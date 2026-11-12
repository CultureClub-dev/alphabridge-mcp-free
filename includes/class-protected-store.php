<?php
/**
 * The protected store of the takeover (Z8, decisions 7, 8 and 20 of the plan
 * of 01.11.2026): where packages (out, in), reports and the backups of a run
 * lie, in a folder of this installation that no web request reaches.
 *
 * Where (decision 7), in this order:
 *
 *  1. dirname( ABSPATH )/ab-mcp-store/<install_id>/ ("outside"), when the
 *     resolved folder is writable and does not lie under the resolved
 *     document root of the web server. "Under" is decided by identity
 *     (device and inode along the chain of parents, holds()), not by the
 *     text of the paths, so neither a link nor another spelling of a path
 *     gets past it; the parents are checked before anything is created.
 *  2. Otherwise uploads/ab-mcp-store-<32 hex>/ ("uploads"), with .htaccess,
 *     index.php and web.config, used only after a self-check: three test
 *     files in it, asked without a login and past every cache through
 *     AB_MCP_Outbound (the way the site fetches any address), must each be
 *     answered with 403 or 404, and a fourth file beside it must be served
 *     with its content (otherwise the uploads are not served at the address
 *     WordPress names, and a 404 there would prove nothing). The check is
 *     made again after RECHECK seconds and whenever one of the guard files
 *     is missing (it is written again first); a failed check is made again
 *     after RETRY seconds.
 *  3. Otherwise there is no store: proof() names why and the way, and
 *     nothing is stored (no export, no import, no run).
 *
 * The place chosen is bound (the option OPTION_PROOF): the next requests use
 * it while it can be used, so get() and the budgets never look into another
 * folder than the one that holds the groups. Another place is taken only
 * when the bound one cannot be used any more.
 *
 * The record of the proof is versioned (rev) and read from the table every
 * time, never from a cache: the place bound and, for each place, the
 * youngest success (when its check began and ended) and the youngest
 * failure (when it ended). A place is proved only by a success whose check
 * began after the last failure there ended (usable()); a tie counts as not
 * protected. Every change is a new version stored only where the stored
 * one is still the one read (swap(), a conditional UPDATE by its bytes);
 * otherwise it is read again and what this request observed is merged into
 * it (decide(), merged()): a failure observed is never lost (where the
 * conditional attempts are spent, it is stored without a condition,
 * revoke()), and a success older than a failure another request saw is
 * never stored. A change is stored under the lock
 * of the place bound so far, and every writer checks the record afresh
 * under the lock of its place (writing(), admitted()): the place is the
 * bound one and its proof is valid (valid()). Readers check it too. The
 * proof a request keeps (proof()) is a hint where to look, never the
 * release.
 *
 * Only a request that knows the document root (the web server sets
 * DOCUMENT_ROOT) chooses the place. A request without it (WP-CLI, a cron job
 * from the shell) uses a proof a web request made in the last RECHECK
 * seconds, or none: so it never picks another place than the web does.
 *
 * Whose (decision 7, finding R2-3): install_id is a random id in an option of
 * its own, without autoload, made once with INSERT IGNORE, so two first
 * requests agree on it (add_option() would let the second overwrite it). The
 * folder holds an ownership manifest, owner.json: the id, the resolved
 * ABSPATH, a hash of DB_NAME and the table prefix, and the address of the
 * site (compared without its scheme, comparable()). Use, clean-up and
 * uninstall work only in a folder whose manifest is
 * this installation's, so two installations side by side (two sites in one
 * parent folder, a copy a host made of the files and the database) each see
 * and clean only their own. A folder at the place of this id whose manifest
 * is another's means this installation is a copy that kept the id: it gets a
 * new id (a conditional UPDATE, so two requests agree again) and a folder of
 * its own, and the other folder is left as it is.
 *
 * What (decisions 7 and 20): four areas, out and in (packages), reports and
 * backups. A group is one package, one report or the backups of one run: a
 * sidecar <area>/<G>.json and a folder <area>/<G>/ with its files, where G is
 * derived from the group's id and the installation's id
 * (AB_MCP_Payload_File::name()), so an id seen in an answer names no path. A
 * file is written once, through a temp file renamed into place, compressed,
 * and read only against the SHA-256 put() returned (AB_MCP_Payload_File).
 * Every read, write and removal goes through way(): from the folder of the
 * store to the file no step is a link (lstat()), and the end lies under the
 * folder by device and inode, so neither a linked area nor a linked group
 * reaches outside it. What a package holds is counted from the files that
 * are there as well (held()), so a file left by a request that died before
 * it was counted still counts.
 * Packages and reports are kept a day; a group bound to a run that is not
 * closed is not cleaned while it is not (AB_MCP_Sync_Schema::open_runs()).
 * The backups of a run follow the retention contract of decision 20
 * (AB_MCP_Sync_Schema::backup_rows(): the last three qualified closes
 * without an age, every closed run until the keep_until its close stored
 * from ab_mcp_staging_backup_days (BACKUP_DAYS, 0 in Free; Pro sets 30:
 * a qualified close 30 days, one that does not qualify 7 days in Free and
 * 30 in Pro, the period staying with the run when the edition changes),
 * every run a way back that is not closed needs), never pushed out by the
 * undo store; the backups of a run without a row are kept by their age as
 * a close that does not qualify (kept(), keep()). The clean-up runs when a
 * group is opened and once a day (CRON).
 *
 * How much (decision 8): AB_MCP_Store_Budget, counted under locked(), a
 * flock() on a file of the folder (the pattern of Pro's upload sessions).
 *
 * TREES (Z8b, plan E4, Z7; branch 3): a folder or file of code, a unit, in a
 * group of the area stage (what a run puts in place) or backups (the old
 * version). put_tree() is the one way a tree comes into the store: never
 * over another, never renamed or changed in the store, only where the
 * store and the folder the tree stands for are on one device
 * (code:store_device), and with a file that configures the web server or
 * PHP (.htaccess, .user.ini, php.ini, web.config) only beside WordPress;
 * in the uploads only without such a file and only where the self-check
 * found a PHP file below stage and below backups answered 403 or 404,
 * not run (code:store_web). What went in comes back byte for byte (tree():
 * its manifest, AB_MCP_Sync_File_Manifest::unit(), the hash put_tree()
 * gave). A tree lives as long as its group (kept(): trees count as rows).
 *
 * MARKS (plan E6, the part of branch 3): a swap writes before its first
 * rename a mark (put_mark(): the unit, its folder, the trees with device,
 * inode and hash, the main files active at the beginning) and beside it an
 * instruction WIEDERHERSTELLEN.txt with the commands that put the old state
 * back by hand at each point of an abort (guide()); both are read back.
 *
 * THE ONE REMOVER (Z37): nothing in the store is removed but through
 * delete_path(), which reads the marks first and leaves what an open mark
 * names, every folder above it, the mark and its instruction; while a mark
 * cannot be read it removes nothing in stage, backups and marks. Every
 * file of the store is written by write_file(), through a temp file of
 * temp_file() in the area tmp/ with the prefix TMP_PREFIX, renamed into
 * place only where tmp/ and the folder of the file report one device; a
 * temp file goes only where it is this store's own (made by this request,
 * or old with its prefix in tmp/), never by its ending. The store is made
 * in one step from a building folder beside it with that prefix (ensure()).
 * AB_MCP_Payload_File::write() writes only outside the store (the undo
 * store, the control file of the self-check).
 *
 * THE CALLS TO THE OUTSIDE of trees, the remover, the writer and the
 * self-check (plan 8.3, branch 3) go through AB_MCP_Protected_Store_Calls,
 * held by the seam of this class; only the tests put another object there.
 * No filter and no option reaches it.
 *
 * Messages are English, as the other classes of the takeover; each names
 * the way. The ones of Z8b (trees, places, the instruction) carry the text
 * domain; their translations come with text/z8b.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-payload-file.php';
require_once __DIR__ . '/class-staging.php';
require_once __DIR__ . '/class-store-budget.php';
require_once __DIR__ . '/sync/class-sync-schema.php';
require_once __DIR__ . '/sync/class-sync-file-manifest.php';

/**
 * The calls of the protected store to the outside (Z8b branch 3, plan 8.3):
 * the real ones. Writing and reading a tree, folders, the device of a
 * folder, the temp files, every removal and the requests of the self-check.
 */
final class AB_MCP_Protected_Store_Calls {

	/**
	 * What lstat() says of a path (a link is not followed).
	 *
	 * @param string $path Path.
	 * @return array|false
	 */
	public function lstat( $path ) {
		clearstatcache( true, (string) $path );
		$stat = @lstat( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone or not readable: false.
		return is_array( $stat ) ? $stat : false;
	}

	/**
	 * What stat() says of a folder: its device.
	 *
	 * @param string $path Path.
	 * @return array|false
	 */
	public function stat( $path ) {
		clearstatcache( true, (string) $path );
		$stat = @stat( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone or not readable: false.
		return is_array( $stat ) ? $stat : false;
	}

	/**
	 * The names in a folder.
	 *
	 * @param string $dir Folder.
	 * @return string[]|false
	 */
	public function scandir( $dir ) {
		$names = @scandir( (string) $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder that cannot be read: false.
		return is_array( $names ) ? array_map( 'strval', $names ) : false;
	}

	/**
	 * Make a folder.
	 *
	 * @param string $path Folder.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	public function mkdir( $path, $mode ) {
		return @mkdir( (string) $path, (int) $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}

	/**
	 * Remove an empty folder (delete_path() only).
	 *
	 * @param string $path Folder.
	 * @return bool
	 */
	public function rmdir( $path ) {
		return @rmdir( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Remove a file or a link (delete_path() only).
	 *
	 * @param string $path File.
	 * @return bool
	 */
	public function unlink( $path ) {
		return @unlink( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Rename a path.
	 *
	 * @param string $from Path.
	 * @param string $to   Path.
	 * @return bool
	 */
	public function rename( $from, $to ) {
		return @rename( (string) $from, (string) $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Set the mode of a path.
	 *
	 * @param string $path Path.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	public function chmod( $path, $mode ) {
		return @chmod( (string) $path, (int) $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	}

	/**
	 * Make an empty file that is not there yet (fopen 'x').
	 *
	 * @param string $path File.
	 * @return bool
	 */
	public function create( $path ) {
		$handle = @fopen( (string) $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return false;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * Write bytes into a file (a temp file of write_file()).
	 *
	 * @param string $path  File.
	 * @param string $bytes Bytes.
	 * @return int|false Bytes written; false where fewer (a full disk) or none.
	 */
	public function put( $path, $bytes ) {
		return @file_put_contents( (string) $path, (string) $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Copy the bytes of a file into a file (a temp file of write_file()), a
	 * piece at a time.
	 *
	 * @param string $from File read.
	 * @param string $to   File written.
	 * @return int|false Bytes copied.
	 */
	public function copy_into( $from, $to ) {
		$in = @fopen( (string) $from, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $in ) {
			return false;
		}
		$out = @fopen( (string) $to, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$copied = @stream_copy_to_stream( $in, $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$closed = fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return ( false === $copied || ! $closed ) ? false : (int) $copied;
	}

	/**
	 * Read a small file whole (a mark, an instruction).
	 *
	 * @param string $path File.
	 * @param int    $max  Bytes at most.
	 * @return string|false
	 */
	public function read( $path, $max ) {
		$stat = $this->lstat( $path );
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] > (int) $max ) {
			return false;
		}
		return @file_get_contents( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * One request of the self-check.
	 *
	 * @param string      $url     Address.
	 * @param array       $args    Arguments of the request.
	 * @param object|null $fetcher An object whose get( $url, $args ) asks an
	 *                             address; null for AB_MCP_Outbound.
	 * @return array|WP_Error
	 */
	public function ask( $url, array $args, $fetcher ) {
		return is_object( $fetcher ) ? $fetcher->get( $url, $args ) : AB_MCP_Outbound::get( $url, $args );
	}
}

/**
 * Class AB_MCP_Protected_Store
 */
final class AB_MCP_Protected_Store {

	/** The option of the installation's id (32 hex, no autoload). */
	const OPTION_ID = 'ab_mcp_install_id';

	/** The option of the last proof: where, when, and with what result. */
	const OPTION_PROOF = 'ab_mcp_store_proof';

	/** The daily WP-Cron event of the clean-up. */
	const CRON = 'ab_mcp_store_cleanup';

	/** Days the backups of a run are kept, and its filter (Pro sets 30). */
	const BACKUP_DAYS        = 0;
	const BACKUP_DAYS_FILTER = 'ab_mcp_staging_backup_days';

	/** The format of the manifest and of the sidecars. */
	const FORMAT = 'ab-mcp-store/1';

	/** The parent folder beside the WordPress folder. */
	const PARENT = 'ab-mcp-store';

	/** The folder in the uploads: this and 32 hex. */
	const UPLOADS_PREFIX = 'ab-mcp-store-';

	/** The ownership manifest in the folder. */
	const OWNER = 'owner.json';

	/** The file of the lock, in the folder. */
	const LOCK_FILE = 'store.lock';

	/** The areas of groups: packages, reports, backups and the stage of trees (Z8b, E4). */
	const AREAS = array( 'out', 'in', 'reports', 'backups', 'stage' );

	/** The areas whose groups hold trees (E4): the stage of a run and the old versions. */
	const TREE_AREAS = array( 'stage', 'backups' );

	/** The areas of packages, which the budgets count. */
	const PACKAGES = array( 'out', 'in' );

	/** The area of the temp files of the store (Z37): only temp_file() makes one there. */
	const TMP = 'tmp';

	/** The beginning of the name of every temp file and of the building folder (Z37). */
	const TMP_PREFIX = '.ab-tmp-';

	/** The folder of the marks of a swap, one folder per mark (E6). */
	const MARKS = 'marks';

	/** The mark in the folder of a mark. */
	const MARK_FILE = 'mark.json';

	/** The instruction beside it, for the way back by hand (E6). */
	const GUIDE_FILE = 'WIEDERHERSTELLEN.txt';

	/** The format of a mark. */
	const MARK_FORMAT = 'ab-mcp-mark/1';

	/** The bytes of a mark and of an instruction at most. */
	const MARK_MAX = 65536;

	/** The roles of a tree a mark names: the old version, the new one. */
	const MARK_ROLES = array( 'backup', 'stage' );

	/**
	 * Files that change how the web server or PHP serve the folder they lie
	 * in and every folder below (E4; Pro's AB_MCP_Pro_Deploy_Guard::
	 * CONFIG_NAMES), compared by name in lower case.
	 */
	const SERVER_CONFIG = array( '.htaccess', '.user.ini', 'php.ini', 'web.config' );

	/** The areas below which the self-check of the uploads asks a PHP file in a folder (E4). */
	const PHP_PROBES = array( 'stage', 'backups' );

	/** Seconds the reading of a tree may take (as a unit of the environment, AB_MCP_Sync_Env::UNIT_BUDGET). */
	const TREE_BUDGET = 45;

	/** Seconds a group is kept, by area; backups by backup_days(); a closed mark. */
	const KEEP = array(
		'out'     => 86400,
		'in'      => 86400,
		'reports' => 86400,
		'stage'   => 86400,
		'marks'   => 86400,
	);

	/** Seconds after which a proof is made again. */
	const RECHECK = 86400;

	/** Seconds after which a failed self-check is made again. */
	const RETRY = 900;

	/** Seconds each request of the self-check may take. */
	const PROBE_TIMEOUT = 5;

	/** Raw bytes of one file at most (16 MiB): larger payloads go in parts. */
	const FILE_MAX = 16777216;

	/** Seconds after which a temp file left by an interrupted write goes. */
	const TMP_AGE = 3600;

	/**
	 * The guard files in the folder: Apache and LiteSpeed read .htaccess (2.2
	 * and 2.4), IIS web.config, and index.php answers a request for the
	 * folder itself. Whether the server honours them only the self-check
	 * shows.
	 */
	const GUARDS = array(
		'.htaccess'  => "# AlphaBridge MCP: this folder is never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		'index.php'  => "<?php // Silence is golden.\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
	);

	/**
	 * The three test files of the self-check: where in the folder and with
	 * which extension, as the store writes its files (manifest and sidecars
	 * as .json, packages with their names, backups without an extension).
	 */
	const PROBES = array(
		array( '', '.json' ),
		array( 'out', '.bin' ),
		array( 'backups', '' ),
	);

	/**
	 * What each finding means, beside WordPress ("outside") and in the
	 * uploads, for the message of proof(): one sentence each, in the
	 * language of the site.
	 *
	 * @return array<string,array<string,string>> Place => finding => sentence.
	 */
	public static function findings() {
		return array(
			'outside' => array(
				'docroot'      => __( 'Beside WordPress: the folder ab-mcp-store beside the WordPress folder would lie inside the web root.', 'alphabridge-mcp' ),
				'not_writable' => __( 'Beside WordPress: the folder ab-mcp-store beside the WordPress folder cannot be created or written, or a part of it is a link.', 'alphabridge-mcp' ),
				'foreign'      => __( 'Beside WordPress: the folder for this installation there belongs to another installation.', 'alphabridge-mcp' ),
			),
			'uploads' => array(
				'no_uploads'       => __( 'In the uploads folder: there is no uploads folder.', 'alphabridge-mcp' ),
				'not_writable'     => __( 'In the uploads folder: the folder ab-mcp-store-… in it cannot be created or written, or a part of it is a link.', 'alphabridge-mcp' ),
				'foreign'          => __( 'In the uploads folder: the folder for this installation there belongs to another installation.', 'alphabridge-mcp' ),
				'self_unreachable' => __( 'In the uploads folder: this site cannot fetch its own files to check them (its address is local or private, or the request failed).', 'alphabridge-mcp' ),
				'control'          => __( 'In the uploads folder: a test file beside the folder was not served at the address of the uploads folder, so the check would prove nothing.', 'alphabridge-mcp' ),
				'served'           => __( 'In the uploads folder: a test file in the folder was served: the web server does not honour its .htaccess or web.config.', 'alphabridge-mcp' ),
			),
		);
	}

	/** @var string|null The id read in this request. */
	private static $id = null;

	/** @var array|null The proof of this request. */
	private static $proof = null;

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_protected_store = null;

	/**
	 * @var array<string,true> What this request made and noted (temp files,
	 *                         the building folder, the files of the
	 *                         self-check, a tree being copied): it may
	 *                         remove them (delete_path()).
	 */
	private static $noted = array();

	/** @var array<string,true> The trees put_tree() copies right now: only into them write_file() writes below a tree. */
	private static $building = array();

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Protected_Store_Calls
	 */
	private static function calls() {
		return self::$naht_protected_store ?? new AB_MCP_Protected_Store_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function real_calls() {
		self::$naht_protected_store = null;
	}

	/* ------------------------------------------------------- place and proof */

	/**
	 * Whether the store can be used, as this request found it: a hint for a
	 * caller (no export, no import, no run without it), never the release
	 * of a write, which every writer and reader checks afresh (admitted()).
	 *
	 * @param object|null $fetcher See proof().
	 * @return bool
	 */
	public static function available( $fetcher = null ) {
		return self::proof( $fetcher )['available'];
	}

	/**
	 * Where the store is and why it is there, or why there is none (decision
	 * 7). Decided once per request; with a fetcher or force, anew.
	 *
	 * @param object|null $fetcher Object whose get( $url, $args ) asks an
	 *                             address as AB_MCP_Outbound::get() does;
	 *                             null for AB_MCP_Outbound itself.
	 * @param bool        $force   Make the self-check now, whatever the
	 *                             record says.
	 * @return array{available:bool,where:string|null,checked:int,reason:string|null,findings:array<string,string>,detail:array,php:bool|null,message:string|null}
	 *         where 'outside' or 'uploads'; reason (without a store) 'no_id',
	 *         'no_document_root' or 'none' with a finding per place
	 *         (findings()); detail the statuses or the error code of the
	 *         last self-check; php in the uploads whether that check found
	 *         a PHP file below stage and backups refused without running it
	 *         (null where it did not ask, and beside WordPress; E4); message
	 *         the reason and the way.
	 */
	public static function proof( $fetcher = null, $force = false ) {
		if ( null !== self::$proof && null === $fetcher && ! $force ) {
			return self::$proof;
		}
		self::$proof = self::decide( $fetcher, (bool) $force, true );
		return self::$proof;
	}

	/**
	 * The refusal while there is no store: the reason and the way.
	 *
	 * @return WP_Error
	 */
	public static function refusal() {
		$proof = self::proof();
		return new WP_Error(
			'ab_mcp_store_unavailable',
			(string) $proof['message'],
			array(
				'reason'   => $proof['reason'],
				'findings' => $proof['findings'],
			)
		);
	}

	/**
	 * The folder of the store, when there is one.
	 *
	 * @return string|WP_Error
	 */
	public static function root() {
		$proof = self::proof();
		if ( ! $proof['available'] ) {
			return self::refusal();
		}
		return self::candidates( self::install_id() )[ $proof['where'] ];
	}

	/**
	 * The id of this installation, made where there is none.
	 *
	 * @param bool $make Make it where there is none.
	 * @return string 32 hex, or '' where it cannot be stored (or $make is false and there is none).
	 */
	public static function install_id( $make = true ) {
		if ( null !== self::$id && '' !== self::$id ) {
			return self::$id;
		}
		$raw = self::read_id();
		if ( null === $raw && $make ) {
			self::insert_id( self::new_id() );
			$raw = self::read_id();
		} elseif ( null !== $raw && ! self::valid_id( $raw ) && $make ) {
			self::replace_id( $raw );
			$raw = self::read_id();
		}
		self::$id = ( null !== $raw && self::valid_id( $raw ) ) ? $raw : '';
		return self::$id;
	}

	/**
	 * Forget what this request read (after a change of the option or the
	 * folders in this request).
	 */
	public static function forget() {
		self::$id    = null;
		self::$proof = null;
	}

	/**
	 * What the ownership manifest of this installation holds for an id.
	 *
	 * @param string $id Id.
	 * @return array<string,string>
	 */
	public static function identity( $id ) {
		global $wpdb;
		$abspath = realpath( ABSPATH );
		return array(
			'format'     => self::FORMAT,
			'install_id' => (string) $id,
			'abspath'    => false === $abspath ? untrailingslashit( ABSPATH ) : $abspath,
			'database'   => hash( 'sha256', ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . ( isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '' ) ),
			'address'    => AB_MCP_Staging::normalize_url( home_url() ),
		);
	}

	/**
	 * Whether a folder is this installation's: a folder, no link, with an
	 * ownership manifest whose every field is this installation's.
	 *
	 * @param string $dir Folder.
	 * @param string $id  Id of this installation.
	 * @return bool
	 */
	public static function owns( $dir, $id ) {
		$file = $dir . '/' . self::OWNER;
		if ( '' === (string) $id || is_link( $dir ) || ! is_dir( $dir ) || is_link( $file ) || ! is_file( $file ) || @filesize( $file ) > 4096 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		$found = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $found ) ) {
			return false;
		}
		foreach ( self::identity( $id ) as $key => $value ) {
			if ( ! isset( $found[ $key ] ) || ! is_string( $found[ $key ] ) || ! hash_equals( self::comparable( $key, $value ), self::comparable( $key, $found[ $key ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A field of the manifest as owns() compares it. The address without its
	 * scheme: a site that moves from http to https is the same installation
	 * (host, port and path still count), and its store must not become a
	 * stranger's folder; another host or path is another installation.
	 *
	 * @param string $key   Field.
	 * @param string $value Value.
	 * @return string
	 */
	private static function comparable( $key, $value ) {
		return 'address' === $key ? (string) preg_replace( '#^https?://#', '', $value ) : $value;
	}

	/**
	 * Whether a folder holds a path: the path is the folder or lies below it,
	 * by device and inode along the resolved chain of the path's parents (so
	 * a link or another spelling of a path is seen through). False when
	 * either cannot be read.
	 *
	 * @param string $dir  Folder.
	 * @param string $path Path.
	 * @return bool
	 */
	public static function holds( $dir, $path ) {
		$target = @stat( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$at     = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $target || false === $at ) {
			return false;
		}
		while ( true ) {
			$here = @stat( $at ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $here && $here['dev'] === $target['dev'] && $here['ino'] === $target['ino'] ) {
				return true;
			}
			$up = dirname( $at );
			if ( $up === $at ) {
				return false;
			}
			$at = $up;
		}
	}

	/**
	 * Whether a proof must be made anew: none for this place, older than
	 * RECHECK, or a guard file missing. A failed one is kept RETRY seconds.
	 *
	 * @param array|null $record  The record of the last proof (record()).
	 * @param string     $where   'outside' or 'uploads'.
	 * @param int        $now     Time.
	 * @param bool       $guarded Whether every guard file is there.
	 * @return bool
	 */
	public static function needs_check( $record, $where, $now, $guarded ) {
		if ( ! $guarded || ! is_array( $record ) || $where !== $record['where'] ) {
			return true;
		}
		return ( $now - (int) $record['checked'] ) >= ( $record['ok'] ? self::RECHECK : self::RETRY );
	}

	/**
	 * What the answers of the self-check prove: the control file served with
	 * its content, each test file answered 403 or 404 without its content.
	 * Where those prove the folder, and the PHP files below stage and backups
	 * were asked (E4), the detail says php: true only where each of them was
	 * answered 403 or 404 without its content and without what it prints
	 * when it runs (both hold the content), false otherwise. The folder is
	 * proved for packages and rows either way; a tree goes into it only with
	 * php true (put_tree()).
	 *
	 * @param array|WP_Error   $control The answer for the file beside the folder.
	 * @param array[]|WP_Error $probes  The answers for the three test files.
	 * @param string           $token   The content of the files.
	 * @param array[]|null     $php     The answers for the PHP files below
	 *                                  stage and backups; null where not asked.
	 * @return array{ok:bool,reason:string|null,detail:array}
	 */
	public static function judge( $control, array $probes, $token, $php = null ) {
		if ( is_wp_error( $control ) ) {
			return array(
				'ok'     => false,
				'reason' => 'self_unreachable',
				'detail' => array( 'error' => $control->get_error_code() ),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $control );
		if ( 200 !== $status || (string) $token !== (string) wp_remote_retrieve_body( $control ) ) {
			return array(
				'ok'     => false,
				'reason' => 'control',
				'detail' => array( 'control' => $status ),
			);
		}
		$statuses = array();
		$served   = false;
		foreach ( $probes as $answer ) {
			if ( is_wp_error( $answer ) ) {
				return array(
					'ok'     => false,
					'reason' => 'self_unreachable',
					'detail' => array( 'error' => $answer->get_error_code() ),
				);
			}
			$code       = (int) wp_remote_retrieve_response_code( $answer );
			$statuses[] = $code;
			if ( ! in_array( $code, array( 403, 404 ), true ) || false !== strpos( (string) wp_remote_retrieve_body( $answer ), (string) $token ) ) {
				$served = true;
			}
		}
		if ( $served || 3 !== count( $statuses ) ) {
			return array(
				'ok'     => false,
				'reason' => 'served',
				'detail' => array( 'probes' => $statuses ),
			);
		}
		$detail = array( 'probes' => $statuses );
		if ( is_array( $php ) ) {
			$answered = array();
			$refused  = count( self::PHP_PROBES ) === count( $php );
			foreach ( $php as $answer ) {
				if ( is_wp_error( $answer ) ) {
					$answered[] = $answer->get_error_code();
					$refused    = false;
					continue;
				}
				$code       = (int) wp_remote_retrieve_response_code( $answer );
				$answered[] = $code;
				if ( ! in_array( $code, array( 403, 404 ), true ) || false !== strpos( (string) wp_remote_retrieve_body( $answer ), (string) $token ) ) {
					$refused = false;
				}
			}
			$detail['php']        = $refused;
			$detail['php_probes'] = $answered;
		}
		return array(
			'ok'     => true,
			'reason' => null,
			'detail' => $detail,
		);
	}

	/* -------------------------------------------------------------- groups */

	/**
	 * Open a group: a package (out, in), a report or the backups of a run.
	 * A package is counted against the budgets under the lock and needs the
	 * bytes it will hold ('declared'). The clean-up runs first, under the
	 * same lock.
	 *
	 * @param string $area Area.
	 * @param array  $meta 'declared' (bytes, packages), 'run_id' (the run it
	 *                     belongs to), 'label' (up to 191 of A-Z a-z 0-9 . _ : -),
	 *                     'binding' (the trust a package was begun with, as
	 *                     the print of a pair; one of another binding counts
	 *                     as open no more, AB_MCP_Store_Budget::package_refusal()).
	 * @return string|WP_Error The id of the group (32 hex).
	 */
	public static function open( $area, array $meta = array() ) {
		$bad = self::bad_area( $area );
		if ( null !== $bad ) {
			return $bad;
		}
		$label = isset( $meta['label'] ) ? (string) $meta['label'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{0,191}\z/', $label ) ) {
			return new WP_Error( 'ab_mcp_store_label', 'The label of a group holds up to 191 of the characters A-Z, a-z, 0-9, ".", "_", ":" and "-". Pass one of those, or none.' );
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$entry = array(
			'format'   => self::FORMAT,
			'gid'      => bin2hex( AB_MCP_Payload_File::random_bytes( 16 ) ),
			'area'     => (string) $area,
			'created'  => time(),
			'user_id'  => (int) get_current_user_id(),
			'run_id'   => isset( $meta['run_id'] ) ? max( 0, (int) $meta['run_id'] ) : 0,
			'declared' => isset( $meta['declared'] ) ? max( 0, (int) $meta['declared'] ) : 0,
			'used'     => 0,
			'label'    => $label,
			'closed'   => false,
			'binding'  => self::binding( isset( $meta['binding'] ) ? $meta['binding'] : '' ),
		);
		$id     = self::install_id();
		$result = self::writing(
			$root,
			static function () use ( $root, $id, $entry ) {
				self::sweep( $root, $id, time() );
				if ( in_array( $entry['area'], self::PACKAGES, true ) ) {
					$refusal = AB_MCP_Store_Budget::package_refusal( array_merge( self::entries( $root, $id, 'out' ), self::entries( $root, $id, 'in' ) ), $entry['area'], $entry['user_id'], $entry['declared'], $entry['label'], $entry['binding'] );
					if ( null !== $refusal ) {
						return $refusal;
					}
				}
				$area_dir = self::way( $root, array( $entry['area'] ) );
				if ( null === $area_dir ) {
					return self::unsafe();
				}
				$name = self::group_name( $entry['area'], $entry['gid'], $id );
				$side = $area_dir . '/' . $name . '.json';
				// The sidecar first: a folder without one is an orphan the
				// clean-up removes, a sidecar without a folder is an empty group.
				if ( true !== self::write_entry( $root, $side, $entry ) || ! self::calls()->mkdir( $area_dir . '/' . $name, 0700 ) ) {
					self::delete_path( $root, $side );
					return new WP_Error( 'ab_mcp_store_write', 'The group could not be made in the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				return $entry['gid'];
			}
		);
		if ( ! is_wp_error( $result ) ) {
			self::schedule();
		}
		return $result;
	}

	/**
	 * Write one file of a group, once: compressed, through a temp file
	 * renamed into place, under the lock. A package takes no more than it
	 * declared, counted as the larger of what its sidecar booked and what its
	 * files hold (held()): a file a request put in place before it died,
	 * without counting it, still counts.
	 *
	 * @param string $area    Area.
	 * @param string $gid     Group.
	 * @param string $name    File name: a-z, 0-9, ".", "_", "-", up to 64.
	 * @param string $payload Bytes.
	 * @return array{name:string,sha256:string,bytes:int,stored:int}|WP_Error
	 */
	public static function put( $area, $gid, $name, $payload ) {
		$bad = self::bad_ref( $area, $gid, $name );
		if ( null !== $bad ) {
			return $bad;
		}
		$payload = (string) $payload;
		if ( strlen( $payload ) > self::FILE_MAX ) {
			return new WP_Error( 'ab_mcp_store_too_large', sprintf( 'One file of the protected store holds at most %d bytes. Split it into parts and use a name of its own for each.', self::FILE_MAX ) );
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$id = self::install_id();
		return self::writing(
			$root,
			static function () use ( $root, $id, $area, $gid, $name, $payload ) {
				$found = self::group( $root, $area, self::group_name( $area, $gid, $id ), $gid );
				if ( ! is_array( $found ) ) {
					return $found;
				}
				list( $side, $dir, $entry ) = $found;
				$path = $dir . '/' . $name;
				if ( false !== @lstat( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return new WP_Error( 'ab_mcp_store_exists', 'This group holds a file of that name already, and a file of the protected store is written once. Use another name for it.' );
				}
				$bytes = strlen( $payload );
				$used  = max( $entry['used'], self::held( $dir ) );
				if ( in_array( $area, self::PACKAGES, true ) && $used + $bytes > $entry['declared'] ) {
					return new WP_Error( 'ab_mcp_store_over_declared', sprintf( 'The package was opened for %1$d bytes and holds %2$d; this file (%3$d bytes) would take it past them. Use a package opened with the size it needs.', $entry['declared'], $used, $bytes ) );
				}
				$blob    = AB_MCP_Payload_File::compress( $payload );
				$written = self::write_file( $root, $path, $blob, array( 'exclusive' => true ) );
				if ( true !== $written ) {
					return 'ab_mcp_store_device' === $written->get_error_code() ? $written : new WP_Error( 'ab_mcp_store_write', 'The file could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				$entry['used'] = $used + $bytes;
				if ( true !== self::write_entry( $root, $side, $entry ) ) {
					self::delete_path( $root, $path );
					return new WP_Error( 'ab_mcp_store_write', 'The file could not be recorded in the protected store, so it was taken out again. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				return array(
					'name'   => $name,
					'sha256' => hash( 'sha256', $payload ),
					'bytes'  => $bytes,
					'stored' => strlen( $blob ),
				);
			}
		);
	}

	/**
	 * Read one file of a group, verified against the SHA-256 put() returned.
	 *
	 * @param string $area   Area.
	 * @param string $gid    Group.
	 * @param string $name   File name.
	 * @param string $sha256 SHA-256 of the raw bytes.
	 * @return string|WP_Error
	 */
	public static function get( $area, $gid, $name, $sha256 ) {
		$bad = self::bad_ref( $area, $gid, $name );
		if ( null !== $bad ) {
			return $bad;
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = self::admitted( $root );
		if ( true !== $admitted ) {
			return $admitted;
		}
		$found = self::group( $root, $area, self::group_name( $area, $gid, self::install_id() ), $gid );
		if ( ! is_array( $found ) ) {
			return $found;
		}
		$path = $found[1] . '/' . $name;
		if ( false !== @lstat( $path ) && ! self::plain( $path, 'file' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return self::unsafe();
		}
		$raw = AB_MCP_Payload_File::read( $path, $sha256, self::FILE_MAX + 65536, self::FILE_MAX );
		if ( ! is_wp_error( $raw ) ) {
			return $raw;
		}
		switch ( $raw->get_error_code() ) {
			case 'missing':
				return new WP_Error( 'ab_mcp_store_missing', 'The file is not in the protected store: it was never written, or its group was removed (packages and reports are kept a day, backups as many days as the filter ab_mcp_staging_backup_days says). Make it again.' );
			case 'read':
				return new WP_Error( 'ab_mcp_store_read', 'The file of the protected store could not be read. Try again; if it keeps failing, ask the host to check the rights of the folder.' );
			default:
				return new WP_Error( 'ab_mcp_store_corrupt', 'The file of the protected store failed its check against its SHA-256, so it is not used. Make it again; if it keeps failing, ask the host whether something else writes into the folder.' );
		}
	}

	/**
	 * The groups of an area, oldest first.
	 *
	 * @param string $area Area.
	 * @return array[]|WP_Error Entries: gid, area, created, user_id, run_id, declared, used, label, closed, expires.
	 */
	public static function groups( $area ) {
		$bad = self::bad_area( $area );
		if ( null !== $bad ) {
			return $bad;
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = self::admitted( $root );
		if ( true !== $admitted ) {
			return $admitted;
		}
		if ( null === self::way( $root, array( $area ) ) ) {
			return self::unsafe();
		}
		$out = array();
		foreach ( self::entries( $root, self::install_id(), $area ) as $entry ) {
			unset( $entry['format'] );
			$entry['expires'] = $entry['created'] + self::keep( $area );
			$out[]            = $entry;
		}
		return $out;
	}

	/**
	 * The files of a group, by name, with their size on disk.
	 *
	 * @param string $area Area.
	 * @param string $gid  Group.
	 * @return array<string,int>|WP_Error
	 */
	public static function files( $area, $gid ) {
		$bad = self::bad_ref( $area, $gid, 'x' );
		if ( null !== $bad ) {
			return $bad;
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = self::admitted( $root );
		if ( true !== $admitted ) {
			return $admitted;
		}
		$found = self::group( $root, $area, self::group_name( $area, $gid, self::install_id() ), $gid );
		if ( ! is_array( $found ) ) {
			return $found;
		}
		$dir = $found[1];
		$out = array();
		foreach ( (array) scandir( $dir ) as $file ) {
			$file = (string) $file;
			if ( self::valid_name( $file ) && self::plain( $dir . '/' . $file, 'file' ) ) {
				$out[ $file ] = (int) filesize( $dir . '/' . $file );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Bind a group to a run: while the run is not closed, the clean-up keeps
	 * the group.
	 *
	 * @param string $area   Area.
	 * @param string $gid    Group.
	 * @param int    $run_id Run (0 to unbind).
	 * @return true|WP_Error
	 */
	public static function bind( $area, $gid, $run_id ) {
		$bad = self::bad_ref( $area, $gid, 'x' );
		if ( null !== $bad ) {
			return $bad;
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$id = self::install_id();
		return self::writing(
			$root,
			static function () use ( $root, $id, $area, $gid, $run_id ) {
				$found = self::group( $root, $area, self::group_name( $area, $gid, $id ), $gid );
				if ( ! is_array( $found ) ) {
					return $found;
				}
				list( $side, , $entry ) = $found;
				$entry['run_id'] = max( 0, (int) $run_id );
				return true === self::write_entry( $root, $side, $entry ) ? true : new WP_Error( 'ab_mcp_store_write', 'The group could not be bound to its run in the protected store. Ask the host to check that the folder is writable, then try again.' );
			}
		);
	}

	/**
	 * Close a package: its use is done (an import checked whole), so it no
	 * longer counts as open for the budgets of packages and exports
	 * (AB_MCP_Store_Budget::package_refusal()); its bytes count until it is
	 * removed, a day after it was made.
	 *
	 * @param string $area Area.
	 * @param string $gid  Group.
	 * @return true|WP_Error
	 */
	public static function close( $area, $gid ) {
		$bad = self::bad_ref( $area, $gid, 'x' );
		if ( null !== $bad ) {
			return $bad;
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$id = self::install_id();
		return self::writing(
			$root,
			static function () use ( $root, $id, $area, $gid ) {
				$found = self::group( $root, $area, self::group_name( $area, $gid, $id ), $gid );
				if ( ! is_array( $found ) ) {
					return $found;
				}
				list( $side, , $entry ) = $found;
				$entry['closed'] = true;
				return true === self::write_entry( $root, $side, $entry ) ? true : new WP_Error( 'ab_mcp_store_write', 'The group could not be closed in the protected store. Ask the host to check that the folder is writable, then try again.' );
			}
		);
	}

	/**
	 * Remove a group with its files, in every folder of this installation,
	 * through delete_path() (Z37): what an open mark names stays, and with it
	 * the group's folder and its sidecar.
	 *
	 * @param string $area Area.
	 * @param string $gid  Group.
	 * @return bool Whether a group was removed whole.
	 */
	public static function remove( $area, $gid ) {
		if ( null !== self::bad_ref( $area, $gid, 'x' ) ) {
			return false;
		}
		$id      = self::install_id( false );
		$removed = false;
		foreach ( self::own_folders( $id ) as $root ) {
			$name    = self::group_name( $area, $gid, $id );
			$removed = self::locked(
				$root,
				static function () use ( $root, $area, $name, $gid ) {
					// Under the lock, the whole way checked: a linked area or
					// group is never followed.
					if ( ! is_array( self::group( $root, $area, $name, $gid ) ) ) {
						return false;
					}
					return self::delete_group( $root, $area, $name );
				}
			) === true || $removed;
		}
		return $removed;
	}

	/**
	 * Remove a group, its folder first and its sidecar last, the sidecar only
	 * where the folder went whole (delete_path()).
	 *
	 * @param string $root Folder of the store.
	 * @param string $area Area.
	 * @param string $name Name of the group on disk.
	 * @return bool Whether it is gone.
	 */
	private static function delete_group( $root, $area, $name ) {
		$dir = self::way( $root, array( $area ) );
		if ( null === $dir ) {
			return false;
		}
		return self::delete_path( $root, $dir . '/' . $name, array( 'with' => $dir . '/' . $name . '.json' ) );
	}

	/**
	 * The clean-up (also the daily CRON): in every folder of this installation,
	 * under its lock, the groups past their time and not bound to a run that
	 * is not closed, orphans and temp files of interrupted writes.
	 *
	 * @param int|null $now Time (for tests); now when null.
	 * @return array{removed:int,kept:int} Groups removed, groups kept for a run.
	 */
	public static function cleanup( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$id  = self::install_id( false );
		$sum = array(
			'removed' => 0,
			'kept'    => 0,
		);
		foreach ( self::own_folders( $id ) as $root ) {
			$done = self::locked(
				$root,
				static function () use ( $root, $id, $now ) {
					return self::sweep( $root, $id, $now );
				}
			);
			if ( is_array( $done ) ) {
				$sum['removed'] += $done['removed'];
				$sum['kept']    += $done['kept'];
			}
		}
		return $sum;
	}

	/**
	 * Whether the clean-up keeps a group: bound to a run that is not closed
	 * (or to any run while the runs cannot be read), or younger than its
	 * area keeps groups. The backups of a run whose row is there follow the
	 * retention contract as the row holds it (decision 20; AB_MCP_Sync_Schema
	 * ::backup_rows(): kept, or gone whatever their age; every run while
	 * the runs cannot be read). The backups of a run without a row (a run
	 * that was never made, a row gone with a database put back from an
	 * older copy) are kept by their age, as a close that does not qualify
	 * (keep()): a backup whose run cannot be read is not removed at once,
	 * fail closed.
	 *
	 * Trees count as rows (Z8, plan E4 and section 6): a tree lies in a
	 * group (stage or backups) and lives as long as its group, so a backup
	 * tree stays until the retention of its run actually lets it go (the
	 * rule of AB_MCP_Sync_Schema::backup_runs(), read here as backup_rows()),
	 * and a tree of the stage as long as its run is not closed, then a day.
	 * That an open mark keeps a tree beyond this is Z37 (delete_path()), not
	 * this function.
	 *
	 * @param array                $entry       Entry of the group.
	 * @param int                  $now         Time.
	 * @param int[]|null           $open_runs   AB_MCP_Sync_Schema::open_runs().
	 * @param array<int,bool>|null $backup_runs AB_MCP_Sync_Schema::backup_rows(); null where not read.
	 * @return bool
	 */
	public static function kept( array $entry, $now, $open_runs, $backup_runs = null ) {
		$run = isset( $entry['run_id'] ) ? (int) $entry['run_id'] : 0;
		if ( $run > 0 && ( null === $open_runs || in_array( $run, $open_runs, true ) ) ) {
			return true;
		}
		if ( 'backups' === (string) $entry['area'] && $run > 0 ) {
			if ( null === $backup_runs ) {
				return true;
			}
			if ( array_key_exists( $run, $backup_runs ) ) {
				return (bool) $backup_runs[ $run ];
			}
		}
		return ( (int) $now - (int) $entry['created'] ) < self::keep( (string) $entry['area'] );
	}

	/**
	 * Seconds an area keeps a group of no run, and a backup whose run has
	 * no row (kept()): a backup as a close that does not qualify,
	 * UNQUALIFIED_DAYS or the days of the filter where more (decision 20,
	 * rule 5, as AB_MCP_Sync_Schema::keep_until() stores them).
	 *
	 * @param string $area Area.
	 * @return int
	 */
	public static function keep( $area ) {
		return 'backups' === $area ? max( AB_MCP_Sync_Schema::UNQUALIFIED_DAYS, self::backup_days() ) * 86400 : ( isset( self::KEEP[ $area ] ) ? self::KEEP[ $area ] : 86400 );
	}

	/**
	 * Days a qualified close keeps its backups besides the last three
	 * (decision 20, rule 3): BACKUP_DAYS, or what the filter
	 * ab_mcp_staging_backup_days says (0 to 3650; Pro sets 30).
	 *
	 * @return int
	 */
	public static function backup_days() {
		return max( 0, min( 3650, (int) apply_filters( self::BACKUP_DAYS_FILTER, self::BACKUP_DAYS ) ) );
	}

	/**
	 * Run $fn under the lock of the store's folder, where there is one: what
	 * $fn does is then ordered against the clean-up, which reads the runs
	 * whose backups it keeps under the same lock right before it removes
	 * groups (sweep()). A way back for a closed run is made here, so no
	 * sweep that read the runs before it removes the backups it needs
	 * (decision 20, rule 4; Codex round 1 of branch 8, A6). Without a folder
	 * there is nothing a sweep could remove, and $fn runs as it is.
	 *
	 * @param callable $fn What runs under the lock.
	 * @return mixed What $fn returns, or WP_Error where the lock could not be taken.
	 */
	public static function under_lock( callable $fn ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $fn();
		}
		return self::locked( $root, $fn );
	}

	/**
	 * Take the lock of a folder of the store for $fn, waiting for its holder
	 * (flock(), the pattern of Pro's upload sessions). Not reentrant: $fn
	 * takes no lock of the store.
	 *
	 * @param string   $root Folder of the store.
	 * @param callable $fn   What runs under the lock.
	 * @return mixed What $fn returns, or WP_Error.
	 */
	public static function locked( $root, callable $fn ) {
		$file   = $root . '/' . self::LOCK_FILE;
		$handle = is_link( $file ) ? false : @fopen( $file, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle || ! flock( $handle, LOCK_EX ) ) {
			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return new WP_Error( 'ab_mcp_store_lock', 'The lock of the protected store could not be taken. Try again in a moment; if it keeps failing, ask the host whether PHP may lock files in that folder.' );
		}
		try {
			return $fn();
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * The lock of a folder of the store for a writer, and under it the record
	 * of the proof read afresh from the table (admitted()): the proof this
	 * request keeps is a hint only, never the release. A request that chose
	 * its place before another request moved the store, or took its proof
	 * back, writes nothing.
	 *
	 * @param string   $root Folder of the store.
	 * @param callable $fn   What writes.
	 * @return mixed What $fn returns, or WP_Error.
	 */
	public static function writing( $root, callable $fn ) {
		return self::locked(
			$root,
			static function () use ( $root, $fn ) {
				$admitted = self::admitted( $root );
				return true === $admitted ? $fn() : $admitted;
			}
		);
	}

	/**
	 * Whether a folder may be used now, by the record as it is in the table:
	 * it is the place bound for this installation's id, and that place holds
	 * a valid proof (valid()). Writers ask under the lock of the folder
	 * (writing()), readers before they read.
	 *
	 * @param string $root Folder of the store.
	 * @return true|WP_Error
	 */
	public static function admitted( $root ) {
		$id    = self::install_id( false );
		$where = array_search( $root, self::candidates( $id ), true );
		$state = self::state( self::stored()['rec'], $id );
		if ( false === $where || $where !== $state['bound'] ) {
			return new WP_Error( 'ab_mcp_store_moved', 'The protected store is no longer in the place this request chose: another request moved it, because that place could not be used any more. Nothing was read or written. Call it again.' );
		}
		if ( ! self::valid( $state, time() ) ) {
			return new WP_Error( 'ab_mcp_store_unproved', 'The protection of the store is not proved any more: a later check failed, or the last proof is older than a day. Nothing was read or written. Call it again over the web, through the MCP endpoint or in wp-admin: that request checks the store anew.' );
		}
		return true;
	}

	/**
	 * Whether a state of the record holds a valid proof of its bound place
	 * (usable()).
	 *
	 * @param array $state state().
	 * @param float $now   Time.
	 * @return bool
	 */
	public static function valid( array $state, $now ) {
		return null !== $state['bound'] && self::usable( $state['seen'][ $state['bound'] ], $now );
	}

	/**
	 * Whether what is known of a place proves it: a success whose check
	 * began after the last failure seen there ended (a tie counts as not
	 * protected), and that ended less than RECHECK seconds ago.
	 *
	 * @param array{ok:array|null,fail:array|null} $at What is known of the place.
	 * @param float                                $now Time.
	 * @return bool
	 */
	public static function usable( array $at, $now ) {
		return null !== $at['ok']
			&& ( null === $at['fail'] || $at['ok']['start'] > $at['fail']['end'] )
			&& ( $now - $at['ok']['end'] ) < self::RECHECK;
	}

	/**
	 * Uninstall (Z9; Free uninstall.php and Pro's uninstall call this and
	 * nothing else of the store): in each folder of this installation, only
	 * where its ownership manifest is this installation's and under its lock,
	 * first every original an open mark names goes back where its identity
	 * is proved (put_back()); then the folder goes through delete_path()
	 * (Z37): what an open mark still names stays, the tree, every folder
	 * above it, the mark and its instruction, and with them the ownership
	 * manifest, the guard files and the lock, so the folder stays this
	 * installation's and, in the uploads, closed to the web. The parent
	 * beside WordPress goes when nothing else is in it; the daily event goes.
	 * The options go with the list of uninstall.php.
	 */
	public static function uninstall() {
		$id = self::install_id( false );
		foreach ( self::own_folders( $id ) as $root ) {
			$work = static function () use ( $root ) {
				try {
					self::put_back( $root );
				} catch ( Throwable $e ) {
					// Nothing more goes back; what a mark names stays (Z37).
					unset( $e );
				}
				return self::delete_path(
					$root,
					$root,
					array(
						'all_tmp' => true,
						'last'    => array_merge( array( self::OWNER, self::LOCK_FILE ), array_keys( self::GUARDS ) ),
					)
				);
			};
			// A folder whose lock cannot be taken goes all the same, as it
			// always went: nothing could write there either.
			if ( is_wp_error( self::locked( $root, $work ) ) ) {
				$work();
			}
		}
		$parent = dirname( untrailingslashit( ABSPATH ) ) . '/' . self::PARENT;
		if ( '' !== $id && is_dir( $parent ) && ! is_link( $parent ) ) {
			// Only when empty: another installation's folder keeps it.
			self::delete_path( $parent, $parent, array( 'empty' => true ) );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
		self::forget();
	}

	/**
	 * Put back the originals the open marks of a folder name, where their
	 * identity is proved (Z9, E4): the old version lies whole in its backup
	 * (device, inode and, where the mark has it, the hash of its manifest as
	 * the mark says), and the target is missing, or holds the new version of
	 * the swap (its identity as the mark says, and its place in stage is
	 * free): then the new version goes back to its place in stage and the old
	 * one to the target, and the mark is closed. Otherwise nothing is moved,
	 * and the tree, the mark and its instruction stay (delete_path()). Under
	 * the lock of the folder (uninstall()).
	 *
	 * @param string $root Folder of the store.
	 * @return string[] The marks closed.
	 */
	private static function put_back( $root ) {
		$calls  = self::calls();
		$closed = array();
		$names  = $calls->scandir( $root . '/' . self::MARKS );
		foreach ( false === $names ? array() : $names as $mid ) {
			if ( ! preg_match( '/^[0-9a-f]{32}\z/', $mid ) ) {
				continue;
			}
			$mark = self::read_mark( $root, $mid );
			if ( ! is_array( $mark ) || 'closed' === $mark['state'] ) {
				continue;
			}
			$old = null;
			$new = null;
			foreach ( $mark['trees'] as $tree ) {
				if ( 'backup' === $tree['role'] ) {
					$old = $tree;
				} elseif ( 'stage' === $tree['role'] ) {
					$new = $tree;
				}
			}
			$target = $mark['target'];
			if ( null === $old || ! self::is_tree( $root . '/' . $old['path'], $old ) ) {
				continue;
			}
			if ( ! self::listed( $target ) ) {
				// The target is missing (its folder lists it not): the old
				// version goes back to it.
				if ( ! $calls->rename( $root . '/' . $old['path'], $target ) ) {
					continue;
				}
			} elseif ( null !== $new && self::is_tree( $target, $new ) && false === $calls->lstat( $root . '/' . $new['path'] ) ) {
				// The new version is at the target: back to its place, the
				// old one to the target.
				if ( ! $calls->rename( $target, $root . '/' . $new['path'] ) || ! $calls->rename( $root . '/' . $old['path'], $target ) ) {
					continue;
				}
			} else {
				continue;
			}
			if ( true === self::close_mark_in( $root, $mid, $mark ) ) {
				$closed[] = $mid;
			}
		}
		return $closed;
	}

	/**
	 * Whether a path is the tree a mark names: a folder or file (no link) of
	 * its form with its device and inode, and, where the mark holds one, the
	 * hash of its manifest read now.
	 *
	 * @param string $path Path.
	 * @param array  $tree The tree as the mark names it.
	 * @return bool
	 */
	private static function is_tree( $path, array $tree ) {
		$stat = self::calls()->lstat( $path );
		if ( false === $stat || (int) $stat['dev'] !== (int) $tree['dev'] || (int) $stat['ino'] !== (int) $tree['ino'] ) {
			return false;
		}
		$type = $stat['mode'] & 0170000;
		if ( ( 'folder' === $tree['form'] ? 0040000 : 0100000 ) !== $type ) {
			return false;
		}
		if ( null === $tree['hash'] ) {
			return true;
		}
		$read = self::read_tree( $path );
		return $read['complete'] && null !== $read['hash'] && hash_equals( (string) $tree['hash'], (string) $read['hash'] );
	}

	/**
	 * Whether the folder of a path lists it: a path that is missing only by a
	 * failed lstat() is no free target (fail closed).
	 *
	 * @param string $path Path.
	 * @return bool True where listed, or where its folder cannot be listed.
	 */
	private static function listed( $path ) {
		$names = self::calls()->scandir( dirname( $path ) );
		return false === $names || in_array( basename( $path ), $names, true ) || false !== self::calls()->lstat( $path );
	}

	/* --------------------------------------------------------------- trees */

	/**
	 * Put a tree into a group of the area stage or backups (Z7, plan E4): the
	 * one way a tree comes into the store, and the one place of its rules:
	 *
	 * - never over another: the place of the tree is free, and a tree is
	 *   never renamed or changed in the store afterwards (write_file() writes
	 *   below a tree only while this function copies it; the store renames
	 *   nothing of a tree but where uninstall() puts an original back);
	 * - the store and the folder the tree stands for ($target, the folder or
	 *   file of the unit in the site) are on one device, else
	 *   code:store_device: a rename between them would copy, not move;
	 * - a tree with a file that configures the web server or PHP
	 *   (SERVER_CONFIG, at any depth) only in the store beside WordPress; in
	 *   the uploads only without one, and only where the self-check answered
	 *   a PHP file below stage and below backups with 403 or 404 without
	 *   running it, else code:store_web;
	 * - what goes in comes back byte for byte: the manifest of the copy
	 *   (AB_MCP_Sync_File_Manifest::unit()) is the one of the source, read
	 *   whole before, else nothing of it stays.
	 *
	 * With a source the tree is a copy of it, file by file through
	 * write_file(), with the modes of the source. Without one only the place
	 * is checked and given, for the old version itself, which the swap
	 * (branch 5) renames from $target into it under its mark: the same rules,
	 * read from $target.
	 *
	 * @param string      $area   'stage' or 'backups'.
	 * @param string      $gid    A group of that area (open()).
	 * @param string      $name   Name of the tree in the group: a-z, 0-9, ".", "_", "-", up to 64.
	 * @param string      $target The folder or file of the unit in the site (absolute; its folder exists).
	 * @param string|null $source A folder or file to copy; null for the place only.
	 * @return array{path:string,hash:string,form:string,files:int,bytes:int,where:string,copied:bool}|WP_Error
	 */
	public static function put_tree( $area, $gid, $name, $target, $source = null ) {
		$bad = self::bad_ref( $area, $gid, $name );
		if ( null !== $bad ) {
			return $bad;
		}
		if ( ! in_array( $area, self::TREE_AREAS, true ) ) {
			return new WP_Error( 'ab_mcp_store_area', __( 'A tree lies in the area stage or backups of the protected store. Use one of them.', 'alphabridge-mcp' ) );
		}
		$target = (string) $target;
		$source = null === $source ? null : (string) $source;
		foreach ( null === $source ? array( $target ) : array( $target, $source ) as $path ) {
			if ( ! self::absolute( $path ) ) {
				return new WP_Error( 'ab_mcp_store_tree_path', __( 'A tree names the folder of its unit and its source by an absolute path without "." or ".." in it. Pass such a path.', 'alphabridge-mcp' ) );
			}
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		// A store in the uploads proved before PHP was asked below stage and
		// backups (an older version of this plugin): asked now, so the tree
		// does not wait a day for the next check.
		$proof = self::proof();
		if ( 'uploads' === $proof['where'] && null === $proof['php'] ) {
			self::proof( null, true );
			$root = self::root();
			if ( is_wp_error( $root ) ) {
				return $root;
			}
		}
		$id = self::install_id();
		return self::writing(
			$root,
			static function () use ( $root, $id, $area, $gid, $name, $target, $source ) {
				$found = self::group( $root, $area, self::group_name( $area, $gid, $id ), $gid );
				if ( ! is_array( $found ) ) {
					return $found;
				}
				$calls = self::calls();
				$place = $found[1] . '/' . $name;
				if ( false !== $calls->lstat( $place ) ) {
					return new WP_Error( 'ab_mcp_store_tree_exists', __( 'This group holds a tree or file of that name already, and a tree is never put over another. Use another name for it.', 'alphabridge-mcp' ) );
				}
				// One device (E4): a rename between the store and the folder
				// of the unit moves, never copies.
				$here  = $calls->stat( $found[1] );
				$there = $calls->stat( dirname( $target ) );
				if ( false === $here || false === $there || (int) $here['dev'] !== (int) $there['dev'] ) {
					return new WP_Error(
						'code:store_device',
						/* translators: %s: the folder of the unit, relative to the folder of WordPress where it lies there. */
						sprintf( __( 'The protected store lies on another device (file system) than the folder %s, or the device of one of them could not be read, so nothing of this unit goes into the store: a rename between two devices copies and deletes instead of moving in one step. Ask the host to put the folder ab-mcp-store beside WordPress (or the uploads folder) on the file system of wp-content, then try again; until then, update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ), self::shown_path( dirname( $target ) ) ),
						array( 'folder' => self::shown_path( dirname( $target ) ) )
					);
				}
				$read = self::read_tree( null === $source ? $target : $source );
				if ( ! $read['complete'] || null === $read['hash'] ) {
					return new WP_Error(
						'ab_mcp_store_tree_unread',
						/* translators: %s: why it was not read whole (a code of the manifest, as unreadable, changed, link, vcs). */
						sprintf( __( 'The folder or file for the tree could not be read whole (%s), so nothing of it went into the protected store. Check that it holds only plain files and folders (no link, no .git) and that nothing writes into it right now, then try again.', 'alphabridge-mcp' ), (string) $read['cause'] ),
						array( 'cause' => $read['cause'] )
					);
				}
				$where = (string) array_search( $root, self::candidates( $id ), true );
				if ( 'outside' !== $where ) {
					$config = self::server_config( $read['entries'] );
					if ( array() !== $config ) {
						return new WP_Error(
							'code:store_web',
							/* translators: %s: the files, relative to the unit, comma-separated. */
							sprintf( __( 'The unit holds a file that configures the web server or PHP (%s), and the protected store lies in the uploads folder, where such a file could open its folder to the web; so it does not go into the store. Ask the host to let PHP create the folder ab-mcp-store beside the WordPress folder, outside the web root, then try again; until then, update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ), implode( ', ', array_slice( $config, 0, 5 ) ) ),
							array(
								'why'   => 'server_config',
								'files' => array_slice( $config, 0, 20 ),
							)
						);
					}
					if ( true !== self::php_proved( $id ) ) {
						return new WP_Error(
							'code:store_web',
							__( 'The protected store lies in the uploads folder, and its self-check did not show that the web server answers a PHP file in a folder below stage and below backups with 403 or 404 without running it; so no plugin or theme goes into the store. Ask the host to let PHP create the folder ab-mcp-store beside the WordPress folder, outside the web root (or to deny every request below the folder ab-mcp-store-… in the uploads, PHP files included), then try again in a day, when the check is made anew; until then, update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ),
							array( 'why' => 'php_proof' )
						);
					}
				}
				$out = array(
					'path'   => $place,
					'hash'   => (string) $read['hash'],
					'form'   => (string) $read['form'],
					'files'  => (int) $read['files'],
					'bytes'  => (int) $read['bytes'],
					'where'  => $where,
					'copied' => false,
				);
				if ( null === $source ) {
					return $out;
				}
				self::$noted[ $place ]    = true;
				self::$building[ $place ] = true;
				try {
					$made = self::copy_tree( $root, $source, $place );
				} catch ( Throwable $e ) {
					$made = new WP_Error( 'ab_mcp_store_write', 'The tree could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				unset( self::$building[ $place ] );
				if ( true === $made ) {
					$back = self::read_tree( $place );
					if ( $back['complete'] && null !== $back['hash'] && hash_equals( $out['hash'], (string) $back['hash'] ) ) {
						unset( self::$noted[ $place ] );
						$out['copied'] = true;
						return $out;
					}
					$made = new WP_Error( 'ab_mcp_store_tree_changed', __( 'The copy in the protected store is not what was read before it (the source changed while it was copied, or a file was not written whole), so nothing of it stays. Check that nothing writes into the source right now, then try again.', 'alphabridge-mcp' ) );
				}
				// What this request made of it goes again (delete_path()).
				self::delete_path( $root, $place );
				return $made;
			}
		);
	}

	/**
	 * A tree read back: its place where its manifest is the one put_tree()
	 * gave (what went in comes back byte for byte, Z7).
	 *
	 * @param string $area Area ('stage' or 'backups').
	 * @param string $gid  Group.
	 * @param string $name Name of the tree.
	 * @param string $hash The hash put_tree() gave.
	 * @return array{path:string,hash:string,form:string,files:int,bytes:int}|WP_Error
	 */
	public static function tree( $area, $gid, $name, $hash ) {
		$bad = self::bad_ref( $area, $gid, $name );
		if ( null !== $bad ) {
			return $bad;
		}
		if ( ! in_array( $area, self::TREE_AREAS, true ) ) {
			return new WP_Error( 'ab_mcp_store_area', __( 'A tree lies in the area stage or backups of the protected store. Use one of them.', 'alphabridge-mcp' ) );
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = self::admitted( $root );
		if ( true !== $admitted ) {
			return $admitted;
		}
		$found = self::group( $root, $area, self::group_name( $area, $gid, self::install_id() ), $gid );
		if ( ! is_array( $found ) ) {
			return $found;
		}
		$place = $found[1] . '/' . $name;
		$stat  = self::calls()->lstat( $place );
		if ( false === $stat ) {
			return new WP_Error( 'ab_mcp_store_tree_missing', __( 'The tree is not in its group of the protected store: it was never put there, or its group was removed after its time. Use put_tree() to put it there again.', 'alphabridge-mcp' ) );
		}
		if ( ! in_array( $stat['mode'] & 0170000, array( 0040000, 0100000 ), true ) ) {
			return self::unsafe();
		}
		$read = self::read_tree( $place );
		if ( ! $read['complete'] || null === $read['hash'] || ! hash_equals( (string) $hash, (string) $read['hash'] ) ) {
			return new WP_Error( 'ab_mcp_store_tree_changed', __( 'The tree in the protected store is not what was put there (its manifest differs, or it could not be read whole), so it is not used. Make it again; if it keeps happening, ask the host whether something else writes into the folder of the store.', 'alphabridge-mcp' ) );
		}
		return array(
			'path'  => $place,
			'hash'  => (string) $read['hash'],
			'form'  => (string) $read['form'],
			'files' => (int) $read['files'],
			'bytes' => (int) $read['bytes'],
		);
	}

	/**
	 * Copy a folder or file into the place of a tree: folders made with the
	 * mode 0700 while it is filled, each file through write_file() (a temp
	 * file of tmp/, renamed into place, never over another) with its mode,
	 * then the modes of the folders, the deepest first. Never through a
	 * link: the source was read whole by its manifest before, which locks a
	 * link.
	 *
	 * @param string $root   Folder of the store.
	 * @param string $source Folder or file.
	 * @param string $place  The place of the tree (free).
	 * @return true|WP_Error
	 */
	private static function copy_tree( $root, $source, $place ) {
		$calls = self::calls();
		$stat  = $calls->lstat( $source );
		if ( false === $stat ) {
			return new WP_Error( 'ab_mcp_store_tree_unread', 'The source of the tree is gone. Check that nothing removes it right now, then try again.' );
		}
		$type = $stat['mode'] & 0170000;
		if ( 0100000 === $type ) {
			return self::write_file(
				$root,
				$place,
				'',
				array(
					'from'      => $source,
					'size'      => (int) $stat['size'],
					'mode'      => $stat['mode'] & 0777,
					'exclusive' => true,
				)
			);
		}
		if ( 0040000 !== $type ) {
			return self::unsafe();
		}
		$dirs  = array( array( $place, $stat['mode'] & 0777 ) );
		$queue = array( array( $source, $place ) );
		if ( ! $calls->mkdir( $place, 0700 ) ) {
			return new WP_Error( 'ab_mcp_store_write', 'A folder of the tree could not be made in the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
		}
		while ( array() !== $queue ) {
			list( $from, $to ) = array_shift( $queue );
			$names             = $calls->scandir( $from );
			if ( false === $names ) {
				return new WP_Error( 'ab_mcp_store_tree_unread', 'A folder of the source of the tree could not be read. Check its rights, then try again.' );
			}
			foreach ( $names as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$at = $calls->lstat( $from . '/' . $entry );
				if ( false === $at ) {
					return new WP_Error( 'ab_mcp_store_tree_unread', 'A file of the source of the tree could not be read. Check its rights, then try again.' );
				}
				$kind = $at['mode'] & 0170000;
				if ( 0040000 === $kind ) {
					if ( ! $calls->mkdir( $to . '/' . $entry, 0700 ) ) {
						return new WP_Error( 'ab_mcp_store_write', 'A folder of the tree could not be made in the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
					}
					$dirs[]  = array( $to . '/' . $entry, $at['mode'] & 0777 );
					$queue[] = array( $from . '/' . $entry, $to . '/' . $entry );
				} elseif ( 0100000 === $kind ) {
					$written = self::write_file(
						$root,
						$to . '/' . $entry,
						'',
						array(
							'from'      => $from . '/' . $entry,
							'size'      => (int) $at['size'],
							'mode'      => $at['mode'] & 0777,
							'exclusive' => true,
						)
					);
					if ( true !== $written ) {
						return $written;
					}
				} else {
					return self::unsafe();
				}
			}
		}
		foreach ( array_reverse( $dirs ) as $dir ) {
			if ( ! $calls->chmod( $dir[0], $dir[1] ) ) {
				return new WP_Error( 'ab_mcp_store_write', 'The mode of a folder of the tree could not be set in the protected store. Ask the host to check the rights of the folder, then try again.' );
			}
		}
		return true;
	}

	/**
	 * Read a tree whole, as a unit (AB_MCP_Sync_File_Manifest::unit(), Z1):
	 * its manifest, or why not.
	 *
	 * @param string $path Folder or file.
	 * @return array As AB_MCP_Sync_File_Manifest::unit().
	 */
	private static function read_tree( $path ) {
		$now = class_exists( 'AB_MCP_Sync_Env', false ) && null !== AB_MCP_Sync_Env::$clock ? (float) call_user_func( AB_MCP_Sync_Env::$clock ) : microtime( true );
		return AB_MCP_Sync_File_Manifest::unit( dirname( (string) $path ), basename( (string) $path ), 'live', $now + self::TREE_BUDGET, true, 'tree' );
	}

	/**
	 * The files of a manifest that configure the web server or PHP
	 * (SERVER_CONFIG), by their name in lower case, a dot or blank at its end
	 * left out (Windows ignores them).
	 *
	 * @param array[] $entries Entries of a manifest: [path, size, sha256, mtime].
	 * @return string[] Their paths, relative to the unit.
	 */
	private static function server_config( array $entries ) {
		$out = array();
		foreach ( $entries as $entry ) {
			$path = (string) $entry[0];
			if ( in_array( strtolower( rtrim( basename( str_replace( '\\', '/', $path ) ), ". \t" ) ), self::SERVER_CONFIG, true ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Whether the proof of the uploads, as the table holds it now, showed a
	 * PHP file below stage and below backups answered 403 or 404 without
	 * running it (E4). Asked under the lock of the store (put_tree()).
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	private static function php_proved( $id ) {
		$state = self::state( self::stored()['rec'], $id );
		$ok    = $state['seen']['uploads']['ok'];
		return 'uploads' === $state['bound'] && self::valid( $state, time() ) && is_array( $ok ) && isset( $ok['detail']['php'] ) && true === $ok['detail']['php'];
	}

	/**
	 * A path as a message names it: relative to the folder of WordPress
	 * where it lies there, else its last two parts.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function shown_path( $path ) {
		$base = untrailingslashit( ABSPATH );
		$path = (string) $path;
		if ( 0 === strpos( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) + 1 );
		}
		return basename( dirname( $path ) ) . '/' . basename( $path );
	}

	/**
	 * Whether a path is absolute and plain: no ".", "..", empty part, NUL or
	 * line break.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function absolute( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' !== $path[0] || preg_match( '/[\x00\r\n]/', $path ) ) {
			return false;
		}
		$parts = explode( '/', substr( $path, 1 ) );
		return ! in_array( '', $parts, true ) && ! in_array( '.', $parts, true ) && ! in_array( '..', $parts, true );
	}

	/* --------------------------------------------------------------- marks */

	/**
	 * Write the mark of a swap and its instruction (plan E6, the part of
	 * branch 3), before the first rename of the swap (branch 5): the folder
	 * marks/<mid>/ with mark.json and WIEDERHERSTELLEN.txt, each through
	 * write_file() and read back. While the mark is open, delete_path() leaves
	 * the trees it names, every folder above them, the mark and the
	 * instruction (Z37), and uninstall() puts the original back only where
	 * its identity is proved (Z9).
	 *
	 * @param array $mark run_id, seq (int); op (a-z and "_"); unit
	 *                    {kind, ref}; target (absolute path of the unit's
	 *                    folder or file); trees (one or two:
	 *                    {role: backup|stage, path (absolute, a tree of
	 *                    stage or backups of this store), form: folder|file,
	 *                    dev, ino, hash (64 hex, or null)}); active (the main
	 *                    files of the unit active at the beginning); note
	 *                    (optional).
	 * @return array{mid:string,mark:string,guide:string}|WP_Error
	 */
	public static function put_mark( array $mark ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$fields = self::mark_fields( $mark, $root );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$id = self::install_id();
		return self::writing(
			$root,
			static function () use ( $root, $id, $fields ) {
				$dir = self::way( $root, array( self::MARKS ) );
				if ( null === $dir ) {
					return self::unsafe();
				}
				$mid  = bin2hex( AB_MCP_Payload_File::random_bytes( 16 ) );
				$mark = array(
					'format' => self::MARK_FORMAT,
					'mid'    => $mid,
					'state'  => 'open',
					'begun'  => time(),
					'closed' => 0,
					'store'  => $root,
					'where'  => (string) array_search( $root, self::candidates( $id ), true ),
				) + $fields;
				if ( ! self::calls()->mkdir( $dir . '/' . $mid, 0700 ) ) {
					return new WP_Error( 'ab_mcp_store_write', 'The mark could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				$text  = (string) json_encode( $mark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$guide = self::guide( $mark );
				foreach ( array( self::MARK_FILE => $text, self::GUIDE_FILE => $guide ) as $file => $bytes ) {
					$path    = $dir . '/' . $mid . '/' . $file;
					$written = self::write_file( $root, $path, $bytes );
					if ( true !== $written ) {
						return $written;
					}
					if ( self::calls()->read( $path, self::MARK_MAX ) !== $bytes ) {
						return new WP_Error( 'ab_mcp_store_mark_read', 'The mark or its instruction did not read back as written, so the swap must not begin. Ask the host whether something else writes into the folder of the protected store, then try again.' );
					}
				}
				return array(
					'mid'   => $mid,
					'mark'  => $dir . '/' . $mid . '/' . self::MARK_FILE,
					'guide' => $dir . '/' . $mid . '/' . self::GUIDE_FILE,
				);
			}
		);
	}

	/**
	 * Close a mark: its swap ended (branch 5) or its original went back
	 * (uninstall()). The trees it named are then a matter of their group
	 * (kept()); the folder of the mark goes a day after (cleanup()).
	 *
	 * @param string $mid Mark (32 hex).
	 * @return true|WP_Error
	 */
	public static function close_mark( $mid ) {
		if ( ! is_string( $mid ) || ! preg_match( '/^[0-9a-f]{32}\z/', $mid ) ) {
			return new WP_Error( 'ab_mcp_store_no_mark', 'A mark of the protected store is named by the 32 hex characters put_mark() returned. Pass that id.' );
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return self::writing(
			$root,
			static function () use ( $root, $mid ) {
				$mark = self::read_mark( $root, $mid );
				if ( ! is_array( $mark ) ) {
					return null === $mark
						? new WP_Error( 'ab_mcp_store_no_mark', 'There is no such mark in the protected store. Pass the id put_mark() returned.' )
						: new WP_Error( 'ab_mcp_store_mark_read', 'The mark could not be read, so it stays open and what it names stays. Ask the host to check the rights of the folder of the protected store, then try again.' );
				}
				return self::close_mark_in( $root, $mid, $mark );
			}
		);
	}

	/**
	 * The marks of the store: each readable one, and the ids of those that
	 * cannot be read (while one cannot, nothing in stage, backups and marks
	 * is removed).
	 *
	 * @return array{marks:array[],unreadable:string[]}|WP_Error
	 */
	public static function marks() {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = self::admitted( $root );
		if ( true !== $admitted ) {
			return $admitted;
		}
		$out   = array(
			'marks'      => array(),
			'unreadable' => array(),
		);
		$names = self::calls()->scandir( $root . '/' . self::MARKS );
		if ( false === $names ) {
			return new WP_Error( 'ab_mcp_store_mark_read', 'The marks of the protected store could not be read. Ask the host to check the rights of the folder of the store, then try again.' );
		}
		foreach ( $names as $mid ) {
			if ( ! preg_match( '/^[0-9a-f]{32}\z/', $mid ) ) {
				continue;
			}
			$mark = self::read_mark( $root, $mid );
			if ( is_array( $mark ) ) {
				$out['marks'][] = $mark;
			} elseif ( false === $mark ) {
				$out['unreadable'][] = $mid;
			}
		}
		return $out;
	}

	/**
	 * The instruction of a mark (WIEDERHERSTELLEN.txt; plan E6, and the rule
	 * of the shell run of a way back by hand that branch 1 laid down): what
	 * the swap moved, with
	 * device, inode and hash of each tree, and for each point of an abort a
	 * section [point] whose lines "$ …" are the commands, in a shell, that put
	 * the old state back by hand and check that it is the right folder (its
	 * inode, ls -di). For a plugin active at the beginning: what to do where
	 * it is inactive afterwards, and the sentence of the race (limit A5).
	 *
	 * @param array $mark A mark as put_mark() stores it.
	 * @return string
	 */
	public static function guide( array $mark ) {
		$root   = rtrim( (string) $mark['store'], '/' );
		$target = (string) $mark['target'];
		$old    = null;
		$new    = null;
		foreach ( $mark['trees'] as $tree ) {
			if ( 'backup' === $tree['role'] ) {
				$old = $tree;
			} elseif ( 'stage' === $tree['role'] ) {
				$new = $tree;
			}
		}
		$q     = static function ( $path ) {
			return "'" . str_replace( "'", "'\\''", (string) $path ) . "'";
		};
		$inode = static function ( $path, $tree ) use ( $q ) {
			return '$ test "$(ls -di ' . $q( $path ) . ' | awk \'{print $1}\')" = "' . (int) $tree['ino'] . '"';
		};
		$lines   = array();
		$lines[] = __( 'AlphaBridge MCP: how to put the old state back by hand, without WordPress', 'alphabridge-mcp' );
		$lines[] = '';
		/* translators: 1: kind of the unit (plugin, theme …), 2: its name. */
		$lines[] = sprintf( __( 'Unit: %1$s %2$s', 'alphabridge-mcp' ), (string) $mark['unit']['kind'], (string) $mark['unit']['ref'] );
		/* translators: %s: absolute path. */
		$lines[] = sprintf( __( 'Target (the folder of the unit in the site): %s', 'alphabridge-mcp' ), $target );
		foreach ( array( $old, $new ) as $tree ) {
			if ( null === $tree ) {
				continue;
			}
			$lines[] = sprintf(
				'backup' === $tree['role']
					/* translators: 1: absolute path, 2: device, 3: inode, 4: hash of the manifest or "-". */
					? __( 'Backup (the old version): %1$s (device %2$d, inode %3$d, hash %4$s)', 'alphabridge-mcp' )
					/* translators: 1: absolute path, 2: device, 3: inode, 4: hash of the manifest or "-". */
					: __( 'Stage (the new version): %1$s (device %2$d, inode %3$d, hash %4$s)', 'alphabridge-mcp' ),
				$root . '/' . $tree['path'],
				(int) $tree['dev'],
				(int) $tree['ino'],
				null === $tree['hash'] ? '-' : (string) $tree['hash']
			);
		}
		/* translators: 1: run, 2: step, 3: the time it began (UTC). */
		$lines[] = sprintf( __( 'Run %1$d, step %2$d, begun %3$s UTC.', 'alphabridge-mcp' ), (int) $mark['run_id'], (int) $mark['seq'], gmdate( 'Y-m-d H:i:s', (int) $mark['begun'] ) );
		$lines[] = __( 'The hash is the one of the manifest of the unit as AlphaBridge reads it; in a shell the inode tells the right folder from another of the same name (ls -di shows it).', 'alphabridge-mcp' );
		$lines[] = __( 'Run the commands of the point where the swap stopped, one after the other; where a check (test …) fails, stop and ask before anything else is moved.', 'alphabridge-mcp' );
		$lines[] = '';

		$lines[] = '[vor-den-umbenennungen]';
		$lines[] = __( 'Before the first rename nothing was moved. Check that the target is the old version:', 'alphabridge-mcp' );
		$lines[] = null === $old ? '$ test -e ' . $q( $target ) : $inode( $target, $old );
		$lines[] = '';

		if ( null !== $old ) {
			$back    = $root . '/' . $old['path'];
			$put     = array( '$ test ! -e ' . $q( $target ), $inode( $back, $old ), '$ mv ' . $q( $back ) . ' ' . $q( $target ), $inode( $target, $old ) );
			$lines[] = '[zwischen-den-umbenennungen]';
			$lines[] = __( 'The target is missing; the old version lies whole in the backup. Put it back and check it:', 'alphabridge-mcp' );
			$lines   = array_merge( $lines, $put );
			$lines[] = '';
			$swap    = $put;
			if ( null !== $new ) {
				$stage = $root . '/' . $new['path'];
				$swap  = array( $inode( $target, $new ), '$ test ! -e ' . $q( $stage ), $inode( $back, $old ), '$ mv ' . $q( $target ) . ' ' . $q( $stage ), '$ mv ' . $q( $back ) . ' ' . $q( $target ), $inode( $target, $old ) );
			}
			$lines[] = '[nach-den-umbenennungen]';
			$lines[] = null !== $new
				? __( 'The new version is at the target, the old one in the backup. Swap back and check it:', 'alphabridge-mcp' )
				: __( 'The target is missing; the old version lies whole in the backup. Put it back and check it:', 'alphabridge-mcp' );
			$lines   = array_merge( $lines, $swap );
			$lines[] = '';
			$lines[] = '[waehrend-des-ruecktauschs]';
			$lines[] = __( 'The swap back stopped: the target holds the new version or is missing. Finish it and check it:', 'alphabridge-mcp' );
			if ( null !== $new ) {
				$lines[] = '$ if [ -e ' . $q( $target ) . ' ] && [ "$(ls -di ' . $q( $target ) . ' | awk \'{print $1}\')" = "' . (int) $new['ino'] . '" ]; then mv ' . $q( $target ) . ' ' . $q( $root . '/' . $new['path'] ) . '; fi';
			}
			$lines[] = '$ if [ ! -e ' . $q( $target ) . ' ]; then mv ' . $q( $back ) . ' ' . $q( $target ) . '; fi';
			$lines[] = $inode( $target, $old );
			$lines[] = '';
			$lines[] = '[nach-der-aktivierung]';
			$lines[] = __( 'After the activation: as after the renames. Swap back and check it:', 'alphabridge-mcp' );
			$lines   = array_merge( $lines, $swap );
			$lines[] = '';
		}
		foreach ( (array) $mark['active'] as $file ) {
			/* translators: %s: the main file of a plugin (folder/file.php). */
			$lines[] = sprintf( __( 'The plugin %s was active when the swap began. Where it is inactive afterwards: way (a) with its approval in accept (it is switched on as by hand, its activation routine runs), or Plugins → Activate in wp-admin by hand.', 'alphabridge-mcp' ), (string) $file );
			$lines[] = __( 'A request to the page Plugins at the same time can still deactivate the plugin after the swap; the next report shows it then.', 'alphabridge-mcp' );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The fields of a mark, checked: every path one of this store or the
	 * site's absolute, each tree a tree of stage or backups, stored relative
	 * to the store.
	 *
	 * @param array  $mark See put_mark().
	 * @param string $root Folder of the store.
	 * @return array|WP_Error
	 */
	private static function mark_fields( array $mark, $root ) {
		$refuse = static function ( $what ) {
			return new WP_Error( 'ab_mcp_store_mark', sprintf( 'The mark is not written: %s. Pass the fields as put_mark() names them.', $what ) );
		};
		foreach ( array( 'run_id', 'seq' ) as $key ) {
			if ( ! isset( $mark[ $key ] ) || ! is_int( $mark[ $key ] ) || $mark[ $key ] < 0 ) {
				return $refuse( $key . ' is a whole number of 0 or more' );
			}
		}
		if ( ! isset( $mark['op'] ) || ! is_string( $mark['op'] ) || ! preg_match( '/^[a-z_]{1,32}\z/', $mark['op'] ) ) {
			return $refuse( 'op is a word of a-z and "_"' );
		}
		if ( ! isset( $mark['unit']['kind'], $mark['unit']['ref'] ) || ! is_string( $mark['unit']['kind'] ) || ! preg_match( '/^[a-z_]{1,32}\z/', $mark['unit']['kind'] ) || ! is_string( $mark['unit']['ref'] ) || '' === $mark['unit']['ref'] || strlen( $mark['unit']['ref'] ) > 191 || preg_match( '/[\x00-\x1f]/', $mark['unit']['ref'] ) ) {
			return $refuse( 'unit names its kind and its name' );
		}
		if ( ! isset( $mark['target'] ) || ! self::absolute( (string) $mark['target'] ) ) {
			return $refuse( 'target is the absolute path of the unit' );
		}
		if ( ! isset( $mark['trees'] ) || ! is_array( $mark['trees'] ) || array() === $mark['trees'] || count( $mark['trees'] ) > count( self::MARK_ROLES ) ) {
			return $refuse( 'trees names one or two trees' );
		}
		$trees = array();
		$roles = array();
		foreach ( array_values( $mark['trees'] ) as $tree ) {
			$path = is_array( $tree ) && isset( $tree['path'] ) && is_string( $tree['path'] ) ? $tree['path'] : '';
			$rel  = 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : '';
			if ( ! is_array( $tree ) || ! isset( $tree['role'], $tree['form'], $tree['dev'], $tree['ino'] ) || ! in_array( $tree['role'], self::MARK_ROLES, true ) || isset( $roles[ $tree['role'] ] ) || ! in_array( $tree['form'], array( 'folder', 'file' ), true ) || ! is_int( $tree['dev'] ) || ! is_int( $tree['ino'] ) || null === self::tree_rel( $rel ) || ( isset( $tree['hash'] ) && ! ( is_string( $tree['hash'] ) && preg_match( '/^[0-9a-f]{64}\z/', $tree['hash'] ) ) ) ) {
				return $refuse( 'each tree names its role (backup or stage, once each), its form, device and inode, a hash or none, and its place in stage or backups of the store' );
			}
			$roles[ $tree['role'] ] = true;
			$trees[]                = array(
				'role' => $tree['role'],
				'path' => $rel,
				'form' => $tree['form'],
				'dev'  => $tree['dev'],
				'ino'  => $tree['ino'],
				'hash' => isset( $tree['hash'] ) ? $tree['hash'] : null,
			);
		}
		$active = isset( $mark['active'] ) ? $mark['active'] : array();
		if ( ! is_array( $active ) ) {
			return $refuse( 'active is a list of main files' );
		}
		foreach ( $active as $file ) {
			if ( ! is_string( $file ) || '' === $file || strlen( $file ) > 191 || preg_match( '/[\x00-\x1f]/', $file ) ) {
				return $refuse( 'active is a list of main files' );
			}
		}
		$note = isset( $mark['note'] ) ? $mark['note'] : '';
		if ( ! is_string( $note ) || strlen( $note ) > 1000 ) {
			return $refuse( 'note is a text of 1000 bytes at most' );
		}
		return array(
			'run_id' => $mark['run_id'],
			'seq'    => $mark['seq'],
			'op'     => $mark['op'],
			'unit'   => array(
				'kind' => $mark['unit']['kind'],
				'ref'  => $mark['unit']['ref'],
			),
			'target' => (string) $mark['target'],
			'trees'  => $trees,
			'active' => array_values( $active ),
			'note'   => $note,
		);
	}

	/**
	 * The path of a tree relative to the store, checked: stage or backups, a
	 * group of 40 hex, a name of a tree.
	 *
	 * @param string $rel Path relative to the store.
	 * @return string|null
	 */
	private static function tree_rel( $rel ) {
		$parts = explode( '/', (string) $rel );
		if ( 3 !== count( $parts ) || ! in_array( $parts[0], self::TREE_AREAS, true ) || ! preg_match( '/^[0-9a-f]{40}\z/', $parts[1] ) || ! self::valid_name( $parts[2] ) ) {
			return null;
		}
		return (string) $rel;
	}

	/**
	 * A mark of a folder of the store, read: the mark (fields checked), null
	 * where its folder holds none (none written yet), false where it cannot
	 * be read or is not a mark of this format (fail closed).
	 *
	 * @param string $root Folder of the store.
	 * @param string $mid  Mark.
	 * @return array|null|false
	 */
	private static function read_mark( $root, $mid ) {
		$calls = self::calls();
		$dir   = $root . '/' . self::MARKS . '/' . $mid;
		$names = $calls->scandir( $dir );
		if ( false === $names ) {
			return false;
		}
		if ( ! in_array( self::MARK_FILE, $names, true ) ) {
			return null;
		}
		$raw  = $calls->read( $dir . '/' . self::MARK_FILE, self::MARK_MAX );
		$mark = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $mark ) || ! isset( $mark['format'], $mark['mid'], $mark['state'], $mark['trees'], $mark['target'], $mark['closed'] ) || self::MARK_FORMAT !== $mark['format'] || $mid !== $mark['mid'] || ! in_array( $mark['state'], array( 'open', 'closed' ), true ) || ! is_array( $mark['trees'] ) || ! is_string( $mark['target'] ) ) {
			return false;
		}
		foreach ( $mark['trees'] as $tree ) {
			if ( ! is_array( $tree ) || ! isset( $tree['role'], $tree['path'], $tree['form'], $tree['dev'], $tree['ino'] ) || ! is_string( $tree['path'] ) || null === self::tree_rel( $tree['path'] ) || ! array_key_exists( 'hash', $tree ) ) {
				return false;
			}
		}
		return $mark;
	}

	/**
	 * Close a mark that was read (close_mark(), put_back()): its state closed,
	 * the time, written through write_file() and read back.
	 *
	 * @param string $root Folder of the store.
	 * @param string $mid  Mark.
	 * @param array  $mark The mark as read.
	 * @return true|WP_Error
	 */
	private static function close_mark_in( $root, $mid, array $mark ) {
		if ( 'closed' === $mark['state'] ) {
			return true;
		}
		$mark['state']  = 'closed';
		$mark['closed'] = time();
		$path           = $root . '/' . self::MARKS . '/' . $mid . '/' . self::MARK_FILE;
		$text           = (string) json_encode( $mark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$written        = self::write_file( $root, $path, $text );
		if ( true !== $written ) {
			return $written;
		}
		return self::calls()->read( $path, self::MARK_MAX ) === $text ? true : new WP_Error( 'ab_mcp_store_mark_read', 'The closed mark did not read back as written. Ask the host whether something else writes into the folder of the protected store, then try again.' );
	}

	/* ------------------------------------------------- the writer, the remover */

	/**
	 * A new temp file of the store (Z37): an empty file in tmp/ with the
	 * prefix TMP_PREFIX, made by this request and noted, so that only it, or
	 * the clean-up after TMP_AGE, removes it (delete_path()). Never in a
	 * group or a tree.
	 *
	 * @param string $root Folder of the store (ensure(): its building folder).
	 * @return string|WP_Error The path.
	 */
	public static function temp_file( $root ) {
		$root = rtrim( (string) $root, '/' );
		if ( null === self::way( $root, array( self::TMP ) ) ) {
			return new WP_Error( 'ab_mcp_store_tmp', 'The folder tmp of the protected store is missing or is a link, so nothing is written there. Ask the host to check the folder of the store, then try again.' );
		}
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$path = $root . '/' . self::TMP . '/' . self::TMP_PREFIX . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) );
			if ( self::calls()->create( $path ) ) {
				self::$noted[ $path ] = true;
				return $path;
			}
		}
		return new WP_Error( 'ab_mcp_store_write', 'A temp file could not be made in the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
	}

	/**
	 * Write a file of the store (Z37; every write into the store but the
	 * folders of a tree): the bytes into a temp file of temp_file(), renamed
	 * into place only where stat() reports one device for tmp/ and the folder
	 * of the file (a rename across devices copies and deletes, and the file
	 * would not be whole or absent; one device does not prove that it is
	 * one step, a mount boundary of the same device is a limit,
	 * GRENZEN-CODE.md). Elsewhere nothing is written, and the answer names the
	 * folder. A file of a group or a tree in stage and backups is written once
	 * (never over another), below a tree only while put_tree() copies it. A
	 * write that fails leaves no temp file: delete_path() removes it, as noted
	 * by this request.
	 *
	 * @param string $root  Folder of the store (ensure(): its building folder).
	 * @param string $path  The file, below $root.
	 * @param string $bytes What it holds (unless 'from').
	 * @param array  $how   'from' (copy the bytes of this file) with 'size'
	 *                      (its size, read before); 'mode' (of the file);
	 *                      'exclusive' (never over a file there).
	 * @return true|WP_Error
	 */
	public static function write_file( $root, $path, $bytes, array $how = array() ) {
		$root  = rtrim( (string) $root, '/' );
		$path  = (string) $path;
		$rel   = 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : '';
		$parts = '' === $rel ? array() : explode( '/', $rel );
		if ( array() === $parts || in_array( '', $parts, true ) || in_array( '.', $parts, true ) || in_array( '..', $parts, true ) ) {
			return new WP_Error( 'ab_mcp_store_path', 'A file of the protected store is written only below its folder, by a plain path. Pass such a path.' );
		}
		$exclusive = ! empty( $how['exclusive'] );
		if ( in_array( $parts[0], self::TREE_AREAS, true ) && count( $parts ) >= 3 && ( ! $exclusive || ( count( $parts ) > 3 && ! isset( self::$building[ $root . '/' . implode( '/', array_slice( $parts, 0, 3 ) ) ] ) ) ) ) {
			return new WP_Error( 'ab_mcp_store_tree', 'A tree of the protected store is never changed: a file of a group or a tree in stage and backups is written once, and into a tree only while it is put there. Use put_tree() for a new tree instead.' );
		}
		$dir = self::way( $root, array_slice( $parts, 0, -1 ) );
		if ( null === $dir ) {
			return self::unsafe();
		}
		$tmp = self::temp_file( $root );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$calls  = self::calls();
		$failed = new WP_Error( 'ab_mcp_store_write', 'The file could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
		try {
			if ( isset( $how['from'] ) ) {
				$want    = isset( $how['size'] ) ? (int) $how['size'] : -1;
				$written = $calls->copy_into( (string) $how['from'], $tmp );
			} else {
				$want    = strlen( (string) $bytes );
				$written = $calls->put( $tmp, (string) $bytes );
			}
			if ( false === $written || (int) $written !== $want ) {
				self::delete_path( $root, $tmp );
				return $failed;
			}
			if ( isset( $how['mode'] ) && ! $calls->chmod( $tmp, (int) $how['mode'] ) ) {
				self::delete_path( $root, $tmp );
				return $failed;
			}
			$at   = $calls->stat( $root . '/' . self::TMP );
			$here = $calls->stat( $dir );
			if ( false === $at || false === $here || (int) $at['dev'] !== (int) $here['dev'] ) {
				self::delete_path( $root, $tmp );
				$folder = '' === implode( '/', array_slice( $parts, 0, -1 ) ) ? '.' : implode( '/', array_slice( $parts, 0, -1 ) );
				return new WP_Error(
					'ab_mcp_store_device',
					/* translators: %s: the folder, relative to the folder of the protected store. */
					sprintf( __( 'The folder %s of the protected store lies on another device (file system) than its folder tmp, or the device of one of them could not be read, so the file was not written: a rename between them would copy and delete instead of replacing it in one step. Ask the host to keep the folder of the store on one file system, then try again.', 'alphabridge-mcp' ), $folder ),
					array( 'folder' => $folder )
				);
			}
			if ( $exclusive && false !== $calls->lstat( $path ) ) {
				self::delete_path( $root, $tmp );
				return new WP_Error( 'ab_mcp_store_exists', 'There is a file of that name in the protected store already, and it is written once. Use another name for it.' );
			}
			if ( ! $calls->rename( $tmp, $path ) ) {
				self::delete_path( $root, $tmp );
				return $failed;
			}
		} catch ( Throwable $e ) {
			self::delete_path( $root, $tmp );
			return $failed;
		}
		unset( self::$noted[ $tmp ] );
		return true;
	}

	/**
	 * The one place that removes anything in the store (Z37): a file, a
	 * folder with what is in it (a tree, the folder of a group, the store
	 * itself), a group with its sidecar ('with'). It reads the marks of the
	 * store first (guard()), and:
	 *
	 * - what an open mark names stays: its trees and what is in them, every
	 *   folder above them, the mark and its instruction; while a mark cannot
	 *   be read nothing in stage, backups and marks goes;
	 * - in tmp/ a file goes only where it is own: this request made and
	 *   noted it, or it lies right in tmp/ with the prefix TMP_PREFIX and is
	 *   older than TMP_AGE; never by its ending ('all_tmp': uninstall());
	 * - outside the folder of the store only what this request made and
	 *   noted goes (the building folder of ensure(), the control file of the
	 *   self-check);
	 * - never through a link: a link is removed itself;
	 * - a folder goes only when all in it went ('last': names in it removed
	 *   only after every other one went; 'empty': only an empty folder).
	 *
	 * @param string $root Folder of the store (ensure(): its building folder;
	 *                     uninstall(): the parent for 'empty').
	 * @param string $path What to remove.
	 * @param array  $how  'with' (a path removed after $path where $path went
	 *                     whole), 'all_tmp', 'last' (names), 'empty'.
	 * @return bool Whether it is gone (with 'with').
	 */
	public static function delete_path( $root, $path, array $how = array() ) {
		$root = rtrim( (string) $root, '/' );
		$path = rtrim( (string) $path, '/' );
		if ( '' === $root || '' === $path || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || preg_match( '#(^|/)\.\.?(/|$)#', $root ) ) {
			return false;
		}
		$calls = self::calls();
		try {
			$guard = self::guard( $root );
		} catch ( Throwable $e ) {
			return false;
		}
		$tmp_dir = $root . '/' . self::TMP;
		$under   = static function ( $p, $dir ) {
			return $p === $dir || 0 === strpos( $p, $dir . '/' );
		};
		$remove  = static function ( $p, $top ) use ( &$remove, $root, $guard, $calls, $tmp_dir, $how, $under ) {
			$stat = $calls->lstat( $p );
			if ( false === $stat ) {
				return true;
			}
			foreach ( $guard['named'] as $named ) {
				if ( $under( $p, $named ) ) {
					return false;
				}
			}
			$noted = isset( self::$noted[ $p ] );
			if ( ! $noted && ! $under( $p, $root ) ) {
				return false;
			}
			if ( ! $noted && empty( $how['all_tmp'] ) && 0 === strpos( $p, $tmp_dir . '/' ) && ( dirname( $p ) !== $tmp_dir || 0 !== strpos( basename( $p ), self::TMP_PREFIX ) || ( time() - (int) $stat['mtime'] ) < self::TMP_AGE ) ) {
				return false;
			}
			if ( 0040000 === ( $stat['mode'] & 0170000 ) ) {
				if ( ! $top || empty( $how['empty'] ) ) {
					$names = $calls->scandir( $p );
					if ( false === $names ) {
						return false;
					}
					$names = array_values( array_diff( $names, array( '.', '..' ) ) );
					$last  = $top && isset( $how['last'] ) ? array_values( array_intersect( $names, (array) $how['last'] ) ) : array();
					$rest  = array_values( array_diff( $names, $last ) );
					// A group's sidecar after its folder, and only where the
					// folder went whole: a group with a tree a mark names
					// keeps its sidecar.
					$sides = array();
					foreach ( $rest as $entry ) {
						if ( '.json' === substr( $entry, -5 ) && in_array( substr( $entry, 0, -5 ), $rest, true ) ) {
							$sides[] = $entry;
						}
					}
					$all  = true;
					$left = array();
					foreach ( array_diff( $rest, $sides ) as $entry ) {
						if ( ! $remove( $p . '/' . $entry, false ) ) {
							$all             = false;
							$left[ $entry ] = true;
						}
					}
					foreach ( $sides as $entry ) {
						$all = ! isset( $left[ substr( $entry, 0, -5 ) ] ) && $remove( $p . '/' . $entry, false ) && $all;
					}
					if ( ! $all ) {
						return false;
					}
					foreach ( $last as $entry ) {
						$all = $remove( $p . '/' . $entry, false ) && $all;
					}
					if ( ! $all ) {
						return false;
					}
				}
				$gone = $calls->rmdir( $p );
			} else {
				$gone = $calls->unlink( $p );
			}
			if ( $gone ) {
				unset( self::$noted[ $p ] );
			}
			return $gone;
		};
		try {
			$gone = $remove( $path, true );
			if ( $gone && isset( $how['with'] ) ) {
				$gone = $remove( rtrim( (string) $how['with'], '/' ), false );
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return $gone;
	}

	/**
	 * What the marks of a folder of the store keep (delete_path()): the trees
	 * each open mark names and the folder of the mark; while a mark (or the
	 * folder of the marks, or the folder of the store) cannot be read, the
	 * areas stage and backups and the marks whole (fail closed).
	 *
	 * @param string $root Folder of the store.
	 * @return array{named:string[],unreadable:bool}
	 */
	private static function guard( $root ) {
		$calls = self::calls();
		$keep  = array( $root . '/stage', $root . '/backups', $root . '/' . self::MARKS );
		$out   = array(
			'named'      => array(),
			'unreadable' => false,
		);
		$top   = $calls->scandir( $root );
		if ( false === $top || ( in_array( self::MARKS, $top, true ) && false === ( $names = $calls->scandir( $root . '/' . self::MARKS ) ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			$out['named']      = $keep;
			$out['unreadable'] = true;
			return $out;
		}
		if ( ! in_array( self::MARKS, $top, true ) ) {
			return $out;
		}
		foreach ( $names as $mid ) {
			if ( ! preg_match( '/^[0-9a-f]{32}\z/', $mid ) ) {
				continue;
			}
			$mark = self::read_mark( $root, $mid );
			if ( false === $mark ) {
				$out['named']      = $keep;
				$out['unreadable'] = true;
				return $out;
			}
			if ( null === $mark || 'closed' === $mark['state'] ) {
				continue;
			}
			$out['named'][] = $root . '/' . self::MARKS . '/' . $mid;
			foreach ( $mark['trees'] as $tree ) {
				$out['named'][] = $root . '/' . $tree['path'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ internals */

	/**
	 * Decide where the store is (proof()), and note it as a new version of
	 * the record (commit()). The rule of a conflict lives here and only here:
	 *
	 * 1. What this request observed (a check that passed or failed, a place
	 *    that cannot be used) is kept over every attempt. Where another
	 *    request changed the record meanwhile, it is read again and this
	 *    request's observations are merged into it (merged()) and stored on
	 *    top: a failure observed here is never lost, also over a success
	 *    stored meanwhile.
	 * 2. A success counts only where its check began after the last failure
	 *    seen at that place ended (usable()); a success that began earlier
	 *    is not stored at all (merged()). A tie counts as not protected.
	 *
	 * @param object|null $fetcher   Fetcher.
	 * @param bool        $force     Self-check now.
	 * @param bool        $may_renew Whether a foreign folder may renew the id.
	 * @return array
	 */
	private static function decide( $fetcher, $force, $may_renew ) {
		// What this request observed, per place: kept over every attempt.
		$own = array();
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$id = self::install_id();
			if ( '' === $id ) {
				return self::verdict( null, 'no_id', array() );
			}
			$paths   = self::candidates( $id );
			$stored  = self::stored();
			$state   = self::state( $stored['rec'], $id );
			$docroot = self::document_root();
			if ( null === $docroot ) {
				return self::without_docroot( $state, $paths, $id );
			}
			// The bound place first, while it can be used: another place
			// would leave its groups where get() and the budgets no longer
			// look. Without a bound place, or where it cannot be used, the
			// order of decision 7.
			$order  = 'uploads' === $state['bound'] ? array( 'uploads', 'outside' ) : array( 'outside', 'uploads' );
			$seen   = self::merged( $state['seen'], $own );
			$chosen = null;
			$found  = array();
			foreach ( $order as $where ) {
				if ( ! isset( $own[ $where ] ) ) {
					// A check forced is made once: after a conflict this
					// request keeps what it observed instead.
					$own[ $where ] = self::observe( $where, $paths, $docroot, $id, $seen[ $where ], $fetcher, $force && 0 === $attempt );
					$seen          = self::merged( $state['seen'], $own );
				}
				if ( 'foreign' === $own[ $where ]['state'] && $may_renew ) {
					break;
				}
				if ( self::usable( $seen[ $where ], microtime( true ) ) ) {
					$chosen = $where;
					break;
				}
				$found[ $where ] = 'ok' !== $own[ $where ]['state'] ? $own[ $where ]['state'] : ( null !== $seen[ $where ]['fail'] ? $seen[ $where ]['fail']['reason'] : 'served' );
			}
			if ( null === $chosen && isset( $where, $own[ $where ] ) && 'foreign' === $own[ $where ]['state'] && $may_renew ) {
				// A folder of another installation at this id's place: this
				// one is a copy that kept the id. A new id, and again (once).
				self::replace_id( $id );
				self::$id  = null;
				$may_renew = false;
				$own       = array();
				continue;
			}
			$next = array(
				'id'    => $id,
				'rev'   => $state['rev'] + 1,
				'bound' => null === $chosen ? $state['bound'] : $chosen,
				'seen'  => $seen,
			);
			if ( ! self::worth( $state, $next ) || self::commit( $stored['raw'], $state, $next, $paths, $id ) ) {
				if ( null === $chosen ) {
					$fail = $seen['uploads']['fail'];
					return self::verdict( null, 'none', $found, 0, null !== $fail ? $fail['detail'] : array() );
				}
				return self::verdict( $chosen, null, $found, (int) $seen[ $chosen ]['ok']['end'], array(), self::php_of( $chosen, $seen[ $chosen ]['ok'] ) );
			}
			// Another request changed the record since it was read: read it
			// again, merge what this request observed into it, decide again.
		}
		// The conditional attempts are spent. A failure this request saw
		// wins all the same: it is stored without a condition (revoke()), as
		// a revocation may displace every older success. Only a success
		// gives up after conflicts.
		foreach ( $own as $obs ) {
			if ( isset( $obs['fail'] ) ) {
				self::revoke( $id, $paths, $own );
				$fail = self::merged( $state['seen'], $own )['uploads']['fail'];
				return self::verdict( null, 'none', $found, 0, null !== $fail ? $fail['detail'] : array() );
			}
		}
		return self::verdict( null, 'moved', array() );
	}

	/**
	 * Store the failures this request saw without a condition: on the record
	 * read afresh, with its version + 1 and its binding, under the lock of
	 * the place bound (its writers check the record under it). Only for a
	 * request whose conditional attempts are spent (decide()).
	 *
	 * @param string $id    Id.
	 * @param array  $paths candidates().
	 * @param array  $own   What this request observed, per place.
	 * @return bool Whether it was stored.
	 */
	private static function revoke( $id, array $paths, array $own ) {
		$fails = array();
		foreach ( $own as $where => $obs ) {
			if ( isset( $obs['fail'] ) ) {
				$fails[ $where ] = array( 'fail' => $obs['fail'] );
			}
		}
		$write = static function () use ( $id, $fails ) {
			$state = self::state( self::stored()['rec'], $id );
			return self::overwrite(
				array(
					'id'    => $id,
					'rev'   => $state['rev'] + 1,
					'bound' => $state['bound'],
					'seen'  => self::merged( $state['seen'], $fails ),
				)
			);
		};
		$bound = self::state( self::stored()['rec'], $id )['bound'];
		if ( null !== $bound && isset( $paths[ $bound ] ) && self::owns( $paths[ $bound ], $id ) ) {
			$done = self::locked( $paths[ $bound ], $write );
			if ( ! is_wp_error( $done ) ) {
				return $done;
			}
		}
		return $write();
	}

	/**
	 * What is known of each place, with what this request observed merged
	 * in: of each kind the youngest; a success only where its check began
	 * after the last failure seen there ended, so a success that is older
	 * than a failure another request saw is never stored.
	 *
	 * @param array $seen What the record knows, per place.
	 * @param array $own  What this request observed, per place (observe()).
	 * @return array
	 */
	private static function merged( array $seen, array $own ) {
		foreach ( $own as $where => $obs ) {
			$at = $seen[ $where ];
			if ( isset( $obs['fail'] ) && ( null === $at['fail'] || $obs['fail']['end'] > $at['fail']['end'] ) ) {
				$at['fail'] = $obs['fail'];
			}
			if ( isset( $obs['ok'] ) && ( null === $at['fail'] || $obs['ok']['start'] > $at['fail']['end'] ) && ( null === $at['ok'] || $obs['ok']['start'] > $at['ok']['start'] ) ) {
				$at['ok'] = $obs['ok'];
			}
			$seen[ $where ] = $at;
		}
		return $seen;
	}

	/**
	 * Whether a new version is worth storing: another place bound, another
	 * validity of a place, a new check of the uploads, a finding with
	 * another reason; a success or failure beside WordPress only once an
	 * hour or RETRY seconds (it is observed by every request), so a request
	 * that finds all as it was writes nothing.
	 *
	 * @param array $state state().
	 * @param array $next  The new version.
	 * @return bool
	 */
	private static function worth( array $state, array $next ) {
		if ( $state['bound'] !== $next['bound'] || $state['seen']['uploads'] != $next['seen']['uploads'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- arrays of scalars, compared by value.
			return true;
		}
		$now = microtime( true );
		$old = $state['seen']['outside'];
		$new = $next['seen']['outside'];
		if ( self::usable( $old, $now ) !== self::usable( $new, $now ) ) {
			return true;
		}
		if ( null !== $new['ok'] && ( null === $old['ok'] || ( $new['ok']['end'] - $old['ok']['end'] ) >= 3600 ) ) {
			return true;
		}
		return null !== $new['fail'] && ( null === $old['fail'] || $new['fail']['reason'] !== $old['fail']['reason'] || ( $new['fail']['end'] - $old['fail']['end'] ) >= self::RETRY );
	}

	/**
	 * A request without the document root uses only what a web request
	 * proved: the bound place with a valid proof (valid()).
	 *
	 * @param array  $state state().
	 * @param array  $paths candidates().
	 * @param string $id    Id.
	 * @return array
	 */
	private static function without_docroot( array $state, array $paths, $id ) {
		$bound = $state['bound'];
		if ( self::valid( $state, microtime( true ) ) && isset( $paths[ $bound ] ) ) {
			$path = $paths[ $bound ];
			if ( self::owns( $path, $id ) && is_writable( $path ) && ( 'outside' === $bound || array() === self::guards_missing( $path ) ) ) {
				return self::verdict( $bound, null, array(), (int) $state['seen'][ $bound ]['ok']['end'], array(), self::php_of( $bound, $state['seen'][ $bound ]['ok'] ) );
			}
		}
		return self::verdict( null, 'no_document_root', array() );
	}

	/**
	 * Store a new version of the record where the stored one is still the
	 * one read (swap()), under the lock of the place bound so far: its
	 * writers check the record under that lock (writing()), so none of them
	 * writes between the change and its end. Without a bound place, or where
	 * its folder cannot be locked (then none of its writers can write), under
	 * the lock of the new place or none.
	 *
	 * @param string|null $raw   The stored value read (null: no row).
	 * @param array       $state state() of it.
	 * @param array       $next  The new version.
	 * @param array       $paths candidates().
	 * @param string      $id    Id.
	 * @return bool Whether it was stored.
	 */
	private static function commit( $raw, array $state, array $next, array $paths, $id ) {
		$swap = static function () use ( $raw, $next ) {
			return self::swap( $raw, $next );
		};
		foreach ( array_unique( array_filter( array( $state['bound'], $next['bound'] ) ) ) as $where ) {
			if ( isset( $paths[ $where ] ) && self::owns( $paths[ $where ], $id ) ) {
				$done = self::locked( $paths[ $where ], $swap );
				if ( ! is_wp_error( $done ) ) {
					return $done;
				}
			}
		}
		return $swap();
	}

	/**
	 * Observe one place: beside WordPress, whether it can be used now; in
	 * the uploads, the folder and then, where the last check is not fresh or
	 * a guard file is missing, a self-check. A success with the time its
	 * check began and ended, a failure with the time it ended, or, in the
	 * uploads within the time of the last check, nothing new.
	 *
	 * @param string      $where   'outside' or 'uploads'.
	 * @param array       $paths   candidates().
	 * @param string      $docroot Resolved document root.
	 * @param string      $id      Id.
	 * @param array       $at      What is known of the place.
	 * @param object|null $fetcher Fetcher.
	 * @param bool        $force   Self-check now.
	 * @return array{state:string,ok?:array,fail?:array}
	 */
	private static function observe( $where, array $paths, $docroot, $id, array $at, $fetcher, $force ) {
		$start = microtime( true );
		if ( 'outside' === $where ) {
			return self::observed( self::outside( $paths['outside'], $docroot, $id ), $start, array() );
		}
		if ( ! isset( $paths['uploads'] ) ) {
			return self::observed( 'no_uploads', $start, array() );
		}
		$path = $paths['uploads'];
		// Which guard files are missing, before ensure() writes them again.
		$missing = ( file_exists( $path ) || is_link( $path ) ) ? self::guards_missing( $path ) : array_keys( self::GUARDS );
		$state   = self::ensure( $path, $id );
		if ( 'ok' !== $state ) {
			return self::observed( $state, $start, array() );
		}
		// The last check, success or failure, decides whether one is due.
		$last = null;
		if ( null !== $at['ok'] || null !== $at['fail'] ) {
			$failed = null !== $at['fail'] && ( null === $at['ok'] || $at['fail']['end'] >= $at['ok']['start'] );
			$last   = array(
				'where'   => 'uploads',
				'checked' => (int) ( $failed ? $at['fail']['end'] : $at['ok']['end'] ),
				'ok'      => ! $failed,
			);
		}
		if ( ! $force && ! self::needs_check( $last, 'uploads', time(), array() === $missing ) ) {
			return array( 'state' => self::usable( $at, microtime( true ) ) ? 'ok' : ( null !== $at['fail'] ? $at['fail']['reason'] : 'served' ) );
		}
		$result = self::self_check( $path, $fetcher );
		return self::observed( $result['ok'] ? 'ok' : (string) $result['reason'], $start, $result['detail'] );
	}

	/**
	 * An observation: a success from $start to now, or a failure ending now.
	 * A foreign folder is no finding of the place (decide() renews the id).
	 *
	 * @param string $state  'ok' or the finding.
	 * @param float  $start  When the observation began.
	 * @param array  $detail Statuses or error code of a self-check.
	 * @return array{state:string,ok?:array,fail?:array}
	 */
	private static function observed( $state, $start, array $detail ) {
		$end = microtime( true );
		if ( 'ok' === $state ) {
			return array(
				'state' => 'ok',
				'ok'    => array(
					'start'  => (float) $start,
					'end'    => $end,
					'detail' => $detail,
				),
			);
		}
		if ( 'foreign' === $state ) {
			return array( 'state' => 'foreign' );
		}
		return array(
			'state' => $state,
			'fail'  => array(
				'end'    => $end,
				'reason' => $state,
				'detail' => $detail,
			),
		);
	}

	/**
	 * The place beside WordPress: 'ok', or 'docroot', 'not_writable', 'foreign'.
	 *
	 * @param string $path    The folder for this id.
	 * @param string $docroot Resolved document root.
	 * @param string $id      Id.
	 * @return string
	 */
	private static function outside( $path, $docroot, $id ) {
		// Both parents are checked, resolved, before anything is made in them:
		// the folder beside WordPress and ab-mcp-store in it, which may be a
		// link. The folder for the id is made as a plain folder, and one that
		// is a link is not taken (owns()).
		foreach ( array( dirname( dirname( $path ) ), dirname( $path ) ) as $parent ) {
			if ( ! file_exists( $parent ) && ! is_link( $parent ) ) {
				continue;
			}
			if ( false === @realpath( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return 'not_writable';
			}
			if ( self::holds( $docroot, $parent ) ) {
				return 'docroot';
			}
		}
		return self::ensure( $path, $id );
	}

	/**
	 * The self-check of a folder in the uploads (decision 7): three test
	 * files in it and a control file beside it, each with a random content,
	 * asked without a login and past every cache; where those prove the
	 * folder, a PHP file in a folder of its own below stage and below
	 * backups as well (E4: what it prints when it runs holds the content
	 * too). The files in the folder are written through write_file(), the
	 * control file beside it, outside the store, through
	 * AB_MCP_Payload_File::write(); all are noted by this request and removed
	 * again through delete_path().
	 *
	 * @param string      $dir     The folder.
	 * @param object|null $fetcher Fetcher.
	 * @return array{ok:bool,reason:string|null,detail:array}
	 */
	private static function self_check( $dir, $fetcher ) {
		$up      = wp_upload_dir( null, false );
		$base    = untrailingslashit( (string) $up['baseurl'] );
		$token   = bin2hex( AB_MCP_Payload_File::random_bytes( 16 ) );
		$name    = 'ab-mcp-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . '.txt';
		$control = dirname( $dir ) . '/' . $name;
		$files   = array();
		foreach ( self::PROBES as $probe ) {
			$file                       = 'ab-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . $probe[1];
			$rel                        = ( '' === $probe[0] ? '' : $probe[0] . '/' ) . $file;
			$files[ $dir . '/' . $rel ] = $base . '/' . basename( $dir ) . '/' . $rel;
		}
		$php = array();
		foreach ( self::PHP_PROBES as $area ) {
			$rel                      = $area . '/ab-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . '/ab-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . '.php';
			$php[ $dir . '/' . $rel ] = $base . '/' . basename( $dir ) . '/' . $rel;
		}
		$unwritable = array(
			'ok'     => false,
			'reason' => 'not_writable',
			'detail' => array(),
		);
		$calls      = self::calls();
		try {
			self::$noted[ $control ] = true;
			if ( ! AB_MCP_Payload_File::write( $control, $token ) ) {
				return $unwritable;
			}
			foreach ( array_keys( $files ) as $file ) {
				self::$noted[ $file ] = true;
				if ( true !== self::write_file( $dir, $file, $token ) ) {
					return $unwritable;
				}
			}
			foreach ( array_keys( $php ) as $file ) {
				self::$noted[ dirname( $file ) ] = true;
				self::$noted[ $file ]            = true;
				if ( ! $calls->mkdir( dirname( $file ), 0700 ) || true !== self::write_file( $dir, $file, "<?php echo 'ab-mcp-' . 'ran-' . '" . $token . "';\n", array( 'exclusive' => true ) ) ) {
					return $unwritable;
				}
			}
			$answer = self::ask( $base . '/' . $name, $fetcher );
			$probes = array();
			if ( ! is_wp_error( $answer ) && 200 === (int) wp_remote_retrieve_response_code( $answer ) ) {
				foreach ( $files as $url ) {
					$probes[] = self::ask( $url, $fetcher );
				}
			}
			$judged = self::judge( $answer, $probes, $token );
			if ( ! $judged['ok'] ) {
				return $judged;
			}
			$answers = array();
			foreach ( $php as $url ) {
				$answers[] = self::ask( $url, $fetcher );
			}
			return self::judge( $answer, $probes, $token, $answers );
		} finally {
			foreach ( array_merge( array( $control ), array_keys( $files ), array_keys( $php ) ) as $file ) {
				self::delete_path( $dir, $file );
			}
			foreach ( array_keys( $php ) as $file ) {
				self::delete_path( $dir, dirname( $file ) );
			}
		}
	}

	/**
	 * One request of the self-check: no cookie, no login, a fresh query and
	 * headers that ask every cache for a fresh answer.
	 *
	 * @param string      $url     Address.
	 * @param object|null $fetcher Fetcher.
	 * @return array|WP_Error
	 */
	private static function ask( $url, $fetcher ) {
		$url  .= '?ab-mcp-nocache=' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) );
		$args  = array(
			'timeout'             => self::PROBE_TIMEOUT,
			'redirection'         => 3,
			'limit_response_size' => 65536,
			'headers'             => array(
				'Cache-Control' => 'no-cache, no-store, max-age=0',
				'Pragma'        => 'no-cache',
			),
			'user-agent'          => 'AlphaBridge-MCP/' . ( defined( 'AB_MCP_VERSION' ) ? AB_MCP_VERSION : '' ),
		);
		try {
			return self::calls()->ask( $url, $args, $fetcher );
		} catch ( Throwable $e ) {
			// A request that throws proves nothing (fail closed).
			return new WP_Error( 'ab_mcp_store_probe_failed', 'The request of the self-check failed. Call it again.' );
		}
	}

	/**
	 * Make the folder for an id with its manifest, guards and areas in one
	 * step, or check the one there: 'ok', 'foreign' (another installation's,
	 * or no manifest) or 'not_writable'. The first making (Z37): a building
	 * folder beside the future folder (the same parent, so one file system
	 * for the last rename), named with TMP_PREFIX; in it first the area tmp/,
	 * then the other areas, the guard files and last the ownership manifest,
	 * each file through write_file() with a temp file of that tmp/; then the
	 * one rename into place. Where that fails, delete_path() removes the
	 * building folder, made and noted by this request.
	 *
	 * @param string $path Folder.
	 * @param string $id   Id.
	 * @return string
	 */
	private static function ensure( $path, $id ) {
		if ( file_exists( $path ) || is_link( $path ) ) {
			if ( ! self::owns( $path, $id ) ) {
				return 'foreign';
			}
			return self::complete( $path ) ? 'ok' : 'not_writable';
		}
		$parent = dirname( $path );
		if ( is_link( $parent ) ? ! is_dir( $parent ) : ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) ) {
			return 'not_writable';
		}
		$calls = self::calls();
		$tmp   = $parent . '/' . self::TMP_PREFIX . basename( $path ) . '.' . bin2hex( AB_MCP_Payload_File::random_bytes( 6 ) );
		if ( ! $calls->mkdir( $tmp, 0700 ) ) {
			return 'not_writable';
		}
		self::$noted[ $tmp ] = true;
		$owner               = self::identity( $id );
		$owner['created']    = (string) time();
		try {
			// The areas and the guard files first, the manifest last: a
			// building folder left by a request that died is never this
			// installation's.
			$made = self::complete( $tmp ) && true === self::write_file( $tmp, $tmp . '/' . self::OWNER, (string) json_encode( $owner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $made && $calls->rename( $tmp, $path ) ) {
				unset( self::$noted[ $tmp ] );
				return 'ok';
			}
		} catch ( Throwable $e ) {
			// As a rename that fails: the building folder goes.
			unset( $e );
		}
		self::delete_path( $tmp, $tmp, array( 'all_tmp' => true ) );
		// Another request of this installation may have put it there first.
		if ( is_dir( $path ) ) {
			return self::owns( $path, $id ) ? ( self::complete( $path ) ? 'ok' : 'not_writable' ) : 'foreign';
		}
		return 'not_writable';
	}

	/**
	 * Put the areas and the guard files of a folder in place where they are
	 * missing: the area tmp/ first (every file of the store is written
	 * through a temp file of it, write_file()), then the areas of the groups
	 * and the folder of the marks, then the guard files; false when that
	 * fails, a part is a link, or the folder is not writable.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function complete( $dir ) {
		foreach ( array_merge( array( self::TMP ), self::AREAS, array( self::MARKS ) ) as $area ) {
			$sub = $dir . '/' . $area;
			if ( is_link( $sub ) || ( ! is_dir( $sub ) && ! self::calls()->mkdir( $sub, 0700 ) ) ) {
				return false;
			}
		}
		foreach ( self::GUARDS as $name => $content ) {
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || ( ! is_file( $file ) && true !== self::write_file( $dir, $file, $content ) ) ) {
				return false;
			}
		}
		return is_writable( $dir );
	}

	/**
	 * The guard files that are not there.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	private static function guards_missing( $dir ) {
		$out = array();
		foreach ( array_keys( self::GUARDS ) as $name ) {
			if ( is_link( $dir . '/' . $name ) || ! is_file( $dir . '/' . $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * The two places for an id.
	 *
	 * @param string $id Id.
	 * @return array{outside:string,uploads?:string}
	 */
	private static function candidates( $id ) {
		$out = array( 'outside' => dirname( untrailingslashit( ABSPATH ) ) . '/' . self::PARENT . '/' . $id );
		$up  = wp_upload_dir( null, false );
		if ( is_array( $up ) && empty( $up['error'] ) && ! empty( $up['basedir'] ) && ! empty( $up['baseurl'] ) ) {
			$out['uploads'] = untrailingslashit( (string) $up['basedir'] ) . '/' . self::UPLOADS_PREFIX . substr( hash( 'sha256', 'ab-mcp-store|' . $id ), 0, 32 );
		}
		return $out;
	}

	/**
	 * The folders of this installation that are there: by the manifest.
	 *
	 * @param string $id Id ('' for none).
	 * @return string[]
	 */
	private static function own_folders( $id ) {
		if ( '' === $id ) {
			return array();
		}
		$out = array();
		foreach ( self::candidates( $id ) as $path ) {
			if ( self::owns( $path, $id ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * The resolved document root of this request, or null where it has none.
	 *
	 * @return string|null
	 */
	private static function document_root() {
		$root = ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) ? $_SERVER['DOCUMENT_ROOT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path, compared by identity only.
		if ( '' === trim( $root ) ) {
			return null;
		}
		$real = @realpath( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return ( false === $real || ! is_dir( $real ) ) ? null : $real;
	}

	/**
	 * The proof of this request.
	 *
	 * @param string|null $where   Place, or null for none.
	 * @param string|null $reason  'no_id', 'no_document_root' or 'none'.
	 * @param array       $found   Finding per place.
	 * @param int         $checked When the proof in effect was made.
	 * @param array       $detail  Statuses or error code of the self-check.
	 * @return array
	 */
	private static function verdict( $where, $reason, array $found, $checked = 0, array $detail = array(), $php = null ) {
		return array(
			'available' => null !== $where,
			'where'     => $where,
			'checked'   => (int) $checked,
			'reason'    => $reason,
			'findings'  => $found,
			'detail'    => $detail,
			'php'       => $php,
			'message'   => null === $where ? self::message( (string) $reason, $found ) : null,
		);
	}

	/**
	 * What the proof in effect showed of a PHP file below stage and backups
	 * (E4): in the uploads true or false, null where the check that made it
	 * did not ask (a proof of an older version) or beside WordPress.
	 *
	 * @param string     $where Place.
	 * @param array|null $ok    The success of the place (seen).
	 * @return bool|null
	 */
	private static function php_of( $where, $ok ) {
		if ( 'uploads' !== $where || ! is_array( $ok ) || ! isset( $ok['detail']['php'] ) ) {
			return null;
		}
		return true === $ok['detail']['php'];
	}

	/**
	 * Why there is no store, and the way.
	 *
	 * @param string $reason Reason.
	 * @param array  $found  Finding per place.
	 * @return string
	 */
	public static function message( $reason, array $found ) {
		if ( 'no_id' === $reason ) {
			return __( 'There is no protected store, so nothing is exported, imported or taken over: the id of this installation (the option ab_mcp_install_id) could not be stored. Ask the host to check that the database user of WordPress may write to the options table, then try again.', 'alphabridge-mcp' );
		}
		if ( 'moved' === $reason ) {
			return __( 'There is no protected store in this request, so nothing is exported, imported or taken over: another request moved the store just now. Call it again.', 'alphabridge-mcp' );
		}
		if ( 'no_document_root' === $reason ) {
			return __( 'There is no protected store in this request, so nothing is exported, imported or taken over: the request does not know the document root of the web server, so it cannot check that the folder beside WordPress lies outside the web root, and no proof of a web request of the last 24 hours is there. Call it again over the web, through the MCP endpoint or in wp-admin.', 'alphabridge-mcp' );
		}
		$parts    = array();
		$findings = self::findings();
		$unusable = array(
			'outside' => __( 'Beside WordPress: the folder cannot be used.', 'alphabridge-mcp' ),
			'uploads' => __( 'In the uploads folder: the folder cannot be used.', 'alphabridge-mcp' ),
		);
		foreach ( array( 'outside', 'uploads' ) as $where ) {
			if ( isset( $found[ $where ] ) ) {
				$parts[] = $findings[ $where ][ $found[ $where ] ] ?? $unusable[ $where ];
			}
		}
		/* translators: %s: what was found at each place, one sentence per place. */
		return sprintf( __( 'There is no protected store, so nothing is exported, imported or taken over. %s Ask the host to let PHP create the folder ab-mcp-store beside the WordPress folder, outside the web root (the safe place), or to make the web server refuse the folder ab-mcp-store-… in the uploads folder (Apache and LiteSpeed read the .htaccess in it, IIS its web.config; nginx needs a rule such as location ~ /ab-mcp-store- { deny all; }); then try again.', 'alphabridge-mcp' ), implode( ' ', $parts ) );
	}

	/**
	 * The record of the proof as it is in the table (never from the option
	 * cache of this request, which another request may have changed since):
	 * its raw value, for the condition of swap(), and the value.
	 *
	 * @return array{raw:string|null,rec:mixed}
	 */
	private static function stored() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION_PROOF ) );
		return array(
			'raw' => null === $raw ? null : (string) $raw,
			'rec' => null === $raw ? null : maybe_unserialize( (string) $raw ),
		);
	}

	/**
	 * What a stored record says for an id: its version, the place bound (in
	 * use), and for each place the youngest success (when its check began
	 * and ended) and the youngest failure (when it ended, why). A record of
	 * another id binds and proves nothing, but keeps its version.
	 *
	 * @param mixed  $rec The stored value.
	 * @param string $id  Id.
	 * @return array{rev:int,bound:string|null,seen:array<string,array{ok:array|null,fail:array|null}>}
	 */
	private static function state( $rec, $id ) {
		$out = array(
			'rev'   => ( is_array( $rec ) && isset( $rec['rev'] ) ) ? max( 0, (int) $rec['rev'] ) : 0,
			'bound' => null,
			'seen'  => array(
				'outside' => array(
					'ok'   => null,
					'fail' => null,
				),
				'uploads' => array(
					'ok'   => null,
					'fail' => null,
				),
			),
		);
		if ( ! is_array( $rec ) || ! isset( $rec['id'] ) || '' === (string) $id || $id !== $rec['id'] ) {
			return $out;
		}
		$out['bound'] = ( isset( $rec['bound'] ) && in_array( $rec['bound'], array( 'outside', 'uploads' ), true ) ) ? $rec['bound'] : null;
		foreach ( array_keys( $out['seen'] ) as $where ) {
			$at = ( isset( $rec['seen'][ $where ] ) && is_array( $rec['seen'][ $where ] ) ) ? $rec['seen'][ $where ] : array();
			if ( isset( $at['ok']['start'], $at['ok']['end'] ) ) {
				$out['seen'][ $where ]['ok'] = array(
					'start'  => (float) $at['ok']['start'],
					'end'    => (float) $at['ok']['end'],
					'detail' => isset( $at['ok']['detail'] ) && is_array( $at['ok']['detail'] ) ? $at['ok']['detail'] : array(),
				);
			}
			if ( isset( $at['fail']['end'] ) ) {
				$out['seen'][ $where ]['fail'] = array(
					'end'    => (float) $at['fail']['end'],
					'reason' => isset( $at['fail']['reason'] ) ? (string) $at['fail']['reason'] : 'served',
					'detail' => isset( $at['fail']['detail'] ) && is_array( $at['fail']['detail'] ) ? $at['fail']['detail'] : array(),
				);
			}
		}
		return $out;
	}

	/**
	 * Store a version of the record without a condition: only for a
	 * revocation (revoke()), which may displace every older success.
	 *
	 * @param array $next The version.
	 * @return bool
	 */
	private static function overwrite( array $next ) {
		global $wpdb;
		$value = serialize( $next ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as update_option() stores an array.
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $value, self::OPTION_PROOF ) );
		if ( 1 !== $done ) {
			$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )", self::OPTION_PROOF, $value, self::no_autoload() ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_PROOF, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return 1 === $done;
	}

	/**
	 * Replace the stored record by a new version, only where it is still the
	 * raw value read (a conditional UPDATE by its bytes; INSERT IGNORE where
	 * there was no row): exactly one row changed, or nothing was written.
	 *
	 * @param string|null $raw  The stored value read (null: no row).
	 * @param array       $next The new version.
	 * @return bool
	 */
	private static function swap( $raw, array $next ) {
		global $wpdb;
		$value = serialize( $next ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as update_option() stores an array.
		if ( null === $raw ) {
			$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )", self::OPTION_PROOF, $value, self::no_autoload() ) );
		} else {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY )", $value, self::OPTION_PROOF, $raw ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_PROOF, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return 1 === $done;
	}

	/**
	 * The entries of an area, oldest first.
	 *
	 * @param string $root Folder.
	 * @param string $id   Id.
	 * @param string $area Area.
	 * @return array[]
	 */
	private static function entries( $root, $id, $area ) {
		$out = array();
		$dir = self::way( $root, array( $area ) );
		if ( null === $dir ) {
			return $out;
		}
		foreach ( (array) glob( $dir . '/*.json' ) as $side ) {
			$name  = basename( (string) $side, '.json' );
			$entry = preg_match( '/^[0-9a-f]{40}\z/', $name ) ? self::read_entry( (string) $side, $area ) : null;
			if ( null !== $entry && self::group_name( $area, $entry['gid'], $id ) === $name ) {
				$out[] = $entry;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['created'] <=> $b['created'];
			}
		);
		return $out;
	}

	/**
	 * The clean-up of one folder, under its lock (cleanup(), open()). Every
	 * removal goes through delete_path() (Z37): what an open mark names stays.
	 * Groups the retention lets go (kept()), sidecars this store did not write
	 * and folders without their sidecar after a day, the own temp files of
	 * writes that were cut off (tmp/, the prefix, older than TMP_AGE; never a
	 * file by its ending), closed marks a day after they closed.
	 *
	 * @param string $root Folder.
	 * @param string $id   Id.
	 * @param int    $now  Time.
	 * @return array{removed:int,kept:int}
	 */
	private static function sweep( $root, $id, $now ) {
		$open    = AB_MCP_Sync_Schema::open_runs();
		$backups = AB_MCP_Sync_Schema::backup_rows( $now );
		$removed = 0;
		$kept    = 0;
		foreach ( self::AREAS as $area ) {
			$dir = self::way( $root, array( $area ) );
			if ( null === $dir ) {
				continue;
			}
			foreach ( (array) scandir( $dir ) as $file ) {
				$file = (string) $file;
				$path = $dir . '/' . $file;
				if ( preg_match( '/^([0-9a-f]{40})\.json\z/', $file, $m ) && self::plain( $path, 'file' ) ) {
					$entry = self::read_entry( $path, $area );
					if ( null === $entry || self::group_name( $area, $entry['gid'], $id ) !== $m[1] ) {
						// Not a sidecar this store wrote: gone after a day.
						if ( ( $now - (int) @filemtime( $path ) ) >= 86400 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							self::delete_group( $root, $area, $m[1] );
						}
						continue;
					}
					if ( self::kept( $entry, $now, $open, $backups ) ) {
						if ( $entry['run_id'] > 0 && ( $now - $entry['created'] ) >= self::keep( $area ) ) {
							++$kept;
						}
						continue;
					}
					if ( self::delete_group( $root, $area, $m[1] ) ) {
						++$removed;
					}
				} elseif ( preg_match( '/^[0-9a-f]{40}\z/', $file ) && self::plain( $path, 'dir' ) && false === @lstat( $path . '.json' ) && ( $now - (int) @filemtime( $path ) ) >= 86400 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					// A folder without its sidecar (an interrupted removal).
					self::delete_path( $root, $path );
				}
			}
		}
		// The temp files of writes that were cut off: own ones only, by
		// delete_path()'s rule (tmp/, the prefix, older than TMP_AGE).
		$tmp = self::way( $root, array( self::TMP ) );
		foreach ( null === $tmp ? array() : (array) scandir( $tmp ) as $file ) {
			if ( 0 === strpos( (string) $file, self::TMP_PREFIX ) ) {
				self::delete_path( $root, $tmp . '/' . $file );
			}
		}
		// Marks closed for a day, and a folder of a mark that holds none (a
		// request that died before it wrote it) after a day.
		$marks = self::way( $root, array( self::MARKS ) );
		foreach ( null === $marks ? array() : (array) scandir( $marks ) as $mid ) {
			$mid = (string) $mid;
			if ( ! preg_match( '/^[0-9a-f]{32}\z/', $mid ) ) {
				continue;
			}
			$mark = self::read_mark( $root, $mid );
			$old  = is_array( $mark ) ? ( 'closed' === $mark['state'] && ( $now - (int) $mark['closed'] ) >= self::KEEP['marks'] ) : ( null === $mark && ( $now - (int) @filemtime( $marks . '/' . $mid ) ) >= self::KEEP['marks'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $old ) {
				self::delete_path( $root, $marks . '/' . $mid );
			}
		}
		return array(
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	/**
	 * A folder below a folder of the store, or null where the way there is
	 * not plain: the folder of the store and every step below it a real
	 * folder (lstat(): no link), and the end, resolved, under the folder of
	 * the store by device and inode (holds()). Every read, write and removal
	 * goes through it.
	 *
	 * @param string   $root  Folder of the store.
	 * @param string[] $parts Steps below it.
	 * @return string|null
	 */
	private static function way( $root, array $parts ) {
		$path = $root;
		if ( ! self::plain( $path, 'dir' ) ) {
			return null;
		}
		foreach ( $parts as $part ) {
			$path .= '/' . $part;
			if ( ! self::plain( $path, 'dir' ) ) {
				return null;
			}
		}
		return self::holds( $root, $path ) ? $path : null;
	}

	/**
	 * Whether a path is a real folder or file (lstat(): a link is neither).
	 *
	 * @param string $path Path.
	 * @param string $type 'dir' or 'file'.
	 * @return bool
	 */
	private static function plain( $path, $type ) {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $stat ) {
			return false;
		}
		return ( 'dir' === $type ? 0040000 : 0100000 ) === ( $stat['mode'] & 0170000 );
	}

	/**
	 * A group of an area with every step of the way to it plain: its sidecar,
	 * its folder and its entry, or the refusal.
	 *
	 * @param string $root Folder of the store.
	 * @param string $area Area.
	 * @param string $name Name of the group on disk.
	 * @param string $gid  Group.
	 * @return array{0:string,1:string,2:array}|WP_Error
	 */
	private static function group( $root, $area, $name, $gid ) {
		$area_dir = self::way( $root, array( $area ) );
		if ( null === $area_dir ) {
			return self::unsafe();
		}
		$side  = $area_dir . '/' . $name . '.json';
		$entry = self::plain( $side, 'file' ) ? self::read_entry( $side, $area ) : null;
		if ( null === $entry || $gid !== $entry['gid'] ) {
			return self::no_group();
		}
		$dir = self::way( $root, array( $area, $name ) );
		if ( null === $dir ) {
			return false !== @lstat( $area_dir . '/' . $name ) ? self::unsafe() : self::no_group(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return array( $side, $dir, $entry );
	}

	/**
	 * The raw bytes the files of a group hold: of a gzip file the size its
	 * trailer names (ISIZE: exact below 4 GiB, and a file holds at most
	 * FILE_MAX), of another file its size.
	 *
	 * @param string $dir Folder of the group.
	 * @return int
	 */
	private static function held( $dir ) {
		$sum = 0;
		foreach ( (array) scandir( $dir ) as $file ) {
			$path = $dir . '/' . $file;
			if ( ! self::valid_name( (string) $file ) || ! self::plain( $path, 'file' ) ) {
				continue;
			}
			$size   = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$raw    = $size;
			$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $handle ) {
				if ( $size >= 18 && "\x1f\x8b" === fread( $handle, 2 ) && 0 === fseek( $handle, -4, SEEK_END ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$isize = unpack( 'V', (string) fread( $handle, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$raw   = is_array( $isize ) ? (int) $isize[1] : $size;
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$sum += $raw;
		}
		return $sum;
	}

	/** @return WP_Error */
	private static function unsafe() {
		return new WP_Error( 'ab_mcp_store_link', 'A folder or file of the protected store is a link, or lies outside the store, so nothing there is read, written or removed. Ask the host to remove the link from the folder of the store, then try again.' );
	}

	/**
	 * Read a sidecar: the entry of a group, or null.
	 *
	 * @param string $side File.
	 * @param string $area Area it must belong to.
	 * @return array|null
	 */
	private static function read_entry( $side, $area ) {
		if ( is_link( $side ) || ! is_file( $side ) || @filesize( $side ) > 4096 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}
		$e = json_decode( (string) @file_get_contents( $side ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $e ) || ! isset( $e['format'], $e['gid'], $e['area'], $e['created'] ) || self::FORMAT !== $e['format'] || $area !== $e['area'] || ! is_string( $e['gid'] ) || ! preg_match( '/^[0-9a-f]{32}\z/', $e['gid'] ) ) {
			return null;
		}
		return array(
			'format'   => self::FORMAT,
			'gid'      => $e['gid'],
			'area'     => $area,
			'created'  => (int) $e['created'],
			'user_id'  => isset( $e['user_id'] ) ? (int) $e['user_id'] : 0,
			'run_id'   => isset( $e['run_id'] ) ? max( 0, (int) $e['run_id'] ) : 0,
			'declared' => isset( $e['declared'] ) ? max( 0, (int) $e['declared'] ) : 0,
			'used'     => isset( $e['used'] ) ? max( 0, (int) $e['used'] ) : 0,
			'label'    => isset( $e['label'] ) && is_string( $e['label'] ) ? $e['label'] : '',
			'closed'   => isset( $e['closed'] ) && true === $e['closed'],
			'binding'  => self::binding( isset( $e['binding'] ) ? $e['binding'] : '' ),
		);
	}

	/**
	 * The binding of a group as a sidecar keeps it: a text, or '' for none.
	 *
	 * @param mixed $binding Value.
	 * @return string
	 */
	private static function binding( $binding ) {
		return is_string( $binding ) ? $binding : '';
	}

	/**
	 * Write a sidecar (write_file(): through a temp file of tmp/ renamed into
	 * place).
	 *
	 * @param string $root  Folder of the store.
	 * @param string $side  File.
	 * @param array  $entry Entry.
	 * @return true|WP_Error
	 */
	private static function write_entry( $root, $side, array $entry ) {
		return self::write_file( $root, $side, (string) json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * The name of a group on disk: derived from its area, its id and the id
	 * of the installation, so the id of a group names no path.
	 *
	 * @param string $area Area.
	 * @param string $gid  Group.
	 * @param string $id   Installation.
	 * @return string 40 hex.
	 */
	private static function group_name( $area, $gid, $id ) {
		return AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, $id, '' );
	}

	/**
	 * @param mixed $area Area.
	 * @return WP_Error|null
	 */
	private static function bad_area( $area ) {
		return in_array( $area, self::AREAS, true ) ? null : new WP_Error( 'ab_mcp_store_area', 'The protected store has the areas out, in, reports, backups and stage. Use one of them.' );
	}

	/**
	 * @param mixed $area Area.
	 * @param mixed $gid  Group.
	 * @param mixed $name File name.
	 * @return WP_Error|null
	 */
	private static function bad_ref( $area, $gid, $name ) {
		$bad = self::bad_area( $area );
		if ( null !== $bad ) {
			return $bad;
		}
		if ( ! is_string( $gid ) || ! preg_match( '/^[0-9a-f]{32}\z/', $gid ) ) {
			return new WP_Error( 'ab_mcp_store_group', 'A group of the protected store is named by the 32 hex characters open() returned. Pass that id.' );
		}
		if ( ! self::valid_name( $name ) ) {
			return new WP_Error( 'ab_mcp_store_name', 'A file of the protected store is named with up to 64 of a-z, 0-9, ".", "_" and "-", starting with a letter or a digit, not ending in .tmp. Use such a name.' );
		}
		return null;
	}

	/**
	 * @param mixed $name File name.
	 * @return bool
	 */
	private static function valid_name( $name ) {
		return is_string( $name ) && preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}\z/', $name ) && false === strpos( $name, '..' ) && '.tmp' !== substr( $name, -4 );
	}

	/** @return WP_Error */
	private static function no_group() {
		return new WP_Error( 'ab_mcp_store_no_group', 'The group is not in the protected store: it was never opened here, or it was removed (packages and reports are kept a day, backups as many days as the filter ab_mcp_staging_backup_days says). Open a new one with open().' );
	}

	/**
	 * Plan the daily clean-up where it is not planned.
	 */
	private static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON );
		}
	}

	/* ------------------------------------------------------------- the id */

	/**
	 * @param string $id Id.
	 * @return bool
	 */
	private static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{32}\z/', $id );
	}

	/** @return string A new id. */
	private static function new_id() {
		return bin2hex( AB_MCP_Payload_File::random_bytes( 16 ) );
	}

	/**
	 * The stored id as it is in the table, past every cache; null without a row.
	 *
	 * @return string|null
	 */
	private static function read_id() {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION_ID ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Store an id where there is none: INSERT IGNORE, so of two first
	 * requests the second keeps the first one's.
	 *
	 * @param string $id Id.
	 */
	private static function insert_id( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )", self::OPTION_ID, $id, self::no_autoload() ) );
		self::forget_cached_id();
	}

	/**
	 * Replace an id by a new one, only where the row still holds it (so of two
	 * requests that replace it, one does).
	 *
	 * @param string $old The id found.
	 */
	private static function replace_id( $old ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS BINARY ) = %s", self::new_id(), self::OPTION_ID, $old ) );
		self::forget_cached_id();
	}

	/**
	 * The autoload value of a row that is not loaded with every request:
	 * 'off' since WordPress 6.6, 'no' before.
	 *
	 * @return string
	 */
	private static function no_autoload() {
		return function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
	}

	/**
	 * The option cache of WordPress no longer holds the id.
	 */
	private static function forget_cached_id() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_ID, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
