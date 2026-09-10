<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Writer\PptxWriter;

/**
 * Does a deck arrive wearing the BRAND it was given?
 *
 * ## Why this is not `ReferenceDeckTest`
 *
 * That suite asks whether the constructs of a real business document survive —
 * callouts, KPI bands, zebra-striped tables — and it asks it well. It says
 * nothing about identity: every one of its assertions passes just as happily on
 * a deck rendered in stock Office Calibri and Office purple.
 *
 * Identity is the difference between a deck someone sends to a client and a
 * deck that looks like a template nobody edited, and it is carried by three
 * things — colours, fonts, and the details a reader notices without naming
 * (speaker notes that survive, code that reads as code).
 *
 * **The theme is the part most likely to be silently ignored**, because a deck
 * with the wrong fonts is not broken. It opens, it renders, every shape is in
 * the right place, and it is simply not yours. Nobody files that as a bug.
 *
 * `theme.fonts.mono` was exactly that: accepted by the validator, published in
 * `Schema::jsonSchema()` as part of the LLM tool definition, named in the
 * writer's own docblocks as the font code runs switch to — and applied nowhere.
 * Every deck got Consolas. See the mono tests below.
 */

/** A deck with a complete brand identity: colours, all three fonts, notes, code. */
function brandDeck(): array
{
    return [
        'id' => 'brand-deck',
        'title' => 'Brand Identity',
        'theme' => [
            'name' => 'brand',
            'colors' => [
                'background' => '#101828',
                'text' => '#F9FAFB',
                'muted' => '#98A2B3',
                'accent' => '#F97316',
                'surface' => '#1D2939',
            ],
            'fonts' => [
                'heading' => 'Playfair Display',
                'body' => 'Inter',
                'mono' => 'JetBrains Mono',
            ],
        ],
        'slides' => [
            [
                'id' => 's1',
                'layout' => 'title',
                'notes' => 'Open on the revenue number, not the agenda.',
                'elements' => [
                    ['id' => 'e1', 'type' => 'text', 'x' => 100, 'y' => 200, 'w' => 800, 'h' => 120,
                        'content' => 'Brand Identity'],
                    // `format` must be markdown for the backticks to become an
                    // inline code run rather than literal backtick characters.
                    ['id' => 'e2', 'type' => 'text', 'x' => 100, 'y' => 340, 'w' => 800, 'h' => 60,
                        'format' => 'markdown',
                        'content' => 'Configure it with `theme.fonts.mono` today.'],
                ],
            ],
            [
                'id' => 's2',
                'layout' => 'title-content',
                'elements' => [
                    ['id' => 'e3', 'type' => 'code', 'x' => 100, 'y' => 120, 'w' => 800, 'h' => 300,
                        'code' => "const deck = { theme };\nexport default deck;", 'language' => 'javascript'],
                ],
            ],
        ],
    ];
}

/** @return array<string,string> every part of a rendered deck, keyed by path */
function deckParts(array $deck): array
{
    $path = tempnam(sys_get_temp_dir(), 'premium').'.pptx';

    try {
        Agent::write($deck, $path);

        $zip = new ZipArchive();
        expect($zip->open($path))->toBeTrue();
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromName($name);
        }
        $zip->close();

        return $parts;
    } finally {
        @unlink($path);
    }
}

describe('the deck wears the brand it was given', function () {
    it('puts the heading and body fonts in the theme font scheme', function () {
        // majorFont drives headings, minorFont body text. Excel and PowerPoint
        // both resolve every un-overridden run through these, so getting them
        // wrong restyles the entire deck at once.
        $theme = deckParts(brandDeck())['ppt/theme/theme1.xml'];

        expect($theme)->toContain('<a:majorFont><a:latin typeface="Playfair Display"/>');
        expect($theme)->toContain('<a:minorFont><a:latin typeface="Inter"/>');
    });

    it('maps every one of the five theme colours into the colour scheme', function () {
        // All five together, because they land in different slots (`muted` in
        // dk2, `surface` in lt2) and a mapping that drops one is invisible: the
        // deck simply renders that element in a stock Office colour.
        $theme = deckParts(brandDeck())['ppt/theme/theme1.xml'];

        $slots = [
            'text -> dk1' => '<a:dk1><a:srgbClr val="F9FAFB"/></a:dk1>',
            'background -> lt1' => '<a:lt1><a:srgbClr val="101828"/></a:lt1>',
            'muted -> dk2' => '<a:dk2><a:srgbClr val="98A2B3"/></a:dk2>',
            'surface -> lt2' => '<a:lt2><a:srgbClr val="1D2939"/></a:lt2>',
            'accent -> accent1' => '<a:accent1><a:srgbClr val="F97316"/></a:accent1>',
        ];

        $missing = [];
        foreach ($slots as $label => $needle) {
            if (! str_contains($theme, $needle)) {
                $missing[] = $label;
            }
        }

        expect($missing, 'theme colours that never reached the colour scheme: '.implode(', ', $missing))
            ->toBe([]);
    });

    it('keeps the speaker notes, on the slide they belong to', function () {
        // Notes are what a presenter actually stands up with. They live in a
        // separate part with its own relationship, so they are easy to write
        // and easy to orphan.
        $parts = deckParts(brandDeck());

        expect($parts)->toHaveKey('ppt/notesSlides/notesSlide1.xml');
        expect($parts['ppt/notesSlides/notesSlide1.xml'])->toContain('Open on the revenue number');
    });
});

describe('theme.fonts.mono actually reaches the code', function () {
    // ## The defect these pin
    //
    // `theme.fonts.mono` was accepted by the validator, published in the JSON
    // Schema handed to an LLM as the tool definition, and described in
    // `PptxWriter`'s docblocks as the font code runs switch to. Both places that
    // render code hardcoded `Consolas`, so it was wired to nothing.
    //
    // A brand deck asking for JetBrains Mono got Consolas, rendered fine, and
    // was quietly off-brand — the failure nobody reports.

    it('sets the deck mono font on a CODE BLOCK element', function () {
        $slide = deckParts(brandDeck())['ppt/slides/slide2.xml'];

        expect($slide)->toContain('<a:latin typeface="JetBrains Mono"/>');
        expect($slide)->not->toContain('Consolas');
    });

    it('sets the deck mono font on an INLINE `code` span', function () {
        // The two render through different code paths, and both hardcoded the
        // same font, so fixing one and calling it done was the likely outcome.
        $slide = deckParts(brandDeck())['ppt/slides/slide1.xml'];

        expect($slide)->toContain('<a:latin typeface="JetBrains Mono"/>');
        expect($slide)->not->toContain('Consolas');
    });

    it('still defaults to Consolas when the deck names no mono font', function () {
        // The compatibility half. Decks that never set `fonts.mono` — which is
        // all of them until now — must render exactly as they did before.
        $deck = brandDeck();
        unset($deck['theme']['fonts']['mono']);

        expect(deckParts($deck)['ppt/slides/slide2.xml'])->toContain('<a:latin typeface="Consolas"/>');
    });

    it('records the mono font in the theme so a READER can find it again', function () {
        // `<a:fontScheme>` has two slots, major and minor. There is nowhere in a
        // standard theme for a third font, so this rides in `<a:extLst>` — the
        // element consumers ignore when they do not know the uri.
        $theme = deckParts(brandDeck())['ppt/theme/theme1.xml'];

        expect($theme)->toContain(PptxWriter::MONO_FONT_EXT_URI);
        expect($theme)->toContain('typeface="JetBrains Mono"');
    });

    it('reads a code run BACK as code, with a brand font the sniff cannot spot', function () {
        // The round-trip this fix would otherwise have broken.
        //
        // The reader identified code runs by looking for "consola", "mono" or
        // "courier" in the typeface name. That was sound while the writer always
        // emitted Consolas. "Fira Code" contains none of those words, so a deck
        // naming one would have round-tripped its code back as ordinary prose —
        // a silent downgrade on a file that opens perfectly.
        //
        // On the read side a `code` element is a documented one-way loss: it
        // returns as a text element carrying an inline-code span, the same way a
        // `kpiBand` returns as the table it became. The assertion is that the
        // code was RECOGNISED — the backticks are the evidence.
        $deck = brandDeck();
        $deck['theme']['fonts']['mono'] = 'Fira Code';

        $path = tempnam(sys_get_temp_dir(), 'premium-rt').'.pptx';

        try {
            Agent::write($deck, $path);
            $back = Agent::read($path);
            $content = $back['slides'][1]['elements'][0]['content'] ?? '';

            expect($content)->toContain('const deck');
            expect($content)->toStartWith('`');
        } finally {
            @unlink($path);
        }
    });

    it('does not shred a highlighted line into one backtick span per token', function () {
        // A syntax highlighter emits one run PER TOKEN, and every one of them
        // is code. The reader emitted a marker per run, so `const deck = 1;`
        // came back as "`const`` deck = ``1``;`" — where each pair of adjacent
        // backticks closes one span and opens the next, meaning a re-parse
        // yields the INVERSE of the emphasis it was trying to preserve.
        //
        // Adjacent runs carrying the same decoration are now merged before
        // anything is emitted, which also makes the output independent of how
        // many runs the writer happened to split the text into.
        $deck = brandDeck();
        $path = tempnam(sys_get_temp_dir(), 'premium-rt2').'.pptx';

        try {
            Agent::write($deck, $path);
            $content = Agent::read($path)['slides'][1]['elements'][0]['content'] ?? '';

            expect($content)->not->toContain('``');

            // One span per LINE — the code block is two lines, and each comes
            // back as a single wrapped span rather than one per token.
            foreach (explode("\n", $content) as $line) {
                expect(substr_count($line, '`'))->toBe(2, "expected one span in: {$line}");
            }
        } finally {
            @unlink($path);
        }
    });
});

describe('the guard against a theme that is accepted and ignored', function () {
    it('proves the assertions can FAIL — a default deck has none of the brand', function () {
        // Without this, every assertion above could be matching boilerplate that
        // appears in any deck, and the suite would be green for a document
        // wearing none of the identity it was given. This is the control.
        $plain = [
            'id' => 'plain', 'title' => 'Plain', 'theme' => ['name' => 'default'],
            'slides' => [['id' => 's1', 'elements' => [
                ['id' => 'e1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50, 'content' => 'text'],
            ]]],
        ];

        $theme = deckParts($plain)['ppt/theme/theme1.xml'];

        expect($theme)->not->toContain('Playfair Display');
        expect($theme)->not->toContain('JetBrains Mono');
        expect($theme)->not->toContain('F97316');
        expect($theme)->toContain('Calibri');
    });
});
