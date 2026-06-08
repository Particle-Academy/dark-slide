# Changelog

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
