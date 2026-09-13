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
	const runQueue = (): void => {
		try {
			wpcli( 'wp action-scheduler run --group=greenpng' );
		} catch {
			try {
				wpcli( 'wp cron event run --due-now' );
			} catch {
				// The assertion's evidence block reports the queues.
			}
		}
	};
	runQueue();

	// Evidence pieces are independent: one failing queue view must
	// not hide the others, and the security log answers the deciding
	// question — did the scanner finding fire at all?
	const piece = ( label: string, cmd: string ): string => {
		try {
			return `${ label }=[${ wpcli( cmd ).slice( 0, 240 ) }]`;
		} catch ( e ) {
			return `${ label }=failed:${ String( e ).split( '\n' ).slice( -2 ).join( ' ' ).slice( 0, 200 ) }`;
		}
	};
	const evidence = (): string => [
		piece( 'security-log', 'wp db query "SELECT id, rule_id, last_seen FROM wp_gr_security_logs ORDER BY id DESC LIMIT 4"' ),
		piece( 'as-actions', 'wp action-scheduler list --fields=hook,status,group --format=csv' ),
		piece( 'cron', 'wp cron event list --fields=hook --format=csv' ),
		piece( 'fcrdns-transients', 'wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE \'%fcrdns%\'"' ),
		piece( 'settings', 'wp option get gr_settings --format=json' ),
	].join( ' ' );

	await login( page );
	await page.goto( adminPage( slug.bot ) );
	await expect( page.locator( 'table' ).first(), evidence() ).toContainText( 'Googlebot' );
} );
