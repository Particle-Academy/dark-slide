## Dark Slide

`particle-academy/dark-slide` — a framework-agnostic PHP 8.2+ library for writing real `.pptx` (Office Open XML) presentations and reading them back. Designed as the slides-shaped sibling to {@see \HolySheet\Agent} — same agent-tool shape, different output format. Round-trips with the JS `@particle-academy/fancy-slides` Deck schema, so an LLM can author a deck in JSON and a Laravel job can hand back a `.pptx` users can open in PowerPoint / Keynote / Google Slides / LibreOffice Impress.

### Features

- **Framework-agnostic core**: Pure PHP, no Laravel dependency. Optional `DarkSlideServiceProvider` for Laravel 10-13.
- **Agent-shaped API**: `DarkSlide\Agent` is a single static class — `validate`, `validateAndRepair`, `write`, `toBytes`, `read`, `describe`, `jsonSchema`. No DI container required.
- **JSON Schema export**: `Agent::jsonSchema()` returns the schema for the `$deck` shape — feed directly into LLM tool registration so the agent emits valid decks the first time.
- **Round-trip read/write**: `Agent::read($path)` extracts a deck from a `.pptx` back into the same JSON shape `Agent::write()` accepts. High fidelity for files this package wrote; hand-authored PowerPoint decks drop styling the schema can't represent.
- **Rich content support**: markdown headings, inline bold/italic/code spans, real tables (not images), syntax-highlighted code blocks, gradient backgrounds, embedded images.
- **Mirrors Holy Sheet**: same `Agent` method shape so "write me an xlsx" and "write me a pptx" feel identical on the caller side.

### Public surface

- `DarkSlide\Agent` — static facade for the validate / write / read / repair loop
- `DarkSlide\DarkSlide` — instance-style entry point (useful with DI)
- `DarkSlide\Schema\Validator` — pure validator; returns structured errors
- `DarkSlide\Schema\Repairer` — heuristic repairs (used by `Agent::validateAndRepair`)
- `DarkSlide\Schema\Schema` — schema definition + JSON Schema export
- `DarkSlide\Writer\PptxWriter` — low-level pptx writer
- `DarkSlide\Reader\PptxReader` — low-level pptx reader
- `DarkSlide\Helpers\SyntaxHighlighter` — pure-PHP tokenizer for colored code blocks
- `DarkSlide\Helpers\MarkdownInline` — inline markdown parser for slide text
- `DarkSlide\Helpers\Color`, `Emu`, `Xml` — internal helpers (rarely needed in app code)

### Quick start (Agent surface)

<code-snippet name="Dark Slide — write a deck" lang="php">
use DarkSlide\Agent;

$deck = [
    'id'    => 'fancy-ui-pitch',
    'title' => 'Fancy UI Kit',
    'theme' => ['name' => 'default'],
    'slides' => [
        [
            'id' => 'cover',
            'elements' => [
                ['type' => 'text', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2,
                 'content' => '# Fancy UI Kit',
                 'format' => 'markdown'],
                ['type' => 'text', 'x' => 0.1, 'y' => 0.65, 'w' => 0.8, 'h' => 0.1,
                 'content' => 'Composable primitives for humans and agents',
                 'format' => 'plain'],
            ],
            'background' => ['color' => '#ffffff'],
        ],
        // …more slides
    ],
];

$result = Agent::write($deck, '/path/to/fancy-ui-pitch.pptx');
// => ['path' => '...', 'bytes' => 6291, 'slides' => 1]
</code-snippet>

### Validate before writing

<code-snippet name="Dark Slide — validate" lang="php">
use DarkSlide\Agent;

$errors = Agent::validate($deck);

if ($errors !== []) {
    // Each error: ['path' => '...', 'expected' => '...', 'got' => '...', 'value' => ..., 'hint' => '...']
    // Hint is written for the agent — feed back verbatim.
    return ['needs_revision' => true, 'errors' => $errors];
}
</code-snippet>

### Repair-or-fail (agentic loop)

<code-snippet name="Dark Slide — validateAndRepair" lang="php">
use DarkSlide\Agent;

$result = Agent::validateAndRepair($deck);

// $result = [
//   'ok'     => bool,
//   'schema' => array, // repaired version — pass to write()
//   'errors' => list<error>, // what was repaired (or what's still broken if !ok)
// ]

if (! $result['ok']) {
    return ['needs_revision' => true, 'errors' => $result['errors']];
}

Agent::write($result['schema'], $path);
</code-snippet>

### Round-trip a pptx back to JSON

<code-snippet name="Dark Slide — read" lang="php">
use DarkSlide\Agent;

$deck = Agent::read('/path/to/existing.pptx');
// Same shape Agent::write() accepts — round-trip safe for files this package wrote.

// Or get a plain-text human/agent summary:
echo Agent::describe($deck);
</code-snippet>

### Register with an LLM as a tool

<code-snippet name="Dark Slide — tool registration" lang="php">
use DarkSlide\Agent;

$tools = [[
    'name'        => 'write_presentation',
    'description' => 'Write a .pptx presentation from a Deck JSON schema. Returns the file path + slide count.',
    'input_schema' => Agent::jsonSchema(),
]];
</code-snippet>

### In-memory bytes (no disk)

<code-snippet name="Dark Slide — toBytes" lang="php">
use DarkSlide\Agent;

$pptxBytes = Agent::toBytes($deck);

return response($pptxBytes, 200, [
    'Content-Type'        => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'Content-Disposition' => 'attachment; filename="deck.pptx"',
]);
</code-snippet>

### Laravel integration (optional)

The `DarkSlideServiceProvider` auto-discovers under Laravel 10-13. Binds `DarkSlide` into the container under `dark-slide`. No config publishing required by default.

<code-snippet name="Dark Slide — Laravel usage" lang="php">
$result = \DarkSlide\Agent::write($deck, storage_path('app/deck.pptx'));
return response()->download($result['path']);
</code-snippet>

### Conventions

- **Decks are plain arrays** — agent-friendly, easy to log, easy to diff. Same Deck shape as the JS `@particle-academy/fancy-slides` package.
- **Errors are structured arrays**, not exceptions. Each error carries `path`, `expected`, `got`, `value`, `hint`. Pass straight back to the agent for its next emission.
- **Static methods on `Agent`** for the agent-facing surface; instance methods on internal services for DI.
- **Round-trip-safe** between `Agent::write()` and `Agent::read()` for files this package wrote.
- **No external office binaries**: writer + reader are pure PHP. No LibreOffice headless, no system PowerPoint.
- **Mirror with Holy Sheet**: if you're already using `HolySheet\Agent`, the call shape here is identical — `validate`, `write`, `read`, `validateAndRepair`, `describe`. Easy mental model for agents that author both spreadsheets and decks.
