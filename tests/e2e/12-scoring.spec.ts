import { test, expect } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'the owner edits the points table and the save redirects back', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.scoring ) );

	// The form is one whole-ruleset editor. The first row's points
	// value is changed, saved, and the confirmation asserted — but the
	// post-save row order is the page's own business, so the restore
	// finds the edited value wherever it landed instead of trusting
	// the same table position.
	const firstRow = page.locator( 'tbody tr' ).first();
	const pointsBox = firstRow.locator( 'input[name^="gr_rule"][name$="[points]"]' );
	const original = await pointsBox.inputValue();
	expect( original ).toMatch( /^-?\d+$/ );

	await pointsBox.fill( '5' );
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );

	// The edited points value survives the redirect somewhere in the
	// rows, not necessarily at the position it was typed into.
	const savedPoints = page.locator( 'input[name^="gr_rule"][name$="[points]"]' );
	const values: string[] = [];
	for ( const box of await savedPoints.all() ) {
		values.push( ( await box.inputValue() ) ?? '' );
	}
	expect( values, 'post-save points values' ).toContain( '5' );

	// Restore: the row holding the fixture value goes back to the
	// value the page shipped with, so the shared site's ruleset
	// survives for the next spec.
	const boxes = await savedPoints.all();
	for ( const box of boxes ) {
		if ( ( await box.inputValue() ) === '5' ) {
			await box.fill( original );
			break;
		}
	}
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );
} );
