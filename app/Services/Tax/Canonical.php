<?php

namespace App\Services\Tax;

/**
 * A stable JSON form for hashing. PostgreSQL's jsonb does not keep the order of an object's keys, so a hash taken over the
 * array as built would not match a hash taken over the same data read back. Objects are sorted by key at every depth; lists
 * keep their order (jsonb keeps array order).
 */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map([self::class, 'sort'], $value);
        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
