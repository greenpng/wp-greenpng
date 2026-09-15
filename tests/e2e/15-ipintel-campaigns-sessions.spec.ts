import { test, expect } from '@playwright/test';
import { login, slug, adminPage } from './helpers';

test( 'the bundled datacenter ranges and the invalid-traffic views answer', async ( { page } ) => {
	await login( page );

	// IP Intelligence: the Datacenter ranges section ships with the
	// bundled dataset — provenance, data date, and counts — plus the
	// IP2Proxy LITE attribution the NOTICE file also carries.
	await page.goto( adminPage( slug.ipintel ) );
	await expect( page.getByText( 'Datacenter ranges' ).first() ).toBeVisible();
	await expect( page.getByText( '2026-09-15' ).first() ).toBeVisible();
	await expect( page.getByText( 'IP2Proxy' ).first() ).toBeVisible();

	// Campaigns invalid-traffic tab: the tab renders either its
	// signal table (earlier specs leave real sessions behind) or its
	// honest empty state — both carry the signal-not-verdict
	// vocabulary, and neither is ever an automatic bot verdict.
	await page.goto( adminPage( slug.campaigns, '&tab=invalid' ) );
	await expect( page.locator( 'nav' ).getByText( 'Invalid traffic' ) ).toBeVisible();
	const signalTable = page.locator( 'table.widefat' ).first();
	if ( await signalTable.count() ) {
		await expect( signalTable ).toContainText( 'Hosting' );
	} else {
		await expect( page.getByText( 'No visitor sessions recorded yet' ) ).toBeVisible();
	}

	// The sessions list carries the Hosting column the classifier
	// feeds (V30): the column header is the contract.
	await page.goto( adminPage( slug.traffic, '&tab=sessions' ) );
	await expect( page.locator( 'thead' ) ).toContainText( 'Hosting' );
} );
