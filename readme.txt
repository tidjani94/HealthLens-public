=== HealthLens ===
Tags: site health, monitoring, diagnostics
Requires at least: 7.0
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

HealthLens is a local-first WordPress site health monitor with a native dashboard for saved, explainable health state.

== Description ==

HealthLens helps site owners understand the operational state of the current WordPress site and what to do next. The current release provides a native admin dashboard, 13 bounded WordPress/database/storage checks, opt-in local error capture and notifications, site-local lifecycle and settings, scheduled execution boundaries, bounded persistence, and a translation-ready presentation layer.

HealthLens is not a remote monitoring service. It operates per site; network-wide activation and cross-site aggregation are not supported.

== Installation ==

1. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the HealthLens release ZIP, or copy the `healthlens` directory to `wp-content/plugins/`.
2. Activate HealthLens for the site where it should run. Network-wide activation is not supported.
3. Open **HealthLens** in the WordPress admin. The dashboard reads saved state; it does not start a check during page rendering.

HealthLens requires WordPress 7.0 or newer and PHP 7.4 or newer.

== Screenshots ==

1. The overview shows the current health score, coverage, priority issues, and recent incident state.
2. The Checks view prioritizes attention-needed results and keeps technical details behind accessible disclosures.
3. The Settings screen makes data retention, local error capture, notifications, and optional gateway consent explicit.
4. The Activity view presents bounded incident history and notification activity without sending data off-site.

== Privacy ==

HealthLens operates locally by default and the current release does not send diagnostic data to HealthLens or a third-party service. It stores plugin-owned site-local configuration, normalized health results, bounded redacted technical context, and bounded history in the site database. It does not provide network-wide aggregation.

Deactivation preserves HealthLens data. Uninstall removes HealthLens-owned data by default; the **Retain data on uninstall** setting can preserve it when explicitly enabled. Future remote integrations, if shipped, will require explicit consent and will be documented here.

For the storage and deletion boundary, see [the privacy documentation](https://github.com/tidjani94/HealthLens-public/blob/main/docs/PRIVACY.md).

== FAQ ==

= Does HealthLens run on every page load? =

No. HealthLens uses scheduled/background execution boundaries. The dashboard displays saved state and does not synchronously start a check.

= Does HealthLens support multisite network aggregation? =

No. Activate and configure HealthLens per site. Network-wide activation and cross-site aggregation are outside the current release boundary.

= Does HealthLens send diagnostic data off the site? =

Not in the current release. HealthLens has no HealthLens server or third-party diagnostic endpoint. Any future remote service must be explicitly consented to and documented before it is enabled.

== Support ==

Report bugs and usage questions in the [HealthLens GitHub issues](https://github.com/tidjani94/HealthLens-public/issues). Include the HealthLens version, WordPress and PHP versions, steps to reproduce, expected behavior, actual behavior, and sanitized logs where useful. See the [support documentation](https://github.com/tidjani94/HealthLens-public/blob/main/docs/SUPPORT.md) for the reporting boundary.

Do not include passwords, API keys, salts, credentials, raw request bodies, complete database exports, or other sensitive site data. For security reports, follow [SECURITY.md](https://github.com/tidjani94/HealthLens-public/blob/main/SECURITY.md).

== Development ==

The source repository contains the development, translation, privacy, and release documentation. See the [translation and generated-asset guidance](https://github.com/tidjani94/HealthLens-public/blob/main/docs/TRANSLATION.md) before changing localized strings or adding build-generated assets.

== Changelog ==

= 0.1.0 =
* Added the local-first HealthLens dashboard and site-local lifecycle foundations.
