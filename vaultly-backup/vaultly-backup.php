<?php
/**
 * Plugin Name: Vaultly Backup
 * Description: Fast, resumable full-site backup and restore (database + wp-content) with live progress, verify, scheduling and an activity log.
 * Version: 1.8.9
 * Requires at least: 7.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vaultly-backup
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.DB.DirectDatabaseQuery,Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.WriteFile -- backup engine: streams multi-GB archives in resumable chunks inside its own wp-content/vaultly-backups folder; WP_Filesystem cannot stream or append. Exception messages go to the admin JSON/log (never echoed as HTML), and are translated, trusted strings.

define( 'VAULTLY_FILE', __FILE__ );
define( 'VAULTLY_BASENAME', plugin_basename( __FILE__ ) );
define( 'VAULTLY_VERSION', '1.8.9' );

require_once __DIR__ . '/includes/class-vaultly-zip.php';
require_once __DIR__ . '/includes/class-vaultly-pack.php';
require_once __DIR__ . '/includes/class-vaultly-db.php';
require_once __DIR__ . '/includes/class-vaultly-job.php';
if ( is_readable( __DIR__ . '/includes/class-vaultly-updater.php' ) ) { // GitHub build only
	require_once __DIR__ . '/includes/class-vaultly-updater.php';
}

final class VAULTLY_Admin {

	public static function init(): void {
		if ( is_multisite() ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Vaultly Backup supports single-site installs only.', 'vaultly-backup' ) . '</p></div>';
			} );
			return;
		}
		register_activation_hook( VAULTLY_FILE, array( 'VAULTLY_Job', 'activate' ) );
		register_deactivation_hook( VAULTLY_FILE, function () {
			wp_clear_scheduled_hook( 'vaultly_cron' );
			wp_clear_scheduled_hook( 'vaultly_cron_continue' );
		} );
		add_action( 'vaultly_cron', array( 'VAULTLY_Job', 'run_scheduled' ) );
		add_action( 'vaultly_cron_continue', array( 'VAULTLY_Job', 'continue_scheduled' ) ); // next slice of a scheduled backup that did not fit in one cron request
		add_action( 'admin_init', array( 'VAULTLY_Job', 'ensure_schedule' ) ); // restore replaces the DB (and the cron option): re-arm (admin only: no disk access on front-end requests)
		add_action( 'plugins_loaded', function () { // add-ons hook this to know the engine is ready (they load after this plugin, so it cannot fire at include time)
			do_action( 'vaultly_loaded', VAULTLY_VERSION );
		} );

		add_action( 'admin_menu', function () {
			add_menu_page( 'Vaultly Backup', 'Vaultly Backup', 'manage_options', 'vaultly-backup', array( __CLASS__, 'page' ), self::menu_icon(), 65 );
		} );
		add_action( 'admin_enqueue_scripts', function () { // the coloured menu icon is an <img>: WordPress dims it to 60%, show it at full strength
			wp_add_inline_style( 'admin-menu', '#toplevel_page_vaultly-backup .wp-menu-image img{opacity:1;padding:6px 0;width:20px;height:20px}' );
		} );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'start', 'cancel', 'delete', 'upload', 'upload_status', 'dl_link', 'settings' ) as $a ) {
			add_action( "wp_ajax_vaultly_$a", array( __CLASS__, "ajax_$a" ) );
		}
		// 'step' must also work without a session: restore replaces wp_users / session tokens mid-job.
		// Protected by a random per-job token; without a session only the late restore phases are accepted.
		add_action( 'wp_ajax_vaultly_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_nopriv_vaultly_step', array( __CLASS__, 'ajax_step' ) );
	}

	/* ---------- UI ---------- */

	/** Tags allowed inside translated help texts (wp_kses). */
	const HTML = array( 'b' => array(), 'strong' => array(), 'code' => array(), 'small' => array() );

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_vaultly-backup' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'vaultly', plugins_url( 'assets/admin.css', VAULTLY_FILE ), array(), VAULTLY_VERSION );
		wp_enqueue_script( 'vaultly', plugins_url( 'assets/admin.js', VAULTLY_FILE ), array(), VAULTLY_VERSION, true );
		wp_localize_script( 'vaultly', 'VAULTLY', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'vaultly' ),
			'chunk' => min( 8 << 20, wp_max_upload_size() ),
			'resume' => self::resumable(),
			'permalinks' => admin_url( 'options-permalink.php' ),
			'i18n'  => array(
				// translators: %s: HTTP status code
				'serverError' => __( 'Server error (HTTP %s)', 'vaultly-backup' ),
				'failed'      => __( 'Request failed', 'vaultly-backup' ),
				// translators: 1: minutes, 2: seconds
				'min'         => __( '%1$d min %2$d s', 'vaultly-backup' ),
				// translators: %d: seconds
				'sec'         => __( '%d s', 'vaultly-backup' ),
				// translators: %s: elapsed time
				'elapsed'     => __( '- elapsed %s', 'vaultly-backup' ),
				// translators: %s: remaining time
				'left'        => __( ', about %s left', 'vaultly-backup' ),
				'okBackup'    => __( 'Your backup has been created successfully!', 'vaultly-backup' ),
				'okRestore'   => __( 'Your data has been restored successfully!', 'vaultly-backup' ),
				'okVerify'    => __( 'Your backup is healthy!', 'vaultly-backup' ),
				'done'        => __( 'Done!', 'vaultly-backup' ),
				'permalinksT' => __( 'Permalinks Settings', 'vaultly-backup' ),
				'saveTwice'   => __( 'You must save your permalinks structure twice.', 'vaultly-backup' ),
				'newWindow'   => __( '(opens in a new window)', 'vaultly-backup' ),
				'clearCache'  => __( 'Clear your browser cache and any caching plugin, then check the site.', 'vaultly-backup' ),
				'twoSteps'    => __( 'You need to perform two more steps:', 'vaultly-backup' ),
				'download'    => __( 'Download', 'vaultly-backup' ),
				'copy'        => __( 'Copy link', 'vaultly-backup' ),
				'copied'      => __( 'Copied!', 'vaultly-backup' ),
				'linkNote'    => __( 'Public link, no login needed:', 'vaultly-backup' ),
				'close'       => __( 'Close', 'vaultly-backup' ),
				'cancelled'   => __( 'Cancelled.', 'vaultly-backup' ),
				'starting'    => __( 'Starting...', 'vaultly-backup' ),
				// translators: %s: file name
				'uploading'   => __( 'Uploading %s', 'vaultly-backup' ),
				// translators: 1: uploaded MB, 2: total MB
				'mbOf'        => __( '%1$d MB of %2$d MB', 'vaultly-backup' ),
				'uploaded'    => __( 'Upload complete.', 'vaultly-backup' ),
				'scopeFull'   => __( 'the database AND files', 'vaultly-backup' ),
				'scopeDb'     => __( 'the database', 'vaultly-backup' ),
				'scopeFiles'  => __( 'the site files', 'vaultly-backup' ),
				'scopeSite'   => __( 'this site', 'vaultly-backup' ),
				// translators: 1: backup file name, 2: what is replaced (e.g. the database)
				'confirmRestore' => __( 'Restore "%1$s"?\n\nThis REPLACES %2$s of this site. A pre-restore backup is made first.', 'vaultly-backup' ),
				// translators: %s: backup file name
				'confirmDelete'  => __( 'Delete "%s"?', 'vaultly-backup' ),
				'cancelBtn'   => __( 'Cancel', 'vaultly-backup' ),
				'restoreBtn'  => __( 'Restore', 'vaultly-backup' ),
				'deleteBtn'   => __( 'Delete', 'vaultly-backup' ),
				'saved'       => __( 'Saved.', 'vaultly-backup' ),
				'resuming'    => __( 'Resuming...', 'vaultly-backup' ),
				't_backup'    => __( 'Creating backup', 'vaultly-backup' ),
				't_restore'   => __( 'Restoring backup', 'vaultly-backup' ),
				't_verify'    => __( 'Verifying backup', 'vaultly-backup' ),
			),
		) );
	}

	/** A job started from a browser that was closed/reloaded: the page offers to keep driving it. */
	private static function resumable(): ?array {
		$j = VAULTLY_Job::load();
		return $j && 'scheduler' !== $j['by'] && time() - $j['updated'] < 600 ? array( 'token' => $j['token'] ) : null;
	}

	private static function menu_icon(): string {
		$f = dirname( VAULTLY_FILE ) . '/assets/menu-icon.svg';
		return is_readable( $f ) ? plugins_url( 'assets/menu-icon.svg', VAULTLY_FILE ) : 'dashicons-backup'; // a URL (not a data: URI) keeps the colours: WordPress only recolours data: icons
	}

	private static function badge( string $text, string $cls = '' ): string {
		return '<span class="vaultly-badge ' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span> ';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'vaultly-backup' ), 403 );
		}
		VAULTLY_Job::sweep();
		$rows   = VAULTLY_Job::list_backups();
		$set    = VAULTLY_Job::settings();
		$total  = array_sum( array_column( $rows, 'size' ) );
		$free   = @disk_free_space( VAULTLY_Job::dir() );
		$last   = null;
		foreach ( $rows as $r ) {
			if ( 'pre-restore' !== $r['kind'] ) {
				$last = $r;
				break;
			}
		}
		$next   = wp_next_scheduled( 'vaultly_cron' );
		$scopes = array( 'full' => __( 'Full', 'vaultly-backup' ), 'db' => __( 'Database', 'vaultly-backup' ), 'files' => __( 'Files', 'vaultly-backup' ), '?' => '?' );
		$kinds  = array( 'manual' => __( 'Manual', 'vaultly-backup' ), 'scheduled' => __( 'Scheduled', 'vaultly-backup' ), 'pre-restore' => __( 'Pre-restore', 'vaultly-backup' ), 'invalid' => __( 'Invalid', 'vaultly-backup' ) );
		?>
		<div class="wrap vaultly">
			<h1><img class="vaultly-logo" src="<?php echo esc_url( plugins_url( 'assets/icon.svg', VAULTLY_FILE ) ); ?>" alt="" width="34" height="34"> Vaultly Backup <span class="vaultly-ver">v<?php echo esc_html( VAULTLY_VERSION ); ?></span></h1>

			<div class="vaultly-cards">
				<div class="vaultly-card"><span><?php esc_html_e( 'Backups', 'vaultly-backup' ); ?></span><strong><?php echo esc_html( count( $rows ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: %s: total size */ __( '%s stored', 'vaultly-backup' ), size_format( $total ) ?: '0 B' ) ); ?></em></div>
				<div class="vaultly-card <?php echo $last ? '' : 'vaultly-warn'; ?>"><span><?php esc_html_e( 'Last backup', 'vaultly-backup' ); ?></span><strong><?php echo $last ? esc_html( sprintf( /* translators: %s: time span, e.g. 2 hours */ __( '%s ago', 'vaultly-backup' ), human_time_diff( $last['time'] ) ) ) : esc_html__( 'Never', 'vaultly-backup' ); ?></strong><em><?php echo $last ? esc_html( $last['name'] ) : esc_html__( 'Create your first backup', 'vaultly-backup' ); ?></em></div>
				<div class="vaultly-card"><span><?php esc_html_e( 'Schedule', 'vaultly-backup' ); ?></span><strong><?php echo 'off' === $set['sched'] ? esc_html__( 'Off', 'vaultly-backup' ) : esc_html( 'daily' === $set['sched'] ? __( 'Daily', 'vaultly-backup' ) : __( 'Weekly', 'vaultly-backup' ) ); ?></strong><em><?php echo $next ? esc_html( sprintf( /* translators: %s: date and time */ __( 'Next: %s', 'vaultly-backup' ), wp_date( 'M j, H:i', $next ) ) ) : esc_html__( 'No automatic backups', 'vaultly-backup' ); ?></em></div>
				<div class="vaultly-card"><span><?php esc_html_e( 'Free disk', 'vaultly-backup' ); ?></span><strong><?php echo false !== $free ? esc_html( size_format( $free ) ) : esc_html__( 'n/a', 'vaultly-backup' ); ?></strong><em><?php esc_html_e( 'on the backup volume', 'vaultly-backup' ); ?></em></div>
			</div>

			<nav class="vaultly-tabs" role="tablist">
				<button class="vaultly-tab is-active" data-tab="backups" role="tab"><?php esc_html_e( 'Backups', 'vaultly-backup' ); ?></button>
				<button class="vaultly-tab" data-tab="settings" role="tab"><?php esc_html_e( 'Schedule & settings', 'vaultly-backup' ); ?></button>
				<button class="vaultly-tab" data-tab="activity" role="tab"><?php esc_html_e( 'Activity', 'vaultly-backup' ); ?></button>
			</nav>

			<section class="vaultly-panel" id="vaultly-tab-backups">
				<div class="vaultly-actions">
					<fieldset class="vaultly-scope">
						<label><input type="radio" name="vaultly-scope" value="full" checked> <b><?php esc_html_e( 'Full site', 'vaultly-backup' ); ?></b> <small><?php esc_html_e( 'database + files', 'vaultly-backup' ); ?></small></label>
						<label><input type="radio" name="vaultly-scope" value="db"> <b><?php esc_html_e( 'Database only', 'vaultly-backup' ); ?></b> <small><?php esc_html_e( '.sql file', 'vaultly-backup' ); ?></small></label>
						<label><input type="radio" name="vaultly-scope" value="files"> <b><?php esc_html_e( 'Files only', 'vaultly-backup' ); ?></b> <small><?php esc_html_e( 'whole site folder, .zip', 'vaultly-backup' ); ?></small></label>
					</fieldset>
					<button class="button button-primary button-hero" id="vaultly-backup"><?php esc_html_e( 'Create backup', 'vaultly-backup' ); ?></button>
					<label class="button button-hero" for="vaultly-file"><?php esc_html_e( 'Upload backup…', 'vaultly-backup' ); ?></label>
					<input type="file" id="vaultly-file" accept=".vlt,.vaultly,.sql,.zip" hidden>
				</div>

				<div id="vaultly-link" class="vaultly-progress" hidden>
					<p><?php echo wp_kses( __( '<b>Public download link.</b> Anyone who has this link can download the backup without logging in. Keep it private.', 'vaultly-backup' ), self::HTML ); ?></p>
					<p><input type="text" readonly class="large-text code" id="vaultly-link-url"> <button class="button" id="vaultly-copy"><?php esc_html_e( 'Copy link', 'vaultly-backup' ); ?></button> <a class="button" id="vaultly-link-go" href="#"><?php esc_html_e( 'Download again', 'vaultly-backup' ); ?></a></p>
				</div>

				<table class="widefat striped vaultly-table">
					<thead><tr><th><?php esc_html_e( 'Backup', 'vaultly-backup' ); ?></th><th><?php esc_html_e( 'Size', 'vaultly-backup' ); ?></th><th><?php esc_html_e( 'Created', 'vaultly-backup' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="4" class="vaultly-empty"><?php echo wp_kses( __( 'No backups yet. Click <b>Create backup</b> to make your first one.', 'vaultly-backup' ), self::HTML ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $r['name'] ); ?></code><br>
								<?php
								echo self::badge( $kinds[ $r['kind'] ] ?? $r['kind'], 'k-' . $r['kind'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge()
								echo self::badge( $scopes[ $r['scope'] ] ?? '?', 's-' . $r['scope'] ); // phpcs:ignore WordPress.Security.EscapeOutput
								?>
							</td>
							<td><?php echo esc_html( size_format( $r['size'] ) ); ?></td>
							<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', $r['time'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: time span, e.g. 2 hours */ __( '%s ago', 'vaultly-backup' ), human_time_diff( $r['time'] ) ) ); ?></td>
							<td class="vaultly-row">
								<button class="button vaultly-dl" data-name="<?php echo esc_attr( $r['name'] ); ?>"><?php esc_html_e( 'Download', 'vaultly-backup' ); ?></button>
								<button class="button vaultly-verify" data-name="<?php echo esc_attr( $r['name'] ); ?>"><?php esc_html_e( 'Verify', 'vaultly-backup' ); ?></button>
								<button class="button vaultly-restore" data-name="<?php echo esc_attr( $r['name'] ); ?>" data-scope="<?php echo esc_attr( $r['scope'] ); ?>"><?php esc_html_e( 'Restore', 'vaultly-backup' ); ?></button>
								<button class="button vaultly-delete" data-name="<?php echo esc_attr( $r['name'] ); ?>"><?php esc_html_e( 'Delete', 'vaultly-backup' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php echo wp_kses( __( '<b>Database only</b> gives a single <code>.sql</code> file; <b>Files only</b> gives a normal <code>.zip</code> of the whole WordPress folder (restoring it never overwrites wp-config.php). <b>Verify</b> re-reads the whole archive and checks every checksum without touching your site. Restore always makes a "pre-restore" safety backup first.', 'vaultly-backup' ), self::HTML ); ?></p>
			</section>

			<section class="vaultly-panel" id="vaultly-tab-settings" hidden>
				<form id="vaultly-settings" class="vaultly-form">
					<h2><?php esc_html_e( 'Automatic backups', 'vaultly-backup' ); ?></h2>
					<p><label><?php esc_html_e( 'Frequency', 'vaultly-backup' ); ?>
						<select name="sched">
							<option value="off" <?php selected( $set['sched'], 'off' ); ?>><?php esc_html_e( 'Off', 'vaultly-backup' ); ?></option>
							<option value="daily" <?php selected( $set['sched'], 'daily' ); ?>><?php esc_html_e( 'Daily (about 03:00)', 'vaultly-backup' ); ?></option>
							<option value="weekly" <?php selected( $set['sched'], 'weekly' ); ?>><?php esc_html_e( 'Weekly (about 03:00)', 'vaultly-backup' ); ?></option>
						</select></label>
						<label><?php esc_html_e( 'Keep newest', 'vaultly-backup' ); ?> <input type="number" name="keep" min="1" max="100" value="<?php echo esc_attr( $set['keep'] ); ?>"> <?php esc_html_e( 'scheduled backups', 'vaultly-backup' ); ?></label></p>
					<p><label><?php esc_html_e( 'E-mail me if a scheduled backup fails', 'vaultly-backup' ); ?> <input type="email" name="email" value="<?php echo esc_attr( $set['email'] ); ?>" placeholder="admin@example.com"></label></p>
					<p class="description"><?php esc_html_e( 'Runs via WP-Cron. Manual backups are never deleted automatically.', 'vaultly-backup' ); ?></p>

					<h2><?php esc_html_e( 'What to back up', 'vaultly-backup' ); ?></h2>
					<p class="description"><?php echo wp_kses( __( 'Everything in <code>wp-content</code> (uploads, plugins, themes, mu-plugins, languages, drop-ins) and every table with your table prefix is always included.', 'vaultly-backup' ), self::HTML ); ?></p>
					<p><label><input type="checkbox" name="no_cache" value="1" <?php checked( $set['no_cache'] ); ?>> <?php echo wp_kses( __( 'Skip the <code>cache</code> folder <small>(smaller + faster; it is rebuilt automatically)</small>', 'vaultly-backup' ), self::HTML ); ?></label><br>
						<label><input type="checkbox" name="no_spam" value="1" <?php checked( $set['no_spam'] ); ?>> <?php esc_html_e( 'Skip spam comments', 'vaultly-backup' ); ?></label><br>
						<label><input type="checkbox" name="no_revs" value="1" <?php checked( $set['no_revs'] ); ?>> <?php esc_html_e( 'Skip post revisions', 'vaultly-backup' ); ?></label></p>

					<h2><?php esc_html_e( 'Files', 'vaultly-backup' ); ?></h2>
					<p><label><?php esc_html_e( 'Skip single files larger than', 'vaultly-backup' ); ?> <input type="number" name="max_file_mb" min="0" value="<?php echo esc_attr( $set['max_file_mb'] ); ?>"> MB <small><?php esc_html_e( '(0 = no limit)', 'vaultly-backup' ); ?></small></label></p>

					<h2><?php esc_html_e( 'Restore', 'vaultly-backup' ); ?></h2>
					<p><label><input type="checkbox" name="safety" value="1" <?php checked( $set['safety'] ); ?>> <?php echo wp_kses( __( 'Make a safety backup of the current site before restoring <small>(turn on for a rollback copy; off restores much faster on big sites)</small>', 'vaultly-backup' ), self::HTML ); ?></label></p>

					<?php do_action( 'vaultly_settings_fields', $set ); // add-ons print their own fields here (escape in the callback); the form is submitted as a whole ?>

					<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save settings', 'vaultly-backup' ); ?></button> <span id="vaultly-saved"></span></p>
				</form>
			</section>

			<section class="vaultly-panel" id="vaultly-tab-activity" hidden>
				<table class="widefat striped vaultly-table">
					<thead><tr><th><?php esc_html_e( 'When', 'vaultly-backup' ); ?></th><th><?php esc_html_e( 'Who', 'vaultly-backup' ); ?></th><th><?php esc_html_e( 'Event', 'vaultly-backup' ); ?></th><th><?php esc_html_e( 'Detail', 'vaultly-backup' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( VAULTLY_Job::recent_log() as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $e['t'] ) ); ?></td>
							<td><?php echo esc_html( $e['u'] ); ?></td>
							<td><?php echo esc_html( str_replace( '_', ' ', $e['a'] ) ); ?></td>
							<td><?php echo esc_html( $e['d'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! VAULTLY_Job::recent_log() ) : ?>
						<tr><td colspan="4" class="vaultly-empty"><?php esc_html_e( 'No records found.', 'vaultly-backup' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</section>

			<div id="vaultly-modal" class="vaultly-modal" hidden>
				<div class="vaultly-dialog" role="alertdialog" aria-live="polite">
					<div id="vaultly-done" hidden></div>
					<div id="vaultly-run">
					<div class="vaultly-spin" id="vaultly-spin"></div>
					<h2 class="vaultly-ptitle" id="vaultly-ptitle"></h2>
					<p class="vaultly-title" id="vaultly-msg"></p>
					<div class="vaultly-bar"><div id="vaultly-bar"></div></div>
					<p class="vaultly-pct"><b id="vaultly-pct">0%</b> <span id="vaultly-eta"></span></p>
					<p class="vaultly-detail" id="vaultly-detail"></p>
					<ol class="vaultly-steps" id="vaultly-steps"></ol>
					<hr>
					<p class="vaultly-warn-txt" id="vaultly-note"><?php esc_html_e( 'Please do not close this browser window until it finishes.', 'vaultly-backup' ); ?></p>
					<p><button class="button" id="vaultly-cancel" hidden><?php esc_html_e( 'Cancel', 'vaultly-backup' ); ?></button> <button class="button button-primary" id="vaultly-close" hidden><?php esc_html_e( 'Close', 'vaultly-backup' ); ?></button></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------- AJAX ---------- */

	private static function guard(): void {
		check_ajax_referer( 'vaultly', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Forbidden', 'vaultly-backup' ), 403 );
		}
	}

	private static function post( string $k ): string {
		return sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- guard() ran
	}

	private static function run( callable $fn ): void {
		try {
			wp_send_json_success( $fn() );
		} catch ( Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	public static function ajax_start(): void {
		self::guard();
		self::run( function () {
			$type = in_array( self::post( 'type' ), array( 'restore', 'verify' ), true ) ? self::post( 'type' ) : 'backup';
			return VAULTLY_Job::start( $type, 'backup' === $type ? '' : self::post( 'archive' ), array( 'scope' => self::post( 'scope' ) ) );
		} );
	}

	public static function ajax_step(): void {
		// Auth = job token (+ capability except for the late restore phases). No nonce: the session may be gone.
		self::run( function () {
			return VAULTLY_Job::step( self::post( 'token' ), current_user_can( 'manage_options' ) );
		} );
	}

	public static function ajax_cancel(): void {
		self::guard();
		self::run( function () {
			VAULTLY_Job::cancel();
			return true;
		} );
	}

	public static function ajax_delete(): void {
		self::guard();
		self::run( function () {
			VAULTLY_Job::delete_backup( self::post( 'name' ) );
			return true;
		} );
	}

	public static function ajax_settings(): void {
		self::guard();
		self::run( function () {
			VAULTLY_Job::save_settings( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- guard() ran
			VAULTLY_Job::log( 'settings_saved' );
			return true;
		} );
	}

	private static function part_path( string $name, string $fp ): string {
		return VAULTLY_Job::dir() . '/' . VAULTLY_Job::clean_name( $name ) . '.' . substr( hash_hmac( 'sha256', preg_replace( '/[^0-9-]/', '', $fp ), VAULTLY_Job::site_key() ), 0, 16 ) . '.up';
	}

	/** Lets the browser resume an interrupted upload. */
	public static function ajax_upload_status(): void {
		self::guard();
		self::run( function () {
			$p = self::part_path( self::post( 'name' ), self::post( 'fp' ) );
			return array( 'offset' => is_file( $p ) ? filesize( $p ) : 0 );
		} );
	}

	/** Chunked, resumable upload: independent of post_max_size / upload_max_filesize. */
	public static function ajax_upload(): void {
		self::guard();
		self::run( function () {
			$name   = VAULTLY_Job::clean_name( self::post( 'name' ) );
			$offset = (int) self::post( 'offset' );
			$total  = (int) self::post( 'total' );
			$part   = self::part_path( $name, self::post( 'fp' ) );
			$f      = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- guard() ran; only tmp_name of a verified upload is used

			if ( ! $f || UPLOAD_ERR_OK !== $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
				throw new RuntimeException( __( 'Chunk upload failed.', 'vaultly-backup' ) );
			}
			$len = filesize( $f['tmp_name'] );
			if ( 0 === $offset && is_file( $part ) ) {
				wp_delete_file( $part );
			}
			$cur = is_file( $part ) ? filesize( $part ) : 0;
			if ( $offset + $len !== $cur ) { // not an already-applied retry
				if ( $offset !== $cur ) {
					throw new RuntimeException( __( 'Upload chunk out of order.', 'vaultly-backup' ) );
				}
				$in  = fopen( $f['tmp_name'], 'rb' );
				$out = fopen( $part, 'ab' );
				if ( false === stream_copy_to_stream( $in, $out ) ) {
					throw new RuntimeException( __( 'Cannot write upload (disk full?).', 'vaultly-backup' ) );
				}
				fclose( $in );
				fclose( $out );
			}
			if ( $offset + $len >= $total ) {
				try {
					VAULTLY_Job::check( $part, $name );
				} catch ( Throwable $e ) {
					wp_delete_file( $part );
					throw $e;
				}
				$final = VAULTLY_Job::dir() . '/' . VAULTLY_Job::secret_name( $name ); // the folder is web-served: the stored name always carries a fresh random id, whatever the uploader called the file
				rename( $part, $final );
				chmod( $final, 0644 );
				VAULTLY_Job::log( 'upload', basename( $final ) );
			}
			return true;
		} );
	}

	/** Direct URL of the archive file (no admin-ajax / wp-admin in it). The folder's rules only let .sql / .zip / .vlt through. */
	public static function ajax_dl_link(): void {
		self::guard();
		self::run( function () {
			$name = basename( VAULTLY_Job::archive_path( self::post( 'name' ) ) );
			@chmod( VAULTLY_Job::archive_path( $name ), 0644 ); // fixes backups made earlier with 0600: the web server must be able to read them
			VAULTLY_Job::log( 'download_link', $name );
			return array( 'url' => VAULTLY_Job::dir_url() . '/' . rawurlencode( $name ) );
		} );
	}
}

VAULTLY_Admin::init();
