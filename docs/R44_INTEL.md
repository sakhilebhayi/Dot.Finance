# R-44 Intelligence and integrations (findings)

Branch `feat/r44-intel`, decisions D-1000..D-1017 in `R44_DECISIONS.md`, integrations in `docs/integrations/`. Built on Wave 1 only (no Wave 2 module was merged when this started); everything that needs a Wave 2 module is behind a contract with an honest default, so nothing waits and nothing is faked.

## What was built

**Assistant** (`/assistant`, `App\Services\Intelligence`). `IntentRecognizer` (keyword and synonym rules with weights, a period parser, amounts) picks one of 13 answerable intents plus three draft intents. `HandlerRegistry` maps each to a handler over the active ledger: spend, expenses that went up (this period against the same stretch of the month before), who owes me, overdue invoices, upcoming bills, cash position, projected cash (forecast engine), profit (cash view), VAT so far, tax to prepare for, needs attention, budgets, can I afford. Each returns an `Answer`: one sentence worded from its own numbers, facts, a table of rows, sources (links to the drill-downs), notes (assumptions) and a basis (Calculated, Estimate, AI-generated summary). If the ledger cannot answer, the answer is "I don't have that information" plus what would create it. History is stored per person per ledger and is deletable; asking is rate limited by a provider-registered limiter (`finance-assistant`, 12 a minute).

**Model layer** (off: `FINANCE_AI_ENABLED=false`). May choose an intent from the fixed list for a question the rules did not understand, and may reword an already-computed answer. `NumberGuard` throws the wording away unless every whole number in it is in the computed text, it uses no new number word and no link. Tested with a fake client (accepted, changed number, invented percentage, "double", failing client, empty reply, no_data never sent, intent injection) and the Anthropic client against `Http::fake`.

**Draft-only actions.** Draft transaction, draft categorisation, draft reminder. A draft is a pending row. `DraftExecutor` is the only class in the namespace that writes the books; it is reached only from a CSRF-protected POST bound to the draft id and the person who was shown it, and re-checks capability, validation, period lock (through `GlSync`), approval policy, expiry and replay (see D-1003). Proven by 15 tests including a check that the POST really is refused without a token outside the testing environment, and a scan of the namespace for the forbidden write paths.

**Findings** (`/attention`, `finance:intel:scan` daily). Eight detectors (D-1006) with evidence rows, severity, dismiss and snooze, a state-line item, the weekly digest appended to the existing weekly summary row (D-1008) and the assistant's "what needs attention" answer.

**Notifications** (`/notifications`, `/notifications/preferences`, the bell). `FinanceNotification`, per-person per-kind preferences, e-mail through the app mailer, Dot.Notify forwarding when connected, scheduled rules `finance:notifications:run` daily at 07:00 (approvals waiting, tax deadlines, overdue summary, new warnings).

**Integrations** (see `docs/integrations/README.md` for the table, evidence and owner steps):

| | status |
|---|---|
| InfoDot sign-in, companies and roles | connected (existing; verified and hardened) |
| InfoDot membership webhook (`POST /api/ecosystem/membership`) | connected (new; same endpoint as Files and docs) |
| Dot.Notify | connected, unverified against a live Notify |
| Dot.Analytics | contract only (Analytics' catalogue lacks `finance`) |
| Dot.Emall | contract only (Emall has no sender, fees or API) |
| Dot.HR payroll | contract only (HR has no payroll) |
| Projects, Files, Billing, Engage, Agents, Brain/Memory | not possible, with reasons |

`/integrations` shows the same table to signed-in people, and `/integrations/emall` is the review screen for staged Emall sales.

**Other edits to shared files** (small, expect trivial merge conflicts): `bootstrap/providers.php` (one line), `routes/web.php` and `routes/api.php` (marked `// --- intel ---` blocks), `routes/console.php` (three schedules), `layouts/app.blade.php` (one `@includeIf('intel.partials.bell')` line), `resources/css/app.css` (one import), `app/Support/Ui/StateLine.php` (optional key on `extend`), `app/Http/Controllers/AuditController.php` (three subjects in the filter list), `app/Console/Commands/GenerateInsights.php` (digest), `app/Console/Commands/DemoSeed.php` (an `intelligence()` step), `app/Http/Controllers/Auth/EcosystemAuthController.php` (token id length), `tests/Feature/Company/ContextIsolationRoutesTest.php` (new tables and bindings: `message`, `draft`, `insight`, `notification`, `line`).

## Binding another module in

```php
// in the module's own provider, listed after IntelligenceServiceProvider in bootstrap/providers.php
$this->app->bind(\App\Contracts\Intelligence\ReceivablesSource::class, \App\Services\Sales\SalesReceivables::class);   // overdue invoices, who owes me by invoice
$this->app->bind(\App\Contracts\Intelligence\PayablesSource::class, \App\Services\Purchases\PurchasesPayables::class);    // approved bills by due date
$this->app->bind(\App\Contracts\Intelligence\TaxPositionSource::class, \App\Services\Tax\TaxPosition::class);             // real VAT period and calendar
$this->app->bind(\App\Contracts\Intelligence\TaxDeadlineSource::class, \App\Services\Tax\TaxDeadlines::class);           // deadline notifications
$this->app->bind(\App\Contracts\Intelligence\AffordabilitySource::class, \App\Services\Planning\Affordability::class);
$this->app->bind(\App\Contracts\Intelligence\InvoiceReminderSender::class, \App\Services\Sales\InvoiceReminders::class);  // enables reminder drafts
$this->app->bind(\App\Contracts\Integrations\PayrollSummarySink::class, \App\Services\Tax\PayrollImport::class);
```

## Tests (exact)

| Run | Result |
|---|---|
| Baseline before this work | SQLite 1,169 tests, 1,159 passed, 10 skipped |
| Final, SQLite in memory | **1,324 tests, 1,314 passed, 10 skipped, 0 failures** (155 new), 269,385 assertions |
| PostgreSQL 16 (`phpunit.pgsql.xml`, clean environment, throwaway database), first full run | 1,324 tests, 2 problems. (1) `IntegerMoneyTest` could not drop `journal_entries` because `emall_sale_lines` had a foreign key to it: fixed by removing that key (D-1018). (2) `AttachmentsTest::test_script_capable_and_executable_types_are_refused` failed once: it feeds `random_bytes(64)` as a `.bin` file and the content sniffer occasionally calls such bytes text; it passes when run alone and is not touched by this branch (a flaky test of the shared-data module, not fixed here) |
| PostgreSQL after the fix, the affected groups (`IntegerMoneyTest`, `tests/Feature/Integrations`, `tests/Feature/Company`) | 184 tests, 0 failures (1 skipped, pre-existing); the whole suite was **not** rerun after the fix. An earlier PostgreSQL run of `tests/Feature/Intelligence` and `tests/Feature/Integrations` found and fixed the aborted-transaction bug (D-1017): 116 tests, 0 failures |
| `route:cache` then `route:clear` | caches and clears cleanly |
| Pint | clean on touched files |

New tests: `tests/Unit/Intelligence` (recogniser 28 questions plus periods, amounts and horizons; number guard), `tests/Feature/Intelligence` (answers against real data, model layer, drafts, detectors and engine, notifications and rules, pages), `tests/Feature/Integrations` (membership webhook, token hardening, Emall import and posting, Analytics publisher, Notify client and payroll contract, docs in step with the status page). The cross-ledger harness (`ContextIsolationRoutesTest`) now smuggles a foreign id into every new route parameter and compares the database before and after.

Bugs the tests found: the weekly-summary relation loaded outside a ledger context; a draft expiry was rolled back by its own refusal; the PostgreSQL-only "transaction aborted" after catching a unique violation inside a transaction (D-1017); an `Intent` rule that sent "Remind Acme about their overdue invoice" to overdue invoices; stored absolute URLs (D-1011).

## Verified in a browser

Own SQLite file, port 9216, `finance:demo-seed` extended deterministically (an entry made twice, a round cash amount, then the real scan, three real questions and the real notification rules). Looked at: the assistant page with three real answers (desktop dark and phone 375), the draft review form, a draft confirmed through the real form (the transaction, its audit rows and the draft status checked in the database), attention (dark and light), notifications, the integrations table. No horizontal scroll at 375 on any new page after one fix (long setting names in the integrations table), no console errors. Tablet width (768) and Safari/Firefox were not looked at. The bell badge, the attention actions row and the draft form spacing were each wrong on first sight and fixed.

## Design skills

Invoked through the Skill tool: `design-taste-frontend` (read `references/app-screens.md`: its rules for app screens: colour on data not chrome, KPIs are type not tiles, flat surfaces, honest empty and zero states, words of the trade) and `impeccable` (read `reference/craft-floor.md`). The `impeccable context` script, `inspect.mjs`, `detect` and `draw.mjs` were **not** run; the screens use the existing Tie-Out design system (`x-fx.*`, tokens only, one small `intel.css`, no new colour) and `hallmark`, `design-md`, `ui-ux-pro-max` and `emil-design-eng` were not invoked (the first three are page or token tools; the last two are unavailable as in the UI wave). Deviations: none from DESIGN.md. Colour carries one meaning (pencil red for warning and worse, the green tick only for a connected integration that is switched on); no tiles, no gradients, no inline styles, no scripts.

## Not done, not verified, honest limits

- **Laravel Boost** was not used (no MCP client in this session).
- **Dot.Notify** delivery was never observed live; Notify's README still calls the receiver unimplemented while its code has it. The client is proven against a fake receiver that verifies the signature as the real one does.
- **Dot.Analytics** will refuse every snapshot until its catalogue gains `finance`. Dot.Emall, Dot.HR have nothing on their side. Nothing was run against them.
- **Model layer** is tested only with fakes and `Http::fake`; no real Anthropic call was made, so the prompt's real-world behaviour (how often it is discarded) is unknown.
- **Bank-detail change detector** is silent today (no bank fields exist on parties). It reads the audit log under configured field names, so it fires once Purchases stores and audits them.
- **Overdue invoices, who owes me by invoice, bills by due date, VAT period, tax deadlines, reminders, planning affordability** answer "I don't have that information" until Wave 2 modules bind their sources. `who owes me` and VAT already work from the general ledger when accounting is on (tested).
- **Recogniser coverage** is the 28 phrasings in the test plus synonyms; a question phrased very differently will be "not understood". It does not understand names of categories or customers except in the draft intents.
- **The savepoint race path** (losing a unique-index race) is guarded but not exercised by a test; the existence pre-check handles the normal case.
- **Real concurrency** (two scans at once, two confirmations at once) was not run in separate processes; the confirmation relies on a row lock (a no-op on SQLite) plus the status check, and the findings on a unique index.
- **E-mail** was only asserted through `Mail::fake` and the log mailer; no real SMTP send, and the markdown mail template was not rendered in a mail client.
- Assistant questions are stored in plain text (the person can delete them); they may contain whatever the person typed.
- `Team::LEDGER_TABLES` (the "has company data" list) was not extended with the seven new tables (restrict-on-delete keys protect a company with rows); add them at integration.

## Owner steps

1. In InfoDot add `finance` to `ECOSYSTEM_MEMBERSHIP_PLATFORMS`; confirm `INFODOT_SERVICE_SECRET` here equals `ECOSYSTEM_SECRET_FINANCE` there.
2. To get Dot.Notify messages: create the webhook, event map and rule in Dot.Notify, set `NOTIFY_WEBHOOK_URL` and `NOTIFY_WEBHOOK_SECRET`, and each person opts in on `/notifications/preferences`.
3. To publish to Dot.Analytics: add the `finance` catalogue entry (see `docs/integrations/analytics-finance-metrics.md`) and its secret there, then set `ANALYTICS_INGEST_URL` and `ANALYTICS_INGEST_SECRET` here; run `php artisan finance:publish-metrics --dry-run` first. A queue worker must run for it.
4. Dot.Emall and Dot.HR: build the senders to the contract files before setting `FINANCE_EMALL_SECRET` or `FINANCE_HR_SECRET`.
5. Decide whether to switch on the model layer. It sends computed answers (figures, party and category names) to the provider; `FINANCE_AI_ENABLED=true` and `ANTHROPIC_API_KEY`. Leave it off if that is not acceptable under your POPIA position.
6. After deploy: `php artisan migrate`, and check the scheduler runs `finance:intel:scan`, `finance:notifications:run` and `finance:publish-metrics`.
