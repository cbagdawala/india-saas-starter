<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Notes\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Note> */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_tenant_id'),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }
}
