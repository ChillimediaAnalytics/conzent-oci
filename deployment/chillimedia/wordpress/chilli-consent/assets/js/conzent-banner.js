/** Chilli Consent bridge: defaults exist before async banner and API consumers. */
(function () {
    const categoryMap = {
        preferences: ['functional', 'preferences'],
        statistics: ['analytics'],
        'statistics-anonymous': ['analytics'],
        marketing: ['marketing'],
    };
    const readCookies = function () {
        const cookies = {};
        document.cookie.split(';').forEach(function (part) {
            const index = part.indexOf('=');
            if (index < 0) return;
            const name = part.slice(0, index).trim();
            const raw = part.slice(index + 1).trim();
            try { cookies[name] = decodeURIComponent(raw); } catch (error) { cookies[name] = raw; }
        });
        return cookies;
    };
    window._getCnzConsent = function () {
        const cookies = readCookies();
        const banner = window.conzent;
        const config = banner && banner._Store && banner._Store._bannerConfig || {};
        const law = typeof config.default_laws === 'string' ? config.default_laws.toLowerCase() : '';
        const type = law === 'ccpa' ? 'optout' : law ? 'optin' : window.chilliConsentDefaultType === 'optout' ? 'optout' : 'optin';
        const completed = Boolean(cookies.conzentConsent);
        let preferences = [];
        try { preferences = JSON.parse(cookies.conzentConsentPrefs || '[]'); } catch (error) {}
        if (!Array.isArray(preferences)) preferences = [];
        const categories = {};
        ['necessary','analytics','marketing','functional','preferences','performance','unclassified'].forEach(function (category) {
            categories[category] = category === 'necessary' || (completed ? preferences.includes(category) : type === 'optout');
        });
        return { activeLaw: law, consentType: type, categories, isUserActionCompleted: completed, consentID: cookies.conzent_id || '', languageCode: config.currentLang || '' };
    };
    window.chilliConsentSync = function () {
        const data = window._getCnzConsent();
        window.wp_consent_type = data.consentType;
        if (typeof wp_set_consent === 'function') {
            Object.entries(categoryMap).forEach(function (entry) {
                const target = entry[0], sources = entry[1];
                wp_set_consent(target, sources.some(source => data.categories[source]) ? 'allow' : 'deny');
            });
            // WP Consent API functional is necessary, not CMP performance.
            wp_set_consent('functional', 'allow');
            document.dispatchEvent(new CustomEvent('wp_consent_type_defined'));
        }
    };
    document.addEventListener('conzentck_consent_update', window.chilliConsentSync);
    document.addEventListener('conzentck_cookie_banner_load', window.chilliConsentSync);
    window.addEventListener('load', function () {
        window.chilliConsentSync();
        const target = document.querySelector('#conzentId');
        if (target) target.textContent = readCookies().conzent_id || 'Not found';
    });
    // This runs in <head>, even if the banner or WP Consent API arrives later.
    window.chilliConsentSync();
})();
