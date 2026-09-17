<?php

declare(strict_types=1);

use DarkSlide\Differ;

/**
 * **Does the diff mention the part that did not change?**
 *
 * A surgical edit cannot. A whole-deck replace must. That is the entire check,
 * and its value is that it holds **no opinion about size** — which is precisely
 * what the guard it replaces got wrong.
 *
 * The consumer who found v0.10.3's regression had a check over the same
 * property: "at most N operations, and fewer bytes than the file". Both
 * assertions were TRUE for every single whole-deck replace v0.10.3 emitted,
 * because `deck.replace` really is one operation and a two-slide deck really
 * does serialise smaller than its own zip. Their suite ran 715 green while
 * every edit silently stored the entire document.
 *
 * So: no threshold. Anything phrased as a bound is eventually satisfied by a
 * replace. This asks whether content the edit did not touch appears in the op
 * stream at all — a question a replace cannot pass however small it is.
 *
 * Ported from that consumer's own suite at their suggestion, because it guards
 * the producer rather than one consumer downstream, and because it is the check
 * that would have caught v0.10.3 here instead of in their house.
 *
 * @see https://github.com/Particle-Academy/dark-slide/issues/9
 */

/** A deck whose every slide carries a phrase unique to it. */
function markedDeck(string $id): array
{
    return [
        'id' => $id,
        'title' => 'Quarterly',
        'theme' => ['name' => 'default'],
        'slides' => [
            ['id' => 's1', 'layout' => 'title', 'elements' => [
                ['id' => 'e1', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2, 'content' => 'MARKER-ALPHA'],
            ]],
            ['id' => 's2', 'elements' => [
                ['id' => 'e2', 'type' => 'text', 'x' => 0.2, 'y' => 0.2, 'w' => 0.3, 'h' => 0.3, 'content' => 'MARKER-BRAVO'],
                ['id' => 'e3', 'type' => 'text', 'x' => 0.2, 'y' => 0.6, 'w' => 0.3, 'h' => 0.3, 'content' => 'MARKER-CHARLIE'],
            ]],
            ['id' => 's3', 'elements' => [
                ['id' => 'e4', 'type' => 'shape', 'x' => 0.4, 'y' => 0.4, 'w' => 0.2, 'h' => 0.2, 'shape' => 'rect', 'content' => 'MARKER-DELTA'],
            ]],
        ],
    ];
}

it('does not mention the parts that did not change', function (string $touch, array $untouched) {
    // Two READS either side of one edit — so the derived ids differ, exactly as
    // read() returns them. That is the case v0.10.3 could not survive.
    $a = markedDeck('content-digest-aaaa');
    $b = markedDeck('content-digest-bbbb');

    // Rewrite exactly one marker.
    $json = json_encode($b);
    $b = json_decode(str_replace($touch, $touch . '-EDITED', $json), true);

    $ops = json_encode(Differ::diff($a, $b));

    foreach ($untouched as $marker) {
        expect($ops)->not->toContain($marker);
    }

    // And it must still say what DID change, or "mentions nothing" would pass.
    expect($ops)->toContain($touch . '-EDITED');
})->with([
    'edit on slide 1' => ['MARKER-ALPHA', ['MARKER-BRAVO', 'MARKER-CHARLIE', 'MARKER-DELTA']],
    'edit on slide 2' => ['MARKER-BRAVO', ['MARKER-ALPHA', 'MARKER-DELTA']],
    'edit on slide 3' => ['MARKER-DELTA', ['MARKER-ALPHA', 'MARKER-BRAVO', 'MARKER-CHARLIE']],
]);

it('does not mention a sibling element on the SAME slide', function () {
    $a = markedDeck('content-digest-aaaa');
    $b = markedDeck('content-digest-bbbb');
    $b['slides'][1]['elements'][0]['content'] = 'MARKER-BRAVO-EDITED';

    $ops = json_encode(Differ::diff($a, $b));

    // e3 shares a slide with the edited e2. A slide-granular op would carry it.
    expect($ops)->not->toContain('MARKER-CHARLIE')
        ->and($ops)->toContain('MARKER-BRAVO-EDITED');
});

/**
 * **The guard proved in the other direction.**
 *
 * A guard nobody has watched fail is not yet a guard. The op stream v0.10.3
 * actually emitted was a single `deck.replace` carrying the whole deck — this
 * asserts that shape DOES trip the check, so a green run above means the differ
 * stayed surgical rather than meaning the assertion is unreachable.
 */
it('WOULD go red on the whole-deck replace that v0.10.3 emitted', function () {
    $b = markedDeck('content-digest-bbbb');
    $b['slides'][0]['elements'][0]['content'] = 'MARKER-ALPHA-EDITED';

    $replace = json_encode([['op' => 'deck.replace', 'deck' => $b]]);

    // Every untouched marker rides along in a replace...
    expect($replace)->toContain('MARKER-BRAVO')
        ->and($replace)->toContain('MARKER-CHARLIE')
        ->and($replace)->toContain('MARKER-DELTA');

    // ...and note it would pass ANY threshold-shaped guard: one operation,
    // and smaller than the .pptx it describes. That is why this one has none.
    expect(json_decode($replace, true))->toHaveCount(1);
});
