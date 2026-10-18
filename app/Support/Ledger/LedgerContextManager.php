<?php

namespace App\Support\Ledger;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Works out which ledger the current work is about, and refuses to guess.
 *
 * In order:
 *  1. a context named explicitly with LedgerContext::run() (console, queue, tests);
 *  2. otherwise the signed-in person's choice, kept in the session
 *     (SESSION_KEY: "personal", or the id of a company team), personal by default.
 *
 * A company choice only counts while the person still belongs to that company and it
 * is still a company. Anything else (nobody signed in, a company they were removed
 * from, a value that is not a context at all) yields NO context, and the shared scope
 * then shows no rows. It never falls back to someone else's data.
 */
final class LedgerContextManager
{
    public const SESSION_KEY = 'ledger.context';

    /** @var list<LedgerContext> */
    private array $stack = [];

    public function run(LedgerContext $context, callable $callback): mixed
    {
        $this->stack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    public function current(): ?LedgerContext
    {
        if ($this->stack !== []) {
            return $this->stack[array_key_last($this->stack)];
        }

        $user = Auth::user();

        return $user instanceof User ? $this->resolve($user, $this->stored()) : null;
    }

    /**
     * The context $requested names for $user, or null when it names something they
     * may not use. null/'personal' is the personal ledger; a team id is a company.
     */
    public function resolve(User $user, mixed $requested): ?LedgerContext
    {
        if ($requested === null || $requested === 'personal') {
            return LedgerContext::personal($user);
        }

        if (is_int($requested) || (is_string($requested) && ctype_digit($requested))) {
            $team = $this->companies($user)->firstWhere('id', (int) $requested);

            return $team instanceof Team ? LedgerContext::company($team) : null;
        }

        return null;
    }

    /** Personal first, then every company the person belongs to. @return list<LedgerContext> */
    public function contextsFor(User $user): array
    {
        return array_merge(
            [LedgerContext::personal($user)],
            $this->companies($user)->map(fn (Team $team) => LedgerContext::company($team))->values()->all(),
        );
    }

    /** Remember the person's choice for the rest of the session. */
    public function remember(LedgerContext $context): void
    {
        app('session.store')->put(self::SESSION_KEY, $context->isCompany() ? $context->teamId : 'personal');
    }

    /**
     * What the session currently holds, untrusted. Read from the session store itself rather
     * than the request, so it is the same store whether or not the request middleware has
     * attached it yet (route-model binding, queued work in a web process, tests).
     */
    public function stored(): mixed
    {
        return app('session.store')->get(self::SESSION_KEY);
    }

    /** The company teams $user belongs to (as owner or member), ordered by name. */
    private function companies(User $user): Collection
    {
        return $user->allTeams()->filter(fn (Team $team) => $team->isCompany())->values();
    }
}
