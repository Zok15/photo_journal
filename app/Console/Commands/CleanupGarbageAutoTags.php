<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Services\PhotoAutoTagger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupGarbageAutoTags extends Command
{
    protected $signature = 'tags:cleanup-garbage-auto';

    protected $description = 'Detach garbage auto tags from series and delete orphan garbage tags';

    public function __construct(private readonly PhotoAutoTagger $photoAutoTagger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $garbageTagIds = Tag::query()
            ->select(['id', 'name'])
            ->get()
            ->filter(fn (Tag $tag): bool => $this->photoAutoTagger->isRejectedAutoTag((string) $tag->name))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($garbageTagIds === []) {
            $this->info('No garbage tags found.');
            return self::SUCCESS;
        }

        $detached = DB::table('series_tag')
            ->where('source', 'auto')
            ->whereIn('tag_id', $garbageTagIds)
            ->delete();

        $deleted = Tag::query()
            ->whereIn('id', $garbageTagIds)
            ->doesntHave('series')
            ->doesntHave('photos')
            ->delete();

        $this->info("Detached auto links: {$detached}. Deleted orphan tags: {$deleted}.");

        return self::SUCCESS;
    }
}
