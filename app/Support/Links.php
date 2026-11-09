<?php

namespace App\Support;

/**
 * Links stored with a finding, a notification or an answer are kept as paths on this app ("/transactions?type=expense"),
 * never as full URLs: the host a console job or a queue worker believes in is not always the host a person is using, and
 * a stored absolute URL would keep pointing at the old one. A link to another host is kept as it is.
 */
final class Links
{
    public static function relative(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $app = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host !== null && strcasecmp($host, (string) $app) !== 0 && strcasecmp($host, (string) (request()?->getHost())) !== 0) {
            return $url;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /** The absolute form, for an e-mail. */
    public static function absolute(?string $link): ?string
    {
        return $link === null ? null : (str_starts_with($link, '/') ? url($link) : $link);
    }
}
