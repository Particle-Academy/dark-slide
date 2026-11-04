<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Reader\PptxReader;

/**
 * Every XML part of an uploaded deck is read through one gate, which refuses a
 * DOCTYPE. All of it goes through the public reader, because what matters is
 * what a hostile upload gets back, not what a helper returns.
 *
 * MARKER is the visible text of each part in a clean file, and ENTITY the value
 * its replacement declares, so "ENTITY in the result" means "it expanded".
 */
const DSD_MARKER = 'HSBASE';
const DSD_ENTITY = 'HSINT';
const DSD_DOCTYPE = '<!DOCTYPE r [<!ENTITY x "'.DSD_ENTITY.'">]>';

function dsdZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'ds_dt_');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function dsdUnzip(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'ds_dt_');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);

    return $parts;
}

/** A real package from the real writer, one marker in each part this file proves. */
function dsdPptx(): array
{
    return dsdUnzip(Agent::toBytes([
        'id' => 'd',
        'title' => DSD_MARKER,
        'theme' => ['name' => 'default'],
        'slides' => [[
            'id' => 's1',
            'layout' => 'blank',
            'notes' => DSD_MARKER,
            'elements' => [
                ['id' => 't1', 'type' => 'text', 'content' => DSD_MARKER, 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.1],
            ],
        ]],
    ]));
}

function dsdWithDoctype(string $xml, string $doctype = DSD_DOCTYPE): string
{
    if (preg_match('/^<\?xml[^>]*\?>\s*/', $xml, $m) === 1) {
        return $m[0].$doctype.substr($xml, strlen($m[0]));
    }

    return $doctype.$xml;
}

function dsdDeclare(string $xml, string $encoding): string
{
    return preg_replace('/^<\?xml[^>]*\?>/', '<?xml version="1.0" encoding="'.$encoding.'"?>', $xml);
}

/** @return array<string, callable(string): string> */
function dsdEncodings(): array
{
    return [
        'plain UTF-8' => static fn (string $x): string => $x,
        'UTF-8 with a BOM' => static fn (string $x): string => "\xEF\xBB\xBF".$x,
        'UTF-16LE with a BOM' => static fn (string $x): string => "\xFF\xFE".mb_convert_encoding(dsdDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'UTF-16BE with a BOM' => static fn (string $x): string => "\xFE\xFF".mb_convert_encoding(dsdDeclare($x, 'UTF-16'), 'UTF-16BE', 'UTF-8'),
        'UTF-16LE without a BOM' => static fn (string $x): string => mb_convert_encoding(dsdDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'past a long comment' => static fn (string $x): string => preg_replace('/^(<\?xml[^>]*\?>\s*)/', '$1<!-- '.str_repeat('p', 5000).' -->', $x, 1),
    ];
}

/** Parts whose marker reaches the result. */
function dsdMarkerParts(): array
{
    return ['docProps/core.xml', 'ppt/slides/slide1.xml', 'ppt/notesSlides/notesSlide1.xml'];
}

function dsdReferencing(array $parts, string $part): string
{
    return str_replace(DSD_MARKER, '&x;', $parts[$part]);
}

function dsdReadBytes(string $bytes): array
{
    return (new PptxReader())->fromBytes($bytes);
}

function dsdRead(array $parts): string
{
    try {
        $deck = dsdReadBytes(dsdZip($parts));
    } catch (RuntimeException) {
        return '(refused)';
    }
    // This line's reader derives the deck id from the clock, so two reads of one
    // file can differ by it alone.
    unset($deck['id']);

    return (string) json_encode($deck);
}

it('reads every marker from a clean deck, so a missing one means refused', function () {
    expect(substr_count(dsdRead(dsdPptx()), DSD_MARKER))->toBe(3);
});

it('uses a fixture whose entity is live, so its absence is the gate and not the fixture', function () {
    $sx = @simplexml_load_string('<?xml version="1.0"?>'.DSD_DOCTYPE.'<r>&x;</r>');

    expect((string) $sx)->toBe(DSD_ENTITY);
});

it('does not expand an internal entity from any part, in any encoding', function (string $part, string $encoding) {
    $parts = dsdPptx();
    $parts[$part] = dsdEncodings()[$encoding](dsdWithDoctype(dsdReferencing($parts, $part)));

    expect(dsdRead($parts))->not->toContain(DSD_ENTITY);
})->with(dsdMarkerParts())->with(array_keys(dsdEncodings()));

it('still reads each encoding without a DOCTYPE', function (string $part, string $encoding) {
    $parts = dsdPptx();
    $clean = dsdRead($parts);
    $parts[$part] = dsdEncodings()[$encoding]($parts[$part]);

    expect(dsdRead($parts))->toBe($clean);
})->with(dsdMarkerParts())->with(array_keys(dsdEncodings()));

it('does not follow a relationship whose target comes from an entity', function (string $part, string $target, string $probe) {
    $parts = dsdPptx();
    expect($parts[$part])->toContain($target);
    $parts[$part] = dsdWithDoctype(str_replace($target, '&x;', $parts[$part]), '<!DOCTYPE r [<!ENTITY x "'.$target.'">]>');

    expect(dsdRead($parts))->not->toContain($probe);
})->with([
    'presentation rels' => ['ppt/_rels/presentation.xml.rels', 'slides/slide1.xml', '"t1"'],
    'slide rels, notes' => ['ppt/slides/_rels/slide1.xml.rels', '../notesSlides/notesSlide1.xml', '"notes"'],
]);

describe('what a consumer sees', function () {
    it('falls back to the default title for a refused core part', function () {
        $parts = dsdPptx();
        $parts['docProps/core.xml'] = dsdWithDoctype($parts['docProps/core.xml']);

        expect(dsdReadBytes(dsdZip($parts))['title'])->toBe('Imported');
    });

    it('keeps the slide, with no elements, for a refused slide part', function () {
        $parts = dsdPptx();
        $parts['ppt/slides/slide1.xml'] = dsdWithDoctype($parts['ppt/slides/slide1.xml']);
        $slide = dsdReadBytes(dsdZip($parts))['slides'][0];

        expect($slide['id'])->toBe('imported-slide-1')->and($slide['elements'])->toBe([]);
    });

    it('reads no slides for refused presentation relationships', function () {
        $parts = dsdPptx();
        $parts['ppt/_rels/presentation.xml.rels'] = dsdWithDoctype($parts['ppt/_rels/presentation.xml.rels']);

        expect(dsdReadBytes(dsdZip($parts))['slides'])->toBe([]);
    });

    it('reads the rest of the deck without a refused notes part', function () {
        $parts = dsdPptx();
        $parts['ppt/notesSlides/notesSlide1.xml'] = dsdWithDoctype($parts['ppt/notesSlides/notesSlide1.xml']);
        $slide = dsdReadBytes(dsdZip($parts))['slides'][0];

        expect($slide)->not->toHaveKey('notes')->and($slide['elements'])->toHaveCount(1);
    });
});

describe('external entities', function () {
    beforeEach(function () {
        $this->canary = tempnam(sys_get_temp_dir(), 'ds_dt_canary_');
        file_put_contents($this->canary, 'HSCANARY');
        $this->url = 'file:///'.str_replace(chr(92), '/', $this->canary);
        $this->loads = [];
        $loads = &$this->loads;
        libxml_set_external_entity_loader(function ($public, $system) use (&$loads) {
            $loads[] = $system;

            return null;
        });
    });

    afterEach(function () {
        libxml_set_external_entity_loader(null);
        unlink($this->canary);
    });

    it('catches a load when one happens, so zero loads below means something', function () {
        $prev = libxml_use_internal_errors(true);
        simplexml_load_string('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "'.$this->url.'">]><r>&x;</r>', SimpleXMLElement::class, LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        expect($this->loads)->toHaveCount(1);
    });

    it('neither loads nor leaks a file entity', function (string $part) {
        $parts = dsdPptx();
        $parts[$part] = dsdWithDoctype(dsdReferencing($parts, $part), '<!DOCTYPE r [<!ENTITY x SYSTEM "'.$this->url.'">]>');

        expect(dsdRead($parts))->not->toContain('HSCANARY')
            ->and($this->loads)->toBe([]);
    })->with(dsdMarkerParts());
});

describe('text that only looks like a DOCTYPE', function () {
    it('is kept in a slide written as CDATA', function () {
        $parts = dsdPptx();
        $parts['ppt/slides/slide1.xml'] = str_replace(DSD_MARKER, '<![CDATA[<!DOCTYPE html>]]>', $parts['ppt/slides/slide1.xml']);

        expect(dsdRead($parts))->toContain('<!DOCTYPE html>');
    });

    it('does not stop a part being read from a comment', function () {
        $parts = dsdPptx();
        $parts['ppt/slides/slide1.xml'] = dsdWithDoctype($parts['ppt/slides/slide1.xml'], '<!-- <!DOCTYPE x> -->');

        expect(dsdRead($parts))->toContain('"t1"');
    });
});

it('leaves no way to a part except through the gate', function () {
    $offenders = [];
    $root = dirname(__DIR__, 2).'/src';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'PartXml.php') {
            continue;
        }
        if (preg_match('/simplexml_load_(string|file)|->loadXML\(|->load\(.*LIBXML|new XMLReader|xml_parser_create|new SimpleXMLElement/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
