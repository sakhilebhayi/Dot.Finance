<?php

namespace App\Services\Shared;

use App\Models\Account;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a payment exactly once. The caller supplies an idempotency key (a client-generated token for
 * the form, or a derived one such as "invoice-42-receipt-1"); repeating the same request with the same
 * key returns the payment that already exists instead of recording a second, and repeating the key with a
 * DIFFERENT request is refused. It writes the payment row only: the journal entry and the cash-ledger row
 * are posted by Sales / Purchases, which then set gl_entry_id and transaction_id.
 */
final class PaymentRecorder
{
    private const SAME_REQUEST = ['direction', 'party_id', 'cash_account_id', 'amount_cents', 'currency', 'paid_on', 'method', 'reference'];

    /**
     * @param  array{direction: string, party_id?: ?int, cash_account_id: int, amount_cents: int, currency?: string, fx_rate_e8?: ?int, base_amount_cents?: ?int, paid_on: string, method?: string, reference?: ?string, idempotency_key: string, notes?: ?string}  $data
     *
     * @throws IdempotencyConflict
     */
    public function record(array $data, ?User $by = null, ?LedgerContext $context = null): Payment
    {
        $context ??= LedgerContext::current() ?? throw new InvalidArgumentException('No ledger is active.');

        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 80) {
            throw new InvalidArgumentException('A payment needs an idempotency key of 1 to 80 characters.');
        }
        if (! in_array($data['direction'] ?? null, Payment::DIRECTIONS, true)) {
            throw new InvalidArgumentException('A payment direction is in or out.');
        }
        if (! is_int($data['amount_cents'] ?? null) || $data['amount_cents'] <= 0) {
            throw new InvalidArgumentException('A payment amount is a whole number of cents greater than zero.');
        }
        if (! in_array($data['method'] ?? 'eft', Payment::METHODS, true)) {
            throw new InvalidArgumentException('Unknown payment method.');
        }

        return DB::transaction(function () use ($data, $by, $context, $key) {
            $existing = Payment::query()->forContext($context)->where('idempotency_key', $key)->lockForUpdate()->first();

            $account = Account::query()->forContext($context)->find($data['cash_account_id'])
                ?? throw new InvalidArgumentException('That account is not in this ledger.');

            $currency = strtoupper($data['currency'] ?? $account->currency);
            if ($currency !== $account->currency) {
                throw new InvalidArgumentException("The payment is in {$currency} but the account holds {$account->currency}. Record it in the account's currency.");
            }

            if (($data['party_id'] ?? null) !== null) {
                Party::query()->forContext($context)->withTrashed()->find($data['party_id'])
                    ?? throw new InvalidArgumentException('That customer or supplier is not in this ledger.');
            }

            $attributes = [
                'direction' => $data['direction'],
                'party_id' => $data['party_id'] ?? null,
                'cash_account_id' => $account->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => $currency,
                'paid_on' => $data['paid_on'],
                'method' => $data['method'] ?? 'eft',
                'reference' => $data['reference'] ?? null,
            ];

            if ($existing !== null) {
                $then = $this->fingerprint($existing->only(self::SAME_REQUEST));
                $now = $this->fingerprint($attributes);

                foreach ($now as $field => $value) {
                    if ($then[$field] !== $value) {
                        throw new IdempotencyConflict("The idempotency key {$key} was already used for a different payment ({$field} differs). Repeat the same request to retry, or use a new key.");
                    }
                }

                return $existing;
            }

            try {
                return DB::transaction(fn () => Payment::query()->create($context->ownerAttributes() + $attributes + [
                    'fx_rate_e8' => $data['fx_rate_e8'] ?? null,
                    'base_amount_cents' => $data['base_amount_cents'] ?? null,
                    'idempotency_key' => $key,
                    'status' => 'recorded',
                    'recorded_by' => $by?->id,
                    'notes' => $data['notes'] ?? null,
                ]));
            } catch (UniqueConstraintViolationException) {
                // a concurrent request with the same key won the race: return its payment
                return Payment::query()->forContext($context)->where('idempotency_key', $key)->firstOrFail();
            }
        });
    }

    /** @param  array<string, mixed>  $a @return array<string, string> */
    private function fingerprint(array $a): array
    {
        $out = [];
        foreach (self::SAME_REQUEST as $field) {
            $value = $a[$field] ?? null;
            $out[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($field === 'paid_on' ? substr((string) $value, 0, 10) : (string) ($value ?? ''));
        }

        return $out;
    }
}
