<?php
/**
 * Google Search Console Index Monitor admin screen.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

$s            = \Convertrack\GSC\Settings::all();
$credentials  = \Convertrack\GSC\Credentials::public_status();
$post_types   = \Convertrack\GSC\Settings::available_post_types();
$export_url   = wp_nonce_url( admin_url( 'admin-post.php?action=convertrack_gsc_export' ), 'convertrack_gsc_export' );
$oauth_url    = wp_nonce_url( admin_url( 'admin-post.php?action=convertrack_gsc_oauth_start' ), 'convertrack_gsc_oauth_start' );
$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=convertrack_gsc_disconnect' ), 'convertrack_gsc_disconnect' );

$notice = isset( $_GET['cvtrk_gsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cvtrk_gsc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$detail = isset( $_GET['cvtrk_gsc_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['cvtrk_gsc_detail'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap convertrack" id="convertrack-gsc">
	<?php Admin::render_header( 'gsc' ); ?>

	<?php if ( $notice ) : ?>
		<div class="cvtrk-notice">
			<?php
			switch ( $notice ) {
				case 'settings-saved':
					esc_html_e( 'Google Index Monitor settings saved.', 'convertrack-click-conversion-analytics' );
					break;
				case 'oauth-connected':
					esc_html_e( 'Google Search Console connected.', 'convertrack-click-conversion-analytics' );
					break;
				case 'oauth-disconnected':
					esc_html_e( 'Google Search Console disconnected.', 'convertrack-click-conversion-analytics' );
					break;
				default:
					echo esc_html( $detail ? $detail : __( 'Google Index Monitor action completed.', 'convertrack-click-conversion-analytics' ) );
					break;
			}
			?>
		</div>
	<?php endif; ?>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Google Search Console Connection', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub">
				<?php echo $credentials['connected'] ? esc_html__( 'Connected', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Not connected', 'convertrack-click-conversion-analytics' ); ?>
			</span>
		</div>
		<div class="cvtrk-card-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convertrack_gsc_save_settings" />
				<?php wp_nonce_field( 'convertrack_gsc_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable monitor', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_gsc_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Run sitemap scans and URL Inspection checks in the background.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-client-id"><?php esc_html_e( 'OAuth Client ID', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="text" id="cvtrk-gsc-client-id" class="regular-text" name="convertrack_gsc_settings[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-client-secret"><?php esc_html_e( 'OAuth Client Secret', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="password" id="cvtrk-gsc-client-secret" class="regular-text" name="convertrack_gsc_client_secret" value="" autocomplete="new-password" placeholder="<?php echo $credentials['has_client_secret'] ? esc_attr__( 'Saved - enter a new value to replace', 'convertrack-click-conversion-analytics' ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-property"><?php esc_html_e( 'Search Console property URL', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="text" id="cvtrk-gsc-property" class="regular-text" name="convertrack_gsc_settings[property_url]" value="<?php echo esc_attr( $s['property_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Use the exact Search Console property, e.g. https://example.com/ or sc-domain:example.com.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-sitemap"><?php esc_html_e( 'Sitemap URL', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="url" id="cvtrk-gsc-sitemap" class="regular-text" name="convertrack_gsc_settings[sitemap_url]" value="<?php echo esc_attr( $s['sitemap_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-quota"><?php esc_html_e( 'Daily URL inspection quota limit', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="number" id="cvtrk-gsc-quota" min="1" max="2000" name="convertrack_gsc_settings[daily_quota_limit]" value="<?php echo esc_attr( $s['daily_quota_limit'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-batch"><?php esc_html_e( 'Batch size per run', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="number" id="cvtrk-gsc-batch" min="1" max="500" name="convertrack_gsc_settings[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types to monitor', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php foreach ( $post_types as $post_type => $object ) : ?>
								<label style="display:inline-block;margin-right:14px;margin-bottom:6px;">
									<input type="checkbox" name="convertrack_gsc_settings[selected_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $s['selected_post_types'], true ) ); ?> />
									<?php echo esc_html( $object->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Google Indexing API', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="convertrack_gsc_settings[use_indexing_api]" value="1" <?php checked( $s['use_indexing_api'], 1 ); ?> />
								<?php esc_html_e( 'Allow Indexing API notifications only for URLs explicitly marked eligible by code.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Google Settings', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>

			<div class="cvtrk-tools">
				<a class="button button-primary" href="<?php echo esc_url( $oauth_url ); ?>"><?php esc_html_e( 'Connect Google Search Console', 'convertrack-click-conversion-analytics' ); ?></a>
				<?php if ( $credentials['connected'] ) : ?>
					<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>"><?php esc_html_e( 'Disconnect', 'convertrack-click-conversion-analytics' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="cvtrk-kpis cvtrk-gsc-kpis" data-cvtrk="gsc-summary">
		<p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'URL Index Queue', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Paginated background inspection results', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-tabs cvtrk-gsc-post-tabs" data-cvtrk="gsc-post-tabs">
				<button type="button" class="cvtrk-tab is-active" data-gsc-post-tab="all"><?php esc_html_e( 'All URLs', 'convertrack-click-conversion-analytics' ); ?></button>
				<?php foreach ( $post_types as $post_type => $object ) : ?>
					<button type="button" class="cvtrk-tab" data-gsc-post-tab="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $object->labels->name ); ?></button>
				<?php endforeach; ?>
			</div>
			<div class="cvtrk-toolbar">
				<label class="cvtrk-field">
					<?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="gsc-status">
						<option value="all"><?php esc_html_e( 'All URLs', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="indexed"><?php esc_html_e( 'Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="not_indexed"><?php esc_html_e( 'Not Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pending_due_to_quota"><?php esc_html_e( 'Pending Due to Quota', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pending_from_sitemap"><?php esc_html_e( 'Pending From Sitemap', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="error"><?php esc_html_e( 'Errors', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="ignored"><?php esc_html_e( 'Ignored', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Post type', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="gsc-post-type">
						<option value="all"><?php esc_html_e( 'All post types', 'convertrack-click-conversion-analytics' ); ?></option>
						<?php foreach ( $post_types as $post_type => $object ) : ?>
							<option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $object->labels->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Priority', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="gsc-priority">
						<option value=""><?php esc_html_e( 'Any', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="1"><?php esc_html_e( 'Priority only', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="0"><?php esc_html_e( 'Normal only', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Sitemap', 'convertrack-click-conversion-analytics' ); ?>
					<select data-cvtrk="gsc-sitemap">
						<option value=""><?php esc_html_e( 'All sitemaps', 'convertrack-click-conversion-analytics' ); ?></option>
					</select>
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Checked from', 'convertrack-click-conversion-analytics' ); ?>
					<input type="date" data-cvtrk="gsc-checked-from" />
				</label>
				<label class="cvtrk-field">
					<?php esc_html_e( 'Checked to', 'convertrack-click-conversion-analytics' ); ?>
					<input type="date" data-cvtrk="gsc-checked-to" />
				</label>
				<button type="button" class="button" data-cvtrk="gsc-scan"><?php esc_html_e( 'Scan Sitemap', 'convertrack-click-conversion-analytics' ); ?></button>
				<button type="button" class="button" data-cvtrk="gsc-process"><?php esc_html_e( 'Run Batch', 'convertrack-click-conversion-analytics' ); ?></button>
				<a class="button" data-cvtrk="gsc-export" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></a>
			</div>

			<div data-cvtrk="gsc-urls"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<div class="cvtrk-pagination">
				<button type="button" class="button" data-cvtrk="gsc-prev"><?php esc_html_e( 'Previous', 'convertrack-click-conversion-analytics' ); ?></button>
				<span data-cvtrk="gsc-page"></span>
				<button type="button" class="button" data-cvtrk="gsc-next"><?php esc_html_e( 'Next', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Google Search Console Activity Log', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Recent OAuth, sitemap, API, quota, and batch events', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div data-cvtrk="gsc-logs"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
		</div>
	</div>
</div>
