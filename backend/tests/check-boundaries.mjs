import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(import.meta.dirname,'../..');
const files=['.env','composer.json','storage/app/private/local-admin.json','storage/app/private/browser-qa.json','app/Models/User.php','vendor/autoload.php'];
const probes=[];
for(const file of files) {
  probes.push({base:'http://127.0.0.1:8080',path:'/'+file,allowed:[403,404]});
  for(const [prefix,suffix] of [['/@fs/D:/Projects/Sebastian/backend/','?raw'],['/@fs/D:/Projects/Sebastian/backend/','?import'],['/@id/D:/Projects/Sebastian/backend/','?import']]) {
    probes.push({base:'http://127.0.0.1:4321',path:prefix+file+suffix,allowed:[403,404]});
  }
}
probes.push({base:'http://127.0.0.1:8080',path:'/admin-media/hero-poster-02d',allowed:[302]});
probes.push({base:'http://127.0.0.1:8080',path:'/admin-export/1/manifest.json',allowed:[302,404]});
const result=[];
for(const probe of probes) {
  const response=await fetch(probe.base+probe.path,{redirect:'manual',signal:AbortSignal.timeout(60000)});
  const item={url:probe.base+probe.path,status:response.status,blocked:probe.allowed.includes(response.status)};
  result.push(item); await response.body?.cancel();
}
const frontend=await fetch('http://127.0.0.1:4321/'); assert.equal(frontend.status,200); await frontend.body?.cancel();
fs.writeFileSync(path.join(root,'docs/qa/03A/http-boundaries.json'),JSON.stringify({result,frontend:200},null,2));
assert(result.every(r=>r.blocked),'One or more private paths were not blocked; see private report, no response bodies saved.');
console.log(`${result.length} private HTTP probes blocked; frontend 4321 returns 200. No secret values read into the report.`);
