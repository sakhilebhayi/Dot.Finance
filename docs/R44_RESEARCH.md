# R-44 Dot.Finance: Competitive Research

Researcher: competitive research agent. Date of research: 2026-11-09.
Scope: QuickBooks, Xero, Sage (Business Cloud / Accounting / Pastel), Zoho Books, FreshBooks, Wave, NetSuite, Odoo, Dynamics 365 Business Central, Pennylane, Hnry, Bokio, Float/Fathom, Pilot, Brex/Ramp, Karbon, and South African players (Sage SA, Xero SA, QuickBooks SA, Zoho SA, Yoco, Stub, Pastel).

## 0. How to read this document

Every factual claim carries a source tag such as [S4]. The tags resolve to URLs in section 11. Each tag also has a confidence grade:

- **[V] Verified**: the page was fetched and the claim read there.
- **[R] Reported**: the claim came from a search-result summary of the page, or from a secondary source, and the primary page was not read (blocked, empty, or not fetched). Treat as likely, re-check before building on it.
- **[U] Unverified**: inference, vendor marketing with no corroboration, or conflicting sources. Marked in text.

Method limits, stated plainly: I did not log in to any competitor product, so UX observations about screens come from vendor help pages, reviews and community threads, not from hands-on use. Pricing pages are volatile; every price below is a dated snapshot (2026-11-09) and several conflict between sources. Sage's SA pages and Xero's help centre returned 403 or empty content to the fetcher, so those claims are [R].

Terminology note: "Ecosystem" below means the Dot platforms named in the brief (InfoDot identity, Emall, HR, Projects, Files, Notify, Agents/Brain). I have not inspected those codebases; the integration ideas are proposals, not statements about what exists.

---

## 1. Executive summary

1. **The market is crowded at the top and thin at the bottom for South Africa.** Xero, Sage, QuickBooks and Zoho all sell a South African edition with VAT handling. The micro-business end is being attacked by local startups (Stub, bundled with Yoco and Capitec) [R S29]. A new entrant cannot win on "invoices plus bank feeds plus VAT"; that is table stakes.
2. **Where owners actually get hurt is in comprehension and compliance, not features.** Surveys across countries show large shares of small-business owners cannot read financial statements [R S32]. In SA the costly failures are invalid tax invoices behind input-VAT claims, claims on blocked categories (entertainment, fuel, vehicle rental), and slow refunds [R S31]. Reconciliation screens that hide unreconciled items are a recurring complaint [R S33].
3. **VAT filing exists in all the big four but is uneven.** Xero ZA advertises auto-populating the VAT201 and filing to SARS [V S5]. Sage supports submit-to-eFiling, but the page I could find describes the Accountants Edition and one-submission-only rules [R S11, S34]. Zoho ZA generates and tracks VAT201 but its help page does not say it submits to SARS; "Mark as Filed" only records status [V S7]. QuickBooks ZA "prepares an activity statement to assist you with filing"; no direct submission claim [V S9].
4. **Accountants' strongest complaints are about controls, not UI.** Xero's own product-idea forum shows users asking for audit-trail reports and lock-date history, and a Xero reply that a complete event-level audit trail "is not in our plans" [R S27]. QuickBooks closing dates are warn-or-password, with the exceptions report as the trail [R S27].
5. **AI is converging on "reconcile and categorise for you".** Xero claims JAX reconciles at 97% accuracy (vendor claim) [R S14]; Intuit ships a team of agents [R S15]; Sage launched an AI suite in SA in March 2026 with human sign-off before adjustments [R S16]. The open gap is explainability and a hard autonomy ceiling, which fits the Dot Guardian pattern.
6. **Big regulatory tail-wind for a new SA platform.** SARS/Treasury confirmed a Peppol-based five-corner e-invoicing model in February 2026, a consultation closed 16 October 2026, and mandatory phases target about 2028 (one source says 2030) [R S28]. A product built on structured invoice data from day one gets a head start over desktop-era systems.
7. **The recommended wedge:** an owner-readable, evidence-first, cash-aware finance layer that is native to the Dot ecosystem (sales from Emall, payroll from HR, budgets from Projects, documents in Files) so that data arrives already coded and already evidenced, with a controlled, auditable path for accountants. Fifteen differentiators follow in section 6.

---

## 2. South African regulatory facts that shape the product

| Fact | Detail | Source / grade |
|---|---|---|
| VAT rate | 15%. The planned rise to 15.5% (May 2025) and 16% (April 2026) was reversed. | SARS VAT page [V S1] |
| Compulsory registration | R2.3 million from 1 April 2026 (was R1 million). | [V S1] |
| Voluntary registration | R120 000 from 1 April 2026 (was R50 000). | [V S1] |
| VAT201 due dates | Return and payment by eFiling: last business day of the following month. Bank/EFT payment route: 25th. If the 25th is not a business day, the preceding business day applies. SARS's own pages conflict on the manual-filer date. **Make the due-date rule a config table and re-verify against the SARS calendar before launch.** | SARS obligations page [V S2]; VAT201 guide [V S3] |
| Record keeping | 5 years; SARS uses tax invoices and records as the audit trail. | [V S2] |
| VAT201 structure | Fields 1 to 20 for output, input and net VAT; fields 21 to 38 for diesel refunds. Fields 4, 4A, 6, 8, 9, 11, 13, 19, 20 auto-calculate. Customs code mandatory for fields 2A, 14A, 15A. Overriding auto-calculated fields by a large amount triggers an error. Revisions must contain full amounts, not differences, and reuse the original PRN. | [V S3] |
| Submission via software | SARS has an independent-software-vendor (ISV) channel. A VAT201 can be submitted electronically through accounting software only once; amendments go through eFiling directly. Diesel refund claims go through eFiling. Submissions made 06:00 to 18:00 are queued and processed after 18:00. | ISV page [R S35]; Sage [R S11, S34] |
| Refunds | Normally due within 21 business days, but SARS may defer pending verification; practitioners report multi-month delays. | [R S31] |
| Invoice defects | SARS reverses input claims backed by invalid tax invoices and charges interest and penalties; entertainment, fuel and motor vehicle rental are commonly mis-claimed. | [R S31] |
| E-invoicing | No general B2B mandate yet. SARS/Treasury confirmed a Peppol-based five-corner model with a SARS central hub on 3 Feb 2026; consultation paper 17 Aug 2026, comments closed 16 Oct 2026; large taxpayers first, target 2028 (one source says 2030). Tax Administration Laws Amendment Act 4 of 2026 added e-invoice definitions to the VAT Act. | KPMG TNF [R S28] |
| Not researched [U] | Late-filing penalty percentages, VAT period categories (A to F and their filing frequency), provisional tax, EMP201/EMP501 payroll returns, Companies Act record-keeping. Secondary pages claim "10% late penalty" and "under R30m files bi-monthly" but I could not verify them on SARS pages. | [U] |

Design implication: a **jurisdiction pack** (rates, thresholds, return layout, due-date rules, invoice-validity rules) as data, not code, so SA ships first and other countries follow.

---

## 3. Competitor findings

### 3.1 Snapshot table (SA-relevant pricing where verifiable)

| Product | SA availability and VAT | Pricing snapshot (ZAR, incl. VAT unless stated) | Notable limits | Source / grade |
|---|---|---|---|---|
| **Xero ZA** | Auto-populates VAT201, files via SARS eFiling connection (set up through a partner connector), shows tax lines for review, late claims roll into the current period, VAT audit reports. Does not state VAT payment flow or per-plan availability. | Starter R485, Standard R857, Premium R1,195 per month regular; intro 80% off for 3 months (R97 / R171.40 / R239). A search summary says prices rise 1 Nov 2026 and shows "From R69"; the fetched page did not show an increase notice, so **conflict, re-check**. | Starter: 20 invoices, 5 bills. Auto-reconcile on Starter is a R100/month add-on. Standard and Premium have no listed caps. | [V S4]; VAT [V S5]; setup [R S6]; increase [R S36] |
| **Sage Accounting (SA)** | VAT calculation, submit VAT201 to eFiling via ISV link (documented for Accountants Edition). Payment still made in eFiling. | Start from R240/month (R3,240/yr); Standard from R435/month (R5,880/yr, 2 users). Plus not verified. Payroll sold separately. | One electronic submission per return; no diesel claims; unclear if business plans include submission. | [R S12, S11, S34] |
| **Pastel / Sage 50cloud Partner** | Desktop product with cloud connectivity. | From R18,120/yr. | Legacy desktop; migration vendors market cloud alternatives. | [R S37] |
| **QuickBooks ZA** | 15% preset, VAT tracked on invoices, expenses and POs, VAT summary report and a liability calculator; "assists" filing, no direct-submit claim. | Prices not shown on fetched page ("Loading live prices"); third-party figures conflict (Lite R170, Plus R300, Premium R700 vs R350 to R1,000). **Unverified.** | Plans: Simple Start (1 user), Essentials (3), Plus (5), Advanced (25). | [V S9, S10]; [U S38] |
| **Zoho Books ZA** | Dedicated South Africa edition; VAT201 report with output, input, net sections; share; mark filed/unfiled; version history. Does not say it submits to SARS. | Free (R0, 1 user + accountant, 1,000 invoices), Standard R99, Professional R199, Premium R299, Elite R1,290, Ultimate R2,490 per month (excl. local taxes); VAT returns from Standard. Extra users R20. | Competitor sites claim weak SARS filing; those sites have a commercial interest. | [V S7, S8] |
| **Stub (SA startup)** | AI-native, targets micro-entrepreneurs (spaza, salons, tuck shops); ~10,000 onboarded; Capitec bank integration; Yoco two-way integration (June 2026, 200,000 merchants). VAT and income tax described as next. | Pricing not found. | Early stage; seven staff. | [R S29] |
| **Yoco** | Integrates with Xero (daily reconciliation of card sales, VAT, settlements), Sage, QuickBooks (via Amaka), Stub. | Yoco Pro listed at R499/month on Xero's app page. | Integration quality depends on connector. | [R S30] |
| **QuickBooks / Xero / Sage global** | See 3.2. | | | |
| **FreshBooks** | Not an SA product [U]. | Four tiers capped by billable clients (Lite 5, Plus 50, Premium unlimited, Select quote); extra team members about $11/month; Advanced Payments $20/month add-on. | Client caps are the main complaint; archived clients may count. | [R S18] |
| **Wave** | Not SA [U]. | Free core; payments 2.9% + $0.60; Pro about $16 to $19/month (figures conflict); payroll fees conflict. | Reported bank-feed/collaborator paywall change in 2026; weak for inventory/projects. | [R S17] |
| **NetSuite** | Enterprise ERP. | Not public; implementation estimates $30k to $250k depending on source. | Setup complexity and cost. | [R S26] |
| **Dynamics 365 Business Central** | ERP for small and mid. | Essentials about $70 to $80 and Premium $100 to $110 per user/month (sources differ). | Reported complexity for small firms, need for training. | [R S26] |
| **Odoo Accounting** | Community free; Enterprise subscription. | Community lacks bank sync, auto-reconcile and OCR/AI per several sources (sources partly conflict on manual reconciliation). | Feature gating by edition; SA localisation not verified. | [R S13] |

### 3.2 Challengers worth learning from

- **Pennylane (France).** A shared workspace where the business captures invoices and receipts while the accountant categorises, corrects and closes in the same system; Factur-X compliant invoicing aimed at the French mandate; pricing often mediated through the accountant; built for France, features and terminology do not transfer [R S19]. Lesson: shared ledger beats email chains, and e-invoicing readiness sells.
- **Hnry (NZ/AU).** Income is paid into a trust account, tax (income tax, GST, ACC, student loan) is calculated and paid automatically on each receipt, returns lodged by their accountants; 1% fee, capped in NZ [V S20; R S20b]. Lesson: the strongest simplification of tax is to take the decision away, using a running "set-aside" so there is no year-end shock. Dot.Finance cannot hold client funds (regulated) but can compute and display the set-aside and prompt a transfer [U: regulatory position needs legal review].
- **Bokio (Sweden).** Free or low-cost bookkeeping aimed at sole traders, with an accountant add-on tier; older reviews note missing bank feeds, payroll and multicurrency, newer sources say paid plans include more [R S21]. Lesson: a genuinely free tier plus paid human help is a viable ladder.
- **Float (cash-flow forecasting, add-on to Xero/QuickBooks/FreeAgent).** Scenarios (new hire, late payment, slower collections) kept separate from the base forecast, 13-week and up to 36-month views; Small Business from £99/month, Growth £159 to £199 [V S22; R S39]. Lesson: forecasting is sold as a separate product at roughly the price of the core ledger, so including it natively is a strong packaging lever. Fathom: I found no primary evidence beyond G2 listings; **not verified**.
- **Pilot.** Dedicated human bookkeeper plus software; Core listed at $599/month under $30k monthly expenses; QuickBooks-only [R S25]. Lesson: people pay for "someone else does it" — an assistant with accountant escalation addresses the same need at lower cost.
- **Ramp / Brex.** Cards with limits per card/merchant/category/user, decline-or-flag rules, receipts by text or email matched to transactions, sync to the ledger [R S24]. Vendor claims only on accuracy. Lesson: control at the moment of spend, with the receipt captured before the transaction reaches the books.
- **Karbon (accountant practice management).** Shared and delegated triage inbox, email-to-work conversion, scheduled client requests with automatic follow-up, client portal; reviewers on G2 find the email integration confusing [R S23]. Lesson: accountants want requests and evidence chased automatically.

### 3.3 AI posture of the incumbents

- Xero JAX: reconciliation learned from history, SMS/email/WhatsApp creation of invoices, "JAX Assure" validation layer; vendor claims 97% accuracy and a demo handling 113 of 118 transactions (one demo, not a benchmark) [R S14].
- Intuit: a set of specialised agents with the user "always in control" and QuickBooks Live humans for judgment calls; beta connections to external assistants [R S15].
- Sage: AI suite launched in South Africa in March 2026, with approval required before any financial adjustment, which "leaves an audit trail for regulators such as SARS" (as reported) [R S16].
- Gap: none of these sources shows a customer-visible **reason** attached to each automated decision plus a configurable autonomy ceiling. That is where the Agents/Brain assistant can differentiate. [U: absence in sources is not proof of absence in product.]

---

## 4. Where competitors create needless complexity

1. **Jargon as the default interface.** Owners see "Accounts receivable", "Bank rec", "Chart of accounts", "Journal", "Tax rates", "Input/output tax". A study of Irish SMEs found about 42% did not understand financial statements and 58% did not use them because they considered it the accountant's job [R S32]. A separate source claims only 45% of owners can define basic accounting principles and that terms like "accounts payable" are not part of their repertoire [R S32b]; that figure is from a secondary article of unstated date.
2. **Reconciliation hides the work.** A practitioner notes it is easy to be confused because the dashboard shows nothing needing reconciliation while transactions still await it in the background [R S33]. Duplicates, timing gaps and VAT errors from bank feeds are a standing topic in how-to guides, though the sources are low quality [R S33b].
3. **Plan packaging punishes growth.** Xero Starter caps at 20 invoices and 5 bills and charges R100/month extra for auto-reconcile [V S4]; FreshBooks caps billable clients, with reviewers calling it artificial scarcity [R S18]; Odoo gates bank sync and AI behind Enterprise [R S13]; Wave reportedly paywalled bank feeds and collaborators [R S17].
4. **VAT filing is a bolt-on, not the spine.** Xero and Sage submit, but with one-shot submission limits, separate payment steps and per-plan ambiguity [V S5; R S11, S34]. Zoho and QuickBooks stop at preparation or status-tracking [V S7, S9]. No source shows a pre-filing exception check tied to SA invoice-validity rules.
5. **ERPs are overkill and expensive for small businesses.** Business Central and NetSuite are repeatedly described as complex for small firms with significant implementation cost [R S26]. Their audience is not Dot.Finance's, but they show how quickly a product can be buried in configuration.
6. **Tool sprawl.** A typical SA small business assembles Yoco (payments) + an accounting package + connectors such as Amaka (Yoco to Sage/QuickBooks, partner charges apply) + a forecasting tool [R S30, S39]. Each hop is a place where VAT mapping drifts.

## 5. What small-business owners struggle with, and what accountants complain about

### Owners
- **What the numbers mean.** Cash vs profit confusion is an inference from the literacy surveys; the Xero US survey ranks cash-flow management and interpreting financial metrics each at 16% among problem areas [R S32c]. Do not over-read: different samples and definitions.
- **VAT.** Valid tax invoice requirements; blocked categories; zero-rated sales omitted; registration timing causing invalid invoices [R S31]. Refund waits create cash-flow stress [R S31].
- **Reconciliation.** What "reconciled" means, why a bank line has no match, what to do with unexplained differences [R S33].
- **Terminology and sequencing.** Quotes, invoices, credit notes, statements: when each applies. [U: no study found; observation from product vocabulary.]

### Accountants
- **Audit trail.** Xero users ask for an exportable audit-trail report; a Xero reply says full event-level auditing is not planned; History & Notes is described as unexportable [R S27]. QuickBooks relies on a closing date plus an Exceptions to Closing Date report, with reopen possible and sometimes needed to fix sync errors [R S27].
- **Period locks.** Xero lock dates are adviser-only; integrations connected with the adviser role can void invoices even when a lock date is set (per Chargebee documentation); discipline gets harder with several advisers [R S27]. A QuickBooks community complaint asks for locks that block new postings without blocking other transaction types [R S27].
- **Workpapers.** I did not find primary evidence of accountants' workpaper complaints about these products in this research pass. Treat "workpapers" as an opportunity hypothesis [U], not a documented pain.
- **Client chasing and email overload.** The reason Karbon exists [R S23].
- **Price changes.** Accountants have publicly pushed back on Xero price rises [R S36].

---

## 6. The fifteen differentiators (prioritised)

Priority key: **P0** = needed for credible launch; **P1** = launch or first quarter; **P2** = follow-on. Each paragraph says why, with evidence, and what ecosystem asset it leans on.

**1. P0. Plain-language layer on every screen and number ("say it simply, show the accounting term one tap away").**
Rationale: literacy surveys show large shares of owners cannot interpret statements [R S32]. Incumbents lead with accounting vocabulary. Dot.Finance should write every label, report heading, empty state and error in the owner's words, with the formal term as a secondary hover or "accountant view" toggle that never changes the underlying ledger. Each key number gets a one-sentence "what this means / what to do" generated from templates (not free-form AI) so it is predictable and testable. Ecosystem: shared glossary content could live in InfoDot docs. See section 7.

**2. P0. VAT as a guided review with live liability, not a form at month end.**
Rationale: VAT is the costliest SA failure mode (invalid invoices, blocked categories, deadline confusion) [R S31] and the filing step is bolted on in competitors [V S5, S7, S9]. Show a running "VAT so far this period" and the cash to set aside, run pre-filing checks (missing supplier VAT number, no "TAX INVOICE" label, blocked categories, zero-rated omissions), then produce the return with every field drillable to documents. Ecosystem: Emall supplies output VAT lines; Files holds evidence. See section 9.

**3. P0. Evidence-first capture: no expense without a document, no input claim without a valid tax invoice check.**
Rationale: SARS reverses claims backed by invalid invoices and adds interest and penalties [R S31]. Ramp's receipt-before-ledger pattern shows the workflow [R S24]. At capture (upload, email-in, WhatsApp or camera), extract supplier, VAT number, date, totals, and validate against SA tax-invoice rules held in the jurisdiction pack; flag, do not block, and mark the line "not claimable until fixed". Ecosystem: Files stores the document with its append-only version history; Notify nudges for missing documents.

**4. P0. Reconciliation as a "needs a decision" inbox with explained suggestions.**
Rationale: competitors hide unreconciled work or bury it in a ledger-style screen [R S33]; Xero's own AI emphasises auto-reconcile as its flagship [R S14] and charges R100/month for it on Starter [V S4]. Treat reconciliation as a short queue of exceptions only: auto-matches are shown as a summary ("84 matched for you, here is why"), uncertain ones show top-3 candidate matches with the reason ("same amount, same reference, 2 days apart"), and unknowns ask a plain question. Included in every plan. See section 10.

**5. P0. Native sales ledger from Emall (and card/POS settlements) with no connector in between.**
Rationale: SA merchants chain Yoco + connector + accounting software, with partner charges and mapping drift [R S30]. Stub and Yoco bundled in June 2026 precisely to remove the hop [R S29]. Orders, refunds, fees, VAT and settlements from Emall should post as already-coded journal entries with the order as the evidence. Yoco/other processors can use a generic settlement-import path (P1).

**6. P1. Payroll posts itself from HR, with the employer liabilities visible as a set-aside.**
Rationale: payroll is sold separately by Sage [R S12] and bolted on elsewhere. When HR runs payroll, the ledger should receive gross, deductions and employer costs and show the money that belongs to SARS rather than the owner. The specific SA returns and rates (PAYE, UIF, SDL, EMP201) were **not researched** here [U]; confirm with HR's existing payroll rules and a tax practitioner before building.

**7. P1. Project budgets and profitability by default (not an "Advanced" tier).**
Rationale: QuickBooks gates project profitability at its Plus tier [V S10]; Business Central and NetSuite handle it at ERP weight [R S26]. If Projects budgets are the dimension, every bill, invoice and timesheet-cost maps to a project and the owner sees budget vs actual in the dashboard. Ecosystem: Projects.

**8. P1. Built-in cash forecast with scenarios (the Float category, included).**
Rationale: Float is a separate subscription from £99 to £199 per month [V S22] and exists because the ledger products do not forecast well. Using invoices due, bills due, VAT date, payroll date, provisional tax estimate and recurring items, produce a 13-week view and a 12-month view, with named scenarios ("lose the biggest client", "hire one person") kept separate from the base [V S22]. Plain-language alerts: "You will be R18,000 short on 28 Nov".

**9. P1. Accountant workspace with a shared ledger, request-and-chase, and a review queue.**
Rationale: Pennylane's shared workspace removes email chains [R S19]; Karbon automates requests and follow-ups [R S23]; accountants dislike chasing clients. Give the accountant a role with a review queue (flagged items, missing documents, unusual entries), scheduled "please upload" requests via Notify, comments tied to ledger lines, and a period-end pack assembled in Files. Workpapers are a hypothesis [U], so ship the pack as exportable bundles and ask pilot accountants what they want in it.

**10. P1. Immutable audit trail and controlled period locks.**
Rationale: this is the clearest documented gap: Xero says event-level auditing is not planned [R S27], lock dates are adviser-only with integrations able to bypass [R S27], and QuickBooks reopen is "discouraged" but routine [R S27]. Build an append-only event log (who, what, before, after, when, why) exported on demand; lock periods at ledger level so no integration or role bypasses them silently; reopening requires a reason, a second approver where configured, and creates a visible "reopened" marker on every affected report. Files' append-only version history is the right pattern to reuse.

**11. P1. Invoice lifecycle with promised-pay, payment links and automatic chasing.**
Rationale: late payment is the main cash-flow lever, and tools like Wave and FreshBooks lean on payments revenue [R S17, S18]. Full lifecycle in section 9. Notify delivers reminders by email and SMS/WhatsApp; the bad-debt VAT adjustment (VAT201 field 17 for the invoice basis [V S3]) is applied correctly when written off.

**12. P1. Assistant that proposes, explains and never posts alone (autonomy ceiling).**
Rationale: incumbents compete on automation volume [R S14, S15]; the weak point is trust, which Xero itself addresses with a "validation layer" [R S14] and Sage with sign-off [R S16]. The Agents/Brain assistant should (a) answer questions in plain language with links to the underlying entries, (b) draft entries, matches and reminders as **proposals** with a reason and a confidence, (c) never post, file or send without a human approval above a configurable ceiling, and (d) record every proposal and decision in the audit trail. This mirrors the Guardian autonomy-ceiling rule already used in the Dot ecosystem [U: from owner memory notes, not re-verified].

**13. P0. Transparent, cap-free packaging with VAT filing and reconciliation in every plan.**
Rationale: caps and add-ons are the visible frustrations (Xero Starter 20 invoices and paid auto-reconcile [V S4]; FreshBooks client caps [R S18]; Odoo edition gating [R S13]). Zoho's Free plan (1,000 invoices, accountant seat) and Bokio's free tier show the market tolerates generous bottom rungs [V S8; R S21]. Proposal: a free plan for sole traders under the VAT threshold (no VAT module needed), paid plans priced per business not per client/invoice, accountant seats free, and ecosystem bundle discounts. Actual price points are a commercial decision [U]; benchmark band for SA: Zoho R99 to R299, Sage R240 to R435, Xero R485 to R1,195 [V S8; R S12; V S4].

**14. P2. Jurisdiction packs and e-invoicing-ready structured invoices.**
Rationale: Pennylane's French focus is both its strength and its ceiling [R S19]; SA's Peppol-based model is coming [R S28]; Odoo already has third-party SA e-invoice modules [R S13b]. Store invoices as structured data (UBL-compatible fields) from day one, keep tax rules in data packs (rates, thresholds, return layouts, due-date rules), and ship SA first with a clean path to other countries.

**15. P2. Two doors, one ledger: "Money in / money out" mode for micro and informal traders.**
Rationale: Stub's traction among spazas, salons and tuck shops [R S29] shows an underserved segment that double-entry products scare off. Offer a cash-style simple mode (record sales, spending, see profit and VAT) that writes proper double-entry underneath, with an upgrade path to full mode when the business or its accountant wants it. Pairs with Capitec/Yoco-style bank and payment data where available.

### Ranking logic

P0 items protect the owner from the two things that hurt most (misunderstanding numbers, VAT mistakes), remove reconciliation friction, and use the ecosystem's unfair advantage (native sales). P1 items turn it into an accountant-credible operating system. P2 items widen the market and the country list.

---

## 7. Plain-language accounting: glossary approach

**Principles**
1. **Say what happened, not the account type.** "Money you are owed" instead of "Accounts receivable"; "Bills to pay" instead of "Accounts payable". The standard term stays as the second line.
2. **Two vocabularies, one switch.** Owner view (default) and Accountant view (formal terms, account codes, journals). The switch changes labels only; it never changes data. Accountant seats default to accountant view.
3. **Glossary as data.** One structured file per language with fields: `key`, `owner_label`, `owner_explanation` (max 20 words), `formal_term`, `formal_definition`, `jurisdiction`, `example`, `see_also`. UI labels, tooltips, reports and the assistant all read from it, so wording is never duplicated.
4. **Every number answers three questions.** What is it? Is it good or bad? What should I do? Templates with thresholds, not free-form generation.
5. **Test the words.** Run short comprehension checks (5 to 8 pilot owners): "Without help, tell me what this number means." Revise labels where fewer than 80% answer correctly [U: threshold is a proposal].
6. **Accountant ratifies, owner reads.** Formal definitions are written or reviewed by a registered accountant; owner explanations are tested with owners.
7. **Localise, do not translate.** SA English first; isiZulu, isiXhosa, Afrikaans glossary entries later via the same file structure [U: language demand not researched].

**Starter mapping (illustrative)**

| Owner label | Formal term | One-line explanation |
|---|---|---|
| Money you are owed | Accounts receivable | Customers who have an invoice open with you. |
| Bills to pay | Accounts payable | What you owe suppliers. |
| VAT to pay SARS (or get back) | Net VAT / Field 20 | What you charged customers minus what you can claim. |
| Checked against the bank | Reconciled | We found this in your bank statement. |
| Profit | Net profit | What you earned minus what it cost, before tax on profit. |
| Cash you can safely spend | Available cash after set-asides | Bank balance minus VAT, payroll and tax already owed. |
| Locked month | Closed period | Nobody can change numbers in this month without a recorded reason. |

---

## 8. Dashboard hierarchy: what an owner must see in 10 seconds

Principle: five answers, in this order, above the fold on a phone. Each is one number plus one sentence plus one action. Everything else is below or one tap away.

1. **"Cash you can safely spend."** Bank balance minus set-asides for VAT, payroll and tax already owed. Sub-line: "You have enough for N weeks at your current pace." Action: see the forecast.
2. **"Money coming in."** Total overdue plus due this week; top three late customers. Action: send reminders (proposed by the assistant, approved by you).
3. **"Money going out."** Bills due in the next 14 days, with the VAT payment and payroll dates shown as their own chips with day counters. Action: pay or schedule.
4. **"Are we making money?"** This month profit vs last month and vs the same month last year, one sparkline, one sentence ("Profit is up 12% because sales rose and fuel costs fell"). Action: see why.
5. **"Needs you."** One counter for everything blocked on the owner: unmatched bank lines, missing receipts, invoices to approve, accountant questions. Action: open the inbox.

Below the fold: a project budget strip (from Projects), sales by channel (from Emall), and a "last month in plain English" auto-written summary. A one-tap switch exposes the accountant view (trial balance, aged reports).

Rules: red appears only when action is needed within 7 days; no more than six numbers on first paint; never show a chart without the sentence that explains it.

---

## 9. Proposals

### 9.1 VAT-return UX

Goal: the owner approves a story; the accountant can audit the detail; nothing is filed that has not passed checks.

1. **Period header.** "VAT period: 1 Sep to 31 Oct. Due Fri 28 Nov." Show the due date from the jurisdiction pack with the rule used ("eFiling: last business day") [V S2]. If the SARS date rules remain ambiguous at build time, show both and make the vendor choose their filing route in settings [V S2, S3].
2. **Health check first.** A list of problems before any numbers: purchases without a valid tax invoice, missing supplier VAT number, blocked categories (entertainment, fuel, vehicle rental) [R S31], zero-rated sales missing a reason, imports missing a customs code [V S3], bank lines not reconciled in the period, unusually large changes vs prior periods. Each item has fix, accept-with-reason, or exclude.
3. **The story in three numbers.** "You charged R412,300 incl. VAT (R53,778 VAT). You can claim back R31,120. You owe SARS R22,658." Plus a one-line comparison with the last return.
4. **Drill-down on demand.** A "show me the SARS boxes" view with fields 1 to 20 (and 21 to 38 when diesel applies) [V S3]; every figure expands to its transactions and their documents in Files. Auto-calculated fields are shown as calculated and overrides require a reason, because large overrides trigger SARS errors [V S3].
5. **Approve and lock.** Owner or accountant approves; the period's transactions lock (see differentiator 10). Revisions create a new full version and reuse the original PRN [V S3], with the diff displayed to the user but full amounts transmitted.
6. **Submit.** Through the ISV link where available. State plainly that software submission works once per return and amendments go through eFiling [R S11, S35]; hide the submit button and explain when the return type is not supported (for example diesel refund) [R S11].
7. **Pay.** Show the PRN, amount and deadline for payment (25th vs last business day depends on route [V S3]); create a calendar and Notify reminder; record the payment when seen on the bank feed and close the loop on the return.
8. **Proof pack.** After filing, store return, working schedule, exceptions accepted and reasons, and SARS acknowledgement in Files with retention marked at 5 years [V S2].
9. **Refund tracker.** If refund due, show "SARS normally pays within 21 business days; yours is on day N" [R S31] and surface the audit-risk factors that apply.

Open questions for implementation: ISV onboarding requirements and test environment with SARS [R S35]; whether a public API exists today [U]; how to handle the 06:00 to 18:00 queue behaviour [R S11].

### 9.2 Bank-reconciliation UX

Goal: the owner never needs the word "reconcile"; the accountant gets a defensible report.

1. **Language.** "Check against the bank." A line is "matched", "needs a look" or "unknown".
2. **Three lanes, one screen.** (a) *Matched for you*: collapsed by default with a count and a "why" on demand. (b) *Needs a look*: top 3 candidate matches with the reason and confidence; one-tap accept. (c) *Unknown*: a plain question ("Is this a customer payment, a bill, a personal withdrawal, or a transfer?") with templates for common SA cases: card settlement net of fees (Emall/Yoco), SARS payment, salary run, bank charges.
3. **Rules that learn, visibly.** After a confirmed match, offer "Do this next time for [payee]?" and show the rule list in settings with an on/off toggle and a count of times applied. Autonomy ceiling: the assistant never auto-accepts above a configured amount or for new payees [see differentiator 12].
4. **Never hide the backlog.** Dashboard counter "N bank lines need you" and a "reconciled to" date per account; this directly answers the documented complaint that dashboards show nothing to do while work is pending [R S33].
5. **Settlement-aware.** A card settlement of R9,730 matches a bundle of Emall orders minus fees; show the bundle and the fee line, not a single mystery match.
6. **Duplicates and timing.** Flag probable duplicates (same amount, date, reference) and timing gaps (bank date vs payment date) before they reach the books [R S33b].
7. **Closing statement.** At period end, produce a one-page reconciliation statement (bank balance, outstanding items, ledger balance, difference = 0) stored in Files and referenced by the period lock.
8. **Undo with trail.** Any match can be reversed; the reversal is logged with user, reason and timestamp.

### 9.3 Invoice-lifecycle proposal

States and transitions (each transition is an event in the audit trail):

1. **Quote** (optional): sent, viewed, accepted, declined, expired. Acceptance converts to a draft invoice.
2. **Draft**: built from Emall order, Projects milestone, timesheets, or manually. Validation: customer VAT number where required, correct tax invoice wording and fields, due date and terms, bank details [R S31].
3. **Approved** (optional second pair of eyes above a threshold): locks content; changes need a credit note.
4. **Sent**: delivered by email, link or WhatsApp/SMS via Notify; includes a payment link and a PDF in structured form (e-invoice-ready) [R S28]. Send time and channel recorded.
5. **Viewed**: open tracking where lawful and not relied upon for legal claims [U: POPIA position to confirm].
6. **Due soon / Due today**: gentle reminders on a schedule the owner sets once ("3 days before, on the day").
7. **Overdue**: escalating chase ladder (friendly, firm, final), with the assistant proposing wording and a statement attached; customer can reply "I will pay on [date]" which creates a *promised-pay* marker that pauses chasing and feeds the cash forecast.
8. **Part-paid / Paid**: payments matched automatically from bank lines or processors; over/underpayments create a clear "short by R200" state rather than a mystery balance.
9. **Disputed**: pauses chasing, opens a thread, links to the credit note or revised invoice.
10. **Credit note / write-off**: credit notes reduce VAT output; bad debt write-off triggers the correct input adjustment on the invoice basis (VAT201 field 17) [V S3].
11. **Closed and archived**: evidence retained for 5 years [V S2]; locked with the period.

Cross-cutting: every invoice shows a plain-language status line ("Owed 12 days, promised for 5 Dec"); late-payment trends per customer feed the dashboard "Money coming in" card and the forecast.

---

## 10. Suggested sequencing for R-44 (inputs for the planning agents)

1. **Foundation:** event-sourced ledger with append-only audit log, period locks, jurisdiction pack format, glossary file format (differentiators 10, 14, 1).
2. **Core flows:** invoicing lifecycle, expense capture with evidence, bank reconciliation inbox, Emall posting (11, 3, 4, 5).
3. **Tax:** VAT health check, return generation, ISV submission feasibility spike (2).
4. **Owner experience:** dashboard, set-aside logic, forecast (cash view first, scenarios second) (8).
5. **Accountant experience:** workspace, requests, pack export (9).
6. **Assistant:** proposals with reasons and autonomy ceiling, wired to the audit trail (12).
7. **Ecosystem links:** HR payroll, Projects budgets, Files evidence (6, 7).
8. **Packaging and micro mode:** (13, 15).

---

## 11. Things I could not verify (consolidated)

- Current Sage SA price list beyond Start and Standard; whether VAT201 submission is included in the business plans (the documented feature is for the Accountants Edition) [R S11, S12].
- QuickBooks ZA rand prices (fetch showed none; third-party numbers conflict) and whether it submits to SARS [V S9, S10; U S38].
- Xero ZA new prices after 1 Nov 2026; the fetched page showed no increase notice while a search summary reported one; the "From R69" figure conflicts with R485 [V S4; R S36].
- Xero's step-by-step eFiling setup (page fetched empty); described via search summary [R S6].
- Zoho ZA direct SARS submission (help page silent) [V S7].
- SARS manual-filer deadline (25th vs last business day): SARS's own pages disagree [V S2, S3].
- VAT period categories and frequencies, late-filing penalty percentages, provisional tax, payroll returns [U].
- Stub pricing and categorisation method; any WhatsApp interface [R S29].
- Fathom features and pricing [U].
- Odoo SA localisation depth and whether Community includes manual reconciliation (sources conflict) [R S13].
- Workpaper-specific complaints [U].
- Whether the 2028 e-invoicing target holds (one source says 2030) [R S28].
- Regulatory position of computing but not holding tax set-asides (Hnry holds funds in a trust account); needs legal review [U].
- All AI accuracy figures (97%, 113 of 118, 22 hours saved) are vendor claims [R S14].
- Hands-on UX of every product: not tested.

---

## 12. Source index

Grades: [V] fetched and read; [R] search-result summary or secondary page; [U] unverified.

- **S1** SARS, Value-Added Tax. https://www.sars.gov.za/taxes/value-added-tax/ [V]
- **S2** SARS, Obligations of a VAT vendor. https://www.sars.gov.za/types-of-tax/value-added-tax/obligations-of-a-vat-vendor/ [V]
- **S3** SARS, Guide to completing the VAT201 return. https://www.sars.gov.za/guide-to-completing-the-value-added-tax-vat201-return/ [V]
- **S4** Xero ZA pricing. https://www.xero.com/za/pricing-plans [V]
- **S5** Xero ZA, VAT software. https://www.xero.com/za/accounting-software/submit-vat-online/ [V]
- **S6** Xero Central, Set up eFiling for VAT. https://central.xero.com/0/article/Set-up-eFiling-for-VAT-in-Xero [R; page fetched empty]
- **S7** Zoho Books ZA, VAT201 report. https://www.zoho.com/za/books/help/vat/vat201-report.html [V]
- **S8** Zoho Books ZA, pricing. https://www.zoho.com/za/books/pricing/ [V]
- **S9** QuickBooks ZA, VAT tracking. https://quickbooks.intuit.com/za/vat-tracking/ [V]
- **S10** QuickBooks ZA, pricing. https://quickbooks.intuit.com/za/pricing/ [V, plans only; no prices]
- **S11** Sage, Automated VAT submissions (Accountants Edition). https://www.sage.com/en-za/accountants/products/accountants-edition/automated-vat-submissions/ [R; 403 on fetch]
- **S12** Sage Accounting Africa pricing. https://www.sage.com/africa/sage-business-cloud/accounting/pricing/ and https://www.sage.com/en-za/sage-business-cloud/accounting/ [R; 403 on fetch]
- **S13** Odoo Community vs Enterprise. https://gloriumtech.com/odoo-community-vs-enterprise/ ; **S13b** Odoo SA e-invoice module. https://apps.odoo.com/apps/modules/18.0/l10n_za_sars_einvoice [R]
- **S14** Xero JAX. https://www.accountingtoday.com/news/xero-aims-to-make-ai-core-to-platform-functionality ; https://venturebeat.com/ai/xeros-jax-shows-why-accuracy-and-user-control-matter-more-than-flashy-ai ; https://www.xero.com/sg/just-ask-xero [R]
- **S15** Intuit AI agents. https://www.usecarly.com/blog/quickbooks-ai ; https://www.fintechfutures.com/press-releases/intuit-launches-ai-powered-intuit-assist-for-quickbooks-giving-millions-of-businesses-a-competitive-edge [R]
- **S16** Sage AI in Africa. https://techcentral.co.za/ai-is-coming-to-your-accounting-software/278964/ [R]
- **S17** Wave. https://ifeeltech.com/blog/wave-accounting-review-small-business-freelancers ; https://beancount.io/blog/2026/08/05/wave-accounting-bank-feeds-collaborator-paywall-guide [R, low-quality aggregators]
- **S18** FreshBooks. https://www.nerdwallet.com/reviews/small-business/freshbooks ; https://www.capterra.com/p/142390/FreshBooks/pricing/ [R]
- **S19** Pennylane. https://www.capterra.co.uk/software/218672/pennylane ; https://www.getapp.com/all-software/a/pennylane/ [R]
- **S20** Hnry. https://hnry.co.nz/product/tax-payments [V]; **S20b** https://www.flyingsolo.com.au/?p=1232096 (fee and trust-account details) [R]
- **S21** Bokio. https://www.techradar.com/reviews/bokio-bookkeeping-software ; https://www.accountingweb.co.uk/node/174325 [R]
- **S22** Float pricing. https://www.floatapp.com/pricing [V]
- **S23** Karbon. https://karbonhq.com/features/email-management/ ; https://www.g2.com/products/karbon-2022-06-30 [R]
- **S24** Ramp. https://support.ramp.com/hc/en-us/articles/360043070613-Ramp-s-spend-management-platform-Enhancing-your-company-s-expense-control ; https://ramp.com/spend-management [R, vendor claims]
- **S25** Pilot. https://www.nerdwallet.com/article/small-business/pilot-bookkeeping [R]
- **S26** Business Central and NetSuite. https://www.g2.com/compare/microsoft-microsoft-dynamics-365-business-central-vs-netsuite ; https://www.spotsaas.com/compare/netsuite-vs-dynamics-365-business-central ; https://www.journeyteam.com/resources/blog/dynamics-365-business-central-vs-netsuite-which-is-better-for-your-business/ [R, partner marketing]
- **S27** Audit trail and period locks. https://quickbooks.intuit.com/learn-support/en-us/reports-and-accounting/how-to-lock-closed-periods/01/193572 ; https://productideas.xero.com/forums/939198-for-small-businesses/suggestions/45631876-history-record-changes-to-lock-dates ; https://productideas.xero.com/forums/939198-for-small-businesses/suggestions/45050950-audit-trail-report?page=3 ; https://www.chargebee.com/docs/billing/2.0/kb/accounting/xero-lock-dates [R]
- **S28** SA e-invoicing and real-time VAT reporting. https://kpmg.com/us/en/taxnewsflash/news/2026/08/tnf-south-africa-public-consultation-on-e-invoicing-and-digital-reporting.html ; https://www.vatupdate.com/2026/02/18/south-africa-sets-multi-year-plan-for-mandatory-e-invoicing-and-real-time-vat-reporting (403 on fetch) ; https://vatcalc.com/south-africa/south-africa-vat-digital-reporting-consultation [R]
- **S29** Stub and Yoco. https://techcentral.co.za/how-a-tiny-sa-team-is-using-ai-to-challenge-accountings-big-boys/282650/ ; https://www.businessday.co.za/companies/2026-06-03-start-up-stub-partners-with-yoco-to-reach-200000-merchants/ ; https://www.itweb.co.za/article/capitec-stub-join-forces-to-simplify-sme-accounting/KPNG878NDbbq4mwD [R]
- **S30** Yoco integrations. https://www.yoco.com/za/integrations/partners ; https://itweb.africa/article/yoco-integrates-payments-platform-with-xero/KWEBb7yLl6jvmRjO ; https://amaka.com/newsroom/amaka-and-yoco-team-up-to-simplify-accounting-for-businesses-in-south-africa/ [R]
- **S31** SA VAT pitfalls and refunds. https://forvismazars.com/za/en/who-we-are/news-and-publications/our-publications/forvis-mazars-messenger/mazars-messenger-november-2020/5-common-tax-pitfalls-small-business-should-avoid ; https://supermarket.co.za/index.php/economic-factors/1061-delayed-vat-refunds-%E2%80%98causing-cash-flow-woes-for-small-business%E2%80%99 ; https://thesait.org.za/navigating-a-sars-vat-audit-essential-guide-for-sa-businesses/ [R; some dated 2020 to 2023]
- **S32** Financial literacy. https://www.lawsociety.ie/gazette/top-stories/financial-literacy-is-low-in-small-firm-owners/ (Irish SFA 2019) ; **S32b** https://accountingedu.org/rescuing-the-small-business-owner ; **S32c** https://smallbiztrends.com/xero-financial-literacy-survey/ [R; non-SA samples]
- **S33** Reconciliation UX. https://marcandrews.com/xero-bank-reconciliation-tutorial-uk-step-by-step-guide/ ; **S33b** https://reconcile-bvs.pages.dev/blogTop10BankErrors [R, low quality]
- **S34** Sage SARS eFiling integration (2021). https://www.sage.com/en-za/news/press-releases/2021/07/new-innovative-sars-efiling-integration-from-sage-eases-compliance-for-accountants/ [R]
- **S35** SARS, Independent software vendors. https://www.sars.gov.za/individuals/i-need-help-with-my-tax/your-tax-questions-answered/independent-software-vendors/ [R]
- **S36** Xero SA tax dates 2026/27 and price change. https://blog.xero.com/small-business-resources/south-african-tax-dates-2026-2027/ ; https://www.xero.com/za/pricing-plans/update/ ; https://www.accountantsdaily.com.au/technology/22732-xero-price-hike-has-some-accountants-looking-for-alternatives [R]
- **S37** Sage 50cloud Pastel Partner. https://www.sage.com/africa/products/sage-50cloud-pastel-partner/ [R]
- **S38** QuickBooks ZA third-party pricing. https://www.capterra.co.za/reviews/141374/quickbooks-online ; https://elitemindz.co/blog/best-accounting-software-small-business-south-africa [U, conflicting]
- **S39** Float on Xero ZA app store. https://apps.xero.com/za/function/reporting/app/float-cashflow-forecasting ; https://www.capterra.com/p/226486/Float-Cash-Flow/ [R]
