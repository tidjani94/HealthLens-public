<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Presentation\Admin\RemediationCatalog;
use PHPUnit\Framework\TestCase;

final class RemediationCatalogTest extends TestCase {
	public function test_known_checks_have_fixed_native_admin_destinations(): void {
		$catalog = new RemediationCatalog();

		$this->assertSame(
			array(
				'path'  => 'update-core.php',
				'label' => 'Review WordPress updates',
			),
			$catalog->for_check( 'wordpress-version' )
		);
		$this->assertSame(
			array(
				'path'  => 'site-health.php',
				'label' => 'Open Site Health',
			),
			$catalog->for_check( 'rest-api-availability' )
		);
	}

	/**
	 * Checks without a trustworthy, specific next step must not get a misleading link.
	 */
	public function test_unknown_and_non_actionable_checks_have_no_destination(): void {
		$catalog = new RemediationCatalog();

		$this->assertNull( $catalog->for_check( 'not-a-real-check' ) );
		$this->assertNull( $catalog->for_check( 'database-storage-growth' ) );
	}
}
