'use strict';

/**
 * gr-datagrid.js component tests (docs/13 U4), run with node:
 *   node tests/js/gr-datagrid-test.js
 *
 * The XSS acceptance is structural: cells are DOM text nodes, so the
 * payload fixtures below must survive into the tree verbatim — no
 * element may ever be constructed from row data, and the file must
 * not contain any HTML-string sink at all.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );

// --- Minimal DOM stub: records structure, refuses to parse markup. --

function StubElement( nodeName ) {
	this.nodeName = nodeName.toUpperCase();
	this.children = [];
	this.attributes = {};
	this.text = '';
}

StubElement.prototype.appendChild = function ( child ) {
	this.children.push( child );
	return child;
};

StubElement.prototype.removeChild = function ( child ) {
	const i = this.children.indexOf( child );
	if ( i >= 0 ) {
		this.children.splice( i, 1 );
	}
	return child;
};

StubElement.prototype.setAttribute = function ( name, value ) {
	this.attributes[ name ] = String( value );
};

Object.defineProperty( StubElement.prototype, 'firstChild', {
	get() {
		return this.children[ 0 ] || null;
	}
} );

Object.defineProperty( StubElement.prototype, 'textContent', {
	set( value ) {
		// The ONLY way data enters the tree: as text, never parsed.
		this.text = String( value );
		this.children = [];
	},
	get() {
		return this.text;
	}
} );

const container = new StubElement( 'div' );
container.id = 'grid-target';

const created = [];
const fetches = [];

global.window = global;
global.document = {
	getElementById: ( id ) => ( id === 'grid-target' ? container : null ),
	createElement: ( name ) => {
		const el = new StubElement( name );
		created.push( el );
		return el;
	},
	hidden: false
};
global.setInterval = () => 999;
global.clearInterval = () => {};
global.fetch = ( url, init ) => {
	fetches.push( { url, init } );
	const status = fixtures.nextStatus;
	fixtures.nextStatus = 200;

	return Promise.resolve( {
		ok: status < 400,
		status,
		json: () => Promise.resolve( fixtures.nextBody )
	} );
};

// --- Load the component inside this stub world. ----------------------

const src = fs.readFileSync(
	path.join( __dirname, '..', '..', 'plugin', 'assets', 'js', 'gr-datagrid.js' ),
	'utf8'
);
eval( src ); // jshint ignore:line

// --- Fixtures: every classic injection shape, in every column. -------

const XSS = [
	'<script>alert(1)</script>',
	'<img src=x onerror=alert(2)>',
	'"><svg onload=alert(3)>',
	'javascript:alert(4)',
	'<iframe src="javascript:alert(5)"></iframe>',
	'\'-alert(6)-\''
];

const fixtures = {
	nextStatus: 200,
	nextBody: { rows: XSS.map( ( payload ) => ( { col: payload } ) ) }
};

const grid = new window.GrDataGrid( {
	containerId: 'grid-target',
	endpoint: 'https://stub.example/wp-json/greenpng/v1/live',
	nonce: 'gr-stub-nonce',
	columns: [ { key: 'col', label: 'Value' } ]
} );

setTimeout( () => {
	// Fix 1: data came from an authenticated fetch, not inline arrays.
	assert.strictEqual( fetches.length, 1 );
	assert.strictEqual( fetches[ 0 ].url, 'https://stub.example/wp-json/greenpng/v1/live' );
	assert.strictEqual( fetches[ 0 ].init.headers[ 'X-WP-Nonce' ], 'gr-stub-nonce' );

	// Fix 2: every payload is present verbatim as cell text…
	const table = container.children[ 0 ];
	assert.strictEqual( table.nodeName, 'TABLE' );
	const body = table.children[ 1 ];
	const texts = body.children.map( ( tr ) => tr.children[ 0 ].text );
	assert.deepStrictEqual( texts, XSS );

	// …and nothing was ever CONSTRUCTED from the data: the created
	// elements are exactly the table furniture, no script/img/svg/
	// iframe nodes anywhere in the tree.
	const dangerous = created.filter( ( el ) =>
		[ 'SCRIPT', 'IMG', 'SVG', 'IFRAME' ].includes( el.nodeName )
	);
	assert.deepStrictEqual( dangerous, [], 'data must never become elements' );

	// The file itself carries no HTML-string sink.
	[ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(' ].forEach( ( sink ) => {
		assert.ok( ! src.includes( sink ), `source must not contain ${ sink }` );
	} );

	// Fix 3: a failed fetch leaves the rendered content untouched.
	const before = container.children.length;
	fixtures.nextStatus = 500;
	grid.load();

	setTimeout( () => {
		assert.strictEqual( container.children.length, before, 'failed enhancement must not blank the page' );
		assert.strictEqual( container.children[ 0 ].nodeName, 'TABLE' );

		console.log( 'gr-datagrid: 3 fix obligations + XSS battery all green' );
	}, 0 );
}, 0 );
