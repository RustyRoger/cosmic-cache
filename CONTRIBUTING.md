# Contributing to Cosmic Cache

Thanks for considering a contribution. This guide keeps the process simple and
predictable for both first-time and regular contributors.

## Reporting bugs

Open an issue with:

- Plugin version and WordPress version.
- PHP version and web server (Apache/Nginx).
- Steps to reproduce, ideally with a minimal site setup.
- Expected vs actual behaviour, including the `debug.log` lines from the
  plugin if debug logging is enabled.

## Security issues

Do **not** open a public issue for security problems. See
[SECURITY.md](SECURITY.md) for the reporting process.

## Local development

Prerequisites: PHP 8.0+, Composer.

```bash
composer install                # dev dependencies only
composer run lint               # php -l over src/dropins/bootstrap
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse      # level 5
vendor/bin/phpcs                # hygiene gate
```

### Integration tests

A `wordpress:php8.4-apache` + MariaDB + Redis stack runs the plugin against a
live install (brotli/APCu/Redis and wp-cli included):

```bash
cd tests/container
podman-compose up -d --build   # wordpress on http://localhost:18080
bash tests/integration/smoke.sh container_wordpress_1
bash tests/integration/extended.sh container_wordpress_1
bash tests/integration/deactivate.sh container_wordpress_1
bash tests/integration/sendfile.sh container_wordpress_1
```

See the README "Container integration test" section for details.

## Coding standards

- Follow the existing style: WordPress-ish formatting with Allman braces and
  spaced calls. The PHPCS config checks mechanical hygiene only (whitespace,
  BOM, EOF newline, lowercase keywords, inline control structures).
- No inline comments unless they clarify a non-obvious decision.
- Keep the hot path (drop-in classes compiled into `advanced-cache.php`)
  dependency-free: no WordPress API calls on the serve path.
- New functionality must keep the **fail-open** guarantee and, unless it is
  clearly opt-in, never rewrite HTML by default.

## Tests

- Every bug fix must add or update a regression test (unit and/or
  integration).
- Run the full unit suite and PHPStan before opening a PR.
- Integration tests must pass against the live container (or CI).

## Commit convention

Use conventional-commit style prefixes: `feat:`, `fix:`, `docs:`, `test:`,
`chore:`, `build:`, `perf:`.

## Pull requests

- Small, focused PRs are preferred; one logical change per PR.
- Reference the issue you fix (e.g. `Fixes #12`).
- Keep the README, `readme.txt` and `CHANGELOG.md` in sync with user-facing
  changes.

## WordPress.org releases

- Keep the plugin header (`cosmic-cache.php`), `readme.txt` Stable tag and
  the `CHANGELOG.md` section in sync — the `guard` job blocks mismatched tags.
- `readme.txt` Tags must stay at 5 or fewer (directory anti-spam rule).
- Release: `git tag -a vX.Y.Z -m "..." && git push origin main vX.Y.Z`.
  The `release` workflow publishes the GitHub Release; the
  `deploy-wporg` workflow mirrors the tag to the wordpress.org SVN
  (needs `SVN_USERNAME`/`SVN_PASSWORD` secrets). Store banners/icons live in
  `.wordpress-org/` and are synced by the deploy and `wporg-assets` workflows.
