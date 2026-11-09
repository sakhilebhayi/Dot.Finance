<?php

namespace App\Enums;

/**
 * How a profit-and-loss figure is recognised. Accrual: on the date of the entry (an invoice when it is issued).
 * Cash: when the money moved (the part of an invoice or bill that has been paid, on the payment date).
 */
enum ReportBasis: string
{
    case Accrual = 'accrual';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Accrual',
            self::Cash => 'Cash',
        };
    }
}
