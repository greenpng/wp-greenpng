import { test, expect } from '@playwright/test';
import { login, saveSettings } from './helpers';

test( 'the probe switch reflects immediately on the front page', async ( { page } ) => {
	await login( page );

	await saveSettings( page, 'security', { probe_enabled: false } );
	await page.goto( '/' );
	await expect( page.locator( 'script', { hasText: 'GreenPNGProbe' } ) ).toHaveCount( 0 );

	await saveSettings( page, 'security', { probe_enabled: true } );
	await page.goto( '/' );
	await expect( page.locator( 'script', { hasText: 'GreenPNGProbe' } ) ).toHaveCount( 1 );
} );
