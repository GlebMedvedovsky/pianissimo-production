import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
// Astro's installed build CLI supports --outDir. No preview config is changed.
const out=resolve('.local-builds/02D/dist');
for(const args of [
 ['scripts/run-ts.mjs','scripts/validate-content.ts'],
 ['scripts/astro.mjs','build','--root','frontend','--outDir',out],
 ['scripts/check-dist.mjs',out],
]){const r=spawnSync(process.execPath,args,{stdio:'inherit',env:{...process.env,ASTRO_TELEMETRY_DISABLED:'1'}});if(r.status!==0)process.exit(r.status??1)}
