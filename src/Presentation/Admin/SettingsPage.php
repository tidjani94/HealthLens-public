<?php
/**
 * HealthLens settings screen.
 *
 * @package HealthLens
 */

namespace HealthLens\Presentation\Admin;

use HealthLens\Plugin;
use HealthLens\Infrastructure\WordPress\OptionalGatewayTransport;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the small, per-site Settings API surface.
 */
final class SettingsPage {
	/** Settings page slug. */
	const PAGE_SLUG = 'healthlens-settings';
	/** Settings group name. */
	const SETTINGS_GROUP = 'healthlens_settings_group';
	/** Settings section ID. */
	const SECTION_ID = 'healthlens_settings_section';
	/** Documented retention field. */
	const RETENTION_FIELD = 'retain_data_on_uninstall';
	/** Opt-in PHP/WordPress error-capture field. */
	const CAPTURE_FIELD = Plugin::CAPTURE_FIELD;
	/** Opt-in local email notification field. */
	const NOTIFICATIONS_FIELD = 'notifications_enabled';
	/** Site-local notification recipient field. */
	const NOTIFICATION_EMAIL_FIELD = 'notification_email';
	/** Recovery notification preference. */
	const NOTIFICATION_RECOVERY_FIELD = 'notification_recovery';
	/** Disabled-by-default optional gateway consent. */
	const GATEWAY_FIELD = 'gateway_enabled';
	/** Fixed approved gateway endpoint setting. */
	const GATEWAY_ENDPOINT_FIELD = 'gateway_endpoint';
	/** Site-local gateway token setting. */
	const GATEWAY_TOKEN_FIELD = 'gateway_token';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings submenu below the HealthLens dashboard.
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		add_submenu_page(
			DashboardPage::PAGE_SLUG,
			esc_html__( 'HealthLens settings', 'healthlens' ),
			esc_html__( 'Settings', 'healthlens' ),
			DashboardPage::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the documented setting through the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			self::SETTINGS_GROUP,
			Plugin::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'capability'        => DashboardPage::CAPABILITY,
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					self::RETENTION_FIELD             => false,
					self::CAPTURE_FIELD               => false,
					self::NOTIFICATIONS_FIELD         => false,
					self::NOTIFICATION_EMAIL_FIELD    => '',
					self::NOTIFICATION_RECOVERY_FIELD => true,
					self::GATEWAY_FIELD               => false,
					self::GATEWAY_ENDPOINT_FIELD      => '',
					self::GATEWAY_TOKEN_FIELD         => '',
				),
				'show_in_rest'      => false,
			)
		);

		if ( function_exists( 'add_settings_section' ) ) {
			add_settings_section(
				self::SECTION_ID,
				esc_html__( 'HealthLens data settings', 'healthlens' ),
				array( $this, 'render_section' ),
				self::PAGE_SLUG
			);
		}

		if ( function_exists( 'add_settings_field' ) ) {
			add_settings_field(
				self::RETENTION_FIELD,
				esc_html__( 'Retain data when uninstalled', 'healthlens' ),
				array( $this, 'render_retention_field' ),
				self::PAGE_SLUG,
				self::SECTION_ID
			);
			add_settings_field(
				self::CAPTURE_FIELD,
				esc_html__( 'Capture approved errors', 'healthlens' ),
				array( $this, 'render_capture_field' ),
				self::PAGE_SLUG,
				self::SECTION_ID
			);
			add_settings_field(
				self::NOTIFICATIONS_FIELD,
				esc_html__( 'Email notifications', 'healthlens' ),
				array( $this, 'render_notification_field' ),
				self::PAGE_SLUG,
				self::SECTION_ID
			);
			add_settings_field(
				self::GATEWAY_FIELD,
				esc_html__( 'Optional gateway', 'healthlens' ),
				array( $this, 'render_gateway_field' ),
				self::PAGE_SLUG,
				self::SECTION_ID
			);
		}
	}

	/**
	 * Sanitize only the documented retention preference.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$current          = get_option( Plugin::SETTINGS_OPTION, array() );
		$current          = is_array( $current ) ? $current : array();
		$value            = isset( $current[ self::RETENTION_FIELD ] ) ? (bool) $current[ self::RETENTION_FIELD ] : false;
		$capture          = isset( $current[ self::CAPTURE_FIELD ] ) ? (bool) $current[ self::CAPTURE_FIELD ] : false;
		$enabled          = isset( $current[ self::NOTIFICATIONS_FIELD ] ) ? (bool) $current[ self::NOTIFICATIONS_FIELD ] : false;
		$email            = isset( $current[ self::NOTIFICATION_EMAIL_FIELD ] ) && is_string( $current[ self::NOTIFICATION_EMAIL_FIELD ] ) ? $current[ self::NOTIFICATION_EMAIL_FIELD ] : '';
		$recovery         = isset( $current[ self::NOTIFICATION_RECOVERY_FIELD ] ) ? (bool) $current[ self::NOTIFICATION_RECOVERY_FIELD ] : true;
		$gateway_enabled  = isset( $current[ self::GATEWAY_FIELD ] ) ? (bool) $current[ self::GATEWAY_FIELD ] : false;
		$gateway_endpoint = isset( $current[ self::GATEWAY_ENDPOINT_FIELD ] ) && is_string( $current[ self::GATEWAY_ENDPOINT_FIELD ] ) ? $current[ self::GATEWAY_ENDPOINT_FIELD ] : '';
		$gateway_token    = isset( $current[ self::GATEWAY_TOKEN_FIELD ] ) && is_string( $current[ self::GATEWAY_TOKEN_FIELD ] ) ? $current[ self::GATEWAY_TOKEN_FIELD ] : '';

		if ( function_exists( 'current_user_can' ) && ! current_user_can( DashboardPage::CAPABILITY ) ) {
			$current[ self::RETENTION_FIELD ]             = $value;
			$current[ self::CAPTURE_FIELD ]               = $capture;
			$current[ self::NOTIFICATIONS_FIELD ]         = $enabled;
			$current[ self::NOTIFICATION_EMAIL_FIELD ]    = $email;
			$current[ self::NOTIFICATION_RECOVERY_FIELD ] = $recovery;
			$current[ self::GATEWAY_FIELD ]               = $gateway_enabled;
			$current[ self::GATEWAY_ENDPOINT_FIELD ]      = $gateway_endpoint;
			$current[ self::GATEWAY_TOKEN_FIELD ]         = $gateway_token;

			return $current;
		}

		if ( is_array( $input ) && array_key_exists( self::RETENTION_FIELD, $input ) ) {
			$submitted = $input[ self::RETENTION_FIELD ];
			if ( true === $submitted || 1 === $submitted || '1' === $submitted || 'true' === strtolower( (string) $submitted ) ) {
				$value = true;
			} elseif ( false === $submitted || 0 === $submitted || '0' === $submitted || 'false' === strtolower( (string) $submitted ) || '' === $submitted ) {
				$value = false;
			}
		}

		$current[ self::RETENTION_FIELD ] = $value;
		if ( is_array( $input ) && array_key_exists( self::CAPTURE_FIELD, $input ) ) {
			$submitted = $input[ self::CAPTURE_FIELD ];
			if ( true === $submitted || 1 === $submitted || '1' === $submitted || 'true' === strtolower( (string) $submitted ) ) {
				$capture = true;
			} elseif ( false === $submitted || 0 === $submitted || '0' === $submitted || 'false' === strtolower( (string) $submitted ) || '' === $submitted ) {
				$capture = false;
			}
		}
		$current[ self::CAPTURE_FIELD ] = $capture;
		if ( is_array( $input ) && array_key_exists( self::NOTIFICATIONS_FIELD, $input ) ) {
			$enabled = in_array( $input[ self::NOTIFICATIONS_FIELD ], array( true, 1, '1', 'true' ), true );
		}
		if ( is_array( $input ) && array_key_exists( self::NOTIFICATION_EMAIL_FIELD, $input ) ) {
			$submitted_email = is_string( $input[ self::NOTIFICATION_EMAIL_FIELD ] ) ? sanitize_email( $input[ self::NOTIFICATION_EMAIL_FIELD ] ) : '';
			$email           = is_email( $submitted_email ) ? $submitted_email : '';
		}
		if ( is_array( $input ) && array_key_exists( self::NOTIFICATION_RECOVERY_FIELD, $input ) ) {
			$recovery = in_array( $input[ self::NOTIFICATION_RECOVERY_FIELD ], array( true, 1, '1', 'true' ), true );
		}
		$current[ self::NOTIFICATIONS_FIELD ]         = $enabled && '' !== $email;
		$current[ self::NOTIFICATION_EMAIL_FIELD ]    = $email;
		$current[ self::NOTIFICATION_RECOVERY_FIELD ] = $recovery;
		if ( is_array( $input ) && array_key_exists( self::GATEWAY_ENDPOINT_FIELD, $input ) ) {
			$candidate_endpoint = is_string( $input[ self::GATEWAY_ENDPOINT_FIELD ] ) ? esc_url_raw( $input[ self::GATEWAY_ENDPOINT_FIELD ] ) : '';
			$gateway_endpoint   = OptionalGatewayTransport::approved_endpoint( $candidate_endpoint ) ? $candidate_endpoint : '';
		}
		if ( is_array( $input ) && array_key_exists( self::GATEWAY_TOKEN_FIELD, $input ) ) {
			$candidate_token = is_string( $input[ self::GATEWAY_TOKEN_FIELD ] ) ? $input[ self::GATEWAY_TOKEN_FIELD ] : '';
			$gateway_token   = substr( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $candidate_token ) : preg_replace( '/[^A-Za-z0-9._-]/', '', $candidate_token ), 0, 256 );
		}
		if ( is_array( $input ) && array_key_exists( self::GATEWAY_FIELD, $input ) ) {
			$gateway_enabled = in_array( $input[ self::GATEWAY_FIELD ], array( true, 1, '1', 'true' ), true );
		}
		$current[ self::GATEWAY_FIELD ]          = $gateway_enabled && '' !== $gateway_endpoint && '' !== $gateway_token;
		$current[ self::GATEWAY_ENDPOINT_FIELD ] = $gateway_endpoint;
		$current[ self::GATEWAY_TOKEN_FIELD ]    = $gateway_token;

		return $current;
	}

	/**
	 * Render the settings screen with a direct capability check.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( DashboardPage::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html__( 'You do not have permission to view HealthLens settings.', 'healthlens' ),
					esc_html__( 'HealthLens settings access denied', 'healthlens' ),
					array( 'response' => 403 )
				);
			}

			return;
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'HealthLens settings', 'healthlens' ) );
		if ( function_exists( 'settings_errors' ) ) {
			settings_errors();
		}
		echo '<form method="post" action="options.php">';
		if ( function_exists( 'settings_fields' ) ) {
			settings_fields( self::SETTINGS_GROUP );
		}
		if ( function_exists( 'do_settings_sections' ) ) {
			do_settings_sections( self::PAGE_SLUG );
		}
		if ( function_exists( 'submit_button' ) ) {
			submit_button();
		}
		echo '</form>';
		( new PluginFooter() )->render();
		echo '</div>';
	}

	/**
	 * Render explanatory section copy.
	 *
	 * @return void
	 */
	public function render_section() {
		printf( '<p>%s</p>', esc_html__( 'HealthLens stores bounded current results and optional error events locally. Error capture is disabled by default; it does not start checks or send data remotely, and never stores raw messages.', 'healthlens' ) );
	}

	/**
	 * Render the retention checkbox.
	 *
	 * @return void
	 */
	public function render_retention_field() {
		$settings = get_option( Plugin::SETTINGS_OPTION, array( self::RETENTION_FIELD => false ) );
		$checked  = is_array( $settings ) && ! empty( $settings[ self::RETENTION_FIELD ] );
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::RETENTION_FIELD ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Keep HealthLens data when the plugin is uninstalled.', 'healthlens' )
		);
	}

	/**
	 * Render the opt-in error-capture checkbox.
	 *
	 * @return void
	 */
	public function render_capture_field() {
		$settings = get_option( Plugin::SETTINGS_OPTION, array( self::CAPTURE_FIELD => false ) );
		$checked  = is_array( $settings ) && ! empty( $settings[ self::CAPTURE_FIELD ] );
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label><p class="description">%s</p>',
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::CAPTURE_FIELD ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Enable bounded local capture of approved HealthLens/PHP error events.', 'healthlens' ),
			esc_html__( 'Messages, paths, URLs, credentials, request data, and debug.log contents are never stored.', 'healthlens' )
		);
	}

	/**
	 * Render opt-in local email notification controls.
	 *
	 * @return void
	 */
	public function render_notification_field() {
		$settings = get_option( Plugin::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$checked  = ! empty( $settings[ self::NOTIFICATIONS_FIELD ] );
		$email    = isset( $settings[ self::NOTIFICATION_EMAIL_FIELD ] ) ? (string) $settings[ self::NOTIFICATION_EMAIL_FIELD ] : '';
		$recovery = ! isset( $settings[ self::NOTIFICATION_RECOVERY_FIELD ] ) || ! empty( $settings[ self::NOTIFICATION_RECOVERY_FIELD ] );
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label><br />',
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::NOTIFICATIONS_FIELD ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Enable one bounded local email per eligible incident state.', 'healthlens' )
		);
		printf(
			'<label for="healthlens-notification-email">%s</label> <input id="healthlens-notification-email" type="email" name="%s[%s]" value="%s" maxlength="254" />',
			esc_html__( 'Recipient', 'healthlens' ),
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::NOTIFICATION_EMAIL_FIELD ),
			esc_attr( $email )
		);
		printf(
			'<br /><label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label><p class="description">%s</p>',
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::NOTIFICATION_RECOVERY_FIELD ),
			$recovery ? ' checked="checked"' : '',
			esc_html__( 'Notify when an incident recovers.', 'healthlens' ),
			esc_html__( 'Disabled by default. Messages contain only allowlisted check identifiers and states; delivery is attempted by background work and is not proof of receipt.', 'healthlens' )
		);
	}

	/**
	 * Render the disabled-by-default gateway boundary.
	 *
	 * @return void
	 */
	public function render_gateway_field() {
		$settings = get_option( Plugin::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$checked  = ! empty( $settings[ self::GATEWAY_FIELD ] );
		$endpoint = isset( $settings[ self::GATEWAY_ENDPOINT_FIELD ] ) ? (string) $settings[ self::GATEWAY_ENDPOINT_FIELD ] : '';
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label><br />',
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::GATEWAY_FIELD ),
			$checked ? ' checked="checked"' : '',
			esc_html__( 'Enable only an approved, consented gateway configuration.', 'healthlens' )
		);
		printf(
			'<label for="healthlens-gateway-endpoint">%s</label> <input id="healthlens-gateway-endpoint" type="url" name="%s[%s]" value="%s" maxlength="255" /> <p class="description">%s</p>',
			esc_html__( 'Approved HTTPS endpoint', 'healthlens' ),
			esc_attr( Plugin::SETTINGS_OPTION ),
			esc_attr( self::GATEWAY_ENDPOINT_FIELD ),
			esc_attr( $endpoint ),
			esc_html__( 'The first running version has no configured production gateway; arbitrary hosts are rejected and no remote request is made by default.', 'healthlens' )
		);
	}
}
