<?php

namespace App\Modules\Landing\Services;

use App\Modules\Landing\Models\LandingVariant;
use Illuminate\Support\Collection;

interface LandingServiceInterface
{
    /** @return Collection<int, LandingVariant> active variants with weight > 0, ordered by slug */
    public function servingVariants(): Collection;

    public function isValidVisitorId(?string $id): bool;

    public function newVisitorId(): string;

    /**
     * Deterministic weighted pick; null = no serving variant (config default).
     *
     * @param  Collection<int, LandingVariant>|null  $variants
     */
    public function pick(string $visitorId, ?Collection $variants = null): ?LandingVariant;

    /** @return array<string, mixed> page content, null fields falling back to config('landing.default') */
    public function content(?LandingVariant $variant): array;

    /** Valid signup attribution slug: `default` or an existing variant. */
    public function isKnownSlug(?string $slug): bool;

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: LandingVariant, 1: bool} [variant, created]
     */
    public function upsert(string $slug, array $data): array;

    /** @return Collection<int, LandingVariant> */
    public function all(): Collection;
}
