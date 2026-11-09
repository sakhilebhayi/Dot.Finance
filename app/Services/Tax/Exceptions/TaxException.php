<?php

namespace App\Services\Tax\Exceptions;

use RuntimeException;

/** A refusal the person can act on; the message is shown as is. */
class TaxException extends RuntimeException {}
