<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Сервис автоматической генерации и синхронизации тегов для фотографий/серий.
 *
 * Источники тегов:
 * - имя файла;
 * - EXIF;
 * - дата загрузки;
 * - внешний vision-сервис.
 */
class PhotoAutoTagger
{
    public function __construct(private VisionTaggerClient $visionTaggerClient)
    {
    }

    private const STOPWORDS = [
        'img', 'image', 'photo', 'picture', 'snapshot', 'scan', 'camera',
        'copy', 'final', 'new', 'temp', 'test', 'edited', 'edit', 'small',
        'large', 'original', 'pxl', 'dsc', 'dscn', 'mvimg', 'picsart',
        'jpg', 'jpeg', 'png', 'webp', 'heic', 'raw',
        'foto', 'fota', 'fotka', 'kartinka', 'izobrazhenie', 'skrinshot',
        'bez', 'papki', 'novyj', 'novyi', 'proba',
    ];

    private const COLOR_KEYWORDS = [
        'red' => ['red', 'scarlet', 'crimson', 'bordo', 'krasn'],
        'orange' => ['orange', 'amber', 'mandarin', 'oranzh'],
        'yellow' => ['yellow', 'gold', 'lemon', 'zhelt'],
        'green' => ['green', 'emerald', 'lime', 'olive', 'zelen'],
        'blue' => ['blue', 'azure', 'cyan', 'navy', 'sini', 'golub'],
        'purple' => ['purple', 'violet', 'lilac', 'fiolet'],
        'pink' => ['pink', 'magenta', 'fuchsia', 'rozov'],
        'white' => ['white', 'ivory', 'bel'],
        'black' => ['black', 'dark', 'chern'],
        'gray' => ['gray', 'grey', 'silver', 'ser'],
        'brown' => ['brown', 'choco', 'coffee', 'korichnev'],
    ];

    private const FLOWER_KEYWORDS = [
        'rose' => ['rose', 'roza'],
        'tulip' => ['tulip', 'tyulpan'],
        'lily' => ['lily', 'lilium', 'liliya'],
        'orchid' => ['orchid', 'orhideya'],
        'daisy' => ['daisy', 'romashka'],
        'sunflower' => ['sunflower', 'podsolnuh'],
        'dandelion' => ['dandelion', 'oduvanchik'],
        'peony' => ['peony', 'pion'],
        'crocus' => ['crocus'],
        'snowdrop' => ['snowdrop', 'podsnezhnik'],
    ];

    private const BIRD_KEYWORDS = [
        'sparrow' => ['sparrow', 'vorobei'],
        'crow' => ['crow', 'vorona'],
        'raven' => ['raven', 'voron'],
        'pigeon' => ['pigeon', 'golub'],
        'seagull' => ['seagull', 'gull', 'chaika'],
        'swallow' => ['swallow', 'lastochka'],
        'owl' => ['owl', 'sova'],
        'eagle' => ['eagle', 'orel'],
        'duck' => ['duck', 'utka'],
        'swan' => ['swan', 'lebed'],
        'tit' => ['tit', 'sinica'],
        'woodpecker' => ['woodpecker', 'dyatel'],
    ];

    private const ANIMAL_KEYWORDS = [
        'animal' => [
            'animal', 'fauna', 'creature', 'beast',
            'dog', 'dogs', 'puppy', 'canine', 'sobaka', 'psina', 'pes', 'shchenok',
            'cat', 'cats', 'kitten', 'feline', 'koshka', 'kot', 'kotik', 'kotenok',
        ],
    ];

    private const SEASON_KEYWORDS = [
        'winter' => ['winter', 'zima'],
        'spring' => ['spring', 'vesna'],
        'summer' => ['summer', 'leto'],
        'autumn' => ['autumn', 'fall', 'osen'],
    ];

    private const MAX_TAGS = 20;
    private const COMPOUND_CANONICAL_MAP = [
        'greatcormorant' => 'greatCormorant',
        'commoncrane' => 'commonCrane',
        'sandhillcrane' => 'sandhillCrane',
        'greyheron' => 'greyHeron',
        'herringgull' => 'herringGull',
        'blackheadedgull' => 'blackHeadedGull',
        'housesparrow' => 'houseSparrow',
        'muteswan' => 'muteSwan',
        'waterlily' => 'waterLily',
    ];

    public function visionEnabled(): bool
    {
        return $this->visionTaggerClient->isEnabled();
    }

    public function visionHealthy(): bool
    {
        return $this->visionTaggerClient->isHealthy();
    }

    /**
     * @return array<int, string>
     */
    public function detectTagsForPhoto(Photo $photo, string $disk, ?Series $series = null): array
    {
        return $this->buildTagNames($photo, $disk, $series);
    }

    public function attachPhotoTagsToSeries(Series $series, Photo $photo, string $disk): void
    {
        $this->syncSeriesTags($series, $this->detectTagsForPhoto($photo, $disk, $series), false);
    }

    /**
     * @param array<int, string> $tagNames
     */
    public function syncSeriesTags(Series $series, array $tagNames, bool $replace = true): void
    {
        // Нормализация + ограничение количества тегов, чтобы держать данные чистыми.
        $normalized = collect($tagNames)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => $this->normalizeTag($value))
            ->filter()
            ->filter(fn (string $value): bool => !$this->isRejectedNumericTag($value))
            ->filter(fn (string $value): bool => !in_array($value, self::STOPWORDS, true))
            ->unique()
            ->take(self::MAX_TAGS)
            ->values();

        $ids = $normalized
            ->map(fn (string $name): Tag => $this->findOrCreateTagSafely($name))
            ->pluck('id')
            ->all();

        $tagIds = collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $existingRows = DB::table('series_tag')
            ->where('series_id', $series->id)
            ->get(['tag_id', 'source']);

        $allAttachedIds = $existingRows
            ->map(fn ($row): int => (int) $row->tag_id)
            ->unique()
            ->values()
            ->all();

        $existingAutoIds = $existingRows
            ->filter(fn ($row): bool => (string) ($row->source ?? 'manual') === 'auto')
            ->map(fn ($row): int => (int) $row->tag_id)
            ->unique()
            ->values()
            ->all();

        $attached = [];
        $detached = [];

        if ($replace) {
            $detached = array_values(array_diff($existingAutoIds, $tagIds));
            if ($detached !== []) {
                DB::table('series_tag')
                    ->where('series_id', $series->id)
                    ->where('source', 'auto')
                    ->whereIn('tag_id', $detached)
                    ->delete();
                $this->cleanupDetachedOrphanTags($detached);
            }
        }

        $toAttach = array_values(array_diff($tagIds, $allAttachedIds));
        if ($toAttach !== []) {
            DB::table('series_tag')->insert(
                collect($toAttach)
                    ->map(fn (int $tagId): array => [
                        'series_id' => $series->id,
                        'tag_id' => $tagId,
                        'source' => 'auto',
                    ])
                    ->all()
            );
            $attached = $toAttach;
        }

        if ($attached !== [] || $detached !== []) {
            $this->touchSeriesForCache($series);
        }
    }

    /**
     * @param array<int, int|string> $tagIds
     */
    private function cleanupDetachedOrphanTags(array $tagIds): void
    {
        $ids = collect($tagIds)
            ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        Tag::query()
            ->whereKey($ids)
            ->doesntHave('series')
            ->doesntHave('photos')
            ->delete();
    }

    /**
     * @param array{attached?:array,detached?:array,updated?:array} $changes
     */
    private function hasSyncChanges(array $changes): bool
    {
        return ! empty($changes['attached'] ?? [])
            || ! empty($changes['detached'] ?? [])
            || ! empty($changes['updated'] ?? []);
    }

    private function touchSeriesForCache(Series $series): void
    {
        // If-Modified-Since is second-precision. Bump timestamp by 1s to avoid false 304 in same second.
        $series->forceFill([
            'updated_at' => now()->addSecond(),
        ])->saveQuietly();
    }

    /**
     * @return array<int, string>
     */
    private function buildTagNames(Photo $photo, string $disk, ?Series $series = null): array
    {
        $all = [];

        $baseName = pathinfo((string) ($photo->original_name ?: basename((string) $photo->path)), PATHINFO_FILENAME);
        $nameTokens = $this->tokensFromText($baseName);
        $all = [...$all, ...$nameTokens];
        $all = [...$all, ...$this->semanticTagsFromTokens($nameTokens)];
        $all = [...$all, ...$this->dateTagsFromText($baseName)];

        $absolutePath = $this->resolveAbsolutePath($disk, (string) $photo->path);
        if ($absolutePath !== null) {
            $all = [...$all, ...$this->tagsFromExif($absolutePath, $nameTokens)];
        }

        if (is_string($photo->mime) && $photo->mime !== '') {
            $all[] = Str::of($photo->mime)->after('/')->lower()->value();
        }

        $all = [...$all, ...$this->tagsFromUploadMoment($photo)];

        $preparedBeforeVision = $this->normalizeAndDedupeTags($all);
        $skipVisionThreshold = max(0, (int) config('vision.skip_if_tags_count_at_least', 7));

        // Если уже достаточно тегов локально — можно не дергать внешний сервис.
        if ($skipVisionThreshold > 0 && $preparedBeforeVision->count() >= $skipVisionThreshold) {
            return $preparedBeforeVision
                ->take(self::MAX_TAGS)
                ->values()
                ->all();
        }

        $visionHints = $this->buildVisionHints($preparedBeforeVision, $series);
        $all = [...$all, ...$this->visionTaggerClient->detectTags($disk, (string) $photo->path, $visionHints)];

        return $this->normalizeAndDedupeTags($all)
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, string> $preparedBeforeVision
     * @return array<int, string>
     */
    private function buildVisionHints(\Illuminate\Support\Collection $preparedBeforeVision, ?Series $series): array
    {
        $fromPhoto = $preparedBeforeVision
            ->take(12)
            ->values()
            ->all();

        $fromSeries = [];
        if ($series !== null) {
            $fromSeries = $series->tags()
                ->pluck('name')
                ->all();
        }

        $fromGlobal = Cache::remember('vision:global-tag-hints:v1', now()->addMinutes(5), function (): array {
            return Tag::query()
                ->select('tags.name')
                ->leftJoin('series_tag', 'series_tag.tag_id', '=', 'tags.id')
                ->groupBy('tags.id', 'tags.name')
                ->orderByRaw('COUNT(series_tag.id) DESC')
                ->orderByDesc('tags.updated_at')
                ->limit(100)
                ->pluck('tags.name')
                ->all();
        });

        return $this->normalizeAndDedupeTags([...$fromPhoto, ...$fromSeries, ...$fromGlobal])
            ->take((int) config('vision.max_hints', 20))
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $all
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function normalizeAndDedupeTags(array $all): \Illuminate\Support\Collection
    {
        return collect($all)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => $this->normalizeTag($value))
            ->filter()
            ->filter(fn (string $value): bool => !$this->isRejectedNumericTag($value))
            ->filter(fn (string $value): bool => !in_array($value, self::STOPWORDS, true))
            ->unique();
    }

    /**
     * @return array<int, string>
     */
    private function tokensFromText(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($value)) ?: [];

        return collect($parts)
            ->filter(fn ($part): bool => is_string($part) && $part !== '')
            ->map(function (string $part): string {
                $token = Str::of($part)
                    ->transliterate()
                    ->lower()
                    ->replaceMatches('/[^a-z0-9]+/', '')
                    ->value();

                $token = trim($token);

                // Camera-style suffixes (bird4, img12) are noisy as tags.
                if (preg_match('/^([a-z]{2,})\d+$/', $token, $matches) === 1) {
                    return $matches[1];
                }

                return $token;
            })
            ->filter(fn (string $part): bool => strlen($part) >= 2)
            ->filter(fn (string $part): bool => !in_array($part, self::STOPWORDS, true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function tagsFromUploadMoment(Photo $photo): array
    {
        $uploadedAt = $photo->created_at;
        if ($uploadedAt === null) {
            return [];
        }

        return [
            $uploadedAt->format('Y'),
            strtolower($uploadedAt->format('F')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tagsFromExif(string $absolutePath, array $nameTokens): array
    {
        if (!function_exists('exif_read_data')) {
            return [];
        }

        $exif = @exif_read_data($absolutePath, null, true, false);
        if (!is_array($exif)) {
            return [];
        }

        $tags = [];

        foreach ([['IFD0', 'Make'], ['IFD0', 'Model'], ['EXIF', 'LensModel']] as [$group, $key]) {
            $raw = (string) ($exif[$group][$key] ?? '');
            if ($raw !== '') {
                $tags = [...$tags, ...$this->tokensFromText($raw)];
            }
        }

        $date = (string) ($exif['EXIF']['DateTimeOriginal'] ?? '');
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})/', $date, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $tags[] = 'year-'.$year;
            $tags[] = sprintf('date-%04d-%02d-%02d', $year, $month, $day);
            $tags[] = $this->seasonByMonth($month);
        }

        $tags = [...$tags, ...$this->semanticTagsFromTokens($nameTokens)];

        return $tags;
    }

    private function normalizeTag(string $tag): string
    {
        $collapsed = Str::of($tag)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        if ($collapsed !== '' && isset(self::COMPOUND_CANONICAL_MAP[$collapsed])) {
            return self::COMPOUND_CANONICAL_MAP[$collapsed];
        }

        $ascii = Str::of($tag)
            ->ascii()
            ->replaceMatches('/([a-z0-9])([A-Z])/', '$1 $2')
            ->value();

        $parts = preg_split('/[^A-Za-z0-9]+/', $ascii) ?: [];
        $parts = array_values(array_filter($parts, fn ($part): bool => is_string($part) && $part !== ''));
        $parts = array_map('strtolower', $parts);

        if ($parts === []) {
            return '';
        }

        $head = $parts[0];
        $tail = array_map(
            fn (string $part): string => ucfirst($part),
            array_slice($parts, 1)
        );

        $normalized = $head.implode('', $tail);

        return strlen($normalized) >= 2 ? $normalized : '';
    }

    private function isRejectedNumericTag(string $tag): bool
    {
        if (preg_match('/^\d+$/', $tag) !== 1) {
            return false;
        }

        return !$this->isMeaningfulNumericTag($tag);
    }

    private function isMeaningfulNumericTag(string $tag): bool
    {
        if (preg_match('/^\d{4}$/', $tag) !== 1) {
            return false;
        }

        $year = (int) $tag;

        return $year >= 1900 && $year <= 2100;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    private function semanticTagsFromTokens(array $tokens): array
    {
        $tags = [];

        $colorTags = $this->mapKeywords($tokens, self::COLOR_KEYWORDS);
        $flowerTags = $this->mapKeywords($tokens, self::FLOWER_KEYWORDS);
        $birdTags = $this->mapKeywords($tokens, self::BIRD_KEYWORDS);
        $animalTags = $this->mapKeywords($tokens, self::ANIMAL_KEYWORDS);
        $seasonTags = $this->mapKeywords($tokens, self::SEASON_KEYWORDS);

        $tags = [...$tags, ...$colorTags, ...$flowerTags, ...$birdTags, ...$animalTags, ...$seasonTags];

        if ($flowerTags !== []) {
            $tags[] = 'flower';
        }

        if ($birdTags !== []) {
            $tags[] = 'bird';
        }

        if ($animalTags !== []) {
            $tags[] = 'animal';
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param array<int, string> $tokens
     * @param array<string, array<int, string>> $map
     * @return array<int, string>
     */
    private function mapKeywords(array $tokens, array $map): array
    {
        $lookup = array_flip($tokens);
        $matched = [];

        foreach ($map as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (isset($lookup[$keyword])) {
                    $matched[] = $tag;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @return array<int, string>
     */
    private function dateTagsFromText(string $value): array
    {
        $tags = [];
        $patterns = [
            '/\b(19\d{2}|20\d{2}|2100)[\._\-](0?[1-9]|1[0-2])[\._\-](0?[1-9]|[12]\d|3[01])\b/u',
            '/\b(0?[1-9]|[12]\d|3[01])[\._\-](0?[1-9]|1[0-2])[\._\-](19\d{2}|20\d{2}|2100)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER) !== 1 && empty($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                if (count($match) < 4) {
                    continue;
                }

                if (strlen($match[1]) === 4) {
                    $year = (int) $match[1];
                    $month = (int) $match[2];
                    $day = (int) $match[3];
                } else {
                    $day = (int) $match[1];
                    $month = (int) $match[2];
                    $year = (int) $match[3];
                }

                if (!checkdate($month, $day, $year)) {
                    continue;
                }

                $tags[] = (string) $year;
                $tags[] = 'year-'.$year;
                $tags[] = sprintf('date-%04d-%02d-%02d', $year, $month, $day);
                $tags[] = $this->seasonByMonth($month);
            }
        }

        return $tags;
    }

    private function seasonByMonth(int $month): string
    {
        return match ($month) {
            12, 1, 2 => 'winter',
            3, 4, 5 => 'spring',
            6, 7, 8 => 'summer',
            default => 'autumn',
        };
    }

    private function resolveAbsolutePath(string $disk, string $path): ?string
    {
        try {
            $absolutePath = Storage::disk($disk)->path($path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath) ? $absolutePath : null;
    }

    private function findOrCreateTagSafely(string $name): Tag
    {
        try {
            $tag = Tag::firstOrCreate(['name' => $name]);

            // MySQL collations are often case-insensitive; normalize existing rows to canonical case.
            if ($tag->name !== $name) {
                $tag->name = $name;
                $tag->save();
                $tag->refresh();
            }

            return $tag;
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;

            if ($sqlState === '23000') {
                $existing = Tag::query()->where('name', $name)->first();
                if ($existing !== null) {
                    if ($existing->name !== $name) {
                        $existing->name = $name;
                        $existing->save();
                        $existing->refresh();
                    }
                    return $existing;
                }
            }

            throw $e;
        }
    }
}
