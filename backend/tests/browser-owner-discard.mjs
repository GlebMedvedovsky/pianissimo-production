import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

const root=path.resolve(import.meta.dirname,'../..');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-qa.json'),'utf8'));
const state=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-owner-discard-state.json'),'utf8'));
const browser=await chromium.launch({channel:'chrome',headless:true});const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(60000);const checks=[];
try{
 await page.goto('http://127.0.0.1:8080/admin/login');await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);await page.getByRole('button',{name:'Anmelden',exact:true}).click();await page.waitForURL('**/admin/dashboard');
 await page.goto('http://127.0.0.1:8080/admin/publication');await page.getByText('Die Änderungen wurden geprüft und können übernommen werden.',{exact:true}).waitFor();checks.push('current review prepared');
 await page.goto('http://127.0.0.1:8080/admin/about');const biography=page.locator('textarea').first();await biography.waitFor();assert.equal(await biography.inputValue(),state.firstBiography);await biography.fill(state.firstBiography+' Browser discard change');await page.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await page.getByText('Entwurf gespeichert',{exact:true}).waitFor();checks.push('About draft saved');
 await page.goto('http://127.0.0.1:8080/admin/publication');await page.getByRole('button',{name:'Nicht veröffentlichte Änderungen verwerfen',exact:true}).waitFor();await page.getByText('Die geprüften Änderungen sind nicht mehr aktuell.',{exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'Für Website übernehmen',exact:true}).count(),0);checks.push('discard visible and old review blocked');
 await page.getByRole('button',{name:'Nicht veröffentlichte Änderungen verwerfen',exact:true}).click();await page.getByText('Diese Änderungen gehen verloren:',{exact:false}).waitFor();await page.getByText(/Website-Einstellungen werden/).waitFor();await page.getByRole('button',{name:/Bestätigen|Nicht veröffentlichte Änderungen verwerfen/}).last().click();await page.getByText('Nicht veröffentlichte Änderungen verworfen',{exact:true}).waitFor();checks.push('confirmation lists restored categories');
 await page.goto('http://127.0.0.1:8080/admin/about');assert.equal(await page.locator('textarea').first().inputValue(),state.firstBiography);assert.equal(hashFile(path.join(root,'content/manifest.json')),state.frontendSha256);checks.push('About restored and frontend unchanged');
 await page.goto('http://127.0.0.1:8080/admin/publication');await page.getByText('Die geprüften Änderungen sind nicht mehr aktuell.',{exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'Für Website übernehmen',exact:true}).count(),0);checks.push('stale review remains non-applicable');
 console.log(JSON.stringify({passed:checks.length,checks},null,2));
}finally{await browser.close();}
function hashFile(file){return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');}
