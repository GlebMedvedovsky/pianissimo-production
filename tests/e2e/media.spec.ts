import {test,expect} from '@playwright/test';import fixtures from '../fixtures/audio.json' with {type:'json'};
declare global{interface Window{__mediaTest:{coordinator:import('../../frontend/src/lib/media/coordinator').MediaCoordinator;select:(id:string,queue:import('../../frontend/src/lib/media/coordinator').Playable[])=>Promise<void>}}}
test('real HTMLAudioElement fixtures, queue, seek, pause policy and source exclusivity',async({page})=>{
 await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.getByRole('button',{name:'Necessary only',exact:true}).click();
 await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);
 const compact=page.locator('.player-secondary');
 await expect(page.locator('.player')).toBeVisible();await expect(page.getByRole('button',{name:'Pause',exact:true})).toBeVisible();
 await expect(page.getByRole('button',{name:'More player controls',exact:true})).toHaveCount(0);
 await expect(compact.getByLabel('Volume')).toBeVisible();
 await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.currentTime)).toBeGreaterThan(.15);
 await page.locator('[data-player-seek]').fill('40');await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.currentTime)).toBeGreaterThan(2);
 await compact.getByRole('button',{name:'Next track'}).click();await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 2');
 await compact.getByRole('button',{name:'Shuffle',exact:true}).click();await expect(compact.locator('[data-player=shuffle]')).toHaveAttribute('aria-pressed','true');
 await compact.getByRole('button',{name:'Previous track'}).click();await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 1');
 await page.getByRole('button',{name:'Documentary',exact:true}).click();await expect(page.getByRole('button',{name:'Pause',exact:true})).toBeVisible();
 await page.locator('header [data-about]').click();await expect(page.locator('#about')).toBeInViewport();await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.state.playing)).toBe(false);
 await page.locator('.back-menu').click();await page.locator('header a[href="/film-music/"]').click();await expect(page.getByRole('button',{name:'Play',exact:true})).toBeVisible();await page.getByRole('button',{name:'Play',exact:true}).click();
 await page.evaluate(()=>window.__mediaTest.coordinator.activateExternal());await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.paused)).toBe(true);
 await page.evaluate(()=>window.__mediaTest.coordinator.exposePanel());await page.getByRole('button',{name:'Close player'}).click();await expect(page.locator('.player')).not.toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);await page.screenshot({path:'docs/qa/test-mobile-player-closed.png'});
});
test('the shared player moves into a project dialog and returns after close',async({page})=>{await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);const player=page.locator('.player');await expect(player).toBeVisible();await page.locator('[data-project-card]').first().click();await expect(page.locator('#project-dialog')).toBeVisible();await expect(page.locator('#project-dialog-player-slot > .player')).toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);await page.keyboard.press('Escape');await expect(page.locator('#project-dialog')).not.toBeVisible();await expect(page.locator('#media-root > .player')).toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);});
test('the compact player keeps contrast in dark and light themes',async({page})=>{for(const [path,theme] of [['/film-music/','dark'],['/directing/','light']] as const){await page.goto(path);await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);await expect(page.locator('html')).toHaveAttribute('data-theme',theme);await expect(page.locator('.player')).toBeVisible();const colors=await page.evaluate(()=>{const player=getComputedStyle(document.querySelector('.player')!),page=getComputedStyle(document.body);return{player:player.backgroundColor,page:page.backgroundColor,text:player.color};});expect(colors.player).not.toBe(colors.page);expect(colors.text).not.toBe(colors.player);}});
test('mobile Showreel player uses two rows with direct volume at 320, 375 and 390px',async({page},info)=>{
 await page.goto('/');const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});if(await keepOff.isVisible())await keepOff.click();await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 for(const width of [320,375,390]){
  await page.setViewportSize({width,height:740});
  const player=page.locator('.player');await expect(player).toBeVisible();await expect(page.getByRole('button',{name:'More player controls'})).toHaveCount(0);await expect(player.locator('.player-secondary [data-player-volume]')).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  const geometry=await player.evaluate(root=>{const box=(selector:string)=>root.querySelector(selector)!.getBoundingClientRect();const track=box('.player-track'),mute=box('.player-secondary [data-player=mute]'),volume=box('.player-secondary [data-player-volume]'),play=box('[data-player=toggle]'),close=box('[data-player=close]'),progress=box('.player-progress'),player=root.getBoundingClientRect();return{row:[track,mute,volume,play,close].map(item=>item.top+item.height/2),labelToSound:mute.left-track.right,volumeWidth:volume.width,progress:progress.top,firstBottom:Math.max(track.bottom,mute.bottom,volume.bottom,play.bottom,close.bottom),height:player.height}});
  expect(Math.max(...geometry.row)-Math.min(...geometry.row)).toBeLessThanOrEqual(3);expect(geometry.labelToSound).toBeLessThanOrEqual(5);expect(geometry.volumeWidth).toBeGreaterThanOrEqual(80);expect(geometry.progress).toBeGreaterThanOrEqual(geometry.firstBottom-1);expect(geometry.height).toBeLessThan(110);
  const targets=await player.locator('[data-player=toggle],[data-player=close],.player-secondary [data-player=mute]').evaluateAll(elements=>elements.map(element=>{const box=element.getBoundingClientRect();return{width:box.width,height:box.height}}));
  targets.forEach(target=>{expect(target.width).toBeGreaterThanOrEqual(40);expect(target.height).toBeGreaterThanOrEqual(40)});
  await page.screenshot({path:info.outputPath(`compact-player-${width}.png`)});
 }
});
test('showreel mute and volume remain authoritative across iOS-style change, loop, seek and restart paths',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});if(await keepOff.isVisible())await keepOff.click();
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 const hero=page.locator('[data-slot-video=hero]');
 const slider=page.locator('.player-secondary [data-player-volume]');
 const mute=page.locator('.player-secondary [data-player=mute]');
 await slider.fill('0.35');
 await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.volumeLevel)).toBeCloseTo(.35,5);
 await slider.evaluate(input=>{const range=input as HTMLInputElement;range.value='.42';range.dispatchEvent(new Event('change',{bubbles:true}));});
 await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.volumeLevel)).toBeCloseTo(.42,5);
 await slider.fill('0.35');
 await mute.click();
 await expect(mute).toHaveAttribute('aria-pressed','true');
 await expect(slider).toHaveValue('0.35');
 expect(await page.evaluate(()=>({muted:window.__mediaTest.coordinator.muted,volume:window.__mediaTest.coordinator.volumeLevel}))).toEqual({muted:true,volume:.35});
 await hero.evaluate(video=>{for(const type of ['seeking','seeked','timeupdate','play','playing'])video.dispatchEvent(new Event(type));});
 expect(await page.evaluate(()=>({muted:window.__mediaTest.coordinator.muted,volume:window.__mediaTest.coordinator.volumeLevel}))).toEqual({muted:true,volume:.35});
 await page.evaluate(()=>window.__mediaTest.coordinator.restartShowreel(document.querySelector<HTMLVideoElement>('[data-slot-video=hero]')!));
 await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.muted)).toBe(true);
 await expect(slider).toHaveValue('0.35');
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await expect(page.locator('.player-secondary [data-player=mute]')).toHaveAttribute('aria-pressed','false');
 expect(await page.evaluate(()=>({muted:window.__mediaTest.coordinator.muted,volume:window.__mediaTest.coordinator.volumeLevel}))).toEqual({muted:false,volume:.35});
});
test('desktop transport aligns optically, shuffle uses accent only, and next mirrors previous',async({page},info)=>{
 await page.setViewportSize({width:1440,height:900});await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);
 const player=page.locator('.player');
 const centers=await player.evaluate(root=>{const center=(selector:string)=>{const box=root.querySelector(selector)!.getBoundingClientRect();return box.top+box.height/2};return{timeline:center('[data-player-seek]'),previous:center('.player-buttons [data-player=previous]'),next:center('.player-buttons [data-player=next]'),shuffle:center('.player-buttons [data-player=shuffle]'),volume:center('.player-more [data-player=mute]')}});
 for(const value of [centers.previous,centers.next,centers.shuffle,centers.volume])expect(Math.abs(value-centers.timeline)).toBeLessThanOrEqual(4);
 const icons=await player.evaluate(root=>{const previous=root.querySelector('[data-player=previous] path')!,next=root.querySelector('[data-player=next] path')!;return{previous:previous.getAttribute('d'),next:next.getAttribute('d'),transform:next.getAttribute('transform')}});expect(icons.next).toBe(icons.previous);expect(icons.transform).toBe('translate(24 0) scale(-1 1)');
 const shuffle=player.locator('.player-buttons [data-player=shuffle]');await shuffle.click();const active=await shuffle.evaluate(element=>({color:getComputedStyle(element).color,outline:getComputedStyle(element).outlineStyle,background:getComputedStyle(element).backgroundColor}));expect(active.color).toBe('rgb(166, 106, 63)');expect(active.outline).toBe('none');expect(active.background).toBe('rgba(0, 0, 0, 0)');
 await page.keyboard.press('Tab');await shuffle.focus();await expect(shuffle).toHaveCSS('outline-style','solid');
 await page.screenshot({path:info.outputPath('desktop-player-focus.png')});
});
test('fixture error, rapid selection, natural ending and normal-build isolation',async({page,request})=>{await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await Promise.all([window.__mediaTest.select(tracks[0]!.id,tracks),window.__mediaTest.select(tracks[1]!.id,tracks)]);},fixtures);await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 2');await page.screenshot({path:'docs/qa/test-mobile-player.png'});await page.evaluate(()=>{const c=window.__mediaTest.coordinator;c.seek(.98)});await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.state.playing)).toBe(false);await page.evaluate(async()=>{await window.__mediaTest.coordinator.select({id:'broken',title:'TEST missing file',url:'/__fixtures/missing.wav'},[])});await expect(page.locator('.player-error')).toBeVisible();expect((await request.get('http://127.0.0.1:4321/__fixtures/synthetic-tone-1.wav')).status()).toBe(404);await page.goto('http://127.0.0.1:4321/film-music/');expect(await page.evaluate(()=>typeof window.__mediaTest)).toBe('undefined');});
test('header logo restarts the persisted showreel after a real navigation',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});
 if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');
 await expect(hero).toHaveCount(1);
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await page.locator('.player-secondary [data-player-volume]').fill('0.41');
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);
 await page.locator('header a[href="/directing/"]').click();
 await expect(page).toHaveURL(/\/directing\/$/);
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 await page.locator('header [data-brand-home]').click();
 await expect(page).toHaveURL(/\/$/);
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>!video.paused&&video.currentTime<1)).toBe(true);
 await expect(page.locator('.player')).toBeVisible();
 expect(await page.evaluate(()=>({muted:window.__mediaTest.coordinator.muted,volume:window.__mediaTest.coordinator.volumeLevel}))).toEqual({muted:false,volume:.41});
});
test('ordinary returns resume only navigation-paused Showreel and restore its panel',async({page})=>{
 test.setTimeout(90_000);
 await page.goto('/');const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');await page.getByRole('button',{name:'Enable sound',exact:true}).click();await page.locator('.player-secondary [data-player-volume]').fill('0.37');
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);await expect(page.locator('.player')).toBeVisible();
 for(const path of ['/film-music/','/directing/']){
  const before=await hero.evaluate((video:HTMLVideoElement)=>video.currentTime);await page.locator(`header a[href="${path}"]`).click();await expect(page).toHaveURL(new RegExp(`${path.replaceAll('/','\\/')}?$`));await expect(page.locator('.player')).not.toBeVisible();await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
  await page.goBack();await expect(page).toHaveURL(/\/$/);await expect.poll(()=>hero.evaluate((video:HTMLVideoElement,time)=>!video.paused&&video.currentTime>=time,before)).toBe(true);await expect(page.locator('.player')).toBeVisible();expect(await page.evaluate(()=>({muted:window.__mediaTest.coordinator.muted,volume:window.__mediaTest.coordinator.volumeLevel}))).toEqual({muted:false,volume:.37});
 }
 await page.locator('header [data-about]').click();await expect(page.locator('#about')).toBeInViewport();await expect(page.locator('.player')).not.toBeVisible();await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 await page.locator('.back-menu').click();await page.evaluate(()=>scrollTo({top:0,behavior:'instant'}));await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);await expect(page.locator('.player')).toBeVisible();
 await page.locator('.player [data-player=toggle]').click();await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 const pausedAt=await hero.evaluate((video:HTMLVideoElement)=>video.currentTime);await page.locator('header a[href="/film-music/"]').click();await expect(page).toHaveURL(/\/film-music\/$/);await page.goBack();await expect(page).toHaveURL(/\/(?:#main-menu)?$/);await page.waitForTimeout(400);expect(await hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);expect(await hero.evaluate((video:HTMLVideoElement)=>video.currentTime)).toBeCloseTo(pausedAt,1);await expect(page.locator('.player')).toBeVisible();
});
test('showreel leaves Film Music while a selected music track still opens the player',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});
 if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);
 await page.locator('header a[href="/film-music/"]').click();
 await expect(page).toHaveURL(/\/film-music\/$/);
 await expect(page.locator('.player')).not.toBeVisible();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 await page.locator('header a[href="/directing/"]').click();
 await expect(page).toHaveURL(/\/directing\/$/);
 await page.locator('header a[href="/film-music/"]').click();
 await expect(page).toHaveURL(/\/film-music\/$/);
 await expect(page.locator('.player')).not.toBeVisible();
 await page.locator('[data-play-track]').first().click();
 await expect(page.locator('.player')).toBeVisible();
});
test('About hides and pauses a sounding showreel before a header-logo restart',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});
 if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);
 await page.locator('header [data-about]').click();
 await expect(page.locator('#about')).toBeInViewport();
 await expect(page.locator('.player')).not.toBeVisible();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 await page.locator('header [data-brand-home]').click();
 await expect(page).toHaveURL(/\/$/);
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>!video.paused&&video.currentTime<1)).toBe(true);
});
test('scrolling into About fades and hides a sounding showreel',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});
 if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);
 await page.mouse.wheel(0,10000);
 await expect(page.locator('#about')).toBeInViewport();
 await expect(page.locator('.player')).not.toBeVisible();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
});

