# R-44 Tax QA (independent): findings

Branch `feat/r44-qa-tax`, decisions D-1300..D-1308 in `R44_DECISIONS.md`. The job was to try to prove the tax numbers wrong, without reusing the implementation's helpers to compute expected values. Nothing here files anything with SARS. Skills: this was QA and logic work with one small view change (a Basis column and a pill, existing components and tokens only), so the design skills were not invoked and the change was not looked at in a browser.

## 1. How the figures were re-derived

Live SARS pages re-fetched on 2026-11-09 (WebFetch): companies and SBC rates, individuals' rates and rebates, turnover tax rates, VAT rates page, VAT tax periods page, VAT obligations page (due dates), UIF page, SDL page, provisional tax page, Budget 2026 FAQs; gov.za public holidays 2026 and 2027. Primary documents extracted by the research agent and re-read by me from their text extracts: Budget 2026 Tax Guide, VAT201 guide rev 11, VAT 404 Issue 15, Provisional Tax guide rev 28, ETI Guide Issue 5. The SDL Act wording came from a web search (acts.co.za / SAQA copy of Act 9 of 1999). Arithmetic of every table was redone by hand (each base amount equals the tax on the band below; thresholds equal the rebates cancelling the 18 percent band).

## 2. Mismatches found (seed vs authority)

| # | Row | Seed said | Authority says | Action |
|---|---|---|---|---|
| 1 | `cit.rate_bp` 27 percent | valid from 2023-04-01; 2023-03-31 refused | SARS: "31 March 2023 to 31 March 2024" is 27 percent | fixed (D-1300) |
| 2 | `sdl.payroll_exemption_threshold_cents` | exempt only if payroll *under* R500,000 (R500,000.00 charged) | Act s4(b) and SARS page: exempt if it "won't exceed" R500,000 | fixed (D-1303); the Budget Tax Guide's "less than" is the odd one out |
| 3 | Provisional business-day direction | "not recorded", earlier date shown with a caveat | SARS provisional page and guide para 10(j): the business day *before* | wording and confidence fixed (D-1302); the dates were already right |
| 4 | `vat201.fields` field 11 | layout says f10 x tax fraction, builder left field 11 empty | guide: field 11 = field 10 x r/(100 + r) | fixed (D-1301) |
| 5 | `emp501`/ETI note | "taper formula is not in the research" | ETI Guide Issue 5: first 12 months R1,500 - 0.75 x (remuneration - R5,500); second 12 months R750 - 0.375 x (remuneration - R5,500) | not changed: nothing calculates ETI; recorded here for the owner |
| 6 | VAT due date, payment outside eFiling | seeded: return last business day, payment 25th | VAT201 guide table says the same, but VAT 404 section on due dates says payment by EFT through internet banking also gets the last business day | **source conflict, not changed** (the 25th is the earlier, safe date); worth a SARS/practitioner check |
| 7 | `employment.minimum_wage_hourly_cents` | R30.23 from 2026-03-02 | SARS Budget FAQs agree (2 March 2026, Gazette 54075); several commercial pages say 1 March | consistent with SARS; the 1-day difference is not verified against the Gazette |
| 8 | Individual and SBC history | only the 2027 individual table and the 2026-27 SBC table seeded | SARS also lists SBC 95,750 / R18,848 / R57,698 for years ending 1 Apr 2024 to 31 Mar 2026 and the 2026 turnover table (335,000...) | **gap**, not seeded (no calculation needs it; a prior-year query is refused, never guessed) |

## 3. Row-by-row status of the seeds

Verified = matches a SARS page I fetched today or the text of a SARS/Treasury document I read; arithmetic = reproduced by hand.

| Group | Rows | Status |
|---|---|---|
| VAT rate 15 percent from 2018-04-01, zero rate | `vat.standard_rate_bp`, `ZA_VAT_STD` rate | verified (SARS VAT rates page, Budget guide). The 15.5/16 percent saga is correctly absent. |
| VAT registration thresholds R2.3m / R120,000 from 2026-04-01 | compulsory, voluntary | figures verified (Budget Tax Guide, SARS FAQs); FAQs say "not yet promulgated", VAT Connect says Act 3 of 2026: confidence medium is right; old R1m / R50,000 rows are the SARS "before" values |
| Registration window 21 days | `vat.registration.window_days` | not verified (VAT Act s23 from memory); medium is right |
| VAT tax periods A-E | `vat.period_category` | verified (SARS periods page: A, B, C, D, E and the R30m / R1.5m triggers). F four-monthly: **not on the SARS page**; medium is right |
| VAT due dates | manual 25th, eFiling last business day, preceding business day | verified (SARS obligations page, VAT201 guide table, VAT 404); see mismatch 6 |
| VAT201 layout fields 1-20 | `vat201.fields` | verified against the guide's text (formulas for 4, 4A, 6, 8, 9, 11, 13, 19, 20) |
| Invoice thresholds, 21 days, 5-year input window | `vat.invoice.*`, `vat.input_claim_window_years` | read in the research from VAT 404; not re-fetched (a 3 MB PDF); the R5,000 boundary ambiguity is flagged in the row |
| Payments-basis cap R2.5m | `vat.payments_basis.turnover_cap_cents` | **not verified** (not on the Budget FAQ page; secondary sources only). low is right |
| Company tax 27 / 28 percent | `cit.rate_bp` | verified; fixed |
| SBC bands, R20m gross income | `sbc.bands`, `sbc.gross_income_limit_cents` | verified (SARS rates page; Budget FAQs); arithmetic reproduced |
| Turnover tax bands (individual and company) and R2.3m limit | `tot.*` | verified (SARS turnover tax page, Budget guide, FAQs) |
| Assessed-loss set-off R1m + 80 percent | `cit.assessed_loss.setoff` | **not verified** by fetch; matches my knowledge of s20; low is right |
| Individual 2027 brackets, rebates, thresholds | `pit.*` | verified (SARS rates page); every base amount and the three thresholds reproduced by hand |
| Interest exemption, tax-free savings, medical credits | `pit.interest_exemption.*`, `pit.tax_free_savings.*`, `paye.medical_credit.*` | verified in the Budget Tax Guide text (R23,800 / R34,500 / R46,000 / R376 / R254) |
| Dividends tax 20 percent, withholding 15 / 15 / 15 / 7.5 / 10 / 15 | `dividends_tax.rate_bp`, `wht.*` | verified (Budget Tax Guide). Dividends-tax payment date: **not verified**, low is right |
| Admin penalty bands | `tax.admin_penalty.bands` | R250 to R16,000 range and 35 months verified on the SARS page; the **band table is not verified**, low is right |
| Provisional tax dates, penalties, basic amount, deemed nil | `pt.*` | dates verified on the SARS provisional tax page and in the guide; penalty rules read in the guide extract |
| ITR14 12 months | `itr14.due_months_after_year_end` | read in the research (SARS calendar); not re-fetched |
| EMP201 7th rule | `emp201.due_date` | verified (SARS UIF page: seven days, last business day before) |
| UIF 1 percent each, ceiling R17,712 from 2021-06-01, R177.12 | `uif.*` | verified (SARS UIF page) |
| SDL 1 percent | `sdl.rate_bp` | verified; threshold fixed |
| ETI | `eti.*` | amounts, ages, R2,500 floor (160 hours), R7,500 cap, end 28 Feb 2029 verified in the ETI Guide text |
| Minimum wage | see mismatch 7 | SARS FAQ agrees; Gazette not read |
| EMP501 windows | `emp501.*` | the 2026 interim window is from a SARS notice read by the researcher; annual window inferred (medium) and the interim rule for other years (low) are labelled correctly; the late penalty is **not verified**, low is right |
| COIDA | `coida.*` | **not verified** (secondary sources only) |
| ITR12 season | `itr12.filing_season` | **not verified** (2026 only) |
| Interest 10.25 percent from 2025-12-01, refund 6.25 percent from 2026-03-01 | `sars.interest_rate*` | verified in the Budget Tax Guide and the Provisional Tax guide text |
| Retention 5 / 7 years | `records.*` | not verified (acts.co.za link only); 5-year is high and matches the research; 7-year medium is right |
| Public holidays 2026-2028 | `tax_holidays` | see section 4 |

**Confidence labels judged wrong:** none needed lowering. The SDL row stays high because the Act and the SARS page agree (the note now records the Budget guide's different wording). No row was raised.

## 4. Public holidays 2026-2028

The seeded list equals a hand-written list for each year (test `test_the_derived_holiday_lists_equal_the_hand_written_ones`), equals an independent derivation of the Public Holidays Act rules for every year 2026-2045 (Easter taken from PHP's `easter_days()`, a different algorithm from the one in the code), and contains every date on gov.za for 2026 and 2027 (gov.za's page does not cover 2028). Derived and not on the gov.za fetch: **2027-12-27** (26 December 2027 is a Sunday) and **2028-09-25** (24 September 2028 is a Sunday), both from the Act's Sunday rule; the fetch summary may simply have omitted them. Not covered, as the seeder says: one-off proclaimed days (for example the local government election day). Error found: none. Easter: 5 April 2026, 28 March 2027, 16 April 2028.

## 5. Defects fixed (each has a failing-before regression test)

| D | Defect | Where |
|---|---|---|
| D-1300 | 31 March 2023 company year end refused | `TaxRulesSeeder` |
| D-1301 | VAT201 field 11 never calculated: a field 10 change in use produced no output tax | `Vat201Builder` |
| D-1302 | Provisional dates claimed the weekend direction was unknown | `DueDates` |
| D-1303 | SDL charged at exactly R500,000 | `PayrollTaxCalculator` |
| D-1304 | 18-month uplift test overflowed month ends (31 Aug plus 18 months became 3 March) | `ProvisionalTaxPlanner` |
| D-1305 | A revision of a settled period could post a second settlement and double VAT control | `TaxReturnService::postSettlement` |
| D-1306 | Working paper and CSV showed figures with no basis tag; payroll list had none; the submitted banner did not name the person | `Workpaper`, views |
| D-1307 | With the books closed through the period end the settlement could never be posted | `TaxReturnService::postSettlement` |
| D-1308 | `figures`, `rule_versions`, `reconciliation`, `checks`, channel, label, preparer of a prepared or submitted return, and a recorded payment, could be changed by raw SQL (and the model only guarded some columns); rule/rate/code content could be changed by raw SQL | migrations `2026_11_10_600000`, `2026_11_10_600100`, `TaxReturn` |

## 6. Probes that found nothing wrong

- A submitted return cannot be altered by HTTP (second submission, withdraw, prepare, adjust, void), by the model, or by raw SQL on the headline columns (existing triggers). A submitted status without reference, date or person is refused by the database.
- Journal lines cannot be re-tagged, moved or deleted (raw UPDATE and DELETE refused on both engines).
- `ready` is false when the reconciliation does not tie or carries no verdict; an unresolved review error and an unfinished period also block preparing.
- Every working paper and CSV, in all four statuses, carries the banner, period, basis, filing channel and rule versions; a submitted one names the reference, date and person and says Dot.Finance did not submit it. No tax view says anything was submitted to SARS by the software. Non-return deadlines can only be *waived* with a reason, never marked submitted.
- A rule edit appends: the old row stays and answers for its own dates (last day of the old version, first day of the new, before the first refused); in-place edits, overlaps and deletes are refused by the models.
- The payroll journal balances, the VAT settlement honours the period lock, the payroll journal is refused in a closed month.

## 7. Open observations (not changed)

1. Credit notes: the VAT of a credit note issued goes to field 18 and its value is left in field 1 (D-803). The guide's text for field 18 supports the VAT part; the guide does not say whether the *value* is deducted from field 1. Zero-rated and exempt credit notes have no VAT to put anywhere, so their value is dropped from fields 2 and 3 without a warning. Not verified against SARS; ask a practitioner.
2. Fields 4, 4A are the VAT booked (line-rounded); eFiling recomputes them as field x 15/115 (total-rounded), so a cent or two can differ. By design (D-803 area), stated in the working paper notes.
3. For a period that straddles a rate change, fields 9 and 11 use the rate at the period end; fields 4/4A and the review check use each entry's own date.
4. The rounding on eFiling (whole rand or cents) is still unconfirmed.
5. A late entry dated in a period whose return is already prepared or submitted is not blocked unless the books are closed; the return stays frozen and the VAT page shows the live check.
6. Payments-basis VAT is not computed (known, D-808).

## 8. Tests (exact)

New files in `tests/Feature/Tax/Qa/`: `QaVatCalculationTest` (7 tests: hand-worked examples, rounding, rate boundary with a made-up rate change, and 100 random documents against an independent integer re-implementation at line and document level), `QaVat201WorkedExampleTest` (5: every box 1-20 and the diesel totals by hand), `QaCalendarTest` (11: holidays, periods A-F, VAT/EMP201/provisional dates), `QaIncomeAndPayrollTaxTest` (16), `QaTaxIntegrityTest` (17), `QaRuleVersioningTest` (3). Changed on purpose: `CalendarTest` (provisional wording), `PayrollTest` (SDL at exactly R500,000), `TaxRulesSeederTest` (31 March 2023).

Results are in section 9 of the hand-off message and are repeated here once the final runs finish.

## 9. Owner steps

1. After merge run `php artisan migrate` (two new trigger migrations), then the seeder as before. Nothing had been seeded in production when this was written. If some database already ran the old `TaxRulesSeeder`, it holds a 27 percent row starting 2023-04-01: the corrected row starting 2023-03-31 would overlap it and be refused, and the new trigger forbids editing its start date. On such a database delete that one `tax_rules` row (`cit.rate_bp`, valid_from 2023-04-01) before running the new seeder.
2. Ask a practitioner about the two source conflicts: the VAT payment-by-EFT date (mismatch 6) and credit-note value in field 1 (observation 1).
3. Confirm the not-verified rows listed in section 3.
