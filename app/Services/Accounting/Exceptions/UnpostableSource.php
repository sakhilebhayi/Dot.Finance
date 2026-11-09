<?php

namespace App\Services\Accounting\Exceptions;

/** A source row that cannot be posted right now (no exchange rate, amount rounds to nothing). The cash ledger is unaffected; the sweeper retries. */
class UnpostableSource extends AccountingException {}
