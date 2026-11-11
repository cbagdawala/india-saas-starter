<?php

use App\Models\User;
use App\Modules\Content\Models\Article;
use App\Modules\Landing\Models\LandingVariant;
use Database\Seeders\DatabaseSeeder;

it('seeds demo data idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(LandingVariant::query()->where('slug', 'gst-ready')->count())->toBe(1)
        ->and(Article::query()->where('slug', 'welcome')->count())->toBe(1)
        ->and(User::where('email', 'demo@example.com')->sole()->tenants()->count())->toBe(1);
});
