<?php
/**
 * Keyword Auto-Apply admin screen.
 *
 * @package Convertrack
 */

namespace Convertrack;

use Convertrack\GSC\Keywords_Autoapply as AA;

defined( 'ABSPATH' ) || exit;

$s      = AA::settings();
$last   = get_option( AA::LAST_RUN );
$log    = array_slice( (array) get_option( AA::LOG_OPTION, array() ), 0, 50 );
$next   = AA::next_run();
$notice = isset( $_GET['cvtrk_aa'] ) ? sanitize_key( wp_unslash( $_GET['cvtrk_aa'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$notices = array(
	'saved'        => array( 'success', __( 'Settings saved.', 'convertrack-click-conversion-analytics' ) ),
	'ran-dry'      => array( 'info', __( 'Dry run finished — nothing was changed. See the plan below.', 'convertrack-click-conversion-analytics' ) ),
	'ran-live'     => array( 'success', __( 'Live run finished. Changes are listed below.', 'convertrack-click-conversion-analytics' ) ),
	'undone'       => array( 'success', __( 'Change undone; the previous SEO title and focus keyword are restored.', 'convertrack-click-conversion-analytics' ) ),
	'undo-blocked' => array( 'warning', __( 'Nothing restored: the fields were edited by hand after the change (tick "force" to overwrite), or there is no active change.', 'convertrack-click-conversion-analytics' ) ),
	'content-applied'  => array( 'success', __( 'Sentence inserted. The page was snapshotted first; use Undo below to remove it.', 'convertrack-click-conversion-analytics' ) ),
	'content-rejected' => array( 'info', __( 'Draft rejected. This keyword will not be suggested for that page again.', 'convertrack-click-conversion-analytics' ) ),
	'content-undone'   => array( 'success', __( 'Sentence removed from the page.', 'convertrack-click-conversion-analytics' ) ),
	'content-edited'   => array( 'success', __( 'Draft updated and re-checked. Approve it when ready.', 'convertrack-click-conversion-analytics' ) ),
	'content-error'    => array( 'error', __( 'That did not go through:', 'convertrack-click-conversion-analytics' ) ),
);
$detail = (string) get_transient( 'convertrack_aa_detail_' . get_current_user_id() );
if ( $detail ) {
	delete_transient( 'convertrack_aa_detail_' . get_current_user_id() );
}

\Convertrack\GSC\Keywords_Autoapply_Content::maybe_install();
$cc       = \Convertrack\GSC\Keywords_Autoapply_Content::counts();
$drafted  = \Convertrack\GSC\Keywords_Autoapply_Content::items( 'drafted', 50 );
$waiting  = \Convertrack\GSC\Keywords_Autoapply_Content::items( 'needs_draft', 50 );
$c_active = \Convertrack\GSC\Keywords_Autoapply_Content::items( array( 'applied', 'kept', 'undone', 'reverted' ), 30 );

// Active change per post (the only one that can be undone).
$active = array();
foreach ( $log as $row ) {
	if ( isset( $active[ $row['post_id'] ] ) ) {
		continue;
	}
	$active[ $row['post_id'] ] = in_array( $row['event'], array( 'applied', 'kept' ), true );
}

$num = static function ( $key, $min, $max ) use ( $s ) {
	printf( '<input type="number" name="autoapply[%1$s]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />', esc_attr( $key ), (int) $s[ $key ], (int) $min, (int) $max );
};
?>
<div class="wrap convertrack" id="convertrack-gsc-autoapply">
	<?php Admin::render_header( 'keywords' ); ?>
	<?php Admin::render_subnav( 'search', 'autoapply' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Keyword Auto-Apply', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Applies "improve title / meta" opportunities automatically: the missing keyword variant is added to the Rank Math SEO title and the keyword becomes a secondary focus keyword. Every change is snapshotted, measured against Search Console after the evaluation window, and reverted automatically if clicks or CTR drop. Missing-keyword content opportunities become one-sentence suggestions (below) that are inserted only after you approve them.', 'convertrack-click-conversion-analytics' ); ?></p>
		</div>
	</div>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] . ( $detail ? ' ' . $detail : '' ) ); ?></p></div>
	<?php endif; ?>

	<div class="card" style="max-width:none">
		<h2><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></h2>
		<p>
			<strong><?php echo $s['enabled'] ? esc_html__( 'Enabled', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Disabled', 'convertrack-click-conversion-analytics' ); ?></strong>
			&middot; <?php echo 'live' === $s['mode'] ? esc_html__( 'Live mode (writes changes)', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Dry-run mode (plans only)', 'convertrack-click-conversion-analytics' ); ?>
			&middot; <?php esc_html_e( 'Next run:', 'convertrack-click-conversion-analytics' ); ?> <?php echo $next && $s['enabled'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) : '&mdash;'; ?>
		</p>
		<?php if ( $last ) : ?>
			<p><?php esc_html_e( 'Last run:', 'convertrack-click-conversion-analytics' ); ?> <?php echo esc_html( $last['time'] . ' (' . ( $last['dry_run'] ? 'dry run' : 'live' ) . ', ' . $last['trigger'] . ')' ); ?>
				<?php if ( $last['blocked'] ) : ?>
					&mdash; <em><?php echo esc_html( $last['blocked'] ); ?></em>
				<?php else : ?>
					&mdash; <?php echo esc_html( sprintf( '%d %s, %d skipped, %d evaluated', count( $last['applied'] ), $last['dry_run'] ? 'planned' : 'applied', count( $last['skipped'] ), count( $last['evaluated'] ) ) ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'convertrack_autoapply_run' ); ?>
			<input type="hidden" name="action" value="convertrack_autoapply_run" />
			<button class="button" name="live" value="0"><?php esc_html_e( 'Dry run now', 'convertrack-click-conversion-analytics' ); ?></button>
			<button class="button button-primary" name="live" value="1" onclick="return confirm('<?php echo esc_js( __( 'Write SEO title and focus keyword changes now?', 'convertrack-click-conversion-analytics' ) ); ?>');"><?php esc_html_e( 'Run live now', 'convertrack-click-conversion-analytics' ); ?></button>
		</form>
	</div>

	<?php if ( $last && ( $last['applied'] || $last['skipped'] || $last['evaluated'] ) ) : ?>
		<h2><?php echo $last['dry_run'] ? esc_html__( 'Last run — planned changes', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Last run — applied changes', 'convertrack-click-conversion-analytics' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Keyword', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Current title', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'New title', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $last['applied'] ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No page qualified this run.', 'convertrack-click-conversion-analytics' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $last['applied'] as $p ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $p['post_id'] ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a></td>
					<td><?php echo esc_html( $p['keyword'] ); ?><br /><small><?php echo esc_html( sprintf( '%d impressions · %s%% CTR · position %s', $p['kw_stats']['impressions'], $p['kw_stats']['ctr'], $p['kw_stats']['position'] ) ); ?></small></td>
					<td><?php echo esc_html( $p['before']['title'] ); ?></td>
					<td><strong><?php echo esc_html( $p['after']['title'] ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $last['evaluated'] ) : ?>
			<h3><?php esc_html_e( 'Measured changes', 'convertrack-click-conversion-analytics' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Clicks before → after', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'CTR before → after', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Result', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $last['evaluated'] as $e ) : ?>
					<tr><td><?php echo esc_html( $e['title'] ); ?></td><td><?php echo esc_html( $e['baseline']['clicks'] . ' → ' . $e['after']['clicks'] ); ?></td><td><?php echo esc_html( $e['baseline']['ctr'] . '% → ' . $e['after']['ctr'] . '%' ); ?></td><td><?php echo esc_html( $e['verdict'] . ( 'none' !== $e['action'] ? ' (' . $e['action'] . ')' : '' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $last['skipped'] ) : ?>
			<details style="margin-top:12px"><summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Skipped pages (%d)', 'convertrack-click-conversion-analytics' ), count( $last['skipped'] ) ) ); ?></summary>
				<table class="widefat striped"><tbody>
				<?php foreach ( $last['skipped'] as $k ) : ?>
					<tr><td><?php echo esc_html( $k['title'] ); ?></td><td><?php echo esc_html( $k['reason'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</details>
		<?php endif; ?>
	<?php endif; ?>

	<h2 id="content-review"><?php esc_html_e( 'Content suggestions', 'convertrack-click-conversion-analytics' ); ?></h2>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: waiting, 2: ready, 3: live */
				__( 'One sentence per page, added to the end of an existing paragraph. Each keyword is verified missing on the live page first. %1$d waiting for a writer · %2$d ready for your review · %3$d live.', 'convertrack-click-conversion-analytics' ),
				(int) ( $cc['needs_draft'] ?? 0 ),
				(int) ( $cc['drafted'] ?? 0 ),
				(int) ( $cc['applied'] ?? 0 ) + (int) ( $cc['kept'] ?? 0 )
			)
		);
		?>
		<?php if ( ! $s['content_enabled'] ) : ?>
			<strong><?php esc_html_e( 'Content suggestions are switched off in Settings below.', 'convertrack-click-conversion-analytics' ); ?></strong>
		<?php endif; ?>
	</p>

	<?php if ( $drafted ) : ?>
		<table class="widefat striped" style="margin-bottom:16px">
			<thead><tr><th style="width:18%"><?php esc_html_e( 'Page / keyword', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Paragraph with the new sentence (highlighted)', 'convertrack-click-conversion-analytics' ); ?></th><th style="width:24%"><?php esc_html_e( 'Review', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $drafted as $it ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_permalink( (int) $it['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></a><br />
						<strong><?php echo esc_html( $it['keyword'] ); ?></strong><br />
						<small><?php echo esc_html( sprintf( '%d impressions · position %s', $it['kw_stats']['impressions'] ?? 0, $it['kw_stats']['position'] ?? '' ) ); ?></small>
					</td>
					<td><?php echo wp_kses_post( $it['paragraph_text'] ); ?> <mark><?php echo esc_html( $it['draft'] ); ?></mark></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'convertrack_autoapply_content_approve' ); ?>
							<input type="hidden" name="action" value="convertrack_autoapply_content_approve" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
							<button class="button button-primary"><?php esc_html_e( 'Approve & insert', 'convertrack-click-conversion-analytics' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'convertrack_autoapply_content_reject' ); ?>
							<input type="hidden" name="action" value="convertrack_autoapply_content_reject" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
							<button class="button"><?php esc_html_e( 'Reject', 'convertrack-click-conversion-analytics' ); ?></button>
						</form>
						<details style="margin-top:6px"><summary><?php esc_html_e( 'Edit wording', 'convertrack-click-conversion-analytics' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'convertrack_autoapply_content_edit' ); ?>
								<input type="hidden" name="action" value="convertrack_autoapply_content_edit" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
								<textarea name="draft" rows="3" style="width:100%"><?php echo esc_textarea( $it['draft'] ); ?></textarea>
								<button class="button button-small"><?php esc_html_e( 'Save & re-check', 'convertrack-click-conversion-analytics' ); ?></button>
							</form>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $waiting ) : ?>
		<details style="margin-bottom:16px"><summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Waiting for a writer (%d)', 'convertrack-click-conversion-analytics' ), count( $waiting ) ) ); ?></summary>
			<table class="widefat striped"><tbody>
			<?php foreach ( $waiting as $it ) : ?>
				<tr><td><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></td><td><?php echo esc_html( $it['keyword'] ); ?></td><td><small><?php echo esc_html( $it['note'] ); ?></small></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</details>
	<?php endif; ?>

	<?php if ( $c_active ) : ?>
		<table class="widefat striped" style="margin-bottom:24px">
			<thead><tr><th><?php esc_html_e( 'Inserted', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Sentence', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $c_active as $it ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $it['applied_at'] ); ?></td>
					<td><a href="<?php echo esc_url( get_permalink( (int) $it['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></a><br /><small><?php echo esc_html( $it['keyword'] ); ?></small></td>
					<td><?php echo esc_html( $it['draft'] ); ?></td>
					<td><?php echo esc_html( $it['status'] ); ?></td>
					<td>
						<?php if ( in_array( $it['status'], array( 'applied', 'kept' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'convertrack_autoapply_content_undo' ); ?>
								<input type="hidden" name="action" value="convertrack_autoapply_content_undo" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
								<label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'force', 'convertrack-click-conversion-analytics' ); ?></label>
								<button class="button button-small"><?php esc_html_e( 'Undo', 'convertrack-click-conversion-analytics' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Change log', 'convertrack-click-conversion-analytics' ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'When', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Event', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Keyword', 'convertrack-click-conversion-analytics' ); ?></th><th><?php esc_html_e( 'Title before → after', 'convertrack-click-conversion-analytics' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php if ( ! $log ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No live changes yet.', 'convertrack-click-conversion-analytics' ); ?></td></tr>
		<?php endif; ?>
		<?php $shown = array(); ?>
		<?php foreach ( $log as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['time'] ); ?></td>
				<td><?php echo esc_html( $row['event'] ); ?></td>
				<td><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['post_id'] ) ); ?></a></td>
				<td><?php echo esc_html( $row['keyword'] ); ?></td>
				<td><?php echo esc_html( $row['before'] ); ?><br />→ <strong><?php echo esc_html( $row['after'] ); ?></strong></td>
				<td>
					<?php if ( ! empty( $active[ $row['post_id'] ] ) && empty( $shown[ $row['post_id'] ] ) ) : ?>
						<?php $shown[ $row['post_id'] ] = true; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'convertrack_autoapply_undo' ); ?>
							<input type="hidden" name="action" value="convertrack_autoapply_undo" />
							<input type="hidden" name="post_id" value="<?php echo (int) $row['post_id']; ?>" />
							<label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'force', 'convertrack-click-conversion-analytics' ); ?></label>
							<button class="button button-small"><?php esc_html_e( 'Undo', 'convertrack-click-conversion-analytics' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Settings', 'convertrack-click-conversion-analytics' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'convertrack_autoapply_save' ); ?>
		<input type="hidden" name="action" value="convertrack_autoapply_save" />
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Run daily', 'convertrack-click-conversion-analytics' ); ?></th><td><label><input type="checkbox" name="autoapply[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Run automatically once a day', 'convertrack-click-conversion-analytics' ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Mode', 'convertrack-click-conversion-analytics' ); ?></th><td><select name="autoapply[mode]"><option value="dry_run" <?php selected( $s['mode'], 'dry_run' ); ?>><?php esc_html_e( 'Dry run (plan only)', 'convertrack-click-conversion-analytics' ); ?></option><option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Live (write changes)', 'convertrack-click-conversion-analytics' ); ?></option></select></td></tr>
			<tr><th><?php esc_html_e( 'What to change', 'convertrack-click-conversion-analytics' ); ?></th><td><label><input type="checkbox" name="autoapply[apply_title]" value="1" <?php checked( $s['apply_title'] ); ?> /> <?php esc_html_e( 'SEO title', 'convertrack-click-conversion-analytics' ); ?></label> &nbsp; <label><input type="checkbox" name="autoapply[apply_focus]" value="1" <?php checked( $s['apply_focus'] ); ?> /> <?php esc_html_e( 'Secondary focus keyword', 'convertrack-click-conversion-analytics' ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Data range', 'convertrack-click-conversion-analytics' ); ?></th><td><select name="autoapply[range]"><?php foreach ( array( '7d', '28d', '3m' ) as $r ) : ?><option value="<?php echo esc_attr( $r ); ?>" <?php selected( $s['range'], $r ); ?>><?php echo esc_html( $r ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Limits', 'convertrack-click-conversion-analytics' ); ?></th><td>
				<?php esc_html_e( 'Max pages per run', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'max_per_run', 1, 50 ); ?> &nbsp;
				<?php esc_html_e( 'Min keyword impressions', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'min_impressions', 1, 100000 ); ?> &nbsp;
				<?php esc_html_e( 'Max position', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'max_position', 1, 20 ); ?> &nbsp;
				<?php esc_html_e( 'Title max characters', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'title_max', 40, 80 ); ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Safety', 'convertrack-click-conversion-analytics' ); ?></th><td>
				<?php esc_html_e( 'Cooldown per page (days)', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'cooldown_days', 7, 365 ); ?> &nbsp;
				<?php esc_html_e( 'Measure after (days)', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'eval_days', 14, 120 ); ?><br />
				<label><input type="checkbox" name="autoapply[auto_revert]" value="1" <?php checked( $s['auto_revert'] ); ?> /> <?php esc_html_e( 'Auto-revert when clicks or CTR fall by more than', 'convertrack-click-conversion-analytics' ); ?></label> <?php $num( 'revert_drop', 5, 90 ); ?>%<br />
				<label><input type="checkbox" name="autoapply[exclude_front]" value="1" <?php checked( $s['exclude_front'] ); ?> /> <?php esc_html_e( 'Never change the front page', 'convertrack-click-conversion-analytics' ); ?></label><br />
				<?php esc_html_e( 'Never change these post IDs:', 'convertrack-click-conversion-analytics' ); ?> <input type="text" name="autoapply[exclude_ids]" value="<?php echo esc_attr( implode( ', ', (array) $s['exclude_ids'] ) ); ?>" class="regular-text" />
			</td></tr>
			<tr><th><?php esc_html_e( 'Content suggestions', 'convertrack-click-conversion-analytics' ); ?></th><td>
				<label><input type="checkbox" name="autoapply[content_enabled]" value="1" <?php checked( $s['content_enabled'] ); ?> /> <?php esc_html_e( 'Queue missing keywords for a one-sentence insertion (inserted only after approval)', 'convertrack-click-conversion-analytics' ); ?></label><br />
				<?php esc_html_e( 'Min impressions', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_min_impressions', 1, 100000 ); ?> &nbsp;
				<?php esc_html_e( 'Max position', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_max_position', 1, 100 ); ?> &nbsp;
				<?php esc_html_e( 'New per run', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_max_per_scan', 1, 50 ); ?>
			</td></tr>
		</table>
		<?php submit_button( __( 'Save settings', 'convertrack-click-conversion-analytics' ) ); ?>
	</form>
</div>
