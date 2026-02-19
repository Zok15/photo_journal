<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Series;
use App\Models\User;
use App\Policies\PhotoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhotoPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_reuses_loaded_series_for_multiple_checks(): void
    {
        $user = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $user->id,
            'title' => 'Access',
            'description' => 'Policy',
        ]);
        $photo = Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/'.$series->id.'/a.jpg',
            'original_name' => 'a.jpg',
        ]);

        $policy = app(PhotoPolicy::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($policy->view($user, $photo));
        $this->assertTrue($policy->update($user, $photo));
        $this->assertTrue($policy->delete($user, $photo));

        $seriesQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(function (string $query): bool {
                $normalized = strtolower($query);
                return str_contains($normalized, 'from `series`')
                    || str_contains($normalized, 'from "series"')
                    || str_contains($normalized, 'from series');
            })
            ->values();

        $this->assertCount(1, $seriesQueries);
    }

    public function test_policy_runs_without_series_query_when_relation_is_preloaded(): void
    {
        $user = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $user->id,
            'title' => 'Preloaded',
            'description' => 'Policy',
        ]);
        $photo = Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/'.$series->id.'/b.jpg',
            'original_name' => 'b.jpg',
        ]);
        $photo->load('series');

        $policy = app(PhotoPolicy::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($policy->view($user, $photo));
        $this->assertTrue($policy->update($user, $photo));
        $this->assertTrue($policy->delete($user, $photo));

        $seriesQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(function (string $query): bool {
                $normalized = strtolower($query);
                return str_contains($normalized, 'from `series`')
                    || str_contains($normalized, 'from "series"')
                    || str_contains($normalized, 'from series');
            })
            ->values();

        $this->assertCount(0, $seriesQueries);
    }
}
