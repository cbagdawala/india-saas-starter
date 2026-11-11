<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ArticleServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Article, 1: bool} [article, created]
     */
    public function upsert(string $slug, array $data): array;

    /** @return LengthAwarePaginator<int, Article> every article, no body */
    public function paginateAll(int $perPage = 20, ?string $status = null): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Article> public articles, newest first */
    public function paginateVisible(int $perPage = 12): LengthAwarePaginator;

    public function findVisible(string $slug): ?Article;

    /** Rendered sitemap.xml, cached for an hour and flushed on every upsert. */
    public function sitemapXml(): string;
}
