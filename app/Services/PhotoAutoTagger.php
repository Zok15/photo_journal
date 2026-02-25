<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Support\SeriesResponseCache;
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

    /**
     * @var array<string, true>|null
     */
    private ?array $moderationOnlyTagLookup = null;

    private const STOPWORDS = [
        'img', 'image', 'photo', 'picture', 'snapshot', 'scan', 'camera',
        'corporation',
        'copy', 'final', 'new', 'temp', 'test', 'edited', 'edit', 'small',
        'large', 'original', 'pxl', 'dsc', 'dscn', 'mvimg', 'picsart',
        'jpg', 'jpeg', 'png', 'webp', 'heic', 'raw',
        'foto', 'fota', 'fotka', 'kartinka', 'izobrazhenie', 'skrinshot',
        'bez', 'papki', 'novyj', 'novyi', 'proba', 'proverka', 'debug', 'demo', 'sample', 'example',
    ];

    private const LOW_VALUE_AUTO_TAGS = [
        'portrait', 'landscape', 'horizontal', 'vertical', 'square',
        'photo', 'image', 'picture', 'snapshot',
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
    public function detectTagsForPhoto(
        Photo $photo,
        string $disk,
        ?Series $series = null,
        bool $forceVision = false
    ): array
    {
        return collect($this->buildTagNames($photo, $disk, $series, $forceVision, false))
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->filter(fn (string $value): bool => !$this->isModerationOnlyTag($value))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function detectTagsForModeration(Photo $photo, string $disk, ?Series $series = null): array
    {
        // For moderation we must always call vision to avoid misses caused by local-tag skip threshold.
        return $this->buildTagNames($photo, $disk, $series, true, true);
    }

    public function attachPhotoTagsToSeries(Series $series, Photo $photo, string $disk): void
    {
        $this->syncSeriesTags($series, $this->detectTagsForPhoto($photo, $disk, $series), false);
    }

    public function isRejectedAutoTag(string $tag): bool
    {
        $normalized = $this->normalizeTag($tag);
        if ($normalized === '') {
            return true;
        }

        if ($this->isRejectedNumericTag($normalized)) {
            return true;
        }

        if (in_array($normalized, self::STOPWORDS, true)) {
            return true;
        }

        if (in_array($normalized, self::LOW_VALUE_AUTO_TAGS, true)) {
            return true;
        }

        // Machine-like ids (img1234, dsc2494, pxl991122) have low retrieval value.
        if (preg_match('/^(img|dsc|dscn|pxl|mvimg|photo|image)\d+$/', $normalized) === 1) {
            return true;
        }

        if ($this->isLikelyGarbageAutoTag($normalized)) {
            return true;
        }

        return false;
    }

    private function isLikelyGarbageAutoTag(string $tag): bool
    {
        if (preg_match('/^(iso\d+|focal\d+mm|shutter(?:\d+over\d+|\d+s))$/', $tag) === 1) {
            return false;
        }

        $length = strlen($tag);

        // Two-char tags are usually noise from model outputs and hurt retrieval quality.
        if ($length < 3) {
            return true;
        }

        $lowered = strtolower($tag);
        if (str_contains($lowered, 'proverka') || str_contains($lowered, 'test')) {
            return true;
        }

        // Hash-like tags (hex blobs) are not user-meaningful.
        if ($length >= 12 && preg_match('/^[a-f0-9]+$/', $tag) === 1) {
            return true;
        }

        $hasLetter = preg_match('/[a-z]/', $tag) === 1;
        $hasDigit = preg_match('/\d/', $tag) === 1;

        // Mixed long alphanumeric tokens are typically generated garbage.
        if ($length >= 8 && $hasLetter && $hasDigit) {
            return true;
        }

        // Long runs of the same char are almost always garbage labels.
        if (preg_match('/(.)\1{4,}/', $tag) === 1) {
            return true;
        }

        // No-vowel long words are likely random consonant sequences.
        $vowelCount = preg_match_all('/[aeiouy]/', $tag);
        if ($length >= 8 && $vowelCount === 0) {
            return true;
        }

        // Very long consonant/digit stretches are low-value machine noise.
        if ($length >= 10 && preg_match('/[bcdfghjklmnpqrstvwxyz0-9]{6,}/', $tag) === 1 && $vowelCount <= 2) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $tagNames
     */
    public function syncSeriesTags(Series $series, array $tagNames, bool $replace = true): void
    {
        // Нормализация + ограничение количества тегов, чтобы держать данные чистыми.
        $normalizedAll = collect($tagNames)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => $this->normalizeTag($value))
            ->filter()
            ->unique()
            ->take(self::MAX_TAGS)
            ->values();

        $normalized = $normalizedAll
            ->filter(fn (string $value): bool => !$this->isModerationOnlyTag($value))
            ->filter(fn (string $value): bool => !$this->isRejectedAutoTag($value))
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

        $this->applySafetyAuditForPublishedSeries($series, $normalizedAll->all());
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
    private function buildTagNames(
        Photo $photo,
        string $disk,
        ?Series $series = null,
        bool $forceVision = false,
        bool $moderationMode = false
    ): array
    {
        $all = [];

        $baseName = pathinfo((string) ($photo->original_name ?: basename((string) $photo->path)), PATHINFO_FILENAME);
        $nameTokens = $this->tokensFromText($baseName);
        $all = [...$all, ...$nameTokens];
        $all = [...$all, ...$this->semanticTagsFromTokens($nameTokens)];
        $all = [...$all, ...$this->dateTagsFromText($baseName)];
        $all = [...$all, ...$this->tagsFromPhotoMetadata($photo)];

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
        if (!$forceVision && $skipVisionThreshold > 0 && $preparedBeforeVision->count() >= $skipVisionThreshold) {
            return $preparedBeforeVision
                ->take(self::MAX_TAGS)
                ->values()
                ->all();
        }

        $visionHints = $this->buildVisionHints($preparedBeforeVision, $series, $moderationMode);
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
    private function buildVisionHints(
        \Illuminate\Support\Collection $preparedBeforeVision,
        ?Series $series,
        bool $moderationMode = false
    ): array
    {
        $fromPhoto = $preparedBeforeVision
            ->take(12)
            ->values()
            ->all();

        if ($moderationMode) {
            // Moderation is isolated from series/search tagging context.
            return $this->normalizeAndDedupeTags($fromPhoto)
                ->take((int) config('vision.max_hints', 20))
                ->values()
                ->all();
        }

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

    private function isModerationOnlyTag(string $tag): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($tag)) ?? '');
        if ($normalized === '') {
            return false;
        }

        return isset($this->moderationOnlyTagLookup()[$normalized]);
    }

    /**
     * @return array<string, true>
     */
    private function moderationOnlyTagLookup(): array
    {
        if ($this->moderationOnlyTagLookup !== null) {
            return $this->moderationOnlyTagLookup;
        }

        $lookup = [];
        $source = array_merge(
            (array) config('moderation.blocked_tags', []),
            (array) config('moderation.contextual_risk_tags', []),
            (array) config('moderation.context_sensitive_blocked_tags', []),
            (array) config('moderation.soft_blocked_tags', []),
            (array) config('moderation.contextual_risk_direct_block_tags', [])
        );

        foreach ($source as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($tag)) ?? '');
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        $this->moderationOnlyTagLookup = $lookup;

        return $this->moderationOnlyTagLookup;
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
            ->filter(fn (string $value): bool => !$this->isRejectedAutoTag($value))
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
        return [
            $this->currentYearTag(),
            strtolower(($photo->created_at ?? now())->format('F')),
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
            $month = (int) $m[2];
            $tags[] = $this->seasonByMonth($month);
        }

        $tags = [...$tags, ...$this->semanticTagsFromTokens($nameTokens)];

        return $tags;
    }

    /**
     * @return array<int, string>
     */
    private function tagsFromPhotoMetadata(Photo $photo): array
    {
        $photo->loadMissing('metadata');
        $metadata = $photo->metadata;
        if ($metadata === null) {
            return [];
        }

        $tags = [];

        $cameraModel = trim((string) ($metadata->camera_model ?? ''));
        if ($cameraModel !== '') {
            $tags = [...$tags, ...$this->tokensFromText($cameraModel)];
        }

        $iso = (int) ($metadata->iso ?? 0);
        if ($iso > 0) {
            $tags[] = $this->isoStepTag($iso);
        }

        $focalLength = (float) ($metadata->focal_length_mm ?? 0);
        if ($focalLength > 0) {
            $tags[] = $this->focalRoundedTag($focalLength);
        }

        $exposureSeconds = $this->parseExposureSeconds((string) ($metadata->exposure_time ?? ''));
        if ($exposureSeconds !== null) {
            $tags[] = $this->exposureTag($exposureSeconds);
        }

        return $tags;
    }

    private function isoStepTag(int $iso): string
    {
        if ($iso <= 200) {
            return 'isoLow';
        }

        if ($iso <= 800) {
            return 'isoMedium';
        }

        if ($iso <= 1600) {
            return 'isoHigh';
        }

        return 'isoVeryHigh';
    }

    private function focalRoundedTag(float $focalLengthMm): string
    {
        if ($focalLengthMm < 35) {
            return 'focalWide';
        }

        if ($focalLengthMm < 70) {
            return 'focalStandard';
        }

        if ($focalLengthMm < 135) {
            return 'focalPortrait';
        }

        if ($focalLengthMm < 300) {
            return 'focalTelephoto';
        }

        return 'focalSuperTelephoto';
    }

    private function exposureTag(float $seconds): string
    {
        if ($seconds <= (1 / 500)) {
            return 'shutterVeryFast';
        }

        if ($seconds <= (1 / 125)) {
            return 'shutterFast';
        }

        if ($seconds <= (1 / 30)) {
            return 'shutterHandheld';
        }

        if ($seconds < 1) {
            return 'shutterSlow';
        }

        return 'shutterLongExposure';
    }

    private function parseExposureSeconds(string $raw): ?float
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $value, $matches) === 1) {
            $numerator = (float) $matches[1];
            $denominator = (float) $matches[2];
            if ($denominator <= 0.0) {
                return null;
            }

            return $numerator / $denominator;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;
        return $seconds > 0 ? $seconds : null;
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

        return $tag === $this->currentYearTag();
    }

    private function currentYearTag(): string
    {
        return (string) now()->year;
    }

    /**
     * @param array<int, string> $normalizedTags
     */
    private function applySafetyAuditForPublishedSeries(Series $series, array $normalizedTags): void
    {
        if ((string) $series->publication_status !== Series::PUBLICATION_PUBLISHED) {
            return;
        }

        if ((string) $series->moderation_status === Series::MODERATION_MANUAL_APPROVED) {
            return;
        }

        $hardBlocked = $this->hardBlockedTagLookup();
        if ($hardBlocked === []) {
            return;
        }

        $matched = [];
        foreach ($normalizedTags as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($tag)) ?? '');
            if ($key === '' || !isset($hardBlocked[$key])) {
                continue;
            }

            $matched[$hardBlocked[$key]] = true;
        }

        if ($matched === []) {
            return;
        }

        $labels = array_keys($matched);
        sort($labels);

        $series->forceFill([
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_REJECTED,
            'moderation_status' => Series::MODERATION_REJECTED,
            'moderation_reason' => 'Blocked content detected during automatic moderation.',
            'moderation_labels' => $labels,
            'moderated_at' => now(),
            'moderated_by' => null,
        ])->save();

        SeriesResponseCache::bumpUser((int) $series->user_id);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }

    /**
     * @return array<string, string>
     */
    private function hardBlockedTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.blocked_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $clean = trim($tag);
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $clean) ?? '');
            if ($clean === '' || $normalized === '') {
                continue;
            }

            $lookup[$normalized] = $clean;
        }

        return $lookup;
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
