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

	// The verification job rides the async queue, which picks its
	// backend at request time: Action Scheduler when its API is
	// loaded, wp-cron otherwise. Both executors run here — whichever
	// holds the job, it fires; a backend that holds nothing just
	// errors and is absorbed.
	try {
		wpcli( 'wp action-scheduler run --group=greenpng' );
	} catch {
		wpcli( 'wp cron event run' );
	}

	const evidence = (): string => {
		try {
			return [
				`security-log=[${ wpcli( 'wp db query "SELECT id, rule_id, last_seen FROM wp_gr_security_logs ORDER BY id DESC LIMIT 3"' ).slice( 0, 240 ) }]`,
				`as=[${ wpcli( 'wp action-scheduler list --group=greenpng --fields=hook,status --format=csv' ).slice( 0, 160 ) }]`,
				`cron=[${ wpcli( 'wp cron event list --fields=hook --format=csv' ).slice( 0, 160 ) }]`,
			].join( ' ' );
		} catch ( e ) {
			return `diagnostics unavailable: ${ String( e ).slice( 0, 200 ) }`;
		}
	};

	await login( page );
	await page.goto( adminPage( slug.bot ) );
	await expect( page.locator( 'table' ).first(), evidence() ).toContainText( 'Googlebot' );
} );
