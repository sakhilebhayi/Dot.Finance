# R-44 QA: security and tenant isolation (findings)

Branch `feat/r44-qa-security`, decisions D-1400..D-1418 in `R44_DECISIONS.md`. Cut from the trunk after every module was merged (sales, purchases, bank, tax, reporting + planning, intel, restyle, controls, shared, gl, ui). Everything below was run; "not tested" says so plainly.

Skills: this was a security sweep, not UI work. The only view/CSS edits are a bug fix in `resources/views/audit/index.blade.php` (a value normalised before it is printed) and a missing `}` in `resources/css/pages.css`. No design skill was invoked and none was needed; no visual change was made or checked. Laravel Boost MCP was not used.

## 1. Result in one screen

| | |
|---|---|
| Real defects found and fixed, each with a regression test | 17 (section 2) |
| Observations left as they are, with the reason | 9 (section 3) |
| New test files | 15 under `tests/Feature/Security` (+ 2 helper traits) |
| SQLite full suite (clean env) | **2,035 tests, 2,022 passed, 13 skipped, 0 failed** in the last full run; the 3 mail tests were added after it and pass alone (the Security folder alone: 146 tests, 1,434 assertions, all pass) |
| PostgreSQL full suite (throwaway DB, clean env) | **2,035 tests, 0 failed, 10 skipped** (section 7) |
| Baseline before this work | the first full run (SQLite) reported 1,915 tests, 1,902 passed, 13 skipped, 0 failed; the trunk lists 1,892 tests today (I did not reconcile the difference of 23; no trunk test was edited or removed) |
| Tests added | **146** in `tests/Feature/Security` (all pass on SQLite and PostgreSQL); the suite now lists 2,038 |
| `composer audit` | no advisories |
| `npm audit` | 9 advisories before (2 critical, 4 high, 3 moderate), all dev/build tools; 2 moderate left after `npm audit fix` (section 3) |
| `route:cache && route:clear` | runs; `view:cache` compiles |

## 2. Defects found and fixed

High = a person could reach, break or read something they should not, or the build/deploy fails. Medium = wrong or unsafe behaviour with a realistic trigger. Low = hardening, or a wrong answer without a harmful trigger.

### High

1. **Any personal access token opened the whole web application** (D-1405). Every ledger route is behind `auth:sanctum`, which accepts a session or a Bearer token. Evidence: `AuthAndSessionSecurityTest::test_a_bearer_token_does_not_open_the_web_application` got **200 on `/dashboard`** and the other pages with a token carrying only `read`, and with the one-time InfoDot handoff token's ability `ecosystem:read`. A leaked API token, or a handoff token not yet redeemed, read every ledger its owner belongs to, past the 2FA that guards the login. Whether the forms also accepted such a token (a non-browser client can send `Sec-Fetch-Site: same-origin`, which Laravel's forgery check trusts) was **not tested**: CSRF checking is off under PHPUnit. Fixed: `RejectBearerTokensOnWeb` refuses a Bearer header on the `web` group (401); `/api/user` still works with a token.
2. **`npm run build` failed on the trunk** (D-1415). The Purchases and Tax merge left `.fx-inline { display: inline-block;` in `resources/css/pages.css` without its closing brace ("CssSyntaxError: Unclosed block"), so a deploy could not build the assets. One line restored; the build passes.
3. **`/audit` answered 500 for every company that had ever imported a bank statement** (D-1413). The view assumed every change value is `{before, after}`; the bank import records plain values (`file`, `rows_total`, `in_cents`...). Found by the XSS sweep opening every page. The audit trail was unreadable after a first import.

### Medium

4. **PDF "zip bomb" in the receipt reader** (D-1409). `PdfText` inflated every stream without a limit: a 117 KB compressed stream took **6.2 s and 241 MB** to read, and a 15 MB upload could hold gigabytes (the receipt is read inside the upload request). Now 4 MB per stream, 16 MB per file, 2,000 streams; the same file reads in milliseconds. Tests: a 120 MB bomb in under 2 s and under 96 MB, a flood of 6,000 streams, 18 hand-made and 180 mutated or random malformed PDFs, all in bounded time with no exception.
5. **Non-numeric or oversized ids in any URL were a 500 on PostgreSQL** (D-1418). `/accounts/abc/edit` or an id of 25 digits reached the database as `id = 'abc'`; PostgreSQL answers "invalid input syntax for type bigint" (a 500 that also aborts the transaction); SQLite says "not found", so no SQLite test could see it. Found by running the new `NumericIdRoutesTest` on PostgreSQL (it also caught `/bank/reconciliation/{20 digits}/...`, where a route carried its own `[0-9]+`). One numeric pattern now covers every id parameter and `whereNumber` in the route files became `[0-9]{1,18}`. 12 hostile ids on every id route (about 3,000 requests) are a clean 404 on both engines.
6. **A reused idempotency key with a different request silently returned the first** (D-1410): supplier payments (a different amount or different bills under the same key), payment runs (different bills, account or dates) and sales refunds (a different amount or source). The caller was told "done" for money it never asked for. Sales receipts were already strict. Now a clear refusal; the identical retry is still a no-op. Tests: `MoneyIntegrityAttackTest`, `MoneyIntegrityPurchasesTest`. Real two-process concurrency was **not** re-tested here.
7. **CSV formula injection in the supplier statement export** (D-1402): a supplier named `=HYPERLINK(...)` ran in a spreadsheet. Every other CSV export already neutralised leading `= + - @ tab CR`; the sweep now plants those payloads in party names, descriptions, references and bank narratives (CSV, OFX, QIF) and opens every CSV route.
8. **Membership webhook replay and size** (D-1403). A captured signed `member.role_changed` could be replayed inside the 5-minute window after InfoDot had demoted the person, handing the role back; there was no body cap. Each authenticated delivery is now remembered for twice the tolerance, and a body over 16 KB is a 413.
9. **Password reset revealed which emails have an account** (D-1406): "We can't find a user with that email address". Every account is created by InfoDot, so this named the people who use the Dot platforms. Known and unknown addresses now get the same answer.
10. **Exports without the export capability, and mostly unaudited** (D-1412). The AP open-items CSV needed only `view-finance`, and only 3 of the 11 ways to take a file out of the books (queued report exports, payment-run CSV, accountant pack) wrote an audit row. Every export now writes an `exported` row (who, which export, format, nothing from inside the file) and the AP CSV needs `export-reports`.

### Low

11. Accounting setup `dry-run` and `apply` on an unprepared ledger answered 500 (D-1401).
12. A price times a quantity beyond the integer range answered 500 in the sales editor and on bills (D-1411). It already failed closed (nothing wrapped, nothing saved); it now says "Amount is outside the supported range", and a document total is capped at `Money::MAX_CENTS`.
13. The payroll import had no body-size cap (D-1404).
14. The public share-link throttle was keyed by token, so every guessed token got a fresh bucket (D-1407); the share pages also set a session and an XSRF cookie although the code and docs said "no cookie" (D-1408).
15. The anonymous CSP report sink answered 500 to a nested array (D-1414).
16. The session cookie `Secure` flag relied on request detection only; it is now on by default in production (D-1417).
17. `npm audit fix` applied (D-1416).

## 3. Observations left as they are

- **Approver can void a posted bill or claim, approve changed supplier bank details, write off an invoice and issue a customer refund.** The capability matrix (`FinanceRole`) gives the approver `approve-bill`, `approve-expense`, `approve-payment` and `approve-invoice`, and the code uses them for those four actions. A void posts a reversing entry and keeps the bill. If "approve" should mean only approve, these need their own capabilities. Owner decision.
- **`GET /purchases/receipts/{attachment}` is open to every reader**, while the inbox list and its actions need an entering role. It follows `AttachmentPolicy` ("an inbox file is readable by anyone who can read the ledger") but is inconsistent with the list.
- **`GET /tax/returns/{id}/workpaper(.csv)` changes data on a GET** (an export counter, and now an audit row). Harmless, but a GET with a side effect.
- **A saved report view and a queued report export are creatable by a viewer** (they can already read the report). They are in the read-only allow-list of the matrix test, each with its reason.
- **Logs.** The application's own log calls write class names, ids and statuses only. Exceptions reported with `report($e)` (mail failure, GL deferral) write the exception message, and a failed database write logs the SQL with its bound values, so a failed insert could put a value in the log. Not exploitable by a stranger; not changed.
- **`POST /_boost/browser-logs`** is Laravel Boost's development route. It exists only where the dev dependency is installed; the deploy uses `composer install --no-dev`. Not verified on the host.
- **The SSO handoff accepts `Sec-Fetch-Site: same-site`** when a browser sends neither Origin nor Referer; `same-site` includes sibling subdomains. When Origin or Referer is present it must be InfoDot exactly (or, with no InfoDot URL configured, this app's parent domain).
- **Remaining `npm audit`**: 2 moderate (`postcss-selector-parser` quadratic complexity, reached through `@tailwindcss/typography`). Build-time only, on CSS this repository wrote; the only fix npm offers downgrades typography to 0.5.4.
- **Access removed in InfoDot reaches this app only through the webhook or the next InfoDot sign-in.** A password reset still lets a removed person log in locally; they then see no company (the membership is gone), and the webhook is what ends access in seconds. If `INFODOT_SERVICE_SECRET` is empty on the host, the webhook is off. Owner step.

## 4. How the authorisation matrix works

`tests/Feature/Security/AuthorisationMatrixTest.php` walks `Route::getRoutes()` (every route whose action is in `App\Http\Controllers`; 357 of them take only ids the seeder can fill and are in the table below; vendor routes (Fortify, Jetstream, Livewire, Sanctum, Boost) are covered separately, see section 5). For each route and each of ten people it makes one request with a generous body (so a refusal is the authorisation and not a missing field), inside a savepoint that is rolled back, with every table that carries `user_id` and `team_id` (found from the schema, 85 tables, so a new table is covered at once) hashed before and after: about 3,570 requests, about two minutes.

The ten people: **owner, finance manager, accountant, bookkeeper, approver, viewer** (a real company with the six roles, the accountant and approver by local grant), **foreign owner** (owns another company, smuggles Mine Ltd's ids), **personal user** (only a personal ledger, smuggles the same ids), **outsider** (belongs to nothing, claims Mine Ltd in the session), **guest**.

Properties asserted for every (route, person):
- nothing answers 5xx;
- every response carries the enforced CSP with a nonce, `nosniff` and `X-Frame-Options: DENY` (pages, redirects, downloads, 403/404, validation errors);
- a guest reaches only the 8 listed public routes and changes nothing;
- the foreign owner, personal user and outsider get 404/403 on every route that takes a Mine Ltd id (the one exception, a staged-import token from another ledger, is the documented redirect to the upload page), change **no row of Mine Ltd**, and see no `Sentinel ... mine` row on any page;
- a viewer and an approver change nothing anywhere except through a short allow-list with a reason on each line (own notifications, own assistant history, the approver's approve actions, download audit rows...);
- the **gate pass-set** of every route (which roles get past the authorisation check) equals the reviewed table in `matrix_golden.php`. A new route fails the test until someone reads its row; a permission appearing shows as a changed letter.

The seeded ledger holds about 80 kinds of row (accounts to tax payroll runs), including a supplier, an inbox receipt and a finished export, so the id routes find real rows. Routes that need a state the seed does not create (a credit note of a posted bill, a staged bank import, a share token, a logo, the signed download routes) answer 404/403 to everyone in the table; those are covered by dedicated tests (section 5).

Reading the table: letters are O owner, M finance manager, A accountant, B bookkeeper, P approver, V viewer; `none` = nobody passes the gate in this fixture. Foreign column: `404` = refused as not found; `200/302` on a route with no id means the caller acted in their own ledger (the sweep proves nothing of Mine Ltd was read or changed). `login` = redirected to sign in. The reviewed differences between roles match the capability matrix in `R44_CONTROLS.md`: accounting and tax routes `OMA`, entering routes `OMAB`, the approver's extra actions `OMP` / `OMABP`, controls `OM`.

| Route | Gate passed by (O M A B P V) | Foreign owner / personal user / outsider | Guest |
|---|---|---|---|
| `GET /accounting/chart` | OMABPV | 200/302/302 | login |
| `POST /accounting/chart` | OMA | 422 | login |
| `GET /accounting/chart/create` | OMA | 200/302/302 | login |
| `PUT /accounting/chart/{glAccount}` | OMA | 404 | login |
| `POST /accounting/chart/{glAccount}/activate` | OMA | 404 | login |
| `POST /accounting/chart/{glAccount}/deactivate` | OMA | 404 | login |
| `GET /accounting/chart/{glAccount}/edit` | OMA | 404 | login |
| `GET /accounting/general-ledger` | OMABPV | 200/302/302 | login |
| `GET /accounting/journals` | OMABPV | 200/302/302 | login |
| `POST /accounting/journals` | OMA | 422/302/302 | login |
| `GET /accounting/journals/create` | OMA | 200/302/302 | login |
| `GET /accounting/journals/{entry}` | OMABPV | 404 | login |
| `POST /accounting/journals/{entry}/reverse` | OMA | 404 | login |
| `GET /accounting/periods` | OMABPV | 200/302/302 | login |
| `POST /accounting/periods/close-through` | OMA | 422 | login |
| `POST /accounting/periods/{period}/close` | OMA | 404 | login |
| `POST /accounting/periods/{period}/reopen` | OMA | 404 | login |
| `GET /accounting/setup` | OMA | 302/200/200 | login |
| `POST /accounting/setup` | OMA | 422 | login |
| `POST /accounting/setup/apply` | OMA | 302/422/422 | login |
| `POST /accounting/setup/dry-run` | OMA | 302/422/422 | login |
| `POST /accounting/setup/mapping` | OMA | 302 | login |
| `GET /accounting/setup/review` | OMA | 302/404/404 | login |
| `GET /accounting/trial-balance` | OMABPV | 200/302/302 | login |
| `GET /accounts` | OMABPV | 200 | login |
| `POST /accounts` | OMAB | 422 | login |
| `DELETE /accounts/{account}` | OM | 404 | login |
| `PUT /accounts/{account}` | OMAB | 404 | login |
| `GET /accounts/{account}/delete` | OM | 404 | login |
| `GET /accounts/{account}/edit` | OMAB | 404 | login |
| `POST /accounts/{account}/opening-balance` | OM | 404 | login |
| `POST /api/ecosystem/membership` | none | login | login |
| `POST /api/integrations/emall/sales` | none | login | login |
| `POST /api/tax/payroll-import` | OMABPV | 503 | 503 |
| `GET /assistant` | OMABPV | 200 | login |
| `POST /assistant` | OMABPV | 422 | login |
| `GET /assistant/drafts/{draft}` | O | 404 | login |
| `POST /assistant/drafts/{draft}/confirm` | O | 404 | login |
| `POST /assistant/drafts/{draft}/dismiss` | O | 404 | login |
| `DELETE /assistant/messages` | OMABPV | 302 | login |
| `DELETE /assistant/messages/{message}` | O | 404 | login |
| `GET /attachments/{attachment}` | none | 404 | login |
| `POST /attention/scan` | OMAB | 302 | login |
| `POST /attention/{insight}/dismiss` | OMAB | 404 | login |
| `POST /attention/{insight}/snooze` | OMAB | 404 | login |
| `GET /audit` | OMA | 200 | login |
| `POST /auth/ecosystem` | none | login | login |
| `GET /bank/reconciliation` | OMABPV | 200 | login |
| `POST /bank/reconciliation` | OMAB | 422 | login |
| `GET /bank/reconciliation/{reconciliation}` | OMABPV | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/accept` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/complete` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/difference` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/entries` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/match` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/matches/{bankMatch}/release` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/reopen` | OMA | 404 | login |
| `GET /bank/reconciliation/{reconciliation}/report` | OMABPV | 404 | login |
| `GET /bank/reconciliation/{reconciliation}/report.csv` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/settle` | OMAB | 404 | login |
| `POST /bank/reconciliation/{reconciliation}/transfer` | OMAB | 404 | login |
| `GET /bank/rules` | OMABPV | 200 | login |
| `POST /bank/rules` | OMAB | 422 | login |
| `DELETE /bank/rules/{bankRule}` | OMAB | 404 | login |
| `PUT /bank/rules/{bankRule}` | OMAB | 404 | login |
| `GET /bank/rules/{bankRule}/edit` | OMAB | 404 | login |
| `GET /bank/statements` | OMABPV | 200 | login |
| `GET /bank/statements/import` | OMAB | 200 | login |
| `POST /bank/statements/import` | OMAB | 422 | login |
| `GET /bank/statements/import/{token}` | none | 404 | login |
| `POST /bank/statements/import/{token}` | none | 404 | login |
| `DELETE /bank/statements/{statement}` | OMAB | 404 | login |
| `GET /bank/statements/{statement}` | OMABPV | 404 | login |
| `GET /budgets` | OMABPV | 200 | login |
| `POST /budgets` | OMAB | 422 | login |
| `DELETE /budgets/{budget}` | OM | 404 | login |
| `PUT /budgets/{budget}` | OMAB | 404 | login |
| `GET /budgets/{budget}/edit` | OMAB | 404 | login |
| `GET /categories` | OMABPV | 200 | login |
| `POST /categories` | OMAB | 302 | login |
| `DELETE /categories/{category}` | OM | 404 | login |
| `PUT /categories/{category}` | OMAB | 404 | login |
| `GET /categories/{category}/edit` | OMAB | 404 | login |
| `GET /controls` | OMABPV | 200 | login |
| `GET /controls/access-review` | OMA | 200 | login |
| `POST /controls/access-review` | OM | 302 | login |
| `GET /controls/access-review/export` | OMA | 200 | login |
| `GET /controls/approvals` | OMABPV | 200 | login |
| `GET /controls/limits` | OMA | 200 | login |
| `POST /controls/limits` | OM | 422 | login |
| `GET /controls/members` | OMA | 200 | login |
| `GET /controls/policies` | OMA | 200 | login |
| `POST /controls/policies` | OM | 422 | login |
| `POST /controls/policies/preset` | OM | 422 | login |
| `POST /csp-report` | OMABPV | 200 | 200 |
| `GET /dashboard` | OMABPV | 200 | login |
| `GET /forecast` | OMABPV | 200 | login |
| `GET /insights` | OMABPV | 200 | login |
| `GET /integrations` | OMABPV | 200 | login |
| `GET /integrations/emall` | OMABPV | 200 | login |
| `POST /integrations/emall/{line}/post` | OMA | 404 | login |
| `POST /integrations/emall/{line}/reject` | OMA | 404 | login |
| `POST /ledger/context` | OMABPV | 302 | login |
| `GET /notifications` | OMABPV | 200 | login |
| `GET /notifications/preferences` | OMABPV | 200 | login |
| `PUT /notifications/preferences` | OMABPV | 302 | login |
| `POST /notifications/read-all` | OMABPV | 302 | login |
| `POST /notifications/{notification}/read` | O | 404 | login |
| `GET /parties` | OMABPV | 200 | login |
| `POST /parties` | OMAB | 422 | login |
| `GET /parties/create` | OMAB | 200 | login |
| `DELETE /parties/{party}` | OMAB | 404 | login |
| `GET /parties/{party}` | OMABPV | 404 | login |
| `PUT /parties/{party}` | OMAB | 404 | login |
| `POST /parties/{party}/attachments` | OMAB | 404 | login |
| `GET /parties/{party}/edit` | OMAB | 404 | login |
| `POST /parties/{party}/restore` | OMAB | 404 | login |
| `GET /planning/afford` | OMABPV | 200 | login |
| `GET /planning/budgets` | OMABPV | 200 | login |
| `POST /planning/budgets` | OMAB | 422/404/404 | login |
| `GET /planning/budgets/create` | OMAB | 200 | login |
| `GET /planning/budgets/{planBudget}` | OMABPV | 404 | login |
| `PUT /planning/budgets/{planBudget}` | OMAB | 404 | login |
| `GET /planning/budgets/{planBudget}/edit` | OMAB | 404 | login |
| `POST /planning/budgets/{planBudget}/reforecast` | OMAB | 404 | login |
| `POST /planning/budgets/{planBudget}/submit` | OM | 404 | login |
| `POST /planning/budgets/{planBudget}/version` | OMAB | 404 | login |
| `GET /planning/cash` | OMABPV | 200 | login |
| `GET /planning/dimensions` | OMABPV | 200 | login |
| `POST /planning/dimensions` | OMAB | 422 | login |
| `PUT /planning/dimensions/{dimension}` | OMAB | 404 | login |
| `GET /planning/forecasts` | OMABPV | 200 | login |
| `GET /planning/scenarios` | OMABPV | 200 | login |
| `POST /planning/scenarios` | OMAB | 422 | login |
| `GET /planning/scenarios/create` | OMAB | 200 | login |
| `DELETE /planning/scenarios/{scenario}` | OMAB | 404 | login |
| `GET /planning/scenarios/{scenario}` | OMABPV | 404 | login |
| `PUT /planning/scenarios/{scenario}` | OMAB | 404 | login |
| `GET /planning/scenarios/{scenario}/edit` | OMAB | 404 | login |
| `GET /purchases` | OMABPV | 200 | login |
| `GET /purchases/ap` | OMABPV | 200 | login |
| `POST /purchases/bank-changes/{detail}/approve` | OMP | 404 | login |
| `POST /purchases/bank-changes/{detail}/reject` | OMP | 404 | login |
| `GET /purchases/bills` | OMABPV | 200 | login |
| `POST /purchases/bills` | OMAB | 422 | login |
| `GET /purchases/bills/create` | OMAB | 200 | login |
| `DELETE /purchases/bills/{bill}` | OMAB | 404 | login |
| `GET /purchases/bills/{bill}` | OMABPV | 404 | login |
| `PUT /purchases/bills/{bill}` | OMAB | 404 | login |
| `POST /purchases/bills/{bill}/apply-credit` | OMAB | 404 | login |
| `POST /purchases/bills/{bill}/attachments` | OMAB | 404 | login |
| `GET /purchases/bills/{bill}/credit-note` | none | 404 | login |
| `POST /purchases/bills/{bill}/credit-note` | none | 404 | login |
| `GET /purchases/bills/{bill}/edit` | OMAB | 404 | login |
| `POST /purchases/bills/{bill}/post` | OMAB | 404 | login |
| `POST /purchases/bills/{bill}/submit` | OMAB | 404 | login |
| `POST /purchases/bills/{bill}/void` | OMP | 404 | login |
| `POST /purchases/bills/{bill}/withdraw` | OMAB | 404 | login |
| `GET /purchases/claims` | OMABPV | 200 | login |
| `POST /purchases/claims` | OMAB | 422 | login |
| `GET /purchases/claims/create` | OMAB | 200 | login |
| `DELETE /purchases/claims/{claim}` | OMAB | 404 | login |
| `GET /purchases/claims/{claim}` | OMABPV | 404 | login |
| `PUT /purchases/claims/{claim}` | OMAB | 404 | login |
| `GET /purchases/claims/{claim}/edit` | OMAB | 404 | login |
| `POST /purchases/claims/{claim}/lines/{claimLine}/attachments` | OMAB | 404 | login |
| `POST /purchases/claims/{claim}/post` | OMAB | 404 | login |
| `POST /purchases/claims/{claim}/submit` | OMAB | 404 | login |
| `POST /purchases/claims/{claim}/void` | OMP | 404 | login |
| `POST /purchases/claims/{claim}/withdraw` | OMAB | 404 | login |
| `GET /purchases/documents` | OMABPV | 200 | login |
| `POST /purchases/documents/transactions/{transaction}` | OMAB | 404 | login |
| `GET /purchases/payments` | OMABPV | 200 | login |
| `POST /purchases/payments` | OMAB | 422 | login |
| `GET /purchases/payments/create` | OMAB | 200 | login |
| `GET /purchases/payments/{payment}` | OMABPV | 404 | login |
| `POST /purchases/payments/{payment}/apply` | OMAB | 404 | login |
| `POST /purchases/payments/{payment}/reverse` | OMAB | 404 | login |
| `GET /purchases/receipts` | OMAB | 200 | login |
| `POST /purchases/receipts` | OMAB | 302 | login |
| `GET /purchases/receipts/{attachment}` | OMABPV | 404 | login |
| `POST /purchases/receipts/{attachment}/bill` | OMAB | 404 | login |
| `POST /purchases/receipts/{attachment}/claim` | OMAB | 404 | login |
| `POST /purchases/receipts/{attachment}/read` | OMAB | 404 | login |
| `POST /purchases/receipts/{attachment}/reject` | OMAB | 404 | login |
| `GET /purchases/recurring` | OMABPV | 200 | login |
| `POST /purchases/recurring` | OMAB | 422 | login |
| `GET /purchases/recurring/create` | OMAB | 200 | login |
| `POST /purchases/recurring/{recurring}/end` | OMAB | 404 | login |
| `POST /purchases/recurring/{recurring}/pause` | OMAB | 404 | login |
| `POST /purchases/recurring/{recurring}/resume` | OMAB | 404 | login |
| `GET /purchases/runs` | OMABPV | 200 | login |
| `POST /purchases/runs` | OMAB | 422 | login |
| `GET /purchases/runs/create` | OMAB | 200 | login |
| `GET /purchases/runs/{run}` | OMABPV | 404 | login |
| `POST /purchases/runs/{run}/cancel` | OMAB | 404 | login |
| `GET /purchases/runs/{run}/csv` | OMAB | 404 | login |
| `POST /purchases/runs/{run}/execute` | OMAB | 404 | login |
| `POST /purchases/runs/{run}/submit` | OMAB | 404 | login |
| `GET /purchases/settings` | OMABPV | 200 | login |
| `PUT /purchases/settings` | OMA | 422 | login |
| `GET /purchases/suppliers` | OMABPV | 200 | login |
| `POST /purchases/suppliers` | OMAB | 302 | login |
| `GET /purchases/suppliers/create` | OMAB | 200 | login |
| `GET /purchases/suppliers/{party}` | OMABPV | 404 | login |
| `PUT /purchases/suppliers/{party}` | OMAB | 404 | login |
| `GET /purchases/suppliers/{party}/bank` | OMAB | 404 | login |
| `POST /purchases/suppliers/{party}/bank` | OMAB | 404 | login |
| `GET /purchases/suppliers/{party}/edit` | OMAB | 404 | login |
| `GET /purchases/suppliers/{party}/statement` | OMABPV | 404 | login |
| `GET /reconciliation` | OMABPV | 200 | login |
| `GET /recurring` | OMABPV | 200 | login |
| `PUT /recurring/{rule}` | OMAB | 404 | login |
| `GET /recurring/{rule}/edit` | OMAB | 404 | login |
| `POST /recurring/{rule}/pause` | OMAB | 404 | login |
| `POST /recurring/{rule}/resume` | OMAB | 404 | login |
| `POST /recurring/{rule}/stop` | OMAB | 404 | login |
| `GET /reports` | OMABPV | 200 | login |
| `GET /reports/ageing-payables` | OMABPV | 200 | login |
| `GET /reports/ageing-receivables` | OMABPV | 200 | login |
| `GET /reports/balance-sheet` | OMABPV | 200 | login |
| `GET /reports/budget-vs-actual` | OMABPV | 200 | login |
| `GET /reports/cash-flow` | OMABPV | 200 | login |
| `GET /reports/cash-flow-statement` | OMABPV | 200 | login |
| `GET /reports/drill` | OMABPV | 200 | login |
| `GET /reports/expenses` | OMABPV | 200 | login |
| `GET /reports/exports` | OMABPV | 200 | login |
| `POST /reports/exports` | OMAB | 422 | login |
| `GET /reports/exports/{reportExport}` | OMAB | 404 | login |
| `GET /reports/exports/{reportExport}/download` | none | 404 | login |
| `GET /reports/general-ledger` | OMABPV | 200 | login |
| `GET /reports/performance` | OMABPV | 200 | login |
| `POST /reports/presets` | OMABPV | 422 | login |
| `DELETE /reports/presets/{reportPreset}` | OM | 404 | login |
| `GET /reports/profit-loss` | OMABPV | 200 | login |
| `GET /reports/revenue` | OMABPV | 200 | login |
| `GET /reports/tax-summary` | OMABPV | 200 | login |
| `GET /reports/trial-balance` | OMABPV | 200 | login |
| `GET /reports/vat` | OMABPV | 200 | login |
| `GET /s/{shareToken}` | none | 404 | 404 |
| `GET /s/{shareToken}/pdf` | none | 404 | 404 |
| `POST /sales/applications/{application}/release` | OMAB | 404 | login |
| `POST /sales/calculate` | OMAB | 200 | login |
| `GET /sales/credit-notes` | OMABPV | 200 | login |
| `GET /sales/customers` | OMABPV | 200 | login |
| `GET /sales/customers/{party}` | OMABPV | 404 | login |
| `POST /sales/customers/{party}/link-transaction` | OMAB | 404 | login |
| `POST /sales/customers/{party}/reminders` | OMAB | 404 | login |
| `GET /sales/customers/{party}/statement` | OMABPV | 404 | login |
| `GET /sales/customers/{party}/statement.csv` | OMABPV | 404 | login |
| `GET /sales/customers/{party}/statement.pdf` | OMABPV | 404 | login |
| `POST /sales/customers/{party}/statement/share` | OMAB | 404 | login |
| `POST /sales/documents` | OMAB | 422 | login |
| `DELETE /sales/documents/{document}` | OMAB | 404 | login |
| `GET /sales/documents/{document}` | OMABPV | 404 | login |
| `PUT /sales/documents/{document}` | OMAB | 404 | login |
| `POST /sales/documents/{document}/answer` | OMAB | 404 | login |
| `POST /sales/documents/{document}/apply-credit` | OMAB | 404 | login |
| `POST /sales/documents/{document}/convert` | OMAB | 404 | login |
| `POST /sales/documents/{document}/credit-note` | OMAB | 404 | login |
| `POST /sales/documents/{document}/debit-note` | OMAB | 404 | login |
| `GET /sales/documents/{document}/edit` | OMAB | 404 | login |
| `POST /sales/documents/{document}/issue` | OMAB | 404 | login |
| `GET /sales/documents/{document}/pdf` | OMABPV | 404 | login |
| `GET /sales/documents/{document}/print` | OMABPV | 404 | login |
| `POST /sales/documents/{document}/send` | OMAB | 404 | login |
| `POST /sales/documents/{document}/share` | OMAB | 404 | login |
| `POST /sales/documents/{document}/void` | OMAB | 404 | login |
| `POST /sales/documents/{document}/write-off` | OMABP | 404 | login |
| `GET /sales/invoices` | OMABPV | 200 | login |
| `GET /sales/invoices/create` | OMAB | 200 | login |
| `POST /sales/links/{link}/revoke` | OMAB | 404 | login |
| `GET /sales/payments` | OMABPV | 200 | login |
| `POST /sales/payments` | OMAB | 422 | login |
| `GET /sales/payments/create` | OMAB | 200 | login |
| `GET /sales/payments/{payment}` | OMABPV | 404 | login |
| `POST /sales/payments/{payment}/allocate` | OMAB | 404 | login |
| `POST /sales/payments/{payment}/reverse` | OMAB | 404 | login |
| `GET /sales/profile` | OMA | 200 | login |
| `PUT /sales/profile` | OMA | 422 | login |
| `GET /sales/profile/logo` | none | 404 | login |
| `POST /sales/profile/logo` | OMA | 422 | login |
| `GET /sales/quotes` | OMABPV | 200 | login |
| `GET /sales/quotes/create` | OMAB | 200 | login |
| `GET /sales/receivables` | OMABPV | 200 | login |
| `GET /sales/receivables.csv` | OMAB | 200 | login |
| `GET /sales/recurring` | OMAB | 200 | login |
| `POST /sales/recurring` | OMAB | 422 | login |
| `GET /sales/recurring/create` | OMAB | 200 | login |
| `POST /sales/recurring/run` | OMAB | 302 | login |
| `PUT /sales/recurring/{template}` | OMAB | 404 | login |
| `GET /sales/recurring/{template}/edit` | OMAB | 404 | login |
| `POST /sales/recurring/{template}/status` | OMAB | 404 | login |
| `POST /sales/refunds` | OMP | 422 | login |
| `GET /sales/reminders` | OMABPV | 200 | login |
| `GET /tax` | OMABPV | 200 | login |
| `GET /tax/accountant-pack` | OMABPV | 200 | login |
| `POST /tax/accountant-pack` | OMAB | 422 | login |
| `GET /tax/calendar` | OMABPV | 302 | login |
| `POST /tax/calendar/generate` | OMA | 302 | login |
| `GET /tax/calendar/{taxDeadline}` | OMABPV | 404 | login |
| `POST /tax/calendar/{taxDeadline}/tick` | OMA | 404 | login |
| `POST /tax/calendar/{taxDeadline}/waive` | OMA | 404 | login |
| `POST /tax/correspondence` | OMA | 422 | login |
| `POST /tax/correspondence/{taxCorrespondence}/status` | OMA | 404 | login |
| `GET /tax/documents` | OMABPV | 302 | login |
| `POST /tax/documents` | OMA | 422 | login |
| `POST /tax/documents/{taxDocument}/archive` | OMA | 404 | login |
| `GET /tax/documents/{taxDocument}/file` | none | 404 | login |
| `POST /tax/documents/{taxDocument}/hold` | OMA | 404 | login |
| `GET /tax/emp201` | OMABPV | 302 | login |
| `POST /tax/emp201/start` | OMA | 302 | login |
| `GET /tax/explain` | OMABPV | 302 | login |
| `GET /tax/income` | OMABPV | 302 | login |
| `POST /tax/income/adjustments` | OMA | 302 | login |
| `POST /tax/income/adjustments/{taxAdjustment}/void` | OMA | 404 | login |
| `POST /tax/income/estimates` | OMA | 302 | login |
| `POST /tax/income/provision` | OMA | 302 | login |
| `GET /tax/income/workpaper` | none | 404 | login |
| `GET /tax/income/workpaper.csv` | none | 404 | login |
| `GET /tax/obligations` | OMABPV | 302 | login |
| `GET /tax/payroll` | OMABPV | 302 | login |
| `POST /tax/payroll` | OMA | 422 | login |
| `GET /tax/payroll/{taxPayrollRun}` | OMABPV | 404 | login |
| `POST /tax/payroll/{taxPayrollRun}/post` | OMA | 404 | login |
| `GET /tax/profile` | OMABPV | 200 | login |
| `PUT /tax/profile` | OMA | 422 | login |
| `GET /tax/provisional` | OMABPV | 302 | login |
| `POST /tax/provisional/estimate` | OMA | 302 | login |
| `POST /tax/provisional/{provisionalPayment}/payment` | OMA | 404 | login |
| `GET /tax/returns/{taxReturn}` | OMABPV | 404 | login |
| `POST /tax/returns/{taxReturn}/adjustments` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/adjustments/{returnAdjustment}/void` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/payment` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/prepare` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/revision` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/settlement` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/submission` | OMA | 404 | login |
| `POST /tax/returns/{taxReturn}/withdraw` | OMA | 404 | login |
| `GET /tax/returns/{taxReturn}/workpaper` | OMAB | 404 | login |
| `GET /tax/returns/{taxReturn}/workpaper.csv` | OMAB | 404 | login |
| `GET /tax/vat` | OMABPV | 302 | login |
| `GET /tax/vat/review` | OMABPV | 302 | login |
| `POST /tax/vat/review/resolve` | OMA | 422 | login |
| `POST /tax/vat/start` | OMA | 302 | login |
| `GET /transactions` | OMABPV | 200 | login |
| `POST /transactions` | OMAB | 422 | login |
| `GET /transactions/create` | OMAB | 200 | login |
| `GET /transactions/import` | OMAB | 200 | login |
| `POST /transactions/import` | OMAB | 422 | login |
| `POST /transactions/import/upload` | OMAB | 422 | login |
| `GET /transactions/import/{token}` | OMAB | 302 | login |
| `DELETE /transactions/{transaction}` | OMAB | 404 | login |
| `PUT /transactions/{transaction}` | OMAB | 404 | login |
| `GET /transactions/{transaction}/edit` | OMAB | 404 | login |
| `POST /transfers` | OMAB | 422 | login |
| `GET /transfers/create` | OMAB | 200 | login |


## 5. Test inventory (all run)

| File | Tests | What it attacks |
|---|---|---|
| `AuthorisationMatrixTest` | 1 (about 3,570 requests) | the matrix above |
| `BodyIdSmugglingTest` | 3 | 21 money forms (sales payment, invoice, refund, journal, supplier payment, bill, payment run, plan cell, spend limit, role, transaction, link) with the ids of 3 foreign ledgers in the body; controls with the person's own ids prove the bodies are otherwise valid; the live preview ignores a foreign document id |
| `MassAssignmentSweepTest` | 2 | every write route with `user_id`, `team_id`, `id`, `status`, `number`, `posted_at`, `voided_at`, `gl_entry_id`, `created_by`, `path`, `disk`, `paid_cents`, `total_cents`, `books_closed_through`... added; afterwards no row anywhere changed owner, every created row is the caller's company's, no other company changed; plus no controller hands `$request->all()` to a model |
| `XssAndCsvSweepTest` | 4 | a company whose free-text fields (account, category, party, memo, reference, preset, scenario, plan, tax correspondence, assistant question, bank narrative, upload file name, company name, person name) hold live markup; the owner opens every GET page; CSV/OFX/QIF statements with hostile narratives; every CSV download |
| `MailTemplatesTest` | 3 | invoice, reminder and notification e-mails with markup and `javascript:` links |
| `UploadsSecurityTest` | 23 | 13 hostile files posing as pdf/png/docx/xlsx, server-made storage names for 5 hostile file names, the 15 MB limit, logo type from the bytes, signed / expiring / tampered / cross-ledger attachment streaming, PDF bomb, stream flood, 18 + 180 malformed PDFs, garbage OFX/QIF/CSV |
| `InboundEndpointsSecurityTest` | 11 | membership, Emall, Dot.HR payroll: forged / stale / future / zero / garbage signatures all the same 401, empty-secret signatures, replay, oversize, "who exists" answers, throttles, tenant mapping |
| `AuthAndSessionSecurityTest` | 11 | bearer tokens against the web app, cookie flags over HTTPS, a new session id at sign-in, one-use handoff, 14 hostile redirect targets, hostile origins, login throttle and enumeration, reset enumeration, reset-token single use, no secrets in logs |
| `ShareLinkAndEnumerationTest` | 5 | public links: unknown / malformed / expired / revoked look identical, no cookie, token never stored, per-address wall, a link shows only its document |
| `MoneyIntegrityAttackTest` | 29 | 21 hostile amounts, leading zeros, the largest amount, overflow, browser-sent totals ignored, key reuse (payment, refund), double void, double reversal, a closed period through 4 forms |
| `MoneyIntegrityPurchasesTest` | 5 | hostile bill quantities and prices, key reuse (supplier payment, payment run), void twice, pay a voided bill |
| `ApprovalAttackTest` | 5 | approve your own request, approve without the capability, decide twice, post around the gate, edit after approval |
| `ExportAndDataControlsTest` | 5 | who may export 7 CSVs, an audit row for each, payroll detail only with `view-payroll-detail` (page and accountant pack), a bank account number never in audit rows, notifications, insights, logs or the pages of readers |
| `PublicSurfaceTest` | 14 | CSP sink junk, the framework file route and Livewire upload without a signature, a member removed mid-session, headers on error pages, no stack trace |
| `NumericIdRoutesTest` | 2 | 12 hostile ids on every id route, clean 404 (a savepoint per request so PostgreSQL cannot hide a swallowed error) |

Helpers: `tests/Concerns/RunsRouteProbes.php` (routes, savepoints, table hashes, response classes) and `tests/Concerns/SeedsSentinelLedgers.php` (a copy of the isolation test's seeder plus a receipt and a finished export; the original test is untouched).

## 6. Reviewed and found sound (no change)

- Route-model binding is ledger-scoped everywhere (no `withoutGlobalScopes` in application code), and the raw `DB::table` services filter by owner or by ids the caller's scoped models already proved.
- No controller passes `$request->all()` into a model; owner columns are added by the server after validation (proved behaviourally by the mass-assignment sweep).
- Money parsing (`Money::parse`, `MoneyAmount`) accepts only plain decimals.
- Attachments: type from the bytes with a short allow-list (no svg, html, php), a server-made path, signed 10-minute links, ledger and record policy, served as a download inside a CSP `sandbox` with `nosniff` and `no-store`.
- Inbound signatures use `hash_equals`, always compute the HMAC, give one generic 401 whatever was wrong, and cover the timestamp; the company mapping is consulted only after authentication.
- The SSO handoff takes the token in the POST body only, checks Origin or Referer, regenerates the session, honours only same-site paths, and is one-use.
- Blade: the only `{!! !!}` outputs are escaped fragments (`e()`), constants and the 2FA QR code; `x-data` carries only `json_encode`d constants.
- Dompdf runs with remote content off and a chroot.
- Approvals: separation of duties, the capability, a single decision, a stale amount, and the post action cannot skip the gate.

## 7. Run record

SQLite (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`): full suite, 2,035 tests, 2,022 passed, 13 skipped, 0 failed, 286,037 assertions (the run after the last code change; `MailTemplatesTest`, 3 tests, was added after it and passes alone).

PostgreSQL (throwaway database `qa_sec_r44`, clean `env -i` shell, `phpunit.pgsql.xml`, dropped afterwards): full suite, **2,035 tests, 286,078 assertions, 0 failures, 10 skipped** (51 minutes on a busy machine); `MailTemplatesTest` (3 tests) added after that run and passes alone on PostgreSQL. The skip counts differ (13 on SQLite, 10 on PostgreSQL) because some tests are engine-specific; I did not list them.

## 8. Not tested, and owner steps

- **Not tested:** two-process concurrency of the idempotency fixes; two-factor sign-in and remember-me; whether the forms (not just the pages) accepted a bearer token before the fix; a real browser executing a payload (the sweep inspects the HTML; the CSP allows no inline script without a nonce); the content of generated PDFs and XLSX files for injected markup (the CSV side is tested; the XLSX writer writes inline strings with XML escaping and never a formula, by reading it); the outbound Notify and Analytics signing beyond the existing tests; the production host (cookie flags, `--no-dev`, `INFODOT_SERVICE_SECRET`, HSTS).
- **Owner steps:** confirm on the host that `INFODOT_SERVICE_SECRET` is set (the membership webhook is off without it); confirm `SESSION_SECURE_COOKIE` is not set to false in the host `.env`; decide whether the approver should void, write off and refund (section 3); one `curl -I https://finance.infodot.co.za/login` shows the cookie flags and the CSP.
- **Merge notes:** `AuditController::SUBJECTS` gained `'export'` (one line); `whereNumber` became `where(..., '[0-9]{1,18}')` in 57 places in `routes/*.php`; `bootstrap/app.php` gained one `web(prepend: ...)`. `package-lock.json` changed (about 400 lines) by `npm audit fix`.
