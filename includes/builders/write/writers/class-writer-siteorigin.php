<?php
/**
 * Writer for SiteOrigin Page Builder (storage B): the page is the layout in
 * panels_data (rows, cells, widgets with their settings), post_content a copy
 * SiteOrigin renders from it ("copy-content", on by default) that the site
 * does not show while SiteOrigin is active (measured).
 *
 * How it saves, in this order (plan review T3):
 * 1. Through SiteOrigin's ability siteorigin-panels/layout-update, where it
 *    is registered (Page Builder 2.36.1 and later) [SVN siteorigin-panels@
 *    2.36.1 inc/abilities.php:145-191, :207-216, :378-473]: it checks edit_post itself,
 *    runs every widget's update() as an editor save does, sanitises styles,
 *    applies the public pre-save filter, stores panels_data and renders the
 *    post_content copy under SiteOrigin's own save guard.
 *    The ability puts every widget through kses as well (:429-431), for any
 *    account — unlike an editor save, which keeps what an author with
 *    unfiltered_html wrote. Where that would change anything on the page
 *    (a Custom HTML widget with a script, an embed in an editor widget),
 *    the ability is not used: it would silently strip markup this change
 *    never touched. Nor where an administrator switched the ability off
 *    under Fine-tuning (group «Abilities of other plugins»): that switch
 *    holds for AlphaBridge's own use of it too.
 * 2. Otherwise the way SiteOrigin's classic editor stores a layout
 *    [inc/admin.php:483-486]: panels_data with wp_slash() (update_post_meta()
 *    unslashes, and a namespaced widget class keeps its backslashes), then the
 *    copy through SiteOrigin_Panels_Admin::copy_content_to_post() (:525-577),
 *    under with_save_guard() where SiteOrigin has it (:592). Only the changed
 *    fields differ from what was stored; widget update() and the pre-save
 *    filter do not run on this path.
 * 3. Neither available: only reading, and the answer says what to do.
 * The answer names the path taken.
 *
 * Element ids are "w" + the widget's index in panels_data's widgets, as the
 * free reader gives them; they hold together with layout_hash only.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-builder-settings-writer.php';

/**
 * Class AB_MCP_Builder_Writer_SiteOrigin
 */
class AB_MCP_Builder_Writer_SiteOrigin extends AB_MCP_Builder_Settings_Writer {

	/** The layout. */
	const DATA = 'panels_data';

	/** SiteOrigin's own way to store a layout (2.36.1). */
	const ABILITY = 'siteorigin-panels/layout-update';

	/** The admin class with the copy and the kses floor. */
	const ADMIN = 'SiteOrigin_Panels_Admin';

	/**
	 * Whether this way of writing was checked on a real installation: it was.
	 * Built from Page Builder 2.36.1's code and tested against stand-ins
	 * (test/builder-writers-meta-test.php), then run against Page Builder by
	 * SiteOrigin 2.36.1 with the SiteOrigin Widgets Bundle 1.75.0 on WordPress
	 * Playground (WordPress 7.1.2, PHP 8.3.33; Pro 4.7.0, bundled core 4.5.0;
	 * 02.10.2026; recipes siteorigin and siteorigin-more of test/e2e/builders/):
	 * both paths — the ability, and the editor's way on a page with a script in
	 * a Custom HTML widget, which stays —, seven kinds of field,
	 * wp_search_replace over eight pages, locked fields left alone, every undo
	 * byte for byte. Not measured: the editor's way on Page Builder older than
	 * 2.36.1, changing an image by its id, an account without unfiltered_html.
	 * The free core's element profile was measured with Widgets Bundle 1.74.3.
	 */
	const MEASURED = true;

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'siteorigin';
	}

	/**
	 * See MEASURED.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function verified( $post ): bool {
		return self::MEASURED && parent::verified( $post );
	}

	/**
	 * SiteOrigin's ability, or its admin class for the editor's way.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( null !== self::ability() || self::admin_can( 'copy_content_to_post' ) ) {
			return true;
		}
		return new WP_Error( 'ab_mcp_builder_cannot_write', __( 'SiteOrigin Page Builder\'s save functions are not loaded here (neither its layout-update ability, from version 2.36.1, nor its admin class), so AlphaBridge only reads this page. Update or activate SiteOrigin Page Builder, or change the page in its editor.', 'alphabridge-mcp' ) );
	}

	/**
	 * The stored settings of a widget.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $element Element.
	 * @return array|WP_Error
	 */
	protected function settings_of( $post, array $element ) {
		$index = self::index( (string) $element['id'] );
		$data  = get_post_meta( (int) $post->ID, self::DATA, true );
		if ( null === $index || ! is_array( $data ) || ! isset( $data['widgets'][ $index ] ) || ! is_array( $data['widgets'][ $index ] ) ) {
			return self::gone( (string) $element['id'], 'settings' );
		}
		return $data['widgets'][ $index ];
	}

	/**
	 * Matches in the stored layout (serialized: strings stand as they are).
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $search Text.
	 * @param bool    $ci     Case-insensitive.
	 * @return int
	 */
	protected function count_stored( $post, $search, $ci = false ) {
		$raw = AB_MCP_Undo_Builder::meta_rows( (int) $post->ID, self::DATA );
		return array() === $raw ? 0 : self::count_in( (string) $raw[0]['value'], (string) $search, $ci );
	}

	/**
	 * Say which way the change will be saved.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return array|WP_Error
	 */
	protected function prepare( $post, array $change, array $opts ) {
		$data = self::changed( $post, $change['changes'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array() !== $change['changes'] ) {
			$notes           = isset( $change['notes'] ) ? (array) $change['notes'] : array();
			$notes[]         = self::route_note( self::route( $data ) );
			$change['notes'] = $notes;
		}
		return $change;
	}

	/**
	 * Step 5.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		$id   = (int) $post->ID;
		$data = self::changed( $post, $change['changes'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$route = self::route( $data );
		if ( 'ability' === $route ) {
			$res = self::ability()->execute(
				array(
					'post_id'     => $id,
					'panels_data' => $data,
				)
			);
			if ( is_wp_error( $res ) ) {
				return new WP_Error(
					'ab_mcp_builder_write_failed',
					/* translators: %s: SiteOrigin's message */
					sprintf( __( 'SiteOrigin\'s layout-update ability did not store the layout: %s', 'alphabridge-mcp' ), $res->get_error_message() )
				);
			}
			if ( ! is_array( $res ) || empty( $res['updated'] ) || ( isset( $res['source'] ) && 'meta' !== $res['source'] ) ) {
				$why = is_array( $res ) && isset( $res['message'] ) && is_string( $res['message'] ) ? $res['message'] : __( 'no reason given', 'alphabridge-mcp' );
				return new WP_Error(
					'ab_mcp_builder_write_failed',
					/* translators: %s: SiteOrigin's message */
					sprintf( __( 'SiteOrigin\'s layout-update ability did not store the layout: %s', 'alphabridge-mcp' ), $why )
				);
			}
			return true;
		}
		// The classic editor's way: update_post_meta() unslashes what it is
		// given, so the layout goes in slashed and comes out as it is.
		if ( false === update_post_meta( $id, self::DATA, wp_slash( $data ) ) ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'WordPress did not store the SiteOrigin layout.', 'alphabridge-mcp' ) );
		}
		$this->copy( $id, $data );
		return true;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * The stored layout with the changes set, or a WP_Error when a field is
	 * not where the outline showed it.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $changes Changes.
	 * @return array|WP_Error
	 */
	private static function changed( $post, array $changes ) {
		$data = get_post_meta( (int) $post->ID, self::DATA, true );
		if ( ! is_array( $data ) || ! isset( $data['widgets'] ) || ! is_array( $data['widgets'] ) ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'This page has no readable SiteOrigin layout (any more). Read it again with wp_get_builder_layout.', 'alphabridge-mcp' ) );
		}
		foreach ( $changes as $c ) {
			$index = self::index( (string) $c['element'] );
			if ( null === $index || ! isset( $data['widgets'][ $index ] ) || ! is_array( $data['widgets'][ $index ] ) || ! self::set_at( $data['widgets'][ $index ], $c['data']['path'], $c['after'] ) ) {
				return self::gone( (string) $c['element'], (string) $c['field'] );
			}
		}
		return $data;
	}

	/**
	 * How this layout is to be stored: 'ability', or 'direct' (no ability),
	 * 'direct_off' (the ability is switched off for AlphaBridge) or
	 * 'direct_kses' (the ability's kses pass would change markup on the
	 * page).
	 *
	 * @param array $data Layout with the changes.
	 * @return string
	 */
	private static function route( array $data ) {
		if ( null === self::ability() ) {
			return null !== self::registered() ? 'direct_off' : 'direct';
		}
		$widgets = isset( $data['widgets'] ) && is_array( $data['widgets'] ) ? $data['widgets'] : array();
		// The ability's own floor (SiteOrigin_Panels_Admin::kses_deep(),
		// inc/admin.php:1472): where it would give back anything else, it
		// would store that.
		if ( ! self::admin_can( 'kses_deep' ) || call_user_func( array( self::ADMIN, 'kses_deep' ), $widgets ) !== $widgets ) {
			return 'direct_kses';
		}
		return 'ability';
	}

	/**
	 * The note on the way taken.
	 *
	 * @param string $route route().
	 * @return string
	 */
	private static function route_note( $route ) {
		if ( 'ability' === $route ) {
			return __( 'Saved through SiteOrigin Page Builder\'s layout-update ability, which also renews the post_content copy.', 'alphabridge-mcp' );
		}
		if ( 'direct_kses' === $route ) {
			return __( 'Saved the way SiteOrigin\'s editor stores a layout, not through its layout-update ability: that ability filters the markup of every widget, and this page holds markup (a script, an embed) it would remove from widgets this change does not touch.', 'alphabridge-mcp' );
		}
		if ( 'direct_off' === $route ) {
			return __( 'Saved the way SiteOrigin\'s editor stores a layout, not through its layout-update ability, which is switched off for AlphaBridge on this site (under AlphaBridge › AI Connection › Fine-tuning, or by a rule in the site\'s code): panels_data, then its post_content copy through SiteOrigin.', 'alphabridge-mcp' );
		}
		return __( 'Saved the way SiteOrigin\'s editor stores a layout (this SiteOrigin version has no layout-update ability): panels_data, then its post_content copy through SiteOrigin.', 'alphabridge-mcp' );
	}

	/**
	 * Renew the post_content copy through SiteOrigin; a copy that cannot be
	 * renewed stays as it was — the site does not show it while SiteOrigin is
	 * active — and the answer says so.
	 *
	 * @param int   $id   Post.
	 * @param array $data Layout as stored.
	 */
	private function copy( $id, array $data ) {
		if ( ! self::admin_can( 'copy_content_to_post' ) || ! self::admin_can( 'single' ) ) {
			$this->warnings[] = __( 'SiteOrigin\'s copy function is not loaded, so post_content keeps the old copy of the page; SiteOrigin renews it the next time the page is saved in its editor.', 'alphabridge-mcp' );
			return;
		}
		try {
			$admin = call_user_func( array( self::ADMIN, 'single' ) );
			wp_cache_delete( (int) $id, 'posts' );
			$post = get_post( (int) $id );
			$run  = static function () use ( $admin, $post, $id, $data ) {
				$admin->copy_content_to_post( $post, (int) $id, $data );
			};
			if ( method_exists( $admin, 'with_save_guard' ) ) {
				$admin->with_save_guard( $run );
			} else {
				$run();
			}
		} catch ( \Throwable $e ) {
			$this->warnings[] = sprintf(
				/* translators: %s: error class */
				__( 'SiteOrigin failed while renewing the post_content copy (%s); the layout is stored, the copy is the old one until the page is next saved in SiteOrigin\'s editor.', 'alphabridge-mcp' ),
				get_class( $e )
			);
		}
	}

	/**
	 * SiteOrigin's layout-update ability, where it is registered and not
	 * switched off for AlphaBridge (measured 02.10.2026: before, the writer
	 * used it while it was switched off). Only Pro's switches count: the
	 * approvals of this plugin (4.12.0) are for wp_run_ability, the general
	 * runner, not for this writer's own checked change.
	 *
	 * @return WP_Ability|null
	 */
	private static function ability() {
		$ability = self::registered();
		if ( null === $ability || ! class_exists( 'AB_MCP_Pro_Ability_Broker', false ) ) {
			return $ability;
		}
		$decision = AB_MCP_Pro_Ability_Broker::decision( $ability );
		return empty( $decision['allowed'] ) ? null : $ability;
	}

	/**
	 * SiteOrigin's layout-update ability, where it is registered.
	 *
	 * @return WP_Ability|null
	 */
	private static function registered() {
		// wp_has_ability() first: wp_get_ability() on a name that is not
		// registered raises a _doing_it_wrong notice.
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( self::ABILITY ) ) {
			return null;
		}
		$ability = wp_get_ability( self::ABILITY );
		return is_object( $ability ) && method_exists( $ability, 'execute' ) ? $ability : null;
	}

	/**
	 * Whether SiteOrigin's admin class has a method (the class loads through
	 * SiteOrigin's autoloader).
	 *
	 * @param string $method Method.
	 * @return bool
	 */
	private static function admin_can( $method ) {
		return class_exists( self::ADMIN ) && method_exists( self::ADMIN, $method );
	}

	/**
	 * The widget index of an element id "w<index>", or null.
	 *
	 * @param string $id Element id.
	 * @return int|null
	 */
	private static function index( $id ) {
		return 1 === preg_match( '~^w(\d{1,9})$~', (string) $id, $m ) ? (int) $m[1] : null;
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_SiteOrigin' );
