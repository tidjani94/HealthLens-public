# Release-candidate gates

M10-03 defines the minimum evidence required before a HealthLens release candidate can be promoted. GitHub Issues remain authoritative for remediation ownership; this document defines the gate contract and the evidence recorded in the release PR and workflow artifacts.

## Blocking policy

- Any failed required job blocks the candidate. A green workflow is not inferred from a partial matrix or a skipped job.
- Plugin Check errors are always blocking. Plugin Check warnings are blocking when they are new, changed, or absent from the reviewed baseline in `docs/PLUGIN-CHECK-BASELINE.md`.
- New PHPCS, PHPStan, compatibility, security, privacy, accessibility, or multisite findings are blocking until the exact remediation issue and passing evidence are linked.
- An unavailable required environment is a failed gate, not an approval to continue with a local-only substitute.
- Existing findings are recorded separately from regressions. A baseline entry must identify the file/code, rationale, owner, and follow-up condition.

## Required jobs and artifacts

| Gate | Required environment/artifact | Minimum pass condition | Blocking findings |
| --- | --- | --- | --- |
| Compatibility | GitHub Actions; PHP 7.4, 8.3, and 8.5 | Composer validates, production dependencies install, and `bin/lint.php` passes for every matrix entry | Any failed PHP or lint matrix entry |
| Static analysis and unit tests | GitHub Actions; PHP 8.3; repository source | PHPCS, PHPStan, and all unit tests pass without a blanket baseline | Any error, new warning, or test failure |
| Release boundary audit | `composer run release:gate`; `release-gate.json` artifact | Metadata, capability/nonce, privacy/default-off, outbound transport, remote-code, accessibility, multisite, environment, and evidence checks pass | Any failed boundary check |
| Integration smoke | GitHub Actions; wp-env WordPress 7.0.4 / PHP 8.3 | Fresh environment starts, activation/lifecycle smoke passes, and cleanup succeeds | Environment failure, activation failure, notice/fatal, or lifecycle cleanup failure |
| Production package | Versioned `dist/healthlens-<version>.zip` | Two builds have identical SHA-256, package audit passes, one `healthlens/` root exists, and development files are absent | Archive mismatch, missing manifest/runtime file, or repository-only file |
| Public source mirror | Private `main` sync workflow, public-repository artifact, and public-repository CI | Allowlisted export, private-link scan, canonical metadata/link audit, and public synchronization PR all pass | Missing configuration, private URL, unsafe file, export mismatch, or failed public CI |
| Plugin Check | The unpacked production archive, not the repository root; official action report audited by `bin/plugin-check-audit.php` | Plugin Check has no errors; warnings are either absent or explicitly matched by code and source file in `docs/PLUGIN-CHECK-BASELINE.json` | Any error, malformed report, or untriaged/new warning |
| Release artifact retention | GitHub release workflow | The audited versioned ZIP and release-gate report are uploaded from the same commit | Missing, mismatched, or untraceable artifact |

`composer run release:gate -- --output=release-gate.json` produces a machine-readable report. The report is source-boundary evidence; the package audit and Plugin Check jobs remain the authority for the unpacked production archive.

## Security, privacy, accessibility, and multisite evidence

The release gate checks the source boundary automatically. The integration smoke and release record provide runtime evidence:

- Security: admin screens require `manage_options`; settings use the Settings API, sanitizer, and generated nonce fields; unauthorized dashboard access is denied.
- Privacy: retention, error capture, notifications, and the optional gateway default off; dashboard rendering does not dispatch checks; current-site probes are host-allowlisted and bounded; optional gateway transport requires explicit consent, a fixed approved HTTPS host, and minimized payloads; no telemetry or remote executable asset is present.
- Accessibility: the dashboard has one labelled main landmark, readable headings/status text, `aria-live` status, native `<details>` controls, and no JavaScript dependency for primary content. The release record must include keyboard and screen-reader review notes.
- Multisite: network activation is rejected before writes, settings remain site-local, and no network-admin or cross-site switching API is introduced. The release record must include the per-site activation policy review.

Manual keyboard, screen-reader, locale, privacy, and multisite observations must include the review date, environment, scenario, result, and any linked remediation issue. The existing scenarios are documented in `docs/UI-UX.md`, `docs/PRIVACY.md`, and `docs/SECURITY.md`.

## Reproducible commands

```text
composer validate --strict
composer run check
composer run release:version
composer run release:gate -- --output=release-gate.json
npm ci
npm run env:start
npm run test:integration
npm run env:stop
composer package
composer run package:audit -- dist/healthlens-<version>.zip
composer run public:export -- --output=TEMP_DIR --repository-url=PUBLIC_URL --private-repository-url=PRIVATE_URL
composer run public:link-audit -- --root=TEMP_DIR --repository-url=PUBLIC_URL --private-repository-url=PRIVATE_URL
```

The CI workflows run the required commands with clean checkout state. No gate may be weakened or blanket-ignored to obtain a green result.
