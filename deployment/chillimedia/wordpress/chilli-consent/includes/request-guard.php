<?php
/**
 * Decides whether a request is a visitor looking at the site.
 *
 * The consent script belongs on the public site and nowhere else. It holds
 * third-party and module scripts until the visitor has chosen, which is
 * exactly what a visitor's page needs and exactly what breaks an editor: a
 * page builder is such a bundle of scripts, and it edits on the front end,
 * where is_admin() is false.
 *
 * @package GetConzent_CMP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query keys that mark a page builder or editor request, by product.
 *
 * @return array<string, string[]>
 */
function getconzent_cmp_editor_keys(): array {
	return array(
		'divi'        => array( 'et_fb', 'et_bfb', 'et_tb', 'et_pb_preview', 'et_block_layout_preview' ),
		'elementor'   => array( 'elementor-preview', 'elementor_library' ),
		'beaver'      => array( 'fl_builder' ),
		'bricks'      => array( 'bricks' ),
		'oxygen'      => array( 'ct_builder', 'oxygen_iframe' ),
		'breakdance'  => array( 'breakdance', 'breakdance_iframe' ),
		'wpbakery'    => array( 'vc_editable', 'vc_action', 'vcv-editable' ),
		'brizy'       => array( 'brizy-edit', 'brizy-edit-iframe' ),
		'thrive'      => array( 'tve' ),
		'avada'       => array( 'fb-edit' ),
		'zion'        => array( 'zionbuilder-preview' ),
		'siteorigin'  => array( 'siteorigin_panels_live_editor' ),
		'customizer'  => array( 'customize_changeset_uuid', 'customize_theme' ),
	);
}

/**
 * Name the editing context a request belongs to, or '' for a visitor's page.
 *
 * No WordPress calls in here: the three inputs are everything it needs.
 *
 * A builder key only counts when the person may edit. Anyone can append
 * ?et_fb=1 to a link, and a parameter alone must never switch consent
 * management off for a visitor.
 *
 * @param array  $query    The query string, as key => value.
 * @param string $path     The request path, without the query string.
 * @param bool   $can_edit Whether the person is logged in and may edit content.
 */
function getconzent_cmp_editor_context( array $query, string $path, bool $can_edit ): string {
	$path = '/' . ltrim( strtolower( $path ), '/' );

	if ( false !== strpos( $path . '/', '/wp-admin/' ) ) {
		return 'admin';
	}
	foreach ( array( 'wp-login.php', 'wp-signup.php', 'wp-activate.php' ) as $file ) {
		if ( substr( $path, -strlen( '/' . $file ) ) === '/' . $file ) {
			return 'login';
		}
	}

	if ( ! $can_edit ) {
		return '';
	}
	foreach ( getconzent_cmp_editor_keys() as $product => $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $query ) ) {
				return $product;
			}
		}
	}

	return '';
}

/**
 * Whether the builders that are installed say, through their own API, that
 * they are editing this request. Catches the cases that carry no query key.
 */
function getconzent_cmp_builder_is_active(): string {
	if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
		return 'divi';
	}
	if ( function_exists( 'et_builder_is_tb_admin_screen' ) && et_builder_is_tb_admin_screen() ) {
		return 'divi';
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview )
		&& is_object( \Elementor\Plugin::$instance->preview )
		&& method_exists( \Elementor\Plugin::$instance->preview, 'is_preview_mode' )
		&& \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
		return 'elementor';
	}
	if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'is_builder_active' ) && FLBuilderModel::is_builder_active() ) {
		return 'beaver';
	}
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
		return 'bricks';
	}

	return '';
}

/**
 * The reason this request is not a visitor's page, or '' when it is one.
 */
function getconzent_cmp_request_context(): string {
	if ( is_admin() || ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
		return 'admin';
	}
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
		return 'background';
	}
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return 'api';
	}
	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return 'customizer';
	}
	// These three need the main query; before it has run they are not asked.
	if ( did_action( 'wp' ) && ( is_feed() || is_robots() || is_embed() ) ) {
		return 'machine';
	}

	$builder = getconzent_cmp_builder_is_active();
	if ( '' !== $builder ) {
		return $builder;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only the presence of a key is read, nothing is acted on.
	$query = is_array( $_GET ) ? $_GET : array();
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	$can_edit = is_user_logged_in() && current_user_can( 'edit_posts' );

	return getconzent_cmp_editor_context( $query, $path, $can_edit );
}

/**
 * True when the consent script should be on this page.
 *
 * Filter `getconzent_cmp_should_load` to add a builder this plugin does not
 * know, or to keep the script off a page of your own:
 *
 *     add_filter( 'getconzent_cmp_should_load', function ( $load, $context ) {
 *         return isset( $_GET['my_builder'] ) ? false : $load;
 *     }, 10, 2 );
 */
function getconzent_cmp_is_public_request(): bool {
	$context = getconzent_cmp_request_context();

	return (bool) apply_filters( 'getconzent_cmp_should_load', '' === $context, $context );
}
