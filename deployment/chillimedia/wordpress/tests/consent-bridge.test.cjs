const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../chilli-consent/assets/js/conzent-banner.js'),'utf8');
function fixture({cookie='',api=true,law='',defaultType}={}) {
  const events={},states={},dispatches=[];
  const document={cookie,addEventListener:(name,fn)=>{events[name]=fn},dispatchEvent:event=>dispatches.push(event.type),querySelector:()=>null};
  const context={document,CustomEvent:class{constructor(type){this.type=type}},addEventListener:()=>{}};
  context.window=context;
  if(defaultType)context.chilliConsentDefaultType=defaultType;
  if(law)context.conzent={_Store:{_bannerConfig:{default_laws:law,currentLang:'sv'}}};
  const installApi=()=>{context.wp_set_consent=(name,value)=>{states[name]=value}};
  if(api)installApi();
  vm.createContext(context);vm.runInContext(source,context);
  return {context,document,states,dispatches,installApi,event:name=>events[name]()};
}
function expectOptional(f,status){for(const cat of ['preferences','statistics','statistics-anonymous','marketing'])assert.equal(f.states[cat],status,cat);assert.equal(f.states.functional,'allow');}
test('initial page without choice denies optional consent before any banner event',()=>{
 const f=fixture();assert.equal(f.context.wp_consent_type,'optin');expectOptional(f,'deny');
});
test('initial page restores saved choice before banner or consent events',()=>{
 const f=fixture({cookie:'conzentConsent=yes; conzentConsentPrefs=%5B%22analytics%22%5D'});
 assert.equal(f.states.statistics,'allow');assert.equal(f.states['statistics-anonymous'],'allow');assert.equal(f.states.marketing,'deny');assert.equal(f.states.preferences,'deny');
});
test('orphaned consent preferences without completed choice never grant consent',()=>{
 const f=fixture({cookie:'conzentConsentPrefs=%5B%22analytics%22%2C%22marketing%22%5D; wp_consent_marketing=allow'});expectOptional(f,'deny');
});
test('head bootstrap remains optin while API is unavailable and syncs immediately after it loads',()=>{
 const f=fixture({api:false});assert.equal(f.context.wp_consent_type,'optin');assert.deepEqual(f.states,{});
 f.installApi();f.context.chilliConsentSync();expectOptional(f,'deny');
});
test('late real GDPR banner load synchronizes without waiting for user action',()=>{
 const f=fixture({api:false});f.installApi();f.context.conzent={_Store:{_bannerConfig:{default_laws:'gdpr'}}};f.event('conzentck_cookie_banner_load');expectOptional(f,'deny');
});
test('explicit CCPA config preserves optout until an actual saved decision says otherwise',()=>{
 const f=fixture({law:'ccpa'});assert.equal(f.context.wp_consent_type,'optout');expectOptional(f,'allow');
 f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%5D';f.event('conzentck_consent_update');assert.equal(f.context.wp_consent_type,'optout');expectOptional(f,'deny');
});
test('explicit server optout is preserved, unknown laws default to optin',()=>{
 const f=fixture({defaultType:'optout'});expectOptional(f,'allow');
 const unknown=fixture({law:'unknown'});assert.equal(unknown.context.wp_consent_type,'optin');expectOptional(unknown,'deny');
});
test('late banner config can establish explicit CCPA optout',()=>{
 const f=fixture();expectOptional(f,'deny');f.context.conzent={_Store:{_bannerConfig:{default_laws:'ccpa'}}};f.event('conzentck_cookie_banner_load');assert.equal(f.context.wp_consent_type,'optout');expectOptional(f,'allow');
});
test('allow then revoke propagates actual saved categories',()=>{
 const f=fixture();f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%22analytics%22%2C%22marketing%22%5D';f.event('conzentck_consent_update');
 assert.equal(f.states.statistics,'allow');assert.equal(f.states.marketing,'allow');assert.equal(f.states.preferences,'deny');
 f.document.cookie='conzentConsent=yes; conzentConsentPrefs=%5B%5D';f.event('conzentck_consent_update');expectOptional(f,'deny');
});
test('malformed cookie encoding and non-array preference payload fail closed',()=>{
 const f=fixture({cookie:'other=%E0%A4%A; conzentConsent=yes; conzentConsentPrefs=%7B%22analytics%22%3Atrue%7D'});expectOptional(f,'deny');
});
test('WP functional remains necessary while optional functional/preferences map to preferences',()=>{
 const f=fixture({cookie:'conzentConsent=yes; conzentConsentPrefs=%5B%22preferences%22%5D'});assert.equal(f.states.preferences,'allow');assert.equal(f.states.functional,'allow');assert.equal(f.states.statistics,'deny');
});
