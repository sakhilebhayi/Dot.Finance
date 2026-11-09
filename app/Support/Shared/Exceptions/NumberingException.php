<?php

namespace App\Support\Shared\Exceptions;

use LogicException;

/** A document number was requested in a way that could leave a gap (outside a transaction, for an unknown ledger). */
final class NumberingException extends LogicException {}
