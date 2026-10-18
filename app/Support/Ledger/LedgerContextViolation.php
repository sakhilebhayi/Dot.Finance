<?php

namespace App\Support\Ledger;

use RuntimeException;

/**
 * Raised when a write would give a ledger row no owner or two owners, move it to
 * another owner, or tie it to a record in a different context (a personal account
 * with a company category, a transfer between two companies, and so on).
 *
 * Controllers validate first and answer with a form error; this is the backstop
 * that makes the same mistake impossible from any other write path (import,
 * scheduler, console, a future controller).
 */
class LedgerContextViolation extends RuntimeException {}
