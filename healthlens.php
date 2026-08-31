<?php
/**
 * Plugin Name:       HealthLens
 * Plugin URI:         https://github.com/tidjani94/HealthLens-public
 * Description:       Explains important WordPress operational problems in plain language.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            COODIV Team
 * Author URI:        https://coodiv.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       healthlens
 *
 * @package HealthLens
 */

defined( 'ABSPATH' ) || exit;

define( 'HEALTHLENS_VERSION', '0.1.0' );
define( 'HEALTHLENS_PLUGIN_FILE', __FILE__ );
define( 'HEALTHLENS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$healthlens_autoloader = HEALTHLENS_PLUGIN_DIR . 'vendor/autoload.php';

if ( is_readable( $healthlens_autoloader ) ) {
	require_once $healthlens_autoloader;
}

if ( class_exists( '\HealthLens\Plugin' ) ) {
	$healthlens_plugin = new \HealthLens\Plugin();
	$healthlens_plugin->boot();

	register_activation_hook(
		__FILE__,
		array( '\HealthLens\Plugin', 'activate' )
	);
	register_deactivation_hook(
		__FILE__,
		array( '\HealthLens\Plugin', 'deactivate' )
	);
}
