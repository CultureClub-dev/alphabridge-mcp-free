<?php
/**
 * What FreeSinceFourSevenTest reaches beyond the bootstrap: navigation menus,
 * a user query, the installed themes, the cron array and the content folder.
 * Each write notes its call in $GLOBALS['ab_test_menu_writes'], so a test sees
 * whether anything was written. WooCommerce comes from woo-stubs.php, which
 * the test loads only after it has checked the answer without WooCommerce.
 * Loaded only by that test.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/** @var array[] $ab_test_menu_writes One entry per write: its kind and what it was handed. */
$GLOBALS['ab_test_menu_writes'] = array();

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ab-mcp-test-content-' . getmypid() );
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	/** The menus the test set ($GLOBALS['ab_test_nav_menus'], term id => object). */
	function wp_get_nav_menus( $args = array() ) {
		return array_values( $GLOBALS['ab_test_nav_menus'] ?? array() );
	}
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	/** As WordPress: a menu by id, slug or name, false for none. */
	function wp_get_nav_menu_object( $menu ) {
		foreach ( $GLOBALS['ab_test_nav_menus'] ?? array() as $m ) {
			if ( (string) $m->term_id === (string) $menu || $m->slug === (string) $menu || $m->name === (string) $menu ) {
				return $m;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( $menu, $args = array() ) {
		return $GLOBALS['ab_test_nav_items'][ (int) $menu ] ?? false;
	}
}

if ( ! function_exists( 'wp_create_nav_menu' ) ) {
	/** Notes the name as handed over and answers a new term id. */
	function wp_create_nav_menu( $menu_name ) {
		$GLOBALS['ab_test_menu_writes'][] = array( 'create', $menu_name );
		return 77;
	}
}

if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
	/** Notes the item data and answers a new item id. */
	function wp_update_nav_menu_item( $menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array(), $fire_after_hooks = true ) {
		$GLOBALS['ab_test_menu_writes'][] = array( 'item', (int) $menu_id, $menu_item_data );
		return 900;
	}
}

if ( ! function_exists( 'is_nav_menu_item' ) ) {
	/** True for the ids the test listed in $GLOBALS['ab_test_nav_item_ids']. */
	function is_nav_menu_item( $menu_item_id = 0 ) {
		return in_array( (int) $menu_item_id, $GLOBALS['ab_test_nav_item_ids'] ?? array(), true );
	}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	/** Notes its arguments and answers the users of $GLOBALS['ab_test_user_rows']. */
	class WP_User_Query {
		public $query_vars;
		public function __construct( $args = array() ) {
			$this->query_vars            = $args;
			$GLOBALS['ab_test_user_query'] = $args;
		}
		public function get_results() {
			return $GLOBALS['ab_test_user_rows'] ?? array();
		}
		public function get_total() {
			return count( $GLOBALS['ab_test_user_rows'] ?? array() ) + (int) ( $GLOBALS['ab_test_user_more'] ?? 0 );
		}
	}
}

if ( ! function_exists( 'wp_get_themes' ) ) {
	/** The themes the test set: slug => [ name, version ]. */
	function wp_get_themes( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['ab_test_themes'] ?? array() as $slug => $info ) {
			$out[ $slug ] = new class( $info ) {
				private $info;
				public function __construct( array $info ) {
					$this->info = $info;
				}
				public function get( $header ) {
					return 'Name' === $header ? $this->info[0] : ( 'Version' === $header ? $this->info[1] : '' );
				}
			};
		}
		return $out;
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	function _get_cron_array() {
		return $GLOBALS['ab_test_cron'] ?? false;
	}
}
