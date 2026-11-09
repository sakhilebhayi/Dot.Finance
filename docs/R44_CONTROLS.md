# R-44 F3: Controls (roles, approvals, limits, access review)

Branch `feat/r44-controls`. Decisions D-300..D-317 are in `R44_DECISIONS.md`.

## What was built

**Roles and capabilities**
- `App\Enums\Capability` (24 specified + 3 legacy structure capabilities, also registered as Gates by `ControlsServiceProvider`), `App\Enums\FinanceRole` (owner, finance_manager, accountant, bookkeeper, approver, viewer) with the capability matrix and the InfoDot ceiling (`grantable()`).
- `LedgerAccess` has a clearly marked block near the top (`R44 F3 CONTROLS`): new constructor, `for()`, `can(Capability, ?User)`, `allows()`, `financeRole()`, `baselineRole()`, `hasLocalRole()`. The legacy `canRecordEntries / canEditStructure / canDestroyStructure / canAdjustOpeningBalance / canViewAudit / role() / roleLabel()` keep their behaviour (the 564 original tests are green) and now ask `can()`. The end of the class is untouched for F1's three appended methods.
- Table `ledger_member_roles`, model `LedgerMemberRole`, service `LedgerRoles` (members, assign, reconcile, memberRemoved). The InfoDot sync (`SyncTeamsFromInfoDot`) calls `reconcile` after a role change and `memberRemoved` after a detach (two added lines).

**Approvals**
- Tables `approval_policies`, `approval_requests`, `approval_decisions`, `spend_limits`; models in `App\Models\Controls`.
- `ApprovalService` (`request, approve, reject, cancel, eligibility, waitingFor, isApproved, latestFor, cancelPendingBy`, static `policyFor` and `requiresApproval`), `ApprovalPolicies` (+ `ApprovalPresets`: seven one-click presets, e.g. "Owner approves bills over ZAR 5,000.00"), `SpendLimits`.
- Contract for other modules: `App\Contracts\Approvable` (documented in the file) + `App\Events\ApprovalDecided`.
- Default is OFF: no policy rows, nothing needs approval.

**Screens** (plain semantic HTML, `resources/views/controls`, routes in `routes/controls.php`, block marked in `routes/web.php`): `/controls` overview, `/controls/members` (members and roles, only offers roles inside the ceiling, capability matrix), `/controls/policies` (presets + custom), `/controls/approvals` (waiting for me / my requests / history), `/controls/limits`, `/controls/access-review` (who has what, last session activity, last action here, role-change history, confirm with notes, earlier reviews, CSV export, formula-safe).

**Audit**: `FinanceAudit::SUBJECTS` extended (member_role, approval_policy, approval_request, spend_limit, access_review); the audit page's filter list includes them.

## How another module uses it

```php
if (ApprovalService::requiresApproval('bill', $baseCurrencyCents, $baseCurrency)) {
    app(ApprovalService::class)->request($user, $bill);   // $bill implements Approvable
    // leave the bill "awaiting approval"; onApprovalDecided() moves it on
}
// before issuing / posting / paying:
abort_unless(app(ApprovalService::class)->isApproved($bill), 409);
```

## Tests (all run)

- `tests/Feature/Controls`: 88 tests, 469+ assertions: `CapabilityMatrixTest` (27 capabilities x 6 roles against a written-out table, via real members of a real company; legacy methods; gates), `LedgerRolesTest` (ceiling, peers, owner, cross-ledger, idempotent grant, DB CHECK/unique), `InfoDotSyncRolesTest` (sync never escalates, lowers revoke, removal revokes), `ApprovalServiceTest` (default off, strict threshold, currency fail-closed, SoD, single member, capability, min role, limits, stale amount, steps, reject/cancel, race and idempotency, removed/demoted approver, cross-ledger, audit, evidence immutability), `ControlsRoutesTest` (every page x every role, writes, validation, cross-ledger 404 for every `{id}` route, CSV).
- SQLite full suite: **652 tests, 645 passed, 7 skipped, 0 failed** (564 original + 88 new).
- PostgreSQL (throwaway DB, clean env): **652 tests, 1 failure, 8 skipped**. All Controls tests pass on PostgreSQL. The one failure is `InfoDotRedeemTest::test_an_unknown_token_is_a_clean_403_not_a_server_error` (PG-only `invalid input syntax for type bigint: ""` from `EcosystemAuthController::userFromLocalToken` -> `PersonalAccessToken::findToken('')`): pre-existing, outside Controls, not fixed here. I did not run it on the trunk branch to prove that.
- `php artisan route:cache && route:clear` runs; `view:cache` compiles; Pint clean on touched files.

## Not done / not verified

- **Real concurrency**: the two-approver race is tested by two stale copies of one request and by the UNIQUE constraints; I did not run two OS processes against PostgreSQL to watch the `lockForUpdate` serialise them.
- **Visual check**: the screens were never opened in a browser and not screenshot. Skills invoked via the Skill tool: `impeccable`, `design-taste-frontend` (loaded; I did not run their `context` / `draw` / `inspect` scripts or craft-floor pass because the brief asked for unstyled semantic HTML for the UI agent to restyle). `hallmark`, `design-md`, `ui-ux-pro-max`, `emil-design-eng` were not invoked. The views use existing classes (`dot-card`, `dot-btn`, `dot-input`, `page-pad`), no inline styles, no scripts, no `@vite`. No empty states beyond sentences, no responsive pass.
- **Navigation**: no link to `/controls` exists in the app layout (layout views are out of scope). The UI agent should add the entry (`route('controls.index')`, shown to everyone; the pages gate themselves) and restyle `resources/views/controls/*`. The views carry classes `controls-*` as styling hooks.
- Nothing in Sales/Purchases/Bank/Planning implements `Approvable` yet; the engine is tested with a stand-in model (`tests/Support/ControlsBill`). Policies exist for subject kinds the other modules will wire.
- Notifications to approvers (e-mail / Dot.Notify) are not sent; the inbox is the only surface. The `ApprovalDecided` event is the hook.
- Jetstream's own add-member / role screens remain and can still attach a person with a pivot role (audit R10); the ceiling logic makes that safe for finance roles, but that path itself is unchanged.
- `Team::LEDGER_TABLES` (the "has company data" list) was not extended with the six new tables, to avoid a merge conflict with the other agents; the FKs are `restrictOnDelete` so deleting a company that has them is still refused by the database. Add them at integration.
- Personal-ledger policies and approvals work (self-approval preset) but personal ledgers have no members page content beyond the owner.

## Integration notes

1. F1's `canPost / canManageChart / canClosePeriod` must delegate to `can()` (D-305).
2. `LedgerScopeGuardTest` expects 13 ledger models (7 + 6 here): sum with the other modules' counts.
3. `routes/web.php` has the `// --- controls ---` block before the register redirect.
4. Migration `2026_11_09_300000_create_controls_tables` creates all six tables (CHECKs on PostgreSQL, triggers on SQLite).
5. `FinanceAudit::record` gained an optional trailing `actorUserId` parameter (backwards compatible).
