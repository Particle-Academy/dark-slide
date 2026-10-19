# Agent guide: professional presentations with dark-slide

<!--
  The agent guide for dark-slide: what makes a deck professional, and the
  schema idioms that produce it. Canonical copy: the PHP package's
  agent-guide/professional-decks.md; the Node and Python packages carry the same
  bytes (a test in each pins the hash). Distilled from rubric-judged builds in
  the Fancy document lab -- every rule here moved a measured score.
-->

## How to use this guide

It is written for an agent and works with any agent loop. Give it to the model
as part of its instructions (a system prompt, a skill, a rules file), together
with the package's JSON schema (`Agent::jsonSchema()` / `Agent.jsonSchema()` / `json_schema()`). Then the procedure is:

1. Read the schema once and plan a deck in full: structure, every figure,
   every derived number, computed and checked before anything is written.
2. Build the whole JSON in one go, following the recipes below.
3. Check it: `Agent::validate` (PHP), `Agent.validate` (Node) or `validate` (Python) returns errors with a hint each.
   For a deck, also run `Agent::lint` (PHP), `Agent.lint` (Node) or `lint` (Python): it names text that will
   overflow its box, overlaps, off-slide boxes, KPI values too wide for their
   cells, text too small to read, and markdown that will print as typed.
   Fix every finding.
4. Write it (`Agent::write` / `Agent.toBytes` / `to_bytes`). If you can render the file and look at it, do,
   but repair only what you can see is wrong: one-shot "improvements" made
   blind regressed more documents than they fixed.

You are building a deck a demanding executive would present to a board or a client
UNCHANGED. Meeting the brief is the floor, not the goal. The brief gives you
content; you supply the narrative, the evidence and the design.

## The bar (every item is required)

1. **Action titles.** Every content slide's title is a full sentence stating the
   takeaway ("EMEA delivered half of Q3 revenue"), never a topic label
   ("Revenue by region").
2. **Evidence on the slide.** Every claim is backed by visible figures. When
   numbers are compared, show a chart (native bar/line/pie) or a KPI band, plus
   the derived numbers the audience needs: totals, shares, deltas.
3. **The space is used.** No slide is one sentence floating on an empty field.
   Pair a statement with its proof: title + chart + 2-3 annotated takeaways.
4. **One visual system.** Same margins, grid, type scale and colours on every slide;
   vary the LAYOUT (statement, chart + commentary, KPI band, comparison).
5. **Furniture.** Slide numbers bottom-right on content slides; a source line
   bottom-left where data is shown; a title slide with date and audience.
6. **Legible when projected.** fontSize is in DESIGN PIXELS on a 1920-wide canvas:
   **1px = 0.375pt**, so 32px is 12pt and 20px is a useless 7.5pt. Nothing on a slide
   below 32px (12pt). Strong contrast (no mid-grey text on dark backgrounds below #98A2B3).

## Never ship

- A placeholder ("[Insert date]", "TBD", "Lorem"). If the brief gives no date, name the
  period ("Q3 2026") instead.
- A claim the slides do not evidence. If the brief gives revenue only, say "largest",
  not "fastest-growing". Never repeat a brief's claim the data cannot support; state
  what the data shows.

## Before you build

Read the schema once. Then plan, in your head, a storyline: slide -> takeaway ->
evidence -> layout. Compute every derived figure (totals, shares, growth) first and
check the arithmetic; a deck that says "led on growth" must show the growth numbers.
If the brief only gives revenue, do not claim growth.

## The grid (1920 x 1080 design canvas; positions are FRACTIONS 0..1)

- Margins: x from 0.06 to 0.94; content top 0.25; footer band y 0.91.
- Title: x 0.06, y 0.06, w 0.88, h 0.13, fontSize 88, weight 700, heading font.
- Accent rule under the title: shape rect, x 0.06, y 0.2, w 0.06, h 0.006, fill accent,
  **strokeWidth 0** (otherwise it gets an outline).
- Two-column body: left x 0.06 w 0.52 (chart), right x 0.62 w 0.32 (takeaways).
- Footer: source text x 0.06 y 0.92 w 0.7 h 0.05 fontSize 32 muted; slide number
  x 0.86 y 0.92 w 0.08 h 0.05 fontSize 32 muted align right.

Type scale (px -> pt): display 150-180 -> 56-68pt (title slide only); slide title
80-96 -> 30-36pt; subtitle 48-56 -> 18-21pt; body 44-48 -> 16-18pt; label 36-40 ->
13.5-15pt; footer/source 32 -> 12pt. Never below 32.

## Schema idioms that work

- Theme: set `theme.colors` {background, text, muted, accent, surface} and
  `theme.fonts` {heading, body} from the brand. Unstyled text takes `colors.text`.
- Text: `{type:"text", x,y,w,h, content, style:{fontSize, fontFamily, weight, color, align}}`.
  Give every text box an explicit fontSize and enough height: lines x fontSize x 1.3 / 1080.
- Panels: `style.fill` (surface colour), `style.radius` 12, `style.padding` 32 on a text box,
  or a shape `rounded-rect` behind elements. `style.accentBar` draws a left accent bar.
- KPI band: `{type:"kpiBand", items:[{value:"$2.10M", caption:"EMEA - 48% of total"}],
  style:{valueFontSize:96, captionFontSize:36, fill: surface}}`.
  SIZE THE VALUE TO ITS CELL: each item gets `w * 1920 / items` pixels, and a value
  needs about `characters * valueFontSize * 0.62` plus 64 of padding. Four items
  across w 0.88 is 422px each: "$4.33M" (6 chars) fits at 96, not at 120. With more
  than three items, or values longer than 6 characters, go to 80 or fewer items.
- Native chart: `{type:"chart", x,y,w,h, option:{xAxis:{data:["South","North","EMEA"]},
  series:[{type:"bar", name:"Q3 revenue ($M)", label:{show:true}, data:[0.98,1.25,2.10]}]}}`.
  Only bar / line / pie / scatter render natively. `label:{show:true}` prints each
  value on its bar -- always use it. Sort bars so the order tells the story. A legend
  appears only if you add `option.legend`: add it only for two or more series. Do NOT
  set `option.title`: the slide's action title already says it.
- CHART COLOURS COME FROM THE BRAND. Set `option.color` to the theme's colours
  (`["#F97316", "#94A3B8"]`); without it the second series or slice is a stock hot
  pink. Then HIGHLIGHT THE POINT THE TITLE IS ABOUT: a muted series
  (`itemStyle:{color:"#94A3B8"}`) with the subject in the strong colour
  (`data:[0.98, 1.25, {value:2.10, itemStyle:{color:"#F97316"}}]`). A missed target
  or the outlier goes red (`#DC2626`). All bars one colour says nothing.
- Values on labels print as numbers, without units: plot in the unit the title or
  series name states ("Q3 revenue ($M)": 2.10, not 2100000). Never a pie for one
  figure ("41% cited X") -- state it as a big number instead.
- MARKDOWN NEEDS `format:"markdown"`. Any `**bold**` lead-in or `# heading` in
  `content` prints its asterisks as typed unless the element has
  `format:"markdown"`. The layout check names it (`literal-markdown`).
- A SCORECARD SHOWS HIT AND MISS AT A GLANCE. A kpiBand colours every value alike,
  so for KPIs against targets use one card per KPI (a text box with `style.fill`
  surface and `style.accentBar` in green `#16A34A` for met, red `#DC2626` for missed),
  value large, "Target 95%" beneath, and the word "Met" / "Missed" -- never colour alone.
- Title length: a title must fit on ONE line, two at most. Characters per line is
  about `w * 1920 / (fontSize * 0.55)`; at fontSize 88 across w 0.88 that is ~35
  characters. Shorten the sentence or lower the size; never let one word wrap alone.
- LEAVE ROOM FOR A WIDER FONT. The viewer may not have the brand font and will
  substitute a wider one, so a line that fits exactly WILL wrap somewhere. Every text
  box is tall enough for one more line than you expect: height = (lines + 1) x
  fontSize x 1.25 / 1080. A one-line 88px title needs h 0.2; a two-line 150px display
  title needs h 0.52.
- NO OVERLAPPING BOXES. Place each element below the previous box's BOTTOM
  (y + h) plus a gap of at least 0.02, never at a guessed y. Check every slide: for
  any two text boxes, one ends before the other begins.
- Letter-spacing: leave `letterSpacing` unset. Eyebrow labels get their character
  from `caps:true`, weight 700 and the accent colour; spaced caps render unevenly.
- Speaker notes go in `slide.notes`, never on the slide.

## Recipes

- **Title slide:** accent rule, eyebrow (label, accent colour, caps), display title,
  subtitle with the audience and date, presenter line. Text block vertically centred.
- **Headline-number slide:** action title; kpiBand across the width; one line below
  interpreting the numbers (total, share, change).
- **Chart + commentary:** action title; native chart on the left 55%; 2-3 short
  takeaways on the right, each a bold lead-in and one supporting clause.
- **Takeaway / close:** the one message in large type, the proof figure beside it,
  and the decision or next step being asked for.

## Failures we have seen (do not repeat)

- Topic titles ("Q3 revenue by region") instead of the takeaway. -> Write the sentence.
- Three numbers in cards with no total, share or comparison. -> Add the derived figures.
- A closing claim ("EMEA led on growth") with no growth data on any slide. -> Show it or drop it.
- Slides 1 and 3 both a left-aligned title floating over an empty half. -> Vary layouts, fill the grid.
- No slide numbers or source. -> Always add the footer.
- 9-12pt labels and 7.5pt footers (sizes written as if they were points). -> Use the
  px -> pt scale above; nothing below 32px.
- A title that wrapped and left one word on its own line. -> Count the characters.
- A chart with its own title duplicating the slide title. -> No `option.title`.
- A title slide that is only text on an empty field. -> Add the headline figure or a
  KPI strip, and the date/audience line, so the opening slide already informs.
- Accent rules with a coloured outline. -> strokeWidth 0 on decorative shapes.
- Never set `theme.slideWidth`: every size in this skill assumes the 1920 canvas.
- "**The problem.**" on the slide, asterisks showing. -> `format:"markdown"`.
- A pie in the stock palette clashing with the brand. -> `option.color`.
- KPIs that missed their target styled like the ones that met it. -> Status cards.
- Tall boxes holding one short line, the lower half empty. -> Size the box to its
  text (lines + 1, as above) and the card to its content; spread spare space as
  gaps between blocks, not inside them.
- KPI strips that repeat the same figures on three slides. -> Each figure once,
  where it is the evidence; the title slide carries at most the one headline number.
- Derived figures presented as given ("1 June", "$6.8M cash"). -> Only what the
  brief states or what you compute and label as computed.
- Captions, card text, chart labels and the source line at 13.5-15pt when the brief
  says the deck is read on a PHONE. -> On a phone brief, nothing below 48px (18pt):
  fewer words per card, not smaller ones.
- A value axis starting at 88 to make a gap look big. -> Bars start at zero; put the
  target in the title or a caption ("Target 95%") instead of truncating the axis.
- A short value ("< 20%") wrapping onto two lines in a narrow card and printing over
  its caption, and a word breaking mid-word ("enablemen-t"). -> Size each box for its
  LONGEST word at its fontSize (characters x fontSize x 0.62 + 40); the layout check
  counts words, so run it and fix every finding before anything else.
- Timeline milestones evenly spaced when the dates are not. -> Place each milestone
  at x proportional to its date between the first and the last.

## Before you write the deck, check

Every content slide: action title, evidence, footer. Every number: computed and
consistent across slides. Every text box: explicit size, fits its height, inside
the margins. Theme carries the brand colours and fonts. Then write once.
