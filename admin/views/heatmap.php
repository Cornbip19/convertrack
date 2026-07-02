<?php
/**
 * Heatmaps: per-page click density + scroll depth. Hydrated from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-heatmaps">
	<?php Admin::render_header( 'heatmaps' ); ?>

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
			<?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?>
			<select id="convertrack-post" data-cvtrk="post">
				<option value="0"><?php esc_html_e( 'Select a page...', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<div class="cvtrk-field cvtrk-device-field">
			<span><?php esc_html_e( 'Device', 'convertrack-click-conversion-analytics' ); ?></span>
			<div class="cvtrk-segmented" data-cvtrk="device-toggle" role="group" aria-label="<?php esc_attr_e( 'Device', 'convertrack-click-conversion-analytics' ); ?>">
				<button type="button" class="cvtrk-segment is-active" data-cvtrk-device="desktop"><?php echo Admin::icon( 'desktop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Desktop', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="cvtrk-segment" data-cvtrk-device="tablet"><?php echo Admin::icon( 'tablet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Tablet', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="cvtrk-segment" data-cvtrk-device="mobile"><?php echo Admin::icon( 'mobile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Mobile', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
			<select data-cvtrk="device" class="cvtrk-device-select">
				<option value="desktop" selected><?php esc_html_e( 'Desktop', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="tablet"><?php esc_html_e( 'Tablet', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="mobile"><?php esc_html_e( 'Mobile', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</div>

		<label class="cvtrk-field">
			<?php esc_html_e( 'Coordinates', 'convertrack-click-conversion-analytics' ); ?>
			<select data-cvtrk="heatmap-mode">
				<option value="element" selected><?php esc_html_e( 'Element anchored', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="page"><?php esc_html_e( 'Page position', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<label class="cvtrk-field">
			<input type="checkbox" data-cvtrk="show-page" checked />
			<?php esc_html_e( 'Show page behind heatmap', 'convertrack-click-conversion-analytics' ); ?>
		</label>
	</div>

	<div class="cvtrk-card cvtrk-heatmap-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Click map', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub" data-cvtrk="heatmap-meta"></span>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-heatmap-stage" data-cvtrk="heatmap-stage">
				<div class="cvtrk-heatmap-page" data-cvtrk="heatmap-page">
					<iframe class="cvtrk-heatmap-frame" data-cvtrk="heatmap-frame" title="<?php esc_attr_e( 'Page preview', 'convertrack-click-conversion-analytics' ); ?>" referrerpolicy="no-referrer" sandbox="allow-same-origin"></iframe>
					<canvas class="cvtrk-heatmap-canvas" data-cvtrk="heatmap-canvas"></canvas>
					<div class="cvtrk-heatmap-markers" data-cvtrk="heatmap-markers" aria-hidden="true"></div>
				</div>
			</div>
			<p class="cvtrk-note" data-cvtrk="heatmap-note"></p>
		</div>
	</div>

	<div class="cvtrk-grid cvtrk-heatmap-details">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Clicked elements', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'What visitors clicked on this page', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="heatmap-elements"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Search keywords', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Terms tied to this page', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="heatmap-keywords"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Scroll depth', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'How far down visitors browse', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="scroll-depth"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>
</div>
