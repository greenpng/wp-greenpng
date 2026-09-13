import { test, expect } from '@playwright/test';
import { login } from './helpers';

test( 'the privacy policy guide includes the greenpng section', async ( { page } ) => {
	await login( page );

	// WP 7.1 serves the guide from the Privacy settings screen; the
	// legacy options-general.php?page=privacy-policy-guide URL is gone
	// and answers with an access-denied die.
	await page.goto( '/wp-admin/options-privacy.php' );

	// Each contributed section is an accordion; opening ours makes its
	// text observable, and the click itself proves the section exists.
	await page.getByRole( 'button', { name: /greenpng analytics/i } ).click();
	await expect( page.locator( '.privacy-settings-accordion-panel' ) ).toContainText( 'anonymized IP addresses' );
} );
