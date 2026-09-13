import { test, expect } from '@playwright/test';
import { COOKIES, login, resetConsentFallback } from './helpers';

test( 'a first-time visitor sees the probe but sets no marketing cookies without consent', async ( { page, browser } ) => {
	// Precondition: the shipped privacy default (marketing consent
	// never implied). The spec sets it explicitly so it cannot depend
	// on which spec ran before it.
	await login( page );
	await resetConsentFallback( page );

	const visitor = await browser.newContext();
	const vpage = await visitor.newPage();
	await vpage.goto( '/' );

	await expect( vpage.locator( 'script', { hasText: 'GreenPNGProbe' } ) ).toHaveCount( 1 );

	const names = ( await visitor.cookies() ).map( ( c ) => c.name );
	expect( names ).not.toContain( COOKIES.attr );
	expect( names ).not.toContain( COOKIES.session );

	await visitor.close();
} );
