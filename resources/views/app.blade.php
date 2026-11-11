<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite('resources/js/app.ts')
    @if (config('product.plausible.domain') && config('product.plausible.src'))
    <script defer data-domain="{{ config('product.plausible.domain') }}" src="{{ config('product.plausible.src') }}"></script>
    @endif
</head>
<body>
    <div id="app"></div>
</body>
</html>
