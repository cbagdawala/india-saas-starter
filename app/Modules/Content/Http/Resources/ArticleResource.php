<?php

namespace App\Modules\Content\Http\Resources;

use App\Modules\Content\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Article */
class ArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'author' => $this->author,
            'url' => rtrim((string) config('app.url'), '/').'/blog/'.$this->slug,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
