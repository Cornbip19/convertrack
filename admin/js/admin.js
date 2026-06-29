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
				throw new Error( 'HTTP ' + r.status );
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

	function num( v ) {
		return ( Number( v ) || 0 ).toLocaleString();
	}

	function empty( box, msg ) {
		clear( box );
		var e = el( 'div', 'cvtrk-empty' );
		e.appendChild( el( 'span', 'dashicons dashicons-chart-bar' ) );
		e.appendChild( el( 'p', null, msg || I18N.noData || 'No data yet for this range.' ) );
		box.appendChild( e );
	}

	function table( headers ) {
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
		return t;
	}

	function rankCell( i ) {
		var td = el( 'td' );
		td.appendChild( el( 'span', 'cvtrk-rank', i + 1 ) );
		return td;
	}

	function labelCell( label, sub, subHref ) {
		var td = el( 'td' );
		td.appendChild( el( 'span', 'cvtrk-label', label ) );
		if ( sub ) {
			if ( subHref ) {
				var wrap = el( 'span', 'cvtrk-sub' );
				var a = el( 'a', null, sub );
				a.href = subHref;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				wrap.appendChild( a );
				td.appendChild( wrap );
			} else {
				td.appendChild( el( 'span', 'cvtrk-sub', sub ) );
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

	function renderChart( series ) {
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

	function pointPosition( p, w, h, frame, mode ) {
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

	function drawHeatCanvas( canvas, page, markers, points, maxW, height, frame, mode ) {
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
		var r = Math.max( 16, Math.round( Math.min( w, h ) * 0.035 ) );
		ctx.globalCompositeOperation = 'lighter';
		points.forEach( function ( p ) {
			var pos = pointPosition( p, w, h, frame, mode );
			var x = pos.x;
			var y = pos.y;
			if ( x < 0 || y < 0 || x > w || y > h ) {
				return;
			}
			var a = Math.max( 0.12, Math.min( 0.9, ( p.w / ( maxW || 1 ) ) ) );
			var g = ctx.createRadialGradient( x, y, 0, x, y, r );
			g.addColorStop( 0, 'rgba(255,70,0,' + a.toFixed( 3 ) + ')' );
			g.addColorStop( 0.55, 'rgba(255,170,0,' + ( a * 0.5 ).toFixed( 3 ) + ')' );
			g.addColorStop( 1, 'rgba(255,255,0,0)' );
			ctx.fillStyle = g;
			ctx.beginPath();
			ctx.arc( x, y, r, 0, 6.2832 );
			ctx.fill();
			if ( markers ) {
				var marker = el( 'span', 'cvtrk-heatmap-marker' );
				var size = Math.max( 8, Math.min( 20, Math.round( 7 + ( p.w / ( maxW || 1 ) ) * 13 ) ) );
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
						frame.style.height = Math.min( docH, 8000 ) + 'px';
						drawHeatCanvas( canvas, page, markers, points, maxW, docH, frame, mode );
					} else {
						frame.style.display = 'none';
						stage.classList.add( 'cvtrk-no-frame' );
						drawHeatCanvas( canvas, page, markers, points, maxW, null, null, 'page' );
					}
				}, 60 );
				window.setTimeout( function () {
					var docH = docHeight( frame );
					if ( docH > 0 ) {
						frame.style.height = Math.min( docH, 8000 ) + 'px';
						drawHeatCanvas( canvas, page, markers, points, maxW, docH, frame, mode );
					}
				}, 500 );
			};
			if ( frame.getAttribute( 'data-snapshot-url' ) !== data.snapshot.url ) {
				frame.setAttribute( 'data-snapshot-url', data.snapshot.url || '' );
				frame.removeAttribute( 'src' );
				frame.srcdoc = data.snapshot.html;
			} else {
				frame.onload();
			}
		} else {
			if ( frame ) {
				frame.style.display = 'none';
				frame.removeAttribute( 'data-snapshot-url' );
			}
			stage.classList.add( 'cvtrk-no-frame' );
			page.classList.add( 'cvtrk-no-frame' );
			drawHeatCanvas( canvas, page, markers, points, maxW, null, null, 'page' );
		}
	}

	function initHeatmap() {
		var rangeSel = attr( 'range' );
		var postSel = attr( 'post' );
		var deviceSel = attr( 'device' );
		var modeSel = attr( 'heatmap-mode' );
		var showChk = attr( 'show-page' );
		var data = null;
		var snapshots = {};
		var resizeTimer = null;

		function mode() {
			return modeSel ? modeSel.value : 'element';
		}

		function loadSnapshot( post ) {
			post = String( post || '' );
			if ( ! post || post === '0' ) {
				return Promise.resolve( null );
			}
			if ( snapshots[ post ] ) {
				return Promise.resolve( snapshots[ post ] );
			}
			return api( '/stats/heatmap-snapshot?post=' + encodeURIComponent( post ) )
				.then( function ( snapshot ) {
					snapshots[ post ] = snapshot;
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
			var device = deviceSel ? deviceSel.value : 'all';
			if ( ! post || post === '0' ) {
				return;
			}
			api( '/stats/heatmap?post=' + encodeURIComponent( post ) + '&range=' + encodeURIComponent( range ) + '&device=' + encodeURIComponent( device ) )
				.then( function ( d ) {
					data = d;
					if ( showChk && showChk.checked ) {
						return loadSnapshot( post ).then( function ( snapshot ) {
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
						meta.textContent = num( d.pageviews ) + ' ' + ( I18N.pageviews || 'Pageviews' ).toLowerCase() +
							' · ' + num( d.clicks ) + ' ' + ( I18N.clicksHere || 'clicks' );
					}
				} )
				.catch( function () {} );
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
			deviceSel.addEventListener( 'change', load );
		}
		if ( modeSel ) {
			modeSel.addEventListener( 'change', renderCurrent );
		}
		if ( showChk ) {
			showChk.addEventListener( 'change', function () {
				if ( showChk.checked && data && ! data.snapshot ) {
					loadSnapshot( postSel ? postSel.value : 0 ).then( function ( snapshot ) {
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

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			updateExports( range, 0 );
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					renderCards( data.totals );
					set( 'avg_duration', formatDuration( data.avg_session_seconds ) );
					renderChart( data.series );
					renderButtons( data.top_buttons );
					renderPages( data.top_pages, null );
					renderSources( data.top_sources );
					renderSearchTerms( 'top-search-terms', data.top_search_terms, data.search_keywords_enabled );
					renderCountries( data.top_countries, data.geo_enabled );
					setLive( data.active );
					toggleConvHint( data.totals );
				} )
				.catch( function () {} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', load );
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

		function renderGscSummary( data ) {
			var box = attr( 'gsc-summary' );
			if ( ! box ) {
				return;
			}
			clear( box );
			var cards = [
				{ key: 'total', label: I18N.totalUrls || 'Total URLs Found', icon: 'list-view' },
				{ key: 'indexed', label: I18N.indexed || 'Indexed', icon: 'yes-alt', cls: 'is-accent' },
				{ key: 'not_indexed', label: I18N.notIndexed || 'Not Indexed', icon: 'dismiss' },
				{ key: 'pending_due_to_quota', label: I18N.pendingQuota || 'Pending Due to Quota', icon: 'clock' },
				{ key: 'pending_from_sitemap', label: I18N.pendingSitemap || 'Pending From Sitemap', icon: 'media-code' },
				{ key: 'crawled_not_indexed', label: I18N.crawledNotIndexed || 'Crawled But Not Indexed', icon: 'visibility' },
				{ key: 'discovered_not_indexed', label: I18N.discoveredNotIndexed || 'Discovered But Not Indexed', icon: 'search' },
				{ key: 'duplicate_canonical', label: I18N.duplicateCanonical || 'Duplicate/Canonical Issue', icon: 'admin-page' },
				{ key: 'blocked_by_robots', label: I18N.blockedRobots || 'Blocked by Robots', icon: 'shield' },
				{ key: 'noindex_detected', label: I18N.noindexDetected || 'Noindex Detected', icon: 'hidden' },
				{ key: 'errors', label: I18N.errors || 'Errors', icon: 'warning' },
				{ key: 'last_sync_time', label: I18N.lastSync || 'Last Sync Time', icon: 'update', text: true },
				{ key: 'next_scheduled_check', label: I18N.nextCheck || 'Next Scheduled Check', icon: 'calendar-alt', text: true }
			];
			cards.forEach( function ( card ) {
				var item = el( 'div', 'cvtrk-kpi ' + ( card.cls || '' ) );
				var icon = el( 'span', 'cvtrk-kpi-icon' );
				icon.appendChild( el( 'span', 'dashicons dashicons-' + card.icon ) );
				var body = el( 'span', 'cvtrk-kpi-body' );
				body.appendChild( el( 'span', 'cvtrk-kpi-value', card.text ? dateText( data[ card.key ] ) : num( data[ card.key ] ) ) );
				body.appendChild( el( 'span', 'cvtrk-kpi-label', card.label ) );
				item.appendChild( icon );
				item.appendChild( body );
				box.appendChild( item );
			} );
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
				status.appendChild( el( 'span', 'cvtrk-badge cvtrk-gsc-status-' + ( row.index_status || '' ), statusText( row.index_status ) ) );
				tr.appendChild( status );
				tr.appendChild( el( 'td', null, row.coverage_state || '-' ) );
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
				td.appendChild( inspect );
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
				tr.appendChild( el( 'td', null, row.message || '-' ) );
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
					.then( reloadAll )
					.catch( function () {
						btn.disabled = false;
					} );
			} );
		}

		var scan = attr( 'gsc-scan' );
		if ( scan ) {
			scan.addEventListener( 'click', function () {
				scan.disabled = true;
				postApi( '/gsc/scan-sitemap', {} )
					.then( reloadAll )
					.catch( function () {} )
					.then( function () { scan.disabled = false; } );
			} );
		}

		var process = attr( 'gsc-process' );
		if ( process ) {
			process.addEventListener( 'click', function () {
				process.disabled = true;
				postApi( '/gsc/process', {} )
					.then( reloadAll )
					.catch( function () {} )
					.then( function () { process.disabled = false; } );
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
