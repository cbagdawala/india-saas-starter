<?php

use App\Models\User;
use App\Modules\Landing\Models\LandingVariant;
use App\Modules\Landing\Services\LandingService;

beforeEach(fn () => $this->withoutVite());

it('serves the default landing page with pricing from config to guests and sets the bucketing cookies', function () {
    $response = $this->get('/')->assertOk()
        ->assertSee(config('landing.default.hero_headline'))
        ->assertSee('Starter')->assertSee('₹499')
        ->assertSee('Pro')->assertSee('₹1,499')
        ->assertSee(config('landing.default.faq.0.q'))
        ->assertSee('<meta name="landing-variant" content="default">', false)
        ->assertSee('data-cta="hero"', false)
        ->assertCookie('bv_variant', 'default', false);

    expect($response->getCookie('bv_id', false)->getValue())->toMatch('/^[a-f0-9]{32}$/')
        ->and($response->getCookie('bv_id', false)->isHttpOnly())->toBeTrue()
        ->and($response->getCookie('bv_variant', false)->isHttpOnly())->toBeFalse();
});

it('gives signed-in users the SPA instead of the landing page', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('id="app"', false)
        ->assertDontSee(config('landing.default.hero_headline'));
});

it('renders the variant copy and SEO meta, escaping untrusted text', function () {
    LandingVariant::factory()->create([
        'slug' => 'bold', 'hero_headline' => 'Ship <script>alert(1)</script> faster',
        'meta_title' => 'Bold title', 'meta_description' => 'Bold description', 'cta_label' => 'Go bold',
    ]);

    $this->get('/')->assertOk()
        ->assertSee('<title>Bold title</title>', false)
        ->assertSee('<meta name="description" content="Bold description">', false)
        ->assertSee('<meta property="og:title" content="Bold title">', false)
        ->assertSee('Go bold')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false)
        ->assertCookie('bv_variant', 'bold', false);
});

it('buckets a visitor deterministically and keeps the bucket across visits', function () {
    LandingVariant::factory()->create(['slug' => 'a', 'weight' => 1]);
    LandingVariant::factory()->create(['slug' => 'b', 'weight' => 1]);
    $visitor = str_repeat('ab', 16);

    $first = $this->withUnencryptedCookie('bv_id', $visitor)->get('/')->getCookie('bv_variant', false)->getValue();
    foreach (range(1, 5) as $_) {
        $this->withUnencryptedCookie('bv_id', $visitor)->get('/')
            ->assertCookie('bv_variant', $first, false)
            ->assertCookie('bv_id', $visitor, false);
    }

    $service = app(LandingService::class);
    expect($service->pick($visitor)?->slug)->toBe($first)
        ->and(LandingService::bucket($visitor, 7))->toBe(LandingService::bucket($visitor, 7));
});

it('splits traffic by weight and never serves inactive or zero-weight variants', function () {
    LandingVariant::factory()->create(['slug' => 'heavy', 'weight' => 3]);
    LandingVariant::factory()->create(['slug' => 'light', 'weight' => 1]);
    LandingVariant::factory()->create(['slug' => 'off', 'weight' => 5, 'active' => false]);
    LandingVariant::factory()->create(['slug' => 'zero', 'weight' => 0]);

    $service = app(LandingService::class);
    $variants = $service->servingVariants();
    $counts = ['heavy' => 0, 'light' => 0];
    for ($i = 0; $i < 2000; $i++) {
        $counts[$service->pick(md5((string) $i), $variants)->slug]++;
    }

    expect(array_keys($counts))->toBe(['heavy', 'light'])
        ->and($counts['heavy'] / 2000)->toBeGreaterThan(0.70)->toBeLessThan(0.80);
});

it('replaces a malformed visitor cookie', function () {
    $response = $this->withUnencryptedCookie('bv_id', '../../etc')->get('/')->assertOk();

    expect($response->getCookie('bv_id', false)->getValue())->toMatch('/^[a-f0-9]{32}$/');
});

it('attributes a signup to a known variant cookie only', function () {
    LandingVariant::factory()->create(['slug' => 'bold']);
    $payload = ['name' => 'Asha', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123'];

    spa()->withCredentials()->withUnencryptedCookie('bv_variant', 'bold')->postJson('/api/auth/register', $payload + ['email' => 'a@example.com'])->assertCreated();
    expect(User::where('email', 'a@example.com')->value('landing_variant'))->toBe('bold');

    auth()->guard('web')->logout();
    spa()->withCredentials()->withUnencryptedCookie('bv_variant', 'made-up')->postJson('/api/auth/register', $payload + ['email' => 'b@example.com'])->assertCreated();
    expect(User::where('email', 'b@example.com')->value('landing_variant'))->toBeNull();
});
