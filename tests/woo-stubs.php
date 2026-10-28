<?php
/**
 * WooCommerce for FreeSinceFourSevenTest: just the functions and classes the
 * read-only shop tools call, answering what the test put in
 * $GLOBALS['ab_test_wc']. Loaded by that test only after it has checked the
 * answer on a site without WooCommerce, since a class cannot be unloaded.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
	}
}

/** A date as WooCommerce returns it. */
final class AB_Test_WC_Date {
	private $iso;
	public function __construct( string $iso ) {
		$this->iso = $iso;
	}
	public function date( $format ) {
		return 'Y-m-d' === $format ? substr( $this->iso, 0, 10 ) : $this->iso;
	}
}

/** An order with the getters the tools read. */
final class AB_Test_WC_Order {
	private $d;
	public function __construct( array $d ) {
		$this->d = $d;
	}
	public function __call( $name, $args ) {
		$key = substr( (string) $name, 4 );
		if ( 'date_created' === $key ) {
			return isset( $this->d['date'] ) ? new AB_Test_WC_Date( $this->d['date'] ) : null;
		}
		if ( 'items' === $key ) {
			return array_map(
				static fn( array $i ) => new class( $i ) {
					private $i;
					public function __construct( array $i ) {
						$this->i = $i;
					}
					public function get_name() {
						return $this->i['name'];
					}
					public function get_quantity() {
						return $this->i['quantity'];
					}
					public function get_total() {
						return $this->i['total'];
					}
				},
				$this->d['items'] ?? array()
			);
		}
		return $this->d[ $key ] ?? '';
	}
}

/** A product with the getters the tools read. */
final class AB_Test_WC_Product {
	private $d;
	public function __construct( array $d ) {
		$this->d = $d;
	}
	public function __call( $name, $args ) {
		$key = substr( (string) $name, 4 );
		return 'stock_quantity' === $key ? ( $this->d['stock'] ?? null ) : ( $this->d[ $key ] ?? '' );
	}
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		private $d;
		public function __construct( $id ) {
			$this->d = $GLOBALS['ab_test_wc']['coupons'][ (int) $id ] ?? array();
		}
		public function get_code() {
			return $this->d['code'] ?? '';
		}
		public function get_amount() {
			return $this->d['amount'] ?? '';
		}
		public function get_discount_type() {
			return $this->d['discount_type'] ?? '';
		}
		public function get_date_expires() {
			return isset( $this->d['expires'] ) ? new AB_Test_WC_Date( $this->d['expires'] ) : null;
		}
	}
}

/** The order statuses of a stock shop. */
function wc_get_order_statuses() {
	return $GLOBALS['ab_test_wc']['statuses'] ?? array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-refunded'   => 'Refunded',
		'wc-failed'     => 'Failed',
	);
}

/** Notes the query and answers the orders of the test, paginated as WooCommerce does. */
function wc_get_orders( $args = array() ) {
	$GLOBALS['ab_test_wc']['order_query'] = $args;
	$orders = array_map( static fn( array $d ) => new AB_Test_WC_Order( $d ), $GLOBALS['ab_test_wc']['orders'] ?? array() );
	return (object) array(
		'orders'        => $orders,
		'total'         => count( $orders ),
		'max_num_pages' => count( $orders ) ? 1 : 0,
	);
}

function wc_get_order( $id ) {
	$d = $GLOBALS['ab_test_wc']['orders'][ (int) $id ] ?? null;
	return null === $d ? false : new AB_Test_WC_Order( $d );
}

function wc_get_products( $args = array() ) {
	$GLOBALS['ab_test_wc']['product_query'] = $args;
	$products = array_map( static fn( array $d ) => new AB_Test_WC_Product( $d ), $GLOBALS['ab_test_wc']['products'] ?? array() );
	return (object) array(
		'products'      => $products,
		'total'         => count( $products ),
		'max_num_pages' => count( $products ) ? 1 : 0,
	);
}

function wc_get_product( $id ) {
	$d = $GLOBALS['ab_test_wc']['products'][ (int) $id ] ?? null;
	return null === $d ? false : new AB_Test_WC_Product( $d );
}

function wc_get_customer_order_count( $user_id ) {
	return (int) ( $GLOBALS['ab_test_wc']['order_counts'][ (int) $user_id ] ?? 0 );
}

if ( ! function_exists( 'get_posts' ) ) {
	/** The coupons of the test as posts, with the query noted. */
	function get_posts( $args = array() ) {
		$GLOBALS['ab_test_wc']['coupon_query'] = $args;
		$out = array();
		foreach ( array_keys( $GLOBALS['ab_test_wc']['coupons'] ?? array() ) as $id ) {
			$out[] = (object) array( 'ID' => (int) $id );
		}
		return $out;
	}
}
