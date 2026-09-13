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

	// The FCrDNS table merges claims per address, and every request in
	// this stack shares one docker-bridge IP: the runner's own curl
	// probes walk that address after the crawler, so the claimed-agent
	// column shows the latest claimant, not the crawler. What the
	// scenario promises is the conclusion — a claim row with a
	// forward-confirmed verdict either way (columns: address, agent,
	// PTR, verdict, walks, last seen).
	const claimRow = page.locator( 'table' ).first().locator( 'tbody tr' ).first();
	const cells = claimRow.locator( 'td' );
	await expect( cells.nth( 3 ), evidence() ).toHaveText( /^(verified|unverified)$/ );
	expect( parseInt( await cells.nth( 4 ).innerText(), 10 ) ).toBeGreaterThanOrEqual( 1 );

	// The crawler's own walk stays separately visible: the
	// scanner-UA engine table lists one row per agent, immune to the
	// shared-address merge.
	await expect(
		page.locator( 'h2', { hasText: 'Scanner-UA engine' } ).locator( 'xpath=following-sibling::table[1]' )
	).toContainText( 'Googlebot' );
} );
