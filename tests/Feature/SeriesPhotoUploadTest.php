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

    public function test_sync_tags_creates_and_attaches_tags(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Tagged',
            'description' => 'Tagged',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/tagged.jpg',
            'original_name' => 'tagged.jpg',
        ]);

        $response = $this->putJson("/api/v1/series/{$series->id}/photos/{$photo->id}/tags", [
            'tags' => ['Street', ' night ', 'night'],
        ]);

        $response->assertOk();

        $names = collect($response->json('data.tags'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['night', 'street'], $names);

        $this->assertDatabaseHas('tags', ['name' => 'street']);
        $this->assertDatabaseHas('tags', ['name' => 'night']);

        $this->assertDatabaseHas('photo_tag', [
            'photo_id' => $photo->id,
            'tag_id' => Tag::query()->where('name', 'street')->first()->id,
        ]);
    }

    public function test_attach_tags_does_not_detach_existing(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Attach',
            'description' => 'Attach',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/attach.jpg',
            'original_name' => 'attach.jpg',
        ]);

        $existing = Tag::query()->create(['name' => 'existing']);
        $photo->tags()->attach($existing->id);

        $response = $this->postJson("/api/v1/series/{$series->id}/photos/{$photo->id}/tags", [
            'tags' => ['New'],
        ]);

        $response->assertOk();

        $names = collect($response->json('data.tags'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['existing', 'new'], $names);

        $this->assertDatabaseHas('photo_tag', [
            'photo_id' => $photo->id,
            'tag_id' => $existing->id,
        ]);
    }

    public function test_detach_removes_single_tag(): void
    {
        $series = Series::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Detach',
            'description' => 'Detach',
        ]);

        $photo = $series->photos()->create([
            'path' => 'photos/series/'.$series->id.'/detach.jpg',
            'original_name' => 'detach.jpg',
        ]);

        $first = Tag::query()->create(['name' => 'first']);
        $second = Tag::query()->create(['name' => 'second']);
        $photo->tags()->attach([$first->id, $second->id]);

        $response = $this->deleteJson("/api/v1/series/{$series->id}/photos/{$photo->id}/tags/{$first->id}");

        $response->assertOk();

        $names = collect($response->json('data.tags'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['second'], $names);
        $this->assertDatabaseMissing('photo_tag', [
            'photo_id' => $photo->id,
            'tag_id' => $first->id,
        ]);
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
