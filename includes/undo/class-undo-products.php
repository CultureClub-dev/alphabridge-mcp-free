<?php
/**
 * Undo kind wc_product (since 4.14.0): the prices, the stock and the status
 * wp_wc_update_product changed, put back through WooCommerce.
 *
 * Snapshot (one point, one product):
 *   { "v": 1, "id": int, "type": string, "parent": int,
 *     "before": { "<location>": value, ... },
 *     "after":  { "<location>": sha256, ... } }
 *
 * Three locations, each kept only where the call changed it:
 *  - price: the regular price, the sale price, the sale period
 *    (date_on_sale_from, date_on_sale_to, as timestamps or null) and the
 *    price WooCommerce derives from them, as one: WooCommerce drops a sale
 *    price that is not below the regular one and derives the price from all
 *    of them, so a change of any since is a change of the price.
 *  - status: the post status.
 *  - stock: what the call booked, not a state ({ "delta": the quantity it
 *    set less the quantity right before its save, both read in the call
 *    itself, "manage": whether WooCommerce managed the stock before,
 *    "status": the stock status before }). The undo books the delta back
 *    relative to the stock as it is then, through wc_update_product_stock(),
 *    which WooCommerce runs as one update of the database: whatever was sold
 *    or refunded since stays, so the stock has no conflict and needs no
 *    force (decision of 30.10.2026, review of 31.10.2026). Where the call
 *    switched stock management on, the undo switches it off again and sets
 *    the stock status of before, which WooCommerce does not derive for a
 *    stock it does not manage; the quantity, which WooCommerce drops with
 *    the management, is then not booked. A stock no longer managed since is
 *    refused (stock_unmanaged): a booking would not be made.
 *
 * The state is read through the objects of WooCommerce — wc_get_product()
 * and its getters in the edit context — not from the tables: WooCommerce
 * decides where a product keeps its data, and its data store keeps _price,
 * the stock hooks and the product lookup table in step when it saves. The
 * state "after" is the product object as the tool saved it, not a read
 * again afterwards, so that a change of another request in between is not
 * taken for the tool's. The undo writes the same way: wc_get_product(), the
 * setters with the old values, save(), the product read again and compared
 * in full; the stock is booked last, so a retry after a failure never books
 * twice.
 *
 * A price or a status changed since is refused unless force is set. The type
 * and the parent of the product are kept to tell that the id is still the
 * same product (gone where not). The account needs the rights of the tool on
 * the product as it is now: the right to edit it (edit_product, for a
 * variation too) and, where a status of before takes the product live
 * (publish, private, future), the right to publish products. A product in
 * the trash gets no status back (in_trash): only WordPress' untrash would
 * take it out of the trash properly.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Products
 */
class AB_MCP_Undo_Products extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'wc_product';

	/** The tool that records this kind. */
	const TOOL = 'wp_wc_update_product';

	/** The reader of a product, named in the answers. */
	const READER = 'wp_wc_get_product';

	/** The locations, in the order they are kept and written. */
	const LOCS = array( 'price', 'stock', 'status' );

	/** The fields of the location price, in the order they are hashed. */
	const PRICE = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'price' );

	/** The statuses wp_wc_update_product sets, and so the only ones its undo sets again. */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/** The statuses that take a product live, as the tool asks the right to publish for them. */
	const LIVE = array( 'publish', 'private', 'future' );

	/* ------------------------------------------------------------ the state */

	/**
	 * The product as WooCommerce holds it, read again past the caches; false
	 * when there is no such product, null when WooCommerce is not there to
	 * ask.
	 *
	 * @param int $id Product id.
	 * @return array|false|null state()
	 */
	public static function state_of( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$p = self::load( $id );
		return is_object( $p ) ? self::state( $p ) : false;
	}

	/**
	 * The product object for an id, read again past the caches.
	 *
	 * @param int $id Product id.
	 * @return WC_Product|false
	 */
	private static function load( $id ) {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( (int) $id );
		}
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : false;
		return is_object( $p ) ? $p : false;
	}

	/**
	 * The state of a product object as its getters give it in the edit
	 * context, past the filters of the view context: its type and parent,
	 * the locations price and status, and its stock.
	 *
	 * @param WC_Product $p Product.
	 * @return array { type, parent, locs: { price, status }, stock: { manage, managing, quantity, status } }
	 */
	public static function state( $p ) {
		$manage = (bool) $p->get_manage_stock( 'edit' );
		return array(
			'type'   => self::text( $p->get_type() ),
			'parent' => (int) $p->get_parent_id( 'edit' ),
			'locs'   => array(
				'price'  => array(
					'regular_price'     => self::text( $p->get_regular_price( 'edit' ) ),
					'sale_price'        => self::text( $p->get_sale_price( 'edit' ) ),
					'date_on_sale_from' => self::stamp( $p->get_date_on_sale_from( 'edit' ) ),
					'date_on_sale_to'   => self::stamp( $p->get_date_on_sale_to( 'edit' ) ),
					'price'             => self::text( $p->get_price( 'edit' ) ),
				),
				'status' => self::text( $p->get_status( 'edit' ) ),
			),
			'stock'  => array(
				'manage'   => $manage,
				// Whether WooCommerce books stock for it at all: the
				// product's own setting and the shop's (managing_stock()).
				'managing' => method_exists( $p, 'managing_stock' ) ? (bool) $p->managing_stock() : $manage,
				'quantity' => self::quantity( $p->get_stock_quantity( 'edit' ) ),
				'status'   => self::text( $p->get_stock_status( 'edit' ) ),
			),
		);
	}

	/**
	 * A value as text; anything that is no scalar as nothing.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A date of WooCommerce (WC_DateTime) as its timestamp; null where there
	 * is none.
	 *
	 * @param mixed $date Date as a getter gives it.
	 * @return int|null
	 */
	private static function stamp( $date ) {
		return is_object( $date ) && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : null;
	}

	/**
	 * A stock quantity in one spelling: null where there is none, else the
	 * number as text — 3 and 3.0 are the same quantity.
	 *
	 * @param mixed $value Quantity.
	 * @return string|null
	 */
	private static function quantity( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return is_numeric( $value ) ? (string) ( 0 + $value ) : self::text( $value );
	}

	/**
	 * Is it the product of the point: the same type under the same parent?
	 *
	 * @param array $state state().
	 * @param array $snap  Snapshot.
	 * @return bool
	 */
	private static function same_product( array $state, array $snap ) {
		return $state['type'] === (string) $snap['type'] && $state['parent'] === (int) $snap['parent'];
	}

	/**
	 * The hash "after" of a booking of the stock: a booking is undone once,
	 * whatever the stock is now, so its location reads as after while the
	 * product is there (now_hashes()).
	 *
	 * @param array $record The booking ({ delta, manage, status }).
	 * @return string
	 */
	private static function booked( array $record ) {
		return self::hash( array( 'booked' => $record ) );
	}

	/* --------------------------------------------------------------- hashes */

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function now_hashes( array $snap ) {
		$state = self::state_of( (int) $snap['id'] );
		if ( null === $state ) {
			return null;
		}
		$same = is_array( $state ) && self::same_product( $state, $snap );
		$out  = array();
		foreach ( array_keys( (array) $snap['before'] ) as $loc ) {
			if ( ! $same ) {
				$out[ (string) $loc ] = self::ABSENT;
			} elseif ( 'stock' === $loc ) {
				// No conflict: the booking goes back relative to the stock
				// as it is then (refusal() asks whether it can).
				$out['stock'] = (string) $snap['after']['stock'];
			} else {
				$out[ (string) $loc ] = self::hash( $state['locs'][ $loc ] );
			}
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		$out = array();
		foreach ( (array) $snap['before'] as $loc => $value ) {
			$out[ (string) $loc ] = self::hash( $value );
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'product:' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * Run the writes of wp_wc_update_product and answer for them: the state
	 * before, the setters and save(), the read-back, the undo point. As
	 * AB_MCP_Undo_Terms::update(): a save that changed nothing while the
	 * product does not hold what was asked is an error; one that changed
	 * nothing because the product held it already is no error, and gets no
	 * point.
	 *
	 * @param WC_Product $p     The product as loaded, before any setter.
	 * @param array      $asked field => value as WooCommerce was asked to store it: regular_price, sale_price, stock, status.
	 * @param callable   $write Calls the setters and save(); a WP_Error is passed on as it is.
	 * @return array|WP_Error { saved_fields, saved_differs?, warning?, undo, undo_note? }
	 */
	public static function update( $p, array $asked, callable $write ) {
		$id     = (int) $p->get_id();
		$before = self::state( $p );
		// The stock right before the save, read again in this call: what the
		// call books is the quantity it sets less this one.
		$was = $before['stock'];
		if ( array_key_exists( 'stock', $asked ) ) {
			$fresh = self::state_of( $id );
			$was   = is_array( $fresh ) ? $fresh['stock'] : $was;
		}
		$done = call_user_func( $write );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		// The product as the tool saved it — the object, not a read again,
		// which could already hold a change of another request — and the
		// read-back for saved_fields.
		$set  = self::state( $p );
		$read = self::read_back( self::state_of( $id ) );

		$changed = array();
		foreach ( self::LOCS as $loc ) {
			if ( 'stock' === $loc ) {
				$booked = self::booking( $asked, $was, $set['stock'] );
				if ( null !== $booked ) {
					$changed['stock'] = $booked;
				}
			} elseif ( self::hash( $set['locs'][ $loc ] ) !== self::hash( $before['locs'][ $loc ] ) ) {
				$changed[ $loc ] = $before['locs'][ $loc ];
			}
		}
		if ( array() === $changed ) {
			$refused = array();
			foreach ( $asked as $field => $want ) {
				if ( ! array_key_exists( $field, $read ) || ! AB_MCP_Undo_Answer::same( $read[ $field ], $want ) ) {
					$refused[] = (string) $field;
				}
			}
			if ( array() !== $refused ) {
				return new WP_Error(
					'ab_mcp_not_saved',
					sprintf(
						/* translators: 1: comma-separated field names, 2: tool name */
						__( 'WooCommerce did not store the change: %1$s of the product still hold what they held before. A plugin may refuse it on save. Check the product with %2$s, and ask the site administrator which plugin guards it.', 'alphabridge-mcp' ),
						implode( ', ', $refused ),
						self::READER
					)
				);
			}
		}

		// The point keeps only what the call changed. A state of before the
		// undo would not write back (a status the tool does not set, a price
		// in a form WooCommerce does not store) gets no point at all: one
		// that put back only part of the change would mislead.
		$snap = null;
		$odd  = false;
		if ( array() !== $changed && self::on() ) {
			$snap = array(
				'v'      => self::VERSION,
				'id'     => $id,
				'type'   => $before['type'],
				'parent' => $before['parent'],
				'before' => $changed,
				'after'  => array(),
			);
			foreach ( $changed as $loc => $value ) {
				$snap['after'][ $loc ] = 'stock' === $loc ? self::booked( $value ) : self::hash( $set['locs'][ $loc ] );
			}
			if ( ! self::valid( self::TOOL, $snap ) ) {
				$snap = null;
				$odd  = true;
			}
		}
		$outcome = $odd ? AB_MCP_Undo_Answer::note( null ) : self::outcome( self::TOOL, self::READER, array() !== $changed, $snap, sprintf( 'product #%d: %s', $id, implode( ', ', array_keys( $changed ) ) ) );
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, $read, self::READER );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * What the call booked on the stock, or null where it booked nothing: the
	 * quantity it saved less the quantity right before (none counts as 0, as
	 * WooCommerce keeps none for a stock it does not manage), and whether it
	 * switched stock management on.
	 *
	 * @param array $asked The fields asked for.
	 * @param array $was   The stock right before the save.
	 * @param array $set   The stock as the tool saved it.
	 * @return array|null { delta, manage, status }
	 */
	private static function booking( array $asked, array $was, array $set ) {
		if ( ! array_key_exists( 'stock', $asked ) || ! $set['manage'] ) {
			return null;
		}
		$from  = $was['manage'] && null !== $was['quantity'] ? 0 + $was['quantity'] : 0;
		$to    = null !== $set['quantity'] ? 0 + $set['quantity'] : 0;
		$delta = self::quantity( $to - $from );
		if ( '0' === $delta && $was['manage'] ) {
			return null;
		}
		return array(
			'delta'  => $delta,
			'manage' => (bool) $was['manage'],
			'status' => $was['status'],
		);
	}

	/**
	 * The fields of a product as the tool takes them: regular_price,
	 * sale_price, stock (the quantity as WooCommerce gives it) and status;
	 * empty where there is no product.
	 *
	 * @param array|false|null $state state_of().
	 * @return array
	 */
	private static function read_back( $state ) {
		if ( ! is_array( $state ) ) {
			return array();
		}
		$q = $state['stock']['quantity'];
		return array(
			'regular_price' => $state['locs']['price']['regular_price'],
			'sale_price'    => $state['locs']['price']['sale_price'],
			// WooCommerce counts stock in whole numbers unless a plugin says
			// otherwise (wc_stock_amount()).
			'stock'         => null === $q ? null : ( function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $q ) : 0 + $q ),
			'status'        => $state['locs']['status'],
		);
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$id    = (int) $snap['id'];
		$state = self::state_of( $id );
		if ( ! is_array( $state ) || ! self::same_product( $state, $snap ) ) {
			return 'gone';
		}
		$status = isset( $snap['before']['status'] ) ? (string) $snap['before']['status'] : '';
		if ( '' !== $status && 'trash' === $state['locs']['status'] ) {
			return 'in_trash';
		}
		if ( isset( $snap['before']['stock'] ) && ! self::bookable( $snap['before']['stock'], $state['stock'] ) ) {
			return 'stock_unmanaged';
		}
		// The rights of the tool, asked as the tool asks them (not copied).
		self::load_tools( 'AB_MCP_Tools_Shop', 'class-tools-shop.php' );
		if ( ! current_user_can( AB_MCP_Tools_Shop::edit_cap( get_post_type( $id ) ), $id ) ) {
			return 'forbidden';
		}
		if ( in_array( $status, self::LIVE, true ) && ! AB_MCP_Tools_Shop::may_publish( 'product', 'publish_products' ) ) {
			return 'forbidden';
		}
		return '';
	}

	/**
	 * Can the booking of the point go back on the stock as it is now? The
	 * product must still manage its own stock; for a quantity to book, also
	 * as the shop books stock at all (wc_update_product_stock() books
	 * nothing otherwise, and a variation that no longer manages its own
	 * would book its parent's).
	 *
	 * @param array $record The booking of the point.
	 * @param array $stock  The stock now (state()).
	 * @return bool
	 */
	private static function bookable( array $record, array $stock ) {
		if ( ! $stock['manage'] ) {
			return false;
		}
		return ! $record['manage'] || '0' === $record['delta'] || $stock['managing'];
	}

	/**
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id    = (int) $snap['id'];
		$p     = self::load( $id );
		$stock = in_array( 'stock', $locs, true ) ? $snap['before']['stock'] : null;
		if ( ! is_object( $p ) ) {
			return self::not_back( $locs, $id );
		}
		$write = false;
		if ( in_array( 'price', $locs, true ) ) {
			$old = $snap['before']['price'];
			$p->set_regular_price( $old['regular_price'] );
			$p->set_sale_price( $old['sale_price'] );
			foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $field ) {
				$getter = 'get_' . $field;
				if ( self::stamp( $p->$getter( 'edit' ) ) !== $old[ $field ] ) {
					$setter = 'set_' . $field;
					$p->$setter( $old[ $field ] );
				}
			}
			$write = true;
		}
		if ( in_array( 'status', $locs, true ) ) {
			$p->set_status( $snap['before']['status'] );
			$write = true;
		}
		if ( null !== $stock && ! $stock['manage'] ) {
			// Stock management back off, and the stock status of before,
			// which WooCommerce does not derive for a stock it does not
			// manage; the quantity goes with the management.
			$p->set_manage_stock( false );
			$p->set_stock_status( $stock['status'] );
			$write = true;
		}
		$saved = $write ? (int) $p->save() : 1;

		// Read again and compared in full before the stock is booked: a
		// failure here leaves the booking for the retry.
		$now = self::state_of( $id );
		$off = array();
		foreach ( $locs as $loc ) {
			if ( 'stock' === $loc ) {
				$back = null === $stock || $stock['manage'] || ( is_array( $now ) && false === $now['stock']['manage'] && $now['stock']['status'] === $stock['status'] );
			} else {
				$back = is_array( $now ) && self::hash( $now['locs'][ $loc ] ) === self::hash( $snap['before'][ $loc ] );
			}
			if ( $saved < 1 || ! $back ) {
				$off[] = $loc;
			}
		}
		if ( array() !== $off ) {
			return self::not_back( $off, $id );
		}

		// The stock last, and relative: what the call booked goes back as one
		// update of the database, and whatever was sold since stays.
		if ( null !== $stock && $stock['manage'] && '0' !== $stock['delta'] ) {
			$p = self::load( $id );
			if ( ! is_object( $p ) || ! is_array( $now ) || ! self::bookable( $stock, $now['stock'] ) || ! function_exists( 'wc_update_product_stock' ) ) {
				return self::not_back( array( 'stock' ), $id );
			}
			$n    = 0 + $stock['delta'];
			$done = wc_update_product_stock( $p, abs( $n ), $n > 0 ? 'decrease' : 'increase' );
			if ( false === $done ) {
				return self::not_back( array( 'stock' ), $id );
			}
		}
		return true;
	}

	/**
	 * The answer where WooCommerce did not take back what the undo wrote.
	 *
	 * @param string[] $locs Locations not taken back.
	 * @param int      $id   Product id.
	 * @return WP_Error
	 */
	private static function not_back( array $locs, $id ) {
		return new WP_Error(
			'ab_mcp_undo_write_failed',
			sprintf(
				/* translators: 1: comma-separated field names, 2: product id */
				__( 'WooCommerce did not take back %1$s of product %2$d; the undo point stays. A plugin may rewrite the fields of a product on save. Check the product with wp_wc_get_product, and try again.', 'alphabridge-mcp' ),
				implode( ', ', $locs ),
				(int) $id
			)
		);
	}

	/**
	 * @return WP_Error
	 */
	protected static function changed_error() {
		return new WP_Error( 'ab_mcp_undo_product_changed', __( 'The product was changed after the undo was planned; nothing was restored. Pass force=true to restore it over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the product is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------------------- the form */

	/**
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( self::TOOL !== $tool || ! is_array( $data ) ) {
			return false;
		}
		$keys = array_keys( $data );
		sort( $keys );
		if ( array( 'after', 'before', 'id', 'parent', 'type', 'v' ) !== $keys || self::VERSION !== $data['v'] ) {
			return false;
		}
		if ( ! is_int( $data['id'] ) || $data['id'] <= 0 || ! is_int( $data['parent'] ) || $data['parent'] < 0 ) {
			return false;
		}
		if ( ! is_string( $data['type'] ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $data['type'] ) ) {
			return false;
		}
		if ( ! is_array( $data['before'] ) || array() === $data['before'] || ! is_array( $data['after'] ) ) {
			return false;
		}
		// Each location once, in the order of LOCS, the same in after.
		$locs = array_map( 'strval', array_keys( $data['before'] ) );
		if ( array_values( array_intersect( self::LOCS, $locs ) ) !== $locs || array_map( 'strval', array_keys( $data['after'] ) ) !== $locs ) {
			return false;
		}
		foreach ( $data['before'] as $loc => $value ) {
			if ( ! self::value_form( (string) $loc, $value ) ) {
				return false;
			}
		}
		foreach ( $data['after'] as $sha ) {
			if ( ! is_string( $sha ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is a value of before in the form the tool's undo writes back: prices as
	 * WooCommerce stores them (a plain decimal or nothing) with the sale
	 * period as timestamps, a status the tool sets, a booking of the stock?
	 *
	 * @param string $loc   Location.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function value_form( $loc, $value ) {
		if ( 'status' === $loc ) {
			return in_array( $value, self::STATUSES, true );
		}
		if ( 'price' === $loc ) {
			if ( ! is_array( $value ) || self::PRICE !== array_keys( $value ) ) {
				return false;
			}
			foreach ( array( 'regular_price', 'sale_price', 'price' ) as $field ) {
				if ( ! is_string( $value[ $field ] ) || 1 !== preg_match( '/\A(-?[0-9]{0,20}(\.[0-9]{0,30})?)?\z/', $value[ $field ] ) ) {
					return false;
				}
			}
			foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $field ) {
				if ( null !== $value[ $field ] && ( ! is_int( $value[ $field ] ) || $value[ $field ] < 0 ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! is_array( $value ) || array( 'delta', 'manage', 'status' ) !== array_keys( $value ) || ! is_bool( $value['manage'] ) ) {
			return false;
		}
		if ( ! is_string( $value['delta'] ) || 1 !== preg_match( '/\A-?[0-9]{1,20}(\.[0-9]{1,30})?\z/', $value['delta'] ) ) {
			return false;
		}
		return is_string( $value['status'] ) && 1 === preg_match( '/\A[a-z0-9_-]{1,40}\z/', $value['status'] );
	}
}
