<?php
/**
 * Two checks of the writing shop tools that the test bootstrap cannot show,
 * run by FreeSinceFourNineUsersShopTest in a PHP process of its own, without
 * tests/bootstrap.php. It prints one JSON object; the test reads it.
 *
 *  - The capability asked for the one order or product is the edit_post of
 *    its post type, under that name (edit_shop_order, edit_product, also for
 *    a variation), as WooCommerce's REST API asks it; edit_post where the
 *    type is not registered. The bootstrap's get_post_type_object() knows
 *    WordPress's own types only, so there every type falls back to edit_post.
 *  - A coupon code with a backslash is refused. The bootstrap's wp_unslash()
 *    leaves a value as it is; WordPress's strips the backslashes, and that
 *    is what the check is about.
 *
 * The rest of WordPress here is just what these calls reach (since 4.14.0
 * also the getters the read-back after the write asks). The same stage as
 * Pro's test/object-rights-test.php (R3, R4) and test/wc-input-test.php.
 *
 * Run: php tests/shop-probe.php
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function __( $text, $domain = '' ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function get_current_user_id() {
	return 3;
}
function wp_get_current_user() {
	return (object) array( 'ID' => 3, 'user_login' => 'shopmanager' );
}
/** As WordPress: strips the backslashes. */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

// Every capability asked, with its object; $ab_deny lists "cap:id" refused.
$GLOBALS['ab_asked'] = array();
$GLOBALS['ab_deny']  = array();
function current_user_can( $cap, ...$args ) {
	$key                   = $cap . ( $args ? ':' . (int) $args[0] : '' );
	$GLOBALS['ab_asked'][] = $key;
	return ! in_array( $key, $GLOBALS['ab_deny'], true );
}

// The post types as WooCommerce registers them: edit_post of shop_order is
// edit_shop_order; product_variation borrows the capabilities of product.
// $ab_types_gone: as if the types were not registered.
$GLOBALS['ab_types_gone'] = false;
function get_post_type_object( $type ) {
	$caps = array(
		'shop_order'        => 'edit_shop_order',
		'product'           => 'edit_product',
		'product_variation' => 'edit_product',
	);
	return ! $GLOBALS['ab_types_gone'] && isset( $caps[ $type ] ) ? (object) array( 'cap' => (object) array( 'edit_post' => $caps[ $type ] ) ) : null;
}
// Product 7 is a product, 8 a variation, 99 the coupon every save makes.
function get_post_type( $post = null ) {
	return 7 === (int) $post ? 'product' : ( 8 === (int) $post ? 'product_variation' : ( 99 === (int) $post ? 'shop_coupon' : false ) );
}

class WooCommerce {
}
function wc_get_orders( $args = array() ) {
	return array();
}
function wc_get_order_statuses() {
	return array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-completed'  => 'Completed',
	);
}
final class AB_Probe_Order {
	public $calls  = array();
	public $notes  = array();
	public $status = 'processing';
	public function get_id() {
		return 5;
	}
	public function get_type() {
		return 'shop_order';
	}
	public function get_status( $context = 'view' ) {
		return $this->status;
	}
	public function update_status( $status, $note = '', $manual = false ) {
		$this->calls[] = $status;
		$this->status  = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		return true;
	}
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->notes[] = $note;
		return 1;
	}
}
final class AB_Probe_Product {
	public $set = array();
	public $id  = 7;
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
		return 8 === $this->id ? 'variation' : 'simple';
	}
	public function get_parent_id( $context = 'view' ) {
		return 8 === $this->id ? 7 : 0;
	}
	public function get_price() {
		return '10';
	}
	public function get_regular_price( $context = 'view' ) {
		return $this->set['regular_price'] ?? '10';
	}
	public function get_sale_price( $context = 'view' ) {
		return '';
	}
	public function get_manage_stock( $context = 'view' ) {
		return true;
	}
	public function get_stock_quantity( $context = 'view' ) {
		return 3;
	}
	public function get_stock_status( $context = 'view' ) {
		return 'instock';
	}
	public function get_status( $context = 'view' ) {
		return 'publish';
	}
}
$GLOBALS['ab_order']   = new AB_Probe_Order();
$GLOBALS['ab_product'] = new AB_Probe_Product();
function wc_get_order( $id ) {
	return 5 === (int) $id ? $GLOBALS['ab_order'] : false;
}
function wc_get_product( $id ) {
	if ( in_array( (int) $id, array( 7, 8 ), true ) ) {
		$GLOBALS['ab_product']->id = (int) $id;
		return $GLOBALS['ab_product'];
	}
	return false;
}
function wc_get_price_decimal_separator() {
	return '.';
}
function wc_get_coupon_types() {
	return array( 'percent' => 'Percentage discount' );
}
function wc_get_coupon_id_by_code( $code, $exclude = 0 ) {
	return 0;
}
function wc_format_coupon_code( $code ) {
	return strtolower( trim( strip_tags( html_entity_decode( (string) $code ) ) ) );
}
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
		return null;
	}
	public function set_code( $v ) {
		$this->set['code'] = wc_format_coupon_code( $v );
	}
	public function get_code( $context = 'view' ) {
		return $this->set['code'] ?? '';
	}
	public function set_discount_type( $v ) {
		$this->set['discount_type'] = $v;
	}
	public function set_amount( $v ) {
		$this->set['amount'] = $v;
	}
	public function set_date_expires( $v ) {
		$this->set['expires'] = $v;
	}
	public function save() {
		self::$saved[] = $this->set;
		return 99;
	}
}

require_once __DIR__ . '/../includes/class-guidance.php';
require_once __DIR__ . '/../includes/tools/class-tools-shop.php';

/** The answer as data: its error code, or 'ok'. */
function ab_code( $res ): string {
	return is_wp_error( $res ) ? (string) $res->get_error_code() : 'ok';
}

/** One call with a fresh order, product and record; what came of it. */
function ab_run( callable $call, array $deny = array(), bool $types_gone = false ): array {
	$GLOBALS['ab_asked']      = array();
	$GLOBALS['ab_deny']       = $deny;
	$GLOBALS['ab_types_gone'] = $types_gone;
	$GLOBALS['ab_order']      = new AB_Probe_Order();
	$GLOBALS['ab_product']    = new AB_Probe_Product();
	WC_Coupon::$saved         = array();
	$res                      = $call();
	return array(
		'code'    => ab_code( $res ),
		'asked'   => $GLOBALS['ab_asked'],
		'calls'   => $GLOBALS['ab_order']->calls,
		'notes'   => $GLOBALS['ab_order']->notes,
		'status'  => $GLOBALS['ab_order']->status,
		'set'     => $GLOBALS['ab_product']->set,
		'coupons' => WC_Coupon::$saved,
	);
}

$order   = static fn() => AB_MCP_Tools_Shop::update_order_status( array( 'id' => 5, 'status' => 'completed', 'note' => 'Versandt' ) );
$product = static fn( int $id ) => static fn() => AB_MCP_Tools_Shop::update_product( array( 'id' => $id, 'regular_price' => '12.50' ) );
$coupon  = static fn( string $code ) => static fn() => AB_MCP_Tools_Shop::create_coupon( array( 'code' => $code, 'amount' => '1' ) );

echo json_encode(
	array(
		'order_denied'      => ab_run( $order, array( 'edit_shop_order:5' ) ),
		'order_allowed'     => ab_run( $order ),
		'order_type_gone'   => ab_run( $order, array( 'edit_post:5' ), true ),
		'product_denied'    => ab_run( $product( 7 ), array( 'edit_product:7' ) ),
		'product_allowed'   => ab_run( $product( 7 ) ),
		'variation_denied'  => ab_run( $product( 8 ), array( 'edit_product:8' ) ),
		'coupon_backslash'  => ab_run( $coupon( 'sale\\10' ) ),
		'coupon_lone'       => ab_run( $coupon( '\\' ) ),
		'coupon_double'     => ab_run( $coupon( 'a\\\\b' ) ),
		'coupon_plain'      => ab_run( $coupon( 'sale10' ) ),
	)
);
