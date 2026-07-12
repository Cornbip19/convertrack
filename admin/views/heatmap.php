<?php
/**
 * Heatmaps: per-page click density and scroll depth. Hydrated from REST.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-heatmaps">
	<?php Admin::render_header( 'heatmaps' ); ?>
	<?php Admin::render_subnav( 'analytics', 'heatmaps' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Heatmaps', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'See where visitors click and how far they scroll on each tracked page.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta" data-cvtrk="heatmap-meta"><?php esc_html_e( 'Select a page to view its sample.', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-page-head-actions">
			<label class="cvtrk-field">
				<span><?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?></span>
				<select id="convertrack-range" data-cvtrk="range">
					<option value="7" selected><?php esc_html_e( 'Last 7 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'convertrack-click-conversion-analytics' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'convertrack-click-conversion-analytics' ); ?></option>
				</select>
			</label>
		</div>
	</div>

	<div class="cvtrk-toolbar cvtrk-heatmap-toolbar">
		<label class="cvtrk-field cvtrk-mini-field-search">
			<span><?php esc_html_e( 'Find a page', 'convertrack-click-conversion-analytics' ); ?></span>
			<input type="search" data-cvtrk="heatmap-page-search" placeholder="<?php esc_attr_e( 'Search page titles', 'convertrack-click-conversion-analytics' ); ?>" />
		</label>
		<label class="cvtrk-field">
			<span><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></span>
			<select id="convertrack-post" data-cvtrk="post">
				<option value="0"><?php esc_html_e( 'Select a page...', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</label>

		<div class="cvtrk-field cvtrk-device-field">
			<span><?php esc_html_e( 'Device', 'convertrack-click-conversion-analytics' ); ?></span>
			<div class="cvtrk-segmented" data-cvtrk="device-toggle" role="group" aria-label="<?php esc_attr_e( 'Device', 'convertrack-click-conversion-analytics' ); ?>">
				<button type="button" class="cvtrk-segment is-active" data-cvtrk-device="desktop" aria-pressed="true"><?php echo Admin::icon( 'desktop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Desktop', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="cvtrk-segment" data-cvtrk-device="tablet" aria-pressed="false"><?php echo Admin::icon( 'tablet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Tablet', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="cvtrk-segment" data-cvtrk-device="mobile" aria-pressed="false"><?php echo Admin::icon( 'mobile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Mobile', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
			<select data-cvtrk="device" class="cvtrk-device-select">
				<option value="desktop" selected><?php esc_html_e( 'Desktop', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="tablet"><?php esc_html_e( 'Tablet', 'convertrack-click-conversion-analytics' ); ?></option>
				<option value="mobile"><?php esc_html_e( 'Mobile', 'convertrack-click-conversion-analytics' ); ?></option>
			</select>
		</div>

		<details class="cvtrk-action-menu cvtrk-heatmap-advanced">
			<summary class="button"><?php esc_html_e( 'Advanced', 'convertrack-click-conversion-analytics' ); ?></summary>
			<div class="cvtrk-action-menu-panel">
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Coordinate mode', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="heatmap-mode">
						<option value="element" selected><?php esc_html_e( 'Element anchored', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="page"><?php esc_html_e( 'Page position', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-checkbox-field">
					<input type="checkbox" data-cvtrk="show-page" checked />
					<span><?php esc_html_e( 'Show page preview', 'convertrack-click-conversion-analytics' ); ?></span>
				</label>
			</div>
		</details>
	</div>

	<div class="cvtrk-heatmap-workspace">
		<div class="cvtrk-card cvtrk-heatmap-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Click map', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Click concentration over the current page preview', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
				<span class="cvtrk-confidence" data-cvtrk="heatmap-confidence" aria-live="polite"></span>
			</div>
			<div class="cvtrk-card-body">
				<div class="cvtrk-heatmap-stage" data-cvtrk="heatmap-stage">
					<div class="cvtrk-heatmap-page" data-cvtrk="heatmap-page">
						<iframe class="cvtrk-heatmap-frame" data-cvtrk="heatmap-frame" title="<?php esc_attr_e( 'Page preview', 'convertrack-click-conversion-analytics' ); ?>" referrerpolicy="no-referrer" sandbox="allow-same-origin" tabindex="-1" aria-hidden="true"></iframe>
						<canvas class="cvtrk-heatmap-canvas" data-cvtrk="heatmap-canvas" role="img" aria-label="<?php esc_attr_e( 'Heatmap showing click density on the selected page.', 'convertrack-click-conversion-analytics' ); ?>"></canvas>
						<div class="cvtrk-heatmap-markers" data-cvtrk="heatmap-markers" aria-hidden="true"></div>
					</div>
					<div class="cvtrk-empty-state cvtrk-heatmap-blocked" data-cvtrk="heatmap-frame-blocked" hidden>
						<strong><?php esc_html_e( 'Page preview unavailable', 'convertrack-click-conversion-analytics' ); ?></strong>
						<p><?php esc_html_e( 'This page blocks iframe previews. The click markers and detail panels are still available.', 'convertrack-click-conversion-analytics' ); ?></p>
					</div>
				</div>
				<p class="cvtrk-note" data-cvtrk="heatmap-note" aria-live="polite"></p>
			</div>
		</div>

		<aside class="cvtrk-heatmap-detail-panel" aria-label="<?php esc_attr_e( 'Heatmap details', 'convertrack-click-conversion-analytics' ); ?>">
			<div class="cvtrk-card">
				<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Clicked elements', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'What visitors clicked on this page', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
				<div class="cvtrk-card-body"><div data-cvtrk="heatmap-elements"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
			</div>

			<div class="cvtrk-card">
				<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Scroll depth', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'How far down visitors browse', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
				<div class="cvtrk-card-body"><div data-cvtrk="scroll-depth"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
			</div>

			<div class="cvtrk-card">
				<div class="cvtrk-card-head"><div><h2><?php esc_html_e( 'Search keywords', 'convertrack-click-conversion-analytics' ); ?></h2><span class="cvtrk-card-sub"><?php esc_html_e( 'Terms tied to this page', 'convertrack-click-conversion-analytics' ); ?></span></div></div>
				<div class="cvtrk-card-body"><div data-cvtrk="heatmap-keywords"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div></div>
			</div>
		</aside>
	</div>
</div>
