<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Exceptions\ArchiveRefused;
use DarkSlide\Exceptions\OfficeTemplateRefused;
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
const DSD_MAIN = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';
const DSD_MACRO_TEMPLATE = 'application/vnd.ms-powerpoint.template.macroEnabled.main+xml';

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
        'theme' => ['name' => 'default', 'fonts' => ['heading' => DSD_MARKER]],
        'slides' => [[
            'id' => 's1',
            'layout' => 'blank',
            'notes' => DSD_MARKER,
            'elements' => [
                ['id' => 't1', 'type' => 'text', 'content' => DSD_MARKER, 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.1],
                ['id' => 'c1', 'type' => 'chart', 'x' => 0.1, 'y' => 0.3, 'w' => 0.5, 'h' => 0.5, 'option' => [
                    'title' => ['text' => DSD_MARKER],
                    'xAxis' => ['type' => 'category', 'data' => ['a']],
                    'yAxis' => ['type' => 'value'],
                    'series' => [['type' => 'bar', 'name' => 'n', 'data' => [1]]],
                ]],
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
    return ['docProps/core.xml', 'ppt/theme/theme1.xml', 'ppt/slides/slide1.xml', 'ppt/notesSlides/notesSlide1.xml', 'ppt/charts/chart1.xml'];
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
        return (string) json_encode(dsdReadBytes(dsdZip($parts)));
    } catch (RuntimeException) {
        return '(refused)';
    }
}

/** The same deck with its slide size changed, so the size in the result proves the part was read. */
function dsdFourByThree(array $parts): array
{
    $parts['ppt/presentation.xml'] = preg_replace('/(<p:sldSz\b[^>]*\bcy=")\d+/', '${1}9144000', $parts['ppt/presentation.xml'], 1, $changed);
    expect($changed)->toBe(1);

    return $parts;
}

it('reads every marker from a clean deck, so a missing one means refused', function () {
    expect(substr_count(dsdRead(dsdPptx()), DSD_MARKER))->toBe(5);
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
    'slide rels, chart' => ['ppt/slides/_rels/slide1.xml.rels', '../charts/chart1.xml', '"c1"'],
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

        expect($slide)->not->toHaveKey('notes')->and($slide['elements'])->toHaveCount(2);
    });

    it('drops a chart whose part is refused and keeps the other elements', function () {
        $parts = dsdPptx();
        $parts['ppt/charts/chart1.xml'] = dsdWithDoctype($parts['ppt/charts/chart1.xml']);
        $elements = dsdReadBytes(dsdZip($parts))['slides'][0]['elements'];

        expect(array_column($elements, 'type'))->not->toContain('chart')->and(array_column($elements, 'id'))->toContain('t1');
    });

    it('reads a slide size from a clean presentation part, so its absence below is the refusal', function () {
        expect(dsdReadBytes(dsdZip(dsdFourByThree(dsdPptx())))['theme'])->toHaveKey('aspectRatio');
    });

    it('takes nothing from a refused presentation part', function () {
        $parts = dsdFourByThree(dsdPptx());
        $parts['ppt/presentation.xml'] = dsdWithDoctype($parts['ppt/presentation.xml']);

        expect(dsdReadBytes(dsdZip($parts))['theme'])->not->toHaveKey('aspectRatio');
    });

    it('takes no mono font from a refused theme', function () {
        $parts = dsdUnzip(Agent::toBytes([
            'id' => 'd',
            'title' => 't',
            'theme' => ['name' => 'default', 'fonts' => ['mono' => 'HSFACE']],
            'slides' => [['id' => 's1', 'layout' => 'blank', 'elements' => [
                ['id' => 'k1', 'type' => 'code', 'language' => 'text', 'code' => 'x', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.3],
            ]]],
        ]));
        $clean = dsdReadBytes(dsdZip($parts));
        $bare = $parts;
        unset($bare['ppt/theme/theme1.xml']);
        $absent = dsdReadBytes(dsdZip($bare));
        $parts['ppt/theme/theme1.xml'] = dsdWithDoctype($parts['ppt/theme/theme1.xml']);

        // A run in the recorded mono face reads as code, so the face changes the slide.
        expect($clean['slides'])->not->toBe($absent['slides'])
            ->and(dsdReadBytes(dsdZip($parts))['slides'])->toBe($absent['slides']);
    });
});

describe('the package declaration', function () {
    /** A template that says it carries macros, which is refused outright when its declarations are read. */
    function dsdMacroTemplate(): array
    {
        $parts = dsdPptx();
        expect($parts['[Content_Types].xml'])->toContain(DSD_MAIN);
        $parts['[Content_Types].xml'] = str_replace(DSD_MAIN, DSD_MACRO_TEMPLATE, $parts['[Content_Types].xml']);

        return $parts;
    }

    function dsdExpectRefused(array $parts): void
    {
        try {
            dsdReadBytes(dsdZip($parts));
        } catch (ArchiveRefused $e) {
            expect($e->reason)->toBe('archive_unsupported');

            return;
        }
        throw new RuntimeException('Expected the package to be refused; it was read.');
    }

    it('refuses a macro template for its declaration, so the cases below have something to skip', function () {
        expect(fn () => dsdReadBytes(dsdZip(dsdMacroTemplate())))->toThrow(OfficeTemplateRefused::class, 'macro_enabled');
    });

    it('reads a clean declaration in every encoding', function (string $encoding) {
        $parts = dsdPptx();
        $clean = dsdRead($parts);
        $parts['[Content_Types].xml'] = dsdEncodings()[$encoding]($parts['[Content_Types].xml']);

        expect(dsdRead($parts))->toBe($clean);
    })->with(array_keys(dsdEncodings()));

    it('still refuses the macro template when its declaration carries a DOCTYPE', function (string $encoding) {
        $parts = dsdMacroTemplate();
        $parts['[Content_Types].xml'] = dsdEncodings()[$encoding](dsdWithDoctype($parts['[Content_Types].xml']));

        dsdExpectRefused($parts);
    })->with(array_keys(dsdEncodings()));

    it('refuses a plain deck whose declaration carries a DOCTYPE', function (string $encoding) {
        $parts = dsdPptx();
        $parts['[Content_Types].xml'] = dsdEncodings()[$encoding](dsdWithDoctype($parts['[Content_Types].xml']));

        dsdExpectRefused($parts);
    })->with(array_keys(dsdEncodings()));

    it('refuses one the prolog check cannot read, by asking the parser', function () {
        $parts = dsdPptx();
        $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-7"?>'
            .'+ADw-+ACE-DOCTYPE r +AFs-+ADw-+ACE-ENTITY x +ACI-HSINT+ACI-+AD4-+AF0-+AD4-'
            .'+ADw-Types xmlns+AD0AIg-http://schemas.openxmlformats.org/package/2006/content-types+ACI-/+AD4-';
        $live = @simplexml_load_string($parts['[Content_Types].xml']);
        if ($live === false || $live->getName() !== 'Types') {
            $this->markTestSkipped('this libxml has no UTF-7 decoder, so there is nothing to refuse');
        }

        dsdExpectRefused($parts);
    });

    it('refuses the conversion too', function () {
        $parts = dsdPptx();
        $parts['[Content_Types].xml'] = dsdWithDoctype($parts['[Content_Types].xml']);

        expect(fn () => Agent::convertTemplate(dsdZip($parts)))->toThrow(ArchiveRefused::class);
    });

    it('leaves a malformed declaration handled as it always was', function () {
        $parts = dsdPptx();
        $clean = dsdRead($parts);
        $parts['[Content_Types].xml'] = '<Types>';

        expect(dsdRead($parts))->toBe($clean);
    });

    it('does not refuse a declaration for text that only looks like a DOCTYPE', function () {
        $parts = dsdPptx();
        $clean = dsdRead($parts);
        $parts['[Content_Types].xml'] = str_replace('</Types>', '<!-- <!DOCTYPE x> --></Types>', dsdWithDoctype($parts['[Content_Types].xml'], '<!-- <!DOCTYPE x> -->'));

        expect(dsdRead($parts))->toBe($clean);
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

    it('is kept in a chart title written as CDATA', function () {
        $parts = dsdPptx();
        $parts['ppt/charts/chart1.xml'] = str_replace(DSD_MARKER, '<![CDATA[<!DOCTYPE html>]]>', $parts['ppt/charts/chart1.xml']);
        $elements = dsdReadBytes(dsdZip($parts))['slides'][0]['elements'];

        expect($elements[1]['option']['title']['text'])->toBe('<!DOCTYPE html>');
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
