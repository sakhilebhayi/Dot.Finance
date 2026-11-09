<?php

namespace App\Support\Shared\Exceptions;

use LogicException;

/** A versioned rule row was edited, deleted or overlapped against its append-only contract. */
final class VersionedRowViolation extends LogicException {}
