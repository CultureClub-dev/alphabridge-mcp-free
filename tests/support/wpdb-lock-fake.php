<?php
/**
 * A stand-in for WordPress' wpdb for the lock of the well-known relay
 * (AB_MCP_OAuth::well_known_lock()), used by WellKnownRelayCacheTest.
 *
 * It knows exactly the three statements of that lock on the options table:
 * INSERT IGNORE (1 row, or 0 where the name exists), DELETE of an expired
 * row and delete() by name; with $fail every statement fails as a database
 * error does (false), as without the right to insert. Any other SQL throws, so a changed statement
 * fails the test instead of passing unseen. Whether INSERT IGNORE is atomic
 * is a property of the database; it is checked against MariaDB in the
 * probe of the hotfix, not here.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! class_exists( 'wpdb', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile,PEAR.NamingConventions.ValidClassName
	class wpdb {
		/** @var string */
		public $options = 'wp_options';
		/** @var array<string,string> option_name => option_value */
		public $rows = array();
		/** @var string[] */
		public $queries = array();
		/** @var bool When true, every statement fails as a database error does (query() returns false). */
		public $fail = false;
		/** @var bool When true, only the DELETE of an expired lock fails so. */
		public $fail_delete = false;
		/** @var bool */
		public $suppressed = false;
		/** @var bool[] Whether errors were suppressed at each delete(). */
		public $suppressed_at_delete = array();
		/** @var bool[] Whether errors were suppressed at each query(). */
		public $suppressed_at_query = array();

		public function suppress_errors( $suppress = true ) {
			$old              = $this->suppressed;
			$this->suppressed = (bool) $suppress;
			return $old;
		}

		public function prepare( $query, ...$args ) {
			$i = 0;
			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( array $m ) use ( &$i, $args ): string {
					$value = $args[ $i++ ];
					return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function query( $sql ) {
			$this->queries[]             = $sql;
			$this->suppressed_at_query[] = $this->suppressed;
			if ( $this->fail ) {
				return false;
			}
			if ( preg_match( "/\\AINSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '([^']*)', 'no'\\)\\z/", $sql, $m ) ) {
				if ( array_key_exists( $m[1], $this->rows ) ) {
					return 0;
				}
				$this->rows[ $m[1] ] = $m[2];
				return 1;
			}
			if ( preg_match( "/\\ADELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value < (\\d+)\\z/", $sql, $m ) ) {
				if ( $this->fail_delete ) {
					return false;
				}
				if ( array_key_exists( $m[1], $this->rows ) && (int) $this->rows[ $m[1] ] < (int) $m[2] ) {
					unset( $this->rows[ $m[1] ] );
					return 1;
				}
				return 0;
			}
			throw new RuntimeException( 'Unexpected SQL: ' . $sql );
		}

		public function delete( $table, array $where ) {
			$this->suppressed_at_delete[] = $this->suppressed;
			if ( 'wp_options' !== $table || array( 'option_name' ) !== array_keys( $where ) ) {
				throw new RuntimeException( 'Unexpected delete: ' . $table . ' ' . json_encode( $where ) );
			}
			$found = array_key_exists( $where['option_name'], $this->rows );
			unset( $this->rows[ $where['option_name'] ] );
			return $found ? 1 : 0;
		}
	}
}
