<?php

namespace App\Jobs;

use App\Models\Series;
use App\Services\PhotoAutoTagger;
use App\Support\SeriesResponseCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModerateSeriesContent implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Moderation may run vision on many photos, so default 60s is too low.
     */
    public int $timeout = 1200;

    /**
     * Avoid queue spam with duplicate moderation jobs for same series.
     */
    public int $uniqueFor = 1800;

    public function __construct(public int $seriesId)
    {
    }

    public function uniqueId(): string
    {
        return 'series-moderation-'.$this->seriesId;
    }

    public function handle(PhotoAutoTagger $photoAutoTagger): void
    {
        $startedAt = microtime(true);
        $series = Series::query()->find($this->seriesId);
        if ($series === null) {
            return;
        }

        if ((string) $series->publication_status !== Series::PUBLICATION_PENDING_MODERATION) {
            return;
        }

        if ($this->isVisionEnabledAndUnhealthy()) {
            Log::warning('Series moderation postponed: vision tagger is enabled but unhealthy.', [
                'series_id' => $series->id,
            ]);
            $this->release(120);

            return;
        }

        $blocked = $this->blockedTagLookup();
        if ($blocked === []) {
            $this->approveSeries($series, []);

            return;
        }

        $disk = config('filesystems.default');
        $matchedHardLabels = [];
        $hardBlockedDetected = false;
        $processedPhotos = 0;
        $failedPhotos = 0;
        $benignContext = $this->benignContextTagLookup();
        $humanContext = $this->humanContextTagLookup();
        $contextSensitive = $this->contextSensitiveBlockedTagLookup();
        $contextSensitiveSupport = $this->contextSensitiveSupportTagLookup();
        $contextualRisk = $this->contextualRiskTagLookup();
        $directContextualBlock = $this->directContextualRiskBlockTagLookup();
        $directContextualSupport = $this->directContextualRiskSupportTagLookup();
        $directContextualWeakSupport = $this->directContextualWeakSupportTagLookup();
        $humanRequiredContextualRisk = $this->humanRequiredContextualRiskTagLookup();
        $alwaysHumanContextualRisk = $this->alwaysHumanContextualRiskTagLookup();

        $series->photos()
            ->orderBy('id')
            ->chunkById(100, function ($photos) use (
                $photoAutoTagger,
                $series,
                $disk,
                $blocked,
                $benignContext,
                $humanContext,
                $contextSensitive,
                $contextSensitiveSupport,
                $contextualRisk,
                $directContextualBlock,
                $directContextualSupport,
                $directContextualWeakSupport,
                $humanRequiredContextualRisk,
                $alwaysHumanContextualRisk,
                &$matchedHardLabels,
                &$hardBlockedDetected,
                &$processedPhotos,
                &$failedPhotos
            ) {
                foreach ($photos as $photo) {
                    try {
                        $processedPhotos++;
                        $tags = $photoAutoTagger->detectTagsForModeration($photo, $disk, $series);
                        $normalizedTags = [];

                        foreach ($tags as $tag) {
                            $normalized = $this->normalizeTag($tag);
                            if ($normalized === '') {
                                continue;
                            }

                            $normalizedTags[] = $normalized;
                        }

                        $photoMatched = $this->collectBlockedLabelsFromTags(
                            $normalizedTags,
                            $blocked,
                            $contextualRisk,
                            $contextSensitive,
                            $benignContext,
                            $humanContext,
                            $directContextualBlock,
                            $directContextualSupport,
                            $directContextualWeakSupport,
                            $contextSensitiveSupport,
                            $humanRequiredContextualRisk,
                            $alwaysHumanContextualRisk
                        );
                        foreach (array_keys($photoMatched) as $label) {
                            $matchedHardLabels[$label] = true;
                        }

                        if ($photoMatched !== []) {
                            $hardBlockedDetected = true;
                            break;
                        }
                    } catch (\Throwable $e) {
                        $failedPhotos++;
                        Log::warning('Series moderation failed for photo.', [
                            'series_id' => $series->id,
                            'photo_id' => $photo->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($hardBlockedDetected) {
                    return false;
                }
            });

        if ($failedPhotos > 0 && !$hardBlockedDetected) {
            Log::warning('Series moderation postponed: one or more photos failed during tagging.', [
                'series_id' => $series->id,
                'failed_photos' => $failedPhotos,
            ]);
            $this->release(120);

            return;
        }

        $hardLabels = array_keys($matchedHardLabels);
        sort($hardLabels);

        if ($hardLabels !== []) {
            $this->rejectSeries($series, $hardLabels);
            $this->logModerationCompleted($series, 'rejected', $processedPhotos, $hardLabels, $startedAt);

            return;
        }

        $this->approveSeries($series, []);
        $this->logModerationCompleted($series, 'approved', $processedPhotos, [], $startedAt);
    }

    private function isVisionEnabledAndUnhealthy(): bool
    {
        if (!((bool) config('vision.enabled', false))) {
            return false;
        }

        try {
            $base = (string) config('vision.url', 'http://127.0.0.1:8010/tag');
            $healthUrl = preg_replace('#/tag$#', '/health', $base) ?: $base;
            $response = Http::timeout(2)->acceptJson()->get($healthUrl);

            return !$response->ok() || ($response->json('ok') !== true);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<string, string>
     */
    private function blockedTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.blocked_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $clean = trim($tag);
            $normalized = $this->normalizeTag($clean);
            if ($clean === '' || $normalized === '') {
                continue;
            }

            $lookup[$normalized] = $clean;
        }

        return $lookup;
    }

    private function normalizeTag(string $tag): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($tag)) ?? '');
    }

    /**
     * @return array<string, true>
     */
    private function contextSensitiveBlockedTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.context_sensitive_blocked_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function benignContextTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.benign_context_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, string>
     */
    private function contextualRiskTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $clean = trim($tag);
            $normalized = $this->normalizeTag($clean);
            if ($clean === '' || $normalized === '') {
                continue;
            }

            $lookup[$normalized] = $clean;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function contextSensitiveSupportTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.context_sensitive_support_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function directContextualRiskBlockTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_direct_block_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function humanContextTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.human_context_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function directContextualRiskSupportTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_direct_support_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function directContextualWeakSupportTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_direct_weak_support_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function humanRequiredContextualRiskTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_requires_human_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function alwaysHumanContextualRiskTagLookup(): array
    {
        $lookup = [];

        foreach ((array) config('moderation.contextual_risk_always_human_tags', []) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $normalized = $this->normalizeTag($tag);
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }

    /**
     * @param array<int, string> $normalizedTags
     * @param array<string, string> $blocked
     * @param array<string, string> $contextualRisk
     * @param array<string, true> $contextSensitive
     * @param array<string, true> $benignContext
     * @param array<string, true> $humanContext
     * @param array<string, true> $directContextualBlock
     * @param array<string, true> $directContextualSupport
     * @param array<string, true> $directContextualWeakSupport
     * @param array<string, true> $contextSensitiveSupport
     * @param array<string, true> $humanRequiredContextualRisk
     * @param array<string, true> $alwaysHumanContextualRisk
     * @return array<string, true>
     */
    private function collectBlockedLabelsFromTags(
        array $normalizedTags,
        array $blocked,
        array $contextualRisk,
        array $contextSensitive,
        array $benignContext,
        array $humanContext,
        array $directContextualBlock,
        array $directContextualSupport,
        array $directContextualWeakSupport,
        array $contextSensitiveSupport,
        array $humanRequiredContextualRisk,
        array $alwaysHumanContextualRisk
    ): array {
        $matched = [];
        $hasBenign = false;
        $hasHuman = false;
        $hasDirectSupport = false;
        $hasDirectContextualRisk = false;
        $hasContextSensitiveSupport = false;

        foreach ($normalizedTags as $tag) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            if (isset($benignContext[$tag])) {
                $hasBenign = true;
            }
            if (isset($humanContext[$tag])) {
                $hasHuman = true;
            }
            if (isset($directContextualSupport[$tag]) && !isset($directContextualWeakSupport[$tag])) {
                $hasDirectSupport = true;
            }
            if (isset($directContextualBlock[$tag])) {
                $hasDirectContextualRisk = true;
            }
            if (isset($contextSensitiveSupport[$tag])) {
                $hasContextSensitiveSupport = true;
            }
        }

        foreach ($normalizedTags as $tag) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            $isHardBlocked = isset($blocked[$tag]);
            $isContextualRisk = isset($contextualRisk[$tag]);
            $isDirectContextualBlock = isset($directContextualBlock[$tag]);
            if (!$isHardBlocked && !$isContextualRisk) {
                continue;
            }

            if ($isDirectContextualBlock && !$hasDirectSupport) {
                continue;
            }

            if ($isHardBlocked && isset($contextSensitive[$tag]) && !$hasHuman && !$hasContextSensitiveSupport) {
                continue;
            }

            if ($isContextualRisk && isset($alwaysHumanContextualRisk[$tag]) && !$hasHuman) {
                continue;
            }

            if (
                $isContextualRisk
                && !$isDirectContextualBlock
                && isset($humanRequiredContextualRisk[$tag])
                && !$hasHuman
                && !$hasDirectContextualRisk
            ) {
                continue;
            }

            if ($hasBenign && !$hasHuman) {
                if ($isContextualRisk && !$isDirectContextualBlock) {
                    continue;
                }
                if ($isHardBlocked && isset($contextSensitive[$tag])) {
                    continue;
                }
            }

            $label = $blocked[$tag] ?? $contextualRisk[$tag] ?? null;
            if ($label !== null) {
                $matched[$label] = true;
            }
        }

        return $matched;
    }

    /**
     * @param array<int, string> $labels
     */
    private function approveSeries(Series $series, array $labels): void
    {
        $series->forceFill([
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_APPROVED,
            'moderation_reason' => null,
            'moderation_labels' => $labels,
            'moderated_at' => now(),
            'moderated_by' => null,
            'is_public' => true,
        ])->save();

        $this->invalidateCaches($series);
    }

    /**
     * @param array<int, string> $labels
     */
    private function rejectSeries(Series $series, array $labels): void
    {
        $series->forceFill([
            'publication_status' => Series::PUBLICATION_REJECTED,
            'moderation_status' => Series::MODERATION_REJECTED,
            'moderation_reason' => 'Blocked content detected during automatic moderation.',
            'moderation_labels' => $labels,
            'moderated_at' => now(),
            'moderated_by' => null,
            'is_public' => false,
        ])->save();

        $this->invalidateCaches($series);
    }

    private function invalidateCaches(Series $series): void
    {
        SeriesResponseCache::bumpUser((int) $series->user_id);
        SeriesResponseCache::bumpSeries((int) $series->id);
    }

    /**
     * @param array<int, string> $labels
     */
    private function logModerationCompleted(
        Series $series,
        string $decision,
        int $processedPhotos,
        array $labels,
        float $startedAt
    ): void {
        Log::info('Series moderation completed.', [
            'series_id' => (int) $series->id,
            'decision' => $decision,
            'processed_photos' => $processedPhotos,
            'labels' => $labels,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
