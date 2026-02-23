<?php

namespace App\Services\Series;

use App\Models\Series;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SeriesTagMutationService
{
    /**
     * @param array<int, string> $tagNames
     * @return array{tag_ids:array<int,int>, attached_count:int, forced_manual_count:int}
     */
    public function attachManualTags(Series $series, array $tagNames): array
    {
        $normalizedNames = $this->normalizeTagNames($tagNames);
        if ($normalizedNames === []) {
            return [
                'tag_ids' => [],
                'attached_count' => 0,
                'forced_manual_count' => 0,
            ];
        }

        $tagIds = collect($normalizedNames)
            ->map(fn (string $name): Tag => $this->findOrCreateTagSafely($name))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $existingIds = DB::table('series_tag')
            ->where('series_id', $series->id)
            ->pluck('tag_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $toAttach = array_values(array_diff($tagIds, $existingIds));
        if ($toAttach !== []) {
            DB::table('series_tag')->insert(
                collect($toAttach)
                    ->map(fn (int $tagId): array => [
                        'series_id' => $series->id,
                        'tag_id' => $tagId,
                        'source' => 'manual',
                    ])
                    ->all()
            );
        }

        $forcedManual = DB::table('series_tag')
            ->where('series_id', $series->id)
            ->whereIn('tag_id', $tagIds)
            ->where('source', '!=', 'manual')
            ->update(['source' => 'manual']);

        return [
            'tag_ids' => $tagIds,
            'attached_count' => count($toAttach),
            'forced_manual_count' => (int) $forcedManual,
        ];
    }

    public function detachTag(Series $series, Tag $tag): int
    {
        return $series->tags()->detach($tag->id);
    }

    public function cleanupUnusedTag(Tag $tag): void
    {
        $stillUsedInSeries = $tag->series()->exists();
        $stillUsedInPhotos = $tag->photos()->exists();

        if (! $stillUsedInSeries && ! $stillUsedInPhotos) {
            $tag->delete();
        }
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    public function normalizeTagNames(array $tags): array
    {
        return collect($tags)
            ->map(fn (string $name): string => Tag::normalizeTagName($name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function findOrCreateTagSafely(string $name): Tag
    {
        try {
            $tag = Tag::firstOrCreate(['name' => $name]);

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
