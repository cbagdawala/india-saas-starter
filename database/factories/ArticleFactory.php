<?php

namespace Database\Factories;

use App\Modules\Content\Enums\ArticleStatus;
use App\Modules\Content\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(5);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'excerpt' => fake()->sentence(12),
            'body_markdown' => "## Intro\n\n".fake()->paragraph()."\n\n- one\n- two\n",
            'status' => ArticleStatus::Published,
            'published_at' => now()->subDay(),
            'author' => Article::AUTHOR,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ArticleStatus::Draft, 'published_at' => null]);
    }
}
