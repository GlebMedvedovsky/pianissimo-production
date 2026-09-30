<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_publication_counters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_sequence');
        });
        DB::table('production_publication_counters')->insert(['id' => 1, 'next_sequence' => 1]);

        Schema::create('production_publications', function (Blueprint $table) {
            $table->id();
            $table->uuid('publication_id')->unique();
            $table->unsignedBigInteger('sequence')->unique();
            $table->char('candidate_hash', 64);
            $table->char('plan_hash', 64);
            $table->char('source_revision', 40);
            $table->char('package_sha256', 64);
            $table->char('manifest_sha256', 64);
            $table->string('package_path')->unique();
            $table->string('status', 32)->index();
            // Nullable unique slot: only the active row owns value 1; all other rows are NULL.
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            // One reviewed plan at one exact source revision claims one immutable payload.
            $table->unique(['plan_hash', 'source_revision'], 'production_publications_logical_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_publications');
        Schema::dropIfExists('production_publication_counters');
    }
};
