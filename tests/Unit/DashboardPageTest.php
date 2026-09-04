<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
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
use HealthLens\Infrastructure\WordPress\CronScheduler;
use HealthLens\Plugin;
use HealthLens\Presentation\Admin\DashboardPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DashboardPageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['healthlens_test_actions']          = array();
		$GLOBALS['healthlens_test_action_callbacks'] = array();
		$GLOBALS['healthlens_test_menu_pages']      = array();
		$GLOBALS['healthlens_test_capabilities']    = array();
		$GLOBALS['healthlens_test_is_admin']        = false;
		$GLOBALS['healthlens_test_styles']          = array();
		$GLOBALS['healthlens_test_scripts']         = array();
		$GLOBALS['healthlens_test_scheduled_events'] = array();
		$_GET = array();

		if ( ! defined( 'HEALTHLENS_PLUGIN_FILE' ) ) {
			define( 'HEALTHLENS_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/healthlens.php' );
		}
	}

	public function test_registers_one_translated_capability_protected_menu_page(): void {
		$page = new DashboardPage();
		$page->register();
		call_user_func( $GLOBALS['healthlens_test_action_callbacks']['admin_menu'] );

		$this->assertSame( array( 'admin_menu', 'admin_enqueue_scripts', 'admin_post_healthlens_request_run' ), $GLOBALS['healthlens_test_actions'] );
		$this->assertCount( 1, $GLOBALS['healthlens_test_menu_pages'] );
		$this->assertSame( 'HealthLens dashboard', $GLOBALS['healthlens_test_menu_pages'][0]['page_title'] );
		$this->assertSame( 'HealthLens', $GLOBALS['healthlens_test_menu_pages'][0]['menu_title'] );
		$this->assertSame( DashboardPage::CAPABILITY, $GLOBALS['healthlens_test_menu_pages'][0]['capability'] );
		$this->assertSame( DashboardPage::PAGE_SLUG, $GLOBALS['healthlens_test_menu_pages'][0]['menu_slug'] );
		$this->assertNull( $GLOBALS['healthlens_test_menu_pages'][0]['position'] );
	}

	public function test_assets_are_scoped_to_the_dashboard_screen(): void {
		$page = new DashboardPage();
		$page->enqueue_assets( 'dashboard_page_other' );
		$this->assertSame( array(), $GLOBALS['healthlens_test_styles'] );
		$this->assertSame( array(), $GLOBALS['healthlens_test_scripts'] );

		$page->enqueue_assets( DashboardPage::PAGE_HOOK );

		$this->assertCount( 1, $GLOBALS['healthlens_test_styles'] );
		$this->assertCount( 1, $GLOBALS['healthlens_test_scripts'] );
		$this->assertSame( 'healthlens-dashboard', $GLOBALS['healthlens_test_styles'][0]['handle'] );
		$this->assertSame( 'healthlens-dashboard', $GLOBALS['healthlens_test_scripts'][0]['handle'] );
	}

	public function test_render_requires_manage_options_and_outputs_server_rendered_states(): void {
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = true;
		$page = new DashboardPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<main id="healthlens-dashboard"', $output );
		$this->assertStringContainsString( 'HealthLens dashboard', $output );
		$this->assertStringContainsString( 'No health results are available yet.', $output );
		$this->assertStringContainsString( 'data-healthlens-state="loading"', $output );
		$this->assertStringContainsString( 'data-healthlens-state="stale"', $output );
		$this->assertStringContainsString( 'Version 0.1.0', $output );
		$this->assertStringContainsString( 'Crafted with ❤️ by', $output );
		$this->assertStringContainsString( 'href="https://coodiv.net"', $output );
		$this->assertStringContainsString( 'COODIV Team', $output );
		$this->assertStringNotContainsString( 'healthlens_run_checks', $output );
	}

	public function test_manual_run_control_is_nonce_protected_and_queues_a_background_event(): void {
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = true;
		$page = new DashboardPage();
		$page->register();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertContains( 'admin_post_healthlens_request_run', $GLOBALS['healthlens_test_actions'] );
		$this->assertStringContainsString( 'Run checks now', $output );
		$this->assertStringContainsString( 'name="action" value="healthlens_request_run"', $output );
		$this->assertStringContainsString( 'name="healthlens_manual_run"', $output );

		$scheduler = new CronScheduler();
		$this->assertTrue( $scheduler->request_manual_run() );
		$this->assertArrayHasKey( CronScheduler::MANUAL_HOOK, $GLOBALS['healthlens_test_scheduled_events'] );
		$this->assertFalse( $GLOBALS['healthlens_test_scheduled_events'][ CronScheduler::MANUAL_HOOK ]['recurrence'] );
		$this->assertTrue( $scheduler->request_manual_run() );
	}

	public function test_manual_run_requires_manage_options(): void {
		$this->expectException( RuntimeException::class );

		( new DashboardPage() )->handle_manual_run();
	}

	public function test_direct_access_is_denied_without_manage_options(): void {
		$page = new DashboardPage();

		$this->expectException( RuntimeException::class );
		$page->render();
	}

	public function test_render_prioritizes_severity_and_escapes_safe_technical_details(): void {
		$registry = new CheckRegistry();
		$critical = new CheckDefinition( 'rest-api-availability', 'wordpress', 1, 900 );
		$warning  = new CheckDefinition( 'warning', 'wordpress', 1, 900 );
		$unknown  = new CheckDefinition( 'unknown', 'wordpress', 1, 900 );
		$missing  = new CheckDefinition( 'missing', 'wordpress', 1, 900 );
		$registry->register( new DashboardPageTestCheck( $critical ) );
		$registry->register( new DashboardPageTestCheck( $warning ) );
		$registry->register( new DashboardPageTestCheck( $unknown ) );
		$registry->register( new DashboardPageTestCheck( $missing ) );
		$wpdb = new DashboardPageTestWpdb(
			array(
			$this->dashboard_row( 'rest-api-availability', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_CRITICAL, 'critical-result', '{"latency_ms":12,"endpoint":"https://private.example"}' ),
				$this->dashboard_row( 'warning', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'warning-result', '{"safe_flag":true}' ),
				$this->dashboard_row( 'unknown', CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'check.execution-failed', '{}' ),
			),
			array()
		);
		$now   = new DateTimeImmutable( '2026-08-19 12:00:00', new DateTimeZone( 'UTC' ) );
		$model = new DashboardReadModel(
			$registry,
			new ResultRepository( $wpdb, new SchemaManager( $wpdb ) ),
			new IncidentRepository( $wpdb, new SchemaManager( $wpdb ) ),
			new ScoreCalculator(),
			new DashboardPageTestClock( $now )
		);
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = true;

		ob_start();
		( new DashboardPage( $model ) )->render();
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '<main ' ) );
		$this->assertSame( 1, substr_count( $output, '<h1 ' ) );
		$this->assertStringContainsString( 'aria-labelledby="healthlens-dashboard-title"', $output );
		$this->assertStringContainsString( 'data-healthlens-tabs', $output );
		$this->assertSame( 3, substr_count( $output, 'data-healthlens-tab=' ) );
		$this->assertSame( 3, substr_count( $output, 'data-healthlens-panel=' ) );
		$this->assertStringContainsString( 'healthlens-dashboard__score-lens', $output );
		$this->assertStringContainsString( 'Overall health score: 30 out of 100', $output );
		$this->assertStringContainsString( 'role="img" aria-label="Status distribution:', $output );
		$this->assertStringContainsString( 'Status distribution: Critical: 1, Warning: 1, Unknown: 1, Not checked: 1', $output );
		$this->assertStringNotContainsString( 'role="tablist"', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		$this->assertStringContainsString( '<strong>Warning</strong>', $output );
		$this->assertStringContainsString( 'REST API availability', $output );
		$this->assertStringContainsString( 'healthlens-dashboard__status--warning', $output );
		$this->assertLessThan( strpos( $output, 'Warning' ), strpos( $output, 'Critical' ) );
		$this->assertStringContainsString( 'Recommended action', $output );
		$this->assertStringContainsString( 'href="https://example.org/wp-admin/site-health.php"', $output );
		$this->assertStringContainsString( 'Open Site Health', $output );
		$this->assertStringContainsString( 'Unknown', $output );
		$this->assertStringContainsString( 'Not checked', $output );
		$this->assertStringContainsString( 'Show technical details', $output );
		$this->assertStringContainsString( '<time datetime="2026-08-19T11:59:00+00:00">Aug 19, 2026 at 11:59 am UTC</time>', $output );
		$this->assertStringContainsString( 'latency_ms', $output );
		$this->assertStringNotContainsString( 'https://private.example', $output );
		$this->assertStringNotContainsString( 'context_json', $output );
	}

	public function test_dashboard_script_supports_the_accessible_tab_keyboard_pattern(): void {
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin-dashboard.js' );

		$this->assertIsString( $script );
		$this->assertStringContainsString( "setAttribute( 'role', 'tablist' )", $script );
		$this->assertStringContainsString( "'ArrowLeft'", $script );
		$this->assertStringContainsString( "'ArrowRight'", $script );
		$this->assertStringContainsString( "'Home'", $script );
		$this->assertStringContainsString( "'End'", $script );
		$this->assertStringContainsString( 'panel.hidden = panel.id !== panelId', $script );
	}

	public function test_plugin_registers_dashboard_hooks_only_for_admin_requests(): void {
		$plugin = new Plugin();
		$plugin->boot();

		$this->assertNotContains( 'admin_menu', $GLOBALS['healthlens_test_actions'] );

		$GLOBALS['healthlens_test_actions']          = array();
		$GLOBALS['healthlens_test_action_callbacks'] = array();
		$GLOBALS['healthlens_test_is_admin']         = true;
		$plugin = new Plugin();
		$plugin->boot();

		$this->assertContains( 'admin_menu', $GLOBALS['healthlens_test_actions'] );
		$this->assertContains( 'admin_enqueue_scripts', $GLOBALS['healthlens_test_actions'] );
		$this->assertContains( 'healthlens_run_checks', $GLOBALS['healthlens_test_actions'] );
	}

	/**
	 * @param string $check_id Check ID.
	 * @param string $state State.
	 * @param string $severity Severity.
	 * @param string $message_code Message code.
	 * @param string $context_json Context JSON.
	 * @return array<string, string>
	 */
	private function dashboard_row( $check_id, $state, $severity, $message_code, $context_json ) {
		return array(
			'check_id'     => $check_id,
			'state'        => $state,
			'severity'     => $severity,
			'message_code' => $message_code,
			'context_json' => $context_json,
			'checked_at'   => '2026-08-19 11:59:00',
			'duration_ms'  => '10',
		);
	}
}

final class DashboardPageTestCheck implements HealthCheckInterface {
	/** @var CheckDefinition */
	private $definition;

	public function __construct( CheckDefinition $definition ) {
		$this->definition = $definition;
	}

	public function definition(): CheckDefinition {
		return $this->definition;
	}

	public function run( CheckContext $context ): CheckResult {
		throw new \RuntimeException( 'Dashboard page rendering must not execute checks.' );
	}
}

final class DashboardPageTestClock implements ClockInterface {
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

final class DashboardPageTestWpdb {
	/** @var string */
	public $prefix = 'wp_test_';

	/** @var array<int, array<string, mixed>> */
	private $result_rows;

	/** @var array<int, array<string, mixed>> */
	private $incident_rows;

	public function __construct( array $result_rows, array $incident_rows ) {
		$this->result_rows   = $result_rows;
		$this->incident_rows = $incident_rows;
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sdi]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_results( $query, $output ) {
		return false !== strpos( $query, 'healthlens_incidents' ) ? $this->incident_rows : $this->result_rows;
	}
}
