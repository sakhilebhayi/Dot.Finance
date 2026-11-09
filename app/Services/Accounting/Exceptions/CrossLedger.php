<?php

namespace App\Services\Accounting\Exceptions;

/** A posting that names an account, cash account or entry from another ledger, or has no ledger to post to. */
class CrossLedger extends AccountingException {}
