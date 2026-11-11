{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>{{ $base }}/</loc>@if ($landingModified)<lastmod>{{ \Illuminate\Support\Carbon::parse($landingModified)->toAtomString() }}</lastmod>@endif<changefreq>weekly</changefreq><priority>1.0</priority></url>
    <url><loc>{{ $base }}/blog</loc>@if ($blogModified)<lastmod>{{ $blogModified->toAtomString() }}</lastmod>@endif<changefreq>daily</changefreq><priority>0.8</priority></url>
@foreach ($articles as $article)
    <url><loc>{{ $base }}/blog/{{ $article->slug }}</loc><lastmod>{{ $article->updated_at->toAtomString() }}</lastmod><priority>0.6</priority></url>
@endforeach
</urlset>
