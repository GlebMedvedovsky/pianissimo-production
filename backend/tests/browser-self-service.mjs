import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

const root=path.resolve(import.meta.dirname,'../..');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-qa.json'),'utf8'));
const baseline=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-self-service-baseline.json'),'utf8'));
const fixture=path.join(root,'backend/storage/app/private/browser-self-service.jpg');
fs.writeFileSync(fixture,Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==','base64'));
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext({viewport:{width:1440,height:1000}}); const page=await context.newPage();
const checks=[]; page.setDefaultTimeout(60000);
try {
  await page.goto('http://127.0.0.1:8080/admin/login');
  await page.locator('input[type=email]').fill(credentials.email); await page.locator('input[type=password]').fill(credentials.password);
  await page.getByRole('button',{name:'Anmelden',exact:true}).click(); await page.waitForURL('**/admin/dashboard');

  await page.getByRole('link',{name:'Medien',exact:true}).click(); await page.getByRole('link',{name:/Erstellen/}).click();
  await page.getByText('Neue Datei hochladen',{exact:true}).waitFor(); await page.getByText('Vorhandene Web-Datei registrieren',{exact:true}).waitFor();
  await page.getByLabel(/Stabile ID/).fill('synthetic-self-service'); await page.locator('input[type=file]').setInputFiles(fixture); await page.getByText('browser-self-service.jpg',{exact:false}).first().waitFor();
  await page.getByLabel('Alternativtext').fill('Synthetic self-service QA'); await page.getByRole('button',{name:'Erstellen',exact:true}).click();
  try{await page.waitForURL('**/synthetic-self-service/edit',{timeout:60000});}catch(error){throw new Error('Media create did not navigate: '+(await page.locator('main').innerText()),{cause:error});}
  await page.getByRole('button',{name:'Datei ersetzen',exact:true}).waitFor(); checks.push('Desktop upload UI and create');

  await page.getByRole('link',{name:'About',exact:true}).click();
  const image=page.getByLabel('Bild'); await image.click(); await page.getByRole('option',{name:/Synthetic self-service QA/}).click();
  await page.getByRole('button',{name:'Entwurf speichern',exact:true}).click(); await page.getByText('Entwurf gespeichert',{exact:true}).waitFor(); checks.push('Assign optional About image and Save Draft');
  assert.equal(hashFile(path.join(root,'content/manifest.json')),baseline.manifestSha256); checks.push('Save Draft leaves active frontend unchanged');

  await page.getByRole('link',{name:'Veröffentlichung',exact:true}).click();
  await page.getByRole('button',{name:'Paket prüfen (Dry Run)',exact:true}).click(); await page.getByText('Validierung erfolgreich',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Export-Vorschau erstellen',exact:true}).click(); await page.getByText('Lokales Paket erstellt',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Änderungen prüfen',exact:true}).click(); await page.getByText(/Änderungen geprüft/).waitFor();
  const descriptions=await page.locator('.fi-section').first().locator('li').allTextContents();
  assert(descriptions.some(text=>/Neues Medium: synthetic-self-service/.test(text))); assert(descriptions.some(text=>/Website-Medium geändert: mediaSlots → about → image/.test(text)));
  for(const text of descriptions)assert.match(text,/^(?:\+ Social Link:|− Social Link:|.+ (?:URL geändert|eingeblendet|ausgeblendet|Reihenfolge geändert)|\+ Neues Medium: synthetic-self-service|Website-Medium geändert: mediaSlots → about → image)$/);
  checks.push('Candidate and guarded semantic review in CMS UI');
  await page.getByRole('button',{name:'Für Website übernehmen',exact:true}).click(); await page.getByRole('button',{name:/Bestätigen|Für Website übernehmen/}).last().click();
  await page.getByText('Für Website übernommen',{exact:true}).waitFor();
  const active=JSON.parse(fs.readFileSync(path.join(root,'content/manifest.json'),'utf8')); const managed=active.assets.find(a=>a.id==='synthetic-self-service'); assert(managed);
  assert.equal(fs.existsSync(path.join(root,'frontend/public',managed.path)),true); checks.push('Apply from CMS UI activates reviewed manifest and managed bytes');
  await page.getByRole('button',{name:'Letzte Änderung zurücksetzen',exact:true}).click(); await page.getByRole('button',{name:/Bestätigen|Letzte Änderung zurücksetzen/}).last().click();
  await page.getByText('Letzte Änderung zurückgesetzt',{exact:true}).waitFor(); assert.equal(hashFile(path.join(root,'content/manifest.json')),baseline.manifestSha256); checks.push('Rollback from CMS UI restores manifest hash');

  await page.setViewportSize({width:390,height:844}); await page.goto('http://127.0.0.1:8080/admin/media-assets/create');
  assert(await page.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)); await page.getByText('Neue Datei hochladen',{exact:true}).waitFor(); checks.push('Narrow CMS upload page has no horizontal overflow');
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
} finally { fs.rmSync(fixture,{force:true}); await browser.close(); }

function hashFile(file){return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');}
