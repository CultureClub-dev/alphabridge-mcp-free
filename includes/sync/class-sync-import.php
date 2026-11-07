<?php
/**
 * A package of the staging site arriving on the production site (Z8,
 * decision 6 of the plan of 01.11.2026; contract B), for wp_site_import_chunk.
 *
 * Every call first checks the signed manifest (AB_MCP_Sync_Manifest::
 * verify(): the key a person confirmed, this site's address exactly, the
 * staging site's address exactly, not expired, the purpose the caller reads
 * it for; in this version the inventory or the environment, whose lines
 * have a structure of their own, AB_MCP_Sync_Env::shaped()). The first call brings the
 * manifest; this site keeps it in a group of the area in of the protected
 * store, of the account that brought it, and checks it again on every call
 * after (and over the connection that brought it, the token of MCP: a
 * group of another connection of the same account is none), so a new key,
 * another pair or the end of its day stops the package
 * where it is; with it the print of the pair it was begun with
 * (AB_MCP_Sync_Anchor::pair_print()), and a call after the role, the pair or
 * the anchor changed ends the group before its manifest is checked again
 * (decision 15), as one that holds it for a purpose this version does not
 * take; a group of a pair of before counts as open no more (the binding
 * it was opened with, AB_MCP_Store_Budget::package_refusal()), so none holds
 * a place in the budgets, and none is removed but by its own continuation
 * or the store after its time. Another account does not see that
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
 * with each line checked again (decision 9, unpacked(); a package whose
 * structure does not hold is removed), the objects a credential holds by
 * the rules of this site, or one written into a name of a line (of the
 * environment too: a name of a file, a plugin, a table), listed
 * (held, each with the code of why), closed (it counts as an open package no more,
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
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-net.php';
require_once __DIR__ . '/class-sync-option-schema.php';
require_once dirname( __DIR__ ) . '/class-staging-gate.php';

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

	/**
	 * The format of that list: of each object its kind, the SHA-256 of its
	 * ref in the spelling of AB_MCP_Sync_Canon::encode() (a text with its
	 * quotes, bytes in the byte form), by which the takeover finds its line
	 * in the package, and the code of why (HELD_CODES); never the ref nor
	 * another name, which may carry what holds it.
	 */
	const HELD_FORMAT = 'ab-sync-held/3';

	/**
	 * Why a line is held (held_code()): a name of it is the name of a
	 * credential by the rules of this site (credential_name); a name of it
	 * holds a field of a credential, as the credential check and the net
	 * over the raw value read a value (credential_field); a name of it does
	 * not read to its end, so nothing says what it holds (unreadable_name).
	 */
	const HELD_CODES = array( 'credential_name', 'credential_field', 'unreadable_name' );

	/** Bytes of that list at most: a kind, a SHA-256 and a code for each object a package may hold (AB_MCP_Store_Budget::UNPACK, some 100 bytes each). */
	const HELD_MAX = 2097152;

	/** Bytes of a chunk inflated at a time (inflate()). */
	const INFLATE_PIECE = 512;

	/**
	 * The fields a line of the inventory carries at most
	 * (AB_MCP_Sync_Export::line()), each with its type (typed()); identity,
	 * locks, refs and possible are checked by their own lists.
	 *
	 * Two levels. The structure refuses the package where it is not what the
	 * export writes: a field without a place or of another type, the version
	 * of the canonical form, a status, lock (key, stage, reason of the
	 * tables), space, strength or reason code outside its list, bytes not in
	 * the byte form of the canon, an identity not of its kind or naming
	 * another object. The content never refuses it: a name (type name: the
	 * ref, the names and slugs of an identity, the places at, unread and b9,
	 * a login, the target) is any text, or bytes in the byte form, without a
	 * ban of characters or a length of its own (only the limits of a chunk
	 * and a line hold it); a credential in a name holds the line
	 * (held_code()).
	 */
	const LINE_FIELDS = array(
		'b9'              => 'names',
		'canon'           => 'canon',
		'complete'        => 'bool',
		'hash'            => 'hash',
		'identity'        => 'identity',
		'instances'       => 'names',
		'kind'            => 'string',
		'locks'           => 'locks',
		'mapped'          => 'hash',
		'mapped_possible' => 'hash',
		'meta'            => 'size',
		'possible'        => 'possible',
		'post_status'     => 'name',
		'reason_code'     => 'string',
		'ref'             => 'name',
		'refs'            => 'refs',
		'size'            => 'size',
		'status'          => 'status',
		'target'          => 'name',
		'twin'            => 'twins',
		'unread'          => 'names',
	);

	/** The statuses a line has (AB_MCP_Sync_Inventory::entry(); b9 is never packed). */
	const STATUSES = array( 'z8', 'locked', 'guarded', 'unreachable', 'unreadable' );

	/** The spaces of a reference (AB_MCP_Sync_Canon::ref(), the readers). */
	const SPACES = array( 'post', 'term', 'user' );

	/** The strength of a reference. */
	const STRENGTHS = array( 'strong' );

	/**
	 * What the readers note in unread where a row could not be read at all
	 * (AB_MCP_Sync_Db_Kinds): the only lines with an empty identity.
	 */
	const ROW_UNREAD = array( 'posts', 'postmeta', 'term_relationships', 'terms', 'menu_item', 'theme_mods', 'options' );

	/** The fields every line carries. */
	const LINE_NEEDS = array( 'identity', 'kind', 'locks', 'ref', 'status' );

	/**
	 * The identity of a line by its kind (AB_MCP_Sync_Db_Kinds): exactly
	 * these fields with these types, or none (a row that could not be read).
	 * A theme mod whose row could not be unpacked has its name only.
	 */
	const IDENTITIES = array(
		'post'        => array(
			'language'  => 'name',
			'post_name' => 'name',
			'post_type' => 'name',
			'theme'     => 'name',
		),
		'term'        => array(
			'language' => 'name',
			'slug'     => 'name',
			'taxonomy' => 'name',
		),
		'menu'        => array(
			'slug'     => 'name',
			'taxonomy' => 'name',
		),
		'menu_item'   => array(
			'menus'    => 'ints',
			'object'   => 'name',
			'parent'   => 'hash',
			'position' => 'integer',
			'target'   => 'hash',
			'type'     => 'name',
		),
		'widget_area' => array( 'area' => 'name' ),
		'custom_css'  => array(
			'linked'     => 'bool',
			'post'       => 'number',
			'stylesheet' => 'name',
		),
		'option'      => array( 'name' => 'name' ),
		'theme_mod'   => array(
			'name'      => 'name',
			'theme_mod' => 'name',
		),
	);

	/** Fields of IDENTITIES a line leaves out where its object has none (a language, Codex round 3, B2). */
	const IDENTITY_OPEN = array(
		'post' => array( 'language' ),
		'term' => array( 'language' ),
	);

	/** The kinds whose ref is an id of the database. */
	const ID_KINDS = array( 'post', 'term', 'menu', 'menu_item' );

	/**
	 * A lock of a line: exactly these fields (AB_MCP_Sync_Kinds::lock() and
	 * the place); key, stage and reason as the tables of AB_MCP_Sync_Kinds
	 * have them (lock_known(), which compares stage and reason with the
	 * table exactly: type table, no type of their own).
	 */
	const LOCK_FIELDS = array(
		'at'     => 'name',
		'key'    => 'string',
		'reason' => 'table',
		'stage'  => 'table',
	);

	/** A reference of a line: these fields at most. */
	const REF_FIELDS = array(
		'at'       => 'name',
		'id'       => 'number',
		'login'    => 'login',
		'space'    => 'space',
		'strength' => 'strength',
	);

	/** The fields every reference carries. */
	const REF_NEEDS = array( 'at', 'id', 'space', 'strength' );

	/** A possible reference: its place only, never its value. */
	const POSSIBLE_FIELDS = array( 'at' => 'name' );

	/** The fields of a line besides its identity, status and locks, which a line a credential holds never carries. */
	const LINE_OPEN = array( 'b9', 'canon', 'complete', 'hash', 'instances', 'mapped', 'mapped_possible', 'meta', 'possible', 'post_status', 'refs', 'size', 'unread' );

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
	 * A package of the inventory this account took over completely, for the
	 * takeover (wp_site_pairs and wp_site_diff, branch 6): its kept manifest
	 * checked again for the purpose this version takes and against the pair
	 * as it is now (the print it was begun with, AB_MCP_Sync_Anchor::
	 * pair_print()), marked complete with the hash of that manifest, its
	 * lines read against the SHA-256 of each chunk and inflated no further
	 * than the manifest declares, and its list of the objects a credential
	 * holds by the rules of this site (held_list()). Reading removes nothing:
	 * what refuses here names the way, and the store removes the group after
	 * its day.
	 *
	 * @param mixed  $package_id Package (32 hex).
	 * @param string $purpose    What the caller reads it for: inventory, env
	 *                           for a package of the environment
	 *                           (wp_site_diff, branch 7), or objects for a
	 *                           package of objects (the run, branch 8; its
	 *                           report is in the kept expectation).
	 * @return array|WP_Error manifest (the array verify() read), lines (the
	 *                        objects, in order), held (kind, SHA-256 of the
	 *                        ref, code of each object held here).
	 */
	public static function taken( $package_id, $purpose = 'inventory' ) {
		if ( ! is_string( $package_id ) || ! preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {
			return new WP_Error( 'ab_mcp_sync_import_package', 'A package is named by the 32 hex characters wp_site_export named it with. Pass the package_id as the staging site gave it.' );
		}
		$found = self::find( $package_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found ) {
			return new WP_Error( 'ab_mcp_sync_import_unknown', sprintf( 'This site holds no package %s taken over by this account over this connection (packages are kept a day, and each belongs to the account and connection that began it). Take it over first: call wp_site_export on the staging site, then wp_site_import_chunk here with the package_id, manifest and purpose it gives, until complete is true.', $package_id ) );
		}
		$expect = $found['expect'];
		if ( ! is_array( $expect ) || ! isset( $expect['purpose'] ) || $purpose !== $expect['purpose'] ) {
			return new WP_Error( 'ab_mcp_sync_import_purpose_held', sprintf( 'This site holds the package %1$s for another purpose than %2$s, so it is not read as one: a package of the inventory goes in packages, a package of the environment in environment, a package of objects in the objects of a run. Pass it where it belongs, or fetch a package of %2$s: call wp_site_export on the staging site with purpose=%2$s, then wp_site_import_chunk here with the same purpose.', $package_id, in_array( $purpose, AB_MCP_Sync_Export::PURPOSES, true ) ? $purpose : 'inventory' ) );
		}
		if ( true !== self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) ) {
			return new WP_Error( 'ab_mcp_sync_import_pair_changed', sprintf( 'The role of this site, its pair or the confirmed key of the staging site changed since the package %s was begun, so it is not read. Fetch the package anew: call wp_site_export on the staging site, then wp_site_import_chunk here with the package_id and manifest it gives.', $package_id ) );
		}
		$checked = AB_MCP_Sync_Manifest::verify( $found['signed'], $expect );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$manifest = $checked['manifest'];
		$have     = AB_MCP_Protected_Store::files( 'in', $found['gid'] );
		if ( is_wp_error( $have ) ) {
			return $have;
		}
		// The mark holds the hash of the package it marks complete, read
		// against its own hash: the mark of this manifest only.
		$mark = isset( $have[ self::COMPLETE ] ) ? AB_MCP_Protected_Store::get( 'in', $found['gid'], self::COMPLETE, hash( 'sha256', $manifest['sha256'] ) ) : null;
		if ( ! is_string( $mark ) ) {
			return new WP_Error( 'ab_mcp_sync_import_incomplete', sprintf( 'The package %s is not complete on this site yet, so it is not read. Hand over or fetch its missing chunks with wp_site_import_chunk until it answers complete true, then call again.', $package_id ) );
		}
		// Each chunk against its hash in the signed manifest, which the import
		// unpacked and checked line by line before it set the mark: the same
		// bytes give the same lines.
		$lines = array();
		$total = 0;
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$data = AB_MCP_Protected_Store::get( 'in', $found['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$text   = (string) self::inflate( $data, min( $manifest['unpacked'] - $total, AB_MCP_Store_Budget::UNPACK['object_bytes'] ) );
			$total += strlen( $text );
			foreach ( '' === $text ? array() : explode( "\n", substr( $text, 0, -1 ) ) as $line ) {
				$lines[] = json_decode( $line, true, AB_MCP_Store_Budget::UNPACK['depth'] );
			}
		}
		$held = self::held_list( $found, $have );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		return array(
			'manifest' => $manifest,
			'lines'    => $lines,
			'held'     => $held,
		);
	}

	/**
	 * Why a line of an object of this site is held, by the rules of this
	 * site, as the import holds a line of a package (held_code()); null where
	 * nothing holds it. For the inventory of the production site itself
	 * (wp_site_pairs), so that its names are shown under the same rule as
	 * those of the staging site.
	 *
	 * @param array $line A line as AB_MCP_Sync_Export::line() writes it.
	 * @return string|null A code of HELD_CODES.
	 */
	public static function held_reason( array $line ) {
		return self::held_code( $line + array( 'identity' => array(), 'locks' => array() ), AB_MCP_Redactor::extra_keys() );
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
				return new WP_Error( 'ab_mcp_sync_import_unknown', 'This site holds no package of that id begun by this account over this connection yet: the first call brings its manifest. Pass manifest and purpose as wp_site_export gave them on the staging site.' );
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
			return new WP_Error( 'ab_mcp_sync_import_purpose_held', 'This site held the package for a purpose this version does not take (it takes purpose=inventory, purpose=env and purpose=objects), so what arrived of it was removed. Call again with the manifest and purpose as wp_site_export gave them on the staging site.' );
		}
		// A purpose a later call names is the one it was begun for (Codex,
		// round 1 of branch 7, A3): the kept one is not put in its place.
		if ( array_key_exists( 'purpose', $a ) && $expect['purpose'] !== $a['purpose'] ) {
			return new WP_Error( 'ab_mcp_sync_import_purpose_other', sprintf( 'This site holds the package for purpose=%1$s, as its first call began it, and this call names another purpose, so nothing of it was taken. Pass purpose=%1$s, as wp_site_export gave it with the manifest, or leave purpose out to go on with the package.', $expect['purpose'] ) );
		}
		// The pair it was begun with (decision 15): a new confirmation of the
		// role, another pair or another anchor ends it, before its manifest
		// is checked again (verify() refuses a manifest of a key that is no
		// longer confirmed, and the group would hold its place in the budget
		// until the end of its day; Codex P2 on PR 132).
		if ( ! self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) ) {
			AB_MCP_Protected_Store::remove( 'in', $found['gid'] );
			return new WP_Error( 'ab_mcp_sync_import_pair_changed', 'The role of this site, its pair or the confirmed key of the staging site changed since this package was begun, so it is not continued, and what arrived of it was removed. Fetch the package anew: call wp_site_export on the staging site, then wp_site_import_chunk with the package_id and manifest it gives.' );
		}
		if ( array_key_exists( 'manifest', $a ) && ( ! is_array( $a['manifest'] ) || array( 'manifest', 'signature' ) !== self::sorted_keys( $a['manifest'] ) || $found['signed']['manifest'] !== $a['manifest']['manifest'] || $found['signed']['signature'] !== $a['manifest']['signature'] ) ) {
			return new WP_Error( 'ab_mcp_sync_import_other_manifest', 'This site holds the package with another manifest than the one passed now. Pass the manifest of the first call, or leave it out.' );
		}
		$checked = AB_MCP_Sync_Manifest::verify( $found['signed'], $expect );
		if ( is_wp_error( $checked ) ) {
			return $checked;
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
			return new WP_Error( 'ab_mcp_sync_import_purpose', 'This version takes packages of the inventory and of the environment of the staging site and packages of objects for a report (purpose says what a package is read for); packages of media come with the takeover of attachments. Pass purpose=inventory, purpose=env or purpose=objects with the manifest, as wp_site_export gave it.' );
		}
		$expect = array(
			'purpose'    => $purpose,
			'package_id' => $package_id,
		);
		// A package of objects is for one report (decision 5): the report it
		// is read for is named here, and the manifest must name the same.
		if ( in_array( $purpose, AB_MCP_Sync_Manifest::FOR_REPORT, true ) ) {
			$hash = isset( $a['report_hash'] ) ? $a['report_hash'] : null;
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $hash ) ) {
				return new WP_Error( 'ab_mcp_sync_import_report', 'A package of objects is read for one report: pass report_hash with the manifest, the 64 hex characters wp_site_diff gave for the report the objects are for (the same the staging site made the package for).' );
			}
			$expect['report_hash'] = $hash;
		}
		return $expect;
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
				// A package of another pair counts as open no more.
				'binding'  => (string) $print,
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$record = AB_MCP_Sync_Canon::encode(
			array(
				'connection' => self::connection(),
				'expect'     => $expect,
				'format'     => self::RECORD,
				'manifest'   => $signed['manifest'],
				'print'      => $print,
				'signature'  => $signed['signature'],
			)
		);
		$put    = AB_MCP_Protected_Store::put( 'in', $gid, hash( 'sha256', $record ), $record );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'in', $gid );
			return $put;
		}
		return array(
			'gid'        => $gid,
			'manifest'   => $manifest,
			'signed'     => array(
				'manifest'  => $signed['manifest'],
				'signature' => $signed['signature'],
			),
			'expect'     => $expect,
			'print'      => $print,
			'connection' => self::connection(),
		);
	}

	/**
	 * The group of a package this account began over this connection, the
	 * oldest of its label, with its record. A group of another connection of
	 * the same account is none (Codex, round 1 of branch 7, A2): the reports
	 * and proposals made from a package are bound to their connection
	 * (decision 15), and so is the package they are made from.
	 *
	 * @param string $package_id Package.
	 * @return array|WP_Error|null held(), null where this account holds none over this connection.
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
			$found = self::record( $group );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( null !== $found && self::connected( $found ) ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Whether a package was begun over the connection of this call: the
	 * connection its record keeps is this one (a record without one, of a
	 * version before, is of none).
	 *
	 * @param array $found record().
	 * @return bool
	 */
	private static function connected( array $found ) {
		return null !== $found['connection'] && hash_equals( $found['connection'], self::connection() );
	}

	/**
	 * The connection of the call, as a package is bound to it (the record
	 * keeps it): a hash of AB_MCP_Staging_Gate::connection() (the token of
	 * MCP, else the account), as the reports and proposals have it
	 * (AB_MCP_Sync_Proposal::connection()).
	 *
	 * @return string
	 */
	public static function connection() {
		$conn = AB_MCP_Staging_Gate::connection();
		return hash( 'sha256', 'ab-sync-import|' . $conn['kind'] . ':' . $conn['id'] );
	}

	/**
	 * A group of the area in with its record.
	 *
	 * @param array $group An entry of AB_MCP_Protected_Store::groups().
	 * @return array|WP_Error|null held(), null where it has no record (yet).
	 */
	private static function record( array $group ) {
		$files = AB_MCP_Protected_Store::files( 'in', $group['gid'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$records = preg_grep( '/^[0-9a-f]{64}\z/', array_map( 'strval', array_keys( $files ) ) );
		if ( array() === $records ) {
			return null;
		}
		$name = reset( $records );
		$text = AB_MCP_Protected_Store::get( 'in', $group['gid'], $name, $name );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$record = json_decode( $text, true, 64 );
		return array(
			'gid'        => $group['gid'],
			'manifest'   => json_decode( $record['manifest'], true, 8 ),
			'signed'     => array(
				'manifest'  => $record['manifest'],
				'signature' => $record['signature'],
			),
			'expect'     => $record['expect'],
			'print'      => isset( $record['print'] ) && is_string( $record['print'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $record['print'] ) ? $record['print'] : null,
			'connection' => isset( $record['connection'] ) && is_string( $record['connection'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $record['connection'] ) ? $record['connection'] : null,
		);
	}

	/**
	 * Whether a package was begun with the pair as it is now: the print it
	 * kept is the one of now (AB_MCP_Sync_Anchor::pair_print(): this site's
	 * role, its pair, the confirmed key of the staging site).
	 *
	 * @param array       $found held().
	 * @param string|null $print The print of now.
	 * @return bool
	 */
	private static function bound( array $found, $print ) {
		return null !== $found['print'] && hash_equals( $found['print'], (string) $print );
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
				AB_MCP_Protected_Store::remove( 'in', $held['gid'] );
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
	 * AB_MCP_Sync_Canon::encode() writes, with the structure of a line of the
	 * inventory (line_shaped(), the first level of LINE_FIELDS), no object
	 * whose name is the name of a credential (those are never packed), and
	 * no line a lock of the credential check holds that carries more than its
	 * identity; as many lines and bytes as the manifest declares. The names
	 * of a line (the second level) never refuse it: what a credential holds
	 * by the rules of this site (AB_MCP_Redactor::extra_keys() included), in
	 * its name or written into a name of the line, is listed with the code
	 * of why (held_code()), and the package goes on.
	 *
	 * @param array $held held().
	 * @return array[]|WP_Error kind, SHA-256 of the ref and code of the objects held by this site (HELD_FORMAT).
	 */
	private static function unpacked( array $held ) {
		$manifest = $held['manifest'];
		$limits   = AB_MCP_Store_Budget::UNPACK;
		$extra    = AB_MCP_Redactor::extra_keys();
		// A package of the environment holds lines of AB_MCP_Sync_Env, no object.
		$env      = 'env' === $manifest['purpose'];
		// A package of objects: a line of the inventory with its form and content.
		$with_content = 'objects' === $manifest['purpose'];
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
			if ( "\n" !== substr( $text, -1 ) ) {
				return self::unpack_refusal( 'A chunk does not end with a whole line.' );
			}
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
				if ( $env ) {
					if ( ! AB_MCP_Sync_Env::shaped( $object ) ) {
						return self::unpack_refusal( 'A line is not one of the environment as the staging site writes it: a kind of line or a field it has no place for, or a field of another type.' );
					}
					// A name of the site (a file, a plugin, a table) a credential
					// holds by the rules of this site, or the staging site held:
					// listed, as an object of the inventory (Codex, round 1 of
					// branch 7, A1).
					$code = AB_MCP_Sync_Env::held_line( $object, $extra );
					if ( null !== $code ) {
						$out[] = array( $object['env'], hash( 'sha256', AB_MCP_Sync_Canon::encode( $object['ref'] ) ), $code );
					}
					continue;
				}
				$content = null;
				if ( $with_content ) {
					$split = self::object_split( $object );
					if ( null === $split ) {
						return self::unpack_refusal( 'A line of a package of objects is not one as the staging site writes it: its form or its content (the columns of its row, its meta rows, its terms) has a field it has no place for or of another type, or an object a credential holds carries content.' );
					}
					list( $object, $content ) = $split;
				}
				if ( ! self::line_shaped( $object ) ) {
					return self::unpack_refusal( 'A line is not one as the staging site writes it: a field it has no place for or of another type, a status, lock, space, strength or reason code outside its list, bytes not in the byte form, an identity not of its kind or naming another object, an object whose name is the name of a credential, or more than the identity of an object a credential holds.' );
				}
				$code = self::held_code( $object, $extra );
				if ( null === $code && null !== $content ) {
					// The content by the rules of this site (decision 9: the
					// check again at the import): a credential in a column, a
					// meta row or a term holds the object; it is never written.
					$code = self::content_held( $content, $extra );
				}
				if ( null !== $code ) {
					$out[] = array( $object['kind'], hash( 'sha256', AB_MCP_Sync_Canon::encode( $object['ref'] ) ), $code );
				}
			}
		}
		if ( $count !== $manifest['objects'] || $total !== $manifest['unpacked'] ) {
			return self::unpack_refusal( 'The lines are not as many, or not as long, as the manifest declares.' );
		}
		return $out;
	}

	/**
	 * A chunk inflated as far as $cap bytes: its input fed in pieces of
	 * INFLATE_PIECE bytes and the inflation stopped once the output passes
	 * $cap, so it passes $cap by at most what one piece inflates to (zlib
	 * inflates at most about 1032 times: some 516 KiB). One gzip member and
	 * nothing after it: all of the input read, the stream at its end.
	 *
	 * @param string $data Chunk (gzip).
	 * @param int    $cap  Bytes at most.
	 * @return string|false|null The bytes; false where it is no whole gzip,
	 *                           or bytes or a second member follow it; null
	 *                           where it inflates past $cap.
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
		return inflate_get_read_len( $context ) === strlen( $data ) ? $out : false;
	}

	/**
	 * A line of a package of objects taken apart: the line of the inventory
	 * (line_shaped() checks it) and its form and content. Null where the
	 * form or the content is not as AB_MCP_Sync_Export::object_line() writes
	 * it: a line with a hash carries form (an array) and content (row: name
	 * => name, meta: a list of [name, name], terms: a list of records with
	 * name, slug and taxonomy as names and term_id and term_order as
	 * integers; a content that could not be read is empty); a held line
	 * (no hash) carries neither.
	 *
	 * @param array $object Line.
	 * @return array{0:array,1:array}|null The line without them, and the content.
	 */
	private static function object_split( array $object ) {
		$has_form    = array_key_exists( 'form', $object );
		$has_content = array_key_exists( 'content', $object );
		if ( ! array_key_exists( 'hash', $object ) ) {
			return $has_form || $has_content ? null : array( $object, array() );
		}
		if ( ! $has_form || ! $has_content || ! is_array( $object['form'] ) || ! is_array( $object['content'] ) ) {
			return null;
		}
		$content = $object['content'];
		unset( $object['form'], $object['content'] );
		if ( array() === $content ) {
			return array( $object, array() );
		}
		if ( array( 'meta', 'row', 'terms' ) !== self::sorted_keys( $content ) || ! is_array( $content['row'] ) || ! is_array( $content['meta'] ) || ! is_array( $content['terms'] ) || ! array_is_list( $content['meta'] ) || ! array_is_list( $content['terms'] ) ) {
			return null;
		}
		foreach ( $content['row'] as $column => $value ) {
			if ( ! is_string( $column ) || ! self::typed( $value, 'name' ) ) {
				return null;
			}
		}
		foreach ( $content['meta'] as $row ) {
			if ( ! is_array( $row ) || array( 0, 1 ) !== array_keys( $row ) || ! self::typed( $row[0], 'name' ) || ! self::typed( $row[1], 'name' ) ) {
				return null;
			}
		}
		foreach ( $content['terms'] as $term ) {
			if ( ! is_array( $term ) || array( 'name', 'slug', 'taxonomy', 'term_id', 'term_order' ) !== self::sorted_keys( $term ) || ! self::typed( $term['name'], 'name' ) || ! self::typed( $term['slug'], 'name' ) || ! self::typed( $term['taxonomy'], 'name' ) || ! is_int( $term['term_id'] ) || ! is_int( $term['term_order'] ) ) {
				return null;
			}
		}
		return array( $object, $content );
	}

	/**
	 * Why the content of an object is held by the rules of this site, or
	 * null: a column named like a credential with a value (as the canonical
	 * form locks it, AB_MCP_Sync_Credentials::column()), a column, a meta
	 * row or a name of a term with a credential in it (mixed) or one that
	 * cannot be read to its end, a meta key that is the name of a credential
	 * here (the staging site packed it under its own rules).
	 *
	 * @param array    $content The content (object_split()).
	 * @param string[] $extra   The names this site redacts on top.
	 * @return string|null A code of HELD_CODES.
	 */
	private static function content_held( array $content, array $extra ) {
		if ( ! isset( $content['row'] ) ) {
			return null;
		}
		$code = static function ( array $check ) {
			if ( 'b9' === $check['state'] ) {
				return 'credential_name';
			}
			if ( 'mixed' === $check['state'] ) {
				return 'credential_field';
			}
			return 'unreadable' === $check['state'] ? 'unreadable_name' : null;
		};
		foreach ( $content['row'] as $column => $value ) {
			$bytes = (string) self::bytes( $value );
			if ( '' !== $bytes && AB_MCP_Sync_Credentials::names_credential( (string) $column, $extra ) ) {
				return 'credential_field';
			}
			$why = $code( AB_MCP_Sync_Credentials::unit( '', $bytes, $extra ) );
			if ( null !== $why ) {
				return $why;
			}
		}
		foreach ( $content['meta'] as $row ) {
			$why = $code( AB_MCP_Sync_Credentials::unit( (string) self::bytes( $row[0] ), (string) self::bytes( $row[1] ), $extra ) );
			if ( null !== $why ) {
				return $why;
			}
		}
		foreach ( $content['terms'] as $term ) {
			foreach ( array( 'name', 'slug', 'taxonomy' ) as $field ) {
				$why = $code( AB_MCP_Sync_Credentials::unit( '', (string) self::bytes( $term[ $field ] ), $extra ) );
				if ( null !== $why ) {
					return $why;
				}
			}
		}
		return null;
	}

	/**
	 * Whether an object of a line has the structure of a line of the
	 * inventory: its fields from LINE_FIELDS with their types, those of
	 * LINE_NEEDS there; a kind of IDENTITIES with its identity as that kind
	 * has it and matching its ref; each lock, reference and possible
	 * reference with its fields and no other; no object whose name is the
	 * name of a credential (B9, never packed); a reason code of the list of
	 * its status where the takeover never reads the row
	 * (AB_MCP_Sync_Option_Schema::REASON_CODES) and none elsewhere, a target
	 * where WordPress reads another row and none elsewhere; and, where a
	 * lock of the credential check holds it, no more than its identity,
	 * status and locks. A name passes as any text or bytes (typed(): name).
	 *
	 * @param array $object Object.
	 * @return bool
	 */
	private static function line_shaped( array $object ) {
		foreach ( self::LINE_NEEDS as $field ) {
			if ( ! array_key_exists( $field, $object ) ) {
				return false;
			}
		}
		foreach ( $object as $field => $value ) {
			// A field a line has no place for has no type (typed(): none).
			$type = isset( self::LINE_FIELDS[ $field ] ) ? self::LINE_FIELDS[ $field ] : '';
			if ( 'locks' === $type ) {
				$fits = self::records( $value, self::LOCK_FIELDS, array_keys( self::LOCK_FIELDS ) ) && array() === array_filter( $value, static fn( $lock ) => ! self::lock_known( $lock ) );
			} elseif ( 'refs' === $type ) {
				$fits = self::records( $value, self::REF_FIELDS, self::REF_NEEDS );
			} elseif ( 'possible' === $type ) {
				$fits = self::records( $value, self::POSSIBLE_FIELDS, array( 'at' ) );
			} else {
				$fits = 'identity' === $type || self::typed( $value, $type );
			}
			if ( ! $fits ) {
				return false;
			}
		}
		if ( ! self::identity_fits( $object['kind'], $object['ref'], $object['identity'] ) ) {
			return false;
		}
		// An empty identity only where the row could not be read: the line
		// says so (not complete, the table in unread).
		if ( array() === $object['identity'] && ( ! isset( $object['complete'], $object['unread'] ) || false !== $object['complete'] || array() === array_intersect( $object['unread'], self::ROW_UNREAD ) ) ) {
			return false;
		}
		// The reason of a row the takeover never reads: a code of its status;
		// where WordPress reads another row, that row.
		$codes = isset( AB_MCP_Sync_Option_Schema::REASON_CODES[ $object['status'] ] ) ? AB_MCP_Sync_Option_Schema::REASON_CODES[ $object['status'] ] : null;
		if ( null === $codes ? array_key_exists( 'reason_code', $object ) : ! in_array( isset( $object['reason_code'] ) ? $object['reason_code'] : null, $codes, true ) ) {
			return false;
		}
		if ( ( 'unreachable' === $object['status'] ) !== array_key_exists( 'target', $object ) ) {
			return false;
		}
		return ! AB_MCP_Sync_Export::credential_locked( $object['locks'] ) || array() === array_intersect_key( $object, array_flip( self::LINE_OPEN ) );
	}

	/**
	 * Whether the identity of a line is the one its kind has and names its
	 * ref: none (a row that could not be read) with a ref of the kind's form,
	 * or exactly the fields of IDENTITIES but those of IDENTITY_OPEN it may
	 * leave out (a theme mod also its name only, a
	 * name as any other: a row in bytes too), typed, with the ref they give:
	 * the id of a post, term or menu (item),
	 * the area of a widget area, the name of an option, the stylesheet and
	 * key of a theme mod, the stylesheet (and post) of the additional CSS.
	 *
	 * @param string $kind     Kind.
	 * @param string $ref      Ref.
	 * @param mixed  $identity Identity.
	 * @return bool
	 */
	private static function identity_fits( $kind, $ref, $identity ) {
		if ( ! isset( self::IDENTITIES[ $kind ] ) ) {
			return false;
		}
		$ref    = (string) self::bytes( $ref );
		$id_ref = 1 === preg_match( '/^[1-9][0-9]*\z/', $ref );
		if ( in_array( $kind, self::ID_KINDS, true ) && ! $id_ref ) {
			return false;
		}
		if ( ! is_array( $identity ) ) {
			return false;
		}
		if ( array() === $identity ) {
			return true;
		}
		$fields = self::IDENTITIES[ $kind ];
		if ( 'theme_mod' === $kind && array( 'name' ) === array_keys( $identity ) ) {
			$fields = array( 'name' => 'name' );
		}
		$given = array_map( 'strval', array_keys( $identity ) );
		$want  = array_keys( $fields );
		$needs = array_diff( $want, self::IDENTITY_OPEN[ $kind ] ?? array() );
		if ( array() !== array_diff( $given, $want ) || array() !== array_diff( $needs, $given ) ) {
			return false;
		}
		foreach ( $fields as $field => $type ) {
			if ( array_key_exists( $field, $identity ) && ! self::typed( $identity[ $field ], $type ) ) {
				return false;
			}
		}
		$text = array_map( array( __CLASS__, 'bytes' ), $identity );
		switch ( $kind ) {
			case 'widget_area':
				return $text['area'] === $ref;
			case 'option':
				return $text['name'] === $ref;
			case 'theme_mod':
				return 0 === strpos( $text['name'], 'theme_mods_' ) && substr( $text['name'], strlen( 'theme_mods_' ) ) . ( isset( $text['theme_mod'] ) ? '/' . $text['theme_mod'] : '' ) === $ref;
			case 'custom_css':
				return $text['stylesheet'] . ( $identity['linked'] ? '' : '@' . $identity['post'] ) === $ref;
			default:
				return true;
		}
	}

	/**
	 * Whether a value is a list of records, each with fields of $fields
	 * only, typed, and those of $needs there.
	 *
	 * @param mixed                $list   Value.
	 * @param array<string,string> $fields Field => type (typed()).
	 * @param string[]             $needs  Fields every record carries.
	 * @return bool
	 */
	private static function records( $list, array $fields, array $needs ) {
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return false;
		}
		foreach ( $list as $record ) {
			if ( ! is_array( $record ) || array() !== array_diff_key( array_flip( $needs ), $record ) ) {
				return false;
			}
			foreach ( $record as $field => $value ) {
				if ( ! isset( $fields[ $field ] ) || ! self::typed( $value, $fields[ $field ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * A name as the export writes it: a text, or bytes that are no UTF-8 in
	 * the byte form of AB_MCP_Sync_Canon::encode() ({"~b": base64}, only for
	 * bytes that are no UTF-8). The bytes, or null where it is neither.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function bytes( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) || array( '~b' ) !== array_keys( $value ) || ! is_string( $value['~b'] ) ) {
			return null;
		}
		$bytes = base64_decode( $value['~b'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the byte form of the canonical form.
		return is_string( $bytes ) && base64_encode( $bytes ) === $value['~b'] && 1 !== preg_match( '//u', $bytes ) ? $bytes : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the round trip.
	}

	/**
	 * Whether a lock is one of the tables of AB_MCP_Sync_Kinds: its key there
	 * (a lock of LOCKS, or an entry of OWNERSHIP a reader locks by), with
	 * the stage and the reason the table gives it.
	 *
	 * @param array $lock Lock (records() checked its fields).
	 * @return bool
	 */
	private static function lock_known( array $lock ) {
		if ( isset( AB_MCP_Sync_Kinds::LOCKS[ $lock['key'] ] ) ) {
			$entry = AB_MCP_Sync_Kinds::LOCKS[ $lock['key'] ];
		} elseif ( isset( AB_MCP_Sync_Kinds::OWNERSHIP[ $lock['key'] ] ) ) {
			$entry = AB_MCP_Sync_Kinds::OWNERSHIP[ $lock['key'] ];
		} else {
			return false;
		}
		return ( isset( $entry['stage'] ) ? $entry['stage'] : null ) === $lock['stage'] && ( isset( $entry['reason'] ) ? $entry['reason'] : null ) === $lock['reason'];
	}

	/**
	 * Whether a value has a type of a line. Content: name (any text, or
	 * bytes in the byte form of the canon, bytes()), names (a list of them),
	 * login (a name or null). Structure: string; table (any value: what the
	 * tables of AB_MCP_Sync_Kinds hold is compared with them, lock_known());
	 * canon (the version of the canonical form); space and strength (of the
	 * readers); integer (an int or the digits of one, negative too); number
	 * (an int or the digits of one); bool; twins (an int from 2); ints (a
	 * list of ints); hash (64 hex or null); size (an int from 0); status (of
	 * STATUSES).
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Type.
	 * @return bool
	 */
	private static function typed( $value, $type ) {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'table':
				return true;
			case 'name':
				return null !== self::bytes( $value );
			case 'names':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( null === self::bytes( $item ) ) {
						return false;
					}
				}
				return true;
			case 'login':
				return null === $value || null !== self::bytes( $value );
			case 'canon':
				return AB_MCP_Sync_Canon::VERSION === $value;
			case 'space':
				return in_array( $value, self::SPACES, true );
			case 'strength':
				return in_array( $value, self::STRENGTHS, true );
			case 'integer':
				return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]{1,19}\z/', $value ) );
			case 'number':
				return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,20}\z/', $value ) );
			case 'bool':
				return is_bool( $value );
			case 'twins':
				// How many objects of the site have the identity: two or more (Codex round 5).
				return is_int( $value ) && $value > 1;
			case 'ints':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( ! is_int( $item ) ) {
						return false;
					}
				}
				return true;
			case 'hash':
				return null === $value || ( is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $value ) );
			case 'size':
				return is_int( $value ) && $value >= 0;
			case 'status':
				return in_array( $value, self::STATUSES, true );
			default:
				// No type: no field of a line.
				return false;
		}
	}

	/**
	 * Why a line is held on this site (HELD_CODES), or null: its name, or the
	 * row WordPress reads instead (target), is the name of a credential by
	 * the rules of this site (named_credential(); a line without its
	 * identity names its object by its ref); else, over every name of the
	 * line (names_of()), what the export runs over a value: the content check
	 * of the credential check (AB_MCP_Sync_Credentials::unit()) and the net
	 * over the raw value in every layer of it (AB_MCP_Sync_Net::over_name():
	 * decoded, in base64, in data:). A field of a credential in one, or one
	 * that does not read to its end, holds the line. A slug «reset-password»
	 * is no field of one; {"password":"…"} is, as its base64 and
	 * password%253D… are.
	 *
	 * @param array    $object Line (line_shaped()).
	 * @param string[] $extra  AB_MCP_Redactor::extra_keys().
	 * @return string|null
	 */
	private static function held_code( array $object, array $extra ) {
		$named = ( array() === $object['identity'] ? array( 'name' => $object['ref'] ) : $object['identity'] ) + array_intersect_key( $object, array( 'target' => true ) );
		if ( self::named_credential( $named, $extra ) ) {
			return 'credential_name';
		}
		foreach ( self::names_of( $object ) as $name ) {
			$check = AB_MCP_Sync_Credentials::unit( '', $name, $extra );
			if ( 'mixed' === $check['state'] ) {
				return 'credential_field';
			}
			if ( 'unreadable' === $check['state'] ) {
				return 'unreadable_name';
			}
			$net = AB_MCP_Sync_Net::over_name( $name, $extra );
			if ( 'credential' === $net ) {
				return 'credential_field';
			}
			if ( 'unreadable' === $net ) {
				return 'unreadable_name';
			}
		}
		return null;
	}

	/**
	 * Every name of a line, as bytes, once: each field typed name, names or
	 * login (LINE_FIELDS, LOCK_FIELDS, REF_FIELDS, POSSIBLE_FIELDS) and each
	 * text of its identity.
	 *
	 * @param array $object Line (line_shaped()).
	 * @return string[]
	 */
	private static function names_of( array $object ) {
		$out    = array_values( $object['identity'] );
		$fields = array(
			array( array( $object ), self::LINE_FIELDS ),
			array( $object['locks'], self::LOCK_FIELDS ),
			array( isset( $object['refs'] ) ? $object['refs'] : array(), self::REF_FIELDS ),
			array( isset( $object['possible'] ) ? $object['possible'] : array(), self::POSSIBLE_FIELDS ),
		);
		foreach ( $fields as list( $records, $types ) ) {
			foreach ( $records as $record ) {
				foreach ( $types as $field => $type ) {
					if ( ! isset( $record[ $field ] ) || ! in_array( $type, array( 'name', 'names', 'login' ), true ) ) {
						continue;
					}
					$out = array_merge( $out, 'names' === $type ? $record[ $field ] : array( $record[ $field ] ) );
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'bytes' ), $out ), 'is_string' ) ) );
	}

	/**
	 * Whether the identity of an object names a credential by the rules of
	 * this site: the name of an option or of a row of theme mods, the key of
	 * a theme mod, the row WordPress reads instead of it.
	 *
	 * @param array    $identity Identity (and target).
	 * @param string[] $extra    AB_MCP_Redactor::extra_keys().
	 * @return bool
	 */
	private static function named_credential( array $identity, array $extra ) {
		foreach ( array( 'name', 'theme_mod', 'target' ) as $field ) {
			if ( isset( $identity[ $field ] ) && AB_MCP_Sync_Credentials::names_credential( (string) self::bytes( $identity[ $field ] ), $extra ) ) {
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
			return new WP_Error( 'ab_mcp_sync_import_held', 'The package is marked complete, but the list of its objects a credential holds on this site does not read back (it was taken over by an older version, or the list was changed), so it is not used, and what arrived of it was removed. Fetch the package anew: 1. Call wp_site_import_chunk again with the package_id, manifest and purpose as wp_site_export gave them, while the manifest is valid; or export it again on the staging site (wp_site_export). 2. Hand over or fetch its chunks.' );
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
