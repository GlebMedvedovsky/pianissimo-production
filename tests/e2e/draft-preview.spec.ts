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
const projectGeometry=(page:import('@playwright/test').Page)=>page.evaluate(()=>{
 const box=(selector:string)=>{const rect=document.querySelector(selector)!.getBoundingClientRect();return {x:rect.x,y:rect.y,width:rect.width,height:rect.height}};
 const title=document.querySelector<HTMLElement>('.detail-cover h1')!;const titleStyle=getComputedStyle(title);const introStyle=getComputedStyle(document.querySelector('.detail-intro')!);const range=document.createRange();range.selectNodeContents(title);
 return {
  viewport:{innerWidth,innerHeight,clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth},
  document:{htmlClass:document.documentElement.className,bodyClass:document.body.className,section:document.documentElement.dataset.section,theme:document.documentElement.dataset.theme},
  stylesheets:[...document.styleSheets].map(sheet=>sheet.href).filter((href):href is string=>!!href),
  directProject:box('.direct-project'),projectDetail:box('.project-detail'),cover:box('.detail-cover'),
  intro:{...box('.detail-intro'),columns:introStyle.gridTemplateColumns,gap:introStyle.gap},
  title:{...box('.detail-cover h1'),fontFamily:titleStyle.fontFamily,fontSize:titleStyle.fontSize,lineHeight:titleStyle.lineHeight,fontWeight:titleStyle.fontWeight,letterSpacing:titleStyle.letterSpacing,overflowWrap:titleStyle.overflowWrap,lineRects:range.getClientRects().length},
 };
});

test('saved Draft preview uses the real site, stays private and never mutates active frontend',async({page},info)=>{
 test.skip(info.project.name!=='desktop','single authenticated desktop/mobile preview flow');
 test.setTimeout(600_000);
 const qaCredentials=path.join(root,'backend/storage/app/private/browser-qa.json');
 const credentials=JSON.parse(fs.readFileSync(fs.existsSync(qaCredentials)?qaCredentials:path.join(root,'backend/storage/app/private/local-admin.json'),'utf8')) as {email:string;password:string};
 const manifest=path.join(root,'content/manifest.json');const manifestBefore=digest(manifest);const mediaBefore=managedState();
 await page.goto('http://127.0.0.1:8080/admin/login');
 await page.locator('input[type=email]').fill(credentials.email);await page.locator('input[type=password]').fill(credentials.password);
 await page.getByRole('button',{name:'Anmelden',exact:true}).click();await page.waitForURL('**/admin/dashboard');
 await page.goto('http://127.0.0.1:8080/admin/about');const biography=page.locator('textarea').first();const original=await biography.inputValue();const unique=`Preview connectivity QA B ${Date.now()}`;const next=`Preview connectivity QA C ${Date.now()}`;
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
  expect(new URL(preview.url()).searchParams.get('preview')).toMatch(/^\d+$/);
  await expect(preview.locator('header a[data-astro-reload][href*="/draft-preview/site"]').first()).toBeAttached();
  await expect(preview.locator('iframe')).toHaveCount(0);expect(externalRequests).toEqual([]);

  await preview.setViewportSize({width:1440,height:900});const previewProjectUrl=new URL(preview.url());previewProjectUrl.searchParams.set('route','/projects/kinnhakenhank/');previewProjectUrl.hash='';await preview.goto(previewProjectUrl.toString(),{waitUntil:'domcontentloaded',timeout:120_000});await preview.evaluate(()=>document.fonts.ready);
  const activeProject=await page.context().newPage();await activeProject.setViewportSize({width:1440,height:900});await activeProject.goto('http://127.0.0.1:4321/projects/kinnhakenhank/',{waitUntil:'domcontentloaded',timeout:120_000});await activeProject.evaluate(()=>document.fonts.ready);
  expect(await projectGeometry(preview)).toEqual(await projectGeometry(activeProject));await activeProject.close();

  await page.bringToFront();await page.goto('http://127.0.0.1:8080/admin/about');await page.locator('textarea').first().fill(next);await saveAbout();
  const activeAfterC=await page.context().newPage();await activeAfterC.goto('http://127.0.0.1:4321/',{waitUntil:'domcontentloaded',timeout:60_000});await expect(activeAfterC.getByText(next,{exact:true})).toHaveCount(0);await activeAfterC.close();
  await preview.bringToFront();await preview.getByRole('link',{name:'Film Music',exact:true}).click();
  await expect(preview.getByRole('heading',{name:'Website-Vorschau nicht verfügbar'})).toBeVisible({timeout:30_000});

  await page.bringToFront();await page.goto('http://127.0.0.1:8080/admin/publication');const nextPopup=page.waitForEvent('popup');await page.getByRole('link',{name:'Website-Vorschau öffnen',exact:true}).click();const previewC=await nextPopup;await previewC.waitForLoadState('domcontentloaded');await expect(previewC.getByText(next,{exact:true})).toBeAttached();
  await previewC.getByRole('link',{name:'Film Music',exact:true}).click();await expect(previewC.locator('html')).toHaveAttribute('data-draft-preview','true');
  await previewC.locator('[data-project="kinnhakenhank"]').click();await expect(previewC.getByRole('heading',{name:'Kinnhakenhank',exact:true})).toBeVisible();expect(new URL(previewC.url()).searchParams.get('preview')).toMatch(/^\d+$/);expect(new URL(previewC.url()).searchParams.get('route')).toBe('/projects/kinnhakenhank/');
  const homeUrl=new URL(previewC.url());homeUrl.searchParams.set('route','/');homeUrl.hash='about';await previewC.goto(homeUrl.toString(),{waitUntil:'domcontentloaded',timeout:120_000});await expect(previewC.getByText(next,{exact:true})).toBeAttached();
  await previewC.setViewportSize({width:390,height:844});await expect(previewC.getByText('Entwurfs-Vorschau · Nicht veröffentlicht',{exact:true})).toBeVisible();
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
