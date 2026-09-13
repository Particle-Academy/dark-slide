<?php

declare(strict_types=1);

namespace DarkSlide\Text;

use DarkSlide\Helpers\Color;
use DarkSlide\Helpers\DesignUnits;
use DarkSlide\Helpers\Emu;

/**
 * The box a text element lives in: its fill, its outline, its corner radius,
 * its insets — and the left accent bar that makes a callout a callout.
 *
 * ## Why the accent bar is a gradient
 *
 * DrawingML has no per-side border on a shape: `<a:ln>` is all four sides or
 * none. So a coloured bar down one edge has always meant a second shape
 * underneath, which pushes the z-ordering and the geometry onto whoever is
 * authoring the deck — and an agent emitting three elements that have to line
 * up is three chances to get it wrong.
 *
 * `<a:gradFill>` with two stops at ADJACENT positions is a hard edge, not a
 * blend. Four stops therefore paint a bar and a flat tint in a single shape,
 * with no extra element, no z-order and no second shape id for the animation
 * builder to renumber. Verified rendering before it was designed in.
 */
final class BoxDecoration
{
    /** Gap between the accent bar and the text when nothing says otherwise. */
    public const ACCENT_GUTTER_PT = 8.0;

    /**
     * The `<p:spPr>` interior: geometry, fill and line, in schema order.
     *
     * @param  array<string, mixed>  $style
     */
    public static function spPr(array $style, int $widthEmu, int $heightEmu, array $theme = []): string
    {
        return self::geometry($style, $widthEmu, $heightEmu, $theme) . self::fill($style, $widthEmu, $theme) . self::line($style, $theme);
    }

    /** @param array<string, mixed> $style */
    public static function hasDecoration(array $style): bool
    {
        return isset($style['fill']) || isset($style['accentBar']) || isset($style['border']) || isset($style['radius']);
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $theme
     */
    private static function geometry(array $style, int $widthEmu, int $heightEmu, array $theme = []): string
    {
        $radius = $style['radius'] ?? null;
        if (! is_numeric($radius) || (float) $radius <= 0) {
            return '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
        }

        // `adj` is a proportion of HALF the shorter side, in 1/1000 of a percent.
        $shorter = max(1, min($widthEmu, $heightEmu));
        $adj = (int) round(Emu::fromPt(DesignUnits::toPt((float) $radius, $theme)) / ($shorter / 2) * 100000);
        $adj = max(0, min(50000, $adj));

        return '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val ' . $adj . '"/></a:avLst></a:prstGeom>';
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $theme
     */
    private static function fill(array $style, int $widthEmu, array $theme = []): string
    {
        $bar = is_array($style['accentBar'] ?? null) ? $style['accentBar'] : null;
        $hasFill = isset($style['fill']) && $style['fill'] !== false && $style['fill'] !== 'none';

        if ($bar === null) {
            if (! $hasFill) {
                return '<a:noFill/>';
            }
            [$hex] = Color::parse((string) $style['fill'], 'FFFFFF');

            return '<a:solidFill><a:srgbClr val="' . $hex . '"/></a:solidFill>';
        }

        [$barHex] = Color::parse((string) ($bar['color'] ?? '#8B5CF6'), '8B5CF6');
        [$restHex] = Color::parse($hasFill ? (string) $style['fill'] : '#FFFFFF', 'FFFFFF');

        $barEmu = Emu::fromPt(self::barWidthPt($bar, $theme));
        $pos = $widthEmu > 0 ? (int) round($barEmu / $widthEmu * 100000) : 1000;
        $pos = max(1, min(99998, $pos));

        $right = ($bar['side'] ?? 'left') === 'right';

        if ($right) {
            $edge = 100000 - $pos;
            $stops = '<a:gs pos="0"><a:srgbClr val="' . $restHex . '"/></a:gs>'
                . '<a:gs pos="' . ($edge - 1) . '"><a:srgbClr val="' . $restHex . '"/></a:gs>'
                . '<a:gs pos="' . $edge . '"><a:srgbClr val="' . $barHex . '"/></a:gs>'
                . '<a:gs pos="100000"><a:srgbClr val="' . $barHex . '"/></a:gs>';
        } else {
            $stops = '<a:gs pos="0"><a:srgbClr val="' . $barHex . '"/></a:gs>'
                . '<a:gs pos="' . $pos . '"><a:srgbClr val="' . $barHex . '"/></a:gs>'
                . '<a:gs pos="' . ($pos + 1) . '"><a:srgbClr val="' . $restHex . '"/></a:gs>'
                . '<a:gs pos="100000"><a:srgbClr val="' . $restHex . '"/></a:gs>';
        }

        return '<a:gradFill flip="none" rotWithShape="0"><a:gsLst>' . $stops . '</a:gsLst><a:lin ang="0" scaled="0"/></a:gradFill>';
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $theme
     */
    private static function line(array $style, array $theme = []): string
    {
        $border = $style['border'] ?? null;
        if ($border === null || $border === false || $border === 'none') {
            return '';
        }
        if (! is_array($border)) {
            return '';
        }

        // A stated width is design pixels; the default outline is 1pt.
        $width = isset($border['width']) && is_numeric($border['width']) ? DesignUnits::toPt((float) $border['width'], $theme) : 1.0;
        if ($width <= 0) {
            return '';
        }
        [$hex] = Color::parse((string) ($border['color'] ?? '#CBD5E1'), 'CBD5E1');
        $dash = ($border['style'] ?? 'solid') !== 'solid'
            ? '<a:prstDash val="' . (string) $border['style'] . '"/>'
            : '';

        return '<a:ln w="' . Emu::fromPt($width) . '"><a:solidFill><a:srgbClr val="' . $hex . '"/></a:solidFill>' . $dash . '</a:ln>';
    }

    /**
     * `lIns`/`tIns`/`rIns`/`bIns` for the text body, or an empty string when
     * the element says nothing — decks that predate this keep their bytes.
     *
     * An accent bar with no explicit padding gets a left inset wide enough to
     * clear it, because text printed on top of the bar is the obvious way for
     * this feature to look broken.
     *
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $theme
     */
    public static function bodyInsets(array $style, array $theme = []): string
    {
        $padding = $style['padding'] ?? null;
        $bar = is_array($style['accentBar'] ?? null) ? $style['accentBar'] : null;

        if ($padding === null && $bar === null) {
            return '';
        }

        // PowerPoint's own defaults, in points, which is what an undecorated box uses.
        $sides = ['left' => 7.2, 'right' => 7.2, 'top' => 3.6, 'bottom' => 3.6];

        if ($bar !== null && ($bar['side'] ?? 'left') !== 'right') {
            $sides['left'] = self::barWidthPt($bar, $theme) + self::ACCENT_GUTTER_PT;
        }
        if ($bar !== null && ($bar['side'] ?? 'left') === 'right') {
            $sides['right'] = self::barWidthPt($bar, $theme) + self::ACCENT_GUTTER_PT;
        }

        // Stated padding is design pixels.
        if (is_numeric($padding)) {
            $sides = array_map(fn () => DesignUnits::toPt((float) $padding, $theme), $sides);
        } elseif (is_array($padding)) {
            foreach ($sides as $side => $_) {
                if (isset($padding[$side]) && is_numeric($padding[$side])) {
                    $sides[$side] = DesignUnits::toPt((float) $padding[$side], $theme);
                }
            }
        }

        return self::insetAttributes($sides);
    }

    /**
     * The accent bar's width in points: a stated width is design pixels, the
     * default bar is 4pt.
     *
     * @param  array<string, mixed>  $bar
     * @param  array<string, mixed>  $theme
     */
    private static function barWidthPt(array $bar, array $theme): float
    {
        return isset($bar['width']) && is_numeric($bar['width'])
            ? DesignUnits::toPt((float) $bar['width'], $theme)
            : 4.0;
    }

    /** @param array<string, float> $sides */
    private static function insetAttributes(array $sides): string
    {
        return ' lIns="' . Emu::fromPt($sides['left']) . '"'
            . ' tIns="' . Emu::fromPt($sides['top']) . '"'
            . ' rIns="' . Emu::fromPt($sides['right']) . '"'
            . ' bIns="' . Emu::fromPt($sides['bottom']) . '"';
    }
}
