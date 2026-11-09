# R-44 Restyle: accounting, controls, parties and the Livewire pages (findings)

Branch `feat/r44-restyle`. Decisions D-1100..D-1113 are in `R44_DECISIONS.md`. Wave 1 (F1 accounting, F2 parties and documents, F3 controls) built plain semantic HTML on class names no stylesheet defined. Every one of those views is now on the `x-fx` design system, keeping all behaviour, routes, field names and asserted wording.

## 1. Design skills

| Skill | What I did |
|---|---|
| `impeccable` | Loaded. Read `reference/craft-floor.md` before the UI edits. I did **not** run the `impeccable context` launcher (the project already has `DESIGN.md`, the owner ordered no interview). `impeccable detect --json` ran statically on `resources/css/ledger.css` and on the `accounting`, `controls` and `parties` view folders: `[]` (no findings). Its URL scan reached only the sign-in page (the signed-in pages need a session), so the live-DOM pass on signed-in pages was **not run**. |
| `design-taste-frontend` | Loaded. I read the chapter headings of `references/app-screens.md` and applied its table, form, state and phone rules; I did not read the file in full and did not run `draw.mjs` or `inspect.mjs --app` (the system and its tokens were fixed by `DESIGN.md`). |
| `hallmark` | Loaded. Its Floor applied by hand (no eyebrow, no tracked capitals, tokens only, `:focus-visible`, reduced motion, no gradient, no card in a card). `theme.mjs` and `sloplint` were not run: this is an app-screen restyle inside an existing locked system. |
| `design-md` | Loaded. Used to extend `DESIGN.md` Components and lint it (`npx @google/design.md lint DESIGN.md`: 0 errors, 0 warnings, 1 info). |
| `ui-ux-pro-max`, `emil-design-eng` | Not invoked: unavailable per the brief. Their checks were not applied. |

## 2. What was built

**Accounting** (`resources/views/accounting`)
- Tabs on every page; one flash and error partial.
- **Trial balance** as a statement: group heads (Assets, Liabilities, Equity, Income, Expenses), single-ruled class subtotals, a double-ruled grand total ticked green only when debits equal credits, a verdict line, the accounting equation as a dot-leader statement, and a provenance tick on every balance that opens that account's general ledger. Empty accounts are left out unless asked for.
- **General ledger**: account and date filter that folds on phones, ruled lines, "balance brought forward" as a subtotal, a running balance with a tick that opens the journal entry; the entry number is the link.
- **Journal detail as a voucher**: reference as the one lead figure, status pill, ruled meta, ruled lines, double-ruled total, "debits equal credits" note, the reversal as a confirmed form.
- **Journals list, new manual entry** (with live debit, credit and difference), **chart of accounts** as a tree grouped by class with balances and ticks, add or edit account.
- **Enable-accounting wizard**: a four-step ordered list; plain-language "In plain words" panel with glossary terms (`base_currency`, `financial_year`, `chart_of_accounts`, `double_entry`, `journal_entry`, `exchange_rate`); the mapping step, the dry run and the apply each in their own panel; the outcome of a run as a verdict pill, a check list (green tick, pencil red for failures) and what could not be posted.
- **Periods**: a "Books closed through" banner (locked is a tone step and a lock icon), a close-through form, a list with Closed or Open pills, and confirm dialogs on close, close-through and reopen.

**Controls** (`resources/views/controls`)
- Overview ("where things stand" with the three live facts), members and roles (people table, what each role is for in words, the permission matrix), approval policies (turned on, one-click presets with their amount inline, a custom policy disclosure), approvals inbox (waiting, my requests, history), spend limits, access review (a verdict on the last confirmation, people with roles merged into one cell, role history, confirm, earlier reviews).

**Parties** (`resources/views/parties`): list with search and filters, the form as grouped panels, and the 360 page (terms strip, details, addresses, notes, documents with signed links, upload, archive or restore).

**Livewire pages**: team name, add member (role picker), pending invitations, members, role dialog, leave, remove, delete team; profile, password, two-factor, browser sessions, delete account; API tokens; confirm-password dialog. All `wire:` bindings and text are unchanged.

**Supporting**: `resources/css/ledger.css` (one import in `app.css`), `components/fx/steps.blade.php`, `resources/js/fx/journal.js`, `DESIGN.md` Components, `finance:demo-controls` (local-only).

## 3. Tests

| Run | Result |
|---|---|
| SQLite in memory (clean environment), full suite | **1,180 tests, 1,170 passed, 10 skipped (7 existing, 3 PostgreSQL-only), 0 failed**, 267,408 assertions |
| PostgreSQL 16 (throwaway database `r44_restyle_pg`, dropped afterwards), full suite | **1,180 tests, 0 failed, 8 skipped**, 267,433 assertions |
| `vendor/bin/pint --test` | passed |
| `php artisan route:cache && route:clear` | both succeed |

New: `RestyleViewsTest` (8): every restyled page (23 URLs, including the team and profile pages) has exactly one h1, none of the old classes or stock utility classes, no style attribute, no un-nonced script, a caption and column scopes on every table; the trial balance hides and reveals empty accounts and ticks its totals only when they balance; the periods page states the lock and confirms; the setup steps follow the person's progress (a real prepare and dry run over HTTP); the members matrix has accessible Yes and No; party documents carry signed links; a viewer sees none of the actions. `DemoControlsTest` (3): refuses outside local, switches accounting on through the real backfill, idempotent. No existing test was changed or weakened (the first suite run after the rebuild passed the 140 accounting, controls and party tests untouched).

## 4. Verified in the browser

Built-in browser, own SQLite file, port 9217, the real login and ledger switcher, demo data from `finance:demo-seed` then `finance:demo-controls`.
- **Desktop 1280, night and light**: trial balance, general ledger, journals list and voucher, chart, periods (and the confirm dialog opening, reading the form's message and closing with Escape), setup, review (steps advancing after a real prepare and dry run), controls overview, members, policies, approvals, limits, access review, parties list, party 360, party form, team settings (add member with the role picker selecting, the role dialog, the remove dialog), profile.
- **Phone 375x812**: trial balance, voucher, chart, general ledger, periods, members, access review, policies, approvals, parties list, party 360, party form. `scrollWidth` equals the viewport on each after the fixes in D-1112.
- **Tablet 768x1024**: trial balance, voucher, general ledger, members, access review. `scrollWidth` 768 after the glossary-tip fix.
- **Contrast**: every visible text node on 12 pages (trial balance, general ledger, voucher, chart, periods, members, policies, approvals, access review, party, team) measured in both themes by script: all pass (minimum 5.67:1 light, 5.70:1 night). The sweep found the invisible pager number (D-1111).
- **Keyboard**: 22 Tab presses on the members page reached the controls in order with a 2px outline at 2px offset (`:focus-visible` true). Dialogs are native or the existing Jetstream modal (focus trap, Escape).

## 5. Not verified, partial or left

- **Not run:** a physical phone, Safari and Firefox (Chromium only), real screen readers (NVDA, VoiceOver); the signed-in live-DOM Impeccable pass and `inspect.mjs --app`; print styles.
- **Reduced motion** was not toggled in a browser; the new CSS adds no animation (only a 120ms background transition on role options) and the global rule in `base.css` covers it.
- **Tablet (768)**: tables that use the design system's card mode below a 760px container (general ledger, members, access review) are lists of cards at 768, not tables. That is the system's threshold (D-403 family), unchanged.
- **Reopen** and **close** a period were seen as dialogs but not submitted in the browser (the HTTP tests post them).
- **Approvals**: the demo inbox uses two stand-in requests with a placeholder subject because the bill module does not exist on this branch; approving them from the browser was not tried.
- **The API tokens page** is still unreachable (feature off in `config/jetstream.php`); its markup was rewritten and linted but not seen.
- The two-factor QR, pending invitations and the sessions list were rewritten but not seen with live data (no QR enabled, no invitation, one session).
- Three typefaces stay (D-402); `'unsafe-eval'` stays (D-404).

## 6. Owner steps

1. Run `php artisan finance:demo-seed` then `php artisan finance:demo-controls` locally to see every state; the second command refuses on a real host.
2. After the sales and purchases modules land, decide whether the stand-in approval requests in the demo should become real bills.
3. Decide whether tables should keep card mode up to 760px on tablets (general ledger and access review are wide enough to stay tables from about 640px).
