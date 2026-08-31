# Changelog

## Unreleased

- Added the HealthLens project foundation, local WordPress skill bundle, architecture documentation, and inert plugin bootstrap.
- Added lifecycle safeguards, project-local quality tooling, PHPUnit scaffolding, wp-env integration setup, CI quality gates, and deterministic release packaging.
- Added the server-rendered dashboard accessibility, localization, no-JavaScript, bounded-query, and fixed-scenario performance verification evidence.
- Added the first concrete WordPress health-check catalog: bounded current-site REST/loopback probes, cached core-update interpretation, WP-Cron scheduling health, and privacy-preserving administrator-email configuration state.
- Added opt-in, site-local error capture with allowlisted redaction, bounded PHP/non-fatal and passive shutdown adapters, prepared persistence, duplicate suppression, retention, and failure isolation.
- Added four read-only, current-site database diagnostics for connectivity, charset/schema compatibility, autoloaded-option pressure, and fixed-table storage growth with bounded aggregate evidence.
- Added bounded SSL/HTTPS, filesystem, disk-capacity, and current-site storage configuration checks with path/URL redaction and no-write/no-recursive-scan behavior.
- Added explicit local notification preferences, finite WordPress mail delivery attempts, deduplication/cooldown state, seven-day incident history, and aggregate dashboard delivery status.
- Added the disabled-by-default optional integration boundary: fixed-host HTTPS transport, minimized versioned payloads, null connectors, finite retry policy, and lifecycle settings that reject arbitrary endpoints.
- Added the dated release-candidate readiness matrix and first running WordPress 7.0.4 smoke evidence; publication remains no-go until external Plugin Check and WordPress.org handoff prerequisites pass.
- Hardened Docker integration evidence for the expanded 13-check registry, restored consent settings after error-capture fixtures, and added browser screenshots for the dashboard and default-off settings state.
- Resolved the Plugin Check direct-database warnings by making prepared SQL data flow explicit at every repository call site; the reviewed warning baseline is now empty.
- Updated the release auditor to accept the official Plugin Check action's clean success message as zero findings.

## 0.1.0 release candidate

- Added the visible plugin version and COODIV Team attribution to the HealthLens admin screens.
- Compatibility: WordPress 7.0+ and PHP 7.4+; CI covers PHP 7.4, 8.3, and 8.5.
- Privacy: operation is site-local by default; no diagnostic data is sent to HealthLens or a third-party service.
- Migration: active-plugin bootstrap upgrades the schema and stored plugin version; failed or partial upgrades keep the previous version for retry.
- Known limitations: activation is per site, network-wide activation is rejected, WP-Cron supplies the background boundary, and WordPress.org publication is not yet performed.
