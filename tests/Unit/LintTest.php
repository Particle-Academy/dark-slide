<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * Agent::lint -- layout problems an agent can fix before anyone looks.
 *
 * Measured in fancy-labs before it became a package feature: decks whose title
 * wrapped (the brand font was missing and the substitute is wider) onto the
 * accent rule beneath it were gated by a reviewer 2 runs in 2; the same skill
 * with these findings handed back from the write passed 2 in 2, for one extra
 * write. The metrics are deliberately conservative for exactly that reason.
 *
 * The same cases, with the same messages, run in all three engines.
 */
function lintDeck(array $elements, array $theme = []): array
{
    return ['id' => 'd', 'title' => 'D', 'theme' => ['name' => 't', ...$theme], 'slides' => [['id' => 's1', 'elements' => $elements]]];
}

it('names a title that will wrap past its box and onto the rule beneath it', function () {
    $findings = Agent::lint(lintDeck([
        ['id' => 'title', 'type' => 'text', 'x' => 0.06, 'y' => 0.06, 'w' => 0.88, 'h' => 0.13,
            'content' => 'EMEA delivered 48% of Q3 revenue', 'style' => ['fontSize' => 88, 'weight' => 700]],
        ['id' => 'rule', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.06, 'y' => 0.2, 'w' => 0.06, 'h' => 0.006],
    ]));

    expect($findings)->toBe([
        ['code' => 'text-overflow', 'slide' => 1, 'element' => 'title',
            'message' => 'At 88px this text needs about 0.23 of the slide height and its box is 0.13: it will wrap past the box, more so in a substitute font. Make h at least 0.23 and move what is below it down, shorten the text, or lower fontSize.'],
        ['code' => 'overlap', 'slide' => 1, 'element' => 'title',
            'message' => 'Its text runs into "rule". Place one below the other\'s bottom edge (y + h) with a gap of at least 0.02.'],
    ]);
});

it('does not count text on a panel behind it as an overlap', function () {
    expect(Agent::lint(lintDeck([
        ['id' => 'card', 'type' => 'shape', 'shape' => 'rounded-rect', 'x' => 0.6, 'y' => 0.3, 'w' => 0.34, 'h' => 0.3],
        ['id' => 'card-text', 'type' => 'text', 'x' => 0.62, 'y' => 0.32, 'w' => 0.3, 'h' => 0.2, 'content' => 'EMEA: $2.10M', 'style' => ['fontSize' => 44]],
    ])))->toBe([]);
});

it('compares where text SITS, not the whole box', function () {
    // Two tall boxes overlap; the text in the top one ends long before the second begins.
    expect(Agent::lint(lintDeck([
        ['id' => 'num', 'type' => 'text', 'x' => 0.06, 'y' => 0.3, 'w' => 0.4, 'h' => 0.4, 'content' => '48.5%', 'style' => ['fontSize' => 120]],
        ['id' => 'cap', 'type' => 'text', 'x' => 0.06, 'y' => 0.6, 'w' => 0.4, 'h' => 0.15, 'content' => 'of Q3 revenue', 'style' => ['fontSize' => 40]],
    ])))->toBe([]);
});

it('names KPI values too wide for their cells, with the size that fits', function () {
    $findings = Agent::lint(lintDeck([
        ['id' => 'kpis', 'type' => 'kpiBand', 'x' => 0.06, 'y' => 0.6, 'w' => 0.88, 'h' => 0.2,
            'items' => [['value' => '$4.33M'], ['value' => '$2.10M'], ['value' => '$1.25M'], ['value' => '$0.98M']],
            'style' => ['valueFontSize' => 120]],
    ]));

    expect($findings)->toBe([
        ['code' => 'kpi-overflow', 'slide' => 1, 'element' => 'kpis',
            'message' => 'A 6-character value at valueFontSize 120 needs about 511px and each of the 4 cells is 422px: values will run into each other. Lower valueFontSize to 96 or less.'],
    ]);
});

it('names a box that runs off the slide', function () {
    expect(Agent::lint(lintDeck([
        ['id' => 'late', 'type' => 'text', 'x' => 0.5, 'y' => 0.9, 'w' => 0.6, 'h' => 0.2, 'content' => 'x', 'style' => ['fontSize' => 40]],
    ])))->toBe([
        ['code' => 'off-slide', 'slide' => 1, 'element' => 'late',
            'message' => 'The box runs off the slide: x + w and y + h must stay within 0..1.'],
    ]);
});

it('names text below 32px on the 1920 canvas, which projects at under 12pt', function () {
    $findings = Agent::lint(lintDeck([
        ['id' => 'foot', 'type' => 'text', 'x' => 0.06, 'y' => 0.93, 'w' => 0.5, 'h' => 0.05, 'content' => 'Source: Q3', 'style' => ['fontSize' => 20]],
    ]));

    expect($findings)->toBe([
        ['code' => 'small-text', 'slide' => 1, 'element' => 'foot',
            'message' => 'fontSize 20 is 7.5pt on the slide: too small to read when projected. Use 32 (12pt) or more.'],
    ]);
});

it('measures on the deck\'s own canvas when it sets one', function () {
    // slideWidth 1440: 20px is 10pt there, so it is not small; 0.75pt per px.
    expect(Agent::lint(lintDeck([
        ['id' => 'foot', 'type' => 'text', 'x' => 0.06, 'y' => 0.9, 'w' => 0.5, 'h' => 0.08, 'content' => 'Source: Q3', 'style' => ['fontSize' => 26]],
    ], ['slideWidth' => 1440])))->toBe([]);
});

it('returns nothing for a clean slide, and nothing for a deck with no slides', function () {
    expect(Agent::lint(lintDeck([
        ['id' => 't', 'type' => 'text', 'x' => 0.06, 'y' => 0.05, 'w' => 0.88, 'h' => 0.2, 'content' => 'EMEA led Q3 revenue', 'style' => ['fontSize' => 72, 'weight' => 700]],
        ['id' => 'r', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.06, 'y' => 0.27, 'w' => 0.06, 'h' => 0.006],
    ])))->toBe([])
        ->and(Agent::lint(['id' => 'd', 'title' => 'D', 'theme' => ['name' => 't'], 'slides' => []]))->toBe([]);
});

it('names markdown markers in a box that is not markdown, which print as typed', function () {
    // A launch deck reviewed with "**The problem.**" on three slides: the agent
    // wrote markdown and left format at its default, so the asterisks printed.
    $findings = Agent::lint(lintDeck([
        ['id' => 'lead', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.3, 'content' => "**The problem.** Reconciliation is manual.", 'style' => ['fontSize' => 40]],
        ['id' => 'head', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.5, 'w' => 0.3, 'h' => 0.1, 'content' => "# Owners"],
        ['id' => 'fine', 'type' => 'text', 'x' => 0.5, 'y' => 0.5, 'w' => 0.4, 'h' => 0.3, 'content' => '**Adoption** at 90 days', 'format' => 'markdown', 'style' => ['fontSize' => 40]],
        ['id' => 'math', 'type' => 'text', 'x' => 0.1, 'y' => 0.85, 'w' => 0.3, 'h' => 0.1, 'content' => '2 * 3 = 6, #1 in EMEA', 'style' => ['fontSize' => 40]],
    ]));

    expect($findings)->toBe([
        ['code' => 'literal-markdown', 'slide' => 1, 'element' => 'lead', 'message' => 'This text uses markdown (**bold** or a # heading) but its format is not "markdown", so the markers print as typed. Set "format": "markdown", or remove the markers.'],
        ['code' => 'literal-markdown', 'slide' => 1, 'element' => 'head', 'message' => 'This text uses markdown (**bold** or a # heading) but its format is not "markdown", so the markers print as typed. Set "format": "markdown", or remove the markers.'],
    ]);
});

it('wraps by WORD, as a renderer does', function () {
    // "Q3 Business Review" at 150px in a 0.54-wide box: 18 characters measure 1.7
    // lines, so a character count said two lines and nothing was flagged. But no
    // renderer breaks inside a word: "Q3 Business" does not fit, so it is three
    // lines, and the title ran into the subtitle beneath it on the slide.
    $findings = Agent::lint(lintDeck([
        ['id' => 'title', 'type' => 'text', 'x' => 0.06, 'y' => 0.27, 'w' => 0.54, 'h' => 0.5,
            'content' => 'Q3 Business Review', 'style' => ['fontSize' => 150, 'weight' => 700]],
        ['id' => 'sub', 'type' => 'text', 'x' => 0.06, 'y' => 0.79, 'w' => 0.54, 'h' => 0.12,
            'content' => 'Regional revenue results', 'style' => ['fontSize' => 44]],
    ]));

    expect(array_column($findings, 'code'))->toBe(['text-overflow', 'overlap', 'overlap'])
        ->and($findings[0]['message'])->toStartWith('At 150px this text needs about 0.54 of the slide height and its box is 0.5:');
});
