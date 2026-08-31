# Plugin Check warning baseline

Reviewed: 2026-08-30 during the production-package remediation tracked by [issue #130](https://github.com/tidjani94/HealthLens-public/issues/130).

The production archive produced no Plugin Check errors or warnings. The warning baseline is intentionally empty after the repository query remediation. The release auditor treats the official action's empty or success-message clean report as zero findings:

| Code | Files | Rationale and follow-up |
| --- | --- | --- |
| — | — | No reviewed warnings. Repository calls pass `$wpdb->prepare()` directly to the database API so the data flow is visible to Plugin Check. |

This baseline is not a blanket ignore. The archive Plugin Check action must still run on every candidate; errors and warnings are blocking. When the Plugin Check version, source, query construction, or warning location changes, refresh this review and record the result in the release PR before promotion.

The machine-readable entries used by CI are kept in [`PLUGIN-CHECK-BASELINE.json`](PLUGIN-CHECK-BASELINE.json). The release workflows run `bin/plugin-check-audit.php` against the report produced by the official Plugin Check action; a missing, changed, or new warning is blocking.
