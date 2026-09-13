<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Fonts\FontEmbeddingException;
use DarkSlide\Tests\Support\GeneratedFont;

/**
 * Embedding the host's fonts, so brand typography survives a machine without them.
 *
 * Until this, a theme naming "Bebas Neue" was a request: anywhere the face was not
 * installed, the viewer substituted another, which also changed how wide every
 * line was. The fancy-labs document lab reported it on the first composition
 * trial ("fonts embedded in the file: NONE").
 *
 * What is pinned here and why each part matters:
 *
 *   - no font supplied means no byte changes, so the Node and Python engines'
 *     parity suites, which diff every part against this one, are untouched;
 *   - the package shape PowerPoint's own schema requires, and LibreOffice's
 *     export uses, down to the element order and relationship ids;
 *   - the EOT header, field by field, since that wrapper is what makes a
 *     `.fntdata` render (a raw font falls back);
 *   - refusal, all at once and before anything is written, for licences that
 *     forbid embedding and for files a deck could never use.
 *
 * The rendered proof lives in the last test, which needs LibreOffice and runs
 * when DARK_SLIDE_RENDER=1.
 */
function feDeck(): array
{
    return [
        'id' => 'fonts',
        'title' => 'Embedded fonts',
        'theme' => ['name' => 'probe', 'fonts' => ['heading' => 'Qvx Display', 'body' => 'Qvx Text']],
        'slides' => [[
            'id' => 's1',
            'layout' => 'blank',
            'elements' => [
                ['id' => 't', 'type' => 'text', 'x' => 0.1, 'y' => 0.3, 'w' => 0.8, 'h' => 0.3,
                    'content' => 'EMBEDDED FACE', 'style' => ['fontSize' => 96, 'fontFamily' => 'Qvx Display']],
            ],
        ]],
    ];
}

/** @return array<string, string> part name => contents */
function feParts(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'fe').'.pptx';
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

function feFontsOption(): array
{
    return [
        'Qvx Display' => [
            'regular' => GeneratedFont::build('Qvx Display'),
            'bold' => GeneratedFont::build('Qvx Display', 'Bold', weight: 700),
        ],
        'Qvx Text' => ['regular' => GeneratedFont::build('Qvx Text')],
    ];
}

it('changes nothing about a deck when no font is supplied', function () {
    $parts = feParts(Agent::toBytes(feDeck()));

    expect(array_filter(array_keys($parts), fn (string $n): bool => str_starts_with($n, 'ppt/fonts/')))->toBe([]);
    expect($parts['ppt/presentation.xml'])->toContain('saveSubsetFonts="1"');
    expect($parts['ppt/presentation.xml'])->not->toContain('embedTrueTypeFonts');
    expect($parts['ppt/presentation.xml'])->not->toContain('embeddedFontLst');
    expect($parts['[Content_Types].xml'])->not->toContain('fntdata');
});

it('writes the parts, relationships and font list PowerPoint expects', function () {
    $parts = feParts(Agent::toBytes(feDeck(), ['fonts' => feFontsOption()]));
    $presentation = $parts['ppt/presentation.xml'];

    // One slide: rId1 theme, rId2 slide, rId3 master, fonts from rId4.
    expect($presentation)->toContain('embedTrueTypeFonts="1"');
    expect($presentation)->not->toContain('saveSubsetFonts');
    expect($presentation)->toContain(
        '<p:notesSz cx="5143500" cy="9144000"/>'
        .'<p:embeddedFontLst>'
        .'<p:embeddedFont><p:font typeface="Qvx Display"/><p:regular r:id="rId4"/><p:bold r:id="rId5"/></p:embeddedFont>'
        .'<p:embeddedFont><p:font typeface="Qvx Text"/><p:regular r:id="rId6"/></p:embeddedFont>'
        .'</p:embeddedFontLst></p:presentation>'
    );

    $rels = $parts['ppt/_rels/presentation.xml.rels'];
    foreach ([4 => 'font1', 5 => 'font2', 6 => 'font3'] as $rid => $file) {
        expect($rels)->toContain('<Relationship Id="rId'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/'.$file.'.fntdata"/>');
        expect($parts)->toHaveKey("ppt/fonts/{$file}.fntdata");
    }
    expect($parts['[Content_Types].xml'])->toContain('<Default Extension="fntdata" ContentType="application/x-fontdata"/>');
});

it('wraps each font in an uncompressed Embedded OpenType header copied from the font', function () {
    $bold = GeneratedFont::build('Qvx Display', 'Bold', fsType: 0x0008, weight: 700);
    $parts = feParts(Agent::toBytes(feDeck(), ['fonts' => ['Qvx Display' => ['bold' => $bold]]]));
    $eot = $parts['ppt/fonts/font1.fntdata'];

    $header = unpack('VeotSize/VfontDataSize/Vversion/Vflags', $eot);
    expect($header['eotSize'])->toBe(strlen($eot));
    expect($header['fontDataSize'])->toBe(strlen($bold));
    expect($header['version'])->toBe(0x00020002);
    expect($header['flags'])->toBe(0); // no subsetting, no compression, no XOR

    $fields = unpack('Ccharset/Citalic/Vweight/vfsType/vmagic', $eot, 26);
    expect($fields)->toBe(['charset' => 1, 'italic' => 0, 'weight' => 700, 'fsType' => 0x0008, 'magic' => 0x504C]);

    // FamilyName: Padding1 at 80, size at 82, UTF-16LE with its trailing NUL.
    $size = unpack('v', $eot, 82)[1];
    expect(substr($eot, 84, $size))->toBe(implode('', array_map(fn (string $c): string => $c."\0", str_split("Qvx Display\0"))));

    // The font itself follows the header untouched.
    expect(substr($eot, -strlen($bold)))->toBe($bold);
});

it('accepts a font as a file path as well as bytes', function () {
    $path = sys_get_temp_dir().'/qvx-display-'.bin2hex(random_bytes(4)).'.ttf';
    file_put_contents($path, GeneratedFont::build('Qvx Display'));

    try {
        $parts = feParts(Agent::toBytes(feDeck(), ['fonts' => ['Qvx Display' => ['regular' => $path]]]));
    } finally {
        @unlink($path);
    }

    expect($parts)->toHaveKey('ppt/fonts/font1.fntdata');
});

it('embeds a font whose licence permits preview and print or editing', function () {
    foreach ([0x0000, 0x0004, 0x0008, 0x0002 | 0x0004] as $fsType) {
        $font = GeneratedFont::build('Qvx Display', fsType: $fsType);
        $parts = feParts(Agent::toBytes(feDeck(), ['fonts' => ['Qvx Display' => ['regular' => $font]]]));
        expect($parts)->toHaveKey('ppt/fonts/font1.fntdata');
    }
});

it('refuses a font whose licence forbids embedding', function (int $fsType, string $reason) {
    $font = GeneratedFont::build('Qvx Display', fsType: $fsType);

    expect(fn () => Agent::toBytes(feDeck(), ['fonts' => ['Qvx Display' => ['regular' => $font]]]))
        ->toThrow(FontEmbeddingException::class, $reason);
})->with([
    'restricted licence' => [0x0002, 'forbids embedding'],
    'bitmap embedding only' => [0x0200, 'bitmap embedding only'],
]);

it('refuses files it cannot embed, naming the typeface and variant', function (callable $font, string $reason) {
    expect(fn () => Agent::toBytes(feDeck(), ['fonts' => ['Qvx Display' => ['regular' => $font()]]]))
        ->toThrow(FontEmbeddingException::class, 'Qvx Display (regular): '.$reason);
})->with([
    'CFF OpenType' => [fn () => GeneratedFont::withTag(GeneratedFont::build('Qvx Display'), 'OTTO'), 'CFF-outline OpenType'],
    'font collection' => [fn () => GeneratedFont::withTag(GeneratedFont::build('Qvx Display'), 'ttcf'), 'a font collection'],
    'a different family' => [fn () => GeneratedFont::build('Somebody Else'), "the file's family name is 'Somebody Else', not 'Qvx Display'"],
    'a missing file' => [fn () => '/no/such/font.ttf', 'no font file at /no/such/font.ttf'],
]);

it('reports every problem at once and writes nothing', function () {
    $path = sys_get_temp_dir().'/fe-refused-'.bin2hex(random_bytes(4)).'.pptx';

    try {
        Agent::write(feDeck(), $path, ['fonts' => [
            'Qvx Display' => ['regular' => GeneratedFont::build('Qvx Display', fsType: 0x0002), 'heavy' => 'x'],
            'Qvx Text' => ['regular' => GeneratedFont::build('Wrong Name')],
        ]]);
        $this->fail('expected the write to be refused');
    } catch (FontEmbeddingException $e) {
        expect($e->getMessage())
            ->toContain("Qvx Display: 'heavy' is not a variant")
            ->toContain('Qvx Display (regular): its licence forbids embedding')
            ->toContain("Qvx Text (regular): the file's family name is 'Wrong Name'");
    }

    expect(file_exists($path))->toBeFalse();
});

it('reads back which typefaces and variants a file embeds, and never the bytes', function () {
    $path = sys_get_temp_dir().'/fe-read-'.bin2hex(random_bytes(4)).'.pptx';
    Agent::write(feDeck(), $path, ['fonts' => feFontsOption()]);

    try {
        $deck = Agent::read($path);
    } finally {
        @unlink($path);
    }

    expect($deck['metadata']['embeddedFonts'])->toBe([
        ['typeface' => 'Qvx Display', 'variants' => ['regular', 'bold']],
        ['typeface' => 'Qvx Text', 'variants' => ['regular']],
    ]);
});

it('reports no embedded fonts for a deck without them', function () {
    $path = sys_get_temp_dir().'/fe-none-'.bin2hex(random_bytes(4)).'.pptx';
    Agent::write(feDeck(), $path);

    try {
        $deck = Agent::read($path);
    } finally {
        @unlink($path);
    }

    expect($deck)->not->toHaveKey('metadata');
});

it('renders in the embedded face in LibreOffice, and falls back without it', function () {
    $soffice = getenv('DARK_SLIDE_SOFFICE') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/LibreOffice/program/soffice.com' : 'soffice');
    $dir = sys_get_temp_dir().'/fe-render-'.bin2hex(random_bytes(4));
    mkdir($dir);

    // A face no machine has, so only the embedded copy can produce it.
    $family = 'Qvx Render '.strtoupper(bin2hex(random_bytes(2)));
    $deck = feDeck();
    $deck['theme']['fonts'] = ['heading' => $family, 'body' => $family];
    $deck['slides'][0]['elements'][0]['style']['fontFamily'] = $family;

    Agent::write($deck, "{$dir}/embedded.pptx", ['fonts' => [$family => ['regular' => GeneratedFont::build($family)]]]);
    Agent::write($deck, "{$dir}/fallback.pptx");

    $profile = 'file:///'.ltrim(str_replace('\\', '/', $dir), '/').'/profile';
    $process = proc_open([$soffice, "-env:UserInstallation={$profile}", '--headless', '--convert-to', 'pdf', '--outdir', $dir, "{$dir}/embedded.pptx", "{$dir}/fallback.pptx"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);

    $postscriptName = str_replace(' ', '', $family).'-Regular';
    expect((string) @file_get_contents("{$dir}/embedded.pdf"))->toContain($postscriptName);
    expect((string) @file_get_contents("{$dir}/fallback.pdf"))->not->toContain($postscriptName);
    // The fallback must have rendered SOMETHING, or the line above proves nothing.
    expect((string) @file_get_contents("{$dir}/fallback.pdf"))->toContain('/BaseFont');
})->skip(getenv('DARK_SLIDE_RENDER') !== '1', 'set DARK_SLIDE_RENDER=1 (needs LibreOffice) to render through a real viewer');
