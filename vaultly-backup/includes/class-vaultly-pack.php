<?php
/**
 * Vaultly's own archive format for full backups (.vaultly) - a flat binary container like .wpress.
 *
 *   "VLTY1\n"
 *   record*   = pack('vVPV', name_len, mtime, size, crc32) . name . data     (name_len > 0)
 *   18 zero bytes                                                            (end of records)
 *   pack('P', offset_of_manifest_record) . "VLTE"                            (footer, 12 bytes)
 *
 * Entries are appended once and never rewritten, so a backup can be built over many requests.
 * It is not a zip: standard unzip tools cannot open it, only Vaultly can.
 * The manifest is the last record; the footer points at it, so reading it never scans the archive.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.DB.DirectDatabaseQuery,Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.WriteFile -- backup engine: streams multi-GB archives in resumable chunks inside its own wp-content/vaultly-backups folder; WP_Filesystem cannot stream or append. Exception messages go to the admin JSON/log (never echoed as HTML), and are translated, trusted strings.

final class VAULTLY_Pack {

	const MAGIC  = "VLTY1\n";
	const START  = 6;  // strlen( MAGIC ): offset of the first record
	const HEAD   = 18; // name_len(2) mtime(4) size(8) crc(4)
	const FOOTER = 12;

	/** Appends one record read from stream $in ($limit bytes, or until EOF). Header is written first, size + crc are patched in afterwards. */
	public static function add_stream( $h, string $name, $in, int $mtime, int $limit = -1 ): void {
		fseek( $h, 0, SEEK_END );
		$off = ftell( $h );
		self::w( $h, pack( 'vVPV', strlen( $name ), max( 0, $mtime ), 0, 0 ) . $name );
		$crc  = hash_init( 'crc32b' );
		$size = 0;
		while ( 0 !== $limit && ! feof( $in ) ) {
			$b = fread( $in, $limit < 0 ? 1 << 20 : min( 1 << 20, $limit ) );
			if ( false === $b ) {
				throw new RuntimeException( sprintf( /* translators: %s: file name */ __( 'Cannot read %s.', 'vaultly-backup' ), $name ) );
			}
			if ( '' === $b ) {
				break;
			}
			hash_update( $crc, $b );
			$size += strlen( $b );
			$limit = $limit > 0 ? $limit - strlen( $b ) : $limit;
			self::w( $h, $b );
		}
		$end = ftell( $h );
		fseek( $h, $off + 6 );
		self::w( $h, pack( 'PV', $size, hexdec( hash_final( $crc ) ) ) );
		fseek( $h, $end );
	}

	public static function add_string( $h, string $name, string $data ): void {
		$m = fopen( 'php://memory', 'w+b' );
		self::w( $m, $data );
		rewind( $m );
		self::add_stream( $h, $name, $m, time() );
		fclose( $m );
	}

	/** Terminator + footer. $manifest_off = offset where the manifest record starts. */
	public static function finish( $h, int $manifest_off ): void {
		fseek( $h, 0, SEEK_END );
		self::w( $h, str_repeat( "\0", self::HEAD ) . pack( 'P', $manifest_off ) . 'VLTE' );
	}

	/** Records from byte offset $from. Each: name, size, crc, mtime, at (data offset), next (offset of the following record = resume cursor). */
	public static function entries( $h, int $from = self::START ): Generator {
		$len = fstat( $h )['size'];
		for ( $pos = $from; ; ) {
			fseek( $h, $pos );
			$raw = (string) fread( $h, self::HEAD );
			if ( self::HEAD !== strlen( $raw ) ) {
				throw new RuntimeException( __( 'The backup file is incomplete.', 'vaultly-backup' ) );
			}
			$e = unpack( 'vlen/Vmtime/Psize/Vcrc', $raw );
			if ( 0 === $e['len'] ) {
				return;
			}
			$name = (string) fread( $h, $e['len'] );
			$at   = $pos + self::HEAD + $e['len'];
			$pos  = $at + $e['size'];
			if ( strlen( $name ) !== $e['len'] || $pos > $len ) {
				throw new RuntimeException( __( 'The backup file is incomplete.', 'vaultly-backup' ) );
			}
			yield array( 'name' => $name, 'size' => $e['size'], 'crc' => $e['crc'], 'mtime' => $e['mtime'], 'at' => $at, 'next' => $pos );
		}
	}

	/** The manifest of a .vaultly file (array), or an exception if it is not one / is cut short. */
	public static function manifest( string $path ): array {
		$h = @fopen( $path, 'rb' );
		if ( ! $h ) {
			throw new RuntimeException( __( 'Backup not found.', 'vaultly-backup' ) );
		}
		try {
			$size = fstat( $h )['size'];
			if ( self::MAGIC !== fread( $h, self::START ) ) {
				throw new RuntimeException( __( 'This is not a backup created by Vaultly Backup.', 'vaultly-backup' ) );
			}
			if ( $size < self::START + self::HEAD + self::FOOTER || 0 !== fseek( $h, -self::FOOTER, SEEK_END ) ) {
				throw new RuntimeException( __( 'The backup file is incomplete.', 'vaultly-backup' ) );
			}
			$f = (string) fread( $h, self::FOOTER );
			if ( 'VLTE' !== substr( $f, 8 ) ) {
				throw new RuntimeException( __( 'The backup file is incomplete.', 'vaultly-backup' ) );
			}
			$off = unpack( 'P', $f )[1];
			$e   = $off >= self::START && $off < $size ? self::entries( $h, $off )->current() : null;
			if ( ! $e || 'manifest.json' !== $e['name'] || $e['size'] > 4194304 ) {
				throw new RuntimeException( __( 'This is not a backup created by Vaultly Backup.', 'vaultly-backup' ) );
			}
			fseek( $h, $e['at'] );
			$man = json_decode( (string) fread( $h, $e['size'] ), true );
			if ( ! is_array( $man ) ) {
				throw new RuntimeException( __( 'This is not a backup created by Vaultly Backup.', 'vaultly-backup' ) );
			}
			return $man;
		} finally {
			fclose( $h );
		}
	}

	private static function w( $h, string $s ): void {
		if ( strlen( $s ) !== fwrite( $h, $s ) ) {
			throw new RuntimeException( __( 'Cannot write archive (disk full?).', 'vaultly-backup' ) );
		}
	}
}
