<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * What shape a table's `rows` take, and whether the published schema says so.
 *
 * A `table` exported as `columns: {type: array}, rows: {type: array}` and
 * nothing more. The real contract was `columns: [{key, label}]` with rows as
 * OBJECTS keyed by each column's key — readable only from the renderer's source,
 * so a vocabulary generated from the schema could not carry it and the natural
 * guess is a positional row, `[["Starter", "$49"]]`.
 *
 * That guess failed SILENTLY, and differently in each runtime, which is why
 * this file pins behaviour and not just the schema text:
 *
 *   - PHP kept the row and emitted every cell empty;
 *   - Node and Python dropped the row from the deck entirely.
 *
 * Three engines held to byte-identical OOXML disagreed on the ROW COUNT of the
 * same input, and no case covered it. Reported as fancy-slides#14.
 *
 * A positional row is now read by column order, so the guess is correct rather
 * than quietly wrong, and the schema publishes both forms.
 */
function trsSchemaProperty(string $key): array
{
    return Agent::jsonSchema()['properties']['slides']['items']['properties']['elements']['items']['properties'][$key];
}

function trsSlideXml(array $rows): string
{
    $deck = [
        'id' => 'trs',
        'title' => 'Table row shape',
        'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 't', 'type' => 'table', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.4,
            'columns' => [['key' => 'plan', 'label' => 'Plan'], ['key' => 'price', 'label' => 'Monthly price']],
            'rows' => $rows,
        ]]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'trs').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

it('publishes the item shape of a column, not just that columns is an array', function () {
    $columns = trsSchemaProperty('columns');

    expect($columns['type'])->toBe('array');
    expect($columns)->toHaveKey('items');

    $properties = $columns['items']['properties'];
    expect($properties)->toHaveKeys(['key', 'label']);
    expect($columns['items']['required'])->toContain('key');

    // `key` is the half that cannot be guessed: it is what a row is keyed BY.
    expect(strtolower($properties['key']['description']))->toContain('row');
});

it('publishes the item shape of a row, including that it is keyed by the column key', function () {
    $rows = trsSchemaProperty('rows');

    expect($rows['type'])->toBe('array');
    expect($rows)->toHaveKey('items');

    // Both accepted forms are described, because only describing the keyed one
    // leaves the other reading as invalid when it is not.
    $described = strtolower(json_encode($rows['items']));
    expect($described)->toContain('key');
    expect($described)->toContain('column order');
});

it('renders a keyed row — the form the schema calls canonical', function () {
    $xml = trsSlideXml([['plan' => 'Starter', 'price' => '$49']]);

    expect($xml)->toContain('Starter')->toContain('$49');
});

it('fills a positional row by column order instead of emptying it', function () {
    $xml = trsSlideXml([['Starter', '$49']]);

    expect($xml)->toContain('Starter')->toContain('$49');
});

it('emits a positional row identically to the keyed row it means', function () {
    expect(trsSlideXml([['Starter', '$49']]))
        ->toBe(trsSlideXml([['plan' => 'Starter', 'price' => '$49']]));
});

it('reads a positional list inside `cells` by column order too', function () {
    $xml = trsSlideXml([['cells' => ['Starter', '$49'], 'height' => 44]]);

    expect($xml)->toContain('Starter')->toContain('$49');
});

it('keeps a short positional row short rather than wrapping onto the next column', function () {
    $xml = trsSlideXml([['Starter']]);

    expect($xml)->toContain('Starter');
    // Two columns, one value: the second cell is empty, never a repeat.
    expect(substr_count($xml, 'Starter'))->toBe(1);
});

it('flags a row whose keys match no column, which no shape can rescue', function () {
    $errors = Agent::validate([
        'id' => 'trs', 'title' => 'Table row shape', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 't', 'type' => 'table', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.4,
            'columns' => [['key' => 'plan', 'label' => 'Plan']],
            // `Plan` is not `plan`. Every cell resolves to nothing, and the
            // table still draws at full size — the blank-grid failure the
            // positional fallback cannot catch, because this row IS an object.
            'rows' => [['Plan' => 'Starter']],
        ]]]],
    ]);

    $paths = array_column($errors, 'path');
    expect($paths)->toContain('/slides/0/elements/0/rows/0');

    $error = $errors[array_search('/slides/0/elements/0/rows/0', $paths, true)];
    expect($error['hint'])->toContain('plan');
});

it('does not flag a row that matches at least one column', function () {
    $errors = Agent::validate([
        'id' => 'trs', 'title' => 'Table row shape', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 't', 'type' => 'table', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.4,
            'columns' => [['key' => 'plan', 'label' => 'Plan'], ['key' => 'price', 'label' => 'Price']],
            // A partially-filled row is ordinary: a missing cell is empty.
            'rows' => [['plan' => 'Starter'], ['Starter', '$49'], ['cells' => ['plan' => 'Pro']]],
        ]]]],
    ]);

    expect($errors)->toBe([]);
});
