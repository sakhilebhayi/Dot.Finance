<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/** A network rate provider is switched off in configuration (the default) or cannot be reached / understood. */
final class RateProviderDisabled extends RuntimeException {}
