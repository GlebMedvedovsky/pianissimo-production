import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
const root=path.resolve(import.meta.dirname,'../..');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-qa.json'),'utf8'));
const baseline=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-self-service-baseline.json'),'utf8'));
const hashFile=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const browser=await chromium.launch({channel:'chrome',headless:true}); const page=await browser.newPage({viewport:{width:1440,height:1000}}); page.setDefaultTimeout(120000); const checks=[]; const failedResponses=[];
page.on('response',response=>{if(response.status()>=400)failedResponses.push(`${response.status()} ${response.url()}`);});
const clickAndWaitForNotification=async(buttonName,notificationText)=>{
  await page.getByRole('button',{name:buttonName,exact:true}).click();
  try {
    await page.getByText(notificationText,{exact:true}).waitFor();
  } catch (error) {
    const notifications=await page.locator('[role=status], .fi-no-notification').allTextContents();
    const main=await page.locator('main').innerText().catch(()=>'(kein main-Inhalt)');
    throw new Error(`${buttonName}: erwartete Meldung "${notificationText}" fehlt. Sichtbare Meldungen: ${notifications.join(' | ')||'(keine)'}. HTTP-Fehler: ${failedResponses.join(' | ')||'(keine)'}. Inhalt: ${main}`,{cause:error});
  }
};
const waitForActionOutcome=async(successText)=>{
  const outcome=await Promise.race([
    page.getByText(successText,{exact:true}).waitFor().then(()=>({status:'success'})),
    page.getByText('Aktion blockiert',{exact:true}).waitFor().then(()=>({status:'blocked'})),
  ]);
  if(outcome.status==='blocked'){
    const notifications=await page.locator('.fi-no-notification').allTextContents();
    const alert=await page.locator('[role=alert]').allTextContents();
    throw new Error(`CMS-Aktion blockiert: ${[...notifications,...alert].join(' | ')||'(keine Detailmeldung)'}`);
  }
};
try {
  await page.goto('http://127.0.0.1:8080/admin/login'); await page.locator('input[type=email]').fill(credentials.email); await page.locator('input[type=password]').fill(credentials.password); await page.getByRole('button',{name:'Anmelden',exact:true}).click(); await page.waitForURL('**/admin/dashboard');
  assert.equal(hashFile(path.join(root,'content/manifest.json')),baseline.manifestSha256); checks.push('Upload and draft assignment left active frontend unchanged');
  await page.getByRole('link',{name:'Veröffentlichung',exact:true}).click();
  if(process.env.SKIP_DRY_RUN!=='1')await clickAndWaitForNotification('Paket prüfen (Dry Run)','Validierung erfolgreich');
  await page.getByRole('button',{name:'Export-Vorschau erstellen',exact:true}).click();
  await page.getByRole('button',{name:'Änderungen prüfen',exact:true}).waitFor();
  await page.getByRole('button',{name:'Änderungen prüfen',exact:true}).click(); await page.getByText(/Änderungen geprüft/).waitFor();
  const descriptions=await page.locator('.fi-section').first().locator('li').allTextContents(); assert(descriptions.some(text=>/Neues Medium: synthetic-self-service/.test(text))); assert(descriptions.some(text=>/Website-Medium geändert: mediaSlots → about → image/.test(text)));
  for(const text of descriptions)assert.match(text,/^(?:\+ Social Link: .+|− Social Link: .+|.+ (?:URL geändert|eingeblendet|ausgeblendet|Reihenfolge geändert)|\+ Neues Medium: synthetic-self-service|Website-Medium geändert: mediaSlots → about → image)$/);
  checks.push('Dry Run, Candidate and whitelisted semantic review from CMS UI');
  await page.getByRole('button',{name:'Für Website übernehmen',exact:true}).click(); await page.getByRole('alertdialog').getByRole('button',{name:/Bestätigen|Für Website übernehmen/}).last().click(); await waitForActionOutcome('Für Website übernommen');
  const active=JSON.parse(fs.readFileSync(path.join(root,'content/manifest.json'),'utf8')); const asset=active.assets.find(a=>a.id==='synthetic-self-service'); assert(asset); assert.equal(fs.existsSync(path.join(root,'frontend/public',asset.path)),true); checks.push('CMS Apply activated reviewed manifest and media bytes');
  await page.getByRole('button',{name:'Letzte Änderung zurücksetzen',exact:true}).click(); await page.getByRole('alertdialog').getByRole('button',{name:/Bestätigen|Letzte Änderung zurücksetzen/}).last().click(); await waitForActionOutcome('Letzte Änderung zurückgesetzt'); assert.equal(hashFile(path.join(root,'content/manifest.json')),baseline.manifestSha256); checks.push('CMS Rollback restored active manifest hash');
  await page.setViewportSize({width:390,height:844}); await page.goto('http://127.0.0.1:8080/admin/media-assets/create'); assert(await page.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)); await page.getByText('Neue Datei hochladen',{exact:true}).waitFor(); checks.push('390px CMS media-create page has no horizontal overflow');
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
} finally {await browser.close();}
