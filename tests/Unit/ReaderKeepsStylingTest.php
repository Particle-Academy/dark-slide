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

it('reads a native chart back as the ECharts option that writes it', function () {
    // Charts vanished on read: a graphicFrame holding a chart returned nothing,
    // so every viewer of a read-back deck showed an empty slide where the chart
    // was, and an edit-and-write lost it outright.
    $option = [
        'color' => ['#38BDF8', '#F59E0B'],
        'legend' => [],
        'xAxis' => ['type' => 'category', 'data' => ['Leeds', 'Bristol', 'Norwich']],
        'yAxis' => ['type' => 'value'],
        'series' => [
            ['type' => 'bar', 'name' => 'On-time %', 'label' => ['show' => true],
                'data' => [96.1, 95.4, ['value' => 90.7, 'itemStyle' => ['color' => '#DC2626']]]],
            ['type' => 'bar', 'name' => 'Target', 'data' => [95, 95, 95]],
        ],
    ];
    $deck = rksDeck();
    $deck['slides'][0]['elements'] = [['id' => 'depot-chart', 'type' => 'chart', 'x' => 0.06, 'y' => 0.2, 'w' => 0.6, 'h' => 0.6, 'option' => $option]];

    $read = rksRead($deck)['slides'][0]['elements'];
    expect($read)->toHaveCount(1)
        ->and($read[0])->toMatchArray(['id' => 'depot-chart', 'type' => 'chart'])
        ->and($read[0]['option']['xAxis']['data'])->toBe(['Leeds', 'Bristol', 'Norwich'])
        ->and($read[0]['option']['series'][0]['name'])->toBe('On-time %')
        ->and($read[0]['option']['series'][0]['label'])->toBe(['show' => true])
        ->and($read[0]['option']['series'][0]['data'][2])->toBe(['value' => 90.7, 'itemStyle' => ['color' => '#DC2626']])
        ->and($read[0]['option'])->toHaveKey('legend');

    $original = rksParts(Agent::toBytes($deck));
    $again = rksParts(Agent::toBytes(rksRead($deck)));
    $chart = fn (string $bytes): string => (function () use ($bytes) {
        $path = tempnam(sys_get_temp_dir(), 'rks').'.pptx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('ppt/charts/chart1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    })();
    expect($chart(Agent::toBytes(rksRead($deck))))->toBe($chart(Agent::toBytes($deck)))
        ->and($again['ppt/slides/slide1.xml'])->toBe($original['ppt/slides/slide1.xml']);
});

it('reads a table back with its styling, as the cell model that writes it', function () {
    // A table read back as bare text: every header band, status fill and cell
    // colour was lost, so a viewer drew the scorecard unstyled and an
    // edit-and-write printed it in the defaults.
    $deck = rksDeck();
    $deck['slides'][0]['elements'] = [[
        'id' => 'kpi', 'type' => 'table', 'x' => 0.06, 'y' => 0.2, 'w' => 0.88, 'h' => 0.5,
        'columns' => [['key' => 'k', 'label' => 'KPI', 'width' => 0.5], ['key' => 's', 'label' => 'Status', 'width' => 0.5]],
        'rows' => [
            ['k' => 'On-time delivery', 's' => ['text' => 'Below target', 'fill' => '#DC2626', 'color' => '#FFFFFF', 'bold' => true]],
            ['k' => 'Damage rate', 's' => ['text' => 'On target', 'fill' => '#16A34A', 'color' => '#FFFFFF', 'bold' => true]],
        ],
        'style' => ['header' => ['fill' => '#38BDF8', 'color' => '#0B1220'], 'fontSize' => 36],
    ]];

    $table = rksRead($deck)['slides'][0]['elements'][0];
    expect($table['type'])->toBe('table')
        ->and($table['style']['header'])->toMatchArray(['fill' => '#38BDF8', 'color' => '#0B1220', 'bold' => true])
        ->and($table['style']['fontSize'])->toBe(36)
        ->and($table['rows'][0]['col1'])->toBe('On-time delivery')
        ->and($table['rows'][0]['col2'])->toMatchArray(['text' => 'Below target', 'fill' => '#DC2626', 'color' => '#FFFFFF', 'bold' => true]);

    expect(rksParts(Agent::toBytes(rksRead($deck)))['ppt/slides/slide1.xml'])->toBe(rksParts(Agent::toBytes($deck))['ppt/slides/slide1.xml']);
});
