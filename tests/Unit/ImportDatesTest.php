<?php

namespace Tests\Unit;

use App\Support\ImportDates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportDatesTest extends TestCase
{
    #[DataProvider('parses')]
    public function test_parse_reads_a_date_in_the_chosen_format(string $raw, string $format, ?string $expected): void
    {
        $this->assertSame($expected, ImportDates::parse($raw, $format));
    }

    public static function parses(): array
    {
        return [
            'iso' => ['2026-08-01', 'ymd', '2026-08-01'],
            'iso slashes' => ['2026/08/01', 'ymd', '2026-08-01'],
            'iso with time' => ['2026-08-01 14:30:00', 'ymd', '2026-08-01'],
            'iso with T' => ['2026-08-01T14:30:00Z', 'ymd', '2026-08-01'],
            'dmy slashes' => ['03/04/2026', 'dmy', '2026-04-03'],
            'dmy dashes' => ['03-04-2026', 'dmy', '2026-04-03'],
            'dmy dots' => ['03.04.2026', 'dmy', '2026-04-03'],
            'dmy single digits' => ['3/4/2026', 'dmy', '2026-04-03'],
            'mdy slashes' => ['03/04/2026', 'mdy', '2026-03-04'],
            'mdy with time' => ['03/04/2026 09:15', 'mdy', '2026-03-04'],
            'impossible day' => ['31/02/2026', 'dmy', null],
            'month 13 under mdy' => ['13/01/2026', 'mdy', null],
            'month 13 under dmy is fine as a day' => ['13/01/2026', 'dmy', '2026-01-13'],
            'two digit year is refused' => ['03/04/26', 'dmy', null],
            'wrong shape for ymd' => ['03/04/2026', 'ymd', null],
            'garbage' => ['yesterday', 'dmy', null],
            'empty' => ['', 'dmy', null],
            'leap day' => ['29/02/2024', 'dmy', '2024-02-29'],
            'not a leap day' => ['29/02/2026', 'dmy', null],
        ];
    }

    #[DataProvider('detects')]
    public function test_detect_commits_only_when_the_whole_column_agrees(array $samples, ?string $format, bool $ambiguous): void
    {
        $result = ImportDates::detect($samples);

        $this->assertSame($format, $result['format']);
        $this->assertSame($ambiguous, $result['ambiguous']);
    }

    public static function detects(): array
    {
        return [
            'iso is unambiguous' => [['2026-08-01', '2026-08-02'], 'ymd', false],
            'a day above 12 first means dmy' => [['13/04/2026', '03/04/2026'], 'dmy', false],
            'a day above 12 second means mdy' => [['04/13/2026', '03/04/2026'], 'mdy', false],
            'all values could be either' => [['03/04/2026', '05/06/2026'], null, true],
            'one value only, could be either' => [['03/04/2026'], null, true],
            'evidence for both is a conflict, not a guess' => [['13/04/2026', '04/13/2026'], null, true],
            'iso mixed with slashes is a conflict' => [['2026-08-01', '03/04/2026'], null, true],
            'no parseable dates' => [['hello', ''], null, true],
            'empty column' => [[], null, true],
            'unambiguous because day equals month' => [['05/05/2026', '06/06/2026'], 'dmy', false],
        ];
    }
}
