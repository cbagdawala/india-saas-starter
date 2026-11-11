<?php

namespace App\Modules\Landing\Models;

use Database\Factories\LandingVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A/B slot for the landing page. Copy is treated as untrusted: Blade-escaped everywhere. */
#[Fillable(['slug', 'hero_headline', 'hero_sub', 'cta_label', 'pricing_blurb', 'features', 'faq', 'meta_title', 'meta_description', 'weight', 'active'])]
#[UseFactory(LandingVariantFactory::class)]
class LandingVariant extends Model
{
    /** @use HasFactory<LandingVariantFactory> */
    use HasFactory;

    /** Content fields a variant can override; null falls back to config('landing.default'). */
    public const CONTENT_FIELDS = ['hero_headline', 'hero_sub', 'cta_label', 'pricing_blurb', 'features', 'faq', 'meta_title', 'meta_description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'faq' => 'array',
            'weight' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @param Builder<LandingVariant> $query */
    public function scopeServing(Builder $query): void
    {
        $query->where('active', true)->where('weight', '>', 0);
    }
}
