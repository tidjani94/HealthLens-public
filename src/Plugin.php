<?php
/**
 * The plugin composition root.
 *
 * @package HealthLens
 */

namespace HealthLens;

use HealthLens\Application\CheckDispatcher;
use HealthLens\Application\NotificationDispatcher;
use HealthLens\Application\CheckRegistry;
use HealthLens\Application\CheckRunner;
use HealthLens\Application\ErrorCapture\ErrorEventCollector;
use HealthLens\Application\Dashboard\DashboardReadModel;
use HealthLens\Application\SystemClock;
use HealthLens\Application\WordPressCheckRegistry;
use HealthLens\Domain\ScoreCalculator;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ErrorEventRepository;
use HealthLens\Infrastructure\Database\NullErrorEventRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\Database\SchemaManager;
use HealthLens\Infrastructure\WordPress\CronScheduler;
use HealthLens\Infrastructure\WordPress\ErrorCaptureBootstrap;
use HealthLens\Infrastructure\WordPress\OptionLock;
use HealthLens\Infrastructure\WordPress\NotificationStateRepository;
use HealthLens\Presentation\Admin\DashboardPage;
use HealthLens\Presentation\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The HealthLens composition root.
 */
final class Plugin {
	/**
	 * Current lifecycle/schema scaffolding version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = SchemaManager::SCHEMA_VERSION;

	/**
	 * Plugin-owned option names.
	 */
	const SETTINGS_OPTION = 'healthlens_settings';
	const SCHEMA_OPTION   = 'healthlens_schema_version';
	const LOCK_OPTION     = 'healthlens_lock';
	const VERSION_OPTION  = 'healthlens_plugin_version';
	const CAPTURE_FIELD   = 'capture_errors';

	/**
	 * Prevents duplicate bootstrapping in unusual loader scenarios.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Cron dispatcher, created only for the WordPress runtime.
	 *
	 * @var CheckDispatcher|null
	 */
	private $dispatcher;

	/**
	 * Boot the plugin without registering feature modules yet.
	 *
	 * Scheduling is lifecycle scaffolding; checks execute only when WordPress
	 * invokes the dedicated cron hook, never during ordinary page requests.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$scheduler = new CronScheduler();
		$scheduler->register();
		$scheduler->schedule();
		self::maybe_upgrade();
		( new ErrorCaptureBootstrap( $this->create_error_collector() ) )->register();

		if ( function_exists( 'add_action' ) ) {
			add_action( CronScheduler::HOOK, array( $this, 'dispatch' ) );
			add_action( CronScheduler::MANUAL_HOOK, array( $this, 'dispatch' ) );
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			$this->create_dashboard_page()->register();
			( new SettingsPage() )->register();
		}
	}

	/**
	 * Build the admin page with read-only cached-state dependencies.
	 *
	 * @return DashboardPage
	 */
	private function create_dashboard_page() {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return new DashboardPage();
		}

		try {
			$schema    = new SchemaManager( $GLOBALS['wpdb'] );
			$results   = new ResultRepository( $GLOBALS['wpdb'], $schema );
			$incidents = new IncidentRepository( $GLOBALS['wpdb'], $schema );

			return new DashboardPage(
				new DashboardReadModel(
					WordPressCheckRegistry::create(),
					$results,
					$incidents,
					new ScoreCalculator(),
					new SystemClock(),
					array(),
					DashboardReadModel::MAX_ITEMS,
					new NotificationStateRepository()
				)
			);
		} catch ( \Throwable $throwable ) {
			return new DashboardPage();
		}
	}

	/**
	 * Create the site-local lifecycle state and apply the current schema.
	 *
	 * @param bool $network_wide Whether the plugin was network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html__( 'HealthLens must be activated per site. Network activation is not supported.', 'healthlens' ),
					esc_html__( 'HealthLens activation blocked', 'healthlens' ),
					array( 'response' => 400 )
				);
			}

			return;
		}

		if ( ! function_exists( 'add_option' ) ) {
			return;
		}

		add_option(
			self::SETTINGS_OPTION,
			array(
				'retain_data_on_uninstall' => false,
				self::CAPTURE_FIELD        => false,
				'notifications_enabled'    => false,
				'notification_email'       => '',
				'notification_recovery'    => true,
				'gateway_enabled'          => false,
				'gateway_endpoint'         => '',
				'gateway_token'            => '',
			),
			'',
			false
		);
		add_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, '', false );

		$scheduler = new CronScheduler();
		$scheduler->register();
		self::upgrade();
		$scheduler->schedule();
	}

	/**
	 * Apply pending schema and plugin-version upgrades.
	 *
	 * WordPress does not run the activation hook when an active plugin is
	 * updated in place. The active bootstrap therefore checks the stored
	 * version and runs the idempotent lifecycle upgrade on the next request.
	 *
	 * @return void
	 */
	public static function upgrade() {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			( new SchemaManager( $GLOBALS['wpdb'] ) )->upgrade();
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::VERSION_OPTION, self::current_version(), false );
		}
	}

	/**
	 * Preserve site data while removing scheduled HealthLens work.
	 *
	 * @return void
	 */
	public static function deactivate() {
		( new CronScheduler() )->unschedule();

		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Execute the cron dispatcher when WordPress invokes the HealthLens hook.
	 *
	 * @return void
	 */
	public function dispatch() {
		if ( ! $this->dispatcher ) {
			$this->dispatcher = $this->create_dispatcher();
		}

		$results = $this->dispatcher->dispatch();
		( new NotificationDispatcher( new NotificationStateRepository() ) )->dispatch( $results );
	}

	/**
	 * Build the runtime dispatcher and optional persistence adapters.
	 *
	 * @return CheckDispatcher
	 */
	private function create_dispatcher() {
		$registry  = WordPressCheckRegistry::create();
		$collector = $this->create_error_collector();
		$runner    = new CheckRunner( $registry, $collector );
		$results   = null;
		$incidents = null;

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$schema    = new SchemaManager( $GLOBALS['wpdb'] );
			$results   = new ResultRepository( $GLOBALS['wpdb'], $schema );
			$incidents = new IncidentRepository( $GLOBALS['wpdb'], $schema );
		}

		return new CheckDispatcher(
			$registry,
			$runner,
			new OptionLock( self::LOCK_OPTION ),
			new SystemClock(),
			$results,
			$incidents,
			null,
			$collector
		);
	}

	/**
	 * Build the opt-in error collector with a safe no-database fallback.
	 *
	 * @return ErrorEventCollector
	 */
	private function create_error_collector() {
		$repository = new NullErrorEventRepository();
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			try {
				$schema     = new SchemaManager( $GLOBALS['wpdb'] );
				$repository = new ErrorEventRepository( $GLOBALS['wpdb'], $schema );
			} catch ( \Throwable $throwable ) {
				$repository = new NullErrorEventRepository();
			}
		}

		$settings = function_exists( 'get_option' ) ? get_option( self::SETTINGS_OPTION, array() ) : array();
		$enabled  = is_array( $settings ) && ! empty( $settings[ self::CAPTURE_FIELD ] );

		return new ErrorEventCollector( $repository, $enabled );
	}

	/**
	 * Upgrade an active installation when its files changed without activation.
	 *
	 * @return void
	 */
	private static function maybe_upgrade() {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$stored_version = get_option( self::VERSION_OPTION, '' );
		$stored_schema  = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( self::current_version() === $stored_version && self::SCHEMA_VERSION <= $stored_schema ) {
			return;
		}

		try {
			self::upgrade();
		} catch ( \Throwable $throwable ) {
			// Keep the previous version so a later request retries the upgrade.
			return;
		}
	}

	/**
	 * Return the version declared by the plugin bootstrap.
	 *
	 * @return string
	 */
	public static function version() {
		return defined( 'HEALTHLENS_VERSION' ) ? HEALTHLENS_VERSION : '0.0.0';
	}

	/**
	 * Return the version declared by the plugin bootstrap.
	 *
	 * @return string
	 */
	private static function current_version() {
		return self::version();
	}
}
