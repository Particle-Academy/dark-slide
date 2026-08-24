<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * Everything the bar needs that is NOT a table: a decorated text box, the
 * paragraph controls under a check-mark list, text inside a shape, and the two
 * composite elements.
 *
 * The callout box is the interesting one. PPTX has no per-side border on a
 * shape, so a coloured LEFT accent bar has historically meant stacking a
 * background rect, a thin rect and a text box and hoping the agent gets the z
 * order right. It does not have to: `<a:gradFill>` with two stops at adjacent
 * positions is a hard edge, so the bar and the tint are ONE shape and one
 * element. Verified rendering before it was designed in.
 */
function rtcSlide(array $element, array $theme = []): string
{
    $deck = [
        'id' => 'rtc',
        'title' => 'Rich text constructs',
        'theme' => array_merge(['name' => 'default'], $theme),
        'slides' => [['id' => 's1', 'elements' => [$element]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'rtc') . '.pptx';
    Agent::write($deck, $path);

    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

function rtcText(array $overrides = []): array
{
    return array_merge([
        'id' => 'tx',
        'type' => 'text',
        'x' => 0.06, 'y' => 0.2, 'w' => 0.88, 'h' => 0.18,
        'content' => 'Core recommendation',
        'format' => 'plain',
    ], $overrides);
}

// ─── The decorated text box ───────────────────────────────────────────────

it('leaves a text box unfilled when nothing asks otherwise', function () {
    expect(rtcSlide(rtcText()))->toContain('<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/>');
});

it('fills a text box when the style asks for it', function () {
    $xml = rtcSlide(rtcText(['style' => ['fill' => '#E8F2F3']]));

    expect($xml)->toContain('<a:solidFill><a:srgbClr val="E8F2F3"/></a:solidFill>');
    expect($xml)->not->toContain('<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/>');
});

it('outlines a text box when the style asks for a border', function () {
    $xml = rtcSlide(rtcText(['style' => ['border' => ['width' => 1, 'color' => '#CBD5E1']]]));

    expect($xml)->toContain('<a:ln w="12700"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill>');
});

it('draws a left accent bar as a hard-stop gradient in ONE shape', function () {
    $xml = rtcSlide(rtcText([
        'w' => 0.5,   // 4572000 EMU
        'style' => ['fill' => '#E8F2F3', 'accentBar' => ['color' => '#0E7C86', 'width' => 4]],
    ]));

    // 4pt = 50800 EMU of 4572000 = 1.1111% → 1111 in thousandths of a percent.
    expect($xml)->toContain('<a:gradFill');
    expect($xml)->toContain('<a:gs pos="0"><a:srgbClr val="0E7C86"/></a:gs>');
    expect($xml)->toContain('<a:gs pos="1111"><a:srgbClr val="0E7C86"/></a:gs>');
    expect($xml)->toContain('<a:gs pos="1112"><a:srgbClr val="E8F2F3"/></a:gs>');
    expect($xml)->toContain('<a:gs pos="100000"><a:srgbClr val="E8F2F3"/></a:gs>');
    expect($xml)->toContain('<a:lin ang="0" scaled="0"/>');

    // Exactly one shape — the whole point of doing it this way.
    expect(substr_count($xml, '<p:sp>'))->toBe(1);
});

it('insets the text clear of the accent bar without being told to', function () {
    $xml = rtcSlide(rtcText([
        'w' => 0.5,
        'style' => ['fill' => '#E8F2F3', 'accentBar' => ['color' => '#0E7C86', 'width' => 4]],
    ]));

    // 4pt bar + the 8pt default gutter = 12pt = 152400 EMU.
    expect($xml)->toContain('lIns="152400"');
});

it('puts the accent bar on the right when asked', function () {
    $xml = rtcSlide(rtcText([
        'w' => 0.5,
        'style' => ['fill' => '#E8F2F3', 'accentBar' => ['color' => '#0E7C86', 'width' => 4, 'side' => 'right']],
    ]));

    expect($xml)->toContain('<a:gs pos="0"><a:srgbClr val="E8F2F3"/></a:gs>');
    expect($xml)->toContain('<a:gs pos="100000"><a:srgbClr val="0E7C86"/></a:gs>');
});

it('rounds the corners when a radius is given', function () {
    $xml = rtcSlide(rtcText(['style' => ['fill' => '#E8F2F3', 'radius' => 8]]));

    expect($xml)->toContain('<a:prstGeom prst="roundRect">');
    expect($xml)->toContain('<a:gd name="adj"');
});

it('turns padding into text-body insets', function () {
    $xml = rtcSlide(rtcText(['style' => ['padding' => ['left' => 12, 'top' => 6, 'right' => 12, 'bottom' => 6]]]));

    expect($xml)->toContain('lIns="152400"');
    expect($xml)->toContain('tIns="76200"');
});

// ─── Paragraph + run controls ─────────────────────────────────────────────

it('emits letter spacing and small caps on the run', function () {
    $xml = rtcSlide(rtcText(['style' => ['letterSpacing' => 2.4, 'caps' => 'small']]));

    expect($xml)->toContain('spc="240"');
    expect($xml)->toContain('cap="small"');
});

it('emits line spacing as a percentage and paragraph spacing as points', function () {
    $xml = rtcSlide(rtcText(['style' => ['lineHeight' => 1.4, 'spaceBefore' => 6, 'spaceAfter' => 3]]));

    expect($xml)->toContain('<a:lnSpc><a:spcPct val="140000"/></a:lnSpc>');
    expect($xml)->toContain('<a:spcBef><a:spcPts val="600"/></a:spcBef>');
    expect($xml)->toContain('<a:spcAft><a:spcPts val="300"/></a:spcAft>');
});

it('takes a custom bullet character, which is what a check-mark list is', function () {
    $xml = rtcSlide(rtcText([
        'format' => 'markdown',
        'content' => "- Easy to use\n- Works offline",
        'style' => ['bullet' => '✓'],
    ]));

    expect($xml)->toContain('<a:buChar char="✓"/>');
    expect($xml)->not->toContain('<a:buChar char="•"/>');
});

it('numbers a list when the bullet is set to number', function () {
    $xml = rtcSlide(rtcText([
        'format' => 'markdown',
        'content' => "- First\n- Second",
        'style' => ['bullet' => 'number'],
    ]));

    expect($xml)->toContain('<a:buAutoNum type="arabicPeriod"/>');
});

it('drops bullets entirely when asked', function () {
    $xml = rtcSlide(rtcText([
        'format' => 'markdown',
        'content' => "- First\n- Second",
        'style' => ['bullet' => 'none'],
    ]));

    expect($xml)->not->toContain('<a:buChar');
    expect($xml)->toContain('<a:buNone/>');
});

it('still uses a round bullet by default, as it always has', function () {
    $xml = rtcSlide(rtcText(['format' => 'markdown', 'content' => '- First']));

    expect($xml)->toContain('<a:buChar char="•"/>');
});

// ─── Shapes ───────────────────────────────────────────────────────────────

it('puts text inside a shape when the shape carries content', function () {
    $xml = rtcSlide([
        'id' => 'sh', 'type' => 'shape', 'shape' => 'rounded-rect',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.2,
        'fill' => '#1B3A5C', 'stroke' => 'none',
        'content' => 'Section 3', 'style' => ['color' => '#FFFFFF', 'align' => 'center'],
    ]);

    expect($xml)->toContain('<a:t>Section 3</a:t>');
    expect($xml)->toContain('algn="ctr"');
});

it('emits a genuinely absent outline for stroke none', function () {
    $xml = rtcSlide([
        'id' => 'sh', 'type' => 'shape', 'shape' => 'rect',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.2,
        'fill' => '#E8F2F3', 'stroke' => 'none',
    ]);

    expect($xml)->toContain('<a:ln><a:noFill/></a:ln>');
});

it('treats a zero stroke width as no outline rather than a hairline', function () {
    $xml = rtcSlide([
        'id' => 'sh', 'type' => 'shape', 'shape' => 'rect',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.2,
        'fill' => '#E8F2F3', 'strokeWidth' => 0,
    ]);

    expect($xml)->toContain('<a:ln><a:noFill/></a:ln>');
    expect($xml)->not->toContain('<a:ln w="0">');
});

it('still strokes a shape that says nothing about its outline', function () {
    $xml = rtcSlide([
        'id' => 'sh', 'type' => 'shape', 'shape' => 'rect',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.2, 'fill' => '#E8F2F3',
    ]);

    expect($xml)->toContain('<a:ln w="25400">');
});

// ─── Composites ───────────────────────────────────────────────────────────

it('expands a kpiBand into one table, figures over captions', function () {
    $xml = rtcSlide([
        'id' => 'kpi', 'type' => 'kpiBand',
        'x' => 0.06, 'y' => 0.5, 'w' => 0.88, 'h' => 0.2,
        'items' => [
            ['value' => '$51K-$68K', 'caption' => 'Annual cost of the current workflow'],
            ['value' => '$31K-$48K', 'caption' => 'Estimated annual savings'],
            ['value' => '155%-240%', 'caption' => 'Estimated year 1 ROI'],
            ['value' => '3-5 months', 'caption' => 'Estimated payback period'],
        ],
    ]);

    expect($xml)->toContain('<a:tbl>');
    expect($xml)->toContain('<a:t>$51K-$68K</a:t>');
    expect($xml)->toContain('<a:t>Estimated payback period</a:t>');

    // Four columns, two rows.
    expect(preg_match_all('/<a:gridCol /', $xml))->toBe(4);
    expect(preg_match_all('/<a:tr /', $xml))->toBe(2);

    // The figure and its caption are one visual cell: no rule between them.
    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $xml, $rows);
    expect($rows[1][0])->toContain('<a:lnB><a:noFill/></a:lnB>');
    expect($rows[1][1])->toContain('<a:lnT><a:noFill/></a:lnT>');
    expect($rows[1][0])->not->toContain('<a:lnB w=');
    expect($rows[1][1])->not->toContain('<a:lnT w=');
});

it('sizes the kpi figure larger than its caption', function () {
    $xml = rtcSlide([
        'id' => 'kpi', 'type' => 'kpiBand',
        'x' => 0.06, 'y' => 0.5, 'w' => 0.88, 'h' => 0.2,
        'items' => [['value' => '42', 'caption' => 'answers']],
    ]);

    preg_match_all('/sz="(\d+)"/', $xml, $m);
    $sizes = array_map('intval', $m[1]);
    expect(max($sizes))->toBeGreaterThan(min($sizes));
});

it('expands a metadataGrid into a label-over-value table with the requested columns', function () {
    $xml = rtcSlide([
        'id' => 'meta', 'type' => 'metadataGrid',
        'x' => 0.06, 'y' => 0.5, 'w' => 0.88, 'h' => 0.22,
        'columns' => 3,
        'items' => [
            ['label' => 'Prepared by', 'value' => 'Operations Analysis'],
            ['label' => 'Deal type', 'value' => 'New engagement'],
            ['label' => 'Annual investment', 'value' => '$15,000-$20,000'],
            ['label' => 'Company', 'value' => 'Example Industries'],
            ['label' => 'Date', 'value' => 'June 2026'],
            ['label' => 'Plan', 'value' => 'Corporate'],
        ],
    ]);

    expect(preg_match_all('/<a:gridCol /', $xml))->toBe(3);
    expect(preg_match_all('/<a:tr /', $xml))->toBe(4);   // label,value × 2 down
    expect($xml)->toContain('<a:t>Prepared by</a:t>');
    expect($xml)->toContain('<a:t>Operations Analysis</a:t>');
    expect($xml)->toContain('cap="small"');              // the labels are eyebrows
});

it('pads a metadataGrid whose last row is short rather than emitting a ragged table', function () {
    $xml = rtcSlide([
        'id' => 'meta', 'type' => 'metadataGrid',
        'x' => 0.06, 'y' => 0.5, 'w' => 0.88, 'h' => 0.22,
        'columns' => 3,
        'items' => [['label' => 'One', 'value' => '1'], ['label' => 'Two', 'value' => '2']],
    ]);

    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $xml, $rows);
    foreach ($rows[1] as $row) {
        expect(preg_match_all('/<a:tc[ >]/', $row))->toBe(3);
    }
});

it('validates the composite element types instead of rejecting them', function () {
    $deck = [
        'id' => 'c', 'title' => 'c', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [
            ['id' => 'k', 'type' => 'kpiBand', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2, 'items' => [['value' => '1', 'caption' => 'x']]],
            ['id' => 'm', 'type' => 'metadataGrid', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2, 'items' => [['label' => 'a', 'value' => 'b']]],
        ]]],
    ];

    expect(Agent::validate($deck))->toBe([]);
});

it('rejects a composite with no items, because an empty band is a silent blank', function () {
    $deck = [
        'id' => 'c', 'title' => 'c', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [
            ['id' => 'k', 'type' => 'kpiBand', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2],
        ]]],
    ];

    $errors = Agent::validate($deck);
    expect($errors)->not->toBeEmpty();
    expect($errors[0]['path'])->toBe('/slides/0/elements/0/items');
});
