<?php

namespace App\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;

/**
 * What the shared sign-in package needs to know about Finance's users table. Matching rules and the claim flow live in the
 * package; this class only reads and writes users. It never touches a password, a team or company membership, two-factor
 * settings, tokens, or a ledger: ledgers hang off the user row, which is attached to, never recreated.
 */
class FinanceProvisioner implements UserProvisioner
{
    public function findByUuid(string $uuid): ?Authenticatable
    {
        return User::query()->where('infodot_user_uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->infodot_user_uuid : null;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user instanceof User && $user->email_verified_at !== null;
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        $user->forceFill(['infodot_user_uuid' => $uuid])->save();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        return app(ProvisionUserFromInfoDot::class)->create($claims['email'], $claims['name'], $claims['sub']);
    }

    public function afterSignIn(Authenticatable $user, array $claims): void
    {
        // Nothing yet: company ledgers come from the team sync of the legacy /go handoff (company data is not in the OIDC token
        // until phase 3/4). The personal ledger exists from the first sign-in because the personal team is made at creation.
    }

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        Mail::to($user->email)->send(new SsoClaimCodeMail($user->name, $code, $minutes));
    }
}
