<?php
/**
 * WooCommerce for the writing shop tools (wp_wc_update_order_status,
 * wp_wc_update_product, wp_wc_create_coupon) in FreeSinceFourNineUsersShopTest:
 * an order, a product and WC_Coupon that note what the tools hand over, and
 * behave where it matters as WooCommerce does (an unknown status becomes
 * "pending", set_code() normalises, set_discount_type() throws, a percentage
 * over 100 is refused). The same stage as Pro's test/wc-input-test.php.
 * Since 4.14.0 the getters take the context, and a coupon is read again by
 * its id: the tools read back what was stored. The undo itself is checked
 * by tests/standalone/undo-shop-test.php.
 *
 * WooCommerce cannot be unloaded, and tests/woo-stubs.php declares some of
 * the same functions for the reading tools. So this file is loaded only in a
 * test that runs in a process of its own (RunInSeparateProcess, without the
 * parent's global state), never next to woo-stubs.php, and never in the
 * process where FreeSinceFourSevenTest checks the answer without WooCommerce.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

class WooCommerce {
}

/** Only for the tools' check that WooCommerce is active. */
function wc_get_orders( $args = array() ) {
	return (object) array(
		'orders'        => array(),
		'total'         => 0,
		'max_num_pages' => 0,
	);
}

/**
 * Core statuses, one a plugin registered properly, one a careless plugin
 * registered WITHOUT the wc- prefix (it stands in this list, but
 * WC_Order::set_status() looks up "wc-packed" and does not find it), and one
 * with an odd key that does work.
 */
function wc_get_order_statuses() {
	return array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-refunded'   => 'Refunded',
		'wc-failed'     => 'Failed',
		'wc-shipped'    => 'Shipped (plugin)',
		'packed'        => 'Packed (careless plugin)',
		'wc-wc-packed'  => 'Packed (plugin with an odd key)',
	);
}

/** An order of type shop_order. */
final class AB_Test_WC_Write_Order {
	public $id;
	public $status;
	public $calls = array();
	public $notes = array();

	public function __construct( int $id, string $status ) {
		$this->id     = $id;
		$this->status = $status;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_type() {
		return 'shop_order';
	}

	/**
	 * Like WooCommerce: the view context runs woocommerce_order_get_status, and
	 * a plugin may show two statuses under one name ($GLOBALS['ab_test_wc_status_filter']);
	 * edit returns what is stored.
	 */
	public function get_status( $context = 'view' ) {
		return ( 'view' === $context && ! empty( $GLOBALS['ab_test_wc_status_filter'] ) ) ? 'in Arbeit' : $this->status;
	}

	/**
	 * Like WooCommerce: one wc- prefix is stripped, then "wc-" . that must be a
	 * registered key, otherwise the status silently becomes "pending", the
	 * status that puts the stock back. False when the save fails
	 * ($GLOBALS['ab_test_wc_save_fails']).
	 */
	public function update_status( $status, $note = '', $manual = false ) {
		$this->calls[] = array( $status, $note, $manual );
		$bare          = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		$this->status  = array_key_exists( 'wc-' . $bare, wc_get_order_statuses() ) ? $bare : 'pending';
		return empty( $GLOBALS['ab_test_wc_save_fails'] );
	}

	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->notes[] = array( $note, $is_customer_note, $added_by_user );
		return 1;
	}
}

/** A product: the setters note what they get, the getters read it back. */
final class AB_Test_WC_Write_Product {
	public $id;
	public $set = array();

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function set_regular_price( $v ) {
		$this->set['regular_price'] = $v;
	}

	public function set_sale_price( $v ) {
		$this->set['sale_price'] = $v;
	}

	public function set_manage_stock( $v ) {
		$this->set['manage_stock'] = $v;
	}

	public function set_stock_quantity( $v ) {
		$this->set['stock'] = $v;
	}

	public function set_status( $v ) {
		$this->set['status'] = $v;
	}

	public function save() {
		if ( ! empty( $GLOBALS['ab_test_wc_save_fails'] ) ) {
			return 0;
		}
		$this->set['saved'] = true;
		return $this->id;
	}

	public function get_name() {
		return 'Widget';
	}

	public function get_sku() {
		return 'W-1';
	}

	public function get_type() {
		return 'simple';
	}

	public function get_parent_id( $context = 'view' ) {
		return 0;
	}

	public function get_price() {
		return $this->set['sale_price'] ?? $this->set['regular_price'] ?? '10';
	}

	public function get_regular_price( $context = 'view' ) {
		return $this->set['regular_price'] ?? '10';
	}

	public function get_sale_price( $context = 'view' ) {
		return $this->set['sale_price'] ?? '';
	}

	public function get_date_on_sale_from( $context = 'view' ) {
		return null;
	}

	public function get_date_on_sale_to( $context = 'view' ) {
		return null;
	}

	public function get_manage_stock( $context = 'view' ) {
		return $this->set['manage_stock'] ?? true;
	}

	public function get_stock_quantity( $context = 'view' ) {
		return $this->set['stock'] ?? 3;
	}

	public function get_stock_status( $context = 'view' ) {
		return 'instock';
	}

	public function get_status( $context = 'view' ) {
		return $this->set['status'] ?? 'publish';
	}
}

/** Order 5 (processing), product 7. */
function ab_test_wc_reset(): void {
	$GLOBALS['ab_test_wc_order']         = new AB_Test_WC_Write_Order( 5, 'processing' );
	$GLOBALS['ab_test_wc_product']       = new AB_Test_WC_Write_Product( 7 );
	$GLOBALS['ab_test_wc_save_fails']    = false;
	$GLOBALS['ab_test_wc_status_filter'] = false;
	$GLOBALS['ab_test_wc_dec']           = '.';
	$GLOBALS['ab_test_wc_coupon_prefix'] = null;
	WC_Coupon::$saved                    = array();
}

function wc_get_order( $id ) {
	return 5 === (int) $id ? $GLOBALS['ab_test_wc_order'] : false;
}

function wc_get_product( $id ) {
	return 7 === (int) $id ? $GLOBALS['ab_test_wc_product'] : false;
}

/** Product 7 is a product, 99 the coupon every save makes. */
function get_post_type( $post = null ) {
	return 7 === (int) $post ? 'product' : ( 99 === (int) $post ? 'shop_coupon' : false );
}

function wc_get_price_decimal_separator() {
	return $GLOBALS['ab_test_wc_dec'];
}

function wc_get_coupon_types() {
	return array(
		'percent'       => 'Percentage discount',
		'fixed_cart'    => 'Fixed cart discount',
		'fixed_product' => 'Fixed product discount',
	);
}

/** "existing" is in use. */
function wc_get_coupon_id_by_code( $code, $exclude = 0 ) {
	return 'existing' === $code ? 42 : 0;
}

/** Like WooCommerce's woocommerce_coupon_code filters: entities decoded, tags out, lower-case. */
function wc_format_coupon_code( $code ) {
	return strtolower( trim( strip_tags( html_entity_decode( (string) $code ) ) ) );
}

/** A new coupon: what the tool sets, and the saves in WC_Coupon::$saved; read by its id (99), the last save. */
class WC_Coupon {
	public static $saved = array();
	public $set          = array();
	public $id           = 0;

	public function __construct( $data = 0 ) {
		if ( 99 === $data && array() !== self::$saved ) {
			$this->id  = 99;
			$this->set = end( self::$saved );
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_amount( $context = 'view' ) {
		return $this->set['amount'] ?? '';
	}

	public function get_discount_type( $context = 'view' ) {
		return $this->set['discount_type'] ?? 'fixed_cart';
	}

	public function get_date_expires( $context = 'view' ) {
		$day = $this->set['expires'] ?? null;
		return null === $day ? null : new class( $day ) {
			private $day;
			public function __construct( $day ) {
				$this->day = $day;
			}
			public function date( $format ) {
				return $this->day;
			}
		};
	}

	/** Like WooCommerce: set_code() normalises on its own. */
	public function set_code( $v ) {
		$this->set['code'] = wc_format_coupon_code( $v );
	}

	/** Like WooCommerce: a percentage over 100 is refused by the setter. */
	public function set_amount( $v ) {
		if ( 'percent' === ( $this->set['discount_type'] ?? 'percent' ) && (float) $v > 100 ) {
			throw new Exception( 'Coupon discount amount must be less than 100 percent.' );
		}
		$this->set['amount'] = $v;
	}

	/** Like WooCommerce: its own compatibility alias, then a throw for anything the shop does not know. */
	public function set_discount_type( $v ) {
		if ( 'percent_product' === $v ) {
			$v = 'percent';
		}
		if ( ! in_array( $v, array_keys( wc_get_coupon_types() ), true ) ) {
			throw new Exception( 'Invalid discount type' );
		}
		$this->set['discount_type'] = $v;
	}

	public function set_date_expires( $v ) {
		$this->set['expires'] = $v;
	}

	/** Like WooCommerce: the view context runs woocommerce_coupon_get_code (a display prefix here), edit returns what is stored. */
	public function get_code( $context = 'view' ) {
		$code = $this->set['code'] ?? '';
		return 'view' === $context && null !== $GLOBALS['ab_test_wc_coupon_prefix'] ? $GLOBALS['ab_test_wc_coupon_prefix'] . $code : $code;
	}

	public function save() {
		if ( ! empty( $GLOBALS['ab_test_wc_save_fails'] ) ) {
			return 0;
		}
		self::$saved[] = $this->set;
		return 99;
	}
}
