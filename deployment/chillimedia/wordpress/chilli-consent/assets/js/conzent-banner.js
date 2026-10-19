const consentType = {
    gdpr: 'optin',
    ccpa: 'optout',
};
const categoryMap = {
    functional: 'preferences',
    analytics: ['statistics','statistics-anonymous'],
    performance: 'functional',
    marketing: 'marketing',
};
const gskEnabled = typeof _gcGsk !== 'undefined' && _gcGsk ? _gcGsk : false;
// Read the asynchronously loaded banner at event time, not at footer load.

const _accessConzentCookie = (name) => {
	const conzent_cookies = document.cookie.split(";").reduce((acc, cookieString) => {
		const [key, value] = cookieString.split("=").map((s) => s.trim());
		if (key && value) {
			try { acc[key] = decodeURIComponent(value); } catch (e) { acc[key] = value; }
		}
		return acc;
	}, {});
	return name ? conzent_cookies[name] || false : conzent_cookies;
};
window._getCnzConsent = function () {
    
    const cookieConsent = {
      activeLaw: "",
      categories: Object.fromEntries(['necessary','analytics','marketing','functional','preferences','performance','unclassified'].map(category => [category, false])),
      isUserActionCompleted: false,
      consentID: "",
      languageCode: ""
    };
		
    try {
        const banner = window.conzent;
        cookieConsent.activeLaw = banner._Store._bannerConfig.default_laws;
        Object.keys(cookieConsent.categories).forEach(category => {
              cookieConsent.categories[category] = _checkCookieCat(category) === "yes";   
         });
  
        cookieConsent.isUserActionCompleted = _consentExists("conzentConsent");
        cookieConsent.consentID = _accessConzentCookie("conzent_id") || "";
        cookieConsent.languageCode = banner._Store._bannerConfig.currentLang || "";
    } catch (e) {}
    return cookieConsent;
};
function _consentExists(cookieName){
    if(Object.prototype.hasOwnProperty.call(_accessConzentCookie(), cookieName)){
        return true;
    }
    return false;
}
function _checkCookieCat(field){

	var _preferences_val = _accessConzentCookie("conzentConsentPrefs"),
	 _preferences_item;

	 try {
		_preferences_item = JSON.parse(_preferences_val);
	 } catch (e) {
		_preferences_item = null;
	 }

	 if(_preferences_item){

		return _preferences_item.includes(field)? 'yes' : "";

	 }

	 return "";

}
document.addEventListener("conzentck_consent_update", function () {
    
    const consentData = _getCnzConsent();
    const categories = consentData.categories;
    
    if ((consentData.isUserActionCompleted === false) && gskEnabled && !Object.values(categories).includes(true)) {
        return;
    }
    window.wp_consent_type = consentData.activeLaw ? consentType[consentData.activeLaw] : 'optin';
    let event = new CustomEvent('wp_consent_type_defined');
    document.dispatchEvent( event );
    Object.entries(categories).forEach(([key, value]) => {
        if (!(key in categoryMap))
            return;
        setConsentStatus(key, value ? 'allow' : 'deny');
        
    });
    function setConsentStatus(key, status) {
        
		if (typeof wp_set_consent === 'function'){
			if (Array.isArray(categoryMap[key])) {
				categoryMap[key].forEach(el => {
					wp_set_consent(el, status);
				});
			} else {
				wp_set_consent(categoryMap[key], status);
			}
		}
		
    }
});

window.addEventListener("load", function() {
	var conzent_id = _accessConzentCookie('conzent_id');
	if(document.querySelector("#conzentId")){
		if(conzent_id){
			document.querySelector("#conzentId").textContent = conzent_id;
		}
		else{
			document.querySelector("#conzentId").textContent = "Not found";
		}
	}
	
});
