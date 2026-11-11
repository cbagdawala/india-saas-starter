<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Enums\ArticleStatus;
use App\Modules\Content\Models\Article;
use App\Modules\Landing\Models\LandingVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ArticleService implements ArticleServiceInterface
{
    /** Flushed by every article or landing-variant upsert. */
    public const SITEMAP_CACHE_KEY = 'seo:sitemap.xml';

    /**
     * Create or replace the article with this slug (used by the seeders). Published without a date = published now;
     * an existing publish date is kept on re-publish so the URL's lastmod history stays stable.
     *
     * @param  array<string, mixed>  $data  article attributes
     * @return array{0: Article, 1: bool} [article, created]
     */
    public function upsert(string $slug, array $data): array
    {
        return DB::transaction(function () use ($slug, $data) {
            $article = Article::query()->where('slug', $slug)->lockForUpdate()->first() ?? new Article(['slug' => $slug]);
            $created = ! $article->exists;

            $status = ArticleStatus::from($data['status']);
            $publishedAt = isset($data['published_at']) ? Carbon::parse($data['published_at']) : null;
            if ($status === ArticleStatus::Published && ! $publishedAt) {
                $publishedAt = $article->published_at ?? now();
            }

            $article->fill([
                'title' => $data['title'],
                'excerpt' => $data['excerpt'] ?? null,
                'body_markdown' => $data['body_markdown'],
                'status' => $status,
                'published_at' => $publishedAt,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'author' => Article::AUTHOR,
            ])->save();

            Cache::forget(self::SITEMAP_CACHE_KEY);

            return [$article, $created];
        });
    }

    /** @return LengthAwarePaginator<int, Article> */
    public function paginateAll(int $perPage = 20, ?string $status = null): LengthAwarePaginator
    {
        return Article::query()
            ->select(['id', 'slug', 'title', 'excerpt', 'status', 'published_at', 'meta_title', 'meta_description', 'author', 'created_at', 'updated_at'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, Article> */
    public function paginateVisible(int $perPage = 12): LengthAwarePaginator
    {
        return Article::query()
            ->visible()
            ->select(['id', 'slug', 'title', 'excerpt', 'published_at'])
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    public function findVisible(string $slug): ?Article
    {
        return Article::query()->visible()->where('slug', $slug)->first();
    }

    public function sitemapXml(): string
    {
        return Cache::remember(self::SITEMAP_CACHE_KEY, 3600, function () {
            $articles = Article::query()->visible()->orderByDesc('published_at')->limit(5000)->get(['slug', 'updated_at', 'published_at']);

            return view('seo.sitemap', [
                'base' => rtrim((string) config('app.url'), '/'),
                'articles' => $articles,
                'landingModified' => LandingVariant::query()->max('updated_at'),
                'blogModified' => $articles->max('updated_at'),
            ])->render();
        });
    }
}
