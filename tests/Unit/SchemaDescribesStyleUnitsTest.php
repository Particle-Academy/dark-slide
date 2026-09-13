<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * The published schema tells a model what unit each style field is in.
 *
 * `style` was exported as a bare `{type: object}`. A model filling it in had only
 * the key names, and `fontSize` reads as points while the writer treats it as
 * design pixels and halves it (trap 5 in AGENTS.md). In the fancy-labs document
 * lab an agent described its headline as 232pt and the file carried 116pt. The
 * style object also mixes units, which no key name conveys.
 *
 * Two halves, because a description is only worth publishing if it is true:
 *
 *   - every style key the writer reads is DESCRIBED, found by reading the writer
 *     rather than a hand list, so a new key cannot ship undocumented;
 *   - every worked example a description quotes is what the writer PRODUCES.
 */
function sduStyle(): array
{
    return Agent::jsonSchema()['properties']['slides']['items']['properties']['elements']['items']['properties']['style'];
}

function sduSlideXml(array $style): string
{
    $deck = [
        'id' => 'units',
        'title' => 'Style units',
        'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 't', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.3,
            'content' => "First paragraph\nSecond paragraph", 'style' => $style,
        ]]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'sdu').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

it('describes every style key the writer reads', function () {
    $source = file_get_contents(__DIR__.'/../../src/Writer/PptxWriter.php')
        .file_get_contents(__DIR__.'/../../src/Text/BoxDecoration.php');
    preg_match_all("/\\\$style\\['([A-Za-z]+)'\\]/", $source, $matches);
    $read = array_values(array_unique($matches[1]));
    sort($read);

    // The scan has to find the keys, or the loop below proves nothing.
    expect($read)->toContain('fontSize')->toContain('letterSpacing')->toContain('accentBar');

    $properties = sduStyle()['properties'];
    foreach ($read as $key) {
        expect($properties)->toHaveKey($key);
        if (! in_array($key, ['italic', 'underline'], true)) {
            expect($properties[$key]['description'] ?? '')->not->toBe('', "style.{$key} has no description");
        }
    }
});

it('says fontSize is design pixels halved into points, and the file agrees', function () {
    $description = sduStyle()['properties']['fontSize']['description'];

    expect($description)
        ->toContain('DESIGN PIXELS')
        ->toContain('96 is written as 48pt')
        ->toContain('24 as 12pt')
        ->toContain('anything under 16 as 8pt');

    expect(sduSlideXml(['fontSize' => 96]))->toContain('sz="4800"');
    expect(sduSlideXml(['fontSize' => 24]))->toContain('sz="1200"');
    expect(sduSlideXml(['fontSize' => 10]))->toContain('sz="800"');
});

it('says which fields are already points, and the file agrees', function () {
    $properties = sduStyle()['properties'];

    expect($properties['letterSpacing']['description'])->toContain('2 is written as 2pt');
    expect(sduSlideXml(['letterSpacing' => 2]))->toContain('spc="200"');

    expect($properties['spaceBefore']['description'])->toContain('6 is written as 6pt');
    expect(sduSlideXml(['spaceBefore' => 6]))->toContain('<a:spcBef><a:spcPts val="600"/></a:spcBef>');

    expect($properties['padding']['description'])->toContain('12 is written as 12pt');
    expect(sduSlideXml(['padding' => 12]))->toContain('lIns="152400"'); // 12pt x 12700 EMU
});

it('says lineHeight is a multiple, and the file agrees', function () {
    expect(sduStyle()['properties']['lineHeight']['description'])->toContain('1.4 is written as 140%');
    expect(sduSlideXml(['lineHeight' => 1.4]))->toContain('<a:spcPct val="140000"/>');
});

it('describes element position and size as fractions of the slide', function () {
    $element = Agent::jsonSchema()['properties']['slides']['items']['properties']['elements']['items']['properties'];

    foreach (['x', 'y', 'w', 'h'] as $key) {
        expect($element[$key]['description'] ?? '')->toContain('FRACTION');
    }
});
