<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->string('publication_status', 40)->default('draft')->after('is_public');
            $table->string('moderation_status', 40)->default('pending')->after('publication_status');
            $table->string('moderation_reason', 500)->nullable()->after('moderation_status');
            $table->json('moderation_labels')->nullable()->after('moderation_reason');
            $table->timestamp('publication_requested_at')->nullable()->after('moderation_labels');
            $table->timestamp('moderated_at')->nullable()->after('publication_requested_at');
            $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();

            $table->index('publication_status');
            $table->index('moderation_status');
        });

        DB::table('series')
            ->where('is_public', true)
            ->update([
                'publication_status' => 'published',
                'moderation_status' => 'approved',
                'moderated_at' => now(),
            ]);

        DB::table('series')
            ->where('is_public', false)
            ->update([
                'publication_status' => 'draft',
                'moderation_status' => 'approved',
                'moderated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropIndex(['publication_status']);
            $table->dropIndex(['moderation_status']);
            $table->dropColumn([
                'publication_status',
                'moderation_status',
                'moderation_reason',
                'moderation_labels',
                'publication_requested_at',
                'moderated_at',
            ]);
        });
    }
};
