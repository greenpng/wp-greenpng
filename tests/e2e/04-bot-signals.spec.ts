import { test, expect } from '@playwright/test';
import { adminPage, login, slug, wpcli } from './helpers';

test( 'a crawler user-agent lands in Bot & Device Signals', async ( { page, browser } ) => {
	const crawler = await browser.newContext( {
		userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	} );
	const cpage = await crawler.newPage();
	await cpage.goto( '/' );
	await cpage.waitForTimeout( 1500 ); // The claim is queued server-side on the request.
	await crawler.close();

	// The verification job is queued through the local queue (wp-cron
	// in this environment); CI runners never idle long enough for cron
	// to fire on its own, so the due event runs on demand.
	wpcli( 'wp cron event run gr_crawler_verify' );

	await login( page );
	await page.goto( adminPage( slug.bot ) );
	await expect( page.locator( 'table' ).first() ).toContainText( 'Googlebot' );
} );
