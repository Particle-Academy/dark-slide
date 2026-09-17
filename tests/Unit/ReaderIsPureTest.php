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
 *
 * **It took three releases to state the property at the right level**, and the
 * first two both passed a suite that looked thorough:
 *
 *   0.10.1  id = digest(whole package)         followed the WRITER's clock
 *   0.10.2  id = digest(package minus core.xml) removed the clock, kept bytes
 *   0.10.3  id = digest(the deck read() returns)
 *
 * The middle one is the instructive failure. Excluding the clock-bearing part
 * removed one source of byte variance and left the others: a deck carrying a
 * shape or a code block re-serialises to different `ppt/slides/slideN.xml`
 * bytes, so the id still moved while the structure sat perfectly still. A digest
 * of bytes identifies a SERIALISATION; two serialisations of one deck are not
 * byte-equal, and no exclusion list was ever going to make them so.
 *
 * So the property is now: **any two byte layouts that read to the same structure
 * get the same id.** Note what that does NOT say — that a file from another
 * producer and one of ours "of the same deck" agree. That holds only as far as
 * `read()` normalises them to the same structure, which is not promised.
 * `foreign-libreoffice.pptx` shows both halves: re-serialising what we read from
 * it keeps the id, while our own rendering of the same source deck reads to a
 * different structure and correctly gets a different id.
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

/** Advance past the next wall-clock second, so two writes cannot share a timestamp. */
function pureNextSecond(): void
{
    $second = time();
    while (time() === $second) {
        usleep(50000);
    }
}

/** The same package with ONLY `docProps/core.xml` replaced — a second save of one deck. */
function pureRestamped(string $bytes, string $modified): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ds-restamp-');
    file_put_contents($tmp, $bytes);

    $zip = new ZipArchive();
    $zip->open($tmp);
    $core = (string) $zip->getFromName('docProps/core.xml');
    $zip->addFromString('docProps/core.xml', (string) preg_replace(
        '/<dcterms:modified[^>]*>[^<]*<\/dcterms:modified>/',
        '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $modified . '</dcterms:modified>',
        $core,
    ));
    $zip->close();

    $out = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $out;
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
    $deck['slides'][0]['elements'][0]['content'] = 'A different deck entirely';
    $other = Agent::toBytes($deck);

    $a = (new PptxReader())->fromBytes(pureRefBytes());
    $b = (new PptxReader())->fromBytes($other);

    expect($a['id'])->not->toBe($b['id']);
});

it('gives a renamed deck a different id, because a title is content', function () {
    // 0.10.2 did the opposite, as a side effect of excluding `docProps/core.xml`
    // whole — `<dc:title>` lives in that part. Digesting the deck rather than the
    // package puts the title back where it belongs: `read()` returns it, so it
    // counts.
    $json = (string) file_get_contents(__DIR__ . '/../fixtures/reference-deck.json');
    $deck = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $deck['title'] = 'Renamed, and that is a change';
    $renamed = (new PptxReader())->fromBytes(Agent::toBytes($deck));
    $original = (new PptxReader())->fromBytes(pureRefBytes());

    expect($renamed['title'])->not->toBe($original['title'])
        ->and($renamed['id'])->not->toBe($original['id']);
});

it('gives two byte layouts of one structure the SAME id', function () {
    // THE property. Everything else in this file is a corollary of it.
    //
    // A shape element is the cheap way to induce it: our own writer does not
    // re-serialise a read-back shape to the same `ppt/slides/slide1.xml` bytes,
    // so these two packages genuinely differ on disk while reading to one deck.
    // The byte-difference is asserted first, because a test where the two
    // buffers happened to be identical would pass while proving nothing.
    $deck = [
        'id' => 'two-layouts',
        'title' => 'Two Layouts',
        'theme' => ['name' => 'default'],
        'slides' => [[
            'id' => 's1',
            'layout' => 'blank',
            'elements' => [['id' => 'r1', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3, 'fill' => '#FF0000']],
        ]],
    ];

    $layoutA = Agent::toBytes($deck);
    $readA = (new PptxReader())->fromBytes($layoutA);
    $layoutB = Agent::toBytes($readA);
    $readB = (new PptxReader())->fromBytes($layoutB);

    $withoutId = function (array $d): array {
        unset($d['id']);

        return $d;
    };

    expect($layoutB)->not->toBe($layoutA)
        ->and($withoutId($readB))->toBe($withoutId($readA))
        ->and($readB['id'])->toBe($readA['id']);
});

it('reads a foreign producer and our re-serialisation of it to one id', function () {
    // The consumer's production shape: version 1 of a deck is the file a user
    // uploaded, every version after it is ours. So the first edit of every
    // upload diffs a FOREIGN serialisation against one of ours.
    //
    // Neither this repo nor its two ports had a single `.pptx` fixture before
    // this one — every fixture was generated by our own writer at test time,
    // which is the same blind spot that left the reader's `random_int` path
    // unexercised for ten minor versions. See the note on the fixture below.
    $foreign = (string) file_get_contents(__DIR__ . '/../fixtures/foreign-libreoffice.pptx');

    $first = (new PptxReader())->fromBytes($foreign);
    $ours = Agent::toBytes($first);
    $second = (new PptxReader())->fromBytes($ours);

    expect($first['slides'])->not->toBeEmpty()
        ->and($ours)->not->toBe($foreign)
        ->and($second['id'])->toBe($first['id']);
});

it('does NOT claim a foreign file and ours of one source deck share an id', function () {
    // The limit of the property, asserted so nobody widens the claim by
    // accident. `read()` recovers what it can model; LibreOffice's rendering of
    // this deck and ours do not reduce to the same structure, so the two ids
    // differ — correctly. The guarantee is about byte layouts of one STRUCTURE,
    // not about two producers' idea of one deck.
    $foreign = (string) file_get_contents(__DIR__ . '/../fixtures/foreign-libreoffice.pptx');
    $source = json_decode(
        (string) file_get_contents(__DIR__ . '/../fixtures/foreign-libreoffice-source.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $fromForeign = (new PptxReader())->fromBytes($foreign);
    $fromOurs = (new PptxReader())->fromBytes(Agent::toBytes($source));

    expect($fromForeign['id'])->not->toBe($fromOurs['id']);
});

it('uses only ASCII keys, which is what lets three engines sort them alike', function () {
    // The canonical encoding sorts map keys, and the three engines' sorts agree
    // only below U+10000 (JS sorts UTF-16 code units, PHP bytes, Python code
    // points). Every key a read deck contains is machine-generated, so this is
    // true by construction — checked rather than assumed, because the digest
    // silently depends on it.
    $keys = [];
    $walk = function ($value) use (&$walk, &$keys): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
            $walk($item);
        }
    };
    $walk((new PptxReader())->fromBytes(pureRefBytes()));
    $walk((new PptxReader())->fromBytes((string) file_get_contents(__DIR__ . '/../fixtures/foreign-libreoffice.pptx')));

    expect($keys)->not->toBeEmpty()
        ->and(array_values(array_filter(array_keys($keys), fn (string $k): bool => preg_match('/[^\x20-\x7E]/', $k) === 1)))
        ->toBe([]);
});

it('derives the deck id from the deck, not from when it was saved', function () {
    // The clock, specifically: `docProps/core.xml` carries the save stamp, and a
    // digest over the deck cannot see it because `read()` does not return it.
    // Kept as its own case because it is the fast deterministic form — no second
    // serialisation needed — and because it is the exact defect 0.10.1 shipped.
    $bytes = pureRefBytes();
    $restamped = pureRestamped($bytes, '2019-01-01T00:00:00Z');

    expect($restamped)->not->toBe($bytes)
        ->and((new PptxReader())->fromBytes($restamped)['id'])
        ->toBe((new PptxReader())->fromBytes($bytes)['id']);
});

it('keeps the deck id when a save changed nothing', function () {
    // The consumer-shaped case, and the one 0.10.1 would have failed: every
    // other test here reads ONE buffer twice, so a defect that needs a second
    // serialisation to appear is invisible to all of them.
    //
    // It starts from the SETTLED read form rather than from the authored deck.
    // The reader IS lossy for some constructs — a composite comes back as the
    // table it became — so the first read-write-read can genuinely change the
    // deck, and an id that moves with it is right. Take care with that sentence:
    // it was once used to excuse an id moving while the structure did not, which
    // is a different thing and was the 0.10.2 bug. What is asserted here is that
    // the STRUCTURE settles and the id settles with it.
    $settled = (new PptxReader())->fromBytes(Agent::toBytes((new PptxReader())->fromBytes(pureRefBytes())));

    // Forced, not hoped for. Two writes inside one second share a timestamp and
    // would pass against the very code this case exists to fail.
    pureNextSecond();

    $afterAnotherSave = (new PptxReader())->fromBytes(Agent::toBytes($settled));

    expect($afterAnotherSave['id'])->toBe($settled['id'])
        ->and($afterAnotherSave)->toBe($settled);
});
