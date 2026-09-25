import {test,expect} from '@playwright/test';import fixtures from '../fixtures/audio.json' with {type:'json'};
declare global{interface Window{__mediaTest:{coordinator:import('../../frontend/src/lib/media/coordinator').MediaCoordinator;select:(id:string,queue:import('../../frontend/src/lib/media/coordinator').Playable[])=>Promise<void>}}}
test('real HTMLAudioElement fixtures, queue, seek, pause policy and source exclusivity',async({page})=>{await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.getByRole('button',{name:'Necessary only',exact:true}).click();await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);await expect(page.locator('.player')).toBeVisible();await expect(page.getByRole('button',{name:'Pause',exact:true})).toBeVisible();await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.currentTime)).toBeGreaterThan(.15);await page.locator('[data-player-seek]').fill('40');await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.currentTime)).toBeGreaterThan(2);await page.getByRole('button',{name:'More player controls',exact:true}).click();await page.getByRole('button',{name:'Next track'}).click();await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 2');await page.getByRole('button',{name:'More player controls',exact:true}).click();await page.getByRole('button',{name:'Shuffle',exact:true}).click();await expect(page.locator('.player-secondary [data-player=shuffle]')).toHaveAttribute('aria-pressed','true');await page.getByRole('button',{name:'More player controls',exact:true}).click();await page.getByRole('button',{name:'Previous track'}).click();await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 1');await page.getByRole('button',{name:'Documentary',exact:true}).click();await expect(page.getByRole('button',{name:'Pause',exact:true})).toBeVisible();await page.locator('header [data-about]').click();await expect(page.locator('#about')).toBeInViewport();await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.state.playing)).toBe(false);await page.locator('.back-menu').click();await page.locator('header a[href="/film-music/"]').click();await expect(page.getByRole('button',{name:'Play',exact:true})).toBeVisible();await page.getByRole('button',{name:'Play',exact:true}).click();await page.evaluate(()=>window.__mediaTest.coordinator.activateExternal());await expect.poll(()=>page.locator('#shared-audio').evaluate((audio:HTMLAudioElement)=>audio.paused)).toBe(true);await page.evaluate(()=>window.__mediaTest.coordinator.exposePanel());await page.getByRole('button',{name:'Close player'}).click();await expect(page.locator('.player')).not.toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);await page.screenshot({path:'docs/qa/test-mobile-player-closed.png'});});
test('the shared player moves into a project dialog and returns after close',async({page})=>{await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);const player=page.locator('.player');await expect(player).toBeVisible();await page.locator('[data-project-card]').first().click();await expect(page.locator('#project-dialog')).toBeVisible();await expect(page.locator('#project-dialog-player-slot > .player')).toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);await page.keyboard.press('Escape');await expect(page.locator('#project-dialog')).not.toBeVisible();await expect(page.locator('#media-root > .player')).toBeVisible();await expect(page.locator('#shared-audio')).toHaveCount(1);});
test('the compact player keeps contrast in dark and light themes',async({page})=>{for(const [path,theme] of [['/film-music/','dark'],['/directing/','light']] as const){await page.goto(path);await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await window.__mediaTest.select(tracks[0]!.id,tracks)},fixtures);await expect(page.locator('html')).toHaveAttribute('data-theme',theme);await expect(page.locator('.player')).toBeVisible();const colors=await page.evaluate(()=>{const player=getComputedStyle(document.querySelector('.player')!),page=getComputedStyle(document.body);return{player:player.backgroundColor,page:page.backgroundColor,text:player.color};});expect(colors.player).not.toBe(colors.page);expect(colors.text).not.toBe(colors.player);}});
test('fixture error, rapid selection, natural ending and normal-build isolation',async({page,request})=>{await page.goto('/film-music/');await page.waitForFunction(()=>!!window.__mediaTest);await page.evaluate(async tracks=>{await Promise.all([window.__mediaTest.select(tracks[0]!.id,tracks),window.__mediaTest.select(tracks[1]!.id,tracks)]);},fixtures);await expect(page.locator('[data-player-title]')).toContainText('TEST AUDIO 2');await page.screenshot({path:'docs/qa/test-mobile-player.png'});await page.evaluate(()=>{const c=window.__mediaTest.coordinator;c.seek(.98)});await expect.poll(()=>page.evaluate(()=>window.__mediaTest.coordinator.state.playing)).toBe(false);await page.evaluate(async()=>{await window.__mediaTest.coordinator.select({id:'broken',title:'TEST missing file',url:'/__fixtures/missing.wav'},[])});await expect(page.locator('.player-error')).toBeVisible();expect((await request.get('http://127.0.0.1:4321/__fixtures/synthetic-tone-1.wav')).status()).toBe(404);await page.goto('http://127.0.0.1:4321/film-music/');expect(await page.evaluate(()=>typeof window.__mediaTest)).toBe('undefined');});
test('header logo restarts the persisted showreel after a real navigation',async({page})=>{
 await page.goto('/');
 const keepOff=page.getByRole('button',{name:'Necessary only',exact:true});
 if(await keepOff.isVisible())await keepOff.click();
 const hero=page.locator('[data-slot-video=hero]');
 await expect(hero).toHaveCount(1);
 await page.getByRole('button',{name:'Enable sound',exact:true}).click();
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(false);
 await page.locator('header a[href="/directing/"]').click();
 await expect(page).toHaveURL(/\/directing\/$/);
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>video.paused)).toBe(true);
 await page.locator('header [data-brand-home]').click();
 await expect(page).toHaveURL(/\/$/);
 await expect.poll(()=>hero.evaluate((video:HTMLVideoElement)=>!video.paused&&video.currentTime<1)).toBe(true);
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

