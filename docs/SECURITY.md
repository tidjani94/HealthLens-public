# Security Design

HealthLens treats all input, stored diagnostics, third-party plugin output, and remote responses as untrusted.

- Authorize with `current_user_can( 'manage_options' )`; nonces protect intent but never replace authorization.
- Validate and sanitize specific inputs early; escape at the output context late.
- Use `$wpdb->prepare()` for dynamic SQL and never interpolate user-controlled identifiers or values.
- Future arbitrary URL checks must use safe WordPress HTTP APIs, bounded timeouts, redirect limits, and response-size limits.
- REST routes require explicit schemas and `permission_callback`.
- Technical details are allowlisted and capped. Never show passwords, API secrets, database credentials, salts, raw request bodies, stack traces, or unnecessary absolute paths.
- Dashboard technical details are escaped at output and limited to the already-redacted scalar context boundary; native `<details>` disclosure does not create a new data or request path.
- Settings changes use the native Settings API with `manage_options`, a sanitizer allowlist, and the existing non-autoloaded site option; no network-admin or REST setting surface is registered.
- One check failure becomes an internal `unknown` result; it must not produce a fatal error or disable the site.
- Technical context redacts both sensitive keys and obvious sensitive scalar values such as URLs, absolute paths, and inline credential-like assignments before persistence.
- M3 current-site REST and loopback probes use `wp_safe_remote_get()` with a host allowlist, five-second timeout, one redirect, an 8 KiB response cap, and no forwarded credentials. This is a local diagnostic boundary, not telemetry or an arbitrary URL scanner.

Security and privacy reviews are required before each new data source, external request, or optional integration.

M4 error capture is opt-in, bounded, redacted, site-local, and failure-isolated as documented in [ERROR-CAPTURE.md](ERROR-CAPTURE.md). Its adapter observes only approved PHP levels and passive shutdown state; it preserves prior handlers and WordPress recovery behavior, never ingests `debug.log`, and never globally intercepts third-party failures.

M5 database scans are read-only, current-site-only, prepared, fixed-allowlist operations with a four-query/500 ms budget; aggregate results do not expose raw option values, table contents, credentials, SQL errors, or unrelated table identifiers.

M6 scheduling and integrations use WP-Cron as the safe baseline. The optional Action Scheduler adapter is runtime-gated, initialized before API use, namespaced, failure-isolated, and unable to create duplicate dispatchers or bypass the existing lock and budgets. Third-party output is untrusted; no raw queue data, credentials, telemetry, or cross-site state is introduced.

M7 SSL and storage checks are planned behind issue #78. SSL/TLS checks must retain certificate verification, reject arbitrary targets, use bounded safe HTTP, and exclude credentials and raw responses. Filesystem and disk checks must constrain paths to the current site, reject traversal/symlink escapes, avoid writes/content reads/shell commands, and redact absolute paths, filenames, permissions, and host details.

M8 notifications and history are implemented behind issue #86. Notifications require explicit per-site consent, validated recipients, fixed templates, stable deduplication, bounded retries, and failure isolation. `wp_mail()` success is not treated as end-to-end delivery proof. Raw context, credentials, email addresses in diagnostics, provider payloads, and request-time sends are prohibited. The dashboard exposes only aggregate delivery counts and bounded resolved history.

M9 optional integrations are implemented behind issue #94 as a disabled-by-default boundary. Gateway requests use the fixed approved HTTPS endpoint, certificate verification, safe URL validation, bounded timeout/redirect/response limits, authenticated requests, and minimized payloads. Connectors are untrusted and runtime-gated. Consent is separate from authentication; secrets are revocable, rotation-aware, absent from logs/UI/context, and never required for local operation.

## M0 foundation review

The M0 review found no critical or high foundation findings. The reviewed surfaces are deliberately small:

| Surface | Risk | Mitigation and evidence | Owner/scope |
| --- | --- | --- | --- |
| Activation | High if network-wide state or partial writes were allowed | Network activation is rejected before writes; site options use add_option() and autoload=no | M0 lifecycle |
| Deactivation | High if unrelated jobs or data were removed | Only the reserved healthlens_run_checks hook and healthlens_lock option are touched | M0 lifecycle |
| Uninstall | High if data loss were implicit or cross-site | uninstall.php is guarded by WP_UNINSTALL_PLUGIN; delete is default and explicit retention skips cleanup | M0 lifecycle |
| Bootstrap | High if it caused remote work or fatal errors | Conditional Composer loading, direct-access guard, no HTTP/database/check code | M0 bootstrap |
| Diagnostics and HTTP | Medium future risk | No diagnostic collection or outbound HTTP exists in M0; future issues must add bounded, allowlisted adapters | M1+ |
| Optional integrations | Medium future risk | No WooCommerce or Action Scheduler code is loaded; future adapters require runtime availability checks | M1+ |
| Packaging | Medium supply-chain risk | Production-only Composer install, fixed archive timestamps, manifest checks, and Plugin Check workflow | M0 release |

Follow-up work is tracked by the M1–M10 milestone issues. A new critical or high finding blocks the relevant milestone until it has a linked mitigation and reproducible evidence.

## M1 engine review

The M1 cross-cutting review is recorded in [PERFORMANCE.md](PERFORMANCE.md). No critical or high finding remains open: context value redaction was strengthened with regression coverage, while SQL, lock, lifecycle, multisite, and request-time boundaries passed their targeted tests and CI gates.
