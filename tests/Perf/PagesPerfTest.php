<?php

namespace Tests\Perf;

use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOCAL MEASUREMENT, skipped unless PERF=1. Reads the busy ledger that tests/Support/Perf/seed.php builds (never refreshes or
 * writes the database): each page is requested cold (application caches flushed) and warm, and the wall time, the number of
 * queries, the slowest query and peak memory are appended as JSON lines to $PERF_OUT. Slow queries (over 40 ms) are
 * written in full to $PERF_OUT.slow so EXPLAIN can be run on them.
 *
 *   PERF=1 PERF_OUT=/tmp/pages.jsonl DB_CONNECTION=pgsql DB_HOST=/tmp DB_USERNAME=$(whoami) DB_PASSWORD= DB_DATABASE=qaperf_src \
 *     php -d memory_limit=512M vendor/bin/phpunit -c phpunit.pgsql.xml tests/Perf/PagesPerfTest.php
 */
class PagesPerfTest extends TestCase
{
    private int $n = 0;

    private float $slowest = 0.0;

    /** @var list<array{ms: float, sql: string}> */
    private array $slow = [];

    private bool $listening = false;

    /** @var array<string, array{n: int, ms: float}> */
    private array $templates = [];

    /** @return array<string, string> */
    private function pages(): array
    {
        $acct = (int) DB::table('gl_accounts')->where('team_id', 2)->where('code', '1011')->value('id');
        $stmt = (int) DB::table('bank_statements')->where('team_id', 2)->orderBy('id')->value('id');
        $party = (int) DB::table('parties')->where('team_id', 2)->where('type', 'customer')->orderByDesc('id')->value('id');
        $supplier = (int) DB::table('parties')->where('team_id', 2)->where('type', 'supplier')->orderByDesc('id')->value('id');

        return [
            'dashboard' => '/dashboard',
            'transactions (page 1)' => '/transactions',
            'transactions (page 5000)' => '/transactions?page=5000',
            'transactions (search)' => '/transactions?search=Checkers',
            'transactions (search, rare)' => '/transactions?search=ref%20123456',
            'transactions (filters)' => '/transactions?account_id=1&category_id=5&from=2026-01-01&to=2026-06-30&type=expense',
            'transactions (date range)' => '/transactions?from=2026-03-01&to=2026-03-31',
            'accounts' => '/accounts',
            'categories' => '/categories',
            'insights' => '/insights',
            'audit' => '/audit',
            'trial balance (accounting)' => '/accounting/trial-balance',
            'general ledger (accounting)' => '/accounting/general-ledger?account='.$acct,
            'general ledger (accounting, page 400)' => '/accounting/general-ledger?account='.$acct.'&page=400',
            'journals' => '/accounting/journals',
            'journals (page 4000)' => '/accounting/journals?page=4000',
            'chart of accounts' => '/accounting/chart',
            'report: trial balance' => '/reports/trial-balance',
            'report: general ledger' => '/reports/general-ledger?account='.$acct.'&from=2026-01-01&to=2026-12-31',
            'report: profit and loss' => '/reports/profit-loss?from=2026-01-01&to=2026-12-31',
            'report: profit and loss monthly' => '/reports/profit-loss?from=2025-11-01&to=2026-11-08&monthly=1',
            'report: balance sheet' => '/reports/balance-sheet?to=2026-11-08',
            'report: cash-flow statement' => '/reports/cash-flow-statement?from=2026-01-01&to=2026-11-08',
            'report: cash flow' => '/reports/cash-flow',
            'report: ageing receivables' => '/reports/ageing-receivables',
            'report: ageing payables' => '/reports/ageing-payables',
            'report: revenue by party' => '/reports/revenue?from=2026-01-01&to=2026-11-08&view=party',
            'report: expenses' => '/reports/expenses',
            'report: performance' => '/reports/performance?from=2026-01-01&to=2026-11-08',
            'report: vat' => '/reports/vat',
            'report: tax summary' => '/reports/tax-summary',
            'report: exports list' => '/reports/exports',
            'tax: index' => '/tax',
            'tax: vat' => '/tax/vat',
            'tax: vat review' => '/tax/vat/review',
            'tax: calendar' => '/tax/calendar',
            'tax: payroll' => '/tax/payroll',
            'tax: emp201' => '/tax/emp201',
            'bank: statements' => '/bank/statements',
            'bank: statement (5,000 lines)' => '/bank/statements/'.$stmt,
            'bank: reconciliation' => '/bank/reconciliation',
            'sales: invoices' => '/sales/invoices',
            'sales: invoices (page 800)' => '/sales/invoices?page=800',
            'sales: receivables' => '/sales/receivables',
            'sales: customers' => '/sales/customers',
            'sales: customer' => '/sales/customers/'.$party,
            'sales: customer statement' => '/sales/customers/'.$party.'/statement',
            'sales: payments' => '/sales/payments',
            'sales: reminders' => '/sales/reminders',
            'purchases: index' => '/purchases',
            'purchases: bills' => '/purchases/bills',
            'purchases: bills (page 800)' => '/purchases/bills?page=800',
            'purchases: payables (AP)' => '/purchases/ap',
            'purchases: suppliers' => '/purchases/suppliers',
            'purchases: supplier' => '/purchases/suppliers/'.$supplier,
            'purchases: payments' => '/purchases/payments',
            'planning: cash' => '/planning/cash',
            'planning: forecasts' => '/planning/forecasts',
            'planning: budgets' => '/planning/budgets',
            'attention' => '/attention',
            'notifications' => '/notifications',
            'assistant' => '/assistant',
            'controls' => '/controls',
        ];
    }

    public function test_pages_cold_and_warm(): void
    {
        if (getenv('PERF') !== '1') {
            $this->markTestSkipped('Measurement only: set PERF=1.');
        }
        $out = getenv('PERF_OUT') ?: '/tmp/perf-pages.jsonl';
        $only = getenv('PERF_ONLY') ?: null;
        $team = Team::query()->where('name', 'Karoo Roasters (Pty) Ltd')->firstOrFail();
        $user = User::query()->findOrFail($team->user_id);
        $this->actingAs($user)->withSession([LedgerContextManager::SESSION_KEY => $team->id]);

        foreach ($this->pages() as $label => $uri) {
            if ($only !== null && ! array_filter(explode('|', $only), fn ($f) => str_contains($label, $f))) {
                continue;
            }
            $row = ['page' => $label, 'uri' => $uri];
            foreach (['cold', 'warm'] as $phase) {
                if ($phase === 'cold') {
                    Cache::flush();
                }
                $this->templates = [];
                [$ms, $queries, $slowest, $status, $bytes, $peak, $slow] = $this->hit($uri);
                if ($phase === 'warm' && getenv('PERF_PROFILE') === '1') {
                    uasort($this->templates, fn ($a, $b) => $b['ms'] <=> $a['ms']);
                    $top = array_slice($this->templates, 0, 12, true);
                    file_put_contents($out.'.profile', '## '.$label."\n".implode("\n", array_map(fn ($k, $v) => sprintf('%7.0f ms x%-6d %s', $v['ms'], $v['n'], mb_substr($k, 0, 260)), array_keys($top), $top))."\n\n", FILE_APPEND);
                }
                $row[$phase] = ['ms' => $ms, 'queries' => $queries, 'slowest_ms' => $slowest, 'status' => $status, 'kb' => (int) ($bytes / 1024), 'peak_mb' => $peak];
                if ($phase === 'cold' && $slow !== []) {
                    file_put_contents($out.'.slow', json_encode(['page' => $label, 'queries' => $slow])."\n", FILE_APPEND);
                }
            }
            file_put_contents($out, json_encode($row)."\n", FILE_APPEND);
        }
        $this->assertTrue(true);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: list<array{ms: float, sql: string}>} */
    private function hit(string $uri): array
    {
        $this->n = 0;
        $this->slowest = 0.0;
        $this->slow = [];
        if (! $this->listening) {
            $this->listening = true;
            DB::listen(function ($q) {
                $this->n++;
                $this->slowest = max($this->slowest, $q->time);
                if (getenv('PERF_PROFILE') === '1') {
                    $key = preg_replace(['/\'[^\']*\'/', '/\b\d+\b/', '/\(\?(, \?)+\)/'], ['?', '?', '(...)'], $q->sql);
                    $this->templates[$key] = ($this->templates[$key] ?? ['n' => 0, 'ms' => 0.0]);
                    $this->templates[$key]['n']++;
                    $this->templates[$key]['ms'] += $q->time;
                }
                if ($q->time > 40) {
                    $this->slow[] = ['ms' => round($q->time, 1), 'sql' => $q->toRawSql()];
                }
            });
        }
        memory_reset_peak_usage();
        $t = microtime(true);
        try {
            $res = $this->get($uri);
            $status = $res->getStatusCode();
            $bytes = strlen((string) $res->getContent());
        } catch (\Throwable $e) {
            $status = 500;
            $bytes = 0;
            file_put_contents((getenv('PERF_OUT') ?: '/tmp/perf-pages.jsonl').'.errors', $uri.' '.get_class($e).' '.mb_substr($e->getMessage(), 0, 300)."\n", FILE_APPEND);
        }
        $ms = (int) round((microtime(true) - $t) * 1000);

        return [$ms, $this->n, (int) round($this->slowest), $status, $bytes, (int) round(memory_get_peak_usage(true) / 1048576), $this->slow];
    }
}
