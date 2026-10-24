<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('cover_asset_id', 100)->nullable()->after('title');
            $table->foreign('cover_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropForeign(['cover_asset_id']);
            $table->dropColumn('cover_asset_id');
        });
    }
};
