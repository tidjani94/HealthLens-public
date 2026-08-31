<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Release\PluginCheckReportAuditor;
use PHPUnit\Framework\TestCase;

final class PluginCheckReportAuditorTest extends TestCase {
	public function test_clean_report_is_non_blocking_with_empty_baseline(): void {
		$auditor = new PluginCheckReportAuditor();

		$result = $auditor->audit_json( "\xEF\xBB\xBF\r\n", array() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( array(), $result['baseline_warnings'] );
		$this->assertSame( array(), $result['unreviewed_warnings'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_plugin_check_success_message_is_non_blocking(): void {
		$auditor = new PluginCheckReportAuditor();

		$result = $auditor->audit_json( "Success: Checks complete. No errors found.\n", array() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_reviewed_warning_is_separate_from_unreviewed_findings(): void {
		$auditor = new PluginCheckReportAuditor();
		$report  = array(
			array(
				'type' => 'WARNING',
				'code' => 'PluginCheck.Security.DirectDB.UnescapedDBParameter',
				'file' => '/workspace/unpacked/healthlens/src/Infrastructure/Database/ResultRepository.php',
			),
			array(
				'type' => 'WARNING',
				'code' => 'PluginCheck.Security.NewFinding',
				'file' => 'src/New.php',
			),
		);

		$result = $auditor->audit(
			$report,
			array(
				array(
					'code'  => 'PluginCheck.Security.DirectDB.UnescapedDBParameter',
					'files' => array( 'src/Infrastructure/Database/ResultRepository.php' ),
				),
			)
		);

		$this->assertCount( 1, $result['baseline_warnings'] );
		$this->assertCount( 1, $result['unreviewed_warnings'] );
		$this->assertTrue( $result['blocking'] );
	}

	public function test_errors_and_unreviewed_warnings_are_blocking(): void {
		$auditor = new PluginCheckReportAuditor();

		$result = $auditor->audit(
			array(
				array(
					'type' => 'ERROR',
					'code' => 'PluginCheck.Security.FatalFinding',
					'file' => 'src/Fatal.php',
				),
				array(
					'type' => 'WARNING',
					'code' => 'PluginCheck.Security.UnreviewedFinding',
					'file' => 'src/Warning.php',
				),
			),
			array()
		);

		$this->assertCount( 1, $result['errors'] );
		$this->assertCount( 1, $result['unreviewed_warnings'] );
		$this->assertTrue( $result['blocking'] );
	}

	public function test_nested_json_report_is_supported(): void {
		$auditor = new PluginCheckReportAuditor();

		$result = $auditor->audit_json(
			json_encode(
				array(
					'results' => array(
						array(
							'type' => 'ERROR',
							'code' => 'PluginCheck.Test.Error',
						),
					),
				)
			),
			array()
		);

		$this->assertTrue( $result['blocking'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_plugin_check_action_file_blocks_are_supported(): void {
		$auditor = new PluginCheckReportAuditor();

		$result = $auditor->audit_json(
			"FILE: src/Infrastructure/Database/IncidentRepository.php\n" .
			"[{\"line\":78,\"column\":34,\"type\":\"WARNING\",\"code\":\"PluginCheck.Security.DirectDB.UnescapedDBParameter\"}]\n" .
			"FILE: src/Infrastructure/Database/ResultRepository.php\n" .
			"[{\"line\":68,\"column\":33,\"type\":\"WARNING\",\"code\":\"PluginCheck.Security.DirectDB.UnescapedDBParameter\"}]\n",
			array(
				array(
					'code'  => 'PluginCheck.Security.DirectDB.UnescapedDBParameter',
					'files' => array(
						'src/Infrastructure/Database/IncidentRepository.php',
						'src/Infrastructure/Database/ResultRepository.php',
					),
				),
			)
		);

		$this->assertCount( 2, $result['warnings'] );
		$this->assertCount( 2, $result['baseline_warnings'] );
		$this->assertCount( 0, $result['unreviewed_warnings'] );
		$this->assertFalse( $result['blocking'] );
	}
}
