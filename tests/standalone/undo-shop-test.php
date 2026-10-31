<?php
/**
 * The undo of the writing shop tools (kinds wc_product and wc_coupon, since
 * 4.14.0) and their read-back, without WordPress and WooCommerce: products,
 * coupons and an order are in-memory rows, behind objects that behave where
 * it matters as WooCommerce's do. A product's getters give the stored value
 * in the edit context and run a filter in the view context, its setters
 * store as WooCommerce's do (wc_format_decimal(), '' clears a quantity, an
 * unknown stock status is instock), and save() aligns the stock first, as
 * WC_Product::validate_props() does — without stock management no quantity,
 * with it the stock status from the quantity —, writes only what changed
 * against the object as loaded (WC_Data::set_prop()), drops a sale price
 * that is not below the regular one and derives the price, as the data
 * store's handle_updated_props() does; the end of a sale sets only the
 * derived price, as wc_apply_sale_state_for_product() does.
 * wc_update_product_stock() is there to show that no undo calls it. A coupon is read by its id with all its
 * data and meta, counts its use and the orders that hold it, and
 * WC_Coupon::delete() without force moves it into the trash through
 * wp_trash_post(), which deletes for good where EMPTY_TRASH_DAYS is 0. The
 * undo store, the restore, wp_undo and the shop tools are the real ones.
 *
 * Proves: wp_wc_update_product records one point with only what it changed,
 * the stored prices as one, read through the edit context from the object
 * as it was saved; wp_undo sets the old prices and status through the
 * setters and save() and compares them again; a price or status changed
 * since — a sale price set since included — is refused unless force, the end
 * of a sale is not; the stock is no part of the point: the answer says so
 * with the stock right before the save, a call that changes only the stock
 * gets no point, and the undo leaves the stock as it is, also that of a
 * variation that had its parent's; the rights of the tool (edit_product, publish_products for a
 * status that takes it live) are asked again; a product of another type,
 * gone or in the trash is not written; wp_wc_create_coupon records a point
 * that moves the coupon into the trash while none of its data — a
 * restriction set by meta alone included — has changed and it is unused,
 * with the right to delete it, and none where the trash is off (in a process
 * of its own: EMPTY_TRASH_DAYS is a constant); wp_wc_update_order_status
 * says it is not undoable, also for a note alone, and that nothing changed
 * without one; a hand-edited point gains no right; no answer about a point
 * shows a price or a code.
 *
 * Run:  php tests/standalone/undo-shop-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$GLOBALS['ab_trash_off'] = '1' === getenv( 'AB_TRASH_OFF' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'EMPTY_TRASH_DAYS', $GLOBALS['ab_trash_off'] ? 0 : 30 );

$GLOBALS['ab_free'] = dirname( __DIR__, 2 ) . '/includes/';

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $d = null ) {
		$this->code    = $c;
		$this->message = $m;
		$this->data    = $d;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }

/* ---- identity and capabilities: everything, unless a test takes it away ---- */
$GLOBALS['ab_uid']   = 7;
$GLOBALS['ab_deny']  = array(); // 'cap' or 'cap:obj' => true
$GLOBALS['ab_asked'] = array();
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, ...$args ) {
	$one                   = $cap . ( $args ? ':' . (string) $args[0] : '' );
	$GLOBALS['ab_asked'][] = $one;
	return ! isset( $GLOBALS['ab_deny'][ $one ] ) && ! isset( $GLOBALS['ab_deny'][ $cap ] );
}
function wp_get_current_user() { return (object) array( 'ID' => 7, 'user_login' => 'shopmanager', 'display_name' => 'Sandra Shop', 'user_email' => 'sandra@example.test' ); }

/* ---- options: the undo index ---- */
$GLOBALS['ab_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { $had = array_key_exists( $k, $GLOBALS['ab_opts'] ); unset( $GLOBALS['ab_opts'][ $k ] ); return $had; }
function wp_cache_delete( $k, $group = '' ) { return true; }

/* ---- filters and actions ---- */
$GLOBALS['ab_filters'] = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( array_key_exists( $tag, $GLOBALS['ab_filters'] ) ) {
		$f = $GLOBALS['ab_filters'][ $tag ];
		return is_callable( $f ) && ! is_string( $f ) ? $f( $value, ...$args ) : $f;
	}
	return $value;
}
function has_filter( $tag, $cb = false ) { return array_key_exists( $tag, $GLOBALS['ab_filters'] ); }
$GLOBALS['ab_actions'] = array();
$GLOBALS['ab_did']     = array(); // [tag, first argument] per action fired
function add_action( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['ab_actions'][ $tag ][] = $cb; return true; }
function remove_action( $tag, $cb, $prio = 10 ) { return true; }
function has_action( $tag, $cb = false ) { return ! empty( $GLOBALS['ab_actions'][ $tag ] ); }
function do_action( $tag, ...$args ) {
	$GLOBALS['ab_did'][] = array( $tag, (string) ( $args[0] ?? '' ) );
	foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $f ) { $f( ...$args ); }
}
function ab_did( $tag, $id ) { return in_array( array( $tag, (string) $id ), $GLOBALS['ab_did'], true ); }
function is_multisite() { return false; }

/* ---- serialisation + fs + misc ---- */
function wp_salt( $s = 'auth' ) { return 'test-salt-value'; }
function size_format( $bytes, $decimals = 0 ) { return $bytes . ' B'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); }
function wp_json_encode( $v, $options = 0, $depth = 512 ) { return json_encode( $v, $options, $depth ); }
function ab_test_rm_tree( $dir ) {
	if ( 0 !== strpos( (string) $dir, sys_get_temp_dir() . '/ab-' ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-free-undo-shop-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_upload'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_serialized( $data ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( ! preg_match( '/^[aOsibd]:/', $data ) ) { return false; }
	return 'b:0;' === $data || false !== @unserialize( $data ); // phpcs:ignore
}
function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; } // phpcs:ignore
function maybe_unserialize( $v ) { return is_serialized( $v ) ? @unserialize( trim( $v ) ) : $v; } // phpcs:ignore

$GLOBALS['wpdb'] = new class {
	public $options     = 'wp_options';
	public $last_error  = '';
	public $last_result = array();
	public function prepare( $sql, ...$args ) { return $sql; }
	public function query( $sql ) { $this->last_result = array(); return false; }
	public function get_var( $sql ) { return null; }
};

/* ---- the posts table, as far as the types go ---- */
function ab_db_reset() {
	$GLOBALS['TYPES']        = array(); // id => post type
	$GLOBALS['P']            = array(); // product id => its data, as WooCommerce stores it
	$GLOBALS['C']            = array(); // coupon id => its data
	$GLOBALS['O']            = array(); // order id => AB_WC_Order
	$GLOBALS['ab_next']      = 100;
	$GLOBALS['ab_clock']     = 1800000000; // the time of each save (date_modified)
	$GLOBALS['ab_saves']     = array(); // product ids saved
	$GLOBALS['ab_save_fails'] = false;
	$GLOBALS['ab_on_save']   = null; // a plugin on woocommerce_before_product_object_save
	$GLOBALS['ab_after_save'] = null; // another request, right after a save (once)
	$GLOBALS['ab_on_load']   = null; // another request, before a product is loaded
	$GLOBALS['ab_now']       = null; // the clock of the sale period (null: now)
	$GLOBALS['ab_meta_veto'] = array(); // fields a filter on update_post_metadata keeps from being written
	$GLOBALS['ab_bookings']  = array(); // [id, operation, quantity] of wc_update_product_stock()
	$GLOBALS['ab_booking_fails'] = false;
	$GLOBALS['ab_veto']      = false; // a plugin on woocommerce_pre_delete_coupon
	$GLOBALS['ab_on_coupon'] = null; // a plugin on woocommerce_new_coupon
}
function get_post_type( $post = null ) { return $GLOBALS['TYPES'][ (int) $post ] ?? false; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['TYPES'][ $id ] ) ) { return null; }
	$post = array( 'ID' => $id, 'post_type' => $GLOBALS['TYPES'][ $id ] );
	if ( isset( $GLOBALS['P'][ $id ]['date'] ) ) { $post['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $GLOBALS['P'][ $id ]['date'] ); }
	return (object) $post;
}
/* All meta rows of a coupon: its reservations, as WooCommerce writes them (_coupon_held_<until>_<random>, _maybe_used_by_<until>_<random>). */
function get_post_meta( $id, $key = '', $single = false ) {
	$rows = array();
	foreach ( $GLOBALS['C'][ (int) $id ]['holds'] ?? array() as $k ) { $rows[ $k ] = array( '1' ); }
	return '' === $key ? $rows : ( $single ? ( $rows[ $key ][0] ?? '' ) : ( $rows[ $key ] ?? array() ) );
}
$GLOBALS['ab_cleaned'] = array();
function clean_post_cache( $id ) { $GLOBALS['ab_cleaned'][] = (int) $id; }
/* The post types as WooCommerce registers them: product_variation borrows the capabilities of product. */
function get_post_type_object( $type ) {
	$caps = array(
		'product'           => array( 'edit_post' => 'edit_product', 'delete_post' => 'delete_product', 'publish_posts' => 'publish_products' ),
		'product_variation' => array( 'edit_post' => 'edit_product', 'delete_post' => 'delete_product', 'publish_posts' => 'publish_products' ),
		'shop_coupon'       => array( 'edit_post' => 'edit_shop_coupon', 'delete_post' => 'delete_shop_coupon', 'publish_posts' => 'publish_shop_coupons' ),
		'shop_order'        => array( 'edit_post' => 'edit_shop_order', 'delete_post' => 'delete_shop_order', 'publish_posts' => 'publish_shop_orders' ),
	);
	return isset( $caps[ $type ] ) ? (object) array( 'cap' => (object) $caps[ $type ] ) : null;
}
function wp_trash_post( $id ) {
	if ( ! EMPTY_TRASH_DAYS ) {
		unset( $GLOBALS['TYPES'][ (int) $id ], $GLOBALS['C'][ (int) $id ] );
		do_action( 'deleted_post', $id );
		return true;
	}
	$GLOBALS['C'][ (int) $id ]['status'] = 'trash';
	do_action( 'trashed_post', $id );
	return true;
}

/* ---- WooCommerce, where it matters as WooCommerce 10 does it ---- */
class WooCommerce {
}
function wc_get_orders( $args = array() ) { return (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 ); }
function wc_get_order_statuses() {
	return array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' );
}
function wc_get_price_decimal_separator() { return '.'; }
/* As WooCommerce writes a price: a leading point gets its zero. */
function wc_format_decimal( $v ) { $v = trim( (string) $v ); return ( '' !== $v && '.' === $v[0] ) ? '0' . $v : $v; }
function wc_stock_amount( $v ) { return (int) $v; }
function wc_get_product_stock_status_options() { return array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder' ); }
function wc_get_coupon_types() { return array( 'percent' => 'Percentage discount', 'fixed_cart' => 'Fixed cart discount', 'fixed_product' => 'Fixed product discount' ); }
function wc_format_coupon_code( $code ) { return strtolower( trim( strip_tags( html_entity_decode( (string) $code ) ) ) ); }
/* As WooCommerce looks a code up: published coupons only. */
function wc_get_coupon_id_by_code( $code, $exclude = 0 ) {
	foreach ( $GLOBALS['C'] as $id => $c ) {
		if ( $c['code'] === wc_format_coupon_code( $code ) && 'publish' === $c['status'] ) { return $id; }
	}
	return 0;
}

/* WC_DateTime: a DateTime with WooCommerce's date(). */
class AB_WC_DateTime extends DateTime {
	public function __construct( $ts ) { parent::__construct( '@' . (int) $ts ); }
	public function date( $format ) { return gmdate( $format, $this->getTimestamp() ); }
}

/* A product: the stored data and the changes not yet saved. */
class AB_WC_Product {
	public $id;
	public $data;
	public $changes = array();
	public function __construct( $id ) {
		$this->id   = (int) $id;
		$this->data = $GLOBALS['P'][ $this->id ];
	}
	private function prop( $key, $context ) {
		$v = array_key_exists( $key, $this->changes ) ? $this->changes[ $key ] : $this->data[ $key ];
		return 'view' === $context ? apply_filters( 'woocommerce_product_get_' . $key, $v, $this ) : $v;
	}
	public function get_id() { return $this->id; }
	public function get_type() { return $this->data['type']; }
	public function get_parent_id( $context = 'view' ) { return $this->prop( 'parent', $context ); }
	public function get_name( $context = 'view' ) { return 'Widget'; }
	public function get_sku( $context = 'view' ) { return 'W-1'; }
	public function get_price( $context = 'view' ) { return $this->prop( 'price', $context ); }
	public function get_regular_price( $context = 'view' ) { return $this->prop( 'regular_price', $context ); }
	public function get_sale_price( $context = 'view' ) { return $this->prop( 'sale_price', $context ); }
	public function get_date_on_sale_from( $context = 'view' ) { $t = $this->prop( 'date_on_sale_from', $context ); return null === $t ? null : new AB_WC_DateTime( $t ); }
	public function get_date_on_sale_to( $context = 'view' ) { $t = $this->prop( 'date_on_sale_to', $context ); return null === $t ? null : new AB_WC_DateTime( $t ); }
	public function managing_stock() { return 'yes' === get_option( 'woocommerce_manage_stock' ) && $this->get_manage_stock( 'edit' ); }
	public function is_on_sale( $context = 'view' ) {
		$s = (string) $this->get_sale_price( $context );
		$f = $this->prop( 'date_on_sale_from', $context );
		$t = $this->prop( 'date_on_sale_to', $context );
		$now = $GLOBALS['ab_now'] ?? time();
		return '' !== $s && (float) $this->get_regular_price( $context ) > (float) $s && ( null === $f || $f <= $now ) && ( null === $t || $t >= $now );
	}
	public function get_manage_stock( $context = 'view' ) { return $this->prop( 'manage_stock', $context ); }
	public function get_stock_quantity( $context = 'view' ) { return $this->prop( 'stock_quantity', $context ); }
	public function get_stock_status( $context = 'view' ) { return $this->prop( 'stock_status', $context ); }
	public function get_backorders( $context = 'view' ) { return $this->prop( 'backorders', $context ); }
	public function get_status( $context = 'view' ) { return $this->prop( 'status', $context ); }
	/* WC_Data::set_prop(): a change only where the value differs from the object as loaded. */
	private function set_prop( $key, $v ) {
		if ( $v === $this->data[ $key ] ) { unset( $this->changes[ $key ] ); } else { $this->changes[ $key ] = $v; }
	}
	public function set_regular_price( $v ) { $this->set_prop( 'regular_price', wc_format_decimal( $v ) ); }
	public function set_sale_price( $v ) { $this->set_prop( 'sale_price', wc_format_decimal( $v ) ); }
	public function set_manage_stock( $v ) { $this->set_prop( 'manage_stock', true === $v || 'yes' === $v || 1 === $v || '1' === $v ); }
	public function set_stock_quantity( $q ) { $this->set_prop( 'stock_quantity', '' !== $q ? wc_stock_amount( $q ) : null ); }
	public function set_stock_status( $s = 'instock' ) { $this->set_prop( 'stock_status', isset( wc_get_product_stock_status_options()[ $s ] ) ? $s : 'instock' ); }
	public function set_status( $s ) { $this->set_prop( 'status', $s ); }
	public function set_date_on_sale_from( $d = null ) { $this->set_prop( 'date_on_sale_from', empty( $d ) ? null : (int) $d ); }
	public function set_date_on_sale_to( $d = null ) { $this->set_prop( 'date_on_sale_to', empty( $d ) ? null : (int) $d ); }
	public function save() {
		if ( $GLOBALS['ab_save_fails'] ) { return 0; }
		// WC_Product::validate_props().
		if ( ! $this->get_manage_stock( 'edit' ) ) {
			$this->changes['stock_quantity'] = null;
			$this->changes['backorders']     = 'no';
		} else {
			$q = (float) $this->get_stock_quantity( 'edit' );
			$this->changes['stock_status'] = $q > 0 ? 'instock' : ( 'no' !== $this->get_backorders( 'edit' ) ? 'onbackorder' : 'outofstock' );
		}
		if ( is_callable( $GLOBALS['ab_on_save'] ) ) { ( $GLOBALS['ab_on_save'] )( $this ); }
		// The data store: only what changed is written, the rest of the row
		// as the database has it (a sale meanwhile included).
		$changed       = array_keys( $this->changes );
		$this->data    = array_merge( $this->data, $this->changes );
		$row           = array_merge( $GLOBALS['P'][ $this->id ], $this->changes );
		$this->changes = array();
		// WordPress stores a status by the post's date (wp_insert_post()), the
		// object keeps what was set; and a filter on update_post_metadata
		// can refuse a field, which the object does not see either.
		if ( in_array( 'status', $changed, true ) && 'future' === $row['status'] && $row['date'] <= time() ) {
			$row['status'] = 'publish';
		} elseif ( in_array( 'status', $changed, true ) && 'publish' === $row['status'] && $row['date'] > time() ) {
			$row['status'] = 'future';
		}
		foreach ( $GLOBALS['ab_meta_veto'] as $field ) {
			$row[ $field ] = $GLOBALS['P'][ $this->id ][ $field ];
		}
		// handle_updated_props(): a sale price not below the regular one goes,
		// and the price is derived again where a price field changed.
		if ( array_intersect( array( 'regular_price', 'sale_price' ), $changed ) && '' !== (string) $this->data['sale_price'] && (float) $this->data['sale_price'] >= (float) $this->data['regular_price'] ) {
			$this->data['sale_price'] = '';
			$row['sale_price']        = '';
		}
		if ( array_intersect( array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ), $changed ) ) {
			$this->data['price'] = $this->is_on_sale( 'edit' ) ? $this->data['sale_price'] : $this->data['regular_price'];
			$row['price']        = $this->data['price'];
		}
		$GLOBALS['P'][ $this->id ] = $row;
		$GLOBALS['ab_saves'][]     = $this->id;
		do_action( 'woocommerce_update_product', $this->id );
		if ( is_callable( $GLOBALS['ab_after_save'] ) ) {
			$f                        = $GLOBALS['ab_after_save'];
			$GLOBALS['ab_after_save'] = null;
			$f( $this->id );
		}
		return $this->id;
	}
}
function wc_get_product( $id ) {
	if ( is_callable( $GLOBALS['ab_on_load'] ) ) { ( $GLOBALS['ab_on_load'] )( (int) $id ); }
	return isset( $GLOBALS['P'][ (int) $id ] ) ? new AB_WC_Product( (int) $id ) : false;
}
/* As WooCommerce's: relative to the database at that moment (one UPDATE), only where stock is managed, then the product saved. */
function wc_update_product_stock( $product, $qty = null, $op = 'set', $updating = false ) {
	if ( ! is_object( $product ) ) { $product = wc_get_product( $product ); }
	if ( ! $product ) { return false; }
	if ( null === $qty || ! $product->managing_stock() ) { return $product->get_stock_quantity(); }
	if ( $GLOBALS['ab_booking_fails'] ) { return false; }
	$id  = $product->get_id();
	$cur = (int) $GLOBALS['P'][ $id ]['stock_quantity'];
	$new = 'set' === $op ? (int) $qty : ( 'increase' === $op ? $cur + (int) $qty : $cur - (int) $qty );
	$GLOBALS['P'][ $id ]['stock_quantity'] = $new;
	$GLOBALS['ab_bookings'][]             = array( $id, $op, $qty );
	$product->data['stock_quantity']      = $new; // read_stock_quantity(); save() derives the stock status again
	$product->save();
	return $new;
}
function ab_product( $id, $over = array(), $type = 'product' ) {
	$GLOBALS['TYPES'][ $id ] = $type;
	$GLOBALS['P'][ $id ]     = array_merge(
		array(
			'type'           => 'product_variation' === $type ? 'variation' : 'simple',
			'parent'         => 0,
			'regular_price'  => '10',
			'sale_price'     => '',
			'price'          => '10',
			'date_on_sale_from' => null,
			'date_on_sale_to'   => null,
			'date'           => time() - 86400, // post_date: yesterday
			'manage_stock'   => false,
			'stock_quantity' => null,
			'stock_status'   => 'instock',
			'backorders'     => 'no',
			'status'         => 'publish',
		),
		$over
	);
	return $id;
}
function ab_p( $id, $key ) { return $GLOBALS['P'][ $id ][ $key ] ?? null; }
/* An order placed: WooCommerce takes the stock down by a direct update (wc_update_product_stock()). */
function ab_sell( $id, $n = 1 ) {
	$GLOBALS['P'][ $id ]['stock_quantity'] -= $n;
	if ( $GLOBALS['P'][ $id ]['stock_quantity'] <= 0 ) { $GLOBALS['P'][ $id ]['stock_status'] = 'outofstock'; }
}

/* An order: update_status() as WooCommerce's, notes as entries of their own. */
class AB_WC_Order {
	public $id;
	public $status;
	public $notes = array();
	public function __construct( $id, $status ) { $this->id = $id; $this->status = $status; }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_status( $context = 'view' ) { return $this->status; }
	public function update_status( $status, $note = '', $manual = false ) {
		$this->status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		return true;
	}
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) { $this->notes[] = $note; return 1; }
}
function wc_get_order( $id ) { return $GLOBALS['O'][ (int) $id ] ?? false; }

/* A coupon: read by its id, saved, used, held, trashed. */
class WC_Coupon {
	public $id   = 0;
	public $data = array( 'code' => '', 'status' => 'publish', 'amount' => '0', 'discount_type' => 'fixed_cart', 'date_created' => null, 'date_expires' => null, 'date_modified' => null, 'usage_count' => 0, 'used_by' => array(), 'holds' => array(), 'individual_use' => false, 'minimum_amount' => '', 'product_ids' => array(), 'meta' => array() );
	public function __construct( $data = '' ) {
		if ( is_int( $data ) && 'shop_coupon' === get_post_type( $data ) ) {
			if ( ! isset( $GLOBALS['C'][ $data ] ) ) { throw new Exception( 'Invalid coupon.' ); }
			$this->id   = $data;
			$this->data = $GLOBALS['C'][ $data ];
		}
	}
	public function get_id() { return $this->id; }
	public function get_code( $context = 'view' ) { return 'view' === $context ? apply_filters( 'woocommerce_coupon_get_code', $this->data['code'], $this ) : $this->data['code']; }
	public function get_status( $context = 'view' ) { return $this->data['status']; }
	public function get_amount( $context = 'view' ) { return wc_format_decimal( $this->data['amount'] ); }
	public function get_discount_type( $context = 'view' ) { return $this->data['discount_type']; }
	public function get_date_expires( $context = 'view' ) { return null === $this->data['date_expires'] ? null : new AB_WC_DateTime( $this->data['date_expires'] ); }
	public function get_date_modified( $context = 'view' ) { return null === $this->data['date_modified'] ? null : new AB_WC_DateTime( $this->data['date_modified'] ); }
	public function get_usage_count( $context = 'view' ) { return $this->data['usage_count']; }
	public function get_used_by( $context = 'view' ) { return $this->data['used_by']; }
	/* WC_Coupon::get_data(): the id, every prop (dates as WC_DateTime), the meta rows as WC_Meta_Data. */
	public function get_data() {
		$out = array( 'id' => $this->id );
		foreach ( $this->data as $k => $v ) {
			if ( in_array( $k, array( 'holds', 'meta' ), true ) ) { continue; }
			$out[ $k ] = in_array( $k, array( 'date_created', 'date_expires', 'date_modified' ), true ) && null !== $v ? new AB_WC_DateTime( $v ) : $v;
		}
		$out['meta_data'] = array();
		$mid              = 500;
		foreach ( $this->data['meta'] as $key => $value ) {
			$out['meta_data'][] = new class( $mid++, $key, $value ) {
				private $d;
				public function __construct( $id, $key, $value ) { $this->d = array( 'id' => $id, 'key' => $key, 'value' => $value ); }
				public function get_data() { return $this->d; }
			};
		}
		return $out;
	}
	public function set_code( $v ) { $this->data['code'] = wc_format_coupon_code( $v ); }
	public function set_discount_type( $v ) {
		if ( ! isset( wc_get_coupon_types()[ $v ] ) ) { throw new Exception( 'Invalid discount type' ); }
		$this->data['discount_type'] = $v;
	}
	public function set_amount( $v ) {
		$v = wc_format_decimal( $v );
		if ( 'percent' === $this->data['discount_type'] && (float) $v > 100 ) { throw new Exception( 'Coupon discount amount must be less than 100 percent.' ); }
		$this->data['amount'] = $v;
	}
	public function set_date_expires( $v ) { $this->data['date_expires'] = strtotime( $v . ' 00:00:00 UTC' ); }
	public function set_individual_use( $v ) { $this->data['individual_use'] = (bool) $v; }
	public function save() {
		if ( $GLOBALS['ab_save_fails'] ) { return 0; }
		if ( ! $this->id ) {
			$this->id                       = $GLOBALS['ab_next']++;
			$GLOBALS['TYPES'][ $this->id ] = 'shop_coupon';
			$this->data['date_created']    = $GLOBALS['ab_clock'];
		}
		$this->data['date_modified'] = $GLOBALS['ab_clock']++;
		$GLOBALS['C'][ $this->id ]   = $this->data;
		if ( is_callable( $GLOBALS['ab_on_coupon'] ) ) { ( $GLOBALS['ab_on_coupon'] )( $this->id ); }
		return $this->id;
	}
	/* WC_Data::delete() and the coupon data store: into the trash, or for good with force. */
	public function delete( $force_delete = false ) {
		if ( $GLOBALS['ab_veto'] ) { return false; }
		$id = $this->id;
		if ( $force_delete ) {
			unset( $GLOBALS['TYPES'][ $id ], $GLOBALS['C'][ $id ] );
		} else {
			wp_trash_post( $id );
			do_action( 'woocommerce_trash_coupon', $id );
		}
		$this->id = 0;
		return true;
	}
	/* WC_Data_Store: has_callable(), and the call handed on with the object first. */
	public function get_data_store() {
		return new class {
			public function has_callable( $m ) { return 'get_tentative_usage_count' === $m; }
			public function get_tentative_usage_count( $id ) { return (string) count( $GLOBALS['C'][ (int) $id ]['holds'] ?? array() ); }
		};
	}
}
function ab_coupon( $id ) { return $GLOBALS['C'][ $id ] ?? null; }

/* ---- the rest of the site the tools and the restore ask ---- */
$GLOBALS['ab_scope']     = 'full';
$GLOBALS['ab_toolstate'] = array();
class AB_MCP_Settings {
	public static function is_tool_enabled( $name, $def ) {
		return array_key_exists( $name, $GLOBALS['ab_toolstate'] ) ? (bool) $GLOBALS['ab_toolstate'][ $name ] : true;
	}
}
class AB_MCP_Auth {
	public static function current_scope() { return $GLOBALS['ab_scope']; }
}

require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-page-cache.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-shop.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

$pass = 0;
$fail = 0;
function ok( $c, $label ) {
	global $pass, $fail;
	if ( $c ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
function ab_msg( $r ) { return is_wp_error( $r ) ? (string) $r->get_error_message() : ''; }
function ab_undo( $token, $extra = array() ) { return AB_MCP_Tools_Undo_Core::undo( array_merge( array( 'token' => (string) $token ), $extra ) ); }
function ab_entry( $token ) {
	foreach ( (array) get_option( AB_MCP_Undo::OPTION, array() ) as $e ) {
		if ( $e['token'] === $token ) { return $e; }
	}
	return null;
}
function ab_points() { return count( (array) get_option( AB_MCP_Undo::OPTION, array() ) ); }
function ab_token( $res ) { return is_array( $res ) && is_string( $res['undo'] ?? null ) && AB_MCP_Undo::valid_token( $res['undo'] ); }
function ab_forge( $tool, $kind, $data ) {
	return AB_MCP_Undo::record( array( 'tool' => $tool, 'cap' => 'manage_woocommerce', 'kind' => $kind, 'summary' => 'forged', 'items' => 1, 'data' => $data ) );
}
function ab_update( $args ) { return AB_MCP_Tools_Shop::update_product( array_merge( array( 'id' => 7 ), $args ) ); }
function ab_create( $args = array() ) { return AB_MCP_Tools_Shop::create_coupon( array_merge( array( 'code' => 'Fruehling', 'amount' => '10', 'discount_type' => 'percent', 'expires' => '2026-12-31' ), $args ) ); }
function ab_status( $status, $note = '' ) { return AB_MCP_Tools_Shop::update_order_status( array( 'id' => 5, 'status' => $status, 'note' => $note ) ); }
function reset_all() {
	ab_db_reset();
	$GLOBALS['ab_opts']      = array();
	$GLOBALS['ab_filters']   = array();
	$GLOBALS['ab_deny']      = array();
	$GLOBALS['ab_asked']     = array();
	$GLOBALS['ab_toolstate'] = array();
	$GLOBALS['ab_did']       = array();
	$GLOBALS['ab_cleaned']   = array();
	$GLOBALS['ab_opts']['woocommerce_manage_stock'] = 'yes';
	ab_product( 7 );
	$GLOBALS['O'][5] = new AB_WC_Order( 5, 'processing' );
}

/* ============================== T) the trash turned off (in a process of its own) */

if ( $GLOBALS['ab_trash_off'] ) {
	reset_all();
	$res = ab_create();
	ok( true === $res['created'] && null === $res['undo'] && 0 === ab_points() && 'publish' === ab_coupon( $res['id'] )['status'], 'T1 a coupon created with the trash off: created, no undo point' );
	ok( AB_MCP_Undo_Coupons::trash_off_note() === $res['undo_note'] && 0 === strpos( $res['undo_note'], 'not undoable: The trash is turned off on this site (EMPTY_TRASH_DAYS is 0)' ) && false !== strpos( $res['undo_note'], 'wp_wc_list_coupons' ), 'T2 ... the note says why, and where the coupon is taken out of use' );
	ok( array( 'code' => 'fruehling', 'amount' => '10', 'discount_type' => 'percent', 'expires' => '2026-12-31' ) === (array) $res['saved_fields'], 'T3 ... and saved_fields as with the trash on' );

	// A point recorded while the trash was on, undone after it was turned off.
	$t   = ab_forge( 'wp_wc_create_coupon', 'wc_coupon', array( 'v' => 1, 'id' => $res['id'], 'after' => AB_MCP_Undo_Coupons::now_hashes( array( 'id' => $res['id'] ) ) ) );
	$out = ab_undo( $t, array( 'force' => true ) );
	ok( 0 === $out['undone'] && 'no_trash' === $out['blocked'][0]['reason'] && 'publish' === ab_coupon( $res['id'] )['status'] && null !== ab_entry( $t ), 'T4 a coupon point undone with the trash off: blocked (no_trash), also with force; the coupon stays, not deleted for good' );

	reset_all();
	$GLOBALS['ab_toolstate']['wp_undo'] = false;
	$res = ab_create();
	ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off' ), 'T5 with undo switched off, the note says that' );

	reset_all();
	$res = ab_update( array( 'regular_price' => '12' ) );
	ok( ab_token( $res ) && 1 === ab_undo( $res['undo'] )['undone'] && '10' === ab_p( 7, 'regular_price' ), 'T6 the undo of a product does not depend on the trash' );

	echo "UNDO-SHOP-TRASH-OFF: $pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}

/* ============================== A) a product: one point, put back through WooCommerce */

function ab_price( $over = array() ) { return array_merge( array( 'regular_price' => '10', 'sale_price' => '', 'date_on_sale_from' => null, 'date_on_sale_to' => null ), $over ); }

reset_all();
$res = ab_update( array( 'regular_price' => '12.50' ) );
ok( true === $res['updated'] && ab_token( $res ) && ! isset( $res['undo_note'] ), 'A1 wp_wc_update_product answers with a token' );
ok( array( 'regular_price' => '12.50' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ) && '12.50' === $res['product']['regular_price'] && '12.50' === ab_p( 7, 'price' ), 'A2 saved_fields: the field sent, as stored; WooCommerce derived the price' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_wc_update_product' === $c['entry']['tool'] && 'wc_product' === $c['entry']['kind'] && 'manage_woocommerce' === $c['entry']['cap'] && 1 === (int) $c['entry']['items'], 'A3 kind wc_product, with the capability of the tool' );
ok( array( 'v', 'id', 'type', 'parent', 'before', 'after' ) === array_keys( $c['data'] ) && 7 === $c['data']['id'] && 'simple' === $c['data']['type'] && 0 === $c['data']['parent'], 'A4 the snapshot names the product, its type and its parent' );
ok( array( 'price' => ab_price() ) === $c['data']['before'] && array( 'price' ) === array_keys( $c['data']['after'] ), 'A5 ... and keeps the prices as one location: regular and sale price and the sale period, as WooCommerce stores them' );
ok( 'product #7: price' === $c['entry']['summary'] && null !== $c['entry']['inline'], 'A6 the summary names the product and the location, no value; a small point lies inline' );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $dry['would_undo'] && array() === $dry['blocked'] && '12.50' === ab_p( 7, 'regular_price' ), 'A7 the dry run would restore it and changes nothing' );
$GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] = static fn( $all, $scope ) => array( 'host-' . $scope => static function () {} );
$saves = count( $GLOBALS['ab_saves'] );
$out   = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && true === $out['consumed'] && array( 'product:7' ) === $out['restored'] && '10' === ab_p( 7, 'regular_price' ) && '10' === ab_p( 7, 'price' ), 'A8 wp_undo sets the old price again, WooCommerce derives it, the point is used up' );
ok( $saves + 1 === count( $GLOBALS['ab_saves'] ) && in_array( 7, $GLOBALS['ab_cleaned'], true ) && array() === $GLOBALS['ab_bookings'], 'A9 ... through save(), read again past the cache; no stock booked' );
ok( array( 'host-site' ) === $out['page_cache_purged'] && 'site' === AB_MCP_Undo_Restore::page_cache_scope()['wc_product'], 'A10 every page of the site is purged: a price shows on the shop page, in archives and widgets' );
unset( $GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] );

// All at once: prices and status in the point, the stock not.
reset_all();
ab_product( 7, array( 'stock_status' => 'outofstock', 'status' => 'draft' ) );
$res = ab_update( array( 'regular_price' => '20', 'sale_price' => '15', 'stock' => 5, 'status' => 'publish' ) );
ok( ab_token( $res ) && array( 'regular_price' => '20', 'sale_price' => '15', 'stock' => 5, 'status' => 'publish' ) === (array) $res['saved_fields'] && true === ab_p( 7, 'manage_stock' ) && '15' === ab_p( 7, 'price' ), 'A11 four fields; WooCommerce manages the stock now and derives the price' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( array( 'price', 'status' ) === array_keys( $c['data']['before'] ) && ab_price() === $c['data']['before']['price'] && 'draft' === $c['data']['before']['status'] && 'product #7: price, status' === $c['entry']['summary'], 'A12 the point keeps the prices and the status of before, no stock' );
ok( AB_MCP_Undo_Products::stock_note( array( 'manage' => false, 'quantity' => null ) ) === $res['stock_note'] && false !== strpos( $res['stock_note'], 'did not manage the stock' ) && false !== strpos( $res['stock_note'], 'wp_wc_get_product' ), 'A13 stock_note: the stock is no part of the undo, it was not managed before, and the way' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '10' === ab_p( 7, 'regular_price' ) && '' === ab_p( 7, 'sale_price' ) && '10' === ab_p( 7, 'price' ) && 'draft' === ab_p( 7, 'status' ), 'A14 the undo puts prices and status back' );
ok( true === ab_p( 7, 'manage_stock' ) && 5 === ab_p( 7, 'stock_quantity' ) && array() === $GLOBALS['ab_bookings'], 'A15 ... and leaves the stock as it is: managed, 5, nothing booked' );

// The same quantity again with a price: the note all the same.
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 4 ) );
$res = ab_update( array( 'stock' => 4, 'regular_price' => '11' ) );
ok( array( 'price' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ) && false !== strpos( $res['stock_note'], 'it was 4' ), 'A20 a quantity the stock had already: the point holds the price, the note the stock of before' );

// What the tool asked for, in the form WooCommerce stores it.
reset_all();
$res = ab_update( array( 'sale_price' => '.5' ) );
ok( '0.5' === ab_p( 7, 'sale_price' ) && array( 'sale_price' => '0.5' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'A21 a price WooCommerce writes in its own form (.5 as 0.5) is no difference' );

// The edit context: a filter of the view context changes nothing kept.
reset_all();
$GLOBALS['ab_filters']['woocommerce_product_get_regular_price'] = static fn( $v ) => 'shown ' . $v;
$res = ab_update( array( 'regular_price' => '11' ) );
ok( array( 'price' => ab_price() ) === AB_MCP_Undo::claim( $res['undo'] )['data']['before'] && array( 'regular_price' => '11' ) === (array) $res['saved_fields'], 'A22 the point and the read-back take the stored value, not the one a display filter shows' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && '10' === ab_p( 7, 'regular_price' ), 'A23 ... and its undo works on it' );
unset( $GLOBALS['ab_filters']['woocommerce_product_get_regular_price'] );

// A product on sale for a period: all of it comes back and is compared.
reset_all();
ab_product( 7, array( 'sale_price' => '8', 'price' => '8', 'date_on_sale_from' => time() - 3600, 'date_on_sale_to' => time() + 86400 ) );
$was = $GLOBALS['P'][7];
$res = ab_update( array( 'regular_price' => '12' ) );
ok( '8' === ab_p( 7, 'price' ) && 1 === ab_undo( $res['undo'] )['undone'] && array_intersect_key( $was, ab_price() ) === array_intersect_key( $GLOBALS['P'][7], ab_price() ), 'A24 a product on sale for a period: prices, period and derived price as before' );

/* ============================== B) nothing changed, and nothing stored */

reset_all();
$res = ab_update( array( 'regular_price' => '10' ) );
ok( true === $res['updated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && false !== strpos( $res['undo_note'], 'wp_wc_get_product' ) && 0 === ab_points(), 'B1 the price the product had already: no point, the note says why' );
ok( array( 'regular_price' => '10' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'B2 ... and saved_fields shows it' );

// Codex, final round: a field sent as it was hides no refusal. Regular price
// 10, no sale price; the call sends 10 and a sale price of 20, which
// WooCommerce drops as not below the regular one: nothing changed.
reset_all();
$res = ab_update( array( 'regular_price' => '10', 'sale_price' => '20' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'sale_price' ) && false === strpos( ab_msg( $res ), 'regular_price' ) && '' === ab_p( 7, 'sale_price' ) && 0 === ab_points(), 'B2b a sale price WooCommerce dropped beside a regular price sent as it was: not updated: true, the error names the sale price' );
reset_all();
$res = ab_update( array( 'regular_price' => '10', 'sale_price' => '', 'status' => 'publish' ) );
ok( true === $res['updated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && ! isset( $res['saved_differs'] ) && 0 === ab_points(), 'B2c everything sent as it was already: a success without a point, nothing changed' );

reset_all();
$GLOBALS['ab_on_save'] = static function ( $p ) { $p->changes['regular_price'] = $p->data['regular_price']; }; // a plugin that keeps the price
$res = ab_update( array( 'regular_price' => '99' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'regular_price' ) && false !== strpos( ab_msg( $res ), 'wp_wc_get_product' ) && 0 === ab_points(), 'B3 a change WooCommerce did not store: an error naming the field and the way, not updated: true' );

reset_all();
$GLOBALS['ab_on_save'] = static function ( $p ) { $p->changes['regular_price'] = '13'; }; // a plugin that rewrites it
$res = ab_update( array( 'regular_price' => '12' ) );
ok( array( 'regular_price' ) === $res['saved_differs'] && '13' === $res['saved_fields']->regular_price && false !== strpos( $res['warning'], 'wp_wc_get_product' ) && ab_token( $res ), 'B4 a plugin that rewrites the price: saved_differs and a warning naming the reader, still a token' );
$GLOBALS['ab_on_save'] = null;
ok( 1 === ab_undo( $res['undo'] )['undone'] && '10' === ab_p( 7, 'regular_price' ), 'B5 ... whose undo puts the price of before back' );

// A plugin that rewrites the price on the undo's own save.
reset_all();
$res                   = ab_update( array( 'regular_price' => '12', 'status' => 'draft' ) );
$GLOBALS['ab_on_save'] = static function ( $p ) { $p->changes['regular_price'] = '11'; };
$out                   = ab_undo( $res['undo'] );
$GLOBALS['ab_on_save'] = null;
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( (string) $out['failed'][0]['reason'], 'back price of' ) && false === strpos( (string) $out['failed'][0]['reason'], 'status' ) && false !== strpos( (string) $out['failed'][0]['reason'], 'wp_wc_get_product' ) && null !== ab_entry( $res['undo'] ), 'B6 the undo read again: a location WooCommerce did not take back is named, with the way; the point stays' );
reset_all();
$res                      = ab_update( array( 'regular_price' => '12', 'status' => 'draft' ) );
$GLOBALS['ab_save_fails'] = true;
$out                      = ab_undo( $res['undo'] );
$GLOBALS['ab_save_fails'] = false;
ok( 0 === $out['undone'] && false !== strpos( (string) $out['failed'][0]['reason'], 'price, status' ) && null !== ab_entry( $res['undo'] ), 'B7 an undo whose save failed: every location named, the point stays' );

reset_all();
$GLOBALS['ab_save_fails'] = true;
$res                      = ab_update( array( 'regular_price' => '12' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'wp_wc_get_product' ) && 0 === ab_points(), 'B8 a save that failed: the error of before, no point' );
$GLOBALS['ab_save_fails'] = false;

reset_all();
ab_product( 7, array( 'status' => 'trash' ) );
$res = ab_update( array( 'status' => 'draft' ) );
ok( true === $res['updated'] && 'draft' === ab_p( 7, 'status' ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'No undo point was recorded' ) && 0 === ab_points(), 'B9 a status of before the tool does not set (trash): the write stands, no point that would put back only part' );
reset_all();
ab_product( 7, array( 'status' => 'trash' ) );
$res = ab_update( array( 'regular_price' => '8' ) );
ok( ab_token( $res ) && array( 'price' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ), 'B10 ... the price of a product in the trash has its point: its status is not part of it' );

reset_all();
ok( 'ab_mcp_bad_price' === ab_code( ab_update( array( 'regular_price' => '1e3', 'stock' => 4 ) ) ) && 0 === ab_points() && array() === $GLOBALS['ab_saves'] && null === ab_p( 7, 'stock_quantity' ), 'B11 a refused price: nothing written, no point' );
ok( 'ab_mcp_not_found' === ab_code( AB_MCP_Tools_Shop::update_product( array( 'id' => 999, 'stock' => 1 ) ) ), 'B12 an unknown product' );

/* ============================== C) prices and status changed since: refused unless force */

reset_all();
$res = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['P'][7]['regular_price'] = '14'; // a shop manager in wp-admin
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'price' ) === $out['blocked'][0]['locs'] && null !== ab_entry( $res['undo'] ) && '14' === ab_p( 7, 'regular_price' ), 'C1 a price changed since is not put back, the point stays' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '10' === ab_p( 7, 'regular_price' ), 'C2 with force it is' );

// Codex 31.10.: a sale price set since. Putting the lower regular price back
// would make WooCommerce drop it (a sale price not below the regular one).
reset_all();
$res = ab_update( array( 'regular_price' => '20' ) );
AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'sale_price' => '15' ) ); // a sale since
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'price' ) === $out['blocked'][0]['locs'] && '15' === ab_p( 7, 'sale_price' ) && '20' === ab_p( 7, 'regular_price' ), 'C3 a sale price set since: the price is not put back without force, the sale stays' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '10' === ab_p( 7, 'regular_price' ) && '' === ab_p( 7, 'sale_price' ) && '10' === ab_p( 7, 'price' ), 'C4 with force the prices of before come back, all of them' );
reset_all();
$res                                  = ab_update( array( 'sale_price' => '7' ) );
$GLOBALS['P'][7]['date_on_sale_to'] = time() + 3600; // a sale period set since
ok( array( 'price' ) === ab_undo( $res['undo'] )['blocked'][0]['locs'] && '7' === ab_p( 7, 'sale_price' ), 'C5 a sale period set since is a change of the prices too' );
ok( 1 === ab_undo( $res['undo'], array( 'force' => true ) )['undone'] && null === ab_p( 7, 'date_on_sale_to' ) && '' === ab_p( 7, 'sale_price' ), 'C5b ... with force the sale period of before comes back as well' );
// Codex 31.10. (2): the end of a sale between the change and the undo.
// WooCommerce sets the price it shows to the regular one then
// (wc_apply_sale_state_for_product()) and leaves the fields it stores.
reset_all();
$t0 = time();
ab_product( 7, array( 'regular_price' => '100', 'sale_price' => '80', 'price' => '80', 'date_on_sale_to' => $t0 + 3600 ) );
$res                      = ab_update( array( 'regular_price' => '120' ) );
$GLOBALS['ab_now']        = $t0 + 7200; // the sale is over
$GLOBALS['P'][7]['price'] = '120';      // and WooCommerce derived the price anew
$out                      = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && array() === $out['failed'] && '100' === ab_p( 7, 'regular_price' ) && '80' === ab_p( 7, 'sale_price' ) && $t0 + 3600 === ab_p( 7, 'date_on_sale_to' ), 'C6 a sale that ended since: no change of the prices, the undo succeeds without force' );
ok( '100' === ab_p( 7, 'price' ), 'C6b ... and WooCommerce derives the price of now: 100, not the 80 of before the end' );

reset_all();
$res                        = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['P'][7]['status'] = 'draft'; // another location, changed since
ab_sell( 7 ); // and stock (not managed, not in the point)
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '10' === ab_p( 7, 'regular_price' ) && 'draft' === ab_p( 7, 'status' ), 'C7 a location the call did not change may change since: no conflict, and it stays' );

reset_all();
$res  = ab_update( array( 'regular_price' => '12' ) );
$plan = AB_MCP_Undo_Products::plan( 'wp_wc_update_product', AB_MCP_Undo::claim( $res['undo'] )['data'], false );
$GLOBALS['P'][7]['regular_price'] = '15'; // between the plan and the write
$again = AB_MCP_Undo_Products::restore( $plan['items'][0] );
ok( 'ab_mcp_undo_product_changed' === ab_code( $again ) && false !== strpos( ab_msg( $again ), 'force=true' ) && '15' === ab_p( 7, 'regular_price' ), 'C8 changed between the plan and the write: refused, with the way' );

// Codex 31.10. (3): the state after is the stored one. A draft dated in the
// past set to future: WordPress publishes it, the object keeps future.
reset_all();
ab_product( 7, array( 'status' => 'draft' ) );
$res = ab_update( array( 'status' => 'future' ) );
ok( 'publish' === ab_p( 7, 'status' ) && 'publish' === $res['saved_fields']->status && array( 'status' ) === $res['saved_differs'] && false !== strpos( $res['warning'], 'wp_wc_get_product' ) && ab_token( $res ), 'C8c future on a date passed: stored as publish, saved_fields and saved_differs say so, a token' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && 'draft' === ab_p( 7, 'status' ), 'C8d ... and the undo at once takes it back to draft, no changed_since' );
reset_all();
ab_product( 7, array( 'status' => 'draft', 'date' => time() + 86400 ) );
$res = ab_update( array( 'status' => 'publish' ) );
ok( 'future' === ab_p( 7, 'status' ) && 1 === ab_undo( $res['undo'] )['undone'] && 'draft' === ab_p( 7, 'status' ), 'C8e publish on a date ahead: stored as future, the undo takes it back too' );

// Codex 31.10. (3): a filter on update_post_metadata refuses the price.
reset_all();
$GLOBALS['ab_meta_veto'] = array( 'regular_price' );
$res                     = ab_update( array( 'regular_price' => '12' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'regular_price' ) && '10' === ab_p( 7, 'regular_price' ) && 0 === ab_points(), 'C8f a price a filter refused: not updated: true, the error names it, no point' );
reset_all();
$GLOBALS['ab_meta_veto'] = array( 'regular_price' );
$res                     = ab_update( array( 'regular_price' => '12', 'status' => 'draft' ) );
ok( ab_token( $res ) && array( 'status' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ) && array( 'regular_price' ) === $res['saved_differs'] && '10' === $res['saved_fields']->regular_price, 'C8g a refused price beside a stored status: the point holds the status only, saved_differs names the price' );
$GLOBALS['ab_meta_veto'] = array();
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'publish' === ab_p( 7, 'status' ) && '10' === ab_p( 7, 'regular_price' ), 'C8h ... and its undo puts the status back' );

// Codex 31.10. (4): field by field. 10/5, the call 20/8, a filter refuses
// only the sale price: stored 20/5, the point holds that, and the undo at
// once takes it back.
reset_all();
ab_product( 7, array( 'sale_price' => '5', 'price' => '5' ) );
$GLOBALS['ab_meta_veto'] = array( 'sale_price' );
$res                     = ab_update( array( 'regular_price' => '20', 'sale_price' => '8' ) );
$GLOBALS['ab_meta_veto'] = array();
ok( ab_token( $res ) && '20' === ab_p( 7, 'regular_price' ) && '5' === ab_p( 7, 'sale_price' ) && array( 'sale_price' ) === $res['saved_differs'] && '5' === $res['saved_fields']->sale_price, 'C8i a sale price a filter refused beside a stored regular price: saved_differs names it' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && '10' === ab_p( 7, 'regular_price' ) && '5' === ab_p( 7, 'sale_price' ), 'C8j ... the undo at once puts 10/5 back, no changed_since' );

// Codex 31.10. (4): a stock stored is a change stored, though no part of
// the point. Stock 3 to 10 stored, the price refused: no error.
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$GLOBALS['ab_meta_veto'] = array( 'regular_price' );
$res                     = ab_update( array( 'regular_price' => '20', 'stock' => 10 ) );
$GLOBALS['ab_meta_veto'] = array();
ok( is_array( $res ) && true === $res['updated'] && 10 === ab_p( 7, 'stock_quantity' ) && 10 === $res['saved_fields']->stock && '10' === $res['saved_fields']->regular_price && array( 'regular_price' ) === $res['saved_differs'], 'C8k the stock stored, the price refused: updated, saved_fields and saved_differs say which' );
ok( null === $res['undo'] && 0 === ab_points() && AB_MCP_Undo_Products::stock_note( array( 'manage' => true, 'quantity' => '3' ) ) === $res['undo_note'], 'C8l ... no point, undo_note the note of the stock with its value before' );

// Codex 31.10. (4): future and publish are WordPress's only where the date
// of the post says so. A product dated tomorrow set to future and published
// right after the save by another request: not the call's.
reset_all();
ab_product( 7, array( 'status' => 'draft', 'date' => time() + 86400 ) );
$GLOBALS['ab_after_save'] = static function ( $id ) { $GLOBALS['P'][ $id ]['status'] = 'publish'; };
$res                      = ab_update( array( 'status' => 'future' ) );
$out                      = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && 'publish' === ab_p( 7, 'status' ), 'C8m publish with a date ahead is not WordPress\'s making of future: another request\'s, refused without force' );
reset_all();
ab_product( 7, array( 'status' => 'draft' ) ); // dated yesterday
$GLOBALS['ab_after_save'] = static function ( $id ) { $GLOBALS['P'][ $id ]['status'] = 'future'; };
$res                      = ab_update( array( 'status' => 'publish' ) );
ok( 'changed_since' === ab_undo( $res['undo'] )['blocked'][0]['reason'] && 'future' === ab_p( 7, 'status' ), 'C8n and future with a date passed is not WordPress\'s making of publish' );

// A field the call did not change, changed by another request right after
// the save: no part of the point, so the undo of the status goes through
// and that price stays.
reset_all();
$GLOBALS['ab_after_save'] = static function ( $id ) { $GLOBALS['P'][ $id ]['regular_price'] = '13'; };
$res                      = ab_update( array( 'status' => 'draft' ) );
ok( array( 'status' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ), 'C8o a price another request set right after a status change: the point holds the status only' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'publish' === ab_p( 7, 'status' ) && '13' === ab_p( 7, 'regular_price' ), 'C8p ... its undo puts the status back and leaves that price' );

// A price another request set right after the save is not the tool's: the
// point holds the price the tool saved, so the newer one is not undone
// without force.
reset_all();
$GLOBALS['ab_after_save'] = static function ( $id ) { $GLOBALS['P'][ $id ]['regular_price'] = '13'; };
$res                      = ab_update( array( 'regular_price' => '12' ) );
$out                      = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && '13' === ab_p( 7, 'regular_price' ), 'C8b a price another request set right after the save: refused without force, the newer price stays' );

reset_all();
$res = ab_update( array( 'regular_price' => '12' ) );
unset( $GLOBALS['P'][7], $GLOBALS['TYPES'][7] );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'gone' === $out['blocked'][0]['reason'], 'C9 a product deleted since: gone, also with force' );

reset_all();
$res                      = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['P'][7]['type'] = 'variable'; // made a variable product since
$out                      = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'gone' === $out['blocked'][0]['reason'] && '12' === ab_p( 7, 'regular_price' ), 'C10 a product of another type under the id: not the product of the point (gone), also with force' );

/* ============================== S) the stock: no part of the undo, the answer says so */

// Decision of 31.10.2026: never recorded, never written back.
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$res = ab_update( array( 'stock' => 10 ) );
ok( true === $res['updated'] && 10 === ab_p( 7, 'stock_quantity' ) && null === $res['undo'] && 0 === ab_points() && ! isset( $res['stock_note'] ), 'S1 only the stock: written, no undo point' );
ok( AB_MCP_Undo_Products::stock_note( array( 'manage' => true, 'quantity' => '3' ) ) === $res['undo_note'] && 0 === strpos( $res['undo_note'], 'not undoable: The stock is no part of the undo' ) && false !== strpos( $res['undo_note'], 'it was 3' ), 'S2 ... the note says why, with the stock of before (3)' );
ok( false !== strpos( $res['undo_note'], 'wp_wc_get_product' ) && false !== strpos( $res['undo_note'], 'wp_wc_update_product' ) && false !== strpos( $res['undo_note'], 'sold since' ), 'S3 ... and the way: check it, set it again less what was sold since' );

reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$res = ab_update( array( 'regular_price' => '20', 'stock' => 10 ) );
ok( ab_token( $res ) && array( 'price' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ) && false !== strpos( (string) $res['stock_note'], 'it was 3' ) && ! isset( $res['undo_note'] ), 'S4 price and stock: a point for the price, stock_note for the stock with its value before' );
ab_sell( 7, 2 );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '10' === ab_p( 7, 'regular_price' ) && 8 === ab_p( 7, 'stock_quantity' ) && true === ab_p( 7, 'manage_stock' ) && array() === $GLOBALS['ab_bookings'], 'S5 the undo puts the price back and leaves the stock: the 2 sold stay counted (8)' );

// Codex 31.10. (2): a variation whose stock its parent managed.
reset_all();
ab_product( 7, array( 'type' => 'variable', 'manage_stock' => true, 'stock_quantity' => 10 ) );
ab_product( 8, array( 'parent' => 7, 'regular_price' => '6', 'price' => '6' ), 'product_variation' );
$res = AB_MCP_Tools_Shop::update_product( array( 'id' => 8, 'regular_price' => '7', 'stock' => 10 ) );
ok( true === ab_p( 8, 'manage_stock' ) && 10 === ab_p( 8, 'stock_quantity' ) && false !== strpos( (string) $res['stock_note'], 'did not manage the stock of this product itself' ), 'S6 a variation on its parent\'s stock: the call gave it its own; the note says it did not manage its own before' );
ab_sell( 8, 2 );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '6' === ab_p( 8, 'regular_price' ) && true === ab_p( 8, 'manage_stock' ) && 8 === ab_p( 8, 'stock_quantity' ) && 10 === ab_p( 7, 'stock_quantity' ), 'S7 its undo: the price back, its own stock of 8 stays, the 2 sold are not lost to the parent\'s 10' );
reset_all();
ab_product( 7, array( 'type' => 'variable', 'manage_stock' => true, 'stock_quantity' => 10 ) );
ab_product( 8, array( 'parent' => 7 ), 'product_variation' );
$res = AB_MCP_Tools_Shop::update_product( array( 'id' => 8, 'stock' => 10 ) );
ok( null === $res['undo'] && 0 === ab_points() && 0 === strpos( $res['undo_note'], 'not undoable: The stock is no part of the undo' ) && false !== strpos( $res['undo_note'], 'Inventory' ), 'S8 only the stock of the variation: no point, the note with the way in wp-admin' );

// Codex 31.10. (2): a sale before the save, the same quantity asked as the
// object holds. WooCommerce writes nothing (set_prop() compares with the
// object as loaded); no point books a difference that was none.
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$loads                 = 0;
$GLOBALS['ab_on_load'] = static function ( $id ) use ( &$loads ) { if ( 2 === ++$loads ) { ab_sell( $id ); } };
$res                   = ab_update( array( 'stock' => 3 ) );
$GLOBALS['ab_on_load'] = null;
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'stock' ) && 2 === ab_p( 7, 'stock_quantity' ) && 0 === ab_points(), 'S9 the quantity of the loaded object asked after a sale: WooCommerce stored nothing, the answer says so, no point' );

// The value of before is the stock right before the save, read in the call.
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$loads                 = 0;
$GLOBALS['ab_on_load'] = static function ( $id ) use ( &$loads ) { if ( 2 === ++$loads ) { ab_sell( $id ); } };
$res                   = ab_update( array( 'stock' => 10 ) );
$GLOBALS['ab_on_load'] = null;
ok( false !== strpos( (string) $res['undo_note'], 'it was 2' ), 'S10 a sale between the tool\'s read and its save: the note names the 2 left right before the save' );

reset_all();
$res = ab_update( array( 'stock' => 10 ) ); // a stock not managed before
ok( null === $res['undo'] && AB_MCP_Undo_Products::stock_note( array( 'manage' => false, 'quantity' => null ) ) === $res['undo_note'], 'S11 stock management switched on by the call: the note says it was off before' );
reset_all();
ab_product( 7, array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
$GLOBALS['ab_toolstate']['wp_undo'] = false;
$res                                = ab_update( array( 'regular_price' => '20', 'stock' => 10 ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off' ) && false !== strpos( (string) $res['stock_note'], 'it was 3' ), 'S12 undo switched off: undo_note says so, stock_note the stock all the same' );

/* ============================== D) the rights of the tool, asked again */

reset_all();
$res                = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['ab_deny'] = array( 'edit_product:7' => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && '12' === ab_p( 7, 'regular_price' ), 'D1 a product the account may no longer edit (edit_product): forbidden, even with force' );
$GLOBALS['ab_deny'] = array( 'manage_woocommerce' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ) && '12' === ab_p( 7, 'regular_price' ), 'D2 without manage_woocommerce, the capability of the tool, wp_undo refuses' );
$GLOBALS['ab_deny'] = array();
ok( 1 === ab_undo( $res['undo'] )['undone'], 'D3 with both, it goes back' );

reset_all();
$res                = ab_update( array( 'status' => 'draft' ) );
$GLOBALS['ab_deny'] = array( 'publish_products' => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && 'draft' === ab_p( 7, 'status' ), 'D4 a status of before that takes it live (publish) needs publish_products, as the tool asks it' );
reset_all();
ab_product( 7, array( 'status' => 'draft' ) );
$res = ab_update( array( 'status' => 'publish' ) );
$GLOBALS['ab_deny'] = array( 'publish_products' => true );
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'draft' === ab_p( 7, 'status' ), 'D5 back to a draft needs no right to publish' );
reset_all();
$res                = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['ab_deny'] = array( 'publish_products' => true );
ok( 1 === ab_undo( $res['undo'] )['undone'], 'D6 nor a point without a status' );

reset_all();
ab_product( 8, array( 'parent' => 7 ), 'product_variation' );
$res = AB_MCP_Tools_Shop::update_product( array( 'id' => 8, 'regular_price' => '6' ) );
ok( 'variation' === AB_MCP_Undo::claim( $res['undo'] )['data']['type'] && 7 === AB_MCP_Undo::claim( $res['undo'] )['data']['parent'], 'D7 a variation: its type and its parent in the point' );
$GLOBALS['ab_deny']  = array( 'edit_product:8' => true );
$GLOBALS['ab_asked'] = array();
$out                 = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && in_array( 'edit_product:8', $GLOBALS['ab_asked'], true ), 'D8 ... and the right asked is edit_product for the variation, as the tool asks it' );
$GLOBALS['ab_deny']           = array();
$GLOBALS['P'][8]['parent'] = 9; // moved under another product since
ok( 'gone' === ab_undo( $res['undo'], array( 'force' => true ) )['blocked'][0]['reason'], 'D9 a variation under another parent is not the product of the point' );

reset_all();
$res                        = ab_update( array( 'status' => 'draft' ) );
$GLOBALS['P'][7]['status'] = 'trash'; // trashed since
$out                        = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'in_trash' === $out['blocked'][0]['reason'] && 'trash' === ab_p( 7, 'status' ), 'D10 a status point of a product in the trash: blocked (in_trash), even with force' );
reset_all();
$res                        = ab_update( array( 'regular_price' => '12' ) );
$GLOBALS['P'][7]['status'] = 'trash';
ok( 1 === ab_undo( $res['undo'] )['undone'] && '10' === ab_p( 7, 'regular_price' ) && 'trash' === ab_p( 7, 'status' ), 'D11 a price point of a product in the trash: the price comes back, the product stays in the trash' );

/* ============================== E) a coupon: created, moved into the trash again */

reset_all();
$res = ab_create();
$id  = $res['id'];
ok( true === $res['created'] && 'fruehling' === $res['code'] && ab_token( $res ) && ! isset( $res['undo_note'] ), 'E1 wp_wc_create_coupon answers with a token' );
ok( array( 'code' => 'fruehling', 'amount' => '10', 'discount_type' => 'percent', 'expires' => '2026-12-31' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'E2 saved_fields: code, amount, discount_type and expires as stored, as wp_wc_list_coupons shows them' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_wc_create_coupon' === $c['entry']['tool'] && 'wc_coupon' === $c['entry']['kind'] && 'manage_woocommerce' === $c['entry']['cap'] && sprintf( 'new coupon #%d', $id ) === $c['entry']['summary'], 'E3 kind wc_coupon, the capability of the tool, the summary names the coupon by id' );
ok( array( 'v', 'id', 'after' ) === array_keys( $c['data'] ) && $id === $c['data']['id'] && array( 'coupon' ) === array_keys( $c['data']['after'] ), 'E4 the snapshot names the coupon and keeps its state after; its use is asked at the undo' );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $dry['would_undo'] && 'publish' === ab_coupon( $id )['status'], 'E5 the dry run would restore it and changes nothing' );
$GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] = static fn( $all, $scope ) => array( 'host-' . $scope => static function () {} );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && true === $out['consumed'] && array( 'coupon:' . $id ) === $out['restored'] && 'trash' === ab_coupon( $id )['status'], 'E6 wp_undo moves it into the trash and uses the point up' );
ok( null !== ab_coupon( $id ) && ab_did( 'woocommerce_trash_coupon', $id ) && 0 === wc_get_coupon_id_by_code( 'fruehling' ), 'E7 ... through WooCommerce, into the trash, not for good; the code is free again' );
ok( array() === $out['page_cache_purged'] && 'post' === AB_MCP_Undo_Restore::page_cache_scope()['wc_coupon'], 'E8 no page is purged: a coupon has no page a cache keeps' );
unset( $GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] );
ok( 'ok' === ( is_wp_error( ab_create() ) ? 'no' : 'ok' ), 'E9 and a new coupon can take the code again' );

foreach ( array(
	'given a new amount' => static function ( $id ) { $GLOBALS['C'][ $id ]['amount'] = '20'; },
	'saved in wp-admin'  => static function ( $id ) { $c = new WC_Coupon( $id ); $c->set_individual_use( true ); $c->save(); },
	'given an expiry'    => static function ( $id ) { $GLOBALS['C'][ $id ]['date_expires'] = 1900000000; },
	// Codex 31.10.: a restriction set by meta alone (the REST API, say),
	// which leaves the time of the last change as it was.
	'given a minimum spend by meta'   => static function ( $id ) { $GLOBALS['C'][ $id ]['minimum_amount'] = '50'; },
	'limited to a product by meta'    => static function ( $id ) { $GLOBALS['C'][ $id ]['product_ids'] = array( 7 ); },
	'given a meta row of a plugin'    => static function ( $id ) { $GLOBALS['C'][ $id ]['meta']['_plugin_rule'] = 'members'; },
) as $what => $change ) {
	reset_all();
	$res = ab_create();
	$change( $res['id'] );
	$out = ab_undo( $res['undo'] );
	ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'coupon' ) === $out['blocked'][0]['locs'] && 'publish' === ab_coupon( $res['id'] )['status'] && null !== ab_entry( $res['undo'] ), "E10 a coupon $what since is not trashed, the location named, the point stays" );
}
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && 'trash' === ab_coupon( $res['id'] )['status'], 'E11 with force it is' );

// Codex 31.10. (4), decision of 30.10.2026: only an unused coupon goes,
// whatever force says; the answer says why and what to do instead.
foreach ( array(
	'used in an order'             => static function ( $id ) { $GLOBALS['C'][ $id ]['usage_count'] = 1; $GLOBALS['C'][ $id ]['used_by'] = array( '12' ); },
	'counted as used alone'        => static function ( $id ) { $GLOBALS['C'][ $id ]['usage_count'] = 1; },
	'remembering a customer alone' => static function ( $id ) { $GLOBALS['C'][ $id ]['used_by'] = array( 'anna@example.test' ); },
	'held for its usage limit'     => static function ( $id ) { $GLOBALS['C'][ $id ]['holds'] = array( '_coupon_held_' . ( time() + 600 ) . '_abcdef' ); },
	'held for a customer\'s limit' => static function ( $id ) { $GLOBALS['C'][ $id ]['holds'] = array( '_maybe_used_by_' . ( time() + 600 ) . '_abcdef' ); },
) as $what => $use ) {
	reset_all();
	$res = ab_create();
	$use( $res['id'] );
	$out = ab_undo( $res['undo'], array( 'force' => true ) );
	ok( 0 === $out['undone'] && 'in_use' === $out['blocked'][0]['reason'] && 'publish' === ab_coupon( $res['id'] )['status'] && null !== ab_entry( $res['undo'] ), "E10b a coupon $what: never into the trash, also with force (in_use)" );
	ok( AB_MCP_Undo_Coupons::in_use_note( $res['id'] ) === $out['blocked'][0]['note'] && false !== strpos( $out['blocked'][0]['note'], 'Marketing › Coupons' ) && false !== strpos( $out['blocked'][0]['note'], 'wp_wc_list_coupons' ), "E10c ... the answer says why and what to do instead ($what)" );
}
$dry = ab_undo( $res['undo'], array( 'dry_run' => true, 'force' => true ) );
ok( 0 === $dry['would_undo'] && 'in_use' === $dry['blocked'][0]['reason'] && isset( $dry['blocked'][0]['note'] ), 'E10d the dry run says so too' );

reset_all();
$res                                      = ab_create();
$GLOBALS['C'][ $res['id'] ]['holds'] = array( '_coupon_held_' . ( time() - 600 ) . '_abcdef', '_coupon_held_x' ); // a hold run out, a row of another form
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'trash' === ab_coupon( $res['id'] )['status'], 'E10e a hold whose time ran out holds nothing: the unused coupon goes' );
reset_all();
$res = ab_create();
$GLOBALS['C'][ $res['id'] ]['usage_count'] = 1;
$GLOBALS['C'][ $res['id'] ]['usage_count'] = 0; // the order was cancelled: WooCommerce counted it back
ok( 1 === ab_undo( $res['undo'] )['undone'], 'E10f a use counted back is no use' );

reset_all();
$res  = ab_create();
$plan = AB_MCP_Undo_Coupons::plan( 'wp_wc_create_coupon', AB_MCP_Undo::claim( $res['undo'] )['data'], true );
$GLOBALS['C'][ $res['id'] ]['usage_count'] = 1; // used between the plan and the write
$again = AB_MCP_Undo_Coupons::restore( $plan['items'][0] );
ok( 'ab_mcp_undo_coupon_in_use' === ab_code( $again ) && AB_MCP_Undo_Coupons::in_use_note( $res['id'] ) === ab_msg( $again ) && 'publish' === ab_coupon( $res['id'] )['status'], 'E12 used between the plan and the write, with force: refused, with the note' );

reset_all();
$res = ab_create();
wp_trash_post( $res['id'] ); // trashed by hand since
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && true === $out['consumed'] && ! ab_did( 'woocommerce_trash_coupon', $res['id'] ), 'E13 a coupon in the trash since: nothing to do, the point is used up' );
reset_all();
$res = ab_create();
unset( $GLOBALS['C'][ $res['id'] ], $GLOBALS['TYPES'][ $res['id'] ] ); // deleted for good since
ok( 1 === ab_undo( $res['undo'] )['undone'], 'E14 a coupon deleted since: nothing to do either' );

reset_all();
$res                = ab_create();
$GLOBALS['ab_deny'] = array( 'delete_shop_coupon:' . $res['id'] => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && 'publish' === ab_coupon( $res['id'] )['status'], 'E15 a coupon the account may not delete (delete_shop_coupon): forbidden, even with force' );
$GLOBALS['ab_deny'] = array( 'manage_woocommerce' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'E16 without manage_woocommerce, wp_undo refuses' );
$GLOBALS['ab_deny'] = array();

reset_all();
$res               = ab_create();
$GLOBALS['ab_veto'] = true; // a plugin on woocommerce_pre_delete_coupon
$out               = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && false !== strpos( (string) $out['failed'][0]['reason'], 'wp_wc_list_coupons' ) && null !== ab_entry( $res['undo'] ) && 'publish' === ab_coupon( $res['id'] )['status'], 'E17 a trash a plugin stopped: failed with the way, the point stays' );
$GLOBALS['ab_veto'] = false;

ok( 'ab_mcp_duplicate' === ab_code( ab_create() ) && 1 === ab_points(), 'E18 a code in use: refused before anything exists, no second point' );

// A meta row a plugin gave the coupon when it was created, changed since.
reset_all();
$GLOBALS['ab_on_coupon'] = static function ( $id ) { $GLOBALS['C'][ $id ]['meta']['_plugin_rule'] = 'all'; };
$res                     = ab_create();
$GLOBALS['ab_on_coupon'] = null;
$GLOBALS['C'][ $res['id'] ]['meta']['_plugin_rule'] = 'members';
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && array( 'coupon' ) === $out['blocked'][0]['locs'] && 'publish' === ab_coupon( $res['id'] )['status'], 'E20 a meta row of a plugin whose value changed since: changed, not trashed' );

reset_all();
$res = ab_create();
( new WC_Coupon( $res['id'] ) )->save(); // saved again, nothing changed: only its time
ok( $GLOBALS['ab_clock'] > ab_coupon( $res['id'] )['date_created'] + 1 && 1 === ab_undo( $res['undo'] )['undone'] && 'trash' === ab_coupon( $res['id'] )['status'], 'E19 a coupon saved again unchanged: the times are no part of it, the undo trashes it' );

/* ============================== F) the status of an order: not undoable */

reset_all();
$res = ab_status( 'completed' );
ok( true === $res['updated'] && false === $res['status_unchanged'] && 'completed' === $GLOBALS['O'][5]->status && array_key_exists( 'undo', $res ) && null === $res['undo'] && 0 === ab_points(), 'F1 a new status: written, undo null, no point' );
ok( AB_MCP_Undo_Objects::order_status_note( true ) === $res['undo_note'] && 0 === strpos( $res['undo_note'], 'not undoable: WooCommerce reacts to a new order status' ), 'F2 ... the note says it is not undoable, and why' );
ok( false !== strpos( $res['undo_note'], 'wp_wc_get_order' ) && false !== strpos( $res['undo_note'], 'wp_wc_update_order_status' ) && false !== strpos( $res['undo_note'], 'refund' ) && false !== strpos( $res['undo_note'], 'stock' ), 'F3 ... naming stock, refund and the way: check it first, set the old status knowing WooCommerce reacts' );
$res = ab_status( 'completed', 'Kunde informiert' );
ok( true === $res['status_unchanged'] && array( 'Kunde informiert' ) === $GLOBALS['O'][5]->notes && null === $res['undo'] && AB_MCP_Undo_Objects::order_status_note( false ) === $res['undo_note'], 'F4 the status set already, with a note: the note alone is not undoable either' );
ok( 0 === strpos( $res['undo_note'], 'not undoable: The order had this status already' ) && false !== strpos( $res['undo_note'], 'wp_wc_get_order' ), 'F5 ... with its reason and the way' );
$res = ab_status( 'completed' );
ok( null === $res['undo'] && AB_MCP_Undo_Answer::nothing_changed( 'wp_wc_get_order' ) === $res['undo_note'] && 0 === ab_points(), 'F6 the status set already, no note: nothing changed' );
$res = ab_status( 'processing', 'Zurueck' );
ok( false === $res['status_unchanged'] && AB_MCP_Undo_Objects::order_status_note( true ) === $res['undo_note'], 'F7 a new status with a note: the note of the status' );
ok( 'ab_mcp_bad_status' === ab_code( ab_status( 'complete' ) ) && 0 === ab_points(), 'F8 an unknown status is refused as before' );
ok( ! isset( AB_MCP_Undo_Restore::policy()['wp_wc_update_order_status'] ), 'F9 and the policy names no undo of it' );

/* ============================== H) why there is no undo point */

reset_all();
$GLOBALS['ab_toolstate']['wp_undo'] = false;
foreach ( array(
	'wp_wc_update_product' => static fn() => ab_update( array( 'regular_price' => '12' ) ),
	'wp_wc_create_coupon'  => static fn() => ab_create( array( 'code' => 'aus' ) ),
) as $tool => $call ) {
	$res = $call();
	ok( is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off on this site' ) && 0 === ab_points(), "H1 undo switched off: the note says so ($tool)" );
}
ok( '12' === ab_p( 7, 'regular_price' ) && 0 !== wc_get_coupon_id_by_code( 'aus' ), 'H2 the writes happen all the same' );
reset_all();
$GLOBALS['ab_toolstate']['wp_undo'] = false;
ok( AB_MCP_Undo_Objects::order_status_note( true ) === ab_status( 'completed' )['undo_note'], 'H3 a status change says not undoable whether undo is on or not' );

/* ============================== K) a hand-edited point gains nothing */

reset_all();
$res  = ab_update( array( 'regular_price' => '12', 'status' => 'draft' ) );
$good = AB_MCP_Undo::claim( $res['undo'] )['data'];
$pr   = $good['before']['price'];
$bad  = array(
	'a field more'                 => array_merge( $good, array( 'x' => 1 ) ),
	'another version'              => array_merge( $good, array( 'v' => 2 ) ),
	'the id as text'               => array_merge( $good, array( 'id' => '7' ) ),
	'a type that is no slug'       => array_merge( $good, array( 'type' => 'Simple Product' ) ),
	'a parent below zero'          => array_merge( $good, array( 'parent' => -1 ) ),
	'nothing before'               => array_merge( $good, array( 'before' => array(), 'after' => array() ) ),
	'a location the tool lacks'    => array_merge( $good, array( 'before' => array( 'name' => 'x' ), 'after' => array( 'name' => $good['after']['price'] ) ) ),
	'a stock location'             => array_merge( $good, array( 'before' => array( 'price' => $pr, 'stock' => array( 'delta' => '4', 'manage' => true, 'status' => 'instock' ) ), 'after' => array( 'price' => $good['after']['price'], 'stock' => $good['after']['price'] ) ) ),
	'the locations out of order'   => array_merge( $good, array( 'before' => array_reverse( $good['before'], true ), 'after' => array_reverse( $good['after'], true ) ) ),
	'after without a location'     => array_merge( $good, array( 'after' => array( 'price' => $good['after']['price'] ) ) ),
	'a price in another form'      => array_merge( $good, array( 'before' => array( 'price' => array_merge( $pr, array( 'regular_price' => '1e3' ) ), 'status' => 'publish' ) ) ),
	'prices with the derived price' => array_merge( $good, array( 'before' => array( 'price' => $pr + array( 'price' => '10' ), 'status' => 'publish' ) ) ),
	'prices without the period'    => array_merge( $good, array( 'before' => array( 'price' => array( 'regular_price' => '10', 'sale_price' => '' ), 'status' => 'publish' ) ) ),
	'a sale date that is no time'  => array_merge( $good, array( 'before' => array( 'price' => array_merge( $pr, array( 'date_on_sale_to' => '2026-12-31' ) ), 'status' => 'publish' ) ) ),
	'a status the tool lacks'      => array_merge( $good, array( 'before' => array( 'price' => $pr, 'status' => 'trash' ) ) ),
	'a hash that is none'          => array_merge( $good, array( 'after' => array( 'price' => 'x', 'status' => $good['after']['status'] ) ) ),
);
foreach ( $bad as $what => $data ) {
	$t = ab_forge( 'wp_wc_update_product', 'wc_product', $data );
	$p = AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] );
	ok( 'ab_mcp_undo_corrupt' === ab_code( $p ) && false !== strpos( ab_msg( $p ), 'wp_undo_discard' ), "K1 a product point refused whole: $what" );
}
ok( '12' === ab_p( 7, 'regular_price' ) && 'draft' === ab_p( 7, 'status' ), 'K2 ... and nothing written' );
$coupon = ab_create();
$cgood  = AB_MCP_Undo::claim( $coupon['undo'] )['data'];
foreach ( array(
	'a field more'          => array_merge( $cgood, array( 'before' => array() ) ),
	'with a location of use' => array_merge( $cgood, array( 'after' => array( 'coupon' => $cgood['after']['coupon'], 'use' => $cgood['after']['coupon'] ) ) ),
	'a hash that is none'    => array_merge( $cgood, array( 'after' => array( 'coupon' => 'x' ) ) ),
	'the id below one'      => array_merge( $cgood, array( 'id' => 0 ) ),
) as $what => $data ) {
	$t = ab_forge( 'wp_wc_create_coupon', 'wc_coupon', $data );
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), "K3 a coupon point refused whole: $what" );
}
$t = ab_forge( 'wp_wc_create_coupon', 'wc_coupon', $good );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), 'K4 a product point under the coupon tool is refused' );
$t = ab_forge( 'wp_wc_update_product', 'wc_coupon', $cgood );
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), 'K5 a kind that does not match its tool' );
$t = ab_forge( 'wp_wc_update_order_status', 'wc_product', $good );
ok( 'ab_mcp_undo_unknown_tool' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ) && '12' === ab_p( 7, 'regular_price' ), 'K6 a point claiming the order tool is refused: it records none' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && '10' === ab_p( 7, 'regular_price' ) && 1 === ab_undo( $coupon['undo'] )['undone'], 'K7 the real points still work' );

/* ============================== V) answers name ids, never a price or a code */

reset_all();
ab_product( 7, array( 'regular_price' => '777.77' ) );
$res = ab_update( array( 'regular_price' => '888.88' ) );
$cou = ab_create( array( 'code' => 'geheimcode' ) );
$GLOBALS['P'][7]['regular_price'] = '999.99';
$dry  = array( ab_undo( $res['undo'], array( 'dry_run' => true ) ), ab_undo( $cou['undo'], array( 'dry_run' => true ) ) );
$list = AB_MCP_Tools_Undo_Core::undo_list( array() );
$json = (string) wp_json_encode( array( $dry, $list ) );
// Whole prices with their decimal point: tokens are hex and times are whole
// numbers, so neither can hold one by chance.
ok( false === strpos( $json, '777.77' ) && false === strpos( $json, '888.88' ) && false === strpos( $json, '999.99' ) && false === strpos( $json, 'geheimcode' ) && 2 === $list['count'], 'V1 neither the dry run nor wp_undo_list shows a price or a code' );

/* ============================== N) the notes of a change without undo, and the wiring */

$notes = AB_MCP_Undo_Objects::own_notes();
foreach ( array( 'wp_wc_create_coupon' => 'wp_wc_list_coupons', 'wp_wc_update_order_status' => 'wp_wc_get_order', 'order-note' => 'wp_wc_get_order' ) as $key => $reader ) {
	ok( isset( $notes[ $key ] ) && 0 === strpos( $notes[ $key ], 'not undoable: ' ) && false !== strpos( $notes[ $key ], $reader ), "N1 the note of $key says not undoable and names $reader" );
}
ok( in_array( 'wc_product', explode( ',', AB_MCP_Undo::DB_KINDS ), true ) && in_array( 'wc_coupon', explode( ',', AB_MCP_Undo::DB_KINDS ), true ), 'N2 both kinds lie inline when small (DB_KINDS)' );
ok( 'wc_product' === AB_MCP_Undo_Restore::policy()['wp_wc_update_product']['kind'] && 'wc_coupon' === AB_MCP_Undo_Restore::policy()['wp_wc_create_coupon']['kind'], 'N3 the policy names both tools with their kinds' );

/* ============================== T) the trash turned off, in a process of its own */

$pipes = array();
$proc  = proc_open(
	array( PHP_BINARY, __FILE__ ),
	array(
		0 => array( 'file', '/dev/null', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'redirect', 1 ),
	),
	$pipes,
	__DIR__,
	array_merge( getenv(), array( 'AB_TRASH_OFF' => '1' ) )
);
$child = is_resource( $proc ) ? (string) stream_get_contents( $pipes[1] ) : '';
if ( is_resource( $proc ) ) {
	fclose( $pipes[1] );
	$code = proc_close( $proc );
} else {
	$code = -1;
}
if ( 1 === preg_match( '/^UNDO-SHOP-TRASH-OFF: (\d+) passed, (\d+) failed$/m', $child, $m ) ) {
	$pass += (int) $m[1];
	$fail += (int) $m[2];
	ok( (int) $m[1] >= 6 && 0 === (int) $m[2] && 0 === $code, 'T0 the process with the trash off ran its checks' );
	$rest = trim( str_replace( $m[0], '', $child ) );
	if ( '' !== $rest ) {
		echo $rest, "\n";
	}
} else {
	ok( false, "T0 the process with the trash off did not finish:\n" . $child );
}

/* ============================== end */

echo "UNDO-SHOP: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
