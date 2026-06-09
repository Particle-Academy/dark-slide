<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * The server-authoritative deck reducer — the PHP twin of fancy-slides'
 * `reduce()` / `reduceDeck`.
 *
 * Applies a single canonical `DeckOp` (the vocabulary shared with
 * `@particle-academy/fancy-slides` — see {@see DeckOpSchema}) to a deck array
 * and returns a NEW deck. Never mutates the input. A consumer running an
 * authoritative server-side deck routes `<DeckEditor>`'s `onOp` straight through
 * this, so direct human edits and agent ops broadcast/replay the same way — no
 * second reducer in a third vocabulary.
 *
 * Behavioural parity with the JS reducer is the contract; the round-trip
 * property `apply` ⇄ {@see Agent::diff()} (`reduce($a, diff($a,$b)) === $b`)
 * relies on it.
 */
final class Reducer
{
    /**
     * @param  array<string,mixed>  $deck
     * @param  array<string,mixed>  $op    A DeckOp keyed by an `op` discriminator.
     * @param  array{onMissing?: 'skip'|'throw'}  $opts  `onMissing` (default `'skip'`):
     *   `'skip'` returns the deck unchanged when the op targets a missing
     *   slide/element (resilient broadcast replay); `'throw'` raises an
     *   {@see \InvalidArgumentException} naming the missing id (agent-facing
     *   paths, where a silent no-op masks a typo). See {@see strictApply()}.
     * @return array<string,mixed>
     */
    public static function apply(array $deck, array $op, array $opts = []): array
    {
        if (($opts['onMissing'] ?? 'skip') === 'throw') {
            self::assertTargetsExist($deck, $op);
        }

        $type = $op['op'] ?? null;

        switch ($type) {
            case 'deck.setTitle':
                $deck['title'] = $op['title'];

                return $deck;

            case 'deck.setTheme':
                $deck['theme'] = $op['theme'];

                return $deck;

            case 'deck.replace':
                return $op['deck'];

            case 'slide.add':
                $slides = $deck['slides'] ?? [];
                $index = max(0, min(count($slides), (int) ($op['index'] ?? count($slides))));
                array_splice($slides, $index, 0, [$op['slide']]);
                $deck['slides'] = $slides;

                return $deck;

            case 'slide.remove':
                $deck['slides'] = array_values(array_filter(
                    $deck['slides'] ?? [],
                    static fn (array $s): bool => ($s['id'] ?? null) !== $op['slideId'],
                ));

                return $deck;

            case 'slide.reorder':
                $slides = $deck['slides'] ?? [];
                $idx = self::indexOfSlide($slides, (string) $op['slideId']);
                if ($idx < 0) {
                    return $deck;
                }
                $moved = array_splice($slides, $idx, 1);
                $to = max(0, min(count($slides), (int) $op['toIndex']));
                array_splice($slides, $to, 0, $moved);
                $deck['slides'] = $slides;

                return $deck;

            case 'slide.setLayout':
                return self::mapSlide($deck, (string) $op['slideId'], static function (array $s) use ($op): array {
                    $s['layout'] = $op['layout'];

                    return $s;
                });

            case 'slide.setNotes':
                return self::mapSlide($deck, (string) $op['slideId'], static function (array $s) use ($op): array {
                    $s['notes'] = $op['notes'];

                    return $s;
                });

            case 'slide.setBackground':
                return self::mapSlide($deck, (string) $op['slideId'], static fn (array $s): array => self::setOrClear($s, 'background', $op['background'] ?? null));

            case 'slide.setTransition':
                return self::mapSlide($deck, (string) $op['slideId'], static fn (array $s): array => self::setOrClear($s, 'transition', $op['transition'] ?? null));

            case 'element.add':
                return self::mapSlide($deck, (string) $op['slideId'], static function (array $s) use ($op): array {
                    $elements = $s['elements'] ?? [];
                    $elements[] = $op['element'];
                    $s['elements'] = array_values($elements);

                    return $s;
                });

            case 'element.remove':
                return self::mapSlide($deck, (string) $op['slideId'], static function (array $s) use ($op): array {
                    $s['elements'] = array_values(array_filter(
                        $s['elements'] ?? [],
                        static fn (array $e): bool => ($e['id'] ?? null) !== $op['elementId'],
                    ));

                    return $s;
                });

            case 'element.update':
                return self::mapElement($deck, (string) $op['slideId'], (string) $op['elementId'], static fn (array $e): array => array_merge($e, $op['patch'] ?? []));

            case 'element.move':
                return self::mapElement($deck, (string) $op['slideId'], (string) $op['elementId'], static function (array $e) use ($op): array {
                    $e['x'] = $op['x'];
                    $e['y'] = $op['y'];

                    return $e;
                });

            case 'element.resize':
                return self::mapElement($deck, (string) $op['slideId'], (string) $op['elementId'], static function (array $e) use ($op): array {
                    $e['w'] = $op['w'];
                    $e['h'] = $op['h'];

                    return $e;
                });

            case 'element.setAnimation':
                return self::mapElement($deck, (string) $op['slideId'], (string) $op['elementId'], static fn (array $e): array => self::setOrClear($e, 'animation', $op['animation'] ?? null));

            default:
                return $deck;
        }
    }

    /**
     * Strict apply — throws {@see \InvalidArgumentException} naming the missing
     * slide/element when an op targets one that isn't on the deck, instead of
     * silently returning the deck unchanged. The signal an agent acts on.
     *
     * Sugar for `apply($deck, $op, ['onMissing' => 'throw'])`. The JS twin is
     * `reduce(deck, op, { onMissing: 'throw' })` in `@particle-academy/fancy-slides`.
     *
     * @param  array<string,mixed>  $deck
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    public static function strictApply(array $deck, array $op): array
    {
        return self::apply($deck, $op, ['onMissing' => 'throw']);
    }

    /**
     * Apply a list of ops in order.
     *
     * @param  array<string,mixed>  $deck
     * @param  list<array<string,mixed>>  $ops
     * @param  array{onMissing?: 'skip'|'throw'}  $opts  Forwarded to {@see apply()}.
     * @return array<string,mixed>
     */
    public static function applyAll(array $deck, array $ops, array $opts = []): array
    {
        foreach ($ops as $op) {
            $deck = self::apply($deck, $op, $opts);
        }

        return $deck;
    }

    /**
     * Assert the op's target slide (and element, when addressed) exist on the
     * deck. Deck-level ops and `slide.add` address no existing target, so they
     * always pass. Used by the strict path; ids are stringified for the message.
     *
     * @param  array<string,mixed>  $deck
     * @param  array<string,mixed>  $op
     */
    private static function assertTargetsExist(array $deck, array $op): void
    {
        if (! isset($op['slideId'])) {
            return;
        }
        $slideId = (string) $op['slideId'];

        $slide = null;
        foreach ($deck['slides'] ?? [] as $s) {
            if ((string) ($s['id'] ?? '') === $slideId) {
                $slide = $s;
                break;
            }
        }
        if ($slide === null) {
            throw new \InvalidArgumentException("No slide '{$slideId}' in the deck.");
        }

        if (! isset($op['elementId'])) {
            return;
        }
        $elementId = (string) $op['elementId'];
        foreach ($slide['elements'] ?? [] as $e) {
            if ((string) ($e['id'] ?? '') === $elementId) {
                return;
            }
        }
        throw new \InvalidArgumentException("No element '{$elementId}' on slide '{$slideId}'.");
    }

    /**
     * Set a key to a value, or unset it when the value is null — mirroring the
     * JS reducer, where clearing a background/transition/animation drops the key
     * (so it inherits / leaves the build sequence) rather than storing null.
     *
     * @param  array<string,mixed>  $arr
     * @return array<string,mixed>
     */
    private static function setOrClear(array $arr, string $key, mixed $value): array
    {
        if ($value === null) {
            unset($arr[$key]);

            return $arr;
        }
        $arr[$key] = $value;

        return $arr;
    }

    /**
     * @param  list<array<string,mixed>>  $slides
     */
    private static function indexOfSlide(array $slides, string $slideId): int
    {
        foreach ($slides as $i => $s) {
            if (($s['id'] ?? null) === $slideId) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Replace the slide with the given id via $fn, returning a new deck.
     *
     * @param  array<string,mixed>  $deck
     * @param  callable(array<string,mixed>):array<string,mixed>  $fn
     * @return array<string,mixed>
     */
    private static function mapSlide(array $deck, string $slideId, callable $fn): array
    {
        $deck['slides'] = array_map(
            static fn (array $s): array => ($s['id'] ?? null) === $slideId ? $fn($s) : $s,
            $deck['slides'] ?? [],
        );

        return $deck;
    }

    /**
     * Replace the element with the given id on the given slide via $fn.
     *
     * @param  array<string,mixed>  $deck
     * @param  callable(array<string,mixed>):array<string,mixed>  $fn
     * @return array<string,mixed>
     */
    private static function mapElement(array $deck, string $slideId, string $elementId, callable $fn): array
    {
        return self::mapSlide($deck, $slideId, static function (array $s) use ($elementId, $fn): array {
            $s['elements'] = array_map(
                static fn (array $e): array => ($e['id'] ?? null) === $elementId ? $fn($e) : $e,
                $s['elements'] ?? [],
            );

            return $s;
        });
    }
}
