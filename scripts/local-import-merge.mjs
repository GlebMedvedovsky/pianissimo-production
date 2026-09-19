/** Three-way field merge. Arrays of identified records merge by ID. A manual
 * edit conflicts only with the proposed field, never the rest of the import. */
export function mergeDraft(base,desired,current,path=''){
 const changes=[],conflicts=[];
 const same=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
 const walk=(before,wanted,actual,p)=>{
  if(same(before,wanted))return actual;
  if(same(actual,wanted)){changes.push({path:p,status:'already_applied'});return actual}
  if(Array.isArray(before)&&Array.isArray(wanted)&&Array.isArray(actual)&&[...before,...wanted,...actual].every(x=>x&&typeof x.id==='string')){
   const result=structuredClone(actual);
   for(const id of new Set([...before,...wanted].map(x=>x.id))){const i=result.findIndex(x=>x.id===id),v=walk(before.find(x=>x.id===id),wanted.find(x=>x.id===id),result[i],`${p}[${id}]`);if(v===undefined){if(i>=0)result.splice(i,1)}else if(i<0)result.push(v);else result[i]=v}return result;
  }
  if(before&&wanted&&actual&&![before,wanted,actual].some(Array.isArray)&&[before,wanted,actual].every(x=>typeof x==='object')){
   const result=structuredClone(actual);for(const k of new Set([...Object.keys(before),...Object.keys(wanted)])){const v=walk(before[k],wanted[k],actual[k],p?p+'.'+k:k);if(v===undefined)delete result[k];else result[k]=v}return result;
  }
  if(same(actual,before)){changes.push({path:p,status:'applied',before,after:wanted});return structuredClone(wanted)}
  conflicts.push({path:p,status:'manual_change_preserved',before,desired:wanted});return actual;
 };
 return {value:walk(base,desired,current,path),changes,conflicts};
}

// Remove only proposals depending on a conflicting media file. Other fields
// still merge normally; an existing manual asset is never overwritten.
export function excludeBlockedMedia(desired,current,blockedIds){
 const blocked=new Set(blockedIds),d=structuredClone(desired);
 d.assets=d.assets.flatMap(a=>blocked.has(a.id)?current.assets.filter(x=>x.id===a.id):[a]);
 d.tracks=d.tracks.flatMap(t=>{if(!blocked.has(t.audioAssetId))return[t];const old=current.tracks.find(x=>x.id===t.id);return old?[{...t,audioAssetId:old.audioAssetId,duration:old.duration,peaks:old.peaks,audioSha256:old.audioSha256,waveformSha256:old.waveformSha256}]:[]});
 const tracks=new Set(d.tracks.map(t=>t.id));
 d.projects=d.projects.flatMap(p=>{const old=current.projects.find(x=>x.id===p.id);if(!old&&Object.values(p.memberships).some(m=>blocked.has(m.coverAssetId)))return[];
  for(const [section,m] of Object.entries(p.memberships)){const previous=old?.memberships[section];if(blocked.has(m.coverAssetId)){if(previous)m.coverAssetId=previous.coverAssetId;else{delete p.memberships[section];continue}}if(blocked.has(m.hoverAssetId)){if(previous?.hoverAssetId)m.hoverAssetId=previous.hoverAssetId;else delete m.hoverAssetId}}
  for(const key of ['localTrailerAssetId','localTrailerPosterId'])if(blocked.has(p[key])){if(old?.[key])p[key]=old[key];else delete p[key]}
  p.gallery=p.gallery.filter(id=>!blocked.has(id));p.tracks=p.tracks.filter(t=>tracks.has(t.trackId));return[p];
 });
 for(const [name,slot] of Object.entries(d.site.mediaSlots))for(const key of ['image','video','poster'])if(blocked.has(slot[key]))slot[key]=current.site.mediaSlots[name][key];
 return d;
}
