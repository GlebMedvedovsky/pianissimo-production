<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Project,Track,Setting};
use App\Services\{ManifestImporter,ManifestExporter};
class Stage04ATest extends TestCase
{
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();app(ManifestImporter::class)->import(json_decode(file_get_contents(config('cms.manifest')),true));}
 public function test_forwarded_https_uses_only_the_specific_proxy_and_allowed_host():void {
  $this->withServerVariables(['REMOTE_ADDR'=>'172.21.0.1','HTTP_HOST'=>'localhost:8080'])->withHeaders(['X-Forwarded-Host'=>'1nx12grf-8080.euw.devtunnels.ms','X-Forwarded-Proto'=>'https','X-Forwarded-Port'=>'443'])->get('/admin')->assertRedirect('https://1nx12grf-8080.euw.devtunnels.ms/admin/login');
 }
 public function test_untrusted_sender_cannot_change_scheme_or_redirect_host():void {
  $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.10'])->withHeaders(['X-Forwarded-Host'=>'attacker.invalid','X-Forwarded-Proto'=>'https','X-Forwarded-Port'=>'443'])->get('http://localhost:8080/admin')->assertRedirect('http://localhost:8080/admin/login');
 }
 public function test_unknown_forwarded_host_is_rejected_even_from_the_local_proxy():void {
  $this->withServerVariables(['REMOTE_ADDR'=>'172.21.0.1'])->withHeaders(['X-Forwarded-Host'=>'attacker.invalid','X-Forwarded-Proto'=>'https'])->get('/admin')->assertStatus(400);
 }
 public function test_standalone_library_opt_in_exports_once_without_a_fake_project():void {
  $t=Track::findOrFail('hole-in-that-dough')->replicate();$t->id='synthetic-library';$t->library_visible=true;$t->audio_asset_id=null;$t->save();
  $p=app(ManifestExporter::class)->package()['manifest'];$row=collect($p['tracks'])->firstWhere('id',$t->id);
  $this->assertTrue($row['libraryVisible']);$this->assertCount(1,array_filter($p['tracks'],fn($r)=>$r['id']===$t->id));
  foreach($p['projects'] as $r)$this->assertNotContains($t->id,array_column($r['tracks'],'trackId'));
  $t->update(['status'=>'draft']);$this->assertNotContains($t->id,array_column(app(ManifestExporter::class)->package()['manifest']['tracks'],'id'));
 }
 public function test_pending_track_order_blocks_a_visible_project_until_explicitly_resolved():void {
  $p=Project::findOrFail('silver-strings');$p->update(['soundtrack_order_pending'=>true]);$p->soundtrack()->first()->update(['sort_order'=>null]);
  $this->expectException(\DomainException::class);$this->expectExceptionMessage('Reihenfolge');app(ManifestExporter::class)->package();
 }
 public function test_legal_review_draft_is_not_public_or_in_companion():void {
  foreach(['impressum','privacy'] as $key)Setting::put($key,[...Setting::read($key),'reviewDraft'=>'SYNTHETIC_PRIVATE_LEGAL_REVIEW','status'=>'draft']);
  $p=app(ManifestExporter::class)->package();$this->assertStringNotContainsString('SYNTHETIC_PRIVATE_LEGAL_REVIEW',json_encode($p));$this->assertFalse($p['manifest']['site']['legal']['approved']);
 }
 public function test_letterboxd_rejects_video_even_if_other_metadata_is_valid():void {
  $m=json_decode(file_get_contents(config('cms.manifest')),true);
  foreach($m['projects'] as &$p)if($p['id']==='kinnhakenhank'){$p['id']='letterboxd-everything-everywhere-all-at-once';break;}unset($p);
  $this->expectException(\DomainException::class);$this->expectExceptionMessage('Letterboxd');app(\App\Services\ContentValidator::class)->validate($m,false,false);
 }
 public function test_authenticated_media_response_is_private_and_never_cacheable():void {
  $asset=\App\Models\MediaAsset::where('type','image')->firstOrFail();
  $this->get('/admin-media/'.$asset->id)->assertRedirect('/admin/login');
  $user=\App\Models\User::create(['name'=>'Synthetic QA','email'=>'media-04a@example.test','password'=>bin2hex(random_bytes(20))]);
  $response=$this->actingAs($user)->get('/admin-media/'.$asset->id)->assertOk();
  $this->assertTrue($response->headers->hasCacheControlDirective('private'));
  $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  $this->assertFalse($response->headers->hasCacheControlDirective('public'));
 }
}
