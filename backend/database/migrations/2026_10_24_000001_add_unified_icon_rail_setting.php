<?php
use App\Services\IconRail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('settings')->where('id','icon-rail')->exists()) return;
        if (!DB::table('settings')->whereIn('id',['contact','social'])->exists()) return;
        $read=fn(string $id)=>(array)(json_decode(DB::table('settings')->where('id',$id)->value('data')??'[]',true)?:[]);
        $contact=$read('contact');$social=$read('social');
        $site=['contacts'=>$contact,'artistLinks'=>$social['artistLinks']??[],'profileLinks'=>$social['profileLinks']??[],'socialLinks'=>$social['links']??[]];
        DB::table('settings')->insert(['id'=>'icon-rail','data'=>json_encode(IconRail::fromManifestSite($site),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { DB::table('settings')->where('id','icon-rail')->delete(); }
};
