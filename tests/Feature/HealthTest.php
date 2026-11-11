<?php

use App\Models\User;
use App\Modules\System\Services\SchedulerHeartbeat;
use App\Providers\AppServiceProvider;
use App\Support\BootGuards;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

const HEALTH_TOKEN_VALUE = 'health-token-0123456789abcdef0123456789';

it('returns only the status publicly', function () {
    $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('returns details with a valid health token', function () {
    config(['product.health_token' => HEALTH_TOKEN_VALUE]);

    $this->getJson('/health', ['X-Health-Token' => HEALTH_TOKEN_VALUE])
        ->assertOk()
        ->assertJsonStructure(['status', 'db', 'cache', 'queue' => ['connection', 'pending', 'failed'], 'scheduler' => ['last_run_at', 'ok'], 'build_sha', 'time'])
        ->assertJsonPath('db', true)
        ->assertJsonPath('queue.failed', 0)
        ->assertJsonPath('scheduler.ok', false)
        ->assertJsonPath('scheduler.last_run_at', null);

    $this->getJson('/health', ['X-Health-Token' => 'wrong'])->assertExactJson(['status' => 'ok']);
});

it('reports the scheduler heartbeat and the build SHA', function () {
    config(['product.health_token' => HEALTH_TOKEN_VALUE, 'product.build_sha' => 'abc1234']);
    app(SchedulerHeartbeat::class)->beat();

    $this->getJson('/health', ['X-Health-Token' => HEALTH_TOKEN_VALUE])->assertOk()
        ->assertJsonPath('scheduler.ok', true)
        ->assertJsonPath('build_sha', 'abc1234');

    $this->travel(10)->minutes();
    $this->getJson('/health', ['X-Health-Token' => HEALTH_TOKEN_VALUE])->assertJsonPath('scheduler.ok', false);
});

it('registers the heartbeat in the schedule', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('health-heartbeat')->assertSuccessful();
});

it('never shows details for a short health token', function () {
    config(['product.health_token' => 'short-token']);

    $this->getJson('/health', ['X-Health-Token' => 'short-token'])->assertExactJson(['status' => 'ok']);
});

it('trusts only the APP_URL host and TRUSTED_HOSTS, exactly', function () {
    config(['app.url' => 'https://app.example.com', 'product.trusted_hosts' => ['starter-nginx']]);
    $hosts = app(TrustHosts::class)->hosts();

    expect($hosts)->toBe(['^app\\.example\\.com$', '^starter\\-nginx$']);
    foreach (['app.example.com', 'starter-nginx'] as $ok) {
        expect(collect($hosts)->contains(fn ($p) => preg_match('{'.$p.'}i', $ok)))->toBeTrue();
    }
    foreach (['evil.com', 'app.example.com.evil.com', 'xapp.example.com'] as $bad) {
        expect(collect($hosts)->contains(fn ($p) => preg_match('{'.$p.'}i', $bad)))->toBeFalse();
    }
});

it('returns 503 when the database is down', function () {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('db down'));

    $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
});

it('serves the SPA shell for non-api routes', function () {
    $this->withoutVite()->get('/billing')->assertOk()->assertSee('id="app"', false);
});

it('refuses to boot with APP_DEBUG=true in production', function () {
    config(['app.debug' => true]);
    app()['env'] = 'production';
    expect(fn () => BootGuards::check(app()))->toThrow(LogicException::class, 'APP_DEBUG=true');

    config(['app.debug' => false]);
    BootGuards::check(app());

    app()['env'] = 'local';
    config(['app.debug' => true]);
    BootGuards::check(app());
    expect(true)->toBeTrue();
});

it('sends security headers from the app itself, with CSP derived from config and HSTS only on HTTPS in production', function () {
    $r = $this->get('/health')->assertOk();
    expect($r->headers->get('X-Frame-Options'))->toBe('DENY')->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')->and($r->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($r->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")->not->toContain('localhost:8001')
        ->and($r->headers->has('Strict-Transport-Security'))->toBeFalse();

    config(['product.plausible.domain' => 'example.com', 'product.plausible.src' => 'https://plausible.example.org/js/script.js']);
    expect($this->get('/health')->headers->get('Content-Security-Policy'))->toContain("script-src 'self' https://plausible.example.org https://checkout.razorpay.com");

    expect($this->get('/health', ['X-Forwarded-Proto' => 'https'])->headers->has('Strict-Transport-Security'))->toBeFalse(); // not production

    app()['env'] = 'production';
    expect($this->get('https://localhost:8200/health')->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
        ->and($this->get('http://localhost:8200/health')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('trusts only configured reverse proxies', function () {
    config(['product.trusted_proxies' => ['10.0.0.0/8']]);
    $app = app();
    (new AppServiceProvider($app))->boot();
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']);
    (new TrustProxies($app))->handle($request, fn () => response('ok'));
    expect($request->ip())->toBe('203.0.113.9')->and($request->isSecure())->toBeTrue();

    $untrusted = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
    (new TrustProxies($app))->handle($untrusted, fn () => response('ok'));
    expect($untrusted->ip())->toBe('198.51.100.7');

    TrustProxies::flushState();
});

it('uses only named rate limiters on routes, each defined and with its own counter', function () {
    $names = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'throttle:')) {
                expect($m)->not->toMatch('/^throttle:\d/', "{$route->uri()} uses a numeric throttle (shared counter); use a named limiter");
                $names[] = substr($m, 9);
            }
        }
    }

    expect($names)->not->toBeEmpty();
    foreach (array_unique($names) as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull("limiter {$name} is not defined");
    }
});

it('does not let other routes consume a limiter budget', function () {
    spa();
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 20) as $_) {
        $this->actingAs($user)->getJson('/api/tenants')->assertOk();
        $this->actingAs($user)->getJson('/api/auth/me')->assertOk();
    }

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertOk(); // its own budget of 3 is intact
    }
    $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertStatus(429);
});

it('refuses APP_ENV=local with a public APP_URL unless explicitly allowed', function () {
    app()['env'] = 'local';

    foreach (['http://localhost:8200', 'http://127.0.0.1:8000', 'http://myapp.test', 'http://admin.localhost', 'http://[::1]:8000', 'http://host.docker.internal:8200'] as $url) {
        config(['app.url' => $url, 'product.allow_local_on_public_host' => false]);
        BootGuards::check(app());
    }

    config(['app.url' => 'https://app.example.com', 'product.allow_local_on_public_host' => false]);
    expect(fn () => BootGuards::check(app()))->toThrow(LogicException::class, 'public APP_URL');

    config(['product.allow_local_on_public_host' => true]);
    BootGuards::check(app());

    app()['env'] = 'production';
    config(['product.allow_local_on_public_host' => false]);
    BootGuards::check(app()); // production with a public URL is the normal case
    expect(true)->toBeTrue();
});
