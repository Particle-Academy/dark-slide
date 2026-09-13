<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Layout;

/**
 * The deck's design canvas maps onto the slide the way fancy-slides maps it.
 *
 * Before 0.10 the same `fontSize` was scaled three ways: fancy-slides drew it as
 * a share of a 1920px canvas (96 = 5.0% of the slide width), the PPTX writer
 * halved it on a 720pt slide (6.7%), and `Layout::fit` estimated against a 1280px
 * canvas (7.5%). Text an agent fitted to a box in the preview overflowed it in
 * PowerPoint. `theme.aspectRatio` was accepted and ignored, so a 4:3 deck came
 * out stretched onto 16:9.
 *
 * Pinned here: one scale for every length, `theme.slideWidth` as the canvas and
 * as the way back to the old sizes, and the slide shaped by the ratio.
 */
function dcDeck(array $theme, array $element): array
{
    return [
        'id' => 'canvas',
        'title' => 'Design canvas',
        'theme' => ['name' => 'canvas', ...$theme],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'e', 'type' => 'text', 'x' => 0.1, 'y' => 0.5, 'w' => 0.5, 'h' => 0.25, 'content' => 'Canvas', ...$element,
        ]]]],
    ];
}

/** @return array<string, string> */
function dcParts(array $deck): array
{
    $path = tempnam(sys_get_temp_dir(), 'dc').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [
        'slide' => (string) $zip->getFromName('ppt/slides/slide1.xml'),
        'presentation' => (string) $zip->getFromName('ppt/presentation.xml'),
    ];
    $zip->close();
    @unlink($path);

    return $parts;
}

it('keeps a font size at the same share of the slide as fancy-slides draws it', function () {
    // 96 of 1920 is 5% of the width; 5% of a 720pt slide is 36pt.
    expect(dcParts(dcDeck([], ['style' => ['fontSize' => 96]]))['slide'])->toContain('sz="3600"');

    // Half the canvas, twice the share.
    expect(dcParts(dcDeck(['slideWidth' => 960], ['style' => ['fontSize' => 96]]))['slide'])->toContain('sz="7200"');
});

it('reproduces the sizes dark-slide wrote before 0.10 with slideWidth 1440', function () {
    // 720 / 1440 is the old halving, so text and every length land where 0.9 put them.
    $slide = dcParts(dcDeck(['slideWidth' => 1440], ['style' => ['fontSize' => 96, 'letterSpacing' => 4, 'padding' => 24]]))['slide'];

    expect($slide)->toContain('sz="4800"');     // 48pt, as 0.9 halved it
    expect($slide)->toContain('spc="200"');     // 2pt
    expect($slide)->toContain('lIns="152400"'); // 12pt
});

it('scales a shape outline with the canvas', function () {
    $shape = ['type' => 'shape', 'shape' => 'rect', 'strokeWidth' => 8];

    expect(dcParts(dcDeck([], $shape))['slide'])->toContain('<a:ln w="38100">');                       // 3pt
    expect(dcParts(dcDeck(['slideWidth' => 1440], $shape))['slide'])->toContain('<a:ln w="50800">');   // 4pt
});

it('shapes the slide by theme.aspectRatio instead of always writing 16:9', function (float $ratio, string $sldSz, int $yEmu) {
    $parts = dcParts(dcDeck(['aspectRatio' => $ratio], []));

    expect($parts['presentation'])->toContain($sldSz);
    // y 0.5 is the middle of THIS slide's height, not of a 16:9 one.
    expect($parts['slide'])->toContain('<a:off x="914400" y="'.$yEmu.'"/>');
})->with([
    '16:9 (default)' => [16 / 9, '<p:sldSz cx="9144000" cy="5143500" type="screen16x9"/>', 2571750],
    '16:10' => [16 / 10, '<p:sldSz cx="9144000" cy="5715000" type="screen16x10"/>', 2857500],
    '4:3' => [4 / 3, '<p:sldSz cx="9144000" cy="6858000" type="screen4x3"/>', 3429000],
    'custom 2:1' => [2.0, '<p:sldSz cx="9144000" cy="4572000"/>', 2286000],
]);

it('writes a deck with no aspectRatio exactly as 16:9', function () {
    expect(dcParts(dcDeck([], []))['presentation'])->toContain('<p:sldSz cx="9144000" cy="5143500" type="screen16x9"/>');
});

it('has Layout::fit estimate text against the same 1920 canvas', function () {
    // A quarter-width box is 480 design px on the 1920 canvas. At fontSize 96 the
    // estimator's half-em glyphs fit "ABCDEFGHIJ" (480px) on one 124.8px line, and
    // the box is 0.2 x 1080 = 216px tall, so nothing shrinks.
    //
    // On the old 1280 canvas the box was 320px wide, the text wrapped to two
    // lines (249.6px) in a 144px box, and the estimator shrank a headline that
    // PowerPoint and fancy-slides both show fitting.
    $slide = ['elements' => [[
        'id' => 't', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.25, 'h' => 0.2,
        'content' => 'ABCDEFGHIJ', 'style' => ['fontSize' => 96],
    ]]];

    $fit = Layout::fit($slide, ['grid' => 1000, 'reflowOverlap' => false, 'fitText' => true]);

    expect($fit['elements'][0]['style']['fontSize'])->toBe(96);

    // And a canvas that genuinely is narrower still shrinks it.
    $narrow = Layout::fit($slide, ['grid' => 1000, 'reflowOverlap' => false, 'fitText' => true, 'slideWidth' => 1280]);
    expect($narrow['elements'][0]['style']['fontSize'])->toBeLessThan(96);
});
