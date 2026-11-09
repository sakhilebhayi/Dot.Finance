<?php

namespace Tests\Feature\Security;

use App\Mail\FinanceNotificationMail;
use App\Mail\SalesDocumentMail;
use App\Mail\SalesReminderMail;
use App\Models\Intelligence\FinanceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** R-44 security sweep, part 3: hostile text in e-mail bodies stays text (no markup, no script link, no raw HTML from names or notes). */
class MailTemplatesTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private const HOSTILE = '<script>alert(1)</script><img src=x onerror=alert(2)> [click](javascript:alert(3)) <a href="javascript:alert(4)">z</a>';

    private function assertInert(string $html): void
    {
        // Escaped text such as &lt;a href="javascript:..."&gt; is inert; what must not exist is a real tag.
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+onerror/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<a\s[^>]*href\s*=\s*["\']?\s*javascript:/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son(error|load|click|focus)\s*=/i', preg_replace('/&lt;.*?&gt;/s', '', $html));
    }

    public function test_a_sales_document_mail_with_hostile_text_renders_inert(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $mail = new SalesDocumentMail(self::HOSTILE, self::HOSTILE, $invoice, self::HOSTILE, 'https://finance.infodot.co.za/s/abc', '%PDF-1.4', 'invoice.pdf', null);

        $this->assertInert($mail->render());
    }

    public function test_a_reminder_mail_with_hostile_text_renders_inert(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $mail = new SalesReminderMail($invoice, 'after_due', 7, 10_000, self::HOSTILE, 'https://finance.infodot.co.za/s/abc', self::HOSTILE, null);

        $this->assertInert($mail->render());
    }

    public function test_a_notification_mail_with_hostile_text_renders_inert(): void
    {
        $this->setUpSales();
        $n = FinanceNotification::create(['team_id' => $this->company->id, 'recipient_id' => $this->owner->id, 'kind' => 'system', 'title' => self::HOSTILE, 'body' => self::HOSTILE, 'dedupe_key' => 'x']);

        $this->assertInert((new FinanceNotificationMail($n, self::HOSTILE))->render());
    }
}
