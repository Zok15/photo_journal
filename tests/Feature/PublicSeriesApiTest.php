<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSeriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_index_is_accessible_without_auth_and_returns_only_public_series(): void
    {
        $author = User::factory()->create(['name' => 'Alice']);

        $publicSeries = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Public birds',
            'description' => 'Visible',
            'is_public' => true,
        ]);

        Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Private birds',
            'description' => 'Hidden',
            'is_public' => false,
        ]);

        $response = $this->getJson('/api/v1/public/series');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.id', $publicSeries->id);
        $response->assertJsonPath('data.0.owner_name', 'Alice');
    }

    public function test_public_index_supports_search_filter(): void
    {
        $author = User::factory()->create();

        Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Northern lights',
            'is_public' => true,
        ]);

        Series::query()->create([
            'user_id' => $author->id,
            'title' => 'City walk',
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/v1/public/series?search=city');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.title', 'City walk');
    }

    public function test_public_index_supports_author_filter(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        Series::query()->create([
            'user_id' => $alice->id,
            'title' => 'Alice public',
            'is_public' => true,
        ]);
        Series::query()->create([
            'user_id' => $bob->id,
            'title' => 'Bob public',
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/v1/public/series?author_id='.$alice->id);

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.title', 'Alice public');
    }

    public function test_public_show_returns_public_series_with_photos_for_guest(): void
    {
        $author = User::factory()->create(['name' => 'Alice']);
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Public album',
            'is_public' => true,
        ]);
        $series->photos()->create([
            'path' => 'photos/public/one.jpg',
            'original_name' => 'one.jpg',
        ]);

        $response = $this->getJson("/api/v1/public/series/{$series->id}?include_photos=1");

        $response->assertOk();
        $response->assertJsonPath('data.id', $series->id);
        $response->assertJsonPath('data.owner_name', 'Alice');
        $response->assertJsonPath('data.photos.0.original_name', 'one.jpg');
    }

    public function test_public_show_returns_404_for_private_series(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Private album',
            'is_public' => false,
        ]);

        $response = $this->getJson("/api/v1/public/series/{$series->id}");

        $response->assertNotFound();
    }
}
