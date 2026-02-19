<?php

use App\Models\Series;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('title');
            $table->unique('slug');
        });

        Series::query()
            ->select(['id', 'title', 'slug'])
            ->orderBy('id')
            ->chunkById(200, function ($seriesBatch): void {
                foreach ($seriesBatch as $series) {
                    $series->ensureSlug();
                }
            });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->dropUnique('series_slug_unique');
            $table->dropColumn('slug');
        });
    }
};
