<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * The published schema tells a model what unit each style field is in.
 *
 * `style` was exported as a bare `{type: object}` until 0.9.2. A model filling it
 * in had only the key names, and `fontSize` reads as points: in the fancy-labs
 * document lab an agent described its headline as 232pt and the file carried
 * 116pt. 0.10 gave every length in the object one unit, the design pixel, and
 * the descriptions say so with worked examples.
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

it('says every length is a design pixel on the canvas', function () {
    expect(sduStyle()['description'])
        ->toContain('DESIGN PIXELS')
        ->toContain('points = px x 720 / slideWidth');

    foreach (['letterSpacing', 'spaceBefore', 'spaceAfter', 'radius', 'padding'] as $key) {
        expect(sduStyle()['properties'][$key]['description'])->toContain('design pixels');
    }
});

it('says what fontSize is written as, and the file agrees', function () {
    $description = sduStyle()['properties']['fontSize']['description'];

    expect($description)
        ->toContain('96 is written as 36pt')
        ->toContain('28 as 10.5pt')
        ->toContain('never below 1pt')
        ->toContain('Default 28');

    expect(sduSlideXml(['fontSize' => 96]))->toContain('sz="3600"');
    expect(sduSlideXml(['fontSize' => 28]))->toContain('sz="1050"');
    expect(sduSlideXml([]))->toContain('sz="1050"');
    expect(sduSlideXml(['fontSize' => 2]))->toContain('sz="100"');
});

it('says what the other lengths are written as, and the file agrees', function () {
    $properties = sduStyle()['properties'];

    expect($properties['letterSpacing']['description'])->toContain('8 is written as 3pt');
    expect(sduSlideXml(['letterSpacing' => 8]))->toContain('spc="300"');

    expect($properties['spaceBefore']['description'])->toContain('16 is written as 6pt');
    expect(sduSlideXml(['spaceBefore' => 16]))->toContain('<a:spcBef><a:spcPts val="600"/></a:spcBef>');

    expect($properties['padding']['description'])->toContain('32 is written as 12pt');
    expect(sduSlideXml(['padding' => 32]))->toContain('lIns="152400"'); // 12pt x 12700 EMU
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
    expect($element['strokeWidth']['description'] ?? '')->toContain('design pixels');
});

it('describes the canvas the lengths are measured on', function () {
    $theme = Agent::jsonSchema()['properties']['theme']['properties'];

    expect($theme['slideWidth']['description'] ?? '')->toContain('1920 by default')->toContain('1440 reproduces');
    expect($theme['aspectRatio']['description'] ?? '')->toContain('16/9 by default');
});
