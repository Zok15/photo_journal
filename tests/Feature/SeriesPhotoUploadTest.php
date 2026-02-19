<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeriesPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_upload_photos_creates_records_and_stores_files(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Coast trip',
            'description' => 'Beach day',
        ]);

        $files = [
            $this->fakeImage('first.jpg'),
            $this->fakeImage('second.png'),
        ];

        $response = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => $files,
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'photos_created');
        $response->assertJsonCount(0, 'photos_failed');

        $this->assertDatabaseHas('photos', [
            'series_id' => $series->id,
            'original_name' => 'first.jpg',
        ]);

        $this->assertDatabaseHas('photos', [
            'series_id' => $series->id,
            'original_name' => 'second.png',
        ]);

        $storedPaths = collect($response->json('photos_created'))
            ->pluck('path')
            ->all();

        foreach ($storedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_upload_assigns_auto_tags_from_file_name(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Flowers',
            'description' => 'Spring',
        ]);

        $file = $this->fakeImage('Blue-Flower_macro_2026.jpg');

        $response = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$file],
        ]);

        $response->assertCreated();

        $tagNames = $series->fresh()->load('tags')->tags->pluck('name')->all();

        $this->assertContains('blue', $tagNames);
        $this->assertContains('flower', $tagNames);
        $this->assertContains('macro', $tagNames);
        $this->assertContains(now()->format('Y'), $tagNames);
        $this->assertContains(strtolower(now()->format('F')), $tagNames);
        $this->assertNotContains('square', $tagNames);
        $this->assertNotContains('portrait', $tagNames);
        $this->assertNotContains('landscape', $tagNames);
    }

    public function test_upload_normalizes_alpha_numeric_camera_suffixes_in_tags(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Birds',
            'description' => 'Bird set',
        ]);

        $response = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage('Bird4.jpg')],
        ]);

        $response->assertCreated();

        $tagNames = $series->fresh()->load('tags')->tags->pluck('name')->all();
        $this->assertContains('bird', $tagNames);
        $this->assertNotContains('bird4', $tagNames);
    }

    public function test_upload_extracts_animal_category_from_filename_tokens(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Dogs',
            'description' => 'Dog set',
        ]);

        $response = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage('happy-dog-puppy.jpg')],
        ]);

        $response->assertCreated();

        $tagNames = $series->fresh()->load('tags')->tags->pluck('name')->all();
        $this->assertContains('animal', $tagNames);
    }

    public function test_upload_keeps_only_current_year_numeric_tag_for_auto_tags(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Years',
            'description' => 'Year tags',
        ]);

        $pastYear = (string) (now()->year - 1);
        $futureYear = (string) (now()->year + 1);
        $currentYear = (string) now()->year;

        $response = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage("IMG_{$pastYear}_{$futureYear}.jpg")],
        ]);

        $response->assertCreated();

        $tagNames = $series->fresh()->load('tags')->tags->pluck('name')->all();
        $numericTags = collect($tagNames)
            ->filter(fn ($name): bool => preg_match('/^\d+$/', (string) $name) === 1)
            ->values()
            ->all();

        $this->assertSame([$currentYear], $numericTags);
        $this->assertNotContains($pastYear, $tagNames);
        $this->assertNotContains($futureYear, $tagNames);
    }

    public function test_retag_endpoint_rebuilds_auto_tags_for_series_photos(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Retag',
            'description' => 'Auto retag',
        ]);

        $upload = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage('Red-Rose_2026.jpg')],
        ]);

        $upload->assertCreated();

        $series->tags()->detach();

        $response = $this->postJson("/api/v1/series/{$series->id}/photos/retag");

        $response->assertOk();
        $response->assertJsonPath('data.processed', 1);
        $response->assertJsonPath('data.failed', 0);

        $names = $series->fresh()->load('tags')->tags->pluck('name')->all();

        $this->assertContains('red', $names);
        $this->assertContains('rose', $names);
        $this->assertContains('2026', $names);
    }

    public function test_retag_endpoint_keeps_manual_numeric_tags(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Cleanup',
            'description' => 'Cleanup numeric tags',
        ]);

        $upload = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage('IMG_2026_2427.jpg')],
        ]);

        $upload->assertCreated();
        $meaningless = Tag::query()->firstOrCreate(['name' => '2427']);
        $series->tags()->syncWithoutDetaching([$meaningless->id]);

        $response = $this->postJson("/api/v1/series/{$series->id}/photos/retag");
        $response->assertOk();

        $names = $series->fresh()->load('tags')->tags->pluck('name')->all();
        $this->assertContains('2427', $names);
        $this->assertContains('2026', $names);
        $this->assertDatabaseHas('tags', ['name' => '2427']);
    }

    public function test_retag_endpoint_keeps_manual_orientation_tags(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Cleanup',
            'description' => 'Cleanup orientation tags',
        ]);

        $upload = $this->post("/api/v1/series/{$series->id}/photos", [
            'photos' => [$this->fakeImage('flower.jpg')],
        ]);

        $upload->assertCreated();
        $portrait = Tag::query()->firstOrCreate(['name' => 'portrait']);
        $series->tags()->syncWithoutDetaching([$portrait->id]);

        $response = $this->postJson("/api/v1/series/{$series->id}/photos/retag");
        $response->assertOk();

        $names = $series->fresh()->load('tags')->tags->pluck('name')->all();
        $this->assertContains('portrait', $names);
        $this->assertDatabaseHas('tags', ['name' => 'portrait']);
    }

    public function test_index_returns_only_photos_for_series(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Urban',
            'description' => 'Streets',
        ]);

        $other = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Nature',
            'description' => 'Forest',
        ]);

        $photoA = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/a.jpg',
            'original_name' => 'a.jpg',
        ]);

        $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/b.jpg',
            'original_name' => 'b.jpg',
        ]);

        $other->photos()->create([
            'path' => 'photos/series/'.$other->id.'/c.jpg',
            'original_name' => 'c.jpg',
        ]);

        $response = $this->getJson("/api/v1/series/{$series->id}/photos");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'));

        $this->assertTrue($rows->contains(fn (array $row): bool => $row['id'] === $photoA->id));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['series_id'] === $series->id));
    }

    public function test_index_supports_pagination_and_sorting(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Paginated',
            'description' => 'Sorted',
        ]);

        $oldest = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/1.jpg',
            'original_name' => 'a.jpg',
            'size' => 100,
            'created_at' => now()->subDays(2),
        ]);

        $newest = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/2.jpg',
            'original_name' => 'b.jpg',
            'size' => 200,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/series/{$series->id}/photos?per_page=1&sort_by=created_at&sort_dir=asc");

        $response->assertOk();
        $response->assertJsonPath('per_page', 1);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $oldest->id);

        $responseDesc = $this->getJson("/api/v1/series/{$series->id}/photos?per_page=1&sort_by=created_at&sort_dir=desc");

        $responseDesc->assertOk();
        $responseDesc->assertJsonPath('data.0.id', $newest->id);
    }

    public function test_show_returns_photo_for_series(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Portraits',
            'description' => 'Studio',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/portrait.jpg',
            'original_name' => 'portrait.jpg',
        ]);

        $response = $this->getJson("/api/v1/series/{$series->id}/photos/{$photo->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $photo->id);
        $response->assertJsonPath('data.series_id', $series->id);
    }

    public function test_update_changes_original_name(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Macro',
            'description' => 'Details',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/macro.jpg',
            'original_name' => 'macro.jpg',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}/photos/{$photo->id}", [
            'original_name' => 'macro-new.jpg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.original_name', 'macroNew.jpg');
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'original_name' => 'macroNew.jpg',
        ]);
    }

    public function test_update_keeps_existing_extension_even_if_user_changes_it(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Rename',
            'description' => 'Extension lock',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/frame.png',
            'original_name' => 'frame.png',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}/photos/{$photo->id}", [
            'original_name' => 'new-name.jpg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.original_name', 'newName.png');
    }

    public function test_update_transliterates_non_latin_name_to_ascii_camel_case(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Rename',
            'description' => 'Transliteration',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/macro.jpg',
            'original_name' => 'macro.jpg',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}/photos/{$photo->id}", [
            'original_name' => 'Привет мир 2026',
        ]);

        $response->assertOk();

        $normalized = (string) $response->json('data.original_name');
        $this->assertMatchesRegularExpression('/^[a-z][A-Za-z0-9]*\.jpg$/', $normalized);
        $this->assertStringNotContainsString(' ', $normalized);
    }

    public function test_reorder_updates_photo_sort_order(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Reorder',
            'description' => 'Manual order',
        ]);

        $first = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/1.jpg',
            'original_name' => '1.jpg',
        ]);
        $second = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/2.jpg',
            'original_name' => '2.jpg',
        ]);
        $third = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/3.jpg',
            'original_name' => '3.jpg',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}/photos/reorder", [
            'photo_ids' => [$second->id, $third->id, $first->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.photo_ids.0', $second->id);
        $response->assertJsonPath('data.photo_ids.1', $third->id);
        $response->assertJsonPath('data.photo_ids.2', $first->id);

        $this->assertDatabaseHas('photos', [
            'id' => $second->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('photos', [
            'id' => $third->id,
            'sort_order' => 2,
        ]);
        $this->assertDatabaseHas('photos', [
            'id' => $first->id,
            'sort_order' => 3,
        ]);
    }

    public function test_reorder_rejects_payload_without_all_series_photo_ids(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Reorder',
            'description' => 'Validation',
        ]);

        $first = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/1.jpg',
            'original_name' => '1.jpg',
        ]);
        $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/2.jpg',
            'original_name' => '2.jpg',
        ]);

        $response = $this->patchJson("/api/v1/series/{$series->id}/photos/reorder", [
            'photo_ids' => [$first->id],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'photo_ids must contain all photos of the series exactly once.');
    }

    public function test_reorder_updates_preview_order_in_series_index_response(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Reorder preview',
            'description' => 'Index preview order',
        ]);

        $first = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/1.jpg',
            'original_name' => '1.jpg',
        ]);
        $second = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/2.jpg',
            'original_name' => '2.jpg',
        ]);
        $third = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/3.jpg',
            'original_name' => '3.jpg',
        ]);

        $reorder = [$third->id, $first->id, $second->id];

        $patch = $this->patchJson("/api/v1/series/{$series->id}/photos/reorder", [
            'photo_ids' => $reorder,
        ]);
        $patch->assertOk();

        $index = $this->getJson('/api/v1/series');
        $index->assertOk();

        $previewIds = collect($index->json('data'))
            ->firstWhere('id', $series->id)['preview_photos'] ?? [];
        $previewIds = collect($previewIds)
            ->pluck('id')
            ->take(3)
            ->values()
            ->all();

        $this->assertSame($reorder, $previewIds);
    }

    public function test_destroy_deletes_photo_and_file(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Delete',
            'description' => 'To remove',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/to-delete.jpg',
            'original_name' => 'to-delete.jpg',
        ]);

        Storage::disk('local')->put($photo->path, 'content');

        $response = $this->deleteJson("/api/v1/series/{$series->id}/photos/{$photo->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('photos', [
            'id' => $photo->id,
        ]);
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_series_index_does_not_return_304_after_photo_delete(): void
    {
        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Delete cache',
            'description' => 'Cache revalidation',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/cache-delete.jpg',
            'original_name' => 'cache-delete.jpg',
        ]);

        Storage::disk('local')->put($photo->path, 'content');

        $first = $this->getJson('/api/v1/series');
        $first->assertOk();

        $ifModifiedSince = (string) $first->headers->get('Last-Modified');
        $this->assertNotSame('', $ifModifiedSince);

        $this->deleteJson("/api/v1/series/{$series->id}/photos/{$photo->id}")
            ->assertNoContent();

        $second = $this
            ->withHeader('If-Modified-Since', $ifModifiedSince)
            ->getJson('/api/v1/series');

        $second->assertOk();
        $photosCount = collect($second->json('data'))
            ->firstWhere('id', $series->id)['photos_count'] ?? null;
        $this->assertSame(0, $photosCount);
    }

    public function test_photo_must_belong_to_series(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Main',
            'description' => 'Main',
        ]);

        $other = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Other',
            'description' => 'Other',
        ]);

        $photo = $other->photos()->create([
            'path' => 'photos/series/'.$other->id.'/other.jpg',
            'original_name' => 'other.jpg',
        ]);

        $response = $this->getJson("/api/v1/series/{$series->id}/photos/{$photo->id}");

        $response->assertNotFound();
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
