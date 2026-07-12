<?php
/**
 * Journeys: conversion path and drop-off reporting. Hydrated from REST.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-funnels">
	<?php Admin::render_header( 'funnels' ); ?>
	<?php Admin::render_subnav( 'analytics', 'funnels' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Journeys', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Understand the paths people take before converting and where non-converting visits end.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta"><?php esc_html_e( 'Observed paths, not ordered-step funnels', 'convertrack-click-conversion-analytics' ); ?></span>
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
		</div>
	</div>

	<div class="cvtrk-kpis">
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'sessions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-sessions">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Sessions', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi is-accent">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'conversions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-converting">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Converting sessions', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'rate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-rate">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Session conversion rate', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'award' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-conversions">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Total conversions', 'convertrack-click-conversion-analytics' ); ?></span>
			</span>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Common paths before conversion', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Last pages seen before a goal', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-paths"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Drop-off pages', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Final page in non-converting sessions', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-dropoffs"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Converting sources', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Source and campaign on conversion events', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-sources"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Buttons clicked before conversion', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Clicks in sessions before the first goal', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>
</div>
