<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * v0.5 feature coverage: element entrance animations exported as OOXML
 * `<p:timing>`. Each test exercises the real writer end-to-end and inspects
 * the emitted slide XML so any regression in the timing emission is caught.
 *
 * Structural well-formedness (DOMDocument) plus spTgt↔cNvPr id matching are
 * necessary but NOT sufficient — the animation tree still needs a real
 * PowerPoint probe to confirm it actually plays. See CHANGELOG / report.
 */

/**
 * @return array<string, mixed>
 */
function animFixture(): array
{
    return [
        'id' => 'anim',
        'title' => 'animation deck',
        'theme' => ['name' => 'default', 'colors' => ['accent' => '#8B5CF6']],
        'slides' => [
            [
                'id' => 's1',
                'layout' => 'title',
                'elements' => [],
            ],
        ],
    ];
}

/**
 * Write a deck to a temp pptx, return the named part's contents, clean up.
 *
 * @param  array<string, mixed>  $deck
 */
function animSlideXml(array $deck, string $part): string
{
    $bytes = Agent::toBytes($deck);
    $tmp = tempnam(sys_get_temp_dir(), 'darkslide-anim-');
    file_put_contents($tmp, $bytes);
    try {
        $zip = new ZipArchive();
        $zip->open($tmp);
        $xml = $zip->getFromName($part);
        $zip->close();

        return $xml === false ? '' : $xml;
    } finally {
        @unlink($tmp);
    }
}

/** Extract the `spid` of every `<p:spTgt spid="N"/>` in some XML, in order. */
function spTgtSpids(string $xml): array
{
    preg_match_all('/<p:spTgt spid="(\d+)"\/>/', $xml, $m);

    return array_map('intval', $m[1]);
}

/** Extract the `id` of every `<p:cNvPr id="N" .../>` in some XML, in order. */
function cNvPrIds(string $xml): array
{
    preg_match_all('/<p:cNvPr id="(\d+)"/', $xml, $m);

    return array_map('intval', $m[1]);
}

it('emits no timing node when no element is animated', function () {
    $deck = animFixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'plain', 'type' => 'text',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
        'content' => 'Hello', 'format' => 'plain',
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->not->toContain('<p:timing>');
});

it('emits a well-formed timing tree for three animated text elements', function () {
    $deck = animFixture();
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'a', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
            'content' => 'One', 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'trigger' => 'on-click'],
        ],
        [
            'id' => 'b', 'type' => 'text', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2,
            'content' => 'Two', 'format' => 'plain',
            'animation' => ['effect' => 'fly-in', 'trigger' => 'with-prev', 'direction' => 'left'],
        ],
        [
            'id' => 'c', 'type' => 'text', 'x' => 0.1, 'y' => 0.7, 'w' => 0.8, 'h' => 0.2,
            'content' => 'Three', 'format' => 'plain',
            'animation' => ['effect' => 'zoom', 'trigger' => 'after-prev'],
        ],
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');

    // Whole slide part is well-formed.
    $dom = new DOMDocument();
    expect($dom->loadXML($xml))->toBeTrue();

    // Timing present and ordered after cSld (and after any transition).
    expect($xml)->toContain('<p:timing>');
    expect(strpos($xml, '<p:timing>'))->toBeGreaterThan(strpos($xml, '</p:cSld>'));

    // mainSeq present.
    expect($xml)->toContain('nodeType="mainSeq"');
    expect($xml)->toContain('nodeType="tmRoot"');

    // Three builds: fade on-click, fly-in with-prev, zoom after-prev.
    // on-click leads a step; with-prev + after-prev attach to it → ONE step.
    // A click step's start condition is the indefinite click wait.
    expect(substr_count($xml, '<p:cond delay="indefinite"/>'))->toBe(1);

    // Effect nodes present.
    expect($xml)->toContain('filter="fade"'); // fade
    expect($xml)->toContain('ppt_x'); // fly-in motion (ppt_x/ppt_y translate)
    expect($xml)->toContain('<p:animScale>'); // zoom grow (scale 0%→100%)
    expect($xml)->toContain('<p:from x="0" y="0"/>'); // zoom starts at a point

    // Builds are entrances — PowerPoint pre-hides them (presetClass="entr"),
    // and each reveals its target via a visibility set when it fires.
    expect($xml)->toContain('presetClass="entr"');
    expect($xml)->toContain('<p:strVal val="visible"/>');
});

it('produces the right number of click steps for mixed triggers', function () {
    $deck = animFixture();
    // Two on-click leads → two click steps; the with-prev attaches to step 2.
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'a', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
            'content' => 'One', 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'trigger' => 'on-click'],
        ],
        [
            'id' => 'b', 'type' => 'text', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2,
            'content' => 'Two', 'format' => 'plain',
            'animation' => ['effect' => 'wipe', 'trigger' => 'on-click', 'direction' => 'up'],
        ],
        [
            'id' => 'c', 'type' => 'text', 'x' => 0.1, 'y' => 0.7, 'w' => 0.8, 'h' => 0.2,
            'content' => 'Three', 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'trigger' => 'with-prev'],
        ],
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');
    // Two click steps → two indefinite waits.
    expect(substr_count($xml, '<p:cond delay="indefinite"/>'))->toBe(2);
    // wipe effect keyed to direction up → filter wipe(down).
    expect($xml)->toContain('filter="wipe(down)"');
});

it('matches every spTgt spid to a real cNvPr shape id', function () {
    $deck = animFixture();
    // Mix an animated element AFTER a non-animated one + an image, so the
    // running shape-id counter is exercised. Shape ids start at 2.
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'plain', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.1,
            'content' => 'Title', 'format' => 'plain',
        ], // shape id 2, not animated
        [
            'id' => 'a', 'type' => 'text', 'x' => 0.1, 'y' => 0.3, 'w' => 0.8, 'h' => 0.2,
            'content' => 'One', 'format' => 'plain',
            'animation' => ['effect' => 'fade'],
        ], // shape id 3
        [
            'id' => 'b', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.6, 'w' => 0.3, 'h' => 0.2,
            'animation' => ['effect' => 'fly-in', 'trigger' => 'after-prev', 'direction' => 'right'],
        ], // shape id 4
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');

    $shapeIds = cNvPrIds($xml); // includes id=1 (grpSp) + 2,3,4
    $targets = spTgtSpids($xml); // only inside <p:timing>

    // The animated shapes are ids 3 and 4; targets must be a subset of real ids.
    expect($targets)->not->toBeEmpty();
    foreach (array_unique($targets) as $spid) {
        expect($shapeIds)->toContain($spid);
    }
    // Specifically the two animated shapes were targeted.
    expect($targets)->toContain(3);
    expect($targets)->toContain(4);
    // The non-animated shape (id 2) is NOT targeted.
    expect($targets)->not->toContain(2);
});

it('orders builds by order then array index', function () {
    $deck = animFixture();
    // Author them out of order; `order` should pull element "b" (order 0)
    // before "a" (order 5). Both on-click → two steps; b's step first.
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'a', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
            'content' => 'A', 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'order' => 5],
        ], // shape id 2
        [
            'id' => 'b', 'type' => 'text', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2,
            'content' => 'B', 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'order' => 0],
        ], // shape id 3
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');
    $targets = spTgtSpids($xml);

    // Drop the initial hide group's targets (those appear in document order:
    // hide-a (2), hide-b (3)). Find the first spTgt that appears AFTER the
    // mainSeq opens — that is the first BUILD's target and must be shape 3 (b).
    $seqPos = strpos($xml, 'nodeType="mainSeq"');
    $afterSeq = substr($xml, $seqPos);
    $seqTargets = spTgtSpids($afterSeq);
    expect($seqTargets[0])->toBe(3); // b builds first thanks to lower order
});

/** Extract the `(st,end)` of every `<p:pRg st="i" end="j"/>` in some XML, in order. */
function pRanges(string $xml): array
{
    preg_match_all('/<p:pRg st="(\d+)" end="(\d+)"\/>/', $xml, $m, PREG_SET_ORDER);

    return array_map(static fn ($r) => [(int) $r[1], (int) $r[2]], $m);
}

it('splits a byParagraph text element into one paragraph-scoped build node per line', function () {
    $deck = animFixture();
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'lines', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.5,
            'content' => "Line one\nLine two\nLine three", 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'byParagraph' => true, 'trigger' => 'on-click'],
        ], // shape id 2
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');

    // Whole slide part is well-formed.
    $dom = new DOMDocument();
    expect($dom->loadXML($xml))->toBeTrue();

    // Three paragraph builds, each targeting its own <a:p> via a single-line
    // paragraph range. The hide group also emits one pRg per paragraph, so we
    // look only at the build region after the mainSeq opens.
    $seqXml = substr($xml, strpos($xml, 'nodeType="mainSeq"'));

    // st=end=i for i = 0,1,2 — selecting just paragraph i.
    expect($seqXml)->toContain('<p:pRg st="0" end="0"/>');
    expect($seqXml)->toContain('<p:pRg st="1" end="1"/>');
    expect($seqXml)->toContain('<p:pRg st="2" end="2"/>');

    // Every paragraph target lives under the SAME shape (spid 2).
    foreach (array_unique(spTgtSpids($seqXml)) as $spid) {
        expect($spid)->toBe(2);
    }

    // No whole-shape (pRg-less) build target slipped in: every spTgt in the
    // build region is immediately followed by a <p:txEl><p:pRg ...>.
    preg_match_all('/<p:spTgt spid="\d+"(\/?>)/', $seqXml, $tg, PREG_SET_ORDER);
    foreach ($tg as $t) {
        // Self-closing spTgt (no txEl child) is the whole-shape form — forbidden here.
        expect($t[1])->not->toBe('/>');
    }

    // Grouped as 3 click steps (3 indefinite waits): first line keeps its
    // on-click trigger, the next two become their own clicks.
    expect(substr_count($seqXml, '<p:cond delay="indefinite"/>'))->toBe(3);

    // The build-region pRgs appear in paragraph order — matching the <a:p>
    // order in the txBody (both come from explode("\n", content)). Each
    // paragraph build emits its range twice (visibility set + effect node),
    // so we collapse consecutive duplicates before comparing the order.
    $ranges = pRanges($seqXml);
    $distinct = [];
    foreach ($ranges as $r) {
        if ($distinct === [] || end($distinct) !== $r) {
            $distinct[] = $r;
        }
    }
    expect($distinct)->toBe([[0, 0], [1, 1], [2, 2]]);

    // Hidden-until-built via entrance pre-hide (presetClass="entr") — one per
    // paragraph build — not a separate load-time hide group (that flashed).
    // Each paragraph reveals its line with a visibility set when it fires.
    expect(substr_count($seqXml, 'presetClass="entr"'))->toBe(3);
    expect($seqXml)->toContain('<p:strVal val="visible"/>');
    expect($xml)->not->toContain('<p:strVal val="hidden"/>');
});

it('keeps a 3-line element WITHOUT byParagraph as a single whole-shape build', function () {
    $deck = animFixture();
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'block', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.5,
            'content' => "Line one\nLine two\nLine three", 'format' => 'plain',
            'animation' => ['effect' => 'fade', 'trigger' => 'on-click'],
        ], // shape id 2
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');

    $dom = new DOMDocument();
    expect($dom->loadXML($xml))->toBeTrue();

    // No paragraph ranges at all — the whole shape is targeted.
    expect($xml)->not->toContain('<p:pRg');
    expect($xml)->toContain('<p:spTgt spid="2"/>');

    // One click step.
    expect(substr_count($xml, '<p:cond delay="indefinite"/>'))->toBe(1);
});

it('ignores byParagraph on non-text elements (whole-shape target)', function () {
    $deck = animFixture();
    $deck['slides'][0]['elements'] = [
        [
            'id' => 'box', 'type' => 'shape', 'shape' => 'rect',
            'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.2,
            'animation' => ['effect' => 'fade', 'byParagraph' => true, 'trigger' => 'on-click'],
        ], // shape id 2
    ];
    $xml = animSlideXml($deck, 'ppt/slides/slide1.xml');

    expect($xml)->not->toContain('<p:pRg');
    expect($xml)->toContain('<p:spTgt spid="2"/>');
});
