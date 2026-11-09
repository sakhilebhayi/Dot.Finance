<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections on every response, with an ENFORCED Content-Security-Policy.
 *
 * The nonce is minted per request, before the view renders: Vite is told to stamp it on every tag @vite
 * writes, and layouts read the same value through Vite::cspNonce() for the few inline scripts they need (the
 * theme choice is applied before first paint). Nothing else is allowed inline:
 *
 *   script-src  'self' + nonce + 'unsafe-eval'.  'unsafe-eval' is the one concession and it is Livewire's:
 *               Jetstream's profile, team and API-token pages run on Livewire 3, whose bundled Alpine compiles
 *               x-data / x-on expressions with new Function(). It is a constraint of that stack (Alpine's CSP
 *               build is not what Livewire ships). Every other page runs no Alpine expressions of its own.
 *   style-src   'self' + nonce.  No 'unsafe-inline': a style="" attribute is blocked, so a figure's width or a
 *               category colour is drawn as an SVG attribute, never as inline CSS.
 *   font-src    'self'.  Fonts are self-hosted; there is no Google Fonts request.
 *   img-src     'self' data: blob:.  data: is for the select chevron and exports; no third-party images.
 *   connect-src 'self' (+ the Vite dev server when public/hot exists, so local development keeps working).
 *   form-action 'self', frame-ancestors 'none', base-uri 'self', object-src 'none'.
 *
 * A header a route has already set is left alone. HSTS is sent on HTTPS requests only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        Vite::useCspNonce($nonce);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            (config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce),
        ];

        if ($request->isSecure() && (int) config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->viteDevServer();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".($dev ? " {$dev}" : ''),
            "style-src 'self' 'nonce-{$nonce}'".($dev ? " {$dev}" : ''),
            "font-src 'self'".($dev ? " {$dev}" : ''),
            "img-src 'self' data: blob:",
            "connect-src 'self'".($dev ? " {$dev} ".str_replace('http', 'ws', $dev) : ''),
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            'report-uri /csp-report',
        ]);
    }

    /** The Vite dev server origin, only while `npm run dev` is running locally (public/hot exists). */
    private function viteDevServer(): ?string
    {
        if (! app()->isLocal() || ! is_file(public_path('hot'))) {
            return null;
        }

        $url = trim((string) @file_get_contents(public_path('hot')));
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
