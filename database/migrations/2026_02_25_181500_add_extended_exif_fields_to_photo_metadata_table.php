<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('photo_metadata', function (Blueprint $table): void {
            $table->unsignedSmallInteger('orientation')->nullable()->after('height');
            $table->boolean('flash_fired')->nullable()->after('altitude_m');
            $table->string('white_balance_mode', 32)->nullable()->after('flash_fired');
            $table->string('color_space', 32)->nullable()->after('white_balance_mode');
            $table->unsignedBigInteger('source_file_size')->nullable()->after('color_space');
        });
    }

    public function down(): void
    {
        Schema::table('photo_metadata', function (Blueprint $table): void {
            $table->dropColumn([
                'orientation',
                'flash_fired',
                'white_balance_mode',
                'color_space',
                'source_file_size',
            ]);
        });
    }
};
