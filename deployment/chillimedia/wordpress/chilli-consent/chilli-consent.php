<?php
/**
 * Fork of GetConzent CMP 2.2.4 (Conzent ApS), modified by Chillimedia 2026-10-19.
 * Upstream notices and GPLv3-or-later licence are preserved in LICENSE and NOTICE.
 * @link https://consent.chillimedia.se/
 *
 * @wordpress-plugin
 * Plugin Name: Chilli Consent
 * Plugin URI: https://consent.chillimedia.se/
 * Description: Connect this WordPress website to the Chillimedia consent dashboard. Includes cookie-banner loading, WordPress Consent API and Google Site Kit integration.
 * Version: 2.2.4.2
 * Requires at least: 5.8
 * Tested up to: 7.1.2
 * Requires PHP: 7.4
 * Author: Chillimedia (based on Conzent ApS)
 * Author URI: https://chillimedia.se/
 * Update URI: https://consent.chillimedia.se/wordpress/chilli-consent
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: getconzent-cmp-cookie-banner-consent-management
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GETCONZENT_CMP_PLUGIN_VERSION', '2.2.4.2' );
define( 'GETCONZENT_CMP_PLUGIN_FILE', __FILE__ );
define( 'GETCONZENT_CMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GETCONZENT_CMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GETCONZENT_CMP_PLUGIN_DIR . 'includes/request-guard.php';

/**
 * This agency build pins the service in source. Old database options and
 * CONZENT_SERVER_URL must never send activation or visitor traffic to Cloud.
 * The legacy helper name is retained for settings/API compatibility.
 */
function getconzent_cmp_server_url_is_constant(): bool {
	return true;
}

function getconzent_cmp_get_server_url(): string {
	return 'https://consent.chillimedia.se';
}

/**
 * Get the API base URL derived from the server URL.
 */
function getconzent_cmp_get_api_url(): string {
	return getconzent_cmp_get_server_url() . '/api/v1';
}

/* --- Hooks ------------------------------------------------------------ */

add_action( 'wp_enqueue_scripts', 'getconzent_cmp_enqueue_frontend', PHP_INT_MAX - 99 );
// Priority 0: the loader must be the first script in <head>. It registers the
// consent defaults itself, and a Google tag that initialises ahead of it would
// discard those defaults for arriving late.
add_action( 'wp_head', 'getconzent_cmp_print_loader', 0 );
add_action( 'init', 'getconzent_cmp_register_shortcodes' );
add_action( 'admin_menu', 'getconzent_cmp_register_admin_menu' );
add_action( 'plugins_loaded', 'getconzent_cmp_update_check' );
add_action( 'activated_plugin', 'getconzent_cmp_save_activation_error' );
add_action( 'admin_enqueue_scripts', 'getconzent_cmp_enqueue_admin' );

// Automatic setup: the responder answers before anything that could redirect
// or gate the front end (coming-soon and force-login plugins act on
// template_redirect); the bootstrap runs before wp_cron spawns at priority 10,
// so a visitor request kicks the claim off without waiting for it.
add_action( 'init', 'getconzent_cmp_claim_responder', 0 );
add_action( 'init', 'getconzent_cmp_claim_bootstrap', 5 );
add_action( 'admin_init', 'getconzent_cmp_claim_admin_watchdog' );
add_action( 'conzent_claim_attempt', 'getconzent_cmp_claim_cron' );
add_action( 'admin_post_conzent_claim_retry', 'getconzent_cmp_claim_retry_action' );
add_action( 'admin_post_conzent_claim_dismiss', 'getconzent_cmp_claim_dismiss_action' );
add_action( 'admin_notices', 'getconzent_cmp_claim_admin_notice' );

register_activation_hook( __FILE__, 'getconzent_cmp_activate' );
register_deactivation_hook( __FILE__, 'getconzent_cmp_deactivate' );

add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );
add_filter( 'wp_get_consent_type', 'getconzent_cmp_default_consent_type', PHP_INT_MAX );
add_filter( 'wp_has_consent', 'getconzent_cmp_php_consent', PHP_INT_MAX, 3 );

/* --- Activation ------------------------------------------------------- */

/**
 * The options automatic setup keeps, all per blog.
 */
function getconzent_cmp_claim_option_defaults(): array {
	return array(
		'conzent_claim_state'      => '',
		'conzent_claim_token'      => '',
		'conzent_claim_attempts'   => 0,
		'conzent_claim_next'       => 0,
		'conzent_claim_domain'     => '',
		'conzent_claim_error_code' => '',
		'conzent_claim_error'      => '',
		'conzent_claim_at'         => 0,
	);
}

function getconzent_cmp_activate(): void {
	$defaults = array(
		'conzent_website_key'  => '',
		'conzent_server_url'   => '',
		'conzent_site_name'    => '',
		'conzent_site_domain'  => '',
		'conzent_site_status'  => '',
		'conzent_site_id'      => '',
		'conzent_verified'     => '',
		'chilli_consent_key_verified' => '',
		'conzent_error'        => '',
	);
	foreach ( $defaults + getconzent_cmp_claim_option_defaults() as $key => $value ) {
		add_option( $key, $value );
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( __FILE__ );
	update_option( 'conzent_db_version', $data['Version'] );

	// No key yet: ask Conzent for it. Not here and now, though: WordPress runs
	// this hook before it records the plugin as active, so a request from the
	// Conzent server arriving during it would find no responder. The first
	// request after activation (or the cron event below) does the work.
	if ( '' === (string) get_option( 'conzent_website_key', '' ) && getconzent_cmp_claim_enabled() ) {
		getconzent_cmp_claim_begin_cycle( 0 );
		getconzent_cmp_claim_schedule( time() + 5 );
	}
}

function getconzent_cmp_deactivate(): void {
	wp_clear_scheduled_hook( 'conzent_claim_attempt' );
	delete_option( 'conzent_claim_lock' );
}

/* --- Update / migration check ----------------------------------------- */

function getconzent_cmp_update_check(): void {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data    = get_plugin_data( __FILE__ );
	$version = $data['Version'];

	// Ensure options exist on older installs.
	if ( false === get_option( 'conzent_server_url' ) ) {
		add_option( 'conzent_server_url', '' );
	}
	if ( false === get_option( 'conzent_verified' ) ) {
		$has_key    = (bool) get_option( 'conzent_website_key' );
		$has_domain = (bool) get_option( 'conzent_site_domain' );
		add_option( 'conzent_verified', ( $has_key && $has_domain ) ? 'yes' : '' );
	}
	if ( false === get_option( 'conzent_error' ) ) {
		add_option( 'conzent_error', '' );
	}
	if ( false === get_option( 'chilli_consent_key_verified' ) ) {
		// A legacy configured flag is not proof of verification with this service.
		add_option( 'chilli_consent_key_verified', '' !== (string) get_option( 'conzent_website_key', '' ) ? 'pending' : '' );
	}
	// An install that already holds a key was configured by hand and stays
	// that way; automatic setup never calls out for it.
	if ( false === get_option( 'conzent_claim_state' ) ) {
		add_option( 'conzent_claim_state', '' !== (string) get_option( 'conzent_website_key', '' ) ? 'manual' : '' );
	}
	foreach ( getconzent_cmp_claim_option_defaults() as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
	if ( get_option( 'conzent_db_version' ) !== $version ) {
		update_option( 'conzent_db_version', $version );
	}
}

/**
 * Keep any output this plugin produced during activation instead of letting
 * WordPress fail the activation over it. Only for this plugin's own
 * activation: the hook fires for every plugin, and earlier versions consumed
 * core's output buffer on all of them.
 *
 * @param string $plugin The plugin basename WordPress just activated.
 */
function getconzent_cmp_save_activation_error( $plugin = '' ): void {
	if ( plugin_basename( __FILE__ ) !== $plugin ) {
		return;
	}
	if ( ob_get_level() > 0 ) {
		$error = ob_get_contents();
		ob_clean();
		if ( ! empty( $error ) ) {
			update_option( 'conzent_plugin_error', $error );
		}
	}

	// Under WP-CLI a blocking call is welcome: `wp plugin activate` returns
	// with the site configured, and the result is printed.
	if ( defined( 'WP_CLI' ) && WP_CLI && 'pending' === get_option( 'conzent_claim_state' ) ) {
		$result = getconzent_cmp_claim_run( true );
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::log( 'Chilli Consent: ' . $result['message'] );
		}
	}
}

/* --- Helper: WP Consent API / Google Site Kit detection --------------- */

function getconzent_cmp_is_wp_consent_api(): bool {
	return class_exists( 'WP_CONSENT_API' );
}

function getconzent_cmp_is_google_site_kit(): bool {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( 'google-site-kit/google-site-kit.php' );
}

/** Empty WP Consent API types mean consent is unmanaged and allow everything. */
function getconzent_cmp_default_consent_type( $consent_type = '' ): string {
	return 'optout' === $consent_type ? 'optout' : 'optin';
}

/** Keep server-side consumers aligned with the saved CMP decision. */
function getconzent_cmp_php_consent( $has_consent, $category, $requested_by = null ): bool {
	if ( 'functional' === $category ) {
		return true; // WordPress uses functional for strictly necessary storage.
	}
	$map = array(
		'preferences' => array( 'functional', 'preferences' ),
		'statistics' => array( 'analytics' ),
		'statistics-anonymous' => array( 'analytics' ),
		'marketing' => array( 'marketing' ),
	);
	if ( ! isset( $map[ $category ] ) ) {
		return (bool) $has_consent;
	}
	if ( empty( $_COOKIE['conzentConsent'] ) || ! is_string( $_COOKIE['conzentConsent'] ) ) {
		return function_exists( 'wp_get_consent_type' ) && 'optout' === wp_get_consent_type();
	}
	$raw = isset( $_COOKIE['conzentConsentPrefs'] ) && is_string( $_COOKIE['conzentConsentPrefs'] )
		? wp_unslash( $_COOKIE['conzentConsentPrefs'] ) : '[]';
	$preferences = json_decode( $raw, true );
	if ( ! is_array( $preferences ) ) {
		return false;
	}
	return count( array_intersect( $map[ $category ], array_filter( $preferences, 'is_string' ) ) ) > 0;
}

/* --- Frontend assets -------------------------------------------------- */

function getconzent_cmp_enqueue_frontend(): void {
	// Same rule as the loader tag: the public site, never an editor.
	if ( ! getconzent_cmp_is_public_request() ) {
		return;
	}
	wp_enqueue_style(
		'getconzent-cmp-banner-css',
		GETCONZENT_CMP_PLUGIN_URL . 'assets/css/conzent-banner.css',
		array(),
		GETCONZENT_CMP_PLUGIN_VERSION,
		'screen'
	);
	if ( getconzent_cmp_is_wp_consent_api() ) {
		// Runs directly after the API script, before its dependent consumers.
		// The bridge itself was printed at wp_head priority 0.
		wp_add_inline_script( 'wp-consent-api', 'if(window.chilliConsentSync){window.chilliConsentSync();}', 'after' );
	}

}

/**
 * Print the consent loader at the very top of <head>.
 *
 * One tag is all that is needed: consent.js issues the Google Consent Mode
 * `consent default` command itself, and because the tag is parser-blocking that
 * command runs before the browser reaches any script below it. An inline stub
 * alongside it would be redundant -- and worse than the loader's own push, which
 * honours a renamed GTM dataLayer via data-dl instead of hardcoding `dataLayer`.
 *
 * This is deliberately NOT enqueued, because position in <head> still decides
 * whether the defaults count. Google discards a `consent default` that arrives
 * after a Google tag (gtag.js or GTM) has initialised, and the tag then behaves
 * as if consent had been granted. The enqueue system offers no ordering guarantee
 * relative to tags printed by other plugins or the theme, so the loader is
 * printed inline from wp_head at priority 0 instead.
 *
 * The loader is deliberately not async either: it carries the IAB TCF stub, and
 * ad tags that probe for `__tcfapi` before it exists do not retry -- they drop
 * straight to non-personalised ads. See docs/embed-snippet.md in the Conzent
 * repository for the canonical snippet.
 *
 * Printed for visitors only. The tag holds scripts until the visitor has
 * chosen, and a page builder is a bundle of scripts that edits on the front
 * end, where is_admin() is false: see includes/request-guard.php.
 */
function getconzent_cmp_print_loader(): void {
	if ( ! getconzent_cmp_is_public_request() ) {
		return;
	}
	if ( 'yes' !== get_option( 'conzent_verified' ) ) {
		return;
	}
	$key = get_option( 'conzent_website_key' );
	if ( empty( $key ) ) {
		return;
	}

	$default_type = function_exists( 'wp_get_consent_type' )
		? getconzent_cmp_default_consent_type( wp_get_consent_type() ) : 'optin';
	$bridge = add_query_arg( 'ver', GETCONZENT_CMP_PLUGIN_VERSION, GETCONZENT_CMP_PLUGIN_URL . 'assets/js/conzent-banner.js' );
	// Establish WP Consent API state before the asynchronous banner or other
	// head consumers. The synchronous bridge replays saved choices when the API
	// script becomes available and whenever the real banner announces readiness.
	echo '<script id="chilli-consent-defaults">window.chilliConsentDefaultType=' . wp_json_encode( $default_type ) . ';window.wp_consent_type=window.chilliConsentDefaultType;</script>' . "\n";
	echo '<script src="' . esc_url( $bridge ) . '" data-no-optimize="1" data-no-defer="1" data-cfasync="false" nowprocket></script>' . "\n";
	$src = getconzent_cmp_get_server_url() . '/c/consent.js';

	// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- must print inline at a guaranteed position; see docblock.
	// The extra attributes ask the common page optimizers to leave this tag
	// alone: LiteSpeed Cache reads data-no-optimize (no minify/combine) and
	// data-no-defer (no defer/delay), WP Rocket reads nowprocket, Cloudflare
	// Rocket Loader reads data-cfasync="false". A loader that is delayed until
	// the first interaction, deferred, or merged into a combined file sets the
	// consent defaults after the very tags it exists to gate, and Tag Assistant
	// reports "A tag read consent state before a default was set".
	// data-plugin names this plugin build, so Compliance → Diagnostics in the
	// Conzent app can tell whether the site runs the current release.
	echo '<script src="' . esc_url( $src ) . '" data-key="' . esc_attr( $key ) . '" data-plugin="wordpress/' . esc_attr( GETCONZENT_CMP_PLUGIN_VERSION ) . '" data-no-optimize="1" data-no-defer="1" data-cfasync="false" nowprocket></script>' . "\n";
	// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
}

function getconzent_cmp_enqueue_admin(): void {
	wp_enqueue_style(
		'getconzent-cmp-admin-css',
		GETCONZENT_CMP_PLUGIN_URL . 'assets/css/conzent-banner-admin.css',
		array(),
		GETCONZENT_CMP_PLUGIN_VERSION,
		'screen'
	);
}

/* --- Shortcodes ------------------------------------------------------- */

function getconzent_cmp_register_shortcodes(): void {
	add_shortcode( 'CONZENT_CONSENT_ID', 'getconzent_cmp_render_consent_id' );
	add_shortcode( 'conzent_consent_id', 'getconzent_cmp_render_consent_id' );
}

function getconzent_cmp_render_consent_id( $atts = array() ): string {
	return '<div class="gc-tracking-box"><div class="tracking-inner">'
		. '<div class="gc-label">' . esc_html__( 'Chilli Consent ID', 'getconzent-cmp-cookie-banner-consent-management' ) . '</div>'
		. '<div class="gc-val"><span id="conzentId"></span></div>'
		. '</div></div>';
}

/* --- Automatic setup: state --------------------------------------------- */

/**
 * Automatic setup is on unless wp-config.php defines CONZENT_AUTO_SETUP as false.
 */
function getconzent_cmp_claim_enabled(): bool {
	return ! ( defined( 'CONZENT_AUTO_SETUP' ) && false === CONZENT_AUTO_SETUP );
}

/**
 * The host visitors see, lowercase, without a port. home_url() rather than
 * site_url(): the latter is where WordPress lives, the former is the address
 * the banner runs on and the Conzent site record is keyed on.
 */
function getconzent_cmp_claim_domain(): string {
	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	return is_string( $host ) ? strtolower( $host ) : '';
}

function getconzent_cmp_claim_challenge_url(): string {
	return add_query_arg( 'conzent_claim', '1', home_url( '/' ) );
}

function getconzent_cmp_claim_new_token(): string {
	return bin2hex( random_bytes( 32 ) );
}

function getconzent_cmp_claim_schedule( int $when ): void {
	wp_clear_scheduled_hook( 'conzent_claim_attempt' );
	wp_schedule_single_event( max( $when, time() ), 'conzent_claim_attempt' );
}

/**
 * Start a fresh claim cycle: new token, attempt counter at zero, first attempt
 * after $delay seconds.
 */
function getconzent_cmp_claim_begin_cycle( int $delay ): void {
	$next = time() + max( 0, $delay );
	update_option( 'conzent_claim_state', 'pending' );
	update_option( 'conzent_claim_token', getconzent_cmp_claim_new_token() );
	update_option( 'conzent_claim_attempts', 0 );
	update_option( 'conzent_claim_next', $next );
	update_option( 'conzent_claim_error_code', '' );
	update_option( 'conzent_claim_error', '' );
	getconzent_cmp_claim_schedule( $next );
}

/**
 * A key entered or cleared by hand ends automatic setup for this site.
 */
function getconzent_cmp_claim_set_manual(): void {
	update_option( 'conzent_claim_state', 'manual' );
	update_option( 'conzent_claim_token', '' );
	update_option( 'conzent_claim_attempts', 0 );
	update_option( 'conzent_claim_next', 0 );
	update_option( 'conzent_claim_error_code', '' );
	update_option( 'conzent_claim_error', '' );
	wp_clear_scheduled_hook( 'conzent_claim_attempt' );
}

function getconzent_cmp_claim_clean( string $text ): string {
	$text = sanitize_text_field( $text );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
}

/**
 * One attempt at a time. An option rather than a transient because add_option
 * is atomic on the options table (the pattern core's own upgrader uses) and a
 * transient without an object cache is an option anyway.
 */
function getconzent_cmp_claim_lock(): bool {
	if ( add_option( 'conzent_claim_lock', (string) time(), '', 'no' ) ) {
		return true;
	}
	$held = (int) get_option( 'conzent_claim_lock', 0 );
	if ( $held > 0 && time() - $held > 90 ) {
		delete_option( 'conzent_claim_lock' );
		return (bool) add_option( 'conzent_claim_lock', (string) time(), '', 'no' );
	}
	return false;
}

function getconzent_cmp_claim_unlock(): void {
	delete_option( 'conzent_claim_lock' );
}

/**
 * Cadence between attempts while the site has no key: every 5 minutes for the
 * first hour, every 30 minutes for the rest of the first day, then hourly for
 * as long as it takes, with up to ten percent of jitter so many sites on one
 * host do not fire together. Nothing ever gives up: create the site in
 * Conzent, or un-suspend it, and the next attempt picks it up. A Retry-After
 * from the server wins.
 */
function getconzent_cmp_claim_delay( int $attempts, int $retry_after = 0 ): int {
	if ( $retry_after > 0 ) {
		return min( max( $retry_after, 60 ), 86400 );
	}
	if ( $attempts <= 12 ) {
		$base = 300;
	} elseif ( $attempts <= 58 ) {
		$base = 1800;
	} else {
		$base = 3600;
	}
	$jitter = (int) round( $base * wp_rand( -10, 10 ) / 100 );
	return max( 60, $base + $jitter );
}

/**
 * Codes that wait for a person: something to do in the Chilli Consent account, or a
 * configuration to fix. Everything else is transient and simply tried again.
 */
function getconzent_cmp_claim_needs_human( string $code ): bool {
	return in_array( $code, array( 'no_site', 'suspended', 'ambiguous', 'invalid_request', 'blocked', 'unsupported', 'redirect', 'unexpected' ), true );
}

/* --- Automatic setup: responder, bootstrap, runner ----------------------- */

/**
 * While a claim is pending the site answers `?conzent_claim=1` with the
 * pending token as plain text, so the Conzent server can confirm that the
 * plugin runs on this domain. Nothing from the request is read or used; the
 * token is random, published only while pending, and unlocks nothing but a
 * website key that is printed in this site's own page source once set.
 */
function getconzent_cmp_claim_responder(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only endpoint by design; the value is never used.
	if ( ! isset( $_GET['conzent_claim'] ) || is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
		return;
	}
	if ( 'pending' !== get_option( 'conzent_claim_state' ) ) {
		return;
	}
	$token = (string) get_option( 'conzent_claim_token', '' );
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Conzent-Claim: ' . $token );
	echo $token; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated hexadecimal string.
	exit;
}

/**
 * An empty key always means automatic setup: start a claim for any blog that
 * has no key and no claim pending (network activation only runs the
 * activation hook on the main site; an older install may never have had a
 * key), and make sure a due attempt has a cron event to run in.
 */
function getconzent_cmp_claim_bootstrap(): void {
	if ( wp_doing_cron() || wp_doing_ajax() ) {
		return;
	}
	$state = (string) get_option( 'conzent_claim_state', '' );
	if ( 'pending' !== $state ) {
		if ( '' === (string) get_option( 'conzent_website_key', '' ) && getconzent_cmp_claim_enabled() ) {
			// A network of hundreds of blogs spreads its claims over ten minutes.
			getconzent_cmp_claim_begin_cycle( is_multisite() ? wp_rand( 0, 600 ) : 0 );
		}
		return;
	}
	if ( time() >= (int) get_option( 'conzent_claim_next', 0 ) && ! wp_next_scheduled( 'conzent_claim_attempt' ) ) {
		wp_schedule_single_event( time(), 'conzent_claim_attempt' );
	}
}

/**
 * For hosts where cron does not fire: an administrator visiting the settings
 * page, or any admin page once the attempt is ten minutes overdue, runs it
 * inline. At most one wait per backoff step, only ever for an administrator.
 */
function getconzent_cmp_claim_admin_watchdog(): void {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'pending' !== get_option( 'conzent_claim_state' ) ) {
		return;
	}
	$next = (int) get_option( 'conzent_claim_next', 0 );
	if ( time() < $next ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which admin page is open, nothing is written from it.
	$on_settings = isset( $_GET['page'] ) && 'gc_banner_setting' === sanitize_key( wp_unslash( $_GET['page'] ) );
	if ( $on_settings || time() - $next > 600 ) {
		getconzent_cmp_claim_run();
	}
}

function getconzent_cmp_claim_cron(): void {
	getconzent_cmp_claim_run();
}

/**
 * One attempt. Returns outcome (`success`, `retry`, `waiting`, `busy`, `noop`),
 * the error code and a human message.
 *
 * @param bool $force Run now even if not pending or not due (Retry, WP-CLI).
 */
function getconzent_cmp_claim_run( bool $force = false ): array {
	$state = (string) get_option( 'conzent_claim_state', '' );
	if ( 'pending' !== $state && ! $force ) {
		return array( 'outcome' => 'noop', 'code' => '', 'message' => __( 'Automatic setup is not pending.', 'getconzent-cmp-cookie-banner-consent-management' ) );
	}
	if ( ! $force && time() < (int) get_option( 'conzent_claim_next', 0 ) ) {
		return array( 'outcome' => 'noop', 'code' => '', 'message' => __( 'The next attempt is not due yet.', 'getconzent-cmp-cookie-banner-consent-management' ) );
	}
	if ( ! getconzent_cmp_claim_lock() ) {
		return array( 'outcome' => 'busy', 'code' => 'busy', 'message' => __( 'Another attempt is running.', 'getconzent-cmp-cookie-banner-consent-management' ) );
	}

	try {
		if ( 'pending' !== $state ) {
			getconzent_cmp_claim_begin_cycle( 0 );
		}
		$token = (string) get_option( 'conzent_claim_token', '' );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			$token = getconzent_cmp_claim_new_token();
			update_option( 'conzent_claim_token', $token );
		}

		$domain = getconzent_cmp_claim_domain();
		update_option( 'conzent_claim_domain', $domain );

		if ( '' === $domain ) {
			$result = array(
				'outcome' => 'waiting',
				'code'    => 'invalid_request',
				'message' => __( 'This site has no public hostname to claim. Enter the website key by hand.', 'getconzent-cmp-cookie-banner-consent-management' ),
			);
		} else {
			$response = wp_safe_remote_post(
				getconzent_cmp_get_api_url() . '/plugin/claim',
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'limit_response_size' => 65536,
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'user-agent'  => 'ChilliConsentWP/' . GETCONZENT_CMP_PLUGIN_VERSION . ' (' . $domain . ')',
					'body'        => wp_json_encode(
						array(
							'domain'         => $domain,
							'challenge_url'  => getconzent_cmp_claim_challenge_url(),
							'token'          => $token,
							'plugin'         => 'wordpress',
							'plugin_version' => GETCONZENT_CMP_PLUGIN_VERSION,
						)
					),
				)
			);
			$result   = getconzent_cmp_claim_classify( $response );
		}

		return getconzent_cmp_claim_apply( $result );
	} finally {
		getconzent_cmp_claim_unlock();
	}
}

/**
 * Turn the server's answer into success, retry or stop.
 *
 * @param array|WP_Error $response The wp_remote_post() result.
 */
function getconzent_cmp_claim_classify( $response ): array {
	$server = getconzent_cmp_get_server_url();

	if ( is_wp_error( $response ) ) {
		if ( 'http_request_not_executed' === $response->get_error_code() ) {
			$host = (string) wp_parse_url( $server, PHP_URL_HOST );
			return array(
				'outcome' => 'waiting',
				'code'    => 'blocked',
				/* translators: %s: the Conzent server hostname */
				'message' => sprintf( __( 'Outbound HTTP is blocked on this site (WP_HTTP_BLOCK_EXTERNAL). Add %s to WP_ACCESSIBLE_HOSTS or enter the website key by hand.', 'getconzent-cmp-cookie-banner-consent-management' ), $host ),
			);
		}
		return array(
			'outcome' => 'retry',
			'code'    => 'network',
			/* translators: 1: the Conzent server URL, 2: the error text */
			'message' => sprintf( __( 'Automatic setup could not reach %1$s: %2$s', 'getconzent-cmp-cookie-banner-consent-management' ), $server, $response->get_error_message() ),
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );
	$data   = json_decode( $body, true );

	if ( $status >= 300 && $status < 400 ) {
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		return array(
			'outcome' => 'waiting',
			'code'    => 'redirect',
			/* translators: %s: the address the server redirects to */
			'message' => sprintf( __( 'The Server URL redirects to %s. Set the Server URL to that address.', 'getconzent-cmp-cookie-banner-consent-management' ), $location ),
		);
	}

	if ( ! is_array( $data ) ) {
		if ( 404 === $status ) {
			return array(
				'outcome' => 'waiting',
				'code'    => 'unsupported',
				/* translators: %s: the Conzent server URL */
				'message' => sprintf( __( 'The Chilli Consent server at %s does not support automatic setup. Enter the website key by hand.', 'getconzent-cmp-cookie-banner-consent-management' ), $server ),
			);
		}
		if ( $status >= 500 ) {
			return array(
				'outcome' => 'retry',
				'code'    => 'server_error',
				/* translators: %d: the HTTP status */
				'message' => sprintf( __( 'The Chilli Consent server answered HTTP %d.', 'getconzent-cmp-cookie-banner-consent-management' ), $status ),
			);
		}
		return array(
			'outcome' => 'waiting',
			'code'    => 'unexpected',
			/* translators: %d: the HTTP status */
			'message' => sprintf( __( 'The Chilli Consent server answered HTTP %d with an unexpected body.', 'getconzent-cmp-cookie-banner-consent-management' ), $status ),
		);
	}

	if ( ! empty( $data['success'] ) && ! empty( $data['data']['website_key'] ) && is_array( $data['data'] ) ) {
		return array(
			'outcome' => 'success',
			'code'    => 'claimed',
			'message' => '',
			'site'    => $data['data'],
		);
	}

	$error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
	$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
	$message = isset( $error['message'] ) ? (string) $error['message'] : sprintf( 'HTTP %d', $status );
	if ( ! empty( $error['reason'] ) ) {
		$message .= ' (' . (string) $error['reason'] . ')';
	}

	switch ( $code ) {
		case 'invalid_request':
		case 'no_site':
		case 'ambiguous':
		case 'suspended':
			return array( 'outcome' => 'waiting', 'code' => $code, 'message' => $message );

		case 'challenge_unreachable':
		case 'challenge_mismatch':
			return array( 'outcome' => 'retry', 'code' => $code, 'message' => $message );

		case 'rate_limited':
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry_after <= 0 && isset( $error['retry_after'] ) ) {
				$retry_after = (int) $error['retry_after'];
			}
			return array( 'outcome' => 'retry', 'code' => $code, 'message' => $message, 'retry_after' => $retry_after );
	}

	if ( $status >= 500 ) {
		return array( 'outcome' => 'retry', 'code' => 'server_error', 'message' => $message );
	}
	return array( 'outcome' => 'waiting', 'code' => '' === $code ? 'unexpected' : $code, 'message' => $message );
}

/**
 * Record an attempt's result and move the state machine.
 */
function getconzent_cmp_claim_apply( array $result ): array {
	$message = getconzent_cmp_claim_clean( (string) ( $result['message'] ?? '' ) );

	if ( 'success' === $result['outcome'] ) {
		$site = $result['site'];
		update_option( 'conzent_website_key', sanitize_text_field( (string) $site['website_key'] ) );
		update_option( 'conzent_site_name', sanitize_text_field( (string) ( $site['site_name'] ?? '' ) ) );
		update_option( 'conzent_site_domain', sanitize_text_field( (string) ( $site['domain'] ?? '' ) ) );
		update_option( 'conzent_site_status', sanitize_text_field( (string) ( $site['status'] ?? '' ) ) );
		update_option( 'conzent_site_id', sanitize_text_field( (string) ( $site['site_id'] ?? '' ) ) );
		update_option( 'conzent_verified', 'yes' );
		update_option( 'chilli_consent_key_verified', 'yes' );
		update_option( 'conzent_error', '' );
		update_option( 'conzent_claim_state', 'claimed' );
		update_option( 'conzent_claim_at', time() );
		update_option( 'conzent_claim_token', '' );
		update_option( 'conzent_claim_attempts', 0 );
		update_option( 'conzent_claim_next', 0 );
		update_option( 'conzent_claim_error_code', '' );
		update_option( 'conzent_claim_error', '' );
		wp_clear_scheduled_hook( 'conzent_claim_attempt' );

		return array(
			'outcome' => 'success',
			'code'    => 'claimed',
			/* translators: %s: the site's domain */
			'message' => sprintf( __( 'Configured automatically for %s.', 'getconzent-cmp-cookie-banner-consent-management' ), get_option( 'conzent_claim_domain', '' ) ),
		);
	}

	// Anything but success is tried again, on the slow cadence, for as long
	// as the site has no key. Whether it waits for a person or for the network
	// only changes what the notice says.
	$code     = sanitize_key( (string) ( $result['code'] ?? 'unexpected' ) );
	$attempts = (int) get_option( 'conzent_claim_attempts', 0 ) + 1;
	$next     = time() + getconzent_cmp_claim_delay( $attempts, (int) ( $result['retry_after'] ?? 0 ) );
	update_option( 'conzent_claim_state', 'pending' );
	update_option( 'conzent_claim_error_code', $code );
	update_option( 'conzent_claim_error', $message );
	update_option( 'conzent_claim_attempts', $attempts );
	update_option( 'conzent_claim_next', $next );
	getconzent_cmp_claim_schedule( $next );

	return array(
		'outcome' => getconzent_cmp_claim_needs_human( $code ) ? 'waiting' : 'retry',
		'code'    => $code,
		'message' => $message,
	);
}

/* --- Automatic setup: admin ------------------------------------------- */

function getconzent_cmp_claim_notice_tuple(): string {
	return get_option( 'conzent_claim_state', '' ) . '|' . get_option( 'conzent_claim_error_code', '' ) . '|' . get_option( 'conzent_claim_token', '' );
}

function getconzent_cmp_claim_retry_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'getconzent-cmp-cookie-banner-consent-management' ), 403 );
	}
	check_admin_referer( 'conzent_claim_retry' );
	getconzent_cmp_claim_begin_cycle( 0 );
	getconzent_cmp_claim_run( true );
	wp_safe_redirect( admin_url( 'admin.php?page=gc_banner_setting' ) );
	exit;
}

function getconzent_cmp_claim_dismiss_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'getconzent-cmp-cookie-banner-consent-management' ), 403 );
	}
	check_admin_referer( 'conzent_claim_dismiss' );
	update_user_meta( get_current_user_id(), 'conzent_claim_dismissed', getconzent_cmp_claim_notice_tuple() );
	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url() );
	exit;
}

/**
 * The Retry button: its own form, because the settings page already wraps its
 * fields in one and forms do not nest.
 */
function getconzent_cmp_claim_retry_button( string $label ): string {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;">'
		. wp_nonce_field( 'conzent_claim_retry', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="conzent_claim_retry" />'
		. '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button>'
		. '</form>';
}

function getconzent_cmp_claim_admin_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
		return;
	}
	$state    = (string) get_option( 'conzent_claim_state', '' );
	$attempts = (int) get_option( 'conzent_claim_attempts', 0 );
	if ( 'pending' !== $state || $attempts < 1 ) {
		return;
	}
	if ( get_user_meta( get_current_user_id(), 'conzent_claim_dismissed', true ) === getconzent_cmp_claim_notice_tuple() ) {
		return;
	}

	$domain  = (string) get_option( 'conzent_claim_domain', '' );
	$domain  = '' !== $domain ? $domain : getconzent_cmp_claim_domain();
	$message = (string) get_option( 'conzent_claim_error', '' );
	$code    = (string) get_option( 'conzent_claim_error_code', '' );
	$next    = (int) get_option( 'conzent_claim_next', 0 );
	$in      = human_time_diff( time(), max( time() + 60, $next ) );

	if ( getconzent_cmp_claim_needs_human( $code ) ) {
		$class = 'notice-warning';
		/* translators: 1: the site's domain, 2: a human time difference */
		$lead = sprintf( __( 'Chilli Consent automatic setup for %1$s is waiting for you. The plugin checks again in %2$s and keeps checking until the site is set up.', 'getconzent-cmp-cookie-banner-consent-management' ), $domain, $in );
	} else {
		$class = 'notice-info';
		/* translators: 1: the site's domain, 2: a human time difference */
		$lead = sprintf( __( 'Chilli Consent automatic setup for %1$s is still trying. Next attempt in %2$s.', 'getconzent-cmp-cookie-banner-consent-management' ), $domain, $in );
	}

	echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html( $lead ) . '</strong>';
	if ( '' !== $message ) {
		echo ' ' . esc_html( $message );
	}
	echo '</p><p>';
	echo getconzent_cmp_claim_retry_button( __( 'Retry now', 'getconzent-cmp-cookie-banner-consent-management' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	if ( in_array( $code, array( 'no_site', 'suspended', 'ambiguous' ), true ) ) {
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( getconzent_cmp_get_server_url() . '/sites' ) . '" style="margin-right:6px;">' . esc_html__( 'Open Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' ) . '</a>';
	}
	echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=gc_banner_setting' ) ) . '" style="margin-right:6px;">' . esc_html__( 'Settings', 'getconzent-cmp-cookie-banner-consent-management' ) . '</a>';
	echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=conzent_claim_dismiss' ), 'conzent_claim_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'getconzent-cmp-cookie-banner-consent-management' ) . '</a>';
	echo '</p></div>';
}

/**
 * One line describing the setup state, for the overview and settings pages.
 */
function getconzent_cmp_claim_status_text(): string {
	$state = (string) get_option( 'conzent_claim_state', '' );
	$key   = (string) get_option( 'conzent_website_key', '' );
	switch ( $state ) {
		case 'claimed':
			/* translators: 1: the site's domain, 2: a date */
			return sprintf( __( 'Configured automatically for %1$s on %2$s.', 'getconzent-cmp-cookie-banner-consent-management' ), get_option( 'conzent_site_domain', '' ) ? get_option( 'conzent_site_domain', '' ) : get_option( 'conzent_claim_domain', '' ), date_i18n( get_option( 'date_format' ), (int) get_option( 'conzent_claim_at', 0 ) ) );
		case 'pending':
			$next     = (int) get_option( 'conzent_claim_next', 0 );
			$attempts = (int) get_option( 'conzent_claim_attempts', 0 );
			$in       = human_time_diff( time(), max( time() + 60, $next ) );
			if ( $attempts < 1 ) {
				/* translators: %s: a human time difference */
				return sprintf( __( 'Automatic setup runs in %s.', 'getconzent-cmp-cookie-banner-consent-management' ), $in );
			}
			/* translators: 1: the last message from the server, 2: a human time difference */
			return sprintf( __( '%1$s The plugin checks again in %2$s and keeps checking until the site is set up.', 'getconzent-cmp-cookie-banner-consent-management' ), (string) get_option( 'conzent_claim_error', '' ), $in );
		case 'manual':
			return '' !== $key
				? __( 'Configured manually.', 'getconzent-cmp-cookie-banner-consent-management' )
				: __( 'No website key yet.', 'getconzent-cmp-cookie-banner-consent-management' );
	}
	return getconzent_cmp_claim_enabled()
		? __( 'Automatic setup has not run yet.', 'getconzent-cmp-cookie-banner-consent-management' )
		: __( 'Automatic setup is switched off in wp-config.php.', 'getconzent-cmp-cookie-banner-consent-management' );
}

/** Actual API verification, separate from the legacy flag that enables loading. */
function getconzent_cmp_key_verification_status(): string {
	if ( '' === (string) get_option( 'conzent_website_key', '' ) ) {
		return __( 'Not configured', 'getconzent-cmp-cookie-banner-consent-management' );
	}
	return 'yes' === get_option( 'chilli_consent_key_verified', '' )
		? __( 'Verified with Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' )
		: __( 'Configured — verification pending', 'getconzent-cmp-cookie-banner-consent-management' );
}

/* --- Admin pages ------------------------------------------------------ */

function getconzent_cmp_register_admin_menu(): void {
	add_menu_page(
		__( 'Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' ),
		__( 'Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' ),
		'manage_options',
		'getconzent',
		'getconzent_cmp_render_overview_page',
		'dashicons-shield',
		90
	);
	add_submenu_page(
		'getconzent',
		__( 'Settings', 'getconzent-cmp-cookie-banner-consent-management' ),
		__( 'Settings', 'getconzent-cmp-cookie-banner-consent-management' ),
		'manage_options',
		'gc_banner_setting',
		'getconzent_cmp_render_settings_page',
		1
	);
}

function getconzent_cmp_render_overview_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'getconzent-cmp-cookie-banner-consent-management' ) );
	}
	$setting_url = esc_url( admin_url( 'admin.php?page=gc_banner_setting' ) );
	$web_url     = esc_url( getconzent_cmp_get_server_url() );
	$verification_status = getconzent_cmp_key_verification_status();
	$key         = get_option( 'conzent_website_key', '' );
	?>
	<div class="opt_welcome">
		<h2><?php esc_html_e( 'Welcome to Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' ); ?></h2>
	</div>
	<div class="opt_box_welcome">
		<div class="opt_item">
			<div class="opt_key"><?php esc_html_e( 'Website Key:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></div>
			<div class="opt_val"><?php echo esc_html( $key ); ?></div>
		</div>
		<div class="opt_item">
			<div class="opt_key"><?php esc_html_e( 'Verification:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></div>
			<div class="opt_val"><?php echo esc_html( $verification_status ); ?></div>
		</div>
		<div class="opt_item">
			<div class="opt_key"><?php esc_html_e( 'Setup:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></div>
			<div class="opt_val"><?php echo esc_html( getconzent_cmp_claim_status_text() ); ?></div>
		</div>
		<div style="margin:10px 0;">
			<a href="<?php echo esc_url( $setting_url ); ?>" class="gc-btn"><?php esc_html_e( 'Settings', 'getconzent-cmp-cookie-banner-consent-management' ); ?></a>
		</div>
		<div style="margin-top:20px;">
			<a href="<?php echo esc_url( $web_url ); ?>" class="gc-btn-normal" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Chilli Consent Dashboard', 'getconzent-cmp-cookie-banner-consent-management' ); ?></a>
		</div>
	</div>
	<?php
}

function getconzent_cmp_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'getconzent-cmp-cookie-banner-consent-management' ) );
	}

	$msg       = '';
	$css_class = '';

	// Handle form submission.
	if ( isset( $_POST['gc_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gc_settings_nonce'] ) ), 'gc_save_settings' ) ) {

		$website_key = sanitize_text_field( wp_unslash( $_POST['conzent_website_key'] ?? '' ) );
		$server_url  = esc_url_raw( wp_unslash( $_POST['conzent_server_url'] ?? '' ) );

		if ( ! getconzent_cmp_server_url_is_constant() ) {
			update_option( 'conzent_server_url', $server_url );
		}

		// Verify website key against the API.
		if ( ! empty( $website_key ) ) {
			$site_info = getconzent_cmp_verify_website( $website_key );

			if ( ! empty( $site_info ) && isset( $site_info['domain'] ) ) {
				update_option( 'conzent_website_key', sanitize_text_field( $site_info['website_key'] ?? $website_key ) );
				update_option( 'conzent_site_name', sanitize_text_field( $site_info['site_name'] ?? '' ) );
				update_option( 'conzent_site_domain', sanitize_text_field( $site_info['domain'] ?? '' ) );
				update_option( 'conzent_site_status', sanitize_text_field( (string) ( $site_info['status'] ?? '' ) ) );
				update_option( 'conzent_site_id', sanitize_text_field( (string) ( $site_info['id'] ?? '' ) ) );
				update_option( 'conzent_verified', 'yes' );
				update_option( 'chilli_consent_key_verified', 'yes' );
				$msg       = __( 'Settings saved and website verified.', 'getconzent-cmp-cookie-banner-consent-management' );
				$css_class = 'success';
			} else {
				// API unreachable or key not found -- still save the key.
				update_option( 'conzent_website_key', $website_key );
				update_option( 'conzent_site_name', '' );
				update_option( 'conzent_site_domain', '' );
				update_option( 'conzent_site_status', '' );
				update_option( 'conzent_site_id', '' );
				// Keep the loader enabled without asserting successful API verification.
				update_option( 'conzent_verified', 'yes' );
				update_option( 'chilli_consent_key_verified', 'pending' );
				update_option( 'conzent_error', '' );
				$msg       = __( 'Website key saved. Verification is pending or failed; the banner loader remains enabled. Check the key and service connection, then save again.', 'getconzent-cmp-cookie-banner-consent-management' );
				$css_class = 'warning';
			}
		} else {
			update_option( 'conzent_website_key', '' );
			update_option( 'conzent_verified', '' );
			update_option( 'chilli_consent_key_verified', '' );
			$msg       = __( 'Settings saved.', 'getconzent-cmp-cookie-banner-consent-management' );
			$css_class = 'success';
		}

		// A key typed in by hand is the administrator's decision and is kept.
		// An empty key always means automatic setup, so clearing it starts a claim.
		if ( ! empty( $website_key ) ) {
			getconzent_cmp_claim_set_manual();
		} elseif ( getconzent_cmp_claim_enabled() ) {
			getconzent_cmp_claim_begin_cycle( 0 );
		}
	}

	$website_key  = get_option( 'conzent_website_key', '' );
	$server_url   = getconzent_cmp_server_url_is_constant() ? getconzent_cmp_get_server_url() : get_option( 'conzent_server_url', '' );
	$verification_status = getconzent_cmp_key_verification_status();
	$claim_state  = (string) get_option( 'conzent_claim_state', '' );
	$claim_code   = (string) get_option( 'conzent_claim_error_code', '' );
	?>
	<div class="opt_welcome">
		<h2><?php esc_html_e( 'Chilli Consent Settings', 'getconzent-cmp-cookie-banner-consent-management' ); ?></h2>
	</div>
	<div class="opt_box_setting">
		<?php if ( $msg ) : ?>
			<div class="gc-<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $msg ); ?></div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'gc_save_settings', 'gc_settings_nonce' ); ?>

			<div class="opt_item">
				<div class="opt_key">
					<label for="gc_website_key"><?php esc_html_e( 'Website Key:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></label>
				</div>
				<div class="opt_val">
					<input type="text" name="conzent_website_key" id="gc_website_key"
						   value="<?php echo esc_attr( $website_key ); ?>" style="min-width:300px;" />
					<p class="description"><?php esc_html_e( 'Find this in your Chilli Consent dashboard under Site Settings.', 'getconzent-cmp-cookie-banner-consent-management' ); ?></p>
				</div>
			</div>

			<div class="opt_item">
				<div class="opt_key">
					<label for="gc_server_url"><?php esc_html_e( 'Server URL:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></label>
				</div>
				<div class="opt_val">
					<input type="url" name="conzent_server_url" id="gc_server_url"
						   value="<?php echo esc_attr( $server_url ); ?>"
						   placeholder="https://consent.chillimedia.se" style="min-width:300px;"<?php echo getconzent_cmp_server_url_is_constant() ? ' readonly' : ''; ?> />
					<?php if ( getconzent_cmp_server_url_is_constant() ) : ?>
						<p class="description"><?php esc_html_e( 'Managed by Chillimedia. This connector uses consent.chillimedia.se.', 'getconzent-cmp-cookie-banner-consent-management' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'This connector uses consent.chillimedia.se.', 'getconzent-cmp-cookie-banner-consent-management' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="opt_item">
				<div class="opt_key"><?php esc_html_e( 'Status:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></div>
				<div class="opt_val"><?php echo esc_html( $verification_status ); ?></div>
			</div>

			<div class="action_box">
				<input type="submit" name="gc_save" value="<?php esc_attr_e( 'Save Settings', 'getconzent-cmp-cookie-banner-consent-management' ); ?>" class="gc-btn" />
			</div>
		</form>

		<div class="opt_item" style="margin-top:20px;">
			<div class="opt_key"><?php esc_html_e( 'Automatic setup:', 'getconzent-cmp-cookie-banner-consent-management' ); ?></div>
			<div class="opt_val">
				<p style="margin-top:0;"><?php echo esc_html( getconzent_cmp_claim_status_text() ); ?></p>
				<?php if ( getconzent_cmp_claim_enabled() ) : ?>
					<?php
					$label = 'pending' === $claim_state
						? __( 'Retry now', 'getconzent-cmp-cookie-banner-consent-management' )
						: __( 'Configure automatically', 'getconzent-cmp-cookie-banner-consent-management' );
					echo getconzent_cmp_claim_retry_button( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					if ( in_array( $claim_code, array( 'no_site', 'suspended', 'ambiguous' ), true ) ) {
						echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( getconzent_cmp_get_server_url() . '/sites' ) . '">' . esc_html__( 'Create site in Chilli Consent', 'getconzent-cmp-cookie-banner-consent-management' ) . '</a>';
					}
					?>
					<p class="description"><?php esc_html_e( 'Automatic setup asks Chilli Consent for the website key of this domain and keeps asking until it gets one. The site must exist in your Chilli Consent account; nothing is created from here.', 'getconzent-cmp-cookie-banner-consent-management' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div style="margin-top:20px;">
			<a href="<?php echo esc_url( getconzent_cmp_get_server_url() ); ?>" class="gc-btn-normal" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open Chilli Consent Dashboard', 'getconzent-cmp-cookie-banner-consent-management' ); ?>
			</a>
		</div>
	</div>
	<?php
}

/* --- API verification ------------------------------------------------- */

function getconzent_cmp_verify_website( string $website_key ): array {
	$api_url  = getconzent_cmp_get_api_url() . '/verify?website_id=' . rawurlencode( $website_key );
	$response = wp_safe_remote_get( esc_url_raw( $api_url ), array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 65536 ) );

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return array();
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) ) {
		return array();
	}

	return $data;
}

/* --- WP-CLI ------------------------------------------------------------ */

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'conzent claim',
		function ( $args, $assoc_args ) {
			$result = getconzent_cmp_claim_run( isset( $assoc_args['force'] ) );
			switch ( $result['outcome'] ) {
				case 'success':
					WP_CLI::success( $result['message'] );
					return;
				case 'noop':
				case 'busy':
					WP_CLI::log( $result['message'] );
					return;
				case 'retry':
				case 'waiting':
					WP_CLI::warning( $result['code'] . ': ' . $result['message'] );
					WP_CLI::halt( 1 );
					return;
				default:
					WP_CLI::error( $result['code'] . ': ' . $result['message'] );
			}
		},
		array(
			'shortdesc' => 'Ask Chilli Consent for this site\'s website key by proving control of the domain.',
			'synopsis'  => array(
				array(
					'type'     => 'flag',
					'name'     => 'force',
					'optional' => true,
					'description' => 'Start a new attempt now, whatever the current state.',
				),
			),
		)
	);
	WP_CLI::add_command(
		'conzent status',
		function () {
			$next = (int) get_option( 'conzent_claim_next', 0 );
			$rows = array(
				array( 'field' => 'state', 'value' => (string) get_option( 'conzent_claim_state', '' ) ),
				array( 'field' => 'domain', 'value' => (string) get_option( 'conzent_claim_domain', '' ) ),
				array( 'field' => 'attempts', 'value' => (string) (int) get_option( 'conzent_claim_attempts', 0 ) ),
				array( 'field' => 'next', 'value' => $next > 0 ? gmdate( 'c', $next ) : '' ),
				array( 'field' => 'error_code', 'value' => (string) get_option( 'conzent_claim_error_code', '' ) ),
				array( 'field' => 'error', 'value' => (string) get_option( 'conzent_claim_error', '' ) ),
				array( 'field' => 'website_key', 'value' => '' !== (string) get_option( 'conzent_website_key', '' ) ? 'set' : 'empty' ),
				array( 'field' => 'server', 'value' => getconzent_cmp_get_server_url() ),
			);
			WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
		},
		array( 'shortdesc' => 'Show the automatic setup state of this site.' )
	);
}
