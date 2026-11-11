<?php

namespace App\Modules\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Services\ArticleServiceInterface;
use App\Modules\Content\Services\MarkdownRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Public, server-rendered blog (SEO). */
class BlogController extends Controller
{
    public function __construct(private readonly ArticleServiceInterface $articles) {}

    public function index(Request $request): View
    {
        return view('blog.index', ['articles' => $this->articles->paginateVisible()]);
    }

    public function show(string $slug, MarkdownRenderer $markdown): View
    {
        $article = $this->articles->findVisible($slug) ?? abort(404);

        return view('blog.show', [
            'article' => $article,
            'html' => $markdown->toHtml($article->body_markdown),
        ]);
    }
}
