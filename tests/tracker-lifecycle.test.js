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
		__cvtrkEarly: options.early,
		performance: options.performance,
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

test( 'initial pageshow does not send an extra heartbeat or pageview', () => {
	const harness = makeHarness();
	const initialRequests = harness.requests.length;
	const initialPageviews = harness.api.getState().queue.length;
	assert.equal( initialRequests, 1 );
	harness.listeners.window.pageshow( { persisted: false } );
	harness.listeners.window.pageshow();
	assert.equal( harness.requests.length, initialRequests );
	assert.equal( harness.api.getState().queue.length, initialPageviews );
} );

test( 'regular analytics uses normal fetch while pagehide keeps the keepalive fallback', () => {
	const harness = makeHarness();
	assert.equal( harness.requests[ 0 ].url, harness.config.heartbeatUrl );
	assert.equal( harness.requests[ 0 ].init.keepalive, false );
	harness.api.flush();
	assert.equal( harness.requests[ 1 ].url, harness.config.collectUrl );
	assert.equal( harness.requests[ 1 ].init.keepalive, false );
	harness.requests.length = 0;
	harness.listeners.window.pagehide();
	assert.ok( harness.requests.length > 0 );
	for ( const request of harness.requests ) {
		assert.equal( request.init.keepalive, true );
	}
} );

test( 'successful beacon delivery does not also send a fetch on pagehide', () => {
	const beacons = [];
	const harness = makeHarness( { beacon: ( url, body ) => {
		beacons.push( { url, body } );
		return true;
	} } );
	const initialRequests = harness.requests.length;
	harness.listeners.window.pagehide();
	assert.ok( beacons.length > 0 );
	assert.equal( harness.requests.length, initialRequests );
} );

for ( const bodyReadFails of [ false, true ] ) {
	test( 'response bodies are consumed without retrying delivered requests (read fails: ' + bodyReadFails + ')', async () => {
		let bodyReads = 0;
		const harness = makeHarness( {
			fetch: () => Promise.resolve( { ok: true, status: 200, text() {
				bodyReads++;
				return bodyReadFails ? Promise.reject( new Error( 'Body read failed' ) ) : Promise.resolve( '{}' );
			} } )
		} );
		harness.api.flush();
		await new Promise( ( resolve ) => setImmediate( resolve ) );
		assert.equal( bodyReads, 2 );
		assert.equal( harness.api.getState().retries.length, 0 );
	} );
}

/* Heatmap click accuracy (2.13) ------------------------------------------ */

const earlySource = fs.readFileSync( path.join( __dirname, '..', 'public', 'js', 'convertrack-early.js' ), 'utf8' );

function fakeElement( tag, options ) {
	options = options || {};
	const matches = options.matches || [ tag ];
	return {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		id: options.id || '',
		type: options.type || '',
		ownerDocument: {},
		parentNode: null,
		textContent: options.text || '',
		isContentEditable: false,
		getAttribute( name ) {
			return options.attrs && options.attrs[ name ] !== undefined ? options.attrs[ name ] : null;
		},
		matches( selector ) {
			const parts = String( selector ).split( ',' ).map( ( part ) => part.trim() );
			return matches.some( ( candidate ) => parts.includes( candidate ) );
		},
		closest( selector ) {
			return this.matches( selector ) ? this : null;
		},
		getBoundingClientRect() {
			return options.rect || { left: 100, top: 200, width: 200, height: 50 };
		}
	};
}

test( 'script-generated and coordinate-less clicks are ignored unless they activate a tracked control', () => {
	const harness = makeHarness( { config: { selectors: [ 'a' ] } } );
	const api = harness.api;
	api.takeBatch( 60000 ); // discard the initial pageview fixture.
	const click = harness.listeners.document.click;
	const content = fakeElement( 'div' );
	const link = fakeElement( 'a', { attrs: { href: '#top' }, text: 'Book now' } );

	// element.click() / dispatchEvent from a chat widget: not a visitor click.
	click( { target: content, isTrusted: false, detail: 1, clientX: 10, clientY: 10, pageX: 10, pageY: 10 } );
	// A trusted "click" on plain content with no pointer position (synthetic).
	click( { target: content, isTrusted: true, detail: 0, clientX: 0, clientY: 0, pageX: 0, pageY: 0 } );
	assert.equal( api.getState().queue.length, 0 );

	// A real tap on content becomes a positioned heatmap-only click.
	click( { target: content, isTrusted: true, detail: 1, clientX: 300, clientY: 150, pageX: 300, pageY: 150 } );
	let queued = api.getState().queue;
	assert.equal( queued.length, 1 );
	assert.equal( queued[ 0 ].t, 'heatmap_click' );
	assert.equal( queued[ 0 ].hsel, 'div' );
	assert.equal( queued[ 0 ].cx, 250 );
	assert.equal( queued[ 0 ].cy, 63 );

	// Keyboard activation of a tracked link is still a click, without a position.
	click( { target: link, isTrusted: true, detail: 0, clientX: 0, clientY: 0, pageX: 0, pageY: 0 } );
	queued = api.getState().queue;
	assert.equal( queued.length, 2 );
	assert.equal( queued[ 1 ].t, 'click' );
	assert.equal( queued[ 1 ].txt, 'Book now' );
	assert.equal( queued[ 1 ].cx, 0 );
	assert.equal( queued[ 1 ].cy, 0 );
	assert.equal( api.hasPointer( { detail: 0, clientX: 0, clientY: 0 } ), false );
	assert.equal( api.hasPointer( { detail: 1, clientX: 0, clientY: 0 } ), true );
} );

test( 'clicks inside ignored elements are never recorded', () => {
	const harness = makeHarness( { config: { selectors: [ 'a' ], ignoreSelectors: [ '#cookie-banner' ] } } );
	harness.api.takeBatch( 60000 );
	const banner = fakeElement( 'a', { matches: [ 'a', '#cookie-banner' ], text: 'Accept', attrs: { href: '#' } } );
	harness.listeners.document.click( { target: banner, isTrusted: true, detail: 1, clientX: 20, clientY: 20, pageX: 20, pageY: 20 } );
	assert.equal( harness.api.getState().queue.length, 0 );
} );

test( 'clicks buffered before the tracker booted are replayed with their original geometry and time', () => {
	let stopped = false;
	const link = fakeElement( 'a', { attrs: { href: '/book/' }, text: 'Book' } );
	const early = [ {
		el: link, cx: 50, cy: 20, px: 50, py: 20, detail: 1, ts: 1699999990000,
		vw: 800, vh: 600, dw: 800, dh: 1600, sx: 0, sy: 0, rect: { l: 0, t: 0, w: 100, h: 40 }
	} ];
	early.stop = () => { stopped = true; };
	const harness = makeHarness( { config: { selectors: [ 'a' ] }, early } );
	const queued = harness.api.getState().queue;
	assert.equal( queued.length, 2 );
	assert.equal( queued[ 0 ].t, 'pageview' );
	assert.equal( queued[ 1 ].t, 'click' );
	assert.equal( queued[ 1 ].ts, 1699999990000 );
	assert.equal( queued[ 1 ].cx, 63 );
	assert.equal( queued[ 1 ].cy, 13 );
	assert.equal( queued[ 1 ].rx, 500 );
	assert.equal( queued[ 1 ].ry, 500 );
	assert.equal( queued[ 1 ].vw, 800 );
	assert.equal( queued[ 1 ].dh, 1600 );
	assert.equal( stopped, true );
	assert.equal( harness.window.__cvtrkEarly, null );
	// A navigating link that was replayed must not trigger an unload-style flush.
	assert.equal( harness.requests.filter( ( request ) => request.url === harness.config.collectUrl ).length, 0 );
} );

test( 'the first pageview is stamped with the navigation start when it is recent', () => {
	const recent = makeHarness( { performance: { timeOrigin: 1700000000000 - 3000 } } );
	assert.equal( recent.api.getState().queue[ 0 ].ts, 1700000000000 - 3000 );
	const stale = makeHarness( { performance: { timeOrigin: 1700000000000 - 3600000 } } );
	assert.equal( stale.api.getState().queue[ 0 ].ts, 1700000000000 );
	const none = makeHarness();
	assert.equal( none.api.getState().queue[ 0 ].ts, 1700000000000 );
} );

test( 'the inline early-click bootstrap buffers trusted clicks and stops cleanly', () => {
	const handlers = {};
	const document = {
		documentElement: { clientWidth: 1000, clientHeight: 700, scrollWidth: 1000, scrollHeight: 3000, scrollLeft: 0, scrollTop: 0 },
		body: { scrollWidth: 1000, scrollHeight: 3000 },
		addEventListener( type, handler, capture ) {
			handlers[ type ] = { handler, capture };
		},
		removeEventListener( type ) {
			delete handlers[ type ];
		}
	};
	const window = { innerWidth: 1000, innerHeight: 700, pageXOffset: 0, pageYOffset: 120 };
	vm.runInNewContext( earlySource, { document, window, Date, Math }, { filename: 'convertrack-early.js' } );
	assert.ok( handlers.click && handlers.click.capture === true );
	const target = { nodeType: 1, getBoundingClientRect: () => ( { left: 10, top: 20, width: 100, height: 40 } ) };
	handlers.click.handler( { target, isTrusted: false, detail: 1, clientX: 5, clientY: 5 } );
	handlers.click.handler( { target, isTrusted: true, detail: 1, clientX: 50, clientY: 60 } );
	const buffer = window.__cvtrkEarly;
	assert.equal( buffer.length, 1 );
	assert.equal( buffer[ 0 ].el, target );
	assert.equal( buffer[ 0 ].px, 50 );
	assert.equal( buffer[ 0 ].py, 180 );
	assert.equal( buffer[ 0 ].dh, 3000 );
	assert.equal( buffer[ 0 ].sy, 120 );
	// The buffer object was created inside the vm realm, so compare by value.
	assert.equal( JSON.stringify( buffer[ 0 ].rect ), JSON.stringify( { l: 10, t: 20, w: 100, h: 40 } ) );
	for ( let i = 0; i < 60; i++ ) {
		handlers.click.handler( { target, isTrusted: true, detail: 1, clientX: 1, clientY: 1 } );
	}
	assert.equal( buffer.length, 50 );
	buffer.stop();
	assert.equal( handlers.click, undefined );
	assert.equal( buffer.done, true );
} );
