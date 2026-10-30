<?php
/**
 * Code an ability would switch on, kept switched off.
 *
 * wp_run_ability runs what plugins register with the Abilities API. An
 * ability that activates a plugin (activate_plugin(), or the plugins route of
 * the REST API through rest_do_request()) or switches the theme
 * (switch_theme()) would switch code on past AB_MCP_Activation_Guard:
 * without the test requests before and after, and so without the way back
 * should that code end every request of the site with a fatal error, this MCP
 * endpoint's too (class-activation-guard.php). A finding of the review of
 * 04.10.2026.
 *
 * So while an ability runs, the writes that would switch code on are kept
 * from the options that hold it: a plugin added to active_plugins or
 * active_sitewide_plugins, and the options switch_theme() writes to name the
 * active theme and to hand the switch to the next request. Their pre_update
 * filters hand back what is there, and WordPress then writes nothing. A
 * plugin taken out of a list is let through, as wp_deactivate_plugin
 * deactivates without test requests too. add_option(), which writes where
 * nothing is stored (after a delete_option(), say), has no filter of its own
 * before it writes but sanitize_option_<name>, and the network's
 * add_network_option() has pre_add_site_option_<name>: there the same holds,
 * measured where nothing is stored against what the option held when the
 * call began (Codex, 06.10.2026: a delete and an add went past the gate). wp_run_ability answers with an
 * error that names what was kept off and the tool that switches it on with
 * test requests.
 *
 * Kept off rather than switched on and back: a plugin or theme the test
 * requests have not seen is never active for the site, not for a visitor who
 * comes in while the ability still runs, and not for the next request, which
 * would run the new theme's after_switch_theme callbacks.
 *
 * What switch_theme() writes besides is put back when the call ends: the copy
 * of the widget areas it stores in the active theme's settings before it
 * switches (retrieve_widgets() would map the widgets from it later), and the
 * autoload flags of both themes' settings, which it sets straight in the
 * table (wp_set_option_autoload_values()); where the ability ends the request
 * with exit or die, at its shutdown, first in WordPress's shutdown action.
 * After a switch that was kept off,
 * the active theme's settings (theme_mods_<stylesheet>) and the widget areas
 * (sidebars_widgets) are kept as they are until the call ends: what the
 * ability writes there next was meant for the theme it switched to, and would
 * land on the active one. Measured on WordPress 7.1.2, 06.10.2026: an ability
 * that switched the theme and one that activated a plugin, with
 * activate_plugin() and through the REST API, left the options table as it
 * was but for what the plugin's activation routine wrote.
 *
 * What runs all the same, as with an activation that is taken back: the
 * plugin's main file and its activation routine (activate_plugin() loads them
 * before it writes the list), and the callbacks of the actions activate_plugin,
 * activated_plugin and switch_theme; what they write stays.
 *
 * Not covered: code switched on past the option functions (a query of its own,
 * a file in mu-plugins) or by another request the ability starts (an HTTP
 * request to the site itself), snippets switched on in a snippets plugin, and
 * other settings an ability writes for the theme it meant to switch to.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lifecycle/class-theme-switch-state.php';

/**
 * Class AB_MCP_Ability_Code_Gate
 */
class AB_MCP_Ability_Code_Gate {

	/**
	 * The options switch_theme() writes to name the active theme and to hand
	 * the switch to the next request, in the order it writes them (WordPress
	 * 7.1.2, wp-includes/theme.php); nothing else in WordPress writes them.
	 */
	const THEME_OPTIONS = array( 'theme_switch_menu_locations', 'template', 'stylesheet', 'template_root', 'stylesheet_root', 'current_theme', 'theme_switched' );

	/** The key of the widget areas' copy switch_theme() stores in the theme's settings. */
	const WIDGETS_COPY = 'sidebars_widgets';

	/** @var int open() calls not closed yet. */
	private static $depth = 0;

	/** @var bool Whether shutdown() is registered in this request. */
	private static $on_shutdown = false;

	/**
	 * What the options the gate watches held when open() ran, false where
	 * none was stored: where an ability deletes one, nothing is stored to
	 * measure its next write against, and this is.
	 *
	 * @var array<string,mixed>
	 */
	private static $at_open = array();

	/**
	 * What was kept off since open(), and what is put back at close().
	 *
	 * @var array{plugins: array<string,bool>, theme: string, autoload: array<string,string>, mods: array|null}
	 */
	private static $kept = array(
		'plugins'  => array(),
		'theme'    => '',
		'autoload' => array(),
		'mods'     => null,
	);

	/**
	 * Keep code from being switched on until close(). Nested calls share the
	 * outer one.
	 */
	public static function open() {
		if ( self::$depth++ > 0 ) {
			return;
		}
		if ( ! self::$on_shutdown ) {
			// First as the earliest action of WordPress's shutdown, which runs
			// from a shutdown function WordPress registered long before this
			// one: an ability's own shutdown callback that calls exit would end
			// every shutdown function after it (Codex, 06.10.2026), as in
			// AB_MCP_Activation_Guard::hook_rescue().
			add_action( 'shutdown', array( __CLASS__, 'shutdown' ), PHP_INT_MIN, 0 );
			register_shutdown_function( array( __CLASS__, 'shutdown' ) );
			self::$on_shutdown = true;
		}
		self::$kept    = self::nothing();
		self::$at_open = array(
			'active_plugins'          => get_option( 'active_plugins' ),
			'active_sitewide_plugins' => get_site_option( 'active_sitewide_plugins' ),
		);
		foreach ( self::THEME_OPTIONS as $name ) {
			self::$at_open[ $name ] = get_option( $name );
		}
		foreach ( self::filters() as $tag => $hook ) {
			add_filter( $tag, array( __CLASS__, $hook[0] ), PHP_INT_MAX, $hook[1] );
		}
	}

	/**
	 * End open(): the filters go, what switch_theme() wrote besides is put
	 * back, and what was kept off is handed over.
	 *
	 * @return array{plugins: array<string,bool>, theme: string} Plugin file =>
	 *         network-wide, and the theme (its stylesheet); both empty where
	 *         nothing was kept off, and in a nested call.
	 */
	public static function close() {
		$none = array(
			'plugins' => array(),
			'theme'   => '',
		);
		if ( self::$depth < 1 || --self::$depth > 0 ) {
			return $none;
		}
		foreach ( self::filters() as $tag => $hook ) {
			remove_filter( $tag, array( __CLASS__, $hook[0] ), PHP_INT_MAX );
		}
		$kept          = self::$kept;
		self::$kept    = self::nothing();
		self::$at_open = array();
		if ( '' !== $kept['theme'] && is_array( $kept['mods'] ) ) {
			self::put_widgets_copy( $kept['mods'][0], $kept['mods'][1] );
		}
		if ( array() !== $kept['autoload'] ) {
			AB_MCP_Theme_Switch_State::put_autoload( $kept['autoload'] );
		}
		return array(
			'plugins' => $kept['plugins'],
			'theme'   => $kept['theme'],
		);
	}

	/**
	 * The end of a request that ended inside the call: exit or die in the
	 * ability skips the finally that calls close(), and what switch_theme()
	 * wrote besides would stay (Codex, 06.10.2026). Nothing where the call
	 * closed the gate.
	 */
	public static function shutdown() {
		if ( self::$depth < 1 ) {
			return;
		}
		self::$depth = 1;
		self::close();
	}

	/**
	 * Nothing kept off yet.
	 *
	 * @return array
	 */
	private static function nothing() {
		return array(
			'plugins'  => array(),
			'theme'    => '',
			'autoload' => array(),
			'mods'     => null,
		);
	}

	/**
	 * The filters, tag => array( method, accepted args ).
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	private static function filters() {
		$out = array(
			'pre_update_option_active_plugins'               => array( 'keep_site_plugins', 2 ),
			'pre_update_site_option_active_sitewide_plugins' => array( 'keep_network_plugins', 2 ),
			'sanitize_option_active_plugins'                 => array( 'add_site_plugins', 1 ),
			'pre_add_site_option_active_sitewide_plugins'    => array( 'add_network_plugins', 1 ),
		);
		foreach ( self::THEME_OPTIONS as $name ) {
			$out[ 'pre_update_option_' . $name ] = array( 'keep_theme', 3 );
			$out[ 'sanitize_option_' . $name ]   = array( 'add_theme', 2 );
		}
		$out['pre_update_option'] = array( 'watch_write', 3 );
		return $out;
	}

	/**
	 * The filter on this site's list: a plugin the write adds stays out of it.
	 * What the write takes out goes; the rest stays as it was stored, its
	 * order and keys too (activate_plugin() sorts the list it writes). Where
	 * nothing is stored, a plugin is added if it was not active when the call
	 * began, and the list is written without it.
	 *
	 * @param mixed $value     What would be written.
	 * @param mixed $old_value What is there, false for nothing.
	 * @return mixed
	 */
	public static function keep_site_plugins( $value, $old_value ) {
		$none  = false === $old_value;
		$was   = $none ? self::at_open( 'active_plugins' ) : $old_value;
		$old   = is_array( $was ) ? $was : array();
		$added = array();
		foreach ( (array) $value as $file ) {
			if ( ! in_array( $file, $old, true ) ) {
				$added[] = $file;
			}
		}
		if ( array() === $added ) {
			return $value;
		}
		foreach ( $added as $file ) {
			if ( is_string( $file ) && '' !== $file ) {
				self::$kept['plugins'][ $file ] = false;
			}
		}
		$keep = array();
		foreach ( $old as $key => $file ) {
			if ( in_array( $file, (array) $value, true ) ) {
				$keep[ $key ] = $file;
			}
		}
		return ( ! $none && count( $keep ) === count( $old ) ) ? $old_value : $keep;
	}

	/**
	 * add_option() of this site's list: it runs sanitize_option() before it
	 * writes, and writes only where nothing is stored. update_option() runs
	 * it too; where something is stored, its pre_update filter judges.
	 *
	 * @param mixed $value What would be written.
	 * @return mixed
	 */
	public static function add_site_plugins( $value ) {
		return false === get_option( 'active_plugins' ) ? self::keep_site_plugins( $value, false ) : $value;
	}

	/**
	 * The filter on the network's list (plugin => time): as on this site's.
	 *
	 * @param mixed $value     What would be written.
	 * @param mixed $old_value What is there, false for nothing.
	 * @return mixed
	 */
	public static function keep_network_plugins( $value, $old_value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$none  = false === $old_value;
		$was   = $none ? self::at_open( 'active_sitewide_plugins' ) : $old_value;
		$old   = is_array( $was ) ? $was : array();
		$added = array_diff_key( $value, $old );
		if ( array() === $added ) {
			return $value;
		}
		foreach ( array_keys( $added ) as $file ) {
			if ( '' !== (string) $file ) {
				self::$kept['plugins'][ (string) $file ] = true;
			}
		}
		$keep = array_intersect_key( $old, $value );
		return ( ! $none && count( $keep ) === count( $old ) ) ? $old_value : $keep;
	}

	/**
	 * add_network_option() of the network's list: as add_site_plugins().
	 *
	 * @param mixed $value What would be written.
	 * @return mixed
	 */
	public static function add_network_plugins( $value ) {
		return false === get_site_option( 'active_sitewide_plugins' ) ? self::keep_network_plugins( $value, false ) : $value;
	}

	/**
	 * The filter on the options of a theme switch: the value stays what it
	 * is, and where nothing is stored, what it was when the call began. The
	 * theme switched to is noted, and the autoload flags of both themes'
	 * settings are read before switch_theme() sets them.
	 *
	 * @param mixed  $value     What would be written.
	 * @param mixed  $old_value What is there, false for nothing.
	 * @param string $option    Option.
	 * @return mixed
	 */
	public static function keep_theme( $value, $old_value, $option ) {
		$was = false === $old_value ? self::at_open( $option ) : $old_value;
		if ( maybe_serialize( $value ) === maybe_serialize( $was ) ) {
			return $value;
		}
		if ( 'stylesheet' === $option || ( 'template' === $option && '' === self::$kept['theme'] ) ) {
			self::$kept['theme'] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( 'stylesheet' === $option && is_string( $value ) && is_string( $old_value ) ) {
			// The first read wins: the flags as they were before the first switch.
			self::$kept['autoload'] += AB_MCP_Theme_Switch_State::autoload_of( array( 'theme_mods_' . $value, 'theme_mods_' . $old_value ) );
		}
		return $was;
	}

	/**
	 * add_option() of an option of a theme switch: as add_site_plugins().
	 *
	 * @param mixed  $value  What would be written.
	 * @param string $option Option.
	 * @return mixed
	 */
	public static function add_theme( $value, $option ) {
		return false === get_option( $option ) ? self::keep_theme( $value, false, $option ) : $value;
	}

	/**
	 * What an option held when open() ran, false where none was stored.
	 *
	 * @param string $option Option.
	 * @return mixed
	 */
	private static function at_open( $option ) {
		return array_key_exists( $option, self::$at_open ) ? self::$at_open[ $option ] : false;
	}

	/**
	 * Every write during the call: the active theme's settings are read as
	 * the table holds them before the first write of the call to them
	 * (switch_theme() writes its widgets copy there before it switches), and
	 * after a switch that was kept off they and the widget areas stay as they
	 * are for the rest of the call.
	 *
	 * @param mixed  $value     What would be written.
	 * @param string $option    Option.
	 * @param mixed  $old_value What is there.
	 * @return mixed
	 */
	public static function watch_write( $value, $option, $old_value ) {
		$mods = 'theme_mods_' . get_option( 'stylesheet' );
		if ( $mods === $option && null === self::$kept['mods'] ) {
			self::$kept['mods'] = array( $mods, AB_MCP_Theme_Switch_State::stored_option( $mods ) );
		}
		if ( '' !== self::$kept['theme'] && ( 'sidebars_widgets' === $option || $mods === $option ) ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * Take the widgets copy of a switch that was kept off out of the active
	 * theme's settings again: the key as it was before the call, the rest as
	 * the ability left it.
	 *
	 * @param string $option Option of the theme's settings.
	 * @param mixed  $before The option before the call's first write to it.
	 */
	private static function put_widgets_copy( $option, $before ) {
		$now = AB_MCP_Theme_Switch_State::stored_option( $option );
		if ( ! is_array( $now ) ) {
			return;
		}
		$had = is_array( $before ) && array_key_exists( self::WIDGETS_COPY, $before );
		$has = array_key_exists( self::WIDGETS_COPY, $now );
		if ( $had && $has && maybe_serialize( $before[ self::WIDGETS_COPY ] ) === maybe_serialize( $now[ self::WIDGETS_COPY ] ) ) {
			return;
		}
		if ( ! $had && ! $has ) {
			return;
		}
		if ( $had ) {
			$now[ self::WIDGETS_COPY ] = $before[ self::WIDGETS_COPY ];
		} else {
			unset( $now[ self::WIDGETS_COPY ] );
		}
		AB_MCP_Theme_Switch_State::put( $option, false === $before && array() === $now ? false : $now );
	}
}
