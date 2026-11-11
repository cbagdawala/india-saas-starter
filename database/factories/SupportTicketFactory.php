<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_tenant_id'),
            'source' => SupportTicket::SOURCE_FORM,
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'subject' => fake()->sentence(4),
            'body_text' => fake()->paragraph(),
            'status' => TicketStatus::Open,
            'received_at' => now(),
        ];
    }
}
