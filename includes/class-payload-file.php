<?php
/**
 * How a stored payload file is named, compressed, written and read back:
 * one implementation for the undo store (AB_MCP_Undo) and the protected
 * store of the takeover (AB_MCP_Protected_Store, Z8). Moved out of
 * AB_MCP_Undo, whose files are written and read exactly as before.
 *
 * - name(): a basename derived from a token and a secret, so a token seen in
 *   a chat log does not name a fetchable path (the undo store passes
 *   wp_salt( 'auth' ), the protected store the id of its installation).
 * - compress(): gzip where zlib is there, the plain bytes otherwise or when
 *   compression fails; read() tells the two apart by the gzip magic bytes.
 * - write(): a sibling temp file renamed into place. rename() within one
 *   directory is atomic: an interrupted write never leaves a half-written
 *   file under the final name, and the final name gets a new file rather
 *   than having an existing one (or what a link there points to) written
 *   over.
 * - read(): the size on disk checked BEFORE reading, the inflation capped
 *   inside gzdecode(), the raw bytes checked against their SHA-256. The
 *   caller words the refusal; read() names it by one of the REFUSALS codes.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Payload_File
 */
final class AB_MCP_Payload_File {

	/**
	 * The codes of read()'s refusals: no file, an implausible size on disk,
	 * an unreadable file, more raw bytes than the ceiling, a hash that does
	 * not match (or none).
	 */
	const REFUSALS = array( 'missing', 'size', 'read', 'large', 'hash' );

	/**
	 * The basename for a token: SHA-1 of the token and a secret, with an
	 * extension ('.bin' as the undo store has always named its snapshots).
	 *
	 * @param string $token  Token.
	 * @param string $secret Secret the name is derived with.
	 * @param string $ext    Extension, with its dot ('' for none).
	 * @return string
	 */
	public static function name( $token, $secret, $ext = '.bin' ) {
		return sha1( $token . '|' . $secret ) . $ext;
	}

	/**
	 * The bytes a payload file will hold: gzip-compressed where zlib is
	 * available, the plain payload otherwise or when compression fails.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	public static function compress( $payload ) {
		if ( ! function_exists( 'gzencode' ) ) {
			return $payload;
		}
		$blob = gzencode( $payload, 6 );
		return ( false === $blob ) ? $payload : $blob;
	}

	/**
	 * Write bytes to a path through a sibling temp file and rename() into
	 * place: an interrupted write must never leave a half-written file that
	 * later reads as a valid-looking one.
	 *
	 * @param string $path Final path.
	 * @param string $blob Bytes.
	 * @return bool Whether the file is in place.
	 */
	public static function write( $path, $blob ) {
		$tmp = $path . '.' . bin2hex( self::random_bytes( 4 ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $blob ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		return true;
	}

	/**
	 * Read a payload file back and verify it against its SHA-256.
	 *
	 * @param string    $path       File.
	 * @param string    $sha        Expected SHA-256 of the raw payload.
	 * @param int|float $stored_max Largest size on disk that is plausible.
	 * @param int       $raw_max    Largest raw payload; a compressed file is
	 *                              inflated against it.
	 * @return string|WP_Error The raw payload, or a WP_Error whose code is
	 *                         one of REFUSALS (no message: the caller words it).
	 */
	public static function read( $path, $sha, $stored_max, $raw_max ) {
		if ( ! is_file( $path ) ) {
			return self::refused( 'missing' );
		}
		// Check the size on disk BEFORE reading: a tampered file must not be
		// pulled into memory first and rejected afterwards.
		$on_disk = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $on_disk || $on_disk > $stored_max ) {
			return self::refused( 'size' );
		}
		$blob = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $blob ) {
			return self::refused( 'read' );
		}
		$raw = $blob;
		if ( function_exists( 'gzdecode' ) && "\x1f\x8b" === substr( $blob, 0, 2 ) ) {
			// The length cap is passed INTO gzdecode: a crafted stream that
			// would expand far beyond it fails right here instead of being
			// inflated in full and measured afterwards. One byte over the cap,
			// so "exactly at the cap" and "past it" stay apart — without
			// running past PHP_INT_MAX when a filter sets the cap that high.
			$decoded = @gzdecode( $blob, ( $raw_max < PHP_INT_MAX ) ? $raw_max + 1 : PHP_INT_MAX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $decoded ) {
				$raw = $decoded;
			}
		}
		// A payload can never legitimately be larger than the ceiling that
		// was applied when it was written; a compressed file that expands
		// past it is a decompression bomb.
		if ( strlen( $raw ) > $raw_max ) {
			return self::refused( 'large' );
		}
		// The hash is not optional: without one the bytes are not trusted.
		if ( ! preg_match( '/^[0-9a-f]{64}\z/', (string) $sha ) || ! hash_equals( (string) $sha, hash( 'sha256', $raw ) ) ) {
			return self::refused( 'hash' );
		}
		return $raw;
	}

	/**
	 * Random bytes, from random_bytes() or, where it fails, wp_rand().
	 *
	 * @param int $n Bytes.
	 * @return string
	 */
	public static function random_bytes( $n ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $n );
			} catch ( \Exception $e ) {
				// fall through
			}
		}
		$out = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$out .= chr( wp_rand( 0, 255 ) );
		}
		return $out;
	}

	/**
	 * A refusal of read(), by its code only.
	 *
	 * @param string $code One of REFUSALS.
	 * @return WP_Error
	 */
	private static function refused( $code ) {
		return new WP_Error( $code );
	}
}
