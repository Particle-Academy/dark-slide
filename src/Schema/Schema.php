<?php

declare(strict_types=1);

namespace DarkSlide\Schema;

/**
 * Schema constants describing the Deck shape DarkSlide reads + writes.
 *
 * Mirrors `@particle-academy/fancy-slides`'s `types.ts` — what the JS editor
 * emits round-trips through here byte-identical. Pure data, no runtime
 * dependencies; usable as a JSON Schema export for LLM tool definitions
 * via {@see jsonSchema()}.
 *
 * The deck shape is intentionally JSON-friendly: scalars, arrays, and
 * objects. No PHP class graph. An LLM can emit a deck verbatim and pass it
 * to {@see \DarkSlide\Agent::write()}.
 */
final class Schema
{
    public const VERSION = '0.1.0';

    /**
     * Supported element types.
     *
     * `kpiBand` and `metadataGrid` are COMPOSITES: they expand into a `table`
     * before the writer serialises anything, so they add no OOXML surface and
     * read back as the table they became. See `Table\Composites`.
     */
    public const ELEMENT_TYPES = ['text', 'image', 'chart', 'code', 'table', 'shape', 'embed', 'kpiBand', 'metadataGrid'];

    /** The subset of ELEMENT_TYPES that is sugar over a `table`. */
    public const COMPOSITE_ELEMENT_TYPES = ['kpiBand', 'metadataGrid'];

    /** Layout presets the writer recognises. Unknown layouts fall back to free placement. */
    public const SLIDE_LAYOUTS = [
        'blank',
        'title',
        'title-content',
        'two-column',
        'section-divider',
        'image-text',
        'text-image',
        'quote',
    ];

    /** Shape kinds supported by the writer. */
    public const SHAPE_KINDS = [
        'rect',
        'rounded-rect',
        'ellipse',
        'triangle',
        'line',
        'arrow',
    ];

    /** Text format options. */
    public const TEXT_FORMATS = ['markdown', 'html', 'plain'];

    /** Slide transition kinds the writer recognises. Unknown kinds fall back to none. */
    public const SLIDE_TRANSITION_KINDS = ['none', 'fade', 'slide', 'zoom'];

    /** Directions accepted by directional transitions (slide/push). */
    public const SLIDE_TRANSITION_DIRECTIONS = ['left', 'right', 'up', 'down'];

    /**
     * Element entrance-animation effects the writer maps to OOXML `<p:timing>`
     * entrance behaviors. Mirrors fancy-slides `AnimationEffect`.
     */
    public const ANIMATION_EFFECTS = ['fade', 'fly-in', 'zoom', 'wipe'];

    /** Triggers controlling when a build fires relative to its neighbours. */
    public const ANIMATION_TRIGGERS = ['on-click', 'with-prev', 'after-prev'];

    /** Directions accepted by directional entrance animations (fly-in / wipe). */
    public const ANIMATION_DIRECTIONS = ['left', 'right', 'up', 'down'];

    /** Default entrance-animation duration in milliseconds. */
    public const ANIMATION_DEFAULT_DURATION_MS = 500;

    /** Default slide width in EMU (English Metric Units) for 16:9 at 10". 914400 EMU = 1 inch. */
    public const DEFAULT_SLIDE_WIDTH_EMU = 9144000;
    public const DEFAULT_SLIDE_HEIGHT_EMU = 5143500;

    /** Default theme name (matches fancy-slides default). */
    public const DEFAULT_THEME_NAME = 'default';

    /**
     * Required top-level keys on a Deck.
     *
     * @return array<int, string>
     */
    public static function deckRequiredKeys(): array
    {
        return ['id', 'title', 'slides', 'theme'];
    }

    /**
     * Required keys on a Slide.
     *
     * @return array<int, string>
     */
    public static function slideRequiredKeys(): array
    {
        return ['id', 'elements'];
    }

    /**
     * Required keys on every element.
     *
     * @return array<int, string>
     */
    public static function elementRequiredKeys(): array
    {
        return ['id', 'type', 'x', 'y', 'w', 'h'];
    }

    /**
     * JSON Schema export for LLM tool-use registration. Pass this as the
     * `inputSchema` when registering a deck-write tool with an MCP server
     * or an agent SDK — the agent gets exact field hints.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'DarkSlide Deck',
            'type' => 'object',
            'required' => self::deckRequiredKeys(),
            'properties' => [
                'id' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'theme' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'aspectRatio' => ['type' => 'number'],
                        'slideWidth' => ['type' => 'number'],
                        'colors' => [
                            'type' => 'object',
                            'properties' => [
                                'background' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                                'muted' => ['type' => 'string'],
                                'accent' => ['type' => 'string'],
                                'surface' => ['type' => 'string'],
                            ],
                        ],
                        'fonts' => [
                            'type' => 'object',
                            'properties' => [
                                'heading' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'mono' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'slides' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => self::slideRequiredKeys(),
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'layout' => ['type' => 'string', 'enum' => self::SLIDE_LAYOUTS],
                            'elements' => [
                                'type' => 'array',
                                'items' => self::elementJsonSchema(),
                            ],
                            'background' => [
                                'type' => 'object',
                                'properties' => [
                                    'color' => ['type' => 'string'],
                                    'image' => ['type' => 'string'],
                                    'imageFit' => ['type' => 'string', 'enum' => ['contain', 'cover', 'fill']],
                                    'gradient' => ['type' => 'string'],
                                ],
                            ],
                            'transition' => [
                                'type' => 'object',
                                'properties' => [
                                    'kind' => ['type' => 'string', 'enum' => self::SLIDE_TRANSITION_KINDS],
                                    'duration' => ['type' => 'number'],
                                    'direction' => ['type' => 'string', 'enum' => self::SLIDE_TRANSITION_DIRECTIONS],
                                ],
                            ],
                            'notes' => ['type' => 'string', 'description' => 'Speaker notes (Markdown).'],
                            'narration' => ['type' => 'string', 'description' => 'Plain-text narration script for AI-narrated decks / TTS. Opaque to the writer.'],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                ],
                'metadata' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * The `style` object, with the unit of every field the writer reads.
     *
     * It used to be published as a bare `{type: object}`, so a model filling it
     * in had nothing to go on but the key names, and `fontSize` reads as points.
     * It is design pixels, halved on the way into the file (trap 5 in AGENTS.md):
     * an agent in the fancy-labs document lab set a headline at what it called
     * 232pt, and the deck it wrote carried 116pt. The units in this object are
     * not all the same either, which no key name can tell you.
     *
     * Descriptions and permissive types only. The validator does not read this
     * export, so nothing a deck could do before is refused now.
     * `SchemaDescribesStyleUnitsTest` checks every worked example below against
     * what the writer actually produces, and fails if the writer starts reading
     * a style key this does not describe.
     *
     * @return array<string, mixed>
     */
    private static function styleJsonSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'How a text element, or the text inside a shape, looks. The units are NOT uniform: fontSize is in design pixels and is halved into points, while letterSpacing, spaceBefore, spaceAfter, padding, radius and the border and accent-bar widths are already in points, and lineHeight is a multiple.',
            'properties' => [
                'fontSize' => ['type' => 'number', 'description' => 'Type size in DESIGN PIXELS on the 1920px-wide fancy-slides canvas, NOT points. The PPTX file carries half, with an 8pt minimum: 96 is written as 48pt, 24 as 12pt, and anything under 16 as 8pt. Default 24.'],
                'fontFamily' => ['type' => 'string', 'description' => 'Typeface name. No font is embedded in the file, so where this face is not installed the viewer substitutes another, which also changes how wide the text is.'],
                'weight' => ['type' => ['string', 'number'], 'description' => 'Bold when "bold" or "semibold", or a number of 600 or more. PPTX has only bold and regular, so any other weight renders regular.'],
                'italic' => ['type' => 'boolean'],
                'underline' => ['type' => 'boolean'],
                'color' => ['type' => 'string', 'description' => 'Text colour as a hex string. Default "#0F172A".'],
                'align' => ['type' => 'string', 'description' => 'Horizontal alignment: "left" (default), "center", "right" or "justify". Anything else renders left.'],
                'verticalAlign' => ['type' => 'string', 'description' => 'Vertical alignment inside the box: "top" (default), "middle" or "bottom".'],
                'lineHeight' => ['type' => 'number', 'description' => 'Line spacing as a MULTIPLE of the type size, as in CSS: 1.4 is written as 140% line spacing.'],
                'letterSpacing' => ['type' => 'number', 'description' => 'Extra space between letters, in POINTS, not halved: 2 is written as 2pt.'],
                'spaceBefore' => ['type' => 'number', 'description' => 'Space above each paragraph, in POINTS, not halved: 6 is written as 6pt.'],
                'spaceAfter' => ['type' => 'number', 'description' => 'Space below each paragraph, in POINTS, not halved.'],
                'caps' => ['type' => 'string', 'description' => '"small" for small capitals, or "all" (also "upper") for all capitals.'],
                'bullet' => ['type' => ['string', 'boolean'], 'description' => 'Marker for list lines ("- item"): omit for a round bullet, "none" or false for no marker, "number" for 1. 2. 3., or any other string to use it as the marker character.'],
                'fill' => ['type' => ['string', 'boolean'], 'description' => 'Background colour of the box, as a hex string. "none" or false for no fill.'],
                'radius' => ['type' => 'number', 'description' => 'Corner radius of the box, in POINTS.'],
                'border' => [
                    'type' => 'object',
                    'description' => 'An outline on all four sides of the box. PPTX cannot outline a single side; use accentBar for that.',
                    'properties' => [
                        'width' => ['type' => 'number', 'description' => 'Line width in POINTS. Default 1; 0 draws no line.'],
                        'color' => ['type' => 'string', 'description' => 'Hex colour. Default "#CBD5E1".'],
                        'style' => ['type' => 'string', 'description' => '"solid" (default), or a DrawingML dash name such as "dash" or "sysDot".'],
                    ],
                ],
                'padding' => ['type' => ['number', 'object'], 'description' => 'Inset between the box edge and the text, in POINTS: one number for every side (12 is written as 12pt), or {left, right, top, bottom}. When unset the insets are 7.2 left and right and 3.6 top and bottom, widened to clear an accent bar.'],
                'accentBar' => [
                    'type' => 'object',
                    'description' => 'A coloured bar down one edge of the box, as on a callout. Painted inside the same shape, so it needs no second element.',
                    'properties' => [
                        'color' => ['type' => 'string', 'description' => 'Hex colour. Default "#8B5CF6".'],
                        'width' => ['type' => 'number', 'description' => 'Bar width in POINTS. Default 4.'],
                        'side' => ['type' => 'string', 'description' => '"left" (default) or "right".'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function elementJsonSchema(): array
    {
        return [
            'type' => 'object',
            'required' => self::elementRequiredKeys(),
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => self::ELEMENT_TYPES],
                'x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Left edge as a FRACTION of the slide width: 0 is the left edge, 0.5 the centre, 1 the right edge. Not pixels.'],
                'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Top edge as a FRACTION of the slide height: 0 is the top, 1 the bottom. Not pixels.'],
                'w' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Width as a FRACTION of the slide width. 1 spans the whole slide.'],
                'h' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Height as a FRACTION of the slide height. 1 spans the whole slide.'],
                'rotation' => ['type' => 'number'],
                'z' => ['type' => 'integer'],
                'locked' => ['type' => 'boolean'],
                'hidden' => ['type' => 'boolean'],
                // Whole-element hyperlink — emitted as an <a:hlinkClick> on the
                // shape / picture. Mirrors fancy-slides ElementBase.href.
                'href' => ['type' => 'string'],
                // Type-specific fields — kept loose since this is a union.
                'content' => ['type' => 'string'],
                'format' => ['type' => 'string', 'enum' => self::TEXT_FORMATS],
                'style' => self::styleJsonSchema(),
                'src' => ['type' => 'string'],
                'alt' => ['type' => 'string'],
                'fit' => ['type' => 'string', 'enum' => ['contain', 'cover', 'fill', 'scale-down']],
                'shape' => ['type' => 'string', 'enum' => self::SHAPE_KINDS],
                'fill' => ['type' => 'string'],
                'stroke' => ['type' => 'string'],
                'strokeWidth' => ['type' => 'number'],
                'dashed' => ['type' => 'boolean'],
                'radius' => ['type' => 'number'],
                'code' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'codeTheme' => ['type' => 'string'],
                'columns' => ['type' => 'array'],
                'rows' => ['type' => 'array'],
                'option' => ['type' => 'object'],
                'chartTheme' => ['type' => 'string'],
                // Optional entrance build animation. When present the element
                // participates in the slide's build sequence and the writer
                // emits a matching `<p:timing>` entrance behavior.
                'animation' => [
                    'type' => 'object',
                    'required' => ['effect'],
                    'properties' => [
                        'effect' => ['type' => 'string', 'enum' => self::ANIMATION_EFFECTS],
                        'trigger' => ['type' => 'string', 'enum' => self::ANIMATION_TRIGGERS],
                        'direction' => ['type' => 'string', 'enum' => self::ANIMATION_DIRECTIONS],
                        'duration' => ['type' => 'number'],
                        'delay' => ['type' => 'number'],
                        'order' => ['type' => 'number'],
                        // Text elements only: animate each paragraph (line) of
                        // the content separately (PowerPoint "By paragraph").
                        // The first paragraph uses `trigger`; every later
                        // paragraph becomes its own on-click step.
                        'byParagraph' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }
}
