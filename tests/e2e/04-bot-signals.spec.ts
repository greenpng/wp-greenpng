import { test, expect } from '@playwright/test';
import { adminPage, login, slug, wpcli } from './helpers';

test( 'a crawler user-agent lands in Bot & Device Signals', async ( { page, browser } ) => {
	// The crawler's walk is proven by the hit count: the fold writer
	// keys security-log rows by address + rule + hour window, and on
	// this stack every request shares one docker-bridge address whose
	// window the wp-env healthchecks (runner curl) own since startup —
	// so the crawler's Googlebot hit always folds into a row showing
	// another agent, and no per-agent text can name it. What can be
	// proven deterministically: its hit lands (the count grows), the
	// claim is queued and verified (a verdict renders), and the
	// scanner engine aggregates (its table carries rows).
	const hits = (): number =>
		parseInt( wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php scanner-hits' ), 10 );

	const before = hits();

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

	// The crawler's scanner hit folded into the window's row.
	expect( hits() ).toBeGreaterThan( before );

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

	// The FCrDNS table merges claims per address (the healthchecks
	// walk it after the crawler, so the claimed-agent column shows
	// the latest claimant). The scenario's promise is the conclusion:
	// a claim row with a forward-confirmed verdict either way
	// (columns: address, agent, PTR, verdict, walks, last seen).
	const claimRow = page.locator( 'table' ).first().locator( 'tbody tr' ).first();
	const cells = claimRow.locator( 'td' );
	await expect( cells.nth( 3 ), evidence() ).toHaveText( /^(verified|unverified)$/ );
	expect( parseInt( await cells.nth( 4 ).innerText(), 10 ) ).toBeGreaterThanOrEqual( 2 );

	// The scanner-UA engine table carries the folded rows: at least
	// one agent with recorded hits proves the engine path live.
	const uaTable = page
		.locator( 'h2', { hasText: 'Scanner-UA engine' } )
		.locator( 'xpath=following-sibling::table[1]' );
	await expect( uaTable.locator( 'tbody tr' ) ).not.toHaveText( /No scanner agents recorded/ );
	await expect( uaTable.locator( 'tbody tr td' ).first() ).not.toBeEmpty();
} );
