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
 * object a credential holds only with its identity, status and locks. What is
 * left is the next package: the answer carries the cursor to go on from,
 * which names this package; the packages of one inventory are one series
 * and count as one open export (AB_MCP_Store_Budget). The manifest lists
 * each kind and subtype of the package; complete is true only where the
 * package holds every object of it (it began that kind of the database at
 * its start and read it to its end, and no object of that kind and subtype
 * was withheld, kinds()). A package of the environment (purpose env,
 * make_env(), branch 7) holds the lines of the environment of this site
 * instead (AB_MCP_Sync_Env: versions, hashes of must-use plugins and
 * drop-ins, counts of files and tables, group U), no object; each package
 * of its series begins with its page. A package of objects (purpose
 * objects, make_objects(), branch 8) holds, for the objects a report named
 * (its report_hash is in the manifest, decision 5), each object as line()
 * writes it with its canonical form and its content as the rows of this
 * site hold it (the engine of its kind reads them, AB_MCP_Sync_Kind::
 * export(): a post with its row, meta and terms); no series, one package
 * for at most MAX_REFS objects, each within the raw size of one object of
 * decision 8. An object a credential holds carries no content, as in a
 * package of the inventory; one the account may not read is withheld.
 * A package of media (purpose media, make_media(), branch 10) holds, for
 * the attachments a report named (its report_hash in the manifest), the
 * bytes of the files of their file lists (AB_MCP_Sync_Media_Package: one
 * line a piece of a file), whole attachments up to
 * AB_MCP_Sync_Media_Package::MAX_BYTES; what does not fit is named for the
 * next package, one the account may not read is withheld.
 *
 * WHO MAKES IT (decision 9): the confirmed staging site paired with a
 * production site, with write access on (the access level Full), for an
 * account with manage_options (refusal()). Of each object only what that
 * account may read (AB_MCP_Sync_Export_Rights): any other is not in the
 * package, counted and named in the answer (withheld). An object whose name
 * is the name of a credential (B9) is not in the package and is counted
 * (credentials.names); one with a field of a credential in it, or any lock
 * of the credential check, is held to its identity, status and locks
 * (credentials.held, line()).
 *
 * WHAT IT IS BOUND TO (binding()): the key, both addresses, the confirmed
 * role and the pair. A package whose binding is not the site's any more
 * (the pair or the role confirmed again, a new key) is given out no more,
 * neither by the route nor by the relay nor with fresh fetch addresses,
 * and is discarded where it is met and before a new package is made.
 *
 * HOW IT LEAVES THE SITE.
 *
 * 1. Direct: the production site asks the route of this site for each
 *    chunk, at an address it builds itself from the address of its staging
 *    site (AB_MCP_Sync_Import), with the four values of a fetch address
 *    this site signed (AB_MCP_Sync_Grant: the route, its MAC, the known
 *    limit of ten minutes). With a MAC of this site, deliver() gives the
 *    chunk of a package this site keeps and that is bound as it is now.
 * 2. Relay: wp_site_export_chunk gives a chunk of a package this account
 *    made as base64 to the client, which hands it to wp_site_import_chunk
 *    on the production site. Both refuse a request that did not come over
 *    https where the site has a public address (relay_refusal()).
 *
 * Messages are in gettext, in the language of the site, as the other
 * classes of the takeover; each names the way. Codes and machine values
 * (kinds, purposes, parameters, fields) stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-outbound.php';
require_once __DIR__ . '/class-sync-manifest.php';
require_once __DIR__ . '/class-sync-grant.php';
require_once __DIR__ . '/class-sync-inventory.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-export-rights.php';
require_once __DIR__ . '/class-sync-env.php';
require_once __DIR__ . '/class-sync-media-package.php';

/**
 * Class AB_MCP_Sync_Export
 */
final class AB_MCP_Sync_Export {

	/** The format of the record of a package in its group. */
	const RECORD = 'ab-sync-export/1';

	/** Bytes of a record at most: the manifest with its signature and cursor. */
	const RECORD_MAX = 2162688;

	/** Bytes of the lines of one chunk at most: its gzip stays within MAX_CHUNK. */
	const SLICE = 5177344;

	/**
	 * Seconds after which a group of the area out without its record is a
	 * making that ended between its chunks and its record and is discarded
	 * (discard_stale()); a making writes both within seconds.
	 */
	const ABANDONED = 600;

	/** Objects one package reads at most (see the class). */
	const OBJECTS = 4000;

	/** Withheld objects an answer names at most (all are counted). */
	const WITHHELD_LIST = 200;


	/** The purposes this version makes packages for: the inventory, the environment (AB_MCP_Sync_Env), the objects of a report and the files of its attachments. */
	const PURPOSES = array( 'inventory', 'env', 'objects', 'media' );

	/** Objects a package of objects holds at most (the list a tool call takes, AB_MCP_Security). */
	const MAX_REFS = 2000;

	/**
	 * The operations of the locks the credential check gives (line()): a
	 * field of a credential and a post protected by a password
	 * (AB_MCP_Sync_Credentials::column(), ::note()), a unit it could not
	 * read to its end with its why (a script, HTML outside kses, an object,
	 * a value that does not unpack or nests too deep), a name of a
	 * credential in a layer no reader read (AB_MCP_Sync_Net). The readers
	 * of the canonical form give the last two of the unread for the same
	 * values; they count as well (fail closed).
	 */
	const CREDENTIAL_LOCKS = array( 'credential_field', 'password_protected', 'script', 'html_outside_kses', 'php_object', 'unreadable_value', 'unread_credential' );


	/* --------------------------------------------------------------- make */

	/**
	 * Make a package on this confirmed staging site, for the production site
	 * it is paired with.
	 *
	 * @param mixed      $purpose What the package is for (PURPOSES).
	 * @param mixed      $cursor  The cursor of the answer for the package
	 *                            before, or null for the first of a series.
	 * @param int|null   $objects Objects the package reads at most; OBJECTS.
	 * @param array|null $for     For purpose objects: report_hash (the report
	 *                            the objects are for) and refs (the objects:
	 *                            kind and ref on this site, as the report
	 *                            names the staging side).
	 * @return array|WP_Error The package (answer()).
	 */
	public static function make( $purpose, $cursor = null, $objects = null, $for = null ) {
		$refused = self::refusal();
		if ( null !== $refused ) {
			return $refused;
		}
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new WP_Error( 'ab_mcp_sync_export_purpose', __( 'This version makes packages of the inventory and of the environment of the staging site, packages of objects for a report and packages of the files of its attachments. Pass purpose=inventory, purpose=env, purpose=objects or purpose=media.', 'alphabridge-mcp' ) );
		}
		if ( 'env' === $purpose ) {
			return self::make_env( $cursor, null === $objects ? self::OBJECTS : (int) $objects );
		}
		if ( 'objects' === $purpose ) {
			return self::make_objects( is_array( $for ) ? $for : array() );
		}
		if ( 'media' === $purpose ) {
			return self::make_media( is_array( $for ) ? $for : array() );
		}
		$from  = null;
		$index = null;
		try {
			$series = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_export_random', __( 'No package was made: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.', 'alphabridge-mcp' ) );
		}
		if ( null !== $cursor ) {
			$follows = self::follows( $cursor, 'inventory' );
			if ( is_wp_error( $follows ) ) {
				return $follows;
			}
			list( $from, $series, $index ) = $follows;
		}
		// The index of identities of the series, as the package before kept
		// it (AB_MCP_Sync_Identity: built once for the series, in parts).
		$text = null;
		if ( null !== $index ) {
			$text = '';
			foreach ( $index['parts'] as $part ) {
				$slice = AB_MCP_Protected_Store::get( 'out', $index['gid'], $part['name'], $part['sha'] );
				if ( is_wp_error( $slice ) ) {
					return new WP_Error( 'ab_mcp_sync_export_index', __( 'The index of the identities this series of packages began with is no longer in the protected store of this site (it is kept with a package of the series, a day), so the series does not go on. Begin a new series: call wp_site_export without a cursor.', 'alphabridge-mcp' ) );
				}
				$text .= $slice;
			}
			unset( $slice );
		}
		$pair   = AB_MCP_Staging::record()['pair'];
		$source = AB_MCP_Sync_Inventory::source( array( home_url(), $pair['partner_url'] ), AB_MCP_Sync_Inventory::BUDGET, null, null === $objects ? self::OBJECTS : (int) $objects, $text );
		unset( $text );
		$read = AB_MCP_Sync_Inventory::run( $source, $from );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		// The objects carry what they need of the index: it goes before the
		// package is made (its text, where this call built on it, goes with
		// the package), and the objects go once their lines are written, so
		// that a package needs no more memory than before the index (Codex
		// round 4, B).
		AB_MCP_Sync_Identity::reset();
		$sorted = self::sort( $read['objects'] );
		$kinds  = self::kinds( $read['objects'], $from, $read['cursor'], $sorted['incomplete'] );
		if ( is_wp_error( $kinds ) ) {
			return $kinds;
		}
		$lines = array_map( array( __CLASS__, 'line' ), $sorted['packed'] );
		$next  = $read['cursor'];
		$text  = $read['index'];
		unset( $read, $sorted['packed'] );
		$packed = self::pack( $lines );
		unset( $lines );
		if ( is_wp_error( $packed ) ) {
			return $packed;
		}
		return self::store( $purpose, $packed, $kinds, $next, $series, $sorted['report'], $index, $text );
	}

	/**
	 * Make a package of the environment of this staging site
	 * (AB_MCP_Sync_Env): as far as the budget of one call reaches
	 * (AB_MCP_Sync_Inventory::BUDGET, OBJECTS lines), each package beginning with
	 * its page; the cursor goes on where it ended, in the same series.
	 * Nothing of an object is in it, and no value of a credential
	 * (AB_MCP_Sync_Env: group U shows no constant that holds a secret); a
	 * name of the site a credential holds only by its SHA-256 in the lines
	 * (AB_MCP_Sync_Env::held()), and no name at all in the cursor, only the
	 * SHA-256 of where the reading stopped (AB_MCP_Sync_Env::place()).
	 *
	 * @param mixed $cursor The cursor of the answer before, or null.
	 * @param int   $lines  Lines one package holds at most.
	 * @return array|WP_Error The package (answer()).
	 */
	private static function make_env( $cursor, $lines ) {
		$state = null;
		try {
			$series = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_export_random', __( 'No package was made: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.', 'alphabridge-mcp' ) );
		}
		if ( null !== $cursor ) {
			$follows = self::follows( $cursor, 'env' );
			if ( is_wp_error( $follows ) ) {
				return $follows;
			}
			$state  = $follows[0]['inner'];
			$series = $follows[1];
		}
		$read = AB_MCP_Sync_Env::read( $state, AB_MCP_Sync_Inventory::BUDGET, $lines, 'staging' );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$packed = self::pack( $read['lines'] );
		if ( is_wp_error( $packed ) ) {
			return $packed;
		}
		$report = array(
			'withheld'    => array(
				'count'   => 0,
				'objects' => array(),
			),
			// A name of the site a credential holds is in the package by its
			// SHA-256 only (AB_MCP_Sync_Env::held()): held counts those lines.
			'credentials' => array(
				'held'  => count( array_filter( $read['lines'], static fn( $line ) => isset( $line['held'] ) ) ),
				'names' => 0,
				'units' => 0,
			),
		);
		$next   = null === $read['state'] ? null : array(
			'kind'  => 'env',
			'inner' => $read['state'],
		);
		return self::store( 'env', $packed, array(), $next, $series, $report );
	}

	/**
	 * Make a package of objects of this staging site for one report of the
	 * production site (decision 5; branch 8): the objects the refs name,
	 * each read by the engine of its kind (AB_MCP_Sync_Kind::export()) as
	 * the line of the inventory with its canonical form and its content,
	 * so the production site writes exactly what the report compared (the
	 * engine checks each hash against the line of the report). What the
	 * account may not read is withheld and named (decision 9); an object a
	 * credential holds carries no content (as line() writes it) and is
	 * counted; an object of a kind whose engine writes nothing in this
	 * version refuses the package with the stage (fail closed, nothing is
	 * guessed); a ref that is not on this site is named as gone.
	 *
	 * @param array $for report_hash, refs.
	 * @return array|WP_Error The package (answer()).
	 */
	private static function make_objects( array $for ) {
		$report_hash = isset( $for['report_hash'] ) ? $for['report_hash'] : null;
		if ( ! is_string( $report_hash ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $report_hash ) ) {
			return new WP_Error( 'ab_mcp_sync_export_report', __( 'A package of objects is made for one report: pass report_hash, the 64 hex characters wp_site_diff gave on the production site, so the package is read for that report only.', 'alphabridge-mcp' ) );
		}
		$refs = isset( $for['refs'] ) ? $for['refs'] : null;
		if ( ! is_array( $refs ) || ! array_is_list( $refs ) || array() === $refs || count( $refs ) > self::MAX_REFS ) {
			/* translators: %d: the most objects one package takes. */
			return new WP_Error( 'ab_mcp_sync_export_refs', sprintf( __( 'A package of objects takes refs: a list of 1 to %d objects of this staging site, each with kind and ref as the entries of the report name the staging side (staging.ref). Pass that list.', 'alphabridge-mcp' ), self::MAX_REFS ) );
		}
		$by_kind = array();
		foreach ( $refs as $i => $one ) {
			if ( ! is_array( $one ) || ! isset( $one['kind'], $one['ref'] ) || ! is_string( $one['kind'] ) || ! is_scalar( $one['ref'] ) || '' === (string) $one['ref'] ) {
				/* translators: %d: position in the list refs. */
				return new WP_Error( 'ab_mcp_sync_export_refs', sprintf( __( 'refs[%d] is not an object of kind and ref. Pass each as the entries of the report name the staging side.', 'alphabridge-mcp' ), (int) $i ) );
			}
			$by_kind[ $one['kind'] ][ (string) $one['ref'] ] = true;
		}
		try {
			$series = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_export_random', __( 'No package was made: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.', 'alphabridge-mcp' ) );
		}
		$pair   = AB_MCP_Staging::record()['pair'];
		$source = AB_MCP_Sync_Inventory::source( array( home_url(), $pair['partner_url'] ), AB_MCP_Sync_Inventory::BUDGET, null, null, null );
		$read   = array();
		$gone   = array();
		foreach ( $by_kind as $kind_name => $list ) {
			$kind = AB_MCP_Sync_Db_Kinds::for_pair( $kind_name );
			if ( null === $kind ) {
				/* translators: %s: the kind that was passed, as the tools name it. */
				return new WP_Error( 'ab_mcp_sync_export_kind', sprintf( __( 'The takeover knows no kind %s, so no package of objects of it is made. Use the kind as the report names it (post, term, attachment, menu, menu_item, widget_area, custom_css, option).', 'alphabridge-mcp' ), $kind_name ), array( 'kind' => $kind_name ) );
			}
			$asked = array();
			foreach ( array_keys( $list ) as $ref ) {
				$asked[] = array(
					'kind'   => $kind_name,
					'ref'    => (string) $ref,
					'source' => $source,
				);
			}
			$objects = $kind->export( $asked );
			if ( is_wp_error( $objects ) ) {
				return $objects;
			}
			$found = array();
			foreach ( $objects as $object ) {
				// The finished content through the credential check once more,
				// whatever a kind appended to it (Codex round 2 of branch 11, A8).
				$object = self::checked_content( $object );
				// Its status by the rule of the inventory (b9 where the kind named
				// the object so), before it is sorted: a result of export() names
				// none of its own (branch 9; before, the test for B9 in sort()
				// read a key that was not there).
				$object['status']                 = AB_MCP_Sync_Inventory::status_of( $object );
				$found[ (string) $object['ref'] ] = true;
				$read[]                           = $object;
			}
			foreach ( array_keys( $list ) as $ref ) {
				if ( ! isset( $found[ (string) $ref ] ) ) {
					$gone[] = array(
						'kind' => $kind_name,
						'ref'  => (string) $ref,
					);
				}
			}
		}
		AB_MCP_Sync_Identity::reset();
		$sorted = self::sort( $read );
		foreach ( $gone as $one ) {
			++$sorted['report']['withheld']['count'];
			if ( count( $sorted['report']['withheld']['objects'] ) < self::WITHHELD_LIST ) {
				$sorted['report']['withheld']['objects'][] = array(
					'kind'    => $one['kind'],
					'needs'   => 'gone',
					'ref'     => $one['ref'],
					'subtype' => '',
				);
			}
		}
		$kinds = array();
		foreach ( $sorted['packed'] as $object ) {
			$subtype = self::subtype( $object );
			$key     = $object['kind'] . "\0" . $subtype;
			// A selection, never every object of its kind.
			$kinds[ $key ] = array(
				'complete' => false,
				'kind'     => (string) $object['kind'],
				'subtype'  => $subtype,
			);
		}
		ksort( $kinds, SORT_STRING );
		$lines = array_map( array( __CLASS__, 'object_line' ), $sorted['packed'] );
		unset( $read, $sorted['packed'] );
		$packed = self::pack( $lines );
		unset( $lines );
		if ( is_wp_error( $packed ) ) {
			return $packed;
		}
		return self::store( 'objects', $packed, array_values( $kinds ), null, $series, $sorted['report'], null, null, $report_hash );
	}

	/**
	 * Make a package of media of this staging site for one report of the
	 * production site (decision 26; branch 10): the files of the file lists
	 * of the attachments the refs name, each read as the list names it
	 * (AB_MCP_Sync_Media::files(): the uploads resolved, no link, a regular
	 * file of its size and SHA-256), a piece a line
	 * (AB_MCP_Sync_Media_Package::lines_of()), written to the store a chunk
	 * at a time, so no more than a chunk is held at once. Whole attachments
	 * up to AB_MCP_Sync_Media_Package::MAX_BYTES: the next ones are named in
	 * left (export them in another package), one whose files alone are more
	 * in too_large (upload it by hand there); one the account may not read is
	 * withheld (decision 9), one whose list does not read whole is named in
	 * unread with its problem. A file that changed while it was read refuses
	 * the package, nothing of it is kept.
	 *
	 * @param array $for report_hash, refs (kind attachment, ref).
	 * @return array|WP_Error The package (answer()), with media: packed, left, too_large, unread.
	 */
	private static function make_media( array $for ) {
		$report_hash = isset( $for['report_hash'] ) ? $for['report_hash'] : null;
		if ( ! is_string( $report_hash ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $report_hash ) ) {
			return new WP_Error( 'ab_mcp_sync_export_report', __( 'A package of media is made for one report: pass report_hash, the 64 hex characters wp_site_diff gave on the production site, so the package is read for that report only.', 'alphabridge-mcp' ) );
		}
		$refs = isset( $for['refs'] ) ? $for['refs'] : null;
		if ( ! is_array( $refs ) || ! array_is_list( $refs ) || array() === $refs || count( $refs ) > self::MAX_REFS ) {
			/* translators: %d: the most attachments one package takes. */
			return new WP_Error( 'ab_mcp_sync_export_refs', sprintf( __( 'A package of media takes refs: a list of 1 to %d attachments of this staging site, each with kind attachment and ref as the entries of the report name the staging side (staging.ref). Pass that list.', 'alphabridge-mcp' ), self::MAX_REFS ) );
		}
		$ids = array();
		foreach ( $refs as $i => $one ) {
			if ( ! is_array( $one ) || ! isset( $one['kind'], $one['ref'] ) || 'attachment' !== $one['kind'] || ! is_scalar( $one['ref'] ) || 1 !== preg_match( '/^[1-9][0-9]*\z/', (string) $one['ref'] ) ) {
				/* translators: %d: position in the list refs. */
				return new WP_Error( 'ab_mcp_sync_export_refs', sprintf( __( 'refs[%d] is not an attachment of this staging site: a package of media holds the files of attachments only, each with kind attachment and its ref. Pass the other objects in a package of objects (purpose=objects).', 'alphabridge-mcp' ), (int) $i ) );
			}
			$ids[ (int) $one['ref'] ] = true;
		}
		try {
			$series = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_export_random', __( 'No package was made: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.', 'alphabridge-mcp' ) );
		}
		$uploads = AB_MCP_Sync_Media::uploads();
		$root    = '' === $uploads['problem'] ? realpath( $uploads['dir'] ) : false;
		$media   = array(
			'packed'    => array(),
			'left'      => array(),
			'too_large' => array(),
			'unread'    => array(),
		);
		$told    = array(
			'withheld'    => array(
				'count'   => 0,
				'objects' => array(),
			),
			'credentials' => array(
				'held'  => 0,
				'names' => 0,
				'units' => 0,
			),
		);
		// Each attachment read as the package of objects reads it (its kind,
		// AB_MCP_Sync_Kind::export()), so its paths and file names go through
		// the same check of credentials as its values (Codex round 1 of branch
		// 10, A7: a stored path such as password=….jpg): one a credential holds
		// (B9, a field of one, a lock of the check) is left out, counted and
		// named as withheld (needs credential), none of its files packed.
		$pair   = AB_MCP_Staging::record()['pair'];
		$source = AB_MCP_Sync_Inventory::source( array( home_url(), $pair['partner_url'] ), AB_MCP_Sync_Inventory::BUDGET, null, null, null );
		$kind   = AB_MCP_Sync_Db_Kinds::for_pair( 'attachment' );
		if ( null === $kind ) {
			return new WP_Error( 'ab_mcp_sync_export_kind', __( 'The takeover knows no kind attachment in this version, so no package of media is made. Update AlphaBridge MCP on both sites to the same version, then call again.', 'alphabridge-mcp' ) );
		}
		$asked = array();
		foreach ( array_keys( $ids ) as $id ) {
			$asked[] = array(
				'kind'   => 'attachment',
				'ref'    => (string) $id,
				'source' => $source,
			);
		}
		$objects = $kind->export( $asked );
		AB_MCP_Sync_Identity::reset();
		if ( is_wp_error( $objects ) ) {
			return $objects;
		}
		$held = array();
		foreach ( $objects as $object ) {
			$object = self::checked_content( $object );
			$status = AB_MCP_Sync_Inventory::status_of( $object );
			if ( 'b9' === $status || array() !== $object['credentials']['mixed'] || self::credential_locked( $object['locks'] ) ) {
				$held[ (string) $object['ref'] ] = 'b9' === $status ? 'names' : 'held';
			}
		}
		unset( $objects );
		$take    = array();
		$total   = 0;
		$pieces  = 0;
		foreach ( array_keys( $ids ) as $id ) {
			if ( isset( $held[ (string) $id ] ) ) {
				++$told['credentials'][ $held[ (string) $id ] ];
				++$told['withheld']['count'];
				if ( count( $told['withheld']['objects'] ) < self::WITHHELD_LIST ) {
					$told['withheld']['objects'][] = array(
						'kind'    => 'attachment',
						'needs'   => 'credential',
						'ref'     => (string) $id,
						'subtype' => 'attachment',
					);
				}
				continue;
			}
			$lacks = AB_MCP_Sync_Export_Rights::withheld(
				array(
					'kind' => 'attachment',
					'ref'  => (string) $id,
				)
			);
			if ( null !== $lacks ) {
				++$told['withheld']['count'];
				if ( count( $told['withheld']['objects'] ) < self::WITHHELD_LIST ) {
					$told['withheld']['objects'][] = array(
						'kind'    => 'attachment',
						'needs'   => $lacks,
						'ref'     => (string) $id,
						'subtype' => 'attachment',
					);
				}
				continue;
			}
			$list = false === $root ? null : AB_MCP_Sync_Media::files( $id );
			if ( ! is_array( $list ) || array() !== $list['problems'] ) {
				$media['unread'][] = array(
					'ref'      => (string) $id,
					'problems' => is_array( $list ) ? array_slice( array_values( array_unique( array_column( $list['problems'], 'problem' ) ) ), 0, 5 ) : array( false === $root ? 'uploads' : 'unreadable' ),
				);
				continue;
			}
			$bytes = AB_MCP_Sync_Media::total( $list );
			if ( $bytes > AB_MCP_Sync_Media_Package::MAX_BYTES ) {
				$media['too_large'][] = array(
					'ref'   => (string) $id,
					'bytes' => $bytes,
				);
				continue;
			}
			// The lines it takes, a piece a line: as for the bytes, the next
			// attachments go to left once a package would hold more lines than
			// a manifest names objects (Codex round 1 of branch 10, C: before,
			// the whole package was refused).
			$lines_of = 0;
			foreach ( $list['files'] as $file ) {
				$lines_of += AB_MCP_Sync_Media_Package::pieces( (int) $file['size'] );
			}
			if ( $lines_of > AB_MCP_Sync_Manifest::MAX_OBJECTS ) {
				$media['too_large'][] = array(
					'ref'   => (string) $id,
					'bytes' => $bytes,
				);
				continue;
			}
			if ( $total + $bytes > AB_MCP_Sync_Media_Package::MAX_BYTES || $pieces + $lines_of > AB_MCP_Sync_Manifest::MAX_OBJECTS ) {
				$media['left'][] = (string) $id;
				continue;
			}
			$pieces            += $lines_of;
			$total             += $bytes;
			$take[]             = array( $id, $list['files'] );
			$media['packed'][]  = (string) $id;
		}
		if ( array() === $take ) {
			return new WP_Error( 'ab_mcp_sync_export_media_none', __( 'No file of the attachments named could be packed: each was withheld, does not read whole, or is too large for one package (see media). Check those attachments in the media library of this site, or pass others in refs.', 'alphabridge-mcp' ), array( 'media' => $media, 'withheld' => $told['withheld'] ) );
		}
		self::discard_stale(); // The packages of another binding go first, as before any package.
		// The lines (base64, a third more than the bytes) and their gzip stay below this.
		$declared = (int) ceil( $total * 1.4 ) + 1048576 + self::RECORD_MAX;
		$label    = 'series:' . $series;
		$gid      = AB_MCP_Protected_Store::open(
			'out',
			array(
				'declared' => $declared,
				'label'    => $label,
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$list     = array();
		$bytes    = 0;
		$whole    = hash_init( 'sha256' );
		$slice    = '';
		$lines    = 0;
		$unpacked = 0;
		$flush    = static function () use ( &$slice, &$list, &$bytes, $whole, $gid ) {
			$gz = function_exists( 'gzencode' ) ? gzencode( $slice, 6 ) : false;
			if ( ! is_string( $gz ) ) {
				return new WP_Error( 'ab_mcp_sync_export_gzip', __( 'The package could not be compressed. Ask the host to enable the zlib extension of PHP, then call again.', 'alphabridge-mcp' ) );
			}
			if ( count( $list ) >= AB_MCP_Sync_Manifest::MAX_LIST || $bytes + strlen( $gz ) > AB_MCP_Sync_Manifest::MAX_BYTES ) {
				return self::too_large( count( $list ) >= AB_MCP_Sync_Manifest::MAX_LIST ? 'chunks' : 'bytes', count( $list ) >= AB_MCP_Sync_Manifest::MAX_LIST ? AB_MCP_Sync_Manifest::MAX_LIST : AB_MCP_Sync_Manifest::MAX_BYTES );
			}
			$put = AB_MCP_Protected_Store::put( 'out', $gid, self::chunk_name( count( $list ) ), $gz );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			hash_update( $whole, $gz );
			$bytes += strlen( $gz );
			$list[] = array(
				'bytes'  => strlen( $gz ),
				'sha256' => hash( 'sha256', $gz ),
			);
			$slice  = '';
			return true;
		};
		$put_line = static function ( $line ) use ( &$slice, &$lines, &$unpacked, $flush ) {
			if ( strlen( $line ) + 1 > self::SLICE ) {
				return new WP_Error( 'ab_mcp_sync_export_object', __( 'A piece of a file cannot be written into a package. Update AlphaBridge MCP on both sites to the same version, then call again.', 'alphabridge-mcp' ) );
			}
			if ( '' !== $slice && strlen( $slice ) + strlen( $line ) + 1 > self::SLICE ) {
				$done = $flush();
				if ( true !== $done ) {
					return $done;
				}
			}
			$slice    .= $line . "\n";
			$unpacked += strlen( $line ) + 1;
			++$lines;
			return $lines > AB_MCP_Sync_Manifest::MAX_OBJECTS ? self::too_large( 'objects', AB_MCP_Sync_Manifest::MAX_OBJECTS ) : true;
		};
		foreach ( $take as list( $id, $files ) ) {
			foreach ( $files as $file ) {
				$done = AB_MCP_Sync_Media_Package::lines_of( (string) $root, (string) $id, $file, $put_line );
				if ( true !== $done ) {
					AB_MCP_Protected_Store::remove( 'out', $gid );
					return $done;
				}
			}
		}
		$done = $flush();
		if ( true !== $done ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $done;
		}
		if ( $unpacked > AB_MCP_Sync_Manifest::MAX_UNPACKED ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return self::too_large( 'unpacked', AB_MCP_Sync_Manifest::MAX_UNPACKED );
		}
		$signed = AB_MCP_Sync_Manifest::sign(
			array(
				'bytes'       => $bytes,
				'chunks'      => $list,
				'kinds'       => array(
					array(
						'complete' => false,
						'kind'     => 'attachment',
						'subtype'  => 'attachment',
					),
				),
				'objects'     => $lines,
				'package_id'  => $gid,
				'purpose'     => 'media',
				'report_hash' => $report_hash,
				'sha256'      => hash_final( $whole ),
				'unpacked'    => $unpacked,
			)
		);
		if ( is_wp_error( $signed ) ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $signed;
		}
		$record = AB_MCP_Sync_Canon::encode(
			array(
				'binding'   => self::binding(),
				'cursor'    => null,
				'format'    => self::RECORD,
				'index'     => null,
				'manifest'  => $signed['manifest'],
				'report'    => $told,
				'series'    => $series,
				'signature' => $signed['signature'],
			)
		);
		$put    = AB_MCP_Protected_Store::put( 'out', $gid, hash( 'sha256', $record ), $record );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $put;
		}
		$answer = self::answer( json_decode( $signed['manifest'], true ), $signed, null, $told );
		if ( is_array( $answer ) ) {
			$answer['media'] = $media + array(
				// Said to the person in the language of the site (Codex round 2
				// of branch 13, B1).
				'way' => array() === $media['left'] && array() === $media['too_large'] && array() === $media['unread'] ? null : __( 'Attachments in left did not fit this package: export them in another one (wp_site_export with purpose=media and their refs). One in too_large is more than a package carries: upload its file by hand in the media library of the production site. One in unread has a file list that does not read whole on this site (a file missing, a link, a path out of the uploads): check it in the media library here.', 'alphabridge-mcp' ),
			);
		}
		return $answer;
	}

	/**
	 * The content of an object as it goes into the package, through the
	 * credential check once more, after every field a kind appended to it
	 * (Codex round 2 of branch 11, A8: the names of the menus of an item, the
	 * version of the theme of the CSS came after the check of the form):
	 * each column of the row as the kinds check a column (the content of the
	 * CSS read as CSS), each meta row by its key and value, the names and
	 * slugs of the terms, anything else as a value; each in the form its kind
	 * checked it (a meta row through AB_MCP_Sync_Db_Kind::checked_meta() of
	 * the kind: the instance of a widget unpacked, with its base as the
	 * name; Codex round 4 of branch 11, B), and the net reads a meta value
	 * and a column the kind names (AB_MCP_Sync_Db_Kind::stored_column(): the
	 * value of an option) unpacked, as WordPress reads them and as the kinds
	 * check them (branch 11b). A meta row whose key is
	 * the name of a credential (B9) is left out of the content and named in
	 * b9, as the kinds leave such a key out of form and content (Codex round
	 * 3 of branch 11, C). Each unit as the kinds
	 * check one (check_unit()): the credential check, then the net over its
	 * raw value (AB_MCP_Sync_Net::check(): every layer of it, its decodings,
	 * base64, data:; Codex round 3 of branch 11, A8). A credential in any of
	 * them holds the object (its line carries no content then, sort() and
	 * line()); the locks, the credentials and what was not read to its end
	 * take the findings.
	 *
	 * @param array $object A result of AB_MCP_Sync_Kind::export().
	 * @return array
	 */
	public static function checked_content( array $object ) {
		if ( ! isset( $object['content'] ) || ! is_array( $object['content'] ) || array() === $object['content'] ) {
			return $object;
		}
		$kind = isset( $object['kind'] ) ? (string) $object['kind'] : '';
		// The kind reads a meta row in the form it checked it (an instance of
		// a widget unpacked; Codex round 4 of branch 11, B); without a kind,
		// as stored.
		static $readers = array();
		if ( ! isset( $readers[ $kind ] ) ) {
			$found             = AB_MCP_Sync_Db_Kinds::for_pair( $kind );
			$readers[ $kind ] = null === $found ? 'AB_MCP_Sync_Db_Kind' : get_class( $found );
		}
		$reader = $readers[ $kind ];
		$acc    = array(
			'locks'       => isset( $object['locks'] ) && is_array( $object['locks'] ) ? $object['locks'] : array(),
			'credentials' => isset( $object['credentials'] ) && is_array( $object['credentials'] ) ? $object['credentials'] + array( 'b9' => array(), 'mixed' => array() ) : array( 'b9' => array(), 'mixed' => array() ),
			// What the net reads beside them (AB_MCP_Sync_Net::mark()).
			'spelling'    => array(),
			'addressed'   => array(),
			'unread'      => array(),
		);
		foreach ( $object['content'] as $part => $value ) {
			if ( 'row' === $part && is_array( $value ) ) {
				foreach ( $value as $column => $field ) {
					if ( is_array( $field ) || is_object( $field ) ) {
						self::check_unit( $acc, $kind, 'content:' . $column, $field, '' );
					} else {
						// A column the kind reads as WordPress unpacks it (the value
						// of an option), unpacked here as well (branch 11b).
						self::check_unit( $acc, $kind, (string) $column, (string) $field, null, 'custom_css' === $kind && 'post_content' === $column, $reader::stored_column( (string) $column ) );
					}
				}
			} elseif ( 'meta' === $part && is_array( $value ) ) {
				$kept = array();
				foreach ( $value as $pair ) {
					if ( is_array( $pair ) && isset( $pair[0] ) && array_key_exists( 1, $pair ) ) {
						// A key named as a credential (B9) is no part: left out of
						// the content and named in b9, as the kinds leave it out of
						// form and content (Codex round 3 of branch 11, C).
						list( $name, $input ) = $reader::checked_meta( (string) $pair[0], $pair[1] );
						// A meta value as WordPress reads it, unpacked, as the
						// kinds read it (meta_form(); branch 11b).
						if ( ! self::check_unit( $acc, $kind, 'meta:' . (string) $pair[0], $input, $name, false, true ) ) {
							continue;
						}
					} else {
						self::check_unit( $acc, $kind, 'content:meta', $pair, '' );
					}
					$kept[] = $pair;
				}
				if ( count( $kept ) !== count( $value ) ) {
					$object['content']['meta'] = $kept;
				}
			} elseif ( 'terms' === $part && is_array( $value ) ) {
				foreach ( $value as $term ) {
					foreach ( array( 'name', 'slug' ) as $field ) {
						if ( is_array( $term ) && isset( $term[ $field ] ) ) {
							self::check_unit( $acc, $kind, 'term:' . ( isset( $term['taxonomy'] ) ? (string) $term['taxonomy'] : '' ) . ':' . $field, (string) $term[ $field ], '' );
						}
					}
				}
			} else {
				self::check_unit( $acc, $kind, 'content:' . (string) $part, $value, '' );
			}
		}
		$object['locks']       = $acc['locks'];
		$object['credentials'] = $acc['credentials'];
		if ( array() !== $acc['unread'] ) {
			$object['unread']   = array_merge( isset( $object['unread'] ) && is_array( $object['unread'] ) ? $object['unread'] : array(), $acc['unread'] );
			$object['complete'] = false;
		}
		return $object;
	}

	/**
	 * One unit of the finished content, as the kinds check a unit (the
	 * columns of a row, a meta row, a widget instance, the CSS): the mark of
	 * the net, the credential check (a column as AB_MCP_Sync_Credentials::
	 * column() checks one, its CSS as CSS; any other unit by its name and
	 * value, unit() and note()), then the net over the raw value
	 * (AB_MCP_Sync_Net::check(), the one the kinds call; without the count of
	 * references, which the readers of the kinds counted over the same
	 * values: no reader of addresses runs here). A unit named as a
	 * credential (B9) is not read further.
	 *
	 * @param array       $acc  Accumulator (locks, credentials, spelling, addressed, unread).
	 * @param string      $kind Kind.
	 * @param string      $at   The unit (a column, meta:<key>, term:<taxonomy>:<field>, content:<part>).
	 * @param mixed       $raw  Its value as it goes into the package.
	 * @param string|null $name   The name of the unit for unit() (a meta key, or ''); null for a column.
	 * @param bool        $css    Whether a column holds CSS.
	 * @param bool        $stored Whether the value is one WordPress unpacks as
	 *                            it reads it (a meta value, the value of an
	 *                            option): the net reads it unpacked, as the
	 *                            kinds do (AB_MCP_Sync_Net::check(); branch 11b).
	 * @return bool Whether the unit stays in the content (not B9).
	 */
	private static function check_unit( array &$acc, $kind, $at, $raw, $name, $css = false, $stored = false ) {
		$mark = AB_MCP_Sync_Net::mark( $acc );
		if ( null === $name ) {
			AB_MCP_Sync_Credentials::column( $acc, $kind, $at, $raw, $css );
		} elseif ( ! AB_MCP_Sync_Credentials::note( $acc, $kind, $at, AB_MCP_Sync_Credentials::unit( $name, $raw ) ) ) {
			return false;
		}
		AB_MCP_Sync_Net::check( $acc, $mark, $kind, $at, $raw, array(), false, $stored );
		return true;
	}

	/**
	 * The line of an object in a package of objects: the line of the
	 * inventory (line()) and, where no credential holds the object, its
	 * canonical form and its content as the engine of its kind read them.
	 *
	 * @param array $object An object of AB_MCP_Sync_Kind::export() (the
	 *                      result of AB_MCP_Sync_Canon::finish() with form
	 *                      and content).
	 * @return array
	 */
	public static function object_line( array $object ) {
		$line = self::line( AB_MCP_Sync_Inventory::entry( $object ) );
		if ( ! isset( $line['hash'] ) ) {
			// Held: identity, status and locks only (line()).
			return $line;
		}
		return $line + array(
			'form'    => $object['form'],
			'content' => isset( $object['content'] ) && is_array( $object['content'] ) ? $object['content'] : array(),
		);
	}

	/**
	 * The objects of the inventory sorted for the package (decision 9): an
	 * object whose name is the name of a credential (B9) is left out and
	 * counted; one the account may not read is left out, counted and named
	 * (its kind and subtype are not complete); the others are packed, those
	 * a credential holds counted.
	 *
	 * @param array[] $objects Objects of the inventory.
	 * @return array{packed:array[],incomplete:array<string,true>,report:array}
	 */
	private static function sort( array $objects ) {
		$packed      = array();
		$incomplete  = array();
		$withheld    = array();
		$count       = 0;
		// Keys in the order the record keeps them (AB_MCP_Sync_Canon::encode()),
		// so again() answers alike.
		$credentials = array(
			'held'  => 0,
			'names' => 0,
			'units' => 0,
		);
		foreach ( $objects as $object ) {
			$status = isset( $object['status'] ) && is_string( $object['status'] ) ? $object['status'] : null;
			if ( 'b9' === $status ) {
				++$credentials['names'];
				continue;
			}
			// An object without a status is not told from one named as a
			// credential: never packed, counted as withheld (fail closed;
			// branch 9). Every caller gives one (entry(), status_of()).
			$needs = null === $status ? 'status' : AB_MCP_Sync_Export_Rights::withheld( $object );
			if ( null !== $needs ) {
				++$count;
				$subtype = self::subtype( $object );
				$incomplete[ $object['kind'] . "\0" . $subtype ] = true;
				if ( count( $withheld ) < self::WITHHELD_LIST ) {
					$withheld[] = array(
						'kind'    => (string) $object['kind'],
						'needs'   => $needs,
						'ref'     => (string) $object['ref'],
						'subtype' => $subtype,
					);
				}
				continue;
			}
			$credentials['units'] += count( $object['credentials']['b9'] );
			if ( array() !== $object['credentials']['mixed'] || self::credential_locked( $object['locks'] ) ) {
				++$credentials['held'];
			}
			$packed[] = $object;
		}
		return array(
			'packed'     => $packed,
			'incomplete' => $incomplete,
			'report'     => array(
				'withheld'    => array(
					'count'   => $count,
					'objects' => $withheld,
				),
				'credentials' => $credentials,
			),
		);
	}

	/**
	 * The subtype of an object in the list of kinds: its post type or its
	 * taxonomy, else ''.
	 *
	 * @param array $object Object.
	 * @return string
	 */
	private static function subtype( array $object ) {
		$identity = $object['identity'];
		return (string) ( isset( $identity['post_type'] ) ? $identity['post_type'] : ( isset( $identity['taxonomy'] ) ? $identity['taxonomy'] : '' ) );
	}

	/**
	 * Where a package goes on from the one before: the cursor of the answer
	 * names that package, which must be one of this account, kept, bound as
	 * this site is now, and whose next cursor it is; the inventory goes on
	 * from the cursor that package recorded, and the package belongs to its
	 * series (one open export for the budgets, AB_MCP_Store_Budget).
	 *
	 * @param mixed  $cursor  The cursor of the answer before.
	 * @param string $purpose The purpose of the package to make: the package
	 *                        before is one of it.
	 * @return array{0:array,1:string,2:array|null}|WP_Error The cursor of the
	 *         inventory, the series and where its index of identities is kept.
	 */
	private static function follows( $cursor, $purpose ) {
		if ( ! is_array( $cursor ) || array( 'inner', 'kind', 'package' ) !== self::sorted_keys( $cursor ) || ! is_string( $cursor['kind'] ) ) {
			return self::cursor_refusal();
		}
		$before = self::own( $cursor['package'], null );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( $purpose !== $before['manifest']['purpose'] ) {
			return self::cursor_refusal();
		}
		$from = array(
			'kind'  => $cursor['kind'],
			'inner' => $cursor['inner'],
		);
		// The last package of a series has no next cursor: null encodes as no cursor does.
		if ( AB_MCP_Sync_Canon::encode( $before['cursor'] ) !== AB_MCP_Sync_Canon::encode( $from ) ) {
			return self::cursor_refusal();
		}
		return array( $before['cursor'], $before['series'], $before['index'] );
	}

	/**
	 * @return WP_Error The cursor is not one an answer gave.
	 */
	private static function cursor_refusal() {
		return new WP_Error( 'ab_mcp_sync_cursor_invalid', __( 'The cursor is not one an answer of wp_site_export gave. Pass the cursor of the answer before as it is, or none to begin with the first package.', 'alphabridge-mcp' ) );
	}

	/**
	 * The line of an object in a package of the inventory, from a list of
	 * what it may carry (contract A): its identity, status and locks with
	 * their reasons, for a row the takeover never reads the code of its
	 * reason (reason_code, AB_MCP_Sync_Option_Schema::REASON_CODES; never a
	 * text) and the row WordPress reads instead (target), twin where another
	 * object of this site has its identity (how many have it,
	 * AB_MCP_Sync_Identity::twins()),
	 * and unless a
	 * credential holds it (a name of a
	 * credential, B9, a field of one in it, or any lock of the credential
	 * check, CREDENTIAL_LOCKS), its hash and the hashes of its mapped forms
	 * (AB_MCP_Sync_Inventory::entry()), size, whether it was read whole,
	 * what was not read, its references by id, the places of possible
	 * references and of the units left out as credentials; for the report
	 * (branch 6, part 2) the status of a post (post_status), how many meta
	 * rows its form holds (meta) and the instances of a widget area by their
	 * ids (instances). Of its content
	 * nothing: a possible reference only by its place, never its value;
	 * what the inventory adds later is not in it until it stands on this
	 * list.
	 *
	 * @param array $object An object of the inventory (AB_MCP_Sync_Inventory::entry()).
	 * @return array
	 */
	public static function line( array $object ) {
		// Without a status of the inventory: as locked, and held below (fail
		// closed; branch 9). entry() always gives one.
		$status = isset( $object['status'] ) && is_string( $object['status'] ) ? $object['status'] : null;
		$line   = array(
			'kind'     => (string) $object['kind'],
			'ref'      => (string) $object['ref'],
			'identity' => $object['identity'],
			'status'   => null === $status ? 'locked' : $status,
			'locks'    => self::fields( $object['locks'], array( 'at', 'key', 'reason', 'stage' ) ),
		) + array_intersect_key( $object, array_flip( array( 'reason_code', 'target' ) ) );
		if ( ! empty( $object['twin'] ) ) {
			$line['twin'] = (int) $object['twin'];
		}
		$credentials = $object['credentials'];
		if ( null === $status || 'b9' === $status || array() !== $credentials['mixed'] || self::credential_locked( $object['locks'] ) ) {
			return $line;
		}
		return $line + array(
			'canon'           => $object['canon'],
			'hash'            => $object['hash'],
			'mapped'          => isset( $object['mapped'] ) ? $object['mapped'] : null,
			'mapped_possible' => isset( $object['mapped_possible'] ) ? $object['mapped_possible'] : null,
			'size'            => $object['size'],
			'complete'        => $object['complete'],
			'unread'          => isset( $object['unread'] ) ? array_values( array_map( 'strval', $object['unread'] ) ) : array(),
			'refs'            => self::fields( $object['refs'], array( 'at', 'id', 'login', 'space', 'strength' ) ),
			'possible'        => self::fields( $object['possible'], array( 'at' ) ),
			'b9'              => array_values( array_map( 'strval', $credentials['b9'] ) ),
		) + array_intersect_key( $object, array_flip( array( 'instances', 'meta', 'post_status' ) ) );
	}

	/**
	 * Whether a lock of the credential check is among the locks of an
	 * object (by the operation of its key, kind:operation).
	 *
	 * @param array $locks Locks.
	 * @return bool
	 */
	public static function credential_locked( array $locks ) {
		foreach ( $locks as $lock ) {
			$key = isset( $lock['key'] ) ? (string) $lock['key'] : '';
			$op  = false === strpos( $key, ':' ) ? $key : (string) substr( $key, strpos( $key, ':' ) + 1 );
			if ( in_array( $op, self::CREDENTIAL_LOCKS, true ) ) {
				return true;
			}
		}
		return false;
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
				return new WP_Error( 'ab_mcp_sync_export_object', __( 'An object of this site cannot be written into a package (it is larger than a chunk holds, or not encodable). Ask the person to check the object named in the inventory, or leave its kind out for now.', 'alphabridge-mcp' ), array( 'kind' => isset( $object['kind'] ) ? $object['kind'] : null, 'ref' => isset( $object['ref'] ) ? $object['ref'] : null ) );
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
				return new WP_Error( 'ab_mcp_sync_export_gzip', __( 'The package could not be compressed. Ask the host to enable the zlib extension of PHP, then call again.', 'alphabridge-mcp' ) );
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
			/* translators: %d: the most objects one package holds. */
			'objects'  => __( 'The objects read are more than one package holds: at most %d objects. Ask the person to check the largest objects of the inventory, then call again with the same cursor.', 'alphabridge-mcp' ),
			/* translators: %d: the most chunks one package holds. */
			'chunks'   => __( 'The objects read are more than one package holds: at most %d chunks. Ask the person to check the largest objects of the inventory, then call again with the same cursor.', 'alphabridge-mcp' ),
			/* translators: %d: the most bytes the chunks of one package hold. */
			'bytes'    => __( 'The objects read are more than one package holds: at most %d bytes in its chunks. Ask the person to check the largest objects of the inventory, then call again with the same cursor.', 'alphabridge-mcp' ),
			/* translators: %d: the most bytes one package holds once unpacked. */
			'unpacked' => __( 'The objects read are more than one package holds: at most %d bytes once unpacked. Ask the person to check the largest objects of the inventory, then call again with the same cursor.', 'alphabridge-mcp' ),
		);
		return new WP_Error(
			'ab_mcp_sync_export_too_large',
			sprintf( $say[ $what ], $limit ),
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
	 * with points past them, or there is none), and where no object of that
	 * kind and subtype was withheld (sort()).
	 *
	 * @param array[]           $objects    Objects read for the package.
	 * @param array|null        $from       The cursor the package began with.
	 * @param array|null        $to         The cursor it ended with.
	 * @param array<string,true> $incomplete kind NUL subtype of the objects withheld.
	 * @return array[]|WP_Error kind, subtype, complete.
	 */
	public static function kinds( array $objects, $from, $to, array $incomplete = array() ) {
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
			$subtype = self::subtype( $object );
			$at      = $of[ $object['kind'] ];
			$key     = $object['kind'] . "\0" . $subtype;
			$out[ $key ] = array(
				'complete' => $at >= $start && $at < $end && ! isset( $incomplete[ $key ] ),
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
		return self::answer( $found['manifest'], $found['signed'], $found['cursor'], $found['report'] );
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
			/* translators: 1: the chunk that was asked for, 2: number of the last chunk. */
			return new WP_Error( 'ab_mcp_sync_chunk_index', sprintf( __( 'The package has no chunk %1$s: its manifest lists the chunks 0 to %2$d. Ask for a chunk of that list.', 'alphabridge-mcp' ), is_scalar( $chunk ) ? (string) $chunk : '?', count( $found['manifest']['chunks'] ) - 1 ) );
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
		return new WP_Error( 'ab_mcp_sync_relay_http', __( 'This site has a public address and the request did not come over https, so no chunk of a package passes through it: the content of the site would cross the network unencrypted. Connect the client to the https:// address of this site, then call again.', 'alphabridge-mcp' ) );
	}

	/* -------------------------------------------------------------- route */

	/**
	 * The bytes a request of the route asks for, or null: the request is
	 * checked by AB_MCP_Sync_Grant::grant() first, then deliver().
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $now     Time.
	 * @return string|null
	 */
	public static function requested( WP_REST_Request $request, $now ) {
		$grant = AB_MCP_Sync_Grant::grant( $request, $now );
		return null === $grant ? null : self::deliver( $grant, $now );
	}

	/**
	 * The chunk a request signed by this site asks for (AB_MCP_Sync_Grant::
	 * grant() checked it), or null for a package this site does not keep,
	 * that is not bound as this site is now or has expired, and for a chunk
	 * it does not have; read against the hash of the chunk in the manifest.
	 *
	 * @param array{package:string,chunk:int} $grant What the request is signed for.
	 * @param int                             $now   Time.
	 * @return string|null
	 */
	public static function deliver( array $grant, $now ) {
		// With write access off, a staging site gives nothing out.
		if ( ! AB_MCP_Site_Mode::is_full() ) {
			return null;
		}
		$found = self::find( $grant['package'] );
		if ( ! is_array( $found ) || ! self::bound( $found ) || $found['manifest']['expires'] <= $now || ! isset( $found['manifest']['chunks'][ $grant['chunk'] ] ) ) {
			return null;
		}
		$data = AB_MCP_Protected_Store::get( 'out', $grant['package'], self::chunk_name( $grant['chunk'] ), $found['manifest']['chunks'][ $grant['chunk'] ]['sha256'] );
		return is_string( $data ) ? $data : null;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Why this site makes or gives out no package: a network, an account
	 * without manage_options, write access off (decision 9), no confirmed
	 * staging site with a pair, no usable key; the secret of the MACs of a
	 * key and the value of bind() are made where missing, and refused only
	 * where they cannot be stored.
	 *
	 * @return WP_Error|null
	 */
	private static function refusal() {
		if ( is_multisite() ) {
			return self::multisite();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ab_mcp_sync_export_capability', __( 'This account may not make or read packages of this staging site: that takes manage_options. 1. Connect with an administrator account of this site, or ask an administrator to give this account that capability. 2. Call again.', 'alphabridge-mcp' ) );
		}
		if ( ! AB_MCP_Site_Mode::is_full() ) {
			/* translators: %s: how to switch write access on, a whole sentence. */
			return new WP_Error( 'ab_mcp_sync_export_mode', sprintf( __( 'Write access is off on this staging site, so it makes and gives out no package (a package is kept in its protected store). 1. Switch write access on: %s 2. Call again.', 'alphabridge-mcp' ), AB_MCP_Site_Mode::way_text() ) );
		}
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return new WP_Error( 'ab_mcp_sync_manifest_role', __( 'Only the confirmed staging site makes and gives out packages: confirm the role staging site on the page AlphaBridge → Staging of this site first.', 'alphabridge-mcp' ) );
		}
		if ( null === AB_MCP_Staging::record()['pair'] ) {
			return new WP_Error( 'ab_mcp_sync_manifest_pair', __( 'A package is made for the production site this staging site is paired with, and it has none: enter the address of the production site on the page AlphaBridge → Staging of this site first.', 'alphabridge-mcp' ) );
		}
		if ( null === AB_MCP_Sync_Key::current() ) {
			return new WP_Error( 'ab_mcp_sync_key_missing', __( 'This staging site has no usable key: make it on the page AlphaBridge → Staging under «Key of this staging site», confirm it on the production site, then call again.', 'alphabridge-mcp' ) );
		}
		// A key without the secret of its MACs gets one (a key stored before
		// it, or one whose secret went); the way where it cannot be stored.
		if ( ! AB_MCP_Sync_Key::mac_ready() ) {
			return AB_MCP_Sync_Key::mac_refusal();
		}
		// A record of the role and pair without the value a package binds to
		// gets one; nothing made before was bound to it.
		if ( null === AB_MCP_Staging::bind() && null === AB_MCP_Staging::new_bind() ) {
			return new WP_Error( 'ab_mcp_sync_export_unbound', __( 'No package was made: the value a package of this staging site is bound to could not be stored with the role and pair in the options of this site (the database refused the write, or PHP has no random bytes). Ask the host to check that the database accepts writes to the options of this site and that PHP has random bytes, then call again.', 'alphabridge-mcp' ) );
		}
		return null;
	}

	/**
	 * The refusal on a network, for export and import alike.
	 *
	 * @return WP_Error
	 */
	public static function multisite() {
		return new WP_Error( 'ab_mcp_sync_multisite', __( 'The takeover from a staging site works between two single sites, and this site is part of a multisite network (where it keeps no tables of the takeover). Use a staging site and a production site that are single installations, or move content on the network with wp_export_content.', 'alphabridge-mcp' ) );
	}

	/**
	 * Store a package: its chunks, the index of identities of the series
	 * where this call built it on (AB_MCP_Sync_Identity::stored(): kept in
	 * this package, which the next one takes it from; else where the package
	 * before kept it), then its signed manifest and the cursor in a record
	 * named by its own SHA-256, which find() reads back. Nothing of it stays
	 * where a step fails.
	 *
	 * @param string      $purpose Purpose.
	 * @param array       $packed  pack().
	 * @param array[]     $kinds   kinds().
	 * @param array|null  $cursor  The cursor of the next package.
	 * @param string      $series  The series (32 hex).
	 * @param array       $report  What was withheld and the credentials (sort()).
	 * @param array|null  $index   Where the package before kept the index (gid, parts).
	 * @param string|null $text    The index as this call left it; null where it stays.
	 * @param string|null $report_hash The report a package of objects is for (decision 5); null otherwise.
	 * @return array|WP_Error answer().
	 */
	private static function store( $purpose, array $packed, array $kinds, $cursor, $series, array $report, $index = null, $text = null, $report_hash = null ) {
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
				'declared' => $bytes + self::RECORD_MAX + ( null === $text ? 0 : strlen( $text ) ),
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
		if ( null !== $text ) {
			// In slices: a file of the store holds 16 MiB at most.
			$index = array(
				'gid'   => $gid,
				'parts' => array(),
			);
			for ( $at = 0, $n = 0; $at < strlen( $text ); $at += AB_MCP_Sync_Identity::slice(), $n++ ) {
				$slice = (string) substr( $text, $at, AB_MCP_Sync_Identity::slice() );
				$sha   = hash( 'sha256', $slice );
				$name  = sprintf( 'index-%03d-%s', $n, substr( $sha, 0, 32 ) );
				$put   = AB_MCP_Protected_Store::put( 'out', $gid, $name, $slice );
				if ( is_wp_error( $put ) ) {
					AB_MCP_Protected_Store::remove( 'out', $gid );
					return $put;
				}
				$index['parts'][] = array(
					'name' => $name,
					'sha'  => $sha,
				);
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
				'report_hash' => $report_hash,
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
				'index'     => $index,
				'manifest'  => $signed['manifest'],
				'report'    => $report,
				'series'    => $series,
				'signature' => $signed['signature'],
			)
		);
		$put    = AB_MCP_Protected_Store::put( 'out', $gid, hash( 'sha256', $record ), $record );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'out', $gid );
			return $put;
		}
		return self::answer( json_decode( $signed['manifest'], true ), $signed, $cursor, $report );
	}

	/**
	 * The answer for a package: what it is, its manifest, the cursor of the
	 * next package (where the inventory goes on, and this package, which the
	 * next one follows), and fresh fetch addresses.
	 *
	 * @param array      $manifest Manifest.
	 * @param array      $signed   manifest, signature.
	 * @param array|null $cursor   Cursor of the next package.
	 * @param array      $report   What was withheld and the credentials (sort()).
	 * @return array|WP_Error
	 */
	private static function answer( array $manifest, array $signed, $cursor, array $report ) {
		$grants = AB_MCP_Sync_Grant::grants( $manifest['package_id'], count( $manifest['chunks'] ) );
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
			'withheld'   => $report['withheld'] + ( $report['withheld']['count'] > 0 ? array( 'way' => self::withheld_way() ) : array() ),
			'credentials' => $report['credentials'],
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
			return new WP_Error( 'ab_mcp_sync_export_unknown', __( 'This site keeps no package of that id made by this account: packages are kept a day, and each account reads its own. Make a new one with wp_site_export.', 'alphabridge-mcp' ) );
		}
		if ( ! self::bound( $found ) ) {
			return new WP_Error( 'ab_mcp_sync_export_stale', __( 'The package was made for another pair, with another key of this staging site or by an older version of this plugin: the pair or the role was confirmed again since, or the plugin was updated, so it was discarded. Make a new one with wp_site_export.', 'alphabridge-mcp' ) );
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
		$bind   = AB_MCP_Staging::bind();
		if ( null === $key || null === $record['pair'] || null === $bind ) {
			return null;
		}
		$role = $record['role'];
		$pair = $record['pair'];
		return hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'bind'   => $bind,
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
		// A record of an older version, without its report, is none either.
		if ( null !== $found['report'] && $found['binding'] === ( null === $binding ? self::binding() : $binding ) ) {
			return true;
		}
		AB_MCP_Protected_Store::remove( 'out', $found['manifest']['package_id'] );
		return false;
	}

	/**
	 * The way an answer names for withheld objects, in the language of the
	 * site (Codex round 2 of branch 13, B1).
	 *
	 * @return string
	 */
	private static function withheld_way() {
		return __( 'These objects are not in the package, because the account that made it may not read them (needs: the capability it lacks; gone: the object is no longer there), and their kinds and subtypes are not complete in it. To include them: 1. Make the package with an account that has the capability named, or give this account that capability. 2. Call wp_site_export again.', 'alphabridge-mcp' );
	}

	/**
	 * Discard every package of this site that is not bound as it is now
	 * (another pair, another key), and every group of a making that ended
	 * before its record, ABANDONED seconds after it began: before a new one
	 * is made, so they hold no place in the budgets.
	 */
	private static function discard_stale() {
		$groups  = AB_MCP_Protected_Store::groups( 'out' );
		$binding = self::binding();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$found = self::find( $group['gid'] );
			if ( is_array( $found ) ) {
				self::bound( $found, $binding );
			} elseif ( null === $found && $group['created'] < time() - self::ABANDONED ) {
				// A making that ended between its chunks and its record (the
				// process stopped): never given out, and no longer counted.
				AB_MCP_Protected_Store::remove( 'out', $group['gid'] );
			}
		}
	}

	/**
	 * A package of this site: the entry of its group and its record, read
	 * against the name it is stored under (its own SHA-256); null where there
	 * is none, or none whole.
	 *
	 * @param mixed $package_id Package.
	 * @return array|WP_Error|null user_id, signed, manifest, cursor, series, index, binding.
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
			'index'    => self::index_shaped( $record['index'] ?? null ),
			'binding'  => $record['binding'],
			'report'   => self::report_shaped( isset( $record['report'] ) ? $record['report'] : null ),
		);
	}

	/**
	 * Where a record keeps the index of its series, as store() writes it:
	 * a group and its slices, each by name and SHA-256; null for none.
	 *
	 * @param mixed $index Index.
	 * @return array{gid:string,parts:array[]}|null
	 */
	private static function index_shaped( $index ) {
		if ( ! is_array( $index ) || array( 'gid', 'parts' ) !== self::sorted_keys( $index ) || ! is_string( $index['gid'] ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $index['gid'] ) || ! is_array( $index['parts'] ) || array() === $index['parts'] || ! array_is_list( $index['parts'] ) ) {
			return null;
		}
		foreach ( $index['parts'] as $n => $part ) {
			if ( ! is_array( $part ) || array( 'name', 'sha' ) !== self::sorted_keys( $part ) || ! is_string( $part['sha'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $part['sha'] ) || sprintf( 'index-%03d-%s', $n, substr( $part['sha'], 0, 32 ) ) !== $part['name'] ) {
				return null;
			}
		}
		return $index;
	}

	/**
	 * The report of a record as sort() writes it (withheld: count and the
	 * objects named; credentials: held, names, units), or null.
	 *
	 * @param mixed $report Report.
	 * @return array|null
	 */
	private static function report_shaped( $report ) {
		if ( ! is_array( $report ) || array( 'credentials', 'withheld' ) !== self::sorted_keys( $report ) || ! is_array( $report['withheld'] ) || ! is_array( $report['credentials'] ) ) {
			return null;
		}
		if ( array( 'count', 'objects' ) !== self::sorted_keys( $report['withheld'] ) || ! is_int( $report['withheld']['count'] ) || ! is_array( $report['withheld']['objects'] ) ) {
			return null;
		}
		if ( array( 'held', 'names', 'units' ) !== self::sorted_keys( $report['credentials'] ) || array( true, true, true ) !== array_values( array_map( 'is_int', $report['credentials'] ) ) ) {
			return null;
		}
		return $report;
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
