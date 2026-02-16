<?php

namespace Database\Seeders;

use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class FrontendDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => 'admin12345']
        );

        $series = Series::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'title' => 'City Lights',
            ],
            [
                'description' => 'Evening walk in downtown',
            ]
        );

        $photo = Photo::query()->firstOrCreate(
            [
                'series_id' => $series->id,
                'path' => 'photos/series/demo/city-lights-1.jpg',
            ],
            [
                'original_name' => 'city-lights-1.jpg',
                'size' => 140000,
                'mime' => 'image/jpeg',
            ]
        );

        $tags = collect(['night', 'street', 'city'])->map(
            fn (string $name) => Tag::query()->firstOrCreate(['name' => $name])
        );

        $photo->tags()->syncWithoutDetaching($tags->pluck('id')->all());
    }
}
