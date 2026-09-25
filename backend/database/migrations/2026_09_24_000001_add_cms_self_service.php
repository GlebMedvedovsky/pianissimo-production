<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $t) {
            $t->string('storage_path')->nullable()->after('path');
            $t->string('original_name')->nullable()->after('storage_path');
            $t->string('mime_type')->nullable()->after('original_name');
            $t->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $t->softDeletes();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('object_type', 80);
            $t->string('object_id', 100)->nullable();
            $t->string('action', 80);
            $t->string('result_hash', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['object_type','object_id']);
        });

        $row=DB::table('settings')->where('id','social')->first();
        if ($row) {
            $data=json_decode($row->data,true) ?: [];
            if (!isset($data['links'])) {
                $labels=['imdb'=>'IMDb','crewUnited'=>'Crew United','musicHub'=>'Music Hub','spotify'=>'Spotify','tidal'=>'Tidal','appleMusic'=>'Apple Music','deezer'=>'Deezer'];
                $providers=['crewUnited'=>'crew-united','musicHub'=>'music-hub','appleMusic'=>'apple-music'];
                $links=[]; $order=0;
                foreach (['profileLinks','artistLinks'] as $group) foreach (($data[$group]??[]) as $provider=>$url) if ($url) {
                    $links[]=['provider'=>$providers[$provider]??$provider,'label'=>$labels[$provider]??$provider,'url'=>$url,'visible'=>true,'sort_order'=>$order++];
                }
                $data['links']=$links;
                DB::table('settings')->where('id','social')->update(['data'=>json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::table('media_assets', function (Blueprint $t) {
            $t->dropSoftDeletes();
            $t->dropColumn(['storage_path','original_name','mime_type','file_size']);
        });
    }
};
