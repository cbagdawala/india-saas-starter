<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Target of the emailed link (web route `verification.verify`). The signed URL (HMAC with APP_KEY, expires after
     * auth.verification.expire minutes) plus the email hash prove possession of the mailbox, so no session is needed:
     * the link also works when it is opened in another browser. Always redirects into the SPA with a status.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect('/verify-email?status=invalid');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect('/verify-email?status=success');
    }

    /** Re-send the verification email (throttled in the route). */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success('Email already verified');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::success('Verification email sent');
    }
}
