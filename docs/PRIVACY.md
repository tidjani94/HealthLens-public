# Privacy

The free plugin operates locally by default. It stores configuration, current normalized check state, bounded technical context, and seven days of resolved history in the site database.

No HealthLens server, analytics service, or third-party diagnostic endpoint receives data in the MVP. Future remote services require explicit consent, documented purposes, a visible setting, authenticated transport, and a retention policy.

Uninstall deletes HealthLens-owned configuration and tables by default. Deactivation preserves them. Diagnostic messages must explain what is stored and why without exposing secrets. Context is allowlisted to scalar values, removes sensitive keys, redacts obvious URLs and absolute paths even under generic keys, rejects credential-like assignments, and is capped at 16 KiB.

The dashboard read model does not expose persisted `context_json` or raw database rows. It passes stable check/category IDs, result state/severity/message codes, UTC timestamps, bounded counts, incident timestamps, and at most 12 scalar fields from the already-redacted technical context to later presentation code.

The lifecycle option names are healthlens_settings, healthlens_schema_version, healthlens_plugin_version, and healthlens_lock. They are site-local and non-autoloaded. Deactivation does not delete them; uninstall deletes them unless healthlens_settings[retain_data_on_uninstall] is explicitly true. The current release has no telemetry or HealthLens remote transport. M3 REST and loopback checks may make bounded background-only requests to the current site's own canonical URLs and retain only response code and elapsed milliseconds.

The M2 settings screen exposes local retention and the disabled-by-default `capture_errors` preference. The dashboard may queue a site-local background run through a capability- and nonce-protected control, but it does not execute checks synchronously. No telemetry, remote transport, or network-wide option is added.

M4 error capture is implemented but disabled by default. When explicitly enabled, it stores only the bounded, redacted event contract in the current site’s `healthlens_errors` table. It does not store raw messages, paths, URLs, credentials, request data, emails, stack traces, or debug logs, and it does not send remote telemetry.

M5 database health scans are read-only, current-site-only diagnostics. They report aggregate connectivity, compatibility, autoload, and storage state, but never persist or display raw option values, table contents, credentials, SQL errors, or unrelated table identifiers. Each scan is bounded by four metadata queries/500 ms, with autoload work stopping at 2,000 entries.

M6 scheduling and integrations are local execution boundaries. Optional Action Scheduler is runtime-gated and transmits no data; backend migration/rollback stores no remote payload and removes only site-local HealthLens jobs. WooCommerce and future adapters remain absent unless their APIs are present and must not cross site boundaries or enable telemetry.

M7 SSL and storage diagnostics are planned to return aggregate local state only. They must not persist or display certificate/header contents, raw transport errors, absolute paths, filenames, file contents, usernames, credentials, or unrelated site data; multisite checks remain limited to the current site.

M8 notifications are implemented as disabled-by-default, explicit, site-local consent. The first channel attempts WordPress mail only from scheduled background work to a validated recipient using fixed localized content. It does not send raw context, credentials, provider payloads, URLs, or telemetry. Notification state is capped at 50 event keys with finite retries; resolved history is capped to seven days, and uninstall removes it unless retention is explicitly selected.

M9 optional integrations are implemented as a disabled-by-default boundary and do not change the local-first privacy posture. Any gateway configuration requires explicit per-site consent, the fixed approved authenticated HTTPS endpoint, minimized versioned payloads, connector isolation, finite retries, and revocation. Raw context, URLs, paths, credentials, emails, stack traces, request bodies, and third-party payloads are excluded; no production gateway is configured in the first running version.
