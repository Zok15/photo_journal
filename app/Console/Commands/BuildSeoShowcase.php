<?php

namespace App\Console\Commands;

use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class BuildSeoShowcase extends Command
{
    protected $signature = 'seo:build-showcase
        {--output= : Output directory for static pages (defaults to ../photo_journal_frontend/dist)}
        {--origin= : Public site origin, e.g. https://photolog.org}
        {--dry-run : Show actions without writing files}';

    protected $description = 'Build static showcase pages and sitemap.xml for public series.';

    public function handle(): int
    {
        $outputDir = $this->resolveOutputDirectory();
        $siteOrigin = $this->resolveSiteOrigin();
        $dryRun = (bool) $this->option('dry-run');
        $generatedAt = Carbon::now()->toIso8601String();

        $seriesList = Series::query()
            ->where('is_public', true)
            ->where('publication_status', Series::PUBLICATION_PUBLISHED)
            ->with(['user:id,name', 'tags:id,name'])
            ->withCount('photos')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'user_id',
                'title',
                'description',
                'slug',
                'created_at',
                'updated_at',
            ]);

        $this->info(sprintf('Public series found: %d', $seriesList->count()));
        $this->line(sprintf('Output directory: %s', $outputDir));
        $this->line(sprintf('Site origin: %s', $siteOrigin));
        $this->line(sprintf('Mode: %s', $dryRun ? 'dry-run' : 'write'));

        $urls = [];
        $urls[] = [
            'path' => '/',
            'lastmod' => $generatedAt,
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];
        $urls[] = [
            'path' => '/public/series',
            'lastmod' => $generatedAt,
            'changefreq' => 'daily',
            'priority' => '0.8',
        ];
        $urls[] = [
            'path' => '/privacy-policy',
            'lastmod' => $generatedAt,
            'changefreq' => 'monthly',
            'priority' => '0.4',
        ];

        $listPagePath = $outputDir.'/public/series/index.html';
        $listHtml = view('showcase.series-list', [
            'siteOrigin' => $siteOrigin,
            'generatedAt' => $generatedAt,
            'seriesList' => $seriesList,
        ])->render();
        $this->writeFile($listPagePath, $listHtml, $dryRun);

        $seriesRoot = $outputDir.'/series';
        $this->deleteDirectory($seriesRoot, $dryRun);

        foreach ($seriesList as $series) {
            $slug = $this->seriesSlug($series);
            $path = '/series/'.$slug;
            $seriesHtml = view('showcase.series-detail', [
                'siteOrigin' => $siteOrigin,
                'generatedAt' => $generatedAt,
                'series' => $series,
                'slug' => $slug,
            ])->render();

            $this->writeFile($seriesRoot.'/'.$slug.'/index.html', $seriesHtml, $dryRun);

            $urls[] = [
                'path' => $path,
                'lastmod' => $this->toIso($series->updated_at ?? $series->created_at ?? $generatedAt),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        $sitemapXml = $this->buildSitemapXml($siteOrigin, $urls);
        $this->writeFile($outputDir.'/sitemap.xml', $sitemapXml, $dryRun);

        $this->info('Showcase build completed.');

        return self::SUCCESS;
    }

    private function resolveOutputDirectory(): string
    {
        $option = trim((string) $this->option('output'));
        if ($option !== '') {
            return rtrim($option, '/');
        }

        return rtrim(dirname(base_path()).'/photo_journal_frontend/dist', '/');
    }

    private function resolveSiteOrigin(): string
    {
        $fromOption = trim((string) $this->option('origin'));
        if ($fromOption !== '') {
            return rtrim($fromOption, '/');
        }

        $fromAppUrl = trim((string) config('app.url', ''));
        if ($fromAppUrl !== '') {
            return rtrim($fromAppUrl, '/');
        }

        return 'https://photolog.org';
    }

    /**
     * @param array<int, array{path:string,lastmod:string,changefreq:string,priority:string}> $urls
     */
    private function buildSitemapXml(string $siteOrigin, array $urls): string
    {
        $unique = [];
        foreach ($urls as $entry) {
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') {
                continue;
            }

            if (isset($unique[$path])) {
                if ($entry['lastmod'] > $unique[$path]['lastmod']) {
                    $unique[$path]['lastmod'] = $entry['lastmod'];
                }

                continue;
            }

            $unique[$path] = $entry;
        }

        ksort($unique);

        $body = collect($unique)
            ->map(function (array $entry) use ($siteOrigin): string {
                $loc = $this->escapeXml($siteOrigin.$entry['path']);
                $lastmod = $this->escapeXml($entry['lastmod']);
                $changefreq = $this->escapeXml($entry['changefreq']);
                $priority = $this->escapeXml($entry['priority']);

                return implode("\n", [
                    '  <url>',
                    "    <loc>{$loc}</loc>",
                    "    <lastmod>{$lastmod}</lastmod>",
                    "    <changefreq>{$changefreq}</changefreq>",
                    "    <priority>{$priority}</priority>",
                    '  </url>',
                ]);
            })
            ->implode("\n");

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            $body,
            '</urlset>',
            '',
        ]);
    }

    private function writeFile(string $path, string $content, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line(sprintf('[dry-run] write %s', $path));
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->line(sprintf('[write] %s', $path));
    }

    private function deleteDirectory(string $path, bool $dryRun): void
    {
        if (!File::exists($path)) {
            return;
        }

        if ($dryRun) {
            $this->line(sprintf('[dry-run] delete directory %s', $path));
            return;
        }

        File::deleteDirectory($path);
        $this->line(sprintf('[delete] %s', $path));
    }

    private function seriesSlug(Series $series): string
    {
        $slug = trim((string) $series->slug);
        if ($slug !== '') {
            return $slug;
        }

        return (string) $series->id;
    }

    private function toIso(mixed $value): string
    {
        try {
            if ($value instanceof Carbon) {
                return $value->toIso8601String();
            }

            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return Carbon::now()->toIso8601String();
        }
    }

    private function escapeXml(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value
        );
    }
}

