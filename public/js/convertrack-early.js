/**
 * Convertrack early-click buffer.
 *
 * Printed inline immediately before the tracker so that clicks made before
 * the tracker has executed (hosts and optimizers frequently delay analytics
 * scripts until the first interaction) are kept with their original
 * coordinates and time, then replayed by the tracker when it boots. It stores
 * nothing but a DOM reference, pointer coordinates, page geometry and the
 * click-authenticity signals (pointer type, whether a press preceded the
 * click, a count of scroll/movement/key events), and it never sends anything
 * itself.
 */
( function ( d, w ) {
	if ( w.__cvtrkEarly || ! d.addEventListener ) {
		return;
	}
	var buffer = [];
	w.__cvtrkEarly = buffer;
	// Shared with the tracker, which keeps counting after it boots.
	buffer.ia = 0;
	buffer.press = null;

	function onPress( e ) {
		if ( e && e.isTrusted !== false ) {
			buffer.press = { t: Date.now(), type: e.pointerType || ( e.type === 'touchstart' ? 'touch' : 'mouse' ), target: e.target };
		}
	}
	function onActivity( e ) {
		if ( e && e.isTrusted !== false ) {
			buffer.ia++;
		}
	}
	var passive = { passive: true, capture: true };
	var watched = [
		[ d, w.PointerEvent ? 'pointerdown' : 'touchstart', onPress ],
		[ d, 'mousedown', onPress, ! w.PointerEvent ],
		[ w, 'scroll', onActivity ],
		[ d, 'touchmove', onActivity ],
		[ d, 'mousemove', onActivity ],
		[ d, 'keydown', onActivity ]
	];
	function watch( add ) {
		for ( var i = 0; i < watched.length; i++ ) {
			var item = watched[ i ];
			var fn = item[ 0 ] && item[ 0 ][ add ? 'addEventListener' : 'removeEventListener' ];
			if ( item[ 3 ] !== false && typeof fn === 'function' ) {
				try {
					fn.call( item[ 0 ], item[ 1 ], item[ 2 ], passive );
				} catch ( err ) {} // eslint-disable-line no-empty
			}
		}
	}
	watch( true );

	function capture( e ) {
		if ( buffer.done || ! e || e.isTrusted === false || buffer.length >= 50 ) {
			return;
		}
		var target = e.target;
		var rect = null;
		try {
			if ( target && target.nodeType !== 1 ) {
				target = target.parentElement;
			}
			if ( target && target.getBoundingClientRect ) {
				var box = target.getBoundingClientRect();
				rect = { l: box.left, t: box.top, w: box.width, h: box.height };
			}
		} catch ( err ) {
			rect = null;
		}
		var root = d.documentElement || {};
		var body = d.body || {};
		var press = buffer.press;
		var pressed = !! ( press && Date.now() - press.t < 1500 && target && target.contains && press.target && ( target === press.target || target.contains( press.target ) || ( press.target.contains && press.target.contains( target ) ) ) );
		var perf = w.performance;
		buffer.push( {
			tq: {
				pt: pressed ? press.type : ( ! e.detail && ! e.clientX && ! e.clientY ? 'keyboard' : '' ),
				pd: pressed ? 1 : 0,
				ms: perf && perf.now ? Math.round( perf.now() ) : 0,
				ia: Math.min( 9999, buffer.ia ),
				hf: d.hasFocus && ! d.hasFocus() ? 0 : 1,
				wd: w.navigator && w.navigator.webdriver ? 1 : 0
			},
			el: target,
			cx: e.clientX || 0,
			cy: e.clientY || 0,
			px: typeof e.pageX === 'number' ? e.pageX : ( e.clientX || 0 ) + ( w.pageXOffset || 0 ),
			py: typeof e.pageY === 'number' ? e.pageY : ( e.clientY || 0 ) + ( w.pageYOffset || 0 ),
			detail: e.detail || 0,
			ts: Date.now(),
			vw: w.innerWidth || root.clientWidth || 0,
			vh: w.innerHeight || root.clientHeight || 0,
			dw: Math.max( root.scrollWidth || 0, body.scrollWidth || 0, root.clientWidth || 0 ),
			dh: Math.max( root.scrollHeight || 0, body.scrollHeight || 0, root.clientHeight || 0 ),
			sx: w.pageXOffset || root.scrollLeft || 0,
			sy: w.pageYOffset || root.scrollTop || 0,
			rect: rect
		} );
	}

	d.addEventListener( 'click', capture, true );
	buffer.stop = function () {
		buffer.done = true;
		d.removeEventListener( 'click', capture, true );
		watch( false );
	};
} )( document, window );
