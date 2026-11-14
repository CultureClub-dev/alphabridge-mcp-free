<?php
/**
 * The swap of a unit of code on the production site (Z8b branch 5; plan E5,
 * E6, E9, 3.1 Z3 to Z23): the one mechanics that puts a staged plugin or
 * theme in the place of the one this site runs, and back.
 *
 * WHAT RUNS (active_plugins(), theme(), runs(); plan E8, 3.1 Z24): whether
 * code of a unit runs on this site is read fresh from the rows of the
 * options (active_plugins; stylesheet and template), past every cache and
 * every filter, in the form of their rows of AB_MCP_Store_Inputs::TABLE
 * (site.active_plugins, site.theme). A row that cannot be read, or holds
 * another form, is not known: never «does not run» (a swap of running code
 * takes its release, AB_MCP_Sync_Code_Ops::run()), and never «still active»
 * (the comparison at the end of a swap).
 *
 * BEFORE THE FIRST MUTATION (prepare(); plan E5 1 to 5, 3.1 Z3, Z4, Z10 to
 * Z12, Z14; Z24 again under the locks): every check of a swap, in the order
 * of E5, in the one call of the swap and under its locks: the lock for
 * changes of the code (AB_MCP_Change_Lock) and WordPress's lock for updates
 * (AB_MCP_Update_Guard::take_swap_lock()); the release of its code
 * (AB_MCP_Sync_Code_Ops::released(), the one rule of Z24); the test
 * requests switched on and the time left; OPcache for a
 * PHP file of running code (invalidate()); the test requests before (home
 * page, MCP endpoint, admin-post.php reached); the time for the swap, its
 * test after it and a swap back with its test; then the live unit hashed
 * whole against its base (or, for a unit made new, its place empty), the
 * stage against the signed manifest, the version (never down), AlphaBridge
 * itself (strictly, AB_MCP_Own_Files::own_state()), one device for the
 * stage and the place of the unit, and the row of the lock for changes.
 * prepare() swaps no file and writes over no file of the live unit; it
 * takes the locks, may take a PHP file out of OPcache and sends the test
 * requests. A refusal gives back every lock it took. What it answers holds
 * within this call and under these locks only; it says nothing of what the
 * site is afterwards. At this commit nothing of the run calls it (the swap
 * itself is the next part of branch 5).
 *
 * OPCACHE (invalidate(), Z13): the one wrapper of
 * AB_MCP_Own_Files::invalidate_opcache() in the swap; only invalidated or
 * not_active let it go on, in the form of its row swap.opcache.
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 5: rename(), the journal and
 * the mark of the swap, the option of the reservation, the note of the
 * replacement, OPcache, the probes before and after, the shutdown; the rows
 * of the options read; the locks, AlphaBridge's own files and the file
 * system for prepare()) go through AB_MCP_Sync_Code_Swap_Calls, held by the
 * seam of this class; only the tests put another object there. No filter
 * and no option reaches it.
 *
 * Messages are in gettext, in the language of the site; their translations
 * come with text/z8b. Codes stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-shape.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-change-lock.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-own-files.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-php-probe.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-update-guard.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-env.php';
require_once __DIR__ . '/class-sync-file-manifest.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-code-stage.php';
require_once __DIR__ . '/class-sync-code-ops.php';

/**
 * The calls of the swap to the outside: the real ones.
 */
final class AB_MCP_Sync_Code_Swap_Calls {

	/**
	 * A stored option row as it is now, past every cache and filter.
	 *
	 * @param string $name Option.
	 * @return array|false|null The row (its value as stored); false where there is none; null where it cannot be read.
	 */
	public function option( $name ) {
		return AB_MCP_Sync_Rows::option( (string) $name );
	}

	/**
	 * The lock for changes of the code this site runs (nested where this
	 * request holds it already, as under the lock of a call of the run).
	 *
	 * @return true|WP_Error
	 */
	public function take_lock() {
		return AB_MCP_Change_Lock::take();
	}

	/**
	 * Give the lock for changes back once.
	 *
	 * @return bool Whether it was given back (AB_MCP_Change_Lock::release()).
	 */
	public function release_lock() {
		return AB_MCP_Change_Lock::release();
	}

	/**
	 * The row of the lock for changes as it is now, on the main site of a
	 * network where the lock lies (AB_MCP_Change_Lock), past every cache.
	 *
	 * @return array|false|null The row; false where there is none; null where it cannot be read.
	 */
	public function lock_row() {
		$switch = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_main_site' ) && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			return AB_MCP_Sync_Rows::option( AB_MCP_Change_Lock::OPTION );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * WordPress's lock for updates, with the note of the swap
	 * (AB_MCP_Update_Guard::take_swap_lock()).
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  The unit.
	 * @return string|WP_Error The nonce of the note.
	 */
	public function core_lock( $kind, $ref ) {
		return AB_MCP_Update_Guard::take_swap_lock( $kind, $ref, $this );
	}

	/**
	 * Whether this swap holds WordPress's lock for updates now, its rows
	 * read as stored (AB_MCP_Update_Guard::swap_lock_held()).
	 *
	 * @param string $nonce The nonce of the note.
	 * @return bool
	 */
	public function core_held( $nonce ) {
		return AB_MCP_Update_Guard::swap_lock_held( $nonce, $this );
	}

	/**
	 * Give WordPress's lock for updates back (where the note is ours).
	 *
	 * @param string $nonce The nonce of the note.
	 * @return bool Whether it was given back.
	 */
	public function free_core_lock( $nonce ) {
		return AB_MCP_Update_Guard::free_swap_lock( $nonce, $this );
	}

	/**
	 * A row of an option of the main site as stored, past every cache and
	 * filter, its value unpacked as WordPress reads it (without classes):
	 * for the rows of WordPress's lock for updates and of the note beside it
	 * (AB_MCP_Update_Guard). Not read (null) in a network: the note lies in
	 * the options of the network there, and a swap does not run in one.
	 *
	 * @param string $name Option.
	 * @return array{value:mixed}|false|null False where there is none; null where it is not read.
	 */
	public function stored( $name ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return null;
		}
		$row = AB_MCP_Sync_Rows::option( (string) $name );
		if ( false === $row ) {
			return false;
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) || ! is_string( $row['value'] ) ) {
			return null;
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		return true === $read['ok'] ? array( 'value' => $read['value'] ) : null;
	}

	/**
	 * Whether the test requests may run on this site.
	 *
	 * @return mixed
	 */
	public function probe_enabled() {
		return AB_MCP_Php_Probe::enabled();
	}

	/**
	 * The test requests to every target, each to its end (no stop at a
	 * fatal error: the test after a swap compares with each of them).
	 *
	 * @param array $opts Options of AB_MCP_Php_Probe::run() (until).
	 * @return mixed
	 */
	public function probe( array $opts ) {
		return AB_MCP_Php_Probe::run( array(), false, $opts );
	}

	/**
	 * A file taken out of OPcache (the one call of the swap to it).
	 *
	 * @param string $path File.
	 * @return mixed invalidated, not_active or not_possible.
	 */
	public function opcache( $path ) {
		return AB_MCP_Own_Files::invalidate_opcache( $path );
	}

	/**
	 * Whether a path is AlphaBridge itself, strictly (own, not own, unknown).
	 *
	 * @param string $path Path.
	 * @return bool|null
	 */
	public function own( $path ) {
		return AB_MCP_Own_Files::own_state( $path );
	}

	/**
	 * A path as it is now, past a link.
	 *
	 * @param string $path Path.
	 * @return array|false
	 */
	public function stat( $path ) {
		clearstatcache( true, $path );
		return @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a path not there is answered.
	}

	/**
	 * A path as it is now, a link as a link.
	 *
	 * @param string $path Path.
	 * @return array|false
	 */
	public function lstat( $path ) {
		clearstatcache( true, $path );
		return @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a path not there is answered.
	}

	/**
	 * The names in a folder.
	 *
	 * @param string $dir Folder.
	 * @return string[]|false
	 */
	public function scandir( $dir ) {
		return @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder not read is answered.
	}
}

/**
 * Class AB_MCP_Sync_Code_Swap
 */
final class AB_MCP_Sync_Code_Swap {

	/**
	 * Seconds a swap keeps beyond twice its test requests before (E5 2): the
	 * two renames, OPcache of each file replaced, the marks, a swap back. A
	 * named start value without a measurement; the measurement of branch 5
	 * (point 9) sets the real one.
	 */
	const RESERVE = 10;

	/** The targets the test requests before a swap reach, or it does not run (Z14, E5 4). */
	const NEEDED = array( 'home', 'mcp', 'admin_post' );

	/** The results of a target that count as reached: WordPress ran there (AB_MCP_Php_Probe::judge()). */
	const REACHED = array( 'ok', 'stopped', 'fatal' );

	/** The clock of the budget of a swap; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_code_swap = null;

	/**
	 * The locks prepare() took in this request and holds until release():
	 * the lock for changes, and the nonce of the note on WordPress's lock for
	 * updates. A lock release() could not give back stays here (A7).
	 */
	private static $held = array(
		'change' => false,
		'core'   => null,
	);

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Code_Swap_Calls
	 */
	private static function calls() {
		return self::$naht_sync_code_swap ?? new AB_MCP_Sync_Code_Swap_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_code_swap = null;
	}

	/* ------------------------------------------------------- what runs */

	/**
	 * The main files of the plugins that run on this site now, as the row of
	 * active_plugins holds them (unpacked without classes, in the form of
	 * its row site.active_plugins); none where the site has no such row (no
	 * plugin was ever activated); null where the row cannot be read or is
	 * not of its form (unknown).
	 *
	 * @return string[]|null
	 */
	public static function active_plugins() {
		$row = self::calls()->option( 'active_plugins' );
		if ( false === $row ) {
			return array();
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) || ! is_string( $row['value'] ) ) {
			return null;
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		$list = true === $read['ok'] ? AB_MCP_Store_Inputs::read( 'site.active_plugins', $read['value'] ) : null;
		return null === $list ? null : array_values( $list );
	}

	/**
	 * The theme that runs on this site now: the rows of stylesheet and
	 * template, each in the form of its row site.theme; the stylesheet is
	 * read again after the template, and a stylesheet that changed in
	 * between (a switch of the theme while it is read) is not known. Null
	 * where a row is missing, cannot be read or is not of its form.
	 *
	 * @return array{stylesheet:string,template:string}|null
	 */
	public static function theme() {
		$read = array();
		foreach ( array( 'stylesheet', 'template', 'again' ) as $option ) {
			$row             = self::calls()->option( 'again' === $option ? 'stylesheet' : $option );
			$read[ $option ] = is_array( $row ) && array_key_exists( 'value', $row ) ? AB_MCP_Store_Inputs::read( 'site.theme', $row['value'] ) : null;
		}
		if ( null === $read['stylesheet'] || null === $read['template'] || $read['again'] !== $read['stylesheet'] ) {
			return null;
		}
		return array(
			'stylesheet' => $read['stylesheet'],
			'template'   => $read['template'],
		);
	}

	/**
	 * The main files of a plugin (its folder, or its one file) that run on
	 * this site now; null where that is not known (active_plugins()).
	 *
	 * @param string $ref The plugin: the name of its folder or of its file.
	 * @return string[]|null
	 */
	public static function active_mains( $ref ) {
		$active = self::active_plugins();
		if ( null === $active || ! is_string( $ref ) || '' === $ref ) {
			return null;
		}
		$mains = array();
		foreach ( $active as $main ) {
			if ( $main === $ref || 0 === strpos( $main, $ref . '/' ) ) {
				$mains[] = $main;
			}
		}
		sort( $mains, SORT_STRING );
		return $mains;
	}

	/**
	 * Whether code of a unit runs on this site now (E5 2, E8): a plugin one
	 * of whose main files is active; a theme that is the stylesheet that runs
	 * or its template (the parent of the theme that runs). Null where that is
	 * not known: a row not read, or a kind that is neither.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  The unit.
	 * @return bool|null
	 */
	public static function runs( $kind, $ref ) {
		if ( 'plugin' === $kind ) {
			$mains = self::active_mains( $ref );
			return null === $mains ? null : array() !== $mains;
		}
		if ( 'theme' !== $kind || ! is_string( $ref ) || '' === $ref ) {
			return null;
		}
		$theme = self::theme();
		return null === $theme ? null : ( $ref === $theme['stylesheet'] || $ref === $theme['template'] );
	}

	/* --------------------------------------------------------- OPcache */

	/**
	 * Take a file out of OPcache (Z13: the one wrapper of
	 * AB_MCP_Own_Files::invalidate_opcache() in the swap). Its answer in the
	 * form of its row swap.opcache: invalidated and not_active let the swap
	 * go on; not_possible refuses with the way (code:opcache); an answer not
	 * of that form is not known and refuses (code:opcache_unknown).
	 *
	 * @param string $path The file.
	 * @return true|WP_Error
	 */
	public static function invalidate( $path ) {
		$answer = AB_MCP_Store_Inputs::read( 'swap.opcache', self::calls()->opcache( $path ) );
		if ( 'invalidated' === $answer || 'not_active' === $answer ) {
			return true;
		}
		if ( 'not_possible' === $answer ) {
			return self::refusal( 'code:opcache', __( 'OPcache runs on this site, and this request may not take a PHP file out of it (opcache.restrict_api, or the filter wp_opcache_invalidate_file), so running code is not swapped: the site could go on running the old code. Ask the host to let WordPress clear OPcache (opcache.restrict_api), or update the unit by hand in wp-admin.', 'alphabridge-mcp' ) );
		}
		return self::refusal( 'code:opcache_unknown', __( 'Whether this request can take a PHP file out of OPcache could not be read (its answer is none of invalidated, not_active and not_possible), so running code is not swapped. Call action=next again; where it keeps failing, update the unit by hand in wp-admin.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------ the test requests */

	/**
	 * The targets the test requests reached, from their answer in the form
	 * of its row swap.probe: target => whether every row of it was reached
	 * (ok, stopped or fatal: WordPress ran there); null where the answer is
	 * not of that form (unknown: never reached).
	 *
	 * @param mixed $answer The answer of AB_MCP_Php_Probe::run().
	 * @return array<string,bool>|null
	 */
	public static function reached( $answer ) {
		$read = AB_MCP_Store_Inputs::read( 'swap.probe', $answer );
		if ( null === $read ) {
			return null;
		}
		$out = array();
		foreach ( $read['targets'] as $row ) {
			$out[ $row['target'] ] = in_array( $row['result'], self::REACHED, true ) && ( ! array_key_exists( $row['target'], $out ) || $out[ $row['target'] ] );
		}
		return $out;
	}

	/* ------------------------------------------------------- the locks */

	/**
	 * Whether the row of the lock for changes is there now, a time of its
	 * form (its row swap.lock; decision V7): AB_MCP_Change_Lock::take()
	 * counts the lock as taken where WordPress's upgrader is missing and
	 * writes no row then, so prepare() asks the row itself.
	 *
	 * @return bool
	 */
	public static function lock_held() {
		$row = self::calls()->lock_row();
		return is_array( $row ) && array_key_exists( 'value', $row ) && null !== AB_MCP_Store_Inputs::read( 'swap.lock', $row['value'] );
	}

	/**
	 * Give back the locks prepare() took: WordPress's lock for updates
	 * first, in the other order of taking, and the lock for changes whatever
	 * came of the first (each in its own finally). A lock goes out of $held
	 * only where its release answered true; one that is not given back (an
	 * answer not true, or a Throwable) stays there and is named.
	 *
	 * @return string[] The locks that stay: core, change.
	 */
	public static function release() {
		$kept = array();
		try {
			if ( null !== self::$held['core'] ) {
				$freed = false;
				try {
					$freed = true === self::calls()->free_core_lock( self::$held['core'] );
				} finally {
					if ( $freed ) {
						self::$held['core'] = null;
					}
				}
				if ( ! $freed ) {
					$kept[] = 'core';
				}
			}
		} finally {
			if ( self::$held['change'] ) {
				self::$held['change'] = true !== self::calls()->release_lock();
			}
		}
		if ( self::$held['change'] ) {
			$kept[] = 'change';
		}
		return $kept;
	}

	/**
	 * A refusal of prepare() with the locks it could not give back named.
	 *
	 * @param WP_Error $refusal The refusal.
	 * @param string[] $kept    release().
	 * @return WP_Error
	 */
	private static function with_kept( WP_Error $refusal, array $kept ) {
		$message = $refusal->get_error_message();
		if ( in_array( 'core', $kept, true ) ) {
			$message .= ' ' . AB_MCP_Update_Guard::swap_lock_kept();
		}
		if ( in_array( 'change', $kept, true ) ) {
			/* translators: %d: minutes */
			$message .= ' ' . sprintf( __( 'AlphaBridge\'s lock for changes of the code, which this call took, could not be given back, so it stays: other changes through AlphaBridge can be refused until it is %d minutes old; from then on the next one takes it over.', 'alphabridge-mcp' ), intdiv( AB_MCP_Change_Lock::TIMEOUT, 60 ) );
		}
		return new WP_Error( $refusal->get_error_code(), $message, array( 'kept' => $kept ) );
	}

	/**
	 * The locks of a swap (E5 1, Z10): the lock for changes of the code, then
	 * WordPress's lock for updates with the note of the swap (the guard gives
	 * back what it took of the second where it is not held, A6); where the
	 * second is not taken, prepare() gives the first back (release()).
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  The unit.
	 * @return true|WP_Error
	 */
	private static function take( $kind, $ref ) {
		$change = self::calls()->take_lock();
		if ( true !== $change ) {
			return is_wp_error( $change ) ? $change : self::lock_unknown();
		}
		self::$held['change'] = true;
		$core                 = self::calls()->core_lock( $kind, $ref );
		if ( ! is_string( $core ) ) {
			return is_wp_error( $core ) ? $core : self::lock_unknown();
		}
		self::$held['core'] = $core;
		return true;
	}

	/**
	 * @return WP_Error A lock of the swap not read as held by this request.
	 */
	private static function lock_unknown() {
		return self::refusal( 'code:lock', __( 'The lock for changes of the code or WordPress\'s lock for updates is not read as held by this request (its answer, or its row in the database, is not of its form), so nothing is swapped. Call action=next again; where it keeps failing, check that the database of this site accepts writes.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------- before the first mutation */

	/**
	 * Every check of a swap before its first mutation, under its locks, in
	 * the order of E5 (Z3, Z4, Z10 to Z12, Z14; Z13 through invalidate();
	 * Z24 again through AB_MCP_Sync_Code_Ops::released()). Where it answers,
	 * the locks stay held until release(); the answer holds within this
	 * call and under these locks only. A refusal gives every lock back and
	 * names the way; so does a Throwable on the way.
	 *
	 * @param mixed $swap What the swap is asked with, in the form of its row
	 *                    swap.prepare: the run, the unit of the swap step,
	 *                    the packages of the environment of the report, the
	 *                    stage the journal of its stage step names, and when
	 *                    the swap has to be over (on the clock of clock(),
	 *                    given by the caller).
	 * @return array|WP_Error target, runs, probe, took, live, tree, signed, version.
	 */
	public static function prepare( $swap ) {
		$in = AB_MCP_Store_Inputs::read( 'swap.prepare', $swap );
		if ( null === $in || 'swap' !== $in['unit']['op'] || ( 'create' === $in['unit']['unit_op'] ) !== ( null === $in['unit']['pair_id'] ) || ( null === $in['unit']['pair_id'] ) !== ( null === $in['unit']['pair_hash'] ) ) {
			return self::refusal( 'code:swap_args', __( 'The swap was asked with values not of its form (the run, the unit of its step, the packages of the environment, the stage and the time of the call), so nothing is taken and nothing is swapped. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ) );
		}
		$target = AB_MCP_Sync_Code_Stage::target( $in['unit']['kind'], $in['unit']['ref'] );
		if ( null === $target ) {
			return self::refusal( 'code:unit', __( 'This site has no folder for plugins or themes WordPress names, so nothing is swapped. Check the constants WP_PLUGIN_DIR and the theme folder in wp-config.php.', 'alphabridge-mcp' ) );
		}
		$prepared = null;
		try {
			$taken    = self::take( $in['unit']['kind'], $in['unit']['ref'] );
			$prepared = true === $taken ? self::under_locks( $in, $target ) : $taken;
		} finally {
			if ( ! is_array( $prepared ) ) {
				$kept = self::release();
				if ( array() !== $kept && is_wp_error( $prepared ) ) {
					$prepared = self::with_kept( $prepared, $kept );
				}
			}
		}
		return $prepared;
	}

	/**
	 * E5 2 to 4 under the locks: the release of the code read again (Z24),
	 * the test requests switched on (for every swap) and the time left,
	 * OPcache for running code, the test requests before, the time for the
	 * rest of the swap; then the checks of E5 5 and before the mutation.
	 *
	 * @param array  $in     The arguments, read in the form of the row swap.prepare.
	 * @param string $target The folder or file of the unit in the site.
	 * @return array|WP_Error
	 */
	private static function under_locks( array $in, $target ) {
		$kind = $in['unit']['kind'];
		$ref  = $in['unit']['ref'];
		$runs = AB_MCP_Sync_Code_Ops::released( $in['unit'] );
		if ( is_wp_error( $runs ) ) {
			return $runs;
		}
		// The test requests before every swap, of running code or not (Z14,
		// E5 4; A2 of the Codex round on 4881c0d): switched off, or not known
		// as switched on, nothing is swapped. A unit made new has no running
		// code here (released() refuses one WordPress names as running): E5 3
		// (OPcache) is for running code only.
		if ( true !== self::calls()->probe_enabled() ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:probe_off', sprintf( __( 'The test requests of AlphaBridge are switched off on this site (the filter ab_mcp_php_probe_enabled), or not read as switched on, so the %1$s %2$s is not swapped: nothing would show whether the site works before the swap and still works after it. Switch the test requests on again, or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		$left = self::time_left( $kind, $ref, $in['until'], self::RESERVE + 1, 0.0 );
		if ( is_wp_error( $left ) ) {
			return $left;
		}
		if ( $runs ) {
			$file = self::php_file( $target, $in['until'] );
			if ( null === $file ) {
				/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
				return self::refusal( 'code:opcache_file', sprintf( __( 'No PHP file of the %1$s %2$s could be found and read on this site now (it has none, a folder of it could not be read, or the time of the call ran out), so whether OPcache lets its running code go is not known and it is not swapped. Call action=next again; where it keeps failing, update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
			}
			$opcache = self::invalidate( $file );
			if ( true !== $opcache ) {
				return $opcache;
			}
		}
		$probe = self::probe_before( $kind, $ref, $in['until'] );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		$now = self::time_left( $kind, $ref, $in['until'], 2 * $probe['took'] + self::RESERVE, $probe['took'] );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		// The readings after the test requests keep the time the rest of the
		// swap needs (A1): what stays of the call less twice the test
		// requests and the reserve.
		$in['keep'] = 2 * $probe['took'] + self::RESERVE;
		return self::checked( $in, $target, $now, $runs, $probe );
	}

	/**
	 * E5 5 and what holds right before the first mutation: the live unit
	 * against its base (Z3, after the test requests), the stage against the
	 * signed manifest (Z4), the version (Z12), AlphaBridge itself (Z11), one
	 * device (Z10), the row of the lock for changes (V7).
	 *
	 * @param array  $in     The arguments.
	 * @param string $target The folder or file of the unit.
	 * @param float  $now    The time now (clock()).
	 * @param bool   $runs   Whether its code runs (released()).
	 * @param array  $probe  answer, took.
	 * @return array|WP_Error
	 */
	private static function checked( array $in, $target, $now, $runs, array $probe ) {
		$kind     = $in['unit']['kind'];
		$ref      = $in['unit']['ref'];
		$deadline = self::read_deadline( $in );
		if ( is_wp_error( $deadline ) ) {
			return $deadline;
		}
		$live = self::unchanged( $in, $target, $now );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$stage = self::signed_stage( $in, $deadline );
		if ( is_wp_error( $stage ) ) {
			return $stage;
		}
		$version = null === $live ? null : self::not_down( $kind, $ref, $target, $live, $stage['signed']['version'], $deadline );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		$own = self::calls()->own( $target );
		if ( true === $own ) {
			return self::refusal( 'code:own', __( 'This is AlphaBridge itself, which is never swapped. Bring both sites to the same version of it by hand.', 'alphabridge-mcp' ) );
		}
		if ( false !== $own ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:own_unknown', sprintf( __( 'Whether the %1$s %2$s is AlphaBridge itself could not be read now (a folder of it or of AlphaBridge, or a plugin folder, could not be read), so it is not swapped. Call action=next again; where it keeps failing, ask the host why the plugin folders cannot be read, or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		$devices = array( self::device( 'stat', $stage['tree']['path'] ), self::device( 'stat', dirname( $target ) ) );
		if ( null !== $live ) {
			$devices[] = self::device( 'lstat', $target );
		}
		if ( in_array( null, $devices, true ) || 1 !== count( array_unique( $devices ) ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:store_device', sprintf( __( 'The protected store and the folder of the %1$s %2$s are not read as lying on one device of this server now, so it is not swapped: a unit is swapped only by renaming, which works on one device only. Ask the host to put the protected store on the device of wp-content, or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		if ( ! self::lock_held() || true !== self::calls()->core_held( self::$held['core'] ) ) {
			return self::lock_unknown();
		}
		// After every check before the mutation (A1 of the Codex round on
		// 4881c0d): the time for the swap, its test after it and a swap back
		// with its test, asked again.
		$end = self::time_left( $kind, $ref, $in['until'], 2 * $probe['took'] + self::RESERVE, $probe['took'] );
		if ( is_wp_error( $end ) ) {
			return $end;
		}
		return array(
			'target'  => $target,
			'runs'    => $runs,
			'probe'   => $probe['answer'],
			'took'    => $probe['took'],
			'live'    => null === $live ? null : array(
				'hash' => $live['hash'],
				'form' => $live['form'],
			),
			'tree'    => $stage['tree'],
			'signed'  => $stage['signed']['hash'],
			'version' => array(
				'staging' => $stage['signed']['version'],
				'live'    => $version,
			),
		);
	}

	/**
	 * The deadline of the readings after the test requests (R12; A1 of the
	 * Codex round on 4881c0d..947fa6d): first the place of the protected
	 * store is decided as this request decides it (AB_MCP_Protected_Store::
	 * proof(), with its self-check where one is due), which no deadline
	 * interrupts and whose time the readings then lack (until stays); then
	 * until − keep on the clock of the budget (AB_MCP_Sync_Deadline::at()),
	 * checked before the next step. The readers under it never decide the
	 * store themselves. The last check of the time left before the mutation
	 * (checked()) stays.
	 *
	 * @param array $in The arguments, with keep (under_locks()).
	 * @return AB_MCP_Sync_Deadline|WP_Error
	 */
	private static function read_deadline( array $in ) {
		AB_MCP_Protected_Store::proof();
		$deadline = AB_MCP_Sync_Deadline::at( $in['until'] - $in['keep'] );
		$left     = is_wp_error( $deadline ) ? $deadline : AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			/* translators: 1: why the stage or the signed manifest was not read (one or more whole sentences), 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			return self::refusal( $left->get_error_code(), sprintf( __( '%1$s So the %2$s %3$s is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', 'alphabridge-mcp' ), $left->get_error_message(), $in['unit']['kind'], $in['unit']['ref'] ) );
		}
		return $deadline;
	}

	/**
	 * A PHP file of the unit there now, for the check of OPcache before a
	 * swap of running code (E5 3): the unit itself where it is one PHP
	 * file, else the first PHP file of its folder by name, folder by folder
	 * from the top, links not followed. Null where it has none, a folder of
	 * it is not read, or the time of the call is over: a unit without a PHP
	 * file is not swapped (the decision of the lead on question 8; a theme
	 * of blocks has its PHP files in patterns/, as Twenty Twenty-Three).
	 *
	 * @param string $target The folder or file of the unit.
	 * @param float  $until  When the swap has to be over.
	 * @return string|null
	 */
	private static function php_file( $target, $until ) {
		if ( self::is_mode( self::calls()->lstat( $target ), 0100000 ) ) {
			return '.php' === strtolower( substr( $target, -4 ) ) ? $target : null;
		}
		$dirs = array( $target );
		while ( array() !== $dirs ) {
			$dir   = array_shift( $dirs );
			$now   = self::clock();
			$names = self::calls()->scandir( $dir );
			if ( null === $now || $now > $until || ! is_array( $names ) ) {
				return null;
			}
			sort( $names, SORT_STRING );
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) || '.' === $name || '..' === $name ) {
					continue;
				}
				$stat = self::calls()->lstat( $dir . '/' . $name );
				if ( '.php' === strtolower( substr( $name, -4 ) ) && self::is_mode( $stat, 0100000 ) ) {
					return $dir . '/' . $name;
				}
				if ( self::is_mode( $stat, 0040000 ) ) {
					$dirs[] = $dir . '/' . $name;
				}
			}
		}
		return null;
	}

	/**
	 * Whether an answer of stat() is of a type (a regular file 0100000, a
	 * folder 0040000).
	 *
	 * @param mixed $stat The answer.
	 * @param int   $type The type.
	 * @return bool
	 */
	private static function is_mode( $stat, $type ) {
		return is_array( $stat ) && isset( $stat['mode'] ) && is_int( $stat['mode'] ) && $type === ( $stat['mode'] & 0170000 );
	}

	/**
	 * The test requests before a swap (Z14, E5 4): every target to its end,
	 * within the time of the call less the reserve; the home page, this MCP
	 * endpoint and admin-post.php reached, or the refusal.
	 *
	 * @param string $kind  plugin or theme.
	 * @param string $ref   The unit.
	 * @param float  $until When the swap has to be over.
	 * @return array|WP_Error answer, took (seconds).
	 */
	private static function probe_before( $kind, $ref, $until ) {
		$start = self::time_left( $kind, $ref, $until, self::RESERVE + 1, 0.0 );
		if ( is_wp_error( $start ) ) {
			return $start;
		}
		$answer = self::calls()->probe( array( 'until' => microtime( true ) + ( $until - self::RESERVE - $start ) ) );
		$end    = self::clock();
		if ( null === $end ) {
			return self::clock_refusal();
		}
		$reached = self::reached( $answer );
		if ( null === $reached ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:probe_unknown', sprintf( __( 'The answer of the test requests before the swap of the %1$s %2$s does not read in the form AlphaBridge writes it, so it counts as not passed and the unit is not swapped. Call action=next again; where it keeps failing, update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		$missing = array();
		foreach ( self::NEEDED as $name ) {
			if ( ! array_key_exists( $name, $reached ) || true !== $reached[ $name ] ) {
				$missing[] = $name;
			}
		}
		if ( array() !== $missing ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the targets not reached, as the probe names them (home, mcp, admin_post). */
			return self::refusal( 'code:probe_before', sprintf( __( 'The test requests before the swap of the %1$s %2$s did not reach every target the swap compares with afterwards (not reached: %3$s; home is the home page, mcp this MCP endpoint, admin_post wp-admin/admin-post.php), so the unit is not swapped. Let the site reach itself (wp_site_health shows the test requests), then call action=next again; or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref, implode( ', ', $missing ) ) );
		}
		return array(
			'answer' => $answer,
			'took'   => $end - $start,
		);
	}

	/**
	 * Z3: the live unit is its base, read now and whole (after the test
	 * requests, the last comparison before the first mutation); a unit made
	 * new has an empty place (its absence by lstat()). The pair is the one
	 * the plan was made with (its snapshot), active, of this unit, with a
	 * base of its files.
	 *
	 * @param array  $in     The arguments.
	 * @param string $target The folder or file of the unit.
	 * @param float  $now    The time now (clock()).
	 * @return array|null|WP_Error The unit read (hash, form, entries); null for a unit made new.
	 */
	private static function unchanged( array $in, $target, $now ) {
		$unit = $in['unit'];
		if ( 'create' === $unit['unit_op'] ) {
			$empty = self::place_empty( $target );
			if ( false === $empty ) {
				/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
				return self::refusal( 'code:live_changed', sprintf( __( 'Something lies in the place of the %1$s %2$s on this site now, which the plan makes new here (code:live_changed), so it is not swapped. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
			}
			if ( true !== $empty ) {
				/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
				return self::refusal( 'code:place_unknown', sprintf( __( 'Whether the place of the %1$s %2$s on this site, which the plan makes new here, is empty could not be read now (its folder was not listed, or the place not read), so it is not swapped. Call action=next again; where it keeps failing, ask the host why the folder of the plugins or themes cannot be read.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
			}
			return null;
		}
		$snapshot = AB_MCP_Sync_Pairs::snapshot( $unit['pair_id'] );
		$pair     = AB_MCP_Sync_Pairs::get( $unit['pair_id'] );
		$base     = is_array( $pair ) ? AB_MCP_Sync_Pairs::part_bases( $pair, 'files' ) : null;
		if ( ! is_string( $snapshot ) || ! hash_equals( $unit['pair_hash'], AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) ) || null === $base || 'active' !== $pair['state'] || $unit['kind'] !== $pair['kind'] || $unit['ref'] !== $pair['live_ref'] ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:pair', sprintf( __( 'The pair of the %1$s %2$s could not be read now, or is no longer the active pair the plan was made with, so the base of its files is not known and it is not swapped. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		$read = AB_MCP_Sync_File_Manifest::unit( dirname( $target ), $unit['ref'], 'live', min( $in['until'] - $in['keep'], $now + AB_MCP_Sync_Env::UNIT_BUDGET ), true, $unit['kind'] );
		if ( ! is_array( $read ) || true !== $read['complete'] || ! is_string( $read['hash'] ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:live_unknown', sprintf( __( 'The %1$s %2$s on this site could not be read whole now (a file or folder not read, or the time of the call ran out), so whether it is still the version its pair is based on is not known and it is not swapped. Call action=next again; where it keeps failing, update it by hand in wp-admin.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		if ( ! hash_equals( $base['live'], $read['hash'] ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:live_changed', sprintf( __( 'The files of the %1$s %2$s on this site are no longer the ones its pair is based on (code:live_changed), so it is not swapped, and the version of the staging site does not win over them. Where the plugin writes into its own folder while it runs, update it by hand in wp-admin; else make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		return $read;
	}

	/**
	 * Whether the place of a unit made new is empty (Z3, A3 of the Codex
	 * round on 4881c0d): true only where the folder above lists without its
	 * name and lstat() finds nothing; false where something is there (lstat()
	 * reads it, or the folder lists its name); null where that is not known
	 * (the folder not listed, nothing read of the place).
	 *
	 * @param string $target The folder or file of the unit.
	 * @return bool|null
	 */
	private static function place_empty( $target ) {
		$stat  = self::calls()->lstat( $target );
		$names = self::calls()->scandir( dirname( $target ) );
		if ( is_array( $stat ) || ( is_array( $names ) && in_array( basename( $target ), $names, true ) ) ) {
			return false;
		}
		if ( false !== $stat || ! is_array( $names ) ) {
			return null;
		}
		return true;
	}

	/**
	 * Z4: the stage the journal names, ready for this step (ready(), the one
	 * reader of the state ready, bound to this run, this unit and the hash
	 * of the plan), its tree read whole, and three hashes the same: of the
	 * tree, of its state (tree and signed), and of the signed manifest read
	 * now (signed()), which is the hash of the plan. ready(), tree() and
	 * signed() read under the deadline of the swap (R12), whose stop is the
	 * refusal with its own code; tree() also within its own budget
	 * (TREE_BUDGET), until the earlier of the two.
	 *
	 * @param array                $in       The arguments.
	 * @param AB_MCP_Sync_Deadline $deadline The deadline of the readings (read_deadline()).
	 * @return array|WP_Error tree, signed.
	 */
	private static function signed_stage( array $in, AB_MCP_Sync_Deadline $deadline ) {
		$unit  = $in['unit'];
		$stage = $in['stage'];
		$state = AB_MCP_Sync_Code_Stage::ready(
			$stage['gid'],
			$stage['name'],
			$stage['hash'],
			array(
				'run_id' => $in['run_id'],
				'kind'   => $unit['kind'],
				'ref'    => $unit['ref'],
				'signed' => $unit['staging_hash'],
			),
			$deadline
		);
		if ( null === $state ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:stage_not_ready', sprintf( __( 'The stage of the %1$s %2$s is not read as ready for this step (its state, its tree and hash, or its binding to this run, this unit and the version of the staging site), so it is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		$tree   = is_wp_error( $state ) ? $state : AB_MCP_Protected_Store::tree( 'stage', $stage['gid'], $stage['name'], $stage['hash'], $deadline );
		$signed = is_wp_error( $tree ) ? $tree : AB_MCP_Sync_Code_Stage::signed( $in['env'], $unit['kind'], $unit['ref'], $deadline );
		if ( is_wp_error( $signed ) ) {
			/* translators: 1: why the stage or the signed manifest was not read (one or more whole sentences), 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			return self::refusal( $signed->get_error_code(), sprintf( __( '%1$s So the %2$s %3$s is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', 'alphabridge-mcp' ), $signed->get_error_message(), $unit['kind'], $unit['ref'] ) );
		}
		if ( ! hash_equals( $signed['hash'], $tree['hash'] ) || ! hash_equals( $state['tree']['hash'], $tree['hash'] ) || ! hash_equals( $state['signed'], $signed['hash'] ) || ! hash_equals( $unit['staging_hash'], $signed['hash'] ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:stage_mismatch', sprintf( __( 'The stage of the %1$s %2$s is not the unit the staging site signed: the hash of its tree, the hashes its state names and the hash of the signed manifest, read now, are not all the same, so it is not swapped. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		return array(
			'tree'   => $tree,
			'signed' => $signed,
		);
	}

	/**
	 * Z12: never down a version forward. The version of the live unit from
	 * a head read whole now (AB_MCP_Sync_Env::head_read()): of a theme its
	 * style.css; of a plugin of one file that file; of a plugin in a folder
	 * its first main file by name, as the report takes it (a PHP file in the
	 * folder itself, not hidden, whose head names a Plugin Name), from the
	 * files of the reading of Z3. A head not read whole on the way, or none
	 * named, is not known and refuses, as a staging version that is none.
	 * Under the deadline of the readings of the swap (R12): the files of the
	 * heads (heads()) and each head (AB_MCP_Sync_Env::head_read()) only while
	 * it is not over, and once more before the versions are compared; its
	 * stop is the refusal with its own code, never a version not known.
	 *
	 * @param string               $kind     plugin or theme.
	 * @param string               $ref      The unit.
	 * @param string               $target   The folder or file of the unit.
	 * @param array                $live     The unit read (unchanged()).
	 * @param mixed                $staging  The version of the staging site (signed()).
	 * @param AB_MCP_Sync_Deadline $deadline The deadline of the readings (read_deadline()).
	 * @return string|WP_Error The live version.
	 */
	private static function not_down( $kind, $ref, $target, array $live, $staging, AB_MCP_Sync_Deadline $deadline ) {
		$version = null;
		foreach ( self::heads( $kind, $target, $live, $deadline ) as $file ) {
			$head = AB_MCP_Sync_Env::head_read( $file, array( 'Plugin Name', 'Version' ), $deadline );
			if ( null === $head ) {
				break;
			}
			if ( 'theme' === $kind || '' !== $head['Plugin Name'] ) {
				$version = $head['Version'];
				break;
			}
		}
		// A head or the files of the heads not read for the deadline: its
		// stop, without the clock asked again; else the deadline once more.
		$stop = AB_MCP_Sync_Deadline::stop( $deadline );
		$left = null === $stop ? AB_MCP_Sync_Deadline::left( $deadline ) : $stop;
		if ( true !== $left ) {
			/* translators: 1: why the stage or the signed manifest was not read (one or more whole sentences), 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			return self::refusal( $left->get_error_code(), sprintf( __( '%1$s So the %2$s %3$s is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', 'alphabridge-mcp' ), $left->get_error_message(), $kind, $ref ) );
		}
		$order = AB_MCP_Sync_Code_Ops::order( $staging, $version );
		if ( null === $order ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:version_unknown', sprintf( __( 'The version of the %1$s %2$s could not be read from its head read whole on this site now, or the one of the staging site is no version, so whether the swap goes down a version is not known and it is not swapped. Make a new report (wp_site_diff) and plan again, or update it by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		if ( 'older' === $order ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the version on the staging site, 4: the version on this site. */
			return self::refusal( 'code:downgrade', sprintf( __( 'The staging site has an older version of the %1$s %2$s (%3$s) than this site (%4$s), and a swap never goes down a version. Update it on the staging site, take its environment over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $kind, $ref, $staging, $version ) );
		}
		return $version;
	}

	/**
	 * The files whose head names the version of a live unit, in order
	 * (not_down()). Under the deadline of the readings (R12) each entry of
	 * the unit read and each name only while it is not over; once it is,
	 * none, and not_down() passes the stop on (AB_MCP_Sync_Deadline::stop()).
	 * The sort of the names is one call (a named limit of the deadline).
	 *
	 * @param string               $kind     plugin or theme.
	 * @param string               $target   The folder or file of the unit.
	 * @param array                $live     The unit read: form, entries.
	 * @param AB_MCP_Sync_Deadline $deadline The deadline of the readings.
	 * @return string[]
	 */
	private static function heads( $kind, $target, array $live, AB_MCP_Sync_Deadline $deadline ) {
		if ( 'theme' === $kind ) {
			return array( $target . '/style.css' );
		}
		if ( 'file' === $live['form'] ) {
			return array( $target );
		}
		$names = array();
		foreach ( $live['entries'] as $entry ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return array();
			}
			if ( false === strpos( $entry[0], '/' ) && '.' !== substr( $entry[0], 0, 1 ) && '.php' === substr( $entry[0], -4 ) ) {
				$names[] = $entry[0];
			}
		}
		sort( $names, SORT_STRING );
		$files = array();
		foreach ( $names as $name ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return array();
			}
			$files[] = $target . '/' . $name;
		}
		return $files;
	}

	/**
	 * The device of a path now, a whole number; null where it is not read.
	 *
	 * @param string $how  stat or lstat.
	 * @param string $path The path.
	 * @return int|null
	 */
	private static function device( $how, $path ) {
		$stat = 'lstat' === $how ? self::calls()->lstat( $path ) : self::calls()->stat( $path );
		return is_array( $stat ) && isset( $stat['dev'] ) && is_int( $stat['dev'] ) ? $stat['dev'] : null;
	}

	/**
	 * The time now where the call has the time it needs; else the refusal
	 * (code:means), or code:clock where the time is not measured.
	 *
	 * @param string $kind  plugin or theme.
	 * @param string $ref   The unit.
	 * @param float  $until When the swap has to be over.
	 * @param float  $need  Seconds needed from now.
	 * @param float  $took  Seconds the test requests before took (0 before them).
	 * @return float|WP_Error
	 */
	private static function time_left( $kind, $ref, $until, $need, $took ) {
		$now = self::clock();
		if ( null === $now ) {
			return self::clock_refusal();
		}
		if ( $until - $now < $need ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: seconds left, 4: seconds needed, 5: seconds the test requests before took. */
			return self::refusal( 'code:means', sprintf( __( 'This call has %3$s seconds left, and the swap of the %1$s %2$s needs %4$s from here (twice the test requests before it, which took %5$s seconds, and a reserve for the swap and a swap back), so it is not swapped in this call. Call action=next again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time), or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref, number_format( max( 0, $until - $now ), 1, '.', '' ), number_format( $need, 1, '.', '' ), number_format( $took, 1, '.', '' ) ) );
		}
		return $now;
	}

	/**
	 * The time within one call, for the budget of a swap only: microtime(
	 * true ), or the clock of a test. A value that is no finite number is
	 * unknown (null): the swap does not go on (a named exception of the
	 * time() guard: budgets within a request; times across requests only
	 * through AB_MCP_Sync_Run::now()).
	 *
	 * @return float|null Seconds.
	 */
	private static function clock() {
		$now = null !== self::$clock ? call_user_func( self::$clock ) : microtime( true );
		return is_float( $now ) && is_finite( $now ) ? $now : null;
	}

	/**
	 * @return WP_Error The time of the call could not be measured.
	 */
	private static function clock_refusal() {
		return self::refusal( 'code:clock', __( 'The time of this call could not be measured, so the unit is not swapped. Call action=next again.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param string $code    Code.
	 * @param string $message Why, with the way.
	 * @return WP_Error
	 */
	private static function refusal( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
