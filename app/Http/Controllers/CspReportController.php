<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports. Anonymous on purpose (a browser sends them without a
 * session) and therefore treated as untrusted input: only the violated directive and the HOST of the blocked
 * address are kept, never a query string, a path or any text the page held.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = json_decode((string) $request->getContent(), true);
        $report = is_array($raw) ? ($raw['csp-report'] ?? $raw[0]['body'] ?? $raw) : [];

        if (is_array($report)) {
            $directive = (string) ($report['effective-directive'] ?? $report['violated-directive'] ?? $report['effectiveDirective'] ?? 'unknown');
            $blocked = (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? '');
            $host = parse_url($blocked, PHP_URL_HOST) ?: (in_array($blocked, ['inline', 'eval', 'data', 'blob', 'self'], true) ? $blocked : 'unknown');

            Log::channel(config('logging.default'))->warning('csp.violation', [
                'directive' => mb_substr(preg_replace('/[^a-z\-]/i', '', $directive) ?? '', 0, 40),
                'blocked' => mb_substr((string) $host, 0, 80),
            ]);
        }

        return response()->noContent();
    }
}
