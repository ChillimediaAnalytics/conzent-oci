<?php
/** Disposable test fixture only. Never distribute inside the connector ZIP. */
if (!defined('ABSPATH')) {
    exit;
}
if (!defined('DB_NAME') || !preg_match('/^chilli_wp_qa_[a-z0-9_]+$/', DB_NAME)) {
    return;
}
add_action('wp_footer', static function () {
    if (!isset($_GET['chilli-consent-qa'])) {
        return;
    }
    ?>
    <pre id="chilli-consent-qa-results" data-status="running">Running synthetic WP Consent API consumer checks…</pre>
    <script>
    window.addEventListener('load', function () {
        const output = document.getElementById('chilli-consent-qa-results');
        const results = [];
        try {
            if (typeof wp_set_consent !== 'function' || typeof wp_has_consent !== 'function') throw new Error('Real WP Consent API scripts missing');
            if (typeof window._getCnzConsent !== 'function') throw new Error('Connector bridge missing');
            // This deliberately tests the event bridge with synthetic consent
            // state. It does not assert a real banner UI interaction or log write.
            window.conzent = window.conzent || {};
            window.conzent._Store = window.conzent._Store || {};
            window.conzent._Store._bannerConfig = window.conzent._Store._bannerConfig || {};
            window.conzent._Store._bannerConfig.default_laws = 'gdpr';
            window.conzent._Store._bannerConfig.currentLang = 'sv';
            const apply = function (categories) {
                document.cookie = 'conzentConsent=yes; path=/; SameSite=Lax';
                document.cookie = 'conzentConsentPrefs=' + encodeURIComponent(JSON.stringify(categories)) + '; path=/; SameSite=Lax';
                document.dispatchEvent(new CustomEvent('conzentck_consent_update'));
            };
            const snapshot = function (name, expected) {
                const actual = Object.fromEntries(Object.keys(expected).map(category => [category, wp_has_consent(category)]));
                const pass = Object.keys(expected).every(category => actual[category] === expected[category]);
                results.push({ name, pass, actual, expected });
            };
            apply([]);
            snapshot('reject optional consent', { statistics: false, 'statistics-anonymous': false, marketing: false, preferences: false, functional: false });
            apply(['analytics', 'marketing']);
            snapshot('allow analytics and marketing', { statistics: true, 'statistics-anonymous': true, marketing: true, preferences: false, functional: false });
            apply([]);
            snapshot('revoke optional consent', { statistics: false, 'statistics-anonymous': false, marketing: false, preferences: false, functional: false });
            const pass = results.every(result => result.pass);
            output.dataset.status = pass ? 'passed' : 'failed';
            output.textContent = JSON.stringify({ status: output.dataset.status, consentType: window.wp_consent_type, results }, null, 2);
        } catch (error) {
            output.dataset.status = 'failed';
            output.textContent = JSON.stringify({ status: 'failed', error: error.message, results }, null, 2);
        }
    });
    </script>
    <?php
}, 100);
