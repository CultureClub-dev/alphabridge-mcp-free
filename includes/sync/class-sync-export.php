<?php
/**
 * The packages of the staging site (Z8, decision 6 of the plan of
 * 01.11.2026; contract B): made by wp_site_export, kept in the protected
 * store, signed by the manifest (AB_MCP_Sync_Manifest), and given out in
 * two ways only.
 *
 * WHAT A PACKAGE IS. A list of chunks, each at most MAX_CHUNK bytes: the
 * gzip of lines of AB_MCP_Sync_Canon::encode(), one object a line, no line
 * split between two chunks (pack()). A chunk is read on its own, against
 * its SHA-256 in the manifest; once inflated it is at most SLICE bytes.
 * The package lies in the area out of the protected store, its id is the id
 * of its group there, and the store removes it a day after it was made
 * (AB_MCP_Protected_Store::KEEP; the manifest expires then too).
 *
 * WHAT IS IN IT, in this version: the inventory of this site
 * (AB_MCP_Sync_Inventory), as far as the budget of one call reaches (time,
 * and OBJECTS objects, below AB_MCP_Sync_Manifest::MAX_OBJECTS because a
 * row may give several), each object as line() writes it from a list of
 * what it may carry: identity, status, locks, hash, size, references by
 * id, the places of possible references, never a value of its content; an
 * object a credential holds only with its identity and the reason. What is
 * left is the next package: the answer carries the cursor to go on from,
 * which names this package; the packages of one inventory are one series
 * and count as one open export (AB_MCP_Store_Budget). The manifest lists
 * each kind and subtype of the package; complete is true only where the
 * package holds every object of it (it began that kind of the database at
 * its start and read it to its end, kinds()). Packages of objects, media
 * and the environment follow with the takeover.
 *
 * WHAT IT IS BOUND TO (binding()): the key, both addresses, the confirmed
 * role and the pair. A package whose binding is not the site's any more
 * (the pair or the role confirmed again, a new key) is given out no more,
 * neither by the route nor by the relay nor with fresh fetch addresses,
 * and is discarded where it is met and before a new package is made.
 *
 * HOW IT LEAVES THE SITE.
 *
 * 1. Direct: the production site asks the route ROUTE of this site for each
 *    chunk, at an address it builds itself from the address of its staging
 *    site (AB_MCP_Sync_Import), with four values from the client: the
 *    package, the chunk, until when, and the signature of this site over
 *    those three (grants(): a MAC with the secret this site keeps for its
 *    MACs, AB_MCP_Sync_Key::mac(), valid GRANT_TTL seconds). The MAC is
 *    checked before anything else is read, so a wrong one costs what an
 *    unknown route costs. The route is no route of WordPress: serve()
 *    answers on rest_pre_dispatch a request that is exactly right and
 *    returns every other one to WordPress, which answers it as it answers a
 *    route it does not know (rest_no_route), byte for byte; so is a
 *    signature that is wrong, late or for another chunk, a package that is
 *    gone, another method, any other parameter. The chunk is sent as it is
 *    (send()), nothing is redirected. The known limit (decision 6): whoever
 *    sees such an address within its ten minutes reads that chunk.
 * 2. Relay: wp_site_export_chunk gives a chunk of a package this account
 *    made as base64 to the client, which hands it to wp_site_import_chunk
 *    on the production site. Both refuse a request that did not come over
 *    https where the site has a public address (relay_refusal()).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-outbound.php';
require_once __DIR__ . '/class-sync-manifest.php';
require_once __DIR__ . '/class-sync-inventory.php';
require_once __DIR__ . '/class-sync-db-kinds.php';

/**
 * Class AB_MCP_Sync_Export
 */
final class AB_MCP_Sync_Export {

	/** The route of the chunks, in the namespace of the plugin. */
	const ROUTE = '/staging-package';

	/** Seconds a signed fetch address is valid (decision 6). */
	const GRANT_TTL = 600;

	/** The purpose of the MAC of a fetch address (AB_MCP_Sync_Key::mac()). */
	const MAC = 'ab-sync-fetch/1';

	/** The format of the record of a package in its group. */
	const RECORD = 'ab-sync-export/1';

	/** Bytes of a record at most: the manifest with its signature and cursor. */
	const RECORD_MAX = 2162688;

	/** Bytes of the lines of one chunk at most: its gzip stays within MAX_CHUNK. */
	const SLICE = 5177344;

	/** Objects one package reads at most (see the class). */
	const OBJECTS = 4000;

	/** The purposes this version makes packages for. */
	const PURPOSES = array( 'inventory' );

	/** The parameters of the route, sorted. */
	const QUERY = array( 'chunk', 'expires', 'package', 'sig' );

	/**
	 * The response serve() accepted and its bytes, until send() writes them.
	 *
	 * @var array{0:WP_REST_Response,1:string}|null
	 */
	private static $serving = null;

	/* --------------------------------------------------------------- make */

	/**
	 * Make a package on this confirmed staging site, for the production site
	 * it is paired with.
	 *
	 * @param mixed    $purpose What the package is for (PURPOSES).
	 * @param mixed    $cursor  The cursor of the answer for the package
	 *                          before, or null for the first of a series.
	 * @param int|null $objects Objects the package reads at most; OBJECTS.
	 * @return array|WP_Error The package (answer()).
	 */
	public static function make( $purpose, $cursor = null, $objects = null ) {
		$refused = self::refusal();
		if ( null !== $refused ) {
			return $refused;
		}
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new WP_Error( 'ab_mcp_sync_export_purpose', sprintf( 'This version makes packages of the inventory of the staging site only; packages of objects, media and the environment come with the takeover. Pass purpose=%s.', self::PURPOSES[0] ) );
		}
		$from = null;
		try {
			$series = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_export_random', 'No package was made: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.' );
		}
		if ( null !== $cursor ) {
			$follows = self::follows( $cursor );
			if ( is_wp_error( $follows ) ) {
				return $follows;
			}
			list( $from, $series ) = $follows;
		}
		$pair   = AB_MCP_Staging::record()['pair'];
		$source = AB_MCP_Sync_Inventory::source( array( home_url(), $pair['partner_url'] ), AB_MCP_Sync_Inventory::BUDGET, null, null === $objects ? self::OBJECTS : (int) $objects );
		$read   = AB_MCP_Sync_Inventory::run( $source, $from );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$packed = self::pack( array_map( array( __CLASS__, 'line' ), $read['objects'] ) );
		if ( is_wp_error( $packed ) ) {
			return $packed;
		}
		$kinds = self::kinds( $read['objects'], $from, $read['cursor'] );
		if ( is_wp_error( $kinds ) ) {
			return $kinds;
		}
		return self::store( $purpose, $packed, $kinds, $read['cursor'], $series );
	}

	/**
	 * Where a package goes on from the one before: the cursor of the answer
	 * names that package, which must be one of this account, kept, bound as
	 * this site is now, and whose next cursor it is; the inventory goes on
	 * from the cursor that package recorded, and the package belongs to its
	 * series (one open export for the budgets, AB_MCP_Store_Budget).
	 *
	 * @param mixed $cursor The cursor of the answer before.
	 * @return array{0:array,1:string}|WP_Error The cursor of the inventory and the series.
	 */
	private static function follows( $cursor ) {
		if ( ! is_array( $cursor ) || array( 'inner', 'kind', 'package' ) !== self::sorted_keys( $cursor ) || ! is_string( $cursor['kind'] ) ) {
			return self::cursor_refusal();
		}
		$before = self::own( $cursor['package'], null );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$from = array(
			'kind'  => $cursor['kind'],
			'inner' => $cursor['inner'],
		);
		// The last package of a series has no next cursor: null encodes as no cursor does.
		if ( AB_MCP_Sync_Canon::encode( $before['cursor'] ) !== AB_MCP_Sync_Canon::encode( $from ) ) {
			return self::cursor_refusal();
		}
		return array( $before['cursor'], $before['series'] );
	}

	/**
	 * @return WP_Error The cursor is not one an answer gave.
	 */
	private static function cursor_refusal() {
		return new WP_Error( 'ab_mcp_sync_cursor_invalid', 'The cursor is not one an answer of wp_site_export gave. Pass the cursor of the answer before as it is, or none to begin with the first package.' );
	}

	/**
	 * The line of an object in a package of the inventory, from a list of
	 * what it may carry (contract A): its identity, status and locks with
	 * their reasons, and unless a credential holds it (a name of a
	 * credential, B9, or a field of one in it), its hash, size, whether it
	 * was read whole, what was not read, its references by id, the places
	 * of possible references and of the units left out as credentials. Of
	 * its content nothing: a possible reference only by its place, never its
	 * value; what the inventory adds later is not in it until it stands on
	 * this list.
	 *
	 * @param array $object An object of the inventory (AB_MCP_Sync_Inventory::entry()).
	 * @return array
	 */
	public static function line( array $object ) {
		$line = array(
			'kind'     => (string) $object['kind'],
			'ref'      => (string) $object['ref'],
			'identity' => $object['identity'],
			'status'   => $object['status'],
			'locks'    => self::fields( $object['locks'], array( 'at', 'key', 'reason', 'stage' ) ),
		);
		if ( isset( $object['reason'] ) ) {
			$line['reason'] = (string) $object['reason'];
		}
		$credentials = $object['credentials'];
		if ( 'b9' === $object['status'] || array() !== $credentials['mixed'] ) {
			return $line;
		}
		return $line + array(
			'canon'    => $object['canon'],
			'hash'     => $object['hash'],
			'size'     => $object['size'],
			'complete' => $object['complete'],
			'unread'   => isset( $object['unread'] ) ? array_values( array_map( 'strval', $object['unread'] ) ) : array(),
			'refs'     => self::fields( $object['refs'], array( 'at', 'id', 'login', 'space', 'strength' ) ),
			'possible' => self::fields( $object['possible'], array( 'at' ) ),
			'b9'       => array_values( array_map( 'strval', $credentials['b9'] ) ),
		);
	}

	/**
	 * Entries of a list with the fields named and no other.
	 *
	 * @param array    $list   Entries.
	 * @param string[] $fields Fields.
	 * @return array[]
	 */
	private static function fields( array $list, array $fields ) {
		$out = array();
		foreach ( $list as $entry ) {
			$out[] = array_intersect_key( (array) $entry, array_flip( $fields ) );
		}
		return $out;
	}

	/**
	 * Lines of objects in chunks: each object one line of
	 * AB_MCP_Sync_Canon::encode(), the lines of a chunk at most SLICE bytes,
	 * each chunk their gzip. A package has a chunk also without an object.
	 * Within the limits of the manifest (AB_MCP_Sync_Manifest), each named
	 * where it is passed.
	 *
	 * @param array[]  $objects Objects.
	 * @param int|null $slice   Bytes of the lines of a chunk at most; SLICE.
	 * @param array    $max     objects, chunks, bytes, unpacked: the limits
	 *                          of the manifest where left out.
	 * @return array{chunks:string[],objects:int,unpacked:int}|WP_Error
	 */
	public static function pack( array $objects, $slice = null, array $max = array() ) {
		$size = null === $slice ? self::SLICE : (int) $slice;
		$max += array(
			'objects'  => AB_MCP_Sync_Manifest::MAX_OBJECTS,
			'chunks'   => AB_MCP_Sync_Manifest::MAX_LIST,
			'bytes'    => AB_MCP_Sync_Manifest::MAX_BYTES,
			'unpacked' => AB_MCP_Sync_Manifest::MAX_UNPACKED,
		);
		if ( count( $objects ) > $max['objects'] ) {
			return self::too_large( 'objects', $max['objects'] );
		}
		$chunks   = array();
		$slice    = '';
		$unpacked = 0;
		foreach ( $objects as $object ) {
			$line = AB_MCP_Sync_Canon::encode( $object );
			if ( '' === $line || strlen( $line ) + 1 > $size ) {
				return new WP_Error( 'ab_mcp_sync_export_object', 'An object of this site cannot be written into a package (it is larger than a chunk holds, or not encodable). Ask the person to check the object named in the inventory, or leave its kind out for now.', array( 'kind' => isset( $object['kind'] ) ? $object['kind'] : null, 'ref' => isset( $object['ref'] ) ? $object['ref'] : null ) );
			}
			if ( strlen( $slice ) + strlen( $line ) + 1 > $size ) {
				$chunks[] = $slice;
				$slice    = '';
			}
			$slice    .= $line . "\n";
			$unpacked += strlen( $line ) + 1;
		}
		$chunks[] = $slice;
		if ( $unpacked > $max['unpacked'] ) {
			return self::too_large( 'unpacked', $max['unpacked'] );
		}
		if ( count( $chunks ) > $max['chunks'] ) {
			return self::too_large( 'chunks', $max['chunks'] );
		}
		$out   = array();
		$bytes = 0;
		foreach ( $chunks as $lines ) {
			// The gzip of at most SLICE bytes stays within MAX_CHUNK (a stored
			// block adds 5 bytes to 65535); zlib that fails gives no package.
			$gz = function_exists( 'gzencode' ) ? gzencode( $lines, 6 ) : false;
			if ( ! is_string( $gz ) ) {
				return new WP_Error( 'ab_mcp_sync_export_gzip', 'The package could not be compressed. Ask the host to enable the zlib extension of PHP, then call again.' );
			}
			$out[]  = $gz;
			$bytes += strlen( $gz );
		}
		if ( $bytes > $max['bytes'] ) {
			return self::too_large( 'bytes', $max['bytes'] );
		}
		return array(
			'chunks'   => $out,
			'objects'  => count( $objects ),
			'unpacked' => $unpacked,
		);
	}

	/**
	 * The refusal of a package past a limit of the manifest.
	 *
	 * @param string $what  objects, chunks, bytes or unpacked.
	 * @param int    $limit The limit.
	 * @return WP_Error
	 */
	private static function too_large( $what, $limit ) {
		$say = array(
			'objects'  => 'objects',
			'chunks'   => 'chunks',
			'bytes'    => 'bytes in its chunks',
			'unpacked' => 'bytes once unpacked',
		);
		return new WP_Error(
			'ab_mcp_sync_export_too_large',
			sprintf( 'The objects read are more than one package holds: at most %1$d %2$s. Ask the person to check the largest objects of the inventory, then call again with the same cursor.', $limit, $say[ $what ] ),
			array(
				'limit' => $what,
				'max'   => $limit,
			)
		);
	}

	/**
	 * The kinds of a package with their subtype (the post type of a post, the
	 * taxonomy of a term, '' otherwise), ascending, each once. Complete is
	 * true only for the kinds of the database the package began at their
	 * start (the cursor it began with points before them, or to one of them
	 * without its inner cursor) and read to their end (the cursor it ended
	 * with points past them, or there is none).
	 *
	 * @param array[]    $objects Objects of the package.
	 * @param array|null $from    The cursor the package began with.
	 * @param array|null $to      The cursor it ended with.
	 * @return array[]|WP_Error kind, subtype, complete.
	 */
	public static function kinds( array $objects, $from, $to ) {
		$kinds = AB_MCP_Sync_Db_Kinds::all();
		if ( is_wp_error( $kinds ) ) {
			return $kinds;
		}
		$order = array();
		$of    = array();
		foreach ( $kinds as $i => $kind ) {
			$order[ $kind->kind() ] = $i;
			foreach ( $kind->pair_kinds() as $pair ) {
				$of[ $pair ] = $i;
			}
		}
		$start = null === $from ? 0 : $order[ $from['kind'] ] + ( null === $from['inner'] ? 0 : 1 );
		$end   = null === $to ? count( $kinds ) : $order[ $to['kind'] ];
		$out   = array();
		foreach ( $objects as $object ) {
			$identity = $object['identity'];
			$subtype  = (string) ( isset( $identity['post_type'] ) ? $identity['post_type'] : ( isset( $identity['taxonomy'] ) ? $identity['taxonomy'] : '' ) );
			$at       = $of[ $object['kind'] ];
			$out[ $object['kind'] . "\0" . $subtype ] = array(
				'complete' => $at >= $start && $at < $end,
				'kind'     => $object['kind'],
				'subtype'  => $subtype,
			);
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				$by = strcmp( $a['kind'], $b['kind'] );
				return 0 !== $by ? $by : strcmp( $a['subtype'], $b['subtype'] );
			}
		);
		return array_values( $out );
	}

	/* ------------------------------------------------------------ given out */

	/**
	 * A package made before, with fresh fetch addresses: only of this
	 * account, only while it is kept.
	 *
	 * @param mixed    $package_id Package.
	 * @param int|null $now        Time; now when null.
	 * @return array|WP_Error The package (answer()).
	 */
	public static function again( $package_id, $now = null ) {
		$refused = self::refusal();
		if ( null !== $refused ) {
			return $refused;
		}
		$found = self::own( $package_id, $now );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		return self::answer( $found['manifest'], $found['signed'], $found['cursor'] );
	}

	/**
	 * One chunk of a package of this account, for the relay.
	 *
	 * @param mixed    $package_id Package.
	 * @param mixed    $chunk      Index of the chunk, from 0.
	 * @param int|null $now        Time; now when null.
	 * @return array|WP_Error package_id, chunk, bytes, sha256, data (base64).
	 */
	public static function chunk( $package_id, $chunk, $now = null ) {
		$refused = self::refusal();
		if ( null !== $refused ) {
			return $refused;
		}
		$relay = self::relay_refusal();
		if ( null !== $relay ) {
			return $relay;
		}
		$found = self::own( $package_id, $now );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! is_int( $chunk ) || ! isset( $found['manifest']['chunks'][ $chunk ] ) ) {
			return new WP_Error( 'ab_mcp_sync_chunk_index', sprintf( 'The package has no chunk %1$s: its manifest lists the chunks 0 to %2$d. Ask for a chunk of that list.', is_scalar( $chunk ) ? (string) $chunk : '?', count( $found['manifest']['chunks'] ) - 1 ) );
		}
		$sha  = $found['manifest']['chunks'][ $chunk ]['sha256'];
		$data = AB_MCP_Protected_Store::get( 'out', $found['manifest']['package_id'], self::chunk_name( $chunk ), $sha );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'package_id' => $found['manifest']['package_id'],
			'chunk'      => $chunk,
			'bytes'      => strlen( $data ),
			'sha256'     => $sha,
			'data'       => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the bytes of a chunk, for the relay.
		);
	}

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
	 * The name of a chunk in its group.
	 *
	 * @param int $chunk Index.
	 * @return string
	 */
	public static function chunk_name( $chunk ) {
		return sprintf( 'chunk-%04d', (int) $chunk );
	}

	/**
	 * Whether a request may carry the bytes of a package through the client:
	 * over https, or to a site that has no public address (a site on this
	 * machine or in a private network, AB_MCP_Outbound::public_addresses()).
	 *
	 * @return WP_Error|null
	 */
	public static function relay_refusal() {
		if ( is_ssl() ) {
			return null;
		}
		if ( 'local' === AB_MCP_Outbound::public_addresses( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return null;
		}
		return new WP_Error( 'ab_mcp_sync_relay_http', 'This site has a public address and the request did not come over https, so no chunk of a package passes through it: the content of the site would cross the network unencrypted. Connect the client to the https:// address of this site, then call again.' );
	}

	/* -------------------------------------------------------------- route */

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
		$bytes = self::requested( $request, time() );
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
	 * The bytes a request of the route asks for, or null for every request
	 * that is not exactly right (grant()), for a package this site does not
	 * keep or that has expired, and for a chunk it does not have; read
	 * against the hash of the chunk in the manifest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $now     Time.
	 * @return string|null
	 */
	public static function requested( WP_REST_Request $request, $now ) {
		$grant = self::grant( $request, $now );
		if ( null === $grant ) {
			return null;
		}
		$found = self::find( $grant['package'] );
		if ( ! is_array( $found ) || ! self::bound( $found ) || $found['manifest']['expires'] <= $now || ! isset( $found['manifest']['chunks'][ $grant['chunk'] ] ) ) {
			return null;
		}
		$data = AB_MCP_Protected_Store::get( 'out', $grant['package'], self::chunk_name( $grant['chunk'] ), $found['manifest']['chunks'][ $grant['chunk'] ]['sha256'] );
		return is_string( $data ) ? $data : null;
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

	/* ------------------------------------------------------------ private */

	/**
	 * Why this site makes or gives out no package: a network, no confirmed
	 * staging site with a pair, no usable key.
	 *
	 * @return WP_Error|null
	 */
	private static function refusal() {
		if ( is_multisite() ) {
			return self::multisite();
		}
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return new WP_Error( 'ab_mcp_sync_manifest_role', 'Only the confirmed staging site makes and gives out packages: confirm the role staging site on the page AlphaBridge → Staging of this site first.' );
		}
		if ( null === AB_MCP_Staging::record()['pair'] ) {
			return new WP_Error( 'ab_mcp_sync_manifest_pair', 'A package is made for the production site this staging site is paired with, and it has none: enter the address of the production site on the page AlphaBridge → Staging of this site first.' );
		}
		if ( null === AB_MCP_Sync_Key::current() ) {
			return new WP_Error( 'ab_mcp_sync_key_missing', 'This staging site has no usable key: make it on the page AlphaBridge → Staging under «Key of this staging site», confirm it on the production site, then call again.' );
		}
		return null;
	}

	/**
	 * The refusal on a network, for export and import alike.
	 *
	 * @return WP_Error
	 */
	public static function multisite() {
		return new WP_Error( 'ab_mcp_sync_multisite', 'The takeover from a staging site works between two single sites, and this site is part of a multisite network (where it keeps no tables of the takeover). Use a staging site and a production site that are single installations, or move content on the network with wp_export_content.' );
	}

	/**
	 * Store a package: its chunks, then its signed manifest and the cursor in
	 * a record named by its own SHA-256, which find() reads back. Nothing of
	 * it stays where a step fails.
	 *
	 * @param string     $purpose Purpose.
	 * @param array      $packed  pack().
	 * @param array[]    $kinds   kinds().
	 * @param array|null $cursor  The cursor of the next package.
	 * @param string     $series  The series (32 hex).
	 * @return array|WP_Error answer().
	 */
	private static function store( $purpose, array $packed, array $kinds, $cursor, $series ) {
		self::discard_stale();
		$bytes = 0;
		$whole = hash_init( 'sha256' );
		$list  = array();
		foreach ( $packed['chunks'] as $data ) {
			$bytes += strlen( $data );
			hash_update( $whole, $data );
			$list[] = array(
				'bytes'  => strlen( $data ),
				'sha256' => hash( 'sha256', $data ),
			);
		}
		$gid = AB_MCP_Protected_Store::open(
			'out',
			array(
				'declared' => $bytes + self::RECORD_MAX,
				'label'    => 'series:' . $series,
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		foreach ( $packed['chunks'] as $i => $data ) {
			$put = AB_MCP_Protected_Store::put( 'out', $gid, self::chunk_name( $i ), $data );
			if ( is_wp_error( $put ) ) {
				AB_MCP_Protected_Store::remove( 'out', $gid );
				return $put;
			}
		}
		$signed = AB_MCP_Sync_Manifest::sign(
			array(
				'bytes'       => $bytes,
				'chunks'      => $list,
				'kinds'       => $kinds,
				'objects'     => $packed['objects'],
				'package_id'  => $gid,
				'purpose'     => $purpose,
				'report_hash' => null,
				'sha256'      => hash_final( $whole ),
				'unpacked'    => $packed['unpacked'],
			)
		);
		if ( is_wp_error( $signed ) ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $signed;
		}
		$record = AB_MCP_Sync_Canon::encode(
			array(
				'binding'   => self::binding(),
				'cursor'    => $cursor,
				'format'    => self::RECORD,
				'manifest'  => $signed['manifest'],
				'series'    => $series,
				'signature' => $signed['signature'],
			)
		);
		$put    = AB_MCP_Protected_Store::put( 'out', $gid, hash( 'sha256', $record ), $record );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $put;
		}
		return self::answer( json_decode( $signed['manifest'], true ), $signed, $cursor );
	}

	/**
	 * The answer for a package: what it is, its manifest, the cursor of the
	 * next package (where the inventory goes on, and this package, which the
	 * next one follows), and fresh fetch addresses.
	 *
	 * @param array      $manifest Manifest.
	 * @param array      $signed   manifest, signature.
	 * @param array|null $cursor   Cursor of the next package.
	 * @return array|WP_Error
	 */
	private static function answer( array $manifest, array $signed, $cursor ) {
		$grants = self::grants( $manifest['package_id'], count( $manifest['chunks'] ) );
		if ( is_wp_error( $grants ) ) {
			return $grants;
		}
		return array(
			'package_id' => $manifest['package_id'],
			'purpose'    => $manifest['purpose'],
			'objects'    => $manifest['objects'],
			'bytes'      => $manifest['bytes'],
			'chunks'     => count( $manifest['chunks'] ),
			'kinds'      => $manifest['kinds'],
			'expires'    => $manifest['expires'],
			'manifest'   => $signed,
			'cursor'     => null === $cursor ? null : array(
				'kind'    => $cursor['kind'],
				'inner'   => $cursor['inner'],
				'package' => $manifest['package_id'],
			),
			'fetch'      => $grants,
		);
	}

	/**
	 * A package this account made, as find() reads it, until it expires and
	 * while it is bound as this site is now (bound()).
	 *
	 * @param mixed    $package_id Package.
	 * @param int|null $now        Time; now when null.
	 * @return array|WP_Error
	 */
	private static function own( $package_id, $now ) {
		$found = self::find( $package_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found || (int) $found['user_id'] !== (int) get_current_user_id() || $found['manifest']['expires'] <= ( null === $now ? time() : (int) $now ) ) {
			return new WP_Error( 'ab_mcp_sync_export_unknown', 'This site keeps no package of that id made by this account: packages are kept a day, and each account reads its own. Make a new one with wp_site_export.' );
		}
		if ( ! self::bound( $found ) ) {
			return new WP_Error( 'ab_mcp_sync_export_stale', 'The package was made for another pair or with another key of this staging site: the pair or the role was confirmed again since, so it was discarded. Make a new one with wp_site_export.' );
		}
		return $found;
	}

	/**
	 * What a package of this site is bound to: the key (source id and key
	 * id), the address of this site, the confirmed role and the pair (with
	 * the address of the production site), each with who confirmed it, when
	 * and where. Another key, a pair confirmed again or another production site
	 * is another binding.
	 *
	 * @return string|null SHA-256; null without a key or a pair.
	 */
	public static function binding() {
		$key    = AB_MCP_Sync_Key::current();
		$record = AB_MCP_Staging::record();
		if ( null === $key || null === $record['pair'] ) {
			return null;
		}
		$role = $record['role'];
		$pair = $record['pair'];
		return hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'key'    => array( $key['source_id'], $key['key_id'] ),
					'source' => AB_MCP_Sync_Key::site_form( home_url() ),
					'role'   => array( $role['value'], $role['user_id'], $role['source'], $role['at'] ),
					'pair'   => array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['source'], $pair['at'] ),
				)
			)
		);
	}

	/**
	 * Whether a package is bound as this site is now; one that is not is
	 * discarded here.
	 *
	 * @param array       $found   find().
	 * @param string|null $binding binding(), where the caller has it.
	 * @return bool
	 */
	private static function bound( array $found, $binding = null ) {
		if ( $found['binding'] === ( null === $binding ? self::binding() : $binding ) ) {
			return true;
		}
		AB_MCP_Protected_Store::remove( 'out', $found['manifest']['package_id'] );
		return false;
	}

	/**
	 * Discard every package of this site that is not bound as it is now
	 * (another pair, another key): before a new one is made, so they hold
	 * no place in the budgets.
	 */
	private static function discard_stale() {
		$groups  = AB_MCP_Protected_Store::groups( 'out' );
		$binding = self::binding();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$found = self::find( $group['gid'] );
			if ( is_array( $found ) ) {
				self::bound( $found, $binding );
			}
		}
	}

	/**
	 * A package of this site: the entry of its group and its record, read
	 * against the name it is stored under (its own SHA-256); null where there
	 * is none, or none whole.
	 *
	 * @param mixed $package_id Package.
	 * @return array|WP_Error|null user_id, signed, manifest, cursor, series, binding.
	 */
	private static function find( $package_id ) {
		if ( ! is_string( $package_id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {
			return null;
		}
		$groups = AB_MCP_Protected_Store::groups( 'out' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$entry = null;
		foreach ( $groups as $group ) {
			if ( $package_id === $group['gid'] ) {
				$entry = $group;
			}
		}
		if ( null === $entry ) {
			return null;
		}
		$files = AB_MCP_Protected_Store::files( 'out', $package_id );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		// The record is written last and named by its SHA-256: a group
		// without one is a package whose making did not end.
		$records = preg_grep( '/^[0-9a-f]{64}\z/', array_map( 'strval', array_keys( $files ) ) );
		if ( array() === $records ) {
			return null;
		}
		$name = reset( $records );
		$text = AB_MCP_Protected_Store::get( 'out', $package_id, $name, $name );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$record = json_decode( $text, true, 64 );
		return array(
			'user_id'  => $entry['user_id'],
			'signed'   => array(
				'manifest'  => $record['manifest'],
				'signature' => $record['signature'],
			),
			'manifest' => json_decode( $record['manifest'], true, 8 ),
			'cursor'   => $record['cursor'],
			'series'   => $record['series'],
			'binding'  => $record['binding'],
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
