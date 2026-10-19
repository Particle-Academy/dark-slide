<?php

declare(strict_types=1);

namespace DarkSlide\Reader;

use DarkSlide\Helpers\Emu;
use HashContext;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * @phpstan-type Slide array<string, mixed>
 */

/**
 * Best-effort PPTX → Deck reader. Extracts text, image, shape, and
 * (v0.3+) table elements with their geometry; preserves slide notes;
 * preserves gradient + solid backgrounds; reconstructs **bold** /
 * *italic* / `code` markdown spans from drawingML runs; emits embedded
 * image bytes as data URIs. Ignores parts of the PPTX surface we can't
 * represent in the Deck schema (animations, complex masters, embedded
 * fonts, etc.).
 *
 * Agent-emitted decks from DarkSlide round-trip with high fidelity;
 * hand-authored PowerPoint files may drop styling we don't model.
 *
 * **read() is a pure function of its bytes.** The same package read twice —
 * in the same second or a year apart, here or on another machine — comes back
 * as an identical structure, down to every generated id. That is a contract
 * rather than a property of the current code: consumers store reads and diff
 * them, and one clock- or RNG-derived field turns a diff of unchanged content
 * into a whole-deck replace. Nothing here may put the clock, a random number,
 * the environment or a temp path into a value it returns.
 *
 * **The id is a function of the CONTENT, never of the package bytes.** That
 * distinction took three attempts to state correctly, so it is worth being
 * blunt about: a digest of the bytes identifies a SERIALISATION, and two
 * serialisations of one deck are not byte-equal. 0.10.1 hashed the whole
 * package, which followed the writer's clock. 0.10.2 excluded the clock-bearing
 * part, which removed ONE source of byte variance and left the rest — a deck
 * carrying a shape or a code block still re-serialises to different
 * `ppt/slides/slideN.xml` bytes, so the id still moved while the structure sat
 * perfectly still. `contentDigest()` hashes what this method RETURNS.
 *
 * The consequence to hold on to: **any two byte layouts that read to the same
 * structure get the same id.** It does NOT follow that a PowerPoint-authored
 * file and a DarkSlide-authored file of "the same deck" agree — that holds only
 * as far as `read()` normalises them to the same structure, which is not
 * promised here and is not what this guarantees.
 */
final class PptxReader
{
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    /** Current slide's relationships, keyed by rId -> ['type' => ..., 'target' => ...]. */
    private array $currentSlideRels = [];

    /**
     * The mono typeface this deck was written with, read back from the theme's
     * `<a:extLst>`. Empty when the package does not record one — anything not
     * written by a current DarkSlide — in which case the name sniff below is
     * the only signal available.
     */
    private string $monoTypeface = '';

    /** ZipArchive being read; held during a single read() call so parsePic can resolve media. */
    private ?ZipArchive $currentZip = null;


    /**
     * The 1-based number of the slide being parsed, and how many fallback ids
     * have been minted for it. Together they replace a `random_int()` fallback
     * for elements whose `<p:cNvPr>` carries no `name`. Numbering PER SLIDE is
     * deliberate: inserting one shape into slide 1 then shifts only slide 1's
     * ids instead of renumbering every element after it, which would turn a
     * one-element edit into a whole-deck diff — the same failure the clock id
     * caused, reached by an edit rather than by time.
     */
    private int $slideNumber = 0;

    private int $slideFallbackIds = 0;

    /**
     * The slide size from `<p:sldSz>`, so geometry comes back as fractions of
     * THIS slide. Every conversion used to assume 16:9 at 10in, which read a
     * 4:3 deck's positions back wrong.
     */
    private int $slideWidthEmu = Emu::DEFAULT_SLIDE_WIDTH;

    private int $slideHeightEmu = Emu::DEFAULT_SLIDE_HEIGHT;

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Could not read file: {$path}");
        }

        return $this->fromBytes($bytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromBytes(string $bytes): array
    {
        // Everything this read returns is derived from these bytes, here or
        // below. The counters start over on every call because one reader
        // instance may be handed a second file.
        $this->slideNumber = 0;
        $this->slideFallbackIds = 0;

        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'dark-slide-read-' . bin2hex(random_bytes(8));
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not open zip archive.');
        }

        try {
            $this->currentZip = $zip;
            $deck = $this->extract($zip);
            // Stamped HERE rather than inside extract() because extract() has
            // early returns for a malformed package, and an id that some return
            // paths skip is worse than one that is wrong.
            $deck['id'] = 'imported-' . $this->contentDigest($deck);
        } finally {
            $this->currentZip = null;
            $zip->close();
            @unlink($tmp);
        }

        return $deck;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(ZipArchive $zip): array
    {
        $deck = [
            // Filled in by fromBytes() once the deck is complete — the digest is
            // over the content, so it cannot exist before the content does.
            // Declared first so the returned key order is unchanged.
            'id' => '',
            'title' => $this->readCoreTitle($zip) ?? 'Imported',
            'theme' => ['name' => 'imported'],
            'slides' => [],
        ];

        $this->monoTypeface = $this->readMonoTypeface($zip);
        $deck['theme'] += $this->readTheme($zip);
        $this->readSlideSize($zip);
        // 16:9 at 10in is the default and says nothing; any other shape is part
        // of the deck and comes back as its aspect ratio.
        if ($this->slideWidthEmu !== Emu::DEFAULT_SLIDE_WIDTH || $this->slideHeightEmu !== Emu::DEFAULT_SLIDE_HEIGHT) {
            // Always a float: `int / int` in PHP is an int when it divides
            // exactly, so a 2:1 slide read back as `2` and a 4:3 one as a float.
            $deck['theme']['aspectRatio'] = (float) $this->slideWidthEmu / $this->slideHeightEmu;
        }

        // Walk the presentation rel list in order to find slide ids.
        $presentationRels = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if ($presentationRels === false) {
            return $deck;
        }
        $slideTargets = $this->extractSlideTargets($presentationRels);

        foreach ($slideTargets as $i => $slideTarget) {
            $slideXml = $zip->getFromName('ppt/' . $slideTarget);
            if ($slideXml === false) {
                continue;
            }
            $slideRels = $zip->getFromName('ppt/' . dirname($slideTarget) . '/_rels/' . basename($slideTarget) . '.rels') ?: '';
            $notes = $this->readNotesFor($zip, $slideRels);
            $this->currentSlideRels = $this->parseSlideRels($slideRels, $slideTarget);
            $this->slideNumber = $i + 1;
            $this->slideFallbackIds = 0;

            $slide = $this->parseSlide($slideXml, 'imported-slide-' . ($i + 1), $notes);
            $deck['slides'][] = $slide;
        }

        // Only when the file embeds any, so every other read is unchanged.
        $embeddedFonts = $this->readEmbeddedFonts($zip);
        if ($embeddedFonts !== []) {
            $deck['metadata'] = ['embeddedFonts' => $embeddedFonts];
        }

        return $deck;
    }

    /**
     * Parse a slide's `_rels/slideN.xml.rels` into a flat map keyed by rId.
     * Targets are normalized to absolute paths in the zip ("ppt/media/imageN.png").
     *
     * @return array<string, array{type: string, target: string}>
     */
    private function parseSlideRels(string $relsXml, string $slideTargetRelative): array
    {
        if ($relsXml === '') {
            return [];
        }
        $sx = @simplexml_load_string($relsXml);
        if ($sx === false) {
            return [];
        }
        $ns = $sx->getNamespaces(true);
        $default = $ns[''] ?? null;
        if ($default === null) {
            return [];
        }
        $sx->registerXPathNamespace('r', $default);

        // The slide lives at ppt/slides/slideN.xml; its rels resolve relative
        // to ppt/slides/, so "../media/image1.png" → "ppt/media/image1.png".
        $slideDirAbs = 'ppt/' . dirname($slideTargetRelative);
        $rels = [];
        foreach ($sx->xpath('//r:Relationship') ?: [] as $r) {
            $id = (string) $r['Id'];
            $type = (string) $r['Type'];
            $target = (string) $r['Target'];
            $resolved = $this->resolveRelTarget($slideDirAbs, $target);
            $rels[$id] = ['type' => $type, 'target' => $resolved];
        }

        return $rels;
    }

    private function resolveRelTarget(string $baseDir, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $stack = explode('/', $baseDir);
        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($stack);
            } elseif ($segment !== '.' && $segment !== '') {
                $stack[] = $segment;
            }
        }

        return implode('/', $stack);
    }

    /**
     * @return list<string>
     */
    private function extractSlideTargets(string $relsXml): array
    {
        $targets = [];
        $xml = @simplexml_load_string($relsXml);
        if ($xml === false) {
            return [];
        }
        $ns = $xml->getNamespaces(true);
        $default = $ns[''] ?? null;
        if ($default !== null) {
            $xml->registerXPathNamespace('r', $default);
            foreach ($xml->xpath('//r:Relationship') ?: [] as $r) {
                $type = (string) $r['Type'];
                if (str_ends_with($type, '/slide')) {
                    $targets[] = (string) $r['Target'];
                }
            }
        }

        return $targets;
    }

    /**
     * The mono typeface recorded in `theme1.xml`'s `<a:extLst>`, or `''`.
     *
     * Deliberately a regex rather than a parse: this runs before the deck is
     * built, the element is one attribute deep, and a theme part that does not
     * carry the extension is the common case rather than an error.
     */
    private function readMonoTypeface(ZipArchive $zip): string
    {
        $xml = $zip->getFromName('ppt/theme/theme1.xml');
        if ($xml === false) {
            return '';
        }

        return preg_match('/<ds:monoFont[^>]*typeface="([^"]*)"/', $xml, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
            : '';
    }

    /**
     * The theme's colours and fonts, in the deck's own keys: background = lt1,
     * text = dk1, accent = accent1, muted = dk2, surface = lt2, heading = the
     * major font, body = the minor font (the mapping the writer uses).
     *
     * A colour the part does not state as an sRGB value (or a system colour
     * with a last-known value) is left out rather than guessed. The writer
     * always states every one, so its own decks come back complete.
     *
     * @return array{colors?: array<string, string>, fonts?: array<string, string>}
     */
    private function readTheme(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('ppt/theme/theme1.xml');
        $sx = $xml === false || stripos($xml, '<!DOCTYPE') !== false ? false : @simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $sx->registerXPathNamespace('a', self::NS_A);

        $colors = [];
        foreach (['background' => 'lt1', 'text' => 'dk1', 'accent' => 'accent1', 'muted' => 'dk2', 'surface' => 'lt2'] as $key => $slot) {
            $node = $sx->xpath('//a:clrScheme/a:'.$slot)[0] ?? null;
            $hex = $node === null ? null : $this->schemeHex($node);
            if ($hex !== null) {
                $colors[$key] = '#'.$hex;
            }
        }

        $fonts = [];
        foreach (['heading' => 'majorFont', 'body' => 'minorFont'] as $key => $slot) {
            $latin = $sx->xpath('//a:fontScheme/a:'.$slot.'/a:latin')[0] ?? null;
            $face = $latin === null ? '' : (string) $latin['typeface'];
            if ($face !== '') {
                $fonts[$key] = $face;
            }
        }
        // Only a NAMED mono face. The writer records Consolas when a deck names
        // none, and absent and Consolas write the same bytes, so reporting it
        // would make a foreign deck gain a key on its first trip through ours
        // and change its id: read must be a fixed point of write-then-read.
        if ($this->monoTypeface !== '' && $this->monoTypeface !== 'Consolas') {
            $fonts['mono'] = $this->monoTypeface;
        }

        $out = [];
        if ($colors !== []) {
            $out['colors'] = $colors;
        }
        if ($fonts !== []) {
            $out['fonts'] = $fonts;
        }

        return $out;
    }

    /** A colour slot's sRGB value (or a system colour's last-known one), upper-case, or null. */
    private function schemeHex(SimpleXMLElement $slot): ?string
    {
        $slot->registerXPathNamespace('a', self::NS_A);
        $srgb = $slot->xpath('a:srgbClr')[0] ?? null;
        $sys = $slot->xpath('a:sysClr')[0] ?? null;
        $val = strtoupper($srgb !== null ? (string) $srgb['val'] : ($sys !== null ? (string) $sys['lastClr'] : ''));

        return preg_match('/^[0-9A-F]{6}$/', $val) === 1 ? $val : null;
    }

    /**
     * Design pixels (on the 1920 canvas the reader reports) from hundredths of
     * a point, to 2 decimals; an int when whole, so 88 reads as 88, not 88.0.
     */
    private static function pxFromHundredths(int $hundredths): int|float
    {
        return self::wholeOrFloat(round($hundredths * 2 / 75, 2));
    }

    /** Design pixels from EMU (12700 per point), to 2 decimals. */
    private static function pxFromEmu(int $emu): int|float
    {
        return self::wholeOrFloat(round($emu * 8 / 38100, 2));
    }

    private static function wholeOrFloat(float $v): int|float
    {
        return $v === floor($v) ? (int) $v : $v;
    }

    /**
     * A `<a:solidFill>` as the deck writes colours: `#RRGGBB`, or
     * `rgba(r,g,b,a)` when it carries an alpha below opaque. The alpha is
     * formatted from the integer (thousandths of a percent), never through a
     * float, so the three engines print the same digits.
     */
    private function solidFillColor(SimpleXMLElement $solidFill): ?string
    {
        $solidFill->registerXPathNamespace('a', self::NS_A);
        $clr = $solidFill->xpath('a:srgbClr')[0] ?? null;
        if ($clr === null) {
            return null;
        }
        $hex = strtoupper((string) $clr['val']);
        if (preg_match('/^[0-9A-F]{6}$/', $hex) !== 1) {
            return null;
        }
        $clr->registerXPathNamespace('a', self::NS_A);
        $alphaNode = $clr->xpath('a:alpha')[0] ?? null;
        $alpha = $alphaNode !== null && ctype_digit((string) $alphaNode['val']) ? (int) $alphaNode['val'] : 100000;
        if ($alpha >= 100000) {
            return '#'.$hex;
        }
        $fraction = rtrim(str_pad((string) $alpha, 5, '0', STR_PAD_LEFT), '0');

        return sprintf('rgba(%d,%d,%d,%s)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $fraction === '' ? '0' : '0.'.$fraction);
    }

    /** The first direct child of $parent with this a: local name. */
    private function aChild(SimpleXMLElement $parent, string $name): ?SimpleXMLElement
    {
        foreach ($parent->children(self::NS_A) as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * A shape's fill and outline, in the deck's keys. `noFill` is stated as
     * `transparent`, because an absent `fill` writes the default tint and an
     * absent `stroke` the theme accent: leaving them out would ADD paint.
     *
     * @return array<string, mixed>
     */
    private function shapePaint(SimpleXMLElement $sp): array
    {
        $spPr = null;
        foreach ($sp->children(self::NS_P) as $child) {
            if ($child->getName() === 'spPr') {
                $spPr = $child;
            }
        }
        if ($spPr === null) {
            return [];
        }

        $out = [];
        if ($this->aChild($spPr, 'noFill') !== null) {
            $out['fill'] = 'transparent';
        } elseif (($solid = $this->aChild($spPr, 'solidFill')) !== null && ($color = $this->solidFillColor($solid)) !== null) {
            $out['fill'] = $color;
        }

        $ln = $this->aChild($spPr, 'ln');
        if ($ln !== null) {
            if ($this->aChild($ln, 'noFill') !== null) {
                $out['stroke'] = 'transparent';
            } elseif (($solid = $this->aChild($ln, 'solidFill')) !== null && ($color = $this->solidFillColor($solid)) !== null) {
                $out['stroke'] = $color;
                // attributes(), not $ln['w']: an element reached through
                // children(NS_A) looks its attributes up in that namespace.
                $w = (string) ($ln->attributes()['w'] ?? '');
                if (ctype_digit($w)) {
                    $out['strokeWidth'] = self::pxFromEmu((int) $w);
                }
                if ($this->aChild($ln, 'prstDash') !== null) {
                    $out['dashed'] = true;
                }
            }
        }

        return $out;
    }

    /**
     * A text body's element-level style: what every run shares, plus the first
     * paragraph's alignment and the box's vertical anchor. Code runs (the mono
     * face) are not the element's style and are skipped.
     *
     * Bold and italic count only when EVERY text run has them; then they are
     * the element's style and the markdown carries no markers for them.
     *
     * @return array<string, mixed>
     */
    private function textStyle(SimpleXMLElement $tBody, array $runs): array
    {
        $style = [];
        $first = null;
        foreach ($runs as $run) {
            if ($run['text'] !== '' && ! $run['code']) {
                $first = $run;
                break;
            }
        }
        if ($first !== null && $first['sz'] !== null) {
            $style['fontSize'] = self::pxFromHundredths($first['sz']);
        }
        $text = array_values(array_filter($runs, static fn (array $r): bool => $r['text'] !== ''));
        if ($text !== [] && array_reduce($text, static fn (bool $all, array $r): bool => $all && $r['b'], true)) {
            $style['weight'] = 700;
        }
        if ($text !== [] && array_reduce($text, static fn (bool $all, array $r): bool => $all && $r['i'], true)) {
            $style['italic'] = true;
        }
        if ($text !== [] && array_reduce($text, static fn (bool $all, array $r): bool => $all && $r['u'], true)) {
            $style['underline'] = true;
        }
        if ($first !== null && $first['color'] !== null) {
            $style['color'] = $first['color'];
        }
        if ($first !== null && $first['font'] !== null) {
            $style['fontFamily'] = $first['font'];
        }

        $tBody->registerXPathNamespace('a', self::NS_A);
        $algn = (string) (($tBody->xpath('a:p[1]/a:pPr')[0] ?? null)['algn'] ?? '');
        $align = match ($algn) {
            'ctr' => 'center',
            'r' => 'right',
            'just' => 'justify',
            default => null,
        };
        if ($align !== null) {
            $style['align'] = $align;
        }
        $anchor = (string) (($tBody->xpath('a:bodyPr')[0] ?? null)['anchor'] ?? '');
        $vertical = match ($anchor) {
            'ctr' => 'middle',
            'b' => 'bottom',
            default => null,
        };
        if ($vertical !== null) {
            $style['verticalAlign'] = $vertical;
        }

        return $style;
    }

    private function readSlideSize(ZipArchive $zip): void
    {
        $this->slideWidthEmu = Emu::DEFAULT_SLIDE_WIDTH;
        $this->slideHeightEmu = Emu::DEFAULT_SLIDE_HEIGHT;

        $xml = $zip->getFromName('ppt/presentation.xml');
        if ($xml === false || ! preg_match('/<p:sldSz\b[^>]*>/', $xml, $tag)) {
            return;
        }
        if (preg_match('/\bcx="(\d+)"/', $tag[0], $cx) && (int) $cx[1] > 0) {
            $this->slideWidthEmu = (int) $cx[1];
        }
        if (preg_match('/\bcy="(\d+)"/', $tag[0], $cy) && (int) $cy[1] > 0) {
            $this->slideHeightEmu = (int) $cy[1];
        }
    }

    private function fracX(int $emu): float
    {
        return Emu::toFracX($emu, $this->slideWidthEmu);
    }

    private function fracY(int $emu): float
    {
        return Emu::toFracY($emu, $this->slideHeightEmu);
    }

    /**
     * The typefaces the file embeds and which of the four variants each carries.
     *
     * Names and variants only, never the font bytes: a reader's output is a deck,
     * and a deck is agent-facing JSON that has no business holding licensed
     * binaries.
     *
     * @return list<array{typeface: string, variants: list<string>}>
     */
    private function readEmbeddedFonts(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('ppt/presentation.xml');
        if ($xml === false || ! preg_match('/<p:embeddedFontLst>(.*?)<\/p:embeddedFontLst>/s', $xml, $list)) {
            return [];
        }

        $fonts = [];
        preg_match_all('/<p:embeddedFont>(.*?)<\/p:embeddedFont>/s', $list[1], $entries);
        foreach ($entries[1] as $entry) {
            if (! preg_match('/<p:font\b[^>]*\btypeface="([^"]*)"/', $entry, $face)) {
                continue;
            }
            preg_match_all('/<p:(regular|bold|italic|boldItalic)\b/', $entry, $variants);
            $fonts[] = [
                'typeface' => html_entity_decode($face[1], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                'variants' => $variants[1],
            ];
        }

        return $fonts;
    }

    private function readCoreTitle(ZipArchive $zip): ?string
    {
        $xml = $zip->getFromName('docProps/core.xml');
        if ($xml === false) {
            return null;
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return null;
        }
        $sx->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $hits = $sx->xpath('//dc:title');
        if (!empty($hits)) {
            return (string) $hits[0];
        }

        return null;
    }

    private function readNotesFor(ZipArchive $zip, string $slideRelsXml): ?string
    {
        if ($slideRelsXml === '') {
            return null;
        }
        $sx = @simplexml_load_string($slideRelsXml);
        if ($sx === false) {
            return null;
        }
        $ns = $sx->getNamespaces(true);
        $default = $ns[''] ?? null;
        if ($default === null) {
            return null;
        }
        $sx->registerXPathNamespace('r', $default);
        foreach ($sx->xpath('//r:Relationship') ?: [] as $r) {
            if (str_ends_with((string) $r['Type'], '/notesSlide')) {
                $target = (string) $r['Target'];
                $notesXml = $zip->getFromName('ppt/' . ltrim(str_replace('../', '', $target), '/'));
                if ($notesXml === false) {
                    return null;
                }

                return $this->parseNotesText($notesXml);
            }
        }

        return null;
    }

    private function parseNotesText(string $xml): string
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return '';
        }
        $sx->registerXPathNamespace('a', self::NS_A);
        $parts = [];
        foreach ($sx->xpath('//a:t') ?: [] as $t) {
            $parts[] = (string) $t;
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSlide(string $xml, string $id, ?string $notes): array
    {
        $slide = [
            'id' => $id,
            'layout' => 'blank',
            'elements' => [],
        ];
        if ($notes !== null && $notes !== '') {
            $slide['notes'] = $notes;
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return $slide;
        }
        $sx->registerXPathNamespace('p', self::NS_P);
        $sx->registerXPathNamespace('a', self::NS_A);

        $bg = $this->parseBackground($sx);
        if ($bg !== null) {
            $slide['background'] = $bg;
        }

        $shapes = $sx->xpath('//p:sp') ?: [];
        foreach ($shapes as $shape) {
            $element = $this->parseShape($shape);
            if ($element !== null) {
                $slide['elements'][] = $element;
            }
        }
        $pics = $sx->xpath('//p:pic') ?: [];
        foreach ($pics as $pic) {
            $element = $this->parsePic($pic);
            if ($element !== null) {
                $slide['elements'][] = $element;
            }
        }
        $graphicFrames = $sx->xpath('//p:graphicFrame') ?: [];
        foreach ($graphicFrames as $gf) {
            $element = $this->parseGraphicFrame($gf);
            if ($element !== null) {
                $slide['elements'][] = $element;
            }
        }

        return $slide;
    }

    /**
     * Extract the slide's background. Recognizes:
     *  - solid fill   → ['color' => '#hex']
     *  - gradient     → ['gradient' => 'linear-gradient(...)']
     *  - blipFill     → ['image' => 'data:...']
     * Returns null when no background is present.
     *
     * @return array<string, mixed>|null
     */
    private function parseBackground(SimpleXMLElement $sx): ?array
    {
        $sx->registerXPathNamespace('p', self::NS_P);
        $sx->registerXPathNamespace('a', self::NS_A);

        $bgPrList = $sx->xpath('//p:bg/p:bgPr');
        if (empty($bgPrList)) {
            return null;
        }
        $bgPr = $bgPrList[0];
        $bgPr->registerXPathNamespace('a', self::NS_A);

        // Solid fill
        $solid = $bgPr->xpath('.//a:solidFill/a:srgbClr');
        if (!empty($solid)) {
            $hex = (string) $solid[0]['val'];
            if ($hex !== '') {
                return ['color' => '#' . $hex];
            }
        }

        // Gradient fill
        $grad = $bgPr->xpath('.//a:gradFill');
        if (!empty($grad)) {
            $css = $this->gradFillToCss($grad[0]);
            if ($css !== null) {
                return ['gradient' => $css];
            }
        }

        // Image (blipFill)
        $blip = $bgPr->xpath('.//a:blipFill/a:blip');
        if (!empty($blip)) {
            $blip[0]->registerXPathNamespace('r', self::NS_R);
            $rid = (string) $blip[0]->attributes(self::NS_R)['embed'];
            if ($rid !== '' && isset($this->currentSlideRels[$rid])) {
                $dataUri = $this->readMediaAsDataUri($this->currentSlideRels[$rid]['target']);
                if ($dataUri !== null) {
                    return ['image' => $dataUri];
                }
            }
        }

        return null;
    }

    /**
     * Convert an `<a:gradFill>` block back to a CSS `linear-gradient(...)`
     * string. Inverts the writer's angle math (PPTX clockwise-from-east
     * 60000ths → CSS clockwise-from-north degrees).
     */
    private function gradFillToCss(SimpleXMLElement $grad): ?string
    {
        $grad->registerXPathNamespace('a', self::NS_A);
        $stops = $grad->xpath('.//a:gs');
        if (empty($stops)) {
            return null;
        }

        $stopStrings = [];
        foreach ($stops as $stop) {
            $pos = (int) $stop['pos']; // 0..100000
            $pct = round($pos / 1000, 1);
            $color = $stop->xpath('.//a:srgbClr');
            if (empty($color)) {
                continue;
            }
            $hex = (string) $color[0]['val'];
            if ($hex === '') {
                continue;
            }
            $stopStrings[] = '#' . strtolower($hex) . ' ' . (string) $pct . '%';
        }
        if (empty($stopStrings)) {
            return null;
        }

        $lin = $grad->xpath('.//a:lin');
        $angle = 180; // CSS default — top to bottom
        if (!empty($lin)) {
            $pptxAng = (int) $lin[0]['ang']; // 60000ths from east
            $deg = ($pptxAng / 60000) + 90;
            $angle = (int) round((($deg % 360) + 360) % 360);
        }

        return 'linear-gradient(' . $angle . 'deg, ' . implode(', ', $stopStrings) . ')';
    }

    /**
     * Resolve an `r:embed` rel → media archive entry → data URI string.
     */
    private function readMediaAsDataUri(string $archivePath): ?string
    {
        if ($this->currentZip === null) {
            return null;
        }
        $bytes = $this->currentZip->getFromName($archivePath);
        if ($bytes === false) {
            return null;
        }
        $mime = $this->guessMimeFromArchivePath($archivePath);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function guessMimeFromArchivePath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * The deck id: CRC-32 over a canonical encoding of the DECK, as eight
     * lowercase hex digits. Not of the package — see the note on this class.
     *
     * `id` is removed first, because it is the value being computed. Nothing
     * else is removed: every other field is either read out of the file or
     * derived deterministically from it (`imported-slide-N`, and an element's
     * positional fallback id), so all of it is content.
     */
    private function contentDigest(array $deck): string
    {
        unset($deck['id']);

        $ctx = hash_init('crc32b');
        $this->canonicalize($deck, $ctx);

        return hash_final($ctx);
    }

    /**
     * Feed one value to the digest in a form all three engines agree on.
     *
     * This is a CANONICAL ENCODING and its rules are the contract, not an
     * implementation detail — the Node and Python ports reimplement it and the
     * reader-parity suites compare the resulting id, so a divergence here is a
     * divergence in the id. Spelled out:
     *
     *   null          `~`
     *   true / false  `T` / `F`
     *   number        `#` then the eight bytes of the IEEE-754 binary64, big endian
     *   string        `s`, the UTF-8 BYTE length in decimal, `:`, then the bytes
     *   empty array   `e`
     *   list          `[` then each item, then `]`
     *   map           `{` then each key then its value, keys ascending, then `}`
     *
     * Three of those choices are load-bearing:
     *
     * **Numbers go in as raw IEEE bits, never as text.** The three languages
     * disagree about the TYPE of a number — PHP's `int / int` is an int when it
     * divides exactly, Python's `/` is always a float, JS has only doubles — and
     * about how a float RENDERS: PHP's depends on the `serialize_precision` ini
     * setting, which a consumer can change underneath us. Bit patterns have no
     * such freedom. Two finite doubles that compare equal have identical bits,
     * and both parity suites already assert the engines read numerically equal
     * values, so agreement here follows from a property that is already tested.
     * `-0.0` is the one exception to that and is normalised; non-finite values
     * cannot occur in a deck and are mapped to a marker rather than trusted.
     *
     * **Strings are length-prefixed**, so there is no escaping convention for
     * three languages to agree on.
     *
     * **An empty array and an empty map collapse to ONE marker.** PHP cannot
     * tell them apart — `[]` is both — while Python and JS can, so a table row
     * with no cells is `[]` here and `{}` there. Both parity suites already
     * normalise the two together, which is the estate deciding that distinction
     * is not meaningful; a digest depending on it would depend on something
     * already ruled meaningless.
     *
     * Keys are sorted rather than taken in insertion order, and by BYTE value:
     * PHP's `SORT_STRING` and Python's `sorted()` are byte order for UTF-8, and
     * JS's default sort matches for everything below U+10000. Every key a read
     * deck contains is machine-generated ASCII, which `ReaderIsPureTest` checks
     * rather than assumes.
     */
    private function canonicalize(mixed $value, HashContext $ctx): void
    {
        if ($value === null) {
            hash_update($ctx, '~');

            return;
        }
        if (is_bool($value)) {
            hash_update($ctx, $value ? 'T' : 'F');

            return;
        }
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            if (!is_finite($number)) {
                hash_update($ctx, '?');

                return;
            }
            if ($number == 0.0) {
                // -0.0 and 0.0 are equal but not bit-equal, and either can be
                // reached. Collapse to +0.0 so the engines cannot disagree.
                $number = 0.0;
            }
            hash_update($ctx, '#');
            hash_update($ctx, pack('E', $number));

            return;
        }
        if (is_string($value)) {
            hash_update($ctx, 's' . strlen($value) . ':');
            hash_update($ctx, $value);

            return;
        }
        if (is_array($value)) {
            if ($value === []) {
                hash_update($ctx, 'e');

                return;
            }
            if (array_is_list($value)) {
                hash_update($ctx, '[');
                foreach ($value as $item) {
                    $this->canonicalize($item, $ctx);
                }
                hash_update($ctx, ']');

                return;
            }
            $keys = array_map('strval', array_keys($value));
            sort($keys, SORT_STRING);
            hash_update($ctx, '{');
            foreach ($keys as $key) {
                $this->canonicalize($key, $ctx);
                $this->canonicalize($value[$key], $ctx);
            }
            hash_update($ctx, '}');

            return;
        }

        // Unreachable for a deck, which is arrays and scalars all the way down.
        hash_update($ctx, '?');
    }

    /**
     * An id for an element whose `<p:cNvPr>` carries no `name` to borrow one
     * from: its position in the file, as `imported-<slide>-<nth>`. Callers
     * reach it through `??`, which does not evaluate it unless the name is
     * genuinely absent, so the numbering stays tied to the file rather than to
     * how many elements were parsed.
     */
    private function nextFallbackId(string $prefix = 'imported-'): string
    {
        $this->slideFallbackIds++;

        return $prefix . $this->slideNumber . '-' . $this->slideFallbackIds;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseShape(SimpleXMLElement $sp): ?array
    {
        $sp->registerXPathNamespace('a', self::NS_A);
        $sp->registerXPathNamespace('p', self::NS_P);

        $xfrm = $sp->xpath('.//a:xfrm')[0] ?? null;
        if ($xfrm === null) {
            return null;
        }
        $offset = $xfrm->xpath('a:off')[0] ?? null;
        $extent = $xfrm->xpath('a:ext')[0] ?? null;
        if ($offset === null || $extent === null) {
            return null;
        }
        $x = (int) $offset['x'];
        $y = (int) $offset['y'];
        $cx = (int) $extent['cx'];
        $cy = (int) $extent['cy'];

        $base = [
            'id' => (string) ($sp->xpath('.//p:cNvPr')[0]['name'] ?? $this->nextFallbackId()),
            'x' => $this->fracX($x),
            'y' => $this->fracY($y),
            'w' => $this->fracX($cx),
            'h' => $this->fracY($cy),
        ];

        // Text body present?
        $tBody = $sp->xpath('.//p:txBody')[0] ?? null;
        $paragraphMarkdown = [];
        $anyDecoration = false;
        $paragraphs = [];
        if ($tBody !== null) {
            $tBody->registerXPathNamespace('a', self::NS_A);
            foreach ($tBody->xpath('.//a:p') ?: [] as $p) {
                $paragraphs[] = $this->paragraphRuns($p);
            }
        }
        // Bold / italic shared by EVERY text run of the element are its style
        // (style.weight / style.italic), so they carry no markers. Decided over
        // the element rather than per paragraph: a bold paragraph inside an
        // otherwise plain box used to come back with no bold at all.
        $allRuns = array_merge([], ...array_column($paragraphs, 1));
        $textRuns = array_filter($allRuns, static fn (array $r): bool => $r['text'] !== '');
        $allBold = $textRuns !== [] && array_reduce($textRuns, static fn (bool $all, array $r): bool => $all && $r['b'], true);
        $allItalic = $textRuns !== [] && array_reduce($textRuns, static fn (bool $all, array $r): bool => $all && $r['i'], true);
        foreach ($paragraphs as [$isBullet, $runs]) {
            [$md, $decorated] = $this->runsToMarkdown($isBullet, $runs, $allBold, $allItalic);
            $paragraphMarkdown[] = $md;
            $anyDecoration = $anyDecoration || $decorated;
        }

        // Preset geometry?
        $prst = $sp->xpath('.//a:prstGeom')[0]['prst'] ?? null;
        $prst = $prst !== null ? (string) $prst : null;

        // Heuristic: if there's any text content, treat as text element.
        $hasText = !empty(array_filter($paragraphMarkdown, fn ($t) => $t !== ''));
        if ($hasText) {
            $element = $base + [
                'type' => 'text',
                'content' => implode("\n", $paragraphMarkdown),
                'format' => $anyDecoration ? 'markdown' : 'plain',
            ];
            $style = $this->textStyle($tBody, $allRuns);
            if ($style !== []) {
                $element['style'] = $style;
            }

            return $element;
        }

        // Otherwise, if the shape has a known preset, emit a shape element.
        $shapeKind = match ($prst) {
            'rect' => 'rect',
            'roundRect' => 'rounded-rect',
            'ellipse' => 'ellipse',
            'triangle' => 'triangle',
            'line' => 'line',
            'rightArrow' => 'arrow',
            default => null,
        };
        if ($shapeKind !== null) {
            return $base + ['type' => 'shape', 'shape' => $shapeKind] + $this->shapePaint($sp);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePic(SimpleXMLElement $pic): ?array
    {
        $pic->registerXPathNamespace('a', self::NS_A);
        $pic->registerXPathNamespace('p', self::NS_P);

        $xfrm = $pic->xpath('.//a:xfrm')[0] ?? null;
        if ($xfrm === null) {
            return null;
        }
        $offset = $xfrm->xpath('a:off')[0] ?? null;
        $extent = $xfrm->xpath('a:ext')[0] ?? null;
        if ($offset === null || $extent === null) {
            return null;
        }

        $src = '';
        $blip = $pic->xpath('.//a:blip');
        if (!empty($blip)) {
            $blip[0]->registerXPathNamespace('r', self::NS_R);
            $rid = (string) $blip[0]->attributes(self::NS_R)['embed'];
            if ($rid !== '' && isset($this->currentSlideRels[$rid])) {
                $dataUri = $this->readMediaAsDataUri($this->currentSlideRels[$rid]['target']);
                if ($dataUri !== null) {
                    $src = $dataUri;
                }
            }
        }

        return [
            'id' => (string) ($pic->xpath('.//p:cNvPr')[0]['name'] ?? $this->nextFallbackId()),
            'type' => 'image',
            'x' => $this->fracX((int) $offset['x']),
            'y' => $this->fracY((int) $offset['y']),
            'w' => $this->fracX((int) $extent['cx']),
            'h' => $this->fracY((int) $extent['cy']),
            'src' => $src,
            'fit' => 'contain',
        ];
    }

    /**
     * Parse a `<p:graphicFrame>` — currently only tables are recognized
     * (drawingML table parts use the `.../drawingml/2006/table` graphicData
     * uri). Anything else returns null so the element is silently dropped.
     *
     * @return array<string, mixed>|null
     */
    private function parseGraphicFrame(SimpleXMLElement $gf): ?array
    {
        $gf->registerXPathNamespace('a', self::NS_A);
        $gf->registerXPathNamespace('p', self::NS_P);

        $xfrm = $gf->xpath('.//p:xfrm')[0] ?? null;
        if ($xfrm === null) {
            return null;
        }
        $offset = $xfrm->xpath('a:off')[0] ?? null;
        $extent = $xfrm->xpath('a:ext')[0] ?? null;
        if ($offset === null || $extent === null) {
            return null;
        }

        $tbl = $gf->xpath('.//a:tbl')[0] ?? null;
        if ($tbl === null) {
            return $this->parseChartFrame($gf, $offset, $extent);
        }
        $tbl->registerXPathNamespace('a', self::NS_A);

        $rows = $tbl->xpath('.//a:tr') ?: [];
        if (empty($rows)) {
            return null;
        }

        // Whether row 0 is a header is DECLARED, not assumed. `a:tblPr/@firstRow`
        // is the only thing that says so, and header-less tables are ordinary
        // now — every metadataGrid and kpiBand is one. Assuming a header
        // promoted a row of DATA to column labels and dropped it from the rows,
        // silently losing content on the way back in.
        $tblPr = $tbl->xpath('a:tblPr')[0] ?? null;
        $hasHeader = $tblPr !== null && (string) ($tblPr['firstRow'] ?? '0') === '1';

        // Column widths come from the grid, as fractions of the table, so a
        // table written with unequal columns reads back with them.
        $gridCols = $tbl->xpath('a:tblGrid/a:gridCol') ?: [];
        $gridWidths = array_map(fn ($c) => (int) $c['w'], $gridCols);
        $gridTotal = array_sum($gridWidths);

        $firstCells = $rows[0]->xpath('.//a:tc') ?: [];
        $columnCount = max(count($firstCells), count($gridWidths));

        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $column = [
                'key' => 'col' . ($i + 1),
                'label' => $hasHeader && isset($firstCells[$i]) ? $this->cellText($firstCells[$i]) : '',
            ];
            if ($gridTotal > 0 && isset($gridWidths[$i])) {
                $column['width'] = $gridWidths[$i] / $gridTotal;
            }
            $columns[] = $column;
        }

        // Styling, read as the cell model that writes it (Table\TableResolver):
        // what every body cell shares becomes the table's `style`, the header
        // row's look becomes `style.header`, the band on every second body row
        // becomes `style.stripe`, and a cell carries only what it does
        // differently from all of that.
        $bodyStart = $hasHeader ? 1 : 0;
        $bodyStyles = [];
        $oddFills = [];
        for ($r = $bodyStart; $r < count($rows); $r++) {
            foreach ($rows[$r]->xpath('.//a:tc') ?: [] as $tc) {
                $look = $this->cellStyle($tc);
                $bodyStyles[] = $look;
                if (($r - $bodyStart) % 2 === 1) {
                    $oddFills[] = $look['fill'];
                }
            }
        }
        $tableColor = self::modal(array_column($bodyStyles, 'color'));
        $tableSize = self::modal(array_column($bodyStyles, 'fontSize'));
        $stripeFill = self::modal($oddFills);
        $style = ['stripe' => $stripeFill === null ? false : ['fill' => $stripeFill]];
        if ($tableColor !== null) {
            $style['color'] = $tableColor;
        }
        if ($tableSize !== null) {
            $style['fontSize'] = $tableSize;
        }
        if ($hasHeader && isset($firstCells[0])) {
            $head = $this->cellStyle($firstCells[0]);
            $header = ['fill' => $head['fill'] ?? 'none', 'bold' => $head['bold']];
            if ($head['color'] !== null) {
                $header['color'] = $head['color'];
            }
            if ($head['fontSize'] !== null && $head['fontSize'] !== $tableSize) {
                $header['fontSize'] = $head['fontSize'];
            }
            $style['header'] = $header;
        } else {
            $style['header'] = false;
        }

        // Body rows
        $bodyRows = [];
        for ($r = $bodyStart; $r < count($rows); $r++) {
            $rowCells = $rows[$r]->xpath('.//a:tc') ?: [];
            $band = ($r - $bodyStart) % 2 === 1 ? $stripeFill : null;
            $rowData = [];
            foreach ($columns as $i => $col) {
                $cell = $rowCells[$i] ?? null;
                if ($cell === null) {
                    continue;
                }
                $s = $this->cellStyle($cell);
                $own = [];
                if ($s['fill'] !== $band) {
                    // A cell with no fill on a banded row has to say so.
                    $own['fill'] = $s['fill'] ?? 'none';
                }
                if ($s['color'] !== null && $s['color'] !== $tableColor) {
                    $own['color'] = $s['color'];
                }
                foreach (['bold', 'italic'] as $flag) {
                    if ($s[$flag]) {
                        $own[$flag] = true;
                    }
                }
                if ($s['fontSize'] !== null && $s['fontSize'] !== $tableSize) {
                    $own['fontSize'] = $s['fontSize'];
                }
                if ($s['align'] !== null) {
                    $own['align'] = $s['align'];
                }
                if ($s['fontFamily'] !== null) {
                    $own['fontFamily'] = $s['fontFamily'];
                }
                $rowData[$col['key']] = $own === [] ? $s['text'] : ['text' => $s['text']] + $own;
            }
            $bodyRows[] = $rowData;
        }

        return [
            'id' => (string) ($gf->xpath('.//p:cNvPr')[0]['name'] ?? $this->nextFallbackId('imported-table-')),
            'type' => 'table',
            'x' => $this->fracX((int) $offset['x']),
            'y' => $this->fracY((int) $offset['y']),
            'w' => $this->fracX((int) $extent['cx']),
            'h' => $this->fracY((int) $extent['cy']),
            'columns' => $columns,
            'rows' => $bodyRows,
            'style' => $style,
        ];
    }

    /**
     * A native chart, back as the ECharts option that writes it (the inverse of
     * Helpers\ChartTranslator). Charts used to vanish on read, so a read-back
     * deck showed an empty slide and an edit-and-write dropped the chart.
     *
     * What comes back: the kind (an area chart is a line with `areaStyle`), the
     * title, the category labels, each series' name, values, colour (as
     * `itemStyle.color`) and smoothing, single-point colours as
     * `data[i].itemStyle`, data labels as `label.show`, and the legend. Read from
     * the part's literal caches; a chart this reader does not model is null.
     *
     * @return array<string, mixed>|null
     */
    private function parseChartFrame(SimpleXMLElement $gf, SimpleXMLElement $offset, SimpleXMLElement $extent): ?array
    {
        $gf->registerXPathNamespace('c', self::NS_C);
        $gf->registerXPathNamespace('r', self::NS_R);
        $ref = $gf->xpath('.//c:chart')[0] ?? null;
        $rid = $ref === null ? '' : (string) ($ref->attributes(self::NS_R)['id'] ?? '');
        $target = $this->currentSlideRels[$rid]['target'] ?? null;
        $xml = $target !== null && $this->currentZip !== null ? $this->currentZip->getFromName($target) : false;
        $sx = $xml === false || stripos($xml, '<!DOCTYPE') !== false ? false : @simplexml_load_string($xml);
        if ($sx === false) {
            return null;
        }
        $sx->registerXPathNamespace('c', self::NS_C);
        $sx->registerXPathNamespace('a', self::NS_A);

        $plot = null;
        $tag = '';
        foreach (['barChart', 'lineChart', 'areaChart', 'pieChart', 'scatterChart'] as $candidate) {
            $plot = $sx->xpath('//c:plotArea/c:'.$candidate)[0] ?? null;
            if ($plot !== null) {
                $tag = $candidate;
                break;
            }
        }
        if ($plot === null) {
            return null;
        }
        $type = match ($tag) {
            'barChart' => 'bar',
            'pieChart' => 'pie',
            'scatterChart' => 'scatter',
            default => 'line',
        };

        $categories = [];
        $series = [];
        foreach ($plot->children(self::NS_C) as $ser) {
            if ($ser->getName() !== 'ser') {
                continue;
            }
            $ser->registerXPathNamespace('c', self::NS_C);
            $ser->registerXPathNamespace('a', self::NS_A);
            if ($categories === [] && $type !== 'scatter') {
                $categories = array_map('strval', $this->chartPoints($ser, 'c:cat'));
            }
            $one = ['type' => $type];
            $name = $ser->xpath('c:tx//c:v')[0] ?? null;
            if ($name !== null) {
                $one['name'] = (string) $name;
            }
            $colorPath = match ($tag) {
                'lineChart' => 'c:spPr/a:ln/a:solidFill/a:srgbClr',
                'pieChart', 'scatterChart' => null,
                default => 'c:spPr/a:solidFill/a:srgbClr',
            };
            $colorNode = $colorPath === null ? null : ($ser->xpath($colorPath)[0] ?? null);
            if ($colorNode !== null && preg_match('/^[0-9A-F]{6}$/', strtoupper((string) $colorNode['val'])) === 1) {
                $one['itemStyle'] = ['color' => '#'.strtoupper((string) $colorNode['val'])];
            }
            if ($tag === 'areaChart') {
                $one['areaStyle'] = [];
            }
            if ((string) (($ser->xpath('c:smooth')[0] ?? null)['val'] ?? '') === '1') {
                $one['smooth'] = true;
            }
            if ((string) (($ser->xpath('c:dLbls/c:showVal')[0] ?? null)['val'] ?? '') === '1') {
                $one['label'] = ['show' => true];
            }

            if ($type === 'scatter') {
                $xs = $this->chartPoints($ser, 'c:xVal');
                $ys = $this->chartPoints($ser, 'c:yVal');
                $data = [];
                foreach ($xs as $i => $x) {
                    $data[] = [(float) $x, (float) ($ys[$i] ?? 0)];
                }
            } else {
                $pointColors = [];
                foreach ($ser->xpath('c:dPt') ?: [] as $dPt) {
                    $dPt->registerXPathNamespace('c', self::NS_C);
                    $dPt->registerXPathNamespace('a', self::NS_A);
                    $idx = (string) (($dPt->xpath('c:idx')[0] ?? null)['val'] ?? '');
                    $clr = strtoupper((string) (($dPt->xpath('.//a:srgbClr')[0] ?? null)['val'] ?? ''));
                    if (ctype_digit($idx) && preg_match('/^[0-9A-F]{6}$/', $clr) === 1) {
                        $pointColors[(int) $idx] = '#'.$clr;
                    }
                }
                $data = [];
                foreach ($this->chartPoints($ser, 'c:val') as $i => $v) {
                    $value = (float) $v;
                    if ($type === 'pie') {
                        $point = ['name' => $categories[$i] ?? '', 'value' => $value];
                        if (isset($pointColors[$i])) {
                            $point['itemStyle'] = ['color' => $pointColors[$i]];
                        }
                        $data[] = $point;
                    } elseif (isset($pointColors[$i])) {
                        $data[] = ['value' => $value, 'itemStyle' => ['color' => $pointColors[$i]]];
                    } else {
                        $data[] = $value;
                    }
                }
            }
            $one['data'] = $data;
            $series[] = $one;
        }
        if ($series === []) {
            return null;
        }

        $option = [];
        $title = implode('', array_map('strval', $sx->xpath('//c:chart/c:title//a:t') ?: []));
        if ($title !== '') {
            $option['title'] = ['text' => $title];
        }
        if (($sx->xpath('//c:chart/c:legend') ?: []) !== []) {
            $option['legend'] = [];
        }
        if ($type === 'scatter') {
            $option['xAxis'] = ['type' => 'value'];
            $option['yAxis'] = ['type' => 'value'];
        } elseif ($type !== 'pie') {
            $option['xAxis'] = ['type' => 'category', 'data' => $categories];
            $option['yAxis'] = ['type' => 'value'];
        }
        $option['series'] = $series;

        return [
            'id' => (string) ($gf->xpath('.//p:cNvPr')[0]['name'] ?? $this->nextFallbackId('imported-chart-')),
            'type' => 'chart',
            'x' => $this->fracX((int) $offset['x']),
            'y' => $this->fracY((int) $offset['y']),
            'w' => $this->fracX((int) $extent['cx']),
            'h' => $this->fracY((int) $extent['cy']),
            'option' => $option,
        ];
    }

    /**
     * A series' cached points under `$host` (c:cat, c:val, c:xVal, c:yVal), in
     * index order, as strings; an index with no point is ''.
     *
     * @return list<string>
     */
    private function chartPoints(SimpleXMLElement $ser, string $host): array
    {
        $ser->registerXPathNamespace('c', self::NS_C);
        $count = (int) (($ser->xpath($host.'//c:ptCount')[0] ?? null)['val'] ?? 0);
        $out = array_fill(0, max(0, $count), '');
        foreach ($ser->xpath($host.'//c:pt') ?: [] as $pt) {
            $pt->registerXPathNamespace('c', self::NS_C);
            $idx = (int) $pt['idx'];
            if ($idx >= 0 && $idx < $count) {
                $out[$idx] = (string) ($pt->xpath('c:v')[0] ?? '');
            }
        }

        return $out;
    }

    /**
     * One table cell's text and look: its fill (tcPr's own solidFill, never a
     * border's; null for noFill), and the first run's colour, weight, slant,
     * size (design px) and face, with the paragraph's alignment (null = left).
     *
     * @return array{text: string, fill: ?string, color: ?string, bold: bool, italic: bool, fontSize: int|float|null, align: ?string, fontFamily: ?string}
     */
    private function cellStyle(SimpleXMLElement $tc): array
    {
        $tc->registerXPathNamespace('a', self::NS_A);
        $fill = null;
        $tcPr = $tc->xpath('a:tcPr')[0] ?? null;
        if ($tcPr !== null) {
            $solid = $this->aChild($tcPr, 'solidFill');
            $fill = $solid !== null ? $this->solidFillColor($solid) : null;
        }
        $rPr = $tc->xpath('.//a:r/a:rPr')[0] ?? $tc->xpath('.//a:endParaRPr')[0] ?? null;
        $color = null;
        $sz = null;
        $font = null;
        $bold = false;
        $italic = false;
        if ($rPr !== null) {
            $rPr->registerXPathNamespace('a', self::NS_A);
            $solid = $this->aChild($rPr, 'solidFill');
            $color = $solid !== null ? $this->solidFillColor($solid) : null;
            $bold = (string) ($rPr['b'] ?? '0') === '1';
            $italic = (string) ($rPr['i'] ?? '0') === '1';
            $sz = ctype_digit((string) ($rPr['sz'] ?? '')) ? self::pxFromHundredths((int) $rPr['sz']) : null;
            $latin = $rPr->xpath('a:latin')[0] ?? null;
            $face = $latin === null ? '' : (string) $latin['typeface'];
            $font = $face !== '' ? $face : null;
        }
        $algn = (string) (($tc->xpath('.//a:p/a:pPr')[0] ?? null)['algn'] ?? '');

        return [
            'text' => $this->cellText($tc),
            'fill' => $fill,
            'color' => $color,
            'bold' => $bold,
            'italic' => $italic,
            'fontSize' => $sz,
            'align' => match ($algn) {
                'ctr' => 'center',
                'r' => 'right',
                'just' => 'justify',
                default => null,
            },
            'fontFamily' => $font,
        ];
    }

    /**
     * The most common non-null value; a tie goes to the one seen first, so
     * the three engines pick the same.
     *
     * @param  list<mixed>  $values
     */
    private static function modal(array $values): mixed
    {
        $best = null;
        $bestCount = 0;
        $seen = [];
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $key = is_string($v) ? 's:'.$v : 'n:'.$v;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > $bestCount) {
                $best = $v;
                $bestCount = $seen[$key];
            }
        }

        return $best;
    }

    private function cellText(SimpleXMLElement $cell): string
    {
        $cell->registerXPathNamespace('a', self::NS_A);
        $segments = [];
        foreach ($cell->xpath('.//a:t') ?: [] as $t) {
            $segments[] = (string) $t;
        }

        return implode('', $segments);
    }

    /**
     * A drawingML `<a:p>`'s bullet flag and runs, each with what the reader
     * needs for markdown (text, b, i, code) and for the element's style
     * (u, sz, color, font). `[isBullet, runs]`.
     *
     * @return array{0: bool, 1: list<array<string, mixed>>}
     */
    private function paragraphRuns(SimpleXMLElement $p): array
    {
        $p->registerXPathNamespace('a', self::NS_A);

        $isBullet = !empty($p->xpath('.//a:pPr/a:buChar'));

        $parsed = [];
        foreach ($p->xpath('.//a:r') ?: [] as $r) {
            $r->registerXPathNamespace('a', self::NS_A);
            $rPr = $r->xpath('a:rPr')[0] ?? null;
            $tNode = $r->xpath('a:t')[0] ?? null;
            $text = $tNode !== null ? (string) $tNode : '';
            $b = false;
            $i = false;
            $u = false;
            $code = false;
            $sz = null;
            $color = null;
            $font = null;
            if ($rPr !== null) {
                $rPr->registerXPathNamespace('a', self::NS_A);
                $b = ((string) ($rPr['b'] ?? '0')) === '1';
                $i = ((string) ($rPr['i'] ?? '0')) === '1';
                $u = ((string) ($rPr['u'] ?? 'none')) !== 'none';
                $sz = ctype_digit((string) ($rPr['sz'] ?? '')) ? (int) $rPr['sz'] : null;
                $solid = $this->aChild($rPr, 'solidFill');
                $color = $solid !== null ? $this->solidFillColor($solid) : null;
                $latin = $rPr->xpath('a:latin');
                if (!empty($latin)) {
                    $face = (string) $latin[0]['typeface'];
                    $typeface = strtolower($face);

                    // Exact match against the typeface the deck RECORDED first,
                    // then the name sniff.
                    //
                    // The sniff alone was sound while the writer always emitted
                    // Consolas. Once a deck can name its own mono font it is
                    // not: "Fira Code" and "Cascadia" contain none of these
                    // words, so a code run came back as plain text — a silent
                    // downgrade on a document that opened perfectly.
                    //
                    // The sniff stays as the fallback, because it is the only
                    // thing that works for a pptx written by anything else.
                    $recorded = strtolower($this->monoTypeface);

                    if (($recorded !== '' && $typeface === $recorded)
                        || str_contains($typeface, 'consola')
                        || str_contains($typeface, 'mono')
                        || str_contains($typeface, 'courier')) {
                        $code = true;
                    } elseif ($face !== '') {
                        $font = $face;
                    }
                }
            }
            $parsed[] = ['text' => $text, 'b' => $b, 'i' => $i, 'u' => $u, 'code' => $code, 'sz' => $sz, 'color' => $color, 'font' => $font];
        }

        return [$isBullet, $parsed];
    }

    /**
     * One paragraph's runs back into a single line of markdown source.
     * Returns `[markdownLine, anyDecoration]`. The flag lets the caller decide
     * whether to mark the whole text element as `format=markdown` (we only do
     * so when at least one run carried decoration or the paragraph was a
     * bullet).
     *
     * Heuristic for inline span emission:
     *   - Bold shared by every text run of the ELEMENT ($allBold) is the
     *     element's `style.weight` and gets no `**` markers. Same for italic.
     *   - Otherwise `**` wraps the bold runs — the canonical markdown case.
     *   - Code runs (the mono font) always wrap in backticks because that's
     *     how the writer encodes them.
     *
     * We intentionally do NOT try to reconstruct `# ATX headings` from
     * run sizes — there's no reliable way to distinguish a hand-styled
     * "large bold title" from a markdown-emitted `#` heading. Round-trip
     * fidelity stops at inline spans.
     *
     * @param  list<array<string, mixed>>  $parsed
     * @return array{0: string, 1: bool}
     */
    private function runsToMarkdown(bool $isBullet, array $parsed, bool $allBold, bool $allItalic): array
    {
        $anyNonEmpty = false;
        foreach ($parsed as $run) {
            if ($run['text'] !== '') {
                $anyNonEmpty = true;
            }
        }
        if (!$anyNonEmpty) {
            return [$isBullet ? '- ' : '', $isBullet];
        }

        // Coalesce adjacent runs that carry the SAME decoration.
        //
        // DrawingML splits text into runs for reasons that have nothing to do
        // with emphasis — a syntax highlighter emits one run per token, all of
        // them code — and emitting a marker per run produces markdown that is
        // not merely ugly but WRONG. A highlighted `const deck = 1;` came back
        // as "`const`` deck = ``1``;`", where every pair of adjacent backticks
        // closes one span and opens the next, so re-parsing it yields the
        // inverse of the intended emphasis.
        //
        // Merging first is also what makes the output stable: the same text
        // reads the same whether the writer split it into one run or six.
        $merged = [];
        foreach ($parsed as $run) {
            $last = $merged !== [] ? array_key_last($merged) : null;

            if ($last !== null
                && $merged[$last]['b'] === $run['b']
                && $merged[$last]['i'] === $run['i']
                && $merged[$last]['code'] === $run['code']) {
                $merged[$last]['text'] .= $run['text'];
                continue;
            }

            $merged[] = $run;
        }
        $parsed = $merged;

        // Second pass: emit, treating uniform bold/italic as the
        // paragraph default (no markers).
        $line = '';
        $anyDecoration = false;
        foreach ($parsed as $run) {
            $text = $run['text'];
            $emitBold = $run['b'] && !$allBold;
            $emitItalic = $run['i'] && !$allItalic;
            if ($run['code']) {
                $line .= '`' . $text . '`';
                $anyDecoration = true;
            } elseif ($emitBold && $emitItalic) {
                $line .= '***' . $text . '***';
                $anyDecoration = true;
            } elseif ($emitBold) {
                $line .= '**' . $text . '**';
                $anyDecoration = true;
            } elseif ($emitItalic) {
                $line .= '*' . $text . '*';
                $anyDecoration = true;
            } else {
                $line .= $text;
            }
        }

        if ($isBullet) {
            return ['- ' . $line, true];
        }

        return [$line, $anyDecoration];
    }
}
