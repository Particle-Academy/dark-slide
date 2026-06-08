<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * The JSON Schema of record for a single canonical `DeckOp` — the PHP twin of
 * fancy-slides' `deckOpSchema()`. Ship them byte-for-byte aligned so both sides
 * validate ops against the same contract, and so the op vocabulary can be
 * registered as an LLM tool definition.
 *
 * The nested deck/slide/element/theme payloads are described as generic objects
 * here; their full shapes live in {@see Schema::jsonSchema()} (the deck schema),
 * which the writer/validator already owns. This schema validates the op
 * *envelope*.
 */
final class DeckOpSchema
{
    /** Every canonical op name. The source of truth for the `op` discriminator. */
    public const TYPES = [
        'deck.setTitle',
        'deck.setTheme',
        'deck.replace',
        'slide.add',
        'slide.remove',
        'slide.reorder',
        'slide.setLayout',
        'slide.setNotes',
        'slide.setBackground',
        'slide.setTransition',
        'element.add',
        'element.remove',
        'element.update',
        'element.move',
        'element.resize',
        'element.setAnimation',
    ];

    /**
     * The DeckOp JSON Schema (draft-07), a `oneOf` keyed on the `op`
     * discriminator.
     *
     * @return array<string,mixed>
     */
    public static function jsonSchema(): array
    {
        $slideId = ['type' => 'string', 'description' => 'Target slide id.'];
        $elementId = ['type' => 'string', 'description' => 'Target element id.'];
        $obj = static fn (string $description): array => ['type' => 'object', 'description' => $description];

        $variant = static function (string $op, array $props, array $required) {
            return [
                'type' => 'object',
                'properties' => ['op' => ['const' => $op]] + $props,
                'required' => array_merge(['op'], $required),
                'additionalProperties' => false,
            ];
        };

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => 'https://particle.academy/schema/deck-op.json',
            'title' => 'DeckOp',
            'description' => 'A single canonical operation on a Deck, shared by fancy-slides and dark-slide.',
            'oneOf' => [
                $variant('deck.setTitle', ['title' => ['type' => 'string']], ['title']),
                $variant('deck.setTheme', ['theme' => $obj('Theme object.')], ['theme']),
                $variant('deck.replace', ['deck' => $obj('A full Deck object.')], ['deck']),
                $variant('slide.add', ['index' => ['type' => 'integer', 'minimum' => 0], 'slide' => $obj('A Slide object.')], ['index', 'slide']),
                $variant('slide.remove', ['slideId' => $slideId], ['slideId']),
                $variant('slide.reorder', ['slideId' => $slideId, 'toIndex' => ['type' => 'integer', 'minimum' => 0]], ['slideId', 'toIndex']),
                $variant('slide.setLayout', ['slideId' => $slideId, 'layout' => ['type' => 'string']], ['slideId', 'layout']),
                $variant('slide.setNotes', ['slideId' => $slideId, 'notes' => ['type' => 'string']], ['slideId', 'notes']),
                $variant('slide.setBackground', ['slideId' => $slideId, 'background' => $obj('A SlideBackground object, or null to clear.')], ['slideId']),
                $variant('slide.setTransition', ['slideId' => $slideId, 'transition' => $obj('A SlideTransition object, or null to clear.')], ['slideId']),
                $variant('element.add', ['slideId' => $slideId, 'element' => $obj('A SlideElement object.')], ['slideId', 'element']),
                $variant('element.remove', ['slideId' => $slideId, 'elementId' => $elementId], ['slideId', 'elementId']),
                $variant('element.update', ['slideId' => $slideId, 'elementId' => $elementId, 'patch' => $obj('Partial SlideElement fields to merge.')], ['slideId', 'elementId', 'patch']),
                $variant('element.move', ['slideId' => $slideId, 'elementId' => $elementId, 'x' => ['type' => 'number'], 'y' => ['type' => 'number']], ['slideId', 'elementId', 'x', 'y']),
                $variant('element.resize', ['slideId' => $slideId, 'elementId' => $elementId, 'w' => ['type' => 'number'], 'h' => ['type' => 'number']], ['slideId', 'elementId', 'w', 'h']),
                $variant('element.setAnimation', ['slideId' => $slideId, 'elementId' => $elementId, 'animation' => $obj('An ElementAnimation object, or null to clear.')], ['slideId', 'elementId']),
            ],
        ];
    }
}
