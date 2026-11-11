<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Always the same answer, whether or not the address has an account (no account enumeration). The broker also
     * rate-limits per address (auth.passwords.users.throttle) and the route per IP.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => Str::lower($request->validated('email'))]);

        return ApiResponse::success('If an account exists for that email, a reset link has been sent.');
    }

    /**
     * Reset with the emailed token (stored hashed by the broker, one use, expires after auth.passwords.users.expire
     * minutes). Having received the mail proves the mailbox, so the address is marked verified too. Remembered logins
     * and API tokens are revoked.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            ['email' => Str::lower($request->validated('email')), 'password' => $request->validated('password'), 'password_confirmation' => $request->input('password_confirmation'), 'token' => $request->validated('token')],
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                // Other browsers: AuthenticateSession drops sessions whose stored password hash no longer matches; with the
                // database session driver the rows are removed outright.
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
                AuthService::clearLoginThrottles($user->email);
                if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
                    event(new Verified($user));
                }
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This password reset link is invalid or has expired. Request a new one.']]);
        }

        return ApiResponse::success('Password reset. You can sign in now.');
    }
}
