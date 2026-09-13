<?php

declare(strict_types=1);

namespace DarkSlide\Fonts;

/**
 * The fonts a host asked to embed, validated and wrapped, ready to write.
 *
 * Supplied through the write options, never through the deck:
 *
 *   Agent::write($deck, $path, ['fonts' => [
 *       'Bebas Neue' => ['regular' => '/fonts/BebasNeue-Regular.ttf'],
 *       'Inter' => ['regular' => $interRegularBytes, 'bold' => '/fonts/Inter-Bold.ttf'],
 *   ]]);
 *
 * The deck is agent-authored JSON; a font is a licensed binary the host owns.
 * Keeping the two apart means an agent can name a typeface but can never make
 * the writer read a file.
 *
 * Each variant is a file path, or the font's bytes (a string containing a NUL
 * byte, which no path does). Variants are `regular`, `bold`, `italic` and
 * `boldItalic`, the four slots PPTX has.
 *
 * ## Refused, all at once, never skipped
 *
 * Every problem across every supplied font is collected and thrown together, so
 * a host fixes them in one pass:
 *
 *   - the licence forbids it: OS/2 `fsType` Restricted License (0x0002) without
 *     a less restrictive bit, or bitmap-only embedding (0x0200). LibreOffice will
 *     render such a font anyway, so the writer is the only place this can hold;
 *   - the file's own family name differs from the typeface it was supplied for.
 *     A deck references a face by name, so a mismatch would embed a font nothing
 *     uses, silently;
 *   - anything {@see TrueTypeFont::fromBytes()} will not accept.
 */
final class EmbeddedFonts
{
    public const VARIANTS = ['regular', 'bold', 'italic', 'boldItalic'];

    private const FS_RESTRICTED = 0x0002;

    private const FS_PREVIEW_PRINT = 0x0004;

    private const FS_EDITABLE = 0x0008;

    private const FS_BITMAP_ONLY = 0x0200;

    /** @param list<array{typeface: string, variant: string, part: string, bytes: string}> $parts */
    private function __construct(public readonly array $parts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, array<string, string>>  $fonts  typeface => variant => path or bytes
     *
     * @throws FontEmbeddingException
     */
    public static function fromOptions(array $fonts): self
    {
        $parts = [];
        $problems = [];

        foreach ($fonts as $typeface => $variants) {
            $typeface = (string) $typeface;
            if (trim($typeface) === '') {
                $problems[] = 'a font was supplied with an empty typeface name';

                continue;
            }
            if (! is_array($variants) || $variants === []) {
                $problems[] = "{$typeface}: no font files; give at least 'regular'";

                continue;
            }
            foreach (array_diff(array_keys($variants), self::VARIANTS) as $unknown) {
                $problems[] = "{$typeface}: '{$unknown}' is not a variant; use ".implode(', ', self::VARIANTS);
            }

            foreach (self::VARIANTS as $variant) {
                if (! array_key_exists($variant, $variants)) {
                    continue;
                }
                try {
                    $font = TrueTypeFont::fromBytes(self::load($variants[$variant]));
                    self::assertEmbeddable($font, $typeface);
                    $parts[] = [
                        'typeface' => $typeface,
                        'variant' => $variant,
                        'part' => 'ppt/fonts/font'.(count($parts) + 1).'.fntdata',
                        'bytes' => EmbeddedOpenType::wrap($font, $typeface),
                    ];
                } catch (FontEmbeddingException $e) {
                    $problems[] = "{$typeface} ({$variant}): ".$e->getMessage();
                }
            }
        }

        if ($problems !== []) {
            throw new FontEmbeddingException("These fonts cannot be embedded:\n- ".implode("\n- ", $problems));
        }

        return new self($parts);
    }

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }

    /**
     * The parts grouped by typeface, in the order they were supplied.
     *
     * @return array<string, array<string, string>> typeface => variant => part path
     */
    public function byTypeface(): array
    {
        $grouped = [];
        foreach ($this->parts as $part) {
            $grouped[$part['typeface']][$part['variant']] = $part['part'];
        }

        return $grouped;
    }

    private static function load(mixed $source): string
    {
        if (! is_string($source) || $source === '') {
            throw new FontEmbeddingException('expected a file path or the font bytes');
        }
        if (str_contains($source, "\0")) {
            return $source;
        }
        if (! is_file($source)) {
            throw new FontEmbeddingException("no font file at {$source}");
        }
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            throw new FontEmbeddingException("could not read {$source}");
        }

        return $bytes;
    }

    private static function assertEmbeddable(TrueTypeFont $font, string $typeface): void
    {
        $fsType = $font->fsType();

        // Bits 0-3 were not mutually exclusive before OS/2 version 3; the least
        // restrictive one set is the one that applies.
        $permissive = self::FS_PREVIEW_PRINT | self::FS_EDITABLE;
        if (($fsType & self::FS_RESTRICTED) !== 0 && ($fsType & $permissive) === 0) {
            throw new FontEmbeddingException(sprintf('its licence forbids embedding (OS/2 fsType 0x%04X, Restricted License)', $fsType));
        }
        if (($fsType & self::FS_BITMAP_ONLY) !== 0) {
            throw new FontEmbeddingException(sprintf('its licence allows bitmap embedding only (OS/2 fsType 0x%04X)', $fsType));
        }

        $family = $font->family();
        if ($family === null || strcasecmp(trim($family), trim($typeface)) !== 0) {
            throw new FontEmbeddingException(sprintf(
                "the file's family name is '%s', not '%s'; a deck references a face by that name, so this font would never be used",
                $family ?? '(none)',
                $typeface,
            ));
        }
    }
}
