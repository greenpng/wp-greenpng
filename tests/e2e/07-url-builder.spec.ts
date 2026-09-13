import { test, expect } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'the URL builder composes campaign links locally', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.url ) );

	await page.fill( '#gr-url-landing', 'https://example.test/landing' );
	await page.fill( '#gr-url-source', 'newsletter' );
	await page.fill( '#gr-url-medium', 'email' );
	await page.fill( '#gr-url-campaign', 'summer' );
	await page.getByRole( 'button', { name: 'Build link' } ).click();

	const built = page.locator( 'input[readonly]' );
	await expect( built ).toHaveValue( /utm_source=newsletter/ );
	await expect( built ).toHaveValue( /utm_medium=email/ );
	await expect( built ).toHaveValue( /utm_campaign=summer/ );
	await expect( page.locator( 'code' ).first() ).toBeVisible();

	// Incomplete trio: guidance notice, and no link is offered.
	await page.goto( adminPage( slug.url ) );
	await page.fill( '#gr-url-landing', 'https://example.test/landing' );
	await page.fill( '#gr-url-source', 'solo' );
	await page.getByRole( 'button', { name: 'Build link' } ).click();
	await expect( page.locator( '.notice-error' ) ).toBeVisible();
	await expect( page.locator( 'input[readonly]' ) ).toHaveCount( 0 );
} );
