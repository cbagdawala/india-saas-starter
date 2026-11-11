<?php

use App\Http\Middleware\AuthenticateWebSession;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // A password change/reset ends every other browser session of that user (the session stores the password hash).
        $middleware->api(append: [AuthenticateWebSession::class]);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'body.limit' => LimitRequestBody::class,    // body.limit:<bytes>
            'tenant' => ResolveTenant::class,           // resolves + authorises the current workspace
        ]);

        // The tenant must be known before route-model binding runs, so cross-tenant ids 404 through the global scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);

        // Host header allow-list (production): APP_URL host + TRUSTED_HOSTS, exact matches only.
        $middleware->trustHosts(at: fn () => array_map(
            fn (string $host) => '^'.preg_quote($host).'$',
            array_values(array_unique(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), ...(array) config('product.trusted_hosts')]))),
        ), subdomains: false);

        // Landing A/B cookies hold a random id and a slug (validated on read); JS reads the slug for Plausible props.
        $middleware->encryptCookies(except: ['bv_id', 'bv_variant', 'known_device']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports unhandled exceptions to GlitchTip (no-op while SENTRY_LARAVEL_DSN is empty).
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::unauthorized();
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error($e->getMessage(), $e->status, $e->errors());
            }
        });

        // 403 / 404 / 405 / 429 ... in the ADR-004 envelope for API routes.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    404 => 'Not Found',
                    403 => $e->getMessage() ?: 'Forbidden',
                    default => $e->getMessage() ?: 'Error',
                };

                return ApiResponse::error($message, $status)->withHeaders($e->getHeaders());
            }
        });
    })->create();
