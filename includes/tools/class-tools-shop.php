<?php
/**
 * WooCommerce, read only: orders, products, coupons and customers.
 *
 * Free since 4.7.0. Until then these tools came with AlphaBridge MCP Pro only;
 * the code is Pro's, unchanged in what it does. Pro registers its own copy of
 * the same names over these. Changing prices, stock and order status and
 * creating coupons stay in Pro. Registered always; every handler first checks
 * that WooCommerce is active and answers with the way to it otherwise.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Shop
 */
class AB_MCP_Tools_Shop extends AB_MCP_Tools_Base {

	/**
	 * Guard: WooCommerce must be active.
	 *
	 * @return true|WP_Error
	 */
	private static function wc_ready() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'ab_mcp_no_woocommerce', __( 'WooCommerce is not active on this site. Ask an administrator to activate it under Plugins in the WordPress dashboard, or to install it there first.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {
		$cap = 'manage_woocommerce';

		$r->register(
			'wp_wc_list_orders',
			array(
				'description' => 'WooCommerce: list the shop\'s orders, newest first, one page at a time. Each item gives id, status, total, currency, customer (billing name), email and date (ISO 8601); pagination gives total and total_pages. Line items and payment method are in wp_wc_get_order. Read-only; needs manage_woocommerce. An order status the shop does not know is refused with the list of the ones it does. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'description' => 'Only orders in this status: one of the shop\'s statuses (pending, processing, on-hold, completed, cancelled, refunded, failed, plus any a plugin registers), with or without the wc- prefix, or trash. Omitted or any: the order statuses the shop has registered (wc_get_order_statuses), so not trash.' ),
						'search'   => array( 'type' => 'string', 'description' => 'Search term, handed to WooCommerce\'s own order search. Omitted: no search.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Orders per page, 1 to 100. Default 20.' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_orders' ),
			)
		);

		$r->register(
			'wp_wc_get_order',
			array(
				'description' => 'WooCommerce: get one order by id. Returns the fields of the order list (id, status, total, currency, customer, email, date) plus items (name, quantity, line total), payment_method (its title) and note_to_customer. An unknown id returns a not-found error; wp_wc_list_orders lists the orders with their ids. Read-only; needs manage_woocommerce. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Order id (the order number WooCommerce shows, unless a plugin renumbers orders).' ) ),
				),
				'callback'    => array( __CLASS__, 'get_order' ),
			)
		);

		$r->register(
			'wp_wc_list_products',
			array(
				'description' => 'WooCommerce: list products, one page at a time. Each item gives id, name, sku, price, regular_price, sale_price, stock, stock_status and status (the same fields wp_wc_get_product returns for one product); pagination gives total and total_pages. One product by id: wp_wc_get_product. Read-only; needs manage_woocommerce. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search term, handed to WooCommerce\'s product search. Omitted: no search.' ),
						'status'   => array( 'type' => 'string', 'description' => 'Only products in this post status, such as publish, draft, pending or private. Passed to WooCommerce as given. Omitted: WooCommerce\'s default statuses.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Products per page, 1 to 100. Default 20.' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_products' ),
			)
		);

		$r->register(
			'wp_wc_get_product',
			array(
				'description' => 'WooCommerce: get one product by id: id, name, sku, price (the current one), regular_price, sale_price, stock, stock_status and status. An unknown id returns a not-found error; wp_wc_list_products lists the products with their ids. Read-only; needs manage_woocommerce. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Product id.' ) ),
				),
				'callback'    => array( __CLASS__, 'get_product' ),
			)
		);

		$r->register(
			'wp_wc_list_coupons',
			array(
				'description' => 'WooCommerce: list the shop\'s published coupons with id, code, amount, discount_type and expires (YYYY-MM-DD or null). One page only, newest first; there is no paging. Read-only; needs manage_woocommerce. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'per_page' => array( 'type' => 'integer', 'description' => 'How many coupons, 1 to 200. Default 50.' ) ),
				),
				'callback'    => array( __CLASS__, 'list_coupons' ),
			)
		);

		$r->register(
			'wp_wc_list_customers',
			array(
				'description' => 'WooCommerce: list registered users with the role customer, with id, email, name and order_count, plus total for paging. Guest orders without an account are not included; wp_list_users lists users of every role. Read-only; needs manage_woocommerce. Requires WooCommerce.',
				'capability'  => $cap,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'WordPress user search, which picks the fields by the term: with "@" the email, starting with http:// or https:// the URL (on a large multisite network the general fields instead), a number the login (or the exact id), otherwise login, email, URL, nicename and display name; matched anywhere in the field. Omitted: all customers.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Customers per page, 1 to 100. Default 20.' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_customers' ),
			)
		);
	}

	/* --------------------------------------------------------------- orders */

	/**
	 * List orders.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_orders( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$args     = array(
			'limit'    => $per_page,
			'paged'    => max( 1, self::i( $a, 'page', 1 ) ),
			'paginate' => true,
			// Newest first, as WooCommerce orders them anyway, with the id
			// breaking a tie: orders placed in the same second — a busy shop,
			// an import — came back in whatever order the database chose, so a
			// page could repeat one and skip another. Both order stores take
			// the list, the order tables (HPOS) as well as the posts table.
			'orderby'  => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);
		$status = self::s( $a, 'status', '' );
		if ( '' !== $status && 'any' !== $status ) {
			// A typo here would come back as an empty list, which reads like
			// "no such orders". trash is not in wc_get_order_statuses() but is
			// a status orders can be asked for, so it is allowed here.
			if ( 'trash' === strtolower( trim( $status ) ) ) {
				$args['status'] = 'trash';
			} else {
				$resolved = self::order_status_or_error( $status );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$args['status'] = $resolved;
			}
		}
		$search = self::s( $a, 'search', '' );
		if ( $search ) {
			$args['s'] = $search;
		}

		$res   = wc_get_orders( $args );
		$items = array();
		foreach ( $res->orders as $order ) {
			$items[] = self::order_summary( $order );
		}
		return array(
			'items'      => $items,
			'pagination' => array(
				'total'       => (int) $res->total,
				'total_pages' => (int) $res->max_num_pages,
			),
		);
	}

	/**
	 * Order summary.
	 *
	 * @param WC_Order $o Order.
	 * @return array
	 */
	private static function order_summary( $o ) {
		return array(
			'id'       => $o->get_id(),
			'status'   => $o->get_status(),
			'total'    => $o->get_total(),
			'currency' => $o->get_currency(),
			'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'email'    => $o->get_billing_email(),
			'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
		);
	}

	/**
	 * Get order.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_order( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$order = wc_get_order( self::i( $a, 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Order not found. wp_wc_list_orders lists the orders.', 'alphabridge-mcp' ) );
		}
		$data          = self::order_summary( $order );
		$data['items'] = array();
		foreach ( $order->get_items() as $item ) {
			$data['items'][] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			);
		}
		$data['payment_method']   = $order->get_payment_method_title();
		$data['note_to_customer'] = $order->get_customer_note();
		return $data;
	}

	/**
	 * The registered order status a caller means, or the error that names the
	 * shop's statuses. Looked up in wc_get_order_statuses(), with or without
	 * the wc- prefix. A key is only handed over when it survives the round
	 * trip WC_Order::set_status() makes (strip one wc- prefix, look up "wc-"
	 * plus the rest): WooCommerce strips exactly one prefix from it, and a
	 * status registered as "wc-wc-packed" must not turn into "packed".
	 *
	 * @param string $raw Status as given, with or without the "wc-" prefix.
	 * @return string|WP_Error Registered status key.
	 */
	private static function order_status_or_error( $raw ) {
		$given = strtolower( trim( (string) $raw ) );
		$keys  = array();
		foreach ( array_keys( (array) wc_get_order_statuses() ) as $key ) {
			$keys[ strtolower( (string) $key ) ] = (string) $key;
		}
		if ( '' !== $given ) {
			foreach ( array( $given, 'wc-' . $given ) as $candidate ) {
				if ( ! isset( $keys[ $candidate ] ) ) {
					continue;
				}
				$bare = 0 === strpos( $candidate, 'wc-' ) ? substr( $candidate, 3 ) : $candidate;
				if ( isset( $keys[ 'wc-' . $bare ] ) ) {
					return $keys[ $candidate ];
				}
			}
		}
		// Only statuses that survive the same round trip: naming one that would
		// come back as this very error would send the caller in a circle.
		$known = array();
		foreach ( $keys as $key ) {
			$bare = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			if ( isset( $keys[ 'wc-' . $bare ] ) ) {
				$known[] = $bare;
			}
		}
		return new WP_Error(
			'ab_mcp_bad_status',
			sprintf(
				/* translators: 1: the status given, 2: comma-separated list of the shop's statuses */
				__( 'Unknown order status "%1$s". This shop knows: %2$s.', 'alphabridge-mcp' ),
				$given,
				implode( ', ', $known )
			)
		);
	}

	/* --------------------------------------------------------------- products */

	/**
	 * List products.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_products( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$args     = array(
			'limit'    => $per_page,
			'page'     => max( 1, self::i( $a, 'page', 1 ) ),
			'paginate' => true,
		);
		if ( self::s( $a, 'search', '' ) ) {
			$args['s'] = self::s( $a, 'search' );
		}
		if ( self::s( $a, 'status', '' ) ) {
			$args['status'] = self::s( $a, 'status' );
		}
		$res   = wc_get_products( $args );
		$items = array();
		foreach ( $res->products as $p ) {
			$items[] = self::product_summary( $p );
		}
		return array(
			'items'      => $items,
			'pagination' => array(
				'total'       => (int) $res->total,
				'total_pages' => (int) $res->max_num_pages,
			),
		);
	}

	/**
	 * Product summary.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function product_summary( $p ) {
		return array(
			'id'            => $p->get_id(),
			'name'          => $p->get_name(),
			'sku'           => $p->get_sku(),
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'    => $p->get_sale_price(),
			'stock'         => $p->get_stock_quantity(),
			'stock_status'  => $p->get_stock_status(),
			'status'        => $p->get_status(),
		);
	}

	/**
	 * Get product.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_product( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$p = wc_get_product( self::i( $a, 'id' ) );
		if ( ! $p ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Product not found. wp_wc_list_products lists the products.', 'alphabridge-mcp' ) );
		}
		return self::product_summary( $p );
	}

	/* --------------------------------------------------------------- coupons */

	/**
	 * List coupons.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_coupons( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'posts_per_page' => self::clamp( self::i( $a, 'per_page', 50 ), 1, 200 ),
				'post_status'    => 'publish',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$c     = new WC_Coupon( $post->ID );
			$out[] = array(
				'id'            => $post->ID,
				'code'          => $c->get_code(),
				'amount'        => $c->get_amount(),
				'discount_type' => $c->get_discount_type(),
				'expires'       => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : null,
			);
		}
		return array( 'coupons' => $out );
	}

	/* --------------------------------------------------------------- customers */

	/**
	 * List customers.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_customers( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$q        = new WP_User_Query(
			array(
				'role'   => 'customer',
				'search' => self::s( $a, 'search', '' ) ? '*' . self::s( $a, 'search' ) . '*' : '',
				'number' => $per_page,
				'paged'  => max( 1, self::i( $a, 'page', 1 ) ),
			)
		);
		$out = array();
		foreach ( $q->get_results() as $u ) {
			$out[] = array(
				'id'          => (int) $u->ID,
				'email'       => $u->user_email,
				'name'        => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name,
				'order_count' => function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $u->ID ) : null,
			);
		}
		return array(
			'customers' => $out,
			'total'     => (int) $q->get_total(),
		);
	}
}
