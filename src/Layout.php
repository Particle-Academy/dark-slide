<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * Opt-in slide tidy-up — snap elements to a grid, nudge overlaps apart, and
 * shrink over-long text to fit its box. Never run automatically; a consumer (or
 * an agent's MCP `layout_fit` tool) calls it after composing a slide so
 * machine-authored decks stop looking machine-authored.
 *
 * Pure: takes a slide array, returns a NEW slide with adjusted element
 * geometry; the input is untouched. Coordinates are slide-relative 0..1, matching
 * fancy-slides.
 */
final class Layout
{
    /**
     * @param  array<string,mixed>  $slide
     * @param  array{grid?:int,reflowOverlap?:bool,fitText?:bool,safeMargin?:float,slideWidth?:float}  $options
     * @return array<string,mixed>
     */
    public static function fit(array $slide, array $options = []): array
    {
        $grid = max(1, (int) ($options['grid'] ?? 12));
        $reflow = (bool) ($options['reflowOverlap'] ?? true);
        $fitText = (bool) ($options['fitText'] ?? true);
        $margin = (float) ($options['safeMargin'] ?? 0.02);
        $slideWidth = (float) ($options['slideWidth'] ?? 1280.0);

        $elements = $slide['elements'] ?? [];
        if (! is_array($elements) || $elements === []) {
            return $slide;
        }

        $step = 1.0 / $grid;
        $usable = max(0.0, 1.0 - 2 * $margin);

        $out = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                $out[] = $el;

                continue;
            }
            $out[] = self::snap($el, $step, $margin, $usable);
        }

        if ($reflow) {
            $out = self::reflow($out, $margin, $step);
        }

        if ($fitText) {
            $out = array_map(static fn (array $e): array => self::fitTextElement($e, $slideWidth), $out);
        }

        $slide['elements'] = array_values($out);

        return $slide;
    }

    /**
     * Snap geometry to the grid and clamp inside the safe margin.
     *
     * @param  array<string,mixed>  $el
     * @return array<string,mixed>
     */
    private static function snap(array $el, float $step, float $margin, float $usable): array
    {
        $snap = static fn (float $v): float => round($v / $step) * $step;

        $w = self::clampSpan($snap((float) ($el['w'] ?? 0.0)), $step, $usable);
        $h = self::clampSpan($snap((float) ($el['h'] ?? 0.0)), $step, $usable);
        $x = self::clampPos($snap((float) ($el['x'] ?? 0.0)), $w, $margin, $usable);
        $y = self::clampPos($snap((float) ($el['y'] ?? 0.0)), $h, $margin, $usable);

        $el['x'] = round($x, 6);
        $el['y'] = round($y, 6);
        $el['w'] = round($w, 6);
        $el['h'] = round($h, 6);

        return $el;
    }

    private static function clampSpan(float $span, float $step, float $usable): float
    {
        return max($step, min($usable, $span));
    }

    private static function clampPos(float $pos, float $span, float $margin, float $usable): float
    {
        $max = $margin + $usable - $span;

        return max($margin, min($max, $pos));
    }

    /**
     * Greedy de-overlap: walk elements in order; when one overlaps an earlier
     * placed box, nudge it down a grid step until it's clear (then clamp to the
     * bottom margin). Cheap and deterministic — good enough to stop boxes
     * stacking on top of each other.
     *
     * @param  list<array<string,mixed>>  $els
     * @return list<array<string,mixed>>
     */
    private static function reflow(array $els, float $margin, float $step): array
    {
        $placed = [];
        foreach ($els as $el) {
            if (! isset($el['x'], $el['y'], $el['w'], $el['h'])) {
                $placed[] = $el;

                continue;
            }
            $guard = 0;
            while (self::overlapsAny($el, $placed) && $guard < 256) {
                $el['y'] = round((float) $el['y'] + $step, 6);
                // Ran off the bottom — give up nudging this one.
                if ((float) $el['y'] + (float) $el['h'] > 1.0 - $margin) {
                    $el['y'] = round(max($margin, 1.0 - $margin - (float) $el['h']), 6);
                    break;
                }
                $guard++;
            }
            $placed[] = $el;
        }

        return $placed;
    }

    /**
     * @param  array<string,mixed>  $el
     * @param  list<array<string,mixed>>  $others
     */
    private static function overlapsAny(array $el, array $others): bool
    {
        foreach ($others as $o) {
            if (! isset($o['x'], $o['y'], $o['w'], $o['h'])) {
                continue;
            }
            $overlapX = (float) $el['x'] < (float) $o['x'] + (float) $o['w'] && (float) $o['x'] < (float) $el['x'] + (float) $el['w'];
            $overlapY = (float) $el['y'] < (float) $o['y'] + (float) $o['h'] && (float) $o['y'] < (float) $el['y'] + (float) $el['h'];
            if ($overlapX && $overlapY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shrink a text element's `fontSize` until its content is estimated to fit
     * the box. Uses a coarse average-glyph-width metric (≈ 0.5em) — good enough
     * to stop text spilling off-canvas without a real font engine.
     *
     * @param  array<string,mixed>  $el
     * @return array<string,mixed>
     */
    private static function fitTextElement(array $el, float $slideWidth): array
    {
        if (($el['type'] ?? null) !== 'text' || ! isset($el['content']) || ! is_string($el['content'])) {
            return $el;
        }

        $style = is_array($el['style'] ?? null) ? $el['style'] : [];
        $fontSize = (float) ($style['fontSize'] ?? 24.0);
        if ($fontSize <= 1) {
            return $el;
        }

        $boxWpx = (float) $el['w'] * $slideWidth;
        $boxHpx = (float) $el['h'] * ($slideWidth * 9 / 16);
        $text = trim($el['content']);
        $longestLine = 0;
        foreach (explode("\n", $text) as $line) {
            $longestLine = max($longestLine, mb_strlen($line));
        }
        $explicitLines = substr_count($text, "\n") + 1;

        $guard = 0;
        while ($fontSize > 8 && $guard < 64) {
            $avgGlyph = 0.5 * $fontSize;
            $lineHeight = 1.3 * $fontSize;
            $charsPerLine = max(1, (int) floor($boxWpx / max(1.0, $avgGlyph)));
            $wrapped = (int) ceil(($longestLine * max(1, $explicitLines)) / $charsPerLine);
            $lines = max($explicitLines, $wrapped);
            if ($lines * $lineHeight <= $boxHpx) {
                break;
            }
            $fontSize -= 1;
            $guard++;
        }

        if ($fontSize !== (float) ($style['fontSize'] ?? 24.0)) {
            $style['fontSize'] = $fontSize;
            $el['style'] = $style;
        }

        return $el;
    }
}
