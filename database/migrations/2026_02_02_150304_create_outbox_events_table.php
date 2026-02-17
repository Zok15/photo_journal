<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();

            $table->string('type');                 // например: series.uploaded
            $table->json('payload');                // данные события
            $table->string('status')->default('pending'); // pending|processing|done|failed

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable(); // для backoff
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
