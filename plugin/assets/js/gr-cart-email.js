/**
 * GreenPNG cart recovery — checkout email watcher (v1.1).
 *
 * One purpose: when the shopper has checked the site owner's opt-in
 * box and the billing email field settles, offer that email to the
 * collect route so a checkout that never completes can be recovered.
 * The checkbox is the ask — unchecked, nothing is ever sent. The
 * server re-gates everything (feature switch, marketing consent, the
 * opt-in flag) before storing, so a tampered client talking without
 * the box is refused there.
 */
( function () {
	'use strict';

	var config = window.GreenPNGCart;

	// Without server-localized endpoint data the watcher stays dormant.
	if ( !config || !config.url || !config.token ) {
		return;
	}

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	var COOLDOWN_MS = 5000;

	var lastValue = '';
	var lastSentAt = 0;
	var inFlight = false;

	/**
	 * The opt-in box: our checkbox, checked by the shopper's own hand.
	 *
	 * @return {boolean}
	 */
	function optedIn() {
		var box = document.getElementById( 'gr-cart-opt-in' );

		return !!box && box.checked;
	}

	/**
	 * The classic checkout's billing email field.
	 *
	 * @return {HTMLInputElement|null}
	 */
	function emailField() {
		return document.getElementById( 'billing_email' ) ||
			document.querySelector( 'input[name="billing_email"]' );
	}

	/**
	 * Offers one settled email to the server. Fire-and-forget by
	 * design: a refusal (feature off, consent denied, junk value) is
	 * the server's word, and the page never reacts to it.
	 *
	 * @param {string} value The settled billing email.
	 */
	function offer( value ) {
		if ( inFlight || Date.now() - lastSentAt < COOLDOWN_MS ) {
			return;
		}

		inFlight = true;
		lastSentAt = Date.now();

		try {
			window.fetch( config.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					token: config.token,
					name: 'cart_email',
					email: value,
					cart_opt_in: 1
				} )
			} ).then( function () {
				inFlight = false;
			}, function () {
				inFlight = false;
			} );
		} catch ( error ) {
			inFlight = false;
		}
	}

	/**
	 * The field settling: only a changed, plausible, opted-in address
	 * is worth an offer — re-blurs and mid-typing tabs never send.
	 */
	function onBlur() {
		if ( !optedIn() ) {
			return;
		}

		var field = emailField();
		var value = field ? String( field.value || '' ).trim() : '';

		if ( !value || value === lastValue || !EMAIL_RE.test( value ) ) {
			return;
		}

		lastValue = value;
		offer( value );
	}

	var field = emailField();

	// The classic form may render after this file runs; a late-bound
	// listener on the document catches the field whenever it appears.
	if ( field ) {
		field.addEventListener( 'blur', onBlur );
	} else {
		document.addEventListener( 'blur', function ( event ) {
			var target = event.target;

			if ( target && target.id === 'billing_email' ) {
				onBlur();
			}
		}, true );
	}
}() );
