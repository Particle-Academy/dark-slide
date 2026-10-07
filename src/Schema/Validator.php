<?php

declare(strict_types=1);

namespace DarkSlide\Schema;

use DarkSlide\Table\TableResolver;

/**
 * Schema-shape validator. Catches malformed agent output before it reaches
 * the writer. Returns a structured error list (path + expected + got +
 * hint) so the agent can correct itself in a follow-up tool call.
 *
 * Validation is intentionally LIBERAL: missing optional fields, unknown
 * element types, extra keys all pass. Only structural mistakes the writer
 * cannot recover from get flagged.
 */
final class Validator
{
    /**
     * @param  array<string, mixed>  $deck
     * @return list<array{path: string, expected: string, got: string, value: mixed, hint: string}>
     */
    public function validate(array $deck): array
    {
        $errors = [];

        foreach (Schema::deckRequiredKeys() as $key) {
            if (!array_key_exists($key, $deck)) {
                $errors[] = $this->err("/{$key}", $key, 'missing', null, "Deck must have an `{$key}` field.");
            }
        }

        if (isset($deck['id']) && !is_string($deck['id'])) {
            $errors[] = $this->err('/id', 'string', $this->typeOf($deck['id']), $deck['id'], 'Deck id must be a string.');
        }

        if (isset($deck['title']) && !is_string($deck['title'])) {
            $errors[] = $this->err('/title', 'string', $this->typeOf($deck['title']), $deck['title'], 'Deck title must be a string.');
        }

        if (isset($deck['theme'])) {
            if (!is_array($deck['theme'])) {
                $errors[] = $this->err('/theme', 'object', $this->typeOf($deck['theme']), $deck['theme'], 'Theme must be an object with at least a `name` field.');
            } elseif (!isset($deck['theme']['name'])) {
                $errors[] = $this->err('/theme/name', 'string', 'missing', null, 'Theme must have a name.');
            }
        }

        if (isset($deck['slides'])) {
            if (!is_array($deck['slides']) || !array_is_list($deck['slides'])) {
                $errors[] = $this->err('/slides', 'array', $this->typeOf($deck['slides']), $deck['slides'], 'Slides must be a JSON array.');
            } else {
                foreach ($deck['slides'] as $i => $slide) {
                    array_push($errors, ...$this->validateSlide($slide, "/slides/{$i}"));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  mixed  $slide
     * @return list<array{path: string, expected: string, got: string, value: mixed, hint: string}>
     */
    private function validateSlide(mixed $slide, string $path): array
    {
        $errors = [];

        if (!is_array($slide)) {
            return [$this->err($path, 'object', $this->typeOf($slide), $slide, 'Each slide must be a JSON object.')];
        }

        foreach (Schema::slideRequiredKeys() as $key) {
            if (!array_key_exists($key, $slide)) {
                $errors[] = $this->err("{$path}/{$key}", $key, 'missing', null, "Slide must have a `{$key}` field.");
            }
        }

        if (isset($slide['id']) && !is_string($slide['id'])) {
            $errors[] = $this->err("{$path}/id", 'string', $this->typeOf($slide['id']), $slide['id'], 'Slide id must be a string.');
        }

        if (isset($slide['layout']) && !in_array($slide['layout'], Schema::SLIDE_LAYOUTS, true)) {
            // Not an error — unknown layouts fall back to free placement. Logged for awareness only.
        }

        if (isset($slide['elements'])) {
            if (!is_array($slide['elements']) || !array_is_list($slide['elements'])) {
                $errors[] = $this->err("{$path}/elements", 'array', $this->typeOf($slide['elements']), $slide['elements'], 'Slide elements must be an array.');
            } else {
                foreach ($slide['elements'] as $i => $element) {
                    array_push($errors, ...$this->validateElement($element, "{$path}/elements/{$i}"));
                }
            }
        }

        if (isset($slide['notes']) && !is_string($slide['notes'])) {
            $errors[] = $this->err("{$path}/notes", 'string', $this->typeOf($slide['notes']), $slide['notes'], 'Slide notes must be a string.');
        }

        return $errors;
    }

    /**
     * @return list<array{path: string, expected: string, got: string, value: mixed, hint: string}>
     */
    private function validateElement(mixed $element, string $path): array
    {
        $errors = [];

        if (!is_array($element)) {
            return [$this->err($path, 'object', $this->typeOf($element), $element, 'Each element must be a JSON object.')];
        }

        foreach (Schema::elementRequiredKeys() as $key) {
            if (!array_key_exists($key, $element)) {
                $errors[] = $this->err("{$path}/{$key}", $key, 'missing', null, "Element must have a `{$key}` field.");
            }
        }

        if (isset($element['type']) && !in_array($element['type'], Schema::ELEMENT_TYPES, true)) {
            $errors[] = $this->err(
                "{$path}/type",
                'one of: ' . implode(' / ', Schema::ELEMENT_TYPES),
                (string) $element['type'],
                $element['type'],
                'Unknown element type — supported: ' . implode(', ', Schema::ELEMENT_TYPES),
            );
        }

        foreach (['x', 'y', 'w', 'h'] as $coord) {
            if (!array_key_exists($coord, $element)) {
                continue; // already flagged as missing required key above
            }
            if (!is_numeric($element[$coord])) {
                $errors[] = $this->err("{$path}/{$coord}", 'number (0..1)', $this->typeOf($element[$coord]), $element[$coord], "Element {$coord} must be a number in the 0..1 range (slide-relative fraction).");
            }
        }

        // Type-specific minimal checks
        if (isset($element['type']) && is_string($element['type'])) {
            switch ($element['type']) {
                case 'text':
                    if (!isset($element['content']) || !is_string($element['content'])) {
                        $errors[] = $this->err("{$path}/content", 'string', $this->typeOf($element['content'] ?? null), $element['content'] ?? null, 'Text element must have a `content` string.');
                    }
                    break;
                case 'image':
                    if (!isset($element['src']) || !is_string($element['src'])) {
                        $errors[] = $this->err("{$path}/src", 'string (URL or data URI)', $this->typeOf($element['src'] ?? null), $element['src'] ?? null, 'Image element must have a `src` string.');
                    }
                    break;
                case 'shape':
                    if (!isset($element['shape']) || !in_array($element['shape'], Schema::SHAPE_KINDS, true)) {
                        $errors[] = $this->err("{$path}/shape", 'one of: ' . implode(' / ', Schema::SHAPE_KINDS), (string) ($element['shape'] ?? 'missing'), $element['shape'] ?? null, 'Shape element must specify a known `shape` kind.');
                    }
                    break;
                case 'code':
                    if (!isset($element['code']) || !is_string($element['code'])) {
                        $errors[] = $this->err("{$path}/code", 'string', $this->typeOf($element['code'] ?? null), $element['code'] ?? null, 'Code element must have a `code` string.');
                    }
                    break;
                case 'table':
                    $errors = array_merge($errors, $this->validateTableRows($element, $path));
                    break;
                case 'kpiBand':
                case 'metadataGrid':
                    // An items-less composite is not an error the writer can
                    // see: it expands to a table with no rows and renders as a
                    // blank rectangle, which is the worst kind of wrong.
                    if (!isset($element['items']) || !is_array($element['items']) || $element['items'] === []) {
                        $errors[] = $this->err("{$path}/items", 'non-empty array', $this->typeOf($element['items'] ?? null), $element['items'] ?? null, "A `{$element['type']}` must have a non-empty `items` array.");
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * A table row that shares no key with any column.
     *
     * Every cell resolves to nothing and the table still draws at FULL SIZE,
     * because its geometry comes from the columns and the row count. The result is
     * a correctly-shaped grid with every cell blank and nothing raised anywhere --
     * the same class of wrong as the items-less composite above, and it reached a
     * customer as a table whose rows were empty (fancy-slides#14).
     *
     * Three shapes are NOT this and must not be flagged: a POSITIONAL row (a list,
     * read in column order), a partially-filled row (a missing cell is simply
     * empty), and a row carrying only row-level style. Only an object row whose
     * cell keys match NOTHING is unrecoverable -- in practice a mis-cased or
     * renamed key, which is why the hint names the keys that would work.
     *
     * @param  array<string, mixed>  $element
     * @return list<array{path: string, expected: string, got: string, value: mixed, hint: string}>
     */
    private function validateTableRows(array $element, string $path): array
    {
        $rows = $element['rows'] ?? null;
        $rawColumns = $element['columns'] ?? null;
        if (! is_array($rows) || ! is_array($rawColumns) || $rawColumns === []) {
            return [];
        }

        $keys = array_column(TableResolver::normalizeColumns($rawColumns), 'key');
        $ignored = array_merge(TableResolver::STYLE_KEYS, TableResolver::ROW_KEYS);
        $errors = [];

        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $inner = is_array($row['cells'] ?? null) ? $row['cells'] : $row;
            if (array_is_list($inner)) {
                continue; // positional, read in column order
            }

            $claimed = array_diff(array_keys($inner), $ignored);
            if ($claimed === [] || array_intersect($claimed, $keys) !== []) {
                continue;
            }

            $errors[] = $this->err(
                "{$path}/rows/{$i}",
                'at least one key from: '.implode(' / ', $keys),
                'keys: '.implode(' / ', $claimed),
                $row,
                'No column reads anything from this row, so every cell would render empty at full table size. Key each cell by a column key ('.implode(', ', $keys).'), or give the row as a positional list in column order.',
            );
        }

        return $errors;
    }
    /**
     * @param  mixed  $value
     * @return array{path: string, expected: string, got: string, value: mixed, hint: string}
     */
    private function err(string $path, string $expected, string $got, mixed $value, string $hint): array
    {
        return [
            'path' => $path,
            'expected' => $expected,
            'got' => $got,
            'value' => $value,
            'hint' => $hint,
        ];
    }

    private function typeOf(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_array($v)) {
            return array_is_list($v) ? 'array' : 'object';
        }

        return gettype($v);
    }
}
