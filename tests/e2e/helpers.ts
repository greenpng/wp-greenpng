import { execSync } from 'node:child_process';
import type { Page } from '@playwright/test';

/** wp-env default administrator (ephemeral CI site, not a secret). */
export const ADMIN = {
	username: 'admin',
	password: process.env.E2E_ADMIN_PASS ?? 'password',
};

/** Admin page slugs (Gr_*_Page::SLUG constants, read from the source). */
export const slug = {
	dashboard: 'greenpng-dashboard',
	settings: 'greenpng-settings',
	traffic: 'greenpng-traffic',
	access: 'greenpng-access',
	url: 'greenpng-url',
	campaigns: 'greenpng-campaigns',
	bot: 'greenpng-bot',
} as const;

/** Absolute-path helper for wp-admin plugin pages. */
export function adminPage( pageSlug: string, extra = '' ): string {
	return `/wp-admin/admin.php?page=${ pageSlug }${ extra }`;
}

/** Marketing cookies set by Gr_Attribution_Identity (source constants). */
export const COOKIES = { attr: 'gr_attr', session: 'gr_session' } as const;

/** Logs in as admin on the wp-env site; no-op when already logged in. */
export async function login( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	if ( ! ( await page.locator( '#loginform' ).count() ) ) {
		return; // Cookie session still valid.
	}
	await page.fill( '#user_login', ADMIN.username );
	await page.fill( '#user_pass', ADMIN.password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
}

/**
 * Flips checkboxes on one settings tab and saves, asserting the
 * post-redirect-get confirmation before returning.
 */
export async function saveSettings(
	page: Page,
	tab: 'general' | 'security' | 'attribution',
	set: Record< string, boolean >
): Promise< void > {
	await page.goto( adminPage( slug.settings, `&tab=${ tab }` ) );
	for ( const [ name, on ] of Object.entries( set ) ) {
		const box = page.locator( `input[name="${ name }"]` );
		if ( ( await box.isChecked() ) !== on ) {
			await box.setChecked( on );
		}
	}
	await page.click( 'p.submit input[type=submit]' );
	await page.waitForURL( /gr_saved=1/ );
}

/** Restores the privacy default (no marketing without consent). */
export async function resetConsentFallback( page: Page ): Promise< void > {
	await saveSettings( page, 'general', { marketing_consent_fallback: false } );
}

/** Reads window.GreenPNGProbe (url + daily token) off the front page. */
export async function probeData( page: Page ): Promise< { url: string; token: string } > {
	await page.goto( '/' );
	const html = await page.content();
	const match = html.match( /window\.GreenPNGProbe=(\{.*?\});/s );
	if ( ! match ) {
		throw new Error( 'Probe data not embedded on the front page.' );
	}
	return JSON.parse( match[ 1 ] ) as { url: string; token: string };
}

/** Runs one wp-cli command inside the wp-env containers. */
export function wpcli( command: string ): string {
	return execSync( `npx wp-env run cli ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/** SQL row count via wp-cli (the CI site prefix is the wp-env default). */
export function dbCount( table: string ): number {
	const out = wpcli( `wp db query "SELECT COUNT(*) AS n FROM ${ table }"` );
	const match = out.match( /(\d+)/ );
	if ( ! match ) {
		throw new Error( `No count in wp db query output: ${ out.slice( 0, 200 ) }` );
	}
	return parseInt( match[ 1 ], 10 );
}
