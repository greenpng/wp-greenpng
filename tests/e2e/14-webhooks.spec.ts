import { test, expect } from '@playwright/test';
import { login, slug, adminPage } from './helpers';

test( 'the owner registers a webhook endpoint and the page refuses http', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.webhooks ) );

	// The receiver verification contract is part of the page: the four
	// header names and the constant-time comparison one-liner.
	await expect( page.getByText( 'X-Gr-Signature' ).first() ).toBeVisible();
	await expect( page.getByText( 'hash_equals' ).first() ).toBeVisible();

	// http is refused at storage: the signature would be meaningless
	// over the wire. The refusal notice leaves no row behind.
	await page.fill( '#gr_wh_url', 'http://hooks.e2e.test/receive' );
	await page.fill( '#gr_wh_secret', 'e2e-secret-0123456789abcdefg' );
	await page.locator( 'input[name="gr_wh_events[]"]' ).first().check();
	await page.locator( 'button[value="add"]' ).click();
	await expect( page.locator( '.notice' ) ).toContainText( 'https' );
	await expect( page.locator( 'tbody' ) ).not.toContainText( 'hooks.e2e.test' );

	// The https twin passes: the endpoint table shows the host and the
	// masked secret, never the secret itself.
	await page.fill( '#gr_wh_url', 'https://hooks.e2e.test/receive' );
	await page.fill( '#gr_wh_secret', 'e2e-secret-0123456789abcdefg' );
	await page.locator( 'input[name="gr_wh_events[]"]' ).first().check();
	await page.locator( 'button[value="add"]' ).click();
	await expect( page.locator( 'tbody' ) ).toContainText( 'hooks.e2e.test' );
	await expect( page.locator( 'tbody' ) ).toContainText( 'e2****fg' );
	expect( await page.locator( 'tbody' ).textContent() ).not.toContain( 'e2e-secret-0123456789abcdefg' );

	// The status page carries the endpoint's delivery state row.
	await page.goto( adminPage( slug.status ) );
	await expect( page.locator( 'table' ) ).toContainText( 'hooks.e2e.test' );

	// Pause flips the state word the owner sees on the Webhooks page,
	// and delete returns the page to its empty state.
	await page.goto( adminPage( slug.webhooks ) );
	await page.locator( 'button[value="toggle"]' ).first().click();
	await expect( page.locator( 'tbody' ) ).toContainText( 'Paused' );

	await page.locator( 'button[value="delete"]' ).first().click();
	await expect( page.getByText( 'No endpoints configured yet' ) ).toBeVisible();
} );
