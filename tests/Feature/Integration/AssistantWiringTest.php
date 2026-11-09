<?php

namespace Tests\Feature\Integration;

use App\Mail\SalesReminderMail;
use App\Models\Intelligence\AssistantMessage;
use App\Models\Sales\SalesReminder;
use App\Services\Intelligence\FinanceAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * Before the integration the assistant answered "I don't have that information" to overdue invoices, bills by due date, the VAT
 * position, tax deadlines, a reminder and "can I afford it" even with the data in the books. These ask the same questions of the
 * same hand-worked November and expect the real answers.
 */
class AssistantWiringTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    private function ask(string $question): AssistantMessage
    {
        return app(FinanceAssistant::class)->ask($this->owner, $this->ledger, $question);
    }

    public function test_overdue_invoices_and_who_owes_me_come_from_the_invoices(): void
    {
        $this->runMonth();
        // Beta Stores bought on 15 September on 30 days' terms: due 15 October, 46 days late on 30 November
        $old = $this->issueInvoice($this->beta, [['description' => 'September goods', 'quantity' => '1', 'unit_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD']], '2026-09-15');

        $overdue = $this->ask('Which invoices are overdue?');
        $this->assertSame('answered', $overdue->status);
        $this->assertStringContainsString($old->number, $overdue->answer_text);
        $this->assertStringContainsString('46 days late', $overdue->answer_text);
        $this->assertStringContainsString('ZAR 11,500.00', $overdue->answer_text, 'R10,000 plus R1,500 VAT');
        $this->assertSame($old->number, $overdue->answer['rows'][0]['cells'][0]);
        $this->assertCount(1, $overdue->answer['rows'], 'Acme\'s invoice is not due until 3 December');

        $owed = $this->ask('Who owes me money?');
        $this->assertSame('answered', $owed->status);
        $names = array_column($owed->answer['rows'], 'cells');
        $this->assertSame([['Beta Stores', 'ZAR 11,500.00'], ['Acme Retail', 'ZAR 9,000.00']], array_map(fn ($c) => [$c[0], $c[1]], $names));
    }

    public function test_bills_come_from_the_supplier_ledger_by_due_date(): void
    {
        $this->runMonth();

        $m = $this->ask('What bills are due in the next 30 days?');
        $this->assertSame('answered', $m->status);
        $this->assertStringContainsString('Paper Co BILL-2026-00003', $m->answer['rows'][0]['cells'][1]);
        $this->assertSame('2026-12-20', $m->answer['rows'][0]['cells'][0], 'the toner, bill date 20 November on 30 day terms');
        $this->assertStringContainsString('ZAR 2,300.00', $m->answer_text);
    }

    public function test_the_vat_position_is_the_vat201s_and_the_calendar_names_what_to_prepare(): void
    {
        $this->runMonth();

        $vat = $this->ask('What is my VAT liability this month?');
        $this->assertSame('answered', $vat->status);
        $this->assertStringContainsString('ZAR 4,560.00', $vat->answer_text, 'output tax: field 13');
        $this->assertStringContainsString('ZAR 1,350.00', $vat->answer_text, 'input tax: field 19');
        $this->assertStringContainsString('ZAR 3,210.00', $vat->answer_text, 'field 20');

        // no calendar yet: the assistant says so; after the nightly calendar run it names the return
        $this->assertSame('no_data', $this->ask('What tax do I need to prepare for?')->status);
        $this->artisan('finance:tax:calendar')->assertExitCode(0);

        $tax = $this->ask('What tax do I need to prepare for?');
        $this->assertSame('answered', $tax->status);
        $labels = collect($tax->answer['rows'])->map(fn ($r) => $r['cells'][1])->implode(' | ');
        $this->assertStringContainsString('VAT201 return for', $labels);
        $this->assertStringContainsString('EMP201', $labels);
    }

    public function test_can_i_afford_it_uses_the_planning_projection(): void
    {
        $this->runMonth();

        $m = $this->ask('Can I afford R5,000?');
        $this->assertSame('answered', $m->status);
        $this->assertStringContainsString('Planning cash projection', implode(' ', $m->answer['notes']));
        // cash today is the cash ledger's balance: the statement's closing 119,290.00
        $this->assertStringContainsString('ZAR 119,290.00', json_encode($m->answer['facts']));
    }

    public function test_a_reminder_is_drafted_then_sent_once_through_the_sales_reminder_log(): void
    {
        Mail::fake();
        $this->runMonth();
        $this->issueInvoice($this->beta, [['description' => 'September goods', 'quantity' => '1', 'unit_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD']], '2026-09-15');

        $m = $this->ask('Remind Beta about their overdue invoice');
        $this->assertSame('answered', $m->status);
        $this->assertCount(1, $m->drafts, 'now that Sales sends reminders a draft is offered');
        Mail::assertNothingSent();

        $draft = $m->drafts->first();
        $this->post(route('intel.assistant.draft.confirm', $draft))->assertRedirect();

        Mail::assertSent(SalesReminderMail::class, 1);
        $log = SalesReminder::query()->forContext($this->ledger)->get();
        $this->assertCount(1, $log);
        $this->assertSame('handed_to_mailer', $log->first()->outcome);
        $this->assertSame('after_due', $log->first()->stage);
        $this->assertSame(46, $log->first()->offset_days);

        // asking again the same day drafts again but the log's unique key stops a second e-mail
        $again = $this->ask('Remind Beta about their overdue invoice');
        $this->post(route('intel.assistant.draft.confirm', $again->drafts->first()))->assertRedirect();
        Mail::assertSent(SalesReminderMail::class, 1);
        $this->assertSame(1, DB::table('sales_reminders')->where('team_id', $this->company->id)->count());
    }
}
