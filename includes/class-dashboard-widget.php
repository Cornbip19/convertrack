<?php
/**
 * Convertrack widget for the WordPress dashboard.
 *
 * Rendered entirely server-side. Admin::enqueue() deliberately loads nothing on
 * screens outside the plugin, so admin.js and admin.css are unavailable here --
 * pulling either onto wp-admin/index.php for one widget would be indefensible.
 * Hence no JavaScript, no REST call, and one small dedicated stylesheet.
 *
 * The WordPress dashboard is the busiest admin screen on any site, so the whole
 * payload is cached: an uncached build runs several aggregate passes, and paying
 * that on every admin page load would be a tax on the entire site.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Dashboard_Widget {

	const WIDGET_ID = 'convertrack_dashboard_overview';

	/**
	 * Days of history summarised.
	 */
	const RANGE_DAYS = 7;

	/**
	 * How long a built payload stays usable.
	 */
	const CACHE_SECONDS = 300;

	/**
	 * Top pages listed.
	 */
	const TOP_PAGES = 5;

	/**
	 * Register the widget for administrators only.
	 *
	 * Gated before wp_add_dashboard_widget() rather than inside render(), so a
	 * non-administrator never has the box registered at all -- it cannot surface
	 * through screen options, and its title cannot leak into the metabox order.
	 */
	public static function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// wp_add_dashboard_widget() lives in wp-admin/includes/dashboard.php. Core
		// always loads that before firing wp_dashboard_setup, but this hook is
		// public and a third party can fire it from a context where it is not
		// loaded -- which would fatal rather than simply skip the widget.
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Convertrack — Traffic & Clicks', 'convertrack-click-conversion-analytics' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the widget.
	 */
	public static function render() {
		// Defence in depth: register() already gates this, but render() is a
		// public callback and must not assume its own registration path.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Settings::get( 'enabled' ) ) {
			self::render_notice(
				__( 'Tracking is turned off, so there is nothing to report yet.', 'convertrack-click-conversion-analytics' ),
				__( 'Open settings', 'convertrack-click-conversion-analytics' ),
				admin_url( 'admin.php?page=convertrack-settings' )
			);
			return;
		}

		$data = self::payload();

		// A failed migration pauses collection, so the numbers below would be
		// incomplete. Say so rather than presenting them as fact.
		$schema_error = get_option( 'convertrack_core_schema_error' );
		if ( $schema_error ) {
			echo '<p class="cvtrk-dw-warn">' . esc_html__( 'These figures may be incomplete: the database needs attention.', 'convertrack-click-conversion-analytics' ) . '</p>';
		}

		if ( empty( $data['has_data'] ) ) {
			self::render_notice(
				__( 'No activity recorded yet. Once visitors arrive, their pageviews and clicks appear here.', 'convertrack-click-conversion-analytics' ),
				__( 'Open Convertrack', 'convertrack-click-conversion-analytics' ),
				admin_url( 'admin.php?page=convertrack' )
			);
			return;
		}

		echo '<div class="cvtrk-dw">';
		self::render_context( $data );
		self::render_kpis( $data );
		self::render_bars( $data['series'], $data['today'] );
		self::render_top_pages( $data['top_pages'] );
		self::render_footer( $data );
		echo '</div>';
	}

	/**
	 * Build, or reuse, the render payload.
	 *
	 * Keyed on the report cache generation so a completed rollup invalidates the
	 * snapshot immediately instead of leaving stale figures on screen for the rest
	 * of the window.
	 *
	 * @return array
	 */
	public static function payload() {
		$key    = 'convertrack_dw_' . Database::report_cache_generation() . '_' . self::RANGE_DAYS;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$totals = Database::overview_stats( self::RANGE_DAYS );
		$series = Database::clicks_timeseries( self::RANGE_DAYS );
		$pages  = Database::top_pages( self::RANGE_DAYS, self::TOP_PAGES );

		$comparison = isset( $totals['comparison'] ) && is_array( $totals['comparison'] ) ? $totals['comparison'] : array();

		// "Conversions" means conversion events here, as on every plugin screen;
		// converting sessions feed the rate caption.
		$conversion_events = isset( $totals['conversion_events'] ) ? (int) $totals['conversion_events'] : ( isset( $totals['conversions'] ) ? (int) $totals['conversions'] : 0 );
		$data = array(
			'pageviews'         => isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0,
			'clicks'            => isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0,
			'conversions'       => $conversion_events,
			'converting_sessions' => isset( $totals['conversions'] ) ? (int) $totals['conversions'] : 0,
			'sessions'          => isset( $totals['sessions'] ) ? (int) $totals['sessions'] : 0,
			'unique_visitors'   => isset( $totals['unique_visitors'] ) ? (int) $totals['unique_visitors'] : 0,
			'conversion_rate'   => isset( $totals['conversion_rate'] ) ? (float) $totals['conversion_rate'] : 0.0,
			'click_through'     => isset( $totals['click_through'] ) ? (float) $totals['click_through'] : 0.0,
			'comparison'        => $comparison,
			'series'            => self::normalize_series( $series ),
			'top_pages'         => self::normalize_pages( $pages ),
			'today'             => Database::today(),
			'generated_at'      => current_time( 'mysql' ),
		);

		// A site with pageviews but no clicks yet is still "has data" -- only a
		// completely silent range should show the empty state.
		$data['has_data'] = $data['pageviews'] > 0 || $data['clicks'] > 0 || $data['conversions'] > 0;

		set_transient( $key, $data, self::CACHE_SECONDS );
		return $data;
	}

	/**
	 * Reduce the timeseries to what the sparkline needs.
	 *
	 * clicks_timeseries() returns a date-keyed map with gaps already filled and a
	 * live bucket for today, so ordering is preserved by taking values as-is.
	 *
	 * @param array $series Raw series.
	 * @return array List of {date, pageviews, clicks}.
	 */
	private static function normalize_series( $series ) {
		$out = array();
		foreach ( (array) $series as $date => $row ) {
			$out[] = array(
				'date'        => (string) $date,
				'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'visitors'    => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
				'conversions' => isset( $row['conversion_events'] ) ? (int) $row['conversion_events'] : ( isset( $row['conversions'] ) ? (int) $row['conversions'] : 0 ),
			);
		}
		return $out;
	}

	/**
	 * Reduce top pages to the fields rendered, resolving titles and URLs.
	 *
	 * Database::top_pages() returns rows undecorated: the query computes a
	 * resolved title but aliases it as sort_title, uses it only for ordering and
	 * discards it, so page_title comes back empty for any page whose raw events
	 * have already aged out into rollups. The REST layer compensates in
	 * decorate_pages(); a direct caller has to do the same or every row reads
	 * "(untitled)" on any site older than its retention window.
	 *
	 * @param array $pages Raw rows.
	 * @return array
	 */
	private static function normalize_pages( $pages ) {
		$pages = (array) $pages;
		if ( empty( $pages ) ) {
			return array();
		}

		// One batched lookup for anything the post tables cannot answer.
		$page_keys = array();
		foreach ( $pages as $row ) {
			if ( ! empty( $row['page_key'] ) ) {
				$page_keys[] = (string) $row['page_key'];
			}
		}
		$details = Database::page_identity_details( $page_keys );

		$out = array();
		foreach ( $pages as $row ) {
			$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$page_key = isset( $row['page_key'] ) ? (string) $row['page_key'] : '';

			$title = $post_id > 0 ? (string) get_the_title( $post_id ) : '';
			$url   = $post_id > 0 ? (string) get_permalink( $post_id ) : '';

			if ( '' === $title && ! empty( $row['page_title'] ) ) {
				$title = (string) $row['page_title'];
			}
			if ( '' === $url && ! empty( $row['page_url'] ) ) {
				$url = (string) $row['page_url'];
			}
			if ( isset( $details[ $page_key ] ) ) {
				if ( '' === $title && ! empty( $details[ $page_key ]['page_title'] ) ) {
					$title = (string) $details[ $page_key ]['page_title'];
				}
				if ( '' === $url && ! empty( $details[ $page_key ]['page_url'] ) ) {
					$url = (string) $details[ $page_key ]['page_url'];
				}
			}

			$title = trim( $title );
			if ( '' === $title ) {
				$title = '' !== $url ? $url : __( '(untitled)', 'convertrack-click-conversion-analytics' );
			}

			$path = '';
			if ( '' !== $url ) {
				$parts = wp_parse_url( $url );
				$path  = is_array( $parts ) && ! empty( $parts['path'] ) ? (string) $parts['path'] : '/';
			}
			$out[] = array(
				'title'       => Database::display_title( $title ),
				'url'         => $url,
				'path'        => $path,
				'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'conversions' => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
			);
		}
		return $out;
	}

	/**
	 * One line of context above the figures: the window, what the change
	 * chips compare against, and the live visitor count.
	 *
	 * @param array $data Payload.
	 */
	private static function render_context( array $data ) {
		$active = Presence::active_count();
		echo '<div class="cvtrk-dw-context">';
		printf(
			'<span class="cvtrk-dw-window">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of days. */
					__( 'Last %d days, change shown against the %d days before', 'convertrack-click-conversion-analytics' ),
					(int) self::RANGE_DAYS,
					(int) self::RANGE_DAYS
				)
			)
		);
		printf(
			'<span class="cvtrk-dw-live" title="%1$s"><span class="cvtrk-dw-live-dot" aria-hidden="true"></span>%2$s</span>',
			esc_attr__( 'People on the site right now; live, not cached.', 'convertrack-click-conversion-analytics' ),
			esc_html(
				sprintf(
					/* translators: %s: number of people. */
					_n( '%s online now', '%s online now', $active, 'convertrack-click-conversion-analytics' ),
					number_format_i18n( $active )
				)
			)
		);
		echo '</div>';
	}

	/**
	 * Four figures, each with a change chip against the previous window and a
	 * one-line caption that turns the number into a rate a reader can use.
	 *
	 * @param array $data Payload.
	 */
	private static function render_kpis( array $data ) {
		$days     = max( 1, (int) self::RANGE_DAYS );
		$per_day  = $data['pageviews'] > 0 ? number_format_i18n( round( $data['pageviews'] / $days ) ) : '0';
		$per_view = $data['pageviews'] > 0 ? number_format_i18n( $data['clicks'] / $data['pageviews'], 2 ) : '0';
		$kpis     = array(
			array(
				'icon'   => 'pageviews',
				'label'  => __( 'Pageviews', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['pageviews'] ),
				'metric' => 'pageviews',
				/* translators: %s: pageviews per day. */
				'sub'    => sprintf( __( '%s per day', 'convertrack-click-conversion-analytics' ), $per_day ),
			),
			array(
				'icon'   => 'visitors',
				'label'  => __( 'Visitors', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['unique_visitors'] ),
				'metric' => 'unique_visitors',
				/* translators: %s: number of visits (sessions). */
				'sub'    => sprintf( __( '%s visits', 'convertrack-click-conversion-analytics' ), number_format_i18n( $data['sessions'] ) ),
			),
			array(
				'icon'   => 'clicks',
				'label'  => __( 'Clicks', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['clicks'] ),
				'metric' => 'clicks',
				/* translators: %s: clicks per pageview. */
				'sub'    => sprintf( __( '%s per pageview', 'convertrack-click-conversion-analytics' ), $per_view ),
			),
			array(
				'icon'   => 'conversions',
				'label'  => __( 'Conversions', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['conversions'] ),
				'metric' => 'conversion_events',
				/* translators: %s: percentage of visits that converted. */
				'sub'    => sprintf( __( '%s%% of visits', 'convertrack-click-conversion-analytics' ), number_format_i18n( (float) $data['conversion_rate'], 1 ) ),
			),
		);

		echo '<ul class="cvtrk-dw-kpis">';
		foreach ( $kpis as $kpi ) {
			echo '<li class="cvtrk-dw-kpi">';
			echo '<span class="cvtrk-dw-kpi-head">';
			// Admin::icon() returns a complete <svg> that is already aria-hidden.
			echo '<span class="cvtrk-dw-kpi-icon">' . Admin::icon( $kpi['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="cvtrk-dw-kpi-label">' . esc_html( $kpi['label'] ) . '</span>';
			echo '</span>';
			echo '<span class="cvtrk-dw-kpi-row">';
			echo '<span class="cvtrk-dw-kpi-value">' . esc_html( $kpi['value'] ) . '</span>';
			self::render_trend( $data['comparison'], $kpi['metric'] );
			echo '</span>';
			echo '<span class="cvtrk-dw-kpi-sub">' . esc_html( $kpi['sub'] ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Change chip for one metric.
	 *
	 * pct_change() returns null when the previous window had nothing to compare
	 * against, which is meaningfully different from zero change and must not be
	 * drawn as "0%".
	 *
	 * @param array  $comparison Comparison map.
	 * @param string $metric     Metric key.
	 */
	private static function render_trend( array $comparison, $metric ) {
		if ( ! array_key_exists( $metric, $comparison ) || null === $comparison[ $metric ] ) {
			printf(
				'<span class="cvtrk-dw-kpi-trend is-none" title="%1$s">%2$s</span>',
				esc_attr__( 'Nothing to compare against yet.', 'convertrack-click-conversion-analytics' ),
				esc_html__( 'new', 'convertrack-click-conversion-analytics' )
			);
			return;
		}

		$change = (float) $comparison[ $metric ];
		if ( abs( $change ) < 0.05 ) {
			$tone  = 'flat';
			$arrow = '±';
		} elseif ( $change > 0 ) {
			$tone  = 'up';
			$arrow = '▲';
		} else {
			$tone  = 'down';
			$arrow = '▼';
		}

		// The arrow is the primary channel and the colour is secondary: a red/green
		// delta pair is the classic colour-vision trap, so direction must survive
		// without hue. The title names what the change is measured against --
		// a bare percentage does not say "compared to what".
		printf(
			'<span class="cvtrk-dw-kpi-trend is-%1$s" title="%2$s"><span class="cvtrk-dw-arrow" aria-hidden="true">%3$s</span>%4$s%%</span>',
			esc_attr( $tone ),
			esc_attr(
				sprintf(
					/* translators: %d: number of days in the comparison window. */
					__( 'Compared with the previous %d days', 'convertrack-click-conversion-analytics' ),
					(int) self::RANGE_DAYS
				)
			),
			esc_html( $arrow ),
			esc_html( number_format_i18n( abs( $change ), 1 ) )
		);
	}

	/**
	 * One bar per day with its value printed on it. At widget width a bar with
	 * a number beats a sparkline: every day is readable, the busiest day is
	 * marked, and today is drawn hollow because it is still filling up.
	 *
	 * Plain HTML and CSS; no script loads on this screen.
	 *
	 * @param array  $series Normalized series.
	 * @param string $today  Site-local Y-m-d.
	 */
	private static function render_bars( array $series, $today ) {
		$points = count( $series );
		if ( $points < 2 ) {
			return;
		}

		$values  = wp_list_pluck( $series, 'pageviews' );
		$max     = max( $values );
		$peak    = $max > 0 ? array_search( $max, $values, true ) : false;
		$first   = $series[0]['date'];
		$last    = $series[ $points - 1 ]['date'];
		$bar_max = 64; // px; the value label and the day label sit outside this.

		printf(
			'<h3 class="cvtrk-dw-heading">%s</h3>',
			esc_html__( 'Pageviews per day', 'convertrack-click-conversion-analytics' )
		);

		printf(
			'<div class="cvtrk-dw-bars" role="img" aria-label="%s">',
			esc_attr(
				sprintf(
					/* translators: 1: number of days, 2: highest daily pageview count, 3: date of the peak. */
					__( 'Pageviews per day over the last %1$d days; the busiest day was %3$s with %2$s.', 'convertrack-click-conversion-analytics' ),
					$points,
					number_format_i18n( $max ),
					false === $peak ? '' : mysql2date( 'M j', $series[ $peak ]['date'] . ' 00:00:00' )
				)
			)
		);
		foreach ( $series as $index => $day ) {
			$value   = (int) $day['pageviews'];
			$height  = $max > 0 ? max( $value > 0 ? 3 : 1, (int) round( ( $value / $max ) * $bar_max ) ) : 1;
			$classes = array( 'cvtrk-dw-bar-col' );
			if ( $index === $peak && $value > 0 ) {
				$classes[] = 'is-peak';
			}
			if ( $day['date'] === $today ) {
				$classes[] = 'is-today';
			}
			$title = sprintf(
				/* translators: 1: date, 2: pageviews, 3: visitors, 4: clicks, 5: conversions. */
				__( '%1$s: %2$s pageviews, %3$s visitors, %4$s clicks, %5$s conversions', 'convertrack-click-conversion-analytics' ),
				mysql2date( 'D, M j', $day['date'] . ' 00:00:00' ),
				number_format_i18n( $value ),
				number_format_i18n( (int) $day['visitors'] ),
				number_format_i18n( (int) $day['clicks'] ),
				number_format_i18n( (int) $day['conversions'] )
			);
			printf( '<div class="%1$s" title="%2$s">', esc_attr( implode( ' ', $classes ) ), esc_attr( $title ) );
			echo '<span class="cvtrk-dw-bar-value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
			printf( '<span class="cvtrk-dw-bar" style="height:%dpx"></span>', (int) $height );
			echo '<span class="cvtrk-dw-bar-day">';
			echo '<span class="cvtrk-dw-bar-weekday">' . esc_html( mysql2date( 'D', $day['date'] . ' 00:00:00' ) ) . '</span>';
			echo '<span class="cvtrk-dw-bar-date">' . esc_html( mysql2date( 'j', $day['date'] . ' 00:00:00' ) ) . '</span>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';

		echo '<p class="cvtrk-dw-bars-foot">';
		printf(
			'<span class="cvtrk-dw-bars-range">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date. */
					__( '%1$s to %2$s', 'convertrack-click-conversion-analytics' ),
					mysql2date( 'M j', $first . ' 00:00:00' ),
					mysql2date( 'M j', $last . ' 00:00:00' )
				)
			)
		);
		if ( false !== $peak && $max > 0 ) {
			printf(
				'<span class="cvtrk-dw-bars-peak">%s</span>',
				wp_kses(
					sprintf(
						/* translators: 1: date of the busiest day, 2: pageviews that day. */
						__( 'Busiest day %1$s with <b>%2$s</b>', 'convertrack-click-conversion-analytics' ),
						mysql2date( 'M j', $series[ $peak ]['date'] . ' 00:00:00' ),
						number_format_i18n( $max )
					),
					array( 'b' => array() )
				)
			);
		}
		if ( $last === $today ) {
			printf(
				'<span class="cvtrk-dw-bars-today">%s</span>',
				esc_html__( 'Hollow bar: today so far', 'convertrack-click-conversion-analytics' )
			);
		}
		echo '</p>';
	}

	/**
	 * Top pages table: the title wraps rather than vanishing into an ellipsis,
	 * the path sits under it, and conversions get their own column.
	 *
	 * @param array $pages Normalized rows.
	 */
	private static function render_top_pages( array $pages ) {
		if ( empty( $pages ) ) {
			return;
		}

		$max_views = 1;
		foreach ( $pages as $page ) {
			$max_views = max( $max_views, (int) $page['pageviews'] );
		}

		printf(
			'<h3 class="cvtrk-dw-heading">%s</h3>',
			esc_html__( 'Top pages', 'convertrack-click-conversion-analytics' )
		);

		echo '<table class="cvtrk-dw-pages">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Page', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '<th scope="col" class="cvtrk-dw-num">' . esc_html__( 'Views', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '<th scope="col" class="cvtrk-dw-num">' . esc_html__( 'Clicks', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '<th scope="col" class="cvtrk-dw-num">' . esc_html__( 'Conv.', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $pages as $page ) {
			$share = (int) round( ( (int) $page['pageviews'] / $max_views ) * 100 );
			echo '<tr>';
			echo '<td class="cvtrk-dw-page">';
			// Titles come from user content, so they are escaped, never trusted.
			$safe_url = '' !== $page['url'] ? esc_url( $page['url'] ) : '';
			if ( '' !== $safe_url ) {
				printf( '<a class="cvtrk-dw-page-title" href="%1$s">%2$s</a>', $safe_url, esc_html( $page['title'] ) );
			} else {
				echo '<span class="cvtrk-dw-page-title">' . esc_html( $page['title'] ) . '</span>';
			}
			if ( '' !== $page['path'] && $page['path'] !== $page['title'] ) {
				echo '<span class="cvtrk-dw-page-path">' . esc_html( $page['path'] ) . '</span>';
			}
			printf( '<span class="cvtrk-dw-page-share" aria-hidden="true"><span style="width:%d%%"></span></span>', $share );
			echo '</td>';
			echo '<td class="cvtrk-dw-num">' . esc_html( number_format_i18n( $page['pageviews'] ) ) . '</td>';
			echo '<td class="cvtrk-dw-num">' . esc_html( number_format_i18n( $page['clicks'] ) ) . '</td>';
			printf(
				'<td class="cvtrk-dw-num"><span class="cvtrk-dw-conv%1$s">%2$s</span></td>',
				(int) $page['conversions'] > 0 ? ' is-some' : '',
				esc_html( number_format_i18n( $page['conversions'] ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Footer: freshness and a link through to the full dashboard.
	 *
	 * The "as of" time matters. These figures are cached, and a cached number
	 * presented as live is worse than an older number labelled honestly.
	 *
	 * @param array $data Payload.
	 */
	private static function render_footer( array $data ) {
		echo '<p class="cvtrk-dw-foot">';
		printf(
			'<a class="cvtrk-dw-link" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=convertrack' ) ),
			esc_html__( 'View full dashboard', 'convertrack-click-conversion-analytics' )
		);
		echo '<span class="cvtrk-dw-asof">';
		printf(
			/* translators: %s: time of day. */
			esc_html__( 'Updated %s', 'convertrack-click-conversion-analytics' ),
			esc_html( mysql2date( get_option( 'time_format' ), $data['generated_at'] ) )
		);
		echo '</span></p>';
	}

	/**
	 * A single-message state with one action.
	 *
	 * @param string $message Message.
	 * @param string $label   Link label.
	 * @param string $url     Link URL.
	 */
	private static function render_notice( $message, $label, $url ) {
		echo '<div class="cvtrk-dw cvtrk-dw-empty">';
		echo '<p>' . esc_html( $message ) . '</p>';
		printf( '<p><a class="cvtrk-dw-link" href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( $label ) );
		echo '</div>';
	}
}
