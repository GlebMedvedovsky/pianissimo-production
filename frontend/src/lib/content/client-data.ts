import type {Manifest} from '../../../../contracts/content';
// Built by publicContent and escaped before HTML serialization.
export const content=JSON.parse(document.querySelector('#site-content')!.textContent!) as Manifest;
export const asset=(id:string|null|undefined)=>content.assets.find(a=>a.id===id);
