<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers for every response, so deployments without the bundled nginx (Laravel Cloud, Forge,
 * Apache, `php artisan serve` behind another proxy) are covered. The bundled nginx also sets the static-file
 * equivalents and hides PHP's copies, so a header is never sent twice. A header already present is left alone.
 *
 * CSP: scripts and XHR only from this origin, Razorpay checkout and (when PLAUSIBLE_SRC is set) the Plausible origin.
 * It is skipped when APP_ENV=local because the Vite dev server runs on another origin. HSTS only on HTTPS in production.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self "https://checkout.razorpay.com")',
        ];
        if (! app()->environment('local')) {
            $defaults['Content-Security-Policy'] = $this->csp();
        }
        if ($request->isSecure() && app()->isProduction()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }

    private function csp(): string
    {
        $plausible = null;
        if (config('product.plausible.domain') && ($src = config('product.plausible.src'))) {
            $parts = parse_url((string) $src);
            $plausible = isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
        }
        $analytics = $plausible ? " {$plausible}" : '';

        return "default-src 'self'; script-src 'self'{$analytics} https://checkout.razorpay.com; connect-src 'self'{$analytics} https://api.razorpay.com https://lumberjack.razorpay.com; "
            ."frame-src https://api.razorpay.com https://checkout.razorpay.com; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    }
}
