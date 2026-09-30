import {build} from 'esbuild';
import {spawnSync} from 'node:child_process';
import {mkdirSync,readdirSync} from 'node:fs';
import {resolve,basename} from 'node:path';
const test=process.argv[2]==='--test';
const entries=test?readdirSync('tests/unit').filter(x=>x.endsWith('.test.ts')).map(x=>'tests/unit/'+x):[process.argv[2]];
mkdirSync('.cache/runtime',{recursive:true});
const outputs=[];
for(const entry of entries){const out=resolve('.cache/runtime',basename(entry,'.ts')+'.mjs');await build({entryPoints:[entry],outfile:out,bundle:true,platform:'node',format:'esm',packages:'external',logLevel:'warning'});outputs.push(out);}
const result=spawnSync(process.execPath,test?['--test','--test-concurrency=1',...outputs]:[...outputs,...process.argv.slice(3)],{stdio:'inherit'});
process.exitCode=result.status??1;
