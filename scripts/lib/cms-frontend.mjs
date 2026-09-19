import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {build} from 'esbuild';

export const hash = value => crypto.createHash('sha256').update(value).digest('hex');
export const json = value => JSON.stringify(value, null, 2) + '\n';
const stable = value => JSON.stringify(value, (_, v) => v && !Array.isArray(v) && typeof v === 'object' ? Object.fromEntries(Object.entries(v).sort(([a],[b]) => a.localeCompare(b))) : v);
const equal = (a,b) => stable(a) === stable(b);
const read = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const hex = value => { if (!/^[a-f0-9]{64}$/.test(value || '')) throw Error('Expected a full SHA-256 identifier'); return value; };
const writeNew = (file, bytes) => {
  fs.mkdirSync(path.dirname(file), {recursive:true});
  if (fs.existsSync(file)) { assert.equal(fs.readFileSync(file, 'utf8'), bytes, 'Immutable local snapshot differs'); return; }
  const fd=fs.openSync(file,'wx',0o600); try { fs.writeFileSync(fd,bytes); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
};

// Differences are keyed by stable ID. Array order within a project/playlist is significant.
export function diff(a,b,prefix='') {
  if (equal(a,b)) return [];
  if (a && b && !Array.isArray(a) && !Array.isArray(b) && typeof a==='object' && typeof b==='object')
    return [...new Set([...Object.keys(a),...Object.keys(b)])].sort().flatMap(k=>diff(a[k],b[k],prefix+'/'+k));
  return [{path:prefix,before:a ?? null,after:b ?? null}];
}

export async function createWorkflow(root, options={}) {
  const result=await build({stdin:{contents:"export {validateManifest} from './contracts/content.ts'; export {publicContent} from './contracts/public-content.ts';",resolveDir:root},bundle:true,platform:'node',format:'esm',write:false});
  const {validateManifest,publicContent}=await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
  const active=options.active || path.join(root,'content/manifest.json');
  const state=options.state || path.join(root,'backend/storage/app/private/frontend-apply');
  const candidates=options.candidates || path.join(root,'backend/storage/app/private/exports/candidates');
  const media=path.join(root,'frontend/public');
  fs.mkdirSync(state,{recursive:true});
  function validate(m, publicOnly=false) {
    const parsed=validateManifest(m);
    if (!equal(parsed,m)) throw Error('Unknown fields or non-canonical schema data');
    if(m.mode!=='prototype') throw Error('Only the existing prototype workflow is authorised');
    const kinn=m.projects.find(p=>p.id==='kinnhakenhank');
    if(kinn?.localTrailerAssetId && kinn.localTrailerAssetId!=='local-f-87d401c4a58387a5') throw Error('Only selected Kinnhakenhank V2 is allowed');
    for(const a of m.assets) {
      const file=fs.realpathSync(path.join(media,a.path));
      if(!file.startsWith(fs.realpathSync(path.join(media,'media'))+path.sep)) throw Error('Media outside approved web directory');
      if(a.sha256 && hash(fs.readFileSync(file))!==a.sha256) throw Error('Web media checksum differs: '+a.id);
    }
    if(publicOnly) {
      if(m.sources.length || m.projects.some(p=>p.sourceIds.length)) throw Error('Private source registry in candidate');
      for(const a of m.assets) if(a.sourceSha256 || a.origin!=='Curated local derivative' || !['Provenance is recorded in the private CMS registry.','Provenance is recorded in the project content registry.'].includes(a.source)) throw Error('Private asset provenance in candidate');
    }
    return m;
  }
  function comparable(m) {
    const v=publicContent(m);
    v.sources=[];
    v.projects=v.projects.map(p=>({...p,externalLinks:p.externalLinks||{},genres:p.genres||[],emotions:p.emotions||[]}));
    v.assets=v.assets.map(a=>{
      const {sourceSha256,origin,source,...rest}=a;
      // PHP/PDO stringifies doubles to 14 significant digits. Preserve the active
      // exact value for these representational differences; never round track timelines.
      return {...rest,credit:a.credit||null,...(a.duration?{duration:Number(a.duration.toPrecision(14))}:{})};
    });
    v.site.artistLinks ||= {};
    for(const key of ['projects','tracks','assets']) v[key]=Object.fromEntries(v[key].map(r=>[r.id,r]));
    return v;
  }
  function candidate(id) {
    hex(id); const dir=path.join(candidates,id);
    const bytes=fs.readFileSync(path.join(dir,'manifest.json'),'utf8');
    const receipt=read(path.join(dir,'receipt.json'));
    if(receipt.candidateHash!==id || receipt.manifestSha256!==hash(bytes) || receipt.settingsSha256!==hash(fs.readFileSync(path.join(dir,'cms-settings.json'))) || !Number.isSafeInteger(receipt.adminUserId) || receipt.adminUserId<1) throw Error('Candidate receipt invalid; export through authenticated CMS');
    const settings=read(path.join(dir,'cms-settings.json'));
    if(settings.deploymentAuthorized!==false || settings.scope!=='next_draft') throw Error('Candidate is not a next-draft package');
    return {manifest:validate(JSON.parse(bytes),true),receipt};
  }
  function prepare(id,expectedBatchHash) {
    const oldBytes=fs.readFileSync(active,'utf8'); const old=validate(JSON.parse(oldBytes));
    const {manifest:next,receipt}=candidate(id);
    const a=comparable(old), b=comparable(next);
    // The core candidate must have no hidden records beyond its own public projection.
    for(const key of ['projects','tracks','assets']) if(Object.keys(b[key]).length!==next[key].length) throw Error('Candidate contains records outside the active frontend contract: '+key);
    const changes=diff(a,b);
    // Larger integrations require an exact, separately reviewed expected diff.
    // This is not a force flag: candidate, baseline and every value must match.
    if(expectedBatchHash) {
      const expected=read(path.join(state,'expected',hex(expectedBatchHash)+'.json'));
      if(hash(stable(expected))!==expectedBatchHash || expected.version!==1 || expected.candidateHash!==id) throw Error('Expected batch was modified or belongs to another candidate');
      if(changes.length && (expected.previousManifestHash!==hash(oldBytes) || !equal(expected.changes,changes))) throw Error('Candidate differs from the reviewed expected batch');
    } else if(changes.length>20 || Buffer.byteLength(json(changes))>32768 || changes.some(c=>/^\/(projects|tracks)\/[^/]+$/.test(c.path))) throw Error('Unexpected bulk/identity diff blocked; reconcile CMS candidate before apply');
    const merged=structuredClone(old);
    for(const key of ['projects','tracks','assets']) {
      const incoming=new Map(next[key].map(r=>[r.id,r]));
      const mergeRecord=(original) => {
        const target=incoming.get(original.id); incoming.delete(original.id);
        if(!target) return key==='assets' || !a[key][original.id] ? original : null;
        if(!a[key][original.id]) {
          if(!equal(original,target)) throw Error('Candidate conflicts with local nonpublic ID: '+original.id);
          return original;
        }
        const copy=structuredClone(original);
        for(const field of new Set([...Object.keys(a[key][original.id]),...Object.keys(b[key][original.id])])) {
          if(!equal(a[key][original.id][field],b[key][original.id][field])) {
            if(field in target) copy[field]=structuredClone(target[field]); else delete copy[field];
          }
        }
        return copy;
      };
      merged[key]=old[key].map(mergeRecord).filter(Boolean).concat([...incoming.values()]);
    }
    for(const field of new Set([...Object.keys(a.site),...Object.keys(b.site)])) if(!equal(a.site[field],b.site[field])) {
      if(field in next.site) merged.site[field]=structuredClone(next.site[field]); else delete merged.site[field];
    }
    validate(merged);
    assert(equal(comparable(merged),b),'Merged public content must equal candidate');
    const bytes=changes.length?json(merged):oldBytes;
    const plan={version:1,candidateHash:id,adminUserId:receipt.adminUserId,candidateManifestHash:receipt.manifestSha256,settingsHash:receipt.settingsSha256,
      previousManifestHash:hash(oldBytes),resultingManifestHash:hash(bytes),changes,
      productionReleased:false,companionApplied:false,...(expectedBatchHash?{expectedBatchHash}:{})};
    return {plan,bytes,oldBytes};
  }
  const planId=plan=>hash(stable(plan));
  function audit(entry) {
    // No content values, private manifests, credentials or visitor information.
    const safe={timestamp:new Date().toISOString(),operation:entry.operation,adminUserId:entry.adminUserId,candidateHash:entry.candidateHash,
      previousManifestHash:entry.previousManifestHash,resultingManifestHash:entry.resultingManifestHash,status:entry.status,productionReleased:false};
    const fd=fs.openSync(path.join(state,'history.jsonl'),'a',0o600);
    try {fs.writeFileSync(fd,JSON.stringify(safe)+'\n');fs.fsyncSync(fd);} finally {fs.closeSync(fd);}
  }
  function locked(fn) {
    const lock=path.join(state,'operation.lock'); let fd;
    try { fd=fs.openSync(lock,'wx',0o600); fs.writeFileSync(fd,String(process.pid)); }
    catch { throw Error('Another operation or interrupted process owns operation.lock; inspect PID before manual recovery'); }
    try { return fn(); } finally { fs.closeSync(fd); fs.unlinkSync(lock); }
  }
  function replace(bytes,expected) {
    const temp=path.join(path.dirname(active),'.cms-manifest-'+crypto.randomUUID()+'.tmp');
    try {
      writeNew(temp,bytes); validate(read(temp));
      options.beforeReplace?.(); // Dependency-injected fault only for isolated tests; no CLI bypass.
      if(hash(fs.readFileSync(active))!==expected) throw Error('Active manifest changed since review');
      fs.renameSync(temp,active); // Same-directory atomic replacement, never truncate/delete active.
    } finally { if(fs.existsSync(temp)) fs.unlinkSync(temp); }
  }
  function review(id,expectedFile) {
    let expectedBatchHash;
    if(expectedFile){const expected=read(expectedFile);expectedBatchHash=hash(stable(expected));writeNew(path.join(state,'expected',expectedBatchHash+'.json'),json(expected));}
    const {plan}=prepare(id,expectedBatchHash); const token=planId(plan);
    writeNew(path.join(state,'plans',token+'.json'),json(plan));
    return {planHash:token,...plan};
  }
  function apply(token,confirmation) {
    hex(token); if(confirmation!==token) throw Error('Explicit full plan-hash confirmation required');
    return locked(()=>{
      const recorded=read(path.join(state,'plans',token+'.json'));
      if(planId(recorded)!==token) throw Error('Review plan was modified');
      let plan=recorded;
      try {
        // Revalidate even on repeated apply. Never blindly trust the review file.
        const current=prepare(recorded.candidateHash,recorded.expectedBatchHash);
        if(hash(current.oldBytes)===recorded.resultingManifestHash && current.plan.changes.length===0) {
          audit({...recorded,operation:'apply',status:'already-current'}); return {status:'already-current'};
        }
        if(planId(current.plan)!==token) throw Error('Candidate or active content changed; review again');
        plan=current.plan;
        if(!plan.changes.length) {audit({...plan,operation:'apply',status:'already-current'});return {status:'already-current'};}
        const backup=path.join(state,'backups',plan.previousManifestHash+'.json');
        writeNew(backup,current.oldBytes);
        assert.equal(hash(fs.readFileSync(backup)),plan.previousManifestHash);
        validate(read(backup));
        // Durable recovery journal exists BEFORE touching active; contains hashes only.
        const revision={...Object.fromEntries(Object.entries(plan).filter(([k])=>k!=='changes')),planHash:token};
        fs.mkdirSync(path.join(state,'revisions'),{recursive:true});
        const revisionPath=path.join(state,'revisions',Date.now()+'-'+token+'.json');
        writeNew(revisionPath,json(revision));
        audit({...plan,operation:'apply',status:'prepared'});
        replace(current.bytes,plan.previousManifestHash);
        audit({...plan,operation:'apply',status:'success'});
        return {status:'success',...revision,backup};
      } catch(error) { audit({...plan,operation:'apply',resultingManifestHash:hash(fs.readFileSync(active)),status:'failure'}); throw error; }
    });
  }
  function rollbackReview() {
    const folder=path.join(state,'revisions');
    const files=fs.existsSync(folder)?fs.readdirSync(folder).filter(f=>f.endsWith('.json')).sort().reverse():[];
    const currentHash=hash(fs.readFileSync(active));
    const revision=files.map(f=>read(path.join(folder,f))).find(r=>r.resultingManifestHash===currentHash);
    if(!revision) throw Error('No applied revision matches current manifest; refusing to overwrite later changes');
    const bytes=fs.readFileSync(path.join(state,'backups',hex(revision.previousManifestHash)+'.json'),'utf8');
    if(hash(bytes)!==revision.previousManifestHash) throw Error('Rollback backup checksum differs');
    validate(JSON.parse(bytes));
    const plan={operation:'rollback',adminUserId:revision.adminUserId,candidateHash:revision.candidateHash,previousManifestHash:currentHash,resultingManifestHash:revision.previousManifestHash,productionReleased:false};
    return {planHash:planId(plan),...plan};
  }
  function rollback(confirmation) {
    return locked(()=>{
      const {planHash:token,...plan}=rollbackReview();
      if(confirmation!==token) throw Error('Explicit rollback hash confirmation required');
      try {
        const bytes=fs.readFileSync(path.join(state,'backups',plan.resultingManifestHash+'.json'),'utf8');
        replace(bytes,plan.previousManifestHash); audit({...plan,status:'success'}); return {status:'success',...plan};
      }catch(error){audit({...plan,resultingManifestHash:hash(fs.readFileSync(active)),status:'failure'});throw error;}
    });
  }
  return {review,apply,rollbackReview,rollback,validate,comparable,state};
}
