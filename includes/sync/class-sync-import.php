<?php
/**
 * A package of the staging site arriving on the production site (Z8,
 * decision 6 of the plan of 01.11.2026; contract B), for wp_site_import_chunk.
 *
 * Every call first checks the signed manifest (AB_MCP_Sync_Manifest::
 * verify(): the key a person confirmed, this site's address exactly, the
 * staging site's address exactly, not expired, the purpose the caller reads
 * it for; in this version the inventory only). The first call brings the
 * manifest; this site keeps it in a group of the area in of the protected
 * store, of the account that brought it, and checks it again on every call
 * after, so a new key, another pair or the end of its day stops the package
 * where it is; with it the print of the pair it was begun with
 * (AB_MCP_Sync_Anchor::pair_print()), and a call after the role, the pair or
 * the anchor changed ends the group (decision 15), as one that holds it for
 * a purpose this version does not take. Another account does not see that
 * group: it begins a group of its own. Then it takes chunks, each checked against its SHA-256 in the
 * manifest before it is stored (AB_MCP_Sync_Manifest::chunk()):
 *
 *  - relayed: the client hands the bytes over as base64 (data), over https
 *    or to a site without a public address only
 *    (AB_MCP_Sync_Export::relay_refusal());
 *  - fetched: the client hands over the signed fetch addresses of the
 *    staging site (fetch: chunk, expires, signature), and this site builds
 *    each address itself from the stored address of its staging site and
 *    the route (url()) and asks it through the fetcher (AB_MCP_Sync_Fetcher:
 *    https only, under that address only, no redirect), within BUDGET
 *    seconds a call.
 *
 * A chunk that is there already is not asked again; the answer lists those
 * missing, so a call with fresh fetch addresses (wp_site_export with the
 * package_id) or the relay takes up where the last one ended. Once every
 * chunk is there, the package is checked whole against its SHA-256
 * (AB_MCP_Sync_Manifest::whole()), unpacked within the limits of decision 8
 * with each line checked again (decision 9, unpacked(); a package that does
 * not hold is removed), the objects a credential holds by the rules of this
 * site listed (held), closed (it counts as an open package no more,
 * AB_MCP_Store_Budget) and marked complete. What is in it is read
 * only by the takeover, which checks the manifest again for its purpose
 * and report. The store removes the group a day after it was made.
 *
 * A fetch takes no longer than what is left of BUDGET (its timeout is that
 * rest at most); where less than MIN_FETCH is left, the call ends and the
 * next one goes on.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-store-budget.php';
require_once __DIR__ . '/class-sync-export.php';
require_once __DIR__ . '/class-sync-fetcher.php';

/**
 * Class AB_MCP_Sync_Import
 */
final class AB_MCP_Sync_Import {

	/** The format of the record of a package in its group. */
	const RECORD = 'ab-sync-import/1';

	/** Bytes of a record at most (see AB_MCP_Sync_Export::RECORD_MAX). */
	const RECORD_MAX = 2162688;

	/** The prefix of the label of a group: this and the package id. */
	const LABEL = 'package-';

	/** The name of the mark of a package checked whole. */
	const COMPLETE = 'complete';

	/** The beginning of the name of the list of objects a credential holds by the rules of this site (then its SHA-256 in base32, held_name()). */
	const HELD = 'held-';

	/** The letters of base32 (RFC 4648, in lower case), which fit the names of the protected store. */
	const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

	/** The format of that list. */
	const HELD_FORMAT = 'ab-sync-held/1';

	/** Bytes of that list at most: the kind and ref of every object a package holds. */
	const HELD_MAX = 2097152;

	/** Bytes of a chunk inflated at a time (inflate()). */
	const INFLATE_PIECE = 4096;

	/** The fields a line of the inventory carries at most (AB_MCP_Sync_Export::line()). */
	const LINE_KEYS = array( 'b9', 'canon', 'complete', 'hash', 'identity', 'kind', 'locks', 'possible', 'reason', 'ref', 'refs', 'size', 'status', 'unread' );

	/** The fields of a line besides its identity, status, locks and reason, which a line a credential holds never carries. */
	const LINE_OPEN = array( 'b9', 'canon', 'complete', 'hash', 'possible', 'refs', 'size', 'unread' );

	/** Seconds one call fetches at most; what is left, the next one does. */
	const BUDGET = 60;

	/** Seconds one chunk may take to arrive. */
	const TIMEOUT = 30;

	/** Seconds of BUDGET at least left for one more fetch. */
	const MIN_FETCH = 5;

	/** The clock of the budget; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/**
	 * One call of wp_site_import_chunk.
	 *
	 * @param array       $a       package_id; manifest (manifest, signature),
	 *                             purpose, report_hash on the first call;
	 *                             chunk and data (relay) or fetch.
	 * @param object|null $fetcher An object with get( $url, $args ), as
	 *                             AB_MCP_Sync_Fetcher; that one when null.
	 * @return array|WP_Error The state of the package (state()).
	 */
	public static function call( array $a, $fetcher = null ) {
		if ( is_multisite() ) {
			return AB_MCP_Sync_Export::multisite();
		}
		$package_id = isset( $a['package_id'] ) ? $a['package_id'] : null;
		if ( ! is_string( $package_id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {
			return new WP_Error( 'ab_mcp_sync_import_package', 'package_id is the 32 hex characters wp_site_export named the package with. Pass it as the staging site gave it.' );
		}
		$has_data  = array_key_exists( 'data', $a ) || array_key_exists( 'chunk', $a );
		$has_fetch = array_key_exists( 'fetch', $a );
		if ( $has_data && $has_fetch ) {
			return new WP_Error( 'ab_mcp_sync_import_both', 'A call either hands over one chunk (chunk and data) or fetch addresses (fetch), not both. Pass one of them, then call again with the other.' );
		}
		$relay = $has_data ? AB_MCP_Sync_Export::relay_refusal() : null;
		if ( null !== $relay ) {
			return $relay;
		}
		if ( $has_fetch && ! self::grants_shaped( $a['fetch'] ) ) {
			return self::grants_refusal();
		}
		$expect = null;
		if ( array_key_exists( 'manifest', $a ) ) {
			$expect = self::expect( $a, $package_id );
			if ( is_wp_error( $expect ) ) {
				return $expect;
			}
		}
		$held = self::held( $package_id, $a, $expect );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		if ( $has_data ) {
			$put = self::relayed( $held, $a );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
		}
		$failed = array();
		if ( $has_fetch ) {
			$failed = self::fetched( $held, $a['fetch'], $fetcher );
			if ( is_wp_error( $failed ) ) {
				return $failed;
			}
		}
		return self::state( $held, $failed );
	}

	/**
	 * The address of one chunk at the staging site, built here: the stored
	 * address of the staging site in the form the anchor compares
	 * (AB_MCP_Sync_Key::site_form()), the route, and the four values of the
	 * fetch address.
	 *
	 * @param string $partner    Stored address of the staging site.
	 * @param string $package_id Package.
	 * @param int    $chunk      Chunk.
	 * @param int    $expires    Until when.
	 * @param string $signature  MAC of the staging site.
	 * @return string
	 */
	public static function url( $partner, $package_id, $chunk, $expires, $signature ) {
		return AB_MCP_Sync_Key::site_form( $partner ) . '/?rest_route=/' . AB_MCP_REST_NAMESPACE . AB_MCP_Sync_Grant::ROUTE
			. '&package=' . $package_id . '&chunk=' . (int) $chunk . '&expires=' . (int) $expires . '&sig=' . $signature;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * The package this site holds, its manifest checked again: from the
	 * manifest of this call on the first one (kept in a new group of the
	 * area in), from the kept one after; a manifest of a later call must be
	 * that one.
	 *
	 * @param string     $package_id Package.
	 * @param array      $a          Arguments.
	 * @param array|null $expect     expect(), where the call brings a manifest.
	 * @return array|WP_Error gid, manifest, signed, expect.
	 */
	private static function held( $package_id, array $a, $expect ) {
		$found = self::find( $package_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found ) {
			if ( null === $expect ) {
				return new WP_Error( 'ab_mcp_sync_import_unknown', 'This site holds no package of that id begun by this account yet: the first call brings its manifest. Pass manifest and purpose as wp_site_export gave them on the staging site.' );
			}
			$checked = AB_MCP_Sync_Manifest::verify( $a['manifest'], $expect );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			return self::begin( $checked['manifest'], $a['manifest'], $expect );
		}
		// What it was begun for, before anything else of it is read: a
		// purpose this version takes (a group of another version, or one
		// changed in the store, is ended; verify() holds it to this package).
		$expect = $found['expect'];
		if ( ! is_array( $expect ) || ! isset( $expect['purpose'] ) || ! in_array( $expect['purpose'], AB_MCP_Sync_Export::PURPOSES, true ) ) {
			AB_MCP_Protected_Store::remove( 'in', $found['gid'] );
			return new WP_Error( 'ab_mcp_sync_import_purpose_held', sprintf( 'This site held the package for a purpose this version does not take (it takes purpose=%s only), so what arrived of it was removed. Call again with the manifest and purpose=%s as wp_site_export gave them on the staging site.', AB_MCP_Sync_Export::PURPOSES[0], AB_MCP_Sync_Export::PURPOSES[0] ) );
		}
		if ( array_key_exists( 'manifest', $a ) && ( ! is_array( $a['manifest'] ) || array( 'manifest', 'signature' ) !== self::sorted_keys( $a['manifest'] ) || $found['signed']['manifest'] !== $a['manifest']['manifest'] || $found['signed']['signature'] !== $a['manifest']['signature'] ) ) {
			return new WP_Error( 'ab_mcp_sync_import_other_manifest', 'This site holds the package with another manifest than the one passed now. Pass the manifest of the first call, or leave it out.' );
		}
		$checked = AB_MCP_Sync_Manifest::verify( $found['signed'], $expect );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		// The pair it was begun with (decision 15): a new confirmation of the
		// role, another pair or another anchor ends it.
		if ( null === $found['print'] || ! hash_equals( $found['print'], (string) AB_MCP_Sync_Anchor::pair_print() ) ) {
			AB_MCP_Protected_Store::remove( 'in', $found['gid'] );
			return new WP_Error( 'ab_mcp_sync_import_pair_changed', 'The role of this site, its pair or the confirmed key of the staging site changed since this package was begun, so it is not continued, and what arrived of it was removed. Fetch the package anew: call wp_site_export on the staging site, then wp_site_import_chunk with the package_id and manifest it gives.' );
		}
		return $found;
	}

	/**
	 * What the caller reads the package for, as verify() takes it.
	 *
	 * @param array  $a          Arguments.
	 * @param string $package_id Package.
	 * @return array|WP_Error
	 */
	private static function expect( array $a, $package_id ) {
		$purpose = isset( $a['purpose'] ) ? $a['purpose'] : null;
		if ( ! in_array( $purpose, AB_MCP_Sync_Export::PURPOSES, true ) ) {
			return new WP_Error( 'ab_mcp_sync_import_purpose', sprintf( 'This version takes packages of the inventory of the staging site only (purpose says what a package is read for); packages of objects, media and the environment come with the takeover. Pass purpose=%s with the manifest.', AB_MCP_Sync_Export::PURPOSES[0] ) );
		}
		return array(
			'purpose'    => $purpose,
			'package_id' => $package_id,
		);
	}

	/**
	 * Keep a checked manifest in a new group of the area in, as a record
	 * named by its own SHA-256.
	 *
	 * @param array $manifest Manifest.
	 * @param array $signed   manifest, signature.
	 * @param array $expect   What the caller reads it for.
	 * @return array|WP_Error held().
	 */
	private static function begin( array $manifest, array $signed, array $expect ) {
		// verify() read the manifest with the active anchor: there is a print.
		$print = AB_MCP_Sync_Anchor::pair_print();
		$gid   = AB_MCP_Protected_Store::open(
			'in',
			array(
				'declared' => $manifest['bytes'] + self::RECORD_MAX + self::HELD_MAX,
				'label'    => self::LABEL . $manifest['package_id'],
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$record = AB_MCP_Sync_Canon::encode(
			array(
				'expect'    => $expect,
				'format'    => self::RECORD,
				'manifest'  => $signed['manifest'],
				'print'     => $print,
				'signature' => $signed['signature'],
			)
		);
		$put    = AB_MCP_Protected_Store::put( 'in', $gid, hash( 'sha256', $record ), $record );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'in', $gid );
			return $put;
		}
		return array(
			'gid'      => $gid,
			'manifest' => $manifest,
			'signed'   => array(
				'manifest'  => $signed['manifest'],
				'signature' => $signed['signature'],
			),
			'expect'   => $expect,
			'print'    => $print,
		);
	}

	/**
	 * The group of a package this account began, the oldest of its label,
	 * with its record.
	 *
	 * @param string $package_id Package.
	 * @return array|WP_Error|null held(), null where this account holds none.
	 */
	private static function find( $package_id ) {
		$groups = AB_MCP_Protected_Store::groups( 'in' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		foreach ( $groups as $group ) {
			if ( self::LABEL . $package_id !== $group['label'] || (int) $group['user_id'] !== (int) get_current_user_id() ) {
				continue;
			}
			$files = AB_MCP_Protected_Store::files( 'in', $group['gid'] );
			if ( is_wp_error( $files ) ) {
				return $files;
			}
			$records = preg_grep( '/^[0-9a-f]{64}\z/', array_map( 'strval', array_keys( $files ) ) );
			if ( array() === $records ) {
				continue;
			}
			$name = reset( $records );
			$text = AB_MCP_Protected_Store::get( 'in', $group['gid'], $name, $name );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			$record = json_decode( $text, true, 64 );
			return array(
				'gid'      => $group['gid'],
				'manifest' => json_decode( $record['manifest'], true, 8 ),
				'signed'   => array(
					'manifest'  => $record['manifest'],
					'signature' => $record['signature'],
				),
				'expect'   => $record['expect'],
				'print'    => isset( $record['print'] ) && is_string( $record['print'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $record['print'] ) ? $record['print'] : null,
			);
		}
		return null;
	}

	/**
	 * Store one chunk the client relayed.
	 *
	 * @param array $held held().
	 * @param array $a    Arguments: chunk, data (base64).
	 * @return true|WP_Error
	 */
	private static function relayed( array $held, array $a ) {
		$chunk = isset( $a['chunk'] ) ? $a['chunk'] : null;
		$text  = isset( $a['data'] ) && is_string( $a['data'] ) ? $a['data'] : '';
		$data  = base64_decode( $text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a chunk the relay hands over.
		// One spelling: what base64_encode() writes for the bytes; a chunk
		// has at least one byte.
		if ( '' === $text || base64_encode( (string) $data ) !== $text ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the round trip.
			return new WP_Error( 'ab_mcp_sync_import_data', 'data is the chunk as base64, exactly as wp_site_export_chunk gave it on the staging site. Pass it unchanged.' );
		}
		return self::keep( $held, $chunk, $data );
	}

	/**
	 * Fetch the chunks of the fetch addresses that are still missing, as far
	 * as BUDGET reaches.
	 *
	 * @param array       $held    held().
	 * @param array[]     $grants  The fetch addresses, as grants_shaped() takes them.
	 * @param object|null $fetcher Fetcher.
	 * @return array[]|WP_Error The chunks that did not arrive: chunk, code, message.
	 */
	private static function fetched( array $held, array $grants, $fetcher ) {
		foreach ( $grants as $grant ) {
			if ( ! isset( $held['manifest']['chunks'][ $grant['chunk'] ] ) ) {
				return self::grants_refusal();
			}
		}
		$partner = AB_MCP_Sync_Anchor::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		$fetcher  = null === $fetcher ? new AB_MCP_Sync_Fetcher() : $fetcher;
		$have     = AB_MCP_Protected_Store::files( 'in', $held['gid'] );
		if ( is_wp_error( $have ) ) {
			return $have;
		}
		$deadline = self::now() + self::BUDGET;
		$failed   = array();
		foreach ( $grants as $grant ) {
			$i = $grant['chunk'];
			if ( isset( $have[ AB_MCP_Sync_Export::chunk_name( $i ) ] ) ) {
				continue;
			}
			// What is left of the budget, and no fetch that could not end in it.
			$left = $deadline - self::now();
			if ( $left < self::MIN_FETCH ) {
				break;
			}
			$bytes  = $held['manifest']['chunks'][ $i ]['bytes'];
			$answer = $fetcher->get(
				self::url( $partner, $held['manifest']['package_id'], $i, $grant['expires'], $grant['signature'] ),
				array(
					'timeout'             => min( (float) self::TIMEOUT, $left ),
					'limit_response_size' => $bytes + 1,
					'headers'             => array( 'Accept' => 'application/octet-stream' ),
				)
			);
			$kept   = is_wp_error( $answer ) ? $answer : self::arrived( $held, $i, $answer );
			if ( is_wp_error( $kept ) ) {
				$failed[] = array(
					'chunk'   => $i,
					'code'    => $kept->get_error_code(),
					'message' => $kept->get_error_message(),
				);
			}
		}
		return $failed;
	}

	/**
	 * A chunk the staging site answered with, kept where it is the one the
	 * manifest names.
	 *
	 * @param array $held   held().
	 * @param int   $chunk  Chunk.
	 * @param array $answer Answer of the fetcher.
	 * @return true|WP_Error
	 */
	private static function arrived( array $held, $chunk, $answer ) {
		$status = (int) wp_remote_retrieve_response_code( $answer );
		if ( 200 !== $status ) {
			return new WP_Error( 'ab_mcp_sync_import_fetch', sprintf( 'The staging site answered %d for this chunk: its fetch address was wrong or older than ten minutes, or the package is gone there. Get fresh fetch addresses with wp_site_export (package_id) on the staging site, then call again with them.', $status ), array( 'status' => $status ) );
		}
		return self::keep( $held, $chunk, (string) wp_remote_retrieve_body( $answer ) );
	}

	/**
	 * Keep a chunk once it is the one the manifest names; a chunk that is
	 * there already stays as it is.
	 *
	 * @param array $held  held().
	 * @param mixed $chunk Chunk.
	 * @param mixed $data  Bytes.
	 * @return true|WP_Error
	 */
	private static function keep( array $held, $chunk, $data ) {
		$ok = AB_MCP_Sync_Manifest::chunk( $held['manifest'], $chunk, $data );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$put = AB_MCP_Protected_Store::put( 'in', $held['gid'], AB_MCP_Sync_Export::chunk_name( $chunk ), $data );
		return is_wp_error( $put ) && 'ab_mcp_store_exists' !== $put->get_error_code() ? $put : true;
	}

	/**
	 * Where the package stands; once every chunk is there and it was not
	 * yet, it is checked whole and marked complete.
	 *
	 * @param array   $held   held().
	 * @param array[] $failed Chunks a fetch did not bring.
	 * @return array|WP_Error
	 */
	private static function state( array $held, array $failed ) {
		$have = AB_MCP_Protected_Store::files( 'in', $held['gid'] );
		if ( is_wp_error( $have ) ) {
			return $have;
		}
		$missing = array();
		foreach ( array_keys( $held['manifest']['chunks'] ) as $i ) {
			if ( ! isset( $have[ AB_MCP_Sync_Export::chunk_name( $i ) ] ) ) {
				$missing[] = $i;
			}
		}
		$complete   = isset( $have[ self::COMPLETE ] );
		$held_count = null;
		if ( ! $complete && array() === $missing ) {
			$whole = hash_init( 'sha256' );
			foreach ( $held['manifest']['chunks'] as $i => $chunk ) {
				$data = AB_MCP_Protected_Store::get( 'in', $held['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				hash_update( $whole, $data );
			}
			$ok = AB_MCP_Sync_Manifest::whole( $held['manifest'], hash_final( $whole ) );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			// Unpacked within the limits of decision 8, each line checked
			// again (decision 9); a package that does not is not used.
			$lines = self::unpacked( $held );
			if ( is_wp_error( $lines ) ) {
				AB_MCP_Protected_Store::remove( 'in', $held['gid'] );
				return $lines;
			}
			$kept = self::keep_held( $held, $lines );
			if ( is_wp_error( $kept ) ) {
				return $kept;
			}
			$held_count = count( $lines );
			// Done with: it counts as an open package no more; then marked.
			$closed = AB_MCP_Protected_Store::close( 'in', $held['gid'] );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
			$put = AB_MCP_Protected_Store::put( 'in', $held['gid'], self::COMPLETE, $held['manifest']['sha256'] );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$complete = true;
		}
		$out = array(
			'package_id' => $held['manifest']['package_id'],
			'purpose'    => $held['manifest']['purpose'],
			'chunks'     => count( $held['manifest']['chunks'] ),
			'received'   => count( $held['manifest']['chunks'] ) - count( $missing ),
			'missing'    => $missing,
			'complete'   => $complete,
			'expires'    => $held['manifest']['expires'],
		);
		if ( $complete && null === $held_count ) {
			$list = self::held_list( $held, $have );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$held_count = count( $list );
		}
		if ( $complete ) {
			$out['held'] = $held_count;
		}
		if ( array() !== $failed ) {
			$out['failed'] = $failed;
		}
		return $out;
	}

	/**
	 * The lines of a package checked whole, unpacked within the limits of
	 * decision 8 (AB_MCP_Store_Budget::UNPACK) and each checked again
	 * (decision 9): the manifest declares at most UNPACK's objects and
	 * unpacked size (AB_MCP_Sync_Manifest::MAX_OBJECTS, ::MAX_UNPACKED);
	 * each chunk is inflated no further than what the manifest has left and
	 * than the raw size of one object (a chunk holds whole lines of at most
	 * AB_MCP_Sync_Export::SLICE bytes, so no line passes that size either),
	 * so a chunk that would inflate further is not inflated past it; no more
	 * objects than the limit; JSON no deeper than the limit, in the one spelling
	 * AB_MCP_Sync_Canon::encode() writes, with the fields of a line of the
	 * inventory and no other, no object whose name is the name of a
	 * credential (those are never packed), and no line a lock of the
	 * credential check holds that carries more than its identity; as many
	 * lines and bytes as the manifest declares. What a credential holds by
	 * the rules of this site (its name, AB_MCP_Redactor::extra_keys()
	 * included) is listed.
	 *
	 * @param array $held held().
	 * @return array[]|WP_Error kind and ref of the objects held by this site.
	 */
	private static function unpacked( array $held ) {
		$manifest = $held['manifest'];
		$limits   = AB_MCP_Store_Budget::UNPACK;
		$extra    = AB_MCP_Redactor::extra_keys();
		$total = 0;
		$count = 0;
		$out   = array();
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$data = AB_MCP_Protected_Store::get( 'in', $held['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$declared = $manifest['unpacked'] - $total;
			$text     = self::inflate( $data, min( $declared, $limits['object_bytes'] ) );
			if ( null === $text && $declared > $limits['object_bytes'] ) {
				return AB_MCP_Store_Budget::unpack_refusal( array( 'object_bytes' => $limits['object_bytes'] + 1 ) );
			}
			if ( ! is_string( $text ) ) {
				return self::unpack_refusal( 'A chunk does not unpack within the package: it is no gzip, or it inflates to more than the manifest declares.' );
			}
			$total += strlen( $text );
			if ( '' === $text ) {
				continue;
			}
			// Each line ends with a line break; a chunk without one at its end
			// leaves its last line cut, which is no object in its one spelling.
			foreach ( explode( "\n", substr( $text, 0, -1 ) ) as $line ) {
				++$count;
				$refused = AB_MCP_Store_Budget::unpack_refusal( array( 'objects' => $count ) );
				if ( null !== $refused ) {
					return $refused;
				}
				$object = json_decode( $line, true, $limits['depth'] );
				if ( JSON_ERROR_DEPTH === json_last_error() ) {
					return AB_MCP_Store_Budget::unpack_refusal( array( 'depth' => $limits['depth'] + 1 ) );
				}
				if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== $line ) {
					return self::unpack_refusal( 'A line is not an object in the one spelling of the staging site.' );
				}
				if ( ! self::line_shaped( $object ) ) {
					return self::unpack_refusal( 'A line carries what a line of the inventory does not: a field it has no place for, an object whose name is the name of a credential, or more than the identity of an object a credential holds.' );
				}
				if ( self::named_credential( $object['identity'], $extra ) ) {
					$out[] = array( $object['kind'], $object['ref'] );
				}
			}
		}
		if ( $count !== $manifest['objects'] || $total !== $manifest['unpacked'] ) {
			return self::unpack_refusal( 'The lines are not as many, or not as long, as the manifest declares.' );
		}
		return $out;
	}

	/**
	 * A chunk inflated as far as $cap bytes and no further: its input fed in
	 * pieces of INFLATE_PIECE bytes, so what one piece adds stays bounded
	 * (zlib inflates at most about 1032 times), and the inflation stops once
	 * the output passes $cap.
	 *
	 * @param string $data Chunk (gzip).
	 * @param int    $cap  Bytes at most.
	 * @return string|false|null The bytes; false where it is no whole gzip;
	 *                           null where it inflates past $cap.
	 */
	public static function inflate( $data, $cap ) {
		$context = '' === $data ? false : inflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $context ) {
			return false;
		}
		$out = '';
		foreach ( str_split( $data, self::INFLATE_PIECE ) as $piece ) {
			$more = @inflate_add( $context, $piece, ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no gzip is answered as false.
			if ( false === $more ) {
				return false;
			}
			$out .= $more;
			if ( strlen( $out ) > $cap ) {
				return null;
			}
		}
		if ( ZLIB_STREAM_END !== inflate_get_status( $context ) ) {
			$more = @inflate_add( $context, '', ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a cut gzip is answered as false.
			if ( false === $more || ZLIB_STREAM_END !== inflate_get_status( $context ) ) {
				return false;
			}
			// With ZLIB_SYNC_FLUSH each piece gave all it holds: the end adds
			// nothing the check in the loop has not seen.
			$out .= $more;
		}
		return $out;
	}

	/**
	 * Whether an object of a line has the fields of a line of the inventory
	 * and no other, is no object whose name is the name of a credential (B9,
	 * never packed), and, where a lock of the credential check holds it,
	 * carries no more than its identity, status, locks and reason.
	 *
	 * @param array $object Object.
	 * @return bool
	 */
	private static function line_shaped( array $object ) {
		if ( array() !== array_diff( array_map( 'strval', array_keys( $object ) ), self::LINE_KEYS ) ) {
			return false;
		}
		if ( ! isset( $object['kind'], $object['ref'], $object['identity'], $object['status'], $object['locks'] ) || ! is_string( $object['kind'] ) || ! is_string( $object['ref'] ) || ! is_string( $object['status'] ) || ! is_array( $object['identity'] ) || ! is_array( $object['locks'] ) ) {
			return false;
		}
		if ( 'b9' === $object['status'] ) {
			return false;
		}
		foreach ( $object['locks'] as $lock ) {
			if ( ! is_array( $lock ) ) {
				return false;
			}
		}
		return ! AB_MCP_Sync_Export::credential_locked( $object['locks'] ) || array() === array_intersect_key( $object, array_flip( self::LINE_OPEN ) );
	}

	/**
	 * Whether the identity of an object names a credential by the rules of
	 * this site: the name of an option or of a row of theme mods, the key of
	 * a theme mod.
	 *
	 * @param array    $identity Identity.
	 * @param string[] $extra    AB_MCP_Redactor::extra_keys().
	 * @return bool
	 */
	private static function named_credential( array $identity, array $extra ) {
		foreach ( array( 'name', 'theme_mod' ) as $field ) {
			if ( isset( $identity[ $field ] ) && is_string( $identity[ $field ] ) && AB_MCP_Sync_Credentials::names_credential( $identity[ $field ], $extra ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep the list of objects a credential holds by the rules of this site
	 * in the group, named by its SHA-256; the same list kept before by an
	 * interrupted call is the one.
	 *
	 * @param array   $held  held().
	 * @param array[] $lines unpacked().
	 * @return true|WP_Error
	 */
	private static function keep_held( array $held, array $lines ) {
		$text = AB_MCP_Sync_Canon::encode(
			array(
				'format'  => self::HELD_FORMAT,
				'objects' => $lines,
			)
		);
		$sha  = hash( 'sha256', $text );
		$put  = AB_MCP_Protected_Store::put( 'in', $held['gid'], self::held_name( $sha ), $text );
		if ( is_wp_error( $put ) && ! ( 'ab_mcp_store_exists' === $put->get_error_code() && is_string( AB_MCP_Protected_Store::get( 'in', $held['gid'], self::held_name( $sha ), $sha ) ) ) ) {
			return $put;
		}
		return true;
	}

	/**
	 * The name of the list of held objects: HELD and its SHA-256 in base32
	 * (52 letters; in hex it would not fit the 64 characters of a name of
	 * the protected store, and a name of 64 hex is the record's).
	 *
	 * @param string $sha SHA-256 in hex.
	 * @return string
	 */
	public static function held_name( $sha ) {
		$bits = '';
		foreach ( str_split( (string) hex2bin( $sha ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$name = self::HELD;
		foreach ( str_split( $bits, 5 ) as $five ) {
			$name .= self::BASE32[ bindec( str_pad( $five, 5, '0' ) ) ];
		}
		return $name;
	}

	/**
	 * The SHA-256 in hex a name of held_name() carries, or null where it is
	 * no such name.
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	public static function held_sha( $name ) {
		if ( 1 !== preg_match( '/^' . self::HELD . '[a-z2-7]{52}\z/', (string) $name ) ) {
			return null;
		}
		$bits = '';
		foreach ( str_split( substr( $name, strlen( self::HELD ) ) ) as $letter ) {
			$bits .= str_pad( decbin( strpos( self::BASE32, $letter ) ), 5, '0', STR_PAD_LEFT );
		}
		$hex = '';
		foreach ( str_split( substr( $bits, 0, 256 ), 4 ) as $four ) {
			$hex .= dechex( bindec( $four ) );
		}
		// The last letter carries four bits more, which held_name() leaves 0.
		return self::held_name( $hex ) === $name ? $hex : null;
	}

	/**
	 * The list of objects a credential holds by the rules of this site, of a
	 * package marked complete: exactly one list, read against its SHA-256.
	 *
	 * @param array             $held held().
	 * @param array<string,int> $have The files of its group.
	 * @return array[]|WP_Error
	 */
	private static function held_list( array $held, array $have ) {
		$names = array_values( array_filter( array_map( 'strval', array_keys( $have ) ), static fn( $name ) => null !== self::held_sha( $name ) ) );
		$text  = 1 === count( $names ) ? AB_MCP_Protected_Store::get( 'in', $held['gid'], $names[0], self::held_sha( $names[0] ) ) : null;
		$list  = is_string( $text ) ? json_decode( $text, true, 8 ) : null;
		if ( ! is_array( $list ) || ! isset( $list['format'], $list['objects'] ) || self::HELD_FORMAT !== $list['format'] || ! is_array( $list['objects'] ) ) {
			return new WP_Error( 'ab_mcp_sync_import_held', 'The package is marked complete, but the list of its objects a credential holds on this site does not read back, so it is not used. 1. Export the package again on the staging site (wp_site_export). 2. Import it again with the new package_id and manifest.' );
		}
		return $list['objects'];
	}

	/**
	 * A package whose lines do not unpack as they must.
	 *
	 * @param string $what What is wrong.
	 * @return WP_Error
	 */
	private static function unpack_refusal( $what ) {
		return new WP_Error( 'ab_mcp_sync_import_lines', $what . ' So the package is not used, and what arrived of it was removed. 1. Update AlphaBridge MCP on the staging site to the version of this site. 2. Export the package again there (wp_site_export). 3. Import it again with the new package_id and manifest.' );
	}

	/**
	 * Whether fetch is a list of fetch addresses as wp_site_export gives
	 * them: chunk and expires as integers, the signature as 64 hex, nothing
	 * else. Whether each chunk is one of the package, fetched() checks.
	 *
	 * @param mixed $grants fetch.
	 * @return bool
	 */
	private static function grants_shaped( $grants ) {
		if ( ! is_array( $grants ) || ! array_is_list( $grants ) || array() === $grants ) {
			return false;
		}
		foreach ( $grants as $grant ) {
			if ( ! is_array( $grant ) || array( 'chunk', 'expires', 'signature' ) !== self::sorted_keys( $grant ) || ! is_int( $grant['chunk'] ) || ! is_int( $grant['expires'] ) || ! is_string( $grant['signature'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $grant['signature'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return WP_Error fetch is not a list of fetch addresses.
	 */
	private static function grants_refusal() {
		return new WP_Error( 'ab_mcp_sync_import_fetch_list', 'fetch is the list of fetch addresses wp_site_export gave on the staging site (fetch: chunk, expires, signature each). Pass them unchanged.' );
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

	/**
	 * @return float Seconds.
	 */
	private static function now() {
		return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
	}
}
