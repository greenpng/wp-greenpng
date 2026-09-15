import { test, expect } from '@playwright/test';
import { adminPage, login, slug, wpcli } from './helpers';

test( 'behavior events land on the engagement and friction tabs', async ( { page } ) => {
	// Four events ride the bus with the collect endpoint's inner
	// payload shapes, then the day's aggregation runs so the KPI
	// tiles answer without waiting for the nightly pass.
	const out = wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php seed-behavior' );
	expect( out, `seed-behavior output: ${ out.slice( 0, 120 ) }` ).toMatch( /^aggregated \d+$/ );

	try {
		await login( page );

		// Engagement tab: dwell and scroll tiles have their fixture
		// counts. Each tab renders two drill-down tables, so the row
		// assertions scope to the tbody that carries the fixture
		// rather than to "the" tbody.
		await page.goto( adminPage( slug.behavior ) );
		await expect( page.getByText( 'Dwell events, last 30 days' ) ).toBeVisible();
		// The fixture is the only behavior source on the CI site, but
		// the tile assertion stays count-shaped rather than exact so a
		// parallel signal can only ever inflate it, never break it.
		await expect(
			page.locator( '.postbox', { hasText: 'Dwell events, last 30 days' } ).locator( '.gr-kpi-value' )
		).not.toHaveText( '0' );
		await expect(
			page.locator( 'tbody' ).filter( { hasText: '60-180' } )
		).toHaveCount( 1 );

		// Friction tab: the rage and dead tiles plus the structural
		// locators — tag plus id/class, never text or coordinates.
		await page.goto( adminPage( slug.behavior, '&tab=friction' ) );
		await expect( page.getByText( 'Rage clicks, last 30 days' ) ).toBeVisible();
		await expect(
			page.locator( 'tbody' ).filter( { hasText: 'button#buy-now' } )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'tbody' ).filter( { hasText: 'div.hero' } )
		).toHaveCount( 1 );
	} finally {
		wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php purge-behavior' );
	}
} );
