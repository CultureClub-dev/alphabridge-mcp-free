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
 * (wp_update_site_settings); since 4.10.0 also builder_data and option_rows
 * (wp_update_builder_element, wp_search_replace), since 4.11.0 the theme
 * (wp_activate_theme), since 4.12.0 builder_data of the posts a plugin's
 * ability changed (wp_run_ability), and since 4.13.0 meta: the meta of a
 * post, a term or a user, and the alternative text, title and caption of a
 * file (wp_update_post_meta, wp_update_term_meta, wp_update_user_meta,
 * wp_update_media); also since 4.13.0 term (a term or a menu created, the
 * fields of a term changed: wp_create_term, wp_create_menu,
 * wp_update_term), menu_item (wp_add_menu_item) and widget (the option rows
 * wp_update_widget, wp_add_widget and wp_delete_widget write), planned and
 * written by AB_MCP_Undo_Terms, AB_MCP_Undo_Menu_Items and
 * AB_MCP_Undo_Widgets. The undo points of Pro's tools (options, files, PHP
 * snippets) share the store; Pro reverses them with its own wp_undo, this
 * class names them and leaves them alone.
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
			// Free since 4.11.0: switched back the way wp_activate_theme switches.
			'wp_activate_theme'       => array( 'kind' => 'theme',         'cap' => 'switch_themes' ),
			// Page builders (free since 4.10.0): edit_post per post is asked by
			// the plan, on top of the tool's capability; a point of a raw
			// replace also needs unfiltered_html (AB_MCP_Undo_Builder::plan()).
			'wp_update_builder_element' => array( 'kind' => 'builder_data', 'cap' => 'edit_posts' ),
			// wp_search_replace keeps the option rows it changed apart from
			// the pages (also, free since 4.10.0): their old values, written
			// back as the option tools write.
			'wp_search_replace'         => array( 'kind' => 'builder_data', 'cap' => 'manage_options', 'also' => array( 'option_rows' ) ),
			// A plugin's ability, run by wp_run_ability (free since 4.12.0):
			// edit_post per post is asked by the plan, as for a builder page.
			// The tool runs only on a full-scope token, so its undo point
			// does too: "full_scope" (may_touch()).
			'wp_run_ability'            => array( 'kind' => 'builder_data', 'cap' => 'edit_posts', 'full_scope' => true ),
			// Meta (free since 4.13.0), each with the capability of its tool:
			// the right to the object and to the key is asked by the plan,
			// with the key rules of the tool (AB_MCP_Undo_Meta::refusal()).
			'wp_update_post_meta'       => array( 'kind' => 'meta', 'cap' => 'edit_posts' ),
			'wp_update_term_meta'       => array( 'kind' => 'meta', 'cap' => 'manage_categories' ),
			'wp_update_user_meta'       => array( 'kind' => 'meta', 'cap' => 'edit_users' ),
			'wp_update_media'           => array( 'kind' => 'meta', 'cap' => 'upload_files' ),
			// Terms, menus and widgets (free since 4.13.0), each with the
			// capability of its tool; the rights to the term, the item or
			// the widget row are asked by the plan of the kind's class.
			'wp_create_term'            => array( 'kind' => 'term', 'cap' => 'manage_categories' ),
			'wp_update_term'            => array( 'kind' => 'term', 'cap' => 'manage_categories' ),
			'wp_create_menu'            => array( 'kind' => 'term', 'cap' => 'edit_theme_options' ),
			'wp_add_menu_item'          => array( 'kind' => 'menu_item', 'cap' => 'edit_theme_options' ),
			'wp_update_widget'          => array( 'kind' => 'widget', 'cap' => 'edit_theme_options' ),
			'wp_add_widget'             => array( 'kind' => 'widget', 'cap' => 'edit_theme_options' ),
			'wp_delete_widget'          => array( 'kind' => 'widget', 'cap' => 'edit_theme_options' ),
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
	 * @param array $opts  force (bool): restore pages or meta changed since (builder_data, meta);
	 *                     parts (array): decoded snapshots of the point's parts.
	 * @return array|WP_Error { tool, kind, items[], blocked[], restorable }
	 */
	public static function plan( array $entry, $data, array $opts = array() ) {
		$policy = self::policy();
		$tool   = isset( $entry['tool'] ) ? (string) $entry['tool'] : '';
		if ( ! isset( $policy[ $tool ] ) ) {
			return self::unknown_tool( $tool );
		}
		$p    = $policy[ $tool ];
		$kind = (string) $entry['kind'];
		if ( $kind !== $p['kind'] && ! in_array( $kind, isset( $p['also'] ) ? (array) $p['also'] : array(), true ) ) {
			return new WP_Error( 'ab_mcp_undo_kind_mismatch', __( 'The undo point does not match the tool it claims to come from. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$gate = self::may_touch( $tool );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		switch ( $kind ) {
			case 'post_status':
				return self::plan_post_status( $tool, $data );
			case 'site_settings':
				return self::plan_site_settings( $tool, $data );
			case 'theme':
				return self::plan_theme( $tool, $data );
			case 'post_fields':
				return self::plan_post_fields( $tool, $data );
			case 'builder_data':
				if ( ! class_exists( 'AB_MCP_Undo_Builder' ) ) {
					require_once dirname( __DIR__ ) . '/builders/write/load.php';
				}
				return AB_MCP_Undo_Builder::plan(
					$tool,
					$data,
					isset( $opts['parts'] ) && is_array( $opts['parts'] ) ? $opts['parts'] : array(),
					isset( $entry['after'] ) && is_array( $entry['after'] ) ? $entry['after'] : array(),
					! empty( $opts['force'] )
				);
			case 'option_rows':
				return self::plan_option_rows( $tool, $data );
			case 'meta':
				return self::plan_meta( $tool, $data, ! empty( $opts['force'] ) );
			case 'term':
			case 'menu_item':
			case 'widget':
				$class = self::object_kinds()[ $kind ];
				return $class::plan( $tool, $data, ! empty( $opts['force'] ) );
		}
		return new WP_Error( 'ab_mcp_undo_unknown_kind', __( 'Unknown undo kind. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * What a partial restore left out, as the snapshot of a new undo point:
	 * the items that were blocked or failed, and of a post whose fields came
	 * back only in part, the fields left out. null where nothing came back or
	 * nothing is left. A point of one item (an option, a file, a snippet, a
	 * theme) is never partly restored. wp_undo records it in place of the
	 * point it came from, so a retry never writes back what came back already
	 * — over whatever changed it since.
	 *
	 * @param array $entry Index entry.
	 * @param mixed $data  Decoded snapshot.
	 * @param array $res   Result of apply().
	 * @param array $parts Decoded snapshots of the point's parts (builder_data).
	 * @return array|null { data, items, after?, parts? }
	 */
	public static function remaining( array $entry, $data, array $res, array $parts = array() ) {
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
				return array() !== $rest ? array( 'data' => $rest, 'items' => count( $rest ) ) : null;
			case 'site_settings':
				$rest = array_diff_key( $data, array_flip( $restored ) );
				return array() !== $rest ? array( 'data' => $rest, 'items' => count( $rest ) ) : null;
			case 'post_fields':
				if ( ! isset( $data['id'], $data['fields'] ) || ! is_array( $data['fields'] ) ) {
					return null;
				}
				$id = (string) (int) $data['id'];
				if ( ! in_array( $id, $restored, true ) ) {
					return array( 'data' => $data, 'items' => 1 );
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
				return array( 'data' => $rest, 'items' => 1 );
			case 'option_rows':
				$rest = array_values(
					array_filter(
						$data,
						static function ( $row ) use ( $restored ) {
							return is_array( $row ) && isset( $row['name'] ) && ! in_array( (string) $row['name'], $restored, true );
						}
					)
				);
				return array() !== $rest ? array( 'data' => $rest, 'items' => count( $rest ) ) : null;
			case 'builder_data':
				if ( ! class_exists( 'AB_MCP_Undo_Builder' ) ) {
					return null;
				}
				if ( is_wp_error( AB_MCP_Undo_Builder::items_of( $data, $parts ) ) ) {
					return null;
				}
				// Part by part, as the point was stored: each part was
				// measured against the limits on its own, and the rest of
				// all of them together might not fit one snapshot.
				$snaps = array();
				$after = array();
				foreach ( array_merge( array( $data ), $parts ) as $snap ) {
					$keep = array();
					foreach ( $snap['posts'] as $item ) {
						$id = (string) (int) $item['id'];
						if ( in_array( $id, $restored, true ) ) {
							continue;
						}
						$keep[] = $item;
						if ( isset( $entry['after'][ $id ] ) && is_array( $entry['after'][ $id ] ) ) {
							$after[ $id ] = $entry['after'][ $id ];
						}
					}
					if ( array() !== $keep ) {
						$snaps[] = AB_MCP_Undo_Builder::data( (string) $snap['scope'], $keep );
					}
				}
				if ( array() === $snaps ) {
					return null;
				}
				$first = array_shift( $snaps );
				// The first counts its own pages; each part adds its own when
				// it is appended (append_part()), as when it was recorded.
				return array(
					'data'  => $first,
					'parts' => $snaps,
					'items' => count( $first['posts'] ),
					'after' => $after,
				);
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
		$probes   = array();

		// A restore must not create an undo point of its own: it would double the
		// stored snapshots and make the semantics ("undo the undo") confusing.
		$hold = isset( $opts['hold'] ) && is_callable( $opts['hold'] ) ? $opts['hold'] : null;
		$lost = false;
		AB_MCP_Undo::suspend();
		try {
			foreach ( $plan['items'] as $item ) {
				// The reservation first: a restore that outlived it writes
				// nothing more once another request has the point.
				if ( null !== $hold && ! call_user_func( $hold ) ) {
					$lost = true;
					break;
				}
				$res = self::apply_item( $plan['kind'], $item );
				if ( is_wp_error( $res ) ) {
					$failed[] = array(
						'ref'    => $item['ref'],
						'reason' => $res->get_error_message(),
					);
					continue;
				}
				$restored[] = $item['ref'];
				// A theme switched back: what the test requests afterwards
				// found, or a warning (see restore_theme()).
				if ( is_array( $res ) && ( isset( $res['probe'] ) || isset( $res['warning'] ) ) ) {
					$probes[] = array( 'ref' => $item['ref'] ) + $res;
				}
			}
		} finally {
			AB_MCP_Undo::resume();
		}
		// And once more after the last item, which may itself have outlived
		// the reservation: the bookkeeping is then left to the new holder.
		if ( ! $lost && null !== $hold && array() !== $plan['items'] && ! call_user_func( $hold ) ) {
			$lost = true;
		}

		$out = array(
			'undone'   => count( $restored ),
			'restored' => $restored,
			'blocked'  => $plan['blocked'],
			'failed'   => $failed,
			'kind'     => $plan['kind'],
			'tool'     => $plan['tool'],
		);
		if ( $lost ) {
			$out['taken_over'] = true;
		}
		if ( 'builder_data' === $plan['kind'] && class_exists( 'AB_MCP_Undo_Builder' ) ) {
			$warnings = AB_MCP_Undo_Builder::take_warnings();
			if ( array() !== $warnings ) {
				$out['warnings'] = $warnings;
			}
		}
		if ( $probes ) {
			$out['probe'] = $probes;
		}
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
			// Raw copies of builder pages: the pages of the posts written.
			'builder_data'  => 'post',
			// Option rows a replace changed: an option can reach every page.
			'option_rows'   => 'site',
			// The theme reaches every page: menus, widgets, layout.
			'theme'         => 'site',
			// The meta of a post or a file: the pages of that post. The meta
			// of a term or a user reaches pages no post id names (archives,
			// author pages): their refs are no post id, and after_restore()
			// purges every page for them.
			'meta'          => 'post',
			// A term or a menu reaches archives and every page that shows a
			// menu; a widget every page with its sidebar.
			'term'          => 'site',
			'menu_item'     => 'site',
			'widget'        => 'site',
		);
	}

	/**
	 * The kinds of one object written through WordPress (since 4.13.0) and
	 * the class that plans and writes each, loaded on first use.
	 *
	 * @return array<string,string>
	 */
	private static function object_kinds() {
		$kinds = array(
			'term'      => array( 'AB_MCP_Undo_Terms', 'class-undo-terms.php' ),
			'menu_item' => array( 'AB_MCP_Undo_Menu_Items', 'class-undo-menu-items.php' ),
			'widget'    => array( 'AB_MCP_Undo_Widgets', 'class-undo-widgets.php' ),
		);
		$out = array();
		foreach ( $kinds as $kind => $c ) {
			if ( ! class_exists( $c[0], false ) ) {
				require_once __DIR__ . '/' . $c[1];
			}
			$out[ $kind ] = $c[0];
		}
		return $out;
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
		$other    = false;
		if ( isset( $scopes[ $kind ] ) && 'post' === $scopes[ $kind ] ) {
			foreach ( $refs as $ref ) {
				if ( is_int( $ref ) || ( is_string( $ref ) && 1 === preg_match( '/\A[0-9]+\z/', $ref ) ) ) {
					$post_ids[] = (int) $ref;
				} elseif ( 'meta' === $kind ) {
					// The meta of a term or a user ("term:3", "user:7").
					$other = true;
				}
			}
			$post_ids = array_values( array_unique( $post_ids ) );
		}
		if ( isset( $scopes[ $kind ] ) && 'post' === $scopes[ $kind ] && ! $other ) {
			$purge = AB_MCP_Page_Cache::purge_posts( $post_ids );
		} else {
			$purge = AB_MCP_Page_Cache::purge_site();
		}

		try {
			/**
			 * Fires after wp_undo has restored something, once the page caches
			 * this plugin knows have been asked to purge. Hook it to purge a
			 * cache it does not know, or to log the restore.
			 *
			 * @param string $kind     Undo kind: post_status, post_fields, site_settings, builder_data, option_rows, theme, meta (Pro fires it for its kinds too).
			 * @param int[]  $post_ids The posts written; empty for the kinds that are not posts, and for the meta of a term or a user.
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
	 * The option rows wp_search_replace changed: each written back as it was.
	 * The guard is asked again now, not when the snapshot was taken: an option
	 * that is protected since, or a hand-edited entry naming one, is blocked.
	 *
	 * @param string $tool Tool.
	 * @param mixed  $data Snapshot: list of { name, existed, value, autoload }.
	 * @return array|WP_Error
	 */
	private static function plan_option_rows( $tool, $data ) {
		if ( ! is_array( $data ) || array() === $data || ! array_is_list( $data ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$items   = array();
		$blocked = array();
		$seen    = array();
		foreach ( $data as $row ) {
			// Only rows that were there: the replace changes existing rows,
			// and a row claiming otherwise would read as "delete it again".
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) || '' === $row['name']
				|| true !== ( $row['existed'] ?? null ) || ! array_key_exists( 'value', $row ) || isset( $seen[ $row['name'] ] ) ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$seen[ $row['name'] ] = true;
			if ( AB_MCP_Option_Guard::option_write_refused( $row['name'] ) ) {
				$blocked[] = array(
					'ref'    => $row['name'],
					'reason' => 'protected_now',
				);
				continue;
			}
			$items[] = array(
				'ref'      => $row['name'],
				'value'    => $row['value'],
				// For a row gone since: added again with its old flag.
				'autoload' => isset( $row['autoload'] ) ? AB_MCP_Undo::normalize_autoload( $row['autoload'] ) : null,
			);
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'option_rows',
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

	/**
	 * The meta tools (kind meta): the key or keys of one object, planned by
	 * AB_MCP_Undo_Meta (rights, key rules, later changes). The title and
	 * caption wp_update_media keeps with them are asked the questions of the
	 * undo of wp_update_post too: a write of them that would be refused or
	 * stored otherwise blocks the whole point, which is one item and never
	 * partly restored.
	 *
	 * @param string $tool  Tool.
	 * @param mixed  $data  Snapshot (AB_MCP_Undo_Meta).
	 * @param bool   $force Restore over later changes.
	 * @return array|WP_Error
	 */
	private static function plan_meta( $tool, $data, $force ) {
		if ( ! class_exists( 'AB_MCP_Undo_Meta' ) ) {
			require_once __DIR__ . '/class-undo-meta.php';
		}
		$plan = AB_MCP_Undo_Meta::plan( $tool, $data, $force );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$items   = array();
		$blocked = $plan['blocked'];
		foreach ( $plan['items'] as $item ) {
			$fields = isset( $item['snap']['fields'] ) ? (array) $item['snap']['fields'] : array();
			if ( array() !== $fields ) {
				$pf = self::plan_post_fields(
					$tool,
					array(
						'id'     => (int) $item['snap']['id'],
						'fields' => $fields,
					)
				);
				if ( is_wp_error( $pf ) ) {
					return $pf;
				}
				if ( array() !== $pf['blocked'] || 1 !== count( $pf['items'] ) ) {
					$first     = isset( $pf['blocked'][0]['reason'] ) ? (string) $pf['blocked'][0]['reason'] : 'forbidden';
					$blocked[] = array(
						'ref'    => $item['ref'],
						'reason' => $first,
					);
					continue;
				}
			}
			$items[] = $item;
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'meta',
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
	 * The theme tools, loaded where they are not yet: wp_undo can be reached
	 * where the tool classes were not loaded in this request. In Pro, where
	 * this came from, a missing class answered «The theme tools are not
	 * available on this site»; here they are part of the plugin.
	 */
	private static function load_theme_tools() {
		if ( class_exists( 'AB_MCP_Tools_Plugins_Themes_Manage' ) ) {
			return;
		}
		if ( ! class_exists( 'AB_MCP_Tools_Base' ) ) {
			require_once dirname( __DIR__ ) . '/tools/class-tools-base.php';
		}
		require_once dirname( __DIR__ ) . '/tools/class-tools-plugins-themes-manage.php';
	}

	/**
	 * @param string $tool Tool.
	 * @param mixed  $data Snapshot: { stylesheet, template } of the theme that was active BEFORE the switch.
	 * @return array|WP_Error
	 */
	private static function plan_theme( $tool, $data ) {
		if ( ! is_array( $data ) || empty( $data['stylesheet'] ) || ! is_string( $data['stylesheet'] ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot is not readable. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$slug = $data['stylesheet'];
		self::load_theme_tools();
		// The same questions the switch itself asks, shared rather than copied:
		// is this the name of an installed theme (nested and underscored
		// directories included), is it whole, may this site use it, does the
		// site meet its PHP/WordPress requirement. A name that is not even the
		// shape of a directory is a hand-edited snapshot.
		$why = AB_MCP_Tools_Plugins_Themes_Manage::activation_blocker( $slug );
		if ( 'invalid' === $why[0] ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot names an invalid theme. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$items   = array();
		$blocked = array();
		if ( '' !== $why[0] ) {
			$blocked[] = array(
				'ref'    => $slug,
				'reason' => 'not_allowed' === $why[0] ? 'forbidden' : $why[0],
			);
		} else {
			$items[] = array(
				'ref'  => $slug,
				'to'   => $slug,
				'from' => (string) get_stylesheet(),
			);
		}
		return array(
			'tool'       => $tool,
			'kind'       => 'theme',
			'items'      => $items,
			'blocked'    => $blocked,
			'restorable' => count( $items ),
		);
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
		if ( isset( $fields['post_parent'] ) && ( ! AB_MCP_Post_Status::may_change_parent( $id, $status_after, (int) $fields['post_parent'] ) || ! self::may_attach_to( $post, (int) $fields['post_parent'] ) ) ) {
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
	 * May a restore attach this file to that parent again? A file shows in
	 * its parent's gallery and attached media: as wp_update_post asks,
	 * another post as its parent needs the right to edit that post, and is
	 * neither a revision nor a file. Asked of the account as it is now, so an
	 * old snapshot does not reach a post it may no longer edit.
	 *
	 * @param WP_Post $post   The post as it is now.
	 * @param int     $parent Parent the restore would give it.
	 * @return bool
	 */
	private static function may_attach_to( $post, $parent ) {
		$parent = (int) $parent;
		if ( 'attachment' !== $post->post_type || $parent <= 0 || (int) $post->post_parent === $parent ) {
			return true;
		}
		$target = get_post( $parent );
		return $target && ! in_array( $target->post_type, array( 'revision', 'attachment' ), true ) && current_user_can( 'edit_post', $parent );
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
		if ( '' === $why && null !== $parent && ! self::may_attach_to( $basis, $parent ) ) {
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
				if ( '' === $why && null !== $parent && ! self::may_attach_to( $basis, $parent ) ) {
					$why = 'forbidden';
				}
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
	 * Switch back to the theme the undo point names, the way wp_activate_theme
	 * switches (AB_MCP_Tools_Plugins_Themes_Manage::switch_to()): its
	 * refusals, the lock, test requests before and after, and the theme active
	 * now back where the site does worse with the previous one. With
	 * recording suspended, the switch records no undo point of its own.
	 *
	 * @param array $item Item.
	 * @return true|array|WP_Error { probe, warning? } after test requests.
	 */
	protected static function restore_theme( array $item ) {
		$slug = (string) $item['to'];
		self::load_theme_tools();
		// Asked again right before the switch, not only when the plan was made:
		// switch_theme() itself would end the request with wp_die() on an unmet
		// requirement, and does not check the network's allow list at all.
		$why = AB_MCP_Tools_Plugins_Themes_Manage::activation_blocker( $slug );
		if ( '' !== $why[0] ) {
			return new WP_Error( 'ab_mcp_undo_theme_' . $why[0], AB_MCP_Tools_Plugins_Themes_Manage::blocker_message( $why ) );
		}
		$res = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( $slug, 'wp_undo' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array_intersect_key( (array) $res, array_flip( array( 'probe', 'warning' ) ) );
		return $out ? $out : true;
	}

	/**
	 * Dispatch one planned item. Protected so a test can subclass and replace
	 * the write paths with recording stand-ins.
	 *
	 * @param string $kind Kind.
	 * @param array  $item Planned item.
	 * @return true|array|WP_Error An array only from restore_theme(): done, with the test requests' report.
	 */
	protected static function apply_item( $kind, array $item ) {
		switch ( $kind ) {
			case 'post_status':
				return static::restore_post_status( $item );
			case 'site_settings':
				return static::restore_setting( $item );
			case 'post_fields':
				return static::restore_post_fields( $item );
			case 'builder_data':
				return AB_MCP_Undo_Builder::restore_planned( $item );
			case 'option_rows':
				return static::restore_option_row( $item );
			case 'theme':
				return static::restore_theme( $item );
			case 'meta':
				return static::restore_meta( $item );
			case 'term':
			case 'menu_item':
			case 'widget':
				$class = self::object_kinds()[ $kind ];
				return $class::restore( $item );
		}
		return new WP_Error( 'ab_mcp_undo_unknown_kind', __( 'Unknown undo kind. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/**
	 * Put the meta of one object back (kind meta), and with wp_update_media
	 * the title and caption of the file. The comparison is made again right
	 * before the write, on the tables as they are now: the plan was a moment
	 * ago. The columns go first, through the write of the undo of
	 * wp_update_post (its checks and its read-back); the keys after them,
	 * row by row (AB_MCP_Undo_Meta::write_back()).
	 *
	 * @param array $item Item of AB_MCP_Undo_Meta::plan().
	 * @return true|WP_Error
	 */
	protected static function restore_meta( array $item ) {
		$snap = (array) $item['snap'];
		$cmp  = AB_MCP_Undo_Meta::compare( $snap );
		if ( null === $cmp ) {
			return new WP_Error( 'ab_mcp_undo_write_failed', __( 'The meta could not be read before the restore; nothing was restored. Try again in a moment; the undo point stays.', 'alphabridge-mcp' ) );
		}
		if ( empty( $item['force'] ) && in_array( 'changed', $cmp, true ) ) {
			return new WP_Error( 'ab_mcp_undo_meta_changed', __( 'The meta was changed after the undo was planned; nothing was restored. Pass force=true to put the old value back over the newer change.', 'alphabridge-mcp' ) );
		}
		$fields = array();
		$keys   = array();
		foreach ( array_keys( array_diff( $cmp, array( 'before' ) ) ) as $loc ) {
			if ( 0 === strpos( $loc, 'field:' ) ) {
				$col            = substr( $loc, 6 );
				$fields[ $col ] = (string) $snap['fields'][ $col ];
			} else {
				$keys[] = substr( $loc, 5 );
			}
		}
		if ( array() !== $fields ) {
			$done = static::restore_post_fields(
				array(
					'ref'    => (int) $snap['id'],
					'fields' => $fields,
				)
			);
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		return array() === $keys ? true : AB_MCP_Undo_Meta::write_back( $snap, $keys );
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
	 * An option row of wp_search_replace, written back. The row was there when
	 * the replace changed it; if it is gone now, it is added again.
	 *
	 * @param array $item Item: ref (option name), value.
	 * @return true|WP_Error
	 */
	protected static function restore_option_row( array $item ) {
		$name = (string) $item['ref'];
		if ( null === AB_MCP_Undo::option_autoload( $name ) ) {
			$flag = isset( $item['autoload'] ) ? (string) $item['autoload'] : '';
			if ( 'yes' === $flag || 'no' === $flag ) {
				add_option( $name, $item['value'], '', $flag );
			} else {
				add_option( $name, $item['value'] );
			}
			if ( null !== AB_MCP_Undo::option_autoload( $name ) && self::option_value_matches( $name, $item['value'] ) ) {
				return true;
			}
			return new WP_Error( 'ab_mcp_undo_option_failed', __( 'The option was missing and could not be added again. Try again; if it keeps failing, check the option in wp-admin, where its plugin offers it.', 'alphabridge-mcp' ) );
		}
		if ( self::write_option_back( $name, $item['value'] ) && null !== AB_MCP_Undo::option_autoload( $name ) ) {
			return true;
		}
		return new WP_Error( 'ab_mcp_undo_option_failed', __( 'The option was written back, but the site stored something else; a plugin may filter it. Check the option in wp-admin, where its plugin offers it.', 'alphabridge-mcp' ) );
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
