// Independent checks of this project's patterns; not a Git index/staging audit.
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import picomatch from 'picomatch';
const root=path.resolve(import.meta.dirname,'../..');
const rules=fs.readFileSync(path.join(root,'.gitignore'),'utf8').split(/\r?\n/).map(s=>s.trim()).filter(s=>s&&!s.startsWith('#')).map(line=>{
  const include=line.startsWith('!'); let pattern=include?line.slice(1):line;
  const anchored=pattern.startsWith('/'); if(anchored)pattern=pattern.slice(1);
  const directory=pattern.endsWith('/'); if(directory)pattern=pattern.slice(0,-1);
  if(!anchored&&!pattern.includes('/'))pattern='**/'+pattern;
  if(directory)pattern+='{,/**}';
  return {include,match:picomatch(pattern,{dot:true})};
});
function ignored(file){
  const parts=file.split('/');
  for(let i=1;i<=parts.length;i++){
    const current=parts.slice(0,i).join('/'); let value=false;
    for(const rule of rules)if(rule.match(current))value=!rule.include;
    if(value)return true; // an excluded parent cannot be re-included by a child rule
  }
  return false;
}
const excluded=['backend/.env','backend/.env.local','backend/vendor/autoload.php','backend/bootstrap/cache/config.php','backend/storage/app/private/local-admin.json','backend/storage/app/private/exports/candidates/qa/manifest.json','backend/storage/logs/laravel.log','backend/public/css/filament/filament/app.css','backend/.phpunit.cache/test-results','backend/backups/db.sql','docs/qa/03A/before/README.md'];
const included=['backend/.env.example','backend/composer.json','backend/composer.lock','backend/compose.yaml','backend/Dockerfile','backend/docker/nginx.conf','backend/database/migrations/2026_09_18_000001_create_cms.php','backend/app/Models/Project.php','backend/tests/Feature/CmsTest.php','backend/contracts/README.md','backend/README.md','backend/Start-Local.ps1'];
for(const file of excluded)assert(ignored(file),'Not excluded: '+file);
for(const file of included)assert(!ignored(file),'Unexpectedly excluded source: '+file);
const report={excluded,included,passed:excluded.length+included.length,limitation:'Independent pattern checks only; no Git repository/index exists and none was created.'};
fs.writeFileSync(path.join(root,'docs/qa/03A/ignore-check.json'),JSON.stringify(report,null,2));console.log(`${report.passed} ignore/source-inclusion fixtures passed; not a Git index audit.`);
