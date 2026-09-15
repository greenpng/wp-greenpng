import { test, expect } from '@playwright/test';
import { login, slug, adminPage, wpcli } from './helpers';

test( 'the owner sees a captured contact across the list, tags, and RFM tabs', async ( { page } ) => {
	// The fixture rides the real capture path: hashed email plus an
	// encrypted envelope, one custom tag, a fixed score, and a segment
	// word from the engine's closed vocabulary.
	const out = wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php seed-contact' );
	expect( out, `seed-contact output: ${ out.slice( 0, 120 ) }` ).toMatch( /^contact \d+$/ );

	try {
		await login( page );

		// List tab: the table never shows plaintext — the mask keeps
		// first and last two characters only.
		await page.goto( adminPage( slug.contacts ) );
		await expect( page.locator( 'tbody' ) ).toContainText( 'E2E Contact' );
		await expect( page.locator( 'tbody' ) ).toContainText( 'e2****st' );
		await expect( page.locator( 'tbody' ) ).toContainText( '37' );
		await expect( page.locator( 'tbody' ) ).toContainText( 'champions' );

		// The email cell carries the inline entry into the profile.
		await page.getByRole( 'link', { name: 'Open profile' } ).first().click();
		await expect( page ).toHaveURL( /contact_id=\d+/ );

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
