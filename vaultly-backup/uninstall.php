<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- removes the plugin's own empty backup folder; WP_Filesystem is not initialised during uninstall

wp_clear_scheduled_hook( 'vaultly_cron' );
wp_clear_scheduled_hook( 'vaultly_cron_continue' );

// Everything lives in wp-content/vaultly-backups (no DB options). Backups are the user's data:
// remove the folder only when it holds no backups (.vlt / .vaultly / .sql / .zip); otherwise leave it untouched.
$vaultly_dir = WP_CONTENT_DIR . '/vaultly-backups';
if ( is_dir( $vaultly_dir ) && ! glob( $vaultly_dir . '/*.{vlt,vaultly,sql,zip}', GLOB_BRACE ) ) {
	$vaultly_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $vaultly_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $vaultly_it as $vaultly_f ) {
		$vaultly_f->isDir() && ! $vaultly_f->isLink() ? rmdir( $vaultly_f->getPathname() ) : wp_delete_file( $vaultly_f->getPathname() );
	}
	rmdir( $vaultly_dir );
}
