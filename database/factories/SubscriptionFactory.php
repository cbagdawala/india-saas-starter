<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_tenant_id'),
            'plan' => 'starter',
            'status' => SubscriptionStatus::Created,
            'gateway' => 'fake',
            'gateway_subscription_id' => 'sub_fake_'.Str::lower(Str::random(14)),
            'amount' => 49900,
            'currency' => 'INR',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Active, 'current_period_end' => now()->addMonth()]);
    }
}
