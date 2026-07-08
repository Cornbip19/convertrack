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

	<div class="cvtrk-toolbar cvtrk-toolbar-primary">
		<div class="cvtrk-toolbar-group">
			<label class="cvtrk-field">
				<span><?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?></span>
				<select id="convertrack-range" data-cvtrk="range">
					<option value="1"><?php esc_html_e( 'Today', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack-click-conversion-analytics' ); ?></option>
				</select>
			</label>
			<span class="cvtrk-updated">
				<?php echo Admin::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span data-cvtrk="last-updated"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>

		<div class="cvtrk-toolbar-group cvtrk-export-group">
			<span class="cvtrk-field"><?php esc_html_e( 'Export', 'convertrack-click-conversion-analytics' ); ?></span>
			<a class="button" data-cvtrk-export data-type="buttons"><?php esc_html_e( 'Buttons', 'convertrack-click-conversion-analytics' ); ?></a>
			<a class="button" data-cvtrk-export data-type="pages"><?php esc_html_e( 'Pages', 'convertrack-click-conversion-analytics' ); ?></a>
			<a class="button" data-cvtrk-export data-type="sources"><?php esc_html_e( 'Sources', 'convertrack-click-conversion-analytics' ); ?></a>
			<a class="button" data-cvtrk-export data-type="keywords"><?php esc_html_e( 'Keywords', 'convertrack-click-conversion-analytics' ); ?></a>
			<a class="button" data-cvtrk-export data-type="countries"><?php esc_html_e( 'Countries', 'convertrack-click-conversion-analytics' ); ?></a>
			<a class="button" data-cvtrk-export data-type="daily"><?php esc_html_e( 'Daily', 'convertrack-click-conversion-analytics' ); ?></a>
		</div>
	</div>

	<div class="cvtrk-kpis">
		<?php
		$kpis = array(
			array( 'key' => 'pageviews', 'icon' => 'pageviews', 'label' => __( 'Pageviews', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'clicks', 'icon' => 'clicks', 'label' => __( 'Button clicks', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'conversions', 'icon' => 'conversions', 'label' => __( 'Conversions', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
			array( 'key' => 'conversion_rate', 'icon' => 'rate', 'label' => __( 'Conversion rate', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
			array( 'key' => 'click_through', 'icon' => 'click-through', 'label' => __( 'Click-through rate', 'convertrack-click-conversion-analytics' ), 'class' => 'is-amber' ),
			array( 'key' => 'unique_visitors', 'icon' => 'visitors', 'label' => __( 'Unique visitors', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'avg_duration', 'icon' => 'duration', 'label' => __( 'Avg. time on site', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
		);
		foreach ( $kpis as $kpi ) :
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

	<div class="cvtrk-card cvtrk-overview-health-card">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( 'Site health at a glance', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Analytics, redirects, indexing, and plugin status in one place', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="overview-health"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-hint" data-cvtrk="conv-hint" hidden>
		<?php echo Admin::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<?php esc_html_e( 'No conversions are being recorded yet. A conversion counts after you configure a goal page or a specific button.', 'convertrack-click-conversion-analytics' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-settings' ) ); ?>"><?php esc_html_e( 'Set up a conversion goal', 'convertrack-click-conversion-analytics' ); ?></a>
		</span>
	</div>

	<div class="cvtrk-grid cvtrk-grid-2-1">
		<div class="cvtrk-card cvtrk-card-tall">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Activity trend', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Pageviews, clicks, and conversions by day', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div class="cvtrk-chart cvtrk-chart-large" data-cvtrk="chart"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card cvtrk-card-tall">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Engagement mix', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Visits, clicks, scrolls, and conversions', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="engagement-chart"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid cvtrk-grid-2-1">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Activity by hour', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Visitor activity patterns across the day', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div class="cvtrk-hourly" data-cvtrk="hourly-chart"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Traffic sources', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors come from', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-sources"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Most clicked buttons', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Across the whole site', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Top pages', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Ranked by visits, clicks, and conversions', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-pages"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid cvtrk-grid-compact">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Search keywords', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Queries and terms visitors used', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-search-terms"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Top countries', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors are located', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-countries"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head cvtrk-card-head-controls">
			<div>
				<h2><?php esc_html_e( 'Activity timeline', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Timestamped page visits, clicks, scrolls, and conversions', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-actions">
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Type', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="timeline-type">
						<option value="all"><?php esc_html_e( 'All events', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pageview"><?php esc_html_e( 'Page visits', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="click"><?php esc_html_e( 'Clicks', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="scroll"><?php esc_html_e( 'Scrolls', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="conversion"><?php esc_html_e( 'Conversions', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-mini-field cvtrk-mini-field-search">
					<span><?php esc_html_e( 'Filter', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="search" data-cvtrk="timeline-filter" placeholder="<?php esc_attr_e( 'Visitor, page, selector', 'convertrack-click-conversion-analytics' ); ?>" />
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Sort', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="timeline-sort">
						<option value="desc"><?php esc_html_e( 'Newest first', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="asc"><?php esc_html_e( 'Oldest first', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="event-timeline"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( 'Live visitors', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Who is on the site right now', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="active-sessions"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>
</div>
