<?php
/**
 * Undo kind menu_item (since 4.13.0): a menu item wp_add_menu_item added,
 * deleted again.
 *
 * Snapshot (one point, one item):
 *   { "v": 1, "id": int, "after": { "item": sha256, "use": sha256 } }
 * "item" is the item as the change left it — its row in the posts table,
 * every meta row of it (target, address, parent item, classes), the menu it
 * is in; "use" the items that name it as their parent. The undo deletes the
 * item only while both are as the change left them, and refuses an item
 * changed, moved or given sub-items since unless force is set; an item
 * deleted since is nothing to do. The id must still be a menu item in the
 * posts table, and the account must have the right to delete it
 * (delete_post), as wp_delete_menu_item asks: a hand-edited point naming a
 * post reaches nothing.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Menu_Items
 */
class AB_MCP_Undo_Menu_Items extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'menu_item';

	/** The columns of the item's row that count. */
	const COLUMNS = 'post_type, post_status, post_title, post_name, post_content, post_excerpt, post_parent, menu_order';

	/**
	 * The item as the tables hold it: its row, its meta rows, its menus, and
	 * the number of items that name it as parent. false when there is no
	 * such post, null when a table could not be read.
	 *
	 * @param int $id Post id.
	 * @return array|false|null
	 */
	public static function state( $id ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'posts', 'postmeta', 'term_relationships' ) ) ) {
			return null;
		}
		$id   = (int) $id;
		$post = self::select( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns from COLUMNS, table from $wpdb.
		if ( null === $post ) {
			return null;
		}
		if ( ! isset( $post[0] ) ) {
			return false;
		}
		$meta  = self::select( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$menus = self::select( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subs  = self::select( $wpdb->prepare( "SELECT COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_menu_item_parent' AND meta_value = %s", (string) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		if ( null === $meta || null === $menus || null === $subs ) {
			return null;
		}
		$row = array();
		foreach ( array_map( 'trim', explode( ',', self::COLUMNS ) ) as $col ) {
			$row[ $col ] = isset( $post[0][ $col ] ) ? (string) $post[0][ $col ] : '';
		}
		$pairs = array();
		foreach ( $meta as $m ) {
			$pairs[] = array( (string) ( $m['meta_key'] ?? '' ), self::text( (string) ( $m['meta_value'] ?? '' ) ) );
		}
		$tt = array();
		foreach ( $menus as $m ) {
			$tt[] = (int) ( $m['term_taxonomy_id'] ?? 0 );
		}
		return array(
			'post'  => $row,
			'meta'  => $pairs,
			'menus' => $tt,
			'subs'  => (int) ( $subs[0]['n'] ?? 0 ),
		);
	}

	/**
	 * Bytes as text JSON can carry: as they are where they are UTF-8, else
	 * base64 behind a marker. Only hashed, never shown.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private static function text( $value ) {
		return 1 === preg_match( '//u', $value ) ? $value : 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes JSON cannot carry.
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function now_hashes( array $snap ) {
		$state = self::state( (int) $snap['id'] );
		if ( null === $state ) {
			return null;
		}
		if ( false === $state ) {
			return array(
				'item' => self::ABSENT,
				'use'  => self::ABSENT,
			);
		}
		return array(
			'item' => self::hash( array( $state['post'], $state['meta'], $state['menus'] ) ),
			'use'  => self::hash( $state['subs'] ),
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		return array(
			'item' => self::ABSENT,
			'use'  => self::ABSENT,
		);
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'menu_item:' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * The undo and read-back part of the answer of wp_add_menu_item.
	 *
	 * @param int    $id      The new item.
	 * @param array  $asked   field => value as sent: title, url, parent, object_id, object.
	 * @param string $summary Summary for wp_undo_list.
	 * @return array
	 */
	public static function added( $id, array $asked, $summary ) {
		$tool   = 'wp_add_menu_item';
		$reader = 'wp_get_menu';
		$snap   = null;
		if ( self::on() ) {
			$data  = array(
				'v'  => self::VERSION,
				'id' => (int) $id,
			);
			$after = self::now_hashes( $data );
			if ( null !== $after && self::ABSENT !== $after['item'] ) {
				$data['after'] = $after;
				$snap          = $data;
			}
		}
		$outcome = self::outcome( $tool, $reader, true, $snap, $summary );
		// wp_get_menu shows the title, the address and the parent item; the
		// target of a content link is compared, not shown.
		$res = AB_MCP_Undo_Answer::saved( array(), $asked, self::read_back( $id ), $reader, array( 'object_id', 'object' ) );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The item as wp_get_menu shows it (wp_setup_nav_menu_item(): the title
	 * of the linked post where the item has none of its own), read again past
	 * the cache, with its target.
	 *
	 * @param int $id Item id.
	 * @return array
	 */
	public static function read_back( $id ) {
		$id = (int) $id;
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;
		if ( ! is_object( $post ) ) {
			return array();
		}
		$item = function_exists( 'wp_setup_nav_menu_item' ) ? wp_setup_nav_menu_item( $post ) : null;
		if ( is_object( $item ) ) {
			$out = array(
				'title'     => $item->title ?? '',
				'url'       => $item->url ?? '',
				'parent'    => $item->menu_item_parent ?? 0,
				'object_id' => $item->object_id ?? 0,
				'object'    => $item->object ?? '',
			);
		} else {
			$out = array(
				'title'     => $post->post_title ?? '',
				'url'       => get_post_meta( $id, '_menu_item_url', true ),
				'parent'    => get_post_meta( $id, '_menu_item_menu_item_parent', true ),
				'object_id' => get_post_meta( $id, '_menu_item_object_id', true ),
				'object'    => get_post_meta( $id, '_menu_item_object', true ),
			);
		}
		foreach ( $out as $k => $v ) {
			$v         = is_scalar( $v ) ? (string) $v : '';
			$out[ $k ] = in_array( $k, array( 'parent', 'object_id' ), true ) ? (int) $v : $v;
		}
		return $out;
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$id    = (int) $snap['id'];
		$state = self::state( $id );
		if ( ! is_array( $state ) || 'nav_menu_item' !== $state['post']['post_type'] ) {
			return 'gone';
		}
		return current_user_can( 'delete_post', $id ) ? '' : 'forbidden';
	}

	/**
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id   = (int) $snap['id'];
		$done = wp_delete_post( $id, true );
		if ( ! $done || false !== self::state( $id ) ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: %d: menu item id */
					__( 'WordPress did not delete menu item %d again; the undo point stays. Check the menu with wp_get_menu, and try again.', 'alphabridge-mcp' ),
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
		return new WP_Error( 'ab_mcp_undo_menu_item_changed', __( 'The menu item was changed, moved or given sub-items after the undo was planned; nothing was restored. Pass force=true to delete it over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the menu item is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( 'wp_add_menu_item' !== $tool || ! is_array( $data ) ) {
			return false;
		}
		$keys = array_keys( $data );
		sort( $keys );
		if ( array( 'after', 'id', 'v' ) !== $keys || self::VERSION !== $data['v'] || ! is_int( $data['id'] ) || $data['id'] <= 0 || ! is_array( $data['after'] ) ) {
			return false;
		}
		$locs = array_keys( $data['after'] );
		sort( $locs );
		if ( array( 'item', 'use' ) !== $locs ) {
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
