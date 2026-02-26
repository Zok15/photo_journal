<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Public Series | PhotoLog</title>
    <meta name="description" content="Public photo series in PhotoLog." />
    <meta name="robots" content="index,follow" />
    <link rel="canonical" href="{{ $siteOrigin }}/public/series" />
    <style>
        body { margin: 0; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1c1f1c; background: #f5f6f2; }
        main { max-width: 920px; margin: 0 auto; padding: 32px 20px 48px; }
        h1 { margin: 0 0 8px; font-size: 34px; line-height: 1.2; }
        p.meta { margin: 0 0 24px; color: #5a6157; }
        ul.list { margin: 0; padding: 0; list-style: none; display: grid; gap: 14px; }
        li.card { background: #fff; border: 1px solid #dde2d8; border-radius: 12px; padding: 16px; }
        a.title { color: #163924; text-decoration: none; font-weight: 700; font-size: 20px; }
        a.title:hover { text-decoration: underline; }
        p.desc { margin: 8px 0 10px; color: #2f372e; }
        .row { color: #5a6157; font-size: 14px; }
        .tags { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
        .tag { font-size: 12px; background: #eef3ea; color: #2e4f3a; border-radius: 999px; padding: 3px 10px; }
    </style>
</head>
<body>
<main>
    <h1>Public Series</h1>
    <p class="meta">Generated at {{ $generatedAt }}. Total: {{ $seriesList->count() }}.</p>

    <ul class="list">
        @foreach($seriesList as $series)
            @php
                $slug = trim((string) $series->slug) !== '' ? $series->slug : (string) $series->id;
                $url = '/series/' . $slug;
                $description = trim((string) ($series->description ?? ''));
                $author = trim((string) ($series->user->name ?? ''));
            @endphp
            <li class="card">
                <a class="title" href="{{ $url }}">{{ $series->title }}</a>
                @if($description !== '')
                    <p class="desc">{{ $description }}</p>
                @endif
                <div class="row">
                    Photos: {{ (int) ($series->photos_count ?? 0) }}
                    @if($author !== '')
                        | Author: {{ $author }}
                    @endif
                    | Updated: {{ optional($series->updated_at)->toIso8601String() }}
                </div>
                @if($series->tags->isNotEmpty())
                    <div class="tags">
                        @foreach($series->tags->take(12) as $tag)
                            <span class="tag">{{ $tag->name }}</span>
                        @endforeach
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</main>
</body>
</html>

