import { test, expect } from '@playwright/test';
import { login } from './helpers';

test( 'the privacy policy guide includes the greenpng section', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/options-general.php?page=privacy-policy-guide' );

	await expect( page.locator( 'body' ) ).toContainText( 'greenpng analytics' );
	await expect( page.locator( 'body' ) ).toContainText( 'anonymized IP addresses' );
} );
