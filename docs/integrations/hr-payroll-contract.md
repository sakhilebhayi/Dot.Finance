# Dot.HR to Dot.Finance: payroll summary contract

Status: **contract defined, not connected, and nothing receives it yet.** Dot.HR has no payroll tables and no API on `origin/main`; this file and `App\Services\Integrations\Hr\PayrollSummary` (validation) exist so the two modules agree before either is built. There is deliberately no route: an endpoint nobody calls is attack surface with no use.

## Shape (totals only)

```json
{
  "company_uuid": "3f2b8c1e-9a4d-4e7b-8c55-1d2e3f4a5b6c",
  "run_id": "run-2026-10",
  "period_start": "2026-10-01", "period_end": "2026-10-31", "pay_date": "2026-10-25",
  "currency": "ZAR", "employees": 12,
  "totals": { "gross_cents": 24000000, "net_cents": 19000000, "paye_cents": 3500000,
              "uif_employee_cents": 177120, "uif_employer_cents": 177120, "sdl_cents": 240000, "eti_cents": 0 }
}
```

Whole minor units; `net <= gross`; `eti_cents` optional. **No employee names, ID numbers, bank details or per-person pay** cross this contract (decision D-17: payroll detail is for owners, finance managers and accountants, inside Dot.HR). Extra keys are dropped by `PayrollSummary::fromArray`.

## Where it goes

`App\Contracts\Integrations\PayrollSummarySink::accept(LedgerContext, PayrollSummary)`, implemented by the Tax module's `PayrollImport` (PAYE, UIF and SDL feed the EMP201). Bound to `NullPayrollSummarySink` until then, which throws: a summary is refused, never swallowed. When Dot.HR can send it, add a signed route in the style of the Emall endpoint (same signature scheme, secret `FINANCE_HR_SECRET`, idempotent by `run_id` per ledger, tenant by `company_uuid`).
