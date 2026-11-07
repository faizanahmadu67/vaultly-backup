<?php
/**
 * Append-only streaming ZIP64 writer.
 *
 * Why not ZipArchive? ZipArchive::close() rewrites the WHOLE archive every time, so adding files in
 * batches over many requests costs O(size^2) I/O (a 20 GB site = terabytes of copying). Here every
 * file is appended once; the central directory is kept in a side file and written at the end.
 * Reading (verify / restore / listing) still uses ZipArchive, which understands ZIP64.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.DB.DirectDatabaseQuery,Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.WriteFile -- backup engine: streams multi-GB archives in resumable chunks inside its own wp-content/vaultly-backups folder; WP_Filesystem cannot stream or append. Exception messages go to the admin JSON/log (never echoed as HTML), and are translated, trusted strings.

final class VAULTLY_Zip {

	const LEVEL = 1; // deflate level: ~3x faster than 6, only a few % bigger

	/** Appends one entry read from stream $in. $h = archive (positioned anywhere), $c = central-directory side file (append). */
	public static function add_stream( $h, $c, string $name, $in, bool $store, int $mtime, int $limit = -1 ): void {
		fseek( $h, 0, SEEK_END );
		$off  = ftell( $h );
		$nl   = strlen( $name );
		$dt   = self::dos( $mtime );
		$meth = $store ? 0 : 8;
		// Local header with zip64 extra; crc/sizes are patched in afterwards (the file is seekable).
		self::w( $h, pack( 'VvvvvvVVVvv', 0x04034b50, 45, 0x0800, $meth, $dt[1], $dt[0], 0, 0xFFFFFFFF, 0xFFFFFFFF, $nl, 20 ) . $name . pack( 'vvPP', 1, 16, 0, 0 ) );

		$crc  = hash_init( 'crc32b' );
		$def  = $store ? null : deflate_init( ZLIB_ENCODING_RAW, array( 'level' => self::LEVEL ) );
		$usz  = $csz = 0;
		while ( ! feof( $in ) && 0 !== $limit ) {
			$b = fread( $in, $limit < 0 ? 1 << 20 : min( 1 << 20, $limit ) );
			if ( false === $b ) {
				throw new RuntimeException( sprintf( /* translators: %s: file name */ __( 'Cannot read %s.', 'vaultly-backup' ), $name ) );
			}
			if ( '' === $b ) {
				break;
			}
			hash_update( $crc, $b );
			$usz += strlen( $b );
			if ( $limit > 0 ) {
				$limit -= strlen( $b );
			}
			$o    = $store ? $b : deflate_add( $def, $b, ZLIB_NO_FLUSH );
			$csz += strlen( $o );
			self::w( $h, $o );
		}
		if ( $def ) {
			$o    = deflate_add( $def, '', ZLIB_FINISH );
			$csz += strlen( $o );
			self::w( $h, $o );
		}
		$crc32 = hexdec( hash_final( $crc ) );
		$end   = ftell( $h );
		fseek( $h, $off + 14 );
		self::w( $h, pack( 'V', $crc32 ) );
		fseek( $h, $off + 30 + $nl + 4 );
		self::w( $h, pack( 'PP', $usz, $csz ) );
		fseek( $h, $end );

		self::w( $c, pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 45, 45, 0x0800, $meth, $dt[1], $dt[0], $crc32, 0xFFFFFFFF, 0xFFFFFFFF, $nl, 28, 0, 0, 0, 0, 0xFFFFFFFF )
			. $name . pack( 'vvPPP', 1, 24, $usz, $csz, $off ) );
	}

	public static function add_string( $h, $c, string $name, string $data ): void {
		$m = fopen( 'php://memory', 'w+b' );
		self::w( $m, $data );
		rewind( $m );
		self::add_stream( $h, $c, $name, $m, false, time() );
		fclose( $m );
	}

	/** Writes central directory + ZIP64 end records. $n = number of entries. */
	public static function finish( $h, $c, int $n ): void {
		fseek( $h, 0, SEEK_END );
		$cd = ftell( $h );
		fseek( $c, 0 );
		$len = stream_copy_to_stream( $c, $h );
		$z64 = $cd + $len;
		self::w( $h, pack( 'VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, $len, $cd ) );
		self::w( $h, pack( 'VVPV', 0x07064b50, 0, $z64, 1 ) );
		self::w( $h, pack( 'VvvvvVVv', 0x06054b50, 0, 0, min( $n, 0xFFFF ), min( $n, 0xFFFF ), min( $len, 0xFFFFFFFF ), min( $cd, 0xFFFFFFFF ), 0 ) );
	}

	private static function w( $h, string $s ): void {
		if ( strlen( $s ) !== fwrite( $h, $s ) ) {
			throw new RuntimeException( __( 'Cannot write archive (disk full?).', 'vaultly-backup' ) );
		}
	}

	private static function dos( int $t ): array {
		$d = getdate( max( $t, 315532800 ) );
		return array( ( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'], ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ) );
	}
}
