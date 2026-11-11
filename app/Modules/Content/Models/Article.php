<?php

namespace App\Modules\Content\Models;

use App\Modules\Content\Enums\ArticleStatus;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** SEO article. Text is treated as untrusted: escaped or rendered with MarkdownRenderer. */
#[Fillable(['slug', 'title', 'excerpt', 'body_markdown', 'status', 'published_at', 'meta_title', 'meta_description', 'author'])]
#[UseFactory(ArticleFactory::class)]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    public const AUTHOR = 'editorial';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** Publicly visible: published and published_at reached (future dates are scheduled posts). */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
