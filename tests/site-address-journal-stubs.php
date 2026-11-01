<?php
/**
 * The database as AB_MCP_Site_Address_Journal reads it: two queries, the
 * value of an option's row by its name and the count of rows of that name, answered from the options of the
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
	/** @var string[] Names whose count (only) fails so. */
	public $fail_count = array();

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
		$count            = false;
		if ( preg_match( "/^SELECT COUNT\\(\\*\\) FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)'$/", (string) $query, $m ) ) {
			$count = true;
		} elseif ( ! preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1$/", (string) $query, $m ) ) {
			$this->last_error = 'You have an error in your SQL syntax';
			return null;
		}
		$name = stripslashes( $m[1] );
		if ( in_array( $name, $this->fail, true ) || ( $count && in_array( $name, $this->fail_count, true ) ) ) {
			$this->last_error = "Table 'wp_options' is marked as crashed and should be repaired";
			return null;
		}
		if ( array_key_exists( $name, $this->rows ) ) {
			$value = $this->rows[ $name ];
		} elseif ( array_key_exists( $name, $GLOBALS['ab_test_options'] ) ) {
			$value = $GLOBALS['ab_test_options'][ $name ];
			$value = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
		} else {
			$value = null;
		}
		if ( $count ) {
			return null === $value ? '0' : '1';
		}
		// As wpdb::get_var() answers: an empty value reads as null, the same
		// as no row (class-wpdb.php, '' !== $values[ $x ]).
		return null === $value || '' === $value ? null : $value;
	}
}
