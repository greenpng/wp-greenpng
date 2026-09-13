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

	// The verification job rides the async queue: WooCommerce provides
	// Action Scheduler here, so wp-cron holds nothing and the queue's
	// group runs through AS's own executor.
	wpcli( 'wp action-scheduler run --group=greenpng' );

	await login( page );
	await page.goto( adminPage( slug.bot ) );
	await expect( page.locator( 'table' ).first() ).toContainText( 'Googlebot' );
} );
