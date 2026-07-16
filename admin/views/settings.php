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
$token_is_set  = '' !== Settings::github_token();
$scrub_state   = get_option( Privacy_Scrubber::STATE_OPTION, array() );
$storage_health= Database::schema_is_healthy() ? Database::storage_health() : array();
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
			<div class="cvtrk-notice"><?php esc_html_e( 'Analytics events, live sessions, rollups, and analytics worker state were deleted. Module configuration and operational module data were preserved.', 'convertrack-click-conversion-analytics' ); ?></div>
		<?php elseif ( 'operational-reset' === $cvtrk_n ) : ?>
			<div class="cvtrk-notice"><?php esc_html_e( 'All Convertrack operational rows were deleted. Configuration, OAuth credentials, schema versions, and the updater secret were preserved.', 'convertrack-click-conversion-analytics' ); ?></div>
		<?php elseif ( 'scrub-started' === $cvtrk_n ) : ?>
			<div class="cvtrk-notice"><?php esc_html_e( 'The historical privacy scrub was queued. It runs in bounded background batches; progress is shown in Data Management.', 'convertrack-click-conversion-analytics' ); ?></div>
		<?php elseif ( 'error' === $cvtrk_n ) :
			$cvtrk_error = get_transient( 'convertrack_action_error_' . get_current_user_id() );
			delete_transient( 'convertrack_action_error_' . get_current_user_id() );
			?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The requested operation did not complete. No success was recorded.', 'convertrack-click-conversion-analytics' ); ?></p><?php if ( $cvtrk_error ) : ?><details><summary><?php esc_html_e( 'Technical detail', 'convertrack-click-conversion-analytics' ); ?></summary><p><code><?php echo esc_html( $cvtrk_error ); ?></code></p></details><?php endif; ?></div>
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
						<th scope="row"><label for="cvtrk-query-allowlist"><?php esc_html_e( 'Retained URL parameters', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<textarea id="cvtrk-query-allowlist" class="large-text code" rows="3" name="convertrack_settings[query_param_allowlist]"><?php echo esc_textarea( $s['query_param_allowlist'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional parameter names, one per line. Page and link query strings are removed by default. Credential, email, session, reset, order-key, and other sensitive parameter names are always removed even if listed here.', 'convertrack-click-conversion-analytics' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Global privacy signals', 'convertrack-click-conversion-analytics' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'Global Privacy Control and a denied WordPress Consent API statistics purpose are always respected.', 'convertrack-click-conversion-analytics' ); ?></p></td>
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
					<tr>
						<th scope="row"><label for="cvtrk-aggregate-retention"><?php esc_html_e( 'Detailed aggregate retention', 'convertrack-click-conversion-analytics' ); ?></label></th>
						<td>
							<input type="number" id="cvtrk-aggregate-retention" min="32" max="3650" name="convertrack_settings[aggregate_retention_days]" value="<?php echo esc_attr( $s['aggregate_retention_days'] ); ?>" />
							<?php esc_html_e( 'days', 'convertrack-click-conversion-analytics' ); ?>
							<p class="description"><?php esc_html_e( 'Daily selector, source, geography, keyword, visitor, and session dimensions older than this are deleted in bounded batches. Default: 400 days.', 'convertrack-click-conversion-analytics' ); ?></p>
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
								<input type="password" id="cvtrk-token" class="regular-text" autocomplete="new-password" name="convertrack_settings[github_token]" value="" placeholder="<?php echo esc_attr( $token_is_set ? __( 'Stored securely - enter a value only to replace it', 'convertrack-click-conversion-analytics' ) : __( 'No token stored', 'convertrack-click-conversion-analytics' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Only needed for a private repository. The saved token is non-autoloaded and is never rendered back into HTML. A CONVERTRACK_GITHUB_TOKEN constant takes precedence.', 'convertrack-click-conversion-analytics' ); ?></p>
								<?php if ( $token_is_set && ! defined( 'CONVERTRACK_GITHUB_TOKEN' ) ) : ?>
									<label><input type="checkbox" name="convertrack_settings[github_token_clear]" value="1" /> <?php esc_html_e( 'Remove the stored token', 'convertrack-click-conversion-analytics' ); ?></label>
								<?php endif; ?>
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
			<span class="cvtrk-card-sub"><?php esc_html_e( 'Inspect storage, scrub legacy sensitive data, or perform explicitly scoped deletion.', 'convertrack-click-conversion-analytics' ); ?></span>
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

			<h3><?php esc_html_e( 'Historical privacy scrub', 'convertrack-click-conversion-analytics' ); ?></h3>
			<p><?php esc_html_e( 'Removes legacy URL queries, redacts credential-like path segments, clears editable-control text, replaces client titles with public WordPress titles, and merges legacy query-specific 404 rows. It is resumable and never scans the full history in this request.', 'convertrack-click-conversion-analytics' ); ?></p>
			<?php if ( is_array( $scrub_state ) && ! empty( $scrub_state['status'] ) ) : ?>
				<p class="description"><?php printf( esc_html__( 'Status: %1$s; stage: %2$s; scanned: %3$d; changed or would change: %4$d.', 'convertrack-click-conversion-analytics' ), esc_html( $scrub_state['status'] ), esc_html( isset( $scrub_state['stage'] ) ? $scrub_state['stage'] : '-' ), (int) ( isset( $scrub_state['scanned'] ) ? $scrub_state['scanned'] : 0 ), (int) ( isset( $scrub_state['changed'] ) ? $scrub_state['changed'] : 0 ) ); ?></p>
			<?php endif; ?>
			<div class="cvtrk-tools">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_privacy_scrub" />
					<input type="hidden" name="scrub_mode" value="dry-run" />
					<?php wp_nonce_field( 'convertrack_privacy_scrub' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Queue privacy scrub dry run', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_privacy_scrub" />
					<input type="hidden" name="scrub_mode" value="apply" />
					<?php wp_nonce_field( 'convertrack_privacy_scrub' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Queue privacy scrub', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
			</div>

			<?php if ( ! empty( $storage_health['events'] ) ) : ?>
				<p class="description"><?php printf( esc_html__( 'Estimated analytics events: %1$d; data: %2$s; indexes: %3$s; rollup backlog: %4$d day(s).', 'convertrack-click-conversion-analytics' ), (int) $storage_health['events']['rows_estimate'], esc_html( size_format( $storage_health['events']['data_bytes'] ) ), esc_html( size_format( $storage_health['events']['index_bytes'] ) ), (int) $storage_health['rollup_backlog_days'] ); ?></p>
			<?php endif; ?>

			<div class="cvtrk-danger-zone">
				<h3><?php esc_html_e( 'Danger Zone', 'convertrack-click-conversion-analytics' ); ?></h3>
				<p><?php esc_html_e( 'Reset analytics deletes events, presence sessions, analytics rollups, and their worker state. It preserves Search, Keyword, Broken URL, settings, credentials, and updater data.', 'convertrack-click-conversion-analytics' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_reset_data" />
					<?php wp_nonce_field( 'convertrack_reset_data' ); ?>
					<label for="cvtrk-reset-confirm"><?php esc_html_e( 'Type RESET ANALYTICS', 'convertrack-click-conversion-analytics' ); ?></label>
					<input id="cvtrk-reset-confirm" name="confirmation" type="text" autocomplete="off" required pattern="RESET ANALYTICS" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset analytics data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
				<hr />
				<p><?php esc_html_e( 'Delete operational data empties every Convertrack table, including active redirects, GSC/Keyword queues and analysis, Broken URL history/logs, and ingestion metrics. Settings, schema versions, OAuth credentials, and the updater secret are preserved.', 'convertrack-click-conversion-analytics' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="convertrack_delete_operational_data" />
					<?php wp_nonce_field( 'convertrack_delete_operational_data' ); ?>
					<label for="cvtrk-delete-confirm"><?php esc_html_e( 'Type DELETE CONVERTRACK', 'convertrack-click-conversion-analytics' ); ?></label>
					<input id="cvtrk-delete-confirm" name="confirmation" type="text" autocomplete="off" required pattern="DELETE CONVERTRACK" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all operational data', 'convertrack-click-conversion-analytics' ); ?></button>
				</form>
			</div>
		</div>
	</section>
	</div>
	</div>
</div>
