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
 * Nothing here works on a network or without the tables
 * (ab_mcp_staging_unavailable, AB_MCP_Sync_Schema::status()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-schema.php';

/**
 * Class AB_MCP_Sync_Pairs
 */
final class AB_MCP_Sync_Pairs {

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

	/** The columns a snapshot holds: all but id and updated. */
	const SNAPSHOT_COLUMNS = array( 'base_extra', 'base_live', 'base_origin', 'base_staging', 'base_time', 'canon', 'kind', 'last_run', 'live_ref', 'override', 'source_id', 'staging_ref', 'state' );

	/** The columns that hold numbers. */
	const INT_COLUMNS = array( 'id', 'base_time', 'last_run', 'updated' );

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
			return self::invalid( reset( $extra ), 'is not a field of a pair' );
		}
		foreach ( array( 'source_id', 'kind', 'live_ref', 'staging_ref', 'canon', 'base_live', 'base_staging', 'base_origin' ) as $required ) {
			if ( ! array_key_exists( $required, $pair ) ) {
				return self::invalid( $required, 'is required' );
			}
		}
		$pair += array(
			'state'      => 'active',
			'override'   => null,
			'base_extra' => null,
			'base_time'  => time(),
			'last_run'   => null,
		);
		$row = self::encode( $pair );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$conflict = self::conflict( $row );
		if ( null !== $conflict ) {
			return $conflict;
		}
		global $wpdb;
		$row['updated'] = time();
		$formats        = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s';
		}
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( AB_MCP_Sync_Schema::table( 'pairs' ), $row, $formats );
		$wpdb->suppress_errors( $suppress );
		if ( false === $inserted ) {
			// Written by another request in between: the unique keys refused it.
			$conflict = self::conflict( $row );
			return null !== $conflict ? $conflict : self::db_error();
		}
		return self::get( (int) $wpdb->insert_id );
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
	 * @param int    $id       Pair id.
	 * @param array  $values   canon, base_live, base_staging, base_origin;
	 *                         optional override, base_extra, last_run (left
	 *                         as they are when not given).
	 * @param string $expected Snapshot hash of the pair as the caller read it.
	 * @return array|WP_Error The pair as stored.
	 */
	public static function rebase( $id, array $values, $expected ) {
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		if ( 'active' !== $raw['state'] ) {
			return new WP_Error(
				'ab_mcp_staging_pair_not_active',
				sprintf( 'Only an active pair takes new bases; a pair in the state %s keeps them until a person confirms a new alignment. Use realign() for that confirmation (wp_site_pairs confirm).', $raw['state'] ),
				array( 'state' => $raw['state'] )
			);
		}
		$set = self::bases( $values, array( 'canon', 'base_live', 'base_staging', 'base_origin' ), array( 'override', 'base_extra', 'last_run' ) );
		return is_wp_error( $set ) ? $set : self::swap( $raw, $set );
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
			return new WP_Error( 'ab_mcp_staging_pair_unconfirmed', 'A new alignment of a pair needs the confirmation of a person: pass true only for a call that carries the token of that confirmation (wp_site_pairs confirm).' );
		}
		$raw = self::read_expected( $id, $expected );
		if ( ! is_array( $raw ) ) {
			return $raw;
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
			return new WP_Error( 'ab_mcp_staging_pair_no_before', 'This pair did not exist before the step, so there is no state to put it back to, and the way back never removes a pair. Use set_state() to mark it residual.' );
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
				return new WP_Error( 'ab_mcp_staging_pair_snapshot_foreign', sprintf( 'The snapshot is of another pair (%s differs); nothing was written. Pass the snapshot the step took of this pair.', $column ), array( 'column' => $column ) );
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
			return self::invalid( 'forms', 'is not live, staging and target' );
		}
		foreach ( array( 'live', 'staging', 'target' ) as $side ) {
			if ( ! array_key_exists( $side, $forms ) || ( null !== $forms[ $side ] && ! self::is_hash( $forms[ $side ] ) ) ) {
				return self::invalid( 'forms', $side . ' is not a SHA-256 or null' );
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
			? 'Object pairs are not available on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the live site.'
			: sprintf( 'The tables of the object pairs are not there (%s). Deactivate and Activate AlphaBridge in wp-admin to make them.', $status['reason'] );
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
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
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
			return self::invalid( 'expected', 'is not a snapshot hash' );
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the table and column names are ours; the values are prepared above.
		$done = $wpdb->query( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );
		if ( false === $done ) {
			return self::db_error();
		}
		if ( 1 !== (int) $done ) {
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the table and column names are ours; the values are prepared.
		$row = $wpdb->get_row( 'SELECT * FROM ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' WHERE ' . $where . ' LIMIT 1', ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
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
						sprintf(
							'There is a pair for this %1$s object of this source and kind already (pair %2$d%3$s); nothing was written. Use that pair, or discard it first (wp_site_pairs discard).',
							'live_ref' === $column ? 'live' : 'staging',
							(int) $found['id'],
							$bytes ? '' : ', whose ref differs only in case, accents or trailing spaces, which the table takes for the same'
						),
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
			return self::invalid( reset( $extra ), 'cannot be set here' );
		}
		$set = array();
		foreach ( array_merge( $required, $optional ) as $column ) {
			if ( ! array_key_exists( $column, $values ) ) {
				if ( in_array( $column, $required, true ) ) {
					return self::invalid( $column, 'is required' );
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
			return self::invalid( 'before', 'is not a snapshot of a pair' );
		}
		$set = $data['columns'];
		if ( self::SNAPSHOT_COLUMNS !== array_keys( $set ) ) {
			return self::invalid( 'before', 'does not hold the columns of a pair' );
		}
		foreach ( array( 'override', 'base_extra' ) as $column ) {
			if ( null === $set[ $column ] ) {
				continue;
			}
			$again = is_string( $set[ $column ] ) ? self::encode_json( $column, json_decode( $set[ $column ], true ) ) : null;
			if ( ! is_string( $again ) || $again !== $set[ $column ] ) {
				return self::invalid( $column, 'is not stored JSON of a pair' );
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
		return false === $json ? self::invalid( 'pair', 'cannot be written as a snapshot' ) : $json;
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
			return self::invalid( $column, 'is not a non-empty array' );
		}
		if ( 'override' === $column ) {
			if ( array_is_list( $value ) ) {
				return self::invalid( $column, 'is not an object of fields' );
			}
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z_][a-z0-9_]{0,63}\z/', $key ) || ! ( null === $item || is_string( $item ) || is_int( $item ) || is_bool( $item ) ) ) {
					return self::invalid( $column, 'holds something other than field names with plain values' );
				}
			}
		}
		$sorted = self::sorted( $value, 1, 'override' === $column ? 1 : self::MAX_EXTRA_DEPTH );
		if ( null === $sorted ) {
			return self::invalid( $column, 'holds a float, an object or nests too deep' );
		}
		$json = json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return self::invalid( $column, 'is not valid UTF-8' );
		}
		$max = 'override' === $column ? self::MAX_OVERRIDE : self::MAX_EXTRA;
		if ( strlen( $json ) > $max ) {
			return self::invalid( $column, 'is longer than ' . $max . ' bytes' );
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
						return new WP_Error( 'ab_mcp_staging_pair_deleted', 'No pair is taken over as deleted in this version: deletions follow with stage Z8d. Use active, unclear or residual.', array( 'stage' => 'Z8d' ) );
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
				return self::invalid( $column, 'is not valid' );
			}
		}
		return null;
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
	 * @param string $problem What is wrong.
	 * @return WP_Error
	 */
	private static function invalid( $field, $problem ) {
		return new WP_Error( 'ab_mcp_staging_pair_invalid', sprintf( 'The field %1$s of the pair %2$s; nothing was written. Check it against the head of AB_MCP_Sync_Pairs and call again.', $field, $problem ), array( 'field' => $field ) );
	}

	/**
	 * @param string $from State now.
	 * @param string $to   State or change asked for.
	 * @return WP_Error
	 */
	private static function transition_refused( $from, $to ) {
		$message = 'residual' === $from || 'unclear' === $from
			? sprintf( 'A pair in the state %s changes only through a confirmed new alignment: ask the person to confirm it (wp_site_pairs confirm), which gives both sides new bases.', $from )
			: sprintf( 'A pair does not go from %1$s to %2$s; the changes it makes are AB_MCP_Sync_Pairs::TRANSITIONS. Check the state and call again.', $from, $to );
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
		return new WP_Error( 'ab_mcp_staging_pair_changed', 'The pair changed since it was read; nothing was written. Read it again and call again with its new snapshot hash.' );
	}

	/**
	 * @param int $id Pair id.
	 * @return WP_Error
	 */
	private static function missing( $id ) {
		return new WP_Error( 'ab_mcp_staging_pair_missing', sprintf( 'There is no pair %d; nothing was written. Check the id and call again.', (int) $id ), array( 'pair' => (int) $id ) );
	}

	/**
	 * @return WP_Error The database answered with an error.
	 */
	private static function db_error() {
		global $wpdb;
		return new WP_Error( 'ab_mcp_staging_db_error', 'The database refused the request on the table of the object pairs; nothing was written. Check the database log (wp_get_error_log) and try again.', array( 'error' => (string) $wpdb->last_error ) );
	}
}
