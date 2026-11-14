<?php
/**
 * The helpers of the steps of a reading under a deadline (Z8b branch 5,
 * R12b, section 3.1 of the order: the one place of the promise (a)). For
 * each function of a step that a reading outside the chain of the check of
 * names calls (a read from the disk, a call of the database, a codec, a
 * sort) there is one static method of the same name here, with the deadline
 * as its first parameter and otherwise the parameters of the function:
 *
 * - it checks the deadline first (AB_MCP_Sync_Deadline::left()); where that
 *   is not true, it answers with that stop, and the step does not run;
 * - else it calls the function with its arguments unchanged and answers with
 *   its result unchanged;
 * - without a deadline (null) it is the bare call, without a reading of the
 *   clock. It sets no default value the caller does not set: an optional
 *   parameter of the function is passed on only where the caller gives it
 *   (the variadic tail ...$more).
 *
 * A method of $wpdb has its helper as wpdb_<method> (the name of a method
 * holds no ->): wpdb_get_var() is the step $wpdb->get_var() on the global
 * $wpdb, as the guard of the read budget reads it (tests/ReadBudgetScan.php).
 *
 * Not here (R12b, part 3.1): fopen(), fstat() and fread(), steps only of the
 * atomic seams of AB_MCP_Sync_File_Manifest_Calls, which stay direct calls
 * (section 3.1 of the order); idn_to_ascii(), called through the callable of
 * AB_MCP_Sync_Key::idn() (part 4). The steps of the chain stay direct calls.
 *
 * $watch is the seam of the tests (as AB_MCP_Sync_Env::$clock): told of each
 * check ('check') and of each step that then runs ('run'), so a test tells
 * the check of the deadline from the operation run; a call refused is no
 * step run. Null outside the tests.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-deadline.php';

/**
 * Class AB_MCP_Sync_Step
 */
final class AB_MCP_Sync_Step {

	/** @var callable|null Tests only: told ( 'check' or 'run', the step ). */
	public static $watch = null;

	/* ------------------------------------------------------------ the disk */

	/**
	 * is_file() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for is_file().
	 * @return bool|WP_Error
	 */
	public static function is_file( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return is_file( $filename );
	}

	/**
	 * is_readable() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for is_readable().
	 * @return bool|WP_Error
	 */
	public static function is_readable( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return is_readable( $filename );
	}

	/**
	 * filesize() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for filesize().
	 * @return int|false|WP_Error
	 */
	public static function filesize( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return filesize( $filename );
	}

	/**
	 * file_get_contents() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for file_get_contents().
	 * @param mixed  ...$more  Its optional arguments, where the caller gives them.
	 * @return string|false|WP_Error
	 */
	public static function file_get_contents( $deadline, $filename, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return file_get_contents( $filename, ...$more ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the step of the caller, unchanged.
	}

	/**
	 * scandir() under the deadline.
	 *
	 * @param mixed  $deadline  Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $directory As for scandir().
	 * @param mixed  ...$more   Its optional arguments, where the caller gives them.
	 * @return array|false|WP_Error
	 */
	public static function scandir( $deadline, $directory, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return scandir( $directory, ...$more );
	}

	/**
	 * lstat() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for lstat().
	 * @return array|false|WP_Error
	 */
	public static function lstat( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return lstat( $filename );
	}

	/**
	 * stat() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for stat().
	 * @return array|false|WP_Error
	 */
	public static function stat( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return stat( $filename );
	}

	/**
	 * realpath() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $path     As for realpath().
	 * @return string|false|WP_Error
	 */
	public static function realpath( $deadline, $path ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return realpath( $path );
	}

	/* -------------------------------------------------------- the database */

	/**
	 * get_option() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $option   As for get_option().
	 * @param mixed  ...$more  Its optional argument, where the caller gives it.
	 * @return mixed|WP_Error
	 */
	public static function get_option( $deadline, $option, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return get_option( $option, ...$more );
	}

	/**
	 * home_url() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return string|WP_Error
	 */
	public static function home_url( $deadline, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return home_url( ...$more );
	}

	/**
	 * admin_url() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return string|WP_Error
	 */
	public static function admin_url( $deadline, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return admin_url( ...$more );
	}

	/**
	 * wp_upload_dir() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return array|WP_Error
	 */
	public static function wp_upload_dir( $deadline, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return wp_upload_dir( ...$more );
	}

	/**
	 * $wpdb->get_var() on the global $wpdb under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return string|null|WP_Error
	 */
	public static function wpdb_get_var( $deadline, ...$more ) {
		global $wpdb;
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return $wpdb->get_var( ...$more ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- the query of the caller, unchanged.
	}

	/* ----------------------------------------------------------- the codecs */

	/**
	 * hash() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $algo     As for hash().
	 * @param string $data     As for hash().
	 * @param mixed  ...$more  Its optional arguments, where the caller gives them.
	 * @return string|WP_Error
	 */
	public static function hash( $deadline, $algo, $data, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return hash( $algo, $data, ...$more );
	}

	/**
	 * hash_init() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $algo     As for hash_init().
	 * @param mixed  ...$more  Its optional arguments, where the caller gives them.
	 * @return HashContext|WP_Error
	 */
	public static function hash_init( $deadline, $algo, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return hash_init( $algo, ...$more );
	}

	/**
	 * hash_update() under the deadline.
	 *
	 * @param mixed       $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param HashContext $context  As for hash_update().
	 * @param string      $data     As for hash_update().
	 * @return true|WP_Error
	 */
	public static function hash_update( $deadline, $context, $data ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return hash_update( $context, $data );
	}

	/**
	 * hash_final() under the deadline.
	 *
	 * @param mixed       $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param HashContext $context  As for hash_final().
	 * @param mixed       ...$more  Its optional argument, where the caller gives it.
	 * @return string|WP_Error
	 */
	public static function hash_final( $deadline, $context, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return hash_final( $context, ...$more );
	}

	/**
	 * sha1() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $string   As for sha1().
	 * @param mixed  ...$more  Its optional argument, where the caller gives it.
	 * @return string|WP_Error
	 */
	public static function sha1( $deadline, $string, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return sha1( $string, ...$more );
	}

	/**
	 * json_decode() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $json     As for json_decode().
	 * @param mixed  ...$more  Its optional arguments, where the caller gives them.
	 * @return mixed|WP_Error
	 */
	public static function json_decode( $deadline, $json, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return json_decode( $json, ...$more );
	}

	/**
	 * json_encode() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed $value    As for json_encode().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return string|false|WP_Error
	 */
	public static function json_encode( $deadline, $value, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return json_encode( $value, ...$more ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the codec of the caller, unchanged.
	}

	/**
	 * wp_json_encode() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed $value    As for wp_json_encode().
	 * @param mixed ...$more  Its optional arguments, where the caller gives them.
	 * @return string|false|WP_Error
	 */
	public static function wp_json_encode( $deadline, $value, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return wp_json_encode( $value, ...$more );
	}

	/**
	 * maybe_unserialize() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param mixed $data     As for maybe_unserialize().
	 * @return mixed|WP_Error
	 */
	public static function maybe_unserialize( $deadline, $data ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return maybe_unserialize( $data );
	}

	/**
	 * base64_encode() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $string   As for base64_encode().
	 * @return string|WP_Error
	 */
	public static function base64_encode( $deadline, $string ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return base64_encode( $string ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the codec of the caller, unchanged.
	}

	/**
	 * base64_decode() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $string   As for base64_decode().
	 * @param mixed  ...$more  Its optional argument, where the caller gives it.
	 * @return string|false|WP_Error
	 */
	public static function base64_decode( $deadline, $string, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return base64_decode( $string, ...$more ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the codec of the caller, unchanged.
	}

	/**
	 * bin2hex() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $string   As for bin2hex().
	 * @return string|WP_Error
	 */
	public static function bin2hex( $deadline, $string ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return bin2hex( $string );
	}

	/**
	 * hex2bin() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $string   As for hex2bin().
	 * @return string|false|WP_Error
	 */
	public static function hex2bin( $deadline, $string ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return hex2bin( $string );
	}

	/**
	 * pack() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $format   As for pack().
	 * @param mixed  ...$values As for pack().
	 * @return string|WP_Error
	 */
	public static function pack( $deadline, $format, ...$values ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return pack( $format, ...$values );
	}

	/**
	 * gzdecode() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $data     As for gzdecode().
	 * @param mixed  ...$more  Its optional argument, where the caller gives it.
	 * @return string|false|WP_Error
	 */
	public static function gzdecode( $deadline, $data, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return gzdecode( $data, ...$more );
	}

	/**
	 * inflate_init() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param int   $encoding As for inflate_init().
	 * @param mixed ...$more  Its optional argument, where the caller gives it.
	 * @return InflateContext|false|WP_Error
	 */
	public static function inflate_init( $deadline, $encoding, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return inflate_init( $encoding, ...$more );
	}

	/**
	 * inflate_add() under the deadline.
	 *
	 * @param mixed          $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param InflateContext $context  As for inflate_add().
	 * @param string         $data     As for inflate_add().
	 * @param mixed          ...$more  Its optional argument, where the caller gives it.
	 * @return string|false|WP_Error
	 */
	public static function inflate_add( $deadline, $context, $data, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return inflate_add( $context, $data, ...$more );
	}

	/**
	 * sodium_crypto_sign_verify_detached() under the deadline.
	 *
	 * @param mixed  $deadline   Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $signature  As for sodium_crypto_sign_verify_detached().
	 * @param string $message    As for sodium_crypto_sign_verify_detached().
	 * @param string $public_key As for sodium_crypto_sign_verify_detached().
	 * @return bool|WP_Error
	 */
	public static function sodium_crypto_sign_verify_detached( $deadline, $signature, $message, $public_key ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
	}

	/* ------------------------------------------------------------ the sorts */

	/**
	 * sort() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param array $array    As for sort(), by reference.
	 * @param mixed ...$more  Its optional argument, where the caller gives it.
	 * @return true|WP_Error
	 */
	public static function sort( $deadline, &$array, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return sort( $array, ...$more );
	}

	/**
	 * ksort() under the deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param array $array    As for ksort(), by reference.
	 * @param mixed ...$more  Its optional argument, where the caller gives it.
	 * @return true|WP_Error
	 */
	public static function ksort( $deadline, &$array, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return ksort( $array, ...$more );
	}

	/**
	 * usort() under the deadline.
	 *
	 * @param mixed    $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param array    $array    As for usort(), by reference.
	 * @param callable $callback As for usort().
	 * @return true|WP_Error
	 */
	public static function usort( $deadline, &$array, $callback ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return usort( $array, $callback );
	}

	/* ----------------------------------------------------------- the check */

	/**
	 * The check of a helper: the deadline (true, or the stop), its seam told
	 * of the check and, where the step runs, of the step.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $step     The step (the name of the helper).
	 * @return true|WP_Error
	 */
	private static function left( $deadline, $step ) {
		self::tell( 'check', $step );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true === $left ) {
			self::tell( 'run', $step );
		}
		return $left;
	}

	/**
	 * Tells the seam of the tests, where one is set.
	 *
	 * @param string $what  'check' or 'run'.
	 * @param string $step  The step.
	 */
	private static function tell( $what, $step ) {
		if ( null !== self::$watch ) {
			call_user_func( self::$watch, $what, $step );
		}
	}
}
