<?php
/**
 * PHPUnit bootstrap for pure, WordPress-free tests.
 *
 * @package HealthLens
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
defined( 'HEALTHLENS_VERSION' ) || define( 'HEALTHLENS_VERSION', '0.1.0' );

function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
	if ( ! array_key_exists( $option, $GLOBALS['healthlens_test_options'] ) ) {
		$GLOBALS['healthlens_test_options'][ $option ] = $value;
		$GLOBALS['healthlens_test_autoload'][ $option ] = $autoload;
		return true;
	}

	return false;
}

function get_option( $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['healthlens_test_options'] ) ? $GLOBALS['healthlens_test_options'][ $option ] : $default;
}

function is_wp_error( $value ) {
	return is_object( $value ) && $value instanceof WP_Error;
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WP_Error test double. */
	class WP_Error {
		/** @var array<int, string> */
		private $codes;
		/** @param string $code Error code. */
		public function __construct( $code ) { $this->codes = array( $code ); }
		/** @return array<int, string> */
		public function get_error_codes() { return $this->codes; }
	}
}

function update_option( $option, $value, $autoload = null ) {
	$GLOBALS['healthlens_test_options'][ $option ]  = $value;
	$GLOBALS['healthlens_test_autoload'][ $option ] = $autoload;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['healthlens_test_filters'][] = $hook;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['healthlens_test_actions'][] = $hook;
	$GLOBALS['healthlens_test_action_callbacks'][ $hook ] = $callback;
	return true;
}

function did_action( $hook ) {
	return isset( $GLOBALS['healthlens_test_did_actions'][ $hook ] ) ? $GLOBALS['healthlens_test_did_actions'][ $hook ] : 0;
}

function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
	return ! empty( $GLOBALS['healthlens_test_action_scheduler_scheduled'] ) ? 1 : false;
}

function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '', $unique = false ) {
	$GLOBALS['healthlens_test_action_scheduler_scheduled'] = array( 'hook' => $hook, 'group' => $group, 'interval' => $interval );
	return 1;
}

function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	$GLOBALS['healthlens_test_action_scheduler_unscheduled'][] = array( 'hook' => $hook, 'group' => $group );
	unset( $GLOBALS['healthlens_test_action_scheduler_scheduled'] );
	return 1;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url = '', $position = null ) {
	$GLOBALS['healthlens_test_menu_pages'][] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
		'menu_slug'  => $menu_slug,
		'callback'   => $callback,
		'icon_url'   => $icon_url,
		'position'   => $position,
	);

	return 'toplevel_page_' . $menu_slug;
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) {
	$GLOBALS['healthlens_test_submenu_pages'][] = array(
		'parent_slug' => $parent_slug,
		'page_title'  => $page_title,
		'menu_title'  => $menu_title,
		'capability'  => $capability,
		'menu_slug'   => $menu_slug,
		'callback'    => $callback,
	);

	return 'healthlens_page_' . $menu_slug;
}

function register_setting( $option_group, $option_name, $args = array() ) {
	$GLOBALS['healthlens_test_settings'][] = array(
		'option_group' => $option_group,
		'option_name'  => $option_name,
		'args'         => $args,
	);
}

function add_settings_section( $id, $title, $callback, $page ) {
	$GLOBALS['healthlens_test_settings_sections'][] = array(
		'id'       => $id,
		'title'    => $title,
		'callback' => $callback,
		'page'     => $page,
	);
}

function add_settings_field( $id, $title, $callback, $page, $section ) {
	$GLOBALS['healthlens_test_settings_fields'][] = array(
		'id'       => $id,
		'title'    => $title,
		'callback' => $callback,
		'page'     => $page,
		'section'  => $section,
	);
}

function current_user_can( $capability ) {
	return isset( $GLOBALS['healthlens_test_capabilities'][ $capability ] ) && $GLOBALS['healthlens_test_capabilities'][ $capability ];
}

function is_admin() {
	return ! empty( $GLOBALS['healthlens_test_is_admin'] );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['healthlens_test_styles'][] = array(
		'handle' => $handle,
		'src'    => $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'media'  => $media,
	);
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	$GLOBALS['healthlens_test_scripts'][] = array(
		'handle' => $handle,
		'src'    => $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'args'   => $args,
	);
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.org/wp-content/plugins/healthlens/' . ltrim( $path, '/' );
}

function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['healthlens_test_scheduled_events'][ $hook ] ) ? $GLOBALS['healthlens_test_scheduled_events'][ $hook ] : false;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	if ( false !== wp_next_scheduled( $hook ) ) {
		return false;
	}

	$GLOBALS['healthlens_test_scheduled_events'][ $hook ] = array(
		'timestamp'  => $timestamp,
		'recurrence' => false,
		'args'       => $args,
	);

	return true;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	if ( false !== wp_next_scheduled( $hook ) ) {
		return false;
	}

	$GLOBALS['healthlens_test_scheduled_events'][ $hook ] = array(
		'timestamp' => $timestamp,
		'recurrence' => $recurrence,
	);

	return true;
}

function dbDelta( $queries ) {
	$GLOBALS['healthlens_test_dbdelta_calls'] = isset( $GLOBALS['healthlens_test_dbdelta_calls'] ) ? $GLOBALS['healthlens_test_dbdelta_calls'] + 1 : 1;
	if ( isset( $GLOBALS['healthlens_test_dbdelta_throw_on_call'] ) && $GLOBALS['healthlens_test_dbdelta_throw_on_call'] === $GLOBALS['healthlens_test_dbdelta_calls'] ) {
		throw new RuntimeException( 'Simulated dbDelta failure.' );
	}

	$GLOBALS['healthlens_test_dbdelta'][] = $queries;
	return array();
}

function delete_option( $option ) {
	unset( $GLOBALS['healthlens_test_options'][ $option ], $GLOBALS['healthlens_test_autoload'][ $option ] );
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['healthlens_test_cleared_hooks'][] = $hook;
	unset( $GLOBALS['healthlens_test_scheduled_events'][ $hook ] );
	return 0;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function wp_kses( $string, $allowed_html ) {
	return $string;
}

function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $key, $value, $url ) {
	return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce">';
	if ( $echo ) {
		echo $field;
	}

	return $field;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	return 1;
}

function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
	$GLOBALS['healthlens_test_redirect'] = array( 'location' => $location, 'status' => $status );
	return true;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_html_class( $class, $fallback = '' ) {
	$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );

	return '' === $sanitized ? $fallback : $sanitized;
}

function wp_die( $message, $title = '', $args = array() ) {
	throw new RuntimeException( $message );
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function get_bloginfo( $show ) {
	return isset( $GLOBALS['healthlens_test_bloginfo'][ $show ] ) ? $GLOBALS['healthlens_test_bloginfo'][ $show ] : '';
}

function get_core_updates() {
	return isset( $GLOBALS['healthlens_test_core_updates'] ) ? $GLOBALS['healthlens_test_core_updates'] : false;
}

function is_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_email( $email ) {
	return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) );
}

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['healthlens_test_mails'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers );
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	return true;
}

function rest_url( $path = '' ) {
	return 'https://example.org/wp-json/' . ltrim( $path, '/' );
}

function home_url( $path = '' ) {
	return 'https://example.org/' . ltrim( $path, '/' );
}

$GLOBALS['healthlens_test_options']       = array();
$GLOBALS['healthlens_test_autoload']      = array();
$GLOBALS['healthlens_test_cleared_hooks'] = array();
$GLOBALS['healthlens_test_dbdelta']       = array();
$GLOBALS['healthlens_test_dbdelta_calls'] = 0;
$GLOBALS['healthlens_test_filters']       = array();
$GLOBALS['healthlens_test_actions']       = array();
$GLOBALS['healthlens_test_action_callbacks'] = array();
$GLOBALS['healthlens_test_menu_pages']     = array();
$GLOBALS['healthlens_test_submenu_pages']  = array();
$GLOBALS['healthlens_test_settings']       = array();
$GLOBALS['healthlens_test_settings_sections'] = array();
$GLOBALS['healthlens_test_settings_fields'] = array();
$GLOBALS['healthlens_test_capabilities']  = array();
$GLOBALS['healthlens_test_is_admin']       = false;
$GLOBALS['healthlens_test_styles']         = array();
$GLOBALS['healthlens_test_scripts']        = array();
$GLOBALS['healthlens_test_scheduled_events'] = array();
$GLOBALS['healthlens_test_bloginfo'] = array();
$GLOBALS['healthlens_test_core_updates'] = false;
$GLOBALS['healthlens_test_did_actions'] = array();
$GLOBALS['healthlens_test_action_scheduler_scheduled'] = array();
$GLOBALS['healthlens_test_action_scheduler_unscheduled'] = array();

require dirname( __DIR__ ) . '/vendor/autoload.php';
