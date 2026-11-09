<?php

namespace App\Services\Accounting\Exceptions;

/** A journal entry whose debits and credits are not equal (or that has fewer than two lines). */
class UnbalancedEntry extends AccountingException {}
