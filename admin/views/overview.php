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

	<div class="cvtrk-toolbar">
		<label class="cvtrk-field">
			<?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?>
			<select id="convertrack-range" data-cvtrk="range">
				<option value="1"><?php esc_html_e( 'Today', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<span class="cvtrk-field"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></span>
		<a class="button" data-cvtrk-export data-type="buttons"><?php esc_html_e( 'Buttons', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="pages"><?php esc_html_e( 'Pages', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="sources"><?php esc_html_e( 'Sources', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="keywords"><?php esc_html_e( 'Keywords', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="countries"><?php esc_html_e( 'Countries', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="daily"><?php esc_html_e( 'Daily', 'convertrack-click-conversion-analytics' ); ?></a>
	</div>

	<div class="cvtrk-kpis">
		<?php
		$kpis = array(
			array( 'key' => 'pageviews', 'icon' => 'visibility', 'label' => __( 'Pageviews', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'clicks', 'icon' => 'admin-links', 'label' => __( 'Button clicks', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'conversions', 'icon' => 'yes-alt', 'label' => __( 'Conversions', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
			array( 'key' => 'conversion_rate', 'icon' => 'chart-bar', 'label' => __( 'Conversion rate', 'convertrack-click-conversion-analytics' ), 'class' => 'is-accent' ),
			array( 'key' => 'click_through', 'icon' => 'chart-line', 'label' => __( 'Click-through rate', 'convertrack-click-conversion-analytics' ), 'class' => 'is-amber' ),
			array( 'key' => 'unique_visitors', 'icon' => 'groups', 'label' => __( 'Unique visitors', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
			array( 'key' => 'avg_duration', 'icon' => 'clock', 'label' => __( 'Avg. time on site', 'convertrack-click-conversion-analytics' ), 'class' => '' ),
		);
		foreach ( $kpis as $kpi ) :
			?>
			<div class="cvtrk-kpi <?php echo esc_attr( $kpi['class'] ); ?>">
				<span class="cvtrk-kpi-icon"><span class="dashicons dashicons-<?php echo esc_attr( $kpi['icon'] ); ?>"></span></span>
				<span class="cvtrk-kpi-body">
					<span class="cvtrk-kpi-value" data-cvtrk="<?php echo esc_attr( $kpi['key'] ); ?>">–</span>
					<span class="cvtrk-kpi-label"><?php echo esc_html( $kpi['label'] ); ?></span>
					<span class="cvtrk-kpi-delta" data-cvtrk-delta="<?php echo esc_attr( $kpi['key'] ); ?>"></span>
				</span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="cvtrk-hint" data-cvtrk="conv-hint" hidden>
		<span class="dashicons dashicons-info-outline"></span>
		<span>
			<?php esc_html_e( 'No conversions are being recorded yet. A conversion only counts once you tell Convertrack what one is — a goal page (e.g. /thank-you) or a specific button.', 'convertrack-click-conversion-analytics' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-settings' ) ); ?>"><?php esc_html_e( 'Set up a conversion goal →', 'convertrack-click-conversion-analytics' ); ?></a>
		</span>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Activity', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Pageviews vs. button clicks per day', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-chart" data-cvtrk="chart"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Most clicked buttons', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Across the whole site', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Top converting pages', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Ranked by clicks', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-pages"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Traffic sources', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors come from', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-sources"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Search keywords', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Queries and terms visitors used', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-search-terms"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Top countries', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Where visitors are located', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="top-countries"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Live — who is on the site right now', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Refreshes automatically', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="active-sessions"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>
</div>
