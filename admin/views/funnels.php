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
			<p class="cvtrk-page-desc"><?php esc_html_e( 'What visitors did before they took a valuable action, and where the others gave up.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta"><?php esc_html_e( 'Based on the pages visitors actually opened, in the order they opened them', 'convertrack-click-conversion-analytics' ); ?></span>
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

	<?php
	Admin::render_guide(
		'journeys',
		array(
			'lead'   => __( 'A conversion is a valuable action: a call, a booking, a message, a form sent, or a thank-you page reached. This screen shows what happened in the visits that ended with one, and in the visits that did not.', 'convertrack-click-conversion-analytics' ),
			'points' => array(
				array( __( 'Session', 'convertrack-click-conversion-analytics' ), __( 'One visit to your site. Someone who comes back tomorrow starts a new session.', 'convertrack-click-conversion-analytics' ) ),
				array( __( 'Converting session', 'convertrack-click-conversion-analytics' ), __( 'A visit in which at least one valuable action happened.', 'convertrack-click-conversion-analytics' ) ),
				array( __( 'Drop-off page', 'convertrack-click-conversion-analytics' ), __( 'The last page someone saw before leaving without doing anything valuable.', 'convertrack-click-conversion-analytics' ) ),
			),
			'tip'    => __( 'Pages high on the drop-off list deserve a closer look: a clearer next step there can turn more of those visits into customers.', 'convertrack-click-conversion-analytics' ),
		)
	);
	?>

	<div class="cvtrk-kpis">
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'sessions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-sessions">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Visits (sessions)', 'convertrack-click-conversion-analytics' ); ?><?php echo Admin::help( 'sessions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi is-accent">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'conversions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-converting">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Visits that converted', 'convertrack-click-conversion-analytics' ); ?><?php echo Admin::help( 'convertingSessions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'rate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-rate">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Conversion rate', 'convertrack-click-conversion-analytics' ); ?><?php echo Admin::help( 'conversionRate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</span>
		</div>
		<div class="cvtrk-kpi">
			<span class="cvtrk-kpi-icon"><?php echo Admin::icon( 'award' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="cvtrk-kpi-body">
				<span class="cvtrk-kpi-value" data-cvtrk="funnel-conversions">-</span>
				<span class="cvtrk-kpi-label"><?php esc_html_e( 'Conversions', 'convertrack-click-conversion-analytics' ); ?><?php echo Admin::help( 'conversions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</span>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Paths that led to a conversion', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'The pages visitors opened, in order, right before the valuable action', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-paths"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Where visitors left without converting', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'The last page seen in visits that did not convert', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-dropoffs"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Where converting visitors came from', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'The source of the visit, and how many conversions it produced', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-sources"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>

		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<div>
					<h2><?php esc_html_e( 'Buttons pressed before converting', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Clicks made during visits that went on to convert', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="funnel-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>
</div>
