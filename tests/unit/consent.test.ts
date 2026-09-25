import test from 'node:test';
import assert from 'node:assert/strict';
import {isCurrentConsentChoice,shouldLoadAnalytics} from '../../frontend/src/lib/privacy-rules';

test('versioned consent accepts only a complete current first-party choice',()=>{
 assert.equal(isCurrentConsentChoice({version:2,externalMedia:true,analytics:false,marketing:false},2),true);
 assert.equal(isCurrentConsentChoice({version:1,externalMedia:true,analytics:false,marketing:false},2),false);
 assert.equal(isCurrentConsentChoice({version:2,externalMedia:'yes',analytics:false,marketing:false},2),false);
});

test('analytics requires active valid public configuration and explicit consent',()=>{
 assert.equal(shouldLoadAnalytics({enabled:true,provider:'ga4',measurementId:'G-TEST1234'},false),false);
 assert.equal(shouldLoadAnalytics({enabled:true,provider:'ga4',measurementId:'G-TEST1234'},true),true);
 assert.equal(shouldLoadAnalytics({enabled:true,provider:'ga4',measurementId:'invalid'},true),false);
 assert.equal(shouldLoadAnalytics({enabled:true,provider:'plausible',siteDomain:'pianissimo.example'},true),true);
 assert.equal(shouldLoadAnalytics({enabled:false,provider:'plausible',siteDomain:'pianissimo.example'},true),false);
 assert.equal(shouldLoadAnalytics({enabled:true,provider:'none'},true),false);
});
