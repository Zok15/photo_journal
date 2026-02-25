<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_public_index_sorts_by_shooting_datetime_when_requested(): void
    {
        $author = User::factory()->create(['name' => 'Alice']);

        $firstTaken = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Taken first public',
            'is_public' => true,
        ]);
        $secondTaken = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Taken second public',
            'is_public' => true,
        ]);

        $photoFirst = $firstTaken->photos()->create([
            'path' => 'photos/public/'.$firstTaken->id.'/first.jpg',
            'original_name' => 'first.jpg',
        ]);
        $photoFirst->metadata()->create([
            'taken_at' => '2026-01-15 07:00:00',
        ]);

        $photoSecond = $secondTaken->photos()->create([
            'path' => 'photos/public/'.$secondTaken->id.'/second.jpg',
            'original_name' => 'second.jpg',
        ]);
        $photoSecond->metadata()->create([
            'taken_at' => '2026-02-20 11:20:00',
        ]);

        $response = $this->getJson('/api/v1/public/series?sort=taken_old');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$firstTaken->id, $secondTaken->id], array_slice($ids, 0, 2));
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

    public function test_public_index_returns_author_suggestions_with_period_fallback(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $charlie = User::factory()->create(['name' => 'Charlie']);

        $oldSeries = Series::query()->create([
            'user_id' => $alice->id,
            'title' => 'Too old',
            'is_public' => true,
        ]);
        DB::table('series')->where('id', $oldSeries->id)->update([
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        $bobRecentOne = Series::query()->create([
            'user_id' => $bob->id,
            'title' => 'Bob recent one',
            'is_public' => true,
        ]);
        DB::table('series')->where('id', $bobRecentOne->id)->update([
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ]);

        $bobRecentTwo = Series::query()->create([
            'user_id' => $bob->id,
            'title' => 'Bob recent two',
            'is_public' => true,
        ]);
        DB::table('series')->where('id', $bobRecentTwo->id)->update([
            'created_at' => Carbon::now()->subHours(12),
            'updated_at' => Carbon::now()->subHours(12),
        ]);

        $charlieRecent = Series::query()->create([
            'user_id' => $charlie->id,
            'title' => 'Charlie recent',
            'is_public' => true,
        ]);
        DB::table('series')->where('id', $charlieRecent->id)->update([
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        $response = $this->getJson('/api/v1/public/series');

        $response->assertOk();
        $response->assertJsonPath('author_suggestions.0.name', 'Bob');
        $response->assertJsonPath('author_suggestions.0.series_count', 2);
        $response->assertJsonPath('author_suggestions.0.period_days', 3);
        $response->assertJsonMissingPath('author_suggestions.2.name');
    }

}
