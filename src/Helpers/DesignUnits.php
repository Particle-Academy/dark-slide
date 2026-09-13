<?php

declare(strict_types=1);

namespace DarkSlide\Helpers;

/**
 * The deck's design canvas, mapped onto the PPTX slide. One place, one formula.
 *
 * ## The model
 *
 * A deck is authored on a design canvas `theme.slideWidth` pixels wide (1920 by
 * default), exactly as `@particle-academy/fancy-slides` renders it: position and
 * size are fractions of the slide, and every authored LENGTH is a design pixel
 * (`fontSize`, `strokeWidth`, `letterSpacing`, `spaceBefore`, `spaceAfter`,
 * `padding`, `radius`, border and accent-bar widths, table row heights). The
 * canvas scales to the slide, so a length keeps its share of the slide width:
 *
 *   points = px * 720 / designWidth       (the slide is 10in = 720pt wide)
 *
 * `fontSize: 96` on the default canvas is 36pt, which is 5% of the slide's width
 * in PowerPoint, in fancy-slides' preview, and in anything else that scales the
 * canvas to fit. This is how design tools map a pixel canvas onto a page; a fixed
 * 96-dpi px-to-pt factor would tie text to a screen instead of to the slide.
 *
 * ## What it replaced
 *
 * Until 0.10 the writer halved `fontSize` into points with an 8pt floor, treated
 * `strokeWidth` as points, and took every other length above as points too. On a
 * 720pt slide the halving made text a third larger than fancy-slides showed it,
 * the shrink-to-fit estimator assumed a third canvas width (1280), and one style
 * object mixed two units. An agent in the fancy-labs lab described a headline as
 * 232pt; the file carried 116pt.
 *
 * `theme.slideWidth: 1440` gives exactly the old text sizes (720 / 1440 = 0.5).
 *
 * ## Defaults are not authored lengths
 *
 * A built-in default that is PowerPoint's own (a text box's 7.2pt / 3.6pt insets,
 * a 1pt outline, a 0.75pt table rule, 40pt / 30pt minimum row heights) stays in
 * points. Only a value the deck states is converted, so a deck that never sets
 * them is unaffected.
 *
 * Every engine (PHP, Node, Python) computes `px * 720 / designWidth` in that
 * order, so the floating-point result, and therefore every rounded EMU, agrees.
 */
final class DesignUnits
{
    public const DEFAULT_DESIGN_WIDTH = 1920.0;

    /** The slide is 10 inches wide: 9144000 EMU / 12700 EMU per point. */
    public const SLIDE_WIDTH_PT = 720.0;

    /** A PPTX font size has to be at least 1pt (`ST_TextFontSize` starts at 100). */
    public const MIN_FONT_PT = 1.0;

    /** @param array<string, mixed> $theme */
    public static function designWidth(array $theme): float
    {
        $width = $theme['slideWidth'] ?? null;

        return is_numeric($width) && (float) $width > 0 ? (float) $width : self::DEFAULT_DESIGN_WIDTH;
    }

    /** @param array<string, mixed> $theme */
    public static function toPt(float $px, array $theme): float
    {
        return $px * self::SLIDE_WIDTH_PT / self::designWidth($theme);
    }

    /** @param array<string, mixed> $theme */
    public static function fontPt(float $px, array $theme): float
    {
        return max(self::MIN_FONT_PT, self::toPt($px, $theme));
    }

    /**
     * The slide height: 10in wide, `theme.aspectRatio` (width / height, 16/9 by
     * default, as fancy-slides reads it) decides the rest.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function slideHeightEmu(array $theme): int
    {
        $ratio = $theme['aspectRatio'] ?? null;
        if (! is_numeric($ratio) || (float) $ratio <= 0) {
            return Emu::DEFAULT_SLIDE_HEIGHT;
        }

        return (int) round(Emu::DEFAULT_SLIDE_WIDTH / (float) $ratio);
    }

    /** The named `<p:sldSz type>` for a 10in-wide slide of this height, or null for a custom size. */
    public static function slideSizeType(int $heightEmu): ?string
    {
        return match ($heightEmu) {
            5143500 => 'screen16x9',
            5715000 => 'screen16x10',
            6858000 => 'screen4x3',
            default => null,
        };
    }
}
