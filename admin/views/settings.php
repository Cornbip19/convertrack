<?php
/**
 * Settings screen (standard Settings API form).
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

$s     = Settings::all();
$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
if ( empty( $roles ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$roles = get_editable_roles();
}

$cvtrk_updater = isset( convertrack()->updater ) ? convertrack()->updater : null;
$repo_url      = 'https://github.com/' . CONVERTRACK_GITHUB_OWNER . '/' . CONVERTRACK_GITHUB_REPO;
$check_url     = wp_nonce_url( self_admin_url( 'update-core.php?force-check=1' ), 'upgrade-core' );
$latest        = __( 'unknown', 'convertrack-click-conversion-analytics' );
if ( $cvtrk_updater ) {
	$release = $cvtrk_updater->get_release();
	$latest  = ( is_array( $release ) && ! empty( $release['version'] ) ) ? $release['version'] : __( 'unknown', 'convertrack-click-conversion-analytics' );
}
?>
<div class="wrap convertrack">
	<?php Admin::render_header( 'settings' ); ?>
	<?php
	// Display-only notices after a Tools action (our own redirect).
	if ( isset( $_GET['cvtrk_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cvtrk_n = sanitize_key( wp_unslash( $_GET['cvtrk_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'seeded' === $cvtrk_n ) :
			$cvtrk_rows = isset( $_GET['cvtrk_rows'] ) ? (int) $_GET['cvtrk_rows'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="cvtrk-notice"><?php printf( esc_html__( 'Inserted %d sample events across the last 7 days — open Overview to see the dashboard populated.', 'convertrack-click-conversion-analytics' ), (int) $cvtrk_rows ); ?></div>
		<?php elseif ( 'reset' === $cvtrk_n ) : ?>
			<div class="cvtrk-notice"><?php esc_html_e( 'All tracked data was deleted.', 'convertrack-click-conversion-analytics' ); ?></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="cvtrk-card"><div class="cvtrk-card-body">
	<form method="post" action="options.php">
		<?php settings_fields( Admin::SETTINGS_GROUP ); ?>

		<h2 class="title"><?php esc_html_e( 'Tracking', 'convertrack-click-conversion-analytics' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable tracking', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
						<?php esc_html_e( 'Collect clicks, pageviews and presence on the front end.', 'convertrack-click-conversion-analytics' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-selectors"><?php esc_html_e( 'Tracked elements', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<textarea id="cvtrk-selectors" class="large-text code" rows="6" name="convertrack_settings[track_selectors]"><?php echo esc_textarea( $s['track_selectors'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One CSS selector per line. Any click on an element matching these is recorded. Defaults cover links, buttons, inputs and block buttons.', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Track search keywords', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[track_search_keywords]" value="1" <?php checked( $s['track_search_keywords'], 1 ); ?> />
						<?php esc_html_e( 'Store supported search terms from UTM term, this site search, and search-engine referrers when browsers expose them.', 'convertrack-click-conversion-analytics' ); ?>
					</label>
					<p class="description">
						<strong><?php esc_html_e( 'Privacy note:', 'convertrack-click-conversion-analytics' ); ?></strong>
						<?php esc_html_e( 'This is off by default because keywords can contain user-entered text. Modern search engines often hide organic keywords; those visits will be shown as not provided.', 'convertrack-click-conversion-analytics' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-conv-url"><?php esc_html_e( 'Conversion goal: pages reached', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<textarea id="cvtrk-conv-url" class="large-text code" rows="3" name="convertrack_settings[conversion_urls]" placeholder="/thank-you&#10;/order-received&#10;/checkout/success"><?php echo esc_textarea( $s['conversion_urls'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One path per line. A visitor reaching any of these pages is counted as a conversion — the easiest, most reliable goal. Common examples: /thank-you, /order-received/ (WooCommerce), a form\'s confirmation page.', 'convertrack-click-conversion-analytics' ); ?>
						<br><?php esc_html_e( 'Match is a substring of the path, so /order-received also matches /order-received/12345/.', 'convertrack-click-conversion-analytics' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-conv-sel"><?php esc_html_e( 'Conversion goal: buttons clicked', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<textarea id="cvtrk-conv-sel" class="large-text code" rows="3" name="convertrack_settings[conversion_selectors]"><?php echo esc_textarea( $s['conversion_selectors'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One CSS selector per line. Clicking a matching button counts as a conversion — use this for goals that have no destination page (AJAX forms, "Add to cart", external checkout links).', 'convertrack-click-conversion-analytics' ); ?>
						<br><?php esc_html_e( 'Easiest option: add the attribute data-cvtrk-convert to any button you consider a conversion (it already matches by default). Or target existing buttons, e.g. .add-to-cart, .wpforms-submit, button[type=submit].', 'convertrack-click-conversion-analytics' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Audience', 'convertrack-click-conversion-analytics' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Track logged-in users', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[track_logged_in]" value="1" <?php checked( $s['track_logged_in'], 1 ); ?> />
						<?php esc_html_e( 'Also track signed-in users (otherwise only logged-out visitors are tracked).', 'convertrack-click-conversion-analytics' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Exclude roles', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<?php foreach ( $roles as $role_key => $role ) : ?>
						<label class="cvtrk-inline-option">
							<input type="checkbox" name="convertrack_settings[exclude_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $s['exclude_roles'], true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'When tracking logged-in users, these roles are still ignored. (Applies only if the option above is on.)', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-exclude-urls"><?php esc_html_e( 'Exclude URLs', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<textarea id="cvtrk-exclude-urls" class="large-text code" rows="3" name="convertrack_settings[exclude_urls]"><?php echo esc_textarea( $s['exclude_urls'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Skip tracking on URLs containing any of these strings (one per line).', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Respect Do Not Track', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[respect_dnt]" value="1" <?php checked( $s['respect_dnt'], 1 ); ?> />
						<?php esc_html_e( 'Do not track visitors whose browser sends a Do-Not-Track signal.', 'convertrack-click-conversion-analytics' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor location', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[enable_geo]" value="1" <?php checked( $s['enable_geo'], 1 ); ?> />
						<?php esc_html_e( 'Record each visitor\'s country and show a "Top countries" breakdown.', 'convertrack-click-conversion-analytics' ); ?>
					</label>
					<p class="description">
						<strong><?php esc_html_e( 'Privacy note:', 'convertrack-click-conversion-analytics' ); ?></strong>
						<?php esc_html_e( 'When on, the visitor\'s IP address is sent to a third-party geolocation service (ip-api.com) to look up the country only. The IP address itself is never stored, and only the 2-letter country code is kept. This is off by default; enabling it means Convertrack contacts an external service, which you should disclose in your privacy policy. A CDN country header (e.g. Cloudflare) is used first when available, avoiding the external call.', 'convertrack-click-conversion-analytics' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-sample"><?php esc_html_e( 'Sample rate', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-sample" min="1" max="100" name="convertrack_settings[sample_rate]" value="<?php echo esc_attr( $s['sample_rate'] ); ?>" /> %
					<p class="description"><?php esc_html_e( 'On very high-traffic sites, track only a percentage of visitors to reduce load. 100% tracks everyone.', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Performance & retention', 'convertrack-click-conversion-analytics' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cvtrk-active-window"><?php esc_html_e( 'Active window', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-active-window" min="30" max="3600" name="convertrack_settings[active_window]" value="<?php echo esc_attr( $s['active_window'] ); ?>" />
					<?php esc_html_e( 'seconds', 'convertrack-click-conversion-analytics' ); ?>
					<p class="description"><?php esc_html_e( 'A visitor counts as "on the site now" if seen within this many seconds.', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-heartbeat"><?php esc_html_e( 'Heartbeat interval', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td><input type="number" id="cvtrk-heartbeat" min="5" max="120" name="convertrack_settings[heartbeat_interval]" value="<?php echo esc_attr( $s['heartbeat_interval'] ); ?>" /> <?php esc_html_e( 'seconds', 'convertrack-click-conversion-analytics' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-flush"><?php esc_html_e( 'Click flush interval', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td><input type="number" id="cvtrk-flush" min="1" max="60" name="convertrack_settings[flush_interval]" value="<?php echo esc_attr( $s['flush_interval'] ); ?>" /> <?php esc_html_e( 'seconds', 'convertrack-click-conversion-analytics' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-batch"><?php esc_html_e( 'Max events per batch', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td><input type="number" id="cvtrk-batch" min="1" max="50" name="convertrack_settings[batch_max]" value="<?php echo esc_attr( $s['batch_max'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-rate"><?php esc_html_e( 'Rate limit', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-rate" min="10" max="100000" name="convertrack_settings[rate_limit_per_min]" value="<?php echo esc_attr( $s['rate_limit_per_min'] ); ?>" />
					<?php esc_html_e( 'requests per minute, per IP', 'convertrack-click-conversion-analytics' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-retention"><?php esc_html_e( 'Raw data retention', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-retention" min="1" max="3650" name="convertrack_settings[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" />
					<?php esc_html_e( 'days', 'convertrack-click-conversion-analytics' ); ?>
					<p class="description"><?php esc_html_e( 'Raw events older than this are deleted. Daily aggregates are kept for long-term trends.', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( $cvtrk_updater ) : ?>
		<h2 class="title"><?php esc_html_e( 'Updates (GitHub)', 'convertrack-click-conversion-analytics' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Version', 'convertrack-click-conversion-analytics' ); ?></th>
				<td>
					<p>
						<?php
						/* translators: 1: installed version, 2: latest version */
						printf( esc_html__( 'Installed: %1$s — Latest on GitHub: %2$s', 'convertrack-click-conversion-analytics' ), '<code>' . esc_html( CONVERTRACK_VERSION ) . '</code>', '<code>' . esc_html( $latest ) . '</code>' );
						?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates now', 'convertrack-click-conversion-analytics' ); ?></a>
						<a class="button-link" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View repository', 'convertrack-click-conversion-analytics' ); ?></a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-token"><?php esc_html_e( 'GitHub token', 'convertrack-click-conversion-analytics' ); ?></label></th>
				<td>
					<input type="password" id="cvtrk-token" class="regular-text" autocomplete="off" name="convertrack_settings[github_token]" value="<?php echo esc_attr( $s['github_token'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Only needed if the repository is private. Use a fine-scoped personal access token with read access to the repo.', 'convertrack-click-conversion-analytics' ); ?></p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>
	</div></div>

	<div class="cvtrk-card">
		<div class="cvtrk-card-head">
			<h2><?php esc_html_e( 'Tools', 'convertrack-click-conversion-analytics' ); ?></h2>
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Preview or clear your data', 'convertrack-click-conversion-analytics' ); ?></span>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-tools">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_seed_demo" />
					<?php wp_nonce_field( 'convertrack_seed_demo' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Insert sample data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete ALL tracked data? This cannot be undone.', 'convertrack-click-conversion-analytics' ) ); ?>');">
					<input type="hidden" name="action" value="convertrack_reset_data" />
					<?php wp_nonce_field( 'convertrack_reset_data' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset all data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'Sample data lets you preview the dashboard without waiting for live traffic. Reset permanently deletes all events, sessions and rollups.', 'convertrack-click-conversion-analytics' ); ?></p>
		</div>
	</div>
</div>
