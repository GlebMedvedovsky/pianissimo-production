import {chromium} from '@playwright/test';import {writeFile} from 'node:fs/promises';import assert from 'node:assert/strict';
const browser=await chromium.launch({channel:'chrome',headless:true}),results=[];
try{for(const mode of ['autoplay-rejected','media-error']){
 const c=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});await c.route('**/*',r=>{const u=new URL(r.request().url());if(u.hostname!=='127.0.0.1'||(mode==='media-error'&&u.pathname.endsWith('local-f-27d30882cf7b7bde.mp4')))return r.abort();return r.continue()});
 if(mode==='autoplay-rejected')await c.addInitScript(()=>{const play=HTMLMediaElement.prototype.play;let rejected=false;HTMLMediaElement.prototype.play=function(){if(!rejected&&this.dataset.slotVideo==='hero'){rejected=true;return Promise.reject(new DOMException('Isolated autoplay fixture','NotAllowedError'))}return play.call(this)}});
 const p=await c.newPage();await p.goto('http://127.0.0.1:4321/',{waitUntil:'domcontentloaded'});await p.waitForTimeout(1000);assert.ok(await p.locator('[data-slot-video=hero]').evaluate(v=>v.paused));assert.ok(await p.locator('[data-slot=hero] img').evaluate(i=>i.naturalWidth>0));assert.ok(await p.locator('[data-hero-toggle]').isVisible());await p.screenshot({path:`docs/qa/02D/${mode}.png`});
 if(mode==='autoplay-rejected'){await p.locator('[data-hero-toggle]').click();await p.waitForFunction(()=>document.querySelector('[data-slot-video=hero]').currentTime>0)}else assert.match(await p.locator('[data-hero-status]').textContent(),/unavailable/);
 results.push({mode,status:'passed'});await c.close();
}}finally{await writeFile('docs/qa/02D/fallback.json',JSON.stringify(results,null,2));await browser.close()}console.log(results);
