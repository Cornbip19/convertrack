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

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Settings', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Control what Convertrack collects, define conversion goals, and manage data, integrations, and modules.', 'convertrack-click-conversion-analytics' ); ?></p>
		</div>
		<div class="cvtrk-page-head-actions">
			<span class="cvtrk-page-head-meta">
				<?php
				/* translators: %s: installed plugin version. */
				printf( esc_html__( 'Version %s', 'convertrack-click-conversion-analytics' ), esc_html( CONVERTRACK_VERSION ) );
				?>
			</span>
		</div>
	</div>

	<?php
	// Display-only notices after a Tools action (our own redirect).
	if ( isset( $_GET['cvtrk_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cvtrk_n = sanitize_key( wp_unslash( $_GET['cvtrk_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'seeded' === $cvtrk_n ) :
			$cvtrk_rows = isset( $_GET['cvtrk_rows'] ) ? (int) $_GET['cvtrk_rows'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="cvtrk-notice"><?php printf( esc_html__( 'Inserted %d sample events across the last 7 days — open Dashboard to see the populated reports.', 'convertrack-click-conversion-analytics' ), (int) $cvtrk_rows ); ?></div>
		<?php elseif ( 'reset' === $cvtrk_n ) : ?>
			<div class="cvtrk-notice"><?php esc_html_e( 'All tracked data was deleted.', 'convertrack-click-conversion-analytics' ); ?></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="cvtrk-settings-layout">
	<nav class="cvtrk-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'convertrack-click-conversion-analytics' ); ?>">
		<a href="#cvtrk-settings-tracking"><?php esc_html_e( 'Tracking', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-goals"><?php esc_html_e( 'Goals', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-privacy"><?php esc_html_e( 'Privacy & Audience', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-performance"><?php esc_html_e( 'Performance & Data', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-integrations"><?php esc_html_e( 'Integrations', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-modules"><?php esc_html_e( 'Module Settings', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-updates"><?php esc_html_e( 'Updates', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-settings-data"><?php esc_html_e( 'Data Management', 'convertrack-click-conversion-analytics' ); ?></a>
	</nav>

	<div class="cvtrk-settings-content">
	<form id="convertrack-settings-form" method="post" action="options.php">
		<?php settings_fields( Admin::SETTINGS_GROUP ); ?>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-tracking" aria-labelledby="cvtrk-settings-tracking-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-tracking-title"><?php esc_html_e( 'Tracking', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Choose what activity is collected on the front end.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
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
							<p class="description"><strong><?php esc_html_e( 'Privacy note:', 'convertrack-click-conversion-analytics' ); ?></strong> <?php esc_html_e( 'This is off by default because keywords can contain user-entered text. Modern search engines often hide organic keywords; those visits will be shown as not provided.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-goals" aria-labelledby="cvtrk-settings-goals-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-goals-title"><?php esc_html_e( 'Goals', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Define the page visits and button clicks that count as conversions.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cvtrk-conv-url"><?php esc_html_e( 'Pages reached', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-conv-url" class="large-text code" rows="3" name="convertrack_settings[conversion_urls]" placeholder="/thank-you&#10;/order-received&#10;/checkout/success"><?php echo esc_textarea( $s['conversion_urls'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One path per line. A visitor reaching any of these pages is counted as a conversion. Common examples include /thank-you, /order-received/ (WooCommerce), or a form confirmation page.', 'convertrack-click-conversion-analytics' ); ?>
								<br><?php esc_html_e( 'Matching uses a substring of the path, so /order-received also matches /order-received/12345/.', 'convertrack-click-conversion-analytics' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvtrk-conv-sel"><?php esc_html_e( 'Buttons clicked', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-conv-sel" class="large-text code" rows="3" name="convertrack_settings[conversion_selectors]"><?php echo esc_textarea( $s['conversion_selectors'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One CSS selector per line. Clicking a matching button counts as a conversion; use this for AJAX forms, add-to-cart actions, and external checkout links.', 'convertrack-click-conversion-analytics' ); ?>
								<br><?php esc_html_e( 'Add data-cvtrk-convert to a conversion button, or target an existing selector such as .add-to-cart, .wpforms-submit, or button[type=submit].', 'convertrack-click-conversion-analytics' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-privacy" aria-labelledby="cvtrk-settings-privacy-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-privacy-title"><?php esc_html_e( 'Privacy & Audience', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Control who is included and which privacy signals are respected.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
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
							<p class="description"><?php esc_html_e( 'When tracking logged-in users, these roles are still ignored. This applies only when the option above is enabled.', 'convertrack-click-conversion-analytics' ); ?></p>
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
							<p class="description"><strong><?php esc_html_e( 'Privacy note:', 'convertrack-click-conversion-analytics' ); ?></strong> <?php esc_html_e( 'When enabled, the visitor\'s IP address may be sent to ip-api.com to look up the country. The IP address is never stored; only the two-letter country code is kept. A CDN country header is used first when available.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-performance" aria-labelledby="cvtrk-settings-performance-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-performance-title"><?php esc_html_e( 'Performance & Data', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Balance collection detail, traffic load, and retention.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cvtrk-sample"><?php esc_html_e( 'Sample rate', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-sample" min="1" max="100" name="convertrack_settings[sample_rate]" value="<?php echo esc_attr( $s['sample_rate'] ); ?>" /> %
							<p class="description"><?php esc_html_e( 'On very high-traffic sites, track only a percentage of visitors to reduce load. 100% tracks everyone.', 'convertrack-click-conversion-analytics' ); ?></p>
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

				<details class="cvtrk-advanced-disclosure">
					<summary><?php esc_html_e( 'Advanced performance controls', 'convertrack-click-conversion-analytics' ); ?></summary>
					<p class="description"><?php esc_html_e( 'These technical controls affect live-presence timing, request frequency, batching, and rate limiting. Keep the defaults unless you are tuning a high-traffic site.', 'convertrack-click-conversion-analytics' ); ?></p>
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
					</table>
				</details>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-integrations" aria-labelledby="cvtrk-settings-integrations-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-integrations-title"><?php esc_html_e( 'Integrations', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Connect external data sources used by Convertrack modules.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<h3><?php esc_html_e( 'Google Search Console', 'convertrack-click-conversion-analytics' ); ?></h3>
				<p><?php esc_html_e( 'The Indexing and Keyword Opportunities modules share one Search Console connection and property.', 'convertrack-click-conversion-analytics' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-gsc#convertrack-gsc-settings' ) ); ?>"><?php esc_html_e( 'Manage Search Console connection', 'convertrack-click-conversion-analytics' ); ?></a></p>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-modules" aria-labelledby="cvtrk-settings-modules-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-modules-title"><?php esc_html_e( 'Module Settings', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Open each module to manage its specialized configuration in context.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div class="cvtrk-settings-links">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-gsc#convertrack-gsc-settings' ) ); ?>"><?php esc_html_e( 'Indexing settings', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-gsc-keywords#convertrack-kw-settings' ) ); ?>"><?php esc_html_e( 'Keyword Opportunities settings', 'convertrack-click-conversion-analytics' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=convertrack-404-monitor#convertrack-404-settings' ) ); ?>"><?php esc_html_e( 'Broken URLs settings', 'convertrack-click-conversion-analytics' ); ?></a>
				</div>
			</div>
		</section>

		<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-updates" aria-labelledby="cvtrk-settings-updates-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-settings-updates-title"><?php esc_html_e( 'Updates', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php esc_html_e( 'Review the installed version and GitHub update source.', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Version', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<p>
								<?php
								if ( $cvtrk_updater ) {
									/* translators: 1: installed version, 2: latest version. */
									printf( esc_html__( 'Installed: %1$s — Latest on GitHub: %2$s', 'convertrack-click-conversion-analytics' ), '<code>' . esc_html( CONVERTRACK_VERSION ) . '</code>', '<code>' . esc_html( $latest ) . '</code>' );
								} else {
									/* translators: %s: installed version. */
									printf( esc_html__( 'Installed: %s', 'convertrack-click-conversion-analytics' ), '<code>' . esc_html( CONVERTRACK_VERSION ) . '</code>' );
								}
								?>
							</p>
							<p>
								<?php if ( $cvtrk_updater ) : ?>
									<a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates now', 'convertrack-click-conversion-analytics' ); ?></a>
								<?php endif; ?>
								<a class="button-link" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View repository', 'convertrack-click-conversion-analytics' ); ?></a>
							</p>
						</td>
					</tr>
					<?php if ( $cvtrk_updater ) : ?>
						<tr>
							<th scope="row"><label for="cvtrk-token"><?php esc_html_e( 'GitHub token', 'convertrack-click-conversion-analytics' ); ?></label></th>
							<td>
								<input type="password" id="cvtrk-token" class="regular-text" autocomplete="off" name="convertrack_settings[github_token]" value="<?php echo esc_attr( $s['github_token'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Only needed if the repository is private. Use a fine-scoped personal access token with read access to the repo.', 'convertrack-click-conversion-analytics' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>
		</section>

		<div class="cvtrk-save-bar">
			<span><?php esc_html_e( 'Save changes to apply your tracking and privacy configuration.', 'convertrack-click-conversion-analytics' ); ?></span>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'convertrack-click-conversion-analytics' ); ?></button>
		</div>
	</form>

	<section class="cvtrk-card cvtrk-settings-section" id="cvtrk-settings-data" aria-labelledby="cvtrk-settings-data-title">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="cvtrk-settings-data-title"><?php esc_html_e( 'Data Management', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Preview the dashboard with sample events or permanently remove analytics data.', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-tools">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_seed_demo" />
					<?php wp_nonce_field( 'convertrack_seed_demo' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Insert sample data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'Sample data lets you preview the dashboard without waiting for live traffic.', 'convertrack-click-conversion-analytics' ); ?></p>

			<div class="cvtrk-danger-zone">
				<h3><?php esc_html_e( 'Danger Zone', 'convertrack-click-conversion-analytics' ); ?></h3>
				<p><?php esc_html_e( 'Resetting data permanently deletes all events, sessions, and rollups. This cannot be undone.', 'convertrack-click-conversion-analytics' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete ALL tracked data? This cannot be undone.', 'convertrack-click-conversion-analytics' ) ); ?>');">
					<input type="hidden" name="action" value="convertrack_reset_data" />
					<?php wp_nonce_field( 'convertrack_reset_data' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset all data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
			</div>
		</div>
	</section>
	</div>
	</div>
</div>
