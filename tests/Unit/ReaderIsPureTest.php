<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Reader\PptxReader;

/**
 * `read()` is a pure function of its bytes.
 *
 * Until 0.10.1 it was not, in two places: the deck id came from `time()`, and
 * an element whose `<p:cNvPr>` carried no `name` got `random_int(1000, 9999)`.
 * The second is the worse one — the clock id only moved across a tick, while a
 * nameless shape got a new id on every single read.
 *
 * It matters because reads get DIFFED. A consumer storing file history as
 * reverse edits compares two read structures, so a field that moves on its own
 * turns a diff of identical content into a whole-deck `replace` — a save that
 * changed nothing storing the entire deck, which is precisely what that design
 * exists to avoid. Reported as dark-slide#9.
 *
 * Both halves are asserted without sleeping, deliberately: a test that reads
 * twice and hopes to straddle a second boundary passes by luck, and one that
 * sleeps to force it pays a second on every run to test a symptom rather than
 * the property. Same bytes agree; different bytes disagree.
 */

/** The nine-slide acceptance deck, as written .pptx bytes. */
function pureRefBytes(): string
{
    static $bytes = null;
    if ($bytes === null) {
        $json = (string) file_get_contents(__DIR__ . '/../fixtures/reference-deck.json');
        $bytes = Agent::toBytes(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    return $bytes;
}

/**
 * The same deck with every `name` attribute stripped off its slide parts.
 *
 * A deck DarkSlide wrote always names its shapes, so its own output never
 * reaches the fallback and a round-trip test cannot see this bug at all. Files
 * from other producers do reach it — and a nameless `<p:cNvPr>` is what a
 * consumer importing arbitrary decks is handed.
 */
function pureNamelessBytes(): string
{
    static $bytes = null;
    if ($bytes !== null) {
        return $bytes;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ds-nameless-');
    file_put_contents($tmp, pureRefBytes());

    $zip = new ZipArchive();
    $zip->open($tmp);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!str_starts_with((string) $name, 'ppt/slides/slide')) {
            continue;
        }
        $xml = (string) $zip->getFromIndex($i);
        $zip->addFromString((string) $name, (string) preg_replace('/(<p:cNvPr\b[^>]*?)\s+name="[^"]*"/', '$1', $xml));
    }
    $zip->close();

    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

it('returns an identical structure for identical bytes', function () {
    $first = (new PptxReader())->fromBytes(pureRefBytes());
    $second = (new PptxReader())->fromBytes(pureRefBytes());

    expect($second)->toBe($first);
});

it('returns an identical structure for elements that have no name to borrow an id from', function () {
    $bytes = pureNamelessBytes();

    $first = (new PptxReader())->fromBytes($bytes);
    $second = (new PptxReader())->fromBytes($bytes);

    // Named early, because a bare structure diff over nine slides says nothing
    // about WHICH field moved.
    $ids = function (array $deck): array {
        $out = [];
        foreach ($deck['slides'] as $slide) {
            foreach ($slide['elements'] ?? [] as $element) {
                $out[] = $element['id'];
            }
        }

        return $out;
    };

    expect($ids($first))->not->toBeEmpty()
        ->and($ids($second))->toBe($ids($first))
        ->and($second)->toBe($first);
});

it('reuses one reader instance without carrying state between files', function () {
    $reader = new PptxReader();

    $fresh = (new PptxReader())->fromBytes(pureNamelessBytes());
    $reader->fromBytes(pureRefBytes());
    $afterAnotherFile = $reader->fromBytes(pureNamelessBytes());

    expect($afterAnotherFile)->toBe($fresh);
});

it('gives two different decks two different ids', function () {
    // The clock id's other half: `time()` does not only move, it also COLLIDES.
    // Every deck imported in the same second shared one id, so a store keyed on
    // it overwrote one import with another.
    $json = (string) file_get_contents(__DIR__ . '/../fixtures/reference-deck.json');
    $deck = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $deck['title'] = 'A different deck entirely';
    $other = Agent::toBytes($deck);

    $a = (new PptxReader())->fromBytes(pureRefBytes());
    $b = (new PptxReader())->fromBytes($other);

    expect($a['id'])->not->toBe($b['id']);
});

it('derives the deck id from the bytes and nothing else', function () {
    $deck = (new PptxReader())->fromBytes(pureRefBytes());

    expect($deck['id'])->toBe('imported-' . sprintf('%08x', crc32(pureRefBytes())));
});
