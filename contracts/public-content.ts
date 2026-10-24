import type {Manifest} from './content';
/** Build-time boundary. Never serialize the input manifest directly to the browser. */
export function publicContent(input:Manifest,policy:'publication'|'draft-preview'='publication'):Manifest {
 const data=structuredClone(input);
 data.projects=data.projects.filter(p=>p.status==='published');
 const trackIds=new Set(data.projects.flatMap(p=>p.tracks.map(t=>t.trackId)));
 data.tracks=data.tracks.filter(t=>t.status==='published'&&(trackIds.has(t.id)||t.libraryVisible===true));
 const publishedTracks=new Set(data.tracks.map(t=>t.id));data.projects.forEach(p=>p.tracks=p.tracks.filter(t=>publishedTracks.has(t.trackId)));
 data.site.cv=data.site.cv.filter(c=>c.status==='published'||c.status==='needs-confirmation');
 const assetIds=new Set<string>();const add=(id:string|null|undefined)=>{if(id)assetIds.add(id)};
 data.projects.forEach(p=>{Object.values(p.memberships).forEach(m=>{add(m.coverAssetId);add(m.hoverAssetId)});add(p.localTrailerAssetId);add(p.localTrailerPosterId);p.gallery.forEach(add)});
 data.tracks.forEach(t=>{add(t.coverAssetId);add(t.audioAssetId)});Object.values(data.site.mediaSlots).forEach(s=>{add(s.image);add(s.video);add(s.poster)});add(data.site.collective?.logoAssetId);
 data.assets=data.assets.filter(a=>assetIds.has(a.id)&&(policy==='draft-preview'||a.state!=='test')).map(a=>({...a,origin:'Curated local derivative',source:'Provenance is recorded in the project content registry.'}));
 data.sources=[];data.projects.forEach(p=>p.sourceIds=[]);
 return data;
}
