@extends('layouts.public', [
    'metaTitle' => 'Blog · '.config('app.name'),
    'metaDescription' => 'Articles and guides from '.config('app.name').'.',
    'canonical' => rtrim(config('app.url'), '/').'/blog'.($articles->currentPage() > 1 ? '?page='.$articles->currentPage() : ''),
])

@section('content')
    <section class="mx-auto max-w-3xl px-6 py-16">
        <h1 class="text-4xl font-bold">Blog</h1>
        @forelse ($articles as $article)
            <article class="mt-10">
                <h2 class="text-2xl font-semibold"><a href="/blog/{{ $article->slug }}" class="hover:text-indigo-600">{{ $article->title }}</a></h2>
                <p class="mt-1 text-sm text-slate-500"><time datetime="{{ $article->published_at->toDateString() }}">{{ $article->published_at->format('j M Y') }}</time></p>
                @if ($article->excerpt)
                    <p class="mt-3 text-slate-600">{{ $article->excerpt }}</p>
                @endif
            </article>
        @empty
            <p class="mt-10 text-slate-600">No articles yet.</p>
        @endforelse

        @if ($articles->hasPages())
            <nav class="mt-12 flex justify-between text-sm">
                @if ($articles->previousPageUrl())<a href="{{ $articles->previousPageUrl() }}" rel="prev">&larr; Newer</a>@else<span></span>@endif
                @if ($articles->nextPageUrl())<a href="{{ $articles->nextPageUrl() }}" rel="next">Older &rarr;</a>@endif
            </nav>
        @endif
    </section>
@endsection
