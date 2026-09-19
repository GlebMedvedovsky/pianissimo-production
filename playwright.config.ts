import {defineConfig} from '@playwright/test';
const label=process.env.QA_LABEL||'dev';
export default defineConfig({testDir:'./tests/e2e',outputDir:`test-results/${label}`,timeout:40000,expect:{timeout:7000},fullyParallel:false,workers:2,reporter:[['list'],['html',{open:'never',outputFolder:`playwright-report/${label}`}],['json',{outputFile:`docs/qa/${label}-results.json`}]],webServer:[{command:'node scripts/astro.mjs dev --root frontend --host 127.0.0.1 --port 4321 --ignore-lock',url:process.env.QA_BASE_URL||'http://127.0.0.1:4321',reuseExistingServer:true},{command:'node scripts/astro.mjs dev --root frontend --host 127.0.0.1 --port 4323 --mode test --ignore-lock',url:'http://127.0.0.1:4323',reuseExistingServer:true}],use:{baseURL:process.env.QA_BASE_URL||'http://127.0.0.1:4321',channel:'chrome',headless:true,trace:'retain-on-failure',screenshot:'only-on-failure'},projects:[
 {name:'desktop',testIgnore:/media\.spec/,use:{viewport:{width:1440,height:900}}},
 {name:'tablet',testIgnore:/media\.spec/,use:{viewport:{width:768,height:1024},hasTouch:true}},
 {name:'mobile',testIgnore:/media\.spec/,use:{viewport:{width:390,height:844},hasTouch:true,isMobile:true}},
 {name:'narrow',testMatch:/layout\.spec/,use:{viewport:{width:320,height:740},hasTouch:true,isMobile:true}},
 {name:'landscape',testMatch:/layout\.spec/,use:{viewport:{width:844,height:390},hasTouch:true,isMobile:true}},
 {name:'media',testMatch:/media\.spec/,use:{baseURL:'http://127.0.0.1:4323',viewport:{width:390,height:844},hasTouch:true,isMobile:true}}
]});
