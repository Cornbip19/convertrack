<?php
/**
 * Overview dashboard. Data is hydrated client-side from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-overview">
	<?php Admin::render_header( 'overview' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Dashboard', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'A focused view of traffic, conversions, and the work that needs your attention.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta">
				<?php echo Admin::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span data-cvtrk="last-updated"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>
		<div class="cvtrk-page-head-actions">
			<label class="cvtrk-field">
				<span><?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?></span>
				<select id="convertrack-range" data-cvtrk="range">
					<option value="1"><?php esc_html_e( 'Today', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack-click-conversion-analytics' ); ?></option>
				</select>
			</label>
			<details class="cvtrk-action-menu cvtrk-export-menu">
				<summary class="button"><?php esc_html_e( 'Export', 'convertrack-click-conversion-analytics' ); ?></summary>
				<div class="cvtrk-action-menu-panel" role="group" aria-label="<?php esc_attr_e( 'Export dashboard data', 'convertrack-click-conversion-analytics' ); ?>">
					<a class="button" data-cvtrk-export data-type="buttons"><?php esc_html_e( 'Buttons', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="pages"><?php esc_html_e( 'Pages', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="sources"><?php esc_html_e( 'Sources', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="keywords"><?php esc_html_e( 'Keywords', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="countries"><?php esc_html_e( 'Countries', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="daily"><?php esc_html_e( 'Daily activity', 'convertrack-click-conversion-analytics' ); ?></a>
				</div>
			</details>
		</div>
	</div>

	<div class="cvtrk-kpis cvtrk-kpis-primary" aria-label="<?php esc_attr_e( 'Primary analytics', 'convertrack-click-conversion-analytics' ); ?>">
		<?php
		$primary_kpis = array(
			array( 'key' => 'unique_visitors', 'icon' => 'visitors', 'label' => __( 'Visitors', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'pageviews', 'icon' => 'pageviews', 'label' => __( 'Pageviews', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'conversions', 'icon' => 'conversions', 'label' => __( 'Conversions', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
			array( 'key' => 'conversion_rate', 'icon' => 'rate', 'label' => __( 'Conversion rate', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
		);
		foreach ( $primary_kpis as $kpi ) :
			?>
			<div class="cvtrk-kpi <?php echo esc_attr( $kpi['class'] ); ?>">
				<span class="cvtrk-kpi-icon"><?php echo Admin::icon( $kpi['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cvtrk-kpi-body">
					<span class="cvtrk-kpi-value" data-cvtrk="<?php echo esc_attr( $kpi['key'] ); ?>">-</span>
					<span class="cvtrk-kpi-label"><?php echo esc_html( $kpi['label'] ); ?></span>
					<span class="cvtrk-kpi-delta" data-cvtrk-delta="<?php echo esc_attr( $kpi['key'] ); ?>"></span>
				</span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="cvtrk-secondary-metrics" aria-label="<?php esc_attr_e( 'Supporting analytics', 'convertrack-click-conversion-analytics' ); ?>">
		<div class="cvtrk-secondary-metric"><span><?php esc_html_e( 'Clicks', 'convertrack-click-conversion-analytics' ); ?></span><strong data-cvtrk="clicks">-</strong><span class="cvtrk-kpi-delta" data-cvtrk-delta="clicks"></span></div>
		<div class="cvtrk-secondary-metric"><span><?php esc_html_e( 'Click-through rate', 'convertrack-click-conversion-analytics' ); ?></span><strong data-cvtrk="click_through">-</strong><span class="cvtrk-kpi-delta" data-cvtrk-delta="click_through"></span></div>
		<div class="cvtrk-secondary-metric"><span><?php esc_html_e( 'Average visit', 'convertrack-click-conversion-analytics' ); ?></span><strong data-cvtrk="avg_duration">-</strong><span class="cvtrk-kpi-delta" data-cvtrk-delta="avg_duration"></span></div>
		<div class="cvtrk-secondary-metric"><span><?php esc_html_e( 'On the site now', 'convertrack-click-conversion-analytics' ); ?></span><strong data-cvtrk="active-secondary">-</strong></div>
	</div>

	<div class="cvtrk-hint" data-cvtrk="conv-hint" hidden>
		<?php echo Admin::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<?php esc_html_e( 'No conversions are being recorded yet. A conversion counts after you configure a goal page or a specific button.', 'convertrack-click-conversion-analytics' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-settings#cvtrk-settings-goals' ) ); ?>"><?php esc_html_e( 'Set up a conversion goal', 'convertrack-click-conversion-analytics' ); ?></a>
		</span>
	</div>

	<div class="cvtrk-card cvtrk-card-tall cvtrk-overview-trend">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( 'Performance trend', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Pageviews, clicks, and conversions across the selected period', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-chart cvtrk-chart-large" data-cvtrk="chart" role="img" aria-label="<?php esc_attr_e( 'Trend chart comparing pageviews, clicks, and conversions by day.', 'convertrack-click-conversion-analytics' ); ?>"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-grid cvtrk-grid-2-1 cvtrk-overview-priority">
		<div class="cvtrk-card cvtrk-overview-health-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Needs attention', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Tracking, broken URLs, indexing, and plugin health', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body"><div data-cvtrk="overview-health"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Top content', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Pages driving visits, clicks, and conversions', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-pages' ) ); ?>"><?php esc_html_e( 'View all', 'convertrack-click-conversion-analytics' ); ?></a>
			</div>
			<div class="cvtrk-card-body"><div data-cvtrk="top-pages"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
		</div>
	</div>

	<details class="cvtrk-more-analytics">
		<summary><?php esc_html_e( 'More analytics', 'convertrack-click-conversion-analytics' ); ?></summary>
		<div class="cvtrk-more-analytics-content">
			<div class="cvtrk-grid cvtrk-grid-2-1">
				<div class="cvtrk-card">
					<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Engagement mix', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Visits, clicks, scrolls, and conversions', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
					<div class="cvtrk-card-body"><div data-cvtrk="engagement-chart" role="img" aria-label="<?php esc_attr_e( 'Chart comparing visits, clicks, scrolls, and conversions.', 'convertrack-click-conversion-analytics' ); ?>"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
				</div>
				<div class="cvtrk-card">
					<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Activity by hour', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Visitor activity patterns across the day', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
					<div class="cvtrk-card-body"><div class="cvtrk-hourly" data-cvtrk="hourly-chart" role="img" aria-label="<?php esc_attr_e( 'Chart showing visitor activity by hour.', 'convertrack-click-conversion-analytics' ); ?>"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
				</div>
			</div>

			<div class="cvtrk-grid">
				<div class="cvtrk-card"><div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Traffic sources', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors come from', 'convertrack-click-conversion-analytics' ); ?></span></div></div><div class="cvtrk-card-body"><div data-cvtrk="top-sources"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div></div>
				<div class="cvtrk-card"><div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Most clicked buttons', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Calls to action across the site', 'convertrack-click-conversion-analytics' ); ?></span></div></div><div class="cvtrk-card-body"><div data-cvtrk="top-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div></div>
			</div>

			<div class="cvtrk-grid cvtrk-grid-compact">
				<div class="cvtrk-card"><div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Search keywords', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Queries and terms visitors used', 'convertrack-click-conversion-analytics' ); ?></span></div></div><div class="cvtrk-card-body"><div data-cvtrk="top-search-terms"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div></div>
				<div class="cvtrk-card"><div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Top countries', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors are located', 'convertrack-click-conversion-analytics' ); ?></span></div></div><div class="cvtrk-card-body"><div data-cvtrk="top-countries"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div></div>
			</div>

			<div class="cvtrk-card">
				<div class="cvtrk-card-head cvtrk-card-head-controls">
					<div><h2><?php esc_html_e( 'Activity timeline', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Timestamped page visits, clicks, scrolls, and conversions', 'convertrack-click-conversion-analytics' ); ?></span></div>
					<div class="cvtrk-card-actions">
						<label class="cvtrk-mini-field"><span><?php esc_html_e( 'Type', 'convertrack-click-conversion-analytics' ); ?></span><select data-cvtrk="timeline-type"><option value="all"><?php esc_html_e( 'All events', 'convertrack-click-conversion-analytics' ); ?></option><option value="pageview"><?php esc_html_e( 'Page visits', 'convertrack-click-conversion-analytics' ); ?></option><option value="click"><?php esc_html_e( 'Clicks', 'convertrack-click-conversion-analytics' ); ?></option><option value="scroll"><?php esc_html_e( 'Scrolls', 'convertrack-click-conversion-analytics' ); ?></option><option value="conversion"><?php esc_html_e( 'Conversions', 'convertrack-click-conversion-analytics' ); ?></option></select></label>
						<label class="cvtrk-mini-field cvtrk-mini-field-search"><span><?php esc_html_e( 'Filter', 'convertrack-click-conversion-analytics' ); ?></span><input type="search" data-cvtrk="timeline-filter" placeholder="<?php esc_attr_e( 'Visitor, page, selector', 'convertrack-click-conversion-analytics' ); ?>" /></label>
						<label class="cvtrk-mini-field"><span><?php esc_html_e( 'Sort', 'convertrack-click-conversion-analytics' ); ?></span><select data-cvtrk="timeline-sort"><option value="desc"><?php esc_html_e( 'Newest first', 'convertrack-click-conversion-analytics' ); ?></option><option value="asc"><?php esc_html_e( 'Oldest first', 'convertrack-click-conversion-analytics' ); ?></option></select></label>
					</div>
				</div>
				<div class="cvtrk-card-body"><div data-cvtrk="event-timeline"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
			</div>

			<div class="cvtrk-card">
				<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Live visitors', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Who is on the site right now', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
				<div class="cvtrk-card-body"><div data-cvtrk="active-sessions"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
			</div>
		</div>
	</details>
</div>
