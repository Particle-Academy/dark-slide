<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * Produce the op list that transforms deck `$a` into deck `$b`, in the canonical
 * {@see DeckOpSchema} vocabulary — so the result broadcasts, replays, and audits
 * like any other op stream.
 *
 * Correctness over cleverness: a granular element-keyed diff is attempted first
 * (per-slide field changes, element add/remove/update, slide add/remove/reorder),
 * then the result is **verified** by replaying it through {@see Reducer}. If the
 * granular ops don't reproduce `$b` exactly (the hard cases — e.g. a removed
 * element field, an indistinguishable reorder), it falls back to a single
 * `deck.replace`. Either way the round-trip property holds:
 * `Reducer::applyAll($a, Differ::diff($a, $b)) == $b`.
 */
final class Differ
{
    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    public static function diff(array $a, array $b): array
    {
        $ops = self::granular($a, $b);

        // Verify, then fix slide order, then verify again; fall back to replace.
        $after = Reducer::applyAll($a, $ops);
        $after = self::fixOrder($after, $b, $ops);

        if (self::canon(Reducer::applyAll($a, $ops)) !== self::canon($b)) {
            return [['op' => 'deck.replace', 'deck' => $b]];
        }

        return $ops;
    }

    /**
     * The granular op list (pre-verification).
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    private static function granular(array $a, array $b): array
    {
        $ops = [];

        if (($a['title'] ?? null) !== ($b['title'] ?? null)) {
            $ops[] = ['op' => 'deck.setTitle', 'title' => $b['title'] ?? ''];
        }
        if (self::canon($a['theme'] ?? null) !== self::canon($b['theme'] ?? null)) {
            $ops[] = ['op' => 'deck.setTheme', 'theme' => $b['theme'] ?? null];
        }

        $aSlides = $a['slides'] ?? [];
        $bSlides = $b['slides'] ?? [];
        $aById = self::byId($aSlides);
        $bById = self::byId($bSlides);

        foreach ($aSlides as $s) {
            $id = $s['id'] ?? null;
            if ($id !== null && ! isset($bById[$id])) {
                $ops[] = ['op' => 'slide.remove', 'slideId' => $id];
            }
        }
        foreach ($bSlides as $i => $s) {
            $id = $s['id'] ?? null;
            if ($id !== null && ! isset($aById[$id])) {
                $ops[] = ['op' => 'slide.add', 'index' => $i, 'slide' => $s];
            }
        }
        foreach ($bSlides as $s) {
            $id = $s['id'] ?? null;
            if ($id === null || ! isset($aById[$id])) {
                continue;
            }
            foreach (self::diffSlide($aById[$id], $s) as $op) {
                $ops[] = $op;
            }
        }

        return $ops;
    }

    /**
     * Append `slide.reorder` ops until the (already field/element-corrected) deck
     * matches `$b`'s slide order. Mutates $ops by reference.
     *
     * @param  array<string,mixed>  $deck   the deck after $ops so far
     * @param  array<string,mixed>  $b
     * @param  list<array<string,mixed>>  $ops
     * @return array<string,mixed>
     */
    private static function fixOrder(array $deck, array $b, array &$ops): array
    {
        $targetIds = array_map(static fn (array $s): mixed => $s['id'] ?? null, $b['slides'] ?? []);
        foreach ($targetIds as $i => $wantId) {
            $current = array_map(static fn (array $s): mixed => $s['id'] ?? null, $deck['slides'] ?? []);
            if (($current[$i] ?? null) === $wantId) {
                continue;
            }
            $op = ['op' => 'slide.reorder', 'slideId' => $wantId, 'toIndex' => $i];
            $ops[] = $op;
            $deck = Reducer::apply($deck, $op);
        }

        return $deck;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    private static function diffSlide(array $a, array $b): array
    {
        $ops = [];
        $id = $b['id'];

        if (($a['layout'] ?? null) !== ($b['layout'] ?? null) && isset($b['layout'])) {
            $ops[] = ['op' => 'slide.setLayout', 'slideId' => $id, 'layout' => $b['layout']];
        }
        if (($a['notes'] ?? null) !== ($b['notes'] ?? null) && isset($b['notes'])) {
            $ops[] = ['op' => 'slide.setNotes', 'slideId' => $id, 'notes' => $b['notes']];
        }
        if (self::canon($a['background'] ?? null) !== self::canon($b['background'] ?? null)) {
            $ops[] = ['op' => 'slide.setBackground', 'slideId' => $id, 'background' => $b['background'] ?? null];
        }
        if (self::canon($a['transition'] ?? null) !== self::canon($b['transition'] ?? null)) {
            $ops[] = ['op' => 'slide.setTransition', 'slideId' => $id, 'transition' => $b['transition'] ?? null];
        }

        $aEls = self::byId($a['elements'] ?? []);
        $bEls = self::byId($b['elements'] ?? []);
        foreach ($a['elements'] ?? [] as $e) {
            $eid = $e['id'] ?? null;
            if ($eid !== null && ! isset($bEls[$eid])) {
                $ops[] = ['op' => 'element.remove', 'slideId' => $id, 'elementId' => $eid];
            }
        }
        foreach ($b['elements'] ?? [] as $e) {
            $eid = $e['id'] ?? null;
            if ($eid === null) {
                continue;
            }
            if (! isset($aEls[$eid])) {
                $ops[] = ['op' => 'element.add', 'slideId' => $id, 'element' => $e];
            } elseif (self::canon($aEls[$eid]) !== self::canon($e)) {
                $ops[] = ['op' => 'element.update', 'slideId' => $id, 'elementId' => $eid, 'patch' => $e];
            }
        }

        return $ops;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,array<string,mixed>>
     */
    private static function byId(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (isset($it['id'])) {
                $out[(string) $it['id']] = $it;
            }
        }

        return $out;
    }

    /** Canonical, key-order-independent JSON of a value, for equality checks. */
    private static function canon(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (is_array($v)) {
                $isList = array_is_list($v);
                $v = array_map($sort, $v);
                if (! $isList) {
                    ksort($v);
                }
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
    }
}
