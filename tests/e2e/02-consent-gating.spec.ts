import { test, expect } from '@playwright/test';
import { COOKIES, dbCount, login, resetConsentFallback, saveSettings } from './helpers';

test( 'the consent fallback grants marketing cookies and records the session', async ( { page, browser } ) => {
	await login( page );
	await saveSettings( page, 'general', { marketing_consent_fallback: true } );
	try {
		const visitor = await browser.newContext();
		const vpage = await visitor.newPage();
		await vpage.goto( '/?utm_source=ci&utm_medium=e2e&utm_campaign=run' );

		const names = ( await visitor.cookies() ).map( ( c ) => c.name );
		expect( names ).toContain( COOKIES.attr );
		expect( names ).toContain( COOKIES.session );

		expect( dbCount( 'wp_gr_sessions' ) ).toBeGreaterThanOrEqual( 1 );
		expect( dbCount( 'wp_gr_touchpoints' ) ).toBeGreaterThanOrEqual( 1 );

		await visitor.close();
	} finally {
		await resetConsentFallback( page );
	}
} );

test( 'a DNT visitor is not tracked even with the consent fallback on', async ( { page, browser } ) => {
	await login( page );
	await saveSettings( page, 'general', { marketing_consent_fallback: true } );
	try {
		const visitor = await browser.newContext( { extraHTTPHeaders: { DNT: '1' } } );
		const vpage = await visitor.newPage();
		await vpage.goto( '/' );

		const names = ( await visitor.cookies() ).map( ( c ) => c.name );
		expect( names ).not.toContain( COOKIES.attr );
		expect( names ).not.toContain( COOKIES.session );

		await visitor.close();
	} finally {
		await resetConsentFallback( page );
	}
} );
