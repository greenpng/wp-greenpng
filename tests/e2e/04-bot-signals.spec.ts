import { test, expect } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'a crawler user-agent lands in Bot & Device Signals', async ( { page, browser } ) => {
	const crawler = await browser.newContext( {
		userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	} );
	const cpage = await crawler.newPage();
	await cpage.goto( '/' );
	await cpage.waitForTimeout( 1500 ); // The claim row is written server-side on the request.
	await crawler.close();

	await login( page );
	await page.goto( adminPage( slug.bot ) );
	await expect( page.locator( 'table' ).first() ).toContainText( 'Googlebot' );
} );
