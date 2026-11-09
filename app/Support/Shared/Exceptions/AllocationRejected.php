<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/** A payment allocation would break an invariant (too much, wrong direction, wrong ledger...). Nothing was written. */
final class AllocationRejected extends RuntimeException {}
