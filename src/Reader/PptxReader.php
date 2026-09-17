<?php

declare(strict_types=1);

namespace DarkSlide\Reader;

use DarkSlide\Helpers\Emu;
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
 * **And reading its OWN clock is only half of that.** A value derived from a
 * part the WRITER stamps with the clock is just as impure, one step removed,
 * and it is worse: two reads of one buffer agree, so it looks fixed, while a
 * deck serialised and re-serialised — a consumer saving a file that changed
 * nothing — diverges every single time. That is what 0.10.1 shipped. Anything
 * derived from the package must therefore skip the parts that are about the
 * SAVE rather than about the deck; see `DIGEST_EXCLUDED_PART`.
 */
final class PptxReader
{
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

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
     * The one part left out of the deck id, because it is the one part that is
     * not about the deck. `docProps/core.xml` carries `<dcterms:created>` and
     * `<dcterms:modified>`, which the writer stamps with `gmdate()`, so it is
     * the only entry that differs between two serialisations of one deck.
     *
     * Measured rather than assumed, and the measurement is why this is exactly
     * one name long: of a 43-entry package written either side of a second
     * boundary, ONE entry differed. Do not widen this to a metadata set on
     * suspicion — `docProps/app.xml` and the rest were measured stable, and a
     * speculative exclusion is a guess someone has to unpick later.
     */
    private const DIGEST_EXCLUDED_PART = 'docProps/core.xml';

    /**
     * CRC-32 over the package's entries as eight lowercase hex digits — the
     * deck id this read returns. CRC-32 rather than a cryptographic digest
     * because all three engines already carry one for the zip container
     * itself, so the trio agrees on the id without any of them growing a
     * hashing dependency.
     *
     * It was `time()` until 0.10.1 and the whole package's bytes until 0.10.2.
     * Both were impure; the second was worse. `time()` moved only across a
     * tick, so a re-read was wrong about one time in five. Hashing the whole
     * file moved the clock read from HERE to the writer — `docProps/core.xml`
     * is stamped at save time — and the two serialisations a round trip
     * compares are always written apart, so it diverged 14 times out of 14 and
     * broke pptx version history in a consumer's shipped product.
     */
    private string $packageDigest = '';

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
        // instance may be handed a second file; the digest is taken in
        // extract(), which is where the opened archive is.
        $this->packageDigest = '';
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
        $this->packageDigest = $this->digestOf($zip);

        $deck = [
            'id' => 'imported-' . $this->packageDigest,
            'title' => $this->readCoreTitle($zip) ?? 'Imported',
            'theme' => ['name' => 'imported'],
            'slides' => [],
        ];

        $this->monoTypeface = $this->readMonoTypeface($zip);
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
     * CRC-32 over every entry of the package except `DIGEST_EXCLUDED_PART`.
     *
     * Entry names go in alongside their contents, so moving a part cannot leave
     * the id unchanged. The walk is in archive order, which is what every
     * engine's zip reader enumerates — that, plus CRC-32 being the one digest
     * all three already have, is what makes two engines read one file to the
     * same id.
     */
    private function digestOf(ZipArchive $zip): string
    {
        $ctx = hash_init('crc32b');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === self::DIGEST_EXCLUDED_PART) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            hash_update($ctx, $name);
            hash_update($ctx, "\0");
            hash_update($ctx, $content === false ? '' : $content);
            hash_update($ctx, "\0");
        }

        return hash_final($ctx);
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
        if ($tBody !== null) {
            $tBody->registerXPathNamespace('a', self::NS_A);
            foreach ($tBody->xpath('.//a:p') ?: [] as $p) {
                $p->registerXPathNamespace('a', self::NS_A);
                [$md, $decorated] = $this->paragraphToMarkdown($p);
                $paragraphMarkdown[] = $md;
                $anyDecoration = $anyDecoration || $decorated;
            }
        }

        // Preset geometry?
        $prst = $sp->xpath('.//a:prstGeom')[0]['prst'] ?? null;
        $prst = $prst !== null ? (string) $prst : null;

        // Heuristic: if there's any text content, treat as text element.
        $hasText = !empty(array_filter($paragraphMarkdown, fn ($t) => $t !== ''));
        if ($hasText) {
            return $base + [
                'type' => 'text',
                'content' => implode("\n", $paragraphMarkdown),
                'format' => $anyDecoration ? 'markdown' : 'plain',
            ];
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
            return $base + ['type' => 'shape', 'shape' => $shapeKind];
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
            return null;
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

        // Body rows
        $bodyRows = [];
        for ($r = $hasHeader ? 1 : 0; $r < count($rows); $r++) {
            $rowCells = $rows[$r]->xpath('.//a:tc') ?: [];
            $rowData = [];
            foreach ($columns as $i => $col) {
                $cell = $rowCells[$i] ?? null;
                if ($cell !== null) {
                    $rowData[$col['key']] = $this->cellText($cell);
                }
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
        ];
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
     * Convert a drawingML `<a:p>` element back into a single line of
     * markdown source. Returns `[markdownLine, anyDecoration]`. The flag
     * lets the caller decide whether to mark the whole text element as
     * `format=markdown` (we only do so when at least one run carried
     * mixed decoration or the paragraph was a bullet).
     *
     * Heuristic for inline span emission:
     *   - If every non-empty run is bold, we treat bold as the paragraph
     *     default (it was set via the element's `style.weight`) and emit
     *     no `**` markers. Same for italic.
     *   - If runs are mixed (some bold, some not), `**` wraps only the
     *     bold ones — the canonical markdown case.
     *   - Code runs (Consolas / monospace font hint) always wrap in
     *     backticks because that's how the writer encodes them.
     *
     * We intentionally do NOT try to reconstruct `# ATX headings` from
     * run sizes — there's no reliable way to distinguish a hand-styled
     * "large bold title" from a markdown-emitted `#` heading. Round-trip
     * fidelity stops at inline spans.
     *
     * @return array{0: string, 1: bool}
     */
    private function paragraphToMarkdown(SimpleXMLElement $p): array
    {
        $p->registerXPathNamespace('a', self::NS_A);

        // Bullet?
        $bu = $p->xpath('.//a:pPr/a:buChar');
        $isBullet = !empty($bu);

        $runs = $p->xpath('.//a:r') ?: [];
        if (empty($runs)) {
            return [$isBullet ? '- ' : '', $isBullet];
        }

        // First pass: collect (text, b, i, code) tuples + uniformity flags.
        $parsed = [];
        $allBold = true;
        $allItalic = true;
        $anyNonEmpty = false;
        foreach ($runs as $r) {
            $r->registerXPathNamespace('a', self::NS_A);
            $rPr = $r->xpath('a:rPr')[0] ?? null;
            $tNode = $r->xpath('a:t')[0] ?? null;
            $text = $tNode !== null ? (string) $tNode : '';
            $b = false;
            $i = false;
            $code = false;
            if ($rPr !== null) {
                $b = ((string) ($rPr['b'] ?? '0')) === '1';
                $i = ((string) ($rPr['i'] ?? '0')) === '1';
                $latin = $rPr->xpath('a:latin');
                if (!empty($latin)) {
                    $typeface = strtolower((string) $latin[0]['typeface']);

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
                    }
                }
            }
            if ($text !== '') {
                $anyNonEmpty = true;
                if (!$b) {
                    $allBold = false;
                }
                if (!$i) {
                    $allItalic = false;
                }
            }
            $parsed[] = ['text' => $text, 'b' => $b, 'i' => $i, 'code' => $code];
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
