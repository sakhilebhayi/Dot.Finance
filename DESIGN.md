---
version: alpha
name: The Tie-Out
description: Dot.Finance's design system. Graphite on cool paper, a ledger green for what has been checked and a pencil red for what has not tied out. Every figure can be traced to the rows behind it.
colors:
  primary: "#14181F"
  paper: "#F6F7F9"
  sheet: "#FFFFFF"
  sunk: "#EDEFF3"
  ink: "#14181F"
  ink-2: "#3A424E"
  ink-3: "#5A6371"
  rule: "#D6DAE1"
  rule-strong: "#AAB1BD"
  edge: "#78818F"
  tick: "#15603D"
  tick-bg: "#E0F1E7"
  tick-line: "#80BA98"
  pencil: "#B0241C"
  pencil-bg: "#FBE8E5"
  pencil-line: "#DF9088"
  night-paper: "#0E1116"
  night-sheet: "#151A21"
  night-sunk: "#1F2630"
  night-ink: "#E8EBF0"
  night-ink-2: "#B2BAC6"
  night-ink-3: "#909AA8"
  night-rule: "#282F3A"
  night-rule-strong: "#3E4755"
  night-edge: "#707A8A"
  night-tick: "#74D2A0"
  night-tick-bg: "#133121"
  night-pencil: "#FF8E83"
  night-pencil-bg: "#3E1B19"
typography:
  display-title:
    fontFamily: Archivo
    fontSize: 1.5rem
    fontWeight: 680
    lineHeight: 1.15
    letterSpacing: -0.005em
    fontVariation: "'wdth' 112"
  display-section:
    fontFamily: Archivo
    fontSize: 1.0625rem
    fontWeight: 650
    lineHeight: 1.2
    fontVariation: "'wdth' 112"
  state-line:
    fontFamily: Archivo
    fontSize: 0.9375rem
    fontWeight: 560
    lineHeight: 1.35
    fontVariation: "'wdth' 108"
  body:
    fontFamily: Public Sans
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.5
  cell:
    fontFamily: Public Sans
    fontSize: 0.875rem
    fontWeight: 400
    lineHeight: 1.4
  label:
    fontFamily: Public Sans
    fontSize: 0.8125rem
    fontWeight: 600
    lineHeight: 1.3
  meta:
    fontFamily: Public Sans
    fontSize: 0.75rem
    fontWeight: 500
    lineHeight: 1.3
  figure:
    fontFamily: Chivo Mono
    fontSize: 0.875rem
    fontWeight: 400
    lineHeight: 1.4
    fontFeature: "'tnum' 1, 'zero' 1"
  figure-lead:
    fontFamily: Chivo Mono
    fontSize: 1.875rem
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: -0.03em
    fontFeature: "'tnum' 1, 'zero' 1"
rounded:
  chip: 3px
  control: 5px
  panel: 6px
  float: 10px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  xxl: 48px
components:
  page:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
  panel:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: 24px
  panel-night:
    backgroundColor: "{colors.night-sheet}"
    textColor: "{colors.night-ink}"
    rounded: "{rounded.panel}"
    padding: 24px
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    height: 38px
    padding: 16px
  button-primary-night:
    backgroundColor: "{colors.night-ink}"
    textColor: "{colors.night-paper}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    height: 38px
    padding: 16px
  button-secondary:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    height: 38px
    padding: 16px
  field:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    height: 38px
    padding: 12px
  pill-ticked:
    backgroundColor: "{colors.tick-bg}"
    textColor: "{colors.tick}"
    typography: "{typography.meta}"
    rounded: "{rounded.chip}"
    padding: 8px
  pill-variance:
    backgroundColor: "{colors.pencil-bg}"
    textColor: "{colors.pencil}"
    typography: "{typography.meta}"
    rounded: "{rounded.chip}"
    padding: 8px
  pill-ticked-night:
    backgroundColor: "{colors.night-tick-bg}"
    textColor: "{colors.night-tick}"
    typography: "{typography.meta}"
    rounded: "{rounded.chip}"
    padding: 8px
  pill-variance-night:
    backgroundColor: "{colors.night-pencil-bg}"
    textColor: "{colors.night-pencil}"
    typography: "{typography.meta}"
    rounded: "{rounded.chip}"
    padding: 8px
  money:
    textColor: "{colors.ink}"
    typography: "{typography.figure}"
  money-lead:
    textColor: "{colors.ink}"
    typography: "{typography.figure-lead}"
  state-line:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.state-line}"
  row-selected:
    backgroundColor: "{colors.sunk}"
    textColor: "{colors.ink}"
    typography: "{typography.cell}"
  row-selected-night:
    backgroundColor: "{colors.night-sunk}"
    textColor: "{colors.night-ink}"
    typography: "{typography.cell}"
  text-secondary:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink-2}"
    typography: "{typography.cell}"
  text-tertiary:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink-3}"
    typography: "{typography.meta}"
  text-secondary-night:
    backgroundColor: "{colors.night-sheet}"
    textColor: "{colors.night-ink-2}"
    typography: "{typography.cell}"
  text-tertiary-night:
    backgroundColor: "{colors.night-sheet}"
    textColor: "{colors.night-ink-3}"
    typography: "{typography.meta}"
  hairline:
    backgroundColor: "{colors.rule}"
    height: 1px
  hairline-night:
    backgroundColor: "{colors.night-rule}"
    height: 1px
  divider-strong:
    backgroundColor: "{colors.rule-strong}"
    height: 1px
  divider-strong-night:
    backgroundColor: "{colors.night-rule-strong}"
    height: 1px
  control-edge:
    backgroundColor: "{colors.edge}"
    height: 1px
  control-edge-night:
    backgroundColor: "{colors.night-edge}"
    height: 1px
  ticked-edge:
    backgroundColor: "{colors.tick-line}"
    height: 1px
  variance-edge:
    backgroundColor: "{colors.pencil-line}"
    height: 1px
---

## Overview

**Concept card: the tie-out.** An accountant verifies a number by ticking it: tracing it to the rows it came from and marking it checked. Dot.Finance is built around that gesture. Every figure can be traced to the entries behind it (the provenance mark), ledgers print their totals the way ledgers do (a single rule for a subtotal, a double rule for a grand total), and colour carries exactly two meanings: ledger green for "checked, ties out" and pencil red for "does not tie, or overdue". Everything else is graphite on cool paper.

It must not be mistaken for QuickBooks, Xero or a generic SaaS dashboard, so it does not borrow their devices: no tinted KPI tiles with icons, no coloured sidebar, no gradient charts, no rounded cards floating in gutters, no accent colour on chrome. The product is calm, precise and trustworthy. It speaks plain South African English and explains jargon in place.

The signature, in order of importance:

1. **The provenance mark** on a figure: a small tick that is a real link to the rows the figure is made of. It turns ledger green only when the figure has been verified (the tie-out page).
2. **The state line**, one sentence at the top of every page, saying in words what needs the owner right now ("3 things need you: ..." or "Nothing needs you right now.").
3. **Ledger rules**: single rule under a subtotal, double rule under a grand total, dot leaders between a label and its figure, figures aligned on the decimal.

Mode: Operate (people do work here, daily, on phones and desktops). Night is a first-class theme that follows the system until the person chooses.

## Colors

Cool graphite and cool paper. Two signal colours only, and each means one thing.

- **Paper (#F6F7F9)** the page. **Sheet (#FFFFFF)** a ruled sheet laid on the page. **Sunk (#EDEFF3)** a pressed or selected step.
- **Ink (#14181F)** primary text and the primary button. **Ink 2 (#3A424E)** secondary text. **Ink 3 (#5A6371)** tertiary text, still 5.7:1 on paper.
- **Rule (#D6DAE1)** the hairline between rows. **Rule strong (#AAB1BD)** a stronger divider. **Edge (#78818F)** control borders (3.9:1 on the sheet).
- **Tick (#15603D)** ledger green: reconciled, ticked, verified. Nothing else is ever green. 7.6:1 on the sheet.
- **Pencil (#B0241C)** pencil red: a variance, an overdue item, an over-limit budget, an error. Nothing else is ever red. A destructive action is not red; it is marked by words, an icon and a confirmation.
- Night mirrors the same roles on true neutral darks (#0E1116 paper, #151A21 sheet), with the signal colours lifted (#74D2A0, #FF8E83) so money text is above 7:1 in both themes.

Contrast, measured: ink 17.8:1, ink 2 10.2:1, ink 3 6.1:1, tick 7.6:1, pencil 6.7:1 on the sheet (light). Night: ink 14.6:1, ink 2 8.9:1, ink 3 6.1:1, tick 9.5:1, pencil 7.9:1 on the sheet. Money text clears AA in both themes. Colour is never the only cue: negatives are in brackets and announced as "negative", pills carry a glyph.

## Typography

Three open-licence families, self-hosted from the `@fontsource-variable` packages (SIL OFL 1.1), latin subset.

- **Archivo** (display): headings and the state line. Set at 112% width (the `wdth` axis) so titles look stamped, like a ledger masthead. Page title 24px, section 17px.
- **Public Sans** (text): everything read. 15px body, 14px in tables, 13px labels, 12px meta. A quiet, civic grotesque derived from Franklin Gothic.
- **Chivo Mono** (figures): every amount, date and reference, with tabular numerals and slashed zeros so columns align on the decimal and a 0 is never an O. The lead figure on a screen is 30px; no figure is larger.

Labels, table heads and nav are sentence case, untracked. There are no eyebrows and no tracked capitals.

## Layout

A ruled T-frame at 1024px and up: a 248px sidebar and a sticky top bar on the paper ground, the work on white sheets. The canvas is capped at 1160px and centred; gutters grow, measures do not. Below 1024px the sidebar becomes a bottom sheet opened from "More" in a five-item bottom bar; the top bar scrolls away and the state line sits under the brand.

- Spacing is a 4px base with one gap (16px) used both ways.
- Sidebar groups: Money, Sales, Purchases, Bank, Tax, Reports, Planning, Controls. A destination whose route is not registered yet is a disabled item marked "Soon", never a dead link (`fx_route()`).
- Tables: caption, `th scope`, sticky header. Below 720px a table becomes a list of cards, each cell labelled from `data-label`; nothing is dropped and the page never scrolls sideways.
- Breakpoints verified: 375, 768, 1280 (and 1440).
- The command centre answers one question first (how much cash, and does anything need me) with the cash position leading and the needs-you list beside it.
- The command centre opens with the landing (`fx-land`), set on the paper rather than in a sheet: the original logo file (`public/images/logo.png`, `logo-light.png` at night, never redrawn), a welcome from the person's first name and the active ledger, the one primary action on the page (`data-primary-action`: add a transaction, or the first account when there is none, or read the entries for a read-only member), the way back to InfoDot and its Platforms page, and what the ledger holds as a ruled statement of counts read from its rows. An empty ledger says so in words and prints no figure. A double rule closes it and the working panels follow. Nothing in it moves on load.

## Elevation & Depth

Flat. Surfaces are separated by a one-step tone change or a 1px rule, never both and never a resting shadow. Shadow is only for what floats: a dialog, the command bar, a toast, the mobile sheet (`0 12px 32px -8px` at 22% black in light, 60% in night). No glass, no blur, no gradients, no glow.

## Shapes

Tight and ledger-like: 3px chips, 5px controls, 6px panels, 10px for floating surfaces. Radii step down inside a container, never up. Rules are 1px; totals use a 4px double rule. Icons are Lucide outlines at 1.6px stroke in `currentColor`, inlined (no icon font).

## Components

All in `resources/views/components/fx`, used as `<x-fx.name>`.

- **money**: one text node ("ZAR 1,234.56"), brackets for negatives plus a screen-reader "negative", a transparent bracket-width on positives so decimals align, `lead`, `variance`, `ticked` and an optional `trace` link.
- **trace**: the provenance mark, a real link to the contributing rows.
- **table**: caption, scope, sticky header, card mode on phones, subtotal and total rules.
- **statement / statement-row**: labelled figures joined by dot leaders; `kind="subtotal"` rules once, `kind="total"` rules twice.
- **pill**: neutral, `ticked` (ledger green) or `variance` (pencil red); always with a glyph.
- **button**: primary (ink fill), secondary, ghost and danger (a heavier ink outline, never red); 38px, 44px touch targets on coarse pointers.
- **input, select, textarea, check, field, errors, flash**: label above, hint, inline error with an icon and `role="alert"`, `aria-invalid`, `aria-describedby`.
- **period**: the page-header period selector (a GET form, state in the URL).
- **tabs**: links with `aria-current`.
- **meter**: a progress or ageing bar drawn as SVG (widths are attributes, not inline styles); the fill turns pencil red when a limit is passed.
- **empty**: what belongs here, and the one action that fills it. Never a placeholder figure.
- **dialog, confirm, command, toasts, toast**: native `<dialog>` (focus trap, Escape), a single accessible confirm replacing `window.confirm()`, the Ctrl/Cmd+K command bar, a polite live region.
- **gloss**: a plain-language tooltip for a term. It reads the `glossary` slot, or `App\Support\Glossary` when that exists; with neither the term is shown plainly.
- **state-line / state-item**: the sentence at the top of every page. Modules add items with `StateLine::extend()`.
- **page-head, panel, swatch, avatar, icon, logo.** The logo component shows the original files (`public/images/logo.png` on paper, `logo-light.png` at night) and nothing else: no drawn or typed stand-in, never cropped, recoloured or redrawn. Callers set only its width and place.
- **steps**: a real ordered list for a guided flow (the four steps of switching accounting on); a step is ticked green when done, ink when current, outlined when still to come. Only used where order carries meaning.
- **Statement tables** (`fx-table--statement`, `--stack`, `--tree`): group heads in the display face, a single rule under a subtotal, a double rule under a total, the provenance tick beside every drill-down figure. Below 540px a row becomes a two-column line (name left, figure right) with the unused side of a debit and credit pair dropped; nothing is removed.
- **Voucher** (`fx-voucher`): a journal entry set as a ledger voucher, the reference as the one lead figure, a rule under the head, ruled lines and a double-ruled total.
- **Lock state** (`fx-lock`): the closed-through banner on the periods page; locked is a tone step (sunk) with a lock icon, never a colour.
- **Lines** (`fx-lines`, `fx-line`): ruled rows of a thing and its action (policies, files, people), and the role picker (`fx-roles`) used by the team pages.

## Do's and Don'ts

- Do trace every figure to its rows, and say what it means in the owner's words.
- Do use green only for "ticked / ties out" and red only for variance, overdue or over limit.
- Do put a sign or brackets on every negative and a glyph on every pill.
- Do show "No data yet" with the action that creates it.
- Don't use inline styles, a CDN, a third-party font or an un-nonced script: the Content-Security-Policy is enforced.
- Don't draw colour from the user's data except as an SVG attribute (category swatches).
- Don't add tinted KPI tiles, gradients, glows, a dark sidebar on a light page, an accent on chrome, or an icon in a tinted square.
- Don't write jargon without a gloss.

## Voice

Plain South African English: "organise", "colour", "cheque", "R" amounts written as the ledger's own currency code (ZAR 1,234.56), dates as `2026-11-09` where rows are compared and "9 November 2026" in sentences. Say what happened, not the account type: "Money you are owed", not "Accounts receivable", with the formal term one tap away through the glossary. Errors name the problem and the recovery. Never "Oops", never a greeting with an emoji, never a claim the software cannot back (the weekly summary is "rule-based, not AI"). Nothing is "submitted to SARS" by this software; it prepares and a person submits.

## Motion

The verb is **tick**: a stroke that draws itself once (180ms, ease-out) when something has been verified or resolved: the tie-out verdict, the "Nothing needs you" line, a saved message. Press feedback is a 1px nudge. Sheets and dialogs open in 160 to 220ms. Nothing else moves, nothing performs on load, and `prefers-reduced-motion: reduce` removes all of it (the global rule in base.css). Transform and opacity only.
