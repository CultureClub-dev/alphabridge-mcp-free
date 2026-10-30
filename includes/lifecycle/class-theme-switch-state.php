<?php
/**
 * What a theme switch that is taken back has to leave as it found it: the
 * menu locations, widgets and rewrite rules of the theme the site comes back
 * to.
 *
 * switch_theme() writes more than the options that name the active theme.
 * It hands the menu locations of the theme it leaves to the next request
 * (theme_switch_menu_locations) and notes the switch (theme_switched); the
 * next request that reaches init — a visitor, cron, or a test request —
 * runs check_theme_switched(), whose after_switch_theme callbacks map those
 * locations onto the active theme (_wp_menus_changed(): a location of the
 * same name takes the old menu, whatever the active theme had there),
 * rewrite its widget areas (_wp_sidebars_changed()) and save its rewrite
 * rules. WordPress 7.0.2 and 7.1.2 alike: wp-includes/theme.php
 * switch_theme(), check_theme_switched(); nav-menu.php _wp_menus_changed(),
 * wp_map_nav_menu_locations(); widgets.php retrieve_widgets().
 *
 * For a switch that is taken back that goes wrong in three ways:
 *  - the test requests after the switch run with the new theme active; where
 *    it gets to init, they map the previous theme's menus and widgets onto
 *    it and save its rewrite rules;
 *  - switch_theme() back to the previous theme is a switch too: it hands the
 *    locations of the theme taken back to the next request, and that request
 *    — the control round right after — maps them onto the previous theme
 *    (Codex, 03.10.2026: primary went from menu 10 to menu 20, and the answer
 *    said the switch was taken back);
 *  - a request that loaded its options while a switch was pending — a test
 *    request that timed out but still runs on the server, a visitor that
 *    came in between — reaches init later and maps from what it loaded, after
 *    everything was put back (measured on WordPress 7.1.2, 04.10.2026);
 *  - a request that got to init while the switch was still pending runs
 *    core's check, and a slow after_switch_theme callback of the new theme
 *    lets it write after everything was put back: the widgets and menus
 *    behind it, and the rewrite rules it saves at wp_loaded (measured on
 *    WordPress 7.1.2, 04.10.2026: a callback that took 25 s left the
 *    rejected theme's rules in place 15 s after the answer).
 *
 * So these options are read from the database right before the switch, as
 * the table holds them, past any filter on them;
 * before the switch back the two that announce a pending switch are put back
 * and switch_theme() is kept from announcing one, and right after it the rest
 * follow, before the control round. drop_stale_switch() runs in every request
 * and keeps a request whose view is out of date from mapping anything, and
 * one that maps from keeping what it writes once its theme is no longer the
 * active one. A switch that is kept is left to WordPress, as a switch in
 * wp-admin is.
 *
 * Not covered: whatever else was written while the new theme was active — by
 * its own code, or by plugins that react to a theme switch.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Theme_Switch_State
 */
class AB_MCP_Theme_Switch_State {

	/** Stands for an option that was not there. */
	const ABSENT = "\0ab-mcp-absent";

	/** The two options that announce a pending switch to the next request. */
	const PENDING = array( 'theme_switched', 'theme_switch_menu_locations' );

	/**
	 * Read the options a switch to $to and back can change, right before the
	 * switch.
	 *
	 * @param string $to Stylesheet the site is switched to.
	 * @return array<string,mixed> Option => value, ABSENT where it is not set.
	 */
	public static function take( $to ) {
		self::forget( array( 'stylesheet' ) );
		// The stored option, not get_stylesheet(): theme mods are stored under
		// the name the option holds (get_theme_mods()).
		$from  = self::raw( 'stylesheet' );
		$names = self::names( self::ABSENT === $from ? '' : (string) $from, (string) $to );
		self::forget( $names );
		$values = array();
		foreach ( $names as $name ) {
			$values[ $name ] = self::raw( $name );
		}
		return $values;
	}

	/**
	 * Before the switch back: put back the two options that announce a
	 * pending switch, and keep switch_theme() from announcing one while it
	 * switches back — so no request, at no moment, finds the theme taken back
	 * handed on to the previous one. release() ends it.
	 *
	 * @param array<string,mixed> $values What take() read.
	 * @return array<string,mixed> The two as they were just before, for
	 *                             unhold() where the switch back fails.
	 */
	public static function hold( array $values ) {
		self::forget( self::PENDING );
		$before = array();
		foreach ( self::PENDING as $name ) {
			$before[ $name ] = self::raw( $name );
		}
		self::write( array_intersect_key( $values, array_flip( self::PENDING ) ) );
		foreach ( self::PENDING as $name ) {
			add_filter( 'pre_update_option_' . $name, array( __CLASS__, 'keep' ), PHP_INT_MAX, 2 );
		}
		return $before;
	}

	/**
	 * The switch back failed and the new theme is still active: its switch
	 * stays announced as before hold(), so the next request still runs its
	 * after_switch_theme (its menus, widgets and own setup).
	 *
	 * @param array<string,mixed> $before What hold() returned.
	 */
	public static function unhold( array $before ) {
		self::write( $before );
	}

	/**
	 * The pre_update_option filter of hold(): the value stays what it is.
	 *
	 * @param mixed $value     What switch_theme() would write.
	 * @param mixed $old_value What is there.
	 * @return mixed
	 */
	public static function keep( $value, $old_value ) {
		return $old_value;
	}

	/** End hold(), before restore() writes. */
	public static function release() {
		foreach ( self::PENDING as $name ) {
			remove_filter( 'pre_update_option_' . $name, array( __CLASS__, 'keep' ), PHP_INT_MAX );
		}
	}

	/**
	 * Write them back after the switch back, before the control round.
	 *
	 * @param array<string,mixed> $values What take() read.
	 * @return string[] Options that are not what take() read after all; empty
	 *                  when everything came back.
	 */
	public static function restore( array $values ) {
		self::write( $values );
		$off = array();
		foreach ( $values as $name => $value ) {
			if ( ! self::same( self::raw( $name ), $value ) ) {
				$off[] = $name;
			}
		}
		return $off;
	}

	/**
	 * A request that loaded its options while a switch was pending, and gets
	 * to init only now: where that switch is no longer pending, or another
	 * theme is active by now, core's check_theme_switched() (init 99) would
	 * map menus and widgets from what this request loaded. Runs at init 98 in
	 * every request; asks the database only where this request's copy says a
	 * switch is pending, and leaves this request's copy of the options alone
	 * (the code it runs is that of the theme it loaded, and so are its
	 * settings). Where core's check runs, keep_if_left() guards what it
	 * writes.
	 */
	public static function drop_stale_switch() {
		if ( ! get_option( 'theme_switched' ) ) {
			return;
		}
		$pending = self::stored_now( 'theme_switched' );
		$active  = self::stored_now( 'stylesheet' );
		if ( null === $active ) {
			return;
		}
		if ( '' === (string) $pending || (string) $active !== (string) get_option( 'stylesheet' ) ) {
			remove_action( 'init', 'check_theme_switched', 99 );
			return;
		}
		// Core's check runs in this request. Should the switch be taken back
		// while it runs, what it writes after that is no longer this theme's
		// to write: keep_if_left() looks at the moment of each write.
		foreach ( self::names( '', (string) get_option( 'stylesheet' ) ) as $name ) {
			add_filter( 'pre_update_option_' . $name, array( __CLASS__, 'keep_if_left' ), PHP_INT_MAX, 2 );
		}
	}

	/**
	 * The pre_update_option filter of a request that runs core's check: the
	 * value stays what it is once another theme than this request's is the
	 * active one in the database — the switch it maps was taken back.
	 *
	 * @param mixed $value     What the request would write.
	 * @param mixed $old_value What is there.
	 * @return mixed
	 */
	public static function keep_if_left( $value, $old_value ) {
		$active = self::stored_now( 'stylesheet' );
		if ( null !== $active && (string) $active !== (string) get_option( 'stylesheet' ) ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * An option as the table holds it now, past this request's copy; null
	 * where it is not there or there is no database to ask.
	 *
	 * @param string $name Option.
	 * @return string|null
	 */
	private static function stored_now( $name ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$sql = "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s";
		return $wpdb->get_var( $wpdb->prepare( $sql, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- this request's copy is the one in doubt.
	}

	/**
	 * An option as the table holds it, ABSENT where it is not there. Read
	 * past get_option(): where the site filters pre_option_* or option_* of
	 * one of them, get_option() hands out the filtered value, and restore()
	 * wrote that into the table — taking a theme back changed menus, widgets,
	 * rewrite rules or theme settings for good (Codex, 04.10.2026). Without a
	 * database to ask, get_option().
	 *
	 * @param string $name Option.
	 * @return mixed
	 */
	private static function raw( $name ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return get_option( $name, self::ABSENT );
		}
		$stored = self::stored_now( $name );
		return null === $stored ? self::ABSENT : maybe_unserialize( $stored );
	}

	/**
	 * The active theme as the table holds it: stylesheet and template, past
	 * this request's copy of the options. A test request whose theme code
	 * switched the theme wrote the table; this request's copy still names
	 * the theme switched to (Codex, 05.10.2026). Where an option is not
	 * there, what WordPress hands out.
	 *
	 * @return string[] array( stylesheet, template ).
	 */
	public static function active() {
		$stylesheet = self::raw( 'stylesheet' );
		$template   = self::raw( 'template' );
		return array(
			self::ABSENT === $stylesheet ? (string) get_stylesheet() : (string) $stylesheet,
			self::ABSENT === $template ? (string) get_template() : (string) $template,
		);
	}

	/**
	 * An option as the table holds it, false where it is not there, as
	 * get_option() says it: for the options switch_theme() writes, read
	 * before the switch (AB_MCP_Tools_Plugins_Themes_Manage::theme_options()).
	 *
	 * @param string $name Option.
	 * @return mixed
	 */
	public static function stored_option( $name ) {
		self::forget( array( $name ) );
		$value = self::raw( $name );
		return self::ABSENT === $value ? false : $value;
	}

	/**
	 * Write an option back as stored_option() read it, false to delete it.
	 *
	 * @param string $name  Option.
	 * @param mixed  $value Value, or false.
	 */
	public static function put( $name, $value ) {
		self::forget( array( $name ) );
		self::put_raw( $name, false === $value ? self::ABSENT : $value );
		self::forget( array( $name ) );
	}

	/**
	 * Write a value as the table held it. update_option() compares with
	 * get_option(), filters included, and writes nothing where that already
	 * hands out the value — while the table holds another (Codex,
	 * 05.10.2026). There the table is written directly. A filter on
	 * pre_update_option that keeps the value is left alone: the table then
	 * differs, and restore() names the option.
	 *
	 * @param string $name  Option.
	 * @param mixed  $value Value, ABSENT to delete.
	 */
	private static function put_raw( $name, $value ) {
		if ( self::ABSENT === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value );
		if ( self::same( self::raw( $name ), $value ) || ! self::same( get_option( $name, self::ABSENT ), $value ) ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || null === self::stored_now( $name ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- update_option() compared with a filtered value and wrote nothing.
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => $name ) );
		self::forget( array( $name ) );
	}

	/**
	 * The autoload flags of options as the table holds them. switch_theme()
	 * sets them for the settings of the theme it switches to (on) and of the
	 * one it leaves (off) with wp_set_option_autoload_values(), straight in
	 * the table and past every option filter (WordPress 7.1.2,
	 * wp-includes/theme.php switch_theme()). Where that switch does not stay,
	 * put_autoload() writes them back: the active theme's settings would
	 * otherwise cost a query of their own in every request, and those of a
	 * theme that is not active would be loaded in every request.
	 *
	 * @param string[] $names Options.
	 * @return array<string,string> Option => flag, only options that are there.
	 */
	public static function autoload_of( array $names ) {
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $out;
		}
		$sql = "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s";
		foreach ( array_unique( array_map( 'strval', $names ) ) as $name ) {
			$flag = $wpdb->get_var( $wpdb->prepare( $sql, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- the flag is not in the option functions.
			if ( null !== $flag ) {
				$out[ $name ] = (string) $flag;
			}
		}
		return $out;
	}

	/**
	 * Write autoload flags back as autoload_of() read them, where the option
	 * is still there and its flag differs; only options of a theme switch.
	 *
	 * @param array<string,string> $flags Option => flag.
	 */
	public static function put_autoload( array $flags ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$now     = self::autoload_of( array_keys( $flags ) );
		$written = array();
		foreach ( $flags as $name => $flag ) {
			if ( self::switch_option_refused( $name ) || ! isset( $now[ $name ] ) || $now[ $name ] === (string) $flag ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as wp_set_option_autoload_values() writes it.
			$wpdb->update( $wpdb->options, array( 'autoload' => (string) $flag ), array( 'option_name' => (string) $name ) );
			$written[] = (string) $name;
		}
		if ( array() !== $written ) {
			self::forget( $written );
		}
	}

	/** Register drop_stale_switch(); once per request, from the plugin's main file. */
	public static function hook() {
		add_action( 'init', array( __CLASS__, 'drop_stale_switch' ), 98 );
	}

	/**
	 * Write values back, only options of a theme switch.
	 *
	 * @param array<string,mixed> $values Option => value, ABSENT to delete.
	 */
	private static function write( array $values ) {
		$names = array_keys( $values );
		// This request read them before the switch, and the test requests
		// changed them since; update_option() would compare with that copy.
		self::forget( $names );
		// The theme mods are written back as they were, so core's site logo
		// hooks must not read a custom_logo going away as one being removed.
		$ignored                              = isset( $GLOBALS['_ignore_site_logo_changes'] ) ? $GLOBALS['_ignore_site_logo_changes'] : null;
		$GLOBALS['_ignore_site_logo_changes'] = true;
		try {
			foreach ( $values as $name => $value ) {
				if ( self::switch_option_refused( $name ) ) {
					continue;
				}
				self::put_raw( $name, $value );
			}
		} finally {
			$GLOBALS['_ignore_site_logo_changes'] = $ignored;
		}
		self::forget( $names );
	}

	/**
	 * The same stored value? As the options table holds it: a theme mod can
	 * hold an object (header_image_data), and two reads give two objects.
	 *
	 * @param mixed $a Value or ABSENT.
	 * @param mixed $b Value or ABSENT.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( self::ABSENT === $a || self::ABSENT === $b ) {
			return $a === $b;
		}
		return self::stored( $a ) === self::stored( $b );
	}

	/**
	 * A value as the options table stores it.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function stored( $v ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- compared only, as maybe_serialize() stores it.
		return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : (string) $v;
	}

	/**
	 * Only the options of a theme switch are written here, whatever the
	 * array handed in holds. Not option_write_refused() of the option tools:
	 * these names are not a caller's, and its needles would refuse the theme
	 * mods of a theme whose name holds "token" or "password".
	 *
	 * @param string $name Option.
	 * @return bool Whether it is none of them.
	 */
	private static function switch_option_refused( $name ) {
		return ! in_array( (string) $name, self::names( '', '' ), true ) && 0 !== strpos( (string) $name, 'theme_mods_' );
	}

	/**
	 * The options, the two of a pending switch first.
	 *
	 * @param string $from Stylesheet before the switch.
	 * @param string $to   Stylesheet switched to.
	 * @return string[]
	 */
	private static function names( $from, $to ) {
		$names = array_merge(
			self::PENDING,
			array(
				'theme_switched_via_customizer',
				'sidebars_widgets',
				'rewrite_rules',
			)
		);
		foreach ( array( $from, $to ) as $stylesheet ) {
			if ( '' !== $stylesheet ) {
				$names[] = 'theme_mods_' . $stylesheet;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Drop this request's copies, so the next read asks the database.
	 *
	 * @param string[] $names Options.
	 */
	private static function forget( array $names ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
