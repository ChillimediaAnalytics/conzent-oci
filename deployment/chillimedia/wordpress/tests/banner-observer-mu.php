<?php
/** Disposable observation fixture. Never package or install on customer sites. */
if (!defined('ABSPATH') || !defined('DB_NAME') || !preg_match('/^chilli_wp_qa_[a-z0-9_]+$/', DB_NAME)) {
    return;
}
add_action('wp_footer', static function () {
    ?>
    <pre id="chilli-banner-observation" aria-live="polite">Waiting for real banner state…</pre>
    <script>
    (function () {
        const target = document.getElementById('chilli-banner-observation');
        const categories = ['statistics', 'statistics-anonymous', 'marketing', 'preferences', 'functional'];
        let attempts = 0;
        const report = function () {
            const cookies = {};
            document.cookie.split(';').forEach(item => {
                const parts = item.trim().split('=');
                const name = parts.shift();
                if (!name || !(name.startsWith('conzent') || name === '_ga_chilli_qa')) return;
                const raw = parts.join('=');
                try { cookies[name] = decodeURIComponent(raw); } catch (error) { cookies[name] = raw; }
            });
            const api = Object.fromEntries(categories.map(category => [category, typeof wp_has_consent === 'function' ? wp_has_consent(category) : 'API not loaded']));
            const observation = { source: 'Real banner and WP Consent API state; local cookie fixture only', attempts, consentType: window.wp_consent_type || null, cookies, wpConsent: api };
            window.__chilliQaObservation = observation;
            target.textContent = JSON.stringify(observation, null, 2);
        };
        const attempt = function () {
            attempts++;
            // No tracker/network: this only exercises the real cookie interceptor.
            document.cookie = '_ga_chilli_qa=fixture; path=/; SameSite=Lax; Max-Age=60';
            report();
        };
        window.addEventListener('load', function () { attempt(); setTimeout(report, 500); setInterval(attempt, 1000); });
        document.addEventListener('conzentck_consent_update', function () { report(); setTimeout(report, 500); });
    })();
    </script>
    <?php
}, 101);
