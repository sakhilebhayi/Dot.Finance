<?php

namespace App\Events;

use App\Models\Controls\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An approval request reached a final state. Dispatched after the database commit.
 * $decision is 'approved', 'rejected' or 'cancelled'; the request carries who and why.
 * See App\Contracts\Approvable for how modules use this together with onApprovalDecided().
 */
final class ApprovalDecided
{
    use Dispatchable;

    public function __construct(public readonly ApprovalRequest $request, public readonly string $decision) {}
}
