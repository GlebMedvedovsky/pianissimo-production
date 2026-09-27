import {test,expect} from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const root=path.resolve(import.meta.dirname,'../..');
const digest=(file:string)=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const managedState=()=>{
 const directory=path.join(root,'frontend/public/media/cms');
 const out:Record<string,string>={};
 const visit=(current:string)=>{for(const entry of fs.readdirSync(current,{withFileTypes:true})){const full=path.join(current,entry.name);if(entry.isDirectory())visit(full);else out[path.relative(directory,full).replaceAll('\\','/')]=digest(full);}};
 if(fs.existsSync(directory))visit(directory);return out;
};

test('saved Draft preview uses the real site, stays private and never mutates active frontend',async({page},info)=>{
 test.skip(info.project.name!=='desktop','single authenticated desktop/mobile preview flow');
 test.setTimeout(300_000);
 const qaCredentials=path.join(root,'backend/storage/app/private/browser-qa.json');
 const credentials=JSON.parse(fs.readFileSync(fs.existsSync(qaCredentials)?qaCredentials:path.join(root,'backend/storage/app/private/local-admin.json'),'utf8')) as {email:string;password:string};
 const manifest=path.join(root,'content/manifest.json');const manifestBefore=digest(manifest);const mediaBefore=managedState();
 await page.goto('http://127.0.0.1:8080/admin/login');
 await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);
 await page.getByRole('button',{name:'Anmelden',exact:true}).click();await page.waitForURL('**/admin/dashboard');
 await page.goto('http://127.0.0.1:8080/admin/about');const biography=page.locator('textarea').first();const original=await biography.inputValue();const unique=`Preview connectivity QA ${Date.now()}`;
 const saveAbout=async()=>{const saved=page.waitForResponse(response=>/\/livewire-[^/]+\/update$/.test(new URL(response.url()).pathname)&&response.request().method()==='POST');await page.getByRole('button',{name:'Entwurf speichern',exact:true}).click();expect((await saved).ok()).toBeTruthy();};
 try{
  await biography.fill(unique);await saveAbout();
  const active=await page.context().newPage();await active.goto('http://127.0.0.1:4321/',{waitUntil:'domcontentloaded',timeout:60_000});await expect(active.getByText(unique,{exact:true})).toHaveCount(0);await active.close();
  await page.goto('http://127.0.0.1:8080/admin/publication');
  const popupPromise=page.waitForEvent('popup');await page.getByRole('link',{name:'Website-Vorschau öffnen',exact:true}).click();const preview=await popupPromise;
  const externalRequests:string[]=[];preview.on('request',request=>{if(/google-analytics|googletagmanager|plausible|youtube|vimeo/.test(request.url()))externalRequests.push(request.url());});
  await preview.waitForLoadState('domcontentloaded');await expect(preview.locator('html')).toHaveAttribute('data-draft-preview','true');
  await expect(preview.getByText('Entwurfs-Vorschau · Nicht veröffentlicht',{exact:true})).toBeVisible();await preview.goto(`${preview.url()}#about`,{waitUntil:'domcontentloaded',timeout:120_000});await expect(preview.getByText(unique,{exact:true})).toBeVisible({timeout:30_000});
  expect(preview.url()).not.toContain('token=');expect(preview.url()).toContain('/draft-preview/site');
  await expect(preview.locator('iframe')).toHaveCount(0);expect(externalRequests).toEqual([]);
  await preview.setViewportSize({width:390,height:844});await expect(preview.getByText('Entwurfs-Vorschau · Nicht veröffentlicht',{exact:true})).toBeVisible();
  expect(digest(manifest)).toBe(manifestBefore);expect(managedState()).toEqual(mediaBefore);
 }finally{
  await page.goto('http://127.0.0.1:8080/admin/about');await page.locator('textarea').first().fill(original);await saveAbout();
 }
});

test('current assigned Draft media renders only in the private preview',async({page},info)=>{
 test.skip(info.project.name!=='desktop','single authenticated runtime check');
 const mediaId=process.env.DRAFT_PREVIEW_MEDIA_ID;
 test.skip(!mediaId,'set DRAFT_PREVIEW_MEDIA_ID to verify the current CMS Draft without mutating it');
 test.setTimeout(180_000);
 const qaCredentials=path.join(root,'backend/storage/app/private/browser-qa.json');
 const credentials=JSON.parse(fs.readFileSync(fs.existsSync(qaCredentials)?qaCredentials:path.join(root,'backend/storage/app/private/local-admin.json'),'utf8')) as {email:string;password:string};
 const manifest=path.join(root,'content/manifest.json');const manifestBefore=digest(manifest);const mediaBefore=managedState();
 await page.goto('http://127.0.0.1:8080/admin/login');
 await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);
 await page.getByRole('button',{name:'Anmelden',exact:true}).click();await page.waitForURL('**/admin/dashboard');
 await page.goto('http://127.0.0.1:8080/admin/publication');
 const popupPromise=page.waitForEvent('popup');await page.getByRole('link',{name:'Website-Vorschau öffnen',exact:true}).click();const preview=await popupPromise;
 await preview.waitForLoadState('domcontentloaded');await expect(preview.locator('html')).toHaveAttribute('data-draft-preview','true');
 const image=preview.locator(`img[src*="/media/preview/${mediaId}."]`);await expect(image).toHaveCount(1);await image.scrollIntoViewIfNeeded();
 const mediaResponse=await image.evaluate(async(element:HTMLImageElement)=>{const response=await fetch(element.src,{credentials:'same-origin'});return {status:response.status,type:response.headers.get('content-type'),bytes:(await response.arrayBuffer()).byteLength};});
 expect(mediaResponse).toMatchObject({status:200,type:'image/png'});expect(mediaResponse.bytes).toBeGreaterThan(0);
 await expect.poll(()=>image.evaluate((element:HTMLImageElement)=>element.complete&&element.naturalWidth>0)).toBe(true);
 expect(await image.getAttribute('src')).toMatch(new RegExp(`/media/preview/${mediaId}\\.(?:webp|jpg|png)$`));
 expect(preview.url()).not.toContain('token=');
 const active=await page.context().newPage();await active.goto('http://127.0.0.1:4321/#about',{waitUntil:'domcontentloaded',timeout:60_000});
 await expect(active.locator(`img[src*="${mediaId}"]`)).toHaveCount(0);await active.close();
 expect(digest(manifest)).toBe(manifestBefore);expect(managedState()).toEqual(mediaBefore);
});
