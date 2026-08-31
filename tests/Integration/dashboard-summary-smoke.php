<?php
/**
 * Verify the dashboard read model against seeded WordPress persistence.
 *
 * @package HealthLens
 */

use HealthLens\Application\CheckRegistry;
use HealthLens\Application\ClockInterface;
use HealthLens\Application\Dashboard\DashboardReadModel;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Domain\ScoreCalculator;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\Database\SchemaManager;
use HealthLens\Presentation\Admin\DashboardPage;

final class HealthLensDashboardSmokeCheck implements HealthCheckInterface {
	/** @var CheckDefinition */
	private $definition;

	public function __construct( CheckDefinition $definition ) {
		$this->definition = $definition;
	}

	public function definition(): CheckDefinition {
		return $this->definition;
	}

	public function run( CheckContext $context ): CheckResult {
		throw new RuntimeException( 'Dashboard summary smoke must not execute checks.' );
	}
}

final class HealthLensDashboardSmokeClock implements ClockInterface {
	/** @var DateTimeImmutable */
	private $now;

	public function __construct( DateTimeImmutable $now ) {
		$this->now = $now;
	}

	public function now() {
		return $this->now;
	}

	public function microtime() {
		return 0.0;
	}
}

$now         = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$definitions = array(
	new CheckDefinition( 'smoke-healthy', 'wordpress', 1, 900 ),
	new CheckDefinition( 'smoke-warning', 'wordpress', 1, 900 ),
);
$registry    = new CheckRegistry();
$schema      = new SchemaManager( $GLOBALS['wpdb'] );
$results     = new ResultRepository( $GLOBALS['wpdb'], $schema );
$incidents   = new IncidentRepository( $GLOBALS['wpdb'], $schema );

foreach ( $definitions as $definition ) {
	$registry->register( new HealthLensDashboardSmokeCheck( $definition ) );
}

$results->save(
	$definitions[0],
	new CheckResult( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY, 'smoke-healthy', new CheckContext(), $now, 1 )
);
$warning = new CheckResult( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'smoke-warning', new CheckContext( array( 'safe_flag' => true ) ), $now, 1 );
$results->save( $definitions[1], $warning );
$incidents->record( $definitions[1], $warning );

$view = ( new DashboardReadModel( $registry, $results, $incidents, new ScoreCalculator(), new HealthLensDashboardSmokeClock( $now ) ) )->compose();

if ( 80 !== $view['score']['value'] || 2 !== $view['coverage']['completed'] || 1 !== $view['incidents']['open_count'] ) {
	WP_CLI::error( 'Seeded dashboard summary did not match the cached score, coverage, or incident state.' );
}

wp_set_current_user( 1 );
$render_started   = microtime( true );
$queries_before   = (int) $GLOBALS['wpdb']->num_queries;
ob_start();
( new DashboardPage( new DashboardReadModel( $registry, $results, $incidents, new ScoreCalculator(), new HealthLensDashboardSmokeClock( $now ) ) ) )->render();
$html             = ob_get_clean();
$render_time_ms   = (int) round( ( microtime( true ) - $render_started ) * 1000 );
$render_query_cnt = (int) $GLOBALS['wpdb']->num_queries - $queries_before;
$friendly_time    = sprintf( '%1$s at %2$s', wp_date( get_option( 'date_format' ), $now->getTimestamp() ), wp_date( get_option( 'time_format' ), $now->getTimestamp() ) );

if (
	false === strpos( $html, '<main id="healthlens-dashboard"' ) ||
	1 !== substr_count( $html, '<main ' ) ||
	1 !== substr_count( $html, '<h1 ' ) ||
	false === strpos( $html, 'aria-labelledby="healthlens-dashboard-title"' ) ||
	false === strpos( $html, 'data-healthlens-tabs' ) ||
	3 !== substr_count( $html, 'data-healthlens-tab=' ) ||
	3 !== substr_count( $html, 'data-healthlens-panel=' ) ||
	false === strpos( $html, 'healthlens-dashboard__score-lens' ) ||
	false === strpos( $html, 'role="img" aria-label="Status distribution:' ) ||
	false === strpos( $html, '>' . $friendly_time . '</time>' ) ||
	false !== strpos( $html, 'role="tablist"' ) ||
	false === strpos( $html, 'aria-hidden="true"' ) ||
	false === strpos( $html, '<strong>Warning</strong>' ) ||
	false === strpos( $html, 'Show technical details' ) ||
	false !== strpos( $html, '<script' )
) {
	WP_CLI::error( 'Dashboard HTML smoke did not produce readable primary content without JavaScript.' );
}

if ( $render_query_cnt > 10 || $render_time_ms > 1000 ) {
	WP_CLI::error( sprintf( 'Dashboard render exceeded the fixed bound: %d queries, %d ms.', $render_query_cnt, $render_time_ms ) );
}

$GLOBALS['wpdb']->query( 'DELETE FROM ' . $schema->incidents_table() . " WHERE check_id IN ('smoke-healthy', 'smoke-warning')" );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . $schema->results_table() . " WHERE check_id IN ('smoke-healthy', 'smoke-warning')" );

WP_CLI::success( sprintf( 'HealthLens dashboard summary composed seeded cached rows without executing checks (%d queries, %d ms; bounds <=10 queries and <=1000 ms).', $render_query_cnt, $render_time_ms ) );
