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
