<?php
/**
 * Undo kind wc_coupon (since 4.14.0): a coupon wp_wc_create_coupon created,
 * moved into the trash again.
 *
 * Snapshot (one point, one coupon):
 *   { "v": 1, "id": int, "after": { "coupon": sha256, "use": sha256 } }
 *
 * "coupon" is the coupon as the change left it: all its data as WooCommerce
 * reads it (WC_Coupon::get_data(): code, status, amount, discount type,
 * expiry, the restrictions — individual use, minimum and maximum amount,
 * products and categories, e-mail addresses, limits — and its other meta),
 * without the id and the times it was created and changed, which a change
 * of its meta alone does not touch; "use" its use — the usage count, the
 * customers it was used by, and the orders that hold it while they are paid
 * (WooCommerce's tentative usage). Both are read through WC_Coupon, as the
 * product kind reads a product (AB_MCP_Undo_Products). The undo moves the coupon into the
 * trash through WooCommerce (WC_Coupon::delete() without force, as the trash
 * link of wp-admin does), not for good (decision of 30.10.2026): that is the
 * gentler way, and it frees the code again, since WooCommerce finds only
 * published coupons by their code. It does so only while both are as the
 * change left them, and refuses a coupon changed or used since unless force
 * is set; a coupon deleted or trashed since is nothing to do. Where the
 * site's trash is off (EMPTY_TRASH_DAYS 0) the trash would delete for good:
 * the coupon gets no point then (trash_off_note()), and a point of before is
 * refused (no_trash). The account needs manage_woocommerce and the right to
 * delete this coupon (delete_shop_coupon, the delete_post of its type), as
 * WooCommerce asks it; force passes over later changes, never over a right.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Coupons
 */
class AB_MCP_Undo_Coupons extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'wc_coupon';

	/** The tool that records this kind. */
	const TOOL = 'wp_wc_create_coupon';

	/** The reader of the coupons, named in the answers. */
	const READER = 'wp_wc_list_coupons';

	/* ------------------------------------------------------------ the state */

	/**
	 * The coupon as WooCommerce holds it, read again past the caches: the
	 * coupon and its use; false when there is no such coupon or it is in
	 * the trash, null when WooCommerce is not there to ask.
	 *
	 * @param int $id Coupon id.
	 * @return array|false|null { coupon, use }
	 */
	public static function state_of( $id ) {
		$c = self::load( $id );
		if ( null === $c || false === $c ) {
			return $c;
		}
		if ( 'trash' === (string) $c->get_status( 'edit' ) ) {
			return false;
		}
		// Every field, any one of which can change what the coupon does; not
		// the id, and no time: a change of meta alone (through the REST API,
		// say) leaves the time of the last change as it was.
		$data = (array) $c->get_data();
		$use  = array(
			'count'   => (int) ( $data['usage_count'] ?? 0 ),
			'used_by' => array(),
			'held'    => self::held( $c, (int) $id ),
		);
		foreach ( (array) ( $data['used_by'] ?? array() ) as $who ) {
			$use['used_by'][] = is_scalar( $who ) ? (string) $who : '';
		}
		unset( $data['id'], $data['date_created'], $data['date_modified'], $data['usage_count'], $data['used_by'] );
		return array(
			'coupon' => self::plain_data( $data ),
			'use'    => $use,
		);
	}

	/**
	 * The data of a coupon in a form the hash takes the same each time: a
	 * date as its timestamp, a meta row as its key and value (its id is the
	 * row's, not the coupon's), any other object as its public fields.
	 *
	 * @param mixed $value Value of get_data().
	 * @return mixed
	 */
	private static function plain_data( $value ) {
		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'getTimestamp' ) ) {
				return (int) $value->getTimestamp();
			}
			if ( method_exists( $value, 'get_data' ) ) {
				$meta = (array) $value->get_data();
				return array( $meta['key'] ?? null, self::plain_data( $meta['value'] ?? null ) );
			}
			return self::plain_data( get_object_vars( $value ) );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::plain_data( $v );
			}
		}
		return $value;
	}

	/**
	 * The coupon object for an id, read again past the caches; false when
	 * there is no coupon under it, null when WooCommerce is not there.
	 *
	 * @param int $id Coupon id.
	 * @return WC_Coupon|false|null
	 */
	private static function load( $id ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		$id = (int) $id;
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'get_post_type' ) && 'shop_coupon' !== get_post_type( $id ) ) {
			return false;
		}
		try {
			$c = new WC_Coupon( $id );
		} catch ( Exception $e ) {
			// Deleted between the two reads: the data store throws.
			return false;
		}
		return (int) $c->get_id() === $id ? $c : false;
	}

	/**
	 * The orders that hold a coupon while they are paid, as WooCommerce counts
	 * them; null where its data store does not say.
	 *
	 * @param WC_Coupon $c  Coupon.
	 * @param int       $id Its id.
	 * @return int|null
	 */
	private static function held( $c, $id ) {
		$store = method_exists( $c, 'get_data_store' ) ? $c->get_data_store() : null;
		if ( ! is_object( $store ) ) {
			return null;
		}
		// WC_Data_Store hands every call on to its store (__call), so it
		// says itself which ones that store has.
		$has = method_exists( $store, 'has_callable' ) ? $store->has_callable( 'get_tentative_usage_count' ) : method_exists( $store, 'get_tentative_usage_count' );
		if ( ! $has ) {
			return null;
		}
		$n = $store->get_tentative_usage_count( $id );
		return null === $n ? null : (int) $n;
	}

	/**
	 * The coupon as wp_wc_list_coupons shows it: code, amount, discount_type
	 * and expires (YYYY-MM-DD or null); empty when it is not there.
	 *
	 * @param int $id Coupon id.
	 * @return array
	 */
	public static function read_back( $id ) {
		$c = self::load( $id );
		if ( ! is_object( $c ) ) {
			return array();
		}
		$expires = $c->get_date_expires( 'edit' );
		return array(
			'code'          => (string) $c->get_code( 'edit' ),
			'amount'        => (string) $c->get_amount( 'edit' ),
			'discount_type' => (string) $c->get_discount_type( 'edit' ),
			'expires'       => is_object( $expires ) ? (string) $expires->date( 'Y-m-d' ) : null,
		);
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
		if ( false === $state ) {
			return array(
				'coupon' => self::ABSENT,
				'use'    => self::ABSENT,
			);
		}
		return array(
			'coupon' => self::hash( $state['coupon'] ),
			'use'    => self::hash( $state['use'] ),
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		return array(
			'coupon' => self::ABSENT,
			'use'    => self::ABSENT,
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'coupon:' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * The undo and read-back part of the answer of wp_wc_create_coupon: the
	 * point moves the new coupon into the trash again.
	 *
	 * @param int    $id      The new coupon.
	 * @param array  $asked   field => value as WooCommerce was asked to store it: code, amount, discount_type, expires.
	 * @param string $summary Summary for wp_undo_list.
	 * @return array { saved_fields, saved_differs?, warning?, undo, undo_note? }
	 */
	public static function created( $id, array $asked, $summary ) {
		$snap = null;
		if ( self::on() && self::trash_on() ) {
			$data  = array(
				'v'  => self::VERSION,
				'id' => (int) $id,
			);
			$after = self::now_hashes( $data );
			if ( null !== $after && self::ABSENT !== $after['coupon'] ) {
				$data['after'] = $after;
				$snap          = $data;
			}
		}
		// Where the trash is off, the undo would delete the coupon for good.
		$outcome = ( self::on() && ! self::trash_on() ) ? self::trash_off_note() : self::outcome( self::TOOL, self::READER, true, $snap, $summary );
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, self::read_back( $id ), self::READER );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The undo_note of a coupon created on a site whose trash is off.
	 *
	 * @return string
	 */
	public static function trash_off_note() {
		return AB_MCP_Undo_Answer::not_undoable( __( 'The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), and the undo of a new coupon moves it into the trash, which here would delete it for good; so there is no undo point. wp_wc_list_coupons lists the coupon; a coupon that is not wanted is taken out of use under Marketing › Coupons in wp-admin.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$id = (int) $snap['id'];
		if ( ! is_array( self::state_of( $id ) ) ) {
			return 'gone';
		}
		// Into the trash, never for good: with the trash off it would be.
		if ( ! self::trash_on() ) {
			return 'no_trash';
		}
		// The right of the type, asked as the tools ask it (not copied).
		self::load_tools( 'AB_MCP_Tools_Shop', 'class-tools-shop.php' );
		return current_user_can( AB_MCP_Tools_Shop::delete_cap( 'shop_coupon' ), $id ) ? '' : 'forbidden';
	}

	/**
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id = (int) $snap['id'];
		$c  = self::trash_on() ? self::load( $id ) : null;
		if ( is_object( $c ) ) {
			$c->delete( false );
		}
		if ( false !== self::state_of( $id ) ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: %d: coupon id */
					__( 'WooCommerce did not move coupon %d into the trash; the undo point stays. A plugin may keep it. Check the coupons with wp_wc_list_coupons, and try again.', 'alphabridge-mcp' ),
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
		return new WP_Error( 'ab_mcp_undo_coupon_changed', __( 'The coupon was changed or used after the undo was planned; nothing was restored. Pass force=true to move it into the trash over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the coupon is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
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
		if ( array( 'after', 'id', 'v' ) !== $keys || self::VERSION !== $data['v'] || ! is_int( $data['id'] ) || $data['id'] <= 0 || ! is_array( $data['after'] ) ) {
			return false;
		}
		$locs = array_keys( $data['after'] );
		sort( $locs );
		if ( array( 'coupon', 'use' ) !== $locs ) {
			return false;
		}
		foreach ( $data['after'] as $sha ) {
			if ( ! is_string( $sha ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) {
				return false;
			}
		}
		return true;
	}
}
