<?php
/**
 * The HealthLens admin dashboard.
 *
 * @package HealthLens
 */

namespace HealthLens\Presentation\Admin;

use HealthLens\Application\Dashboard\DashboardReadModel;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\CronScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the server-rendered dashboard.
 */
final class DashboardPage {
	/** Stable admin page slug. */
	const PAGE_SLUG = 'healthlens';
	/** Stable top-level admin page hook suffix. */
	const PAGE_HOOK = 'toplevel_page_healthlens';
	/** Required capability for the dashboard. */
	const CAPABILITY = 'manage_options';
	/** Admin-post action for a user-requested background run. */
	const MANUAL_RUN_ACTION = 'healthlens_request_run';
	/** Nonce action for the manual run form. */
	const MANUAL_RUN_NONCE = 'healthlens_manual_run';
	/** Query-string key for the post-redirect notice. */
	const NOTICE_PARAM = 'healthlens_notice';

	/**
	 * Optional cached-state read model.
	 *
	 * @var DashboardReadModel|null
	 */
	private $read_model;

	/**
	 * Fixed, reviewed destinations for actionable checks.
	 *
	 * @var RemediationCatalog
	 */
	private $remediation_catalog;

	/**
	 * Create the dashboard page.
	 *
	 * @param DashboardReadModel|null $read_model Cached-state composer.
	 * @param RemediationCatalog|null $remediation_catalog Reviewed next-step destinations.
	 */
	public function __construct( $read_model = null, $remediation_catalog = null ) {
		$this->read_model          = $read_model instanceof DashboardReadModel ? $read_model : null;
		$this->remediation_catalog = $remediation_catalog instanceof RemediationCatalog ? $remediation_catalog : new RemediationCatalog();
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::MANUAL_RUN_ACTION, array( $this, 'handle_manual_run' ) );
	}

	/**
	 * Register one site-local top-level admin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( ! function_exists( 'add_menu_page' ) ) {
			return;
		}

		add_menu_page(
			esc_html__( 'HealthLens dashboard', 'healthlens' ),
			esc_html__( 'HealthLens', 'healthlens' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-heart'
		);
	}

	/**
	 * Enqueue dashboard assets only on the HealthLens screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( self::PAGE_HOOK !== $hook_suffix ) {
			return;
		}

		if ( ! defined( 'HEALTHLENS_PLUGIN_FILE' ) || ! function_exists( 'plugins_url' ) ) {
			return;
		}

		$version        = defined( 'HEALTHLENS_VERSION' ) ? HEALTHLENS_VERSION : '0.1.0';
		$style_path     = dirname( HEALTHLENS_PLUGIN_FILE ) . '/assets/css/admin-dashboard.css';
		$script_path    = dirname( HEALTHLENS_PLUGIN_FILE ) . '/assets/js/admin-dashboard.js';
		$style_version  = $this->asset_version( $version, $style_path );
		$script_version = $this->asset_version( $version, $script_path );

		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style(
				'healthlens-dashboard',
				plugins_url( 'assets/css/admin-dashboard.css', HEALTHLENS_PLUGIN_FILE ),
				array(),
				$style_version
			);
		}

		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script(
				'healthlens-dashboard',
				plugins_url( 'assets/js/admin-dashboard.js', HEALTHLENS_PLUGIN_FILE ),
				array(),
				$script_version,
				true
			);
		}
	}

	/**
	 * Build a cache-busting version from the asset contents.
	 *
	 * Release archives use deterministic file timestamps, so filemtime() cannot
	 * distinguish two asset revisions. A short content hash keeps browser caches
	 * correct without changing the plugin version for every dashboard tweak.
	 *
	 * @param string $version Plugin version.
	 * @param string $path Asset path.
	 * @return string
	 */
	private function asset_version( $version, $path ) {
		if ( ! is_readable( $path ) ) {
			return $version;
		}

		$hash = hash_file( 'sha256', $path );

		return false !== $hash ? $version . '.' . substr( $hash, 0, 12 ) : $version;
	}

	/**
	 * Render the dashboard and enforce its capability boundary.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html__( 'You do not have permission to view the HealthLens dashboard.', 'healthlens' ),
					esc_html__( 'HealthLens access denied', 'healthlens' ),
					array( 'response' => 403 )
				);
			}

			return;
		}

		echo '<div class="wrap healthlens-dashboard">';
		echo '<main id="healthlens-dashboard" aria-labelledby="healthlens-dashboard-title">';
		printf( '<h1 id="healthlens-dashboard-title">%s</h1>', esc_html__( 'HealthLens dashboard', 'healthlens' ) );
		$this->render_manual_run_controls();

		if ( ! $this->read_model ) {
			$this->render_empty_shell();
		} else {
			$this->render_summary( $this->read_model->compose() );
		}

		echo '</main>';
		( new PluginFooter() )->render();
		echo '</div>';
	}

	/**
	 * Queue a bounded background run from the authenticated dashboard form.
	 *
	 * @return void
	 */
	public function handle_manual_run() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::CAPABILITY ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html__( 'You do not have permission to run HealthLens checks.', 'healthlens' ),
					esc_html__( 'HealthLens access denied', 'healthlens' ),
					array( 'response' => 403 )
				);
			}

			return;
		}

		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::MANUAL_RUN_ACTION, self::MANUAL_RUN_NONCE );
		}

		$queued = ( new CronScheduler() )->request_manual_run();
		$notice = $queued ? 'queued' : 'failed';
		$url    = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : '';
		if ( function_exists( 'add_query_arg' ) ) {
			$url = add_query_arg( self::NOTICE_PARAM, $notice, $url );
		}

		if ( function_exists( 'wp_safe_redirect' ) && '' !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Render the dashboard's authenticated manual-run control and notice.
	 *
	 * @return void
	 */
	private function render_manual_run_controls() {
		$notice     = '';
		$raw_notice = function_exists( 'filter_input' ) ? filter_input( INPUT_GET, self::NOTICE_PARAM, FILTER_UNSAFE_RAW ) : false;
		if ( false !== $raw_notice && null !== $raw_notice ) {
			$value  = function_exists( 'wp_unslash' ) ? wp_unslash( $raw_notice ) : $raw_notice;
			$notice = function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : (string) $value;
		}

		if ( 'queued' === $notice ) {
			echo '<div class="notice notice-success healthlens-dashboard__notice" role="status">';
			printf( '<p>%s</p>', esc_html__( 'Health checks were queued to run in the background. Refresh this page after the run completes to see the latest results.', 'healthlens' ) );
			echo '</div>';
		} elseif ( 'failed' === $notice ) {
			echo '<div class="notice notice-error healthlens-dashboard__notice" role="alert">';
			printf( '<p>%s</p>', esc_html__( 'HealthLens could not queue a background check run. Confirm that WP-Cron is available, then try again.', 'healthlens' ) );
			echo '</div>';
		}

		echo '<section class="healthlens-dashboard__run-controls" aria-labelledby="healthlens-run-title">';
		printf( '<h2 id="healthlens-run-title">%s</h2>', esc_html__( 'Run a manual check', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'Queue the bounded HealthLens checks now. They run in the background, so this page stays responsive.', 'healthlens' ) );
		$action_url = function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : '';
		printf( '<form method="post" action="%s">', esc_url( $action_url ) );
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( self::MANUAL_RUN_ACTION, self::MANUAL_RUN_NONCE );
		}
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::MANUAL_RUN_ACTION ) );
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Run checks now', 'healthlens' ) );
		echo '</form></section>';
	}

	/**
	 * Render the initial no-data shell when persistence is unavailable.
	 *
	 * @return void
	 */
	private function render_empty_shell() {
		echo '<section aria-labelledby="healthlens-status-title">';
		printf( '<h2 id="healthlens-status-title">%s</h2>', esc_html__( 'Health status', 'healthlens' ) );
		echo '<div class="healthlens-dashboard__state healthlens-dashboard__state--empty" data-healthlens-state="empty" role="status" aria-live="polite">';
		printf( '<p><strong>%s</strong></p>', esc_html__( 'No health results are available yet.', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'HealthLens will show saved results after its background checks run. Use Run checks now to queue a bounded background run, or wait for the next scheduled run.', 'healthlens' ) );
		echo '</div>';
		echo '</section>';
		echo '<section class="healthlens-dashboard__state healthlens-dashboard__state--loading" data-healthlens-state="loading" aria-labelledby="healthlens-loading-title" hidden>';
		printf( '<h2 id="healthlens-loading-title">%s</h2>', esc_html__( 'Loading saved results', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'The dashboard is loading the latest saved results. Health checks continue in the background.', 'healthlens' ) );
		echo '</section>';
		echo '<section class="healthlens-dashboard__state healthlens-dashboard__state--stale" data-healthlens-state="stale" aria-labelledby="healthlens-stale-title" hidden>';
		printf( '<h2 id="healthlens-stale-title">%s</h2>', esc_html__( 'Results need attention', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'The saved results are stale. Wait for the next background check before relying on this status.', 'healthlens' ) );
		echo '</section>';
	}

	/**
	 * Render the read-only summary and status cards.
	 *
	 * @param array<string, mixed> $view Dashboard view model.
	 * @return void
	 */
	private function render_summary( array $view ) {
		$items         = isset( $view['items'] ) && is_array( $view['items'] ) ? $view['items'] : array();
		$history       = isset( $view['history'] ) && is_array( $view['history'] ) ? $view['history'] : array();
		$notifications = isset( $view['notifications'] ) && is_array( $view['notifications'] ) ? $view['notifications'] : array();

		printf( '<p class="healthlens-dashboard__lede">%s</p>', esc_html__( 'See what needs attention, understand the impact, and decide what to do next.', 'healthlens' ) );
		$this->render_tab_navigation();

		echo '<section id="healthlens-panel-overview" class="healthlens-dashboard__panel" data-healthlens-panel="overview" aria-labelledby="healthlens-overview-title">';
		echo '<header class="healthlens-dashboard__panel-header">';
		printf( '<p class="healthlens-dashboard__eyebrow">%s</p>', esc_html__( 'Site overview', 'healthlens' ) );
		printf( '<h2 id="healthlens-overview-title">%s</h2>', esc_html__( 'Is your site healthy?', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'This summary uses the latest saved results. HealthLens never runs checks just because you opened this page.', 'healthlens' ) );
		echo '</header>';
		echo '<div class="healthlens-dashboard__overview-grid">';
		$this->render_score( $view['score'], $view['coverage'], $view['availability'] );
		echo '<article class="healthlens-dashboard__surface healthlens-dashboard__signal-card" aria-labelledby="healthlens-signal-title">';
		printf( '<h3 id="healthlens-signal-title">%s</h3>', esc_html__( 'Current signal', 'healthlens' ) );
		$this->render_freshness( $view['freshness'] );
		$this->render_metric_summary( $view );
		echo '</article></div>';
		$this->render_status_distribution( $items );
		$this->render_categories( $items );
		echo '</section>';

		echo '<section id="healthlens-panel-checks" class="healthlens-dashboard__panel" data-healthlens-panel="checks" aria-labelledby="healthlens-checks-title">';
		echo '<header class="healthlens-dashboard__panel-header">';
		printf( '<p class="healthlens-dashboard__eyebrow">%s</p>', esc_html__( 'Checks', 'healthlens' ) );
		printf( '<h2 id="healthlens-checks-title">%s</h2>', esc_html__( 'What needs your attention?', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'Critical and warning results appear first. Open technical details only when you need more context.', 'healthlens' ) );
		echo '</header>';
		$this->render_priority_items( $items );
		$this->render_status_items( $items );
		echo '</section>';

		echo '<section id="healthlens-panel-activity" class="healthlens-dashboard__panel" data-healthlens-panel="activity" aria-labelledby="healthlens-activity-title">';
		echo '<header class="healthlens-dashboard__panel-header">';
		printf( '<p class="healthlens-dashboard__eyebrow">%s</p>', esc_html__( 'Activity', 'healthlens' ) );
		printf( '<h2 id="healthlens-activity-title">%s</h2>', esc_html__( 'What changed recently?', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'Review recently resolved incidents and the aggregate outcome of background notifications.', 'healthlens' ) );
		echo '</header>';
		echo '<div class="healthlens-dashboard__activity-grid">';
		$this->render_history( $history );
		$this->render_notifications( $notifications );
		echo '</div></section>';
	}

	/**
	 * Render anchor navigation that JavaScript progressively enhances into tabs.
	 *
	 * @return void
	 */
	private function render_tab_navigation() {
		echo '<nav class="healthlens-dashboard__tab-nav" aria-label="' . esc_attr( __( 'Dashboard views', 'healthlens' ) ) . '">';
		echo '<div class="healthlens-dashboard__tabs" data-healthlens-tabs>';
		printf( '<a id="healthlens-tab-overview" class="healthlens-dashboard__tab" data-healthlens-tab="overview" href="#healthlens-panel-overview">%s</a>', esc_html__( 'Overview', 'healthlens' ) );
		printf( '<a id="healthlens-tab-checks" class="healthlens-dashboard__tab" data-healthlens-tab="checks" href="#healthlens-panel-checks">%s</a>', esc_html__( 'Checks', 'healthlens' ) );
		printf( '<a id="healthlens-tab-activity" class="healthlens-dashboard__tab" data-healthlens-tab="activity" href="#healthlens-panel-activity">%s</a>', esc_html__( 'Activity', 'healthlens' ) );
		echo '</div></nav>';
	}

	/**
	 * Render the high-level metrics that help an administrator triage the site.
	 *
	 * @param array<string, mixed> $view Dashboard view model.
	 * @return void
	 */
	private function render_metric_summary( array $view ) {
		$items     = isset( $view['items'] ) && is_array( $view['items'] ) ? $view['items'] : array();
		$statuses  = $this->status_counts( $items );
		$incidents = isset( $view['incidents'] ) && is_array( $view['incidents'] ) ? $view['incidents'] : array();
		$coverage  = isset( $view['score']['coverage_percentage'] ) ? (int) $view['score']['coverage_percentage'] : 0;
		$metrics   = array(
			'critical' => array( __( 'Critical', 'healthlens' ), $statuses['critical'] ),
			'warning'  => array( __( 'Warnings', 'healthlens' ), $statuses['warning'] ),
			'incident' => array( __( 'Open incidents', 'healthlens' ), (int) ( $incidents['open_count'] ?? 0 ) ),
			'coverage' => array( __( 'Coverage', 'healthlens' ), $coverage, '%' ),
		);

		echo '<dl class="healthlens-dashboard__metrics">';
		foreach ( $metrics as $key => $metric ) {
			$suffix = isset( $metric[2] ) ? $metric[2] : '';
			echo '<div class="healthlens-dashboard__metric healthlens-dashboard__metric--' . esc_attr( $key ) . '">';
			printf( '<dt>%s</dt><dd>%d%s</dd>', esc_html( $metric[0] ), (int) $metric[1], esc_html( $suffix ) );
			echo '</div>';
		}
		echo '</dl>';
	}

	/**
	 * Render a color-independent stacked composition chart for current checks.
	 *
	 * @param array<int, array<string, mixed>> $items Dashboard items.
	 * @return void
	 */
	private function render_status_distribution( array $items ) {
		$counts = $this->status_counts( $items );
		$total  = array_sum( $counts );

		echo '<section class="healthlens-dashboard__surface healthlens-dashboard__distribution" aria-labelledby="healthlens-distribution-title">';
		echo '<div class="healthlens-dashboard__section-heading">';
		printf( '<div><p class="healthlens-dashboard__eyebrow">%s</p><h3 id="healthlens-distribution-title">%s</h3></div>', esc_html__( 'Current checks', 'healthlens' ), esc_html__( 'Status distribution', 'healthlens' ) );
		printf( '<p>%s</p>', esc_html__( 'A proportional view of every check in the latest saved result set.', 'healthlens' ) );
		echo '</div>';

		if ( 0 === $total ) {
			printf( '<p class="healthlens-dashboard__empty">%s</p>', esc_html__( 'No check status data is available yet.', 'healthlens' ) );
			echo '</section>';
			return;
		}

		$summary = array();
		foreach ( $counts as $key => $count ) {
			if ( 0 === $count ) {
				continue;
			}
			$status    = $this->status_label( $key, $key );
			$summary[] = sprintf( '%1$s: %2$d', $status['label'], $count );
		}
		// translators: %s is a comma-separated list of translated status labels and counts.
		$chart_label = sprintf( __( 'Status distribution: %s', 'healthlens' ), implode( ', ', $summary ) );

		echo '<div class="healthlens-dashboard__stacked-bar" role="img" aria-label="' . esc_attr( $chart_label ) . '">';
		foreach ( $counts as $key => $count ) {
			if ( 0 === $count ) {
				continue;
			}
			printf( '<span class="healthlens-dashboard__segment healthlens-dashboard__segment--%s" style="--healthlens-segment:%d" aria-hidden="true"></span>', esc_attr( $key ), (int) $count );
		}
		echo '</div><ul class="healthlens-dashboard__legend">';
		foreach ( $counts as $key => $count ) {
			$status = $this->status_label( $key, $key );
			printf( '<li><span class="healthlens-dashboard__legend-mark healthlens-dashboard__legend-mark--%s" aria-hidden="true">%s</span><span>%s</span><strong>%d</strong></li>', esc_attr( $key ), esc_html( $status['icon'] ), esc_html( $status['label'] ), (int) $count );
		}
		echo '</ul></section>';
	}

	/**
	 * Classify each visible check into one non-overlapping chart status.
	 *
	 * @param array<int, array<string, mixed>> $items Dashboard items.
	 * @return array<string, int>
	 */
	private function status_counts( array $items ) {
		$counts = array(
			'critical' => 0,
			'warning'  => 0,
			'healthy'  => 0,
			'info'     => 0,
			'unknown'  => 0,
			'missing'  => 0,
		);

		foreach ( $items as $item ) {
			if ( CheckResult::STATE_UNKNOWN === $item['state'] ) {
				++$counts['unknown'];
			} elseif ( 'missing' === $item['state'] ) {
				++$counts['missing'];
			} elseif ( isset( $counts[ $item['severity'] ] ) ) {
				++$counts[ $item['severity'] ];
			}
		}

		return $counts;
	}

	/**
	 * Render bounded resolved incident history.
	 *
	 * @param array<int, array<string, mixed>> $history History rows.
	 * @return void
	 */
	private function render_history( array $history ) {
		echo '<section class="healthlens-dashboard__surface healthlens-dashboard__activity-card" aria-labelledby="healthlens-history-title">';
		printf( '<h2 id="healthlens-history-title">%s</h2>', esc_html__( 'Recent incident history', 'healthlens' ) );
		if ( empty( $history ) ) {
			printf( '<p>%s</p>', esc_html__( 'No resolved incidents are recorded in the seven-day history window.', 'healthlens' ) );
			echo '</section>';
			return;
		}
		echo '<ul class="healthlens-dashboard__history">';
		foreach ( $history as $item ) {
			printf(
				'<li><strong>%s</strong> · %s · %s: ',
				esc_html( $item['check_id'] ),
				esc_html( $item['severity'] ),
				esc_html__( 'resolved', 'healthlens' )
			);
			$this->render_time( $item['resolved_at'] );
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Render aggregate notification status without a recipient address.
	 *
	 * @param array<string, mixed> $status Notification status.
	 * @return void
	 */
	private function render_notifications( array $status ) {
		echo '<section class="healthlens-dashboard__surface healthlens-dashboard__activity-card" aria-labelledby="healthlens-notification-title">';
		printf( '<h2 id="healthlens-notification-title">%s</h2>', esc_html__( 'Notification status', 'healthlens' ) );
		echo '<dl class="healthlens-dashboard__metrics healthlens-dashboard__metrics--notifications">';
		printf( '<div class="healthlens-dashboard__metric"><dt>%s</dt><dd>%d</dd></div>', esc_html__( 'Tracked events', 'healthlens' ), (int) ( $status['event_count'] ?? 0 ) );
		printf( '<div class="healthlens-dashboard__metric healthlens-dashboard__metric--healthy"><dt>%s</dt><dd>%d</dd></div>', esc_html__( 'Sent attempts', 'healthlens' ), (int) ( $status['sent_count'] ?? 0 ) );
		printf( '<div class="healthlens-dashboard__metric healthlens-dashboard__metric--critical"><dt>%s</dt><dd>%d</dd></div>', esc_html__( 'Failed attempts', 'healthlens' ), (int) ( $status['failed_count'] ?? 0 ) );
		echo '</dl>';
		if ( ! empty( $status['last_attempt_at'] ) ) {
			printf( '<p>%s: ', esc_html__( 'Last background attempt', 'healthlens' ) );
			$this->render_time( $status['last_attempt_at'] );
			echo '</p>';
		} else {
			printf( '<p>%s</p>', esc_html__( 'No notification attempt has been recorded.', 'healthlens' ) );
		}
		echo '</section>';
	}

	/**
	 * Render freshness status.
	 *
	 * @param array<string, mixed> $freshness Freshness view data.
	 * @return void
	 */
	private function render_freshness( array $freshness ) {
		$messages = array(
			'no-data'   => __( 'No checks are registered yet.', 'healthlens' ),
			'never-run' => __( 'HealthLens has not completed a check yet.', 'healthlens' ),
			'stale'     => __( 'Saved results are stale. Use Run checks now or wait for WP-Cron to refresh them in the background.', 'healthlens' ),
			'partial'   => __( 'Some checks have no saved result yet.', 'healthlens' ),
			'current'   => __( 'Showing the latest saved results.', 'healthlens' ),
		);
		$state    = isset( $freshness['state'] ) && isset( $messages[ $freshness['state'] ] ) ? $freshness['state'] : 'no-data';

		echo '<div class="healthlens-dashboard__state healthlens-dashboard__state--' . esc_attr( $state ) . '" role="status" aria-live="polite">';
		printf( '<p><strong>%s</strong></p>', esc_html( $messages[ $state ] ) );
		if ( ! empty( $freshness['last_checked_at'] ) ) {
			printf( '<p>%s: ', esc_html__( 'Last checked', 'healthlens' ) );
			$this->render_time( $freshness['last_checked_at'] );
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Render score and coverage.
	 *
	 * @param array<string, mixed> $score Score data.
	 * @param array<string, mixed> $coverage Coverage data.
	 * @param array<string, mixed> $availability Availability data.
	 * @return void
	 */
	private function render_score( array $score, array $coverage, array $availability ) {
		$bands       = array(
			'healthy'                     => __( 'Healthy', 'healthlens' ),
			'attention-recommended'       => __( 'Attention recommended', 'healthlens' ),
			'problems-detected'           => __( 'Problems detected', 'healthlens' ),
			'critical-attention-required' => __( 'Critical attention required', 'healthlens' ),
			'insufficient-coverage'       => __( 'Insufficient coverage', 'healthlens' ),
		);
		$band        = isset( $score['band'], $bands[ $score['band'] ] ) ? $score['band'] : 'insufficient-coverage';
		$band_label  = $bands[ $band ];
		$score_value = null === $score['value'] ? 0 : max( 0, min( 100, (int) $score['value'] ) );
		// translators: %d is the calculated score from 0 through 100.
		$score_label = null === $score['value'] ? $band_label : sprintf( __( 'Overall health score: %d out of 100', 'healthlens' ), $score_value );

		echo '<article class="healthlens-dashboard__surface healthlens-dashboard__score-card healthlens-dashboard__score-card--' . esc_attr( $band ) . '" aria-labelledby="healthlens-score-title">';
		printf( '<h3 id="healthlens-score-title">%s</h3>', esc_html__( 'Overall health', 'healthlens' ) );
		echo '<div class="healthlens-dashboard__score-layout">';
		echo '<div class="healthlens-dashboard__score-lens" role="img" aria-label="' . esc_attr( $score_label ) . '">';
		echo '<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false"><circle class="healthlens-dashboard__score-track" cx="60" cy="60" r="50" pathLength="100"></circle>';
		printf( '<circle class="healthlens-dashboard__score-value" cx="60" cy="60" r="50" pathLength="100" stroke-dasharray="%s 100"></circle>', esc_attr( (string) $score_value ) );
		echo '</svg><span class="healthlens-dashboard__score-number">';
		if ( null === $score['value'] ) {
			echo '<strong aria-hidden="true">—</strong>';
		} else {
			printf( '<strong aria-hidden="true">%s</strong><span aria-hidden="true">/100</span>', esc_html( (string) $score_value ) );
		}
		echo '</span></div><div class="healthlens-dashboard__score-copy">';
		printf( '<p class="healthlens-dashboard__score-band"><strong>%s</strong></p>', esc_html( $band_label ) );
		printf( '<p>%s</p>', esc_html__( 'A weighted summary of completed checks. Use the detailed results to decide what to fix.', 'healthlens' ) );
		printf( '<p class="healthlens-dashboard__coverage"><strong>%d%%</strong> %s <span>(%d/%d)</span></p>', (int) $score['coverage_percentage'], esc_html__( 'coverage', 'healthlens' ), (int) $coverage['completed'], (int) $coverage['total'] );
		if ( 'never-run' === $availability['state'] || 'no-data' === $availability['state'] ) {
			printf( '<p>%s</p>', esc_html__( 'A score will appear after saved check results are available.', 'healthlens' ) );
		}
		echo '</div></div></article>';
	}

	/**
	 * Render critical and warning items before other states.
	 *
	 * @param array<int, array<string, mixed>> $items Dashboard items.
	 * @return void
	 */
	private function render_priority_items( array $items ) {
		echo '<section class="healthlens-dashboard__check-group" aria-labelledby="healthlens-priority-title">';
		printf( '<h2 id="healthlens-priority-title">%s</h2>', esc_html__( 'Priority issues', 'healthlens' ) );
		$has_priority = false;
		echo '<div class="healthlens-dashboard__cards">';

		foreach ( array( CheckResult::SEVERITY_CRITICAL, CheckResult::SEVERITY_WARNING ) as $severity ) {
			foreach ( $items as $item ) {
				if ( $severity !== $item['severity'] || CheckResult::STATE_UNKNOWN === $item['state'] || 'missing' === $item['state'] ) {
					continue;
				}

				$has_priority = true;
				$this->render_item( $item );
			}
		}

		if ( ! $has_priority ) {
			printf( '<p>%s</p>', esc_html__( 'No critical or warning issues are recorded.', 'healthlens' ) );
		}
		echo '</div></section>';
	}

	/**
	 * Render healthy, unknown, and missing statuses.
	 *
	 * @param array<int, array<string, mixed>> $items Dashboard items.
	 * @return void
	 */
	private function render_status_items( array $items ) {
		echo '<section class="healthlens-dashboard__check-group" aria-labelledby="healthlens-status-list-title">';
		printf( '<h2 id="healthlens-status-list-title">%s</h2>', esc_html__( 'Check status', 'healthlens' ) );
		$has_status = false;
		echo '<div class="healthlens-dashboard__cards">';

		foreach ( $items as $item ) {
			if (
				in_array( $item['severity'], array( CheckResult::SEVERITY_CRITICAL, CheckResult::SEVERITY_WARNING ), true ) &&
				CheckResult::STATE_UNKNOWN !== $item['state'] &&
				'missing' !== $item['state']
			) {
				continue;
			}

			$has_status = true;
			$this->render_item( $item );
		}

		if ( ! $has_status ) {
			printf( '<p>%s</p>', esc_html__( 'No other check states are available.', 'healthlens' ) );
		}
		echo '</div></section>';
	}

	/**
	 * Render one accessible status card.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return void
	 */
	private function render_item( array $item ) {
		$copy        = $this->message_copy( $item['message_code'], $item['state'], $item['severity'] );
		$status      = $this->status_label( $item['state'], $item['severity'] );
		$check_label = $this->check_label( $item['check_id'] );
		$card_id     = 'healthlens-card-' . sanitize_html_class( $item['check_id'] );
		$card_status = in_array( $item['state'], array( CheckResult::STATE_UNKNOWN, 'missing' ), true ) ? $item['state'] : $item['severity'];

		echo '<article class="healthlens-dashboard__card healthlens-dashboard__card--' . esc_attr( $card_status ) . '" aria-labelledby="' . esc_attr( $card_id ) . '">';
		echo '<div class="healthlens-dashboard__card-header">';
		echo '<div><p class="healthlens-dashboard__card-eyebrow">' . esc_html__( 'Health check', 'healthlens' ) . '</p>';
		printf( '<h3 id="%s">%s</h3></div>', esc_attr( $card_id ), esc_html( $check_label ) );
		printf( '<p class="healthlens-dashboard__status healthlens-dashboard__status--%s"><span aria-hidden="true">%s</span> <strong>%s</strong></p>', esc_attr( $card_status ), esc_html( $status['icon'] ), esc_html( $status['label'] ) );
		echo '</div>';
		printf( '<p>%s</p>', esc_html( $copy['explanation'] ) );
		printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Recommended action', 'healthlens' ), esc_html( $copy['recommendation'] ) );
		$this->render_remediation_link( $item );
		printf( '<p class="healthlens-dashboard__check-id"><code>%s</code> · %s</p>', esc_html( $item['check_id'] ), esc_html( $item['category'] ) );

		if ( ! empty( $item['checked_at'] ) ) {
			printf( '<p>%s: ', esc_html__( 'Last checked', 'healthlens' ) );
			$this->render_time( $item['checked_at'] );
			echo '</p>';
		} else {
			printf( '<p>%s</p>', esc_html__( 'This check has not run yet.', 'healthlens' ) );
		}

		if ( ! empty( $item['incident'] ) ) {
			printf( '<p>%s: ', esc_html__( 'Incident active since', 'healthlens' ) );
			$this->render_time( $item['incident']['first_detected_at'] );
			echo '</p>';
		}

		if ( ! empty( $item['technical_details'] ) ) {
			echo '<details class="healthlens-dashboard__details">';
			printf( '<summary>%s</summary>', esc_html__( 'Show technical details', 'healthlens' ) );
			echo '<dl>';
			foreach ( $item['technical_details'] as $key => $value ) {
				printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $key ), esc_html( (string) $value ) );
			}
			echo '</dl></details>';
		}
		echo '</article>';
	}

	/**
	 * Render a navigation-only link for an actionable non-healthy result.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return void
	 */
	private function render_remediation_link( array $item ) {
		if ( CheckResult::STATE_HEALTHY === $item['state'] ) {
			return;
		}

		$destination = $this->remediation_catalog->for_check( $item['check_id'] );
		if ( ! is_array( $destination ) || empty( $destination['path'] ) || empty( $destination['label'] ) || ! function_exists( 'admin_url' ) ) {
			return;
		}

		$url = admin_url( $destination['path'] );
		printf(
			'<p class="healthlens-dashboard__remediation"><a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html( $destination['label'] )
		);
	}

	/**
	 * Return a translated human-readable name for a stable check ID.
	 *
	 * @param mixed $check_id Stable check identifier.
	 * @return string
	 */
	private function check_label( $check_id ) {
		$labels = array(
			'wordpress-version'        => __( 'WordPress version', 'healthlens' ),
			'rest-api-availability'    => __( 'REST API availability', 'healthlens' ),
			'loopback-requests'        => __( 'Loopback requests', 'healthlens' ),
			'wp-cron-schedule'         => __( 'WP-Cron schedule', 'healthlens' ),
			'administrator-email'      => __( 'Administrator email', 'healthlens' ),
			'database-connectivity'    => __( 'Database connectivity', 'healthlens' ),
			'database-charset-schema'  => __( 'Database charset and schema', 'healthlens' ),
			'autoloaded-options'       => __( 'Autoloaded options', 'healthlens' ),
			'database-storage-growth'  => __( 'Database storage growth', 'healthlens' ),
			'ssl-https'                => __( 'SSL and HTTPS', 'healthlens' ),
			'filesystem-paths'         => __( 'Filesystem paths', 'healthlens' ),
			'disk-space'               => __( 'Disk space', 'healthlens' ),
			'wordpress-storage-config' => __( 'WordPress storage configuration', 'healthlens' ),
		);

		if ( is_string( $check_id ) && isset( $labels[ $check_id ] ) ) {
			return $labels[ $check_id ];
		}

		$label = is_scalar( $check_id ) ? (string) $check_id : '';
		$label = str_replace( array( '-', '_' ), ' ', $label );

		return '' !== $label ? ucwords( $label ) : __( 'Health check', 'healthlens' );
	}

	/**
	 * Render a machine-readable timestamp with site-local, user-friendly text.
	 *
	 * @param mixed $timestamp ISO 8601 timestamp.
	 * @return void
	 */
	private function render_time( $timestamp ) {
		$value = is_scalar( $timestamp ) ? (string) $timestamp : '';

		printf( '<time datetime="%s">%s</time>', esc_attr( $value ), esc_html( $this->format_time( $value ) ) );
	}

	/**
	 * Format a timestamp using the WordPress locale, site timezone, and preferences.
	 *
	 * @param string $timestamp ISO 8601 timestamp.
	 * @return string
	 */
	private function format_time( $timestamp ) {
		try {
			$instant = new \DateTimeImmutable( $timestamp );
		} catch ( \Exception $exception ) {
			return $timestamp;
		}

		if ( function_exists( 'wp_date' ) ) {
			$date_format = function_exists( 'get_option' ) ? get_option( 'date_format', 'F j, Y' ) : 'F j, Y';
			$time_format = function_exists( 'get_option' ) ? get_option( 'time_format', 'g:i a' ) : 'g:i a';
			$date_format = is_string( $date_format ) && '' !== $date_format ? $date_format : 'F j, Y';
			$time_format = is_string( $time_format ) && '' !== $time_format ? $time_format : 'g:i a';
			$date        = wp_date( $date_format, $instant->getTimestamp() );
			$time        = wp_date( $time_format, $instant->getTimestamp() );

			// translators: 1: localized date, 2: localized time.
			return sprintf( __( '%1$s at %2$s', 'healthlens' ), $date, $time );
		}

		return $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'M j, Y \\a\\t g:i a \\U\\T\\C' );
	}

	/**
	 * Render category counts.
	 *
	 * @param array<int, array<string, mixed>> $items Dashboard items.
	 * @return void
	 */
	private function render_categories( array $items ) {
		$categories = array();
		foreach ( $items as $item ) {
			if ( ! isset( $categories[ $item['category'] ] ) ) {
				$categories[ $item['category'] ] = 0;
			}
			++$categories[ $item['category'] ];
		}
		ksort( $categories, SORT_STRING );

		echo '<section class="healthlens-dashboard__category-section" aria-labelledby="healthlens-category-title">';
		printf( '<h2 id="healthlens-category-title">%s</h2>', esc_html__( 'Categories', 'healthlens' ) );
		if ( empty( $categories ) ) {
			printf( '<p>%s</p>', esc_html__( 'No categories are available yet.', 'healthlens' ) );
		} else {
			echo '<ul class="healthlens-dashboard__categories">';
			foreach ( $categories as $category => $count ) {
				printf( '<li class="healthlens-dashboard__category"><span>%s</span><strong>%d</strong></li>', esc_html( $category ), (int) $count );
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * Return translated fallback copy for a stable message code/state.
	 *
	 * @param mixed $message_code Stable message code.
	 * @param mixed $state Result state.
	 * @param mixed $severity Result severity.
	 * @return array<string, string>
	 */
	private function message_copy( $message_code, $state, $severity ) {
		$messages = array(
			'check.execution-failed' => array(
				'title'          => __( 'Health check could not complete', 'healthlens' ),
				'explanation'    => __( 'HealthLens could not determine a reliable result for this check.', 'healthlens' ),
				'recommendation' => __( 'Review the next background run and investigate only the safe details shown below.', 'healthlens' ),
			),
		);

		if ( is_string( $message_code ) && isset( $messages[ $message_code ] ) ) {
			return $messages[ $message_code ];
		}

		$fallback = array(
			CheckResult::STATE_HEALTHY => array( __( 'Check is healthy', 'healthlens' ), __( 'No problem was detected by this check.', 'healthlens' ), __( 'Continue normal monitoring.', 'healthlens' ) ),
			CheckResult::STATE_UNKNOWN => array( __( 'Check result is unknown', 'healthlens' ), __( 'This check did not produce a reliable result.', 'healthlens' ), __( 'Wait for the next background run before treating this check as healthy.', 'healthlens' ) ),
			'missing'                  => array( __( 'Check has not run yet', 'healthlens' ), __( 'There is no saved result for this check.', 'healthlens' ), __( 'Wait for the next background run to collect a result.', 'healthlens' ) ),
			'critical'                 => array( __( 'Critical issue detected', 'healthlens' ), __( 'This check found a problem that needs prompt attention.', 'healthlens' ), __( 'Review the recommended action and resolve the underlying issue.', 'healthlens' ) ),
			'warning'                  => array( __( 'Warning detected', 'healthlens' ), __( 'This check found a condition that may affect site health.', 'healthlens' ), __( 'Review the recommendation and monitor the next saved result.', 'healthlens' ) ),
		);
		if ( CheckResult::STATE_UNKNOWN === $state || 'missing' === $state ) {
			$key = $state;
		} elseif ( is_string( $message_code ) && isset( $messages[ $message_code ] ) ) {
			$key = $message_code;
		} elseif ( isset( $fallback[ $severity ] ) ) {
			$key = $severity;
		} else {
			$key = CheckResult::STATE_UNKNOWN;
		}

		if ( isset( $messages[ $key ] ) ) {
			return $messages[ $key ];
		}

		return array(
			'title'          => $fallback[ $key ][0],
			'explanation'    => $fallback[ $key ][1],
			'recommendation' => $fallback[ $key ][2],
		);
	}

	/**
	 * Return a color-independent status label and icon.
	 *
	 * @param mixed $state Result state.
	 * @param mixed $severity Result severity.
	 * @return array<string, string>
	 */
	private function status_label( $state, $severity ) {
		$labels = array(
			CheckResult::SEVERITY_CRITICAL => array(
				'label' => __( 'Critical', 'healthlens' ),
				'icon'  => '!',
			),
			CheckResult::SEVERITY_WARNING  => array(
				'label' => __( 'Warning', 'healthlens' ),
				'icon'  => '!',
			),
			CheckResult::SEVERITY_INFO     => array(
				'label' => __( 'Information', 'healthlens' ),
				'icon'  => 'i',
			),
			CheckResult::SEVERITY_HEALTHY  => array(
				'label' => __( 'Healthy', 'healthlens' ),
				'icon'  => '✓',
			),
			'unknown'                      => array(
				'label' => __( 'Unknown', 'healthlens' ),
				'icon'  => '?',
			),
			'missing'                      => array(
				'label' => __( 'Not checked', 'healthlens' ),
				'icon'  => '—',
			),
		);

		$key = isset( $labels[ $state ] ) ? $state : $severity;
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $labels['unknown'];
	}
}
