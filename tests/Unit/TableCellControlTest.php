<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * Per-cell control of a pptx table: borders, insets, vertical anchor, merging,
 * column widths, and the fill/text resolution chain behind them.
 *
 * Why this file exists: before it, `<a:tcPr>` carried a fill and NOTHING else.
 * A table therefore had no rules of its own — whatever a reader drew came from
 * its own default table style, which is not the same as "no borders" and is not
 * controllable either way. Every assertion here is on emitted OOXML, because
 * the resolver being right is only half of it.
 *
 * Two traps are pinned deliberately:
 *
 *   1. `<a:tcPr>` child order is schema-fixed — lnL, lnR, lnT, lnB, then the
 *      fill. Emitting the fill first produced a file whose fill was ignored.
 *   2. Insets and vertical anchor belong on `<a:tcPr>` (marL…/@anchor) for a
 *      table cell, NOT on the `<a:bodyPr>` inside it. The old writer put them
 *      on bodyPr, which is the right element for a shape and the wrong one here.
 */
function tccSlide(array $element): string
{
    $deck = [
        'id' => 'tcc',
        'title' => 'Table cell control',
        'theme' => ['name' => 'default', 'colors' => ['accent' => '#1B3A5C']],
        'slides' => [['id' => 's1', 'elements' => [$element]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'tcc') . '.pptx';
    Agent::write($deck, $path);

    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

/** The `<a:tc>` elements of the slide's only table, in document order. */
function tccCells(string $xml): array
{
    preg_match_all('/<a:tc[ >].*?<\/a:tc>/s', $xml, $m);

    return $m[0];
}

function tccTable(array $overrides = []): array
{
    return array_merge([
        'id' => 't1',
        'type' => 'table',
        'x' => 0.05, 'y' => 0.1, 'w' => 0.9, 'h' => 0.4,
        'columns' => [
            ['key' => 'a', 'label' => 'Cost Category'],
            ['key' => 'b', 'label' => 'Annual Estimate'],
        ],
        'rows' => [
            ['a' => 'Drawing labor', 'b' => '$21,000'],
            ['a' => 'Change orders', 'b' => '$13,000'],
        ],
    ], $overrides);
}

// ─── Borders ──────────────────────────────────────────────────────────────

it('STATES that there is no border rather than omitting the element', function () {
    // Found by rendering, not by reading the spec. Omitting `<a:lnL>` does not
    // mean "no rule" — it means "unspecified", and the reader supplies one from
    // its own default table style. LibreOffice drew a full grid over a
    // `borders: false` metadata panel. An explicit empty line is unambiguous
    // in every renderer.
    $xml = tccSlide(tccTable(['style' => ['borders' => false]]));

    expect($xml)->toContain('<a:lnL><a:noFill/></a:lnL>');
    expect($xml)->toContain('<a:lnR><a:noFill/></a:lnR>');
    expect($xml)->toContain('<a:lnT><a:noFill/></a:lnT>');
    expect($xml)->toContain('<a:lnB><a:noFill/></a:lnB>');
    expect($xml)->not->toContain('<a:lnL w=');
});

it('emits all four per-cell borders with the requested width and colour', function () {
    $xml = tccSlide(tccTable([
        'style' => ['borders' => ['width' => 1.5, 'color' => '#D8E0E8']],
    ]));

    // 1.5pt = 19050 EMU (12700 EMU per point).
    expect($xml)->toContain('<a:lnL w="19050"');
    expect($xml)->toContain('<a:lnR w="19050"');
    expect($xml)->toContain('<a:lnT w="19050"');
    expect($xml)->toContain('<a:lnB w="19050"');
    expect($xml)->toContain('<a:srgbClr val="D8E0E8"/>');
});

it('puts the borders BEFORE the fill inside a:tcPr, which the schema requires', function () {
    $xml = tccSlide(tccTable([
        'style' => ['borders' => ['width' => 1, 'color' => '#D8E0E8']],
    ]));

    // A header cell has both a fill and borders. The fill must come last.
    expect($xml)->toMatch('/<a:tcPr[^>]*><a:lnL .*?<a:lnB .*?<\/a:lnB><a:solidFill>/s');
});

it('resolves outer and inner borders separately by cell position', function () {
    $xml = tccSlide(tccTable([
        'style' => [
            'borders' => [
                'outer' => ['width' => 2, 'color' => '#1B3A5C'],
                'inner' => ['width' => 0.5, 'color' => '#D8E0E8'],
            ],
        ],
    ]));

    $cells = tccCells($xml);
    // Top-left header cell: left + top are outer (2pt = 25400), right + bottom inner (0.5pt = 6350).
    expect($cells[0])->toContain('<a:lnL w="25400"');
    expect($cells[0])->toContain('<a:lnT w="25400"');
    expect($cells[0])->toContain('<a:lnR w="6350"');
    expect($cells[0])->toContain('<a:lnB w="6350"');

    // Bottom-right cell: right + bottom are outer.
    $last = $cells[count($cells) - 1];
    expect($last)->toContain('<a:lnR w="25400"');
    expect($last)->toContain('<a:lnB w="25400"');
    expect($last)->toContain('<a:lnL w="6350"');
});

it('lets a single side be turned off while the rest stay on', function () {
    $xml = tccSlide(tccTable([
        'style' => [
            'borders' => ['all' => ['width' => 1, 'color' => '#D8E0E8'], 'left' => false, 'right' => false],
        ],
    ]));

    expect($xml)->toContain('<a:lnL><a:noFill/></a:lnL>');
    expect($xml)->toContain('<a:lnR><a:noFill/></a:lnR>');
    expect($xml)->toContain('<a:lnT w="12700"');
    expect($xml)->toContain('<a:lnB w="12700"');
});

it('carries a dashed border style through to prstDash', function () {
    $xml = tccSlide(tccTable([
        'style' => ['borders' => ['width' => 1, 'color' => '#D8E0E8', 'style' => 'dash']],
    ]));

    expect($xml)->toContain('<a:prstDash val="dash"/>');
});

// ─── Insets + anchor, on the right element ────────────────────────────────

it('writes cell insets as a:tcPr margins, not as a:bodyPr insets', function () {
    $xml = tccSlide(tccTable(['style' => ['padding' => 9]]));

    // 9pt = 114300 EMU.
    expect($xml)->toContain('marL="114300"');
    expect($xml)->toContain('marR="114300"');
    expect($xml)->toContain('marT="114300"');
    expect($xml)->toContain('marB="114300"');

    // And the cell's bodyPr no longer carries them.
    preg_match('/<a:tc[ >].*?<a:bodyPr[^>]*>/s', $xml, $m);
    expect($m[0])->not->toContain('lIns=');
});

it('accepts per-side padding', function () {
    $xml = tccSlide(tccTable([
        'style' => ['padding' => ['left' => 12, 'right' => 6, 'top' => 3, 'bottom' => 3]],
    ]));

    expect($xml)->toContain('marL="152400"');
    expect($xml)->toContain('marR="76200"');
    expect($xml)->toContain('marT="38100"');
    expect($xml)->toContain('marB="38100"');
});

it('writes the vertical anchor on a:tcPr', function () {
    $xml = tccSlide(tccTable(['style' => ['anchor' => 'top']]));

    expect($xml)->toMatch('/<a:tcPr[^>]*anchor="t"/');
});

it('anchors cells to the middle by default, as it always has', function () {
    $xml = tccSlide(tccTable());

    expect($xml)->toMatch('/<a:tcPr[^>]*anchor="ctr"/');
});

// ─── Column widths ────────────────────────────────────────────────────────

it('splits columns equally when no widths are given', function () {
    $xml = tccSlide(tccTable());

    preg_match_all('/<a:gridCol w="(\d+)"\/>/', $xml, $m);
    expect($m[1])->toBe(['4114800', '4114800']);
});

it('honours relative column widths and still sums to the table width exactly', function () {
    $xml = tccSlide(tccTable([
        'columns' => [
            ['key' => 'a', 'label' => 'Cost Category', 'width' => 3],
            ['key' => 'b', 'label' => 'Annual Estimate', 'width' => 1],
        ],
    ]));

    preg_match_all('/<a:gridCol w="(\d+)"\/>/', $xml, $m);
    $widths = array_map('intval', $m[1]);

    // 0.9 of a 9144000 EMU slide = 8229600, split 3:1.
    expect($widths)->toBe([6172200, 2057400]);
    expect(array_sum($widths))->toBe(8229600);
});

it('shares the remaining width between columns that do not declare one', function () {
    $xml = tccSlide(tccTable([
        'columns' => [
            ['key' => 'a', 'label' => 'A', 'width' => 0.5],
            ['key' => 'b', 'label' => 'B'],
            ['key' => 'c', 'label' => 'C'],
        ],
        'rows' => [['a' => '1', 'b' => '2', 'c' => '3']],
    ]));

    preg_match_all('/<a:gridCol w="(\d+)"\/>/', $xml, $m);
    $widths = array_map('intval', $m[1]);

    expect($widths[0])->toBe(4114800);        // half
    expect($widths[1])->toBe($widths[2]);     // the rest, evenly
    expect(array_sum($widths))->toBe(8229600);
});

// ─── Merging ──────────────────────────────────────────────────────────────

it('emits gridSpan on the anchor cell and hMerge on the cells it swallows', function () {
    $xml = tccSlide(tccTable([
        'rows' => [
            ['a' => 'Drawing labor', 'b' => '$21,000'],
            ['cells' => ['a' => ['text' => 'Total estimated annual cost', 'colSpan' => 2]]],
        ],
    ]));

    expect($xml)->toContain('gridSpan="2"');
    expect($xml)->toContain('hMerge="1"');

    // The row still has one <a:tc> per grid column — a short row is a corrupt file.
    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $xml, $rows);
    $lastRow = $rows[1][count($rows[1]) - 1];
    expect(preg_match_all('/<a:tc[ >]/', $lastRow))->toBe(2);
});

it('emits rowSpan and vMerge for a vertically merged cell', function () {
    $xml = tccSlide(tccTable([
        'rows' => [
            ['cells' => ['a' => ['text' => 'Spans down', 'rowSpan' => 2], 'b' => 'first']],
            ['b' => 'second'],
        ],
    ]));

    expect($xml)->toContain('rowSpan="2"');
    expect($xml)->toContain('vMerge="1"');
});

it('does not let a colSpan run off the end of the row', function () {
    $xml = tccSlide(tccTable([
        'rows' => [['cells' => ['a' => ['text' => 'Too wide', 'colSpan' => 99], 'b' => 'x']]],
    ]));

    expect($xml)->toContain('gridSpan="2"');
    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $xml, $rows);
    $lastRow = $rows[1][count($rows[1]) - 1];
    expect(preg_match_all('/<a:tc[ >]/', $lastRow))->toBe(2);
});

// ─── The fill / text resolution chain ─────────────────────────────────────

it('derives the header fill from the theme accent instead of a hardcoded violet', function () {
    $xml = tccSlide(tccTable());

    expect($xml)->toContain('<a:srgbClr val="1B3A5C"/>');
    expect($xml)->not->toContain('<a:srgbClr val="8B5CF6"/>');
});

it('lets a row set its own fill, colour and weight', function () {
    $xml = tccSlide(tccTable([
        'rows' => [
            ['a' => 'Drawing labor', 'b' => '$21,000'],
            ['cells' => ['a' => 'Total', 'b' => '$51,000'], 'fill' => '#0E7C86', 'color' => '#FFFFFF', 'bold' => true],
        ],
    ]));

    $cells = tccCells($xml);
    $total = $cells[count($cells) - 1];
    expect($total)->toContain('<a:srgbClr val="0E7C86"/>');
    expect($total)->toContain('<a:srgbClr val="FFFFFF"/>');
    expect($total)->toContain(' b="1"');
});

it('lets a cell override the row it sits in', function () {
    $xml = tccSlide(tccTable([
        'rows' => [
            ['cells' => ['a' => 'plain', 'b' => ['text' => 'loud', 'color' => '#B91C1C']], 'color' => '#334155'],
        ],
    ]));

    $cells = tccCells($xml);
    expect($cells[2])->toContain('<a:srgbClr val="334155"/>');
    expect($cells[3])->toContain('<a:srgbClr val="B91C1C"/>');
});

it('takes a configurable stripe fill and can switch striping off', function () {
    $striped = tccSlide(tccTable([
        'rows' => [['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']],
        'style' => ['stripe' => ['fill' => '#F1F5F9']],
    ]));
    expect($striped)->toContain('<a:srgbClr val="F1F5F9"/>');

    $flat = tccSlide(tccTable([
        'rows' => [['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']],
        'style' => ['stripe' => false],
    ]));
    expect($flat)->not->toContain('<a:srgbClr val="F1F5F9"/>');
});

it('carries letter spacing and small caps into a header cell run', function () {
    $xml = tccSlide(tccTable([
        'style' => ['header' => ['letterSpacing' => 1.2, 'caps' => 'small']],
    ]));

    // 1.2pt = 120 hundredths.
    expect($xml)->toContain('spc="120"');
    expect($xml)->toContain('cap="small"');
});

it('aligns a column and lets a cell override it', function () {
    $xml = tccSlide(tccTable([
        'columns' => [
            ['key' => 'a', 'label' => 'A', 'align' => 'left'],
            ['key' => 'b', 'label' => 'B', 'align' => 'right'],
        ],
        'rows' => [['cells' => ['a' => 'x', 'b' => ['text' => 'y', 'align' => 'center']]]],
    ]));

    $cells = tccCells($xml);
    expect($cells[1])->toContain('algn="r"');   // header of column b
    expect($cells[3])->toContain('algn="ctr"'); // the cell that overrode it
});

it('honours an explicit row height', function () {
    $xml = tccSlide(tccTable([
        'rows' => [['cells' => ['a' => 'x', 'b' => 'y'], 'height' => 50]],
    ]));

    expect($xml)->toContain('<a:tr h="635000">'); // 50pt
});

it('can suppress the header row entirely', function () {
    $xml = tccSlide(tccTable(['style' => ['header' => false]]));

    expect($xml)->not->toContain('Cost Category');
    preg_match_all('/<a:tr /', $xml, $m);
    expect(count($m[0]))->toBe(2);
});

// ─── The old behaviour that must not silently change ──────────────────────

it('still renders a scalar cell exactly as before', function () {
    $xml = tccSlide(tccTable(['rows' => [['a' => 'Drawing labor', 'b' => 42]]]));

    expect($xml)->toContain('<a:t>Drawing labor</a:t>');
    expect($xml)->toContain('<a:t>42</a:t>');
});

it('treats an object cell as a cell spec rather than JSON text', function () {
    // BEHAVIOUR CHANGE: this used to emit the json_encode of the array.
    $xml = tccSlide(tccTable(['rows' => [['a' => ['text' => 'Total'], 'b' => '']]]));

    expect($xml)->toContain('<a:t>Total</a:t>');
    expect($xml)->not->toContain('{&quot;text&quot;');
});

// ─── Reading it back ──────────────────────────────────────────────────────

/** Round-trip a table element through write + read. */
function tccRoundTrip(array $element): array
{
    $deck = [
        'id' => 'tcc', 'title' => 'rt', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [$element]]],
    ];
    $path = tempnam(sys_get_temp_dir(), 'tccrt') . '.pptx';
    Agent::write($deck, $path);
    $read = Agent::read($path);
    @unlink($path);

    return $read['slides'][0]['elements'][0];
}

it('does not eat the first data row of a header-less table when reading it back', function () {
    // The reader assumed row 0 was ALWAYS the header. Header-less tables only
    // became expressible with `header: false`, and every metadataGrid and
    // kpiBand is one — so each came back with its first row of data promoted
    // to column labels and one row of content missing.
    $element = tccRoundTrip(tccTable([
        'rows' => [['a' => 'first', 'b' => '1'], ['a' => 'second', 'b' => '2']],
        'style' => ['header' => false],
    ]));

    expect($element['rows'])->toHaveCount(2);
    expect($element['rows'][0]['col1'])->toBe('first');
    expect($element['columns'][0]['label'])->toBe('');
});

it('still treats row 0 as the header when the table has one', function () {
    $element = tccRoundTrip(tccTable());

    expect($element['columns'][0]['label'])->toBe('Cost Category');
    expect($element['rows'])->toHaveCount(2);
});

it('reads column widths back as fractions that sum to 1', function () {
    $element = tccRoundTrip(tccTable([
        'columns' => [
            ['key' => 'a', 'label' => 'A', 'width' => 3],
            ['key' => 'b', 'label' => 'B', 'width' => 1],
        ],
    ]));

    expect($element['columns'][0]['width'])->toBe(0.75);
    expect($element['columns'][1]['width'])->toBe(0.25);
});
