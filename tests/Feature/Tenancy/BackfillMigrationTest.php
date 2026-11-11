<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('moves existing users and their data into personal workspaces when upgrading', function () {
    // Go back to the pre-tenancy schema, create legacy rows, then migrate forward again.
    // Every migration from the tenancy ones onwards (later migrations build on them, so they go back too).
    $newer = count(array_filter(glob(database_path('migrations/*.php')), fn ($f) => basename($f) >= '2026_11_11_000000'));
    Artisan::call('migrate:rollback', ['--step' => $newer]);

    $userId = DB::table('users')->insertGetId(['name' => 'Legacy Lata', 'email' => 'lata@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notes')->insert(['user_id' => $userId, 'title' => 'Old note', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('support_tickets')->insert(['user_id' => $userId, 'source' => 'form', 'from_email' => 'lata@example.com', 'subject' => 's', 'body_text' => 'b', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('migrate');

    $user = DB::table('users')->find($userId);
    $tenant = DB::table('tenants')->find($user->current_tenant_id);
    expect($tenant->name)->toBe("Legacy Lata's workspace")
        ->and(DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $userId, 'role' => 'owner'])->exists())->toBeTrue()
        ->and(DB::table('notes')->where('user_id', $userId)->value('tenant_id'))->toBe($tenant->id)
        ->and(DB::table('support_tickets')->where('user_id', $userId)->value('tenant_id'))->toBe($tenant->id);
});
