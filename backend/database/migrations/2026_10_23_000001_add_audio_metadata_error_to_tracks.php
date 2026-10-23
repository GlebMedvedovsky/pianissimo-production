<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audio_analysis_jobs', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('media_asset_id', 100);
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $table->string('expected_audio_sha256', 64);
            $table->string('status', 20);
            $table->string('failure_code', 64)->nullable();
            $table->double('duration')->nullable();
            $table->json('peaks')->nullable();
            $table->string('waveform_sha256', 64)->nullable();
            $table->string('metadata_integrity_sha256', 64)->nullable();
            $table->timestamps();
            $table->index(['media_asset_id', 'expected_audio_sha256', 'status'], 'audio_analysis_asset_sha_status');
        });
        Schema::table('tracks', function (Blueprint $table): void {
            $table->text('audio_metadata_error')->nullable()->after('waveform_sha256');
            $table->string('audio_metadata_status', 20)->default('missing')->after('audio_metadata_error');
            $table->string('audio_analysis_job_id', 32)->nullable()->after('audio_metadata_status');
            $table->string('audio_metadata_integrity_sha256', 64)->nullable()->after('audio_analysis_job_id');
            $table->foreign('audio_analysis_job_id')->references('id')->on('audio_analysis_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropForeign(['audio_analysis_job_id']);
            $table->dropColumn(['audio_metadata_error', 'audio_metadata_status', 'audio_analysis_job_id', 'audio_metadata_integrity_sha256']);
        });
        Schema::dropIfExists('audio_analysis_jobs');
    }
};
