<?php

namespace App\Support\Ledger;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whose books a piece of work is about: one person's personal ledger, or one
 * company's. Every ledger row belongs to exactly one.
 *
 * A context is only a name. It carries no permission: whether the signed-in person
 * may use it is decided where it is resolved (LedgerContextManager) and by
 * LedgerAccess.
 *
 * Console commands, queued jobs and anything else with no signed-in person must
 * name their context explicitly, with the one documented helper:
 *
 *     LedgerContext::run(LedgerContext::company($team), function () { ... });
 *
 * Inside the callback the shared scope behaves as if that context were active.
 * It is released afterwards, even when the callback throws.
 */
final class LedgerContext
{
    private function __construct(
        public readonly ?int $userId,
        public readonly ?int $teamId,
        private ?string $name = null,
    ) {}

    /** The personal ledger of a person. */
    public static function personal(User|int $user): self
    {
        return new self($user instanceof User ? $user->id : $user, null, 'Personal');
    }

    /**
     * A company's ledger. Only a real company is one: a non-personal team that InfoDot
     * knows (it has an InfoDot uuid). A personal team or a hand-made team is refused.
     */
    public static function company(Team|int $team): self
    {
        $model = $team instanceof Team ? $team : Team::find($team);

        if (! $model instanceof Team || ! $model->isCompany()) {
            throw new InvalidArgumentException('A company ledger needs a company: a non-personal team linked to an InfoDot company.');
        }

        return new self(null, $model->id, $model->name);
    }

    /** The context a row's owner columns describe. */
    public static function fromOwner(?int $userId, ?int $teamId): self
    {
        if (($userId === null) === ($teamId === null)) {
            throw new LedgerContextViolation('A ledger row belongs to exactly one owner: a user or a company, never both and never neither.');
        }

        return new self($userId, $teamId);
    }

    /** The active context for this request or callback, or null when none can be established (the scope then shows nothing). */
    public static function current(): ?self
    {
        return app(LedgerContextManager::class)->current();
    }

    /** Run $callback with $context active. The documented way for console and queue code to name a context. */
    public static function run(self $context, callable $callback): mixed
    {
        return app(LedgerContextManager::class)->run($context, $callback);
    }

    /**
     * Every context that owns at least one row in $table, as ids only. This is the one
     * deliberate cross-context read in the app, for work that must visit every ledger
     * in turn (the scheduler, the weekly job, the balance check); callers then do the
     * real work one context at a time inside run().
     *
     * @return Collection<int, self>
     */
    public static function owningRowsIn(string $table, bool $liveOnly = false): Collection
    {
        $query = DB::table($table)->select('user_id', 'team_id')->distinct();

        if ($liveOnly) {
            $query->whereNull('deleted_at');
        }

        return $query->orderBy('team_id')->orderBy('user_id')->get()
            ->map(fn ($row) => self::fromOwner($row->user_id !== null ? (int) $row->user_id : null, $row->team_id !== null ? (int) $row->team_id : null))
            ->values();
    }

    public function isCompany(): bool
    {
        return $this->teamId !== null;
    }

    public function isPersonal(): bool
    {
        return $this->userId !== null;
    }

    /** 'personal:5' or 'company:12': stable, comparable, safe to store. */
    public function key(): string
    {
        return $this->isCompany() ? 'company:'.$this->teamId : 'personal:'.$this->userId;
    }

    /** The owner columns a row in this context carries. @return array{user_id: ?int, team_id: ?int} */
    public function ownerAttributes(): array
    {
        return ['user_id' => $this->userId, 'team_id' => $this->teamId];
    }

    public function equals(self $other): bool
    {
        return $this->userId === $other->userId && $this->teamId === $other->teamId;
    }

    public function team(): ?Team
    {
        return $this->teamId !== null ? Team::find($this->teamId) : null;
    }

    /** What to call it on screen: "Personal" or the company's name. */
    public function label(): string
    {
        return $this->name ??= ($this->isCompany() ? (string) ($this->team()?->name ?? 'Company') : 'Personal');
    }
}
