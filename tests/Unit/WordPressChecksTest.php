<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\BoundedHttpProbe;
use HealthLens\Infrastructure\WordPress\Checks\AdministratorEmailCheck;
use HealthLens\Infrastructure\WordPress\Checks\CronScheduleCheck;
use HealthLens\Infrastructure\WordPress\Checks\LoopbackRequestsCheck;
use HealthLens\Infrastructure\WordPress\Checks\RestApiAvailabilityCheck;
use HealthLens\Infrastructure\WordPress\Checks\WordPressVersionCheck;
use PHPUnit\Framework\TestCase;

final class WordPressChecksTest extends TestCase {
	public function test_http_probe_applies_bounds_and_normalizes_success_without_persisting_body(): void {
		$args_seen = array();
	$probe = new BoundedHttpProbe(
		function ( $url, $args ) use ( &$args_seen ) {
			$args_seen = $args;
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
				'body'     => '{"ok":true}',
			);
		},
		'example.test'
	);

		$result = $probe->probe( 'https://example.test/wp-json/' );

		$this->assertTrue( $result->transport_ok() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame( '{"ok":true}', $result->body() );
		$this->assertSame( 5, $args_seen['timeout'] );
		$this->assertSame( 1, $args_seen['redirection'] );
		$this->assertSame( BoundedHttpProbe::MAX_RESPONSE_BYTES, $args_seen['limit_response_size'] );
		$this->assertTrue( $args_seen['reject_unsafe_urls'] );
	}

	public function test_http_probe_rejects_credentials_and_unallowlisted_hosts(): void {
		$probe = new BoundedHttpProbe( static function () { return array(); }, 'example.test' );

		$this->assertSame( 'unsafe-url', $probe->probe( 'https://user:pass@example.test/' )->error_code() );
		$this->assertSame( 'host-not-allowlisted', $probe->probe( 'https://other.test/' )->error_code() );
	}

	public function test_wordpress_version_check_distinguishes_current_and_available_updates(): void {
		$current = new WordPressVersionCheck( static function () { return '7.0.1'; }, static function () { return array(); } );
		$update  = new WordPressVersionCheck( static function () { return '7.0.0'; }, static function () { return array( array( 'response' => 'upgrade', 'version' => '7.0.1' ) ); } );

		$this->assertSame( CheckResult::STATE_HEALTHY, $current->run( new CheckContext() )->state() );
		$update_result = $update->run( new CheckContext() );
		$this->assertSame( CheckResult::STATE_ISSUE, $update_result->state() );
		$this->assertSame( 'wordpress.update-available', $update_result->message_code() );
		$this->assertSame( '7.0.1', $update_result->context()->to_array()['update_version'] );
	}

	public function test_rest_check_normalizes_transport_and_malformed_response_failures(): void {
		$transport = static function ( $url, $args ) {
			return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"ok":true}' );
		};
		$check = new RestApiAvailabilityCheck( new BoundedHttpProbe( $transport ), static function () { return 'https://example.test/wp-json/'; } );

		$this->assertSame( CheckResult::STATE_HEALTHY, $check->run( new CheckContext() )->state() );

		$malformed = new RestApiAvailabilityCheck(
			new BoundedHttpProbe( static function () { return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => 'not-json' ); } ),
			static function () { return 'https://example.test/wp-json/'; }
		);
		$this->assertSame( 'wordpress.rest-malformed-response', $malformed->run( new CheckContext() )->message_code() );
	}

	public function test_loopback_check_is_bounded_and_does_not_retain_a_url(): void {
		$check = new LoopbackRequestsCheck(
			new BoundedHttpProbe( static function () { return array( 'response' => array( 'code' => 204 ), 'body' => '' ); } ),
			static function () { return 'https://example.test/'; }
		);

		$result = $check->run( new CheckContext() );
		$this->assertSame( CheckResult::STATE_HEALTHY, $result->state() );
		$this->assertArrayNotHasKey( 'url', $result->context()->to_array() );
	}

	public function test_cron_check_handles_missing_duplicate_and_late_events_without_mutation(): void {
		$missing = new CronScheduleCheck( static function () { return array(); }, static function () { return 1000; } );
		$this->assertSame( 'wordpress.cron-missing', $missing->run( new CheckContext() )->message_code() );

		$duplicate = new CronScheduleCheck( static function () { return array( array( 'timestamp' => 1200 ), array( 'timestamp' => 1300 ) ); }, static function () { return 1000; } );
		$this->assertSame( 'wordpress.cron-duplicate', $duplicate->run( new CheckContext() )->message_code() );

		$late = new CronScheduleCheck( static function () { return array( array( 'timestamp' => 1, 'has_args' => false ) ); }, static function () { return 10000; } );
		$this->assertSame( 'wordpress.cron-overdue', $late->run( new CheckContext() )->message_code() );
	}

	public function test_administrator_email_check_only_exposes_boolean_state(): void {
		$valid = new AdministratorEmailCheck( static function () { return 'owner@example.test'; }, static function ( $email ) { return 'owner@example.test' === $email; } );
		$result = $valid->run( new CheckContext() );

		$this->assertSame( CheckResult::STATE_HEALTHY, $result->state() );
		$this->assertSame( array( 'configured' => true, 'state' => 'valid', 'valid' => true ), $result->context()->to_array() );
		$this->assertStringNotContainsString( 'owner@example.test', $result->context()->to_json() );
	}
}
