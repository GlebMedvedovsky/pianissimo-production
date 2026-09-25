export type ConsentChoice={version:number;externalMedia:boolean;analytics:boolean;marketing:boolean};
export type AnalyticsConfig={enabled:boolean;provider:'none'|'ga4'|'plausible';measurementId?:string|null;siteDomain?:string|null};

export function isCurrentConsentChoice(value:unknown,version:number):value is ConsentChoice{
 return !!value&&typeof value==='object'&&(value as ConsentChoice).version===version&&['externalMedia','analytics','marketing'].every(key=>typeof (value as unknown as Record<string,unknown>)[key]==='boolean');
}

export function shouldLoadAnalytics(value:AnalyticsConfig,allowed:boolean){
 if(!value.enabled||!allowed)return false;
 if(value.provider==='ga4')return !!value.measurementId&&/^G-[A-Z0-9]{4,20}$/.test(value.measurementId);
 if(value.provider==='plausible')return !!value.siteDomain&&/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/.test(value.siteDomain);
 return false;
}
