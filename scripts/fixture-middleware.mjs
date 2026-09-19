import {readFile} from 'node:fs/promises';
export const audioFixtures={name:'isolated-audio-fixtures',apply:'serve',configureServer(server){
 if(server.config.mode!=='test')return;
 server.middlewares.use('/__fixtures/',async(req,res,next)=>{
  const name=req.url?.split('?')[0]?.replace(/^\//,'');
  if(!['synthetic-tone-1.wav','synthetic-tone-2.wav'].includes(name||''))return next();
  try{const buffer=await readFile(new URL('../tests/fixtures/'+name,import.meta.url));res.setHeader('Content-Type','audio/wav');res.setHeader('Accept-Ranges','bytes');
   const range=/^bytes=(\d+)-(\d*)$/.exec(req.headers.range||'');
   if(range){const start=Number(range[1]),end=Math.min(Number(range[2]||buffer.length-1),buffer.length-1);if(start>end){res.statusCode=416;res.end();return;}res.statusCode=206;res.setHeader('Content-Range',`bytes ${start}-${end}/${buffer.length}`);res.setHeader('Content-Length',end-start+1);res.end(buffer.subarray(start,end+1));}
   else{res.setHeader('Content-Length',buffer.length);res.end(req.method==='HEAD'?undefined:buffer);}
  }catch{res.statusCode=404;res.end();}
 });
}};
