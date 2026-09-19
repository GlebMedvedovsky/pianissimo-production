import {readdir,readFile} from 'node:fs/promises';
const root=process.argv[2]||'frontend/dist';const files=[];
async function walk(dir){for(const entry of await readdir(dir,{withFileTypes:true})){const path=dir+'/'+entry.name;if(entry.isDirectory())await walk(path);else files.push(path)}}
await walk(root);
const forbidden=files.filter(p=>/\.pdf$|Sebastian[34]?\.png|test-adapter|fixtures|\.php$|\.env/i.test(p));
for(const file of files.filter(p=>/\.(js|html|json)$/.test(p))){const text=await readFile(file,'utf8');if(/eyXBrA1LUsI|FINAL_KHH|secret_token|soundcloud[^\s]*\/s-|Pianissimo_Fragen|entity_address|admin_email|__mediaTest|synthetic-tone|D:\\\\Projects|\/tests\/fixtures\//.test(text))forbidden.push(file);}
if(forbidden.length)throw Error('Private/test artifacts in build: '+forbidden.join(', '));
console.log(`Build isolation passed: ${files.length} public files; no original PDF, source portraits, server code or audio fixtures.`);
