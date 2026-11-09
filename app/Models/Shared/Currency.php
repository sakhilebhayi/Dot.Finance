<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

/** One currency of the ISO 4217 subset the app supports, with its minor-unit digits. A global reference list. */
class Currency extends Model
{
    protected $fillable = ['code', 'numeric_code', 'name', 'symbol', 'minor_units', 'is_active'];

    protected $casts = ['minor_units' => 'integer', 'is_active' => 'boolean'];

    /** @var array<string, int>|null */
    private static ?array $digits = null;

    /** Minor-unit digits of every active currency, by code (cached for the request). @return array<string, int> */
    public static function digitsByCode(): array
    {
        return self::$digits ??= static::query()->pluck('minor_units', 'code')->map(fn ($d) => (int) $d)->all();
    }

    /** Digits for one currency; an unknown code is an error, never a guess. */
    public static function minorUnits(string $code): int
    {
        $digits = self::digitsByCode()[strtoupper($code)] ?? null;

        if ($digits === null) {
            throw new \InvalidArgumentException("Unknown currency: {$code}");
        }

        return $digits;
    }

    public static function forgetCache(): void
    {
        self::$digits = null;
    }
}
