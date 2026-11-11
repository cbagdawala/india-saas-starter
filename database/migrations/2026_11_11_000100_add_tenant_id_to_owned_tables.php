<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tenant-owned tables get tenant_id. Existing installs: every user without a workspace gets a personal one
 * (the user as owner), and their existing notes / subscriptions / support tickets move into it.
 */
return new class extends Migration
{
    private const TABLES = ['notes', 'subscriptions', 'support_tickets'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // No explicit index: MySQL indexes the foreign key itself, and an extra one blocks the rollback there.
                $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        $now = now();
        DB::table('users')->whereNull('current_tenant_id')->orderBy('id')->each(function ($user) use ($now) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => Str::limit($user->name."'s workspace", 120, ''),
                'slug' => Str::slug(Str::limit($user->name, 60, '')).'-'.Str::lower(Str::random(8)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('tenant_user')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('users')->where('id', $user->id)->update(['current_tenant_id' => $tenantId]);
        });

        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('tenant_id')->whereNotNull('user_id')->update([
                'tenant_id' => DB::raw('(select current_tenant_id from users where users.id = '.$name.'.user_id)'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
    }
};
