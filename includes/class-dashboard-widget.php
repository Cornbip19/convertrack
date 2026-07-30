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
		self::render_kpis( $data );
		self::render_sparkline( $data['series'] );
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

		$data = array(
			'pageviews'       => isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0,
			'clicks'          => isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0,
			'conversions'     => isset( $totals['conversions'] ) ? (int) $totals['conversions'] : 0,
			'unique_visitors' => isset( $totals['unique_visitors'] ) ? (int) $totals['unique_visitors'] : 0,
			'conversion_rate' => isset( $totals['conversion_rate'] ) ? (float) $totals['conversion_rate'] : 0.0,
			'comparison'      => $comparison,
			'series'          => self::normalize_series( $series ),
			'top_pages'       => self::normalize_pages( $pages ),
			'generated_at'    => current_time( 'mysql' ),
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
				'date'      => (string) $date,
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'clicks'    => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
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

			$out[] = array(
				'title'     => $title,
				'url'       => $url,
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'clicks'    => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
			);
		}
		return $out;
	}

	/**
	 * KPI row, with trend against the previous equal-length window.
	 *
	 * @param array $data Payload.
	 */
	private static function render_kpis( array $data ) {
		$active = Presence::active_count();

		$kpis = array(
			array(
				'icon'   => 'pageviews',
				'label'  => __( 'Pageviews', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['pageviews'] ),
				'metric' => 'pageviews',
			),
			array(
				'icon'   => 'clicks',
				'label'  => __( 'Clicks', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['clicks'] ),
				'metric' => 'clicks',
			),
			array(
				'icon'   => 'conversions',
				'label'  => __( 'Conversions', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $data['conversions'] ),
				'metric' => 'conversions',
			),
			array(
				'icon'   => 'visitors',
				'label'  => __( 'Online now', 'convertrack-click-conversion-analytics' ),
				'value'  => number_format_i18n( $active ),
				'metric' => '',
				// Live, unlike the cached range figures beside it.
				'live'   => true,
			),
		);

		echo '<ul class="cvtrk-dw-kpis">';
		foreach ( $kpis as $kpi ) {
			echo '<li class="cvtrk-dw-kpi">';
			// Admin::icon() returns a complete <svg> that is already aria-hidden.
			echo '<span class="cvtrk-dw-kpi-icon">' . Admin::icon( $kpi['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="cvtrk-dw-kpi-value">' . esc_html( $kpi['value'] ) . '</span>';
			echo '<span class="cvtrk-dw-kpi-label">' . esc_html( $kpi['label'] ) . '</span>';
			if ( ! empty( $kpi['live'] ) ) {
				echo '<span class="cvtrk-dw-kpi-live">' . esc_html__( 'live', 'convertrack-click-conversion-analytics' ) . '</span>';
			} elseif ( '' !== $kpi['metric'] ) {
				self::render_trend( $data['comparison'], $kpi['metric'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Trend indicator for one metric.
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
			return;
		}

		$change = (float) $comparison[ $metric ];
		if ( abs( $change ) < 0.05 ) {
			$tone   = 'flat';
			$arrow  = '±';
		} elseif ( $change > 0 ) {
			$tone  = 'up';
			$arrow = '▲';
		} else {
			$tone  = 'down';
			$arrow = '▼';
		}

		printf(
			'<span class="cvtrk-dw-kpi-trend is-%1$s">%2$s %3$s%%</span>',
			esc_attr( $tone ),
			esc_html( $arrow ),
			esc_html( number_format_i18n( abs( $change ), 1 ) )
		);
	}

	/**
	 * Inline sparkline of daily pageviews.
	 *
	 * Hand-built SVG rather than a charting library, since no scripts load on this
	 * screen. Silently renders nothing when there is too little data to plot.
	 *
	 * @param array $series Normalized series.
	 */
	private static function render_sparkline( array $series ) {
		$points = count( $series );
		if ( $points < 2 ) {
			return;
		}

		$values = wp_list_pluck( $series, 'pageviews' );
		$max    = max( $values );
		$width  = 100;
		$height = 28;
		// Inset the plot vertically so the stroke on the highest and lowest points
		// stays inside the viewBox. Without this the peak sits exactly on y=0 and
		// half the stroke width renders outside the box.
		$pad    = 2;
		$usable = $height - ( 2 * $pad );

		$coords = array();
		foreach ( $values as $index => $value ) {
			$x = ( $points > 1 ) ? ( $index / ( $points - 1 ) ) * $width : 0;
			// A flat series would divide by zero; pin it to the baseline instead.
			$y = $max > 0 ? ( $height - $pad ) - ( ( $value / $max ) * $usable ) : ( $height - $pad );
			$coords[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}

		$first = $series[0]['date'];
		$last  = $series[ $points - 1 ]['date'];

		echo '<div class="cvtrk-dw-spark">';
		printf(
			'<svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s" focusable="false">',
			(int) $width,
			(int) $height,
			esc_attr(
				sprintf(
					/* translators: 1: number of days, 2: highest daily pageview count. */
					__( 'Daily pageviews over the last %1$d days, peaking at %2$s.', 'convertrack-click-conversion-analytics' ),
					count( $series ),
					number_format_i18n( $max )
				)
			)
		);
		echo '<polyline points="' . esc_attr( implode( ' ', $coords ) ) . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />';
		echo '</svg>';
		printf(
			'<span class="cvtrk-dw-spark-meta">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date. */
					__( '%1$s to %2$s', 'convertrack-click-conversion-analytics' ),
					mysql2date( get_option( 'date_format' ), $first . ' 00:00:00' ),
					mysql2date( get_option( 'date_format' ), $last . ' 00:00:00' )
				)
			)
		);
		echo '</div>';
	}

	/**
	 * Top pages table.
	 *
	 * @param array $pages Normalized rows.
	 */
	private static function render_top_pages( array $pages ) {
		if ( empty( $pages ) ) {
			return;
		}

		echo '<table class="cvtrk-dw-pages">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Top pages', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '<th scope="col" class="cvtrk-dw-num">' . esc_html__( 'Views', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '<th scope="col" class="cvtrk-dw-num">' . esc_html__( 'Clicks', 'convertrack-click-conversion-analytics' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $pages as $page ) {
			echo '<tr>';
			echo '<td class="cvtrk-dw-page">';
			// Titles come from user content, so they are escaped, never trusted.
			$safe_url = '' !== $page['url'] ? esc_url( $page['url'] ) : '';
			if ( '' !== $safe_url ) {
				printf(
					'<a href="%1$s" title="%2$s">%3$s</a>',
					$safe_url,
					esc_attr( $page['url'] ),
					esc_html( $page['title'] )
				);
			} else {
				echo esc_html( $page['title'] );
			}
			echo '</td>';
			echo '<td class="cvtrk-dw-num">' . esc_html( number_format_i18n( $page['pageviews'] ) ) . '</td>';
			echo '<td class="cvtrk-dw-num">' . esc_html( number_format_i18n( $page['clicks'] ) ) . '</td>';
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
			/* translators: 1: number of days, 2: time of day. */
			esc_html__( 'Last %1$d days · as of %2$s', 'convertrack-click-conversion-analytics' ),
			(int) self::RANGE_DAYS,
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
