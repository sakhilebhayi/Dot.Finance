<?php

namespace App\Services\Accounting\Exceptions;

/** The entry is already reversed, or is itself a reversal. */
class AlreadyReversed extends AccountingException {}
