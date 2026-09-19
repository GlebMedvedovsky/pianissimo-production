<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('admin_theme')->default('dark'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('media_assets', function (Blueprint $t) {
            $t->string('id', 100)->primary(); $t->string('path')->unique(); $t->string('type');
            $t->string('purpose')->default('other'); $t->text('alt'); $t->text('caption')->nullable();
            $t->text('credit')->nullable(); $t->text('license')->nullable(); $t->text('source')->nullable();
            $t->string('state')->default('temporary'); $t->unsignedInteger('width')->nullable(); $t->unsignedInteger('height')->nullable();
            $t->double('duration')->nullable(); $t->json('crop'); $t->string('sha256', 64)->nullable();
            $t->string('rights_scope')->default('unconfirmed'); $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->string('id', 100)->primary(); $t->string('slug')->unique(); $t->string('title');
            $t->text('synopsis'); $t->string('status')->default('draft'); $t->integer('sort_order')->default(0);
            $t->string('production_type')->default(''); $t->string('year')->nullable();
            foreach (['credits','categories','awards','platform_links','genres','emotions','seo','external_links'] as $field) $t->json($field)->nullable();
            $t->json('external_film')->nullable(); $t->string('year_status')->nullable(); $t->string('order_status')->nullable();
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id(); $t->string('project_id', 100); $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->string('direction'); $t->string('role'); $t->integer('sort_order')->default(0);
            $t->string('cover_asset_id', 100); $t->foreign('cover_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $t->string('hover_asset_id', 100)->nullable(); $t->foreign('hover_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $t->json('crop'); $t->string('fit')->nullable(); $t->unique(['project_id','direction']); $t->timestamps();
        });
        Schema::create('project_media', function (Blueprint $t) {
            $t->id(); $t->string('project_id', 100); $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->string('media_asset_id', 100); $t->foreign('media_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $t->string('slot'); $t->integer('sort_order')->default(0); $t->timestamps();
        });
        Schema::create('tracks', function (Blueprint $t) {
            $t->string('id', 100)->primary(); $t->string('title');
            $t->string('audio_asset_id', 100)->nullable(); $t->foreign('audio_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $t->double('duration')->nullable(); $t->json('peaks')->nullable();
            foreach (['genres','emotions','platform_overrides'] as $field) $t->json($field);
            $t->string('status')->default('draft'); $t->string('rights_scope')->default('unconfirmed');
            $t->string('audio_sha256',64)->nullable(); $t->string('waveform_sha256',64)->nullable(); $t->string('tag_source')->nullable(); $t->timestamps();
        });
        Schema::create('project_tracks', function (Blueprint $t) {
            $t->id(); $t->string('project_id',100); $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->string('track_id',100); $t->foreign('track_id')->references('id')->on('tracks')->restrictOnDelete();
            $t->integer('sort_order')->default(0); $t->unique(['project_id','track_id']); $t->timestamps();
        });
        Schema::create('cv_entries', function (Blueprint $t) {
            $t->string('id',100)->primary(); $t->string('date'); $t->text('title'); $t->string('institution')->nullable();
            $t->text('description')->nullable(); $t->text('link')->nullable(); $t->integer('sort_order')->default(0);
            $t->string('status')->default('draft'); $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('id',100)->primary(); $t->json('data'); $t->timestamps();
        });
        Schema::create('external_services', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('provider'); $t->string('category');
            $t->text('purpose'); $t->text('description')->nullable(); $t->text('privacy_url'); $t->text('storage_description')->nullable();
            $t->boolean('requires_consent')->default(true); $t->boolean('enabled')->default(false);
            $t->string('provider_id')->nullable(); $t->integer('sort_order')->default(0); $t->timestamps();
        });
        Schema::create('import_records', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('input_hash',64); $t->string('record_hash',64); $t->timestamps();
        });
        Schema::create('publications', function (Blueprint $t) {
            $t->id(); $t->string('hash',64); $t->string('path'); $t->string('status'); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->json('summary'); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['publications','import_records','external_services','settings','cv_entries','project_tracks','tracks','project_media','memberships','projects','media_assets','users'] as $table) Schema::dropIfExists($table);
    }
};
