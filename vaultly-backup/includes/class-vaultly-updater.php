<?php
/**
 * GitHub updater. Ships ONLY in the GitHub release zip (WordPress.org forbids plugin updaters): delete this file for the WordPress.org build.
 * Release rule: tag vX.Y.Z and attach the GitHub build zip, named vaultly-backup-X.Y.Z.zip (or vaultly-backup.zip), that contains the folder vaultly-backup/.
 * The WordPress.org zip (vaultly-backup-wporg-X.Y.Z.zip) must NOT be attached: it has no updater, installing it would remove updates.
 */
defined( 'ABSPATH' ) || exit;

final class VAULTLY_Updater {
	const REPO = 'brandixsoft/vaultly-backup'; // set to the public GitHub repo, "user/repo"

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) ); // WP's own update check: fetch the release here
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'show' ) ); // every read: cached data only, so the "Enable auto-updates" link is always there
	}

	public static function show( $t ) {
		return self::check( $t, false );
	}

	public static function check( $t, bool $fetch = true ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}
		$rel = get_transient( 'vaultly_gh_release' );
		if ( false === $rel && ! $fetch ) {
			$rel = array();
		} elseif ( false === $rel ) {
			$r   = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
			$rel = ( 200 === wp_remote_retrieve_response_code( $r ) ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : array();
			set_transient( 'vaultly_gh_release', is_array( $rel ) ? $rel : array(), 6 * HOUR_IN_SECONDS );
		}
		$ver = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' );
		$zip = '';
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
			if ( preg_match( '/^vaultly-backup(-\d[\w.]*)?\.zip$/', (string) ( $a['name'] ?? '' ) ) ) { // vaultly-backup.zip or vaultly-backup-1.8.9.zip, never the -wporg- build
				$zip = (string) $a['browser_download_url'];
			}
		}
		$item = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => 'vaultly-backup',
			'plugin'      => VAULTLY_BASENAME,
			'new_version' => $ver ?: VAULTLY_VERSION,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $zip,
		);
		if ( $ver && $zip && version_compare( $ver, VAULTLY_VERSION, '>' ) ) {
			$t->response[ VAULTLY_BASENAME ] = $item;
		} else {
			unset( $t->response[ VAULTLY_BASENAME ] );
			$t->no_update[ VAULTLY_BASENAME ] = $item; // makes "Enable auto-updates" show
		}
		return $t;
	}
}
VAULTLY_Updater::init();
