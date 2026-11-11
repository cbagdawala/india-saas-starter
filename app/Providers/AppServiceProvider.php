<?php

namespace App\Providers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\AuthServiceInterface;
use App\Support\BootGuards;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
    }

    public function boot(): void
    {
        BootGuards::check($this->app);

        // Named limiters, one counter each. A numeric `throttle:N,M` on a route and on its group (or on two routes) shares ONE
        // counter for the same user/IP, so a stricter per-route limit stacked on a group limit is silently halved and unrelated
        // endpoints eat each other's budget. Every route limit in this kit uses a name from here (a test enforces it).
        $perUser = fn (int $max, int $minutes = 1) => fn (Request $r) => Limit::perMinutes($minutes, $max)->by($r->user()?->getAuthIdentifier() ?: $r->ip());
        $perIp = fn (int $max) => fn (Request $r) => Limit::perMinute($max)->by($r->ip());
        // Guests: per IP + email (one address cannot be hammered from one IP), plus a looser per-IP ceiling across addresses.
        $guest = fn (int $max, int $ipMax) => fn (Request $r) => [
            Limit::perMinute($max)->by($r->ip().'|'.Str::lower((string) $r->input('email'))),
            Limit::perMinute($ipMax)->by('ip|'.$r->ip()),
        ];

        RateLimiter::for('register', $perIp(10));
        RateLimiter::for('login', $guest(10, 60));
        RateLimiter::for('forgot-password', $guest(5, 30));
        RateLimiter::for('reset-password', $guest(5, 30));
        RateLimiter::for('verification-resend', $perUser(3));
        RateLimiter::for('verification-link', $perIp(20));
        RateLimiter::for('account-api', $perUser(10));
        RateLimiter::for('tenancy-api', $perUser(60));
        RateLimiter::for('notes-api', $perUser(120));
        RateLimiter::for('invoicing-api', $perUser(120));
        RateLimiter::for('support-ticket', $perUser(5));
        RateLimiter::for('support-inbound', $perIp(60));
        RateLimiter::for('billing-checkout', $perUser(20));
        RateLimiter::for('billing-confirm', $perUser(20));
        RateLimiter::for('billing-webhook', $perIp(120));
        RateLimiter::for('workspace-create', $perUser(10));
        RateLimiter::for('invitation-send', $perUser(20));
        RateLimiter::for('invitation-accept', $perUser(10));
        RateLimiter::for('invoice-pdf', $perUser(30));
        RateLimiter::for('account-export', $perUser(3, 60));
        RateLimiter::for('account-delete', $perUser(5));

        // Every emailed link (verification, password reset, invitations) is built from APP_URL, never from the request's Host
        // header, which a client controls: with a poisoned Host a reset mail would carry a link to the attacker's server.
        // The reset token travels in the URL fragment so it never reaches access logs or Referer headers.
        $appUrl = rtrim((string) config('app.url'), '/');
        URL::forceRootUrl($appUrl);
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
        ResetPassword::createUrlUsing(fn ($user, string $token) => $appUrl.'/reset-password#token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));

        $proxies = (array) config('product.trusted_proxies');
        if ($proxies !== []) {
            TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);
        }
    }
}
