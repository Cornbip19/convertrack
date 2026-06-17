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

	function api( path ) {
		return fetch( ROOT + path, {
			headers: { 'X-WP-Nonce': C.nonce },
			credentials: 'same-origin'
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
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

	function convCell( value ) {
		var td = el( 'td', 'cvtrk-num' );
		var v = Number( value ) || 0;
		td.appendChild( el( 'span', 'cvtrk-badge ' + ( v > 0 ? 'cvtrk-badge-green' : 'cvtrk-badge-gray' ), num( v ) ) );
		return td;
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
	}

	function set( key, value ) {
		var node = attr( key );
		if ( node ) {
			node.textContent = value;
		}
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
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.clicks || 'Clicks', num: true }
		] );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( it.title || it.url, it.url ) );
			tr.appendChild( numCell( it.page_views ) );
			tr.appendChild( numCell( it.clicks ) );
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
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					renderCards( data.totals );
					renderChart( data.series );
					renderButtons( data.top_buttons );
					renderPages( data.top_pages, null );
					setLive( data.active );
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

	/* Boot ---------------------------------------------------------------- */

	initLive();
	if ( document.getElementById( 'convertrack-overview' ) ) {
		initOverview();
	}
	if ( document.getElementById( 'convertrack-pages' ) ) {
		initPages();
	}
} )();
