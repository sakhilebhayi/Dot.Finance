<?php

namespace App\Services\Accounting\Exceptions;

/** No exchange rate is available for a currency pair on a date. */
class MissingFxRate extends UnpostableSource {}
