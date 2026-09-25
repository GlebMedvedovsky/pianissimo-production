import {chromium} from '@playwright/test';import {mkdir,writeFile} from 'node:fs/promises';import assert from 'node:assert/strict';
const out='docs/qa/02D';await mkdir(out,{recursive:true});const browser=await chromium.launch({channel:'chrome',headless:true});
async function run(name,mobile){
 const context=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:900},hasTouch:mobile,isMobile:mobile});await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{
  document.addEventListener('DOMContentLoaded',()=>{
   const video=document.querySelector('[data-slot-video=hero]');if(!video)return;
   const m=window.__loopQA={startWall:performance.now(),firstFrame:null,wraps:[],events:[],frames:[],sourceMutations:[],rates:[],initialMuted:video.muted};let lastTime=-1,lastWall=0,nearEndCaptured=-1;
   new MutationObserver(records=>m.sourceMutations.push(...records.map(r=>({at:performance.now(),attribute:r.attributeName})))).observe(video,{attributes:true,attributeFilter:['src']});
   for(const type of ['loadstart','loadedmetadata','waiting','stalled','pause','play','ended','seeking','seeked','error','ratechange'])video.addEventListener(type,()=>m.events.push({type,wall:performance.now(),time:video.currentTime,muted:video.muted}));
   const capture=(label)=>{const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;canvas.getContext('2d').drawImage(video,0,0,640,360);m.frames.push({label,png:canvas.toDataURL('image/png')})};
   function frame(now,meta){
    if(m.firstFrame===null){m.firstFrame=meta.mediaTime;m.firstFrameWall=now}
    if(!m.rates.includes(video.playbackRate))m.rates.push(video.playbackRate);
    if(meta.mediaTime>video.duration-.2&&nearEndCaptured!==m.wraps.length){capture(`cycle-${m.wraps.length+1}-end`);nearEndCaptured=m.wraps.length}
    if(lastTime>video.duration-1&&meta.mediaTime<1){const logo=document.querySelector('.scene-logo').getBoundingClientRect();m.wraps.push({from:lastTime,to:meta.mediaTime,gapMs:now-lastWall,wall:now,duration:video.duration,muted:video.muted,paused:video.paused,sameElement:video===document.querySelector('[data-slot-video=hero]'),scrollY,width:video.clientWidth,height:video.clientHeight,logo:{x:logo.x,y:logo.y,width:logo.width,height:logo.height}});capture(`cycle-${m.wraps.length+1}-start`)}
    lastTime=meta.mediaTime;lastWall=now;video.requestVideoFrameCallback(frame);
   }video.requestVideoFrameCallback(frame);
  },{once:true});
 });
 await page.goto('http://127.0.0.1:4321/');await page.waitForFunction(()=>window.__loopQA?.firstFrame!==null&&document.querySelector('[data-slot-video=hero]').currentTime>.1);
 assert.ok(await page.locator('[data-slot-video=hero]').evaluate(v=>v.muted&&v.loop&&!v.paused));
 await page.getByRole('button',{name:'Necessary only',exact:true}).click();await page.locator('[data-showreel-sound]').click();await page.waitForFunction(()=>!document.querySelector('[data-slot-video=hero]').muted);await page.screenshot({path:`${out}/${name}-loop-start-ui.png`});
 console.log(name,'natural loop observation started');
 await page.waitForFunction(()=>window.__loopQA.wraps.length>=3,undefined,{timeout:285000,polling:1000});
 await page.screenshot({path:`${out}/${name}-loop-finish-ui.png`});const result=await page.evaluate(()=>window.__loopQA);const frames=result.frames;delete result.frames;
 result.browser=await browser.version();result.environment=mobile?'Chrome mobile emulation; not a physical phone':'Chrome desktop headless';result.errors=errors;
 assert.deepEqual(result.rates,[1]);assert.ok(result.firstFrame<.2);assert.equal(result.wraps.length,3);assert.ok(result.wraps.every(w=>w.sameElement&&!w.paused&&!w.muted));assert.equal(result.sourceMutations.length,0);assert.deepEqual(errors,[]);
 const video=page.locator('[data-slot-video=hero]');await page.locator('[data-player=toggle]').click();const time=await video.evaluate(v=>v.currentTime);await page.waitForTimeout(1700);assert.ok(await video.evaluate((v,t)=>v.paused&&Math.abs(v.currentTime-t)<.1,time));result.manualPause='passed';
 await page.locator('.site-header a[href="/directing/"]').click();await page.waitForURL('**/directing/');assert.ok(await video.evaluate(v=>v.paused));await page.locator('.site-header [data-about]').click();await page.waitForURL('**/#about');await page.waitForTimeout(500);assert.ok(await video.evaluate(v=>v.paused));result.transitionsAfterPause='Directing and About remain paused';
 await writeFile(`${out}/${name}-loop.json`,JSON.stringify(result,null,2));for(const f of frames)await writeFile(`${out}/${name}-${f.label}.png`,Buffer.from(f.png.split(',')[1],'base64'));console.log(name,JSON.stringify({wraps:result.wraps.map(w=>({from:w.from,to:w.to,gapMs:w.gapMs})),events:result.events.filter(e=>['stalled','waiting','error'].includes(e.type)),manualPause:result.manualPause}));await context.close();return result;
}
try{await Promise.all([run('desktop',false),run('mobile',true)])}finally{await browser.close()}
