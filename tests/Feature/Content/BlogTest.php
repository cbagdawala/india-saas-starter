<?php

use App\Modules\Content\Models\Article;
use App\Modules\Content\Services\ArticleServiceInterface;
use App\Modules\Content\Services\MarkdownRenderer;
use App\Modules\Landing\Models\LandingVariant;

beforeEach(fn () => $this->withoutVite());

it('lists and shows published articles only', function () {
    $published = Article::factory()->create(['slug' => 'hello-world', 'title' => 'Hello world']);
    Article::factory()->draft()->create(['slug' => 'secret-draft', 'title' => 'Secret draft']);
    Article::factory()->create(['slug' => 'scheduled', 'title' => 'Scheduled', 'published_at' => now()->addDay()]);

    $this->get('/blog')->assertOk()->assertSee('Hello world')->assertDontSee('Secret draft')->assertDontSee('Scheduled');

    $this->get('/blog/hello-world')->assertOk()
        ->assertSee('<h1 class="mt-4 text-4xl font-bold">Hello world</h1>', false)
        ->assertSee('<meta property="og:type" content="article">', false)
        ->assertSee('<link rel="canonical" href="http://localhost:8200/blog/hello-world">', false);
    $this->get('/blog/secret-draft')->assertNotFound();
    $this->get('/blog/scheduled')->assertNotFound();
    expect($published->author)->toBe('editorial');
});

it('uses the article SEO meta when set', function () {
    Article::factory()->create(['slug' => 'seo', 'meta_title' => 'Custom SEO title', 'meta_description' => 'Custom description']);

    $this->get('/blog/seo')->assertOk()
        ->assertSee('<title>Custom SEO title</title>', false)
        ->assertSee('<meta name="description" content="Custom description">', false);
});

it('strips raw HTML and unsafe links from markdown', function () {
    $html = app(MarkdownRenderer::class)->toHtml(<<<'MD'
# Title

<script>alert('x')</script>

<div onclick="evil()">raw block</div>

Inline <img src=x onerror=alert(1)> html and <iframe src="https://evil.test"></iframe>.

[click me](javascript:alert(1)) [vb](vbscript:msgbox) [data](data:text/html;base64,PHNjcmlwdD4=)

[ok](https://example.com/page) **bold**
MD);

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('<iframe')
        ->not->toContain('<div')
        ->not->toContain('javascript:')
        ->not->toContain('vbscript:')
        ->not->toContain('data:text/html')
        ->toContain('<h1>Title</h1>')
        ->toContain('<strong>bold</strong>')
        ->toContain('href="https://example.com/page"')
        ->toContain('rel="nofollow noopener noreferrer"')
        ->toContain('<a>click me</a>');
});

it('renders article bodies through the safe renderer', function () {
    Article::factory()->create(['slug' => 'xss', 'body_markdown' => "Hi <script>alert(1)</script>\n\n[x](javascript:alert(1))"]);

    $this->get('/blog/xss')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('javascript:alert', false);
});

it('builds a sitemap with the landing page, blog and published articles', function () {
    Article::factory()->create(['slug' => 'in-sitemap']);
    Article::factory()->draft()->create(['slug' => 'not-in-sitemap']);

    LandingVariant::factory()->create();

    $response = $this->get('/sitemap.xml')->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertHeader('Cache-Control', 'max-age=3600, public');
    $xml = simplexml_load_string($response->getContent());
    $locs = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));

    expect($locs)->toBe([
        'http://localhost:8200/',
        'http://localhost:8200/blog',
        'http://localhost:8200/blog/in-sitemap',
    ])->and($xml->xpath('//*[local-name()="lastmod"]'))->toHaveCount(3);
});

it('caches the sitemap for an hour and refreshes it when an article is upserted', function () {
    Article::factory()->create(['slug' => 'first']);
    $this->get('/sitemap.xml')->assertSee('/blog/first');

    Article::factory()->create(['slug' => 'sneaked-in']); // direct write: cache still serves the old copy
    $this->get('/sitemap.xml')->assertDontSee('/blog/sneaked-in');

    app(ArticleServiceInterface::class)->upsert('second', ['title' => 'Second', 'body_markdown' => 'x', 'status' => 'published']);
    $this->get('/sitemap.xml')->assertSee('/blog/second')->assertSee('/blog/sneaked-in');
});

it('blocks crawlers outside production and points at the sitemap in production', function () {
    $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false)->assertDontSee('Sitemap:');

    app()->detectEnvironment(fn () => 'production');
    $this->get('/robots.txt')->assertOk()
        ->assertSee('Disallow: /api/')
        ->assertSee('Sitemap: http://localhost:8200/sitemap.xml');
});
