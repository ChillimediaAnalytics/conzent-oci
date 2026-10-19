<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package GetConzent
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$getconzent_cmp_options = array(
	'conzent_website_key',
	'conzent_server_url',
	'conzent_site_name',
	'conzent_site_domain',
	'conzent_site_status',
	'conzent_site_id',
	'conzent_verified',
	'chilli_consent_key_verified',
	'conzent_error',
	'conzent_db_version',
	'conzent_plugin_error',
	'conzent_claim_state',
	'conzent_claim_token',
	'conzent_claim_attempts',
	'conzent_claim_next',
	'conzent_claim_domain',
	'conzent_claim_error_code',
	'conzent_claim_error',
	'conzent_claim_at',
	'conzent_claim_lock',
);

foreach ( $getconzent_cmp_options as $getconzent_cmp_option ) {
	delete_option( $getconzent_cmp_option );
}

wp_clear_scheduled_hook( 'conzent_claim_attempt' );
delete_metadata( 'user', 0, 'conzent_claim_dismissed', '', true );
