<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\DeckOpSchema;
use DarkSlide\Differ;
use DarkSlide\Reducer;

/** A small deck fixture. */
function deckFixture(): array
{
    return [
        'id' => 'd1',
        'title' => 'Quarterly',
        'theme' => ['name' => 'default'],
        'slides' => [
            ['id' => 's1', 'layout' => 'title', 'elements' => [
                ['id' => 'e1', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2, 'content' => 'Hello'],
            ]],
            ['id' => 's2', 'elements' => [
                ['id' => 'e2', 'type' => 'shape', 'x' => 0.2, 'y' => 0.2, 'w' => 0.3, 'h' => 0.3, 'shape' => 'rect'],
            ]],
        ],
    ];
}

it('applies element.move / resize / update', function () {
    $deck = deckFixture();

    $moved = Reducer::apply($deck, ['op' => 'element.move', 'slideId' => 's1', 'elementId' => 'e1', 'x' => 0.5, 'y' => 0.6]);
    expect($moved['slides'][0]['elements'][0])->toMatchArray(['x' => 0.5, 'y' => 0.6]);

    $resized = Reducer::apply($deck, ['op' => 'element.resize', 'slideId' => 's2', 'elementId' => 'e2', 'w' => 0.9, 'h' => 0.4]);
    expect($resized['slides'][1]['elements'][0])->toMatchArray(['w' => 0.9, 'h' => 0.4]);

    $updated = Reducer::apply($deck, ['op' => 'element.update', 'slideId' => 's1', 'elementId' => 'e1', 'patch' => ['content' => 'Bye', 'rotation' => 5]]);
    expect($updated['slides'][0]['elements'][0])->toMatchArray(['content' => 'Bye', 'rotation' => 5, 'type' => 'text']);

    // input untouched
    expect($deck['slides'][0]['elements'][0]['x'])->toBe(0.1);
});

it('adds, removes, and reorders slides', function () {
    $deck = deckFixture();

    $added = Reducer::apply($deck, ['op' => 'slide.add', 'index' => 1, 'slide' => ['id' => 's3', 'elements' => []]]);
    expect(array_column($added['slides'], 'id'))->toBe(['s1', 's3', 's2']);

    $removed = Reducer::apply($deck, ['op' => 'slide.remove', 'slideId' => 's1']);
    expect(array_column($removed['slides'], 'id'))->toBe(['s2']);

    $reordered = Reducer::apply($deck, ['op' => 'slide.reorder', 'slideId' => 's2', 'toIndex' => 0]);
    expect(array_column($reordered['slides'], 'id'))->toBe(['s2', 's1']);
});

it('clears background / animation by dropping the key (not storing null)', function () {
    $deck = deckFixture();
    $deck['slides'][0]['background'] = ['color' => '#fff'];

    $cleared = Reducer::apply($deck, ['op' => 'slide.setBackground', 'slideId' => 's1']);
    expect($cleared['slides'][0])->not->toHaveKey('background');

    $set = Reducer::apply($deck, ['op' => 'slide.setBackground', 'slideId' => 's1', 'background' => ['color' => '#000']]);
    expect($set['slides'][0]['background'])->toBe(['color' => '#000']);
});

it('satisfies the round-trip property: reduce(a, diff(a,b)) == b', function (array $b) {
    $a = deckFixture();
    $ops = Differ::diff($a, $b);
    $result = Reducer::applyAll($a, $ops);

    // key-order-independent equality
    $canon = fn ($v) => json_encode($v); // arrays compared structurally below
    expect(normalizeDeck($result))->toEqual(normalizeDeck($b));
})->with([
    'title change' => fn () => ['id' => 'd1', 'title' => 'NEW', 'theme' => ['name' => 'default'], 'slides' => deckFixture()['slides']],
    'theme change' => fn () => array_merge(deckFixture(), ['theme' => ['name' => 'dark']]),
    'element moved' => function () {
        $d = deckFixture();
        $d['slides'][0]['elements'][0]['x'] = 0.42;

        return $d;
    },
    'element added' => function () {
        $d = deckFixture();
        $d['slides'][1]['elements'][] = ['id' => 'e9', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.1, 'content' => 'new'];

        return $d;
    },
    'slide added' => function () {
        $d = deckFixture();
        array_splice($d['slides'], 1, 0, [['id' => 's5', 'elements' => []]]);

        return $d;
    },
    'slide removed' => function () {
        $d = deckFixture();
        array_splice($d['slides'], 0, 1);

        return $d;
    },
    'slides reordered' => function () {
        $d = deckFixture();
        $d['slides'] = array_reverse($d['slides']);

        return $d;
    },
    'wholesale replace' => fn () => ['id' => 'd1', 'title' => 'X', 'theme' => ['name' => 'vivid'], 'slides' => [['id' => 'z1', 'elements' => []]]],
]);

it('exposes the op schema with all 16 variants, aligned with Agent::opSchema', function () {
    $schema = DeckOpSchema::jsonSchema();
    expect($schema['oneOf'])->toHaveCount(16)
        ->and(DeckOpSchema::TYPES)->toHaveCount(16)
        ->and(Agent::opSchema())->toBe($schema);
});

it('Agent::reduce + Agent::diff delegate correctly', function () {
    $a = deckFixture();
    $b = array_merge($a, ['title' => 'Renamed']);

    $ops = Agent::diff($a, $b);
    expect(normalizeDeck(Agent::reduce($a, $ops)))->toEqual(normalizeDeck($b));

    // single-op form
    $one = Agent::reduce($a, ['op' => 'deck.setTitle', 'title' => 'Solo']);
    expect($one['title'])->toBe('Solo');
});

it('strictApply throws naming a missing slide target', function () {
    $deck = deckFixture();
    expect(fn () => Reducer::strictApply($deck, ['op' => 'element.update', 'slideId' => 'oops', 'elementId' => 'e1', 'patch' => ['content' => 'x']]))
        ->toThrow(InvalidArgumentException::class, "No slide 'oops' in the deck.");
});

it('strictApply throws naming a missing element target', function () {
    $deck = deckFixture();
    expect(fn () => Reducer::strictApply($deck, ['op' => 'element.move', 'slideId' => 's1', 'elementId' => 'ghost', 'x' => 0.1, 'y' => 0.1]))
        ->toThrow(InvalidArgumentException::class, "No element 'ghost' on slide 's1'.");
});

it('strictApply mutates normally when targets exist', function () {
    $out = Reducer::strictApply(deckFixture(), ['op' => 'element.update', 'slideId' => 's1', 'elementId' => 'e1', 'patch' => ['content' => 'Hi']]);
    expect($out['slides'][0]['elements'][0]['content'])->toBe('Hi');
});

it('default apply silently skips a missing target (backward compatible)', function () {
    $deck = deckFixture();
    expect(normalizeDeck(Reducer::apply($deck, ['op' => 'slide.remove', 'slideId' => 'nope'])))
        ->toEqual(normalizeDeck($deck));
    expect(normalizeDeck(Reducer::apply($deck, ['op' => 'element.move', 'slideId' => 'nope', 'elementId' => 'x', 'x' => 1, 'y' => 1], ['onMissing' => 'skip'])))
        ->toEqual(normalizeDeck($deck));
});

it('strict mode never throws for deck-level ops or slide.add', function () {
    $deck = deckFixture();
    expect(Reducer::strictApply($deck, ['op' => 'deck.setTitle', 'title' => 'T'])['title'])->toBe('T');
    $added = Reducer::strictApply($deck, ['op' => 'slide.add', 'index' => 0, 'slide' => ['id' => 's9', 'elements' => []]]);
    expect(array_column($added['slides'], 'id'))->toBe(['s9', 's1', 's2']);
});

/** Recursively ksort assoc arrays so equality ignores key order. */
function normalizeDeck(mixed $v): mixed
{
    if (is_array($v)) {
        $isList = array_is_list($v);
        $v = array_map('normalizeDeck', $v);
        if (! $isList) {
            ksort($v);
        }
    }

    return $v;
}
