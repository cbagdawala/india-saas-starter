<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Landing\Services\LandingServiceInterface;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public const DEVICE_COOKIE = 'known_device';

    public function __construct(private readonly LandingServiceInterface $landing, private readonly TenantService $tenants) {}

    /**
     * Attempt a session (cookie) login.
     *  - 5 attempts per minute per email + IP (hard).
     *  - Per email, across all IPs: after 10 failures an hour each further failure doubles a lock-out (capped), and 100
     *    failures an hour is a hard stop. Neither applies to a browser holding the signed known-device cookie of that
     *    account (set on every successful login), so an attacker cannot lock the real owner out from other devices.
     *
     * @throws ValidationException
     */
    public function login(Request $request, string $email, string $password, bool $remember = false): User
    {
        $email = Str::lower($email);
        $cfg = (array) config('accounts.login');
        $ipKey = $email.'|'.$request->ip();
        $emailKey = 'login-email|'.$email;
        $lockKey = 'login-lock|'.$email;

        if (RateLimiter::tooManyAttempts($ipKey, (int) $cfg['per_ip_limit'])) {
            $this->throttled(RateLimiter::availableIn($ipKey));
        }

        $lockedFor = max(0, (int) Cache::get($lockKey, 0) - now()->timestamp);
        if (($lockedFor > 0 || RateLimiter::tooManyAttempts($emailKey, (int) $cfg['ceiling'])) && ! $this->isKnownDevice($request, $email)) {
            $this->throttled($lockedFor > 0 ? $lockedFor : RateLimiter::availableIn($emailKey));
        }

        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($emailKey, 3600);
            $failures = RateLimiter::attempts($emailKey);
            if ($failures >= (int) $cfg['delay_after']) {
                $delay = min(2 ** min($failures - (int) $cfg['delay_after'], 20), (int) $cfg['max_delay']);
                Cache::put($lockKey, now()->timestamp + $delay, $delay);
            }

            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($emailKey);
        Cache::forget($lockKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $this->rememberDevice($user);

        return $user;
    }

    /** @throws ValidationException */
    private function throttled(int $seconds): never
    {
        throw ValidationException::withMessages([
            'email' => [__('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
        ])->status(429);
    }

    /** Bound to the password hash: changing or resetting the password invalidates every known-device cookie of the account. */
    private function deviceSignature(int $userId, string $passwordHash): string
    {
        return hash_hmac('sha256', 'known-device|'.$userId.'|'.$passwordHash, (string) config('app.key'));
    }

    /** After a successful password reset: the owner proved the mailbox, so drop the failure counters and any lock-out. */
    public static function clearLoginThrottles(string $email): void
    {
        $email = Str::lower($email);
        RateLimiter::clear('login-email|'.$email);
        Cache::forget('login-lock|'.$email);
    }

    /** Signed, httpOnly cookie "userId.signature": proves this browser logged in to that account before. */
    private function rememberDevice(User $user): void
    {
        Cookie::queue(Cookie::make(
            self::DEVICE_COOKIE, $user->id.'.'.$this->deviceSignature($user->id, (string) $user->password),
            60 * 24 * (int) config('accounts.login.known_device_days'), '/', null, (bool) config('session.secure'), true, false, 'lax',
        ));
    }

    private function isKnownDevice(Request $request, string $email): bool
    {
        $cookie = (string) $request->cookie(self::DEVICE_COOKIE);
        if (! preg_match('/^(\d+)\.([0-9a-f]{64})$/D', $cookie, $m)) {
            return false;
        }
        $user = User::query()->where('email', $email)->first(['id', 'password']);

        return $user !== null && (int) $m[1] === (int) $user->id && hash_equals($this->deviceSignature((int) $user->id, (string) $user->password), $m[2]);
    }

    /** Create a self-serve account and sign it in (session cookie). Attributes the signup to the landing A/B variant cookie. */
    public function register(Request $request, array $data): User
    {
        $variant = $request->cookie((string) config('landing.cookies.variant'));

        $user = DB::transaction(function () use ($data, $variant) {
            $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
            $user->landing_variant = $this->landing->isKnownSlug($variant) ? $variant : null;
            $user->save();
            $this->tenants->createPersonal($user); // every account starts with a personal workspace it owns

            event(new Registered($user)); // hook point: listen to Registered for welcome emails or analytics

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
