<?php

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Every Eloquent model class under app/. */
function allModels(): array
{
    $files = array_merge(glob(app_path('Models/*.php')) ?: [], glob(app_path('Modules/*/Models/*.php')) ?: []);
    $classes = [];
    foreach ($files as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path()) + 1));
        if (is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Models that deliberately are not tenant-scoped even though they could look tenant-related. Keep this list tiny and
 * explain each entry: adding a model here is a decision for the security review.
 */
const TENANCY_EXEMPT = [
    Tenant::class => 'the tenant root itself; reached through a user\'s memberships',
];

it('requires BelongsToTenant on every model that has a tenant_id or user_id column', function () {
    $missing = [];
    foreach (allModels() as $class) {
        if (array_key_exists($class, TENANCY_EXEMPT) || $class === User::class) {
            continue;
        }
        $columns = Schema::getColumnListing((new $class)->getTable());
        $ownsData = in_array('tenant_id', $columns, true) || in_array('user_id', $columns, true);
        if ($ownsData && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'Add the BelongsToTenant trait (and a tenant_id column) to: '.implode(', ', $missing));
});

it('only uses BelongsToTenant on models whose table has a tenant_id column', function () {
    foreach (allModels() as $class) {
        if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            expect(Schema::hasColumn((new $class)->getTable(), 'tenant_id'))->toBeTrue("{$class} uses BelongsToTenant but has no tenant_id column");
        }
    }
});

it('has a tenant_id column on every table that stores per-user data (tenant-owned tables are all declared)', function () {
    $tenantOwned = collect(allModels())->filter(fn ($c) => in_array(BelongsToTenant::class, class_uses_recursive($c), true))->map(fn ($c) => (new $c)->getTable())->all();

    expect($tenantOwned)->toContain('notes', 'subscriptions', 'support_tickets', 'tenant_invitations', 'invoices');
});

/** Authenticated routes that are intentionally about the user, not a workspace. Everything else needs the `tenant` middleware. */
const USER_LEVEL_ROUTES = [
    'auth.logout', 'auth.me', 'auth.verification.send', 'account.export', 'account.destroy',
    'onboarding.show', 'onboarding.complete',
    'tenants.index', 'tenants.store', 'tenants.switch', 'invitations.accept',
];

it('puts the tenant middleware on every authenticated API route unless it is declared user-level', function () {
    $offenders = [];
    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $authenticated = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'));
        if (! $authenticated || ! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        $hasTenant = in_array('tenant', $middleware, true);
        $declaredUserLevel = in_array($route->getName(), USER_LEVEL_ROUTES, true);
        if (! $hasTenant && ! $declaredUserLevel) {
            $offenders[] = $route->methods()[0].' '.$route->uri().' ('.($route->getName() ?? 'unnamed').')';
        }
        if ($hasTenant && $declaredUserLevel) {
            $offenders[] = 'declared user-level but has the tenant middleware: '.$route->getName();
        }
    }

    expect($offenders)->toBe([], "Add the 'tenant' middleware, or list the route in USER_LEVEL_ROUTES:\n".implode("\n", $offenders));
});

it('runs the tenant middleware before route-model binding', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    expect(array_search(ResolveTenant::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
});
