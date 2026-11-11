<?php
/**
 * The comparison of the core of WordPress (Z8b, decision E18 of the plan of
 * 11.11.2026; branch 2): each site checks its own core files against the
 * checksums api.wordpress.org gives for its version and language, and the
 * report shows both sides: equal (both as shipped), differs (how many files
 * differ or are missing on each side) or not compared (the service not
 * reached, or the time of a call over). WordPress itself is never taken
 * over (A17): no kind of the takeover writes the core (AB_MCP_Sync_Kinds:
 * OWNERSHIP gives no key of the core to a kind, so no kind can claim one);
 * the way is the host, or a new install in wp-admin.
 *
 * THE ONE PLACE (plan 3.1, Z35): checksums() asks for the checksums, only
 * over https and only through AB_MCP_Outbound (the addresses of the host
 * checked and tied, no redirect followed), never through
 * get_core_checksums(), which asks again over http where https fails (core:
 * wp-admin/includes/update.php:131-158).
 *
 * THE ANSWER of api.wordpress.org (checksums/1.0 with version and locale,
 * read 12.11.2026 for 7.1.3 and en_US, kept as tests/fixtures/core-checksums):
 * {"checksums":{"<path from the folder of WordPress>":"<md5, 32 hex>", ...}},
 * paths with forward slashes, those of wp-content (the bundled plugins and
 * themes) among them; for a version it does not know {"checksums":false}.
 * The files of wp-content are not core here: they are units of the manifest
 * (AB_MCP_Sync_File_Manifest) or files of the site.
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 2: HTTP to api.wordpress.org,
 * reading the core files) go through AB_MCP_Sync_Core_Compare_Calls, held by
 * the seam of this class; only the tests put another object there.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-outbound.php';

/**
 * The calls of the comparison of the core to the outside: the real ones.
 */
final class AB_MCP_Sync_Core_Compare_Calls {

	/**
	 * GET an address through AB_MCP_Outbound.
	 *
	 * @param string $url  Address.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error
	 */
	public function get( $url, array $args ) {
		return AB_MCP_Outbound::get( (string) $url, $args );
	}

	/**
	 * The MD5 of a file, false where it cannot be read.
	 *
	 * @param string $path File.
	 * @return string|false
	 */
	public function md5( $path ) {
		return is_file( (string) $path ) && is_readable( (string) $path ) ? @md5_file( (string) $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that cannot be read differs.
	}

	/**
	 * Whether a path is there (as a file or anything else).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		return file_exists( (string) $path ) || is_link( (string) $path );
	}
}

/**
 * Class AB_MCP_Sync_Core_Compare
 */
final class AB_MCP_Sync_Core_Compare {

	/** The service of the checksums (only https). */
	const API = 'https://api.wordpress.org/core/checksums/1.0/';

	/** Seconds the request waits at most. */
	const TIMEOUT = 10;

	/** Bytes of an answer at most (the answer for 7.1.3 has 327 182). */
	const MAX_BODY = 4194304;

	/** What is not core in the list of the service. */
	const NOT_CORE = 'wp-content/';

	/** Why a side was not compared. */
	const WHY = array( 'unreachable', 'answer', 'unknown_version', 'no_version' );

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_core_compare = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Core_Compare_Calls
	 */
	private static function calls() {
		return self::$naht_sync_core_compare ?? new AB_MCP_Sync_Core_Compare_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_core_compare = null;
	}

	/**
	 * The checksums of the core files of a version and language, from
	 * api.wordpress.org over https through AB_MCP_Outbound (Z35).
	 *
	 * @param string $version Version of WordPress (AB_MCP_Sync_Manifest::WORDPRESS).
	 * @param string $locale  Language of the package of WordPress (en_US where none).
	 * @return array<string,string>|WP_Error Path => md5; code ab_mcp_sync_core_unreachable,
	 *                                      _answer or _unknown_version.
	 */
	public static function checksums( $version, $locale ) {
		$url = self::API . '?' . http_build_query(
			array(
				'version' => (string) $version,
				'locale'  => (string) $locale,
			),
			'',
			'&'
		);
		// Over https only (API), no redirect followed (a redirect to http is
		// an answer, not followed), never a second try over http.
		$response = self::calls()->get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return new WP_Error( 'ab_mcp_sync_core_unreachable', 'api.wordpress.org was not reached.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( 200 !== $status || ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'ab_mcp_sync_core_unreachable', 'api.wordpress.org did not answer with the checksums.' );
		}
		$data = json_decode( $body, true, 8 );
		if ( ! is_array( $data ) || ! array_key_exists( 'checksums', $data ) ) {
			return new WP_Error( 'ab_mcp_sync_core_answer', 'The answer of api.wordpress.org holds no checksums.' );
		}
		if ( false === $data['checksums'] ) {
			return new WP_Error( 'ab_mcp_sync_core_unknown_version', 'api.wordpress.org knows no checksums of this version and language.' );
		}
		if ( ! is_array( $data['checksums'] ) || array() === $data['checksums'] ) {
			return new WP_Error( 'ab_mcp_sync_core_answer', 'The answer of api.wordpress.org holds no checksums.' );
		}
		$out = array();
		foreach ( $data['checksums'] as $path => $md5 ) {
			// A relative path of the folder of WordPress, and an md5.
			if ( ! is_string( $path ) || ! self::relative( $path ) || ! is_string( $md5 ) || 1 !== preg_match( '/^[0-9a-f]{32}\z/', $md5 ) ) {
				return new WP_Error( 'ab_mcp_sync_core_answer', 'The answer of api.wordpress.org holds an entry that is no path with its md5.' );
			}
			$out[ $path ] = $md5;
		}
		return $out;
	}

	/**
	 * The check of the core of this site for its line of the environment:
	 * every file of the list of the service but those of wp-content, by its
	 * md5, in the folder of WordPress.
	 *
	 * @param float $deadline Until when (seconds, the clock of AB_MCP_Sync_Env).
	 * @return array{cause:string|null,checked:int,complete:bool,differs:int,missing:int}|null
	 *               null: the time of the call ran out (a later call checks again).
	 */
	public static function check( $deadline ) {
		$out     = array(
			'cause'    => null,
			'checked'  => 0,
			'complete' => false,
			'differs'  => 0,
			'missing'  => 0,
		);
		$version = AB_MCP_Sync_Manifest::wordpress();
		if ( '' === $version ) {
			$out['cause'] = 'no_version';
			return $out;
		}
		$locale = isset( $GLOBALS['wp_local_package'] ) && is_string( $GLOBALS['wp_local_package'] ) && '' !== $GLOBALS['wp_local_package'] ? $GLOBALS['wp_local_package'] : 'en_US';
		$sums   = self::checksums( $version, $locale );
		if ( is_wp_error( $sums ) ) {
			$out['cause'] = self::cause( $sums->get_error_code() );
			return $out;
		}
		$calls = self::calls();
		$root  = rtrim( (string) ABSPATH, '/' ) . '/';
		$tick  = 0;
		foreach ( $sums as $path => $md5 ) {
			if ( 0 === strpos( $path, self::NOT_CORE ) ) {
				continue;
			}
			if ( 0 === ( ++$tick % 64 ) && self::now() >= (float) $deadline ) {
				return null;
			}
			++$out['checked'];
			$file = $root . $path;
			if ( ! $calls->exists( $file ) ) {
				++$out['missing'];
				continue;
			}
			$have = $calls->md5( $file );
			if ( ! is_string( $have ) || ! hash_equals( $md5, $have ) ) {
				++$out['differs'];
			}
		}
		$out['complete'] = true;
		return $out;
	}

	/**
	 * The cause of a side not compared, from the code of the error of
	 * checksums(): a code of WHY.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function cause( $code ) {
		$cause = (string) substr( (string) $code, strlen( 'ab_mcp_sync_core_' ) );
		return in_array( $cause, self::WHY, true ) ? $cause : 'answer';
	}

	/**
	 * Whether a path of the answer is a plain relative path in the folder of
	 * WordPress: no leading slash, no segment . or .., no backslash, no NUL.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function relative( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' === $path[0] || false !== strpos( $path, '\\' ) || false !== strpos( $path, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return float Seconds (the clock of AB_MCP_Sync_Env, which tests set).
	 */
	private static function now() {
		return class_exists( 'AB_MCP_Sync_Env', false ) && null !== AB_MCP_Sync_Env::$clock ? (float) call_user_func( AB_MCP_Sync_Env::$clock ) : microtime( true );
	}
}
