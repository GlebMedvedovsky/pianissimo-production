import {chromium} from '@playwright/test';
import {mkdir,writeFile} from 'node:fs/promises';
await mkdir('docs/qa',{recursive:true});
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage({viewport:{width:1440,height:900}});const errors=[];
page.on('pageerror',e=>errors.push(String(e)));page.on('console',m=>{if(m.type()==='error')errors.push(m.text())});
for(const [name,path]of [['hero','/'],['music','/film-music/'],['directing','/directing/'],['about','/#about']]){await page.goto('http://127.0.0.1:4321'+path);await page.waitForTimeout(600);await page.screenshot({path:`docs/qa/smoke-${name}.png`});console.log(name,await page.title(),await page.locator('body').innerText().then(x=>x.slice(0,150)));}
await writeFile('docs/qa/smoke-errors.json',JSON.stringify(errors,null,2));console.log({errors});await browser.close();
