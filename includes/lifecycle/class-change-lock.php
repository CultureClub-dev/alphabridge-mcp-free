<?php
/**
 * One change of the code this site runs at a time: activations, deploys into
 * active code, updates, and writes and undos of a PHP file the site runs
 * (AB_MCP_Own_Files) or of a PHP snippet (AB_MCP_Tools_Php_Snippets,
 * AB_MCP_Undo_Restore) through AlphaBridge, one after the other.
 *
 * Each of them notes the state before, switches or writes, sends test
 * requests and puts the noted state back where the site does worse. Two of
 * them at the same time get in each other's way: the second notes the first
 * one's change as "before", the test requests of one see the error of the
 * other, and the way back of one undoes the other's change, which its answer
 * had already reported as done (Codex, 06.10.2026: two theme activations).
 * Updates have run one at a time since 4.7.1 under core's lock for its
 * background updates (AB_MCP_Update_Guard); an update takes this lock as
 * well once it holds that one, and an activation or a deploy does not start
 * while the note of an update through AlphaBridge is fresh.
 *
 * The lock is core's (WP_Upgrader::create_lock(), an option written with
 * INSERT IGNORE, so two requests cannot both take it), on the main site of a
 * network as the update lock, because the code is shared by every site of
 * the network. A request that dies while holding it leaves it behind; after
 * TIMEOUT seconds the next one takes it over. A holder that runs long keeps
 * it fresh: a ZIP deploy over SFTP has no time limit, and every remote
 * operation of the deploy client writes the lock's time anew, at most every
 * HEARTBEAT seconds (touch()), so only a lock left behind grows old (Codex,
 * 06.10.2026: a deploy of more than ten minutes lost it). Taken again within
 * the same request (a deploy that activates the theme it deployed), it nests.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Change_Lock
 */
class AB_MCP_Change_Lock {

	/**
	 * The option core keeps the lock in: its name and '.lock'. Written out
	 * whole, as the uninstall routine removes it under this name.
	 */
	const OPTION = 'ab_mcp_pro_code_change.lock';

	/**
	 * Seconds after which a lock left behind is taken over: the STALE of the
	 * update guard. An activation with its test requests stays within the
	 * guards' budget of 45 s; a ZIP deploy may take longer and keeps the lock
	 * fresh while it runs (touch()).
	 */
	const TIMEOUT = 600;

	/**
	 * Seconds between two refreshes of a held lock (touch()): well within
	 * TIMEOUT, rare enough not to write the option at every file.
	 */
	const HEARTBEAT = 60;

	/** @var int How often this request holds the lock. */
	private static $depth = 0;

	/** @var int When this request last wrote the lock's time. */
	private static $touched = 0;

	/** @var bool */
	private static $shutdown_registered = false;

	/**
	 * Take the lock for an activation or a deploy, or say why not.
	 *
	 * @return true|WP_Error
	 */
	public static function take() {
		if ( self::$depth > 0 ) {
			++self::$depth;
			return true;
		}
		$update = self::update_running();
		if ( null !== $update ) {
			return self::update_busy( $update );
		}
		$taken = self::create();
		if ( true !== $taken ) {
			// An update that took it in between has its note by now.
			$update = self::update_running();
			return null !== $update ? self::update_busy( $update ) : $taken;
		}
		return true;
	}

	/**
	 * Take the lock for an update, which holds core's update lock and has
	 * written its note already: an activation or a deploy that runs keeps it
	 * from starting, as it keeps an activation from starting.
	 *
	 * @return true|WP_Error
	 */
	public static function take_for_update() {
		if ( self::$depth > 0 ) {
			++self::$depth;
			return true;
		}
		return self::create();
	}

	/**
	 * Give the lock back (the outermost holder of this request frees it).
	 */
	public static function release() {
		if ( self::$depth > 1 ) {
			--self::$depth;
			return;
		}
		if ( 1 !== self::$depth ) {
			return;
		}
		self::$depth = 0;
		if ( self::upgrader() ) {
			self::on_main_site( array( 'WP_Upgrader', 'release_lock' ), self::name() );
		}
	}

	/**
	 * Give the lock back however often this request took it: for a request
	 * that ends before the holders' own release ran (an exit or a fatal error
	 * of PHP skips finally), once its check at shutdown is done.
	 */
	public static function release_all() {
		if ( self::$depth > 1 ) {
			self::$depth = 1;
		}
		self::release();
	}

	/**
	 * Does this request hold the lock?
	 *
	 * @return bool
	 */
	public static function held() {
		return self::$depth > 0;
	}

	/**
	 * Keep the lock fresh while this request holds it: its time written anew
	 * where the last write is HEARTBEAT seconds old. A no-op otherwise, so the
	 * long loops of a holder may call it as often as they like.
	 */
	public static function touch() {
		if ( self::$depth < 1 || ( time() - self::$touched ) < self::HEARTBEAT || ! self::upgrader() ) {
			return;
		}
		self::$touched = time();
		self::on_main_site( 'update_option', self::OPTION, self::$touched );
	}

	/**
	 * Core's lock, on the main site.
	 *
	 * @return true|WP_Error
	 */
	private static function create() {
		if ( self::upgrader() && ! self::on_main_site( array( 'WP_Upgrader', 'create_lock' ), self::name(), self::TIMEOUT ) ) {
			$since = (int) self::on_main_site( 'get_option', self::OPTION, 0 );
			// A request that ended inside its own way back (an exit there ends
			// every shutdown function after it) leaves the lock until TIMEOUT:
			// the answer says when it is taken over.
			return new WP_Error(
				'ab_mcp_change_busy',
				sprintf(
					/* translators: 1: seconds, 2: time in UTC */
					__( 'Not started: another change of the code this site runs through AlphaBridge (an activation, a deploy, or a PHP file or snippet written or undone) has been running for %1$d s. Changes to the code the site runs go one after the other, so that the way back of one cannot undo the other. Nothing was changed; call again when the other one has answered. If none is running, a request that ended early left the lock behind; from %2$s UTC the next call takes it over.', 'alphabridge-mcp' ),
					$since > 0 ? max( 0, time() - $since ) : 0,
					gmdate( 'H:i', ( $since > 0 ? $since : time() ) + self::TIMEOUT )
				)
			);
		}
		// Without core's upgrader there is no lock to take; counted all the
		// same, so that release() pairs with this.
		self::$depth   = 1;
		self::$touched = time();
		self::forget_active();
		// A request that ends while holding it, past every release of its
		// holders, frees it at the end rather than after TIMEOUT. Registered
		// late, it runs after the shutdown action, where the activation check
		// of a request that ended early runs under the lock.
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'release_all' ) );
			self::$shutdown_registered = true;
		}
		return true;
	}

	/**
	 * Forget what this request read at its start about what is active: an
	 * activation that held the lock in between may have changed it, and a
	 * holder decides by it what is code the site runs (Codex, 06.10.2026:
	 * active_plugins came from the copy loaded at the start of the request).
	 * The next read comes from the database.
	 */
	private static function forget_active() {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( array( 'active_plugins', 'stylesheet', 'template' ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_network_id' ) ) {
			wp_cache_delete( get_current_network_id() . ':active_sitewide_plugins', 'site-options' );
		}
	}

	/**
	 * The answer while an update runs.
	 *
	 * @param string $item Plugin or theme being updated.
	 * @return WP_Error
	 */
	private static function update_busy( $item ) {
		return new WP_Error(
			'ab_mcp_change_busy',
			sprintf(
				/* translators: %s: plugin or theme being updated */
				__( 'Not started: an update of %s through AlphaBridge is running on this site. Changes to the code the site runs go one after the other, so that the way back of one cannot undo the other. Nothing was changed; call again when that update has answered.', 'alphabridge-mcp' ),
				$item
			)
		);
	}

	/**
	 * The plugin or theme an update through AlphaBridge is replacing right
	 * now, or null. A note whose last sign of life (its start, the last beat
	 * of a long update, or the end of its lease while WordPress's upgrader
	 * runs, less STALE) is older than the update guard's STALE belongs to a
	 * request that died and does not count.
	 *
	 * @return string|null
	 */
	private static function update_running() {
		if ( ! class_exists( 'AB_MCP_Update_Log' ) ) {
			return null;
		}
		$note = AB_MCP_Update_Log::running();
		if ( ! is_array( $note ) || empty( $note['item'] ) ) {
			return null;
		}
		$stale = class_exists( 'AB_MCP_Update_Guard' ) ? AB_MCP_Update_Guard::STALE : 600;
		$sign  = max( (int) $note['started'], isset( $note['beat'] ) ? (int) $note['beat'] : 0, isset( $note['until'] ) ? (int) $note['until'] - $stale : 0 );
		if ( ( time() - $sign ) >= $stale ) {
			return null;
		}
		return (string) $note['item'];
	}

	/**
	 * The lock's name for core's functions, which add '.lock'.
	 *
	 * @return string
	 */
	private static function name() {
		return substr( self::OPTION, 0, -strlen( '.lock' ) );
	}

	/**
	 * Load core's upgrader class for its lock.
	 *
	 * @return bool Whether it is there.
	 */
	private static function upgrader() {
		if ( ! class_exists( 'WP_Upgrader' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		return class_exists( 'WP_Upgrader' ) && method_exists( 'WP_Upgrader', 'create_lock' );
	}

	/**
	 * Call on the main site of a network, like the update lock.
	 *
	 * @param callable $fn      What to call.
	 * @param mixed    ...$args Its arguments.
	 * @return mixed
	 */
	private static function on_main_site( $fn, ...$args ) {
		$switch = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_main_site' ) && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			return call_user_func_array( $fn, $args );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}
}
