<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * Text with no colour of its own takes the THEME's text colour.
 *
 * fancy-slides draws `style.color ?? theme.colors.text`; the writer used
 * `style.color ?? #0F172A`. On a light theme the two look alike, so nothing
 * noticed. On a dark theme every unstyled text box was near-black on a dark
 * background: the deck previewed correctly and exported with no visible text.
 * Found by rendering fancy-labs' premium reference deck in LibreOffice.
 */
function ticSlide(array $theme, array $style = []): string
{
    $deck = [
        'id' => 'tic',
        'title' => 'Theme text colour',
        'theme' => ['name' => 'tic', ...$theme],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'e', 'type' => 'text', 'x' => 0.1, 'y' => 0.4, 'w' => 0.8, 'h' => 0.2,
            'content' => "Headline\n\n- **bold** point", 'format' => 'markdown',
            ...($style === [] ? [] : ['style' => $style]),
        ]]]],
    ];
    $path = tempnam(sys_get_temp_dir(), 'tic').'.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

it('colours unstyled text with the theme text colour', function () {
    $xml = ticSlide(['colors' => ['background' => '#101828', 'text' => '#F9FAFB']]);

    expect($xml)->toContain('<a:srgbClr val="F9FAFB"/>')
        ->and($xml)->not->toContain('<a:srgbClr val="0F172A"/>');
});

it('still lets an element colour win over the theme', function () {
    $xml = ticSlide(['colors' => ['background' => '#101828', 'text' => '#F9FAFB']], ['color' => '#F97316']);

    expect($xml)->toContain('<a:srgbClr val="F97316"/>')
        ->and($xml)->not->toContain('<a:srgbClr val="F9FAFB"/>');
});

it('keeps the old default when the theme names no text colour', function () {
    expect(ticSlide([]))->toContain('<a:srgbClr val="0F172A"/>');
});
