/**
 * Convertrack front-end tracker.
 *
 * Captures clicks on configured "button-like" elements, records a pageview,
 * and sends lightweight presence heartbeats. Events are batched and delivered
 * with navigator.sendBeacon so tracking never blocks navigation.
 */
( function () {
	'use strict';

	var cfg = window.ConvertrackConfig;
	if ( ! cfg || ! cfg.collectUrl ) {
		return;
	}

	/* ----------------------------------------------------------------- *
	 * Opt-outs
	 * ----------------------------------------------------------------- */

	function dntEnabled() {
		var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
		return dnt === '1' || dnt === 'yes';
	}

	if ( cfg.respectDnt && dntEnabled() ) {
		return;
	}

	var store = safeStorage();

	// Stable per-visitor sampling decision.
	if ( cfg.sampleRate < 100 ) {
		var decision = store.get( 'cvtrk_smp' );
		if ( decision === null ) {
			decision = Math.random() * 100 < cfg.sampleRate ? '1' : '0';
			store.set( 'cvtrk_smp', decision );
		}
		if ( decision !== '1' ) {
			return;
		}
	}

	/* ----------------------------------------------------------------- *
	 * Identity
	 * ----------------------------------------------------------------- */

	var visitorId = store.get( 'cvtrk_vid' );
	if ( ! isUuid( visitorId ) ) {
		visitorId = uuid();
		store.set( 'cvtrk_vid', visitorId );
	}

	var sessionId = resolveSession();

	/* ----------------------------------------------------------------- *
	 * State + selectors
	 * ----------------------------------------------------------------- */

	var queue = [];
	var device = detectDevice();
	var source = detectSource();
	var selector = buildSelector( cfg.selectors );
	var batchMax = cfg.batchMax > 0 ? cfg.batchMax : 25;
	var maxScroll = 0;
	var scrollSent = false;
	var scrollTick = false;

	/* ----------------------------------------------------------------- *
	 * Event capture
	 * ----------------------------------------------------------------- */

	// Capture phase so we record the click even if a handler calls stopPropagation.
	document.addEventListener( 'click', onClick, true );

	function onClick( e ) {
		if ( ! selector ) {
			return;
		}
		var target = e.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var el;
		try {
			el = target.closest( selector );
		} catch ( err ) {
			return;
		}
		if ( ! el ) {
			return;
		}

		var href = el.getAttribute ? ( el.getAttribute( 'href' ) || '' ) : '';
		var coords = clickPosition( e, el );
		queue.push( {
			t: 'click',
			pid: cfg.postId || 0,
			url: currentPath(),
			title: docTitle(),
			tag: ( el.tagName || '' ).toLowerCase(),
			id: el.id || '',
			cls: elementClasses( el ),
			txt: elementText( el ),
			sel: cssPath( el ),
			href: href,
			conv: isConversion( el, href ) ? 1 : 0,
			dev: device,
			src: source.src,
			rh: source.rh,
			us: source.us,
			um: source.um,
			uc: source.uc,
			cx: coords.cx,
			cy: coords.cy,
			rx: coords.rx,
			ry: coords.ry,
			vw: coords.vw,
			vh: coords.vh,
			dw: coords.dw,
			dh: coords.dh,
			sx: coords.sx,
			sy: coords.sy
		} );

		// Flush immediately if this click is likely to navigate away, so the
		// event is delivered before unload instead of relying on pagehide alone.
		var navigates = ( !! href && href.charAt( 0 ) !== '#' && href.toLowerCase().indexOf( 'javascript:' ) !== 0 ) ||
			el.type === 'submit';

		if ( navigates || queue.length >= batchMax ) {
			flush();
		}
	}

	/* ----------------------------------------------------------------- *
	 * Pageview + heartbeat
	 * ----------------------------------------------------------------- */

	queue.push( {
		t: 'pageview',
		pid: cfg.postId || 0,
		url: currentPath(),
		title: docTitle(),
		tag: '',
		id: '',
		cls: '',
		txt: '',
		sel: '',
		href: '',
		conv: isConversionUrl( currentPath() ) ? 1 : 0,
		dev: device,
		src: source.src,
		rh: source.rh,
		us: source.us,
		um: source.um,
		uc: source.uc
	} );

	heartbeat();
	var heartbeatTimer = window.setInterval( heartbeat, Math.max( 5000, cfg.heartbeat || 15000 ) );
	var flushTimer = window.setInterval( flush, Math.max( 1000, cfg.flush || 5000 ) );

	// Flush reliably when the page is being hidden or unloaded.
	updateScroll();
	window.addEventListener( 'scroll', onScroll, { passive: true } );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			recordScroll();
			flush();
		} else {
			heartbeat();
		}
	} );
	window.addEventListener( 'pagehide', function () {
		recordScroll();
		flush();
	} );

	/* ----------------------------------------------------------------- *
	 * Transport
	 * ----------------------------------------------------------------- */

	function flush() {
		if ( ! queue.length ) {
			return;
		}
		touchSession();
		var batch = queue.splice( 0, batchMax );
		send( cfg.collectUrl, {
			v: '1',
			vid: visitorId,
			sid: sessionId,
			events: batch
		} );
	}

	function heartbeat() {
		touchSession();
		send( cfg.heartbeatUrl, {
			vid: visitorId,
			sid: sessionId,
			url: currentPath(),
			pid: cfg.postId || 0
		} );
	}

	function send( url, payload ) {
		var body = JSON.stringify( payload );
		try {
			if ( navigator.sendBeacon ) {
				var blob = new Blob( [ body ], { type: 'application/json' } );
				if ( navigator.sendBeacon( url, blob ) ) {
					return;
				}
			}
		} catch ( err ) {} // eslint-disable-line no-empty

		// Fallback for browsers without a working sendBeacon.
		try {
			fetch( url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'omit'
			} ).catch( function () {} );
		} catch ( err2 ) {} // eslint-disable-line no-empty
	}

	/* ----------------------------------------------------------------- *
	 * Session handling (sliding 30-min window)
	 * ----------------------------------------------------------------- */

	function resolveSession() {
		var ttl = ( cfg.sessionTtl || 1800 ) * 1000;
		var raw = store.get( 'cvtrk_sid' );
		var now = nowMs();
		if ( raw ) {
			var parts = raw.split( '|' );
			if ( parts.length === 2 && isUuid( parts[0] ) && ( now - parseInt( parts[1], 10 ) ) < ttl ) {
				store.set( 'cvtrk_sid', parts[0] + '|' + now );
				return parts[0];
			}
		}
		var id = uuid();
		store.set( 'cvtrk_sid', id + '|' + now );
		return id;
	}

	function touchSession() {
		store.set( 'cvtrk_sid', sessionId + '|' + nowMs() );
	}

	/* ----------------------------------------------------------------- *
	 * Element helpers
	 * ----------------------------------------------------------------- */

	function buildSelector( list ) {
		if ( ! list || ! list.length ) {
			return '';
		}
		var valid = [];
		for ( var i = 0; i < list.length; i++ ) {
			var sel = String( list[ i ] ).trim();
			if ( ! sel ) {
				continue;
			}
			try {
				document.querySelector( sel ); // throws on invalid selector
				valid.push( sel );
			} catch ( err ) {} // eslint-disable-line no-empty
		}
		return valid.join( ',' );
	}

	function matchesSafe( el, sel ) {
		if ( ! el || ! el.matches ) {
			return false;
		}
		try {
			return el.matches( sel );
		} catch ( err ) {
			return false;
		}
	}

	function elementClasses( el ) {
		var cls = el.getAttribute ? ( el.getAttribute( 'class' ) || '' ) : '';
		return cls.substring( 0, 255 );
	}

	function elementText( el ) {
		var txt = '';
		if ( el.value && typeof el.value === 'string' ) {
			txt = el.value;
		} else if ( el.textContent ) {
			txt = el.textContent;
		} else if ( el.getAttribute ) {
			txt = el.getAttribute( 'aria-label' ) || el.getAttribute( 'title' ) || '';
		}
		return txt.replace( /\s+/g, ' ' ).trim().substring( 0, 200 );
	}

	function tagOf( node ) {
		return ( node && node.tagName ) ? node.tagName.toLowerCase() : '';
	}

	// Only keep ids that look human-authored. Auto-generated ids (long digit
	// runs, very long strings) would explode the rollup table's cardinality.
	function stableId( node ) {
		var id = ( node && node.id ) ? String( node.id ) : '';
		if ( ! id || id.length > 40 || /\d{4,}/.test( id ) ) {
			return '';
		}
		return id;
	}

	function cssPath( el ) {
		var elId = stableId( el );
		if ( elId ) {
			return ( tagOf( el ) + '#' + elId ).substring( 0, 255 );
		}
		var parts = [];
		var node = el;
		var depth = 0;
		while ( node && node.nodeType === 1 && depth < 4 ) {
			var sel = tagOf( node );
			var nodeId = stableId( node );
			if ( nodeId ) {
				parts.unshift( sel + '#' + nodeId );
				break;
			}
			var parent = node.parentNode;
			if ( parent && parent.children ) {
				var same = [];
				for ( var i = 0; i < parent.children.length; i++ ) {
					if ( parent.children[ i ].tagName === node.tagName ) {
						same.push( parent.children[ i ] );
					}
				}
				if ( same.length > 1 ) {
					sel += ':nth-of-type(' + ( same.indexOf( node ) + 1 ) + ')';
				}
			}
			parts.unshift( sel );
			node = parent;
			depth++;
		}
		return parts.join( '>' ).substring( 0, 255 );
	}

	function isConversion( el, href ) {
		// An internal link to a goal URL is counted on its destination pageview,
		// so never flag the click for it — even when the element also matches a
		// conversion selector. This keeps one goal completion = one conversion.
		var internalGoal = !! href && ! isExternalHref( href ) && isConversionUrl( href );

		var list = cfg.conversionSelectors || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( matchesSafe( el, list[ i ] ) ) {
				return ! internalGoal;
			}
		}
		// URL goals are otherwise only counted on the click when the link leaves
		// the site, since no pageview will fire here to count it.
		return isExternalHref( href ) && isConversionUrl( href );
	}

	// True when href points to a different host (so it will not produce a
	// tracked pageview on this site).
	function isExternalHref( href ) {
		if ( ! href ) {
			return false;
		}
		var h = href.toLowerCase();
		if ( h.charAt( 0 ) === '#' || h.indexOf( 'javascript:' ) === 0 || h.indexOf( 'mailto:' ) === 0 || h.indexOf( 'tel:' ) === 0 ) {
			return false;
		}
		try {
			var a = document.createElement( 'a' );
			a.href = href;
			return !! a.hostname && a.hostname.toLowerCase() !== ( location.hostname || '' ).toLowerCase();
		} catch ( e ) {
			return false;
		}
	}

	function isConversionUrl( url ) {
		if ( ! url ) {
			return false;
		}
		var list = cfg.conversionUrls || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ] && url.indexOf( list[ i ] ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	/* ----------------------------------------------------------------- *
	 * Misc helpers
	 * ----------------------------------------------------------------- */

	function detectDevice() {
		var ua = navigator.userAgent || '';
		if ( /Mobi|Android|iPhone|iPod/i.test( ua ) ) {
			return 'mobile';
		}
		if ( /iPad|Tablet|PlayBook|Silk/i.test( ua ) || ( /Android/i.test( ua ) && ! /Mobi/i.test( ua ) ) ) {
			return 'tablet';
		}
		return 'desktop';
	}

	function param( name ) {
		var m = new RegExp( '[?&]' + name + '=([^&#]*)' ).exec( location.search || '' );
		if ( ! m ) {
			return '';
		}
		try {
			return decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) );
		} catch ( e ) {
			return m[ 1 ];
		}
	}

	function referrerHost() {
		try {
			if ( ! document.referrer ) {
				return '';
			}
			var a = document.createElement( 'a' );
			a.href = document.referrer;
			return ( a.hostname || '' ).toLowerCase().replace( /^www\./, '' );
		} catch ( e ) {
			return '';
		}
	}

	function cap( s ) {
		s = String( s || '' );
		return s ? s.charAt( 0 ).toUpperCase() + s.slice( 1 ) : s;
	}

	// Classify the visit's traffic source from UTM params and the referrer.
	function detectSource() {
		var us = param( 'utm_source' ).substring( 0, 100 );
		var um = param( 'utm_medium' ).substring( 0, 100 );
		var uc = param( 'utm_campaign' ).substring( 0, 150 );
		var rh = referrerHost().substring( 0, 191 );
		var self = ( location.hostname || '' ).toLowerCase().replace( /^www\./, '' );
		var src;

		if ( um ) {
			if ( /cpc|ppc|paid/i.test( um ) ) {
				src = 'Paid search';
			} else if ( /email|newsletter/i.test( um ) ) {
				src = 'Newsletter';
			} else if ( /social/i.test( um ) ) {
				src = 'Social';
			} else {
				src = cap( us || um );
			}
		} else if ( us ) {
			src = cap( us );
		} else if ( ! rh || rh === self ) {
			src = 'Direct';
		} else if ( /(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia)\./.test( rh ) ) {
			src = 'Organic search';
		} else if ( /(^|\.)(facebook|fb|instagram|twitter|x|linkedin|youtube|reddit|pinterest|tiktok)\.|t\.co/.test( rh ) ) {
			src = 'Social';
		} else {
			src = 'Referral';
		}

		return { src: src.substring( 0, 100 ), rh: rh, us: us, um: um, uc: uc };
	}

	function currentPath() {
		return ( ( location.pathname || '/' ) + ( location.search || '' ) ).substring( 0, 800 );
	}

	/* ----------------------------------------------------------------- *
	 * Heatmap capture: click position + scroll depth (% of the page)
	 * ----------------------------------------------------------------- */

	function clampInt( v, lo, hi ) {
		v = v | 0;
		return v < lo ? lo : ( v > hi ? hi : v );
	}

	function pageDims() {
		var doc = document.documentElement || {};
		var body = document.body || {};
		return {
			w: Math.max( doc.scrollWidth || 0, body.scrollWidth || 0, doc.clientWidth || 0 ) || 1,
			h: Math.max( doc.scrollHeight || 0, body.scrollHeight || 0, doc.clientHeight || 0 ) || 1
		};
	}

	// Click position as tenths of a percent (0-1000) of the full page and
	// clicked element. The element-relative values let heatmaps stay attached
	// to sticky/fixed controls instead of smearing by viewport scroll offset.
	function clickPosition( e, el ) {
		var d = pageDims();
		var px = ( typeof e.pageX === 'number' ) ? e.pageX : ( ( e.clientX || 0 ) + ( window.pageXOffset || 0 ) );
		var py = ( typeof e.pageY === 'number' ) ? e.pageY : ( ( e.clientY || 0 ) + ( window.pageYOffset || 0 ) );
		var doc = document.documentElement || {};
		var sx = Math.max( 0, window.pageXOffset || doc.scrollLeft || 0 );
		var sy = Math.max( 0, window.pageYOffset || doc.scrollTop || 0 );
		var vw = Math.max( 1, window.innerWidth || doc.clientWidth || 1 );
		var vh = Math.max( 1, window.innerHeight || doc.clientHeight || 1 );
		var rx = 0;
		var ry = 0;

		if ( el && el.getBoundingClientRect ) {
			var rect = el.getBoundingClientRect();
			if ( rect.width > 0 ) {
				rx = clampInt( Math.round( ( ( ( e.clientX || 0 ) - rect.left ) / rect.width ) * 1000 ), 0, 1000 );
			}
			if ( rect.height > 0 ) {
				ry = clampInt( Math.round( ( ( ( e.clientY || 0 ) - rect.top ) / rect.height ) * 1000 ), 0, 1000 );
			}
		}

		return {
			cx: clampInt( Math.round( ( px / d.w ) * 1000 ), 0, 1000 ),
			cy: clampInt( Math.round( ( py / d.h ) * 1000 ), 0, 1000 ),
			rx: rx,
			ry: ry,
			vw: clampInt( vw, 1, 1000000 ),
			vh: clampInt( vh, 1, 1000000 ),
			dw: clampInt( d.w, 1, 1000000 ),
			dh: clampInt( d.h, 1, 1000000 ),
			sx: clampInt( sx, 0, 1000000 ),
			sy: clampInt( sy, 0, 1000000 )
		};
	}

	function updateScroll() {
		var doc = document.documentElement || {};
		var d = pageDims();
		var seen = ( window.pageYOffset || doc.scrollTop || 0 ) + ( window.innerHeight || doc.clientHeight || 0 );
		var pct = clampInt( Math.round( ( seen / d.h ) * 100 ), 0, 100 );
		if ( pct > maxScroll ) {
			maxScroll = pct;
		}
	}

	function onScroll() {
		if ( scrollTick ) {
			return;
		}
		scrollTick = true;
		window.setTimeout( function () {
			updateScroll();
			scrollTick = false;
		}, 250 );
	}

	// Send the deepest scroll reached, once, as the visit ends.
	function recordScroll() {
		if ( scrollSent ) {
			return;
		}
		updateScroll();
		scrollSent = true;
		if ( maxScroll > 0 ) {
			queue.push( {
				t: 'scroll',
				pid: cfg.postId || 0,
				url: currentPath(),
				title: docTitle(),
				tag: '', id: '', cls: '', txt: '', sel: '', href: '', conv: 0,
				dev: device,
				src: source.src, rh: source.rh, us: source.us, um: source.um, uc: source.uc,
				sd: maxScroll
			} );
		}
	}

	function docTitle() {
		return ( document.title || '' ).substring( 0, 255 );
	}

	function isUuid( v ) {
		return typeof v === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test( v );
	}

	function uuid() {
		if ( window.crypto && crypto.getRandomValues ) {
			var b = new Uint8Array( 16 );
			crypto.getRandomValues( b );
			b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
			b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
			var hex = [];
			for ( var i = 0; i < 16; i++ ) {
				hex.push( ( b[ i ] + 0x100 ).toString( 16 ).substr( 1 ) );
			}
			return hex[0] + hex[1] + hex[2] + hex[3] + '-' + hex[4] + hex[5] + '-' + hex[6] + hex[7] + '-' + hex[8] + hex[9] + '-' + hex[10] + hex[11] + hex[12] + hex[13] + hex[14] + hex[15];
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function nowMs() {
		return ( window.Date && Date.now ) ? Date.now() : new Date().getTime();
	}

	/**
	 * localStorage wrapper that degrades to an in-memory map (private mode, etc.).
	 */
	function safeStorage() {
		var mem = {};
		var ok = false;
		try {
			var k = '__cvtrk_test__';
			window.localStorage.setItem( k, '1' );
			window.localStorage.removeItem( k );
			ok = true;
		} catch ( err ) {
			ok = false;
		}
		return {
			get: function ( key ) {
				if ( ok ) {
					try {
						return window.localStorage.getItem( key );
					} catch ( e ) {}
				}
				return key in mem ? mem[ key ] : null;
			},
			set: function ( key, val ) {
				if ( ok ) {
					try {
						window.localStorage.setItem( key, val );
						return;
					} catch ( e ) {}
				}
				mem[ key ] = val;
			}
		};
	}
} )();
