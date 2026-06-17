/**
 * Convertrack admin dashboard.
 *
 * Pulls aggregated stats and live presence from the REST API and renders them
 * without any third-party charting dependency. All server-supplied strings are
 * inserted with textContent to stay XSS-safe.
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

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function attr( key, ctx ) {
		return ( ctx || document ).querySelector( '[data-cvtrk="' + key + '"]' );
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
		v = Number( v ) || 0;
		return v.toLocaleString();
	}

	/* Rendering ----------------------------------------------------------- */

	function renderCards( totals ) {
		if ( ! totals ) {
			return;
		}
		setCard( 'pageviews', num( totals.pageviews ) );
		setCard( 'clicks', num( totals.clicks ) );
		setCard( 'conversions', num( totals.conversions ) );
		setCard( 'unique_visitors', num( totals.unique_visitors ) );
		setCard( 'conversion_rate', ( Number( totals.conversion_rate ) || 0 ) + '%' );
		setCard( 'click_through', ( Number( totals.click_through ) || 0 ) + '%' );
	}

	function setCard( key, value ) {
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
			box.appendChild( el( 'p', 'convertrack-empty', I18N.noData || 'No data.' ) );
			return;
		}

		var max = 1;
		dates.forEach( function ( d ) {
			max = Math.max( max, Number( series[ d ].clicks ) || 0, Number( series[ d ].pageviews ) || 0 );
		} );

		var chart = el( 'div', 'convertrack-bars' );
		dates.forEach( function ( d ) {
			var row = series[ d ];
			var col = el( 'div', 'convertrack-bar-col' );

			var stack = el( 'div', 'convertrack-bar-stack' );
			var pv = el( 'span', 'convertrack-bar convertrack-bar-pv' );
			pv.style.height = ( ( ( Number( row.pageviews ) || 0 ) / max ) * 100 ) + '%';
			pv.title = d + ' · ' + ( I18N.pageviews || 'Pageviews' ) + ': ' + num( row.pageviews );
			var cl = el( 'span', 'convertrack-bar convertrack-bar-click' );
			cl.style.height = ( ( ( Number( row.clicks ) || 0 ) / max ) * 100 ) + '%';
			cl.title = d + ' · ' + ( I18N.clicks || 'Clicks' ) + ': ' + num( row.clicks );

			stack.appendChild( pv );
			stack.appendChild( cl );
			col.appendChild( stack );
			col.appendChild( el( 'span', 'convertrack-bar-label', d.slice( 5 ) ) );
			chart.appendChild( col );
		} );

		var legend = el( 'div', 'convertrack-legend' );
		legend.appendChild( legendItem( 'convertrack-bar-pv', I18N.pageviews || 'Pageviews' ) );
		legend.appendChild( legendItem( 'convertrack-bar-click', I18N.clicks || 'Clicks' ) );

		box.appendChild( chart );
		box.appendChild( legend );
	}

	function legendItem( cls, label ) {
		var item = el( 'span', 'convertrack-legend-item' );
		item.appendChild( el( 'span', 'convertrack-swatch ' + cls ) );
		item.appendChild( el( 'span', null, label ) );
		return item;
	}

	function renderButtons( target, items ) {
		var box = attr( target );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			box.appendChild( el( 'p', 'convertrack-empty', I18N.noData || 'No data.' ) );
			return;
		}
		var max = items[ 0 ].clicks || 1;
		items.forEach( function ( it ) {
			var row = el( 'div', 'convertrack-row' );
			var main = el( 'div', 'convertrack-row-main' );
			main.appendChild( el( 'span', 'convertrack-row-label', it.label || it.selector ) );
			if ( it.selector && it.label !== it.selector ) {
				main.appendChild( el( 'span', 'convertrack-row-sub', it.selector ) );
			}
			var meter = el( 'div', 'convertrack-meter' );
			var fill = el( 'span', 'convertrack-meter-fill' );
			fill.style.width = ( ( ( it.clicks || 0 ) / max ) * 100 ) + '%';
			meter.appendChild( fill );
			main.appendChild( meter );

			row.appendChild( main );
			var counts = el( 'div', 'convertrack-row-count' );
			counts.appendChild( el( 'strong', null, num( it.clicks ) ) );
			if ( it.conversions ) {
				counts.appendChild( el( 'span', 'convertrack-tag', num( it.conversions ) + ' conv' ) );
			}
			row.appendChild( counts );
			box.appendChild( row );
		} );
	}

	function renderPages( target, items, onPick ) {
		var box = attr( target );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			box.appendChild( el( 'p', 'convertrack-empty', I18N.noData || 'No data.' ) );
			return;
		}
		items.forEach( function ( it ) {
			var row = el( 'div', 'convertrack-row convertrack-row-page' );
			var main = el( 'div', 'convertrack-row-main' );
			var label = el( 'span', 'convertrack-row-label', it.title );
			main.appendChild( label );
			if ( it.url ) {
				var sub = el( 'a', 'convertrack-row-sub' );
				sub.href = it.url;
				sub.target = '_blank';
				sub.rel = 'noopener noreferrer';
				sub.textContent = it.url;
				main.appendChild( sub );
			}
			row.appendChild( main );

			var counts = el( 'div', 'convertrack-row-count' );
			counts.appendChild( el( 'strong', null, num( it.clicks ) ) );
			counts.appendChild( el( 'span', 'convertrack-row-sub', num( it.pageviews ) + ' views' ) );
			row.appendChild( counts );

			if ( onPick ) {
				row.classList.add( 'convertrack-clickable' );
				row.addEventListener( 'click', function () {
					onPick( it );
				} );
			}
			box.appendChild( row );
		} );
	}

	function renderSessions( items ) {
		var box = attr( 'active-sessions' );
		if ( ! box ) {
			return;
		}
		clear( box );
		if ( ! items || ! items.length ) {
			box.appendChild( el( 'p', 'convertrack-empty', I18N.noData || 'No one is browsing right now.' ) );
			return;
		}
		items.forEach( function ( it ) {
			var row = el( 'div', 'convertrack-row' );
			var main = el( 'div', 'convertrack-row-main' );
			main.appendChild( el( 'span', 'convertrack-row-label', it.title || it.url ) );
			main.appendChild( el( 'span', 'convertrack-row-sub', it.url ) );
			row.appendChild( main );
			var counts = el( 'div', 'convertrack-row-count' );
			counts.appendChild( el( 'strong', null, num( it.clicks ) ) );
			counts.appendChild( el( 'span', 'convertrack-row-sub', num( it.page_views ) + ' views' ) );
			row.appendChild( counts );
			box.appendChild( row );
		} );
	}

	function setLive( count ) {
		var node = attr( 'active' );
		if ( node ) {
			node.textContent = num( count );
		}
	}

	/* Pages --------------------------------------------------------------- */

	function initOverview() {
		var rangeSel = attr( 'range' );

		function loadSummary() {
			var range = rangeSel ? rangeSel.value : 7;
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					renderCards( data.totals );
					renderChart( data.series );
					renderButtons( 'top-buttons', data.top_buttons );
					renderPages( 'top-pages', data.top_pages, null );
					setLive( data.active );
				} )
				.catch( function () {} );
		}

		function loadActive() {
			api( '/stats/active' )
				.then( function ( data ) {
					setLive( data.active );
					renderSessions( data.sessions );
				} )
				.catch( function () {} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', loadSummary );
		}
		loadSummary();
		loadActive();
		window.setInterval( loadActive, C.activeRefresh || 10000 );
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
					renderPages( 'top-pages', data.top_pages, function ( it ) {
						if ( postSel ) {
							ensureOption( postSel, it.post_id, it.title );
							postSel.value = String( it.post_id );
						}
						load();
					} );
					renderButtons( 'top-buttons', data.top_buttons );

					if ( titleEl ) {
						var sel = postSel && postSel.options[ postSel.selectedIndex ];
						titleEl.textContent = ( I18N.topButtons || 'Most clicked buttons' ) +
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

	if ( document.getElementById( 'convertrack-overview' ) ) {
		initOverview();
	}
	if ( document.getElementById( 'convertrack-pages' ) ) {
		initPages();
	}
} )();
