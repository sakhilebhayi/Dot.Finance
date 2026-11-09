<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/** No exchange rate is on record on or before the date asked (a rate from the future is never used). */
final class NoFxRate extends RuntimeException {}
