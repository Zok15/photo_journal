<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('photo_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('photo_id')->unique()->constrained()->cascadeOnDelete();
            $table->dateTime('taken_at')->nullable()->index();
            $table->string('camera_make')->nullable();
            $table->string('camera_model')->nullable();
            $table->string('lens_model')->nullable();
            $table->unsignedInteger('iso')->nullable();
            $table->string('exposure_time', 64)->nullable();
            $table->decimal('aperture', 6, 2)->nullable();
            $table->decimal('focal_length_mm', 7, 2)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->json('raw_exif_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_metadata');
    }
};

