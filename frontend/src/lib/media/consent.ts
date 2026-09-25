import {coordinator} from './player';
import {isCurrentConsentChoice,type ConsentChoice} from '../privacy-rules';

type Category='externalMedia'|'analytics'|'marketing';
type ConsentConfig={version:number;necessary:true;externalMedia:boolean;analytics:boolean;marketing:boolean;bannerText:string;privacyLink:string};
const storageKey='pianissimo.consent';
const fallback:ConsentConfig={version:1,necessary:true,externalMedia:true,analytics:false,marketing:false,bannerText:'',privacyLink:'/privacy/'};
let config=fallback;let memory:ConsentChoice|null=null;let pending:HTMLElement|null=null;

function root(){return document.querySelector<HTMLElement>('[data-consent-root]');}
function readConfig():ConsentConfig{try{return {...fallback,...JSON.parse(root()?.dataset.consentConfig||'{}')}}catch{return fallback}}
function loadChoice():ConsentChoice|null{
 try{const parsed=JSON.parse(localStorage.getItem(storageKey)||'null');if(isCurrentConsentChoice(parsed,config.version))return parsed;}catch{/* Storage may be unavailable. */}
 try{const legacy=localStorage.getItem('pianissimo.external-media');if(legacy==='accepted'||legacy==='rejected')return{version:config.version,externalMedia:legacy==='accepted',analytics:false,marketing:false};}catch{/* Memory-only choice remains usable. */}
 return null;
}
function store(choice:ConsentChoice){memory=choice;try{localStorage.setItem(storageKey,JSON.stringify(choice));localStorage.removeItem('pianissimo.external-media')}catch{}window.dispatchEvent(new CustomEvent('pianissimo:consent',{detail:choice}));}
export const getConsent=()=>memory===null?null:(memory.externalMedia?'accepted':'rejected');
export function hasConsent(category:Category){if(category==='externalMedia'&&!config.externalMedia)return true;if(!config[category])return false;return memory?.[category]===true;}
function updateDialog(){const dialog=document.querySelector<HTMLDialogElement>('#consent-dialog');if(!dialog)return;dialog.querySelectorAll<HTMLInputElement>('[data-consent-toggle]').forEach(input=>input.checked=memory?.[input.dataset.consentToggle as Category]??false);const status=dialog.querySelector<HTMLElement>('.consent-status');if(status)status.textContent=memory?'Your current choice is shown above.':'No optional services have been approved yet.';}
export function openSettings(){updateDialog();const dialog=document.querySelector<HTMLDialogElement>('#consent-dialog');if(dialog&&!dialog.open)dialog.showModal();}
function unloadEmbeds(){document.querySelectorAll('.external-film iframe').forEach(frame=>frame.remove());document.querySelectorAll<HTMLElement>('.embed-placeholder').forEach(el=>el.hidden=false);}
function activate(slot:HTMLElement){if(!hasConsent('externalMedia')){pending=slot;openSettings();return;}coordinator.activateExternal();unloadEmbeds();const iframe=document.createElement('iframe');const id=slot.dataset.videoId;const provider=slot.dataset.provider;if(!id||!/^[a-zA-Z0-9_-]+$/.test(id))return;iframe.src=provider==='youtube'?`https://www.youtube-nocookie.com/embed/${id}?enablejsapi=1&autoplay=1&origin=${encodeURIComponent(location.origin)}`:`https://player.vimeo.com/video/${id}?autoplay=1`;iframe.title='Project film';iframe.allow='autoplay; fullscreen; picture-in-picture';iframe.referrerPolicy='strict-origin-when-cross-origin';iframe.allowFullscreen=true;slot.querySelector<HTMLElement>('.embed-placeholder')!.hidden=true;slot.append(iframe);}
function choose(mode:'accepted'|'rejected'|'save'){
 const allowed=(category:Category)=>config[category]===true;
 const choice:ConsentChoice={version:config.version,externalMedia:false,analytics:false,marketing:false};
 if(mode==='accepted'){choice.externalMedia=allowed('externalMedia');choice.analytics=allowed('analytics');choice.marketing=allowed('marketing');}
 if(mode==='save')document.querySelectorAll<HTMLInputElement>('[data-consent-toggle]').forEach(input=>choice[input.dataset.consentToggle as Category]=input.checked&&allowed(input.dataset.consentToggle as Category));
 store(choice);document.querySelector<HTMLDialogElement>('#consent-dialog')?.close();const notice=document.querySelector<HTMLElement>('.cookie-notice');if(notice)notice.hidden=true;if(!choice.externalMedia)unloadEmbeds();if(choice.externalMedia&&pending?.isConnected)activate(pending);pending=null;
}
coordinator.registerOther(()=>{document.querySelectorAll<HTMLIFrameElement>('.external-film iframe').forEach(frame=>{const youtube=frame.src.includes('youtube-nocookie');frame.contentWindow?.postMessage(youtube?JSON.stringify({event:'command',func:'pauseVideo',args:[]}):JSON.stringify({method:'pause'}),youtube?'https://www.youtube-nocookie.com':'https://player.vimeo.com');});});
export function initConsent(signal?:AbortSignal){config=readConfig();memory=loadChoice();const notice=document.querySelector<HTMLElement>('.cookie-notice');if(notice)notice.hidden=memory!==null||(!config.externalMedia&&!config.analytics&&!config.marketing);updateDialog();document.addEventListener('click',event=>{const target=(event.target as Element).closest<HTMLElement>('[data-consent],[data-consent-settings],[data-activate-film]');if(!target)return;if(target.hasAttribute('data-consent-settings')){pending=null;openSettings();return;}if(target.hasAttribute('data-activate-film')){activate(target.closest('.external-film')!);return;}const mode=target.dataset.consent;if(mode==='accepted'||mode==='rejected'||mode==='save')choose(mode);},{signal});}
