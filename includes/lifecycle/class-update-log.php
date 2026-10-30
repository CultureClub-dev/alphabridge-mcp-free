<?php
/**
 * What the update tools did, and which versions they took back.
 *
 * The log is a ring buffer of the last 200 updates made through
 * wp_update_plugin and wp_update_theme (one option, like the free core's
 * audit log): time, account, tool, package, versions, the result per test
 * target and the time taken. It holds no credentials and no content. A call
 * that timed out at the client (a 504 after 60 s) has its result here, and
 * the next call for the same package names it.
 *
 * A version that was taken back because of a fatal error or because it cut
 * the MCP endpoint is held back: WordPress's own automatic updates would
 * otherwise install it again at their next run — for a theme with nothing to
 * stop it, as core rolls back plugins only, and only by the home page
 * (wp-admin/includes/class-wp-automatic-updater.php, update()). The hold
 * answers false in auto_update_plugin / auto_update_theme for exactly that
 * package in exactly that version; a newer version is updated as usual, and
 * an update called by name tries it again. A version taken back because a
 * target no longer answered is not held: that can be the host, and a
 * security release must not wait for a newer one because of it.
 *
 * Loaded on every request (cron included), not only with the tools: the
 * hold has to answer the background updates of WordPress. The main plugin
 * file hooks the two filters.
 *
 * Network options: all sites of a network share the plugin and theme files,
 * and core's background updates run on the main site, so a hold written from
 * any site has to be read there (on a single site they are options without
 * autoload).
 *
 * Where this plugin does not run on that main site (active on another site only, not
 * network-wide), its filter cannot answer there. The update guard then takes
 * the package off WordPress's own list of automatic updates
 * (auto_update_plugins / auto_update_themes, network options, read by
 * WP_Automatic_Updater::should_update()) and the hold notes it (auto_off).
 * The package goes back on the list when an update of it through AlphaBridge
 * is kept (release()) or, at a check of this plugin's own, once a version newer than
 * the one held back is installed (resume_auto_updates()); never for a package
 * that is not there, and never where someone had switched it off before: then
 * nothing was taken off the list. That check is a WP-Cron event of the site
 * where this plugin runs (RESUME, twice a day), planned only while such a hold is
 * open: WordPress's own update checks are not planned on a site that is not
 * the main one (wp-includes/update.php stops before its hooks there).
 *
 * The list does not reach an offer with the mark autoupdate, which
 * wordpress.org sets for some security releases:
 * WP_Automatic_Updater::should_update() reads the list only for an offer
 * without it, so taking the package off the list does not stop such an
 * offer; this filter, running on the main site, does. The answer of the
 * update guard says so.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Update_Log
 */
class AB_MCP_Update_Log {

	/** The ring buffer. */
	const OPTION = 'ab_mcp_pro_update_log';

	/** Versions taken back: "type:item" => { version, file_version, time, reason, auto_off[, switch_on] }. */
	const HOLDS = 'ab_mcp_pro_update_holds';

	/**
	 * The WP-Cron event (of this site, not an option) that puts a package
	 * back on the list of automatic updates once a newer version is
	 * installed; planned while a hold with auto_off is open (schedule_resume()).
	 */
	const RESUME = 'ab_mcp_pro_resume_auto_updates';

	/**
	 * Who holds WordPress's update lock (auto_updater), when it is an update
	 * through AlphaBridge: { type, item, started, nonce }. The lock itself
	 * keeps only a time.
	 */
	const RUNNING = 'ab_mcp_pro_update_running';

	/**
	 * Folders whose files an update replaced: "plugin:folder" or
	 * "theme:stylesheet" => when that update began. A file undo point
	 * recorded before then holds a file of the previous version; the undo
	 * refuses it on every site of the network (AB_MCP_Undo_Restore), as
	 * the files are shared and the undo index is not.
	 */
	const REPLACED = 'ab_mcp_pro_update_replaced';

	/** Entries the log keeps, oldest dropped first. */
	const MAX = 200;

	/**
	 * Add one entry. Writers hold WordPress's update lock (auto_updater), so
	 * two updates never write it at the same time.
	 *
	 * @param array $row Entry.
	 */
	public static function add( array $row ) {
		$log   = self::entries();
		$log[] = $row;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_site_option( self::OPTION, array_values( $log ) );
	}

	/**
	 * All entries, oldest first.
	 *
	 * @return array[]
	 */
	public static function entries() {
		$log = get_site_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$log,
				static function ( $e ) {
					return is_array( $e ) && isset( $e['type'], $e['item'], $e['time'] );
				}
			)
		);
	}

	/**
	 * The newest entry for one package, or null.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return array|null
	 */
	public static function last_for( $type, $item ) {
		foreach ( array_reverse( self::entries() ) as $e ) {
			if ( (string) $e['type'] === (string) $type && (string) $e['item'] === (string) $item ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * Hold a version back from the automatic updates. Both versions are
	 * kept: the automatic updates go by the version of the offer
	 * (new_version), which a vendor's updater writes by hand and which need
	 * not be the one in the package's header.
	 *
	 * auto_off stays from an earlier hold of the same package: AlphaBridge
	 * took it off the list then, so it is not on it now, and it still has to
	 * go back once a newer version is installed.
	 *
	 * @param string $type         plugin|theme.
	 * @param string $item         Plugin file or stylesheet.
	 * @param string $version      The version taken back, as the offer named it.
	 * @param string $reason       fatal | mcp_cut.
	 * @param string $file_version The version in the header of that package.
	 * @param bool   $auto_off     AlphaBridge took it off the list of automatic updates.
	 */
	public static function hold( $type, $item, $version, $reason, $file_version = '', $auto_off = false ) {
		$holds  = self::holds();
		$before = isset( $holds[ $type . ':' . $item ] ) && is_array( $holds[ $type . ':' . $item ] ) ? $holds[ $type . ':' . $item ] : array();
		$holds[ $type . ':' . $item ] = array(
			'version'      => (string) $version,
			'file_version' => (string) $file_version,
			'time'         => time(),
			'reason'       => (string) $reason,
			'auto_off'     => (bool) $auto_off || ! empty( $before['auto_off'] ),
		);
		update_site_option( self::HOLDS, $holds );
		if ( $holds[ $type . ':' . $item ]['auto_off'] ) {
			self::schedule_resume();
		}
	}

	/**
	 * Is this version the one held back, by the offer's version or by the
	 * one in the package's header?
	 *
	 * @param array|null $hold    A hold of held().
	 * @param string     $version Version.
	 * @return bool
	 */
	public static function holds_version( $hold, $version ) {
		if ( ! is_array( $hold ) || '' === (string) $version ) {
			return false;
		}
		foreach ( array( 'version', 'file_version' ) as $k ) {
			if ( isset( $hold[ $k ] ) && '' !== (string) $hold[ $k ] && (string) $hold[ $k ] === (string) $version ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lift the hold of a package (an update of it was kept, or a newer
	 * version is installed), and put it back on the list of automatic updates
	 * where AlphaBridge took it off. Where that write does not land, the hold
	 * stays, switch_on naming the version installed then, and the next check
	 * tries again while that version is installed (resume_auto_updates());
	 * otherwise nothing would ever switch it on. A version put back by hand
	 * in between is not one to switch it on for.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return bool Whether its automatic update is switched on again.
	 */
	public static function release( $type, $item ) {
		$holds = self::holds();
		$key   = $type . ':' . $item;
		if ( ! isset( $holds[ $key ] ) ) {
			return false;
		}
		$off = is_array( $holds[ $key ] ) && ! empty( $holds[ $key ]['auto_off'] );
		$on  = $off && self::auto_update_on( $type, $item );
		if ( $off && ! $on ) {
			$holds[ $key ]['switch_on'] = (string) self::installed_version( $type, $item );
		} else {
			unset( $holds[ $key ] );
		}
		update_site_option( self::HOLDS, $holds );
		if ( $off ) {
			self::schedule_resume();
		}
		return $on;
	}

	/**
	 * Take a package off WordPress's list of automatic updates.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return string switched_off | was_off (not on the list) | failed (still on it).
	 */
	public static function auto_update_off( $type, $item ) {
		$name = 'auto_update_' . $type . 's';
		$list = (array) get_site_option( $name, array() );
		if ( ! in_array( (string) $item, $list, true ) ) {
			return 'was_off';
		}
		update_site_option( $name, array_values( array_diff( $list, array( (string) $item ) ) ) );
		return in_array( (string) $item, (array) get_site_option( $name, array() ), true ) ? 'failed' : 'switched_off';
	}

	/**
	 * Put a package back on WordPress's list of automatic updates.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return bool Whether it is on the list now.
	 */
	private static function auto_update_on( $type, $item ) {
		$name = 'auto_update_' . $type . 's';
		$list = (array) get_site_option( $name, array() );
		if ( in_array( (string) $item, $list, true ) ) {
			return true;
		}
		$list[] = (string) $item;
		update_site_option( $name, array_values( array_unique( $list ) ) );
		return in_array( (string) $item, (array) get_site_option( $name, array() ), true );
	}

	/**
	 * The event RESUME: a package AlphaBridge took off the list of automatic
	 * updates goes back on it once a version newer than the one held back is
	 * installed, through wp-admin or otherwise, or where an earlier switch-on
	 * did not land and the version installed then still is (switch_on); its
	 * hold is lifted. A package that is not there keeps its hold, and nothing
	 * is switched on: a folder that cannot be read for a moment (an update
	 * copying it) must not end the hold. With no such hold left, the event
	 * goes.
	 */
	public static function resume_auto_updates() {
		foreach ( self::holds() as $key => $hold ) {
			if ( ! is_array( $hold ) || empty( $hold['auto_off'] ) || ! preg_match( '/^(plugin|theme):(.+)\z/s', (string) $key, $m ) ) {
				continue;
			}
			$now   = self::installed_version( $m[1], $m[2] );
			$again = isset( $hold['switch_on'] ) && $now === (string) $hold['switch_on'];
			if ( $again || self::newer_than_hold( $now, $hold ) ) {
				self::release( $m[1], $m[2] );
			}
		}
		self::schedule_resume();
	}

	/**
	 * Plan the event RESUME on this site while a hold with auto_off is open,
	 * and take it off when none is. Two sites of a network may both plan it;
	 * a second run finds nothing left to do.
	 */
	public static function schedule_resume() {
		$open = false;
		foreach ( self::holds() as $hold ) {
			$open = $open || ( is_array( $hold ) && ! empty( $hold['auto_off'] ) );
		}
		$next = wp_next_scheduled( self::RESUME );
		if ( $open && false === $next ) {
			wp_schedule_event( time(), 'twicedaily', self::RESUME );
		} elseif ( ! $open && false !== $next ) {
			wp_clear_scheduled_hook( self::RESUME );
		}
	}

	/**
	 * On init of a request in wp-admin or of WP-Cron: plan the event where a
	 * hold needs it and it is missing (cleared by hand, planned on a site
	 * where this plugin no longer runs), or take it off. Not on a visitor's request:
	 * on a network that read of the holds would be one more database query
	 * on every page.
	 */
	public static function keep_resume_scheduled() {
		if ( is_admin() || wp_doing_cron() ) {
			self::schedule_resume();
		}
	}

	/**
	 * Is the installed version newer than every version the hold names? Only
	 * plain versions are compared (1.2, 1.2.3.4): version_compare() puts
	 * 2.0.1-beta below 2.0.1 and answers anything else as well, so a version
	 * of another form keeps the hold.
	 *
	 * @param string|null $installed Version in the header on disk, null without the package.
	 * @param array       $hold      A hold of held().
	 * @return bool
	 */
	public static function newer_than_hold( $installed, array $hold ) {
		$plain = '/^[0-9]+(\.[0-9]+)*\z/';
		if ( ! is_string( $installed ) || ! preg_match( $plain, $installed ) ) {
			return false;
		}
		$compared = false;
		foreach ( array( 'version', 'file_version' ) as $k ) {
			$v = isset( $hold[ $k ] ) ? (string) $hold[ $k ] : '';
			if ( '' === $v ) {
				continue;
			}
			if ( ! preg_match( $plain, $v ) || ! version_compare( $installed, $v, '>' ) ) {
				return false;
			}
			$compared = true;
		}
		return $compared;
	}

	/**
	 * The version in the header of an installed plugin or theme; null where
	 * it is not there.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return string|null
	 */
	private static function installed_version( $type, $item ) {
		if ( false !== strpos( $item, '..' ) ) {
			return null;
		}
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $item );
			return $theme->exists() ? (string) $theme->get( 'Version' ) : null;
		}
		$file = WP_PLUGIN_DIR . '/' . $item;
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$head = get_file_data( $file, array( 'Version' => 'Version' ) );
		return isset( $head['Version'] ) ? (string) $head['Version'] : '';
	}

	/**
	 * The hold of a package, or null.
	 *
	 * @param string $type plugin|theme.
	 * @param string $item Plugin file or stylesheet.
	 * @return array|null { version, file_version, time, reason }
	 */
	public static function held( $type, $item ) {
		$holds = self::holds();
		$h     = isset( $holds[ $type . ':' . $item ] ) ? $holds[ $type . ':' . $item ] : null;
		return ( is_array( $h ) && isset( $h['version'] ) ) ? $h : null;
	}

	/**
	 * @return array
	 */
	private static function holds() {
		$holds = get_site_option( self::HOLDS, array() );
		return is_array( $holds ) ? $holds : array();
	}

	/**
	 * Note that an update replaced the files of a folder. Writers hold
	 * WordPress's update lock, as for add().
	 *
	 * @param string $type  plugin|theme.
	 * @param string $slug  The folder.
	 * @param int    $since When the update began.
	 */
	public static function mark_replaced( $type, $slug, $since ) {
		$map = get_site_option( self::REPLACED, array() );
		$map = is_array( $map ) ? $map : array();
		$map[ $type . ':' . $slug ] = (int) $since;
		update_site_option( self::REPLACED, $map );
	}

	/**
	 * When the last update that replaced the files of a folder began, or 0.
	 *
	 * @param string $type plugin|theme.
	 * @param string $slug The folder.
	 * @return int
	 */
	public static function replaced_at( $type, $slug ) {
		$map = get_site_option( self::REPLACED, array() );
		return ( is_array( $map ) && isset( $map[ $type . ':' . $slug ] ) ) ? (int) $map[ $type . ':' . $slug ] : 0;
	}

	/**
	 * The note on the update lock, or null.
	 *
	 * @return array|null
	 */
	public static function running() {
		$note = get_site_option( self::RUNNING );
		return ( is_array( $note ) && isset( $note['nonce'], $note['started'] ) ) ? $note : null;
	}

	/**
	 * Put our note on the update lock.
	 *
	 * @param array $note { type, item, started, nonce }.
	 */
	public static function set_running( array $note ) {
		update_site_option( self::RUNNING, $note );
	}

	/**
	 * Take the note off.
	 */
	public static function clear_running() {
		delete_site_option( self::RUNNING );
	}

	/**
	 * Filter auto_update_plugin: no automatic update to a version that was
	 * taken back.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function auto_update_plugin( $update, $item ) {
		return self::auto_update( 'plugin', ( is_object( $item ) && isset( $item->plugin ) ) ? (string) $item->plugin : '', $item, $update );
	}

	/**
	 * Filter auto_update_theme: the same for themes.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function auto_update_theme( $update, $item ) {
		return self::auto_update( 'theme', ( is_object( $item ) && isset( $item->theme ) ) ? (string) $item->theme : '', $item, $update );
	}

	/**
	 * @param string    $type   plugin|theme.
	 * @param string    $name   Plugin file or stylesheet of the offer.
	 * @param object    $item   The offer.
	 * @param bool|null $update What the filter got.
	 * @return bool|null
	 */
	private static function auto_update( $type, $name, $item, $update ) {
		if ( '' === $name || ! is_object( $item ) || ! isset( $item->new_version ) ) {
			return $update;
		}
		if ( self::holds_version( self::held( $type, $name ), (string) $item->new_version ) ) {
			return false;
		}
		return $update;
	}
}
