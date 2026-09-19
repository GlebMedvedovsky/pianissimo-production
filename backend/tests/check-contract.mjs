import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
const root=path.resolve(import.meta.dirname,'../..');
const result=await build({stdin:{contents:"export {validateManifest} from './contracts/content.ts';export {publicContent} from './contracts/public-content.ts';",resolveDir:root},bundle:true,platform:'node',format:'esm',write:false});
const {validateManifest,publicContent}=await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
const dir=path.join(root,'backend/storage/app/private/exports/candidates');
const candidates=fs.readdirSync(dir).map(name=>path.join(dir,name,'manifest.json')).filter(file=>fs.existsSync(file));
const file=process.argv[2]||candidates.sort((a,b)=>fs.statSync(b).mtimeMs-fs.statSync(a).mtimeMs)[0];
assert(file,'No candidate exported');
const candidate=validateManifest(JSON.parse(fs.readFileSync(file,'utf8')));
const source=publicContent(JSON.parse(fs.readFileSync(path.join(root,'content/manifest.json'),'utf8')));
for(const key of ['projects','tracks','assets']) assert.deepEqual(candidate[key].map(r=>r.id).sort(),source[key].map(r=>r.id).sort(),key+' identity mismatch');
for(const project of source.projects) {
  const exported=candidate.projects.find(p=>p.id===project.id);
  for(const key of ['title','synopsis','memberships','tracks','gallery','externalFilm','localTrailerAssetId','localTrailerPosterId']) assert.deepEqual(exported[key],project[key],project.id+'.'+key);
}
for(const track of source.tracks) {
  const exported=candidate.tracks.find(t=>t.id===track.id);
  for(const key of ['audioAssetId','duration','peaks','genres','emotions']) assert.deepEqual(exported[key],track[key],track.id+'.'+key);
}
assert.throws(()=>validateManifest(candidate,true));
const summary={candidate:path.relative(root,file),schema:'Existing TypeScript/Zod validateManifest, unchanged',projects:candidate.projects.length,tracks:candidate.tracks.length,assets:candidate.assets.length,identityAndPlaybackParity:true,releaseBlocked:true};
fs.writeFileSync(path.join(root,'docs/qa/03A/frontend-contract.json'),JSON.stringify(summary,null,2)); console.log(JSON.stringify(summary,null,2));
