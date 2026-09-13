import { test, expect } from '@playwright/test';
import { dbCount, login, resetConsentFallback, saveSettings, wpcli } from './helpers';

test( 'a customer buys a product and the paid order is attributed', async ( { page, browser } ) => {
	// Cash on delivery gives the Store API a payment method that needs
	// no external processor. Merge the enabled flag over whatever the
	// gateway's settings row holds: WooCommerce may not have seeded it
	// on a fresh install, so a targeted patch would fail.
	wpcli(
		`wp eval "update_option( 'woocommerce_cod_settings', array_merge( (array) get_option( 'woocommerce_cod_settings', array() ), array( 'enabled' => 'yes' ) ) );"`
	);
	const productId = parseInt(
		wpcli( 'wp wc product create --name="E2E Cap" --regular_price=9.99 --type=simple --user=1 --porcelain' ),
		10
	);
	expect( productId ).toBeGreaterThan( 0 );

	await login( page );
	await saveSettings( page, 'general', { marketing_consent_fallback: true } );
	try {
		const visitor = await browser.newContext();
		const vpage = await visitor.newPage();

		// Landing with UTMs first, so the order binds to the campaign.
		await vpage.goto( '/?utm_source=ci&utm_medium=e2e&utm_campaign=run' );

		// Store API as the same visitor: the browser context's request
		// shares the page cookies (cart token included).
		const cart = await vpage.request.get( '/wp-json/wc/store/v1/cart' );
		expect( cart.status() ).toBe( 200 );
		const nonce = cart.headers()[ 'nonce' ] ?? '';

		const added = await vpage.request.post( '/wp-json/wc/store/v1/cart/add-item', {
			headers: { Nonce: nonce },
			data: { id: productId, quantity: 1 },
		} );
		// The Store API answers 201 Created on a successful add.
		expect( [ 200, 201 ] ).toContain( added.status() );

		const address = {
			first_name: 'CI',
			last_name: 'Customer',
			email: 'buyer@example.test',
			phone: '5550100',
			address_1: '1 Test Way',
			city: 'San Francisco',
			state: 'CA',
			postcode: '94103',
			country: 'US',
		};
		const order = await vpage.request.post( '/wp-json/wc/store/v1/checkout', {
			headers: { Nonce: nonce },
			data: {
				billing_address: address,
				shipping_address: address,
				payment_method: 'cod',
				customer_note: 'E2E order',
			},
		} );
		if ( order.status() >= 400 ) {
			throw new Error( `Store checkout failed (${ order.status() }): ${ ( await order.text() ).slice( 0, 400 ) }` );
		}
		const orderId = ( await order.json() ).id as number;
		expect( orderId ).toBeGreaterThan( 0 );

		// Payment completes out-of-band (cash collected): the status
		// move fires the payment hook the adapter listens to.
		wpcli( `wp wc order update ${ orderId } --status=completed --user=1` );

		expect( dbCount( 'wp_gr_conversions' ) ).toBeGreaterThanOrEqual( 1 );
		const source = wpcli( 'wp db query "SELECT source_type FROM wp_gr_conversions ORDER BY id DESC LIMIT 1"' );
		expect( source ).toContain( 'woocommerce' );

		await visitor.close();
	} finally {
		await resetConsentFallback( page );
	}
} );
