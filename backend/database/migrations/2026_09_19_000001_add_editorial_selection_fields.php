<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('tracks',function(Blueprint $t){$t->boolean('library_visible')->default(false);});
        Schema::table('projects',function(Blueprint $t){$t->text('editorial_notes')->nullable();$t->string('presentation_status')->nullable();$t->boolean('soundtrack_order_pending')->default(false);});
        Schema::table('project_tracks',function(Blueprint $t){$t->integer('sort_order')->nullable()->default(null)->change();});
        Schema::table('cv_entries',function(Blueprint $t){$t->string('link_text')->nullable();});
    }
    public function down(): void {
        Schema::table('cv_entries',fn(Blueprint $t)=>$t->dropColumn('link_text'));
        Schema::table('projects',fn(Blueprint $t)=>$t->dropColumn(['editorial_notes','presentation_status','soundtrack_order_pending']));
        Schema::table('tracks',fn(Blueprint $t)=>$t->dropColumn('library_visible'));
        // A pending sequence cannot safely be replaced with an invented order.
    }
};
