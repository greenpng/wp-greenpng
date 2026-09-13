import { test, expect } from '@playwright/test';
import { probeData } from './helpers';

test( 'collect API contract: token gate, strict schema, body ceiling, rate limit', async ( { page, request } ) => {
	const { url, token } = await probeData( page );
	const post = ( data: object ) => request.post( url, { data } );

	// Token gate: a missing token and a forged token never reach the
	// schema stage.
	expect( ( await post( { name: 'pageview' } ) ).status() ).toBe( 401 );
	const flipped = ( token.endsWith( 'a' ) ? token.slice( 0, -1 ) + 'b' : token.slice( 0, -1 ) + 'a' );
	expect( ( await post( { token: flipped, name: 'pageview' } ) ).status() ).toBe( 401 );

	// Strict schema: unknown event name, unknown field, out-of-range
	// bot score.
	expect( ( await post( { token, name: 'gr_bogus' } ) ).status() ).toBe( 400 );
	expect( ( await post( { token, name: 'pageview', evil: 'x' } ) ).status() ).toBe( 400 );
	expect( ( await post( { token, name: 'pageview', bot_score: 999 } ) ).status() ).toBe( 400 );

	// Happy path: stored flag present, no-cache on the response.
	const ok = await post( { token, name: 'pageview', path: '/', event_id: 'ci-1' } );
	expect( ok.status() ).toBe( 200 );
	const body = await ok.json();
	expect( typeof body.stored ).toBe( 'boolean' );
	expect( ( ok.headers()[ 'cache-control' ] ?? '' ) ).toContain( 'no-cache' );

	// Body ceiling: the gate rejects an 8KB+ payload with 413 before
	// schema validation runs (its content is deliberately garbage).
	expect( ( await post( { token, name: 'pageview', evil: 'x'.repeat( 9000 ) } ) ).status() ).toBe( 413 );

	// Rate limiter: a burst past the 60/minute allowance is shed with
	// 429. Loop until the first 429 arrives; 70 is comfortably above
	// the allowance even counting the requests above.
	let saw429 = false;
	for ( let i = 0; i < 70; i++ ) {
		const r = await post( { token, name: 'pageview', path: '/', event_id: `ci-r${ i }` } );
		if ( r.status() === 429 ) {
			saw429 = true;
			break;
		}
	}
	expect( saw429 ).toBe( true );
} );
