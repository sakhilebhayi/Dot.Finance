<?php

namespace App\Services\Accounting\Exceptions;

/** The ledger has no accounting settings (base currency, chart) yet, or accounting is not enabled where it must be. */
class AccountingNotReady extends AccountingException {}
