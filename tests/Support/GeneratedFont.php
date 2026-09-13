<?php

declare(strict_types=1);

namespace DarkSlide\Tests\Support;

/**
 * A minimal, valid TrueType font built in memory, for tests.
 *
 * Embedding tests need real font files, and a third-party font is not something
 * this repository takes on. So the test writes its own: ten tables (OS/2, cmap,
 * glyf, head, hhea, hmtx, loca, maxp, name, post), an empty `.notdef`, and one
 * tall box glyph for every printable ASCII character. Text set in it renders as
 * a row of boxes, which is unmistakable next to any fallback face.
 *
 * Rendered through LibreOffice 26 while this was designed: embedded in a `.pptx`,
 * a font from this generator was the face the exported PDF used.
 */
final class GeneratedFont
{
    public static function build(string $family, string $style = 'Regular', int $fsType = 0, int $weight = 400, bool $italic = false): string
    {
        $firstChar = 0x20;
        $lastChar = 0x7E;
        $glyphs = 1 + ($lastChar - $firstChar + 1);

        $box = self::boxGlyph(100, 0, 500, 700);
        $glyf = '';
        $offsets = [0, 0]; // .notdef is empty
        for ($i = $firstChar; $i <= $lastChar; $i++) {
            $glyf .= $box;
            $offsets[] = strlen($glyf);
        }
        $loca = '';
        foreach ($offsets as $offset) {
            $loca .= pack('N', $offset);
        }

        $head = pack('NNNN', 0x00010000, 0x00010000, 0, 0x5F0F3CF5)
            .pack('nn', 0x000B, 1000)
            .pack('JJ', 0, 0)
            .self::s16(0, 0, 600, 800)
            .pack('nn', $italic ? 2 : ($weight >= 600 ? 1 : 0), 8)
            .self::s16(2, 1, 0);

        $hhea = pack('N', 0x00010000)
            .self::s16(800, -200, 0)
            .pack('n', 600)
            .self::s16(0, 0, 500, 1, 0, 0, 0, 0, 0, 0, 0)
            .pack('n', $glyphs);

        $maxp = pack('Nnnnnnnnnnnnnnn', 0x00010000, $glyphs, 4, 1, 0, 0, 2, 0, 0, 0, 0, 0, 0, 0, 0);

        $hmtx = '';
        for ($i = 0; $i < $glyphs; $i++) {
            $hmtx .= pack('n', 600).self::s16($i === 0 ? 0 : 100);
        }

        // cmap format 4: one segment mapping U+0020..U+007E to glyphs 1.., and the
        // mandatory 0xFFFF terminator.
        $segCount = 2;
        $searchRange = 2 * (2 ** (int) floor(log($segCount, 2)));
        $subtable = pack('nnn', 4, 16 + 8 * $segCount, 0)
            .pack('nnnn', $segCount * 2, $searchRange, (int) log($searchRange / 2, 2), $segCount * 2 - $searchRange)
            .pack('nn', $lastChar, 0xFFFF).pack('n', 0)
            .pack('nn', $firstChar, 0xFFFF)
            .pack('nn', (1 - $firstChar) & 0xFFFF, 1)
            .pack('nn', 0, 0);
        $cmap = pack('nnnnN', 0, 1, 3, 1, 12).$subtable;

        $names = [
            1 => $family,
            2 => $style,
            3 => "{$family} {$style}",
            4 => "{$family} {$style}",
            5 => 'Version 1.000',
            6 => str_replace(' ', '', $family).'-'.str_replace(' ', '', $style),
        ];
        $records = '';
        $strings = '';
        foreach ($names as $id => $value) {
            $utf16 = self::utf16be($value);
            $records .= pack('nnnnnn', 3, 1, 0x409, $id, strlen($utf16), strlen($strings));
            $strings .= $utf16;
        }
        $name = pack('nnn', 0, count($names), 6 + 12 * count($names)).$records.$strings;

        $fsSelection = ($italic ? 0x01 : 0) | ($weight >= 600 ? 0x20 : 0) | (! $italic && $weight < 600 ? 0x40 : 0);
        $os2 = pack('n', 4).self::s16(600).pack('nnn', $weight, 5, $fsType)
            .self::s16(300, 300, 0, 0, 300, 300, 0, 300, 50, 300, 0)
            .str_repeat("\0", 10)                  // PANOSE
            .pack('NNNN', 1, 0, 0, 0)              // ulUnicodeRange1..4 (Basic Latin)
            .'NONE'
            .pack('nnn', $fsSelection, $firstChar, $lastChar)
            .self::s16(800, -200, 0).pack('nn', 800, 200)
            .pack('NN', 1, 0)                      // ulCodePageRange1..2 (Latin 1)
            .self::s16(500, 700).pack('nnn', 0, 0x20, 0);

        $post = pack('NN', 0x00030000, 0).self::s16(-100, 50).pack('NNNNN', 0, 0, 0, 0, 0);

        return self::assemble([
            'OS/2' => $os2, 'cmap' => $cmap, 'glyf' => $glyf, 'head' => $head, 'hhea' => $hhea,
            'hmtx' => $hmtx, 'loca' => $loca, 'maxp' => $maxp, 'name' => $name, 'post' => $post,
        ]);
    }

    /** The same font with its sfnt tag replaced, to stand in for a CFF `.otf` or a `.ttc`. */
    public static function withTag(string $font, string $tag): string
    {
        return $tag.substr($font, 4);
    }

    /** @param array<string, string> $tables */
    private static function assemble(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $selector = (int) floor(log($count, 2));
        $searchRange = (2 ** $selector) * 16;

        $directory = pack('Nnnnn', 0x00010000, $count, $searchRange, $selector, $count * 16 - $searchRange);
        $body = '';
        $offset = 12 + 16 * $count;
        $headOffset = 0;
        foreach ($tables as $tag => $data) {
            if ($tag === 'head') {
                $headOffset = $offset + strlen($body);
            }
            $directory .= $tag.pack('NNN', self::checksum($data), $offset + strlen($body), strlen($data));
            $body .= $data.str_repeat("\0", (4 - strlen($data) % 4) % 4);
        }

        $font = $directory.$body;
        $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;

        return substr_replace($font, pack('N', $adjustment), $headOffset + 8, 4);
    }

    private static function boxGlyph(int $x0, int $y0, int $x1, int $y1): string
    {
        $points = [[$x0, $y0], [$x0, $y1], [$x1, $y1], [$x1, $y0]];
        $glyph = self::s16(1, $x0, $y0, $x1, $y1).pack('nn', 3, 0).str_repeat("\x01", 4);
        $xs = '';
        $ys = '';
        [$px, $py] = [0, 0];
        foreach ($points as [$x, $y]) {
            $xs .= self::s16($x - $px);
            $ys .= self::s16($y - $py);
            [$px, $py] = [$x, $y];
        }
        $glyph .= $xs.$ys;

        return $glyph.str_repeat("\0", (4 - strlen($glyph) % 4) % 4);
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private static function s16(int ...$values): string
    {
        return implode('', array_map(static fn (int $v): string => pack('n', $v & 0xFFFF), $values));
    }

    private static function utf16be(string $ascii): string
    {
        return implode('', array_map(static fn (string $c): string => "\0".$c, str_split($ascii)));
    }
}
