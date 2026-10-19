<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * A deck read back keeps the styling it was written with.
 *
 * The reader used to return geometry and text only: the theme came back as
 * `{"name": "imported"}` and every text style and shape colour was dropped. An
 * agent editing a branded deck (read, change one slide, write) therefore
 * shipped the whole deck in 28px default text, default colours and a purple
 * tint on every panel, with nothing reporting a loss.
 *
 * The strongest form of the check is the round trip: writing what was read
 * must reproduce the slide and theme parts byte for byte, which can only hold
 * if nothing visible was lost on the way through.
 */
function rksDeck(): array
{
    return [
        'id' => 'rks',
        'title' => 'Styled',
        'theme' => [
            'name' => 'brand',
            'colors' => ['background' => '#101828', 'text' => '#F9FAFB', 'accent' => '#F97316', 'muted' => '#94A3B8', 'surface' => '#1E293B'],
            'fonts' => ['heading' => 'Playfair Display', 'body' => 'Inter'],
        ],
        'slides' => [['id' => 's1', 'elements' => [
            ['id' => 'title', 'type' => 'text', 'x' => 0.06, 'y' => 0.08, 'w' => 0.88, 'h' => 0.2,
                'content' => 'EMEA led Q3', 'style' => ['fontSize' => 88, 'weight' => 700, 'fontFamily' => 'Playfair Display', 'color' => '#F97316', 'align' => 'center']],
            ['id' => 'body', 'type' => 'text', 'x' => 0.06, 'y' => 0.35, 'w' => 0.5, 'h' => 0.3,
                'content' => 'Revenue grew 31%', 'style' => ['fontSize' => 40, 'italic' => true, 'verticalAlign' => 'middle', 'align' => 'right']],
            ['id' => 'plain', 'type' => 'text', 'x' => 0.06, 'y' => 0.7, 'w' => 0.5, 'h' => 0.1, 'content' => 'Unstyled'],
            ['id' => 'panel', 'type' => 'shape', 'shape' => 'rounded-rect', 'x' => 0.6, 'y' => 0.35, 'w' => 0.34, 'h' => 0.3,
                'fill' => '#1E293B', 'stroke' => 'transparent'],
            ['id' => 'tint', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.6, 'y' => 0.7, 'w' => 0.34, 'h' => 0.1],
            ['id' => 'rule', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.06, 'y' => 0.3, 'w' => 0.1, 'h' => 0.006,
                'fill' => '#F97316', 'stroke' => '#F97316', 'strokeWidth' => 4, 'dashed' => true],
        ]]],
    ];
}

/** @return array<string, string> */
function rksParts(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'rks').'.pptx';
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    foreach (['ppt/slides/slide1.xml', 'ppt/theme/theme1.xml'] as $name) {
        $parts[$name] = (string) $zip->getFromName($name);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

function rksRead(array $deck): array
{
    $path = tempnam(sys_get_temp_dir(), 'rks').'.pptx';
    Agent::write($deck, $path);
    $read = Agent::read($path);
    @unlink($path);

    return $read;
}

/** @return array<string, array<string, mixed>> */
function rksElements(array $read): array
{
    return array_column($read['slides'][0]['elements'], null, 'id');
}

it('reads the theme back: colours and fonts', function () {
    expect(rksRead(rksDeck())['theme'])->toBe([
        'name' => 'imported',
        'colors' => ['background' => '#101828', 'text' => '#F9FAFB', 'accent' => '#F97316', 'muted' => '#94A3B8', 'surface' => '#1E293B'],
        // No mono: the deck named none, and the writer's default (Consolas) is
        // not reported, so a read stays a fixed point of write-then-read.
        'fonts' => ['heading' => 'Playfair Display', 'body' => 'Inter'],
    ]);
});

it('reads a text element style back in design pixels', function () {
    $els = rksElements(rksRead(rksDeck()));

    expect($els['title']['style'])->toBe(['fontSize' => 88, 'weight' => 700, 'color' => '#F97316', 'fontFamily' => 'Playfair Display', 'align' => 'center'])
        ->and($els['body']['style'])->toBe(['fontSize' => 40, 'italic' => true, 'color' => '#F9FAFB', 'align' => 'right', 'verticalAlign' => 'middle'])
        // Unstyled text was written in the theme's colour at the 28px default; both are stated.
        ->and($els['plain']['style'])->toBe(['fontSize' => 28, 'color' => '#F9FAFB']);
});

it('reads shape fill and outline back, including "none"', function () {
    $els = rksElements(rksRead(rksDeck()));

    expect($els['panel'])->toMatchArray(['fill' => '#1E293B', 'stroke' => 'transparent'])
        // The writer's default tint, stated rather than left to the default.
        ->and($els['tint'])->toMatchArray(['fill' => 'rgba(139,92,246,0.15)', 'stroke' => '#F97316', 'strokeWidth' => 2])
        ->and($els['rule'])->toMatchArray(['fill' => '#F97316', 'stroke' => '#F97316', 'strokeWidth' => 4, 'dashed' => true]);
});

it('keeps a bold paragraph bold inside an otherwise plain box', function () {
    // Bold was decided per PARAGRAPH: a paragraph whose every run was bold read
    // as "bold is this paragraph's default" and emitted no marker, so a bold
    // lead line in a plain box came back plain. It is decided per element now.
    $deck = rksDeck();
    $deck['slides'][0]['elements'] = [['id' => 'mix', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.4,
        'content' => "**Lead line**\nPlain detail", 'format' => 'markdown']];
    $el = rksRead($deck)['slides'][0]['elements'][0];

    expect($el['content'])->toBe("**Lead line**\nPlain detail")
        ->and($el['format'])->toBe('markdown')
        ->and($el['style'])->not->toHaveKey('weight');
});

it('reports a named mono face, and not the default one', function () {
    $deck = rksDeck();
    $deck['theme']['fonts']['mono'] = 'Fira Code';

    expect(rksRead($deck)['theme']['fonts']['mono'])->toBe('Fira Code');
});

it('writes what it read to the same slide and theme parts', function () {
    $original = rksParts(Agent::toBytes(rksDeck()));
    $again = rksParts(Agent::toBytes(rksRead(rksDeck())));

    expect($again['ppt/theme/theme1.xml'])->toBe($original['ppt/theme/theme1.xml'])
        ->and($again['ppt/slides/slide1.xml'])->toBe($original['ppt/slides/slide1.xml']);
});
