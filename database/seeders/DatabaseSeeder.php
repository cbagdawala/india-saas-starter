<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Database\Seeder;

/** Local/demo data: `php artisan db:seed`. Idempotent (upserts by slug / email). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('local', 'testing')) {
            $demo = User::query()->firstOrCreate(
                ['email' => 'demo@example.com'],
                ['name' => 'Demo User', 'password' => 'password', 'email_verified_at' => now()],
            );
            if (! $demo->tenants()->exists()) {
                app(TenantService::class)->createPersonal($demo);
            }
        }

        $this->call([LandingVariantSeeder::class, ArticleSeeder::class]);
    }
}
