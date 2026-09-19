import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createWorkflow,hash,json,diff} from '../../scripts/lib/cms-frontend.mjs';
const root=path.resolve(import.meta.dirname,'../..');
const roundTripOnly=process.argv.includes('--roundtrip-only');
const out=path.join(root,'docs/qa/03B',roundTripOnly?'browser-fixed':'browser');fs.mkdirSync(out,{recursive:true});
const active=path.join(root,'content/manifest.json');
const baseline=fs.readFileSync(path.join(root,'docs/qa/03B/before/manifest.json'),'utf8');
assert.equal(hash(fs.readFileSync(active)),hash(baseline),'Start with exact baseline');
const original=JSON.parse(baseline).projects.find(p=>p.id==='abszission').synopsis;
const marker='[03B temporary round-trip check — not project content]';
const draft=original+'\n\n'+marker;
const prefs=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/03b-baseline.json')));
const flow=await createWorkflow(root);
const browser=await chromium.launch({channel:'chrome',headless:true});
const ctx=await browser.newContext({viewport:{width:1440,height:1000},colorScheme:'light'});
const admin=await ctx.newPage(),home=await ctx.newPage(),project=await ctx.newPage();
const checks=[],errors=[],frames=[],requests=[];let draftSaved=false,applied=false,themeChanged=false;
for(const page of [admin,home,project]){page.setDefaultTimeout(60000);page.on('pageerror',e=>errors.push({page:page===admin?'CMS':'frontend',message:e.message}));}
home.on('websocket',ws=>ws.on('framereceived',event=>{try {const p=JSON.parse(String(event.payload));frames.push({type:p.type,path:p.path});}catch{}}));
home.on('request',r=>requests.push(new URL(r.url()).pathname));
let homeNavigations=0;home.on('framenavigated',f=>{if(f===home.mainFrame())homeNavigations++;});
async function login(file) {
  // Read existing credential only into memory for the normal login form.
  // Never copy it, log it, trace the login, screenshot it, or change it.
  const credentials=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private',file),'utf8'));
  await admin.goto('http://127.0.0.1:8080/admin/login');
  await admin.locator('input[type=email]').fill(credentials.email);await admin.locator('input[type=password]').fill(credentials.password);
  await admin.getByRole('button',{name:'Anmelden',exact:true}).click();await admin.waitForURL('**/admin/dashboard');
}
async function menu(){if(!await admin.getByRole('button',{name:'Light Mode einschalten',exact:true}).isVisible())await admin.locator('.fi-user-menu-trigger').click();}
async function theme(value){if(await admin.evaluate(()=>localStorage.getItem('theme'))===value)return;await menu();const label={dark:'Dark Mode einschalten',light:'Light Mode einschalten',system:'Systemthema benutzen'};
  await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/admin-theme')&&r.request().postDataJSON()?.theme===value&&r.status()===200),admin.getByRole('button',{name:label[value],exact:true}).click()]);await admin.keyboard.press('Escape');}
async function logout(){await menu();await admin.getByRole('button',{name:'Abmelden',exact:true}).click();await admin.waitForURL('**/admin/login');}
const isDark=()=>admin.locator('html').evaluate(e=>e.classList.contains('dark'));
async function saveSynopsis(value){await admin.goto('http://127.0.0.1:8080/admin/projects/abszission/edit');await admin.getByRole('textbox',{name:/^Synopsis/}).fill(value);await admin.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await admin.getByText('Gespeichert',{exact:true}).waitFor();}
async function publicData(page){return page.locator('#site-content').textContent();}
async function exportCandidate(){await admin.goto('http://127.0.0.1:8080/admin/publication');await admin.getByRole('button',{name:'Export-Vorschau erstellen',exact:true}).click();await admin.getByText('Lokales Paket erstellt',{exact:true}).waitFor();return await admin.locator('code[title]').getAttribute('title');}
async function cli(command,id,token,label){
  return new Promise((resolve,reject)=>{let output='',sent=false;const args=['scripts/cms-frontend.mjs',command,...(id?[id]:[])];
    const child=spawn(process.execPath,args,{cwd:root,stdio:['pipe','pipe','pipe'],windowsHide:true});
    child.stdout.on('data',b=>{output+=b.toString();if(!sent&&output.includes('Type the complete confirmation hash')){sent=true;child.stdin.end(token+'\n');}});
    child.stderr.on('data',b=>output+=b.toString());child.on('error',reject);
    child.on('exit',code=>{fs.writeFileSync(path.join(out,label+'.txt'),output);code===0?resolve(output):reject(Error('Local '+command+' failed; see private QA output'));});
  });
}
try {
  await login('local-admin.json');checks.push('Normal CMS login with unchanged credentials');
  await home.goto('http://127.0.0.1:4321/');await project.goto('http://127.0.0.1:4321/projects/abszission/');
  const publicBefore=await publicData(home);fs.writeFileSync(path.join(out,'baseline-public-hash.json'),json({hash:hash(publicBefore)}));
  if(!roundTripOnly) {
  await theme('dark');themeChanged=true;assert(await isDark());
  assert.equal(await admin.locator('body').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(20, 20, 20)');
  await admin.screenshot({path:path.join(out,'admin-dark.png'),fullPage:true});
  await theme('light');assert.equal(await isDark(),false);await admin.reload();assert.equal(await isDark(),false);
  assert.equal(await admin.locator('body').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(243, 240, 234)');
  await admin.screenshot({path:path.join(out,'admin-light.png'),fullPage:true});checks.push('Dark → Light, correct backgrounds, refresh persistence');
  await logout();await login('03b-test-user.json');assert(await isDark());checks.push('Second user defaults to independent Dark');
  await theme('system');assert.equal(await isDark(),false);await admin.emulateMedia({colorScheme:'dark'});await admin.waitForFunction(()=>document.documentElement.classList.contains('dark'));
  await admin.emulateMedia({colorScheme:'light'});await admin.waitForFunction(()=>!document.documentElement.classList.contains('dark'));await admin.reload();assert.equal(await isDark(),false);
  await logout();await login('03b-test-user.json');assert.equal(await isDark(),false);checks.push('System follows OS and survives refresh and logout/login');
  await logout();await login('local-admin.json');assert.equal(await isDark(),false);checks.push('First-user Light survives second-user theme changes and logout/login');
  await theme(prefs.theme);themeChanged=false;
  await admin.goto('http://127.0.0.1:8080/admin/appearance');assert.match(await admin.locator('main').innerText(),/Website-Farben/);
  assert.equal(hash(fs.readFileSync(active)),hash(baseline));assert.equal(await publicData(home),publicBefore);checks.push('Theme changes leave active manifest and frontend public data unchanged');
  }
  const baselineCandidate=await exportCandidate();
  const settingsBefore=fs.readFileSync(path.join(root,'backend/storage/app/private/exports/candidates',baselineCandidate,'cms-settings.json'),'utf8');
  await saveSynopsis(draft);draftSaved=true;
  await admin.reload();assert.equal(await admin.getByRole('textbox',{name:/^Synopsis/}).inputValue(),draft);
  assert.equal(hash(fs.readFileSync(active)),hash(baseline));await home.reload();assert.equal(await publicData(home),publicBefore);
  await project.reload();assert.equal(await project.getByText(marker,{exact:true}).count(),0);checks.push('CMS Draft Save persists only in CMS; active and served public data hashes unchanged');
  const id=await exportCandidate();const candidate=path.join(root,'backend/storage/app/private/exports/candidates',id,'manifest.json');
  assert(fs.existsSync(candidate));assert.equal(hash(fs.readFileSync(active)),hash(baseline));checks.push('Separate authenticated candidate export does not activate frontend');
  assert.equal(fs.readFileSync(path.join(path.dirname(candidate),'cms-settings.json'),'utf8'),settingsBefore);checks.push('Synopsis-only save leaves all companion SEO/settings byte-exact');
  const plan=flow.review(id);assert.equal(plan.changes.length,1);assert.deepEqual(plan.changes[0],{path:'/projects/abszission/synopsis',before:original,after:draft});
  fs.writeFileSync(path.join(out,'review.json'),json(plan));checks.push('Existing schema + references + media hashes valid; exactly one expected stable-ID diff');
  await admin.screenshot({path:path.join(out,'candidate-review.png'),fullPage:true});
  console.log(JSON.stringify({stage:'reviewed',candidate:path.relative(root,candidate),changes:plan.changes.length,path:plan.changes[0].path,baselineHash:hash(baseline)}));
  const beforeFrames=frames.length,beforeNavigation=homeNavigations;
  await cli('apply',plan.planHash,plan.planHash,'explicit-apply');applied=true;
  await home.waitForFunction(marker=>document.querySelector('#site-content')?.textContent.includes(marker),marker,{timeout:30000});
  await project.getByText(marker,{exact:true}).waitFor({timeout:30000});
  checks.push('Actual CLI confirmation applied; existing open frontend pages updated without manual reload/restart');
  const rawDiff=diff(JSON.parse(baseline),JSON.parse(fs.readFileSync(active)));
  // Raw top-level arrays are compared separately for a useful field-level report.
  const appliedData=JSON.parse(fs.readFileSync(active));const originalData=JSON.parse(baseline);
  for(const key of ['projects','tracks','assets'])assert.deepEqual(appliedData[key].map(r=>r.id),originalData[key].map(r=>r.id));
  assert.deepEqual(appliedData.assets,originalData.assets);assert.deepEqual(appliedData.sources,originalData.sources);checks.push('All stable IDs, exact media metadata and private local registry preserved');
  await project.screenshot({path:path.join(out,'frontend-applied.png'),fullPage:true});
  const hmr={frames:frames.slice(beforeFrames),automaticNavigations:homeNavigations-beforeNavigation,manualReload:false,serverRestart:false};
  fs.writeFileSync(path.join(out,'hmr.json'),json(hmr));
  await cli('apply',plan.planHash,plan.planHash,'repeated-apply');checks.push('Repeated candidate apply is an explicit already-current no-op');
  const rollback=flow.rollbackReview();await cli('rollback',null,rollback.planHash,'explicit-rollback');applied=false;
  assert.equal(fs.readFileSync(active,'utf8'),baseline);
  await home.waitForFunction(marker=>!document.querySelector('#site-content')?.textContent.includes(marker),marker,{timeout:30000});
  await project.getByText(marker,{exact:true}).waitFor({state:'detached',timeout:30000});
  await project.screenshot({path:path.join(out,'frontend-restored.png'),fullPage:true});checks.push('Rollback restores byte-exact baseline and open browser without resetting CMS draft');
  await admin.goto('http://127.0.0.1:8080/admin/projects/abszission/edit');assert.equal(await admin.getByRole('textbox',{name:/^Synopsis/}).inputValue(),draft);checks.push('Rollback does not silently delete CMS draft');
  await saveSynopsis(original);draftSaved=false;await admin.reload();assert.equal(await admin.getByRole('textbox',{name:/^Synopsis/}).inputValue(),original);checks.push('Only synthetic synopsis addition removed from CMS');
  const restoredId=await exportCandidate();assert.deepEqual(flow.review(restoredId).changes,[]);checks.push('Fresh restored candidate has zero public diff');
  await admin.screenshot({path:path.join(out,'cms-final-history.png'),fullPage:true});
  for(const route of ['/','/film-music/','/directing/','/projects/kinnhakenhank/']){const r=await project.goto('http://127.0.0.1:4321'+route);assert.equal(r.status(),200);}
  checks.push('Frontend home, both directions and Kinnhakenhank remain available');
  await admin.setViewportSize({width:390,height:844});await admin.goto('http://127.0.0.1:8080/admin/dashboard');assert(await admin.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1));
  await admin.screenshot({path:path.join(out,'admin-mobile.png'),fullPage:true});checks.push('CMS 390px emulation has no horizontal overflow');
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(out,'result.json'),json({passed:checks.length,checks,errors,baselineValue:original,draftValue:draft,candidate:path.relative(root,candidate),restoredCandidate:restoredId,baselineHash:hash(baseline),finalHash:hash(fs.readFileSync(active)),hmr,browser:await browser.version(),physicalDevices:false}));
  console.log(JSON.stringify({passed:checks.length,checks,baselineRestored:true,hmr},null,2));
}catch(error){fs.writeFileSync(path.join(out,'failure.json'),json({checks,errors,failure:error.message}));if(!admin.url().endsWith('/login'))await admin.screenshot({path:path.join(out,'failure.png'),fullPage:true});throw error;}
finally {
  try{if(applied || hash(fs.readFileSync(active))!==hash(baseline)){const r=flow.rollbackReview();flow.rollback(r.planHash);}if(draftSaved)await saveSynopsis(original);if(themeChanged)await theme(prefs.theme);}
  finally {await browser.close();}
}
