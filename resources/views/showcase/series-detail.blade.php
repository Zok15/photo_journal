<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $series->title }} | PhotoLog</title>
    <meta name="description" content="{{ trim((string) ($series->description ?? 'Public photo series in PhotoLog.')) }}" />
    <meta name="robots" content="index,follow" />
    <link rel="canonical" href="{{ $siteOrigin }}/series/{{ $slug }}" />
    <style>
        body { margin: 0; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1c1f1c; background: #f5f6f2; }
        main { max-width: 860px; margin: 0 auto; padding: 32px 20px 48px; }
        a.back { color: #2e4f3a; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin: 12px 0 8px; font-size: 34px; line-height: 1.2; }
        p.desc { margin: 0 0 18px; color: #2f372e; white-space: pre-wrap; }
        .meta { color: #5a6157; font-size: 14px; }
        .tags { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 6px; }
        .tag { font-size: 12px; background: #eef3ea; color: #2e4f3a; border-radius: 999px; padding: 3px 10px; }
    </style>
</head>
<body>
<main>
    <a class="back" href="/public/series">&larr; Back to gallery</a>
    <h1>{{ $series->title }}</h1>
    @if(trim((string) ($series->description ?? '')) !== '')
        <p class="desc">{{ $series->description }}</p>
    @endif

    <div class="meta">
        Photos: {{ (int) ($series->photos_count ?? 0) }}
        @if(trim((string) ($series->user->name ?? '')) !== '')
            | Author: {{ $series->user->name }}
        @endif
        | Updated: {{ optional($series->updated_at)->toIso8601String() }}
        | Generated: {{ $generatedAt }}
    </div>

    @if($series->tags->isNotEmpty())
        <div class="tags">
            @foreach($series->tags as $tag)
                <span class="tag">{{ $tag->name }}</span>
            @endforeach
        </div>
    @endif
</main>
</body>
</html>

