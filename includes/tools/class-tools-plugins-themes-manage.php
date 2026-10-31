<?php
/**
 * Switching plugins and themes on and off: wp_activate_plugin,
 * wp_deactivate_plugin and wp_activate_theme.
 *
 * Free since 4.11.0, before in AlphaBridge MCP Pro only (its class
 * AB_MCP_Tools_Plugins_Themes, which also holds the theme file tools that
 * stay in Pro). Taken over without a change of behaviour: an activation is
 * checked by AB_MCP_Activation_Guard (test requests before and after, taken
 * back where the site does worse), a theme switch records an undo point
 * (kind theme) that wp_undo reverses through switch_to(). The reading tools
 * wp_list_plugins and wp_list_themes are in AB_MCP_Tools_Plugins_Themes_List.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

// Activating a plugin or theme is checked with test requests before and after,
// and taken back where the site does worse.
require_once dirname( __DIR__ ) . '/lifecycle/class-activation-guard.php';
// A theme switch taken back leaves menu locations, widgets and rewrite rules
// as they were.
require_once dirname( __DIR__ ) . '/lifecycle/class-theme-switch-state.php';
// The files of wp-admin a deactivation routine expects.
require_once dirname( __DIR__ ) . '/lifecycle/class-admin-files.php';

/**
 * Class AB_MCP_Tools_Plugins_Themes_Manage
 */
class AB_MCP_Tools_Plugins_Themes_Manage extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_activate_plugin',
			array(
				'description' => 'Activate an installed plugin on this site, named by its file as wp_list_plugins lists it (e.g. akismet/akismet.php); installing is wp_install_plugin; network-wide activation on a multisite network is done under Network Admin › Plugins. Needs activate_plugins. A plugin taken back after a failed check is deactivated without its deactivation routine, as WordPress does after a fatal error.' . AB_MCP_Activation_Guard::DESCRIPTION,
				'capability'  => 'activate_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'plugin' ),
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => 'Plugin identifier as wp_list_plugins returns it in file: folder and main file, e.g. "akismet/akismet.php" (a single-file plugin as "hello.php").' ) ),
				),
				'callback'    => array( __CLASS__, 'activate_plugin' ),
			)
		);

		$r->register(
			'wp_deactivate_plugin',
			array(
				'description' => 'Deactivate a plugin by its file path as wp_list_plugins lists it (e.g. akismet/akismet.php). The answer says in was_active whether it was active before. AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro, the owner drop-in) is refused here, because deactivating it would end this connection or take tools away from it; it is deactivated in wp-admin under Plugins. wp_activate_plugin is the reverse.',
				'capability'  => 'activate_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'plugin' ),
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => 'Exact identifier of an installed plugin as wp_list_plugins returns it in file, e.g. "akismet/akismet.php". Anything else is refused.' ) ),
				),
				'callback'    => array( __CLASS__, 'deactivate_plugin' ),
			)
		);

		$r->register(
			'wp_activate_theme',
			array(
				'description' => 'Switch the active theme to an installed theme (wp_install_theme installs one), named by its directory as wp_list_themes lists it (e.g. "twentytwentyfour", a child theme, or "collection/theme" for a theme inside a collection). Refuses, with the reason, a theme that is broken (a child without its parent), that the network does not allow on this site, or whose PHP/WordPress requirement this site does not meet. Records an undo point that switches back to the previous theme; the token is returned in "undo" (null when no snapshot could be taken). A theme taken back after a failed check is replaced by the previous one again.' . AB_MCP_Activation_Guard::DESCRIPTION,
				'capability'  => 'switch_themes',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'theme' ),
					'properties' => array(
						'theme' => array( 'type' => 'string', 'description' => 'Theme folder (stylesheet) as wp_list_themes lists it in slug, e.g. "twentytwentyfour" or "collection/theme"; a child theme is named by its own folder, not its parent\'s.' ),
					),
				),
				'callback'    => array( __CLASS__, 'activate_theme' ),
			)
		);
	}

	/* --------------------------------------------------------------- plugins */

	/**
	 * Activate plugin, through AB_MCP_Activation_Guard: test requests
	 * before and after, taken back where the site does worse.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $a ) {
		$plugin = self::s( $a, 'plugin' );
		$result = AB_MCP_Activation_Guard::plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'activated' => true,
			'plugin'    => $plugin,
		) + $result;
	}

	/**
	 * Deactivate plugin: only the exact identifier of an installed plugin, as
	 * wp_delete_plugin takes it. Before 4.7.4 any string went through, and the
	 * answer said "deactivated" whether anything was or not.
	 *
	 * AlphaBridge itself is refused, as by wp_update_plugin and
	 * wp_delete_plugin: the connection that asks would end or lose its tools,
	 * and only wp-admin brings them back. «Deactivate every plugin, then turn
	 * them on one by one» is the usual way to find a conflict, and an
	 * assistant that follows it must not cut itself off halfway.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $a ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = self::s( $a, 'plugin' );
		if ( '' === $plugin || 0 !== validate_file( $plugin ) || ! array_key_exists( $plugin, get_plugins() ) ) {
			return new WP_Error( 'ab_mcp_bad_plugin', __( 'plugin must be the exact identifier of an installed plugin, e.g. "akismet/akismet.php". wp_list_plugins lists the installed plugins with their identifiers (file).', 'alphabridge-mcp' ) );
		}
		if ( is_wp_error( AB_MCP_Own_Files::own_file_refusal( WP_PLUGIN_DIR . '/' . $plugin ) ) ) {
			return new WP_Error(
				'ab_mcp_deactivate_own',
				__( 'Not deactivated: this is AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro or the owner drop-in). It is not deactivated through AlphaBridge: that would end this connection or take tools away from it, and only wp-admin brings them back. Nothing was changed. Deactivate it in wp-admin under Plugins.', 'alphabridge-mcp' )
			);
		}
		// deactivate_plugins() takes a plugin active on the network off the
		// network's list too, so for every site: that takes the network's own
		// right, not activate_plugins of one site (Codex, 30.10.2026).
		$network = is_multisite() && is_plugin_active_for_network( $plugin );
		if ( $network && ! current_user_can( 'manage_network_plugins' ) ) {
			return AB_MCP_Guidance::role_refusal(
				__( 'Not deactivated: the plugin is active on the whole network, so deactivating it turns it off for every site, and that takes a network administrator. Nothing was changed. Ask a network administrator to deactivate it under Network Admin › Plugins.', 'alphabridge-mcp' ),
				array(
					'steps' => '',
					'url'   => network_admin_url( 'plugins.php' ),
				)
			);
		}
		$was_active = is_plugin_active( $plugin );
		// Its deactivation routine runs here as in wp-admin, with wp-admin's
		// files loaded: without them WP Super Cache's ended with a fatal
		// error, and the plugin stayed active (31.10.2026).
		AB_MCP_Admin_Files::load();
		deactivate_plugins( array( $plugin ) );
		return array(
			'deactivated' => true,
			'plugin'      => $plugin,
			'was_active'  => $was_active,
		) + ( $network ? array( 'network_wide' => true ) : array() );
	}

	/* --------------------------------------------------------------- themes */

	/**
	 * Activate an installed theme.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $a ) {
		$need = self::need( $a, array( 'theme' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		return self::switch_to( self::s( $a, 'theme' ), 'wp_activate_theme' );
	}

	/**
	 * Make an installed theme the active one. Shared by wp_activate_theme, by
	 * the activate_theme flag of wp_deploy_push_zip and by the activate flag of
	 * wp_install_theme_zip, so they refuse the same things, check the switch
	 * the same way (AB_MCP_Activation_Guard: test requests before and
	 * after, back to the previous theme where the site does worse) and record
	 * the same undo point — only for a switch that is kept: one taken back
	 * has nothing to undo.
	 *
	 * @param string $slug Theme directory name.
	 * @param string $via  The tool that asked; goes into the summary only. The
	 *                     undo point always belongs to wp_activate_theme, the
	 *                     tool whose capability a restore re-checks.
	 * @return array|WP_Error
	 */
	public static function switch_to( $slug, $via = 'wp_activate_theme' ) {
		$slug = trim( (string) $slug );
		// The registry checks this for wp_activate_theme; the deploy tool reaches
		// this method directly, so it is asked again here.
		if ( ! current_user_can( 'switch_themes' ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot switch themes. It takes the switch_themes capability, which administrators have; connect with such an account.', 'alphabridge-mcp' ) );
		}
		// Files that arrived a moment ago (a deploy) are not in the theme cache yet.
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}
		$why = self::activation_blocker( $slug );
		if ( '' !== $why[0] ) {
			$codes = array(
				'invalid'      => 'ab_mcp_bad_theme',
				'gone'         => 'ab_mcp_no_theme',
				'broken'       => 'ab_mcp_theme_broken',
				'not_allowed'  => 'ab_mcp_theme_not_allowed',
				'requirements' => 'ab_mcp_theme_requirements',
			);
			return new WP_Error( $codes[ $why[0] ], self::blocker_message( $why ) );
		}
		// The state before is read from here on, so under the lock: read
		// before another activation's switch and used after it, the way back
		// would undo that one (AB_MCP_Change_Lock).
		$lock = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			return self::switch_locked( $slug, $via, $why[1] );
		} finally {
			AB_MCP_Activation_Guard::release_lock();
		}
	}

	/**
	 * switch_to() under the lock.
	 *
	 * @param string   $slug  Theme directory name.
	 * @param string   $via   The tool that asked.
	 * @param WP_Theme $theme The theme.
	 * @return array|WP_Error
	 */
	private static function switch_locked( $slug, $via, $theme ) {
		$want_template = (string) $theme->get_template();
		// As the table holds it, as the checks after the switch read it.
		list( $prev, $prev_template ) = AB_MCP_Theme_Switch_State::active();
		$summary       = array(
			'slug'    => $slug,
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
		);
		if ( $prev === $slug ) {
			return array(
				'activated'      => false,
				'already_active' => true,
				'theme'          => $summary,
			);
		}
		// Asked before the switch: whether switch_theme() can bring the
		// previous theme back, and what to write back where it cannot.
		$back = self::activation_blocker( $prev );
		$snap = self::theme_options();
		// Menus, widgets and rewrite rules as they are right before the switch
		// (AB_MCP_Theme_Switch_State), and after a switch back which of
		// them did not come back (null: nothing was written back).
		$state = null;
		$off   = null;
		// The autoload flags of both themes' settings, which switch_theme()
		// sets past the option functions; a switch taken back puts them back
		// (AB_MCP_Theme_Switch_State::autoload_of()).
		$flags = array();
		$kept  = AB_MCP_Activation_Guard::activate(
			array(
				'type'      => 'theme',
				'item'      => $slug,
				'template'  => $want_template,
				'previous'  => $prev,
				'activate'  => static function () use ( $slug, $prev, &$state, &$flags ) {
					$state = AB_MCP_Theme_Switch_State::take( $slug );
					$flags = AB_MCP_Theme_Switch_State::autoload_of( array( 'theme_mods_' . $prev, 'theme_mods_' . $slug ) );
					switch_theme( $slug );
					return ( (string) get_stylesheet() === $slug ) ? true : new WP_Error( 'ab_mcp_switch_failed', __( 'The theme did not become active. Activate it in wp-admin under Appearance › Themes.', 'alphabridge-mcp' ) );
				},
				// On means the theme with its template: switch_theme() writes the
				// template, then the stylesheet, and a filter can keep either
				// (Codex, 05.10.2026). Neither on nor as before is a switch half
				// done, and activate() takes that back.
				// Read from the table: a test request whose theme code switched
				// the theme wrote it there, past this request's copy.
				'is_on'     => static function () use ( $slug, $want_template ) {
					list( $stylesheet, $template ) = AB_MCP_Theme_Switch_State::active();
					return $stylesheet === $slug && $template === $want_template;
				},
				'changed'   => static function () use ( $prev, $prev_template ) {
					list( $stylesheet, $template ) = AB_MCP_Theme_Switch_State::active();
					return $stylesheet !== $prev || $template !== $prev_template;
				},
				'take_back' => static function () use ( $prev, $prev_template, $back, $snap, &$state, &$off, &$flags ) {
					if ( ! is_array( $state ) ) {
						$ok = self::switch_back( $prev, $prev_template, '' === $back[0], $snap );
					} else {
						// No moment in which the switch back is announced to the
						// next request, which would map the menus of the theme
						// taken back onto the previous one.
						$before = AB_MCP_Theme_Switch_State::hold( $state );
						$ok     = false;
						try {
							$ok = self::switch_back( $prev, $prev_template, '' === $back[0], $snap );
						} finally {
							AB_MCP_Theme_Switch_State::release();
							if ( ! $ok ) {
								AB_MCP_Theme_Switch_State::unhold( $before );
							}
						}
						// The rest before the control round.
						if ( $ok ) {
							$off = AB_MCP_Theme_Switch_State::restore( $state );
						}
					}
					// Also where switch_back() wrote the options itself: the
					// active theme's settings would otherwise load with no
					// request, the ones of the theme taken back with every one.
					if ( $ok ) {
						AB_MCP_Theme_Switch_State::put_autoload( $flags );
					}
					return $ok;
				},
				'restored'  => static function () use ( &$off ) {
					return $off;
				},
			)
		);
		if ( is_wp_error( $kept ) ) {
			return $kept;
		}
		$undo = null;
		$note = null;
		if ( '' !== $back[0] ) {
			// get_stylesheet() hands back what the option holds: a name that is
			// no directory WordPress lists, a theme that is broken, withheld by
			// the network or below the site's PHP/WordPress. None of these can
			// be switched back to, so no token is issued — and the answer says why.
			$note = sprintf(
				/* translators: %s: reason the previous theme cannot be activated */
				__( 'No undo point: the previous theme cannot be switched back to (%s)', 'alphabridge-mcp' ),
				self::blocker_message( $back )
			);
		} elseif ( class_exists( 'AB_MCP_Undo' ) ) {
			$undo = AB_MCP_Undo::record(
				array(
					'tool'    => 'wp_activate_theme',
					'cap'     => 'switch_themes',
					'kind'    => 'theme',
					'summary' => sprintf( '%s: %s -> %s', $via, $prev, $slug ),
					'items'   => 1,
					'data'    => array(
						'stylesheet' => $prev,
						'template'   => $prev_template,
					),
				)
			);
		}
		$out = array(
			'activated' => true,
			'theme'     => $summary,
			'previous'  => array( 'slug' => $prev ),
			'undo'      => $undo,
		);
		if ( null !== $note ) {
			$out['undo_note'] = $note;
		}
		return $out + $kept;
	}

	/**
	 * The options switch_theme() writes, as they are now.
	 *
	 * @return array<string,mixed> Option => value (false where it is not set).
	 */
	private static function theme_options() {
		$out = array();
		// As the table holds them, past any filter on them: the way back
		// writes these, and a filtered value would land in the table (Codex,
		// 05.10.2026).
		foreach ( array( 'template', 'stylesheet', 'template_root', 'stylesheet_root', 'current_theme', 'theme_switched' ) as $name ) {
			$out[ $name ] = AB_MCP_Theme_Switch_State::stored_option( $name );
		}
		return $out;
	}

	/**
	 * Back to the previous theme after a switch the test requests took back:
	 * with switch_theme(), as wp-admin switches back, where WordPress would
	 * switch to it now; otherwise — a previous theme WordPress would not
	 * switch to any more (switch_theme() would end the request with
	 * wp_die()), or one switch_theme() did not bring back — the options
	 * switch_theme() writes go back to what they were.
	 *
	 * @param string $prev          Previous stylesheet.
	 * @param string $prev_template Previous template.
	 * @param bool   $switchable    Whether switch_theme() may be asked.
	 * @param array  $snap          theme_options() before the switch.
	 * @return bool Whether the previous theme is active again.
	 */
	private static function switch_back( $prev, $prev_template, $switchable, array $snap ) {
		if ( $switchable ) {
			switch_theme( $prev );
		}
		if ( (string) get_stylesheet() !== $prev || (string) get_template() !== $prev_template ) {
			// Each by its fixed name: no option a caller names is written here.
			// As the table held them; put() writes past a filtered comparison.
			foreach ( array( 'template', 'stylesheet', 'template_root', 'stylesheet_root', 'current_theme', 'theme_switched' ) as $name ) {
				AB_MCP_Theme_Switch_State::put( $name, $snap[ $name ] );
			}
		}
		list( $stylesheet, $template ) = AB_MCP_Theme_Switch_State::active();
		return $stylesheet === $prev && $template === $prev_template;
	}

	/**
	 * The shape of a theme's directory name as WordPress lists it: one
	 * directory, or one directory inside a collection ("collection/theme"),
	 * no segment starting with a dot (WordPress skips those), no backslash,
	 * no NUL. Anything installed passes; a path of the caller's own making
	 * does not.
	 *
	 * @param mixed $slug Name.
	 * @return bool
	 */
	public static function theme_name_ok( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 255 || 1 === preg_match( '/[\\\\\0]/', $slug ) ) {
			return false;
		}
		$parts = explode( '/', $slug );
		if ( count( $parts ) > 2 ) {
			return false;
		}
		foreach ( $parts as $seg ) {
			if ( '' === $seg || '.' === $seg[0] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The installed theme of exactly that name, or null. Looked up in the
	 * list WordPress keeps (broken ones included) rather than built from the
	 * name, so only a directory that really is a theme root can be named.
	 *
	 * @param string $slug Name.
	 * @return WP_Theme|null
	 */
	public static function installed_theme( $slug ) {
		$themes = wp_get_themes( array( 'errors' => null ) );
		return ( is_array( $themes ) && isset( $themes[ $slug ] ) && is_object( $themes[ $slug ] ) ) ? $themes[ $slug ] : null;
	}

	/**
	 * Why a theme cannot become active on this site right now. One answer for
	 * the tool, the deploy flag and the undo restore, so the three cannot
	 * drift apart.
	 *
	 * @param mixed $slug Name.
	 * @return array{0:string,1:object|null,2:WP_Error|null} Reason ('' = it can;
	 *         'invalid', 'gone', 'broken', 'not_allowed', 'requirements'),
	 *         the theme, WordPress's own error if it gave one.
	 */
	public static function activation_blocker( $slug ) {
		if ( ! self::theme_name_ok( $slug ) ) {
			return array( 'invalid', null, null );
		}
		$theme = self::installed_theme( $slug );
		if ( null === $theme ) {
			return array( 'gone', null, null );
		}
		$errors = $theme->errors();
		if ( $errors ) {
			return array( 'broken', $theme, $errors );
		}
		// A network can withhold a theme from a site; switch_theme() does not ask.
		if ( function_exists( 'is_multisite' ) && is_multisite() && method_exists( $theme, 'is_allowed' ) && ! $theme->is_allowed() ) {
			return array( 'not_allowed', $theme, null );
		}
		// switch_theme() asks this too — and ends the request with wp_die()
		// when the answer is no. Asked here first, so it comes back as data.
		if ( function_exists( 'validate_theme_requirements' ) ) {
			$req = validate_theme_requirements( $slug );
			if ( is_wp_error( $req ) ) {
				return array( 'requirements', $theme, $req );
			}
		}
		return array( '', $theme, null );
	}

	/**
	 * @param array $why Answer of activation_blocker().
	 * @return string
	 */
	public static function blocker_message( array $why ) {
		$wp = ( isset( $why[2] ) && is_wp_error( $why[2] ) ) ? implode( ' ', (array) $why[2]->get_error_messages() ) : '';
		switch ( $why[0] ) {
			case 'invalid':
				return __( 'A theme is named by its directory as wp_list_themes lists it (for a theme inside a collection: "collection/theme").', 'alphabridge-mcp' );
			case 'gone':
				return __( 'Theme not found. wp_list_themes shows the installed themes by their folder name.', 'alphabridge-mcp' );
			case 'broken':
				/* translators: %s: reason WordPress gives */
				return sprintf( __( 'The theme cannot be activated: %s', 'alphabridge-mcp' ), $wp );
			case 'not_allowed':
				return __( 'The network does not allow this theme on this site. A super admin enables it for the site under Network Admin › Themes or Sites.', 'alphabridge-mcp' );
			case 'requirements':
				/* translators: %s: reason WordPress gives */
				return sprintf( __( 'This site does not meet the requirements of the theme: %s Update WordPress or PHP to what it needs, or choose another theme.', 'alphabridge-mcp' ), $wp );
		}
		return '';
	}
}
