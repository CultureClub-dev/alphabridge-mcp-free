<?php
/**
 * WooCommerce: orders, products, coupons and customers.
 *
 * Free since 4.7.0 (reading), since 4.9.0 also setting an order's status,
 * changing a product's price, stock and status, and creating a coupon. Until
 * then these tools came with AlphaBridge MCP Pro only; the code is Pro's
 * (4.8.11), unchanged in what it does, with its checks: the right to the one
 * order or product, only statuses the shop knows, prices and dates checked
 * before anything is written. Pro registers its own copy of the same names
 * over these. Registered always; every handler first checks that WooCommerce
 * is active and answers with the way to it otherwise.
 *
 * Since 4.14.0 a change of a product and a new coupon leave an undo point
 * (AB_MCP_Undo_Products, AB_MCP_Undo_Coupons) and read back what WooCommerce
 * stored; a change of an order's status says it is not undoable.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-products.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-coupons.php';

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
	 * The capability WooCommerce's REST API asks to edit one object: the
	 * edit_post of its post type (edit_shop_order, edit_product). map_meta_cap
	 * hands it on as edit_post, but a user_has_cap filter sees this name, so
	 * asking edit_post instead would pass where such a filter refuses
	 * (Codex, 28.10.2026). edit_post where the type is not registered.
	 * Public since 4.14.0: the undo of a product asks it again
	 * (AB_MCP_Undo_Products::refusal()).
	 *
	 * @param string|false $type Post type.
	 * @return string
	 */
	public static function edit_cap( $type ) {
		return self::type_cap( $type, 'edit_post' );
	}

	/**
	 * The capability to delete one object of a type, under its name as
	 * edit_cap() asks it (delete_shop_coupon); delete_post where the type is
	 * not registered. Since 4.14.0, for the undo of a new coupon
	 * (AB_MCP_Undo_Coupons::refusal()).
	 *
	 * @param string|false $type Post type.
	 * @return string
	 */
	public static function delete_cap( $type ) {
		return self::type_cap( $type, 'delete_post' );
	}

	/**
	 * One meta capability of a post type under its own name, or the generic
	 * one where the type is not registered.
	 *
	 * @param string|false $type Post type.
	 * @param string       $cap  edit_post or delete_post.
	 * @return string
	 */
	private static function type_cap( $type, $cap ) {
		$obj = is_string( $type ) && '' !== $type ? get_post_type_object( $type ) : null;
		return is_object( $obj ) && isset( $obj->cap->$cap ) && is_string( $obj->cap->$cap ) ? $obj->cap->$cap : $cap;
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
			'wp_wc_update_order_status',
			array(
				'description' => 'WooCommerce: set one order to one of the statuses this shop knows (processing, completed, on-hold, cancelled, refunded, … plus any a plugin registers); an unknown status is refused, with the list of known ones. Only the status changes: no payment is touched and no money moves, but WooCommerce reacts as in the order screen (status e-mails, stock handling, whatever plugins attach to the transition), and "refunded" makes it record a full refund of what is still unrefunded (wc_order_fully_refunded), which shows in the shop\'s reports. A note is added as a private order note of its own, beside WooCommerce\'s transition note, on every call that carries one, also when the status was already set. Returns updated, id, status and status_unchanged, with undo: null and undo_note: a change of the status is not undoable, since setting the old status again takes back none of what WooCommerce did but makes it react once more; wp_wc_get_order shows an order before it changes, and wp_wc_update_order_status sets the old status again where that is wanted, with WooCommerce reacting to it as well. With the status set already, the note alone is not undoable either, and without a note undo_note says that nothing changed. An unknown order is a not-found error. Needs manage_woocommerce and the right to edit this order (edit_shop_order), which a site can withhold for single orders. Requires WooCommerce.',
				'open_world'  => true, // WooCommerce mails the customer on most status changes.
				'capability'  => $cap,
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id', 'status' ),
					'properties' => array(
						'id'     => array( 'type' => 'integer', 'description' => 'Order id, as wp_wc_list_orders returns it.' ),
						'status' => array( 'type' => 'string', 'description' => 'One of the shop\'s order statuses, with or without the wc- prefix.' ),
						'note'   => array( 'type' => 'string', 'description' => 'Optional private order note, added as an entry of its own. Omitted: no note besides WooCommerce\'s own.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_order_status' ),
			)
		);

		$r->register(
			'wp_wc_list_products',
			array(
				'description' => 'WooCommerce: list products, one page at a time. Each item gives id, name, sku, price, regular_price, sale_price, stock, stock_status and status (the same fields wp_wc_get_product returns for one product); pagination gives total and total_pages. One product by id: wp_wc_get_product; changing one: wp_wc_update_product. Needs manage_woocommerce. Requires WooCommerce.',
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
				'description' => 'WooCommerce: get one product by id: id, name, sku, price (the current one), regular_price, sale_price, stock, stock_status and status. An unknown id returns a not-found error; wp_wc_list_products lists the products with their ids, and wp_wc_update_product changes one. Needs manage_woocommerce. Requires WooCommerce.',
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
			'wp_wc_update_product',
			array(
				'description' => 'WooCommerce: change a product\'s regular price, sale price, stock quantity or status. Only the fields given change; all of them are checked before anything is written, so a refused call (bad price, unknown status, unknown id) leaves the product as it was. Returns updated, the product as saved (the fields of wp_wc_get_product), saved_fields (the fields sent, as WooCommerce stored them, read again; saved_differs and warning where it stored something else; a change it did not store at all is an error, ab_mcp_not_saved) and undo: the token for wp_undo, which sets the regular price, sale price, stock and status of before again through WooCommerce, or null with undo_note saying why, also where the product held the values already. The undo never writes back a stock that moved after the change (an order, a refund), also not with force=true: without force it is then blocked, with force the other fields come back and the stock stays as it is. Needs manage_woocommerce and the right to edit this product (edit_product), which a site can withhold for single products, and the undo asks both again, with the right to publish products where it sets a status that takes the product live. Requires WooCommerce.',
				'open_world'  => true, // changes a product the shop shows, price and status included.
				'capability'  => $cap,
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'            => array( 'type' => 'integer', 'description' => 'Product id.' ),
						'regular_price' => array( 'type' => 'string', 'description' => 'Plain number of at least 0, written with "." or the shop\'s own decimal separator and nothing else (no grouping, no exponent); empty clears the price.' ),
						'sale_price'    => array( 'type' => 'string', 'description' => 'Same form as regular_price; empty clears the sale price.' ),
						'stock'         => array( 'type' => 'integer', 'description' => 'Stock quantity (enables stock management).' ),
						'status'        => array( 'type' => 'string', 'description' => 'publish, draft, pending, private or future.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_product' ),
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
			'wp_wc_create_coupon',
			array(
				'description' => 'WooCommerce: create a published coupon with a new, unused code, an amount, a discount type and an optional expiry date. Everything is checked before the coupon exists: a code already in use, an unknown discount type or an impossible date is refused. Returns created, id, the code as WooCommerce stores it (normally lower-cased), saved_fields (code, amount, discount_type and expires as stored, read again) and undo: the token for wp_undo, which moves the coupon into the trash again while it is unchanged and unused (no order has used it or holds it), and so frees its code, or null with undo_note saying why; on a site with the trash turned off (EMPTY_TRASH_DAYS 0) the trash would delete it for good, so there it is not undoable. A repeated call with the same code after the first has finished is refused, since the code is then in use; two calls that overlap (a retry while the first still runs) are not guarded against and can create two coupons. wp_wc_list_coupons lists the existing coupons; changing or deleting one is done in WooCommerce. Needs manage_woocommerce and the right to publish coupons (publish_shop_coupons); the undo asks for the right to delete this coupon (delete_shop_coupon). Requires WooCommerce.',
				'capability'  => $cap,
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'code', 'amount' ),
					'properties' => array(
						'code'          => array( 'type' => 'string', 'description' => 'Coupon code customers enter. WooCommerce normalises it (sanitised, lower-cased); a backslash is refused.' ),
						'amount'        => array( 'type' => 'string', 'description' => 'A number of at least 0: a percentage or an amount in shop currency, depending on discount_type. WooCommerce refuses a percentage over 100.' ),
						'discount_type' => array( 'type' => 'string', 'description' => 'percent, fixed_cart or fixed_product. Default percent.' ),
						'expires'       => array( 'type' => 'string', 'description' => 'Expiry date, YYYY-MM-DD. Omitted: the coupon does not expire.' ),
					),
				),
				'callback'    => array( __CLASS__, 'create_coupon' ),
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
	 * Update order status.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_order_status( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$order = wc_get_order( self::i( $a, 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Order not found. wp_wc_list_orders lists the orders.', 'alphabridge-mcp' ) );
		}
		// And the right to edit this one order, as WooCommerce's REST API asks
		// it (wc_rest_check_post_permissions( 'shop_order', 'edit', $id )):
		// edit_shop_order, which map_meta_cap hands on as edit_post, and under
		// HPOS WooCommerce answers edit_post for the order itself
		// (HposOrderCapabilityHelper). A site can withhold single orders from
		// an account that may manage the shop. The note goes onto this order
		// too, so the one check covers both.
		if ( ! current_user_can( self::edit_cap( $order->get_type() ), (int) $order->get_id() ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this specific order. Ask someone who may edit it, such as a shop manager, or an administrator.', 'alphabridge-mcp' ) );
		}
		$status = self::order_status_or_error( self::s( $a, 'status' ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$note = self::s( $a, 'note', '' );
		// The stored status, not the one woocommerce_order_get_status shows: two
		// statuses can look alike there, and a change would then read as none.
		$before = (string) ( method_exists( $order, 'get_status' ) ? $order->get_status( 'edit' ) : '' );
		// The note is not handed to update_status(): WooCommerce writes it only
		// when it sees a transition, and it decides that on the filtered status,
		// which no caller can predict. It is written here as an entry of its
		// own, once, whether the status changed or not — the same words either
		// way, next to whatever transition note WooCommerce writes itself.
		// WooCommerce answers false when the save failed or the order has no
		// id; reporting "updated" on that would be a plain untruth.
		if ( false === $order->update_status( $status, '', true ) ) {
			return new WP_Error( 'ab_mcp_not_saved', __( 'WooCommerce did not save this status change. Check the order with wp_wc_get_order, then try again.', 'alphabridge-mcp' ) );
		}
		$after = (string) $order->get_status( 'edit' );
		$noted = '' !== $note && method_exists( $order, 'add_order_note' );
		if ( $noted ) {
			$order->add_order_note( $note, 0, true );
		}
		// No undo point (since 4.14.0, decision of 30.10.2026): WooCommerce
		// reacted to the new status (stock, a refund, e-mails), and setting
		// the old one again would make it react once more, not take that
		// back. The answer says so, and what to do instead; where the status
		// was set already, only the note is new, and without one nothing.
		if ( $after !== $before ) {
			$why = AB_MCP_Undo_Objects::order_status_note( true );
		} elseif ( $noted ) {
			$why = AB_MCP_Undo_Objects::order_status_note( false );
		} else {
			$why = AB_MCP_Undo_Answer::nothing_changed( 'wp_wc_get_order' );
		}
		return AB_MCP_Undo_Answer::with(
			array(
				'updated'          => true,
				'id'               => $order->get_id(),
				'status'           => (string) $order->get_status(),
				'status_unchanged' => $after === $before,
			),
			$why
		);
	}

	/**
	 * The registered order status a caller means, or the error that names the
	 * shop's statuses. Looked up in wc_get_order_statuses(), with or without
	 * the wc- prefix. A key is only handed over when it survives the round
	 * trip WC_Order::set_status() makes (strip one wc- prefix, look up "wc-"
	 * plus the rest): WooCommerce strips exactly one prefix from it, and a
	 * status registered as "wc-wc-packed" must not turn into "packed".
	 *
	 * On the write path this matters most: WC_Order::set_status() does not
	 * refuse an unknown status, it silently replaces it with "pending", and
	 * "pending" is a status on which WooCommerce restores the order's stock
	 * (wc_maybe_increase_stock_levels). A typo such as "complete" turned a
	 * paid order into an unpaid one and put its items back on the shelf.
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

	/**
	 * Update product.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_product( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$p = wc_get_product( self::i( $a, 'id' ) );
		if ( ! $p ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Product not found. wp_wc_list_products lists the products.', 'alphabridge-mcp' ) );
		}
		// And the right to edit this one product, as WooCommerce's REST API
		// asks it: edit_product (edit_product for a variation too, whose type
		// borrows the product's capabilities), handed on as edit_post like
		// edit_shop_order in update_order_status().
		if ( ! current_user_can( self::edit_cap( get_post_type( (int) $p->get_id() ) ), (int) $p->get_id() ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this specific product. Ask someone who may edit it, such as a shop manager, or an administrator.', 'alphabridge-mcp' ) );
		}
		// Validate everything before the first setter: a refused request must
		// leave the product untouched, and wc_format_decimal() would turn a
		// non-numeric price into an empty one instead of complaining.
		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			if ( array_key_exists( $field, $a ) && ! self::price_ok( $a[ $field ] ) ) {
				return new WP_Error(
					'ab_mcp_bad_price',
					sprintf(
						/* translators: 1: field name, 2: the shop's decimal separator, 3: number of decimal places */
						__( '%1$s must be a plain number of at least 0, written with "." or this shop\'s decimal separator "%2$s" and nothing else (no grouping, no exponent), at most 15 digits before the separator and %3$d after it, or empty to clear it. Use a value such as 12.50.', 'alphabridge-mcp' ),
						$field,
						function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
						self::decimal_places()
					)
				);
			}
		}
		// The statuses the product screen offers, and only those. Asking
		// WordPress instead (get_post_stati) is too wide: WooCommerce registers
		// every ORDER status as a post status with public false, and internal
		// stays false for them, so wc-completed would count as a product
		// status and take the product out of the catalogue.
		if ( array_key_exists( 'status', $a ) ) {
			$status_given = self::s( $a, 'status' );
			$statuses     = array( 'publish', 'draft', 'pending', 'private', 'future' );
			if ( ! in_array( $status_given, $statuses, true ) ) {
				return new WP_Error(
					'ab_mcp_bad_product_status',
					sprintf(
						/* translators: %s: comma-separated list of statuses */
						__( 'Unknown product status. This site knows: %s. Choose one of these.', 'alphabridge-mcp' ),
						implode( ', ', $statuses )
					)
				);
			}
			// A status that takes the product live takes the right to publish
			// products, as wp-admin and the REST API ask it: the right to edit
			// a product lets an account keep it a draft, not publish it.
			if ( in_array( $status_given, array( 'publish', 'private', 'future' ), true ) && ! self::may_publish( 'product', 'publish_products' ) ) {
				return AB_MCP_Guidance::role_refusal( __( 'Your account cannot publish products, so this status was not set and nothing was changed. Leave status out to change the other fields, or ask someone who may publish products, such as a shop manager, or an administrator.', 'alphabridge-mcp' ) );
			}
		}
		$prices = array();
		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			if ( ! array_key_exists( $field, $a ) ) {
				continue;
			}
			$given = trim( self::s( $a, $field ) );
			if ( '' === $given ) {
				$prices[ $field ] = '';
				continue;
			}
			$number = self::shop_number( $given );
			if ( false === $number ) {
				// price_ok() answered the same question above; asking again
				// here means an empty price can never be written by accident.
				return new WP_Error( 'ab_mcp_bad_price', __( 'This price is not a plain number this shop writes. Use a value such as 12.50.', 'alphabridge-mcp' ) );
			}
			$prices[ $field ] = $number;
		}
		// What WooCommerce is asked to store, in the form its setters store
		// it, for the read-back (since 4.14.0).
		$asked = array();
		foreach ( $prices as $field => $value ) {
			$asked[ $field ] = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $value ) : $value;
		}
		if ( array_key_exists( 'stock', $a ) ) {
			$asked['stock'] = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( self::i( $a, 'stock' ) ) : self::i( $a, 'stock' );
		}
		if ( array_key_exists( 'status', $a ) ) {
			$asked['status'] = self::s( $a, 'status' );
		}
		// The state before, the setters and the save, the read-back and the
		// undo point (since 4.14.0).
		$res = AB_MCP_Undo_Products::update(
			$p,
			$asked,
			static function () use ( $p, $a, $prices ) {
				foreach ( $prices as $field => $value ) {
					if ( 'regular_price' === $field ) {
						$p->set_regular_price( $value );
					} else {
						$p->set_sale_price( $value );
					}
				}
				if ( array_key_exists( 'stock', $a ) ) {
					$p->set_manage_stock( true );
					$p->set_stock_quantity( self::i( $a, 'stock' ) );
				}
				if ( array_key_exists( 'status', $a ) ) {
					$p->set_status( self::s( $a, 'status' ) );
				}
				if ( (int) $p->save() < 1 ) {
					return new WP_Error( 'ab_mcp_not_saved', __( 'WooCommerce did not save this product. Check it with wp_wc_get_product, then try again.', 'alphabridge-mcp' ) );
				}
				return true;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge(
			array(
				'updated' => true,
				'product' => self::product_summary( wc_get_product( $p->get_id() ) ),
			),
			$res
		);
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

	/**
	 * May the account publish entries of this post type: the type's own
	 * publish capability (publish_products, publish_shop_coupons where
	 * WooCommerce maps them), the given name where the type is not registered.
	 * Public since 4.14.0: the undo of a product asks it again where it sets
	 * a status that takes the product live.
	 *
	 * @param string $type     Post type.
	 * @param string $fallback Capability when the type is unknown.
	 * @return bool
	 */
	public static function may_publish( $type, $fallback ) {
		$pto = get_post_type_object( $type );
		$cap = ( $pto && isset( $pto->cap->publish_posts ) ) ? (string) $pto->cap->publish_posts : (string) $fallback;
		return current_user_can( $cap );
	}

	/**
	 * Create coupon.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function create_coupon( $a ) {
		$g = self::wc_ready();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		// The coupon is published at once, so it takes the right to publish
		// coupons, as WooCommerce's REST API asks it for a new one
		// (wc_rest_check_post_permissions()): manage_woocommerce alone is not it.
		if ( ! self::may_publish( 'shop_coupon', 'publish_shop_coupons' ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot publish coupons, so no coupon was created. Ask someone who may publish coupons, such as a shop manager, or an administrator.', 'alphabridge-mcp' ) );
		}
		// Everything is checked before the coupon exists: an unknown discount
		// type used to throw inside WooCommerce (a generic "internal error" for
		// the caller), an unreadable expiry date was dropped without a word and
		// left the coupon valid forever, and a code already in use made
		// WooCommerce apply whichever coupon it found first.
		// Normalised the way WooCommerce stores it (wc_format_coupon_code: the
		// woocommerce_coupon_code filters — sanitising, lower-casing) BEFORE the
		// checks: "<b></b>" is not empty until then, and would have been saved
		// as a coupon with no code (Codex).
		// The code is judged as the coupon object stores it: set_code() runs
		// WooCommerce's own normalisation (entity decoding, sanitising,
		// lower-casing), and an entity-encoded "<b></b>" survives one pass of
		// it to come out empty on the object (Codex). So: set first, then read
		// back and check, before anything is saved.
		$c = new WC_Coupon();
		$c->set_code( trim( self::s( $a, 'code' ) ) );
		// The code as it will be stored: get_code() in the view context runs
		// the woocommerce_coupon_get_code filter (a display prefix, say), and a
		// taken or empty stored code must not hide behind it.
		$code = trim( (string) $c->get_code( 'edit' ) );
		if ( '' === $code ) {
			return new WP_Error( 'ab_mcp_bad_code', __( 'A coupon needs a code. Choose one that is not in use yet; wp_wc_list_coupons lists the codes taken.', 'alphabridge-mcp' ) );
		}
		// A code WooCommerce's own normalisation would change once more — an
		// entity-encoded tag, say — could never be looked up: the lookup
		// normalises what it is asked for, so it would search for something
		// else, and every duplicate check would pass it. Only a code that is
		// its own normalisation is accepted.
		if ( function_exists( 'wc_format_coupon_code' ) && wc_format_coupon_code( $code ) !== $code ) {
			return new WP_Error( 'ab_mcp_bad_code', __( 'This code would not survive WooCommerce\'s own normalisation a second time, so it could never be looked up; choose a plain code.', 'alphabridge-mcp' ) );
		}
		// And a backslash does not survive the save: WooCommerce hands the code
		// to wp_insert_post() as the post title, which unslashes it — sale\10
		// would be stored as sale10, a lone backslash as nothing — so the code
		// checked here would not be the code stored.
		if ( wp_unslash( $code ) !== $code ) {
			return new WP_Error( 'ab_mcp_bad_code', __( 'A coupon code cannot contain a backslash: WooCommerce would store it without, as a different code. Choose a code without one.', 'alphabridge-mcp' ) );
		}
		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'ab_mcp_duplicate', __( 'A coupon with this code already exists. Choose another code; wp_wc_list_coupons lists the codes.', 'alphabridge-mcp' ) );
		}
		$amount = self::shop_number( self::s( $a, 'amount' ) );
		if ( false === $amount ) {
			return new WP_Error(
				'ab_mcp_bad_amount',
				sprintf(
					/* translators: 1: the shop's decimal separator, 2: number of decimal places */
					__( 'The coupon amount must be a plain number of at least 0, written with "." or this shop\'s decimal separator "%1$s" and nothing else (no grouping, no exponent), at most 15 digits before the separator and %2$d after it. Use a value such as 10 or 12.50.', 'alphabridge-mcp' ),
					function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
					self::decimal_places()
				)
			);
		}
		// WooCommerce decides what a discount type is: set_discount_type()
		// knows its own compatibility aliases (percent_product is percent) and
		// throws for anything else. Asking it beats keeping a list here.
		// Not ?:, which would take "0" for nothing given.
		$type = self::s( $a, 'discount_type', 'percent' );
		$type = '' === trim( $type ) ? 'percent' : $type;
		try {
			$c->set_discount_type( $type );
		} catch ( Exception $e ) {
			$types = array_map( 'strval', array_keys( (array) wc_get_coupon_types() ) );
			return new WP_Error(
				'ab_mcp_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of discount types */
					__( 'Unknown discount type. This shop knows: %s. Choose one of these.', 'alphabridge-mcp' ),
					implode( ', ', $types )
				)
			);
		}
		$expires = trim( self::s( $a, 'expires', '' ) );
		if ( '' !== $expires && ! self::date_ok( $expires ) ) {
			return new WP_Error( 'ab_mcp_bad_date', __( 'The expiry date must be a real date in YYYY-MM-DD form. Use a date such as 2026-12-31; without expires the coupon does not expire.', 'alphabridge-mcp' ) );
		}
		// WooCommerce checks the amount against the type it was given (a
		// percentage over 100 is refused there); that answer belongs to the
		// caller as an input error, not as an internal one.
		try {
			$c->set_amount( $amount );
		} catch ( Exception $e ) {
			return new WP_Error( 'ab_mcp_bad_amount', $e->getMessage() );
		}
		if ( '' !== $expires ) {
			$c->set_date_expires( $expires );
		}
		$id = (int) $c->save();
		if ( $id < 1 ) {
			return new WP_Error( 'ab_mcp_not_saved', __( 'WooCommerce did not save this coupon. wp_wc_list_coupons shows whether it exists; if not, try again.', 'alphabridge-mcp' ) );
		}
		// What WooCommerce was asked to store, as its setters stored it on
		// the object, the read-back and an undo point that moves the coupon
		// into the trash again (since 4.14.0).
		$asked = array(
			'code'          => (string) $c->get_code( 'edit' ),
			'amount'        => (string) $c->get_amount( 'edit' ),
			'discount_type' => (string) $c->get_discount_type( 'edit' ),
			'expires'       => '' !== $expires ? $expires : null,
		);
		return array_merge(
			array(
				'created' => true,
				'id'      => $id,
				'code'    => $c->get_code( 'edit' ),
			),
			AB_MCP_Undo_Coupons::created( $id, $asked, sprintf( 'new coupon #%d', $id ) )
		);
	}

	/**
	 * A price a shop can use: empty (clears the field) or a number of at least 0.
	 *
	 * @param mixed $value Value as given.
	 * @return bool
	 */
	private static function price_ok( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return '' === trim( (string) $value ) || false !== self::shop_number( $value );
	}

	/**
	 * The value as a plain number, written the way this shop writes numbers.
	 *
	 * WooCommerce's own setters read a price through wc_format_decimal(),
	 * which reads it in the shop's writing: where the comma is the decimal
	 * separator, "12,50" is twelve and a half, and "12.50" is the same number
	 * written the way most callers write it — wc_format_decimal() reads both,
	 * because it keeps a lone point and drops what it does not know. Both are
	 * taken here and resolved to a point before the value reaches the setter.
	 * Refused is a value carrying two separators at once, which is a grouped
	 * number ("1.234,50"): which of them groups is the shop's business, and a
	 * tool that cannot tell should not guess. Refused too is a comma where the
	 * shop writes decimals with a point — "1,5" is fifteen to WooCommerce and
	 * one and a half to most callers — and everything wc_format_decimal()
	 * would quietly reshape: "1e2" becomes 12 there, and 309 nines become INF,
	 * which WooCommerce stores as 0.
	 *
	 * @param mixed $value Value as given.
	 * @return string|false The plain number, or false.
	 */
	private static function shop_number( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		$plain = trim( (string) $value );
		$dec   = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		// A single point is the decimal point in every shop and every locale —
		// wc_format_decimal() keeps it and drops everything it does not know.
		// The shop's own separator counts as well, but not both at once: a
		// value carrying two different separators is a grouped number, and
		// which of them groups is the shop's business, not the caller's.
		if ( '' !== $dec && '.' !== $dec && false !== strpos( $plain, $dec ) ) {
			if ( false !== strpos( $plain, '.' ) ) {
				return false;
			}
			$plain = str_replace( $dec, '.', $plain );
		}
		return self::plain_number( $plain ) ? $plain : false;
	}

	/**
	 * Digits with an optional decimal fraction, nothing else, and bounded:
	 * at most 15 digits before the point and as many after it as the shop is
	 * set to, never fewer than six. is_numeric() also
	 * accepts "1e2" and "1e999", which WooCommerce's decimal formatting turns
	 * into 12 and 1999, and 309 nines become INF, which WooCommerce stores
	 * as 0 (Codex); a price is never written in scientific notation.
	 *
	 * @param mixed $value Value as given.
	 * @return bool
	 */
	private static function plain_number( $value ) {
		$dp = self::decimal_places();
		// Digits with an optional fraction, a leading point (".5") or a trailing
		// one ("5.") — all three are numbers wc_format_decimal() reads, and all
		// three worked before this release.
		return is_scalar( $value ) && (bool) preg_match( '/^(\\d{1,15}(\\.\\d{0,' . $dp . '})?|\\.\\d{1,' . $dp . '})\\z/', trim( (string) $value ) );
	}

	/**
	 * How many decimal places a price may carry. The same number the refusals
	 * name, so what the message says is what the check does.
	 *
	 * @return int
	 */
	private static function decimal_places() {
		// Not the shop's display setting: wc_format_decimal() does not round a
		// price string to it, and the product screen takes more places than it
		// shows. This is only an upper bound against nonsense; what guards
		// against the values that reshape themselves is the limit before the
		// separator.
		return 30;
	}

	/**
	 * A real calendar date in YYYY-MM-DD form. Anchored with \\z, not $, so a
	 * trailing newline does not slip through.
	 *
	 * @param string $value Value as given.
	 * @return bool
	 */
	private static function date_ok( $value ) {
		return (bool) preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})\\z/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
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
