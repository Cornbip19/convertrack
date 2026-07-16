'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const trackerSource = fs.readFileSync( path.join( __dirname, '..', 'public', 'js', 'convertrack.js' ), 'utf8' );

class MemoryStorage {
	constructor( seed ) {
		this.data = Object.assign( {}, seed || {} );
	}
	get length() {
		return Object.keys( this.data ).length;
	}
	key( index ) {
		return Object.keys( this.data )[ index ] || null;
	}
	getItem( key ) {
		return Object.prototype.hasOwnProperty.call( this.data, key ) ? String( this.data[ key ] ) : null;
	}
	setItem( key, value ) {
		this.data[ key ] = String( value );
	}
	removeItem( key ) {
		delete this.data[ key ];
	}
}

function makeHarness( options ) {
	options = options || {};
	let now = options.now || 1700000000000;
	let uuidSeed = 1;
	let timerId = 1;
	const listeners = { document: {}, window: {} };
	const timers = [];
	const requests = [];
	const storage = options.storage || new MemoryStorage( options.seed );
	const currentUrl = new URL( options.url || 'https://example.test/shop/?page=2&email=person%40example.test&token=secret' );

	class FakeDate extends Date {
		static now() {
			return now;
		}
	}

	function anchor() {
		const out = {};
		Object.defineProperty( out, 'href', {
			set( value ) {
				const parsed = new URL( value, currentUrl.href );
				out.protocol = parsed.protocol;
				out.hostname = parsed.hostname;
				out.host = parsed.host;
				out.pathname = parsed.pathname;
				out.search = parsed.search;
			},
			get() {
				return out.protocol + '//' + out.host + out.pathname + out.search;
			}
		} );
		return out;
	}

	const documentElement = {
		clientWidth: 1200,
		clientHeight: 600,
		scrollWidth: 1200,
		scrollHeight: 2400,
		scrollLeft: 0,
		scrollTop: 0
	};
	const body = {
		clientWidth: 1200,
		clientHeight: 600,
		scrollWidth: 1200,
		scrollHeight: 2400
	};
	const document = {
		visibilityState: 'visible',
		title: 'Safe page',
		referrer: '',
		documentElement,
		body,
		addEventListener( type, handler ) {
			listeners.document[ type ] = handler;
		},
		querySelector() {
			return null;
		},
		createElement( tag ) {
			return tag === 'a' ? anchor() : {};
		}
	};

	const config = Object.assign( {
		collectUrl: 'https://example.test/wp-json/convertrack/v1/collect',
		heartbeatUrl: 'https://example.test/wp-json/convertrack/v1/heartbeat',
		collectorToken: 'collector-token',
		postId: 42,
		pageKey: 'post:42',
		objectType: 'post',
		objectId: 42,
		pageIdentityToken: 'signed-page-identity',
		sampleRate: 100,
		sessionTtl: 60,
		batchMax: 3,
		heartbeat: 15000,
		flush: 5000,
		selectors: [],
		conversionSelectors: [],
		conversionUrls: [],
		allowedQueryParams: [ 'page' ],
		trackSearchKeywords: false,
		respectDnt: false
	}, options.config || {} );

	const crypto = {
		getRandomValues( bytes ) {
			for ( let i = 0; i < bytes.length; i++ ) {
				bytes[ i ] = ( uuidSeed + i ) & 255;
			}
			uuidSeed += 17;
			return bytes;
		}
	};
	const navigator = {
		userAgent: 'Unit Test Desktop',
		maxTouchPoints: 0,
		doNotTrack: '0',
		globalPrivacyControl: false,
		sendBeacon: options.beacon || ( () => false )
	};
	const window = {
		ConvertrackConfig: config,
		__CONVERTRACK_TEST__: true,
		localStorage: storage,
		Date: FakeDate,
		TextEncoder,
		crypto,
		navigator,
		document,
		location: currentUrl,
		innerWidth: 1200,
		innerHeight: 600,
		pageXOffset: 0,
		pageYOffset: 0,
		addEventListener( type, handler ) {
			listeners.window[ type ] = handler;
		},
		setInterval() {
			return timerId++;
		},
		setTimeout( handler, delay ) {
			timers.push( { handler, delay } );
			return timerId++;
		}
	};
	const context = {
		window,
		document,
		navigator,
		location: currentUrl,
		Date: FakeDate,
		TextEncoder,
		crypto,
		Blob: class Blob {
			constructor( chunks, metadata ) {
				this.chunks = chunks;
				this.type = metadata && metadata.type;
			}
		},
		fetch( url, init ) {
			requests.push( { url, init } );
			return options.fetch ? options.fetch( url, init ) : Promise.resolve( { ok: true, status: 200 } );
		},
		URL,
		console
	};
	vm.runInNewContext( trackerSource, context, { filename: 'convertrack.js' } );

	return {
		api: window.__ConvertrackTest,
		config,
		listeners,
		requests,
		storage,
		timers,
		window,
		advance( milliseconds ) {
			now += milliseconds;
		}
	};
}

test( 'privacy URL handling removes sensitive query values and redacts credential paths', () => {
	const { api } = makeHarness();
	assert.equal( api.safeQuery( '?page=3&email=person%40example.test&token=abcdef' ), '?page=3' );
	assert.equal(
		api.privacySafeUrl( '/reset-password/0123456789abcdef0123456789abcdef?email=x%40y.test&page=3#private' ),
		'/reset-password/redacted?page=3'
	);
	assert.equal( api.privacySafeUrl( 'javascript:alert(1)' ), '' );
} );

test( 'stable sampling bucket adapts deterministically when the configured rate changes', () => {
	const storage = new MemoryStorage( { cvtrk_smp_bucket: '500000' } );
	const excluded = makeHarness( { storage, config: { sampleRate: 25 } } );
	assert.equal( excluded.api.sampleBucket, 500000 );
	assert.equal( excluded.api.sampledIn( 25, excluded.api.sampleBucket ), false );

	const included = makeHarness( { storage, config: { sampleRate: 75 } } );
	assert.equal( included.api.sampleBucket, 500000 );
	assert.equal( included.api.sampledIn( 75, included.api.sampleBucket ), true );
	assert.equal( storage.getItem( 'cvtrk_smp' ), null );
} );

test( 'expired BFCache resume rotates the session, acquisition, pageview and scroll state', () => {
	const storage = new MemoryStorage( {
		cvtrk_acq_old_one: JSON.stringify( { src: 'Old' } ),
		cvtrk_acq_old_two: JSON.stringify( { src: 'Old' } )
	} );
	const harness = makeHarness( { storage } );
	const first = harness.api.getState();
	assert.match( first.sessionId, /^[0-9a-f-]{36}$/ );
	assert.equal( first.queue[ 0 ].t, 'pageview' );
	assert.match( first.queue[ 0 ].eid, /^[0-9a-f-]{36}$/ );

	harness.advance( 61001 );
	harness.listeners.window.pageshow( { persisted: true } );
	const resumed = harness.api.getState();
	assert.notEqual( resumed.sessionId, first.sessionId );
	assert.equal( resumed.queue.length, 1 );
	assert.equal( resumed.queue[ 0 ].t, 'pageview' );
	assert.equal( resumed.queue[ 0 ].pk, 'post:42' );
	assert.equal( resumed.queue[ 0 ].pit, 'signed-page-identity' );
	assert.equal( resumed.lastSentScroll, 0 );
	assert.ok( storage.getItem( 'cvtrk_acq' ) );
	assert.equal( storage.getItem( 'cvtrk_acq_old_one' ), null );
	assert.equal( storage.getItem( 'cvtrk_acq_old_two' ), null );
} );

test( 'scroll events emit only when the maximum depth increases', () => {
	const harness = makeHarness();
	harness.api.takeBatch( 60000 );
	harness.api.recordScroll();
	let queued = harness.api.getState().queue;
	assert.equal( queued.length, 1 );
	assert.equal( queued[ 0 ].sd, 25 );
	harness.api.takeBatch( 60000 );

	harness.window.pageYOffset = 1200;
	harness.api.recordScroll();
	harness.api.recordScroll();
	queued = harness.api.getState().queue;
	assert.equal( queued.length, 1 );
	assert.equal( queued[ 0 ].sd, 75 );
} );

test( 'conversion URL rules support explicit modes and normalize legacy lines to exact', () => {
	const { api } = makeHarness();
	assert.equal( api.conversionUrlMatches( '/thanks', 'exact:/thanks' ), true );
	assert.equal( api.conversionUrlMatches( '/thanks/receipt', 'exact:/thanks' ), false );
	assert.equal( api.conversionUrlMatches( '/thanks/receipt', 'prefix:/thanks/' ), true );
	assert.equal( api.conversionUrlMatches( '/orders/1234/done', 'regex:^/orders/[0-9]+/done$' ), true );
	assert.equal( api.conversionUrlMatches( '/orders/1234/done', 'regex:(a+)+$' ), false );
	assert.equal( api.conversionUrlMatches( '/thank-you/', '/thank-you/' ), true );
	assert.equal( api.conversionUrlMatches( '/shop/thank-you/receipt', '/thank-you/' ), false );
} );

test( 'event batching, unload delivery and retry queues remain byte and count bounded', () => {
	const harness = makeHarness();
	const api = harness.api;
	api.takeBatch( 60000 ); // discard the initial pageview fixture.
	for ( let i = 0; i < 120; i++ ) {
		api.enqueue( { t: 'heatmap_click', eid: String( i ), data: 'x'.repeat( 40 ) } );
	}
	assert.equal( api.getState().queue.length, 100 );

	harness.listeners.window.pagehide();
	const collectRequests = harness.requests.filter( ( request ) => request.url === harness.config.collectUrl );
	assert.ok( collectRequests.length > 0 );
	for ( const request of collectRequests ) {
		assert.ok( Buffer.byteLength( request.init.body, 'utf8' ) <= 60000 );
		assert.ok( JSON.parse( request.init.body ).events.length <= harness.config.batchMax );
	}

	for ( let i = 0; i < 12; i++ ) {
		api.scheduleRetry( '/collect', { events: [ { eid: i } ] }, 1000, 0 );
	}
	assert.equal( api.getState().retries.length, 8 );
	api.scheduleRetry( '/collect', { events: [] }, 1000, 2 );
	assert.equal( api.getState().retries.length, 8 );
	api.drainRetry();
	assert.equal( api.getState().retries.length, 7 );
} );

test( 'retryable transport failures enqueue one bounded retry attempt', async () => {
	const harness = makeHarness( {
		fetch: () => Promise.resolve( { ok: false, status: 503 } )
	} );
	harness.api.flush();
	await Promise.resolve();
	await Promise.resolve();
	const retries = harness.api.getState().retries;
	assert.equal( retries.length, 1 );
	assert.equal( retries[ 0 ].attempt, 1 );
} );
