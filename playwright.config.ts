import { defineConfig, devices } from '@playwright/test';

/**
 * All specs share one wp-env WordPress instance (ADR-0008): specs mutate
 * shared settings, so tests run serially in one browser. Retries absorb
 * environment flake only — a real failure stays red on retry.
 */
export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 90_000,
	expect: { timeout: 20_000 },
	fullyParallel: false,
	workers: 1,
	retries: 2,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		actionTimeout: 20_000,
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
