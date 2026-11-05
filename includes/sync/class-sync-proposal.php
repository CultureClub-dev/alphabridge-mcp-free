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
 * the import's list for the staging side) is listed by the SHA-256 of its ref
 * and its code only, never by a name, and is never proposed as a pair.
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
 * and identity (identity_key()); within a group of more than one object on a
 * side, by the path of parents (posts), then by an equal hash that is unique
 * on both sides; what is left one to one is a pair, more is «ambiguous».
 * Categories (CATEGORIES): equal (one to one, both hashes there, read whole
 * and equal: confirmed as active), differs (the same identity, other content:
 * confirmed as unclear), ambiguous (the person names the pair), realign (a
 * pair in the state unclear or residual, both objects there: confirmed with
 * the bases of both sides now, the only way out of these states, decision
 * 14), staging_only and live_only (no counterpart; live_only only where the
 * staging side is complete), not_compared (a side without a hash, a row not
 * read, a ref no pair can hold, the staging side not complete) and held. An
 * object of a pair is not proposed again.
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
 * canon, until when), the parts of the production inventory and, once done,
 * the entries in parts with an index. Each file is written once and named by
 * its SHA-256 (base32), read only against it. The proposal id is the id of
 * the group; it holds for that account and connection, while the print of the
 * pair and the packages hold, until the earliest package expires.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
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

/**
 * Class AB_MCP_Sync_Proposal
 */
final class AB_MCP_Sync_Proposal {

	/** The format of the files of a proposal. */
	const FORMAT = 'ab-sync-proposal/1';

	/** The label of its group in the area reports. */
	const LABEL = 'proposal';

	/** The categories of an entry, in the order of the entries. */
	const CATEGORIES = array( 'equal', 'differs', 'ambiguous', 'realign', 'staging_only', 'live_only', 'not_compared', 'held' );

	/** The categories a person confirms. */
	const CONFIRMABLE = array( 'equal', 'differs', 'ambiguous', 'realign' );

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
			return new WP_Error( 'ab_mcp_sync_pairs_packages', sprintf( 'A proposal begins with packages: the package_ids of the inventory of the staging site this site took over completely with wp_site_import_chunk, each once, at most %d. Pass them, or proposal_id to go on with a proposal.', self::MAX_PACKAGES ) );
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
			return new WP_Error( 'ab_mcp_sync_pairs_stale', 'The role of this site, its pair, the confirmed key of the staging site or the version of the canonical form changed since this proposal was made, so it holds no more. Propose again: call wp_site_pairs with action=propose and the packages of a fresh inventory of the staging site.' );
		}
		if ( self::now() >= (int) $head['expires'] ) {
			return new WP_Error( 'ab_mcp_sync_pairs_expired', 'This proposal expired: it holds as long as the packages it was made from (a day at most). Propose again: export the inventory on the staging site (wp_site_export), take it over here (wp_site_import_chunk), then call wp_site_pairs with action=propose.' );
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
			return new WP_Error( 'ab_mcp_sync_multisite', 'Object pairs are not available on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the production site.' );
		}
		$anchor = AB_MCP_Sync_Anchor::active();
		if ( null === $anchor ) {
			return new WP_Error( 'ab_mcp_sync_pairs_no_anchor', 'This site has no confirmed key of a staging site, so it has no object pairs with one. Ask the person to fetch the key of the staging site, or enter its pairing code, and confirm it on the page AlphaBridge → Staging of this site; then call again.' );
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
					$line['held'] = $codes[ $kind . "\0" . $sha ];
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
		$cursor = null;
		$seq    = count( $found['parts'] );
		if ( $seq > 0 ) {
			$last   = $found['parts'][ $seq - 1 ];
			$cursor = $last['cursor'];
			if ( null === $cursor ) {
				return self::finish( $found );
			}
		}
		$partner = AB_MCP_Sync_Anchor::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		$source = AB_MCP_Sync_Inventory::source( array( home_url(), $partner ), max( 1, AB_MCP_Sync_Inventory::BUDGET - self::RESERVE ), null, null === self::$limit ? self::LIMIT : (int) self::$limit );
		$read   = AB_MCP_Sync_Inventory::run( $source, $cursor );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$lines = array();
		$b9    = 0;
		foreach ( $read['objects'] as $object ) {
			if ( 'b9' === $object['status'] ) {
				++$b9;
				continue;
			}
			$line = AB_MCP_Sync_Export::line( $object );
			$code = AB_MCP_Sync_Import::held_reason( $line );
			if ( null !== $code ) {
				$line['held'] = $code;
			}
			$lines[] = $line;
		}
		$part = self::put_part( $found['gid'], $seq + 1, $lines, $read['cursor'], $b9 );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		$found['parts'][] = $part;
		if ( null === $read['cursor'] ) {
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
			'next'        => sprintf( 'The inventory of this site is read as far as one call reaches (%d objects so far). Call wp_site_pairs again with action=propose and this proposal_id to go on.', $read_so_far ),
		);
	}

	/**
	 * Store one part of the production inventory: its lines in files of at
	 * most SLICE bytes, then the file that closes the part (its cursor, its
	 * count of credentials and the names of its files). A part without that
	 * file is no part (a call that ended in between); two parts of one number
	 * are two calls that went on at once.
	 *
	 * @param string     $gid    Group.
	 * @param int        $seq    Number of the part, from 1.
	 * @param array[]    $lines  Lines.
	 * @param array|null $cursor Cursor of the inventory after it.
	 * @param int        $b9     Objects left out as credentials.
	 * @return array|WP_Error The part as parts() reads it.
	 */
	private static function put_part( $gid, $seq, array $lines, $cursor, $b9 ) {
		$files = array();
		$slice = array();
		$size  = 0;
		foreach ( $lines as $line ) {
			$text = AB_MCP_Sync_Canon::encode( $line );
			if ( array() !== $slice && $size + strlen( $text ) + 1 > self::SLICE ) {
				$put = self::put( $gid, 'd', $seq, $slice );
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
			$put = self::put( $gid, 'd', $seq, $slice );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$files[] = $put;
		}
		$close = array(
			'b9'      => (int) $b9,
			'cursor'  => $cursor,
			'files'   => $files,
			'format'  => self::FORMAT,
			'objects' => count( $lines ),
			'seq'     => (int) $seq,
		);
		$put   = self::put( $gid, 'c', $seq, $close );
		return is_wp_error( $put ) ? $put : $close;
	}

	/**
	 * The parts of the production inventory, in order: each one closed, one
	 * per number, without a gap.
	 *
	 * @param string            $gid   Group.
	 * @param array<string,int> $files Files of the group.
	 * @return array[]|WP_Error
	 */
	private static function parts( $gid, array $files ) {
		$closes = array();
		foreach ( array_keys( $files ) as $name ) {
			$parsed = self::parse_name( (string) $name );
			if ( null === $parsed || 'c' !== $parsed['prefix'] ) {
				continue;
			}
			if ( isset( $closes[ $parsed['seq'] ] ) ) {
				return self::concurrent();
			}
			$closes[ $parsed['seq'] ] = $parsed;
		}
		ksort( $closes );
		$out = array();
		$at  = 1;
		foreach ( $closes as $seq => $parsed ) {
			if ( $seq !== $at ) {
				return self::concurrent();
			}
			$text  = AB_MCP_Protected_Store::get( 'reports', $gid, $parsed['name'], $parsed['sha'] );
			$close = is_string( $text ) ? json_decode( $text, true, 64 ) : null;
			if ( ! is_array( $close ) || self::FORMAT !== ( $close['format'] ?? null ) || $seq !== ( $close['seq'] ?? null ) ) {
				return self::concurrent();
			}
			$out[] = $close;
			++$at;
		}
		return $out;
	}

	/**
	 * The lines of the production inventory of a proposal.
	 *
	 * @param array $found find().
	 * @return array|WP_Error lines, b9.
	 */
	private static function live( array $found ) {
		$lines = array();
		$b9    = 0;
		foreach ( $found['parts'] as $part ) {
			$b9 += (int) $part['b9'];
			foreach ( $part['files'] as $file ) {
				$text = AB_MCP_Protected_Store::get( 'reports', $found['gid'], $file['name'], $file['sha'] );
				if ( is_wp_error( $text ) ) {
					return $text;
				}
				$list = json_decode( $text, true, 128 );
				if ( ! is_array( $list ) || ! is_array( $list['data'] ?? null ) ) {
					return self::concurrent();
				}
				foreach ( $list['data'] as $line ) {
					$lines[] = $line;
				}
			}
		}
		return array(
			'lines' => $lines,
			'b9'    => $b9,
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
			'entries'     => $entries,
			'next_after'  => $page['more'] ? $last : null,
			'next'        => 'Show the entries to the person; nothing is paired yet. To pair, call wp_site_pairs with action=confirm, this proposal_id and the entries the person chose: select (entry numbers), categories (all equal, differs or realign entries, after the number in after) or choices (for an ambiguous entry: its staging_ref and live_ref). equal is paired as active, differs and an ambiguous pair of other content as unclear (the report then asks which side wins), realign makes the states of both sides now the new bases.' . ( $page['more'] ? ' More entries: call again with action=propose, this proposal_id and after=' . $last . '.' : '' ),
		);
	}

	/**
	 * An entry as an answer shows it: without the hashes.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function shown( array $entry ) {
		foreach ( array( 'staging', 'live' ) as $side ) {
			if ( isset( $entry[ $side ] ) && is_array( $entry[ $side ] ) ) {
				unset( $entry[ $side ]['hash'] );
			}
		}
		if ( isset( $entry['candidates'] ) ) {
			foreach ( $entry['candidates'] as $side => $list ) {
				foreach ( $list as $i => $one ) {
					unset( $entry['candidates'][ $side ][ $i ]['hash'] );
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
	 * sides: the target is the staging hash only where nothing maps it (no
	 * override, no post or term named by its id, no possible reference).
	 *
	 * @param array      $pair    Pair.
	 * @param array|null $staging Its staging line.
	 * @param array|null $live    Its production line.
	 * @return array live, staging, target (SHA-256 or null).
	 */
	public static function canon_forms( array $pair, $staging, $live ) {
		$hash   = static function ( $line ) {
			return is_array( $line ) && isset( $line['hash'] ) && is_string( $line['hash'] ) && ! empty( $line['complete'] ) && ! isset( $line['held'] ) ? $line['hash'] : null;
		};
		$mapped = false;
		if ( is_array( $staging ) ) {
			foreach ( isset( $staging['refs'] ) ? $staging['refs'] : array() as $ref ) {
				if ( isset( $ref['space'] ) && in_array( $ref['space'], array( 'post', 'term' ), true ) ) {
					$mapped = true;
				}
			}
			$mapped = $mapped || ! empty( $staging['possible'] );
		}
		$s = $hash( $staging );
		return array(
			'live'    => $hash( $live ),
			'staging' => $s,
			'target'  => null === $pair['override'] && ! $mapped ? $s : null,
		);
	}

	/* ------------------------------------------------------------ match */

	/**
	 * The proposal from the lines of both sides (no database).
	 *
	 * @param array[]  $staging  Lines of the staging side (a held one with 'held').
	 * @param array[]  $live     Lines of the production side (a held one with 'held').
	 * @param array[]  $pairs    The pairs of the source (AB_MCP_Sync_Pairs::decode()).
	 * @param callable $complete function( kind, subtype ): bool, whether the staging side is complete.
	 * @return array entries (numbered), counts, paired, held (staging, live), subtypes.
	 */
	public static function match( array $staging, array $live, array $pairs, callable $complete ) {
		$sides = array(
			's' => self::items( $staging, 's' ),
			'l' => self::items( $live, 'l' ),
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
			$si = $by['s'][ $pair['kind'] . "\0" . AB_MCP_Sync_Canon::encode( $pair['staging_ref'] ) ] ?? null;
			$li = $by['l'][ $pair['kind'] . "\0" . AB_MCP_Sync_Canon::encode( $pair['live_ref'] ) ] ?? null;
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
				if ( null === $item['key'] ) {
					$entries[] = self::alone( $item, 'not_compared', self::why_unread( $item ) );
					continue;
				}
				if ( ! AB_MCP_Sync_Pairs::valid_ref( $item['ref'] ) && null === $item['held'] ) {
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
		foreach ( $sides['l'] as $item ) {
			$subtypes[ $item['kind'] . "\0" . $item['subtype'] ] = true;
		}
		foreach ( $sides['s'] as $item ) {
			$subtypes[ $item['kind'] . "\0" . $item['subtype'] ] = true;
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
	 * The objects of one side, each with its identity key and its path.
	 *
	 * @param array[] $lines Lines.
	 * @param string  $side  s or l.
	 * @return array[]
	 */
	private static function items( array $lines, $side ) {
		$posts = array();
		$menus = array();
		foreach ( $lines as $line ) {
			$id = isset( $line['identity'] ) && is_array( $line['identity'] ) ? $line['identity'] : array();
			if ( 'post' === $line['kind'] && is_string( $line['ref'] ) && isset( $id['post_name'] ) ) {
				$posts[ $line['ref'] ] = array(
					'name'   => $id['post_name'],
					'parent' => self::parent_of( $line ),
				);
			}
			if ( 'menu' === $line['kind'] && is_string( $line['ref'] ) && isset( $id['slug'] ) ) {
				$menus[ $line['ref'] ] = $id['slug'];
			}
		}
		$out = array();
		foreach ( $lines as $line ) {
			$identity = isset( $line['identity'] ) && is_array( $line['identity'] ) ? $line['identity'] : array();
			$kind     = (string) $line['kind'];
			$locks    = array();
			foreach ( isset( $line['locks'] ) && is_array( $line['locks'] ) ? $line['locks'] : array() as $lock ) {
				$locks[] = array(
					'key'   => $lock['key'] ?? null,
					'stage' => $lock['stage'] ?? null,
				);
			}
			$out[] = array(
				'side'        => $side,
				'kind'        => $kind,
				'ref'         => $line['ref'],
				'ref_key'     => AB_MCP_Sync_Canon::encode( $line['ref'] ),
				'subtype'     => (string) ( $identity['post_type'] ?? ( $identity['taxonomy'] ?? '' ) ),
				'identity'    => $identity,
				'status'      => (string) ( $line['status'] ?? '' ),
				'hash'        => isset( $line['hash'] ) && is_string( $line['hash'] ) ? $line['hash'] : null,
				'complete'    => ! empty( $line['complete'] ),
				'held'        => isset( $line['held'] ) ? (string) $line['held'] : null,
				'locks'       => $locks,
				'reason_code' => $line['reason_code'] ?? null,
				'target'      => $line['target'] ?? null,
				'key'         => self::identity_key( $kind, $identity, $menus ),
				'path'        => 'post' === $kind ? self::path( $line, $posts ) : '',
			);
		}
		return $out;
	}

	/**
	 * The key an object is matched by: its kind and what names it apart from
	 * its id (concept section 4); null where the identity was not read.
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
				$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null );
				break;
			case 'term':
			case 'menu':
				$parts = array( $identity['taxonomy'] ?? null, $identity['slug'] ?? null );
				break;
			case 'menu_item':
				$slugs = array();
				foreach ( isset( $identity['menus'] ) && is_array( $identity['menus'] ) ? $identity['menus'] : array() as $menu ) {
					$slugs[] = isset( $menus[ (string) $menu ] ) ? AB_MCP_Sync_Canon::encode( $menus[ (string) $menu ] ) : '?';
				}
				sort( $slugs, SORT_STRING );
				$parts = array( $slugs, $identity['type'] ?? null, $identity['object'] ?? null, $identity['position'] ?? null );
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
	 * The path of a post: the names of its parents from the top, as far as
	 * the side names them.
	 *
	 * @param array $line  Line.
	 * @param array $posts Post ref => name, parent of the same side.
	 * @return string
	 */
	private static function path( array $line, array $posts ) {
		$names  = array();
		$parent = self::parent_of( $line );
		$seen   = array();
		while ( null !== $parent && count( $names ) < self::MAX_PATH ) {
			if ( isset( $seen[ $parent ] ) || ! isset( $posts[ $parent ] ) ) {
				$names[] = '?';
				break;
			}
			$seen[ $parent ] = true;
			$names[]         = $posts[ $parent ]['name'];
			$parent          = $posts[ $parent ]['parent'];
		}
		return AB_MCP_Sync_Canon::encode( array_reverse( $names ) );
	}

	/**
	 * The entries of one group of the same kind and identity.
	 *
	 * @param array[]  $s        Staging objects.
	 * @param array[]  $l        Production objects.
	 * @param callable $complete Completeness of the staging side.
	 * @return array[]
	 */
	private static function resolve( array $s, array $l, callable $complete ) {
		foreach ( array_merge( $s, $l ) as $item ) {
			if ( null !== $item['held'] ) {
				return array( self::held_entry( $s, $l ) );
			}
		}
		$out  = array();
		$left = array(
			's' => array(),
			'l' => array(),
		);
		$by_path = array();
		foreach ( $s as $item ) {
			$by_path[ $item['path'] ]['s'][] = $item;
		}
		foreach ( $l as $item ) {
			$by_path[ $item['path'] ]['l'][] = $item;
		}
		ksort( $by_path, SORT_STRING );
		foreach ( $by_path as $sub ) {
			$ss = isset( $sub['s'] ) ? $sub['s'] : array();
			$ll = isset( $sub['l'] ) ? $sub['l'] : array();
			if ( 1 === count( $ss ) && 1 === count( $ll ) ) {
				$out[] = self::pair_entry( $ss[0], $ll[0] );
				continue;
			}
			// An equal hash that is unique on both sides of the subgroup.
			$hs = array_count_values( array_filter( array_column( $ss, 'hash' ), 'is_string' ) );
			$hl = array_count_values( array_filter( array_column( $ll, 'hash' ), 'is_string' ) );
			$by = array();
			foreach ( $ll as $j => $item ) {
				if ( null !== $item['hash'] && 1 === ( $hl[ $item['hash'] ] ?? 0 ) && 1 === ( $hs[ $item['hash'] ] ?? 0 ) ) {
					$by[ $item['hash'] ] = $j;
				}
			}
			$used = array();
			foreach ( $ss as $item ) {
				if ( null !== $item['hash'] && isset( $by[ $item['hash'] ] ) ) {
					$used[ $by[ $item['hash'] ] ] = true;
					$out[]                         = self::pair_entry( $item, $ll[ $by[ $item['hash'] ] ] );
					continue;
				}
				$left['s'][] = $item;
			}
			foreach ( $ll as $j => $item ) {
				if ( ! isset( $used[ $j ] ) ) {
					$left['l'][] = $item;
				}
			}
		}
		if ( 1 === count( $left['s'] ) && 1 === count( $left['l'] ) ) {
			$out[] = self::pair_entry( $left['s'][0], $left['l'][0] );
		} elseif ( array() !== $left['s'] && array() !== $left['l'] ) {
			$out[] = self::ambiguous_entry( $left['s'], $left['l'] );
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
		return self::alone( $item, 'not_compared', 'The inventory of the staging site does not hold every object of this kind (it was read across packages, or objects were withheld from the account that made it), so an object of this site without a counterpart is not called only live. Make the inventory again on the staging site with an account that may read every object (wp_site_export), take it over here, then propose again.' );
	}

	/**
	 * The entry of one staging object and one production object of the same
	 * identity: equal (both hashes, both read whole, the same hash), differs,
	 * or not compared where a side has no hash.
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
		$equal = $s['complete'] && $l['complete'] && hash_equals( $s['hash'], $l['hash'] );
		return self::entry( $equal ? 'equal' : 'differs', $s, $l ) + array( 'confirm' => $equal ? 'active' : 'unclear' );
	}

	/**
	 * The entry of a group with more than one candidate on a side.
	 *
	 * @param array[] $s Staging objects.
	 * @param array[] $l Production objects.
	 * @return array
	 */
	private static function ambiguous_entry( array $s, array $l ) {
		$entry               = self::entry( 'ambiguous', $s[0], $l[0] );
		$entry['staging']    = null;
		$entry['live']       = null;
		$entry['candidates'] = array(
			'staging' => array_map( array( __CLASS__, 'side' ), $s ),
			'live'    => array_map( array( __CLASS__, 'side' ), $l ),
		);
		$entry['confirm']    = 'choice';
		return $entry;
	}

	/**
	 * The entry of a group that holds an object a credential holds: by the
	 * SHA-256 of each ref and its code, never by a name.
	 *
	 * @param array[] $s Staging objects.
	 * @param array[] $l Production objects.
	 * @return array
	 */
	private static function held_entry( array $s, array $l ) {
		$one   = array() === $s ? $l[0] : $s[0];
		$named = static function ( array $item ) {
			return array(
				'ref_sha256' => hash( 'sha256', $item['ref_key'] ),
				'held'       => $item['held'],
			);
		};
		return array(
			'category' => 'held',
			'kind'     => $one['kind'],
			'subtype'  => '',
			'staging'  => array_map( $named, $s ),
			'live'     => array_map( $named, $l ),
			'reason'   => 'A name of this object or of its counterpart holds a credential by the rules of this site (held: credential_name, credential_field or unreadable_name), so it is shown by the SHA-256 of its ref only and is not paired. Take it over by hand.',
			'sort'     => hash( 'sha256', $one['key'] ),
		);
	}

	/**
	 * The entry of a pair in the state unclear or residual: its new alignment
	 * with the bases of both sides now, where both objects are there and
	 * compared.
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
			'subtype'  => null !== $s ? $s['subtype'] : ( null !== $l ? $l['subtype'] : '' ),
			'staging'  => null === $s ? array( 'ref' => $pair['staging_ref'] ) : self::side( $s ),
			'live'     => null === $l ? array( 'ref' => $pair['live_ref'] ) : self::side( $l ),
			'pair'     => array(
				'id'       => (int) $pair['id'],
				'state'    => $pair['state'],
				'snapshot' => hash( 'sha256', self::pair_snapshot( $pair ) ),
			),
			'sort'     => sprintf( '%020d', (int) $pair['id'] ),
		);
		$ready = null !== ( $s['hash'] ?? null ) && null !== ( $l['hash'] ?? null ) && null === $s['held'] && null === $l['held'];
		if ( ! $ready ) {
			$entry['confirm'] = null;
			$entry['reason']  = null === $l
				? ( 'residual' === $pair['state'] ? 'Rest removed: the object is no longer on this site.' : 'The object is no longer on this site.' ) . ' A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.'
				: ( null === $s ? 'The object is not in the inventory of the staging site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.' : 'A side of this pair is not compared (no hash or held by a credential), so it is not aligned anew. Take it over by hand, or call wp_site_pairs with action=discard and its pair id.' );
			return $entry;
		}
		$entry['confirm'] = 'active';
		if ( ! ( $s['complete'] && $l['complete'] && hash_equals( $s['hash'], $l['hash'] ) ) ) {
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
	 * One side of an entry: ref, identity, status, locks, whether it was read
	 * whole, its hash (stored, not shown).
	 *
	 * @param array $item Object.
	 * @return array
	 */
	private static function side( array $item ) {
		$out = array(
			'ref'      => $item['ref'],
			'identity' => $item['identity'],
			'status'   => $item['status'],
			'complete' => $item['complete'],
			'hash'     => $item['hash'],
		);
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
	private static function why_no_hash( array $item ) {
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
	private static function why_unread( array $item ) {
		return 's' === $item['side']
			? 'The staging site could not read this row, so it has no identity to match. Make the inventory again on the staging site (wp_site_export), take it over here, then propose again.'
			: 'This site could not read this row, so it has no identity to match. Try again; if it keeps failing, the database error log names the cause.';
	}

	/**
	 * Lines by kind NUL the encoded ref.
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,array>
	 */
	private static function by_ref( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			$out[ $line['kind'] . "\0" . ( is_string( $line['ref'] ) ? $line['ref'] : AB_MCP_Sync_Canon::encode( $line['ref'] ) ) ] = $line;
		}
		return $out;
	}

	/* ------------------------------------------------------------ files */

	/**
	 * Write one file of a proposal, named by its prefix, number and SHA-256.
	 *
	 * @param string   $gid    Group.
	 * @param string   $prefix h, c, d, e or x.
	 * @param int|null $seq    Number (c, d, e).
	 * @param mixed    $data   What it holds (d and e: a list, wrapped).
	 * @return array|WP_Error name, sha.
	 */
	private static function put( $gid, $prefix, $seq, $data ) {
		if ( in_array( $prefix, array( 'd', 'e' ), true ) ) {
			$data = array(
				'data'   => $data,
				'format' => self::FORMAT,
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
	 * @param string            $gid    Group.
	 * @param array<string,int> $files  Files.
	 * @param string            $prefix h or x.
	 * @return array|null|WP_Error
	 */
	private static function read_one( $gid, array $files, $prefix ) {
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
			return self::concurrent();
		}
		$text = AB_MCP_Protected_Store::get( 'reports', $gid, $found[0]['name'], $found[0]['sha'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = json_decode( $text, true, 64 );
		return is_array( $data ) ? $data : self::concurrent();
	}

	/**
	 * The entries of one stored part.
	 *
	 * @param string $gid  Group.
	 * @param array  $part name, sha.
	 * @return array[]|WP_Error
	 */
	private static function read_part( $gid, array $part ) {
		$text = AB_MCP_Protected_Store::get( 'reports', $gid, $part['name'], $part['sha'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$list = json_decode( $text, true, 128 );
		return is_array( $list ) && is_array( $list['data'] ?? null ) ? $list['data'] : self::concurrent();
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
		if ( 1 !== preg_match( '/^([hx])([a-z2-7]{52})\z|^([cde])([0-9]{4})-([a-z2-7]{52})\z/', $name, $m ) ) {
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
		return new WP_Error( 'ab_mcp_sync_pairs_unknown', 'This site holds no proposal of that id (a proposal is kept a day at most). Propose again: call wp_site_pairs with action=propose and the packages of the inventory of the staging site.' );
	}

	/**
	 * @return WP_Error A proposal of another account or connection.
	 */
	private static function foreign() {
		return new WP_Error( 'ab_mcp_sync_pairs_foreign', 'This proposal belongs to another account or connection, and only the one that made it goes on with it or confirms it. Propose again over this connection: call wp_site_pairs with action=propose and the packages of the inventory of the staging site.' );
	}

	/**
	 * @return WP_Error Packages of more than one series.
	 */
	private static function mixed() {
		return new WP_Error( 'ab_mcp_sync_pairs_series', 'The packages are not of one inventory: an object is in two of them, or a kind read whole in one package has objects in another (packages of two exports). Pass the packages of one series only: the first wp_site_export without a cursor and each one after with the cursor of the one before, all taken over here; then call again.' );
	}

	/**
	 * @return WP_Error The files of a proposal are not as one call wrote them.
	 */
	private static function concurrent() {
		return new WP_Error( 'ab_mcp_sync_pairs_parts', 'The files of this proposal are not as one call after the other wrote them (two calls went on with it at once, or a file was changed in the protected store). Propose again: call wp_site_pairs with action=propose and the packages of the inventory of the staging site, and go on with one call at a time.' );
	}
}
