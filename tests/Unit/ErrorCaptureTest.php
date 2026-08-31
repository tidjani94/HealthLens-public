<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use Exception;
use HealthLens\Application\ErrorCapture\ErrorEventCollector;
use HealthLens\Application\ErrorCapture\ErrorEventCollectorInterface;
use HealthLens\Application\ErrorCapture\ErrorEventNormalizer;
use HealthLens\Domain\ErrorEvent;
use HealthLens\Infrastructure\WordPress\ErrorCaptureBootstrap;
use PHPUnit\Framework\TestCase;

final class ErrorCaptureTest extends TestCase {
	public function test_normalizer_allowlists_context_and_drops_sensitive_values(): void {
		$event = ErrorEventNormalizer::event(
			'php-error',
			'healthlens.invalid-input',
			'warning',
			'healthlens',
			'runtime',
			array(
				'error_level' => E_WARNING,
				'line_bucket' => 20,
				'operation'   => str_repeat( 'x', 200 ),
				'password'    => 'must-not-reach-storage',
				'message'     => 'must-not-reach-storage',
			)
		);

		$this->assertSame( E_WARNING, $event->context()->to_array()['error_level'] );
		$this->assertSame( 128, strlen( $event->context()->to_array()['operation'] ) );
		$this->assertArrayNotHasKey( 'password', $event->context()->to_array() );
		$this->assertArrayNotHasKey( 'message', $event->context()->to_array() );
		$this->assertStringNotContainsString( 'must-not-reach-storage', $event->context()->to_json() );
	}

	public function test_supported_throwable_and_wp_error_are_normalized_without_messages(): void {
		$throwable = ErrorEventNormalizer::throwable( new Exception( 'secret message /tmp/private' ), 'healthlens', 'check', 'healthlens.check.failed' );
		$wp_error  = ErrorEventNormalizer::wp_error( new \WP_Error( 'remote-secret' ), 'wordpress', 'hook', 'wordpress.error' );

		$this->assertSame( 'throwable', $throwable->context()->to_array()['error_type'] );
		$this->assertSame( 'remote-secret', $wp_error->code() );
		$this->assertStringNotContainsString( 'secret message', $throwable->context()->to_json() );
	}

	public function test_disabled_capture_and_storage_failure_are_noops(): void {
		$repository = new RecordingErrorRepository();
		$disabled   = new ErrorEventCollector( $repository, false );
		$failing    = new ErrorEventCollector( new RecordingErrorRepository( true ), true );

		$this->assertFalse( $disabled->capture( 'php-error', 'php.warning', 'warning', 'php', 'runtime' ) );
		$this->assertFalse( $failing->capture_throwable( new Exception( 'ignored' ), 'healthlens', 'check', 'healthlens.check.failed' ) );
		$this->assertCount( 0, $repository->events );
	}

	public function test_request_rate_limit_is_fixed_at_ten_attempts(): void {
		$repository = new RecordingErrorRepository();
		$collector  = new ErrorEventCollector( $repository, true );

		for ( $index = 0; $index < 11; $index++ ) {
			$collector->capture( 'php-error', 'php.warning-' . $index, 'warning', 'php', 'runtime' );
		}

		$this->assertCount( ErrorEventCollector::MAX_EVENTS_PER_REQUEST, $repository->events );
	}

	public function test_handler_preserves_previous_handler_and_excludes_unsupported_levels(): void {
		$repository = new RecordingErrorRepository();
		$collector  = new ErrorEventCollector( $repository, true );
		$previous   = 0;
		set_error_handler(
			function () use ( &$previous ) {
				++$previous;
				return true;
			}
		);
		$bootstrap = new ErrorCaptureBootstrap( $collector );
		$this->assertTrue( $bootstrap->register() );
		$this->assertTrue( $bootstrap->handle( E_USER_WARNING, 'secret', '/tmp/private.php', 23 ) );
		$this->assertFalse( $bootstrap->handle( E_STRICT, 'ignored', '/tmp/private.php', 23 ) );
		$bootstrap->restore();

		$this->assertSame( 1, $previous );
		$this->assertCount( 1, $repository->events );
		$this->assertSame( 20, $repository->events[0]->context()->to_array()['line_bucket'] );
	}
}

/**
 * In-memory repository double for collector boundary tests.
 */
final class RecordingErrorRepository implements \HealthLens\Infrastructure\Database\ErrorEventRepositoryInterface {
	/** @var array<int, ErrorEvent> */
	public $events = array();
	/** @var bool */
	private $fail;

	/** @param bool $fail Whether persistence should throw. */
	public function __construct( $fail = false ) {
		$this->fail = $fail;
	}

	/** @param ErrorEvent $event Event. @return bool */
	public function save( ErrorEvent $event ) {
		if ( $this->fail ) {
			throw new \RuntimeException( 'storage failure' );
		}

		$this->events[] = $event;
		return true;
	}
}
