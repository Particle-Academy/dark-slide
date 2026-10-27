<?php

declare(strict_types=1);

namespace DarkSlide\Helpers;

/**
 * Tiny inline-markdown tokenizer. Splits a single paragraph (no newlines)
 * into a list of `(text, flags)` runs that map cleanly onto drawingML's
 * `<a:r>` elements with `<a:rPr>` decoration.
 *
 * Supports:
 *
 *   **bold**       — `b` flag
 *   __bold__       — `b` flag
 *   *italic*       — `i` flag
 *   _italic_       — `i` flag
 *   `code`         — `code` flag (monospace + colored)
 *
 * Combinations are honored (`***bold italic***`, `**bold with `code` inside**`).
 * Unknown / unmatched markers fall through as literal text.
 *
 * Backslash escapes follow CommonMark: a backslash before any ASCII
 * punctuation character makes that character literal and is removed; a
 * backslash before anything else is a literal backslash. Inside a code span
 * a backslash is always literal.
 *
 * Deliberately simple — no nested emphasis tracking beyond the bare
 * minimum the demo decks use. Block-level markdown (headings, lists,
 * blockquotes) is handled by the caller; this tokenizer only deals with
 * inline runs.
 *
 * @phpstan-type InlineRun array{text: string, b: bool, i: bool, code: bool}
 */
final class MarkdownInline
{
    /**
     * Tokenize a single paragraph string into runs.
     *
     * @return list<array{text: string, b: bool, i: bool, code: bool}>
     */
    public static function tokenize(string $text): array
    {
        $runs = [];
        $i = 0;
        $len = strlen($text);
        $buf = '';
        $b = false;
        $it = false;
        $code = false;

        $flush = function () use (&$buf, &$runs, &$b, &$it, &$code) {
            if ($buf !== '') {
                $runs[] = ['text' => $buf, 'b' => $b, 'i' => $it, 'code' => $code];
                $buf = '';
            }
        };

        while ($i < $len) {
            $c = $text[$i];
            $next2 = substr($text, $i, 2);

            // CommonMark backslash escape: before ASCII punctuation the
            // backslash is dropped and the character is literal, so it can
            // never open or close a span. Before anything else (a letter, a
            // digit, a space, a UTF-8 lead byte, the end of the paragraph)
            // the backslash is itself literal and falls through below. Code
            // spans are sliced raw further down, so nothing is an escape
            // inside one.
            if ($c === '\\' && $i + 1 < $len && self::isAsciiPunctuation($text[$i + 1])) {
                $buf .= $text[$i + 1];
                $i += 2;
                continue;
            }

            // Code spans win — they swallow markers literally.
            if ($c === '`' && !$code) {
                $flush();
                $end = strpos($text, '`', $i + 1);
                if ($end === false) {
                    // Unmatched — render the rest as plain.
                    $buf .= substr($text, $i);
                    $i = $len;
                    continue;
                }
                $codeText = substr($text, $i + 1, $end - $i - 1);
                $runs[] = ['text' => $codeText, 'b' => $b, 'i' => $it, 'code' => true];
                $i = $end + 1;
                continue;
            }

            // Bold via ** or __
            if ($next2 === '**' || $next2 === '__') {
                $flush();
                $b = !$b;
                $i += 2;
                continue;
            }

            // Italic via * or _ (but not ** / __, which we just consumed)
            if (($c === '*' || $c === '_') && !$code) {
                // Guard: don't treat _ inside snake_case as emphasis.
                // Heuristic: only open / close italic when surrounded by
                // word-boundary characters.
                $prev = $i > 0 ? $text[$i - 1] : ' ';
                $follow = $i + 1 < $len ? $text[$i + 1] : ' ';
                $isWordChar = fn ($ch) => preg_match('/[a-zA-Z0-9_]/', $ch) === 1;
                if (
                    ($it && !$isWordChar($prev) === false)  // closing inside word: still close
                    || !$it
                ) {
                    if (!$it) {
                        // Opening: require non-word before, word after.
                        if (!$isWordChar($prev) || $prev === ' ') {
                            $flush();
                            $it = true;
                            $i++;
                            continue;
                        }
                    } else {
                        // Closing: just close.
                        $flush();
                        $it = false;
                        $i++;
                        continue;
                    }
                }
            }

            $buf .= $c;
            $i++;
        }

        $flush();

        if (empty($runs)) {
            $runs[] = ['text' => '', 'b' => false, 'i' => false, 'code' => false];
        }

        return $runs;
    }

    /**
     * CommonMark's ASCII punctuation set: `!` to `/`, `:` to `@`, `[` to
     * `` ` ``, and `{` to `~`. Takes one BYTE; a UTF-8 lead or continuation
     * byte is never in range, so a backslash before non-ASCII stays literal.
     */
    private static function isAsciiPunctuation(string $ch): bool
    {
        $o = ord($ch);

        return ($o >= 33 && $o <= 47)
            || ($o >= 58 && $o <= 64)
            || ($o >= 91 && $o <= 96)
            || ($o >= 123 && $o <= 126);
    }

    /**
     * Detect whether a paragraph starts with a markdown bullet marker.
     * Returns `[isBullet, contentWithoutMarker]`.
     *
     * @return array{0: bool, 1: string}
     */
    public static function bulletPrefix(string $line): array
    {
        if (str_starts_with($line, '- ') || str_starts_with($line, '* ')) {
            return [true, substr($line, 2)];
        }

        return [false, $line];
    }

    /**
     * Detect whether a paragraph starts with a markdown ATX heading marker
     * (`# `, `## `, `### `, …). Returns `[level, contentWithoutMarker]`
     * where level is 1..6, or `[0, $line]` if no heading was found.
     *
     * Only paragraph-leading markers count — `# `s mid-line are passed
     * through as plain text.
     *
     * @return array{0: int, 1: string}
     */
    public static function headingPrefix(string $line): array
    {
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
            return [strlen($m[1]), $m[2]];
        }

        return [0, $line];
    }
}
