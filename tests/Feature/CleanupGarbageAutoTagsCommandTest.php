<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanupGarbageAutoTagsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_command_detaches_garbage_auto_tags_and_keeps_valid_or_manual(): void
    {
        $user = User::factory()->create();
        $seriesA = Series::query()->create([
            'user_id' => $user->id,
            'title' => 'A',
            'description' => 'A',
        ]);
        $seriesB = Series::query()->create([
            'user_id' => $user->id,
            'title' => 'B',
            'description' => 'B',
        ]);

        $numericGarbage = Tag::query()->create(['name' => '2427']);
        $lowValue = Tag::query()->create(['name' => 'portrait']);
        $stopword = Tag::query()->create(['name' => 'img']);
        $year = Tag::query()->create(['name' => (string) now()->year]);
        $valid = Tag::query()->create(['name' => 'rose']);

        DB::table('series_tag')->insert([
            ['series_id' => $seriesA->id, 'tag_id' => $numericGarbage->id, 'source' => 'auto'],
            ['series_id' => $seriesA->id, 'tag_id' => $lowValue->id, 'source' => 'auto'],
            ['series_id' => $seriesB->id, 'tag_id' => $lowValue->id, 'source' => 'manual'],
            ['series_id' => $seriesA->id, 'tag_id' => $stopword->id, 'source' => 'auto'],
            ['series_id' => $seriesA->id, 'tag_id' => $year->id, 'source' => 'auto'],
            ['series_id' => $seriesA->id, 'tag_id' => $valid->id, 'source' => 'auto'],
        ]);

        $this->artisan('tags:cleanup-garbage-auto')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('series_tag', [
            'series_id' => $seriesA->id,
            'tag_id' => $numericGarbage->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseMissing('series_tag', [
            'series_id' => $seriesA->id,
            'tag_id' => $lowValue->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseMissing('series_tag', [
            'series_id' => $seriesA->id,
            'tag_id' => $stopword->id,
            'source' => 'auto',
        ]);

        $this->assertDatabaseHas('series_tag', [
            'series_id' => $seriesB->id,
            'tag_id' => $lowValue->id,
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('series_tag', [
            'series_id' => $seriesA->id,
            'tag_id' => $year->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('series_tag', [
            'series_id' => $seriesA->id,
            'tag_id' => $valid->id,
            'source' => 'auto',
        ]);

        $this->assertDatabaseMissing('tags', ['id' => $numericGarbage->id]);
        $this->assertDatabaseMissing('tags', ['id' => $stopword->id]);
        $this->assertDatabaseHas('tags', ['id' => $lowValue->id]);
        $this->assertDatabaseHas('tags', ['id' => $year->id]);
        $this->assertDatabaseHas('tags', ['id' => $valid->id]);
    }
}
