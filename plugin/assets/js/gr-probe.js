/**
 * GreenPNG client probe — security module (v1.0).
 *
 * Conclusion-only automation signals: the four checks below each
 * produce one boolean, the weighted sum becomes the bot score, and
 * only that conclusion set ever leaves the browser. No fingerprint
 * strings, no canvas/audio sampling, no storage access, no
 * persistent identifiers. The payload travels to the site's own
 * collect endpoint via navigator.sendBeacon; a request without the
 * API, or a score of zero (an ordinary visitor), sends nothing at
 * all — human traffic must not grow the event table.
 *
 * Load is deferred and the whole module is failure-silent: without
 * JavaScript, with a blocked endpoint, or on a browser lacking the
 * APIs, the site behaves exactly as before.
 */
( function () {
	'use strict';

	var config = window.GreenPNGProbe;

	// Server-localized endpoint data; without it there is nothing to
	// talk to and the module stays dormant.
	if ( !config || !config.url || !config.token ) {
		return;
	}

	/**
	 * Automation-controlled browsers expose this W3C flag.
	 *
	 * @return {boolean} True when driven by WebDriver.
	 */
	function webdriverFlag() {
		return navigator.webdriver === true;
	}

	/**
	 * Renderer category check: real GPUs name concrete hardware, while
	 * automation stacks fall back to software rasterizers. The string
	 * itself never leaves this function — only its category does.
	 *
	 * @return {boolean} True when the renderer is a software rasterizer.
	 */
	function softwareRendererFlag() {
		try {
			var canvas = document.createElement( 'canvas' );
			var gl = canvas.getContext( 'webgl' ) || canvas.getContext( 'experimental-webgl' );
			if ( !gl ) {
				return false;
			}

			var ext = gl.getExtension( 'WEBGL_debug_renderer_info' );
			if ( !ext ) {
				// The browser withholds the string entirely: honest
				// unknown, never a suspicion.
				return false;
			}

			var renderer = String( gl.getParameter( ext.UNMASKED_RENDERER_WEBGL ) || '' );
			return /swiftshader|llvmpipe|software|basic render|mesa offscreen|angle \(.*software/i.test( renderer );
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Headless window trait: a real browser window always has outer
	 * dimensions; classic headless shells report zero.
	 *
	 * @return {boolean} True when the outer window is dimensionless.
	 */
	function headlessWindowFlag() {
		return window.outerWidth === 0 || window.outerHeight === 0;
	}

	/**
	 * Language stack anomaly: real browsers expose a non-empty
	 * navigator.languages array; several automation builds ship an
	 * empty or missing one.
	 *
	 * @return {boolean} True when the language stack is absent or empty.
	 */
	function languageAnomalyFlag() {
		return !Array.isArray( navigator.languages ) || navigator.languages.length === 0;
	}

	/**
	 * Sends the conclusion set once, only when automation is suspected.
	 *
	 * @param {number} score Weighted conclusion score.
	 * @param {Object} flags The four boolean conclusions.
	 */
	function report( score, flags ) {
		if ( !navigator.sendBeacon || score <= 0 ) {
			return;
		}

		var payload = {
			token: config.token,
			name: 'signal',
			bot_score: score,
			webdriver: flags.webdriver,
			software_renderer: flags.softwareRenderer,
			headless_window: flags.headlessWindow,
			language_anomaly: flags.languageAnomaly,
			path: String( window.location.pathname || '' ).slice( 0, 191 )
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

	var flags = {
		webdriver: webdriverFlag(),
		softwareRenderer: softwareRendererFlag(),
		headlessWindow: headlessWindowFlag(),
		languageAnomaly: languageAnomalyFlag()
	};

	// Weighted conclusions: the automation-driving flag counts most,
	// the others are corroborating traits.
	var score = ( flags.webdriver ? 40 : 0 ) +
		( flags.softwareRenderer ? 30 : 0 ) +
		( flags.headlessWindow ? 20 : 0 ) +
		( flags.languageAnomaly ? 10 : 0 );

	report( Math.min( score, 100 ), flags );
}() );
