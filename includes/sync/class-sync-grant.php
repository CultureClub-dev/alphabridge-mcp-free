<?php
/**
 * The signed fetch addresses of the packages of a staging site and the route
 * that answers them (Z8, decision 6 of the plan of 01.11.2026; contract B).
 *
 * A fetch address names a package, a chunk and until when, signed by this
 * site with a MAC (AB_MCP_Sync_Key::mac(), with the secret this site keeps
 * for its MACs), valid GRANT_TTL seconds (grants()). The production site
 * asks the route ROUTE with exactly those four values (AB_MCP_Sync_Import).
 *
 * The route is no route of WordPress: serve() answers on rest_pre_dispatch
 * a request that is exactly right (grant()) and returns every other one to
 * WordPress, which answers it as it answers a route it does not know
 * (rest_no_route), byte for byte; so is a signature that is wrong, late or
 * for another chunk, a package that is gone, another method, any other
 * parameter. This class is loaded with the plugin and checks the request
 * with no more than the two options of the MAC and the role read: the
 * export, the inventory and the kinds of the database are loaded only once
 * the MAC is right (AB_MCP_Sync_Export::deliver()). The chunk is sent as it
 * is (send()), nothing is redirected. The known limit (decision 6): whoever
 * sees such an address within its ten minutes reads that chunk.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-key.php';

/**
 * Class AB_MCP_Sync_Grant
 */
final class AB_MCP_Sync_Grant {

	/** The route of the chunks, in the namespace of the plugin. */
	const ROUTE = '/staging-package';

	/** Seconds a signed fetch address is valid (decision 6). */
	const GRANT_TTL = 600;

	/** The purpose of the MAC of a fetch address (AB_MCP_Sync_Key::mac()). */
	const MAC = 'ab-sync-fetch/1';

	/** The parameters of the route, sorted. */
	const QUERY = array( 'chunk', 'expires', 'package', 'sig' );

	/**
	 * The response serve() accepted and its bytes, until send() writes them.
	 *
	 * @var array{0:WP_REST_Response,1:string}|null
	 */
	private static $serving = null;

	/**
	 * The fetch addresses of a package: per chunk until when and the MAC of
	 * this site over package, chunk and that time.
	 *
	 * @param string   $package_id Package.
	 * @param int      $count      Chunks.
	 * @param int|null $now        Time; now when null.
	 * @return array[]|WP_Error chunk, expires, signature.
	 */
	public static function grants( $package_id, $count, $now = null ) {
		$expires = ( null === $now ? time() : (int) $now ) + self::GRANT_TTL;
		$out     = array();
		for ( $i = 0; $i < (int) $count; $i++ ) {
			$mac = AB_MCP_Sync_Key::mac( self::MAC, self::grant_text( $package_id, $i, $expires ) );
			if ( null === $mac ) {
				return new WP_Error( 'ab_mcp_sync_key_missing', 'This staging site has no usable key: make it on the page AlphaBridge → Staging under «Key of this staging site», confirm it on the production site, then call again.' );
			}
			$out[] = array(
				'chunk'     => $i,
				'expires'   => $expires,
				'signature' => $mac,
			);
		}
		return $out;
	}

	/**
	 * What a fetch address signs.
	 *
	 * @param string $package_id Package.
	 * @param int    $chunk      Chunk.
	 * @param int    $expires    Until when.
	 * @return string
	 */
	public static function grant_text( $package_id, $chunk, $expires ) {
		return (string) $package_id . "\n" . (int) $chunk . "\n" . (int) $expires;
	}

	/**
	 * The route of the chunks, on rest_pre_dispatch: a request that is
	 * exactly right gets its chunk; every other one goes on to WordPress,
	 * which knows no such route and answers as for any it does not know.
	 *
	 * @param mixed           $result  What another filter chose, or null.
	 * @param WP_REST_Server  $server  Server (unused).
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function serve( $result, $server, $request ) {
		unset( $server );
		// Like WordPress, which serves whatever is not empty here.
		if ( ! empty( $result ) || ! $request instanceof WP_REST_Request || '/' . AB_MCP_REST_NAMESPACE . self::ROUTE !== $request->get_route() ) {
			return $result;
		}
		$now   = time();
		$grant = self::grant( $request, $now );
		if ( null === $grant ) {
			return $result;
		}
		// Only now, with a MAC of this site, the package is looked up.
		require_once __DIR__ . '/class-sync-export.php';
		$bytes = AB_MCP_Sync_Export::deliver( $grant, $now );
		if ( null === $bytes ) {
			return $result;
		}
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/octet-stream' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		self::$serving = array( $response, $bytes );
		if ( false === has_filter( 'rest_pre_serve_request', array( __CLASS__, 'send' ) ) ) {
			add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send' ), 10, 3 );
		}
		return $response;
	}

	/**
	 * Send the bytes of the response serve() accepted, as they are (on
	 * rest_pre_serve_request, after WordPress sent status and headers).
	 *
	 * @param bool             $served  Whether the request was served.
	 * @param WP_REST_Response $result  Response.
	 * @param WP_REST_Request  $request Request (unused).
	 * @return bool
	 */
	public static function send( $served, $result, $request ) {
		unset( $request );
		if ( $served || null === self::$serving || self::$serving[0] !== $result ) {
			return $served;
		}
		$bytes         = self::$serving[1];
		self::$serving = null;
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the bytes of a chunk, application/octet-stream.
		return true;
	}

	/**
	 * What a request of the route is signed for, or null where it is not
	 * exactly right: GET without a body, the four parameters and no other
	 * (rest_route aside, which routed it), chunk and until when in their one
	 * spelling, until when later than now and at most GRANT_TTL from now,
	 * and the MAC of this site over package, chunk and that time (which
	 * holds package and signature to their one spelling: another one is
	 * another text or another code).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $now     Time.
	 * @return array{package:string,chunk:int}|null
	 */
	public static function grant( WP_REST_Request $request, $now ) {
		$query = $request->get_query_params();
		unset( $query['rest_route'] );
		if ( 'GET' !== $request->get_method() || self::QUERY !== self::sorted_keys( $query ) || array() !== $request->get_body_params() || '' !== (string) $request->get_body() ) {
			return null;
		}
		foreach ( $query as $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
		}
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,3})\z/', $query['chunk'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,11}\z/', $query['expires'] ) ) {
			return null;
		}
		$chunk   = (int) $query['chunk'];
		$expires = (int) $query['expires'];
		if ( $expires <= $now || $expires > $now + self::GRANT_TTL ) {
			return null;
		}
		$mac = AB_MCP_Sync_Key::mac( self::MAC, self::grant_text( $query['package'], $chunk, $expires ) );
		if ( null === $mac || ! hash_equals( $mac, $query['sig'] ) ) {
			return null;
		}
		return array(
			'package' => $query['package'],
			'chunk'   => $chunk,
		);
	}

	/**
	 * @param array $array Array.
	 * @return string[] Its keys, sorted.
	 */
	private static function sorted_keys( array $array ) {
		$keys = array_map( 'strval', array_keys( $array ) );
		sort( $keys );
		return $keys;
	}
}
