<?php
/**
 * The object pairs: which object of the live site belongs to which object
 * of a staging source (Z8, decisions 3, 10, 13, 14 and 23 of the plan of
 * 01.11.2026). One row of AB_MCP_Sync_Schema's table pairs per pair.
 *
 * A PAIR: source_id (the staging source), kind (the pair kind, such as
 * post, term, menu, menu_item, widget_area, custom_css, option,
 * attachment), live_ref and staging_ref (the object in the ID space of
 * each side), state, override (a confirmed target that differs from the
 * staging version, such as {"post_status":"draft"}), canon (the version
 * of the canonical form the bases are hashes of), base_live and
 * base_staging (the SHA-256 of each side at the base, each in its own ID
 * space; base_live is the hash of the target), base_extra (bounded JSON:
 * the entries of a menu, the instances of a widget area with the mapping
 * staging instance -> live number), base_time, base_origin (proposal,
 * clone, push, revert), last_run. A kind whose objects live in more than
 * one ID space keeps them apart by its pair kinds (a menu term and a menu
 * entry are menu and menu_item): a ref is unique per (source_id, kind).
 *
 * STATES. active; unclear (the same identity with other content, a canon
 * change with different forms, a part left unclear by a run); residual
 * (made by a run and left standing after its way back or an error: rule 0
 * of the report, decision 14 — the pair keeps its mapping and nothing is
 * taken over for it); deleted (taken over as deleted: not reached in Z8,
 * where no object is deleted, decision 22). Changes of state go only along
 * TRANSITIONS; out of unclear and residual only realign(), the confirmed
 * new alignment of wp_site_pairs confirm.
 *
 * WRITES are conditional: every change names the snapshot hash of the row
 * it read (snapshot_hash()) and is written with that row's exact bytes in
 * the WHERE clause, so a pair someone else changed in between is not
 * overwritten (named error ab_mcp_staging_pair_changed). A second pair on
 * the same (source_id, kind, live_ref) or (source_id, kind, staging_ref)
 * is refused as ab_mcp_staging_pair_conflict, by the lookup before and by
 * the unique keys of the table during the insert. Refs are compared by
 * their bytes, not by the collation of the table.
 *
 * THE PAIR OF A UNIT (Z8b; plan 3.1, Z38 and Z44): a plugin, a theme, the
 * theme that runs and the further kinds of PARTS. Its first bases, and their
 * confirmed new alignment, are set only by set_unit_base(): every part from
 * the lines of both sides read whole, with its mode, and with what confirms
 * it (the token of wp_site_pairs, or a step that made the unit here, origin
 * push). create(), realign() and change_canon() refuse a unit; rebase() moves
 * a part of an active pair on, the one way of the steps (Z38).
 *
 * Nothing here works on a network or without the tables
 * (ab_mcp_staging_unavailable, AB_MCP_Sync_Schema::status()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-schema.php';
require_once __DIR__ . '/class-sync-kinds.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * The calls of the pairs to the database that read a pair and write its
 * bases (Z8b branch 2, plan 8.3: the bases of the pairs): the real ones.
 */
final class AB_MCP_Sync_Pairs_Calls {

	/**
	 * One row.
	 *
	 * @param string $sql A SELECT built from prepared parts.
	 * @return array|null|false The row, none, or false where the database answered with an error.
	 */
	public function row( $sql ) {
		global $wpdb;
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- built from prepared parts by the caller.
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One statement that writes the row of a pair (named apart from
	 * $wpdb->query(), which SyncSafeCallsTest counts as a call of its own).
	 *
	 * @param string $sql An UPDATE built from prepared parts.
	 * @return int|false The rows it changed, or false.
	 */
	public function update_row( $sql ) {
		global $wpdb;
		$done = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- built from prepared parts by the caller.
		return false === $done ? false : (int) $done;
	}

	/**
	 * One new row of a pair (Z8b branch 2b: the rows of the pairs a
	 * confirmation makes).
	 *
	 * @param string $table   The table of the pairs.
	 * @param array  $row     Column => value.
	 * @param array  $formats The format of each column.
	 * @return int|false The id of the row, or false where the database refused it.
	 */
	public function insert_row( $table, array $row, array $formats ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( $table, $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the table of the pairs is ours.
		$wpdb->suppress_errors( $suppress );
		return false === $inserted ? false : (int) $wpdb->insert_id;
	}
}

/**
 * What confirms the first base of the pair of a unit (Z8b, plan 3.1, Z44),
 * handed to AB_MCP_Sync_Pairs::set_unit_base(): either the operations of a
 * confirmation a person gave with the token of wp_site_pairs (made only by
 * AB_MCP_Sync_Pairs_Tool::confirm(), from the plan the gate checked the
 * token against), or the parts a step of a run that made the unit on this
 * site wrote and checked (origin push; the engine brings it with branch 5).
 * Nothing else makes one: the constructor is private, and a guard test
 * holds where the two makers are called.
 */
final class AB_MCP_Sync_Unit_Proof {

	/** @var string proposal or push. */
	private $origin;

	/** @var string[] The operations confirmed, each in the spelling of AB_MCP_Sync_Canon::encode(). */
	private $ops;

	/** @var string[] The parts the step wrote and checked (push). */
	private $written;

	/**
	 * @param string   $origin  proposal or push.
	 * @param string[] $ops     Operations, encoded.
	 * @param string[] $written Parts written and checked.
	 */
	private function __construct( $origin, array $ops, array $written ) {
		$this->origin  = $origin;
		$this->ops     = $ops;
		$this->written = $written;
	}

	/**
	 * The confirmation of a person: the operations of the plan whose state
	 * the token was checked against.
	 *
	 * @param array[] $ops The operations of that plan.
	 * @return self
	 */
	public static function confirmed( array $ops ) {
		$encoded = array();
		foreach ( $ops as $op ) {
			$encoded[] = AB_MCP_Sync_Canon::encode( $op );
		}
		return new self( 'proposal', $encoded, array() );
	}

	/**
	 * A step of a run made the unit on this site and wrote and checked these
	 * parts of it.
	 *
	 * @param string[] $written The parts written and checked.
	 * @return self
	 */
	public static function pushed( array $written ) {
		return new self( 'push', array(), array_values( array_map( 'strval', $written ) ) );
	}

	/**
	 * @return string proposal or push.
	 */
	public function origin() {
		return $this->origin;
	}

	/**
	 * Whether this confirmation holds this operation as it is.
	 *
	 * @param array $op An operation.
	 * @return bool
	 */
	public function covers( array $op ) {
		return 'proposal' === $this->origin && in_array( AB_MCP_Sync_Canon::encode( $op ), $this->ops, true );
	}

	/**
	 * @return string[] The parts the step wrote and checked.
	 */
	public function written() {
		return $this->written;
	}
}

/**
 * Class AB_MCP_Sync_Pairs
 */
final class AB_MCP_Sync_Pairs {

	/** The calls to the database that read a pair and write its bases (the seam); only the tests put another object here. */
	private static $naht_sync_pairs = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Pairs_Calls
	 */
	private static function calls() {
		return self::$naht_sync_pairs ?? new AB_MCP_Sync_Pairs_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_pairs = null;
	}

	/** How deep the calls of transaction() are now (0: none open). */
	private static $depth = 0;

	/** The states of a pair. */
	const STATES = array( 'active', 'deleted', 'unclear', 'residual' );

	/**
	 * The changes of state set_state() makes. Not listed: anything out of
	 * residual and unclear back to active (realign() only), anything into or
	 * out of deleted (Z8d).
	 */
	const TRANSITIONS = array(
		'active'   => array( 'unclear', 'residual' ),
		'unclear'  => array( 'residual' ),
		'residual' => array(),
		'deleted'  => array(),
	);

	/** Where the bases of a pair come from. */
	const ORIGINS = array( 'proposal', 'clone', 'push', 'revert' );

	/** At most this many bytes of base_extra as stored. */
	const MAX_EXTRA = 262144;

	/** At most this deep nested arrays in base_extra. */
	const MAX_EXTRA_DEPTH = 8;

	/** At most this many bytes of override as stored. */
	const MAX_OVERRIDE = 4096;

	/** At most this many characters in a ref (the column). */
	const MAX_REF = 191;

	/** Pairs per page of page() at most. */
	const PER_PAGE = 200;

	/** The format of a snapshot. */
	const SNAPSHOT_FORMAT = 1;

	/**
	 * How the base of a part of the pair of a unit is set (Z44), with the
	 * origin each belongs to: equal (the part is the same on both sides now,
	 * which is its base on both); live_base (Frage F, b: the state of this
	 * site now, read whole, is the base of both sides, so the difference is a
	 * change on staging); keep_apart (Frage F, c: the state of each side now
	 * is its own base, the difference kept); push (a unit a step of a run
	 * made on this site, as written and checked).
	 */
	const UNIT_MODES = array(
		'equal'      => 'proposal',
		'live_base'  => 'proposal',
		'keep_apart' => 'proposal',
		'push'       => 'push',
	);

	/** The columns a snapshot holds: all but id and updated. */
	const SNAPSHOT_COLUMNS = array( 'base_extra', 'base_live', 'base_origin', 'base_staging', 'base_time', 'canon', 'kind', 'last_run', 'live_ref', 'override', 'source_id', 'staging_ref', 'state' );

	/** The columns that hold numbers. */
	const INT_COLUMNS = array( 'id', 'base_time', 'last_run', 'updated' );

	/**
	 * The parts of the pair of a unit (Z8b, E2), by its kind: each part has
	 * a base of its own and is moved on only by the step that wrote and
	 * checked it (rebase() with the part, Z38). The first part keeps its
	 * bases in base_live and base_staging, any further one in base_extra
	 * (parts → part → live, staging). A kind not listed is an object of Z8
	 * without parts.
	 */
	const PARTS = array(
		'plugin'       => array( 'files', 'activation' ),
		'theme'        => array( 'files' ),
		'mu_plugin'    => array( 'files' ),
		'language'     => array( 'files' ),
		'font'         => array( 'files' ),
		'upload'       => array( 'files' ),
		'theme_choice' => array( 'activation' ),
	);

	/* ------------------------------------------------------------ read */

	/**
	 * One pair by its id.
	 *
	 * @param int $id Pair id.
	 * @return array|null|WP_Error
	 */
	public static function get( $id ) {
		$raw = self::raw( $id );
		return is_array( $raw ) ? self::decode( $raw ) : $raw;
	}

	/**
	 * The pair of a live object.
	 *
	 * @param string $source_id Source.
	 * @param string $kind      Pair kind.
	 * @param string $live_ref  Ref on the live site.
	 * @return array|null|WP_Error
	 */
	public static function find_live( $source_id, $kind, $live_ref ) {
		return self::find( $source_id, $kind, 'live_ref', $live_ref );
	}

	/**
	 * The pair of a staging object.
	 *
	 * @param string $source_id   Source.
	 * @param string $kind        Pair kind.
	 * @param string $staging_ref Ref on the staging site.
	 * @return array|null|WP_Error
	 */
	public static function find_staging( $source_id, $kind, $staging_ref ) {
		return self::find( $source_id, $kind, 'staging_ref', $staging_ref );
	}

	/**
	 * The pairs of a source and a kind, by id, a page at a time.
	 *
	 * @param string $source_id Source.
	 * @param string $kind      Pair kind.
	 * @param int    $after     The last id of the page before (0: from the start).
	 * @param int    $limit     At most this many (1 to PER_PAGE).
	 * @return array{pairs:array[],next:int|null}|WP_Error next is the $after of the next page.
	 */
	public static function page( $source_id, $kind, $after = 0, $limit = self::PER_PAGE ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$bad = self::check_fields(
			array(
				'source_id' => $source_id,
				'kind'      => $kind,
			)
		);
		if ( null !== $bad ) {
			return $bad;
		}
		global $wpdb;
		$limit = max( 1, min( self::PER_PAGE, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the values are prepared.
		$sql  = 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . self::exact( 'source_id', $source_id ) . ' AND ' . self::exact( 'kind', $kind ) . $wpdb->prepare( ' AND id > %d ORDER BY id ASC LIMIT %d', max( 0, (int) $after ), $limit + 1 );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared parts above.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return self::db_error();
		}
		$next = null;
		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
			$next = (int) $rows[ $limit - 1 ]['id'];
		}
		return array(
			'pairs' => array_map( array( __CLASS__, 'decode' ), $rows ),
			'next'  => $next,
		);
	}

	/**
	 * The pairs of a source over every kind, by id, a page at a time
	 * (wp_site_pairs list and the proposal, branch 6).
	 *
	 * @param string $source_id Source.
	 * @param int    $after     The last id of the page before (0: from the start).
	 * @param int    $limit     At most this many (1 to PER_PAGE).
	 * @return array{pairs:array[],next:int|null}|WP_Error next is the $after of the next page.
	 */
	public static function page_source( $source_id, $after = 0, $limit = self::PER_PAGE ) {
		$page = self::rows_of_source( $source_id, $after, $limit );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return array(
			'pairs' => array_map( array( __CLASS__, 'decode' ), $page['rows'] ),
			'next'  => $page['next'],
		);
	}

	/**
	 * page_source() for a page that shows the pairs with a form each (Z8,
	 * branch 12: «Discard» on the page «Staging»): each pair decoded, with
	 * the snapshot hash of the very row it was decoded from (snapshot_hash()
	 * of snapshot()), so that the form binds what the page shows and no
	 * second reading in between can put a newer state into it (Codex round 2
	 * of branch 12, A).
	 *
	 * @param string $source_id Source.
	 * @param int    $after     The last id of the page before (0: from the start).
	 * @param int    $limit     At most this many (1 to PER_PAGE).
	 * @return array{pairs:array[],next:int|null}|WP_Error Each pair with snapshot_hash.
	 */
	public static function page_source_shown( $source_id, $after = 0, $limit = self::PER_PAGE ) {
		$page = self::rows_of_source( $source_id, $after, $limit );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$pairs = array();
		foreach ( $page['rows'] as $raw ) {
			$snapshot = self::snapshot_of( $raw );
			$pairs[]  = self::decode( $raw ) + array( 'snapshot_hash' => is_wp_error( $snapshot ) ? null : self::snapshot_hash( $snapshot ) );
		}
		return array(
			'pairs' => $pairs,
			'next'  => $page['next'],
		);
	}

	/**
	 * One pair as page_source_shown() gives it, read once by its id, with
	 * the row as stored (raw), for an action on exactly that row
	 * (discard_row(); AB_MCP_Sync_Pairs_Tool::discard_as_shown()). Null
	 * where there is none.
	 *
	 * @param int $id Pair id.
	 * @return array|null|WP_Error The pair decoded, with snapshot_hash and raw.
	 */
	public static function shown_row( $id ) {
		$raw = self::raw( (int) $id );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		$snapshot = self::snapshot_of( $raw );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return self::decode( $raw ) + array(
			'snapshot_hash' => self::snapshot_hash( $snapshot ),
			'raw'           => $raw,
		);
	}

	/**
	 * End a pair as it was read (shown_row()): its row deleted only where it
	 * is still that row byte for byte, by one conditional DELETE, with no
	 * reading in between.
	 *
	 * @param array $raw The row as read.
	 * @return true|WP_Error ab_mcp_sync_pairs_changed where it is not that row any more.
	 */
	public static function discard_row( array $raw ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the values are prepared.
		$done = $wpdb->query( 'DELETE FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . self::where_row( $raw ) );
		if ( false === $done ) {
			return self::db_error();
		}
		return 1 === (int) $done ? true : self::changed();
	}

	/**
	 * The rows of a page of the pairs of a source, as stored.
	 *
	 * @param string $source_id Source.
	 * @param int    $after     The last id of the page before.
	 * @param int    $limit     At most this many.
	 * @return array{rows:array[],next:int|null}|WP_Error
	 */
	private static function rows_of_source( $source_id, $after, $limit ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$bad = self::check_fields( array( 'source_id' => $source_id ) );
		if ( null !== $bad ) {
			return $bad;
		}
		global $wpdb;
		$limit = max( 1, min( self::PER_PAGE, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the values are prepared.
		$sql  = 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . self::exact( 'source_id', $source_id ) . $wpdb->prepare( ' AND id > %d ORDER BY id ASC LIMIT %d', max( 0, (int) $after ), $limit + 1 );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared parts above.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return self::db_error();
		}
		$next = null;
		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
			$next = (int) $rows[ $limit - 1 ]['id'];
		}
		return array(
			'rows' => $rows,
			'next' => $next,
		);
	}

	/* ----------------------------------------------------------- write */

	/**
	 * Make a pair.
	 *
	 * @param array $pair source_id, kind, live_ref, staging_ref, canon,
	 *                    base_live, base_staging, base_origin; optional
	 *                    state (active, unclear or residual; default
	 *                    active), override, base_extra, base_time (default
	 *                    now), last_run.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function create( array $pair ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$extra = array_diff( array_keys( $pair ), self::SNAPSHOT_COLUMNS );
		if ( array() !== $extra ) {
			/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
			return self::invalid( reset( $extra ), sprintf( __( 'The field %s is not a field of a pair.', 'alphabridge-mcp' ), reset( $extra ) ) );
		}
		foreach ( array( 'source_id', 'kind', 'live_ref', 'staging_ref', 'canon', 'base_live', 'base_staging', 'base_origin' ) as $required ) {
			if ( ! array_key_exists( $required, $pair ) ) {
				/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
				return self::invalid( $required, sprintf( __( 'The field %s of the pair is required.', 'alphabridge-mcp' ), $required ) );
			}
		}
		// The pair of a unit takes its first bases from set_unit_base()
		// only (Z44): never from bases a caller names.
		if ( self::is_unit( $pair['kind'] ) ) {
			return self::unit_only( 'create' );
		}
		$pair += array(
			'state'      => 'active',
			'override'   => null,
			'base_extra' => null,
			'base_time'  => time(),
			'last_run'   => null,
		);
		return self::insert_pair( $pair );
	}

	/**
	 * Write a new pair: its fields checked, no second pair on one of the
	 * unique keys (by the lookup before and by the keys during the insert).
	 *
	 * @param array $pair Every field of a pair.
	 * @return array|WP_Error The pair as stored.
	 */
	private static function insert_pair( array $pair ) {
		$row = self::encode( $pair );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$conflict = self::conflict( $row );
		if ( null !== $conflict ) {
			return $conflict;
		}
		$row['updated'] = time();
		$formats        = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s';
		}
		$id = self::calls()->insert_row( AB_MCP_Sync_Schema::table( 'pairs' ), $row, $formats );
		if ( false === $id ) {
			// Written by another request in between: the unique keys refused it.
			$conflict = self::conflict( $row );
			return null !== $conflict ? $conflict : self::db_error();
		}
		return self::get( (int) $id );
	}

	/**
	 * Change the state of a pair along TRANSITIONS.
	 *
	 * @param int    $id       Pair id.
	 * @param string $to       New state.
	 * @param string $expected Snapshot hash of the pair as the caller read it.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function set_state( $id, $to, $expected ) {
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		if ( $raw['state'] === $to ) {
			return self::decode( $raw );
		}
		if ( ! self::transition_allowed( $raw['state'], $to ) ) {
			return self::transition_refused( $raw['state'], $to );
		}
		return self::swap( $raw, array( 'state' => $to ) );
	}

	/**
	 * New bases for an active pair: after a step that wrote it, or a canon
	 * change. A residual or unclear pair keeps its bases until realign().
	 *
	 * THE PAIR OF A UNIT (Z8b, E2; plan 3.1, Z38, the one place): its parts
	 * (PARTS) have bases of their own, and only the step that wrote and
	 * checked a part moves it on. Here such a pair takes new bases only for
	 * the one part named, from the kind responsible for that part (contract
	 * A: AB_MCP_Sync_Kinds::OWNERSHIP gives the key part:<kind>:<part> to it,
	 * and it is the very engine registered under its name, which the registry
	 * takes only with that key in responsibility()), on the condition that the pair is still as the
	 * step read it ($expected); the bases of every other part stay byte for
	 * byte. Without a part, or with a part of a kind that has none, nothing
	 * is written.
	 *
	 * @param int                   $id       Pair id.
	 * @param array                 $values   canon, base_live, base_staging, base_origin;
	 *                                        optional override, base_extra, last_run (left
	 *                                        as they are when not given). For the part of a
	 *                                        unit: canon, base_live, base_staging,
	 *                                        base_origin, optional last_run.
	 * @param string                $expected Snapshot hash of the pair as the caller read it.
	 * @param string|null           $part     The part of a unit (files, activation); null for an object.
	 * @param AB_MCP_Sync_Kind|null $kind     The engine that wrote and checked the part.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function rebase( $id, array $values, $expected, $part = null, $kind = null ) {
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		if ( 'active' !== $raw['state'] ) {
			return new WP_Error(
				'ab_mcp_staging_pair_not_active',
				/* translators: %s: the state of the pair, as the tools name it (unclear, residual, …). */
				sprintf( __( 'Only an active pair takes new bases; a pair in the state %s keeps them until a person confirms a new alignment. Use realign() for that confirmation (wp_site_pairs confirm).', 'alphabridge-mcp' ), $raw['state'] ),
				array( 'state' => $raw['state'] )
			);
		}
		$parts = self::PARTS[ $raw['kind'] ] ?? null;
		if ( null === $parts && null === $part ) {
			$set = self::bases( $values, array( 'canon', 'base_live', 'base_staging', 'base_origin' ), array( 'override', 'base_extra', 'last_run' ) );
			return is_wp_error( $set ) ? $set : self::swap( $raw, $set );
		}
		if ( null === $parts || ! in_array( $part, $parts, true ) ) {
			return new WP_Error(
				'ab_mcp_staging_pair_part',
				/* translators: 1: the part named (files, activation), 2: the kind of the pair. */
				sprintf( __( 'The pair of kind %2$s has no part %1$s whose bases could be moved on: a unit takes new bases part by part (files, activation), an object of the takeover without a part. Nothing was written. Pass a part of the pair (AB_MCP_Sync_Pairs::PARTS), or none for an object, and call again.', 'alphabridge-mcp' ), is_string( $part ) ? $part : '', $raw['kind'] ),
				array( 'part' => $part )
			);
		}
		if ( ! self::responsible( $kind, (string) $raw['kind'], (string) $part ) ) {
			return new WP_Error(
				'ab_mcp_staging_pair_part_owner',
				/* translators: 1: the part (files, activation), 2: the kind of the pair. */
				sprintf( __( 'Only the step that wrote and checked the part %1$s of a pair of kind %2$s moves its bases on: the kind responsible for it by AB_MCP_Sync_Kinds::OWNERSHIP, registered as such. Nothing was written. Pass the kind registered for the part, and call again.', 'alphabridge-mcp' ), (string) $part, $raw['kind'] ),
				array( 'part' => $part )
			);
		}
		$set = self::bases( $values, array( 'canon', 'base_live', 'base_staging', 'base_origin' ), array( 'last_run' ) );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		if ( $parts[0] !== $part ) {
			// A further part: its bases in base_extra, the columns of the
			// first part as they are.
			$extra = null === $raw['base_extra'] ? array() : json_decode( (string) $raw['base_extra'], true );
			if ( ! is_array( $extra ) ) {
				return self::invalid( 'base_extra', __( 'The field base_extra of the pair is not stored JSON of a pair.', 'alphabridge-mcp' ) );
			}
			$extra['parts'][ $part ] = array(
				'live'    => $set['base_live'],
				'staging' => $set['base_staging'],
			);
			$json = self::encode_json( 'base_extra', $extra );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			unset( $set['base_live'], $set['base_staging'] );
			$set['base_extra'] = $json;
		}
		return self::swap( $raw, $set );
	}

	/**
	 * Whether an engine is responsible for a part of the pair of a unit
	 * (contract A, responsibility()): the table gives the key
	 * part:<kind>:<part> with no stage to its name, and it is the engine
	 * registered under that name.
	 *
	 * @param mixed  $kind      The engine.
	 * @param string $pair_kind The kind of the pair.
	 * @param string $part      The part.
	 * @return bool
	 */
	public static function responsible( $kind, $pair_kind, $part ) {
		if ( ! $kind instanceof AB_MCP_Sync_Kind ) {
			return false;
		}
		// The registry took the engine only for exactly what the table gives
		// it (AB_MCP_Sync_Kinds::register()), so being the engine registered
		// under the name the table names is claiming the key.
		$answer = AB_MCP_Sync_Kinds::part( $pair_kind . ':' . $part );
		return 'z8' === $answer['status'] && $answer['kind'] === $kind->kind()
			&& AB_MCP_Sync_Kinds::get( $kind->kind() ) === $kind;
	}

	/**
	 * The bases of a part of the pair of a unit (decoded, decode()): the
	 * first part from base_live and base_staging, a further one from
	 * base_extra; null where the pair has no such part or no base of it.
	 *
	 * @param array  $pair The pair, decoded.
	 * @param string $part The part.
	 * @return array{live:string,staging:string}|null
	 */
	public static function part_bases( array $pair, $part ) {
		$parts = self::PARTS[ $pair['kind'] ?? '' ] ?? null;
		if ( null === $parts || ! in_array( $part, $parts, true ) ) {
			return null;
		}
		$base = $parts[0] === $part
			? array(
				'live'    => $pair['base_live'] ?? null,
				'staging' => $pair['base_staging'] ?? null,
			)
			: ( $pair['base_extra']['parts'][ $part ] ?? null );
		if ( ! is_array( $base ) || ! self::is_hash( $base['live'] ?? null ) || ! self::is_hash( $base['staging'] ?? null ) ) {
			return null;
		}
		return array(
			'live'    => (string) $base['live'],
			'staging' => (string) $base['staging'],
		);
	}

	/**
	 * The confirmed new alignment of a pair (wp_site_pairs confirm, decision
	 * 14): new bases of both sides, state active. The only way out of
	 * residual and unclear.
	 *
	 * @param int    $id        Pair id.
	 * @param array  $values    canon, base_live, base_staging; optional
	 *                          override, base_extra (left as they are when
	 *                          not given: the instance mapping of a widget
	 *                          area stays).
	 * @param string $expected  Snapshot hash of the pair the person confirmed.
	 * @param bool   $confirmed True: a person confirmed it (the token of the call).
	 * @return array|WP_Error The pair as stored.
	 */
	public static function realign( $id, array $values, $expected, $confirmed ) {
		if ( true !== $confirmed ) {
			return new WP_Error( 'ab_mcp_staging_pair_unconfirmed', __( 'A new alignment of a pair needs the confirmation of a person: pass true only for a call that carries the token of that confirmation (wp_site_pairs confirm).', 'alphabridge-mcp' ) );
		}
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		// A unit is aligned anew only part by part, each with its mode and
		// from the lines of both sides read whole (set_unit_base(), Z44):
		// never both columns of a caller with the further parts left as
		// they were.
		if ( self::is_unit( $raw['kind'] ) ) {
			return self::unit_only( 'realign' );
		}
		if ( 'deleted' === $raw['state'] ) {
			return self::transition_refused( 'deleted', 'active' );
		}
		$set = self::bases( $values, array( 'canon', 'base_live', 'base_staging' ), array( 'override', 'base_extra' ) );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$set['state']       = 'active';
		$set['base_origin'] = 'proposal';
		return self::swap( $raw, $set );
	}

	/**
	 * The first bases of the pair of a unit, or its confirmed new alignment
	 * (Z8b, plan 3.1, Z44: the one place that sets them). Every part of the
	 * kind (PARTS), each with its mode (UNIT_MODES), from the lines of the
	 * environment of both sides:
	 *  - the files only from a manifest read whole (complete, a folder or a
	 *    file, its hash; no lock, no name held, not AlphaBridge itself), which
	 *    main files run only from the whole set of them (the main files and
	 *    active_plugins read), the theme that runs only from both its rows
	 *    read: by AB_MCP_Sync_Decision::part_hash(), the hash the report
	 *    compares (unit_part());
	 *  - equal only where the part is the same on both sides, live_base and
	 *    keep_apart only where it differs (Frage F, b and c), push for a part
	 *    the step wrote only where this site has it as staging has it; a part
	 *    the step did not write begins where this site stands after it, read
	 *    whole;
	 *  - a line of each side, of this unit by its name: a unit without a
	 *    counterpart gets no base made up for the side it is missing on, so
	 *    «only live, no pair» stays apart from a removal on staging, which
	 *    only an active pair tells;
	 *  - the values the operation names are the ones the lines give in its
	 *    modes, and a confirmation holds the operation as it is
	 *    (AB_MCP_Sync_Unit_Proof): identity, parts, modes, values;
	 *  - the same name on both sides (a unit renamed is another unit), one a
	 *    pair holds (MAX_REF), never one held by its SHA-256;
	 *  - origin proposal only with the confirmation of a person; push only
	 *    for a unit a step made on this site, without a pair before, its
	 *    files written and checked;
	 *  - a new alignment only of a pair in the state unclear or residual, as
	 *    the person saw it (expected), every part anew and the other keys of
	 *    base_extra as they were (an active pair moves on part by part
	 *    through rebase(), Z38, which makes no first base).
	 * create() and realign() refuse a unit, and change_canon() leaves it
	 * (unit_only()).
	 *
	 * @param string                      $source_id The source.
	 * @param array                       $op        kind, ref, parts (part => mode, base_live,
	 *                                               base_staging), lines (staging, live), pair_id and
	 *                                               expected (a new alignment; none: a new pair),
	 *                                               last_run (push).
	 * @param AB_MCP_Sync_Unit_Proof|null $proof     What confirms it.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function set_unit_base( $source_id, array $op, $proof ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$kind = $op['kind'] ?? null;
		if ( ! self::is_unit( $kind ) ) {
			/* translators: %s: the kind named, as the code names it. */
			return self::unit_refused( 'kind', sprintf( __( 'There is no unit of the kind %s: set_unit_base() sets the bases of the pairs of units only (a plugin, a theme, the theme that runs, and the further kinds of AB_MCP_Sync_Pairs::PARTS).', 'alphabridge-mcp' ), is_string( $kind ) ? $kind : '' ) );
		}
		$ref = $op['ref'] ?? null;
		if ( ! self::valid_ref( $ref ) || 1 === preg_match( '/^sha256:[0-9a-f]{64}\z/', (string) $ref ) ) {
			return self::unit_refused( 'ref', __( 'The name of this unit cannot be held by a pair (longer than 191 characters, a control character, not UTF-8, or named by its SHA-256 only, because a credential holds it or it is no text a line holds), so it gets no pair. Take it over by hand, or rename it on both sites.', 'alphabridge-mcp' ) );
		}
		if ( ! $proof instanceof AB_MCP_Sync_Unit_Proof ) {
			return self::unit_refused( 'proof', __( 'The base of a unit is set only with what confirms it: the confirmation of a person with the token of wp_site_pairs that holds this very operation, or the step of a run that made the unit on this site.', 'alphabridge-mcp' ) );
		}
		$origin = $proof->origin();
		$want   = self::PARTS[ $kind ];
		$parts  = $op['parts'] ?? null;
		if ( ! is_array( $parts ) || count( $parts ) !== count( $want ) || array() !== array_diff( $want, array_keys( $parts ) ) ) {
			/* translators: %s: the parts of the unit, as the code names them (files, activation). */
			return self::unit_refused( 'parts', sprintf( __( 'A unit takes a base for each of its parts (%s), each with its mode, in one operation: not one of them alone and none besides.', 'alphabridge-mcp' ), implode( ', ', $want ) ) );
		}
		foreach ( $want as $part ) {
			$mode = is_array( $parts[ $part ] ) ? ( $parts[ $part ]['mode'] ?? null ) : null;
			if ( ! is_string( $mode ) || ( self::UNIT_MODES[ $mode ] ?? null ) !== $origin ) {
				/* translators: %s: the part (files, activation). */
				return self::unit_refused( 'mode', sprintf( __( 'The part %s has no mode of this origin: a person confirms equal, live_base or keep_apart, a step of a run sets push.', 'alphabridge-mcp' ), $part ) );
			}
		}
		$pair_id = $op['pair_id'] ?? null;
		if ( 'proposal' === $origin && ! $proof->covers( $op ) ) {
			return self::unit_refused( 'proof', __( 'The confirmation does not hold this operation as it is (the unit, a part, its mode or its values differ from what the person confirmed), so no base is set.', 'alphabridge-mcp' ) );
		}
		if ( 'push' === $origin && ( null !== $pair_id || ! in_array( $want[0], $proof->written(), true ) || array() !== array_diff( $proof->written(), $want ) ) ) {
			return self::unit_refused( 'written', __( 'A base with the origin push is set only for a unit a step of a run made on this site, without a pair before, once the step wrote and checked its files.', 'alphabridge-mcp' ) );
		}
		$lines = array(
			'staging' => $op['lines']['staging'] ?? null,
			'live'    => $op['lines']['live'] ?? null,
		);
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {
				return self::unit_refused( 'side', __( 'Each side needs its line of the environment, of this unit by its name: a unit on one side only gets no pair, and no base is made up for the side it is missing on.', 'alphabridge-mcp' ) );
			}
		}
		$bases = array();
		foreach ( $want as $part ) {
			$s = self::unit_part( $kind, $part, $lines['staging'] );
			$l = self::unit_part( $kind, $part, $lines['live'] );
			if ( null === $s || null === $l ) {
				/* translators: %s: the part (files, activation). */
				return self::unit_refused( 'whole', sprintf( __( 'The part %s is not read whole on both sides (its manifest not read to its end, a lock, a name held, AlphaBridge itself, or which main files run or which theme runs not read), so it takes no base.', 'alphabridge-mcp' ), $part ) );
			}
			$mode  = $parts[ $part ]['mode'];
			$same  = hash_equals( $s, $l );
			$wrote = in_array( $part, $proof->written(), true );
			$fits  = array(
				'equal'      => $same,
				'live_base'  => ! $same,
				'keep_apart' => ! $same,
				'push'       => $same || ! $wrote,
			);
			if ( ! $fits[ $mode ] ) {
				return self::unit_refused(
					'state',
					/* translators: 1: the part (files, activation), 2: its mode (equal, live_base, keep_apart, push). */
					sprintf( __( 'The part %1$s does not fit the mode %2$s: equal is for a part that is the same on both sides now, live_base and keep_apart for one that differs, push for a part this site has as staging has it once the step wrote it.', 'alphabridge-mcp' ), $part, $mode )
				);
			}
			$bases[ $part ] = array(
				'live'    => $l,
				'staging' => 'keep_apart' === $mode ? $s : $l,
			);
			if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] || ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {
				/* translators: %s: the part (files, activation). */
				return self::unit_refused( 'bound', sprintf( __( 'The values of the part %s are not the ones the lines of both sides give in its mode: the confirmation binds them.', 'alphabridge-mcp' ), $part ) );
			}
		}
		$further = array();
		foreach ( array_slice( $want, 1 ) as $part ) {
			$further[ $part ] = $bases[ $part ];
		}
		// The write and the reading back in one transaction (V5 of the
		// pre-check of branch 2b): a pair whose row cannot be read back after
		// its insert is taken back, never left as one the caller was told is
		// not there. Part of the confirmation where it runs in one.
		return self::transaction(
			static function () use ( $source_id, $kind, $ref, $want, $bases, $further, $origin, $pair_id, $op ) {
				return self::write_unit_base( $source_id, $kind, $ref, $want, $bases, $further, $origin, $pair_id, $op );
			}
		);
	}

	/**
	 * Write what set_unit_base() checked: a new pair, or the new alignment of
	 * the pair of the unit as the person saw it.
	 *
	 * @param string     $source_id The source.
	 * @param string     $kind      Kind.
	 * @param string     $ref       Its name.
	 * @param string[]   $want      Its parts.
	 * @param array      $bases     Part => live, staging.
	 * @param array      $further   The bases of the parts after the first.
	 * @param string     $origin    proposal or push.
	 * @param int|null   $pair_id   The pair to align anew; null for a new one.
	 * @param array      $op        The operation (expected, last_run).
	 * @return array|WP_Error The pair as stored.
	 */
	private static function write_unit_base( $source_id, $kind, $ref, array $want, array $bases, array $further, $origin, $pair_id, array $op ) {
		if ( null === $pair_id ) {
			return self::insert_pair(
				array(
					'source_id'    => $source_id,
					'kind'         => $kind,
					'live_ref'     => $ref,
					'staging_ref'  => $ref,
					'state'        => 'active',
					'override'     => null,
					'canon'        => AB_MCP_Sync_Canon::VERSION,
					'base_live'    => $bases[ $want[0] ]['live'],
					'base_staging' => $bases[ $want[0] ]['staging'],
					'base_extra'   => array() === $further ? null : array( 'parts' => $further ),
					'base_time'    => time(),
					'base_origin'  => $origin,
					'last_run'     => 'push' === $origin ? ( $op['last_run'] ?? null ) : null,
				)
			);
		}
		$raw = self::read_expected( (int) $pair_id, $op['expected'] ?? null );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		if ( $source_id !== $raw['source_id'] || $kind !== $raw['kind'] || $ref !== $raw['live_ref'] || $ref !== $raw['staging_ref'] ) {
			/* translators: %d: pair number. */
			return self::unit_refused( 'identity', sprintf( __( 'Pair %d is not the pair of this unit (another source, kind or name).', 'alphabridge-mcp' ), (int) $pair_id ) );
		}
		if ( ! in_array( $raw['state'], array( 'unclear', 'residual' ), true ) ) {
			/* translators: 1: pair number, 2: its state, as the tools name it (active, …). */
			return self::unit_refused( 'pair_state', sprintf( __( 'Pair %1$d is %2$s: only an unclear pair or a rest is aligned anew, every part of it; an active pair moves on part by part with the step that wrote it.', 'alphabridge-mcp' ), (int) $pair_id, $raw['state'] ) );
		}
		$extra = null === $raw['base_extra'] ? array() : json_decode( (string) $raw['base_extra'], true );
		if ( ! is_array( $extra ) ) {
			return self::invalid( 'base_extra', __( 'The field base_extra of the pair is not stored JSON of a pair.', 'alphabridge-mcp' ) );
		}
		unset( $extra['parts'] );
		if ( array() !== $further ) {
			$extra['parts'] = $further;
		}
		$json = array() === $extra ? null : self::encode_json( 'base_extra', $extra );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return self::swap(
			$raw,
			array(
				'state'        => 'active',
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => $bases[ $want[0] ]['live'],
				'base_staging' => $bases[ $want[0] ]['staging'],
				'base_extra'   => $json,
				'base_origin'  => 'proposal',
				'base_time'    => time(),
			)
		);
	}

	/**
	 * Whether a kind is a kind of units (PARTS), whose bases only
	 * set_unit_base() makes and only rebase() moves on.
	 *
	 * @param mixed $kind Kind.
	 * @return bool
	 */
	public static function is_unit( $kind ) {
		return is_string( $kind ) && isset( self::PARTS[ $kind ] );
	}

	/**
	 * The hash of a part of a unit from its line, where the line holds it
	 * whole; null where not (set_unit_base()).
	 *
	 * @param string $kind Kind.
	 * @param string $part Part.
	 * @param array  $line The line of the environment.
	 * @return string|null
	 */
	private static function unit_part( $kind, $part, array $line ) {
		if ( isset( $line['held'] ) || ! empty( $line['own'] ) || null !== ( $line['lock'] ?? null ) ) {
			return null;
		}
		if ( 'files' === $part ) {
			if ( true !== ( $line['complete'] ?? null ) || ! in_array( $line['form'] ?? null, array( 'folder', 'file' ), true ) ) {
				return null;
			}
		} elseif ( 'theme_choice' !== $kind && ( ! is_array( $line['mains'] ?? null ) || ! is_array( $line['active'] ?? null ) ) ) {
			return null;
		}
		require_once __DIR__ . '/class-sync-decision.php';
		$hash = AB_MCP_Sync_Decision::part_hash( $kind, $part, $line );
		return self::is_hash( $hash ) ? $hash : null;
	}

	/**
	 * The refusal of a call that would set the bases of a unit past
	 * set_unit_base() (Z44).
	 *
	 * @param string $call create, realign or change_canon.
	 * @return WP_Error
	 */
	private static function unit_only( $call ) {
		return new WP_Error(
			'ab_mcp_staging_pair_unit',
			__( 'The bases of the pair of a unit (a plugin, a theme, the theme that runs, …) are set only by set_unit_base(): part by part from the lines of both sides read whole, with the confirmation of a person (wp_site_pairs confirm) or as a step of a run wrote and checked them. Nothing was written.', 'alphabridge-mcp' ),
			array(
				'clause' => 'unit_only',
				'call'   => (string) $call,
			)
		);
	}

	/**
	 * A refusal of set_unit_base(), by the clause.
	 *
	 * @param string $why     The clause (kind, ref, proof, parts, mode, written, side, whole, state, bound, identity, pair_state), as data clause.
	 * @param string $problem What is wrong, a whole sentence.
	 * @return WP_Error
	 */
	private static function unit_refused( $why, $problem ) {
		return new WP_Error( 'ab_mcp_staging_pair_unit', $problem . ' ' . __( 'Nothing was written. Check the operation against AB_MCP_Sync_Pairs::set_unit_base() (or confirm the unit anew through wp_site_pairs) and call again.', 'alphabridge-mcp' ), array( 'clause' => (string) $why ) );
	}

	/**
	 * Remove a pair (wp_site_pairs discard): its objects stay, each without
	 * a pair from then on.
	 *
	 * @param int    $id       Pair id.
	 * @param string $expected Snapshot hash of the pair the person confirmed.
	 * @return true|WP_Error
	 */
	public static function discard( $id, $expected ) {
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the values are prepared.
		$done = $wpdb->query( 'DELETE FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . self::where_row( $raw ) );
		if ( false === $done ) {
			return self::db_error();
		}
		return 1 === (int) $done ? true : self::changed();
	}

	/**
	 * Run writes of pairs in one transaction of the database: committed when
	 * $fn returns anything but a WP_Error, rolled back otherwise, on an
	 * exception and, by the database itself, when the process ends before
	 * the commit (wp_site_pairs confirm and discard, all or nothing). Only on
	 * a table whose engine keeps transactions (InnoDB); another one refuses
	 * with the way, before anything is written.
	 *
	 * @param callable $fn function(): mixed|WP_Error.
	 * @return mixed|WP_Error What $fn returned.
	 */
	public static function transaction( callable $fn ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		// Within a transaction already (a confirmation that writes the base of
		// a unit through set_unit_base()): $fn is part of it, and the one
		// that began it commits or takes back (a second START TRANSACTION
		// would commit the first).
		if ( self::$depth > 0 ) {
			return call_user_func( $fn );
		}
		global $wpdb;
		$table  = AB_MCP_Sync_Schema::table( 'pairs' );
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		if ( '' !== $wpdb->last_error ) {
			return self::db_error();
		}
		if ( ! is_string( $engine ) || 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
			return new WP_Error(
				'ab_mcp_staging_pairs_engine',
				is_string( $engine )
					/* translators: 1: the name of the table, 2: its storage engine (MyISAM, …). */
					? sprintf( __( 'The table of the object pairs (%1$s) is stored with the engine %2$s, which keeps no transactions, so pairs are not written: a confirmation writes all of its pairs or none. Ask the host to convert the table to InnoDB (ALTER TABLE %1$s ENGINE=InnoDB), then call again.', 'alphabridge-mcp' ), $table, $engine )
					/* translators: 1: the name of the table. */
					: sprintf( __( 'The table of the object pairs (%1$s) is stored with an engine the database does not name, so pairs are not written: a confirmation writes all of its pairs or none, and only InnoDB keeps transactions. Ask the host to convert the table to InnoDB (ALTER TABLE %1$s ENGINE=InnoDB), then call again.', 'alphabridge-mcp' ), $table ),
				array( 'engine' => is_string( $engine ) ? $engine : null )
			);
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::db_error();
		}
		++self::$depth;
		try {
			$result = call_user_func( $fn );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			--self::$depth;
		}
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::db_error();
		}
		return $result;
	}

	/* -------------------------------------------- snapshot (steps.before) */

	/**
	 * The state of a pair as a snapshot for steps.pair_before: every column
	 * but id and updated, as stored, in a stable JSON form.
	 *
	 * @param int $id Pair id.
	 * @return string|WP_Error
	 */
	public static function snapshot( $id ) {
		$raw = self::raw( $id );
		if ( ! is_array( $raw ) ) {
			return null === $raw ? self::missing( $id ) : $raw;
		}
		return self::snapshot_of( $raw );
	}

	/**
	 * @param string $snapshot A snapshot.
	 * @return string Its SHA-256, the $expected of the conditional writes.
	 */
	public static function snapshot_hash( $snapshot ) {
		return hash( 'sha256', (string) $snapshot );
	}

	/**
	 * Put a pair back to a snapshot, only when it is still in the state the
	 * caller left it in ($expected), and never out of residual or unclear
	 * (rule 0 and decision 14: only a confirmed new alignment ends them). A pair a run made has no snapshot
	 * before it and is never removed here: it becomes residual instead.
	 *
	 * @param int         $id       Pair id.
	 * @param string|null $before   Snapshot taken before the step.
	 * @param string      $expected Snapshot hash of the state the step left.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function restore( $id, $before, $expected ) {
		if ( null === $before || '' === $before ) {
			return new WP_Error( 'ab_mcp_staging_pair_no_before', __( 'This pair did not exist before the step, so there is no state to put it back to, and the way back never removes a pair. Use set_state() to mark it residual.', 'alphabridge-mcp' ) );
		}
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		// Neither residual (rule 0) nor unclear ends here: both wait for the
		// confirmed new alignment, realign().
		if ( in_array( $raw['state'], array( 'residual', 'unclear' ), true ) ) {
			return self::transition_refused( $raw['state'], 'restore' );
		}
		$set = self::parse_snapshot( $before );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		foreach ( array( 'source_id', 'kind', 'live_ref', 'staging_ref' ) as $column ) {
			if ( $set[ $column ] !== $raw[ $column ] ) {
				/* translators: %s: the column that differs (source_id, kind, live_ref, staging_ref). */
				return new WP_Error( 'ab_mcp_staging_pair_snapshot_foreign', sprintf( __( 'The snapshot is of another pair (%s differs); nothing was written. Pass the snapshot the step took of this pair.', 'alphabridge-mcp' ), $column ), array( 'column' => $column ) );
			}
		}
		return self::swap( $raw, $set );
	}

	/* ---------------------------------------------------- canon change */

	/**
	 * A new version of the canonical form (decision 10): the forms of both
	 * sides are worked out again in it; are they the same when mapped (the
	 * live form equals the target, the staging form mapped onto the live
	 * site with the confirmed override), they are the new bases, else the
	 * pair becomes unclear. Only active pairs: a residual pair keeps its
	 * mapping and its bases (rule 0), an unclear one waits for a
	 * confirmation.
	 *
	 * @param int      $id    Pair id.
	 * @param string   $canon The new version, such as canon/2.
	 * @param callable $forms function( array $pair, string $canon ): array|WP_Error
	 *                        with 'live', 'staging' and 'target', each the
	 *                        SHA-256 of that form in $canon, or null where
	 *                        the object is not there. The canonicalizer of
	 *                        the kind (branch 4 of the plan).
	 * @return string|WP_Error 'rebased', 'unclear', 'current' (already in
	 *                         $canon) or 'skipped' (not active).
	 */
	public static function change_canon( $id, $canon, callable $forms ) {
		$bad = self::check_fields( array( 'canon' => $canon ) );
		if ( null !== $bad ) {
			return $bad;
		}
		$raw = self::raw( $id );
		if ( ! is_array( $raw ) ) {
			return null === $raw ? self::missing( $id ) : $raw;
		}
		// The bases of a unit are the hashes of its manifest and of what runs,
		// no canonical form of an object: never worked out anew here (Z44).
		if ( self::is_unit( $raw['kind'] ) ) {
			return self::unit_only( 'change_canon' );
		}
		if ( $raw['canon'] === $canon ) {
			return 'current';
		}
		if ( 'active' !== $raw['state'] ) {
			return 'skipped';
		}
		$result = call_user_func( $forms, self::decode( $raw ), $canon );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$set = self::canon_outcome( $result, $canon );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$done = self::swap( $raw, $set );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return 'unclear' === $set['state'] ? 'unclear' : 'rebased';
	}

	/**
	 * What a canon change sets, from the forms in the new version: the new
	 * bases when the live form equals the target, else state unclear (the
	 * old bases and version stay, for the person who confirms).
	 *
	 * @param mixed  $forms 'live', 'staging', 'target' (SHA-256 or null).
	 * @param string $canon The new version.
	 * @return array|WP_Error Columns to set.
	 */
	public static function canon_outcome( $forms, $canon ) {
		if ( ! is_array( $forms ) ) {
			return self::invalid( 'forms', __( 'The field forms of the pair is not live, staging and target.', 'alphabridge-mcp' ) );
		}
		foreach ( array( 'live', 'staging', 'target' ) as $side ) {
			if ( ! array_key_exists( $side, $forms ) || ( null !== $forms[ $side ] && ! self::is_hash( $forms[ $side ] ) ) ) {
				/* translators: %s: the side in the field forms (live, staging, target). */
				return self::invalid( 'forms', sprintf( __( 'In the field forms of the pair, %s is not a SHA-256 or null.', 'alphabridge-mcp' ), $side ) );
			}
		}
		if ( null !== $forms['live'] && null !== $forms['staging'] && null !== $forms['target'] && hash_equals( $forms['target'], $forms['live'] ) ) {
			return array(
				'state'        => 'active',
				'canon'        => $canon,
				'base_live'    => $forms['live'],
				'base_staging' => $forms['staging'],
				'base_time'    => time(),
			);
		}
		return array( 'state' => 'unclear' );
	}

	/**
	 * @param string $from State now.
	 * @param string $to   State asked for.
	 * @return bool Whether set_state() makes this change.
	 */
	public static function transition_allowed( $from, $to ) {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/* --------------------------------------------------------- helpers */

	/**
	 * The tables are there, and this is no network.
	 *
	 * @return true|WP_Error
	 */
	private static function ready() {
		$status = AB_MCP_Sync_Schema::status();
		if ( $status['available'] ) {
			return true;
		}
		$message = 'multisite' === $status['reason']
			? __( 'Object pairs are not available on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the live site.', 'alphabridge-mcp' )
			/* translators: %s: why the tables are not there, as the schema names it (missing, …). */
			: sprintf( __( 'The tables of the object pairs are not there (%s). Deactivate and Activate AlphaBridge in wp-admin to make them.', 'alphabridge-mcp' ), $status['reason'] );
		return new WP_Error( 'ab_mcp_staging_unavailable', $message, array( 'reason' => $status['reason'] ) );
	}

	/**
	 * The stored row of a pair, as the database returns it.
	 *
	 * @param int $id Pair id.
	 * @return array|null|WP_Error
	 */
	private static function raw( $id ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the value is prepared.
		$row = self::calls()->row( $wpdb->prepare( 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE id = %d', (int) $id ) );
		if ( false === $row ) {
			return self::db_error();
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The stored row, when its snapshot hash is $expected.
	 *
	 * @param int    $id       Pair id.
	 * @param string $expected Snapshot hash.
	 * @return array|WP_Error
	 */
	private static function read_expected( $id, $expected ) {
		if ( ! self::is_hash( $expected ) ) {
			return self::invalid( 'expected', __( 'The field expected of the pair is not a snapshot hash.', 'alphabridge-mcp' ) );
		}
		$raw = self::raw( $id );
		if ( ! is_array( $raw ) ) {
			return null === $raw ? self::missing( $id ) : $raw;
		}
		$snapshot = self::snapshot_of( $raw );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return hash_equals( $expected, self::snapshot_hash( $snapshot ) ) ? $raw : self::changed();
	}

	/**
	 * Write columns of a row, on the condition that it is still byte for
	 * byte the row read; updated always moves on, so a row that matched is a
	 * row changed.
	 *
	 * @param array $raw The row as read.
	 * @param array $set Column => value (null for NULL).
	 * @return array|WP_Error The pair as stored.
	 */
	private static function swap( array $raw, array $set ) {
		global $wpdb;
		$set['updated'] = max( time(), (int) $raw['updated'] + 1 );
		$parts          = array();
		foreach ( $set as $column => $value ) {
			if ( null === $value ) {
				$parts[] = $column . ' = NULL';
			} elseif ( in_array( $column, self::INT_COLUMNS, true ) ) {
				$parts[] = $wpdb->prepare( $column . ' = %d', (int) $value );
			} else {
				$parts[] = $wpdb->prepare( $column . ' = %s', (string) $value );
			}
		}
		$done = self::calls()->update_row( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );
		if ( false === $done ) {
			return self::db_error();
		}
		if ( 1 !== $done ) {
			return self::changed();
		}
		return self::get( (int) $raw['id'] );
	}

	/**
	 * The WHERE clause that holds only for this row byte for byte.
	 *
	 * @param array $raw The row as read.
	 * @return string
	 */
	private static function where_row( array $raw ) {
		global $wpdb;
		$conds = array( $wpdb->prepare( 'id = %d', (int) $raw['id'] ) );
		foreach ( array_keys( AB_MCP_Sync_Schema::COLUMNS['pairs'] ) as $column ) {
			if ( 'id' === $column ) {
				continue;
			}
			$conds[] = null === $raw[ $column ] ? $column . ' IS NULL' : self::exact( $column, (string) $raw[ $column ], false );
		}
		return implode( ' AND ', $conds );
	}

	/**
	 * A condition that holds when a column has these bytes: the bytes and
	 * their number (decision 23: a plain comparison follows the collation,
	 * which takes 'abc' for 'ABC' and 'a' for 'a '), after the plain one
	 * that lets the key be used.
	 *
	 * @param string $column Column (ours).
	 * @param string $value  Value.
	 * @param bool   $keyed  Lead with the plain comparison (for a lookup by key).
	 * @return string
	 */
	private static function exact( $column, $value, $keyed = true ) {
		global $wpdb;
		$sql = $wpdb->prepare( "CAST($column AS BINARY) = CAST(%s AS BINARY) AND OCTET_LENGTH($column) = %d", $value, strlen( $value ) );
		return $keyed ? $wpdb->prepare( "$column = %s AND ", $value ) . $sql : $sql;
	}

	/**
	 * A pair by one of its refs.
	 *
	 * @param string $source_id Source.
	 * @param string $kind      Pair kind.
	 * @param string $column    live_ref or staging_ref.
	 * @param string $ref       Ref.
	 * @return array|null|WP_Error
	 */
	private static function find( $source_id, $kind, $column, $ref ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$bad = self::check_fields(
			array(
				'source_id' => $source_id,
				'kind'      => $kind,
				$column     => $ref,
			)
		);
		if ( null !== $bad ) {
			return $bad;
		}
		$row = self::lookup( $source_id, $kind, $column, $ref );
		return is_array( $row ) ? self::decode( $row ) : $row;
	}

	/**
	 * The stored row with these bytes in source_id, kind and the ref, or,
	 * with $bytes false, one whose ref the collation of the table takes for
	 * this one (the unique key compares so).
	 *
	 * @param string $source_id Source.
	 * @param string $kind      Pair kind.
	 * @param string $column    live_ref or staging_ref.
	 * @param string $ref       Ref.
	 * @param bool   $bytes     Compare the ref by its bytes.
	 * @return array|null|WP_Error
	 */
	private static function lookup( $source_id, $kind, $column, $ref, $bytes = true ) {
		global $wpdb;
		$where = self::exact( 'source_id', $source_id ) . ' AND ' . self::exact( 'kind', $kind ) . ' AND ' . ( $bytes ? self::exact( $column, $ref ) : $wpdb->prepare( "$column = %s", $ref ) );
		$row = self::calls()->row( 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . $where . ' LIMIT 1' );
		if ( false === $row ) {
			return self::db_error();
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The named refusal of a second pair on one of the unique keys.
	 *
	 * @param array $row The row to write.
	 * @return WP_Error|null
	 */
	private static function conflict( array $row ) {
		foreach ( array( 'live_ref', 'staging_ref' ) as $column ) {
			foreach ( array( true, false ) as $bytes ) {
				$found = self::lookup( $row['source_id'], $row['kind'], $column, $row[ $column ], $bytes );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				if ( is_array( $found ) ) {
					return new WP_Error(
						'ab_mcp_staging_pair_conflict',
						$bytes
							/* translators: 1: the side of the object, as the tools name it (live, staging), 2: pair number. */
							? sprintf( __( 'There is a pair for this %1$s object of this source and kind already (pair %2$d); nothing was written. Use that pair, or discard it first (wp_site_pairs discard).', 'alphabridge-mcp' ), 'live_ref' === $column ? 'live' : 'staging', (int) $found['id'] )
							/* translators: 1: the side of the object, as the tools name it (live, staging), 2: pair number. */
							: sprintf( __( 'There is a pair for this %1$s object of this source and kind already (pair %2$d, whose ref differs only in case, accents or trailing spaces, which the table takes for the same); nothing was written. Use that pair, or discard it first (wp_site_pairs discard).', 'alphabridge-mcp' ), 'live_ref' === $column ? 'live' : 'staging', (int) $found['id'] ),
						array(
							'key'  => $column,
							'pair' => (int) $found['id'],
							'same' => $bytes ? 'bytes' : 'collation',
						)
					);
				}
			}
		}
		return null;
	}

	/**
	 * The fields of a pair as stored: validated, override and base_extra
	 * as canonical JSON.
	 *
	 * @param array $pair Fields.
	 * @return array|WP_Error
	 */
	private static function encode( array $pair ) {
		$row = array();
		foreach ( self::SNAPSHOT_COLUMNS as $column ) {
			$value = $pair[ $column ];
			if ( 'override' === $column || 'base_extra' === $column ) {
				$value = self::encode_json( $column, $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			$row[ $column ] = $value;
		}
		$bad = self::check_fields( $row );
		return null === $bad ? $row : $bad;
	}

	/**
	 * Columns of bases for rebase() and realign().
	 *
	 * @param array    $values   Values given.
	 * @param string[] $required Columns that must be given.
	 * @param string[] $optional Columns that may be given.
	 * @return array|WP_Error
	 */
	private static function bases( array $values, array $required, array $optional ) {
		$extra = array_diff( array_keys( $values ), $required, $optional );
		if ( array() !== $extra ) {
			/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
			return self::invalid( reset( $extra ), sprintf( __( 'The field %s of the pair cannot be set here.', 'alphabridge-mcp' ), reset( $extra ) ) );
		}
		$set = array();
		foreach ( array_merge( $required, $optional ) as $column ) {
			if ( ! array_key_exists( $column, $values ) ) {
				if ( in_array( $column, $required, true ) ) {
					/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
					return self::invalid( $column, sprintf( __( 'The field %s of the pair is required.', 'alphabridge-mcp' ), $column ) );
				}
				continue;
			}
			$value = $values[ $column ];
			if ( 'override' === $column || 'base_extra' === $column ) {
				$value = self::encode_json( $column, $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			$set[ $column ] = $value;
		}
		$bad = self::check_fields( $set );
		if ( null !== $bad ) {
			return $bad;
		}
		$set['base_time'] = time();
		return $set;
	}

	/**
	 * The columns of a snapshot, validated as create() validates them.
	 *
	 * @param string $snapshot Snapshot.
	 * @return array|WP_Error
	 */
	private static function parse_snapshot( $snapshot ) {
		$data = is_string( $snapshot ) ? json_decode( $snapshot, true ) : null;
		if ( ! is_array( $data ) || array( 'columns', 'format' ) !== array_keys( $data ) || self::SNAPSHOT_FORMAT !== $data['format'] || ! is_array( $data['columns'] ) ) {
			return self::invalid( 'before', __( 'The field before of the pair is not a snapshot of a pair.', 'alphabridge-mcp' ) );
		}
		$set = $data['columns'];
		if ( self::SNAPSHOT_COLUMNS !== array_keys( $set ) ) {
			return self::invalid( 'before', __( 'The field before of the pair does not hold the columns of a pair.', 'alphabridge-mcp' ) );
		}
		foreach ( array( 'override', 'base_extra' ) as $column ) {
			if ( null === $set[ $column ] ) {
				continue;
			}
			$again = is_string( $set[ $column ] ) ? self::encode_json( $column, json_decode( $set[ $column ], true ) ) : null;
			if ( ! is_string( $again ) || $again !== $set[ $column ] ) {
				/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
				return self::invalid( $column, sprintf( __( 'The field %s of the pair is not stored JSON of a pair.', 'alphabridge-mcp' ), $column ) );
			}
		}
		$bad = self::check_fields( $set );
		return null === $bad ? $set : $bad;
	}

	/**
	 * The snapshot of a stored row.
	 *
	 * @param array $raw The row as read.
	 * @return string|WP_Error
	 */
	private static function snapshot_of( array $raw ) {
		$columns = array();
		foreach ( self::SNAPSHOT_COLUMNS as $column ) {
			$value = $raw[ $column ];
			if ( null !== $value && in_array( $column, self::INT_COLUMNS, true ) ) {
				$value = (int) $value;
			}
			$columns[ $column ] = $value;
		}
		$json = json_encode(
			array(
				'columns' => $columns,
				'format'  => self::SNAPSHOT_FORMAT,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return false === $json ? self::invalid( 'pair', __( 'The pair cannot be written as a snapshot.', 'alphabridge-mcp' ) ) : $json;
	}

	/**
	 * A stored row as the callers see it: numbers as numbers, override and
	 * base_extra as arrays.
	 *
	 * @param array $raw The row as read.
	 * @return array
	 */
	public static function decode( array $raw ) {
		$out = array();
		foreach ( array_keys( AB_MCP_Sync_Schema::COLUMNS['pairs'] ) as $column ) {
			$value = array_key_exists( $column, $raw ) ? $raw[ $column ] : null;
			if ( null !== $value && in_array( $column, self::INT_COLUMNS, true ) ) {
				$value = (int) $value;
			} elseif ( null !== $value && ( 'override' === $column || 'base_extra' === $column ) ) {
				$value = json_decode( (string) $value, true );
			}
			$out[ $column ] = $value;
		}
		return $out;
	}

	/**
	 * override or base_extra as stored: null, or canonical JSON within its
	 * bounds (keys sorted, no floats or objects, valid UTF-8).
	 *
	 * @param string $column override or base_extra.
	 * @param mixed  $value  Value.
	 * @return string|null|WP_Error
	 */
	public static function encode_json( $column, $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array() === $value ) {
			/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
			return self::invalid( $column, sprintf( __( 'The field %s of the pair is not a non-empty array.', 'alphabridge-mcp' ), $column ) );
		}
		if ( 'override' === $column ) {
			if ( array_is_list( $value ) ) {
				/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
				return self::invalid( $column, sprintf( __( 'The field %s of the pair is not an object of fields.', 'alphabridge-mcp' ), $column ) );
			}
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z_][a-z0-9_]{0,63}\z/', $key ) || ! ( null === $item || is_string( $item ) || is_int( $item ) || is_bool( $item ) ) ) {
					/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
					return self::invalid( $column, sprintf( __( 'The field %s of the pair holds something other than field names with plain values.', 'alphabridge-mcp' ), $column ) );
				}
			}
		}
		$sorted = self::sorted( $value, 1, 'override' === $column ? 1 : self::MAX_EXTRA_DEPTH );
		if ( null === $sorted ) {
			/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
			return self::invalid( $column, sprintf( __( 'The field %s of the pair holds a float, an object or nests too deep.', 'alphabridge-mcp' ), $column ) );
		}
		$json = json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
			return self::invalid( $column, sprintf( __( 'The field %s of the pair is not valid UTF-8.', 'alphabridge-mcp' ), $column ) );
		}
		$max = 'override' === $column ? self::MAX_OVERRIDE : self::MAX_EXTRA;
		if ( strlen( $json ) > $max ) {
			/* translators: 1: the field of the pair, as the code names it, 2: the most bytes it holds. */
			return self::invalid( $column, sprintf( __( 'The field %1$s of the pair is longer than %2$d bytes.', 'alphabridge-mcp' ), $column, $max ) );
		}
		return $json;
	}

	/**
	 * An array with every object's keys sorted, or null for a float, an
	 * object, or nesting deeper than $max.
	 *
	 * @param array $value Value.
	 * @param int   $depth Depth of $value.
	 * @param int   $max   Deepest allowed.
	 * @return array|null
	 */
	private static function sorted( array $value, $depth, $max ) {
		if ( $depth > $max ) {
			return null;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$item = self::sorted( $item, $depth + 1, $max );
				if ( null === $item ) {
					return null;
				}
				$value[ $key ] = $item;
			} elseif ( ! ( null === $item || is_string( $item ) || is_int( $item ) || is_bool( $item ) ) ) {
				return null;
			}
		}
		return $value;
	}

	/**
	 * The first field that is not as a pair has it, as an error.
	 *
	 * @param array $fields Column => value (stored form).
	 * @return WP_Error|null
	 */
	private static function check_fields( array $fields ) {
		foreach ( $fields as $column => $value ) {
			$ok = true;
			switch ( $column ) {
				case 'source_id':
					$ok = is_string( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\z/', $value );
					break;
				case 'kind':
					$ok = is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $value );
					break;
				case 'live_ref':
				case 'staging_ref':
					$ok = is_string( $value ) && '' !== $value && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) && mb_strlen( $value, 'UTF-8' ) <= self::MAX_REF;
					break;
				case 'state':
					if ( 'deleted' === $value ) {
						return new WP_Error( 'ab_mcp_staging_pair_deleted', __( 'No pair is taken over as deleted in this version: deletions follow with stage Z8d. Use active, unclear or residual.', 'alphabridge-mcp' ), array( 'stage' => 'Z8d' ) );
					}
					$ok = in_array( $value, self::STATES, true );
					break;
				case 'canon':
					$ok = is_string( $value ) && 1 === preg_match( '/^canon\/[1-9][0-9]{0,5}\z/', $value );
					break;
				case 'base_live':
				case 'base_staging':
					$ok = self::is_hash( $value );
					break;
				case 'base_time':
					$ok = is_int( $value ) && $value >= 0;
					break;
				case 'base_origin':
					$ok = in_array( $value, self::ORIGINS, true );
					break;
				case 'last_run':
					$ok = null === $value || ( is_int( $value ) && $value > 0 );
					break;
				case 'override':
				case 'base_extra':
					$ok = null === $value || is_string( $value );
					break;
			}
			if ( ! $ok ) {
				/* translators: %s: the field of the pair (live_ref, base_extra, …), as the code names it. */
				return self::invalid( $column, sprintf( __( 'The field %s of the pair is not valid.', 'alphabridge-mcp' ), $column ) );
			}
		}
		return null;
	}

	/**
	 * Whether a pair can hold this ref (the rule of the columns live_ref and
	 * staging_ref): text of valid UTF-8 without control characters, 1 to
	 * MAX_REF characters.
	 *
	 * @param mixed $ref Ref.
	 * @return bool
	 */
	public static function valid_ref( $ref ) {
		return null === self::check_fields( array( 'live_ref' => $ref ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return bool A SHA-256 in lower-case hex.
	 */
	private static function is_hash( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $value );
	}

	/**
	 * @param string $field   Field.
	 * @param string $problem What is wrong, a whole sentence that names the field.
	 * @return WP_Error
	 */
	private static function invalid( $field, $problem ) {
		return new WP_Error( 'ab_mcp_staging_pair_invalid', $problem . ' ' . __( 'Nothing was written. Check it against the head of AB_MCP_Sync_Pairs and call again.', 'alphabridge-mcp' ), array( 'field' => $field ) );
	}

	/**
	 * @param string $from State now.
	 * @param string $to   State or change asked for.
	 * @return WP_Error
	 */
	private static function transition_refused( $from, $to ) {
		$message = 'residual' === $from || 'unclear' === $from
			/* translators: %s: the state of the pair, as the tools name it (residual, unclear). */
			? sprintf( __( 'A pair in the state %s changes only through a confirmed new alignment: ask the person to confirm it (wp_site_pairs confirm), which gives both sides new bases.', 'alphabridge-mcp' ), $from )
			/* translators: 1: the state of the pair now, 2: the state or change asked for, as the tools name them. */
			: sprintf( __( 'A pair does not go from %1$s to %2$s; the changes it makes are AB_MCP_Sync_Pairs::TRANSITIONS. Check the state and call again.', 'alphabridge-mcp' ), $from, $to );
		return new WP_Error(
			'ab_mcp_staging_pair_transition',
			$message,
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	/**
	 * @return WP_Error The pair changed since the caller read it.
	 */
	private static function changed() {
		return new WP_Error( 'ab_mcp_staging_pair_changed', __( 'The pair changed since it was read; nothing was written. Read it again and call again with its new snapshot hash.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param int $id Pair id.
	 * @return WP_Error
	 */
	private static function missing( $id ) {
		/* translators: %d: pair number. */
		return new WP_Error( 'ab_mcp_staging_pair_missing', sprintf( __( 'There is no pair %d; nothing was written. Check the id and call again.', 'alphabridge-mcp' ), (int) $id ), array( 'pair' => (int) $id ) );
	}

	/**
	 * @return WP_Error The database answered with an error.
	 */
	private static function db_error() {
		global $wpdb;
		return new WP_Error( 'ab_mcp_staging_db_error', __( 'The database refused the request on the table of the object pairs; nothing was written. Check the database log (wp_get_error_log) and try again.', 'alphabridge-mcp' ), array( 'error' => (string) $wpdb->last_error ) );
	}
}
