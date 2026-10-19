<?php

declare(strict_types=1);

namespace DarkSlide;

use DarkSlide\Helpers\DesignUnits;

/**
 * Layout findings for a deck, computed from the deck alone: what will look
 * wrong on the slide, named precisely enough for an agent to fix it.
 *
 * Pure and read-only: the deck is not changed. `Layout::fit` CHANGES a slide
 * to tidy it; this REPORTS, so the author keeps control of the fix. Built after
 * measuring it in fancy-labs: decks whose title wrapped onto the accent rule
 * beneath it were gated 2 runs in 2; with these findings handed back from the
 * write, 0 in 2, for one extra write.
 *
 * ## The metrics are conservative on purpose
 *
 * The failure this exists for is a font the viewer does not have. A substitute
 * is usually WIDER than the brand face, so a line that fits exactly in the brand
 * face wraps in the substitute. Bold or heading text is estimated at 0.62em per
 * character and other text at 0.55em, lines at 1.25x the font size. Box insets
 * are PowerPoint's defaults (7.2pt left/right, 3.6pt top/bottom) unless the
 * element sets `style.padding`. All lengths are design pixels on the deck's
 * canvas (`theme.slideWidth`, 1920 by default), as the writer uses them.
 *
 * ## Findings
 *
 * `{code, slide (1-based), element (id), message}`, in slide order, per-element
 * checks first and overlaps after. Codes: `text-overflow`, `overlap`,
 * `off-slide`, `kpi-overflow`, `small-text`, `literal-markdown`. Every number in a message is rounded
 * UP to its shown precision, so the three engines print the same text.
 *
 * The same rules, messages and cases are in `@particle-academy/dark-slide` and
 * `fancy-dark-slide`.
 */
final class Lint
{
    /** Text below this many points is too small to read when projected. */
    private const MIN_PT = 12.0;

    private const BOLD_GLYPH = 0.62;

    private const GLYPH = 0.55;

    private const LINE = 1.25;

    /** A KPI cell's horizontal padding, design px (the composite's default). */
    private const KPI_PAD = 64.0;

    /**
     * @param  array<string, mixed>  $deck
     * @return list<array{code: string, slide: int, element: string, message: string}>
     */
    public static function deck(array $deck): array
    {
        $theme = is_array($deck['theme'] ?? null) ? $deck['theme'] : [];
        $canvasW = DesignUnits::designWidth($theme);
        $ratio = is_numeric($theme['aspectRatio'] ?? null) && (float) $theme['aspectRatio'] > 0 ? (float) $theme['aspectRatio'] : 16 / 9;
        $canvasH = $canvasW / $ratio;
        $headingFont = is_string($theme['fonts']['heading'] ?? null) ? $theme['fonts']['heading'] : '';

        $findings = [];
        $slides = is_array($deck['slides'] ?? null) ? array_values($deck['slides']) : [];
        foreach ($slides as $n => $slide) {
            $slideNo = $n + 1;
            $items = [];
            $elements = is_array($slide['elements'] ?? null) ? array_values($slide['elements']) : [];
            foreach ($elements as $i => $el) {
                if (! is_array($el)) {
                    continue;
                }
                $box = self::box($el);
                if ($box === null) {
                    continue;
                }
                $id = self::label($el['id'] ?? null) ?? 'element '.($i + 1);
                $type = is_string($el['type'] ?? null) ? $el['type'] : '';

                if ($box['x'] < -0.001 || $box['y'] < -0.001 || $box['x'] + $box['w'] > 1.001 || $box['y'] + $box['h'] > 1.001) {
                    $findings[] = self::finding('off-slide', $slideNo, $id, 'The box runs off the slide: x + w and y + h must stay within 0..1.');
                }

                $extent = $box;
                if ($type === 'text') {
                    $style = is_array($el['style'] ?? null) ? $el['style'] : [];
                    $font = is_numeric($style['fontSize'] ?? null) ? (float) $style['fontSize'] : 28.0;
                    $need = self::textHeight($el, $style, $font, $box['w'] * $canvasW, $canvasW, $headingFont);
                    if ($need !== null) {
                        $needH = $need / $canvasH;
                        $extent['h'] = $needH;
                        $extent['y'] = match ($style['verticalAlign'] ?? 'top') {
                            'middle' => $box['y'] + ($box['h'] - $needH) / 2,
                            'bottom' => $box['y'] + $box['h'] - $needH,
                            default => $box['y'],
                        };
                        if ($need > $box['h'] * $canvasH + 1) {
                            $up = self::up2($needH);
                            $findings[] = self::finding('text-overflow', $slideNo, $id, sprintf(
                                'At %spx this text needs about %s of the slide height and its box is %s: it will wrap past the box, more so in a substitute font. Make h at least %s and move what is below it down, shorten the text, or lower fontSize.',
                                self::num($font), $up, self::num($box['h']), $up,
                            ));
                        }
                    }
                    $pt = $font * 720 / $canvasW;
                    if ($pt < self::MIN_PT) {
                        $findings[] = self::finding('small-text', $slideNo, $id, sprintf(
                            'fontSize %s is %spt on the slide: too small to read when projected. Use %s (12pt) or more.',
                            self::num($font), self::num(floor($pt * 10) / 10), self::num(ceil(self::MIN_PT * $canvasW / 720)),
                        ));
                    }
                }

                // Markdown in a box that is not markdown prints its markers as
                // typed: "**The problem.**" on the slide, asterisks and all.
                if (($type === 'text' || $type === 'shape') && is_string($el['content'] ?? null)
                    && ($el['format'] ?? 'plain') !== 'markdown'
                    && preg_match('/\*\*[^*\n]+\*\*|(^|\n)#{1,6}[ \t]/', $el['content']) === 1) {
                    $findings[] = self::finding('literal-markdown', $slideNo, $id,
                        'This text uses markdown (**bold** or a # heading) but its format is not "markdown", so the markers print as typed. Set "format": "markdown", or remove the markers.');
                }

                if ($type === 'kpiBand') {
                    $kpi = self::kpi($el, $box, $canvasW);
                    if ($kpi !== null) {
                        $findings[] = self::finding('kpi-overflow', $slideNo, $id, $kpi);
                    }
                }

                $items[] = ['id' => $id, 'type' => $type, 'box' => $box, 'extent' => $extent];
            }

            // Text whose extent runs into another element. A panel that fully
            // contains the text box is a card behind it, not a collision.
            foreach ($items as $a) {
                if ($a['type'] !== 'text') {
                    continue;
                }
                foreach ($items as $b) {
                    if ($a['id'] === $b['id'] || self::contains($b['box'], $a['box']) || self::contains($a['box'], $b['box'])) {
                        continue;
                    }
                    if (self::intersects($a['extent'], $b['type'] === 'text' ? $b['extent'] : $b['box'])) {
                        $findings[] = self::finding('overlap', $slideNo, $a['id'], sprintf(
                            'Its text runs into "%s". Place one below the other\'s bottom edge (y + h) with a gap of at least 0.02.',
                            $b['id'],
                        ));
                    }
                }
            }
        }

        return $findings;
    }

    /** @return array{code: string, slide: int, element: string, message: string} */
    private static function finding(string $code, int $slide, string $element, string $message): array
    {
        return ['code' => $code, 'slide' => $slide, 'element' => $element, 'message' => $message];
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    private static function box(array $el): ?array
    {
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (! is_numeric($el[$k] ?? null) || is_string($el[$k])) {
                return null;
            }
        }

        return ['x' => (float) $el['x'], 'y' => (float) $el['y'], 'w' => (float) $el['w'], 'h' => (float) $el['h']];
    }

    /** Estimated height in px the text needs, or null when there is no text. */
    private static function textHeight(array $el, array $style, float $font, float $boxW, float $canvasW, string $headingFont): ?float
    {
        $content = $el['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            return null;
        }
        $weight = $style['weight'] ?? null;
        $bold = (is_numeric($weight) && (int) $weight >= 600) || $weight === 'bold'
            || ($headingFont !== '' && ($style['fontFamily'] ?? null) === $headingFont);
        $glyph = ($bold ? self::BOLD_GLYPH : self::GLYPH) * $font;
        [$padX, $padY] = self::padding($style, $canvasW);
        $usable = max(1.0, $boxW - 2 * $padX);

        $lines = 0;
        foreach (explode("\n", $content) as $line) {
            // Markdown marks and bullets take no width once rendered.
            // Spaces and tabs, spelled out: `\s` means different sets in the three
            // engines' regex flavours, and the messages must match.
            $plain = preg_replace(['/\*\*|__|`/', '/^[ \t]*[-*][ \t]+/', '/^#+[ \t]+/'], '', $line) ?? $line;
            $chars = (int) preg_match_all('/./us', $plain);
            $lines += max(1, (int) ceil(($chars * $glyph) / $usable));
        }

        return $lines * self::LINE * $font + 2 * $padY;
    }

    /** @return array{0: float, 1: float} horizontal and vertical inset, design px */
    private static function padding(array $style, float $canvasW): array
    {
        $p = $style['padding'] ?? null;
        if (is_numeric($p) && ! is_string($p)) {
            return [(float) $p, (float) $p];
        }
        if (is_array($p)) {
            $x = $p['left'] ?? $p['x'] ?? null;
            $y = $p['top'] ?? $p['y'] ?? null;
            if (is_numeric($x) && is_numeric($y)) {
                return [(float) $x, (float) $y];
            }
        }

        // PowerPoint's defaults, 7.2pt and 3.6pt, on this canvas.
        return [7.2 * $canvasW / 720, 3.6 * $canvasW / 720];
    }

    private static function kpi(array $el, array $box, float $canvasW): ?string
    {
        $items = array_values(array_filter(is_array($el['items'] ?? null) ? $el['items'] : [], 'is_array'));
        if ($items === []) {
            return null;
        }
        $style = is_array($el['style'] ?? null) ? $el['style'] : [];
        $size = is_numeric($style['valueFontSize'] ?? null) ? (float) $style['valueFontSize'] : 40.0;
        $cell = $box['w'] * $canvasW / count($items);
        $longest = 0;
        foreach ($items as $item) {
            $longest = max($longest, (int) preg_match_all('/./us', self::label($item['value'] ?? null) ?? ''));
        }
        $need = $longest * self::BOLD_GLYPH * $size + self::KPI_PAD;
        if ($longest === 0 || $need <= $cell) {
            return null;
        }

        return sprintf(
            'A %d-character value at valueFontSize %s needs about %dpx and each of the %d cells is %dpx: values will run into each other. Lower valueFontSize to %d or less.',
            $longest, self::num($size), (int) ceil($need), count($items), (int) floor($cell),
            (int) floor(($cell - self::KPI_PAD) / ($longest * self::BOLD_GLYPH)),
        );
    }

    private static function intersects(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] - 0.001 && $b['x'] < $a['x'] + $a['w'] - 0.001
            && $a['y'] < $b['y'] + $b['h'] - 0.001 && $b['y'] < $a['y'] + $a['h'] - 0.001;
    }

    private static function contains(array $outer, array $inner): bool
    {
        return $outer['x'] <= $inner['x'] + 0.001 && $outer['y'] <= $inner['y'] + 0.001
            && $outer['x'] + $outer['w'] >= $inner['x'] + $inner['w'] - 0.001
            && $outer['y'] + $outer['h'] >= $inner['y'] + $inner['h'] - 0.001;
    }

    /** Rounded UP to 2 decimals, always shown with 2: "0.23". */
    private static function up2(float $v): string
    {
        return number_format(ceil(round($v * 100, 6)) / 100, 2, '.', '');
    }

    /**
     * A string, or an integer as a string; null for anything else. Floats and
     * booleans are refused rather than cast, because each language prints them
     * differently ("2" / "2.0", "1" / "true") and the messages must match.
     */
    private static function label(mixed $v): ?string
    {
        return is_string($v) ? $v : (is_int($v) ? (string) $v : null);
    }

    /** A number shown as authored: 88 -> "88", 7.5 -> "7.5". */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
