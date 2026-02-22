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
        $series = Series::query()->find($this->seriesId);
        if ($series === null) {
            return;
        }

        if ((string) $series->publication_status !== Series::PUBLICATION_PENDING_MODERATION) {
            return;
        }

        $blocked = $this->blockedTagLookup();
        if ($blocked === []) {
            $this->approveSeries($series, []);

            return;
        }

        $disk = config('filesystems.default');
        $matchedHardLabels = [];
        $observedNormalizedTags = [];
        $benignContext = $this->benignContextTagLookup();
        $humanContext = $this->humanContextTagLookup();
        $contextSensitive = $this->contextSensitiveBlockedTagLookup();
        $contextualRisk = $this->contextualRiskTagLookup();

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
                $contextualRisk,
                &$matchedHardLabels,
                &$observedNormalizedTags
            ): void {
                foreach ($photos as $photo) {
                    try {
                        $tags = $photoAutoTagger->detectTagsForModeration($photo, $disk, $series);
                        $normalizedTags = [];
                        $hasBenignContext = false;
                        $hasHumanContext = false;

                        foreach ($tags as $tag) {
                            $normalized = $this->normalizeTag($tag);
                            if ($normalized === '') {
                                continue;
                            }

                            $normalizedTags[] = $normalized;
                            $observedNormalizedTags[$normalized] = true;
                            if (isset($benignContext[$normalized])) {
                                $hasBenignContext = true;
                            }
                            if (isset($humanContext[$normalized])) {
                                $hasHumanContext = true;
                            }
                        }

                        foreach ($normalizedTags as $normalized) {
                            $isHardBlocked = isset($blocked[$normalized]);
                            $isContextualRisk = isset($contextualRisk[$normalized]);
                            if (!$isHardBlocked && !$isContextualRisk) {
                                continue;
                            }

                            if ($hasBenignContext && !$hasHumanContext) {
                                if ($isContextualRisk) {
                                    continue;
                                }
                                if ($isHardBlocked && isset($contextSensitive[$normalized])) {
                                    continue;
                                }
                            }

                            $label = $blocked[$normalized] ?? $contextualRisk[$normalized];
                            if ($label !== null) {
                                $matchedHardLabels[$label] = true;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Series moderation failed for photo.', [
                            'series_id' => $series->id,
                            'photo_id' => $photo->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $seriesRiskEvidence = [];
        foreach ($series->tags()->pluck('name')->all() as $tagName) {
            if (!is_string($tagName)) {
                continue;
            }

            $normalized = $this->normalizeTag($tagName);
            if ($normalized === '') {
                continue;
            }

            $observedNormalizedTags[$normalized] = true;
            if (isset($contextualRisk[$normalized])) {
                $seriesRiskEvidence[$normalized] = true;
            }
        }

        $matchedHardLabels = $this->collectBlockedLabelsFromTags(
            array_keys($observedNormalizedTags),
            $blocked,
            $contextualRisk,
            $contextSensitive,
            $benignContext,
            $humanContext,
            $seriesRiskEvidence
        );

        $hardLabels = array_keys($matchedHardLabels);
        sort($hardLabels);

        if ($hardLabels !== []) {
            $this->rejectSeries($series, $hardLabels);

            return;
        }

        $this->approveSeries($series, []);
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
     * @param array<int, string> $normalizedTags
     * @param array<string, string> $blocked
     * @param array<string, string> $contextualRisk
     * @param array<string, true> $contextSensitive
     * @param array<string, true> $benignContext
     * @param array<string, true> $humanContext
     * @param array<string, true> $seriesRiskEvidence
     * @return array<string, true>
     */
    private function collectBlockedLabelsFromTags(
        array $normalizedTags,
        array $blocked,
        array $contextualRisk,
        array $contextSensitive,
        array $benignContext,
        array $humanContext,
        array $seriesRiskEvidence
    ): array {
        $matched = [];
        $hasBenign = false;
        $hasHuman = false;

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
        }

        foreach ($normalizedTags as $tag) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            $isHardBlocked = isset($blocked[$tag]);
            $isContextualRisk = isset($contextualRisk[$tag]);
            if (!$isHardBlocked && !$isContextualRisk) {
                continue;
            }

            // Contextual-risk labels must be supported by persisted series tags
            // to avoid one-frame zero-shot noise.
            if ($isContextualRisk && !isset($seriesRiskEvidence[$tag])) {
                continue;
            }

            if ($hasBenign && !$hasHuman) {
                if ($isContextualRisk) {
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
}
