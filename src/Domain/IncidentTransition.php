<?php
/**
 * Incident transition decision value.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

/**
 * Decides how a result changes one continuous incident period.
 */
final class IncidentTransition {
	const OPEN    = 'open';
	const UPDATE  = 'update';
	const RESOLVE = 'resolve';
	const NONE    = 'none';

	/**
	 * Decide the transition for a result and existing open incident.
	 *
	 * Unknown results remain non-healthy so they do not falsely resolve an issue.
	 *
	 * @param bool        $has_open_incident Whether an open incident exists.
	 * @param CheckResult $result Normalized result.
	 * @return string
	 */
	public static function decide( $has_open_incident, CheckResult $result ) {
		if ( CheckResult::STATE_HEALTHY === $result->state() ) {
			return $has_open_incident ? self::RESOLVE : self::NONE;
		}

		return $has_open_incident ? self::UPDATE : self::OPEN;
	}
}
