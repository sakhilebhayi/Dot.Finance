<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Services\Accounting\GlQuery;

/**
 * Owner criterion 11: ledger transactions are traceable. From a figure on a report to the journal lines behind it (adding up to the
 * cent), from a line to its journal entry, from the entry to the document, payment or payroll summary that made it, on a whole month
 * of real trading. Defect D-1602 (bills, claims, supplier payments and payroll summaries had no way back) is covered here.
 */
class Acceptance11_TraceabilityTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
    }

    public function test_a_profit_and_loss_figure_drills_down_to_journal_lines_that_add_up_to_it_to_the_cent(): void
    {
        $r = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $income = $r->row('Total income');
        $this->assertSame(3_053_500, $income['cells']['total'], 'income for the month, by hand: 30,535.00');

        foreach ($r->rows as $row) {
            if ($row['type'] !== 'line' || empty($row['drill']['total'])) {
                continue;
            }
            $drill = $row['drill']['total'];
            $page = $this->asOwner()->get(route('reports.drill', $drill))->assertOk();
            $result = app(\App\Services\Reporting\DrillDown::class)->lines($this->ledger, $drill);
            $this->assertSame($row['cells']['total'], $result['total_cents'], $row['label'].': the drill-down total is the report figure');
            $this->assertSame($result['count'], $result['lines']->total());
            $page->assertSee('JE-0000');
        }
    }

    public function test_every_journal_entry_of_the_month_leads_to_the_thing_that_made_it(): void
    {
        $followed = [];
        foreach (JournalEntry::query()->forContext($this->ledger)->orderBy('id')->get() as $entry) {
            $html = $this->asOwner()->get(route('accounting.journals.show', $entry))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<dd><a href="([^"]+)" class="fx-link">([^<]+)</a>, version#', $html, "entry {$entry->entry_number} ({$entry->source_type} {$entry->source_id}) has a link to its source");
            preg_match('#<dd><a href="([^"]+)" class="fx-link">([^<]+)</a>, version#', $html, $m);
            $source = $this->asOwner()->get(html_entity_decode($m[1]));
            $source->assertOk();
            $followed[$entry->source_type][] = $m[2];
        }

        // the month touched every one of these, and each could be followed
        foreach (['opening_balance', 'invoice', 'credit_note', 'bill', 'payment', 'payroll', 'transaction'] as $type) {
            $this->assertArrayHasKey($type, $followed, "{$type} entries were traced");
        }
        $this->assertContains('Expense claim #1', $followed['bill']);
        $this->assertTrue(collect($followed['payment'])->contains(fn ($l) => str_starts_with($l, 'Supplier payment')), 'a supplier payment leads to the supplier payments page');
        $this->assertTrue(collect($followed['payment'])->contains(fn ($l) => str_starts_with($l, 'Payment received')), 'a receipt leads to the receipts page');
    }

    public function test_an_invoice_figure_traces_to_the_invoice_and_back_a_bill_traces_to_the_bill(): void
    {
        $line = JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $this->gl('ar')->id)->where('party_id', $this->acme->id)->orderBy('id')->firstOrFail();
        $entry = JournalEntry::query()->forContext($this->ledger)->findOrFail($line->journal_entry_id);
        $source = app(GlQuery::class)->source($entry);
        $this->assertSame(['sales.documents.show', (string) $this->inv1->id], [$source['route'], (string) $source['parameter']]);
        $this->asOwner()->get(route($source['route'], $source['parameter']))->assertOk()->assertSee($this->inv1->number);

        $billEntry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->where('source_id', (string) $this->bill1->id)->firstOrFail();
        $billSource = app(GlQuery::class)->source($billEntry);
        $this->asOwner()->get(route($billSource['route'], $billSource['parameter']))->assertOk()->assertSee($this->bill1->displayNumber());

        $payroll = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'payroll')->firstOrFail();
        $this->asOwner()->get(route('accounting.journals.show', $payroll))->assertOk()->assertSee('Payroll summary');
    }

    public function test_a_reversal_leads_back_to_what_it_reversed_and_the_general_ledger_lists_the_source_of_each_line(): void
    {
        $this->asOwner()->post(route('purchases.bills.void', $this->bill3), ['reason' => 'Test void for traceability'])->assertSessionHasNoErrors();
        $reversal = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'reversal')->firstOrFail();
        $html = $this->asOwner()->get(route('accounting.journals.show', $reversal))->assertOk()->getContent();
        $this->assertStringContainsString('Reverses', $html);
        $this->assertStringContainsString(route('accounting.journals.show', $reversal->reverses_entry_id), $html, 'the reversal links to the entry it reverses');
        $original = $this->asOwner()->get(route('accounting.journals.show', $reversal->reverses_entry_id))->assertOk()->getContent();
        $this->assertStringContainsString('Reversed by', $original);

        $gl = $this->asOwner()->get(route('accounting.general-ledger', ['account' => $this->gl('ar')->id]))->assertOk()->getContent();
        $this->assertStringContainsString('JE-0000', $gl);
        $this->assertMatchesRegularExpression('/Invoice #1|INV-00001/', $gl);
    }

    public function test_the_trial_balance_balance_sheet_and_vat_report_figures_also_drill_down(): void
    {
        foreach (['trial-balance' => ['to' => '2026-11-30'], 'balance-sheet' => ['to' => '2026-11-30'], 'vat' => ['from' => '2026-11-01', 'to' => '2026-11-30']] as $key => $input) {
            $r = $this->report($key, $input);
            $drilled = 0;
            foreach ($r->rows as $row) {
                foreach ($row['drill'] ?? [] as $params) {
                    if ($params !== []) {
                        $this->asOwner()->get(route('reports.drill', $params))->assertOk();
                        $drilled++;
                    }
                }
            }
            $this->assertGreaterThan(3, $drilled, "{$key} offers drill-downs");
        }
    }

    public function test_a_drill_down_for_another_ledgers_account_is_not_found(): void
    {
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $this->inCompany($other, $company)->get(route('reports.drill', ['account' => $this->gl('ar')->id]))->assertNotFound();
    }
}
