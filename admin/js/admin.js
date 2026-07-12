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

	// Distinct error state (warning icon + optional retry) so a failed request
	// is never mistaken for a legitimately empty result.
	function errorState( box, msg, onRetry ) {
		if ( ! box ) {
			return;
		}
		var previous = box.querySelector( '.cvtrk-refresh-error' );
		if ( previous && previous.parentNode ) {
			previous.parentNode.removeChild( previous );
		}
		var preserve = !! box.firstElementChild && ! box.querySelector( '.cvtrk-skeleton' ) && ! box.querySelector( '.cvtrk-empty.is-error:not(.cvtrk-refresh-error)' );
		if ( ! preserve ) {
			clear( box );
		}
		var e = el( 'div', 'cvtrk-empty is-error' );
		e.setAttribute( 'role', 'alert' );
		if ( preserve ) {
			e.classList.add( 'cvtrk-refresh-error' );
		}
		e.appendChild( svgIcon( 'warning', 'cvtrk-empty-icon' ) );
		e.appendChild( el( 'p', null, msg || I18N.loadError || 'Something went wrong while loading this data.' ) );
		if ( typeof onRetry === 'function' ) {
			var actions = el( 'div', 'cvtrk-empty-actions' );
			var retry = el( 'button', 'button button-small', I18N.retry || 'Try again' );
			retry.type = 'button';
			retry.addEventListener( 'click', onRetry );
			actions.appendChild( retry );
			e.appendChild( actions );
		}
		box.appendChild( e );
	}

	// Toggle a reload spinner/dim on a region while a fetch is in flight, so
	// subsequent loads give feedback instead of silently showing stale data.
	function setBusy( box, on ) {
		if ( ! box ) {
			return;
		}
		box.classList.toggle( 'cvtrk-loading', !! on );
		box.setAttribute( 'aria-busy', on ? 'true' : 'false' );
	}

	// Admin URLs already use the `page` query argument for the WordPress menu
	// slug. Keep Convertrack state namespaced so pagination never overwrites it.
	function getUrlParam( key, fallback ) {
		if ( ! window.URLSearchParams ) {
			return fallback;
		}
		var value = new URLSearchParams( window.location.search ).get( 'cvtrk_' + key );
		return value === null || value === '' ? fallback : value;
	}

	function setUrlParams( values, replace ) {
		if ( ! window.URL || ! window.history || ! history.pushState ) {
			return;
		}
		var url = new URL( window.location.href );
		Object.keys( values || {} ).forEach( function ( key ) {
			var value = values[ key ];
			if ( value === undefined || value === null || value === '' ) {
				url.searchParams.delete( 'cvtrk_' + key );
			} else {
				url.searchParams.set( 'cvtrk_' + key, String( value ) );
			}
		} );
		history[ replace ? 'replaceState' : 'pushState' ]( null, '', url.pathname + url.search + url.hash );
	}

	function focusableElements( container ) {
		if ( ! container ) {
			return [];
		}
		return Array.prototype.slice.call( container.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		) ).filter( function ( node ) {
			return ! node.hidden && node.getAttribute( 'aria-hidden' ) !== 'true';
		} );
	}

	// Make every branch outside a nested modal inert, including the WordPress
	// admin menu. Attribute state is restored exactly when the dialog closes.
	function setModalBackgroundInert( modal, on ) {
		if ( ! modal ) {
			return;
		}
		if ( ! on ) {
			( modal._cvtrkInertNodes || [] ).forEach( function ( item ) {
				if ( ! item.hadInert ) {
					item.node.removeAttribute( 'inert' );
				}
				if ( item.ariaHidden === null ) {
					item.node.removeAttribute( 'aria-hidden' );
				} else {
					item.node.setAttribute( 'aria-hidden', item.ariaHidden );
				}
			} );
			modal._cvtrkInertNodes = [];
			return;
		}

		var stored = [];
		var branch = modal;
		while ( branch && branch.parentElement ) {
			Array.prototype.forEach.call( branch.parentElement.children, function ( sibling ) {
				if ( sibling === branch || stored.some( function ( item ) { return item.node === sibling; } ) ) {
					return;
				}
				stored.push( {
					node: sibling,
					hadInert: sibling.hasAttribute( 'inert' ),
					ariaHidden: sibling.getAttribute( 'aria-hidden' )
				} );
				sibling.setAttribute( 'inert', '' );
				sibling.setAttribute( 'aria-hidden', 'true' );
			} );
			if ( branch.parentElement === document.body ) {
				break;
			}
			branch = branch.parentElement;
		}
		modal._cvtrkInertNodes = stored;
	}

	function handleModalKeydown( event, modal, close ) {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();
			return;
		}
		if ( event.key !== 'Tab' ) {
			return;
		}
		var items = focusableElements( modal );
		if ( ! items.length ) {
			event.preventDefault();
			return;
		}
		var first = items[ 0 ];
		var last = items[ items.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function appendChartSummary( box, text ) {
		if ( ! box || ! text ) {
			return;
		}
		box.appendChild( el( 'p', 'cvtrk-chart-summary', text ) );
	}

	function table( headers ) {
		var wrap = el( 'div', 'cvtrk-table-wrap' );
		var t = el( 'table', 'cvtrk-table' );
		var thead = el( 'thead' );
		var tr = el( 'tr' );
		headers.forEach( function ( h ) {
			var th = el( 'th', h.num ? 'cvtrk-num' : null, h.label );
			th.scope = 'col';
			if ( h.hide ) {
				th.classList.add( 'cvtrk-col-hide-' + h.hide );
			}
			tr.appendChild( th );
		} );
		thead.appendChild( tr );
		t.appendChild( thead );
		t.appendChild( el( 'tbody' ) );
		wrap.appendChild( t );
		return wrap;
	}

	// Mirror a header's responsive-hide class onto the row's cells so the
	// column disappears as one unit on narrow admin widths.
	function applyHideClasses( tr, headers ) {
		headers.forEach( function ( h, i ) {
			if ( h.hide && tr.cells[ i ] ) {
				tr.cells[ i ].classList.add( 'cvtrk-col-hide-' + h.hide );
			}
		} );
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

	function shortDate( value ) {
		if ( ! value ) {
			return I18N.never || 'Never';
		}
		return String( value ).replace( 'T', ' ' );
	}

	function statusClass( value ) {
		return String( value || 'unknown' ).toLowerCase().replace( /[^a-z0-9_-]+/g, '_' );
	}

	function summaryMetaItem( label, value, tone ) {
		var className = 'cvtrk-summary-meta-item' + ( tone ? ' is-' + tone : '' );
		var item = el( 'span', className );
		item.appendChild( el( 'span', 'cvtrk-summary-meta-label', label ) );
		item.appendChild( el( 'strong', 'cvtrk-summary-meta-value', value === null || value === undefined || value === '' ? '—' : String( value ) ) );
		return item;
	}

	// Only http(s) and same-site relative URLs are allowed in generated
	// links. Protocol-relative (//host) is rejected: it silently points at
	// an external origin.
	function safeUrl( value ) {
		var url = String( value || '' ).trim();
		if ( ! url ) {
			return '';
		}
		if ( /^https?:\/\//i.test( url ) || /^\/(?!\/)/.test( url ) ) {
			return url;
		}
		return '';
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

	function healthCard( item ) {
		var tag = item.href ? 'a' : 'div';
		var card = el( tag, 'cvtrk-health-card is-' + ( item.tone || 'neutral' ) );
		if ( item.href ) {
			card.href = item.href;
		}
		var icon = el( 'span', 'cvtrk-health-icon' );
		icon.appendChild( svgIcon( item.icon || 'shield', 'cvtrk-icon' ) );
		card.appendChild( icon );

		var body = el( 'span', 'cvtrk-health-body' );
		body.appendChild( el( 'span', 'cvtrk-health-label', item.label ) );
		body.appendChild( el( 'span', 'cvtrk-health-value', item.value ) );
		if ( item.meta ) {
			body.appendChild( el( 'span', 'cvtrk-health-meta', item.meta ) );
		}
		card.appendChild( body );
		return card;
	}

	function renderOverviewHealth( stats, notFound, gsc ) {
		var box = attr( 'overview-health' );
		if ( ! box ) {
			return;
		}

		var totals = stats && stats.totals ? stats.totals : {};
		var active = Number( stats && stats.active ) || 0;
		var pageviews = Number( totals.pageviews ) || 0;
		var clicks = Number( totals.clicks ) || 0;
		var conversions = Number( totals.conversions ) || 0;
		var total404 = Number( notFound && notFound.total ) || 0;
		var unresolved = Number( notFound && notFound.unresolved ) || 0;
		var recommended = Number( notFound && notFound.recommended ) || 0;
		var redirected = Number( notFound && notFound.redirected ) || 0;
		var redirectHits = Number( notFound && notFound.redirect_hits ) || 0;
		var gscTotal = Number( gsc && gsc.total ) || 0;
		var gscIndexed = Number( gsc && gsc.indexed ) || 0;
		var gscIssues = ( Number( gsc && gsc.not_indexed ) || 0 ) + ( Number( gsc && gsc.errors ) || 0 ) +
			( Number( gsc && gsc.blocked_by_robots ) || 0 ) + ( Number( gsc && gsc.noindex_detected ) || 0 );
		var gscReady = !! ( gsc && gsc.settings_ready && gsc.credentials && gsc.credentials.connected );
		var notFoundError = !! ( notFound && notFound._loadError );
		var gscError = !! ( gsc && gsc._loadError );
		var pct = gscTotal > 0 ? Math.round( ( gscIndexed / gscTotal ) * 100 ) : 0;
		var lastScan = ( gsc && gsc.last_sync_time ) || ( notFound && notFound.last_sitemap_refresh ) || '';
		clear( box );
		var grid = el( 'div', 'cvtrk-health-grid' );
		var cards = [
			{
				label: I18N.analyticsActivity || 'Analytics activity',
				value: pageviews + clicks > 0 ? ( I18N.trackingActive || 'Tracking active' ) : ( I18N.noActivityYet || 'No activity yet' ),
				meta: num( pageviews ) + ' ' + ( I18N.pageviews || 'Pageviews' ) + ' / ' + num( clicks ) + ' ' + ( I18N.clicks || 'Clicks' ) + ' / ' + num( conversions ) + ' ' + ( I18N.conversions || 'Conversions' ),
				tone: pageviews + clicks > 0 ? 'green' : 'neutral',
				icon: 'visit',
				href: C.adminUrls && C.adminUrls.overview,
				show: pageviews + clicks === 0
			},
			{
				label: I18N.total404s || 'Total 404 URLs',
				value: notFoundError ? ( I18N.unavailable || 'Unavailable' ) : ( notFound ? num( total404 ) : I18N.loading || 'Loading...' ),
				meta: notFoundError ? ( notFound._loadError || I18N.loadError || 'Could not load this data.' ) : ( notFound ? num( unresolved ) + ' unresolved' : '' ),
				tone: notFoundError ? 'red' : ( unresolved > 0 ? 'amber' : ( total404 > 0 ? 'neutral' : 'green' ) ),
				icon: 'warning',
				href: C.adminUrls && C.adminUrls.notFound,
				show: notFoundError || unresolved > 0
			},
			{
				label: I18N.activeRedirects || 'Active redirects',
				value: notFoundError ? ( I18N.unavailable || 'Unavailable' ) : ( notFound ? num( redirected ) : I18N.loading || 'Loading...' ),
				meta: notFoundError ? ( notFound._loadError || I18N.loadError || 'Could not load this data.' ) : ( notFound ? num( redirectHits ) + ' redirect hits' : '' ),
				tone: notFoundError ? 'red' : ( redirected > 0 ? 'green' : 'neutral' ),
				icon: 'update',
				href: C.adminUrls && C.adminUrls.notFound,
				show: false
			},
			{
				label: I18N.pendingRecommendations || 'Pending recommendations',
				value: notFoundError ? ( I18N.unavailable || 'Unavailable' ) : ( notFound ? num( recommended ) : I18N.loading || 'Loading...' ),
				meta: notFoundError ? ( notFound._loadError || I18N.loadError || 'Could not load this data.' ) : ( notFound ? 'Manual review queue' : '' ),
				tone: notFoundError ? 'red' : ( recommended > 0 ? 'amber' : 'green' ),
				icon: 'search',
				href: C.adminUrls && C.adminUrls.notFound,
				show: recommended > 0
			},
			{
				label: I18N.sitemapStatus || 'Sitemap status',
				value: gscError ? ( I18N.unavailable || 'Unavailable' ) : ( ! gsc ? ( I18N.loading || 'Loading...' ) : ( gscReady ? ( gscTotal > 0 ? pct + '% indexed' : I18N.connected || 'Connected' ) : I18N.setupNeeded || 'Setup needed' ) ),
				meta: gscError ? ( gsc._loadError || I18N.loadError || 'Could not load this data.' ) : ( ! gsc ? '' : ( gscReady ? num( gscTotal ) + ' URLs / ' + num( gscIssues ) + ' issues' : I18N.notConnected || 'Not connected' ) ),
				tone: gscError ? 'red' : ( ! gsc ? 'neutral' : ( ! gscReady || gscIssues > 0 ? 'amber' : 'green' ) ),
				icon: 'indexed',
				href: C.adminUrls && C.adminUrls.gsc,
				show: gscError || ! gscReady || gscIssues > 0
			},
			{
				label: I18N.lastScan || 'Last scan',
				value: shortDate( lastScan ),
				meta: gsc && gsc.next_scheduled_check ? 'Next: ' + shortDate( gsc.next_scheduled_check ) : '',
				tone: lastScan ? 'green' : 'neutral',
				icon: 'calendar',
				show: gscReady && ! lastScan
			},
			{
				label: I18N.pluginHealth || 'Plugin health',
				value: I18N.operational || 'Operational',
				meta: 'Convertrack ' + ( C.version || '' ),
				tone: 'green',
				icon: 'shield',
				href: C.adminUrls && C.adminUrls.settings,
				show: false
			}
		];

		if ( active > 0 ) {
			cards[ 0 ].meta += ' / ' + num( active ) + ' live';
		}

		var attentionCards = cards.filter( function ( item ) { return item.show; } );
		if ( ! attentionCards.length ) {
			var clear = el( 'div', 'cvtrk-empty cvtrk-health-clear' );
			clear.appendChild( svgIcon( 'indexed', 'cvtrk-empty-icon' ) );
			clear.appendChild( el( 'p', null, I18N.noAttentionNeeded || 'No setup or health issues need attention right now.' ) );
			box.appendChild( clear );
			return;
		}
		attentionCards.forEach( function ( item ) {
			grid.appendChild( healthCard( item ) );
		} );
		box.appendChild( grid );
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
		box.removeAttribute( 'role' );
		box.removeAttribute( 'aria-label' );

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
		var totals = dates.reduce( function ( result, date ) {
			result.pageviews += Number( series[ date ].pageviews ) || 0;
			result.clicks += Number( series[ date ].clicks ) || 0;
			return result;
		}, { pageviews: 0, clicks: 0 } );
		appendChartSummary( box, dates[ 0 ] + ' to ' + dates[ dates.length - 1 ] + ': ' +
			num( totals.pageviews ) + ' ' + ( I18N.pageviews || 'pageviews' ) + ', ' +
			num( totals.clicks ) + ' ' + ( I18N.clicks || 'clicks' ) + '.' );
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
		box.removeAttribute( 'role' );
		box.removeAttribute( 'aria-label' );

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
		var totals = { pageviews: 0, clicks: 0, conversions: 0 };
		var peakDate = dates[ 0 ];
		var peakValue = -1;
		dates.forEach( function ( date ) {
			var dayTotal = 0;
			Object.keys( totals ).forEach( function ( key ) {
				var value = Number( series[ date ][ key ] ) || 0;
				totals[ key ] += value;
				dayTotal += value;
			} );
			if ( dayTotal > peakValue ) {
				peakValue = dayTotal;
				peakDate = date;
			}
		} );
		appendChartSummary( box, dates[ 0 ] + ' to ' + dates[ dates.length - 1 ] + ': ' +
			num( totals.pageviews ) + ' ' + ( I18N.pageviews || 'pageviews' ) + ', ' +
			num( totals.clicks ) + ' ' + ( I18N.clicks || 'clicks' ) + ', and ' +
			num( totals.conversions ) + ' ' + ( I18N.conversions || 'conversions' ) +
			'. Peak activity was ' + peakDate + '.' );
	}

	function renderHourlyChart( items ) {
		var box = attr( 'hourly-chart' );
		if ( ! box ) {
			return;
		}
		clear( box );
		box.removeAttribute( 'role' );
		box.removeAttribute( 'aria-label' );
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
		var hourTotals = { pageviews: 0, clicks: 0, conversions: 0 };
		var peak = items[ 0 ];
		var peakTotal = -1;
		items.forEach( function ( item ) {
			var itemTotal = 0;
			Object.keys( hourTotals ).forEach( function ( key ) {
				var value = Number( item[ key ] ) || 0;
				hourTotals[ key ] += value;
				itemTotal += value;
			} );
			if ( itemTotal > peakTotal ) {
				peakTotal = itemTotal;
				peak = item;
			}
		} );
		appendChartSummary( box, ( I18N.busiestHour || 'Busiest hour' ) + ': ' + ( peak.hour || '-' ) + '. ' +
			num( hourTotals.pageviews ) + ' ' + ( I18N.pageviews || 'pageviews' ) + ', ' +
			num( hourTotals.clicks ) + ' ' + ( I18N.clicks || 'clicks' ) + ', and ' +
			num( hourTotals.conversions ) + ' ' + ( I18N.conversions || 'conversions' ) + ' overall.' );
	}

	function renderEngagement( data ) {
		var box = attr( 'engagement-chart' );
		if ( ! box ) {
			return;
		}
		clear( box );
		box.removeAttribute( 'role' );
		box.removeAttribute( 'aria-label' );

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
		appendChartSummary( box, items.map( function ( item ) {
			return item.label + ': ' + num( item.value );
		} ).join( '. ' ) + '.' );
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

		var headers = [
			{ label: '' },
			{ label: I18N.page || 'Page' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.conversions || 'Conversions', num: true }
		];
		if ( onPick ) {
			headers.push( { label: I18N.actions || 'Actions' } );
		}
		var t = table( headers );
		var body = t.querySelector( 'tbody' );
		items.forEach( function ( it, i ) {
			var tr = el( 'tr' );
			tr.appendChild( rankCell( i ) );
			tr.appendChild( labelCell( it.title, it.url, it.url || '' ) );
			tr.appendChild( clicksCell( it.clicks, max ) );
			tr.appendChild( numCell( it.pageviews ) );
			tr.appendChild( convCell( it.conversions ) );
			if ( onPick ) {
				var actionCell = el( 'td', 'cvtrk-row-actions' );
				var details = el( 'button', 'button button-small', I18N.viewDetails || 'View details' );
				details.type = 'button';
				details.setAttribute( 'aria-label', ( I18N.viewDetails || 'View details' ) + ': ' + ( it.title || it.url || '' ) );
				details.addEventListener( 'click', function () { onPick( it ); } );
				actionCell.appendChild( details );
				tr.appendChild( actionCell );
			}
			body.appendChild( tr );
		} );
		box.appendChild( t );
	}

	function renderPagedPages( box, data, onPick ) {
		if ( ! box ) {
			return;
		}
		var rows = ( data && data.rows ) || [];
		clear( box );
		if ( ! rows.length ) {
			empty( box, I18N.noMatchingPages || 'No tracked pages match the current filters.' );
			return;
		}

		var max = rows.reduce( function ( current, row ) {
			return Math.max( current, Number( row.clicks ) || 0 );
		}, 1 );
		var wrap = table( [
			{ label: I18N.page || 'Page' },
			{ label: I18N.clicks || 'Clicks', num: true },
			{ label: I18N.pageviews || 'Pageviews', num: true },
			{ label: I18N.conversions || 'Conversions', num: true },
			{ label: I18N.actions || 'Actions' }
		] );
		var t = wrap.querySelector( 'table' );
		var caption = el( 'caption', null, num( data.total ) + ' ' + ( I18N.trackedPages || 'tracked pages' ) );
		t.insertBefore( caption, t.firstChild );
		var body = wrap.querySelector( 'tbody' );
		rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			tr.appendChild( labelCell( row.title || row.url || ( '#' + row.post_id ), row.url || '', safeUrl( row.url ) ) );
			tr.appendChild( clicksCell( row.clicks, max ) );
			tr.appendChild( numCell( row.pageviews ) );
			tr.appendChild( convCell( row.conversions ) );
			var actionCell = el( 'td', 'cvtrk-row-actions' );
			var details = el( 'button', 'button button-small', I18N.viewDetails || 'View details' );
			details.type = 'button';
			details.setAttribute( 'aria-label', ( I18N.viewDetails || 'View details' ) + ': ' + ( row.title || row.url || row.post_id ) );
			details.addEventListener( 'click', function () { onPick( row ); } );
			actionCell.appendChild( details );
			tr.appendChild( actionCell );
			body.appendChild( tr );
		} );
		box.appendChild( wrap );
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
		[ attr( 'active' ), attr( 'active-secondary' ) ].forEach( function ( node ) {
			if ( node ) {
				node.textContent = num( count );
			}
		} );
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
		var blocked = attr( 'heatmap-frame-blocked' );
		if ( ! stage || ! page || ! canvas ) {
			return;
		}
		var points = ( data && data.points ) || [];
		var maxW = ( data && data.max_weight ) || 1;
		mode = mode === 'page' ? 'page' : 'element';
		var viewport = applyHeatmapViewport( stage, page, frame, data && data.device );
		if ( blocked ) {
			blocked.hidden = true;
		}

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
						if ( blocked ) {
							blocked.hidden = true;
						}
						var renderH = Math.max( viewport.h, Math.min( docH, 8000 ) );
						frame.style.height = renderH + 'px';
						drawHeatCanvas( canvas, page, markers, points, maxW, renderH, frame, mode, viewport );
					} else {
						if ( blocked ) {
							blocked.hidden = false;
						}
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
			if ( blocked && showPage ) {
				blocked.hidden = false;
			}
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
		var pageSearch = attr( 'heatmap-page-search' );
		var confidence = attr( 'heatmap-confidence' );
		var frameBlocked = attr( 'heatmap-frame-blocked' );
		var data = null;
		var snapshots = {};
		var resizeTimer = null;
		var pageSearchTimer = null;
		if ( rangeSel ) {
			var initialRange = getUrlParam( 'range', rangeSel.value );
			if ( rangeSel.querySelector( 'option[value="' + initialRange + '"]' ) ) {
				rangeSel.value = initialRange;
			}
		}

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

		function heatmapConfidenceText( samples ) {
			samples = Number( samples ) || 0;
			if ( samples >= 100 ) {
				return 'High confidence: ' + num( samples ) + ' heatmap clicks in this view.';
			}
			if ( samples >= 25 ) {
				return 'Moderate confidence: ' + num( samples ) + ' heatmap clicks in this view.';
			}
			if ( samples > 0 ) {
				return 'Low confidence: only ' + num( samples ) + ' heatmap clicks in this view.';
			}
			return '';
		}

		function setHeatmapConfidenceNote( samples ) {
			var note = attr( 'heatmap-note' );
			var text = heatmapConfidenceText( samples );
			if ( confidence ) {
				confidence.textContent = text;
				confidence.hidden = ! text;
			}
			if ( note && Number( samples ) > 0 ) {
				note.textContent = text;
			}
		}

		function renderCurrent() {
			if ( data ) {
				renderClickMap( data, showChk ? showChk.checked : true, mode() );
				setHeatmapConfidenceNote( data.heatmap_clicks !== undefined ? data.heatmap_clicks : data.clicks );
			}
		}

		function clearHeatmapSurface( message ) {
			var msg = message || I18N.noHeatmapPages || I18N.noData || 'No page activity in this range yet.';
			data = null;
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
			if ( confidence ) {
				confidence.textContent = '';
				confidence.hidden = true;
			}
			if ( frameBlocked ) {
				frameBlocked.hidden = true;
			}
			var stage = attr( 'heatmap-stage' );
			var page = attr( 'heatmap-page' );
			var frame = attr( 'heatmap-frame' );
			var canvas = attr( 'heatmap-canvas' );
			var markers = attr( 'heatmap-markers' );
			if ( stage && page && canvas ) {
				var viewport = applyHeatmapViewport( stage, page, frame, selectedDevice() );
				if ( frame ) {
					frame.style.display = 'none';
					frame.removeAttribute( 'data-snapshot-key' );
					frame.removeAttribute( 'data-snapshot-url' );
					frame.removeAttribute( 'src' );
					frame.removeAttribute( 'srcdoc' );
				}
				stage.classList.add( 'cvtrk-no-frame' );
				page.classList.add( 'cvtrk-no-frame' );
				drawHeatCanvas( canvas, page, markers, [], 1, viewport.h, null, 'page', viewport );
			}
		}

		function load() {
			var post = postSel ? postSel.value : 0;
			var range = rangeSel ? rangeSel.value : 7;
			var device = selectedDevice();
			syncDeviceToggle();
			if ( ! post || post === '0' ) {
				clearHeatmapSurface( 'Select a page to view heatmap data.' );
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
					var heatmapClicks = Number( d.heatmap_clicks !== undefined ? d.heatmap_clicks : d.clicks ) || 0;
					var trackedClicks = Number( d.tracked_clicks ) || 0;
					if ( meta ) {
						var viewport = heatmapViewport( d.device );
						meta.textContent = viewport.label + ' ' + viewport.w + 'x' + viewport.h + ' - ' +
							num( d.pageviews ) + ' ' + ( I18N.pageviews || 'Pageviews' ).toLowerCase() +
							' - ' + num( heatmapClicks ) + ' heatmap clicks' +
							( trackedClicks && trackedClicks !== heatmapClicks ? ' - ' + num( trackedClicks ) + ' tracked clicks' : '' );
					}
					var canvas = attr( 'heatmap-canvas' );
					if ( canvas ) {
						var selected = postSel && postSel.options[ postSel.selectedIndex ];
						canvas.setAttribute( 'aria-label', ( selected ? selected.textContent + '. ' : '' ) +
							num( d.pageviews ) + ' ' + ( I18N.pageviews || 'pageviews' ).toLowerCase() + ', ' +
							num( heatmapClicks ) + ' heatmap clicks. ' + heatmapConfidenceText( heatmapClicks ) );
					}
					setHeatmapConfidenceNote( heatmapClicks );
				} )
				.catch( function ( err ) {
					clearHeatmapSurface( ( err && err.message ) || 'Could not load heatmap data.' );
				} );
		}

		function showNoPages() {
			var msg = I18N.noHeatmapPages || I18N.noData || 'No page activity in this range yet.';
			clearHeatmapSurface( msg );
		}

		function loadPages() {
			var range = rangeSel ? rangeSel.value : 7;
			var search = pageSearch ? pageSearch.value.trim() : '';
			api( '/stats/pages?range=' + encodeURIComponent( range ) + '&page=1&per_page=100&orderby=pageviews&order=desc&search=' + encodeURIComponent( search ) )
				.then( function ( d ) {
					if ( ! postSel || ! d.rows ) {
						return;
					}
					var cur = postSel.value;
					var hasCurrent = false;
					while ( postSel.options.length > 1 ) {
						postSel.remove( 1 );
					}
					d.rows.forEach( function ( p ) {
						if ( p.post_id > 0 ) {
							var o = document.createElement( 'option' );
							o.value = p.post_id;
							o.textContent = p.title;
							postSel.appendChild( o );
							if ( String( p.post_id ) === String( cur ) ) {
								hasCurrent = true;
							}
						}
					} );
					if ( postSel.options.length <= 1 ) {
						clearHeatmapSurface( search ? ( I18N.noMatchingPages || 'No tracked pages match this search.' ) : ( I18N.noHeatmapPages || 'No page activity in this range yet.' ) );
						return;
					}
					if ( cur && cur !== '0' && hasCurrent ) {
						postSel.value = cur;
					} else {
						postSel.selectedIndex = 1;
					}
					load();
				} )
				.catch( function ( err ) {
					clearHeatmapSurface( ( err && err.message ) || 'Could not load heatmap pages.' );
				} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', function () {
				setUrlParams( { range: rangeSel.value }, false );
				loadPages();
			} );
		}
		if ( postSel ) {
			postSel.addEventListener( 'change', load );
		}
		if ( pageSearch ) {
			pageSearch.addEventListener( 'input', function () {
				window.clearTimeout( pageSearchTimer );
				pageSearchTimer = window.setTimeout( loadPages, 250 );
			} );
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

	function regionNodes( keys ) {
		var seen = [];
		( keys || [] ).forEach( function ( key ) {
			var node = attr( key );
			if ( node && seen.indexOf( node ) === -1 ) {
				seen.push( node );
			}
		} );
		return seen;
	}

	function setRegionsBusy( keys, on ) {
		regionNodes( keys ).forEach( function ( node ) { setBusy( node, on ); } );
	}

	function showRegionsError( keys, error, retry ) {
		var message = ( error && error.message ) || I18N.loadError || 'Something went wrong while loading this data.';
		regionNodes( keys ).forEach( function ( node ) {
			setBusy( node, false );
			errorState( node, message, retry );
		} );
	}

	function loadActive() {
		var sessions = attr( 'active-sessions' );
		setBusy( sessions, true );
		api( '/stats/active' )
			.then( function ( data ) {
				setBusy( sessions, false );
				setLive( data.active );
				renderSessions( data.sessions );
			} )
			.catch( function ( err ) {
				setBusy( sessions, false );
				if ( sessions ) {
					errorState( sessions, ( err && err.message ) || 'Could not load live visitors.', loadActive );
				}
			} );
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
		var overviewRegions = [ 'chart', 'hourly-chart', 'engagement-chart', 'top-buttons', 'top-pages', 'top-sources', 'top-search-terms', 'top-countries', 'event-timeline', 'active-sessions', 'overview-health' ];
		if ( rangeSel ) {
			var initialRange = getUrlParam( 'range', rangeSel.value );
			if ( rangeSel.querySelector( 'option[value="' + initialRange + '"]' ) ) {
				rangeSel.value = initialRange;
			}
		}

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			updateExports( range, 0 );
			setRegionsBusy( overviewRegions, true );
			api( '/stats/summary?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					setRegionsBusy( overviewRegions, false );
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
					renderOverviewHealth( data, null, null );
					Promise.all( [
						api( '/404/summary' ).catch( function ( err ) {
							return { _loadError: ( err && err.message ) || ( I18N.loadError || 'Could not load 404 data.' ) };
						} ),
						api( '/gsc/summary' ).catch( function ( err ) {
							return { _loadError: ( err && err.message ) || ( I18N.loadError || 'Could not load indexing data.' ) };
						} )
					] ).then( function ( moduleData ) {
						renderOverviewHealth( data, moduleData[ 0 ], moduleData[ 1 ] );
					} );
				} )
				.catch( function ( err ) {
					showRegionsError( overviewRegions, err, load );
					var updated = attr( 'last-updated' );
					if ( updated ) {
						updated.textContent = I18N.loadError || 'Could not load dashboard data.';
					}
				} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', function () {
				setUrlParams( { range: rangeSel.value }, false );
				load();
			} );
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

	function initPagesLegacy() {
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
				.catch( function ( err ) {
					[ attr( 'top-pages' ), attr( 'top-buttons' ) ].forEach( function ( box ) {
						if ( box ) {
							errorState( box, ( err && err.message ) || 'Could not load content analytics.', load );
						}
					} );
				} );
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

	function initPages() {
		var rangeSel = attr( 'range' );
		var postSel = attr( 'post' );
		var titleEl = attr( 'buttons-title' );
		var listBox = attr( 'pages-list' ) || attr( 'top-pages' );
		var searchInput = attr( 'pages-search' );
		var orderbySel = attr( 'pages-orderby' );
		var orderSel = attr( 'pages-order' );
		var perPageSel = attr( 'pages-per-page' );
		var prev = attr( 'pages-prev' );
		var next = attr( 'pages-next' );
		var pageEl = attr( 'pages-page' );
		var resultCount = attr( 'pages-result-count' );
		var detailBox = attr( 'top-buttons' );
		var state = { page: 1, pages: 1, total: 0 };

		function ensureOption( select, value, label ) {
			if ( ! select ) {
				return;
			}
			value = String( value );
			for ( var i = 0; i < select.options.length; i++ ) {
				if ( select.options[ i ].value === value ) {
					if ( label && /^#\d+$/.test( select.options[ i ].textContent || '' ) ) {
						select.options[ i ].textContent = label;
					}
					return;
				}
			}
			var option = document.createElement( 'option' );
			option.value = value;
			option.textContent = label;
			select.appendChild( option );
		}

		function useSelectValue( select, value ) {
			if ( ! select ) {
				return;
			}
			value = String( value );
			for ( var i = 0; i < select.options.length; i++ ) {
				if ( select.options[ i ].value === value ) {
					select.value = value;
					return;
				}
			}
		}

		useSelectValue( rangeSel, getUrlParam( 'range', rangeSel ? rangeSel.value : '7' ) );
		useSelectValue( orderbySel, getUrlParam( 'pages_orderby', orderbySel ? orderbySel.value : 'pageviews' ) );
		useSelectValue( orderSel, getUrlParam( 'pages_order', orderSel ? orderSel.value : 'desc' ) );
		useSelectValue( perPageSel, getUrlParam( 'pages_per_page', perPageSel ? perPageSel.value : '25' ) );
		state.page = Math.max( 1, Number( getUrlParam( 'pages_page', '1' ) ) || 1 );
		if ( searchInput ) {
			searchInput.value = getUrlParam( 'pages_search', searchInput.value || '' );
		}
		var initialPost = Math.max( 0, Number( getUrlParam( 'pages_post', '0' ) ) || 0 );
		if ( initialPost && postSel ) {
			ensureOption( postSel, initialPost, '#' + initialPost );
			postSel.value = String( initialPost );
		}

		function listQuery() {
			return '?' + [
				'range=' + encodeURIComponent( rangeSel ? rangeSel.value : 7 ),
				'page=' + encodeURIComponent( state.page ),
				'per_page=' + encodeURIComponent( perPageSel ? perPageSel.value : 25 ),
				'search=' + encodeURIComponent( searchInput ? searchInput.value : '' ),
				'orderby=' + encodeURIComponent( orderbySel ? orderbySel.value : 'pageviews' ),
				'order=' + encodeURIComponent( orderSel ? orderSel.value : 'desc' )
			].join( '&' );
		}

		function syncListUrl( replace ) {
			setUrlParams( {
				range: rangeSel ? rangeSel.value : 7,
				pages_page: state.page,
				pages_per_page: perPageSel ? perPageSel.value : 25,
				pages_search: searchInput ? searchInput.value : '',
				pages_orderby: orderbySel ? orderbySel.value : 'pageviews',
				pages_order: orderSel ? orderSel.value : 'desc',
				pages_post: postSel && postSel.value !== '0' ? postSel.value : ''
			}, replace );
		}

		function updatePagination() {
			if ( pageEl ) {
				pageEl.textContent = ( I18N.pageWord || 'Page' ) + ' ' + state.page + ' / ' + state.pages + ' (' + num( state.total ) + ')';
			}
			if ( resultCount ) {
				resultCount.textContent = num( state.total ) + ' ' + ( state.total === 1 ? ( I18N.trackedPage || 'tracked page' ) : ( I18N.trackedPages || 'tracked pages' ) );
			}
			if ( prev ) {
				prev.disabled = state.page <= 1;
			}
			if ( next ) {
				next.disabled = state.page >= state.pages;
			}
		}

		function focusDetail() {
			var card = document.querySelector( '[data-cvtrk-pages-detail]' ) || document.getElementById( 'convertrack-page-detail' );
			if ( card && card.scrollIntoView ) {
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			if ( titleEl ) {
				titleEl.setAttribute( 'tabindex', '-1' );
				titleEl.focus();
			}
		}

		function loadDetail( shouldFocus ) {
			var range = rangeSel ? rangeSel.value : 7;
			var post = postSel ? postSel.value : '0';
			updateExports( range, post );
			if ( ! post || post === '0' ) {
				if ( titleEl ) {
					titleEl.textContent = I18N.pageDetails || 'Page details';
				}
				if ( detailBox ) {
					empty( detailBox, I18N.choosePage || 'Select View details for a page to inspect its calls to action.' );
				}
				return;
			}

			setBusy( detailBox, true );
			api( '/stats/summary?range=' + encodeURIComponent( range ) + '&post=' + encodeURIComponent( post ) )
				.then( function ( data ) {
					setBusy( detailBox, false );
					( data.top_pages || [] ).forEach( function ( page ) {
						ensureOption( postSel, page.post_id, page.title || page.url || ( '#' + page.post_id ) );
					} );
					var selected = postSel && postSel.options[ postSel.selectedIndex ];
					if ( titleEl ) {
						titleEl.textContent = ( I18N.topButtons || 'Buttons clicked' ) + ( selected ? ' - ' + selected.textContent : '' );
					}
					renderButtons( data.top_buttons || [] );
					if ( shouldFocus ) {
						focusDetail();
					}
				} )
				.catch( function ( err ) {
					setBusy( detailBox, false );
					if ( detailBox ) {
						errorState( detailBox, ( err && err.message ) || 'Could not load page details.', function () { loadDetail( false ); } );
					}
				} );
		}

		function pickPage( row ) {
			ensureOption( postSel, row.post_id, row.title || row.url || ( '#' + row.post_id ) );
			if ( postSel ) {
				postSel.value = String( row.post_id );
			}
			syncListUrl( false );
			loadDetail( true );
		}

		function loadList() {
			setBusy( listBox, true );
			api( '/stats/pages' + listQuery() )
				.then( function ( data ) {
					setBusy( listBox, false );
					state.page = Math.max( 1, Number( data.page ) || 1 );
					state.pages = Math.max( 1, Number( data.total_pages ) || 1 );
					state.total = Number( data.total ) || 0;
					( data.rows || [] ).forEach( function ( page ) {
						ensureOption( postSel, page.post_id, page.title || page.url || ( '#' + page.post_id ) );
					} );
					renderPagedPages( listBox, data, pickPage );
					updatePagination();
					syncListUrl( true );
				} )
				.catch( function ( err ) {
					setBusy( listBox, false );
					if ( listBox ) {
						errorState( listBox, ( err && err.message ) || 'Could not load tracked pages.', loadList );
					}
					if ( resultCount ) {
						resultCount.textContent = I18N.loadError || 'Could not load tracked pages.';
					}
				} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', function () {
				state.page = 1;
				syncListUrl( false );
				loadList();
				loadDetail( false );
			} );
		}
		if ( postSel ) {
			postSel.addEventListener( 'change', function () {
				syncListUrl( false );
				loadDetail( true );
			} );
		}
		[ orderbySel, orderSel, perPageSel ].forEach( function ( select ) {
			if ( select ) {
				select.addEventListener( 'change', function () {
					state.page = 1;
					syncListUrl( false );
					loadList();
				} );
			}
		} );
		if ( searchInput ) {
			var searchTimer = null;
			searchInput.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( function () {
					state.page = 1;
					syncListUrl( true );
					loadList();
				}, 250 );
			} );
		}
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				if ( state.page > 1 ) {
					state.page--;
					syncListUrl( false );
					loadList();
				}
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				if ( state.page < state.pages ) {
					state.page++;
					syncListUrl( false );
					loadList();
				}
			} );
		}
		window.addEventListener( 'popstate', function () {
			useSelectValue( rangeSel, getUrlParam( 'range', rangeSel ? rangeSel.value : '7' ) );
			useSelectValue( orderbySel, getUrlParam( 'pages_orderby', orderbySel ? orderbySel.value : 'pageviews' ) );
			useSelectValue( orderSel, getUrlParam( 'pages_order', orderSel ? orderSel.value : 'desc' ) );
			useSelectValue( perPageSel, getUrlParam( 'pages_per_page', perPageSel ? perPageSel.value : '25' ) );
			state.page = Math.max( 1, Number( getUrlParam( 'pages_page', '1' ) ) || 1 );
			if ( searchInput ) {
				searchInput.value = getUrlParam( 'pages_search', '' );
			}
			var post = Math.max( 0, Number( getUrlParam( 'pages_post', '0' ) ) || 0 );
			if ( postSel ) {
				ensureOption( postSel, post, post ? '#' + post : ( I18N.choosePage || 'Choose a page' ) );
				postSel.value = String( post );
			}
			loadList();
			loadDetail( false );
		} );
		updatePagination();
		loadList();
		loadDetail( false );
	}

	function initFunnels() {
		var rangeSel = attr( 'range' );
		if ( rangeSel ) {
			var initialRange = getUrlParam( 'range', rangeSel.value );
			if ( rangeSel.querySelector( 'option[value="' + initialRange + '"]' ) ) {
				rangeSel.value = initialRange;
			}
		}

		function load() {
			var range = rangeSel ? rangeSel.value : 7;
			var boxes = [ 'funnel-paths', 'funnel-dropoffs', 'funnel-sources', 'funnel-buttons' ];
			setRegionsBusy( boxes, true );
			api( '/stats/funnels?range=' + encodeURIComponent( range ) )
				.then( function ( data ) {
					setRegionsBusy( boxes, false );
					renderFunnelCards( data );
					renderFunnelPaths( data.paths );
					renderFunnelDropoffs( data.dropoffs );
					renderFunnelSources( data.sources );
					renderFunnelButtons( data.buttons );
				} )
				.catch( function ( err ) {
					showRegionsError( boxes, err, load );
				} );
		}

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', function () {
				setUrlParams( { range: rangeSel.value }, false );
				load();
			} );
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
			var box = attr( 'gsc-summary' );
			setBusy( box, true );
			api( '/gsc/summary' )
				.then( function ( data ) {
					setBusy( box, false );
					renderGscSummary( data );
					renderGscSitemapOptions( data.sitemaps || [] );
					if ( ! proc.running && data.last_batch_error && data.last_batch_error.message ) {
						setGscProgress( data.last_batch_error.message, true );
					}
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load indexing coverage.', loadSummary );
					}
				} );
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
			var first = history[ 0 ];
			var last = history[ history.length - 1 ];
			appendChartSummary( wrap, ( first.date || '' ) + ' to ' + ( last.date || '' ) + ': ' +
				( I18N.indexed || 'Indexed' ) + ' ' + num( first.indexed ) + ' to ' + num( last.indexed ) +
				' out of ' + num( last.total ) + ' URLs.' );
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
			postTabs.setAttribute( 'role', 'tablist' );
			postTabs.setAttribute( 'aria-label', I18N.postType || 'Filter by post type' );
			var controlled = attr( 'gsc-urls' );
			if ( controlled && ! controlled.id ) {
				controlled.id = 'cvtrk-gsc-url-results';
			}
			if ( controlled ) {
				controlled.setAttribute( 'role', 'tabpanel' );
				controlled.setAttribute( 'tabindex', '0' );
			}
			var value = postTypeSel.value || 'all';
			postTabs.querySelectorAll( '[data-gsc-post-tab]' ).forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-gsc-post-tab' ) === value;
				tab.setAttribute( 'role', 'tab' );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
				if ( controlled ) {
					tab.setAttribute( 'aria-controls', controlled.id );
				}
				if ( active ) {
					tab.classList.add( 'is-active' );
				} else {
					tab.classList.remove( 'is-active' );
				}
			} );
		}

		function loadUrls() {
			syncPostTabs();
			updateExport();
			var box = attr( 'gsc-urls' );
			setBusy( box, true );
			api( '/gsc/urls' + query() )
				.then( function ( data ) {
					setBusy( box, false );
					state.pages = data.pages || 1;
					renderGscUrls( data.rows || [] );
					renderGscPagination( data );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load the indexing queue.', loadUrls );
					}
				} );
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
			var box = attr( 'gsc-logs' );
			setBusy( box, true );
			api( '/gsc/logs?limit=50' )
				.then( function ( data ) {
					setBusy( box, false );
					renderGscLogs( data.rows || [] );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load indexing activity.', loadLogs );
					}
				} );
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
				var levelCell = el( 'td' );
				levelCell.appendChild( el( 'span', 'cvtrk-badge cvtrk-status-' + statusClass( row.level ), statusText( row.level ) ) );
				tr.appendChild( levelCell );
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
			postTabs.addEventListener( 'keydown', function ( e ) {
				if ( [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].indexOf( e.key ) === -1 ) {
					return;
				}
				var tabs = Array.prototype.slice.call( postTabs.querySelectorAll( '[data-gsc-post-tab]' ) );
				var current = tabs.indexOf( document.activeElement );
				if ( current < 0 || ! tabs.length ) {
					return;
				}
				e.preventDefault();
				var index = e.key === 'Home' ? 0 : ( e.key === 'End' ? tabs.length - 1 : current + ( e.key === 'ArrowRight' ? 1 : -1 ) );
				index = ( index + tabs.length ) % tabs.length;
				tabs[ index ].focus();
				tabs[ index ].click();
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
					propertyPicker.classList.remove( 'cvtrk-is-hidden' );
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

	function init404Monitor() {
		var root = document.getElementById( 'convertrack-404-monitor' );
		if ( ! root ) {
			return;
		}

		var state = {
			page: 1,
			pages: 1,
			lastRows: []
		};
		var statusSel = attr( '404-status' );
		var postTypeSel = attr( '404-post-type' );
		var confidenceMin = attr( '404-confidence-min' );
		var confidenceMax = attr( '404-confidence-max' );
		var detectedFrom = attr( '404-detected-from' );
		var detectedTo = attr( '404-detected-to' );
		var searchInput = attr( '404-search' );
		var progressBox = attr( '404-progress' );
		var exportLink = attr( '404-export' );
		var threshold = Number( root.getAttribute( 'data-404-threshold' ) ) || 90;

		function statusText( status ) {
			status = String( status || '' );
			var map = {
				new: 'New',
				recommended: 'Recommended',
				auto_redirected: 'Auto redirected',
				approved: 'Approved',
				ignored: 'Ignored',
				manual_review: 'Manual review',
				deleted: 'Deleted',
				active: 'Active',
				paused: 'Paused',
				disabled: 'Disabled'
			};
			return map[ status ] || status.replace( /_/g, ' ' );
		}

		function dateText( value ) {
			if ( ! value ) {
				return '-';
			}
			return String( value ).replace( 'T', ' ' );
		}

		function setProgress( text, isError ) {
			if ( ! progressBox ) {
				return;
			}
			if ( ! text ) {
				progressBox.hidden = true;
				return;
			}
			progressBox.hidden = false;
			progressBox.textContent = text;
			// Errors get the red variant; everything else keeps the neutral
			// info style (progress messages are not "successes").
			progressBox.classList.toggle( 'cvtrk-notice-error', !! isError );
		}

		function appendParam( parts, key, value ) {
			if ( value !== undefined && value !== null && String( value ) !== '' ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
			}
		}

		function query() {
			var parts = [];
			appendParam( parts, 'page', state.page );
			appendParam( parts, 'per_page', 25 );
			appendParam( parts, 'status', statusSel ? statusSel.value : 'all' );
			appendParam( parts, 'post_type', postTypeSel ? postTypeSel.value : 'all' );
			appendParam( parts, 'confidence_min', confidenceMin ? confidenceMin.value : '' );
			appendParam( parts, 'confidence_max', confidenceMax ? confidenceMax.value : '' );
			appendParam( parts, 'detected_from', detectedFrom ? detectedFrom.value : '' );
			appendParam( parts, 'detected_to', detectedTo ? detectedTo.value : '' );
			appendParam( parts, 'search', searchInput ? searchInput.value : '' );
			return '?' + parts.join( '&' );
		}

		function updateExportLink() {
			if ( ! exportLink || ! C.notFoundExportUrl ) {
				return;
			}
			var q = query().replace( /^\?/, '' ).replace( /(^|&)page=[^&]*/g, '' ).replace( /(^|&)per_page=[^&]*/g, '' );
			exportLink.href = C.notFoundExportUrl + '&_wpnonce=' + encodeURIComponent( C.notFoundExportNonce || '' ) + ( q ? '&' + q.replace( /^&/, '' ) : '' );
		}

		function kpi( value, label, icon, mod ) {
			var item = el( 'div', 'cvtrk-kpi' + ( mod ? ' ' + mod : '' ) );
			var iconWrap = el( 'span', 'cvtrk-kpi-icon' );
			iconWrap.appendChild( svgIcon( icon || 'warning', 'cvtrk-icon' ) );
			var body = el( 'span', 'cvtrk-kpi-body' );
			body.appendChild( el( 'span', 'cvtrk-kpi-value', value ) );
			body.appendChild( el( 'span', 'cvtrk-kpi-label', label ) );
			item.appendChild( iconWrap );
			item.appendChild( body );
			return item;
		}

		function renderSummary( data ) {
			var box = attr( '404-summary' );
			if ( ! box ) {
				return;
			}
			clear( box );

			var grid = el( 'div', 'cvtrk-kpis cvtrk-404-kpis' );
			grid.appendChild( kpi( num( data.total ), I18N.nfTotal || 'Total 404 URLs', 'warning' ) );
			grid.appendChild( kpi( num( data.unresolved ), I18N.nfNew || 'New', 'clock' ) );
			grid.appendChild( kpi( num( data.recommended ), I18N.nfPending || 'Pending suggestions', 'search', 'is-amber' ) );
			grid.appendChild( kpi( num( data.redirected ), I18N.nfResolved || 'Auto-resolved', 'update', 'is-accent' ) );
			grid.appendChild( kpi( num( data.manual ), I18N.nfManual || 'Manual review', 'list' ) );
			grid.appendChild( kpi( num( data.ignored ), I18N.nfIgnored || 'Ignored', 'hidden' ) );
			box.appendChild( grid );

			var meta = el( 'div', 'cvtrk-summary-meta cvtrk-404-meta' );
			var mode = data.settings && data.settings.mode ? data.settings.mode : '';
			meta.appendChild( summaryMetaItem( I18N.nfMode || 'Mode', statusText( mode ) ) );
			meta.appendChild( summaryMetaItem( I18N.nfRedirectHits || 'Redirect hits', num( data.redirect_hits ) ) );
			meta.appendChild( summaryMetaItem( I18N.nfValidUrls || 'Valid URLs', num( data.valid_url_count ) ) );
			meta.appendChild( summaryMetaItem( I18N.nfRecentHits || 'Recent hits', num( data.spike_hits ) + ' / ' + num( data.spike_threshold ) ) );
			if ( data.compatibility && data.compatibility.tools && data.compatibility.tools.length ) {
				meta.appendChild( summaryMetaItem( I18N.status || 'Status', I18N.nfToolDetected || 'Redirect tool detected', 'warning' ) );
			}
			box.appendChild( meta );

			var lastScan = attr( '404-last-scan' );
			if ( lastScan ) {
				lastScan.textContent = data.last_sitemap_refresh ? dateText( data.last_sitemap_refresh ) : ( I18N.never || 'Never' );
			}
		}

		function loadSummary() {
			var box = attr( '404-summary' );
			setBusy( box, true );
			api( '/404/summary' )
				.then( function ( data ) {
					setBusy( box, false );
					renderSummary( data );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load 404 summary.', loadSummary );
					}
				} );
		}

		function updateSelectionCount() {
			var counter = attr( '404-selection' );
			if ( ! counter ) {
				return;
			}
			var count = selectedIds().length;
			counter.textContent = count
				? count + ' ' + ( I18N.nfSelected || 'selected' )
				: ( I18N.nfNoneSelected || 'No rows selected' );
			var master = document.querySelector( '.cvtrk-404-select-all' );
			if ( master ) {
				var boxes = document.querySelectorAll( '.cvtrk-404-select' );
				master.checked = boxes.length > 0 && count === boxes.length;
				master.indeterminate = count > 0 && count < boxes.length;
			}
		}

		function checkboxCell( row ) {
			var td = el( 'td', 'cvtrk-check-col' );
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.className = 'cvtrk-404-select';
			input.value = String( row.id );
			input.setAttribute( 'aria-label', ( I18N.nfSelectRow || 'Select 404 row' ) + ' ' + ( row.url || row.id ) );
			input.addEventListener( 'change', updateSelectionCount );
			td.appendChild( input );
			return td;
		}

		function selectAllHeader() {
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.className = 'cvtrk-404-select-all';
			input.setAttribute( 'aria-label', I18N.nfSelectAll || 'Select all rows on this page' );
			input.addEventListener( 'change', function () {
				document.querySelectorAll( '.cvtrk-404-select' ).forEach( function ( box ) {
					box.checked = input.checked;
				} );
				updateSelectionCount();
			} );
			return input;
		}

		function eventActionsCell( row ) {
			var td = el( 'td', 'cvtrk-404-actions' );
			function button( action, text ) {
				var b = el( 'button', 'button button-small', text );
				b.type = 'button';
				b.setAttribute( 'data-404-action', action );
				b.setAttribute( 'data-404-id', row.id );
				b.setAttribute( 'data-404-destination', row.suggested_url || '' );
				return b;
			}
			td.appendChild( button( 'approve', I18N.nfApprove || 'Approve' ) );
			td.appendChild( button( 'edit', I18N.nfEdit || 'Edit' ) );
			td.appendChild( button( 'ignore', I18N.nfIgnore || 'Ignore' ) );
			td.appendChild( button( 'delete', I18N.nfDelete || 'Delete' ) );
			var details = el( 'button', 'button button-small', I18N.nfDetails || 'Details' );
			details.type = 'button';
			details.setAttribute( 'data-404-details', row.id );
			details.setAttribute( 'aria-expanded', 'false' );
			td.appendChild( details );
			return td;
		}

		function detailItem( label, value, href ) {
			var item = el( 'div', 'cvtrk-detail-item' );
			item.appendChild( el( 'span', 'cvtrk-detail-label', label ) );
			var valueEl = el( 'span', 'cvtrk-detail-value' );
			var link = safeUrl( href );
			if ( value && link ) {
				var a = el( 'a', null, value );
				a.href = link;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				valueEl.appendChild( a );
			} else {
				valueEl.textContent = value || '—';
			}
			item.appendChild( valueEl );
			return item;
		}

		function buildDetailRow( row, columns ) {
			var tr = el( 'tr', 'cvtrk-detail-row' );
			tr.setAttribute( 'data-404-detail-row', row.id );
			tr.hidden = true;
			var td = el( 'td' );
			td.colSpan = columns;
			var panel = el( 'div', 'cvtrk-detail-panel' );
			panel.appendChild( detailItem( I18N.nfDetailSource || '404 URL', row.url || '', row.source_full_url ) );
			panel.appendChild( detailItem( I18N.nfDetailReferrer || 'Referrer', row.referrer_url || '', row.referrer_url ) );
			panel.appendChild( detailItem( I18N.nfDetailFirst || 'First detected', dateText( row.first_detected_at ) ) );
			panel.appendChild( detailItem( I18N.nfDetailLast || 'Last detected', dateText( row.last_detected_at ) ) );
			panel.appendChild( detailItem( I18N.nfDetailHits || 'Hit count', num( row.hit_count ) ) );
			panel.appendChild( detailItem( I18N.nfDetailSuggested || 'Suggested redirect', row.suggested_url || '', row.suggested_url ) );
			panel.appendChild( detailItem( I18N.nfDetailConfidence || 'Match confidence', row.suggested_url ? ( Number( row.confidence ) || 0 ) + '%' : '' ) );
			panel.appendChild( detailItem( I18N.nfDetailReason || 'Match reason', row.match_reason || '' ) );
			panel.appendChild( detailItem( I18N.nfDetailPostType || 'Suggested post type', row.suggested_post_type || '' ) );
			panel.appendChild( detailItem( I18N.nfDetailStatus || 'Status', statusText( row.status ) ) );
			if ( row.error_message ) {
				panel.appendChild( detailItem( I18N.nfDetailError || 'Last error', row.error_message ) );
			}
			td.appendChild( panel );
			tr.appendChild( td );
			return tr;
		}

		function renderEvents( data ) {
			var box = attr( '404-events' );
			if ( ! box ) {
				return;
			}
			state.pages = Number( data.pages ) || 1;
			state.lastRows = data.rows || [];
			updateExportLink();
			if ( ! state.lastRows.length ) {
				empty( box, I18N.nfNoRows || 'No 404 rows match the current filters.' );
				updatePagination();
				updateSelectionCount();
				return;
			}

			clear( box );
			// Referrer/Detected stay available in the expandable Details row,
			// so they can safely collapse on narrow admin widths.
			var headers = [
				{ label: '' },
				{ label: I18N.nfColUrl || '404 URL' },
				{ label: I18N.nfColReferrer || 'Referrer', hide: 'md' },
				{ label: I18N.nfColDetected || 'Detected', hide: 'sm' },
				{ label: I18N.nfColHits || 'Hits', num: true },
				{ label: I18N.nfColSuggestion || 'Suggested redirect' },
				{ label: I18N.nfColConfidence || 'Confidence', num: true },
				{ label: I18N.nfColStatus || 'Status' },
				{ label: I18N.nfColActions || 'Actions' }
			];
			var wrap = table( headers );
			var firstTh = wrap.querySelector( 'thead th' );
			if ( firstTh ) {
				firstTh.className = 'cvtrk-check-col';
				firstTh.appendChild( selectAllHeader() );
			}
			var tbody = wrap.querySelector( 'tbody' );
			state.lastRows.forEach( function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( checkboxCell( row ) );
				tr.appendChild( labelCell( row.url || '-', row.source_full_url || '', safeUrl( row.source_full_url ) ) );
				tr.appendChild( labelCell( row.referrer_url || '-', '', safeUrl( row.referrer_url ) ) );
				tr.appendChild( labelCell( dateText( row.last_detected_at ), ( I18N.nfFirstSeen || 'First' ) + ': ' + dateText( row.first_detected_at ) ) );
				tr.appendChild( numCell( row.hit_count ) );
				tr.appendChild( labelCell( row.suggested_url || '-', row.match_reason || '', safeUrl( row.suggested_url ) ) );
				var confidenceCell = el( 'td', 'cvtrk-num' );
				confidenceCell.textContent = row.suggested_url ? ( Number( row.confidence ) || 0 ) + '%' : '—';
				tr.appendChild( confidenceCell );
				var statusCell = el( 'td' );
				var statusBadge = el( 'span', 'cvtrk-badge cvtrk-status-' + statusClass( row.status ) );
				statusBadge.appendChild( el( 'span', 'cvtrk-badge-dot' ) );
				statusBadge.appendChild( document.createTextNode( statusText( row.status ) ) );
				statusCell.appendChild( statusBadge );
				tr.appendChild( statusCell );
				tr.appendChild( eventActionsCell( row ) );
				applyHideClasses( tr, headers );
				tbody.appendChild( tr );
				tbody.appendChild( buildDetailRow( row, headers.length ) );
			} );
			box.appendChild( wrap );
			updatePagination();
			updateSelectionCount();
		}

		function loadEvents() {
			var box = attr( '404-events' );
			setBusy( box, true );
			api( '/404/events' + query() )
				.then( function ( data ) {
					setBusy( box, false );
					renderEvents( data );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load 404 events.', loadEvents );
					}
				} );
		}

		function updatePagination() {
			var pageEl = attr( '404-page' );
			var prev = attr( '404-prev' );
			var next = attr( '404-next' );
			if ( pageEl ) {
				pageEl.textContent = ( I18N.pageWord || 'Page' ) + ' ' + state.page + ' / ' + state.pages;
			}
			if ( prev ) {
				prev.disabled = state.page <= 1;
			}
			if ( next ) {
				next.disabled = state.page >= state.pages;
			}
		}

		function renderRedirects( data ) {
			var box = attr( '404-redirects' );
			if ( ! box ) {
				return;
			}
			var rows = ( data && data.rows ) || [];
			if ( ! rows.length ) {
				empty( box, 'No internal or readable external redirects found yet.' );
				return;
			}
			clear( box );
			var wrap = table( [
				{ label: 'Source' },
				{ label: 'Destination' },
				{ label: 'Provider' },
				{ label: 'Status' },
				{ label: 'Last hit' },
				{ label: 'Hits', num: true },
				{ label: 'Actions' }
			] );
			var tbody = wrap.querySelector( 'tbody' );
			rows.forEach( function ( row ) {
				var tr = el( 'tr' );
				var provider = row.provider || row.source || 'internal';
				tr.appendChild( labelCell( row.source_url || '-', '', safeUrl( row.source_url ) ) );
				tr.appendChild( labelCell( row.destination_url || '-', '', safeUrl( row.destination_url ) ) );
				tr.appendChild( el( 'td', null, provider ) );
				var redirectStatus = el( 'td' );
				var typeSuffix = row.redirect_type ? ' / ' + row.redirect_type : ( row.external ? '' : ' / 301' );
				var redirectBadge = el( 'span', 'cvtrk-badge cvtrk-status-' + statusClass( row.status ) );
				redirectBadge.appendChild( el( 'span', 'cvtrk-badge-dot' ) );
				redirectBadge.appendChild( document.createTextNode( statusText( row.status ) + typeSuffix ) );
				redirectStatus.appendChild( redirectBadge );
				tr.appendChild( redirectStatus );
				tr.appendChild( el( 'td', null, dateText( row.last_hit_at ) ) );
				tr.appendChild( numCell( row.hit_count ) );
				var actions = el( 'td', 'cvtrk-404-actions' );
				if ( row.external ) {
					actions.appendChild( el( 'span', 'cvtrk-badge cvtrk-badge-gray', 'Read-only' ) );
				} else {
					[ 'active', 'paused', 'disabled' ].forEach( function ( status ) {
						var b = el( 'button', 'button button-small', statusText( status ) );
						b.type = 'button';
						b.disabled = row.status === status;
						b.setAttribute( 'data-404-redirect-action', 'status' );
						b.setAttribute( 'data-404-redirect-id', row.id );
						b.setAttribute( 'data-404-redirect-status', status );
						actions.appendChild( b );
					} );
					var del = el( 'button', 'button button-small', 'Delete' );
					del.type = 'button';
					del.setAttribute( 'data-404-redirect-action', 'delete' );
					del.setAttribute( 'data-404-redirect-id', row.id );
					actions.appendChild( del );
				}
				tr.appendChild( actions );
				tbody.appendChild( tr );
			} );
			box.appendChild( wrap );
		}

		function loadRedirects() {
			var box = attr( '404-redirects' );
			setBusy( box, true );
			api( '/404/redirects?limit=100' )
				.then( function ( data ) {
					setBusy( box, false );
					renderRedirects( data );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load redirects.', loadRedirects );
					}
				} );
		}

		function renderLogs( data ) {
			var box = attr( '404-logs' );
			if ( ! box ) {
				return;
			}
			var rows = ( data && data.rows ) || [];
			if ( ! rows.length ) {
				empty( box, 'No 404 Monitor log entries yet.' );
				return;
			}
			clear( box );
			var wrap = table( [
				{ label: 'Time' },
				{ label: 'Level' },
				{ label: 'Source' },
				{ label: 'Message' },
				{ label: 'Context' }
			] );
			var tbody = wrap.querySelector( 'tbody' );
			rows.forEach( function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', null, dateText( row.created_at ) ) );
				var levelCell = el( 'td' );
				levelCell.appendChild( el( 'span', 'cvtrk-badge cvtrk-status-' + statusClass( row.level ), row.level || '' ) );
				tr.appendChild( levelCell );
				tr.appendChild( el( 'td', null, row.source || '' ) );
				tr.appendChild( labelCell( row.message || '' ) );
				tr.appendChild( labelCell( row.context || '' ) );
				tbody.appendChild( tr );
			} );
			box.appendChild( wrap );
		}

		function loadLogs() {
			var box = attr( '404-logs' );
			setBusy( box, true );
			api( '/404/logs?limit=50' )
				.then( function ( data ) {
					setBusy( box, false );
					renderLogs( data );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load 404 logs.', loadLogs );
					}
				} );
		}

		function reloadAll() {
			loadSummary();
			loadEvents();
			loadRedirects();
			loadLogs();
		}

		function selectedIds() {
			var box = attr( '404-events' );
			if ( ! box ) {
				return [];
			}
			return Array.prototype.slice.call( box.querySelectorAll( '.cvtrk-404-select:checked' ) ).map( function ( input ) {
				return Number( input.value ) || 0;
			} ).filter( Boolean );
		}

		var destinationModal = null;
		var destinationLastFocused = null;

		function closeDestinationDialog() {
			if ( ! destinationModal ) {
				return;
			}
			document.removeEventListener( 'keydown', onDestinationDialogKey );
			setModalBackgroundInert( destinationModal, false );
			if ( destinationModal._cvtrkCleanup ) {
				destinationModal._cvtrkCleanup();
				destinationModal._cvtrkCleanup = null;
			}
			if ( destinationModal.getAttribute( 'data-cvtrk' ) === '404-edit-dialog' ) {
				destinationModal.hidden = true;
			} else {
				destinationModal.parentNode && destinationModal.parentNode.removeChild( destinationModal );
			}
			destinationModal = null;
			if ( destinationLastFocused && destinationLastFocused.focus ) {
				destinationLastFocused.focus();
			}
		}

		function onDestinationDialogKey( event ) {
			handleModalKeydown( event, destinationModal, closeDestinationDialog );
		}

		function run404Action( action, id, destination ) {
			var body = { id: id };
			if ( destination ) {
				body.destination = destination;
			}
			return postApi( '/404/' + action, body )
				.then( function () {
					setProgress( '404 row updated.' );
					reloadAll();
				} )
				.catch( function ( err ) {
					setProgress( ( err && err.message ) || '404 action failed.', true );
					throw err;
				} );
		}

		function showDestinationDialog( action, id, current, trigger ) {
			if ( destinationModal ) {
				return;
			}
			destinationLastFocused = trigger || document.activeElement;
			var staticDialog = attr( '404-edit-dialog' );
			if ( staticDialog ) {
				destinationModal = staticDialog;
				var staticId = attr( '404-edit-id' );
				var staticInput = attr( '404-edit-destination' );
				var staticError = attr( '404-edit-error' );
				var staticSave = attr( '404-edit-save' );
				var staticCancels = Array.prototype.slice.call( root.querySelectorAll( '[data-cvtrk="404-edit-cancel"]' ) );
				if ( staticId ) {
					staticId.value = String( id );
				}
				if ( staticInput ) {
					staticInput.value = current || '';
				}
				if ( staticError ) {
					staticError.hidden = true;
					staticError.textContent = '';
				}
				staticDialog.hidden = false;
				function cancelStatic() {
					closeDestinationDialog();
				}
				function clickBackdrop( event ) {
					if ( event.target === staticDialog ) {
						closeDestinationDialog();
					}
				}
				function saveStatic() {
					var destination = staticInput ? staticInput.value.trim() : '';
					if ( ! destination || ( staticInput && staticInput.checkValidity && ! staticInput.checkValidity() ) ) {
						if ( staticError ) {
							staticError.textContent = I18N.nfDestinationRequired || 'Enter a valid destination URL.';
							staticError.hidden = false;
						}
						if ( staticInput && staticInput.reportValidity ) {
							staticInput.reportValidity();
						}
						staticInput && staticInput.focus();
						return;
					}
					if ( staticSave ) {
						staticSave.disabled = true;
					}
					run404Action( action, id, destination )
						.then( closeDestinationDialog )
						.catch( function ( err ) {
							if ( staticSave ) {
								staticSave.disabled = false;
							}
							if ( staticError ) {
								staticError.textContent = ( err && err.message ) || 'Could not update this redirect.';
								staticError.hidden = false;
							}
						} );
				}
				function inputKey( event ) {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						saveStatic();
					}
				}
				staticCancels.forEach( function ( button ) { button.addEventListener( 'click', cancelStatic ); } );
				staticSave && staticSave.addEventListener( 'click', saveStatic );
				staticInput && staticInput.addEventListener( 'keydown', inputKey );
				staticDialog.addEventListener( 'click', clickBackdrop );
				staticDialog._cvtrkCleanup = function () {
					staticCancels.forEach( function ( button ) { button.removeEventListener( 'click', cancelStatic ); } );
					staticSave && staticSave.removeEventListener( 'click', saveStatic );
					staticInput && staticInput.removeEventListener( 'keydown', inputKey );
					staticDialog.removeEventListener( 'click', clickBackdrop );
					if ( staticSave ) {
						staticSave.disabled = false;
					}
				};
				setModalBackgroundInert( destinationModal, true );
				document.addEventListener( 'keydown', onDestinationDialogKey );
				if ( staticInput ) {
					staticInput.focus();
					staticInput.select();
				}
				return;
			}
			destinationModal = el( 'div', 'cvtrk-modal' );
			destinationModal.setAttribute( 'role', 'dialog' );
			destinationModal.setAttribute( 'aria-modal', 'true' );
			destinationModal.setAttribute( 'aria-labelledby', 'cvtrk-404-destination-title' );
			destinationModal.setAttribute( 'aria-describedby', 'cvtrk-404-destination-help' );

			var backdrop = el( 'div', 'cvtrk-modal-backdrop' );
			backdrop.addEventListener( 'click', closeDestinationDialog );
			destinationModal.appendChild( backdrop );

			var dialog = el( 'div', 'cvtrk-modal-dialog' );
			var title = el( 'h2', null, action === 'edit' ? ( I18N.nfEditDestination || 'Edit redirect destination' ) : ( I18N.nfAddDestination || 'Add redirect destination' ) );
			title.id = 'cvtrk-404-destination-title';
			dialog.appendChild( title );
			var help = el( 'p', null, I18N.nfDestinationHelp || 'Enter the page URL where this missing address should redirect.' );
			help.id = 'cvtrk-404-destination-help';
			dialog.appendChild( help );

			var form = document.createElement( 'form' );
			var label = el( 'label', 'cvtrk-field' );
			label.appendChild( el( 'span', null, I18N.nfDestination || 'Destination URL' ) );
			var input = document.createElement( 'input' );
			input.type = 'url';
			input.className = 'regular-text';
			input.value = current || '';
			input.placeholder = 'https://example.com/page/';
			input.required = true;
			input.setAttribute( 'autocomplete', 'url' );
			label.appendChild( input );
			form.appendChild( label );
			var error = el( 'p', 'cvtrk-notice cvtrk-notice-error' );
			error.hidden = true;
			error.setAttribute( 'aria-live', 'polite' );
			form.appendChild( error );

			var actions = el( 'div', 'cvtrk-modal-actions' );
			var save = el( 'button', 'button button-primary', I18N.save || 'Save destination' );
			save.type = 'submit';
			actions.appendChild( save );
			var cancel = el( 'button', 'button', I18N.cancel || 'Cancel' );
			cancel.type = 'button';
			cancel.addEventListener( 'click', closeDestinationDialog );
			actions.appendChild( cancel );
			form.appendChild( actions );
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var destination = input.value.trim();
				if ( ! destination ) {
					error.textContent = I18N.nfDestinationRequired || 'Enter a destination URL.';
					error.hidden = false;
					input.focus();
					return;
				}
				save.disabled = true;
				error.hidden = true;
				run404Action( action, id, destination )
					.then( closeDestinationDialog )
					.catch( function ( err ) {
						save.disabled = false;
						error.textContent = ( err && err.message ) || 'Could not update this redirect.';
						error.hidden = false;
					} );
			} );
			dialog.appendChild( form );
			destinationModal.appendChild( dialog );
			root.appendChild( destinationModal );
			setModalBackgroundInert( destinationModal, true );
			document.addEventListener( 'keydown', onDestinationDialogKey );
			input.focus();
			input.select();
		}

		var eventBox = attr( '404-events' );
		if ( eventBox ) {
			eventBox.addEventListener( 'click', function ( e ) {
				var detailsBtn = e.target && e.target.closest ? e.target.closest( '[data-404-details]' ) : null;
				if ( detailsBtn ) {
					var detailRow = eventBox.querySelector( '[data-404-detail-row="' + detailsBtn.getAttribute( 'data-404-details' ) + '"]' );
					if ( detailRow ) {
						detailRow.hidden = ! detailRow.hidden;
						detailsBtn.setAttribute( 'aria-expanded', detailRow.hidden ? 'false' : 'true' );
					}
					return;
				}
				var btn = e.target && e.target.closest ? e.target.closest( '[data-404-action]' ) : null;
				if ( ! btn ) {
					return;
				}
				var action = btn.getAttribute( 'data-404-action' );
				var id = Number( btn.getAttribute( 'data-404-id' ) ) || 0;
				var destination = btn.getAttribute( 'data-404-destination' ) || '';

				if ( 'edit' === action || ( 'approve' === action && ! destination ) ) {
					showDestinationDialog( action, id, destination, btn );
					return;
				}
				if ( 'delete' === action && ! window.confirm( 'Delete this 404 row?' ) ) {
					return;
				}
				run404Action( action, id, destination ).catch( function ( err ) {
					// run404Action already announced the error in the persistent status area.
					return err;
				} );
			} );
		}

		var redirectBox = attr( '404-redirects' );
		if ( redirectBox ) {
			redirectBox.addEventListener( 'click', function ( e ) {
				var btn = e.target && e.target.closest ? e.target.closest( '[data-404-redirect-action]' ) : null;
				if ( ! btn ) {
					return;
				}
				var action = btn.getAttribute( 'data-404-redirect-action' );
				var id = Number( btn.getAttribute( 'data-404-redirect-id' ) ) || 0;
				var path = 'delete' === action ? '/404/redirect-delete' : '/404/redirect-status';
				var body = { id: id };
				if ( 'status' === action ) {
					body.status = btn.getAttribute( 'data-404-redirect-status' );
				} else if ( ! window.confirm( 'Delete this internal redirect?' ) ) {
					return;
				}
				postApi( path, body )
					.then( function () {
						setProgress( 'Redirect updated.' );
						reloadAll();
					} )
					.catch( function ( err ) {
						setProgress( ( err && err.message ) || 'Redirect action failed.', true );
					} );
			} );
		}

		function resetPageAndLoad() {
			state.page = 1;
			loadEvents();
		}
		[ statusSel, postTypeSel, confidenceMin, confidenceMax, detectedFrom, detectedTo ].forEach( function ( input ) {
			if ( input ) {
				input.addEventListener( 'change', resetPageAndLoad );
			}
		} );
		if ( searchInput ) {
			var searchTimer = null;
			searchInput.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( resetPageAndLoad, 250 );
			} );
		}

		var prev = attr( '404-prev' );
		var next = attr( '404-next' );
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				state.page = Math.max( 1, state.page - 1 );
				loadEvents();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				state.page = Math.min( state.pages, state.page + 1 );
				loadEvents();
			} );
		}

		var refresh = attr( '404-refresh' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				refresh.disabled = true;
				setProgress( 'Refreshing valid URL cache...' );
				postApi( '/404/refresh', {} )
					.then( function ( data ) {
						setProgress( 'Valid URL cache refreshed. Active candidates: ' + num( data.total ) + '.' );
						refresh.disabled = false;
						reloadAll();
					} )
					.catch( function ( err ) {
						refresh.disabled = false;
						setProgress( ( err && err.message ) || 'URL refresh failed.', true );
						loadLogs();
					} );
			} );
		}

		var process = attr( '404-process' );
		if ( process ) {
			process.addEventListener( 'click', function () {
				process.disabled = true;
				setProgress( 'Processing recommendations...' );
				postApi( '/404/process', { limit: 50 } )
					.then( function ( data ) {
						setProgress( 'Processed ' + num( data.processed ) + ' rows. Auto-created redirects: ' + num( data.auto_created ) + '.' );
						process.disabled = false;
						reloadAll();
					} )
					.catch( function ( err ) {
						process.disabled = false;
						setProgress( ( err && err.message ) || 'Recommendation processing failed.', true );
						loadLogs();
					} );
			} );
		}

		var bulkRun = attr( '404-bulk-run' );
		var bulkAction = attr( '404-bulk-action' );
		if ( bulkRun && bulkAction ) {
			bulkRun.addEventListener( 'click', function () {
				var action = bulkAction.value;
				if ( ! action ) {
					return;
				}
				var body = { action: action, ids: selectedIds(), threshold: threshold };
				if ( 'approve_high_confidence' !== action && ! body.ids.length ) {
					setProgress( 'Choose at least one row first.', true );
					return;
				}
				if ( 'delete' === action && ! window.confirm( I18N.nfBulkDeleteConfirm || 'Delete the selected 404 rows?' ) ) {
					return;
				}
				bulkRun.disabled = true;
				postApi( '/404/bulk', body )
					.then( function ( data ) {
						setProgress( 'Bulk action updated ' + num( data.updated ) + ' rows' + ( Number( data.errors ) ? ' with ' + num( data.errors ) + ' errors.' : '.' ) );
						bulkRun.disabled = false;
						reloadAll();
					} )
					.catch( function ( err ) {
						bulkRun.disabled = false;
						setProgress( ( err && err.message ) || 'Bulk action failed.', true );
					} );
			} );
		}

		reloadAll();
	}

	function initGscKeywords() {
		var root = document.getElementById( 'convertrack-gsc-keywords' );
		if ( ! root ) {
			return;
		}

		var state = {
			page: 1,
			pages: 1,
			perPage: 25,
			orderby: 'opportunity_score',
			order: 'desc',
			range: root.getAttribute( 'data-kw-default-range' ) || '28d',
			lastRows: [],
			statusTimer: null
		};

		var rangeSel = attr( 'kw-range' );
		var customDates = attr( 'kw-custom-dates' );
		var dateFrom = attr( 'kw-date-from' );
		var dateTo = attr( 'kw-date-to' );
		var customSync = attr( 'kw-custom-sync' );
		var typeSel = attr( 'kw-type' );
		var pageSel = attr( 'kw-page-filter' );
		var oppSel = attr( 'kw-opportunity' );
		var presenceSel = attr( 'kw-presence' );
		var minImpressions = attr( 'kw-min-impressions' );
		var searchInput = attr( 'kw-search' );
		var progressBox = attr( 'kw-progress' );
		var exportLink = attr( 'kw-export' );
		var tableBox = attr( 'kw-table' );
		var detailCard = attr( 'kw-detail' );
		var toolsStatus = attr( 'kw-tools-status' );

		var typeLabels = {
			branded: I18N.kwTypeBranded || 'Branded',
			non_branded: I18N.kwTypeNonBranded || 'Non-branded',
			service: I18N.kwTypeService || 'Service',
			product: I18N.kwTypeProduct || 'Product',
			location: I18N.kwTypeLocation || 'Location',
			commercial: I18N.kwTypeCommercial || 'Commercial',
			informational: I18N.kwTypeInformational || 'Informational',
			transactional: I18N.kwTypeTransactional || 'Transactional',
			navigational: I18N.kwTypeNavigational || 'Navigational',
			question: I18N.kwTypeQuestion || 'Question',
			long_tail: I18N.kwTypeLongTail || 'Long-tail',
			competitor: I18N.kwTypeCompetitor || 'Competitor'
		};
		var presenceLabels = {
			present: I18N.kwPresent || 'Present',
			partial: I18N.kwPartial || 'Partial',
			missing: I18N.kwMissing || 'Missing',
			overused: I18N.kwOverused || 'Overused',
			needs_improvement: I18N.kwNeedsImprovement || 'Needs improvement',
			unknown: I18N.kwUnknown || 'Not analyzable'
		};
		var presenceTones = {
			present: 'cvtrk-badge-green',
			partial: 'cvtrk-badge-amber',
			missing: 'cvtrk-badge-red',
			overused: 'cvtrk-badge-amber',
			needs_improvement: 'cvtrk-badge-amber',
			unknown: 'cvtrk-badge-gray'
		};
		var levelLabels = {
			high: I18N.kwOppHigh || 'High',
			medium: I18N.kwOppMedium || 'Medium',
			low: I18N.kwOppLow || 'Low',
			optimized: I18N.kwOppOptimized || 'Optimized'
		};
		var levelTones = {
			high: 'cvtrk-badge-red',
			medium: 'cvtrk-badge-amber',
			low: 'cvtrk-badge-gray',
			optimized: 'cvtrk-badge-green'
		};
		var areaLabels = {
			seo_title: I18N.kwAreaSeoTitle || 'SEO title',
			meta_description: I18N.kwAreaMetaDescription || 'Meta description',
			h1: I18N.kwAreaH1 || 'H1 heading',
			headings: I18N.kwAreaHeadings || 'H2/H3 headings',
			first_paragraph: I18N.kwAreaFirstParagraph || 'First paragraph',
			body: I18N.kwAreaBody || 'Body content',
			image_alts: I18N.kwAreaImageAlts || 'Image alt text',
			anchor_texts: I18N.kwAreaAnchorTexts || 'Internal anchor text',
			url_slug: I18N.kwAreaUrlSlug || 'URL slug'
		};

		function pct( fraction ) {
			return ( ( Number( fraction ) || 0 ) * 100 ).toFixed( 1 ) + '%';
		}

		function setProgress( text, isError ) {
			if ( ! progressBox ) {
				return;
			}
			if ( ! text ) {
				progressBox.hidden = true;
				return;
			}
			progressBox.hidden = false;
			progressBox.textContent = text;
			// Errors get the red variant; everything else keeps the neutral
			// info style (progress messages are not "successes").
			progressBox.classList.toggle( 'cvtrk-notice-error', !! isError );
		}

		function appendParam( parts, key, value ) {
			if ( value !== undefined && value !== null && String( value ) !== '' ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
			}
		}

		function query() {
			var parts = [];
			appendParam( parts, 'page', state.page );
			appendParam( parts, 'per_page', state.perPage );
			appendParam( parts, 'range', state.range );
			appendParam( parts, 'search', searchInput ? searchInput.value : '' );
			appendParam( parts, 'label', typeSel && typeSel.value !== 'all' ? typeSel.value : '' );
			appendParam( parts, 'post_id', pageSel && pageSel.value !== '0' ? pageSel.value : '' );
			appendParam( parts, 'opportunity', oppSel && oppSel.value !== 'all' ? oppSel.value : '' );
			appendParam( parts, 'presence', presenceSel && presenceSel.value !== 'all' ? presenceSel.value : '' );
			appendParam( parts, 'min_impressions', minImpressions ? minImpressions.value : '' );
			appendParam( parts, 'orderby', state.orderby );
			appendParam( parts, 'order', state.order );
			return '?' + parts.join( '&' );
		}

		function updateExportLink() {
			if ( ! exportLink || ! C.gscKeywordsExportUrl ) {
				return;
			}
			var q = query().replace( /^\?/, '' ).replace( /(^|&)page=[^&]*/g, '' ).replace( /(^|&)per_page=[^&]*/g, '' );
			exportLink.href = C.gscKeywordsExportUrl + '&_wpnonce=' + encodeURIComponent( C.gscKeywordsExportNonce || '' ) + ( q ? '&' + q.replace( /^&/, '' ) : '' );
		}

		function kpi( value, label, icon, modifier ) {
			var item = el( 'div', 'cvtrk-kpi' + ( modifier ? ' ' + modifier : '' ) );
			var iconWrap = el( 'span', 'cvtrk-kpi-icon' );
			iconWrap.appendChild( svgIcon( icon || 'search', 'cvtrk-icon' ) );
			var body = el( 'span', 'cvtrk-kpi-body' );
			body.appendChild( el( 'span', 'cvtrk-kpi-value', value ) );
			body.appendChild( el( 'span', 'cvtrk-kpi-label', label ) );
			item.appendChild( iconWrap );
			item.appendChild( body );
			return item;
		}

		function renderSummary( data ) {
			var box = attr( 'kw-summary' );
			if ( ! box ) {
				return;
			}
			clear( box );

			var headSync = attr( 'kw-last-sync' );
			if ( headSync ) {
				headSync.textContent = data.last_synced_at ? shortDate( data.last_synced_at ) : ( I18N.never || 'Never' );
			}

			if ( ! data.connected ) {
				empty( box, I18N.kwNotConnected || 'Connect Google Search Console first.' );
				return;
			}
			if ( ! data.enabled ) {
				empty( box, I18N.kwDisabled || 'Enable Keyword Insights in the settings below.' );
				return;
			}
			if ( ! data.has_data ) {
				var cta = el( 'div', 'cvtrk-empty' );
				cta.appendChild( svgIcon( 'search', 'cvtrk-empty-icon' ) );
				cta.appendChild( el( 'p', null, I18N.kwNeverSynced || 'No keyword data yet.' ) );
				var btn = el( 'button', 'button button-primary', I18N.kwSyncFirst || 'Sync now' );
				btn.type = 'button';
				btn.addEventListener( 'click', function () {
					startSync( {} );
				} );
				cta.appendChild( btn );
				box.appendChild( cta );
				return;
			}

			var grid = el( 'div', 'cvtrk-kpis cvtrk-kw-kpis' );
			grid.appendChild( kpi( num( data.total_keywords ), I18N.kwTotalKeywords || 'Tracked keywords', 'search' ) );
			grid.appendChild( kpi( num( data.high_opportunity ), I18N.kwHighOpportunity || 'High opportunity', 'click', 'is-accent' ) );
			grid.appendChild( kpi( num( data.pages_missing ), I18N.kwPagesMissing || 'Pages with missing keywords', 'warning' ) );
			grid.appendChild( kpi( num( data.page_two ), I18N.kwPageTwo || 'Page-2 rankings (11-20)', 'rate', 'is-amber' ) );
			grid.appendChild( kpi( num( data.low_ctr ), I18N.kwLowCtr || 'High impressions, low CTR', 'visibility' ) );
			box.appendChild( grid );

			var meta = el( 'div', 'cvtrk-summary-meta cvtrk-kw-meta' );
			meta.appendChild( summaryMetaItem( I18N.kwLastSync || 'Last keyword sync', shortDate( data.last_synced_at ) ) );
			meta.appendChild( summaryMetaItem( I18N.kwLastAnalysis || 'Last content analysis', shortDate( data.last_analyzed_at ) ) );
			if ( Number( data.pending_analysis ) > 0 ) {
				meta.appendChild( summaryMetaItem( I18N.kwPendingAnalysis || 'Keywords awaiting analysis', num( data.pending_analysis ), 'warning' ) );
			}
			var lastSync = data.last_sync && data.last_sync[ state.range ];
			if ( lastSync && lastSync.truncated ) {
				meta.appendChild( summaryMetaItem( I18N.status || 'Status', I18N.kwTruncated || 'Row cap reached.', 'warning' ) );
			}
			box.appendChild( meta );

			if ( data.last_error && data.last_error.message ) {
				setProgress( ( I18N.kwSyncFailed || 'Keyword sync failed:' ) + ' ' + data.last_error.message, true );
			}
			if ( data.sync && data.sync.running ) {
				beginPolling();
			}
		}

		function renderBranded( data ) {
			var box = attr( 'kw-branded' );
			if ( ! box ) {
				return;
			}
			clear( box );

			var branded = Number( data.branded ) || 0;
			var non = Number( data.non_branded ) || 0;
			var total = branded + non;
			if ( ! total ) {
				empty( box );
				return;
			}

			var bar = el( 'div', 'cvtrk-split-bar' );
			var segA = el( 'span', 'cvtrk-split-seg is-a' );
			segA.style.width = ( ( branded / total ) * 100 ) + '%';
			var segB = el( 'span', 'cvtrk-split-seg is-b' );
			segB.style.width = ( ( non / total ) * 100 ) + '%';
			bar.appendChild( segA );
			bar.appendChild( segB );
			box.appendChild( bar );

			var legend = el( 'div', 'cvtrk-split-legend' );
			var itemA = el( 'span', 'cvtrk-split-item' );
			itemA.appendChild( el( 'span', 'cvtrk-split-swatch is-a' ) );
			itemA.appendChild( el( 'span', null, ( I18N.kwBranded || 'Branded' ) + ': ' + num( branded ) + ' (' + Math.round( ( branded / total ) * 100 ) + '%)' ) );
			var itemB = el( 'span', 'cvtrk-split-item' );
			itemB.appendChild( el( 'span', 'cvtrk-split-swatch is-b' ) );
			itemB.appendChild( el( 'span', null, ( I18N.kwNonBranded || 'Non-branded' ) + ': ' + num( non ) + ' (' + Math.round( ( non / total ) * 100 ) + '%)' ) );
			legend.appendChild( itemA );
			legend.appendChild( itemB );
			box.appendChild( legend );
		}

		function renderTopPages( rows ) {
			var box = attr( 'kw-top-pages' );
			if ( ! box ) {
				return;
			}
			clear( box );
			rows = ( rows || [] ).filter( function ( row ) {
				return Number( row.keywords ) > 0;
			} );
			if ( ! rows.length ) {
				empty( box );
				return;
			}

			var wrap = table( [
				{ label: I18N.page || 'Page' },
				{ label: I18N.kwRowsLabel || 'keywords', num: true },
				{ label: I18N.kwOpportunity || 'Opportunity', num: true },
				{ label: '' }
			] );
			var tbody = wrap.querySelector( 'tbody' );
			rows.forEach( function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( labelCell( row.post_title || row.page_url, row.page_url, safeUrl( row.page_url ) ) );
				tr.appendChild( numCell( row.keywords ) );
				tr.appendChild( textNumCell( String( Math.round( Number( row.opportunity_score ) || 0 ) ) ) );
				var td = el( 'td', 'cvtrk-kw-actions' );
				if ( row.post_id ) {
					var btn = el( 'button', 'button button-small', I18N.kwDetails || 'Details' );
					btn.type = 'button';
					btn.setAttribute( 'data-kw-action', 'details' );
					btn.setAttribute( 'data-kw-post', row.post_id );
					td.appendChild( btn );
				}
				tr.appendChild( td );
				tbody.appendChild( tr );
			} );
			box.appendChild( wrap );
		}

		function loadSummary() {
			var box = attr( 'kw-summary' );
			setBusy( box, true );
			api( '/gsc/keywords/summary?range=' + encodeURIComponent( state.range ) )
				.then( function ( data ) {
					setBusy( box, false );
					renderSummary( data );
					renderBranded( data );
					renderTopPages( data.top_pages || [] );
				} )
				.catch( function ( err ) {
					setBusy( box, false );
					if ( box ) {
						errorState( box, ( err && err.message ) || 'Could not load keyword summary.', loadSummary );
					}
					// These cards are fed by the same request — stop their
					// skeletons instead of letting them pulse forever.
					[ attr( 'kw-branded' ), attr( 'kw-top-pages' ) ].forEach( function ( card ) {
						if ( card ) {
							errorState( card, ( err && err.message ) || ( I18N.loadError || 'Something went wrong while loading this data.' ) );
						}
					} );
				} );
		}

		function sortableTable( headers ) {
			var wrap = el( 'div', 'cvtrk-table-wrap' );
			var t = el( 'table', 'cvtrk-table' );
			var thead = el( 'thead' );
			var tr = el( 'tr' );
			headers.forEach( function ( h ) {
				var cls = h.num ? 'cvtrk-num' : '';
				var sorted = h.sort && state.orderby === h.sort;
				if ( h.sort ) {
					cls += ' cvtrk-sortable';
					if ( sorted ) {
						cls += state.order === 'asc' ? ' is-sorted-asc' : ' is-sorted-desc';
					}
				}
				var th = el( 'th', cls.trim() || null, h.sort ? null : h.label );
				th.scope = 'col';
				if ( h.hide ) {
					th.classList.add( 'cvtrk-col-hide-' + h.hide );
				}
				if ( h.sort ) {
					// Real button inside the header so keyboard and screen-reader
					// users can sort; aria-sort announces the current direction.
					th.setAttribute( 'aria-sort', sorted ? ( state.order === 'asc' ? 'ascending' : 'descending' ) : 'none' );
					var sortBtn = el( 'button', 'cvtrk-sort-btn', h.label );
					sortBtn.type = 'button';
					sortBtn.addEventListener( 'click', function () {
						if ( state.orderby === h.sort ) {
							state.order = state.order === 'asc' ? 'desc' : 'asc';
						} else {
							state.orderby = h.sort;
							state.order = 'desc';
						}
						state.page = 1;
						loadKeywords();
						updateExportLink();
					} );
					th.appendChild( sortBtn );
					// Clicks on the header cell's padding still sort.
					th.addEventListener( 'click', function ( e ) {
						if ( e.target === th ) {
							sortBtn.click();
						}
					} );
				}
				tr.appendChild( th );
			} );
			thead.appendChild( tr );
			t.appendChild( thead );
			t.appendChild( el( 'tbody' ) );
			wrap.appendChild( t );
			return wrap;
		}

		function updateSelectionCount() {
			var counter = attr( 'kw-selection' );
			if ( ! counter ) {
				return;
			}
			var count = selectedIds().length;
			counter.textContent = count
				? count + ' ' + ( I18N.kwSelected || 'selected' )
				: ( I18N.kwNoneSelected || 'No keywords selected' );
			var master = root.querySelector( '.cvtrk-kw-select-all' );
			if ( master ) {
				var boxes = root.querySelectorAll( '.cvtrk-kw-select' );
				master.checked = boxes.length > 0 && count === boxes.length;
				master.indeterminate = count > 0 && count < boxes.length;
			}
		}

		function checkboxCell( row ) {
			var td = el( 'td', 'cvtrk-check-col' );
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.className = 'cvtrk-kw-select';
			input.value = String( row.id );
			input.setAttribute( 'aria-label', ( I18N.kwSelectRow || 'Select keyword' ) + ' ' + ( row.query || row.id ) );
			input.addEventListener( 'change', updateSelectionCount );
			td.appendChild( input );
			return td;
		}

		function selectAllHeader() {
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.className = 'cvtrk-kw-select-all';
			input.setAttribute( 'aria-label', I18N.kwSelectAll || 'Select all keywords on this page' );
			input.addEventListener( 'change', function () {
				root.querySelectorAll( '.cvtrk-kw-select' ).forEach( function ( box ) {
					box.checked = input.checked;
				} );
				updateSelectionCount();
			} );
			return input;
		}

		function typeBadgesCell( labels ) {
			var td = el( 'td' );
			var group = el( 'span', 'cvtrk-badge-group' );
			var shown = ( labels || [] ).slice( 0, 3 );
			shown.forEach( function ( slug ) {
				group.appendChild( el( 'span', 'cvtrk-badge cvtrk-badge-gray', typeLabels[ slug ] || slug ) );
			} );
			if ( ( labels || [] ).length > 3 ) {
				var more = el( 'span', 'cvtrk-badge cvtrk-badge-gray', '+' + ( labels.length - 3 ) );
				more.title = labels.slice( 3 ).map( function ( slug ) {
					return typeLabels[ slug ] || slug;
				} ).join( ', ' );
				group.appendChild( more );
			}
			td.appendChild( group );
			return td;
		}

		function presenceCell( status, analyzed ) {
			var td = el( 'td' );
			if ( ! analyzed ) {
				td.appendChild( el( 'span', 'cvtrk-badge cvtrk-badge-gray', I18N.kwQueuedForAnalysis || 'Queued for analysis' ) );
				return td;
			}
			var badge = el( 'span', 'cvtrk-badge ' + ( presenceTones[ status ] || 'cvtrk-badge-gray' ) );
			badge.appendChild( el( 'span', 'cvtrk-badge-dot' ) );
			badge.appendChild( document.createTextNode( presenceLabels[ status ] || status ) );
			td.appendChild( badge );
			return td;
		}

		function opportunityCell( score, level, analyzed ) {
			var td = el( 'td', 'cvtrk-num' );
			if ( ! analyzed ) {
				td.appendChild( el( 'span', 'cvtrk-badge cvtrk-badge-gray', '—' ) );
				return td;
			}
			var badge = el( 'span', 'cvtrk-badge cvtrk-score-pill ' + ( levelTones[ level ] || 'cvtrk-badge-gray' ) );
			badge.appendChild( el( 'b', null, String( Math.round( Number( score ) || 0 ) ) ) );
			if ( level ) {
				badge.appendChild( document.createTextNode( ' ' + ( levelLabels[ level ] || level ) ) );
			}
			td.appendChild( badge );
			return td;
		}

		function renderKeywords( data ) {
			if ( ! tableBox ) {
				return;
			}
			clear( tableBox );
			state.pages = Number( data.pages ) || 1;
			state.lastRows = data.rows || [];

			if ( ! state.lastRows.length ) {
				empty( tableBox, I18N.kwNoResults || 'No keywords match the current filters.' );
				updatePagination();
				updateSelectionCount();
				return;
			}

			var kwHeaders = [
				{ label: '' },
				{ label: I18N.kwKeyword || 'Keyword', sort: 'query' },
				{ label: I18N.page || 'Page' },
				{ label: I18N.kwTypes || 'Type', hide: 'sm' },
				{ label: I18N.clicks || 'Clicks', num: true, sort: 'clicks' },
				{ label: I18N.kwImpressions || 'Impressions', num: true, sort: 'impressions' },
				{ label: I18N.kwCtrShort || 'CTR', num: true, sort: 'ctr' },
				{ label: I18N.kwPosition || 'Position', num: true, sort: 'position' },
				{ label: I18N.kwPresence || 'Presence' },
				{ label: I18N.kwOpportunity || 'Opportunity', num: true, sort: 'opportunity_score' },
				{ label: I18N.kwAction || 'Recommended action' },
				{ label: I18N.actions || 'Actions' }
			];
			var wrap = sortableTable( kwHeaders );
			var firstTh = wrap.querySelector( 'thead th' );
			if ( firstTh ) {
				firstTh.className = 'cvtrk-check-col';
				firstTh.appendChild( selectAllHeader() );
			}
			var tbody = wrap.querySelector( 'tbody' );

			state.lastRows.forEach( function ( row ) {
				var analyzed = row.analysis_state === 'done';
				var tr = el( 'tr' );
				tr.appendChild( checkboxCell( row ) );
				tr.appendChild( labelCell( row.query ) );
				tr.appendChild( labelCell( row.post_title || row.page_url, row.page_url, safeUrl( row.page_url ) ) );
				tr.appendChild( analyzed ? typeBadgesCell( row.labels ) : el( 'td', null, '—' ) );
				tr.appendChild( numCell( row.clicks ) );
				tr.appendChild( numCell( row.impressions ) );
				tr.appendChild( textNumCell( pct( row.ctr ) ) );
				tr.appendChild( textNumCell( ( Number( row.position ) || 0 ).toFixed( 1 ) ) );
				tr.appendChild( presenceCell( row.presence_status, analyzed ) );
				tr.appendChild( opportunityCell( row.opportunity_score, row.opportunity_level, analyzed ) );
				tr.appendChild( labelCell( row.primary_recommendation ? row.primary_recommendation.message : '—' ) );

				var actions = el( 'td', 'cvtrk-kw-actions' );
				if ( row.post_id ) {
					var details = el( 'button', 'button button-small', I18N.kwDetails || 'Details' );
					details.type = 'button';
					details.setAttribute( 'data-kw-action', 'details' );
					details.setAttribute( 'data-kw-post', row.post_id );
					actions.appendChild( details );
				}
				var reanalyze = el( 'button', 'button button-small', I18N.kwReanalyze || 'Re-analyze' );
				reanalyze.type = 'button';
				reanalyze.setAttribute( 'data-kw-action', 'reanalyze' );
				reanalyze.setAttribute( 'data-kw-id', row.id );
				actions.appendChild( reanalyze );
				tr.appendChild( actions );

				applyHideClasses( tr, kwHeaders );
				tbody.appendChild( tr );
			} );

			tableBox.appendChild( wrap );
			updatePagination();
			updateSelectionCount();
		}

		function loadKeywords() {
			setBusy( tableBox, true );
			api( '/gsc/keywords' + query() )
				.then( function ( data ) {
					setBusy( tableBox, false );
					renderKeywords( data );
				} )
				.catch( function ( err ) {
					setBusy( tableBox, false );
					if ( tableBox ) {
						errorState( tableBox, ( err && err.message ) || 'Could not load keywords.', loadKeywords );
					}
				} );
		}

		function updatePagination() {
			var prev = attr( 'kw-prev' );
			var next = attr( 'kw-next' );
			var label = attr( 'kw-page' );
			if ( label ) {
				label.textContent = ( I18N.pageWord || 'Page' ) + ' ' + state.page + ' / ' + state.pages;
			}
			if ( prev ) {
				prev.disabled = state.page <= 1;
			}
			if ( next ) {
				next.disabled = state.page >= state.pages;
			}
		}

		function selectedIds() {
			var ids = [];
			root.querySelectorAll( '.cvtrk-kw-select:checked' ).forEach( function ( input ) {
				ids.push( Number( input.value ) );
			} );
			return ids;
		}

		function loadPageFilter() {
			if ( ! pageSel ) {
				return;
			}
			api( '/gsc/keywords/pages?range=' + encodeURIComponent( state.range ) + '&limit=100' )
				.then( function ( data ) {
					var current = pageSel.value;
					while ( pageSel.options.length > 1 ) {
						pageSel.remove( 1 );
					}
					( data.rows || [] ).forEach( function ( row ) {
						if ( ! row.post_id ) {
							return;
						}
						var option = document.createElement( 'option' );
						option.value = String( row.post_id );
						option.textContent = ( row.post_title || row.page_url ) + ' (' + num( row.keywords ) + ')';
						pageSel.appendChild( option );
					} );
					pageSel.value = current;
					if ( pageSel.selectedIndex < 0 ) {
						pageSel.value = '0';
					}
				} )
				.catch( function ( err ) {
					setProgress( ( I18N.kwPageFilterFailed || 'Could not load the page filter:' ) + ' ' + ( ( err && err.message ) || '' ), true );
				} );
		}

		/* Page detail --------------------------------------------------------- */

		function badgeList( parent, items, tone ) {
			var group = el( 'div', 'cvtrk-badge-group' );
			items.forEach( function ( item ) {
				var badge = el( 'span', 'cvtrk-badge ' + tone, item.query );
				badge.title = num( item.impressions ) + ' impressions · ' + num( item.clicks ) + ' clicks · pos ' + ( Number( item.position ) || 0 ).toFixed( 1 );
				group.appendChild( badge );
			} );
			parent.appendChild( group );
		}

		function detailSection( parent, title ) {
			var section = el( 'div', 'cvtrk-kw-detail-section' );
			section.appendChild( el( 'h3', 'cvtrk-gsc-group-title', title ) );
			parent.appendChild( section );
			return section;
		}

		function renderDetail( data ) {
			var body = attr( 'kw-detail-body' );
			var title = attr( 'kw-detail-title' );
			var sub = attr( 'kw-detail-sub' );
			if ( ! body ) {
				return;
			}
			clear( body );
			if ( title ) {
				title.textContent = data.page.post_title || data.page.page_url;
			}
			if ( sub ) {
				sub.textContent = data.page.page_url;
			}

			var totals = data.totals || {};
			var grid = el( 'div', 'cvtrk-kpis cvtrk-kw-kpis' );
			grid.appendChild( kpi( num( totals.keywords ), I18N.kwTotalKeywords || 'Tracked keywords', 'search' ) );
			grid.appendChild( kpi( num( totals.clicks ), I18N.clicks || 'Clicks', 'click' ) );
			grid.appendChild( kpi( num( totals.impressions ), I18N.kwImpressions || 'Impressions', 'visibility' ) );
			grid.appendChild( kpi( ( Number( totals.avg_position ) || 0 ).toFixed( 1 ), I18N.kwPosition || 'Position', 'rate' ) );
			grid.appendChild( kpi( num( totals.missing ), I18N.kwMissingGroup || 'Missing from page', 'warning', 'is-amber' ) );
			body.appendChild( grid );

			if ( data.page.edit_link ) {
				var editWrap = el( 'p' );
				var edit = el( 'a', 'button', I18N.kwEditPage || 'Edit page' );
				edit.href = data.page.edit_link;
				edit.target = '_blank';
				edit.rel = 'noopener noreferrer';
				editWrap.appendChild( edit );
				body.appendChild( editWrap );
			}

			var groups = el( 'div', 'cvtrk-gsc-groups' );
			var groupDefs = [
				{ key: 'present', tone: 'is-good', badge: 'cvtrk-badge-green', label: I18N.kwIncluded || 'Included on page' },
				{ key: 'partial', tone: 'is-warn', badge: 'cvtrk-badge-amber', label: I18N.kwPartialGroup || 'Partially covered' },
				{ key: 'missing', tone: 'is-bad', badge: 'cvtrk-badge-red', label: I18N.kwMissingGroup || 'Missing from page' }
			];
			groupDefs.forEach( function ( def ) {
				var items = ( data.groups && data.groups[ def.key ] ) || [];
				var section = el( 'div', 'cvtrk-gsc-group ' + def.tone );
				section.appendChild( el( 'h3', 'cvtrk-gsc-group-title', def.label + ' (' + items.length + ')' ) );
				if ( items.length ) {
					badgeList( section, items.slice( 0, 30 ), def.badge );
				} else {
					section.appendChild( el( 'p', 'cvtrk-sub', I18N.noData || 'None.' ) );
				}
				groups.appendChild( section );
			} );
			body.appendChild( groups );

			if ( data.recommendations && data.recommendations.length ) {
				var recSection = detailSection( body, I18N.kwPageRecs || 'Recommended actions for this page' );
				data.recommendations.forEach( function ( rec ) {
					var reco = el( 'div', 'cvtrk-reco' );
					var recoIcon = el( 'span', 'cvtrk-reco-icon' );
					recoIcon.appendChild( svgIcon( 'indexed', 'cvtrk-icon' ) );
					reco.appendChild( recoIcon );
					var recoBody = el( 'div', 'cvtrk-reco-body' );
					recoBody.appendChild( el( 'div', 'cvtrk-reco-text', rec.message ) );
					if ( rec.keywords && rec.keywords.length ) {
						recoBody.appendChild( el( 'div', 'cvtrk-reco-keys', rec.keywords.join( ', ' ) ) );
					}
					reco.appendChild( recoBody );
					recSection.appendChild( reco );
				} );
			}

			if ( data.placements && data.placements.length ) {
				var placeSection = detailSection( body, I18N.kwPlacements || 'Recommended placements' );
				var wrap = table( [
					{ label: I18N.kwKeyword || 'Keyword' },
					{ label: I18N.kwAreas || 'Content area coverage' }
				] );
				var tbody = wrap.querySelector( 'tbody' );
				data.placements.forEach( function ( item ) {
					var tr = el( 'tr' );
					tr.appendChild( labelCell( item.query ) );
					var td = el( 'td' );
					var group = el( 'span', 'cvtrk-badge-group' );
					item.areas.forEach( function ( area ) {
						group.appendChild( el( 'span', 'cvtrk-badge cvtrk-badge-amber', areaLabels[ area ] || area ) );
					} );
					td.appendChild( group );
					tr.appendChild( td );
					tbody.appendChild( tr );
				} );
				placeSection.appendChild( wrap );
			}

			if ( data.faq && data.faq.length ) {
				var faqSection = detailSection( body, I18N.kwFaq || 'Suggested FAQ questions' );
				var faqList = el( 'ul', 'cvtrk-kw-list' );
				data.faq.forEach( function ( q ) {
					faqList.appendChild( el( 'li', null, q ) );
				} );
				faqSection.appendChild( faqList );
			}

			if ( data.anchors && data.anchors.length ) {
				var anchorSection = detailSection( body, I18N.kwAnchors || 'Suggested internal link anchor texts' );
				var anchorList = el( 'ul', 'cvtrk-kw-list' );
				data.anchors.forEach( function ( a ) {
					anchorList.appendChild( el( 'li', null, a ) );
				} );
				anchorSection.appendChild( anchorList );
			}

			if ( data.title_meta ) {
				var tmSection = detailSection( body, I18N.kwTitleMeta || 'Current SEO title & description' );
				tmSection.appendChild( el( 'p', 'cvtrk-strong', data.title_meta.title || '—' ) );
				tmSection.appendChild( el( 'p', 'cvtrk-sub', data.title_meta.description || '—' ) );
			}

			if ( data.areas_summary && data.areas_summary.length ) {
				var areaSection = detailSection( body, I18N.kwAreas || 'Content area coverage' );
				var areaGroup = el( 'div', 'cvtrk-badge-group' );
				data.areas_summary.forEach( function ( area ) {
					var total = area.present + area.partial + area.missing;
					if ( ! total ) {
						return;
					}
					var tone = area.present > 0 ? 'cvtrk-badge-green' : ( area.partial > 0 ? 'cvtrk-badge-amber' : 'cvtrk-badge-red' );
					areaGroup.appendChild( el( 'span', 'cvtrk-badge ' + tone, ( areaLabels[ area.area ] || area.area ) + ': ' + area.present + '/' + total ) );
				} );
				areaSection.appendChild( areaGroup );
			}
		}

		function openDetail( postId ) {
			if ( ! detailCard || ! postId ) {
				return;
			}
			detailCard.hidden = false;
			var body = attr( 'kw-detail-body' );
			if ( body ) {
				clear( body );
				body.appendChild( el( 'p', 'cvtrk-skeleton', I18N.loading || 'Loading…' ) );
			}
			api( '/gsc/keywords/page?post_id=' + encodeURIComponent( postId ) + '&range=' + encodeURIComponent( state.range ) )
				.then( function ( data ) {
					renderDetail( data );
					detailCard.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					if ( window.history && history.replaceState ) {
						history.replaceState( null, '', '#kw-page-' + postId );
					}
				} )
				.catch( function ( err ) {
					var box = attr( 'kw-detail-body' );
					if ( box ) {
						errorState( box, ( I18N.kwDetailFailed || 'Could not load the page detail:' ) + ' ' + ( ( err && err.message ) || '' ), function () {
							openDetail( postId );
						} );
					}
				} );
		}

		function closeDetail() {
			if ( detailCard ) {
				detailCard.hidden = true;
			}
			if ( window.history && history.replaceState ) {
				history.replaceState( null, '', location.pathname + location.search );
			}
		}

		/* Sync + analysis ------------------------------------------------------ */

		function setSyncButtons( disabled ) {
			[ attr( 'kw-sync' ), attr( 'kw-sync-now' ), customSync ].forEach( function ( btn ) {
				if ( btn ) {
					btn.disabled = disabled;
				}
			} );
		}

		function startSync( body ) {
			setSyncButtons( true );
			setProgress( I18N.kwSyncQueued || 'Keyword sync started…' );
			postApi( '/gsc/keywords/sync', body || {} )
				.then( function () {
					beginPolling();
				} )
				.catch( function ( err ) {
					setSyncButtons( false );
					setProgress( ( I18N.kwSyncFailed || 'Keyword sync failed:' ) + ' ' + ( ( err && err.message ) || '' ), true );
				} );
		}

		function beginPolling() {
			if ( state.statusTimer ) {
				return;
			}
			setSyncButtons( true );
			var pollErrors = 0;
			state.statusTimer = window.setInterval( function () {
				api( '/gsc/keywords/status' )
					.then( function ( data ) {
						pollErrors = 0;
						var sync = data.sync || {};
						if ( sync.running ) {
							var progress = sync.progress || {};
							setProgress( ( I18N.kwSyncRunning || 'Syncing keyword data from Search Console…' ) + ' ' + num( progress.rows_stored ) + ' ' + ( I18N.kwRowsLabel || 'keywords' ) + ' (' + ( progress.percent || 0 ) + '%)' );
							return;
						}

						window.clearInterval( state.statusTimer );
						state.statusTimer = null;
						setSyncButtons( false );

						if ( sync.status === 'failed' && sync.last_error ) {
							setProgress( ( I18N.kwSyncFailed || 'Keyword sync failed:' ) + ' ' + sync.last_error.message, true );
						} else {
							setProgress( I18N.kwSyncDone || 'Keyword sync complete.' );
						}
						reloadAll();
					} )
					.catch( function ( err ) {
						// Do not let repeated status errors lock the Sync buttons
						// forever: give up after a few failures and let the user retry.
						pollErrors++;
						if ( pollErrors >= 5 ) {
							window.clearInterval( state.statusTimer );
							state.statusTimer = null;
							setSyncButtons( false );
							setProgress( ( I18N.kwStatusLost || 'Lost contact with the sync status endpoint. The sync may still be running in the background — refresh to check.' ) + ( err && err.message ? ' (' + err.message + ')' : '' ), true );
						}
					} );
			}, 4000 );
		}

		function reloadAll() {
			loadSummary();
			loadKeywords();
			loadPageFilter();
			updateExportLink();
		}

		/* Events --------------------------------------------------------------- */

		if ( rangeSel ) {
			rangeSel.addEventListener( 'change', function () {
				state.range = rangeSel.value;
				state.page = 1;
				var isCustom = state.range === 'custom';
				if ( customDates ) {
					customDates.hidden = ! isCustom;
				}
				if ( isCustom ) {
					setProgress( I18N.kwCustomHint || 'Pick both dates, then press "Sync range".' );
				} else {
					setProgress( '' );
				}
				reloadAll();
			} );
		}

		if ( customSync ) {
			customSync.addEventListener( 'click', function () {
				if ( ! dateFrom || ! dateTo || ! dateFrom.value || ! dateTo.value ) {
					setProgress( I18N.kwCustomHint || 'Pick both dates first.', true );
					return;
				}
				startSync( { date_from: dateFrom.value, date_to: dateTo.value } );
			} );
		}

		[ typeSel, pageSel, oppSel, presenceSel, minImpressions ].forEach( function ( select ) {
			if ( select ) {
				select.addEventListener( 'change', function () {
					state.page = 1;
					loadKeywords();
					updateExportLink();
				} );
			}
		} );

		if ( searchInput ) {
			var searchTimer = null;
			searchInput.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( function () {
					state.page = 1;
					loadKeywords();
					updateExportLink();
				}, 250 );
			} );
		}

		var prevBtn = attr( 'kw-prev' );
		var nextBtn = attr( 'kw-next' );
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				if ( state.page > 1 ) {
					state.page--;
					loadKeywords();
				}
			} );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				if ( state.page < state.pages ) {
					state.page++;
					loadKeywords();
				}
			} );
		}

		[ attr( 'kw-sync' ), attr( 'kw-sync-now' ) ].forEach( function ( btn ) {
			if ( btn ) {
				btn.addEventListener( 'click', function () {
					startSync( {} );
				} );
			}
		} );

		var reanalyzeAll = attr( 'kw-reanalyze-all' );
		if ( reanalyzeAll ) {
			reanalyzeAll.addEventListener( 'click', function () {
				reanalyzeAll.disabled = true;
				postApi( '/gsc/keywords/analyze', {} )
					.then( function ( data ) {
						reanalyzeAll.disabled = false;
						if ( toolsStatus ) {
							toolsStatus.hidden = false;
							toolsStatus.textContent = ( I18N.kwAnalyzeQueued || 'Re-analysis queued for' ) + ' ' + num( data.queued ) + ' ' + ( I18N.kwRowsLabel || 'keywords' ) + '.';
						}
					} )
					.catch( function ( err ) {
						reanalyzeAll.disabled = false;
						setProgress( ( err && err.message ) || 'Re-analysis failed.', true );
					} );
			} );
		}

		var bulkBtn = attr( 'kw-bulk-reanalyze' );
		if ( bulkBtn ) {
			bulkBtn.addEventListener( 'click', function () {
				var ids = selectedIds();
				if ( ! ids.length ) {
					setProgress( I18N.kwSelectRows || 'Choose at least one keyword first.', true );
					return;
				}
				bulkBtn.disabled = true;
				postApi( '/gsc/keywords/bulk', { action: 'reanalyze', ids: ids } )
					.then( function ( data ) {
						bulkBtn.disabled = false;
						setProgress( ( I18N.kwBulkQueued || 'Queued for re-analysis:' ) + ' ' + num( data.updated ) );
						window.setTimeout( loadKeywords, 1500 );
					} )
					.catch( function ( err ) {
						bulkBtn.disabled = false;
						setProgress( ( err && err.message ) || 'Bulk action failed.', true );
					} );
			} );
		}

		root.addEventListener( 'click', function ( event ) {
			var target = event.target.closest ? event.target.closest( '[data-kw-action]' ) : null;
			if ( ! target ) {
				return;
			}
			var action = target.getAttribute( 'data-kw-action' );
			if ( action === 'details' ) {
				openDetail( Number( target.getAttribute( 'data-kw-post' ) ) );
			} else if ( action === 'reanalyze' ) {
				target.disabled = true;
				postApi( '/gsc/keywords/bulk', { action: 'reanalyze', ids: [ Number( target.getAttribute( 'data-kw-id' ) ) ] } )
					.then( function () {
						target.disabled = false;
						setProgress( ( I18N.kwBulkQueued || 'Queued for re-analysis:' ) + ' 1' );
						window.setTimeout( loadKeywords, 1500 );
					} )
					.catch( function ( err ) {
						target.disabled = false;
						setProgress( ( err && err.message ) || 'Re-analysis failed.', true );
					} );
			}
		} );

		var backBtn = attr( 'kw-detail-back' );
		if ( backBtn ) {
			backBtn.addEventListener( 'click', closeDetail );
		}

		/* Enable prompt ------------------------------------------------------- */

		var enableModal = null;
		var lastFocused = null;

		function closeEnablePrompt() {
			if ( ! enableModal ) {
				return;
			}
			document.removeEventListener( 'keydown', onModalKey );
			setModalBackgroundInert( enableModal, false );
			enableModal.parentNode && enableModal.parentNode.removeChild( enableModal );
			enableModal = null;
			if ( lastFocused && lastFocused.focus ) {
				lastFocused.focus();
			}
		}

		function onModalKey( event ) {
			handleModalKeydown( event, enableModal, closeEnablePrompt );
		}

		function doEnable( button, thenSync ) {
			if ( button ) {
				button.disabled = true;
				button.textContent = I18N.kwEnabling || 'Enabling…';
			}
			postApi( '/gsc/keywords/enable', {} )
				.then( function ( res ) {
					root.setAttribute( 'data-kw-enabled', '1' );
					closeEnablePrompt();
					setProgress( I18N.kwEnabledMsg || 'Keyword Insights enabled.' );
					reloadAll();
					// If Google is connected, kick the first sync straight away.
					if ( thenSync && res && res.connected ) {
						startSync( {} );
					}
				} )
				.catch( function ( err ) {
					if ( button ) {
						button.disabled = false;
						button.textContent = I18N.kwPromptEnableBtn || 'Enable & sync now';
					}
					setProgress( ( I18N.kwEnableFailed || 'Could not enable Keyword Insights:' ) + ' ' + ( ( err && err.message ) || '' ), true );
				} );
		}

		function showEnablePrompt() {
			if ( enableModal ) {
				return;
			}
			var connected = root.getAttribute( 'data-kw-connected' ) === '1';

			enableModal = el( 'div', 'cvtrk-modal' );
			enableModal.setAttribute( 'role', 'dialog' );
			enableModal.setAttribute( 'aria-modal', 'true' );
			enableModal.setAttribute( 'aria-labelledby', 'cvtrk-kw-modal-title' );
			enableModal.setAttribute( 'aria-describedby', 'cvtrk-kw-modal-description' );

			var backdrop = el( 'div', 'cvtrk-modal-backdrop' );
			backdrop.addEventListener( 'click', closeEnablePrompt );
			enableModal.appendChild( backdrop );

			var dialog = el( 'div', 'cvtrk-modal-dialog' );
			var iconWrap = el( 'span', 'cvtrk-modal-icon' );
			iconWrap.appendChild( svgIcon( connected ? 'search' : 'shield', 'cvtrk-icon' ) );
			dialog.appendChild( iconWrap );

			var title = el( 'h2', null, connected ? ( I18N.kwPromptEnableTitle || 'Turn on Keyword Insights' ) : ( I18N.kwPromptConnectTitle || 'Connect Google Search Console' ) );
			title.id = 'cvtrk-kw-modal-title';
			dialog.appendChild( title );
			var description = el( 'p', null, connected ? ( I18N.kwPromptEnableBody || '' ) : ( I18N.kwPromptConnectBody || '' ) );
			description.id = 'cvtrk-kw-modal-description';
			dialog.appendChild( description );

			var actions = el( 'div', 'cvtrk-modal-actions' );
			var primary;

			if ( connected ) {
				primary = el( 'button', 'button button-primary', I18N.kwPromptEnableBtn || 'Enable & sync now' );
				primary.type = 'button';
				primary.addEventListener( 'click', function () {
					doEnable( primary, true );
				} );
				actions.appendChild( primary );

				var dismiss = el( 'button', 'button', I18N.kwPromptDismiss || 'Not now' );
				dismiss.type = 'button';
				dismiss.addEventListener( 'click', closeEnablePrompt );
				actions.appendChild( dismiss );
			} else {
				primary = el( 'a', 'button button-primary', I18N.kwPromptConnectBtn || 'Open Google Index Monitor' );
				primary.href = ( C.adminUrls && C.adminUrls.gsc ) || '#';
				actions.appendChild( primary );

				var enableAnyway = el( 'button', 'button', I18N.kwPromptEnableAnyway || 'Enable anyway' );
				enableAnyway.type = 'button';
				enableAnyway.addEventListener( 'click', function () {
					doEnable( enableAnyway, false );
				} );
				actions.appendChild( enableAnyway );

				var dismiss2 = el( 'button', 'button', I18N.kwPromptDismiss || 'Not now' );
				dismiss2.type = 'button';
				dismiss2.addEventListener( 'click', closeEnablePrompt );
				actions.appendChild( dismiss2 );
			}

			dialog.appendChild( actions );
			enableModal.appendChild( dialog );
			// Append inside the .convertrack wrapper so the design tokens
			// (--cvtrk-*) resolve; they are scoped to that element, not :root.
			root.appendChild( enableModal );

			lastFocused = document.activeElement;
			setModalBackgroundInert( enableModal, true );
			document.addEventListener( 'keydown', onModalKey );
			if ( primary && primary.focus ) {
				primary.focus();
			}
		}

		reloadAll();

		// Resume progress polling after reloads and honor detail deep links.
		api( '/gsc/keywords/status' )
			.then( function ( data ) {
				if ( data.sync && data.sync.running ) {
					beginPolling();
				}
			} )
			.catch( function ( err ) {
				setProgress( ( I18N.kwStatusFailed || 'Could not check keyword sync status:' ) + ' ' + ( ( err && err.message ) || '' ), true );
			} );

		var hashMatch = /^#kw-page-(\d+)$/.exec( location.hash || '' );
		if ( hashMatch ) {
			openDetail( Number( hashMatch[ 1 ] ) );
		}

		// Fresh install / disabled feature: prompt to enable right here instead
		// of making the user hunt through the settings below.
		if ( root.getAttribute( 'data-kw-enabled' ) !== '1' ) {
			showEnablePrompt();
		}
	}

	function initSubviews() {
		var controlSelector = '[data-cvtrk-subview], [data-cvtrk-404-view]';
		var controls = Array.prototype.slice.call( document.querySelectorAll( controlSelector ) );
		if ( ! controls.length ) {
			return;
		}
		var groups = [];
		controls.forEach( function ( control ) {
			var group = control.closest( '[data-cvtrk-subviews]' ) || control.closest( '.convertrack' );
			if ( group && groups.indexOf( group ) === -1 ) {
				groups.push( group );
			}
		} );

		groups.forEach( function ( group, groupIndex ) {
			var groupControls = Array.prototype.slice.call( group.querySelectorAll( controlSelector ) ).filter( function ( control ) {
				return ( control.closest( '[data-cvtrk-subviews]' ) || control.closest( '.convertrack' ) ) === group;
			} );
			var panels = Array.prototype.slice.call( group.querySelectorAll( '[data-cvtrk-subview-panel], [data-cvtrk-subview-content], [data-cvtrk-404-panel]' ) );
			if ( ! groupControls.length || ! panels.length ) {
				return;
			}

			var tablist = groupControls[ 0 ].parentElement;
			if ( tablist ) {
				tablist.setAttribute( 'role', 'tablist' );
			}
			function panelName( panel ) {
				return panel.getAttribute( 'data-cvtrk-subview-panel' ) || panel.getAttribute( 'data-cvtrk-subview-content' ) || panel.getAttribute( 'data-cvtrk-404-panel' ) || '';
			}
			function controlName( control ) {
				return control.getAttribute( 'data-cvtrk-subview' ) || control.getAttribute( 'data-cvtrk-404-view' ) || '';
			}
			function activate( name, updateUrl, focus ) {
				var found = false;
				groupControls.forEach( function ( control, index ) {
					var active = controlName( control ) === name;
					found = found || active;
					control.setAttribute( 'role', 'tab' );
					control.setAttribute( 'aria-selected', active ? 'true' : 'false' );
					control.setAttribute( 'tabindex', active ? '0' : '-1' );
					control.classList.toggle( 'is-active', active );
					if ( active ) {
						control.setAttribute( 'aria-current', 'page' );
					} else {
						control.removeAttribute( 'aria-current' );
					}
					var controlled = panels.filter( function ( panel ) { return panelName( panel ) === controlName( control ); } )[ 0 ];
					if ( controlled ) {
						if ( ! controlled.id ) {
							controlled.id = 'cvtrk-subview-' + groupIndex + '-' + index;
						}
						control.setAttribute( 'aria-controls', controlled.id );
						if ( ! control.id ) {
							control.id = controlled.id + '-tab';
						}
						controlled.setAttribute( 'aria-labelledby', control.id );
					}
					if ( active && focus ) {
						control.focus();
					}
				} );
				if ( ! found ) {
					return;
				}
				panels.forEach( function ( panel ) {
					var active = panelName( panel ) === name;
					panel.hidden = ! active;
					panel.setAttribute( 'role', 'tabpanel' );
					panel.setAttribute( 'tabindex', active ? '0' : '-1' );
				} );
				if ( updateUrl ) {
					setUrlParams( { subview: name }, false );
				}
				group.dispatchEvent( new CustomEvent( 'cvtrk:subview', { detail: { name: name } } ) );
			}

			groupControls.forEach( function ( control ) {
				control.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					activate( controlName( control ), true, false );
				} );
				control.addEventListener( 'keydown', function ( event ) {
					if ( [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].indexOf( event.key ) === -1 ) {
						return;
					}
					event.preventDefault();
					var current = groupControls.indexOf( control );
					var index = event.key === 'Home' ? 0 : ( event.key === 'End' ? groupControls.length - 1 : current + ( event.key === 'ArrowRight' ? 1 : -1 ) );
					index = ( index + groupControls.length ) % groupControls.length;
					activate( controlName( groupControls[ index ] ), true, true );
				} );
			} );

			var initial = getUrlParam( 'subview', '' );
			if ( ! groupControls.some( function ( control ) { return controlName( control ) === initial; } ) ) {
				var activeControl = groupControls.filter( function ( control ) { return control.classList.contains( 'is-active' ); } )[ 0 ];
				initial = activeControl ? controlName( activeControl ) : controlName( groupControls[ 0 ] );
			}
			activate( initial, false, false );
			window.addEventListener( 'popstate', function () {
				var restored = getUrlParam( 'subview', initial );
				if ( groupControls.some( function ( control ) { return controlName( control ) === restored; } ) ) {
					activate( restored, false, false );
				}
			} );
		} );
	}

	/* Boot ---------------------------------------------------------------- */

	initSubviews();
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
	init404Monitor();
	initGscKeywords();
} )();
