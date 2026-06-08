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
     * @return array<string,mixed>
     */
    public static function apply(array $deck, array $op): array
    {
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
     * Apply a list of ops in order.
     *
     * @param  array<string,mixed>  $deck
     * @param  list<array<string,mixed>>  $ops
     * @return array<string,mixed>
     */
    public static function applyAll(array $deck, array $ops): array
    {
        foreach ($ops as $op) {
            $deck = self::apply($deck, $op);
        }

        return $deck;
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
