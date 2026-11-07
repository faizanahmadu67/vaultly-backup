<?php
/**
 * Step-driven backup / restore / verify engine.
 *
 * A job is a list of phases. The browser (or the scheduler) calls step() repeatedly; each call
 * works for a few seconds, persists its state to a JSON file (NOT the database - restore replaces
 * the DB) and returns progress. No loopback HTTP requests: works on locked-down hosts.
 * Every phase is resumable, so no single request depends on archive size.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.DB.DirectDatabaseQuery,Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.WriteFile -- backup engine: streams multi-GB archives in resumable chunks inside its own wp-content/vaultly-backups folder; WP_Filesystem cannot stream or append. Exception messages go to the admin JSON/log (never echoed as HTML), and are translated, trusted strings.

final class VAULTLY_Job {

	const PREFIX_RE = '/^(\s*\$table_prefix\s*=\s*)([\'"])[A-Za-z0-9_]+\2\s*;/m';
	const NAME_RE ='/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(vlt|vaultly|sql|zip)$/'; // full = .vlt (.vaultly = made by 1.7.0-1.7.4), database only = .sql, files only = .zip
	const SECRET_RE = '/_[0-9a-f]{16}\.(vlt|vaultly|sql|zip)$/'; // every archive name ends in a random 64-bit id: the folder is web-served, so the name is the access key
	const STORE   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'bz2', '7z', 'rar', 'mp3', 'mp4', 'mov', 'webm', 'woff2', 'pdf' );
	const LATE    = array( 'import', 'replace', 'finish' ); // phases that run after users/sessions were replaced
	/** Phase and process names (methods, not constants: they are translated). */
	private static function label( string $p ): string {
		$l = array(
			'validate' => __( 'Verifying backup', 'vaultly-backup' ),
			'dbcopy'   => __( 'Checking database export', 'vaultly-backup' ),
			'db'       => __( 'Exporting database', 'vaultly-backup' ),
			'list'     => __( 'Scanning files', 'vaultly-backup' ),
			'files'    => __( 'Compressing files', 'vaultly-backup' ),
			'final'    => __( 'Finalizing archive', 'vaultly-backup' ),
			'extract'  => __( 'Restoring files', 'vaultly-backup' ),
			'import'   => __( 'Restoring database', 'vaultly-backup' ),
			'replace'  => __( 'Updating site URLs', 'vaultly-backup' ),
			'finish'   => __( 'Cleaning up', 'vaultly-backup' ),
			'vfiles'   => __( 'Checking file integrity', 'vaultly-backup' ),
		);
		return $l[ $p ] ?? ( self::ext_phases()[ $p ]['label'] ?? $p );
	}

	/**
	 * Phases added by extensions (e.g. a separate add-on plugin that copies the finished backup to cloud storage).
	 * Filter 'vaultly_phase_handlers': name => array( 'run' => callable( array &$job, float $deadline ): bool, 'label' => string, 'weight' => float ).
	 * 'run' follows the same contract as the built-in p_* methods: resumable, keep progress in $job['s'], return true when finished.
	 */
	private static function ext_phases(): array {
		$h = function_exists( 'apply_filters' ) ? apply_filters( 'vaultly_phase_handlers', array() ) : array();
		return is_array( $h ) ? $h : array();
	}

	/** Progress-bar weight of a phase (built-in table, else the extension's own, else 1). */
	private static function weight( string $p ): float {
		return (float) ( self::WEIGHT[ $p ] ?? ( self::ext_phases()[ $p ]['weight'] ?? 1 ) );
	}

	private static function title( string $t ): string {
		$l = array(
			'backup'  => __( 'Creating backup', 'vaultly-backup' ),
			'restore' => __( 'Restoring backup', 'vaultly-backup' ),
			'verify'  => __( 'Verifying backup', 'vaultly-backup' ),
		);
		return $l[ $t ];
	}

	const WEIGHT  = array( 'validate' => 1, 'dbcopy' => 2, 'db' => 3, 'list' => 1, 'files' => 8, 'final' => 1, 'extract' => 6, 'import' => 4, 'replace' => 1, 'finish' => 0.2, 'vfiles' => 8 );

	/** Name shown in the activity log for steps that run without a session. */
	private static $actor = '';

	/** Seconds of work per request. Tests set 0 to force a resume after every unit of work. */
	public static $budget = 12;

	/* ---------- pure helpers (unit-tested) ---------- */

	public static function valid_name( $n ): bool {
		return is_string( $n ) && preg_match( self::NAME_RE, $n ) && false === strpos( $n, '..' );
	}

	/** Is this zip entry name safe to extract under wp-content? (zip-slip guard) */
	public static function safe_entry( string $n, string $fmt = 'vaultly' ): bool {
		if ( 'zip' !== $fmt && ( 'manifest.json' === $n || preg_match( '~^db/\d{4}\.sql$~', $n ) ) ) {
			return true;
		}
		return ( 'zip' === $fmt || 0 === strpos( $n, 'content/' ) )
			&& strlen( $n ) <= 1024
			&& ! preg_match( '~[\\\\:\x00-\x1f]|^/|(^|/)\.\.?(/|$)|//~', $n );
	}

	/** Backup type from the file name: .sql = database only, .zip = files only (whole site folder), .vaultly = full. */
	public static function fmt( string $name ): string {
		$e = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $e, array( 'sql', 'zip' ), true ) ? $e : 'vaultly';
	}

	public static function scope_of( string $fmt ): string {
		return array( 'sql' => 'db', 'zip' => 'files' )[ $fmt ] ?? 'full';
	}

	/** Folder a file backup is read from / restored to, and the path prefix inside the archive. Files only = the WordPress root folder. */
	private static function root( string $scope ): array {
		return 'files' === $scope ? array( rtrim( str_replace( '\\', '/', ABSPATH ), '/' ), '' ) : array( rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ), 'content/' );
	}

	/** $abs relative to $root ('' if it is not inside). */
	private static function rel_to( string $abs, string $root ): string {
		$abs = rtrim( str_replace( '\\', '/', $abs ), '/' );
		return 0 === strpos( $abs, $root . '/' ) ? substr( $abs, strlen( $root ) + 1 ) : '';
	}

	/**
	 * Like All-in-One WP Migration, everything in wp-content is included (uploads, plugins, themes, mu-plugins,
	 * languages, cache, drop-ins...). Left out: our own folder and ai1wm-backups (AI1WM skips its own too),
	 * cache if the setting is on, and the archives/dumps inside other tools' backup folders (backuply, updraft,
	 * duplicator... can be tens of GB). Those folders' small stub files (index.php, .htaccess) are kept.
	 */
	public static function excluded( string $rel, string $own_dir, bool $no_cache = false ): bool {
		$rel = str_replace( '\\', '/', $rel );
		$top = explode( '/', $rel )[0];
		if ( self::host_file( $rel ) || $top === $own_dir || 'ai1wm-backups' === $top || 0 === strpos( $top, 'vaultly-' ) || ( $no_cache && 'cache' === $top ) ) {
			return true;
		}
		return preg_match( '/backup|^updraft/i', $top ) && preg_match( '/\.(zip|tar|tgz|gz|bz2|7z|rar|wpress|vlt|vaultly|sql|bak|jpa|daf|part)$/i', $rel );
	}

	/**
	 * Files that belong to the host, not the site: managed-hosting mu-plugins (they require files that only exist on that
	 * server, e.g. /usr/share/php/wp-stack-cache.php, and fatal the whole site elsewhere) and cache / db drop-ins.
	 * Never packed, and skipped when restoring older backups that already contain them. $rel is relative to wp-content
	 * (a leading "wp-content/" from a files-only archive is accepted).
	 */
	public static function host_file( string $rel ): bool {
		$rel = preg_replace( '~^wp-content/~', '', str_replace( '\\', '/', $rel ) );
		return (bool) preg_match( '~^(advanced-cache\.php|object-cache\.php|db\.php|mu-plugins/(wp-stack-cache|gd-system-plugin|endurance-[a-z-]+|wpe-[a-z-]+|sg-cachepress|0-sg-[a-z-]+)\.php|mu-plugins/(kinsta-mu-plugins|wpengine-common|gd-system-plugin)/.*)$~i', $rel );
	}

	/** Every spelling of $a -> $b as stored in a database: plain, URL-encoded, raw-URL-encoded, JSON-escaped. */
	private static function variants( string $a, string $b ): array {
		return array(
			$a                => $b,
			urlencode( $a )    => urlencode( $b ),
			rawurlencode( $a ) => rawurlencode( $b ),
			addcslashes( $a, '/' ) => addcslashes( $b, '/' ),
		);
	}

	/**
	 * Old site URL -> new site URL, in every form WordPress and plugins store it (same coverage as All-in-One WP Migration):
	 * http / https / protocol-relative, with and without www, URL-encoded, JSON-escaped, plus the e-mail domain.
	 */
	public static function url_pairs( string $from, string $to, bool $email = true ): array {
		$from = rtrim( $from, '/' );
		$to   = rtrim( $to, '/' );
		$fh   = wp_parse_url( $from, PHP_URL_HOST );
		$th   = wp_parse_url( $to, PHP_URL_HOST );
		if ( $from === $to || ! $fh || ! $th ) {
			return array();
		}
		$flip  = false !== stripos( $from, '//www.' ) ? preg_replace( '~//www\.~i', '//', $from, 1 ) : preg_replace( '~//~', '//www.', $from, 1 );
		$tscm  = (string) wp_parse_url( $to, PHP_URL_SCHEME );
		$trest = preg_replace( '~^[a-z][a-z0-9+.-]*:~i', '', $to ); // //host/path
		$out   = array();
		foreach ( array( $from, $flip ) as $f ) {
			$rest = preg_replace( '~^[a-z][a-z0-9+.-]*:~i', '', $f );
			foreach ( array( 'http:', 'https:' ) as $s ) {
				$out += self::variants( $s . $rest, $tscm . ':' . $trest );
			}
			$out += self::variants( $rest, $trest );
			if ( $email && strtolower( (string) wp_parse_url( $f, PHP_URL_HOST ) ) !== strtolower( $th ) ) {
				$out[ '@' . wp_parse_url( $f, PHP_URL_HOST ) ] = '@' . $th;
			}
		}
		return $out;
	}

	/** wp-content moved to another folder: rewrite the old absolute path stored in the database. */
	public static function path_pairs( string $from, string $to ): array {
		$from = rtrim( $from, '/\\' );
		$to   = rtrim( $to, '/\\' );
		return ( '' === $from || '' === $to || $from === $to ) ? array() : self::variants( $from, $to );
	}

	public static function clean_settings( array $in ): array {
		$clean = array(
			'sched'       => in_array( $in['sched'] ?? '', array( 'daily', 'weekly' ), true ) ? $in['sched'] : 'off',
			'keep'        => max( 1, min( 100, (int) ( $in['keep'] ?? 5 ) ) ),
			'email'       => function_exists( 'is_email' ) && is_email( $in['email'] ?? '' ) ? $in['email'] : '',
			'safety'      => ! empty( $in['safety'] ),
			'max_file_mb' => max( 0, min( 1000000, (int) ( $in['max_file_mb'] ?? 0 ) ) ),
			'no_cache'    => ! empty( $in['no_cache'] ),
			'no_spam'     => ! empty( $in['no_spam'] ),
			'no_revs'     => ! empty( $in['no_revs'] ),
		);
		// Add-ons keep their own keys through this filter (they are dropped otherwise): ( $clean, $raw_input ) -> $clean.
		$ext = function_exists( 'apply_filters' ) ? apply_filters( 'vaultly_clean_settings', $clean, $in ) : $clean;
		return is_array( $ext ) ? $clean + $ext : $clean; // built-in keys always keep their validated values
	}

	/* ---------- storage ---------- */

	/** Backup directory wp-content/vaultly-backups: archives (.sql .zip .vlt) are reachable by their direct URL (random names); state/log/work files are denied on Apache/IIS. */
	public static function dir(): string {
		static $cached = null;
		if ( null !== $cached && is_dir( $cached ) ) {
			return $cached;
		}
		$d = WP_CONTENT_DIR . '/vaultly-backups'; // fixed, readable name (like ai1wm-backups): wp-content/vaultly-backups
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		$files = array(
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\n<FilesMatch \"\\.(json|jsonl|lst|bin|up|part|tmp)$\">\nRequire all denied\n</FilesMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\n<FilesMatch \"\\.(json|jsonl|lst|bin|up|part|tmp)$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>\n</IfModule>\n",
			'index.php'  => '', // empty on purpose (plugin review: no PHP code in the backup folder)
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="true"><add fileExtension=".json" allowed="false" /><add fileExtension=".jsonl" allowed="false" /><add fileExtension=".lst" allowed="false" /><add fileExtension=".bin" allowed="false" /><add fileExtension=".up" allowed="false" /><add fileExtension=".part" allowed="false" /></fileExtensions></requestFiltering></security></system.webServer></configuration>',
		);
		foreach ( $files as $f => $body ) {
			if ( @file_get_contents( "$d/$f" ) !== $body ) { // also upgrades the rules written by older versions
				file_put_contents( "$d/$f", $body );
			}
		}
		@wp_delete_file( "$d/probe.txt" );
		return $cached = $d;
	}

	public static function dir_url(): string {
		return content_url( basename( self::dir() ) );
	}

	public static function archive_path( string $name ): string {
		if ( ! self::valid_name( $name ) || ! is_file( self::dir() . '/' . $name ) ) {
			throw new RuntimeException( __( 'Backup not found.', 'vaultly-backup' ) );
		}
		return self::dir() . '/' . $name;
	}

	/**
	 * Archive name: sitedomain[-subfolder][_tag][_database|_files]_timestamp_RANDOM.ext (tag = scheduled / pre-restore; full backups have no scope word).
	 * RANDOM = 16 hex chars from random_bytes(): the backup folder is served by the web server, so a name made only of host + time could be guessed.
	 */
	public static function file_name( string $tag, string $scope, string $ext = '' ): string {
		$host = substr( preg_replace( '/[^A-Za-z0-9.-]+/', '-', preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '/' . trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ), 0, 80 ); // host + subfolder, so https://x.com/clientsite gives x.com-clientsite
		$ext  = $ext ?: ( array( 'db' => 'sql', 'files' => 'zip' )[ $scope ] ?? 'vlt' );
		return implode( '_', array_filter( array( trim( $host, '.-/' ) ?: 'site', $tag, array( 'db' => 'database', 'files' => 'files' )[ $scope ] ?? '', gmdate( 'Ymd-His' ), bin2hex( random_bytes( 8 ) ) ) ) ) . '.' . $ext;
	}

	/** Name for an uploaded archive: the visitor's name (cleaned) with a fresh random id, so the stored name is never chosen or guessable by the uploader. */
	public static function secret_name( string $name ): string {
		$name = self::clean_name( $name );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$stem = preg_replace( '/_[0-9a-f]{16}$/', '', substr( $name, 0, -( strlen( $ext ) + 1 ) ) ); // drop an id we gave it earlier: a new one is added
		return rtrim( substr( $stem, 0, 100 ), '._-' ) . '_' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
	}

	/**
	 * Backups made by older versions have names that can be guessed (host + time). Give each one a random id, once, when idle
	 * (the old public link stops working; that is the point). The .meta.json sidecar follows.
	 */
	public static function secure_names(): void {
		$lock = fopen( self::dir() . '/.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return; // a step is running
		}
		if ( ! self::load() ) {
			foreach ( glob( self::dir() . '/*.{vlt,vaultly,sql,zip}', GLOB_BRACE ) ?: array() as $p ) {
				$n = basename( $p );
				if ( ! self::valid_name( $n ) || preg_match( self::SECRET_RE, $n ) ) {
					continue;
				}
				$new = self::secret_name( $n );
				if ( rename( $p, self::dir() . '/' . $new ) ) {
					is_file( "$p.meta.json" ) && rename( "$p.meta.json", self::dir() . "/$new.meta.json" );
					self::log( 'renamed_private', "$n -> $new" );
				}
			}
		}
		flock( $lock, LOCK_UN );
		fclose( $lock );
	}

	/**
	 * Address this site is being served from right now. Taken from the request, not only from the options: after an earlier restore
	 * left the live address in the options, "old = new" would turn the URL replace into a no-op and wp-admin would redirect to the live site.
	 */
	private static function site_url_now( string $opt ): string {
		$cur  = (string) get_option( $opt );
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( '' === $host || ! preg_match( '~^(.*?)/wp-(admin|login)~', $uri, $m ) || strtolower( (string) wp_parse_url( $cur, PHP_URL_HOST ) ) === strtolower( preg_replace( '/:\d+$/', '', $host ) ) && 'home' === $opt ) {
			return $cur ? $cur : '';
		}
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $m[1];
	}

	public static function clean_name( string $n ): string {
		$n = ltrim( preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( $n ) ), '.-' );
		if ( ! self::valid_name( $n ) ) {
			throw new RuntimeException( __( 'File name must end in .vlt and use letters, digits, dot, dash or underscore.', 'vaultly-backup' ) );
		}
		return $n;
	}

	private static function work( array $j ): string {
		$w = self::dir() . '/work-' . $j['id'];
		if ( ! is_dir( $w ) ) {
			mkdir( $w, 0700 );
			// The work folder holds the raw database dump while a backup runs and sits inside the web-served backup folder: refuse every request to it.
			file_put_contents( "$w/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
			file_put_contents( "$w/web.config", '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false"><add fileExtension=".none" allowed="true" /></fileExtensions></requestFiltering></security></system.webServer></configuration>' );
			file_put_contents( "$w/index.php", '' );
		}
		return $w;
	}

	/* --- settings / key / log (files, not DB: they must survive a restore) --- */

	public static function settings(): array {
		$f = self::dir() . '/settings.json';
		$s = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return self::clean_settings( is_array( $s ) ? $s : array() );
	}

	public static function save_settings( array $in ): array {
		$s = self::clean_settings( $in );
		file_put_contents( self::dir() . '/settings.json', json_encode( $s ), LOCK_EX );
		self::ensure_schedule();
		return $s;
	}

	public static function log( string $action, string $detail = '' ): void {
		$f  = self::dir() . '/log.jsonl';
		$u  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user()->user_login : '';
		$by = self::$actor ?: ( $u ?: 'system' );
		file_put_contents( $f, json_encode( array( 't' => time(), 'u' => $by, 'a' => $action, 'd' => substr( $detail, 0, 300 ) ), JSON_INVALID_UTF8_SUBSTITUTE ) . "\n", FILE_APPEND | LOCK_EX );
		if ( filesize( $f ) > 262144 ) {
			file_put_contents( $f, implode( '', array_slice( file( $f ), -200 ) ), LOCK_EX );
		}
	}

	public static function recent_log( int $n = 40 ): array {
		$f = self::dir() . '/log.jsonl';
		$o = array();
		foreach ( is_file( $f ) ? array_reverse( array_slice( file( $f ), -$n ) ) : array() as $l ) {
			if ( is_array( $e = json_decode( $l, true ) ) ) {
				$o[] = $e;
			}
		}
		return $o;
	}

	/* --- job state --- */

	public static function load(): ?array {
		$f = self::dir() . '/.job.json';
		$d = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return is_array( $d ) ? $d : null;
	}

	private static function save( array $j ): void {
		$j['updated'] = time();
		$f            = self::dir() . '/.job.json';
		$json         = json_encode( $j );
		if ( false === $json ) { // e.g. a file name that is not valid UTF-8: never replace a good state file with an empty one
			throw new RuntimeException( sprintf( /* translators: %s: error */ __( 'Cannot save job state: %s', 'vaultly-backup' ), json_last_error_msg() ) );
		}
		file_put_contents( "$f.tmp", $json, LOCK_EX );
		rename( "$f.tmp", $f );
	}

	private static function rrmdir( string $d ): void {
		if ( ! is_dir( $d ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : wp_delete_file( $f->getPathname() );
		}
		rmdir( $d );
	}

	/** Cancel the job. Refused once a restore has started replacing the site (a half-way stop would leave it broken). */
	public static function cancel(): void {
		$lock = fopen( self::dir() . '/.lock', 'c' );
		flock( $lock, LOCK_EX ); // wait for a running step to finish first, so it cannot resurrect the job
		$j = self::load();
		if ( $j && 'restore' === $j['type'] && ! empty( $j['phases'][ $j['i'] ] ) && in_array( $j['phases'][ $j['i'] ], array( 'extract', 'import', 'replace', 'finish' ), true ) ) {
			throw new RuntimeException( __( 'The restore is already replacing your site and cannot be cancelled safely. Let it finish.', 'vaultly-backup' ) );
		}
		self::cleanup( $j );
		self::log( 'cancel' );
	}

	public static function cleanup( ?array $j ): void {
		if ( $j ) {
			self::rrmdir( self::dir() . '/work-' . $j['id'] );
			@wp_delete_file( self::dir() . '/' . $j['out'] . '.part' );
		}
		@wp_delete_file( self::dir() . '/.job.json' );
	}

	/** Housekeeping when idle: stale uploads, parts and work dirs older than a day. */
	public static function sweep(): void {
		if ( self::load() ) {
			return;
		}
		self::secure_names();
		foreach ( glob( self::dir() . '/{*.up,*.part,work-*}', GLOB_BRACE ) ?: array() as $p ) {
			if ( filemtime( $p ) < time() - DAY_IN_SECONDS ) {
				is_dir( $p ) ? self::rrmdir( $p ) : wp_delete_file( $p );
			}
		}
	}

	/** Read + sanity-check manifest of an archive (used for uploads, listing, restore). */
	public static function inspect( string $path ): array {
		$man = VAULTLY_Pack::manifest( $path );
		if ( ( $man['format'] ?? '' ) !== 'vaultly-3' ) {
			throw new RuntimeException( __( 'This is not a backup created by Vaultly Backup.', 'vaultly-backup' ) );
		}
		return $man;
	}

	/** Cached summary of a backup (sidecar file so listing never has to open big zips). */
	public static function meta( string $name ): array {
		$mf = self::dir() . "/$name.meta.json";
		if ( is_file( $mf ) && is_array( $m = json_decode( (string) file_get_contents( $mf ), true ) ) ) {
			return $m;
		}
		$fmt = self::fmt( $name );
		try {
			'vaultly' === $fmt && self::inspect( self::dir() . '/' . $name );
			$m = array(
				'scope' => self::scope_of( $fmt ),
				'kind'  => false !== strpos( $name, 'pre-restore' ) ? 'pre-restore' : ( false !== strpos( $name, 'scheduled' ) ? 'scheduled' : 'manual' ),
			);
		} catch ( Throwable $e ) {
			return array( 'scope' => '?', 'kind' => 'invalid', 'invalid' => true );
		}
		file_put_contents( $mf, json_encode( $m ) );
		return $m;
	}

	public static function list_backups(): array {
		$out = array();
		foreach ( glob( self::dir() . '/*.{vlt,vaultly,sql,zip}', GLOB_BRACE ) ?: array() as $p ) {
			if ( self::valid_name( basename( $p ) ) ) {
				$out[] = array( 'name' => basename( $p ), 'size' => filesize( $p ), 'time' => filemtime( $p ) ) + self::meta( basename( $p ) );
			}
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		return $out;
	}

	public static function delete_backup( string $name ): void {
		wp_delete_file( self::archive_path( $name ) );
		@wp_delete_file( self::dir() . "/$name.meta.json" );
		self::log( 'delete', $name );
	}

	/** Scheduled: keep newest N; pre-restore safety copies: keep newest 3. Manual backups are never auto-deleted. */
	public static function retain(): void {
		$keep = array( 'scheduled' => self::settings()['keep'], 'pre-restore' => 3 );
		$seen = array();
		foreach ( self::list_backups() as $b ) {
			if ( isset( $keep[ $b['kind'] ] ) && ( $seen[ $b['kind'] ] = ( $seen[ $b['kind'] ] ?? 0 ) + 1 ) > $keep[ $b['kind'] ] ) {
				self::delete_backup( $b['name'] );
			}
		}
	}

	/* ---------- scheduler ---------- */

	public static function ensure_schedule(): void {
		$want = self::settings()['sched'];
		$next = wp_next_scheduled( 'vaultly_cron' );
		if ( 'off' === $want ) {
			$next && wp_clear_scheduled_hook( 'vaultly_cron' );
			return;
		}
		if ( ! $next || wp_get_schedule( 'vaultly_cron' ) !== $want ) {
			wp_clear_scheduled_hook( 'vaultly_cron' );
			wp_schedule_event( ( new DateTime( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp(), $want, 'vaultly_cron' ); // off-peak
		}
	}

	/**
	 * Scheduled backup. No PHP limits are changed: the job is resumable, so this request works for a slice of the host's own time
	 * limit and, if the backup is not finished, a one-off cron event ('vaultly_cron_continue') carries on with the next slice.
	 */
	public static function run_scheduled(): void {
		try {
			$token = self::start( 'backup', '', array( 'by' => 'scheduler', 'tag' => 'scheduled' ) )['token'];
		} catch ( Throwable $e ) {
			self::scheduled_failed( $e );
			return;
		}
		self::drive_scheduled( $token );
	}

	/** Next slice of a scheduled backup that did not fit in one cron request. */
	public static function continue_scheduled(): void {
		$j = self::load();
		if ( $j && 'scheduler' === $j['by'] ) { // a backup started by a person is driven by their browser, not by cron
			self::drive_scheduled( $j['token'] );
		}
	}

	private static function drive_scheduled( string $token ): void {
		$max  = (int) ini_get( 'max_execution_time' );
		$stop = $max > 0 ? microtime( true ) + max( 5, $max * 0.3 ) : INF; // each step() stops by itself after at most half the limit
		try {
			do {
				$r = self::step( $token, true );
				if ( ! empty( $r['busy'] ) ) {
					sleep( 2 );
				}
			} while ( empty( $r['done'] ) && microtime( true ) < $stop );
			if ( empty( $r['done'] ) ) {
				wp_schedule_single_event( time() + 5, 'vaultly_cron_continue' );
			}
		} catch ( Throwable $e ) {
			self::scheduled_failed( $e );
		}
	}

	private static function scheduled_failed( Throwable $e ): void {
		self::log( 'scheduled_failed', $e->getMessage() );
		if ( $to = self::settings()['email'] ) {
			wp_mail( $to, sprintf( /* translators: %s: site name */ __( '[%s] Scheduled backup FAILED', 'vaultly-backup' ), get_bloginfo( 'name' ) ), $e->getMessage() );
		}
	}

	public static function activate(): void {
		self::ensure_schedule();
	}

	/* ---------- lifecycle ---------- */

	/** $o: scope (full|db|files), prefix, by */
	public static function start( string $type, string $archive = '', array $o = array() ): array {
		if ( ( $old = self::load() ) && time() - $old['updated'] < 600 ) {
			throw new RuntimeException( __( 'Another backup/restore is already running.', 'vaultly-backup' ) );
		}
		self::cleanup( $old );
		foreach ( glob( self::dir() . '/work-*', GLOB_ONLYDIR ) ?: array() as $w ) { // leftovers of a job that died
			self::rrmdir( $w );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'The PHP zip extension is required.', 'vaultly-backup' ) );
		}
		if ( PHP_INT_SIZE < 8 ) {
			throw new RuntimeException( __( '64-bit PHP is required (32-bit PHP cannot handle archives over 2 GB).', 'vaultly-backup' ) );
		}
		$id    = bin2hex( random_bytes( 8 ) ); // names the work folder, which sits in a web-served directory
		$scope = in_array( $o['scope'] ?? 'full', array( 'full', 'db', 'files' ), true ) ? ( $o['scope'] ?? 'full' ) : 'full';
		$by    = $o['by'] ?? ( function_exists( 'wp_get_current_user' ) ? wp_get_current_user()->user_login : 'system' );
		$job   = array(
			'type'  => $type,
			'id'    => $id,
			'by'    => $by,
			'token' => bin2hex( random_bytes( 16 ) ),
			'i'     => 0,
			's'     => array(),
			'p'     => 0,
			'out'   => '',
		);

		if ( 'backup' === $type ) {
			$job['scope']  = $scope;
			$job['phases'] = self::backup_phases( $scope, 'backup' );
		} else {
			$job['src']   = basename( self::archive_path( $archive ) );
			$job['fmt']   = self::fmt( $job['src'] );
			$job['scope'] = self::scope_of( $job['fmt'] );
			$has_db       = 'files' !== $job['scope'];
			if ( 'verify' === $type ) {
				$job['phases'] = array_merge( array( 'validate' ), 'vaultly' === $job['fmt'] ? array( 'dbcopy' ) : array(), 'sql' === $job['fmt'] ? array() : array( 'vfiles' ) );
			} else {
				$job['cur']    = array( 'home' => self::site_url_now( 'home' ), 'siteurl' => self::site_url_now( 'siteurl' ), 'content' => WP_CONTENT_DIR );
				$job['phases'] = array_merge(
					array( 'validate' ),
					'vaultly' === $job['fmt'] ? array( 'dbcopy' ) : array(),
					self::settings()['safety'] ? self::backup_phases( $job['scope'], 'restore' ) : array(), // automatic pre-restore safety backup of what will be replaced
					'db' === $job['scope'] ? array() : array( 'extract' ),
					$has_db ? array( 'import', 'replace' ) : array(),
					array( 'finish' )
				);
			}
		}
		// The safety backup of a restore has the same type as what is restored.
		$job['out'] = self::file_name( $o['tag'] ?? ( 'restore' === $type ? 'pre-restore' : '' ), $job['scope'] );
		self::save( $job );
		self::log( $type . '_start', $job['src'] ?? $job['out'] );
		return array( 'token' => $job['token'] );
	}

	/**
	 * $type = 'backup' (a real backup) or 'restore' (the safety backup made before a restore).
	 * Filter 'vaultly_backup_phases' ( $phases, $scope, $type ) lets an add-on append steps (for example after 'final', which leaves the finished
	 * archive in self::dir() . '/' . $job['out']). It can only ADD steps: every built-in step must stay, in order, and every added step must be registered.
	 */
	private static function backup_phases( string $scope, string $type = 'backup' ): array {
		$base = array_merge(
			'files' === $scope ? array() : array( 'db' ),
			'db' === $scope ? array() : array( 'list', 'files' ),
			array( 'final' )
		);
		if ( ! function_exists( 'apply_filters' ) ) {
			return $base;
		}
		$all   = (array) apply_filters( 'vaultly_backup_phases', $base, $scope, $type );
		$known = self::ext_phases();
		$ok    = array_values( array_filter( $all, function ( $p ) use ( $known ) {
			return is_string( $p ) && ( method_exists( __CLASS__, 'p_' . $p ) || isset( $known[ $p ] ) );
		} ) );
		return array_values( array_intersect( $ok, $base ) ) === $base ? $ok : $base;
	}

	/** One unit of work. $can = current user is an authorised admin (false only for late restore phases). */
	public static function step( string $token, bool $can ): array {
		$lock = fopen( self::dir() . '/.lock', 'c' );
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return array( 'busy' => true );
		}
		$j = self::load();
		if ( ! $j || ! hash_equals( $j['token'], $token ) ) {
			throw new RuntimeException( __( 'No active job.', 'vaultly-backup' ) );
		}
		if ( ! $can && ! in_array( $j['phases'][ $j['i'] ] ?? '', self::LATE, true ) ) {
			throw new RuntimeException( __( 'Forbidden.', 'vaultly-backup' ) );
		}
		self::$actor = $j['by'];
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		// A step that died hard (memory / time limit, killed by the host) leaves this flag behind: do less work per step from now on.
		$flag = self::dir() . '/.inflight';
		if ( is_file( $flag ) ) {
			$j['budget'] = max( 1, ( $j['budget'] ?? self::$budget ) / 2 );
		}
		file_put_contents( $flag, '1' );
		try {
			return self::run_step( $j );
		} finally {
			@wp_delete_file( $flag );
		}
	}

	/** Runs the current phase: a built-in p_* method, else a handler registered through 'vaultly_phase_handlers'. */
	private static function run_phase( array &$j, float $deadline ): bool {
		$ph = (string) $j['phases'][ $j['i'] ];
		if ( method_exists( __CLASS__, 'p_' . $ph ) ) {
			$m = 'p_' . $ph;
			return self::$m( $j, $deadline );
		}
		$run = self::ext_phases()[ $ph ]['run'] ?? null;
		if ( ! is_callable( $run ) ) { // e.g. the add-on that owned this phase was deactivated while the job was running
			throw new RuntimeException( sprintf( /* translators: %s: phase name */ __( 'Unknown step "%s" (is the add-on that provides it still active?).', 'vaultly-backup' ), $ph ) );
		}
		return (bool) $run( $j, $deadline );
	}

	private static function run_step( array $j ): array {
		if ( ! empty( $j['pfx'] ) ) { // the restored database has another table prefix: use it for the rest of this request too
			$GLOBALS['wpdb']->set_prefix( $j['pfx'] );
		}
		$max      = (int) ini_get( 'max_execution_time' );
		$deadline = microtime( true ) + min( $j['budget'] ?? self::$budget, $max > 0 ? max( 2, $max / 2 ) : INF ); // short steps = the progress popup updates every few seconds

		try {
			do {
				$done = self::run_phase( $j, $deadline );
				if ( $done ) {
					$j['i']++;
					$j['s'] = array();
					$j['p'] = 0;
					unset( $j['detail'] );
				}
			} while ( $done && $j['i'] < count( $j['phases'] ) && microtime( true ) < $deadline );
		} catch ( Throwable $e ) {
			$msg = $e->getMessage();
			// Transient trouble (busy DB, slow disk, one bad batch): every phase except import/replace is safe to repeat from the saved state, so retry with smaller steps before giving up.
			$k = self::load();
			if ( $k && ( $k['fail'] ?? 0 ) < 3 && in_array( $k['phases'][ $k['i'] ] ?? '', array( 'db', 'list', 'files', 'final', 'dbcopy', 'vfiles', 'extract' ), true ) ) {
				$k['fail']   = ( $k['fail'] ?? 0 ) + 1;
				$k['budget'] = max( 1, ( $k['budget'] ?? self::$budget ) / 2 );
				self::save( $k );
				self::log( $k['type'] . '_retry', $msg );
				return array( 'retry' => true, 'message' => sprintf( /* translators: 1: error message, 2: attempt number */ __( 'Problem: %1$s - retrying (%2$d/3)...', 'vaultly-backup' ), $msg, $k['fail'] ) );
			}
			$first = array_search( 'extract', $j['phases'], true );
			$first = false === $first ? array_search( 'import', $j['phases'], true ) : $first;
			if ( 'restore' === $j['type'] && false !== $first && $j['i'] >= $first ) {
				$msg .= ' ' . __( 'Your site may be half-restored.', 'vaultly-backup' ) . ( in_array( 'final', $j['phases'], true ) ? ' ' . sprintf( /* translators: %s: backup file name */ __( 'A pre-restore backup was saved as %s - restore it to roll back.', 'vaultly-backup' ), $j['out'] ) : '' );
			}
			self::log( $j['type'] . '_failed', $msg );
			self::cleanup( $j );
			function_exists( 'do_action' ) && do_action( 'vaultly_job_failed', $j['type'], $msg );
			throw new RuntimeException( $msg );
		}

		if ( $j['i'] >= count( $j['phases'] ) ) {
			self::cleanup( $j );
			self::log( $j['type'] . '_done', $j['src'] ?? $j['out'] );
			if ( 'verify' !== $j['type'] ) {
				self::retain();
			}
			function_exists( 'do_action' ) && do_action( 'vaultly_job_done', $j['type'], array( 'file' => 'backup' === $j['type'] ? $j['out'] : '', 'source' => $j['src'] ?? '', 'scope' => $j['scope'], 'by' => $j['by'] ) );
			$msg = array( 'backup' => __( 'Backup complete.', 'vaultly-backup' ), 'restore' => __( 'Restore complete. You may need to log in again.', 'vaultly-backup' ), 'verify' => __( 'Backup verified.', 'vaultly-backup' ) );
			return array( 'done' => true, 'percent' => 100, 'kind' => $j['type'], 'file' => 'backup' === $j['type'] ? $j['out'] : '', 'message' => ( $j['result'] ?? '' ) ?: $msg[ $j['type'] ] );
		}
		$j['fail'] = 0;
		self::save( $j );
		$ph    = $j['phases'][ $j['i'] ];
		$steps = array_map( function ( $p ) use ( $j ) {
			return ( 'restore' === $j['type'] && in_array( $p, array( 'db', 'list', 'files', 'final' ), true ) ? __( 'Safety backup: ', 'vaultly-backup' ) : '' ) . self::label( $p );
		}, $j['phases'] );
		$total = array_sum( array_map( function ( $p ) {
			return self::weight( $p );
		}, $j['phases'] ) );
		$before = 0;
		foreach ( array_slice( $j['phases'], 0, $j['i'] ) as $p ) {
			$before += self::weight( $p );
		}
		return array(
			'done'    => false,
			'percent' => (int) floor( 100 * ( $before + self::weight( $ph ) * $j['p'] ) / $total ),
			'detail'  => $j['detail'] ?? '',
			'title'   => self::title( $j['type'] ),
			'steps'   => $steps,
			'index'   => $j['i'],
			'cancel'  => ! ( 'restore' === $j['type'] && in_array( $ph, array( 'extract', 'import', 'replace', 'finish' ), true ) ),
			'message' => ( 'restore' === $j['type'] && in_array( $ph, array( 'db', 'list', 'files', 'final' ), true ) ? __( 'Safety backup: ', 'vaultly-backup' ) : '' ) . self::label( $ph ) . '...',
		);
	}

	/* ---------- phases: backup ---------- */

	private static function p_db( array &$j, float $dl ): bool {
		global $wpdb;
		$set = self::settings();
		$pre = $wpdb->prefix;
		VAULTLY_DB::$where = array();
		if ( $set['no_spam'] ) {
			VAULTLY_DB::$where[ $pre . 'comments' ]    = "`comment_approved` != 'spam'";
			VAULTLY_DB::$where[ $pre . 'commentmeta' ] = "`comment_id` IN ( SELECT `comment_ID` FROM `{$pre}comments` WHERE `comment_approved` != 'spam' )";
		}
		if ( $set['no_revs'] ) {
			VAULTLY_DB::$where[ $pre . 'posts' ] = "`post_type` != 'revision'";
		}
		$done   = VAULTLY_DB::dump_step( $j['s'], self::work( $j ), $dl );
		$nt     = count( $j['s']['tables'] ?? array() );
		$j['p'] = $done ? 1 : ( $j['s']['t'] ?? 0 ) / max( 1, $nt );
		$j['detail'] = sprintf( /* translators: 1: current table number, 2: total tables */ __( 'Table %1$d of %2$d', 'vaultly-backup' ), min( $nt, ( $j['s']['t'] ?? 0 ) + 1 ), $nt );
		return $done;
	}

	/** Resumable directory scan (stat only). Works through a stack of pending folders, so it stays inside any max_execution_time. */
	private static function p_list( array &$j, float $dl ): bool {
		$root = self::root( $j['scope'] )[0];
		$cpre = self::rel_to( WP_CONTENT_DIR, $root ); // wp-content inside the scanned folder (files only = whole site); the exclusion rules are relative to wp-content
		$own  = basename( self::dir() );
		$set  = self::settings();
		$max  = $set['max_file_mb'] * 1048576;
		if ( ! isset( $j['s']['dirs'] ) ) {
			$j['s'] = array( 'dirs' => array( '' ), 'n' => 0, 'bytes' => 0, 'big' => 0, 'pos' => 0 );
		}
		$s = &$j['s'];
		$o = fopen( self::work( $j ) . '/files.lst', 'c+b' );
		ftruncate( $o, $s['pos'] ); // resume: drop anything written after the last persisted state
		fseek( $o, 0, SEEK_END );
		while ( $s['dirs'] ) {
			$dir = base64_decode( array_pop( $s['dirs'] ) ); // names are kept base64-encoded in the JSON job state: they can be any bytes
			foreach ( @scandir( '' === $dir ? $root : "$root/$dir" ) ?: array() as $nm ) {
				if ( '.' === $nm || '..' === $nm ) {
					continue;
				}
				$rel = '' === $dir ? $nm : "$dir/$nm";
				$abs = "$root/$rel";
				$crel = 'files' !== $j['scope'] ? $rel : ( 0 === strpos( $rel, "$cpre/" ) ? substr( $rel, strlen( $cpre ) + 1 ) : '' );
				if ( is_link( $abs ) || ( '' !== $crel && self::excluded( $crel, $own, $set['no_cache'] ) ) || apply_filters( 'vaultly_exclude', false, $rel ) ) {
					continue;
				}
				if ( is_dir( $abs ) ) {
					$s['dirs'][] = base64_encode( $rel );
				} elseif ( is_file( $abs ) && is_readable( $abs ) && ! preg_match( '/[\t\r\n\0]/', $rel ) ) {
					$size = (int) filesize( $abs );
					if ( $max && $size > $max ) {
						$s['big']++;
						continue;
					}
					self::put( $o, "$size\t$rel\n" );
					$s['n']++;
					$s['bytes'] += $size;
				}
			}
			$s['pos'] = ftell( $o );
			if ( microtime( true ) > $dl ) {
				break;
			}
		}
		fclose( $o );
		$j['detail'] = sprintf( /* translators: 1: number of files, 2: size */ __( 'Found %1$s files (%2$s)', 'vaultly-backup' ), number_format_i18n( $s['n'] ), size_format( $s['bytes'] ) );
		if ( $s['dirs'] ) {
			return false;
		}
		$j['total_files'] = $s['n'];
		$j['total_bytes'] = $s['bytes'];
		$j['skipped_big'] = $s['big'];
		return true;
	}

	private static function p_files( array &$j, float $dl ): bool {
		$tmp = self::dir() . '/' . $j['out'] . '.part';
		$w   = self::work( $j );
		if ( ! isset( $j['s']['off'] ) ) {
			$j['s'] = array( 'off' => 0, 'bytes' => 0, 'n' => 0, 'zpos' => 0, 'cpos' => 0 );
		}
		$s = &$j['s'];
		// Resume: drop anything written after the last persisted state.
		$h = fopen( $tmp, 'c+b' );
		$c = fopen( "$w/cd.bin", 'c+b' );
		if ( ! $h || ! $c ) {
			throw new RuntimeException( __( 'Cannot write the archive (check folder permissions and free disk space).', 'vaultly-backup' ) );
		}
		ftruncate( $h, $s['zpos'] );
		ftruncate( $c, $s['cpos'] );
		fseek( $c, 0, SEEK_END );
		$pack = 'full' === $j['scope']; // full = Vaultly's own format; files only = plain zip
		if ( $pack && 0 === $s['zpos'] ) {
			self::put( $h, VAULTLY_Pack::MAGIC );
			$s['zpos'] = ftell( $h );
		}
		list( $root, $pre ) = self::root( $j['scope'] );
		$lst = fopen( "$w/files.lst", 'rb' );
		fseek( $lst, $s['off'] );
		while ( false !== ( $line = fgets( $lst ) ) ) {
			list( $size, $rel ) = explode( "	", rtrim( $line, "
" ), 2 );
			$abs = "$root/$rel";
			if ( is_file( $abs ) && ! is_link( $abs ) && ( $in = @fopen( $abs, 'rb' ) ) ) {
				if ( $pack ) {
					VAULTLY_Pack::add_stream( $h, $pre . $rel, $in, (int) @filemtime( $abs ), (int) $size );
				} else {
					// Already-compressed or huge files are only stored: I/O-bound, no CPU spike.
					$store = $size > ( 256 << 20 ) || in_array( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ), self::STORE, true );
					VAULTLY_Zip::add_stream( $h, $c, $pre . $rel, $in, $store, (int) @filemtime( $abs ) );
				}
				fclose( $in );
				$s['n']++;
				$s['bytes'] += (int) $size;
			}
			$s['off']  = ftell( $lst );
			$s['zpos'] = ftell( $h );
			$s['cpos'] = ftell( $c );
			if ( microtime( true ) > $dl ) {
				break;
			}
		}
		$done = feof( $lst );
		fclose( $lst );
		fclose( $h );
		fclose( $c );
		$j['p']      = $j['total_bytes'] ? min( 1, $s['bytes'] / $j['total_bytes'] ) : 1;
		$j['detail'] = sprintf( /* translators: 1: files done, 2: files total, 3: bytes done, 4: bytes total */ __( '%1$s of %2$s files (%3$s of %4$s)', 'vaultly-backup' ), number_format_i18n( $s['n'] ), number_format_i18n( $j['total_files'] ), size_format( $s['bytes'] ), size_format( $j['total_bytes'] ) );
		if ( $done ) {
			$j['z'] = array( 'zpos' => $s['zpos'], 'cpos' => $s['cpos'], 'n' => $s['n'] );
		}
		return $done;
	}

	private static function put( $h, string $s ): void {
		if ( strlen( $s ) !== fwrite( $h, $s ) ) {
			throw new RuntimeException( __( 'Cannot write the backup (disk full?).', 'vaultly-backup' ) );
		}
	}

	private static function p_final( array &$j, float $dl ): bool {
		global $wpdb;
		$w     = self::work( $j );
		$parts = array();
		for ( $n = 1; 'files' !== $j['scope'] && is_file( VAULTLY_DB::part_path( $w, $n ) ); $n++ ) {
			$parts[] = VAULTLY_DB::part_path( $w, $n );
		}
		$out = self::dir() . '/' . $j['out'];
		$tmp = "$out.part";

		if ( 'db' === $j['scope'] ) { // database only: one plain .sql file (header line + the dump parts + end marker)
			$h = fopen( $tmp, 'wb' );
			if ( ! $h ) {
				throw new RuntimeException( __( 'Cannot open archive (check folder permissions).', 'vaultly-backup' ) );
			}
			self::put( $h, '-- vaultly ' . json_encode( array( 'home' => get_option( 'home' ), 'siteurl' => get_option( 'siteurl' ), 'content' => WP_CONTENT_DIR, 'prefix' => $wpdb->prefix, 'wp' => get_bloginfo( 'version' ), 'created' => gmdate( 'c' ) ) ) . "\n" );
			foreach ( $parts as $p ) {
				$in = fopen( $p, 'rb' );
				if ( filesize( $p ) !== stream_copy_to_stream( $in, $h ) ) {
					throw new RuntimeException( __( 'Cannot write the backup (disk full?).', 'vaultly-backup' ) );
				}
				fclose( $in );
			}
			self::put( $h, "-- vaultly-end\n" );
			if ( ! fclose( $h ) ) {
				throw new RuntimeException( __( 'Cannot finalize archive (disk full?).', 'vaultly-backup' ) );
			}
		} else {
			$z = $j['z'] ?? array( 'zpos' => 0, 'cpos' => 0, 'n' => 0 );
			$h = fopen( $tmp, 'c+b' );
			$c = fopen( "$w/cd.bin", 'c+b' );
			if ( ! $h || ! $c ) {
				throw new RuntimeException( __( 'Cannot open archive (check folder permissions).', 'vaultly-backup' ) );
			}
			ftruncate( $h, $z['zpos'] );
			ftruncate( $c, $z['cpos'] );
			fseek( $c, 0, SEEK_END );
			if ( 'full' === $j['scope'] ) { // full backup: database parts + manifest after content/
				$man = array(
					'format'      => 'vaultly-3',
					'dbat'        => $z['zpos'], // offset of the first database record
					'scope'       => 'full',
					'created'     => gmdate( 'c' ),
					'home'        => get_option( 'home' ),
					'siteurl'     => get_option( 'siteurl' ),
					'prefix'      => $wpdb->prefix,
					'content'     => WP_CONTENT_DIR,
					'plugins'     => array_values( (array) get_option( 'active_plugins', array() ) ),
					'template'    => get_option( 'template' ),
					'stylesheet'  => get_option( 'stylesheet' ),
					'wp'          => get_bloginfo( 'version' ),
					'php'         => PHP_VERSION,
					'files'       => $j['total_files'] ?? 0,
					'skipped_big' => $j['skipped_big'] ?? 0,
					'parts'       => array_map( function ( $p ) {
						return hash_file( 'sha256', $p );
					}, $parts ),
				);
				foreach ( $parts as $i => $p ) {
					$in = fopen( $p, 'rb' );
					VAULTLY_Pack::add_stream( $h, sprintf( 'db/%04d.sql', $i + 1 ), $in, time() );
					fclose( $in );
				}
				fseek( $h, 0, SEEK_END );
				$moff = ftell( $h );
				VAULTLY_Pack::add_string( $h, 'manifest.json', json_encode( $man, JSON_PRETTY_PRINT ) );
				VAULTLY_Pack::finish( $h, $moff );
			} else {
				VAULTLY_Zip::finish( $h, $c, $z['n'] ); // files only: a plain zip of the WordPress root folder, nothing else inside
			}
			fclose( $c );
			if ( ! fclose( $h ) ) {
				throw new RuntimeException( __( 'Cannot finalize archive (disk full?).', 'vaultly-backup' ) );
			}
		}
		rename( $tmp, $out );
		chmod( $out, 0644 );
		self::meta( $j['out'] );
		if ( ! empty( $j['skipped_big'] ) && 'backup' === $j['type'] ) {
			$j['result'] = sprintf( /* translators: %d: number of files */ __( 'Backup complete. %d file(s) over the size limit were skipped.', 'vaultly-backup' ), $j['skipped_big'] );
		}
		return true;
	}

	/** First line of a database export ("-- vaultly {json}") + end marker: proves it is ours and complete. */
	public static function sql_header( string $path ): array {
		$h     = @fopen( $path, 'rb' );
		$first = $h ? (string) fgets( $h, 65536 ) : '';
		$tail  = '';
		if ( $h && 0 === fseek( $h, -16, SEEK_END ) ) {
			$tail = (string) fread( $h, 16 );
		}
		$h && fclose( $h );
		$man = 0 === strpos( $first, '-- vaultly ' ) ? json_decode( substr( $first, 10 ), true ) : null;
		if ( ! is_array( $man ) || ! isset( $man['prefix'] ) ) {
			throw new RuntimeException( __( 'This is not a database export created by Vaultly Backup.', 'vaultly-backup' ) );
		}
		if ( false === strpos( $tail, '-- vaultly-end' ) ) {
			throw new RuntimeException( __( 'The database export is incomplete.', 'vaultly-backup' ) );
		}
		return $man;
	}

	/** Uploaded file really is what its extension says. */
	public static function check( string $path, string $name ): void {
		$fmt = self::fmt( $name );
		if ( 'sql' === $fmt ) {
			self::sql_header( $path );
		} elseif ( 'zip' === $fmt ) {
			$z = new ZipArchive();
			if ( true !== $z->open( $path ) ) {
				throw new RuntimeException( __( 'Not a valid zip archive.', 'vaultly-backup' ) );
			}
			$z->close();
		} else {
			self::inspect( $path );
		}
	}

	/* ---------- phases: restore / verify ---------- */

	/** $len >= 0: read exactly that many bytes (a record inside a .vaultly file); -1: until EOF (a zip entry stream). */
	private static function copy_crc( $in, $out, int $crc, string $label, int $len = -1 ): void {
		$ctx = hash_init( 'crc32b' );
		while ( $in && ! feof( $in ) && 0 !== $len ) {
			$b = fread( $in, $len < 0 ? 1 << 20 : min( 1 << 20, $len ) );
			if ( false === $b ) {
				throw new RuntimeException( sprintf( /* translators: %s: file label */ __( 'Cannot read %s from the archive.', 'vaultly-backup' ), $label ) );
			}
			hash_update( $ctx, $b );
			if ( $out && '' !== $b && strlen( $b ) !== fwrite( $out, $b ) ) {
				throw new RuntimeException( sprintf( /* translators: %s: file label */ __( 'Cannot write %s (permissions or disk full).', 'vaultly-backup' ), $label ) );
			}
			$len = $len > 0 ? $len - strlen( $b ) : $len;
		}
		if ( hexdec( hash_final( $ctx ) ) !== $crc ) {
			throw new RuntimeException( sprintf( /* translators: %s: file label */ __( 'Checksum mismatch in %s - the backup is corrupted.', 'vaultly-backup' ), $label ) );
		}
	}

	/* A restorable archive is read through one of two handles: a file handle (.vaultly, sequential records) or a ZipArchive (.zip, files only). */

	private static function arch_open( string $fmt, string $path ) {
		if ( 'vaultly' === $fmt ) {
			return fopen( $path, 'rb' );
		}
		$z = new ZipArchive();
		$z->open( $path );
		return $z;
	}

	private static function arch_close( $a ): void {
		$a instanceof ZipArchive ? $a->close() : fclose( $a );
	}

	/** Size of the archive in resume-cursor units: bytes for .vaultly, entries for .zip. */
	private static function arch_total( $a ): int {
		return $a instanceof ZipArchive ? $a->numFiles : (int) fstat( $a )['size'];
	}

	/** Entries (name, size, crc, mtime, next) from resume cursor $from (null = the start). 'next' is the cursor of the entry after this one. */
	private static function each( string $fmt, $a, ?int $from = null ): Generator {
		if ( 'vaultly' === $fmt ) {
			yield from VAULTLY_Pack::entries( $a, $from ?? VAULTLY_Pack::START );
			return;
		}
		for ( $i = $from ?? 0; $i < $a->numFiles; $i++ ) {
			yield $a->statIndex( $i ) + array( 'next' => $i + 1 );
		}
	}

	/** Copies one entry to $out (or just checks it when $out is null), verifying its CRC. */
	private static function copy_entry( string $fmt, $a, array $st, $out, string $label ): void {
		if ( 'vaultly' === $fmt ) {
			fseek( $a, $st['at'] );
			self::copy_crc( $a, $out, $st['crc'], $label, $st['size'] );
		} else {
			self::copy_crc( $a->getStream( $st['name'] ), $out, $st['crc'], $label );
		}
	}

	private static function p_validate( array &$j, float $dl ): bool {
		global $wpdb;
		$src = self::archive_path( $j['src'] );
		$man = 'sql' === $j['fmt'] ? self::sql_header( $src ) : ( 'vaultly' === $j['fmt'] ? self::inspect( $src ) : array() );

		// A backup with another table prefix is restored as it is (same table names); wp-config.php then gets that prefix. Check this up front, before anything is changed.
		if ( 'restore' === $j['type'] && 'files' !== $j['scope'] && ( $man['prefix'] ?? '' ) !== $wpdb->prefix && ( ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', (string) ( $man['prefix'] ?? '' ) ) || ! self::config_file() ) ) {
			throw new RuntimeException( sprintf( /* translators: 1: backup table prefix, 2: this site's table prefix */ __( 'This backup uses the table prefix %1$s (this site: %2$s). To restore it, wp-config.php must be writable and hold a plain $table_prefix line so the prefix can be switched. Fix that and try again.', 'vaultly-backup' ), $man['prefix'], $wpdb->prefix ) );
		}
		$j['man']   = array( 'prefix' => (string) ( $man['prefix'] ?? $wpdb->prefix ), 'home' => $man['home'] ?? '', 'siteurl' => $man['siteurl'] ?? '', 'content' => $man['content'] ?? '', 'parts' => $man['parts'] ?? array(), 'dbat' => $man['dbat'] ?? 0 );
		$j['facts'] = array( 'files' => 0 );
		if ( 'sql' === $j['fmt'] ) {
			if ( 'verify' === $j['type'] ) {
				$j['result'] = __( 'Database export verified: it is complete and belongs to this plugin.', 'vaultly-backup' );
			}
			return true;
		}

		$a     = self::arch_open( $j['fmt'], $src );
		$bytes = $j['s']['bytes'] ?? 0;
		$count = $j['s']['count'] ?? 0;
		$from  = $j['s']['from'] ?? null;
		$more  = false;
		try {
			foreach ( self::each( $j['fmt'], $a, $from ) as $st ) {
				if ( ! self::safe_entry( $st['name'], $j['fmt'] ) ) {
					throw new RuntimeException( __( 'Backup contains an unsafe path and was rejected.', 'vaultly-backup' ) );
				}
				$bytes += $st['size'];
				$count += ( 'zip' === $j['fmt'] ? '/' !== substr( $st['name'], -1 ) : 0 === strpos( $st['name'], 'content/' ) ) ? 1 : 0;
				$from   = $st['next'];
				if ( microtime( true ) > $dl ) { // big archives: scan in short steps so no single request hits the host's time limit
					$more = true;
					break;
				}
			}
		} finally {
			self::arch_close( $a );
		}
		if ( $more ) {
			$j['s'] = array( 'bytes' => $bytes, 'count' => $count, 'from' => $from );
			$j['p'] = min( 0.99, $from / max( 1, filesize( $src ) ) );
			return false;
		}
		$free = @disk_free_space( self::dir() );
		if ( 'restore' === $j['type'] && false !== $free && $free < $bytes * 1.2 ) {
			throw new RuntimeException( __( 'Not enough free disk space to restore this backup.', 'vaultly-backup' ) );
		}
		$j['facts']['files'] = $count;
		return true;
	}

	/** Copy the database records out of a .vaultly file + checksum them (each <= ~32 MB, so each step is short regardless of DB size). */
	private static function p_dbcopy( array &$j, float $dl ): bool {
		$h     = fopen( self::archive_path( $j['src'] ), 'rb' );
		$parts = $j['man']['parts'];
		$i     = $j['s']['n'] ?? 0;
		$off   = $j['s']['off'] ?? $j['man']['dbat'];
		$more  = false;
		try {
			foreach ( VAULTLY_Pack::entries( $h, $off ) as $st ) {
				if ( $i >= count( $parts ) ) {
					break;
				}
				$name = sprintf( 'db/%04d.sql', $i + 1 );
				if ( $st['name'] !== $name ) {
					break;
				}
				fseek( $h, $st['at'] );
				$out = fopen( self::work( $j ) . sprintf( '/restore-%04d.sql', $i + 1 ), 'wb' );
				$ctx = hash_init( 'sha256' );
				for ( $left = $st['size']; $left > 0; $left -= strlen( $buf ) ) {
					$buf = fread( $h, min( 1 << 20, $left ) );
					if ( false === $buf || '' === $buf ) {
						break;
					}
					hash_update( $ctx, $buf );
					if ( strlen( $buf ) !== fwrite( $out, $buf ) ) {
						fclose( $out );
						throw new RuntimeException( __( 'Cannot write the database copy (permissions or disk full).', 'vaultly-backup' ) );
					}
				}
				fclose( $out );
				if ( $left > 0 || ! hash_equals( $parts[ $i ], hash_final( $ctx ) ) ) {
					throw new RuntimeException( sprintf( /* translators: %s: database part file name */ __( 'Database part %s is corrupted (checksum mismatch).', 'vaultly-backup' ), $name ) );
				}
				$i++;
				$off = $st['next'];
				if ( microtime( true ) > $dl ) {
					$more = true;
					break;
				}
			}
		} finally {
			fclose( $h );
		}
		if ( $i < count( $parts ) && ! $more ) {
			throw new RuntimeException( __( 'The backup is missing database parts.', 'vaultly-backup' ) );
		}
		$j['s']['n']   = $i;
		$j['s']['off'] = $off;
		$j['p']        = $parts ? $i / count( $parts ) : 1;
		return $i >= count( $parts );
	}

	private static function p_vfiles( array &$j, float $dl ): bool {
		$a     = self::arch_open( $j['fmt'], self::archive_path( $j['src'] ) );
		$total = self::arch_total( $a );
		$next  = $j['s']['i'] ?? null;
		$more  = false;
		try {
			foreach ( self::each( $j['fmt'], $a, $next ) as $st ) {
				$next = $st['next'];
				if ( ( 'zip' === $j['fmt'] || 0 === strpos( $st['name'], 'content/' ) ) && $st['size'] > 0 ) {
					self::copy_entry( $j['fmt'], $a, $st, null, $st['name'] );
				}
				if ( microtime( true ) > $dl ) {
					$more = true;
					break;
				}
			}
		} finally {
			self::arch_close( $a );
		}
		$j['s']['i'] = $next;
		$j['p']      = $more ? $next / max( 1, $total ) : 1;
		if ( $more ) {
			return false;
		}
		$j['result'] = 'zip' === $j['fmt']
			? sprintf( /* translators: %d: file count */ __( 'Backup verified: %d files intact.', 'vaultly-backup' ), $j['facts']['files'] )
			: sprintf( /* translators: 1: file count, 2: database part count */ __( 'Backup verified: %1$d files and %2$d database part(s) intact.', 'vaultly-backup' ), $j['facts']['files'], count( $j['man']['parts'] ) );
		return true;
	}

	private static function p_extract( array &$j, float $dl ): bool {
		$a = self::arch_open( $j['fmt'], self::archive_path( $j['src'] ) );
		list( $base, $pre ) = self::root( $j['scope'] ); // files only = the WordPress root folder, full = wp-content
		$root  = realpath( $base );
		$total = self::arch_total( $a );
		$skip  = array(); // never overwrite the running plugin or our own backup folder
		foreach ( array( realpath( dirname( VAULTLY_FILE ) ), realpath( self::dir() ) ) as $d ) {
			if ( $d && '' !== ( $r = self::rel_to( $d, str_replace( '\\', '/', $root ) ) ) ) {
				$skip[] = "$r/";
			}
		}
		$okd = array(); // folders already created + checked in this step
		try {
			foreach ( self::each( $j['fmt'], $a, $j['s']['i'] ?? null ) as $st ) {
				if ( '/' === substr( $st['name'], -1 ) || 0 !== strpos( $st['name'], $pre ) ) {
					continue;
				}
				$rel = substr( $st['name'], strlen( $pre ) );
				if ( ! self::safe_entry( $st['name'], $j['fmt'] ) || ( 'zip' === $j['fmt'] && 'wp-config.php' === $rel ) || self::host_file( $rel ) || array_filter( $skip, function ( $p ) use ( $rel ) {
					return 0 === strpos( $rel, $p );
				} ) ) {
					continue;
				}
				$dst = $root . '/' . $rel;
				$dd  = dirname( $dst );
				if ( ! isset( $okd[ $dd ] ) ) {
					wp_mkdir_p( $dd );
					$real = realpath( $dd );
					if ( false === $real || 0 !== strpos( $real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) {
						throw new RuntimeException( sprintf( /* translators: %s: file path */ __( 'Refusing to write outside the site folder: %s', 'vaultly-backup' ), $rel ) );
					}
					$okd[ $dd ] = true;
				}
				if ( is_link( $dst ) || is_dir( $dst ) ) {
					throw new RuntimeException( sprintf( /* translators: %s: file path */ __( 'Refusing to write outside the site folder: %s', 'vaultly-backup' ), $rel ) );
				}
				$out = fopen( "$dst.vaultlytmp", 'wb' );
				if ( ! $out ) {
					throw new RuntimeException( sprintf( /* translators: %s: file path */ __( 'Cannot write %s (permissions).', 'vaultly-backup' ), $rel ) );
				}
				try {
					if ( $st['size'] > 0 ) {
						self::copy_entry( $j['fmt'], $a, $st, $out, $rel );
					}
				} catch ( Throwable $e ) {
					fclose( $out );
					@wp_delete_file( "$dst.vaultlytmp" );
					throw $e;
				}
				fclose( $out );
				rename( "$dst.vaultlytmp", $dst );
				@touch( $dst, $st['mtime'] );
				if ( microtime( true ) > $dl ) {
					$f           = 'vaultly' === $j['fmt'] ? 'size_format' : 'number_format_i18n';
					$j['s']['i'] = $st['next'];
					$j['p']      = $st['next'] / max( 1, $total );
					$j['detail'] = sprintf( /* translators: 1: amount done, 2: amount total (bytes or items) */ __( '%1$s of %2$s', 'vaultly-backup' ), $f( $st['next'] ), $f( $total ) );
					return false;
				}
			}
		} finally {
			self::arch_close( $a );
		}
		return true;
	}

	/** wp-config.php when it can take a new $table_prefix (writable and holds a plain $table_prefix = '...'; line), else ''. */
	private static function config_file(): string {
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $f ) {
			if ( is_file( $f ) ) {
				return is_writable( $f ) && preg_match( self::PREFIX_RE, (string) file_get_contents( $f ) ) ? $f : '';
			}
		}
		return '';
	}

	/** Point $table_prefix in wp-config.php at the restored tables (the only change a restore ever makes to that file). */
	private static function config_prefix( string $prefix ): void {
		$f   = self::config_file();
		$src = $f ? file_get_contents( $f ) : '';
		$new = $f ? preg_replace( self::PREFIX_RE, '${1}\'' . $prefix . '\';', $src, 1 ) : null; // prefix = letters, digits, _ (checked by the backup reader)
		if ( ! $new || $new === $src || false === file_put_contents( $f, $new, LOCK_EX ) ) {
			throw new RuntimeException( __( 'Could not switch the table prefix in wp-config.php. Set $table_prefix there to the backup\'s prefix by hand.', 'vaultly-backup' ) );
		}
		function_exists( 'opcache_invalidate' ) && @opcache_invalidate( $f, true ); // the next step request must see the new line at once
	}

	private static function p_import( array &$j, float $dl ): bool {
		global $wpdb;
		$files = 'sql' === $j['fmt'] ? array( self::archive_path( $j['src'] ) ) : array(); // a .sql backup is imported in place
		foreach ( $j['man']['parts'] as $i => $_ ) {
			$files[] = self::work( $j ) . sprintf( '/restore-%04d.sql', $i + 1 );
		}
		$from   = (string) ( $j['man']['prefix'] ?? $wpdb->prefix ); // tables go in under the backup's own names (same to same), whatever prefix that is
		$done   = VAULTLY_DB::import_step( $j['s'], $files, $from, $dl );
		$j['p'] = $done ? 1 : ( $j['s']['base'] + $j['s']['pos'] ) / $j['s']['total'];
		if ( $done ) {
			if ( $from !== $wpdb->prefix ) {
				self::config_prefix( $from ); // from now on this site reads the restored tables
				$wpdb->set_prefix( $from );
				$j['pfx'] = $from;
			}
			wp_cache_flush();
			// The backup's active_plugins list may not contain this plugin: keep it active.
			$act = (array) get_option( 'active_plugins', array() );
			if ( ! in_array( VAULTLY_BASENAME, $act, true ) ) {
				$act[] = VAULTLY_BASENAME;
				update_option( 'active_plugins', $act );
			}
		}
		return $done;
	}

	private static function p_replace( array &$j, float $dl ): bool {
		$pairs = array();
		foreach ( array( 'home', 'siteurl' ) as $k ) {
			$pairs += self::url_pairs( (string) $j['man'][ $k ], (string) $j['cur'][ $k ] );
		}
		$pairs += self::path_pairs( (string) ( $j['man']['content'] ?? '' ), (string) $j['cur']['content'] );
		// ponytail: only URLs, wp-content path and e-mail domain are rewritten (like All-in-One WP Migration); other absolute paths (ABSPATH) are not.
		return VAULTLY_DB::replace_step( $j['s'], $pairs, $dl );
	}

	private static function p_finish( array &$j, float $dl ): bool {
		wp_cache_flush();
		global $wpdb, $wp_rewrite;
		// Site address: write the current one straight into the options, whatever the replace pass did (a leftover live URL = logout/login redirects to the live site).
		foreach ( array( 'home', 'siteurl' ) as $k ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $j['cur'][ $k ] ), array( 'option_name' => $k ) );
		}
		// Cached copies of the old URLs: transients, and the CSS builders' generated files (they hold absolute live font/image URLs; www vs non-www fonts are blocked by CORS and break the layout).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_css','_elementor_element_cache','_et_builder_dynamic_assets_loaded')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$up = wp_get_upload_dir();
		foreach ( array( WP_CONTENT_DIR . '/et-cache', WP_CONTENT_DIR . '/cache', $up['basedir'] . '/elementor/css', $up['basedir'] . '/fusion-styles', $up['basedir'] . '/bricks/css', $up['basedir'] . '/oxygen/css' ) as $d ) {
			self::rrmdir( $d );
		}
		// Permalinks: reload the saved structure and write .htaccess again (the restored site's rules, not the old ones).
		wp_cache_flush();
		if ( $wp_rewrite ) {
			$wp_rewrite->init();
			if ( ! function_exists( 'save_mod_rewrite_rules' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}
			flush_rewrite_rules( true );
		}
		// Plugins that lock you out of a moved / non-https site (same list as All-in-One WP Migration) are switched off.
		$bad = array( 'invisible-recaptcha', 'wps-hide-login', 'hide-my-wp', 'hide-my-wordpress', 'mycustomwidget', 'lockdown-wp-admin', 'rename-wp-login' );
		if ( ! is_ssl() ) {
			$bad = array_merge( $bad, array( 'really-simple-ssl', 'wordpress-https', 'wp-force-ssl' ) );
		}
		$act  = (array) get_option( 'active_plugins', array() );
		$keep = array_values( array_filter( $act, function ( $p ) use ( $bad ) {
			return ! in_array( dirname( $p ), $bad, true );
		} ) );
		if ( $keep !== $act ) {
			update_option( 'active_plugins', $keep );
			$j['result'] = sprintf( /* translators: %s: list of plugins */ __( 'Restore complete. Deactivated for safety: %s.', 'vaultly-backup' ), implode( ', ', array_map( 'dirname', array_diff( $act, $keep ) ) ) );
		}
		return true;
	}
}
