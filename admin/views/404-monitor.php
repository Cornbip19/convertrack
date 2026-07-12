<?php
/**
 * 404 Monitor admin screen.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

$s             = \Convertrack\NotFound\Settings::all();
$post_types    = \Convertrack\NotFound\Settings::available_post_types();
$taxonomies    = \Convertrack\NotFound\Settings::available_taxonomies();
$compatibility = \Convertrack\NotFound\Compatibility::status();
$export_url    = wp_nonce_url( admin_url( 'admin-post.php?action=convertrack_404_export' ), 'convertrack_404_export' );
$notice        = isset( $_GET['cvtrk_404_notice'] ) ? sanitize_key( wp_unslash( $_GET['cvtrk_404_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap convertrack" id="convertrack-404-monitor" data-404-threshold="<?php echo esc_attr( (int) $s['auto_min_confidence'] ); ?>">
	<?php Admin::render_header( '404' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Broken URLs', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Broken URLs your visitors hit, with matched redirect suggestions and a safe approval workflow.', 'convertrack-click-conversion-analytics' ); ?></p>
		</div>
		<div class="cvtrk-page-head-actions">
			<span class="cvtrk-page-head-meta">
				<?php echo Admin::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Last scan:', 'convertrack-click-conversion-analytics' ); ?> <b data-cvtrk="404-last-scan">&mdash;</b></span>
			</span>
			<a class="button" data-cvtrk="404-export" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></a>
			<button type="button" class="button" data-cvtrk="404-refresh"><?php esc_html_e( 'Refresh URLs', 'convertrack-click-conversion-analytics' ); ?></button>
			<button type="button" class="button button-primary" data-cvtrk="404-process"><?php esc_html_e( 'Run Match', 'convertrack-click-conversion-analytics' ); ?></button>
		</div>
	</div>

	<?php if ( $notice ) : ?>
		<div class="cvtrk-notice cvtrk-notice-success">
			<?php
			switch ( $notice ) {
				case 'settings-saved':
					esc_html_e( '404 Monitor settings saved.', 'convertrack-click-conversion-analytics' );
					break;
				default:
					esc_html_e( '404 Monitor action completed.', 'convertrack-click-conversion-analytics' );
					break;
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $compatibility['tools'] ) || ! empty( $compatibility['htaccess_hint'] ) ) : ?>
		<div class="cvtrk-notice cvtrk-notice-warning">
			<?php if ( ! empty( $compatibility['tools'] ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: redirect tool names. */
							__( 'Detected redirect tools: %s. Convertrack keeps those integrations read-only and blocks duplicate internal redirects for matching sources.', 'convertrack-click-conversion-analytics' ),
							implode( ', ', wp_list_pluck( $compatibility['tools'], 'label' ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $compatibility['htaccess_hint'] ) ) : ?>
				<p><?php echo esc_html( $compatibility['htaccess_hint'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="cvtrk-notice cvtrk-progress-notice" data-cvtrk="404-progress" hidden aria-live="polite"></div>

	<div class="cvtrk-404-panel" data-cvtrk="404-summary">
		<p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p>
	</div>

	<nav class="cvtrk-subview-nav" aria-label="<?php esc_attr_e( 'Broken URL views', 'convertrack-click-conversion-analytics' ); ?>">
		<a class="cvtrk-subview-link is-active" href="#convertrack-404-events" data-cvtrk-404-view="detected" aria-current="page"><?php esc_html_e( 'Detected', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="cvtrk-subview-link" href="#convertrack-404-redirects" data-cvtrk-404-view="redirects"><?php esc_html_e( 'Redirects', 'convertrack-click-conversion-analytics' ); ?></a>
		<a class="cvtrk-subview-link" href="#convertrack-404-activity" data-cvtrk-404-view="activity"><?php esc_html_e( 'Activity', 'convertrack-click-conversion-analytics' ); ?></a>
	</nav>

	<div class="cvtrk-card cvtrk-404-view-panel is-active" id="convertrack-404-events" data-cvtrk-404-panel="detected">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( 'Detected 404s', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Recommendations, approval workflow, and bulk review', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-filters" role="group" aria-label="<?php esc_attr_e( '404 filters', 'convertrack-click-conversion-analytics' ); ?>">
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="404-status">
						<option value="all"><?php esc_html_e( 'All active', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="new"><?php esc_html_e( 'New', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="recommended"><?php esc_html_e( 'Recommended', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="manual_review"><?php esc_html_e( 'Manual review', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="approved"><?php esc_html_e( 'Approved', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="auto_redirected"><?php esc_html_e( 'Auto redirected', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="ignored"><?php esc_html_e( 'Ignored', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Post type', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="404-post-type">
						<option value="all"><?php esc_html_e( 'All post types', 'convertrack-click-conversion-analytics' ); ?></option>
						<?php foreach ( $post_types as $post_type => $object ) : ?>
							<option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $object->labels->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Confidence %', 'convertrack-click-conversion-analytics' ); ?></span>
					<div class="cvtrk-field-pair">
						<input type="number" min="0" max="100" data-cvtrk="404-confidence-min" placeholder="<?php esc_attr_e( 'Min', 'convertrack-click-conversion-analytics' ); ?>" aria-label="<?php esc_attr_e( 'Minimum confidence', 'convertrack-click-conversion-analytics' ); ?>" />
						<span><?php esc_html_e( 'to', 'convertrack-click-conversion-analytics' ); ?></span>
						<input type="number" min="0" max="100" data-cvtrk="404-confidence-max" placeholder="<?php esc_attr_e( 'Max', 'convertrack-click-conversion-analytics' ); ?>" aria-label="<?php esc_attr_e( 'Maximum confidence', 'convertrack-click-conversion-analytics' ); ?>" />
					</div>
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Detected from', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="date" data-cvtrk="404-detected-from" />
				</label>
				<label class="cvtrk-mini-field">
					<span><?php esc_html_e( 'Detected to', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="date" data-cvtrk="404-detected-to" />
				</label>
				<label class="cvtrk-mini-field cvtrk-mini-field-search cvtrk-filter-wide">
					<span><?php esc_html_e( 'Search', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="search" data-cvtrk="404-search" placeholder="<?php esc_attr_e( 'URL or referrer', 'convertrack-click-conversion-analytics' ); ?>" />
				</label>
			</div>

			<div class="cvtrk-bulk-bar">
				<label class="cvtrk-mini-field">
					<span class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'convertrack-click-conversion-analytics' ); ?></span>
					<select data-cvtrk="404-bulk-action" aria-label="<?php esc_attr_e( 'Bulk action', 'convertrack-click-conversion-analytics' ); ?>">
						<option value=""><?php esc_html_e( 'Bulk actions', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="approve"><?php esc_html_e( 'Approve selected', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="approve_high_confidence"><?php esc_html_e( 'Approve high-confidence recommendations', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="ignore"><?php esc_html_e( 'Ignore selected', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete selected', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<button type="button" class="button" data-cvtrk="404-bulk-run"><?php esc_html_e( 'Apply', 'convertrack-click-conversion-analytics' ); ?></button>
				<span class="cvtrk-bulk-spacer"></span>
				<span class="cvtrk-selection-count" data-cvtrk="404-selection" aria-live="polite"><?php esc_html_e( 'No rows selected', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>

			<div data-cvtrk="404-events" aria-busy="false"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<div class="cvtrk-pagination">
				<button type="button" class="button" data-cvtrk="404-prev"><?php esc_html_e( 'Previous', 'convertrack-click-conversion-analytics' ); ?></button>
				<span data-cvtrk="404-page" aria-live="polite"></span>
				<button type="button" class="button" data-cvtrk="404-next"><?php esc_html_e( 'Next', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>

	<div class="cvtrk-card cvtrk-404-view-panel" id="convertrack-404-redirects" data-cvtrk-404-panel="redirects">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( 'Redirect Visibility', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Internal 301 redirects and safely readable third-party rows', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="404-redirects" aria-busy="false"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<div class="cvtrk-card cvtrk-404-view-panel" id="convertrack-404-activity" data-cvtrk-404-panel="activity">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( '404 Monitor Activity Log', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Recent scans, recommendations, redirects, validation failures, and cleanup events', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="404-logs" aria-busy="false"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>

	<details class="cvtrk-advanced-disclosure cvtrk-module-settings" id="convertrack-404-settings">
		<summary>
			<strong><?php esc_html_e( 'Broken URL settings', 'convertrack-click-conversion-analytics' ); ?></strong>
			<span><?php esc_html_e( 'Monitoring mode, matching, exclusions, retention, and alerts', 'convertrack-click-conversion-analytics' ); ?></span>
		</summary>
	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<div>
				<h2><?php esc_html_e( '404 Monitor Settings', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Conservative by default: monitor and recommend before redirecting', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convertrack_404_save_settings" />
				<?php wp_nonce_field( 'convertrack_404_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable monitor', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_404_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Capture frontend 404 requests and build redirect recommendations.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-mode"><?php esc_html_e( 'Mode', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<select id="cvtrk-404-mode" name="convertrack_404_settings[mode]">
								<option value="monitor" <?php selected( $s['mode'], 'monitor' ); ?>><?php esc_html_e( 'Monitor only', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="recommend" <?php selected( $s['mode'], 'recommend' ); ?>><?php esc_html_e( 'Recommend only', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="manual" <?php selected( $s['mode'], 'manual' ); ?>><?php esc_html_e( 'Manual approval required', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="auto_high_confidence" <?php selected( $s['mode'], 'auto_high_confidence' ); ?>><?php esc_html_e( 'Auto redirect high-confidence matches', 'convertrack-click-conversion-analytics' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Internal 301 redirects are created only in auto mode or when an administrator approves a row.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-threshold"><?php esc_html_e( 'Auto threshold', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="number" id="cvtrk-404-threshold" min="50" max="100" name="convertrack_404_settings[auto_min_confidence]" value="<?php echo esc_attr( (int) $s['auto_min_confidence'] ); ?>" />%</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-fallback"><?php esc_html_e( 'Fallback destination', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="url" id="cvtrk-404-fallback" class="regular-text" name="convertrack_404_settings[fallback_url]" value="<?php echo esc_attr( $s['fallback_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Used only as a low-confidence suggestion unless manually approved.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-refresh"><?php esc_html_e( 'Scan frequency', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<select id="cvtrk-404-refresh" name="convertrack_404_settings[scan_frequency]">
								<option value="hourly" <?php selected( $s['scan_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="twicedaily" <?php selected( $s['scan_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="daily" <?php selected( $s['scan_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'convertrack-click-conversion-analytics' ); ?></option>
							</select>
							<input type="number" min="1" max="168" name="convertrack_404_settings[sitemap_refresh_hours]" value="<?php echo esc_attr( (int) $s['sitemap_refresh_hours'] ); ?>" aria-label="<?php esc_attr_e( 'Minimum hours between sitemap refreshes', 'convertrack-click-conversion-analytics' ); ?>" />
							<span class="description"><?php esc_html_e( 'minimum hours between sitemap refreshes', 'convertrack-click-conversion-analytics' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-retention"><?php esc_html_e( 'Retention', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="number" id="cvtrk-404-retention" min="1" max="3650" name="convertrack_404_settings[retention_days]" value="<?php echo esc_attr( (int) $s['retention_days'] ); ?>" /> <?php esc_html_e( 'days', 'convertrack-click-conversion-analytics' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-query"><?php esc_html_e( 'Ignored query params', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><textarea id="cvtrk-404-query" class="large-text code" rows="4" name="convertrack_404_settings[ignore_query_params]"><?php echo esc_textarea( $s['ignore_query_params'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-patterns"><?php esc_html_e( 'Ignored URL patterns', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><textarea id="cvtrk-404-patterns" class="large-text code" rows="4" name="convertrack_404_settings[ignore_patterns]"><?php echo esc_textarea( $s['ignore_patterns'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-404-sitemaps"><?php esc_html_e( 'Custom sitemap URLs', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><textarea id="cvtrk-404-sitemaps" class="large-text code" rows="3" name="convertrack_404_settings[sitemap_urls]"><?php echo esc_textarea( $s['sitemap_urls'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded post types', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $post_types as $post_type => $object ) : ?>
								<label class="cvtrk-inline-option">
									<input type="checkbox" name="convertrack_404_settings[exclude_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $s['exclude_post_types'], true ) ); ?> />
									<?php echo esc_html( $object->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded taxonomies', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $taxonomies as $taxonomy => $object ) : ?>
								<label class="cvtrk-inline-option">
									<input type="checkbox" name="convertrack_404_settings[exclude_taxonomies][]" value="<?php echo esc_attr( $taxonomy ); ?>" <?php checked( in_array( $taxonomy, (array) $s['exclude_taxonomies'], true ) ); ?> />
									<?php echo esc_html( $object->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Spike email alerts', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_404_settings[email_notifications]" value="1" <?php checked( $s['email_notifications'], 1 ); ?> />
								<?php esc_html_e( 'Email the site admin when recent 404 hits exceed', 'convertrack-click-conversion-analytics' ); ?>
							</label>
							<input type="number" min="5" max="10000" name="convertrack_404_settings[spike_threshold]" value="<?php echo esc_attr( (int) $s['spike_threshold'] ); ?>" aria-label="<?php esc_attr_e( 'Spike hit threshold', 'convertrack-click-conversion-analytics' ); ?>" />
							<?php esc_html_e( 'hits in', 'convertrack-click-conversion-analytics' ); ?>
							<input type="number" min="5" max="1440" name="convertrack_404_settings[spike_window_minutes]" value="<?php echo esc_attr( (int) $s['spike_window_minutes'] ); ?>" aria-label="<?php esc_attr_e( 'Spike window in minutes', 'convertrack-click-conversion-analytics' ); ?>" />
							<?php esc_html_e( 'minutes.', 'convertrack-click-conversion-analytics' ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save 404 Monitor Settings', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>
		</div>
	</div>
	</details>

	<div class="cvtrk-dialog-backdrop" data-cvtrk="404-edit-dialog" hidden>
		<div class="cvtrk-dialog" role="dialog" aria-modal="true" aria-labelledby="convertrack-404-edit-title" aria-describedby="convertrack-404-edit-description" tabindex="-1">
			<div class="cvtrk-dialog-head">
				<h2 id="convertrack-404-edit-title"><?php esc_html_e( 'Edit redirect', 'convertrack-click-conversion-analytics' ); ?></h2>
				<button type="button" class="cvtrk-dialog-close" data-cvtrk="404-edit-cancel" aria-label="<?php esc_attr_e( 'Close edit redirect dialog', 'convertrack-click-conversion-analytics' ); ?>">&times;</button>
			</div>
			<div class="cvtrk-dialog-body">
				<p id="convertrack-404-edit-description"><?php esc_html_e( 'Choose the destination for this broken URL. Convertrack validates it before saving.', 'convertrack-click-conversion-analytics' ); ?></p>
				<input type="hidden" data-cvtrk="404-edit-id" value="" />
				<label class="cvtrk-field" for="convertrack-404-edit-destination">
					<span><?php esc_html_e( 'Destination URL', 'convertrack-click-conversion-analytics' ); ?></span>
					<input type="text" inputmode="url" autocomplete="url" spellcheck="false" id="convertrack-404-edit-destination" class="large-text" data-cvtrk="404-edit-destination" value="" aria-describedby="convertrack-404-edit-destination-hint" required />
				</label>
				<p class="description" id="convertrack-404-edit-destination-hint"><?php esc_html_e( 'Enter a full same-site URL or a root-relative path such as /new-page/.', 'convertrack-click-conversion-analytics' ); ?></p>
				<p class="cvtrk-field-error" data-cvtrk="404-edit-error" hidden aria-live="polite"></p>
			</div>
			<div class="cvtrk-dialog-actions">
				<button type="button" class="button" data-cvtrk="404-edit-cancel"><?php esc_html_e( 'Cancel', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="button button-primary" data-cvtrk="404-edit-save"><?php esc_html_e( 'Save redirect', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>
</div>
