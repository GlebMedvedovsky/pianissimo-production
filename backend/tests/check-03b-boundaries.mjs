import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(import.meta.dirname,'../..'),out=path.join(root,'docs/qa/03B');
const privatePaths=['backend/.env','backend/storage/app/private/local-admin.json','backend/storage/app/private/frontend-apply/history.jsonl','backend/storage/app/private/03b-baseline.json'];
const probes=[];
for(const name of privatePaths){
  for(const suffix of ['', '?raw','?import'])probes.push('http://127.0.0.1:4321/@fs/D:/Projects/Sebastian/'+name+suffix);
  probes.push('http://127.0.0.1:4321/'+name);
  probes.push('http://127.0.0.1:8080/'+name.replace(/^backend\//,''));
}
for(const name of ['.env','storage/app/private/local-admin.json','storage/app/private/frontend-apply/history.jsonl'])probes.push('http://127.0.0.1:8080/'+name);
const results=[];for(const url of probes){const r=await fetch(url,{redirect:'manual'});await r.arrayBuffer();results.push({url,status:r.status});assert([403,404].includes(r.status),url+' unexpectedly reachable');}
const guest=await fetch('http://127.0.0.1:8080/admin-export/1/manifest.json',{redirect:'manual'});assert.equal(guest.status,302);
fs.writeFileSync(path.join(out,'http-boundaries.json'),JSON.stringify({probes:results,authenticatedExportRedirect:guest.status},null,2));console.log(`${results.length} private HTTP paths blocked; candidate download requires login.`);
