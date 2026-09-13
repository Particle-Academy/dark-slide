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
                        'aspectRatio' => ['type' => 'number', 'description' => 'Slide width divided by height, 16/9 by default. 16/9, 16/10 and 4/3 are written as PowerPoint\'s named sizes, anything else as a custom size; the slide is always 10 inches wide.'],
                        'slideWidth' => ['type' => 'number', 'description' => 'Width of the design canvas in pixels, 1920 by default, as fancy-slides uses it. Every length in the deck (fontSize, strokeWidth, padding and the rest) is a pixel on this canvas and keeps its share of the slide width. 1440 reproduces the text sizes of the earlier model, which halved fontSize into points.'],
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
     * Published as a bare `{type: object}` until 0.9.2, so a model filling it in
     * had only the key names, and `fontSize` reads as points: an agent in the
     * fancy-labs document lab set a headline at what it called 232pt, and the
     * deck carried 116pt. 0.10 then gave the whole object ONE unit, the design
     * pixel (see {@see \DarkSlide\Helpers\DesignUnits}), and this says so.
     *
     * Descriptions and permissive types only. The validator does not read this
     * export. `SchemaDescribesStyleUnitsTest` checks every worked example below
     * against what the writer actually produces, and fails if the writer starts
     * reading a style key this does not describe.
     *
     * @return array<string, mixed>
     */
    private static function styleJsonSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'How a text element, or the text inside a shape, looks. Every length here is in DESIGN PIXELS on the deck\'s canvas (theme.slideWidth, 1920 by default), the same unit as fontSize, and keeps its share of the slide width: points = px x 720 / slideWidth. lineHeight is a multiple, not a length.',
            'properties' => [
                'fontSize' => ['type' => 'number', 'description' => 'Type size in DESIGN PIXELS on the theme.slideWidth canvas, as fancy-slides renders it. Written to PPTX as px x 720 / slideWidth points, never below 1pt: on the default 1920 canvas 96 is written as 36pt and 28 as 10.5pt. Default 28.'],
                'fontFamily' => ['type' => 'string', 'description' => 'Typeface name. It renders in that face only where the face is installed, unless the host embeds the font file when writing (the `fonts` write option); anywhere else a substitute face is used, which also changes how wide the text is.'],
                'weight' => ['type' => ['string', 'number'], 'description' => 'Bold when "bold" or "semibold", or a number of 600 or more. PPTX has only bold and regular, so any other weight renders regular.'],
                'italic' => ['type' => 'boolean'],
                'underline' => ['type' => 'boolean'],
                'color' => ['type' => 'string', 'description' => 'Text colour as a hex string. Default "#0F172A".'],
                'align' => ['type' => 'string', 'description' => 'Horizontal alignment: "left" (default), "center", "right" or "justify". Anything else renders left.'],
                'verticalAlign' => ['type' => 'string', 'description' => 'Vertical alignment inside the box: "top" (default), "middle" or "bottom".'],
                'lineHeight' => ['type' => 'number', 'description' => 'Line spacing as a MULTIPLE of the type size, as in CSS: 1.4 is written as 140% line spacing.'],
                'letterSpacing' => ['type' => 'number', 'description' => 'Extra space between letters, in design pixels: on the default canvas 8 is written as 3pt.'],
                'spaceBefore' => ['type' => 'number', 'description' => 'Space above each paragraph, in design pixels: on the default canvas 16 is written as 6pt.'],
                'spaceAfter' => ['type' => 'number', 'description' => 'Space below each paragraph, in design pixels.'],
                'caps' => ['type' => 'string', 'description' => '"small" for small capitals, or "all" (also "upper") for all capitals.'],
                'bullet' => ['type' => ['string', 'boolean'], 'description' => 'Marker for list lines ("- item"): omit for a round bullet, "none" or false for no marker, "number" for 1. 2. 3., or any other string to use it as the marker character.'],
                'fill' => ['type' => ['string', 'boolean'], 'description' => 'Background colour of the box, as a hex string. "none" or false for no fill.'],
                'radius' => ['type' => 'number', 'description' => 'Corner radius of the box, in design pixels.'],
                'border' => [
                    'type' => 'object',
                    'description' => 'An outline on all four sides of the box. PPTX cannot outline a single side; use accentBar for that.',
                    'properties' => [
                        'width' => ['type' => 'number', 'description' => 'Line width in design pixels. Unset gives a 1pt line; 0 draws no line.'],
                        'color' => ['type' => 'string', 'description' => 'Hex colour. Default "#CBD5E1".'],
                        'style' => ['type' => 'string', 'description' => '"solid" (default), or a DrawingML dash name such as "dash" or "sysDot".'],
                    ],
                ],
                'padding' => ['type' => ['number', 'object'], 'description' => 'Inset between the box edge and the text, in design pixels: one number for every side (on the default canvas 32 is written as 12pt), or {left, right, top, bottom}. When unset the insets are PowerPoint\'s own 7.2pt left and right and 3.6pt top and bottom, widened to clear an accent bar.'],
                'accentBar' => [
                    'type' => 'object',
                    'description' => 'A coloured bar down one edge of the box, as on a callout. Painted inside the same shape, so it needs no second element.',
                    'properties' => [
                        'color' => ['type' => 'string', 'description' => 'Hex colour. Default "#8B5CF6".'],
                        'width' => ['type' => 'number', 'description' => 'Bar width in design pixels. Unset gives a 4pt bar.'],
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
                'strokeWidth' => ['type' => 'number', 'description' => 'A shape\'s outline width in design pixels, 2 by default (0.75pt on the default canvas); 0 for no outline.'],
                'dashed' => ['type' => 'boolean'],
                'radius' => ['type' => 'number', 'description' => 'Corner radius of a rounded-rect shape, in design pixels, 8 by default; capped at half the shorter side. A plain rect has square corners.'],
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
