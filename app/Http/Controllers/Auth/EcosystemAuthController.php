<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->query('token');

        // A missing, empty or array token (?token[]=x) is a plain refusal, never a 500.
        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->safeRedirect($request->query('redirect')) ?? route('dashboard'));
    }

    /**
     * A deep link from InfoDot may name a page to open. Only a single-slash, same-site path is
     * honoured: no scheme or host, no '//' or backslash (browsers read those as another site),
     * no '..' and no control characters, also after percent-decoding. Anything else is ignored.
     */
    private function safeRedirect(mixed $redirect): ?string
    {
        if (! is_string($redirect) || ! preg_match('#^/[A-Za-z0-9/_\-.~%?=&+,:\#]*$#', $redirect)) {
            return null;
        }

        foreach ([$redirect, rawurldecode($redirect)] as $candidate) {
            if (! str_starts_with($candidate, '/') || str_contains($candidate, '//') || str_contains($candidate, '..') || str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
                return null;
            }
        }

        return $redirect;
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user instanceof User ? $user : null;
    }
}
