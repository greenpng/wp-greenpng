import { test, expect } from '@playwright/test';
import { adminPage, login, slug } from './helpers';

test( 'an owner adds and deletes access rules', async ( { page } ) => {
	await login( page );

	// Ban list (default tab).
	await page.goto( adminPage( slug.access ) );
	await page.selectOption( '#gr-match-kind', 'ip' );
	await page.fill( '#gr-match-value', '198.51.100.7' );
	await page.fill( '#gr-note', 'CI ban fixture' );
	await page.getByRole( 'button', { name: 'Add rule' } ).click();
	await expect( page.locator( 'tbody', { hasText: '198.51.100.7' } ) ).toBeVisible();

	// Allow list tab carries its own table.
	await page.goto( adminPage( slug.access, '&tab=allow' ) );
	await page.selectOption( '#gr-match-kind', 'ip' );
	await page.fill( '#gr-match-value', '203.0.113.0/24' );
	await page.fill( '#gr-note', 'CI allow fixture' );
	await page.getByRole( 'button', { name: 'Add rule' } ).click();
	await expect( page.locator( 'tbody', { hasText: '203.0.113.0/24' } ) ).toBeVisible();

	// Bulk delete returns the ban list to empty. The scoping matters:
	// the add-form's table carries its own tbody, so a bare tbody
	// locator resolves to both and breaks strict mode now that the
	// rules table actually renders its rows.
	await page.goto( adminPage( slug.access ) );
	await page.locator( '.wp-list-table tbody input[type=checkbox]' ).first().check();
	await page.getByRole( 'button', { name: 'Delete selected' } ).click();
	await expect( page.locator( '.wp-list-table tbody' ) ).not.toContainText( '198.51.100.7' );
} );
