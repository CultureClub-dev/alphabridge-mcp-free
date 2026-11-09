<?php
/**
 * The proposal of object pairs (Z8, decisions 10, 13 and 14 of the plan of
 * 01.11.2026; branch 6, part 1): which object of the staging site belongs to
 * which object of this production site, where the staging site is no clone
 * of it (concept «Echtes Staging», section 4: slug, path, taxonomy, theme and
 * hash serve as a proposal only, and a person confirms it).
 *
 * THE TWO SIDES. The staging side is the inventory the staging site packed
 * (wp_site_export) and this site took over completely (wp_site_import_chunk):
 * one package or a series, read with AB_MCP_Sync_Import::taken(), which
 * checks each manifest again. The production side is this site's own
 * inventory (AB_MCP_Sync_Inventory), read with the same two addresses as
 * neutral as the staging site read its own, as far as the budget of one call
 * reaches (AB_MCP_Sync_Inventory::BUDGET less RESERVE, at most LIMIT
 * objects); the next call takes it up where it stopped. An object whose name
 * is the name of a credential (B9) is counted, never listed; an object a
 * credential holds by a name of its line (AB_MCP_Sync_Import::held_reason(),
 * the import's list for the staging side) is reduced to its kind, the
 * SHA-256 of its ref and its code before it is stored or answered with,
 * never named, and never proposed as a pair.
 *
 * A SERIES. The packages of one inventory together: an object twice, or a
 * kind of the database read whole in one package while another package holds
 * objects of it, is refused (packages of two series). A kind and subtype of
 * the staging side is complete only where one package read its kind of the
 * database from its start to its end (AB_MCP_Sync_Export::kinds()) and
 * withheld nothing of it; a subtype that package names nowhere had no object.
 * A kind read across two packages is complete in neither, so it is not
 * complete here: a production object without a counterpart is then «not
 * compared», never «only live» (unvollständig → nie «nur live»;
 * GRENZEN-BERICHT.md).
 *
 * THE MATCH (match(), no database): objects of both sides are grouped by kind
 * and identity (identity_key(); a menu item by its menus, the identity of
 * its target and of its parent item, never by its position), then by the
 * path of parents (posts; each parent with its type, theme, slug and
 * language, Codex round 4). Equality is that of the mapped forms (decision
 * 10: every reference names its target by identity, AB_MCP_Sync_Identity;
 * either the form with the typed references so or the one with the possible
 * references so as well, mapped_same(), Codex round 2, B-1), so the same
 * objects with other ids on the two sites are equal; a reference to the
 * object of a pair is the same only where the other side names the other
 * object of that pair (refs_agree(), the rule of the report as well; Codex
 * round 2 of part 2, b; a target without a pair stays with the mapped form,
 * whether its line is at hand or not, round 3 of part 2): else other content
 * (differs), or, where the reference names the object (the parent of a post,
 * the target, parent item or menus of a menu item, NAMED_BY), uncertain. No
 * pair by exclusion:
 * one object a side of the same identity and path is a pair. An identity
 * that is there more than once on a site (its line says twin with how many
 * its site has, or a side holds it twice on a path: Codex round 4) pairs on
 * that path only by an equal mapped form there once on each side, where
 * every object of it of both sites is at hand (Codex round 5: a published
 * page and a draft of its slug); the rest is uncertain, also where a package
 * holds only one of them or another one was paired by a choice; so are the
 * same identity left on another path, a post without a slug or with a path
 * of parents not known whole ("?", Codex round 2, A-2), a menu item whose
 * target or parent has no identity of its own, and menu items of one
 * identity in one menu (only their place tells them apart, Codex round 3,
 * A2). A post or term carries
 * its language where its site names one (Codex round 3, B2). Categories
 * (CATEGORIES): equal (both read whole,
 * the same mapped form: confirmed as active), differs (the same identity,
 * other content, or a reference without a target: confirmed as unclear),
 * uncertain (only a person's choice pairs them), realign (a
 * pair in the state unclear or residual, both objects there: confirmed with
 * the bases of both sides now, the only way out of these states, decision
 * 14), staging_only and live_only (no counterpart; live_only only where the
 * staging side is complete), not_compared (a side without a hash, a row not
 * read, a ref no pair can hold, the staging side not complete) and held
 * (reduced to the SHA-256 of the ref and the code before it is stored or
 * shown). An object of a pair is not proposed again; the bases of a pair
 * stay the hashes of each side in its own ID space (decision 10).
 *
 * THE CANON (decision 10). An active pair whose bases are of another version
 * of the canonical form is worked out again in this one
 * (AB_MCP_Sync_Pairs::change_canon()) where both inventories hold its
 * objects: the live hash equal to the target (the staging hash, where no
 * override changes it and the staging object names no post or term by its
 * id, so that mapping it changes nothing) gives new bases; anything else
 * makes it unclear. A pair whose object an inventory does not hold waits as
 * it is (waiting).
 *
 * WHERE IT LIES. A group of the area reports of the protected store
 * (AB_MCP_Protected_Store, kept a day), made by the account that proposes,
 * one per connection (a new proposal removes the earlier ones of its
 * connection):
 * its head (packages, the print of the pair, the connection, the source, the
 * canon, until when), the parts of the production inventory with the index
 * of the identities of this site as far as it is built (AB_MCP_Sync_Identity,
 * once for the proposal, taken up by the next part) and, once done, the
 * entries in parts with an index. Each file is written once and named by
 * its SHA-256 (base32), read only against it. The proposal id is the id of
 * the group; it holds for that account and connection, while the print of the
 * pair and the packages hold, until the earliest package expires.
 *
 * Refusals and errors are gettext texts in the language of the site, each
 * naming the way; codes, states, kinds and parameters stay as the tools
 * name them. The words of the report (reasons of a lock, the why of a
 * decision, the words of the environment) stay English: they are compared
 * across the two sites and go into the hashes of the report and the plan.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-staging-gate.php';
require_once __DIR__ . '/class-sync-import.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-option-schema.php';
require_once __DIR__ . '/class-sync-said.php';

/**
 * Class AB_MCP_Sync_Proposal
 */
final class AB_MCP_Sync_Proposal {

	/** The format of the files of a proposal. */
	const FORMAT = 'ab-sync-proposal/1';

	/** The label of its group in the area reports. */
	const LABEL = 'proposal';

	/** The categories of an entry, in the order of the entries. */
	const CATEGORIES = array( 'equal', 'differs', 'uncertain', 'realign', 'staging_only', 'live_only', 'not_compared', 'held' );

	/** The categories a person confirms. */
	const CONFIRMABLE = array( 'equal', 'differs', 'uncertain', 'realign' );

	/** The categories a person confirms only by a choice of the pair. */
	const BY_CHOICE = array( 'uncertain' );

	/** Entries of one answer at most (the first 200, then a cursor). */
	const PAGE = 200;

	/** Entries of one stored part. */
	const PART = 1000;

	/** Seconds of the budget of a call kept for working out the proposal. */
	const RESERVE = 15;

	/** Objects of the production inventory one call reads at most (AB_MCP_Sync_Export::OBJECTS). */
	const LIMIT = 4000;

	/** Packages of one proposal at most. */
	const MAX_PACKAGES = 100;

	/** Bytes of one file of lines at most. */
	const SLICE = 8388608;

	/** Parents of a post followed at most for its path. */
	const MAX_PATH = 64;

	/** The pair kinds whose objects are in the ID space of posts, and of terms. */
	const SPACES = array(
		'post' => array( 'post', 'menu_item', 'attachment' ),
		'term' => array( 'term', 'menu' ),
	);

	/**
	 * The places of the references that name an object, by its kind (its
	 * identity and path, identity_key()): the parent of a post; the target,
	 * the parent item and the menus of a menu item. Where such a reference
	 * names the object of a pair on one side and another object on the
	 * other, the two objects are uncertain, not of other content
	 * (pair_entry(); Codex round 2 of part 2, b).
	 */
	const NAMED_BY = array(
		'post'      => array( 'post_parent' ),
		'menu_item' => array( 'meta:_menu_item_object_id', 'meta:_menu_item_menu_item_parent', 'menu' ),
	);

	/** Objects a call reads at most; LIMIT when null. Set by tests only. */
	public static $limit = null;

	/** The clock of the expiry of a proposal: a callable returning seconds, null for time(). Set by tests only. */
	public static $clock = null;

	/* ---------------------------------------------------------- the tool */

	/**
	 * wp_site_pairs action propose: begin a proposal from the packages, go on
	 * with one, or give a page of one that is done.
	 *
	 * @param array $a packages (to begin) or proposal_id (to go on or page); after.
	 * @return array|WP_Error
	 */
	public static function propose( array $a ) {
		$source = self::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( isset( $a['proposal_id'] ) ) {
			$found = self::find( $a['proposal_id'], $source );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( null !== $found['index'] ) {
				return self::page( $found, isset( $a['after'] ) ? $a['after'] : 0 );
			}
			return self::step( $found );
		}
		$packages = isset( $a['packages'] ) ? $a['packages'] : null;
		if ( ! is_array( $packages ) || ! array_is_list( $packages ) || array() === $packages || count( $packages ) > self::MAX_PACKAGES || count( array_unique( array_map( 'strval', $packages ) ) ) !== count( $packages ) ) {
			/* translators: %d: the most packages a proposal takes. */
			return new WP_Error( 'ab_mcp_sync_pairs_packages', sprintf( __( 'A proposal begins with packages: the package_ids of the inventory of the staging site this site took over completely with wp_site_import_chunk, each once, at most %d. Pass them, or proposal_id to go on with a proposal.', 'alphabridge-mcp' ), self::MAX_PACKAGES ) );
		}
		$staging = self::staging( $packages );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$gone = self::replace_own();
		if ( is_wp_error( $gone ) ) {
			return $gone;
		}
		$gid = AB_MCP_Protected_Store::open( 'reports', array( 'label' => self::LABEL ) );
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$head = array(
			'canon'      => AB_MCP_Sync_Canon::VERSION,
			'connection' => self::connection(),
			'created'    => self::now(),
			'expires'    => min( $staging['expires'], self::now() + AB_MCP_Protected_Store::KEEP['reports'] ),
			'format'     => self::FORMAT,
			'packages'   => array_values( array_map( 'strval', $packages ) ),
			'print'      => (string) AB_MCP_Sync_Anchor::pair_print(),
			'source_id'  => $source,
		);
		$put  = self::put( $gid, 'h', null, $head );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'reports', $gid );
			return $put;
		}
		return self::step(
			array(
				'gid'   => $gid,
				'head'  => $head,
				'parts' => array(),
				'index' => null,
			)
		);
	}

	/**
	 * A connection works on one proposal: a new one removes the earlier ones
	 * of this connection from the store (those of other connections stay), so
	 * proposals made one after the other do not pile up for their day.
	 *
	 * @return true|WP_Error
	 */
	private static function replace_own() {
		$groups = AB_MCP_Protected_Store::groups( 'reports' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$mine = self::connection();
		foreach ( $groups as $group ) {
			if ( self::LABEL !== $group['label'] ) {
				continue;
			}
			$files = AB_MCP_Protected_Store::files( 'reports', $group['gid'] );
			$head  = is_wp_error( $files ) ? null : self::read_one( $group['gid'], $files, 'h' );
			if ( is_array( $head ) && isset( $head['connection'] ) && hash_equals( (string) $head['connection'], $mine ) ) {
				AB_MCP_Protected_Store::remove( 'reports', $group['gid'] );
			}
		}
		return true;
	}

	/**
	 * A proposal of this account and connection, for this source and pair,
	 * not expired: its head, the parts of the production inventory and, once
	 * done, its index.
	 *
	 * @param mixed  $proposal_id Proposal (32 hex).
	 * @param string $source      The source of the confirmed key.
	 * @return array|WP_Error gid, head, parts, index (null while not done).
	 */
	public static function find( $proposal_id, $source ) {
		if ( ! is_string( $proposal_id ) || 1 !== preg_match( '/^[0-9a-f]{32}\z/', $proposal_id ) ) {
			return self::unknown();
		}
		$groups = AB_MCP_Protected_Store::groups( 'reports' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$group = null;
		foreach ( $groups as $entry ) {
			if ( $proposal_id === $entry['gid'] && self::LABEL === $entry['label'] ) {
				$group = $entry;
			}
		}
		if ( null === $group ) {
			return self::unknown();
		}
		$files = AB_MCP_Protected_Store::files( 'reports', $proposal_id );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$head = self::read_one( $proposal_id, $files, 'h' );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		if ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) ) {
			return self::unknown();
		}
		// The connection names the account as well: a token belongs to one,
		// and a call without one is its account (connection()).
		if ( ! hash_equals( (string) $head['connection'], self::connection() ) ) {
			return self::foreign();
		}
		if ( AB_MCP_Sync_Canon::VERSION !== $head['canon'] || $source !== $head['source_id'] || ! hash_equals( (string) $head['print'], (string) AB_MCP_Sync_Anchor::pair_print() ) ) {
			return new WP_Error( 'ab_mcp_sync_pairs_stale', __( 'The role of this site, its pair, the confirmed key of the staging site or the version of the canonical form changed since this proposal was made, so it holds no more. Propose again: call wp_site_pairs with action=propose and the packages of a fresh inventory of the staging site.', 'alphabridge-mcp' ) );
		}
		if ( self::now() >= (int) $head['expires'] ) {
			return new WP_Error( 'ab_mcp_sync_pairs_expired', __( 'This proposal expired: it holds as long as the packages it was made from (a day at most). Propose again: export the inventory on the staging site (wp_site_export), take it over here (wp_site_import_chunk), then call wp_site_pairs with action=propose.', 'alphabridge-mcp' ) );
		}
		$parts = self::parts( $proposal_id, $files );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$index = self::read_one( $proposal_id, $files, 'x' );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		return array(
			'gid'   => $proposal_id,
			'head'  => $head,
			'parts' => $parts,
			'index' => $index,
		);
	}

	/**
	 * The entries of a proposal that is done, by their numbers.
	 *
	 * @param array $found find(), done.
	 * @param int[] $numbers Numbers of entries.
	 * @return array<int,array>|WP_Error Entries by number; a number without an entry is left out.
	 */
	public static function entries( array $found, array $numbers ) {
		$want = array_flip( array_map( 'intval', $numbers ) );
		$out  = array();
		foreach ( $found['index']['parts'] as $part ) {
			$from = (int) $part['first'];
			$to   = $from + (int) $part['count'] - 1;
			$hit  = false;
			foreach ( array_keys( $want ) as $n ) {
				if ( $n >= $from && $n <= $to ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				continue;
			}
			$list = self::read_part( $found['gid'], $part );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $entry ) {
				if ( isset( $want[ $entry['n'] ] ) ) {
					$out[ $entry['n'] ] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * The entries of a proposal that is done, of these categories, after a
	 * number, at most $limit.
	 *
	 * @param array    $found      find(), done.
	 * @param string[] $categories Categories.
	 * @param int      $after      Number.
	 * @param int      $limit      At most.
	 * @return array{entries:array[],more:bool}|WP_Error
	 */
	public static function of_categories( array $found, array $categories, $after, $limit ) {
		$out = array();
		foreach ( $found['index']['parts'] as $part ) {
			if ( (int) $part['first'] + (int) $part['count'] - 1 <= $after ) {
				continue;
			}
			$list = self::read_part( $found['gid'], $part );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $entry ) {
				if ( $entry['n'] <= $after || ! in_array( $entry['category'], $categories, true ) ) {
					continue;
				}
				if ( count( $out ) >= $limit ) {
					return array(
						'entries' => $out,
						'more'    => true,
					);
				}
				$out[] = $entry;
			}
		}
		return array(
			'entries' => $out,
			'more'    => false,
		);
	}

	/**
	 * The source of the key a person confirmed on this site: the source of
	 * the pairs. Refused on a network and without a confirmed key.
	 *
	 * @return string|WP_Error
	 */
	public static function source_id() {
		if ( is_multisite() ) {
			return new WP_Error( 'ab_mcp_sync_multisite', __( 'Object pairs are not available on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the production site.', 'alphabridge-mcp' ) );
		}
		$anchor = AB_MCP_Sync_Anchor::active();
		if ( null === $anchor ) {
			return new WP_Error( 'ab_mcp_sync_pairs_no_anchor', __( 'This site has no confirmed key of a staging site, so it has no object pairs with one. Ask the person to fetch the key of the staging site, or enter its pairing code, and confirm it on the page AlphaBridge → Staging of this site; then call again.', 'alphabridge-mcp' ) );
		}
		return (string) $anchor['source_id'];
	}

	/**
	 * The connection of the call, as a proposal and a confirmation are bound
	 * to it (decision 15): a hash of AB_MCP_Staging_Gate::connection().
	 *
	 * @return string
	 */
	public static function connection() {
		$conn = AB_MCP_Staging_Gate::connection();
		return hash( 'sha256', 'ab-sync-pairs|' . $conn['kind'] . ':' . $conn['id'] );
	}

	/* --------------------------------------------------- the staging side */

	/**
	 * The inventory of the staging site from packages of one series: their
	 * lines (each object once, a held one with its code), whether each kind
	 * and subtype is complete, and until when they hold.
	 *
	 * @param string[] $packages Package ids.
	 * @return array|WP_Error lines, complete (kind NUL subtype => true), whole
	 *                        (db kind => true), objects, held, expires.
	 */
	public static function staging( array $packages ) {
		$taken = array();
		foreach ( $packages as $id ) {
			$one = AB_MCP_Sync_Import::taken( $id );
			if ( is_wp_error( $one ) ) {
				return $one;
			}
			$taken[] = $one;
		}
		return self::compose( $taken );
	}

	/**
	 * Packages of one series as one inventory (no database): see staging().
	 *
	 * @param array[] $taken AB_MCP_Sync_Import::taken() of each package.
	 * @return array|WP_Error
	 */
	public static function compose( array $taken ) {
		$db      = self::db_kinds();
		$whole   = array();
		$expires = PHP_INT_MAX;
		foreach ( $taken as $i => $one ) {
			$expires = min( $expires, (int) $one['manifest']['expires'] );
			foreach ( $one['manifest']['kinds'] as $entry ) {
				if ( ! $entry['complete'] || ! isset( $db[ $entry['kind'] ] ) ) {
					continue;
				}
				// A kind whole in two packages has objects in both: refused
				// below, as objects of a whole kind in another package.
				$whole[ $db[ $entry['kind'] ] ] = $i;
			}
		}
		$lines = array();
		$seen  = array();
		$held  = 0;
		foreach ( $taken as $i => $one ) {
			$codes = array();
			foreach ( $one['held'] as $entry ) {
				$codes[ $entry[0] . "\0" . $entry[1] ] = $entry[2];
			}
			foreach ( $one['lines'] as $line ) {
				$kind = (string) $line['kind'];
				$sha  = hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) );
				if ( isset( $seen[ $kind . "\0" . $sha ] ) ) {
					return self::mixed();
				}
				$seen[ $kind . "\0" . $sha ] = true;
				if ( isset( $db[ $kind ], $whole[ $db[ $kind ] ] ) && $whole[ $db[ $kind ] ] !== $i ) {
					return self::mixed();
				}
				if ( isset( $codes[ $kind . "\0" . $sha ] ) ) {
					// Reduced before it goes anywhere (Codex A3).
					$line = self::reduced( $line, $codes[ $kind . "\0" . $sha ] );
					++$held;
				}
				$lines[] = $line;
			}
		}
		$complete = array();
		foreach ( $whole as $of => $i ) {
			foreach ( $taken[ $i ]['manifest']['kinds'] as $entry ) {
				if ( isset( $db[ $entry['kind'] ] ) && $db[ $entry['kind'] ] === $of ) {
					$complete[ $entry['kind'] . "\0" . $entry['subtype'] ] = (bool) $entry['complete'];
				}
			}
		}
		return array(
			'lines'    => $lines,
			'complete' => $complete,
			'whole'    => array_fill_keys( array_keys( $whole ), true ),
			'objects'  => count( $lines ),
			'held'     => $held,
			'expires'  => PHP_INT_MAX === $expires ? time() : $expires,
		);
	}

	/**
	 * Whether a kind and subtype is complete on the staging side: its kind of
	 * the database read whole in one package, and that package withheld
	 * nothing of it (a subtype it names nowhere had no object).
	 *
	 * @param array  $staging compose().
	 * @param string $kind    Pair kind.
	 * @param string $subtype Subtype.
	 * @return bool
	 */
	public static function complete( array $staging, $kind, $subtype ) {
		$db = self::db_kinds();
		if ( ! isset( $db[ $kind ], $staging['whole'][ $db[ $kind ] ] ) ) {
			return false;
		}
		$key = $kind . "\0" . $subtype;
		return isset( $staging['complete'][ $key ] ) ? $staging['complete'][ $key ] : true;
	}

	/**
	 * Pair kind => the kind of the database whose inventory holds it.
	 *
	 * @return array<string,string>
	 */
	public static function db_kinds() {
		$out   = array();
		$kinds = AB_MCP_Sync_Db_Kinds::all();
		if ( is_wp_error( $kinds ) ) {
			return $out;
		}
		foreach ( $kinds as $kind ) {
			foreach ( $kind->pair_kinds() as $pair ) {
				$out[ $pair ] = $kind->kind();
			}
		}
		return $out;
	}

	/* ------------------------------------------------ the production side */

	/**
	 * One call of a proposal that is not done: the next part of the
	 * production inventory; once it is read to its end, the proposal.
	 *
	 * @param array $found find() (or a group just begun).
	 * @return array|WP_Error
	 */
	private static function step( array $found ) {
		$seq = count( $found['parts'] );
		if ( $seq > 0 ) {
			$cursor = $found['parts'][ $seq - 1 ]['cursor'];
			if ( null === $cursor ) {
				return self::finish( $found );
			}
		}
		$found = self::read_on( $found, self::FORMAT );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === end( $found['parts'] )['cursor'] ) {
			return self::finish( $found );
		}
		$read_so_far = array_sum( array_column( $found['parts'], 'objects' ) );
		return array(
			'proposal_id' => $found['gid'],
			'done'        => false,
			'live'        => array(
				'objects' => $read_so_far,
			),
			'expires'     => (int) $found['head']['expires'],
			/* translators: %d: objects read so far. */
			'next'        => sprintf( __( 'The inventory of this site is read as far as one call reaches (first the identities of its objects, then the objects: %d so far).', 'alphabridge-mcp' ), $read_so_far ) . ' Call wp_site_pairs again with action=propose and this proposal_id to go on.',
		);
	}

	/**
	 * The next part of the inventory of this site, for a group of the area
	 * reports whose parts it reads one call after the other: a proposal, or
	 * a report of wp_site_diff (AB_MCP_Sync_Report, branch 6, part 2), the
	 * same inventory and the same index of identities. The index as the part
	 * before kept it, then the inventory as far as one call reaches
	 * (AB_MCP_Sync_Inventory::BUDGET less RESERVE, at most LIMIT objects),
	 * stored as the next part: an object whose name is the name of a
	 * credential (B9) counted and kept by its kind and the SHA-256 of its ref
	 * only, a line a credential holds reduced (Codex A3).
	 *
	 * @param array         $found      gid, parts (and the rest of find()).
	 * @param string        $format     The format of the files of the group.
	 * @param WP_Error|null $concurrent The refusal where its files are not as
	 *                                  one call after the other wrote them;
	 *                                  that of a proposal when null.
	 * @return array|WP_Error $found with the part read.
	 */
	public static function read_on( array $found, $format, $concurrent = null ) {
		$cursor = null;
		$index  = null;
		$seq    = count( $found['parts'] );
		if ( $seq > 0 ) {
			$last   = $found['parts'][ $seq - 1 ];
			$cursor = $last['cursor'];
			$index  = $last['index'] ?? null;
		}
		// The index of identities of this site, as the part before kept it
		// (AB_MCP_Sync_Identity: built once for the group, in parts).
		$text = null;
		if ( is_array( $index ) ) {
			$text = '';
			foreach ( $index as $file ) {
				$slice = is_array( $file ) ? AB_MCP_Protected_Store::get( 'reports', $found['gid'], (string) ( $file['name'] ?? '' ), (string) ( $file['sha'] ?? '' ) ) : null;
				if ( ! is_string( $slice ) ) {
					return null === $concurrent ? self::concurrent() : $concurrent;
				}
				$text .= $slice;
			}
			unset( $slice );
		}
		$source = self::inventory_source( max( 1, AB_MCP_Sync_Inventory::BUDGET - self::RESERVE ), null === self::$limit ? self::LIMIT : (int) self::$limit, $text );
		unset( $text );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$read = AB_MCP_Sync_Inventory::run( $source, $cursor );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		if ( null !== $read['index'] ) {
			// In slices: a file of the store holds 16 MiB at most.
			$index = array();
			for ( $at = 0; $at < strlen( $read['index'] ); $at += AB_MCP_Sync_Identity::slice() ) {
				$slice = (string) substr( $read['index'], $at, AB_MCP_Sync_Identity::slice() );
				$sha   = hash( 'sha256', $slice );
				$put   = AB_MCP_Protected_Store::put( 'reports', $found['gid'], self::name( 'i', $seq + 1, $sha ), $slice );
				if ( is_wp_error( $put ) ) {
					return $put;
				}
				$index[] = array(
					'name' => self::name( 'i', $seq + 1, $sha ),
					'sha'  => $sha,
				);
			}
			unset( $read['index'], $slice );
		}
		// The objects carry what they need of the index; it goes before the part is stored.
		AB_MCP_Sync_Identity::reset();
		$lines   = array();
		$b9      = 0;
		$b9_refs = array();
		foreach ( $read['objects'] as $object ) {
			if ( 'b9' === $object['status'] ) {
				++$b9;
				// Kept by its kind and the SHA-256 of its ref only: so a
				// report tells an object of a pair whose name holds a
				// credential from one that is gone (never by its name).
				$b9_refs[] = array( (string) $object['kind'], hash( 'sha256', AB_MCP_Sync_Canon::encode( (string) $object['ref'] ) ) );
				continue;
			}
			$line = AB_MCP_Sync_Export::line( $object );
			$code = AB_MCP_Sync_Import::held_reason( $line );
			// A held line is reduced before it is stored (Codex A3).
			$lines[] = null === $code ? $line : self::reduced( $line, $code );
		}
		$part = self::put_part( $found['gid'], $seq + 1, $lines, $read['cursor'], $b9, $index, $b9_refs, $format );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		$found['parts'][] = $part;
		return $found;
	}

	/**
	 * The source of an inventory of this site, with its own address and the
	 * one of its staging site neutral, as the staging site reads its own
	 * (AB_MCP_Sync_Export::make()).
	 *
	 * @param int|float   $budget  Seconds.
	 * @param int|null    $objects Objects at most.
	 * @param string|null $index   The index of identities of the proposal so far.
	 * @return array|WP_Error
	 */
	private static function inventory_source( $budget = AB_MCP_Sync_Inventory::BUDGET, $objects = null, $index = null ) {
		$partner = AB_MCP_Sync_Anchor::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		return AB_MCP_Sync_Inventory::source( array( home_url(), $partner ), $budget, null, $objects, $index );
	}

	/**
	 * The objects of this site as they are now, read fresh (Codex B3: the
	 * token of a confirmation binds them): kind NUL ref => the SHA-256 of
	 * its canonical form, or 'gone'.
	 *
	 * @param array[] $objects kind, ref.
	 * @return array<string,string>|WP_Error
	 */
	public static function live_now( array $objects ) {
		$source = self::inventory_source();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$out = array();
		foreach ( $objects as $object ) {
			$kind = AB_MCP_Sync_Db_Kinds::for_pair( $object['kind'] );
			$read = null === $kind ? null : $kind->canonical(
				array(
					'kind'   => $object['kind'],
					'ref'    => $object['ref'],
					'source' => $source,
				)
			);
			$out[ $object['kind'] . "\0" . $object['ref'] ] = is_array( $read ) && isset( $read['hash'] ) ? (string) $read['hash'] : 'gone';
		}
		return $out;
	}

	/**
	 * Store one part of the production inventory: its lines in files of at
	 * most SLICE bytes, then the file that closes the part (its cursor, its
	 * count of credentials with the kind and SHA-256 of the ref of each, the
	 * names of its files and of the file of the index of identities so far).
	 * A part without that file is no part (a call that ended in between); two
	 * parts of one number are two calls that went on at once.
	 *
	 * @param string     $gid     Group.
	 * @param int        $seq     Number of the part, from 1.
	 * @param array[]    $lines   Lines.
	 * @param array|null $cursor  Cursor of the inventory after it.
	 * @param int        $b9      Objects left out as credentials.
	 * @param array|null $index   The slices of the index of identities (i), each name and sha; null for none.
	 * @param array[]    $b9_refs Of each of those objects its kind and the SHA-256 of its ref.
	 * @param string     $format  The format of the files of the group.
	 * @return array|WP_Error The part as parts() reads it.
	 */
	private static function put_part( $gid, $seq, array $lines, $cursor, $b9, $index = null, array $b9_refs = array(), $format = self::FORMAT ) {
		$files = array();
		$slice = array();
		$size  = 0;
		foreach ( $lines as $line ) {
			$text = AB_MCP_Sync_Canon::encode( $line );
			if ( array() !== $slice && $size + strlen( $text ) + 1 > self::SLICE ) {
				$put = self::put( $gid, 'd', $seq, $slice, $format );
				if ( is_wp_error( $put ) ) {
					return $put;
				}
				$files[] = $put;
				$slice   = array();
				$size    = 0;
			}
			$slice[] = $line;
			$size   += strlen( $text ) + 1;
		}
		if ( array() !== $slice ) {
			$put = self::put( $gid, 'd', $seq, $slice, $format );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$files[] = $put;
		}
		$close = array(
			'b9'      => (int) $b9,
			'b9_refs' => $b9_refs,
			'cursor'  => $cursor,
			'files'   => $files,
			'format'  => $format,
			'index'   => $index,
			'objects' => count( $lines ),
			'seq'     => (int) $seq,
		);
		$put   = self::put( $gid, 'c', $seq, $close, $format );
		return is_wp_error( $put ) ? $put : $close;
	}

	/**
	 * The parts of the production inventory, in order: each one closed, one
	 * per number, without a gap.
	 *
	 * @param string            $gid        Group.
	 * @param array<string,int> $files      Files of the group.
	 * @param string            $format     The format of the files of the group.
	 * @param WP_Error|null     $concurrent The refusal where they are not as
	 *                                      one call after the other wrote
	 *                                      them; that of a proposal when null.
	 * @return array[]|WP_Error
	 */
	public static function parts( $gid, array $files, $format = self::FORMAT, $concurrent = null ) {
		$refused = null === $concurrent ? self::concurrent() : $concurrent;
		$closes  = array();
		foreach ( array_keys( $files ) as $name ) {
			$parsed = self::parse_name( (string) $name );
			if ( null === $parsed || 'c' !== $parsed['prefix'] ) {
				continue;
			}
			if ( isset( $closes[ $parsed['seq'] ] ) ) {
				return $refused;
			}
			$closes[ $parsed['seq'] ] = $parsed;
		}
		ksort( $closes );
		$out = array();
		$at  = 1;
		foreach ( $closes as $seq => $parsed ) {
			if ( $seq !== $at ) {
				return $refused;
			}
			$text  = AB_MCP_Protected_Store::get( 'reports', $gid, $parsed['name'], $parsed['sha'] );
			$close = is_string( $text ) ? json_decode( $text, true, 64 ) : null;
			if ( ! is_array( $close ) || $format !== ( $close['format'] ?? null ) || $seq !== ( $close['seq'] ?? null ) ) {
				return $refused;
			}
			$out[] = $close;
			++$at;
		}
		return $out;
	}

	/**
	 * The lines of the production inventory of a group (a proposal, a
	 * report).
	 *
	 * @param array         $found      find().
	 * @param WP_Error|null $concurrent See parts().
	 * @return array|WP_Error lines, b9 (how many), b9_refs (kind NUL SHA-256 of the ref => true).
	 */
	public static function live( array $found, $concurrent = null ) {
		$lines = array();
		$b9    = 0;
		$refs  = array();
		foreach ( $found['parts'] as $part ) {
			$b9 += (int) $part['b9'];
			foreach ( isset( $part['b9_refs'] ) && is_array( $part['b9_refs'] ) ? $part['b9_refs'] : array() as $one ) {
				if ( is_array( $one ) && isset( $one[0], $one[1] ) ) {
					$refs[ $one[0] . "\0" . $one[1] ] = true;
				}
			}
			foreach ( $part['files'] as $file ) {
				$text = AB_MCP_Protected_Store::get( 'reports', $found['gid'], $file['name'], $file['sha'] );
				if ( is_wp_error( $text ) ) {
					return $text;
				}
				$list = json_decode( $text, true, 128 );
				if ( ! is_array( $list ) || ! is_array( $list['data'] ?? null ) ) {
					return null === $concurrent ? self::concurrent() : $concurrent;
				}
				foreach ( $list['data'] as $line ) {
					$lines[] = $line;
				}
			}
		}
		return array(
			'lines'   => $lines,
			'b9'      => $b9,
			'b9_refs' => $refs,
		);
	}

	/* ----------------------------------------------------- the proposal */

	/**
	 * Work out the proposal of a group whose production inventory is read
	 * to its end: the staging packages read again, the canon of the active
	 * pairs, the match, the entries stored.
	 *
	 * @param array $found find().
	 * @return array|WP_Error The first page.
	 */
	private static function finish( array $found ) {
		$head    = $found['head'];
		$staging = self::staging( $head['packages'] );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$live = self::live( $found );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$pairs = self::pairs( $head['source_id'] );
		if ( is_wp_error( $pairs ) ) {
			return $pairs;
		}
		$canon = self::canon( $pairs, $staging['lines'], $live['lines'] );
		if ( is_wp_error( $canon ) ) {
			return $canon;
		}
		if ( $canon['rebased'] + $canon['unclear'] > 0 ) {
			$pairs = self::pairs( $head['source_id'] );
			if ( is_wp_error( $pairs ) ) {
				return $pairs;
			}
		}
		$result = self::match(
			$staging['lines'],
			$live['lines'],
			$pairs,
			static function ( $kind, $subtype ) use ( $staging ) {
				return self::complete( $staging, $kind, $subtype );
			}
		);
		$parts  = array();
		foreach ( array_chunk( $result['entries'], self::PART ) as $i => $chunk ) {
			$put = self::put( $found['gid'], 'e', $i + 1, $chunk );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$parts[] = $put + array(
				'first' => (int) $chunk[0]['n'],
				'count' => count( $chunk ),
			);
		}
		$incomplete = array();
		foreach ( $result['subtypes'] as $key => $true ) {
			list( $kind, $subtype ) = explode( "\0", $key, 2 );
			if ( ! self::complete( $staging, $kind, $subtype ) ) {
				$incomplete[] = array(
					'kind'    => $kind,
					'subtype' => $subtype,
				);
			}
		}
		$index = array(
			'canon'   => $canon,
			'counts'  => $result['counts'],
			'format'  => self::FORMAT,
			'live'    => array(
				'objects'     => count( $live['lines'] ),
				'held'        => $result['held']['live'],
				'credentials' => $live['b9'],
			),
			'paired'  => $result['paired'],
			'parts'   => $parts,
			'staging' => array(
				'packages'   => count( $head['packages'] ),
				'objects'    => $staging['objects'],
				'held'       => $staging['held'],
				'incomplete' => $incomplete,
			),
			'total'   => count( $result['entries'] ),
		);
		$put   = self::put( $found['gid'], 'x', null, $index );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$found['index'] = $index;
		return self::page( $found, 0 );
	}

	/**
	 * A page of a proposal that is done.
	 *
	 * @param array $found find(), done.
	 * @param mixed $after The number of the last entry of the page before.
	 * @return array|WP_Error
	 */
	private static function page( array $found, $after ) {
		$after = is_numeric( $after ) ? max( 0, (int) $after ) : 0;
		$index = $found['index'];
		$page  = self::of_categories( $found, self::CATEGORIES, $after, self::PAGE );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$entries = array_map( array( __CLASS__, 'shown' ), $page['entries'] );
		$last    = array() === $page['entries'] ? null : (int) end( $page['entries'] )['n'];
		return array(
			'proposal_id' => $found['gid'],
			'done'        => true,
			'expires'     => (int) $found['head']['expires'],
			'counts'      => $index['counts'],
			'total'       => $index['total'],
			'paired'      => $index['paired'],
			'canon'       => $index['canon'],
			'staging'     => $index['staging'],
			'live'        => $index['live'],
			'entries'     => AB_MCP_Sync_Said::shown( $entries ),
			'next_after'  => $page['more'] ? $last : null,
			'next'        => __( 'Nothing is paired yet.', 'alphabridge-mcp' ) . ' Show the entries to the person. To pair, call wp_site_pairs with action=confirm, this proposal_id and the entries the person chose: select (entry numbers), categories (all equal, differs or realign entries, after the number in after) or choices (for an uncertain entry: the staging_ref and live_ref the person chose; these are never paired otherwise). equal is paired as active, differs and a chosen pair of other content as unclear (the report then asks which side wins), realign makes the states of both sides now the new bases.' . ( $page['more'] ? ' More entries: call again with action=propose, this proposal_id and after=' . $last . '.' : '' ),
		);
	}

	/**
	 * An entry as an answer shows it: without the hashes and the places of
	 * the references (what only the comparison uses).
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function shown( array $entry ) {
		foreach ( array( 'staging', 'live' ) as $side ) {
			if ( isset( $entry[ $side ] ) && is_array( $entry[ $side ] ) ) {
				unset( $entry[ $side ]['hash'], $entry[ $side ]['mapped'], $entry[ $side ]['mapped_possible'], $entry[ $side ]['places'] );
			}
		}
		if ( isset( $entry['candidates'] ) ) {
			foreach ( $entry['candidates'] as $side => $list ) {
				foreach ( $list as $i => $one ) {
					unset( $entry['candidates'][ $side ][ $i ]['hash'], $entry['candidates'][ $side ][ $i ]['mapped'], $entry['candidates'][ $side ][ $i ]['mapped_possible'], $entry['candidates'][ $side ][ $i ]['places'] );
				}
			}
		}
		if ( isset( $entry['pair'] ) ) {
			unset( $entry['pair']['snapshot'] );
		}
		return $entry;
	}

	/**
	 * The pairs of a source, all.
	 *
	 * @param string $source Source.
	 * @return array[]|WP_Error
	 */
	private static function pairs( $source ) {
		$out   = array();
		$after = 0;
		do {
			$page = AB_MCP_Sync_Pairs::page_source( $source, $after );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['pairs'] as $pair ) {
				$out[] = $pair;
			}
			$after = $page['next'];
		} while ( null !== $after );
		return $out;
	}

	/**
	 * The canon change of the active pairs whose bases are of another version
	 * (decision 10).
	 *
	 * @param array[] $pairs   Pairs.
	 * @param array[] $staging Lines of the staging side.
	 * @param array[] $live    Lines of the production side.
	 * @return array|WP_Error rebased, unclear.
	 */
	private static function canon( array $pairs, array $staging, array $live ) {
		$out = array(
			'rebased' => 0,
			'unclear' => 0,
			'waiting' => 0,
		);
		$s   = self::by_ref( $staging );
		$l   = self::by_ref( $live );
		foreach ( $pairs as $pair ) {
			if ( 'active' !== $pair['state'] || AB_MCP_Sync_Canon::VERSION === $pair['canon'] ) {
				continue;
			}
			$sl = $s[ $pair['kind'] . "\0" . $pair['staging_ref'] ] ?? null;
			$ll = $l[ $pair['kind'] . "\0" . $pair['live_ref'] ] ?? null;
			// Worked out again only with both objects at hand: one the
			// inventories do not hold is no difference (a series not taken
			// over whole, an object gone), and the pair waits as it is.
			if ( null === $sl || null === $ll ) {
				++$out['waiting'];
				continue;
			}
			$forms  = self::canon_forms( $pair, $sl, $ll );
			$result = AB_MCP_Sync_Pairs::change_canon(
				$pair['id'],
				AB_MCP_Sync_Canon::VERSION,
				static function () use ( $forms ) {
					return $forms;
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( isset( $out[ $result ] ) ) {
				++$out[ $result ];
			}
		}
		return $out;
	}

	/**
	 * The forms of a pair in the version of now, from the lines of both
	 * sides: live and staging are the hashes of each side; the target equals
	 * the live hash where the mapped forms are the same (decision 10) and no
	 * override changes the target, else it is none (the pair becomes
	 * unclear).
	 *
	 * @param array      $pair    Pair.
	 * @param array|null $staging Its staging line.
	 * @param array|null $live    Its production line.
	 * @return array live, staging, target (SHA-256 or null).
	 */
	public static function canon_forms( array $pair, $staging, $live ) {
		$usable = static function ( $line ) {
			return is_array( $line ) && isset( $line['hash'] ) && is_string( $line['hash'] ) && ! empty( $line['complete'] ) && ! isset( $line['held'] );
		};
		$s      = $usable( $staging ) ? $staging['hash'] : null;
		$l      = $usable( $live ) ? $live['hash'] : null;
		$same   = null !== $s && null !== $l && self::mapped_same( $staging, $live );
		return array(
			'live'    => $l,
			'staging' => $s,
			'target'  => null === $pair['override'] && $same ? $l : null,
		);
	}

	/* ------------------------------------------------------------ match */

	/**
	 * A line a credential holds, reduced to what may be kept and shown: its
	 * kind, the SHA-256 of its ref (in the spelling of AB_MCP_Sync_Canon::
	 * encode()) and the code of why. Every held line is reduced before it is
	 * stored or answered with (Codex A3).
	 *
	 * @param array  $line Line.
	 * @param string $code Code (AB_MCP_Sync_Import::HELD_CODES).
	 * @return array
	 */
	public static function reduced( array $line, $code ) {
		return array(
			'kind'       => (string) $line['kind'],
			'ref_sha256' => hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) ),
			'held'       => (string) $code,
		);
	}

	/**
	 * The proposal from the lines of both sides (no database).
	 *
	 * @param array[]  $staging  Lines of the staging side (a held one reduced(), or with 'held').
	 * @param array[]  $live     Lines of the production side (likewise).
	 * @param array[]  $pairs    The pairs of the source (AB_MCP_Sync_Pairs::decode()).
	 * @param callable $complete function( kind, subtype ): bool, whether the staging side is complete.
	 * @return array entries (numbered), counts, paired, held (staging, live), subtypes.
	 */
	public static function match( array $staging, array $live, array $pairs, callable $complete ) {
		$paired = self::paired( $pairs );
		$sides  = array(
			's' => self::items( $staging, 's', $paired['s'] ),
			'l' => self::items( $live, 'l', $paired['l'] ),
		);
		$by    = array(
			's' => array(),
			'l' => array(),
		);
		foreach ( $sides as $side => $items ) {
			foreach ( $items as $i => $item ) {
				$by[ $side ][ $item['kind'] . "\0" . $item['ref_key'] ] = $i;
			}
		}
		$taken   = array(
			's' => array(),
			'l' => array(),
		);
		$entries = array();
		$paired  = 0;
		foreach ( $pairs as $pair ) {
			$si = self::lookup( $by['s'], $pair['kind'], $pair['staging_ref'] );
			$li = self::lookup( $by['l'], $pair['kind'], $pair['live_ref'] );
			if ( null !== $si ) {
				$taken['s'][ $si ] = true;
			}
			if ( null !== $li ) {
				$taken['l'][ $li ] = true;
			}
			if ( ! in_array( $pair['state'], array( 'unclear', 'residual' ), true ) ) {
				++$paired;
				continue;
			}
			$entries[] = self::realign_entry( $pair, null === $si ? null : $sides['s'][ $si ], null === $li ? null : $sides['l'][ $li ] );
		}
		$groups = array();
		foreach ( $sides as $side => $items ) {
			foreach ( $items as $i => $item ) {
				if ( isset( $taken[ $side ][ $i ] ) ) {
					continue;
				}
				if ( null !== $item['held'] ) {
					$entries[] = self::held_entry( $item );
					continue;
				}
				if ( null === $item['key'] ) {
					$entries[] = self::alone( $item, 'not_compared', self::why_unread( $item ) );
					continue;
				}
				if ( ! AB_MCP_Sync_Pairs::valid_ref( $item['ref'] ) ) {
					$entries[] = self::alone( $item, 'not_compared', 'Its name cannot be held by a pair (longer than 191 characters, a control character, or not UTF-8), so it is not paired. Take it over by hand.' );
					continue;
				}
				$groups[ $item['key'] ][ $side ][] = $item;
			}
		}
		ksort( $groups, SORT_STRING );
		foreach ( $groups as $group ) {
			foreach ( self::resolve( isset( $group['s'] ) ? $group['s'] : array(), isset( $group['l'] ) ? $group['l'] : array(), $complete ) as $entry ) {
				$entries[] = $entry;
			}
		}
		$order = array_flip( self::CATEGORIES );
		usort(
			$entries,
			static function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['category'] ], $a['sort'] ) <=> array( $order[ $b['category'] ], $b['sort'] );
			}
		);
		$counts   = array_fill_keys( self::CATEGORIES, 0 );
		$subtypes = array();
		foreach ( $entries as $n => $entry ) {
			unset( $entries[ $n ]['sort'] );
			$entries[ $n ] = array( 'n' => $n + 1 ) + $entries[ $n ];
			++$counts[ $entry['category'] ];
		}
		foreach ( array( 's', 'l' ) as $side ) {
			foreach ( $sides[ $side ] as $item ) {
				if ( null === $item['held'] ) {
					$subtypes[ $item['kind'] . "\0" . $item['subtype'] ] = true;
				}
			}
		}
		ksort( $subtypes, SORT_STRING );
		return array(
			'entries'  => array_values( $entries ),
			'counts'   => $counts,
			'paired'   => $paired,
			'held'     => array(
				'staging' => count( array_filter( $sides['s'], static fn( $item ) => null !== $item['held'] ) ),
				'live'    => count( array_filter( $sides['l'], static fn( $item ) => null !== $item['held'] ) ),
			),
			'subtypes' => $subtypes,
		);
	}

	/**
	 * The object of a side a pair names by its ref: by the ref itself, or by
	 * the SHA-256 of its ref where its line is held.
	 *
	 * @param array<string,int> $by   kind NUL ref key => index.
	 * @param string            $kind Kind.
	 * @param string            $ref  Ref of the pair.
	 * @return int|null
	 */
	private static function lookup( array $by, $kind, $ref ) {
		$encoded = AB_MCP_Sync_Canon::encode( $ref );
		foreach ( array( $encoded, 'sha:' . hash( 'sha256', $encoded ) ) as $key ) {
			if ( isset( $by[ $kind . "\0" . $key ] ) ) {
				return $by[ $kind . "\0" . $key ];
			}
		}
		return null;
	}

	/**
	 * The objects of one side, each with its identity key, its path and what
	 * keeps it from a pair by identity alone (doubt).
	 *
	 * @param array[] $lines  Lines.
	 * @param string  $side   s or l.
	 * @param array   $paired paired() of the side.
	 * @return array[]
	 */
	private static function items( array $lines, $side, array $paired = array() ) {
		$posts = array();
		$menus = array();
		foreach ( $lines as $line ) {
			if ( isset( $line['held'] ) ) {
				continue;
			}
			$id = isset( $line['identity'] ) && is_array( $line['identity'] ) ? $line['identity'] : array();
			if ( 'post' === $line['kind'] && is_string( $line['ref'] ) && isset( $id['post_name'] ) ) {
				$posts[ $line['ref'] ] = array(
					'name'     => $id['post_name'],
					'parent'   => self::parent_of( $line ),
					'type'     => (string) ( $id['post_type'] ?? '' ),
					'theme'    => (string) ( $id['theme'] ?? '' ),
					'language' => (string) ( $id['language'] ?? '' ),
					'twin'     => ! empty( $line['twin'] ),
				);
			}
			if ( 'menu' === $line['kind'] && is_string( $line['ref'] ) && isset( $id['slug'] ) ) {
				$menus[ $line['ref'] ] = $id['slug'];
			}
		}
		// Parents of one type, slug, theme and parent more than once: no path (Codex round 2, A-1).
		$twins = array_count_values( array_map( array( __CLASS__, 'twin_key' ), $posts ) );
		$out   = array();
		foreach ( $lines as $line ) {
			$kind = (string) $line['kind'];
			if ( isset( $line['held'] ) ) {
				$sha   = isset( $line['ref_sha256'] ) ? (string) $line['ref_sha256'] : hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) );
				$out[] = array(
					'side'    => $side,
					'kind'    => $kind,
					'ref_key' => 'sha:' . $sha,
					'ref_sha' => $sha,
					'held'    => (string) $line['held'],
					'subtype' => '',
					'key'     => null,
				);
				continue;
			}
			$identity = isset( $line['identity'] ) && is_array( $line['identity'] ) ? $line['identity'] : array();
			$locks    = array();
			foreach ( isset( $line['locks'] ) && is_array( $line['locks'] ) ? $line['locks'] : array() as $lock ) {
				$locks[] = array(
					'key'   => $lock['key'] ?? null,
					'stage' => $lock['stage'] ?? null,
				);
			}
			$parents = 'post' === $kind ? self::path( $line, $posts, $twins ) : array();
			$names   = array();
			$whole   = array();
			foreach ( $parents as $parent ) {
				$names[] = '?' === $parent ? '?' : $parent['name'];
				// Each parent by its whole identity (Codex round 4): another
				// language, type or theme of the same slug is another path.
				$whole[] = '?' === $parent ? '?' : array( $parent['type'], $parent['theme'], (string) $parent['name'], $parent['language'] );
			}
			$out[] = array(
				'side'            => $side,
				'kind'            => $kind,
				'ref'             => $line['ref'],
				'ref_key'         => AB_MCP_Sync_Canon::encode( $line['ref'] ),
				'subtype'         => (string) ( $identity['post_type'] ?? ( $identity['taxonomy'] ?? '' ) ),
				'identity'        => $identity,
				'status'          => (string) ( $line['status'] ?? '' ),
				'hash'            => isset( $line['hash'] ) && is_string( $line['hash'] ) ? $line['hash'] : null,
				'mapped'          => isset( $line['mapped'] ) && is_string( $line['mapped'] ) ? $line['mapped'] : null,
				'mapped_possible' => isset( $line['mapped_possible'] ) && is_string( $line['mapped_possible'] ) ? $line['mapped_possible'] : null,
				'complete'        => ! empty( $line['complete'] ),
				'held'            => null,
				'locks'           => $locks,
				'reason_code'     => $line['reason_code'] ?? null,
				'target'          => $line['target'] ?? null,
				'key'             => self::identity_key( $kind, $identity, $menus ),
				'names'           => $names,
				'path'            => AB_MCP_Sync_Canon::encode( $whole ),
				'twin'            => ! empty( $line['twin'] ),
				// How many objects of its site have its identity (AB_MCP_Sync_Identity::twins()), 1 for none but this one.
				'twins'           => empty( $line['twin'] ) ? 1 : $line['twin'],
				'doubt'           => self::doubt( $kind, $identity, $names ),
				// The instances of a widget area, in order (branch 6, part 2):
				// a confirmation keeps those of both sides with the bases.
				'instances'       => isset( $line['instances'] ) && is_array( $line['instances'] ) ? $line['instances'] : null,
				// Its references to the objects of pairs (Codex round 2 of part 2, b).
				'places'          => self::places( $line, $paired ),
			);
		}
		return $out;
	}

	/**
	 * What keeps an object from a pair by its identity alone, so that only a
	 * person's choice pairs it (Codex A1, A2; round 2, A-1 and A-2): a post
	 * without a slug, a post whose path of parents is not known whole on its
	 * site ("?" names no parent, never one the two sites share), a menu item
	 * whose target or parent has no identity. Null for none.
	 *
	 * @param string   $kind     Kind.
	 * @param array    $identity Identity.
	 * @param string[] $names    Its path (posts).
	 * @return string|null
	 */
	private static function doubt( $kind, array $identity, array $names = array() ) {
		if ( array() === $identity ) {
			return null;
		}
		if ( 'post' === $kind && '' === ( $identity['post_name'] ?? '' ) ) {
			return 'Without a slug, an object is named by nothing but its id, so it is paired only by the choice of a person.';
		}
		if ( 'post' === $kind && in_array( '?', $names, true ) ) {
			return 'The path of its parents is not known whole on its site (a parent not read, without a slug, or with the slug of another), so it is paired only by the choice of a person.';
		}
		if ( 'menu_item' === $kind && ( ! isset( $identity['target'] ) || ! isset( $identity['parent'] ) ) ) {
			return 'What the menu item shows, or the item it hangs under, has no identity of its own on its site (a target that is gone or has no slug, or another with the same: two links of one address and label), so it is paired only by the choice of a person.';
		}
		return null;
	}

	/**
	 * The key an object is matched by: its kind and what names it apart from
	 * its id (concept section 4); null where the identity was not read. A
	 * menu item by its menus, type, object, target and parent item, never by
	 * its position, which a site changes by sorting (Codex A2).
	 *
	 * @param string $kind     Pair kind.
	 * @param array  $identity Identity.
	 * @param array  $menus    Menu ref => slug of the same side.
	 * @return string|null
	 */
	public static function identity_key( $kind, array $identity, array $menus = array() ) {
		if ( array() === $identity ) {
			return null;
		}
		switch ( $kind ) {
			case 'post':
				// The language where the site says one (Codex round 3, B2): translations may share a slug.
				$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null, $identity['language'] ?? '' );
				break;
			case 'term':
			case 'menu':
				$parts = array( $identity['taxonomy'] ?? null, $identity['slug'] ?? null, $identity['language'] ?? '' );
				break;
			case 'menu_item':
				$slugs = array();
				foreach ( isset( $identity['menus'] ) && is_array( $identity['menus'] ) ? $identity['menus'] : array() as $menu ) {
					$slugs[] = isset( $menus[ (string) $menu ] ) ? AB_MCP_Sync_Canon::encode( $menus[ (string) $menu ] ) : '?';
				}
				sort( $slugs, SORT_STRING );
				$parts = array( $slugs, $identity['type'] ?? null, $identity['object'] ?? null, $identity['target'] ?? null, $identity['parent'] ?? null );
				break;
			case 'widget_area':
				$parts = array( $identity['area'] ?? null );
				break;
			case 'custom_css':
				$parts = array( $identity['stylesheet'] ?? null, ! empty( $identity['linked'] ) );
				break;
			case 'option':
				$parts = array( $identity['name'] ?? null );
				break;
			case 'theme_mod':
				$parts = array( $identity['name'] ?? null, $identity['theme_mod'] ?? null );
				break;
			default:
				$parts = array( $identity );
		}
		return AB_MCP_Sync_Canon::encode( array( $kind, $parts ) );
	}

	/**
	 * The id of the parent of a post line, from its references.
	 *
	 * @param array $line Line.
	 * @return string|null
	 */
	private static function parent_of( array $line ) {
		foreach ( isset( $line['refs'] ) && is_array( $line['refs'] ) ? $line['refs'] : array() as $ref ) {
			if ( 'post_parent' === ( $ref['at'] ?? null ) && 'post' === ( $ref['space'] ?? null ) ) {
				return (string) $ref['id'];
			}
		}
		return null;
	}

	/**
	 * What a post is named by on its side apart from its id: type, theme,
	 * slug, language and parent.
	 *
	 * @param array $post name, parent, type, theme, language.
	 * @return string
	 */
	private static function twin_key( array $post ) {
		return AB_MCP_Sync_Canon::encode( array( $post['type'] ?? '', $post['theme'] ?? '', (string) $post['name'], $post['language'] ?? '', $post['parent'] ) );
	}

	/**
	 * The path of a post: its parents from the top, each with its type,
	 * theme, slug and language, as far as the side names them ('?' for one
	 * it does not: not read, without a slug, with the type, slug, theme,
	 * language and parent of another post of the side, or one its site says
	 * another post has the identity of; Codex round 2, A-1 and A-2; round 4).
	 *
	 * @param array $line  Line.
	 * @param array $posts Post ref => name, parent, type, theme, language, twin of the same side.
	 * @param array $twins twin_key() => how many posts of the side have it.
	 * @return array Parents (as in $posts) or '?'.
	 */
	private static function path( array $line, array $posts, array $twins = array() ) {
		$parents = array();
		$parent  = self::parent_of( $line );
		$seen    = array();
		while ( null !== $parent && count( $parents ) < self::MAX_PATH ) {
			if ( isset( $seen[ $parent ] ) || ! isset( $posts[ $parent ] ) || '' === (string) $posts[ $parent ]['name'] || $posts[ $parent ]['twin'] || ( $twins[ self::twin_key( $posts[ $parent ] ) ] ?? 0 ) > 1 ) {
				$parents[] = '?';
				break;
			}
			$seen[ $parent ] = true;
			$parents[]       = $posts[ $parent ];
			$parent          = $posts[ $parent ]['parent'];
		}
		return array_reverse( $parents );
	}

	/**
	 * The entries of one group of the same kind and identity. No pair by
	 * exclusion (Codex A1): a pair only where the identity, the path of
	 * parents included, is the same on both sides, one to one. An identity
	 * that is there more than once on its site is no identity (Codex round
	 * 4), whether its site says so (twin) or a side holds it twice on one
	 * path: its objects on a path are paired only by an equal mapped form,
	 * one to one, where every one of them of both sites is at hand (by_form(),
	 * Codex round 5; a published page and a draft of its slug, unchanged).
	 * The others are offered for a person's choice (uncertain), also where a
	 * package holds only one of them or another one was paired by a choice;
	 * so are objects of the same identity left on another path, or with a
	 * doubt. None is paired by exclusion.
	 *
	 * @param array[]  $s        Staging objects.
	 * @param array[]  $l        Production objects.
	 * @param callable $complete Completeness of the staging side.
	 * @return array[]
	 */
	private static function resolve( array $s, array $l, callable $complete ) {
		$doubt = null;
		foreach ( array_merge( $s, $l ) as $item ) {
			if ( null !== $item['doubt'] ) {
				$doubt = $item['doubt'];
			}
		}
		$out  = array();
		$left = array(
			's' => array(),
			'l' => array(),
		);
		if ( null !== $doubt ) {
			$left = array(
				's' => $s,
				'l' => $l,
			);
		} else {
			$by_path = array();
			foreach ( $s as $item ) {
				$by_path[ $item['path'] ]['s'][] = $item;
			}
			foreach ( $l as $item ) {
				$by_path[ $item['path'] ]['l'][] = $item;
			}
			ksort( $by_path, SORT_STRING );
			foreach ( $by_path as $sub ) {
				$ss   = isset( $sub['s'] ) ? $sub['s'] : array();
				$ll   = isset( $sub['l'] ) ? $sub['l'] : array();
				$twin = count( $ss ) > 1 || count( $ll ) > 1;
				foreach ( array_merge( $ss, $ll ) as $item ) {
					$twin = $twin || $item['twin'];
				}
				if ( $twin && array() !== $ss && array() !== $ll ) {
					list( $pairs, $ss, $ll ) = self::by_form( $ss, $ll );
					foreach ( $pairs as $pair ) {
						$out[] = self::pair_entry( $pair[0], $pair[1] );
					}
					if ( array() !== $ss && array() !== $ll ) {
						$out[] = self::choice_entry( 'uncertain', $ss, $ll, self::twin_reason( $ss[0]['kind'] ) );
						continue;
					}
					// What is left on one side only has no counterpart on its path.
				}
				if ( 1 === count( $ss ) && 1 === count( $ll ) ) {
					$out[] = self::pair_entry( $ss[0], $ll[0] );
					continue;
				}
				$left['s'] = array_merge( $left['s'], $ss );
				$left['l'] = array_merge( $left['l'], $ll );
			}
		}
		if ( array() !== $left['s'] && array() !== $left['l'] ) {
			$out[] = self::choice_entry( 'uncertain', $left['s'], $left['l'], null !== $doubt ? $doubt : 'The same slug on another path of parents (another parent, or a parent of another type, theme or language): another object, or one that moved. It is paired only by the choice of a person.' );
		} else {
			foreach ( $left['s'] as $item ) {
				$out[] = self::alone( $item, 'staging_only', null );
			}
			foreach ( $left['l'] as $item ) {
				$out[] = self::live_alone( $item, $complete );
			}
		}
		return $out;
	}

	/**
	 * Twins of one path paired by an equal mapped form, as a proof only
	 * (Codex round 3, B3; round 5): the form with the typed references by
	 * identity, then the one with the possible references so as well, each
	 * pair where that form is there once on each side among what is left,
	 * both read whole. Only where every object of the identity of both sites
	 * is at hand (all_at_hand()), never for menu items (only their
	 * place tells them apart, Codex round 3, A2). What is left is never paired
	 * by exclusion.
	 *
	 * @param array[] $ss Staging objects of the path.
	 * @param array[] $ll Production objects of the path.
	 * @return array pairs (staging, production), the rest of each side.
	 */
	private static function by_form( array $ss, array $ll ) {
		$pairs = array();
		if ( 'menu_item' === $ss[0]['kind'] || ! self::all_at_hand( $ss ) || ! self::all_at_hand( $ll ) ) {
			return array( $pairs, $ss, $ll );
		}
		foreach ( array( 'mapped', 'mapped_possible' ) as $form ) {
			list( $found, $ss, $ll ) = self::unique_pairs( $ss, $ll, $form );
			$pairs                   = array_merge( $pairs, $found );
		}
		return array( $pairs, $ss, $ll );
	}

	/**
	 * Whether the objects of one side on one path are every object its site
	 * has of their identity: as many as each line says its site has (twin; a
	 * line that is none says one, a twin that does not say how many is never
	 * all). Not so where a package holds only some of them, one is held or
	 * not read, or one was paired by a choice before (Codex round 4).
	 *
	 * @param array[] $items Objects of one side and path.
	 * @return bool
	 */
	private static function all_at_hand( array $items ) {
		foreach ( $items as $item ) {
			if ( count( $items ) !== $item['twins'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The objects of the two sides whose mapped form (of $form) is equal and
	 * there once on each side, as pairs, where both are read whole (an equal
	 * form not read to its end says nothing); the rest of each side.
	 *
	 * @param array[] $ss   Staging objects.
	 * @param array[] $ll   Production objects.
	 * @param string  $form mapped or mapped_possible.
	 * @return array pairs (staging, production), the rest of each side.
	 */
	private static function unique_pairs( array $ss, array $ll, $form ) {
		$proof = static function ( array $item ) use ( $form ) {
			return is_string( $item[ $form ] ) && $item['complete'];
		};
		$hs    = array_count_values( array_filter( array_column( $ss, $form ), 'is_string' ) );
		$hl    = array_count_values( array_filter( array_column( $ll, $form ), 'is_string' ) );
		$by    = array();
		foreach ( $ll as $j => $item ) {
			if ( $proof( $item ) && 1 === $hl[ $item[ $form ] ] && 1 === ( $hs[ $item[ $form ] ] ?? 0 ) ) {
				$by[ $item[ $form ] ] = $j;
			}
		}
		$pairs = array();
		$used  = array();
		$rest  = array();
		foreach ( $ss as $item ) {
			if ( $proof( $item ) && isset( $by[ $item[ $form ] ] ) ) {
				$used[ $by[ $item[ $form ] ] ] = true;
				$pairs[]                        = array( $item, $ll[ $by[ $item[ $form ] ] ] );
				continue;
			}
			$rest[] = $item;
		}
		$rest_l = array();
		foreach ( $ll as $j => $item ) {
			if ( ! isset( $used[ $j ] ) ) {
				$rest_l[] = $item;
			}
		}
		return array( $pairs, $rest, $rest_l );
	}

	/**
	 * Why the objects of an identity that is there more than once on a site
	 * are paired only by a choice.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function twin_reason( $kind ) {
		if ( 'menu_item' === $kind ) {
			// Only the place tells them apart, which is no identity (Codex round 3, A2).
			return 'Another item of its menu has the same identity on its site (two links of one address and label, or one target, under one parent), so only its place tells them apart: it is paired only by the choice of a person.';
		}
		return 'Another object of its site has the same identity (a post of the same type, slug, language and parent, such as a published page and a draft of its slug; a term of the same taxonomy, slug and language), so neither is named by it. Such objects are paired by an equal content only where it is there once on each side and every one of them of both sites is at hand; this one is paired only by the choice of a person, also where a package holds only one of them or the other one was paired.';
	}

	/**
	 * The entry of a production object without a staging counterpart: only
	 * live where the staging side is complete for its kind and subtype, else
	 * not compared.
	 *
	 * @param array    $item     Object.
	 * @param callable $complete Completeness of the staging side.
	 * @return array
	 */
	private static function live_alone( array $item, callable $complete ) {
		if ( true === call_user_func( $complete, $item['kind'], $item['subtype'] ) ) {
			return self::alone( $item, 'live_only', null );
		}
		return self::alone( $item, 'not_compared', 'The inventory of the staging site does not show that it holds every object of this kind: it was read across packages (a package does not say which other packages belong to its series, so this site cannot tell a whole series from one with a package left out), or objects of it were withheld from the account that made it. So an object of this site without a counterpart is not called only live. Where objects were withheld, export again with an account that may read every object (wp_site_export); a kind read across packages stays so in this version.' );
	}

	/**
	 * The entry of one staging object and one production object of the same
	 * identity and path: equal where both are read whole, their mapped forms
	 * are the same (decision 10) and every reference to the object of a pair
	 * names, on the other side, the other object of that pair (alike(), Codex
	 * round 2 of part 2, b); differs where they are not, or where a side
	 * names a target that has no identity; not compared where a side has no
	 * hash. Where a reference that names the object (NAMED_BY: the parent of
	 * a post, the target, parent item or menus of a menu item) is the object
	 * of a pair on one side and another object on the other, the two are no
	 * objects of one identity: uncertain, only a person's choice pairs them.
	 *
	 * @param array $s Staging object.
	 * @param array $l Production object.
	 * @return array
	 */
	private static function pair_entry( array $s, array $l ) {
		if ( null === $s['hash'] || null === $l['hash'] ) {
			$why = self::why_no_hash( null === $s['hash'] ? $s : $l );
			return self::entry( 'not_compared', $s, $l ) + array( 'reason' => $why );
		}
		$named = self::NAMED_BY[ $s['kind'] ] ?? array();
		if ( ! self::refs_agree( self::at( $s['places'], $named ), self::at( $l['places'], $named ) ) ) {
			return self::choice_entry( 'uncertain', array( $s ), array( $l ), 'What names it on its site (the parent of a post; the target, parent item or menu of a menu item) is the object of a pair on one side and another object on the other (the object of the pair renamed, and another object of its site took its slug): another object, or one that moved. It is paired only by the choice of a person.' );
		}
		$equal = self::alike( $s, $l );
		return self::entry( $equal ? 'equal' : 'differs', $s, $l ) + array( 'confirm' => $equal ? 'active' : 'unclear' );
	}

	/**
	 * Whether two objects are alike so that their pair begins active: both
	 * read whole, the same mapped form, and each reference to the object of a
	 * pair naming, on the other side, the other object of that pair
	 * (refs_agree()). Also for two sides as an entry of a proposal keeps them
	 * (the choice of a person, AB_MCP_Sync_Pairs_Tool); a side kept without
	 * its places (a proposal of an earlier version) is not alike.
	 *
	 * @param array $s Staging object or side.
	 * @param array $l Production object or side.
	 * @return bool
	 */
	public static function alike( array $s, array $l ) {
		return ! empty( $s['complete'] ) && ! empty( $l['complete'] ) && self::mapped_same( $s, $l )
			&& isset( $s['places'], $l['places'] ) && is_array( $s['places'] ) && is_array( $l['places'] )
			&& self::refs_agree( $s['places'], $l['places'] );
	}

	/**
	 * The pairs by the objects they name: by side (s, l), ID space and ref,
	 * the id of the pair (for places()).
	 *
	 * @param array[] $pairs Pairs (AB_MCP_Sync_Pairs::decode()).
	 * @return array
	 */
	public static function paired( array $pairs ) {
		$out = array(
			's' => array(),
			'l' => array(),
		);
		foreach ( $pairs as $pair ) {
			foreach ( self::SPACES as $space => $kinds ) {
				if ( in_array( $pair['kind'], $kinds, true ) ) {
					$out['s'][ $space ][ (string) $pair['staging_ref'] ] = (string) $pair['id'];
					$out['l'][ $space ][ (string) $pair['live_ref'] ]    = (string) $pair['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * The references of a line by their place (ID space NUL at), where a
	 * target of the place is the object of a pair on its side: each target
	 * of the place in its order as the id of its pair, '' for none (pairs).
	 * Whether a target is the object of a pair, the pairs of its side say by
	 * its id alone (paired()): no line of the target is needed, so a package
	 * that holds the object but not its targets yet compares as one that
	 * holds both (Codex round 3 of part 2, B). A place without a target of a
	 * pair is left out: the mapped forms compare it.
	 *
	 * @param array $line   Line.
	 * @param array $paired paired() of its side.
	 * @return array
	 */
	public static function places( array $line, array $paired ) {
		$out = array();
		foreach ( isset( $line['refs'] ) && is_array( $line['refs'] ) ? $line['refs'] : array() as $ref ) {
			$space = (string) ( $ref['space'] ?? '' );
			$key   = $space . "\0" . (string) ( $ref['at'] ?? '' );
			$out[ $key ]['pairs'][] = $paired[ $space ][ (string) ( $ref['id'] ?? '' ) ] ?? '';
		}
		foreach ( $out as $key => $place ) {
			if ( array() === array_diff( $place['pairs'], array( '' ) ) ) {
				unset( $out[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Whether the references of two objects name the same objects where a
	 * pair names their targets (Codex round 1 of part 2, A; round 2, B and
	 * b; round 3, B), from their places(). Two rules, one for each kind of
	 * target: a target without a pair is compared by the identity the mapped
	 * form of the object names it by (mapped_same(), decision 10: the mapper
	 * of its site put the identity of every target into the form, so the
	 * form carries it whether the line of the target is at hand or not); a
	 * target of a pair is compared by its pair, because an object of a pair
	 * renamed on one side, whose slug another object there takes, has the
	 * identity of that other one, and the forms alone would call the other
	 * one the same. So a reference to the object of a pair, on either side,
	 * is the same only where the other side names the other object of that
	 * pair in its place:
	 *  - by the place of each reference (its ID space and where it stands,
	 *    at), in its order, each target by the id of its pair or none ('');
	 *  - a place no target of which has a pair on either side is left to the
	 *    mapped forms (both name objects without a pair, by identity);
	 *  - in a set of the canonical form (AB_MCP_Sync_Canon::set(): the terms
	 *    of a taxonomy, at term:<taxonomy>, and the menus of a menu item, at
	 *    menu) the order does not count: the ids of the pairs of the place
	 *    are compared in one order, with as many '' as targets without a
	 *    pair, so a target of a pair on one side against an object without a
	 *    pair in its place on the other (also of the same identity) is not
	 *    alike;
	 *  - a place on one side only, with a target of a pair: not alike.
	 *
	 * @param array $s places() of the staging object.
	 * @param array $l places() of the production object.
	 * @return bool
	 */
	public static function refs_agree( array $s, array $l ) {
		foreach ( array_unique( array_merge( array_keys( $s ), array_keys( $l ) ) ) as $key ) {
			$there = $s[ $key ]['pairs'] ?? array();
			$here  = $l[ $key ]['pairs'] ?? array();
			$at    = explode( "\0", (string) $key, 2 )[1] ?? '';
			if ( 'menu' === $at || 0 === strpos( $at, 'term:' ) ) {
				sort( $there, SORT_STRING );
				sort( $here, SORT_STRING );
			}
			if ( $there !== $here ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The places of these kinds of references (by at).
	 *
	 * @param array    $places places().
	 * @param string[] $ats    The places (at) to keep.
	 * @return array
	 */
	private static function at( array $places, array $ats ) {
		$out = array();
		foreach ( $places as $key => $place ) {
			if ( in_array( explode( "\0", (string) $key, 2 )[1] ?? '', $ats, true ) ) {
				$out[ $key ] = $place;
			}
		}
		return $out;
	}

	/**
	 * Whether two lines or objects have the same mapped form: the one with
	 * its typed references by identity (mapped), or the one with its
	 * possible references by identity as well (mapped_possible, Codex
	 * round 2, B-1). Either suffices: a number that is no reference (a price
	 * of 10 on both sites) keeps the first, an id of the same image on other
	 * ids the second. A side without one (a target without identity) is
	 * none.
	 *
	 * @param array $s Staging line or object.
	 * @param array $l Production line or object.
	 * @return bool
	 */
	public static function mapped_same( array $s, array $l ) {
		foreach ( array( 'mapped', 'mapped_possible' ) as $key ) {
			if ( isset( $s[ $key ], $l[ $key ] ) && is_string( $s[ $key ] ) && is_string( $l[ $key ] ) && hash_equals( $s[ $key ], $l[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The entry of objects only a person pairs: uncertain (an identity there
	 * more than once on a site, the same identity on another path, or a
	 * doubt).
	 *
	 * @param string      $category uncertain.
	 * @param array[]     $s        Staging objects.
	 * @param array[]     $l        Production objects.
	 * @param string|null $reason   Why.
	 * @return array
	 */
	private static function choice_entry( $category, array $s, array $l, $reason ) {
		$entry               = self::entry( $category, $s[0], $l[0] );
		$entry['staging']    = null;
		$entry['live']       = null;
		$entry['candidates'] = array(
			'staging' => array_map( array( __CLASS__, 'side' ), $s ),
			'live'    => array_map( array( __CLASS__, 'side' ), $l ),
		);
		$entry['confirm']    = 'choice';
		if ( null !== $reason ) {
			$entry['reason'] = $reason;
		}
		return $entry;
	}

	/**
	 * The entry of an object a credential holds: by the SHA-256 of its ref
	 * and its code, never by a name.
	 *
	 * @param array $item Held object.
	 * @return array
	 */
	private static function held_entry( array $item ) {
		$named = array(
			array(
				'held'       => $item['held'],
				'ref_sha256' => $item['ref_sha'],
			),
		);
		return array(
			'category' => 'held',
			'kind'     => $item['kind'],
			'subtype'  => '',
			'staging'  => 's' === $item['side'] ? $named : array(),
			'live'     => 'l' === $item['side'] ? $named : array(),
			'reason'   => 'A name of this object holds a credential by the rules of this site (held: credential_name, credential_field or unreadable_name), so it is shown by the SHA-256 of its ref only and is not paired. Take it over by hand.',
			'sort'     => $item['side'] . $item['ref_sha'],
		);
	}

	/**
	 * The entry of a pair in the state unclear or residual: its new alignment
	 * with the bases of both sides now, where both objects are there,
	 * compared and not held. A held side is named by the SHA-256 of its ref.
	 *
	 * @param array      $pair Pair.
	 * @param array|null $s    Its staging object.
	 * @param array|null $l    Its production object.
	 * @return array
	 */
	private static function realign_entry( array $pair, $s, $l ) {
		$entry = array(
			'category' => 'realign',
			'kind'     => $pair['kind'],
			'subtype'  => null !== $s && null === $s['held'] ? $s['subtype'] : ( null !== $l && null === $l['held'] ? $l['subtype'] : '' ),
			'staging'  => null === $s ? array( 'ref' => $pair['staging_ref'] ) : self::side( $s ),
			'live'     => null === $l ? array( 'ref' => $pair['live_ref'] ) : self::side( $l ),
			'pair'     => array(
				'id'       => (int) $pair['id'],
				'state'    => $pair['state'],
				'snapshot' => hash( 'sha256', self::pair_snapshot( $pair ) ),
			),
			'sort'     => sprintf( '%020d', (int) $pair['id'] ),
		);
		$ready = null !== $s && null !== $l && null === $s['held'] && null === $l['held'] && null !== $s['hash'] && null !== $l['hash'];
		if ( ! $ready ) {
			$entry['confirm'] = null;
			$entry['reason']  = null === $l
				? ( 'residual' === $pair['state'] ? 'Rest removed: the object is no longer on this site.' : 'The object is no longer on this site.' ) . ' A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.'
				: ( null === $s ? 'The object is not in the inventory of the staging site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.' : 'A side of this pair is not compared (no hash, or a credential holds it), so it is not aligned anew. Take it over by hand, or call wp_site_pairs with action=discard and its pair id.' );
			return $entry;
		}
		$entry['confirm'] = 'active';
		if ( ! self::alike( $s, $l ) ) {
			$entry['note'] = 'The two sides differ now. Confirming makes the state of each side now its new base: the difference is then no change to carry over.';
		}
		return $entry;
	}

	/**
	 * The snapshot of a pair as a proposal holds it (AB_MCP_Sync_Pairs::
	 * snapshot() of the decoded row): the same text the store gives.
	 *
	 * @param array $pair Pair (decoded).
	 * @return string
	 */
	public static function pair_snapshot( array $pair ) {
		$columns = array();
		foreach ( AB_MCP_Sync_Pairs::SNAPSHOT_COLUMNS as $column ) {
			$value = $pair[ $column ] ?? null;
			if ( ( 'override' === $column || 'base_extra' === $column ) && null !== $value ) {
				$value = AB_MCP_Sync_Pairs::encode_json( $column, $value );
			}
			$columns[ $column ] = $value;
		}
		return (string) json_encode(
			array(
				'columns' => $columns,
				'format'  => AB_MCP_Sync_Pairs::SNAPSHOT_FORMAT,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * The entry of an object alone.
	 *
	 * @param array       $item     Object.
	 * @param string      $category Category.
	 * @param string|null $reason   Why, where it is not compared.
	 * @return array
	 */
	private static function alone( array $item, $category, $reason ) {
		$entry = self::entry( $category, 's' === $item['side'] ? $item : null, 'l' === $item['side'] ? $item : null );
		$why   = null !== $reason ? $reason : ( null === $item['hash'] && null !== $item['reason_code'] ? self::why_no_hash( $item ) : null );
		return null === $why ? $entry : $entry + array( 'reason' => $why );
	}

	/**
	 * An entry with its sides.
	 *
	 * @param string     $category Category.
	 * @param array|null $s        Staging object.
	 * @param array|null $l        Production object.
	 * @return array
	 */
	private static function entry( $category, $s, $l ) {
		$one = null !== $s ? $s : $l;
		return array(
			'category' => $category,
			'kind'     => $one['kind'],
			'subtype'  => $one['subtype'],
			'staging'  => null === $s ? null : self::side( $s ),
			'live'     => null === $l ? null : self::side( $l ),
			'sort'     => (string) $one['key'] . "\0" . $one['path'] . "\0" . $one['ref_key'],
		);
	}

	/**
	 * One side of an entry: ref, identity with the path of parents of a
	 * post, status, locks, whether it was read whole, its hashes and its
	 * references to the objects of pairs (places(), for the choice of a
	 * person; stored, not shown); a held object by the SHA-256 of its ref
	 * only.
	 *
	 * @param array $item Object.
	 * @return array
	 */
	private static function side( array $item ) {
		if ( null !== $item['held'] ) {
			return array(
				'held'       => $item['held'],
				'ref_sha256' => $item['ref_sha'],
			);
		}
		$out = array(
			'ref'             => $item['ref'],
			'identity'        => $item['identity'],
			'status'          => $item['status'],
			'complete'        => $item['complete'],
			'hash'            => $item['hash'],
			'mapped'          => $item['mapped'],
			'mapped_possible' => $item['mapped_possible'],
			'places'          => $item['places'],
		);
		if ( 'post' === $item['kind'] ) {
			$out['path'] = $item['names'];
		}
		if ( 'widget_area' === $item['kind'] && null !== $item['instances'] ) {
			$out['instances'] = $item['instances'];
		}
		if ( array() !== $item['locks'] ) {
			$out['locks'] = $item['locks'];
		}
		return $out;
	}

	/**
	 * Why an object has no hash.
	 *
	 * @param array $item Object.
	 * @return string
	 */
	public static function why_no_hash( array $item ) {
		$text = AB_MCP_Sync_Option_Schema::reason_text( $item['reason_code'], $item['target'] );
		if ( null !== $text ) {
			return $text;
		}
		return 'A credential holds this object (a field of a credential, a post protected by a password, a script, HTML outside kses, a value that does not unpack), so it is never compared; its locks name why. Take it over by hand.';
	}

	/**
	 * Why an object without an identity is not compared.
	 *
	 * @param array $item Object.
	 * @return string
	 */
	public static function why_unread( array $item ) {
		return 's' === $item['side']
			? 'The staging site could not read this row, so it has no identity to match. Make the inventory again on the staging site (wp_site_export), take it over here, then propose again.'
			: 'This site could not read this row, so it has no identity to match. Try again; if it keeps failing, the database error log names the cause.';
	}

	/**
	 * Lines by kind NUL the ref (held lines are reduced and named by none).
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,array>
	 */
	private static function by_ref( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( isset( $line['held'] ) ) {
				continue;
			}
			$out[ $line['kind'] . "\0" . ( is_string( $line['ref'] ) ? $line['ref'] : AB_MCP_Sync_Canon::encode( $line['ref'] ) ) ] = $line;
		}
		return $out;
	}

	/* ------------------------------------------------------------ files */

	/**
	 * Write one file of a proposal (or of a report, with its format), named
	 * by its prefix, number and SHA-256.
	 *
	 * @param string   $gid    Group.
	 * @param string   $prefix h, c, d, e or x (i: put directly, as text; v:
	 *                         a part of the environment of a report, put by
	 *                         AB_MCP_Sync_Report).
	 * @param int|null $seq    Number (c, d, e).
	 * @param mixed    $data   What it holds (d and e: a list, wrapped).
	 * @param string   $format The format of the files of the group.
	 * @return array|WP_Error name, sha.
	 */
	public static function put( $gid, $prefix, $seq, $data, $format = self::FORMAT ) {
		if ( in_array( $prefix, array( 'd', 'e' ), true ) ) {
			$data = array(
				'data'   => $data,
				'format' => $format,
			);
		}
		$text = AB_MCP_Sync_Canon::encode( $data );
		$sha  = hash( 'sha256', $text );
		$name = self::name( $prefix, $seq, $sha );
		$put  = AB_MCP_Protected_Store::put( 'reports', $gid, $name, $text );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		return array(
			'name' => $name,
			'sha'  => $sha,
		);
	}

	/**
	 * The one file of a prefix without a number (h, x), read against its
	 * SHA-256; null where there is none.
	 *
	 * @param string            $gid        Group.
	 * @param array<string,int> $files      Files.
	 * @param string            $prefix     h or x.
	 * @param WP_Error|null     $concurrent See parts().
	 * @return array|null|WP_Error
	 */
	public static function read_one( $gid, array $files, $prefix, $concurrent = null ) {
		$found = array();
		foreach ( array_keys( $files ) as $name ) {
			$parsed = self::parse_name( (string) $name );
			if ( null !== $parsed && $prefix === $parsed['prefix'] ) {
				$found[] = $parsed;
			}
		}
		if ( array() === $found ) {
			return null;
		}
		if ( count( $found ) > 1 ) {
			return null === $concurrent ? self::concurrent() : $concurrent;
		}
		$text = AB_MCP_Protected_Store::get( 'reports', $gid, $found[0]['name'], $found[0]['sha'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = json_decode( $text, true, 64 );
		return is_array( $data ) ? $data : ( null === $concurrent ? self::concurrent() : $concurrent );
	}

	/**
	 * The entries of one stored part.
	 *
	 * @param string        $gid        Group.
	 * @param array         $part       name, sha.
	 * @param WP_Error|null $concurrent See parts().
	 * @return array[]|WP_Error
	 */
	public static function read_part( $gid, array $part, $concurrent = null ) {
		$text = AB_MCP_Protected_Store::get( 'reports', $gid, $part['name'], $part['sha'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$list = json_decode( $text, true, 128 );
		return is_array( $list ) && is_array( $list['data'] ?? null ) ? $list['data'] : ( null === $concurrent ? self::concurrent() : $concurrent );
	}

	/**
	 * The name of a file: prefix, number (four digits and a hyphen) where it
	 * has one, its SHA-256 in base32.
	 *
	 * @param string   $prefix Prefix.
	 * @param int|null $seq    Number.
	 * @param string   $sha    SHA-256 in hex.
	 * @return string
	 */
	public static function name( $prefix, $seq, $sha ) {
		$bits = '';
		foreach ( str_split( (string) hex2bin( $sha ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$name = $prefix . ( null === $seq ? '' : sprintf( '%04d-', (int) $seq ) );
		foreach ( str_split( $bits, 5 ) as $five ) {
			$name .= AB_MCP_Sync_Import::BASE32[ bindec( str_pad( $five, 5, '0' ) ) ];
		}
		return $name;
	}

	/**
	 * A name of name(), parsed; null for any other.
	 *
	 * @param string $name Name.
	 * @return array|null prefix, seq, sha, name.
	 */
	public static function parse_name( $name ) {
		if ( 1 !== preg_match( '/^([hx])([a-z2-7]{52})\z|^([cdeiv])([0-9]{4})-([a-z2-7]{52})\z/', $name, $m ) ) {
			return null;
		}
		$prefix  = '' !== $m[1] ? $m[1] : $m[3];
		$seq     = '' !== $m[1] ? null : (int) $m[4];
		$letters = '' !== $m[1] ? $m[2] : $m[5];
		$bits    = '';
		foreach ( str_split( $letters ) as $letter ) {
			$bits .= str_pad( decbin( strpos( AB_MCP_Sync_Import::BASE32, $letter ) ), 5, '0', STR_PAD_LEFT );
		}
		$hex = '';
		foreach ( str_split( substr( $bits, 0, 256 ), 4 ) as $four ) {
			$hex .= dechex( bindec( $four ) );
		}
		if ( self::name( $prefix, $seq, $hex ) !== $name ) {
			return null;
		}
		return array(
			'prefix' => $prefix,
			'seq'    => $seq,
			'sha'    => $hex,
			'name'   => $name,
		);
	}

	/**
	 * @return int Seconds.
	 */
	private static function now() {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/* ---------------------------------------------------------- refusals */

	/**
	 * @return WP_Error No proposal of that id.
	 */
	private static function unknown() {
		return new WP_Error( 'ab_mcp_sync_pairs_unknown', __( 'This site holds no proposal of that id (a proposal is kept a day at most). Propose again: call wp_site_pairs with action=propose and the packages of the inventory of the staging site.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error A proposal of another account or connection.
	 */
	private static function foreign() {
		return new WP_Error( 'ab_mcp_sync_pairs_foreign', __( 'This proposal belongs to another account or connection, and only the one that made it goes on with it or confirms it. Propose again over this connection: call wp_site_pairs with action=propose and the packages of the inventory of the staging site.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error Packages of more than one series.
	 */
	private static function mixed() {
		return new WP_Error( 'ab_mcp_sync_pairs_series', __( 'The packages are not of one inventory: an object is in two of them, or a kind read whole in one package has objects in another (packages of two exports). Pass the packages of one series only: the first wp_site_export without a cursor and each one after with the cursor of the one before, all taken over here; then call again.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error The files of a proposal are not as one call wrote them.
	 */
	private static function concurrent() {
		return new WP_Error( 'ab_mcp_sync_pairs_parts', __( 'The files of this proposal are not as one call after the other wrote them (two calls went on with it at once, or a file was changed in the protected store). Propose again: call wp_site_pairs with action=propose and the packages of the inventory of the staging site, and go on with one call at a time.', 'alphabridge-mcp' ) );
	}
}
