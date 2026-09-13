import { test, expect } from '@playwright/test';
import { login } from './helpers';

test( 'the privacy policy guide includes the greenpng section', async ( { page } ) => {
	await login( page );

	// WP 7.1 serves the guide from its own admin file; the settings
	// screen (options-privacy.php) shows a policy-page picker until
	// one is selected, and the legacy options-general.php URL answers
	// with an access-denied die.
	await page.goto( '/wp-admin/privacy-policy-guide.php' );

	// Each contributed section is an accordion titled with the plugin
	// name passed to wp_add_privacy_policy_content; ours is "greenpng".
	// Opening it makes the section text observable, and the click
	// itself proves the section exists.
	await page.getByRole( 'button', { name: 'greenpng' } ).click();
	await expect( page.locator( '.privacy-settings-accordion-panel' ) ).toContainText( 'anonymized IP addresses' );
} );
