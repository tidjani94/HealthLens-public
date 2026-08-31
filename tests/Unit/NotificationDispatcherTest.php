<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Application\NotificationDispatcher;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\NotificationStateRepository;
use HealthLens\Plugin;
use PHPUnit\Framework\TestCase;

final class NotificationDispatcherTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['healthlens_test_options'] = array(
			Plugin::SETTINGS_OPTION => array(
				'notifications_enabled' => false,
			),
		);
		$GLOBALS['healthlens_test_mails'] = array();
	}

	public function test_notifications_are_disabled_by_default(): void {
		$result = $this->warning_result();
		$sent   = ( new NotificationDispatcher( new NotificationStateRepository() ) )->dispatch( array( 'warning' => $result ) );

		$this->assertSame( 0, $sent );
		$this->assertSame( array(), $GLOBALS['healthlens_test_mails'] );
	}

	public function test_opt_in_mail_is_bounded_and_deduplicated(): void {
		$GLOBALS['healthlens_test_options'][ Plugin::SETTINGS_OPTION ] = array(
			'notifications_enabled' => true,
			'notification_email'    => 'owner@example.org',
			'notification_recovery' => true,
		);
		$dispatcher = new NotificationDispatcher( new NotificationStateRepository() );
		$result     = $this->warning_result();

		$this->assertSame( 1, $dispatcher->dispatch( array( 'warning' => $result ) ) );
		$this->assertSame( 0, $dispatcher->dispatch( array( 'warning' => $result ) ) );
		$this->assertCount( 1, $GLOBALS['healthlens_test_mails'] );
		$this->assertStringNotContainsString( 'raw-message', $GLOBALS['healthlens_test_mails'][0]['message'] );
		$this->assertSame( 1, ( new NotificationStateRepository() )->summary()['sent_count'] );
	}

	private function warning_result(): CheckResult {
		return new CheckResult(
			CheckResult::STATE_ISSUE,
			CheckResult::SEVERITY_WARNING,
			'notification.warning',
			new CheckContext( array( 'message' => 'raw-message', 'safe_flag' => true ) ),
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			1
		);
	}
}
