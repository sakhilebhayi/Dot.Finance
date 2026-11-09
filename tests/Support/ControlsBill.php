<?php

namespace Tests\Support;

use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/** A stand-in module record for the approvals tests: a "bill" that implements Approvable. */
class ControlsBill extends Model implements Approvable
{
    use HasLedgerContext;

    protected $table = 'controls_test_bills';

    protected $guarded = [];

    /** @var list<string> the statuses onApprovalDecided saw, in order */
    public static array $decided = [];

    public static ?string $kind = 'bill';

    public function approvalSubjectKind(): string
    {
        return self::$kind ?? 'bill';
    }

    public function approvalAmountCents(): int
    {
        return (int) $this->amount_cents;
    }

    public function approvalCurrency(): ?string
    {
        return $this->currency;
    }

    public function approvalLabel(): string
    {
        return 'Bill B-'.$this->getKey();
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        self::$decided[] = $request->status;
        $this->forceFill(['state' => $request->status])->save();
    }
}
