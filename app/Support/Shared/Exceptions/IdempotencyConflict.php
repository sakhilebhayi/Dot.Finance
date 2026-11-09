<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/** The idempotency key was already used for a different payment: a retry must repeat the same request. */
final class IdempotencyConflict extends RuntimeException {}
