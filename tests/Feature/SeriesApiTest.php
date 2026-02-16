<?php

namespace Tests\Feature;

use App\Jobs\ProcessSeries;
use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_series_with_photos_count(): void
    {
        $first = Series::query()->create([
            'title' => 'First',
            'description' => 'Old one',
        ]);

        $second = Series::query()->create([
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
                UploadedFile::fake()->image('one.jpg', 1200, 800),
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
            UploadedFile::fake()->image('ok.jpg', 1200, 800),
            UploadedFile::fake()->image('fail.jpg', 1200, 800),
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

    public function test_show_returns_series_with_photos_tags_and_count(): void
    {
        $series = Series::query()->create([
            'title' => 'Mountains',
            'description' => 'Trip album',
        ]);

        $photo = Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/mountains-1.jpg',
            'original_name' => 'mountains-1.jpg',
            'mime' => 'image/jpeg',
        ]);

        $tag = Tag::query()->create([
            'name' => 'landscape',
        ]);

        $photo->tags()->attach($tag->id);

        $response = $this->getJson("/api/v1/series/{$series->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $series->id);
        $response->assertJsonPath('data.photos_count', 1);
        $response->assertJsonPath('data.photos.0.id', $photo->id);
        $response->assertJsonPath('data.photos.0.tags.0.name', 'landscape');
    }

    public function test_update_changes_title_and_description(): void
    {
        $series = Series::query()->create([
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

    public function test_destroy_deletes_series_and_returns_no_content(): void
    {
        $series = Series::query()->create([
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
}
