/**
 * Convertrack front-end tracker.
 *
 * Captures analytics clicks on configured "button-like" elements, records
 * all-click heatmap positions, pageviews, and presence heartbeats. Events are
 * batched over keepalive fetch with sendBeacon for navigation/pagehide so
 * tracking never blocks navigation.
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

	function privacyOptedOut() {
		if ( navigator.globalPrivacyControl === true ) {
			return true;
		}
		try {
			return typeof window.wp_has_consent === 'function' && ! window.wp_has_consent( 'statistics' );
		} catch ( err ) {
			return false;
		}
	}

	if ( privacyOptedOut() || ( cfg.respectDnt && dntEnabled() ) ) {
		return;
	}

	var store = safeStorage();

	// Store a stable random bucket, not a permanent yes/no decision. This means
	// changing the configured sample rate immediately expands or contracts the
	// same deterministic cohort. Legacy binary decisions are migrated into the
	// equivalent side of the current threshold, then removed.
	var sampleBucket = resolveSamplingBucket();
	if ( ! sampledIn( cfg.sampleRate, sampleBucket ) ) {
		publishTestApi();
		return;
	}

	/* ----------------------------------------------------------------- *
	 * Identity
	 * ----------------------------------------------------------------- */

	var visitorId = store.get( 'cvtrk_vid' );
	if ( ! isUuid( visitorId ) ) {
		visitorId = uuid();
		store.set( 'cvtrk_vid', visitorId );
	}

	var sessionState = resolveSession();
	var sessionId = sessionState.id;

	/* ----------------------------------------------------------------- *
	 * State + selectors
	 * ----------------------------------------------------------------- */

	var queue = [];
	var detectedSource = detectSource();
	var source = resolveAcquisitionSource( detectedSource );
	var selector = buildSelector( cfg.selectors );
	var batchMax = Math.min( 25, cfg.batchMax > 0 ? cfg.batchMax : 25 );
	var queueMax = 100;
	var collectMaxBytes = 60000;
	var heartbeatMaxBytes = 3500;
	var maxScroll = 0;
	var lastSentScroll = 0;
	var scrollTick = false;
	var retryQueue = [];
	var retryTimer = 0;
	var retryMaxBatches = 8;
	var retryMaxAttempts = 2;

	/* ----------------------------------------------------------------- *
	 * Event capture
	 * ----------------------------------------------------------------- */

	// Capture phase so we record the click even if a handler calls stopPropagation.
	document.addEventListener( 'click', onClick, true );

	function onClick( e ) {
		if ( privacyOptedOut() ) {
			queue.length = 0;
			return;
		}
		rotateSessionIfExpired();
		var target = e.target;
		var heatmapEl = heatmapTarget( target );
		if ( ! heatmapEl ) {
			return;
		}

		var el = null;
		if ( selector && target.closest ) {
			try {
				el = target.closest( selector );
			} catch ( err ) {
				el = null;
			}
		}

		var attr = eventAttribution();
		var eventDevice = detectDevice();

		// Tracked clicks keep the existing dashboard/conversion semantics and
		// also feed the heatmap. Untracked clicks are recorded as heatmap-only
		// events so all-click heatmaps do not inflate click analytics.
		if ( el && isButtonLike( el ) ) {
			var href = el.getAttribute ? ( el.getAttribute( 'href' ) || '' ) : '';
			var goal = conversionRule( el, href );
			enqueue( clickPayload( e, el, href, attr, eventDevice, goal ) );
		} else {
			enqueue( heatmapPayload( e, heatmapEl, attr, eventDevice ) );
		}

		// Flush immediately if this click is likely to navigate away, so the
		// event is delivered before unload instead of relying on pagehide alone.
		var navHref = el ? ( el.getAttribute ? ( el.getAttribute( 'href' ) || '' ) : '' ) : heatmapHref( heatmapEl );
		var navigates = ( !! navHref && navHref.charAt( 0 ) !== '#' && navHref.toLowerCase().indexOf( 'javascript:' ) !== 0 ) ||
			( el && el.type === 'submit' );

		if ( navigates ) {
			flush( true );
		} else if ( queue.length >= batchMax ) {
			flush( false );
		}
	}

	function heatmapTarget( target ) {
		var el = target && target.nodeType === 1 ? target : ( target && target.parentElement );
		if ( ! el || ! el.ownerDocument ) {
			return null;
		}
		if ( el === document.documentElement && document.body ) {
			return document.body;
		}
		return el;
	}

	function heatmapHref( el ) {
		if ( ! el ) {
			return '';
		}
		if ( el.getAttribute && el.getAttribute( 'href' ) ) {
			return el.getAttribute( 'href' ) || '';
		}
		try {
			var link = el.closest ? el.closest( 'a[href],area[href]' ) : null;
			return link && link.getAttribute ? ( link.getAttribute( 'href' ) || '' ) : '';
		} catch ( e ) {
			return '';
		}
	}

	function clickPayload( event, el, href, attr, eventDevice, goal ) {
		var coords = clickPosition( event, el );
		var identity = pageIdentity();
		return {
			t: 'click',
			eid: uuid(),
			ts: nowMs(),
			pid: cfg.postId || 0,
			pk: identity.pk,
			ot: identity.ot,
			oid: identity.oid,
			pit: identity.pit,
			url: currentPath(),
			title: docTitle(),
			tag: ( el.tagName || '' ).toLowerCase(),
			id: stableId( el ),
			cls: elementClasses( el ),
			txt: staticControlLabel( el ),
			sel: cssPath( el ),
			hsel: heatmapPath( el ),
			href: privacySafeUrl( href ),
			conv: goal ? 1 : 0,
			goal: byteLimit( goal, 191 ),
			dev: eventDevice,
			src: attr.src,
			rh: attr.rh,
			us: attr.us,
			um: attr.um,
			uc: attr.uc,
			ut: attr.ut,
			kw: attr.kw,
			ks: attr.ks,
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
		};
	}

	// Heatmap-only events intentionally contain no text, href, classes or
	// arbitrary attributes. Coordinates and a generated structural path are
	// sufficient to render the heatmap.
	function heatmapPayload( event, el, attr, eventDevice ) {
		var coords = clickPosition( event, el );
		var identity = pageIdentity();
		return {
			t: 'heatmap_click', eid: uuid(), ts: nowMs(), pid: cfg.postId || 0,
			pk: identity.pk, ot: identity.ot, oid: identity.oid, pit: identity.pit,
			url: currentPath(), title: docTitle(), tag: tagOf( el ),
			id: '', cls: '', txt: '', sel: '', hsel: heatmapPath( el ), href: '', conv: 0,
			dev: eventDevice,
			src: attr.src, rh: attr.rh, us: attr.us, um: attr.um, uc: attr.uc, ut: attr.ut, kw: attr.kw, ks: attr.ks,
			cx: coords.cx, cy: coords.cy, rx: coords.rx, ry: coords.ry,
			vw: coords.vw, vh: coords.vh, dw: coords.dw, dh: coords.dh, sx: coords.sx, sy: coords.sy
		};
	}

	/* ----------------------------------------------------------------- *
	 * Pageview + heartbeat
	 * ----------------------------------------------------------------- */

	enqueuePageview();

	function enqueuePageview() {
		var pageAttr = eventAttribution();
		var pageViewMetrics = viewportSnapshot();
		var pageDevice = detectDevice();
		var identity = pageIdentity();
		enqueue( {
			t: 'pageview',
			eid: uuid(),
			ts: nowMs(),
			pid: cfg.postId || 0,
			pk: identity.pk,
			ot: identity.ot,
			oid: identity.oid,
			pit: identity.pit,
			url: currentPath(),
			title: docTitle(),
			tag: '',
			id: '',
			cls: '',
			txt: '',
			sel: '',
			href: '',
			conv: isConversionUrl( currentPath() ) ? 1 : 0,
			dev: pageDevice,
			src: pageAttr.src,
			rh: pageAttr.rh,
			us: pageAttr.us,
			um: pageAttr.um,
			uc: pageAttr.uc,
			ut: pageAttr.ut,
			kw: pageAttr.kw,
			ks: pageAttr.ks,
			vw: pageViewMetrics.vw,
			vh: pageViewMetrics.vh,
			dw: pageViewMetrics.dw,
			dh: pageViewMetrics.dh,
			sx: pageViewMetrics.sx,
			sy: pageViewMetrics.sy
		} );
	}

	heartbeat();
	var heartbeatTimer = window.setInterval( function () {
		resumeLifecycle( false );
	}, Math.max( 5000, cfg.heartbeat || 15000 ) );
	var flushTimer = window.setInterval( flush, Math.max( 1000, cfg.flush || 5000 ) );

	// Flush reliably when the page is being hidden or unloaded.
	updateScroll();
	window.addEventListener( 'scroll', onScroll, { passive: true } );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			recordScroll();
			flushAll();
		} else {
			resumeLifecycle( false );
		}
	} );
	window.addEventListener( 'pagehide', function () {
		recordScroll();
		flushAll();
	} );
	window.addEventListener( 'pageshow', function ( event ) {
		// pageshow is the only reliable resume signal for BFCache restores. A
		// restore inside the inactivity window keeps the session and does not
		// double-count a pageview; an expired restore starts a genuine session.
		resumeLifecycle( !! ( event && event.persisted ) );
	} );

	publishTestApi();

	/* ----------------------------------------------------------------- *
	 * Transport
	 * ----------------------------------------------------------------- */

	function enqueue( event ) {
		if ( ! event ) {
			return;
		}
		if ( queue.length >= queueMax ) {
			// Prefer dropping a heatmap-only event over a conversion or pageview.
			var drop = -1;
			for ( var i = 0; i < queue.length; i++ ) {
				if ( queue[ i ] && queue[ i ].t === 'heatmap_click' ) {
					drop = i;
					break;
				}
			}
			queue.splice( drop >= 0 ? drop : 0, 1 );
		}
		queue.push( event );
	}

	function flush( preferBeacon ) {
		if ( privacyOptedOut() ) {
			queue.length = 0;
			return 0;
		}
		if ( ! queue.length ) {
			return 0;
		}
		touchSession();
		var batch = takeBatch( collectMaxBytes );
		if ( ! batch.length ) {
			return 0;
		}
		send( cfg.collectUrl, {
			v: '1',
			_ct: byteLimit( cfg.collectorToken || '', 96 ),
			vid: visitorId,
			sid: sessionId,
			events: batch
		}, collectMaxBytes, true, 0, !! preferBeacon );
		return batch.length;
	}

	// pagehide/visibility flushing is deliberately bounded. Each request is
	// independently kept below the browser keepalive limit, and the in-memory
	// queue itself is capped, so a hostile page cannot create an unload loop.
	function flushAll() {
		var sends = 0;
		while ( queue.length && sends < 8 ) {
			if ( ! flush( true ) ) {
				break;
			}
			sends++;
		}
	}

	function heartbeat() {
		if ( privacyOptedOut() || document.visibilityState === 'hidden' ) {
			return;
		}
		touchSession();
		var identity = pageIdentity();
		send( cfg.heartbeatUrl, {
			_ct: byteLimit( cfg.collectorToken || '', 96 ),
			vid: visitorId,
			sid: sessionId,
			url: currentPath(),
			pid: cfg.postId || 0,
			pk: identity.pk,
			ot: identity.ot,
			oid: identity.oid,
			pit: identity.pit
		}, heartbeatMaxBytes, false, 0, false );
	}

	function send( url, payload, maxBytes, canRetry, attempt, preferBeacon ) {
		var body = JSON.stringify( payload );
		if ( utf8Bytes( body ) > maxBytes ) {
			return false;
		}
		try {
			if ( preferBeacon && navigator.sendBeacon ) {
				var blob = new Blob( [ body ], { type: 'application/json' } );
				if ( navigator.sendBeacon( url, blob ) ) {
					return true;
				}
			}
		} catch ( err ) {} // eslint-disable-line no-empty

		// Fallback for browsers without a working sendBeacon.
		try {
			var request = fetch( url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'omit'
			} );
			if ( request && request.then ) {
				request.then( function ( response ) {
					if ( canRetry && shouldRetryResponse( response ) ) {
						scheduleRetry( url, payload, maxBytes, attempt );
					}
				} ).catch( function () {
					if ( canRetry ) {
						scheduleRetry( url, payload, maxBytes, attempt );
					}
				} );
			}
			return true;
		} catch ( err2 ) {
			if ( canRetry ) {
				scheduleRetry( url, payload, maxBytes, attempt );
			}
			return false;
		}
	}

	function shouldRetryResponse( response ) {
		if ( ! response ) {
			return true;
		}
		if ( response.ok ) {
			return false;
		}
		var status = parseInt( response.status, 10 ) || 0;
		return status === 0 || status === 408 || status === 425 || status === 429 || status >= 500;
	}

	function scheduleRetry( url, payload, maxBytes, attempt ) {
		attempt = ( parseInt( attempt, 10 ) || 0 ) + 1;
		if ( attempt > retryMaxAttempts ) {
			return;
		}
		if ( retryQueue.length >= retryMaxBatches ) {
			retryQueue.shift();
		}
		retryQueue.push( { url: url, payload: payload, maxBytes: maxBytes, attempt: attempt } );
		armRetryTimer();
	}

	function armRetryTimer() {
		if ( retryTimer || ! retryQueue.length ) {
			return;
		}
		var delay = Math.min( 4000, 500 * Math.pow( 2, Math.max( 0, retryQueue[ 0 ].attempt - 1 ) ) );
		retryTimer = window.setTimeout( drainRetry, delay );
	}

	function drainRetry() {
		retryTimer = 0;
		if ( privacyOptedOut() ) {
			retryQueue.length = 0;
			return;
		}
		var entry = retryQueue.shift();
		if ( entry ) {
			send( entry.url, entry.payload, entry.maxBytes, true, entry.attempt, false );
		}
		armRetryTimer();
	}

	// Pull the largest bounded prefix that fits under the keepalive/body cap.
	// Events are already field-capped; a pathological event is discarded rather
	// than creating an unbounded client queue.
	function takeBatch( maxBytes ) {
		var batch = [];
		while ( queue.length && batch.length < batchMax ) {
			var candidate = batch.concat( [ queue[ 0 ] ] );
			var envelope = { v: '1', _ct: cfg.collectorToken || '', vid: visitorId, sid: sessionId, events: candidate };
			if ( utf8Bytes( JSON.stringify( envelope ) ) > maxBytes ) {
				if ( ! batch.length ) {
					queue.shift();
					continue;
				}
				break;
			}
			batch.push( queue.shift() );
		}
		return batch;
	}

	/* ----------------------------------------------------------------- *
	 * Session handling (sliding 30-min window)
	 * ----------------------------------------------------------------- */

	function normalizedSampleRate( rate ) {
		rate = parseFloat( rate );
		if ( ! isFinite( rate ) ) {
			return 100;
		}
		return Math.max( 0, Math.min( 100, rate ) );
	}

	function sampledIn( rate, bucket ) {
		rate = normalizedSampleRate( rate );
		bucket = parseInt( bucket, 10 );
		return bucket >= 0 && bucket < 1000000 && bucket < Math.round( rate * 10000 );
	}

	function resolveSamplingBucket() {
		var raw = store.get( 'cvtrk_smp_bucket' );
		var bucket = parseInt( raw, 10 );
		if ( ! /^\d+$/.test( String( raw || '' ) ) || bucket < 0 || bucket >= 1000000 ) {
			var rate = normalizedSampleRate( cfg.sampleRate );
			var threshold = Math.round( rate * 10000 );
			var legacy = store.get( 'cvtrk_smp' );
			if ( legacy === '1' && threshold > 0 ) {
				bucket = Math.floor( Math.random() * threshold );
			} else if ( legacy === '0' && threshold < 1000000 ) {
				bucket = threshold + Math.floor( Math.random() * ( 1000000 - threshold ) );
			} else {
				bucket = Math.floor( Math.random() * 1000000 );
			}
			store.set( 'cvtrk_smp_bucket', String( bucket ) );
		}
		store.remove( 'cvtrk_smp' );
		return bucket;
	}

	function sessionTtlMs() {
		return Math.max( 60000, ( parseInt( cfg.sessionTtl, 10 ) || 1800 ) * 1000 );
	}

	function resolveSession() {
		var ttl = sessionTtlMs();
		var raw = store.get( 'cvtrk_sid' );
		var now = nowMs();
		if ( raw ) {
			var parts = raw.split( '|' );
			var touched = parts.length === 2 ? parseInt( parts[ 1 ], 10 ) : 0;
			if ( parts.length === 2 && isUuid( parts[ 0 ] ) && touched > 0 && now >= touched && ( now - touched ) < ttl ) {
				store.set( 'cvtrk_sid', parts[0] + '|' + now );
				return { id: parts[ 0 ], isNew: false };
			}
		}
		var id = uuid();
		store.set( 'cvtrk_sid', id + '|' + now );
		return { id: id, isNew: true };
	}

	function touchSession() {
		var now = nowMs();
		store.set( 'cvtrk_sid', sessionId + '|' + now );
		refreshAcquisitionExpiry( now );
	}

	function resumeLifecycle( fromBfcache ) { // eslint-disable-line no-unused-vars
		if ( privacyOptedOut() ) {
			queue.length = 0;
			retryQueue.length = 0;
			return false;
		}
		var rotated = rotateSessionIfExpired();
		heartbeat();
		return rotated;
	}

	function rotateSessionIfExpired() {
		var next = resolveSession();
		if ( next.id !== sessionId ) {
			// Any remaining events belong to the previous session and must be
			// enveloped before the session id changes.
			flushAll();
			// A deliberately tiny batch setting can leave events after the bounded
			// unload-style flush. Drop them instead of assigning them to the new
			// session; accepted batches remain retryable with their original sid.
			queue.length = 0;
			sessionId = next.id;
			store.set( 'cvtrk_sid', sessionId + '|' + nowMs() );
			detectedSource = detectSource();
			source = resolveAcquisitionSource( detectedSource );
			maxScroll = 0;
			lastSentScroll = 0;
			updateScroll();
			enqueuePageview();
			return true;
		}
		sessionId = next.id;
		return false;
	}

	function normalizeSource( src ) {
		src = src || {};
		return {
			src: safeAttributionValue( src.src || 'Direct', 100 ),
			rh: safeHost( src.rh || '' ),
			us: safeAttributionValue( src.us, 100 ),
			um: safeAttributionValue( src.um, 100 ),
			uc: safeAttributionValue( src.uc, 150 ),
			ut: safeAttributionValue( src.ut, 150 ),
			kw: safeAttributionValue( src.kw, 191 ),
			ks: safeAttributionValue( src.ks, 50 )
		};
	}

	function resolveAcquisitionSource( current ) {
		var key = 'cvtrk_acq';
		var now = nowMs();
		var raw = store.get( key );
		var legacyRaw = store.get( 'cvtrk_acq_' + sessionId );
		pruneLegacyAcquisitionKeys();
		if ( raw ) {
			try {
				var record = JSON.parse( raw );
				if ( record && record.sid === sessionId && parseInt( record.exp, 10 ) > now && record.data ) {
					return normalizeSource( record.data );
				}
			} catch ( e ) {} // eslint-disable-line no-empty
		}
		if ( legacyRaw ) {
			try {
				current = normalizeSource( JSON.parse( legacyRaw ) );
			} catch ( e2 ) {} // eslint-disable-line no-empty
		}
		current = normalizeSource( current );
		// On-site search is page-specific, not an acquisition channel. If the
		// session's first page is itself a site search, do not bake that term
		// into the durable first-touch source — otherwise it would be
		// mis-attached to every later (non-search) page in the session. The
		// per-page site-search term is applied in eventAttribution() via the
		// override path instead. utm_term and referrer_query terms are genuine
		// acquisition signals and stay.
		if ( current.ks === 'site_search' ) {
			current.kw = '';
			current.ks = '';
		}
		store.set( key, JSON.stringify( { sid: sessionId, exp: now + sessionTtlMs(), data: current } ) );
		return current;
	}

	function refreshAcquisitionExpiry( now ) {
		var raw = store.get( 'cvtrk_acq' );
		if ( ! raw ) {
			return;
		}
		try {
			var record = JSON.parse( raw );
			if ( record && record.sid === sessionId && record.data ) {
				record.exp = now + sessionTtlMs();
				store.set( 'cvtrk_acq', JSON.stringify( record ) );
			}
		} catch ( e ) {
			store.remove( 'cvtrk_acq' );
		}
	}

	function pruneLegacyAcquisitionKeys() {
		var keys = store.keys();
		for ( var i = 0; i < keys.length; i++ ) {
			if ( keys[ i ].indexOf( 'cvtrk_acq_' ) === 0 ) {
				store.remove( keys[ i ] );
			}
		}
	}

	function eventAttribution() {
		var out = normalizeSource( source );
		// On-site search is page-specific, so preserve the first-touch traffic
		// source while attaching the current search term to this page's events.
		if ( cfg.trackSearchKeywords && detectedSource && detectedSource.ks === 'site_search' && detectedSource.kw ) {
			out.kw = detectedSource.kw;
			out.ks = detectedSource.ks;
			out.ut = detectedSource.ut || out.ut;
		}
		return out;
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
		var parts = String( cls ).split( /\s+/ ).filter( function ( item ) {
			return /^[A-Za-z_-][A-Za-z0-9_-]{0,63}$/.test( item ) && ! looksSensitive( item ) && ! /^(user|customer|account|member|email|order|session|auth|token|key)[_-]/i.test( item );
		} );
		return byteLimit( parts.slice( 0, 12 ).join( ' ' ), 191 );
	}

	function isEditable( el ) {
		if ( ! el || el.nodeType !== 1 ) {
			return false;
		}
		var tag = tagOf( el );
		if ( /^(input|textarea|select|option)$/.test( tag ) || el.isContentEditable ) {
			return true;
		}
		try {
			return !! ( el.closest && el.closest( '[contenteditable],[role="textbox"],[role="searchbox"],[role="combobox"]' ) );
		} catch ( err ) {
			return false;
		}
	}

	function isButtonLike( el ) {
		if ( ! el || isEditable( el ) && tagOf( el ) !== 'input' ) {
			return false;
		}
		var tag = tagOf( el );
		if ( tag === 'a' || tag === 'button' ) {
			return true;
		}
		if ( tag === 'input' ) {
			var type = String( el.type || '' ).toLowerCase();
			return /^(button|submit|image)$/.test( type );
		}
		return matchesSafe( el, '[role="button"],[data-cvtrk]' );
	}

	// Labels are collected only for explicitly tracked, non-editable
	// button-like controls. No .value access exists anywhere in the tracker.
	function staticControlLabel( el ) {
		if ( ! isButtonLike( el ) || isEditable( el ) ) {
			return '';
		}
		var txt = '';
		if ( el.getAttribute ) {
			txt = el.getAttribute( 'data-cvtrk-label' ) || el.getAttribute( 'aria-label' ) || '';
		}
		if ( ! txt && el.textContent ) {
			txt = el.textContent;
		}
		txt = String( txt ).replace( /\s+/g, ' ' ).trim();
		return looksSensitive( txt ) ? '' : byteLimit( txt, 100 );
	}

	function tagOf( node ) {
		return ( node && node.tagName ) ? node.tagName.toLowerCase() : '';
	}

	// Only keep ids that look human-authored. Auto-generated ids (long digit
	// runs, very long strings) would explode the rollup table's cardinality.
	function stableId( node ) {
		var id = ( node && node.id ) ? String( node.id ) : '';
		if ( ! id || id.length > 40 || /\d{4,}/.test( id ) || ! /^[A-Za-z][A-Za-z0-9_-]*$/.test( id ) || looksSensitive( id ) || isSensitiveParamName( id ) ) {
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

	function heatmapPath( el ) {
		var parts = [];
		var node = el;
		var depth = 0;
		while ( node && node.nodeType === 1 && depth < 9 ) {
			var sel = tagOf( node );
			var nodeId = stableId( node );
			if ( nodeId ) {
				parts.unshift( sel + '#' + nodeId );
				break;
			}
			var parent = node.parentNode;
			if ( parent && parent.children ) {
				var pos = 1;
				var same = 0;
				for ( var i = 0; i < parent.children.length; i++ ) {
					if ( parent.children[ i ].tagName === node.tagName ) {
						same++;
						if ( parent.children[ i ] === node ) {
							pos = same;
						}
					}
				}
				if ( same > 1 ) {
					sel += ':nth-of-type(' + pos + ')';
				}
			}
			parts.unshift( sel );
			if ( sel === 'html' ) {
				break;
			}
			node = parent;
			depth++;
		}
		return parts.join( '>' ).substring( 0, 255 );
	}

	function conversionRule( el, href ) {
		// An internal link to a goal URL is counted on its destination pageview,
		// so never flag the click for it — even when the element also matches a
		// conversion selector. This keeps one goal completion = one conversion.
		var internalGoal = !! href && ! isExternalHref( href ) && isConversionUrl( href );

		var list = cfg.conversionSelectors || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( matchesSafe( el, list[ i ] ) ) {
				return ! internalGoal ? String( list[ i ] ).substring( 0, 191 ) : '';
			}
		}
		// URL goals are otherwise only counted on the click when the link leaves
		// the site, since no pageview will fire here to count it.
		return isExternalHref( href ) && isConversionUrl( href ) ? '@url' : '';
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
		var test = privacySafeUrl( url );
		if ( ! test ) {
			return false;
		}
		var list = cfg.conversionUrls || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( conversionUrlMatches( test, list[ i ] ) ) {
				return true;
			}
		}
		return false;
	}

	function conversionUrlMatches( test, rawRule ) {
		var mode = 'exact';
		var value = rawRule;
		if ( rawRule && typeof rawRule === 'object' ) {
			mode = String( rawRule.mode || rawRule.match || 'exact' ).toLowerCase();
			value = rawRule.value || rawRule.pattern || rawRule.url || '';
		} else {
			var explicit = /^(exact|prefix|regex):(.*)$/i.exec( String( rawRule || '' ) );
			if ( explicit ) {
				mode = explicit[ 1 ].toLowerCase();
				value = explicit[ 2 ];
			}
		}

		value = String( value || '' ).trim();
		if ( ! value ) {
			return false;
		}

		if ( mode === 'regex' ) {
			return safeRegexMatch( test, value );
		}

		var goal = privacySafeUrl( value );
		if ( ! goal ) {
			return false;
		}
		if ( mode === 'exact' ) {
			// Backward compatibility: existing unprefixed setting lines remain
			// valid, but are intentionally normalized to exact matching. Sites that
			// need broader matching can opt into an explicit prefix: rule.
			return test === goal;
		}
		if ( mode === 'prefix' ) {
			return test.indexOf( goal ) === 0;
		}

		return false;
	}

	function safeRegexMatch( value, pattern ) {
		pattern = String( pattern || '' );
		// Regex rules must be anchored and intentionally conservative. Reject
		// lookarounds, backreferences and visibly nested repeaters to avoid a
		// configuration typo turning pageview tracking into a ReDoS vector.
		if ( ! pattern || utf8Bytes( pattern ) > 191 || pattern.charAt( 0 ) !== '^' || pattern.charAt( pattern.length - 1 ) !== '$' || /[\u0000-\u001f\u007f]/.test( pattern ) || /\(\?/.test( pattern ) || /\\[1-9]/.test( pattern ) || /\([^)]*[+*}][^)]*\)\s*[+*{]/.test( pattern ) ) {
			return false;
		}
		try {
			return new RegExp( pattern ).test( String( value ).substring( 0, 255 ) );
		} catch ( err ) {
			return false;
		}
	}

	/* ----------------------------------------------------------------- *
	 * Misc helpers
	 * ----------------------------------------------------------------- */

	function pageIdentity() {
		var objectId = parseInt( cfg.objectId, 10 ) || 0;
		return {
			pk: byteLimit( cfg.pageKey || '', 191 ),
			ot: byteLimit( String( cfg.objectType || '' ).toLowerCase().replace( /[^a-z0-9:_-]/g, '' ), 40 ),
			oid: Math.max( 0, objectId ),
			pit: byteLimit( cfg.pageIdentityToken || '', 191 )
		};
	}

	function detectDevice() {
		var ua = navigator.userAgent || '';
		var doc = document.documentElement || {};
		var width = Math.max( 1, window.innerWidth || doc.clientWidth || 1 );
		if ( /iPad|Tablet|PlayBook|Silk/i.test( ua ) || ( /Macintosh/i.test( ua ) && navigator.maxTouchPoints > 1 ) ) {
			return 'tablet';
		}
		if ( /iPhone|iPod|Mobi|Android.*Mobile/i.test( ua ) ) {
			return 'mobile';
		}
		if ( /Android/i.test( ua ) && ! /Mobi/i.test( ua ) ) {
			return 'tablet';
		}
		if ( width <= 600 ) {
			return 'mobile';
		}
		if ( width <= 1024 ) {
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

	function isSensitiveParamName( name ) {
		name = String( name || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' );
		if ( ! name ) {
			return true;
		}
		var compact = name.replace( /[_-]/g, '' );
		var exact = [ 'token', 'key', 'apikey', 'nonce', 'password', 'passwd', 'pass', 'email', 'auth', 'authorization', 'session', 'sessionid', 'code', 'secret', 'signature', 'sig', 'orderkey', 'resetkey', 'magiclink' ];
		if ( exact.indexOf( compact ) !== -1 ) {
			return true;
		}
		return /(^|[_-])(token|key|nonce|password|passwd|email|auth|session|code|secret|signature|order[_-]?key|reset[_-]?key)($|[_-])/.test( name );
	}

	function allowedQueryParams() {
		var list = cfg.allowedQueryParams || [];
		var out = {};
		for ( var i = 0; i < list.length; i++ ) {
			var key = String( list[ i ] || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' );
			if ( key && ! isSensitiveParamName( key ) ) {
				out[ key ] = true;
			}
		}
		return out;
	}

	function safeQuery( query ) {
		var allow = allowedQueryParams();
		var out = [];
		String( query || '' ).replace( /^\?/, '' ).split( '&' ).forEach( function ( pair ) {
			if ( ! pair ) {
				return;
			}
			var pos = pair.indexOf( '=' );
			var rawKey = pos === -1 ? pair : pair.substring( 0, pos );
			var rawVal = pos === -1 ? '' : pair.substring( pos + 1 );
			var key;
			var value;
			try {
				key = decodeURIComponent( rawKey.replace( /\+/g, ' ' ) ).toLowerCase();
				value = decodeURIComponent( rawVal.replace( /\+/g, ' ' ) );
			} catch ( err ) {
				return;
			}
			if ( ! allow[ key ] || isSensitiveParamName( key ) || looksSensitive( value ) ) {
				return;
			}
			out.push( encodeURIComponent( key ) + '=' + encodeURIComponent( byteLimit( value, 100 ) ) );
		} );
		return out.length ? '?' + out.join( '&' ) : '';
	}

	function privacySafeUrl( value ) {
		value = String( value || '' ).trim();
		if ( ! value || /^(javascript|data|mailto|tel|sms):/i.test( value ) ) {
			return '';
		}
		try {
			var a = document.createElement( 'a' );
			a.href = value;
			if ( ! /^https?:$/i.test( a.protocol ) ) {
				return '';
			}
			var path = safePath( a.pathname || '/' );
			var query = safeQuery( a.search || '' );
			var external = a.hostname && a.hostname.toLowerCase() !== ( location.hostname || '' ).toLowerCase();
			var safe = external ? ( a.protocol + '//' + a.host + path + query ) : ( path + query );
			return byteLimit( safe, 255 );
		} catch ( err ) {
			return '';
		}
	}

	function safePath( path ) {
		var segments = String( path || '/' ).split( '/' );
		for ( var i = 0; i < segments.length; i++ ) {
			var decoded = segments[ i ];
			try {
				decoded = decodeURIComponent( decoded );
			} catch ( err ) {} // eslint-disable-line no-empty
			var prior = i > 0 ? String( segments[ i - 1 ] ).toLowerCase() : '';
			var credentialRoute = /^(magic|magic-link|magic-login|magic_link|magic_login|login|passwordless|one-time-login|reset|password-reset|reset-password|lostpassword|verify|verification|auth|authenticate|token)$/.test( prior );
			if ( looksSensitive( decoded ) || ( credentialRoute && utf8Bytes( decoded ) >= 16 ) ) {
				segments[ i ] = 'redacted';
			}
		}
		return segments.join( '/' ) || '/';
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

	function referrerParam( names ) {
		try {
			if ( ! document.referrer ) {
				return '';
			}
			var a = document.createElement( 'a' );
			a.href = document.referrer;
			var query = a.search || '';
			for ( var i = 0; i < names.length; i++ ) {
				var m = new RegExp( '[?&]' + names[ i ] + '=([^&#]*)' ).exec( query );
				if ( m ) {
					return decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) );
				}
			}
		} catch ( e ) {} // eslint-disable-line no-empty
		return '';
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
		var ut = cfg.trackSearchKeywords ? param( 'utm_term' ).substring( 0, 150 ) : '';
		var rh = referrerHost().substring( 0, 191 );
		var self = ( location.hostname || '' ).toLowerCase().replace( /^www\./, '' );
		var src;
		var kw = '';
		var ks = '';

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

		if ( cfg.trackSearchKeywords ) {
			if ( ut ) {
				kw = ut;
				ks = 'utm_term';
			} else if ( param( 's' ) ) {
				kw = param( 's' ).substring( 0, 191 );
				ks = 'site_search';
			} else if ( src === 'Organic search' ) {
				kw = referrerParam( [ 'q', 'p', 'query', 'text', 'wd' ] ).substring( 0, 191 );
				if ( kw ) {
					ks = 'referrer_query';
				}
			}
		}

		return { src: src.substring( 0, 100 ), rh: rh, us: us, um: um, uc: uc, ut: ut, kw: kw, ks: ks };
	}

	function currentPath() {
		return byteLimit( safePath( location.pathname || '/' ) + safeQuery( location.search || '' ), 255 );
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

	function viewportSnapshot() {
		var doc = document.documentElement || {};
		var d = pageDims();
		return {
			vw: clampInt( Math.max( 1, window.innerWidth || doc.clientWidth || 1 ), 1, 1000000 ),
			vh: clampInt( Math.max( 1, window.innerHeight || doc.clientHeight || 1 ), 1, 1000000 ),
			dw: clampInt( d.w, 1, 1000000 ),
			dh: clampInt( d.h, 1, 1000000 ),
			sx: clampInt( Math.max( 0, window.pageXOffset || doc.scrollLeft || 0 ), 0, 1000000 ),
			sy: clampInt( Math.max( 0, window.pageYOffset || doc.scrollTop || 0 ), 0, 1000000 )
		};
	}

	// Click position as tenths of a percent (0-1000) of the full page and
	// clicked element. The element-relative values let heatmaps stay attached
	// to sticky/fixed controls instead of smearing by viewport scroll offset.
	function clickPosition( e, el ) {
		var d = pageDims();
		var px = ( typeof e.pageX === 'number' ) ? e.pageX : ( ( e.clientX || 0 ) + ( window.pageXOffset || 0 ) );
		var py = ( typeof e.pageY === 'number' ) ? e.pageY : ( ( e.clientY || 0 ) + ( window.pageYOffset || 0 ) );
		var view = viewportSnapshot();
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
			vw: view.vw,
			vh: view.vh,
			dw: view.dw,
			dh: view.dh,
			sx: view.sx,
			sy: view.sy
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

	// Send monotonically increasing milestones. Visibility changes and BFCache
	// round-trips may happen repeatedly; only a new maximum creates an event.
	function recordScroll() {
		updateScroll();
		if ( maxScroll > lastSentScroll ) {
			lastSentScroll = maxScroll;
			var attr = eventAttribution();
			var eventDevice = detectDevice();
			var identity = pageIdentity();
			enqueue( {
				t: 'scroll',
				eid: uuid(),
				ts: nowMs(),
				pid: cfg.postId || 0,
				pk: identity.pk,
				ot: identity.ot,
				oid: identity.oid,
				pit: identity.pit,
				url: currentPath(),
				title: docTitle(),
				tag: '', id: '', cls: '', txt: '', sel: '', href: '', conv: 0,
				dev: eventDevice,
				src: attr.src, rh: attr.rh, us: attr.us, um: attr.um, uc: attr.uc, ut: attr.ut, kw: attr.kw, ks: attr.ks,
				sd: maxScroll
			} );
		}
	}

	function docTitle() {
		var title = String( document.title || '' ).replace( /\s+/g, ' ' ).trim();
		return looksSensitive( title ) ? '' : byteLimit( title, 160 );
	}

	function safeHost( value ) {
		value = String( value || '' ).toLowerCase();
		return /^[a-z0-9.-]+$/.test( value ) ? byteLimit( value, 191 ) : '';
	}

	function looksSensitive( value ) {
		value = String( value || '' ).trim();
		if ( ! value ) {
			return false;
		}
		if ( /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test( value ) ) {
			return true;
		}
		if ( /(?:token|nonce|password|passwd|secret|signature|authorization|order[_ -]?key|reset[_ -]?key)\s*[:=]/i.test( value ) ) {
			return true;
		}
		return /^[A-Za-z0-9_\-+/=]{40,}$/.test( value );
	}

	function safeAttributionValue( value, maxBytes ) {
		value = String( value || '' ).replace( /[\u0000-\u001f\u007f]/g, '' ).trim();
		return looksSensitive( value ) ? '' : byteLimit( value, maxBytes );
	}

	function utf8Bytes( value ) {
		value = String( value || '' );
		if ( window.TextEncoder ) {
			return new window.TextEncoder().encode( value ).length;
		}
		try {
			return unescape( encodeURIComponent( value ) ).length; // eslint-disable-line no-undef
		} catch ( err ) {
			return value.length * 3;
		}
	}

	function byteLimit( value, maxBytes ) {
		value = String( value || '' );
		if ( utf8Bytes( value ) <= maxBytes ) {
			return value;
		}
		var out = '';
		for ( var i = 0; i < value.length; i++ ) {
			var next = out + value.charAt( i );
			if ( utf8Bytes( next ) > maxBytes ) {
				break;
			}
			out = next;
		}
		return out;
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

	function publishTestApi() {
		if ( window.__CONVERTRACK_TEST__ !== true ) {
			return;
		}
		window.__ConvertrackTest = {
			sampledIn: sampledIn,
			sampleBucket: sampleBucket,
			safePath: safePath,
			safeQuery: safeQuery,
			privacySafeUrl: privacySafeUrl,
			conversionUrlMatches: conversionUrlMatches,
			safeRegexMatch: safeRegexMatch,
			utf8Bytes: utf8Bytes,
			byteLimit: byteLimit,
			takeBatch: takeBatch,
			enqueue: enqueue,
			flush: flush,
			flushAll: flushAll,
			recordScroll: recordScroll,
			updateScroll: updateScroll,
			resumeLifecycle: resumeLifecycle,
			rotateSessionIfExpired: rotateSessionIfExpired,
			scheduleRetry: scheduleRetry,
			drainRetry: drainRetry,
			getState: function () {
				return {
					sessionId: sessionId,
					queue: queue ? queue.slice() : [],
					retries: retryQueue ? retryQueue.slice() : [],
					maxScroll: maxScroll,
					lastSentScroll: lastSentScroll,
					source: source
				};
			}
		};
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
			},
			remove: function ( key ) {
				if ( ok ) {
					try {
						window.localStorage.removeItem( key );
					} catch ( e ) {}
				}
				delete mem[ key ];
			},
			keys: function () {
				var out = [];
				if ( ok ) {
					try {
						for ( var i = 0; i < window.localStorage.length; i++ ) {
							var item = window.localStorage.key( i );
							if ( item !== null ) {
								out.push( item );
							}
						}
					} catch ( e ) {}
				}
				for ( var key in mem ) {
					if ( Object.prototype.hasOwnProperty.call( mem, key ) && out.indexOf( key ) === -1 ) {
						out.push( key );
					}
				}
				return out;
			}
		};
	}
} )();
