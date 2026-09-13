import { test, expect } from '@playwright/test';
import { login, probePresent, saveSettings } from './helpers';

test( 'the probe switch reflects immediately on the front page', async ( { page } ) => {
	await login( page );

	await saveSettings( page, 'security', { probe_enabled: false } );
	await page.goto( '/' );
	expect( await probePresent( page ) ).toBe( false );

	await saveSettings( page, 'security', { probe_enabled: true } );
	await page.goto( '/' );
	expect( await probePresent( page ) ).toBe( true );
} );
