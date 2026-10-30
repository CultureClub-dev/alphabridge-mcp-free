<?php
/**
 * Undo kind builder_data: the raw stored copies of pages built with a page
 * builder, taken before a builder writer changes them, and put back byte for
 * byte.
 *
 * Why raw bytes and not the builder's own save path: a restore has to give
 * back exactly what was there. A builder's save re-sanitises, re-renders and
 * re-encodes (Elementor runs its JSON through its document, WordPress filters
 * post_content for an account without unfiltered_html), so what came back
 * would be the builder's opinion of the old page, not the old page. The cost
 * of bypassing the save — caches made from the data are stale — is paid
 * below: the builder caches and the page caches are emptied after every
 * restore.
 *
 * Snapshot (one point, or one part of a point):
 *   { "v": 1, "scope": "element"|"text"|"raw", "posts": [ item, … ] }
 * item:
 *   { "id": int, "builder": adapter id, "created"?: true,
 *     "locs": { "post_content": { "existed": true, "value"|"b64": … },
 *               "meta:<key>": { "existed": false }
 *                           | { "existed": true, "rows": [ { "meta_id": int, "value"|"b64": … }, … ] } } }
 * A value that is not valid UTF-8 is kept as "b64": JSON cannot carry it, and
 * wp_json_encode() would re-encode it into other bytes rather than fail.
 *
 * The index entry keeps, per post, a sha256 of every copy as the change left
 * it ("after", AB_MCP_Undo::set_after()). wp_undo restores a copy only
 * where it still has that state — or the one from before, where there is
 * nothing to do — and refuses a page somebody has changed since, unless force
 * is set.
 *
 * "created" marks an autosave revision the change made: a plugin's ability
 * staged its change there instead of changing the live page, as Elementor's
 * do on a published page (wp_run_ability). There was nothing before, so its
 * locs hold the revision as the change left it, "row" a sha256 of its title
 * and excerpt (row_hash()), and wp_undo deletes the revision again — only an
 * autosave revision, and only while it still holds that state and no copy
 * more (an editor saving the draft again changes them or adds meta keys),
 * unless force is set.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Undo_Builder
 */
class AB_MCP_Undo_Builder {

	/** The undo kind. */
	const KIND = 'builder_data';

	/** Snapshot format version. */
	const VERSION = 1;

	/**
	 * Scopes a snapshot can carry: one element, text fields across a page, raw
	 * search and replace, or what a plugin's ability (wp_run_ability) may have
	 * changed: every stored copy and every meta key of the post it names.
	 */
	const SCOPES = array( 'element', 'text', 'raw', 'ability' );

	/**
	 * Meta a builder derives from the content on save_post and nowhere else
	 * (measured: Stackable builds both from the <style> parts of the content,
	 * a direct database write leaves them stale and the page then carries the
	 * old and the new rule). A byte-exact restore of the content has to take
	 * them back with it, because no save runs.
	 */
	const DERIVED_META = array( 'stackable_optimized_css', 'stackable_optimized_css_raw' );

	/**
	 * Meta keys WordPress writes through functions of its own, or uses for
	 * itself; a snapshot that names one is not ours and is refused whole.
	 */
	const NEVER_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id', '_wp_page_template', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/** The prefix of Yoast SEO's meta keys (WPSEO_Meta::$meta_prefix). */
	const YOAST_PREFIX = '_yoast_wpseo_';

	/** Yoast SEO's watcher that builds a post's indexable (28.6). */
	const YOAST_WATCHER = 'Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher';

	/**
	 * Warnings of the restores since the last take_warnings(): a row put back
	 * over its key, say. wp_undo passes them on.
	 *
	 * @var string[]
	 */
	private static $warnings = array();

	/**
	 * The warnings gathered since the last call, and forget them.
	 *
	 * @return string[]
	 */
	public static function take_warnings() {
		$out            = array_values( array_unique( self::$warnings ) );
		self::$warnings = array();
		return $out;
	}

	/* ----------------------------------------------------------- snapshot */

	/**
	 * Which stored copies a write to this page can change: every copy the
	 * reading adapter lists, the derived meta the page has, and whatever the
	 * writer names on top (a copy it creates, a draft it keeps in step). Never
	 * a key the adapter locks: those hold page code no writer touches.
	 *
	 * @param WP_Post                $post    Post.
	 * @param AB_MCP_Builder_Adapter $adapter Reading adapter.
	 * @param string[]               $extra   Further locs, e.g. 'meta:_fl_builder_draft'.
	 * @return string[]
	 */
	public static function locs( $post, $adapter, array $extra = array() ) {
		$locs   = array();
		$locked = is_object( $adapter ) && method_exists( $adapter, 'locked_meta_keys' ) ? (array) $adapter->locked_meta_keys() : array();
		if ( is_object( $adapter ) && method_exists( $adapter, 'copies' ) ) {
			foreach ( (array) $adapter->copies( $post ) as $copy ) {
				if ( is_array( $copy ) && isset( $copy['loc'] ) ) {
					$locs[] = (string) $copy['loc'];
				}
			}
		}
		$locs[] = 'post_content';
		/**
		 * Meta a page builder derives from the content on save and nowhere
		 * else; undo points of builder pages keep and restore it with the
		 * content.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$derived = (array) apply_filters( 'ab_mcp_pro_builder_derived_meta', self::DERIVED_META );
		foreach ( $derived as $key ) {
			if ( is_string( $key ) && '' !== $key && self::meta_rows( (int) $post->ID, $key ) ) {
				$locs[] = 'meta:' . $key;
			}
		}
		foreach ( $extra as $loc ) {
			$locs[] = (string) $loc;
		}
		$out = array();
		foreach ( array_unique( $locs ) as $loc ) {
			if ( ! self::valid_loc( $loc ) ) {
				continue;
			}
			if ( 0 === strpos( $loc, 'meta:' ) && class_exists( 'AB_MCP_Builders' ) && AB_MCP_Builders::is_locked_key( substr( $loc, 5 ), $locked ) ) {
				continue;
			}
			$out[] = $loc;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The snapshot item of one post: the raw state of every loc.
	 *
	 * @param WP_Post                $post    Post.
	 * @param AB_MCP_Builder_Adapter $adapter Reading adapter.
	 * @param string[]               $extra   Further locs (writer's touched_locs()).
	 * @return array
	 */
	public static function snapshot( $post, $adapter, array $extra = array() ) {
		return self::snapshot_of(
			(int) $post->ID,
			self::locs( $post, $adapter, $extra ),
			is_object( $adapter ) && method_exists( $adapter, 'id' ) ? (string) $adapter->id() : ''
		);
	}

	/**
	 * The snapshot item of one post over the locs a caller names. A caller
	 * that cannot know which copies a change reaches (a plugin's ability)
	 * names all of them; a loc this class does not write is left out.
	 *
	 * @param int      $post_id Post.
	 * @param string[] $locs    Locs.
	 * @param string   $builder Adapter id, for the caches a restore empties; '' when none.
	 * @return array
	 */
	public static function snapshot_of( $post_id, array $locs, $builder = '' ) {
		$id  = (int) $post_id;
		$out = array();
		foreach ( $locs as $loc ) {
			if ( self::valid_loc( $loc ) ) {
				$out[ (string) $loc ] = self::encode_state( self::read_loc( $id, (string) $loc ) );
			}
		}
		ksort( $out, SORT_STRING );
		return array(
			'id'      => $id,
			'builder' => (string) $builder,
			'locs'    => $out,
		);
	}

	/**
	 * A snapshot ready for the store.
	 *
	 * @param string $scope element, text or raw.
	 * @param array  $items Items of snapshot().
	 * @return array
	 */
	public static function data( $scope, array $items ) {
		return array(
			'v'     => self::VERSION,
			'scope' => in_array( $scope, self::SCOPES, true ) ? $scope : 'element',
			'posts' => array_values( $items ),
		);
	}

	/**
	 * Record an undo point, or add a part to one ($token given).
	 *
	 * @param string      $tool    Tool the change came from (a key of AB_MCP_Undo_Restore::policy()).
	 * @param string      $scope   element, text or raw.
	 * @param array       $items   Items of snapshot().
	 * @param string|null $token   Token to add a part to, or null for a new point.
	 * @param string      $summary Short summary for wp_undo_list.
	 * @return string|WP_Error The token.
	 */
	public static function record( $tool, $scope, array $items, $token = null, $summary = '' ) {
		$policy = class_exists( 'AB_MCP_Undo_Restore' ) ? AB_MCP_Undo_Restore::policy() : array();
		$rec    = array(
			'tool'    => (string) $tool,
			'cap'     => isset( $policy[ $tool ]['cap'] ) ? (string) $policy[ $tool ]['cap'] : 'edit_posts',
			'kind'    => self::KIND,
			'summary' => (string) $summary,
			'items'   => count( $items ),
			'data'    => self::data( $scope, $items ),
		);
		if ( null !== $token && '' !== $token ) {
			$added = AB_MCP_Undo::append_part( (string) $token, $rec );
			return is_wp_error( $added ) ? $added : (string) $token;
		}
		return AB_MCP_Undo::record_result( $rec );
	}

	/**
	 * Would these items fit one undo point (or one part)?
	 *
	 * @param string $scope Scope.
	 * @param array  $items Items.
	 * @return true|WP_Error
	 */
	public static function check_size( $scope, array $items ) {
		return AB_MCP_Undo::check_size( self::KIND, self::data( $scope, $items ) );
	}

	/**
	 * Keep the state one post was written to with the undo point.
	 *
	 * @param string               $token   Token.
	 * @param int                  $post_id Post.
	 * @param array<string,string> $hashes  state_hashes() right after the write.
	 * @return true|WP_Error
	 */
	public static function mark_written( $token, $post_id, array $hashes ) {
		return AB_MCP_Undo::set_after( (string) $token, (int) $post_id, $hashes );
	}

	/* -------------------------------------------------------------- state */

	/**
	 * The current raw state of one loc, straight from the tables (the object
	 * cache holds unserialized meta and may be stale).
	 *
	 * @param int    $post_id Post.
	 * @param string $loc     post_content, post_content_filtered or meta:<key>.
	 * @return array { existed, value? , rows? } with raw strings.
	 */
	public static function read_loc( $post_id, $loc ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( 'post_content' === $loc || 'post_content_filtered' === $loc ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT {$loc} FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- the column is one of two literals.
			if ( null === $value ) {
				// wpdb::get_var() answers null for an empty column as for a
				// missing row (class-wpdb.php:3044); only the row tells them
				// apart. An empty post_content_filtered is the rule, and a
				// snapshot calling it missing would be refused by its restore.
				$row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return array(
					'existed' => null !== $row,
					'value'   => '',
				);
			}
			return array(
				'existed' => true,
				'value'   => (string) $value,
			);
		}
		$rows = self::meta_rows( $post_id, substr( (string) $loc, 5 ) );
		return array(
			'existed' => array() !== $rows,
			'rows'    => $rows,
		);
	}

	/**
	 * Every row of one meta key of a post, oldest first.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @return array<int,array{meta_id:int,value:string}>
	 */
	public static function meta_rows( $post_id, $key ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", (int) $post_id, (string) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the stored bytes are the point.
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row   = (array) $row;
			$out[] = array(
				'meta_id' => (int) $row['meta_id'],
				'value'   => null === $row['meta_value'] ? '' : (string) $row['meta_value'],
			);
		}
		return $out;
	}

	/**
	 * sha256 of a state, raw or as stored in a snapshot. Meta ids do not
	 * count: a key restored over meta_key gets new ones and is still the same
	 * page.
	 *
	 * @param array $state read_loc() or a snapshot loc.
	 * @return string
	 */
	public static function hash_state( array $state ) {
		$state  = self::decode_state( $state );
		$values = array();
		if ( ! empty( $state['existed'] ) ) {
			if ( isset( $state['rows'] ) ) {
				foreach ( $state['rows'] as $row ) {
					$values[] = self::encode_value( (string) $row['value'] );
				}
			} else {
				$values[] = self::encode_value( isset( $state['value'] ) ? (string) $state['value'] : '' );
			}
		}
		return hash(
			'sha256',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes on every call, without WordPress' re-encoding.
				array(
					'existed' => ! empty( $state['existed'] ),
					'values'  => $values,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * The raw values of a state, raw or as stored in a snapshot: the column,
	 * or every row of the meta key; none for a copy that did not exist.
	 *
	 * @param array $state read_loc() or a snapshot loc.
	 * @return string[]
	 */
	public static function values_of( array $state ) {
		$state = self::decode_state( $state );
		if ( empty( $state['existed'] ) ) {
			return array();
		}
		if ( isset( $state['rows'] ) ) {
			$out = array();
			foreach ( $state['rows'] as $row ) {
				$out[] = (string) $row['value'];
			}
			return $out;
		}
		return array( isset( $state['value'] ) ? (string) $state['value'] : '' );
	}

	/**
	 * sha256 of the current state of each loc.
	 *
	 * @param int      $post_id Post.
	 * @param string[] $locs    Locs.
	 * @return array<string,string>
	 */
	public static function state_hashes( $post_id, array $locs ) {
		$out = array();
		foreach ( $locs as $loc ) {
			$out[ (string) $loc ] = self::hash_state( self::read_loc( (int) $post_id, (string) $loc ) );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The locs of the caches every restore empties.
	 *
	 * @return string[]
	 */
	private static function cache_locs() {
		if ( ! class_exists( 'AB_MCP_Builder_Caches', false ) ) {
			return array();
		}
		$out = array();
		foreach ( array_merge( AB_MCP_Builder_Caches::META, AB_MCP_Builder_Caches::elementor_keys() ) as $key ) {
			$out[] = 'meta:' . $key;
		}
		return $out;
	}

	/**
	 * sha256 of each loc of a snapshot item: the state before the change.
	 *
	 * @param array $item Snapshot item.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $item ) {
		$out = array();
		foreach ( $item['locs'] as $loc => $state ) {
			$out[ (string) $loc ] = self::hash_state( (array) $state );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * How the page stands against the change: per loc, 'before' (as before
	 * the change, nothing to restore), 'after' (as the change left it,
	 * restorable) or 'changed' (neither: somebody wrote it since). A cache
	 * every restore empties (AB_MCP_Builder_Caches::clear()) is left out:
	 * it is never put back, and its builder rebuilds it at the next view. An
	 * undo point that wp_run_ability recorded with Pro 4.7.0 can hold one
	 * (_elementor_page_assets); written back and emptied again it would read
	 * as a failed restore.
	 *
	 * @param array      $item  Snapshot item.
	 * @param array|null $after loc => sha256 after the change, null when not known.
	 * @return array<string,string>
	 */
	public static function compare( array $item, $after ) {
		$locs   = array_diff( array_keys( $item['locs'] ), self::cache_locs() );
		$now    = self::state_hashes( (int) $item['id'], $locs );
		$before = self::before_hashes( $item );
		$out    = array();
		foreach ( $now as $loc => $sha ) {
			if ( hash_equals( $before[ $loc ], $sha ) ) {
				$out[ $loc ] = 'before';
			} elseif ( is_array( $after ) && isset( $after[ $loc ] ) && hash_equals( (string) $after[ $loc ], $sha ) ) {
				$out[ $loc ] = 'after';
			} else {
				$out[ $loc ] = 'changed';
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------- restore */

	/**
	 * Check a snapshot and turn it into restore items: the posts of the
	 * point and of every part, per copy the first snapshot winning (it is the
	 * oldest state). A later part may add copies of a post the point already
	 * holds: a plugin's ability can add meta keys nobody could name before
	 * the call, and wp_run_ability keeps them as missing in a part of its own,
	 * so the restore removes them again. Fail closed on the shape: a snapshot
	 * that is not in the form this class writes is refused whole.
	 *
	 * @param mixed $data  Snapshot of the point.
	 * @param array $parts Snapshots of its parts.
	 * @return array|WP_Error { scope, items }
	 */
	public static function items_of( $data, array $parts ) {
		$scope = null;
		$items = array();
		$seen  = array();
		foreach ( array_merge( array( $data ), $parts ) as $snap ) {
			if ( ! is_array( $snap ) || ! isset( $snap['v'], $snap['posts'] ) || self::VERSION !== (int) $snap['v'] || ! is_array( $snap['posts'] )
				|| ! isset( $snap['scope'] ) || ! in_array( $snap['scope'], self::SCOPES, true ) ) {
				return self::corrupt();
			}
			if ( null === $scope ) {
				$scope = (string) $snap['scope'];
			} elseif ( 'raw' === $snap['scope'] ) {
				$scope = 'raw'; // The widest scope of any part decides the right needed.
			}
			foreach ( $snap['posts'] as $item ) {
				if ( ! self::valid_item( $item ) ) {
					return self::corrupt();
				}
				$id = (int) $item['id'];
				if ( isset( $seen[ $id ] ) ) {
					$at = $seen[ $id ];
					foreach ( $item['locs'] as $loc => $state ) {
						if ( ! array_key_exists( $loc, $items[ $at ]['locs'] ) ) {
							$items[ $at ]['locs'][ $loc ] = $state;
						}
					}
					continue;
				}
				$seen[ $id ] = count( $items );
				$items[]     = $item;
			}
		}
		foreach ( $items as $i => $item ) {
			ksort( $items[ $i ]['locs'], SORT_STRING );
		}
		return array(
			'scope' => null === $scope ? 'element' : $scope,
			'items' => $items,
		);
	}

	/**
	 * Plan a restore: which posts can go back, which are blocked and why.
	 *
	 * @param string $tool  Tool of the point.
	 * @param mixed  $data  Snapshot.
	 * @param array  $parts Snapshots of its parts.
	 * @param array  $after post id => loc => sha256 (from the index entry).
	 * @param bool   $force Restore over later changes.
	 * @return array|WP_Error { tool, kind, items, blocked, restorable }
	 */
	public static function plan( $tool, $data, array $parts, array $after, $force ) {
		$of = self::items_of( $data, $parts );
		if ( is_wp_error( $of ) ) {
			return $of;
		}
		$out = array(
			'tool'       => (string) $tool,
			'kind'       => self::KIND,
			'items'      => array(),
			'blocked'    => array(),
			'restorable' => 0,
		);
		// A raw replace reached builder keys as stored, past every field rule;
		// putting such bytes back takes what writing them took.
		if ( 'raw' === $of['scope'] && ! current_user_can( 'unfiltered_html' ) ) {
			foreach ( $of['items'] as $item ) {
				$out['blocked'][] = array(
					'ref'    => (int) $item['id'],
					'reason' => 'forbidden: unfiltered_html',
				);
			}
			return $out;
		}
		foreach ( $of['items'] as $item ) {
			$id = (int) $item['id'];
			wp_cache_delete( $id, 'posts' );
			$post = get_post( $id );
			if ( ! empty( $item['created'] ) ) {
				$planned = self::plan_created( $item, $post, $force );
				if ( isset( $planned['reason'] ) ) {
					$out['blocked'][] = $planned;
				} else {
					$out['items'][] = $planned;
				}
				continue;
			}
			if ( ! $post ) {
				$out['blocked'][] = array( 'ref' => $id, 'reason' => 'gone' );
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$out['blocked'][] = array( 'ref' => $id, 'reason' => 'forbidden' );
				continue;
			}
			$post_after = isset( $after[ (string) $id ] ) && is_array( $after[ (string) $id ] ) ? $after[ (string) $id ] : null;
			$cmp        = self::compare( $item, $post_after );
			$changed    = array_keys( $cmp, 'changed', true );
			if ( array() !== $changed && ! $force ) {
				$out['blocked'][] = array(
					'ref'    => $id,
					'reason' => 'changed_since',
					'locs'   => $changed,
				);
				continue;
			}
			$out['items'][] = array(
				'ref'   => $id,
				'item'  => $item,
				'after' => $post_after,
				'force' => (bool) $force,
				'noop'  => array() === array_diff( $cmp, array( 'before' ) ),
			);
		}
		$out['restorable'] = count( $out['items'] );
		return $out;
	}

	/**
	 * Plan the deletion of an autosave revision the change created: nothing
	 * to do once it is gone; blocked where the post is no autosave revision
	 * (any more), where the account may not edit its page, or where the
	 * revision no longer holds what the change left in it.
	 *
	 * @param array        $item  Snapshot item with "created".
	 * @param WP_Post|null $post  The post now.
	 * @param bool         $force Delete it over later changes.
	 * @return array Planned item, or { ref, reason, locs? } when blocked.
	 */
	private static function plan_created( array $item, $post, $force ) {
		$id      = (int) $item['id'];
		$planned = array(
			'ref'   => $id,
			'item'  => $item,
			'after' => null,
			'force' => (bool) $force,
			'noop'  => ! $post,
		);
		if ( ! $post ) {
			return $planned;
		}
		if ( ! self::is_autosave( $post ) ) {
			return array( 'ref' => $id, 'reason' => 'not_a_draft' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return array( 'ref' => $id, 'reason' => 'forbidden' );
		}
		$changed = self::created_changes( $item );
		if ( array() !== $changed && ! $force ) {
			return array(
				'ref'    => $id,
				'reason' => 'changed_since',
				'locs'   => $changed,
			);
		}
		return $planned;
	}

	/**
	 * What changed in an autosave revision since the change created it: its
	 * copies in the snapshot, copies it has now and the snapshot does not
	 * (wp_run_ability's own list, locs_of() of its broker: Pro's where Pro is
	 * loaded, else this plugin's), and its row ("post_fields").
	 *
	 * @param array $item Snapshot item with "created".
	 * @return string[] Locs.
	 */
	private static function created_changes( array $item ) {
		$id      = (int) $item['id'];
		$changed = array_keys( self::compare( $item, null ), 'changed', true );
		$broker  = class_exists( 'AB_MCP_Pro_Ability_Broker', false ) ? 'AB_MCP_Pro_Ability_Broker' : 'AB_MCP_Ability_Broker';
		if ( class_exists( $broker, false ) ) {
			foreach ( call_user_func( array( $broker, 'locs_of' ), $id ) as $loc ) {
				if ( ! array_key_exists( $loc, $item['locs'] ) ) {
					$changed[] = $loc;
				}
			}
		}
		if ( isset( $item['row'] ) && ! hash_equals( (string) $item['row'], self::row_hash( $id ) ) ) {
			$changed[] = 'post_fields';
		}
		return $changed;
	}

	/**
	 * sha256 of the fields of a post's row that no copy holds and an editor
	 * saving an autosave writes (wp_create_post_autosave()): its title and
	 * its excerpt. Not the time of saving: a later change staged in the same
	 * draft, which its own undo point takes back, moves it as well (measured
	 * 03.10.2026 with Elementor's manage-elements), and the earlier undo
	 * point would then never delete the draft without force.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function row_hash( $post_id ) {
		wp_cache_delete( (int) $post_id, 'posts' );
		$post = get_post( (int) $post_id );
		$row  = array();
		foreach ( array( 'post_title', 'post_excerpt' ) as $field ) {
			$row[ $field ] = is_object( $post ) && isset( $post->$field ) ? (string) $post->$field : '';
		}
		return hash( 'sha256', (string) json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes on every call.
	}

	/**
	 * Whether a post is an autosave revision (wp-includes/revision.php
	 * wp_is_post_autosave(): a revision named "<parent>-autosave-…").
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_autosave( $post ) {
		return is_object( $post ) && isset( $post->post_type ) && 'revision' === $post->post_type
			&& function_exists( 'wp_is_post_autosave' ) && false !== wp_is_post_autosave( $post );
	}

	/**
	 * Delete an autosave revision the change created, the checks of
	 * plan_created() made again on the tables as they are now.
	 *
	 * @param array $planned Item of plan() with "created".
	 * @return true|WP_Error
	 */
	private static function drop_created( array $planned ) {
		$item = $planned['item'];
		$id   = (int) $item['id'];
		wp_cache_delete( $id, 'posts' );
		$post = get_post( $id );
		if ( ! $post ) {
			return true; // Gone already: published or dropped in the editor.
		}
		if ( ! self::is_autosave( $post ) ) {
			return new WP_Error( 'ab_mcp_undo_not_a_draft', __( 'The post this undo point would delete is no autosave revision; nothing was deleted. Check it with wp_get_post; wp_undo_discard removes this undo point.', 'alphabridge-mcp' ) );
		}
		if ( empty( $planned['force'] ) && array() !== self::created_changes( $item ) ) {
			return new WP_Error( 'ab_mcp_undo_post_changed', __( 'The draft was changed after the undo was planned; it was not deleted. Pass force=true to delete it with the newer change.', 'alphabridge-mcp' ) );
		}
		if ( function_exists( 'wp_delete_post_revision' ) ) {
			wp_delete_post_revision( $id );
		}
		wp_cache_delete( $id, 'posts' );
		if ( get_post( $id ) ) {
			/* translators: %d: revision id */
			return new WP_Error( 'ab_mcp_undo_write_failed', sprintf( __( 'WordPress did not delete the draft (autosave revision #%d). Try again; if it keeps failing, ask the site administrator.', 'alphabridge-mcp' ), $id ) );
		}
		return true;
	}

	/**
	 * Restore one planned post. The comparison is made again right before the
	 * write, on the tables as they are now: the plan was a moment ago.
	 *
	 * @param array $planned Item of plan().
	 * @return true|WP_Error
	 */
	public static function restore_planned( array $planned ) {
		$item = $planned['item'];
		if ( ! empty( $item['created'] ) ) {
			return self::drop_created( $planned );
		}
		$cmp = self::compare( $item, isset( $planned['after'] ) ? $planned['after'] : null );
		if ( empty( $planned['force'] ) && in_array( 'changed', $cmp, true ) ) {
			return new WP_Error(
				'ab_mcp_undo_post_changed',
				__( 'The page was changed after the undo was planned; nothing was restored. Read it with wp_get_builder_layout, and pass force=true to put the old state back over the newer change.', 'alphabridge-mcp' )
			);
		}
		$todo = array_keys( array_diff( $cmp, array( 'before' ) ) );
		if ( array() === $todo ) {
			return true; // Already as it was: nothing to write, no cache to empty.
		}
		$res = self::write_back( $item, $todo );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( $res['warnings'] as $w ) {
			/* translators: 1: post id, 2: warning */
			self::$warnings[] = sprintf( __( 'Post %1$d: %2$s', 'alphabridge-mcp' ), (int) $item['id'], $w );
		}
		return true;
	}

	/**
	 * Write the snapshot's bytes back, then empty the caches made from them,
	 * then check that every loc carries its old state. Meta goes back over
	 * its meta_id ($wpdb->update; update_metadata_by_mid() would serialize a
	 * serialized value a second time); see restore_rows() for a row whose id
	 * is gone.
	 *
	 * @param array         $item Snapshot item.
	 * @param string[]|null $only Locs to write; null for all that differ.
	 * @return array|WP_Error { warnings }
	 */
	public static function write_back( array $item, $only = null ) {
		global $wpdb;
		$id       = (int) $item['id'];
		$warnings = array();
		$locs     = null === $only ? array_keys( $item['locs'] ) : array_values( array_intersect( array_keys( $item['locs'] ), $only ) );
		foreach ( $locs as $loc ) {
			$state = self::decode_state( (array) $item['locs'][ $loc ] );
			if ( 'post_content' === $loc || 'post_content_filtered' === $loc ) {
				$done = $wpdb->update( $wpdb->posts, array( $loc => (string) $state['value'] ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false === $done ) {
					return new WP_Error( 'ab_mcp_undo_write_failed', sprintf( /* translators: %s: column */ __( 'The database did not take back %s. Try again; wp_get_builder_layout shows how the page is now.', 'alphabridge-mcp' ), $loc ) );
				}
				continue;
			}
			$key = substr( $loc, 5 );
			$now = self::meta_rows( $id, $key );
			if ( empty( $state['existed'] ) ) {
				if ( array() !== $now && false === $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => $key ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					return new WP_Error( 'ab_mcp_undo_write_failed', sprintf( /* translators: %s: meta key */ __( 'The database did not remove %s again. Try again; wp_get_builder_layout shows how the page is now.', 'alphabridge-mcp' ), $key ) );
				}
				continue;
			}
			$res = self::restore_rows( $id, $key, $state['rows'], $now, $warnings );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		wp_cache_delete( $id, 'post_meta' );
		clean_post_cache( $id );
		if ( class_exists( 'AB_MCP_Builder_Caches' ) ) {
			AB_MCP_Builder_Caches::clear( $id, isset( $item['builder'] ) ? (string) $item['builder'] : '' );
		}
		// Byte for byte, or it is not a restore.
		$cmp = self::compare( $item, null );
		$off = array_keys( array_diff( $cmp, array( 'before' ) ) );
		if ( null !== $only ) {
			$off = array_values( array_intersect( $off, $locs ) );
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_undo_post_mismatch',
				sprintf(
					/* translators: %s: comma-separated stored copies */
					__( 'The page was written back, but the database holds something else for: %s. Check the page with wp_get_builder_layout.', 'alphabridge-mcp' ),
					implode( ', ', $off )
				)
			);
		}
		foreach ( self::sync_plugin_copies( $id, $locs ) as $w ) {
			$warnings[] = $w;
		}
		return array( 'warnings' => $warnings );
	}

	/**
	 * After a restore: the copies other plugins keep of a post's meta in
	 * tables of their own, which a write past WordPress's meta functions does
	 * not reach (no hook fires), brought in step through each plugin's own
	 * function — what the plugin does when that meta changes. Measured
	 * 02.10.2026: after an undo of a Yoast ability that had set a canonical
	 * address, wp_undo answered «Restored» and visitors kept getting the
	 * address from Yoast's indexable; after an undo of a WooCommerce product,
	 * the shop's price filter still read the price of before from the lookup
	 * table. A failure the plugin's function catches itself (Yoast's watcher
	 * logs it and goes on) is not seen here, as after a save in the editor;
	 * a warning says what could not be called at all, or threw.
	 *
	 * @param int      $id   Post.
	 * @param string[] $locs Locs written back.
	 * @return string[] Warnings, one per copy that could not be brought in step.
	 */
	private static function sync_plugin_copies( $id, array $locs ) {
		$keys = array();
		foreach ( $locs as $loc ) {
			if ( 0 === strpos( (string) $loc, 'meta:' ) ) {
				$keys[] = substr( (string) $loc, 5 );
			}
		}
		if ( array() === $keys ) {
			return array();
		}
		$out = array();
		$why = self::sync_yoast( $id, $keys );
		if ( '' !== $why ) {
			/* translators: %s: reason */
			$out[] = sprintf( __( 'Yoast SEO keeps the SEO fields of this post in a table of its own (its indexable), which the page shows to visitors and search engines; it could not be brought in step with the restored values (%s). Until the post is saved once, in the editor or with wp_update_post, visitors and search engines may still get the values this undo took back.', 'alphabridge-mcp' ), $why );
		}
		$why = in_array( '_stock_status', $keys, true ) ? self::sync_stock_visibility( $id ) : '';
		if ( '' !== $why ) {
			/* translators: %s: reason */
			$out[] = sprintf( __( 'WooCommerce marks a product that is out of stock with a term of its own (outofstock in product_visibility), by which the shop hides it where "Hide out of stock items" is on; it could not be brought in step with the restored stock status (%s). Until the product is saved once in WooCommerce, the shop may show or hide it as before this undo.', 'alphabridge-mcp' ), $why );
		}
		$why = self::sync_woocommerce( $id );
		if ( '' !== $why ) {
			/* translators: %s: reason */
			$out[] = sprintf( __( 'WooCommerce keeps the price and stock of this product in its lookup table too, which the shop\'s filters and sorting read; it could not be brought in step with the restored values (%s). Until the product is saved once in WooCommerce, filters and sorting may still use the values this undo took back.', 'alphabridge-mcp' ), $why );
		}
		return $out;
	}

	/**
	 * Yoast SEO: its indexable of the post, built again from the restored meta
	 * by Yoast's own watcher, as Yoast does when a meta key with its prefix
	 * changes (Indexable_Post_Meta_Watcher calls
	 * Indexable_Post_Watcher::build_indexable(), src/integrations/watchers/ in
	 * 28.6). Nothing to do where no key of Yoast's came back or Yoast is not
	 * active.
	 *
	 * @param int      $id   Post.
	 * @param string[] $keys Meta keys written back.
	 * @return string '' when done or nothing to do, else why not.
	 */
	private static function sync_yoast( $id, array $keys ) {
		$ours = false;
		foreach ( $keys as $key ) {
			$ours = $ours || 0 === strpos( (string) $key, self::YOAST_PREFIX );
		}
		if ( ! $ours || ! function_exists( 'YoastSEO' ) ) {
			return '';
		}
		try {
			$watcher = YoastSEO()->classes->get( self::YOAST_WATCHER );
			if ( ! is_object( $watcher ) || ! method_exists( $watcher, 'build_indexable' ) ) {
				return __( 'its indexable watcher is not available', 'alphabridge-mcp' );
			}
			$watcher->build_indexable( (int) $id );
		} catch ( \Throwable $e ) {
			return get_class( $e );
		}
		return '';
	}

	/**
	 * WooCommerce: the product's row in its lookup table, written again from
	 * the restored meta as WooCommerce does after it writes a price directly
	 * (wc_apply_sale_state_for_product(), includes/wc-product-functions.php in
	 * 11.1.2): the product's object cache emptied as a meta update empties it
	 * (WC_Post_Data::flush_object_meta_cache()), the data store's
	 * refresh_product_lookup_table() (WooCommerce 10.8), the product's
	 * transients. Nothing to do on a post that is no product or without
	 * WooCommerce.
	 *
	 * @param int $id Post.
	 * @return string '' when done or nothing to do, else why not.
	 */
	private static function sync_woocommerce( $id ) {
		$post = get_post( (int) $id );
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || ! class_exists( 'WC_Data_Store' ) ) {
			return '';
		}
		try {
			if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'object_' . (int) $id );
			}
			$store = WC_Data_Store::load( 'product' );
			if ( ! $store->has_callable( 'refresh_product_lookup_table' ) ) {
				return __( 'this WooCommerce has no refresh_product_lookup_table(), which WooCommerce 10.8 brought', 'alphabridge-mcp' );
			}
			$store->refresh_product_lookup_table( (int) $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $id );
			}
		} catch ( \Throwable $e ) {
			return get_class( $e );
		}
		return '';
	}

	/**
	 * WooCommerce: the outofstock term of product_visibility, set from the
	 * restored stock status as WooCommerce's data stores do when a product is
	 * saved (update_visibility() of WC_Product_Data_Store_CPT and of the
	 * variation store): the shop's catalogue reads the term, not the meta,
	 * where "Hide out of stock items" is on (Codex, 03.10.2026: a product
	 * set out of stock stayed hidden after the undo). Only this term: the
	 * others of the taxonomy (featured, the catalogue visibility, the rating)
	 * do not follow from a meta key. Nothing to do on a post that is no
	 * product or without WooCommerce's taxonomy.
	 *
	 * @param int $id Post.
	 * @return string '' when done or nothing to do, else why not.
	 */
	private static function sync_stock_visibility( $id ) {
		$post = get_post( (int) $id );
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || ! taxonomy_exists( 'product_visibility' ) ) {
			return '';
		}
		$terms = wp_get_object_terms( (int) $id, 'product_visibility', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) ) {
			return $terms->get_error_message();
		}
		$want = 'outofstock' === (string) get_post_meta( (int) $id, '_stock_status', true );
		$has  = in_array( 'outofstock', (array) $terms, true );
		if ( $want === $has ) {
			return '';
		}
		$done = $want ? wp_add_object_terms( (int) $id, 'outofstock', 'product_visibility' ) : wp_remove_object_terms( (int) $id, 'outofstock', 'product_visibility' );
		if ( is_wp_error( $done ) ) {
			return $done->get_error_message();
		}
		return false === $done ? __( 'the term was not written', 'alphabridge-mcp' ) : '';
	}

	/**
	 * Put the rows of one meta key back. A key can hold several rows, and
	 * get_post_meta( …, true ) reads the one with the lowest meta_id, so the
	 * values have to come back in their old order, not only as a set.
	 *
	 * Every row under its own meta_id where that is possible: the row is
	 * still there, or its id is free — a save that deleted the row and added
	 * it again leaves the old id unused, and the database never hands an
	 * AUTO_INCREMENT id out twice — then the rows the change added are
	 * removed, and the page is as it was, ids included. Where an id of the
	 * snapshot is taken by some other row (an id handed out again, as MySQL
	 * before 8.0 can after a restart), the values go back in their order into
	 * the rows the key has now, oldest first, with new rows behind them: the
	 * ids differ, the order of the values does not, and the answer says so.
	 *
	 * @param int    $id       Post.
	 * @param string $key      Meta key.
	 * @param array  $rows     Snapshot rows { meta_id, value }, oldest first.
	 * @param array  $now      Rows of the key now (meta_rows()).
	 * @param array  $warnings Warnings (by reference).
	 * @return true|WP_Error
	 */
	private static function restore_rows( $id, $key, array $rows, array $now, array &$warnings ) {
		global $wpdb;
		$by_id = array();
		foreach ( $now as $row ) {
			$by_id[ (int) $row['meta_id'] ] = $row;
		}
		$own = true;
		foreach ( $rows as $row ) {
			$mid = (int) $row['meta_id'];
			if ( ! isset( $by_id[ $mid ] ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$own = false;
				break;
			}
		}
		$failed = new WP_Error( 'ab_mcp_undo_write_failed', sprintf( /* translators: %s: meta key */ __( 'The database did not take back %s. Try again; wp_get_builder_layout shows how the page is now.', 'alphabridge-mcp' ), $key ) );
		if ( $own ) {
			foreach ( $rows as $row ) {
				$mid = (int) $row['meta_id'];
				if ( isset( $by_id[ $mid ] ) ) {
					if ( $by_id[ $mid ]['value'] === (string) $row['value'] ) {
						continue;
					}
					$done = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => (string) $row['value'] ), array( 'meta_id' => $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				} else {
					$done = $wpdb->insert( $wpdb->postmeta, array( 'meta_id' => $mid, 'post_id' => (int) $id, 'meta_key' => $key, 'meta_value' => (string) $row['value'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				}
				if ( false === $done ) {
					return $failed;
				}
			}
			// Rows of the key the snapshot does not have came with the change.
			foreach ( $now as $row ) {
				if ( ! self::snapshot_has_row( $rows, $row['meta_id'] ) ) {
					$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $row['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			return true;
		}
		$slots = array_values( $now );
		foreach ( array_values( $rows ) as $i => $row ) {
			if ( isset( $slots[ $i ] ) ) {
				if ( $slots[ $i ]['value'] === (string) $row['value'] ) {
					continue;
				}
				$done = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => (string) $row['value'] ), array( 'meta_id' => (int) $slots[ $i ]['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			} else {
				$done = $wpdb->insert( $wpdb->postmeta, array( 'post_id' => (int) $id, 'meta_key' => $key, 'meta_value' => (string) $row['value'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			}
			if ( false === $done ) {
				return $failed;
			}
		}
		for ( $i = count( $rows ); $i < count( $slots ); $i++ ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $slots[ $i ]['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		/* translators: %s: meta key */
		$warnings[] = sprintf( __( '%s was restored over its meta key: a row it was in no longer exists and its id is taken by another row, so the values are back in their order under other ids.', 'alphabridge-mcp' ), $key );
		return true;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Whether a loc names a copy this class writes.
	 *
	 * @param mixed $loc Loc.
	 * @return bool
	 */
	public static function valid_loc( $loc ) {
		if ( ! is_string( $loc ) ) {
			return false;
		}
		if ( 'post_content' === $loc || 'post_content_filtered' === $loc ) {
			return true;
		}
		if ( 0 !== strpos( $loc, 'meta:' ) ) {
			return false;
		}
		$key = substr( $loc, 5 );
		return '' !== $key && strlen( $key ) <= 255 && ! in_array( $key, self::NEVER_META, true );
	}

	/**
	 * Whether a snapshot item has the form snapshot() writes.
	 *
	 * @param mixed $item Item.
	 * @return bool
	 */
	private static function valid_item( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['id'], $item['locs'] ) || ! is_array( $item['locs'] ) || array() === $item['locs'] ) {
			return false;
		}
		if ( ! ( is_int( $item['id'] ) || ctype_digit( (string) $item['id'] ) ) || (int) $item['id'] <= 0 ) {
			return false;
		}
		if ( isset( $item['builder'] ) && ! is_string( $item['builder'] ) ) {
			return false;
		}
		if ( array_key_exists( 'created', $item ) && true !== $item['created'] ) {
			return false;
		}
		if ( array_key_exists( 'row', $item ) && ( ! is_string( $item['row'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $item['row'] ) ) ) {
			return false;
		}
		foreach ( $item['locs'] as $loc => $state ) {
			if ( ! self::valid_loc( $loc ) || ! is_array( $state ) || ! isset( $state['existed'] ) || ! is_bool( $state['existed'] ) ) {
				return false;
			}
			$field = 'post_content' === $loc || 'post_content_filtered' === $loc;
			if ( $field ) {
				if ( true !== $state['existed'] || ! self::valid_value( $state ) ) {
					return false;
				}
				continue;
			}
			if ( ! $state['existed'] ) {
				continue;
			}
			if ( ! isset( $state['rows'] ) || ! is_array( $state['rows'] ) || array() === $state['rows'] ) {
				return false;
			}
			foreach ( $state['rows'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['meta_id'] ) || ! ( is_int( $row['meta_id'] ) || ctype_digit( (string) $row['meta_id'] ) ) || ! self::valid_value( $row ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Exactly one of "value" (a string) or "b64" (valid base64).
	 *
	 * @param array $v Holder.
	 * @return bool
	 */
	private static function valid_value( array $v ) {
		$has_value = array_key_exists( 'value', $v );
		$has_b64   = array_key_exists( 'b64', $v );
		if ( $has_value === $has_b64 ) {
			return false;
		}
		if ( $has_value ) {
			return is_string( $v['value'] );
		}
		return is_string( $v['b64'] ) && false !== base64_decode( $v['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- bytes JSON cannot carry.
	}

	/**
	 * A raw value in the form a snapshot keeps it.
	 *
	 * @param string $value Raw bytes.
	 * @return array { value } or { b64 }.
	 */
	public static function encode_value( $value ) {
		$value = (string) $value;
		if ( 1 === preg_match( '//u', $value ) ) {
			return array( 'value' => $value );
		}
		return array( 'b64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes JSON cannot carry.
	}

	/**
	 * A state as read_loc() returns it, in the form a snapshot keeps it.
	 *
	 * @param array $state Raw state.
	 * @return array
	 */
	private static function encode_state( array $state ) {
		$out = array( 'existed' => ! empty( $state['existed'] ) );
		if ( isset( $state['rows'] ) ) {
			if ( $out['existed'] ) {
				$out['rows'] = array();
				foreach ( $state['rows'] as $row ) {
					$out['rows'][] = array_merge( array( 'meta_id' => (int) $row['meta_id'] ), self::encode_value( (string) $row['value'] ) );
				}
			}
			return $out;
		}
		return array_merge( $out, self::encode_value( isset( $state['value'] ) ? (string) $state['value'] : '' ) );
	}

	/**
	 * A state as a snapshot keeps it, or as read_loc() returns it, with raw
	 * strings.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function decode_state( array $state ) {
		$out = array( 'existed' => ! empty( $state['existed'] ) );
		if ( isset( $state['rows'] ) && is_array( $state['rows'] ) ) {
			$out['rows'] = array();
			foreach ( $state['rows'] as $row ) {
				$out['rows'][] = array(
					'meta_id' => (int) $row['meta_id'],
					'value'   => self::raw_value( (array) $row ),
				);
			}
			return $out;
		}
		if ( array_key_exists( 'value', $state ) || array_key_exists( 'b64', $state ) ) {
			$out['value'] = self::raw_value( $state );
		}
		return $out;
	}

	/**
	 * The raw bytes of a { value } or { b64 } holder.
	 *
	 * @param array $v Holder.
	 * @return string
	 */
	private static function raw_value( array $v ) {
		if ( array_key_exists( 'b64', $v ) ) {
			$raw = base64_decode( (string) $v['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- bytes JSON cannot carry.
			return false === $raw ? '' : $raw;
		}
		return isset( $v['value'] ) ? (string) $v['value'] : '';
	}

	/**
	 * Whether a snapshot's rows include a meta id.
	 *
	 * @param array $rows    Rows.
	 * @param int   $meta_id Meta id.
	 * @return bool
	 */
	private static function snapshot_has_row( array $rows, $meta_id ) {
		foreach ( $rows as $row ) {
			if ( (int) $row['meta_id'] === (int) $meta_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The answer to a snapshot in a form this class does not write.
	 *
	 * @return WP_Error
	 */
	private static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the page is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}
}
