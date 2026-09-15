import { test, expect } from '@playwright/test';
import { adminPage, login, slug, wpcli } from './helpers';

test( 'the owner sees a captured contact across the list, tags, and RFM tabs', async ( { page } ) => {
	// The fixture rides the real capture path: hashed email plus an
	// encrypted envelope, one custom tag, a fixed score, and a segment
	// word from the engine's closed vocabulary. The seed answers with
	// the contact id, which the profile step needs.
	const out = wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php seed-contact' );
	const contactId = out.match( /^contact (\d+)$/ )?.[ 1 ] ?? '';
	expect( contactId, `seed-contact output: ${ out.slice( 0, 120 ) }` ).toMatch( /^\d+$/ );

	try {
		await login( page );

		// List tab: the table never shows plaintext — the mask keeps
		// first and last two characters only.
		await page.goto( adminPage( slug.contacts ) );
		await expect( page.locator( 'tbody' ) ).toContainText( 'E2E Contact' );
		await expect( page.locator( 'tbody' ) ).toContainText( 'e2****st' );
		await expect( page.locator( 'tbody' ) ).toContainText( '37' );
		await expect( page.locator( 'tbody' ) ).toContainText( 'champions' );

		// The profile opens directly at its slug: the list's inline
		// "Open profile" action is a hover-revealed row action, so the
		// id (not the link) is the stable entry.
		await page.goto( adminPage( slug.contactsProfile, `&contact_id=${ contactId }` ) );
		await expect( page.getByRole( 'heading', { name: 'Contact Profile' } ) ).toBeVisible();
		await expect( page.locator( 'body' ) ).toContainText( 'E2E Contact' );

		// Tags tab: the fixture tag shows as Custom with its count.
		await page.goto( adminPage( slug.contacts, '&tab=tags' ) );
		await expect( page.locator( 'tbody' ) ).toContainText( 'e2e-tag' );
		await expect( page.locator( 'tbody' ) ).toContainText( 'Custom' );

		// RFM tab: the segment population table carries the word.
		await page.goto( adminPage( slug.contacts, '&tab=rfm' ) );
		await expect( page.locator( 'tbody' ) ).toContainText( 'champions' );
	} finally {
		wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php purge-contact' );
	}
} );
