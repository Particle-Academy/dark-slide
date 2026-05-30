<?php

declare(strict_types=1);

namespace DarkSlide\Writer;

use DarkSlide\Helpers\ChartTranslator;
use DarkSlide\Helpers\Color;
use DarkSlide\Helpers\Emu;
use DarkSlide\Helpers\MarkdownInline;
use DarkSlide\Helpers\SyntaxHighlighter;
use DarkSlide\Helpers\Xml;
use DarkSlide\Schema\Schema;
use RuntimeException;
use ZipArchive;

/**
 * @phpstan-consistent-constructor
 *
 * PPTX (Office Open XML) writer. Takes a Deck schema and produces a
 * `.pptx` file PowerPoint / Keynote / Google Slides / LibreOffice
 * Impress can open.
 *
 * The PPTX format is a zip archive of XML parts following ECMA-376. This
 * writer ships the minimal viable set:
 *
 *   [Content_Types].xml
 *   _rels/.rels
 *   docProps/{core, app}.xml
 *   ppt/presentation.xml + _rels
 *   ppt/theme/theme1.xml
 *   ppt/slideMasters/slideMaster1.xml + _rels
 *   ppt/slideLayouts/slideLayoutN.xml + _rels (one per recognised layout)
 *   ppt/slides/slideN.xml + _rels (one per deck slide)
 *   ppt/notesSlides/notesSlideN.xml + _rels (one per slide with notes)
 *   ppt/charts/chartN.xml (one per chart element with a renderable option)
 *   ppt/media/imageN.* (referenced by image elements)
 *
 * Coordinates are converted from 0..1 fractions to EMU. Font sizes are
 * stored in hundredths-of-point (24pt → 2400). Colors are 6-digit hex.
 *
 * What gets written per element type:
 *   text   — `<p:sp>` with `<p:txBody>` containing styled paragraphs/runs
 *   image  — `<p:pic>` honouring `fit` (fill / cover / contain /
 *            scale-down) + explicit `crop`, binary embedded as
 *            `ppt/media/imageN.*`
 *   shape  — `<p:sp>` with `<a:prstGeom>` preset geometry (rect,
 *            ellipse, triangle, line, arrow; rounded-rect uses rect
 *            with `prstGeom="roundRect"`)
 *   code   — syntax-highlighted monospace runs on a dark fill
 *   chart  — native OOXML `ppt/charts/chartN.xml` (bar / line / area /
 *            pie / scatter) referenced by a `<p:graphicFrame>`; falls
 *            back to an embedded pre-render image or a titled placeholder
 *            when the option isn't translatable
 *   table  — real `<a:tbl>` (header + striped body rows)
 *   embed  — placeholder text "[embed: <src>]" (no PPTX equivalent)
 */
final class PptxWriter
{
    /** Drawing-ML chart namespace, reused across chart parts. */
    private const NS_CHART = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    /**
     * Slide layouts the writer ships, in registration order. Index + 1 is
     * the slideLayoutN.xml number; the value is the deck `slide.layout`
     * name. The first entry (`blank`) is the fallback.
     *
     * @var list<string>
     */
    private const LAYOUT_ORDER = [
        'blank',
        'title',
        'title-content',
        'two-column',
        'section-divider',
        'image-text',
        'text-image',
        'quote',
    ];

    /** Series fill palette (hex, no #) used when no theme accent is given. */
    private const CHART_PALETTE = ['8B5CF6', 'EC4899', '06B6D4', 'F59E0B', '10B981', '3B82F6', 'EF4444', 'A855F7'];

    /** Counter for media file names. Reset per write(). */
    private int $mediaCounter = 0;

    /** Counter for chart part file names. Reset per write(). */
    private int $chartCounter = 0;

    /** Media files queued for the archive, keyed by archive path. */
    private array $mediaFiles = [];

    /** Chart part XML queued for the archive, keyed by archive path. */
    private array $chartFiles = [];

    /** Accent hex (no #) pulled from the deck theme; drives chart series colors. */
    private string $themeAccent = '8B5CF6';

    /**
     * Override the temp directory used while assembling the archive.
     * Defaults to {@see sys_get_temp_dir()}; callers running in
     * sandboxes / containers where that path isn't writable can pass
     * their own (e.g. `storage_path('app/tmp')` in Laravel).
     *
     * @param  bool  $allowHttpImages  When true, `http(s)://` image sources
     *                                 are fetched via `file_get_contents` and
     *                                 embedded. OFF by default — fetching
     *                                 remote URLs is a security boundary the
     *                                 caller must opt into.
     */
    public function __construct(private ?string $tempDir = null, private bool $allowHttpImages = false)
    {
    }

    private function resolveTempDir(): string
    {
        $dir = $this->tempDir ?? sys_get_temp_dir();

        return rtrim($dir, DIRECTORY_SEPARATOR);
    }

    /**
     * Write a deck to disk.
     *
     * @param  array<string, mixed>  $deck
     * @return array{path: string, bytes: int, slides: int}
     */
    public function write(array $deck, string $path): array
    {
        $bytes = $this->toBytes($deck);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
        $ok = file_put_contents($path, $bytes);
        if ($ok === false) {
            throw new RuntimeException("Could not write file: {$path}");
        }

        return [
            'path' => $path,
            'bytes' => strlen($bytes),
            'slides' => count($deck['slides'] ?? []),
        ];
    }

    /**
     * Build the PPTX archive entirely in memory and return its bytes.
     *
     * @param  array<string, mixed>  $deck
     */
    public function toBytes(array $deck): string
    {
        $this->mediaCounter = 0;
        $this->chartCounter = 0;
        $this->mediaFiles = [];
        $this->chartFiles = [];
        $this->pendingSlideRels = [];
        [$this->themeAccent] = Color::parse($deck['theme']['colors']['accent'] ?? '#8B5CF6', '8B5CF6');

        $slides = $deck['slides'] ?? [];
        $slideCount = count($slides);

        // Build the zip in a dedicated per-call subdirectory so ZipArchive's
        // internal scratch file (which it creates next to the target during
        // close()) has a clean place to live. We can't rely on tempnam()
        // because it raises a PHP notice on some platforms that Laravel's
        // HandleExceptions promotes to a fatal ErrorException — even when
        // wrapped with `@`. The temp dir defaults to sys_get_temp_dir()
        // but is overridable via the constructor for hosts where that
        // path isn't writable.
        $base = $this->resolveTempDir();
        $tmpDir = $base . DIRECTORY_SEPARATOR . 'dark-slide-' . bin2hex(random_bytes(8));
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Could not allocate temp dir for PPTX archive at: {$tmpDir}. Override the temp directory by passing it to the PptxWriter constructor.");
        }
        $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'deck.pptx';

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not open zip archive for writing.');
            }

            // 1. Stage every text-based part first so we know which media
            //    references to register before [Content_Types].xml is written.
            $slidesXml = [];
            $notesSlidesXml = [];
            foreach ($slides as $i => $slide) {
                $oneBased = $i + 1;
                $slidesXml[$oneBased] = $this->buildSlideXml($slide, $oneBased, $deck);
                if (!empty($slide['notes'])) {
                    $notesSlidesXml[$oneBased] = $this->buildNotesSlideXml($slide, $oneBased);
                }
            }

            // 2. Top-level + ppt-level scaffolding.
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypes($slideCount, array_keys($notesSlidesXml), array_keys($this->chartFiles)));
            $zip->addFromString('_rels/.rels', $this->buildTopRels());
            $zip->addFromString('docProps/core.xml', $this->buildCoreProps($deck));
            $zip->addFromString('docProps/app.xml', $this->buildAppProps($slideCount));

            $zip->addFromString('ppt/presentation.xml', $this->buildPresentation($slideCount));
            $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->buildPresentationRels($slideCount));

            $zip->addFromString('ppt/theme/theme1.xml', $this->buildTheme($deck));
            $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $this->buildSlideMaster());
            $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->buildSlideMasterRels());

            // 3. Slide layout parts — one per recognised layout.
            foreach (self::LAYOUT_ORDER as $idx => $layoutName) {
                $n = $idx + 1;
                $zip->addFromString("ppt/slideLayouts/slideLayout{$n}.xml", $this->buildSlideLayout($layoutName));
                $zip->addFromString("ppt/slideLayouts/_rels/slideLayout{$n}.xml.rels", $this->buildSlideLayoutRels());
            }

            // 4. Slide parts.
            foreach ($slidesXml as $i => $xml) {
                $layoutNum = $this->layoutNumberFor($slides[$i - 1]['layout'] ?? null);
                $zip->addFromString("ppt/slides/slide{$i}.xml", $xml);
                $zip->addFromString("ppt/slides/_rels/slide{$i}.xml.rels", $this->buildSlideRels($i, isset($notesSlidesXml[$i]), $layoutNum));
            }

            // 5. Notes slide parts (only for slides with notes).
            foreach ($notesSlidesXml as $i => $xml) {
                $zip->addFromString("ppt/notesSlides/notesSlide{$i}.xml", $xml);
                $zip->addFromString("ppt/notesSlides/_rels/notesSlide{$i}.xml.rels", $this->buildNotesSlideRels($i));
            }

            // 6. Native chart parts.
            foreach ($this->chartFiles as $archivePath => $chartXml) {
                $zip->addFromString($archivePath, $chartXml);
            }

            // 7. Embedded media files.
            foreach ($this->mediaFiles as $archivePath => $bytes) {
                $zip->addFromString($archivePath, $bytes);
            }

            $zip->close();

            $contents = file_get_contents($tmp);
            if ($contents === false) {
                throw new RuntimeException('Could not read assembled PPTX archive.');
            }

            return $contents;
        } finally {
            @unlink($tmp);
            @rmdir($tmpDir);
        }
    }

    // ─── Top-level parts ───────────────────────────────────────────────────

    /**
     * @param  list<int>  $notesSlideIds
     * @param  list<string>  $chartParts  archive paths of emitted chart parts
     */
    private function buildContentTypes(int $slideCount, array $notesSlideIds, array $chartParts): string
    {
        $slideOverrides = '';
        for ($i = 1; $i <= $slideCount; $i++) {
            $slideOverrides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $layoutOverrides = '';
        foreach (self::LAYOUT_ORDER as $idx => $_) {
            $n = $idx + 1;
            $layoutOverrides .= '<Override PartName="/ppt/slideLayouts/slideLayout' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>';
        }
        $notesOverrides = '';
        foreach ($notesSlideIds as $i) {
            $notesOverrides .= '<Override PartName="/ppt/notesSlides/notesSlide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
        }
        $chartOverrides = '';
        foreach ($chartParts as $archivePath) {
            $chartOverrides .= '<Override PartName="/' . $archivePath . '" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }

        // Extension defaults — covers our embedded image media. PNG / JPEG
        // are the common cases; SVG and GIF are added defensively for
        // agent-emitted decks that reference data URIs of those types.
        $extensionDefaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Default Extension="gif" ContentType="image/gif"/>'
            . '<Default Extension="svg" ContentType="image/svg+xml"/>'
            . '<Default Extension="webp" ContentType="image/webp"/>';

        return Xml::declaration()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . $extensionDefaults
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . $layoutOverrides
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . $slideOverrides
            . $notesOverrides
            . $chartOverrides
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function buildTopRels(): string
    {
        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    /** @param array<string, mixed> $deck */
    private function buildCoreProps(array $deck): string
    {
        $title = Xml::text((string) ($deck['title'] ?? 'Untitled'));
        $author = isset($deck['metadata']['author']) ? Xml::text((string) $deck['metadata']['author']) : 'Dark Slide';
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return Xml::declaration()
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . "<dc:title>{$title}</dc:title>"
            . "<dc:creator>{$author}</dc:creator>"
            . "<cp:lastModifiedBy>{$author}</cp:lastModifiedBy>"
            . "<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created>"
            . "<dcterms:modified xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:modified>"
            . '</cp:coreProperties>';
    }

    private function buildAppProps(int $slideCount): string
    {
        return Xml::declaration()
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>DarkSlide</Application>'
            . '<AppVersion>0.4.0</AppVersion>'
            . "<Slides>{$slideCount}</Slides>"
            . '</Properties>';
    }

    // ─── presentation.xml ──────────────────────────────────────────────────

    private function buildPresentation(int $slideCount): string
    {
        $sldIdLst = '';
        for ($i = 1; $i <= $slideCount; $i++) {
            // Slide ids must be >= 256. We start at 256 and increment.
            $id = 256 + ($i - 1);
            $sldIdLst .= '<p:sldId id="' . $id . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $slideMasterRid = 'rId' . ($slideCount + 2);

        return Xml::declaration()
            . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
            . 'saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="' . $slideMasterRid . '"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>' . $sldIdLst . '</p:sldIdLst>'
            . '<p:sldSz cx="' . Emu::DEFAULT_SLIDE_WIDTH . '" cy="' . Emu::DEFAULT_SLIDE_HEIGHT . '" type="screen16x9"/>'
            . '<p:notesSz cx="' . Emu::DEFAULT_SLIDE_HEIGHT . '" cy="' . Emu::DEFAULT_SLIDE_WIDTH . '"/>'
            . '</p:presentation>';
    }

    private function buildPresentationRels(int $slideCount): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';
        for ($i = 1; $i <= $slideCount; $i++) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($slideCount + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';

        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    // ─── theme / master / layout (single set, reused across all slides) ───

    /** @param array<string, mixed> $deck */
    private function buildTheme(array $deck): string
    {
        $colors = (array) ($deck['theme']['colors'] ?? []);
        [$bg, ] = Color::parse($colors['background'] ?? '#FFFFFF', 'FFFFFF');
        [$text, ] = Color::parse($colors['text'] ?? '#0F172A', '0F172A');
        [$accent, ] = Color::parse($colors['accent'] ?? '#8B5CF6', '8B5CF6');
        // `muted` maps to the secondary dark (dk2 → headings / chart axes);
        // `surface` maps to the secondary light (lt2 → panel fills). Both
        // fall back to the classic Office values when the deck omits them.
        [$muted, ] = Color::parse($colors['muted'] ?? '#44546A', '44546A');
        [$surface, ] = Color::parse($colors['surface'] ?? '#E7E6E6', 'E7E6E6');

        $heading = Xml::attr((string) ($deck['theme']['fonts']['heading'] ?? 'Calibri'));
        $body = Xml::attr((string) ($deck['theme']['fonts']['body'] ?? 'Calibri'));

        // Derive a small accent ramp from the deck accent so accent1..6 stay
        // coherent with the brand instead of Office's stock rainbow.
        $palette = self::CHART_PALETTE;
        $palette[0] = $accent;

        return Xml::declaration()
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="DarkSlide">'
            . '<a:themeElements>'
            . '<a:clrScheme name="DarkSlide">'
            . '<a:dk1><a:srgbClr val="' . $text . '"/></a:dk1>'
            . '<a:lt1><a:srgbClr val="' . $bg . '"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="' . $muted . '"/></a:dk2>'
            . '<a:lt2><a:srgbClr val="' . $surface . '"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="' . $palette[0] . '"/></a:accent1>'
            . '<a:accent2><a:srgbClr val="' . $palette[1] . '"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="' . $palette[2] . '"/></a:accent3>'
            . '<a:accent4><a:srgbClr val="' . $palette[3] . '"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="' . $palette[4] . '"/></a:accent5>'
            . '<a:accent6><a:srgbClr val="' . $palette[5] . '"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink>'
            . '<a:folHlink><a:srgbClr val="954F72"/></a:folHlink>'
            . '</a:clrScheme>'
            . '<a:fontScheme name="DarkSlide">'
            . '<a:majorFont><a:latin typeface="' . $heading . '"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="' . $body . '"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme>'
            . '<a:fmtScheme name="DarkSlide">'
            . '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
            . '</a:fmtScheme>'
            . '</a:themeElements>'
            . '</a:theme>';
    }

    private function buildSlideMaster(): string
    {
        return Xml::declaration()
            . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree>'
            . '</p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst>' . $this->buildSlideLayoutIdLst() . '</p:sldLayoutIdLst>'
            . '<p:txStyles>'
            . '<p:titleStyle><a:lvl1pPr algn="ctr"><a:defRPr sz="4400"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:defRPr></a:lvl1pPr></p:titleStyle>'
            . '<p:bodyStyle><a:lvl1pPr><a:defRPr sz="2400"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:defRPr></a:lvl1pPr></p:bodyStyle>'
            . '<p:otherStyle/>'
            . '</p:txStyles>'
            . '</p:sldMaster>';
    }

    /**
     * Build the master's `<p:sldLayoutId>` entries — one per layout, each
     * pointing at its rels (`rId1`..`rIdN`); the theme rel comes last.
     */
    private function buildSlideLayoutIdLst(): string
    {
        $out = '';
        foreach (self::LAYOUT_ORDER as $idx => $_) {
            $n = $idx + 1;
            $id = 2147483648 + $n; // master uses 2147483648; layouts follow
            $out .= '<p:sldLayoutId id="' . $id . '" r:id="rId' . $n . '"/>';
        }

        return $out;
    }

    private function buildSlideMasterRels(): string
    {
        $rels = '';
        foreach (self::LAYOUT_ORDER as $idx => $_) {
            $n = $idx + 1;
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout' . $n . '.xml"/>';
        }
        $themeRid = 'rId' . (count(self::LAYOUT_ORDER) + 1);
        $rels .= '<Relationship Id="' . $themeRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>';

        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    /**
     * Map a deck `slide.layout` name to its 1-based slideLayoutN number.
     * Unknown / missing layouts fall back to `blank` (layout 1).
     */
    private function layoutNumberFor(?string $layout): int
    {
        if ($layout === null) {
            return 1;
        }
        $idx = array_search($layout, self::LAYOUT_ORDER, true);

        return $idx === false ? 1 : $idx + 1;
    }

    /**
     * Map a deck layout name to the closest OOXML slide-layout `type=`.
     * The geometry is irrelevant here — elements are placed absolutely on
     * the slide — but the type lets PowerPoint's "Reset" / layout picker
     * recognise the slide's role.
     */
    private function layoutTypeFor(string $layout): string
    {
        return match ($layout) {
            'title' => 'title',
            'title-content' => 'obj',
            'two-column' => 'twoObj',
            'section-divider' => 'secHead',
            'image-text', 'text-image' => 'picTx',
            'quote' => 'obj',
            default => 'blank',
        };
    }

    /**
     * Build one slideLayout part. Layouts ship empty shape trees — DarkSlide
     * places every element absolutely on the slide itself, so layouts exist
     * purely to give PowerPoint the right theme/reset affordance and a
     * recognisable layout `type`.
     */
    private function buildSlideLayout(string $layout): string
    {
        $type = $this->layoutTypeFor($layout);
        $name = Xml::attr(ucwords(str_replace('-', ' ', $layout)));

        return Xml::declaration()
            . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
            . 'type="' . $type . '" preserve="1">'
            . '<p:cSld name="' . $name . '">'
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree>'
            . '</p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sldLayout>';
    }

    private function buildSlideLayoutRels(): string
    {
        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            . '</Relationships>';
    }

    // ─── Per-slide rendering ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $slide
     */
    private function buildSlideXml(array $slide, int $slideNumber, array $deck = []): string
    {
        $elements = $slide['elements'] ?? [];
        // Z-order: elements without `z` keep their array order; explicit z overrides.
        usort($elements, function ($a, $b) {
            return ($a['z'] ?? -1) <=> ($b['z'] ?? -1);
        });

        $shapeId = 2; // 1 is reserved for the slide's nvGrpSpPr
        $shapeTreeXml = '';
        $slideRels = []; // collected for the slide's _rels file

        $bg = $this->buildBackground($slide['background'] ?? null, $slideNumber, $slideRels);

        // Builds collected for the `<p:timing>` tree: each animated element
        // paired with the shape id actually assigned to its `<p:cNvPr>`. We
        // capture the id here (rather than recomputing it) so the timing tree's
        // `<p:spTgt spid>` always matches the emitted shape, even as the
        // shape-id counter skips elements that render to nothing.
        $animatedBuilds = [];

        foreach ($elements as $arrayIndex => $element) {
            if (!is_array($element) || !isset($element['type'])) {
                continue;
            }
            if (!empty($element['hidden'])) {
                continue;
            }
            [$xml, $rels] = $this->buildElementXml($element, $shapeId, $slideNumber);
            if ($xml === '') {
                continue;
            }
            $shapeTreeXml .= $xml;
            $slideRels = array_merge($slideRels, $rels);

            if (isset($element['animation']) && is_array($element['animation']) && isset($element['animation']['effect'])) {
                // "By paragraph" builds only make sense for text elements: we
                // count the paragraphs the SAME way buildTextBody() splits the
                // content into `<a:p>` (a plain explode on "\n"), so paragraph
                // index i in the timing tree lines up with `<a:p>` index i in
                // the emitted `<p:txBody>`. Anything else (non-text, or text
                // without byParagraph) keeps a null paragraph count → one
                // whole-shape build node, exactly as before.
                $paragraphCount = null;
                if (
                    ($element['type'] ?? null) === 'text'
                    && !empty($element['animation']['byParagraph'])
                ) {
                    $paragraphCount = count(explode("\n", (string) ($element['content'] ?? '')));
                }

                $animatedBuilds[] = [
                    'shapeId' => $shapeId,
                    'arrayIndex' => $arrayIndex,
                    'animation' => $element['animation'],
                    'paragraphCount' => $paragraphCount,
                ];
            }

            $shapeId++;
        }

        // Persist the rels for buildSlideRels() to merge with the notes rel.
        $this->pendingSlideRels[$slideNumber] = $slideRels;

        $transition = $this->buildTransition($slide['transition'] ?? null, $deck['theme']['defaultTransition'] ?? null);
        $timing = $this->buildTiming($animatedBuilds);

        // CT_Slide child order is cSld, clrMapOvr, transition, timing — so the
        // timing node must come LAST, after any transition.
        return Xml::declaration()
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld>'
            . $bg
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $shapeTreeXml
            . '</p:spTree>'
            . '</p:cSld>'
            . $transition
            . $timing
            . '</p:sld>';
    }

    /** Accumulated per-slide rels keyed by 1-based slide number. */
    private array $pendingSlideRels = [];

    /**
     * Build the `<p:transition>` for a slide. Maps the deck's terse
     * transition spec to drawingML transition effects:
     *
     *   fade  → `<p:fade/>`
     *   slide → `<p:push dir="l|r|u|d"/>` (a directional push)
     *   zoom  → `<p:circle/>` (an iris grow-from-centre — PowerPoint has no
     *           native zoom slide transition; the legacy `<p:zoom>` was dropped
     *           from the render engine, so circle is the closest effect that
     *           actually animates)
     *   none / unknown → omitted entirely
     *
     * Speed (`spd`) is derived from `duration` (ms): >=700 → slow,
     * <=250 → fast, otherwise med. When the slide has no transition the
     * deck-level `theme.defaultTransition` is used as a fallback.
     *
     * @param  mixed  $transition  the slide's `transition` spec, if any
     * @param  mixed  $fallback  the deck's `theme.defaultTransition`, if any
     */
    private function buildTransition(mixed $transition, mixed $fallback): string
    {
        $spec = is_array($transition) ? $transition : null;
        if ($spec === null || ($spec['kind'] ?? null) === null || ($spec['kind'] ?? null) === 'none') {
            $spec = is_array($fallback) ? $fallback : null;
        }
        if ($spec === null) {
            return '';
        }

        $kind = is_string($spec['kind'] ?? null) ? strtolower((string) $spec['kind']) : 'none';
        if ($kind === 'none' || !in_array($kind, Schema::SLIDE_TRANSITION_KINDS, true)) {
            return '';
        }

        $spd = $this->transitionSpeed($spec['duration'] ?? null);

        $effect = match ($kind) {
            'fade' => '<p:fade/>',
            'slide' => '<p:push dir="' . $this->transitionDirection($spec['direction'] ?? null) . '"/>',
            // No native zoom transition survives in modern PowerPoint; an iris
            // circle grow-from-centre is the closest effect that animates.
            'zoom' => '<p:circle/>',
            default => '',
        };
        if ($effect === '') {
            return '';
        }

        return '<p:transition spd="' . $spd . '">' . $effect . '</p:transition>';
    }

    /** Derive PPTX transition speed (`spd`) from a duration in milliseconds. */
    private function transitionSpeed(mixed $duration): string
    {
        if (!is_numeric($duration)) {
            return 'med';
        }
        $ms = (float) $duration;
        if ($ms >= 700) {
            return 'slow';
        }
        if ($ms <= 250) {
            return 'fast';
        }

        return 'med';
    }

    /**
     * Map a deck transition direction to a drawingML push direction
     * (`l` / `r` / `u` / `d`). Defaults to `l` (push from the right).
     */
    private function transitionDirection(mixed $direction): string
    {
        return match (is_string($direction) ? strtolower($direction) : '') {
            'left' => 'l',
            'right' => 'r',
            'up' => 'u',
            'down' => 'd',
            default => 'l',
        };
    }

    // ─── Element entrance animations (`<p:timing>`) ───────────────────────────

    /** drawingML `<p:cTn>` id counter, reset per slide timing tree. */
    private int $tnId = 0;

    /**
     * Build the slide's `<p:timing>` tree from the animated builds captured
     * while emitting the shape tree. Returns '' when there are no animations
     * (so non-animated slides emit no timing node at all).
     *
     * The shape of the tree mirrors what PowerPoint authors when you add
     * entrance effects, and the build grouping mirrors fancy-slides'
     * `buildSteps()`:
     *
     *   - Animated builds are stable-sorted by `(order ?? 0)` then array index.
     *   - A "by paragraph" text build (captured with a non-null
     *     `paragraphCount`) is expanded in place into ONE sub-build per
     *     paragraph, each scoped to a single `<a:p>` via a paragraph-range
     *     target. The element's FIRST paragraph keeps the element's trigger
     *     (relative to prior builds); every later paragraph becomes its own
     *     `on-click` step (one line per click). Non-byParagraph builds expand
     *     to a single whole-shape sub-build (`paragraph = null`), unchanged.
     *   - The first sub-build and every `on-click` sub-build start a NEW click
     *     step (its step `<p:cTn>` waits on `<p:cond delay="indefinite"/>`).
     *   - `with-prev` attaches to the current step's par with begin delay 0.
     *   - `after-prev` attaches with begin delay = the previous build's
     *     duration (so it starts when the previous build finishes).
     *
     * Tree skeleton:
     *   <p:timing><p:tnLst>
     *     <p:par><p:cTn nodeType="tmRoot">
     *       <p:childTnLst><p:seq concurrent="1" nextAc="seek" nodeType="mainSeq">
     *         <p:cTn nodeType="mainSeq"><p:childTnLst>
     *           <p:par> ... one per click step ... </p:par>
     *         </p:childTnLst></p:cTn>
     *         <p:prevCondLst>/<p:nextCondLst> (click advance)
     *       </p:seq></p:childTnLst>
     *     </p:cTn></p:par>
     *   </p:tnLst></p:timing>
     *
     * @param  list<array{shapeId: int, arrayIndex: int, animation: array<string, mixed>, paragraphCount?: int|null}>  $builds
     */
    private function buildTiming(array $builds): string
    {
        if ($builds === []) {
            return '';
        }

        // Stable sort by (order ?? 0) then original array index.
        usort($builds, static function (array $a, array $b): int {
            $ao = isset($a['animation']['order']) && is_numeric($a['animation']['order']) ? (float) $a['animation']['order'] : 0.0;
            $bo = isset($b['animation']['order']) && is_numeric($b['animation']['order']) ? (float) $b['animation']['order'] : 0.0;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }

            return $a['arrayIndex'] <=> $b['arrayIndex'];
        });

        // Expand each element-level build into the sub-builds that actually
        // contribute timing nodes. A whole-shape build → one sub-build
        // (`paragraph = null`). A by-paragraph text build → one sub-build per
        // paragraph (`paragraph = 0..N-1`), each targeting a single `<a:p>`.
        // The element's first paragraph keeps its trigger so it slots into the
        // sequence where `order` placed it; every later paragraph is forced to
        // its own `on-click` step (one line revealed per click).
        /** @var list<array{shapeId: int, arrayIndex: int, animation: array<string, mixed>, paragraph: int|null}> $subBuilds */
        $subBuilds = [];
        foreach ($builds as $build) {
            $paragraphCount = $build['paragraphCount'] ?? null;
            if ($paragraphCount === null || $paragraphCount <= 1) {
                // Whole-shape build, OR a by-paragraph element with a single
                // paragraph (no point splitting): keep one node. A single
                // paragraph still scopes to its `<a:p>` when byParagraph is set.
                $subBuilds[] = [
                    'shapeId' => $build['shapeId'],
                    'arrayIndex' => $build['arrayIndex'],
                    'animation' => $build['animation'],
                    'paragraph' => $paragraphCount === null ? null : 0,
                ];

                continue;
            }

            for ($i = 0; $i < $paragraphCount; $i++) {
                $animation = $build['animation'];
                if ($i > 0) {
                    // Subsequent lines each get their own click.
                    $animation['trigger'] = 'on-click';
                }
                $subBuilds[] = [
                    'shapeId' => $build['shapeId'],
                    'arrayIndex' => $build['arrayIndex'],
                    'animation' => $animation,
                    'paragraph' => $i,
                ];
            }
        }

        // Group into click steps.
        /** @var list<list<array{shapeId: int, arrayIndex: int, animation: array<string, mixed>, paragraph: int|null}>> $steps */
        $steps = [];
        foreach ($subBuilds as $build) {
            $trigger = $this->animationTrigger($build['animation']['trigger'] ?? null);
            if ($steps === [] || $trigger === 'on-click') {
                $steps[] = [$build];
            } else {
                $steps[count($steps) - 1][] = $build;
            }
        }

        $this->tnId = 1; // tmRoot takes id 1 conventionally
        $rootId = $this->tnId++;

        // Entrance builds (presetClass="entr") make PowerPoint pre-hide their
        // targets before the first paint, so NO separate load-time hide node is
        // emitted — that approach flashed (the slide painted, then the hide ran
        // a frame later). Each build's visibility `<p:set>` reveals its target
        // when it fires; by-paragraph builds keep their pRg-scoped reveal.
        $stepPars = '';
        foreach ($steps as $step) {
            $stepPars .= $this->buildStepPar($step);
        }

        $mainSeqId = $this->tnId++;

        return '<p:timing>'
            . '<p:tnLst>'
            . '<p:par>'
            . '<p:cTn id="' . $rootId . '" dur="indefinite" restart="never" nodeType="tmRoot">'
            . '<p:childTnLst>'
            . '<p:seq concurrent="1" nextAc="seek">'
            . '<p:cTn id="' . $mainSeqId . '" dur="indefinite" nodeType="mainSeq">'
            . '<p:childTnLst>'
            . $stepPars
            . '</p:childTnLst>'
            . '</p:cTn>'
            . '<p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
            . '<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst>'
            . '</p:seq>'
            . '</p:childTnLst>'
            . '</p:cTn>'
            . '</p:par>'
            . '</p:tnLst>'
            . '</p:timing>';
    }

    /**
     * Build the `<p:tgtEl>` for an entrance behavior. When `$paragraph` is
     * null the whole shape is targeted (`<p:spTgt spid="N"/>`); when it is a
     * paragraph index the target is scoped to that single `<a:p>` via a
     * paragraph range (`st = end = i` selects exactly paragraph i):
     *
     *   <p:tgtEl><p:spTgt spid="N"><p:txEl><p:pRg st="i" end="i"/></p:txEl></p:spTgt></p:tgtEl>
     *
     * The paragraph index matches `<a:p>` index i in the shape's `<p:txBody>`
     * because both come from the same `explode("\n", content)` split.
     */
    private function buildTargetEl(int $spid, ?int $paragraph): string
    {
        if ($paragraph === null) {
            return '<p:tgtEl><p:spTgt spid="' . $spid . '"/></p:tgtEl>';
        }

        return '<p:tgtEl><p:spTgt spid="' . $spid . '">'
            . '<p:txEl><p:pRg st="' . $paragraph . '" end="' . $paragraph . '"/></p:txEl>'
            . '</p:spTgt></p:tgtEl>';
    }

    /**
     * Build one click-step `<p:par>`. The step `<p:cTn>` waits for a click
     * (`<p:cond delay="indefinite"/>`); each build inside fires according to
     * its trigger. The lead build keeps its own delay; `with-prev` followers
     * start with the lead (delay 0 + own), `after-prev` followers start after
     * the lead's duration.
     *
     * @param  list<array{shapeId: int, arrayIndex: int, animation: array<string, mixed>, paragraph: int|null}>  $step
     */
    private function buildStepPar(array $step): string
    {
        $lead = $step[0];
        $leadDelay = $this->animationDelay($lead['animation']);
        $leadDuration = $this->animationDuration($lead['animation']);

        $childTns = '';
        foreach ($step as $i => $build) {
            if ($i === 0) {
                $begin = $leadDelay;
            } else {
                $trigger = $this->animationTrigger($build['animation']['trigger'] ?? null);
                // with-prev → simultaneous with the lead; after-prev → after it.
                $base = $trigger === 'after-prev' ? $leadDelay + $leadDuration : $leadDelay;
                $begin = $base + $this->animationDelay($build['animation']);
            }
            $childTns .= $this->buildEffectPar($build, $begin);
        }

        $stepId = $this->tnId++;

        return '<p:par>'
            . '<p:cTn id="' . $stepId . '" fill="hold">'
            . '<p:stCondLst><p:cond delay="indefinite"/></p:stCondLst>'
            . '<p:childTnLst>'
            . $childTns
            . '</p:childTnLst>'
            . '</p:cTn>'
            . '</p:par>';
    }

    /**
     * Build the effect `<p:par>` for a single build: a wrapper par that begins
     * after `$begin` ms, containing the visibility `<p:set>` (hidden→visible)
     * plus the effect node (`<p:animEffect>` / `<p:anim>`), all targeting the
     * build's shape id.
     *
     * @param  array{shapeId: int, arrayIndex: int, animation: array<string, mixed>, paragraph: int|null}  $build
     * @param  int  $beginMs  begin offset for the effect, in milliseconds
     */
    private function buildEffectPar(array $build, int $beginMs): string
    {
        $spid = $build['shapeId'];
        $paragraph = $build['paragraph'];
        $animation = $build['animation'];
        $effect = $this->animationEffect($animation['effect'] ?? null);
        $duration = $this->animationDuration($animation);
        $direction = $this->animationDirection($animation['direction'] ?? null);

        $wrapId = $this->tnId++;

        // The wrapper par begins (relative to its parent click step) after
        // `$beginMs`. PowerPoint expresses this as a `<p:cond delay="...">`.
        $stCond = '<p:stCondLst><p:cond delay="' . max(0, $beginMs) . '"/></p:stCondLst>';

        $effectXml = match ($effect) {
            'fly-in' => $this->buildFlyInEffect($spid, $duration, $direction, $paragraph),
            'zoom' => $this->buildZoomEffect($spid, $duration, $paragraph),
            'wipe' => $this->buildWipeEffect($spid, $duration, $direction, $paragraph),
            default => $this->buildFadeEffect($spid, $duration, $paragraph),
        };

        // `presetClass="entr"` is what tells PowerPoint this is an ENTRANCE —
        // it pre-hides the target before the first paint, so the shape never
        // flashes visible at slide load. The visibility `<p:set>` inside the
        // effect then reveals it when the build fires. (No separate load-time
        // hide node is emitted — that approach flashed.)
        $presetId = $this->animationPresetId($effect);

        return '<p:par>'
            . '<p:cTn id="' . $wrapId . '" presetID="' . $presetId . '" presetClass="entr" presetSubtype="0" fill="hold">'
            . $stCond
            . '<p:childTnLst>'
            . $effectXml
            . '</p:childTnLst>'
            . '</p:cTn>'
            . '</p:par>';
    }

    /**
     * Map an entrance effect to its PowerPoint preset id (used alongside
     * `presetClass="entr"` so PowerPoint recognises the build as an entrance
     * and pre-hides the target). Appear=1, Fly In=2, Fade=10, Wipe=22, Zoom=23.
     */
    private function animationPresetId(string $effect): int
    {
        return match ($effect) {
            'fly-in' => 2,
            'wipe' => 22,
            'zoom' => 23,
            default => 10, // fade
        };
    }

    /**
     * The visibility `<p:set>` that flips `style.visibility` to `visible` at
     * the start of an entrance. Paired (in the shape's pre-build state) with a
     * `<p:set>` to `hidden`, this is how PowerPoint keeps a not-yet-built
     * element invisible until its build fires.
     */
    private function buildVisibilitySet(int $spid, ?int $paragraph = null): string
    {
        $id = $this->tnId++;

        return '<p:set>'
            . '<p:cBhvr>'
            . '<p:cTn id="' . $id . '" dur="1" fill="hold">'
            . '<p:stCondLst><p:cond delay="0"/></p:stCondLst>'
            . '</p:cTn>'
            . $this->buildTargetEl($spid, $paragraph)
            . '<p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst>'
            . '</p:cBhvr>'
            . '<p:to><p:strVal val="visible"/></p:to>'
            . '</p:set>';
    }

    /**
     * `fade` entrance — a visibility set plus `<p:animEffect transition="in"
     * filter="fade">` over the build duration. When `$paragraph` is set the
     * effect is scoped to that single `<a:p>` rather than the whole shape.
     */
    private function buildFadeEffect(int $spid, int $durationMs, ?int $paragraph = null): string
    {
        $set = $this->buildVisibilitySet($spid, $paragraph);
        $id = $this->tnId++;

        $effect = '<p:animEffect transition="in" filter="fade">'
            . '<p:cBhvr>'
            . '<p:cTn id="' . $id . '" dur="' . $durationMs . '"/>'
            . $this->buildTargetEl($spid, $paragraph)
            . '</p:cBhvr>'
            . '</p:animEffect>';

        return $set . $effect;
    }

    /**
     * `fly-in` entrance — a visibility set plus a `<p:anim>` translating the
     * shape from off-slide (per `direction`) to its final position. Offsets are
     * expressed as fractions of the slide (`ppt_x` / `ppt_y` are 0..1), so a
     * from-value of `-#ppt_w` (i.e. one shape-width left) slides it in from
     * the left edge. PowerPoint's stock fly-in uses ±0.5 of the slide; we
     * follow that for a comparable distance. When `$paragraph` is set the
     * effect is scoped to that single `<a:p>` rather than the whole shape.
     */
    private function buildFlyInEffect(int $spid, int $durationMs, string $direction, ?int $paragraph = null): string
    {
        $set = $this->buildVisibilitySet($spid, $paragraph);

        // from → to over ppt_x / ppt_y (slide-fraction coordinates of the
        // shape's centre). The shape's final centre is "#ppt_x"/"#ppt_y"; we
        // start it a half-slide off in the chosen direction.
        [$attr, $fromExpr, $toExpr] = match ($direction) {
            'right' => ['ppt_x', '1+#ppt_w/2', '#ppt_x'],
            'up' => ['ppt_y', '0-#ppt_h/2', '#ppt_y'],
            'down' => ['ppt_y', '1+#ppt_h/2', '#ppt_y'],
            default => ['ppt_x', '0-#ppt_w/2', '#ppt_x'], // left
        };

        $id = $this->tnId++;

        $anim = '<p:anim calcmode="lin" valueType="num">'
            . '<p:cBhvr additive="base">'
            . '<p:cTn id="' . $id . '" dur="' . $durationMs . '" fill="hold"/>'
            . $this->buildTargetEl($spid, $paragraph)
            . '<p:attrNameLst><p:attrName>' . $attr . '</p:attrName></p:attrNameLst>'
            . '</p:cBhvr>'
            . '<p:tavLst>'
            . '<p:tav tm="0"><p:val><p:strVal val="' . $fromExpr . '"/></p:val></p:tav>'
            . '<p:tav tm="100000"><p:val><p:strVal val="' . $toExpr . '"/></p:val></p:tav>'
            . '</p:tavLst>'
            . '</p:anim>';

        return $set . $anim;
    }

    /**
     * `zoom` entrance — PowerPoint's "Zoom" is a scale-up from a point paired
     * with a fade. A generic `<p:anim>` on `ppt_w`/`ppt_h` is NOT rendered as a
     * grow (it pops); the dedicated `<p:animScale>` behavior is. We run a fade
     * (`<p:animEffect>`) concurrently so it grows AND fades in. When
     * `$paragraph` is set the effect is scoped to that single `<a:p>` rather
     * than the whole shape.
     */
    private function buildZoomEffect(int $spid, int $durationMs, ?int $paragraph = null): string
    {
        $set = $this->buildVisibilitySet($spid, $paragraph);
        $fadeId = $this->tnId++;
        $scaleId = $this->tnId++;

        $fade = '<p:animEffect transition="in" filter="fade">'
            . '<p:cBhvr>'
            . '<p:cTn id="' . $fadeId . '" dur="' . $durationMs . '"/>'
            . $this->buildTargetEl($spid, $paragraph)
            . '</p:cBhvr>'
            . '</p:animEffect>';

        // Scale from a point (0%) to full size (100%). x/y in thousandths-of-percent.
        $scale = '<p:animScale>'
            . '<p:cBhvr>'
            . '<p:cTn id="' . $scaleId . '" dur="' . $durationMs . '" fill="hold"/>'
            . $this->buildTargetEl($spid, $paragraph)
            . '</p:cBhvr>'
            . '<p:from x="0" y="0"/>'
            . '<p:to x="100000" y="100000"/>'
            . '</p:animScale>';

        return $set . $fade . $scale;
    }

    /**
     * `wipe` entrance — a visibility set plus `<p:animEffect transition="in"
     * filter="wipe(...)">` keyed to `direction`. PowerPoint's wipe filter
     * names the edge the wipe travels FROM: a left-direction wipe reveals from
     * the right edge inward, so we map the deck direction to the matching
     * filter subtype. When `$paragraph` is set the effect is scoped to that
     * single `<a:p>` rather than the whole shape.
     */
    private function buildWipeEffect(int $spid, int $durationMs, string $direction, ?int $paragraph = null): string
    {
        $set = $this->buildVisibilitySet($spid, $paragraph);

        $filter = match ($direction) {
            'right' => 'wipe(left)',
            'up' => 'wipe(down)',
            'down' => 'wipe(up)',
            default => 'wipe(right)', // left
        };

        $id = $this->tnId++;

        $effect = '<p:animEffect transition="in" filter="' . $filter . '">'
            . '<p:cBhvr>'
            . '<p:cTn id="' . $id . '" dur="' . $durationMs . '"/>'
            . $this->buildTargetEl($spid, $paragraph)
            . '</p:cBhvr>'
            . '</p:animEffect>';

        return $set . $effect;
    }

    /** Normalise the animation effect name; unknown effects fall back to fade. */
    private function animationEffect(mixed $effect): string
    {
        $name = is_string($effect) ? strtolower($effect) : '';

        return in_array($name, Schema::ANIMATION_EFFECTS, true) ? $name : 'fade';
    }

    /** Normalise the animation trigger; defaults to on-click. */
    private function animationTrigger(mixed $trigger): string
    {
        $name = is_string($trigger) ? strtolower($trigger) : '';

        return in_array($name, Schema::ANIMATION_TRIGGERS, true) ? $name : 'on-click';
    }

    /** Normalise the animation direction; defaults to left. */
    private function animationDirection(mixed $direction): string
    {
        $name = is_string($direction) ? strtolower($direction) : '';

        return in_array($name, Schema::ANIMATION_DIRECTIONS, true) ? $name : 'left';
    }

    /**
     * Resolve a build's duration in milliseconds (default 500, clamped >= 1 so
     * `<p:cTn dur>` is always a positive number PowerPoint accepts).
     *
     * @param  array<string, mixed>  $animation
     */
    private function animationDuration(array $animation): int
    {
        $duration = isset($animation['duration']) && is_numeric($animation['duration'])
            ? (int) round((float) $animation['duration'])
            : Schema::ANIMATION_DEFAULT_DURATION_MS;

        return max(1, $duration);
    }

    /**
     * Resolve a build's begin delay in milliseconds (default 0, clamped >= 0).
     *
     * @param  array<string, mixed>  $animation
     */
    private function animationDelay(array $animation): int
    {
        $delay = isset($animation['delay']) && is_numeric($animation['delay'])
            ? (int) round((float) $animation['delay'])
            : 0;

        return max(0, $delay);
    }

    /**
     * Build the slide background XML. Three shapes are supported:
     *
     *   `image`    — CSS `url()` or path; embeds the binary as a media
     *                relationship + `<p:blipFill>`.
     *   `gradient` — CSS-style `linear-gradient(...)` parsed into PPTX
     *                `<a:gradFill>` with stops; angles converted to
     *                drawingML's 60,000ths-of-a-degree units.
     *   `color`    — solid fill (fallback when nothing else matches).
     *
     * @param  array<string, mixed>  $bg
     * @param  int  $slideNumber  used to keep media rel ids unique
     * @param  list<array{id: string, type: string, target: string}>  $rels  populated when a background image is embedded
     */
    private function buildBackground(mixed $bg, int $slideNumber, array &$rels): string
    {
        if (!is_array($bg)) {
            return '';
        }

        if (isset($bg['image']) && is_string($bg['image']) && $bg['image'] !== '') {
            $embed = $this->stageMedia($bg['image'], $slideNumber);
            if ($embed !== null) {
                $rels[] = [
                    'id' => $embed['relId'],
                    'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
                    'target' => $embed['target'],
                ];

                return '<p:bg><p:bgPr>'
                    . '<a:blipFill dpi="0" rotWithShape="1"><a:blip r:embed="' . $embed['relId'] . '"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></a:blipFill>'
                    . '<a:effectLst/></p:bgPr></p:bg>';
            }
            // Fall through to gradient / color when the image couldn't be staged.
        }

        if (isset($bg['gradient']) && is_string($bg['gradient'])) {
            $grad = $this->parseGradient($bg['gradient']);
            if ($grad !== null) {
                return '<p:bg><p:bgPr>' . $grad . '<a:effectLst/></p:bgPr></p:bg>';
            }
        }

        if (isset($bg['color']) && is_string($bg['color'])) {
            [$hex, $alpha] = Color::parse($bg['color']);

            return '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $hex . '"><a:alpha val="' . $alpha . '"/></a:srgbClr></a:solidFill><a:effectLst/></p:bgPr></p:bg>';
        }

        return '';
    }

    /**
     * Parse a CSS-style `linear-gradient(...)` string into a PPTX
     * `<a:gradFill>` block. Supports angle-or-direction + 2+ stops; falls
     * back to null when the input doesn't look like a CSS gradient.
     *
     * Examples that parse:
     *   linear-gradient(135deg, #fef3c7 0%, #fce7f3 100%)
     *   linear-gradient(to right, #ff0000, #00ff00)
     *   linear-gradient(#000, #fff)
     */
    private function parseGradient(string $css): ?string
    {
        $css = trim($css);
        if (!preg_match('/^linear-gradient\((.+)\)\s*;?\s*$/i', $css, $m)) {
            return null;
        }
        $args = $m[1];

        // Split top-level commas (rgba() inside stops contains commas too).
        $parts = $this->splitTopLevelCommas($args);
        if (count($parts) < 2) {
            return null;
        }

        // Direction is optional. It's the first part iff it doesn't look
        // like a color stop.
        $angleDeg = 90.0; // CSS default — top to bottom
        $first = trim($parts[0]);
        $directionLike = preg_match('/^(?:to\s+|[-+]?[0-9.]+(?:deg|rad|turn|grad)?\s*$)/i', $first) === 1;
        if ($directionLike) {
            $angleDeg = $this->parseGradientDirection($first);
            array_shift($parts);
        }

        // Remaining parts are stops: "color [position]"
        $stops = [];
        $count = count($parts);
        foreach ($parts as $i => $part) {
            $part = trim($part);
            // Pull off the trailing position (e.g. "50%" or "0.5"); the rest is the color.
            if (preg_match('/^(.+?)\s+([0-9.]+%?)\s*$/', $part, $sm)) {
                $colorStr = $sm[1];
                $posStr = $sm[2];
                if (str_ends_with($posStr, '%')) {
                    $pos = (float) rtrim($posStr, '%') / 100;
                } else {
                    $pos = (float) $posStr;
                }
            } else {
                $colorStr = $part;
                $pos = $count <= 1 ? 0.0 : $i / ($count - 1);
            }
            [$hex, ] = Color::parse($colorStr);
            $stops[] = ['hex' => $hex, 'pos' => max(0.0, min(1.0, $pos))];
        }

        $gsList = '';
        foreach ($stops as $stop) {
            $pos1000 = (int) round($stop['pos'] * 100000);
            $gsList .= '<a:gs pos="' . $pos1000 . '"><a:srgbClr val="' . $stop['hex'] . '"/></a:gs>';
        }

        // PPTX angles are in 60000ths of a degree, measured clockwise from
        // east (so CSS 0deg=top becomes PPTX 270deg). CSS is clockwise from
        // north → PPTX is clockwise from east; subtract 90 + clamp.
        $pptxAngle = (int) round(($angleDeg - 90) * 60000);
        $pptxAngle = (($pptxAngle % (360 * 60000)) + (360 * 60000)) % (360 * 60000);

        return '<a:gradFill flip="none" rotWithShape="1">'
            . '<a:gsLst>' . $gsList . '</a:gsLst>'
            . '<a:lin ang="' . $pptxAngle . '" scaled="0"/>'
            . '</a:gradFill>';
    }

    /**
     * Split a comma-separated argument list while respecting parentheses.
     * Used so `rgb(255, 0, 0)` inside a gradient stop doesn't get cut up.
     *
     * @return list<string>
     */
    private function splitTopLevelCommas(string $s): array
    {
        $out = [];
        $depth = 0;
        $buf = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
                $buf .= $c;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
                $buf .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $out[] = $buf;
                $buf = '';
            } else {
                $buf .= $c;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * Convert a CSS gradient direction (`135deg`, `to right`, `to top left`)
     * into a CSS-clockwise angle in degrees, 0 = up.
     */
    private function parseGradientDirection(string $dir): float
    {
        $dir = trim($dir);
        if (preg_match('/^([-+]?[0-9.]+)deg$/i', $dir, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^([-+]?[0-9.]+)rad$/i', $dir, $m)) {
            return ((float) $m[1]) * 180 / M_PI;
        }
        if (preg_match('/^([-+]?[0-9.]+)turn$/i', $dir, $m)) {
            return ((float) $m[1]) * 360;
        }
        return match (strtolower($dir)) {
            'to top' => 0,
            'to top right' => 45,
            'to right' => 90,
            'to bottom right' => 135,
            'to bottom' => 180,
            'to bottom left' => 225,
            'to left' => 270,
            'to top left' => 315,
            default => 180, // top→bottom (CSS default)
        };
    }

    /**
     * Build a single element's shape XML + any relationships it needs.
     *
     * @param  array<string, mixed>  $element
     * @return array{0: string, 1: list<array{id: string, type: string, target: string}>}
     */
    private function buildElementXml(array $element, int $shapeId, int $slideNumber): array
    {
        $rels = [];
        $xml = match ($element['type']) {
            'text' => $this->buildTextShape($element, $shapeId),
            'image' => $this->buildImageShape($element, $shapeId, $slideNumber, $rels),
            'shape' => $this->buildShape($element, $shapeId),
            'code' => $this->buildCodeShape($element, $shapeId),
            'chart' => $this->buildChart($element, $shapeId, $slideNumber, $rels),
            'table' => $this->buildTable($element, $shapeId),
            'embed' => $this->buildPlaceholder('[embed: ' . (string) ($element['src'] ?? '') . ']', $element, $shapeId),
            default => '',
        };

        // Whole-element hyperlink — inject an <a:hlinkClick> into the shape's
        // <p:cNvPr> and register an external relationship. Mirrors the
        // fancy-slides ElementBase.href click target.
        $xml = $this->applyHyperlink($xml, $element, $shapeId, $rels);

        return [$xml, $rels];
    }

    /**
     * If the element carries an `href`, register an external hyperlink
     * relationship and inject `<a:hlinkClick r:id="…">` into the shape's first
     * `<p:cNvPr>` (the relationships + drawingml namespaces are already declared
     * on the slide root, so the element needs no extra xmlns). No-op when there
     * is no href or no shape XML.
     *
     * @param  array<string, mixed>  $element
     * @param  list<array{id: string, type: string, target: string, mode?: string}>  $rels
     */
    private function applyHyperlink(string $xml, array $element, int $shapeId, array &$rels): string
    {
        $href = $element['href'] ?? null;
        if (!is_string($href) || $href === '' || $xml === '') {
            return $xml;
        }

        $relId = 'rIdLink' . $shapeId;
        $rels[] = [
            'id' => $relId,
            'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
            'target' => $href,
            'mode' => 'External',
        ];

        $hlink = '<a:hlinkClick r:id="' . $relId . '"/>';
        $count = 0;
        $injected = preg_replace(
            '/<p:cNvPr\b([^>]*)\/>/',
            '<p:cNvPr$1>' . $hlink . '</p:cNvPr>',
            $xml,
            1,
            $count
        );

        return ($injected !== null && $count > 0) ? $injected : $xml;
    }

    // ─── Element renderers ────────────────────────────────────────────────

    /** @param array<string, mixed> $element */
    private function buildTextShape(array $element, int $shapeId): string
    {
        $xfrm = $this->xfrmFromFractions($element);
        $body = $this->buildTextBody((string) ($element['content'] ?? ''), $element['style'] ?? [], $element['format'] ?? 'plain');
        $id = $element['id'] ?? "text-{$shapeId}";

        return '<p:sp>'
            . '<p:nvSpPr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvSpPr txBox="1"/>'
            . '<p:nvPr/>'
            . '</p:nvSpPr>'
            . '<p:spPr>'
            . $xfrm
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '<a:noFill/>'
            . '</p:spPr>'
            . $body
            . '</p:sp>';
    }

    /**
     * Build a `<p:pic>` honouring the element's `fit` + `crop`.
     *
     * The box is `x/y/w/h`; how the image lands in it depends on `fit`:
     *
     *   fill (default)       — stretch to the box (`<a:stretch>`)
     *   cover                — fill the box, centre-crop the overflowing
     *                          axis via `<a:srcRect>`
     *   contain / scale-down — preserve aspect inside the box: shrink
     *                          the off/ext to the fitted (letterboxed)
     *                          rect; no crop
     *   explicit `crop`      — `{x,y,w,h}` (0..1 of source) → `<a:srcRect>`,
     *                          takes precedence over `fit`
     *
     * @param  array<string, mixed>  $element
     * @param  list<array{id: string, type: string, target: string}>  $rels  populated with the embedded-image relationship.
     */
    private function buildImageShape(array $element, int $shapeId, int $slideNumber, array &$rels): string
    {
        $src = (string) ($element['src'] ?? '');
        $embed = $this->stageMedia($src, $slideNumber);
        if ($embed === null) {
            // Image couldn't be embedded — fall back to a placeholder text box.
            return $this->buildPlaceholder('[image: ' . $src . ']', $element, $shapeId);
        }
        $relId = $embed['relId'];
        $rels[] = [
            'id' => $relId,
            'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
            'target' => $embed['target'],
        ];

        $id = $element['id'] ?? "image-{$shapeId}";
        $alt = Xml::attr((string) ($element['alt'] ?? ''));
        $fit = is_string($element['fit'] ?? null) ? strtolower((string) $element['fit']) : 'fill';

        // Box geometry in EMU.
        $boxX = Emu::fromFracX((float) ($element['x'] ?? 0));
        $boxY = Emu::fromFracY((float) ($element['y'] ?? 0));
        $boxW = max(1, Emu::fromFracX((float) ($element['w'] ?? 0)));
        $boxH = max(1, Emu::fromFracY((float) ($element['h'] ?? 0)));

        // Intrinsic dimensions (best effort — null when undeterminable).
        $intrinsic = @getimagesizefromstring($embed['bytes']);
        $imgW = is_array($intrinsic) ? (int) ($intrinsic[0] ?? 0) : 0;
        $imgH = is_array($intrinsic) ? (int) ($intrinsic[1] ?? 0) : 0;

        $offX = $boxX;
        $offY = $boxY;
        $extW = $boxW;
        $extH = $boxH;
        $srcRect = '';

        $explicitCrop = $this->imageCropRect($element['crop'] ?? null);
        if ($explicitCrop !== null) {
            $srcRect = $explicitCrop;
        } elseif ($fit === 'cover' && $imgW > 0 && $imgH > 0) {
            $srcRect = $this->coverSrcRect($boxW, $boxH, $imgW, $imgH);
        } elseif (($fit === 'contain' || $fit === 'scale-down') && $imgW > 0 && $imgH > 0) {
            [$offX, $offY, $extW, $extH] = $this->containedRect($boxX, $boxY, $boxW, $boxH, $imgW, $imgH);
        }

        $blipFill = '<p:blipFill><a:blip r:embed="' . $relId . '"/>' . $srcRect . '<a:stretch><a:fillRect/></a:stretch></p:blipFill>';

        return '<p:pic>'
            . '<p:nvPicPr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '" descr="' . $alt . '"/>'
            . '<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>'
            . '<p:nvPr/>'
            . '</p:nvPicPr>'
            . $blipFill
            . '<p:spPr>'
            . '<a:xfrm><a:off x="' . $offX . '" y="' . $offY . '"/><a:ext cx="' . $extW . '" cy="' . $extH . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '</p:spPr>'
            . '</p:pic>';
    }

    /**
     * Build an `<a:srcRect>` for an explicit crop `{x,y,w,h}` (0..1 of the
     * source). Insets are in thousandths-of-percent (100% = 100000).
     * Returns null when the crop isn't a usable rectangle.
     *
     * @param  mixed  $crop
     */
    private function imageCropRect(mixed $crop): ?string
    {
        if (!is_array($crop)) {
            return null;
        }
        $x = isset($crop['x']) && is_numeric($crop['x']) ? (float) $crop['x'] : null;
        $y = isset($crop['y']) && is_numeric($crop['y']) ? (float) $crop['y'] : null;
        $w = isset($crop['w']) && is_numeric($crop['w']) ? (float) $crop['w'] : null;
        $h = isset($crop['h']) && is_numeric($crop['h']) ? (float) $crop['h'] : null;
        if ($x === null || $y === null || $w === null || $h === null) {
            return null;
        }
        $l = (int) round($x * 100000);
        $t = (int) round($y * 100000);
        $r = (int) round((1 - $x - $w) * 100000);
        $b = (int) round((1 - $y - $h) * 100000);
        $l = max(0, min(100000, $l));
        $t = max(0, min(100000, $t));
        $r = max(0, min(100000, $r));
        $b = max(0, min(100000, $b));

        return '<a:srcRect l="' . $l . '" t="' . $t . '" r="' . $r . '" b="' . $b . '"/>';
    }

    /**
     * Build the centre-crop `<a:srcRect>` for `fit: cover`. The image fills
     * the box; whichever axis overflows is cropped equally on both sides.
     */
    private function coverSrcRect(int $boxW, int $boxH, int $imgW, int $imgH): string
    {
        $boxAspect = $boxW / $boxH;
        $imgAspect = $imgW / $imgH;
        $l = $t = $r = $b = 0;

        if ($imgAspect > $boxAspect) {
            // Image is wider than the box — crop left/right.
            $visibleFrac = $boxAspect / $imgAspect;
            $inset = (int) round((1 - $visibleFrac) / 2 * 100000);
            $l = $inset;
            $r = $inset;
        } elseif ($imgAspect < $boxAspect) {
            // Image is taller than the box — crop top/bottom.
            $visibleFrac = $imgAspect / $boxAspect;
            $inset = (int) round((1 - $visibleFrac) / 2 * 100000);
            $t = $inset;
            $b = $inset;
        }

        return '<a:srcRect l="' . $l . '" t="' . $t . '" r="' . $r . '" b="' . $b . '"/>';
    }

    /**
     * Compute the letterboxed off/ext (in EMU) for `fit: contain` /
     * `scale-down`: the image is scaled to fit entirely inside the box,
     * preserving aspect, and centred.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}  [offX, offY, extW, extH]
     */
    private function containedRect(int $boxX, int $boxY, int $boxW, int $boxH, int $imgW, int $imgH): array
    {
        $scale = min($boxW / $imgW, $boxH / $imgH);
        $extW = max(1, (int) round($imgW * $scale));
        $extH = max(1, (int) round($imgH * $scale));
        $offX = $boxX + (int) round(($boxW - $extW) / 2);
        $offY = $boxY + (int) round(($boxH - $extH) / 2);

        return [$offX, $offY, $extW, $extH];
    }

    /** @param array<string, mixed> $element */
    private function buildShape(array $element, int $shapeId): string
    {
        $xfrm = $this->xfrmFromFractions($element);
        $id = $element['id'] ?? "shape-{$shapeId}";
        $kind = (string) ($element['shape'] ?? 'rect');
        $prst = match ($kind) {
            'rect' => 'rect',
            'rounded-rect' => 'roundRect',
            'ellipse' => 'ellipse',
            'triangle' => 'triangle',
            'line' => 'line',
            'arrow' => 'rightArrow',
            default => 'rect',
        };

        [$fillHex, $fillAlpha] = Color::parse($element['fill'] ?? 'rgba(139,92,246,0.15)', '8B5CF6');
        [$strokeHex, ] = Color::parse($element['stroke'] ?? '#8B5CF6', '8B5CF6');
        $strokeWidthEmu = Emu::fromPt((float) ($element['strokeWidth'] ?? 2));
        $dashStr = !empty($element['dashed']) ? '<a:prstDash val="dash"/>' : '';

        $fillXml = $fillAlpha === 0
            ? '<a:noFill/>'
            : '<a:solidFill><a:srgbClr val="' . $fillHex . '"><a:alpha val="' . $fillAlpha . '"/></a:srgbClr></a:solidFill>';

        return '<p:sp>'
            . '<p:nvSpPr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvSpPr/>'
            . '<p:nvPr/>'
            . '</p:nvSpPr>'
            . '<p:spPr>'
            . $xfrm
            . '<a:prstGeom prst="' . $prst . '"><a:avLst/></a:prstGeom>'
            . $fillXml
            . '<a:ln w="' . $strokeWidthEmu . '"><a:solidFill><a:srgbClr val="' . $strokeHex . '"/></a:solidFill>' . $dashStr . '</a:ln>'
            . '</p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody>'
            . '</p:sp>';
    }

    /** @param array<string, mixed> $element */
    private function buildCodeShape(array $element, int $shapeId): string
    {
        $xfrm = $this->xfrmFromFractions($element);
        $code = (string) ($element['code'] ?? '');
        $id = $element['id'] ?? "code-{$shapeId}";
        $language = isset($element['language']) ? (string) $element['language'] : null;
        $body = $this->buildHighlightedCodeBody($code, $language);

        return '<p:sp>'
            . '<p:nvSpPr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvSpPr txBox="1"/>'
            . '<p:nvPr/>'
            . '</p:nvSpPr>'
            . '<p:spPr>'
            . $xfrm
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="0F172A"/></a:solidFill>'
            . '</p:spPr>'
            . $body
            . '</p:sp>';
    }

    /**
     * Build a `<p:txBody>` for a code block: monospace, dark fill, one
     * `<a:r>` per highlighted token so keywords / strings / comments /
     * numbers render in distinct colors.
     */
    private function buildHighlightedCodeBody(string $code, ?string $language): string
    {
        $sz = Emu::hundredthsOfPoint(12);
        $paragraphs = '';
        $lines = explode("\n", $code);
        foreach ($lines as $line) {
            $tokens = SyntaxHighlighter::tokenize($line, $language);
            $runs = '';
            foreach ($tokens as $token) {
                if ($token['text'] === '') {
                    continue;
                }
                $color = SyntaxHighlighter::colorFor($token['kind']);
                $runs .= '<a:r>'
                    . '<a:rPr lang="en-US" sz="' . $sz . '">'
                    . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
                    . '<a:latin typeface="Consolas"/>'
                    . '</a:rPr>'
                    . '<a:t>' . Xml::text($token['text']) . '</a:t>'
                    . '</a:r>';
            }
            if ($runs === '') {
                // Preserve blank lines so the rendered layout matches the source.
                $runs = '<a:endParaRPr lang="en-US" sz="' . $sz . '"/>';
            }
            $paragraphs .= '<a:p><a:pPr algn="l"/>' . $runs . '</a:p>';
        }

        return '<p:txBody>'
            . '<a:bodyPr wrap="square" anchor="t" rtlCol="0" lIns="91440" tIns="45720" rIns="91440" bIns="45720"/>'
            . '<a:lstStyle/>'
            . $paragraphs
            . '</p:txBody>';
    }

    /**
     * Build a real PPTX table — `<p:graphicFrame>` wrapping `<a:tbl>`.
     * Header row gets the accent color background + white bold text;
     * body rows alternate between transparent and a tinted "surface" fill
     * for readability.
     *
     * @param  array<string, mixed>  $element
     */
    private function buildTable(array $element, int $shapeId): string
    {
        $columns = is_array($element['columns'] ?? null) ? $element['columns'] : [];
        $rows = is_array($element['rows'] ?? null) ? $element['rows'] : [];
        if (empty($columns)) {
            return $this->buildPlaceholder('[table: no columns]', $element, $shapeId);
        }

        $totalWidthEmu = Emu::fromFracX((float) ($element['w'] ?? 0.5));
        $colCount = count($columns);
        $colWidthEmu = (int) round($totalWidthEmu / max(1, $colCount));

        // Approximate row height — 40pt header, 30pt body. Could be tighter.
        $headerRowH = Emu::fromPt(40);
        $bodyRowH = Emu::fromPt(30);

        $gridCols = '';
        foreach ($columns as $i => $_) {
            $gridCols .= '<a:gridCol w="' . $colWidthEmu . '"/>';
        }

        // Header row
        $headerCells = '';
        foreach ($columns as $col) {
            $label = (string) ($col['label'] ?? $col['key'] ?? '');
            $headerCells .= $this->buildTableCell($label, true);
        }
        $headerRow = '<a:tr h="' . $headerRowH . '">' . $headerCells . '</a:tr>';

        // Body rows
        $bodyRows = '';
        $rowIndex = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = '';
            foreach ($columns as $col) {
                $key = (string) ($col['key'] ?? '');
                $value = $row[$key] ?? '';
                $text = is_scalar($value) ? (string) $value : json_encode($value);
                $cells .= $this->buildTableCell((string) $text, false, $rowIndex % 2 === 1);
            }
            $bodyRows .= '<a:tr h="' . $bodyRowH . '">' . $cells . '</a:tr>';
            $rowIndex++;
        }

        $xfrm = $this->xfrmFromFractions($element);
        $id = $element['id'] ?? "table-{$shapeId}";

        return '<p:graphicFrame>'
            . '<p:nvGraphicFramePr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr>'
            . '<p:nvPr/>'
            . '</p:nvGraphicFramePr>'
            . '<p:xfrm>' . substr($xfrm, strlen('<a:xfrm>'), -strlen('</a:xfrm>')) . '</p:xfrm>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
            . '<a:tbl>'
            . '<a:tblPr firstRow="1" bandRow="1"><a:tableStyleId>{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}</a:tableStyleId></a:tblPr>'
            . '<a:tblGrid>' . $gridCols . '</a:tblGrid>'
            . $headerRow
            . $bodyRows
            . '</a:tbl>'
            . '</a:graphicData>'
            . '</a:graphic>'
            . '</p:graphicFrame>';
    }

    /**
     * Build a single `<a:tc>` table cell. Header cells get a violet fill +
     * white bold text; striped body rows get a subtle tint.
     */
    private function buildTableCell(string $text, bool $header, bool $striped = false): string
    {
        if ($header) {
            $fill = '<a:solidFill><a:srgbClr val="8B5CF6"/></a:solidFill>';
            $textColor = 'FFFFFF';
            $bold = ' b="1"';
        } else {
            $fill = $striped
                ? '<a:solidFill><a:srgbClr val="F8FAFC"/></a:solidFill>'
                : '<a:noFill/>';
            $textColor = '0F172A';
            $bold = '';
        }

        return '<a:tc>'
            . '<a:txBody>'
            . '<a:bodyPr wrap="square" anchor="ctr" lIns="91440" tIns="45720" rIns="91440" bIns="45720"/>'
            . '<a:lstStyle/>'
            . '<a:p><a:pPr algn="l"/><a:r><a:rPr lang="en-US" sz="1400"' . $bold . '><a:solidFill><a:srgbClr val="' . $textColor . '"/></a:solidFill></a:rPr><a:t>' . Xml::text($text) . '</a:t></a:r></a:p>'
            . '</a:txBody>'
            . '<a:tcPr>' . $fill . '</a:tcPr>'
            . '</a:tc>';
    }

    // ─── Charts ───────────────────────────────────────────────────────────

    /**
     * Build a chart element. The happy path translates the ECharts-style
     * `option` into a native OOXML chart part (`ppt/charts/chartN.xml`)
     * referenced by a `<p:graphicFrame>`. When the option can't be
     * translated (no recognisable series / categories, or an unsupported
     * series type) it falls back, in order, to:
     *
     *   1. a pre-rendered `image` / `src` data-URI on the element, embedded
     *      as a `<p:pic>`;
     *   2. a tidy titled placeholder box.
     *
     * Never throws — a chart it can't model degrades gracefully.
     *
     * @param  array<string, mixed>  $element
     * @param  list<array{id: string, type: string, target: string}>  $rels
     */
    private function buildChart(array $element, int $shapeId, int $slideNumber, array &$rels): string
    {
        $option = is_array($element['option'] ?? null) ? $element['option'] : null;
        $spec = $option !== null ? ChartTranslator::translate($option) : null;

        if ($spec !== null) {
            $this->chartCounter++;
            $n = $this->chartCounter;
            $archivePath = "ppt/charts/chart{$n}.xml";
            $this->chartFiles[$archivePath] = $this->buildChartPart($spec);

            $relId = 'rIdChart' . $n;
            $rels[] = [
                'id' => $relId,
                'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart',
                'target' => "../charts/chart{$n}.xml",
            ];

            return $this->buildChartFrame($element, $shapeId, $relId);
        }

        // Fallback 1: a pre-rendered chart image on the element.
        $preRender = $this->chartPreRenderSrc($element);
        if ($preRender !== null) {
            $imageElement = $element;
            $imageElement['src'] = $preRender;
            $imageElement['fit'] = $element['fit'] ?? 'contain';

            return $this->buildImageShape($imageElement, $shapeId, $slideNumber, $rels);
        }

        // Fallback 2: a titled placeholder box.
        $title = '';
        if (is_array($option)) {
            $title = (string) ($option['title']['text'] ?? '');
        }
        $label = $title !== '' ? $title : 'chart';

        return $this->buildChartPlaceholder($label, $element, $shapeId);
    }

    /**
     * Pull a pre-rendered chart image data-URI off the element, if present.
     * Accepts `image` or `src` carrying a `data:` URI.
     *
     * @param  array<string, mixed>  $element
     */
    private function chartPreRenderSrc(array $element): ?string
    {
        foreach (['image', 'src'] as $key) {
            $value = $element[$key] ?? null;
            if (is_string($value) && str_starts_with($value, 'data:')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Build the `<p:graphicFrame>` that hosts a native chart part, placed at
     * the element's x/y/w/h.
     *
     * @param  array<string, mixed>  $element
     */
    private function buildChartFrame(array $element, int $shapeId, string $relId): string
    {
        $xfrm = $this->xfrmFromFractions($element);
        $innerXfrm = substr($xfrm, strlen('<a:xfrm>'), -strlen('</a:xfrm>'));
        $id = $element['id'] ?? "chart-{$shapeId}";

        return '<p:graphicFrame>'
            . '<p:nvGraphicFramePr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvGraphicFramePr/>'
            . '<p:nvPr/>'
            . '</p:nvGraphicFramePr>'
            . '<p:xfrm>' . $innerXfrm . '</p:xfrm>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="' . self::NS_CHART . '">'
            . '<c:chart xmlns:c="' . self::NS_CHART . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="' . $relId . '"/>'
            . '</a:graphicData>'
            . '</a:graphic>'
            . '</p:graphicFrame>';
    }

    /**
     * Build a complete `ppt/charts/chartN.xml` part from a normalised chart
     * spec. Uses literal caches (`<c:strLit>` / `<c:numLit>`) so no embedded
     * workbook is required.
     *
     * @param  array{kind: string, title: string, categories: list<string>, series: list<array<string, mixed>>}  $spec
     */
    private function buildChartPart(array $spec): string
    {
        $kind = $spec['kind'];
        $plot = match ($kind) {
            'bar' => $this->buildBarChartXml($spec),
            'line' => $this->buildLineChartXml($spec),
            'pie' => $this->buildPieChartXml($spec),
            'scatter' => $this->buildScatterChartXml($spec),
            default => $this->buildBarChartXml($spec),
        };

        if ($spec['title'] !== '') {
            $title = '<c:title><c:tx><c:rich><a:bodyPr/><a:p><a:r><a:t>' . Xml::text($spec['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>';
        } else {
            $title = '<c:autoTitleDeleted val="1"/>';
        }

        return Xml::declaration()
            . '<c:chartSpace xmlns:c="' . self::NS_CHART . '" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:chart>'
            . $title
            . '<c:plotArea>'
            . '<c:layout/>'
            . $plot
            . '</c:plotArea>'
            . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
            . '<c:plotVisOnly val="1"/>'
            . '<c:dispBlanksAs val="gap"/>'
            . '</c:chart>'
            . '</c:chartSpace>';
    }

    /**
     * @param  array{categories: list<string>, series: list<array<string, mixed>>}  $spec
     */
    private function buildBarChartXml(array $spec): string
    {
        $sers = '';
        foreach ($spec['series'] as $idx => $series) {
            $sers .= '<c:ser>'
                . '<c:idx val="' . $idx . '"/>'
                . '<c:order val="' . $idx . '"/>'
                . $this->chartSeriesName($series, $idx)
                . $this->chartSeriesFill($idx)
                . $this->chartCatRef($spec['categories'], $series['values'])
                . $this->chartValRef($series['values'])
                . '</c:ser>';
        }

        return '<c:barChart>'
            . '<c:barDir val="col"/>'
            . '<c:grouping val="clustered"/>'
            . '<c:varyColors val="0"/>'
            . $sers
            . '<c:axId val="111111111"/>'
            . '<c:axId val="222222222"/>'
            . '</c:barChart>'
            . $this->buildCatValAxes();
    }

    /**
     * @param  array{categories: list<string>, series: list<array<string, mixed>>}  $spec
     */
    private function buildLineChartXml(array $spec): string
    {
        // Promote to an area chart when any series carries an areaStyle.
        $isArea = false;
        foreach ($spec['series'] as $series) {
            if (!empty($series['area'])) {
                $isArea = true;
                break;
            }
        }

        $sers = '';
        foreach ($spec['series'] as $idx => $series) {
            $smooth = (!$isArea && !empty($series['smooth'])) ? '<c:smooth val="1"/>' : '';
            $sers .= '<c:ser>'
                . '<c:idx val="' . $idx . '"/>'
                . '<c:order val="' . $idx . '"/>'
                . $this->chartSeriesName($series, $idx)
                . $this->chartSeriesLine($idx)
                . $this->chartCatRef($spec['categories'], $series['values'])
                . $this->chartValRef($series['values'])
                . $smooth
                . '</c:ser>';
        }

        if ($isArea) {
            return '<c:areaChart>'
                . '<c:grouping val="standard"/>'
                . '<c:varyColors val="0"/>'
                . $sers
                . '<c:axId val="111111111"/>'
                . '<c:axId val="222222222"/>'
                . '</c:areaChart>'
                . $this->buildCatValAxes();
        }

        return '<c:lineChart>'
            . '<c:grouping val="standard"/>'
            . '<c:varyColors val="0"/>'
            . $sers
            . '<c:marker val="1"/>'
            . '<c:axId val="111111111"/>'
            . '<c:axId val="222222222"/>'
            . '</c:lineChart>'
            . $this->buildCatValAxes();
    }

    /**
     * Pie charts carry a single series; each data point gets its own
     * colored `<c:dPt>` so slices read distinctly.
     *
     * @param  array{categories: list<string>, series: list<array<string, mixed>>}  $spec
     */
    private function buildPieChartXml(array $spec): string
    {
        $series = $spec['series'][0] ?? ['values' => [], 'name' => ''];
        $values = is_array($series['values'] ?? null) ? $series['values'] : [];
        $categories = $spec['categories'];

        $dPts = '';
        foreach ($values as $idx => $_) {
            $dPts .= '<c:dPt>'
                . '<c:idx val="' . $idx . '"/>'
                . '<c:bubble3D val="0"/>'
                . '<c:spPr><a:solidFill><a:srgbClr val="' . $this->chartColor($idx) . '"/></a:solidFill></c:spPr>'
                . '</c:dPt>';
        }

        return '<c:pieChart>'
            . '<c:varyColors val="1"/>'
            . '<c:ser>'
            . '<c:idx val="0"/>'
            . '<c:order val="0"/>'
            . $this->chartSeriesName($series, 0)
            . $dPts
            . $this->chartCatRef($categories, $values)
            . $this->chartValRef($values)
            . '</c:ser>'
            . '<c:firstSliceAng val="0"/>'
            . '</c:pieChart>';
    }

    /**
     * @param  array{series: list<array<string, mixed>>}  $spec
     */
    private function buildScatterChartXml(array $spec): string
    {
        $sers = '';
        foreach ($spec['series'] as $idx => $series) {
            $points = is_array($series['points'] ?? null) ? $series['points'] : [];
            $xs = [];
            $ys = [];
            foreach ($points as $point) {
                $xs[] = (float) ($point['x'] ?? 0);
                $ys[] = (float) ($point['y'] ?? 0);
            }
            $sers .= '<c:ser>'
                . '<c:idx val="' . $idx . '"/>'
                . '<c:order val="' . $idx . '"/>'
                . $this->chartSeriesName($series, $idx)
                . '<c:spPr><a:ln w="19050"><a:noFill/></a:ln></c:spPr>'
                . '<c:marker><c:symbol val="circle"/><c:size val="6"/><c:spPr><a:solidFill><a:srgbClr val="' . $this->chartColor($idx) . '"/></a:solidFill></c:spPr></c:marker>'
                . '<c:xVal>' . $this->numLit($xs) . '</c:xVal>'
                . '<c:yVal>' . $this->numLit($ys) . '</c:yVal>'
                . '</c:ser>';
        }

        return '<c:scatterChart>'
            . '<c:scatterStyle val="lineMarker"/>'
            . '<c:varyColors val="0"/>'
            . $sers
            . '<c:axId val="111111111"/>'
            . '<c:axId val="222222222"/>'
            . '</c:scatterChart>'
            . '<c:valAx><c:axId val="111111111"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:crossAx val="222222222"/></c:valAx>'
            . '<c:valAx><c:axId val="222222222"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:crossAx val="111111111"/></c:valAx>';
    }

    /** Category + value axis pair shared by bar / line / area charts. */
    private function buildCatValAxes(): string
    {
        return '<c:catAx>'
            . '<c:axId val="111111111"/>'
            . '<c:scaling><c:orientation val="minMax"/></c:scaling>'
            . '<c:delete val="0"/>'
            . '<c:axPos val="b"/>'
            . '<c:crossAx val="222222222"/>'
            . '</c:catAx>'
            . '<c:valAx>'
            . '<c:axId val="222222222"/>'
            . '<c:scaling><c:orientation val="minMax"/></c:scaling>'
            . '<c:delete val="0"/>'
            . '<c:axPos val="l"/>'
            . '<c:crossAx val="111111111"/>'
            . '</c:valAx>';
    }

    /**
     * Build the `<c:tx>` series-name node. Falls back to "Series N".
     *
     * @param  array<string, mixed>  $series
     */
    private function chartSeriesName(array $series, int $idx): string
    {
        $name = is_string($series['name'] ?? null) && $series['name'] !== ''
            ? (string) $series['name']
            : 'Series ' . ($idx + 1);

        return '<c:tx><c:strRef><c:f>Sheet1!$A$' . ($idx + 1) . '</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . Xml::text($name) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>';
    }

    /** Solid-fill `<c:spPr>` for a bar/area series, colored from the palette. */
    private function chartSeriesFill(int $idx): string
    {
        return '<c:spPr><a:solidFill><a:srgbClr val="' . $this->chartColor($idx) . '"/></a:solidFill></c:spPr>';
    }

    /** Line `<c:spPr>` for a line/area series, colored from the palette. */
    private function chartSeriesLine(int $idx): string
    {
        return '<c:spPr><a:ln w="28575"><a:solidFill><a:srgbClr val="' . $this->chartColor($idx) . '"/></a:solidFill></a:ln></c:spPr>';
    }

    /**
     * Category reference (`<c:cat>`) as a string literal cache. When there
     * are no categories, synthesises index labels so the cache point count
     * matches the value count (PowerPoint dislikes mismatches).
     *
     * @param  list<string>  $categories
     * @param  list<float>  $values
     */
    private function chartCatRef(array $categories, array $values): string
    {
        if ($categories === []) {
            $categories = [];
            foreach (array_keys($values) as $i) {
                $categories[] = (string) ($i + 1);
            }
        }

        $pts = '';
        foreach ($categories as $i => $label) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . Xml::text((string) $label) . '</c:v></c:pt>';
        }

        return '<c:cat><c:strLit><c:ptCount val="' . count($categories) . '"/>' . $pts . '</c:strLit></c:cat>';
    }

    /**
     * Value reference (`<c:val>`) as a numeric literal cache.
     *
     * @param  list<float>  $values
     */
    private function chartValRef(array $values): string
    {
        return '<c:val>' . $this->numLit($values) . '</c:val>';
    }

    /**
     * Numeric literal cache (`<c:numLit>`) shared by val / xVal / yVal.
     *
     * @param  list<float>  $values
     */
    private function numLit(array $values): string
    {
        $pts = '';
        foreach ($values as $i => $value) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . $this->numStr((float) $value) . '</c:v></c:pt>';
        }

        return '<c:numLit><c:formatCode>General</c:formatCode><c:ptCount val="' . count($values) . '"/>' . $pts . '</c:numLit>';
    }

    /** Render a float without a trailing `.0` / locale separators. */
    private function numStr(float $value): string
    {
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    /** Pick a series color: accent for the first series, palette thereafter. */
    private function chartColor(int $idx): string
    {
        if ($idx === 0) {
            return $this->themeAccent;
        }
        $palette = self::CHART_PALETTE;

        return $palette[$idx % count($palette)];
    }

    /**
     * Build a tidy titled placeholder box for an untranslatable chart — a
     * rounded rect with a tinted fill and the chart title / "chart" label.
     *
     * @param  array<string, mixed>  $element
     */
    private function buildChartPlaceholder(string $label, array $element, int $shapeId): string
    {
        $xfrm = $this->xfrmFromFractions($element);
        $id = $element['id'] ?? "chart-{$shapeId}";

        return '<p:sp>'
            . '<p:nvSpPr>'
            . '<p:cNvPr id="' . $shapeId . '" name="' . Xml::attr((string) $id) . '"/>'
            . '<p:cNvSpPr/>'
            . '<p:nvPr/>'
            . '</p:nvSpPr>'
            . '<p:spPr>'
            . $xfrm
            . '<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="F1F5F9"/></a:solidFill>'
            . '<a:ln w="12700"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></a:ln>'
            . '</p:spPr>'
            . '<p:txBody>'
            . '<a:bodyPr wrap="square" anchor="ctr" rtlCol="0"/>'
            . '<a:lstStyle/>'
            . '<a:p><a:pPr algn="ctr"/><a:r><a:rPr lang="en-US" sz="1600" b="1"><a:solidFill><a:srgbClr val="64748B"/></a:solidFill></a:rPr><a:t>' . Xml::text($label) . '</a:t></a:r></a:p>'
            . '</p:txBody>'
            . '</p:sp>';
    }

    /** @param array<string, mixed> $element */
    private function buildPlaceholder(string $label, array $element, int $shapeId): string
    {
        return $this->buildTextShape([
            'id' => $element['id'] ?? "placeholder-{$shapeId}",
            'type' => 'text',
            'x' => $element['x'] ?? 0.1,
            'y' => $element['y'] ?? 0.4,
            'w' => $element['w'] ?? 0.8,
            'h' => $element['h'] ?? 0.2,
            'content' => $label,
            'format' => 'plain',
            'style' => ['fontSize' => 20, 'align' => 'center', 'color' => '#64748B'],
        ], $shapeId);
    }

    // ─── Text body / paragraphs / runs ────────────────────────────────────

    /**
     * Build the `<p:txBody>` for a text shape.
     *
     * In "markdown" mode each paragraph is tokenized with
     * {@see MarkdownInline::tokenize()} so bold / italic / code spans
     * become real per-`<a:r>` `<a:rPr>` decorations rather than
     * flattening to plain text. Bulleted lines (`- ` / `* ` prefix)
     * become paragraphs with bullet point markup.
     *
     * @param  array<string, mixed>  $style
     */
    private function buildTextBody(string $content, array $style, string $format): string
    {
        $fontPt = (float) ($style['fontSize'] ?? 24);
        // Convert the design-width-relative font size to a usable PPTX size.
        // The fancy-slides design width is 1920px; PPTX assumes ~720px-wide
        // rendering at 10 inches, so we apply a heuristic divisor of 2 to
        // land in PPTX-sensible territory.
        $pt = max(8.0, $fontPt / 2);
        $sz = Emu::hundredthsOfPoint($pt);
        $baseBold = $this->weightToBold($style['weight'] ?? null);
        $baseItalic = !empty($style['italic']) ? ' i="1"' : '';
        $baseUnderline = !empty($style['underline']) ? ' u="sng"' : '';
        $align = $this->alignToAlgn($style['align'] ?? 'left');
        [$colorHex, ] = Color::parse((string) ($style['color'] ?? '#0F172A'), '0F172A');
        $fontFamily = isset($style['fontFamily']) ? '<a:latin typeface="' . Xml::attr((string) $style['fontFamily']) . '"/>' : '';
        $anchor = match ($style['verticalAlign'] ?? 'top') {
            'middle' => 't="ctr"',
            'bottom' => 't="b"',
            default => 't="t"',
        };

        $renderRuns = $format === 'markdown';

        $paragraphs = '';
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            // Paragraph-level markers: heading first (it disqualifies
            // bullets), then bullet. Heading content drops the `# ` prefix
            // and gets an inflated, bold-by-default size.
            $headingLevel = 0;
            $isBullet = false;
            $body = $line;
            if ($renderRuns) {
                [$headingLevel, $body] = MarkdownInline::headingPrefix($line);
                if ($headingLevel === 0) {
                    [$isBullet, $body] = MarkdownInline::bulletPrefix($line);
                }
            }

            // Heading lines get a bigger font + bold base; level 1 is
            // largest, falling toward the base body size at level 4+.
            $paragraphSz = $sz;
            $paragraphBold = $baseBold;
            if ($headingLevel > 0) {
                $multiplier = match ($headingLevel) {
                    1 => 1.8,
                    2 => 1.45,
                    3 => 1.2,
                    default => 1.0,
                };
                $paragraphSz = Emu::hundredthsOfPoint($pt * $multiplier);
                $paragraphBold = ' b="1"';
            }

            $pPr = '<a:pPr algn="' . $align . '"';
            if ($isBullet) {
                $pPr .= ' indent="-228600" marL="228600"><a:buFont typeface="Arial"/><a:buChar char="•"/>';
            } else {
                $pPr .= '><a:buNone/>';
            }
            $pPr .= '</a:pPr>';

            $runs = '';
            if ($renderRuns) {
                $tokens = MarkdownInline::tokenize($body);
                foreach ($tokens as $token) {
                    $runs .= $this->buildRun($token['text'], $paragraphSz, $paragraphBold, $baseItalic, $baseUnderline, $colorHex, $fontFamily, $token['b'], $token['i'], $token['code']);
                }
            } else {
                $runs = $this->buildRun($body, $paragraphSz, $paragraphBold, $baseItalic, $baseUnderline, $colorHex, $fontFamily, false, false, false);
            }

            $paragraphs .= '<a:p>' . $pPr . $runs . '</a:p>';
        }

        return '<p:txBody>'
            . '<a:bodyPr wrap="square" anchor="' . substr($anchor, 3, -1) . '" rtlCol="0"/>'
            . '<a:lstStyle/>'
            . $paragraphs
            . '</p:txBody>';
    }

    /**
     * Build a single `<a:r>` (drawingML text run) with the supplied base
     * formatting layered on top of inline markdown flags from the tokenizer.
     *
     * `code` spans switch to the theme's mono font + a darker fill so
     * they read as code on every theme. Inline `**bold**` overrides the
     * base weight even when the surrounding text was non-bold; inline
     * `*italic*` is additive.
     */
    private function buildRun(string $text, int $sz, string $baseBold, string $baseItalic, string $baseUnderline, string $colorHex, string $fontFamily, bool $bold, bool $italic, bool $code): string
    {
        $b = $bold ? ' b="1"' : $baseBold;
        $i = ($italic ? ' i="1"' : '') ?: $baseItalic;
        $u = $baseUnderline;
        $color = $colorHex;
        $family = $fontFamily;

        if ($code) {
            // Inline code: keep the run inline but switch font + tint.
            $color = '8B5CF6';
            $family = '<a:latin typeface="Consolas"/>';
        }

        $rPr = '<a:rPr lang="en-US" sz="' . $sz . '"' . $b . $i . $u . '><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>' . $family . '</a:rPr>';

        return '<a:r>' . $rPr . '<a:t>' . Xml::text($text) . '</a:t></a:r>';
    }

    /** @param mixed $weight */
    private function weightToBold(mixed $weight): string
    {
        if (is_numeric($weight) && (int) $weight >= 600) {
            return ' b="1"';
        }
        if (in_array($weight, ['bold', 'semibold'], true)) {
            return ' b="1"';
        }

        return '';
    }

    private function alignToAlgn(string $align): string
    {
        return match ($align) {
            'center' => 'ctr',
            'right' => 'r',
            'justify' => 'just',
            default => 'l',
        };
    }

    // ─── Geometry helper ──────────────────────────────────────────────────

    /** @param array<string, mixed> $element */
    private function xfrmFromFractions(array $element): string
    {
        $x = Emu::fromFracX((float) ($element['x'] ?? 0));
        $y = Emu::fromFracY((float) ($element['y'] ?? 0));
        $cx = Emu::fromFracX((float) ($element['w'] ?? 0));
        $cy = Emu::fromFracY((float) ($element['h'] ?? 0));
        $rot = isset($element['rotation']) ? (int) round(((float) $element['rotation']) * 60000) : 0;
        $rotAttr = $rot !== 0 ? ' rot="' . $rot . '"' : '';

        return '<a:xfrm' . $rotAttr . '><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>';
    }

    // ─── Media staging ────────────────────────────────────────────────────

    /**
     * @return array{relId: string, target: string, bytes: string}|null
     */
    private function stageMedia(string $src, int $slideNumber): ?array
    {
        $data = $this->loadImageBytes($src);
        if ($data === null) {
            return null;
        }
        $this->mediaCounter++;
        $i = $this->mediaCounter;
        $ext = $this->extensionForMime($data['mime']) ?? 'png';
        $archivePath = "ppt/media/image{$i}.{$ext}";
        $this->mediaFiles[$archivePath] = $data['bytes'];

        // Slide rels reference the media via a relative path. We assign rel
        // ids per slide; mediaCounter is global, so collisions can't happen.
        $relId = 'rId' . $i;

        return [
            'relId' => $relId,
            'target' => "../media/image{$i}.{$ext}",
            'bytes' => $data['bytes'],
        ];
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    private function loadImageBytes(string $src): ?array
    {
        // data: URI — decode inline.
        if (preg_match('/^data:([^;,]+)(?:;base64)?,(.*)$/s', $src, $m) === 1) {
            $mime = $m[1];
            $payload = $m[2];
            $bytes = str_contains($src, ';base64,') ? base64_decode($payload, true) : urldecode($payload);
            if ($bytes === false) {
                return null;
            }

            return ['bytes' => $bytes, 'mime' => $mime];
        }

        // file:// URL — read from disk.
        if (str_starts_with($src, 'file://')) {
            $path = substr($src, 7);
            if (!is_file($path)) {
                return null;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                return null;
            }

            return ['bytes' => $bytes, 'mime' => $this->guessMimeFromPath($path)];
        }

        // Local path (no scheme).
        if (!str_contains($src, '://') && is_file($src)) {
            $bytes = file_get_contents($src);
            if ($bytes === false) {
                return null;
            }

            return ['bytes' => $bytes, 'mime' => $this->guessMimeFromPath($src)];
        }

        // http(s) URLs are fetched only when the caller opted in via the
        // `allowHttpImages` flag — fetching remote URLs is a security
        // boundary. When OFF, returning null falls back to a placeholder.
        if ($this->allowHttpImages && preg_match('#^https?://#i', $src) === 1) {
            $bytes = @file_get_contents($src);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            return ['bytes' => $bytes, 'mime' => $this->guessMimeFromBytes($bytes, $src)];
        }

        return null;
    }

    /**
     * Best-effort MIME detection for fetched bytes: inspect the decoded
     * image header, falling back to the URL extension.
     */
    private function guessMimeFromBytes(string $bytes, string $src): string
    {
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime'])) {
            return $info['mime'];
        }
        $path = parse_url($src, PHP_URL_PATH);

        return is_string($path) ? $this->guessMimeFromPath($path) : 'image/png';
    }

    private function guessMimeFromPath(string $path): string
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

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            default => null,
        };
    }

    // ─── Slide rels ───────────────────────────────────────────────────────

    private function buildSlideRels(int $slideNumber, bool $hasNotes, int $layoutNumber = 1): string
    {
        $rels = '';
        // Required: slide → layout (the one matching the slide's layout).
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout' . $layoutNumber . '.xml"/>';
        $nextRelNum = 2;

        // Optional: slide → notesSlide.
        if ($hasNotes) {
            $rels .= '<Relationship Id="rId' . $nextRelNum . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $slideNumber . '.xml"/>';
            $nextRelNum++;
        }

        // Media + hyperlink rels (set during slide build via $pendingSlideRels).
        // External targets (hyperlinks) carry TargetMode="External".
        foreach ($this->pendingSlideRels[$slideNumber] ?? [] as $rel) {
            $mode = (($rel['mode'] ?? null) === 'External') ? ' TargetMode="External"' : '';
            $rels .= '<Relationship Id="' . Xml::attr($rel['id']) . '" Type="' . Xml::attr($rel['type']) . '" Target="' . Xml::attr($rel['target']) . '"' . $mode . '/>';
        }

        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    // ─── Notes slides ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $slide
     */
    private function buildNotesSlideXml(array $slide, int $slideNumber): string
    {
        $notes = (string) ($slide['notes'] ?? '');
        $paragraphs = '';
        foreach (explode("\n", $notes) as $line) {
            $paragraphs .= '<a:p><a:r><a:rPr lang="en-US" sz="1200"/><a:t>' . Xml::text($line) . '</a:t></a:r></a:p>';
        }

        return Xml::declaration()
            . '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld>'
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '<p:sp>'
            . '<p:nvSpPr>'
            . '<p:cNvPr id="2" name="Notes Placeholder"/>'
            . '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
            . '<p:nvPr><p:ph type="body"/></p:nvPr>'
            . '</p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="685800" y="1700213"/><a:ext cx="5772150" cy="3679371"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>'
            . $paragraphs
            . '</p:txBody>'
            . '</p:sp>'
            . '</p:spTree>'
            . '</p:cSld>'
            . '</p:notes>';
    }

    private function buildNotesSlideRels(int $slideNumber): string
    {
        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="../slides/slide' . $slideNumber . '.xml"/>'
            . '</Relationships>';
    }
}
