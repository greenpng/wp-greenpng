import { test, expect } from '@playwright/test';
import { dbCount, login, resetConsentFallback, saveSettings, wpcli } from './helpers';

test( 'a customer buys a product and the paid order is attributed', async ( { page, browser } ) => {
	// Cash on delivery gives the Store API a payment method that needs
	// no external processor. Both the gateway flag and the product ride
	// the eval-file seed helpers: their payloads stay PHP literals
	// instead of crossing the wp-env argument layer, and the wc CLI
	// commands are not a dependency at all.
	wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php enable-cod' );
	const productOut = wpcli( 'wp eval-file wp-content/plugins/greenpng/ci-seed.php make-product' );
	const productId = parseInt( productOut, 10 );
	expect( productId, `make-product output: ${ productOut.slice( 0, 120 ) }` ).toBeGreaterThan( 0 );

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
		const orderBody = await order.text();
		// The Store API's checkout envelope names the order order_id.
		const orderId = ( await order.json() ).order_id as number | undefined;
		// A thrown error prints its message in full, unlike an expect
		// message riding a matcher error — the checkout's response
		// body is the only way to see why no order id came back.
		if ( ! orderId || orderId < 1 ) {
			throw new Error( `checkout returned no order id (status ${ order.status() }): ${ orderBody.slice( 0, 400 ) }` );
		}

		// Payment completes out-of-band (cash collected): the status
		// move fires the payment hook the adapter listens to.
		wpcli( `wp eval-file wp-content/plugins/greenpng/ci-seed.php complete-order ${ orderId }` );

		expect( dbCount( 'wp_gr_conversions' ) ).toBeGreaterThanOrEqual( 1 );
		const source = wpcli( 'wp db query "SELECT source_type FROM wp_gr_conversions ORDER BY id DESC LIMIT 1"' );
		expect( source ).toContain( 'woocommerce' );

		await visitor.close();
	} finally {
		await resetConsentFallback( page );
	}
} );
