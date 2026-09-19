import {readFile,writeFile,copyFile,mkdir,stat} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {resolve,dirname,relative} from 'node:path';
import {validateManifest} from '../contracts/content';
// @ts-expect-error Small generic merge also tested directly by Node.
import {mergeDraft,excludeBlockedMedia} from './local-import-merge.mjs';
const site=resolve('.'),materials=resolve(site,'../Sebastian_Materials'),prepared=resolve(materials,'prepared/02D');
if(site.toLowerCase()!=='d:\\projects\\sebastian')throw Error('Use the permanent project root');
const json=async(p:string)=>JSON.parse(await readFile(p,'utf8'));
const hash=async(p:string)=>createHash('sha256').update(await readFile(p)).digest('hex');
const save=async(p:string,data:unknown)=>{await mkdir(dirname(p),{recursive:true});await writeFile(p,JSON.stringify(data,null,2)+'\n','utf8')};
const preflight=await json(prepared+'/preflight.json'),plan=await json(prepared+'/data/import.plan.local.json'),answers=await json(prepared+'/data/answers.received.2026-09-18.json');
if(plan.application_scope!=='local_draft'||plan.production_release!==false||plan.apply_to_live!==false||plan.input_errors.length||plan.questions_conflict)throw Error('Local plan is not eligible');
if(!preflight.public_4321?.includes('not forwarded; external preview only 4322'))throw Error('Private dev preflight missing');
const candidate=validateManifest(await json(prepared+'/data/manifest.local.candidate.json'));
if(candidate.mode!=='prototype'||candidate.site.legal.approved||answers.target_context?.access!=='local_only')throw Error('Only local prototype import allowed');
const ready=(id:string)=>{const op=plan.operations.find((o:any)=>o.operation_id===id);if(op?.status!=='ready_for_later_review')throw Error('Unconfirmed operation '+id);return op};
for(const id of ['site.hero.video','site.composerMenu.image','site.directingMenu.image','site.about.image','request.A03','request.A07','request.A06','request.K02','silver-strings.playlist','rollen-am-klavier.playlist','site.contacts'])ready(id);
for(const p of candidate.projects){ready(p.id+'.placement');ready(p.id+'.category');for(const section of Object.keys(p.memberships))ready(p.id+'.cover.'+section)}
const index=await json(prepared+'/data/assets.for-plan.json'),rights=answers.answers.find((a:any)=>a.question_id==='M01'),copies=await json(prepared+'/data/media-copy.plan.json');
const verified=new Set<string>(),blockedFiles=new Set<string>();const mediaConflicts:Array<{file_id:string;path:string;reason:string}>=[];
const base=await json(resolve(preflight.backup,'site/content/manifest.json')),current=await json('content/manifest.json');
for(const c of copies){
 const src=resolve(c.source),dest=resolve(site,'frontend/public',c.path);
 if(relative(materials,src).startsWith('..')||!dest.startsWith(resolve(site,'frontend/public/media/02d')+'\\'))throw Error('Path outside selected media scope');
 try{
 if(await hash(src)!==c.sha256)throw Error('Changed derivative '+c.path);
 if(!verified.has(c.file_id)){
  const a=index.assets.find((a:any)=>a.file_id===c.file_id);ready('prepare.'+c.file_id);
  if(!a||rights.values.asset_permissions[c.file_id]?.allowed_scope!=='next_draft'||rights.source_hashes[c.file_id]!==a.source_sha256)throw Error('Missing scoped rights '+c.file_id);
  const source=resolve(materials,'unpacked',a.source_relative_path);if(!source.startsWith(resolve(materials,'unpacked')+'\\')||await hash(source)!==a.source_sha256)throw Error('Changed original '+c.file_id);
  verified.add(c.file_id);
 }
 const exists=await stat(dest).then(()=>true,()=>false);if(exists&&await hash(dest)!==c.sha256)throw Error('Manual media change preserved: '+c.path);
 }catch(error){blockedFiles.add(c.file_id);mediaConflicts.push({file_id:c.file_id,path:c.path,reason:String(error)})}
}
for(const c of copies.filter((c:any)=>!blockedFiles.has(c.file_id))){const dest=resolve(site,'frontend/public',c.path);await mkdir(dirname(dest),{recursive:true});if(!await stat(dest).then(()=>true,()=>false))await copyFile(c.source,dest)}
const blockedPaths=new Set(copies.filter((c:any)=>blockedFiles.has(c.file_id)).map((c:any)=>c.path));const blockedIds=candidate.assets.filter(a=>blockedPaths.has(a.path)).map(a=>a.id);
const result=mergeDraft(base,excludeBlockedMedia(candidate,current,blockedIds),current);validateManifest(result.value);
const applied=result.changes.filter((c:any)=>c.status==='applied');
if(applied.length)await save('content/manifest.json',result.value);
const journal={date:new Date().toISOString(),application_scope:'local_draft',production_release:false,plan_sha256:await hash(prepared+'/data/import.plan.local.json'),answers_sha256:await hash(prepared+'/data/answers.received.2026-09-18.json'),backup:preflight.backup,changes:result.changes,conflicts:result.conflicts,media_conflicts:mediaConflicts,media:copies,question_sources:answers.answers.map((a:any)=>({question_id:a.question_id,source:a.answer_source})),operations:plan.operations.filter((o:any)=>o.status==='ready_for_later_review').map((o:any)=>({id:o.operation_id,field:o.field,object:o.object,questions:o.question_ids,file_ids:o.file_ids,source_hashes:o.source_hashes}))};
await save(prepared+'/data/import.last-run.json',journal);
if(applied.length)await save(prepared+'/data/import.applied.json',journal);
console.log(JSON.stringify({applied:applied.length,alreadyApplied:result.changes.length-applied.length,conflicts:result.conflicts.length,mediaConflicts:mediaConflicts.length,verifiedSources:verified.size,mediaFiles:copies.length,scope:'local_draft'}));
if(result.conflicts.length||mediaConflicts.length)process.exitCode=2;
