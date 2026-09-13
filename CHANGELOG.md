# Changelog

## [Unreleased]

**BREAKING, for how big things are, not for any API.** Pre-1.0, so this lands in
a minor.

### Changed

- **BREAKING: decks are drawn at the size fancy-slides draws them.** Every length
  in a deck is now a design pixel on a canvas `theme.slideWidth` wide (1920 by
  default) and converts as `points = px × 720 / slideWidth`. `fontSize: 96` is
  36pt, 5% of the slide width, which is what fancy-slides shows.

  Before, `fontSize` was halved into points with an 8pt floor (96 → 48pt, a third
  larger than the preview) and every other length was taken as points, so one
  style object mixed two units and text fitted in the preview overflowed in
  PowerPoint. Now converted identically: `fontSize`, `strokeWidth`,
  `letterSpacing`, `spaceBefore`, `spaceAfter`, `padding`, `radius`, border and
  accent-bar widths, and table row heights. The floor is 1pt (PPTX's minimum).
  Built-in defaults that are PowerPoint's own (text insets, a 1pt outline, a
  0.75pt table rule, minimum row heights, a 4pt accent bar) stay in points.

  **What to do:** nothing, if your decks were designed in fancy-slides; they now
  match it. To keep a 0.9 deck's output exactly, set `theme.slideWidth: 1440` and
  double every length you had written in points (the list above, minus
  `fontSize`). Composites (`kpiBand`, `metadataGrid`) already did this to their
  own defaults, so at 1440 they render as before.

- **The text default is 28 design px** (10.5pt), fancy-slides' own default,
  instead of 24.

- **Code blocks take `style.fontSize`**, default 32 design px (12pt, the size
  they were fixed at).

- **`Layout::fit` estimates on the same canvas as the writer**: `slideWidth`
  defaults to 1920 instead of 1280, and a new `aspectRatio` option replaces a
  fixed 16:9. Text that fits in the preview is no longer shrunk.

### Fixed

- **`theme.aspectRatio` shapes the slide.** It was validated, published in the
  schema and ignored, so a 4:3 deck came out stretched onto 16:9. The slide stays
  10in wide; 16:9, 16:10 and 4:3 get PowerPoint's named `<p:sldSz type>`, any
  other ratio a custom size.

- **`Layout::fit` no longer needs `ext-mbstring`.** It called `mb_strlen`, which
  this package never declared.

- **The README linked to `docs/schema.md`, which does not exist.** It now
  describes the unit model and points to `Agent::jsonSchema()`, the actual
  reference.

### Added

- **Embed the host's fonts in the file**, so brand typography survives machines
  that do not have it installed. Pass `fonts` in the write options (`write`,
  `toBytes`, `toStream`):

  ```php
  Agent::write($deck, $path, ['fonts' => [
      'Bebas Neue' => ['regular' => '/fonts/BebasNeue-Regular.ttf'],
      'Inter' => ['regular' => $bytes, 'bold' => '/fonts/Inter-Bold.ttf'],
  ]]);
  ```

  Each variant (`regular`, `bold`, `italic`, `boldItalic`) is a path or the font
  bytes. The deck itself never carries a font, so an agent can name a typeface
  but never make the writer read a file.

  Fonts are written as uncompressed Embedded OpenType in `ppt/fonts/fontN.fntdata`,
  with `<p:embeddedFontLst>` and `embedTrueTypeFonts="1"`. **Verified by rendering
  in LibreOffice 26**: the embedded face renders, and the same deck without it
  falls back. **Not verified in PowerPoint or Google Slides**, which are not
  available here.

  Refused, all at once and before anything is written, with
  `FontEmbeddingException`: fonts whose licence (`fsType`) forbids embedding or
  allows bitmaps only, CFF-outline `.otf` and `.ttc` collections, and a file whose
  family name is not the typeface it was supplied for (the deck would never use
  it).

  `Agent::read()` reports embedded typefaces and variants in
  `metadata.embeddedFonts`, never the bytes.

  **Nothing changes for a deck written without `fonts`**: same parts, same bytes.

## v0.9.2 — 2026-09-13

### Fixed

- **The published schema says what unit every `style` field is in.**
  `Agent::jsonSchema()` exported `style` as a bare `{type: object}`, so a model
  filling it in had only the key names, and `fontSize` reads as points. It is
  design pixels on the 1920px fancy-slides canvas, halved into points with an
  8pt minimum. In the fancy-labs document lab an agent described its headline as
  232pt, and the file it wrote carried 116pt.

  The style object also mixes units, which no key name conveys: `letterSpacing`,
  `spaceBefore`, `spaceAfter`, `padding`, `radius` and the border and accent-bar
  widths are already points, and `lineHeight` is a multiple. Every field the
  writer reads now carries a description with a worked example, and `x`, `y`,
  `w` and `h` say they are fractions of the slide.

  **Upgrade and do nothing.** Descriptions and permissive types only: the
  validator never reads this export and the writer's bytes are unchanged, so no
  deck validates or renders differently. If you register the schema as an LLM
  tool, the model now sees the units.

  `SchemaDescribesStyleUnitsTest` scans the writer for every `$style['…']` key
  and fails if one is undescribed, and checks each worked example against the
  XML the writer emits. The Node and Python ports publish the identical schema
  and diff theirs against this one.

## v0.9.1 — 2026-09-10

### Fixed

- **`version()` reports the version this package actually ships as.** It
  returned `0.6.0` from a 0.9.x release. The constant had drifted because
  nothing compared it to the packaging metadata — the same shape as every other
  two-copies-of-one-number failure in this estate.

  `VersionIsSingleSourcedTest` / `version.test.ts` now pins it, so the class is
  closed rather than the instance fixed. `dark-slide-py` already had that
  assertion and was the only engine in the family to catch itself.


## v0.9.0 — 2026-09-10

Rich document constructs: per-cell table control, decorated text boxes,
paragraph controls, text inside shapes, and two composite elements. Pre-1.0, so
this lands in a MINOR.

### Added

- **Per-cell table control.** A `table` element resolves through a documented
  precedence chain — `cell > row > column > band (header|stripe|body) > table >
  theme > default` — and every cell now carries its own decisions:

  - **Borders**, per side, with a width in points, a colour and a
    `solid`/`dash`/`dot` style. Shorthands: a bare `{width,color}` for all four
    sides, `all`, and `outer` / `inner` which resolve by the cell's position in
    the grid. Any side can be switched off with `false`.
  - **Insets** (`style.padding`), a number for all four sides or a map naming
    the ones you want.
  - **Vertical anchor** (`style.anchor`: `top` / `middle` / `bottom`).
  - **Merging**: `colSpan` and `rowSpan` on a cell. Spans are clamped to the
    grid, and the cells a span covers are still emitted as continuations — a row
    with fewer cells than the grid declares is a corrupt file, not a narrow
    table.
  - **Column widths** (`width` on a column). Values `<= 1` are fractions of the
    table and columns without one share the remainder; any value `> 1` makes
    them all weights. Widths are accumulated and differenced so they sum to the
    table's width EXACTLY.
  - **Per-row and per-cell styling**: a row may be written as
    `{cells: {...}, fill, color, bold, align, anchor, fontSize, letterSpacing,
    caps, padding, borders, height}`, and any cell value may be an object
    carrying the same keys plus `text`.
  - **Band configuration**: `style.header` (or `false` for no header row),
    `style.body`, `style.stripe` (or `false` for no striping), `style.rowHeight`.

- **Decorated text boxes.** A `text` element's `style` takes `fill`, `border`,
  `padding`, `radius`, and `accentBar` — a coloured bar down one edge, drawn as
  a hard-stop `<a:gradFill>` so the bar and the tint are ONE shape. DrawingML
  has no per-side border on a shape, so this construct previously meant stacking
  a background rect, a thin rect and a text box in the right z-order.

- **Paragraph and run controls** on any text body: `lineHeight` (a multiple),
  `spaceBefore` / `spaceAfter` (points), `letterSpacing` (points), `caps`
  (`small` / `all`), and `bullet` — a literal character (which is all a
  check-mark list is), `none`, or `number`.

- **Text inside shapes.** A `shape` element takes `content`, `format` and
  `style`. Its text body used to be unconditionally empty.

- **Composite elements `kpiBand` and `metadataGrid`.** Authoring sugar: each
  expands into an ordinary `table` before anything is serialised, so they add no
  new OOXML and no new reader shape. A composite read back comes back as the
  table it became.

- **A reference deck fixture and its acceptance test.** A nine-slide deck built
  from the construct classes of a real paginated business document — metadata
  grid, KPI band, accent-bar callouts, tables with a highlighted total row,
  check-mark lists, a three-column comparison. It is the shared fixture for all
  three engines, compared byte-for-byte.

- **A cross-language conformance suite**, `dark-slide/table-cell-model` in
  `fancy-conformance`, pinning the resolver's decisions. Byte parity proves the
  engines agree on the inputs it runs; these rows walk the precedence chain one
  layer at a time, which byte parity on a single deck cannot.

### Changed

Six changes alter emitted bytes. Five need nothing from you; one can.

- **Table header fill and zebra derive from `theme.colors.accent`** instead of a
  hardcoded violet. `#8B5CF6` is the default accent, so a deck that sets no
  accent is unchanged. **What you must do:** nothing — unless your deck sets an
  accent and you wanted the violet, in which case set `style.header.fill`.

- **Table cells now state their borders.** Previously no line elements were
  emitted at all, which is not "no rules" — it is "unspecified", and each reader
  drew its own default table style. The default is now an explicit 0.75pt
  `#D9DEE4` grid, and "no border" is emitted as an explicit empty line rather
  than by omission. **What you must do:** nothing — unless you want no rules, in
  which case `style: {borders: false}`.

- **Cell insets and vertical anchor moved from `<a:bodyPr>` to `<a:tcPr>`**,
  which is where the schema puts them for a table cell. **What you must do:**
  nothing.

- **The table style id changed** from Medium Style 2 Accent 1 to No Style, No
  Grid. Every fill and rule is now stated per cell, so a built-in style is a
  second opinion layered on ours rather than a default to fall back on. **What
  you must do:** nothing — unless you relied on PowerPoint's own banding, which
  is now baked per cell and configurable.

- **`strokeWidth: 0` or `stroke: "none"` on a shape emits no outline.** It used
  to emit `<a:ln w="0">`, which every renderer draws as a hairline, so "no
  outline" was not sayable. **What you must do:** nothing — unless you relied on
  the hairline, in which case give `strokeWidth` a real value.

- **An object-valued table cell is now read as a cell SPEC** when it carries any
  of the spec keys (`text`, `fill`, `color`, `bold`, `italic`, `underline`,
  `align`, `anchor`, `fontSize`, `letterSpacing`, `caps`, `fontFamily`,
  `padding`, `borders`, `colSpan`, `rowSpan`). It used to be JSON-stringified
  into the cell text. **What you must do:** if you were deliberately displaying
  the JSON of an object that happens to carry one of those keys, wrap it —
  `{"text": "<the json>"}`. An object with NONE of those keys still stringifies
  exactly as before, so most callers are unaffected.

### Fixed

- **`theme.fonts.mono` now reaches the code it names.** It was accepted by the
  validator, published in the JSON Schema handed to an LLM as the tool
  definition, and described in the writer's own docblocks as the font code runs
  switch to — and applied nowhere. Both places that render code (block elements
  and inline `` `code` `` spans) hardcoded `Consolas`, so a brand deck asking for
  JetBrains Mono got Consolas, rendered perfectly, and was quietly off-brand.

  All three engines had it, identically, which is why no parity test caught it:
  **parity detects disagreement, and they agreed.**

  **What you must do: nothing.** A deck that sets no `fonts.mono` still renders
  in Consolas, byte for byte as before.

- **A code run written in a brand mono font reads BACK as code.** The reader
  identified code by looking for "consola", "mono" or "courier" in the typeface
  name — sound while the writer always emitted Consolas, and not sound once a
  deck can name its own font, since "Fira Code" and "Cascadia" contain none of
  those words. The deck's mono typeface is now recorded in `theme1.xml`'s
  `<a:extLst>` (there is no third slot in `<a:fontScheme>` for it) and matched
  exactly on read; the name sniff remains the fallback for files written by
  anything else, including earlier versions of this package.

- **A highlighted line no longer comes back shredded into one span per token.**
  A syntax highlighter emits one run per token and every one of them is code, so
  the reader emitted a marker per run: `const deck = 1;` returned with each pair
  of adjacent backticks closing one span and opening the next, meaning a
  re-parse yields the INVERSE of the emphasis it was preserving. Adjacent runs
  carrying the same decoration are now merged before anything is emitted, so the
  output no longer depends on how many runs the writer split the text into.

- **The reader dropped the first data row of a header-less table.** It assumed
  row 0 was always a header; whether it is one is declared by
  `<a:tblPr firstRow="1">`. Header-less tables only became ordinary with this
  release — every `metadataGrid` and `kpiBand` is one — so the bug is new
  surface rather than an old one, but the reader now honours the declaration.

- **Column widths declared on a column were discarded**, and every table was an
  equal split. The reader also now recovers widths as fractions.

## v0.8.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.

## v0.7.0 — 2026-06-09

A "felt-while-using" follow-up surfaced by Decksmith's adoption of the 0.6 API.

### Added
- **Strict-mode `Reducer`** (#8) — `Reducer::strictApply($deck, $op)` (and an
  `apply($deck, $op, ['onMissing' => 'throw'])` opts arg) throws
  `InvalidArgumentException` naming the missing slide/element when an op targets
  one that isn't on the deck, instead of silently returning the deck unchanged.
  The default stays silent-skip (resilient broadcast replay); strict mode is the
  signal agent/MCP tool paths need so a typo'd `slideId` doesn't report success.
  `applyAll` forwards the opts. Twin of `@particle-academy/fancy-slides`'
  `reduce(deck, op, { onMissing: 'throw' })`. Fully backward-compatible.

## v0.6.0 — 2026-06-08

The "agent kit, not just a writer" release — resolving the whole open issue set
(filed from Decksmith) and the shared op contract with `@particle-academy/fancy-slides`.

### Added — the shared op spine
- **`DarkSlide\Reducer::apply($deck, $op)`** — the server-authoritative twin of
  fancy-slides' `reduceDeck`; applies a canonical `DeckOp` and returns a new
  deck. Route `<DeckEditor>`'s `onOp` straight through it — one reducer in
  whichever language hosts the deck. (#1)
- **`Agent::diff($a, $b): DeckOp[]`** — the op list that turns `$a` into `$b`, in
  the shared vocabulary. Verify-and-fallback guarantees the round-trip property
  `reduce($a, diff($a, $b)) == $b`. Plus `Agent::reduce()`. (#5)
- **`DeckOpSchema` / `Agent::opSchema()`** — the DeckOp JSON Schema of record,
  byte-aligned with fancy-slides' `deckOpSchema()`. (#1)

### Added — export DX
- **`ImageResolver`** (+ `Images\LocalFileImageResolver`, `Images\CallbackImageResolver`)
  — defer image-byte resolution to the host; pass via the `images` write option.
  No more walking the deck to inline data URIs before export. (#2)
- **`DarkSlide\Layout::fit($slide, $opts)`** — opt-in snap-to-grid, overlap
  reflow, and fit-text for machine-authored slides. Pure; never automatic. (#3)
- **`Agent::toStream($deck): resource`** — streamed-export counterpart to
  `toBytes()` for `response()->stream(...)` (returns a PHP stream resource to
  stay dependency-free). (#6)
- **Chart export strategy** — `chart.mode = 'png' | 'native'` (default `'png'`):
  PNG renders via a consumer `ChartRenderer` (or a pre-rendered `chart.image`) so
  the .pptx matches the editor; degrades to the native OOXML chart when no PNG
  source is available, so existing decks are unaffected. Pass a renderer via the
  `charts` write option. (#4)
- **`slide.narration`** schema field — plain-text narration for AI-narrated decks
  (opaque to the writer); `notes` documented as Markdown. (#7, partial — reading
  arbitrary non-fancy-slides `.pptx` remains future/experimental.)

### Compatibility
- Additive. No breaking changes; existing schemas + exports behave identically.
- Still zero third-party runtime deps.

## v0.5.2 — 2026-05-29

### Writer — emit whole-element hyperlinks

- An element's `href` now exports as a real pptx hyperlink: the writer injects
  `<a:hlinkClick r:id="…">` into the shape's `<p:cNvPr>` and registers an
  external slide relationship (`TargetMode="External"`) pointing at the URL.
  Completes the `href` support added to the schema in 0.5.1 (no longer carried
  only in the deck JSON). Works for every element type (text / image / shape /
  code / table / chart). Inline links inside text via markdown `[label](url)`
  continue to export as before.

## v0.5.1 — 2026-05-29

### Schema — keep lockstep with fancy-slides

- Accept an optional `element.href` (whole-element hyperlink) in the schema /
  validator so it survives `validateAndRepair` and round-trips through the deck
  JSON. Mirrors `fancy-slides` `ElementBase.href` (added in fancy-slides 0.9.0).
  The writer does not yet emit `<a:hlinkClick>` for it — whole-element href is
  carried in the deck JSON for the host, while inline links inside text via
  markdown `[label](url)` continue to export as real hyperlinks.

## v0.5.0 — 2026-05-29

### Element entrance animations → OOXML `<p:timing>`

- New optional `element.animation` — `{ effect: fade|fly-in|zoom|wipe,
  trigger?: on-click|with-prev|after-prev, direction?: left|right|up|down,
  duration?: ms, delay?: ms, order?: number }`. Mirrors the shape
  `@particle-academy/fancy-slides` emits, so build steps authored in the
  editor now export to PowerPoint.
- Slides with any animated element emit a real `<p:timing>` tree (last child
  of `<p:sld>`, after any `<p:transition>`, per CT_Slide's element order). The
  tree is a `tmRoot` `<p:par>` → `mainSeq` `<p:seq>` with one click-step
  `<p:par>` per build step. Builds are stable-sorted by `(order ?? 0)` then
  array index, then grouped exactly like fancy-slides' `buildSteps()`: the
  first build and every `on-click` build open a new step (gated by
  `<p:cond delay="indefinite"/>`); `with-prev` attaches with begin 0;
  `after-prev` begins after the lead build's duration.
- Each build targets its shape via `<p:spTgt spid="N">`, where `N` is the exact
  `<p:cNvPr id>` assigned when the shape was emitted (captured during body
  emission, never recomputed — so the target always matches even when the
  shape-id counter skips elements that render to nothing).
- Effect mapping: `fade` → `<p:animEffect transition="in" filter="fade">`;
  `fly-in` → `<p:anim>` translating `ppt_x`/`ppt_y` from off-slide to final;
  `zoom` → a `<p:animScale>` growing from a point (`<p:from x="0" y="0"/>` →
  `<p:to x="100000" y="100000"/>`) run concurrently with a fade `<p:animEffect>`
  — a generic `<p:anim>` on `ppt_w`/`ppt_h` pops rather than grows, so the
  dedicated scale behavior is required for PowerPoint to render the grow;
  `wipe` → `<p:animEffect filter="wipe(dir)">` keyed to direction. Each
  entrance pairs with a `style.visibility` `<p:set>`.
- **By-paragraph builds.** A text element's `animation` may set
  `byParagraph: true` (PowerPoint "By paragraph"): the writer splits its
  `content` into paragraphs the SAME way the text body does (`explode("\n")`,
  so paragraph index *i* lines up with `<a:p>` index *i* in `<p:txBody>`) and
  emits ONE build node per paragraph, each scoped to that single line via
  `<p:spTgt spid="N"><p:txEl><p:pRg st="i" end="i"/></p:txEl></p:spTgt>`. The
  hide-until-built `<p:set>` is paragraph-scoped too, so each line stays hidden
  until its own build fires. The element's first paragraph keeps the element's
  `trigger` (and its place from `order`); every later paragraph is its own
  `on-click` step (one line per click). Non-text elements and text without
  `byParagraph` are unchanged (whole-shape `<p:spTgt spid="N"/>`).
- Schema: `animation` gains an optional `byParagraph: boolean`.
- Animated shapes are hidden at slide load (an instantaneous visibility→hidden
  `<p:set>` group that fires before the first click) and re-shown when their
  build fires, so a not-yet-built element never pre-shows. Elements with no
  `animation` are untouched — always visible, no timing node.
- Schema: added `ANIMATION_EFFECTS` / `ANIMATION_TRIGGERS` /
  `ANIMATION_DIRECTIONS` constants and an `animation` object in
  `jsonSchema()`'s element properties. Validation stays liberal.

## v0.4.2 — 2026-05-29

### Fixed
- `zoom` transition now actually animates. Modern PowerPoint dropped the legacy
  `<p:zoom>` transition from its render engine entirely (the 0.4.1 `dir="in"`
  attempt still didn't play), so `zoom` now maps to `<p:circle/>` — an iris
  grow-from-centre, the closest effect to a zoom that reliably animates.

## v0.4.1 — 2026-05-29

### Fixed
- Attempted zoom fix via `<p:zoom dir="in"/>` (superseded by 0.4.2 — modern
  PowerPoint does not render `<p:zoom>` at all).

## v0.4.0 — 2026-05-29

A complete presentation: slide transitions, image fit/crop, native charts,
and pptx theme + layout styling.

### Slide transitions
- New optional `slide.transition` — `{ kind: none|fade|slide|zoom,
  duration?: ms, direction?: left|right|up|down }`. Emits a real
  `<p:transition>` per slide: `fade` → `<p:fade/>`, `slide` →
  `<p:push dir="l|r|u|d"/>`, `zoom` → `<p:zoom/>`. Speed (`spd`) derives
  from `duration` (>=700 slow / <=250 fast / else med). Falls back to
  `theme.defaultTransition` when a slide has none; `none` is omitted.

### Images — fit + crop
- `fit` is now honoured. `fill` (default) stretches; `cover` fills the box
  and centre-crops the overflowing axis via a computed `<a:srcRect>`;
  `contain` / `scale-down` letterbox inside the box (shrunk `<a:off>`/
  `<a:ext>`, no crop). Intrinsic dimensions read via
  `getimagesizefromstring`.
- Explicit `crop: {x,y,w,h}` (0..1 of source) → `<a:srcRect>` in
  thousandths-of-percent; takes precedence over `fit`.
- Opt-in HTTP(S) image fetch — `new PptxWriter($tempDir, allowHttpImages:
  true)` or `Agent::write($deck, $path, ['allowHttpImages' => true])`.
  OFF by default (security boundary); when OFF, remote URLs keep the
  text-placeholder fallback.

### Charts — native OOXML chart parts
- `chart` elements now emit a real `ppt/charts/chartN.xml` part referenced
  by a `<p:graphicFrame>`, with the `[Content_Types].xml` override + slide
  relationship wired up. New pure-PHP `ChartTranslator` reads an ECharts-
  style `option` (categories from `xAxis.data` / `xAxis[0].data` /
  `categories`; series from `series[]`).
- Series types: `bar` → `<c:barChart>`, `line` → `<c:lineChart>`
  (honours `smooth`), `line` + `areaStyle` → `<c:areaChart>`, `pie` →
  `<c:pieChart>` (colored `<c:dPt>` slices), `scatter` →
  `<c:scatterChart>`. Literal caches (`<c:strLit>` / `<c:numLit>`) — no
  embedded workbook required. Series colored from the theme accent + a
  small palette.
- Graceful fallback: an untranslatable / unsupported option embeds a
  pre-rendered `image` / `src` data-URI as a picture, else a tidy titled
  placeholder box. Never crashes.

### Theme + layouts
- `theme1.xml` maps `theme.colors` into the clrScheme more sensibly
  (`muted` → dk2, `surface` → lt2, accent ramp from `accent`) and
  `theme.fonts` into major/minor fonts.
- Ships all 8 real `slideLayoutN.xml` parts (blank / title / title-content
  / two-column / section-divider / image-text / text-image / quote) with
  the right `type=`, registered in the master's `<p:sldLayoutIdLst>` and
  content types. Each slide references the layout matching its
  `slide.layout` (falls back to blank). Elements stay absolutely placed —
  layouts drive PowerPoint's theme/reset UI, not re-flow.

### Testing
- 39 Pest cases / 151 assertions, all green (was 23). New v0.4 cases cover
  transition emission (push/fade/zoom), image cover `<a:srcRect>`, contain
  letterbox, explicit crop, a well-formed bar chart with the right `<c:ser>`
  count, pie chart, untranslatable-chart fallback, the 8 layout parts, and
  per-slide layout references.

## v0.3.0 — 2026-05-26

Markdown headings, syntax-highlighted code, and reader fidelity for the
v0.2 features.

### Writer
- Paragraph-leading `# / ## / ###` in markdown text elements render as
  larger bold runs in PPTX (level→multiplier: 1.8× / 1.45× / 1.2×).
- Code elements now ship one `<a:r>` per highlighted token (keyword /
  string / comment / number / builtin / punctuation). Pure-PHP
  `SyntaxHighlighter` helper with built-in support for JS/TS, PHP, JSON,
  bash, CSS, Python, HTML — zero third-party deps.

### Reader fidelity for v0.2 features
- Real `<a:tbl>` graphicFrames round-trip back to `type: "table"` with
  columns + rows preserved.
- Solid / gradient / image backgrounds round-trip via
  `parseBackground()`. Gradients reconstruct CSS `linear-gradient(...)`
  from `<a:gradFill>` stops + PPTX clockwise-from-east angles.
- Embedded image bytes resolve through slide rels and emit as data URIs
  in the read-back `src` field (previously placeholder).
- Inline markdown spans (bold / italic / code) reconstruct from
  drawingML rPr decoration. Uniform-bold or uniform-italic paragraphs
  collapse to the paragraph default so the element's `style.weight`
  doesn't double-wrap.

### Testing
- 23 Pest cases / 96 assertions, all green.
- New v0.3 cases cover markdown headings, code highlighting, table
  round-trip, gradient round-trip, embedded image round-trip, and
  markdown span round-trip.

## v0.2.0

Inline markdown spans, real tables, gradient backgrounds. See git
history.

## v0.1.0

Initial release. Validate / write / toBytes / read / describe surface
plus the minimal writer (text / image / shape / notes / multi-slide).
