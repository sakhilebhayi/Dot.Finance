<?php

namespace Tests\Unit;

use App\Services\Tax\TaxLabels;
use PHPUnit\Framework\TestCase;

class TaxLabelsTest extends TestCase
{
    public function test_a_stored_category_letter_becomes_the_length_of_the_period(): void
    {
        $this->assertSame('Sep 2026 to Oct 2026 (two-month period)', TaxLabels::plainPeriod('Sep 2026 to Oct 2026 (category B)', '2026-09-01', '2026-10-31'));
        $this->assertSame('Mar 2026 to Aug 2026 (six-month period)', TaxLabels::plainPeriod('Mar 2026 to Aug 2026 (category D)', '2026-03-01', '2026-08-31'));
        $this->assertSame('October 2026', TaxLabels::plainPeriod('October 2026', '2026-10-01', '2026-10-31'), 'a label without a letter is left alone');
        $this->assertSame('Year ending 28 February 2027', TaxLabels::plainPeriod('Year ending 28 February 2027', null, null));
    }

    public function test_statuses_are_in_words_and_only_decisions_draw_the_eye(): void
    {
        $this->assertSame('Applies to you', TaxLabels::obligationStatus('applies'));
        $this->assertSame('Needs a decision', TaxLabels::obligationStatus('review'));
        $this->assertSame('Not set up', TaxLabels::obligationStatus('not_configured'));
        $this->assertSame('Some new state', TaxLabels::obligationStatus('some_new_state'));
        $this->assertTrue(TaxLabels::obligationNeedsAction('review'));
        $this->assertFalse(TaxLabels::obligationNeedsAction('applies'));
    }

    public function test_rule_keys_read_as_words(): void
    {
        $this->assertSame('VAT: registration: compulsory threshold', TaxLabels::headline('vat.registration.compulsory_threshold_cents'));
        $this->assertSame('CIT: assessed loss: setoff', TaxLabels::headline('cit.assessed_loss.setoff'));
        $this->assertStringNotContainsString('_', TaxLabels::headline('some.internal_key_name'));
    }
}
