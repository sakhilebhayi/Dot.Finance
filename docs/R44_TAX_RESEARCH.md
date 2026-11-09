# R-44 Dot.Finance: South African tax research (and extensibility notes)

Researcher: tax research agent. Compiled 2026-11-09. Every "accessed" date below is 2026-11-09 unless stated.
Status: research input for a rules engine. It is not tax advice. Dot.Finance must present everything in section 6 as "prompts to review", never as advice, and must carry a "verify with a registered tax practitioner / SARS" disclaimer.

## 0. How to read this document

Confidence labels:

- **HIGH**: read directly from a SARS/Treasury document (I extracted text from the PDF myself) or two independent official SARS pages agree.
- **MEDIUM**: one official SARS web page (summarised by a fetch tool, not read line by line), or an official source plus a consistent secondary source; or my own long-standing knowledge of the statute that I could not re-confirm against a primary page today.
- **LOW**: secondary/commercial source only, conflicting sources, or inference.

Primary documents I read in full text (not summarised): SARS Budget 2026 Tax Guide (pocket guide), SARS VAT201 guide GEN-ELEC-04-G01 rev 11 (effective 12 May 2025), SARS VAT 404 Guide for Vendors Issue 15, SARS Provisional Tax guide GEN-PT-01-G01 rev 28 (effective 16 Oct 2026), SARS ETI Guide Issue 5 (27 Jul 2026), SARS VAT Modernisation Consultation Paper (Aug 2026). Their local extracts sit in the scratchpad `work/` folder (`budget26.txt`, `vat201.txt`, `vat404.txt`, `pt.txt`, `eti.txt`, `vatmod.txt`).

The rates and thresholds changed in 2026. Several older rules of thumb (VAT threshold R1m, voluntary R50k, turnover tax R1m, tax-free threshold R95,750) are now stale. Do not seed any pre-2026 figure as "current".

---

## 1. Key figures table (current as at 2026-11-09)

| Item | Value | Effective | Confidence | Source |
|---|---|---|---|---|
| VAT standard rate | 15% (unchanged since 1 Apr 2018; the 15.5% (1 May 2025) and 16% (1 Apr 2026) increases were reversed; Budget 2026 did not raise it) | 1 Apr 2018 onward | HIGH | sars.gov.za/tax-rates/value-added-tax/; Budget 2026 Tax Guide |
| VAT zero rate | 0% | n/a | HIGH | VAT201 guide |
| VAT compulsory registration | taxable supplies more than R2,300,000 in 12 months (was R1,000,000) | 1 Apr 2026 | HIGH (figure), MEDIUM (legal status, see 2.2) | Budget 2026 Tax Guide; SARS Budget 2026 FAQs; VAT Connect 21 |
| VAT voluntary registration | taxable supplies more than R120,000 (was R50,000), up to R2.3m | 1 Apr 2026 | HIGH | SARS Budget 2026 FAQs; Budget 2026 Tax Guide |
| VAT201 due (manual) | 25th of month after period end, or previous business day | standing | HIGH | VAT 404; SARS calendar |
| VAT201 due (eFiling) | last business day of the month after period end (return and payment); payment made outside eFiling is due the 25th | standing | HIGH | VAT 404; SARS obligations page |
| VAT late payment penalty | 10% of unpaid VAT (s39 VAT Act) plus interest | standing | HIGH | VAT 404 ch.11 |
| Interest (late/underpaid tax, late VAT, customs) | 10.25% p.a. | from 1 Dec 2025 (reconfirmed from 1 Mar 2026) | HIGH | Budget 2026 Tax Guide; Provisional Tax guide rev 28. Prescribed rate is gazetted and moves with the repo rate, so model it as a dated rule |
| Interest on overpaid provisional tax (refund) | 6.25% p.a. | from 1 Mar 2026 | HIGH | Budget 2026 Tax Guide |
| Company income tax | 27% | YAs ending 1 Apr 2023 to 31 Mar 2027 (28% for YAs ending 1 Apr 2022 to 30 Mar 2023) | HIGH | SARS companies/SBC rates page (updated 28 Oct 2026) |
| Small business corporation (SBC) | 0% to R99,000; 7% of excess to R365,000; R18,620 + 21% to R550,000; R57,470 + 27% above | YAs ending 1 Apr 2026 to 31 Mar 2027 | HIGH | same page; Budget 2026 Tax Guide |
| Turnover tax | qualifying turnover up to R2,300,000; 0% to R600,000; 1% to R950,000; R3,500 + 2% to R1,400,000; R12,500 + 3% above | individuals 1 Mar 2026 to 28 Feb 2027; companies YAs ending 1 Apr 2026 to 31 Mar 2027 (limit rise effective 1 Apr 2026) | HIGH (rates, Tax Guide), MEDIUM (R2.3m limit effective date wording) | Budget 2026 Tax Guide; SARS small business page; Budget 2026 FAQs |
| Dividends tax | 20% final tax | standing | HIGH | Budget 2026 Tax Guide |
| Withholding (non-residents) | royalties 15%; interest 15% (with exemptions); foreign entertainers/sportspersons 15%; immovable property sellers 7.5% (individual) / 10% (company) / 15% (trust) | standing | HIGH | Budget 2026 Tax Guide |
| Individuals: top marginal rate | 45% above R1,878,600 | 1 Mar 2026 to 28 Feb 2027 | HIGH | SARS rates of tax for individuals |
| Rebates (2027 YA) | primary R17,820; secondary (65+) R9,765; tertiary (75+) R3,249 | 1 Mar 2026 | HIGH | SARS; Budget 2026 Tax Guide |
| Tax thresholds (2027 YA) | under 65: R99,000; 65-74: R153,250; 75+: R171,300 | 1 Mar 2026 | HIGH | same |
| Medical scheme fees tax credit | R376 per month for first two persons; R254 per additional dependant | 2026/27 | HIGH | Budget 2026 Tax Guide |
| PAYE/SDL/UIF/ETI due (EMP201) | 7th of following month; previous business day if 7th is weekend/public holiday | standing | HIGH | SARS calendar; UIF page; PAYE guide |
| UIF | 1% employee + 1% employer; remuneration ceiling R17,712/month (R212,544/yr); max R177.12 per party per month | ceiling since 1 Jun 2021, no 2026 change found | HIGH for SARS page; some calculator sites wrongly date it 2026 | sars.gov.za UIF page |
| SDL | 1% of total remuneration; employers with annual payroll under R500,000 exempt | standing | HIGH | Budget 2026 Tax Guide |
| ETI | ages 18-29; remuneration at least R2,500 (min wage test, hours-apportioned) and under R7,500; R1,500/month first 12 months for R2,500-R5,499; R750 second 12; tapering R5,500-R7,499; max 24 months; ETI ends 28 Feb 2029 | 1 Apr 2025 (enacted retrospectively by Act 3 of 2026) | HIGH | SARS ETI Guide Issue 5 (27 Jul 2026) |
| National minimum wage | R30.23/hour | 2 Mar 2026 | MEDIUM | SARS Budget 2026 FAQs |
| Provisional tax | 1st payment: 6 months after YA start; 2nd: last day of YA; 3rd (voluntary top-up): 7 months after YA end for Feb year-ends (30 Sep), otherwise 6 months | standing | HIGH | Provisional Tax guide rev 28 |
| Provisional estimate rules | basic amount (last assessed taxable income, less taxable capital gain; +8% if estimate made more than 18 months after that YA end); for taxable income above R1m the 2nd estimate must be at least 80% of actual; up to R1m: at least the lesser of 90% of actual and basic amount; underestimation penalty 20% of shortfall; 10% late payment penalty on 1st/2nd; no penalty on 3rd | standing | HIGH | Provisional Tax guide rev 28 |
| ITR14 (companies) | within 12 months of financial year end | standing | HIGH | SARS calendar |
| EMP501 interim | period 1 Mar to 31 Aug; window 21 Sep to 31 Oct 2026 | 2026 | HIGH | SARS notice |
| EMP501 annual | period 1 Mar to end Feb; window 1 Apr to 31 May (2027 window dates not yet published; inferred from the standing calendar) | annual | MEDIUM | SARS calendar; 2026 annual notice |
| Record retention (tax) | 5 years (Tax Administration Act s29; VAT: 5 years) | standing | HIGH | SARS obligations page; VAT 404 |
| COIDA maximum earnings | R668,000 per employee; minimum assessment R1,621 (domestic employers R560) | 1 Mar 2026 to 28 Feb 2027 (GN 3910, GG 54577) | MEDIUM | acts.co.za; accountingacademy.co.za (SAAA) |
| E-invoicing | not mandatory; SARS proposed phased Peppol-style model, mandatory phase roughly from 2030 (consultation paper) | proposal only | HIGH (that it is a proposal) | SARS VAT Modernisation Consultation Paper Aug 2026 |

---

## 2. VAT

### 2.1 Rate and rate history

- Standard rate 15% since 1 April 2018 (14% before that). Zero rate 0%.
- 2025 saga: the February 2025 Budget announced 15.5% from 1 May 2025 and 16% from 1 April 2026. A court order (SARS media release, 27/29 April 2025) and the Rates and Monetary Amounts and Amendment of Revenue Laws Bill (introduced 24 April 2025) reversed this; the rate stayed at 15%. The planned zero-rated food list expansion also fell away (Budget 3.0, 21 May 2025); the zero-rated food list is back to its original items.
- 2026 Budget (25 February 2026): no rate increase. The change was the registration thresholds (below). Analysts expect continued fiscal pressure, so a rate change in a future Budget is plausible. The rules engine must never hard-code 15%.
- Source: https://www.sars.gov.za/tax-rates/value-added-tax/ (accessed 2026-11-09). HIGH.
- Modelling note: VAT201 itself has a 14%-to-15% transition mechanic (Field 12 for 14% supplies, Field 18 for 14% capital inputs). It is historic, but it shows the return must be able to handle a period that straddles two rates. Rate must therefore be resolved per transaction by tax point (time of supply), not per return.

### 2.2 Registration thresholds

| Type | Before | From 1 April 2026 |
|---|---|---|
| Compulsory | more than R1,000,000 in 12 months | more than R2,300,000 |
| Voluntary | more than R50,000 | more than R120,000 (up to R2.3m) |

- Announced in the Budget Speech of 25 February 2026. SARS FAQs (20 March 2026) said the amendments were not yet promulgated but SARS applied them from 1 April 2026. VAT Connect Issue 21 (September 2026) states the Rates and Monetary Amounts and Amendment of Revenue Laws Act 3 of 2026 was promulgated on 1 April 2026 (the ETI guide also cites "Act 3 of 2026" for the ETI change). The same VAT Connect page still describes the voluntary threshold as "proposed" and tells the reader to confirm final amounts in the Act. Treat the R2.3m compulsory figure as HIGH and the R120,000 voluntary figure as HIGH-MEDIUM; store `legal_status` on the rule.
- Existing voluntary registrations below R120,000 are not auto-cancelled; SARS notifies and the vendor can object (ADR1, 80 business days). Vendors between R120,000 and R2.3m may apply to cancel (VAT123e); deemed exit VAT applies on cancellation (lesser of cost or open market value).
- Registration must happen within 21 days of exceeding the threshold (VAT Act s23; stated in VAT 404 at a general level, I did not re-read the clause). MEDIUM.
- Sources: https://www.sars.gov.za/about/sars-tax-and-customs-system/budget/budget-2026-frequently-asked-questions/ ; https://www.sars.gov.za/businesses-and-employers/my-business-and-tax/vat-connect-issue-21-september-2026/ (both accessed 2026-11-09).
- Related: payments basis of accounting is available to vendors whose taxable supplies have not exceeded R2.5 million (VAT 404 Issue 15, section 4.3). I did not find evidence this was raised in 2026; flag as "verify" because the Budget raised other thresholds to R2.3m. MEDIUM.
- Related: voluntary registration is not permitted for vendors with only exempt supplies. Foreign e-services suppliers register under a separate rule (threshold R1m historically; not re-verified, LOW).

### 2.3 Tax periods (categories)

Source: https://www.sars.gov.za/types-of-tax/value-added-tax/tax-periods-for-vat-vendors/ and VAT Act s27. Category is allocated by SARS, not chosen.

| Category | Period | Typical vendor | Period ends |
|---|---|---|---|
| A | 2 months | default | Jan, Mar, May, Jul, Sep, Nov |
| B | 2 months | default | Feb, Apr, Jun, Aug, Oct, Dec |
| C | 1 month | taxable supplies exceed or are likely to exceed R30m in 12 months; or by application; or placed there for non-compliance | every month end |
| D | 6 months | farmers with taxable supplies under R1.5m; registered micro businesses (Sixth Schedule) | end Feb and Aug (or approved alternative) |
| E | 12 months | companies/trusts that let fixed property, hire movable goods, or manage connected companies (and similar, VAT 404 ch.3) | last day of the vendor's year of assessment |
| F | 4 months | not listed on the SARS page I fetched. The VAT Act s27 defines it as four-month periods ending the last day of June, October and February | end Jun, Oct, Feb |

Confidence: HIGH for A-E; MEDIUM for F (Act text via a secondary source; SARS page omits it). Treat F as "supported but rare".

### 2.4 Due dates

- Manual/EFT/bank: return and payment by the 25th of the month after the tax period ends; if not a business day, the preceding business day.
- eFiling: return and payment by the last business day of the month after the tax period ends. SARS's VAT201 guide also lists a split: return filed on eFiling with payment made outside eFiling means return on the last business day, payment on the 25th. All of this is in VAT 404 and on https://www.sars.gov.za/types-of-tax/value-added-tax/obligations-of-a-vendor/ . HIGH.
- Late payment: 10% penalty plus interest (10.25%). SARS FAQ says no fixed penalty for late submission alone (secondary-summary, MEDIUM); admin non-compliance penalties can still apply for persistently outstanding returns.
- Interest on delayed VAT refunds (s45) from 1 April 2026: runs from 21 business days after SARS receives the VAT201 showing the refund (VAT Connect 21). MEDIUM.
- Model "due date" as a rule row `(period_end + offset_months, day = 25 | LAST_BUSINESS_DAY, convention = PREVIOUS_BUSINESS_DAY)` keyed by `submission_channel`. Public holidays need a separate jurisdiction holiday table (SARS uses business days; Saturday and Sunday are not business days).

### 2.5 VAT201 return fields

Source: SARS GEN-ELEC-04-G01 rev 11 (effective 12 May 2025), read directly. HIGH. https://www.sars.gov.za/gen-elec-04-g01-guide-for-completing-the-value-added-tax-vat201-declaration-external-guide . Amounts: supplies fields are VAT-inclusive except where noted; the tax fraction is r/(100+r) = 15/115.

Output tax:

| Field | Meaning | Calculation |
|---|---|---|
| 1 | Standard rate supplies, excluding capital goods/services and accommodation (VAT inclusive) | input |
| 1A | Standard rate supplies of capital goods/services (sale of land/buildings/plant/IP; deemed supplies on deregistration) | input |
| 2 | Zero-rated supplies excluding exports | input |
| 2A | Zero-rated exported goods (Customs code mandatory) | input |
| 3 | Exempt supplies and non-supplies | input |
| 4 | Output tax on field 1 | Field 1 x 15/115 (editable) |
| 4A | Output tax on field 1A | Field 1A x 15/115 (editable) |
| 5 | Commercial accommodation exceeding 28 days (VAT exclusive, all-inclusive charge) | input |
| 6 | 60% of field 5 | Field 5 x 60% |
| 7 | Accommodation of 28 days or less (VAT exclusive) | input |
| 8 | Sum of 6 and 7 | 6 + 7 |
| 9 | Output tax on accommodation | Field 8 x 15% (note: rate, not tax fraction) |
| 10 | Change in use and export of second-hand goods (VAT inclusive consideration) | input |
| 11 | Output tax on field 10 | Field 10 x 15/115 (editable) |
| 12 | Other and imported services: VAT on debit notes issued, credit notes received, recovered bad debts, barter, imported services for non-taxable use, going-concern acquisitions, accounting-basis changes | input |
| 13 | Total output tax | 4 + 4A + 9 + 11 + 12 |

Input tax:

| Field | Meaning |
|---|---|
| 14 | Capital goods/services supplied to you (VAT amount; valid tax invoice held) |
| 14A | Capital goods imported (Customs code mandatory; bill of entry, release document and proof of VAT payment held) |
| 15 | Other goods/services supplied to you (not capital): stock, rent, repairs, accounting fees, notional input on second-hand goods |
| 15A | Other goods imported (not capital) |
| 16 | Change in use (previously non-taxable now taxable; increase in taxable use) |
| 17 | Bad debts (VAT portion, invoice basis only) |
| 18 | Other: credit notes issued, debit notes received, accounting-basis changes |
| 19 | Total input tax = 14 + 14A + 15 + 15A + 16 + 17 + 18 |
| 20 | VAT payable (positive) or refundable (negative) = 13 - 19 |

Diesel refund (only if registered): fields 21-25 on land, 26-29 offshore, 30-33 rail and harbour, 34-37 peak power plant, 38 net after diesel (Field 38 = 20 less diesel totals 25+29+33+37). On-land claimable litres = eligible litres x 80%. Payment section: PRN, payment period CCYYMM, total (Field 20 + penalty and interest), VAT, diesel, penalty and interest. Refund section mirrors it.

Also known to the guide: the declaration is the eFiler's login. Original or revised returns share the same PRN; revisions pre-populate the earlier submission. A return can be revised for periods inside the 5-year window.

Modelling: store the VAT201 as a versioned `form_definition` (form code, revision, effective date) with `form_box` rows (box code, label, kind: INPUT | CALC, formula as an expression string, rounding rule, editable flag, mandatory-if conditions such as Customs code). Do not hard-code the 15/115 and 60% in code; resolve `vat.standard_rate`, `vat.accommodation_long_stay_pct` from the rule table. Dot.Finance should map each ledger VAT code (standard / zero / exempt / capital / import / accommodation) to a box, and drill down from every box to the transactions behind it (SARS audit expectation).

### 2.6 Supply categories

Standard-rated: everything not zero-rated or exempt (VAT201 guide examples include restaurant meals, motor vehicles, medicines, professional services, local air travel, hotel accommodation).

Zero-rated (examples SARS gives in the VAT201 guide, HIGH; the statutory list is in VAT Act s11 and Schedule 2 Part B for foods): brown bread, eggs, dried beans, lentils, fresh and frozen fruit and vegetables, maize meal, milk and dairy powder blend, pilchards/sardines (canned), vegetable oil (not olive oil), illuminating kerosene, fuel levy goods (petrol, diesel), sale of a business as a going concern (conditions apply), services on goods temporarily imported for repair, international travel, exports of goods (with the documentary rules: VAT 404 ch.12), supplies of qualifying testing and clinical-trial services for non-residents (new, VAT Connect 21). The expansion of the zero-rated food list proposed in 2025 was dropped. Zero-rated supplies always require a full tax invoice (VAT 404 s13.1).

Exempt (VAT201 guide examples, HIGH; VAT Act s12): financial services (bank charges, interest, life insurance premiums), residential accommodation (rent), the letting of leasehold land (as stated), educational services, transport of fare-paying passengers by road or rail, membership contributions to employee organisations, childcare, donated goods or services by associations not for gain. New: most school supplies exempt from 1 Jan 2026 with exit VAT rules (VAT Connect 21).

Non-supplies (Field 3 group): outside-scope items (for example salaries, dividends, some deposits).

### 2.7 Tax invoices, debit and credit notes

Source: VAT 404 Issue 15, chapter 13, read directly. HIGH. Statutory sections: VAT Act s20 and s21.

| Case | Rule |
|---|---|
| Consideration over R5,000 (VAT inclusive) | Full tax invoice (s20(4)). VAT 404 states "more than R5,000" in one place and "R5,000 or more" in another; store the boundary as a flagged ambiguity and issue a full invoice at exactly R5,000 to be safe |
| Consideration under R5,000 | Abridged tax invoice allowed (s20(5)), but never for a zero-rated supply |
| Consideration under R50 | No tax invoice needed |
| Zero-rated supply | Full tax invoice always |
| Issued by | supplier, within 21 days of the time of supply; one tax invoice per supply (never issue a second to correct the first) |

Full tax invoice must show: the words "tax invoice", "VAT invoice" or "invoice"; supplier name, address and VAT number; recipient name, address and VAT number (if the recipient is a vendor); serial number and date of issue; full description of goods or services; quantity or volume; and the price and VAT by one of three methods: (1) amount excl. VAT, VAT, total incl. VAT; (2) total consideration and the VAT rate charged (statement that VAT is included at the rate); or (3) total consideration and the VAT amount included.

Abridged tax invoice: same but no recipient name/address/VAT number and no quantity requirement (the "…" rows in the SARS table are the omitted items).

Credit/debit note must show: "credit note" or "debit note"; supplier name, address, VAT number; recipient name and address (unless the original was an abridged invoice); date of issue; amount by which value and VAT changed (or total and VAT or rate, matching the original invoice); brief reason; identification of the original invoice (number and date). Triggers: cancelled supply, fundamentally varied supply, consideration changed by agreement (including discount), goods returned, price overstated/understated. Not required for prompt-settlement discounts clearly stated on the invoice, or coin-rounding differences (BGR 65).

Also: second-hand goods notional input needs a VAT264 declaration; agent statements within 21 days of month end; buyer of an enterprise may use seller details for six months (s20(5A)).

### 2.8 Input tax rules

Source: VAT 404 ch.8-9 (read directly). HIGH.

- Need a valid tax invoice, debit or credit note, bill of entry with proof of payment, or other prescribed document before claiming. The claim is made in the period the document is held, and must fall within **5 years** after the end of the tax period in which the invoice should have been issued (21 days from time of supply) or the import occurred. Expired claims are forfeited.
- Denied outright (s17(2)): entertainment (with exceptions below), sporting/social/recreational club membership, acquisition of a "motor car" (vehicle with 3+ wheels, normally on public roads, built mainly to carry passengers; exceptions for motor dealers and car rental enterprises; vehicles outside the definition such as goods vehicles are different, verify per vehicle), medical scheme and benefit fund cover. Magazine/trade journal subscriptions directly related to the business are claimable. VAT on professional body fees paid for employees is not claimable.
- Entertainment exceptions (s17(2)(a)): vendors who supply entertainment for a charge covering cost or open market value; overnight business subsistence of the vendor or employee (not an allowance); seminar meals included in the ticket price; passenger transport entertainment; municipality sport facilities; welfare organisations; donor-funded projects; bet prizes; crew meals on vessels. Common denied examples: staff lunches and year-end parties, client lunches and golf days, canteen costs, hospitality boxes, holiday houses.
- Mixed use: direct attribution first (wholly taxable: full claim; wholly exempt/private: none). Only mixed-purpose overheads are apportioned. The turnover-based method may be used without a ruling; other methods require a ruling (VAT 404 s8.4). Apportionment is usually annual with an adjustment.
- Bad debts (s22): invoice-basis vendors may claim the VAT on a debt written off as irrecoverable (tax fraction at time of supply times amount written off), provided the supply was a taxable supply for consideration in money and was already accounted for. Recovered amounts are output tax when received. Payments-basis vendors cannot claim except on invoice-basis supplies of R100,000 or more. Reverse side: if a buyer who claimed input tax has not paid the supplier 12 months after the period of the deduction, they must account for output tax (field 12).
- Capital goods: claimed in field 14 / 14A at acquisition (no spreading) provided taxable use. Change in use adjustments run through fields 10-11 (output) and 16 (input). Imports: VAT paid at Customs, claimed from the bill of entry (14A/15A).
- Imported services (reverse charge): where the vendor makes taxable supplies there is no net effect; for non-taxable-use vendors, VAT on imported services is output tax in field 12 (record VAT215). Exceptions: imported services under R100 per invoice; exempt-if-local; non-resident employees.
- Foreign electronic services suppliers registered in SA charge SA VAT on B2C supplies; they have specific rules (VAT-REG-02-G02). MEDIUM.

### 2.9 SARS submission mechanics and what a software vendor can actually do

**Short answer: SARS offers no open public API for VAT201 submission.** What exists is the **Independent Software Vendor (ISV) programme**, plus manual eFiling. Details:

1. **Official channels for a VAT201** (VAT201 guide rev 11, section 8): eFiling, or at a SARS branch by appointment. Faxed, emailed or photocopied returns are not accepted. HIGH.
2. **ISV programme** (https://www.sars.gov.za/individuals/i-need-help-with-my-tax/your-tax-questions-answered/independent-software-vendors/ , accessed 2026-11-09): SARS describes the ISV service as an extension of eFiling letting accounting/tax software interface with SARS. Listed categories: Tax Returns Submissions (ITR12, ITR14, IRP6, ITR12T, PAYE returns), Transfer Duty, and Tax Directive requests. Access is by a unique access key issued per product/application to an active eFiling user with the right permissions. To register for the returns category the vendor emails signed Terms and Conditions (21 June 2017 version) to SARS; Tax Directives use Form INF001 and interface specification IBIR-006. **VAT201 is not named on the SARS ISV page.** No developer portal, sandbox, OpenAPI document or public spec is published. HIGH that nothing public is published; MEDIUM that VAT201 is in practice supported (next point).
3. **In practice, commercial vendors do file VAT201 through the ISV channel**: Sage ("VAT201 Electronic Submission", requires activating Sage as an ISV in eFiling: Organisation > ISV Activation > ISV Application, SARS then issues a login key), Konsise (monthly and two-monthly VAT201 only; nil returns in bulk; SARS processes queued submissions, typically overnight, with an email on completion; payment is still made on eFiling), Fintura (claims "Official SARS ISV Partner", XML schema validation, VAT201 and EMP201), DataGrows (ISV key per organisation-type portfolio; bulk IRP6; correspondence download). Sources: Sage KB article, konsise.com, fintura.io, mydatagrowsblog.com. LOW to MEDIUM (vendor pages, not SARS). I could not obtain the VAT201 message specification; it is not public.
4. **What this means for Dot.Finance**: (a) a software vendor cannot just register for an API key online; it must be accepted into the ISV programme by SARS (apply, sign Terms and Conditions, receive the VAT-specific interface specification and test arrangements privately). (b) The end user, not the vendor, authorises access by activating the ISV on their own eFiling organisation and pasting the key into the software. (c) Payment of the VAT is not done through the ISV channel; the user pays via eFiling/EFT using the PRN. (d) Sage's help text suggests the electronic route is for submission of the return, with restrictions on later revisions (a Sage blog says only once; unverified, treat as LOW). (e) Biometric verification of registered representatives (Fintura) and SARS's eFiling profile requirements stay outside the software.
5. **Recommended phased design**: Phase A (no SARS dependency): calculate VAT, produce a VAT201 working paper that reproduces SARS field numbers 1-20 plus transaction drill-down, export PDF/CSV, show a "capture these numbers on eFiling" checklist, record filed-status and PRN manually. Phase B: build a `SarsFilingGateway` port with a stubbed adapter; begin the ISV application with SARS in parallel (owner action: email the ISV desk on the address that is obfuscated on the SARS page; ask for the VAT201/EMP201 specification and a test environment). Phase C: real adapter, with idempotent submission records, status polling, and the overnight-processing delay.
6. **E-invoicing (future)**: SARS's VAT Modernisation Consultation Paper (August 2026, comments closed 16 October 2026) proposes a phased "digital VAT model": Phase 1 preparation 2026/27, Phase 2 solution development 2027/28, Phase 3 QA testing 2028/29, Phase 4 pilot 2029/30, Phase 5 phased implementation starting during the 2030 calendar year over about 36 months, with large taxpayers volunteering first and mandatory adoption later. Based on Peppol-style interoperability (accredited service providers/Access Points); not yet law. Earlier press quoting "mandatory by 2028" or a "Central Tax Hub" is superseded or unconfirmed by this paper. VAT Connect 21 confirms the Act now contains enabling definitions (e-invoice, e-credit note, e-debit note, interoperability framework, e-reporting) and a regulation-making power for a voluntary e-reporting system. HIGH. Design implication: keep invoices structured (UBL/PINT-ready data model, supplier and buyer identifiers, tax category codes) so a Peppol export can be added later.

---

## 3. Income tax

### 3.1 Companies and small business

- **Company rate 27%** for years of assessment ending 1 April 2023 onward (28% for years ending 1 April 2022 to 30 March 2023). Applies to YAs ending 1 April 2026 to 31 March 2027, unchanged in Budget 2026. SEZ companies may qualify for 15%. Source: https://www.sars.gov.za/tax-rates/income-tax/companies-trusts-and-small-business-corporations-sbc/ (last updated 28 Oct 2026). HIGH.
- **Financial year end**: companies choose any year end; a "year of assessment" for a company is its financial year. Rates are keyed by the YA **end date window** (1 April to 31 March), not the calendar. Individuals and trusts use the tax year 1 March to the last day of February.
- **SBC** (s12E): natural-person shareholders only, gross income no more than R20m, not a personal service company or holding company (Budget 2026 FAQs). Table in section 1. HIGH. SBC history: 2026 YA thresholds were R95,750 / R365,000 / R550,000 (R18,848 and R57,698 bases), now R99,000 first band (SARS page). Sole proprietors and partnerships are taxed as individuals.
- **Turnover tax** (micro business, Sixth Schedule): qualifying turnover up to R2.3m (limit increased from R1m on 1 April 2026); first update to the rate table since 2009 (R600,000 zero band instead of R335,000). Excludes professional services above 20% of receipts, personal service providers, labour brokers. Replaces income tax, provisional tax, CGT, dividends tax and (unless elected to stay) VAT. Restriction on tax year-end dates removed. Registration is open on SARS Online Query System (announced 6 Feb 2026). A vendor may be registered for both turnover tax and VAT. HIGH (Tax Guide), MEDIUM (some wording).
- **Individuals / sole proprietors** (2027 YA, 1 Mar 2026 to 28 Feb 2027): brackets are 18% to R245,100; R44,118 + 26% to R383,100; R79,998 + 31% to R530,200; R125,599 + 36% to R695,800; R185,215 + 39% to R887,000; R259,783 + 41% to R1,878,600; R666,339 + 45% above. 2026 and 2025 YAs used the 2024 table (18% to R237,100 ... 45% above R1,817,000; rebates R17,235 / R9,444 / R3,145; thresholds R95,750 / R148,217 / R165,689). The 2027 table is the first inflation adjustment (3.4%) since 2023/24. Source: https://www.sars.gov.za/tax-rates/income-tax/rates-of-tax-for-individuals/ (updated 28 Oct 2026) and the Budget 2026 Tax Guide. HIGH.
- Other individual items in the Budget 2026 Tax Guide: interest exemption R23,800 (under 65) / R34,500 (65+); tax-free savings R46,000 per year; retirement deduction 27.5% up to R430,000; trusts (non-special) 45%; CGT effective rates 18% individuals, 21.6% companies; annual exclusion R50,000; primary residence exclusion R3m; travel allowance and fringe-benefit rules; subsistence R595/day (meals) or R184/day (incidentals).

### 3.2 Provisional tax and returns

Source: SARS Guide for Provisional Tax GEN-PT-01-G01 revision 28, effective 16 Oct 2026 (read directly). HIGH.

- Companies are provisional taxpayers automatically. Individuals are if they have non-remuneration income (with exclusions: no business income and taxable income below the threshold, or investment-type income of R30,000 or less).
- Due dates: first payment within 6 months of the start of the YA; second by the last day of the YA; optional third (top-up) 7 months after YA end for 28/29 Feb year-ends (30 September) or 6 months for other year ends. Worked examples in the guide: Feb 2026 YE: 31 Aug 2025 / 28 Feb 2026 / 30 Sep 2026; 31 May 2026 YE: 30 Nov 2025 / 31 May 2026 / 30 Nov 2026. Business-day convention applies.
- Amounts: first period is half the estimated annual tax (less employees' tax for six months, foreign tax credits and rebates); second period is full-year estimate less credits and first payment; third is full liability less all prior payments. Capital gains are included in both estimates.
- Estimate rules: the estimate may not be below the **basic amount** (last assessed taxable income, assessed at least 14 days before submission, less taxable capital gain and certain lump sums), increased by 8% per year if the estimate is made more than 18 months after the end of the last assessed YA. Underestimation penalty (paragraph 20): taxable income **above R1m**: 20% of the difference between tax on 80% of actual taxable income and tax already paid; **up to R1m**: penalty if the estimate is under 90% of actual and under the basic amount, 20% of the difference between tax on the lesser of 90% of actual and the basic amount and tax paid. Late payment penalty 10% on first and second periods. No penalty on the third. If no final estimate within 4 months of YE, the taxpayer is deemed to estimate nil. Interest is charged at the prescribed rate (10.25% from 1 Dec 2025).
- **Correction to the shorthand in the brief**: "80%/90%" is not a rule about the payment itself; it is the penalty trigger (80% above R1m, 90% up to R1m). Any simplistic "pay 90%" prompt is wrong.
- Returns: companies file the **ITR14** within 12 months of year end; **IRP6** for provisional periods; individuals file **ITR12** in the annual filing season (2026 season for non-provisional taxpayers closed 23 Oct 2026; provisional taxpayers and trusts to 22 Jan 2027, per SARS media release; MEDIUM on exact windows).
- Admin non-compliance penalty (late return): a fixed monthly amount between R250 and R16,000 depending on the previous year's taxable income or assessed loss, recurring monthly up to 35 months; remission via a Request for Remission. The SARS pages confirm the range, recurrence and the 35 months; the band table (secondary sources: R250 up to R250,000; R500 to R500,000; R1,000 to R1m; R2,000 to R5m; R3,000 to R10m; R4,000 to R50m; R16,000 above R50m) is LOW-MEDIUM. Understatement penalties (TAA Chapter 16, 25% to 200%) exist but were not researched.
- Interest (TAA s187-s189): daily compound at the prescribed rate; not deductible.

### 3.3 Capital allowances, assessed loss, dividends (high level)

- Wear and tear s11(e): allowance over the asset's useful life; SARS accepts write-off periods listed in Binding General Ruling 7 / Interpretation Note 47 Issue 5 (9 Feb 2021); cost excludes finance charges; cannot be used where another section applies (s12B renewable energy, s12C manufacturing, s12DA, s12E SBC, s37B). s12E SBC: 100% write-off of manufacturing plant and a 50/30/20 allowance on other assets (the older text I saw says SBCs choose between 50:30:20 and s11(e) for s12E(1A) assets, MEDIUM). Government-funded assets get no allowance. HIGH on structure, MEDIUM on percentages.
- **Assessed loss** (s20): a company's assessed loss is carried forward while the entity trades; set-off is limited to R1m plus 80% of the balance of taxable income (my knowledge of the post-2022 rule; not re-confirmed on a SARS page today: MEDIUM). Ring-fencing rules apply to individuals' trades (s20A).
- **Dividends tax** 20%, withheld by the paying company or regulated intermediary; companies and retirement funds are exempt beneficiaries; payment due by the end of the month following the month of payment (s64K; MEDIUM, from knowledge). Foreign dividends: maximum effective rate about 20% for less-than-10% holdings.
- STC history is irrelevant (replaced by dividends tax in 2012).

---

## 4. Employment taxes

### 4.1 PAYE

- 2027 tax tables apply from 1 March 2026 (Guide for Employers in respect of Employees' Tax 2027, https://www.sars.gov.za/guide-for-employers-in-respect-of-employees-tax-2027/). Annual brackets, rebates and thresholds are in section 3.1. Monthly deduction is annualised per SARS tax deduction tables, with the medical scheme fees tax credit (R376, R376, R254 per month) and the additional medical expenses credit. Fringe-benefit and allowance rules (travel 80% inclusion, employer vehicle 3.5% of cash cost per month, R4.95/km no-tax rate, subsistence R595/R184) are in the Budget 2026 Tax Guide. HIGH.
- Payment ("7th rule"): PAYE, SDL and UIF are paid with the monthly **EMP201** by the 7th day after the end of the month of deduction; if the 7th is a weekend or public holiday, the last business day before it. Late payment: 10% penalty plus interest. Wilful non-payment of PAYE can attract up to twice the amount as a penalty and criminal sanctions. HIGH.
- **EMP501** employer reconciliation: interim (1 March to 31 August; submission 21 September to 31 October 2026) and annual (1 March to end February; submission 1 April to 31 May; the 2027 annual window is inferred from the standing calendar and previous notices, MEDIUM). Via e@syFile Employer or eFiling (the eFiling limit is 50 certificates per submission on the notice; confirm). Valid income-tax numbers for employees are strictly enforced since the February 2026 filing season. Late EMP501 penalty: 1% of annual PAYE, plus 1% per month outstanding, capped at 10% (SARS notice; the quoted sentence looked incomplete, MEDIUM). Source: https://www.sars.gov.za/latest-news/employer-interim-declarations-emp501-21-september-to-october-2026/ . Prepopulated IRP5/IT3(a) values must reconcile to EMP201 payments.
- **IRP5/IT3(a)** certificates are issued to employees per the prescribed periods (annual certificates after the annual reconciliation; deadlines not extracted today, MEDIUM). The SARS BRS for reconciliation 2026-08 is V25.3.0, a useful hint for a future ISV integration.

### 4.2 UIF, SDL, ETI

- **UIF**: 1% employee plus 1% employer on remuneration up to R17,712 per month. Maximum R177.12 per party (R354.24 combined). Ceiling effective 1 June 2021 per https://www.sars.gov.za/types-of-tax/unemployment-insurance-fund/ . Several calculator sites claim a 2026 increase; I found no official evidence and one of them labels it a myth. Treat 2026 as unchanged and put it on a watch list. Employees working fewer than 24 hours a month, and certain public officers, are excluded. HIGH.
- **SDL**: 1% of total remuneration; payroll under R500,000 per annum exempt (an employer that reasonably expects no more than R500,000 over the next 12 months need not register). HIGH.
- **ETI**: described in section 1. Claims are made monthly on the EMP201; non-compliance (for example any employee paid below the minimum wage) can disqualify the whole month's claim; unused ETI can be forfeited if returns are not submitted; the minimum wage test uses R2,500 apportioned over 160 hours. The Act 3 of 2026 retrospective amendment applies from 1 April 2025. Sunset 28 February 2029. HIGH. Source: SARS ETI Guide Issue 5 (https://www.sars.gov.za/legal-pub-guide-eti01-guide-to-the-employment-tax-incentive/).
- **Minimum wage**: R30.23 per hour from 2 March 2026 (previously R28.79). MEDIUM.

### 4.3 COIDA (Compensation Fund)

- Employers register with the Compensation Fund and pay an annual assessment on earnings (capped at a maximum per employee) using an industry-class tariff. 2026/27 maximum earnings R668,000 (was R633,168), minimum assessment R1,621, domestic employers R560, effective 1 March 2026 to 28 February 2027 (General Notice 3910, Government Gazette 54577). Return of Earnings (W.As.8) window 1 April to 30 June 2026; penalty up to 10% of the assessment for failure; Letter of Good Standing only after the ROE and payment (or arrangement). Amendments effective 1 February 2026 replaced some criminal offences with administrative penalties. Tariff rates vary by class (2025/26 range roughly 0.18% to 3.34%, LOW because secondary). Sources: https://acts.co.za/news/blog/2026/04/coida-2026-roe-submission-dates ; https://accountingacademy.co.za/news/read/coida-new-max-and-min-amount-of-earnings-2026-2027 . MEDIUM. Not a SARS tax; keep it in a separate `regime = COIDA` namespace. Dot.Finance should show it as a reminder and an estimate only.

---

## 5. Other taxes, calendar, records, POPIA

- **Withholding**: dividends 20% (section 3.3); royalties 15%; interest to non-residents 15% (exemptions for banks, government, listed debt); foreign entertainers and sportspersons 15%; non-resident immovable-property sellers 7.5% / 10% / 15%. Treaty relief may reduce. HIGH (Budget 2026 Tax Guide).
- **Other levies**: securities transfer tax 0.25%; donations tax 20% up to R30m, 25% above, R150,000 annual exemption for natural persons (R20,000 for non-persons from 2026); estate duty 20% / 25%; transfer duty bands start at R1,210,000. Carbon tax R308 per tonne from 1 January 2026. HIGH (out of scope for a first release).
- **SA business tax calendar (summary)**:

| Frequency | Obligation | Due |
|---|---|---|
| Monthly | EMP201 (PAYE, UIF, SDL, ETI) | 7th of next month (previous business day) |
| Monthly or bi-monthly or other | VAT201 | 25th (manual) or last business day (eFiling) of month after period |
| Twice a year plus top-up | Provisional tax IRP6 | 6 months into YA; last day of YA; 3rd 6/7 months after YA end |
| Dividend declaration | Dividends tax | by end of month after the month of payment (MEDIUM) |
| Twice a year | EMP501 reconciliation | interim 21 Sep to 31 Oct (period 1 Mar to 31 Aug); annual 1 Apr to 31 May |
| Annually | ITR14 | within 12 months of year end |
| Annually | COIDA ROE | 1 Apr to 30 Jun (2026 window) |
| Annually | Annual financial statements and Companies Act annual return | separate (CIPC) rules: not researched |

- **Record keeping**: five years (TAA s29: from the date the return is submitted, or from the end of the tax period where no return is required; VAT Act: 5 years). The Companies Act s24 sets 7 years for company records; MOI and registers kept indefinitely (secondary sources; MEDIUM). Records can be kept electronically if accessible and unaltered. Longer retention applies for capital gains computation or SARS disputes.
- **POPIA**: s14 limits retention of personal information to what is needed for the purpose, except where law requires or allows longer. The SARS (5 years) and Companies Act (7 years) retention periods are the legal basis for keeping financial records that contain personal data (suppliers, customers, employees). Design: retention policy per record type with a legal-hold flag; deletion or de-identification job after the longest applicable period; role-based access to payroll and ID-number fields; operator agreements for cloud processing. MEDIUM (secondary sources, not the statute text).

---

## 6. Expense-category review prompts (NOT advice)

Use as "worth reviewing" prompts attached to expense categories, with the rule reference and an "ask your tax practitioner" disclaimer. The generic test is: expenditure incurred in the production of income, in the carrying on of a trade, and not of a capital nature (Income Tax Act s11(a)), subject to the prohibitions in s23.

| Category | Income tax prompt | VAT prompt |
|---|---|---|
| Entertainment (client meals, golf, hospitality), staff functions and canteens | Often not deductible (s23(b) prohibits entertainment, with exceptions such as entertainment provided to the public for a charge, or amounts taxed to the employee as a benefit); review | Input tax usually denied (s17(2)(a)); exceptions: overnight business subsistence, seminar meals in the price |
| Fines and penalties (traffic, regulatory), bribes and corrupt payments | Not deductible when arising from an unlawful act (s23(o)); Tax Court (April 2026) applied this to kickbacks. SARS tax penalties and interest are not deductible (s23(d)) | No VAT on fines (non-supply) |
| SARS interest and penalties; interest on provisional tax underpayment | Not deductible (guide confirms for provisional tax interest) | n/a |
| Private or domestic expenses, home-office cost | Private is not deductible (s23(a)); home-office deduction needs a specifically equipped room regularly and exclusively used for trade (s11(a)/s23(b)); review | Claim only the business portion |
| Motor vehicles (purchase, lease, fuel, repairs) | Wear and tear (s11(e)) or lease deduction subject to value caps and logbook; company-car fringe benefit for the employee; review | Input tax on acquiring a "motor car" denied; running costs claimable on a business-use basis with valid invoices |
| Travel and subsistence | Employee reimbursements within SARS prescribed rates (R595 / R184 per day, R4.95 per km no-tax rate) are not taxed; employer deduction needs proof | Subsistence claimable only for the overnight-business exception, not as an allowance |
| Salaries, wages, bonuses, directors' fees | Deductible when incurred; subject to PAYE, UIF, SDL, ETI; check regular vs irregular items, loans to directors (s7C, interest-free loan fringe benefit at the official rate 7.75% from 1 Dec 2025) | Outside VAT scope |
| Rent (commercial) | Deductible; check residential leases | Standard-rated if the landlord is a vendor; residential rent is exempt (no input tax) |
| Software and SaaS subscriptions, hosting | Generally deductible revenue; software acquired outright may be capital (wear and tear or s11(e) schedule) | Foreign supplier: registered e-services supplier charges SA VAT; otherwise consider imported services (VAT215, field 12) |
| Bank charges and interest | Interest generally deductible if on a loan used in the business; s24J and s23M/s23N limits for related-party or acquisition debt, s11G for non-trading interest limits; review | Financial services exempt: no input tax on interest, but bank fee VAT where the bank charges VAT should be reviewed |
| Insurance (short-term) | Premiums on business assets generally deductible | Claimable only if a taxable premium was charged (VAT Connect 21); life/funeral cover exempt |
| Medical aid and group risk | Employer contribution is a taxable fringe benefit; contributions deductible as employee cost | VAT on medical scheme cover denied (s17(2)) |
| Donations | Deductible only for s18A-approved PBOs with a receipt, up to 10% of taxable income | Not VAT supplies |
| Gifts to clients | Review for s11(a) link to income; food and drink is entertainment | Gifts are deemed supplies if input tax was claimed (output adjustment, field 10-11 area) |
| Professional fees (legal, accounting) | Deductible if revenue in nature and trade-related; legal costs of capital or restructuring items are capital | Claimable with a valid invoice |
| Training and learnerships | Training for employees is generally deductible; learnership additional allowance only for agreements entered before 1 April 2024 | Claimable |
| Capital equipment and furniture | Capital: wear and tear / s12B / s12E; no immediate deduction except SBC and listed sections | Capital goods input tax claimed in field 14 when the invoice is held |
| Repairs vs improvements | Repairs are deductible; improvements that add value or extend life are capital | Claimable in either case |
| Bad debts and doubtful debts | Specific bad debts (s11(i)) and a limited doubtful-debt allowance (s11(j)) | Output/input adjustment via fields 12 / 17 (see 2.8) |
| Club memberships | Review (often private) | Input tax denied |
| Accounting depreciation | Add back; replace with wear and tear | n/a |
| Pre-incorporation expenses | Specific rules apply (s11A) | VAT 404 s8.7 |

Each prompt must display the source (Act section or SARS document) and the effective date, and must be disabled or flagged when the underlying rule row is `legal_status != enacted` or past `valid_to`.

---

## 7. Proposed data model: versioned, jurisdiction-aware rule table

Principles:

1. **Effective-dated and append-only**: never update a rule value; insert a new row with a new `valid_from` and close the previous row's `valid_to`. Look rules up by the *tax point* (transaction date, supply date, year-of-assessment end date, period end), never by "today".
2. **Different "clocks"** for different taxes: VAT by transaction/time of supply; individual income tax by tax year (1 Mar - end Feb); company tax by YA **end date**; payroll by pay period; provisional tax by YA start/end. Store `period_basis` on each rule family.
3. **Legal status**: `enacted | announced | proposed | draft | repealed`. SARS applied the 2026 VAT thresholds before promulgation. The engine should use `enacted` or `announced+applied_by_authority` for calculation and refuse `proposed`.
4. **Provenance**: every row carries `source_url`, `source_ref` (document number, gazette, section), `accessed_at`, `confidence`, `entered_by`, `reviewed_by`, `review_due`.
5. **Structured values**: scalar rules use typed value columns; bands and formulas live in child tables; form layouts (VAT201 boxes) are versioned like rules.
6. **Jurisdiction hierarchy** with fallback: `ZA` < region/province < municipality (for future states/counties); `EU` < member state; rules resolve most-specific first.
7. **No calculation in rule rows**: expressions are data (a small, safe expression language), executed by an engine with a test corpus ("golden returns") per rule version.

Suggested tables (PostgreSQL flavour; Laravel migrations to follow):

```sql
create table tax_jurisdiction (
  code            text primary key,          -- 'ZA', 'GB', 'EU', 'KE', 'NG', 'BW', 'US-CA'
  name            text not null,
  parent_code     text references tax_jurisdiction(code),
  currency        text not null,             -- ISO 4217
  fiscal_calendar jsonb,                     -- default tax-year convention, holidays source
  created_at      timestamptz default now()
);

create table tax_rule_set (                  -- groups rules into a regime
  id            bigserial primary key,
  jurisdiction  text not null references tax_jurisdiction(code),
  tax_type      text not null,               -- VAT | CIT | PIT | PAYE | UIF | SDL | ETI | PROVISIONAL | TURNOVER_TAX | WHT | DIVIDENDS | COIDA | RECORDS
  period_basis  text not null,               -- TAX_POINT | TAX_YEAR | YA_END | PAY_PERIOD | CALENDAR
  unique (jurisdiction, tax_type)
);

create table tax_rule (
  id            bigserial primary key,
  rule_set_id   bigint not null references tax_rule_set(id),
  rule_key      text not null,               -- 'vat.standard_rate', 'vat.registration.compulsory_threshold'
  value_type    text not null,               -- DECIMAL | MONEY | PERCENT | INTEGER | TEXT | BOOLEAN | DATE_RULE | JSON
  value_num     numeric(20,6),
  value_text    text,
  value_json    jsonb,
  unit          text,                        -- 'ZAR', 'percent', 'months', 'days'
  applies_to    jsonb,                       -- qualifiers: {"category":"A"}, {"age_band":"65-74"}, {"entity":"company"}
  valid_from    date not null,
  valid_to      date,                        -- null = open ended
  legal_status  text not null default 'enacted',
  source_url    text not null,
  source_ref    text,                        -- 'GEN-ELEC-04-G01 rev 11 s7.6; VAT Act s7(1)'
  accessed_at   date not null,
  confidence    text not null,               -- HIGH | MEDIUM | LOW
  note          text,
  supersedes_id bigint references tax_rule(id),
  recorded_at   timestamptz default now(),
  reviewed_by   text,
  review_due    date,
  exclude using gist (rule_set_id with =, rule_key with =, applies_to with =, daterange(valid_from, valid_to, '[)') with &&)
);

create table tax_bracket (                   -- progressive tables, SBC, turnover tax, lump sums, transfer duty
  id           bigserial primary key,
  rule_id      bigint not null references tax_rule(id),   -- rule_key 'pit.rates', 'sbc.rates', 'turnover_tax.rates'
  lower        numeric(20,2) not null,
  upper        numeric(20,2),
  base_amount  numeric(20,2) not null default 0,
  rate         numeric(9,6) not null
);

create table tax_calendar_rule (             -- due dates
  id             bigserial primary key,
  rule_id        bigint not null references tax_rule(id),
  obligation     text not null,              -- 'VAT201', 'EMP201', 'IRP6_1', 'IRP6_2', 'IRP6_3', 'ITR14', 'EMP501_INTERIM', 'EMP501_ANNUAL', 'COIDA_ROE'
  anchor         text not null,              -- PERIOD_END | YA_START | YA_END | FIXED_MONTH_DAY
  offset_months  int,
  offset_days    int,
  day_rule       text not null,              -- 'DAY_OF_MONTH:25' | 'LAST_BUSINESS_DAY' | 'DAY_OF_MONTH:7'
  business_day_convention text not null,     -- PREVIOUS | NEXT | NONE
  channel        text,                       -- EFILING | MANUAL | ANY
  exceptions     jsonb                       -- e.g. {"year_end_month":2,"offset_months":7}
);

create table tax_form_definition (           -- VAT201, EMP201, IRP6 layouts
  id            bigserial primary key,
  jurisdiction  text not null references tax_jurisdiction(code),
  form_code     text not null,               -- 'VAT201'
  revision      text not null,               -- 'rev 11'
  valid_from    date not null,
  valid_to      date,
  source_url    text not null,
  unique (jurisdiction, form_code, revision)
);
create table tax_form_box (
  id            bigserial primary key,
  form_id       bigint not null references tax_form_definition(id),
  box_code      text not null,               -- '1','1A','2A','4','13','14A','20'
  label         text not null,
  kind          text not null,               -- INPUT | CALC | PAYMENT
  formula       text,                        -- 'box.1 * rule(vat.standard_rate) / (1 + rule(vat.standard_rate))'
  editable      boolean default false,
  mandatory_if  text,                        -- 'box.2A > 0 => customs_code'
  sort_order    int
);

create table tax_document_requirement (      -- invoice profiles
  id            bigserial primary key,
  jurisdiction  text not null,
  profile       text not null,               -- 'FULL_TAX_INVOICE' | 'ABRIDGED' | 'CREDIT_NOTE' | 'DEBIT_NOTE'
  applies_when  jsonb,                       -- {"consideration_gt":5000,"currency":"ZAR"}
  required_fields jsonb not null,            -- list of field codes
  valid_from    date not null, valid_to date,
  source_url    text not null
);

create table tax_code (                      -- Dot.Finance VAT/tax codes mapped to rules
  id            bigserial primary key,
  jurisdiction  text not null,
  code          text not null,               -- 'ZA_VAT_STD', 'ZA_VAT_ZERO', 'ZA_VAT_EXEMPT', 'ZA_VAT_CAPITAL', 'ZA_VAT_IMPORT'
  rate_rule_key text,                        -- resolves rate through tax_rule at transaction date
  deductible_policy text,                    -- ALLOWED | DENIED_ENTERTAINMENT | DENIED_MOTOR_CAR | APPORTION
  return_box    text,                        -- 'VAT201:1' (output) or 'VAT201:15' (input)
  valid_from    date not null, valid_to date
);

create table tax_review_prompt (             -- section 6 guidance, never advice
  id            bigserial primary key,
  jurisdiction  text not null,
  expense_category text not null,
  tax_type      text not null,
  prompt_text   text not null,
  rule_refs     text[],                      -- 'ITA s23(b)', 'VAT s17(2)(a)'
  valid_from    date not null, valid_to date,
  source_url    text not null
);

create table tax_public_holiday (
  jurisdiction text not null, holiday_date date not null, name text,
  primary key (jurisdiction, holiday_date)
);
```

Illustrative seed rows (all `jurisdiction=ZA`, `legal_status=enacted` unless stated, `accessed_at=2026-11-09`):

| rule_set | rule_key | value | valid_from | valid_to | source_url | confidence |
|---|---|---|---|---|---|---|
| VAT | vat.standard_rate | 0.15 | 2018-04-01 | null | https://www.sars.gov.za/tax-rates/value-added-tax/ | HIGH |
| VAT | vat.standard_rate (history) | 0.14 | 1993-?? | 2018-03-31 | VAT201 guide s7 (history only, do not seed pre-2018 without verification) | MEDIUM |
| VAT | vat.registration.compulsory_threshold | 2300000 ZAR | 2026-04-01 | null | Budget 2026 FAQs; Budget 2026 Tax Guide | HIGH |
| VAT | vat.registration.compulsory_threshold | 1000000 ZAR | 2009-03-01 (verify) | 2026-03-31 | Budget 2026 FAQs | MEDIUM |
| VAT | vat.registration.voluntary_threshold | 120000 ZAR | 2026-04-01 | null | same | HIGH |
| VAT | vat.registration.voluntary_threshold | 50000 ZAR | (verify) | 2026-03-31 | same | MEDIUM |
| VAT | vat.accommodation.long_stay_pct | 0.60 | (verify) | null | VAT201 guide field 6 | HIGH |
| VAT | vat.payments_basis.turnover_cap | 2500000 ZAR | (verify) | null | VAT 404 s4.3 | MEDIUM |
| VAT | vat.late_payment_penalty_pct | 0.10 | (verify) | null | VAT 404 ch.11 | HIGH |
| VAT | vat.invoice.full_threshold | 5000 ZAR (flag boundary) | (verify) | null | VAT 404 s13.1 | HIGH |
| VAT | vat.invoice.min_invoice_threshold | 50 ZAR | (verify) | null | VAT 404 s13.1 | HIGH |
| VAT | vat.input_claim_window_years | 5 | (verify) | null | VAT 404 s8.3.6 | HIGH |
| VAT | vat.category_C.turnover_trigger | 30000000 ZAR | (verify) | null | SARS tax periods page | HIGH |
| CIT | cit.rate | 0.27 | YA ending 2023-03-31 | null | SARS rates page | HIGH |
| CIT | cit.rate | 0.28 | YA ending 2022-04-01 | YA ending 2023-03-30 | same | HIGH |
| CIT | sbc.rates | brackets 0/99000; 7%; 18620+21%/365000; 57470+27%/550000 | YA ending 2026-04-01 | YA ending 2027-03-31 | same | HIGH |
| TURNOVER_TAX | tot.qualifying_limit | 2300000 ZAR | 2026-04-01 | null | Budget 2026 Tax Guide | HIGH |
| TURNOVER_TAX | tot.rates | brackets 0/600000; 1%/950000; 3500+2%/1400000; 12500+3% | 2026-03-01 (individuals) | 2027-02-28 | Budget 2026 Tax Guide | HIGH |
| PIT | pit.rates | 2027 table (section 3.1) | 2026-03-01 | 2027-02-28 | SARS individual rates page | HIGH |
| PIT | pit.rebate.primary | 17820 | 2026-03-01 | 2027-02-28 | same | HIGH |
| PAYE | paye.medical_credit.main | 376 / 254 | 2026-03-01 | 2027-02-28 | Budget 2026 Tax Guide | HIGH |
| UIF | uif.rate_employee / employer | 0.01 / 0.01 | (verify) | null | SARS UIF page | HIGH |
| UIF | uif.ceiling_monthly | 17712 ZAR | 2021-06-01 | null | https://www.sars.gov.za/types-of-tax/unemployment-insurance-fund/ | HIGH |
| SDL | sdl.rate / sdl.payroll_threshold | 0.01 / 500000 | (verify) | null | Budget 2026 Tax Guide | HIGH |
| ETI | eti.max_first_12 / second_12 | 1500 / 750 | 2025-04-01 | 2029-02-28 | ETI Guide Issue 5 | HIGH |
| ETI | eti.remuneration_cap / floor | 7500 / 2500 | 2025-04-01 | 2029-02-28 | same | HIGH |
| PROVISIONAL | pt.penalty_underestimate_pct | 0.20 | (verify) | null | GEN-PT-01-G01 rev 28 | HIGH |
| PROVISIONAL | pt.penalty_late_pct | 0.10 | (verify) | null | same | HIGH |
| PROVISIONAL | pt.estimate_floor_pct_over_1m / under_1m | 0.80 / 0.90 | (verify) | null | same | HIGH |
| INTEREST | sars.interest_rate | 0.1025 | 2025-12-01 | null (watch gazette) | Budget 2026 Tax Guide; PT guide | HIGH |
| DIVIDENDS | dividends_tax.rate | 0.20 | (verify; since 2017-02-22 from knowledge) | null | Budget 2026 Tax Guide | HIGH |
| RECORDS | records.retention_years.tax | 5 | (standing) | null | TAA s29; VAT 404 | HIGH |
| COIDA | coida.max_earnings | 668000 ZAR | 2026-03-01 | 2027-02-28 | GN 3910 (GG 54577) via acts.co.za | MEDIUM |

Where I wrote "(verify)" for `valid_from` the current value is certain but the historic start date was not researched; seed those with `valid_from` set to the earliest date Dot.Finance needs (for example 2024-03-01) and note "earlier history not modelled".

Engine behaviours worth building from day one:

- `resolve(rule_key, as_of, qualifiers) -> value + provenance`; calculation results store the rule version IDs used, so a return can be re-explained later.
- A **rule-diff job**: on each Budget (late February) and each SARS guide revision, create draft rule versions, show a diff, require a named human to approve (`reviewed_by`), and alert filers whose pending periods straddle the change.
- A **watch list** for facts known to move: prescribed interest rate (gazetted with the repo rate), UIF ceiling, COIDA tariffs (each March), ETI (annual legislative amendment), VAT payments-basis cap, e-invoicing mandate dates.
- Golden-file tests per VAT201 field using the SARS guide's formulas, including a straddle period across two rates.
- Money: store in minor units or `numeric(20,2)`; SARS rounds to whole rand on VAT201 fields in practice (VAT201 accepts rands; confirm rounding rule before building). LOW: not verified.

---

## 8. Extending to other jurisdictions (brief)

Architecture: treat tax as `jurisdiction + tax_type + rule_key + valid_from/valid_to`, with a **place-of-supply resolver** deciding which jurisdiction's rules apply to a transaction (seller establishment, buyer location, B2B vs B2C, goods vs services, digital services). Keep a **return adapter** per authority (form layout + filing gateway), so the same ledger codes feed a SA VAT201, a UK MTD VAT return (nine boxes, submitted over the HMRC REST API with registered developer credentials and a sandbox), or an EU OSS return. Allow multi-rate and multi-currency (tax amounts in the authority's currency, with the exchange-rate source and date recorded). Never assume one VAT rate, one registration threshold, or one filing period.

Jurisdiction notes (LOW to MEDIUM; secondary or vendor sources, verify with each authority before seeding):

- **UK VAT**: HMRC runs a public Developer Hub with RESTful Making Tax Digital APIs, a sandbox, and published terms of collaboration for software developers (https://www.gov.uk/government/collections/register-as-a-software-developer ; https://www.gov.uk/government/publications/hmrc-third-party-tax-software-and-api-strategy/hmrc-third-party-tax-software-and-application-programming-interface-api-strategy). This is the opposite of SARS's closed ISV model and shows the target interface for a `FilingGateway` port. From my own knowledge (not re-verified today): standard rate 20%, registration threshold £90,000 since April 2024.
- **EU VAT / OSS**: the EU-wide €10,000 threshold for cross-border B2C distance sales replaced national thresholds on 1 July 2021; OSS (Union), Non-Union OSS and IOSS schemes file quarterly (IOSS monthly) with payment due by the end of the following month; the seller charges the VAT rate of the destination member state. Need a per-member-state rate and goods/services classification table, plus an "VAT ID validation (VIES)" check for B2B reverse charge. Sources: https://www.kvk.nl/en/international/vat-rules-for-e-commerce-in-the-eu/ ; https://support.quaderno.io/article/536-eu-vat-one-stop-shop-schemes-oss-and-ioss (secondary).
- **US sales tax**: not a VAT; no federal tax; thousands of state, county and city jurisdictions; since *South Dakota v. Wayfair* (2018) states impose economic nexus (commonly $100,000 or 200 transactions, with exceptions such as New York), destination versus origin sourcing differs by state, product taxability varies, and rate lookup by rooftop address is normally bought from a tax-engine provider. Model it as `tax_jurisdiction` with many children, nexus tracking per state, and a pluggable rate provider. Sources are vendor blogs (https://www.avalara.com/us/en/blog/the-impact-of-economic-nexus-on-sales-tax-and-income-tax.html, https://trykintsugi.com/blog/ecommerce-sales-tax); verify against state revenue departments and the Streamlined Sales Tax project.
- **Kenya / Nigeria / Botswana**: Kenya VAT 16% with a KES 5m registration threshold reported by guides, but sources conflict on whether the threshold was removed or proposed for removal, and filing is on KRA iTax; Nigeria VAT 7.5% retained under the Nigeria Tax Act 2025 effective 1 January 2026 with broader zero-rating and input-credit recovery (e-invoicing status not confirmed); Botswana VAT 14% with a P1,000,000 mandatory registration threshold reported, and VAT on non-resident digital services from 1 June 2026 under the Value Added Tax Act 2026. Sources: https://trykintsugi.com/sales-tax-guides/africa/kenya ; https://trykintsugi.com/sales-tax-guides/africa/nigeria ; https://www.vatcalc.com/botswana/botswana-vat-on-non-resident-digital-services . All commercial and unverified; do not seed without checking KRA, FIRS/NRS and BURS.

---

## 9. Open questions and flags for the owner

1. **SARS ISV application**: the VAT201 and EMP201 message specifications are not public. The owner (or the product lead) needs to apply to the SARS ISV desk (address obfuscated on the SARS ISV page) before Phase C. Decide whether to rely on a third-party filing partner instead.
2. **R120,000 voluntary threshold** legal status: promulgated 1 April 2026 per VAT Connect 21 (Act 3 of 2026, GG 54446 as cited by that page) but the same page still says "proposed". Re-check the Act text before declaring HIGH.
3. **EMP501 annual 2027 window** and **IRP5 issue deadlines**: confirm when SARS publishes the 2027 notice (likely early 2027).
4. **UIF ceiling**: R17,712 since 1 June 2021 per SARS; some sources claim a 2026 increase. Watch the Government Gazette.
5. **VAT payments-basis cap (R2.5m)** and **foreign e-services registration threshold (R1m)**: not verified against 2026 changes.
6. **Assessed loss 80% limitation, dividends-tax payment date, s12E rates, admin penalty band table**: from knowledge or secondary sources, MEDIUM or LOW. Confirm before seeding.
7. **Interest rate**: SARS rate 10.25% is correct as published for Dec 2025 to Oct 2026; the next gazetted change should be picked up by the watch list.
8. **Tooling caveat**: some figures above came from a fetch tool that summarises pages. Where I could, I cross-checked against PDF text I extracted myself (flagged HIGH). Rows marked MEDIUM should be spot-checked against the cited URL by a human before production seeding.
9. **Disclaimer**: Dot.Finance should show a visible "guidance, not tax advice" statement, record the rule version used for each calculation, and let a registered tax practitioner override any value with a reason.

---

## 10. Source list (all accessed 2026-11-09 unless noted)

SARS and Treasury (primary):

- VAT overview and thresholds: https://www.sars.gov.za/tax-rates/value-added-tax/ ; https://www.sars.gov.za/types-of-tax/value-added-tax/
- Budget 2026 FAQs: https://www.sars.gov.za/about/sars-tax-and-customs-system/budget/budget-2026-frequently-asked-questions/
- VAT Connect Issue 21 (Sep 2026): https://www.sars.gov.za/businesses-and-employers/my-business-and-tax/vat-connect-issue-21-september-2026/
- Tax periods for VAT: https://www.sars.gov.za/types-of-tax/value-added-tax/tax-periods-for-vat-vendors/
- Obligations of a VAT vendor: https://www.sars.gov.za/types-of-tax/value-added-tax/obligations-of-a-vat-vendor/
- VAT201 guide GEN-ELEC-04-G01 rev 11: https://www.sars.gov.za/gen-elec-04-g01-guide-for-completing-the-value-added-tax-vat201-declaration-external-guide
- VAT 404 Guide for Vendors Issue 15: https://www.sars.gov.za/wp-content/uploads/Ops/Guides/Legal-Pub-Guide-VAT404-VAT-404-Guide-for-Vendors.pdf
- VAT Modernisation Consultation Paper (Aug 2026): https://www.sars.gov.za/vat-modernisation-consultation-paper-august-2026/
- Independent Software Vendors: https://www.sars.gov.za/individuals/i-need-help-with-my-tax/your-tax-questions-answered/independent-software-vendors/
- Individuals' rates: https://www.sars.gov.za/tax-rates/income-tax/rates-of-tax-for-individuals/
- Company/SBC rates: https://www.sars.gov.za/tax-rates/income-tax/companies-trusts-and-small-business-corporations-sbc/
- Other taxes: https://www.sars.gov.za/tax-rates/other-taxes/
- Budget 2026 Tax Guide (Treasury archive): https://archive.treasury.gov.za/documents/national%20budget/2026/sars/Budget%202026%20Tax%20guide.pdf
- Small businesses: https://www.sars.gov.za/businesses-and-employers/small-businesses-taxpayers/
- Provisional tax: https://www.sars.gov.za/types-of-tax/provisional-tax/ ; guide https://www.sars.gov.za/wp-content/uploads/Ops/Guides/GEN-PT-01-G01-Guide-for-Provisional-Tax-External-Guide.pdf
- Calendar: https://www.sars.gov.za/individuals/i-need-help-with-my-tax/calendar/
- Admin penalty: https://www.sars.gov.za/individuals/what-if-i-do-not-agree/admin-penalty/
- PAYE guide 2027: https://www.sars.gov.za/guide-for-employers-in-respect-of-employees-tax-2027/
- UIF: https://www.sars.gov.za/types-of-tax/unemployment-insurance-fund/
- EMP501 interim 2026: https://www.sars.gov.za/latest-news/employer-interim-declarations-emp501-21-september-to-october-2026/
- ETI changes and guide: https://www.sars.gov.za/latest-news/employment-tax-incentive-eti-changes-with-effect-from-1-april-2025/ ; https://www.sars.gov.za/legal-pub-guide-eti01-guide-to-the-employment-tax-incentive/
- Interpretation Note 47 (wear and tear): https://www.sars.gov.za/wp-content/uploads/Legal/Notes/LAPD-IntR-IN-2012-47-Wear-And-Tear-Depreciation-Allowance.pdf
- Interpretation Note 54 (corrupt activities, fines and penalties): https://www.sars.gov.za/legal-intr-in-54-deductions-not-allowed-corrupt-activities-fines-and-penalties-and-fruitless-and-wasteful-expenditure/
- Tax Administration Act s29: https://www.acts.co.za/tax/29__duty_to_keep_records.php

Secondary (use with care):

- ISV vendor pages: https://www.konsise.com/vat201-integration-to-sars-efiling-with-konsise/ ; https://www.fintura.io/solutions/vat-submissions ; https://www.mydatagrowsblog.com/post/sars-datagrows-integration ; https://za-kb.sage.com/portal/app/portlets/results/view2.jsp?k2dockey=210702123130000
- COIDA: https://acts.co.za/news/blog/2026/04/coida-2026-roe-submission-dates ; https://accountingacademy.co.za/news/read/coida-new-max-and-min-amount-of-earnings-2026-2027
- 2025 VAT reversal commentary: https://www.cliffedekkerhofmeyr.com/news/publications/2025/Practice/Tax-Exchange-Control/tax-and-exchange-control-alert-29-april-suspension-of-vat-rate-increase-current-status-and-implications ; https://www.ewn.co.za/2025/05/21/budget-30-treasury-makes-u-turn-on-expanding-zero-rated-food-items-list
- Other jurisdictions (listed in section 8).
