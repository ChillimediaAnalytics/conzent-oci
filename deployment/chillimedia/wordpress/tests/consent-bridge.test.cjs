const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../chilli-consent/assets/js/conzent-banner.js'),'utf8');
function fixture(siteKit=false) {
  const events={},calls=[],dispatches=[];
  const document={cookie:'',addEventListener:(name,fn)=>{events[name]=fn},dispatchEvent:event=>dispatches.push(event.type),querySelector:()=>null};
  const window={addEventListener:()=>{}};
  const context={window,document,_gcGsk:siteKit,CustomEvent:class{constructor(type){this.type=type}},wp_set_consent:(...args)=>calls.push(args)};
  context.window=context; context.addEventListener=()=>{};
  vm.createContext(context);vm.runInContext(source,context);
  return {context,document,window:context,calls,dispatches,update:()=>events.conzentck_consent_update()};
}
function loadBanner(f){f.window.conzent={_Store:{_bannerConfig:{default_laws:'gdpr',currentLang:'sv'}}};}
test('banner loaded after connector updates WP Consent API categories at event time',()=>{
 const f=fixture();loadBanner(f);f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%22analytics%22%2C%22marketing%22%5D';f.update();
 assert.equal(f.window.wp_consent_type,'optin');assert.deepEqual(f.dispatches,['wp_consent_type_defined']);
 assert.deepEqual(f.calls.sort(),[['preferences','deny'],['statistics','allow'],['statistics-anonymous','allow'],['functional','deny'],['marketing','allow']].sort());
});
test('reject/revoke maps all optional categories to deny',()=>{
 const f=fixture();loadBanner(f);f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%5D';f.update();
 assert.equal(f.calls.length,5);assert.equal(f.calls.every(([,status])=>status==='deny'),true);
});
test('malformed cookie encoding does not abort consent bridge',()=>{
 const f=fixture();loadBanner(f);f.document.cookie='other=%E0%A4%A; conzentConsent=yes; conzentConsentPrefs=%5B%22analytics%22%5D';assert.doesNotThrow(f.update);
 assert.equal(f.calls.find(([cat])=>cat==='statistics')[1],'allow');
});
test('Site Kit initial state is preserved until a consent action',()=>{
 const f=fixture(true);loadBanner(f);f.document.cookie='my_conzentConsent_backup=yes; conzentConsentPrefs=%5B%5D';f.update();assert.equal(f.calls.length,0);
 f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%5D';f.update();assert.equal(f.calls.length,5);
});
test('CCPA law maps to optout and unknown/missing banner never grants optional consent',()=>{
 const f=fixture();f.update();assert.equal(f.calls.every(([,status])=>status==='deny'),true);
 loadBanner(f);f.window.conzent._Store._bannerConfig.default_laws='ccpa';f.document.cookie='conzentConsent=yes';f.update();assert.equal(f.window.wp_consent_type,'optout');
});
