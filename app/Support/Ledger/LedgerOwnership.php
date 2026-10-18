<?php

namespace App\Support\Ledger;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The model-level half of the owner rule (the database holds the other half).
 * Runs on every save of a ledger row:
 *
 *  - a row that names no owner inherits its parent's (a transaction takes its account's);
 *  - exactly one of user_id / team_id is set, and a team_id is a real company;
 *  - an existing row's owner never changes (rows are not moved between contexts);
 *  - every referenced record (account, category, rule) lies in the same context;
 *  - the two legs of a transfer lie in the same context.
 *
 * References are only looked up when a row is created or the reference itself changes,
 * so an ordinary edit costs no extra queries.
 */
final class LedgerOwnership
{
    public static function guard(Model $model): void
    {
        /** @var Model&HasLedgerContext $model */
        $creating = ! $model->exists;

        if ($creating && $model->user_id === null && $model->team_id === null && ($from = $model->ledgerInheritsFrom()) !== null && $model->{$from} !== null) {
            $parent = self::ownerOf($model->ledgerReferences()[$from], $model->{$from});

            if ($parent !== null) {
                $model->user_id = $parent['user_id'];
                $model->team_id = $parent['team_id'];
            }
        }

        $owner = $model->ledgerOwner();

        if (($owner['user_id'] === null) === ($owner['team_id'] === null)) {
            throw new LedgerContextViolation(class_basename($model).': a ledger row belongs to exactly one owner (a user or a company), never both and never neither.');
        }

        if (! $creating && ($model->isDirty('user_id') || $model->isDirty('team_id'))) {
            throw new LedgerContextViolation(class_basename($model).': a ledger row cannot be moved to another owner. Moving rows between contexts is not supported.');
        }

        if ($creating && $owner['team_id'] !== null && ! self::isCompany($owner['team_id'])) {
            throw new LedgerContextViolation(class_basename($model).': only a company (an InfoDot-linked, non-personal team) can own a company ledger.');
        }

        foreach ($model->ledgerReferences() as $column => $class) {
            if ($model->{$column} === null || (! $creating && ! $model->isDirty($column))) {
                continue;
            }

            $referenced = self::ownerOf($class, $model->{$column});

            if ($referenced !== null && $referenced !== $owner) {
                throw new LedgerContextViolation(class_basename($model).": {$column} points at a record in a different ledger. A record can only be used inside its own context.");
            }
        }

        if ($creating && $model->getAttribute('transfer_group') !== null && self::transferCrossesContexts($model, $owner)) {
            throw new LedgerContextViolation('A transfer must stay inside one ledger: its two legs belong to different contexts.');
        }
    }

    /** @return array{user_id: ?int, team_id: ?int}|null null when the record does not exist */
    private static function ownerOf(string $class, mixed $id): ?array
    {
        $row = DB::table((new $class)->getTable())->where('id', $id)->first(['user_id', 'team_id']);

        return $row === null ? null : [
            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'team_id' => $row->team_id !== null ? (int) $row->team_id : null,
        ];
    }

    private static function isCompany(int $teamId): bool
    {
        $team = Team::find($teamId);

        return $team instanceof Team && $team->isCompany();
    }

    /** @param  array{user_id: ?int, team_id: ?int}  $owner */
    private static function transferCrossesContexts(Model $model, array $owner): bool
    {
        return DB::table('transactions')
            ->where('transfer_group', $model->getAttribute('transfer_group'))
            ->where(function ($query) use ($owner) {
                $query->whereRaw('COALESCE(user_id, 0) <> ?', [$owner['user_id'] ?? 0])
                    ->orWhereRaw('COALESCE(team_id, 0) <> ?', [$owner['team_id'] ?? 0]);
            })
            ->exists();
    }
}
