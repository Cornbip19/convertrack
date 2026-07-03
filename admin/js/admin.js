/**
 * Convertrack admin dashboard.
 *
 * Pulls aggregated stats and live presence from the REST API and renders them
 * as tables/charts with no third-party dependency. All server-supplied strings
 * are inserted with textContent to stay XSS-safe.
 */
( function () {
	'use strict';

	var C = window.ConvertrackAdmin;
	if ( ! C ) {
		return;
	}

	var ROOT = String( C.root ).replace( /\/$/, '' );
	var I18N = C.i18n || {};
	var timelineEvents = [];

	function api( path, options ) {
		options = options || {};
		var headers = { 'X-WP-Nonce': C.nonce };
		if ( options.body ) {
			headers['Content-Type'] = 'application/json';
		}
		return fetch( ROOT + path, {
			method: options.method || 'GET',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined,
			credentials: 'same-origin'
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				return r.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					throw new Error( ( body && body.message ) || 'HTTP ' + r.status );
				} );
			}
			return r.json();
		} );
	}

	function postApi( path, body ) {
		return api( path, { method: 'POST', body: body || {} } );
	}

	/* DOM helpers --------------------------------------------------------- */

	function attr( key ) {
		return document.querySelector( '[data-cvtrk="' + key + '"]' );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = String( text );
		}
		return n;
	}

	function clear( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function svgIcon( name, cls ) {
		var paths = {
			empty: '<path d="M5 17.5h14"/><path d="M7 14l3-4 3 2.5 4-6"/><circle cx="10" cy="10" r="1.3"/><circle cx="13" cy="12.5" r="1.3"/><circle cx="17" cy="6.5" r="1.3"/>',
			visit: '<path d="M4 6.5h16v11H4z"/><path d="M8 21h8"/><path d="M12 17.5V21"/><circle cx="12" cy="12" r="2.7"/>',
			click: '<path d="M8 4v11l2.4-2.4L14 20l2.2-1.1-3.5-6.9H16z"/>',
			scroll: '<path d="M12 5v14"/><path d="M7.5 14.5L12 19l4.5-4.5"/><path d="M7.5 9.5L12 5l4.5 4.5"/>',
			conversion: '<path d="M5 12.5l4 4L19 6.5"/><path d="M4 5h11"/><path d="M4 19h16"/>',
			event: '<circle cx="12" cy="12" r="7"/><path d="M12 8v4l3 2"/>',
			list: '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
			indexed: '<path d="M5 12.5l4 4L19 6.5"/>',
			dismiss: '<path d="M7 7l10 10"/><path d="M17 7L7 17"/>',
			clock: '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
			code: '<path d="M8 8l-4 4 4 4"/><path d="M16 8l4 4-4 4"/>',
			visibility: '<circle cx="12" cy="12" r="2.7"/><path d="M4 12s3-5 8-5 8 5 8 5-3 5-8 5-8-5-8-5z"/>',
			search: '<circle cx="10.5" cy="10.5" r="5.5"/><path d="M15 15l4 4"/>',
			page: '<path d="M7 4h8l4 4v12H7z"/><path d="M14 4v5h5"/>',
			shield: '<path d="M12 4l7 3v5c0 4-2.6 6.9-7 8-4.4-1.1-7-4-7-8V7z"/>',
			hidden: '<path d="M4 12s3-5 8-5 8 5 8 5-3 5-8 5-8-5-8-5z"/><path d="M7 7l10 10"/>',
			warning: '<path d="M12 4l9 16H3z"/><path d="M12 9v5"/><path d="M12 17h.01"/>',
			update: '<path d="M18 8a7 7 0 0 0-12-2l-2 2"/><path d="M4 4v4h4"/><path d="M6 16a7 7 0 0 0 12 2l2-2"/><path d="M20 20v-4h-4"/>',
			calendar: '<path d="M6 5h12v15H6z"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M6 9h12"/>'
		};
		var aliases = {
			'yes-alt': 'indexed',
			'hidden': 'hidden',
			'list-view': 'list',
			'media-code': 'code',
			'admin-page': 'page',
			'calendar-alt': 'calendar'
		};
		name = aliases[ name ] || name || 'event';
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', cls || 'cvtrk-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.8' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.innerHTML = paths[ name ] || paths.event;
		return svg;
	}

	function num( v ) {
		return ( Number( v ) || 0 ).toLocaleString();
	}

	function empty( box, msg ) {
		clear( box );
		var e = el( 'div', 'cvtrk-empty' );
		e.appendChild( svgIcon( 'empty', 'cvtrk-empty-icon' ) );
		e.appendChild( el( 'p', null, msg || I18N.noData || 'No data yet for this range.' ) );
		box.appendChild( e );
	}

	function table( headers ) {
		var wrap = el( 'div', 'cvtrk-table-wrap' );
		var t = el( 'table', 'cvtrk-table' );
		var thead = el( 'thead' );
		var tr = el( 'tr' );
		headers.forEach( function ( h ) {
			var th = el( 'th', h.num ? 'cvtrk-num' : null, h.label );
			tr.appendChild( th );
		} );
		thead.appendChild( tr );
		t.appendChild( thead );
		t.appendChild( el( 'tbody' ) );
		wrap.appendChild( t );
		return wrap;
	}

	function rankCell( i ) {
		var td = el( 'td' );
		td.appendChild( el( 'span', 'cvtrk-rank', i + 1 ) );
		return td;
	}

	function labelCell( label, sub, subHref ) {
		var td = el( 'td' );
		var labelEl = el( 'span', 'cvtrk-label', label );
		// Labels/URLs are truncated with ellipsis, so expose the full value on hover.
		if ( label ) {
			labelEl.title = String( label );
		}
		td.appendChild( labelEl );
		if ( sub ) {
			if ( subHref ) {
				var wrap = el( 'span', 'cvtrk-sub' );
				var a = el( 'a', null, sub );
				a.href = subHref;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.title = String( sub );
				wrap.appendChild( a );
				td.appendChild( wrap );
			} else {
				var subEl = el( 'span', 'cvtrk-sub', sub );
				subEl.title = String( sub );
				td.appendChild( subEl );
			}
		}
		return td;
	}

	function clicksCell( value, max ) {
		var td = el( 'td', 'cvtrk-num' );
		td.appendChild( el( 'span', 'cvtrk-strong', num( value ) ) );
		var meter = el( 'div', 'cvtrk-meter' );
		var fill = el( 'span', 'cvtrk-meter-fill' );
		fill.style.width = ( ( ( Number( value ) || 0 ) / ( max || 1 ) ) * 100 ) + '%';
		meter.appendChild( fill );
		td.appendChild( meter );
		return td;
	}

	function numCell( value ) {
		return el( 'td', 'cvtrk-num', num( value ) );
	}

	// Numeric-styled cell for an already-formatted string (e.g. "3m 20s").
	// Must NOT go through num(), which would coerce it to 0.
	function textNumCell( str ) {
		return el( 'td', 'cvtrk-num', str );
	}

	function convCell( value ) {
		var td = el( 'td', 'cvtrk-num' );
		var v = Number( value ) || 0;
		td.appendChild( el( 'span', 'cvtrk-badge ' + ( v > 0 ? 'cvtrk-badge-green' : 'cvtrk-badge-gray' ), num( v ) ) );
		return td;
	}

	// Seconds -> compact "1h 4m" / "3m 20s" / "45s".
	function formatDuration( seconds ) {
		var s = Math.max( 0, Math.round( Number( seconds ) || 0 ) );
		var h = Math.floor( s / 3600 );
		var m = Math.floor( ( s % 3600 ) / 60 );
		var sec = s % 60;
		if ( h > 0 ) {
			return h + 'h ' + m + 'm';
		}
		if ( m > 0 ) {
			return m + 'm ' + sec + 's';
		}
		return sec + 's';
	}

	// Two-letter code -> readable country name, falling back to the code.
	function countryName( code ) {
		if ( ! code ) {
			return I18N.unknownCountry || 'Unknown';
		}
		try {
			if ( window.Intl && Intl.DisplayNames ) {
				var dn = new Intl.DisplayNames( undefined, { type: 'region' } );
				return dn.of( String( code ).toUpperCase() ) || code;
			}
		} catch ( e ) {} // eslint-disable-line no-empty
		return code;
	}

	// Two-letter code -> flag emoji (regional indicator symbols).
	function flagEmoji( code ) {
		code = String( code || '' ).toUpperCase();
		if ( ! /^[A-Z]{2}$/.test( code ) || ! String.fromCodePoint ) {
			return '';
		}
		return String.fromCodePoint( 0x1F1E6 + ( code.charCodeAt( 0 ) - 65 ), 0x1F1E6 + ( code.charCodeAt( 1 ) - 65 ) );
	}

	/* Rendering ----------------------------------------------------------- */

	function renderCards( totals ) {
		if ( ! totals ) {
			return;
		}
		set( 'pageviews', num( totals.pageviews ) );
		set( 'clicks', num( totals.clicks ) );
		set( 'conversions', num( totals.conversions ) );
		set( 'unique_visitors', num( totals.unique_visitors ) );
		set( 'conversion_rate', ( Number( totals.conversion_rate ) || 0 ) + '%' );
		set( 'click_through', ( Number( totals.click_through ) || 0 ) + '%' );

		var cmp = totals.comparison || {};
		[ 'pageviews', 'clicks', 'conversions', 'unique_visitors', 'conversion_rate', 'click_through' ].forEach( function ( k ) {
			setDelta( k, cmp[ k ] );
		} );
	}

	function set( key, value ) {
		var node = attr( key );
		if ( node ) {
			node.textContent = value;
		}
	}

	// Surface the "set up a conversion goal" hint only when there is traffic but
	// no conversions — i.e. goals are probably not configured.
	function toggleConvHint( totals ) {
		var hint = attr( 'conv-hint' );
		if ( ! hint || ! totals ) {
			return;
		}
		var hasTraffic = ( Number( totals.pageviews ) || 0 ) > 0 || ( Number( totals.clicks ) || 0 ) > 0;
		var noConv = ( Number( totals.conversions ) || 0 ) === 0;
		hint.hidden = ! ( hasTraffic && noConv );
	}

	function setDelta( key, change ) {
		var node = document.querySelector( '[data-cvtrk-delta="' + key + '"]' );
		if ( ! node ) {
			return;
		}
		node.className = 'cvtrk-kpi-delta';
		if ( change === null || change === undefined ) {
			node.textContent = '';
			return;
		}
		var v = Number( change );
		var arrow = v > 0 ? '▲' : ( v < 0 ? '▼' : '▬' );
		node.textContent = arrow + ' ' + Math.abs( v ) + '%';
		node.classList.add( v > 0 ? 'is-up' : ( v < 0 ? 'is-down' : 'is-flat' ) );
		node.title = I18N.vsPrev || 'vs. previous period';
	}

	function renderLegacyChart( series ) {
		var box = attr( 'chart' );
		if ( ! box ) {
			return;
		}
		clear( box );

		var dates = Object.keys( series || {} );
		if ( ! dates.length ) {
			empty( box );
			return;
		}

		var max = 1;
		dates.forEach( function ( d ) {
			max = Math.max( max, Number( series[ d ].clicks ) || 0, Number( series[ d ].pageviews ) || 0 );
		} );

		var chart = el( 'div', 'cvtrk-bars' );
		dates.forEach( function ( d ) {
			var row = series[ d ];
			var col = el( 'div', 'cvtrk-bar-col' );
			var stack = el( 'div', 'cvtrk-bar-stack' );

			var pv = el( 'span', 'cvtrk-bar cvtrk-bar-pv' );
			pv.style.height = ( ( ( Number( row.pageviews ) || 0 ) / max ) * 100 ) + '%';
			pv.title = d + ' · ' + ( I18N.pageviews || 'Pageviews' ) + ': ' + num( row.pageviews );

			var cl = el( 'span', 'cvtrk-bar cvtrk-bar-click' );
			cl.style.height = ( ( ( Number( row.clicks ) || 0 ) / max ) * 100 ) + '%';
			cl.title = d + ' · ' + ( I18N.clicks || 'Clicks' ) + ': ' + num( row.clicks );

			stack.appendChild( pv );
			stack.appendChild( cl );
			col.appendChild( stack );
			col.appendChild( el( 'span', 'cvtrk-bar-label', d.slice( 5 ) ) );
			chart.appendChild( col );
		} );

		var legend = el( 'div', 'cvtrk-legend' );
		legend.appendChild( legendItem( 'cvtrk-bar-pv', I18N.pageviews || 'Pageviews' ) );
		legend.appendChild( legendItem( 'cvtrk-bar-click', I18N.clicks || 'Clicks' ) );

		box.appendChild( chart );
		box.appendChild( legend );
	}

	function legendItem( cls, label ) {
		var item = el( 'span', 'cvtrk-legend-item' );
		item.appendChild( el( 'span', 'cvtrk-swatch ' + cls ) );
		item.appendChild( el( 'span', null, label ) );
		return item;
	}

	function svgEl( tag, attrs ) {
		var n = document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			n.setAttribute( key, attrs[ key ] );
		} );
		return n;
	}

	// Series-visibility state for the Activity trend chart, kept at module scope
	// so legend toggles persist across date-range changes and auto-refresh.
	var seriesHidden = { pageviews: false, clicks: false, conversions: false };
	var lastSeries = null;

	function legendToggle( key, cls, label, onToggle ) {
		var btn = el( 'button', 'cvtrk-legend-item cvtrk-legend-toggle' + ( seriesHidden[ key ] ? ' is-off' : '' ) );
		btn.type = 'button';
		btn.setAttribute( 'aria-pressed', seriesHidden[ key ] ? 'false' : 'true' );
		btn.appendChild( el( 'span', 'cvtrk-swatch ' + cls ) );
		btn.appendChild( el( 'span', null, label ) );
		btn.addEventListener( 'click', function () { onToggle( key ); } );
		return btn;
	}

	function renderChart( series ) {
		var box = attr( 'chart' );
		if ( ! box ) {
			return;
		}
		lastSeries = series;
		clear( box );

		var dates = Object.keys( series || {} );
		if ( ! dates.length ) {
			empty( box );
			return;
		}

		var seriesMeta = [
			{ key: 'pageviews', cls: 'is-pageviews', label: I18N.pageviews || 'Pageviews' },
			{ key: 'clicks', cls: 'is-clicks', label: I18N.clicks || 'Clicks' },
			{ key: 'conversions', cls: 'is-conversions', label: I18N.conversions || 'Conversions' }
		];
		var visible = seriesMeta.filter( function ( s ) { return ! seriesHidden[ s.key ]; } );

		var max = 1;
		dates.forEach( function ( d ) {
			visible.forEach( function ( s ) {
				max = Math.max( max, Number( series[ d ][ s.key ] ) || 0 );
			} );
		} );

		var width = 920;
		var height = 280;
		var pad = { top: 20, right: 24, bottom: 42, left: 50 };
		var innerW = width - pad.left - pad.right;
		var innerH = height - pad.top - pad.bottom;
		var svg = svgEl( 'svg', {
			class: 'cvtrk-linechart',
			viewBox: '0 0 ' + width + ' ' + height,
			role: 'img',
			'aria-label': I18N.activityTrend || 'Activity trend'
		} );

		[ 0, 0.25, 0.5, 0.75, 1 ].forEach( function ( step ) {
			var y = pad.top + ( innerH * step );
			svg.appendChild( svgEl( 'line', { class: 'cvtrk-chart-gridline', x1: pad.left, y1: y, x2: width - pad.right, y2: y } ) );
		} );

		function xy( d, i, key ) {
			var x = pad.left + ( dates.length === 1 ? innerW / 2 : ( innerW * i ) / ( dates.length - 1 ) );
			var y = pad.top + innerH - ( ( Number( series[ d ][ key ] ) || 0 ) / max ) * innerH;
			return { x: x, y: y };
		}

		function pathFor( key ) {
			return dates.map( function ( d, i ) {
				var p = xy( d, i, key );
				return ( i === 0 ? 'M' : 'L' ) + p.x.toFixed( 2 ) + ' ' + p.y.toFixed( 2 );
			} ).join( ' ' );
		}

		function drawLine( key, cls ) {
			svg.appendChild( svgEl( 'path', { class: 'cvtrk-line ' + cls, d: pathFor( key ) } ) );
			dates.forEach( function ( d, i ) {
				if ( dates.length > 31 && i !== dates.length - 1 ) {
					return;
				}
				var p = xy( d, i, key );
				var dot = svgEl( 'circle', { class: 'cvtrk-line-dot ' + cls, cx: p.x.toFixed( 2 ), cy: p.y.toFixed( 2 ), r: 2.5 } );
				var title = svgEl( 'title' );
				title.textContent = d + ' - ' + key + ': ' + num( series[ d ][ key ] );
				dot.appendChild( title );
				svg.appendChild( dot );
			} );
		}

		visible.forEach( function ( s ) { drawLine( s.key, s.cls ); } );

		var zero = svgEl( 'text', { class: 'cvtrk-chart-axis', x: 8, y: height - pad.bottom + 4 } );
		zero.textContent = '0';
		svg.appendChild( zero );
		var maxLabel = svgEl( 'text', { class: 'cvtrk-chart-axis', x: 8, y: pad.top + 4 } );
		maxLabel.textContent = num( max );
		svg.appendChild( maxLabel );

		var firstLabel = svgEl( 'text', { class: 'cvtrk-chart-axis', x: pad.left, y: height - 12 } );
		firstLabel.textContent = dates[ 0 ].slice( 5 );
		svg.appendChild( firstLabel );
		var lastLabel = svgEl( 'text', { class: 'cvtrk-chart-axis is-end', x: width - pad.right, y: height - 12 } );
		lastLabel.textContent = dates[ dates.length - 1 ].slice( 5 );
		svg.appendChild( lastLabel );

		function toggleSeries( key ) {
			var visibleCount = seriesMeta.filter( function ( s ) { return ! seriesHidden[ s.key ]; } ).length;
			// Keep at least one series visible so the chart never blanks.
			if ( ! seriesHidden[ key ] && visibleCount <= 1 ) {
				return;
			}
			seriesHidden[ key ] = ! seriesHidden[ key ];
			renderChart( lastSeries );
		}

		var legend = el( 'div', 'cvtrk-legend' );
		seriesMeta.forEach( function ( s ) {
			legend.appendChild( legendToggle( s.key, s.cls, s.label, toggleSeries ) );
		} );

		box.appendChild( svg );
		box.appendChild( legend );
	}

	function renderHourlyChart( items ) {
		var box = attr( 'hourly-chart' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}

		var max = 1;
		items.forEach( function ( it ) {
			max = Math.max( max, ( Number( it.pageviews ) || 0 ) + ( Number( it.clicks ) || 0 ) + ( Number( it.conversions ) || 0 ) );
		} );

		var chart = el( 'div', 'cvtrk-hour-bars' );
		items.forEach( function ( it, index ) {
			var pv = Number( it.pageviews ) || 0;
			var clicks = Number( it.clicks ) || 0;
			var conversions = Number( it.conversions ) || 0;
			var total = pv + clicks + conversions;
			var col = el( 'div', 'cvtrk-hour-col' );
			var stack = el( 'div', 'cvtrk-hour-stack' );
			if ( total <= 0 ) {
				stack.className += ' is-empty';
				stack.style.height = '2px';
			} else {
				stack.style.height = Math.max( 4, ( total / max ) * 100 ) + '%';
			}
			stack.title = it.hour + ' - ' + ( I18N.pageviews || 'Pageviews' ) + ': ' + num( pv ) + ', ' +
				( I18N.clicks || 'Clicks' ) + ': ' + num( clicks ) + ', ' +
				( I18N.conversions || 'Conversions' ) + ': ' + num( conversions );

			var pvSeg = el( 'span', 'cvtrk-hour-seg is-pageviews' );
			var clickSeg = el( 'span', 'cvtrk-hour-seg is-clicks' );
			var convSeg = el( 'span', 'cvtrk-hour-seg is-conversions' );
			pvSeg.style.flexGrow = Math.max( pv, 0 );
			clickSeg.style.flexGrow = Math.max( clicks, 0 );
			convSeg.style.flexGrow = Math.max( conversions, 0 );
			stack.appendChild( convSeg );
			stack.appendChild( clickSeg );
			stack.appendChild( pvSeg );
			col.appendChild( stack );
			if ( index % 4 === 0 ) {
				col.appendChild( el( 'span', 'cvtrk-hour-label', it.hour.slice( 0, 2 ) ) );
			}
			chart.appendChild( col );
		} );

		var legend = el( 'div', 'cvtrk-legend' );
		legend.appendChild( legendItem( 'is-pageviews', I18N.pageviews || 'Pageviews' ) );
		legend.appendChild( legendItem( 'is-clicks', I18N.clicks || 'Clicks' ) );
		legend.appendChild( legendItem( 'is-conversions', I18N.conversions || 'Conversions' ) );

		box.appendChild( chart );
		box.appendChild( legend );
	}

	function renderEngagement( data ) {
		var box = attr( 'engagement-chart' );
		if ( ! box ) {
			return;
		}
		clear( box );

		var items = [
			{ key: 'pageviews', label: I18N.pageviews || 'Pageviews', value: Number( data && data.pageviews ) || 0, color: '#145c63' },
			{ key: 'clicks', label: I18N.clicks || 'Clicks', value: Number( data && data.clicks ) || 0, color: '#188a6b' },
			{ key: 'scrolls', label: I18N.scrolls || 'Scrolls', value: Number( data && data.scrolls ) || 0, color: '#c58a24' },
			{ key: 'conversions', label: I18N.conversions || 'Conversions', value: Number( data && data.conversions ) || 0, color: '#b84a62' }
		];
		var total = items.reduce( function ( sum, it ) { return sum + it.value; }, 0 );
		if ( total <= 0 ) {
			empty( box, I18N.noData );
			return;
		}

		var start = 0;
		var stops = items.map( function ( it ) {
			var end = start + ( it.value / total ) * 100;
			var part = it.color + ' ' + start.toFixed( 2 ) + '% ' + end.toFixed( 2 ) + '%';
			start = end;
			return part;
		} );

		var wrap = el( 'div', 'cvtrk-donut-wrap' );
		var donut = el( 'div', 'cvtrk-donut' );
		donut.style.background = 'conic-gradient(' + stops.join( ',' ) + ')';
		donut.appendChild( el( 'span', null, num( total ) ) );
		wrap.appendChild( donut );

		var list = el( 'div', 'cvtrk-donut-list' );
		items.forEach( function ( it ) {
			var row = el( 'div', 'cvtrk-donut-row' );
			row.appendChild( el( 'span', 'cvtrk-donut-key is-' + it.key ) );
			row.appendChild( el( 'span', 'cvtrk-donut-label', it.label ) );
			row.appendChild( el( 'span', 'cvtrk-donut-value', num( it.value ) ) );
			list.appendChild( row );
		} );
		wrap.appendChild( list );
		box.appendChild( wrap );
	}

	function renderButtons( items ) {
		var box = attr( 'top-buttons' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = items[ 0 ].clicks || 1;
		var t = table( [
			{ label: '' },
			{ label: I18N.button || 'Button' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it, i ) {
			var tr = el( 'tr' );
			tr.appendChild( rankCell( i ) );
			tr.appendChild( labelCell( it.label || it.selector, it.label !== it.selector ? it.selector : '' ) );
			tr.appendChild( clicksCell( it.clicks, max ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderPages( items, onPick ) {
		var box = attr( 'top-pages' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.clicks || 0 ); } );

		var t = table( [
			{ label: '' },
			{ label: I18N.page || 'Page' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it, i ) {
			var tr = el( 'tr' );
			if ( onPick ) {
				tr.className = 'is-clickable';
				tr.addEventListener( 'click', function () { onPick( it ); } );
			}
			tr.appendChild( rankCell( i ) );
			tr.appendChild( labelCell( it.title, it.url, it.url || '' ) );
			tr.appendChild( clicksCell( it.clicks, max ) );
			tr.appendChild( numCell( it.pageviews ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderSessions( items ) {
		var box = attr( 'active-sessions' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData || 'No one is browsing right now.' );
			return;
		}
		var t = table( [
			{ label: I18N.page || 'Page' },
			{ label: I18N.location || 'Location' },
			{ label: I18N.timeOnSite || 'Time on site', num: true },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.clicks || 'Clicks', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( it.title || it.url, it.url ) );
			tr.appendChild( countryCell( it.country ) );
			tr.appendChild( textNumCell( formatDuration( it.duration ) ) );
			tr.appendChild( numCell( it.page_views ) );
			tr.appendChild( numCell( it.clicks ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	// A country cell with flag + readable name (em dash when unknown).
	function countryCell( code ) {
		var td = el( 'td' );
		if ( ! code ) {
			td.appendChild( el( 'span', 'cvtrk-sub', '—' ) );
			return td;
		}
		var flag = flagEmoji( code );
		td.appendChild( el( 'span', 'cvtrk-label', ( flag ? flag + ' ' : '' ) + countryName( code ) ) );
		return td;
	}

	function renderCountries( items, enabled ) {
		var box = attr( 'top-countries' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! enabled ) {
			empty( box, I18N.geoOff );
			return;
		}
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.visitors || 0 ); } );

		var t = table( [
			{ label: I18N.country || 'Country' },
			{ label: I18N.visitors || 'Visitors', num: true },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			var flag = flagEmoji( it.country );
			tr.appendChild( labelCell( ( flag ? flag + ' ' : '' ) + countryName( it.country ), '' ) );
			tr.appendChild( clicksCell( it.visitors, max ) );
			tr.appendChild( numCell( it.pageviews ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderSources( items ) {
		var box = attr( 'top-sources' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.pageviews || 0 ); } );

		var t = table( [
			{ label: I18N.source || 'Source' },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( it.source, '' ) );
			tr.appendChild( clicksCell( it.pageviews, max ) );
			tr.appendChild( numCell( it.clicks ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function keywordSourceLabel( source ) {
		source = String( source || '' );
		if ( source === 'utm_term' ) {
			return I18N.utmTerm || 'UTM term';
		}
		if ( source === 'site_search' ) {
			return I18N.siteSearch || 'Site search';
		}
		if ( source === 'referrer_query' ) {
			return I18N.referrerQuery || 'Search referrer';
		}
		if ( source === 'organic_not_provided' ) {
			return I18N.notProvided || 'Not provided';
		}
		return source || ( I18N.unknown || 'Unknown' );
	}

	function renderSearchTerms( targetKey, items, enabled ) {
		var box = attr( targetKey );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! enabled ) {
			empty( box, I18N.keywordsOff || 'Enable search keyword tracking in Settings to collect supported queries.' );
			return;
		}
		if ( ! items || ! items.length ) {
			empty( box, I18N.noSearchTerms || 'No search keywords for this range.' );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.pageviews || 0 ); } );

		var t = table( [
			{ label: I18N.keyword || 'Keyword' },
			{ label: I18N.source || 'Source' },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( it.keyword || '', keywordSourceLabel( it.keyword_source ) ) );
			tr.appendChild( labelCell( it.traffic_source || '', '' ) );
			tr.appendChild( clicksCell( it.pageviews, max ) );
			tr.appendChild( numCell( it.clicks ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function eventTypeLabel( item ) {
		if ( item && Number( item.is_conversion ) > 0 ) {
			return I18N.conversion || 'Conversion';
		}
		if ( item && item.type === 'pageview' ) {
			return I18N.pageVisit || 'Page visit';
		}
		if ( item && item.type === 'click' ) {
			return I18N.click || 'Click';
		}
		if ( item && item.type === 'scroll' ) {
			return I18N.scroll || 'Scroll';
		}
		return I18N.event || 'Event';
	}

	function eventIconName( item ) {
		if ( item && Number( item.is_conversion ) > 0 ) {
			return 'conversion';
		}
		if ( item && item.type === 'pageview' ) {
			return 'visit';
		}
		if ( item && item.type === 'click' ) {
			return 'click';
		}
		if ( item && item.type === 'scroll' ) {
			return 'scroll';
		}
		return 'event';
	}

	function eventMainLabel( item ) {
		if ( ! item ) {
			return '';
		}
		if ( item.type === 'click' ) {
			return item.element_text || item.element_selector || item.element_href || item.page_title || item.page_url || '';
		}
		return item.page_title || item.page_url || '';
	}

	function eventSubLabel( item ) {
		if ( ! item ) {
			return '';
		}
		if ( item.type === 'click' && item.element_selector ) {
			return item.page_url ? item.page_url + ' | ' + item.element_selector : item.element_selector;
		}
		return item.page_url || item.element_selector || '';
	}

	function eventSearchText( item ) {
		return [
			item.time,
			item.visitor,
			item.session,
			item.type,
			eventTypeLabel( item ),
			item.page_title,
			item.page_url,
			item.element_text,
			item.element_selector,
			item.element_href,
			item.device,
			item.country,
			item.source
		].join( ' ' ).toLowerCase();
	}

	function timelineMeta( label, value ) {
		var chip = el( 'span', 'cvtrk-timeline-meta' );
		chip.appendChild( el( 'span', null, label ) );
		chip.appendChild( el( 'b', null, value || '-' ) );
		return chip;
	}

	function renderTimeline( items ) {
		if ( items ) {
			timelineEvents = items.slice( 0 );
		}

		var box = attr( 'event-timeline' );
		if ( ! box ) {
			return;
		}
		clear( box );

		var typeSel = attr( 'timeline-type' );
		var filterInput = attr( 'timeline-filter' );
		var sortSel = attr( 'timeline-sort' );
		var type = typeSel ? typeSel.value : 'all';
		var query = filterInput ? String( filterInput.value || '' ).trim().toLowerCase() : '';
		var sort = sortSel ? sortSel.value : 'desc';

		var rows = timelineEvents.filter( function ( item ) {
			if ( type === 'conversion' && Number( item.is_conversion ) <= 0 ) {
				return false;
			}
			if ( type !== 'all' && type !== 'conversion' && item.type !== type ) {
				return false;
			}
			if ( query && eventSearchText( item ).indexOf( query ) === -1 ) {
				return false;
			}
			return true;
		} );

		rows.sort( function ( a, b ) {
			var cmp = String( a.time || '' ).localeCompare( String( b.time || '' ) );
			if ( cmp === 0 ) {
				cmp = ( Number( a.id ) || 0 ) - ( Number( b.id ) || 0 );
			}
			return sort === 'asc' ? cmp : -cmp;
		} );

		if ( ! rows.length ) {
			empty( box, I18N.noData );
			return;
		}

		var list = el( 'div', 'cvtrk-timeline' );
		rows.slice( 0, 80 ).forEach( function ( item ) {
			var row = el( 'div', 'cvtrk-timeline-item ' + ( Number( item.is_conversion ) > 0 ? 'is-conversion' : 'is-' + item.type ) );
			var icon = el( 'span', 'cvtrk-timeline-icon' );
			icon.appendChild( svgIcon( eventIconName( item ), 'cvtrk-icon' ) );
			var body = el( 'div', 'cvtrk-timeline-body' );
			var top = el( 'div', 'cvtrk-timeline-top' );
			top.appendChild( el( 'span', 'cvtrk-timeline-type', eventTypeLabel( item ) ) );
			top.appendChild( el( 'time', 'cvtrk-timeline-time', item.time || '' ) );
			body.appendChild( top );
			var titleText = eventMainLabel( item ) || ( I18N.unknown || 'Unknown' );
			var titleEl = el( 'div', 'cvtrk-timeline-title', titleText );
			titleEl.title = String( titleText );
			body.appendChild( titleEl );
			var sub = eventSubLabel( item );
			if ( sub ) {
				var subEl = el( 'div', 'cvtrk-timeline-sub', sub );
				subEl.title = String( sub );
				body.appendChild( subEl );
			}
			var meta = el( 'div', 'cvtrk-timeline-metas' );
			meta.appendChild( timelineMeta( I18N.visitor || 'Visitor', item.visitor ) );
			meta.appendChild( timelineMeta( I18N.source || 'Source', item.source || 'Direct' ) );
			if ( item.device ) {
				meta.appendChild( timelineMeta( I18N.device || 'Device', item.device ) );
			}
			body.appendChild( meta );
			row.appendChild( icon );
			row.appendChild( body );
			list.appendChild( row );
		} );

		box.appendChild( list );
	}

	function setLastUpdated() {
		var node = attr( 'last-updated' );
		if ( ! node ) {
			return;
		}
		var now = new Date();
		node.textContent = ( I18N.updated || 'Updated' ) + ' ' + now.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	function setLive( count ) {
		var node = attr( 'active' );
		if ( node ) {
			node.textContent = num( count );
		}
	}

	/* Heatmaps ------------------------------------------------------------ */

	function renderScrollDepth( scroll, samples ) {
		var box = attr( 'scroll-depth' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! scroll || ! scroll.length || ! samples ) {
			empty( box, I18N.noHeatmap );
			return;
		}
		var map = el( 'div', 'cvtrk-scrollmap' );
		scroll.forEach( function ( b ) {
			var pct = Number( b.pct ) || 0;
			var row = el( 'div', 'cvtrk-scrollband' );
			var alpha = Math.max( 0.04, ( pct / 100 ) * 0.82 );
			row.style.background = 'rgba(45,45,45,' + alpha.toFixed( 3 ) + ')';
			if ( alpha > 0.5 ) {
				row.style.color = '#fff';
			}
			row.appendChild( el( 'span', null, b.depth + '%' ) );
			row.appendChild( el( 'span', null, pct + '% ' + ( I18N.reached || 'reached this depth' ) ) );
			map.appendChild( row );
		} );
		box.appendChild( map );
		box.appendChild( el( 'p', 'cvtrk-note', num( samples ) + ' ' + ( I18N.visitors || 'Visitors' ).toLowerCase() + ' sampled' ) );
	}

	function renderHeatmapElements( items ) {
		var box = attr( 'heatmap-elements' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noHeatmap || 'No heatmap data for this page yet.' );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.clicks || 0 ); } );
		var t = table( [
			{ label: I18N.button || 'Element' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			var sub = it.selector || it.href || '';
			tr.appendChild( labelCell( it.label || it.selector || '', sub ) );
			tr.appendChild( clicksCell( it.clicks, max ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function frameDoc( frame ) {
		try {
			return frame && ( frame.contentDocument || ( frame.contentWindow && frame.contentWindow.document ) );
		} catch ( e ) {
			return null;
		}
	}

	function frameScroll( frame, doc ) {
		var win = frame && frame.contentWindow;
		var root = doc && doc.documentElement;
		var body = doc && doc.body;
		return {
			x: ( win && win.pageXOffset ) || ( root && root.scrollLeft ) || ( body && body.scrollLeft ) || 0,
			y: ( win && win.pageYOffset ) || ( root && root.scrollTop ) || ( body && body.scrollTop ) || 0
		};
	}

	function heatmapViewport( device ) {
		device = String( device || 'desktop' );
		if ( device === 'mobile' ) {
			return { key: 'mobile', w: 390, h: 844, label: I18N.mobile || 'Mobile' };
		}
		if ( device === 'tablet' ) {
			return { key: 'tablet', w: 768, h: 1024, label: I18N.tablet || 'Tablet' };
		}
		return { key: 'desktop', w: 1280, h: 800, label: I18N.desktop || 'Desktop' };
	}

	function applyHeatmapViewport( stage, page, frame, device ) {
		var viewport = heatmapViewport( device );
		if ( stage ) {
			stage.classList.remove( 'is-device-desktop', 'is-device-tablet', 'is-device-mobile' );
			stage.classList.add( 'is-device-' + viewport.key );
		}
		if ( page ) {
			page.style.width = viewport.w + 'px';
			page.style.minHeight = viewport.h + 'px';
		}
		if ( frame ) {
			frame.style.width = viewport.w + 'px';
			frame.style.minHeight = viewport.h + 'px';
			frame.style.height = viewport.h + 'px';
			frame.setAttribute( 'width', String( viewport.w ) );
			frame.setAttribute( 'height', String( viewport.h ) );
		}
		return viewport;
	}

	function viewportPointPosition( p, w, h, viewport ) {
		if ( ! p || ! p.has_viewport ) {
			return null;
		}

		var vw = Number( p.vw ) || 0;
		var vh = Number( p.vh ) || 0;
		var dw = Number( p.dw ) || 0;
		var dh = Number( p.dh ) || 0;
		if ( vw <= 0 || vh <= 0 || dw <= 0 || dh <= 0 ) {
			return null;
		}

		var sx = Math.max( 0, Number( p.sx ) || 0 );
		var sy = Math.max( 0, Number( p.sy ) || 0 );
		var rawPx = Number( p.px );
		var rawPy = Number( p.py );
		var pageX = ( ( isFinite( rawPx ) && rawPx >= 0 ? rawPx : ( ( Number( p.x ) || 0 ) * 10 ) ) / 1000 ) * dw;
		var pageY = ( ( isFinite( rawPy ) && rawPy >= 0 ? rawPy : ( ( Number( p.y ) || 0 ) * 10 ) ) / 1000 ) * dh;
		var clientX = Math.max( 0, Math.min( vw, pageX - sx ) );
		var clientY = Math.max( 0, Math.min( vh, pageY - sy ) );
		var previewW = viewport && viewport.w ? viewport.w : w;
		var previewH = viewport && viewport.h ? viewport.h : vh;
		var previewScrollX = 0;
		var previewScrollY = 0;
		var originalScrollX = Math.max( 1, dw - vw );
		var originalScrollY = Math.max( 1, dh - vh );
		var renderedScrollX = Math.max( 0, w - previewW );
		var renderedScrollY = Math.max( 0, h - previewH );

		if ( renderedScrollX > 0 ) {
			previewScrollX = ( sx / originalScrollX ) * renderedScrollX;
		}
		if ( renderedScrollY > 0 ) {
			previewScrollY = ( sy / originalScrollY ) * renderedScrollY;
		}

		return {
			x: previewScrollX + ( clientX / vw ) * previewW,
			y: previewScrollY + ( clientY / vh ) * previewH
		};
	}

	function pointPosition( p, w, h, frame, mode, viewport ) {
		if ( mode === 'element' && p && p.has_rel && p.selector && frame ) {
			var doc = frameDoc( frame );
			if ( doc && doc.querySelector ) {
				try {
					var target = doc.querySelector( p.selector );
					if ( target && target.getBoundingClientRect ) {
						var rect = target.getBoundingClientRect();
						if ( rect.width > 0 && rect.height > 0 ) {
							var scroll = frameScroll( frame, doc );
							return {
								x: scroll.x + rect.left + ( rect.width * ( ( Number( p.rx ) || 0 ) / 100 ) ),
								y: scroll.y + rect.top + ( rect.height * ( ( Number( p.ry ) || 0 ) / 100 ) )
							};
						}
					}
				} catch ( e ) {} // eslint-disable-line no-empty
			}
		}
		var viewportPos = viewportPointPosition( p, w, h, viewport );
		if ( viewportPos ) {
			return viewportPos;
		}
		return {
			x: ( ( Number( p.x ) || 0 ) / 100 ) * w,
			y: ( ( Number( p.y ) || 0 ) / 100 ) * h
		};
	}

	function docHeight( frame ) {
		var doc = frameDoc( frame );
		if ( ! doc ) {
			return 0;
		}
		var root = doc.documentElement || {};
		var body = doc.body || {};
		return Math.max(
			root.scrollHeight || 0,
			body.scrollHeight || 0,
			root.offsetHeight || 0,
			body.offsetHeight || 0,
			root.clientHeight || 0
		);
	}

	function drawHeatCanvas( canvas, page, markers, points, maxW, height, frame, mode, viewport ) {
		var w = page.clientWidth || ( frame && frame.clientWidth ) || 600;
		var h = Math.max( 240, Math.min( height || Math.round( w * 1.4 ), 8000 ) );
		page.style.height = h + 'px';
		canvas.width = w;
		canvas.height = h;
		canvas.style.width = w + 'px';
		canvas.style.height = h + 'px';
		if ( markers ) {
			markers.style.width = w + 'px';
			markers.style.height = h + 'px';
			clear( markers );
		}
		var ctx = canvas.getContext( '2d' );
		ctx.clearRect( 0, 0, w, h );
		if ( ! points || ! points.length ) {
			return;
		}
		var r = Math.max( 9, Math.min( 28, Math.round( w * 0.016 ) ) );
		ctx.globalCompositeOperation = 'source-over';
		points.forEach( function ( p ) {
			var pos = pointPosition( p, w, h, frame, mode, viewport );
			var x = pos.x;
			var y = pos.y;
			if ( x < 0 || y < 0 || x > w || y > h ) {
				return;
			}
			var a = Math.max( 0.12, Math.min( 0.85, ( p.w / ( maxW || 1 ) ) ) );
			var g = ctx.createRadialGradient( x, y, 0, x, y, r );
			g.addColorStop( 0, 'rgba(220,50,30,' + a.toFixed( 3 ) + ')' );
			g.addColorStop( 0.5, 'rgba(255,150,40,' + ( a * 0.5 ).toFixed( 3 ) + ')' );
			g.addColorStop( 1, 'rgba(255,200,60,0)' );
			ctx.fillStyle = g;
			ctx.beginPath();
			ctx.arc( x, y, r, 0, 6.2832 );
			ctx.fill();
			if ( markers ) {
				var marker = el( 'span', 'cvtrk-heatmap-marker' );
				var size = Math.max( 5, Math.min( 11, Math.round( 4 + ( p.w / ( maxW || 1 ) ) * 7 ) ) );
				marker.style.left = x + 'px';
				marker.style.top = y + 'px';
				marker.style.width = size + 'px';
				marker.style.height = size + 'px';
				marker.title = num( p.w ) + ' ' + ( I18N.clicksHere || 'clicks' ) + ( p.selector ? ' - ' + p.selector : '' );
				markers.appendChild( marker );
			}
		} );
		ctx.globalCompositeOperation = 'source-over';
	}

	function renderClickMap( data, showPage, mode ) {
		var stage = attr( 'heatmap-stage' );
		var page = attr( 'heatmap-page' );
		var frame = attr( 'heatmap-frame' );
		var canvas = attr( 'heatmap-canvas' );
		var markers = attr( 'heatmap-markers' );
		var note = attr( 'heatmap-note' );
		if ( ! stage || ! page || ! canvas ) {
			return;
		}
		var points = ( data && data.points ) || [];
		var maxW = ( data && data.max_weight ) || 1;
		mode = mode === 'page' ? 'page' : 'element';
		var viewport = applyHeatmapViewport( stage, page, frame, data && data.device );

		if ( note ) {
			note.textContent = points.length ? '' : ( I18N.noHeatmap || '' );
		}

		if ( showPage && data && data.snapshot && data.snapshot.html && frame ) {
			stage.classList.remove( 'cvtrk-no-frame' );
			page.classList.remove( 'cvtrk-no-frame' );
			frame.style.display = 'block';
			frame.onload = function () {
				window.setTimeout( function () {
					var docH = docHeight( frame );
					if ( docH > 0 ) {
						var renderH = Math.max( viewport.h, Math.min( docH, 8000 ) );
						frame.style.height = renderH + 'px';
						drawHeatCanvas( canvas, page, markers, points, maxW, renderH, frame, mode, viewport );
					} else {
						frame.style.display = 'none';
						stage.classList.add( 'cvtrk-no-frame' );
						drawHeatCanvas( canvas, page, markers, points, maxW, viewport.h, null, 'page', viewport );
					}
				}, 60 );
				window.setTimeout( function () {
					var docH = docHeight( frame );
					if ( docH > 0 ) {
						var renderH = Math.max( viewport.h, Math.min( docH, 8000 ) );
						frame.style.height = renderH + 'px';
						drawHeatCanvas( canvas, page, markers, points, maxW, renderH, frame, mode, viewport );
					}
				}, 500 );
			};
			var snapshotKey = ( data.snapshot.url || '' ) + '|' + ( data.snapshot.device || viewport.key );
			if ( frame.getAttribute( 'data-snapshot-key' ) !== snapshotKey ) {
				frame.setAttribute( 'data-snapshot-key', snapshotKey );
				frame.setAttribute( 'data-snapshot-url', data.snapshot.url || '' );
				frame.removeAttribute( 'src' );
				frame.srcdoc = data.snapshot.html;
			} else {
				frame.onload();
			}
		} else {
			if ( frame ) {
				frame.style.display = 'none';
				frame.removeAttribute( 'data-snapshot-key' );
				frame.removeAttribute( 'data-snapshot-url' );
			}
			stage.classList.add( 'cvtrk-no-frame' );
			page.classList.add( 'cvtrk-no-frame' );
			drawHeatCanvas( canvas, page, markers, points, maxW, viewport.h, null, 'page', viewport );
		}
	}

	function initHeatmap() {
		var rangeSel = attr( 'range' );
		var postSel = attr( 'post' );
		var deviceSel = attr( 'device' );
		var deviceToggle = attr( 'device-toggle' );
		var modeSel = attr( 'heatmap-mode' );
		var showChk = attr( 'show-page' );
		var data = null;
		var snapshots = {};
		var resizeTimer = null;

		function selectedDevice() {
			var value = deviceSel ? deviceSel.value : 'desktop';
			return value === 'tablet' || value === 'mobile' ? value : 'desktop';
		}

		function syncDeviceToggle() {
			var device = selectedDevice();
			if ( deviceSel && deviceSel.value !== device ) {
				deviceSel.value = device;
			}
			if ( ! deviceToggle ) {
				return;
			}
			deviceToggle.querySelectorAll( '[data-cvtrk-device]' ).forEach( function ( btn ) {
				var active = btn.getAttribute( 'data-cvtrk-device' ) === device;
				btn.classList.toggle( 'is-active', active );
				btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		}

		function mode() {
			return modeSel ? modeSel.value : 'element';
		}

		function loadSnapshot( post, device ) {
			post = String( post || '' );
			device = device || selectedDevice();
			if ( ! post || post === '0' ) {
				return Promise.resolve( null );
			}
			var key = post + ':' + device;
			if ( snapshots[ key ] ) {
				return Promise.resolve( snapshots[ key ] );
			}
			return api( '/stats/heatmap-snapshot?post=' + encodeURIComponent( post ) + '&device=' + encodeURIComponent( device ) )
				.then( function ( snapshot ) {
					snapshots[ key ] = snapshot;
					return snapshot;
				} );
		}

		function renderCurrent() {
			if ( data ) {
				renderClickMap( data, showChk ? showChk.checked : true, mode() );
			}
		}

		function load() {
			var post = postSel ? postSel.value : 0;
			var range = rangeSel ? rangeSel.value : 7;
			var device = selectedDevice();
			syncDeviceToggle();
			if ( ! post || post === '0' ) {
				return;
			}
			api( '/stats/heatmap?post=' + encodeURIComponent( post ) + '&range=' + encodeURIComponent( range ) + '&device=' + encodeURIComponent( device ) )
				.then( function ( d ) {
					data = d;
					if ( showChk && showChk.checked ) {
						return loadSnapshot( post, device ).then( function ( snapshot ) {
							data.snapshot = snapshot;
							return data;
						}, function () {
							return data;
						} );
					}
					return data;
				} )
				.then( function ( d ) {
					renderScrollDepth( d.scroll, d.scroll_samples );
					renderHeatmapElements( d.elements );
					renderSearchTerms( 'heatmap-keywords', d.search_terms, d.search_keywords_enabled );
					renderClickMap( d, showChk ? showChk.checked : true, mode() );
					var meta = attr( 'heatmap-meta' );
					if ( meta ) {
						var viewport = heatmapViewport( d.device );
						meta.textContent = viewport.label + ' ' + viewport.w + 'x' + viewport.h + ' - ' +
							num( d.pageviews ) + ' ' + ( I18N.pageviews || 'Pageviews' ).toLowerCase() +
							' - ' + num( d.clicks ) + ' ' + ( I18N.clicksHere || 'clicks' );
					}
				} )
				.catch( function () {} );
		}

		function showNoPages() {
			var msg = I18N.noHeatmapPages || I18N.noData || 'No page activity in this range yet.';
			[ 'heatmap-elements', 'heatmap-keywords', 'scroll-depth' ].forEach( function ( key ) {
				var box = attr( key );
				if ( box ) {
					empty( box, msg );
				}
			} );
			var note = attr( 'heatmap-note' );
			if ( note ) {
				note.textContent = msg;
			}
			var meta = attr( 'heatmap-meta' );
			if ( meta ) {
				meta.textContent = '';
			}
		}

		function loadPages() {
			var range = rangeSel ? rangeSel.value : 7;
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( d ) {
					if ( ! postSel || ! d.top_pages ) {
						return;
					}
					var cur = postSel.value;
					while ( postSel.options.length > 1 ) {
						postSel.remove( 1 );
					}
					d.top_pages.forEach( function ( p ) {
						if ( p.post_id > 0 ) {
							var o = document.createElement( 'option' );
							o.value = p.post_id;
							o.textContent = p.title;
							postSel.appendChild( o );
						}
					} );
					if ( ( cur === '0' || ! cur ) && postSel.options.length > 1 ) {
						postSel.selectedIndex = 1;
					} else {
						postSel.value = cur;
					}
					// No pages have activity yet: show clear guidance instead of an endless skeleton.
					if ( postSel.options.length <= 1 ) {
						showNoPages();
						return;
					}
					load();
				} )
				.catch( function () {} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', loadPages );
		}
		if ( postSel ) {
			postSel.addEventListener( 'change', load );
		}
		if ( deviceSel ) {
			deviceSel.addEventListener( 'change', function () {
				syncDeviceToggle();
				load();
			} );
		}
		if ( deviceToggle && deviceSel ) {
			deviceToggle.addEventListener( 'click', function ( e ) {
				var btn = e.target && e.target.closest ? e.target.closest( '[data-cvtrk-device]' ) : null;
				if ( ! btn ) {
					return;
				}
				deviceSel.value = btn.getAttribute( 'data-cvtrk-device' ) || 'desktop';
				syncDeviceToggle();
				load();
			} );
		}
		if ( modeSel ) {
			modeSel.addEventListener( 'change', renderCurrent );
		}
		if ( showChk ) {
			showChk.addEventListener( 'change', function () {
				if ( showChk.checked && data && ! data.snapshot ) {
					loadSnapshot( postSel ? postSel.value : 0, selectedDevice() ).then( function ( snapshot ) {
						data.snapshot = snapshot;
						renderCurrent();
					}, renderCurrent );
				} else {
					renderCurrent();
				}
			} );
		}
		window.addEventListener( 'resize', function () {
			if ( resizeTimer ) {
				window.clearTimeout( resizeTimer );
			}
			resizeTimer = window.setTimeout( renderCurrent, 120 );
		} );
		syncDeviceToggle();
		loadPages();
	}

	function updateExports( range, post ) {
		if ( ! C.exportUrl ) {
			return;
		}
		var links = document.querySelectorAll( '[data-cvtrk-export]' );
		for ( var i = 0; i < links.length; i++ ) {
			var url = C.exportUrl +
				'&type=' + encodeURIComponent( links[ i ].getAttribute( 'data-type' ) ) +
				'&range=' + encodeURIComponent( range ) +
				'&_wpnonce=' + encodeURIComponent( C.exportNonce );
			if ( post ) {
				url += '&post=' + encodeURIComponent( post );
			}
			links[ i ].setAttribute( 'href', url );
		}
	}

	/* Funnels ------------------------------------------------------------- */

	function renderFunnelCards( data ) {
		set( 'funnel-sessions', num( data.total_sessions ) );
		set( 'funnel-converting', num( data.converting_sessions ) );
		set( 'funnel-conversions', num( data.total_conversions ) );
		set( 'funnel-rate', ( Number( data.conversion_rate ) || 0 ) + '%' );
	}

	function renderFunnelPaths( items ) {
		var box = attr( 'funnel-paths' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.sessions || 0 ); } );
		var t = table( [
			{ label: I18N.page || 'Path' },
			{ label: I18N.sessions || 'Sessions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( it.path || '', '' ) );
			tr.appendChild( clicksCell( it.sessions, max ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderFunnelDropoffs( items ) {
		var box = attr( 'funnel-dropoffs' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.sessions || 0 ); } );
		var t = table( [
			{ label: I18N.page || 'Page' },
			{ label: I18N.sessions || 'Sessions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var label = it.title || it.url || '';
			var tr = el( 'tr' );
			tr.appendChild( labelCell( label, it.url || '' ) );
			tr.appendChild( clicksCell( it.sessions, max ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderFunnelSources( items ) {
		var box = attr( 'funnel-sources' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.sessions || 0 ); } );
		var t = table( [
			{ label: I18N.source || 'Source' },
			{ label: I18N.sessions || 'Sessions', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var source = it.source || 'Direct';
			var tr = el( 'tr' );
			tr.appendChild( labelCell( source, it.campaign || '' ) );
			tr.appendChild( clicksCell( it.sessions, max ) );
			tr.appendChild( convCell( it.conversions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderFunnelButtons( items ) {
		var box = attr( 'funnel-buttons' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			empty( box, I18N.noData );
			return;
		}
		var max = 1;
		items.forEach( function ( it ) { max = Math.max( max, it.clicks || 0 ); } );
		var t = table( [
			{ label: I18N.button || 'Button' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.sessions || 'Sessions', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var label = it.element_text || it.element_selector || '';
			var tr = el( 'tr' );
			tr.appendChild( labelCell( label, label !== it.element_selector ? it.element_selector : '' ) );
			tr.appendChild( clicksCell( it.clicks, max ) );
			tr.appendChild( numCell( it.sessions ) );
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	/* Data loaders -------------------------------------------------------- */

	function loadActive() {
		api( '/stats/active' )
			.then( function ( data ) {
				setLive( data.active );
				renderSessions( data.sessions );
			} )
			.catch( function () {} );
	}

	function initLive() {
		if ( ! attr( 'active' ) ) {
			return;
		}
		loadActive();
		window.setInterval( loadActive, C.activeRefresh || 10000 );
	}

	function initOverview() {
		var rangeSel = attr( 'range' );
		var timelineType = attr( 'timeline-type' );
		var timelineFilter = attr( 'timeline-filter' );
		var timelineSort = attr( 'timeline-sort' );

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			updateExports( range, 0 );
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					renderCards( data.totals );
					set( 'avg_duration', formatDuration( data.avg_session_seconds ) );
					renderChart( data.series );
					renderHourlyChart( data.activity_hours );
					renderEngagement( data.engagement );
					renderButtons( data.top_buttons );
					renderPages( data.top_pages, null );
					renderSources( data.top_sources );
					renderSearchTerms( 'top-search-terms', data.top_search_terms, data.search_keywords_enabled );
					renderCountries( data.top_countries, data.geo_enabled );
					renderTimeline( data.recent_events || [] );
					setLastUpdated();
					setLive( data.active );
					toggleConvHint( data.totals );
				} )
				.catch( function () {} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', load );
		}
		if ( timelineType ) {
			timelineType.addEventListener( 'change', function () { renderTimeline(); } );
		}
		if ( timelineFilter ) {
			timelineFilter.addEventListener( 'input', function () { renderTimeline(); } );
		}
		if ( timelineSort ) {
			timelineSort.addEventListener( 'change', function () { renderTimeline(); } );
		}
		load();
	}

	function initPages() {
		var rangeSel = attr( 'range' );
		var postSel = attr( 'post' );
		var titleEl = attr( 'buttons-title' );
		var pagesLoaded = false;

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			var post = postSel ? postSel.value : 0;
			updateExports( range, post );

			api( '/stats/summary?range=' + encodeURIComponent( range ) + '&post=' + encodeURIComponent( post ) )
				.then( function ( data ) {
					renderPages( data.top_pages, function ( it ) {
						if ( postSel ) {
							ensureOption( postSel, it.post_id, it.title );
							postSel.value = String( it.post_id );
						}
						load();
					} );
					renderButtons( data.top_buttons );

					if ( titleEl ) {
						var sel = postSel && postSel.options[ postSel.selectedIndex ];
						titleEl.textContent = ( I18N.topButtons || 'Buttons clicked' ) +
							( post && post !== '0' && sel ? ' — ' + sel.textContent : '' );
					}

					if ( ! pagesLoaded && postSel ) {
						data.top_pages.forEach( function ( p ) {
							ensureOption( postSel, p.post_id, p.title );
						} );
						pagesLoaded = true;
					}
				} )
				.catch( function () {} );
		}

		function ensureOption( select, value, label ) {
			value = String( value );
			for ( var i = 0; i < select.options.length; i++ ) {
				if ( select.options[ i ].value === value ) {
					return;
				}
			}
			var opt = document.createElement( 'option' );
			opt.value = value;
			opt.textContent = label;
			select.appendChild( opt );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', load );
		}
		if ( postSel ) {
			postSel.addEventListener( 'change', load );
		}
		load();
	}

	function initFunnels() {
		var rangeSel = attr( 'range' );

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			api( '/stats/funnels?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					renderFunnelCards( data );
					renderFunnelPaths( data.paths );
					renderFunnelDropoffs( data.dropoffs );
					renderFunnelSources( data.sources );
					renderFunnelButtons( data.buttons );
				} )
				.catch( function () {} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', load );
		}
		load();
	}

	function initGsc() {
		var root = document.getElementById( 'convertrack-gsc' );
		if ( ! root ) {
			return;
		}

		var state = { page: 1, pages: 1, perPage: 25 };
		var proc = { running: false };
		var indexingApiOn = root.getAttribute( 'data-gsc-indexing-api' ) === '1';
		var statusSel = attr( 'gsc-status' );
		var postTypeSel = attr( 'gsc-post-type' );
		var prioritySel = attr( 'gsc-priority' );
		var sitemapSel = attr( 'gsc-sitemap' );
		var checkedFrom = attr( 'gsc-checked-from' );
		var checkedTo = attr( 'gsc-checked-to' );
		var exportLink = attr( 'gsc-export' );
		var postTabs = attr( 'gsc-post-tabs' );

		function statusText( value ) {
			return String( value || '' ).replace( /_/g, ' ' ).replace( /\b\w/g, function ( c ) {
				return c.toUpperCase();
			} );
		}

		function dateText( value ) {
			return value || '-';
		}

		function loadSummary() {
			api( '/gsc/summary' )
				.then( function ( data ) {
					renderGscSummary( data );
					renderGscSitemapOptions( data.sitemaps || [] );
					if ( ! proc.running && data.last_batch_error && data.last_batch_error.message ) {
						setGscProgress( data.last_batch_error.message, true );
					}
				} )
				.catch( function () {} );
		}

		function renderGscSitemapOptions( items ) {
			if ( ! sitemapSel || sitemapSel.getAttribute( 'data-loaded' ) === '1' || ! items.length ) {
				return;
			}
			items.forEach( function ( item ) {
				var opt = document.createElement( 'option' );
				opt.value = item.hash || '';
				opt.textContent = ( item.url || '' ) + ' (' + num( item.total ) + ')';
				sitemapSel.appendChild( opt );
			} );
			sitemapSel.setAttribute( 'data-loaded', '1' );
		}

		function gscDrillTo( status ) {
			if ( statusSel ) {
				statusSel.value = status;
			}
			state.page = 1;
			loadUrls();
			var queue = document.getElementById( 'convertrack-gsc-queue' );
			if ( queue && queue.scrollIntoView ) {
				queue.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				var heading = queue.querySelector( 'h2' );
				if ( heading ) {
					heading.setAttribute( 'tabindex', '-1' );
					heading.focus( { preventScroll: true } );
				}
			}
		}

		function gscTrend( history ) {
			var wrap = el( 'div', 'cvtrk-gsc-trend' );
			wrap.appendChild( el( 'h3', 'cvtrk-gsc-trend-title', I18N.indexingProgress || 'Indexing progress' ) );

			if ( ! history || history.length < 2 ) {
				wrap.className = 'cvtrk-gsc-trend is-empty';
				wrap.appendChild( el( 'p', 'cvtrk-gsc-trend-empty', I18N.collectingData || 'Collecting daily data — the progress line appears after a couple of days of monitoring.' ) );
				return wrap;
			}

			var width = 560, height = 210;
			var pad = { top: 16, right: 18, bottom: 28, left: 44 };
			var innerW = width - pad.left - pad.right;
			var innerH = height - pad.top - pad.bottom;
			var max = 1;
			history.forEach( function ( h ) { max = Math.max( max, Number( h.total ) || 0, Number( h.indexed ) || 0 ); } );

			var svg = svgEl( 'svg', { class: 'cvtrk-linechart', viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': I18N.indexingProgress || 'Indexing progress' } );
			[ 0, 0.5, 1 ].forEach( function ( step ) {
				var y = pad.top + innerH * step;
				svg.appendChild( svgEl( 'line', { class: 'cvtrk-chart-gridline', x1: pad.left, y1: y, x2: width - pad.right, y2: y } ) );
			} );

			function xy( i, key ) {
				var x = pad.left + ( history.length === 1 ? innerW / 2 : ( innerW * i ) / ( history.length - 1 ) );
				var y = pad.top + innerH - ( ( Number( history[ i ][ key ] ) || 0 ) / max ) * innerH;
				return { x: x, y: y };
			}
			function pathFor( key ) {
				return history.map( function ( h, i ) {
					var p = xy( i, key );
					return ( i === 0 ? 'M' : 'L' ) + p.x.toFixed( 2 ) + ' ' + p.y.toFixed( 2 );
				} ).join( ' ' );
			}

			svg.appendChild( svgEl( 'path', { class: 'cvtrk-line is-total', d: pathFor( 'total' ) } ) );
			svg.appendChild( svgEl( 'path', { class: 'cvtrk-line is-indexed', d: pathFor( 'indexed' ) } ) );

			var zero = svgEl( 'text', { class: 'cvtrk-chart-axis is-end', x: pad.left - 6, y: height - pad.bottom + 4 } );
			zero.textContent = '0';
			svg.appendChild( zero );
			var maxLabel = svgEl( 'text', { class: 'cvtrk-chart-axis is-end', x: pad.left - 6, y: pad.top + 4 } );
			maxLabel.textContent = num( max );
			svg.appendChild( maxLabel );
			var firstLabel = svgEl( 'text', { class: 'cvtrk-chart-axis', x: pad.left, y: height - 8 } );
			firstLabel.textContent = String( history[ 0 ].date || '' ).slice( 5 );
			svg.appendChild( firstLabel );
			var lastLabel = svgEl( 'text', { class: 'cvtrk-chart-axis is-end', x: width - pad.right, y: height - 8 } );
			lastLabel.textContent = String( history[ history.length - 1 ].date || '' ).slice( 5 );
			svg.appendChild( lastLabel );
			wrap.appendChild( svg );

			var legend = el( 'div', 'cvtrk-legend' );
			[ { cls: 'is-total', label: I18N.totalUrls || 'Total URLs Found' }, { cls: 'is-indexed', label: I18N.indexed || 'Indexed' } ].forEach( function ( lg ) {
				var lit = el( 'span', 'cvtrk-legend-item' );
				lit.appendChild( el( 'span', 'cvtrk-swatch ' + lg.cls ) );
				lit.appendChild( el( 'span', null, lg.label ) );
				legend.appendChild( lit );
			} );
			wrap.appendChild( legend );
			return wrap;
		}

		function renderGscSummary( data ) {
			var box = attr( 'gsc-summary' );
			if ( ! box ) {
				return;
			}
			clear( box );

			function metaItem( label, value ) {
				var item = el( 'span', 'cvtrk-gsc-meta-item' );
				item.appendChild( el( 'span', 'cvtrk-gsc-meta-label', label ) );
				item.appendChild( el( 'span', 'cvtrk-gsc-meta-value', value || '-' ) );
				return item;
			}
			function figure( value, label ) {
				var f = el( 'div', 'cvtrk-gsc-figure' );
				f.appendChild( el( 'span', 'cvtrk-gsc-figure-value', value ) );
				f.appendChild( el( 'span', 'cvtrk-gsc-figure-label', label ) );
				return f;
			}

			var total = Number( data.total ) || 0;
			var indexed = Number( data.indexed ) || 0;
			var pending = ( Number( data.pending_due_to_quota ) || 0 ) + ( Number( data.pending_from_sitemap ) || 0 ) +
				( Number( data.crawled_not_indexed ) || 0 ) + ( Number( data.discovered_not_indexed ) || 0 );
			var issues = ( Number( data.not_indexed ) || 0 ) + ( Number( data.duplicate_canonical ) || 0 ) +
				( Number( data.blocked_by_robots ) || 0 ) + ( Number( data.noindex_detected ) || 0 ) + ( Number( data.errors ) || 0 );
			var pct = total > 0 ? Math.round( ( indexed / total ) * 100 ) : 0;

			var card = el( 'div', 'cvtrk-card cvtrk-gsc-coverage' );
			var head = el( 'div', 'cvtrk-card-head cvtrk-card-head-controls' );
			var headText = el( 'div' );
			headText.appendChild( el( 'h2', null, I18N.indexCoverage || 'Index coverage' ) );
			headText.appendChild( el( 'span', 'cvtrk-card-sub', I18N.indexCoverageSub || 'Click a status below to see its URLs' ) );
			head.appendChild( headText );
			var meta = el( 'div', 'cvtrk-gsc-meta' );
			meta.appendChild( metaItem( I18N.lastSync || 'Last Sync Time', dateText( data.last_sync_time ) ) );
			meta.appendChild( metaItem( I18N.nextCheck || 'Next Scheduled Check', dateText( data.next_scheduled_check ) ) );
			head.appendChild( meta );
			card.appendChild( head );

			var body = el( 'div', 'cvtrk-card-body cvtrk-gsc-coverage-body' );

			var cover = el( 'div', 'cvtrk-gsc-cover' );
			var segItems = [
				{ label: I18N.indexed || 'Indexed', value: indexed, color: '#188a6b' },
				{ label: I18N.pending || 'Pending', value: pending, color: '#c58a24' },
				{ label: I18N.issues || 'Issues', value: issues, color: '#b84a62' }
			];
			var donutSum = segItems.reduce( function ( s, it ) { return s + it.value; }, 0 );
			var donutWrap = el( 'div', 'cvtrk-donut-wrap cvtrk-gsc-donut-wrap' );
			var donut = el( 'div', 'cvtrk-donut cvtrk-gsc-donut' );
			if ( donutSum > 0 ) {
				var start = 0;
				var stops = segItems.map( function ( it ) {
					var end = start + ( it.value / donutSum ) * 100;
					var part = it.color + ' ' + start.toFixed( 2 ) + '% ' + end.toFixed( 2 ) + '%';
					start = end;
					return part;
				} );
				donut.style.background = 'conic-gradient(' + stops.join( ',' ) + ')';
			} else {
				donut.style.background = 'conic-gradient(#e6edf1 0% 100%)';
			}
			var center = el( 'div', 'cvtrk-gsc-donut-center' );
			center.appendChild( el( 'span', 'cvtrk-gsc-donut-pct', total > 0 ? pct + '%' : '—' ) );
			center.appendChild( el( 'span', 'cvtrk-gsc-donut-cap', total > 0 ? ( I18N.indexed || 'Indexed' ) : ( I18N.noDataYet || 'No data yet' ) ) );
			donut.appendChild( center );
			donutWrap.appendChild( donut );

			var list = el( 'div', 'cvtrk-donut-list' );
			segItems.forEach( function ( it ) {
				var row = el( 'div', 'cvtrk-donut-row' );
				var key = el( 'span', 'cvtrk-donut-key' );
				key.style.background = it.color;
				row.appendChild( key );
				row.appendChild( el( 'span', 'cvtrk-donut-label', it.label ) );
				row.appendChild( el( 'span', 'cvtrk-donut-value', num( it.value ) ) );
				list.appendChild( row );
			} );
			donutWrap.appendChild( list );
			cover.appendChild( donutWrap );

			var figures = el( 'div', 'cvtrk-gsc-figures' );
			figures.appendChild( figure( num( total ), I18N.totalUrls || 'Total URLs Found' ) );
			figures.appendChild( figure( num( indexed ), ( I18N.indexed || 'Indexed' ) + ' · ' + pct + '%' ) );
			cover.appendChild( figures );

			body.appendChild( cover );
			body.appendChild( gscTrend( data.history || [] ) );
			card.appendChild( body );
			box.appendChild( card );

			var groups = [
				{ title: I18N.indexed || 'Indexed', tone: 'green', items: [
					{ key: 'indexed', status: 'indexed', label: I18N.indexed || 'Indexed', icon: 'yes-alt' }
				] },
				{ title: I18N.pending || 'Pending', tone: 'amber', items: [
					{ key: 'pending_from_sitemap', status: 'pending_from_sitemap', label: I18N.pendingSitemap || 'Pending From Sitemap', icon: 'media-code' },
					{ key: 'pending_due_to_quota', status: 'pending_due_to_quota', label: I18N.pendingQuota || 'Pending Due to Quota', icon: 'clock' },
					{ key: 'discovered_not_indexed', status: 'discovered_not_indexed', label: I18N.discoveredNotIndexed || 'Discovered But Not Indexed', icon: 'search' },
					{ key: 'crawled_not_indexed', status: 'crawled_not_indexed', label: I18N.crawledNotIndexed || 'Crawled But Not Indexed', icon: 'visibility' }
				] },
				{ title: I18N.issues || 'Issues', tone: 'red', items: [
					{ key: 'not_indexed', status: 'not_indexed', label: I18N.notIndexed || 'Not Indexed', icon: 'dismiss' },
					{ key: 'duplicate_canonical', status: 'duplicate_canonical', label: I18N.duplicateCanonical || 'Duplicate/Canonical Issue', icon: 'admin-page' },
					{ key: 'blocked_by_robots', status: 'blocked_by_robots', label: I18N.blockedRobots || 'Blocked by Robots', icon: 'shield' },
					{ key: 'noindex_detected', status: 'noindex_detected', label: I18N.noindexDetected || 'Noindex Detected', icon: 'hidden' },
					{ key: 'errors', status: 'error', label: I18N.errors || 'Errors', icon: 'warning' }
				] }
			];

			var bd = el( 'div', 'cvtrk-card' );
			var bdHead = el( 'div', 'cvtrk-card-head' );
			var bdHeadText = el( 'div' );
			bdHeadText.appendChild( el( 'h2', null, I18N.coverageBreakdown || 'Coverage breakdown' ) );
			bdHead.appendChild( bdHeadText );
			bd.appendChild( bdHead );
			var bdBody = el( 'div', 'cvtrk-card-body cvtrk-gsc-groups' );

			groups.forEach( function ( group ) {
				var section = el( 'div', 'cvtrk-gsc-group is-' + group.tone );
				section.appendChild( el( 'h3', 'cvtrk-gsc-group-title', group.title ) );
				var grid = el( 'div', 'cvtrk-gsc-breakdown' );
				group.items.forEach( function ( item ) {
					var stat = el( 'button', 'cvtrk-gsc-stat is-clickable is-' + group.tone );
					stat.type = 'button';
					var value = num( data[ item.key ] );
					stat.setAttribute( 'aria-label', item.label + ': ' + value );
					var ic = el( 'span', 'cvtrk-gsc-stat-icon' );
					ic.appendChild( svgIcon( item.icon, 'cvtrk-icon' ) );
					stat.appendChild( ic );
					var sbody = el( 'span', 'cvtrk-gsc-stat-body' );
					sbody.appendChild( el( 'span', 'cvtrk-gsc-stat-value', value ) );
					sbody.appendChild( el( 'span', 'cvtrk-gsc-stat-label', item.label ) );
					stat.appendChild( sbody );
					stat.addEventListener( 'click', function () { gscDrillTo( item.status ); } );
					grid.appendChild( stat );
				} );
				section.appendChild( grid );
				bdBody.appendChild( section );
			} );

			bd.appendChild( bdBody );
			box.appendChild( bd );
		}

		function query() {
			var q = '?page=' + encodeURIComponent( state.page ) + '&per_page=' + encodeURIComponent( state.perPage );
			q += '&status=' + encodeURIComponent( statusSel ? statusSel.value : 'all' );
			q += '&post_type=' + encodeURIComponent( postTypeSel ? postTypeSel.value : 'all' );
			if ( prioritySel && prioritySel.value !== '' ) {
				q += '&priority=' + encodeURIComponent( prioritySel.value );
			}
			if ( sitemapSel && sitemapSel.value !== '' ) {
				q += '&sitemap_hash=' + encodeURIComponent( sitemapSel.value );
			}
			if ( checkedFrom && checkedFrom.value ) {
				q += '&checked_from=' + encodeURIComponent( checkedFrom.value );
			}
			if ( checkedTo && checkedTo.value ) {
				q += '&checked_to=' + encodeURIComponent( checkedTo.value );
			}
			return q;
		}

		function updateExport() {
			if ( ! exportLink || ! C.gscExportUrl ) {
				return;
			}
			var href = C.gscExportUrl + '&_wpnonce=' + encodeURIComponent( C.gscExportNonce || '' );
			href += '&status=' + encodeURIComponent( statusSel ? statusSel.value : 'all' );
			href += '&post_type=' + encodeURIComponent( postTypeSel ? postTypeSel.value : 'all' );
			if ( prioritySel && prioritySel.value !== '' ) {
				href += '&priority=' + encodeURIComponent( prioritySel.value );
			}
			if ( sitemapSel && sitemapSel.value !== '' ) {
				href += '&sitemap_hash=' + encodeURIComponent( sitemapSel.value );
			}
			if ( checkedFrom && checkedFrom.value ) {
				href += '&checked_from=' + encodeURIComponent( checkedFrom.value );
			}
			if ( checkedTo && checkedTo.value ) {
				href += '&checked_to=' + encodeURIComponent( checkedTo.value );
			}
			exportLink.href = href;
		}

		function syncPostTabs() {
			if ( ! postTabs || ! postTypeSel ) {
				return;
			}
			var value = postTypeSel.value || 'all';
			postTabs.querySelectorAll( '[data-gsc-post-tab]' ).forEach( function ( tab ) {
				if ( tab.getAttribute( 'data-gsc-post-tab' ) === value ) {
					tab.classList.add( 'is-active' );
				} else {
					tab.classList.remove( 'is-active' );
				}
			} );
		}

		function loadUrls() {
			syncPostTabs();
			updateExport();
			api( '/gsc/urls' + query() )
				.then( function ( data ) {
					state.pages = data.pages || 1;
					renderGscUrls( data.rows || [] );
					renderGscPagination( data );
				} )
				.catch( function () {} );
		}

		function renderGscUrls( rows ) {
			var box = attr( 'gsc-urls' );
			if ( ! box ) {
				return;
			}
			state.lastRows = rows;
			clear( box );
			if ( ! rows.length ) {
				empty( box, I18N.noData );
				return;
			}

			var t = table( [
				{ label: 'URL' },
				{ label: 'Post Type' },
				{ label: I18N.gscStatus || 'Google Index Status' },
				{ label: I18N.coverageState || 'Coverage State' },
				{ label: I18N.googleVerdict || 'Google Verdict' },
				{ label: 'Last Checked' },
				{ label: I18N.nextCheck || 'Next Check' },
				{ label: I18N.attempts || 'Attempts', num: true },
				{ label: I18N.actions || 'Actions' }
			] );
			var body = t.querySelector( 'tbody' );
			rows.forEach( function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( labelCell( row.post_title || row.url, row.url ) );
				tr.appendChild( el( 'td', null, row.post_type || '-' ) );
				var status = el( 'td' );
				var badge = el( 'span', 'cvtrk-badge cvtrk-gsc-status-' + ( row.index_status || '' ), statusText( row.index_status ) );
				if ( row.error_message ) {
					badge.title = row.error_message;
				}
				status.appendChild( badge );
				tr.appendChild( status );
				tr.appendChild( el( 'td', null, row.coverage_state || row.error_message || '-' ) );
				tr.appendChild( el( 'td', null, row.google_verdict || '-' ) );
				tr.appendChild( el( 'td', null, dateText( row.last_checked_at ) ) );
				tr.appendChild( el( 'td', null, dateText( row.next_check_at ) ) );
				tr.appendChild( numCell( row.attempt_count ) );
				tr.appendChild( gscActionsCell( row ) );
				body.appendChild( tr );
			} );
			box.appendChild( t );
		}

		function gscActionsCell( row ) {
			var td = el( 'td', 'cvtrk-gsc-actions' );
			var recheck = el( 'button', 'button button-small', 'Recheck' );
			recheck.type = 'button';
			recheck.setAttribute( 'data-gsc-action', 'recheck' );
			recheck.setAttribute( 'data-gsc-id', row.id );
			td.appendChild( recheck );

			if ( row.url ) {
				var open = el( 'a', 'button button-small', 'Open' );
				open.href = row.url;
				open.target = '_blank';
				open.rel = 'noopener noreferrer';
				td.appendChild( open );
			}
			if ( row.edit_link ) {
				var edit = el( 'a', 'button button-small', 'Edit' );
				edit.href = row.edit_link;
				td.appendChild( edit );
			}
			if ( row.inspection_result_link ) {
				var inspect = el( 'a', 'button button-small', 'Inspect' );
				inspect.href = row.inspection_result_link;
				inspect.target = '_blank';
				inspect.rel = 'noopener noreferrer';
				inspect.title = I18N.gscInspectHint || 'Opens Google Search Console, where you can use "Request Indexing".';
				td.appendChild( inspect );
			}

			var notifiable = [ 'not_indexed', 'crawled_not_indexed', 'discovered_not_indexed', 'pending_from_sitemap', 'queued', 'error' ];
			if ( indexingApiOn && notifiable.indexOf( row.index_status ) !== -1 ) {
				var notify = el( 'button', 'button button-small', I18N.gscNotifyGoogle || 'Notify Google' );
				notify.type = 'button';
				notify.setAttribute( 'data-gsc-action', 'indexing-notify' );
				notify.setAttribute( 'data-gsc-id', row.id );
				notify.title = I18N.gscNotifyHint || 'Sends a Google Indexing API notification for this URL. Google officially supports this only for job-posting and livestream pages.';
				td.appendChild( notify );
			}

			var priority = el( 'button', 'button button-small', row.priority ? 'Normal' : 'Priority' );
			priority.type = 'button';
			priority.setAttribute( 'data-gsc-action', 'priority' );
			priority.setAttribute( 'data-gsc-id', row.id );
			priority.setAttribute( 'data-gsc-priority', row.priority ? '0' : '1' );
			td.appendChild( priority );

			var ignore = el( 'button', 'button button-small', 'Ignore' );
			ignore.type = 'button';
			ignore.setAttribute( 'data-gsc-action', 'ignore' );
			ignore.setAttribute( 'data-gsc-id', row.id );
			td.appendChild( ignore );

			return td;
		}

		function renderGscPagination( data ) {
			var pageEl = attr( 'gsc-page' );
			var prev = attr( 'gsc-prev' );
			var next = attr( 'gsc-next' );
			if ( pageEl ) {
				pageEl.textContent = 'Page ' + ( data.page || 1 ) + ' of ' + ( data.pages || 1 ) + ' (' + num( data.total ) + ')';
			}
			if ( prev ) {
				prev.disabled = state.page <= 1;
			}
			if ( next ) {
				next.disabled = state.page >= state.pages;
			}
		}

		function loadLogs() {
			api( '/gsc/logs?limit=50' )
				.then( function ( data ) {
					renderGscLogs( data.rows || [] );
				} )
				.catch( function () {} );
		}

		function renderGscLogs( rows ) {
			var box = attr( 'gsc-logs' );
			if ( ! box ) {
				return;
			}
			clear( box );
			if ( ! rows.length ) {
				empty( box, I18N.noData );
				return;
			}
			var t = table( [
				{ label: 'Time' },
				{ label: 'Level' },
				{ label: 'Source' },
				{ label: 'Message' }
			] );
			var body = t.querySelector( 'tbody' );
			rows.forEach( function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', null, dateText( row.created_at ) ) );
				tr.appendChild( el( 'td', null, statusText( row.level ) ) );
				tr.appendChild( el( 'td', null, row.source || '-' ) );
				var msg = el( 'td', null, row.message || '-' );
				var ctx = null;
				try {
					ctx = row.context ? JSON.parse( row.context ) : null;
				} catch ( e ) {
					ctx = null;
				}
				if ( ctx && ( ctx.error || ctx.reason ) ) {
					var detail = String( ctx.error || ctx.reason );
					if ( ctx.url ) {
						detail = ctx.url + ' — ' + detail;
					}
					var detailEl = el( 'div', 'cvtrk-gsc-log-detail', detail );
					detailEl.style.color = '#b84a62';
					detailEl.style.fontSize = '12px';
					msg.appendChild( detailEl );
				}
				tr.appendChild( msg );
				body.appendChild( tr );
			} );
			box.appendChild( t );
		}

		function reloadAll() {
			loadSummary();
			loadUrls();
			loadLogs();
		}

		[ statusSel, postTypeSel, prioritySel, sitemapSel, checkedFrom, checkedTo ].forEach( function ( node ) {
			if ( node ) {
				node.addEventListener( 'change', function () {
					state.page = 1;
					loadUrls();
				} );
			}
		} );

		var prev = attr( 'gsc-prev' );
		var next = attr( 'gsc-next' );
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				state.page = Math.max( 1, state.page - 1 );
				loadUrls();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				state.page = Math.min( state.pages, state.page + 1 );
				loadUrls();
			} );
		}
		if ( postTabs && postTypeSel ) {
			postTabs.addEventListener( 'click', function ( e ) {
				var tab = e.target && e.target.closest ? e.target.closest( '[data-gsc-post-tab]' ) : null;
				if ( ! tab ) {
					return;
				}
				postTypeSel.value = tab.getAttribute( 'data-gsc-post-tab' ) || 'all';
				state.page = 1;
				loadUrls();
			} );
		}

		var urlsBox = attr( 'gsc-urls' );
		if ( urlsBox ) {
			urlsBox.addEventListener( 'click', function ( e ) {
				var btn = e.target && e.target.closest ? e.target.closest( '[data-gsc-action]' ) : null;
				if ( ! btn ) {
					return;
				}
				var id = btn.getAttribute( 'data-gsc-id' );
				var action = btn.getAttribute( 'data-gsc-action' );
				var body = { id: id };
				if ( action === 'priority' ) {
					body.priority = btn.getAttribute( 'data-gsc-priority' ) || '0';
				}
				btn.disabled = true;
				postApi( '/gsc/' + action, body )
					.then( function () {
						if ( action === 'indexing-notify' ) {
							setGscProgress( I18N.gscIndexingNotified || 'Google has been notified about this URL. The next recheck will show whether it was picked up.' );
						}
						reloadAll();
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						setGscProgress( ( err && err.message ) || 'Request failed.', true );
					} );
			} );
		}

		var scan = attr( 'gsc-scan' );
		var process = attr( 'gsc-process' );
		var progressBox = attr( 'gsc-progress' );
		var CHUNK = 5;
		var MAX_CHUNKS = 20;

		function setGscProgress( text, isError ) {
			if ( ! progressBox ) {
				return;
			}
			if ( ! text ) {
				progressBox.hidden = true;
				return;
			}
			progressBox.hidden = false;
			progressBox.textContent = text;
			progressBox.style.color = isError ? '#b84a62' : '';
		}

		function setGscButtonsDisabled( disabled ) {
			if ( scan ) {
				scan.disabled = disabled;
			}
			if ( process ) {
				process.disabled = disabled;
			}
		}

		function runInspectionLoop( knownTotal ) {
			if ( proc.running ) {
				return Promise.resolve();
			}
			proc.running = true;
			setGscButtonsDisabled( true );

			var done = 0;
			var errs = 0;
			var chunks = 0;
			var total = Number( knownTotal ) || 0;

			function step() {
				return postApi( '/gsc/process', { limit: CHUNK } ).then( function ( d ) {
					chunks++;
					done += ( Number( d.processed ) || 0 ) + ( Number( d.errors ) || 0 );
					errs += Number( d.errors ) || 0;
					var remaining = Number( d.remaining ) || 0;
					if ( done + remaining > total ) {
						total = done + remaining;
					}

					if ( d.busy ) {
						setGscProgress( I18N.gscBackground || 'Another inspection run is already active — processing will continue in the background.' );
						return;
					}
					if ( d.aborted ) {
						setGscProgress( ( I18N.gscInspectStopped || 'Inspection stopped:' ) + ' ' + ( d.abort_reason || d.last_error || '' ), true );
						return;
					}
					if ( d.quota_reached ) {
						setGscProgress( I18N.gscQuotaReached || 'Daily inspection quota reached. Remaining URLs will be checked automatically tomorrow.', true );
						return;
					}
					if ( remaining <= 0 || chunks >= MAX_CHUNKS ) {
						var doneText = ( I18N.gscInspectDone || 'Inspection finished:' ) + ' ' + done + ' ' + ( I18N.gscUrlsChecked || 'URLs checked' );
						if ( errs > 0 ) {
							doneText += ', ' + errs + ' ' + ( I18N.errors || 'Errors' ).toLowerCase() + ( d.last_error ? ' — ' + d.last_error : '' );
						}
						if ( remaining > 0 ) {
							doneText += ' (' + remaining + ' ' + ( I18N.gscRemaining || 'remaining, continuing in the background' ) + ')';
						}
						setGscProgress( doneText, errs > 0 );
						return;
					}

					setGscProgress( ( I18N.gscInspecting || 'Inspecting URLs…' ) + ' ' + done + ' / ' + total + ( errs > 0 ? ' — ' + errs + ' ' + ( I18N.errors || 'Errors' ).toLowerCase() : '' ) );
					return step();
				} );
			}

			setGscProgress( ( I18N.gscInspecting || 'Inspecting URLs…' ) + ( total > 0 ? ' 0 / ' + total : '' ) );

			return step()
				.catch( function ( err ) {
					setGscProgress( ( I18N.gscInspectFailed || 'Inspection failed:' ) + ' ' + ( ( err && err.message ) || '' ), true );
				} )
				.then( function () {
					proc.running = false;
					setGscButtonsDisabled( false );
					reloadAll();
				} );
		}

		if ( scan ) {
			scan.addEventListener( 'click', function () {
				if ( proc.running ) {
					return;
				}
				setGscButtonsDisabled( true );
				setGscProgress( I18N.gscScanning || 'Scanning sitemap…' );
				postApi( '/gsc/scan-sitemap', {} )
					.then( function ( data ) {
						var found = Number( data.stored ) || Number( data.sitemap_urls ) || 0;
						var remaining = Number( data.remaining ) || 0;
						setGscButtonsDisabled( false );
						loadUrls();
						if ( remaining > 0 ) {
							setGscProgress( ( I18N.gscScanDone || 'Sitemap scan complete — URLs queued:' ) + ' ' + found + '. ' + ( I18N.gscStartingInspection || 'Starting inspection…' ) );
							return runInspectionLoop( remaining );
						}
						setGscProgress( ( I18N.gscScanDone || 'Sitemap scan complete — URLs queued:' ) + ' ' + found + '. ' + ( I18N.gscNothingDue || 'No URLs are currently due for inspection.' ) );
						reloadAll();
					} )
					.catch( function ( err ) {
						setGscButtonsDisabled( false );
						setGscProgress( ( I18N.gscScanFailed || 'Sitemap scan failed:' ) + ' ' + ( ( err && err.message ) || '' ), true );
						loadLogs();
					} );
			} );
		}

		if ( process ) {
			process.addEventListener( 'click', function () {
				runInspectionLoop( 0 );
			} );
		}

		// Bulk workflow: open the listed URLs (max 10) in Search Console tabs so
		// "Request Indexing" is one click away in each.
		var openGsc = attr( 'gsc-open-gsc' );
		if ( openGsc ) {
			openGsc.addEventListener( 'click', function () {
				var links = [];
				( state.lastRows || [] ).forEach( function ( row ) {
					if ( row.inspection_result_link && links.length < 10 ) {
						links.push( row.inspection_result_link );
					}
				} );
				if ( ! links.length ) {
					setGscProgress( I18N.gscNoInspectLinks || 'No URLs with Search Console links in the current view. Adjust the filters above first.', true );
					return;
				}
				var opened = 0;
				links.forEach( function ( href ) {
					var w = window.open( href, '_blank' );
					if ( w ) {
						w.opener = null;
						opened++;
					}
				} );
				if ( opened < links.length ) {
					setGscProgress( ( I18N.gscTabsBlocked || 'Your browser blocked some tabs — allow pop-ups for this site and click again. Opened:' ) + ' ' + opened + ' / ' + links.length, true );
				} else {
					setGscProgress( ( I18N.gscTabsOpened || 'Opened in Search Console:' ) + ' ' + opened + '. ' + ( I18N.gscTabsHint || 'Click "Request Indexing" in each tab.' ) );
				}
			} );
		}

		// Populate the property picker from the connected account's verified sites.
		var propertyPicker = attr( 'gsc-property-picker' );
		var propertyInput = attr( 'gsc-property-input' );
		var propertyStatus = attr( 'gsc-property-status' );

		function setPropertyStatus( text, isError ) {
			if ( ! propertyStatus ) {
				return;
			}
			if ( ! text ) {
				propertyStatus.hidden = true;
				return;
			}
			propertyStatus.hidden = false;
			propertyStatus.textContent = text;
			propertyStatus.style.color = isError ? '#b84a62' : '';
		}

		function loadProperties() {
			setPropertyStatus( I18N.gscPropsLoading || 'Loading Search Console properties…' );
			api( '/gsc/properties' )
				.then( function ( data ) {
					var sites = ( data && data.sites ) || [];
					if ( ! data || ! data.connected ) {
						setPropertyStatus( '' );
						return;
					}
					if ( ! sites.length ) {
						setPropertyStatus( I18N.gscPropsEmpty || 'No Search Console properties found for this account. Verify your site in Search Console first.', true );
						return;
					}
					while ( propertyPicker.options.length > 1 ) {
						propertyPicker.remove( 1 );
					}
					sites.forEach( function ( site ) {
						var opt = document.createElement( 'option' );
						opt.value = site.siteUrl;
						opt.textContent = site.siteUrl + ( site.permissionLevel === 'siteUnverifiedUser' ? ' (unverified)' : '' );
						if ( site.siteUrl === propertyInput.value ) {
							opt.selected = true;
						}
						propertyPicker.appendChild( opt );
					} );
					propertyPicker.style.display = '';
					setPropertyStatus( '' );
				} )
				.catch( function ( err ) {
					setPropertyStatus( ( I18N.gscPropsError || "Couldn't load properties:" ) + ' ' + ( ( err && err.message ) || '' ), true );
					if ( propertyStatus ) {
						propertyStatus.appendChild( document.createTextNode( ' ' ) );
						var retry = el( 'button', 'button-link', I18N.retry || 'Retry' );
						retry.type = 'button';
						retry.addEventListener( 'click', loadProperties );
						propertyStatus.appendChild( retry );
					}
				} );
		}

		if ( propertyPicker && propertyInput ) {
			loadProperties();
			propertyPicker.addEventListener( 'change', function () {
				if ( propertyPicker.value ) {
					propertyInput.value = propertyPicker.value;
				}
			} );
		}

		// Copy the redirect URI to register in Google Cloud.
		var copyRedirect = attr( 'gsc-copy-redirect' );
		var redirectInput = attr( 'gsc-redirect-uri' );
		if ( copyRedirect && redirectInput ) {
			copyRedirect.addEventListener( 'click', function () {
				redirectInput.focus();
				redirectInput.select();
				var done = false;
				try {
					done = document.execCommand( 'copy' );
				} catch ( e ) {} // eslint-disable-line no-empty
				if ( ! done && navigator.clipboard ) {
					navigator.clipboard.writeText( redirectInput.value ).catch( function () {} );
				}
				var original = copyRedirect.textContent;
				copyRedirect.textContent = I18N.copied || 'Copied';
				window.setTimeout( function () { copyRedirect.textContent = original; }, 1500 );
			} );
		}

		reloadAll();
	}

	/* Boot ---------------------------------------------------------------- */

	initLive();
	if ( document.getElementById( 'convertrack-overview' ) ) {
		initOverview();
	}
	if ( document.getElementById( 'convertrack-pages' ) ) {
		initPages();
	}
	if ( document.getElementById( 'convertrack-heatmaps' ) ) {
		initHeatmap();
	}
	if ( document.getElementById( 'convertrack-funnels' ) ) {
		initFunnels();
	}
	initGsc();
} )();
