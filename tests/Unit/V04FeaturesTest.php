<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * v0.4 feature coverage: slide transitions, image fit/crop, native charts,
 * and theme/layout parts. Each test exercises the real writer end-to-end and
 * inspects the emitted OOXML so any regression in the emission is caught.
 */

/**
 * @return array<string, mixed>
 */
function v04Fixture(): array
{
    return [
        'id' => 'v04',
        'title' => 'v0.4 deck',
        'theme' => ['name' => 'default', 'colors' => ['accent' => '#8B5CF6']],
        'slides' => [
            [
                'id' => 's1',
                'layout' => 'title',
                'elements' => [
                    [
                        'id' => 'e1', 'type' => 'text',
                        'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
                        'content' => 'Hello', 'format' => 'plain',
                    ],
                ],
            ],
        ],
    ];
}

/** 1x1 PNG data URI (intrinsic 1×1, so aspect = 1). */
function v04Png(): string
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    return 'data:image/png;base64,' . base64_encode($png);
}

/**
 * Write a deck to a temp pptx, return the named part's contents, clean up.
 *
 * @param  array<string, mixed>  $deck
 */
function v04SlideXml(array $deck, string $part): string
{
    $bytes = Agent::toBytes($deck);
    $tmp = tempnam(sys_get_temp_dir(), 'darkslide-v04-');
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

// ─── A) Transitions ─────────────────────────────────────────────────────────

it('emits a fade transition', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['transition'] = ['kind' => 'fade', 'duration' => 800];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->toContain('<p:transition spd="slow">');
    expect($xml)->toContain('<p:fade/>');
});

it('emits a directional push for slide transitions', function () {
    foreach (['left' => 'l', 'right' => 'r', 'up' => 'u', 'down' => 'd'] as $dir => $code) {
        $deck = v04Fixture();
        $deck['slides'][0]['transition'] = ['kind' => 'slide', 'direction' => $dir, 'duration' => 200];
        $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
        expect($xml)->toContain('<p:push dir="' . $code . '"/>');
        expect($xml)->toContain('spd="fast"'); // duration <= 250
    }
});

it('emits a zoom transition', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['transition'] = ['kind' => 'zoom', 'duration' => 400];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->toContain('<p:zoom/>');
    expect($xml)->toContain('spd="med"'); // 250 < 400 < 700
});

it('omits the transition element for kind none', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['transition'] = ['kind' => 'none'];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->not->toContain('<p:transition');
});

it('falls back to the deck default transition', function () {
    $deck = v04Fixture();
    $deck['theme']['defaultTransition'] = ['kind' => 'fade', 'duration' => 300];
    // slide has no transition of its own
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->toContain('<p:fade/>');
});

// ─── B) Image fit / crop ─────────────────────────────────────────────────────

it('emits a non-empty srcRect for fit cover', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'img', 'type' => 'image',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.4, 'h' => 0.4,
        'src' => v04Png(), 'fit' => 'cover',
    ];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($xml)->toMatch('/<a:srcRect [^>]*\/>/');
    // A 1x1 image in a wider-than-tall box crops top/bottom (t/b > 0).
    expect($xml)->toMatch('/<a:srcRect l="0" t="[1-9]\d*" r="0" b="[1-9]\d*"\/>/');
});

it('shrinks the ext for fit contain (letterbox)', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'img', 'type' => 'image',
        'x' => 0.0, 'y' => 0.0, 'w' => 0.4, 'h' => 0.4,
        'src' => v04Png(), 'fit' => 'contain',
    ];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    // Box is 0.4*9144000 x 0.4*5143500 = 3657600 x 2057400. A square image
    // fits to the smaller axis (height) → ext should be 2057400 x 2057400.
    expect($xml)->toContain('<a:ext cx="2057400" cy="2057400"/>');
    // No crop for contain.
    expect($xml)->not->toContain('<a:srcRect');
});

it('honours an explicit crop rect over fit', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'img', 'type' => 'image',
        'x' => 0.1, 'y' => 0.1, 'w' => 0.4, 'h' => 0.4,
        'src' => v04Png(), 'fit' => 'cover',
        'crop' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.5],
    ];
    $xml = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    // l=0.1*100000, t=0.2*100000, r=(1-0.1-0.5)*100000, b=(1-0.2-0.5)*100000
    expect($xml)->toContain('<a:srcRect l="10000" t="20000" r="40000" b="30000"/>');
});

// ─── C) Charts ───────────────────────────────────────────────────────────────

it('emits a well-formed bar chart part with one ser per series', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'bar', 'type' => 'chart',
        'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.5,
        'option' => [
            'xAxis' => ['data' => ['Q1', 'Q2', 'Q3']],
            'series' => [
                ['type' => 'bar', 'name' => 'Rev', 'data' => [10, 20, 15]],
                ['type' => 'bar', 'name' => 'Cost', 'data' => [5, 8, 7]],
            ],
        ],
    ];
    $chart = v04SlideXml($deck, 'ppt/charts/chart1.xml');
    expect($chart)->not->toBe('');

    // Well-formed XML.
    $dom = new DOMDocument();
    expect($dom->loadXML($chart))->toBeTrue();

    expect($chart)->toContain('<c:barChart>');
    expect(substr_count($chart, '<c:ser>'))->toBe(2);
    expect($chart)->toContain('<c:strLit>'); // category literal cache
    expect($chart)->toContain('<c:numLit>'); // value literal cache

    // The graphicFrame on the slide references the chart part.
    $slide = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($slide)->toContain('<p:graphicFrame>');
    expect($slide)->toContain('r:id="rIdChart1"');
});

it('emits a pie chart part', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'pie', 'type' => 'chart',
        'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.5,
        'option' => ['series' => [['type' => 'pie', 'data' => [
            ['name' => 'A', 'value' => 30],
            ['name' => 'B', 'value' => 50],
            ['name' => 'C', 'value' => 20],
        ]]]],
    ];
    $chart = v04SlideXml($deck, 'ppt/charts/chart1.xml');
    $dom = new DOMDocument();
    expect($dom->loadXML($chart))->toBeTrue();
    expect($chart)->toContain('<c:pieChart>');
    // Three slices → three colored data points.
    expect(substr_count($chart, '<c:dPt>'))->toBe(3);
    // Categories derived from data[].name.
    expect($chart)->toContain('<c:v>A</c:v>');
});

it('registers a content-type override for chart parts', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'bar', 'type' => 'chart',
        'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.5,
        'option' => ['categories' => ['a'], 'series' => [['type' => 'bar', 'data' => [1]]]],
    ];
    $ct = v04SlideXml($deck, '[Content_Types].xml');
    expect($ct)->toContain('/ppt/charts/chart1.xml');
    expect($ct)->toContain('application/vnd.openxmlformats-officedocument.drawingml.chart+xml');
});

it('falls back to a placeholder for an untranslatable chart without crashing', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'bad', 'type' => 'chart',
        'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.5,
        'option' => ['title' => ['text' => 'Mystery'], 'series' => [['type' => 'radar', 'data' => [1, 2, 3]]]],
    ];
    $slide = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    // No chart part should exist for an untranslatable option.
    $chart = v04SlideXml($deck, 'ppt/charts/chart1.xml');
    expect($chart)->toBe('');
    // The placeholder carries the title and is a shape, not a graphicFrame.
    expect($slide)->toContain('<a:t>Mystery</a:t>');
    expect($slide)->toContain('prst="roundRect"');
});

it('embeds a pre-rendered chart image when the option is untranslatable', function () {
    $deck = v04Fixture();
    $deck['slides'][0]['elements'][] = [
        'id' => 'preR', 'type' => 'chart',
        'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.5,
        'option' => ['series' => 'nonsense'],
        'image' => v04Png(),
    ];
    $slide = v04SlideXml($deck, 'ppt/slides/slide1.xml');
    expect($slide)->toContain('<p:pic>'); // embedded as a picture
});

// ─── D) Theme + layouts ──────────────────────────────────────────────────────

it('emits all eight slide layout parts', function () {
    $bytes = Agent::toBytes(v04Fixture());
    $tmp = tempnam(sys_get_temp_dir(), 'darkslide-v04-');
    file_put_contents($tmp, $bytes);
    try {
        $zip = new ZipArchive();
        $zip->open($tmp);
        for ($n = 1; $n <= 8; $n++) {
            expect($zip->locateName("ppt/slideLayouts/slideLayout{$n}.xml"))->not->toBeFalse(message: "missing layout {$n}");
        }
        // The blank layout (1) carries type="blank"; title (2) carries type="title".
        expect($zip->getFromName('ppt/slideLayouts/slideLayout1.xml'))->toContain('type="blank"');
        expect($zip->getFromName('ppt/slideLayouts/slideLayout2.xml'))->toContain('type="title"');
        $zip->close();
    } finally {
        @unlink($tmp);
    }
});

it('points each slide at the layout matching its layout name', function () {
    $deck = v04Fixture();
    // slide 1 = title (layout 2); add a section-divider slide (layout 5).
    $deck['slides'][] = [
        'id' => 's2', 'layout' => 'section-divider',
        'elements' => [['id' => 'x', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2, 'content' => 'Section', 'format' => 'plain']],
    ];
    // unknown layout falls back to blank (layout 1).
    $deck['slides'][] = [
        'id' => 's3', 'layout' => 'made-up',
        'elements' => [['id' => 'y', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2, 'content' => 'Fallback', 'format' => 'plain']],
    ];

    expect(v04SlideXml($deck, 'ppt/slides/_rels/slide1.xml.rels'))->toContain('slideLayout2.xml');
    expect(v04SlideXml($deck, 'ppt/slides/_rels/slide2.xml.rels'))->toContain('slideLayout5.xml');
    expect(v04SlideXml($deck, 'ppt/slides/_rels/slide3.xml.rels'))->toContain('slideLayout1.xml');
});

it('maps theme colors into the clrScheme', function () {
    $deck = v04Fixture();
    $deck['theme']['colors'] = [
        'background' => '#101820', 'text' => '#F0F0F0', 'accent' => '#FF6600',
        'muted' => '#445566', 'surface' => '#223344',
    ];
    $theme = v04SlideXml($deck, 'ppt/theme/theme1.xml');
    expect($theme)->toContain('<a:lt1><a:srgbClr val="101820"/></a:lt1>');
    expect($theme)->toContain('<a:dk1><a:srgbClr val="F0F0F0"/></a:dk1>');
    expect($theme)->toContain('<a:accent1><a:srgbClr val="FF6600"/></a:accent1>');
    expect($theme)->toContain('<a:dk2><a:srgbClr val="445566"/></a:dk2>');
    expect($theme)->toContain('<a:lt2><a:srgbClr val="223344"/></a:lt2>');
});
