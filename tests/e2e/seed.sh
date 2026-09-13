#!/usr/bin/env bash
# E2E environment seed (ADR-0008): one wp-env site at :8888 with the
# plugin, WooCommerce, and Contact Form 7. Creates the fixtures the
# customer scenarios need: pretty permalinks and a published CF7 form
# page at /contact. Idempotent — safe on workflow retries.
set -euo pipefail

cli() { npx wp-env run cli "$@" 2>/dev/null; }

echo "== Activating plugins"
cli wp plugin activate greenpng woocommerce contact-form-7

echo "== Take the store out of coming-soon mode"
# A fresh WooCommerce parks the front end behind a "Store coming soon"
# placeholder that replaces the real site for logged-out visitors,
# which is every simulated customer in this suite.
cli wp option update woocommerce_coming_soon no >/dev/null 2>&1 || true
cli wp option update woocommerce_store_pages_only no >/dev/null 2>&1 || true

echo "== Pretty permalinks"
cli wp rewrite structure '/%postname%/' --hard >/dev/null
cli wp rewrite flush >/dev/null

echo "== Contact Form 7 form + page"
# The form's template rides inside ci-seed.php as a PHP literal: the
# wp-env argument layer mangles bracketed multi-word values (the
# template arrived empty and rendered a fieldless form), while plain
# paths always arrive intact, so the payload never crosses a shell.
cli wp eval-file wp-content/plugins/greenpng/ci-seed.php seed-contact-form

echo "== Seed complete"
