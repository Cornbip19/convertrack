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
<div class="wrap convertrack" id="convertrack-gsc" data-gsc-indexing-api="<?php echo esc_attr( ! empty( $s['use_indexing_api'] ) ? '1' : '0' ); ?>">
	<?php Admin::render_header( 'gsc' ); ?>
	<?php Admin::render_subnav( 'search', 'gsc' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Indexing', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Monitor Google index coverage and work through URLs that need attention.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta">
				<?php if ( $credentials['connected'] ) : ?>
					<?php echo Admin::icon( 'conversions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php
					if ( '' !== $credentials['google_account'] ) {
						echo esc_html(
							sprintf(
								/* translators: %s: connected Google account email. */
								__( 'Connected as %s', 'convertrack-click-conversion-analytics' ),
								$credentials['google_account']
							)
						);
					} else {
						esc_html_e( 'Google Search Console connected', 'convertrack-click-conversion-analytics' );
					}
					?>
				<?php else : ?>
					<?php echo Admin::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Setup required', 'convertrack-click-conversion-analytics' ); ?>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<?php if ( $notice ) : ?>
		<div class="cvtrk-notice">
			<?php
			switch ( $notice ) {
				case 'settings-saved':
					esc_html_e( 'Google Index Monitor settings saved.', 'convertrack-click-conversion-analytics' );
					break;
				case 'client-saved':
					esc_html_e( 'Google API credentials saved. You can now connect Search Console.', 'convertrack-click-conversion-analytics' );
					break;
				case 'oauth-connected':
					if ( '' !== $detail ) {
						echo esc_html(
							sprintf(
								/* translators: %s: auto-selected Search Console property. */
								__( 'Google Search Console connected. Search Console property set to %s.', 'convertrack-click-conversion-analytics' ),
								$detail
							)
						);
					} else {
						esc_html_e( 'Google Search Console connected.', 'convertrack-click-conversion-analytics' );
					}
					break;
				case 'oauth-disconnected':
					esc_html_e( 'Google Search Console disconnected.', 'convertrack-click-conversion-analytics' );
					break;
				case 'oauth-property-warning':
					echo esc_html(
						sprintf(
							/* translators: %s: detail message from the property check. */
							__( 'Connected to Google, but the Search Console property URL may not match a property this account owns: %s', 'convertrack-click-conversion-analytics' ),
							$detail
						)
					);
					break;
				case 'settings-property-warning':
					echo esc_html(
						sprintf(
							/* translators: %s: detail message from the property check. */
							__( 'Settings saved, but the Search Console property may not match a property this account owns: %s', 'convertrack-click-conversion-analytics' ),
							$detail
						)
					);
					break;
				default:
					echo esc_html( $detail ? $detail : __( 'Google Index Monitor action completed.', 'convertrack-click-conversion-analytics' ) );
					break;
			}
			?>
		</div>
	<?php endif; ?>

	<?php
	$cvtrk_redirect_uri = \Convertrack\GSC\OAuth::redirect_uri();
	$cvtrk_link_kses    = array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) );
	ob_start();
	?>
	<div class="cvtrk-card" id="convertrack-gsc-credentials">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Google API credentials', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub">
				<?php echo $credentials['has_client'] ? esc_html__( 'Saved', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Not set', 'convertrack-click-conversion-analytics' ); ?>
			</span>
		</div>
		<div class="cvtrk-card-body">
			<p class="description">
				<?php esc_html_e( 'Convertrack connects to your Google account directly using your own Google Cloud OAuth client. This one-time setup takes a few minutes:', 'convertrack-click-conversion-analytics' ); ?>
			</p>
			<ol class="cvtrk-gsc-steps">
				<li><?php echo wp_kses( sprintf( __( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and create or select a project.', 'convertrack-click-conversion-analytics' ), 'https://console.cloud.google.com/' ), $cvtrk_link_kses ); ?></li>
				<li>
					<?php
					echo wp_kses( sprintf( __( 'Enable the <a href="%s" target="_blank" rel="noopener noreferrer">Google Search Console API</a> for the project.', 'convertrack-click-conversion-analytics' ), 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' ), $cvtrk_link_kses );
					if ( ! empty( $s['use_indexing_api'] ) ) {
						echo ' ';
						echo wp_kses( sprintf( __( 'Also enable the <a href="%s" target="_blank" rel="noopener noreferrer">Web Search Indexing API</a>.', 'convertrack-click-conversion-analytics' ), 'https://console.cloud.google.com/apis/library/indexing.googleapis.com' ), $cvtrk_link_kses );
					}
					?>
				</li>
				<li><?php esc_html_e( 'On the OAuth consent screen, choose "External" and add your Google account as a test user.', 'convertrack-click-conversion-analytics' ); ?></li>
				<li><?php esc_html_e( 'Create an OAuth client ID of type "Web application".', 'convertrack-click-conversion-analytics' ); ?></li>
				<li><?php esc_html_e( 'Add this exact Authorized redirect URI to that client:', 'convertrack-click-conversion-analytics' ); ?></li>
			</ol>
			<p class="cvtrk-gsc-redirect">
				<input type="text" readonly class="regular-text code" data-cvtrk="gsc-redirect-uri" value="<?php echo esc_attr( $cvtrk_redirect_uri ); ?>" aria-label="<?php esc_attr_e( 'Authorized OAuth redirect URI', 'convertrack-click-conversion-analytics' ); ?>" />
				<button type="button" class="button" data-cvtrk="gsc-copy-redirect"><?php esc_html_e( 'Copy', 'convertrack-click-conversion-analytics' ); ?></button>
			</p>
			<?php if ( 0 === strpos( (string) $cvtrk_redirect_uri, 'http://' ) && false === strpos( (string) $cvtrk_redirect_uri, '://localhost' ) && false === strpos( (string) $cvtrk_redirect_uri, '://127.0.0.1' ) ) : ?>
				<p class="description cvtrk-danger-text">
					<?php esc_html_e( 'Google requires an https (or localhost) redirect URI. Enable HTTPS for this site, or filter convertrack_gsc_redirect_uri to an https URL, before connecting.', 'convertrack-click-conversion-analytics' ); ?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convertrack_gsc_save_client" />
				<?php wp_nonce_field( 'convertrack_gsc_save_client' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cvtrk-gsc-client-id"><?php esc_html_e( 'OAuth Client ID', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td><input type="text" id="cvtrk-gsc-client-id" class="regular-text" name="convertrack_gsc_client_id" value="<?php echo esc_attr( $credentials['client_id'] ); ?>" autocomplete="off" placeholder="1234567890-abc.apps.googleusercontent.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-gsc-client-secret"><?php esc_html_e( 'OAuth Client Secret', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="password" id="cvtrk-gsc-client-secret" class="regular-text" name="convertrack_gsc_client_secret" value="" autocomplete="off" placeholder="<?php echo $credentials['has_client'] ? esc_attr__( 'Saved — leave blank to keep', 'convertrack-click-conversion-analytics' ) : ''; ?>" />
							<p class="description"><?php esc_html_e( 'Stored encrypted in this site\'s database. Leave blank to keep the saved secret.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save API credentials', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>
		</div>
	</div>

	<div class="cvtrk-card" id="convertrack-gsc-connection">
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
						<th scope="row"><label for="cvtrk-gsc-property"><?php esc_html_e( 'Search Console property', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<select class="regular-text cvtrk-stacked-field cvtrk-is-hidden" data-cvtrk="gsc-property-picker" aria-label="<?php esc_attr_e( 'Connected Search Console property', 'convertrack-click-conversion-analytics' ); ?>">
								<option value=""><?php esc_html_e( 'Choose a connected property…', 'convertrack-click-conversion-analytics' ); ?></option>
							</select>
							<input type="text" id="cvtrk-gsc-property" class="regular-text" name="convertrack_gsc_settings[property_url]" data-cvtrk="gsc-property-input" value="<?php echo esc_attr( $s['property_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'After connecting, pick a verified property from the list above, or type it exactly (e.g. https://example.com/ or sc-domain:example.com).', 'convertrack-click-conversion-analytics' ); ?></p>
							<p class="description" data-cvtrk="gsc-property-status" hidden aria-live="polite"></p>
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
								<label class="cvtrk-inline-option">
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
								<?php esc_html_e( 'Enable Google Indexing API notifications, including a per-URL "Notify Google" button in the queue below.', 'convertrack-click-conversion-analytics' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Google officially supports the Indexing API only for job-posting and livestream pages — notifications for other content may be ignored. Quota is 200 requests per day. Requires enabling the Web Search Indexing API in your Google Cloud project (see setup above) and reconnecting Google once to grant the extra permission.', 'convertrack-click-conversion-analytics' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Google Settings', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>

			<div class="cvtrk-tools">
				<?php if ( $credentials['has_client'] ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $oauth_url ); ?>">
						<?php echo $credentials['connected'] ? esc_html__( 'Reconnect Google Search Console', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Connect Google Search Console', 'convertrack-click-conversion-analytics' ); ?>
					</a>
				<?php else : ?>
					<button type="button" class="button button-primary" disabled><?php esc_html_e( 'Connect Google Search Console', 'convertrack-click-conversion-analytics' ); ?></button>
				<?php endif; ?>
				<?php if ( $credentials['connected'] ) : ?>
					<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>"><?php esc_html_e( 'Disconnect', 'convertrack-click-conversion-analytics' ); ?></a>
				<?php endif; ?>
				<p class="description">
					<?php
					if ( $credentials['connected'] && '' !== $credentials['google_account'] ) {
						echo esc_html(
							sprintf(
								/* translators: %s: connected Google account email. */
								__( 'Connected as %s.', 'convertrack-click-conversion-analytics' ),
								$credentials['google_account']
							)
						);
					} elseif ( ! $credentials['has_client'] ) {
						esc_html_e( 'Save your Google API credentials above, then connect.', 'convertrack-click-conversion-analytics' );
					} else {
						esc_html_e( 'You will be redirected to Google to sign in and grant Search Console access.', 'convertrack-click-conversion-analytics' );
					}
					?>
				</p>
			</div>
		</div>
	</div>
	<?php
	$cvtrk_integration_markup = ob_get_clean();
	if ( ! $credentials['connected'] ) :
		?>
		<section class="cvtrk-gsc-setup" aria-labelledby="convertrack-gsc-setup-title">
			<div class="cvtrk-section-head">
				<div>
					<h2 id="convertrack-gsc-setup-title"><?php esc_html_e( 'Connect Google Search Console', 'convertrack-click-conversion-analytics' ); ?></h2>
					<p><?php esc_html_e( 'Complete the one-time setup below. Your credentials stay in this WordPress site.', 'convertrack-click-conversion-analytics' ); ?></p>
				</div>
				<ol class="cvtrk-setup-progress" aria-label="<?php esc_attr_e( 'Connection setup progress', 'convertrack-click-conversion-analytics' ); ?>">
					<li class="is-current"><?php esc_html_e( 'Create OAuth client', 'convertrack-click-conversion-analytics' ); ?></li>
					<li class="<?php echo $credentials['has_client'] ? 'is-complete' : ''; ?>"><?php esc_html_e( 'Save credentials', 'convertrack-click-conversion-analytics' ); ?></li>
					<li><?php esc_html_e( 'Connect account', 'convertrack-click-conversion-analytics' ); ?></li>
				</ol>
			</div>
			<?php echo $cvtrk_integration_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
	<?php endif; ?>

	<div class="cvtrk-gsc-panel" data-cvtrk="gsc-summary">
		<p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p>
	</div>

	<div class="cvtrk-card" id="convertrack-gsc-queue">
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
						<option value="needs_indexing"><?php esc_html_e( 'Needs Indexing (all stuck pages)', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="indexed"><?php esc_html_e( 'Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="not_indexed"><?php esc_html_e( 'Not Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pending_due_to_quota"><?php esc_html_e( 'Pending Due to Quota', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="pending_from_sitemap"><?php esc_html_e( 'Pending From Sitemap', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="crawled_not_indexed"><?php esc_html_e( 'Crawled But Not Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="discovered_not_indexed"><?php esc_html_e( 'Discovered But Not Indexed', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="duplicate_canonical"><?php esc_html_e( 'Duplicate/Canonical Issue', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="blocked_by_robots"><?php esc_html_e( 'Blocked by Robots', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="noindex_detected"><?php esc_html_e( 'Noindex Detected', 'convertrack-click-conversion-analytics' ); ?></option>
						<option value="submitted_via_indexing_api"><?php esc_html_e( 'Submitted via Indexing API', 'convertrack-click-conversion-analytics' ); ?></option>
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
				<button type="button" class="button" data-cvtrk="gsc-open-gsc" title="<?php esc_attr_e( 'Opens each listed URL (up to 10) in Google Search Console, where you can click Request Indexing.', 'convertrack-click-conversion-analytics' ); ?>"><?php esc_html_e( 'Open in GSC', 'convertrack-click-conversion-analytics' ); ?></button>
				<a class="button" data-cvtrk="gsc-export" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'convertrack-click-conversion-analytics' ); ?></a>
			</div>

			<div class="cvtrk-notice cvtrk-progress-notice" data-cvtrk="gsc-progress" hidden aria-live="polite"></div>

			<div data-cvtrk="gsc-urls"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<div class="cvtrk-pagination">
				<button type="button" class="button" data-cvtrk="gsc-prev"><?php esc_html_e( 'Previous', 'convertrack-click-conversion-analytics' ); ?></button>
				<span data-cvtrk="gsc-page"></span>
				<button type="button" class="button" data-cvtrk="gsc-next"><?php esc_html_e( 'Next', 'convertrack-click-conversion-analytics' ); ?></button>
			</div>
		</div>
	</div>

	<?php if ( $credentials['connected'] ) : ?>
		<details class="cvtrk-advanced-disclosure cvtrk-integration-disclosure" id="convertrack-gsc-settings">
			<summary>
				<strong><?php esc_html_e( 'Integration settings', 'convertrack-click-conversion-analytics' ); ?></strong>
				<span><?php esc_html_e( 'Connection, property, credentials, quotas, and monitored content', 'convertrack-click-conversion-analytics' ); ?></span>
			</summary>
			<div class="cvtrk-disclosure-content">
				<?php echo $cvtrk_integration_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</details>
	<?php endif; ?>

	<details class="cvtrk-advanced-disclosure cvtrk-log-disclosure">
		<summary>
			<strong><?php esc_html_e( 'Activity log', 'convertrack-click-conversion-analytics' ); ?></strong>
			<span><?php esc_html_e( 'Recent OAuth, sitemap, API, quota, and batch events', 'convertrack-click-conversion-analytics' ); ?></span>
		</summary>
		<div class="cvtrk-card">
			<div class="cvtrk-card-body">
				<div data-cvtrk="gsc-logs"><p class="cvtrk-skeleton"><?php esc_html_e( 'Loading...', 'convertrack-click-conversion-analytics' ); ?></p></div>
			</div>
		</div>
	</details>
</div>
