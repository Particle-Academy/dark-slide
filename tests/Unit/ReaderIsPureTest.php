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
 * **0.10.1's fix was half a fix, and every test in this file passed anyway.**
 * It derived the id from the whole package, and the package embeds `gmdate()`
 * in `docProps/core.xml` — so the clock read moved from HERE to the writer.
 * Two reads of one buffer still agreed, which is all these cases asked, while a
 * deck saved and re-read got a different id EVERY time instead of one in five.
 * That broke pptx version history in a consumer's shipped product.
 *
 * The lesson is in the shape of the cases below, not in the fix: every one of
 * them read the same buffer twice. A defect one serialisation away was outside
 * what any of them could see. `it keeps the deck id when a save changed
 * nothing` is the case that reaches it, and `it derives the deck id from the
 * deck, not from when it was saved` is the fast deterministic form.
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

it('gives a renamed deck the SAME id, because the title lives in the excluded part', function () {
    // A consequence of excluding `docProps/core.xml` whole, recorded here so it
    // is a decision rather than something the next person discovers. `<dc:title>`
    // shares that part with the save timestamp, so a rename does not move the
    // id — the returned `title` still changes, so a differ still sees the
    // rename, and treating a renamed deck as the same deck is defensible on its
    // own terms. Narrowing the exclusion to the two `<dcterms:*>` elements would
    // change this, at the cost of regexing XML inside the digest path in three
    // engines; it was measured as unnecessary and deliberately not done.
    $json = (string) file_get_contents(__DIR__ . '/../fixtures/reference-deck.json');
    $deck = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $deck['title'] = 'Renamed, same deck';
    $renamed = (new PptxReader())->fromBytes(Agent::toBytes($deck));
    $original = (new PptxReader())->fromBytes(pureRefBytes());

    expect($renamed['title'])->not->toBe($original['title'])
        ->and($renamed['id'])->toBe($original['id']);
});

it('derives the deck id from the deck, not from when it was saved', function () {
    // The deterministic form of the case below, and the one that says WHY:
    // `docProps/core.xml` is the only entry a second save of one deck changes,
    // so the id must not depend on it. Measured, not assumed — of this
    // package's 43 entries, it is the only one that differs across a save.
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
    // It starts from the SETTLED read form rather than from the authored deck,
    // because the reader is lossy by design — a composite comes back as the
    // table it became — so the first read-write-read genuinely changes the deck
    // and is supposed to change the id with it. What must hold is that it then
    // stops: from that point a save that changed nothing changes nothing.
    $settled = (new PptxReader())->fromBytes(Agent::toBytes((new PptxReader())->fromBytes(pureRefBytes())));

    // Forced, not hoped for. Two writes inside one second share a timestamp and
    // would pass against the very code this case exists to fail.
    pureNextSecond();

    $afterAnotherSave = (new PptxReader())->fromBytes(Agent::toBytes($settled));

    expect($afterAnotherSave['id'])->toBe($settled['id'])
        ->and($afterAnotherSave)->toBe($settled);
});
