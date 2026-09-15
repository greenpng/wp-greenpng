import { test, expect } from '@playwright/test';
import { login, slug, adminPage } from './helpers';

test( 'the owner edits the points table and the save redirects back', async ( { page } ) => {
	await login( page );
	await page.goto( adminPage( slug.scoring ) );

	// The form is one whole-ruleset editor: read the first row's
	// original points value, change it, save, and assert both the
	// post-redirect confirmation and the persisted value — then
	// restore the original so the shared site's ruleset survives.
	const firstRow = page.locator( 'tbody tr' ).first();
	const pointsBox = firstRow.locator( 'input[name^="gr_rule"][name$="[points]"]' );
	const original = await pointsBox.inputValue();

	await pointsBox.fill( '5' );
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );
	await expect(
		firstRow.locator( 'input[name^="gr_rule"][name$="[points]"]' )
	).toHaveValue( '5' );

	// Restore: the value the page shipped with is the value the
	// next spec must still see.
	await firstRow.locator( 'input[name^="gr_rule"][name$="[points]"]' ).fill( original );
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect( page.locator( '.notice.is-dismissible' ) ).toContainText( 'Rules saved' );
} );
