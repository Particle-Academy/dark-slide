<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Exceptions\ArchiveRefused;
use DarkSlide\Exceptions\OfficeTemplateRefused;
use DarkSlide\Reader\PptxReader;

/*
 * PowerPoint .potx templates.
 *
 * A .potx IS its .pptx twin with the main part (/ppt/presentation.xml)
 * declared `presentationml.template.main+xml` in [Content_Types].xml. The
 * reader marks `sourceKind: "template"`; the converter flips that one value
 * and copies every other zip member as its original record.
 *
 * dark-slide has no OpenDocument presentation reader, so .otp/.odp are not
 * handled here at all: the converter refuses them as `not_convertible`.
 *
 * Fixtures are built here with ZipArchive, independently of the production
 * converter, so a converter bug cannot also forge its own evidence.
 */

const DST_DOC = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';
const DST_TEMPLATE = 'application/vnd.openxmlformats-officedocument.presentationml.template.main+xml';
const DST_MACRO_TEMPLATE = 'application/vnd.ms-powerpoint.template.macroEnabled.main+xml';
const DST_MACRO_DOC = 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml';
const DST_WORD_TEMPLATE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';

function dstDeck(): array
{
    return [
        'id' => 'brand',
        'title' => 'Brand Deck',
        'theme' => ['name' => 'default'],
        'slides' => [[
            'id' => 's1',
            'layout' => 'blank',
            'elements' => [
                ['id' => 't1', 'type' => 'text', 'content' => 'Quarterly review', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2],
                ['id' => 'r1', 'type' => 'shape', 'shape' => 'rounded-rect', 'x' => 0.1, 'y' => 0.5, 'w' => 0.3, 'h' => 0.3, 'fill' => '#FF0000'],
            ],
            'notes' => 'Speaker notes survive',
        ]],
    ];
}

/** @return array<string, string> entry name => uncompressed bytes, in archive order */
function dstEntries(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'dst_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

function dstMethods(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'dst_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::RDONLY);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $out[$stat['name']] = $stat['comp_method'];
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

function dstZip(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'dst_').'.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $data) {
        $zip->addFromString($name, $data);
        if ($name === 'mimetype') {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function dstPptx(): string
{
    return Agent::toBytes(dstDeck());
}

/** The pptx with its main part re-declared; nothing else differs. */
function dstRetyped(string $contentType, array $extra = []): string
{
    $entries = dstEntries(dstPptx());
    $entries['[Content_Types].xml'] = str_replace(DST_DOC, $contentType, $entries['[Content_Types].xml']);

    return dstZip($entries + $extra);
}

function dstPotx(): string
{
    return dstRetyped(DST_TEMPLATE);
}

function dstRead(string $bytes): array
{
    return (new PptxReader())->fromBytes($bytes);
}

function dstRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (OfficeTemplateRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected a {$reason} refusal; the input was accepted.");
}

function dstArchiveRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (ArchiveRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected archive refusal {$reason}; the input was accepted.");
}

// ─── Reading ──────────────────────────────────────────────────────────────

it('reads a PowerPoint .potx and marks it as a template', function () {
    $document = dstRead(dstPptx());
    $template = dstRead(dstPotx());

    expect($template['sourceKind'] ?? null)->toBe('template')
        // The deck id digests CONTENT, and a template and its twin have the
        // same content: sourceKind is stamped after the digest, not in it.
        ->and($template['id'])->toBe($document['id']);
    unset($template['sourceKind']);
    expect($template)->toBe($document);
});

it('leaves a .pptx read exactly as it was: no sourceKind key', function () {
    expect(dstRead(dstPptx()))->not->toHaveKey('sourceKind');
});

it('reads a .potx from a path through Agent::read', function () {
    $path = tempnam(sys_get_temp_dir(), 'dst_').'.potx';
    file_put_contents($path, dstPotx());
    expect(Agent::read($path)['sourceKind'] ?? null)->toBe('template');
    unlink($path);
});

it('refuses a macro-enabled .potm by name rather than reading around the VBA', function () {
    dstRefused(fn () => dstRead(dstRetyped(DST_MACRO_TEMPLATE)), 'macro_enabled');
});

it('refuses a .potx that carries a VBA project anyway', function () {
    dstRefused(fn () => dstRead(dstRetyped(DST_TEMPLATE, ['ppt/vbaProject.bin' => "\xD0\xCF\x11\xE0 not really"])), 'macro_enabled');
});

it('still reads a macro-enabled PRESENTATION (.pptm) as before: only templates changed', function () {
    expect(dstRead(dstRetyped(DST_MACRO_DOC)))->not->toHaveKey('sourceKind');
});

// ─── Hostile input ────────────────────────────────────────────────────────

it('refuses a template whose content type claims another family', function () {
    // The presentation part declared as a Word template.
    dstRefused(fn () => dstRead(dstRetyped(DST_WORD_TEMPLATE)), 'family_mismatch');

    // A Word template declared beside intact PowerPoint parts.
    $entries = dstEntries(dstPptx());
    $entries['[Content_Types].xml'] = str_replace('</Types>',
        '<Override PartName="/word/document.xml" ContentType="'.DST_WORD_TEMPLATE.'"/></Types>',
        $entries['[Content_Types].xml']);
    dstRefused(fn () => dstRead(dstZip($entries)), 'family_mismatch');

    // Every .xml part declared a template through a Default.
    $entries = dstEntries(dstPptx());
    $entries['[Content_Types].xml'] = str_replace('ContentType="application/xml"',
        'ContentType="'.DST_TEMPLATE.'"', $entries['[Content_Types].xml']);
    dstRefused(fn () => dstRead(dstZip($entries)), 'family_mismatch');
});

it('refuses a template zip bomb through the archive guard, reading and converting', function () {
    $bomb = dstRetyped(DST_TEMPLATE, ['ppt/media/bomb.bin' => str_repeat("\0", 65 * 1024 * 1024)]);
    dstArchiveRefused(fn () => dstRead($bomb), 'archive_limit');
    dstArchiveRefused(fn () => Agent::convertTemplate($bomb), 'archive_limit');
});

it('refuses a template with a traversing entry name, reading and converting', function () {
    $evil = dstRetyped(DST_TEMPLATE, ['../evil.xml' => '<x/>']);
    dstArchiveRefused(fn () => dstRead($evil), 'archive_path');
    dstArchiveRefused(fn () => Agent::convertTemplate($evil), 'archive_path');
});

// ─── Converting ───────────────────────────────────────────────────────────

it('converts a .potx to a .pptx changing only the content-type value', function () {
    $potx = dstPotx();
    $pptx = Agent::convertTemplate($potx, 'document');

    $before = dstEntries($potx);
    $after = dstEntries($pptx);
    expect(array_keys($after))->toBe(array_keys($before));
    foreach ($before as $name => $data) {
        if ($name === '[Content_Types].xml') {
            expect($after[$name])->toBe(str_replace(DST_TEMPLATE, DST_DOC, $data));
        } else {
            expect($after[$name])->toBe($data, "{$name} changed");
        }
    }
    expect(dstRead($pptx))->not->toHaveKey('sourceKind');
});

it('round-trips pptx to potx and back with every part byte-identical', function () {
    $pptx = dstPptx();
    $potx = Agent::convertTemplate($pptx, 'template');
    $back = Agent::convertTemplate($potx, 'document');

    expect(dstRead($potx)['sourceKind'])->toBe('template')
        ->and(dstEntries($back))->toBe(dstEntries($pptx));

    $methods = dstMethods($pptx);
    unset($methods['[Content_Types].xml']);
    $converted = dstMethods($potx);
    unset($converted['[Content_Types].xml']);
    expect($converted)->toBe($methods);
});

it('flips direction when no target is given, and takes a path', function () {
    $path = tempnam(sys_get_temp_dir(), 'dst_').'.potx';
    file_put_contents($path, dstPotx());
    expect(dstRead(Agent::convertTemplate(dstPptx()))['sourceKind'])->toBe('template')
        ->and(dstRead(Agent::convertTemplate($path)))->not->toHaveKey('sourceKind');
    unlink($path);
});

it('refuses conversions it must not make', function () {
    dstRefused(fn () => Agent::convertTemplate(dstPotx(), 'template'), 'already_template');
    dstRefused(fn () => Agent::convertTemplate(dstPptx(), 'document'), 'already_document');
    dstRefused(fn () => Agent::convertTemplate(dstRetyped(DST_MACRO_TEMPLATE), 'document'), 'macro_enabled');
    dstRefused(fn () => Agent::convertTemplate(dstRetyped(DST_MACRO_DOC), 'template'), 'macro_enabled');
    dstRefused(fn () => Agent::convertTemplate(dstPptx(), 'spreadsheet'), 'bad_target');
    dstRefused(fn () => Agent::convertTemplate(dstRetyped(DST_WORD_TEMPLATE)), 'family_mismatch');
});

it('does not handle OpenDocument presentations: no ODF presentation reader exists here', function () {
    foreach (['application/vnd.oasis.opendocument.presentation', 'application/vnd.oasis.opendocument.presentation-template'] as $type) {
        $odf = dstZip(['mimetype' => $type, 'content.xml' => '<x/>']);
        dstRefused(fn () => Agent::convertTemplate($odf), 'not_convertible');
    }
});

it('refuses to convert a Word document it does not own', function () {
    $docx = dstZip([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml' => '<w:document/>',
    ]);
    dstRefused(fn () => Agent::convertTemplate($docx), 'not_convertible');
});

it('refuses a content-type declaration it cannot rewrite unambiguously', function () {
    $entries = dstEntries(dstPotx());
    $entries['[Content_Types].xml'] = str_replace(
        '<Override PartName="/ppt/presentation.xml"',
        '<!-- <Override PartName="/ppt/presentation.xml" ContentType="'.DST_TEMPLATE.'"/> --><Override PartName="/ppt/presentation.xml"',
        $entries['[Content_Types].xml'],
    );
    dstRefused(fn () => Agent::convertTemplate(dstZip($entries)), 'not_convertible');
});

// ─── CLI ──────────────────────────────────────────────────────────────────

function dstCli(array $args): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/dark-slide'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('converts through the template:convert CLI', function () {
    $dir = sys_get_temp_dir().'/dst-cli-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/in.potx", dstPotx());

    [$code, $stdout] = dstCli(['template:convert', "{$dir}/in.potx", "{$dir}/out.pptx"]);
    expect($code)->toBe(0)
        ->and($stdout)->toContain('document')
        ->and(Agent::read("{$dir}/out.pptx"))->not->toHaveKey('sourceKind');

    [$code, , $stderr] = dstCli(['template:convert', "{$dir}/out.pptx", "{$dir}/out.pptx", '--to=template']);
    expect($code)->toBe(1)->and($stderr)->toContain('exists');

    [$code] = dstCli(['template:convert', "{$dir}/out.pptx", "{$dir}/back.potx", '--to=template']);
    expect($code)->toBe(0)->and(Agent::read("{$dir}/back.potx")['sourceKind'])->toBe('template');

    file_put_contents("{$dir}/macro.potm", dstRetyped(DST_MACRO_TEMPLATE));
    [$code, , $stderr] = dstCli(['template:convert', "{$dir}/macro.potm", "{$dir}/macro.pptx"]);
    expect($code)->toBe(1)->and($stderr)->toContain('macro_enabled')
        ->and(file_exists("{$dir}/macro.pptx"))->toBeFalse();

    [$code, , $stderr] = dstCli(['template:convert']);
    expect($code)->toBe(2)->and($stderr)->toContain('Usage');

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

// ─── LibreOffice as an oracle (skipped where it is not installed) ─────────

function dstSoffice(): ?string
{
    $candidates = array_filter([getenv('LIBREOFFICE_BIN') ?: null,
        'C:/Program Files/LibreOffice/program/soffice.com', '/usr/bin/soffice', '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice']);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** Headless convert; the profile lives in a SHORT temp path (long ones crash LO on Windows). */
function dstLibreOffice(string $soffice, string $input, string $format, string $outDir): string
{
    $profile = sys_get_temp_dir().'/dslo'.bin2hex(random_bytes(3));
    $url = 'file:///'.ltrim(str_replace(chr(92), '/', $profile), '/');
    $process = proc_open([$soffice, '-env:UserInstallation='.$url, '--headless', '--norestore',
        '--convert-to', $format, '--outdir', $outDir, $input], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $out = $outDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.'.explode(':', $format)[0];
    expect($code)->toBe(0)->and(is_file($out))->toBeTrue("LibreOffice produced no {$format}");

    return $out;
}

it('reads what LibreOffice writes as .potx, and LibreOffice opens our conversion', function () {
    $soffice = dstSoffice();
    if ($soffice === null) {
        $this->markTestSkipped('LibreOffice is not installed; the oracle check runs where it is.');
    }
    $dir = sys_get_temp_dir().'/dso'.bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents("{$dir}/src.pptx", dstPptx());

    $loTemplate = dstLibreOffice($soffice, "{$dir}/src.pptx", 'potx', $dir);
    $read = Agent::read($loTemplate);
    expect($read['sourceKind'] ?? null)->toBe('template', 'LibreOffice .potx not marked')
        ->and(json_encode($read))->toContain('Quarterly review');

    // LibreOffice's own template back to its document twin: not a template.
    mkdir("{$dir}/lo");
    $loBack = dstLibreOffice($soffice, $loTemplate, 'pptx', "{$dir}/lo");
    expect(Agent::read($loBack))->not->toHaveKey('sourceKind');

    // OUR conversion of LibreOffice's template, opened by LibreOffice.
    file_put_contents("{$dir}/ours.pptx", Agent::convertTemplate($loTemplate, 'document'));
    mkdir("{$dir}/ours");
    $odp = dstLibreOffice($soffice, "{$dir}/ours.pptx", 'odp', "{$dir}/ours");
    expect(dstEntries((string) file_get_contents($odp))['content.xml'])->toContain('Quarterly review');
})->group('libreoffice');
