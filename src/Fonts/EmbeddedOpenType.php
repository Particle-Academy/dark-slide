<?php

declare(strict_types=1);

namespace DarkSlide\Fonts;

/**
 * Wraps a TrueType font in an Embedded OpenType (EOT) header, uncompressed.
 *
 * ## Why EOT and not the raw font
 *
 * A `.pptx` stores an embedded font as `ppt/fonts/fontN.fntdata`, and the bytes
 * in that part are an EOT, not a `.ttf`. Verified by rendering in LibreOffice
 * 26, with a font renamed to a family that was not installed: an EOT-wrapped
 * `.fntdata` rendered in the embedded face; the same font stored raw, or with
 * Word's GUID obfuscation, fell back to another face.
 *
 * Uncompressed (Flags 0) on purpose. EOT allows MicroType Express compression,
 * and LibreOffice's libeot build here cannot decompress it.
 *
 * ## The layout (EOT version 0x00020002)
 *
 * Little-endian, unlike the font inside it:
 *
 *   EOTSize, FontDataSize, Version, Flags          4 x ULONG
 *   FontPANOSE                                     10 bytes
 *   Charset, Italic                                BYTE, BYTE
 *   Weight                                         ULONG
 *   fsType, MagicNumber 0x504C                     USHORT, USHORT
 *   UnicodeRange1..4, CodePageRange1..2            6 x ULONG
 *   CheckSumAdjustment, Reserved1..4               5 x ULONG
 *   (Padding, NameSize, UTF-16LE name) x 4         family, style, version, full
 *   Padding5, RootStringSize (0)
 *   RootStringCheckSum, EUDCCodePage               ULONG, ULONG
 *   Padding6, SignatureSize (0), EUDCFlags, EUDCFontSize (0)
 *   FontData
 *
 * Names carry a trailing NUL inside their counted size, as LibreOffice's own
 * export does; with that, this header matched LibreOffice's byte for byte on a
 * real font except `Charset`, where this writes DEFAULT_CHARSET (1) per the EOT
 * format and LibreOffice writes 0. Both render.
 */
final class EmbeddedOpenType
{
    public const VERSION = 0x00020002;

    public const MAGIC = 0x504C;

    /** RootStringCheckSum for an empty root string: the XOR key itself. */
    private const ROOT_STRING_CHECKSUM = 0x50475342;

    private const WINDOWS_1252 = 1252;

    public static function wrap(TrueTypeFont $font, string $family): string
    {
        $panose = $font->panose();
        [$u1, $u2, $u3, $u4] = $font->unicodeRanges();
        [$c1, $c2] = $font->codePageRanges();

        $body = $panose
            . pack('CC', 1, $font->isItalic() ? 1 : 0)
            . pack('V', $font->weight())
            . pack('vv', $font->fsType(), self::MAGIC)
            . pack('VVVV', $u1, $u2, $u3, $u4)
            . pack('VV', $c1, $c2)
            . pack('V', $font->checkSumAdjustment())
            . pack('VVVV', 0, 0, 0, 0)
            . self::name($family)
            . self::name($font->name(2) ?? 'Regular')
            . self::name($font->name(5) ?? '')
            . self::name($font->name(4) ?? $family)
            . pack('vv', 0, 0)
            . pack('VV', self::ROOT_STRING_CHECKSUM, self::WINDOWS_1252)
            . pack('vv', 0, 0)
            . pack('VV', 0, 0);

        $headerLength = 16 + strlen($body);
        $dataLength = strlen($font->bytes);

        return pack('VVVV', $headerLength + $dataLength, $dataLength, self::VERSION, 0)
            . $body
            . $font->bytes;
    }

    private static function name(string $value): string
    {
        $utf16 = Utf16::utf8ToLe($value . "\0");

        return pack('vv', 0, strlen($utf16)) . $utf16;
    }
}
