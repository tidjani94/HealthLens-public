<?php
/**
 * Applies the reviewed Plugin Check warning policy to a report.
 *
 * @package HealthLens
 */

namespace HealthLens\Release;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Audits Plugin Check findings without silently suppressing warnings.
 */
final class PluginCheckReportAuditor {
	/**
	 * Decode and audit a Plugin Check report.
	 *
	 * @param string                           $report_content Plugin Check JSON or action report output.
	 * @param array<int, array<string, mixed>> $baseline Reviewed warning entries.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When the report is not a supported format.
	 */
	public function audit_json( $report_content, array $baseline ) {
		$report_content = ltrim( $report_content, "\xEF\xBB\xBF \t\r\n" );
		if ( '' === $report_content || 'Success: Checks complete. No errors found.' === trim( $report_content ) ) {
			return $this->audit( array(), $baseline );
		}

		$report = json_decode( $report_content, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $report ) ) {
			$report = $this->parse_action_report( $report_content );
		}

		if ( ! is_array( $report ) ) {
			throw new InvalidArgumentException( 'Plugin Check report is not a supported JSON or action report.' );
		}

		return $this->audit( $report, $baseline );
	}

	/**
	 * Parse the grouped report emitted by wordpress/plugin-check-action.
	 *
	 * The action stores each source file as a FILE header followed by one JSON
	 * array. The CLI findings do not repeat the file path, so add the header as
	 * the finding file before passing the normalized findings to the auditor.
	 *
	 * @param mixed $report Plugin Check action output.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function parse_action_report( $report ) {
		if ( ! is_string( $report ) ) {
			return null;
		}

		$lines        = preg_split( '/\r\n|\r|\n/', trim( $report ) );
		$findings     = array();
		$current_file = '';
		$seen_file    = false;
		$seen_json    = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, 'FILE:' ) ) {
				$current_file = trim( substr( $line, 5 ) );
				if ( '' === $current_file ) {
					return null;
				}

				$seen_file = true;
				continue;
			}

			if ( ! $seen_file ) {
				return null;
			}

			$block = json_decode( $line, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $block ) ) {
				return null;
			}

			$seen_json = true;
			foreach ( $block as $finding ) {
				if ( ! is_array( $finding ) ) {
					return null;
				}

				if ( ! isset( $finding['file'] ) ) {
					$finding['file'] = $current_file;
				}

				$findings[] = $finding;
			}
		}

		if ( ! $seen_file || ! $seen_json ) {
			return null;
		}

		return $findings;
	}

	/**
	 * Audit a decoded Plugin Check report against reviewed warning entries.
	 *
	 * @param array<mixed>                     $report Decoded Plugin Check report.
	 * @param array<int, array<string, mixed>> $baseline Reviewed warning entries.
	 * @return array<string, mixed>
	 */
	public function audit( array $report, array $baseline ) {
		$findings = $this->collect_findings( $report );

		$errors              = array();
		$warnings            = array();
		$baseline_warnings   = array();
		$unreviewed_warnings = array();

		foreach ( $findings as $finding ) {
			$type = isset( $finding['type'] ) && is_string( $finding['type'] ) ? strtoupper( $finding['type'] ) : '';

			if ( 'ERROR' === $type ) {
				$errors[] = $finding;
				continue;
			}

			if ( 'WARNING' !== $type ) {
				continue;
			}

			$warnings[] = $finding;
			if ( $this->matches_baseline( $finding, $baseline ) ) {
				$baseline_warnings[] = $finding;
			} else {
				$unreviewed_warnings[] = $finding;
			}
		}

		return array(
			'errors'              => $errors,
			'warnings'            => $warnings,
			'baseline_warnings'   => $baseline_warnings,
			'unreviewed_warnings' => $unreviewed_warnings,
			'blocking'            => ! empty( $errors ) || ! empty( $unreviewed_warnings ),
		);
	}

	/**
	 * Collect finding-shaped arrays from the report's current JSON structure.
	 *
	 * @param mixed $value Report value or child node.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_findings( $value ) {
		$findings = array();

		if ( ! is_array( $value ) ) {
			return $findings;
		}

		if ( isset( $value['type'], $value['code'] ) ) {
			$findings[] = $value;
		}

		foreach ( $value as $child ) {
			$findings = array_merge( $findings, $this->collect_findings( $child ) );
		}

		return $findings;
	}

	/**
	 * Determine whether a finding is explicitly reviewed by code and file.
	 *
	 * @param array<string, mixed>             $finding Plugin Check finding.
	 * @param array<int, array<string, mixed>> $baseline Reviewed warning entries.
	 * @return bool
	 */
	private function matches_baseline( array $finding, array $baseline ) {
		$code = isset( $finding['code'] ) && is_string( $finding['code'] ) ? $finding['code'] : '';
		$file = isset( $finding['file'] ) && is_string( $finding['file'] ) ? $this->normalize_path( $finding['file'] ) : '';

		foreach ( $baseline as $entry ) {
			if ( ! isset( $entry['code'], $entry['files'] ) || $entry['code'] !== $code || ! is_array( $entry['files'] ) ) {
				continue;
			}

			foreach ( $entry['files'] as $baseline_file ) {
				if ( is_string( $baseline_file ) && $this->normalize_path( $baseline_file ) === $file ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalize repository and unpacked-archive paths to a stable source path.
	 *
	 * @param string $path Reported path.
	 * @return string
	 */
	private function normalize_path( $path ) {
		$path          = str_replace( '\\', '/', ltrim( $path, './' ) );
		$source_marker = strpos( $path, '/src/' );

		if ( false !== $source_marker ) {
			return substr( $path, $source_marker + 1 );
		}

		return $path;
	}
}
