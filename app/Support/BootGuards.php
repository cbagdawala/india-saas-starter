<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use LogicException;

/** Refuses to boot with settings that are unsafe outside development. Keep checks cheap: this runs on every request. */
final class BootGuards
{
    public static function check(Application $app): void
    {
        $config = $app['config'];

        if ($app->environment('local') && ! $config->get('product.allow_local_on_public_host') && ! self::isLocalHost((string) parse_url((string) $config->get('app.url'), PHP_URL_HOST))) {
            throw new LogicException('APP_ENV=local with a public APP_URL ('.$config->get('app.url').'): host trust and error detail are off in local. Set APP_ENV=production, or ALLOW_LOCAL_ON_PUBLIC_HOST=true if this is deliberate.');
        }

        if ($app->environment('production') && $app['config']->get('app.debug')) {
            throw new LogicException('APP_DEBUG=true is not allowed in production: it exposes stack traces, environment values and queries. Set APP_DEBUG=false.');
        }
    }

    /** Loopback, localhost and the reserved development suffixes. An empty host (APP_URL unset) is treated as local. */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)
            || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }
}
