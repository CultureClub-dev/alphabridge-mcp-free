<?php
/**
 * What FreeSinceFourNineWidgetsMetaOpsTest reaches beyond the bootstrap and
 * the stubs of AnswerWayTest (taxonomies and terms): a registered widget
 * type, the two core functions the widget tools must not call, and the
 * writes of user and term meta. Loaded only by that test.
 *
 * The widget sanitises the way a real one does in its update(): it strips
 * tags from text, and it can refuse a save by returning false
 * ($GLOBALS['ab_test_widget_vetoes']). It also notes the instance number it
 * was told before it ran, as the widgets screen tells it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require_once __DIR__ . '/answer-way-stubs.php';

/** @var array[] $ab_test_meta_writes One entry per meta write: kind, object id, key, value. */
$GLOBALS['ab_test_meta_writes'] = array();

if ( ! class_exists( 'WP_Widget', false ) ) {
	/**
	 * A widget type with its own update(), as WordPress registers them.
	 */
	class WP_Widget {
		/** @var string */
		public $id_base;
		/** @var int */
		public $number = 0;

		public function __construct( $id_base ) {
			$this->id_base = (string) $id_base;
		}

		public function _set( $number ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- WordPress' name.
			$this->number = (int) $number;
		}

		/**
		 * Strips tags from text, notes the number it was told; false when the
		 * test says the widget refuses the save.
		 */
		public function update( $new_instance, $old_instance ) {
			if ( ! empty( $GLOBALS['ab_test_widget_vetoes'] ) ) {
				return false;
			}
			$clean = array();
			foreach ( (array) $new_instance as $key => $value ) {
				$clean[ $key ] = is_string( $value ) ? strip_tags( $value ) : $value;
			}
			$clean['seen_number'] = $this->number;
			return $clean;
		}
	}
}

if ( ! function_exists( 'wp_get_sidebars_widgets' ) ) {
	/** The filtered view of the sidebar map; the widget tools must not read it. */
	function wp_get_sidebars_widgets( $deprecated = true ) {
		$GLOBALS['ab_test_widget_violation'] = 'wp_get_sidebars_widgets';
		return get_option( 'sidebars_widgets', array() );
	}
}

if ( ! function_exists( 'wp_set_sidebars_widgets' ) ) {
	/** Pairs the write with the filtered read; the widget tools must not call it. */
	function wp_set_sidebars_widgets( $sidebars_widgets ) {
		$GLOBALS['ab_test_widget_violation'] = 'wp_set_sidebars_widgets';
		update_option( 'sidebars_widgets', $sidebars_widgets );
	}
}

// The store of user and term meta ($ab_test_meta_store, with the keys
// $ab_test_meta_vetoes keeps from being stored), ab_test_stored_meta() and
// the user meta functions are in the bootstrap: the settings page reads a
// user meta too (since 4.15.0).

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $meta_key, $meta_value, $prev_value = '' ) {
		$GLOBALS['ab_test_meta_writes'][] = array( 'term', (int) $term_id, (string) $meta_key, ab_test_unslash_deep( $meta_value ) );
		if ( in_array( (string) $meta_key, $GLOBALS['ab_test_meta_vetoes'], true ) ) {
			return false;
		}
		$GLOBALS['ab_test_meta_store']['term'][ (int) $term_id ][ (string) $meta_key ] = array( ab_test_stored_meta( $meta_value ) );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/** No object cache here: the read-back reads the store. */
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		$values = $GLOBALS['ab_test_meta_store']['term'][ (int) $term_id ][ (string) $key ] ?? array();
		return $single ? ( $values ? $values[0] : '' ) : $values;
	}
}
