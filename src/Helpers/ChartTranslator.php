<?php

declare(strict_types=1);

namespace DarkSlide\Helpers;

/**
 * Translate an Apache ECharts-style `option` object into a normalised
 * chart spec the {@see \DarkSlide\Writer\PptxWriter} can emit as native
 * OOXML chart parts.
 *
 * The translator is deliberately forgiving about the many shapes ECharts
 * accepts: categories may live on `xAxis.data` or `xAxis[0].data`; series data
 * points may be bare numbers, `{value}` objects, or `{name,value}` pairs (pie).
 * Anything it cannot understand yields `null`, which the writer treats as a cue
 * to fall back to an image or a titled placeholder rather than crash.
 *
 * `categories` really is a third place to put them, as of 0.13.0. It was not
 * before: `extractCategories()` seeded its candidate list with `[]`, which is
 * already an array, so the fallback fired only when an `xAxis` was present
 * WITHOUT `data` -- while the Node engine seeded `null` and honoured it. A
 * three-way split on a path byte parity never reaches, since the reference deck
 * carries no chart. Measured and ruled 2026-10-07: the two engines that ignored
 * it now match Node, which is what this docblock had claimed all along.
 *
 * Pure data, no runtime dependencies.
 *
 * @phpstan-type ChartSeries array{type: string, name: string, values: list<float>, smooth: bool, area: bool, points: list<array{x: float, y: float}>, labels: bool, color: ?string, pointColors: list<array{index: int, color: string}>}
 * @phpstan-type ChartSpec array{kind: string, title: string, categories: list<string>, series: list<ChartSeries>, legend: bool, palette: list<string>}
 */
final class ChartTranslator
{
    /**
     * ECharts series types we can render as native OOXML charts.
     *
     * WIDENING IS SAFE, NARROWING IS BREAKING, and not only for our own output: the
     * list is published in the deck schema as `option.series.items.type`'s enum, and a
     * consumer generating their writer vocabulary from that schema guards it — their
     * craft guidance tells authoring agents to stay inside these four, because a type
     * outside them makes the WHOLE option untranslatable and leaves a same-sized
     * placeholder box with a file still written and nothing raised. Drop one and their
     * test goes red before a writer finds out, which is the arrangement working; add
     * one and nothing breaks anywhere.
     */
    public const SUPPORTED_TYPES = ['bar', 'line', 'pie', 'scatter'];

    /**
     * Translate an ECharts option into a normalised chart spec.
     *
     * @param  array<string, mixed>  $option
     * @return ChartSpec|null  null when nothing renderable could be derived
     */
    public static function translate(array $option): ?array
    {
        $rawSeries = self::extractSeries($option);
        if ($rawSeries === []) {
            return null;
        }

        $categories = self::extractCategories($option);
        $series = [];
        $kind = null;

        foreach ($rawSeries as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = is_string($raw['type'] ?? null) ? strtolower((string) $raw['type']) : 'bar';
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                return null;
            }

            $normalised = self::normaliseSeries($raw, $type);
            if ($normalised === null) {
                return null;
            }

            $kind ??= $type;
            $series[] = $normalised;

            if ($type === 'pie' && $categories === []) {
                $categories = self::pieCategories($raw);
            }
        }

        if ($series === [] || $kind === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'title' => self::extractTitle($option),
            'categories' => $categories,
            'series' => $series,
            'legend' => self::wantsLegend($option),
            'palette' => self::palette($option),
        ];
    }

    /**
     * `option.color`, ECharts' palette: the colours series take in turn (slices,
     * for a pie). Entries that are not #RRGGBB are skipped.
     *
     * @param  array<string, mixed>  $option
     * @return list<string>  upper-case hex, no '#'
     */
    private static function palette(array $option): array
    {
        $out = [];
        foreach (is_array($option['color'] ?? null) ? array_values($option['color']) : [] as $color) {
            $hex = self::hexColor($color);
            if ($hex !== null) {
                $out[] = $hex;
            }
        }

        return $out;
    }

    /** `#RRGGBB` as upper-case hex without the '#', or null. */
    private static function hexColor(mixed $color): ?string
    {
        return is_string($color) && preg_match('/^#([0-9A-Fa-f]{6})$/', $color, $m) === 1 ? strtoupper($m[1]) : null;
    }

    /** `itemStyle.color` of a series or a data item, or null. */
    private static function itemColor(mixed $holder): ?string
    {
        return is_array($holder) && is_array($holder['itemStyle'] ?? null) ? self::hexColor($holder['itemStyle']['color'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $option
     * @return list<mixed>
     */
    private static function extractSeries(array $option): array
    {
        $series = $option['series'] ?? null;
        if (is_array($series) && array_is_list($series)) {
            return $series;
        }
        if (is_array($series) && $series !== []) {
            return [$series];
        }

        return [];
    }

    /**
     * Category labels, from `xAxis.data`, `xAxis[0].data` or `categories`.
     *
     * The seed is `null` and NOT `[]`, which is the whole of the fix the owner
     * ruled on 2026-10-07: `[]` is already an array, so the `categories` fallback
     * below could only fire when an `xAxis` was present WITHOUT `data`, and a deck
     * using `categories` alone silently got `1, 2, 3 ...` labels. The Node engine
     * seeded `null` and honoured it, so the trio was split three ways on a path
     * byte parity never reaches -- the reference deck carries no chart.
     *
     * @param  array<string, mixed>  $option
     * @return list<string>
     */
    private static function extractCategories(array $option): array
    {
        $candidates = null;
        $xAxis = $option['xAxis'] ?? null;
        if (is_array($xAxis)) {
            if (array_is_list($xAxis) && isset($xAxis[0]) && is_array($xAxis[0])) {
                $candidates = $xAxis[0]['data'] ?? null;
            } else {
                $candidates = $xAxis['data'] ?? null;
            }
        }
        if (!is_array($candidates)) {
            $candidates = $option['categories'] ?? null;
        }
        if (!is_array($candidates)) {
            return [];
        }

        $out = [];
        foreach ($candidates as $value) {
            if (is_scalar($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private static function extractTitle(array $option): string
    {
        $title = $option['title'] ?? null;
        if (is_array($title)) {
            $text = $title['text'] ?? ($title[0]['text'] ?? null);
            if (is_string($text)) {
                return $text;
            }
        }
        if (is_string($title)) {
            return $title;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return ChartSeries|null
     */
    private static function normaliseSeries(array $raw, string $type): ?array
    {
        $name = is_scalar($raw['name'] ?? null) ? (string) $raw['name'] : '';
        $data = $raw['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $values = [];
        $points = [];
        $pointColors = [];
        foreach (array_values($data) as $i => $point) {
            $pointColor = $type === 'scatter' ? null : self::itemColor($point);
            if ($pointColor !== null) {
                $pointColors[] = ['index' => $i, 'color' => $pointColor];
            }
            if ($type === 'scatter') {
                $xy = self::scatterPoint($point);
                if ($xy !== null) {
                    $points[] = $xy;
                }

                continue;
            }
            $values[] = self::numericValue($point);
        }

        if ($type === 'scatter' && $points === []) {
            return null;
        }
        if ($type !== 'scatter' && $values === []) {
            return null;
        }

        $area = $type === 'line' && isset($raw['areaStyle']);

        return [
            'type' => $type,
            'name' => $name,
            'values' => $values,
            'smooth' => !empty($raw['smooth']),
            'area' => $area,
            'points' => $points,
            // ECharts' `label.show`: the series' values drawn on the chart.
            'labels' => is_array($raw['label'] ?? null) && ($raw['label']['show'] ?? false) === true,
            // ECharts' itemStyle.color: the series' own colour, and a point's.
            'color' => self::itemColor($raw),
            'pointColors' => $pointColors,
        ];
    }

    /**
     * Whether the chart draws a legend: only when `option.legend` is set and not
     * switched off, which is when ECharts draws one.
     *
     * @param  array<string, mixed>  $option
     */
    private static function wantsLegend(array $option): bool
    {
        $legend = $option['legend'] ?? null;

        return is_array($legend) && ($legend['show'] ?? true) !== false;
    }

    /**
     * Coerce a single ECharts data point into a numeric value. Accepts a
     * bare number, a `{value}` object, or a `{name,value}` pie slice.
     *
     * @param  mixed  $point
     */
    private static function numericValue(mixed $point): float
    {
        if (is_numeric($point)) {
            return (float) $point;
        }
        if (is_array($point) && isset($point['value']) && is_numeric($point['value'])) {
            return (float) $point['value'];
        }

        return 0.0;
    }

    /**
     * Extract an `{x, y}` pair from a scatter data point. ECharts scatter
     * points are usually `[x, y]` arrays but may be `{value: [x, y]}`.
     *
     * @param  mixed  $point
     * @return array{x: float, y: float}|null
     */
    private static function scatterPoint(mixed $point): ?array
    {
        $pair = $point;
        if (is_array($point) && isset($point['value']) && is_array($point['value'])) {
            $pair = $point['value'];
        }
        if (is_array($pair) && array_is_list($pair) && count($pair) >= 2 && is_numeric($pair[0]) && is_numeric($pair[1])) {
            return ['x' => (float) $pair[0], 'y' => (float) $pair[1]];
        }

        return null;
    }

    /**
     * Derive pie categories from a single pie series' `{name,value}` data.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function pieCategories(array $raw): array
    {
        $data = $raw['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $i => $point) {
            if (is_array($point) && isset($point['name']) && is_scalar($point['name'])) {
                $out[] = (string) $point['name'];
            } else {
                $out[] = 'Slice ' . ($i + 1);
            }
        }

        return $out;
    }
}
