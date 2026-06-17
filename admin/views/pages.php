<?php
/**
 * Pages & Buttons drill-down. Hydrated client-side from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack-wrap" id="convertrack-pages">
	<h1 class="convertrack-title">
		<span class="dashicons dashicons-list-view"></span>
		<?php esc_html_e( 'Pages & Buttons', 'convertrack' ); ?>
	</h1>

	<div class="convertrack-toolbar">
		<label class="convertrack-range">
			<?php esc_html_e( 'Range', 'convertrack' ); ?>
			<select id="convertrack-range" data-cvtrk="range">
				<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack' ); ?></option>
				<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack' ); ?></option>
				<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack' ); ?></option>
			</select>
		</label>

		<label class="convertrack-range">
			<?php esc_html_e( 'Page', 'convertrack' ); ?>
			<select id="convertrack-post" data-cvtrk="post">
				<option value="0"><?php esc_html_e( 'All pages', 'convertrack' ); ?></option>
			</select>
		</label>
	</div>

	<div class="convertrack-columns">
		<div class="convertrack-panel">
			<h2><?php esc_html_e( 'Pages ranked by clicks', 'convertrack' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Click a page to see which buttons its visitors click.', 'convertrack' ); ?></p>
			<div class="convertrack-list" data-cvtrk="top-pages"></div>
		</div>
		<div class="convertrack-panel">
			<h2 data-cvtrk="buttons-title"><?php esc_html_e( 'Buttons clicked', 'convertrack' ); ?></h2>
			<div class="convertrack-list" data-cvtrk="top-buttons"></div>
		</div>
	</div>
</div>
