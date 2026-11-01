<?php
/**
 * What the unit tests of the takeover's tables need beyond the bootstrap:
 * dbDelta(), recorded, and a $wpdb that answers the three questions
 * AB_MCP_Sync_Schema::check() asks (is the table there, its columns, its
 * unique keys) from what a test sets, and records every query.
 *
 * It reads the table name out of those three queries and nothing else:
 * whether the SQL of the tables, the keys and the conditional writes does
 * what it says is shown against MariaDB in tests/integration (a stand-in
 * that answers SQL proves nothing about it).
 *
 * @package AlphaBridge_MCP
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress's name.
		$GLOBALS['ab_test_dbdelta'][] = $queries;
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof AbTestSyncWpdb && null !== $GLOBALS['wpdb']->after_dbdelta ) {
			$GLOBALS['wpdb']->tables = $GLOBALS['wpdb']->after_dbdelta;
		}
		return array();
	}
}

/**
 * The $wpdb of the takeover's unit tests.
 */
final class AbTestSyncWpdb {
	public $prefix     = 'wp_';
	/** The options table, which the protected store reads its id from (Z8). */
	public $options    = 'wp_options';
	public $last_error = '';
	public $insert_id  = 0;
	/** @var string[] Every query, in order. */
	public $queries = array();
	/** @var array<string,true> Tables that are there, by full name. */
	public $tables = array();
	/** @var array<string,true>|null The tables after dbDelta() ran, when a test sets it. */
	public $after_dbdelta = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = (string) preg_replace_callback(
				'/%[sd]/',
				static function ( $m ) use ( $arg ) {
					return '%d' === $m[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}

	public function suppress_errors( $on = true ) {
		return false;
	}

	public function get_var( $query ) {
		$this->queries[] = (string) $query;
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", (string) $query, $m ) ) {
			$name = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[1] ) );
			return isset( $this->tables[ $name ] ) ? $name : null;
		}
		return null;
	}

	public function get_col( $query, $x = 0 ) {
		$this->queries[] = (string) $query;
		if ( preg_match( '/^SHOW COLUMNS FROM `([a-z_]+)`$/', (string) $query, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return array_keys( AB_MCP_Sync_Schema::COLUMNS[ self::short( $m[1] ) ] );
		}
		if ( preg_match( '/^SHOW INDEX FROM `([a-z_]+)` WHERE Non_unique = 0$/', (string) $query, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return AB_MCP_Sync_Schema::UNIQUE[ self::short( $m[1] ) ];
		}
		return array();
	}

	public function get_row( $query, $output = null ) {
		$this->queries[] = (string) $query;
		return null;
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = (string) $query;
		return array();
	}

	public function query( $query ) {
		$this->queries[] = (string) $query;
		return 0;
	}

	public function insert( $table, $data, $format = null ) {
		$this->queries[] = 'INSERT ' . $table;
		return false;
	}

	/** @return string 'pairs', 'runs' or 'steps' for a full table name. */
	private static function short( $name ) {
		return (string) array_search( substr( $name, 3 ), AB_MCP_Sync_Schema::TABLES, true );
	}

	/** Every table of the takeover, as there. */
	public static function all_tables(): array {
		$out = array();
		foreach ( AB_MCP_Sync_Schema::TABLES as $table ) {
			$out[ 'wp_' . $table ] = true;
		}
		return $out;
	}
}
