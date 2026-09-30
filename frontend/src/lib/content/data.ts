import raw from 'virtual:pianissimo-manifest';
import {validateManifest} from '../../../../contracts/content';
import type {Manifest} from '../../../../contracts/content';
import {publicContent} from '../../../../contracts/public-content';
const active=publicContent(validateManifest(raw));
export const content=active as Manifest;
export const contentFor=(locals?:{content?:Manifest})=>locals?.content??content;
export const asset=(id:string|null|undefined)=>content.assets.find(a=>a.id===id);
export const assetFrom=(source:Manifest,id:string|null|undefined)=>source.assets.find(a=>a.id===id);
