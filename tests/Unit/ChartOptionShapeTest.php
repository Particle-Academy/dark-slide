<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Helpers\ChartTranslator;

/**
 * What a chart element's `option` may contain, and whether the schema says so.
 *
 * `option` exported as `['type' => 'object']` and nothing more — the same gap as
 * a table's `columns`/`rows`, with the same silent failure at the end of it. The
 * React renderer hands `option` straight to ECharts, which draws an empty canvas
 * for a shape it does not recognise and raises nothing; the pptx writer hands it
 * to {@see ChartTranslator}, which returns null for anything it cannot read and
 * leaves a titled PLACEHOLDER where the chart should be. Both are full-size and
 * empty. Reported by a consumer generating their writer vocabulary from this
 * schema, in the fancy-slides#14 thread.
 *
 * Two halves, because a description is only worth publishing if it is true:
 *
 *   - every option key the TRANSLATOR reads is described, found by reading the
 *     translator rather than a hand list, so a new key cannot ship undocumented;
 *   - a chart authored exactly as the schema describes emits a native chart part,
 *     not the placeholder.
 */
function cosOption(): array
{
    return Agent::jsonSchema()['properties']['slides']['items']['properties']['elements']['items']['properties']['option'];
}

/** The parts of a written deck, so a native chart can be told from a placeholder. */
function cosParts(array $option, array $extra = []): array
{
    $deck = [
        'id' => 'cos',
        'title' => 'Chart option',
        'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [array_merge([
            'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6,
            'option' => $option,
        ], $extra)]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'cos').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $slide = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return ['names' => $names, 'slide' => $slide];
}

it('describes every option key the translator reads', function () {
    // Found by reading the translator, not by listing keys here: a key added
    // there and not described here must fail this.
    $source = file_get_contents(__DIR__.'/../../src/Helpers/ChartTranslator.php');
    preg_match_all("/\\\$option\['([A-Za-z]+)'\]/", $source, $matches);
    $read = array_values(array_unique($matches[1]));
    sort($read);

    // The scan has to find the keys, or the loop below proves nothing.
    expect($read)->toContain('series')->toContain('xAxis')->toContain('title');

    $described = cosOption()['properties'];
    foreach ($read as $key) {

        expect($described)->toHaveKey($key);
        expect($described[$key]['description'] ?? '')->not->toBe('');
    }
});

it('describes the series item shape, which is where a chart is actually declared', function () {
    $series = cosOption()['properties']['series'];

    expect($series['items']['properties'])->toHaveKeys(['type', 'name', 'data', 'smooth', 'areaStyle']);
    expect($series['items']['properties']['type']['enum'])->toBe(ChartTranslator::SUPPORTED_TYPES);
});

it('says what happens to an option it cannot translate, since nothing else will', function () {
    $described = strtolower(json_encode(cosOption()));

    expect($described)->toContain('placeholder');
    expect($described)->toContain('image');
});

it('emits a native chart part for an option authored as the schema describes', function () {
    $parts = cosParts([
        'title' => ['text' => 'Revenue'],
        'xAxis' => ['data' => ['Q1', 'Q2']],
        'series' => [['type' => 'bar', 'name' => 'ARR', 'data' => [120, 180]]],
    ]);

    expect($parts['names'])->toContain('ppt/charts/chart1.xml');
});

it('falls back to a placeholder for a series type it cannot render', function () {
    // The counter-case. Without it, the assertion above passes on a writer that
    // emits a chart part for everything.
    $parts = cosParts(['series' => [['type' => 'radar', 'data' => [1, 2]]]]);

    expect($parts['names'])->not->toContain('ppt/charts/chart1.xml');
});

it('flags a chart with no option object at all', function () {
    $errors = Agent::validate([
        'id' => 'cos', 'title' => 'Chart option', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6,
        ]]]],
    ]);

    $paths = array_column($errors, 'path');
    expect($paths)->toContain('/slides/0/elements/0/option');

    $error = $errors[array_search('/slides/0/elements/0/option', $paths, true)];
    expect($error['hint'])->toContain('series');
});

it('does NOT flag an option it cannot translate, because the placeholder is supported', function () {
    // The narrowness is load-bearing. `Agent::write()` throws on any validator
    // error, so flagging an untranslatable option turns a documented, tested
    // fallback into a hard failure. It did, briefly, while this file was being
    // written, and V04FeaturesTest caught it. Pinned here too, at the place
    // someone reaching for a stricter check would start.
    $deck = [
        'id' => 'cos', 'title' => 'Chart option', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6,
            'option' => ['series' => [['type' => 'radar', 'data' => [1, 2]]]],
        ]]]],
    ];

    expect(Agent::validate($deck))->toBe([]);

    // And the write that follows must still succeed, placeholder and all.
    $path = tempnam(sys_get_temp_dir(), 'cos').'.pptx';
    Agent::write($deck, $path);
    expect(is_file($path))->toBeTrue();
    @unlink($path);
});
it('does not flag a chart it can render, or one carrying its own image', function () {
    $deck = function (array $element): array {
        return [
            'id' => 'cos', 'title' => 'Chart option', 'theme' => ['name' => 'default'],
            'slides' => [['id' => 's1', 'elements' => [array_merge([
                'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6,
            ], $element)]]],
        ];
    };

    expect(Agent::validate($deck(['option' => ['series' => [['type' => 'bar', 'data' => [1, 2]]]]])))->toBe([]);

    // A pre-rendered chart is the documented escape hatch for anything the
    // translator cannot read, so an untranslatable option with an image is fine.
    expect(Agent::validate($deck([
        'option' => ['series' => [['type' => 'radar', 'data' => [1, 2]]]],
        'image' => 'data:image/png;base64,iVBORw0KGgo=',
    ])))->toBe([]);
});

it('honours a standalone `categories`, as all three engines now do', function () {
    // Was a three-way split until 2026-10-07: this engine and Python seeded their
    // candidate list with [], which is already an array, so the fallback never
    // fired and a deck using `categories` alone got 1, 2, 3 ... labels, while Node
    // seeded null and honoured it. Invisible to byte parity, which never reaches
    // the translator -- the reference deck carries no chart. The owner ruled that
    // the two should match Node, which is what the docblock had always claimed.
    $spec = ChartTranslator::translate([
        'categories' => ['Q1', 'Q2'],
        'series' => [['type' => 'bar', 'data' => [1, 2]]],
    ]);

    expect($spec['categories'])->toBe(['Q1', 'Q2']);

    // `xAxis.data` still wins where both are given: it is the ECharts key, and
    // the only one the browser renderer reads.
    $both = ChartTranslator::translate([
        'categories' => ['ignored', 'also ignored'],
        'xAxis' => ['data' => ['Q1', 'Q2']],
        'series' => [['type' => 'bar', 'data' => [1, 2]]],
    ]);

    expect($both['categories'])->toBe(['Q1', 'Q2']);
});
