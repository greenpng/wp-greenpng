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
	// name passed to wp_add_privacy_policy_content; ours is "greenpng"
	// exactly — a substring match also hits WP's own "Copy suggested
	// policy text" button once the section opens.
	const trigger = page.getByRole( 'button', { name: 'greenpng', exact: true } );
	await trigger.click();
	const panelId = await trigger.getAttribute( 'aria-controls' );
	await expect( page.locator( `#${ panelId }` ) ).toContainText( 'anonymized IP addresses' );
} );
