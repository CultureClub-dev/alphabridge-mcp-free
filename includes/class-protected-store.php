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
 * Packages and reports are kept a day, backups ab_mcp_staging_backup_days
 * days (BACKUP_DAYS here; Pro sets 30), never pushed out by the undo store;
 * a group bound to a run that is not closed is not cleaned while it is not
 * (AB_MCP_Sync_Schema::open_runs()). The clean-up runs when a group is
 * opened and once a day (CRON).
 *
 * How much (decision 8): AB_MCP_Store_Budget, counted under locked(), a
 * flock() on a file of the folder (the pattern of Pro's upload sessions).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-payload-file.php';
require_once __DIR__ . '/class-staging.php';
require_once __DIR__ . '/class-store-budget.php';
require_once __DIR__ . '/sync/class-sync-schema.php';

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
	const BACKUP_DAYS        = 7;
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

	/** The areas. */
	const AREAS = array( 'out', 'in', 'reports', 'backups' );

	/** The areas of packages, which the budgets count. */
	const PACKAGES = array( 'out', 'in' );

	/** Seconds a group is kept, by area; backups by backup_days(). */
	const KEEP = array(
		'out'     => 86400,
		'in'      => 86400,
		'reports' => 86400,
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
	 * uploads, for the message of proof().
	 */
	const FINDINGS = array(
		'outside' => array(
			'docroot'      => 'the folder ab-mcp-store beside the WordPress folder would lie inside the web root',
			'not_writable' => 'the folder ab-mcp-store beside the WordPress folder cannot be created or written, or a part of it is a link',
			'foreign'      => 'the folder for this installation there belongs to another installation',
		),
		'uploads' => array(
			'no_uploads'       => 'there is no uploads folder',
			'not_writable'     => 'the folder ab-mcp-store-… in it cannot be created or written, or a part of it is a link',
			'foreign'          => 'the folder for this installation there belongs to another installation',
			'self_unreachable' => 'this site cannot fetch its own files to check them (its address is local or private, or the request failed)',
			'control'          => 'a test file beside the folder was not served at the address of the uploads folder, so the check would prove nothing',
			'served'           => 'a test file in the folder was served: the web server does not honour its .htaccess or web.config',
		),
	);

	/** @var string|null The id read in this request. */
	private static $id = null;

	/** @var array|null The proof of this request. */
	private static $proof = null;

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
	 * @return array{available:bool,where:string|null,checked:int,reason:string|null,findings:array<string,string>,detail:array,message:string|null}
	 *         where 'outside' or 'uploads'; reason (without a store) 'no_id',
	 *         'no_document_root' or 'none' with a finding per place
	 *         (FINDINGS); detail the statuses or the error code of the
	 *         last self-check; message the reason and the way.
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
	 *
	 * @param array|WP_Error   $control The answer for the file beside the folder.
	 * @param array[]|WP_Error $probes  The answers for the three test files.
	 * @param string           $token   The content of the files.
	 * @return array{ok:bool,reason:string|null,detail:array}
	 */
	public static function judge( $control, array $probes, $token ) {
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
		return array(
			'ok'     => true,
			'reason' => null,
			'detail' => array( 'probes' => $statuses ),
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
	 *                     belongs to), 'label' (up to 191 of A-Z a-z 0-9 . _ : -).
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
		);
		$id     = self::install_id();
		$result = self::writing(
			$root,
			static function () use ( $root, $id, $entry ) {
				self::sweep( $root, $id, time() );
				if ( in_array( $entry['area'], self::PACKAGES, true ) ) {
					$refusal = AB_MCP_Store_Budget::package_refusal( array_merge( self::entries( $root, $id, 'out' ), self::entries( $root, $id, 'in' ) ), $entry['area'], $entry['user_id'], $entry['declared'] );
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
				if ( ! self::write_entry( $side, $entry ) || ! @mkdir( $area_dir . '/' . $name, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
					@unlink( $side ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
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
				$blob = AB_MCP_Payload_File::compress( $payload );
				if ( ! AB_MCP_Payload_File::write( $path, $blob ) ) {
					return new WP_Error( 'ab_mcp_store_write', 'The file could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				$entry['used'] = $used + $bytes;
				if ( ! self::write_entry( $side, $entry ) ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
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
	 * @return array[]|WP_Error Entries: gid, area, created, user_id, run_id, declared, used, label, expires.
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
				return self::write_entry( $side, $entry ) ? true : new WP_Error( 'ab_mcp_store_write', 'The group could not be bound to its run in the protected store. Ask the host to check that the folder is writable, then try again.' );
			}
		);
	}

	/**
	 * Remove a group with its files, in every folder of this installation.
	 *
	 * @param string $area Area.
	 * @param string $gid  Group.
	 * @return bool Whether a group was removed.
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
					self::remove_group( $root, $area, $name );
					return true;
				}
			) === true || $removed;
		}
		return $removed;
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
	 * area keeps groups.
	 *
	 * @param array      $entry     Entry of the group.
	 * @param int        $now       Time.
	 * @param int[]|null $open_runs AB_MCP_Sync_Schema::open_runs().
	 * @return bool
	 */
	public static function kept( array $entry, $now, $open_runs ) {
		$run = isset( $entry['run_id'] ) ? (int) $entry['run_id'] : 0;
		if ( $run > 0 && ( null === $open_runs || in_array( $run, $open_runs, true ) ) ) {
			return true;
		}
		return ( (int) $now - (int) $entry['created'] ) < self::keep( (string) $entry['area'] );
	}

	/**
	 * Seconds an area keeps a group.
	 *
	 * @param string $area Area.
	 * @return int
	 */
	public static function keep( $area ) {
		return 'backups' === $area ? self::backup_days() * 86400 : ( isset( self::KEEP[ $area ] ) ? self::KEEP[ $area ] : 86400 );
	}

	/**
	 * Days the backups of a run are kept: BACKUP_DAYS, or what the filter
	 * ab_mcp_staging_backup_days says (1 to 3650).
	 *
	 * @return int
	 */
	public static function backup_days() {
		return max( 1, min( 3650, (int) apply_filters( self::BACKUP_DAYS_FILTER, self::BACKUP_DAYS ) ) );
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
	 * Uninstall: the folders of this installation, each only where its
	 * ownership manifest is this installation's, the parent beside WordPress
	 * when nothing else is in it, and the daily event. The options go with
	 * the list of uninstall.php.
	 */
	public static function uninstall() {
		$id = self::install_id( false );
		foreach ( self::own_folders( $id ) as $root ) {
			self::remove_tree( $root );
		}
		$parent = dirname( untrailingslashit( ABSPATH ) ) . '/' . self::PARENT;
		if ( '' !== $id && is_dir( $parent ) && ! is_link( $parent ) ) {
			@rmdir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only when empty: another installation's folder keeps it.
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
		self::forget();
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
				return self::verdict( $chosen, null, $found, (int) $seen[ $chosen ]['ok']['end'] );
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
				return self::verdict( $bound, null, array(), (int) $state['seen'][ $bound ]['ok']['end'] );
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
	 * asked without a login and past every cache, then removed again.
	 *
	 * @param string      $dir     The folder.
	 * @param object|null $fetcher Fetcher.
	 * @return array{ok:bool,reason:string|null,detail:array}
	 */
	private static function self_check( $dir, $fetcher ) {
		$up    = wp_upload_dir( null, false );
		$base  = untrailingslashit( (string) $up['baseurl'] );
		$token = bin2hex( AB_MCP_Payload_File::random_bytes( 16 ) );
		$name  = 'ab-mcp-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . '.txt';
		$files = array( dirname( $dir ) . '/' . $name => $base . '/' . $name );
		foreach ( self::PROBES as $probe ) {
			$file   = 'ab-probe-' . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) ) . $probe[1];
			$rel    = ( '' === $probe[0] ? '' : $probe[0] . '/' ) . $file;
			$files[ $dir . '/' . $rel ] = $base . '/' . basename( $dir ) . '/' . $rel;
		}
		try {
			foreach ( array_keys( $files ) as $file ) {
				if ( ! AB_MCP_Payload_File::write( $file, $token ) ) {
					return array(
						'ok'     => false,
						'reason' => 'not_writable',
						'detail' => array(),
					);
				}
			}
			$urls    = array_values( $files );
			$control = self::ask( array_shift( $urls ), $fetcher );
			$probes  = array();
			if ( ! is_wp_error( $control ) && 200 === (int) wp_remote_retrieve_response_code( $control ) ) {
				foreach ( $urls as $url ) {
					$probes[] = self::ask( $url, $fetcher );
				}
			}
			return self::judge( $control, $probes, $token );
		} finally {
			foreach ( array_keys( $files ) as $file ) {
				if ( is_file( $file ) && ! is_link( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
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
		return is_object( $fetcher ) ? $fetcher->get( $url, $args ) : AB_MCP_Outbound::get( $url, $args );
	}

	/**
	 * Make the folder for an id with its manifest, guards and areas in one
	 * step (a temp folder renamed into place), or check the one there:
	 * 'ok', 'foreign' (another installation's, or no manifest) or
	 * 'not_writable'.
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
		$tmp = $parent . '/.' . basename( $path ) . '.' . bin2hex( AB_MCP_Payload_File::random_bytes( 6 ) ) . '.tmp';
		if ( ! @mkdir( $tmp, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return 'not_writable';
		}
		$owner = self::identity( $id );
		$owner['created'] = (string) time();
		$made  = AB_MCP_Payload_File::write( $tmp . '/' . self::OWNER, (string) json_encode( $owner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) && self::complete( $tmp );
		if ( $made && @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return 'ok';
		}
		self::remove_tree( $tmp );
		// Another request of this installation may have put it there first.
		if ( is_dir( $path ) ) {
			return self::owns( $path, $id ) ? ( self::complete( $path ) ? 'ok' : 'not_writable' ) : 'foreign';
		}
		return 'not_writable';
	}

	/**
	 * Put the areas and the guard files of a folder in place where they are
	 * missing; false when that fails, a part is a link, or the folder is not
	 * writable.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function complete( $dir ) {
		foreach ( self::AREAS as $area ) {
			$sub = $dir . '/' . $area;
			if ( is_link( $sub ) || ( ! is_dir( $sub ) && ! @mkdir( $sub, 0700 ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				return false;
			}
		}
		foreach ( self::GUARDS as $name => $content ) {
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || ( ! is_file( $file ) && ! AB_MCP_Payload_File::write( $file, $content ) ) ) {
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
	private static function verdict( $where, $reason, array $found, $checked = 0, array $detail = array() ) {
		return array(
			'available' => null !== $where,
			'where'     => $where,
			'checked'   => (int) $checked,
			'reason'    => $reason,
			'findings'  => $found,
			'detail'    => $detail,
			'message'   => null === $where ? self::message( (string) $reason, $found ) : null,
		);
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
			return 'There is no protected store, so nothing is exported, imported or taken over: the id of this installation (the option ab_mcp_install_id) could not be stored. Ask the host to check that the database user of WordPress may write to the options table, then try again.';
		}
		if ( 'moved' === $reason ) {
			return 'There is no protected store in this request, so nothing is exported, imported or taken over: another request moved the store just now. Call it again.';
		}
		if ( 'no_document_root' === $reason ) {
			return 'There is no protected store in this request, so nothing is exported, imported or taken over: the request does not know the document root of the web server, so it cannot check that the folder beside WordPress lies outside the web root, and no proof of a web request of the last 24 hours is there. Call it again over the web, through the MCP endpoint or in wp-admin.';
		}
		$parts = array();
		foreach ( array( 'outside' => 'Beside WordPress', 'uploads' => 'In the uploads folder' ) as $where => $label ) {
			if ( isset( $found[ $where ] ) ) {
				$parts[] = $label . ' ' . ( isset( self::FINDINGS[ $where ][ $found[ $where ] ] ) ? self::FINDINGS[ $where ][ $found[ $where ] ] : 'the folder cannot be used' ) . '.';
			}
		}
		return 'There is no protected store, so nothing is exported, imported or taken over. ' . implode( ' ', $parts ) . ' Ask the host to let PHP create the folder ab-mcp-store beside the WordPress folder, outside the web root (the safe place), or to make the web server refuse the folder ab-mcp-store-… in the uploads folder (Apache and LiteSpeed read the .htaccess in it, IIS its web.config; nginx needs a rule such as location ~ /ab-mcp-store- { deny all; }); then try again.';
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
	 * The clean-up of one folder, under its lock (cleanup(), open()).
	 *
	 * @param string $root Folder.
	 * @param string $id   Id.
	 * @param int    $now  Time.
	 * @return array{removed:int,kept:int}
	 */
	private static function sweep( $root, $id, $now ) {
		$open    = AB_MCP_Sync_Schema::open_runs();
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
							self::remove_group( $root, $area, $m[1] );
						}
						continue;
					}
					if ( self::kept( $entry, $now, $open ) ) {
						if ( $entry['run_id'] > 0 && ( $now - $entry['created'] ) >= self::keep( $area ) ) {
							++$kept;
						}
						continue;
					}
					self::remove_group( $root, $area, $m[1] );
					++$removed;
				} elseif ( preg_match( '/^[0-9a-f]{40}\z/', $file ) && self::plain( $path, 'dir' ) && false === @lstat( $path . '.json' ) && ( $now - (int) @filemtime( $path ) ) >= 86400 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					// A folder without its sidecar (an interrupted removal).
					self::remove_tree( $path );
				} elseif ( '.tmp' === substr( $file, -4 ) ) {
					self::drop_old_tmp( $path, $now );
				}
			}
		}
		foreach ( (array) scandir( $root ) as $file ) {
			if ( '.tmp' === substr( (string) $file, -4 ) ) {
				self::drop_old_tmp( $root . '/' . $file, $now );
			}
		}
		return array(
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	/**
	 * A temp file of an interrupted write, older than TMP_AGE, goes.
	 *
	 * @param string $path File.
	 * @param int    $now  Time.
	 */
	private static function drop_old_tmp( $path, $now ) {
		if ( ( is_file( $path ) || is_link( $path ) ) && ( $now - (int) @filemtime( $path ) ) >= self::TMP_AGE ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Remove a group: its files and folder, the sidecar last.
	 *
	 * @param string $root Folder.
	 * @param string $area Area.
	 * @param string $name Name of the group on disk (40 hex).
	 */
	private static function remove_group( $root, $area, $name ) {
		$dir = self::way( $root, array( $area ) );
		if ( null === $dir ) {
			return;
		}
		// The group's folder only where it is a real folder under the area:
		// a link there is removed itself, never followed.
		$group = $dir . '/' . $name;
		if ( null !== self::way( $root, array( $area, $name ) ) ) {
			self::remove_tree( $group );
		} elseif ( is_link( $group ) ) {
			@unlink( $group ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$side = $dir . '/' . $name . '.json';
		if ( false !== @lstat( $side ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $side ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
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
	 * Remove a folder and what is in it, never through a link: a link is
	 * removed itself, never what it points to.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function remove_tree( $dir ) {
		if ( is_link( $dir ) || ( file_exists( $dir ) && ! is_dir( $dir ) ) ) {
			return @unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::remove_tree( $dir . '/' . $entry );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
		);
	}

	/**
	 * Write a sidecar (through a temp file renamed into place).
	 *
	 * @param string $side  File.
	 * @param array  $entry Entry.
	 * @return bool
	 */
	private static function write_entry( $side, array $entry ) {
		return AB_MCP_Payload_File::write( $side, (string) json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
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
		return in_array( $area, self::AREAS, true ) ? null : new WP_Error( 'ab_mcp_store_area', 'The protected store has the areas out, in, reports and backups. Use one of them.' );
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
