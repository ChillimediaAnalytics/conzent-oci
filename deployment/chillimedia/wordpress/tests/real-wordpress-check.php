<?php
/** Run ONLY in a new disposable WordPress database using `wp eval-file`. */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('This check requires WP-CLI.');
}
if (!defined('DB_NAME') || !preg_match('/^chilli_wp_qa_[a-z0-9_]+$/', DB_NAME)) {
    WP_CLI::error('Refusing to modify a database that is not named chilli_wp_qa_<unique-id>.');
}
if (!defined('CONZENT_AUTO_SETUP') || CONZENT_AUTO_SETUP !== false) {
    WP_CLI::error('Set CONZENT_AUTO_SETUP=false before activating the connector in this fixture.');
}
$key = (string) getenv('CHILLI_QA_SITE_KEY');
if ($key === '') {
    WP_CLI::error('Set CHILLI_QA_SITE_KEY to a disposable central-service test site key.');
}
$admin = get_user_by('login', 'qaadmin');
if (!$admin) {
    WP_CLI::error('Expected the disposable qaadmin account.');
}
wp_set_current_user((int) $admin->ID);
if (!current_user_can('manage_options') || !function_exists('getconzent_cmp_get_server_url')) {
    WP_CLI::error('Connector must be active and qaadmin must be an administrator.');
}
$requests = [];
$inspect = static function ($pre, $args, $url) use (&$requests) {
    $requests[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'redirection' => $args['redirection'] ?? null];
    if (strpos($url, 'https://consent.chillimedia.se/api/v1/') !== 0) {
        WP_CLI::error('Connector attempted a destination outside the pinned agency API.');
    }
    return $pre;
};
add_filter('pre_http_request', $inspect, 10, 3);
$_POST = [
    'gc_settings_nonce' => wp_create_nonce('gc_save_settings'),
    'conzent_website_key' => $key,
    // This deliberately hostile field must be ignored by the agency fork.
    'conzent_server_url' => 'https://app.getconzent.com',
];
ob_start();
getconzent_cmp_render_settings_page();
$html = ob_get_clean();
remove_filter('pre_http_request', $inspect, 10);
$verified = get_option('chilli_consent_key_verified', '') === 'yes';
$expectVerified = getenv('CHILLI_QA_EXPECT_VERIFIED') !== '0';
$checks = [
    'real_wordpress_version' => get_bloginfo('version'),
    'plugin_active' => is_plugin_active('chilli-consent/chilli-consent.php'),
    'agency_service_pinned' => getconzent_cmp_get_server_url() === 'https://consent.chillimedia.se',
    'site_key_saved' => get_option('conzent_website_key', '') === $key,
    'loader_enabled' => get_option('conzent_verified', '') === 'yes',
    'api_verified' => $verified,
    'expected_verification_state' => $verified === $expectVerified,
    'settings_branding' => strpos($html, 'Chilli Consent Settings') !== false,
    'status_visible' => strpos($html, getconzent_cmp_key_verification_status()) !== false,
    'wp_consent_api_present' => getconzent_cmp_is_wp_consent_api(),
    'consent_api_registration' => apply_filters('wp_consent_api_registered_chilli-consent/chilli-consent.php', false) === true,
    'automatic_setup_disabled' => getconzent_cmp_claim_enabled() === false,
    'api_requests' => $requests,
];
WP_CLI::line(wp_json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
foreach (['plugin_active','agency_service_pinned','site_key_saved','loader_enabled','expected_verification_state','settings_branding','status_visible','wp_consent_api_present','consent_api_registration','automatic_setup_disabled'] as $name) {
    if ($checks[$name] !== true) {
        WP_CLI::error('Failed real WordPress check: ' . $name);
    }
}
if (count($requests) !== 1 || $requests[0]['redirection'] !== 0) {
    WP_CLI::error('Expected exactly one non-redirecting key verification request.');
}
WP_CLI::success('Real WordPress settings and service verification checks passed.');
