<?php
/**
 * The tables of the object pairs, of the takeover runs and of their steps
 * (Z8, decision 3 of the plan of 01.11.2026).
 *
 * Rows, not one option: AB_MCP_Settings::set() writes ab_mcp_options as a
 * whole, and a pair, a run and a step each change on their own. Three
 * tables with the prefix of the site:
 *
 *  - pairs: one row per object pair (AB_MCP_Sync_Pairs), unique per
 *    (source_id, kind, live_ref) and per (source_id, kind, staging_ref);
 *  - runs:  one row per run of wp_site_push or wp_site_revert (filled from
 *    branch 8 of the plan on);
 *  - steps: one row per step of a run, the old state of its pair in
 *    pair_before (the plan's steps.before: BEFORE is a reserved word of
 *    MySQL, and dbDelta() takes the columns without backticks).
 *
 * They are made with dbDelta() on activation and on plugins_loaded when
 * the stored version (OPTION) is not VERSION, and checked afterwards:
 * only tables that are there with their columns and unique keys store the
 * version. A failed attempt stores 'failed:<version>:<time>' instead and is
 * tried again after RETRY seconds or on the next activation, so a host
 * that refuses the tables does not run dbDelta() on every request.
 *
 * Not on a network: there are no tables on a multisite installation, and
 * status() says so; the tools of Z8 refuse with that.
 *
 * Uninstall (uninstall.php) drops the tables and removes OPTION, under the
 * rule that keeps the shared data while Pro is installed.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Schema
 */
final class AB_MCP_Sync_Schema {

	/** The option that holds the installed version of the tables. */
	const OPTION = 'ab_mcp_staging_db_version';

	/** The version of the tables this code makes. */
	const VERSION = 1;

	/** Seconds before a failed attempt is made again on plugins_loaded. */
	const RETRY = 3600;

	/**
	 * The tables, without the prefix of the site.
	 */
	const TABLES = array(
		'pairs' => 'ab_mcp_staging_pairs',
		'runs'  => 'ab_mcp_staging_runs',
		'steps' => 'ab_mcp_staging_steps',
	);

	/**
	 * The columns of each table as dbDelta() takes them: one per line, the
	 * types as DESCRIBE answers them (with the display width MariaDB keeps),
	 * quoted defaults, no backticks. Every indexed text column holds at most
	 * 191 characters: 764 bytes in utf8mb4, within the 767 bytes InnoDB
	 * allows per column in its older row formats.
	 */
	const COLUMNS = array(
		'pairs' => array(
			'id'           => 'bigint(20) unsigned NOT NULL auto_increment',
			'source_id'    => "varchar(64) NOT NULL default ''",
			'kind'         => "varchar(32) NOT NULL default ''",
			'live_ref'     => "varchar(191) NOT NULL default ''",
			'staging_ref'  => "varchar(191) NOT NULL default ''",
			'state'        => "varchar(20) NOT NULL default 'active'",
			'override'     => 'longtext NULL',
			'canon'        => "varchar(20) NOT NULL default ''",
			'base_live'    => "char(64) NOT NULL default ''",
			'base_staging' => "char(64) NOT NULL default ''",
			'base_extra'   => 'longtext NULL',
			'base_time'    => "bigint(20) unsigned NOT NULL default '0'",
			'base_origin'  => "varchar(20) NOT NULL default ''",
			'last_run'     => 'bigint(20) unsigned NULL',
			'updated'      => "bigint(20) unsigned NOT NULL default '0'",
		),
		'runs'  => array(
			'run_id'      => 'bigint(20) unsigned NOT NULL auto_increment',
			'type'        => "varchar(10) NOT NULL default ''",
			'source_id'   => "varchar(64) NOT NULL default ''",
			'key_id'      => "varchar(64) NOT NULL default ''",
			'pair_print'  => "char(64) NOT NULL default ''",
			'report_hash' => "char(64) NOT NULL default ''",
			'plan_hash'   => "char(64) NOT NULL default ''",
			'user_id'     => "bigint(20) unsigned NOT NULL default '0'",
			'connection'  => "varchar(64) NOT NULL default ''",
			'state'       => "varchar(20) NOT NULL default ''",
			'created'     => "bigint(20) unsigned NOT NULL default '0'",
			'updated'     => "bigint(20) unsigned NOT NULL default '0'",
			'closed'      => "bigint(20) unsigned NOT NULL default '0'",
			'heartbeat'   => "bigint(20) unsigned NOT NULL default '0'",
			'expires'     => "bigint(20) unsigned NOT NULL default '0'",
			'budget'      => 'longtext NULL',
		),
		'steps' => array(
			'run_id'      => "bigint(20) unsigned NOT NULL default '0'",
			'seq'         => "int(10) unsigned NOT NULL default '0'",
			'pair_id'     => 'bigint(20) unsigned NULL',
			'op'          => "varchar(10) NOT NULL default ''",
			'phase'       => "tinyint(3) unsigned NOT NULL default '0'",
			'depends'     => 'longtext NULL',
			'state'       => "varchar(20) NOT NULL default 'planned'",
			'parts'       => 'longtext NULL',
			'pair_before' => 'longtext NULL',
			'result'      => 'longtext NULL',
			'time'        => "bigint(20) unsigned NOT NULL default '0'",
		),
	);

	/**
	 * The keys of each table, as dbDelta() takes them (two spaces after
	 * PRIMARY KEY, KEY rather than INDEX, the columns without spaces).
	 */
	const KEYS = array(
		'pairs' => array(
			'PRIMARY KEY  (id)',
			'UNIQUE KEY live_ref (source_id,kind,live_ref)',
			'UNIQUE KEY staging_ref (source_id,kind,staging_ref)',
		),
		'runs'  => array(
			'PRIMARY KEY  (run_id)',
			'KEY state (state)',
		),
		'steps' => array(
			'PRIMARY KEY  (run_id,seq)',
			'KEY pair_id (pair_id)',
		),
	);

	/**
	 * The unique keys check() asks for, by the name SHOW INDEX gives them.
	 */
	const UNIQUE = array(
		'pairs' => array( 'PRIMARY', 'live_ref', 'staging_ref' ),
		'runs'  => array( 'PRIMARY' ),
		'steps' => array( 'PRIMARY' ),
	);

	/**
	 * The states of a run that is closed (decision 3); every other state
	 * (open, interrupted, reverting, or one this code does not know) is not.
	 */
	const CLOSED = array( 'closed_done', 'closed_findings', 'closed_aborted', 'closed_checked', 'closed_reverted' );

	/** @var array|null What status() found in this request. */
	private static $status = null;

	/**
	 * The full name of a table on this site.
	 *
	 * @param string $table 'pairs', 'runs' or 'steps'.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $table ];
	}

	/**
	 * The statements for dbDelta(), one CREATE TABLE per table.
	 *
	 * @return string[]
	 */
	public static function sql() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$out     = array();
		foreach ( array_keys( self::TABLES ) as $table ) {
			$lines = array();
			foreach ( self::COLUMNS[ $table ] as $column => $definition ) {
				$lines[] = $column . ' ' . $definition;
			}
			$lines = array_merge( $lines, self::KEYS[ $table ] );
			$out[] = 'CREATE TABLE ' . self::table( $table ) . " (\n" . implode( ",\n", $lines ) . "\n) " . $collate . ';';
		}
		return $out;
	}

	/**
	 * On plugins_loaded: make or bring up the tables when the stored version
	 * is not this one, not while a failed attempt is younger than RETRY. On
	 * a network install() makes nothing.
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( self::OPTION, '' );
		if ( (string) self::VERSION === $stored || self::wait_after_failure( $stored ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Make or bring up the tables with dbDelta() and check them.
	 *
	 * @return string[]|WP_Error What dbDelta() changed (empty when nothing),
	 *                           or why the tables are not there.
	 */
	public static function install() {
		self::$status = null;
		if ( is_multisite() ) {
			return new WP_Error( 'ab_mcp_staging_multisite', 'The tables of the object pairs are not made on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the live site.' );
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$changes  = dbDelta( self::sql() );
		$problems = self::check();
		if ( array() !== $problems ) {
			update_option( self::OPTION, 'failed:' . self::VERSION . ':' . time(), true );
			return new WP_Error(
				'ab_mcp_staging_tables_failed',
				sprintf( 'The tables of the object pairs could not be made (%s). Ask the site administrator or the host to let the database user of WordPress create tables and indexes; then Deactivate and Activate AlphaBridge in wp-admin, or wait an hour for the next attempt.', implode( '; ', $problems ) ),
				array( 'problems' => $problems )
			);
		}
		update_option( self::OPTION, (string) self::VERSION, true );
		return array_values( (array) $changes );
	}

	/**
	 * Whether the tables can be used on this site, and if not, why:
	 * 'multisite', 'not_installed' (no version stored), 'failed' (the last
	 * attempt failed), 'outdated' (another version stored) or 'missing' (the
	 * version is stored, a table or a key is not there). Read once per
	 * request.
	 *
	 * @return array{available:bool,reason:string|null}
	 */
	public static function status() {
		if ( null !== self::$status ) {
			return self::$status;
		}
		$reason = null;
		$stored = (string) get_option( self::OPTION, '' );
		if ( is_multisite() ) {
			$reason = 'multisite';
		} elseif ( '' === $stored ) {
			$reason = 'not_installed';
		} elseif ( 0 === strpos( $stored, 'failed:' ) ) {
			$reason = 'failed';
		} elseif ( (string) self::VERSION !== $stored ) {
			$reason = 'outdated';
		} elseif ( array() !== self::check() ) {
			$reason = 'missing';
		}
		self::$status = array(
			'available' => null === $reason,
			'reason'    => $reason,
		);
		return self::$status;
	}

	/**
	 * @return bool Whether the tables can be used.
	 */
	public static function available() {
		return self::status()['available'];
	}

	/**
	 * Forget what status() found (after a change of the tables in this
	 * request).
	 */
	public static function forget() {
		self::$status = null;
	}

	/**
	 * What is missing: a table, a column, a unique key.
	 *
	 * @return string[] Empty when all is there.
	 */
	public static function check() {
		global $wpdb;
		$problems = array();
		foreach ( array_keys( self::TABLES ) as $table ) {
			$name  = self::table( $table );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( $name !== $found ) {
				$problems[] = 'table ' . $name . ' missing';
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name is ours, checked above.
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$name`", 0 );
			foreach ( array_diff( array_keys( self::COLUMNS[ $table ] ), $columns ) as $column ) {
				$problems[] = 'column ' . $name . '.' . $column . ' missing';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name is ours, checked above.
			$unique = (array) $wpdb->get_col( "SHOW INDEX FROM `$name` WHERE Non_unique = 0", 2 );
			foreach ( array_diff( self::UNIQUE[ $table ], $unique ) as $key ) {
				$problems[] = 'unique key ' . $name . '.' . $key . ' missing';
			}
		}
		return $problems;
	}

	/**
	 * The runs of this site that are not closed, by run_id: what the protected
	 * store keeps for a run (AB_MCP_Protected_Store::sweep()) and what a
	 * booking counts (AB_MCP_Store_Budget::book()). Null when the table cannot
	 * be read (no tables, a network, a failed query), so a caller that keeps
	 * what an open run needs keeps everything rather than nothing.
	 *
	 * @return int[]|null
	 */
	public static function open_runs() {
		global $wpdb;
		if ( ! self::available() ) {
			return null;
		}
		$closed = implode( ', ', array_fill( 0, count( self::CLOSED ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours, the states are placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT run_id FROM `' . self::table( 'runs' ) . "` WHERE state NOT IN ( $closed ) ORDER BY run_id", self::CLOSED ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $ids ) ) {
			return null;
		}
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Drop the tables of the current site and remove OPTION (uninstall.php).
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( array_keys( self::TABLES ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own tables, by their fixed names.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::table( $table ) . '`' );
		}
		delete_option( self::OPTION );
		self::$status = null;
	}

	/**
	 * Whether a failed attempt of this version is younger than RETRY.
	 *
	 * @param string $stored The stored value of OPTION.
	 * @return bool
	 */
	private static function wait_after_failure( $stored ) {
		if ( ! preg_match( '/^failed:(\d+):(\d+)\z/', $stored, $m ) || (string) self::VERSION !== $m[1] ) {
			return false;
		}
		return time() - (int) $m[2] < self::RETRY;
	}
}
