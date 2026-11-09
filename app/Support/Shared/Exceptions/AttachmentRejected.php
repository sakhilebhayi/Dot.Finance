<?php

namespace App\Support\Shared\Exceptions;

use RuntimeException;

/** A file was refused: too large, empty, an unsupported type, or unreadable. */
final class AttachmentRejected extends RuntimeException {}
