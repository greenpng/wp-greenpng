/**
 * Dashboard trend chart: fetches the summary series from the plugin's
 * REST endpoint and renders it with the vendored uPlot build. Every
 * UI string arrives from the server-side config (i18n discipline);
 * when anything fails — network, permissions, library — the page
 * keeps its server-rendered screen-reader-text table as the data.
 */
( function () {
	'use strict';

	var cfg   = window.GreenPNGDashboard;
	var mount = document.getElementById( 'gr-dashboard-trend' );

	if ( ! cfg || ! mount || typeof uPlot === 'undefined' || typeof fetch === 'undefined' ) {
		return;
	}

	var labels = cfg.labels || {};

	fetch( cfg.endpoint + '?days=' + cfg.trendDays, {
		headers: { 'X-WP-Nonce': cfg.nonce },
		credentials: 'same-origin'
	} ).then( function ( response ) {
		if ( ! response.ok ) {
			throw new Error( 'dashboard endpoint ' + response.status );
		}
		return response.json();
	} ).then( function ( data ) {
		var x = data.dates.map( function ( day ) {
			return Date.parse( day + 'T00:00:00Z' ) / 1000;
		} );

		var options = {
			width: mount.clientWidth || 800,
			height: 260,
			cursor: { drag: { x: true, y: false } },
			scales: { x: { time: true } },
			series: [
				{},
				{ label: labels.sessions || 'Sessions', stroke: '#3858e9', width: 2 },
				{ label: labels.visitors || 'Visitors', stroke: '#00a32a', width: 2 },
				{ label: labels.pageviews || 'Page views', stroke: '#8c8f94', width: 2 },
				{ label: labels.conversions || 'Conversions', stroke: '#d63638', width: 2 }
			]
		};

		var chart = [
			x,
			data.series.sessions,
			data.series.visitors,
			data.series.pageviews,
			data.series.conversions
		];

		new uPlot( options, chart, mount ); // jshint ignore:line
	} ).catch( function () {
		// The server-rendered table stays the accessible data source.
	} );
}() );

/**
 * Live panels: polls the panels endpoint and refreshes the
 * online/sessions/bots values and the device table. The server
 * rendered complete values first, so any failure — network,
 * permissions, no fetch — just leaves the page as it was. Labels
 * come from the server config; unknown device codes render as
 * themselves.
 */
( function () {
	'use strict';

	var cfg = window.GreenPNGDashboard;

	if ( ! cfg || ! cfg.panelsEndpoint || typeof fetch === 'undefined' ) {
		return;
	}

	var labels = cfg.panelsLabels || {};
	var mounts = {
		online: document.getElementById( 'gr-panel-online' ),
		sessions: document.getElementById( 'gr-panel-sessions-today' ),
		bots: document.getElementById( 'gr-panel-bots-today' ),
		devices: document.getElementById( 'gr-panel-devices' )
	};

	function deviceName( key ) {
		var known = labels.devices || {};
		return known[ key ] || key;
	}

	function renderDevices( devices ) {
		var body = mounts.devices;

		if ( ! body ) {
			return;
		}

		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		if ( 0 === devices.length ) {
			body.appendChild( emptyRow() );
			return;
		}

		devices.forEach( function ( device ) {
			var row   = document.createElement( 'tr' );
			var name  = document.createElement( 'td' );
			var count = document.createElement( 'td' );

			name.textContent = deviceName( device.key );
			count.textContent = String( device.value );
			row.appendChild( name );
			row.appendChild( count );
			body.appendChild( row );
		} );
	}

	function emptyRow() {
		var row = document.createElement( 'tr' );
		var cell = document.createElement( 'td' );

		cell.colSpan = 2;
		cell.textContent = labels.noneToday || '';
		row.appendChild( cell );

		return row;
	}

	function refresh() {
		fetch( cfg.panelsEndpoint, {
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'panels endpoint ' + response.status );
			}
			return response.json();
		} ).then( function ( data ) {
			if ( mounts.online ) {
				mounts.online.textContent = String( data.online );
			}
			if ( mounts.sessions ) {
				mounts.sessions.textContent = String( data.sessionsToday );
			}
			if ( mounts.bots ) {
				mounts.bots.textContent = String( data.botsToday );
			}
			if ( Array.isArray( data.devices ) ) {
				renderDevices( data.devices );
			}
		} ).catch( function () {
			// Server-rendered values stay until the next poll.
		} );
	}

	refresh();
	window.setInterval( refresh, cfg.panelPollMs || 30000 );
}() );
