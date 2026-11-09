<?php

namespace Tests\Unit\Shared;

use App\Support\Glossary;
use PHPUnit\Framework\TestCase;

class GlossaryTest extends TestCase
{
    public function test_there_are_at_least_eighty_terms(): void
    {
        $this->assertGreaterThanOrEqual(80, count(Glossary::all()));
    }

    public function test_every_key_has_a_label_and_a_one_sentence_explanation(): void
    {
        foreach (Glossary::all() as $key => $term) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key, "key {$key} is snake_case");
            $this->assertNotSame('', trim($term['label']), "{$key} has a label");
            $this->assertNotSame('', trim($term['explanation']), "{$key} has an explanation");
            $this->assertLessThanOrEqual(60, mb_strlen($term['label']), "{$key} label is short");
            $this->assertGreaterThanOrEqual(25, mb_strlen($term['explanation']), "{$key} explanation says something");
            $this->assertLessThanOrEqual(300, mb_strlen($term['explanation']), "{$key} explanation is one readable sentence");
            $this->assertStringEndsWith('.', $term['explanation'], "{$key} explanation is a sentence");
            $this->assertDoesNotMatchRegularExpression('/\.\s+\S/', $term['explanation'], "{$key} explanation is ONE sentence");
            $this->assertNotSame(mb_strtolower($term['label']), mb_strtolower(rtrim($term['explanation'], '.')), "{$key} explanation does not just repeat the label");
        }
    }

    public function test_labels_are_unique(): void
    {
        $labels = array_map(fn (array $t) => mb_strtolower($t['label']), Glossary::all());

        $this->assertSame(count($labels), count(array_unique($labels)), 'Two terms share a label: '.implode(', ', array_diff_assoc($labels, array_unique($labels))));
    }

    public function test_it_uses_south_african_spelling(): void
    {
        $american = ['organization', 'organize', 'color', 'center ', 'analyze', 'recognize', 'honor', 'favor', 'labor', 'program ', 'defense', 'license ', 'meter'];

        foreach (Glossary::all() as $key => $term) {
            $text = mb_strtolower($term['label'].' '.$term['explanation'].' ');
            foreach ($american as $word) {
                $this->assertStringNotContainsString($word, $text, "{$key} uses the American spelling '{$word}'");
            }
        }
    }

    public function test_the_south_african_context_terms_are_present(): void
    {
        foreach (['vat', 'vat201', 'sars', 'efiling', 'paye', 'uif', 'sdl', 'eti', 'emp201', 'provisional_tax', 'popia', 'prn', 'tax_invoice', 'credit_note', 'base_currency'] as $key) {
            $this->assertTrue(Glossary::has($key), "missing {$key}");
        }
    }

    public function test_the_four_figure_labels_exist(): void
    {
        foreach (['label_calculated', 'label_estimate', 'label_user_provided', 'label_ai_suggestion'] as $key) {
            $this->assertTrue(Glossary::has($key));
        }
    }

    public function test_lookups(): void
    {
        $this->assertSame('Value-Added Tax (VAT)', Glossary::label('vat'));
        $this->assertStringContainsString('SARS', Glossary::explain('vat'));
        $this->assertTrue(Glossary::has('vat'));
        $this->assertFalse(Glossary::has('nonsense_term'));
        $this->assertSame('Nonsense term', Glossary::label('nonsense_term'), 'an unknown key shows itself rather than breaking a page');
        $this->assertSame('', Glossary::explain('nonsense_term'));
        $this->assertSame(array_keys(Glossary::all()), Glossary::keys());
    }

    public function test_acronyms_are_glossed_where_they_are_used(): void
    {
        // Any acronym that has its own entry must not appear in another explanation without the full words
        // appearing in the same sentence, unless the entry for that acronym is the thing being explained.
        $needs = ['SDL' => 'Skills Development Levy', 'UIF' => 'Unemployment Insurance Fund', 'PAYE' => 'Pay-As-You-Earn', 'ETI' => 'Employment Tax Incentive'];

        foreach (Glossary::all() as $key => $term) {
            foreach ($needs as $short => $long) {
                if (preg_match('/\b'.$short.'\b/', $term['explanation']) === 1) {
                    $this->assertStringContainsString($long, $term['explanation'], "{$key} uses {$short} without saying what it is");
                }
            }
        }
    }
}
