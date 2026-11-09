<?php

namespace App\Services\Accounting\Exceptions;

/** The entry date falls in a closed period (on or before books_closed_through). */
class PeriodClosed extends AccountingException {}
