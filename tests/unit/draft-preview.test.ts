import test from 'node:test';
import assert from 'node:assert/strict';
import raw from '../../content/manifest.json';
import {validateManifest} from '../../contracts/content';
import {previewContent} from '../../frontend/src/lib/content/preview';

test('draft preview suppresses analytics without removing visual consent configuration',()=>{
 const draft=structuredClone(validateManifest(raw));
 draft.site.consent={version:4,necessary:true,externalMedia:true,analytics:true,marketing:false,bannerText:'Preview consent',privacyLink:'/privacy/'};
 draft.site.analytics={enabled:true,provider:'ga4',measurementId:'G-TEST1234',siteDomain:null};
 const preview=previewContent(draft);
 assert.equal(preview.site.analytics?.enabled,false);
 assert.equal(preview.site.analytics?.provider,'ga4');
 assert.equal(preview.site.consent?.externalMedia,true);
 assert.equal(preview.site.consent?.analytics,true);
 assert.equal(draft.site.analytics.enabled,true,'preview projection must not mutate the saved draft value');
});

test('draft preview retains referenced test media but excludes unrelated private media from its projection',()=>{
 const draft=structuredClone(validateManifest(raw));
 const referencedId=draft.site.mediaSlots.about.image!;
 const referenced=draft.assets.find(asset=>asset.id===referencedId)!;
 referenced.state='test';
 draft.assets.push({...referenced,id:'unrelated-preview-test',path:'media/preview/unrelated-preview-test.jpg'});
 assert.throws(()=>validateManifest(draft),/Test fixtures/,'publication validation must remain strict');
 const preview=previewContent(draft);
 assert.equal(preview.assets.find(asset=>asset.id===referencedId)?.state,'test');
 assert.equal(preview.assets.some(asset=>asset.id==='unrelated-preview-test'),false);
});
