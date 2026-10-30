<?php
/**
 * Plugins and themes that do not take the site down when they are switched
 * on: the one place wp_activate_plugin, wp_network_activate_plugin,
 * wp_activate_theme (and through it activate_theme of wp_deploy_push_zip),
 * the installs with activate and install_provider of wp_save_php_snippet hand
 * an activation to.
 *
 * A PHP file of a plugin or theme that is not active is written without test
 * requests: its code does not run on the site. That is the usual way to a new
 * one ("build me a small plugin"), and a fatal error in it shows only once it
 * is switched on — then every request of the site ends with it, this MCP
 * endpoint's too, and with it the way back (measured on Playground,
 * 03.10.2026: a new plugin with a fatal error in an init callback, and a new
 * theme with one in functions.php; the activation answered "activated", and
 * the home page, wp-admin, the REST API and MCP answered HTTP 500 from then
 * on, so neither wp_deactivate_plugin nor wp_undo got through).
 *
 * The rule is the one of the updates, and so are its pieces (this class
 * extends AB_MCP_Update_Guard for them): the test requests of
 * AB_MCP_Php_Probe before and after, within the same time budget, and
 * verdict(): where a target does worse after the activation — a fatal error
 * it did not have before, no answer, or this MCP endpoint not giving its own
 * answer — the activation is taken back in the same request. A plugin is
 * deactivated silently, without its deactivation hooks, as WordPress pauses a
 * plugin that ends requests with a fatal error; a theme is switched back to
 * the previous one. The files stay as they are, so the assistant can fix the
 * error and activate again. A site that cannot reach itself is activated as
 * before, with "not checked" (as an update, decision 3 of 03.10.2026).
 *
 * No maintenance mode and no wait: no file changes, only an option, and
 * WordPress reads that again on the next request.
 *
 * Not covered: AB_MCP_Php_Probe::NOT_CHECKED, and the database — what an
 * activation routine writes there stays.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-update-guard.php';
require_once __DIR__ . '/class-change-lock.php';

/**
 * Class AB_MCP_Activation_Guard
 */
class AB_MCP_Activation_Guard extends AB_MCP_Update_Guard {

	/**
	 * Appended to the descriptions of the tools that activate. One text, so
	 * they cannot describe the same steps differently.
	 */
	const DESCRIPTION = ' Activating is checked like an update: test requests check the home page, this MCP endpoint, wp-admin/admin-post.php and the dashboard before and after, logged in as this connection\'s account (where a security plugin refuses that login, the dashboard is left out and the answer says so). Where a target then shows a new fatal error, stops answering, or this MCP endpoint loses its own answer, the activation is taken back in the same call; the answer, an error, names the error with file and line, and the files stay as they are, so a corrected version can be activated again. Code has to let WordPress finish every request: code that ends the answer itself (fastcgi_finish_request(), or exit in the shutdown action) is taken back too, since the test cannot tell it from a broken one, and background work belongs in WP-Cron. A fatal error that was there before keeps the activation with a warning; a site that cannot reach itself is activated without a test, with the warning "not checked". Where code on every request filters the active plugins or the theme, so the site does not load what WordPress lists as active, the answer says so and the list stays as it was. One activation, update or deploy into running code runs at a time; a second is refused and changes nothing. Not checked: other wp-admin screens, cron, AJAX, code that runs only on some pages or for other users; what an activation routine writes to the database stays.';

	/** Appended to the descriptions of the installs that can activate. */
	const INSTALL_DESCRIPTION = ' With activate, the activation is checked as with wp_activate_plugin and wp_activate_theme: where the site does worse with it active, it is taken back in the same call, and the answer, an error, names the error with file and line; what was installed stays installed.';

	/**
	 * The activation of this request whose check is not over yet, for the
	 * shutdown function: { job, check }.
	 *
	 * @var array|null
	 */
	protected static $pending = null;

	/** @var bool */
	private static $rescue_registered = false;

	/* --------------------------------------------------------------- plugins */

	/**
	 * Activate a plugin on this site or network-wide, checked.
	 *
	 * @param string $plugin       Plugin file (akismet/akismet.php).
	 * @param bool   $network_wide Network-wide.
	 * @return array|WP_Error Fields for the answer: already_active, or probe
	 *                        and warning; network_wide where WordPress
	 *                        activates it network-wide although this site was
	 *                        asked for.
	 */
	public static function plugin( $plugin, $network_wide = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$asked = (bool) $network_wide;
		$file  = plugin_basename( trim( (string) $plugin ) );
		// What activate_plugin() asks first, asked before the test requests.
		$valid = validate_plugin( $file );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( function_exists( 'validate_plugin_requirements' ) ) {
			$req = validate_plugin_requirements( $file );
			if ( is_wp_error( $req ) ) {
				return $req;
			}
		}
		// From here the state before is read, so it is read under the lock
		// (AB_MCP_Change_Lock): read before another activation's switch
		// and used after it, its way back would undo that one.
		$lock = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			return self::plugin_locked( $file, $asked );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * plugin() under the lock.
	 *
	 * @param string $file  Plugin file.
	 * @param bool   $asked Network-wide was asked for.
	 * @return array|WP_Error
	 */
	private static function plugin_locked( $file, $asked ) {
		// As activate_plugin() decides it: on a network a plugin with
		// "Network: true" is activated network-wide, whatever was asked. The
		// check of its state and the way back follow that (Codex, 03.10.2026:
		// taken back on this site only, it stayed active on the network).
		$network_wide = $asked || ( is_multisite() && is_network_only_plugin( $file ) );
		$extra        = ( $network_wide && ! $asked ) ? array( 'network_wide' => true ) : array();
		$job          = self::plugin_job( $file, $network_wide );
		// Before the switch, only as WordPress sees it: its code is not loaded
		// yet, so the filters on the list are those of code that runs on
		// every request. A host that keeps a plugin on through one (an
		// mu-plugin) has it active without it in the stored list; one that
		// keeps it off through one leaves a stored row that loads nothing
		// (Codex, 05.10.2026). The stored list counts after the switch.
		if ( $network_wide ? is_plugin_active_for_network( $file ) : is_plugin_active( $file ) ) {
			// Its code runs already; nothing new to test. Called all the same:
			// a plugin active network-wide still gets onto this site's list.
			$res = activate_plugin( $file, '', $network_wide );
			return is_wp_error( $res ) ? $res : array( 'already_active' => true ) + $extra;
		}
		$out = static::activate( $job );
		return is_wp_error( $out ) ? $out : $out + $extra;
	}

	/**
	 * The job for a plugin, for activate().
	 *
	 * Whether it is on is read from the list as the database holds it, not
	 * through is_plugin_active(): activate_plugin() has loaded the plugin in
	 * this request, and get_option() runs its filters. A plugin that filters
	 * option_active_plugins to leave itself out, or with a filter that
	 * throws, read as not active, and the check and the way back were skipped
	 * although the next request loads it from the stored list (Codex,
	 * 04.10.2026). That stored list is what the next request reads, before
	 * any plugin is loaded.
	 *
	 * @param string $file         Plugin file.
	 * @param bool   $network_wide Network-wide.
	 * @return array See activate().
	 */
	public static function plugin_job( $file, $network_wide ) {
		$is_on = static function () use ( $file, $network_wide ) {
			return self::stored_active( $file, $network_wide );
		};
		// Both lists as they are before the switch. An activation routine may
		// add the plugin to the other one too (network-wide to this site's,
		// or on this site to the network's); taken back, it goes from there
		// as well, unless it was there before (Codex, 05.10.2026).
		$on_site_before    = in_array( $file, self::stored_list( false ), true );
		$on_network_before = is_multisite() && array_key_exists( $file, self::stored_list( true ) );
		return array(
			'type'          => 'plugin',
			'item'          => $file,
			'network'       => $network_wide,
			// On the list it is switched on in, before the switch: a row a
			// filter of code that runs on every request kept from loading.
			'listed_before' => $network_wide ? $on_network_before : $on_site_before,
			'activate'  => static function () use ( $file, $network_wide ) {
				return activate_plugin( $file, '', $network_wide );
			},
			'is_on'     => $is_on,
			// Silent: the deactivation hooks of a plugin that ends requests
			// with a fatal error do not run, as with WordPress's recovery mode.
			// deactivate_plugins() reads the list through the plugin's filters
			// too; where that leaves it in the stored list, or throws, it is
			// taken out of the stored list directly — which is all a silent
			// deactivation writes.
			'take_back' => static function () use ( $file, $network_wide, $is_on, $on_site_before, $on_network_before ) {
				try {
					deactivate_plugins( $file, true, $network_wide );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// The other list first: on this site, is_on() counts the
				// network's list too.
				$other_before = $network_wide ? $on_site_before : $on_network_before;
				if ( ! $other_before && self::in_stored( $file, ! $network_wide ) ) {
					self::drop_stored( $file, ! $network_wide );
				}
				if ( $is_on() ) {
					self::drop_stored( $file, $network_wide );
				}
				return ! $is_on() && ( $other_before || ! self::in_stored( $file, ! $network_wide ) );
			},
		);
	}

	/**
	 * Whether a plugin is in the stored list: network-wide in the network's,
	 * otherwise in this site's or the network's, as is_plugin_active() asks.
	 *
	 * @param string $file         Plugin file.
	 * @param bool   $network_wide Network-wide.
	 * @return bool
	 */
	private static function stored_active( $file, $network_wide ) {
		$network = is_multisite() && array_key_exists( $file, self::stored_list( true ) );
		if ( $network_wide ) {
			return $network;
		}
		return $network || in_array( $file, self::stored_list( false ), true );
	}

	/**
	 * Whether a plugin is in one stored list.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $network The network's list, else this site's.
	 * @return bool
	 */
	private static function in_stored( $file, $network ) {
		if ( $network ) {
			return is_multisite() && array_key_exists( $file, self::stored_list( true ) );
		}
		return in_array( $file, self::stored_list( false ), true );
	}

	/**
	 * The list of active plugins as the database holds it: this site's
	 * (active_plugins) or the network's (active_sitewide_plugins, plugin =>
	 * time). Without a database to ask, through the option functions.
	 *
	 * @param bool $network The network's.
	 * @return array
	 */
	private static function stored_list( $network ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $network ? (array) get_site_option( 'active_sitewide_plugins', array() ) : (array) get_option( 'active_plugins', array() );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this request's copy, read through the plugin's filters, is the one in doubt.
		if ( $network ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", 'active_sitewide_plugins', get_current_network_id() ) );
		} else {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
		}
		// phpcs:enable
		$list = null === $raw ? array() : maybe_unserialize( $raw );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Take a plugin out of the stored list, past the option functions and
	 * the plugin's filters on them, and drop this request's copy.
	 *
	 * @param string $file         Plugin file.
	 * @param bool   $network_wide Network-wide.
	 */
	private static function drop_stored( $file, $network_wide ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $network_wide ) {
			$list = self::stored_list( true );
			unset( $list[ $file ] );
			$wpdb->update( $wpdb->sitemeta, array( 'meta_value' => maybe_serialize( $list ) ), array( 'meta_key' => 'active_sitewide_plugins', 'site_id' => get_current_network_id() ) );
			wp_cache_delete( get_current_network_id() . ':active_sitewide_plugins', 'site-options' );
		} else {
			$list = array_values( array_diff( self::stored_list( false ), array( $file ) ) );
			$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $list ) ), array( 'option_name' => 'active_plugins' ) );
			wp_cache_delete( 'active_plugins', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------- the activation */

	/**
	 * Switch something on with test requests before and after, and take it
	 * back where the site does worse.
	 *
	 * @param array $job {
	 *     @type string   $type      plugin|theme.
	 *     @type string   $item      Plugin file or stylesheet.
	 *     @type bool     $network   Plugin network-wide.
	 *     @type string   $previous  Theme: the stylesheet before.
	 *     @type callable $activate  Switches it on; true or WP_Error.
	 *     @type callable $is_on     Whether it is on now.
	 *     @type callable $changed   Optional: whether anything changed although
	 *                               it is not on (a theme switch half done);
	 *                               then it is taken back all the same.
	 *     @type callable $take_back Switches it off again; true when it is.
	 *     @type callable $restored  Theme, optional: after take_back, the
	 *                               options that did not come back as they
	 *                               were before the switch (string[]), or
	 *                               null where none were written back.
	 * }
	 * @return array|WP_Error Fields for the answer when it stays on: probe,
	 *                        and warning where there is one.
	 */
	public static function activate( array $job ) {
		// Taken here too for a caller that did not take it; within plugin()
		// and a theme switch it nests.
		$lock = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			return static::activate_locked( $job );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * activate() under the lock.
	 *
	 * @param array $job See activate().
	 * @return array|WP_Error
	 */
	protected static function activate_locked( array $job ) {
		$start = static::request_start();
		// Should the client hang up, PHP may end this request at its next
		// output; the decision has to come first.
		ignore_user_abort( true );

		$plan    = AB_MCP_Php_Probe::enabled() ? 'run' : 'off';
		$before  = null;
		$reached = array();
		if ( 'run' === $plan ) {
			// Every target, to the end, so the round after has something to
			// compare with.
			$before  = static::probe( array(), false );
			$reached = self::reached( $before );
			if ( ! $reached ) {
				$plan = 'unreachable';
			}
		}
		// The round after and the reserve for taking it back and asking again
		// have to fit; the round after ends before that reserve.
		$round   = ( 'run' === $plan ) ? self::round_seconds( $before, $reached ) : 0.0;
		$reserve = $round + self::BUFFER;
		$elapsed = static::now() - $start;
		if ( 'run' === $plan && $elapsed + $round + $reserve > self::BUDGET ) {
			return self::budget_refusal( $job, $elapsed, $round + $reserve );
		}
		$check = array(
			'start'    => $start,
			'plan'     => $plan,
			'before'   => $before,
			'reached'  => $reached,
			'reserve'  => $reserve,
			'deadline' => $start + self::BUDGET - $reserve,
		);

		// From here until the verdict, a request that ends early (a plugin
		// that exits in its activation hook, a fatal error of PHP) is checked
		// at shutdown.
		static::$pending = array(
			'job'   => $job,
			'check' => $check,
		);
		self::hook_rescue();
		$said = self::switch_on( $job );
		if ( ! self::is_on( $job ) ) {
			static::$pending = null;
			if ( self::changed( $job ) ) {
				return self::half_switched( $job, self::take_back( $job ) );
			}
			return is_wp_error( $said ) ? self::not_switched_on( $job, $said ) : new WP_Error(
				'ab_mcp_activation_failed',
				/* translators: %s: plugin or theme */
				sprintf( __( 'Not activated: %s is not active after WordPress was asked to activate it, and WordPress gave no reason. Nothing was changed. Activating it in wp-admin shows what WordPress says there.', 'alphabridge-mcp' ), self::named( $job ) )
			);
		}
		$out             = static::check_after( $job, $check );
		static::$pending = null;
		if ( is_wp_error( $said ) && is_array( $out ) ) {
			$out['warning'] = trim( self::said_words( $said ) . ' ' . ( isset( $out['warning'] ) ? $out['warning'] : '' ) );
		}
		return $out;
	}

	/**
	 * After the switch: the round of test requests, the verdict, and where it
	 * goes against the activation, taking it back and one more round.
	 *
	 * @param array $job   See activate().
	 * @param array $check What activate() measured before.
	 * @return array|WP_Error
	 */
	protected static function check_after( array $job, array $check ) {
		if ( 'run' !== $check['plan'] ) {
			return self::kept_answer( $job, $check, null, null );
		}
		// To the end where there was a fatal error before, so the old error
		// cannot hide a new one at another target.
		$fatal_before = 'fatal' === $check['before']['result'];
		$after        = static::probe_until( $check['reached'], ! $fatal_before, $check['deadline'] );
		$after        = static::ask_timeouts_again( $check['before'], $after, $check['deadline'] );
		$verdict      = self::verdict( $check['before'], $after );
		if ( $verdict['keep'] ) {
			// The test requests loaded its code: a plugin that switches itself
			// off there (a requirement it misses, at init or admin_init) is not
			// active any more, though the site passed (Codex, 05.10.2026). A
			// theme whose code switched the site on to another theme, or half
			// way, is taken back to the one before (Codex, 05.10.2026, round 6).
			if ( ! self::is_on( $job ) ) {
				if ( self::changed( $job ) ) {
					return self::moved_during_test( $job );
				}
				return self::switched_itself_off( $job );
			}
			// On in the table, but not what the site loads: code that runs on
			// every request (an mu-plugin, the host's) filters the list of
			// plugins or the theme. Only a fresh request shows that (Codex,
			// 06.10.2026); the test requests report what they loaded.
			$loaded = self::loaded( $job, $after );
			if ( null !== $loaded && true !== $loaded ) {
				return self::not_loaded( $job, $loaded );
			}
			return self::kept_answer( $job, $check, $after, $verdict );
		}
		$back = self::take_back( $job );
		if ( true !== $back ) {
			return self::not_taken_back( $job, $after, $verdict, $back );
		}
		$control = static::probe_until( $check['reached'], false, $check['start'] + self::BUDGET );
		return self::taken_back( $job, $check, $after, $verdict, $control );
	}

	/**
	 * Switch it on. A Throwable from the code that runs while activating —
	 * activate_plugin() loads the plugin's main file in this request and runs
	 * its activation hook — comes back as an error, with the output buffer
	 * activate_plugin() opened closed again.
	 *
	 * @param array $job See activate().
	 * @return true|WP_Error
	 */
	private static function switch_on( array $job ) {
		$level = ob_get_level();
		try {
			$res = call_user_func( $job['activate'] );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return new WP_Error(
				'ab_mcp_activation_threw',
				sprintf(
					/* translators: 1: class and message of what was thrown, 2: file, 3: line */
					__( '%1$s in %2$s on line %3$d', 'alphabridge-mcp' ),
					get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 500 ),
					$e->getFile(),
					$e->getLine()
				)
			);
		}
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Whether it is on. Where asking throws, the state is unknown, and that
	 * counts as on: the test requests decide, and a site that does worse gets
	 * it taken back. Counted as off, the check and the way back were skipped
	 * (Codex, 04.10.2026).
	 *
	 * @param array $job See activate().
	 * @return bool
	 */
	private static function is_on( array $job ) {
		try {
			return (bool) call_user_func( $job['is_on'] );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Whether a switch that is not on changed anything all the same.
	 * Where asking throws, it counts as changed: taking back what was not
	 * changed costs nothing.
	 *
	 * @param array $job See activate().
	 * @return bool
	 */
	private static function changed( array $job ) {
		if ( ! isset( $job['changed'] ) ) {
			return false;
		}
		try {
			return (bool) call_user_func( $job['changed'] );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Switch it off again.
	 *
	 * @param array $job See activate().
	 * @return true|string True when it is off; otherwise what went wrong.
	 */
	private static function take_back( array $job ) {
		try {
			$ok = call_user_func( $job['take_back'] );
		} catch ( \Throwable $e ) {
			return get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 300 );
		}
		if ( true === $ok ) {
			return true;
		}
		return is_wp_error( $ok ) ? $ok->get_error_message() : __( 'the state before the activation did not come back', 'alphabridge-mcp' );
	}

	/**
	 * The test requests of an activation report what they loaded.
	 *
	 * @return bool
	 */
	protected static function wants_state() {
		return true;
	}

	/**
	 * Does the site load it, as the test requests after the switch saw it?
	 *
	 * @param array      $job   See activate().
	 * @param array|null $after Report after.
	 * @return true|array|null True where a test request loaded it; null where
	 *                         none reported what it loaded (a cached page,
	 *                         one that ended early); otherwise the state of
	 *                         the first that reported, which did not load it.
	 */
	private static function loaded( array $job, $after ) {
		if ( ! is_array( $after ) || empty( $after['targets'] ) ) {
			return null;
		}
		$first = null;
		foreach ( $after['targets'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['state'] ) || ! is_array( $row['state'] ) ) {
				continue;
			}
			$st = $row['state'];
			if ( 'theme' === $job['type'] ) {
				$on = $st['stylesheet'] === (string) $job['item'] && ( ! isset( $job['template'] ) || $st['template'] === (string) $job['template'] );
			} else {
				$on = in_array( (string) $job['item'], $st['plugins'], true ) || in_array( (string) $job['item'], $st['network'], true );
			}
			// One that loads it is enough: a plugin may switch themes for
			// some visitors (a preview, the logged-in dashboard).
			if ( $on ) {
				return true;
			}
			if ( null === $first ) {
				$first = $st;
			}
		}
		return $first;
	}

	/**
	 * In the table, but the site does not load it. A theme the site does not
	 * show is switched back, so that WordPress lists what the site shows. A
	 * plugin is taken off the list again where this call put it there; a row
	 * that was there before stays, as it was.
	 *
	 * @param array $job   See activate().
	 * @param array $state What a test request loaded instead (AB_MCP_Probe_State).
	 * @return WP_Error
	 */
	private static function not_loaded( array $job, array $state ) {
		if ( 'theme' === $job['type'] ) {
			$shown = sprintf(
				/* translators: 1: stylesheet, 2: template */
				__( 'stylesheet %1$s with template %2$s', 'alphabridge-mcp' ),
				'' !== $state['stylesheet'] ? $state['stylesheet'] : '?',
				'' !== $state['template'] ? $state['template'] : '?'
			);
			$back = self::take_back( $job );
			$then = ( true === $back )
				/* translators: %s: previous theme */
				? sprintf( __( 'This call switched back to %s, so WordPress lists the theme the site shows again.', 'alphabridge-mcp' ), (string) $job['previous'] )
				/* translators: 1: previous theme, 2: what went wrong */
				: sprintf( __( 'Switching back to %1$s failed (%2$s): WordPress now lists a theme the site does not show. Switch themes in wp-admin under Appearance › Themes.', 'alphabridge-mcp' ), (string) $job['previous'], (string) $back );
			return new WP_Error(
				'ab_mcp_activation_held',
				sprintf(
					/* translators: 1: the theme, 2: what the site ran, 3: what this call did about it */
					__( 'Not active: WordPress stored %1$s as the active theme, but code that runs on every request keeps another one (a filter on stylesheet or template, in an mu-plugin or in the host\'s own code): the test requests after the switch ran %2$s. %3$s Find that filter in wp-content/mu-plugins, or ask the host why the theme is kept.', 'alphabridge-mcp' ),
					self::named( $job ),
					$shown,
					$then
				),
				array( 'why' => 'held' )
			);
		}
		if ( ! empty( $job['listed_before'] ) ) {
			$then = __( 'It was on the list of active plugins before this call, and stays on it, as it was.', 'alphabridge-mcp' );
		} else {
			$back = self::take_back( $job );
			$then = ( true === $back )
				? __( 'This call took it off the list again, as it was before.', 'alphabridge-mcp' )
				/* translators: %s: what went wrong */
				: sprintf( __( 'Taking it off the list again failed (%s): WordPress lists it as active although the site does not load it. Deactivate it in wp-admin under Plugins.', 'alphabridge-mcp' ), (string) $back );
		}
		return new WP_Error(
			'ab_mcp_activation_hidden',
			sprintf(
				/* translators: 1: the plugin, 2: what this call did about it */
				__( 'Not active: WordPress lists %1$s as active, but code that runs on every request keeps it from loading (a filter on the list of active plugins, in an mu-plugin or in the host\'s own code): the test requests after the activation did not load it. %2$s Its activation routine ran in this call; what it wrote to the database stays. Find that filter in wp-content/mu-plugins, or ask the host why the plugin is kept off.', 'alphabridge-mcp' ),
				self::named( $job ),
				$then
			),
			array( 'why' => 'hidden' )
		);
	}

	/* ------------------------------------------------------------- shutdown */

	/**
	 * Register the check at shutdown, before the switch loads the plugin.
	 *
	 * First as the earliest shutdown action: WordPress runs that action from
	 * a shutdown function it registered long before this one, and a plugin
	 * loaded by the switch whose own shutdown callback calls exit or die ends
	 * every shutdown function after it — this one's check never ran, and a
	 * broken plugin stayed active (Codex, 05.10.2026). Added before the
	 * plugin is loaded, it runs before the plugin's callbacks even at the
	 * same priority. The shutdown function stays for a request that never
	 * reaches the action; finish_at_shutdown() does nothing a second time.
	 */
	private static function hook_rescue() {
		$rescue = array( get_called_class(), 'finish_at_shutdown' );
		if ( false === has_action( 'shutdown', $rescue ) ) {
			add_action( 'shutdown', $rescue, PHP_INT_MIN, 0 );
		}
		if ( ! self::$rescue_registered ) {
			register_shutdown_function( $rescue );
			self::$rescue_registered = true;
		}
	}

	/**
	 * A request that ended between switching on and the verdict — a plugin
	 * that redirects and exits in its activation hook, a fatal error of PHP —
	 * gets its check here: the same round of test requests and the same
	 * verdict, with the time from now on. Taken back only where the site
	 * does worse, as in the request itself; nothing at all where it is not
	 * on.
	 */
	public static function finish_at_shutdown() {
		if ( null === static::$pending ) {
			return;
		}
		$p               = static::$pending;
		static::$pending = null;
		// The lock of the request stays until this check is done: finally,
		// where it would have been given back, did not run.
		try {
			if ( ! self::is_on( $p['job'] ) ) {
				if ( self::changed( $p['job'] ) ) {
					self::take_back( $p['job'] );
				}
				return;
			}
			$check             = $p['check'];
			$check['start']    = static::now();
			$check['deadline'] = $check['start'] + self::BUDGET - $check['reserve'];
			static::check_after( $p['job'], $check );
		} finally {
			AB_MCP_Change_Lock::release_all();
		}
	}

	/**
	 * Give the lock back, unless the check of an activation is still to run
	 * at shutdown (a Throwable left it pending): that check runs under it,
	 * and finish_at_shutdown() frees it. For the callers that take the lock
	 * before they read the state before (a theme switch).
	 */
	public static function release_lock() {
		if ( null === static::$pending ) {
			AB_MCP_Change_Lock::release();
		}
	}

	/* ------------------------------------------------------------- answers */

	/**
	 * Is this an activation that was taken back (or could not be)?
	 *
	 * @param mixed $r Answer of plugin() or activate().
	 * @return bool
	 */
	public static function was_taken_back( $r ) {
		// Not a class constant: the uninstall test reads every 'ab_mcp_…'
		// string of a constant as the name of an option to remove.
		return is_wp_error( $r ) && in_array( $r->get_error_code(), array( 'ab_mcp_activation_rolled_back', 'ab_mcp_activation_rollback_failed' ), true );
	}

	/**
	 * Kept: the report, and the warnings.
	 *
	 * @param array      $job     See activate().
	 * @param array      $check   What activate() measured before.
	 * @param array|null $after   Report after, or null without a test.
	 * @param array|null $verdict verdict(), or null.
	 * @return array
	 */
	private static function kept_answer( array $job, array $check, $after, $verdict ) {
		$what     = self::named( $job );
		$warnings = array();
		if ( 'off' === $check['plan'] ) {
			$warnings[] = sprintf(
				/* translators: %s: plugin or theme */
				__( 'Not checked: the test requests are switched off with the ab_mcp_php_probe_enabled filter; nothing checked that the site still runs with %s active.', 'alphabridge-mcp' ),
				$what
			);
		} elseif ( 'unreachable' === $check['plan'] ) {
			$warnings[] = sprintf(
				/* translators: 1: plugin or theme, 2: result per target */
				__( 'Not checked: no test request reached this site before %1$s was activated (%2$s), so it was activated without a test, as wp-admin does. Make sure the site still runs. An administrator can give the test requests the headers an HTTP login or firewall wants with the ab_mcp_php_probe_request_args filter.', 'alphabridge-mcp' ),
				$what,
				AB_MCP_Own_Files::summary( $check['before']['targets'] )
			);
		}
		if ( is_array( $verdict ) ) {
			if ( $verdict['same'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Kept active with a fatal error that was there before: %s. Where that error ends a request, the new code was not checked.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $verdict['same'] )
				);
			}
			if ( $verdict['stopped'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Kept active, but %s: the code after that point was not checked. A login wall or a maintenance mode does that on purpose; if this one does it unasked, deactivate it.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $verdict['stopped'] )
				);
			}
			if ( $verdict['unknown'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Not compared, as already before the activation: %s.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $verdict['unknown'] )
				);
			}
			if ( $verdict['unchecked'] ) {
				$names = array();
				foreach ( $verdict['unchecked'] as $row ) {
					$names[] = AB_MCP_Php_Probe::label( (string) $row['target'] );
				}
				$warnings[] = sprintf(
					/* translators: %s: targets */
					__( 'Not checked: this call ran out of time before %s could be tested after the activation; make sure the site still runs.', 'alphabridge-mcp' ),
					implode( ', ', $names )
				);
			}
			$login = self::login_warning( $verdict );
			if ( '' !== $login ) {
				$warnings[] = $login;
			}
		}
		$out = array(
			'probe' => array(
				'tested'      => self::tested_after( $after ),
				'before'      => $check['before'],
				'after'       => $after,
				'not_checked' => AB_MCP_Php_Probe::NOT_CHECKED,
			),
		);
		if ( $warnings ) {
			$out['warning'] = implode( ' ', $warnings );
		}
		return $out;
	}

	/**
	 * Did a test request check it after the activation?
	 *
	 * @param array|null $after Report after.
	 * @return bool
	 */
	private static function tested_after( $after ) {
		if ( ! is_array( $after ) ) {
			return false;
		}
		foreach ( (array) $after['targets'] as $row ) {
			if ( empty( $row['out_of_time'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Taken back. The message carries everything: an MCP client shows the
	 * message of an error, not its data.
	 *
	 * @param array $job     See activate().
	 * @param array $check   What activate() measured before.
	 * @param array $after   Report after.
	 * @param array $verdict verdict().
	 * @param array $control Report with it taken back.
	 * @return WP_Error
	 */
	private static function taken_back( array $job, array $check, array $after, array $verdict, array $control ) {
		$theme   = 'theme' === $job['type'];
		$parts   = array();
		$parts[] = sprintf(
			/* translators: 1: plugin or theme, 2: result per target */
			__( 'Not kept active: after %1$s was activated, %2$s.', 'alphabridge-mcp' ),
			self::named( $job ),
			AB_MCP_Own_Files::summary( self::telling( $verdict['blamed'] ) )
		);
		if ( $theme ) {
			/* translators: %s: theme */
			$parts[] = sprintf( __( 'The previous theme %s is active again: this call switched back to it.', 'alphabridge-mcp' ), (string) $job['previous'] );
		} else {
			$parts[] = empty( $job['network'] )
				? __( 'This call deactivated it again, without running its deactivation routine, as WordPress does after a fatal error.', 'alphabridge-mcp' )
				: __( 'This call deactivated it network-wide again, without running its deactivation routine, as WordPress does after a fatal error.', 'alphabridge-mcp' );
		}
		if ( 'passed' === $control['result'] ) {
			$parts[] = __( 'The test requests pass again.', 'alphabridge-mcp' );
		} else {
			$parts[] = sprintf(
				/* translators: %s: result per target */
				__( 'With it taken back, the test requests say: %s.', 'alphabridge-mcp' ),
				AB_MCP_Own_Files::summary( $control['targets'] )
			);
		}
		$files = $theme
			? __( 'Its files are unchanged on the site (over SFTP, or in the theme file editor of wp-admin where it is switched on).', 'alphabridge-mcp' )
			: __( 'Its files are unchanged on the site (over SFTP, or in the plugin file editor of wp-admin where it is switched on).', 'alphabridge-mcp' );
		if ( 'fatal' === $verdict['why'] ) {
			$parts[] = $files . ' ' . __( 'Fix the error and activate it again. PHP names the file in which an error surfaces, which can be another file than the one at fault.', 'alphabridge-mcp' );
		} elseif ( 'mcp_cut' === $verdict['why'] ) {
			$parts[] = __( 'Kept active, it would have cut this connection: it ends requests to AlphaBridge\'s REST route (/wp-json/alphabridge/v1/) before AlphaBridge checks its token.', 'alphabridge-mcp' ) . ' ' . $files . ' ' . __( 'Leave that route out of code that blocks, redirects or ends requests, then activate it again.', 'alphabridge-mcp' );
		} else {
			// Answered without WordPress's report, or not at all: each says
			// what helps.
			$ends = false;
			$hang = false;
			foreach ( $verdict['blamed'] as $row ) {
				if ( ! empty( $row['no_report'] ) ) {
					$ends = true;
				} elseif ( 'unreachable' === $row['result'] ) {
					$hang = true;
				}
			}
			$lead = array();
			$help = array();
			if ( $ends ) {
				$lead[] = __( 'With it active, the site answered without WordPress\'s test report. Most often its code ends the answer itself before WordPress has finished (fastcgi_finish_request() or litespeed_finish_request(), or exit or die in the shutdown action), fails at the very end of the request, or cuts the page off; an HTTP 502 or 504 means PHP or the web server ended the request, for example after a crash. From outside, a harmless case cannot be told from a harmful one (exit at the end of a request also stops the end-of-request work of other plugins), so it was taken back.', 'alphabridge-mcp' );
				$help[] = __( 'Let WordPress finish every request and move background work to WP-Cron (wp_schedule_single_event()), then activate it again.', 'alphabridge-mcp' );
				if ( $theme ) {
					$help[] = __( 'A theme that does this on purpose can be switched to in wp-admin under Appearance › Themes.', 'alphabridge-mcp' );
				} elseif ( ! empty( $job['network'] ) ) {
					$help[] = __( 'A plugin that does this on purpose can be activated network-wide under Network Admin › Plugins.', 'alphabridge-mcp' );
				} else {
					$help[] = __( 'A plugin that does this on purpose can be activated in wp-admin.', 'alphabridge-mcp' );
				}
				// What that costs: where every target answered without the
				// report, no test request reaches the site any more; where
				// some did, those pages are no longer checked.
				$all = true;
				foreach ( isset( $after['targets'] ) ? (array) $after['targets'] : array() as $row ) {
					$all = $all && ! empty( $row['no_report'] );
				}
				if ( ! $all ) {
					$help[] = __( 'The test requests then cannot check the pages where it does so.', 'alphabridge-mcp' );
				} elseif ( is_multisite() ) {
					$help[] = __( 'From then on the test requests cannot check this site: activations and updates go ahead with "not checked", and PHP files of active plugins and themes can only be written with allow_without_probe: true.', 'alphabridge-mcp' );
				} else {
					$help[] = __( 'From then on the test requests cannot check this site: activations and updates go ahead with "not checked", and PHP files of active plugins and themes can only be written, and PHP snippets saved, with allow_without_probe: true.', 'alphabridge-mcp' );
				}
			}
			if ( $hang || ! $ends ) {
				$lead[] = __( 'With it active the site no longer answered: its code may hang or end requests before WordPress finishes them.', 'alphabridge-mcp' );
				$help[] = __( 'If the site is only slow, an administrator can give the test requests more time with the ab_mcp_php_probe_request_args filter (timeout, at most 60 seconds).', 'alphabridge-mcp' );
			}
			$parts[] = implode( ' ', $lead ) . ' ' . $files . ' ' . implode( ' ', $help );
		}
		$parts[] = $theme
			? self::theme_database_words( $job )
			: __( 'What its activation routine wrote to the database stays.', 'alphabridge-mcp' );
		return new WP_Error(
			'ab_mcp_activation_rolled_back',
			implode( ' ', $parts ),
			array(
				'rolled_back' => true,
				'why'         => $verdict['why'],
				'probe'       => array(
					'before'         => $check['before'],
					'after'          => $after,
					'after_rollback' => $control,
					'not_checked'    => AB_MCP_Php_Probe::NOT_CHECKED,
				),
			)
		);
	}

	/**
	 * What a theme switch taken back left in the database. Where the job
	 * wrote menu locations, widgets, rewrite rules and both themes' settings back as
	 * they were before the switch (AB_MCP_Theme_Switch_State), its
	 * 'restored' callback names the options that did not come back.
	 *
	 * @param array $job See activate(); 'restored' returns string[], or null
	 *                   where nothing was written back.
	 * @return string
	 */
	private static function theme_database_words( array $job ) {
		$off = isset( $job['restored'] ) ? call_user_func( $job['restored'] ) : null;
		if ( ! is_array( $off ) ) {
			return __( 'What the theme wrote to the database while it was active stays.', 'alphabridge-mcp' );
		}
		if ( ! $off ) {
			return __( 'Menu locations, widgets, rewrite rules and the settings of both themes are as they were before the switch; anything else written to the database while it was active, by the theme or by plugins reacting to the switch, stays.', 'alphabridge-mcp' );
		}
		return sprintf(
			/* translators: %s: names of options */
			__( 'Not everything came back as it was before the switch (%s differ), so menu locations, widgets or permalinks may have changed: look at them under Appearance and Settings › Permalinks. Anything else written to the database while it was active stays.', 'alphabridge-mcp' ),
			implode( ', ', $off )
		);
	}

	/**
	 * It had to be taken back and could not be.
	 *
	 * @param array  $job     See activate().
	 * @param array  $after   Report after.
	 * @param array  $verdict verdict().
	 * @param string $why     What went wrong.
	 * @return WP_Error
	 */
	private static function not_taken_back( array $job, array $after, array $verdict, $why ) {
		$way = ( 'theme' === $job['type'] )
			? __( 'Switch themes in wp-admin under Appearance › Themes, or rename the theme\'s folder over SFTP.', 'alphabridge-mcp' )
			: __( 'Deactivate it in wp-admin, through WordPress\'s recovery mode (the link it e-mails to the admin address), or by renaming its folder in wp-content/plugins over SFTP.', 'alphabridge-mcp' );
		return new WP_Error(
			'ab_mcp_activation_rollback_failed',
			sprintf(
				/* translators: 1: plugin or theme, 2: result per target, 3: what went wrong, 4: the way */
				__( 'After %1$s was activated, %2$s — and the activation could NOT be taken back (%3$s), so the site may not answer. %4$s', 'alphabridge-mcp' ),
				self::named( $job ),
				AB_MCP_Own_Files::summary( self::telling( $verdict['blamed'] ) ),
				rtrim( (string) $why, '.' ),
				$way
			),
			array(
				'rolled_back' => false,
				'why'         => $verdict['why'],
				'probe'       => array( 'after' => $after ),
			)
		);
	}

	/**
	 * WordPress did not switch it on: its own refusal, or what PHP threw
	 * while the plugin was loaded to be activated.
	 *
	 * @param array    $job  See activate().
	 * @param WP_Error $said What switch_on() gave.
	 * @return WP_Error
	 */
	private static function not_switched_on( array $job, WP_Error $said ) {
		if ( 'ab_mcp_activation_threw' !== $said->get_error_code() ) {
			return $said;
		}
		$files = ( 'theme' === $job['type'] )
			? __( 'over SFTP, or in the theme file editor of wp-admin where it is switched on', 'alphabridge-mcp' )
			: __( 'over SFTP, or in the plugin file editor of wp-admin where it is switched on', 'alphabridge-mcp' );
		return new WP_Error(
			'ab_mcp_activation_threw',
			sprintf(
				/* translators: 1: plugin or theme, 2: error with file and line, 3: where its files are reached */
				__( 'Not activated: while %1$s was loaded to be activated, PHP stopped with %2$s. It is not active, and the site runs as before. Its files are on the site (%3$s): fix the error and activate it again.', 'alphabridge-mcp' ),
				self::named( $job ),
				$said->get_error_message(),
				$files
			)
		);
	}

	/**
	 * The switch went only part of the way: neither what was asked for nor
	 * what was there before (a theme's stylesheet without its template, or
	 * the other way round). Taken back without test requests, as nothing
	 * that was asked for is there to test.
	 *
	 * @param array       $job  See activate().
	 * @param true|string $back What take_back() gave.
	 * @return WP_Error
	 */
	private static function half_switched( array $job, $back ) {
		$now = ( 'theme' === $job['type'] ) ? self::theme_words() : '';
		if ( true === $back ) {
			return new WP_Error(
				'ab_mcp_activation_rolled_back',
				sprintf(
					/* translators: %s: plugin or theme */
					__( 'Not activated: WordPress switched to %s only part of the way (a filter on the site kept part of the switch), so the switch was taken back. The previous state is active again, and the site runs as before.', 'alphabridge-mcp' ),
					self::named( $job )
				),
				array( 'why' => 'half_switched' )
			);
		}
		return new WP_Error(
			'ab_mcp_activation_rollback_failed',
			sprintf(
				/* translators: 1: plugin or theme, 2: what is active now, 3: what went wrong */
				__( 'Not activated: WordPress switched to %1$s only part of the way (a filter on the site kept part of the switch), and the switch could NOT be taken back (%3$s). Active now: %2$s. Switch themes in wp-admin under Appearance › Themes.', 'alphabridge-mcp' ),
				self::named( $job ),
				'' !== $now ? $now : __( 'unknown', 'alphabridge-mcp' ),
				(string) $back
			),
			array( 'why' => 'half_switched' )
		);
	}

	/**
	 * After the test requests neither it nor what was there before is
	 * active: its code switched the site on to another theme (a fallback in
	 * after_switch_theme), or half way. Taken back to the one before.
	 *
	 * @param array $job See activate().
	 * @return WP_Error
	 */
	private static function moved_during_test( array $job ) {
		$was  = ( 'theme' === $job['type'] ) ? self::theme_words() : '';
		$back = self::take_back( $job );
		if ( true === $back ) {
			return new WP_Error(
				'ab_mcp_activation_rolled_back',
				sprintf(
					/* translators: 1: plugin or theme, 2: what its code switched to */
					__( 'Not kept active: after %1$s was activated, its own code switched the site on during the test requests (to %2$s). That was taken back: the previous state is active again, and the site runs as before. Its files are unchanged. Check what its code does once it is switched on (the after_switch_theme hook, for a theme), then activate it again.', 'alphabridge-mcp' ),
					self::named( $job ),
					'' !== $was ? $was : __( 'something else', 'alphabridge-mcp' )
				),
				array( 'why' => 'moved' )
			);
		}
		return new WP_Error(
			'ab_mcp_activation_rollback_failed',
			sprintf(
				/* translators: 1: plugin or theme, 2: what is active now, 3: what went wrong */
				__( 'Not kept active: after %1$s was activated, its own code switched the site on during the test requests, and that could NOT be taken back (%3$s). Active now: %2$s. Switch themes in wp-admin under Appearance › Themes.', 'alphabridge-mcp' ),
				self::named( $job ),
				'theme' === $job['type'] ? self::theme_words() : __( 'unknown', 'alphabridge-mcp' ),
				(string) $back
			),
			array( 'why' => 'moved' )
		);
	}

	/**
	 * The active theme in words, as the table holds it.
	 *
	 * @return string
	 */
	private static function theme_words() {
		list( $stylesheet, $template ) = class_exists( 'AB_MCP_Theme_Switch_State' ) ? AB_MCP_Theme_Switch_State::active() : array( (string) get_stylesheet(), (string) get_template() );
		return sprintf(
			/* translators: 1: stylesheet, 2: template */
			__( 'stylesheet %1$s with template %2$s', 'alphabridge-mcp' ),
			$stylesheet,
			$template
		);
	}

	/**
	 * Active after the switch, but no longer after the test requests: its own
	 * code switched it off while the site loaded it. Nothing to take back.
	 *
	 * @param array $job See activate().
	 * @return WP_Error
	 */
	private static function switched_itself_off( array $job ) {
		return new WP_Error(
			'ab_mcp_activation_switched_off',
			sprintf(
				/* translators: %s: plugin or theme */
				__( 'Not active: %s was activated, but after the test requests it is no longer active. Its own code switched it off while the site loaded it, most often because something it needs is missing (another plugin, a PHP extension, a setting). Nothing was taken back, and the site runs as before. Its reason, if it gave one, is shown in wp-admin.', 'alphabridge-mcp' ),
				self::named( $job )
			)
		);
	}

	/**
	 * Active, although WordPress or PHP said something on the way (output of
	 * the plugin, a Throwable in a hook after the switch).
	 *
	 * @param WP_Error $said What switch_on() gave.
	 * @return string
	 */
	private static function said_words( WP_Error $said ) {
		/* translators: %s: what WordPress or PHP said */
		return sprintf( __( 'It is active, but while activating it WordPress or PHP said: %s.', 'alphabridge-mcp' ), rtrim( $said->get_error_message(), '.' ) );
	}

	/**
	 * Not started: the test before took so long that the round after and the
	 * reserve for taking it back would not fit.
	 *
	 * @param array $job     See activate().
	 * @param float $elapsed Seconds used.
	 * @param float $need    Seconds still needed.
	 * @return WP_Error
	 */
	private static function budget_refusal( array $job, $elapsed, $need ) {
		return new WP_Error(
			'ab_mcp_activation_budget',
			sprintf(
				/* translators: 1: plugin or theme, 2: seconds used, 3: seconds needed, 4: budget */
				__( 'Not activated: %1$s was not switched on. This call had already taken %2$d s (the test requests before the activation included), and the activation with one more round of test requests and the reserve for taking it back needs about %3$d s more, past the %4$d s a call may take (clients and web servers stop waiting at 60 s). Nothing was changed. Call it again; if the site answers its test requests slowly, an administrator can look into that.', 'alphabridge-mcp' ),
				self::named( $job ),
				(int) round( $elapsed ),
				(int) ceil( $need ),
				self::BUDGET
			)
		);
	}

	/**
	 * How the messages name what is activated.
	 *
	 * @param array $job See activate().
	 * @return string
	 */
	private static function named( array $job ) {
		if ( 'theme' === $job['type'] ) {
			/* translators: %s: stylesheet */
			return sprintf( __( 'the theme %s', 'alphabridge-mcp' ), (string) $job['item'] );
		}
		return empty( $job['network'] )
			/* translators: %s: plugin file */
			? sprintf( __( 'the plugin %s', 'alphabridge-mcp' ), (string) $job['item'] )
			/* translators: %s: plugin file */
			: sprintf( __( 'the plugin %s (network-wide)', 'alphabridge-mcp' ), (string) $job['item'] );
	}
}
