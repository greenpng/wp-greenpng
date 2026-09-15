import { test, expect, type Locator } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'the owner edits the points table and the save redirects back', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.scoring ) );

	// The form is one whole-ruleset editor. A fresh CI site carries no
	// rules yet, so the first row is the page's own "add a rule"
	// template: an empty event name means rules_from_post drops the
	// row, so the event must be chosen before the points value can
	// survive the save.
	const firstRow = page.locator( 'tbody tr' ).first();
	await firstRow.locator( 'select[name$="[event_name]"]' ).selectOption( 'pageview' );
	await firstRow.locator( 'input[name$="[points]"]' ).fill( '5' );
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );

	// The saved points value survives the redirect somewhere in the
	// rows, not necessarily at the position it was typed into.
	const savedPoints = page.locator( 'input[name^="gr_rule"][name$="[points]"]' );
	const values: string[] = [];
	for ( const box of await savedPoints.all() ) {
		values.push( ( await box.inputValue() ) ?? '' );
	}
	expect( values, 'post-save points values' ).toContain( '5' );

	// Restore: the row this spec created is removed with its own
	// checkbox, so the shared CI site keeps the ruleset it shipped
	// with for the next spec.
	const rows = page.locator( 'tbody tr' );
	let removed = false;
	for ( const row of await rows.all() ) {
		const event = await row.locator( 'select[name$="[event_name]"]' ).inputValue();
		const points = await row.locator( 'input[name$="[points]"]' ).inputValue();
		if ( 'pageview' === event && '5' === points ) {
			await row.locator( 'input[name$="[remove]"]' ).check();
			removed = true;
			break;
		}
	}
	expect( removed, 'the rule this spec added was found for removal' ).toBe( true );
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );

	// The fixture rule is gone: no points input reads the fixture
	// value any more.
	const after: string[] = [];
	for ( const box of await savedPoints.all() ) {
		after.push( ( await box.inputValue() ) ?? '' );
	}
	expect( after, 'post-restore points values' ).not.toContain( '5' );
} );
