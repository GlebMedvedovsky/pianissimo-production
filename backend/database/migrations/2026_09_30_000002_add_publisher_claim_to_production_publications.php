<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->string('claimed_by', 128)->nullable()->index()->after('status');
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');
            $table->char('release_sha256', 64)->nullable()->after('manifest_sha256');
            $table->timestamp('built_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->dropIndex(['claimed_by']);
            $table->dropColumn(['claimed_by', 'claimed_at', 'release_sha256', 'built_at']);
        });
    }
};
