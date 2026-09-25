import {hasConsent} from './media/consent';
import {shouldLoadAnalytics,type AnalyticsConfig} from './privacy-rules';

let currentScript:HTMLScriptElement|null=null;
function config():AnalyticsConfig{try{return JSON.parse(document.querySelector<HTMLElement>('[data-consent-root]')?.dataset.analyticsConfig||'{"enabled":false,"provider":"none"}')}catch{return{enabled:false,provider:'none'}}}
function stop(value:AnalyticsConfig){if(value.provider==='ga4'&&value.measurementId)(window as unknown as Record<string,unknown>)[`ga-disable-${value.measurementId}`]=true;currentScript?.remove();currentScript=null;}
export function syncAnalytics(){const value=config();if(!shouldLoadAnalytics(value,hasConsent('analytics'))){stop(value);return;}if(currentScript)return;
 if(value.provider==='ga4'&&value.measurementId&&/^G-[A-Z0-9]{4,20}$/.test(value.measurementId)){(window as unknown as Record<string,unknown>)[`ga-disable-${value.measurementId}`]=false;const host=window as unknown as {dataLayer?:unknown[]};const dataLayer=host.dataLayer??=[];host.dataLayer=dataLayer;const gtag=(...args:unknown[])=>dataLayer.push(args);gtag('js',new Date());gtag('config',value.measurementId,{anonymize_ip:true});currentScript=document.createElement('script');currentScript.async=true;currentScript.src=`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(value.measurementId)}`;currentScript.dataset.pianissimoAnalytics='ga4';document.head.append(currentScript);}
 if(value.provider==='plausible'&&value.siteDomain&&/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/.test(value.siteDomain)){currentScript=document.createElement('script');currentScript.defer=true;currentScript.src='https://plausible.io/js/script.js';currentScript.dataset.domain=value.siteDomain;currentScript.dataset.pianissimoAnalytics='plausible';document.head.append(currentScript);}
}
export function initAnalytics(signal?:AbortSignal){syncAnalytics();window.addEventListener('pianissimo:consent',syncAnalytics,{signal});}
