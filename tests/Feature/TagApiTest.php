<?php

namespace Tests\Feature;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_tag_with_normalized_name(): void
    {
        $response = $this->postJson('/api/v1/tags', [
            'name' => '  Night   City  ',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'night city');
        $this->assertDatabaseHas('tags', [
            'name' => 'night city',
        ]);
    }

    public function test_store_rejects_non_latin_name(): void
    {
        $response = $this->postJson('/api/v1/tags', [
            'name' => 'ночь',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_changes_tag_name_with_validation_rules(): void
    {
        $tag = Tag::query()->create([
            'name' => 'old name',
        ]);

        $response = $this->patchJson("/api/v1/tags/{$tag->id}", [
            'name' => '  New   Name ',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'new name');
        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'new name',
        ]);
    }

    public function test_update_rejects_duplicate_name_after_normalization(): void
    {
        $first = Tag::query()->create(['name' => 'night city']);
        $second = Tag::query()->create(['name' => 'street']);

        $response = $this->patchJson("/api/v1/tags/{$second->id}", [
            'name' => ' Night   City ',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('tags', [
            'id' => $first->id,
            'name' => 'night city',
        ]);
    }
}
