<?php

declare(strict_types=1);

namespace DarkSlide;

use DarkSlide\Exceptions\SchemaException;
use DarkSlide\Reader\PptxReader;
use DarkSlide\Schema\Repairer;
use DarkSlide\Schema\Schema;
use DarkSlide\Schema\Validator;
use DarkSlide\Writer\PptxWriter;

/**
 * Agent — the structured-tool surface for DarkSlide.
 *
 * Designed for LLM tool-use: validate-then-write semantics, structured
 * error format, JSON Schema export for tool definitions. Every method is
 * static for the simplest possible call shape from agent infrastructure.
 *
 * Mirrors {@see \HolySheet\Agent} so the two libraries feel like sibling
 * tools — "write me an xlsx" and "write me a pptx" take the same code
 * shape on the caller side.
 */
final class Agent
{
    /**
     * Validate a deck without writing anything. Returns a structured error
     * list — empty when the deck is valid. Pass the JSON Schema from
     * {@see jsonSchema()} to your LLM tool registration to give the agent
     * field-level hints up front.
     *
     * @param  array<string, mixed>  $deck
     * @return list<array{path: string, expected: string, got: string, value: mixed, hint: string}>
     */
    public static function validate(array $deck): array
    {
        return (new Validator())->validate($deck);
    }

    /**
     * Validate + apply heuristic repairs. Returns:
     *
     *   - `ok: true, schema: array, errors: []`     — deck was valid as-is
     *   - `ok: true, schema: array, errors: [...]`  — deck had recoverable issues; the returned schema is the repaired version, errors lists what changed
     *   - `ok: false, schema: array, errors: [...]` — deck couldn't be repaired safely
     *
     * Designed for agentic feedback loops: hand the agent back the errors
     * if !ok so it can correct its next emission.
     *
     * @param  array<string, mixed>  $deck
     * @return array{ok: bool, schema: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    public static function validateAndRepair(array $deck): array
    {
        $errors = self::validate($deck);
        if (empty($errors)) {
            return ['ok' => true, 'schema' => $deck, 'errors' => []];
        }
        $repaired = (new Repairer())->repair($deck);
        $remaining = self::validate($repaired);

        return [
            'ok' => empty($remaining),
            'schema' => $repaired,
            'errors' => $remaining,
        ];
    }

    /**
     * Write a deck to disk as a PPTX file. Throws SchemaException on
     * unrecoverable validation errors.
     *
     * Options:
     *   - `tempDir` (string): override the temp dir used to assemble the
     *     archive (for hosts where the system temp isn't writable).
     *   - `allowHttpImages` (bool, default false): when true, `http(s)://`
     *     image sources are fetched and embedded. Off by default — fetching
     *     remote URLs is a security boundary the caller must opt into.
     *
     * @param  array<string, mixed>  $deck
     * @param  array{tempDir?: ?string, allowHttpImages?: bool}  $options
     * @return array{path: string, bytes: int, slides: int}
     *
     * @throws SchemaException
     */
    public static function write(array $deck, string $path, array $options = []): array
    {
        $errors = self::validate($deck);
        if (!empty($errors)) {
            throw new SchemaException(
                'Deck failed schema validation. Call Agent::validateAndRepair() for a recoverable form.',
                $errors,
            );
        }

        return self::makeWriter($options)->write($deck, $path);
    }

    /**
     * Return the PPTX bytes for a deck (no temp file). Same validation
     * semantics + options as {@see write()}.
     *
     * @param  array<string, mixed>  $deck
     * @param  array{tempDir?: ?string, allowHttpImages?: bool}  $options
     *
     * @throws SchemaException
     */
    public static function toBytes(array $deck, array $options = []): string
    {
        $errors = self::validate($deck);
        if (!empty($errors)) {
            throw new SchemaException(
                'Deck failed schema validation. Call Agent::validateAndRepair() for a recoverable form.',
                $errors,
            );
        }

        return self::makeWriter($options)->toBytes($deck);
    }

    /**
     * Construct a writer from the shared options array.
     *
     * @param  array{tempDir?: ?string, allowHttpImages?: bool}  $options
     */
    private static function makeWriter(array $options): PptxWriter
    {
        return new PptxWriter(
            $options['tempDir'] ?? null,
            (bool) ($options['allowHttpImages'] ?? false),
        );
    }

    /**
     * Read a PPTX file back into the Deck schema. Best-effort: text /
     * image / shape elements with their geometry come through; styling
     * fidelity, masters, transitions, animations are dropped.
     *
     * @return array<string, mixed>
     */
    public static function read(string $path): array
    {
        return (new PptxReader())->read($path);
    }

    /**
     * Plain-text summary of a deck — slide count, layouts, element counts
     * per type. Useful as an agent tool that "describes" a deck without
     * dumping the full JSON back to the model.
     *
     * @param  array<string, mixed>  $deck
     */
    public static function describe(array $deck): string
    {
        $title = (string) ($deck['title'] ?? 'Untitled');
        $themeName = (string) ($deck['theme']['name'] ?? Schema::DEFAULT_THEME_NAME);
        $slides = $deck['slides'] ?? [];
        $slideCount = count($slides);

        $elementCounts = [];
        foreach ($slides as $slide) {
            foreach (($slide['elements'] ?? []) as $element) {
                $type = (string) ($element['type'] ?? 'unknown');
                $elementCounts[$type] = ($elementCounts[$type] ?? 0) + 1;
            }
        }

        $lines = [
            "Deck: {$title}",
            "Theme: {$themeName}",
            "Slides: {$slideCount}",
        ];
        if (!empty($elementCounts)) {
            $parts = [];
            foreach ($elementCounts as $type => $n) {
                $parts[] = "{$n} {$type}";
            }
            $lines[] = 'Elements: ' . implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * JSON Schema export for LLM tool-use registration. Pass this to your
     * MCP server / agent SDK so the model gets typed field hints.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return Schema::jsonSchema();
    }
}
