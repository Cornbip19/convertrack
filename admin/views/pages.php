<?php
/**
 * Pages & Buttons drill-down. Hydrated client-side from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-pages">
	<?php Admin::render_header( 'pages' ); ?>

	<div class="cvtrk-toolbar">
		<label class="cvtrk-field">
			<?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?>
			<select id="convertrack-range" data-cvtrk="range">
				<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<label class="cvtrk-field">
			<?php esc_html_e( 'Filter by page', 'convertrack-click-conversion-analytics' ); ?>
			<select id="convertrack-post" data-cvtrk="post">
				<option value="0"><?php esc_html_e( 'All pages', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<span class="cvtrk-field"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></span>
		<a class="button" data-cvtrk-export data-type="buttons"><?php esc_html_e( 'Buttons', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="button" data-cvtrk-export data-type="pages"><?php esc_html_e( 'Pages', 'convertrack-click-conversion-analytics' ); ?></a>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2 data-cvtrk="buttons-title"><?php esc_html_e( 'Buttons clicked', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Every tracked button & link, ranked by clicks', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="top-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Pages ranked by clicks', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Select a page to see only its buttons', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="top-pages"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading…', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>
</div>
