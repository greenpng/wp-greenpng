# greenpng

Free, open-source, fully self-contained operations suite for independent WordPress site owners. Five domains in one plugin, with a strict **local-only** promise:

- **Traffic & security** — bot/automation detection, access rules, login protection, security logging with lawful-interest full-IP retention (masked in UI by default).
- **Marketing attribution** — first/last-touch attribution with consent gating, URL builder, campaign tracking.
- **Conversion funnels** — funnel definition and session progression.
- **Behavior & CRM scoring** — contacts, tags, scoring from on-site behavior.
- **Ecosystem integrations** — WooCommerce, Contact Form 7, WPForms, Fluent Forms, and more, hooked only through each target's public APIs/hooks.

## Principles

- **No calls home, ever.** The plugin never contacts any greenpng-run server: no telemetry, no version checks, no license checks. Outbound requests happen only when *you* enter third-party credentials and explicitly enable an integration (see `plugin/readme.txt`, `== External services ==`).
- **Free only.** No Pro tier, no license keys, no locked features.
- **Privacy dual-track.** Marketing data is IP-anonymized and consent-gated by default; security logs keep full IPs under legitimate interest with short retention, and both tracks implement the WordPress core privacy export/erase API.
- **WordPress-native UI**, no CDN assets, no bundled frameworks. Ships with zero Composer runtime dependencies.

## Requirements

PHP 7.4+ / WordPress 6.0+ / MySQL 5.7+ or MariaDB (upward compatible through PHP 8.5 and WordPress 7.1).

## Continuous integration

[![Unit · PHP matrix](https://github.com/greenpng/wp-greenpng/actions/workflows/unit-matrix.yml/badge.svg)](https://github.com/greenpng/wp-greenpng/actions/workflows/unit-matrix.yml)
[![Static · Security](https://github.com/greenpng/wp-greenpng/actions/workflows/static-security.yml/badge.svg)](https://github.com/greenpng/wp-greenpng/actions/workflows/static-security.yml)
[![WP integration](https://github.com/greenpng/wp-greenpng/actions/workflows/wp-integration.yml/badge.svg)](https://github.com/greenpng/wp-greenpng/actions/workflows/wp-integration.yml)
[![E2E · Playwright](https://github.com/greenpng/wp-greenpng/actions/workflows/e2e-playwright.yml/badge.svg)](https://github.com/greenpng/wp-greenpng/actions/workflows/e2e-playwright.yml)

Four suites run on every push, pull request, and manual dispatch (see `docs/adr/0008` for the full rationale):

| Suite | What it proves | Matrix |
| :--- | :--- | :--- |
| Unit | Plugin logic (stubbed WordPress API), 653 tests / 3,887 assertions | PHP 7.4 – 8.5 (8.4/8.5 experimental) |
| Static & security | WordPress coding standard, PHP 7.4 syntax compatibility, PHPStan level 6, JS component tests, secret scanning | 1 environment each |
| WP integration | Real WordPress in Docker (wp-env): schema install, dbDelta idempotency, collect REST API contract (401/400/413/200/rate-limit), activate/deactivate/reactivate lifecycle, uninstall in **both** data modes | PHP {8.1, 8.3} × WP {6.0, 7.1}; PHP 7.4 + MySQL 5.7 floor on its own compose workflow |
| E2E (Playwright) | Simulated customers in a real browser: first-time visitor, consent/DNT gating, crawler user-agent, collect API, admin settings, access rules, URL builder, Contact Form 7 conversion, WooCommerce order attribution, privacy policy registration | WP 7.1 + PHP 8.1 + WooCommerce + CF7 |

All CI runs on free GitHub-hosted Ubuntu runners (public repositories); peak concurrency stays below the 20-job free-tier cap, and no paid runners are used.

## Repository layout

```
plugin/                  the plugin itself (ships to WordPress.org)
tests/Unit/              PHPUnit suite (stubbed WP, no WordPress needed)
tests/integration/       REST/lifecycle/uninstall arms for the wp-env matrix
tests/e2e/               Playwright customer-simulation specs
tests/stubs/             in-repo description of the WP 6.0+ API surface
docs/                    architecture decisions and engineering specs (ADR + numbered docs)
.github/workflows/       the four CI suites
```

## Running tests locally

```bash
composer install                                   # dev tooling only
vendor/bin/phpunit                                 # unit suite
vendor/bin/phpcs --standard=phpcs.xml.dist         # WordPress coding standard
vendor/bin/phpcs --standard=phpcs-compat.xml.dist \
    --runtime-set testVersion 7.4- plugin/         # PHP 7.4 compatibility
node tests/js/gr-datagrid-test.js                  # JS component tests
```

The integration and E2E suites need Docker (`npx wp-env start`, see `.github/workflows/` for the exact invocations).

## License

GPL-2.0-or-later. The bundled DB-IP Lite database and CrawlerDetect list are dual-attributed per their own licenses (see `plugin/NOTICE` and `plugin/readme.txt`).
