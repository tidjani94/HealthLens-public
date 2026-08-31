# Production package provenance

The production archive is built by `build/package.php`. It always has one `healthlens/` root, derives its version from the `Version` field in `healthlens.php`, installs Composer dependencies with `--no-dev`, sorts archive entries lexicographically, and applies the fixed `SOURCE_DATE_EPOCH` value `946684800` to archive entries.

Each archive contains `healthlens/PROVENANCE.json`. The manifest records the plugin identity, version, canonical public source repository/commit when supplied by CI, Composer lock hash, production dependency licenses and source references, channel expectations, and SHA-256/byte-count/license/source evidence for every other distributed file. Set `HEALTHLENS_CANONICAL_REPOSITORY_URL` to the public repository URL for release builds. The manifest records its own license and generator but intentionally omits its own hash to avoid a self-referential value.

## Channel contract

GitHub Release artifacts, CI-uploaded release artifacts, and the eventual WordPress.org SVN `trunk`/`tags/<version>` tree must be produced from the same production staging tree. A source-code archive is not an installable release artifact. Before promotion, compare the archive SHA-256 and inspect `PROVENANCE.json`; any channel-specific wrapper or filename difference must be recorded without changing the plugin root or runtime contents.

There is no WordPress.org SVN repository or GitHub release for HealthLens yet. This document defines the contract; it does not claim directory approval or release publication.

## Reproducible verification

From a clean checkout:

```text
composer install --no-interaction --no-progress --prefer-dist
composer package
composer run package:audit -- dist/healthlens-<version>.zip
```

Build twice and compare the resulting SHA-256 values. The release and quality workflows perform this comparison, run the package audit, unpack the archive, and run the official Plugin Check action against the unpacked `healthlens/` root.
