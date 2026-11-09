<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Accounting\Exceptions\CrossLedger;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The chart of accounts of a ledger: creating it from a template, looking system accounts up,
 * mapping cash accounts and categories to GL accounts, and the few edits a chart allows.
 *
 * Accounts are never deleted. A system account (one the posting engine looks up by key) can be
 * renamed and renumbered but not deactivated, re-classed or re-keyed.
 */
class Chart
{
    /** @var array<string, array<string, int>> */
    private array $systemMemo = [];

    public function flush(): void
    {
        $this->systemMemo = [];
    }

    /**
     * Create every account of $template that the ledger does not have yet (matched by system key,
     * else by code). Idempotent: running it twice changes nothing the second time.
     *
     * @return int how many accounts were created
     */
    public function ensure(LedgerContext $context, string $template): int
    {
        $created = 0;

        DB::transaction(function () use ($context, $template, &$created) {
            $existing = GlAccount::query()->forContext($context)->get();
            $byKey = $existing->whereNotNull('system_key')->keyBy('system_key');
            $byCode = $existing->keyBy('code');

            foreach (ChartTemplates::rows($template) as [$code, $name, $class, $subtype, $key, $options]) {
                if (($key !== null && $byKey->has($key)) || $byCode->has($code)) {
                    continue;
                }

                $account = GlAccount::create($context->ownerAttributes() + [
                    'code' => $code,
                    'name' => $name,
                    'class' => $class,
                    'subtype' => $subtype,
                    'normal_side' => $options['normal'] ?? GlAccount::normalSideFor($class),
                    'system_key' => $key,
                    'is_active' => $options['active'] ?? true,
                    'is_postable' => $options['postable'] ?? true,
                ]);

                $byCode->put($code, $account);
                $created++;
            }
        });

        $this->flush();

        return $created;
    }

    /** @return array<string, int> system key => gl_accounts.id */
    public function systemIds(LedgerContext $context): array
    {
        return $this->systemMemo[$context->key()] ??= GlAccount::query()->forContext($context)->whereNotNull('system_key')->pluck('id', 'system_key')->all();
    }

    public function system(string $key, ?LedgerContext $context = null): GlAccount
    {
        $context ??= LedgerContext::current() ?? throw new AccountingNotReady('There is no active ledger.');
        $id = $this->systemIds($context)[$key] ?? null;

        return $id !== null
            ? GlAccount::query()->forContext($context)->findOrFail($id)
            : throw new AccountingNotReady("This ledger's chart has no '{$key}' account. Create the chart first.");
    }

    /** True when every system key exists (the chart is complete enough to post to). */
    public function isComplete(LedgerContext $context): bool
    {
        return array_diff(ChartTemplates::SYSTEM_KEYS, array_keys($this->systemIds($context))) === [];
    }

    /**
     * Map every cash account and category of the ledger that has no GL account yet, creating one
     * each. Returns how many mappings were created.
     */
    public function mapAll(LedgerContext $context): int
    {
        $n = 0;

        DB::transaction(function () use ($context, &$n) {
            foreach (Account::query()->forContext($context)->withTrashed()->whereNull('gl_account_id')->orderBy('id')->get() as $account) {
                $this->mapAccount($account);
                $n++;
            }

            foreach (Category::query()->forContext($context)->withTrashed()->whereNull('gl_account_id')->orderBy('id')->get() as $category) {
                $this->mapCategory($category);
                $n++;
            }
        });

        return $n;
    }

    public function mapAccount(Account $account): GlAccount
    {
        $context = $account->ledgerContext();

        if ($account->gl_account_id !== null) {
            return GlAccount::query()->forContext($context)->findOrFail($account->gl_account_id);
        }

        [$class, $subtype, $range] = match ($account->type) {
            'credit' => ['liability', 'credit_card', 'credit'],
            'investment' => ['asset', 'investment', 'investment'],
            'cash' => ['asset', 'cash', 'bank'],
            default => ['asset', 'bank', 'bank'],
        };

        $gl = $this->createInRange($context, $range, $account->name, $class, $subtype, $account->currency);
        $account->forceFill(['gl_account_id' => $gl->id])->saveQuietly();

        return $gl;
    }

    public function mapCategory(Category $category): GlAccount
    {
        $context = $category->ledgerContext();

        if ($category->gl_account_id !== null) {
            return GlAccount::query()->forContext($context)->findOrFail($category->gl_account_id);
        }

        $income = $category->type === 'income';
        $gl = $this->createInRange($context, $income ? 'income' : 'expense', $category->name, $income ? 'income' : 'expense', 'category', null);
        $category->forceFill(['gl_account_id' => $gl->id])->saveQuietly();

        return $gl;
    }

    /**
     * Point a cash account at a different GL account. Refused once the account has postings: its
     * history is on the old account, and moving only the label would break the cash tie-out.
     */
    public function remapAccount(Account $account, GlAccount $target): void
    {
        $this->guardRemap($account->ledgerContext(), $target, ['asset', 'liability'], 'cash_account_id', $account->id, $account->currency);
        $before = $account->gl_account_id;
        $account->forceFill(['gl_account_id' => $target->id])->saveQuietly();
        FinanceAudit::record($account->ledgerOwner(), 'mapped', 'gl_account', $target->id, $account->name.' -> '.$target->label(), ['cash_account_id' => ['after' => $account->id], 'gl_account_id' => ['before' => $before, 'after' => $target->id]]);
    }

    public function remapCategory(Category $category, GlAccount $target): void
    {
        $this->guardRemap($category->ledgerContext(), $target, ['income', 'expense'], 'category_id', $category->id, null);
        $before = $category->gl_account_id;
        $category->forceFill(['gl_account_id' => $target->id])->saveQuietly();
        FinanceAudit::record($category->ledgerOwner(), 'mapped', 'gl_account', $target->id, $category->name.' -> '.$target->label(), ['category_id' => ['after' => $category->id], 'gl_account_id' => ['before' => $before, 'after' => $target->id]]);
    }

    /** @param list<string> $classes */
    private function guardRemap(LedgerContext $context, GlAccount $target, array $classes, string $lineColumn, int $id, ?string $currency): void
    {
        if (! $target->ledgerContext()->equals($context)) {
            throw new CrossLedger('That GL account belongs to another ledger.');
        }

        if (! in_array($target->class, $classes, true) || ! $target->is_active || ! $target->is_postable) {
            throw new AccountingException('Choose an active, postable '.implode(' or ', $classes).' account.');
        }

        if ($currency !== null && $target->currency !== null && $target->currency !== $currency) {
            throw new AccountingException("That GL account is for {$target->currency}; this account is in {$currency}.");
        }

        if (JournalLine::query()->forContext($context)->where($lineColumn, $id)->exists()) {
            throw new AccountingException('It already has journal postings, so it can no longer be pointed at a different GL account. Post an adjusting entry instead.');
        }
    }

    /**
     * Create an account the user asked for.
     *
     * @param  array{code: string, name: string, class: string, subtype?: ?string, parent_id?: ?int, currency?: ?string, description?: ?string}  $attributes
     */
    public function create(LedgerContext $context, array $attributes): GlAccount
    {
        if (! in_array($attributes['class'], GlAccount::CLASSES, true)) {
            throw new InvalidArgumentException('Unknown account class.');
        }

        $account = GlAccount::create($context->ownerAttributes() + [
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'class' => $attributes['class'],
            'subtype' => $attributes['subtype'] ?? null,
            'normal_side' => GlAccount::normalSideFor($attributes['class']),
            'parent_id' => $attributes['parent_id'] ?? null,
            'currency' => $attributes['currency'] ?? null,
            'description' => $attributes['description'] ?? null,
        ]);

        $this->flush();

        return $account;
    }

    /**
     * Rename, renumber, re-describe, change the parent. The class changes only while the account
     * has no postings, and never for a system account.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(GlAccount $account, array $attributes): GlAccount
    {
        if (isset($attributes['class']) && $attributes['class'] !== $account->class) {
            if ($account->isSystem()) {
                throw new AccountingException('A system account keeps its class: the posting engine depends on it.');
            }
            if ($account->hasPostings()) {
                throw new AccountingException('An account with postings cannot change class.');
            }
            $attributes['normal_side'] = GlAccount::normalSideFor($attributes['class']);
        }

        $account->update(array_intersect_key($attributes, array_flip(['code', 'name', 'class', 'normal_side', 'subtype', 'parent_id', 'description'])));

        return $account;
    }

    public function deactivate(GlAccount $account): void
    {
        if ($account->isSystem()) {
            throw new AccountingException('A system account cannot be deactivated: the posting engine uses it.');
        }

        $mapped = Account::query()->forContext($account->ledgerContext())->where('gl_account_id', $account->id)->exists()
            || Category::query()->forContext($account->ledgerContext())->where('gl_account_id', $account->id)->exists();

        if ($mapped) {
            throw new AccountingException('A cash account or category still maps to this account. Remap it first.');
        }

        $account->update(['is_active' => false]);
    }

    public function activate(GlAccount $account): void
    {
        $account->update(['is_active' => true]);
    }

    /** The load PostingRules needs, in four queries. */
    public function map(LedgerContext $context, string $baseCurrency): PostingMap
    {
        $cash = [];
        foreach (Account::query()->forContext($context)->withTrashed()->whereNotNull('gl_account_id')->get(['id', 'gl_account_id', 'currency', 'type']) as $a) {
            $cash[$a->id] = ['gl' => (int) $a->gl_account_id, 'currency' => $a->currency, 'type' => $a->type];
        }

        $categories = Category::query()->forContext($context)->withTrashed()->whereNotNull('gl_account_id')->pluck('gl_account_id', 'id')->map(fn ($v) => (int) $v)->all();

        return new PostingMap($baseCurrency, $this->systemIds($context), $cash, $categories);
    }

    /** The next unused 4-digit code inside a template range. */
    public function nextCode(LedgerContext $context, string $range): string
    {
        [$first, $last] = ChartTemplates::RANGES[$range];
        $used = GlAccount::query()->forContext($context)->whereBetween('code', [(string) $first, (string) $last])->pluck('code')->flip();

        for ($code = $first; $code <= $last; $code++) {
            if (! $used->has((string) $code)) {
                return (string) $code;
            }
        }

        throw new AccountingException("The {$range} range of the chart ({$first} to {$last}) is full; create the account by hand with a free code.");
    }

    private function createInRange(LedgerContext $context, string $range, string $name, string $class, string $subtype, ?string $currency): GlAccount
    {
        $gl = GlAccount::create($context->ownerAttributes() + [
            'code' => $this->nextCode($context, $range),
            'name' => mb_substr($name, 0, 120),
            'class' => $class,
            'subtype' => $subtype,
            'normal_side' => GlAccount::normalSideFor($class),
            'currency' => $currency,
        ]);

        $this->flush();

        return $gl;
    }

    public function settingsFor(LedgerContext $context): ?LedgerSetting
    {
        return LedgerSetting::query()->forContext($context)->first();
    }
}
