# R-44 UI: design system, app shell and redesign of the existing pages (agent U)

Branch `feat/r44-ui`. Decisions D-400 to D-415 are in `R44_DECISIONS.md`. The design system itself is `DESIGN.md` (design-md format, lints with 0 errors and 0 warnings: `npx @google/design.md lint DESIGN.md`, which worked offline-enough here because the package resolved from npm).

## 1. Design skills

Invoked through the Skill tool, in this order, and what each gave:

| Skill | Result |
|---|---|
| `design-taste-frontend` | Loaded. Read `references/app-screens.md` in full (the app-screen run, shell archetypes, KPI/table/form/state/phone rules, the dashboard tells). I did not run `draw.mjs` (the concept and tokens were fixed by the owner brief); I ran `inspect.mjs --app` on the rendered pages (section 6). |
| `impeccable` | Loaded. `impeccable context` ran (no PRODUCT.md existed; the owner ordered no questions, so no interview, mode = Operate, inferred from the brief). Read `reference/craft-floor.md` before the UI edits. The `detect` CLI ran (section 6). |
| `hallmark` | Loaded. Its app-screen branch applies (no shape, hero or fold; tokens, focus, states, no eyebrow, no tracked capitals, no invented figure). Its `sloplint` was not run (it is a page-build tool; `inspect.mjs --app` and the impeccable detector were the gates used). |
| `ui-ux-pro-max` | **Did not load.** `Skill` answered "Unknown skill"; `~/.claude/skills/ui-ux-pro-max` is a dangling symlink to `~/.agents/skills/ui-ux-pro-max`, which does not exist. |
| `design-md` | Loaded. Used for `DESIGN.md` and its lint. |
| `awesome-design-md`, `open-design` | Read for reference only (the Mercury DESIGN.md frontmatter shape; the open-design SKILL.md). Nothing was copied. |
| `emil-design-eng` | **Unavailable.** Dangling symlink to `~/.agents/skills/emil-design-eng` (does not exist). |

Deviations from the skills, on the record: three typefaces (the owner brief's display + text + figures; the taste skill says two), tracked ledger rules and dot leaders and dashed empty-state frames (the brief's concept; the app-screen chapter allows borders and dashed empties), no hue on the trend chart (colour is reserved for meaning, so the inspector's `no-data-hue` warning stands).

## 2. What was built

**Build pipeline.** The Tailwind Play CDN, Google Fonts, Material Symbols and every inline `<style>`, `style=""` and script are gone. `resources/css` (fonts, tokens, base, shell, components, pages) and `resources/js/fx` (theme, shell, command bar, dialogs, forms, toasts) are built by Vite (`npm run build`); Tailwind 3 stays for utilities only (D-403). Fonts: Archivo, Public Sans, Chivo Mono from `@fontsource-variable/*` (OFL 1.1), self-hosted, latin subset, about 143 KB. Icons: Lucide (ISC), 73 glyphs inlined through `<x-fx.icon>` from `App\Support\Ui\Icons`.

**Security headers and CSP** (`App\Http\Middleware\SecurityHeaders`, registered in `bootstrap/app.php`; `config/security.php`; `POST /csp-report`): enforced nonce CSP, HSTS (HTTPS only), nosniff, X-Frame-Options DENY, referrer and permissions policies. `'unsafe-eval'` remains for Livewire's Alpine (D-404). Modelled on the shape of the Emall middleware (the repo's local copy is stale; I read `origin/main`), but that one is report-only with inline allowed; this one is enforced with a nonce.

**Shell** (`layouts/app.blade.php`, `layouts/guest.blade.php`, error pages): desktop sidebar grouped Money / Sales / Purchases / Bank / Tax / Reports / Planning / Controls; ledger switcher; account section; top state line; search; command bar (Ctrl/Cmd+K); theme toggle (system, light, night); mobile bottom bar and bottom-sheet menu; skip link and landmarks; sign-in, recovery and legal pages; error pages 403/404/419/429/500/503 (the ecosystem "discover" list is kept, drawn with SVG dots).

**Component library** `resources/views/components/fx/*` (see `DESIGN.md` Components): money, trace, statement, table, pill, button, input/select/textarea/check/field/errors/flash, period, tabs, meter, empty, dialog/confirm/command/toast(s), gloss, state-line/state-item, page-head, panel, swatch, avatar, icon, mark. Jetstream's stock components (form-section, action-section, modal, banner, input, button, label...) were rewritten onto the same classes so the Livewire pages inherit the look.

**Pages redesigned, behaviour and routes unchanged:** dashboard ("Command centre": cash position per currency with provenance ticks and ruled net, "Needs you", coming up, budgets, reserves, spending trend, recent transactions, accounts, rule-based summary, and a slot for later modules' panels), transactions (filters fold on phones, cards on narrow containers, confirm dialog), add/edit transaction, import and its preview, transfers, accounts (+ edit, delete confirmation), categories (+ edit), budgets (+ edit), recurring (+ edit), cash flow, forecast, weekly summaries, audit log, balance tie-out (green ticks only here, where a figure really is verified a second way), profile, two-factor, sessions, password, account deletion, teams (create, settings, members), API tokens, sign-in, register (closed), forgot/reset password, verify email, confirm password, two-factor challenge, cookies/terms/privacy, welcome. Nothing shows a made-up figure: empty states say what belongs there and offer the action that creates it.

**`finance:demo-seed`** (local/testing only, D-412).

## 3. For the other agents: how to adopt it

- **Layout.** Keep `<x-app-layout title="Invoices">...</x-app-layout>` (the `title` prop is new and optional) and open with `<x-fx.page-head title="Invoices" sub="..."><x-slot name="actions">...</x-slot></x-fx.page-head>`. One `h1` per page. Wrap content in `<x-fx.panel title="..." flush>`. Use `<x-fx.table caption="..." >` with `<th scope="col">` and `data-label` on each `<td>` (the cell with `class="is-lead"` is the card heading), `class="is-num"` on figures, `is-actions` on the last cell.
- **Money.** `<x-fx.money :cents="$c" :currency="$code" :signed="true" :trace="route(...)" trace-label="Show the entries" />`. Pass `variance` only for something overdue or over a limit, `ticked` only for something verified.
- **Forms.** `<x-fx.input name="amount" label="Amount" figure />`, `<x-fx.select ...>` with your `<option>`s, `<x-fx.check>`, `<x-fx.errors />`, `<x-fx.flash />`. Errors, `aria-invalid` and `aria-describedby` are automatic. Destructive form: `data-fx-confirm="Message" data-fx-confirm-label="Void"`.
- **Empty states.** `<x-fx.empty title="No invoices yet."> what belongs here <x-slot name="action"><x-fx.button ...></x-slot></x-fx.empty>`.
- **Navigation.** Do not edit the shell. Register a route with one of the names below and the item turns from "Soon" into a link (and into a command-bar entry) by itself. Add anything else with `Navigation::add('sales', ['key', 'label', 'icon', 'routes' => [...], 'can' => 'ability'])` from a provider.
- **State line / dashboard.** `StateLine::extend(fn ($ledger) => ['key', 'tone' => 'variance'|'neutral', 'count', 'text', 'href'])`; `DashboardPanels::add('your.view', order: 20)`.
- **Glossary.** `<x-fx.gloss term="VAT"><x-slot name="glossary">plain explanation</x-slot></x-fx.gloss>`, or `<x-fx.gloss term="vat" />` once `App\Support\Glossary` (agent F2) has one of `explain`, `for`, `get`, `ownerExplanation` (the component calls the first it finds; adjust `components/fx/gloss.blade.php` if F2's API differs).
- **Rules.** No inline style, no `<script>` without the nonce (`Vite::cspNonce()`), no CDN, no third-party image. A user-chosen colour is only an SVG attribute. Never mention the asset folders in a view comment that also contains `@vite`.

Route names the shell already listens for (first registered alternative wins):

| Group | Item | Route names |
|---|---|---|
| Sales | Invoices / Quotes / Customers | `sales.invoices.index`, `invoices.index` / `sales.quotes.index`, `quotes.index` / `parties.customers.index`, `customers.index`, `sales.customers.index` |
| Purchases | Bills / Suppliers / Expense claims | `purchases.bills.index`, `bills.index` / `parties.suppliers.index`, `suppliers.index`, `purchases.suppliers.index` / `purchases.claims.index`, `claims.index` |
| Bank | Statements / Bank reconciliation | `bank.index`, `bank.statements.index` / `bank.reconciliation.index` |
| Tax | Overview / VAT / Calendar | `tax.index` / `tax.vat.index`, `vat.index` / `tax.calendar.index`, `tax.calendar` |
| Reports | All reports / Profit and loss / Balance sheet | `reports.index` / `reports.profit-loss`, `reports.pnl` / `reports.balance-sheet` |
| Planning | Scenarios | `planning.scenarios.index` |
| Controls | Approvals / Roles and access / Period locks / Chart of accounts / Journals | `controls.approvals.index`, `approvals.index` / `controls.access.index`, `controls.roles.index` / `controls.periods.index`, `accounting.periods.index` / `accounting.accounts.index`, `gl.accounts.index`, `chart.index` / `accounting.journals.index`, `journals.index` |

Quick actions in the command bar also watch `sales.invoices.create`, `invoices.create`, `purchases.bills.create`, `bills.create`.

## 4. Tests

SQLite (in memory): **608 tests, 601 passed, 7 skipped, 0 failed, 4,351 assertions** (baseline was 564 with the same 7 skipped; 44 added). New: `SecurityHeadersTest` (23: headers on every response, nonce per request and on the theme and Livewire scripts, no `'unsafe-inline'`, report-only switch, HSTS, the report endpoint, and for 16 signed-in pages no style attribute, no un-nonced script, no inline handler, no third-party origin, plus public and error pages), `DesignSystemTest` (16: `fx_route`, disabled "Soon" items vs links, grouped nav and `aria-current`, command bar lists only real routes, money brackets and `negative`, provenance link, pill glyphs, glossary, state line with real counts and the "Nothing needs you" case, extension points, `category_id=none`, table captions and scopes, one `h1` and a skip link per page), `DemoSeedTest` (3: refuses in production and staging, builds a ledger that ties out to the cent with no findings, `--fresh` rebuilds without duplicates).

Existing tests changed, deliberately: `ResponsiveShellTest` (the shell was rebuilt: its structural hooks now name the sheet, bottom bar, state line and built script/CSS files; the transfer form fixture has two accounts; the "no inline style/script" guard is new) and `ForecastTest::test_the_dashboard_does_not_mix_projected_figures_into_actuals` (D-401). No test was deleted or weakened otherwise; all wording the other tests assert ("Add Transaction", "Spending Trend", "Rule-based summary", "Audit log", "Your role: ...", "No transactions yet", ...) is preserved.

PostgreSQL (throwaway database, clean environment, `phpunit.pgsql.xml` made from `phpunit.xml`; the file is left untracked so the agents do not collide on it): **608 tests, 1 failure**, `InfoDotRedeemTest` line 263 (`personal_access_tokens ... invalid input syntax for type bigint: ""`). Nothing on this branch touches tokens or that test; I did not run it on the base commit, so whether it is pre-existing is **not verified**. `php artisan route:cache && route:clear` succeeded. `vendor/bin/pint` clean.

## 5. Verification in a browser

Built-in browser, own SQLite file, port 9201, demo data from `finance:demo-seed`, real login and the real ledger switcher. Checked: dashboard, transactions, accounts, tie-out, forecast, add transaction and profile at desktop width in night and light; dashboard and transactions at an exact 375x812 viewport (bottom bar, bottom sheet menu, card tables, folded filters, no horizontal page scroll: scrollWidth equals viewport width); 1024 (tables turn to cards by container width). Functional checks in the page: the type-filtered category list, the Ctrl+K command bar filter and its search fallback, the confirm dialog opening with the form's message and closing, the theme toggle cycling system, light, night and persisting, the skip link taking focus first with a visible ring. Across a sweep of ten pages (plain and Livewire) the console reported no CSP violation; the only violations seen came from Laravel's own debug error page during a deliberate parse error and from Boost's dev-only browser logger. Fonts report `loaded` for all three families. Contrast was computed for every text/background token pair in both themes (all at least 5.3:1; money text 6.1 to 17.8:1; the table is in `DESIGN.md`).

`impeccable detect` (static) on 16 rendered pages: 0 findings (they contain no inline CSS to scan) and on `resources/css`: 0 findings. Its URL scan needs `puppeteer`, which is not installed in the Impeccable checkout, so the live-DOM pass was **not run**. `design-taste-frontend/scripts/inspect.mjs --app` on saved copies of the rendered pages (file-served): transactions and the tie-out 0 FAIL, 2 WARN (3 typefaces, by design; `font-fallback`, an artefact of the file-served copy: the live app reports all fonts loaded). Dashboard 1 FAIL and 5 WARN, defended: `covered-text` at 390px is the fixed bottom bar passing over the lead figure while scrolling (the main area has bottom padding so nothing is permanently covered); `ragged-row` 77px is two stacks of unlike content ending at different heights (top-aligned on purpose rather than stretched over empty space); `below-fold` 5 of 11 blocks is a dashboard that answers cash and needs first; `no-data-hue` is the colour-means-one-thing rule.

## 6. Not verified, partial, or left

- **Not tested:** a physical phone and Safari/Firefox (Chromium only); real screen readers (the markup was checked for names, landmarks, scope and labels with a script, not with NVDA/VoiceOver); print styles beyond hiding the chrome; the tablet band was checked only through the 1024px container behaviour, not at 768 in a screenshot.
- **Livewire pages** (profile, teams, API tokens): restyled through the rewritten stock components, the remapped palette and new page wrappers; their inner markup still uses Tailwind utility classes. They look right in night and light at the profile page; the team-member manager, role dialogs and API-token page were not individually screenshotted. The API token page is still unreachable (feature off in `config/jetstream.php`).
- **`ui-ux-pro-max` and `emil-design-eng` are unavailable** (broken symlinks); their checks were not applied.
- **`'unsafe-eval'`** stays in the CSP until Livewire's Alpine can be replaced (D-404).
- **Glossary**: `<x-fx.gloss>` is ready but nothing links it to `App\Support\Glossary` until F2's class exists; the shell itself uses no jargon that needs a gloss today.
- **Trend chart** has no hue by design (D-400); a dashboard with several series would need a decision on how to tell them apart without green or red.
- **Mail templates** (`resources/views/vendor/mail`) still carry inline styles, which is correct for email clients and outside the CSP.
- **Packages**: `@fontsource-variable/archivo`, `public-sans` and `chivo-mono` were added to `package.json` (devDependencies, lockfile updated); `@tailwindcss/vite` (unused before and after) is untouched. `composer.json` gained an `autoload.files` entry for `app/Support/helpers.php` (`fx_route()`); run `composer dump-autoload`.

## 7. Owner steps

1. Decide whether to keep three typefaces (D-402) and `'unsafe-eval'` (D-404).
2. After deploying, watch the log for `csp.violation` lines for a few days; `SECURITY_CSP_REPORT_ONLY=true` is the instant fallback.
3. Set `DOT_*` env URLs on the host if the error pages' ecosystem list should point somewhere other than the registry defaults.
4. Run `finance:demo-seed` only on a local machine; it refuses anywhere else.
