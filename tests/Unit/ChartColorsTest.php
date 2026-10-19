<?php

declare(strict_types=1);

/**
 * Chart colours, as ECharts states them.
 *
 * The writer coloured every chart from its own palette and read none of the
 * option's colours, so an agent could not highlight the bar a slide was about,
 * and a pie took the stock hot pink as its second slice whatever the brand.
 * Reviewers marked both down on every comparison deck. Three ECharts keys are
 * honoured now, each only when present, so a chart that sets none writes the
 * bytes it always did:
 *
 *  - `option.color`: the palette, series by series (slice by slice for a pie);
 *  - `series[].itemStyle.color`: one series' colour;
 *  - `series[].data[i].itemStyle.color`: one point -- the bar, marker or slice
 *    the slide is about.
 *
 */
function ccChart(array $option): string
{
    $deck = [
        'id' => 'cc',
        'title' => 'Chart colours',
        'theme' => ['name' => 'cc'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6, 'option' => $option,
        ]]]],
    ];
    $zip = new ZipArchive();
    $path = tempnam(sys_get_temp_dir(), 'cc').'.pptx';
    DarkSlide\Agent::write($deck, $path);
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/charts/chart1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

const CC_BARS = [
    'xAxis' => ['type' => 'category', 'data' => ['Leeds', 'Bristol', 'Glasgow', 'Norwich']],
    'yAxis' => ['type' => 'value'],
    'series' => [[
        'type' => 'bar',
        'itemStyle' => ['color' => '#94A3B8'],
        'data' => [96.1, 95.4, 93.8, ['value' => 90.7, 'itemStyle' => ['color' => '#DC2626']]],
    ]],
];

it('colours a series and a single bar from itemStyle', function () {
    $xml = ccChart(CC_BARS);

    expect($xml)->toContain('<c:spPr><a:solidFill><a:srgbClr val="94A3B8"/></a:solidFill></c:spPr>')
        ->and($xml)->toContain('<c:dPt><c:idx val="3"/><c:invertIfNegative val="0"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="DC2626"/></a:solidFill></c:spPr></c:dPt>')
        // CT_BarSer: the series fill, then data points, then labels and categories.
        ->and(strpos($xml, '<c:dPt>'))->toBeGreaterThan(strpos($xml, 'val="94A3B8"'))
        ->and(strpos($xml, '<c:dPt>'))->toBeLessThan(strpos($xml, '<c:cat>'));
});

it('takes the palette from option.color', function () {
    $xml = ccChart([
        'color' => ['#0F766E', '#F59E0B'],
        'xAxis' => ['type' => 'category', 'data' => ['A', 'B']],
        'series' => [['type' => 'bar', 'data' => [1, 2]], ['type' => 'bar', 'data' => [3, 4]]],
    ]);

    expect($xml)->toContain('<a:srgbClr val="0F766E"/>')
        ->and($xml)->toContain('<a:srgbClr val="F59E0B"/>')
        ->and($xml)->not->toContain('val="EC4899"');
});

it('colours pie slices from the palette and from a slice of their own', function () {
    $xml = ccChart([
        'color' => ['#0F766E', '#CBD5E1'],
        'series' => [['type' => 'pie', 'data' => [
            ['name' => 'Cited reconciliation', 'value' => 41, 'itemStyle' => ['color' => '#C2410C']],
            ['name' => 'Other reasons', 'value' => 59],
        ]]],
    ]);

    expect($xml)->toContain('<c:dPt><c:idx val="0"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="C2410C"/></a:solidFill></c:spPr></c:dPt>')
        ->and($xml)->toContain('<c:dPt><c:idx val="1"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></c:spPr></c:dPt>')
        ->and($xml)->not->toContain('EC4899');
});

it('colours a line marker for a single point', function () {
    $xml = ccChart([
        'xAxis' => ['type' => 'category', 'data' => ['Q1', 'Q2', 'Q3']],
        'series' => [['type' => 'line', 'data' => [1, 2, ['value' => 3, 'itemStyle' => ['color' => '#DC2626']]]]],
    ]);

    expect($xml)->toContain('<c:dPt><c:idx val="2"/><c:marker><c:symbol val="circle"/><c:size val="7"/><c:spPr><a:solidFill><a:srgbClr val="DC2626"/></a:solidFill><a:ln><a:solidFill><a:srgbClr val="DC2626"/></a:solidFill></a:ln></c:spPr></c:marker><c:bubble3D val="0"/></c:dPt>');
});

it('writes what it always did when the option names no colour', function () {
    $plain = CC_BARS;
    unset($plain['series'][0]['itemStyle']);
    $plain['series'][0]['data'][3] = 90.7;

    expect(ccChart($plain))->not->toContain('<c:dPt>');
});

it('starts a bar axis at zero, unless a value is negative or it is a line', function () {
    expect(ccChart(CC_BARS))->toContain('<c:scaling><c:orientation val="minMax"/><c:min val="0"/></c:scaling>')
        ->and(ccChart(['xAxis' => ['data' => ['A', 'B']], 'series' => [['type' => 'bar', 'data' => [3, -1]]]]))->not->toContain('<c:min val="0"/>')
        ->and(ccChart(['xAxis' => ['data' => ['A', 'B']], 'series' => [['type' => 'line', 'data' => [96, 91]]]]))->not->toContain('<c:min val="0"/>');
});
