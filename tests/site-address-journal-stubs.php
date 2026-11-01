<?php
/**
 * The database as AB_MCP_Site_Address_Journal reads it: one query, the
 * value of an option's row by its name, answered from the options of the
 * bootstrap, or from rows set here where the row differs from what
 * get_option() returns (a filter on the option, a row a hook writes back).
 * Any other query is answered as MySQL answers a query it cannot run: no
 * value and last_error set, which the class reads as a failed lookup. So a
 * query that names another column, table or option does not pass here.
 *
 * @package AlphaBridge_MCP
 */

/**
 * The $wpdb of the journal's tests.
 */
final class AbTestJournalWpdb {
	public $options    = 'wp_options';
	public $last_error = '';
	/** @var string[] Every query, in order. */
	public $queries = array();
	/** @var array<string,string|null> Name => the row's value (null: no row), where it differs from get_option(). */
	public $rows = array();
	/** @var string[] Names whose lookup fails as a broken table answers. */
	public $fail = array();

	public function db_version() {
		return '8.0.36';
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace_callback(
				'/%s/',
				static function () use ( $arg ) {
					return "'" . addslashes( (string) $arg ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[]  = (string) $query;
		$this->last_error = '';
		if ( ! preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1$/", (string) $query, $m ) ) {
			$this->last_error = 'You have an error in your SQL syntax';
			return null;
		}
		$name = stripslashes( $m[1] );
		if ( in_array( $name, $this->fail, true ) ) {
			$this->last_error = "Table 'wp_options' is marked as crashed and should be repaired";
			return null;
		}
		if ( array_key_exists( $name, $this->rows ) ) {
			return $this->rows[ $name ];
		}
		if ( ! array_key_exists( $name, $GLOBALS['ab_test_options'] ) ) {
			return null;
		}
		$value = $GLOBALS['ab_test_options'][ $name ];
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}
}
