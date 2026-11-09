<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/**
 * No version of a tax rule, rate or code is valid on the date asked. The system refuses to answer
 * rather than fall back to a nearby or "current" value: a tax figure must come from a rule that was
 * in force on the tax point.
 */
final class NoValidTaxRule extends RuntimeException {}
