import { test, expect } from '@playwright/test';
import { adminPage, login, probePresent, saveSettings, slug } from './helpers';

test( 'the probe switch reflects immediately on the front page', async ( { page } ) => {
	await login( page );

	await saveSettings( page, 'security', { probe_enabled: false } );
	await page.goto( '/' );
	expect( await probePresent( page ) ).toBe( false );

	await saveSettings( page, 'security', { probe_enabled: true } );
	await page.goto( '/' );
	expect( await probePresent( page ) ).toBe( true );
} );

test( 'the engine dials save from the security tab', async ( { page } ) => {
	// The G1 gap closed: the verdict threshold went from a settings
	// key with no writer to a dial that survives a save round-trip.
	await login( page );

	await page.goto( adminPage( slug.settings, '&tab=security' ) );
	await page.locator( 'input[name="bot_verdict_threshold"]' ).fill( '90' );
	await page.click( 'p.submit input[type=submit]' );
	await page.waitForURL( /gr_saved=1/ );

	// The PRG re-render shows the saved value, not the default.
	await page.goto( adminPage( slug.settings, '&tab=security' ) );
	await expect( page.locator( 'input[name="bot_verdict_threshold"]' ) ).toHaveValue( '90' );

	// Back to the default so later specs start from the recorded
	// baseline.
	await page.locator( 'input[name="bot_verdict_threshold"]' ).fill( '70' );
	await page.click( 'p.submit input[type=submit]' );
	await page.waitForURL( /gr_saved=1/ );
} );
