<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['series_id', 'tag_id']);
        });

        $rows = DB::table('photo_tag')
            ->join('photos', 'photos.id', '=', 'photo_tag.photo_id')
            ->select('photos.series_id', 'photo_tag.tag_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            DB::table('series_tag')->insertOrIgnore([
                'series_id' => $row->series_id,
                'tag_id' => $row->tag_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('series_tag');
    }
};
