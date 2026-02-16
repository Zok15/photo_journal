<?php

namespace Tests\Feature;

use App\Jobs\ProcessSeries;
use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeriesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_returns_paginated_series_with_photos_count(): void
    {
        $first = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'First',
            'description' => 'Old one',
        ]);

        $second = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Second',
            'description' => 'New one',
        ]);

        Photo::query()->create([
            'series_id' => $second->id,
            'path' => 'photos/second-1.jpg',
            'original_name' => 'second-1.jpg',
        ]);

        $response = $this->getJson('/api/v1/series?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('total', 2);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame(1, $rows[$second->id]['photos_count']);
        $this->assertSame(0, $rows[$first->id]['photos_count']);
    }

    public function test_store_creates_series_and_dispatches_processing_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $payload = [
            'title' => 'Winter walk',
            'description' => 'Evening city lights',
            'photos' => [
                $this->fakeImage('one.jpg'),
            ],
        ];

        $response = $this->post('/api/v1/series', $payload);

        $response->assertCreated();
        $response->assertJsonPath('status', 'queued');
        $response->assertJsonCount(1, 'photos_created');
        $response->assertJsonCount(0, 'photos_failed');
        $this->assertDatabaseHas('series', [
            'title' => 'Winter walk',
            'description' => 'Evening city lights',
        ]);
        $this->assertDatabaseHas('photos', [
            'original_name' => 'one.jpg',
        ]);

        Queue::assertPushed(ProcessSeries::class, 1);
    }

    public function test_store_requires_at_least_one_photo(): void
    {
        $response = $this->postJson('/api/v1/series', [
            'title' => 'Empty series',
            'description' => 'No photos',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('series', [
            'title' => 'Empty series',
        ]);
    }

    public function test_store_returns_failed_entries_but_keeps_series_if_some_photos_saved(): void
    {
        Queue::fake();

        $seriesPayload = [
            'title' => 'Partial',
            'description' => 'Partial upload',
        ];

        $files = [
            $this->fakeImage('ok.jpg'),
            $this->fakeImage('fail.jpg'),
        ];

        $writes = 0;
        $disk = \Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->andReturnUsing(function ($path, $file, $name, $options = []) use (&$writes) {
                $writes++;
                if ($writes === 2) {
                    throw new \RuntimeException('Disk write failed');
                }

                return $path.'/'.$name;
            });
        $disk->shouldReceive('delete')->andReturnTrue();

        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->post('/api/v1/series', [
            ...$seriesPayload,
            'photos' => $files,
        ]);

        $response->assertCreated();
        $response->assertJsonCount(1, 'photos_created');
        $response->assertJsonCount(1, 'photos_failed');
        $response->assertJsonPath('photos_failed.0.original_name', 'fail.jpg');

        $this->assertDatabaseHas('series', [
            'title' => 'Partial',
        ]);

        $this->assertDatabaseHas('photos', [
            'original_name' => 'ok.jpg',
        ]);
    }

    public function test_show_returns_series_with_series_tags_and_count(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Mountains',
            'description' => 'Trip album',
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/mountains-1.jpg',
            'original_name' => 'mountains-1.jpg',
            'mime' => 'image/jpeg',
        ]);

        $tag = Tag::query()->create([
            'name' => 'landscape',
        ]);

        $series->tags()->attach($tag->id);

        $response = $this->getJson("/api/v1/series/{$series->id}?include_photos=1");

        $response->assertOk();
        $response->assertJsonPath('data.id', $series->id);
        $response->assertJsonPath('data.photos_count', 1);
        $response->assertJsonPath('data.tags.0.name', 'landscape');
    }

    public function test_update_changes_title_and_description(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Before',
            'description' => 'Before desc',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}", [
            'title' => 'After',
            'description' => 'After desc',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $series->id);
        $response->assertJsonPath('data.title', 'After');
        $response->assertJsonPath('data.description', 'After desc');

        $this->assertDatabaseHas('series', [
            'id' => $series->id,
            'title' => 'After',
            'description' => 'After desc',
        ]);
    }

    public function test_index_without_filters_preserves_default_behavior(): void
    {
        $older = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Older',
            'description' => 'First',
        ]);
        $older->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        $newer = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Newer',
            'description' => 'Second',
        ]);
        $newer->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->saveQuietly();

        $response = $this->getJson('/api/v1/series');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_index_filters_by_search(): void
    {
        Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Bird Album',
            'description' => 'Seaside',
        ]);
        Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Flower Album',
            'description' => 'Garden',
        ]);

        $response = $this->getJson('/api/v1/series?search=seaside');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.title', 'Bird Album');
    }

    public function test_index_filters_by_tag_with_normalization(): void
    {
        $redBird = Tag::query()->create(['name' => 'redBird']);
        $blueBird = Tag::query()->create(['name' => 'blueBird']);

        $seriesWithRed = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Red',
            'description' => 'Tagged',
        ]);
        $seriesWithBlue = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Blue',
            'description' => 'Tagged',
        ]);

        $seriesWithRed->tags()->attach($redBird->id);
        $seriesWithBlue->tags()->attach($blueBird->id);

        foreach (['Red Bird', 'red-bird', 'red bird'] as $tagQuery) {
            $response = $this->getJson('/api/v1/series?tag='.urlencode($tagQuery));
            $response->assertOk();
            $this->assertCount(1, $response->json('data'));
            $response->assertJsonPath('data.0.id', $seriesWithRed->id);
        }
    }

    public function test_index_filters_by_date_range(): void
    {
        $inside = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Inside',
            'description' => null,
        ]);
        $inside->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();
        Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Outside',
            'description' => null,
        ])->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->saveQuietly();

        $from = now()->subDays(3)->toDateString();
        $to = now()->subDay()->toDateString();

        $response = $this->getJson("/api/v1/series?date_from={$from}&date_to={$to}");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_index_sorts_oldest_when_requested(): void
    {
        $older = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Older sort',
            'description' => null,
        ]);
        $older->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();
        $newer = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Newer sort',
            'description' => null,
        ]);
        $newer->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->saveQuietly();

        $response = $this->getJson('/api/v1/series?sort=old');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$older->id, $newer->id], $ids);
    }

    public function test_index_supports_page_and_per_page_parameters(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $series = Series::query()->create([
                'user_id' => $this->user->id,
                'title' => "Series {$i}",
                'description' => null,
            ]);
            $series->forceFill([
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ])->saveQuietly();
        }

        $response = $this->getJson('/api/v1/series?per_page=2&page=2');
        $response->assertOk();
        $response->assertJsonPath('per_page', 2);
        $response->assertJsonPath('current_page', 2);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_attach_tags_adds_series_tags_without_duplicates_and_normalizes_names(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Tagged series',
            'description' => 'Taggable',
        ]);

        $response = $this->postJson("/api/v1/series/{$series->id}/tags", [
            'tags' => ['Bird', ' bird ', 'night sky', 'Ворона серая'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $series->id);

        $names = collect($response->json('data.tags'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['bird', 'nightSky', 'voronaSeraia'], $names);

        $this->assertDatabaseHas('series_tag', [
            'series_id' => $series->id,
            'tag_id' => Tag::query()->where('name', 'nightSky')->firstOrFail()->id,
        ]);
    }

    public function test_detach_tag_removes_tag_from_series(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Tagged series',
            'description' => 'Taggable',
        ]);

        $first = Tag::query()->create(['name' => 'bird']);
        $second = Tag::query()->create(['name' => 'nature']);
        $series->tags()->attach([$first->id, $second->id]);

        $response = $this->deleteJson("/api/v1/series/{$series->id}/tags/{$first->id}");

        $response->assertOk();
        $names = collect($response->json('data.tags'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['nature'], $names);

        $this->assertDatabaseMissing('series_tag', [
            'series_id' => $series->id,
            'tag_id' => $first->id,
        ]);
    }

    public function test_detach_tag_deletes_orphaned_tag_from_database(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Tagged series',
            'description' => 'Taggable',
        ]);

        $tag = Tag::query()->create(['name' => 'rareBird']);
        $series->tags()->attach($tag->id);

        $response = $this->deleteJson("/api/v1/series/{$series->id}/tags/{$tag->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('tags', [
            'id' => $tag->id,
        ]);
    }

    public function test_detach_tag_keeps_tag_if_used_by_another_series(): void
    {
        $seriesA = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'A',
            'description' => 'A',
        ]);
        $seriesB = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'B',
            'description' => 'B',
        ]);

        $tag = Tag::query()->create(['name' => 'sharedTag']);
        $seriesA->tags()->attach($tag->id);
        $seriesB->tags()->attach($tag->id);

        $response = $this->deleteJson("/api/v1/series/{$seriesA->id}/tags/{$tag->id}");

        $response->assertOk();
        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
        ]);
        $this->assertDatabaseHas('series_tag', [
            'series_id' => $seriesB->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_tag_suggest_returns_prefix_matches(): void
    {
        Tag::query()->create(['name' => 'greatCormorant']);
        Tag::query()->create(['name' => 'greatHeron']);
        Tag::query()->create(['name' => 'cormorant']);

        $response = $this->getJson('/api/v1/tags/suggest?q=great&limit=10');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['greatCormorant', 'greatHeron'], $names);
    }

    public function test_destroy_deletes_series_and_returns_no_content(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'To delete',
            'description' => 'Will be removed',
        ]);

        $response = $this->deleteJson("/api/v1/series/{$series->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('series', [
            'id' => $series->id,
        ]);
    }

    public function test_destroy_deletes_photo_files_from_storage(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'To delete with files',
            'description' => 'Cleanup test',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/cleanup.jpg',
            'original_name' => 'cleanup.jpg',
        ]);

        Storage::disk('local')->put($photo->path, 'content');
        Storage::disk('local')->assertExists($photo->path);

        $response = $this->deleteJson("/api/v1/series/{$series->id}");

        $response->assertNoContent();
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_user_cannot_view_foreign_series(): void
    {
        $foreignUser = User::factory()->create();
        $foreignSeries = Series::query()->create([
            'user_id' => $foreignUser->id,
            'title' => 'Foreign',
            'description' => 'Foreign',
        ]);

        $response = $this->getJson("/api/v1/series/{$foreignSeries->id}");

        $response->assertForbidden();
    }

    private function fakeImage(string $name): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7YfU8AAAAASUVORK5CYII=',
            true
        );

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
