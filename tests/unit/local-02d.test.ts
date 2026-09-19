import test from 'node:test';import assert from 'node:assert/strict';
import raw from '../../content/manifest.json';import {validateManifest} from '../../contracts/content';import {publicContent} from '../../contracts/public-content';import {selectTracks,selectProjects} from '../../frontend/src/lib/content/selectors';
// @ts-expect-error Pure JS merge shared with the importer.
import {mergeDraft,excludeBlockedMedia} from '../../scripts/local-import-merge.mjs';
test('04A source selection preserves 02D identities, distinct memberships and inherited versus individual tags',()=>{
 const d=validateManifest(raw);assert.equal(selectProjects(d,'music','All').length,13);assert.equal(selectProjects(d,'directing','All').length,5);assert.equal(d.tracks.filter(t=>t.audioAssetId).length,15);
 assert.equal(d.projects.find(p=>p.id==='kinnhakenhank')!.externalFilm,null);assert.match(d.projects.find(p=>p.id==='kinnhakenhank')!.localTrailerAssetId!,/87d401/);
 assert.equal(d.tracks.find(t=>t.id==='au-milieu-song')!.audioAssetId,'local-f-5c334c2b28600906');assert.deepEqual(d.tracks.find(t=>t.id==='au-milieu-song')!.genres,['Jazz','Songs']);
 assert.match(d.tracks.find(t=>t.id==='lillys-lullaby-track')!.audioAssetId!,/a7bd4d/);assert.equal(d.tracks.filter(t=>t.id.startsWith('lilly')).length,1);
 const ordered=selectTracks(d).filter(t=>t.id.startsWith('silver-strings'));assert.deepEqual(ordered.map(t=>t.title),['Silver Strings','First Spider Encounter','Spider Swing','Tunnel of Darkness','Good Times']);assert.deepEqual(ordered[2]!.genres,['Jazz','Songs']);assert.ok(selectTracks(d,'Orchestral','calm').some(t=>t.id==='lillys-lullaby-track'));
 for(const t of d.tracks.filter(t=>t.audioAssetId)){const a=d.assets.find(a=>a.id===t.audioAssetId)!;assert.equal(t.audioSha256,a.sha256);assert.ok(t.waveformSha256);assert.equal(t.peaks?.length,180)}
 const runtime=publicContent(d);assert.ok(runtime.assets.some(a=>a.id===d.site.mediaSlots.hero.video));assert.ok(runtime.assets.some(a=>a.id===d.projects.find(p=>p.id==='kinnhakenhank')!.localTrailerAssetId));assert.doesNotMatch(JSON.stringify(runtime),/eyXBrA1LUsI|FINAL_KHH|secret_token|admin_email|entity_address|Pianissimo_Fragen|D:\\\\/);
});
test('local import is idempotent and preserves only conflicting manual fields',()=>{
 const base={projects:[{id:'one',title:'Old',year:null}]},next={projects:[{id:'one',title:'New',year:'2026'},{id:'two',title:'Added',year:null}]};
 const first=mergeDraft(base,next,base);assert.deepEqual(first.value,next);const again=mergeDraft(base,next,first.value);assert.equal(again.changes.filter((x:{status:string})=>x.status==='applied').length,0);assert.equal(again.conflicts.length,0);
 const edited={projects:[{id:'one',title:'Manual',year:null}]};const merged=mergeDraft(base,next,edited);assert.equal(merged.conflicts.length,1);assert.equal(merged.value.projects[0].title,'Manual');assert.equal(merged.value.projects[0].year,'2026');assert.equal(merged.value.projects.length,2);
});

test('conflicting media blocks its dependent fields, not an independent contact change',()=>{
 const base=validateManifest(raw),desired=structuredClone(base);const hero=desired.site.mediaSlots.hero.video!;const old=base.site.mediaSlots.hero;desired.site.contacts.email='fixture@example.invalid';desired.site.mediaSlots.about.image=hero;
 const projected=excludeBlockedMedia(desired,base,[hero]);assert.equal(projected.site.contacts.email,'fixture@example.invalid');assert.equal(projected.site.mediaSlots.hero.video,old.video);assert.equal(projected.site.mediaSlots.about.image,base.site.mediaSlots.about.image);
});
