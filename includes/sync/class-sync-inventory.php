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
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Inventory
 */
final class AB_MCP_Sync_Inventory {

	/** Seconds one call may read (AB_MCP_Tools_Search_Bulk::STATUS_BUDGET). */
	const BUDGET = 60;

	/** The clock of the budget; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/**
	 * The source of an inventory on this site.
	 *
	 * @param string[]      $addresses The addresses of both sites (neutral).
	 * @param int|float     $budget    Seconds.
	 * @param callable|null $users     Reader of user logins.
	 * @return array ctx, deadline.
	 */
	public static function source( array $addresses, $budget = self::BUDGET, $users = null ) {
		return array(
			'ctx'      => AB_MCP_Sync_Canon::context( $addresses, $users ),
			'deadline' => self::now() + max( 0, (float) $budget ),
		);
	}

	/**
	 * Whether the budget of a source is used up.
	 *
	 * @param mixed $source Source.
	 * @return bool
	 */
	public static function out_of_time( $source ) {
		return is_array( $source ) && isset( $source['deadline'] ) && self::now() >= (float) $source['deadline'];
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
	 * An object of the inventory: the result without its form.
	 *
	 * @param array $result AB_MCP_Sync_Canon::finish().
	 * @return array
	 */
	public static function entry( array $result ) {
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
