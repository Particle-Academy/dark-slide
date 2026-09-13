<?php

declare(strict_types=1);

namespace DarkSlide\Fonts;

/**
 * UTF-16 <-> UTF-8 for font name records, in plain PHP.
 *
 * `mb_convert_encoding` would be one line, and it would add `ext-mbstring` to a
 * package whose only requirements are `ext-zip`, `ext-dom` and `ext-libxml`.
 * Font names are short, so a hand loop costs nothing.
 */
final class Utf16
{
    public static function beToUtf8(string $raw): string
    {
        $out = '';
        $length = strlen($raw) - (strlen($raw) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $unit = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 3 < $length) {
                $low = (ord($raw[$i + 2]) << 8) | ord($raw[$i + 3]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                    $i += 2;
                }
            }
            $out .= self::codePointToUtf8($unit);
        }

        return $out;
    }

    public static function utf8ToLe(string $utf8): string
    {
        $out = '';
        foreach (self::codePoints($utf8) as $cp) {
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $out .= pack('vv', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 0x3FF));
            } else {
                $out .= pack('v', $cp);
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function codePoints(string $utf8): array
    {
        $points = [];
        $length = strlen($utf8);
        for ($i = 0; $i < $length;) {
            $byte = ord($utf8[$i]);
            [$width, $cp] = match (true) {
                $byte < 0x80 => [1, $byte],
                $byte >= 0xF0 => [4, $byte & 0x07],
                $byte >= 0xE0 => [3, $byte & 0x0F],
                $byte >= 0xC0 => [2, $byte & 0x1F],
                default => [1, 0xFFFD],
            };
            for ($k = 1; $k < $width && $i + $k < $length; $k++) {
                $cp = ($cp << 6) | (ord($utf8[$i + $k]) & 0x3F);
            }
            $points[] = $cp;
            $i += $width;
        }

        return $points;
    }

    private static function codePointToUtf8(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => chr($cp),
            $cp < 0x800 => chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F)),
            $cp < 0x10000 => chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)),
            default => chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)),
        };
    }
}
