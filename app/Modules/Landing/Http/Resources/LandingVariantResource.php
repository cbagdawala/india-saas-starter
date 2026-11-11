<?php

namespace App\Modules\Landing\Http\Resources;

use App\Modules\Landing\Models\LandingVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LandingVariant */
class LandingVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'hero_headline' => $this->hero_headline,
            'hero_sub' => $this->hero_sub,
            'cta_label' => $this->cta_label,
            'pricing_blurb' => $this->pricing_blurb,
            'features' => $this->features,
            'faq' => $this->faq,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'weight' => $this->weight,
            'active' => $this->active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
