<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * Whole-element hyperlink (`element.href`) → OOXML `<a:hlinkClick>` + an
 * external slide relationship. Mirrors fancy-slides ElementBase.href.
 */

/** Read one part out of the generated pptx zip. */
function hlinkPart(array $deck, string $part): string
{
    $bytes = Agent::toBytes($deck);
    $tmp = tempnam(sys_get_temp_dir(), 'darkslide-hlink-');
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

/**
 * @return array<string, mixed>
 */
function hlinkDeck(string $href): array
{
    return [
        'id' => 'hl',
        'title' => 'hyperlink deck',
        'theme' => ['name' => 'default'],
        'slides' => [
            [
                'id' => 's1',
                'layout' => 'blank',
                'elements' => [
                    [
                        'id' => 'btn', 'type' => 'shape', 'shape' => 'rounded-rect',
                        'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.15,
                        'fill' => '#8B5CF6', 'href' => $href,
                    ],
                    [
                        'id' => 'plain', 'type' => 'text',
                        'x' => 0.1, 'y' => 0.4, 'w' => 0.5, 'h' => 0.1,
                        'content' => 'no link', 'format' => 'plain',
                    ],
                ],
            ],
        ],
    ];
}

it('injects an <a:hlinkClick> into the cNvPr of an element with href', function () {
    $xml = hlinkPart(hlinkDeck('https://particle.academy/fancy'), 'ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:hlinkClick r:id="rIdLink');
    // The shape's cNvPr is no longer self-closing — it wraps the hlink.
    expect($xml)->toContain('</p:cNvPr>');
});

it('registers an external hyperlink relationship with the target URL', function () {
    $rels = hlinkPart(hlinkDeck('https://particle.academy/fancy'), 'ppt/slides/_rels/slide1.xml.rels');

    expect($rels)->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"');
    expect($rels)->toContain('Target="https://particle.academy/fancy"');
    expect($rels)->toContain('TargetMode="External"');
});

it('emits no hlinkClick for elements without href', function () {
    $deck = [
        'id' => 'hl2', 'title' => 't', 'theme' => ['name' => 'default'],
        'slides' => [[
            'id' => 's1', 'layout' => 'blank',
            'elements' => [[
                'id' => 'x', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.1,
                'content' => 'plain', 'format' => 'plain',
            ]],
        ]],
    ];
    $xml = hlinkPart($deck, 'ppt/slides/slide1.xml');

    expect($xml)->not->toContain('<a:hlinkClick');
});
