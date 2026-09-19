import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {createWorkflow,hash,json,diff} from '../../scripts/lib/cms-frontend.mjs';
const root=path.resolve(import.meta.dirname,'../..');
const baseline=fs.readFileSync(path.join(root,'docs/qa/03B/before/manifest.json'),'utf8');
const source=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/exports/candidates/b38fa52d86687779407be9b586f12399ae6c0994c6cab2b9b4dfbb7b11e85f2b/manifest.json')));
let serial=0;
async function fixture(mutate=()=>{},options={}) {
  const dir=path.join(root,'docs/qa/03B/fixtures','run-'+process.pid+'-'+(++serial));fs.mkdirSync(dir,{recursive:true});
  const active=path.join(dir,'manifest.json');fs.writeFileSync(active,baseline);
  const next=structuredClone(source); mutate(next);
  const id=hash(json(next)), candidates=path.join(dir,'candidates'),folder=path.join(candidates,id);fs.mkdirSync(folder,{recursive:true});
  fs.writeFileSync(path.join(folder,'manifest.json'),json(next));
  const settings=json({scope:'next_draft',deploymentAuthorized:false});fs.writeFileSync(path.join(folder,'cms-settings.json'),settings);
  fs.writeFileSync(path.join(folder,'receipt.json'),json({version:1,candidateHash:id,adminUserId:99,manifestSha256:hash(json(next)),settingsSha256:hash(settings)}));
  const config={active,candidates,state:path.join(dir,'state'),...options};
  return {flow:await createWorkflow(root,config),active,id,folder,config,dir};
}
const marker=m=>m.projects.find(p=>p.id==='abszission').synopsis+='\n\n[03B synthetic reversible check]';
const unchanged=f=>assert.equal(fs.readFileSync(f.active,'utf8'),baseline);
test('existing exporter has zero public diff; private registry and byte formatting preserved',async()=>{
  const f=await fixture();const p=f.flow.review(f.id);assert.deepEqual(p.changes,[]);unchanged(f);
  assert.equal(f.flow.apply(p.planHash,p.planHash).status,'already-current');unchanged(f);
});
test('review validates and shows exactly one stable-ID synopsis change, without activating it',async()=>{
  const f=await fixture(marker);const p=f.flow.review(f.id);assert.equal(p.changes.length,1);assert.equal(p.changes[0].path,'/projects/abszission/synopsis');unchanged(f);
});
test('confirmation required; backup + atomic apply + repeated no-op + byte-exact rollback',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id);assert.throws(()=>f.flow.apply(p.planHash,''),/confirmation/);unchanged(f);
  const result=f.flow.apply(p.planHash,p.planHash);assert.equal(result.status,'success');assert.equal(fs.readFileSync(result.backup,'utf8'),baseline);
  const updated=JSON.parse(fs.readFileSync(f.active));const original=JSON.parse(baseline);
  assert.match(updated.projects.find(p=>p.id==='abszission').synopsis,/03B synthetic/);
  for(const key of ['projects','tracks','assets'])assert.deepEqual(updated[key].map(x=>x.id),original[key].map(x=>x.id));
  assert.deepEqual(updated.sources,original.sources);assert.deepEqual(updated.assets,original.assets);
  assert.equal(f.flow.apply(p.planHash,p.planHash).status,'already-current');
  const rollback=f.flow.rollbackReview();assert.throws(()=>f.flow.rollback(''),/confirmation/);f.flow.rollback(rollback.planHash);unchanged(f);
  assert.match(fs.readFileSync(path.join(f.folder,'manifest.json'),'utf8'),/03B synthetic/);
  const history=fs.readFileSync(path.join(f.flow.state,'history.jsonl'),'utf8');assert.doesNotMatch(history,/synopsis|password|sourceIds|03B synthetic/);
});
test('invalid schema and unknown private keys cannot enter candidate',async()=>{
  for(const mutate of [m=>m.projects[0].title='',m=>m.password='SYNTHETIC_SECRET',m=>m.projects[0].adminOnly='SYNTHETIC_SECRET']) {
    const f=await fixture(mutate);assert.throws(()=>f.flow.review(f.id));unchanged(f);
  }
});
test('private provenance is rejected even where schema permits a string',async()=>{
  const f=await fixture(m=>m.assets[0].source='SYNTHETIC_PRIVATE_SOURCE');assert.throws(()=>f.flow.review(f.id),/Private/);unchanged(f);
});
test('large unexpected diff and identity changes are blocked without a force option',async()=>{
  for(const mutate of [m=>m.projects.forEach(p=>{p.title+=' QA';p.synopsis+=' QA'}),m=>{m.projects[0].id='synthetic-renamed-id';},m=>{m.projects[0].synopsis='Synthetic '.repeat(5000);}]) {
    const f=await fixture(mutate);assert.throws(()=>f.flow.review(f.id),/bulk\/identity/);unchanged(f);
  }
});

test('exact reviewed batch permits a structural addition through the same backup and rollback workflow',async()=>{
 const f=await fixture(m=>{const p=structuredClone(m.projects[0]);p.id=p.slug='synthetic-04a-project';p.title='Synthetic batch project';m.projects.push(p)});
 assert.throws(()=>f.flow.review(f.id),/bulk\/identity/);
 const next=JSON.parse(fs.readFileSync(path.join(f.folder,'manifest.json')));
 const expected={version:1,candidateHash:f.id,previousManifestHash:hash(baseline),changes:diff(f.flow.comparable(JSON.parse(baseline)),f.flow.comparable(next))};
 const file=path.join(f.dir,'expected.json');fs.writeFileSync(file,json(expected));
 const p=f.flow.review(f.id,file);unchanged(f);assert(p.expectedBatchHash);
 f.flow.apply(p.planHash,p.planHash);assert(JSON.parse(fs.readFileSync(f.active)).projects.some(p=>p.id==='synthetic-04a-project'));
 assert.equal(f.flow.apply(p.planHash,p.planHash).status,'already-current');
 f.flow.rollback(f.flow.rollbackReview().planHash);unchanged(f);
});
test('an expected batch cannot approve different values, another candidate, or a modified approval snapshot',async()=>{
 const f=await fixture(marker);const next=JSON.parse(fs.readFileSync(path.join(f.folder,'manifest.json')));
 const expected={version:1,candidateHash:f.id,previousManifestHash:hash(baseline),changes:diff(f.flow.comparable(JSON.parse(baseline)),f.flow.comparable(next))};
 const file=path.join(f.dir,'expected.json');
 fs.writeFileSync(file,json({...expected,changes:[]}));assert.throws(()=>f.flow.review(f.id,file),/expected batch/);unchanged(f);
 fs.writeFileSync(file,json({...expected,candidateHash:'f'.repeat(64)}));assert.throws(()=>f.flow.review(f.id,file),/another candidate/);unchanged(f);
 fs.writeFileSync(file,json(expected));const p=f.flow.review(f.id,file);
 fs.writeFileSync(path.join(f.flow.state,'expected',p.expectedBatchHash+'.json'),json({...expected,changes:[]}));
 assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/modified/);unchanged(f);
});
test('candidate file tampering after review is rejected',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id);fs.appendFileSync(path.join(f.folder,'manifest.json'),' ');
  assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/receipt/);unchanged(f);
});
test('stale review cannot overwrite another manifest change',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id);fs.appendFileSync(f.active,'\n');const changed=fs.readFileSync(f.active,'utf8');
  assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/changed/);assert.equal(fs.readFileSync(f.active,'utf8'),changed);
});
test('failed replacement preserves active and a valid backup',async()=>{
  const f=await fixture(marker,{beforeReplace:()=>{throw Error('Synthetic interrupted apply');}}),p=f.flow.review(f.id);
  assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/interrupted/);unchanged(f);
  assert.equal(fs.readFileSync(path.join(f.flow.state,'backups',hash(baseline)+'.json'),'utf8'),baseline);
  assert.equal(fs.readdirSync(f.dir).filter(n=>n.endsWith('.tmp')).length,0);
  assert.match(fs.readFileSync(path.join(f.flow.state,'history.jsonl'),'utf8'),/failure/);
});
test('actual child-process termination before rename leaves complete active and recovery journal',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id);
  const script=`import {createWorkflow} from ${JSON.stringify(new URL('../../scripts/lib/cms-frontend.mjs',import.meta.url).href)};const f=await createWorkflow(${JSON.stringify(root)},{...${JSON.stringify(f.config)},beforeReplace:()=>process.exit(77)});f.apply('${p.planHash}','${p.planHash}');`;
  const run=spawnSync(process.execPath,['--input-type=module','-e',script],{encoding:'utf8'});assert.equal(run.status,77);unchanged(f);
  assert(fs.existsSync(path.join(f.flow.state,'operation.lock')));assert.equal(fs.readdirSync(path.join(f.flow.state,'revisions')).length,1);
  assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/interrupted process/);
});
test('corrupt backup and post-apply external changes block rollback',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id),r=f.flow.apply(p.planHash,p.planHash);
  fs.appendFileSync(r.backup,' ');assert.throws(()=>f.flow.rollbackReview(),/checksum/);
  fs.appendFileSync(f.active,' ');assert.throws(()=>f.flow.rollbackReview(),/No applied revision/);
});
test('termination immediately after atomic rename leaves valid new manifest and recoverable backup',async()=>{
  const f=await fixture(marker),p=f.flow.review(f.id);
  const script=`import fs from 'node:fs';import {createWorkflow} from ${JSON.stringify(new URL('../../scripts/lib/cms-frontend.mjs',import.meta.url).href)};const f=await createWorkflow(${JSON.stringify(root)},{...${JSON.stringify(f.config)},beforeReplace:()=>{const rename=fs.renameSync;fs.renameSync=(...args)=>{rename(...args);process.exit(78);};}});f.apply('${p.planHash}','${p.planHash}');`;
  const run=spawnSync(process.execPath,['--input-type=module','-e',script],{encoding:'utf8'});assert.equal(run.status,78);
  f.flow.validate(JSON.parse(fs.readFileSync(f.active)));assert.equal(hash(fs.readFileSync(f.active)),p.resultingManifestHash);
  // Child is confirmed exited; remove ONLY this isolated fixture's stale lock.
  fs.unlinkSync(path.join(f.flow.state,'operation.lock'));
  const r=f.flow.rollbackReview();f.flow.rollback(r.planHash);unchanged(f);
});
test('missing candidate and modified review plan cannot apply',async()=>{
  const f=await fixture(marker);assert.throws(()=>f.flow.review('0'.repeat(64)));const p=f.flow.review(f.id);
  const file=path.join(f.flow.state,'plans',p.planHash+'.json'),v=JSON.parse(fs.readFileSync(file));v.adminUserId=100;fs.writeFileSync(file,json(v));
  assert.throws(()=>f.flow.apply(p.planHash,p.planHash),/modified/);unchanged(f);
});
test('media references/checksums, selected V2 and production gate remain enforced',async()=>{
  for(const mutate of [m=>m.assets[0].sha256='0'.repeat(64),m=>m.projects[0].memberships.music.coverAssetId='missing',m=>m.projects.find(p=>p.id==='kinnhakenhank').localTrailerAssetId=m.site.mediaSlots.hero.video,m=>m.mode='release']) {
    const f=await fixture(mutate);assert.throws(()=>f.flow.review(f.id));unchanged(f);
  }
});
