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
	'ran-dry'      => array( 'info', __( 'Dry run finished — nothing was changed. The plan is listed below.', 'convertrack-click-conversion-analytics' ) ),
	'ran-live'     => array( 'success', __( 'Live run finished. The changes are listed below.', 'convertrack-click-conversion-analytics' ) ),
	'undone'       => array( 'success', __( 'Change undone; the previous SEO title and focus keyword are back.', 'convertrack-click-conversion-analytics' ) ),
	'undo-blocked' => array( 'warning', __( 'Nothing restored: the fields were edited by hand after the change (tick "Undo even if edited since" to overwrite), or there is no active change.', 'convertrack-click-conversion-analytics' ) ),
	'content-applied'  => array( 'success', __( 'Sentence added to the page. A copy of the page was saved first; use Undo below to take it out again.', 'convertrack-click-conversion-analytics' ) ),
	'content-rejected' => array( 'info', __( 'Suggestion rejected. This keyword will not be suggested for that page again.', 'convertrack-click-conversion-analytics' ) ),
	'content-undone'   => array( 'success', __( 'Sentence removed from the page.', 'convertrack-click-conversion-analytics' ) ),
	'content-edited'   => array( 'success', __( 'Wording updated and re-checked. Approve it when it reads right.', 'convertrack-click-conversion-analytics' ) ),
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

// Plain-language presentation helpers (2.12). Stored values are unchanged;
// only what the screen says about them is.
$cvtrk_datetime = static function ( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '—';
	}
	return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $value );
};
$cvtrk_status_labels = array(
	'needs_draft' => __( 'Waiting for a writer', 'convertrack-click-conversion-analytics' ),
	'drafted'     => __( 'Ready for your review', 'convertrack-click-conversion-analytics' ),
	'approved'    => __( 'Approved', 'convertrack-click-conversion-analytics' ),
	'applied'     => __( 'On the page', 'convertrack-click-conversion-analytics' ),
	'kept'        => __( 'On the page, kept (it helped)', 'convertrack-click-conversion-analytics' ),
	'undone'      => __( 'Removed by you', 'convertrack-click-conversion-analytics' ),
	'reverted'    => __( 'Removed automatically (no gain)', 'convertrack-click-conversion-analytics' ),
	'rejected'    => __( 'Rejected', 'convertrack-click-conversion-analytics' ),
);
$cvtrk_status_tones  = array(
	'applied'     => 'green',
	'kept'        => 'green',
	'drafted'     => 'amber',
	'approved'    => 'amber',
	'needs_draft' => 'gray',
	'undone'      => 'gray',
	'rejected'    => 'gray',
	'reverted'    => 'red',
);
$cvtrk_event_labels  = array(
	'applied'  => __( 'Title changed', 'convertrack-click-conversion-analytics' ),
	'kept'     => __( 'Change kept (it helped)', 'convertrack-click-conversion-analytics' ),
	'reverted' => __( 'Change reverted (no gain)', 'convertrack-click-conversion-analytics' ),
	'undone'   => __( 'Change undone by you', 'convertrack-click-conversion-analytics' ),
);
$cvtrk_event_tones   = array(
	'applied'  => 'green',
	'kept'     => 'green',
	'reverted' => 'red',
	'undone'   => 'gray',
);
$cvtrk_trigger_labels = array(
	'schedule' => __( 'ran automatically', 'convertrack-click-conversion-analytics' ),
	'admin'    => __( 'started by you', 'convertrack-click-conversion-analytics' ),
	'manual'   => __( 'started by you', 'convertrack-click-conversion-analytics' ),
	'cli'      => __( 'from the command line', 'convertrack-click-conversion-analytics' ),
);
$cvtrk_verdict_labels = array(
	'kept'  => __( 'Held up, kept', 'convertrack-click-conversion-analytics' ),
	'worse' => __( 'Got worse', 'convertrack-click-conversion-analytics' ),
);
$cvtrk_badge = static function ( $label, $tone ) {
	printf( '<span class="cvtrk-badge cvtrk-badge-%1$s"><span class="cvtrk-badge-dot"></span>%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
};
$cvtrk_live       = 'live' === $s['mode'];
$cvtrk_ready      = (int) ( isset( $cc['drafted'] ) ? $cc['drafted'] : 0 );
$cvtrk_waiting    = (int) ( isset( $cc['needs_draft'] ) ? $cc['needs_draft'] : 0 );
$cvtrk_on_page    = (int) ( isset( $cc['applied'] ) ? $cc['applied'] : 0 ) + (int) ( isset( $cc['kept'] ) ? $cc['kept'] : 0 );
$cvtrk_last_rows  = $last && ( $last['applied'] || $last['skipped'] || $last['evaluated'] );
?>
<div class="wrap convertrack" id="convertrack-gsc-autoapply">
	<?php Admin::render_header( 'keywords' ); ?>
	<?php Admin::render_subnav( 'search', 'autoapply' ); ?>

	<div class="cvtrk-page-head">
		<div class="cvtrk-page-head-text">
			<h1 class="cvtrk-page-title"><?php esc_html_e( 'Keyword Auto-Apply', 'convertrack-click-conversion-analytics' ); ?></h1>
			<p class="cvtrk-page-desc"><?php esc_html_e( 'Lets Convertrack make small search-engine improvements for you. Every change is measured afterwards and undone automatically if it did not help.', 'convertrack-click-conversion-analytics' ); ?></p>
			<span class="cvtrk-page-head-meta">
				<?php
				if ( $s['enabled'] ) {
					echo Admin::icon( 'conversions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo esc_html( $cvtrk_live ? __( 'Runs daily in Live mode', 'convertrack-click-conversion-analytics' ) : __( 'Runs daily in Dry-run mode', 'convertrack-click-conversion-analytics' ) );
				} else {
					echo Admin::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html_e( 'Automatic runs are off', 'convertrack-click-conversion-analytics' );
				}
				?>
			</span>
		</div>
	</div>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] . ( $detail ? ' ' . $detail : '' ) ); ?></p></div>
	<?php endif; ?>

	<?php
	Admin::render_guide(
		'autoapply',
		array(
			'lead'   => __( 'Keyword Opportunities finds searches your pages almost rank for. Auto-Apply acts on them in two careful ways, and keeps a record of everything so it can be undone.', 'convertrack-click-conversion-analytics' ),
			'points' => array(
				array( __( 'Title changes', 'convertrack-click-conversion-analytics' ), __( 'A missing search phrase is added to the page\'s SEO title (the headline Google shows) and set as a secondary focus keyword in Rank Math.', 'convertrack-click-conversion-analytics' ) ),
				array( __( 'Sentence suggestions', 'convertrack-click-conversion-analytics' ), __( 'One sentence per page, written to include a phrase the page is missing. Nothing is added to a page until you approve it.', 'convertrack-click-conversion-analytics' ) ),
				array( __( 'Safety net', 'convertrack-click-conversion-analytics' ), __( 'Each change is measured against Google after a waiting period and reverted automatically if clicks or click-through rate drop.', 'convertrack-click-conversion-analytics' ) ),
			),
			'tip'    => __( 'Stay in Dry run until the planned changes look right. Switch to Live when you are happy for them to be written.', 'convertrack-click-conversion-analytics' ),
		)
	);
	?>

	<nav class="cvtrk-section-nav" data-cvtrk="section-nav" aria-label="<?php esc_attr_e( 'Sections on this screen', 'convertrack-click-conversion-analytics' ); ?>">
		<a href="#cvtrk-aa-status" class="is-active" aria-current="location"><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></a>
		<?php if ( $cvtrk_last_rows ) : ?>
			<a href="#cvtrk-aa-lastrun"><?php esc_html_e( 'Last run', 'convertrack-click-conversion-analytics' ); ?></a>
		<?php endif; ?>
		<a href="#cvtrk-aa-suggestions"><?php esc_html_e( 'Sentence suggestions', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-aa-live"><?php esc_html_e( 'On your pages', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-aa-log"><?php esc_html_e( 'Change log', 'convertrack-click-conversion-analytics' ); ?></a>
		<a href="#cvtrk-aa-settings"><?php esc_html_e( 'Settings', 'convertrack-click-conversion-analytics' ); ?></a>
	</nav>

	<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-status" aria-labelledby="cvtrk-aa-status-title">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="cvtrk-aa-status-title"><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'What Auto-Apply is set to do right now', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<div class="cvtrk-summary-meta cvtrk-aa-meta">
				<span class="cvtrk-summary-meta-item">
					<span class="cvtrk-summary-meta-label"><?php esc_html_e( 'Automatic runs', 'convertrack-click-conversion-analytics' ); ?></span>
					<strong class="cvtrk-summary-meta-value"><?php $s['enabled'] ? $cvtrk_badge( __( 'On, once a day', 'convertrack-click-conversion-analytics' ), 'green' ) : $cvtrk_badge( __( 'Off', 'convertrack-click-conversion-analytics' ), 'gray' ); ?></strong>
				</span>
				<span class="cvtrk-summary-meta-item">
					<span class="cvtrk-summary-meta-label"><?php esc_html_e( 'Mode', 'convertrack-click-conversion-analytics' ); ?><?php echo Admin::help( $cvtrk_live ? 'liveRun' : 'dryRun' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<strong class="cvtrk-summary-meta-value"><?php $cvtrk_live ? $cvtrk_badge( __( 'Live: changes are written', 'convertrack-click-conversion-analytics' ), 'green' ) : $cvtrk_badge( __( 'Dry run: nothing is changed', 'convertrack-click-conversion-analytics' ), 'amber' ); ?></strong>
				</span>
				<span class="cvtrk-summary-meta-item">
					<span class="cvtrk-summary-meta-label"><?php esc_html_e( 'Next run', 'convertrack-click-conversion-analytics' ); ?></span>
					<strong class="cvtrk-summary-meta-value"><?php echo $next && $s['enabled'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) : esc_html__( 'Not scheduled', 'convertrack-click-conversion-analytics' ); ?></strong>
				</span>
				<span class="cvtrk-summary-meta-item">
					<span class="cvtrk-summary-meta-label"><?php esc_html_e( 'Last run', 'convertrack-click-conversion-analytics' ); ?></span>
					<strong class="cvtrk-summary-meta-value">
						<?php
						if ( $last ) {
							$cvtrk_trigger = isset( $cvtrk_trigger_labels[ $last['trigger'] ] ) ? $cvtrk_trigger_labels[ $last['trigger'] ] : (string) $last['trigger'];
							echo esc_html( $cvtrk_datetime( $last['time'] ) . ' · ' . ( $last['dry_run'] ? __( 'Dry run', 'convertrack-click-conversion-analytics' ) : __( 'Live', 'convertrack-click-conversion-analytics' ) ) . ' · ' . $cvtrk_trigger );
						} else {
							esc_html_e( 'Never', 'convertrack-click-conversion-analytics' );
						}
						?>
					</strong>
				</span>
			</div>
			<?php if ( $last ) : ?>
				<p class="cvtrk-aa-outcome">
					<?php
					if ( $last['blocked'] ) {
						/* translators: %s: reason the run did not happen. */
						echo esc_html( sprintf( __( 'Did not run: %s', 'convertrack-click-conversion-analytics' ), $last['blocked'] ) );
					} else {
						echo esc_html(
							sprintf(
								/* translators: 1: number of changes, 2: "planned" or "applied", 3: pages skipped, 4: earlier changes measured. */
								__( '%1$d changes %2$s · %3$d pages skipped · %4$d earlier changes measured', 'convertrack-click-conversion-analytics' ),
								count( $last['applied'] ),
								$last['dry_run'] ? __( 'planned', 'convertrack-click-conversion-analytics' ) : __( 'applied', 'convertrack-click-conversion-analytics' ),
								count( $last['skipped'] ),
								count( $last['evaluated'] )
							)
						);
					}
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvtrk-aa-actions">
				<?php wp_nonce_field( 'convertrack_autoapply_run' ); ?>
				<input type="hidden" name="action" value="convertrack_autoapply_run" />
				<span class="cvtrk-aa-action">
					<button class="button" name="live" value="0"><?php esc_html_e( 'Dry run now', 'convertrack-click-conversion-analytics' ); ?></button>
					<small><?php esc_html_e( 'See what would change. Nothing is written.', 'convertrack-click-conversion-analytics' ); ?></small>
				</span>
				<span class="cvtrk-aa-action">
					<button class="button button-primary" name="live" value="1" onclick="return confirm('<?php echo esc_js( __( 'Write SEO title and focus keyword changes now?', 'convertrack-click-conversion-analytics' ) ); ?>');"><?php esc_html_e( 'Run live now', 'convertrack-click-conversion-analytics' ); ?></button>
					<small><?php esc_html_e( 'Write the changes to your pages. Each one can be undone below.', 'convertrack-click-conversion-analytics' ); ?></small>
				</span>
			</form>
		</div>
	</section>

	<?php if ( $cvtrk_last_rows ) : ?>
		<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-lastrun" aria-labelledby="cvtrk-aa-lastrun-title">
			<div class="cvtrk-card-head">
				<div>
					<h2 id="cvtrk-aa-lastrun-title"><?php echo $last['dry_run'] ? esc_html__( 'Last run: planned changes', 'convertrack-click-conversion-analytics' ) : esc_html__( 'Last run: applied changes', 'convertrack-click-conversion-analytics' ); ?></h2>
					<span class="cvtrk-card-sub"><?php echo $last['dry_run'] ? esc_html__( 'What a live run would have written. Nothing was changed.', 'convertrack-click-conversion-analytics' ) : esc_html__( 'What the last live run wrote to your pages', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
			<div class="cvtrk-card-body">
				<div class="cvtrk-table-wrap">
					<table class="cvtrk-table cvtrk-aa-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Keyword added', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Title before', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Title after', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
						<tbody>
						<?php if ( ! $last['applied'] ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No page qualified this run.', 'convertrack-click-conversion-analytics' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $last['applied'] as $p ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $p['post_id'] ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a></td>
								<td><strong><?php echo esc_html( $p['keyword'] ); ?></strong><span class="cvtrk-cell-sub"><?php echo esc_html( sprintf( /* translators: 1: impressions, 2: CTR, 3: position. */ __( '%1$d impressions · %2$s%% click-through · position %3$s', 'convertrack-click-conversion-analytics' ), $p['kw_stats']['impressions'], $p['kw_stats']['ctr'], $p['kw_stats']['position'] ) ); ?></span></td>
								<td class="cvtrk-aa-text"><?php echo esc_html( $p['before']['title'] ); ?></td>
								<td class="cvtrk-aa-text"><strong><?php echo esc_html( $p['after']['title'] ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $last['evaluated'] ) : ?>
					<h3 class="cvtrk-aa-subhead"><?php esc_html_e( 'Earlier changes measured this run', 'convertrack-click-conversion-analytics' ); ?></h3>
					<div class="cvtrk-table-wrap">
						<table class="cvtrk-table cvtrk-aa-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Clicks before → after', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Click-through before → after', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $last['evaluated'] as $e ) : ?>
								<?php
								$cvtrk_verdict = isset( $cvtrk_verdict_labels[ $e['verdict'] ] ) ? $cvtrk_verdict_labels[ $e['verdict'] ] : (string) $e['verdict'];
								if ( 'none' !== $e['action'] ) {
									$cvtrk_verdict .= ' (' . ( 'reverted' === $e['action'] ? __( 'reverted automatically', 'convertrack-click-conversion-analytics' ) : (string) $e['action'] ) . ')';
								}
								?>
								<tr><td><?php echo esc_html( $e['title'] ); ?></td><td class="cvtrk-num"><?php echo esc_html( $e['baseline']['clicks'] . ' → ' . $e['after']['clicks'] ); ?></td><td class="cvtrk-num"><?php echo esc_html( $e['baseline']['ctr'] . '% → ' . $e['after']['ctr'] . '%' ); ?></td><td><?php $cvtrk_badge( $cvtrk_verdict, 'worse' === $e['verdict'] ? 'red' : 'green' ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( $last['skipped'] ) : ?>
					<details class="cvtrk-advanced-disclosure cvtrk-aa-disclosure"><summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Pages skipped, and why (%d)', 'convertrack-click-conversion-analytics' ), count( $last['skipped'] ) ) ); ?></summary>
						<div class="cvtrk-table-wrap">
							<table class="cvtrk-table cvtrk-aa-table"><tbody>
							<?php foreach ( $last['skipped'] as $k ) : ?>
								<tr><td><?php echo esc_html( $k['title'] ); ?></td><td class="cvtrk-aa-text"><?php echo esc_html( $k['reason'] ); ?></td></tr>
							<?php endforeach; ?>
							</tbody></table>
						</div>
					</details>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-suggestions" aria-labelledby="content-review">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="content-review"><?php esc_html_e( 'Sentence suggestions', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'One sentence per page, added to the end of an existing paragraph. Each keyword is checked as missing on the live page first. Nothing is added until you approve it.', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
			<span class="cvtrk-badge-group cvtrk-aa-counts">
				<?php $cvtrk_badge( sprintf( /* translators: %d: count */ _n( '%d ready for your review', '%d ready for your review', $cvtrk_ready, 'convertrack-click-conversion-analytics' ), $cvtrk_ready ), $cvtrk_ready ? 'amber' : 'gray' ); ?>
				<?php $cvtrk_badge( sprintf( /* translators: %d: count */ __( '%d waiting for a writer', 'convertrack-click-conversion-analytics' ), $cvtrk_waiting ), 'gray' ); ?>
				<?php $cvtrk_badge( sprintf( /* translators: %d: count */ __( '%d on your pages', 'convertrack-click-conversion-analytics' ), $cvtrk_on_page ), $cvtrk_on_page ? 'green' : 'gray' ); ?>
			</span>
		</div>
		<div class="cvtrk-card-body">
			<?php if ( ! $s['content_enabled'] ) : ?>
				<div class="cvtrk-notice cvtrk-notice-warning"><?php esc_html_e( 'Sentence suggestions are switched off. Turn them on in Settings at the bottom of this screen to start collecting new ones.', 'convertrack-click-conversion-analytics' ); ?></div>
			<?php endif; ?>

			<?php if ( $drafted ) : ?>
				<div class="cvtrk-table-wrap">
					<table class="cvtrk-table cvtrk-aa-table cvtrk-aa-review">
						<thead><tr><th scope="col" class="cvtrk-aa-col-page"><?php esc_html_e( 'Page and keyword', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Paragraph with the new sentence highlighted', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col" class="cvtrk-aa-col-review"><?php esc_html_e( 'Your decision', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $drafted as $it ) : ?>
							<tr>
								<td class="cvtrk-aa-col-page">
									<a href="<?php echo esc_url( (string) get_permalink( (int) $it['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></a>
									<strong class="cvtrk-aa-keyword"><?php echo esc_html( $it['keyword'] ); ?></strong>
									<span class="cvtrk-cell-sub"><?php echo esc_html( sprintf( /* translators: 1: impressions, 2: position. */ __( '%1$d impressions · position %2$s', 'convertrack-click-conversion-analytics' ), isset( $it['kw_stats']['impressions'] ) ? $it['kw_stats']['impressions'] : 0, isset( $it['kw_stats']['position'] ) ? $it['kw_stats']['position'] : '' ) ); ?></span>
								</td>
								<td class="cvtrk-aa-text"><?php echo wp_kses_post( $it['paragraph_text'] ); ?> <mark><?php echo esc_html( $it['draft'] ); ?></mark></td>
								<td class="cvtrk-aa-col-review">
									<div class="cvtrk-aa-decide">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'convertrack_autoapply_content_approve' ); ?>
											<input type="hidden" name="action" value="convertrack_autoapply_content_approve" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
											<button class="button button-primary"><?php esc_html_e( 'Approve & add to page', 'convertrack-click-conversion-analytics' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'convertrack_autoapply_content_reject' ); ?>
											<input type="hidden" name="action" value="convertrack_autoapply_content_reject" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
											<button class="button"><?php esc_html_e( 'Reject', 'convertrack-click-conversion-analytics' ); ?></button>
										</form>
									</div>
									<details class="cvtrk-aa-edit"><summary><?php esc_html_e( 'Change the wording', 'convertrack-click-conversion-analytics' ); ?></summary>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'convertrack_autoapply_content_edit' ); ?>
											<input type="hidden" name="action" value="convertrack_autoapply_content_edit" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
											<textarea name="draft" rows="3"><?php echo esc_textarea( $it['draft'] ); ?></textarea>
											<button class="button button-small"><?php esc_html_e( 'Save & re-check', 'convertrack-click-conversion-analytics' ); ?></button>
										</form>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="cvtrk-empty"><?php echo Admin::icon( 'keywords' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p><?php esc_html_e( 'Nothing is waiting for your review right now. New suggestions appear here after the next run.', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $waiting ) : ?>
				<details class="cvtrk-advanced-disclosure cvtrk-aa-disclosure"><summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Waiting for a writer (%d): keywords found, sentence not written yet', 'convertrack-click-conversion-analytics' ), count( $waiting ) ) ); ?></summary>
					<div class="cvtrk-table-wrap">
						<table class="cvtrk-table cvtrk-aa-table"><tbody>
						<?php foreach ( $waiting as $it ) : ?>
							<tr><td><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></td><td><strong><?php echo esc_html( $it['keyword'] ); ?></strong></td><td class="cvtrk-aa-text"><?php echo esc_html( $it['note'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					</div>
				</details>
			<?php endif; ?>
		</div>
	</section>

	<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-live" aria-labelledby="cvtrk-aa-live-title">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="cvtrk-aa-live-title"><?php esc_html_e( 'Sentences on your pages', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'Approved sentences that were added to a page, and the ones taken out again', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<?php if ( $c_active ) : ?>
				<div class="cvtrk-table-wrap">
					<table class="cvtrk-table cvtrk-aa-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Added', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Sentence', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $c_active as $it ) : ?>
							<?php $cvtrk_st = (string) $it['status']; ?>
							<tr>
								<td class="cvtrk-aa-when"><?php echo esc_html( $cvtrk_datetime( $it['applied_at'] ) ); ?></td>
								<td><a href="<?php echo esc_url( (string) get_permalink( (int) $it['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $it['post_id'] ) ); ?></a><span class="cvtrk-cell-sub"><?php echo esc_html( $it['keyword'] ); ?></span></td>
								<td class="cvtrk-aa-text"><?php echo esc_html( $it['draft'] ); ?></td>
								<td><?php $cvtrk_badge( isset( $cvtrk_status_labels[ $cvtrk_st ] ) ? $cvtrk_status_labels[ $cvtrk_st ] : $cvtrk_st, isset( $cvtrk_status_tones[ $cvtrk_st ] ) ? $cvtrk_status_tones[ $cvtrk_st ] : 'gray' ); ?></td>
								<td>
									<?php if ( in_array( $cvtrk_st, array( 'applied', 'kept' ), true ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvtrk-aa-undo">
											<?php wp_nonce_field( 'convertrack_autoapply_content_undo' ); ?>
											<input type="hidden" name="action" value="convertrack_autoapply_content_undo" /><input type="hidden" name="item" value="<?php echo (int) $it['id']; ?>" />
											<button class="button button-small"><?php esc_html_e( 'Undo', 'convertrack-click-conversion-analytics' ); ?></button>
											<label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'Undo even if the page was edited since', 'convertrack-click-conversion-analytics' ); ?></label>
										</form>
									<?php else : ?>
										<span class="cvtrk-cell-note"><?php esc_html_e( 'Nothing to undo', 'convertrack-click-conversion-analytics' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="cvtrk-empty"><?php echo Admin::icon( 'pages' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p><?php esc_html_e( 'No sentences have been added to a page yet. Approve a suggestion above and it will show up here.', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-log" aria-labelledby="cvtrk-aa-log-title">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="cvtrk-aa-log-title"><?php esc_html_e( 'Change log', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'SEO title changes made by Auto-Apply, newest first. Undo puts the previous title back.', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<?php if ( ! $log ) : ?>
				<div class="cvtrk-empty"><?php echo Admin::icon( 'keywords' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p><?php esc_html_e( 'No live changes yet. Dry runs are not listed here because they change nothing.', 'convertrack-click-conversion-analytics' ); ?></p></div>
			<?php else : ?>
				<div class="cvtrk-table-wrap">
					<table class="cvtrk-table cvtrk-aa-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'When', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'What happened', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Page', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Keyword', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Title before → after', 'convertrack-click-conversion-analytics' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'convertrack-click-conversion-analytics' ); ?></th></tr></thead>
						<tbody>
						<?php $shown = array(); ?>
						<?php foreach ( $log as $row ) : ?>
							<?php $cvtrk_ev = (string) $row['event']; ?>
							<tr>
								<td class="cvtrk-aa-when"><?php echo esc_html( $cvtrk_datetime( $row['time'] ) ); ?></td>
								<td><?php $cvtrk_badge( isset( $cvtrk_event_labels[ $cvtrk_ev ] ) ? $cvtrk_event_labels[ $cvtrk_ev ] : ucfirst( str_replace( '_', ' ', $cvtrk_ev ) ), isset( $cvtrk_event_tones[ $cvtrk_ev ] ) ? $cvtrk_event_tones[ $cvtrk_ev ] : 'gray' ); ?></td>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['post_id'] ) ); ?></a></td>
								<td><?php echo esc_html( $row['keyword'] ); ?></td>
								<td class="cvtrk-aa-text"><span class="cvtrk-aa-before"><?php echo esc_html( $row['before'] ); ?></span><span class="cvtrk-aa-after">→ <strong><?php echo esc_html( $row['after'] ); ?></strong></span></td>
								<td>
									<?php if ( ! empty( $active[ $row['post_id'] ] ) && empty( $shown[ $row['post_id'] ] ) ) : ?>
										<?php $shown[ $row['post_id'] ] = true; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvtrk-aa-undo">
											<?php wp_nonce_field( 'convertrack_autoapply_undo' ); ?>
											<input type="hidden" name="action" value="convertrack_autoapply_undo" />
											<input type="hidden" name="post_id" value="<?php echo (int) $row['post_id']; ?>" />
											<button class="button button-small"><?php esc_html_e( 'Undo', 'convertrack-click-conversion-analytics' ); ?></button>
											<label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'Undo even if edited since', 'convertrack-click-conversion-analytics' ); ?></label>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="cvtrk-card cvtrk-section" id="cvtrk-aa-settings" aria-labelledby="cvtrk-aa-settings-title">
		<div class="cvtrk-card-head">
			<div>
				<h2 id="cvtrk-aa-settings-title"><?php esc_html_e( 'Settings', 'convertrack-click-conversion-analytics' ); ?></h2>
				<span class="cvtrk-card-sub"><?php esc_html_e( 'How often Auto-Apply runs, what it may change, and the safety limits', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<div class="cvtrk-card-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'convertrack_autoapply_save' ); ?>
				<input type="hidden" name="action" value="convertrack_autoapply_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic runs', 'convertrack-click-conversion-analytics' ); ?></th>
						<td><label><input type="checkbox" name="autoapply[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Run once a day without me', 'convertrack-click-conversion-analytics' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<select name="autoapply[mode]">
								<option value="dry_run" <?php selected( $s['mode'], 'dry_run' ); ?>><?php esc_html_e( 'Dry run: plan only, nothing changes', 'convertrack-click-conversion-analytics' ); ?></option>
								<option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Live: write the changes', 'convertrack-click-conversion-analytics' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What may change', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<label><input type="checkbox" name="autoapply[apply_title]" value="1" <?php checked( $s['apply_title'] ); ?> /> <?php esc_html_e( 'The SEO title (the headline Google shows)', 'convertrack-click-conversion-analytics' ); ?></label><br />
							<label><input type="checkbox" name="autoapply[apply_focus]" value="1" <?php checked( $s['apply_focus'] ); ?> /> <?php esc_html_e( 'The secondary focus keyword in Rank Math', 'convertrack-click-conversion-analytics' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search data to use', 'convertrack-click-conversion-analytics' ); ?></th>
						<td>
							<?php $cvtrk_ranges = array( '7d' => __( 'Last 7 days', 'convertrack-click-conversion-analytics' ), '28d' => __( 'Last 28 days', 'convertrack-click-conversion-analytics' ), '3m' => __( 'Last 3 months', 'convertrack-click-conversion-analytics' ) ); ?>
							<select name="autoapply[range]"><?php foreach ( $cvtrk_ranges as $r => $cvtrk_range_label ) : ?><option value="<?php echo esc_attr( $r ); ?>" <?php selected( $s['range'], $r ); ?>><?php echo esc_html( $cvtrk_range_label ); ?></option><?php endforeach; ?></select>
							<p class="description"><?php esc_html_e( 'A longer range is steadier; a shorter one reacts faster.', 'convertrack-click-conversion-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits per run', 'convertrack-click-conversion-analytics' ); ?></th>
						<td class="cvtrk-aa-fields">
							<label><?php esc_html_e( 'Change at most', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'max_per_run', 1, 50 ); ?> <?php esc_html_e( 'pages', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Only keywords with at least', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'min_impressions', 1, 100000 ); ?> <?php esc_html_e( 'impressions', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Only keywords ranking at position', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'max_position', 1, 20 ); ?> <?php esc_html_e( 'or better', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Keep titles under', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'title_max', 40, 80 ); ?> <?php esc_html_e( 'characters', 'convertrack-click-conversion-analytics' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Safety', 'convertrack-click-conversion-analytics' ); ?></th>
						<td class="cvtrk-aa-fields">
							<label><?php esc_html_e( 'Wait', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'cooldown_days', 7, 365 ); ?> <?php esc_html_e( 'days before changing the same page again', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Measure the result after', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'eval_days', 14, 120 ); ?> <?php esc_html_e( 'days', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><input type="checkbox" name="autoapply[auto_revert]" value="1" <?php checked( $s['auto_revert'] ); ?> /> <?php esc_html_e( 'Undo a change automatically when clicks or click-through fall by more than', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'revert_drop', 5, 90 ); ?>%</label>
							<label><input type="checkbox" name="autoapply[exclude_front]" value="1" <?php checked( $s['exclude_front'] ); ?> /> <?php esc_html_e( 'Never change the front page', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Never change these pages (post IDs, separated by commas):', 'convertrack-click-conversion-analytics' ); ?> <input type="text" name="autoapply[exclude_ids]" value="<?php echo esc_attr( implode( ', ', (array) $s['exclude_ids'] ) ); ?>" class="regular-text" /></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sentence suggestions', 'convertrack-click-conversion-analytics' ); ?></th>
						<td class="cvtrk-aa-fields">
							<label><input type="checkbox" name="autoapply[content_enabled]" value="1" <?php checked( $s['content_enabled'] ); ?> /> <?php esc_html_e( 'Suggest a one-sentence addition for keywords a page is missing (added only after you approve it)', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Only keywords with at least', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_min_impressions', 1, 100000 ); ?> <?php esc_html_e( 'impressions', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'Only keywords ranking at position', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_max_position', 1, 100 ); ?> <?php esc_html_e( 'or better', 'convertrack-click-conversion-analytics' ); ?></label>
							<label><?php esc_html_e( 'New suggestions per run:', 'convertrack-click-conversion-analytics' ); ?> <?php $num( 'content_max_per_scan', 1, 50 ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'convertrack-click-conversion-analytics' ) ); ?>
			</form>
		</div>
	</section>
</div>
