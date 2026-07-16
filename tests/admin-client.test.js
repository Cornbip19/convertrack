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
