import {defineMiddleware} from 'astro:middleware';
import {previewContent} from './lib/content/preview';

const backend=(import.meta.env.CMS_PREVIEW_BACKEND_URL||'http://127.0.0.1:8080').replace(/\/$/,'');
const blocked=(message:string,status=403)=>new Response(`<!doctype html><html lang="de"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Website-Vorschau</title><body><main><h1>Website-Vorschau nicht verfügbar</h1><p>${message.replace(/[&<>"']/g,value=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[value]!))}</p><p><a href="${backend}/admin">Zurück zum CMS</a></p></main></body></html>`,{status,headers:{'content-type':'text/html; charset=UTF-8','cache-control':'private, no-store, max-age=0','x-robots-tag':'noindex, nofollow, noarchive'}});

export const onRequest=defineMiddleware(async(context,next)=>{
 const pathname=context.url.pathname;
 const isPage=pathname==='/draft-preview' || pathname==='/draft-preview/';
 if(!isPage)return next();
 if(import.meta.env.PROD)return blocked('Die lokale Entwurfs-Vorschau ist in diesem Build nicht verfügbar.');
 const bearer=context.request.headers.get('authorization')?.match(/^Bearer ([a-f0-9]{64})$/i)?.[1];
 const token=bearer||context.request.headers.get('x-draft-preview-token')||'';
 if(context.request.method!=='POST'||!/^[a-f0-9]{64}$/.test(token))return blocked('Bitte öffnen Sie die Vorschau erneut im CMS.');
 try{
  const payload=await context.request.json() as {manifest?:unknown;expiresAt?:unknown};
  if(typeof payload.expiresAt!=='string')throw new Error('Die Vorschau-Daten sind unvollständig.');
  const content=previewContent(payload.manifest);
  const draftPreview={expiresAt:payload.expiresAt,cmsUrl:`${backend}/admin`};
  context.locals.content=content;
  context.locals.draftPreview=draftPreview;
  const response=await next();
  response.headers.set('cache-control','private, no-store, max-age=0');response.headers.set('pragma','no-cache');response.headers.set('x-robots-tag','noindex, nofollow, noarchive');
  return response;
 }catch(error){return blocked(error instanceof Error?error.message:'Bitte öffnen Sie die Vorschau erneut im CMS.');}
});
