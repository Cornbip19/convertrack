<?php
/**
 * Overview dashboard. Data is hydrated client-side from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack-wrap" id="convertrack-overview">
	<h1 class="convertrack-title">
		<span class="dashicons dashicons-chart-line"></span>
		<?php esc_html_e( 'Convertrack', 'convertrack' ); ?>
	</h1>

	<div class="convertrack-toolbar">
		<div class="convertrack-live" id="convertrack-live">
			<span class="convertrack-live-dot"></span>
			<span class="convertrack-live-count" data-cvtrk="active">–</span>
			<span class="convertrack-live-label"><?php esc_html_e( 'visitors on the site now', 'convertrack' ); ?></span>
		</div>

		<label class="convertrack-range">
			<?php esc_html_e( 'Range', 'convertrack' ); ?>
			<select id="convertrack-range" data-cvtrk="range">
				<option value="1"><?php esc_html_e( 'Today', 'convertrack' ); ?></option>
				<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack' ); ?></option>
				<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack' ); ?></option>
				<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack' ); ?></option>
			</select>
		</label>
	</div>

	<div class="convertrack-cards" data-cvtrk="cards">
		<div class="convertrack-card"><span class="convertrack-card-value" data-cvtrk="pageviews">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Pageviews', 'convertrack' ); ?></span></div>
		<div class="convertrack-card"><span class="convertrack-card-value" data-cvtrk="clicks">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Button clicks', 'convertrack' ); ?></span></div>
		<div class="convertrack-card"><span class="convertrack-card-value" data-cvtrk="conversions">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Conversions', 'convertrack' ); ?></span></div>
		<div class="convertrack-card convertrack-card-accent"><span class="convertrack-card-value" data-cvtrk="conversion_rate">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Conversion rate', 'convertrack' ); ?></span></div>
		<div class="convertrack-card"><span class="convertrack-card-value" data-cvtrk="click_through">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Click-through rate', 'convertrack' ); ?></span></div>
		<div class="convertrack-card"><span class="convertrack-card-value" data-cvtrk="unique_visitors">–</span><span class="convertrack-card-label"><?php esc_html_e( 'Unique visitors', 'convertrack' ); ?></span></div>
	</div>

	<div class="convertrack-panel">
		<h2><?php esc_html_e( 'Activity', 'convertrack' ); ?></h2>
		<div class="convertrack-chart" data-cvtrk="chart"></div>
	</div>

	<div class="convertrack-columns">
		<div class="convertrack-panel">
			<h2><?php esc_html_e( 'Most clicked buttons', 'convertrack' ); ?></h2>
			<div class="convertrack-list" data-cvtrk="top-buttons"></div>
		</div>
		<div class="convertrack-panel">
			<h2><?php esc_html_e( 'Top converting pages', 'convertrack' ); ?></h2>
			<div class="convertrack-list" data-cvtrk="top-pages"></div>
		</div>
	</div>

	<div class="convertrack-panel">
		<h2><?php esc_html_e( 'Who is on the site right now', 'convertrack' ); ?></h2>
		<div class="convertrack-list" data-cvtrk="active-sessions"></div>
	</div>
</div>
