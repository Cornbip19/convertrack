<?php
/**
 * GSC Keyword Insights admin screen.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

$s           = \Convertrack\GSC\Keywords_Settings::all();
$credentials = \Convertrack\GSC\Credentials::public_status();
$post_types  = \Convertrack\GSC\Settings::available_post_types();
$export_url  = wp_nonce_url( admin_url( 'admin-post.php?action=convertrack_gsc_keywords_export' ), 'convertrack_gsc_keywords_export' );
$notice      = isset( $_GET['cvtrk_kw_notice'] ) ? sanitize_key( wp_unslash( $_GET['cvtrk_kw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$connected   = ! empty( $credentials['connected'] );

$range_labels = array(
	'7d'  => __( 'Last 7 days', 'convertrack-click-conversion-analytics' ),
	'28d' => __( 'Last 28 days', 'convertrack-click-conversion-analytics' ),
	'3m'  => __( 'Last 3 months', 'convertrack-click-conversion-analytics' ),
	'6m'  => __( 'Last 6 months', 'convertrack-click-conversion-analytics' ),
);

$type_labels = array(
	'branded'       => __( 'Branded', 'convertrack-click-conversion-analytics' ),
	'non_branded'   => __( 'Non-branded', 'convertrack-click-conversion-analytics' ),
	'service'       => __( 'Service', 'convertrack-click-conversion-analytics' ),
	'product'       => __( 'Product', 'convertrack-click-conversion-analytics' ),
	'location'      => __( 'Location / Local SEO', 'convertrack-click-conversion-analytics' ),
	'commercial'    => __( 'Commercial intent', 'convertrack-click-conversion-analytics' ),
	'informational' => __( 'Informational', 'convertrack-click-conversion-analytics' ),
	'transactional' => __( 'Transactional', 'convertrack-click-conversion-analytics' ),
	'navigational'  => __( 'Navigational', 'convertrack-click-conversion-analytics' ),
	'question'      => __( 'Question-based', 'convertrack-click-conversion-analytics' ),
	'long_tail'     => __( 'Long-tail', 'convertrack-click-conversion-analytics' ),
	'competitor'    => __( 'Competitor-related', 'convertrack-click-conversion-analytics' ),
);
?>
<div class="wrap convertrack" id="convertrack-gsc-keywords"
	data-kw-default-range="<?php echo esc_attr( $s['default_range'] ); ?>"
	data-kw-connected="<?php echo $connected ? '1' : '0'; ?>"
	data-kw-enabled="<?php echo $s['enabled'] ? '1' : '0'; ?>">
	<?php Admin::render_header( 'keywords' ); ?>

	<?php if ( $notice ) : ?>
		<div class="cvtrk-notice">
			<?php
			switch ( $notice ) {
				case 'settings-saved':
					esc_html_e( 'Keyword Insights settings saved.', 'convertrack-click-conversion-analytics' );
					break;
				default:
					esc_html_e( 'Keyword Insights action completed.', 'convertrack-click-conversion-analytics' );
					break;
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! $connected ) : ?>
		<div class="cvtrk-notice cvtrk-notice-warning">
			<p><?php esc_html_e( 'Keyword Insights needs your Google Search Console connection. Connect Google on the Google Index Monitor tab first — the keyword data reuses the same connection and property.', 'convertrack-click-conversion-analytics' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-gsc' ) ); ?>"><?php esc_html_e( 'Open Google Index Monitor', 'convertrack-click-conversion-analytics' ); ?></a></p>
		</div>
	<?php elseif ( ! $s['enabled'] ) : ?>
		<div class="cvtrk-notice cvtrk-notice-warning">
			<p><?php esc_html_e( 'Keyword Insights is currently disabled. Enable it in the settings at the bottom of this page, save, then run your first sync.', 'convertrack-click-conversion-analytics' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="cvtrk-notice" data-cvtrk="kw-progress" hidden aria-live="polite"></div>

	<div class="cvtrk-gsc-panel" data-cvtrk="kw-summary">
		<p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p>
	</div>

	<div class="cvtrk-grid">
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Branded vs Non-branded', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Query mix in the selected range', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="kw-branded"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
		<div class="cvtrk-card">
			<div class="cvtrk-card-head">
				<h2><?php esc_html_e( 'Top Pages by Opportunity', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Where content work moves the needle most', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<div class="cvtrk-card-body">
				<div data-cvtrk="kw-top-pages"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</div>

	<div class="cvtrk-card" id="convertrack-kw-table">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Keywords', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Search Console queries mapped to your content, with presence checks and recommendations', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-toolbar cvtrk-kw-toolbar">
				<label class="cvtrk-field">
					<?php esc_html_e( 'Date range', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="kw-range">
						<?php foreach ( $range_labels as $range_key => $range_label ) : ?>
							<option value="<?php echo esc_attr( $range_key ); ?>" <?php selected( $s['default_range'], $range_key ); ?>><?php echo esc_html( $range_label ); ?></option>
						<?php endforeach; ?>
						<option value="custom"><?php esc_html_e( 'Custom range (synced on demand)', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field" data-cvtrk="kw-custom-dates" hidden>
					<?php esc_html_e( 'From / to', 'convertrack-click-conversion-analytics' ); ?>
					<input type="date" data-cvtrk="kw-date-from" />
					<input type="date" data-cvtrk="kw-date-to" />
					<button type="button" class="button" data-cvtrk="kw-custom-sync"><?php esc_html_e( 'Sync range', 'convertrack-click-conversion-analytics' ); ?></button>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Keyword type', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="kw-type">
						<option value="all"><?php esc_html_e( 'All types', 'convertrack-click-conversion-analytics' ); ?></option>
						<?php foreach ( $type_labels as $type_key => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="kw-page-filter">
						<option value="0"><?php esc_html_e( 'All pages', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Opportunity', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="kw-opportunity">
						<option value="all"><?php esc_html_e( 'All levels', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="high"><?php esc_html_e( 'High', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="medium"><?php esc_html_e( 'Medium', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="low"><?php esc_html_e( 'Low', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="optimized"><?php esc_html_e( 'Already optimized', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Presence', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="kw-presence">
						<option value="all"><?php esc_html_e( 'All statuses', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="present"><?php esc_html_e( 'Present', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="partial"><?php esc_html_e( 'Partially matched', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="missing"><?php esc_html_e( 'Missing', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="needs_improvement"><?php esc_html_e( 'Needs improvement', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="overused"><?php esc_html_e( 'Overused', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="unknown"><?php esc_html_e( 'Not analyzable', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field cvtrk-field-search">
					<?php esc_html_e( 'Search', 'convertrack-click-conversion-analytics' ); ?>
					<input type="search" data-cvtrk="kw-search" placeholder="<?php esc_attr_e( 'Keyword or page URL', 'convertrack-click-conversion-analytics' ); ?>" />
				</label>
				<button type="button" class="button button-primary" data-cvtrk="kw-sync"><?php esc_html_e( 'Sync Now', 'convertrack-click-conversion-analytics' ); ?></button>
				<a class="button" data-cvtrk="kw-export" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></a>
			</div>

			<div class="cvtrk-toolbar cvtrk-kw-bulk">
				<button type="button" class="button" data-cvtrk="kw-bulk-reanalyze"><?php esc_html_e( 'Re-analyze selected', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>

			<div data-cvtrk="kw-table"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<div class="cvtrk-pagination">
				<button type="button" class="button" data-cvtrk="kw-prev"><?php esc_html_e( 'Previous', 'convertrack-click-conversion-analytics' ); ?></button>
				<span data-cvtrk="kw-page"></span>
				<button type="button" class="button" data-cvtrk="kw-next"><?php esc_html_e( 'Next', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>

	<div class="cvtrk-card" data-cvtrk="kw-detail" hidden>
		<div class="cvtrk-card-head">
			<div>
				<h2 data-cvtrk="kw-detail-title"><?php esc_html_e( 'Page detail', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub" data-cvtrk="kw-detail-sub"></span>
			</div>
			<div class="cvtrk-card-actions">
				<button type="button" class="button" data-cvtrk="kw-detail-back"><?php esc_html_e( 'Back to all keywords', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="kw-detail-body"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Keyword Insights Settings', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Data sync, thresholds, and classification vocabulary', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convertrack_gsc_keywords_save_settings" />
				<?php wp_nonce_field( 'convertrack_gsc_keywords_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Keyword Insights', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_gsc_keywords_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Pull Search Console query data and build content recommendations.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-auto-sync"><?php esc_html_e( 'Auto-sync frequency', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<select id="cvtrk-kw-auto-sync" name="convertrack_gsc_keywords_settings[auto_sync]">
								<option value="daily" <?php selected( $s['auto_sync'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="weekly" <?php selected( $s['auto_sync'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="manual" <?php selected( $s['auto_sync'], 'manual' ); ?>><?php esc_html_e( 'Manual only', 'convertrack-click-conversion-analytics' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Search Console data lags 2-3 days behind, so syncing more than daily adds nothing.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-default-range"><?php esc_html_e( 'Default date range', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<select id="cvtrk-kw-default-range" name="convertrack_gsc_keywords_settings[default_range]">
								<?php foreach ( $range_labels as $range_key => $range_label ) : ?>
									<option value="<?php echo esc_attr( $range_key ); ?>" <?php selected( $s['default_range'], $range_key ); ?>><?php echo esc_html( $range_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ranges to keep synced', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $range_labels as $range_key => $range_label ) : ?>
								<label class="cvtrk-check">
									<input type="checkbox" name="convertrack_gsc_keywords_settings[sync_ranges][]" value="<?php echo esc_attr( $range_key ); ?>" <?php checked( in_array( $range_key, (array) $s['sync_ranges'], true ) ); ?> />
									<?php echo esc_html( $range_label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Each checked range is refreshed on every sync.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-min-impressions"><?php esc_html_e( 'Minimum impressions', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-kw-min-impressions" min="0" max="10000" name="convertrack_gsc_keywords_settings[min_impressions]" value="<?php echo esc_attr( (int) $s['min_impressions'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Queries below this impression count are not stored.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-min-position"><?php esc_html_e( 'Opportunity position threshold', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-kw-min-position" min="1" max="100" name="convertrack_gsc_keywords_settings[min_position]" value="<?php echo esc_attr( (int) $s['min_position'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Keywords ranking worse than this position are treated as improvement candidates.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-ctr"><?php esc_html_e( 'Low-CTR alert sensitivity', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-kw-ctr" min="10" max="100" name="convertrack_gsc_keywords_settings[low_ctr_sensitivity]" value="<?php echo esc_attr( (int) round( $s['low_ctr_ratio'] * 100 ) ); ?>" />%
							<p class="description"><?php esc_html_e( 'Flag a keyword when its CTR falls below this share of the CTR typical for its ranking position. Lower = stricter.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types to analyze', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $post_types as $post_type => $object ) : ?>
								<label class="cvtrk-check">
									<input type="checkbox" name="convertrack_gsc_keywords_settings[selected_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $s['selected_post_types'], true ) ); ?> />
									<?php echo esc_html( $object->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Keyword types to score', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $type_labels as $type_key => $type_label ) : ?>
								<label class="cvtrk-check">
									<input type="checkbox" name="convertrack_gsc_keywords_settings[keyword_types][]" value="<?php echo esc_attr( $type_key ); ?>" <?php checked( in_array( $type_key, (array) $s['keyword_types'], true ) ); ?> />
									<?php echo esc_html( $type_label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Leave every box unchecked to score all keyword types. Unscored keywords keep their labels and presence data but get no opportunity score or recommendations.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-brand"><?php esc_html_e( 'Brand terms', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-kw-brand" class="large-text code" rows="3" name="convertrack_gsc_keywords_settings[brand_terms]"><?php echo esc_textarea( implode( "\n", (array) $s['brand_terms'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Your site name and domain are always treated as brand terms automatically.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-locations"><?php esc_html_e( 'Location terms', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-kw-locations" class="large-text code" rows="3" name="convertrack_gsc_keywords_settings[location_terms]"><?php echo esc_textarea( implode( "\n", (array) $s['location_terms'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Cities and areas you serve, one per line. No built-in city list exists — phrases like "near me" are detected automatically, everything else comes from here.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-services"><?php esc_html_e( 'Service terms', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-kw-services" class="large-text code" rows="3" name="convertrack_gsc_keywords_settings[service_terms]"><?php echo esc_textarea( implode( "\n", (array) $s['service_terms'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Published page titles are used automatically; add extra service phrases here.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-products"><?php esc_html_e( 'Product terms', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-kw-products" class="large-text code" rows="3" name="convertrack_gsc_keywords_settings[product_terms]"><?php echo esc_textarea( implode( "\n", (array) $s['product_terms'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. WooCommerce product titles and categories are used automatically when present.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-competitors"><?php esc_html_e( 'Competitor names', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-kw-competitors" class="large-text code" rows="3" name="convertrack_gsc_keywords_settings[competitor_terms]"><?php echo esc_textarea( implode( "\n", (array) $s['competitor_terms'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Competitor keywords are only detected from this list.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-row-cap"><?php esc_html_e( 'Keywords per range', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-kw-row-cap" min="100" max="25000" name="convertrack_gsc_keywords_settings[row_cap]" value="<?php echo esc_attr( (int) $s['row_cap'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Cap on stored keywords per date range. Google returns rows by clicks, so the cap keeps the most valuable queries.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-kw-country"><?php esc_html_e( 'Country filter', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="text" id="cvtrk-kw-country" maxlength="3" size="4" name="convertrack_gsc_keywords_settings[country_filter]" value="<?php echo esc_attr( $s['country_filter'] ); ?>" placeholder="usa" />
							<p class="description"><?php esc_html_e( 'Optional 3-letter country code (ISO-3166-1 alpha-3, e.g. usa, esp, gbr). Leave empty for worldwide data.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Device breakdown', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_gsc_keywords_settings[track_devices]" value="1" <?php checked( $s['track_devices'], 1 ); ?> />
								<?php esc_html_e( 'Store one row per device type (desktop / mobile / tablet). Multiplies stored rows.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Keyword Insights Settings', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>

			<div class="cvtrk-toolbar cvtrk-kw-tools">
				<button type="button" class="button" data-cvtrk="kw-sync-now"><?php esc_html_e( 'Sync keywords now', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="button" data-cvtrk="kw-reanalyze-all"><?php esc_html_e( 'Re-analyze all content', 'convertrack-click-conversion-analytics' ); ?></button>
				<span class="description" data-cvtrk="kw-tools-status" hidden></span>
			</div>
		</div>
	</div>
</div>
