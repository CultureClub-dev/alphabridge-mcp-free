<?php
/**
 * Undo kind wc_product (since 4.14.0): the regular price, the sale price, the
 * stock and the status wp_wc_update_product changed, put back through
 * WooCommerce.
 *
 * Snapshot (one point, one product):
 *   { "v": 1, "id": int, "type": string, "parent": int,
 *     "before": { "<location>": value, ... },
 *     "after":  { "<location>": sha256, ... } }
 *
 * A location is one thing the tool writes: regular_price, sale_price and
 * status, each as text, and stock, which is whether WooCommerce manages the
 * stock, the quantity and the stock status ({ "manage": bool, "quantity":
 * text or null, "status": text }): the tool switches stock management on
 * with a quantity, and WooCommerce derives the stock status from the
 * quantity where it manages the stock. The point keeps only the locations
 * the call changed, so a later change of another field is no conflict.
 * Unlike the kinds of WordPress objects, the state is read through the
 * objects of WooCommerce — wc_get_product() and its getters in the edit
 * context — and not from the tables: WooCommerce decides where a product
 * keeps its data, and its data store keeps _price, the stock hooks and the
 * product lookup table in step when it saves. The undo writes the same way:
 * wc_get_product(), the setters with the old values, save(), and the product
 * read again.
 *
 * A location changed since is refused unless force is set, with one
 * exception (decision of 30.10.2026): a stock that moved since — an order, a
 * refund, a change by hand — is never written back, also not with force,
 * since that would overwrite the quantities sold. Without force the point is
 * then blocked (changed_since); with force the other locations come back,
 * the stock stays as it is, and the answer names it (kept). The type and the
 * parent of the product are kept to tell that the id is still the same
 * product (gone where not). The account needs the rights of the tool on the
 * product as it is now: the right to edit it (edit_product, for a variation
 * too) and, where a status of before takes the product live (publish,
 * private, future), the right to publish products. A product in the trash
 * gets no status back (in_trash): only WordPress' untrash would take it out
 * of the trash properly.
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
	const LOCS = array( 'regular_price', 'sale_price', 'stock', 'status' );

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
	 * @return array|false|null { type, parent, locs }
	 */
	public static function state_of( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( (int) $id );
		}
		$p = wc_get_product( (int) $id );
		return is_object( $p ) ? self::state( $p ) : false;
	}

	/**
	 * The state of a product object: its type and parent, and each location
	 * as its getters give it in the edit context, past the filters of the
	 * view context.
	 *
	 * @param WC_Product $p Product.
	 * @return array { type, parent, locs }
	 */
	public static function state( $p ) {
		return array(
			'type'   => self::text( $p->get_type() ),
			'parent' => (int) $p->get_parent_id( 'edit' ),
			'locs'   => array(
				'regular_price' => self::text( $p->get_regular_price( 'edit' ) ),
				'sale_price'    => self::text( $p->get_sale_price( 'edit' ) ),
				'stock'         => array(
					'manage'   => (bool) $p->get_manage_stock( 'edit' ),
					'quantity' => self::quantity( $p->get_stock_quantity( 'edit' ) ),
					'status'   => self::text( $p->get_stock_status( 'edit' ) ),
				),
				'status'        => self::text( $p->get_status( 'edit' ) ),
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
	 * A stock quantity in one spelling: null where there is none, else the
	 * number as text — 3 and 3.0 are the same quantity.
	 *
	 * @param mixed $value Quantity as a getter or a snapshot gives it.
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
			$out[ (string) $loc ] = $same ? self::hash( $state['locs'][ $loc ] ) : self::ABSENT;
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
		$done   = call_user_func( $write );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = self::state_of( $id );
		$read  = self::read_back( $after );

		$changed = array();
		foreach ( is_array( $after ) ? self::LOCS : array() as $loc ) {
			if ( self::hash( $after['locs'][ $loc ] ) !== self::hash( $before['locs'][ $loc ] ) ) {
				$changed[] = $loc;
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
		if ( is_array( $after ) && array() !== $changed && self::on() ) {
			$snap = array(
				'v'      => self::VERSION,
				'id'     => $id,
				'type'   => $before['type'],
				'parent' => $before['parent'],
				'before' => array(),
				'after'  => array(),
			);
			foreach ( $changed as $loc ) {
				$snap['before'][ $loc ] = $before['locs'][ $loc ];
				$snap['after'][ $loc ]  = self::hash( $after['locs'][ $loc ] );
			}
			if ( ! self::valid( self::TOOL, $snap ) ) {
				$snap = null;
				$odd  = true;
			}
		}
		if ( $odd || ( ! is_array( $after ) && self::on() ) ) {
			$outcome = AB_MCP_Undo_Answer::note( null );
		} else {
			$outcome = self::outcome( self::TOOL, self::READER, array() !== $changed, $snap, sprintf( 'product #%d: %s', $id, implode( ', ', $changed ) ) );
		}
		$res = AB_MCP_Undo_Answer::saved( array(), $asked, $read, self::READER );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The fields of a product as the tool takes them, from state_of():
	 * regular_price, sale_price, stock (the quantity as WooCommerce gives it)
	 * and status; empty where there is no product.
	 *
	 * @param array|false|null $state state_of().
	 * @return array
	 */
	private static function read_back( $state ) {
		if ( ! is_array( $state ) ) {
			return array();
		}
		$q = $state['locs']['stock']['quantity'];
		return array(
			'regular_price' => $state['locs']['regular_price'],
			'sale_price'    => $state['locs']['sale_price'],
			// WooCommerce counts stock in whole numbers unless a plugin says
			// otherwise (wc_stock_amount()).
			'stock'         => null === $q ? null : ( function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $q ) : 0 + $q ),
			'status'        => $state['locs']['status'],
		);
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * The plan of the kind, and where the stock moved since and force is
	 * set, which stock stays as it is (kept): the dry run says so too.
	 *
	 * @param string $tool  Tool of the point.
	 * @param mixed  $data  Snapshot.
	 * @param bool   $force Restore over later changes.
	 * @return array|WP_Error
	 */
	public static function plan( $tool, $data, $force ) {
		$out = parent::plan( $tool, $data, $force );
		if ( is_array( $out ) && array() !== $out['items'] ) {
			$cmp = self::compare( $data );
			if ( is_array( $cmp ) && 'changed' === ( $cmp['stock'] ?? '' ) ) {
				$out['kept'] = array( self::kept( (int) $data['id'] ) );
			}
		}
		return $out;
	}

	/**
	 * What the answer says of a stock left as it is.
	 *
	 * @param int $id Product id.
	 * @return array { ref, locs, reason, note }
	 */
	private static function kept( $id ) {
		return array(
			'ref'    => 'product:' . (int) $id,
			'locs'   => array( 'stock' ),
			'reason' => 'stock_moved',
			'note'   => sprintf(
				/* translators: %d: product id */
				__( 'The stock of product %d moved after the change (an order, a refund or a change by hand), and an undo never overwrites a stock that has moved: it stays as it is, and the other fields come back. Check the stock with wp_wc_get_product.', 'alphabridge-mcp' ),
				(int) $id
			),
		);
	}

	/**
	 * Restore one planned item: as AB_MCP_Undo_Objects::restore(), but a
	 * stock that moved since stays as it is, also with force.
	 *
	 * @param array $item Item of plan().
	 * @return true|array|WP_Error An array where the stock was kept: { kept: { ref, locs, reason, note } }.
	 */
	public static function restore( array $item ) {
		$snap = (array) $item['snap'];
		$cmp  = self::compare( $snap );
		if ( null === $cmp ) {
			return new WP_Error( 'ab_mcp_undo_write_failed', __( 'The state could not be read before the restore; nothing was restored. Try again in a moment; the undo point stays.', 'alphabridge-mcp' ) );
		}
		if ( empty( $item['force'] ) && in_array( 'changed', $cmp, true ) ) {
			return self::changed_error();
		}
		// The quantities sold since stay: force passes over the other
		// fields, never over the stock, which is then left as it is.
		$kept = isset( $cmp['stock'] ) && 'changed' === $cmp['stock'];
		if ( $kept ) {
			$cmp['stock'] = 'before';
		}
		$locs = array_keys( array_diff( $cmp, array( 'before' ) ) );
		if ( array() !== $locs ) {
			$done = self::put_back( $snap, array_map( 'strval', $locs ) );
			if ( true !== $done ) {
				return $done;
			}
		}
		return $kept ? array( 'kept' => self::kept( (int) $snap['id'] ) ) : true;
	}

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
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id = (int) $snap['id'];
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		$p     = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
		$saved = 0;
		if ( is_object( $p ) ) {
			foreach ( self::LOCS as $loc ) {
				if ( ! in_array( $loc, $locs, true ) ) {
					continue;
				}
				$old = $snap['before'][ $loc ];
				if ( 'regular_price' === $loc ) {
					$p->set_regular_price( $old );
				} elseif ( 'sale_price' === $loc ) {
					$p->set_sale_price( $old );
				} elseif ( 'status' === $loc ) {
					$p->set_status( $old );
				} else {
					$p->set_manage_stock( $old['manage'] );
					// '' clears the quantity: null would be read as 0 (wc_stock_amount()).
					$p->set_stock_quantity( null === $old['quantity'] ? '' : $old['quantity'] );
					$p->set_stock_status( $old['status'] );
				}
			}
			$saved = (int) $p->save();
		}
		$now = self::state_of( $id );
		$off = array();
		foreach ( $locs as $loc ) {
			if ( $saved < 1 || ! is_array( $now ) || self::hash( $now['locs'][ $loc ] ) !== self::hash( $snap['before'][ $loc ] ) ) {
				$off[] = $loc;
			}
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: 1: comma-separated field names, 2: product id */
					__( 'WooCommerce did not take back %1$s of product %2$d; the undo point stays. A plugin may rewrite the fields of a product on save. Check the product with wp_wc_get_product, and try again.', 'alphabridge-mcp' ),
					implode( ', ', $off ),
					$id
				)
			);
		}
		return true;
	}

	/**
	 * @return WP_Error
	 */
	protected static function changed_error() {
		return new WP_Error( 'ab_mcp_undo_product_changed', __( 'The product was changed after the undo was planned; nothing was restored. Pass force=true to restore it over the newer change; a stock that moved is left as it is even then.', 'alphabridge-mcp' ) );
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
	 * Is a value of before in the form the tool's undo writes back: a price
	 * as WooCommerce stores one (a plain decimal or nothing), a status the
	 * tool sets, a stock of its three parts?
	 *
	 * @param string $loc   Location.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function value_form( $loc, $value ) {
		if ( 'regular_price' === $loc || 'sale_price' === $loc ) {
			return is_string( $value ) && 1 === preg_match( '/\A(-?[0-9]{0,20}(\.[0-9]{0,30})?)?\z/', $value );
		}
		if ( 'status' === $loc ) {
			return in_array( $value, self::STATUSES, true );
		}
		if ( ! is_array( $value ) || array( 'manage', 'quantity', 'status' ) !== array_keys( $value ) || ! is_bool( $value['manage'] ) ) {
			return false;
		}
		if ( null !== $value['quantity'] && ( ! is_string( $value['quantity'] ) || 1 !== preg_match( '/\A-?[0-9]{1,20}(\.[0-9]{1,30})?\z/', $value['quantity'] ) ) ) {
			return false;
		}
		return is_string( $value['status'] ) && 1 === preg_match( '/\A[a-z0-9_-]{1,40}\z/', $value['status'] );
	}
}
