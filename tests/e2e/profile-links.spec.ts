import {expect,test} from '@playwright/test';

test('CMS profile links, streaming profiles and collective membership render accessibly',async({page})=>{
 await page.goto('/#about',{waitUntil:'domcontentloaded'});
 await expect(page.getByRole('link',{name:'IMDb profile'})).toHaveAttribute('href','https://www.imdb.com/de/name/nm10952014/');
 await expect(page.getByRole('link',{name:'Crew United profile'})).toHaveAttribute('href','https://www.crew-united.com/de/Sebastian-Greim_711574.html');
 await expect(page.getByRole('link',{name:'Music Hub'})).toHaveAttribute('href','https://music-hub.bio/sebastiangreim');
 const about=page.locator('#about');
 await expect(about.getByRole('link',{name:'Spotify artist profile'})).toHaveAttribute('href','https://open.spotify.com/intl-de/artist/1KsDRqYN2Y2Cvf2lVBt5Em');
 await expect(about.getByRole('link',{name:'Tidal artist profile'})).toBeAttached();
 await expect(about.getByRole('link',{name:'Apple Music artist profile'})).toBeAttached();
 await expect(about.getByRole('link',{name:/Proud member of Samples unterm Sofa/})).toHaveAttribute('href','https://samples-unterm-sofa.de/');
 await expect(about.locator('.collective-membership img')).toHaveCount(0);
 const targets=await page.locator('.social-rail > *').evaluateAll(nodes=>nodes.map(node=>{const box=node.getBoundingClientRect();return {width:box.width,height:box.height}}));
 if((await page.viewportSize())!.width<=800) for(const target of targets){expect(target.width).toBeGreaterThanOrEqual(40);expect(target.height).toBeGreaterThanOrEqual(40);}
});
