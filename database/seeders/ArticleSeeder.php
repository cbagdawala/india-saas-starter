<?php

namespace Database\Seeders;

use App\Modules\Content\Services\ArticleServiceInterface;
use Illuminate\Database\Seeder;

/** Sample blog post so /blog is not empty. Add more with ArticleService::upsert(). */
class ArticleSeeder extends Seeder
{
    public function run(ArticleServiceInterface $articles): void
    {
        $articles->upsert('welcome', [
            'title' => 'Welcome to your new SaaS',
            'excerpt' => 'A sample article rendered from Markdown.',
            'body_markdown' => "## Hello\n\nThis post is seeded from `database/seeders/ArticleSeeder.php`.\n\n- Edit it\n- Or add your own\n",
            'status' => 'published',
            'meta_title' => 'Welcome',
            'meta_description' => 'A sample article.',
        ]);
    }
}
