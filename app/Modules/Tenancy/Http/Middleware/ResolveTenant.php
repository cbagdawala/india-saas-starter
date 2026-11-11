<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tenant`. Runs after authentication and before route-model binding (see bootstrap/app.php priority list).
 * The current workspace is the server-side users.current_tenant_id; it is re-checked against the membership table on
 * every request, so a removed member loses access immediately. Not a member (or none selected) = 403.
 * The context is always cleared afterwards so nothing leaks into the next request (Octane, tests).
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user && $user->current_tenant_id ? $user->tenants()->whereKey($user->current_tenant_id)->first() : null;

        if (! $tenant) {
            abort(403, 'No active workspace. Switch to a workspace you belong to.');
        }

        $this->context->set($tenant, TenantRole::from($tenant->pivot->role));

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
