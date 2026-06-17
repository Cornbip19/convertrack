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

$release   = convertrack()->updater->get_release();
$latest    = ( is_array( $release ) && ! empty( $release['version'] ) ) ? $release['version'] : __( 'unknown', 'convertrack' );
$repo_url  = 'https://github.com/' . CONVERTRACK_GITHUB_OWNER . '/' . CONVERTRACK_GITHUB_REPO;
$check_url = wp_nonce_url( self_admin_url( 'update-core.php?force-check=1' ), 'upgrade-core' );
?>
<div class="wrap convertrack-wrap">
	<h1 class="convertrack-title">
		<span class="dashicons dashicons-admin-settings"></span>
		<?php esc_html_e( 'Convertrack Settings', 'convertrack' ); ?>
	</h1>

	<form method="post" action="options.php">
		<?php settings_fields( Admin::SETTINGS_GROUP ); ?>

		<h2 class="title"><?php esc_html_e( 'Tracking', 'convertrack' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable tracking', 'convertrack' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
						<?php esc_html_e( 'Collect clicks, pageviews and presence on the front end.', 'convertrack' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-selectors"><?php esc_html_e( 'Tracked elements', 'convertrack' ); ?></label></th>
				<td>
					<textarea id="cvtrk-selectors" class="large-text code" rows="6" name="convertrack_settings[track_selectors]"><?php echo esc_textarea( $s['track_selectors'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One CSS selector per line. Any click on an element matching these is recorded. Defaults cover links, buttons, inputs and block buttons.', 'convertrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-conv-sel"><?php esc_html_e( 'Conversion elements', 'convertrack' ); ?></label></th>
				<td>
					<textarea id="cvtrk-conv-sel" class="large-text code" rows="3" name="convertrack_settings[conversion_selectors]"><?php echo esc_textarea( $s['conversion_selectors'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Clicks on elements matching these count as conversions (e.g. .cvtrk-convert, [data-cvtrk-convert]).', 'convertrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-conv-url"><?php esc_html_e( 'Conversion URLs', 'convertrack' ); ?></label></th>
				<td>
					<textarea id="cvtrk-conv-url" class="large-text code" rows="3" name="convertrack_settings[conversion_urls]"><?php echo esc_textarea( $s['conversion_urls'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One path per line (e.g. /thank-you). A pageview or a click whose link points to one of these counts as a conversion.', 'convertrack' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Audience', 'convertrack' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Track logged-in users', 'convertrack' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[track_logged_in]" value="1" <?php checked( $s['track_logged_in'], 1 ); ?> />
						<?php esc_html_e( 'Also track signed-in users (otherwise only logged-out visitors are tracked).', 'convertrack' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Exclude roles', 'convertrack' ); ?></th>
				<td>
					<?php foreach ( $roles as $role_key => $role ) : ?>
						<label style="display:inline-block;margin-right:14px;">
							<input type="checkbox" name="convertrack_settings[exclude_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $s['exclude_roles'], true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'When tracking logged-in users, these roles are still ignored. (Applies only if the option above is on.)', 'convertrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-exclude-urls"><?php esc_html_e( 'Exclude URLs', 'convertrack' ); ?></label></th>
				<td>
					<textarea id="cvtrk-exclude-urls" class="large-text code" rows="3" name="convertrack_settings[exclude_urls]"><?php echo esc_textarea( $s['exclude_urls'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Skip tracking on URLs containing any of these strings (one per line).', 'convertrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Respect Do Not Track', 'convertrack' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="convertrack_settings[respect_dnt]" value="1" <?php checked( $s['respect_dnt'], 1 ); ?> />
						<?php esc_html_e( 'Do not track visitors whose browser sends a Do-Not-Track signal.', 'convertrack' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-sample"><?php esc_html_e( 'Sample rate', 'convertrack' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-sample" min="1" max="100" name="convertrack_settings[sample_rate]" value="<?php echo esc_attr( $s['sample_rate'] ); ?>" /> %
					<p class="description"><?php esc_html_e( 'On very high-traffic sites, track only a percentage of visitors to reduce load. 100% tracks everyone.', 'convertrack' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Performance & retention', 'convertrack' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cvtrk-active-window"><?php esc_html_e( 'Active window', 'convertrack' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-active-window" min="30" max="3600" name="convertrack_settings[active_window]" value="<?php echo esc_attr( $s['active_window'] ); ?>" />
					<?php esc_html_e( 'seconds', 'convertrack' ); ?>
					<p class="description"><?php esc_html_e( 'A visitor counts as "on the site now" if seen within this many seconds.', 'convertrack' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-heartbeat"><?php esc_html_e( 'Heartbeat interval', 'convertrack' ); ?></label></th>
				<td><input type="number" id="cvtrk-heartbeat" min="5" max="120" name="convertrack_settings[heartbeat_interval]" value="<?php echo esc_attr( $s['heartbeat_interval'] ); ?>" /> <?php esc_html_e( 'seconds', 'convertrack' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-flush"><?php esc_html_e( 'Click flush interval', 'convertrack' ); ?></label></th>
				<td><input type="number" id="cvtrk-flush" min="1" max="60" name="convertrack_settings[flush_interval]" value="<?php echo esc_attr( $s['flush_interval'] ); ?>" /> <?php esc_html_e( 'seconds', 'convertrack' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-batch"><?php esc_html_e( 'Max events per batch', 'convertrack' ); ?></label></th>
				<td><input type="number" id="cvtrk-batch" min="1" max="50" name="convertrack_settings[batch_max]" value="<?php echo esc_attr( $s['batch_max'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-rate"><?php esc_html_e( 'Rate limit', 'convertrack' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-rate" min="10" max="100000" name="convertrack_settings[rate_limit_per_min]" value="<?php echo esc_attr( $s['rate_limit_per_min'] ); ?>" />
					<?php esc_html_e( 'requests per minute, per IP', 'convertrack' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-retention"><?php esc_html_e( 'Raw data retention', 'convertrack' ); ?></label></th>
				<td>
					<input type="number" id="cvtrk-retention" min="1" max="3650" name="convertrack_settings[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" />
					<?php esc_html_e( 'days', 'convertrack' ); ?>
					<p class="description"><?php esc_html_e( 'Raw events older than this are deleted. Daily aggregates are kept for long-term trends.', 'convertrack' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Updates (GitHub)', 'convertrack' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Version', 'convertrack' ); ?></th>
				<td>
					<p>
						<?php
						/* translators: 1: installed version, 2: latest version */
						printf( esc_html__( 'Installed: %1$s — Latest on GitHub: %2$s', 'convertrack' ), '<code>' . esc_html( CONVERTRACK_VERSION ) . '</code>', '<code>' . esc_html( $latest ) . '</code>' );
						?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates now', 'convertrack' ); ?></a>
						<a class="button-link" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View repository', 'convertrack' ); ?></a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvtrk-token"><?php esc_html_e( 'GitHub token', 'convertrack' ); ?></label></th>
				<td>
					<input type="password" id="cvtrk-token" class="regular-text" autocomplete="off" name="convertrack_settings[github_token]" value="<?php echo esc_attr( $s['github_token'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Only needed if the repository is private. Use a fine-scoped personal access token with read access to the repo.', 'convertrack' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
