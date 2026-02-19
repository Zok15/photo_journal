<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_store_creates_tag_with_normalized_name(): void
    {
        $response = $this->postJson('/api/v1/tags', [
            'name' => '  Night   City  ',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'nightCity');
        $this->assertDatabaseHas('tags', [
            'name' => 'nightCity',
        ]);
    }

    public function test_store_rejects_non_latin_name(): void
    {
        $response = $this->postJson('/api/v1/tags', [
            'name' => 'ночь',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_endpoint_is_not_available_for_users(): void
    {
        $tag = Tag::query()->create([
            'name' => 'oldTag',
        ]);

        $response = $this->patchJson("/api/v1/tags/{$tag->id}", [
            'name' => '  New   Name ',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'oldTag',
        ]);
    }

    public function test_store_rejects_duplicate_name_after_normalization(): void
    {
        Tag::query()->create([
            'name' => 'nightCity',
        ]);

        $response = $this->postJson('/api/v1/tags', [
            'name' => ' Night   City ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_blank_name_and_does_not_generate_fallback_tag(): void
    {
        $response = $this->postJson('/api/v1/tags', [
            'name' => '   ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertDatabaseMissing('tags', [
            'name' => 'tag',
        ]);
    }

    public function test_index_returns_sorted_tag_list_for_filters(): void
    {
        $series = Series::query()->create([
            'user_id' => (int) auth()->id(),
            'title' => 'Tagged',
            'is_public' => false,
        ]);

        $first = Tag::query()->create(['name' => 'cityNight']);
        $second = Tag::query()->create(['name' => 'bird']);
        $third = Tag::query()->create(['name' => 'autumn']);
        $series->tags()->attach([$first->id, $second->id, $third->id]);

        $response = $this->getJson('/api/v1/tags?limit=10');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'autumn');
        $response->assertJsonPath('data.1.name', 'bird');
        $response->assertJsonPath('data.2.name', 'cityNight');
    }
}
