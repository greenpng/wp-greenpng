/**
 * GreenPNG client probe — behavior module (v1.1).
 *
 * Engagement and friction signals for the marketing track: dwell
 * buckets, deepest scroll milestone, rage clicks, dead clicks.
 * Consent gates everything: the server omits this file unless the
 * visitor's consent state allowed it, and every buffer addition
 * re-reads the WP Consent API cookie so a mid-session revocation
 * drops the buffer. One sendBeacon flush per page — pagehide or the
 * first hidden state — capped at 20 events. Locators stay
 * structural: tag plus id or first class word, never text or
 * coordinates.
 */
( function () {
	'use strict';

	var config = window.GreenPNGBehavior;

	// Without server-localized endpoint data the module stays dormant.
	if ( !config || !config.url || !config.token ) {
		return;
	}

	var MAX_EVENTS = 20;
	var IDLE_MS = 30000;
	var RAGE_WINDOW_MS = 1000;
	var RAGE_DISTANCE_PX = 20;
	var RAGE_COOLDOWN_MS = 3000;
	var DEAD_CLICK_MS = 600;

	var buffer = [];
	var sent = false;

	/**
	 * Marketing consent right now: the CMP's WP Consent API cookie,
	 * else the server fallback. The only storage read this module makes.
	 *
	 * @return {boolean} True when recording is allowed.
	 */
	function consentAllowed() {
		var match = document.cookie.match( /(?:^|;\s*)wp_consent_marketing=([^;]+)/ );

		if ( match ) {
			return 'allow' === decodeURIComponent( match[ 1 ] );
		}

		return config.consent === true;
	}

	/**
	 * Structural locator: tag plus id or first class word, clipped.
	 * The site's own markup vocabulary — no text, no coordinates.
	 *
	 * @param {Element} target Click target.
	 * @return {string} Locator, at most 64 characters.
	 */
	function locator( target ) {
		try {
			var tag = ( target.tagName || '' ).toLowerCase();
			var mark = '';

			if ( target.id ) {
				mark = '#' + target.id;
			} else if ( 'string' === typeof target.className && target.className ) {
				var first = target.className.trim().split( /\s+/ )[ 0 ];
				if ( first ) {
					mark = '.' + first;
				}
			}

			var value = tag + mark;

			return value.length > 64 ? value.slice( 0, 64 ) : value;
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * Current path, server-side capped shape.
	 *
	 * @return {string}
	 */
	function path() {
		return String( window.location.pathname || '' ).slice( 0, 191 );
	}

	/**
	 * Buffers one event: past the cap it drops, without consent it
	 * never records.
	 *
	 * @param {Object} event Event body.
	 */
	function record( event ) {
		if ( buffer.length >= MAX_EVENTS ) {
			return;
		}

		if ( !consentAllowed() ) {
			return;
		}

		buffer.push( event );
	}

	// ---- Dwell: active seconds, paused by 30s idleness. ----
	var activeMs = 0;
	var lastTick = 0;
	var idleTimer = null;

	function tick() {
		var now = Date.now();

		if ( lastTick && now - lastTick < IDLE_MS + 1000 ) {
			activeMs += now - lastTick;
		}

		lastTick = now;
		clearTimeout( idleTimer );
		idleTimer = setTimeout( function () {
			lastTick = 0;
		}, IDLE_MS );
	}

	// The clock starts with the page: a no-input reader still dwelled.
	tick();

	window.addEventListener( 'pointerdown', tick, { passive: true } );
	window.addEventListener( 'keydown', tick, { passive: true } );

	// ---- Scroll: deepest milestone only, measured in a rAF. ----
	var milestone = 0;
	var rafPending = false;

	function measure() {
		rafPending = false;

		// Scrolling is reading — it keeps the dwell clock warm.
		tick();

		var doc = document.documentElement;
		var bottom = window.innerHeight + ( window.scrollY || doc.scrollTop || 0 );
		var height = ( doc.scrollHeight || 0 ) - window.innerHeight;

		if ( height <= 0 ) {
			return;
		}

		var reached = Math.floor( Math.min( Math.max( 100 * bottom / height, 0 ), 100 ) / 25 ) * 25;

		if ( reached > milestone ) {
			milestone = reached;
		}
	}

	function onScroll() {
		if ( rafPending ) {
			return;
		}

		rafPending = true;
		window.requestAnimationFrame( measure );
	}

	window.addEventListener( 'scroll', onScroll, { passive: true } );

	// ---- Dead click: a click on a non-interactive element followed
	// by 600ms without any DOM change. ----
	function isInteractive( element ) {
		return !!element.closest( 'a, button, input, textarea, select, option, label, summary, [role="button"], [contenteditable="true"]' );
	}

	function watchDeadClick( target ) {
		if ( isInteractive( target ) ) {
			return;
		}

		var mutated = false;
		var observer;

		try {
			observer = new MutationObserver( function () {
				mutated = true;
				observer.disconnect();
			} );
			observer.observe( document.body, { childList: true, subtree: true, attributes: true } );
		} catch ( error ) {
			return;
		}

		setTimeout( function () {
			observer.disconnect();

			if ( !mutated && 'hidden' !== document.visibilityState ) {
				record( { name: 'dead_click', locator: locator( target ), path: path() } );
			}
		}, DEAD_CLICK_MS );
	}

	// ---- Rage click: three or more clicks within one second, all
	// within a 20px radius, then a cooldown before the next verdict.
	// The verdict fires at the third click so a fast exit cannot
	// lose it; later clicks inside the same window and radius raise
	// the recorded count. ----
	var clicks = [];
	var rageCooldownUntil = 0;
	var rageBurst = null;

	document.addEventListener( 'click', function ( event ) {
		tick();

		var target = event.target;

		if ( !target || !target.tagName ) {
			return;
		}

		watchDeadClick( target );

		var now = Date.now();

		if ( rageBurst && now <= rageBurst.until ) {
			var dx = event.clientX - rageBurst.x;
			var dy = event.clientY - rageBurst.y;

			if ( dx * dx + dy * dy <= RAGE_DISTANCE_PX * RAGE_DISTANCE_PX ) {
				rageBurst.event.clicks++;
			}

			return;
		}

		if ( now < rageCooldownUntil ) {
			clicks = [];

			return;
		}

		clicks.push( { t: now, x: event.clientX, y: event.clientY } );
		clicks = clicks.filter( function ( click ) {
			return now - click.t <= RAGE_WINDOW_MS;
		} );

		if ( clicks.length < 3 ) {
			return;
		}

		var burst = clicks.length;
		var first = clicks[ 0 ];
		var spanX = event.clientX - first.x;
		var spanY = event.clientY - first.y;

		clicks = [];

		if ( spanX * spanX + spanY * spanY > RAGE_DISTANCE_PX * RAGE_DISTANCE_PX ) {
			return;
		}

		var verdict = { name: 'rage_click', clicks: burst, locator: locator( target ), path: path() };
		record( verdict );
		rageBurst = { event: verdict, until: first.t + RAGE_WINDOW_MS, x: first.x, y: first.y };
		rageCooldownUntil = now + RAGE_COOLDOWN_MS;
	}, { passive: true } );

	// ---- Flush: exactly one beacon, at the earliest of pagehide or
	// the first hidden state. Dwell and scroll join first — they are
	// the guaranteed pair — then the buffered click events, all under
	// the page cap. ----
	function flush() {
		if ( sent ) {
			return;
		}

		sent = true;

		if ( !consentAllowed() || !navigator.sendBeacon ) {
			return;
		}

		// Close the open interval; the idle rule caps it.
		var leaving = Date.now();
		if ( lastTick && leaving - lastTick < IDLE_MS + 1000 ) {
			activeMs += leaving - lastTick;
		}

		var seconds = Math.floor( activeMs / 1000 );
		var bucket = seconds >= 180 ? '180+' : ( seconds >= 60 ? '60-180' : ( seconds >= 15 ? '15-60' : '0-15' ) );
		var events = [
			{ name: 'dwell', bucket: bucket, path: path() }
		];

		if ( milestone > 0 ) {
			events.push( { name: 'scroll_depth', milestone: milestone, path: path() } );
		}

		events = events.concat( buffer ).slice( 0, MAX_EVENTS );

		var payload = {
			token: config.token,
			name: 'behavior',
			events: events
		};

		try {
			// A typed Blob keeps the REST endpoint's JSON parsing happy;
			// a plain string would arrive as text/plain and be rejected.
			var blob = new Blob( [ JSON.stringify( payload ) ], { type: 'application/json' } );
			navigator.sendBeacon( config.url, blob );
		} catch ( error ) {
			// Never let reporting disturb the page.
		}
	}

	window.addEventListener( 'pagehide', flush );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		}
	} );
}() );
