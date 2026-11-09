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
 *  - runs:  one row per run of wp_site_push or wp_site_revert (branch 8 of
 *    the plan; AB_MCP_Sync_Run): open_one is 1 while the run is not closed
 *    and NULL once it is, so the unique key on it lets one run at a time be
 *    open on a site (decision 15); forward_closed_at marks a qualified
 *    close (decision 20); calls counts the calls of the run; plan holds
 *    what it writes;
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

	/**
	 * The version of the tables this code makes: 2 since branch 8 of the
	 * plan (the runs: one open run per site by a unique key, the mark of a
	 * qualified close, the calls of a run and its plan); 3 with keep_until,
	 * the time a closed run's backups are kept until (Codex round 1 of
	 * branch 8, A7).
	 */
	const VERSION = 3;

	/**
	 * Days the backups of a run that closed without qualifying (aborted,
	 * closed as checked, reverted, or a run of the way back) are kept after
	 * it closed in Free (decision 20, rule 5); Pro's 30 come through
	 * AB_MCP_Protected_Store::BACKUP_DAYS_FILTER into keep_until() at the
	 * close, so that the period a close got stays with it when the edition
	 * changes (rule 6).
	 */
	const UNQUALIFIED_DAYS = 7;

	/** Qualified closes whose backups Free keeps at least (decision 20, rule 2). */
	const KEEP_LAST = 3;

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
			'open_one'    => 'tinyint(3) unsigned NULL',
			'forward_closed_at' => "bigint(20) unsigned NOT NULL default '0'",
			'keep_until'  => "bigint(20) unsigned NOT NULL default '0'",
			'calls'       => "int(10) unsigned NOT NULL default '0'",
			'plan'        => 'longtext NULL',
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
			'UNIQUE KEY open_one (open_one)',
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
		'runs'  => array( 'PRIMARY', 'open_one' ),
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
			return new WP_Error( 'ab_mcp_staging_multisite', __( 'The tables of the object pairs are not made on a multisite installation: the takeover from a staging site works between single sites. Use a single site as the live site.', 'alphabridge-mcp' ) );
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
				/* translators: %s: the problems found, separated by semicolons, as the database names them. */
				sprintf( __( 'The tables of the object pairs could not be made (%s). Ask the site administrator or the host to let the database user of WordPress create tables and indexes; then Deactivate and Activate AlphaBridge in wp-admin, or wait an hour for the next attempt.', 'alphabridge-mcp' ), implode( '; ', $problems ) ),
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
	 * The time the backups of a run that closes now are kept until by the
	 * retention contract (decision 20) as it holds at the close: a qualified
	 * close for $days days (rule 3: Pro's 30 through the filter; 0 in Free,
	 * where the last KEEP_LAST qualified closes are kept without an age,
	 * rule 2, so the period ends at the close itself); a close that does not
	 * qualify for UNQUALIFIED_DAYS, or $days where that is more (rule 5: 7
	 * days in Free, 30 in Pro). The period stays with the run when the
	 * edition changes (rule 6: Pro → Free keeps every backup with its Pro
	 * period; Free → Pro extends none). Never 0: a stored 0 means a row that
	 * closed before the period was stored (version 2), which the clean-up
	 * keeps (backup_runs(); Codex round 2 of branch 8, N1).
	 *
	 * @param int  $now       The time of the close.
	 * @param bool $qualified Whether the close qualifies (forward_closed_at).
	 * @param int  $days      Days a qualified close is kept (AB_MCP_Protected_Store::backup_days()).
	 * @return int Seconds, at least $now.
	 */
	public static function keep_until( $now, $qualified, $days ) {
		$days = max( 0, (int) $days );
		if ( $qualified ) {
			return (int) $now + $days * 86400;
		}
		return (int) $now + max( self::UNQUALIFIED_DAYS, $days ) * 86400;
	}

	/**
	 * The runs whose backups the clean-up keeps right now (decision 20, the
	 * retention contract of 03.11.2026), by run_id: every run that is not
	 * closed (rule 5 and the way back of its own run); every run a way back
	 * that is not closed needs (rule 4: a run of the type revert names the
	 * runs it takes back in its plan); the last KEEP_LAST qualified closes
	 * (forward_closed_at set, rule 2, without an age); every closed run
	 * whose keep_until is not reached (rules 3 and 5 as they held at its
	 * close, rule 6); and every run that closed without a keep_until (0: a
	 * row of version 2, closed before the period was stored), kept as long
	 * as it is there, fail closed, since no stored period says how long it
	 * was promised (Codex round 2 of branch 8, N1; such rows exist on sites
	 * of an intermediate state of branch 8 only). Null when the table cannot
	 * be read, so the caller keeps everything.
	 *
	 * @param int|null $now Time; now when null.
	 * @return array<int,true>|null
	 */
	public static function backup_runs( $now = null ) {
		$rows = self::backup_rows( $now );
		return null === $rows ? null : array_filter( $rows );
	}

	/**
	 * Every run of this site by run_id, with what the retention contract
	 * says of its backups right now: true where the clean-up keeps them
	 * (backup_runs()), false where their period is reached and the run is
	 * not among the last KEEP_LAST qualified closes. The clean-up tells a run
	 * whose row says its backups go from a run without a row by this (a
	 * group of a run that was never made, or whose row is gone with a
	 * database put back from an older copy), which it keeps by its age
	 * instead, never removes at once (AB_MCP_Protected_Store::kept()). A
	 * run a way back that is not closed needs is named true also where it
	 * has no row. Null when the table cannot be read, so the caller keeps
	 * everything.
	 *
	 * The rows are read without their plan, which only a run that is not
	 * closed needs (rule 4) and which holds up to AB_MCP_Sync_Run::MAX_PLAN
	 * per run: the plan of each run that is not closed is read for it alone
	 * (one at most in the normal case, open_one; GitHub Codex round 7 on
	 * free#137, P2: before, every plan of the whole history of the site was
	 * loaded, at every clean-up and every group opened in the store, and a
	 * history that grew long enough ended them in an exhausted memory).
	 *
	 * @param int|null $now Time; now when null.
	 * @return array<int,bool>|null
	 */
	public static function backup_rows( $now = null ) {
		global $wpdb;
		if ( ! self::available() ) {
			return null;
		}
		$now = null === $now ? time() : (int) $now;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$rows = $wpdb->get_results( 'SELECT run_id, type, state, closed, forward_closed_at, keep_until FROM `' . self::table( 'runs' ) . '` ORDER BY run_id', ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		$keep      = array();
		$qualified = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['run_id'];
			// Every row is named: false until a rule keeps it (a way back
			// read before may have named it already).
			if ( ! array_key_exists( $id, $keep ) ) {
				$keep[ $id ] = false;
			}
			if ( ! in_array( (string) $row['state'], self::CLOSED, true ) ) {
				$keep[ $id ] = true;
				// The runs a way back that is not closed needs (rule 4), from
				// its plan, read for this run alone; a read that fails keeps
				// everything (null), as a table that cannot be read.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
				$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT plan FROM `' . self::table( 'runs' ) . '` WHERE run_id = %d', $id ) );
				if ( '' !== (string) $wpdb->last_error ) {
					return null;
				}
				$plan = is_string( $raw ) ? json_decode( $raw, true, 16 ) : null;
				foreach ( is_array( $plan ) && isset( $plan['reverts'] ) && is_array( $plan['reverts'] ) ? $plan['reverts'] : array() as $needed ) {
					$keep[ (int) $needed ] = true;
				}
				continue;
			}
			// The period the close got (rules 3, 5, 6); a row without one is
			// kept (N1).
			if ( 0 === (int) $row['keep_until'] || (int) $row['keep_until'] > $now ) {
				$keep[ $id ] = true;
			}
			if ( (int) $row['forward_closed_at'] > 0 ) {
				$qualified[ $id ] = (int) $row['forward_closed_at'];
			}
		}
		// The last KEEP_LAST qualified closes, by the time they closed, the
		// younger run first where two closed in the same second (the rows come
		// by run_id ascending, and arsort() kept the older one first; GitHub
		// Codex round 3 on free#137, P2).
		uksort(
			$qualified,
			static function ( $a, $b ) use ( $qualified ) {
				$by_time = $qualified[ $b ] <=> $qualified[ $a ];
				return 0 !== $by_time ? $by_time : $b <=> $a;
			}
		);
		$n = 0;
		foreach ( $qualified as $id => $at ) {
			++$n;
			if ( $n <= self::KEEP_LAST ) {
				$keep[ $id ] = true;
			}
		}
		return $keep;
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
