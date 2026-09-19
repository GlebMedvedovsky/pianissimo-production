import {readFileSync,existsSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {z} from 'zod';
import {manifestSchema,validateManifest} from '../contracts/content';
try {
 const data=validateManifest(JSON.parse(readFileSync('content/manifest.json','utf8')),process.argv.includes('--release'));
 for(const a of data.assets)if(!existsSync(resolve('frontend/public',a.path)))throw Error(`Missing derivative ${a.path}`);
 writeFileSync('contracts/manifest.schema.json',JSON.stringify(z.toJSONSchema(manifestSchema),null,2)+'\n');
 console.log(`Content valid: ${data.projects.length} projects, ${data.tracks.length} tracks, ${data.assets.length} assets (${data.mode}).`);
}catch(e){console.error(e instanceof Error?e.message:e);process.exitCode=1;}
