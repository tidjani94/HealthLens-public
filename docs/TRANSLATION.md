# Translation and generated assets

HealthLens uses `healthlens` as its canonical plugin slug and text domain. Keep the slug and text domain lowercase, use hyphens only if the slug changes, and do not introduce a second domain for the same plugin.

The `Version` field in `healthlens.php` is the release version source. The package builder derives its archive filename from that field, while `readme.txt` `Stable tag` and `HEALTHLENS_VERSION` are checked against it.

## Source strings

- Every user-facing PHP string must use a WordPress internationalization function with the literal `healthlens` domain.
- Add a translator comment when a placeholder, technical term, or context is not obvious.
- Keep placeholders in translated strings and pass dynamic values through the appropriate escaping or formatting function.
- Escape translated output for its final HTML or attribute context. Do not concatenate translated sentence fragments.
- Review dashboard headings, status labels, recommendations, settings labels, notices, accessible names, and plugin metadata for meaning and length after translation.
- The current JavaScript and CSS assets contain no user-facing translatable strings.

## WordPress.org language packs

The plugin header declares `Text Domain: healthlens`. WordPress.org language packs are the canonical distribution path for a hosted release. No bundled `languages/` directory or runtime text-domain loader is currently required; if bundled translations are added later, document the source files, loading path, and release inclusion explicitly.

## Generated and minified assets

The distributed assets are currently readable source files and do not include minified files or source maps. If a build step later produces minified JavaScript or CSS:

1. Commit the readable source and the build instructions used to reproduce the asset.
2. Keep source maps or another public source path available when the generated asset is distributed.
3. Ensure generated files contain no untranslated user-facing strings and that the source strings remain discoverable by WordPress tooling.
4. Include only the production asset and its documented source/provenance in the release archive.

## Reproducible checks

From the repository root, run:

```text
composer run readme:audit
composer run dashboard:audit
composer run phpcs
```

`readme:audit` verifies the public metadata contract, required support/privacy sections, version alignment, tag count, short-description length, and absence of stale foundation-only wording. `dashboard:audit` verifies the current PHP translation calls use `healthlens`. The full release gate is `composer run check`.
