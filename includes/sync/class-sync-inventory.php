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
	 * @return array ctx, deadline, objects.
	 */
	public static function source( array $addresses, $budget = self::BUDGET, $users = null, $objects = null ) {
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
		AB_MCP_Sync_Identity::reset();
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
	 * Every kind of the database, as far as the budget reaches.
	 *
	 * @param array      $source source().
	 * @param array|null $cursor array( 'kind' => name, 'inner' => the kind's
	 *                           cursor ); null at the start.
	 * @return array|WP_Error objects, cursor (null when every kind is done).
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
	 * none or there is no form), which the two sites compare (decision 10).
	 *
	 * @param array $result AB_MCP_Sync_Canon::finish().
	 * @return array
	 */
	public static function entry( array $result ) {
		++self::$made;
		$result['mapped'] = isset( $result['form'], $result['hash'] ) && is_array( $result['form'] ) && null !== self::$ctx ? AB_MCP_Sync_Identity::mapped_hash( $result['form'], self::$ctx ) : null;
		unset( $result['form'] );
		if ( ! isset( $result['status'] ) ) {
			$result['status'] = array() === $result['locks'] ? 'z8' : 'locked';
		} elseif ( 'core' === $result['status'] ) {
			$result['status'] = array() === $result['locks'] ? 'z8' : 'locked';
		} elseif ( ! in_array( $result['status'], array( 'b9', 'guarded', 'unreachable', 'unreadable' ), true ) ) {
			$result['status'] = 'locked';
		}
		return $result;
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
