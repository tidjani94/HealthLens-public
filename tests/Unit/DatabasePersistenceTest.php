<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\IncidentTransition;
use HealthLens\Domain\ErrorEvent;
use HealthLens\Application\ErrorCapture\ErrorEventNormalizer;
use HealthLens\Infrastructure\Database\ErrorEventRepository;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\Database\SchemaManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabasePersistenceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['healthlens_test_dbdelta'] = array();
	}

	public function test_schema_upgrade_is_idempotent_and_uses_site_prefix(): void {
		$wpdb  = new FakeWpdb();
		$schema = new SchemaManager( $wpdb );

		$schema->upgrade();
		$schema->upgrade();

		$this->assertSame( 6, count( $GLOBALS['healthlens_test_dbdelta'] ) );
		$this->assertStringContainsString( 'wp_test_healthlens_results', $GLOBALS['healthlens_test_dbdelta'][0] );
		$this->assertStringContainsString( 'wp_test_healthlens_incidents', $GLOBALS['healthlens_test_dbdelta'][1] );
		$this->assertStringContainsString( 'wp_test_healthlens_errors', $GLOBALS['healthlens_test_dbdelta'][2] );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, $GLOBALS['healthlens_test_options']['healthlens_schema_version'] );
	}

	public function test_schema_rejects_an_unsafe_prefix(): void {
		$this->expectException( InvalidArgumentException::class );

		( new SchemaManager( new FakeWpdb( 'wp-test-' ) ) )->results_table();
	}

	public function test_current_result_upsert_uses_prepared_values_and_round_trips(): void {
		$wpdb       = new FakeWpdb();
		$schema     = new SchemaManager( $wpdb );
		$repository = new ResultRepository( $wpdb, $schema );
		$definition = new CheckDefinition( 'rest-api', 'wordpress', 3, 900 );
		$result     = $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'rest-slow' );

		$this->assertTrue( $repository->save( $definition, $result ) );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->queries[0] );
		$this->assertStringContainsString( "'rest-api'", $wpdb->queries[0] );

		$wpdb->next_row = array(
			'state'        => CheckResult::STATE_ISSUE,
			'severity'     => CheckResult::SEVERITY_WARNING,
			'message_code' => 'rest-slow',
			'context_json' => '{"latency_ms":120}',
			'checked_at'   => '2026-08-18 12:00:00',
			'duration_ms'  => '25',
		);
		$loaded = $repository->get( 'rest-api' );

		$this->assertInstanceOf( CheckResult::class, $loaded );
		$this->assertSame( CheckResult::STATE_ISSUE, $loaded->state() );
		$this->assertSame( array( 'latency_ms' => 120 ), $loaded->context()->to_array() );
		$this->assertSame( 'UTC', $loaded->checked_at()->getTimezone()->getName() );
		$this->assertSame( 25, $loaded->duration_milliseconds() );
	}

	public function test_incident_repository_opens_updates_and_resolves_one_period(): void {
		$wpdb       = new FakeWpdb();
		$schema     = new SchemaManager( $wpdb );
		$repository = new IncidentRepository( $wpdb, $schema );
		$definition = new CheckDefinition( 'cron', 'wordpress', 2, 900 );

		$wpdb->next_row = null;
		$this->assertTrue( $repository->record( $definition, $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'cron-late' ) ) );
		$this->assertStringContainsString( 'INSERT INTO `wp_test_healthlens_incidents`', $wpdb->queries[1] );

		$wpdb->next_row = array( 'id' => 7 );
		$this->assertTrue( $repository->record( $definition, $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'check.execution-failed' ) ) );
		$this->assertStringContainsString( 'UPDATE `wp_test_healthlens_incidents`', $wpdb->queries[3] );

		$this->assertTrue( $repository->record( $definition, $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY, 'cron-healthy' ) ) );
		$this->assertStringContainsString( 'resolved_at', $wpdb->queries[5] );
	}

	public function test_incident_transition_does_not_resolve_on_unknown(): void {
		$this->assertSame(
			IncidentTransition::UPDATE,
			IncidentTransition::decide( true, $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'check.execution-failed' ) )
		);
		$this->assertSame(
			IncidentTransition::NONE,
			IncidentTransition::decide( false, $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY, 'check-healthy' ) )
		);
	}

	public function test_cleanup_limits_are_bounded(): void {
		$wpdb       = new FakeWpdb();
		$schema     = new SchemaManager( $wpdb );
		$results    = new ResultRepository( $wpdb, $schema );
		$incidents  = new IncidentRepository( $wpdb, $schema );
		$cutoff     = new DateTimeImmutable( '2026-08-11 00:00:00', new DateTimeZone( 'UTC' ) );

		$results->delete_orphans( $cutoff, 5000 );
		$incidents->delete_resolved( $cutoff, 5000 );

		$this->assertStringContainsString( 'LIMIT 1000', $wpdb->queries[0] );
		$this->assertStringContainsString( 'LIMIT 1000', $wpdb->queries[1] );
	}

	public function test_error_event_repository_uses_prepared_site_local_bounded_operations(): void {
		$wpdb       = new FakeWpdb();
		$schema     = new SchemaManager( $wpdb );
		$repository = new ErrorEventRepository( $wpdb, $schema );
		$event      = ErrorEventNormalizer::event( 'php-error', 'php.warning', 'warning', 'php', 'runtime', array( 'error_level' => E_WARNING ) );

		$wpdb->next_var = null;
		$this->assertTrue( $repository->save( $event ) );
		$this->assertStringContainsString( 'INSERT INTO `wp_test_healthlens_errors`', $wpdb->queries[1] );
		$this->assertStringContainsString( 'LIMIT 100', $wpdb->queries[2] );
		$this->assertStringContainsString( 'OFFSET 500', $wpdb->queries[3] );
	}

	/**
	 * @param string $state Result state.
	 * @param string $severity Result severity.
	 * @param string $message_code Message code.
	 * @return CheckResult
	 */
	private function result( $state, $severity, $message_code ) {
		return new CheckResult(
			$state,
			$severity,
			$message_code,
			new CheckContext( array( 'safe_flag' => true ) ),
			new DateTimeImmutable( '2026-08-18 12:00:00+02:00' ),
			25
		);
	}
}

/**
 * Minimal wpdb test double for prepared-query repository tests.
 */
final class FakeWpdb {
	/**
	 * @var string
	 */
	public $prefix;

	/**
	 * @var array<int, string>
	 */
	public $queries = array();

	/**
	 * @var array<string, mixed>|null
	 */
	public $next_row;

	/** @var mixed */
	public $next_var;

	/**
	 * @param string $prefix Site table prefix.
	 */
	public function __construct( $prefix = 'wp_test_' ) {
		$this->prefix = $prefix;
	}

	/**
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			if ( false !== strpos( $query, '%i' ) ) {
				$replacement = '`' . addslashes( (string) $arg ) . '`';
			}

			$query = preg_replace( '/%[sdi]/', $replacement, $query, 1 );
		}

		return $query;
	}

	/**
	 * @param string $query SQL query.
	 * @return int
	 */
	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}

	/**
	 * @param string $query SQL query.
	 * @param string $output Output mode.
	 * @return array<string, mixed>|null
	 */
	public function get_row( $query, $output ) {
		$this->queries[] = $query;
		return $this->next_row;
	}

	/** @param string $query SQL query. @return mixed */
	public function get_var( $query ) {
		$this->queries[] = $query;
		return $this->next_var;
	}

	/** @param string $query SQL query. @param string $output Output mode. @return array<int, array<string, mixed>> */
	public function get_results( $query, $output ) {
		$this->queries[] = $query;
		return array();
	}
}
