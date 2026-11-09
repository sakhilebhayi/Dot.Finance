<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Reports\Report;
use Illuminate\Contracts\Container\Container;

/** Every report by key. Reports are resolved lazily so listing them costs nothing. */
final class ReportRegistry
{
    /** @var array<string, callable(): Report> */
    private array $factories = [];

    public function __construct(private readonly Container $container) {}

    /** @param  callable(): Report  $factory */
    public function register(string $key, callable $factory): void
    {
        $this->factories[$key] = $factory;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->factories);
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    public function get(string $key): Report
    {
        $factory = $this->factories[$key] ?? abort(404);

        return $factory();
    }

    /** @return array<string, Report> */
    public function all(): array
    {
        return array_map(fn ($factory) => $factory(), $this->factories);
    }

    public static function groupLabel(string $group): string
    {
        return ['statements' => 'Financial statements', 'ledgers' => 'Ledgers and ageing', 'analysis' => 'Analysis', 'tax' => 'Tax', 'planning' => 'Planning'][$group] ?? ucfirst($group);
    }
}
