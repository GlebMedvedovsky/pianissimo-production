import raw from '../../../../content/manifest.json';
import {validateManifest} from '../../../../contracts/content';
import {publicContent} from '../../../../contracts/public-content';
export const content=publicContent(validateManifest(raw));
export const asset=(id:string|null|undefined)=>content.assets.find(a=>a.id===id);
