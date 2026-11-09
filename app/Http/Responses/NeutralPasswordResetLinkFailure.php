<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse as FortifyFailure;

/**
 * "We can't find a user with that email address" tells a stranger which emails have an account. Every account here is
 * created by InfoDot, so that answer would reveal who uses the Dot platforms. An unknown address now gets exactly the answer a
 * known one gets ("We have emailed your password reset link"); a real failure (the broker's own throttle) still says so.
 * (QA security D-1406.)
 */
class NeutralPasswordResetLinkFailure implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_USER) {
            return $request->wantsJson()
                ? new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)], 200)
                : back()->with('status', trans(Password::RESET_LINK_SENT));
        }

        return (new FortifyFailure($this->status))->toResponse($request);
    }
}
