<?php

namespace App\Modules\Landing\Services;

use App\Modules\Content\Services\ArticleService;
use App\Modules\Landing\Models\LandingVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LandingService implements LandingServiceInterface
{
    private const VISITOR_PATTERN = '/^[a-f0-9]{32}$/';

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @return Collection<int, LandingVariant> active variants with weight > 0, in a stable (slug) order */
    public function servingVariants(): Collection
    {
        return LandingVariant::query()->serving()->orderBy('slug')->get();
    }

    public function isValidVisitorId(?string $id): bool
    {
        return is_string($id) && preg_match(self::VISITOR_PATTERN, $id) === 1;
    }

    public function newVisitorId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Deterministic weighted bucketing: the same visitor id always lands in the same variant while the set of
     * serving variants and their weights are unchanged. Null = no serving variant (use the config default).
     *
     * @param  Collection<int, LandingVariant>|null  $variants
     */
    public function pick(string $visitorId, ?Collection $variants = null): ?LandingVariant
    {
        $variants ??= $this->servingVariants();
        $total = (int) $variants->sum('weight');
        if ($total <= 0) {
            return null;
        }

        $point = self::bucket($visitorId, $total);
        foreach ($variants as $variant) {
            $point -= $variant->weight;
            if ($point < 0) {
                return $variant;
            }
        }

        return $variants->last();
    }

    /** Stable position in [0, $total) from the first 32 bits of sha256(visitor id). */
    public static function bucket(string $visitorId, int $total): int
    {
        return (int) (hexdec(substr(hash('sha256', 'landing:'.$visitorId), 0, 8)) % max(1, $total));
    }

    /**
     * Page content for a variant: each null field falls back to config('landing.default').
     *
     * @return array<string, mixed>
     */
    public function content(?LandingVariant $variant): array
    {
        $content = (array) config('landing.default');
        if ($variant) {
            $content['slug'] = $variant->slug;
            foreach (LandingVariant::CONTENT_FIELDS as $field) {
                $value = $variant->{$field};
                if ($value !== null && $value !== '' && $value !== []) {
                    $content[$field] = $value;
                }
            }
        }

        $content['meta_title'] ??= config('app.name').' · '.$content['hero_headline'];
        $content['meta_description'] ??= $content['hero_sub'];

        return $content;
    }

    /** A slug that may be stored as signup attribution: the default slug or an existing variant. */
    public function isKnownSlug(?string $slug): bool
    {
        if (! is_string($slug) || preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 64) {
            return false;
        }

        return $slug === config('landing.default.slug') || LandingVariant::query()->where('slug', $slug)->exists();
    }

    /**
     * Create or replace a variant (used by the seeders).
     *
     * @param  array<string, mixed>  $data  variant attributes
     * @return array{0: LandingVariant, 1: bool} [variant, created]
     */
    public function upsert(string $slug, array $data): array
    {
        return DB::transaction(function () use ($slug, $data) {
            $variant = LandingVariant::query()->where('slug', $slug)->lockForUpdate()->first() ?? new LandingVariant(['slug' => $slug]);
            $created = ! $variant->exists;

            $variant->fill([
                'hero_headline' => $data['hero_headline'],
                'hero_sub' => $data['hero_sub'] ?? null,
                'cta_label' => $data['cta_label'] ?? null,
                'pricing_blurb' => $data['pricing_blurb'] ?? null,
                'features' => $data['features'] ?? null,
                'faq' => $data['faq'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'weight' => $data['weight'] ?? 1,
                'active' => $data['active'] ?? false,
            ])->save();

            Cache::forget(ArticleService::SITEMAP_CACHE_KEY); // landing lastmod

            return [$variant, $created];
        });
    }

    /** @return Collection<int, LandingVariant> */
    public function all(): Collection
    {
        return LandingVariant::query()->orderBy('slug')->get();
    }
}
