<?php

declare(strict_types=1);

namespace DarkSlide\Table;

/**
 * Turns a loose agent-authored `table` element into a fully-resolved table,
 * where every cell already carries every decision. The writer only serialises
 * what comes out of here; it makes no styling choices of its own.
 *
 * ## Why this is a separate, pure class
 *
 * The resolved shape is expressed in POINTS and 6-digit hex, never EMU, so it
 * is document-format-neutral. `last-word` (docx) needs exactly the same
 * decisions — per-side borders, cell insets, vertical anchor, spans — and
 * renders them as `w:tcBorders` / `w:tcMar` / `w:vAlign` / `w:gridSpan`. The
 * shared contract between the two packages is THIS MODEL, not the XML, and it
 * is pinned as a cross-language table in `fancy-conformance`
 * (`shared/table-cell-control`) rather than transcribed into either repo.
 *
 * ## Precedence, which is the whole design
 *
 *     cell > row > column > band (header|stripe|body) > table > theme > default
 *
 * A key that is ABSENT falls through. A key present with the value `false`
 * stops the chain and means "off" — that distinction is why this code uses
 * `array_key_exists` rather than `??` in the places it does.
 */
final class TableResolver
{
    /** Schema font sizes are halved to points, matching text elements. */
    public const DEFAULT_FONT_SIZE = 28;

    public const DEFAULT_BODY_COLOR = '#0F172A';

    public const DEFAULT_HEADER_COLOR = '#FFFFFF';

    public const DEFAULT_ACCENT = '#8B5CF6';

    public const DEFAULT_STRIPE_FILL = '#F8FAFC';

    public const DEFAULT_BORDER_COLOR = '#D9DEE4';

    public const DEFAULT_BORDER_WIDTH = 0.75;

    public const DEFAULT_PADDING_X = 7.2;

    public const DEFAULT_PADDING_Y = 3.6;

    public const DEFAULT_HEADER_HEIGHT = 40;

    public const DEFAULT_BODY_HEIGHT = 30;

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $theme
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, hasHeader: bool}
     */
    public static function resolve(array $element, array $theme = []): array
    {
        $columns = self::normalizeColumns(is_array($element['columns'] ?? null) ? $element['columns'] : []);
        $rawRows = is_array($element['rows'] ?? null) ? $element['rows'] : [];
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];

        $accent = self::themeColor($theme, 'accent', self::DEFAULT_ACCENT);

        $headerSpec = $style['header'] ?? [];
        $hasHeader = $headerSpec !== false;
        $headerStyle = is_array($headerSpec) ? $headerSpec : [];

        $bodyStyle = is_array($style['body'] ?? null) ? $style['body'] : [];

        $stripeSpec = $style['stripe'] ?? [];
        $stripeOn = $stripeSpec !== false;
        $stripeStyle = is_array($stripeSpec) ? $stripeSpec : [];

        $tableDefaults = self::tableDefaults($accent);
        $tableStyle = self::styleKeys($style);

        // Build the logical grid first, so a span knows what it covers before
        // any cell is resolved. A short row is a corrupt file, so every row is
        // exactly count($columns) wide by construction.
        $grid = self::buildGrid($columns, $rawRows, $hasHeader);

        $rows = [];
        $rowCount = count($grid);

        foreach ($grid as $r => $gridRow) {
            $isHeader = $hasHeader && $r === 0;
            $rowSource = $gridRow['source'];
            $rowStyle = self::styleKeys($rowSource);

            $bandStyle = $isHeader
                ? array_merge(self::headerDefaults($accent), $headerStyle)
                : $bodyStyle;

            // Striping is counted over BODY rows only, and the first body row
            // is never striped — which is what the old writer did.
            if (! $isHeader && $stripeOn) {
                $bodyIndex = $hasHeader ? $r - 1 : $r;
                if ($bodyIndex % 2 === 1) {
                    $bandStyle = array_merge($bandStyle, self::stripeDefaults(), $stripeStyle);
                }
            }

            $cells = [];
            foreach ($gridRow['cells'] as $c => $slot) {
                $cells[] = self::resolveCell(
                    $slot,
                    $columns[$c],
                    [$tableDefaults, $tableStyle, $bandStyle, self::styleKeys($columns[$c]), $rowStyle],
                    [
                        'firstRow' => $r === 0,
                        'lastRow' => $r === $rowCount - 1,
                        'firstCol' => $c === 0,
                        'lastCol' => $c === count($columns) - 1,
                    ],
                );
            }

            $rows[] = [
                'header' => $isHeader,
                'height' => self::rowHeight($rowSource, $rowStyle, $bandStyle, $tableStyle, $isHeader),
                'cells' => $cells,
            ];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'hasHeader' => $hasHeader,
        ];
    }

    // ─── Columns ──────────────────────────────────────────────────────────

    /**
     * Normalise the column list, resolving widths to fractions that sum to 1.
     *
     * Two modes, chosen by the values themselves:
     *
     *   - every declared width <= 1 → they are FRACTIONS of the table, and
     *     columns without one share whatever is left over;
     *   - any declared width > 1    → they are WEIGHTS, and columns without
     *     one weigh 1.
     *
     * No width anywhere is an equal split, which is what the writer did before
     * widths were reachable at all.
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    public static function normalizeColumns(array $raw): array
    {
        $columns = [];
        foreach (array_values($raw) as $i => $col) {
            $col = is_array($col) ? $col : ['key' => (string) $col];
            $columns[] = [
                'key' => (string) ($col['key'] ?? "col{$i}"),
                'label' => (string) ($col['label'] ?? $col['key'] ?? ''),
                'width' => isset($col['width']) && is_numeric($col['width']) && (float) $col['width'] > 0
                    ? (float) $col['width']
                    : null,
                'align' => $col['align'] ?? null,
                'anchor' => $col['anchor'] ?? null,
            ];
        }

        $n = count($columns);
        if ($n === 0) {
            return [];
        }

        $declared = array_filter(array_column($columns, 'width'), fn ($w) => $w !== null);

        if ($declared === []) {
            foreach ($columns as $i => $_) {
                $columns[$i]['widthFrac'] = 1 / $n;
            }

            return $columns;
        }

        $asFractions = max($declared) <= 1.0;
        $undeclared = $n - count($declared);

        if ($asFractions) {
            $remaining = max(0.0, 1.0 - array_sum($declared));
            $share = $undeclared > 0 ? $remaining / $undeclared : 0.0;
            $weights = array_map(fn ($col) => $col['width'] ?? $share, $columns);
        } else {
            $weights = array_map(fn ($col) => $col['width'] ?? 1.0, $columns);
        }

        $total = array_sum($weights);
        foreach ($columns as $i => $_) {
            $columns[$i]['widthFrac'] = $total > 0 ? $weights[$i] / $total : 1 / $n;
        }

        return $columns;
    }

    /**
     * Column widths in EMU that sum EXACTLY to the table width.
     *
     * Rounding each fraction independently loses or gains a few EMU and leaves
     * the grid a hair narrower or wider than the frame; accumulating and
     * differencing cannot.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<int>
     */
    public static function columnWidthsEmu(array $columns, int $totalEmu): array
    {
        $out = [];
        $cum = 0.0;
        $prev = 0;
        foreach ($columns as $col) {
            $cum += (float) $col['widthFrac'];
            $edge = (int) round($cum * $totalEmu);
            $out[] = $edge - $prev;
            $prev = $edge;
        }

        return $out;
    }

    // ─── The grid ─────────────────────────────────────────────────────────

    /**
     * Lay every row out as exactly count($columns) slots, marking the ones a
     * span swallows. Spans are clamped to the grid: a `colSpan` of 99 on a
     * two-column table is a 2, never a row with 99 cells in it.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  array<int|string, mixed>  $rawRows
     * @return list<array{source: array<string, mixed>, cells: list<array<string, mixed>>}>
     */
    private static function buildGrid(array $columns, array $rawRows, bool $hasHeader): array
    {
        $n = count($columns);
        $grid = [];

        if ($hasHeader) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = ['spec' => ['text' => $col['label']], 'merged' => 'none', 'colSpan' => 1, 'rowSpan' => 1];
            }
            $grid[] = ['source' => [], 'cells' => $cells];
        }

        foreach (array_values($rawRows) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cellMap = is_array($row['cells'] ?? null) ? $row['cells'] : $row;
            $cells = [];
            foreach ($columns as $col) {
                $value = $cellMap[$col['key']] ?? null;
                $cells[] = [
                    'spec' => self::cellSpec($value),
                    'merged' => 'none',
                    'colSpan' => 1,
                    'rowSpan' => 1,
                ];
            }
            $grid[] = ['source' => $row, 'cells' => $cells];
        }

        // Apply spans over the laid-out grid.
        foreach ($grid as $r => $gridRow) {
            foreach ($gridRow['cells'] as $c => $slot) {
                if ($grid[$r]['cells'][$c]['merged'] !== 'none') {
                    continue;
                }
                $spec = $slot['spec'];
                $colSpan = self::clampSpan($spec['colSpan'] ?? 1, $n - $c);
                $rowSpan = self::clampSpan($spec['rowSpan'] ?? 1, count($grid) - $r);

                $grid[$r]['cells'][$c]['colSpan'] = $colSpan;
                $grid[$r]['cells'][$c]['rowSpan'] = $rowSpan;

                for ($dr = 0; $dr < $rowSpan; $dr++) {
                    for ($dc = 0; $dc < $colSpan; $dc++) {
                        if ($dr === 0 && $dc === 0) {
                            continue;
                        }
                        $covered = $dc > 0 && $dr > 0 ? 'both' : ($dc > 0 ? 'horizontal' : 'vertical');
                        $grid[$r + $dr]['cells'][$c + $dc]['merged'] = $covered;
                        $grid[$r + $dr]['cells'][$c + $dc]['spec'] = ['text' => ''];
                    }
                }
            }
        }

        return $grid;
    }

    private static function clampSpan(mixed $span, int $available): int
    {
        $span = is_numeric($span) ? (int) $span : 1;

        return max(1, min($span, max(1, $available)));
    }

    /**
     * A cell value is either a scalar, or a spec object.
     *
     * BEHAVIOUR CHANGE: an array used to be `json_encode`d into the cell text.
     * Anything with none of the spec keys still is, so a genuine nested value
     * an agent meant to display is not silently emptied.
     *
     * @return array<string, mixed>
     */
    private static function cellSpec(mixed $value): array
    {
        if (is_array($value)) {
            $specKeys = ['text', 'colSpan', 'rowSpan', 'fill', 'color', 'bold', 'italic', 'underline',
                'align', 'anchor', 'fontSize', 'letterSpacing', 'caps', 'fontFamily', 'padding', 'borders'];
            if (array_intersect($specKeys, array_keys($value)) !== []) {
                $spec = $value;
                $spec['text'] = self::scalarText($value['text'] ?? '');

                return $spec;
            }

            return ['text' => (string) json_encode($value)];
        }

        return ['text' => self::scalarText($value)];
    }

    private static function scalarText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    // ─── One cell ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $column
     * @param  list<array<string, mixed>>  $chain  ordered low → high precedence
     * @param  array<string, bool>  $edges
     * @return array<string, mixed>
     */
    private static function resolveCell(array $slot, array $column, array $chain, array $edges): array
    {
        $spec = $slot['spec'];
        $merged = $slot['merged'];

        $layers = $chain;
        $layers[] = self::styleKeys($spec);

        $resolved = [];
        foreach ($layers as $layer) {
            foreach ($layer as $k => $v) {
                $resolved[$k] = $v;
            }
        }

        $fill = $resolved['fill'] ?? null;
        $borders = self::resolveBorders($layers, $edges);
        $padding = self::resolvePadding($resolved['padding'] ?? null);

        return [
            'text' => (string) ($spec['text'] ?? ''),
            'bold' => (bool) ($resolved['bold'] ?? false),
            'italic' => (bool) ($resolved['italic'] ?? false),
            'underline' => (bool) ($resolved['underline'] ?? false),
            'color' => self::hex($resolved['color'] ?? self::DEFAULT_BODY_COLOR, '0F172A'),
            'fill' => $fill === false || $fill === null || $fill === 'none' ? null : self::hex($fill, 'FFFFFF'),
            'align' => self::align($resolved['align'] ?? 'left'),
            'anchor' => self::anchor($resolved['anchor'] ?? 'middle'),
            'fontSize' => max(1.0, (float) ($resolved['fontSize'] ?? self::DEFAULT_FONT_SIZE) / 2),
            'letterSpacing' => (float) ($resolved['letterSpacing'] ?? 0),
            'caps' => self::caps($resolved['caps'] ?? 'none'),
            'fontFamily' => isset($resolved['fontFamily']) ? (string) $resolved['fontFamily'] : null,
            'padding' => $padding,
            'borders' => $borders,
            'colSpan' => (int) $slot['colSpan'],
            'rowSpan' => (int) $slot['rowSpan'],
            'merged' => $merged,
        ];
    }

    /**
     * Per-side border resolution. The whole point of the class, and the part
     * `last-word` needs identically.
     *
     * @param  list<array<string, mixed>>  $layers  ordered low → high precedence
     * @param  array<string, bool>  $edges
     * @return array<string, array<string, mixed>|null>
     */
    private static function resolveBorders(array $layers, array $edges): array
    {
        $sides = ['left' => 'firstCol', 'right' => 'lastCol', 'top' => 'firstRow', 'bottom' => 'lastRow'];
        $out = [];

        foreach ($sides as $side => $edgeKey) {
            $isOuter = $edges[$edgeKey];
            $value = ['width' => self::DEFAULT_BORDER_WIDTH, 'color' => self::DEFAULT_BORDER_COLOR];

            foreach ($layers as $layer) {
                if (! array_key_exists('borders', $layer)) {
                    continue;
                }
                $spec = $layer['borders'];

                if ($spec === false || $spec === null || $spec === 'none') {
                    $value = null;

                    continue;
                }
                if (! is_array($spec)) {
                    continue;
                }
                if (! empty($spec['none'])) {
                    $value = null;

                    continue;
                }

                // A bare {width,color,style} means all four sides.
                if (isset($spec['width']) || isset($spec['color']) || isset($spec['style'])) {
                    $value = $spec;
                }
                if (array_key_exists('all', $spec)) {
                    $value = $spec['all'];
                }
                $band = $isOuter ? 'outer' : 'inner';
                if (array_key_exists($band, $spec)) {
                    $value = $spec[$band];
                }
                if (array_key_exists($side, $spec)) {
                    $value = $spec[$side];
                }
            }

            $out[$side] = self::borderSide($value);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function borderSide(mixed $value): ?array
    {
        if ($value === false || $value === null || $value === 'none') {
            return null;
        }
        if (! is_array($value)) {
            return null;
        }
        $width = isset($value['width']) && is_numeric($value['width'])
            ? (float) $value['width']
            : self::DEFAULT_BORDER_WIDTH;
        if ($width <= 0) {
            return null;
        }

        $style = (string) ($value['style'] ?? 'solid');

        return [
            'width' => $width,
            'color' => self::hex($value['color'] ?? self::DEFAULT_BORDER_COLOR, 'D9DEE4'),
            'style' => in_array($style, ['solid', 'dash', 'dot'], true) ? $style : 'solid',
        ];
    }

    /** @return array<string, float> */
    private static function resolvePadding(mixed $padding): array
    {
        $default = [
            'left' => self::DEFAULT_PADDING_X,
            'right' => self::DEFAULT_PADDING_X,
            'top' => self::DEFAULT_PADDING_Y,
            'bottom' => self::DEFAULT_PADDING_Y,
        ];

        if (is_numeric($padding)) {
            $v = (float) $padding;

            return ['left' => $v, 'right' => $v, 'top' => $v, 'bottom' => $v];
        }
        if (is_array($padding)) {
            foreach ($default as $side => $_) {
                if (isset($padding[$side]) && is_numeric($padding[$side])) {
                    $default[$side] = (float) $padding[$side];
                }
            }
        }

        return $default;
    }

    // ─── Bands + defaults ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function tableDefaults(string $accent): array
    {
        return [
            'color' => self::DEFAULT_BODY_COLOR,
            'align' => 'left',
            'anchor' => 'middle',
            'fontSize' => self::DEFAULT_FONT_SIZE,
        ];
    }

    /** @return array<string, mixed> */
    private static function headerDefaults(string $accent): array
    {
        return ['fill' => $accent, 'color' => self::DEFAULT_HEADER_COLOR, 'bold' => true];
    }

    /** @return array<string, mixed> */
    private static function stripeDefaults(): array
    {
        return ['fill' => self::DEFAULT_STRIPE_FILL];
    }

    /**
     * The style keys a layer may contribute. Filtering by an allow-list keeps
     * a row's `cells` / `height` (and a column's `key` / `label`) from leaking
     * into a cell's resolved style.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function styleKeys(array $source): array
    {
        $keys = ['fill', 'color', 'bold', 'italic', 'underline', 'align', 'anchor',
            'fontSize', 'letterSpacing', 'caps', 'fontFamily', 'padding', 'borders'];

        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $source) && $source[$k] !== null) {
                $out[$k] = $source[$k];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rowSource
     * @param  array<string, mixed>  $rowStyle
     * @param  array<string, mixed>  $bandStyle
     * @param  array<string, mixed>  $tableStyle
     */
    private static function rowHeight(array $rowSource, array $rowStyle, array $bandStyle, array $tableStyle, bool $isHeader): float
    {
        foreach ([$rowSource['height'] ?? null, $bandStyle['height'] ?? null, $tableStyle['rowHeight'] ?? null] as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return $isHeader ? self::DEFAULT_HEADER_HEIGHT : self::DEFAULT_BODY_HEIGHT;
    }

    // ─── Small coercions ──────────────────────────────────────────────────

    /** @param array<string, mixed> $theme */
    private static function themeColor(array $theme, string $key, string $fallback): string
    {
        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
        $value = $colors[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private static function hex(mixed $value, string $fallback): string
    {
        return \DarkSlide\Helpers\Color::parse(is_string($value) ? $value : null, $fallback)[0];
    }

    private static function align(mixed $value): string
    {
        return match ((string) $value) {
            'center', 'centre' => 'center',
            'right' => 'right',
            'justify' => 'justify',
            default => 'left',
        };
    }

    private static function anchor(mixed $value): string
    {
        return match ((string) $value) {
            'top' => 'top',
            'bottom' => 'bottom',
            default => 'middle',
        };
    }

    private static function caps(mixed $value): string
    {
        return match ((string) $value) {
            'small' => 'small',
            'all', 'upper' => 'all',
            default => 'none',
        };
    }
}
