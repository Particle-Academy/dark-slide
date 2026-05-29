# Changelog

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
