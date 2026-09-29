import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root=path.resolve(import.meta.dirname,'../..');
const out=path.join(root,'docs/qa/03A/browser'); fs.mkdirSync(out,{recursive:true});
const first=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/local-admin.json'),'utf8'));
const second=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-qa.json'),'utf8'));
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext({viewport:{width:1440,height:1000},colorScheme:'light'});
const page=await context.newPage(); const errors=[]; const checks=[]; const failedResponses=[]; const externalRequests=[];
page.on('pageerror',e=>errors.push(e.message));
page.on('response',r=>{if(r.status()>=400)failedResponses.push({path:new URL(r.url()).pathname,status:r.status()});});
page.on('request',r=>{if(r.url().startsWith('http')&&new URL(r.url()).hostname!=='127.0.0.1')externalRequests.push(new URL(r.url()).origin);});
page.setDefaultTimeout(60000);
async function dark() { return page.locator('html').evaluate(e=>e.classList.contains('dark')); }
async function menu() { if (!await page.getByRole('button',{name:'Light Mode einschalten',exact:true}).isVisible()) await page.locator('.fi-user-menu-trigger').click(); }
async function theme(name) {
  await menu();
  const labels={dark:'Dark Mode einschalten',light:'Light Mode einschalten',system:'Systemthema benutzen'};
  await Promise.all([page.waitForResponse(r=>r.url().endsWith('/admin-theme')&&r.request().postDataJSON()?.theme===name&&r.status()===200),page.getByRole('button',{name:labels[name],exact:true}).click()]);
  await page.keyboard.press('Escape');
}
async function logout() { await menu(); await page.getByRole('button',{name:'Abmelden',exact:true}).click(); await page.waitForURL('**/admin/login'); }
async function login(credentials) {
  await page.goto('http://127.0.0.1:8080/admin/login');
  await page.locator('input[type=email]').fill(credentials.email);
  await page.locator('input[type=password]').fill(credentials.password);
  await page.getByRole('button',{name:'Anmelden',exact:true}).click();
  await page.waitForURL('**/admin/dashboard');
  await page.getByRole('heading',{name:'Dashboard',exact:true}).waitFor();
}
try {
  await login(first);
  if (!await dark()) await theme('dark');
  assert.equal(await dark(),true); checks.push('First admin Dark applies despite light OS');
  await page.screenshot({path:path.join(out,'dashboard-initial.png'),fullPage:true});
  await theme('light'); assert.equal(await dark(),false);
  await page.reload(); assert.equal(await dark(),false); checks.push('Light persists on refresh');
  await page.screenshot({path:path.join(out,'dashboard-light.png'),fullPage:true});
  await logout(); await login(second); assert.equal(await dark(),true); checks.push('Second user has independent default Dark');
  await theme('system'); assert.equal(await dark(),false);
  await page.emulateMedia({colorScheme:'dark'}); await page.waitForFunction(()=>document.documentElement.classList.contains('dark'));
  await page.emulateMedia({colorScheme:'light'}); await page.waitForFunction(()=>!document.documentElement.classList.contains('dark'));
  await page.reload(); assert.equal(await dark(),false); checks.push('System follows OS changes and refresh');
  await logout(); await login(first); assert.equal(await dark(),false); checks.push('First user Light survives second-user login and logout');
  await theme('dark'); assert.equal(await dark(),true);
  const routes=[...new Set(await page.locator('.fi-sidebar-nav a[href]').evaluateAll(es=>es.map(e=>e.href)))];
  for (const url of routes) {
    const response=await page.goto(url); assert.equal(response.status(),200,url);
    await page.locator('main').waitFor(); checks.push('Admin route '+new URL(url).pathname);
  }
  await page.goto('http://127.0.0.1:8080/admin/projects/kinnhakenhank/edit');
  await page.getByRole('tab',{name:'Richtungen & Bilder',exact:true}).click();
  const projectText=await page.locator('main').innerText();
  assert.match(projectText,/Film Music/); assert.match(projectText,/Directing/);
  await page.screenshot({path:path.join(out,'kinnhakenhank-memberships.png'),fullPage:true}); checks.push('Shared Kinnhakenhank memberships visible');
  await page.goto('http://127.0.0.1:8080/admin/projects/create');
  await page.getByRole('textbox',{name:/^Stabile ID/}).fill('synthetic-browser-qa');
  await page.getByRole('textbox',{name:/^URL-Slug/}).fill('synthetic-browser-qa');
  await page.getByRole('textbox',{name:/^Titel/}).fill('Synthetic browser QA');
  await page.getByRole('textbox',{name:/^Synopsis/}).fill('Synthetic test record; not Sebastian content.');
  await page.getByRole('textbox',{name:/^Produktionsart/}).fill('QA');
  await page.getByRole('button',{name:'Erstellen',exact:true}).click();
  await page.waitForURL('**/synthetic-browser-qa/edit');
  await page.getByRole('textbox',{name:/^Titel/}).fill('Synthetic browser QA edited');
  await page.getByRole('button',{name:'Entwurf speichern',exact:true}).click();
  await page.getByText('Gespeichert',{exact:true}).waitFor();
  await page.reload(); assert.equal(await page.getByRole('textbox',{name:/^Titel/}).inputValue(),'Synthetic browser QA edited'); checks.push('Create/edit/save draft persists');
  await page.goto('http://127.0.0.1:8080/admin/publication');
  await page.getByRole('button',{name:'Paket prüfen',exact:true}).click();
  await page.getByText('Validierung erfolgreich',{exact:true}).waitFor(); checks.push('Dry-run through real browser');
  await page.getByRole('button',{name:'Kandidat exportieren',exact:true}).click();
  await page.getByText('Lokales Paket erstellt',{exact:true}).waitFor(); checks.push('Candidate export through real browser');
  await page.screenshot({path:path.join(out,'candidate-export.png'),fullPage:true});
  const media=await context.request.get('http://127.0.0.1:8080/admin-media/hero-poster-02d'); assert.equal(media.status(),200); assert.match(media.headers()['content-type'],/^image\//); checks.push('Authenticated media file loads');
  await page.setViewportSize({width:390,height:844});
  await page.goto('http://127.0.0.1:8080/admin/dashboard');
  assert(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1));
  await page.screenshot({path:path.join(out,'mobile-dashboard.png'),fullPage:true}); checks.push('Mobile emulation without horizontal overflow');
  const observedExternalOrigins=[...new Set(externalRequests)];
  const knownEnvironmentOrigins=['http://gc.kis.v2.scr.kaspersky-labs.com'];
  const unexpectedExternalOrigins=observedExternalOrigins.filter(origin=>!knownEnvironmentOrigins.includes(origin));
  assert.deepEqual(errors,[]); assert.deepEqual(failedResponses,[]); assert.deepEqual(unexpectedExternalOrigins,[]);
  fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({checks,errors,failedResponses,observedExternalOrigins,unexpectedExternalOrigins,environmentNote:'Installed Kaspersky injects browser traffic; it was not disabled. Raw CMS response/source checked separately. Zero external traffic for the entire browser environment is not claimed.',browser:await browser.version(),devices:['Chrome desktop','Chrome 390px emulation'],physicalDevices:false},null,2));
  console.log(JSON.stringify({passed:checks.length,checks,errors},null,2));
} catch(error) { fs.writeFileSync(path.join(out,'progress.json'),JSON.stringify({checks,errors,failure:error.message},null,2)); await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}); throw error; }
finally {await browser.close();}
