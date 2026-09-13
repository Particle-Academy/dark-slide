<?php

declare(strict_types=1);

namespace DarkSlide\Fonts;

/**
 * The handful of TrueType fields that embedding a font in a `.pptx` needs.
 *
 * Not a font parser in any wider sense: it reads the table directory, the OS/2
 * fields the Embedded OpenType header copies, `head.checkSumAdjustment`, and the
 * name records. Nothing is subset, hinted or rewritten; the font bytes go into
 * the file exactly as supplied.
 *
 * Refuses, rather than guesses at, anything it cannot embed safely:
 *
 *   - a font collection (`ttcf`), which is several fonts in one file;
 *   - CFF-outline OpenType (`OTTO`). LibreOffice renders one, but PowerPoint's
 *     acceptance is unverified, so the first version embeds TrueType outlines
 *     only and says so;
 *   - a file missing a table the header needs.
 */
final class TrueTypeFont
{
    /** @param array<string, array{offset: int, length: int}> $tables */
    private function __construct(
        public readonly string $bytes,
        private readonly array $tables,
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) < 12) {
            throw new FontEmbeddingException('not a font file: shorter than a table directory');
        }

        $tag = substr($bytes, 0, 4);
        if ($tag === 'ttcf') {
            throw new FontEmbeddingException('a font collection (.ttc) holds several fonts; supply the single font file');
        }
        if ($tag === 'OTTO') {
            throw new FontEmbeddingException('CFF-outline OpenType (.otf) is not embedded yet; supply the TrueType-outline (.ttf) build of the font');
        }
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') {
            throw new FontEmbeddingException('not a TrueType font file');
        }

        $count = self::u16($bytes, 4);
        if (strlen($bytes) < 12 + 16 * $count) {
            throw new FontEmbeddingException('not a font file: the table directory is truncated');
        }

        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $offset = self::u32($bytes, $record + 8);
            $length = self::u32($bytes, $record + 12);
            if ($offset + $length > strlen($bytes)) {
                throw new FontEmbeddingException('not a font file: a table runs past the end of the file');
            }
            $tables[substr($bytes, $record, 4)] = ['offset' => $offset, 'length' => $length];
        }

        foreach (['OS/2', 'head', 'name', 'glyf'] as $required) {
            if (! isset($tables[$required])) {
                throw new FontEmbeddingException("not an embeddable TrueType font: it has no '{$required}' table");
            }
        }
        if ($tables['OS/2']['length'] < 78 || $tables['head']['length'] < 12) {
            throw new FontEmbeddingException('not an embeddable TrueType font: its OS/2 or head table is too short');
        }

        return new self($bytes, $tables);
    }

    /** OS/2 `fsType`: the embedding permissions the font's licence grants. */
    public function fsType(): int
    {
        return self::u16($this->bytes, $this->tables['OS/2']['offset'] + 8);
    }

    public function weight(): int
    {
        return self::u16($this->bytes, $this->tables['OS/2']['offset'] + 4);
    }

    public function isItalic(): bool
    {
        return (self::u16($this->bytes, $this->tables['OS/2']['offset'] + 62) & 1) === 1;
    }

    /** The ten PANOSE classification bytes. */
    public function panose(): string
    {
        return substr($this->bytes, $this->tables['OS/2']['offset'] + 32, 10);
    }

    /** `ulUnicodeRange1..4`. @return list<int> */
    public function unicodeRanges(): array
    {
        $o = $this->tables['OS/2']['offset'] + 42;

        return [self::u32($this->bytes, $o), self::u32($this->bytes, $o + 4), self::u32($this->bytes, $o + 8), self::u32($this->bytes, $o + 12)];
    }

    /** `ulCodePageRange1..2`, which only OS/2 version 1 and later carry. @return list<int> */
    public function codePageRanges(): array
    {
        $o = $this->tables['OS/2']['offset'];
        if (self::u16($this->bytes, $o) < 1 || $this->tables['OS/2']['length'] < 86) {
            return [0, 0];
        }

        return [self::u32($this->bytes, $o + 78), self::u32($this->bytes, $o + 82)];
    }

    public function checkSumAdjustment(): int
    {
        return self::u32($this->bytes, $this->tables['head']['offset'] + 8);
    }

    /**
     * A name record's text: Windows Unicode (3, 1) US English first, then any
     * Windows Unicode record, then a Macintosh Roman (1, 0) record that is plain
     * ASCII (the only range Mac Roman shares with UTF-8).
     */
    public function name(int $nameId): ?string
    {
        $table = $this->tables['name']['offset'];
        $count = self::u16($this->bytes, $table + 2);
        $storage = $table + self::u16($this->bytes, $table + 4);

        $best = null;
        $bestRank = PHP_INT_MAX;
        for ($i = 0; $i < $count; $i++) {
            $r = $table + 6 + 12 * $i;
            if (self::u16($this->bytes, $r + 6) !== $nameId) {
                continue;
            }
            $platform = self::u16($this->bytes, $r);
            $encoding = self::u16($this->bytes, $r + 2);
            $language = self::u16($this->bytes, $r + 4);
            $raw = substr($this->bytes, $storage + self::u16($this->bytes, $r + 10), self::u16($this->bytes, $r + 8));

            if ($platform === 3 && $encoding === 1) {
                [$rank, $text] = [$language === 0x409 ? 0 : 1, Utf16::beToUtf8($raw)];
            } elseif ($platform === 1 && $encoding === 0 && self::isAscii($raw)) {
                [$rank, $text] = [2, $raw];
            } else {
                continue;
            }
            if ($rank < $bestRank) {
                $best = $text;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /** The family a deck would reference: the typographic family (16) when set, else the legacy one (1). */
    public function family(): ?string
    {
        return $this->name(16) ?? $this->name(1);
    }

    private static function isAscii(string $raw): bool
    {
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            if (ord($raw[$i]) > 0x7F) {
                return false;
            }
        }

        return true;
    }

    private static function u16(string $b, int $o): int
    {
        return (ord($b[$o]) << 8) | ord($b[$o + 1]);
    }

    private static function u32(string $b, int $o): int
    {
        return (ord($b[$o]) << 24) | (ord($b[$o + 1]) << 16) | (ord($b[$o + 2]) << 8) | ord($b[$o + 3]);
    }
}
