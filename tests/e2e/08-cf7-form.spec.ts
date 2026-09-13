import { test, expect } from '@playwright/test';
import { dbCount, login, resetConsentFallback, saveSettings } from './helpers';

test( 'a customer submits the contact form and becomes a conversion', async ( { page, browser } ) => {
	await login( page );
	await saveSettings( page, 'general', { marketing_consent_fallback: true } );
	try {
		const visitor = await browser.newContext();
		const vpage = await visitor.newPage();

		// Land on the campaign URL first so the form conversion binds
		// to a campaign touchpoint.
		await vpage.goto( '/?utm_source=ci&utm_medium=e2e&utm_campaign=run' );
		await vpage.goto( '/contact' );

		await vpage.fill( '[name=your-name]', 'CI Customer' );
		await vpage.fill( '[name=your-email]', 'ci@example.test' );
		await vpage.locator( '.wpcf7-form input[type=submit]' ).click();
		await expect( vpage.locator( '.wpcf7-response-output' ) ).toContainText( /thank/i );

		await visitor.close();

		// The conversion is the v1.0 contract: the CRM contact row has
		// no writer yet (docs/13 V2 — the privacy exporter honestly
		// returns empty for contacts until CRM lands).
		expect( dbCount( 'wp_gr_conversions' ) ).toBeGreaterThanOrEqual( 1 );
	} finally {
		await resetConsentFallback( page );
	}
} );
