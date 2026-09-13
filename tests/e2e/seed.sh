#!/usr/bin/env bash
# E2E environment seed (ADR-0008): one wp-env site at :8888 with the
# plugin, WooCommerce, and Contact Form 7. Creates the fixtures the
# customer scenarios need: pretty permalinks and a published CF7 form
# page at /contact. Idempotent — safe on workflow retries.
set -euo pipefail

cli() { npx wp-env run cli "$@" 2>/dev/null; }

echo "== Activating plugins"
cli wp plugin activate greenpng woocommerce contact-form-7

echo "== Pretty permalinks"
cli wp rewrite structure '/%postname%/' --hard >/dev/null
cli wp rewrite flush >/dev/null

echo "== Contact Form 7 form + page"
PAGE_ID="$( cli wp post list --post_type=page --name=contact --field=ID )"
if [ -z "$PAGE_ID" ]; then
    FORM_ID="$( cli wp post create \
        --post_type=wpcf7_contact_form \
        --post_status=publish \
        --post_title='Contact' \
        --post_content='[text* your-name] [email* your-email] [submit "Send"]' \
        --porcelain )"
    cli wp post create \
        --post_type=page \
        --post_status=publish \
        --post_title='Contact us' \
        --post_name=contact \
        --post_content="[contact-form-7 id=\"${FORM_ID}\"]" \
        >/dev/null
    echo "   created form ${FORM_ID} + page /contact"
else
    echo "   page /contact already exists (${PAGE_ID})"
fi

echo "== Seed complete"
