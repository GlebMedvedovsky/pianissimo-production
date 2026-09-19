import { chromium } from '@playwright/test';
import { writeFile } from 'node:fs/promises';
const browser = await chromium.launch({channel:'chrome', headless:true});
const page = await browser.newPage({viewport:{width:1440,height:900}});
const results=[];
for (const [name,url] of Object.entries({pianissimo:'https://pianissimo-production.de/',hauschka:'https://hauschka-net.de/#projects-volker-bertelmann',brenner:'https://brenner-images.de/',vincent:'https://vincentruhl.com/index.html'})) {
 try {
  await page.goto(url,{waitUntil:'domcontentloaded',timeout:25000});
  await page.screenshot({path:`docs/research/reference-${name}.png`});
  results.push({name,url,title:await page.title(),text:(await page.locator('body').innerText()).slice(0,25000)});
 } catch(e) {results.push({name,url,error:String(e)});}
}
await writeFile('docs/research/browser-references.json',JSON.stringify(results,null,2));
await browser.close();
console.log(results.map(({name,title,error})=>({name,title,error})));
