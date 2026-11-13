# Dot.Finance integrations: what is real

Every row was decided by reading the other platform's code on `origin/main` (the local checkouts under `~/Dot` are weeks behind), not by assuming. The same list is shown to signed-in people at `/integrations` (`App\Services\Integrations\IntegrationStatus`); a test keeps this file and that page in step.

Statuses: **connected** means a real signed channel exists on both sides and this side is live in code (it is inert until its settings are present on a server); **contract only** means this side is built and tested but the other platform has nothing that calls or answers it yet, so nothing flows; **not possible** means the other platform exposes nothing to build against, or there is no sound use for one.

| Integration | Status | Contract | Owner step |
|---|---|---|---|
| InfoDot sign-in and companies | connected | InfoDot `POST /api/ecosystem/redeem` with `INFODOT_SERVICE_SECRET`; roles and companies synced at each sign-in (`SyncTeamsFromInfoDot`) | Set `INFODOT_SERVICE_SECRET` to the value of `ECOSYSTEM_SECRET_FINANCE` in InfoDot |
| InfoDot membership changes | connected | InfoDot signs `POST /api/ecosystem/membership` (`member.removed`, `member.role_changed`, `team.deleted`), same as Dot.Files and Dot.docs | Add `finance` to `ECOSYSTEM_MEMBERSHIP_PLATFORMS` in InfoDot (it sends to `files,docs` by default) |
| Dot.Notify | connected | Notify's inbound webhook `POST /webhooks/{token}`, `X-Dot-Signature` = HMAC-SHA256 of the raw body with the webhook's signing secret | Create the webhook, an event map entry per `finance.*` event and a rule with a channel and template in Dot.Notify; set `NOTIFY_WEBHOOK_URL` and `NOTIFY_WEBHOOK_SECRET` |
| Dot.Analytics | contract only | Daily snapshot to `POST /api/ingest/snapshots`, signed `X-InfoDot-Timestamp` / `X-InfoDot-Signature`; see `analytics-finance-metrics.md` | Add the `finance` platform and the metric keys to Analytics' catalogue and set `ANALYTICS_INGEST_SECRET_FINANCE` there; then set `ANALYTICS_INGEST_URL` and `ANALYTICS_INGEST_SECRET` here |
| Dot.Emall | contract only | `POST /api/integrations/emall/sales`, signed; see `emall-sales-contract.md` | Build the sender in Dot.Emall, then set `FINANCE_EMALL_SECRET` on both sides |
| Dot.HR payroll | contract only | Payroll summary totals; see `hr-payroll-contract.md` | Dot.HR must build payroll first; the Tax module's `PayrollImport` then implements `PayrollSummarySink` |
| Dot.Projects | not possible | none | None until Dot.Projects publishes a project list endpoint |
| Dot.Files | not possible | none (Files has signed membership and search endpoints only) | None until Dot.Files offers a storage API |
| Dot.Engage | not possible | none | None |
| Dot.Agents | not possible | none, deliberately | None |
| Dot.Brain and Dot.Memory | not possible | none agreed | Decide which aggregate events, if any, should be recorded there |

## The evidence, per platform

**InfoDot.** The redeem call and the team sync already existed. Verified and hardened here: the hand-off token is never read from a URL, never echoed and never logged; an id part that is not a number, or does not fit a bigint, is a clean miss (not a database error on PostgreSQL); removed members are detached at the next sign-in *and now at once* through the signed membership webhook, which also drops their local role grant, spending limits and pending approval requests. `team.deleted` detaches everyone except the local owner and keeps the link and the books (company records must outlive a deleted company: decision D-1010). InfoDot's `MembershipWebhook::send` builds the URL from `ecosystem.platforms[finance].url`, so the platform key `finance` must be in its `membership_platforms` list.

**Dot.Notify.** `WebhookInboundController` (read on `origin/main`) authenticates the caller by the endpoint token in the URL plus the HMAC of the raw body, maps the body's `event` through the webhook's `event_map` to a trigger event, finds an active `NotifyRule` for it, and creates a `NotifyLog` addressed to `recipient`. Its README still lists the receiver as "not implemented", but the controller and route exist in code. I could not call a live Notify, so "connected" means the client signs exactly as the receiver verifies, proven against a fake receiver, not that a message was delivered. Events sent: `finance.approval_waiting`, `finance.tax_deadline`, `finance.overdue_summary`, `finance.insight`, `finance.weekly_digest`, `finance.payment_reminder`, `finance.system`, with the flat fields `title`, `body`, `url`, `ledger`, `severity` available to templates. A person must opt in per kind (default off).

**Dot.Analytics.** The publishers in Dot.Pulse and Dot.Auction (on `origin/main`; the local checkouts do not have them) sign `"{timestamp}.{raw body}"` with HMAC-SHA256 and post one snapshot per company per day to `/api/ingest/snapshots`. This app copies that shape (`AnalyticsClient`, `SendAnalyticsSnapshot`, `SnapshotPublisher`, `finance:publish-metrics`). Analytics' catalogue (`config/metrics.php`) lists eleven platforms and **not** `finance`, so it answers a generic 401 until a platform entry is added there: that is why this row says contract only. Only aggregates are sent, and only for company ledgers.

**Dot.Emall.** `origin/main` has orders and order lines with `seller_id`, `total`, a promo discount and a currency, and a seller dashboard that says seller payouts do not exist. It has no commission or fee model, no VAT, no export and no API (`routes/api.php` is the stock `/user` route). The contract therefore describes what Emall would have to send; this side recomputes and stages it.

**Dot.HR.** No payroll, payslip or salary tables and no API on `origin/main`.

**Dot.Projects.** No API. **Dot.Files.** `POST /api/ecosystem/membership` and `POST /api/ecosystem/search` only. **Dot.Engage.** Sanctum-token endpoints for signatures, contract PDFs and video tokens. **Dot.Agents.** A Sanctum API for deployments and skill execution with its own approvals: connecting it would give an outside actor a way around this app's controls. **Dot.Memory / Dot.Brain.** Token-authenticated event and DKP ingestion; no finance event has been agreed.

## Security rules every inbound call follows

Signature over the timestamp and the raw body, five minutes of tolerance, the HMAC computed even when the secret or header is missing, one generic 401 for every refusal, authentication before the company is looked up, a rate limit, a body size cap for Emall, the sender's business mapped to a ledger only through InfoDot's company uuid (`teams.infodot_team_uuid`), idempotency by delivery id and by order item id, and amounts recomputed wherever they can be.
