<?php
/**
 * Shared HealthLens admin metadata footer.
 *
 * @package HealthLens
 */

namespace HealthLens\Presentation\Admin;

use HealthLens\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the current release and team attribution.
 */
final class PluginFooter {
	/** Team website shown in the admin attribution. */
	const TEAM_URL = 'https://coodiv.net';

	/**
	 * Render the footer metadata.
	 *
	 * @return void
	 */
	public function render() {
		$version = Plugin::version();
		$team    = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::TEAM_URL ),
			esc_html__( 'COODIV Team', 'healthlens' )
		);
		// translators: %s is the current HealthLens plugin version.
		$version_label = sprintf( esc_html__( 'Version %s', 'healthlens' ), $version );
		// translators: %s is the linked COODIV Team attribution.
		$attribution = sprintf( esc_html__( 'Crafted with ❤️ by %s', 'healthlens' ), $team );

		printf(
			'<footer class="healthlens-plugin-footer" aria-label="%s"><span>%s</span> <span aria-hidden="true">&middot;</span> <span>%s</span></footer>',
			esc_attr( esc_html__( 'HealthLens plugin information', 'healthlens' ) ),
			esc_html( $version_label ),
			wp_kses(
				$attribution,
				array(
					'a' => array(
						'href'   => true,
						'rel'    => true,
						'target' => true,
					),
				)
			)
		);
	}
}
