# Changelog

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
