<?php

namespace App\Services\Tax\Exceptions;

/** A payroll import payload that was refused. status is the HTTP status the endpoint answers with. */
final class ImportRejected extends TaxException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
