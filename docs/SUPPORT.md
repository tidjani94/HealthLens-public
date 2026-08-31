# Support

HealthLens support is handled through the public [GitHub issue tracker](https://github.com/tidjani94/HealthLens-public/issues). GitHub Issues are the authoritative place for bug reports, usage questions, and reproducible implementation work.

## What to include

For a useful report, include:

- HealthLens version and installation source.
- WordPress version, PHP version, and whether the site is multisite.
- The steps that reproduce the problem.
- Expected behavior and actual behavior.
- Relevant HealthLens or WordPress log messages after sanitizing them.
- Whether the problem occurs on a fresh site and whether it is reproducible with other plugins or themes disabled, when safe to test.

Do not paste credentials, API keys, salts, passwords, raw request bodies, complete database exports, absolute private paths, or unredacted site diagnostics. Replace domains, usernames, email addresses, identifiers, and other sensitive values with placeholders.

## Scope expectations

HealthLens currently operates per site and locally. Network-wide activation, cross-site aggregation, remote monitoring, notifications, and optional integrations are not support promises for the current release unless the relevant milestone documentation says otherwise.

The dashboard displays saved state and does not execute a synchronous check during page rendering. Administrators can use **Run checks now** to queue the existing bounded background job; a newly installed site may still show no saved results until WP-Cron runs that job.

## Security and privacy

Do not report a suspected vulnerability in a public issue. Follow the private process in [SECURITY.md](../SECURITY.md). For data-handling questions, see [PRIVACY.md](PRIVACY.md).

Support responses should preserve the same privacy and accessibility boundaries as the product: diagnostics remain minimized and redacted, and any proposed UI change must remain keyboard-usable, understandable without color alone, and translation-ready.
