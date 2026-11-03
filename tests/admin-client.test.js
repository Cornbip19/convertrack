'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '..', 'admin', 'js', 'admin.js' ), 'utf8' );

function loadClient( fetchImpl ) {
	const window = {
		ConvertrackAdmin: {
			root: 'https://example.test/wp-json/convertrack/v1',
			nonce: 'test-nonce',
			testMode: true,
			i18n: {
				loadError: 'Something went wrong while loading this data.',
				networkError: 'The server could not be reached. Please try again.'
			}
		},
		setTimeout: ( callback, delay ) => {
			// Run retry backoff immediately while leaving the request timeout idle.
			if ( Number( delay ) < 1000 ) {
				callback();
			}
			return 1;
		},
		clearTimeout: () => {},
		console
	};
	const context = vm.createContext( {
		window,
		document: {},
		fetch: fetchImpl,
		console,
		Error,
		JSON,
		Math,
		Number,
		Promise,
		String
	} );
	vm.runInContext( source, context, { filename: 'admin.js' } );
	return window.__ConvertrackAdminTest;
}

function response( ok, status, body ) {
	return {
		ok,
		status,
		json: () => Promise.resolve( body )
	};
}

test( 'clear removes every child and is always a callable helper', () => {
	const client = loadClient( () => Promise.resolve( response( true, 200, {} ) ) );
	const node = {
		children: [ 1, 2, 3 ],
		get firstChild() {
			return this.children[ 0 ] || null;
		},
		removeChild() {
			this.children.shift();
		}
	};

	assert.equal( typeof client.clear, 'function' );
	client.clear( node );
	assert.deepEqual( node.children, [] );
} );

test( 'renderer exceptions are replaced with the safe generic load error', () => {
	const client = loadClient( () => Promise.resolve( response( true, 200, {} ) ) );
	assert.equal(
		client.errorMessage( new TypeError( 'clear is not a function' ) ),
		'Something went wrong while loading this data.'
	);
} );

test( 'GET retries one transient HTTP failure and returns recovered data', async () => {
	let calls = 0;
	const client = loadClient( () => {
		calls++;
		return Promise.resolve(
			calls === 1
				? response( false, 503, { message: 'Temporarily unavailable', code: 'rest_unavailable' } )
				: response( true, 200, { recovered: true } )
		);
	} );

	assert.deepEqual( await client.api( '/stats/overview' ), { recovered: true } );
	assert.equal( calls, 2 );
} );

test( 'unexpected network details never reach the public error message', async () => {
	const client = loadClient( () => Promise.reject( new TypeError( 'internal socket detail' ) ) );

	await assert.rejects(
		client.api( '/stats/overview', { retries: 0 } ),
		( error ) => {
			assert.equal( error.cvtrkPublic, true );
			assert.equal( error.code, 'network_error' );
			assert.equal( error.message, 'The server could not be reached. Please try again.' );
			assert.equal( error.message.includes( 'socket' ), false );
			return true;
		}
	);
} );

test( 'unlabelled heatmap rows are named by the kind of element that was clicked', () => {
	const client = loadClient( () => Promise.resolve( response( true, 200, {} ) ) );
	assert.equal( client.unlabelledLabel( 'body#top>chat-widget' ), 'Unlabelled chat widget' );
	assert.equal( client.unlabelledLabel( 'main>section:nth-of-type(2)>img' ), 'Unlabelled image' );
	assert.equal( client.unlabelledLabel( 'div#hero>p:nth-of-type(1)' ), 'Unlabelled text' );
	assert.equal( client.unlabelledLabel( 'section#services>div>div:nth-of-type(3)>button' ), 'Unlabelled button' );
	assert.equal( client.unlabelledLabel( 'custom-thing' ), 'Unlabelled custom-thing' );
	assert.equal( client.unlabelledLabel( '' ), 'Unlabelled element' );
} );

test( 'heat colours run from blue for one click to red for the busiest spot', () => {
	const client = loadClient( () => Promise.resolve( response( true, 200, {} ) ) );
	// Arrays come from the vm realm, so compare by value.
	const rgb = ( value ) => JSON.stringify( client.heatColor( value ) );
	assert.equal( rgb( 0 ), '[36,99,235]' );
	assert.equal( rgb( 1 ), '[225,50,30]' );
	assert.equal( rgb( 2 ), '[225,50,30]' );
	assert.equal( rgb( 0.6 ), '[120,200,60]' );
	const between = client.heatColor( 0.175 );
	assert.ok( between[ 0 ] > 36 && between[ 0 ] < 42 + 1 && between[ 2 ] < 235 && between[ 2 ] > 190 );
} );

test( 'insight sentences are built from the server figures in plain language', () => {
	const client = loadClient( () => Promise.resolve( response( true, 200, {} ) ) );
	assert.equal(
		client.insightText( { kind: 'top', label: 'Book Online', selector: 'nav>a:nth-of-type(3)', visitors: 9, share: 14.1 } ),
		'"Book Online" is the most used element: 14.1% of visitors pressed it (9 people).'
	);
	assert.equal( client.insightText( { kind: 'fold', share: 48 } ), '48% of clicks happen on the first screen, before anyone scrolls.' );
	assert.equal( client.insightText( { kind: 'scroll', band: 40, samples: 80 } ), 'Half of visitors stop before 50% of the page. Anything below that is seen by fewer than half.' );
	assert.equal( client.insightText( { kind: 'scroll', band: 100, samples: 80 } ), 'More than half of visitors scroll all the way to the bottom.' );
	assert.equal( client.insightText( { kind: 'scroll', band: 0, samples: 80 } ), 'Half of visitors never scroll past the first screen.' );
	assert.equal(
		client.insightText( { kind: 'dead', label: '', selector: 'main>section:nth-of-type(2)>img', visitors: 7, clicks: 12 } ),
		'"Unlabelled image" is not clickable, yet 7 people clicked it (12 clicks). If it looks like a button, make it one.'
	);
	assert.equal( client.insightText( { kind: 'rage', label: 'See more', selector: 'section>button', sessions: 3, clicks: 14 } ), '"See more" was clicked four or more times in a row by 3 visitors, a sign it did not respond quickly.' );
	assert.equal( client.insightText( { kind: 'device', device: 'mobile', share: 85 }, 'desktop' ), '85% of clicks on this page come from mobile visitors; switch the map to that device first.' );
	assert.equal( client.insightText( { kind: 'device', device: 'mobile', share: 85 }, 'mobile' ), '85% of clicks on this page come from mobile visitors, which is the device shown now.' );
	assert.equal( client.insightText( { kind: 'unknown' } ), '' );
	assert.equal( client.insightText( null ), '' );
} );
