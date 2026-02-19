<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('series_tag', function (Blueprint $table): void {
            $table->string('source', 16)->default('manual')->after('tag_id');
            $table->index(['series_id', 'source']);
        });

        DB::table('series_tag')
            ->whereNull('source')
            ->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('series_tag', function (Blueprint $table): void {
            $table->dropIndex('series_tag_series_id_source_index');
            $table->dropColumn('source');
        });
    }
};
