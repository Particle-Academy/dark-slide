<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Images\CallbackImageResolver;
use DarkSlide\Images\LocalFileImageResolver;
use DarkSlide\Layout;

/** A 1x1 transparent PNG. */
function tinyPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
}

it('Layout::fit snaps geometry to the grid and clamps inside the safe margin', function () {
    $slide = ['id' => 's1', 'elements' => [
        ['id' => 'e1', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.137, 'y' => 0.0, 'w' => 0.31, 'h' => 0.2],
    ]];

    $fit = Layout::fit($slide, ['grid' => 12, 'safeMargin' => 0.02, 'reflowOverlap' => false, 'fitText' => false]);
    $el = $fit['elements'][0];

    // x/w snapped to 1/12 multiples
    expect(fmod(round($el['x'] * 12, 4), 1.0))->toBe(0.0)
        ->and(fmod(round($el['w'] * 12, 4), 1.0))->toBe(0.0)
        // clamped inside the margin
        ->and($el['x'])->toBeGreaterThanOrEqual(0.02)
        ->and($el['x'] + $el['w'])->toBeLessThanOrEqual(0.98 + 1e-9);

    // input untouched
    expect($slide['elements'][0]['x'])->toBe(0.137);
});

it('Layout::fit reflows overlapping boxes apart', function () {
    $slide = ['id' => 's1', 'elements' => [
        ['id' => 'a', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.1, 'w' => 0.4, 'h' => 0.3],
        ['id' => 'b', 'type' => 'shape', 'shape' => 'rect', 'x' => 0.1, 'y' => 0.1, 'w' => 0.4, 'h' => 0.3],
    ]];

    $fit = Layout::fit($slide, ['reflowOverlap' => true, 'fitText' => false]);
    [$a, $b] = $fit['elements'];

    $overlapY = $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    $overlapX = $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'];
    expect($overlapX && $overlapY)->toBeFalse();
});

it('Layout::fit shrinks over-long text to fit its box', function () {
    $slide = ['id' => 's1', 'elements' => [
        ['id' => 't', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.08,
            'content' => str_repeat('word ', 60), 'style' => ['fontSize' => 48]],
    ]];

    $fit = Layout::fit($slide, ['fitText' => true, 'reflowOverlap' => false, 'slideWidth' => 1280]);
    expect($fit['elements'][0]['style']['fontSize'])->toBeLessThan(48);
});

it('embeds images via a consumer ImageResolver (non-standard src)', function () {
    $deck = [
        'id' => 'd', 'title' => 'T', 'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [
            ['id' => 'img', 'type' => 'image', 'x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.5, 'src' => 'asset:42'],
        ]]],
    ];

    $resolver = new CallbackImageResolver(fn (string $src) => $src === 'asset:42' ? tinyPng() : null);
    $bytes = Agent::toBytes($deck, ['images' => $resolver]);

    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04")
        ->and(strlen($bytes))->toBeGreaterThan(0);
});

it('LocalFileImageResolver reads a file by relative path under a base dir', function () {
    $dir = sys_get_temp_dir().'/ds-img-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/pic.png', tinyPng());

    $resolver = new LocalFileImageResolver($dir);
    expect($resolver->resolve('pic.png'))->toBe(tinyPng())
        ->and($resolver->resolve('file://'.$dir.'/pic.png'))->toBe(tinyPng())
        ->and($resolver->resolve('https://example.com/x.png'))->toBeNull();

    @unlink($dir.'/pic.png');
    @rmdir($dir);
});

it('toStream returns a readable resource of valid pptx bytes', function () {
    $deck = ['id' => 'd', 'title' => 'T', 'theme' => ['name' => 'default'], 'slides' => [['id' => 's1', 'elements' => []]]];

    $stream = Agent::toStream($deck);
    expect(is_resource($stream))->toBeTrue();
    $head = fread($stream, 4);
    expect($head)->toBe("PK\x03\x04");
    fclose($stream);
});
