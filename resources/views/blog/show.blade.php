{{-- $html is MarkdownRenderer output (raw HTML stripped, unsafe links dropped): the only unescaped output allowed. --}}
@extends('layouts.public', [
    'metaTitle' => $article->meta_title ?: $article->title.' · '.config('app.name'),
    'metaDescription' => $article->meta_description ?: (string) $article->excerpt,
    'canonical' => rtrim(config('app.url'), '/').'/blog/'.$article->slug,
    'ogType' => 'article',
])

@push('head')
    <meta property="article:published_time" content="{{ $article->published_at->toIso8601String() }}">
    <meta property="article:modified_time" content="{{ $article->updated_at->toIso8601String() }}">
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-6 py-16">
        <p class="text-sm text-slate-500"><a href="/blog">&larr; Blog</a> · <time datetime="{{ $article->published_at->toDateString() }}">{{ $article->published_at->format('j M Y') }}</time></p>
        <h1 class="mt-4 text-4xl font-bold">{{ $article->title }}</h1>
        <div class="prose-article mt-10">{!! $html !!}</div>
        <div class="mt-16 rounded-xl bg-slate-50 p-8 text-center">
            <p class="text-lg font-semibold">Try {{ config('app.name') }}</p>
            <a href="/register" data-cta="article" class="mt-4 inline-block rounded-lg bg-indigo-600 px-6 py-3 font-medium text-white hover:bg-indigo-700">Get started</a>
        </div>
    </article>
@endsection
