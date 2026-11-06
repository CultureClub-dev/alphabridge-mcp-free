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
 *  - plugin and theme: each by its file or stylesheet, with its version and
 *    whether it runs (the stored rows of active_plugins, stylesheet and
 *    template, unpacked without classes);
 *  - mu_plugin: each PHP file WordPress loads from the must-use folder
 *    (its top level, as wp_get_mu_plugins()), with its SHA-256 and size;
 *  - dropin: each drop-in of a single site (DROPINS) that is there, the same;
 *  - host (group U of the concept): the versions of PHP and of the database,
 *    whether an external object cache runs, the WordPress block of the
 *    .htaccess by its SHA-256, the must-use loader of AlphaBridge by its
 *    SHA-256, and the constants of CONSTANTS, none of which holds a secret
 *    (never a value of a key, a salt, a password or of the access to the
 *    database: NEVER, and no name AB_MCP_Redactor redacts, the names of the
 *    filter ab_mcp_redact_keys included);
 *  - table: each table with the prefix of the site that neither the core
 *    nor the takeover covers (the tables of $wpdb and of AB_MCP_Sync_Schema),
 *    by its name without the prefix, with its rows (COUNT) and the SHA-256 of
 *    SHOW CREATE TABLE with the prefix and AUTO_INCREMENT taken out; tables
 *    (one line) says whether the list was read whole. Nothing else of a
 *    table is read;
 *  - files: the roots of ROOTS, each counted and summed (files, bytes),
 *    never read: languages (WP_LANG_DIR), fonts (the fonts folder of the
 *    uploads), mu-plugins (what of the must-use folder is no line of its own)
 *    and uploads (the files of the uploads outside the file lists of the
 *    attachments, AB_MCP_Sync_Media; fonts and the folders of AlphaBridge
 *    left out). A link is not followed and counted as skipped; a folder that
 *    cannot be read makes its root incomplete (fail closed).
 *
 * IN PARTS. read() goes on from a state within a budget of seconds and lines:
 * the lines of the site at once, then the tables one by one, then each root
 * file by file, in one order (order(): the folders of a root depth first, the
 * names of a folder by strcmp()), so the next call goes on after the last
 * path it counted. Each part begins with a line page (walk: one random id
 * for all parts of one reading, seq, last), by which the production site
 * holds the packages of the staging site to one whole series.
 *
 * NAMES. A name of the site (a plugin, a theme, a must-use plugin, a
 * table: NAMED) goes through the check the import runs over the names of a
 * line of the inventory (held(): a field of a credential written into it,
 * also encoded, by the rules of the site with ab_mcp_redact_keys); one it
 * holds is in a line by its SHA-256 only, with why (held), and so is a name
 * that is no text a line holds (no UTF-8, a control character, longer than
 * MAX_TEXT). The place a part ends at (the state: the cursor of
 * wp_site_export, a part of a report) keeps each part of its path the same
 * way (place()), and the next part finds it again among the names where it
 * lies (found()); one gone since leaves its root not counted to its end.
 *
 * WHAT IT SAYS (compare()): an entry per line of either side. Only a
 * must-use plugin and a drop-in are equal or differ by what was read (the
 * SHA-256 of the file); a value of group U and the language show both
 * values, same_value where they are the same (what lies behind a value is
 * not compared), else differs; what is only counted or summed (plugins and
 * themes of the same version, WordPress of the same version, tables with
 * the same rows and schema, the roots with the same count and size) is
 * «counted, contents not compared», with the stage of the comparison of
 * their contents (Z8b for files and code, Z8d for tables), never equal.
 * Nothing of it is taken over: code, files and tables are locked with their
 * reason and stage (LOCKS), group U and the drop-ins are changed at the host
 * (action host, whatever the result). What could not be read is not
 * compared, with why (fail closed). No hash and no value of a file in an
 * entry; a held name by its SHA-256 with held.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover.
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
require_once dirname( __DIR__ ) . '/class-redactor.php';

/**
 * Class AB_MCP_Sync_Env
 */
final class AB_MCP_Sync_Env {

	/** The steps of a reading, in order. */
	const STEPS = array( 'site', 'tables', 'languages', 'fonts', 'mu-plugins', 'uploads' );

	/** The roots of files, counted and summed. */
	const ROOTS = array( 'languages', 'fonts', 'mu-plugins', 'uploads' );

	/**
	 * The fields of each kind of line besides env and ref, with their types:
	 * text (any text), text? (or null), bool, bool?, count (a whole number
	 * from 0), count?, hash? (64 hex or null), hex32, scalar? (text, whole
	 * number, bool or null).
	 */
	const LINES = array(
		'page'      => array(
			'last' => 'bool',
			'seq'  => 'count',
			'walk' => 'hex32',
		),
		'core'      => array( 'value' => 'text?' ),
		'plugin'    => array(
			'active'  => 'bool?',
			'version' => 'text?',
		),
		'theme'     => array(
			'active'  => 'bool?',
			'version' => 'text?',
		),
		'mu_plugin' => array(
			'bytes'  => 'count?',
			'sha256' => 'hash?',
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
	const NAMED = array( 'plugin', 'theme', 'mu_plugin', 'table' );

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
			'reason' => 'A plugin is code: its files and whether it runs are compared and taken over with the code of the site.',
		),
		'theme'           => array(
			'key'    => 'env:theme',
			'art'    => 'A11',
			'stage'  => 'Z8b',
			'reason' => 'A theme is code: its files and which theme runs are compared and taken over with the code of the site.',
		),
		'core'            => array(
			'key'    => 'env:wordpress',
			'art'    => 'A17',
			'stage'  => 'Z8b',
			'reason' => 'WordPress itself is never taken over: where staging is newer, this site is updated first (wp_update_core); its files are compared with the code of the site.',
		),
		'mu_plugin'       => array(
			'key'    => 'env:mu_plugin',
			'art'    => 'A21',
			'stage'  => 'Z8b',
			'reason' => 'A must-use plugin is a file: compared by its SHA-256, taken over with the files of the site.',
		),
		'table'           => array(
			'key'    => 'env:table',
			'art'    => 'A16',
			'stage'  => 'Z8d',
			'reason' => 'A table of a plugin without an adapter: shown by its rows and schema, its rows are not read; taken over only through an adapter, or as a whole on a confirmation per table.',
		),
		'files:languages' => array(
			'key'    => 'env:languages',
			'art'    => 'A21',
			'stage'  => 'Z8b',
			'reason' => 'The language files are counted and summed, their contents not compared; they are compared and taken over with the files of the site.',
		),
		'files:fonts'     => array(
			'key'    => 'env:fonts',
			'art'    => 'A20',
			'stage'  => 'Z8b',
			'reason' => 'The files of the font library are counted and summed, their contents not compared; they come with the fonts of the font library.',
		),
		'files:mu-plugins' => array(
			'key'    => 'env:mu_files',
			'art'    => 'A21',
			'stage'  => 'Z8b',
			'reason' => 'The files of the must-use folder WordPress does not load itself (in its subfolders) are counted and summed, their contents not compared.',
		),
		'files:uploads'   => array(
			'key'    => 'env:uploads',
			'art'    => 'A22',
			'stage'  => 'Z8b',
			'reason' => 'Files under uploads outside the file lists of attachments are counted and summed, their contents not compared.',
		),
	);

	/** What each result says. */
	const WORDS = array(
		'equal'        => 'Equal on both sides, by the SHA-256 of the file.',
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
	const ORDER = array( 'environment', 'core', 'plugin', 'theme', 'mu_plugin', 'dropin', 'host', 'table', 'files' );

	/** The clock of the budget; a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/* ------------------------------------------------------------ reading */

	/**
	 * Read the environment of this site from a state, as far as a budget of
	 * seconds and lines reaches. The first line is the page.
	 *
	 * @param array|null $state The state of the part before (null: begin).
	 * @param int|float  $budget Seconds.
	 * @param int        $max    Lines at most (the page and one more at least).
	 * @return array{lines:array[],state:array|null}|WP_Error state null: done.
	 */
	public static function read( $state, $budget, $max = 4000 ) {
		if ( null === $state ) {
			try {
				$walk = bin2hex( random_bytes( 16 ) );
			} catch ( Throwable $e ) {
				return new WP_Error( 'ab_mcp_sync_env_random', 'The environment was not read: PHP has no source of random bytes on this server. Ask the host to check the PHP installation, then call again.' );
			}
			$state = self::fresh( $walk, 1 );
		} elseif ( ! self::state_shaped( $state ) ) {
			return new WP_Error( 'ab_mcp_sync_env_state', 'The place to go on from in the environment is not one this site wrote. Begin anew: call wp_site_export with purpose=env without a cursor, or wp_site_diff with the packages again.' );
		}
		$deadline = self::now() + (float) $budget;
		$max      = max( 2, (int) $max );
		$lines    = array();
		while ( $state['step'] < count( self::STEPS ) ) {
			$step = self::STEPS[ $state['step'] ];
			if ( 'site' === $step ) {
				$lines = array_merge( $lines, self::site_lines() );
				$state = self::next( $state );
			} elseif ( 'tables' === $step ) {
				$read  = self::table_lines( $state['after'], $deadline, $max - 1 - count( $lines ) );
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
				'env'  => 'page',
				'last' => $done,
				'ref'  => 'page',
				'seq'  => (int) $state['seq'],
				'walk' => (string) $state['walk'],
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
	 * Whether a place is one place() writes: a list of parts, each a text
	 * a line holds as it is (no «/» in it) or a SHA-256 alone.
	 *
	 * @param mixed $place Place.
	 * @return bool
	 */
	private static function place_shaped( $place ) {
		if ( ! is_array( $place ) || array() === $place || ! array_is_list( $place ) ) {
			return false;
		}
		foreach ( $place as $part ) {
			$text = is_string( $part ) && '' !== $part && false === strpos( $part, '/' ) && self::name( $part ) === $part && 1 !== preg_match( self::HASHED, $part );
			$hash = is_array( $part ) && array( 'sha256' ) === array_keys( $part ) && is_string( $part['sha256'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $part['sha256'] );
			if ( ! $text && ! $hash ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The place a part ended at, as a state keeps it (after): the parts of
	 * its path, each as it is where a line would show it as it is (plain()),
	 * else by its SHA-256 alone, so that no state (the cursor of
	 * wp_site_export, a part of a report) carries a name a credential holds,
	 * or one that is no text (Codex, round 1 of branch 7, A1 and C5).
	 *
	 * @param string|null $path  A path of a root, or the name of a table.
	 * @param bool        $split Whether it is a path (by its parts), not the
	 *                           name of a table (one part, «/» in it too).
	 * @return array|null
	 */
	public static function place( $path, $split = true ) {
		if ( null === $path ) {
			return null;
		}
		$out = array();
		foreach ( $split ? explode( '/', (string) $path ) : array( (string) $path ) as $part ) {
			$out[] = self::plain( $part ) && false === strpos( $part, '/' ) ? $part : array( 'sha256' => hash( 'sha256', $part ) );
		}
		return $out;
	}

	/**
	 * The path a place names, each part held by its SHA-256 found again among
	 * the names where it lies; null where such a part is no longer there.
	 *
	 * @param array    $place place().
	 * @param callable $names function( string $path ): string[]|null, the
	 *                        names in the folder of a path ('' the root).
	 * @return string|null
	 */
	public static function found( array $place, callable $names ) {
		$path = '';
		foreach ( $place as $part ) {
			if ( is_array( $part ) ) {
				$hit = null;
				foreach ( (array) $names( $path ) as $name ) {
					if ( hash_equals( $part['sha256'], hash( 'sha256', (string) $name ) ) ) {
						$hit = (string) $name;
						break;
					}
				}
				if ( null === $hit ) {
					return null;
				}
				$part = $hit;
			}
			$path = '' === $path ? $part : $path . '/' . $part;
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
		foreach ( self::plugins() as $line ) {
			$lines[] = $line;
		}
		foreach ( self::themes() as $line ) {
			$lines[] = $line;
		}
		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '';
		foreach ( self::mu_files( $mu ) as $name ) {
			$lines[] = self::named( 'mu_plugin', $name, self::file_hash( $mu . '/' . $name ) );
		}
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
	 * The lines of the plugins: each by its file, its version, whether it
	 * runs on this site (the stored row of active_plugins). The files as
	 * get_plugins() finds them (plugin_files()), read here: get_plugins()
	 * keeps its answer in the object cache, which an external cache fills
	 * from stored values.
	 *
	 * @return array[]
	 */
	private static function plugins() {
		$active = self::stored_list( 'active_plugins' );
		$lines  = array();
		foreach ( self::plugin_files( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' ) as $file => $version ) {
			$lines[] = self::named(
				'plugin',
				(string) $file,
				array(
					'active'  => null === $active ? null : in_array( (string) $file, $active, true ),
					'version' => $version,
				)
			);
		}
		return self::sorted( $lines );
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
	 * The lines of the themes: each by its stylesheet, its version, whether
	 * it runs (the stored rows of stylesheet and template). The folders as
	 * search_theme_directories() finds them (theme_dirs()), read here:
	 * wp_get_themes() keeps the roots of the themes in a site transient.
	 *
	 * @return array[]
	 */
	private static function themes() {
		$names = array();
		foreach ( array( 'stylesheet', 'template' ) as $option ) {
			$row = AB_MCP_Sync_Rows::option( $option );
			if ( ! is_array( $row ) ) {
				$names = null;
				break;
			}
			$names[] = $row['value'];
		}
		$roots = isset( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) && array() !== $GLOBALS['wp_theme_directories'] ? $GLOBALS['wp_theme_directories'] : ( defined( 'WP_CONTENT_DIR' ) ? array( WP_CONTENT_DIR . '/themes' ) : array() );
		$lines = array();
		foreach ( self::theme_dirs( array_map( 'strval', $roots ) ) as $stylesheet => $version ) {
			$lines[] = self::named(
				'theme',
				(string) $stylesheet,
				array(
					'active'  => null === $names ? null : in_array( (string) $stylesheet, $names, true ),
					'version' => $version,
				)
			);
		}
		return self::sorted( $lines );
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
	 * The files of the must-use folder WordPress loads (its top level, *.php,
	 * as wp_get_mu_plugins()), without the loader of AlphaBridge, by strcmp().
	 *
	 * @param string $dir The must-use folder.
	 * @return string[]
	 */
	public static function mu_files( $dir ) {
		$out = array();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( self::names( $dir ) as $name ) {
			if ( self::loaded_mu( $dir, $name ) && self::LOADER !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Whether a name at the top level of the must-use folder is a file
	 * WordPress loads (or the loader of AlphaBridge).
	 *
	 * @param string $dir  The must-use folder.
	 * @param string $name Name.
	 * @return bool
	 */
	private static function loaded_mu( $dir, $name ) {
		return '.php' === substr( $name, -4 ) && is_file( $dir . '/' . $name );
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

	/* ---------------------------------------------------------- tables */

	/**
	 * The tables with the prefix of this site that neither the core nor the
	 * takeover covers, after a name, as far as the clock and the lines
	 * reach; the line tables once the list is read to its end (or could not
	 * be read).
	 *
	 * @param array|null $place    Where the part before ended: the table
	 *                            (without the prefix) as place() keeps it.
	 * @param float      $deadline Until when.
	 * @param int        $room     Lines at most.
	 * @return array{lines:array[],done:bool,after:array|null} after as place() keeps it.
	 */
	public static function table_lines( $place, $deadline, $room ) {
		$names = self::tables();
		// A table held by its SHA-256 that is gone since: the list is not read
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
	 * copies of one schema: the prefix of every name, AUTO_INCREMENT.
	 *
	 * @param string $create The statement.
	 * @param string $prefix The prefix of the site.
	 * @return string
	 */
	public static function schema( $create, $prefix ) {
		$text = (string) preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', (string) $create );
		$text = str_replace( '`' . $prefix, '`', $text );
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
		// The place of the part before, a part held by its SHA-256 found
		// again; one gone since leaves the root not counted to its end.
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
	 * the fonts (a root of their own) and the folders of AlphaBridge, in the
	 * must-use folder its files WordPress loads (lines of their own) and the
	 * loader.
	 *
	 * @param string $root Root.
	 * @return callable(string $dir, string $name): bool
	 */
	private static function skip_of( $root ) {
		if ( 'uploads' === $root ) {
			return static function ( $dir, $name ) {
				return 'fonts' === $name || in_array( $name, self::OWN_UPLOADS, true ) || 0 === strpos( $name, self::OWN_UPLOADS_PREFIX );
			};
		}
		if ( 'mu-plugins' === $root ) {
			return static function ( $dir, $name ) {
				return self::loaded_mu( $dir, $name );
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
	 * the check the import runs over each name of a line of the inventory
	 * (AB_MCP_Sync_Import::held_code(): the content check of the credential
	 * check and the net over the raw name, with the names of the filter
	 * ab_mcp_redact_keys): a field of a credential written into it
	 * (password=…, also encoded), or a name that does not read to its end
	 * (deeper than the check or the net reads). A name is the name of a file, a
	 * folder or a table, as a slug is that of a post: «reset-password» or
	 * «woocommerce_api_keys» holds nothing (Codex, round 1 of branch 7, A1).
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
		$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra );
		if ( 'credential' === $net ) {
			return 'credential_field';
		}
		return 'unreadable' === $net ? 'unreadable_name' : null;
	}

	/**
	 * Whether a name is shown as it is: a text a line holds as it is
	 * (name()), not of the form of a hash, and held by nothing (held()).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function plain( $name ) {
		$name = (string) $name;
		return '' !== $name && self::name( $name ) === $name && 1 !== preg_match( self::HASHED, $name ) && null === self::held( $name );
	}

	/**
	 * The line of a name of the site (NAMED): its ref the name where it is
	 * shown as it is (plain()), else its SHA-256 (hashed()), with held, why,
	 * where a credential holds it.
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
	 * name, with why (the report, AB_MCP_Sync_Report::environment()).
	 *
	 * @param array[] $lines Lines.
	 * @param array[] $held  The list of held objects of the import.
	 * @return array[]
	 */
	public static function held_here( array $lines, array $held ) {
		$codes = array();
		foreach ( $held as $one ) {
			if ( is_array( $one ) && isset( $one[0], $one[1], $one[2] ) ) {
				$codes[ $one[0] . "\0" . $one[1] ] = (string) $one[2];
			}
		}
		$out = array();
		foreach ( $lines as $line ) {
			$key   = is_array( $line ) && isset( $line['env'], $line['ref'] ) ? $line['env'] . "\0" . hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) ) : null;
			$out[] = null !== $key && ! isset( $line['held'] ) && isset( $codes[ $key ] ) && in_array( $line['env'], self::NAMED, true ) ? self::reduced( $line, $codes[ $key ] ) : $line;
		}
		return $out;
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
		switch ( $line['env'] ) {
			case 'page':
				return 'page' === $ref && $line['seq'] >= 1;
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
		}
		return false;
	}

	/* ------------------------------------------------------------ compare */

	/**
	 * The entries of the environment: one per line of either side (the
	 * pages left out), in ORDER, then by ref; with their counts and what is
	 * locked.
	 *
	 * @param array[] $staging The lines of the staging site.
	 * @param array[] $live    The lines of this site.
	 * @return array{entries:array[],counts:array,blocked:array[]}
	 */
	public static function compare( array $staging, array $live ) {
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
				$entries[] = self::entry( $env, $ref, $s[ $env ][ $ref ] ?? null, $l[ $env ][ $ref ] ?? null, $whole );
			}
		}
		return self::counted( $entries );
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
			if ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) ) {
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
		if ( 'core' === $env && 'locale' === $ref ) {
			$words .= ' Shown only: the language is the option WPLANG, compared with the options; its files are counted under languages.';
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
				$same   = 'wordpress' === $ref ? 'counted' : 'same_value';
				break;
			case 'plugin':
			case 'theme':
				$fields = array( 'version', 'active' );
				$same   = 'counted';
				break;
			case 'mu_plugin':
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
					return array( 'not_compared', array(), sprintf( 'The %1$s could not be counted to its end on %2$s (%3$s).', $ref, $s['complete'] ? 'this site' : 'the staging site', self::why_files( (string) $side['why'] ) ) );
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
			'moved'                  => 'the file or folder the call before stopped at, kept by its SHA-256 because its name holds a credential or is no text, is gone since',
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
