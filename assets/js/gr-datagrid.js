/**
 * gr-datagrid.js — live table for the realtime traffic page
 * (docs/06 §2.2), ported from the prototype's datagrid with its three
 * defects fixed: data comes from an authenticated fetch instead of
 * inline arrays, every cell renders through DOM text nodes instead of
 * HTML string assembly, and a failed fetch leaves the server-rendered
 * table in place — the page works before this script even runs.
 *
 * The file contains no interface copy: column labels and messages
 * arrive through the options object the page localizes.
 */
( function ( global ) {
	'use strict';

	/**
	 * One grid: fetch, render, optionally poll.
	 *
	 * @param {Object} options { containerId, endpoint, nonce, columns,
	 *                           pollMs, loadingLabel }
	 * @constructor
	 */
	function GrDataGrid( options ) {
		this.container = global.document.getElementById( options.containerId );
		this.options = options;
		this.columns = options.columns || [];
		this.pollMs = options.pollMs || 0;
		this.timer = null;

		if ( ! this.container ) {
			return;
		}

		this.load();
		this.start();
	}

	/**
	 * Fetches the current rows. The REST nonce rides the header; the
	 * response shape is { rows: [ { key: value } ] }.
	 *
	 * @return {void}
	 */
	GrDataGrid.prototype.load = function () {
		var self = this;

		global.fetch( this.options.endpoint, {
			headers: { 'X-WP-Nonce': this.options.nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'grid endpoint ' + response.status );
			}
			return response.json();
		} ).then( function ( data ) {
			self.render( data.rows || [] );
		} ).catch( function () {
			// The server-rendered table stays; a failed enhancement
			// must never blank the page.
		} );
	};

	/**
	 * Rebuilds the table. Cells are created through the DOM with
	 * textContent only — values are data, never markup.
	 *
	 * @param {Array} rows Row objects keyed by column id.
	 * @return {void}
	 */
	GrDataGrid.prototype.render = function ( rows ) {
		var doc = global.document;
		var table = doc.createElement( 'table' );
		table.className = 'widefat striped';

		var head = doc.createElement( 'thead' );
		var headRow = doc.createElement( 'tr' );
		var i;

		for ( i = 0; i < this.columns.length; i++ ) {
			var th = doc.createElement( 'th' );
			th.setAttribute( 'scope', 'col' );
			th.textContent = this.columns[ i ].label;
			headRow.appendChild( th );
		}
		head.appendChild( headRow );
		table.appendChild( head );

		var body = doc.createElement( 'tbody' );

		for ( i = 0; i < rows.length; i++ ) {
			var tr = doc.createElement( 'tr' );

			for ( var c = 0; c < this.columns.length; c++ ) {
				var td = doc.createElement( 'td' );
				td.textContent = rows[ i ][ this.columns[ c ].key ];
				tr.appendChild( td );
			}
			body.appendChild( tr );
		}

		table.appendChild( body );

		while ( this.container.firstChild ) {
			this.container.removeChild( this.container.firstChild );
		}
		this.container.appendChild( table );
	};

	/**
	 * Starts polling when an interval was given; hidden tabs skip
	 * their turns so background pages stop paying for updates.
	 *
	 * @return {void}
	 */
	GrDataGrid.prototype.start = function () {
		var self = this;

		if ( ! this.pollMs || this.timer ) {
			return;
		}

		this.timer = global.setInterval( function () {
			if ( global.document.hidden ) {
				return;
			}
			self.load();
		}, this.pollMs );
	};

	/**
	 * Stops polling; pages call this when the view goes away.
	 *
	 * @return {void}
	 */
	GrDataGrid.prototype.stop = function () {
		if ( this.timer ) {
			global.clearInterval( this.timer );
			this.timer = null;
		}
	};

	global.GrDataGrid = GrDataGrid;
}( typeof window !== 'undefined' ? window : globalThis ) );
