<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->char('release_metadata_sha256', 64)->nullable()->after('release_files_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('production_publications', function (Blueprint $table): void {
            $table->dropColumn('release_metadata_sha256');
        });
    }
};
