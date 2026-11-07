<?php
/**
 * The inventory of the database kinds of one site (Z8, decision 14 of the
 * plan of 01.11.2026, the part of branch 4): a reading run over every kind of
 * AB_MCP_Sync_Db_Kinds with a budget of 60 seconds, the way
 * wp_bulk_update_status keeps its own (AB_MCP_Tools_Search_Bulk::
 * STATUS_BUDGET), and a cursor to go on from. Each object comes with its
 * identity, hash, references, size and whether it is complete; the form
 * itself stays out (canonical() gives it for one object).
 *
 * The source of this branch is the rows of this site, with the addresses
 * that are neutral; the package as a source follows with branch 5.
 *
 * A source may also bound the number of objects (branch 5: a package holds
 * at most AB_MCP_Sync_Manifest::MAX_OBJECTS): the kinds stop the same way
 * as at the end of the time, before the next row, once the objects made
 * since source() reach it. A row that gives several objects (a menu with
 * its items, the theme mods of a theme) is read whole, so a part may end a
 * few objects past the bound.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-identity.php';

/**
 * Class AB_MCP_Sync_Inventory
 */
final class AB_MCP_Sync_Inventory {

	/** Seconds one call may read (AB_MCP_Tools_Search_Bulk::STATUS_BUDGET). */
	const BUDGET = 60;

	/** The clock of the budget; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/** Objects entry() made since the last source(). */
	private static $made = 0;

	/** The context of the last source(), for the mapped form of entry(). */
	private static $ctx = null;

	/**
	 * The source of an inventory on this site.
	 *
	 * @param string[]      $addresses The addresses of both sites (neutral).
	 * @param int|float     $budget    Seconds.
	 * @param callable|null $users     Reader of user logins.
	 * @param int|null      $objects   Objects at most (see the class), null for no bound.
	 * @param string|null   $index     The index of identities of this run as
	 *                                 the call before kept it (run(): index;
	 *                                 AB_MCP_Sync_Identity::stored()), null to
	 *                                 begin one.
	 * @return array ctx, deadline, objects.
	 */
	public static function source( array $addresses, $budget = self::BUDGET, $users = null, $objects = null, $index = null ) {
		// The rewrite rules of this site route its paths, and its own
		// addresses, both from its rows (AB_MCP_Sync_Urls). What cannot be
		// read locks where it counts.
		$own = AB_MCP_Sync_Canon::own_addresses();
		$ctx = AB_MCP_Sync_Canon::context(
			$addresses,
			$users,
			AB_MCP_Sync_Urls::routes(),
			null === $own ? array() : $own
		);
		// The tags read in UTF-8 only where the site writes in it.
		$ctx['utf8'] = AB_MCP_Sync_Rows::utf8();
		self::$made  = 0;
		self::$ctx   = $ctx;
		AB_MCP_Sync_Identity::reset( $ctx, $index );
		return array(
			'ctx'      => $ctx,
			'deadline' => self::now() + max( 0, (float) $budget ),
			'objects'  => null === $objects ? null : max( 1, (int) $objects ),
		);
	}

	/**
	 * Whether the budget of a source is used up: its time, or the objects
	 * it bounds.
	 *
	 * @param mixed $source Source.
	 * @return bool
	 */
	public static function out_of_time( $source ) {
		// isset() reads nothing from what is no source.
		if ( isset( $source['objects'] ) && self::$made >= (int) $source['objects'] ) {
			return true;
		}
		return isset( $source['deadline'] ) && self::now() >= (float) $source['deadline'];
	}

	/**
	 * Every kind of the database, as far as the budget reaches: first the
	 * index of identities of the site (AB_MCP_Sync_Identity), which the
	 * mapped forms need, then the kinds. Where the budget ends before the
	 * index is done, no object is read: the cursor says where to go on (the
	 * first kind at its start, or the cursor given), and index what to keep
	 * for the next call, which source() takes up.
	 *
	 * @param array      $source source().
	 * @param array|null $cursor array( 'kind' => name, 'inner' => the kind's
	 *                           cursor ); null at the start.
	 * @return array|WP_Error objects, cursor (null when every kind is done),
	 *                        index (AB_MCP_Sync_Identity::stored(): the text
	 *                        to keep, null where the one taken up stays).
	 */
	public static function run( array $source, $cursor = null ) {
		require_once __DIR__ . '/class-sync-db-kinds.php';
		$kinds = AB_MCP_Sync_Db_Kinds::all();
		if ( is_wp_error( $kinds ) ) {
			return $kinds;
		}
		$names = array();
		foreach ( $kinds as $kind ) {
			$names[] = $kind->kind();
		}
		$at = 0;
		if ( is_array( $cursor ) && isset( $cursor['kind'] ) ) {
			$found = array_search( (string) $cursor['kind'], $names, true );
			if ( false === $found ) {
				return new WP_Error( 'ab_mcp_sync_cursor_invalid', 'The cursor names no kind of this inventory. Use the cursor of the answer before, or start again without one.' );
			}
			$at = (int) $found;
		}
		$ready = AB_MCP_Sync_Identity::build(
			static function () use ( $source ) {
				return self::out_of_time( $source );
			}
		);
		if ( is_string( $ready ) ) {
			return self::unreadable( $ready );
		}
		$read = false === $ready ? array(
			'objects' => array(),
			'cursor'  => is_array( $cursor ) && isset( $cursor['kind'] ) ? $cursor : array(
				'kind'  => $names[0],
				'inner' => null,
			),
		) : self::kinds( $kinds, $names, $at, $source, $cursor );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		return $read + array( 'index' => AB_MCP_Sync_Identity::stored() );
	}

	/**
	 * The kinds from the one a cursor names, as far as the budget reaches.
	 *
	 * @param AB_MCP_Sync_Db_Kind[] $kinds  Kinds.
	 * @param string[]              $names  Their names.
	 * @param int                   $at     The kind to begin with.
	 * @param array                 $source source().
	 * @param array|null            $cursor Cursor.
	 * @return array|WP_Error objects, cursor.
	 */
	private static function kinds( array $kinds, array $names, $at, array $source, $cursor ) {
		$inner   = is_array( $cursor ) && isset( $cursor['inner'] ) ? $cursor['inner'] : null;
		$objects = array();
		for ( $i = $at; $i < count( $kinds ); $i++ ) {
			$part = $kinds[ $i ]->inventory( $source, $inner );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			foreach ( $part['objects'] as $object ) {
				$objects[] = $object;
			}
			if ( null !== $part['cursor'] ) {
				return array(
					'objects' => $objects,
					'cursor'  => array(
						'kind'  => $names[ $i ],
						'inner' => $part['cursor'],
					),
				);
			}
			$inner = null;
			if ( $i + 1 < count( $kinds ) && self::out_of_time( $source ) ) {
				return array(
					'objects' => $objects,
					'cursor'  => array(
						'kind'  => $names[ $i + 1 ],
						'inner' => null,
					),
				);
			}
		}
		return array(
			'objects' => $objects,
			'cursor'  => null,
		);
	}

	/**
	 * An object of the inventory: the result without its form, with the
	 * SHA-256 of its mapped form (mapped: every reference by id naming the
	 * identity of its target, AB_MCP_Sync_Identity; null where a target has
	 * none or there is no form), which the two sites compare (decision 10),
	 * and of the mapped form with its possible references by identity
	 * (mapped_possible, AB_MCP_Sync_Identity::possible_hash(); null where
	 * none hits an object), and twin where another object of this site has
	 * its identity: how many objects of the site have it
	 * (AB_MCP_Sync_Identity::twins(): such objects are paired only where
	 * every one of them is at hand, also in a package that holds only one of
	 * them; Codex rounds 4 and 5); for the report (branch 6, part 2) the
	 * post_status of a post and the number of meta rows of a form (meta).
	 *
	 * @param array $result AB_MCP_Sync_Canon::finish().
	 * @return array
	 */
	public static function entry( array $result ) {
		++self::$made;
		$twins = null === self::$ctx ? 0 : AB_MCP_Sync_Identity::twins( $result, self::$ctx );
		if ( $twins > 1 ) {
			$result['twin'] = $twins;
		}
		$result['mapped'] = isset( $result['form'], $result['hash'] ) && is_array( $result['form'] ) && null !== self::$ctx ? AB_MCP_Sync_Identity::mapped_hash( $result['form'], self::$ctx ) : null;
		// The same with the possible references by identity (Codex round 2, B-1).
		$result['mapped_possible'] = null !== $result['mapped'] && isset( $result['possible'] ) && is_array( $result['possible'] ) ? AB_MCP_Sync_Identity::possible_hash( $result['form'], $result['possible'], self::$ctx ) : null;
		// What the report reads of an object beside its hashes (branch 6,
		// part 2), no value of its content: the status of a post (the
		// trash is a deletion, an auto-draft no content), how many meta rows
		// its form holds (a new term with meta is locked, decision 28).
		if ( 'post' === ( $result['kind'] ?? null ) && isset( $result['form']['row']['post_status'] ) && is_string( $result['form']['row']['post_status'] ) ) {
			$result['post_status'] = $result['form']['row']['post_status'];
		}
		if ( isset( $result['form']['meta'] ) && is_array( $result['form']['meta'] ) ) {
			$result['meta'] = count( $result['form']['meta'] );
		}
		unset( $result['form'] );
		$result['status'] = self::status_of( $result );
		return $result;
	}

	/**
	 * The status of an object of the inventory (entry()), from the result of
	 * its kind: b9, guarded, unreachable and unreadable as the kind named
	 * them; z8 without a lock, locked with one, for a result that names none,
	 * core or z8 (the rule given once already: it holds again, entry() of an
	 * object make_objects() gave its status); locked for locked and any other
	 * (fail closed). The one place of the rule,
	 * for the inventory and for the package of objects alike
	 * (AB_MCP_Sync_Export::make_objects(): the results of export() go through
	 * it before they are sorted; branch 9: before, a result there carried no
	 * status, and the test for B9 in sort() never held).
	 *
	 * @param array $result AB_MCP_Sync_Canon::finish(), or a bare entry of a kind.
	 * @return string
	 */
	public static function status_of( array $result ) {
		$locks  = isset( $result['locks'] ) && is_array( $result['locks'] ) ? $result['locks'] : array( 'unknown' );
		$status = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : null;
		if ( null === $status || 'core' === $status || 'z8' === $status ) {
			return array() === $locks ? 'z8' : 'locked';
		}
		return in_array( $status, array( 'b9', 'guarded', 'unreachable', 'unreadable' ), true ) ? $status : 'locked';
	}

	/**
	 * The error of a table that could not be read.
	 *
	 * @param string $table Table.
	 * @return WP_Error
	 */
	public static function unreadable( $table ) {
		return new WP_Error( 'ab_mcp_sync_unreadable', sprintf( 'The table %s could not be read, so the inventory stopped. Try again; if it keeps failing, the database error log names the cause.', $table ), array( 'table' => $table ) );
	}

	/**
	 * @return float Seconds.
	 */
	private static function now() {
		return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
	}
}
