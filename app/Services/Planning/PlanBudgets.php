<?php

namespace App\Services\Planning;

use App\Models\Accounting\GlAccount;
use App\Models\Planning\PlanBudget;
use App\Models\Planning\PlanBudgetLine;
use App\Models\User;
use App\Services\Controls\ApprovalService;
use App\Services\Reporting\Books;
use App\Services\Reporting\ReportRequest;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Plans: create, fill, submit for approval, version, and re-forecast. A plan is a set of amounts; it never writes a journal.
 * Everything that changes more than one row runs in a transaction and is audited.
 */
class PlanBudgets
{
    public const MAX_MONTHS = 24;

    public function __construct(private readonly ApprovalService $approvals, private readonly Books $books) {}

    /** @param  array{name: string, starts_on: string, ends_on: string, currency: string, fiscal_year?: int}  $a */
    public function create(LedgerContext $context, User $by, array $a, string $kind = 'budget', ?PlanBudget $basedOn = null): PlanBudget
    {
        $start = CarbonImmutable::parse($a['starts_on'])->startOfMonth();
        $end = CarbonImmutable::parse($a['ends_on'])->endOfMonth();

        if ($end->lt($start) || $start->diffInMonths($end) >= self::MAX_MONTHS) {
            throw new InvalidArgumentException('A plan covers one to '.self::MAX_MONTHS.' months.');
        }

        return DB::transaction(fn () => PlanBudget::create($context->ownerAttributes() + [
            'name' => $a['name'], 'kind' => $kind, 'fiscal_year' => $a['fiscal_year'] ?? (int) $end->year, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
            'currency' => $a['currency'], 'status' => 'draft', 'based_on_id' => $basedOn?->id, 'version' => $basedOn ? $basedOn->version + 1 : 1, 'created_by' => $by->id,
        ]));
    }

    /** The first day of every month the plan covers. @return list<string> */
    public function months(PlanBudget $budget): array
    {
        $out = [];
        for ($c = CarbonImmutable::parse($budget->starts_on->toDateString())->startOfMonth(); $c->lte($budget->ends_on); $c = $c->addMonthNoOverflow()) {
            $out[] = $c->toDateString();
        }

        return $out;
    }

    /**
     * Replace the plan's cells. Each row: gl_account_id, optional dimension, and either months => [Y-m-01 => cents] or annual => cents
     * (spread over the plan's months, the remainder going to the earliest months). Only income and expense accounts of this ledger.
     *
     * @param  list<array{gl_account_id: int, dimension?: ?string, months?: array<string, int>, annual?: ?int}>  $rows
     */
    public function setLines(PlanBudget $budget, array $rows): int
    {
        if (! $budget->isEditable()) {
            throw new InvalidArgumentException('Only a draft or rejected plan can be edited. Make a new version instead.');
        }

        $context = $budget->ledgerContext();
        $months = $this->months($budget);
        $accounts = GlAccount::query()->forContext($context)->whereIn('class', ['income', 'expense'])->where('is_active', true)->where('is_postable', true)->get()->keyBy('id');

        $cells = [];
        foreach ($rows as $row) {
            $account = $accounts->get((int) $row['gl_account_id']) ?? throw new InvalidArgumentException('Plans use active income and expense accounts of this ledger.');
            $dimension = isset($row['dimension']) && $row['dimension'] !== '' ? (string) $row['dimension'] : null;
            if ($dimension !== null && preg_match('/^[a-z_]{2,20}:[A-Za-z0-9_.-]{1,50}$/', $dimension) !== 1) {
                throw new InvalidArgumentException("'{$dimension}' is not a department or project reference (for example department:3).");
            }

            $values = [];
            if (isset($row['annual']) && $row['annual'] !== null && $row['annual'] > 0) {
                $each = intdiv((int) $row['annual'], count($months));
                $extra = (int) $row['annual'] - $each * count($months);
                foreach ($months as $i => $m) {
                    $values[$m] = $each + ($i < $extra ? 1 : 0);
                }
            } else {
                foreach ($row['months'] ?? [] as $m => $cents) {
                    if (! in_array($m, $months, true)) {
                        throw new InvalidArgumentException("{$m} is outside the plan.");
                    }
                    $values[$m] = (int) $cents;
                }
            }

            foreach ($values as $m => $cents) {
                if ($cents < 0 || $cents > Money::MAX_CENTS) {
                    throw new InvalidArgumentException('A plan amount is between zero and '.Money::format(Money::MAX_CENTS).'.');
                }
                if ($cents === 0) {
                    continue;
                }
                $cells[$account->id.'|'.($dimension ?? '').'|'.$m] = ['account' => $account->id, 'dimension' => $dimension, 'month' => $m, 'cents' => $cents];
            }
        }

        return DB::transaction(function () use ($budget, $cells, $context) {
            PlanBudget::query()->forContext($context)->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            PlanBudgetLine::query()->forContext($context)->where('plan_budget_id', $budget->id)->delete();
            foreach ($cells as $c) {
                PlanBudgetLine::create($context->ownerAttributes() + ['plan_budget_id' => $budget->id, 'gl_account_id' => $c['account'], 'dimension' => $c['dimension'], 'month' => $c['month'], 'amount_cents' => $c['cents']]);
            }
            FinanceAudit::record($context->ownerAttributes(), 'lines_replaced', 'plan_budget', $budget->id, $budget->auditLabel(), ['cells' => ['after' => count($cells)]]);

            return count($cells);
        });
    }

    /**
     * Send for approval. When a policy covers budgets above this amount the approvers decide; otherwise the plan is
     * approved by being submitted by someone allowed to approve budgets (the caller checks the capability).
     */
    public function submit(PlanBudget $budget, User $by): PlanBudget
    {
        if (! $budget->isEditable()) {
            throw new InvalidArgumentException('Only a draft or rejected plan can be submitted.');
        }
        if (! $budget->cells()->exists()) {
            throw new InvalidArgumentException('Add at least one amount before submitting.');
        }

        return DB::transaction(function () use ($budget, $by) {
            $context = $budget->ledgerContext();
            $needs = ApprovalService::requiresApproval('budget', $budget->approvalAmountCents(), $budget->approvalCurrency(), $context);

            if ($needs) {
                $budget->forceFill(['status' => 'pending', 'submitted_at' => now()])->save();
                $this->approvals->request($by, $budget);
            } else {
                $budget->forceFill(['status' => 'approved', 'submitted_at' => now(), 'decided_by' => $by->id, 'decided_at' => now()])->save();
            }

            return $budget->refresh();
        });
    }

    /** A new editable copy of a plan (next version number, same cells). */
    public function newVersion(PlanBudget $from, User $by): PlanBudget
    {
        return DB::transaction(function () use ($from, $by) {
            $context = $from->ledgerContext();
            $copy = $this->create($context, $by, [
                'name' => $from->name, 'starts_on' => $from->starts_on->toDateString(), 'ends_on' => $from->ends_on->toDateString(), 'currency' => $from->currency, 'fiscal_year' => $from->fiscal_year,
            ], $from->kind, $from);

            foreach ($from->cells()->get() as $line) {
                PlanBudgetLine::create($context->ownerAttributes() + ['plan_budget_id' => $copy->id, 'gl_account_id' => $line->gl_account_id, 'dimension' => $line->dimension, 'month' => $line->month->toDateString(), 'amount_cents' => $line->amount_cents]);
            }

            return $copy;
        });
    }

    /**
     * A rolling re-forecast of a plan as at a date: months that have ended are the actuals from the books, months still to
     * come are either the plan as it stood ('hold') or the average of the last three months of actuals ('run_rate').
     * It is a new plan (kind reforecast) linked to the original; the original is untouched and nothing is posted.
     *
     * @param  'hold'|'run_rate'  $method
     */
    public function reforecast(PlanBudget $base, CarbonImmutable $asOf, string $method, User $by, ReportRequest $request): PlanBudget
    {
        if (! in_array($method, ['hold', 'run_rate'], true)) {
            throw new InvalidArgumentException('Unknown re-forecast method.');
        }
        if ($base->status !== 'approved') {
            throw new InvalidArgumentException('Re-forecast an approved plan.');
        }

        $context = $base->ledgerContext();
        $months = $this->months($base);
        $closed = array_values(array_filter($months, fn ($m) => CarbonImmutable::parse($m)->endOfMonth()->toDateString() <= $asOf->toDateString()));
        $future = array_values(array_diff($months, $closed));

        $accounts = GlAccount::query()->forContext($context)->whereIn('class', ['income', 'expense'])->get()->keyBy('id');
        $budgetCells = [];
        foreach ($base->cells()->get() as $line) {
            $budgetCells[$line->gl_account_id.'|'.($line->dimension ?? '')][$line->month->toDateString()] = $line->amount_cents;
        }

        $actual = [];
        if ($closed !== []) {
            $from = $closed[0];
            $to = CarbonImmutable::parse(end($closed))->endOfMonth()->toDateString();
            foreach ($this->books->plRows($request, $from, $to, ['gl_account_id', 'month', 'dimension']) as $row) {
                $a = $accounts->get((int) $row['gl_account_id']);
                if ($a === null) {
                    continue;
                }
                $natural = $a->class === 'income' ? -$row['net'] : $row['net'];
                $actual[$row['gl_account_id'].'|'.($row['dimension'] ?? '')][$row['month'].'-01'] = $natural;
            }
        }

        $keys = array_unique(array_merge(array_keys($budgetCells), array_keys($actual)));
        sort($keys);

        $forecast = $this->create($context, $by, [
            'name' => $base->name.' (re-forecast as at '.$asOf->format('j M Y').')', 'starts_on' => $base->starts_on->toDateString(), 'ends_on' => $base->ends_on->toDateString(), 'currency' => $base->currency, 'fiscal_year' => $base->fiscal_year,
        ], 'reforecast', $base);

        DB::transaction(function () use ($forecast, $keys, $closed, $future, $budgetCells, $actual, $method, $context, $asOf, $by) {
            foreach ($keys as $key) {
                [$accountId, $dimension] = explode('|', $key, 2);
                $recent = array_slice($closed, -3);
                $avg = $recent === [] ? null : Money::divideRound(array_sum(array_map(fn ($m) => max(0, $actual[$key][$m] ?? 0), $recent)), count($recent));

                $cells = [];
                foreach ($closed as $m) {
                    $cells[$m] = max(0, $actual[$key][$m] ?? 0);
                }
                foreach ($future as $m) {
                    $cells[$m] = $method === 'run_rate' && $avg !== null ? $avg : ($budgetCells[$key][$m] ?? 0);
                }
                foreach ($cells as $m => $cents) {
                    if ($cents > 0) {
                        PlanBudgetLine::create($context->ownerAttributes() + ['plan_budget_id' => $forecast->id, 'gl_account_id' => (int) $accountId, 'dimension' => $dimension === '' ? null : $dimension, 'month' => $m, 'amount_cents' => $cents]);
                    }
                }
            }
            $forecast->forceFill(['method' => $method, 'as_of' => $asOf->toDateString()])->save();
            FinanceAudit::record($context->ownerAttributes(), 'reforecast', 'plan_budget', $forecast->id, $forecast->auditLabel(), ['method' => ['after' => $method], 'as_of' => ['after' => $asOf->toDateString()]], null, null, $by->id);
        });

        return $forecast->refresh();
    }

    /** Total of a plan's cells per class. @return array{income: int, expense: int} */
    public function totals(PlanBudget $budget): array
    {
        $rows = $budget->cells()->join('gl_accounts as g', 'g.id', '=', 'plan_budget_lines.gl_account_id')->groupBy('g.class')
            ->selectRaw('g.class, SUM(plan_budget_lines.amount_cents) AS total')->pluck('total', 'class');

        return ['income' => (int) ($rows['income'] ?? 0), 'expense' => (int) ($rows['expense'] ?? 0)];
    }
}
