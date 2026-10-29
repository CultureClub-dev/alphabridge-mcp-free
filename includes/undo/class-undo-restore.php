<?php
/**
 * Undo restore: turn a stored snapshot back into the previous state.
 *
 * Two principles decide the design here.
 *
 * FIRST: rights are checked at RESTORE time, never at recording time. A
 * snapshot is data, not permission. Between recording and restoring, a user can
 * lose a capability, an option can land on the block list, a file jail can move.
 * All of those must win over the snapshot.
 *
 * SECOND: a restore must never be able to do something the original tool could
 * not. The checks the tools make on a post (AB_MCP_Post_Status) are
 * asked here again, not copied. Recording is suspended for the duration so a
 * restore does not create an undo point of its own.
 *
 * Free since 4.9.0, before in Pro only (AB_MCP_Pro_Undo_Restore there), for
 * the three kinds of the tools of this plugin: post_fields (wp_update_post),
 * post_status (wp_bulk_update_status) and site_settings
 * (wp_update_site_settings). The undo points of Pro's tools (options, files,
 * PHP snippets, page builders, the theme) share the store; Pro reverses them
 * with its own wp_undo, this class names them and leaves them alone.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Undo_Restore
 */
class AB_MCP_Undo_Restore {

	/**
	 * The least lead, in seconds, a restore leaves a scheduled post with — as
	 * the free core's AB_MCP_Tools_Content::SCHEDULE_LEAD (4.3.6).
	 */
	private const SCHEDULE_LEAD = 300;

	/**
	 * The options wp_update_site_settings writes, and so the only ones an
	 * undo point of it may write back (AB_MCP_Tools_Options_Settings).
	 */
	const SITE_SETTINGS = array( 'blogname', 'blogdescription', 'admin_email', 'timezone_string', 'posts_per_page' );

	/**
	 * Which tool produced which kind, and which capability the restore needs.
	 *
	 * Keyed by the EXACT originating tool, not by kind: a hand-edited index
	 * entry claiming kind "option" while naming a file tool must not slip
	 * through. An unknown tool is refused — fail closed.
	 *
	 * @return array
	 */
	public static function policy() {
		return array(
			'wp_bulk_update_status'   => array( 'kind' => 'post_status',   'cap' => 'edit_others_posts' ),
			'wp_update_site_settings' => array( 'kind' => 'site_settings', 'cap' => 'manage_options' ),
			'wp_update_post'          => array( 'kind' => 'post_fields',   'cap' => 'edit_posts' ),
		);
	}

	/**
	 * May the CURRENT request touch an undo point that came from $tool at all —
	 * to reverse it or to throw it away? Both need the same answer: an undo
	 * point for a plugin file is not content, and a content-scoped token has no
	 * business reversing OR discarding it.
	 *
	 * wp_undo and wp_undo_discard are deliberately easy to reach (capability
	 * "read") so that every token can at least SEE its own points. This is the
	 * gate that matters, and it is the gate of the ORIGINAL tool.
	 *
	 * @param string $tool Source tool name.
	 * @return true|WP_Error
	 */
	public static function may_touch( $tool ) {
		$policy = self::policy();
		$tool   = (string) $tool;
		if ( ! isset( $policy[ $tool ] ) ) {
			return self::unknown_tool( $tool );
		}
		$cap = $policy[ $tool ]['cap'];

		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'ab_mcp_undo_forbidden',
				AB_MCP_Guidance::compose(
					/* translators: %s: capability name */
					sprintf( __( 'Undoing this needs the same permission as the original action (%s), and the account of this connection lacks it.', 'alphabridge-mcp' ), $cap ),
					__( 'To allow it, ask an administrator to give the account a role with that permission under Users, or connect the app again with an account that has it.', 'alphabridge-mcp' ),
					function_exists( 'admin_url' ) ? admin_url( 'users.php' ) : ''
				)
			);
		}

		// A tool that runs only on a full-scope token: its capability alone
		// would let a content token through, so the scope is asked directly.
		if ( ! empty( $policy[ $tool ]['full_scope'] ) && class_exists( 'AB_MCP_Auth' ) ) {
			$scope = (string) AB_MCP_Auth::current_scope();
			if ( '' !== $scope && 'full' !== $scope ) {
				return new WP_Error(
					'ab_mcp_undo_scope',
					AB_MCP_Guidance::compose(
						sprintf(
							/* translators: 1: name of the connection's access level, 2: tool, 3: name of the full access level */
							__( 'This connection has the access level "%1$s"; an undo point of "%2$s" is reversed or discarded only with the access level "%3$s", as the tool itself runs only with it.', 'alphabridge-mcp' ),
							self::scope_label( $scope ),
							$tool,
							self::scope_label( 'full' )
						),
						sprintf(
							/* translators: %s: name of the full access level */
							__( 'To allow it, run the undo on a connection whose access level includes that tool ("%s" includes every tool): connect the app again and choose it on the consent screen, or have an administrator create such a connection under Settings › AlphaBridge MCP.', 'alphabridge-mcp' ),
							self::scope_label( 'full' )
						),
						AB_MCP_Guidance::settings_url( 'ab-connections' )
					)
				);
			}
		}

		if ( class_exists( 'AB_MCP_Tool_Registry' ) && class_exists( 'AB_MCP_Auth' ) ) {
			$src_def = array( 'capability' => $cap );
			if ( ! AB_MCP_Tool_Registry::scope_allows( AB_MCP_Auth::current_scope(), $tool, $src_def ) ) {
				return new WP_Error(
					'ab_mcp_undo_scope',
					AB_MCP_Guidance::compose(
						sprintf(
							/* translators: 1: name of the connection's access level, 2: tool */
							__( 'This connection has the access level "%1$s", which does not include "%2$s", the tool this undo point came from.', 'alphabridge-mcp' ),
							self::scope_label( (string) AB_MCP_Auth::current_scope() ),
							$tool
						),
						sprintf(
							/* translators: %s: name of the full access level */
							__( 'To allow it, run the undo on a connection whose access level includes that tool ("%s" includes every tool): connect the app again and choose it on the consent screen, or have an administrator create such a connection under Settings › AlphaBridge MCP.', 'alphabridge-mcp' ),
							self::scope_label( 'full' )
						),
						AB_MCP_Guidance::settings_url( 'ab-connections' )
					)
				);
			}
		}
		return true;
	}

	/**
	 * The answer for an undo point of a tool not in policy(). A tool of
	 * AlphaBridge MCP Pro shares the store: its point is reversed where Pro
	 * runs, and expires here like any other. Facts only, as every answer about
	 * Pro in this plugin (AB_MCP_Guidance). Any other name cannot come from a
	 * tool and is refused as before.
	 *
	 * @param string $tool Source tool name.
	 * @return WP_Error
	 */
	private static function unknown_tool( $tool ) {
		if ( class_exists( 'AB_MCP_Guidance' ) && '' !== AB_MCP_Guidance::edition_of( $tool ) ) {
			return new WP_Error(
				'ab_mcp_undo_needs_pro',
				sprintf(
					/* translators: %s: tool name */
					__( 'This undo point came from "%s", a tool of AlphaBridge MCP Pro, which is not active here. It is reversed or discarded where Pro runs; here it expires on its own within the undo window, and wp_undo_list shows when.', 'alphabridge-mcp' ),
					(string) $tool
				)
			);
		}
		return new WP_Error( 'ab_mcp_undo_unknown_tool', __( 'This undo point came from a tool that cannot be reversed. It expires on its own within the undo window, and wp_undo_list shows when.', 'alphabridge-mcp' ) );
	}

	/**
	 * The name of an access level as the settings screen shows it.
	 *
	 * @param string $scope Slug.
	 * @return string
	 */
	private static function scope_label( $scope ) {
		$labels = class_exists( 'AB_MCP_Tool_Registry' ) && method_exists( 'AB_MCP_Tool_Registry', 'scope_labels' ) ? (array) AB_MCP_Tool_Registry::scope_labels() : array();
		return isset( $labels[ $scope ] ) ? (string) $labels[ $scope ] : (string) $scope;
	}

	/**
	 * Describe what a restore would do, without doing it. Also the first half of
	 * apply(): the same checks run, so a plan that says "allowed" and an apply
	 * that refuses cannot drift apart.
	 *
	 * @param array $entry Index entry.
	 * @param mixed $data  Decoded snapshot.
	 * @param array $opts  Unused by the kinds here; Pro passes page-builder options.
	 * @return array|WP_Error { tool, kind, items[], blocked[], restorable }
	 */
	public static function plan( array $entry, $data, array $opts = array() ) {
		$policy = self::policy();
		$tool   = isset( $entry['tool'] ) ? (string) $entry['tool'] : '';
		if ( ! isset( $policy[ $tool ] ) ) {
			return self::unknown_tool( $tool );
		}
		$p = $policy[ $tool ];
		if ( (string) $entry['kind'] !== $p['kind'] ) {
			return new WP_Error( 'ab_mcp_undo_kind_mismatch', __( 'The undo point does not match the tool it claims to come from. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$gate = self::may_touch( $tool );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		switch ( $p['kind'] ) {
			case 'post_status':
				return self::plan_post_status( $tool, $data );
			case 'site_settings':
				return self::plan_site_settings( $tool, $data );
			case 'post_fields':
				return self::plan_post_fields( $tool, $data );
		}
		return new WP_Error( 'ab_mcp_undo_unknown_kind', __( 'Unknown undo kind. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * What a partial restore left: the snapshot of the point with only what
	 * did not come back, for a new undo point that replaces this one (the
	 * undo tool records it and uses this one up). A retry then puts back only
	 * the rest, never again what came back the first time and may have been
	 * changed since. Null where nothing is left or nothing came back.
	 *
	 * Per kind, by the refs of apply(): post_status keeps the posts that did
	 * not come back; site_settings the settings; post_fields, one post, the
	 * fields a plan blocked one by one ("<id>:post_status"; a date both of
	 * its columns), or all of them where the post did not come back at all.
	 *
	 * @param array $entry Index entry.
	 * @param mixed $data  Decoded snapshot of the point.
	 * @param array $res   Result of apply().
	 * @return mixed|null The rest, in the form of the kind's snapshot.
	 */
	public static function remaining( array $entry, $data, array $res ) {
		$restored = isset( $res['restored'] ) ? array_map( 'strval', (array) $res['restored'] ) : array();
		$open     = array();
		foreach ( array_merge( (array) ( $res['blocked'] ?? array() ), (array) ( $res['failed'] ?? array() ) ) as $o ) {
			if ( is_array( $o ) && isset( $o['ref'] ) ) {
				$open[] = (string) $o['ref'];
			}
		}
		if ( array() === $restored || array() === $open || ! is_array( $data ) ) {
			return null;
		}
		switch ( isset( $entry['kind'] ) ? (string) $entry['kind'] : '' ) {
			case 'post_status':
				$rest = array_values(
					array_filter(
						$data,
						static function ( $pair ) use ( $restored ) {
							return is_array( $pair ) && isset( $pair[0] ) && ! in_array( (string) (int) $pair[0], $restored, true );
						}
					)
				);
				return array() !== $rest ? $rest : null;
			case 'site_settings':
				$rest = array_diff_key( $data, array_flip( $restored ) );
				return array() !== $rest ? $rest : null;
			case 'post_fields':
				if ( ! isset( $data['id'], $data['fields'] ) || ! is_array( $data['fields'] ) ) {
					return null;
				}
				$id = (string) (int) $data['id'];
				if ( ! in_array( $id, $restored, true ) ) {
					return $data;
				}
				$keep = array();
				foreach ( $open as $ref ) {
					if ( 0 === strpos( $ref, $id . ':' ) ) {
						$col    = substr( $ref, strlen( $id ) + 1 );
						$keep[] = $col;
						if ( 'post_date' === $col ) {
							$keep[] = 'post_date_gmt';
						}
					}
				}
				$fields = array_intersect_key( $data['fields'], array_flip( $keep ) );
				if ( array() === $fields ) {
					return null;
				}
				$rest           = $data;
				$rest['fields'] = $fields;
				return $rest;
		}
		return null;
	}

	/**
	 * Perform the restore. Items the plan marked blocked are skipped; the result
	 * reports both sides so a partial restore is visible rather than silent.
	 *
	 * @param array  $entry Index entry.
	 * @param mixed  $data  Decoded snapshot.
	 * @param array  $opts  See plan().
	 * @return array|WP_Error
	 */
	public static function apply( array $entry, $data, array $opts = array() ) {
		$plan = self::plan( $entry, $data, $opts );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$restored = array();
		$failed   = array();

		// A restore must not create an undo point of its own: it would double the
		// stored snapshots and make the semantics ("undo the undo") confusing.
		AB_MCP_Undo::suspend();
		try {
			foreach ( $plan['items'] as $item ) {
				$res = self::apply_item( $plan['kind'], $item );
				if ( is_wp_error( $res ) ) {
					$failed[] = array(
						'ref'    => $item['ref'],
						'reason' => $res->get_error_message(),
					);
					continue;
				}
				$restored[] = $item['ref'];
			}
		} finally {
			AB_MCP_Undo::resume();
		}

		$out = array(
			'undone'   => count( $restored ),
			'restored' => $restored,
			'blocked'  => $plan['blocked'],
			'failed'   => $failed,
			'kind'     => $plan['kind'],
			'tool'     => $plan['tool'],
		);
		return $out;
	}

	/**
	 * Which pages a restore of each kind can leave stale in a page cache: those
	 * of the posts it wrote ('post') or every page of the site ('site'). Every
	 * kind in policy() is listed here; one that is not counts as 'site'.
	 *
	 * @return array<string,string>
	 */
	public static function page_cache_scope() {
		return array(
			'post_status'   => 'post',
			'post_fields'   => 'post',
			// The settings reach every page: the site's name, tagline, the
			// number of posts on a list.
			'site_settings' => 'site',
		);
	}

	/**
	 * After a restore has been applied: drop the cached copies and the cached
	 * pages it left stale, then announce it on ab_mcp_after_restore. Called by
	 * wp_undo after the undo point's bookkeeping, so a slow or failing cache
	 * can neither hold the point's reservation nor cost the answer.
	 *
	 * An item that failed counts as well as one that was restored: a failure
	 * can come after the write (WordPress stored something else, or the post
	 * came out of the trash and the rest was refused). Only a restore that
	 * wrote nothing at all purges and announces nothing.
	 *
	 * @param array $res Result of apply().
	 * @return array { page_cache_purged: string[], page_cache_failed?: string[], page_cache_skipped?: array<string,string> }
	 */
	public static function after_restore( array $res ) {
		$kind  = isset( $res['kind'] ) ? (string) $res['kind'] : '';
		$tool  = isset( $res['tool'] ) ? (string) $res['tool'] : '';
		$refs  = isset( $res['restored'] ) ? (array) $res['restored'] : array();
		$fails = isset( $res['failed'] ) ? (array) $res['failed'] : array();
		foreach ( $fails as $f ) {
			if ( is_array( $f ) && isset( $f['ref'] ) ) {
				$refs[] = $f['ref'];
			}
		}
		$out = array( 'page_cache_purged' => array() );
		if ( empty( $refs ) ) {
			return $out;
		}

		$scopes   = self::page_cache_scope();
		$post_ids = array();
		if ( isset( $scopes[ $kind ] ) && 'post' === $scopes[ $kind ] ) {
			foreach ( $refs as $ref ) {
				if ( is_int( $ref ) || ( is_string( $ref ) && 1 === preg_match( '/\A[0-9]+\z/', $ref ) ) ) {
					$post_ids[] = (int) $ref;
				}
			}
			$post_ids = array_values( array_unique( $post_ids ) );
			$purge    = AB_MCP_Page_Cache::purge_posts( $post_ids );
		} else {
			$purge = AB_MCP_Page_Cache::purge_site();
		}

		try {
			/**
			 * Fires after wp_undo has restored something, once the page caches
			 * this plugin knows have been asked to purge. Hook it to purge a
			 * cache it does not know, or to log the restore.
			 *
			 * @param string $kind     Undo kind: post_status, post_fields, site_settings (Pro fires it for its kinds too).
			 * @param int[]  $post_ids The posts written; empty for the kinds that are not posts.
			 * @param string $tool     The tool whose change was undone.
			 */
			do_action( 'ab_mcp_after_restore', $kind, $post_ids, $tool );
		} catch ( \Throwable $e ) {
			$purge['failed'][] = 'ab_mcp_after_restore';
		}

		$out['page_cache_purged'] = $purge['purged'];
		if ( ! empty( $purge['failed'] ) ) {
			$out['page_cache_failed'] = $purge['failed'];
		}
		if ( ! empty( $purge['skipped'] ) ) {
			$out['page_cache_skipped'] = $purge['skipped'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ plans */

	/**
	 * @param string $tool Tool.
	 * @param mixed  $data Snapshot: [[id, old_status], ...]; an entry for a post
	 *                     that had a password carries 'password_protected' third.
	 * @return array|WP_Error
	 */
	private static function plan_post_status( $tool, $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$items   = array();
		$blocked = array();
		foreach ( $data as $pair ) {
			if ( ! is_array( $pair ) || ! in_array( count( $pair ), array( 2, 3 ), true ) ) {
				continue;
			}
			// A third entry is the note that the post had a password — the only
			// one the tool writes. Anything else there is a hand-edited snapshot.
			$protected = 3 === count( $pair );
			if ( $protected && ( ! array_key_exists( 2, $pair ) || 'password_protected' !== $pair[2] ) ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot carries a note in a form this tool does not write. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$id = (int) $pair[0];
			// The status must already be the key WordPress would store: it runs
			// the value through sanitize_key() when writing, so "PUBLISH" would
			// pass the capability check below as "not a publishing status" and
			// land as "publish" anyway. Not canonical = hand-edited = refused.
			if ( ! self::canonical_status( $pair[1] ) ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot carries a status in a form WordPress would rewrite. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$status = $pair[1];
			// Judged on the post as the table has it, as the write will find it.
			$post = self::fresh_post( $id );
			if ( ! $post ) {
				$blocked[] = array( 'ref' => $id, 'reason' => 'gone' );
				continue;
			}
			// The status goes back through the same write as the undo of
			// wp_update_post (restore_post_status()), so it is asked the same
			// questions: may it be set here at all, and would WordPress store it
			// with the post's date.
			$why = self::status_refusal( $id, $post, $status, null, $protected );
			if ( '' === $why ) {
				$why = self::date_status_fit( $post, $status, null );
			}
			if ( '' !== $why ) {
				$blocked[] = array( 'ref' => $id, 'reason' => $why );
				continue;
			}
			$item = array(
				'ref'  => $id,
				'to'   => $status,
				'from' => (string) $post->post_status,
			);
			// The checks before the write ask password_lost() again.
			if ( $protected ) {
				$item['password_protected'] = true;
			}
			$items[] = $item;
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'post_status',
			'items'      => $items,
			'blocked'    => $blocked,
			'restorable' => count( $items ),
		);
	}

	/**
	 * @param string $tool Tool.
	 * @param mixed  $data Snapshot: { option_name: old_value, ... }.
	 * @return array|WP_Error
	 */
	private static function plan_site_settings( $tool, $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is empty. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$items   = array();
		$blocked = array();
		foreach ( $data as $name => $value ) {
			$name = (string) $name;
			if ( '' === $name ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot names an option without a name. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			// Only the options wp_update_site_settings writes. Pro asks its
			// block list of options here; a list of the five is narrower and
			// needs no copy of it: a hand-edited index entry cannot reach any
			// other row through this kind.
			if ( ! in_array( $name, self::SITE_SETTINGS, true ) ) {
				$blocked[] = array( 'ref' => $name, 'reason' => 'protected_now' );
				continue;
			}
			$items[] = array(
				'ref'      => $name,
				'value'    => $value,
				'autoload' => null,
				'existed'  => true,
			);
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'site_settings',
			'items'      => $items,
			'blocked'    => $blocked,
			'restorable' => count( $items ),
		);
	}

	/* ----------------------------------------------------------------- applies */

	/**
	 * The post columns a post_fields snapshot may carry: exactly the ones the
	 * wp_update_post tool writes. Anything else is refused, so a hand-edited
	 * index entry cannot reach post_password or a column the tool never touches.
	 *
	 * @return string[]
	 */
	public static function post_fields_allowed() {
		return array_keys( self::post_field_types() );
	}

	/**
	 * The columns wp_update_post's undo may carry, each with the only form its
	 * value may take in a snapshot. Text is text, an id is a whole number, a
	 * status is a key WordPress would store unchanged. Anything else — a
	 * boolean where text belongs, a list where an id belongs — is not
	 * converted but refused, because PHP's casts would quietly turn false
	 * into "" and [999] into 1.
	 *
	 * @return array<string,string>
	 */
	public static function post_field_types() {
		return array(
			'post_title'   => 'text',
			'post_content' => 'text',
			'post_excerpt' => 'text',
			'post_status'  => 'status',
			'post_name'    => 'text',
			'post_parent'  => 'id',
			'post_author'  => 'id',
			'post_date'     => 'datetime',
			'post_date_gmt' => 'datetime',
		);
	}

	/**
	 * A date column exactly as WordPress stores it: "Y-m-d H:i:s" naming a real
	 * day and time of day, or the zero date a draft without a UTC date carries.
	 *
	 * @param mixed $value Value from a snapshot.
	 * @return bool
	 */
	private static function stored_datetime( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\z/', $value, $m ) ) {
			return false;
		}
		if ( '0000-00-00 00:00:00' === $value ) {
			return true;
		}
		return (int) $m[1] >= 1 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59;
	}

	/**
	 * A status exactly as WordPress stores it. wp_insert_post() passes the
	 * status through sanitize_key(), so "PUBLISH" or "pub!lish" become
	 * "publish" AFTER any check that compared the raw string. Only a string
	 * that sanitize_key() would leave as it is may enter a plan.
	 *
	 * @param mixed $status Value from a snapshot.
	 * @return bool
	 */
	public static function canonical_status( $status ) {
		return is_string( $status ) && '' !== $status && 1 === preg_match( '/\A[a-z0-9_-]+\z/', $status );
	}

	/**
	 * A whole number from a snapshot: an int, or a string of digits without a
	 * leading zero (JSON keeps ints as ints; the string form is accepted only
	 * in its canonical spelling). Negative numbers are refused.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function whole_number( $v ) {
		if ( is_int( $v ) ) {
			return $v >= 0;
		}
		// The round trip through (int) proves the string is in range: a longer
		// run of digits would come back as PHP_INT_MAX, not as itself.
		return is_string( $v ) && 1 === preg_match( '/\A(0|[1-9][0-9]*)\z/', $v ) && (string) (int) $v === $v;
	}

	/**
	 * @param string $status Status name.
	 * @return bool
	 */
	private static function status_registered( $status ) {
		return function_exists( 'get_post_stati' ) && in_array( $status, (array) get_post_stati(), true );
	}

	/**
	 * Why a status cannot be put back on this post, whatever its date: '' when
	 * it can. Both undo kinds that restore a status ask this, and then
	 * date_status_fit().
	 *
	 * @param int      $id        Post id.
	 * @param object   $post      The post as the table has it.
	 * @param string   $status    Status to restore.
	 * @param int|null $parent    Parent the same write puts back, or null.
	 * @param bool     $protected Whether the undo point notes that the post had a password.
	 * @return string
	 */
	private static function status_refusal( $id, $post, $status, $parent = null, $protected = false ) {
		if ( self::password_lost( $protected, $post, $status ) ) {
			return 'password_lost';
		}
		if ( ! self::status_registered( $status ) ) {
			// Only a status this site knows can be judged (public or not) and
			// stored as meant. One a plugin registered and has since taken away
			// is not restored on a guess.
			return 'unknown_status';
		}
		if ( ! AB_MCP_Post_Status::can_set_status( $id, $status, $parent ) ) {
			// Restoring a status is the same decision as setting it: the cascade
			// of the bulk tool (publish capability included) answers it — with
			// the parent this write puts back, since a file left to another
			// parent than before asks for the right to publish too.
			return 'forbidden';
		}
		if ( isset( $post->post_type ) && 'attachment' === $post->post_type && ! in_array( $status, array( 'inherit', 'private', 'trash', 'auto-draft' ), true ) ) {
			// wp_insert_post() gives an attachment any other status as "inherit".
			return 'attachment_status';
		}
		if ( null !== $parent && isset( $post->post_type ) && 'attachment' === $post->post_type
			&& 'trash' === (string) $post->post_status && 'inherit' === $status
			&& (int) $parent !== (int) $post->post_parent ) {
			// A file comes out of the trash with the status put back
			// (untrash_status()) under the parent it has now, and gets the other
			// parent only with the write after: for that moment an "inherit"
			// file is as visible as the parent it is leaving. It is not taken
			// out of the trash together with a new parent.
			return 'attachment_parent';
		}
		if ( 'trash' === $status && defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			// On a site without a trash, wp_trash_post() deletes for good.
			// Putting a post "back into the trash" must not do that.
			return 'no_trash';
		}
		return '';
	}

	/**
	 * Whether a status must not be put back because the post had a password
	 * when the undo point was taken and has none now. WordPress drops the
	 * password of a post it saves as private — in the call being undone or in
	 * any later one — and the undo does not keep passwords: on purpose, so no
	 * copy of one sits in the snapshots and a hand-edited snapshot cannot set
	 * one. Any status but "private" could then show the post without the
	 * protection it had, so the status is left as it is and the password is set
	 * again by hand. A post that has a password now keeps it through the
	 * restore, so its status may go back.
	 *
	 * @param bool   $protected Whether the undo point notes that the post had a password.
	 * @param object $post      The post as the table has it.
	 * @param string $status    Status to restore.
	 * @return bool
	 */
	private static function password_lost( $protected, $post, $status ) {
		return $protected && 'private' !== $status && '' === ( isset( $post->post_password ) ? (string) $post->post_password : '' );
	}

	/**
	 * Whether a status and a date pair, written together, would be stored as
	 * written: '' when they would, else the reason. The status is the one given,
	 * or the post's own when none is; the date pair the one given — written
	 * with edit_date, so WordPress keeps it — or the post's own. WordPress dates
	 * a local zero date at the save, and keeps a zero UTC date only for a post
	 * whose status is registered as date_floating: such a pair would not be
	 * stored as given. A post in the trash is judged as it will be when the
	 * status is written, out of the trash.
	 *
	 * @param object      $post   The post as it is now.
	 * @param string|null $status Status to write, or null.
	 * @param array|null  $pair   post_date and/or post_date_gmt to write, or null.
	 * @return string
	 */
	private static function date_status_fit( $post, $status, $pair ) {
		// Any status but "trash" goes onto a post in the trash only after
		// untrash_for_restore() has taken it out: the save starts from there.
		if ( null !== $status && 'trash' !== $status && 'trash' === (string) $post->post_status ) {
			$post              = clone $post;
			$post->post_status = self::untrash_status( isset( $post->post_type ) ? (string) $post->post_type : '', $status );
		}
		$target = null !== $status ? $status : (string) $post->post_status;
		$dated  = $post;
		if ( null !== $pair ) {
			$dated = clone $post;
			foreach ( $pair as $col => $value ) {
				$dated->$col = $value;
			}
			// The statuses WordPress keeps a zero UTC date for are the ones
			// registered as date_floating: draft, pending and auto-draft, and
			// any a plugin adds.
			$floating = function_exists( 'get_post_stati' ) ? array_values( (array) get_post_stati( array( 'date_floating' => true ) ) ) : array( 'draft', 'pending', 'auto-draft' );
			$zero     = '0000-00-00 00:00:00';
			if ( $zero === (string) $dated->post_date
				|| ( $zero === (string) $dated->post_date_gmt && ! in_array( $target, $floating, true ) ) ) {
				return 'date_unstorable';
			}
		}
		return self::status_date_conflict( $dated, $target );
	}

	/**
	 * Whether WordPress would store a different status than the one asked for,
	 * because of the post's date: "future" with a date that has passed becomes
	 * "publish", "publish" with a date still ahead becomes "future" — the same
	 * minute-granular comparison wp_insert_post() makes. And whether a "future"
	 * it would keep is too close to its date: with less than SCHEDULE_LEAD to
	 * go, a save a moment later could publish it early.
	 *
	 * @param object $post   The post as it will be dated.
	 * @param string $status Status to restore.
	 * @return string '' when the status would stick, otherwise 'date_passed', 'date_ahead' or 'too_close'.
	 */
	private static function status_date_conflict( $post, $status ) {
		if ( 'future' !== $status && 'publish' !== $status ) {
			return '';
		}
		// Attachments are exempt from the conversion in wp_insert_post().
		if ( isset( $post->post_type ) && 'attachment' === $post->post_type ) {
			return '';
		}
		$gmt = isset( $post->post_date_gmt ) ? (string) $post->post_date_gmt : '';
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			// wp_update_post() gives a draft, pending or auto-draft post without
			// a GMT date the date of NOW when no edit_date is passed — its local
			// date, however far ahead, does not count. Any other post keeps its
			// local date, which wp_insert_post() converts to GMT.
			$now_dated = self::dated_at_save( $post );
			$gmt       = ( $now_dated || ! isset( $post->post_date ) ) ? gmdate( 'Y-m-d H:i:s' ) : (string) get_gmt_from_date( (string) $post->post_date );
		}
		$lead  = strtotime( $gmt ) - strtotime( gmdate( 'Y-m-d H:i:s' ) );
		$ahead = $lead >= MINUTE_IN_SECONDS;
		if ( 'future' === $status && ! $ahead ) {
			return 'date_passed';
		}
		if ( 'publish' === $status && $ahead ) {
			return 'date_ahead';
		}
		// Saved as scheduled with less than SCHEDULE_LEAD to go: a save still
		// running, or a second one another plugin makes of the same post, could
		// come within the last minute and publish it early — the free core's
		// rule for the same case.
		if ( 'future' === $status && $lead < self::SCHEDULE_LEAD ) {
			return 'too_close';
		}
		return '';
	}

	/**
	 * @param string $tool Tool.
	 * @param mixed  $data Snapshot: { id, fields: { post_title: old, ... } }.
	 * @return array|WP_Error
	 */
	private static function plan_post_fields( $tool, $data ) {
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['fields'] ) || ! is_array( $data['fields'] ) || empty( $data['fields'] ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		if ( ! self::whole_number( $data['id'] ) || (int) $data['id'] <= 0 ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot does not name a post. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		// The recorder notes that the post had a password — the only value it
		// writes there is true.
		if ( array_key_exists( 'password_protected', $data ) && true !== $data['password_protected'] ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot carries a note in a form this tool does not write. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$protected = ! empty( $data['password_protected'] );
		$id        = (int) $data['id'];
		$types     = self::post_field_types();
		$fields = array();
		foreach ( $data['fields'] as $key => $value ) {
			// Fail closed on the shape: an unknown column, or a value in a form
			// the column does not take, makes the whole snapshot unreadable
			// rather than partly applied or quietly converted.
			if ( ! is_string( $key ) || ! isset( $types[ $key ] ) ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot carries a field this tool cannot restore. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$ok = false;
			switch ( $types[ $key ] ) {
				case 'text':
					$ok = is_string( $value );
					break;
				case 'status':
					$ok = self::canonical_status( $value );
					break;
				case 'id':
					$ok    = self::whole_number( $value );
					$value = $ok ? (int) $value : $value;
					break;
				case 'datetime':
					$ok = self::stored_datetime( $value );
					break;
			}
			if ( ! $ok ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot carries a value in a form this field does not take. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$fields[ $key ] = $value;
		}
		$empty = array(
			'tool'       => $tool,
			'kind'       => 'post_fields',
			'items'      => array(),
			'blocked'    => array(),
			'restorable' => 0,
		);
		// Judged on the post as the table has it: the write starts from the
		// row, and a check made on a stale cached copy would hold for a post
		// that is not the one written.
		$post = self::fresh_post( $id );
		if ( ! $post ) {
			$empty['blocked'][] = array( 'ref' => $id, 'reason' => 'gone' );
			return $empty;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			$empty['blocked'][] = array( 'ref' => $id, 'reason' => 'forbidden' );
			return $empty;
		}
		$blocked = array();
		if ( isset( $fields['post_status'] ) ) {
			// Whether it sticks with the date is judged below, together with
			// the date pair the snapshot may put back.
			$why = self::status_refusal( $id, $post, $fields['post_status'], isset( $fields['post_parent'] ) ? (int) $fields['post_parent'] : null, $protected );
			// A status that cannot be restored is left out and reported; the
			// other fields are still restored.
			if ( '' !== $why ) {
				unset( $fields['post_status'] );
				$blocked[] = array( 'ref' => $id . ':post_status', 'reason' => $why );
			}
		}
		// Same rule as the tool: only who may edit others' posts of this type
		// reassigns authorship.
		if ( isset( $fields['post_author'] ) ) {
			$pto = get_post_type_object( get_post_type( $id ) );
			$cap = ( $pto && isset( $pto->cap->edit_others_posts ) ) ? $pto->cap->edit_others_posts : 'edit_others_posts';
			if ( ! current_user_can( $cap ) ) {
				unset( $fields['post_author'] );
				$blocked[] = array( 'ref' => $id . ':post_author', 'reason' => 'forbidden' );
			}
		}
		// A file with "inherit" is as visible as its parent: a parent put back
		// that leaves the file to another post than before needs the right to
		// publish, as a status would (may_change_parent()) — judged with the
		// status the file then has. And every save gives a file the parent
		// WordPress stores, none where a loop runs through the file: a save
		// that would still move the file to another parent is not written.
		$status_after = isset( $fields['post_status'] ) ? (string) $fields['post_status'] : (string) $post->post_status;
		if ( isset( $fields['post_parent'] ) && ! AB_MCP_Post_Status::may_change_parent( $id, $status_after, (int) $fields['post_parent'] ) ) {
			unset( $fields['post_parent'] );
			$blocked[] = array( 'ref' => $id . ':post_parent', 'reason' => 'forbidden' );
		}
		if ( ! empty( $fields ) && ! isset( $fields['post_parent'] ) && ! AB_MCP_Post_Status::may_change_parent( $id, $status_after, null ) ) {
			$fields    = array();
			$blocked[] = array( 'ref' => $id, 'reason' => 'forbidden' );
		}
		// Status and date go to WordPress together, and WordPress settles
		// "publish" and "future" by the date: so the two are judged as the pair
		// they will be written as. Both where they fit; else the status alone,
		// with the post's own date; else the date pair alone, with the post's
		// own status; else neither. What is left out is reported, the other
		// fields are still restored.
		$status = isset( $fields['post_status'] ) ? (string) $fields['post_status'] : null;
		$pair   = array_intersect_key( $fields, array_flip( array( 'post_date', 'post_date_gmt' ) ) );
		$pair   = empty( $pair ) ? null : $pair;
		if ( null !== $status || null !== $pair ) {
			$both = self::date_status_fit( $post, $status, $pair );
			if ( '' !== $both ) {
				$status_only = ( null !== $status && null !== $pair ) ? self::date_status_fit( $post, $status, null ) : $both;
				$pair_only   = ( null !== $status && null !== $pair ) ? self::date_status_fit( $post, null, $pair ) : $both;
				$keep_status = null !== $status && null !== $pair && '' === $status_only;
				$keep_pair   = ! $keep_status && null !== $status && null !== $pair && '' === $pair_only;
				if ( null !== $status && ! $keep_status ) {
					unset( $fields['post_status'] );
					$blocked[] = array( 'ref' => $id . ':post_status', 'reason' => $status_only );
				}
				if ( null !== $pair && ! $keep_pair ) {
					unset( $fields['post_date'], $fields['post_date_gmt'] );
					$blocked[] = array( 'ref' => $id . ':post_date', 'reason' => '' !== $pair_only ? $pair_only : $both );
				}
			}
		}
		// Whatever is left is written with the status and date the post then
		// has, and WordPress settles "publish" and "future" on every save — a
		// title alone included: a scheduled post saved within the last minute
		// before its date is published. So the remaining write is judged as a
		// whole; if it would publish the post early or take it off the site,
		// nothing of it is written.
		if ( ! empty( $fields ) ) {
			$rest = self::date_status_fit(
				$post,
				isset( $fields['post_status'] ) ? (string) $fields['post_status'] : null,
				( isset( $fields['post_date'] ) || isset( $fields['post_date_gmt'] ) ) ? array_intersect_key( $fields, array_flip( array( 'post_date', 'post_date_gmt' ) ) ) : null
			);
			if ( '' !== $rest ) {
				$fields    = array();
				$blocked[] = array( 'ref' => $id, 'reason' => $rest );
			}
		}
		$items = array();
		if ( ! empty( $fields ) ) {
			$item = array(
				'ref'    => $id,
				'fields' => $fields,
			);
			// The checks before the write ask password_lost() again.
			if ( $protected && isset( $fields['post_status'] ) ) {
				$item['password_protected'] = true;
			}
			$items[] = $item;
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'post_fields',
			'items'      => $items,
			'blocked'    => $blocked,
			'restorable' => count( $items ),
		);
	}

	/**
	 * @param array $item Item.
	 * @return true|WP_Error
	 */
	protected static function restore_post_fields( array $item ) {
		$id     = (int) $item['ref'];
		$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		if ( empty( $fields ) ) {
			return true;
		}
		$status = isset( $fields['post_status'] ) ? (string) $fields['post_status'] : '';
		$write  = $fields;
		unset( $write['post_status'] );
		// The plan was judged a moment ago; the write goes to the post as it
		// is now. So the same write is judged again, before anything changes,
		// on the row it will start from: a post rescheduled in between would
		// otherwise be written on a check that no longer holds for it.
		$pair  = array_intersect_key( $fields, array_flip( array( 'post_date', 'post_date_gmt' ) ) );
		$basis = self::fresh_post( $id );
		if ( ! $basis ) {
			return new WP_Error( 'ab_mcp_undo_post_gone', __( 'The post no longer exists; nothing was restored. wp_undo_discard removes this undo point.', 'alphabridge-mcp' ) );
		}
		$parent    = isset( $fields['post_parent'] ) ? (int) $fields['post_parent'] : null;
		$protected = ! empty( $item['password_protected'] );
		$why       = '' !== $status ? self::status_refusal( $id, $basis, $status, $parent, $protected ) : '';
		// The parent the save leaves the file with — the one put back, or the
		// one it keeps, none where a loop runs through it. With a status,
		// status_refusal() has asked this already; without one, only this does.
		if ( '' === $why && ! AB_MCP_Post_Status::may_change_parent( $id, '' !== $status ? $status : (string) $basis->post_status, $parent ) ) {
			$why = 'forbidden';
		}
		if ( '' === $why ) {
			$why = self::date_status_fit( $basis, '' !== $status ? $status : null, empty( $pair ) ? null : $pair );
		}
		if ( '' !== $why ) {
			return new WP_Error(
				'ab_mcp_undo_post_changed',
				sprintf(
					/* translators: %s: reason code, e.g. date_passed */
					__( 'The post changed after the undo was planned, and the restore can no longer be written as planned (%s); nothing was restored. The undo point stays: wp_undo with dry_run=true shows what it would restore now, and wp_undo runs it again.', 'alphabridge-mcp' ),
					$why
				)
			);
		}
		$status_before = (string) $basis->post_status;
		// The trash is a workflow, not a column: wp_trash_post() and
		// wp_untrash_post() keep the pre-trash status and the comments' states
		// and run the hooks other plugins listen to; a bare post_status write
		// would not. The move comes FIRST and is verified by reading the post
		// again — not by the return value, which a pre_trash_post or
		// pre_untrash_post filter can turn into anything — so a refused move
		// leaves nothing half-restored on a post that is still live.
		if ( 'trash' === $status && 'trash' !== self::fresh_status( $id ) ) {
			AB_MCP_Post_Status::trash_post( $id );
			if ( 'trash' !== self::fresh_status( $id ) ) {
				return new WP_Error( 'ab_mcp_undo_trash_failed', __( 'The post could not be moved to the trash; nothing was restored. Try again, or move it in wp-admin.', 'alphabridge-mcp' ) );
			}
		}
		if ( '' !== $status && 'trash' !== $status ) {
			if ( 'trash' === self::fresh_status( $id ) ) {
				self::untrash_for_restore( $id, $status );
				$now = self::fresh_status( $id );
				if ( '' === $now || 'trash' === $now ) {
					return new WP_Error( 'ab_mcp_undo_untrash_failed', __( 'The post could not be taken out of the trash; nothing was restored. Try again, or restore it from the trash in wp-admin.', 'alphabridge-mcp' ) );
				}
				// The checks above judged the post as untrash_for_restore()
				// gives it back; a plugin may still change it on the way (on
				// untrashed_post, say). The write starts from the row as it is
				// now, so that row is asked the same questions once more.
				$basis = self::fresh_post( $id );
				$why   = $basis ? self::status_refusal( $id, $basis, $status, $parent, $protected ) : 'gone';
				if ( '' === $why ) {
					$why = self::date_status_fit( $basis, $status, empty( $pair ) ? null : $pair );
				}
				if ( '' !== $why ) {
					return new WP_Error(
						'ab_mcp_undo_post_changed',
						sprintf(
							/* translators: 1: status, 2: reason code, e.g. date_passed */
							__( 'The post was taken out of the trash as "%1$s", but WordPress would not store the rest of the restore as planned (%2$s); nothing else was written. Check the post with wp_get_post and set the rest with wp_update_post.', 'alphabridge-mcp' ),
							$now,
							$why
						)
					);
				}
			}
			$write['post_status'] = $status;
		}
		// An undated draft that is published is dated at the save, from the
		// site's local time, which WordPress then reads back into UTC — the
		// night the clock goes back, an hour ahead, and "publish" an hour ahead
		// is stored as "future". So the date is passed along: now, in both
		// columns, as the free core does. ("future" does not get here: dated
		// now, it would be published, and the checks above refuse it.)
		if ( empty( $pair ) && in_array( $status, array( 'publish', 'future' ), true ) && self::dated_at_save( $basis ) ) {
			$utc                    = new DateTimeImmutable( '@' . time() );
			$write['post_date']     = $utc->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
			$write['post_date_gmt'] = $utc->format( 'Y-m-d H:i:s' );
		}
		// A date put back goes with edit_date: without it wp_update_post()
		// would date a draft without a UTC date at the save instead. The
		// planner has already left out a pair WordPress would not store as
		// given; the read-back below still reports any other difference.
		if ( isset( $write['post_date'] ) || isset( $write['post_date_gmt'] ) ) {
			$write['edit_date'] = true;
		}
		if ( ! empty( $write ) ) {
			// wp_update_post() starts from get_post(), i.e. from the object cache
			// — which still holds the pre-trash post when cache invalidation is
			// suspended (importers do that) — and writes back every column it
			// finds there, post_status included. Drop the cached copy first, so
			// the merge starts from the table row the checks above looked at.
			wp_cache_delete( $id, 'posts' );
			// Same rule as the tool: wp_update_post() hands the array straight to
			// wp_insert_post(), which unslashes it. Without $wp_error, as in the
			// tool: WordPress saves a post's page template along, and where it
			// is gone it would give up with $wp_error after the row is written,
			// before the hooks. Without it, it falls back to the default
			// template and finishes the save; 0 means nothing was written, and
			// the read-back below reports what the site stored.
			$res = wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $write ) ) );
			if ( ! $res || is_wp_error( $res ) ) {
				return new WP_Error( 'ab_mcp_undo_write_failed', __( 'WordPress did not save the post; nothing was restored for it. Try again; wp_get_post shows how it is now.', 'alphabridge-mcp' ) );
			}
		}
		// What the site stored is not always what was asked: a "future" whose
		// date has passed is published, a slug is made unique, a body is
		// filtered for an account without unfiltered_html. Read back and say
		// so, instead of reporting a restore that did not happen as asked.
		$row = self::fresh_row( $id );
		$off = array();
		foreach ( $fields as $col => $want ) {
			$have = ( is_array( $row ) && array_key_exists( $col, $row ) ) ? $row[ $col ] : null;
			$same = in_array( $col, array( 'post_parent', 'post_author' ), true )
				? ( (int) $have === (int) $want )
				: ( (string) $have === (string) $want );
			if ( ! $same ) {
				$off[] = $col;
			}
		}
		// A write without a status can still change it: WordPress settles
		// "publish" and "future" on every save, and a plugin may set a status
		// of its own. The check above says it will not; the row says whether
		// it did.
		if ( '' === $status && is_array( $row ) && isset( $row['post_status'] ) && (string) $row['post_status'] !== $status_before ) {
			$off[] = 'post_status';
		}
		if ( $off ) {
			return new WP_Error(
				'ab_mcp_undo_post_mismatch',
				sprintf(
					/* translators: %s: comma-separated column names */
					__( 'The post was written, but the site stored something else for: %s. Check the post with wp_get_post; a plugin may rewrite these fields on save.', 'alphabridge-mcp' ),
					implode( ', ', $off )
				)
			);
		}
		return true;
	}

	/**
	 * The restorable columns of a post as the TABLE has them now: not through
	 * the object cache, which may be stale or suspended, and not through
	 * get_post_status()'s filter, which a plugin can make say anything.
	 *
	 * @param int $id Post id.
	 * @return array<string,string>|null Null when the post is gone.
	 */
	private static function fresh_row( $id ) {
		global $wpdb;
		$cols = implode( ', ', self::post_fields_allowed() );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$wpdb->posts} WHERE ID = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The post's status as the table has it now, '' when the post is gone.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	private static function fresh_status( $id ) {
		$row = self::fresh_row( $id );
		return ( null === $row || ! isset( $row['post_status'] ) ) ? '' : (string) $row['post_status'];
	}

	/**
	 * The post as the table has it now. The cached copy is dropped first, so
	 * get_post() reads the row — the one wp_update_post() starts from when the
	 * restore writes, since it drops the cached copy the same way — and not a
	 * copy the object cache still holds from before a change it was not told
	 * about (importers suspend the invalidation).
	 *
	 * @param int $id Post id.
	 * @return WP_Post|null Null when the post is gone.
	 */
	private static function fresh_post( $id ) {
		wp_cache_delete( (int) $id, 'posts' );
		$post = get_post( (int) $id );
		return $post ? $post : null;
	}

	/**
	 * Whether wp_update_post() dates this post at the save when no date is
	 * passed: a draft, pending or auto-draft post without a UTC date.
	 *
	 * @param object $post The post as it is before the save.
	 * @return bool
	 */
	private static function dated_at_save( $post ) {
		$gmt = isset( $post->post_date_gmt ) ? (string) $post->post_date_gmt : '';
		return isset( $post->post_status ) && in_array( (string) $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true )
			&& ( '' === $gmt || '0000-00-00 00:00:00' === $gmt );
	}

	/**
	 * The status a post comes out of the trash with, on its way to the one the
	 * restore puts back: a draft, which nobody sees and which WordPress never
	 * turns into another status by the date. A file comes out with the status
	 * the restore puts back: WordPress never converts a file's status by the
	 * date, "inherit" — its own default for a file — is public wherever the
	 * file has no parent or a published one, and "private" would drop a
	 * password the file has.
	 *
	 * @param string $type   Post type.
	 * @param string $status Status the restore puts back.
	 * @return string
	 */
	private static function untrash_status( $type, $status ) {
		return 'attachment' === $type ? (string) $status : 'draft';
	}

	/**
	 * Take a post out of the trash with untrash_status(), whatever status a
	 * plugin would have the trash give back: wp_untrash_post_set_previous_status()
	 * gives back the one from before the trash, which WordPress saves with the
	 * post's date as it is, and a "future" whose date has passed would be
	 * published on the way. The restore writes the status it planned right
	 * after.
	 *
	 * @param int    $id     Post id.
	 * @param string $status Status the restore puts back.
	 */
	private static function untrash_for_restore( $id, $status ) {
		$id = (int) $id;
		AB_MCP_Post_Status::untrash_as( $id, self::untrash_status( (string) get_post_type( $id ), $status ) );
	}

	/**
	 * Dispatch one planned item. Protected so a test can subclass and replace
	 * the write paths with recording stand-ins.
	 *
	 * @param string $kind Kind.
	 * @param array  $item Planned item.
	 * @return true|WP_Error
	 */
	protected static function apply_item( $kind, array $item ) {
		switch ( $kind ) {
			case 'post_status':
				return static::restore_post_status( $item );
			case 'site_settings':
				return static::restore_setting( $item );
			case 'post_fields':
				return static::restore_post_fields( $item );
		}
		return new WP_Error( 'ab_mcp_undo_unknown_kind', __( 'Unknown undo kind. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param array $item Item.
	 * @return true|WP_Error
	 */
	protected static function restore_post_status( array $item ) {
		// The same write as the undo of wp_update_post, with the status alone:
		// the trash moves, the checks on the row the write starts from, the
		// date an undated draft gets when it is published, and the read-back.
		$fields_item = array(
			'ref'    => (int) $item['ref'],
			'fields' => array( 'post_status' => (string) $item['to'] ),
		);
		if ( ! empty( $item['password_protected'] ) ) {
			$fields_item['password_protected'] = true;
		}
		return static::restore_post_fields( $fields_item );
	}

	/**
	 * Write one site setting back (kind site_settings). The undo point holds
	 * the value each setting had; plan_site_settings() lets only the options
	 * of wp_update_site_settings through, and those are WordPress's own, so
	 * the row is there and keeps its autoload flag. Pro's restore of a raw
	 * option (create again, delete again, put a flag back) is not needed for
	 * them. Every write is read back: update_option() cannot tell "failed"
	 * from "already that value", and a filter may rewrite what it stores.
	 *
	 * @param array $item Item: ref (option name), value.
	 * @return true|WP_Error
	 */
	protected static function restore_setting( array $item ) {
		$name = (string) $item['ref'];
		if ( null === AB_MCP_Undo::option_autoload( $name ) ) {
			// Not there right now: a plain add restores it and can lose
			// nothing. add_option() reports that it inserted a row, not what
			// the row holds or that it is still there, so the site is asked.
			add_option( $name, $item['value'] );
			if ( null !== AB_MCP_Undo::option_autoload( $name ) && self::option_value_matches( $name, $item['value'] ) ) {
				return true;
			}
			if ( self::write_option_back( $name, $item['value'] ) && null !== AB_MCP_Undo::option_autoload( $name ) ) {
				return true;
			}
			return new WP_Error( 'ab_mcp_undo_option_failed', __( 'The setting was missing and could not be added again. Try again; wp_get_site_settings shows what the site holds now.', 'alphabridge-mcp' ) );
		}
		// The value alone is not proof the row survived: get_option() is
		// filterable, and a default_option filter can answer for an option an
		// updated_option hook has just deleted — with the very value asked for.
		$landed = self::write_option_back( $name, $item['value'] );
		if ( $landed && null !== AB_MCP_Undo::option_autoload( $name ) ) {
			return true;
		}
		return new WP_Error( 'ab_mcp_undo_option_failed', __( 'The setting was written back, but the site stored something else; a plugin may filter it. wp_get_site_settings shows what it holds now.', 'alphabridge-mcp' ) );
	}

	/**
	 * Write an option back and READ IT BACK, because update_option's return
	 * value cannot distinguish "failed" from "already that value": a
	 * pre_update_option filter that rejects or rewrites the value would
	 * otherwise let a restore report success while the stored value never
	 * became the one from the snapshot. The stored value is the only honest
	 * success signal.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value from the snapshot.
	 * @return bool True when the option really holds the snapshot value.
	 */
	protected static function write_option_back( $name, $value ) {
		update_option( $name, $value );
		return self::option_value_matches( $name, $value );
	}

	/**
	 * Does the option now hold the value the snapshot has? Kept apart from the
	 * writing so an add_option() can be proven WITHOUT writing a second time —
	 * add_option reports that it inserted a row, not what the row ended up
	 * holding.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value from the snapshot.
	 * @return bool
	 */
	protected static function option_value_matches( $name, $value ) {
		// The comparison rules moved to the options class when the write tools
		// started proving their own writes — one set of rules, shared, so the
		// tool's idea of "stored" and the restore's cannot drift apart.
		return AB_MCP_Tools_Options_Settings::option_value_matches( $name, $value );
	}

}
