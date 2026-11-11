{{-- Public, server-rendered pages (landing, blog). All dynamic text is Blade-escaped; no inline scripts (CSP). --}}
@php
    $metaTitle = $metaTitle ?? config('app.name');
    $metaDescription = $metaDescription ?? '';
    $canonical = $canonical ?? url()->current();
    $ogType = $ogType ?? 'website';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    @isset($variant)
    <meta name="landing-variant" content="{{ $variant }}">
    @endisset
    @stack('head')
    @vite(['resources/css/public.css', 'resources/js/public.ts'])
    @if (config('product.plausible.domain') && config('product.plausible.src'))
    <script defer data-domain="{{ config('product.plausible.domain') }}" src="{{ config('product.plausible.src') }}"></script>
    @endif
</head>
<body class="bg-white text-slate-900 antialiased">
    <header class="border-b border-slate-200">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="/" class="text-lg font-semibold">{{ config('app.name') }}</a>
            <div class="flex items-center gap-6 text-sm">
                <a href="/blog" class="text-slate-600 hover:text-slate-900">Blog</a>
                <a href="/login" class="text-slate-600 hover:text-slate-900">Sign in</a>
                <a href="/register" data-cta="nav" class="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-700">{{ $navCta ?? 'Get started' }}</a>
            </div>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="border-t border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-6 py-8 text-sm text-slate-500 sm:flex-row sm:justify-between">
            <span>&copy; {{ now()->year }} {{ config('app.name') }}</span>
            <span class="flex gap-4"><a href="/blog">Blog</a><a href="/sitemap.xml">Sitemap</a></span>
        </div>
    </footer>
</body>
</html>
