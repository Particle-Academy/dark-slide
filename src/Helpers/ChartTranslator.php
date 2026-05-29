<?php

declare(strict_types=1);

namespace DarkSlide\Helpers;

/**
 * Translate an Apache ECharts-style `option` object into a normalised
 * chart spec the {@see \DarkSlide\Writer\PptxWriter} can emit as native
 * OOXML chart parts.
 *
 * The translator is deliberately forgiving about the many shapes ECharts
 * accepts: categories may live on `xAxis.data`, `xAxis[0].data`, or
 * `categories`; series data points may be bare numbers, `{value}`
 * objects, or `{name,value}` pairs (pie). Anything it cannot understand
 * yields `null`, which the writer treats as a cue to fall back to an
 * image or a titled placeholder rather than crash.
 *
 * Pure data, no runtime dependencies.
 *
 * @phpstan-type ChartSeries array{type: string, name: string, values: list<float>, smooth: bool, area: bool, points: list<array{x: float, y: float}>}
 * @phpstan-type ChartSpec array{kind: string, title: string, categories: list<string>, series: list<ChartSeries>}
 */
final class ChartTranslator
{
    /** ECharts series types we can render as native OOXML charts. */
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
        ];
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
     * @param  array<string, mixed>  $option
     * @return list<string>
     */
    private static function extractCategories(array $option): array
    {
        $candidates = [];
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
        foreach ($data as $point) {
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
        ];
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
