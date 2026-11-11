<?php

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Services\ArticleServiceInterface;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function sitemap(ArticleServiceInterface $articles): Response
    {
        return response($articles->sitemapXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** Production: allow everything public, hide the app and API. Any other environment: disallow all (no staging in search). */
    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        $lines = app()->isProduction()
            ? ['User-agent: *', 'Disallow: /api/', 'Disallow: /login', 'Disallow: /welcome', 'Disallow: /billing', 'Disallow: /support', '', "Sitemap: {$base}/sitemap.xml"]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
