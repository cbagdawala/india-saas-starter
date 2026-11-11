{{-- Landing page. $content comes from a landing_variants row merged over config/landing.php. Untrusted: escaped. --}}
@extends('layouts.public', [
    'metaTitle' => $content['meta_title'],
    'metaDescription' => $content['meta_description'],
    'canonical' => rtrim(config('app.url'), '/').'/',
    'navCta' => $content['cta_label'],
])

@section('content')
    <section class="mx-auto max-w-4xl px-6 py-20 text-center">
        <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">{{ $content['hero_headline'] }}</h1>
        @if (! empty($content['hero_sub']))
            <p class="mx-auto mt-6 max-w-2xl text-lg text-slate-600">{{ $content['hero_sub'] }}</p>
        @endif
        <div class="mt-10 flex justify-center gap-4">
            <a href="/register" data-cta="hero" class="rounded-lg bg-indigo-600 px-6 py-3 font-medium text-white hover:bg-indigo-700">{{ $content['cta_label'] }}</a>
            @if (! empty($plans))<a href="#pricing" class="rounded-lg border border-slate-300 px-6 py-3 font-medium hover:bg-slate-50">See pricing</a>@endif
        </div>
    </section>

    @if (! empty($content['features']))
        <section class="bg-slate-50">
            <div class="mx-auto grid max-w-6xl gap-8 px-6 py-16 sm:grid-cols-3">
                @foreach ($content['features'] as $feature)
                    <div>
                        <h2 class="text-lg font-semibold">{{ $feature['title'] ?? '' }}</h2>
                        <p class="mt-2 text-slate-600">{{ $feature['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (! empty($plans))
    <section id="pricing" class="mx-auto max-w-5xl px-6 py-16">
        <h2 class="text-center text-3xl font-bold">Pricing</h2>
        @if (! empty($content['pricing_blurb']))
            <p class="mt-3 text-center text-slate-600">{{ $content['pricing_blurb'] }}</p>
        @endif
        <div class="mt-10 grid gap-6 sm:grid-cols-2">
            @foreach ($plans as $plan)
                <div class="flex flex-col rounded-xl border border-slate-200 p-8">
                    <h3 class="text-xl font-semibold">{{ $plan['name'] }}</h3>
                    <p class="mt-4 text-4xl font-bold">
                        {{ ['INR' => '₹', 'USD' => '$', 'EUR' => '€'][$plan['currency']] ?? $plan['currency'].' ' }}{{ number_format($plan['amount'] / 100, $plan['amount'] % 100 ? 2 : 0) }}<span class="text-base font-normal text-slate-500">/{{ $plan['interval'] }}</span>
                    </p>
                    <ul class="mt-6 flex-1 space-y-2 text-slate-600">
                        @foreach ($plan['features'] ?? [] as $feature)
                            <li>&#10003; {{ $feature }}</li>
                        @endforeach
                    </ul>
                    <a href="/register" data-cta="pricing-{{ $plan['key'] }}" class="mt-8 rounded-lg bg-indigo-600 px-4 py-2 text-center font-medium text-white hover:bg-indigo-700">{{ $content['cta_label'] }}</a>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if (! empty($content['faq']))
        <section class="bg-slate-50">
            <div class="mx-auto max-w-3xl px-6 py-16">
                <h2 class="text-center text-3xl font-bold">Frequently asked questions</h2>
                <dl class="mt-10 space-y-6">
                    @foreach ($content['faq'] as $item)
                        <div>
                            <dt class="font-semibold">{{ $item['q'] ?? '' }}</dt>
                            <dd class="mt-2 text-slate-600">{{ $item['a'] ?? '' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>
    @endif

    <section class="mx-auto max-w-4xl px-6 py-20 text-center">
        <h2 class="text-3xl font-bold">Ready to start?</h2>
        <a href="/register" data-cta="footer" class="mt-8 inline-block rounded-lg bg-indigo-600 px-6 py-3 font-medium text-white hover:bg-indigo-700">{{ $content['cta_label'] }}</a>
    </section>
@endsection
