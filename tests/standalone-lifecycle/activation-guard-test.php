<?php
/**
 * Activating a plugin or theme through AB_MCP_Activation_Guard: test
 * requests before and after, and the activation taken back in the same call
 * where the site does worse. Runs the REAL guard, update guard, probe and
 * tool classes against tests/standalone-lib/support/update-harness.php, whose site answers
 * the test requests by the markers in the code it loads (the active plugins,
 * the active theme and its parent).
 *
 * Proves (the M5 case of 03.10.2026 first):
 *  - a new plugin that ends every request with a fatal error is deactivated
 *    again in the same call, silently (no deactivation routine), the answer
 *    is an error that names the error with file and line, says it was taken
 *    back and that the files are unchanged, and the control round passes;
 *    the file is byte for byte as before;
 *  - the same for a theme: the previous theme is active again, stylesheet and
 *    template, and no undo point is left for a switch that did not stay;
 *    a previous theme switch_theme() cannot take (it would wp_die()) comes
 *    back through the options it wrote;
 *  - a healthy plugin or theme stays active, the test after passed, a kept
 *    switch records its undo point;
 *  - a site that cannot reach itself before: activated as before, "not
 *    checked"; the test requests switched off: activated, "not checked";
 *  - a fatal error that was there before keeps the activation with a
 *    warning; a new one at another target, a cut MCP endpoint and a site
 *    that hangs take it back;
 *  - a plugin whose filters leave it out of the list get_option() hands out,
 *    or throw, is checked and taken back all the same: its state is read
 *    from the stored list, which the next request loads, and it is taken out
 *    of that list where deactivate_plugins() does not get it (A14);
 *  - a plugin that switches itself off when the test requests load it is
 *    not reported as activated (A15);
 *  - an already active plugin is not tested again; a plugin whose main file
 *    throws while it is loaded is not active, and the answer names the
 *    error; output while activating keeps it, with a warning;
 *  - the time budget: no activation starts that the round after and the
 *    reserve for taking it back would not fit, and a site that hangs after
 *    it keeps the call within 45 s by the guard's clock;
 *  - a request that ends between the switch and the verdict is checked at
 *    shutdown: a broken plugin goes, a healthy one stays; in child
 *    processes with a real exit (in activated_plugin, after WordPress wrote
 *    the list) and a real fatal error of PHP, through the shutdown function
 *    the guard registers: a broken plugin is taken back exactly once, a
 *    healthy one stays, and an exit in the activation hook itself (before
 *    the list is written) leaves nothing to take back; the check runs as the
 *    earliest shutdown action, so a plugin whose own shutdown callback exits
 *    does not keep it from running;
 *  - network-wide: taken back network-wide only; a plugin with "Network:
 *    true" goes network-wide on a network whatever was asked (core's
 *    activate_plugin()), through wp_activate_plugin and an install with
 *    activate, and is taken back network-wide;
 *  - the installs with activate: the install stays, the activation is taken
 *    back, and the error names the install first; a theme from a ZIP goes
 *    through the same switch as wp_activate_theme (undo point, refusals);
 *  - a theme switch that goes only part of the way (a filter keeps the
 *    stylesheet or the template) is taken back (in Pro also through Site Deploy);
 *    a child theme is on with its parent's template; theme code that
 *    switches the site on to another theme during the test requests is
 *    taken back, and the state is read from the table, not from this
 *    request's copy of the options (H);
 *  - a theme switch taken back leaves menu locations, widgets, rewrite
 *    rules and both themes' settings as they were (M): with the error
 *    before init and after it, without switch_theme() back, at shutdown,
 *    with a switch pending before, with an object in the theme mods; no
 *    request finds the switch back announced, and a request that loaded its
 *    options while the switch was pending maps nothing when it reaches init
 *    later; a kept switch is WordPress's; what does not come back is named;
 *    only the options of a switch are written; where the site filters
 *    those options, the table gets back what it held, not the filtered
 *    values (M15);
 *  - Code Snippets through install_provider is not covered here
 *    (php-snippets-test.php runs that path).
 *
 * Run:  php tests/standalone-lifecycle/activation-guard-test.php
 *       (it starts itself as a child process for the X cases:
 *       php tests/standalone-lifecycle/activation-guard-test.php child-<case>)
 */

require __DIR__ . '/../standalone-lib/support/update-harness.php';

/* wp_cache_delete() for the request's copy of the options in the harness
 * ($GLOBALS['ab_ocache']): one option, or the whole copy for alloptions and
 * notoptions. */
function wp_cache_delete( $k, $g = '' ) {
	if ( 'options' === $g && isset( $GLOBALS['ab_ocache'] ) && is_array( $GLOBALS['ab_ocache'] ) && empty( $GLOBALS['ab_other'] ) ) {
		if ( 'alloptions' === $k || 'notoptions' === $k ) {
			$GLOBALS['ab_ocache'] = array();
		} else {
			unset( $GLOBALS['ab_ocache'][ $k ] );
		}
	}
	return true;
}

/* A child process (X cases): the request ends for real after the switch. */
$ab_child = ( isset( $argv[1] ) && 0 === strpos( (string) $argv[1], 'child-' ) ) ? substr( (string) $argv[1], 6 ) : '';

/* -------------------------------------------- what core does around it */

$GLOBALS['ab_requirements_fail'] = array();
function validate_plugin( $p ) {
	return ( '' !== (string) $p && is_file( WP_PLUGIN_DIR . '/' . $p ) ) ? 0 : new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' );
}
function validate_plugin_requirements( $p ) { return true; }
/* Core's is_network_only_plugin() (WordPress 7.0.2): the "Network" header of
 * get_plugin_data(), or the old "Site Wide Only", true only as "true". */
function is_network_only_plugin( $plugin ) {
	$h = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, array( 'Network' => 'Network', '_sitewide' => 'Site Wide Only' ) );
	$n = ( '' === $h['Network'] && '' !== $h['_sitewide'] ) ? $h['_sitewide'] : $h['Network'];
	return 'true' === strtolower( $n );
}
/* Core's activate_plugin(), wp-admin/includes/plugin.php (7.0.2): on a
 * network a "Network: true" plugin goes network-wide whatever was asked;
 * validate; nothing where it is in the list already; else load the main file
 * in this request (plugin_sandbox_scrape()), run the activation hook, write
 * the list, run activated_plugin, and complain about output. */
function activate_plugin( $plugin, $redirect = '', $network_wide = false, $silent = false ) {
	if ( is_multisite() && ( $network_wide || is_network_only_plugin( $plugin ) ) ) {
		$network_wide = true;
		$current      = (array) get_site_option( 'active_sitewide_plugins', array() );
	} else {
		$current = (array) get_option( 'active_plugins', array() );
	}
	$valid = validate_plugin( $plugin );
	if ( is_wp_error( $valid ) ) { return $valid; }
	if ( $network_wide ? isset( $current[ $plugin ] ) : in_array( $plugin, $current, true ) ) {
		return null;
	}
	ab_event( 'activate_plugin:' . $plugin . ( $network_wide ? ':network' : '' ) );
	$src = (string) file_get_contents( WP_PLUGIN_DIR . '/' . $plugin );
	ob_start();
	if ( false !== strpos( $src, 'AB_THROW_ON_LOAD' ) ) {
		throw new Error( 'Call to undefined function ab_top_level()' );
	}
	// AB_EXIT_IN_SHUTDOWN: loading it adds a shutdown callback that ends the request.
	if ( false !== strpos( $src, 'AB_EXIT_IN_SHUTDOWN' ) ) {
		add_action(
			'shutdown',
			static function () {
				ab_child_ends( 'exit' );
			},
			10,
			0
		);
	}
	// do_action( "activate_{$plugin}" ): the activation hook, before the list.
	if ( false !== strpos( $src, 'AB_EXIT_IN_HOOK' ) ) {
		ab_child_ends( 'exit' );
	}
	if ( $network_wide ) {
		$cur            = (array) get_site_option( 'active_sitewide_plugins', array() );
		$cur[ $plugin ] = time();
		update_site_option( 'active_sitewide_plugins', $cur );
	} else {
		$cur   = (array) get_option( 'active_plugins', array() );
		$cur[] = $plugin;
		sort( $cur );
		update_option( 'active_plugins', $cur );
	}
	// AB_ADDS_NETWORK: its activation routine on this site puts it on the network's list too.
	if ( ! $network_wide && is_multisite() && false !== strpos( $src, 'AB_ADDS_NETWORK' ) ) {
		$net            = (array) get_site_option( 'active_sitewide_plugins', array() );
		$net[ $plugin ] = time();
		update_site_option( 'active_sitewide_plugins', $net );
	}
	// AB_ADDS_SITE: its network activation routine puts it on this site's list too.
	if ( $network_wide && false !== strpos( $src, 'AB_ADDS_SITE' ) ) {
		$site   = (array) get_option( 'active_plugins', array() );
		$site[] = $plugin;
		update_option( 'active_plugins', array_values( array_unique( $site ) ) );
	}
	// do_action( 'activated_plugin' ): after the list is written.
	// AB_HIDES_AFTER: a filter that leaves the plugin out of the list as
	// get_option() hands it out; AB_FILTER_THROWS: one that throws.
	$list_option = $network_wide ? 'option_active_sitewide_plugins' : 'option_active_plugins';
	if ( false !== strpos( $src, 'AB_HIDES_AFTER' ) ) {
		add_filter(
			$list_option,
			static function ( $list ) use ( $plugin, $network_wide ) {
				$list = (array) $list;
				if ( $network_wide ) {
					unset( $list[ $plugin ] );
					return $list;
				}
				return array_values( array_diff( $list, array( $plugin ) ) );
			}
		);
	}
	if ( false !== strpos( $src, 'AB_FILTER_THROWS' ) ) {
		add_filter(
			$list_option,
			static function () {
				throw new RuntimeException( 'a filter of the plugin threw' );
			}
		);
	}
	if ( false !== strpos( $src, 'AB_EXIT_AFTER' ) ) {
		ab_child_ends( 'exit' );
	}
	if ( false !== strpos( $src, 'AB_FATAL_AFTER' ) ) {
		ab_child_ends( 'fatal' );
	}
	if ( false !== strpos( $src, 'AB_OUTPUT' ) ) { echo 'Notice: something'; }
	if ( ob_get_length() > 0 ) {
		$out = ob_get_clean();
		return new WP_Error( 'unexpected_output', 'The plugin generated unexpected output.', $out );
	}
	ob_end_clean();
	return null;
}
$GLOBALS['ab_deactivations'] = array();
/* Core's deactivate_plugins() (7.0.2): false leaves the network list alone,
 * true leaves this site's list alone, null takes it from both. */
function deactivate_plugins( $plugins, $silent = false, $network_wide = null ) {
	$network_current = (array) get_site_option( 'active_sitewide_plugins', array() );
	$current         = (array) get_option( 'active_plugins', array() );
	$do_blog         = false;
	$do_network      = false;
	foreach ( (array) $plugins as $p ) {
		$GLOBALS['ab_deactivations'][] = array( $p, (bool) $silent, $network_wide );
		ab_event( 'deactivate_plugins:' . $p . ':' . ( $silent ? 'silent' : 'loud' ) . ':' . var_export( $network_wide, true ) );
		if ( ! is_plugin_active( $p ) ) {
			continue;
		}
		if ( false !== $network_wide ) {
			if ( is_plugin_active_for_network( $p ) ) {
				$do_network = true;
				unset( $network_current[ $p ] );
			} elseif ( $network_wide ) {
				continue;
			}
		}
		if ( true !== $network_wide ) {
			$key = array_search( $p, $current, true );
			if ( false !== $key ) {
				$do_blog = true;
				unset( $current[ $key ] );
			}
		}
	}
	if ( $do_blog ) { update_option( 'active_plugins', array_values( $current ) ); }
	if ( $do_network ) { update_site_option( 'active_sitewide_plugins', $network_current ); }
}
function validate_theme_requirements( $s ) {
	return in_array( $s, $GLOBALS['ab_requirements_fail'], true ) ? new WP_Error( 'theme_php_version', 'Current PHP version does not meet minimum requirements for this theme.' ) : true;
}
/* Core's switch_theme(), wp-includes/theme.php 757-876 (7.0.2 and 7.1.2 alike),
 * as far as it goes here: wp_die() on unmet requirements; the widget areas of
 * this request, where init ran in it, kept in the mods of the theme it leaves
 * (776); that theme's menu locations handed to the next request (785-786;
 * update_option() writes nothing where old and new are both false); the
 * options; the theme it switched from noted for the next request (840); the
 * new theme's mods set to autoload, which drops this request's copy of the
 * options where they were not (857-861, option.php 489-501); the
 * switch_theme action last (875). The new theme's code is not loaded in this
 * request. */
function switch_theme( $stylesheet ) {
	global $sidebars_widgets;
	$req = validate_theme_requirements( $stylesheet );
	if ( is_wp_error( $req ) ) { throw new RuntimeException( 'wp_die: ' . $req->get_error_message() ); }
	ab_event( 'switch_theme:' . $stylesheet );
	if ( is_array( $sidebars_widgets ) ) {
		set_theme_mod( 'sidebars_widgets', array( 'time' => time(), 'data' => $sidebars_widgets ) );
	}
	$nav = get_theme_mod( 'nav_menu_locations' );
	if ( get_option( 'theme_switch_menu_locations' ) !== $nav ) {
		update_option( 'theme_switch_menu_locations', $nav, true );
	}
	$old = get_stylesheet();
	$new = wp_get_theme( $stylesheet );
	update_option( 'template', $new->get_template() );
	update_option( 'stylesheet', $stylesheet );
	update_option( 'current_theme', $new->get( 'Name' ) );
	update_option( 'theme_switched', $old );
	if ( false !== get_option( 'theme_mods_' . $stylesheet ) ) {
		wp_cache_delete( 'alloptions', 'options' );
	}
	// wp_set_option_autoload_values(): the flags of both themes' mods,
	// straight in the table, only rows that are there (option.php 489-501).
	foreach ( array( 'theme_mods_' . $stylesheet => 'on', 'theme_mods_' . $old => 'off' ) as $name => $flag ) {
		if ( array_key_exists( $name, $GLOBALS['ab_opts'] ) ) {
			$GLOBALS['ab_autoload'][ $name ] = $flag;
		}
	}
	do_action( 'switch_theme', $new->get( 'Name' ), $new, wp_get_theme( $old ) );
}

/* Theme mods, theme.php 1022-1125: an option per stylesheet, as the option
 * "stylesheet" names it. */
function get_theme_mods() { $m = get_option( 'theme_mods_' . get_option( 'stylesheet' ) ); return is_array( $m ) ? $m : array(); }
function get_theme_mod( $name, $default = false ) { $m = get_theme_mods(); return isset( $m[ $name ] ) ? $m[ $name ] : $default; }
function set_theme_mod( $name, $value ) { $m = get_theme_mods(); $m[ $name ] = $value; return update_option( 'theme_mods_' . get_option( 'stylesheet' ), $m ); }
/* What a theme registers: menu locations and widget areas, per stylesheet. */
$GLOBALS['ab_theme_menus']    = array();
$GLOBALS['ab_theme_sidebars'] = array();
function get_registered_nav_menus() { $s = get_stylesheet(); return isset( $GLOBALS['ab_theme_menus'][ $s ] ) ? $GLOBALS['ab_theme_menus'][ $s ] : array(); }
function get_nav_menu_locations() { $l = get_theme_mod( 'nav_menu_locations' ); return is_array( $l ) ? $l : array(); }
/* _wp_menus_changed() and wp_map_nav_menu_locations(), unchanged from core. */
require __DIR__ . '/../standalone-lib/support/nav-menu-switch-7.1.2.php';

/* check_theme_switched(), theme.php 3474/3483, run by every request that gets
 * to init (priority 99) — the test requests too ($GLOBALS['ab_on_init'] in the
 * harness): where a switch is noted, the after_switch_theme callbacks, core's
 * own two first (_wp_menus_changed(), _wp_sidebars_changed(); both left out
 * after a switch in the Customizer), then flush_rewrite_rules() (saved at
 * wp_loaded) and the note cleared (core passes false, the table keeps ''). */
function ab_check_theme_switched( $target ) {
	if ( ! get_option( 'theme_switched' ) ) { return; }
	ab_event( 'check_theme_switched:' . get_stylesheet() . ':' . $target );
	ab_slow_switch( 'first' );
	if ( get_option( 'theme_switched_via_customizer' ) ) {
		update_option( 'theme_switched_via_customizer', '' );
	} else {
		_wp_menus_changed();
		ab_wp_sidebars_changed();
	}
	ab_slow_switch( 'last' );
	update_option( 'rewrite_rules', array( 'rules of' => get_stylesheet() ) );
	update_option( 'theme_switched', '' );
}
/* A slow after_switch_theme callback of the new theme (the M14 case):
 * $GLOBALS['ab_slow_switch'] = array( slot, what happens meanwhile ), the slot
 * 'first' ahead of core's own two, 'last' after them, before the rewrite
 * rules are saved. */
function ab_slow_switch( $slot ) {
	if ( isset( $GLOBALS['ab_slow_switch'] ) && $slot === $GLOBALS['ab_slow_switch'][0] ) {
		( $GLOBALS['ab_slow_switch'][1] )();
	}
}
/* _wp_sidebars_changed(): retrieve_widgets( true ), widgets.php 1327-1382
 * (7.1.2), in short: the widget areas the active theme registers keep their
 * widgets, those of areas it lacks go to wp_inactive_widgets, an area left
 * empty gets its widgets back from the theme's sidebars_widgets mod, and the
 * option is saved with array_version 3. */
function ab_wp_sidebars_changed() {
	$s      = get_stylesheet();
	$areas  = isset( $GLOBALS['ab_theme_sidebars'][ $s ] ) ? $GLOBALS['ab_theme_sidebars'][ $s ] : array();
	$stored = (array) get_option( 'sidebars_widgets', array() );
	unset( $stored['array_version'] );
	$out = array( 'wp_inactive_widgets' => isset( $stored['wp_inactive_widgets'] ) ? (array) $stored['wp_inactive_widgets'] : array() );
	foreach ( $areas as $a ) {
		$out[ $a ] = isset( $stored[ $a ] ) ? (array) $stored[ $a ] : array();
	}
	foreach ( $stored as $a => $w ) {
		if ( 'wp_inactive_widgets' !== $a && ! in_array( $a, $areas, true ) ) {
			$out['wp_inactive_widgets'] = array_merge( $out['wp_inactive_widgets'], (array) $w );
		}
	}
	$mod = get_theme_mod( 'sidebars_widgets' );
	foreach ( $areas as $a ) {
		if ( empty( $out[ $a ] ) && is_array( $mod ) && ! empty( $mod['data'][ $a ] ) ) {
			$out[ $a ] = $mod['data'][ $a ];
		}
	}
	$out['array_version'] = 3;
	update_option( 'sidebars_widgets', $out );
}
/* Core's check_theme_switched(), as default-filters.php hooks it at init 99. */
function check_theme_switched() { ab_check_theme_switched( isset( $GLOBALS['ab_init_target'] ) ? $GLOBALS['ab_init_target'] : 'late' ); }
/**
 * Init of a request on a site with Pro: drop_stale_switch() at 98 (hooked by
 * the plugin's main file), core's check at 99, the database there to ask.
 * The filters it adds to its option writes end with it.
 */
function ab_init( $target, $at100 = null ) {
	$saved                       = isset( $GLOBALS['ab_hooks']['init'] ) ? $GLOBALS['ab_hooks']['init'] : array();
	$writes                      = array_filter(
		$GLOBALS['ab_hooks'],
		static function ( $tag ) {
			return 0 === strpos( $tag, 'pre_update_option_' );
		},
		ARRAY_FILTER_USE_KEY
	);
	$GLOBALS['ab_hooks']['init'] = array();
	$GLOBALS['ab_init_target']   = $target;
	$db                          = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	$GLOBALS['wpdb']             = new AB_Test_Wpdb();
	AB_MCP_Theme_Switch_State::hook();
	add_action( 'init', 'check_theme_switched', 99 );
	if ( null !== $at100 ) {
		add_action( 'init', $at100, 100, 0 );
	}
	try {
		do_action( 'init' );
	} finally {
		$GLOBALS['ab_hooks']['init'] = $saved;
		foreach ( array_keys( $GLOBALS['ab_hooks'] ) as $tag ) {
			if ( 0 === strpos( $tag, 'pre_update_option_' ) ) {
				unset( $GLOBALS['ab_hooks'][ $tag ] );
			}
		}
		$GLOBALS['ab_hooks'] += $writes;
		unset( $GLOBALS['ab_init_target'] );
		if ( null !== $db ) {
			$GLOBALS['wpdb'] = $db;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}
}
/** A visitor's request now: it reads and writes the table. */
function ab_visitor() {
	$GLOBALS['ab_other'] = true;
	try {
		ab_init( 'visitor' );
	} finally {
		$GLOBALS['ab_other'] = false;
	}
}
/**
 * A request that loaded its options at another moment ($view: the table as it
 * was then) and reaches init only now — a test request that timed out but
 * still runs, a visitor that came in between: Pro's drop_stale_switch() at
 * init 98, core's check at 99, with what it loaded.
 */
class AB_Test_Wpdb {
	public $options  = 'wp_options';
	public $sitemeta = 'wp_sitemeta';
	/* The table as it is now, as the database answers: the stored text, or
	 * null. The harness keeps network options in the same table. */
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $q ) {
		$name = (string) $q[1][0];
		if ( ! array_key_exists( $name, $GLOBALS['ab_opts'] ) ) { return null; }
		// The autoload column: as set here, else what add_option() gives a new row.
		if ( 0 === strpos( (string) $q[0], 'SELECT autoload' ) ) {
			return isset( $GLOBALS['ab_autoload'][ $name ] ) ? $GLOBALS['ab_autoload'][ $name ] : 'auto';
		}
		$v = $GLOBALS['ab_opts'][ $name ];
		return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : (string) $v;
	}
	/* A write past the option functions: the table only, this request's copy stays. */
	public function update( $table, $data, $where ) {
		$name = (string) ( $where['option_name'] ?? $where['meta_key'] );
		if ( array( 'autoload' ) === array_keys( $data ) ) {
			$GLOBALS['ab_autoload'][ $name ] = (string) $data['autoload'];
			ab_event( 'wpdb_autoload:' . $name . ':' . $data['autoload'] );
			return 1;
		}
		$v    = (string) ( $data['option_value'] ?? $data['meta_value'] );
		$GLOBALS['ab_opts'][ $name ] = maybe_unserialize( $v );
		ab_event( 'wpdb_update:' . $table . ':' . $name );
		return 1;
	}
}
function ab_late_request( array $view, $at100 = null ) {
	$keep                 = $GLOBALS['ab_ocache'];
	$GLOBALS['ab_ocache'] = array();
	foreach ( $view as $k => $v ) {
		$GLOBALS['ab_ocache'][ $k ] = array( ab_db_copy( $v ) );
	}
	foreach ( array( 'theme_switched', 'theme_switch_menu_locations', 'theme_switched_via_customizer' ) as $k ) {
		if ( ! array_key_exists( $k, $view ) ) {
			$GLOBALS['ab_ocache'][ $k ] = null;
		}
	}
	try {
		ab_init( 'late', $at100 );
	} finally {
		$GLOBALS['ab_ocache'] = $keep;
	}
}
function is_super_admin() { return true; }
/* AB_MCP_Guidance::role_refusal() of this plugin adds the way to the role; here the message only. */
class AB_MCP_Guidance { public static function role_refusal( $m, array $args = array() ) { return new WP_Error( 'ab_mcp_forbidden', $m . ( isset( $args['url'] ) ? ' Direct link: ' . $args['url'] : '' ) ); } }
function network_admin_url( $path = '' ) { return 'https://example.test/wp-admin/network/' . $path; }
class WP_Theme extends AB_Test_Theme {}

require_once $GLOBALS['ab_free'] . 'tools/class-tools-plugins-themes-manage.php';

/**
 * wp_network_activate_plugin stays in Pro (AB_MCP_Tools_Multisite there);
 * the network-wide path of the guard it runs through is this plugin's, so it
 * is called here as that tool calls it.
 */
function ab_network_activate_plugin( array $a ) {
	$plugin = (string) ( $a['plugin'] ?? '' );
	$res    = AB_MCP_Activation_Guard::plugin( $plugin, true );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array(
		'network_activated' => true,
		'plugin'            => $plugin,
	) + $res;
}

/** The guard with the clock of the harness, and a way to let the request end before the verdict. */
class AB_Test_Activation_Guard extends AB_MCP_Activation_Guard {
	public static $start     = null;
	public static $began     = 0.0;
	public static $die_once  = false;
	protected static function now() { return microtime( true ) + $GLOBALS['ab_clock']; }
	protected static function request_start() { return null !== self::$start ? self::$start : static::now(); }
	public static function elapsed() { return static::now() - self::$began; }
	public static function activate( array $job ) { self::$began = static::request_start(); return parent::activate( $job ); }
	/* The request ends right after the switch: nothing after it runs. */
	protected static function check_after( array $job, array $check ) {
		if ( self::$die_once ) {
			self::$die_once = false;
			throw new RuntimeException( 'the request ended here' );
		}
		return parent::check_after( $job, $check );
	}
	public static function pending() { return static::$pending; }
}

/** A plugin in its folder, not active; returns its file. */
function ab_new_plugin( $slug, $marker = '' ) {
	ab_install_plugin( $slug, '1.0.0', $marker, false );
	return "$slug/$slug.php";
}
/** A plugin whose header says "Network: true", not active; returns its file. */
function ab_new_network_plugin( $slug, $marker = '' ) {
	ab_rm_any( WP_PLUGIN_DIR . "/$slug" );
	mkdir( WP_PLUGIN_DIR . "/$slug", 0777, true );
	file_put_contents( WP_PLUGIN_DIR . "/$slug/$slug.php", "<?php\n/**\n * Plugin Name: Test $slug\n * Version: 1.0.0\n * Network: true\n */\n" . ( '' !== $marker ? "// $marker\n" : '' ) );
	return "$slug/$slug.php";
}
/** A theme, optionally a child of another. */
function ab_new_theme( $slug, $marker = '', $parent = '' ) {
	ab_install_theme( $slug, '1.0.0', $marker );
	if ( '' !== $parent ) {
		file_put_contents( get_theme_root() . "/$slug/style.css", "/*\nTheme Name: Test $slug\nVersion: 1.0.0\nTemplate: $parent\n*/\n" );
	}
}
/** The job of AB_MCP_Activation_Guard::plugin(), for the test guard: the same one. */
function ab_plugin_activation( $file, $network = false ) {
	return AB_MCP_Activation_Guard::plugin_job( $file, $network );
}
function ab_reset_activation() {
	// Before ab_reset(), which deletes options a case may have frozen.
	$GLOBALS['ab_opts_stuck'] = array();
	$GLOBALS['ab_ocache']     = null;
	$GLOBALS['ab_other']      = false;
	ab_reset();
	$GLOBALS['ab_deactivations']     = array();
	$GLOBALS['ab_requirements_fail'] = array();
	$GLOBALS['ab_autoload']          = array();
	AB_Test_Activation_Guard::$start    = null;
	AB_Test_Activation_Guard::$die_once = false;
	ab_install_theme( 'calm', '1.0.0' );
	$GLOBALS['ab_stylesheet'] = 'calm';
	$GLOBALS['ab_template']   = 'calm';
	// The menus and widgets of M: off unless a case sets them up.
	unset( $GLOBALS['ab_on_init'] );
	$GLOBALS['sidebars_widgets']         = null;
	$GLOBALS['ab_views']                 = array();
	$GLOBALS['ab_hooks']['switch_theme'] = array();
	$GLOBALS['ab_hooks']['init']         = array();
	$GLOBALS['ab_hooks']['option_active_plugins']          = array();
	$GLOBALS['ab_hooks']['option_active_sitewide_plugins'] = array();
	foreach ( array_keys( $GLOBALS['ab_hooks'] ) as $tag ) {
		if ( 0 === strpos( $tag, 'option_' ) || 0 === strpos( $tag, 'pre_option_' ) ) {
			$GLOBALS['ab_hooks'][ $tag ] = array();
		}
	}
	// As on every WordPress site, there is a database to ask.
	$GLOBALS['wpdb'] = new AB_Test_Wpdb();
	foreach ( array_keys( $GLOBALS['ab_opts'] ) as $o ) {
		if ( 0 === strpos( $o, 'theme_mods_' ) || in_array( $o, array( 'sidebars_widgets', 'rewrite_rules', 'theme_switch_menu_locations', 'theme_switched_via_customizer' ), true ) ) {
			delete_option( $o );
		}
	}
}
function ab_has( $hay, ...$needles ) {
	foreach ( $needles as $n ) {
		if ( false === strpos( (string) $hay, $n ) ) { return false; }
	}
	return true;
}

/**
 * A site with menus and widgets (the M cases): calm active with its menus
 * (primary 10, footer 11) and a logo, shiny used before (primary 20, social
 * 21), the widget areas, the rewrite rules, no switch pending — and this
 * request, like the MCP request on a site, ran init with calm. Every test
 * request that gets to init runs check_theme_switched(). Returns the state.
 */
function ab_menu_site() {
	$GLOBALS['ab_theme_menus']    = array(
		'calm'  => array( 'primary' => 'Main', 'footer' => 'Footer' ),
		'shiny' => array( 'primary' => 'Top', 'social' => 'Social' ),
		'fresh' => array( 'primary' => 'Main' ),
	);
	$GLOBALS['ab_theme_sidebars'] = array(
		'calm'  => array( 'sidebar-1', 'footer-1' ),
		'shiny' => array( 'sidebar-1', 'shop' ),
		'fresh' => array( 'sidebar-1' ),
	);
	update_option( 'stylesheet', 'calm' );
	update_option( 'template', 'calm' );
	update_option( 'current_theme', 'Test calm' );
	update_option( 'theme_mods_calm', array( 'nav_menu_locations' => array( 'primary' => 10, 'footer' => 11 ), 'custom_logo' => 5 ) );
	update_option( 'theme_mods_shiny', array( 'nav_menu_locations' => array( 'primary' => 20, 'social' => 21 ) ) );
	$widgets = array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'search-2', 'text-3' ), 'footer-1' => array( 'archives-2' ) );
	update_option( 'sidebars_widgets', $widgets + array( 'array_version' => 3 ) );
	update_option( 'rewrite_rules', array( 'rules of' => 'calm' ) );
	update_option( 'theme_switched', '' );
	$GLOBALS['sidebars_widgets'] = $widgets;
	$GLOBALS['ab_on_init']       = 'ab_init';
	// What a request that loads its options right after each switch sees.
	add_action(
		'switch_theme',
		static function () {
			$GLOBALS['ab_views'][] = $GLOBALS['ab_opts'];
		},
		10,
		0
	);
	// This request's own copy of the options, loaded at its first read.
	$GLOBALS['ab_ocache'] = array();
	return ab_menu_state();
}
/** The options a theme switch touches, as stored ('(none)' where not set). */
function ab_menu_state() {
	$o = array();
	foreach ( array( 'stylesheet', 'template', 'theme_mods_calm', 'theme_mods_shiny', 'theme_mods_fresh', 'sidebars_widgets', 'rewrite_rules', 'theme_switched', 'theme_switch_menu_locations', 'theme_switched_via_customizer' ) as $n ) {
		$o[ $n ] = array_key_exists( $n, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $n ] : '(none)';
	}
	return $o;
}
/** Where two states differ, for the message of a failed check. */
function ab_menu_diff( array $want, array $have ) {
	$d = array();
	foreach ( $want as $k => $v ) {
		if ( $v !== $have[ $k ] ) { $d[] = $k . ': ' . json_encode( $v ) . ' -> ' . json_encode( $have[ $k ] ); }
	}
	return implode( '; ', $d );
}

/* ============================================ X in a child process */

/* Declared here, so a file that declares it again ends PHP with a fatal
 * error nothing can catch ("Cannot redeclare"). */
function ab_child_twice() {}

/**
 * The request ends here, for real: the child's report goes to the end of the
 * shutdown functions (after the guard's, registered before the switch), then
 * exit, or a fatal error of PHP.
 */
function ab_child_ends( $how ) {
	static $ended = false;
	if ( $ended ) {
		// Again, inside a shutdown function: the ones after it, the report
		// among them, would not run.
		ab_child_report();
		exit( 0 );
	}
	$ended = true;
	register_shutdown_function( 'ab_child_report' );
	if ( 'fatal' === $how ) {
		$f = $GLOBALS['ab_child_root'] . '/twice.php';
		file_put_contents( $f, "<?php\nfunction ab_child_twice() {}\n" );
		include $f;
	}
	exit( 0 );
}

/** What the child finds once the guard's shutdown function has run. */
function ab_child_report() {
	$file  = $GLOBALS['ab_child_plugin'];
	$after = array_slice( $GLOBALS['ab_requests'], 3 );
	$deact = $GLOBALS['ab_deactivations'];
	switch ( $GLOBALS['ab_child_case'] ) {
		case 'exit-broken':
		case 'fatal-broken':
		case 'exit-in-shutdown':
			ok( ! is_plugin_active( $file ), 'X the broken plugin is not active after the request ended' );
			ok( array( array( $file, true, false ) ) === $deact, 'X taken back exactly once, silently (' . json_encode( $deact ) . ')' );
			ok( 4 === count( $after ) && 'home' === $after[0]['target'], 'X the round after (to the fatal error) and the control ran at shutdown (' . count( $after ) . ' requests)' );
			break;
		case 'exit-healthy':
			ok( is_plugin_active( $file ), 'X the healthy plugin stays active' );
			ok( array() === $deact, 'X nothing taken back' );
			ok( 3 === count( $after ), 'X it was tested after the switch, at shutdown (' . count( $after ) . ' requests)' );
			break;
		case 'exit-in-hook':
			ok( ! is_plugin_active( $file ) && array() === $deact && array() === $after, 'X an exit in the activation hook itself: WordPress never wrote the list, nothing to test or take back' );
			break;
		case 'theme-exit-both':
			$have = ab_menu_state();
			ok( 'calm' === $have['stylesheet'], 'X the switch back got as far as the options before the second exit' );
			ok( '' === $have['theme_switched'] && '(none)' === $have['theme_switch_menu_locations'], 'X and announced no switch: the two were put back before it (' . json_encode( array( $have['theme_switched'], $have['theme_switch_menu_locations'] ) ) . ')' );
			ab_visitor();
			ok( array( 'primary' => 10, 'footer' => 11 ) === $GLOBALS['ab_opts']['theme_mods_calm']['nav_menu_locations'], 'X the next visitor maps nothing onto calm' );
			break;
		case 'theme-half-exit':
			ok( 'calm' === get_stylesheet() && 'calm' === get_template(), 'X the half switch (calm\'s stylesheet, shiny\'s template) is taken back at shutdown: ' . get_stylesheet() . '/' . get_template() );
			ok( $GLOBALS['ab_child_s0'] === ab_menu_state(), 'X menus, widgets and rewrite rules as before: ' . ab_menu_diff( $GLOBALS['ab_child_s0'], ab_menu_state() ) );
			break;
		case 'theme-exit-broken':
			$have = ab_menu_state();
			ok( 'calm' === $have['stylesheet'] && 'calm' === $have['template'], 'X the previous theme is active again after the request ended' );
			ok( $GLOBALS['ab_child_s0'] === $have, 'X menus, widgets and rewrite rules as before the switch, written back at shutdown: ' . ab_menu_diff( $GLOBALS['ab_child_s0'], $have ) );
			ok( 4 === count( $after ), 'X the round after (to the fatal error) and the control ran at shutdown (' . count( $after ) . ' requests)' );
			break;
	}
	if ( 'theme-exit-both' === $GLOBALS['ab_child_case'] ) {
		// The exit in the switch back at shutdown ends every shutdown function
		// after it, finally included: the lock stays until it is taken over.
		ok( false !== get_option( AB_MCP_Change_Lock::OPTION ), 'X an exit in the way back at shutdown leaves the lock behind (taken over after its timeout, and the busy answer says when)' );
	} else {
		ok( false === get_option( AB_MCP_Change_Lock::OPTION ), 'X the lock for changes to active code is free after the request ended' );
	}
	echo 'CHILD ' . $GLOBALS['ab_count'] . ' ' . $GLOBALS['ab_fails'] . "\n";
	ab_rm_tree( $GLOBALS['ab_child_root'] );
}

if ( '' !== $ab_child ) {
	$markers = array(
		'exit-broken'  => 'AB_FATAL_LOAD AB_EXIT_AFTER',
		'fatal-broken' => 'AB_FATAL_LOAD AB_FATAL_AFTER',
		'exit-healthy' => 'AB_EXIT_AFTER',
		'exit-in-hook' => 'AB_FATAL_LOAD AB_EXIT_IN_HOOK',
		'exit-in-shutdown' => 'AB_FATAL_LOAD AB_EXIT_AFTER AB_EXIT_IN_SHUTDOWN',
		'theme-exit-broken' => 'AB_FATAL_LOAD',
		'theme-exit-both'   => 'AB_FATAL_LOAD',
		'theme-half-exit'   => '',
	);
	if ( ! isset( $markers[ $ab_child ] ) ) {
		echo "CHILD 0 1\n";
		exit( 2 );
	}
	ab_reset_activation();
	// WordPress's shutdown_action_hook(): registered in wp-settings.php before
	// any plugin is loaded, it runs the shutdown action.
	register_shutdown_function(
		static function () {
			do_action( 'shutdown' );
		}
	);
	// The tree has to outlive the harness's own shutdown function (registered
	// first, so it runs first): the guard's check at shutdown reads it, and
	// the report removes it.
	$GLOBALS['ab_child_root']   = $GLOBALS['ab_root'];
	$GLOBALS['ab_root']         = '';
	$GLOBALS['ab_child_case']   = $ab_child;
	if ( 0 === strpos( $ab_child, 'theme-' ) ) {
		// Another plugin's switch_theme callback ends the request: after the
		// switch to the broken theme, and in theme-exit-both after the
		// switch back too (core loads no code of the new theme here).
		$GLOBALS['ab_child_s0']     = ab_menu_site();
		$GLOBALS['ab_child_plugin'] = '';
		$GLOBALS['ab_exit_on']      = ( 'theme-exit-both' === $ab_child ) ? array( 'shiny', 'calm' ) : array( 'shiny' );
		// theme-half-exit: a filter keeps the stylesheet, so the switch writes
		// only shiny's template, and the callback ends the request there.
		if ( 'theme-half-exit' === $ab_child ) {
			$GLOBALS['ab_opts_stuck']['stylesheet'] = true;
		}
		add_action(
			'switch_theme',
			static function () {
				$now = ( 'theme-half-exit' === $GLOBALS['ab_child_case'] ) ? ( $GLOBALS['ab_opts']['template'] ?? '' ) : $GLOBALS['ab_opts']['stylesheet'];
				if ( in_array( $now, $GLOBALS['ab_exit_on'], true ) ) {
					ab_child_ends( 'exit' );
				}
			},
			20,
			0
		);
		ab_new_theme( 'shiny', $markers[ $ab_child ] );
		AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
		ok( false, 'X the request should have ended in the switch' );
		echo 'CHILD ' . $GLOBALS['ab_count'] . ' ' . $GLOBALS['ab_fails'] . "\n";
		exit( 1 );
	}
	$GLOBALS['ab_child_plugin'] = ab_new_plugin( 'child', $markers[ $ab_child ] );
	// The tool and the real guard, as on a site.
	AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $GLOBALS['ab_child_plugin'] ) );
	ok( false, 'X the request should have ended in the activation' );
	echo 'CHILD ' . $GLOBALS['ab_count'] . ' ' . $GLOBALS['ab_fails'] . "\n";
	exit( 1 );
}

/* ============================================ A1 the M5 case: a plugin */

echo "A1 a new plugin with a fatal error, activated\n";
ab_reset_activation();
$a1  = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$sha = hash_file( 'sha256', WP_PLUGIN_DIR . '/' . $a1 );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a1 ) );
$m   = ab_msg( $r );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ), 'A1 an error: ' . ab_code( $r ) );
ok( ! is_plugin_active( $a1 ), 'A1 the plugin is not active any more' );
ok( ab_has( $m, 'Not kept active: after the plugin broken/broken.php was activated', 'fatal error "Uncaught Error: Call to undefined function ab_missing()', 'in wp-content/plugins/broken/broken.php on line 6' ), 'A1 the message names the error with file and line: ' . $m );
ok( ab_has( $m, 'deactivated it again, without running its deactivation routine', 'The test requests pass again', 'Its files are unchanged on the site', 'activate it again' ), 'A1 it says it was taken back, that the site runs, that the files are there to fix' );
ok( array( array( $a1, true, false ) ) === $GLOBALS['ab_deactivations'], 'A1 deactivated silently, on this site only' );
ok( $sha === hash_file( 'sha256', WP_PLUGIN_DIR . '/' . $a1 ), 'A1 the file is byte for byte as before' );
ok( ab_in_order_a( array( 'probe:home', 'probe:mcp', 'probe:admin_post', 'activate_plugin:' . $a1, 'probe:home', 'deactivate_plugins:' . $a1, 'probe:home', 'probe:mcp', 'probe:admin_post' ) ), 'A1 order: test before, activate, test after (stops at the first fatal error), deactivate, control' );
$d = ab_data( $r );
ok( true === $d['rolled_back'] && 'fatal' === $d['why'] && 'passed' === $d['probe']['before']['result'] && 'fatal' === $d['probe']['after']['result'] && 'passed' === $d['probe']['after_rollback']['result'], 'A1 the data has the three rounds' );
ok( array() === AB_MCP_Undo::$recorded, 'A1 no undo point' );

/** Do these events occur in this order (each by its prefix)? */
function ab_in_order_a( array $want ) {
	$i = 0;
	foreach ( $GLOBALS['ab_events'] as $e ) {
		if ( $i < count( $want ) && 0 === strpos( $e, $want[ $i ] ) ) { ++$i; }
	}
	return count( $want ) === $i;
}

/* ============================================ A2 a healthy plugin */

echo "A2 a healthy plugin\n";
ab_reset_activation();
$a2 = ab_new_plugin( 'fine' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a2 ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && $a2 === $r['plugin'], 'A2 activated: ' . ab_msg( $r ) );
ok( is_plugin_active( $a2 ) && array() === $GLOBALS['ab_deactivations'], 'A2 it stays active' );
ok( true === $r['probe']['tested'] && 'passed' === $r['probe']['before']['result'] && 'passed' === $r['probe']['after']['result'], 'A2 tested before and after, passed' );
ok( ! isset( $r['warning'] ) && ab_has( $r['probe']['not_checked'], 'wp-admin screens' ), 'A2 no warning; the answer says what is not checked' );
ok( 6 === count( $GLOBALS['ab_requests'] ), 'A2 two rounds of three requests' );

/* ============================================ A3 not reached before */

echo "A3 a site that cannot reach itself\n";
ab_reset_activation();
$a3 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$GLOBALS['ab_net'] = 'down';
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a3 ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && is_plugin_active( $a3 ), 'A3 activated as before: ' . ab_msg( $r ) );
ok( ab_has( $r['warning'] ?? '', 'Not checked: no test request reached this site before the plugin broken/broken.php was activated', 'ab_mcp_php_probe_request_args' ), 'A3 with the warning "not checked": ' . ( $r['warning'] ?? '' ) );
ok( false === $r['probe']['tested'] && null === $r['probe']['after'] && 3 === count( $GLOBALS['ab_requests'] ), 'A3 no round after' );

/* ============================================ A4 the test requests switched off */

echo "A4 the test requests switched off\n";
ab_reset_activation();
$a4 = ab_new_plugin( 'fine' );
$GLOBALS['ab_filters']['ab_mcp_php_probe_enabled'] = false;
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a4 ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $a4 ) && array() === $GLOBALS['ab_requests'], 'A4 activated, no request' );
ok( ab_has( $r['warning'] ?? '', 'Not checked', 'ab_mcp_php_probe_enabled' ), 'A4 the warning names the filter' );
unset( $GLOBALS['ab_filters']['ab_mcp_php_probe_enabled'] );

/* ============================================ A5 a fatal error that was there before */

echo "A5 a fatal error that was there before\n";
ab_reset_activation();
ab_install_plugin( 'oldbug', '1.0.0', 'AB_FATAL_FRONT', true );
$a5 = ab_new_plugin( 'fine' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a5 ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $a5 ), 'A5 kept: ' . ab_msg( $r ) );
ok( ab_has( $r['warning'] ?? '', 'Kept active with a fatal error that was there before', 'oldbug.php' ), 'A5 with a warning that names the old error' );
ok( 3 === count( $r['probe']['after']['targets'] ) && 'ok' === $r['probe']['after']['targets'][1]['result'], 'A5 the round after went on past the old error' );
// A new error at another target is not hidden by the old one.
$a5b = ab_new_plugin( 'restbug', 'AB_FATAL_REST' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a5b ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a5b ) && ab_has( ab_msg( $r ), 'this MCP endpoint: fatal error' ), 'A5 a new fatal error at another target takes it back: ' . ab_msg( $r ) );
ok( is_plugin_active( 'oldbug/oldbug.php' ) && is_plugin_active( $a5 ), 'A5 and nothing else is deactivated' );

/* ============================================ A6 a cut MCP endpoint, a site that hangs */

echo "A6 a cut MCP endpoint, a site that hangs\n";
ab_reset_activation();
$a6 = ab_new_plugin( 'closer', 'AB_CLOSE_REST' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a6 ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a6 ), 'A6 the cut MCP endpoint takes it back' );
ok( ab_has( ab_msg( $r ), 'it would have cut this connection', '/wp-json/alphabridge/v1/' ) && 'mcp_cut' === ab_data( $r )['why'], 'A6 and says why: ' . ab_msg( $r ) );
ab_reset_activation();
$a6b = ab_new_plugin( 'hangs', 'AB_HANG' );
$r   = AB_Test_Activation_Guard::activate( ab_plugin_activation( $a6b ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a6b ), 'A6 a site that hangs takes it back: ' . ab_msg( $r ) );
ok( ab_has( ab_msg( $r ), 'no longer answered', 'ab_mcp_php_probe_request_args' ), 'A6 and says so' );
ok( AB_Test_Activation_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'A6 within the budget of %d s (%.1f s by the guard\'s clock)', AB_MCP_Update_Guard::BUDGET, AB_Test_Activation_Guard::elapsed() ) );

ab_reset_activation();
$a6c = ab_new_plugin( 'sleepy', 'AB_SLOW_ONCE' );
$r   = AB_Test_Activation_Guard::activate( ab_plugin_activation( $a6c ) );
$home = is_array( $r ) ? $r['probe']['after']['targets'][0] : array();
ok( ! is_wp_error( $r ) && is_plugin_active( $a6c ) && ! empty( $home['asked_again'] ) && 'ok' === $home['result'], 'A6 a first request after the activation that times out is asked again, with longer, before anything is taken back: ' . ab_msg( $r ) );

/* ============================================ A7 the budget */

echo "A7 no time left for the round after\n";
ab_reset_activation();
$a7 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
// The call has already taken 38 s (an install from a slow host, say), and
// the test before takes 6 s more (2 s per request, by the guard's clock):
// the round after and the reserve for taking it back no longer fit into
// the 45 s.
$GLOBALS['ab_net'] = 'slow:2';
AB_Test_Activation_Guard::$start = microtime( true ) - 38;
$r  = AB_Test_Activation_Guard::activate( ab_plugin_activation( $a7 ) );
ok( 'ab_mcp_activation_budget' === ab_code( $r ) && ! is_plugin_active( $a7 ), 'A7 not started, not active: ' . ab_msg( $r ) );
ok( ab_has( ab_msg( $r ), 'Nothing was changed', 'Call it again' ) && array() === ab_events_like( 'activate_plugin:' ), 'A7 nothing was switched on, and the answer says so' );

/* ============================================ A8 already active, a refusal, a throw, output */

echo "A8 already active, not installed, a throw, output\n";
ab_reset_activation();
$a8 = ab_install_plugin( 'fine', '1.0.0' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a8 ) );
ok( ! is_wp_error( $r ) && true === $r['already_active'] && array() === $GLOBALS['ab_requests'], 'A8 an active plugin is not tested again' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => 'nope/nope.php' ) );
ok( 'plugin_not_found' === ab_code( $r ) && array() === $GLOBALS['ab_requests'], 'A8 WordPress\'s refusal, before any test request' );
ab_reset_activation();
$a8b   = ab_new_plugin( 'thrower', 'AB_THROW_ON_LOAD' );
$level = ob_get_level();
$r     = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a8b ) );
ok( 'ab_mcp_activation_threw' === ab_code( $r ) && ! is_plugin_active( $a8b ), 'A8 a main file that throws while it is loaded: not active' );
ok( ab_has( ab_msg( $r ), 'Not activated', 'Error: Call to undefined function ab_top_level()', 'on line', 'the site runs as before' ), 'A8 the answer names the error: ' . ab_msg( $r ) );
ok( $level === ob_get_level() && 3 === count( $GLOBALS['ab_requests'] ), 'A8 the output buffer is closed again, no round after' );
ab_reset_activation();
$a8c = ab_new_plugin( 'talker', 'AB_OUTPUT' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a8c ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $a8c ) && ab_has( $r['warning'] ?? '', 'It is active, but while activating it WordPress or PHP said: The plugin generated unexpected output' ), 'A8 output while activating keeps it, with a warning: ' . ab_msg( $r ) );

/* ============================================ A9 the request ends before the verdict */

echo "A9 checked at shutdown\n";
ab_reset_activation();
$a9 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
AB_Test_Activation_Guard::$die_once = true;
try {
	AB_Test_Activation_Guard::activate( ab_plugin_activation( $a9 ) );
	ok( false, 'A9 the request should have ended' );
} catch ( RuntimeException $e ) {
	ok( is_plugin_active( $a9 ) && null !== AB_Test_Activation_Guard::pending(), 'A9 the request ended with the plugin on and its check pending' );
}
AB_Test_Activation_Guard::finish_at_shutdown();
ok( ! is_plugin_active( $a9 ) && null === AB_Test_Activation_Guard::pending(), 'A9 at shutdown the broken plugin is checked and deactivated' );
ab_reset_activation();
$a9b = ab_new_plugin( 'fine' );
AB_Test_Activation_Guard::$die_once = true;
try {
	AB_Test_Activation_Guard::activate( ab_plugin_activation( $a9b ) );
} catch ( RuntimeException $e ) {
	unset( $e );
}
AB_Test_Activation_Guard::finish_at_shutdown();
ok( is_plugin_active( $a9b ) && array() === $GLOBALS['ab_deactivations'], 'A9 a healthy one stays' );
AB_Test_Activation_Guard::finish_at_shutdown();
ok( array() === $GLOBALS['ab_deactivations'], 'A9 nothing a second time' );

echo "X the request ends for real after the switch (child processes)\n";
if ( function_exists( 'exec' ) ) {
	$cases = array(
		'exit-broken'  => array( 0, 3, 'an exit in activated_plugin, the site broken: taken back exactly once at shutdown' ),
		'fatal-broken' => array( 255, 3, 'a fatal error of PHP after the switch, the site broken: taken back exactly once at shutdown' ),
		'exit-healthy' => array( 0, 3, 'an exit in activated_plugin, the site healthy: tested at shutdown, stays active' ),
		'exit-in-hook' => array( 0, 1, 'an exit in the activation hook (before the list): nothing to take back' ),
		'exit-in-shutdown' => array( 0, 3, 'the broken plugin\'s own shutdown callback exits as well (Codex, 05.10.2026): the check runs before it, taken back exactly once' ),
		'theme-exit-broken' => array( 0, 3, 'another plugin\'s switch_theme callback ends the request, the theme broken: switched back at shutdown, menus and widgets as before' ),
		'theme-exit-both'   => array( 0, 3, 'that callback ends the switch back at shutdown too: no switch announced, the next visitor maps nothing' ),
		'theme-half-exit'   => array( 0, 2, 'the request ends in a theme switch that went only part of the way: taken back at shutdown (Codex, 05.10.2026)' ),
	);
	foreach ( $cases as $case => $want ) {
		$out  = array();
		$code = null;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' child-' . $case . ' 2>&1', $out, $code );
		$last = (string) end( $out );
		ok( $want[0] === $code && preg_match( '/^CHILD (\d+) 0$/', $last, $m ) && (int) $m[1] >= $want[1], "X $case: {$want[2]} (exit $code, $last)" . ( preg_match( '/^CHILD \d+ 0$/', $last ) ? '' : ' :: ' . implode( ' | ', array_slice( $out, -6 ) ) ) );
		if ( 'fatal-broken' === $case ) {
			ok( 1 === preg_match( '/Fatal error: .*Cannot redeclare/', implode( "\n", $out ) ), 'X fatal-broken: the child really ended with a fatal error of PHP' );
		}
	}
} else {
	echo "  (exec() is not available: X not run)\n";
}

/* ============================================ A10 network-wide */

echo "A10 network-wide\n";
ab_reset_activation();
$GLOBALS['ab_multisite'] = true;
$a10 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$r   = ab_network_activate_plugin( array( 'plugin' => $a10 ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active_for_network( $a10 ), 'A10 taken back network-wide: ' . ab_msg( $r ) );
ok( array( array( $a10, true, true ) ) === $GLOBALS['ab_deactivations'] && ab_has( ab_msg( $r ), '(network-wide)', 'deactivated it network-wide again' ), 'A10 silently, network-wide only' );
$a10b = ab_new_plugin( 'fine' );
$r    = ab_network_activate_plugin( array( 'plugin' => $a10b ) );
ok( ! is_wp_error( $r ) && true === $r['network_activated'] && is_plugin_active_for_network( $a10b ) && 'passed' === $r['probe']['after']['result'], 'A10 a healthy one stays network-wide' );

echo "A11 \"Network: true\" on a network, asked for this site\n";
ab_reset_activation();
$a11 = ab_new_network_plugin( 'netbug', 'AB_FATAL_LOAD' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a11 ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ), 'A11 taken back: ' . ab_msg( $r ) );
ok( ! is_plugin_active_for_network( $a11 ) && ! is_plugin_active( $a11 ) && array() === (array) get_site_option( 'active_sitewide_plugins', array() ), 'A11 WordPress activated it network-wide, and it is off on the network again, not only on this site' );
ok( array( array( $a11, true, true ) ) === $GLOBALS['ab_deactivations'] && ab_has( ab_msg( $r ), '(network-wide)', 'deactivated it network-wide again' ), 'A11 silently, network-wide, and the answer says so' );
ok( 1 === count( ab_events_like( 'activate_plugin:' . $a11 . ':network' ) ), 'A11 activated network-wide as core does it' );
$a11b = ab_new_network_plugin( 'netfine' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a11b ) );
ok( ! is_wp_error( $r ) && true === ( $r['network_wide'] ?? null ) && is_plugin_active_for_network( $a11b ) && 'passed' === $r['probe']['after']['result'], 'A11 a healthy one stays active network-wide, and the answer says network_wide: ' . ab_msg( $r ) );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a11b ) );
ok( ! is_wp_error( $r ) && true === $r['already_active'] && true === ( $r['network_wide'] ?? null ), 'A11 asked again: already active network-wide, not tested again' );
echo "A11n a site administrator without the network's right (Codex, 30.10.2026)\n";
ab_reset_activation();
$GLOBALS['ab_denied_caps'] = array( 'manage_network_plugins' );
$a11n = ab_new_network_plugin( 'netsiteadmin' );
$GLOBALS['ab_events'] = array();
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a11n ) );
ok( 'ab_mcp_forbidden' === ab_code( $r ) && ! is_plugin_active( $a11n ) && array() === ab_events_like( 'activate_plugin:' ) && array() === ab_events_like( 'probe:' ) && ab_has( ab_msg( $r ), 'Network: true', 'Nothing was changed', 'Network Admin › Plugins', 'Direct link: https://example.test/wp-admin/network/plugins.php' ), 'A11n "Network: true" refused without manage_network_plugins, before any test request: ' . ab_msg( $r ) );
$a11s = ab_new_plugin( 'siteonly' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a11s ) );
ok( ! is_wp_error( $r ) && in_array( $a11s, (array) get_option( 'active_plugins' ), true ) && array() === (array) get_site_option( 'active_sitewide_plugins', array() ), 'A11n a plugin for one site still goes on this site only: ' . ab_msg( $r ) );
update_site_option( 'active_sitewide_plugins', array( $a11n => time() ) );
$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => $a11n ) );
ok( 'ab_mcp_forbidden' === ab_code( $r ) && is_plugin_active_for_network( $a11n ) && ab_has( ab_msg( $r ), 'every site', 'Nothing was changed', 'Network Admin › Plugins', 'Direct link: https://example.test/wp-admin/network/plugins.php' ), 'A11n a plugin active on the network is not deactivated without manage_network_plugins: ' . ab_msg( $r ) );
$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => $a11s ) );
ok( ! is_wp_error( $r ) && true === $r['was_active'] && ! isset( $r['network_wide'] ) && ! is_plugin_active( $a11s ), 'A11n one of this site goes off as before: ' . ab_msg( $r ) );
$GLOBALS['ab_denied_caps'] = array();
$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => $a11n ) );
ok( ! is_wp_error( $r ) && true === ( $r['network_wide'] ?? null ) && ! is_plugin_active_for_network( $a11n ), 'A11n with the right: off on the network, and the answer says network_wide: ' . ab_msg( $r ) );
echo "A12 \"Network: true\" on a network, installed with activate\n";
ab_reset_activation();
$zip_a12 = base64_encode( "PK\x03\x04" . str_repeat( 'x', 40 ) );
function wp_tempnam( $name = '' ) { return tempnam( $GLOBALS['ab_root'], 'pkg' ); }
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	$GLOBALS['ab_plugin_info'] = ab_new_network_plugin( 'netzip', 'AB_FATAL_LOAD' );
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip_a12, 'activate' => true ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( 'netzip/netzip.php' ) && array( array( 'netzip/netzip.php', true, true ) ) === $GLOBALS['ab_deactivations'], 'A12 taken back network-wide: ' . ab_msg( $r ) );
ab_reset_activation();
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	$GLOBALS['ab_plugin_info'] = ab_new_network_plugin( 'netzip' );
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip_a12, 'activate' => true ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && true === ( $r['network_wide'] ?? null ) && is_plugin_active_for_network( 'netzip/netzip.php' ), 'A12 a healthy one: installed, active network-wide, and the answer says so' );
$GLOBALS['ab_multisite'] = false;
ab_reset_activation();
$a12c = ab_new_network_plugin( 'netsingle' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a12c ) );
ok( ! is_wp_error( $r ) && ! isset( $r['network_wide'] ) && in_array( $a12c, (array) get_option( 'active_plugins' ), true ), 'A12 without a network the header changes nothing' );

/* ============================================ A13 code that ends the answer itself */

echo "A13 a plugin that ends the answer itself, a theme that does\n";
ab_reset_activation();
$a13 = ab_new_plugin( 'finisher', 'AB_ENDS_ALL' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a13 ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a13 ), 'A13 answered without WordPress\'s report after the activation: taken back, as the test requests cannot tell harmless from harmful' );
ok( ab_has( ab_msg( $r ), 'the site answered without WordPress\'s test report', 'Most often its code ends the answer itself', 'fastcgi_finish_request()', 'HTTP 502 or 504', 'WP-Cron (wp_schedule_single_event())', 'A plugin that does this on purpose can be activated in wp-admin', 'activations and updates go ahead with "not checked"', 'can only be written, and PHP snippets saved, with allow_without_probe: true' ) && ! ab_has( ab_msg( $r ), 'If the site is only slow' ), 'A13 the message says what it means and what to do, not that the site is slow: ' . ab_msg( $r ) );
// What the message promises once it is active anyway: activations go ahead "not checked".
activate_plugin( $a13 );
$a13c = ab_new_plugin( 'fine' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a13c ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $a13c ) && ab_has( $r['warning'] ?? '', 'Not checked' ), 'A13 with such a plugin active, the next activation goes ahead "not checked", as the message says: ' . ( $r['warning'] ?? ab_msg( $r ) ) );
ab_reset_activation();
$GLOBALS['ab_multisite'] = true;
$a13n = ab_new_plugin( 'netfinisher', 'AB_ENDS_ALL' );
$r    = ab_network_activate_plugin( array( 'plugin' => $a13n ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active_for_network( $a13n ) && ab_has( ab_msg( $r ), 'can be activated network-wide under Network Admin › Plugins', 'can only be written with allow_without_probe: true' ) && ! ab_has( ab_msg( $r ), 'snippets' ), 'A13 network-wide: taken back, the message names the network way (and no snippets, which are not on a network): ' . ab_msg( $r ) );
$GLOBALS['ab_multisite'] = false;
ab_reset_activation();
$a13f = ab_new_plugin( 'frontfinisher', 'AB_ENDS_FRONT' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a13f ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a13f ) && ab_has( ab_msg( $r ), 'the site answered without WordPress\'s test report', 'The test requests then cannot check the pages where it does so.' ) && ! ab_has( ab_msg( $r ), 'cannot check this site' ), 'A13 only the home page without the report: taken back, and the message does not claim the whole site goes unchecked: ' . ab_msg( $r ) );
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_ENDS_ALL' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_stylesheet() && ab_has( ab_msg( $r ), 'A theme that does this on purpose can be switched to in wp-admin' ), 'A13 a theme: switched back, the message names the theme way: ' . ab_msg( $r ) );

/* ============================================ A14 the plugin's own filters on the list */

echo "A14 a plugin whose filters hide it from the list, or throw (Codex, 04.10.2026)\n";
/* What the next request loads: the list as the table holds it. */
function ab_stored_on( $file, $network = false ) {
	return $network ? array_key_exists( $file, (array) ( $GLOBALS['ab_opts']['active_sitewide_plugins'] ?? array() ) ) : in_array( $file, (array) ( $GLOBALS['ab_opts']['active_plugins'] ?? array() ), true );
}
ab_reset_activation();
$GLOBALS['wpdb'] = new AB_Test_Wpdb();
$a14 = ab_new_plugin( 'hider', "AB_FATAL_LOAD AB_HIDES_AFTER" );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14 ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14 ), 'A14 a broken plugin that leaves itself out of get_option(): checked and taken out of the stored list: ' . ab_code( $r ) . ' ' . json_encode( $GLOBALS['ab_opts']['active_plugins'] ?? null ) );
ok( array() !== ab_events_like( 'wpdb_update:wp_options:active_plugins' ), 'A14 past deactivate_plugins(), which read the list through its filter and found nothing to take out' );
ab_reset_activation();
$GLOBALS['wpdb'] = new AB_Test_Wpdb();
$a14b = ab_new_plugin( 'thrower', "AB_FATAL_LOAD AB_FILTER_THROWS" );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14b ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14b ), 'A14 a broken plugin whose filter on the list throws: checked and taken back all the same: ' . ab_code( $r ) );
ab_reset_activation();
$GLOBALS['wpdb'] = new AB_Test_Wpdb();
$a14c = ab_new_plugin( 'quiet', 'AB_HIDES_AFTER' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14c ) );
ok( ! is_wp_error( $r ) && ab_stored_on( $a14c ) && 'passed' === $r['probe']['after']['result'], 'A14 a healthy one that hides itself stays, checked: ' . ab_msg( $r ) );
ab_reset_activation();
$GLOBALS['wpdb'] = new AB_Test_Wpdb();
$a14d = ab_new_plugin( 'broken', "AB_FATAL_LOAD AB_FILTER_THROWS" );
AB_Test_Activation_Guard::$die_once = true;
try {
	AB_Test_Activation_Guard::activate( ab_plugin_activation( $a14d ) );
} catch ( RuntimeException $e ) {
	unset( $e );
}
ok( ab_stored_on( $a14d ) && null !== AB_Test_Activation_Guard::pending(), 'A14 the request ended with it in the stored list and its check pending' );
AB_Test_Activation_Guard::finish_at_shutdown();
ok( ! ab_stored_on( $a14d ) && null === AB_Test_Activation_Guard::pending(), 'A14 at shutdown it is checked and taken back, though asking through its filter throws' );
ab_reset_activation();
$GLOBALS['ab_multisite'] = true;
$GLOBALS['wpdb']         = new AB_Test_Wpdb();
$a14n = ab_new_plugin( 'nethider', "AB_FATAL_LOAD AB_FILTER_THROWS" );
$r    = ab_network_activate_plugin( array( 'plugin' => $a14n ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14n, true ) && array() !== ab_events_like( 'wpdb_update:wp_sitemeta:active_sitewide_plugins' ), 'A14 network-wide: taken out of the network\'s stored list: ' . ab_code( $r ) );
// Network-wide, and its activation routine puts it on this site's list too:
// taken back from both (Codex, 05.10.2026).
ab_reset_activation();
$a14s = ab_new_plugin( 'netsite', 'AB_FATAL_LOAD AB_ADDS_SITE' );
$r    = ab_network_activate_plugin( array( 'plugin' => $a14s ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14s, true ) && ! ab_stored_on( $a14s ), 'A14 network-wide, put on this site\'s list by its routine too: taken off both: ' . ab_code( $r ) . ' ' . json_encode( $GLOBALS['ab_opts']['active_plugins'] ?? null ) );
// On this site, and its activation routine puts it on the network's list
// too: taken back from both (Codex, 06.10.2026).
ab_reset_activation();
$a14t = ab_new_plugin( 'sitenet', 'AB_FATAL_LOAD AB_ADDS_NETWORK' );
$r    = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14t ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14t ) && ! ab_stored_on( $a14t, true ), 'A14 on this site, put on the network\'s list by its routine too: taken off both: ' . ab_code( $r ) . ' ' . json_encode( $GLOBALS['ab_opts']['active_sitewide_plugins'] ?? null ) );
// On this site's list before, broken only network-wide: the site keeps it.
ab_reset_activation();
$a14k = ab_new_plugin( 'netkeep', 'AB_FATAL_IF_NETWORK' );
update_option( 'active_plugins', array( $a14k ) );
$r = ab_network_activate_plugin( array( 'plugin' => $a14k ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! ab_stored_on( $a14k, true ) && ab_stored_on( $a14k ), 'A14 on this site\'s list before: taken back network-wide only, this site keeps it: ' . ab_code( $r ) . ' ' . json_encode( $GLOBALS['ab_opts']['active_plugins'] ?? null ) );
$GLOBALS['ab_multisite'] = false;
ab_reset_activation();
// A host keeps a plugin on through a filter (an mu-plugin), not in the stored
// list: it is active as WordPress sees it, as before.
$a14f = ab_new_plugin( 'forced' );
add_filter( 'option_active_plugins', static function ( $l ) use ( $a14f ) { return array_merge( (array) $l, array( $a14f ) ); } );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14f ) );
ok( ! is_wp_error( $r ) && true === ( $r['already_active'] ?? null ) && array() === $GLOBALS['ab_requests'], 'A14 a plugin a host keeps on through a filter: already active, not "not activated": ' . ab_msg( $r ) );
ab_reset_activation();
// The other way round: an old row in the stored list, the plugin kept off by
// a filter of code that runs on every request. Not "already active": activated and tested.
$a14g = ab_new_plugin( 'hidden' );
update_option( 'active_plugins', array( $a14g ) );
add_filter( 'option_active_plugins', static function ( $l ) use ( $a14g ) { return array_values( array_diff( (array) $l, array( $a14g ) ) ); } );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a14g ) );
ok( ! is_wp_error( $r ) && empty( $r['already_active'] ) && array() !== $GLOBALS['ab_requests'], 'A14 a stored row a filter keeps off: not "already active", the activation is tested (Codex, 05.10.2026): ' . ab_msg( $r ) );
ab_reset_activation();
// Asking whether it is on throws once, right after the switch: unknown counts as on.
$a14e  = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$job   = ab_plugin_activation( $a14e );
$asked = 0;
$job['is_on'] = static function () use ( &$asked, $a14e ) {
	if ( 1 === ++$asked ) {
		throw new RuntimeException( 'the state could not be read' );
	}
	return is_plugin_active( $a14e );
};
$r = AB_Test_Activation_Guard::activate( $job );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ! is_plugin_active( $a14e ), 'A14 where asking throws, the state counts as on: checked and taken back, not "not activated": ' . ab_code( $r ) );
ab_reset_activation();

/* ============================================ A15 it switches itself off in the test requests */

echo "A15 a plugin that switches itself off when the site loads it (Codex, 05.10.2026)\n";
ab_reset_activation();
$a15 = ab_new_plugin( 'needy', 'AB_SELF_OFF' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $a15 ) );
ok( 'ab_mcp_activation_switched_off' === ab_code( $r ) && ! ab_stored_on( $a15 ), 'A15 not reported as activated: ' . ab_code( $r ) . ' ' . ab_msg( $r ) );
ok( ab_has( ab_msg( $r ), 'no longer active', 'switched it off', 'Nothing was taken back' ) && array() === $GLOBALS['ab_deactivations'], 'A15 the answer says what happened, and nothing was deactivated by Pro' );
ab_reset_activation();

/* ============================================ T1 the M5 case: a theme */

echo "T1 a new theme with a fatal error in functions.php\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$sha = hash_file( 'sha256', get_theme_root() . '/shiny/functions.php' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$m   = ab_msg( $r );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ), 'T1 an error: ' . ab_code( $r ) );
ok( 'calm' === get_stylesheet() && 'calm' === get_template(), 'T1 the previous theme is active again, stylesheet and template' );
ok( ab_has( $m, 'Not kept active: after the theme shiny was activated', 'fatal error', 'wp-content/themes/shiny/functions.php on line 2', 'The previous theme calm is active again', 'over SFTP, or in the theme file editor of wp-admin', 'activate it again' ), 'T1 the message names the error, the previous theme and the way (in Pro: wp_write_theme_file, which stays in Pro): ' . $m );
ok( array() === AB_MCP_Undo::$recorded, 'T1 no undo point for a switch that did not stay' );
ok( $sha === hash_file( 'sha256', get_theme_root() . '/shiny/functions.php' ), 'T1 the theme\'s files are unchanged' );
ok( ab_in_order_a( array( 'probe:home', 'switch_theme:shiny', 'probe:home', 'switch_theme:calm', 'probe:home' ) ), 'T1 switched back with switch_theme(), as wp-admin does' );

echo "T2 a healthy theme, a child theme\n";
ab_reset_activation();
ab_new_theme( 'fresh' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'fresh' ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && 'fresh' === get_stylesheet() && 'passed' === $r['probe']['after']['result'], 'T2 switched and kept: ' . ab_msg( $r ) );
ok( 'undo_1' === $r['undo'] && 1 === count( AB_MCP_Undo::$recorded ) && 'calm' === AB_MCP_Undo::$recorded[0]['data']['stylesheet'], 'T2 the undo point is recorded, for the previous theme' );
ok( ab_in_order_a( array( 'switch_theme:fresh', 'probe:home', 'probe:mcp', 'probe:admin_post', 'undo:record:wp_activate_theme' ) ), 'T2 the undo point only after the test after' );
ab_reset_activation();
ab_new_theme( 'parent' );
ab_new_theme( 'kid', '', 'parent' );
file_put_contents( get_theme_root() . '/parent/functions.php', "<?php\n// AB_FATAL_FRONT\n" );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'kid' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_stylesheet() && 'calm' === get_template() && ab_has( ab_msg( $r ), 'themes/parent/functions.php' ), 'T2 a child whose parent fails: back to calm, the parent named: ' . ab_msg( $r ) );

echo "T3 a previous theme switch_theme() would not take\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
// As WordPress keeps them: the active theme in the options switch_theme() writes.
update_option( 'stylesheet', 'calm' );
update_option( 'template', 'calm' );
update_option( 'current_theme', 'Test calm' );
$GLOBALS['ab_requirements_fail'] = array( 'calm' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_option( 'stylesheet' ) && 'calm' === get_option( 'template' ) && 'calm' === get_stylesheet(), 'T3 back through the options switch_theme() wrote: ' . ab_msg( $r ) );
ok( array() === ab_events_like( 'switch_theme:calm' ) && false === get_option( 'theme_switched' ) && 'Test calm' === get_option( 'current_theme' ), 'T3 without switch_theme() (it would wp_die()), and the other options as they were' );
ok( array() === AB_MCP_Undo::$recorded, 'T3 no undo point' );

echo "T3c the autoload flags of both themes' settings after a switch back without switch_theme()\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
update_option( 'stylesheet', 'calm' );
update_option( 'template', 'calm' );
update_option( 'current_theme', 'Test calm' );
update_option( 'theme_mods_calm', array( 'nav_menu_locations' => array( 'primary' => 3 ) ) );
update_option( 'theme_mods_shiny', array( 'custom_logo' => 9 ) );
$GLOBALS['ab_autoload']          = array( 'theme_mods_calm' => 'on', 'theme_mods_shiny' => 'off' );
$GLOBALS['ab_requirements_fail'] = array( 'calm' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_option( 'stylesheet' ) && array() === ab_events_like( 'switch_theme:calm' ), 'T3c taken back through the options, without switch_theme(): ' . ab_msg( $r ) );
ok( 'on' === $GLOBALS['ab_autoload']['theme_mods_calm'] && 'off' === $GLOBALS['ab_autoload']['theme_mods_shiny'], 'T3c the active theme\'s settings load with every request again, those of the theme taken back do not: ' . json_encode( $GLOBALS['ab_autoload'] ) );

echo "T3d the same where switch_theme() switches back: the flags as they were, not as switch_theme() sets them\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
update_option( 'theme_mods_calm', array( 'nav_menu_locations' => array( 'primary' => 3 ) ) );
update_option( 'theme_mods_shiny', array( 'custom_logo' => 9 ) );
$GLOBALS['ab_autoload'] = array( 'theme_mods_calm' => 'auto', 'theme_mods_shiny' => 'auto' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_stylesheet() && array() !== ab_events_like( 'switch_theme:calm' ), 'T3d taken back with switch_theme(): ' . ab_msg( $r ) );
ok( 'auto' === $GLOBALS['ab_autoload']['theme_mods_calm'] && 'auto' === $GLOBALS['ab_autoload']['theme_mods_shiny'], 'T3d both flags are what they were before the switch: ' . json_encode( $GLOBALS['ab_autoload'] ) );

echo "T3b the same with a filter on current_theme: the table gets back what it held (Codex, 05.10.2026)\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
update_option( 'stylesheet', 'calm' );
update_option( 'template', 'calm' );
update_option( 'current_theme', 'Test calm' );
$GLOBALS['ab_requirements_fail'] = array( 'calm' );
// This request keeps its copy of the options, and a filter hands out calm's
// name whatever is stored: update_option() would compare with that and write nothing.
$GLOBALS['ab_ocache'] = array();
add_filter( 'option_current_theme', static function () { return 'Test calm'; } );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $GLOBALS['ab_opts']['stylesheet'] && 'Test calm' === $GLOBALS['ab_opts']['current_theme'], 'T3b back, and the table holds calm\'s name again, not shiny\'s: ' . json_encode( array( $GLOBALS['ab_opts']['stylesheet'] ?? null, $GLOBALS['ab_opts']['current_theme'] ?? null ) ) );

// A filter that hands out another name than the stored one: the snapshot
// takes the stored one, not the filtered.
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
update_option( 'stylesheet', 'calm' );
update_option( 'template', 'calm' );
update_option( 'current_theme', 'Test calm' );
$GLOBALS['ab_requirements_fail'] = array( 'calm' );
$GLOBALS['ab_ocache']            = array();
add_filter( 'option_current_theme', static function () { return 'A filtered name'; } );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'Test calm' === $GLOBALS['ab_opts']['current_theme'], 'T3b the stored name comes back, not the one the filter hands out: ' . json_encode( $GLOBALS['ab_opts']['current_theme'] ?? null ) );

echo "T4 not reached before: switched, not checked\n";
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$GLOBALS['ab_net'] = 'down';
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( ! is_wp_error( $r ) && 'shiny' === get_stylesheet() && ab_has( $r['warning'] ?? '', 'Not checked' ) && is_string( $r['undo'] ), 'T4 switched as before, with "not checked" and its undo point' );

/* ============================================ I the installs with activate */

echo "I1 a plugin ZIP with activate\n";
$zip = base64_encode( "PK\x03\x04" . str_repeat( 'x', 40 ) );
ab_reset_activation();
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	ab_install_plugin( 'zipped', '1.0.0', 'AB_FATAL_LOAD', false );
	$GLOBALS['ab_plugin_info'] = 'zipped/zipped.php';
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip, 'activate' => true ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ab_has( ab_msg( $r ), 'The plugin zipped/zipped.php was installed. Not kept active' ), 'I1 the install is named first: ' . ab_msg( $r ) );
ok( is_file( WP_PLUGIN_DIR . '/zipped/zipped.php' ) && ! is_plugin_active( 'zipped/zipped.php' ) && true === ab_data( $r )['installed'], 'I1 installed, not active' );
ab_reset_activation();
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	ab_install_plugin( 'zipped', '1.0.0', '', false );
	$GLOBALS['ab_plugin_info'] = 'zipped/zipped.php';
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip, 'activate' => true ) );
ok( ! is_wp_error( $r ) && true === $r['installed'] && true === $r['activated'] && 'passed' === $r['probe']['after']['result'] && is_plugin_active( 'zipped/zipped.php' ), 'I1 a healthy one is installed and active: ' . ab_msg( $r ) );

echo "I2 a theme ZIP with activate\n";
ab_reset_activation();
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	ab_new_theme( 'zippy', 'AB_FATAL_LOAD' );
	$GLOBALS['ab_theme_info'] = new WP_Theme( 'zippy' );
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_theme_zip( array( 'content' => $zip, 'activate' => true ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_stylesheet() && ab_has( ab_msg( $r ), 'The theme zippy was installed. Not kept active' ), 'I2 taken back, the install named first: ' . ab_msg( $r ) );
ab_reset_activation();
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	ab_new_theme( 'zippy' );
	$GLOBALS['ab_theme_info'] = new WP_Theme( 'zippy' );
	return true;
};
$r = AB_MCP_Tools_Install_Update::install_theme_zip( array( 'content' => $zip, 'activate' => true ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && 'zippy' === get_stylesheet() && is_string( $r['undo'] ) && 'passed' === $r['probe']['after']['result'], 'I2 a healthy one is switched to the way wp_activate_theme does, with an undo point: ' . ab_msg( $r ) );
ab_reset_activation();
$GLOBALS['ab_requirements_fail'] = array( 'zippy' );
$r = AB_MCP_Tools_Install_Update::install_theme_zip( array( 'content' => $zip, 'activate' => true ) );
ok( ! is_wp_error( $r ) && false === $r['activated'] && ab_has( $r['activation_error'] ?? '', 'requirements' ) && 'calm' === get_stylesheet(), 'I2 a theme whose requirements are not met: installed, not switched, the reason in the answer (switch_theme() would have ended the request)' );

/* ============================================ M menus and widgets of a switch taken back */

echo "M1 the theme's error ends requests before init (Codex, 03.10.2026)\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$m  = ab_msg( $r );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'], 'M1 taken back: ' . ab_code( $r ) );
ok( array( 'primary' => 10, 'footer' => 11 ) === $s1['theme_mods_calm']['nav_menu_locations'], 'M1 calm keeps its menus after the control round (not shiny\'s primary 20): ' . json_encode( $s1['theme_mods_calm'] ) );
ok( $s0 === $s1, 'M1 menus, widgets, rewrite rules and both themes\' settings as before the switch: ' . ab_menu_diff( $s0, $s1 ) );
ok( ab_has( $m, 'Menu locations, widgets, rewrite rules and the settings of both themes are as they were before the switch' ), 'M1 the answer says so: ' . $m );
ab_visitor();
ok( $s0 === ab_menu_state(), 'M1 and the next visitor finds no switch to map either' );

echo "M2 the theme's error comes after init: the test requests mapped the menus onto it\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_FRONT' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$s1 = ab_menu_state();
ok( array() !== ab_events_like( 'check_theme_switched:shiny:' ), 'M2 a test request ran check_theme_switched() with shiny active (calm\'s menus mapped onto it, its widgets and rewrite rules saved)' );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'], 'M2 taken back: ' . ab_code( $r ) );
ok( $s0 === $s1, 'M2 menus, widgets, rewrite rules and both themes\' settings as before the switch: ' . ab_menu_diff( $s0, $s1 ) );
ok( array() === ab_events_like( 'check_theme_switched:calm:' ), 'M2 the control round with calm mapped nothing' );

echo "M3 a switch that is kept is WordPress's, as in wp-admin\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'fresh' );
$GLOBALS['ab_opts']['theme_mods_fresh'] = array( 'nav_menu_locations' => array( 'primary' => 30 ) );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'fresh' ) );
$s1 = ab_menu_state();
ok( ! is_wp_error( $r ) && 'fresh' === $s1['stylesheet'], 'M3 kept: ' . ab_msg( $r ) );
ok( 10 === ( $s1['theme_mods_fresh']['nav_menu_locations']['primary'] ?? null ) && array( 'rules of' => 'fresh' ) === $s1['rewrite_rules'] && '' === $s1['theme_switched'], 'M3 WordPress mapped calm\'s primary 10 onto fresh and saved its rewrite rules, nothing written back: ' . json_encode( $s1['theme_mods_fresh'] ) );

echo "M4 what does not come back is named\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_FRONT' );
// A filter on the site keeps the widget areas as the test request left them.
$GLOBALS['ab_on_init'] = static function ( $target ) {
	ab_init( $target );
	if ( 'shiny' === get_stylesheet() ) {
		$GLOBALS['ab_opts_stuck']['sidebars_widgets'] = true;
	}
};
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$m  = ab_msg( $r );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'], 'M4 still taken back: ' . ab_code( $r ) );
ok( ab_has( $m, 'Not everything came back as it was before the switch (sidebars_widgets differ)', 'under Appearance' ) && ! ab_has( $m, 'are as they were before the switch' ), 'M4 the answer names the option: ' . $m );
ok( array( 'primary' => 10, 'footer' => 11 ) === $s1['theme_mods_calm']['nav_menu_locations'], 'M4 the menus came back all the same' );

echo "M5 a theme switch_theme() cannot take back: the options written back, the menus too\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_FRONT' );
$GLOBALS['ab_requirements_fail'] = array( 'calm' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'] && array() === ab_events_like( 'switch_theme:calm' ), 'M5 back without switch_theme(): ' . ab_code( $r ) );
ok( $s0 === $s1, 'M5 menus, widgets, rewrite rules and both themes\' settings as before the switch, though no switch_theme() dropped this request\'s copy of the options: ' . ab_menu_diff( $s0, $s1 ) );

echo "M6 only the options of a theme switch are written back\n";
ab_reset_activation();
update_option( 'siteurl', 'http://site.test' );
update_option( 'admin_email', 'admin@site.test' );
$off = AB_MCP_Theme_Switch_State::restore( array( 'siteurl' => 'http://elsewhere.test', 'active_plugins' => array( 'x/x.php' ), 'admin_email' => 'x@elsewhere.test', 'ab_mcp_x' => 'y', 'theme_switched' => '' ) );
ok( 'http://site.test' === get_option( 'siteurl' ) && array() === get_option( 'active_plugins' ) && 'admin@site.test' === get_option( 'admin_email' ) && false === get_option( 'ab_mcp_x' ) && '' === get_option( 'theme_switched' ), 'M6 siteurl, active_plugins, admin_email and an option of its own are left alone, theme_switched is written' );
ok( array( 'siteurl', 'active_plugins', 'admin_email', 'ab_mcp_x' ) === $off, 'M6 and they are named as not written back: ' . json_encode( $off ) );

echo "M7 a switch pending before: mapped once, by the test requests before, not again\n";
ab_reset_activation();
ab_menu_site();
$GLOBALS['ab_opts']['theme_switched']              = 'olde';
$GLOBALS['ab_opts']['theme_switch_menu_locations'] = array( 'primary' => 40, 'footer' => 41 );
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ab_visitor();
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'], 'M7 taken back: ' . ab_code( $r ) );
ok( 1 === count( ab_events_like( 'check_theme_switched:calm:' ) ), 'M7 the pending switch was mapped exactly once (by the test request before the switch), not again after it was taken back: ' . count( ab_events_like( 'check_theme_switched:calm:' ) ) );
ok( array( 'primary' => 40, 'footer' => 41 ) === $s1['theme_mods_calm']['nav_menu_locations'] && '' === $s1['theme_switched'], 'M7 calm keeps what that mapping gave it: ' . json_encode( $s1['theme_mods_calm']['nav_menu_locations'] ) );

echo "M8 an object in the theme mods (header_image_data) is no false alarm\n";
ab_reset_activation();
$s0  = ab_menu_site();
$hdr = (object) array( 'attachment_id' => 5, 'url' => 'http://site.test/header.jpg', 'width' => 1200, 'height' => 280 );
$GLOBALS['ab_opts']['theme_mods_calm']['header_image_data'] = $hdr;
$s0  = ab_menu_state();
ab_new_theme( 'shiny', 'AB_FATAL_FRONT' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$m  = ab_msg( $r );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && serialize( $s0 ) === serialize( $s1 ), 'M8 taken back, everything as before: ' . ab_menu_diff( $s0, $s1 ) );
ok( ab_has( $m, 'are as they were before the switch' ) && ! ab_has( $m, 'Not everything came back' ), 'M8 and the answer says so, though every read of the table gives a new object: ' . $m );

echo "M9 no request finds the switch back announced\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 2 === count( $GLOBALS['ab_views'] ), 'M9 taken back, both switches seen: ' . ab_code( $r ) );
$during = $GLOBALS['ab_views'][1];
ok( 'calm' === $during['stylesheet'] && '' === $during['theme_switched'] && ! array_key_exists( 'theme_switch_menu_locations', $during ), 'M9 a request loading its options while switch_theme() switches back finds no switch pending: ' . json_encode( array( $during['theme_switched'], $during['theme_switch_menu_locations'] ?? '(none)' ) ) );
ab_late_request( $during );
ok( $s0 === ab_menu_state(), 'M9 and maps nothing when it reaches init afterwards: ' . ab_menu_diff( $s0, ab_menu_state() ) );

echo "M10 a request that loaded its options with the new theme active reaches init after it was taken back\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && $s0 === ab_menu_state(), 'M10 taken back, everything as before: ' . ab_code( $r ) );
$then = $GLOBALS['ab_views'][0];
ok( 'shiny' === $then['stylesheet'] && 'calm' === $then['theme_switched'], 'M10 that request loaded shiny with the switch from calm pending' );
ab_late_request( $then );
ok( $s0 === ab_menu_state(), 'M10 it maps nothing onto shiny, rewrites no widgets and saves no rewrite rules: ' . ab_menu_diff( $s0, ab_menu_state() ) );
ok( array() !== ab_events_like( 'remove_action:init' ) && array() === ab_events_like( 'check_theme_switched:shiny:late' ), 'M10 Pro took core\'s check out of that request' );
// The theme's own code, still running in that request, writes its settings
// after init: they go to the theme it loaded, not to the previous one.
ab_late_request(
	$then,
	static function () {
		set_theme_mod( 'seeded', 'by shiny' );
	}
);
ok( $s0['theme_mods_calm'] === $GLOBALS['ab_opts']['theme_mods_calm'] && 'by shiny' === ( $GLOBALS['ab_opts']['theme_mods_shiny']['seeded'] ?? null ), 'M10 what its code writes after init goes to shiny\'s settings, calm\'s stay as they were' );
// A request that loaded a pending switch that is gone by now (a switch back
// announced it for a moment, before hold() existed).
ab_late_request( array_merge( $GLOBALS['ab_opts'], array( 'theme_switched' => 'shiny', 'theme_switch_menu_locations' => array( 'primary' => 20, 'social' => 21 ) ) ) );
ok( array( 'primary' => 10, 'footer' => 11 ) === $GLOBALS['ab_opts']['theme_mods_calm']['nav_menu_locations'], 'M10 a pending switch it loaded that is gone by now maps nothing onto calm: ' . json_encode( $GLOBALS['ab_opts']['theme_mods_calm']['nav_menu_locations'] ) );
// A switch pending in the database for calm (one nobody has run yet): still
// nothing onto shiny, the theme that request loaded.
$GLOBALS['ab_opts']['theme_switched'] = 'olde';
$before = ab_menu_state();
ab_late_request( $then );
ok( $before === ab_menu_state(), 'M10 with a switch pending for calm, it still maps nothing onto shiny: ' . ab_menu_diff( $before, ab_menu_state() ) );
$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/alphabridge-mcp.php' );
ok( 1 === preg_match( "~^require_once AB_MCP_DIR \\. 'includes/lifecycle/class-theme-switch-state\\.php';\\s*\\nAB_MCP_Theme_Switch_State::hook\\(\\);~m", $main ), 'M10 the plugin\'s main file hooks it in on every request' );

echo "M12 a late request with the other theme loaded leaves a new pending switch to the next one\n";
ab_reset_activation();
ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$then                                              = $GLOBALS['ab_views'][0];
$GLOBALS['ab_opts']['theme_switched']              = 'olde';
$GLOBALS['ab_opts']['theme_switch_menu_locations'] = array( 'primary' => 50 );
$GLOBALS['ab_events']                              = array();
ab_late_request( $then );
ok( array() === ab_events_like( 'check_theme_switched:' ), 'M12 the late request (shiny loaded) maps nothing: ' . json_encode( ab_events_like( 'check_theme_switched:' ) ) );
ab_visitor();
ok( array( 'check_theme_switched:calm:visitor' ) === ab_events_like( 'check_theme_switched:' ) && 50 === $GLOBALS['ab_opts']['theme_mods_calm']['nav_menu_locations']['primary'], 'M12 the next visitor (calm) maps the pending switch: ' . json_encode( ab_events_like( 'check_theme_switched:' ) ) );

echo "M13 a switch pending on a site with Pro is mapped by the next request, as without it\n";
ab_reset_activation();
ab_menu_site();
$GLOBALS['ab_ocache'] = null;
update_option( 'stylesheet', 'fresh' );
update_option( 'template', 'fresh' );
update_option( 'theme_switched', 'calm' );
update_option( 'theme_switch_menu_locations', array( 'primary' => 10, 'footer' => 11 ) );
ab_visitor();
ok( 10 === ( $GLOBALS['ab_opts']['theme_mods_fresh']['nav_menu_locations']['primary'] ?? null ) && '' === $GLOBALS['ab_opts']['theme_switched'] && array() === ab_events_like( 'remove_action:init' ), 'M13 mapped, nothing taken out: ' . json_encode( $GLOBALS['ab_opts']['theme_mods_fresh'] ?? null ) );

echo "M14 a request that runs core's check while the switch is taken back writes nothing after it\n";
/**
 * Another request, while this one waits: its own copy of the options, and
 * without the filters this one added to its writes.
 */
function ab_meanwhile( callable $other ) {
	$cache = $GLOBALS['ab_ocache'];
	$hooks = $GLOBALS['ab_hooks'];
	$GLOBALS['ab_ocache'] = array();
	foreach ( array_keys( $GLOBALS['ab_hooks'] ) as $tag ) {
		if ( 0 === strpos( $tag, 'pre_update_option_' ) ) {
			unset( $GLOBALS['ab_hooks'][ $tag ] );
		}
	}
	try {
		$other();
	} finally {
		$GLOBALS['ab_ocache'] = $cache;
		$GLOBALS['ab_hooks']  = $hooks;
	}
}
/**
 * The switch to shiny as the guard makes it, still pending, and a request that
 * gets to init in that moment, so core's check runs in it; shiny's slow
 * callback ($slot) lasts while the MCP request takes the switch back as the
 * guard's take_back does (or, with $take_back false, keeps it). Returns the
 * state before the switch and what restore() reported.
 */
function ab_m14( $slot, $take_back = true ) {
	ab_reset_activation();
	$s0 = ab_menu_site();
	ab_new_theme( 'shiny' );
	$state = AB_MCP_Theme_Switch_State::take( 'shiny' );
	switch_theme( 'shiny' );
	$then = $GLOBALS['ab_opts'];
	$off  = null;
	$GLOBALS['ab_slow_switch'] = array(
		$slot,
		static function () use ( $state, $take_back, &$off ) {
			if ( $take_back ) {
				ab_meanwhile(
					static function () use ( $state, &$off ) {
						AB_MCP_Theme_Switch_State::hold( $state );
						try {
							switch_theme( 'calm' );
						} finally {
							AB_MCP_Theme_Switch_State::release();
						}
						$off = AB_MCP_Theme_Switch_State::restore( $state );
					}
				);
			}
		},
	);
	$GLOBALS['ab_events'] = array();
	try {
		ab_late_request( $then );
	} finally {
		unset( $GLOBALS['ab_slow_switch'] );
	}
	return array( $s0, $off );
}
list( $s0, $off ) = ab_m14( 'first' );
ok( array( 'check_theme_switched:shiny:late' ) === ab_events_like( 'check_theme_switched:' ) && array() === $off, 'M14 core\'s check ran in that request, and the switch was taken back while it waited: ' . json_encode( array( ab_events_like( 'check_theme_switched:' ), $off ) ) );
ok( $s0 === ab_menu_state(), 'M14 what it writes after that (menus onto shiny, widgets, rewrite rules, the note) does not land: ' . ab_menu_diff( $s0, ab_menu_state() ) );
list( $s0 ) = ab_m14( 'last' );
ok( $s0 === ab_menu_state(), 'M14 with the slow callback after core\'s own two, the rewrite rules saved at wp_loaded do not land either: ' . ab_menu_diff( $s0, ab_menu_state() ) );
// A switch that is kept: the same request maps as core does.
ab_m14( 'first', false );
ok( 'shiny' === $GLOBALS['ab_opts']['stylesheet'] && 10 === ( $GLOBALS['ab_opts']['theme_mods_shiny']['nav_menu_locations']['primary'] ?? null ) && array( 'rules of' => 'shiny' ) === $GLOBALS['ab_opts']['rewrite_rules'] && '' === $GLOBALS['ab_opts']['theme_switched'], 'M14 with the switch kept, it maps calm\'s menus onto shiny and saves its rewrite rules: ' . json_encode( $GLOBALS['ab_opts']['theme_mods_shiny']['nav_menu_locations'] ?? null ) );

echo "M11 the switch back fails: the new theme's switch stays announced\n";
ab_reset_activation();
ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
// Once shiny is active, the option that names the active theme takes no write.
add_action(
	'switch_theme',
	static function () {
		if ( 'shiny' === $GLOBALS['ab_opts']['stylesheet'] ) {
			$GLOBALS['ab_opts_stuck']['stylesheet'] = true;
		}
	},
	20,
	0
);
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rollback_failed' === ab_code( $r ) && 'shiny' === $s1['stylesheet'], 'M11 not taken back, shiny still active: ' . ab_code( $r ) );
ok( 'calm' === $s1['theme_switched'] && array( 'primary' => 10, 'footer' => 11 ) === $s1['theme_switch_menu_locations'], 'M11 its switch from calm is announced as before, for its own after_switch_theme: ' . json_encode( array( $s1['theme_switched'], $s1['theme_switch_menu_locations'] ) ) );

echo "M15 the site filters the options of a switch: the table gets back what it held (Codex, 04.10.2026)\n";
ab_reset_activation();
$s0 = ab_menu_site();
ab_new_theme( 'shiny', 'AB_FATAL_LOAD' );
// Code on the site that changes what get_option() hands out, not what is stored.
add_filter(
	'option_sidebars_widgets',
	static function ( $v ) {
		$v                   = (array) $v;
		$v['sidebar-runtime'] = array( 'injected-1' );
		return $v;
	}
);
add_filter(
	'option_theme_mods_calm',
	static function ( $v ) {
		$v                                  = (array) $v;
		$v['nav_menu_locations']['runtime'] = 99;
		return $v;
	}
);
add_filter( 'pre_option_rewrite_rules', static function () { return array( 'rules of' => 'a filter' ); } );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
$m  = ab_msg( $r );
$s1 = ab_menu_state();
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === $s1['stylesheet'], 'M15 taken back: ' . ab_code( $r ) );
ok( $s0 === $s1, 'M15 the table holds what it held before the switch, not what the filters hand out: ' . ab_menu_diff( $s0, $s1 ) );
ok( ab_has( $m, 'are as they were before the switch' ), 'M15 and the answer says so, checked against the table: ' . $m );

/* ============================================ H a theme switch half done */

echo "H a theme switch that goes only part of the way (Codex, 05.10.2026)\n";
// A filter keeps the stylesheet: switch_theme() writes shiny's template, the
// stylesheet stays calm's.
ab_reset_activation();
ab_new_theme( 'shiny' );
$GLOBALS['ab_opts_stuck']['stylesheet'] = true;
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'half_switched' === ( ab_data( $r )['why'] ?? null ), 'H1 the stylesheet kept: taken back, not "not activated" with the template left: ' . ab_code( $r ) . ' ' . ab_msg( $r ) );
ok( 'calm' === get_stylesheet() && 'calm' === get_template() && ab_has( ab_msg( $r ), 'only part of the way', 'previous state is active again' ), 'H1 calm with its template again, and the answer says what happened: ' . get_stylesheet() . '/' . get_template() );
// A filter keeps the template: the stylesheet becomes shiny's, the template stays calm's.
ab_reset_activation();
ab_new_theme( 'shiny' );
$GLOBALS['ab_opts_stuck']['template'] = true;
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'calm' === get_stylesheet() && 'calm' === get_template(), 'H2 the template kept: not kept as activated with shiny\'s stylesheet on calm\'s template, taken back: ' . ab_code( $r ) . ' ' . get_stylesheet() . '/' . get_template() );
// Its code switches the site on to a third theme during the test requests
// (Codex, 05.10.2026, round 6): taken back to calm, not "the site runs as before" with fresh.
ab_reset_activation();
ab_new_theme( 'fresh' );
ab_new_theme( 'shiny', 'AB_SWITCH_TO_fresh' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && 'moved' === ( ab_data( $r )['why'] ?? null ) && 'calm' === get_stylesheet() && 'calm' === get_template(), 'H5 switched on to fresh during the test requests: taken back to calm: ' . ab_code( $r ) . ' ' . get_stylesheet() . '/' . get_template() );
ok( ab_has( ab_msg( $r ), 'switched the site on during the test requests', 'stylesheet fresh with template fresh', 'previous state is active again' ), 'H5 the answer names what it switched to: ' . ab_msg( $r ) );
// Its code switches back to calm during the test requests, while this request
// keeps its own copy of the options (the object cache): read from the table.
ab_reset_activation();
ab_new_theme( 'shiny', 'AB_SWITCH_TO_calm' );
$GLOBALS['ab_ocache'] = array();
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_switched_off' === ab_code( $r ) && 'calm' === $GLOBALS['ab_opts']['stylesheet'], 'H6 back on calm in the table, this request\'s copy still says shiny: not kept as activated: ' . ab_code( $r ) );
// A healthy child theme: on means its own stylesheet with its parent's template.
ab_reset_activation();
ab_new_theme( 'parent' );
ab_new_theme( 'kid', '', 'parent' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'kid' ) );
ok( ! is_wp_error( $r ) && 'kid' === get_stylesheet() && 'parent' === get_template(), 'H4 a child theme is on with its parent\'s template: ' . ab_msg( $r ) );
ab_reset_activation();

/* ============================================ K what the site loads */

echo "K what the site loads, not only what the table holds (Codex, 06.10.2026)\n";
// An mu-plugin or the host keeps a plugin off on every request, while a row
// for it stands in the table from before.
ab_reset_activation();
$k1 = ab_new_plugin( 'hidden' );
update_option( 'active_plugins', array( $k1 ) );
$GLOBALS['ab_everywhere']['option_active_plugins'] = static function ( $list ) use ( $k1 ) {
	return array_values( array_diff( (array) $list, array( $k1 ) ) );
};
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $k1 ) );
ok( 'ab_mcp_activation_hidden' === ab_code( $r ) && ab_has( ab_msg( $r ), 'WordPress lists the plugin hidden/hidden.php as active, but code that runs on every request keeps it from loading', 'the test requests after the activation did not load it', 'It was on the list of active plugins before this call, and stays on it, as it was', 'wp-content/mu-plugins' ), 'K1 kept off by code that runs on every request: not reported as activated: ' . ab_msg( $r ) );
ok( array( $k1 ) === array_values( (array) $GLOBALS['ab_opts']['active_plugins'] ) && array() === $GLOBALS['ab_deactivations'], 'K1 the row that stood there before stays, nothing deactivated' );
ab_reset_activation();
$k1b = ab_new_plugin( 'hidden' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $k1b ) );
$st  = $r['probe']['after']['targets'][0]['state'] ?? null;
ok( ! is_wp_error( $r ) && true === $r['activated'] && is_array( $st ) && in_array( $k1b, $st['plugins'], true ), 'K1 counter-check: without that filter the test requests report it loaded, and it is kept: ' . ab_msg( $r ) );

ab_reset_activation();
$k2 = ab_new_plugin( 'blocked' );
$GLOBALS['ab_everywhere']['option_active_plugins'] = static function ( $list ) use ( $k2 ) {
	return array_values( array_diff( (array) $list, array( $k2 ) ) );
};
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $k2 ) );
ok( 'ab_mcp_activation_hidden' === ab_code( $r ) && ab_has( ab_msg( $r ), 'This call took it off the list again, as it was before' ), 'K2 a plugin the host always keeps off: not reported as activated: ' . ab_msg( $r ) );
ok( ! in_array( $k2, (array) ( $GLOBALS['ab_opts']['active_plugins'] ?? array() ), true ), 'K2 the row this call wrote is gone again' );

ab_reset_activation();
$k3 = ab_new_plugin( 'blocked' );
$GLOBALS['ab_everywhere']['option_active_plugins'] = static function ( $list ) use ( $k3 ) {
	return array_values( array_diff( (array) $list, array( $k3 ) ) );
};
$GLOBALS['ab_no_state'] = true;
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $k3 ) );
ok( ! is_wp_error( $r ) && true === $r['activated'], 'K3 where no test request reports what it loaded (a cached page), it is judged by the table, as before: ' . ab_msg( $r ) );

ab_reset_activation();
ab_new_theme( 'shiny' );
$GLOBALS['ab_everywhere']['option_stylesheet'] = static function () { return 'calm'; };
$GLOBALS['ab_everywhere']['option_template']   = static function () { return 'calm'; };
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_activation_held' === ab_code( $r ) && ab_has( ab_msg( $r ), 'WordPress stored the theme shiny as the active theme, but code that runs on every request keeps another one', 'the test requests after the switch ran stylesheet calm with template calm', 'This call switched back to calm' ), 'K4 a theme kept off by a filter on stylesheet: not reported as active: ' . ab_msg( $r ) );
ok( 'calm' === $GLOBALS['ab_opts']['stylesheet'] && 'calm' === $GLOBALS['ab_opts']['template'] && array() === AB_MCP_Undo::$recorded, 'K4 the table holds calm again, as the site shows it, and no undo point' );
ab_reset_activation();
ab_new_theme( 'shiny' );
// A preview for visitors of the home page only: the other test requests run the new theme.
$GLOBALS['ab_everywhere']['option_stylesheet'] = static function ( $v ) { return 'home' === $GLOBALS['ab_target_now'] ? 'calm' : $v; };
$GLOBALS['ab_everywhere']['option_template']   = static function ( $v ) { return 'home' === $GLOBALS['ab_target_now'] ? 'calm' : $v; };
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( ! is_wp_error( $r ) && true === $r['activated'] && 'shiny' === $GLOBALS['ab_opts']['stylesheet'], 'K4 counter-check: one test request that runs the new theme is enough (a preview plugin shows another to some): ' . ab_msg( $r ) );
ab_reset_activation();

/* ============================================ C one change to active code at a time */

echo "C one change to active code at a time (Codex, 06.10.2026)\n";
$lock = AB_MCP_Change_Lock::OPTION;
ab_reset_activation();
$c1 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$t  = time() - 5;
update_option( $lock, $t );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $c1 ) );
ok( 'ab_mcp_change_busy' === ab_code( $r ) && ab_has( ab_msg( $r ), 'another change of the code this site runs through AlphaBridge (an activation, a deploy, or a PHP file or snippet written or undone) has been running for 5 s', 'Nothing was changed' ), 'C1 another request holds the lock: refused, with how long it has run: ' . ab_msg( $r ) );
ok( ! is_plugin_active( $c1 ) && array() === $GLOBALS['ab_requests'] && array() === ab_events_like( 'activate_plugin:' ), 'C1 nothing switched on, no test request sent' );
ok( $t === (int) get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C1 the other request\'s lock stays as it was' );
ok( ab_has( ab_msg( $r ), 'If none is running, a request that ended early left the lock behind; from ' . gmdate( 'H:i', $t + AB_MCP_Change_Lock::TIMEOUT ) . ' UTC the next call takes it over' ), 'C1 the answer says from when a lock left behind is taken over' );
// Counter-check: a lock left behind longer than its timeout is taken over.
update_option( $lock, time() - AB_MCP_Change_Lock::TIMEOUT - 1 );
$c1b = ab_new_plugin( 'fine' );
$r   = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $c1b ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $c1b ), 'C1 a lock older than its timeout (a request that died) is taken over: ' . ab_msg( $r ) );
ok( false === get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C1 and free again after the answer' );

ab_reset_activation();
$c2 = ab_new_plugin( 'fine' );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'other/other.php', 'started' => time() - 3, 'nonce' => 'x' ) );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $c2 ) );
ok( 'ab_mcp_change_busy' === ab_code( $r ) && ab_has( ab_msg( $r ), 'an update of other/other.php through AlphaBridge is running on this site', 'Nothing was changed' ), 'C2 while an update through AlphaBridge runs: refused: ' . ab_msg( $r ) );
ok( ! is_plugin_active( $c2 ) && array() === $GLOBALS['ab_requests'] && false === get_option( $lock ), 'C2 nothing switched on, no test request, the lock not taken' );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'other/other.php', 'started' => time() - AB_MCP_Update_Guard::STALE - 1, 'nonce' => 'x' ) );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $c2 ) );
ok( ! is_wp_error( $r ) && is_plugin_active( $c2 ), 'C2 counter-check: a note older than STALE belongs to a request that died and does not block: ' . ab_msg( $r ) );

ab_reset_activation();
$c3 = ab_new_plugin( 'broken', 'AB_FATAL_LOAD' );
$r  = AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => $c3 ) );
$last_probe = -1;
foreach ( $GLOBALS['ab_events'] as $i => $e ) {
	if ( 0 === strpos( $e, 'probe:' ) ) {
		$last_probe = $i;
	}
}
$released = array_keys( array_filter( $GLOBALS['ab_events'], static function ( $e ) { return 'lock:release' === $e; } ) );
ok( 'ab_mcp_activation_rolled_back' === ab_code( $r ) && ab_in_order_a( array( 'lock:create', 'probe:home', 'activate_plugin:' . $c3, 'deactivate_plugins:' . $c3 ) ), 'C3 taken back: the lock was taken before the test requests' );
ok( 1 === count( ab_events_like( 'lock:create' ) ) && 1 === count( $released ) && $released[0] > $last_probe, 'C3 taken once and given back once, after the control round' );
ok( false === get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C3 free after the answer' );

ab_reset_activation();
ab_new_theme( 'shiny' );
update_option( $lock, time() - 7 );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( 'ab_mcp_change_busy' === ab_code( $r ) && 'calm' === get_stylesheet() && array() === AB_MCP_Undo::$recorded && array() === $GLOBALS['ab_requests'], 'C4 a theme switch while another change runs: refused, still calm, no undo point, no test request' );
delete_option( $lock );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( ! is_wp_error( $r ) && 'shiny' === get_stylesheet() && false === get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C4 counter-check: with the lock free it switches, and frees the lock again: ' . ab_msg( $r ) );

ab_reset_activation();
ab_new_theme( 'shiny' );
ok( true === AB_MCP_Change_Lock::take(), 'C5 a deploy holds the lock' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'shiny' ) );
ok( ! is_wp_error( $r ) && 'shiny' === get_stylesheet(), 'C5 the theme it activates under its own lock is switched (the lock nests): ' . ab_msg( $r ) );
ok( false !== get_option( $lock ) && AB_MCP_Change_Lock::held(), 'C5 after the switch the lock is still the deploy\'s' );
AB_MCP_Change_Lock::release();
ok( false === get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C5 free once the deploy gives it back' );

ab_reset_activation();
$c6 = ab_new_plugin( 'fine' );
AB_Test_Activation_Guard::$die_once = true;
try {
	AB_Test_Activation_Guard::plugin( $c6 );
} catch ( RuntimeException $e ) {
	unset( $e );
}
ok( false !== get_option( $lock ) && AB_MCP_Change_Lock::held(), 'C6 the request ended before the verdict: the lock stays for the check at shutdown' );
AB_Test_Activation_Guard::finish_at_shutdown();
ok( is_plugin_active( $c6 ) && false === get_option( $lock ) && ! AB_MCP_Change_Lock::held(), 'C6 freed once that check is done, though plugin() and activate() had both taken it' );
ab_reset_activation();

// C7 A holder that runs long keeps the lock fresh (Codex, 06.10.2026: a ZIP
// deploy without a time limit lost it after TIMEOUT, and another change could
// take it while the deploy still wrote).
$tp = new ReflectionProperty( 'AB_MCP_Change_Lock', 'touched' );
if ( PHP_VERSION_ID < 80100 ) { $tp->setAccessible( true ); }
ok( true === AB_MCP_Change_Lock::take(), 'C7 a deploy holds the lock' );
update_option( $lock, time() - AB_MCP_Change_Lock::TIMEOUT + 5 );
$tp->setValue( null, time() - AB_MCP_Change_Lock::HEARTBEAT );
AB_MCP_Change_Lock::touch();
ok( time() - (int) get_option( $lock ) <= 1, 'C7 touch() writes the time anew once a heartbeat has passed' );
update_option( $lock, 12345 );
AB_MCP_Change_Lock::touch();
ok( 12345 === (int) get_option( $lock ), 'C7 and not again within the heartbeat' );
// (Pro's deploy client refreshes it at every remote operation, checked there.)
AB_MCP_Change_Lock::release();
update_option( $lock, 12345 );
$tp->setValue( null, 0 );
AB_MCP_Change_Lock::touch();
ok( 12345 === (int) get_option( $lock ), 'C7 a request that does not hold the lock writes nothing to it' );
delete_option( $lock );

// C8 What is active is read again once the lock is taken (Codex, 06.10.2026,
// round 2: active_plugins came from the copy loaded at the start of the
// request, older than an activation that held the lock in between).
ab_reset_activation();
$c8 = ab_new_plugin( 'later' );
$GLOBALS['ab_ocache'] = array();
$seen = (array) get_option( 'active_plugins', array() );
$GLOBALS['ab_opts']['active_plugins'] = array_merge( $seen, array( $c8 ) );
ok( ! in_array( $c8, (array) get_option( 'active_plugins', array() ), true ), 'C8 test setup: this request\'s copy does not know the activation of another' );
ok( true === AB_MCP_Change_Lock::take() && in_array( $c8, (array) get_option( 'active_plugins', array() ), true ), 'C8 once the lock is taken, what is active comes from the database' );
AB_MCP_Change_Lock::release();
ab_reset_activation();

/* ============================================ D the descriptions */

echo "D the tools say it\n";
$reg = new AB_MCP_Tool_Registry();
AB_MCP_Tools_Plugins_Themes_Manage::register( $reg );
AB_MCP_Tools_Install_Update::register( $reg );
foreach ( array( 'wp_activate_plugin', 'wp_activate_theme' ) as $tool ) {
	ok( ab_has( $reg->defs[ $tool ]['description'], 'Activating is checked like an update', 'the activation is taken back in the same call', 'not checked', 'has to let WordPress finish every request', 'WP-Cron' ), "D $tool describes the check" );
}
foreach ( array( 'wp_install_plugin', 'wp_install_plugin_zip', 'wp_install_theme_zip' ) as $tool ) {
	ok( ab_has( $reg->defs[ $tool ]['description'], 'With activate, the activation is checked' ), "D $tool describes the check" );
}

ab_finish( 'ACTIVATION-GUARD' );
