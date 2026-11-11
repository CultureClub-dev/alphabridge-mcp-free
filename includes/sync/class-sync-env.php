<?php
/**
 * The environment of a site for the report (Z8, decision 14 of the plan of
 * 01.11.2026, «Lesende Bestandsaufnahme der übrigen Arten»; branch 7): what
 * the takeover does not read as objects, read on both sides and compared by
 * what is known of it, never by its contents.
 *
 * WHAT IS READ, one line each (LINES says the fields of each):
 *
 *  - core: the version of WordPress, the language of the site (the stored
 *    row of WPLANG, past the filter of get_locale());
 *  - core_files (Z8b, E18): the core files of this site checked against the
 *    checksums of api.wordpress.org for its version and language
 *    (AB_MCP_Sync_Core_Compare): how many were checked, differ, are missing,
 *    or why not compared;
 *  - the units of code and files (Z8b, E1; UNITS, each a kind of line): a
 *    folder or a plugin of one file under the folder of the plugins
 *    (plugin), a folder of the standard root of the themes (theme), a file
 *    or folder at the top of the must-use folder (mu_plugin; the loader of
 *    AlphaBridge is group U), a language file (language), a file of the
 *    fonts (font), a file of the uploads at their top level or under a year
 *    folder outside the file lists of the attachments (upload). Each is read
 *    whole by AB_MCP_Sync_File_Manifest::unit() (every file by its path,
 *    size and SHA-256, the hash of the unit over them, ab-files/1), with its
 *    version and main files and which of them run (a plugin), its version (a
 *    theme), whether it is AlphaBridge itself (own, E12: shown, never
 *    taken over, not hashed); the files of a folder follow in lines of their
 *    own (entries, PER_LINE files a line). A theme of a further root of
 *    themes (register_theme_directory()) is locked (code:theme_root), not
 *    read (theme_root, E2);
 *  - theme_choice: which theme runs (the stored rows of stylesheet and
 *    template), a unit of its own (E2);
 *  - dropin: each drop-in of a single site (DROPINS) that is there, with its
 *    SHA-256 and size;
 *  - host (group U of the concept): the versions of PHP and of the database,
 *    whether an external object cache runs, the WordPress block of the
 *    .htaccess by its SHA-256, the must-use loader of AlphaBridge by its
 *    SHA-256, and the constants of CONSTANTS, none of which holds a secret
 *    (never a value of a key, a salt, a password or of the access to the
 *    database: NEVER, and no name AB_MCP_Redactor redacts, the names of the
 *    filter ab_mcp_redact_keys included; a constant of the staging site
 *    this site does not show with its value not shown, held_here());
 *  - table: each table with the prefix of the site that neither the core
 *    nor the takeover covers (the tables of $wpdb and of AB_MCP_Sync_Schema),
 *    by its name without the prefix, with its rows (COUNT) and the SHA-256 of
 *    SHOW CREATE TABLE with AUTO_INCREMENT and the prefix of the names of
 *    tables and constraints taken out, never of a column or an index
 *    (schema()); tables
 *    (one line) says whether the list was read whole. Nothing else of a
 *    table is read;
 *  - files: the root of ROOTS, counted and summed (files, bytes), never
 *    read: uploads, what of them is no unit (the folders at their top level
 *    other than the year folders, the files of the attachments counted
 *    apart; fonts and the folders of AlphaBridge left out). A link is not
 *    followed and counted as skipped; a folder that cannot be read makes its
 *    root incomplete (fail closed).
 *
 * Left out of the units (E1), as no unit: the .htaccess and .user.ini of the
 * top of the uploads, the fonts (a root of their own), the folders of
 * AlphaBridge in the uploads (the store, undo); everything else under a root
 * of units is a unit or in one, with no list of names left out within a
 * unit.
 *
 * IN PARTS. read() goes on from a state within a budget of seconds and lines:
 * the lines of the site at once, then the check of the core, the tables one
 * by one, the units of each kind one by one (each whole within one call:
 * a unit that does not end in time is read anew by the next call, and is too
 * large only where it had a whole call, UNIT_BUDGET), then the root file by
 * file, in one order (order(): the folders of a root depth first, the names
 * of a folder by strcmp()), so the next call goes on after the last unit or
 * path it read. Each part begins with a line page (walk: one random id
 * for all parts of one reading, seq, last, files: the format of the
 * manifests), by which the production site holds the packages of the
 * staging site to one whole series.
 *
 * NAMES. A name of the site (a plugin, a theme, a must-use plugin, a
 * table: NAMED) goes, whole, through the check the import runs over the
 * names of a line of the inventory (held(): a field of a credential written
 * into it, or the name of one right before = or :, whatever follows, also
 * encoded, by the rules of the site with ab_mcp_redact_keys); one it holds
 * is in a line by its SHA-256 only, with why (held), and so is a name that
 * is no text a line holds (no UTF-8, a control character, longer than
 * MAX_TEXT). A name the staging site holds
 * is held in the lines of this site as well before the report stores them
 * (held_names(), held_there()). The place a part ends at (the state: the
 * cursor of wp_site_export, a part of a report) holds no name and no part
 * of one: the SHA-256 of each folder its path goes through and of the whole
 * path (place()), by which the next part finds it again among the names
 * where it lies (found()); one gone since leaves its root not counted to its
 * end, the tables not read whole (Codex, round 2 of branch 7, A1).
 *
 * WHAT IT SAYS (compare()): an entry per line of either side. A unit (and
 * the theme that runs) is decided by AB_MCP_Sync_Decision::unit_item() from
 * its lines and its pair: equal, differs with the paths, only on one side,
 * not compared, the states of a pair by its parts (E2), code:live_changed;
 * a drop-in is equal or differs by its SHA-256; the core files equal where
 * both sides are as shipped, else they differ by how many files; a value of
 * group U, the version of WordPress and the language show both values,
 * same_value where they are the same (what lies behind a value is not
 * compared), else differs; what is only counted or summed (tables with the
 * same rows and schema, the root with the same count and size) is
 * «counted, contents not compared», with the stage of the comparison of
 * their contents, never equal. Nothing of it is taken over in this version:
 * code, files and tables are locked with their reason and stage (LOCKS),
 * group U, the drop-ins and the core are changed at the host (action host,
 * whatever the result). What could not be read is not compared, with why
 * (fail closed). No hash and no value of a file in an entry; a held name by
 * its SHA-256 with held.
 *
 * Refusals and errors are gettext texts in the language of the site, each
 * naming the way; codes, states, kinds and parameters stay as the tools
 * name them. The words of the report (reasons of a lock, the why of a
 * decision, the words of the environment) stay English: they are compared
 * across the two sites and go into the hashes of the report and the plan.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-media.php';
require_once __DIR__ . '/class-sync-schema.php';
require_once __DIR__ . '/class-sync-manifest.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-net.php';
require_once __DIR__ . '/class-sync-file-manifest.php';
require_once __DIR__ . '/class-sync-core-compare.php';
require_once dirname( __DIR__ ) . '/class-redactor.php';

/**
 * Class AB_MCP_Sync_Env
 */
final class AB_MCP_Sync_Env {

	/** The steps of a reading, in order. */
	const STEPS = array( 'site', 'core', 'tables', 'plugin', 'theme', 'theme_root', 'mu_plugin', 'language', 'font', 'upload', 'uploads' );

	/** The roots of files, counted and summed. */
	const ROOTS = array( 'uploads' );

	/** The kinds of units (E1), each a kind of line and the kind of its pair. */
	const UNITS = array( 'plugin', 'theme', 'mu_plugin', 'language', 'font', 'upload' );

	/** The fields of the line of a unit. */
	const UNIT = array(
		'active'   => 'list?',
		'bytes'    => 'count',
		'complete' => 'bool',
		'files'    => 'count',
		'form'     => 'text',
		'hash'     => 'hash?',
		'lock'     => 'text?',
		'mains'    => 'list?',
		'mtime'    => 'count?',
		'own'      => 'bool',
		'sha256'   => 'hash?',
		'version'  => 'text?',
		'cause'    => 'text?',
	);

	/** The forms of a unit: a folder, a file, what is neither (locked), AlphaBridge itself, a root not read. */
	const FORMS = array( 'folder', 'file', 'other', 'own', 'root' );

	/** Why a unit was not read whole (AB_MCP_Sync_File_Manifest::WHY and the root). */
	const UNIT_WHY = array( 'unreadable', 'changed', 'too_large', 'too_many', 'link', 'vcs', 'nonfile', 'theme_root', 'too_many_lines', 'unknown', 'by_code', 'not_a_folder', 'attachments_unreadable', 'attachments_too_many', 'moved' );

	/** Files of a unit in one line of entries at most. */
	const PER_LINE = 500;

	/** Seconds a call must have had for a unit that does not end in it to be too large (a call with less leaves it to the next). */
	const UNIT_BUDGET = 30;

	/** Names at the top of the uploads that are no unit (E1). */
	const UPLOADS_TOP = array( '.htaccess', '.user.ini' );

	/**
	 * The fields of each kind of line besides env and ref, with their types:
	 * text (any text), text? (or null), bool, bool?, count (a whole number
	 * from 0), count?, hash? (64 hex or null), hex32, scalar? (text, whole
	 * number, bool or null), list? (a list of texts, or null), entries (a
	 * list of files: path, size, SHA-256, mtime).
	 */
	const LINES = array(
		'page'       => array(
			'files' => 'text',
			'last'  => 'bool',
			'seq'   => 'count',
			'walk'  => 'hex32',
		),
		'core'       => array( 'value' => 'text?' ),
		'core_files' => array(
			'cause'    => 'text?',
			'checked'  => 'count',
			'complete' => 'bool',
			'differs'  => 'count',
			'missing'  => 'count',
		),
		'plugin'     => self::UNIT,
		'theme'      => self::UNIT,
		'mu_plugin'  => self::UNIT,
		'language'   => self::UNIT,
		'font'       => self::UNIT,
		'upload'     => self::UNIT,
		'entries'    => array(
			'entries' => 'entries',
			'seq'     => 'count',
			'unit'    => 'text',
		),
		'theme_choice' => array(
			'stylesheet' => 'text?',
			'template'   => 'text?',
		),
		'dropin'    => array(
			'bytes'  => 'count?',
			'sha256' => 'hash?',
		),
		'host'      => array(
			'defined' => 'bool',
			'value'   => 'scalar?',
		),
		'table'     => array(
			'rows'   => 'count?',
			'schema' => 'hash?',
		),
		'tables'    => array(
			'complete' => 'bool',
			'count'    => 'count',
		),
		'files'     => array(
			'attached' => 'count',
			'bytes'    => 'count',
			'complete' => 'bool',
			'files'    => 'count',
			'skipped'  => 'count',
			'why'      => 'text?',
		),
	);

	/** The refs of core. */
	const CORE = array( 'wordpress', 'locale' );

	/**
	 * The kinds of lines whose ref is a name of the site (a file, a folder,
	 * a table): checked for a credential (held()), held by its SHA-256 then.
	 */
	const NAMED = array( 'plugin', 'theme', 'mu_plugin', 'language', 'font', 'upload', 'table' );

	/**
	 * Why a name is held (held(); the codes of AB_MCP_Sync_Import for a name
	 * of a line): a field of a credential is written into it, or it does not
	 * read to its end, so nothing says what it holds.
	 */
	const HELD = array( 'credential_field', 'unreadable_name' );

	/** The form of a name held or too long for a line: its SHA-256. */
	const HASHED = '/^sha256:[0-9a-f]{64}\z/';

	/** The refs of group U besides the constants. */
	const HOST = array( 'php', 'database', 'object_cache', 'htaccess', 'mu_loader' );

	/** The drop-ins of a single site (_get_dropins() of WordPress 7.1.3). */
	const DROPINS = array( 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php' );

	/** The must-use loader of AlphaBridge (AB_MCP_Preview_Protection::LOADER_FILE): group U, not a must-use plugin. */
	const LOADER = 'alphabridge-preview-protection.php';

	/**
	 * The constants group U shows: how the site runs, none a secret. A
	 * text that is not a short plain word (a path, an address) is shown by
	 * its SHA-256 only, so both sides compare without showing it.
	 */
	const CONSTANTS = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_CACHE', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS', 'COMPRESS_CSS', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS', 'MEDIA_TRASH', 'IMAGE_EDIT_OVERWRITE', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISALLOW_UNFILTERED_HTML', 'ALLOW_UNFILTERED_UPLOADS', 'FORCE_SSL_ADMIN', 'WP_HTTP_BLOCK_EXTERNAL', 'WP_ENVIRONMENT_TYPE', 'WP_DEVELOPMENT_MODE', 'WP_DEFAULT_THEME', 'WP_DISABLE_FATAL_ERROR_HANDLER', 'DB_CHARSET', 'DB_COLLATE', 'FS_METHOD', 'WP_ALLOW_MULTISITE' );

	/**
	 * Constants never shown, whatever a list says: the access to the
	 * database, the keys and salts of wp-config.php, the salt of a cache.
	 */
	const NEVER = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'WP_CACHE_KEY_SALT', 'COOKIEHASH' );

	/**
	 * The words of SHOW CREATE TABLE right before a name that has the prefix
	 * of the site (schema()): CREATE TABLE, CONSTRAINT, REFERENCES.
	 */
	const SCHEMA_NAMES = array( 'TABLE', 'CONSTRAINT', 'REFERENCES' );

	/** A text of a constant shown as it is. */
	const PLAIN = '/^[A-Za-z0-9_.\-]{0,64}\z/';

	/** The folders of AlphaBridge in the uploads, left out of its count. */
	const OWN_UPLOADS = array( 'ab-mcp-undo' );

	/** The beginning of the name of the protected store in the uploads (AB_MCP_Protected_Store::UPLOADS_PREFIX). */
	const OWN_UPLOADS_PREFIX = 'ab-mcp-store-';

	/** Paths of the file lists of attachments held at most to count the uploads. */
	const MAX_ATTACHED = 500000;

	/** Characters of a text in a line at most (a version, a value). */
	const MAX_TEXT = 1024;

	/** Entries of the walk between two looks at the clock. */
	const TICK = 64;

	/**
	 * What is locked of each kind of line, with the art of the concept, the
	 * stage that brings it and why. The drop-ins and group U are not locked
	 * but changed at the host; the language is shown only (the option WPLANG
	 * is compared with the options).
	 */
	const LOCKS = array(
		'plugin'          => array(
			'key'    => 'env:plugin',
			'art'    => 'A11',
			'stage'  => 'Z8b',
			'reason' => 'A plugin is code: its files are compared by their contents and whether it runs by its main files; it is taken over with the code of the site.',
		),
		'theme'           => array(
			'key'    => 'env:theme',
			'art'    => 'A11',
			'stage'  => 'Z8b',
			'reason' => 'A theme is code: its files are compared by their contents; it is taken over with the code of the site.',
		),
		'theme_choice'    => array(
			'key'    => 'env:theme_choice',
			'art'    => 'A9',
			'stage'  => 'Z8b',
			'reason' => 'Which theme runs is a phase of the code: it is taken over with the code of the site.',
		),
		'mu_plugin'       => array(
			'key'    => 'env:mu_plugin',
			'art'    => 'A21',
			'stage'  => 'Z8b',
			'reason' => 'A must-use file or folder is compared by the contents of its files and taken over with the files of the site.',
		),
		'language'        => array(
			'key'    => 'env:languages',
			'art'    => 'A21',
			'stage'  => 'Z8b',
			'reason' => 'A language file is compared by its contents and taken over with the files of the site.',
		),
		'font'            => array(
			'key'    => 'env:fonts',
			'art'    => 'A20',
			'stage'  => 'Z8b',
			'reason' => 'A file of the font library is compared by its contents; it comes with the fonts of the font library.',
		),
		'upload'          => array(
			'key'    => 'env:upload_files',
			'art'    => 'A22',
			'stage'  => 'Z8b',
			'reason' => 'A file of the uploads outside the file lists of attachments is compared by its contents and taken over with the files of the site.',
		),
		'table'           => array(
			'key'    => 'env:table',
			'art'    => 'A16',
			'stage'  => 'Z8d',
			'reason' => 'A table of a plugin without an adapter: shown by its rows and schema, its rows are not read; taken over only through an adapter, or as a whole on a confirmation per table.',
		),
		'files:uploads'   => array(
			'key'    => 'env:uploads',
			'art'    => 'A22',
			'stage'  => 'Z8b',
			'reason' => 'The folders at the top of uploads other than the year folders (outside the file lists of attachments) are counted and summed, their contents not compared; they are classified with the files of the site.',
		),
	);

	/**
	 * Locks of a unit that no stage lifts (stage Z8: a person resolves them),
	 * each with its way.
	 */
	const CODE_LOCKS = array(
		'code:live_extra'  => array(
			'stage'  => 'Z8',
			'reason' => 'The unit holds on this site what no manifest holds (a folder .git, .svn or .hg, a symbolic link, or something that is no regular file): it is never taken over. Remove it on this site (or take the unit over by hand), then make a new report.',
		),
		'code:link'        => array(
			'stage'  => 'Z8',
			'reason' => 'The unit holds on the staging site what no manifest holds (a folder .git, .svn or .hg, a symbolic link, or something that is no regular file): it is never taken over. Remove it on the staging site (or take the unit over by hand), then make a new report.',
		),
		'code:theme_root'  => array(
			'stage'  => 'Z8',
			'reason' => 'The theme lies in a further root of themes (register_theme_directory()), not in the folder of the themes: it is never taken over. Take it over by hand.',
		),
		'code:own'         => array(
			'stage'  => 'Z8',
			'reason' => 'AlphaBridge itself is shown, never taken over: bring both sites to the same version of AlphaBridge (wp-admin, Plugins → Update), then make a new report.',
		),
		'code:live_changed' => array(
			'stage'  => 'Z8',
			'reason' => 'The files of the unit changed on this site since the base of its pair, and on the staging site as well: the files written on this site would leave their folder, so there is no choice «staging wins». Bring both sides to the same version (the same files), then make a new report. A plugin that keeps writing into its own folder (logs, caches, backups) changes again after every write: update it by hand in wp-admin (Plugins → Update) instead.',
		),
	);

	/** What each result says. */
	const WORDS = array(
		'equal'        => 'Equal on both sides, by the SHA-256 of the file.',
		'core_equal'   => 'Equal on both sides: the core files of each site are those api.wordpress.org gives for its version and language.',
		'core_differs' => 'Differs: core files of a site are not those api.wordpress.org gives for its version and language. WordPress itself is never taken over: install it anew at the host or in wp-admin (Dashboard → Updates → Re-install).',
		'same_value'   => 'The same value on both sides, shown only: what lies behind it (how the host builds and sets up PHP, the database or the server) is not compared.',
		'differs'      => 'Differs between the sides.',
		'counted'      => 'Counted, contents not compared: the same version, rows or count on both sides says nothing about the contents.',
		'only_staging' => 'Only on staging.',
		'only_live'    => 'Only on this site.',
		'not_compared' => 'Not compared: one side could not be read whole.',
	);

	/** What the action host says. */
	const HOST_WORDS = 'Change at the host: nothing of this is taken over.';

	/** The order of the kinds of entries. */
	const ORDER = array( 'environment', 'core', 'core_files', 'plugin', 'theme', 'mu_plugin', 'language', 'font', 'upload', 'theme_choice', 'dropin', 'host', 'table', 'files' );

	/** The clock of the budget; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/* ------------------------------------------------------------ reading */

	/**
	 * Read the environment of this site from a state, as far as a budget of
	 * seconds and lines reaches. The first line is the page.
	 *
	 * @param array|null $state  The state of the part before (null: begin).
	 * @param int|float  $budget Seconds.
	 * @param int        $max    Lines at most (the page and one more at least).
	 * @param string     $side   live (this is the production site: wp_site_diff)
	 *                           or staging (wp_site_export): which lock a unit
	 *                           that holds what no manifest holds gets (E1).
	 * @return array{lines:array[],state:array|null}|WP_Error state null: done.
	 */
	public static function read( $state, $budget, $max = 4000, $side = 'live' ) {
		if ( null === $state ) {
			try {
				$walk = bin2hex( random_bytes( 16 ) );
			} catch ( Throwable $e ) {
				return new WP_Error( 'ab_mcp_sync_env_random', __( 'The environment was not read: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.', 'alphabridge-mcp' ) );
			}
			$state = self::fresh( $walk, 1 );
		} elseif ( ! self::state_shaped( $state ) ) {
			return new WP_Error( 'ab_mcp_sync_env_state', __( 'The place to go on from in the environment is not one this site wrote. Begin anew: call wp_site_export with purpose=env without a cursor, or wp_site_diff with the packages again.', 'alphabridge-mcp' ) );
		}
		$deadline = self::now() + (float) $budget;
		$max      = max( 2, (int) $max );
		$lines    = array();
		// The first unit of this call is too large where it does not end in
		// it, if the call had a whole budget; else the next call reads it.
		$first = (float) $budget >= self::UNIT_BUDGET;
		while ( $state['step'] < count( self::STEPS ) ) {
			$step = self::STEPS[ $state['step'] ];
			if ( 'site' === $step ) {
				$lines = array_merge( $lines, self::site_lines() );
				$state = self::next( $state );
			} elseif ( 'core' === $step ) {
				$check = AB_MCP_Sync_Core_Compare::check( $deadline );
				if ( null === $check ) {
					break;
				}
				$lines[] = self::line( 'core_files', 'files', $check );
				$state   = self::next( $state );
			} elseif ( 'tables' === $step ) {
				$read  = self::table_lines( $state['after'], $deadline, $max - 1 - count( $lines ) );
				$lines = array_merge( $lines, $read['lines'] );
				if ( ! $read['done'] ) {
					$state['after'] = $read['after'];
					break;
				}
				$state = self::next( $state );
			} elseif ( in_array( $step, self::UNITS, true ) || 'theme_root' === $step ) {
				$read  = self::unit_step( $step, $state['after'], $deadline, $max - 1 - count( $lines ), $side, $first );
				$lines = array_merge( $lines, $read['lines'] );
				if ( ! $read['done'] ) {
					$state['after'] = $read['after'];
					break;
				}
				$state = self::next( $state );
			} else {
				$read = self::root( $step, $state, $deadline );
				if ( ! $read['done'] ) {
					$state = $read['state'];
					break;
				}
				$lines[] = $read['line'];
				$state   = self::next( $state );
			}
			if ( self::now() >= $deadline || count( $lines ) + 1 >= $max ) {
				break;
			}
		}
		$done = $state['step'] >= count( self::STEPS );
		array_unshift(
			$lines,
			array(
				'env'   => 'page',
				'files' => AB_MCP_Sync_File_Manifest::FORMAT,
				'last'  => $done,
				'ref'   => 'page',
				'seq'   => (int) $state['seq'],
				'walk'  => (string) $state['walk'],
			)
		);
		if ( $done ) {
			return array(
				'lines' => $lines,
				'state' => null,
			);
		}
		$state['seq'] = (int) $state['seq'] + 1;
		return array(
			'lines' => $lines,
			'state' => $state,
		);
	}

	/**
	 * A state at the beginning of a step.
	 *
	 * @param string $walk Walk.
	 * @param int    $seq  Part.
	 * @param int    $step Step.
	 * @return array
	 */
	private static function fresh( $walk, $seq, $step = 0 ) {
		return array(
			'after'    => null,
			'attached' => 0,
			'bytes'    => 0,
			'files'    => 0,
			'seq'      => (int) $seq,
			'skipped'  => 0,
			'step'     => (int) $step,
			'walk'     => (string) $walk,
		);
	}

	/**
	 * The state at the beginning of the next step, in the same part.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function next( array $state ) {
		return self::fresh( $state['walk'], $state['seq'], $state['step'] + 1 );
	}

	/**
	 * Whether a state is one read() writes.
	 *
	 * @param mixed $state State.
	 * @return bool
	 */
	public static function state_shaped( $state ) {
		if ( ! is_array( $state ) ) {
			return false;
		}
		$keys = array_keys( $state );
		sort( $keys );
		if ( array( 'after', 'attached', 'bytes', 'files', 'seq', 'skipped', 'step', 'walk' ) !== $keys ) {
			return false;
		}
		foreach ( array( 'attached', 'bytes', 'files', 'skipped' ) as $count ) {
			if ( ! is_int( $state[ $count ] ) || $state[ $count ] < 0 ) {
				return false;
			}
		}
		return is_int( $state['seq'] ) && $state['seq'] >= 1
			&& is_int( $state['step'] ) && $state['step'] >= 0 && $state['step'] < count( self::STEPS )
			&& is_string( $state['walk'] ) && 1 === preg_match( '/^[0-9a-f]{32}\z/', $state['walk'] )
			&& ( null === $state['after'] || self::place_shaped( $state['after'] ) );
	}

	/**
	 * Whether a place is one place() writes: a list of SHA-256, 64 hex each.
	 *
	 * @param mixed $place Place.
	 * @return bool
	 */
	private static function place_shaped( $place ) {
		if ( ! is_array( $place ) || array() === $place || ! array_is_list( $place ) ) {
			return false;
		}
		foreach ( $place as $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $hash ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The place a part ended at, as a state keeps it (after): no name and no
	 * part of one, only the SHA-256 of each folder the path goes through and,
	 * last, of the whole path (of a table: of its whole name), so that no
	 * state (the cursor of wp_site_export, a part of a report) carries a name
	 * in any spelling, a credential written into it or not (Codex, rounds 1
	 * and 2 of branch 7, A1 and C5). One SHA-256 per folder, so that the next
	 * part finds it again folder by folder (found()), without reading again
	 * what the parts before counted.
	 *
	 * @param string|null $path  A path of a root, or the name of a table.
	 * @param bool        $split Whether it is a path (through its folders),
	 *                           not the name of a table («/» in it too).
	 * @return string[]|null
	 */
	public static function place( $path, $split = true ) {
		if ( null === $path ) {
			return null;
		}
		$out = array();
		$at  = null;
		foreach ( $split ? explode( '/', (string) $path ) : array( (string) $path ) as $part ) {
			$at    = null === $at ? $part : $at . '/' . $part;
			$out[] = hash( 'sha256', $at );
		}
		return $out;
	}

	/**
	 * The path a place names, found again folder by folder: in the folder of
	 * the path found so far, the name whose path has the next SHA-256; null
	 * where one is no longer there (gone, renamed, moved).
	 *
	 * @param string[] $place place().
	 * @param callable $names function( string $path ): string[]|null, the
	 *                        names in the folder of a path ('' the root).
	 * @return string|null
	 */
	public static function found( array $place, callable $names ) {
		$path = null;
		foreach ( $place as $hash ) {
			$hit = null;
			foreach ( (array) $names( (string) $path ) as $name ) {
				$at = null === $path ? (string) $name : $path . '/' . $name;
				if ( hash_equals( (string) $hash, hash( 'sha256', $at ) ) ) {
					$hit = $at;
					break;
				}
			}
			if ( null === $hit ) {
				return null;
			}
			$path = $hit;
		}
		return $path;
	}

	/* ------------------------------------------------------ the lines of the site */

	/**
	 * The lines of core, plugins, themes, must-use plugins, drop-ins and
	 * group U.
	 *
	 * @return array[]
	 */
	public static function site_lines() {
		$lines = array();
		$wordpress = AB_MCP_Sync_Manifest::wordpress();
		$lines[]   = self::line( 'core', 'wordpress', array( 'value' => '' === $wordpress ? null : $wordpress ) );
		$lines[] = self::line( 'core', 'locale', array( 'value' => self::locale() ) );
		$lines[] = self::theme_choice();
		$content = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : '';
		foreach ( self::DROPINS as $name ) {
			$path = $content . '/' . $name;
			if ( '' !== $content && file_exists( $path ) ) {
				$lines[] = self::line( 'dropin', $name, self::file_hash( $path ) );
			}
		}
		foreach ( self::host() as $ref => $value ) {
			$lines[] = self::line(
				'host',
				$ref,
				array(
					'defined' => true,
					'value'   => $value,
				)
			);
		}
		foreach ( self::constants() as $name => $shown ) {
			$lines[] = self::line( 'host', 'constant:' . $name, $shown );
		}
		return $lines;
	}

	/**
	 * The language of the site as stored (WPLANG; empty is en_US), past the
	 * filter of get_locale(); null where the row could not be read.
	 *
	 * @return string|null
	 */
	public static function locale() {
		$row = AB_MCP_Sync_Rows::option( 'WPLANG' );
		if ( null === $row ) {
			return null;
		}
		$value = false === $row ? '' : $row['value'];
		return '' === $value ? 'en_US' : self::text( $value, null );
	}

	/**
	 * The plugins in a folder as get_plugins() finds them: each PHP file of
	 * its top level and of its folders one level down whose head names a
	 * plugin (Plugin Name), by its path relative to the folder, with the
	 * version its head names (null without one); no name beginning with a dot.
	 *
	 * @param string $dir The folder of the plugins.
	 * @return array<string,string|null>
	 */
	public static function plugin_files( $dir ) {
		$out = array();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( (array) self::names( $dir ) as $name ) {
			if ( '.' === $name[0] ) {
				continue;
			}
			$inner = is_dir( $dir . '/' . $name ) ? (array) self::names( $dir . '/' . $name ) : array( null );
			foreach ( $inner as $file ) {
				$rel = null === $file ? $name : $name . '/' . $file;
				// head() reads files only: a folder named *.php names no plugin.
				if ( ( null !== $file && '.' === $file[0] ) || '.php' !== substr( $rel, -4 ) ) {
					continue;
				}
				$head = self::head( $dir . '/' . $rel, array( 'Plugin Name', 'Version' ) );
				if ( '' !== $head['Plugin Name'] ) {
					$out[ $rel ] = '' === $head['Version'] ? null : self::name( $head['Version'] );
				}
			}
		}
		return $out;
	}

	/**
	 * The themes in the folders of themes as search_theme_directories()
	 * finds them: each folder with a style.css, else each folder in it with
	 * one (one level down), by its path relative to its root, with the
	 * version the head of style.css names (null without one); no name
	 * beginning with a dot. A stylesheet in two roots counts once, from the
	 * first.
	 *
	 * @param string[] $roots The folders of themes.
	 * @return array<string,string|null>
	 */
	public static function theme_dirs( array $roots ) {
		$out = array();
		foreach ( $roots as $root ) {
			$root = rtrim( $root, '/' );
			foreach ( is_dir( $root ) ? (array) self::names( $root ) : array() as $name ) {
				if ( '.' === $name[0] || ! is_dir( $root . '/' . $name ) ) {
					continue;
				}
				$found = is_file( $root . '/' . $name . '/style.css' ) ? array( $name ) : array();
				if ( array() === $found ) {
					foreach ( (array) self::names( $root . '/' . $name ) as $sub ) {
						if ( '.' !== $sub[0] && is_file( $root . '/' . $name . '/' . $sub . '/style.css' ) ) {
							$found[] = $name . '/' . $sub;
						}
					}
				}
				foreach ( $found as $stylesheet ) {
					if ( ! array_key_exists( $stylesheet, $out ) ) {
						$version            = self::head( $root . '/' . $stylesheet . '/style.css', array( 'Version' ) )['Version'];
						$out[ $stylesheet ] = '' === $version ? null : self::name( $version );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * The headers of a file as get_file_data() reads them: its first 8 KiB,
	 * each header the first line that names it, without the end of a comment
	 * or of PHP; '' for a header it does not name.
	 *
	 * @param string   $path    File.
	 * @param string[] $headers Names.
	 * @return array<string,string>
	 */
	public static function head( $path, array $headers ) {
		$text = is_file( $path ) && is_readable( $path ) ? @file_get_contents( $path, false, null, 0, 8192 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the head of a local file.
		$text = str_replace( "\r", "\n", is_string( $text ) ? $text : '' );
		$out  = array();
		foreach ( $headers as $header ) {
			$out[ $header ] = 1 === preg_match( '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $text, $m ) ? trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) ) : '';
		}
		return $out;
	}

	/**
	 * A stored option that is a list of texts, unpacked without classes;
	 * null where it cannot be read as one.
	 *
	 * @param string $name Option.
	 * @return string[]|null
	 */
	private static function stored_list( $name ) {
		$row = AB_MCP_Sync_Rows::option( $name );
		if ( false === $row ) {
			return array();
		}
		if ( null === $row ) {
			return null;
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		if ( ! $read['ok'] ) {
			return null;
		}
		if ( '' === $read['value'] ) {
			return array();
		}
		if ( ! is_array( $read['value'] ) ) {
			return null;
		}
		return array_values( array_map( 'strval', array_filter( $read['value'], 'is_scalar' ) ) );
	}

	/**
	 * The SHA-256 and size of a file, null where it cannot be read.
	 *
	 * @param string $path Path.
	 * @return array{bytes:int|null,sha256:string|null}
	 */
	public static function file_hash( $path ) {
		$sha  = is_file( $path ) && is_readable( $path ) ? @hash_file( 'sha256', $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that cannot be read is not compared.
		$size = is_string( $sha ) ? @filesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array(
			'bytes'  => is_int( $size ) ? $size : null,
			'sha256' => is_string( $sha ) && is_int( $size ) ? $sha : null,
		);
	}

	/**
	 * Group U but the constants: the versions of PHP and of the database,
	 * whether an external object cache runs, the WordPress block of the
	 * .htaccess and the must-use loader of AlphaBridge by their SHA-256
	 * (absent where there is none; null where it cannot be read).
	 *
	 * @return array<string,mixed>
	 */
	private static function host() {
		global $wpdb;
		$database = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$info     = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : null;
			$database = is_string( $info ) && '' !== $info ? self::text( $info, null ) : null;
		}
		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR . '/' . self::LOADER : '';
		$loader = '' === $mu || ! file_exists( $mu ) ? 'absent' : self::file_hash( $mu )['sha256'];
		return array(
			'php'          => PHP_VERSION,
			'database'     => $database,
			'object_cache' => (bool) wp_using_ext_object_cache(),
			'htaccess'     => self::htaccess(),
			'mu_loader'    => $loader,
		);
	}

	/**
	 * The WordPress block of the .htaccess beside WordPress, by its SHA-256:
	 * absent without the file, no_block without the block; null where the
	 * file cannot be read or the site lives in another folder than
	 * WordPress (get_home_path() would ask the script of the request); home
	 * and siteurl as stored, past their filters.
	 *
	 * @return string|null
	 */
	public static function htaccess() {
		$paths = array();
		foreach ( array( 'home', 'siteurl' ) as $option ) {
			$row = AB_MCP_Sync_Rows::option( $option );
			if ( ! is_array( $row ) ) {
				return null;
			}
			$paths[] = rtrim( (string) wp_parse_url( $row['value'], PHP_URL_PATH ), '/' );
		}
		if ( $paths[0] !== $paths[1] ) {
			return null;
		}
		$path = ABSPATH . '.htaccess';
		if ( ! file_exists( $path ) ) {
			return 'absent';
		}
		$text = is_file( $path ) && is_readable( $path ) ? @file_get_contents( $path, false, null, 0, 1048577 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, read up to a limit.
		if ( ! is_string( $text ) || strlen( $text ) > 1048576 ) {
			return null;
		}
		$block = self::block( $text );
		return null === $block ? 'no_block' : hash( 'sha256', $block );
	}

	/**
	 * The lines between # BEGIN WordPress and # END WordPress, as
	 * extract_from_markers() takes them (each line without its end, joined by
	 * a line feed); null without the block.
	 *
	 * @param string $text The file.
	 * @return string|null
	 */
	public static function block( $text ) {
		$inside = false;
		$out    = array();
		foreach ( explode( "\n", (string) $text ) as $line ) {
			$line = rtrim( $line, "\r" );
			if ( $inside && false !== strpos( $line, '# END WordPress' ) ) {
				return implode( "\n", $out );
			}
			if ( $inside ) {
				$out[] = $line;
			} elseif ( false !== strpos( $line, '# BEGIN WordPress' ) ) {
				$inside = true;
			}
		}
		return null;
	}

	/**
	 * The constants of CONSTANTS this site shows, each defined or not with
	 * its value (shown()); none of NEVER and none whose name AB_MCP_Redactor
	 * redacts (the filter ab_mcp_redact_keys included).
	 *
	 * @return array<string,array{defined:bool,value:mixed}>
	 */
	public static function constants() {
		$extra = AB_MCP_Redactor::extra_keys();
		$out   = array();
		foreach ( self::CONSTANTS as $name ) {
			if ( ! self::shown( $name, $extra ) ) {
				continue;
			}
			$out[ $name ] = array(
				'defined' => defined( $name ),
				'value'   => defined( $name ) ? self::constant_value( constant( $name ) ) : null,
			);
		}
		return $out;
	}

	/**
	 * Whether a constant may be shown: not of NEVER, not a name of a
	 * credential.
	 *
	 * @param string   $name  Constant.
	 * @param string[] $extra AB_MCP_Redactor::extra_keys().
	 * @return bool
	 */
	public static function shown( $name, array $extra ) {
		return ! in_array( strtoupper( (string) $name ), self::NEVER, true ) && ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );
	}

	/**
	 * The value of a constant as a line holds it: a whole number, a bool or
	 * null as it is; a float as its text; a short plain text (PLAIN) as it
	 * is; any other text or value by its SHA-256 only (sha256:…).
	 *
	 * @param mixed $value Value.
	 * @return string|int|bool|null
	 */
	public static function constant_value( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( self::PLAIN, $value ) ) {
			return $value;
		}
		return 'sha256:' . hash( 'sha256', is_string( $value ) ? $value : AB_MCP_Sync_Canon::encode( $value ) );
	}

	/* ----------------------------------------------------------- units */

	/**
	 * The line of the theme that runs (E2: a unit of its own): the stored
	 * rows of stylesheet and template, each a name of the site (by its
	 * SHA-256 where a credential holds it, held()); null where a row cannot
	 * be read or holds none.
	 *
	 * @return array
	 */
	public static function theme_choice() {
		$out = array();
		foreach ( array( 'stylesheet', 'template' ) as $option ) {
			$row            = AB_MCP_Sync_Rows::option( $option );
			$value          = is_array( $row ) && is_string( $row['value'] ) && '' !== $row['value'] ? $row['value'] : null;
			$out[ $option ] = null === $value ? null : self::path_name( $value );
		}
		return self::line( 'theme_choice', 'theme_choice', $out );
	}

	/**
	 * One step of units (UNITS, and theme_root): the units of a kind one by
	 * one after the place the part before ended at, each read whole
	 * (read_unit()), with its line and the lines of its entries, as far as the
	 * clock and the room reach. A unit that does not end in time, or whose
	 * lines do not fit, is read anew by the next call (where it had the call
	 * to itself: too large).
	 *
	 * @param string     $kind     A kind of UNITS, or theme_root.
	 * @param array|null $place    Where the part before ended (place()).
	 * @param float      $deadline Until when.
	 * @param int        $room     Lines at most.
	 * @param string     $side     live or staging.
	 * @param bool       $first    No unit read yet in this call, with a whole budget (cleared here).
	 * @return array{lines:array[],done:bool,after:array|null}
	 */
	private static function unit_step( $kind, $place, $deadline, $room, $side, &$first ) {
		$alone = $first;
		if ( 'theme_root' === $kind ) {
			$lines = self::theme_roots();
			if ( count( $lines ) > $room && ! $alone ) {
				return array(
					'lines' => array(),
					'done'  => false,
					'after' => $place,
				);
			}
			return array(
				'lines' => array_slice( $lines, 0, max( 0, (int) $room ) ),
				'done'  => true,
				'after' => null,
			);
		}
		$base = self::unit_base( $kind );
		if ( '' !== $base['why'] ) {
			return self::units_done( array( self::root_unit( $kind, $base['why'] ) ) );
		}
		$dir = $base['dir'];
		if ( ! is_dir( $dir ) ) {
			// No folder of this kind on this site: no units (a site without a
			// must-use folder), or its place is no folder.
			return self::units_done( file_exists( $dir ) || is_link( $dir ) ? array( self::root_unit( $kind, 'not_a_folder' ) ) : array() );
		}
		$after = null === $place ? null : self::found(
			$place,
			static function ( $rel ) use ( $dir ) {
				return self::names( '' === $rel ? $dir : $dir . '/' . $rel );
			}
		);
		if ( null !== $place && null === $after ) {
			return self::units_done( array( self::root_unit( $kind, 'moved' ) ) );
		}
		$ctx = self::unit_context( $kind, $dir, $deadline );
		if ( is_string( $ctx ) ) {
			return self::units_done( array( self::root_unit( $kind, $ctx ) ) );
		}
		$lines = array();
		$last  = $after;
		foreach ( self::candidates( $kind, $dir, $after, $ctx ) as $candidate ) {
			list( $name, $flag ) = $candidate;
			if ( ( array() !== $lines || ! $alone ) && self::now() >= $deadline ) {
				return self::units_stop( $lines, $last );
			}
			$read = self::read_unit( $kind, $dir, $name, $flag, $side, $deadline, $first );
			if ( $read['stopped'] ) {
				return self::units_stop( $lines, $last );
			}
			$of = self::unit_lines( $kind, $name, $read, $ctx );
			if ( count( $lines ) + count( $of ) > $room ) {
				if ( array() !== $lines || ! $alone ) {
					return self::units_stop( $lines, $last );
				}
				// Alone in a part its lines do not fit: no entries, no hash.
				$read['complete'] = false;
				$read['hash']     = null;
				$read['cause']    = 'too_many_lines';
				$of               = array_slice( self::unit_lines( $kind, $name, $read, $ctx ), 0, 1 );
			}
			$lines = array_merge( $lines, $of );
			$last  = $name;
			$first = false;
			$alone = false;
		}
		return self::units_done( $lines );
	}

	/**
	 * @param array[] $lines Lines.
	 * @return array{lines:array[],done:bool,after:null} A step of units read to its end.
	 */
	private static function units_done( array $lines ) {
		return array(
			'lines' => $lines,
			'done'  => true,
			'after' => null,
		);
	}

	/**
	 * @param array[]     $lines Lines.
	 * @param string|null $last  The last unit read.
	 * @return array{lines:array[],done:bool,after:array|null} A step of units that goes on after $last.
	 */
	private static function units_stop( array $lines, $last ) {
		return array(
			'lines' => $lines,
			'done'  => false,
			'after' => self::place( $last ),
		);
	}

	/**
	 * The folder of a kind of units, or why it is not read: the folder of
	 * the plugins, the standard root of the themes, the must-use folder, the
	 * languages, the fonts, the uploads (root_dir()).
	 *
	 * @param string $kind A kind of UNITS.
	 * @return array{dir:string,why:string}
	 */
	public static function unit_base( $kind ) {
		$constant = array(
			'plugin'    => 'WP_PLUGIN_DIR',
			'mu_plugin' => 'WPMU_PLUGIN_DIR',
		);
		if ( isset( $constant[ $kind ] ) ) {
			return defined( $constant[ $kind ] ) ? array( 'dir' => rtrim( (string) constant( $constant[ $kind ] ), '/' ), 'why' => '' ) : array( 'dir' => '', 'why' => 'unknown' );
		}
		if ( 'theme' === $kind ) {
			return defined( 'WP_CONTENT_DIR' ) ? array( 'dir' => rtrim( (string) WP_CONTENT_DIR, '/' ) . '/themes', 'why' => '' ) : array( 'dir' => '', 'why' => 'unknown' );
		}
		$root = array(
			'language' => 'languages',
			'font'     => 'fonts',
			'upload'   => 'uploads',
		);
		return self::root_dir( $root[ $kind ] );
	}

	/**
	 * What the units of a kind need besides their files, read once a step:
	 * the main files of the plugins with their versions and which run; the
	 * versions of the themes; the paths of the file lists of the attachments
	 * (the uploads). A text where it cannot be read (why).
	 *
	 * @param string $kind     Kind.
	 * @param string $dir      Its folder.
	 * @param float  $deadline Until when.
	 * @return array|string
	 */
	private static function unit_context( $kind, $dir, $deadline ) {
		if ( 'plugin' === $kind ) {
			return array(
				'mains'  => self::plugin_files( $dir ),
				'active' => self::stored_list( 'active_plugins' ),
			);
		}
		if ( 'theme' === $kind ) {
			return array( 'versions' => self::theme_dirs( array( $dir ) ) );
		}
		if ( 'upload' === $kind ) {
			$known = self::attached( $deadline );
			return is_string( $known ) ? $known : array( 'attached' => $known );
		}
		return array();
	}

	/**
	 * The units of a kind in the order of the walk (order()), after a path:
	 * each with what it is (unit, unreadable: a folder below the root that
	 * cannot be read, vcs: a folder of version control below a root of files).
	 *
	 * @param string      $kind  Kind.
	 * @param string      $dir   Its folder.
	 * @param string|null $after The last unit the part before read.
	 * @param array       $ctx   unit_context().
	 * @return Generator<array{0:string,1:string}>
	 */
	private static function candidates( $kind, $dir, $after, array $ctx ) {
		if ( in_array( $kind, array( 'plugin', 'theme', 'mu_plugin' ), true ) ) {
			foreach ( (array) self::names( $dir ) as $name ) {
				if ( null !== $after && strcmp( $name, $after ) <= 0 ) {
					continue;
				}
				$full = $dir . '/' . $name;
				if ( 'mu_plugin' === $kind ) {
					if ( self::LOADER !== $name ) {
						yield array( $name, 'unit' );
					}
					continue;
				}
				// Plugins and themes as WordPress finds them: no name beginning
				// with a dot; a folder (or a link); a plugin of one file by the
				// head that names it.
				if ( '.' === $name[0] ) {
					continue;
				}
				if ( is_link( $full ) || is_dir( $full ) || ( 'plugin' === $kind && isset( $ctx['mains'][ $name ] ) ) ) {
					yield array( $name, 'unit' );
				}
			}
			return;
		}
		$pick = static function ( $rel, $name, $full ) use ( $kind, $ctx ) {
			if ( 'upload' === $kind && '' === $rel ) {
				if ( in_array( $name, self::UPLOADS_TOP, true ) || 'fonts' === $name || in_array( $name, self::OWN_UPLOADS, true ) || 0 === strpos( $name, self::OWN_UPLOADS_PREFIX ) ) {
					return 'skip';
				}
				if ( is_dir( $full ) && ! is_link( $full ) ) {
					// The year folders hold units; the other folders are counted (uploads).
					return 1 === preg_match( '/^[0-9]{4}\z/', $name ) ? 'into' : 'skip';
				}
				return 'unit';
			}
			if ( is_dir( $full ) && ! is_link( $full ) ) {
				return in_array( $name, AB_MCP_Sync_File_Manifest::VCS, true ) ? 'vcs' : 'into';
			}
			$path = '' === $rel ? $name : $rel . '/' . $name;
			return 'upload' === $kind && isset( $ctx['attached'][ $path ] ) ? 'skip' : 'unit';
		};
		yield from self::nested( $dir, '', $after, $pick );
	}

	/**
	 * The units below one folder of a root of files, depth first in order(),
	 * after a path.
	 *
	 * @param string      $base  The root.
	 * @param string      $rel   This folder ('' the root).
	 * @param string|null $after The last unit the part before read.
	 * @param callable    $pick  function( $rel, $name, $full ): unit, into, vcs or skip.
	 * @return Generator<array{0:string,1:string}>
	 */
	private static function nested( $base, $rel, $after, callable $pick ) {
		$dir   = '' === $rel ? $base : $base . '/' . $rel;
		$names = self::names( $dir );
		if ( null === $names ) {
			if ( '' !== $rel && ( null === $after || self::order( $rel, $after ) > 0 ) ) {
				yield array( $rel, 'unreadable' );
			}
			return;
		}
		foreach ( $names as $name ) {
			$path = '' === $rel ? $name : $rel . '/' . $name;
			$into = null;
			if ( null !== $after ) {
				if ( 0 === strpos( $after, $path . '/' ) ) {
					$into = $after;
				} elseif ( self::order( $path, $after ) <= 0 ) {
					continue;
				}
			}
			$what = $pick( $rel, $name, $base . '/' . $path );
			if ( 'skip' === $what ) {
				continue;
			}
			if ( 'into' === $what ) {
				yield from self::nested( $base, $path, $into, $pick );
				continue;
			}
			yield array( $path, $what );
		}
	}

	/**
	 * Read one unit: AlphaBridge itself shown, not hashed (own, E12); a folder
	 * below a root that cannot be read, or one of version control there,
	 * without a hash; any other through AB_MCP_Sync_File_Manifest::unit().
	 *
	 * @param string $kind     Kind.
	 * @param string $dir      Its folder.
	 * @param string $name     The unit.
	 * @param string $flag     What candidates() said of it.
	 * @param string $side     live or staging.
	 * @param float  $deadline Until when.
	 * @param bool   $first    See unit_step().
	 * @return array As AB_MCP_Sync_File_Manifest::unit(), with own.
	 */
	private static function read_unit( $kind, $dir, $name, $flag, $side, $deadline, $first ) {
		$none = array(
			'form'     => 'other',
			'complete' => false,
			'stopped'  => false,
			'cause'    => null,
			'lock'     => null,
			'hash'     => null,
			'files'    => 0,
			'bytes'    => 0,
			'entries'  => array(),
			'own'      => false,
		);
		if ( in_array( $kind, array( 'plugin', 'mu_plugin' ), true ) && self::own( $dir . '/' . $name ) ) {
			return array(
				'form'     => 'own',
				'complete' => true,
				'own'      => true,
			) + $none;
		}
		if ( 'unreadable' === $flag ) {
			return array( 'cause' => 'unreadable' ) + $none;
		}
		if ( 'vcs' === $flag ) {
			return array(
				'complete' => true,
				'lock'     => AB_MCP_Sync_File_Manifest::LOCK_BY_SIDE[ 'live' === $side ? 'live' : 'staging' ],
				'cause'    => 'vcs',
			) + $none;
		}
		return array( 'own' => false ) + AB_MCP_Sync_File_Manifest::unit( $dir, $name, $side, $deadline, $first );
	}

	/**
	 * Whether a path is AlphaBridge itself (Free, Pro, the owner drop-in), by
	 * its identity (AB_MCP_Own_Files::own_file_refusal(), E12).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function own( $path ) {
		return class_exists( 'AB_MCP_Own_Files' ) && is_wp_error( AB_MCP_Own_Files::own_file_refusal( (string) $path ) );
	}

	/**
	 * The lines of a unit: its line (named(): by its SHA-256 where its name
	 * holds a credential), then, for a folder read whole under a name a line
	 * holds as it is, its files PER_LINE a line (entries), each path by its
	 * SHA-256 where a credential holds it.
	 *
	 * @param string $kind Kind.
	 * @param string $name The unit.
	 * @param array  $read read_unit().
	 * @param array  $ctx  unit_context().
	 * @return array[]
	 */
	private static function unit_lines( $kind, $name, array $read, array $ctx ) {
		$file   = 'file' === $read['form'] && $read['complete'] && null !== $read['hash'] && isset( $read['entries'][0] ) ? $read['entries'][0] : null;
		$fields = array(
			'active'   => null,
			'bytes'    => (int) $read['bytes'],
			'complete' => (bool) $read['complete'],
			'files'    => (int) $read['files'],
			'form'     => (string) $read['form'],
			'hash'     => $read['hash'],
			'lock'     => $read['lock'],
			'mains'    => null,
			'mtime'    => null === $file ? null : (int) $file[3],
			'own'      => (bool) $read['own'],
			'sha256'   => null === $file ? null : (string) $file[2],
			'version'  => null,
			'cause'    => $read['cause'],
		);
		if ( 'plugin' === $kind ) {
			$mains = array();
			foreach ( $ctx['mains'] as $rel => $version ) {
				$rel = (string) $rel;
				if ( $rel === $name ) {
					$mains[ $rel ] = $version;
				} elseif ( 0 === strpos( $rel, $name . '/' ) ) {
					$mains[ (string) substr( $rel, strlen( $name ) + 1 ) ] = $version;
				}
			}
			ksort( $mains, SORT_STRING );
			$fields['mains']   = array_map( array( __CLASS__, 'path_name' ), array_map( 'strval', array_keys( $mains ) ) );
			$fields['version'] = array() === $mains ? null : reset( $mains );
			if ( null !== $ctx['active'] ) {
				$active = array();
				foreach ( array_keys( $mains ) as $main ) {
					$file_of = (string) $main === $name ? $name : $name . '/' . $main;
					if ( in_array( $file_of, $ctx['active'], true ) ) {
						$active[] = self::path_name( (string) $main );
					}
				}
				$fields['active'] = $active;
			}
		} elseif ( 'theme' === $kind ) {
			foreach ( $ctx['versions'] as $stylesheet => $version ) {
				if ( (string) $stylesheet === $name || 0 === strpos( (string) $stylesheet, $name . '/' ) ) {
					$fields['version'] = $version;
					break;
				}
			}
		}
		$unit  = self::named( $kind, $name, $fields );
		$lines = array( $unit );
		if ( isset( $unit['held'] ) || $unit['ref'] !== $name || 'folder' !== $read['form'] || ! $read['complete'] || null === $read['hash'] ) {
			return $lines;
		}
		foreach ( array_chunk( $read['entries'], self::PER_LINE ) as $i => $chunk ) {
			$entries = array();
			foreach ( $chunk as $entry ) {
				$entries[] = array( self::path_name( (string) $entry[0] ), (int) $entry[1], (string) $entry[2], (int) $entry[3] );
			}
			$lines[] = self::line(
				'entries',
				$kind . ':' . $name . '#' . ( $i + 1 ),
				array(
					'entries' => $entries,
					'seq'     => $i + 1,
					'unit'    => $kind . ':' . $name,
				)
			);
		}
		return $lines;
	}

	/**
	 * The line of a kind of units whose folder was not read (form root, ref
	 * *), with why: so that what was not read shows (fail closed).
	 *
	 * @param string $kind Kind.
	 * @param string $why  A code of UNIT_WHY.
	 * @return array
	 */
	private static function root_unit( $kind, $why ) {
		return self::line(
			$kind,
			'*',
			array(
				'active'   => null,
				'bytes'    => 0,
				'complete' => false,
				'files'    => 0,
				'form'     => 'root',
				'hash'     => null,
				'lock'     => null,
				'mains'    => null,
				'mtime'    => null,
				'own'      => false,
				'sha256'   => null,
				'version'  => null,
				'cause'    => (string) $why,
			)
		);
	}

	/**
	 * The lines of the themes of the further roots of themes
	 * (register_theme_directory(), $GLOBALS['wp_theme_directories'] besides
	 * the standard root), each locked (code:theme_root, E2), not read; one
	 * whose stylesheet the standard root has as well is that one.
	 *
	 * @return array[]
	 */
	private static function theme_roots() {
		$standard = self::unit_base( 'theme' );
		if ( '' !== $standard['why'] || ! isset( $GLOBALS['wp_theme_directories'] ) || ! is_array( $GLOBALS['wp_theme_directories'] ) ) {
			return array();
		}
		$home   = realpath( $standard['dir'] );
		$others = array();
		foreach ( $GLOBALS['wp_theme_directories'] as $root ) {
			$root = rtrim( (string) $root, '/' );
			$real = realpath( $root );
			if ( false !== $real && $real !== $home ) {
				$others[] = $root;
			}
		}
		$lines = array();
		foreach ( self::theme_dirs( $others ) as $stylesheet => $version ) {
			$top = explode( '/', (string) $stylesheet )[0];
			if ( file_exists( $standard['dir'] . '/' . $top ) ) {
				continue;
			}
			$lines[] = self::named(
				'theme',
				(string) $stylesheet,
				array(
					'active'   => null,
					'bytes'    => 0,
					'complete' => true,
					'files'    => 0,
					'form'     => 'other',
					'hash'     => null,
					'lock'     => 'code:theme_root',
					'mains'    => null,
					'mtime'    => null,
					'own'      => false,
					'sha256'   => null,
					'version'  => $version,
					'cause'    => 'theme_root',
				)
			);
		}
		return self::sorted( $lines );
	}

	/**
	 * A name of the site in a field or an entry: as it is, or by its SHA-256
	 * where a credential holds it (held()) or a line does not hold it as it
	 * is (name()).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function path_name( $name ) {
		$name = (string) $name;
		return null === self::held( $name ) && self::name( $name ) === $name && 1 !== preg_match( self::HASHED, $name ) ? $name : self::hashed( $name );
	}

	/* ---------------------------------------------------------- tables */

	/**
	 * The tables with the prefix of this site that neither the core nor the
	 * takeover covers, after a name, as far as the clock and the lines
	 * reach; the line tables once the list is read to its end (or could not
	 * be read).
	 *
	 * @param array|null $place    Where the part before ended: the table
	 *                            (without the prefix) as place() keeps it,
	 *                            by the SHA-256 of its name.
	 * @param float      $deadline Until when.
	 * @param int        $room     Lines at most.
	 * @return array{lines:array[],done:bool,after:array|null} after as place() keeps it.
	 */
	public static function table_lines( $place, $deadline, $room ) {
		$names = self::tables();
		// The table the part before ended at, gone since: the list is not read
		// whole (fail closed), as where SHOW TABLES fails.
		$after = null === $names || null === $place ? null : self::found( $place, static fn() => $names );
		if ( null === $names || ( null !== $place && null === $after ) ) {
			return array(
				'lines' => array(
					self::line(
						'tables',
						'tables',
						array(
							'complete' => false,
							'count'    => 0,
						)
					),
				),
				'done'  => true,
				'after' => null,
			);
		}
		global $wpdb;
		$lines = array();
		$last  = $after;
		foreach ( $names as $short ) {
			if ( null !== $after && strcmp( $short, $after ) <= 0 ) {
				continue;
			}
			// One table a part at least, so that each part moves on.
			if ( array() !== $lines && ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) ) {
				return array(
					'lines' => $lines,
					'done'  => false,
					'after' => self::place( $last, false ),
				);
			}
			$table   = $wpdb->prefix . $short;
			$quoted  = '`' . str_replace( '`', '``', $table ) . '`';
			$rows    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $quoted ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- a name SHOW TABLES gave, quoted.
			$rows    = '' === (string) $wpdb->last_error && is_numeric( $rows ) ? (int) $rows : null;
			$create  = $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- the same.
			$schema  = '' === (string) $wpdb->last_error && is_array( $create ) && isset( $create[1] ) && is_string( $create[1] ) ? self::schema( $create[1], $wpdb->prefix ) : null;
			$lines[] = self::named(
				'table',
				$short,
				array(
					'rows'   => $rows,
					'schema' => $schema,
				)
			);
			$last    = $short;
		}
		$lines[] = self::line(
			'tables',
			'tables',
			array(
				'complete' => true,
				'count'    => count( $names ),
			)
		);
		return array(
			'lines' => $lines,
			'done'  => true,
			'after' => null,
		);
	}

	/**
	 * The names (without the prefix) of the tables with the prefix of this
	 * site that are neither of the core ($wpdb->tables()) nor of the takeover
	 * (AB_MCP_Sync_Schema::TABLES), by strcmp(); null where SHOW TABLES fails.
	 *
	 * @return string[]|null
	 */
	public static function tables() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== (string) $wpdb->last_error || ! is_array( $found ) ) {
			return null;
		}
		$known = array();
		foreach ( (array) $wpdb->tables( 'all', true ) as $table ) {
			$known[ (string) $table ] = true;
		}
		foreach ( AB_MCP_Sync_Schema::TABLES as $table ) {
			$known[ $wpdb->prefix . $table ] = true;
		}
		$out = array();
		foreach ( $found as $table ) {
			$table = (string) $table;
			if ( isset( $known[ $table ] ) || 0 !== strpos( $table, $wpdb->prefix ) || strlen( $table ) === strlen( $wpdb->prefix ) ) {
				continue;
			}
			$out[] = (string) substr( $table, strlen( $wpdb->prefix ) );
		}
		usort( $out, 'strcmp' );
		return $out;
	}

	/**
	 * The SHA-256 of a SHOW CREATE TABLE without what differs between two
	 * copies of one schema: AUTO_INCREMENT, and the prefix of the site at the
	 * beginning of a name of a table (SCHEMA_NAMES: the table itself, the
	 * table a foreign key references) and of a constraint (MySQL names one
	 * after its table, <table>_ibfk_1). A column, an index and what a text in
	 * quotes says keep their names: a column wp_id is no column id under
	 * another prefix (GitHub-Codex, PR #136, P2).
	 *
	 * @param string $create The statement.
	 * @param string $prefix The prefix of the site.
	 * @return string
	 */
	public static function schema( $create, $prefix ) {
		$text   = (string) preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', (string) $create );
		$prefix = '`' . (string) $prefix;
		$before = null;
		// A text in quotes, a name in backticks (`` within it), a word: a
		// name right after a word of SCHEMA_NAMES loses the prefix.
		$text = (string) preg_replace_callback(
			"/'(?:[^'\\\\]|\\\\.)*'|`(?:[^`]|``)*`|[A-Za-z_]+/",
			static function ( $match ) use ( $prefix, &$before ) {
				$token = $match[0];
				if ( '`' !== $token[0] ) {
					if ( "'" !== $token[0] ) {
						$before = $token;
					}
					return $token;
				}
				$word   = $before;
				$before = null;
				return in_array( $word, self::SCHEMA_NAMES, true ) && 0 === strpos( $token, $prefix ) ? '`' . substr( $token, strlen( $prefix ) ) : $token;
			},
			$text
		);
		return hash( 'sha256', $text );
	}

	/* ---------------------------------------------------------- roots */

	/**
	 * Count one root as far as the clock reaches.
	 *
	 * @param string $root     A root of ROOTS.
	 * @param array  $state    The state (after, files, bytes, skipped, attached).
	 * @param float  $deadline Until when.
	 * @return array{done:bool,line?:array,state?:array}
	 */
	private static function root( $root, array $state, $deadline ) {
		$place = self::root_dir( $root );
		if ( '' !== $place['why'] ) {
			return array(
				'done' => true,
				'line' => self::files_line( $root, $state, false, $place['why'] ),
			);
		}
		$known = null;
		if ( 'uploads' === $root ) {
			$known = self::attached( $deadline );
			if ( is_string( $known ) ) {
				return array(
					'done' => true,
					'line' => self::files_line( $root, $state, false, $known ),
				);
			}
		}
		if ( ! is_dir( $place['dir'] ) ) {
			return array(
				'done' => true,
				'line' => self::files_line( $root, $state, ! file_exists( $place['dir'] ), file_exists( $place['dir'] ) ? 'not_a_folder' : null ),
			);
		}
		// The place of the part before, found again by its SHA-256; gone
		// since, the root is not counted to its end.
		$dir   = $place['dir'];
		$after = null === $state['after'] ? null : self::found(
			$state['after'],
			static function ( $rel ) use ( $dir ) {
				return self::names( '' === $rel ? $dir : $dir . '/' . $rel );
			}
		);
		if ( null !== $state['after'] && null === $after ) {
			return array(
				'done' => true,
				'line' => self::files_line( $root, $state, false, 'moved' ),
			);
		}
		$state['after'] = $after;
		$walked         = self::walk( $dir, $after, $state, $deadline, self::skip_of( $root ), $known );
		if ( 'stopped' === $walked ) {
			$state['after'] = self::place( $state['after'] );
			return array(
				'done'  => false,
				'state' => $state,
			);
		}
		return array(
			'done' => true,
			'line' => self::files_line( $root, $state, 'done' === $walked, 'done' === $walked ? null : 'unreadable' ),
		);
	}

	/**
	 * The line of a root.
	 *
	 * @param string      $root     Root.
	 * @param array       $acc      files, bytes, skipped, attached.
	 * @param bool        $complete Read to its end.
	 * @param string|null $why      Why not.
	 * @return array
	 */
	private static function files_line( $root, array $acc, $complete, $why ) {
		return self::line(
			'files',
			$root,
			array(
				'attached' => (int) $acc['attached'],
				'bytes'    => (int) $acc['bytes'],
				'complete' => (bool) $complete,
				'files'    => (int) $acc['files'],
				'skipped'  => (int) $acc['skipped'],
				'why'      => $complete ? null : $why,
			)
		);
	}

	/**
	 * The folder of a root, or why it is not counted: by_code where code
	 * decides where the uploads or fonts lie (AB_MCP_Sync_Media::uploads(),
	 * a filter on font_dir), unknown where WordPress names no such folder.
	 *
	 * @param string $root Root.
	 * @return array{dir:string,why:string}
	 */
	public static function root_dir( $root ) {
		if ( 'languages' === $root ) {
			return defined( 'WP_LANG_DIR' ) ? array( 'dir' => rtrim( (string) WP_LANG_DIR, '/' ), 'why' => '' ) : array( 'dir' => '', 'why' => 'unknown' );
		}
		if ( 'mu-plugins' === $root ) {
			return defined( 'WPMU_PLUGIN_DIR' ) ? array( 'dir' => rtrim( (string) WPMU_PLUGIN_DIR, '/' ), 'why' => '' ) : array( 'dir' => '', 'why' => 'unknown' );
		}
		$uploads = AB_MCP_Sync_Media::uploads();
		if ( '' !== $uploads['problem'] ) {
			return array(
				'dir' => '',
				'why' => 'by_code' === $uploads['problem'] ? 'by_code' : 'unreadable',
			);
		}
		if ( 'fonts' === $root ) {
			return has_filter( 'font_dir' ) ? array( 'dir' => '', 'why' => 'by_code' ) : array( 'dir' => rtrim( $uploads['dir'], '/' ) . '/fonts', 'why' => '' );
		}
		return array(
			'dir' => rtrim( $uploads['dir'], '/' ),
			'why' => '',
		);
	}

	/**
	 * What of the top level of a root is not counted in it: in the uploads
	 * the fonts (a root of their own), the folders of AlphaBridge, and the
	 * units (each file and link at the top, the year folders) with what E1
	 * leaves out there (UPLOADS_TOP).
	 *
	 * @param string $root Root.
	 * @return callable(string $dir, string $name): bool
	 */
	private static function skip_of( $root ) {
		if ( 'uploads' === $root ) {
			return static function ( $dir, $name ) {
				$full = $dir . '/' . $name;
				return 'fonts' === $name || in_array( $name, self::OWN_UPLOADS, true ) || 0 === strpos( $name, self::OWN_UPLOADS_PREFIX )
					|| is_link( $full ) || ! is_dir( $full ) || 1 === preg_match( '/^[0-9]{4}\z/', $name );
			};
		}
		return static function () {
			return false;
		};
	}

	/**
	 * The paths (relative to the uploads) the file lists of the attachments
	 * of this site name (AB_MCP_Sync_Media::reverse()), or why the uploads
	 * cannot be counted against them: attachments_unreadable where a list
	 * could not be read whole, attachments_too_many past MAX_ATTACHED or
	 * where reading them takes more than half of what is left of the budget.
	 *
	 * @param float $deadline Until when.
	 * @return array<string,true>|string
	 */
	private static function attached( $deadline ) {
		$start = self::now();
		$half  = $start + max( 0.0, ( $deadline - $start ) / 2 );
		$paths = array();
		$after = 0;
		do {
			$page = AB_MCP_Sync_Media::reverse( $after, AB_MCP_Sync_Rows::MAX_PAGE );
			if ( null === $page || ! $page['complete'] ) {
				return 'attachments_unreadable';
			}
			foreach ( array_keys( $page['paths'] ) as $path ) {
				$paths[ (string) $path ] = true;
			}
			if ( count( $paths ) > self::MAX_ATTACHED || ( null !== $page['cursor'] && self::now() >= $half ) ) {
				return 'attachments_too_many';
			}
			$after = $page['cursor'];
		} while ( null !== $after );
		return $paths;
	}

	/**
	 * Count the files under a folder, depth first in order(), after a path,
	 * until the clock says stop: each regular file (one of $known counted as
	 * attached instead), each link (not followed) and anything else as
	 * skipped. $acc carries the counts and, where it stopped, after.
	 *
	 * @param string                  $base     The folder of the root.
	 * @param string|null             $after    The path the part before ended with.
	 * @param array                   $acc      files, bytes, skipped, attached, after.
	 * @param float                   $deadline Until when.
	 * @param callable                $skip     What of the top level not to count.
	 * @param array<string,true>|null $known    Paths counted as attached.
	 * @return string done, stopped or unreadable.
	 */
	public static function walk( $base, $after, array &$acc, $deadline, callable $skip, $known = null ) {
		$tick = 0;
		$from = null === $after ? null : (string) $after;
		return self::walk_in( rtrim( (string) $base, '/' ), '', $from, $acc, (float) $deadline, $skip, $known, $tick, $from );
	}

	/**
	 * One folder of walk().
	 *
	 * @param string                  $base     Root folder.
	 * @param string                  $rel      This folder, relative ('' the root).
	 * @param string|null             $after    See walk().
	 * @param array                   $acc      See walk().
	 * @param float                   $deadline See walk().
	 * @param callable                $skip     See walk().
	 * @param array<string,true>|null $known    See walk().
	 * @param int                     $tick     Entries since the clock was asked.
	 * @param string|null             $from     Where this call began: it stops only once it moved on from there.
	 * @return string
	 */
	private static function walk_in( $base, $rel, $after, array &$acc, $deadline, callable $skip, $known, &$tick, $from ) {
		$dir   = '' === $rel ? $base : $base . '/' . $rel;
		$names = self::names( $dir );
		if ( null === $names ) {
			return 'unreadable';
		}
		foreach ( $names as $name ) {
			if ( '' === $rel && $skip( $dir, $name ) ) {
				continue;
			}
			$path = '' === $rel ? $name : $rel . '/' . $name;
			$full = $base . '/' . $path;
			$into = null;
			if ( null !== $after ) {
				if ( 0 === strpos( $after, $path . '/' ) ) {
					$into = $after;
				} elseif ( self::order( $path, $after ) <= 0 ) {
					continue;
				}
			}
			if ( ++$tick >= self::TICK ) {
				$tick = 0;
				// Only once it moved on: a call that counted nothing yet (a
				// tree of folders without a file) goes on until it has.
				if ( self::now() >= $deadline && $acc['after'] !== $from ) {
					return 'stopped';
				}
			}
			if ( is_link( $full ) ) {
				++$acc['skipped'];
				$acc['after'] = $path;
				continue;
			}
			if ( is_dir( $full ) ) {
				$in = self::walk_in( $base, $path, $into, $acc, $deadline, $skip, $known, $tick, $from );
				if ( 'done' !== $in ) {
					return $in;
				}
				// A folder counted to its end: the next part goes on after it.
				$acc['after'] = $path;
				continue;
			}
			if ( is_file( $full ) ) {
				if ( null !== $known && isset( $known[ $path ] ) ) {
					++$acc['attached'];
				} else {
					$size = @filesize( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file gone in between counts as skipped.
					if ( is_int( $size ) ) {
						++$acc['files'];
						$acc['bytes'] += $size;
					} else {
						++$acc['skipped'];
					}
				}
			} else {
				++$acc['skipped'];
			}
			$acc['after'] = $path;
		}
		return 'done';
	}

	/**
	 * The order of the walk: two relative paths by their parts, each part
	 * by strcmp(), a folder before what is in it.
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return int -1, 0 or 1.
	 */
	public static function order( $a, $b ) {
		$x = explode( '/', (string) $a );
		$y = explode( '/', (string) $b );
		$n = min( count( $x ), count( $y ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$by = strcmp( $x[ $i ], $y[ $i ] );
			if ( 0 !== $by ) {
				return $by < 0 ? -1 : 1;
			}
		}
		return count( $x ) <=> count( $y );
	}

	/**
	 * The names in a folder by strcmp(), without . and ..; null where it
	 * cannot be read.
	 *
	 * @param string $dir Folder.
	 * @return string[]|null
	 */
	private static function names( $dir ) {
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder that cannot be read makes its root incomplete.
		if ( ! is_array( $names ) ) {
			return null;
		}
		$names = array_values( array_diff( array_map( 'strval', $names ), array( '.', '..' ) ) );
		usort( $names, 'strcmp' );
		return $names;
	}

	/* ------------------------------------------------------------ lines */

	/**
	 * A line.
	 *
	 * @param string $env    Kind.
	 * @param string $ref    Ref.
	 * @param array  $fields Fields.
	 * @return array
	 */
	private static function line( $env, $ref, array $fields ) {
		$line = array(
			'env' => (string) $env,
			'ref' => (string) $ref,
		) + $fields;
		ksort( $line );
		return $line;
	}

	/**
	 * Lines by ref, strcmp().
	 *
	 * @param array[] $lines Lines.
	 * @return array[]
	 */
	private static function sorted( array $lines ) {
		usort(
			$lines,
			static function ( $a, $b ) {
				return strcmp( $a['ref'], $b['ref'] );
			}
		);
		return $lines;
	}

	/**
	 * A text as a line holds it: as it is where it is UTF-8 without a
	 * control character and no longer than MAX_TEXT, else by its SHA-256
	 * (hashed(): the same on both sides, and itself such a text, so that a
	 * line with it keeps its structure; Codex, round 1 of branch 7, C6).
	 *
	 * @param string $name Name or value.
	 * @return string
	 */
	public static function name( $name ) {
		$name = (string) $name;
		return 1 === preg_match( '//u', $name ) && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $name ) && strlen( $name ) <= self::MAX_TEXT ? $name : self::hashed( $name );
	}

	/**
	 * A name or a value by its SHA-256: sha256: and the hex.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function hashed( $name ) {
		return 'sha256:' . hash( 'sha256', (string) $name );
	}

	/**
	 * Why a name of the site is held by the rules of this site, or null:
	 * over the whole name (a path with its folders, a table with its whole
	 * name), the check the import runs over each name of a line of the
	 * inventory (AB_MCP_Sync_Import::held_code(): the content check of the
	 * credential check and the net over the raw name, with the names of the
	 * filter ab_mcp_redact_keys): a field of a credential written into it
	 * (password=…, also encoded), or a name that does not read to its end
	 * (deeper than the check or the net reads); and, a name of the site
	 * being no prose, the name of a credential at any place in it right
	 * before an =, a :, a ; or a quote, whatever follows
	 * (AB_MCP_Sync_Net::keyed(): note:password:…, password:;…, x.token = …,
	 * s:8:"password";…; Codex, rounds 2 and 3 of branch 7, A1). The content
	 * check holds what neither holds: a field of a form as the HTML API of
	 * the core reads it (<button name=password>). A name is the name of a file, a folder or a table, as a
	 * slug is that of a post: «reset-password» or «woocommerce_api_keys»
	 * holds nothing (Codex, round 1 of branch 7, A1); «reset-password:guide.php»
	 * is held as well (fail closed).
	 *
	 * @param string        $name  Name.
	 * @param string[]|null $extra AB_MCP_Redactor::extra_keys() (read when null).
	 * @return string|null A code of HELD.
	 */
	public static function held( $name, $extra = null ) {
		$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();
		$check = AB_MCP_Sync_Credentials::unit( '', (string) $name, $extra );
		if ( 'mixed' === $check['state'] ) {
			return 'credential_field';
		}
		if ( 'unreadable' === $check['state'] ) {
			return 'unreadable_name';
		}
		$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra, true );
		if ( 'credential' === $net ) {
			return 'credential_field';
		}
		return 'unreadable' === $net ? 'unreadable_name' : null;
	}

	/**
	 * The line of a name of the site (NAMED): its ref the name where nothing
	 * holds it (held()) and a line holds it as it is (name(), not of the form
	 * of a hash), else its SHA-256 (hashed()), with held, why, where a
	 * credential holds it.
	 *
	 * @param string $env    Kind.
	 * @param string $name   Name.
	 * @param array  $fields Fields.
	 * @return array
	 */
	private static function named( $env, $name, array $fields ) {
		$name = (string) $name;
		$held = self::held( $name );
		if ( null !== $held ) {
			return self::reduced( self::line( $env, self::hashed( $name ), $fields ), $held );
		}
		return self::line( $env, '' !== $name && self::name( $name ) === $name && 1 !== preg_match( self::HASHED, $name ) ? $name : self::hashed( $name ), $fields );
	}

	/**
	 * A line whose name is held, by the SHA-256 of its name and why.
	 *
	 * @param array  $line Line, its ref the name.
	 * @param string $code A code of HELD.
	 * @return array
	 */
	private static function reduced( array $line, $code ) {
		if ( 1 !== preg_match( self::HASHED, (string) $line['ref'] ) ) {
			$line['ref'] = self::hashed( $line['ref'] );
		}
		$line['held'] = (string) $code;
		ksort( $line );
		return $line;
	}

	/**
	 * Why a line of a package of the environment is held on this site, or
	 * null: a line of a name the staging site held already says why; another
	 * one by its name (held()), by the rules of this site. For the import
	 * (AB_MCP_Sync_Import, its list of held objects).
	 *
	 * @param array    $line  Line (shaped()).
	 * @param string[] $extra AB_MCP_Redactor::extra_keys().
	 * @return string|null
	 */
	public static function held_line( array $line, array $extra ) {
		if ( ! in_array( $line['env'], self::NAMED, true ) ) {
			return null;
		}
		if ( isset( $line['held'] ) ) {
			return (string) $line['held'];
		}
		return 1 === preg_match( self::HASHED, $line['ref'] ) ? null : self::held( $line['ref'], $extra );
	}

	/**
	 * The lines of a package of the environment as this site shows them:
	 * a line the import listed as held here (kind, SHA-256 of its ref in the
	 * spelling of AB_MCP_Sync_Canon::encode(), code) by the SHA-256 of its
	 * name, with why; a constant this site does not show (shown(), by its
	 * rules) with its value not shown, whatever the staging site showed
	 * (GitHub-Codex, PR #136, P2). For the report, before it stores or shows
	 * a line (AB_MCP_Sync_Report::environment()).
	 *
	 * @param array[]       $lines Lines.
	 * @param array[]       $held  The list of held objects of the import.
	 * @param string[]|null $extra AB_MCP_Redactor::extra_keys() (read when null).
	 * @return array[]
	 */
	public static function held_here( array $lines, array $held, $extra = null ) {
		$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();
		$codes = array();
		foreach ( $held as $one ) {
			if ( is_array( $one ) && isset( $one[0], $one[1], $one[2] ) ) {
				$codes[ $one[0] . "\0" . $one[1] ] = (string) $one[2];
			}
		}
		$out = array();
		foreach ( $lines as $line ) {
			$key   = is_array( $line ) && isset( $line['env'], $line['ref'] ) ? $line['env'] . "\0" . hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) ) : null;
			if ( null !== $key && 'host' === $line['env'] && 0 === strpos( (string) $line['ref'], 'constant:' ) && ! self::shown( substr( (string) $line['ref'], 9 ), $extra ) ) {
				$line['value'] = 'not shown';
			}
			$out[] = null !== $key && ! isset( $line['held'] ) && isset( $codes[ $key ] ) && in_array( $line['env'], self::NAMED, true ) ? self::reduced( $line, $codes[ $key ] ) : $line;
		}
		return self::names_held( $out, static fn( $name ) => null !== self::held( $name, $extra ) );
	}

	/**
	 * The names inside lines held by a rule (the paths of entries, the themes
	 * of the theme that runs): each such name by its SHA-256, and no entries
	 * of a unit whose line is held (its paths would name it again).
	 *
	 * @param array[]  $lines Lines.
	 * @param callable $held  function( string $name ): bool, whether a rule holds a name.
	 * @return array[]
	 */
	private static function names_held( array $lines, callable $held ) {
		$units = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && isset( $line['env'], $line['held'] ) && in_array( $line['env'], self::UNITS, true ) ) {
				$units[ (string) $line['ref'] ] = $line['env'];
			}
		}
		$hash = static function ( $name ) use ( $held ) {
			$name = (string) $name;
			return 1 !== preg_match( self::HASHED, $name ) && $held( $name ) ? self::hashed( $name ) : $name;
		};
		$out = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && 'entries' === ( $line['env'] ?? null ) && is_array( $line['entries'] ?? null ) ) {
				$unit = explode( ':', (string) $line['unit'], 2 );
				if ( 2 === count( $unit ) && ( $held( $unit[1] ) || isset( $units[ self::hashed( $unit[1] ) ] ) ) ) {
					continue;
				}
				foreach ( $line['entries'] as $i => $entry ) {
					$line['entries'][ $i ][0] = $hash( $entry[0] );
				}
			} elseif ( is_array( $line ) && 'theme_choice' === ( $line['env'] ?? null ) ) {
				foreach ( array( 'stylesheet', 'template' ) as $field ) {
					if ( is_string( $line[ $field ] ?? null ) ) {
						$line[ $field ] = $hash( $line[ $field ] );
					}
				}
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * The names the lines of the staging site hold (NAMED, with held): the
	 * SHA-256 of each name (its ref, hashed()) with why, by strcmp(). For the
	 * report, which keeps them with its group (AB_MCP_Sync_Report) and holds
	 * the lines of this site by them before it stores them (held_there()).
	 *
	 * @param array[] $lines Lines of the staging site.
	 * @return array<string,string> hashed() => a code of HELD.
	 */
	public static function held_names( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && isset( $line['env'], $line['ref'], $line['held'] ) && in_array( $line['env'], self::NAMED, true ) && 1 === preg_match( self::HASHED, (string) $line['ref'] ) ) {
				$out[ (string) $line['ref'] ] = (string) $line['held'];
			}
			// A path of entries or a theme of the theme that runs the staging
			// site holds by its SHA-256.
			$inside = array();
			if ( is_array( $line ) && 'entries' === ( $line['env'] ?? null ) && is_array( $line['entries'] ?? null ) ) {
				$inside = array_column( $line['entries'], 0 );
			} elseif ( is_array( $line ) && 'theme_choice' === ( $line['env'] ?? null ) ) {
				$inside = array( $line['stylesheet'] ?? null, $line['template'] ?? null );
			}
			foreach ( $inside as $name ) {
				if ( is_string( $name ) && 1 === preg_match( self::HASHED, $name ) && ! isset( $out[ $name ] ) ) {
					$out[ $name ] = 'credential_field';
				}
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The lines of this site with a name the staging site holds (held_names(),
	 * of whatever kind of NAMED) by its SHA-256 with why, before a part of
	 * them is stored: the rules of both sites hold what this site keeps, not
	 * only those of this one (Codex, round 2 of branch 7, A1). A line held
	 * here already stays as it is.
	 *
	 * @param array[]              $lines Lines of this site.
	 * @param array<string,string> $names held_names() of the staging site.
	 * @return array[]
	 */
	public static function held_there( array $lines, array $names ) {
		foreach ( $lines as $i => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) || isset( $line['held'] ) || ! in_array( $line['env'], self::NAMED, true ) ) {
				continue;
			}
			$ref    = (string) $line['ref'];
			$hashed = 1 === preg_match( self::HASHED, $ref ) ? $ref : self::hashed( $ref );
			if ( isset( $names[ $hashed ] ) ) {
				$lines[ $i ] = self::reduced( $line, (string) $names[ $hashed ] );
			}
		}
		return self::names_held( $lines, static fn( $name ) => isset( $names[ self::hashed( $name ) ] ) );
	}

	/**
	 * A text as a line holds it (name()), $none where it is no text.
	 *
	 * @param mixed       $value Value.
	 * @param string|null $none  For a value that is no text.
	 * @return string|null
	 */
	private static function text( $value, $none ) {
		return is_string( $value ) ? self::name( $value ) : $none;
	}

	/**
	 * Whether a line has the structure read() writes: env of LINES, ref a
	 * text (core and host of their lists, files of ROOTS, page and tables
	 * their own name, a drop-in of DROPINS), exactly the fields of its kind,
	 * each of its type. For the lines of a package of the environment of the
	 * staging site (AB_MCP_Sync_Import).
	 *
	 * @param array $line Line.
	 * @return bool
	 */
	public static function shaped( array $line ) {
		if ( ! isset( $line['env'], $line['ref'] ) || ! is_string( $line['env'] ) || ! isset( self::LINES[ $line['env'] ] ) || ! is_string( $line['ref'] ) || '' === $line['ref'] || self::name( $line['ref'] ) !== $line['ref'] ) {
			return false;
		}
		$fields = self::LINES[ $line['env'] ];
		$keys   = array_keys( $line );
		sort( $keys );
		// A name held (NAMED): by its SHA-256, with why.
		$held = array_key_exists( 'held', $line );
		if ( $held && ( ! in_array( $line['env'], self::NAMED, true ) || ! in_array( $line['held'], self::HELD, true ) || 1 !== preg_match( self::HASHED, $line['ref'] ) ) ) {
			return false;
		}
		$want = array_merge( array_keys( $fields ), array( 'env', 'ref' ), $held ? array( 'held' ) : array() );
		sort( $want );
		if ( $keys !== $want ) {
			return false;
		}
		foreach ( $fields as $field => $type ) {
			if ( ! self::typed( $line[ $field ], $type ) ) {
				return false;
			}
		}
		$ref = $line['ref'];
		if ( in_array( $line['env'], self::UNITS, true ) ) {
			return self::unit_shaped( $line );
		}
		switch ( $line['env'] ) {
			case 'page':
				return 'page' === $ref && $line['seq'] >= 1 && AB_MCP_Sync_File_Manifest::FORMAT === $line['files'];
			case 'entries':
				$unit = explode( ':', (string) $line['unit'], 2 );
				return $line['seq'] >= 1 && $ref === $line['unit'] . '#' . $line['seq'] && 2 === count( $unit ) && in_array( $unit[0], self::UNITS, true ) && '' !== $unit[1];
			case 'theme_choice':
				return 'theme_choice' === $ref;
			case 'core_files':
				return 'files' === $ref && ( null === $line['cause'] ) === $line['complete'] && ( null === $line['cause'] || in_array( $line['cause'], AB_MCP_Sync_Core_Compare::WHY, true ) );
			case 'tables':
				return 'tables' === $ref;
			case 'core':
				return in_array( $ref, self::CORE, true );
			case 'dropin':
				return in_array( $ref, self::DROPINS, true );
			case 'files':
				return in_array( $ref, self::ROOTS, true ) && ( null === $line['why'] ) === $line['complete'];
			case 'host':
				$constant = 0 === strpos( $ref, 'constant:' ) ? substr( $ref, 9 ) : null;
				return null === $constant ? in_array( $ref, self::HOST, true ) && true === $line['defined'] : in_array( $constant, self::CONSTANTS, true ) && ( $line['defined'] || null === $line['value'] );
		}
		return true;
	}

	/**
	 * Whether a value is of a type of LINES.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Type.
	 * @return bool
	 */
	private static function typed( $value, $type ) {
		$null = '?' === substr( $type, -1 );
		if ( null === $value ) {
			return $null;
		}
		switch ( rtrim( $type, '?' ) ) {
			case 'text':
				return is_string( $value ) && self::name( $value ) === $value;
			case 'bool':
				return is_bool( $value );
			case 'count':
				return is_int( $value ) && $value >= 0;
			case 'hash':
				return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $value );
			case 'hex32':
				return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}\z/', $value );
			case 'scalar':
				return is_bool( $value ) || is_int( $value ) || ( is_string( $value ) && self::name( $value ) === $value );
			case 'list':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || '' === $item || self::name( $item ) !== $item ) {
						return false;
					}
				}
				return true;
			case 'entries':
				if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > self::PER_LINE ) {
					return false;
				}
				foreach ( $value as $entry ) {
					if ( ! is_array( $entry ) || ! array_is_list( $entry ) || 4 !== count( $entry ) || ! self::typed( $entry[0], 'text' ) || '' === $entry[0] || ! self::typed( $entry[1], 'count' ) || ! self::typed( $entry[2], 'hash' ) || ! self::typed( $entry[3], 'count' ) ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * Whether the line of a unit has the structure unit_lines() writes: a
	 * form of FORMS, the ref * for a root not read and only there, why and
	 * lock of their lists, a hash only for a folder or file read whole and
	 * not locked, the SHA-256 and mtime of its file only for a file.
	 *
	 * @param array $line Line, its fields typed.
	 * @return bool
	 */
	private static function unit_shaped( array $line ) {
		if ( ! in_array( $line['form'], self::FORMS, true ) || ( '*' === $line['ref'] ) !== ( 'root' === $line['form'] ) ) {
			return false;
		}
		if ( null !== $line['cause'] && ! in_array( $line['cause'], self::UNIT_WHY, true ) ) {
			return false;
		}
		if ( null !== $line['lock'] && ! isset( self::CODE_LOCKS[ $line['lock'] ] ) ) {
			return false;
		}
		if ( null !== $line['hash'] && ( ! $line['complete'] || null !== $line['lock'] || ! in_array( $line['form'], array( 'folder', 'file' ), true ) ) ) {
			return false;
		}
		return ( null === $line['sha256'] && null === $line['mtime'] ) || ( 'file' === $line['form'] && null !== $line['hash'] );
	}

	/* ------------------------------------------------------------ compare */

	/**
	 * The entries of the environment: one per line of either side (the
	 * pages and the entries of the units left out), in ORDER, then by ref;
	 * with their counts and what is locked. A unit and the theme that runs
	 * are decided with their pairs (AB_MCP_Sync_Decision::unit_item()).
	 *
	 * @param array[] $staging The lines of the staging site.
	 * @param array[] $live    The lines of this site.
	 * @param array[] $pairs   The pairs of the source (AB_MCP_Sync_Pairs::decode()).
	 * @return array{entries:array[],counts:array,blocked:array[]}
	 */
	public static function compare( array $staging, array $live, array $pairs = array() ) {
		$s = self::by_ref( $staging );
		$l = self::by_ref( $live );
		// A name one site holds by its rules and the other not: by its
		// SHA-256 on both sides, so it is one entry and no side shows it.
		$l     = self::held_alike( $s, $l );
		$s     = self::held_alike( $l, $s );
		$whole = array(
			's' => isset( $s['tables']['tables'] ) && true === $s['tables']['tables']['complete'],
			'l' => isset( $l['tables']['tables'] ) && true === $l['tables']['tables']['complete'],
		);
		$files = array(
			's' => self::entries_of( $staging, $s ),
			'l' => self::entries_of( $live, $l ),
		);
		$by_pair = array();
		foreach ( $pairs as $pair ) {
			if ( in_array( $pair['kind'], AB_MCP_Sync_Decision::UNIT_KINDS, true ) ) {
				$by_pair[ $pair['kind'] ][ (string) $pair['staging_ref'] ] = $pair;
			}
		}
		$entries = array();
		foreach ( self::ORDER as $env ) {
			if ( 'environment' === $env ) {
				continue;
			}
			if ( 'table' === $env && ( ! $whole['s'] || ! $whole['l'] ) ) {
				// The list itself, so that what was not read shows also
				// where neither side names a table (fail closed).
				$entries[] = self::entry( 'table', '*', null, null, $whole );
			}
			$refs = array_keys( ( $s[ $env ] ?? array() ) + ( $l[ $env ] ?? array() ) );
			$refs = array_map( 'strval', $refs );
			usort( $refs, 'strcmp' );
			foreach ( $refs as $ref ) {
				if ( in_array( $env, self::UNITS, true ) || 'theme_choice' === $env ) {
					$entries[] = AB_MCP_Sync_Decision::unit_item(
						$env,
						$ref,
						$s[ $env ][ $ref ] ?? null,
						$l[ $env ][ $ref ] ?? null,
						$by_pair[ $env ][ $ref ] ?? null,
						array(
							's' => $files['s'][ $env . ':' . $ref ] ?? null,
							'l' => $files['l'][ $env . ':' . $ref ] ?? null,
						)
					);
					continue;
				}
				$entries[] = self::entry( $env, $ref, $s[ $env ][ $ref ] ?? null, $l[ $env ][ $ref ] ?? null, $whole );
			}
		}
		return self::counted( $entries );
	}

	/**
	 * The files of each unit of a side read whole, from its lines of entries
	 * in their order: kind:name => [path, size, SHA-256, mtime], only where
	 * every line of entries of the unit is there (seq 1 to n without a gap,
	 * as many files as its line says), else null for that unit (its paths
	 * not shown, fail closed).
	 *
	 * @param array[]                           $lines Lines of a side.
	 * @param array<string,array<string,array>> $by    Its lines by kind and ref (by_ref(), held_alike()).
	 * @return array<string,array[]|null>
	 */
	private static function entries_of( array $lines, array $by ) {
		$parts = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && 'entries' === ( $line['env'] ?? null ) && is_array( $line['entries'] ?? null ) ) {
				$parts[ (string) $line['unit'] ][ (int) $line['seq'] ] = $line['entries'];
			}
		}
		$out = array();
		foreach ( $parts as $unit => $seqs ) {
			list( $kind, $name ) = array_pad( explode( ':', $unit, 2 ), 2, '' );
			$line                = $by[ $kind ][ $name ] ?? null;
			ksort( $seqs );
			$files = array();
			$want  = 1;
			foreach ( $seqs as $seq => $list ) {
				if ( $seq !== $want ) {
					$files = null;
					break;
				}
				++$want;
				foreach ( $list as $entry ) {
					$files[] = $entry;
				}
			}
			$out[ $unit ] = null !== $files && is_array( $line ) && ! isset( $line['held'] ) && count( $files ) === (int) $line['files'] ? $files : null;
		}
		return $out;
	}

	/**
	 * The entry where no package of the environment of the staging site was
	 * given: the environment not compared, with the way.
	 *
	 * @return array{entries:array[],counts:array,blocked:array[]}
	 */
	public static function without() {
		return self::counted(
			array(
				array(
					'kind'    => 'environment',
					'subtype' => 'environment',
					'ref'     => 'environment',
					'result'  => 'not_compared',
					'action'  => 'none',
					'staging' => null,
					'live'    => null,
					'why'     => 'No package of the environment of the staging site was given, so plugins, themes, files, tables and the host are not compared. 1. On the staging site, call wp_site_export with purpose=env (and its cursor until it gives none). 2. Here, take each package over with wp_site_import_chunk (purpose=env). 3. Call wp_site_diff with packages and environment.',
					'words'   => self::WORDS['not_compared'],
				),
			)
		);
	}

	/**
	 * Entries numbered from 1 within the environment, with the counts by
	 * result and action and the locks with how many entries each.
	 *
	 * @param array[] $entries Entries.
	 * @return array{entries:array[],counts:array,blocked:array[]}
	 */
	private static function counted( array $entries ) {
		$results = array();
		$actions = array();
		$blocked = array();
		foreach ( $entries as $entry ) {
			$results[ $entry['result'] ] = ( $results[ $entry['result'] ] ?? 0 ) + 1;
			$actions[ $entry['action'] ] = ( $actions[ $entry['action'] ] ?? 0 ) + 1;
			foreach ( $entry['locks'] ?? array() as $lock ) {
				if ( ! isset( $blocked[ $lock['key'] ] ) ) {
					$blocked[ $lock['key'] ] = $lock + array( 'entries' => 0 );
				}
				++$blocked[ $lock['key'] ]['entries'];
			}
		}
		ksort( $results );
		ksort( $actions );
		ksort( $blocked );
		return array(
			'entries' => $entries,
			'counts'  => array(
				'entries' => count( $entries ),
				'results' => $results,
				'actions' => $actions,
			),
			'blocked' => array_values( $blocked ),
		);
	}

	/**
	 * Lines by kind and ref (a page is of no kind of ORDER, so no entry).
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,array<string,array>>
	 */
	private static function by_ref( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) || 'entries' === $line['env'] ) {
				continue;
			}
			$out[ (string) $line['env'] ][ (string) $line['ref'] ] = $line;
		}
		return $out;
	}

	/**
	 * The lines of side b with a name side a holds (NAMED: a line with held,
	 * its ref the SHA-256 of the name) by that SHA-256 as well, held for the
	 * same reason.
	 *
	 * @param array<string,array<string,array>> $a Lines by kind and ref (by_ref()).
	 * @param array<string,array<string,array>> $b The same of the other side.
	 * @return array<string,array<string,array>> $b.
	 */
	private static function held_alike( array $a, array $b ) {
		foreach ( self::NAMED as $env ) {
			$codes = array();
			foreach ( $a[ $env ] ?? array() as $ref => $line ) {
				if ( isset( $line['held'] ) ) {
					$codes[ (string) $ref ] = (string) $line['held'];
				}
			}
			if ( array() === $codes ) {
				continue;
			}
			foreach ( $b[ $env ] ?? array() as $ref => $line ) {
				$hashed = self::hashed( (string) $ref );
				if ( ! isset( $line['held'] ) && isset( $codes[ $hashed ] ) && ! isset( $b[ $env ][ $hashed ] ) ) {
					unset( $b[ $env ][ $ref ] );
					$b[ $env ][ $hashed ] = self::reduced( $line, $codes[ $hashed ] );
				}
			}
		}
		return $b;
	}

	/**
	 * The entry of one ref.
	 *
	 * @param string     $env   Kind.
	 * @param string     $ref   Ref.
	 * @param array|null $s     The line of the staging site.
	 * @param array|null $l     The line of this site.
	 * @param array      $whole Whether the list of tables was read whole on each side.
	 * @return array
	 */
	private static function entry( $env, $ref, $s, $l, array $whole ) {
		$why     = null;
		$differs = array();
		if ( 'table' === $env && ( ! $whole['s'] || ! $whole['l'] ) ) {
			$result = 'not_compared';
			$why    = 'The list of tables could not be read whole on ' . ( $whole['s'] ? 'this site' : 'the staging site' ) . '.';
		} elseif ( null === $s || null === $l ) {
			$result = null === $s ? 'only_live' : 'only_staging';
		} else {
			list( $result, $differs, $why ) = self::judged( $env, $ref, $s, $l );
		}
		$key   = 'files' === $env ? 'files:' . $ref : $env;
		$entry = array(
			'kind'    => 'environment',
			'subtype' => $env,
			'ref'     => $ref,
			'result'  => $result,
			'action'  => 'none',
			'staging' => null === $s ? null : self::show( $env, $s ),
			'live'    => null === $l ? null : self::show( $env, $l ),
		);
		if ( array() !== $differs ) {
			$entry['differs'] = $differs;
		}
		if ( null !== $why ) {
			$entry['why'] = $why;
		}
		$held = $s['held'] ?? ( $l['held'] ?? null );
		if ( null !== $held ) {
			$entry['held'] = $held;
		}
		$words = self::WORDS[ $result ];
		if ( 'core_files' === $env ) {
			$words = self::WORDS[ 'equal' === $result ? 'core_equal' : ( 'differs' === $result ? 'core_differs' : $result ) ];
			$entry['action'] = 'host';
		} elseif ( 'core' === $env && 'locale' === $ref ) {
			$words .= ' Shown only: the language is the option WPLANG, compared with the options; its files are compared under languages.';
		} elseif ( 'core' === $env ) {
			// WordPress itself is never taken over (A17): changed at the host.
			$entry['action'] = 'host';
			$words          .= ' WordPress itself is never taken over: where staging is newer, this site is updated first (wp_update_core).';
		} elseif ( in_array( $env, array( 'dropin', 'host' ), true ) ) {
			// Group U (the drop-ins with it): changed at the host, whatever
			// the result.
			$entry['action'] = 'host';
			$words          .= ' ' . self::HOST_WORDS;
		} elseif ( 'equal' !== $result && isset( self::LOCKS[ $key ] ) ) {
			$lock            = self::LOCKS[ $key ];
			$entry['action'] = 'locked';
			$entry['locks']  = array(
				array(
					'key'    => $lock['key'],
					'stage'  => $lock['stage'],
					'reason' => $lock['reason'],
				),
			);
			if ( 'counted' === $result ) {
				$entry['contents'] = 'not compared';
				$entry['stage']    = $lock['stage'];
				$words            .= sprintf( ' Their contents are compared from %s.', $lock['stage'] );
			}
		}
		if ( null !== $held ) {
			$words .= sprintf( ' Its name holds a credential by the rules of a site (held: %s), so it is shown by its SHA-256 only.', $held );
		}
		$entry['words'] = $words;
		return $entry;
	}

	/**
	 * What both lines of a ref say: the result, what differs, why not
	 * compared. Equal only where the contents of a file were read (a
	 * must-use plugin, a drop-in: its SHA-256); a value of group U and the
	 * language the same on both sides are same_value, shown only (decision
	 * 14: group U is changed at the host; Codex, round 1 of branch 7, B4);
	 * what is only counted is counted; none of them equal.
	 *
	 * @param string $env Kind.
	 * @param string $ref Ref.
	 * @param array  $s   Staging.
	 * @param array  $l   This site.
	 * @return array{0:string,1:string[],2:string|null}
	 */
	private static function judged( $env, $ref, array $s, array $l ) {
		$unread = static function ( array $fields ) use ( $s, $l ) {
			foreach ( $fields as $field ) {
				if ( null === $s[ $field ] || null === $l[ $field ] ) {
					return 'A value of it could not be read on ' . ( null === $s[ $field ] ? 'the staging site' : 'this site' ) . '.';
				}
			}
			return null;
		};
		$differ = static function ( array $fields ) use ( $s, $l ) {
			$out = array();
			foreach ( $fields as $field ) {
				if ( $s[ $field ] !== $l[ $field ] ) {
					$out[] = $field;
				}
			}
			return $out;
		};
		switch ( $env ) {
			case 'core':
				$fields = array( 'value' );
				$same   = 'same_value';
				break;
			case 'core_files':
				if ( ! $s['complete'] || ! $l['complete'] ) {
					return array( 'not_compared', array(), sprintf( $s['complete'] ? 'The core files could not be checked on this site (%s).' : 'The core files could not be checked on the staging site (%s).', self::why_core( (string) ( $s['complete'] ? $l['cause'] : $s['cause'] ) ) ) );
				}
				$differs = array();
				foreach ( array( 'differs', 'missing' ) as $field ) {
					if ( 0 !== $s[ $field ] || 0 !== $l[ $field ] ) {
						$differs[] = $field;
					}
				}
				return array( array() === $differs ? 'equal' : 'differs', $differs, null );
			case 'dropin':
				$fields = array( 'sha256' );
				$same   = 'equal';
				break;
			case 'host':
				if ( $s['defined'] !== $l['defined'] ) {
					return array( 'differs', array( 'defined' ), null );
				}
				if ( ! $s['defined'] ) {
					return array( 'same_value', array(), null );
				}
				$fields = array( 'value' );
				$same   = 'same_value';
				break;
			case 'table':
				$fields = array( 'rows', 'schema' );
				$same   = 'counted';
				break;
			case 'files':
				if ( ! $s['complete'] || ! $l['complete'] ) {
					$side = $s['complete'] ? $l : $s;
					// One whole sentence a side, as AB_MCP_Sync_Said knows it.
					return array( 'not_compared', array(), sprintf( $s['complete'] ? 'The %1$s could not be counted to its end on this site (%2$s).' : 'The %1$s could not be counted to its end on the staging site (%2$s).', $ref, self::why_files( (string) $side['why'] ) ) );
				}
				$fields = array( 'files', 'bytes' );
				$same   = 'counted';
				break;
			default:
				return array( 'not_compared', array(), 'This kind of line is not known here.' );
		}
		$why = $unread( $fields );
		if ( null !== $why ) {
			return array( 'not_compared', array(), $why );
		}
		$differs = $differ( $fields );
		return array( array() === $differs ? $same : 'differs', array_map( static fn( $f ) => 'sha256' === $f ? 'contents' : $f, $differs ), null );
	}

	/**
	 * Why the core files of a side were not checked, in words.
	 *
	 * @param string $why A code of AB_MCP_Sync_Core_Compare::WHY.
	 * @return string
	 */
	private static function why_core( $why ) {
		$say = array(
			'unreachable'     => 'api.wordpress.org was not reached',
			'answer'          => 'the answer of api.wordpress.org held no checksums',
			'unknown_version' => 'api.wordpress.org knows no checksums of its version and language',
			'no_version'      => 'its version of WordPress could not be read',
		);
		return $say[ $why ] ?? 'not known why';
	}

	/**
	 * Why a root was not counted, in words.
	 *
	 * @param string $why The code of the line.
	 * @return string
	 */
	private static function why_files( $why ) {
		$say = array(
			'by_code'                => 'code decides where these files lie, a filter or a network of sites',
			'unreadable'             => 'a folder of it could not be read',
			'unknown'                => 'WordPress names no such folder',
			'not_a_folder'           => 'its place is no folder',
			'attachments_unreadable' => 'the file list of an attachment could not be read whole',
			'attachments_too_many'   => 'the file lists of the attachments are too many to hold within one call',
			'moved'                  => 'the file or folder the call before stopped at (kept by its SHA-256 only, never by its name) is gone since, so where to go on is not known',
		);
		return $say[ $why ] ?? 'not known why';
	}

	/**
	 * A side of an entry as the report shows it: no hash (a value held by
	 * its SHA-256 is not shown), the rest of its line.
	 *
	 * @param string $env  Kind.
	 * @param array  $line Line.
	 * @return array
	 */
	private static function show( $env, array $line ) {
		unset( $line['env'], $line['ref'], $line['sha256'], $line['schema'], $line['held'] );
		if ( 'host' === $env && is_string( $line['value'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $line['value'] ) ) {
			$line['value'] = 'present';
		}
		// A value held by its SHA-256 (a constant that is no plain word, a
		// version longer than a line holds): not shown.
		foreach ( $line as $field => $value ) {
			if ( is_string( $value ) && 0 === strpos( $value, 'sha256:' ) ) {
				$line[ $field ] = 'not shown';
			}
		}
		if ( 'files' === $env && null === $line['why'] ) {
			unset( $line['why'] );
		}
		return $line;
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * @return float Seconds.
	 */
	private static function now() {
		return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
	}
}
