/**
 * Convertrack early-click buffer.
 *
 * Printed inline immediately before the tracker so that clicks made before
 * the tracker has executed (hosts and optimizers frequently delay analytics
 * scripts until the first interaction) are kept with their original
 * coordinates and time, then replayed by the tracker when it boots. It stores
 * nothing but a DOM reference, pointer coordinates and page geometry, and it
 * never sends anything itself.
 */
( function ( d, w ) {
	if ( w.__cvtrkEarly || ! d.addEventListener ) {
		return;
	}
	var buffer = [];
	w.__cvtrkEarly = buffer;

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
		buffer.push( {
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
	};
} )( document, window );
