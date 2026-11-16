<?php
/**
 * The swap of a unit of code on the production site (Z8b branch 5; plan E5,
 * E6, E9, 3.1 Z3 to Z16, Z20a, as Variante b keeps them, 15.23): the one
 * mechanics that puts a staged plugin or theme in the place of the one this
 * site runs.
 *
 * WHAT RUNS (active_plugins(), theme(), runs(); plan E8, 3.1 Z24): whether
 * code of a unit runs on this site is read fresh from the rows of the
 * options (active_plugins; stylesheet and template), past every cache and
 * every filter, in the form of their rows of AB_MCP_Store_Inputs::TABLE
 * (site.active_plugins, site.theme). A row that cannot be read, or holds
 * another form, is not known: never «does not run» (a swap of running code
 * takes its release, AB_MCP_Sync_Code_Ops::run()).
 *
 * BEFORE THE FIRST MUTATION (prepare(); plan E5 1 to 5, 3.1 Z3, Z4, Z10 to
 * Z12, Z14; Z24 again under the lock): every check of a swap, in the order
 * of E5, in the one call of the swap and under its lock: the lock for
 * changes of the code (AB_MCP_Change_Lock); the release of its code
 * (AB_MCP_Sync_Code_Ops::released(), the one rule of Z24); the test
 * requests switched on and the time left; OPcache for a
 * PHP file of running code (invalidate()); the test requests before (home
 * page, MCP endpoint, admin-post.php reached); the time kept for the swap
 * (twice the test requests before and a reserve, a margin kept as it was
 * built, 15.23 «zu 5»); then the live unit hashed
 * whole against its base (or, for a unit made new, its place empty), the
 * stage against the signed manifest, the version (never down), AlphaBridge
 * itself (strictly, AB_MCP_Own_Files::own_state()), one device for the
 * stage and the place of the unit, and the row of the lock for changes.
 * prepare() swaps no file and writes over no file of the live unit; it
 * takes the lock, may take a PHP file out of OPcache and sends the test
 * requests. A refusal gives the lock back. What it answers holds within
 * this call and under this lock only; it says nothing of what the site is
 * afterwards.
 *
 * THE SWAP (run(); plan E5 6 to 11 as Variante b keeps them): in the same
 * call, under the same lock, after prepare(): the place of the old version
 * in the backups of the run (backup_place()); rename_marked(), the one
 * function that renames in the swap (Z15, Z16): the journal of the step, the
 * mark and its instruction, the note of the replacement
 * (AB_MCP_Update_Log::mark_replaced()) last, each read back, then the
 * target into the backup (the whole old folder) and the stage into the
 * target, each only to a place that is free; OPcache for each PHP file
 * replaced (invalidate(), Z13); and
 * close() (Z20a): the mark closes only on the new version proved at the
 * target and the old one whole in the backup, then the file undo points of
 * the unit go. The files of its pair take their new bases, the step is
 * done. The swap sends no test request after it and swaps nothing back by
 * itself (Variante b): its answer names the way back of the run, the target,
 * the backup and the commands of the way by hand.
 *
 * OPCACHE (invalidate(), Z13): the one wrapper of
 * AB_MCP_Own_Files::invalidate_opcache() in the swap; only invalidated or
 * not_active let it go on, in the form of its row swap.opcache.
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 5: rename(), the journal and
 * the mark of the swap, the note of the replacement, OPcache, the test
 * requests before, the file undo points; the rows of the options read; the
 * lock, AlphaBridge's own files and the file system for prepare()) go
 * through AB_MCP_Sync_Code_Swap_Calls, held by the seam of this class; only
 * the tests put another object there. No filter and no option reaches it.
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
require_once __DIR__ . '/class-sync-code-kind.php';
require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-backup.php';
require_once __DIR__ . '/class-sync-resources.php';
require_once dirname( __DIR__ ) . '/undo/class-undo.php';

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
	 * Whether the test requests may run on this site.
	 *
	 * @return mixed
	 */
	public function probe_enabled() {
		return AB_MCP_Php_Probe::enabled();
	}

	/**
	 * The test requests to every target, each to its end (no stop at a
	 * fatal error: prepare() asks each target it needs).
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

	/**
	 * Rename a folder or file (the one call of the swap to rename(), only
	 * through rename_marked()).
	 *
	 * @param string $from The path now.
	 * @param string $to   The path after.
	 * @return bool
	 */
	public function rename( $from, $to ) {
		return @rename( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- a rename that fails is answered; a swap renames only on one device.
	}

	/**
	 * Set the rights of a folder or file (only through given_rights()).
	 *
	 * @param string $path Path.
	 * @param int    $mode The rights.
	 * @return bool
	 */
	public function chmod( $path, $mode ) {
		return @chmod( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- rights not set are answered.
	}

	/**
	 * The note of the replacement of the files of a folder (E5 6): a file
	 * undo point recorded before $since holds a file of the previous version.
	 *
	 * @param string $type  plugin or theme.
	 * @param string $slug  The folder.
	 * @param int    $since When the swap began.
	 */
	public function replaced( $type, $slug, $since ) {
		AB_MCP_Update_Log::mark_replaced( $type, $slug, $since );
	}

	/**
	 * Write a step of the run (its state from the state read, its journal,
	 * its result, its pair).
	 *
	 * @param int    $run_id Run.
	 * @param int    $seq    Step.
	 * @param array  $set    state, parts, result, pair_id.
	 * @param string $from   The state read.
	 * @return true|WP_Error
	 */
	public function journal( $run_id, $seq, array $set, $from ) {
		return AB_MCP_Sync_Run::update_step( $run_id, $seq, $set, $from );
	}

	/**
	 * A step of the run as it is stored now.
	 *
	 * @param int $run_id Run.
	 * @param int $seq    Step.
	 * @return array|null|WP_Error
	 */
	public function step( $run_id, $seq ) {
		return AB_MCP_Sync_Run::step( $run_id, $seq );
	}

	/**
	 * Write the mark of the swap and its instruction, each read back.
	 *
	 * @param array $mark See AB_MCP_Protected_Store::put_mark().
	 * @return array|WP_Error
	 */
	public function put_mark( array $mark ) {
		return AB_MCP_Protected_Store::put_mark( $mark );
	}

	/**
	 * Close the mark of the swap.
	 *
	 * @param string $mid Mark.
	 * @return true|WP_Error
	 */
	public function close_mark( $mid ) {
		return AB_MCP_Protected_Store::close_mark( $mid );
	}

	/**
	 * Discard the file undo points of a unit on this site.
	 *
	 * @param string $jail plugin or theme.
	 * @param string $slug The folder.
	 * @return string[] The points discarded.
	 */
	public function discard_files( $jail, $slug ) {
		return AB_MCP_Undo::discard_files_of( $jail, $slug );
	}
}

/**
 * Class AB_MCP_Sync_Code_Swap
 */
final class AB_MCP_Sync_Code_Swap {

	/**
	 * Seconds a swap keeps beyond twice its test requests before (E5 2): the
	 * place of the backup, the journal, the mark and the note, the two
	 * renames, OPcache of each file replaced and the readings of close(). A
	 * named start value without a measurement; the measurement of branch 5
	 * (part G of the rest) names the real durations.
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
	 * The lock prepare() took in this request and holds until release(): the
	 * lock for changes. A lock release() could not give back stays here (A7).
	 */
	private static $held = array(
		'change' => false,
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

	/* -------------------------------------------------------- the lock */

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
	 * Give back the lock prepare() took: the lock for changes. It goes out
	 * of $held only where its release answered true; one that is not given
	 * back (an answer not true, or a Throwable) stays there and is named.
	 *
	 * @return string[] The lock that stays: change, or none.
	 */
	public static function release() {
		$kept = array();
		if ( self::$held['change'] ) {
			self::$held['change'] = true !== self::calls()->release_lock();
		}
		if ( self::$held['change'] ) {
			$kept[] = 'change';
		}
		return $kept;
	}

	/**
	 * A refusal of prepare() with the lock it could not give back named.
	 *
	 * @param WP_Error $refusal The refusal.
	 * @param string[] $kept    release().
	 * @return WP_Error
	 */
	private static function with_kept( WP_Error $refusal, array $kept ) {
		return new WP_Error( $refusal->get_error_code(), $refusal->get_error_message() . self::kept_note( $kept ), array( 'kept' => $kept ) );
	}

	/**
	 * The sentence on the lock release() could not give back, with a blank
	 * before it; none where it was given back.
	 *
	 * @param string[] $kept release().
	 * @return string
	 */
	private static function kept_note( array $kept ) {
		if ( ! in_array( 'change', $kept, true ) ) {
			return '';
		}
		/* translators: %d: minutes */
		return ' ' . sprintf( __( 'AlphaBridge\'s lock for changes of the code, which this call took, could not be given back, so it stays: other changes through AlphaBridge can be refused until it is %d minutes old; from then on the next one takes it over.', 'alphabridge-mcp' ), intdiv( AB_MCP_Change_Lock::TIMEOUT, 60 ) );
	}

	/**
	 * The lock of a swap (E5 1, Z10): the lock for changes of the code
	 * (AB_MCP_Change_Lock).
	 *
	 * @return true|WP_Error
	 */
	private static function take() {
		$change = self::calls()->take_lock();
		if ( true !== $change ) {
			return is_wp_error( $change ) ? $change : self::lock_unknown();
		}
		self::$held['change'] = true;
		return true;
	}

	/**
	 * @return WP_Error The lock of the swap not read as held by this request.
	 */
	private static function lock_unknown() {
		return self::refusal( 'code:lock', __( 'The lock for changes of the code is not read as held by this request (its answer, or its row in the database, is not of its form), so nothing is swapped. Call action=next again; where it keeps failing, check that the database of this site accepts writes.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------- before the first mutation */

	/**
	 * Every check of a swap before its first mutation, under its lock, in
	 * the order of E5 (Z3, Z4, Z10 to Z12, Z14; Z13 through invalidate();
	 * Z24 again through AB_MCP_Sync_Code_Ops::released()). Where it answers,
	 * the lock stays held until release(); the answer holds within this
	 * call and under this lock only. A refusal gives the lock back and
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
			return self::args_refusal();
		}
		$target = AB_MCP_Sync_Code_Stage::target( $in['unit']['kind'], $in['unit']['ref'] );
		if ( null === $target ) {
			return self::refusal( 'code:unit', __( 'This site has no folder for plugins or themes WordPress names, so nothing is swapped. Check the constants WP_PLUGIN_DIR and the theme folder in wp-config.php.', 'alphabridge-mcp' ) );
		}
		$prepared = null;
		try {
			$taken    = self::take();
			$prepared = true === $taken ? self::under_lock( $in, $target ) : $taken;
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
	 * @return WP_Error The swap asked with values not of the form of swap.prepare.
	 */
	private static function args_refusal() {
		return self::refusal( 'code:swap_args', __( 'The swap was asked with values not of its form (the run, the unit of its step, the packages of the environment, the stage and the time of the call), so nothing is taken and nothing is swapped. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ) );
	}

	/**
	 * E5 2 to 4 under the lock: the release of the code read again (Z24),
	 * the test requests switched on (for every swap) and the time left,
	 * OPcache for running code, the test requests before, the time for the
	 * rest of the swap; then the checks of E5 5 and before the mutation.
	 *
	 * @param array  $in     The arguments, read in the form of the row swap.prepare.
	 * @param string $target The folder or file of the unit in the site.
	 * @return array|WP_Error
	 */
	private static function under_lock( array $in, $target ) {
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
			return self::refusal( 'code:probe_off', sprintf( __( 'The test requests of AlphaBridge are switched off on this site (the filter ab_mcp_php_probe_enabled), or not read as switched on, so the %1$s %2$s is not swapped: nothing would show that the site works before the swap. Switch the test requests on again, or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
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
		if ( ! self::lock_held() ) {
			return self::lock_unknown();
		}
		// After every check before the mutation (A1 of the Codex round on
		// 4881c0d): the time kept for the swap (twice the test requests before
		// it and the reserve, the margin as it was built), asked again.
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
	 * @param array $in The arguments, with keep (under_lock()).
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
			return self::refusal( 'code:probe_before', sprintf( __( 'The test requests before the swap of the %1$s %2$s did not reach every target this site has to answer before a swap (not reached: %3$s; home is the home page, mcp this MCP endpoint, admin_post wp-admin/admin-post.php), so the unit is not swapped. Let the site reach itself (wp_site_health shows the test requests), then call action=next again; or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref, implode( ', ', $missing ) ) );
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

	/* ------------------------------------------------------------ the swap */

	/**
	 * When a swap asked now has to be over, on the clock of the swap (the
	 * until of swap.prepare its caller gives, AB_MCP_Sync_Code_Ops): now and
	 * the work of one call (AB_MCP_Sync_Resources::time_for_call(): half the
	 * time limit of the request, at most WORK_MAX, as for every loop of a
	 * call of the run); 0 where the clock gives no time, which prepare()
	 * refuses (code:clock).
	 *
	 * @return float
	 */
	public static function until() {
		$now = self::clock();
		if ( null === $now ) {
			return 0.0;
		}
		$time = AB_MCP_Sync_Resources::time_for_call();
		return $now + (float) $time['budget'];
	}

	/**
	 * The swap of a unit in one call (plan E5 6 to 11 as Variante b keeps
	 * them, 15.23): prepare() under the lock for changes; then the place of
	 * the old version in the backups of the run (plan_of()), the time once
	 * more, rename_marked(), OPcache for each PHP file replaced, close() and
	 * the new bases of the files of its pair; the step ends with its journal
	 * and result. The lock prepare() took is given back in every way. A unit
	 * made new is not swapped at this commit (part D3 of the rest). Before
	 * prepare() the rights of E10 for the operation of the unit
	 * (AB_MCP_Sync_Code_Kind::rights(); F4, part D3a).
	 *
	 * @param mixed $swap What the swap is asked with, in the form of its row swap.prepare.
	 * @return array|WP_Error next, the answer of the step as it ended (done; failed where
	 *                        nothing was moved; unclear with the way back of the run and the
	 *                        instruction of its mark, unclear()); a refusal
	 *                        where nothing of the site changed and the step stays planned.
	 */
	public static function run( $swap ) {
		$in = AB_MCP_Store_Inputs::read( 'swap.prepare', $swap );
		if ( null !== $in && 'create' === $in['unit']['unit_op'] ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:create_not_built', sprintf( __( 'The plan makes the %1$s %2$s new on this site, and the swap of a unit made new is not part of this version, so nothing is swapped and nothing of this site changed. Abort the run (wp_site_push action=abort) and install the unit by hand in wp-admin.', 'alphabridge-mcp' ), $in['unit']['kind'], $in['unit']['ref'] ) );
		}
		// The rights of E10 (Z26; F4), right before the step writes: those of
		// the account for the operation (an edit, the same version with other
		// files, takes edit_plugins or edit_themes besides, which
		// DISALLOW_FILE_EDIT takes from everyone) and of the site
		// (DISALLOW_FILE_MODS, the direct file system method); a refusal
		// changes nothing and the step stays planned.
		$engine = null === $in ? true : AB_MCP_Sync_Code_Kind::engine();
		$rights = is_object( $engine ) ? $engine->rights( $in['unit']['unit_op'], $in['unit']['kind'] . ':' . $in['unit']['ref'] ) : $engine;
		if ( true !== $rights ) {
			return $rights;
		}
		$prepared = self::prepare( $swap );
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}
		try {
			$answer = null === $in ? self::args_refusal() : self::swapped( $in, $prepared );
		} finally {
			$kept = self::release();
		}
		if ( array() === $kept ) {
			return $answer;
		}
		return is_wp_error( $answer ) ? self::with_kept( $answer, $kept ) : array( 'next' => $answer['next'] . self::kept_note( $kept ) );
	}

	/**
	 * The swap after prepare() answered, under its lock. What is asked
	 * without writing comes before the writes that prepare the swap in the
	 * protected store, the group of the backups (plan_of()) and the rights
	 * of the stage (given_rights()) (Codex D4, finding 4): the clock of the
	 * run, here; the time kept for the swap and the unit read whole within
	 * one call, last in prepare(). Then what it moves, read from the target
	 * (plan_of()); the new version given the rights of the site; the time
	 * kept for the swap asked once more right before the first write (the
	 * place of the backup read the target whole, the rights took their
	 * time); rename_marked(), which reads the clock of the run again for the
	 * time the swap begins; and its end.
	 *
	 * @param array $in       The arguments, read in the form of the row swap.prepare.
	 * @param array $prepared prepare().
	 * @return array|WP_Error
	 */
	private static function swapped( array $in, array $prepared ) {
		if ( null === AB_MCP_Sync_Run::now() ) {
			return AB_MCP_Sync_Run::clock_unknown();
		}
		$plan = self::plan_of( $in, $prepared );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$rights = self::given_rights( $in, $plan );
		if ( is_wp_error( $rights ) ) {
			return $rights;
		}
		$left = self::time_left( $in['unit']['kind'], $in['unit']['ref'], $in['until'], 2 * $prepared['took'] + self::RESERVE, $prepared['took'] );
		if ( is_wp_error( $left ) ) {
			return $left;
		}
		$moved = self::rename_marked( $in, $plan );
		if ( 'done' !== $moved['at'] ) {
			return self::not_swapped( $in, $plan, $moved );
		}
		return self::after_renames( $in, $prepared, $plan, $moved );
	}

	/**
	 * What the swap moves, read before anything is written: the place of the
	 * old version in the backups of the run (AB_MCP_Sync_Backup::group(),
	 * AB_MCP_Protected_Store::put_tree() without a source: the rules of a
	 * tree, read from the target, whose hash is the one prepare() read);
	 * the identity of the target and of the stage (form, device, inode);
	 * and the main files of a plugin active now, for its mark.
	 *
	 * @param array $in       The arguments.
	 * @param array $prepared prepare().
	 * @return array|WP_Error target, old and new (path, gid, name, hash, form, dev, ino), active.
	 */
	private static function plan_of( array $in, array $prepared ) {
		$kind   = $in['unit']['kind'];
		$ref    = $in['unit']['ref'];
		$target = $prepared['target'];
		$name   = basename( $target );
		$gid    = AB_MCP_Sync_Backup::group( $in['run_id'] );
		$place  = is_wp_error( $gid ) ? $gid : AB_MCP_Protected_Store::put_tree( 'backups', $gid, $name, $target );
		if ( is_wp_error( $place ) ) {
			$cause = $place->get_error_data();
			if ( 'ab_mcp_store_tree_unread' === $place->get_error_code() && is_array( $cause ) && 'too_large' === ( $cause['cause'] ?? null ) ) {
				// F38: a target too large to be read whole within one call stays
				// so in the next call and the next run (Codex D4, finding 6), as
				// in prepare() (unchanged()).
				/* translators: 1: why the target was not read whole (one or more whole sentences), 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
				return self::refusal( $place->get_error_code(), sprintf( __( '%1$s So the %2$s %3$s is not swapped. Call action=next again; where it keeps failing, update it by hand in wp-admin.', 'alphabridge-mcp' ), $place->get_error_message(), $kind, $ref ) );
			}
			/* translators: 1: why the stage or the signed manifest was not read (one or more whole sentences), 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			return self::refusal( $place->get_error_code(), sprintf( __( '%1$s So the %2$s %3$s is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', 'alphabridge-mcp' ), $place->get_error_message(), $kind, $ref ) );
		}
		if ( ! hash_equals( $prepared['live']['hash'], $place['hash'] ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:live_changed', sprintf( __( 'The files of the %1$s %2$s on this site are no longer the ones its pair is based on (code:live_changed), so it is not swapped, and the version of the staging site does not win over them. Where the plugin writes into its own folder while it runs, update it by hand in wp-admin; else make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		$old    = self::identity( $target );
		$new    = self::identity( $prepared['tree']['path'] );
		$active = 'plugin' === $kind ? self::active_mains( $ref ) : array();
		if ( null === $old || null === $new || null === $active ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::refusal( 'code:identity', sprintf( __( 'The folder of the %1$s %2$s on this site, its stage in the protected store, or which of its main files run, could not be read now (device and inode of a folder, or the option active_plugins), so it is not swapped and nothing of it was moved. Call action=next again; where it keeps failing, update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref ) );
		}
		return array(
			'target' => $target,
			'old'    => array(
				'path' => $place['path'],
				'gid'  => $gid,
				'name' => $name,
				'hash' => $place['hash'],
			) + $old,
			'new'    => array(
				'path' => $prepared['tree']['path'],
				'gid'  => $in['stage']['gid'],
				'name' => $in['stage']['name'],
				'hash' => $prepared['tree']['hash'],
			) + $new,
			'active' => $active,
		);
	}

	/**
	 * A folder or file as it is now, a link as a link: its form, device and
	 * inode (the identity a mark and the journal name); null where it is not
	 * read so, or is neither a folder nor a plain file.
	 *
	 * @param string $path The path.
	 * @return array{form:string,dev:int,ino:int}|null
	 */
	private static function identity( $path ) {
		$stat = self::calls()->lstat( $path );
		$form = self::is_mode( $stat, 0040000 ) ? 'folder' : ( self::is_mode( $stat, 0100000 ) ? 'file' : null );
		if ( null === $form || ! isset( $stat['dev'], $stat['ino'] ) || ! is_int( $stat['dev'] ) || ! is_int( $stat['ino'] ) ) {
			return null;
		}
		return array(
			'form' => $form,
			'dev'  => $stat['dev'],
			'ino'  => $stat['ino'],
		);
	}

	/**
	 * The rights of the new version as WordPress gives them to the files of
	 * a plugin or theme it installs or updates (Codex D1, finding 2): each
	 * folder FS_CHMOD_DIR, each file FS_CHMOD_FILE; where they are not
	 * defined, WordPress's own reckoning (WP_Filesystem(),
	 * wp-admin/includes/file.php: fileperms( ABSPATH ) & 0777 | 0755 and
	 * fileperms( ABSPATH . 'index.php' ) & 0777 | 0644). Else the stage would
	 * take the rights of its building folder (0700, AB_MCP_Sync_Code_Stage)
	 * into the site. Each entry set and read back, folder by folder from the
	 * top, links not followed, within the time of the call; before the first
	 * write of the swap, so that a refusal leaves the site as it was and the
	 * step planned. The stage stays below the folders of the store (0700).
	 * The rights are no part of its hash: the manifest reads the type of an
	 * entry only.
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @return true|WP_Error
	 */
	private static function given_rights( array $in, array $plan ) {
		$mode = static function ( $path ) {
			$stat = self::calls()->stat( $path );
			return is_array( $stat ) && isset( $stat['mode'] ) && is_int( $stat['mode'] ) ? $stat['mode'] : 0;
		};
		$want = array(
			'folder' => defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : ( $mode( ABSPATH ) & 0777 | 0755 ),
			'file'   => defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( $mode( ABSPATH . 'index.php' ) & 0777 | 0644 ),
		);
		$ok   = is_int( $want['folder'] ) && is_int( $want['file'] ) && min( $want ) >= 0 && max( $want ) <= 07777;
		$todo = $ok ? array( $plan['new']['path'] ) : array();
		while ( $ok && array() !== $todo ) {
			$path  = array_shift( $todo );
			$stat  = self::calls()->lstat( $path );
			$form  = self::is_mode( $stat, 0040000 ) ? 'folder' : ( self::is_mode( $stat, 0100000 ) ? 'file' : null );
			$now   = self::clock();
			$ok    = null !== $form && null !== $now && $now <= $in['until'] && true === self::calls()->chmod( $path, $want[ $form ] );
			$back  = $ok ? self::calls()->lstat( $path ) : null;
			$ok    = $ok && is_array( $back ) && isset( $back['mode'] ) && is_int( $back['mode'] ) && ( $back['mode'] & 07777 ) === $want[ $form ];
			$names = $ok && 'folder' === $form ? self::calls()->scandir( $path ) : array();
			$ok    = $ok && is_array( $names );
			foreach ( $ok ? $names : array() as $name ) {
				$ok = $ok && is_string( $name );
				if ( $ok && '.' !== $name && '..' !== $name ) {
					$todo[] = $path . '/' . $name;
				}
			}
		}
		if ( $ok ) {
			return true;
		}
		$octal = static function ( $rights ) {
			return is_int( $rights ) ? sprintf( '%04o', $rights ) : '-';
		};
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the rights of a folder (octal, as 0755), 4: the rights of a file (octal, as 0644). */
		return self::refusal( 'code:rights', sprintf( __( 'The new version of the %1$s %2$s could not be given the rights WordPress gives the files of a plugin or theme it installs (folders %3$s, files %4$s: FS_CHMOD_DIR and FS_CHMOD_FILE, or as WordPress reckons them from the folder of the site), each set and read back within the time of this call, so it is not swapped and nothing of this site changed. Call action=next again; where it keeps failing, check that PHP may change the rights of the files it owns in the protected store and that FS_CHMOD_DIR and FS_CHMOD_FILE are whole numbers of rights, or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $in['unit']['kind'], $in['unit']['ref'], $octal( $want['folder'] ), $octal( $want['file'] ) ) );
	}

	/**
	 * The one function that renames in the swap (Z15, Z16; E5 6 and 7). First
	 * the journal of the step (begun), the mark of the swap with its
	 * instruction and the note of the replacement with the time the swap
	 * begins (AB_MCP_Update_Log::mark_replaced(), for the file undo points of
	 * before it), in this order and each read back: the note last, so that
	 * a journal or mark that fails leaves no note of a swap that did not
	 * begin (the lead on D2a, 16.11. 03:57; Z15 and F56 ask only that all
	 * three stand before the first rename); then the target into the backup
	 * (the whole old folder, never beside the target) and the stage into the
	 * target, each only to a place that is free (move()). Where the second
	 * rename fails, the old version goes back to the target, again only
	 * where the target is free (F35).
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @return array{at:string,mid:string|null,guide:string|null,why:WP_Error|null,journal:array|null,back:bool|null}
	 *         at: journal (the step not begun, nothing moved), mark (the step begun, no
	 *         mark), note (the mark open, the note not read back, nothing moved), first
	 *         (the mark open and the note written, nothing moved), second (the second
	 *         rename failed; back: whether the old version went back to the target), done.
	 *         mid and guide: the mark and the file of its instruction (absolute path), as
	 *         put_mark() gave them.
	 */
	private static function rename_marked( array $in, array $plan ) {
		$out   = array(
			'at'      => 'journal',
			'mid'     => null,
			'guide'   => null,
			'why'     => null,
			'journal' => null,
			'back'    => null,
		);
		$unit  = $in['unit'];
		$since = AB_MCP_Sync_Run::now();
		if ( null === $since ) {
			return array( 'why' => AB_MCP_Sync_Run::clock_unknown() ) + $out;
		}
		$journal = array(
			'unit'  => array(
				'kind' => $unit['kind'],
				'ref'  => $unit['ref'],
				'op'   => 'swap',
			),
			'since' => $since,
		) + $plan;
		$begun   = self::begun( $in, $journal );
		if ( true !== $begun ) {
			return array(
				'at'  => 'journal',
				'why' => $begun,
			) + $out;
		}
		$out['journal'] = $journal;
		$mark           = self::calls()->put_mark( self::mark_of( $in, $plan ) );
		if ( ! is_array( $mark ) || ! isset( $mark['mid'], $mark['guide'] ) || ! is_string( $mark['mid'] ) || ! is_string( $mark['guide'] ) ) {
			return array(
				'at'  => 'mark',
				'why' => is_wp_error( $mark ) ? $mark : self::refusal( 'code:mark', __( 'The mark of the swap gave no answer of its form.', 'alphabridge-mcp' ) ),
			) + $out;
		}
		$out['mid']   = $mark['mid'];
		$out['guide'] = $mark['guide'];
		// The note last (the lead on D2a, 16.11. 03:57): where the journal
		// or the mark fails, it is not written, so it never names a swap that
		// did not begin; since stays the time in the journal.
		$noted = self::noted( $unit['kind'], $unit['ref'], $since );
		if ( true !== $noted ) {
			return array(
				'at'  => 'note',
				'why' => $noted,
			) + $out;
		}
		if ( ! self::move( $plan['target'], $plan['old']['path'] ) ) {
			return array( 'at' => 'first' ) + $out;
		}
		if ( ! self::move( $plan['new']['path'], $plan['target'] ) ) {
			return array(
				'at'   => 'second',
				'back' => self::move( $plan['old']['path'], $plan['target'] ),
			) + $out;
		}
		return array( 'at' => 'done' ) + $out;
	}

	/**
	 * A rename only to a place that is free (Z16: exclusive): nothing is
	 * there now (lstat()), then rename(); nothing is reserved, unlinked or
	 * deleted (the lead after Codex D1, round 2: no reservation of the
	 * place, which journal, instruction and way by hand do not know).
	 * What another process puts at the place between lstat() and rename()
	 * (GRENZEN-CODE.md, G-5-10; rename(2), POSIX.1):
	 * - a folder (the unit is a folder): rename() never replaces a folder
	 *   with content (ENOTEMPTY or EEXIST) and never a file with a folder
	 *   (ENOTDIR), so the rename fails, or it replaces at most an empty
	 *   folder, which carries no data. That is the exclusivity of Z16 for a
	 *   folder. Under Windows a rename onto a folder that is there fails
	 *   altogether.
	 * - a single file (F6): rename() replaces a file that is there. A file
	 *   that comes to the place just between lstat() and rename() is
	 *   replaced; in practice an update of the same unit at the same time
	 *   (G-5-9). A named limit, as in WordPress (move_dir(), and copy() of
	 *   WP_Filesystem_Direct, check first and write then).
	 *
	 * @param string $from The path now.
	 * @param string $to   The path after.
	 * @return bool Whether it moved.
	 */
	private static function move( $from, $to ) {
		return false === self::calls()->lstat( $to ) && true === self::calls()->rename( $from, $to );
	}

	/**
	 * The note of the replacement (E5 6), written and read back: the row of
	 * AB_MCP_Update_Log::REPLACED as it is stored now, past every cache,
	 * unpacked without classes, holds the folder of the unit with this very
	 * time.
	 *
	 * @param string $kind  plugin or theme.
	 * @param string $ref   The unit.
	 * @param int    $since When the swap began (AB_MCP_Sync_Run::now()).
	 * @return true|WP_Error
	 */
	private static function noted( $kind, $ref, $since ) {
		self::calls()->replaced( $kind, $ref, $since );
		$row  = self::calls()->option( AB_MCP_Update_Log::REPLACED );
		$read = is_array( $row ) && array_key_exists( 'value', $row ) && is_string( $row['value'] ) ? AB_MCP_Sync_Canon::unserialized( $row['value'] ) : null;
		$key  = $kind . ':' . $ref;
		if ( is_array( $read ) && true === $read['ok'] && is_array( $read['value'] ) && array_key_exists( $key, $read['value'] ) && $since === $read['value'][ $key ] ) {
			return true;
		}
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
		return self::refusal( 'code:note', sprintf( __( 'The note that the files of the %1$s %2$s are replaced (it tells a file undo point of before the swap from one of after it) did not read back as written, so nothing of it was moved; where the note is written all the same, the undo of AlphaBridge Pro refuses the file undo points of these files from before this call, as after a swap. Where it keeps failing, check that the database of this site accepts writes.', 'alphabridge-mcp' ), $kind, $ref ) );
	}

	/**
	 * The journal of the step written with the state begun, from planned,
	 * and read back (E5 7): the step as stored now is begun and holds this
	 * very journal (its canonical form, AB_MCP_Sync_Canon::encode()).
	 *
	 * @param array $in      The arguments.
	 * @param array $journal The journal.
	 * @return true|WP_Error
	 */
	private static function begun( array $in, array $journal ) {
		$saved = self::calls()->journal( $in['run_id'], $in['unit']['seq'], array( 'state' => 'begun', 'parts' => $journal ), 'planned' );
		$step  = true === $saved ? self::calls()->step( $in['run_id'], $in['unit']['seq'] ) : null;
		if ( is_array( $step ) && 'begun' === $step['state'] && ( ! isset( $step['unread'] ) || array() === $step['unread'] ) && AB_MCP_Sync_Canon::encode( $journal ) === AB_MCP_Sync_Canon::encode( $step['parts'] ) ) {
			return true;
		}
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the error of the database, or nothing. */
		return self::refusal( 'code:journal', sprintf( __( 'The journal of the swap of the %1$s %2$s could not be written and read back before its first rename (%3$s), so nothing of it was moved. Ask for the status of the run (wp_site_push action=status) and go on from there; where it keeps failing, check that the database of this site accepts writes.', 'alphabridge-mcp' ), $in['unit']['kind'], $in['unit']['ref'], is_wp_error( $saved ) ? $saved->get_error_message() : '-' ) );
	}

	/**
	 * The mark of the swap as put_mark() takes it: the run and the step, the
	 * unit, the target, the backup (the old version) and the stage (the new
	 * one) with their identity and hash, the main files active now.
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @return array
	 */
	private static function mark_of( array $in, array $plan ) {
		$trees = array();
		foreach ( array( 'backup' => $plan['old'], 'stage' => $plan['new'] ) as $role => $tree ) {
			$trees[] = array(
				'role' => $role,
				'path' => $tree['path'],
				'form' => $tree['form'],
				'dev'  => $tree['dev'],
				'ino'  => $tree['ino'],
				'hash' => $tree['hash'],
			);
		}
		return array(
			'run_id' => $in['run_id'],
			'seq'    => $in['unit']['seq'],
			'op'     => 'swap',
			'unit'   => array(
				'kind' => $in['unit']['kind'],
				'ref'  => $in['unit']['ref'],
			),
			'target' => $plan['target'],
			'trees'  => $trees,
			'active' => $plan['active'],
		);
	}

	/**
	 * After the two renames: OPcache for each PHP file replaced, close(),
	 * the new bases of the files of the pair (E5 11), the step done. A
	 * failure on the way ends the step unclear (15.23 «zu 2»: the way back
	 * of the run puts the old version back; the way by hand is the
	 * instruction of the mark). What its answer says of the disk follows
	 * what close() proved in this call (unclear()).
	 *
	 * @param array $in       The arguments.
	 * @param array $prepared prepare().
	 * @param array $plan     plan_of().
	 * @param array $moved    rename_marked().
	 * @return array|WP_Error
	 */
	private static function after_renames( array $in, array $prepared, array $plan, array $moved ) {
		$unit    = $in['unit'];
		$opcache = self::invalidated( $plan, $in['until'] );
		if ( is_wp_error( $opcache ) ) {
			// Whatever is put back or left, OPcache is to be cleared at the
			// host (the lead on D2a 4). close() was not reached, so nothing
			// is proved in this call.
			return self::unclear( $in, $plan, $moved, $opcache->get_error_message() . ' ' . __( 'Have OPcache of this site cleared at the host, or PHP restarted (PHP-FPM or the web server), so that PHP runs the files that are at the target now.', 'alphabridge-mcp' ) );
		}
		// Whether both versions are proved in this call, close() says at its
		// one place; what fails after that still has them proved.
		$whole  = false;
		$closed = self::close( 'swap', $in, $plan, $moved['mid'], $whole );
		if ( is_wp_error( $closed ) ) {
			return self::unclear( $in, $plan, $moved, $closed->get_error_message(), $whole );
		}
		$rebased = self::rebased( $in, $plan );
		if ( is_wp_error( $rebased ) ) {
			/* translators: %s: the error of the object pairs (one or more whole sentences). */
			return self::unclear( $in, $plan, $moved, sprintf( __( 'The new version is at the target and the mark of the swap is closed, but the files of its pair did not take their new bases (%s).', 'alphabridge-mcp' ), $rebased->get_error_message() ), $whole );
		}
		$by_hand = self::by_hand( $in, $plan );
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: its version here before, 4: its version now, 5: the folder or file of the unit (absolute path), 6: the backup of the old version (absolute path), 7: run number. */
		$message = sprintf( __( 'The %1$s %2$s of the staging site is swapped in (version %3$s here before, %4$s now): its new version lies at %5$s, the old one whole in the backup %6$s. Look at the site now (the home page and wp-admin): where something fails, the way back of this run puts the old version back (wp_site_revert action=plan with run_id %7$d). Where this site answers no request any more (the new version ends fatal on every call, so neither MCP nor wp-admin is reached), put the old version back by hand in a shell on the server, as the lines below say:', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], (string) $prepared['version']['live'], (string) $prepared['version']['staging'], $plan['target'], $plan['old']['path'], $in['run_id'] );
		$message .= "\n" . implode( "\n", $by_hand );
		$ended    = self::ended(
			$in,
			'done',
			$moved['journal'],
			array(
				'message'   => $message,
				'swapped'   => array(
					'kind' => $unit['kind'],
					'ref'  => $unit['ref'],
				),
				'version'   => $prepared['version'],
				'target'    => $plan['target'],
				'backup'    => $plan['old']['path'],
				'mark'      => $moved['mid'],
				'opcache'   => count( $opcache ),
				'discarded' => $closed['discarded'],
				'by_hand'   => $by_hand,
			)
		);
		// The call to go on only once the end is recorded (Codex D3,
		// finding 3), as the engine adds it.
		return is_wp_error( $ended ) ? $ended : array( 'next' => $ended['next'] . "\n" . __( 'Call action=next for the next step.', 'alphabridge-mcp' ) );
	}

	/**
	 * OPcache for each PHP file replaced (E5 8, Z13): every PHP file of the
	 * old version (now in the backup) and of the new one (now at the
	 * target), each by its path at the target, through invalidate(). The
	 * paths, sorted; the error where one is not invalidated or not_active,
	 * or the files of a version could not all be listed (php_paths()).
	 *
	 * @param array $plan  plan_of().
	 * @param float $until When the swap has to be over.
	 * @return string[]|WP_Error
	 */
	private static function invalidated( array $plan, $until ) {
		$paths = array();
		foreach ( array( $plan['old']['path'], $plan['target'] ) as $root ) {
			$found = self::php_paths( $root, $until );
			if ( null === $found ) {
				/* translators: %s: a folder (absolute path). */
				return new WP_Error( 'code:opcache_files', sprintf( __( 'The PHP files in %s could not all be listed (a folder or an entry not read, an entry neither a folder nor a file, or the time of this call over), so OPcache was not cleared for each file the swap replaced, and this site may still run old code.', 'alphabridge-mcp' ), $root ) );
			}
			foreach ( $found as $rel ) {
				$paths[ '' === $rel ? $plan['target'] : $plan['target'] . '/' . $rel ] = true;
			}
		}
		$paths = array_keys( $paths );
		sort( $paths, SORT_STRING );
		foreach ( $paths as $path ) {
			$done = self::invalidate( $path );
			if ( true !== $done ) {
				/* translators: 1: a PHP file (absolute path), 2: the code of the answer (code:opcache, code:opcache_unknown). */
				return new WP_Error( $done->get_error_code(), sprintf( __( 'OPcache could not be cleared for %1$s (%2$s), so this site may still run the old code of it.', 'alphabridge-mcp' ), $path, $done->get_error_code() ) );
			}
		}
		return $paths;
	}

	/**
	 * The files PHP runs below a folder (or the file itself), relative to it
	 * ('', the file itself), by the list of the stage
	 * (AB_MCP_Sync_Code_Stage::is_php(): .php, .inc, .phtml …; Codex D1,
	 * finding 1), folder by folder, links not followed; null where the
	 * listing is not whole (finding 4): a folder not listed, an entry not
	 * read (lstat() fails) or neither a folder nor a plain file, or the time
	 * of the call is over.
	 *
	 * @param string $root  A folder or file.
	 * @param float  $until When the swap has to be over.
	 * @return string[]|null
	 */
	private static function php_paths( $root, $until ) {
		$top = self::calls()->lstat( $root );
		if ( self::is_mode( $top, 0100000 ) ) {
			return AB_MCP_Sync_Code_Stage::is_php( $root ) ? array( '' ) : array();
		}
		if ( ! self::is_mode( $top, 0040000 ) ) {
			return null;
		}
		$out  = array();
		$dirs = array( '' );
		while ( array() !== $dirs ) {
			$rel   = array_shift( $dirs );
			$now   = self::clock();
			$names = self::calls()->scandir( '' === $rel ? $root : $root . '/' . $rel );
			if ( null === $now || $now > $until || ! is_array( $names ) ) {
				return null;
			}
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = is_string( $name ) ? ( '' === $rel ? $name : $rel . '/' . $name ) : null;
				$stat = null === $path ? false : self::calls()->lstat( $root . '/' . $path );
				if ( self::is_mode( $stat, 0040000 ) ) {
					$dirs[] = $path;
				} elseif ( ! self::is_mode( $stat, 0100000 ) ) {
					return null; // Not read, or neither a folder nor a plain file: the listing is not whole.
				} elseif ( AB_MCP_Sync_Code_Stage::is_php( $name ) ) {
					$out[] = $path;
				}
			}
		}
		return $out;
	}

	/**
	 * The end of the mark of a swap (Z20a), one condition for each way it
	 * ends: swap, the new version at the target with its identity proved
	 * (form, device and inode of the stage, the hash of the unit read whole
	 * there now that of the stage) and the old one whole in the backup
	 * (form, device and inode of the old folder, its tree read back by the
	 * store against its hash), then the file undo points of the unit go
	 * (AB_MCP_Undo::discard_files_of(), as settle() of the Update Guard);
	 * kept, nothing moved for good: the old version at the target with its
	 * identity proved. No comparison of what is active (15.23). Anything
	 * else closes no mark.
	 *
	 * Here is also the one place that says whether the proofs of this call
	 * are whole (option B, Thomas 16.11.2026): only for a swap whose new
	 * version at the target and whose old one in the backup were both
	 * proved just now. Only then an answer after a failure names what lies
	 * where and gives the commands of the way by hand (unclear()).
	 *
	 * And the one place that takes an error of the store into the answer
	 * of the swap: what close_mark() said goes on as it is, but for a mark
	 * that could not be read (ab_mcp_store_mark_read). Of that the swap
	 * says only that it could not be read, in its own words, and nothing
	 * on whether the mark is open or what it names stays (Codex on
	 * 3014d8b, finding 1).
	 *
	 * @param string $op    swap or kept.
	 * @param array  $in    The arguments.
	 * @param array  $plan  plan_of().
	 * @param string $mid   The mark.
	 * @param bool   $whole Set where the version at the target (and, for a
	 *                      swap, the old one in the backup) was proved: true
	 *                      for a swap, whatever comes after, false for kept;
	 *                      left as it is where a proof failed.
	 * @return array{discarded:int}|WP_Error
	 */
	private static function close( $op, array $in, array $plan, $mid, &$whole = false ) {
		$swap   = 'swap' === $op;
		$proved = self::at_target( $in, $plan, $swap ? $plan['new'] : $plan['old'] );
		if ( true === $proved && $swap ) {
			$proved = self::in_backup( $plan['old'], $in['until'] );
		}
		if ( true !== $proved ) {
			return $proved;
		}
		$whole  = $swap;
		$closed = self::calls()->close_mark( $mid );
		if ( true !== $closed ) {
			if ( is_wp_error( $closed ) && 'ab_mcp_store_mark_read' === $closed->get_error_code() ) {
				// A mark that could not be read: the answer says that and no
				// more (option B). The sentence of the store for this case
				// names a state of the mark that nothing read in this call
				// (it may be closed by now), so it is not passed on.
				return self::refusal( 'code:mark_read', __( 'The mark of the swap could not be read.', 'alphabridge-mcp' ) );
			}
			return is_wp_error( $closed ) ? $closed : self::refusal( 'code:mark', __( 'The mark of the swap gave no answer of its form.', 'alphabridge-mcp' ) );
		}
		$gone = $swap ? self::calls()->discard_files( 'theme' === $in['unit']['kind'] ? 'theme' : 'plugin', $in['unit']['ref'] ) : array();
		return array( 'discarded' => is_array( $gone ) ? count( $gone ) : 0 );
	}

	/**
	 * Whether the version a swap left at the target is there with its
	 * identity proved: its form, device and inode, and the hash of the unit
	 * read whole there now (AB_MCP_Sync_File_Manifest::unit(), within the
	 * time of the call).
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @param array $want The tree of the version (old or new of plan_of()).
	 * @return true|WP_Error
	 */
	private static function at_target( array $in, array $plan, array $want ) {
		$at  = self::identity( $plan['target'] );
		$now = self::clock();
		if ( null !== $at && null !== $now && array( $want['form'], $want['dev'], $want['ino'] ) === array( $at['form'], $at['dev'], $at['ino'] ) ) {
			$read = AB_MCP_Sync_File_Manifest::unit( dirname( $plan['target'] ), $in['unit']['ref'], 'live', min( $in['until'], $now + AB_MCP_Sync_Env::UNIT_BUDGET ), true, $in['unit']['kind'] );
			if ( is_array( $read ) && true === $read['complete'] && is_string( $read['hash'] ) && hash_equals( $want['hash'], $read['hash'] ) ) {
				return true;
			}
		}
		/* translators: %s: the folder or file of the unit (absolute path). */
		return new WP_Error( 'code:close_target', sprintf( __( 'The version at %s could not be proved as the one the swap left there (its device, inode or hash differ, or it could not be read whole within the time of this call).', 'alphabridge-mcp' ), $plan['target'] ) );
	}

	/**
	 * Whether the old version lies whole in the backup: its form, device and
	 * inode, and its tree read back by the store against its hash
	 * (AB_MCP_Protected_Store::tree()), only reading, and only while the time
	 * of the call is not over (its deadline, R12). The one proof of the
	 * backup: close() asks it here.
	 *
	 * @param array $old   The old version (plan_of()).
	 * @param float $until The end of the time of the call.
	 * @return true|WP_Error
	 */
	private static function in_backup( array $old, $until ) {
		$at   = self::identity( $old['path'] );
		$tree = null !== $at && array( $old['form'], $old['dev'], $old['ino'] ) === array( $at['form'], $at['dev'], $at['ino'] ) ? AB_MCP_Protected_Store::tree( 'backups', $old['gid'], $old['name'], $old['hash'], AB_MCP_Sync_Deadline::at( $until ) ) : null;
		if ( is_array( $tree ) ) {
			return true;
		}
		/* translators: %s: the backup of the old version (absolute path). */
		return new WP_Error( 'code:close_backup', sprintf( __( 'The old version could not be proved whole in the backup %s (its device or inode differ, its tree did not read back as its hash, or the time of this call ran out before it was read).', 'alphabridge-mcp' ), $old['path'] ) );
	}

	/**
	 * The files of the pair of the unit take their new bases (E5 11, Z38):
	 * live and staging the hash of the new version, from the kind code,
	 * which writes and checks the part files; on the condition that the
	 * pair is still the one the plan was made with.
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @return array|WP_Error
	 */
	private static function rebased( array $in, array $plan ) {
		$engine = AB_MCP_Sync_Code_Kind::engine();
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		return AB_MCP_Sync_Pairs::rebase(
			$in['unit']['pair_id'],
			array(
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => $plan['new']['hash'],
				'base_staging' => $in['unit']['staging_hash'],
				'base_origin'  => 'push',
				'last_run'     => $in['run_id'],
			),
			$in['unit']['pair_hash'],
			'files',
			$engine
		);
	}

	/**
	 * A swap that moved nothing for good (rename_marked() stopped before both
	 * renames held). Before the journal: its refusal, the step stays
	 * planned. After it: the mark, where one was written, closed on the old
	 * version proved at the target (close(), kept), and the step failed,
	 * also where the note of the replacement did not read back; where a
	 * rename failed after the note, the answer says that it stays without
	 * a replacement. Where the old version did not go back, is not proved
	 * at the target, or its mark did not close, the step is unclear: the
	 * answer says what this call did and names the way back of the run and
	 * the instruction of the mark, nothing of what lies where (unclear()).
	 *
	 * @param array $in    The arguments.
	 * @param array $plan  plan_of().
	 * @param array $moved rename_marked().
	 * @return array|WP_Error
	 */
	private static function not_swapped( array $in, array $plan, array $moved ) {
		if ( null === $moved['journal'] ) {
			return $moved['why'];
		}
		if ( 'second' === $moved['at'] && true !== $moved['back'] ) {
			// Only what this call did: what is at the target now is not read
			// for the answer (option B); who puts back reads it.
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return self::unclear( $in, $plan, $moved, sprintf( __( 'The swap of the %1$s %2$s did not finish: its old version was moved into the backup, the new one could not be put at the target, and the old one could not be put back.', 'alphabridge-mcp' ), $in['unit']['kind'], $in['unit']['ref'] ) );
		}
		$whole  = false;
		$closed = null === $moved['mid'] ? true : self::close( 'kept', $in, $plan, $moved['mid'], $whole );
		if ( is_wp_error( $closed ) ) {
			return self::unclear( $in, $plan, $moved, $closed->get_error_message(), $whole );
		}
		// After the note, a rename that failed leaves it without a
		// replacement; it is not taken back (the lead on D2a, 16.11. 03:57).
		$void = __( 'The note that its files are replaced stays written all the same (it is not taken back): the undo of AlphaBridge Pro refuses the file undo points of these files from before this call, as after a swap.', 'alphabridge-mcp' );
		$why  = array(
			// A mark not given may still be written in part (mark.json before
			// its instruction; D2a 3, the Festlegung of this part): named, as
			// the swap knows no id of it.
			'mark'   => ( null === $moved['why'] ? '' : $moved['why']->get_error_message() . ' ' ) . self::part_mark_note( $in ),
			'note'   => null === $moved['why'] ? '' : $moved['why']->get_error_message(),
			'first'  => __( 'The old version could not be moved into the backup of the run (rename() failed, or the place was not free).', 'alphabridge-mcp' ) . ' ' . $void,
			'second' => __( 'The new version could not be put at the target (rename() failed, or the place was not free), so the old one went back to its place.', 'alphabridge-mcp' ) . ' ' . $void,
		);
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: why (one or more whole sentences). */
		$message = sprintf( __( 'The %1$s %2$s is not swapped, and the old version is at its place: %3$s Its stage stays in the protected store. The run goes on with the next step (action=next); to swap the unit, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $in['unit']['kind'], $in['unit']['ref'], $why[ $moved['at'] ] );
		return self::ended( $in, 'failed', $moved['journal'], array( 'message' => $message ) );
	}

	/**
	 * Where the mark of a swap was not given: that a part of it may be left
	 * in the folder of the marks of the store, open (its mark.json without
	 * its instruction), and what that means.
	 *
	 * @param array $in The arguments.
	 * @return string A sentence.
	 */
	private static function part_mark_note( array $in ) {
		$root = AB_MCP_Protected_Store::root();
		/* translators: 1: the folder of the marks of the protected store (absolute path), 2: run number, 3: step number. */
		return sprintf( __( 'A mark of this swap may be left in part in %1$s (run %2$d, step %3$d, open): it moved nothing, and while it is open the protected store keeps the stage of this unit; it needs nothing else.', 'alphabridge-mcp' ), is_wp_error( $root ) ? 'the protected store' : $root . '/' . AB_MCP_Protected_Store::MARKS, $in['run_id'], $in['unit']['seq'] );
	}

	/**
	 * The end of a swap that is not proved (15.23 «zu 2»): the step unclear.
	 * The one place that builds this answer (option B, Thomas 16.11.2026):
	 * of the disk it says only what close() proved in this call.
	 * - The proofs whole (a swap, both versions proved just now, then
	 *   something failed: the mark, the pair): as the answer of a swap
	 *   done, with the way back of the run and the commands of the way by
	 *   hand in the answer, as the mark may be closed and its folder go a
	 *   day later.
	 * - Else the short answer: what happened in this call ($why), the way
	 *   back of the run with its call, the instruction of the mark on the
	 *   server for where the site answers no more (its first lines tell
	 *   which of its commands fits, or that none does and what to do
	 *   then), and the paths of the target, the
	 *   backup and that instruction. No sentence on what lies at the target
	 *   or in the backup or on the state of the mark, no command, and
	 *   nothing is read for the answer: who puts back reads it, the way
	 *   back of the run and the commands of the instruction, each before it
	 *   moves anything.
	 *
	 * @param array  $in    The arguments.
	 * @param array  $plan  plan_of().
	 * @param array  $moved rename_marked(): the journal of the step, the mark and the file of its instruction.
	 * @param string $why   What happened in this call (one or more whole sentences).
	 * @param bool   $whole Whether both versions were proved in this call, as close() said it; false where it was not reached.
	 * @return array|WP_Error
	 */
	private static function unclear( array $in, array $plan, array $moved, $why, $whole = false ) {
		if ( true !== $whole ) {
			$by_hand = array();
			$message = implode(
				"\n",
				array(
					/* translators: %s: what happened (one or more whole sentences). */
					sprintf( __( '%s The step is unclear.', 'alphabridge-mcp' ), $why ),
					/* translators: %d: run number. */
					sprintf( __( '1. To go back, call wp_site_revert action=plan with run_id %d.', 'alphabridge-mcp' ), $in['run_id'] ),
					/* translators: %s: the instruction of the swap on the server (absolute path of a file). */
					sprintf( __( '2. Only where this site answers no request any more: in a shell on the server, open the file %s and run the command its first lines point to. The command contains checks; where it does not print OK, stop.', 'alphabridge-mcp' ), $moved['guide'] ),
					/* translators: %s: the folder or file of the unit (absolute path). */
					sprintf( __( 'Target: %s', 'alphabridge-mcp' ), $plan['target'] ),
					/* translators: %s: the place of the backup of the old version (absolute path). */
					sprintf( __( 'Backup: %s', 'alphabridge-mcp' ), $plan['old']['path'] ),
				)
			);
		} else {
			$by_hand = self::by_hand( $in, $plan );
			/* translators: 1: what happened (one or more whole sentences), 2: run number. */
			$message = sprintf( __( '%1$s The step is unclear. The way back of this run puts the old version back (wp_site_revert action=plan with run_id %2$d). Where this site answers no request any more, put it back by hand in a shell on the server, as the lines below say:', 'alphabridge-mcp' ), $why, $in['run_id'] ) . "\n" . implode( "\n", $by_hand );
		}
		return self::ended(
			$in,
			'unclear',
			$moved['journal'],
			array(
				'message' => $message,
				'target'  => $plan['target'],
				'backup'  => $plan['old']['path'],
				'mark'    => $moved['mid'],
				'guide'   => $moved['guide'],
				'by_hand' => $by_hand,
			)
		);
	}

	/**
	 * The step of the swap ended, from begun: its state, journal and result
	 * in one write (the journal through the seam). Where that write fails,
	 * the refusal says that the end could not be recorded and names the
	 * status of the run and the ways on from there; what the step holds
	 * then is not read here, so the refusal claims no state of it.
	 *
	 * @param array  $in      The arguments.
	 * @param string $state   done, failed or unclear.
	 * @param array  $journal The journal.
	 * @param array  $result  The result, with its message.
	 * @return array|WP_Error next.
	 */
	private static function ended( array $in, $state, array $journal, array $result ) {
		$saved = self::calls()->journal(
			$in['run_id'],
			$in['unit']['seq'],
			array(
				'state'  => $state,
				'parts'  => $journal,
				'result' => $result,
			),
			'begun'
		);
		if ( true !== $saved ) {
			/* translators: 1: what the step did (one or more whole sentences), 2: step number, 3: the error of the database, or nothing. */
			return self::refusal( 'ab_mcp_sync_run_journal', sprintf( __( '%1$s The end of step %2$d could not be recorded (%3$s). Ask for the status of the run (wp_site_push action=status): where it shows the run interrupted, wp_site_revert action=plan with this run_id goes on from there, or a person closes it as checked on the page AlphaBridge → Staging.', 'alphabridge-mcp' ), $result['message'], $in['unit']['seq'], is_wp_error( $saved ) ? $saved->get_error_message() : '-' ) );
		}
		AB_MCP_Sync_Run::touch( $in['run_id'] );
		return array( 'next' => $result['message'] );
	}

	/**
	 * The commands of the way by hand after both renames (the new version
	 * at the target, the old one in the backup): the section
	 * [nach-den-umbenennungen] of the instruction of the mark
	 * (AB_MCP_Protected_Store::guide(), the one place that writes them),
	 * made from the same trees, so that the answer carries them after the
	 * mark is closed and its folder gone (a day later,
	 * AB_MCP_Protected_Store::cleanup()). Only for an answer whose proofs
	 * are whole (close()): the swap done, or unclear after both proofs.
	 *
	 * @param array $in   The arguments.
	 * @param array $plan plan_of().
	 * @return string[] Its lines (what to check, then the command); none where the store is not decided.
	 */
	private static function by_hand( array $in, array $plan ) {
		$root = AB_MCP_Protected_Store::root();
		if ( is_wp_error( $root ) ) {
			return array();
		}
		$trees = array();
		foreach ( self::mark_of( $in, $plan )['trees'] as $tree ) {
			$tree['path'] = 0 === strpos( $tree['path'], $root . '/' ) ? substr( $tree['path'], strlen( $root ) + 1 ) : $tree['path'];
			$trees[]      = $tree;
		}
		$lines = explode(
			"\n",
			AB_MCP_Protected_Store::guide(
				array(
					'store'  => $root,
					'begun'  => 0,
					'trees'  => $trees,
					'active' => array(),
				) + self::mark_of( $in, $plan )
			)
		);
		$at    = array_search( '[nach-den-umbenennungen]', $lines, true );
		$end   = count( $lines );
		$out   = array();
		for ( $i = false === $at ? $end : $at + 1; $i < $end && '' !== $lines[ $i ]; $i++ ) {
			$out[] = $lines[ $i ];
		}
		return $out;
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
			return self::refusal( 'code:means', sprintf( __( 'This call has %3$s seconds left, and the swap of the %1$s %2$s needs %4$s from here (a margin of twice the test requests before it, which took %5$s seconds, and a reserve, kept for the swap), so it is not swapped in this call. Call action=next again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time), or update the unit by hand in wp-admin.', 'alphabridge-mcp' ), $kind, $ref, number_format( max( 0, $until - $now ), 1, '.', '' ), number_format( $need, 1, '.', '' ), number_format( $took, 1, '.', '' ) ) );
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
