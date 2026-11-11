<?php

namespace Database\Factories;

use App\Modules\Landing\Models\LandingVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LandingVariant> */
class LandingVariantFactory extends Factory
{
    protected $model = LandingVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'hero_headline' => fake()->sentence(6),
            'hero_sub' => fake()->sentence(14),
            'cta_label' => 'Try it free',
            'weight' => 1,
            'active' => true,
        ];
    }
}
