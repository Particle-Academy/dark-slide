<?php

declare(strict_types=1);

use DarkSlide\Writer\PptxWriter;

/**
 * `docProps/core.xml`'s timestamps are an INPUT when the deck supplies them.
 *
 * dark-slide#10. `buildCoreProps` embedded `gmdate()` unconditionally, so two
 * `toBytes()` calls on one deck a second apart produced different bytes. That
 * makes the writer unusable for anything that compares or addresses its output:
 * a save that changed nothing reads as a change to a content-addressed store or
 * a byte-level diff.
 *
 * `dcterms:created` / `dcterms:modified` are legitimately timestamps -- Word and
 * PowerPoint display them -- so the clock is not simply removed. It becomes the
 * DEFAULT, and `metadata.created` / `metadata.modified` override it. A caller
 * that wants reproducible bytes supplies them; a caller that does not sees
 * exactly the previous behaviour.
 *
 * **The spelling is not new and was not chosen here.** `dark-slide-py` has
 * honoured these two keys since its first release (`EPOCH_TIMESTAMP` in
 * `pptx_writer.py`), including `modified` falling back to `created` rather than
 * to the default. A consumer writes ONE deck for three engines, so a second
 * spelling would be the defect this fixes, one layer out.
 */
function coreXmlOf(array $deck): string
{
    $bytes = (new PptxWriter)->toBytes($deck);
    $path = tempnam(sys_get_temp_dir(), 'ds') . '.pptx';
    file_put_contents($path, $bytes);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('docProps/core.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

function deckWithMetadata(array $metadata = []): array
{
    return [
        'id' => 'd1',
        'title' => 'Timestamps',
        'slides' => [['id' => 's1', 'elements' => []]],
        'metadata' => $metadata,
    ];
}

it('honours metadata.created and metadata.modified', function (): void {
    $xml = coreXmlOf(deckWithMetadata([
        'created' => '2020-02-02T03:04:05Z',
        'modified' => '2021-03-03T04:05:06Z',
    ]));

    expect($xml)->toContain('<dcterms:created xsi:type="dcterms:W3CDTF">2020-02-02T03:04:05Z</dcterms:created>');
    expect($xml)->toContain('<dcterms:modified xsi:type="dcterms:W3CDTF">2021-03-03T04:05:06Z</dcterms:modified>');
});

it('falls back modified to created, not to the clock', function (): void {
    // Python's rule, matched exactly. A deck that states when it was made and
    // says nothing about edits is not a deck modified "now".
    $xml = coreXmlOf(deckWithMetadata(['created' => '2020-02-02T03:04:05Z']));

    expect($xml)->toContain('<dcterms:modified xsi:type="dcterms:W3CDTF">2020-02-02T03:04:05Z</dcterms:modified>');
});

it('is byte-stable across calls when the deck supplies its timestamps', function (): void {
    // The property the issue is actually about. Asserted with an explicit
    // timestamp rather than two fast calls -- a test that passes only when both
    // calls land inside the same second goes red on the rare run that straddles
    // a tick, which reads as flakiness and gets retried rather than investigated.
    $deck = deckWithMetadata(['created' => '2020-02-02T03:04:05Z', 'modified' => '2020-02-02T03:04:05Z']);
    $writer = new PptxWriter;

    expect($writer->toBytes($deck))->toBe($writer->toBytes($deck));
});

it('still writes a clock timestamp when the deck supplies none', function (): void {
    // Unchanged behaviour for every existing caller, which is what makes this
    // purely additive. Matched loosely on shape: asserting a value would be
    // asserting the clock.
    $xml = coreXmlOf(deckWithMetadata());

    expect($xml)->toMatch('#<dcterms:created xsi:type="dcterms:W3CDTF">\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z</dcterms:created>#');
    expect($xml)->not->toContain('1980-01-01T00:00:00Z');
});

it('escapes a supplied timestamp instead of pasting it into the XML', function (): void {
    // It is consumer input now, which it was not when it came from gmdate().
    // Python routes it through Xml::text for this reason; an unescaped value
    // would let a deck close the element early and rewrite the rest of the part.
    $xml = coreXmlOf(deckWithMetadata(['created' => '</dcterms:created><evil>&']));

    expect($xml)->not->toContain('<evil>');
    expect($xml)->toContain('&lt;/dcterms:created&gt;&lt;evil&gt;&amp;');
});

it('ignores a non-string or empty timestamp rather than writing it', function (): void {
    foreach ([['created' => ''], ['created' => 12345], ['created' => ['x']], ['created' => null]] as $metadata) {
        $xml = coreXmlOf(deckWithMetadata($metadata));

        expect($xml)->toMatch('#<dcterms:created xsi:type="dcterms:W3CDTF">\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z</dcterms:created>#');
    }
});
