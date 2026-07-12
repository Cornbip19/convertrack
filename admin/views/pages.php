<?php
/**
 * Content and CTA drill-down. Hydrated client-side from the REST API.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap convertrack" id="convertrack-pages">
	<?php Admin::render_header( 'pages' ); ?>
	<?php Admin::render_subnav( 'analytics', 'pages' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Content & CTAs', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Compare every tracked page, then open its calls to action for a focused performance review.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta" data-cvtrk="pages-result-count"><?php esc_html_e( 'Loading tracked pages...', 'convertrack-click-conversion-analytics' ); ?></span>
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
			<details class="cvtrk-action-menu cvtrk-export-menu">
				<summary class="button"><?php esc_html_e( 'Export', 'convertrack-click-conversion-analytics' ); ?></summary>
				<div class="cvtrk-action-menu-panel" role="group" aria-label="<?php esc_attr_e( 'Export content analytics', 'convertrack-click-conversion-analytics' ); ?>">
					<a class="button" data-cvtrk-export data-type="pages"><?php esc_html_e( 'Pages', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" data-cvtrk-export data-type="buttons"><?php esc_html_e( 'Buttons', 'convertrack-click-conversion-analytics' ); ?></a>
				</div>
			</details>
		</div>
	</div>

	<div class="cvtrk-card" id="convertrack-content-table">
		<div class="cvtrk-card-head cvtrk-card-head-controls">
			<div>
				<h2><?php esc_html_e( 'Tracked content', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Search, sort, and open a page to inspect its buttons and links.', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-actions cvtrk-pages-controls" role="group" aria-label="<?php esc_attr_e( 'Content table controls', 'convertrack-click-conversion-analytics' ); ?>">
				<label class="cvtrk-mini-field cvtrk-mini-field-search">
					<span><?php esc_html_e( 'Search', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="search" data-cvtrk="pages-search" data-cvtrk-pages-search placeholder="<?php esc_attr_e( 'Page title or URL', 'convertrack-click-conversion-analytics' ); ?>" />
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Sort by', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="pages-orderby" data-cvtrk-pages-orderby>
						<option value="clicks"><?php esc_html_e( 'Clicks', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pageviews" selected><?php esc_html_e( 'Pageviews', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="conversions"><?php esc_html_e( 'Conversions', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="title"><?php esc_html_e( 'Page title', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Direction', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="pages-order" data-cvtrk-pages-order>
						<option value="desc"><?php esc_html_e( 'Highest first', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="asc"><?php esc_html_e( 'Lowest first', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Rows', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="pages-per-page" data-cvtrk-pages-per-page>
						<option value="25" selected>25</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
				</label>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="top-pages" data-cvtrk-pages-list aria-busy="true"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<div class="cvtrk-pagination" aria-label="<?php esc_attr_e( 'Content pages', 'convertrack-click-conversion-analytics' ); ?>">
				<button type="button" class="button" data-cvtrk="pages-prev" data-cvtrk-pages-prev><?php esc_html_e( 'Previous', 'convertrack-click-conversion-analytics' ); ?></button>
				<span data-cvtrk="pages-page" data-cvtrk-pages-page aria-live="polite"></span>
				<button type="button" class="button" data-cvtrk="pages-next" data-cvtrk-pages-next><?php esc_html_e( 'Next', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>

	<div class="cvtrk-card" id="convertrack-page-detail" data-cvtrk-pages-detail>
		<div class="cvtrk-card-head cvtrk-card-head-controls">
			<div>
				<h2 data-cvtrk="buttons-title"><?php esc_html_e( 'Page details', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Buttons and tracked links for the selected page', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<label class="cvtrk-mini-field">
				<span><?php esc_html_e( 'Selected page', 'convertrack-click-conversion-analytics' ); ?></span>
				<select id="convertrack-post" data-cvtrk="post">
					<option value="0"><?php esc_html_e( 'Choose a page', 'convertrack-click-conversion-analytics' ); ?></option>
				</select>
			</label>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="top-buttons"><p class="cvtrk-skeleton"><?php esc_html_e( 'Select View details for a page to inspect its calls to action.', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>
</div>
