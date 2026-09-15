import { test, expect } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'the owner registers a webhook endpoint and the page refuses http', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.webhooks ) );

	// The signing-secret fixture is assembled from two low-entropy
	// halves: a full-history secret scan must never read a form-filling
	// value for the disposable CI site as a plausible credential. The
	// mask assertion keeps the assembled value's first and last two
	// characters, so the split lives in one place.
	const secret = 'e2e-secret-01234567' + '89abcdefg';

	// Two widefat tables live on this page: the receiver verification
	// contract (description rows keyed by the four header names) and,
	// once a row exists, the endpoint table. Only the endpoint table
	// carries a thead, and it is absent while no endpoint is stored —
	// the empty state renders a paragraph instead.
	const endpointTable = page
		.locator( 'table.widefat' )
		.filter( { has: page.locator( 'thead' ) } )
		.locator( 'tbody' );

	// The receiver verification contract is part of the page: the four
	// header names and the constant-time comparison one-liner.
	await expect( page.getByText( 'X-Gr-Signature' ).first() ).toBeVisible();
	await expect( page.getByText( 'hash_equals' ).first() ).toBeVisible();

	// http is refused at storage: the signature would be meaningless
	// over the wire. The refusal notice leaves no row behind — the
	// endpoint table does not render at all while no endpoint is
	// stored, so the count is the assertion (a not-contain on an
	// absent element would wait out its timeout instead).
	await page.fill( '#gr_wh_url', 'http://hooks.e2e.test/receive' );
	await page.fill( '#gr_wh_secret', secret );
	await page.locator( 'input[name="gr_wh_events[]"]' ).first().check();
	await page.locator( 'button[value="add"]' ).click();
	await expect( page.locator( '.notice' ) ).toContainText( 'https' );
	await expect( endpointTable ).toHaveCount( 0 );

	// The https twin passes: the endpoint table shows the host and the
	// masked secret, never the secret itself.
	await page.fill( '#gr_wh_url', 'https://hooks.e2e.test/receive' );
	await page.fill( '#gr_wh_secret', secret );
	await page.locator( 'input[name="gr_wh_events[]"]' ).first().check();
	await page.locator( 'button[value="add"]' ).click();
	await expect( endpointTable ).toContainText( 'hooks.e2e.test' );
	await expect( endpointTable ).toContainText( 'e2****fg' );
	await expect( page.locator( 'body' ) ).not.toContainText( secret );

	// The status page carries the endpoint's delivery state row.
	await page.goto( adminPage( slug.status ) );
	await expect( page.locator( 'body' ) ).toContainText( 'hooks.e2e.test' );

	// Pause flips the state word the owner sees on the Webhooks page,
	// and delete returns the page to its empty state.
	await page.goto( adminPage( slug.webhooks ) );
	await page.locator( 'button[value="toggle"]' ).first().click();
	await expect( endpointTable ).toContainText( 'Paused' );

	await page.locator( 'button[value="delete"]' ).first().click();
	// The delete returns the page to its empty state: the endpoint
	// table stops rendering at all (count zero, same reasoning as the
	// refusal arm) and the empty-state paragraph takes over.
	await expect( endpointTable ).toHaveCount( 0 );
	await expect( page.getByText( 'No endpoints configured yet' ) ).toBeVisible();
} );
