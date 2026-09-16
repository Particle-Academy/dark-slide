# AGENTS.md — dark-slide (PHP)

This file describes **this repository's code**: its API, its invariants, and the
traps in it. Process rules — release lifecycle, publishing, version policy,
backports — live in the envelope's `AGENTS.md` and must never be copied here; a
copy on a maintenance branch would freeze a rule that has since changed, with
nothing to flag it.

## What this is

A `.pptx` writer + reader with **no runtime dependencies** beyond `ext-zip`,
`ext-dom` and `ext-libxml`. It is one of three engines that must produce the
same document from the same deck:

| | |
|---|---|
| PHP | this repo — **the reference** |
| Node | `@particle-academy/dark-slide` |
| Python | `fancy-dark-slide` |

The deck model is plain arrays and `Schema\Validator` is the gate. Loose agent
JSON is the *input format*; `Agent::validateAndRepair()` exists to rescue it.

## The invariant everything else serves

> **This engine's OOXML parts are the reference the other two are diffed
> against.**

`dark-slide-js/tests/parity.test.ts` and `dark-slide-py/tests/test_parity_php.py`
both run THIS package as a subprocess and compare every part. So:

- **Never build XML with `DOMDocument` on the write side.** Attribute order,
  self-closing style and the absence of inter-element whitespace are all part of
  the output. `Helpers\Xml` is an escaper, not a serialiser, and the writer
  concatenates strings.
- **A change here breaks two other repos** until they are ported. Land all
  three, or land none.
- The reader is different: nothing is serialised on the read side, so
  `Reader\PptxReader` uses SimpleXML freely.
- **`read()` is a PURE function of its bytes**, and that is a contract rather
  than an observation. The same package read twice — in the same second or a
  year apart, on any machine — returns an identical structure, down to every
  generated id, because consumers store reads and DIFF them: one clock- or
  RNG-derived field turns a diff of unchanged content into a whole-deck replace.
  The deck id is CRC-32 of the package bytes; an element whose `<p:cNvPr>`
  carries no `name` is numbered by its position in the file. Nothing on the read
  side may put the clock, a random number or the environment into a returned
  value. Guarded by `tests/Unit/ReaderIsPureTest.php`.

  Both parity suites used to DELETE the deck id before comparing, so neither
  could see this — a comparison that drops the field it cannot explain asserts
  nothing about it, and all three engines had the same bug, which a suite that
  only detects disagreement will never report.

## Layout

```
src/
  Agent.php                 the public façade (validate / write / toBytes / read)
  Schema/                   Schema · Validator · Repairer
  Writer/PptxWriter.php     string building; the byte contract lives here
  Reader/PptxReader.php     SimpleXML; best-effort, degrades rather than raises
  Table/TableResolver.php   loose table element -> fully-decided cells
  Table/Composites.php      kpiBand / metadataGrid -> a table element
  Text/BoxDecoration.php    a text box's fill, outline, radius, insets, accent bar
  Helpers/                  Xml · Color · Emu · MarkdownInline · SyntaxHighlighter
                            · ChartTranslator
```

## The table model

`Table\TableResolver` is a **pure function**: a loose table element and a theme
go in, a table whose every cell carries every decision comes out. The writer
serialises what it is handed and makes no styling choices of its own.

Its output is in **points and 6-digit hex, never EMU**, deliberately: the same
decisions are what `last-word` needs for docx (`w:tcBorders` / `w:tcMar` /
`w:vAlign` / `w:gridSpan`), and a model expressed in one format's units cannot
be shared. The decisions are pinned cross-language in `fancy-conformance` as
`dark-slide/table-cell-model`.

**Precedence is the design**, and it is the half that byte parity cannot reach:

```
cell > row > column > band (header|stripe|body) > table > theme > default
```

**An absent key and a `false` key are different.** Absent falls through to the
next layer; `false` STOPS the chain and means off. That is why the resolver uses
`array_key_exists` rather than `??` in the places it does, and it is the most
likely thing for a port to get wrong — `?? ` passes the common case and fails
every "turn this one off" case.

## Traps

### 1. `<a:tcPr>` has a fixed child order

`lnL, lnR, lnT, lnB, …, fill`. Emitting the fill first parses fine and produces
a file whose **fill is dropped on the floor** by the reader. Found by rendering,
not by reading the spec.

### 2. `gridSpan` / `rowSpan` / `hMerge` / `vMerge` belong on `<a:tc>`

Not on `<a:tcPr>`. On `tcPr` they are well-formed and **silently ignored**, so
the table renders unmerged with no error anywhere.

### 3. "No border" is STATED, never omitted

An absent `<a:lnL>` is not an absent rule — it is an *unspecified* one, and a
reader fills it in from its own default table style. LibreOffice draws a full
grid over a table carrying no line elements at all, which is exactly how a
`borders: false` metadata panel came back fully ruled. Emit
`<a:lnL><a:noFill/></a:lnL>`.

The same shape applies to shapes: `<a:ln w="0">` is a **hairline** in every
renderer tested, not an absence. "No outline" is `<a:ln><a:noFill/></a:ln>`.

### 4. A left accent bar is a gradient, not a second shape

DrawingML has no per-side border on a shape — `<a:ln>` is all four sides or
none. `<a:gradFill>` with two stops at ADJACENT positions is a hard edge, so
`Text\BoxDecoration` paints a bar and a flat tint in ONE shape. The alternative
(a background rect plus a thin rect plus a text box) costs three elements, a
z-order the author has to get right, and two extra shape ids for the animation
builder to renumber.

### 5. Every length is a design pixel, converted in ONE place

`Helpers\DesignUnits::toPt()` is `px * 720 / designWidth`, where `designWidth` is
`theme.slideWidth ?? 1920` and 720 is the 10in slide in points. `fontSize: 96` is
36pt, which is 5% of the slide width in PowerPoint and in fancy-slides alike.

- It applies to every AUTHORED length: `fontSize`, `strokeWidth`, `letterSpacing`,
  `spaceBefore`/`spaceAfter`, `padding`, `radius`, border and accent-bar widths,
  table row heights, and the composites' own defaults.
- It does NOT apply to PowerPoint-native defaults that were never authored: the
  7.2pt / 3.6pt insets, a 1pt box outline, a 0.75pt table rule, 40pt / 30pt
  minimum rows, a 4pt accent bar, the 8pt gutter. A deck that never sets those is
  unaffected, which is the point.
- Compute it in exactly that order (`px * 720 / width`) in every engine. The EMU
  and hundredths-of-a-point values are rounded from it, and a different operation
  order can move a result across a rounding boundary.
- `theme.aspectRatio` shapes the slide height (`DesignUnits::slideHeightEmu()`),
  so every Y conversion takes `$this->slideHeightEmu`, never the 16:9 default.

**Until 0.10 this was a halving** (`fontSize / 2`, 8pt floor) with every other
length taken as points, on a 720pt slide. That made PowerPoint text a third larger
than the fancy-slides preview, `Layout::fit` assumed a third canvas (1280), and one
style object mixed two units. `theme.slideWidth: 1440` reproduces the old
halving exactly; `tests/fixtures/reference-deck.json` uses it, with its
former point lengths doubled, so the acceptance deck still renders as it did.

### 6. Composites are sugar and are read back as their expansion

`kpiBand` and `metadataGrid` become a `table` in `buildElementXml` before
anything is serialised. That keeps the OOXML surface and the reader shape
unchanged, and keeps `@particle-academy/fancy-slides` able to render the schema.
The cost is a one-way loss: read a deck back and a composite is the table it
became. Documented rather than hidden — a reader inventing intent it cannot
recover is worse.

### 7. The reader must not assume a header row

Whether row 0 is a header is declared by `<a:tblPr firstRow="1">`. Assuming it
promoted a row of DATA to column labels and dropped it from the rows. Every
`metadataGrid` and `kpiBand` is a header-less table, so this is the common case.

### 8. The rel-id collision is reproduced, not fixed

Image relationship ids come from a **global** media counter while a slide's own
rels start at `rId1`, so one image on one slide already emits two
`<Relationship Id="rId1">` entries. Every `.pptx` with an image that any of the
three engines has written carries it. **Do not fix it here alone** — that breaks
part-level parity, which is the only cross-runtime guarantee the trio has. It
needs one coordinated release across all three.

## What PPTX cannot express

Worth knowing before designing around it:

- **A table header row that repeats across a break.** DOCX has `w:tblHeader`;
  PPTX has no pagination at all, so a table taller than its frame is clipped,
  not continued. Author it as two tables on two slides.
- **Anything else pagination-derived** — running headers keyed to a page number,
  "continued" labels, orphan/widow control. (A slide *number* via `<a:fld>` is a
  different thing and is reachable.)
- **Producer-computed auto-fit.** `<a:normAutofit fontScale="…">` exists, but the
  scale has to be computed from text metrics this package does not have and will
  not grow. PowerPoint recomputes on edit; on first open it honours whatever is
  stored, so emitting a guess is worse than emitting nothing.
- **Row height as "auto".** `<a:tr h>` is required. It behaves as a minimum in
  every renderer tested, which is close enough, but there is no declarative
  "fit the content".

## Embedded fonts (`Fonts\`)

Opt-in through the write options (`fonts: typeface => [variant => path|bytes]`),
never through the deck: an agent names a face, the host supplies the licensed file.

- **`.fntdata` is an EOT, not a `.ttf`.** Verified by rendering in LibreOffice 26:
  an uncompressed EOT renders the embedded face; the same font stored raw, or
  GUID-obfuscated as Word does, falls back. `FontEmbeddingTest`'s render case
  (`DARK_SLIDE_RENDER=1`) goes red if the wrapper is dropped.
- **Uncompressed.** LibreOffice's libeot here cannot read MicroType Express.
- **TrueType outlines only** (`OTTO` and `ttcf` are refused). LibreOffice renders a
  CFF font too, but PowerPoint's acceptance is unverified.
- **The licence is enforced here or nowhere.** LibreOffice renders a font whose
  `fsType` forbids embedding, so `EmbeddedFonts` refuses Restricted License
  (without a less restrictive bit) and bitmap-only.
- **Refusals throw, together, before anything is written.** A skipped font is a
  deck in a substitute face with nothing saying so.
- **No font supplied means no byte changes**, which keeps both parity suites green.
  `embedTrueTypeFonts="1"` REPLACES `saveSubsetFonts="1"` only when a font is
  embedded: that flag declares subsets, and these are whole fonts.
- **PowerPoint and Google Slides are not verified.** Neither is installed here.
- Tests use `tests/Support/GeneratedFont`, a TrueType font built in memory, so no
  third-party font file is ever committed.

Also worth recording: **LibreOffice Impress does not honour `hMerge` on import.**
It draws the split rule and both cell texts. The construction is spec-correct and
is what PowerPoint expects, but it cannot be verified by rendering in Impress, so
nothing in the reference deck depends on merging for its look.

## Testing

```bash
composer install
vendor/bin/pest
```

`tests/fixtures/reference-deck.json` is the acceptance artifact — nine slides
covering every rich construct — and is loaded by the Node and Python parity
suites from this repo, so the three engines are compared on the same bytes
rather than on three transcriptions of the same intent. Do not move it without
updating both.
