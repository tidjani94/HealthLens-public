# Security Policy

HealthLens is intended to run on production WordPress sites, so security issues are release-blocking when they can expose data, elevate privileges, cause data loss, or create a fatal error.

Please report suspected vulnerabilities privately to the repository owner rather than opening a public issue. Include the affected version, reproduction steps, impact, and any suggested mitigation. Do not include credentials, API secrets, database dumps, or private customer data.

The plugin follows WordPress security guidance: authorize with capabilities, protect state changes with nonces, validate and sanitize input, escape at output, use `$wpdb->prepare()` for dynamic SQL, use safe HTTP requests, and avoid storing sensitive diagnostics. See [docs/SECURITY.md](docs/SECURITY.md) for the project threat model.
