<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->dropUnique('production_publications_logical_identity');
            $table->unsignedInteger('attempt')->default(1)->after('source_revision');
            $table->unique(['plan_hash', 'source_revision', 'attempt'], 'production_publications_logical_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->dropUnique('production_publications_logical_attempt');
            $table->dropColumn('attempt');
            $table->unique(['plan_hash', 'source_revision'], 'production_publications_logical_identity');
        });
    }
};
