import {chromium} from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root=path.resolve(import.meta.dirname,'../..');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'backend/storage/app/private/browser-qa.json'),'utf8'));
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(60000);const checks=[];
try{
 await page.goto('http://127.0.0.1:8080/admin/login');await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);await page.getByRole('button',{name:'Anmelden',exact:true}).click();await page.waitForURL('**/admin/dashboard');
 await page.getByText('Website-Status',{exact:true}).waitFor();const dashboard=await page.locator('main').innerText();assert(!/4321|Docker|Manifest|Plan-Hash|Au milieu|Kinnhakenhank/.test(dashboard));await page.getByRole('link',{name:'Änderungen veröffentlichen',exact:true}).waitFor();checks.push('Owner Dashboard cards, computed hints and clean non-technical copy');
 const helpLink=page.getByRole('link',{name:'Hilfe / Handbuch',exact:true}).first();const helpUrl=await helpLink.getAttribute('href');await helpLink.click();await page.getByPlaceholder('Im Handbuch suchen...').fill('Canonical');await page.getByText('Ein neues Bild hochladen',{exact:true}).waitFor({state:'hidden'});await page.getByText('Canonical URL',{exact:true}).waitFor();await page.getByPlaceholder('Im Handbuch suchen...').fill('unauffindbarer-begriff');await page.getByText('Keine passenden Artikel',{exact:true}).waitFor();checks.push('Help search and no-result state');
 await page.goto('http://127.0.0.1:8080/admin/seo');await page.getByText('Hinweis zur späteren Veröffentlichung',{exact:true}).waitFor();await page.getByText(/Bestimmt, wie Seitentitel/).waitFor();checks.push('SEO guidance and noindex safeguard');
 await page.goto('http://127.0.0.1:8080/admin/analytics');await page.getByText('Keine Besucherstatistik',{exact:true}).waitFor();assert.equal(await page.getByText('Es werden keine Besucherstatistiken erhoben.',{exact:true}).count(),1);checks.push('Analytics none-state guidance');
 await page.goto('http://127.0.0.1:8080/admin/publication');await page.getByRole('button',{name:'Paket prüfen',exact:true}).waitFor();await page.getByRole('button',{name:'Vorschau erstellen',exact:true}).waitFor();const publication=await page.locator('main').innerText();assert(!/media\/cms|PowerShell|localhost|Netcup/.test(publication));checks.push('Publication owner copy retains explicit actions');
 const ownerPaths=['/admin/dashboard','/admin/media-assets','/admin/projects','/admin/tracks','/admin/about','/admin/cv-entries','/admin/social','/admin/seo','/admin/consent','/admin/analytics','/admin/publication','/admin/help'];
 await page.setViewportSize({width:390,height:844});
 for(const path of ownerPaths){const response=await page.goto(`http://127.0.0.1:8080${path}`);assert(response?.ok(),`${path} did not load`);await page.locator('main').waitFor();assert(await page.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${path} overflows at 390px`);}
 checks.push('All primary owner pages load without 390px horizontal overflow');
 console.log(JSON.stringify({passed:checks.length,checks},null,2));
}finally{await browser.close();}
