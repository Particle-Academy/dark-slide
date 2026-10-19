<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * A native chart looks like the chart fancy-slides draws from the same option.
 *
 * Found by rendering agent-built decks for review: every chart carried a legend
 * for its single series, dense minor tick marks and no values, and reviewers
 * marked all three as "default styling". ECharts shows a legend only when
 * `option.legend` is set, shows values when a series asks with `label.show`, and
 * draws no minor ticks -- the writer did the opposite on all three.
 *
 * Also here: a shape's outline. fancy-slides draws it in `theme.colors.accent`;
 * the writer drew a fixed purple, so an orange brand got purple-edged rules.
 */
function cpChart(array $option, array $theme = []): string
{
    $deck = [
        'id' => 'cp',
        'title' => 'Chart presentation',
        'theme' => ['name' => 'cp', ...$theme],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'c', 'type' => 'chart', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.6, 'option' => $option,
        ]]]],
    ];

    return cpPart($deck, 'ppt/charts/chart1.xml');
}

function cpPart(array $deck, string $part): string
{
    $path = tempnam(sys_get_temp_dir(), 'cp').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName($part);
    $zip->close();
    @unlink($path);

    return $xml;
}

const CP_BARS = ['xAxis' => ['data' => ['North', 'South', 'EMEA']], 'series' => [['type' => 'bar', 'name' => 'Revenue', 'data' => [1.25, 0.98, 2.1]]]];

it('draws no legend unless the option asks for one, as ECharts does', function () {
    expect(cpChart(CP_BARS))->not->toContain('<c:legend>');
    expect(cpChart([...CP_BARS, 'legend' => []]))->toContain('<c:legend>');
    expect(cpChart([...CP_BARS, 'legend' => ['show' => false]]))->not->toContain('<c:legend>');
});

it('shows a series values when it asks with label.show', function () {
    $labelled = CP_BARS;
    $labelled['series'][0]['label'] = ['show' => true];

    expect(cpChart(CP_BARS))->not->toContain('<c:dLbls>')
        ->and(cpChart($labelled))->toContain('<c:dLbls>')
        ->and(cpChart($labelled))->toContain('<c:showVal val="1"/>');
});

it('labels a pie and a line too', function () {
    $pie = ['series' => [['type' => 'pie', 'label' => ['show' => true], 'data' => [['name' => 'A', 'value' => 1], ['name' => 'B', 'value' => 2]]]]];
    $line = ['xAxis' => ['data' => ['Q1', 'Q2']], 'series' => [['type' => 'line', 'label' => ['show' => true], 'data' => [1, 2]]]];

    expect(cpChart($pie))->toContain('<c:showVal val="1"/>')
        ->and(cpChart($line))->toContain('<c:showVal val="1"/>');
});

it('draws no minor tick marks', function () {
    $xml = cpChart(CP_BARS);

    expect($xml)->toContain('<c:minorTickMark val="none"/>')
        ->and(substr_count($xml, '<c:minorTickMark val="none"/>'))->toBe(2);
});

it('outlines a shape in the theme accent unless it names a stroke', function () {
    $deck = fn (array $shape) => [
        'id' => 'cp', 'title' => 'Shape', 'theme' => ['name' => 'cp', 'colors' => ['accent' => '#F97316']],
        'slides' => [['id' => 's1', 'elements' => [['id' => 'r', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.01, 'fill' => '#F97316', ...$shape]]]],
    ];

    expect(cpPart($deck([]), 'ppt/slides/slide1.xml'))->toContain('<a:ln w="9525"><a:solidFill><a:srgbClr val="F97316"')
        ->and(cpPart($deck([]), 'ppt/slides/slide1.xml'))->not->toContain('8B5CF6')
        ->and(cpPart($deck(['stroke' => '#000000']), 'ppt/slides/slide1.xml'))->toContain('<a:srgbClr val="000000"');
});
