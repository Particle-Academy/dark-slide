<?php

declare(strict_types=1);

namespace DarkSlide\Table;

/**
 * Composite elements — `kpiBand` and `metadataGrid` — as AUTHORING SUGAR.
 *
 * Each expands into an ordinary `table` element before the writer ever sees
 * it. That is a deliberate choice and it has three consequences worth stating,
 * because the alternative (a genuinely new primitive) looks tempting:
 *
 *   1. **No new OOXML surface.** A KPI band is a two-row table with the rule
 *      between the two rows switched off; a metadata grid is a label-over-value
 *      table with every rule switched off. Both were already expressible once
 *      per-cell borders existed — the composite just spares an agent the
 *      arithmetic.
 *   2. **`@particle-academy/fancy-slides` keeps a schema it can render.** A new
 *      element type would be a hole in the JS editor the day it shipped.
 *   3. **A composite READ BACK comes back as its expansion**, a `table`. That
 *      is lossy in one direction and is documented rather than hidden; the
 *      alternative is a reader inventing intent it cannot actually recover.
 */
final class Composites
{
    public const TYPES = ['kpiBand', 'metadataGrid'];

    public static function isComposite(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public static function expand(array $element, array $theme = []): array
    {
        return match ($element['type'] ?? '') {
            'kpiBand' => self::kpiBand($element, $theme),
            'metadataGrid' => self::metadataGrid($element, $theme),
            default => $element,
        };
    }

    /**
     * Four big figures with a small caption under each, in one banded box.
     *
     * The figure and its caption are two table rows that must read as one
     * cell, so the rule between them is turned off from BOTH sides — a border
     * is resolved per cell, and leaving either half on draws the line.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    private static function kpiBand(array $element, array $theme): array
    {
        $items = self::items($element);
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];

        $accent = self::themeColor($theme, 'accent', '#8B5CF6');
        $fill = $style['fill'] ?? null;
        $valueColor = $style['valueColor'] ?? $style['color'] ?? $accent;
        $captionColor = $style['captionColor'] ?? self::themeColor($theme, 'muted', '#64748B');
        // Conservative on purpose: the writer has no font metrics, so it cannot
        // know a figure will fit. Four items across a full-width band at 60
        // wrapped "$51K-$68K" onto two lines. A deck that knows its own
        // content raises it.
        $valueSize = $style['valueFontSize'] ?? 40;
        $captionSize = $style['captionFontSize'] ?? 20;
        $align = $style['align'] ?? 'center';

        $columns = [];
        $values = [];
        $captions = [];
        foreach (array_values($items) as $i => $item) {
            $key = "k{$i}";
            $columns[] = ['key' => $key, 'label' => ''];
            $values[$key] = (string) ($item['value'] ?? '');
            $captions[$key] = (string) ($item['caption'] ?? '');
        }

        // Only the rule BETWEEN kpis, plus the band's own outline.
        $borders = $style['borders'] ?? [
            'inner' => ['width' => 0.75, 'color' => self::themeColor($theme, 'muted', '#D9DEE4')],
            'outer' => ['width' => 0.75, 'color' => self::themeColor($theme, 'muted', '#D9DEE4')],
        ];

        return [
            'id' => $element['id'] ?? 'kpi-band',
            'type' => 'table',
            'x' => $element['x'] ?? 0.06,
            'y' => $element['y'] ?? 0.5,
            'w' => $element['w'] ?? 0.88,
            'h' => $element['h'] ?? 0.2,
            'z' => $element['z'] ?? null,
            'hidden' => $element['hidden'] ?? null,
            'animation' => $element['animation'] ?? null,
            'columns' => $columns,
            'rows' => [
                [
                    'cells' => $values,
                    'height' => $style['valueHeight'] ?? 44,
                    'fontSize' => $valueSize,
                    'color' => $valueColor,
                    'bold' => true,
                    'align' => $align,
                    'anchor' => 'bottom',
                    'borders' => self::withoutSide($borders, 'bottom'),
                ],
                [
                    'cells' => $captions,
                    'height' => $style['captionHeight'] ?? 30,
                    'fontSize' => $captionSize,
                    'color' => $captionColor,
                    'align' => $align,
                    'anchor' => 'top',
                    'borders' => self::withoutSide($borders, 'top'),
                ],
            ],
            'style' => [
                'header' => false,
                'stripe' => false,
                'fill' => $fill,
                'padding' => $style['padding'] ?? ['left' => 8, 'right' => 8, 'top' => 2, 'bottom' => 2],
            ],
        ];
    }

    /**
     * A label/value metadata panel, `columns` across and as many rows down as
     * the items need. Labels are letterspaced small caps — the eyebrow
     * treatment the construct exists for.
     *
     * A short final row is PADDED to full width. A ragged row is a shorter
     * `<a:tr>` than the grid declares, which is a corrupt file rather than a
     * cosmetic problem.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    private static function metadataGrid(array $element, array $theme): array
    {
        $items = array_values(self::items($element));
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];

        $across = isset($element['columns']) && is_numeric($element['columns'])
            ? max(1, (int) $element['columns'])
            : 3;

        $labelColor = $style['labelColor'] ?? self::themeColor($theme, 'muted', '#64748B');
        $valueColor = $style['valueColor'] ?? self::themeColor($theme, 'text', '#0F172A');
        $fill = $style['fill'] ?? null;

        $columns = [];
        for ($i = 0; $i < $across; $i++) {
            $columns[] = ['key' => "c{$i}", 'label' => ''];
        }

        $rows = [];
        foreach (array_chunk($items, $across) as $chunk) {
            $labels = [];
            $values = [];
            for ($i = 0; $i < $across; $i++) {
                $item = is_array($chunk[$i] ?? null) ? $chunk[$i] : [];
                $labels["c{$i}"] = (string) ($item['label'] ?? '');
                $values["c{$i}"] = (string) ($item['value'] ?? '');
            }
            $rows[] = [
                'cells' => $labels,
                'height' => $style['labelHeight'] ?? 18,
                'fontSize' => $style['labelFontSize'] ?? 18,
                'color' => $labelColor,
                'letterSpacing' => $style['labelLetterSpacing'] ?? 1.2,
                'caps' => 'small',
                'bold' => true,
                'anchor' => 'bottom',
            ];
            $rows[] = [
                'cells' => $values,
                'height' => $style['valueHeight'] ?? 26,
                'fontSize' => $style['valueFontSize'] ?? 28,
                'color' => $valueColor,
                'bold' => true,
                'anchor' => 'top',
            ];
        }

        return [
            'id' => $element['id'] ?? 'metadata-grid',
            'type' => 'table',
            'x' => $element['x'] ?? 0.06,
            'y' => $element['y'] ?? 0.5,
            'w' => $element['w'] ?? 0.88,
            'h' => $element['h'] ?? 0.22,
            'z' => $element['z'] ?? null,
            'hidden' => $element['hidden'] ?? null,
            'animation' => $element['animation'] ?? null,
            'columns' => $columns,
            'rows' => $rows,
            'style' => [
                'header' => false,
                'stripe' => false,
                'borders' => $style['borders'] ?? false,
                'fill' => $fill,
                'padding' => $style['padding'] ?? ['left' => 10, 'right' => 10, 'top' => 2, 'bottom' => 2],
            ],
        ];
    }

    /**
     * Drop one side from a border spec, so two stacked rows read as one cell.
     *
     * @param  mixed  $borders
     * @return mixed
     */
    private static function withoutSide(mixed $borders, string $side): mixed
    {
        if (! is_array($borders)) {
            return $borders;
        }
        $borders[$side] = false;

        return $borders;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<int, array<string, mixed>>
     */
    private static function items(array $element): array
    {
        $items = is_array($element['items'] ?? null) ? $element['items'] : [];

        return array_values(array_filter($items, 'is_array'));
    }

    /** @param array<string, mixed> $theme */
    private static function themeColor(array $theme, string $key, string $fallback): string
    {
        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
        $value = $colors[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
