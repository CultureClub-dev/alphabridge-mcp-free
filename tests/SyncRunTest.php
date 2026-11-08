<?php
/**
 * The run of the takeover as far as a test without a database shows it
 * (Z8 branch 8; decisions 15 to 20, 23, 32 and 33):
 *
 * - wp_site_push and wp_site_revert registered like the other tools of the
 *   takeover (manage_options, the access level Full, destructive and
 *   dangerous, the confirmation with when, state and preview): push the
 *   carrying over (CARRY_OVER), revert a way back (WAYS_BACK, never held);
 *   when a strict false for next, step, status and abort (revert: next,
 *   status) and true for plan and anything else; refused with the way
 *   without an action, without a confirmed key, on a network;
 * - the means (15.11): each criterion with its level, one insufficient
 *   refuses with the two ways and the change at the host, tight lets the
 *   run go, a limit set_time_limit() can raise is tight at most;
 * - the lock of a boundary (decision 19): the record with its time, the
 *   owner passes, every other writer of a covered object is refused (REST
 *   with 423, the trash and deletions, meta by object and by id, the
 *   classic way with the core's text, a covered option still), an object
 *   not covered is free, a record past its time is none;
 * - the engine of the kind post (decision 17, 21, 23): the rule of this
 *   version for meta keys; the target of a line (status, the confirmed
 *   choice, the UTC date derived, the parent and the terms by the map) and
 *   each refusal with its lock (type, password, status, parent, author,
 *   meta, a new post with plain meta, taxonomy); the hashes of the parts
 *   stable and the terms as a set; a lock names its stage and the way;
 * - the run: its shape, the connection it is bound to, when the lock is
 *   fresh (decision 18); the retention of the backups (decision 20) from
 *   the rows of the runs: the last three qualified closes, the days of Pro,
 *   what a way back needs, seven days for an unqualified close;
 * - the plan: which entry of a report is a step, locked or nothing
 *   (verdict), the order of the graph with a cycle locked (order), the
 *   lines of the packages against the report (line_of).
 *
 * The run on two WordPress sites: tests/integration/SyncRunTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Change_Lock;
use AB_MCP_Confirm_Token;
use AB_MCP_Staging_Gate;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Post_Writer;
use AB_MCP_Sync_Push;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Revert;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-run-stubs.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/../includes/sync/class-sync-push.php';
require_once __DIR__ . '/../includes/sync/class-sync-revert.php';
require_once __DIR__ . '/SyncTrustSites.php';

/**
 * A $wpdb that answers the rows of the runs the retention reads, and the
 * three questions of AB_MCP_Sync_Schema::check() as AbTestSyncWpdb does.
 */
final class SyncRunRowsWpdb {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';
	/** @var string[] */
	public $queries = array();
	/** @var array[] */
	public $rows = array();

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_var( $query ) {
		$this->queries[] = (string) $query;
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", (string) $query, $m ) ) {
			return str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[1] ) );
		}
		// The plan of one run (AB_MCP_Sync_Schema::backup_rows()).
		if ( preg_match( '/^SELECT plan FROM `[^`]+` WHERE run_id = (\d+)$/', (string) $query, $m ) ) {
			foreach ( $this->rows as $row ) {
				if ( isset( $row['run_id'] ) && (int) $row['run_id'] === (int) $m[1] ) {
					return array_key_exists( 'plan', $row ) ? $row['plan'] : null;
				}
			}
		}
		return null;
	}

	public function get_col( $query, $x = 0 ) {
		$this->queries[] = (string) $query;
		$db              = new \AbTestSyncWpdb();
		$db->tables      = \AbTestSyncWpdb::all_tables();
		return $db->get_col( $query, $x );
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = (string) $query;
		// Only the columns a plain SELECT names, as the database answers.
		if ( preg_match( '/^SELECT ((?:\w+, )*\w+) FROM /', (string) $query, $m ) ) {
			$columns = array_flip( explode( ', ', $m[1] ) );
			return array_map( static fn( array $row ): array => array_intersect_key( $row, $columns ), $this->rows );
		}
		return $this->rows;
	}

	/** A write (START TRANSACTION, COMMIT, ROLLBACK): recorded, answered true. */
	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return true;
	}
}

/**
 * A $wpdb that answers the row of an option from $ab_test_options, as the
 * lock of a boundary reads its record afresh (AB_MCP_Sync_Rows::option():
 * query() and last_result), the three questions of AB_MCP_Sync_Schema::
 * check() and whether the table of the runs has a row ($runs; N3); every
 * other question gives no row.
 */
final class SyncRunOptionWpdb {
	public $prefix      = 'wp_';
	public $options     = 'wp_options';
	public $posts       = 'wp_posts';
	public $last_error  = '';
	public $last_result = array();
	/** @var int Reads of the record. */
	public $record_reads = 0;
	/** @var int|null The run_id the table of the runs answers (null: no run). */
	public $runs = null;
	/** @var int Reads of the table of the runs. */
	public $run_reads = 0;
	/** @var int|null The post in the trash the query for a slug finds (null: none). */
	public $trashed = null;

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_var( $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", (string) $query, $m ) ) {
			return str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[1] ) );
		}
		if ( 'SELECT run_id FROM `wp_ab_mcp_staging_runs` LIMIT 1' === (string) $query ) {
			++$this->run_reads;
			return $this->runs;
		}
		if ( false !== strpos( (string) $query, "post_status = 'trash'" ) ) {
			return $this->trashed;
		}
		return null;
	}

	public function get_col( $query, $x = 0 ) {
		$db         = new \AbTestSyncWpdb();
		$db->tables = \AbTestSyncWpdb::all_tables();
		return $db->get_col( $query, $x );
	}

	public function query( $sql ) {
		$this->last_result = array();
		if ( preg_match( "/FROM wp_options WHERE option_name = '([^']*)' LIMIT 1/", (string) $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( AB_MCP_Sync_Guard::OPTION === $name ) {
				++$this->record_reads;
			}
			if ( array_key_exists( $name, $GLOBALS['ab_test_options'] ) ) {
				$value             = $GLOBALS['ab_test_options'][ $name ];
				$this->last_result = array( (object) array( 'option_name' => $name, 'option_value' => is_array( $value ) ? serialize( $value ) : (string) $value, 'autoload' => 'on' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				return 1;
			}
		}
		return 0;
	}
}

/**
 * A $wpdb that answers the rows of one post as the engine of the kind post
 * reads them (AB_MCP_Sync_Rows::select(): query() and last_result): the
 * post, its meta, its terms, each term; any other question gives no row.
 * The post may change after its first reading (edit_after_first: another
 * writer between two readings, A4).
 */
final class SyncRunPostWpdb {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $termmeta           = 'wp_termmeta';
	public $term_relationships = 'wp_term_relationships';
	public $options            = 'wp_options';
	public $users              = 'wp_users';
	public $last_error         = '';
	public $last_result        = array();
	/** @var array<int,array> Post rows by id. */
	public $rows = array();
	/** @var array<int,array[]> Meta rows by post id. */
	public $meta = array();
	/** @var array<int,array[]> Relations by object id: term_taxonomy_id, term_order, term_id, taxonomy, slug. */
	public $relations = array();
	/** @var array<int,array> Term rows by term id. */
	public $term_rows = array();
	/** @var array<int,array[]> Revisions by post_parent: ID, post_content, post_excerpt. */
	public $revisions = array();
	/** @var array<int,string>|null A title the post gets once it was read once. */
	public $edit_after_first = null;
	/** @var int Readings of the post row. */
	public $post_reads = 0;

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		return null;
	}

	public function get_row( $query, $output = null ) {
		return null;
	}

	public function get_results( $query, $output = null ) {
		return array();
	}

	public function query( $sql ) {
		$this->last_result = array();
		$rows              = array();
		// The way back of a meta row, byte for byte conditional (restore_meta()).
		if ( preg_match( "/^UPDATE wp_postmeta SET meta_value = '((?:[^'\\\\]|\\\\.)*)' WHERE meta_id = (\\d+) AND post_id = (\\d+) AND CAST\\( meta_value AS BINARY \\) = CAST\\( '((?:[^'\\\\]|\\\\.)*)' AS BINARY \\)/s", (string) $sql, $m ) ) {
			foreach ( $this->meta[ (int) $m[3] ] ?? array() as $k => $row ) {
				if ( (int) $row['meta_id'] === (int) $m[2] && (string) $row['meta_value'] === stripslashes( $m[4] ) ) {
					$this->meta[ (int) $m[3] ][ $k ]['meta_value'] = stripslashes( $m[1] );
					return 1;
				}
			}
			return 0;
		}
		if ( preg_match( '/FROM wp_posts WHERE ID = (\d+)$/', (string) $sql, $m ) ) {
			++$this->post_reads;
			if ( isset( $this->rows[ (int) $m[1] ] ) ) {
				$row = $this->rows[ (int) $m[1] ];
				if ( $this->post_reads > 1 && null !== $this->edit_after_first ) {
					$row['post_title'] = $this->edit_after_first;
				}
				$rows[] = $row;
			}
		} elseif ( preg_match( '/^SELECT post_type FROM wp_posts WHERE ID = (\d+)$/', (string) $sql, $m ) ) {
			// The type of a post (AB_MCP_Sync_Rows::post_type_of(); the featured image, branch 9).
			$rows = isset( $this->rows[ (int) $m[1] ] ) ? array( array( 'post_type' => $this->rows[ (int) $m[1] ]['post_type'] ) ) : array();
		} elseif ( preg_match( '/FROM wp_postmeta WHERE post_id = (\d+) ORDER BY meta_id ASC$/', (string) $sql, $m ) ) {
			$rows = $this->meta[ (int) $m[1] ] ?? array();
			} elseif ( preg_match( "/FROM wp_postmeta WHERE post_id = (\\d+) AND meta_key = '([^']*)' ORDER BY meta_id ASC$/", (string) $sql, $m ) ) {
				// The rows of one key as the database matches them: with a
				// collation that compares without regard to letter case.
				$rows = array_values( array_filter( $this->meta[ (int) $m[1] ] ?? array(), static fn( array $r ): bool => 0 === strcasecmp( (string) $r['meta_key'], stripslashes( $m[2] ) ) ) );
		} elseif ( preg_match( '/FROM wp_term_relationships AS tr .* WHERE tr\.object_id = (\d+) ORDER BY/', (string) $sql, $m ) ) {
			$rows = $this->relations[ (int) $m[1] ] ?? array();
		} elseif ( preg_match( "/FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON tt\.term_id = t\.term_id WHERE t\.term_id = (\d+) AND tt\.taxonomy = BINARY '([^']*)'/", (string) $sql, $m ) ) {
			$rows = isset( $this->term_rows[ (int) $m[1] ] ) && $this->term_rows[ (int) $m[1] ]['taxonomy'] === stripslashes( $m[2] ) ? array( $this->term_rows[ (int) $m[1] ] ) : array();
		} elseif ( preg_match( "/FROM wp_posts WHERE post_parent = (\d+) AND post_type = 'revision' AND CAST\( (post_content|post_excerpt) AS BINARY \) = CAST\( '(.*)' AS BINARY \) AND OCTET_LENGTH/s", (string) $sql, $m ) ) {
			foreach ( array_reverse( $this->revisions[ (int) $m[1] ] ?? array() ) as $revision ) {
				if ( ( $revision[ $m[2] ] ?? null ) === stripslashes( $m[3] ) ) {
					$rows = array( array( 'ID' => $revision['ID'] ) );
					break;
				}
			}
		}
		$this->last_result = array_map( static fn( $r ) => (object) $r, $rows );
		return count( $rows );
	}
}

/**
 * A stream of a log whose read fails after so many bytes (fread() false),
 * for the reader of the error log: ab-log-fail://<anything>. Content and
 * the byte the failure comes at are set on the class.
 */
final class SyncRunFailingLogStream {
	/** @var string The content. */
	public static $content = '';
	/** @var int The read fails once this many bytes were given. */
	public static $fail_at = 0;
	/** @var resource|null */
	public $context;
	private $at = 0;

	public function stream_open( $path, $mode, $options, &$opened ) {
		$this->at = 0;
		return true;
	}

	public function stream_read( $count ) {
		if ( $this->at >= strlen( self::$content ) ) {
			return ''; // The end of the file, as a real stream answers it.
		}
		if ( $this->at >= self::$fail_at ) {
			return false;
		}
		$chunk     = substr( self::$content, $this->at, min( (int) $count, self::$fail_at - $this->at ) );
		$this->at += strlen( $chunk );
		return $chunk;
	}

	public function stream_eof() {
		return $this->at >= strlen( self::$content );
	}

	public function stream_seek( $offset, $whence ) {
		if ( SEEK_SET !== $whence || $offset < 0 || $offset > strlen( self::$content ) ) {
			return false;
		}
		$this->at = (int) $offset;
		return true;
	}

	public function stream_tell() {
		return $this->at;
	}

	public function stream_stat() {
		return array( 'size' => strlen( self::$content ), 'mode' => 0100644 );
	}

	public function url_stat( $path, $flags ) {
		return array( 'size' => strlen( self::$content ), 'mode' => 0100644 );
	}

	public function stream_close() {
	}

	public function stream_set_option( $option, $arg1, $arg2 ) {
		return false;
	}
}

final class SyncRunTest extends TestCase {

	use SyncTrustSites;

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::$now = null;
		$GLOBALS['ab_test_can']      = static fn(): bool => true;
		$GLOBALS['ab_test_meta_ids'] = array();
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		// The three taxonomies of this version, registered with the rights
		// WordPress gives them (get_taxonomy(), taxonomy_exists()).
		$GLOBALS['ab_test_way']['taxonomies'] = array(
			'category'    => (object) array( 'name' => 'category', 'cap' => (object) array( 'manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories', 'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_posts' ) ),
			'post_tag'    => (object) array( 'name' => 'post_tag', 'cap' => (object) array( 'manage_terms' => 'manage_post_tags', 'edit_terms' => 'edit_post_tags', 'delete_terms' => 'delete_post_tags', 'assign_terms' => 'assign_post_tags' ) ),
			'post_format' => (object) array( 'name' => 'post_format', 'cap' => (object) array( 'manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories', 'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_posts' ) ),
		);
		$GLOBALS['ab_test_terms_by_slug'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'], $GLOBALS['wpdb'], $GLOBALS['ab_test_way'], $GLOBALS['ab_test_terms_by_slug'] );
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::$now       = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Resources::$time  = null;
		AB_MCP_Sync_Run::$clock       = null;
		$GLOBALS['ab_test_multisite'] = false;
	}

	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/* --------------------------------------------------------- the tools */

	public function testBothToolsAreRegisteredLikeTheOtherToolsOfTheTakeover(): void {
		foreach ( array( 'wp_site_push', 'wp_site_revert' ) as $name ) {
			$def = self::def( $name );
			self::assertSame( 'manage_options', $def['capability'], $name );
			self::assertFalse( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name . ' writes: the access level Full.' );
			self::assertTrue( AB_MCP_Tool_Registry::is_destructive( $name, $def ), $name );
			self::assertTrue( $def['destructive'], $name . ': said in its definition.' );
			self::assertTrue( $def['dangerous'], $name . ' is Mighty.' );
			self::assertFalse( AB_MCP_Tool_Registry::is_open_world( $name, $def ), $name );
			self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'full', $name, $def ), $name );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', $name, $def ), $name );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', $name, $def ), $name );
			self::assertFalse( AB_MCP_Staging_Gate::concerns( $name, $def ), $name . ' is never held by «Staging first».' );
			self::assertSame( array( 'action' ), $def['inputSchema']['required'], $name );
			self::assertTrue( AB_MCP_Confirm_Token::required( $def ), $name );
			self::assertTrue( AB_MCP_Confirm_Token::conditional( $def['confirm'] ), $name . ' confirms plan only.' );
			self::assertArrayHasKey( AB_MCP_Confirm_Token::PARAM, $def['inputSchema']['properties'], $name );
			self::assertStringNotContainsString( 'Runs in two steps', $def['description'], $name );
			self::assertStringContainsString( 'action=plan runs in two steps', $def['description'], $name );
			self::assertStringNotContainsString( 'Pro', $def['description'], $name . ': no edition in the text.' );
		}
		self::assertContains( 'wp_site_push', AB_MCP_Staging_Gate::CARRY_OVER );
		self::assertContains( 'wp_site_revert', AB_MCP_Staging_Gate::WAYS_BACK, 'A way back the filter cannot take away.' );
		$push = self::def( 'wp_site_push' );
		self::assertSame( AB_MCP_Sync_Push::ACTIONS, $push['inputSchema']['properties']['action']['enum'] );
		self::assertSame( AB_MCP_Sync_Push::CHOICES, $push['inputSchema']['properties']['choices']['items']['properties']['choice']['enum'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'push_when' ), $push['confirm']['when'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'push_state' ), $push['confirm']['state'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'push_preview' ), $push['confirm']['preview'] );
		foreach ( array( 'Nothing is deleted', 'wp_site_revert', '60 seconds', 'interrupted', 'wp_publish_post() by the cron', 'as_draft', 'posts and pages', 'manage_options and the access level Full' ) as $says ) {
			self::assertStringContainsString( $says, $push['description'] );
		}
		$revert = self::def( 'wp_site_revert' );
		self::assertSame( AB_MCP_Sync_Revert::ACTIONS, $revert['inputSchema']['properties']['action']['enum'] );
		self::assertSame( array( AB_MCP_Tools_Staging::class, 'revert_when' ), $revert['confirm']['when'] );
		foreach ( array( 'byte for byte', 'never removed', 'residual', 'check needed', 'edit_date' ) as $says ) {
			self::assertStringContainsString( $says, $revert['description'] );
		}
	}

	public function testOnlyPlanAsksForTheToken(): void {
		foreach ( array( 'next', 'step', 'status', 'abort' ) as $action ) {
			self::assertFalse( AB_MCP_Tools_Staging::push_when( array( 'action' => $action ) ), $action );
			self::assertFalse( AB_MCP_Sync_Push::when( array( 'action' => $action ) ), $action );
		}
		foreach ( array( array( 'action' => 'plan' ), array(), array( 'action' => 'other' ), array( 'action' => array( 'next' ) ) ) as $a ) {
			self::assertTrue( AB_MCP_Tools_Staging::push_when( $a ), (string) json_encode( $a ) );
		}
		foreach ( array( 'next', 'status' ) as $action ) {
			self::assertFalse( AB_MCP_Tools_Staging::revert_when( array( 'action' => $action ) ), $action );
		}
		foreach ( array( array( 'action' => 'plan' ), array(), array( 'action' => 'abort' ) ) as $a ) {
			self::assertTrue( AB_MCP_Tools_Staging::revert_when( $a ), (string) json_encode( $a ) );
		}
		// The gate runs next without asking state (which would refuse without a key).
		self::assertTrue( AB_MCP_Confirm_Token::gate( 'wp_site_push', self::def( 'wp_site_push' ), array( 'action' => 'status' ) ) );
		self::assertTrue( AB_MCP_Confirm_Token::gate( 'wp_site_revert', self::def( 'wp_site_revert' ), array( 'action' => 'next', 'run_id' => 1 ) ) );
	}

	public function testEveryActionNeedsAConfirmedKeyAndNoNetworkAndAnAction(): void {
		foreach ( array( 'plan', 'next', 'step', 'status', 'abort' ) as $action ) {
			$refused = AB_MCP_Sync_Push::call( array( 'action' => $action, 'run_id' => 1 ) );
			self::assertInstanceOf( WP_Error::class, $refused, $action );
			self::assertSame( 'ab_mcp_sync_pairs_no_anchor', $refused->get_error_code(), $action );
		}
		self::assertSame( 'ab_mcp_sync_pairs_no_anchor', AB_MCP_Tools_Staging::push_state( array( 'action' => 'plan' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_pairs_no_anchor', AB_MCP_Tools_Staging::revert_state( array( 'action' => 'plan' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_pairs_no_anchor', AB_MCP_Tools_Staging::site_revert( array( 'action' => 'next', 'run_id' => 1 ) )->get_error_code() );
		foreach ( array( array(), array( 'action' => 'confirm' ), array( 'action' => null ) ) as $a ) {
			self::assertSame( 'ab_mcp_sync_push_action', AB_MCP_Sync_Push::call( $a )->get_error_code(), (string) json_encode( $a ) );
			self::assertSame( 'ab_mcp_sync_push_action', AB_MCP_Sync_Push::state( $a )->get_error_code() );
			self::assertSame( 'ab_mcp_sync_revert_action', AB_MCP_Sync_Revert::call( $a )->get_error_code() );
			self::assertSame( 'ab_mcp_sync_revert_action', AB_MCP_Sync_Revert::state( $a )->get_error_code() );
		}
		$GLOBALS['ab_test_multisite'] = true;
		self::assertSame( 'ab_mcp_sync_multisite', AB_MCP_Sync_Push::call( array( 'action' => 'status' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_multisite', AB_MCP_Sync_Revert::preview( array( 'action' => 'plan' ) )->get_error_code() );
	}

	/* --------------------------------------------------------- the means */

	/** Measurements that suffice. */
	private static function enough(): array {
		return array(
			'free'                => 50 * 1073741824,
			'time_limit'          => 0,
			'time_limit_settable' => false,
			'memory_limit'        => -1,
			'write_seconds'       => 0.01,
			'read_seconds'        => 0.01,
			'rows_seconds'        => 0.01,
		);
	}

	public function testTheMeansAreCheckedPerCriterionAndOneInsufficientRefusesWithTheTwoWays(): void {
		AB_MCP_Sync_Resources::$probe = static fn(): array => self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1048576 );
		self::assertSame( AB_MCP_Sync_Resources::OK, $check['level'] );
		self::assertSame( array( 'space', 'time', 'memory', 'tempo' ), array_column( $check['criteria'], 'name' ) );
		self::assertSame( array( 'ok', 'ok', 'ok', 'ok' ), array_column( $check['criteria'], 'level' ) );
		self::assertSame( (int) ceil( 1048576 * 1.2 ) + \AB_MCP_Store_Budget::TEST_WRITE, $check['booked'], 'The booking: the bytes with the reserve of 20 % and the test write.' );

		// Time: a limit below 30 s is insufficient, 30 to 44 tight, 45 ok; one
		// set_time_limit() can raise is tight at most.
		foreach ( array( 10 => 'insufficient', 30 => 'tight', 44 => 'tight', 45 => 'ok', 0 => 'ok' ) as $limit => $level ) {
			AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'time_limit' => $limit ) + self::enough();
			$check                        = AB_MCP_Sync_Resources::check( 1 );
			self::assertSame( $level, $check['criteria'][1]['level'], "time limit $limit" );
			self::assertSame( $level, $check['level'], "time limit $limit" );
		}
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'time_limit' => 10, 'time_limit_settable' => true ) + self::enough();
		self::assertSame( 'tight', AB_MCP_Sync_Resources::check( 1 )['criteria'][1]['level'], 'A limit set_time_limit() raises.' );
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'time_limit' => 10 ) + self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1 );
		self::assertStringContainsString( 'max_execution_time) to at least 30 s (today 10 s)', $check['criteria'][1]['change'] );
		$refusal = AB_MCP_Sync_Resources::refusal( $check );
		self::assertSame( 'ab_mcp_sync_run_resources', $refusal->get_error_code() );
		foreach ( array( 'Nothing began', '1. Set staging up with the host first:', 'max_execution_time', '2. Work directly on the live site', 'Staging first', 'staging_acknowledged' ) as $says ) {
			self::assertStringContainsString( $says, $refusal->get_error_message() );
		}
		self::assertSame( $check, $refusal->get_error_data()['resources'] );

		// Memory: 128 MiB needed; -1 is none.
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'memory_limit' => 64 * 1048576 ) + self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1 );
		self::assertSame( 'insufficient', $check['criteria'][2]['level'] );
		self::assertStringContainsString( 'at least 128 MB (today 64 MB)', $check['criteria'][2]['change'] );
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'memory_limit' => 150 * 1048576 ) + self::enough();
		self::assertSame( 'tight', AB_MCP_Sync_Resources::check( 1 )['criteria'][2]['level'] );

		// Space: what the run books with the reserve of the store, unknown is insufficient.
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'free' => 1000 ) + self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1048576 );
		self::assertSame( 'insufficient', $check['criteria'][0]['level'] );
		self::assertStringContainsString( 'Free at least', $check['criteria'][0]['change'] );
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'free' => null ) + self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1 );
		self::assertSame( 'insufficient', $check['criteria'][0]['level'] );
		self::assertStringContainsString( 'disk_free_space()', $check['criteria'][0]['change'] );

		// Tempo: projected over 30 minutes is tight, never insufficient.
		AB_MCP_Sync_Resources::$probe = static fn(): array => array( 'write_seconds' => 2.0, 'read_seconds' => 1.0 ) + self::enough();
		$check                        = AB_MCP_Sync_Resources::check( 1000 * 1048576 );
		self::assertSame( 'tight', $check['criteria'][3]['level'] );
		self::assertSame( 'tight', $check['level'] );
		self::assertGreaterThan( 1800, $check['criteria'][3]['measured'] );
	}

	public function testTheSizesOfPhpAndTheLevels(): void {
		self::assertSame( 134217728, AB_MCP_Sync_Resources::bytes_of( '128M' ) );
		self::assertSame( 1073741824, AB_MCP_Sync_Resources::bytes_of( '1G' ) );
		self::assertSame( 2048, AB_MCP_Sync_Resources::bytes_of( '2k' ) );
		self::assertSame( 500, AB_MCP_Sync_Resources::bytes_of( '500' ) );
		self::assertSame( -1, AB_MCP_Sync_Resources::bytes_of( '-1' ) );
		self::assertSame( -1, AB_MCP_Sync_Resources::bytes_of( '' ) );
		self::assertSame( 'insufficient', AB_MCP_Sync_Resources::level( 29.0, 30.0 ) );
		self::assertSame( 'tight', AB_MCP_Sync_Resources::level( 30.0, 30.0 ) );
		self::assertSame( 'tight', AB_MCP_Sync_Resources::level( 44.9, 30.0 ) );
		self::assertSame( 'ok', AB_MCP_Sync_Resources::level( 45.0, 30.0 ) );
	}

	/* ---------------------------------------------------- the boundary */

	public function testTheLockOfABoundaryRefusesOtherWritersOfACoveredObjectAndLetsTheOwnerPass(): void {
		$db                     = new SyncRunOptionWpdb();
		$GLOBALS['wpdb']        = $db;
		AB_MCP_Sync_Guard::$now = 1000;
		ab_test_add_post( 5, array( 'post_type' => 'page' ) );
		ab_test_add_post( 6, array( 'post_type' => 'page' ) );
		$GLOBALS['ab_test_meta_ids'][40] = array( 5, 'note', 'abc' );
		$GLOBALS['ab_test_meta_ids'][41] = array( 6, 'note', 'abc' );
		self::assertTrue( AB_MCP_Sync_Guard::begin( 7, 2, array( 5 => 'page' ), array( 'category' ), array( 'blogname' ) ) );
		self::assertTrue( AB_MCP_Sync_Guard::$owner );
		$record = get_option( AB_MCP_Sync_Guard::OPTION );
		self::assertSame( array( 'run_id' => 7, 'seq' => 2, 'posts' => array( '5' => 'page' ), 'taxonomies' => array( 'category' ), 'options' => array( 'blogname' ), 'terms' => array(), 'until' => 1000 + AB_MCP_Change_Lock::TIMEOUT, 'user_id' => 3 ), $record );
		self::assertTrue( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Guard::OPTION ], 'Autoloaded: no query on the requests that are not held.' );
		self::assertSame( array( '1000:3' ), get_post_meta( 5, '_edit_lock' ), 'The edit lock of wp-admin.' );
		// The owner: arm() does nothing, every write passes.
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( has_filter( 'pre_trash_post', array( AB_MCP_Sync_Guard::class, 'pre_trash' ) ) );
		self::assertFalse( AB_MCP_Sync_Guard::covers( 5 ) );

		// Another request: armed, the covered page refused on every way.
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::arm();
		do_action( 'rest_api_init' );
		self::assertNotFalse( has_filter( 'rest_pre_insert_page', array( AB_MCP_Sync_Guard::class, 'rest_pre_insert' ) ), 'The REST way of every registered type, hooked on rest_api_init.' );
		self::assertNotFalse( has_filter( 'rest_pre_insert_post', array( AB_MCP_Sync_Guard::class, 'rest_pre_insert' ) ) );
		self::assertTrue( AB_MCP_Sync_Guard::covers( 5 ) );
		self::assertGreaterThan( 0, $db->record_reads, 'The record is read afresh from its row, past the caches of the request.' );
		self::assertFalse( AB_MCP_Sync_Guard::covers( 6 ) );
		$rest = AB_MCP_Sync_Guard::rest_pre_insert( (object) array( 'ID' => 5 ), null );
		self::assertInstanceOf( WP_Error::class, $rest );
		self::assertSame( AB_MCP_Sync_Guard::CODE, $rest->get_error_code() );
		self::assertSame( 423, $rest->get_error_data()['status'] );
		self::assertStringContainsString( 'run 7, step 2', $rest->get_error_message() );
		self::assertStringContainsString( 'free again in at most 600 s', $rest->get_error_message() );
		self::assertStringContainsString( 'Nothing of yours was written', $rest->get_error_message() );
		$other = (object) array( 'ID' => 6 );
		self::assertSame( $other, AB_MCP_Sync_Guard::rest_pre_insert( $other, null ), 'Another page passes.' );
		self::assertFalse( AB_MCP_Sync_Guard::pre_trash( null, get_post( 5 ) ) );
		self::assertNull( AB_MCP_Sync_Guard::pre_trash( null, get_post( 6 ) ) );
		self::assertFalse( AB_MCP_Sync_Guard::pre_delete( null, 5 ) );
		self::assertFalse( AB_MCP_Sync_Guard::meta_by_object( null, 5 ) );
		self::assertNull( AB_MCP_Sync_Guard::meta_by_object( null, 6 ) );
		self::assertFalse( update_metadata( 'post', 5, 'note', 'x' ), 'The short-circuit of the metadata API.' );
		self::assertFalse( add_metadata( 'post', 5, 'note', 'x' ) );
		self::assertFalse( update_metadata_by_mid( 'post', 40, 'x' ), 'By the meta id, through its post.' );
		self::assertFalse( delete_metadata_by_mid( 'post', 40 ) );
		self::assertTrue( update_metadata_by_mid( 'post', 41, 'x' ), 'The meta of another page.' );
		self::assertTrue( AB_MCP_Sync_Guard::empty_content( false, array( 'ID' => 5 ) ), 'The classic way: the core\'s own text.' );
		self::assertFalse( AB_MCP_Sync_Guard::empty_content( false, array( 'ID' => 6 ) ) );
		self::assertFalse( AB_MCP_Sync_Guard::empty_content( false, array() ), 'A new post is not covered.' );
		$term = AB_MCP_Sync_Guard::pre_insert_term( 'New', 'category' );
		self::assertInstanceOf( WP_Error::class, $term );
		self::assertSame( 'New', AB_MCP_Sync_Guard::pre_insert_term( 'New', 'post_tag' ) );
		self::assertSame( 'old', AB_MCP_Sync_Guard::pre_update_option( 'new', 'blogname', 'old' ), 'A covered option keeps its value, still.' );
		self::assertSame( 'new', AB_MCP_Sync_Guard::pre_update_option( 'new', 'blogdescription', 'old' ), 'An option not covered is written.' );
		self::assertNotFalse( has_filter( 'pre_update_option', array( AB_MCP_Sync_Guard::class, 'pre_update_option' ) ), 'The generic filter: every option, whichever the record names.' );
		$reads = $db->record_reads;
		self::assertSame( 'new', AB_MCP_Sync_Guard::pre_update_option( 'new', '_transient_x', 'old' ) );
		self::assertSame( $reads, $db->record_reads, 'An option of group B (a transient) is answered without a read of the record.' );
		// delete_post_meta_by_key(): the core hands over no object and
		// delete_all; it would reach the covered page, so it is refused.
		self::assertFalse( AB_MCP_Sync_Guard::meta_delete( null, null, 'note', '', true ), 'A delete across every post while a boundary covers one.' );
		self::assertFalse( AB_MCP_Sync_Guard::meta_delete( null, 5, 'note', '', false ) );
		self::assertNull( AB_MCP_Sync_Guard::meta_delete( null, 6, 'note', '', false ), 'The meta of another page goes.' );

		// The request of the next step (the way back of an interrupted run,
		// say) was armed on plugins_loaded by the record of the step that
		// died; once it owns a boundary, its own writes pass the filters.
		self::assertTrue( AB_MCP_Sync_Guard::begin( 7, 3, array( 5 => 'page' ), array( 'category' ), array( 'blogname' ) ) );
		self::assertTrue( AB_MCP_Sync_Guard::$owner );
		self::assertFalse( AB_MCP_Sync_Guard::covers( 5 ), 'The owner is not refused by the record.' );
		$own = (object) array( 'ID' => 5 );
		self::assertSame( $own, AB_MCP_Sync_Guard::rest_pre_insert( $own, null ), 'The owner\'s REST save passes.' );
		self::assertNull( AB_MCP_Sync_Guard::pre_trash( null, get_post( 5 ) ) );
		self::assertNull( AB_MCP_Sync_Guard::meta_by_object( null, 5 ) );
		self::assertNull( AB_MCP_Sync_Guard::meta_delete( null, null, 'note', '', true ) );
		self::assertTrue( update_metadata( 'post', 5, 'note', 'x' ), 'The owner writes the meta of the covered page.' );
		self::assertTrue( update_metadata_by_mid( 'post', 40, 'x' ) );
		self::assertFalse( AB_MCP_Sync_Guard::empty_content( false, array( 'ID' => 5 ) ) );
		self::assertSame( 'New', AB_MCP_Sync_Guard::pre_insert_term( 'New', 'category' ) );
		self::assertSame( 'new', AB_MCP_Sync_Guard::pre_update_option( 'new', 'blogname', 'old' ) );
		AB_MCP_Sync_Guard::end( 7, 3 );
		self::assertFalse( AB_MCP_Sync_Guard::$owner );
		AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( AB_MCP_Sync_Guard::begin( 7, 2, array( 5 => 'page' ), array( 'category' ), array( 'blogname' ) ) );
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::arm();
		self::assertTrue( AB_MCP_Sync_Guard::covers( 5 ), 'Armed again as another request.' );

		// A request that passed plugins_loaded before the boundary began
		// (Codex round 1 of branch 8, A1): nothing was armed from a record
		// then; its write afterwards meets the record all the same.
		AB_MCP_Sync_Guard::end( 7, 2 );
		AB_MCP_Sync_Guard::disarm();
		// Every hook arm() set in this test goes (arm() ran more than once).
		while ( remove_filter( 'update_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_by_object' ), 1 ) ) {
			continue;
		}
		while ( remove_filter( 'delete_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_delete' ), 1 ) ) {
			continue;
		}
		self::assertFalse( has_filter( 'update_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_by_object' ) ) );
		AB_MCP_Sync_Guard::arm();
		self::assertNotFalse( has_filter( 'update_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_by_object' ) ), 'Hooked with no record in sight.' );
		self::assertNotFalse( has_filter( 'delete_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_delete' ) ) );
		self::assertFalse( AB_MCP_Sync_Guard::covers( 5 ), 'No boundary yet.' );
		self::assertTrue( AB_MCP_Sync_Guard::begin( 9, 1, array( 5 => 'page' ) ), 'Another process begins a boundary now.' );
		AB_MCP_Sync_Guard::$owner = false;
		self::assertTrue( AB_MCP_Sync_Guard::covers( 5 ), 'Read afresh at the write, not from what plugins_loaded saw.' );
		self::assertFalse( update_metadata( 'post', 5, 'note', 'late' ), 'The late write of the early request is refused.' );
		self::assertFalse( AB_MCP_Sync_Guard::meta_delete( null, null, 'note', '', true ) );
		AB_MCP_Sync_Guard::end( 9, 1 );
		self::assertTrue( update_metadata( 'post', 5, 'note', 'after' ), 'Free again once the boundary ended.' );
		AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( AB_MCP_Sync_Guard::begin( 7, 2, array( 5 => 'page' ), array( 'category' ), array( 'blogname' ) ) );
		AB_MCP_Sync_Guard::disarm();

		// Past its time the record is none; end() removes it.
		AB_MCP_Sync_Guard::$now = 1000 + AB_MCP_Change_Lock::TIMEOUT;
		self::assertFalse( AB_MCP_Sync_Guard::covers( 5 ), 'Past its time.' );
		self::assertNull( AB_MCP_Sync_Guard::record() );
		self::assertFalse( get_option( AB_MCP_Sync_Guard::OPTION ), 'A record past its time is removed.' );
		AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( AB_MCP_Sync_Guard::begin( 7, 3, array( 5 => 'page' ) ) );
		AB_MCP_Sync_Guard::end( 8, 3 );
		self::assertIsArray( get_option( AB_MCP_Sync_Guard::OPTION ), 'Another run\'s end leaves it.' );
		AB_MCP_Sync_Guard::end( 7, 3 );
		self::assertFalse( get_option( AB_MCP_Sync_Guard::OPTION ) );
		self::assertFalse( AB_MCP_Sync_Guard::$owner );
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( AB_MCP_Sync_Guard::covers( 5 ), 'Nothing armed without a record.' );
	}

	public function testTheFiltersOfTheLockAreHookedOnlyOnASiteThatHasHadARun(): void {
		// A site that never had a run never had a boundary: nothing is hooked
		// on its requests, and a write reads no record (Codex round 2, N3).
		// The mark is an autoloaded option; where none is stored, it is
		// derived once from the table of the runs.
		$db              = new SyncRunOptionWpdb();
		$GLOBALS['wpdb'] = $db;
		ab_test_add_post( 5, array( 'post_type' => 'page' ) );
		$hooked = static fn(): bool => false !== has_filter( 'update_post_metadata', array( AB_MCP_Sync_Guard::class, 'meta_by_object' ) ) || false !== has_filter( 'pre_trash_post', array( AB_MCP_Sync_Guard::class, 'pre_trash' ) ) || false !== has_filter( 'pre_update_option', array( AB_MCP_Sync_Guard::class, 'pre_update_option' ) );
		// No mark, no tables (a fresh site): derived as none and stored.
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( $hooked(), 'Nothing hooked on a site without a run.' );
		self::assertSame( '0', get_option( AB_MCP_Sync_Guard::MARK ) );
		self::assertTrue( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Guard::MARK ], 'Autoloaded: read with the options of every request, no query of its own.' );
		self::assertSame( 0, $db->run_reads, 'Without the tables no run can be there: the table is not asked.' );
		self::assertTrue( update_metadata( 'post', 5, 'note', 'x' ) );
		self::assertSame( 0, $db->record_reads, 'A write of this site reads no record.' );
		// The mark stored as none: nothing hooked, nothing derived.
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$db->runs = 7;
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( $hooked() );
		self::assertSame( 0, $db->run_reads, 'A stored mark is the answer; the table is not asked.' );
		// No mark, the tables there and a run in them (a site from before the
		// mark): derived as had, stored, hooked.
		delete_option( AB_MCP_Sync_Guard::MARK );
		AB_MCP_Sync_Guard::arm();
		self::assertTrue( $hooked(), 'Hooked on a site with a run in its table.' );
		self::assertSame( 1, $db->run_reads );
		self::assertSame( '1', get_option( AB_MCP_Sync_Guard::MARK ) );
		self::assertTrue( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Guard::MARK ] );
		$writes = ab_test_writes( AB_MCP_Sync_Guard::MARK );
		AB_MCP_Sync_Guard::arm();
		self::assertSame( 1, $db->run_reads, 'Derived once.' );
		self::assertSame( $writes, ab_test_writes( AB_MCP_Sync_Guard::MARK ), 'Not written again.' );
		// A write of this site reads the record now (the lock as before, A1).
		AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( AB_MCP_Sync_Guard::begin( 9, 1, array( 5 => 'page' ) ) );
		AB_MCP_Sync_Guard::$owner = false;
		self::assertFalse( update_metadata( 'post', 5, 'note', 'late' ), 'Refused as before.' );
		self::assertGreaterThan( 0, $db->record_reads );
		AB_MCP_Sync_Guard::end( 9, 1 );
		// No mark, the tables there and no run: derived as none.
		ab_test_reset();
		$GLOBALS['wpdb'] = $db;
		$db->runs        = null;
		$db->run_reads   = 0;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( $hooked() );
		self::assertSame( 1, $db->run_reads );
		self::assertSame( '0', get_option( AB_MCP_Sync_Guard::MARK ) );
		// A table that cannot be read: hooked for this request, nothing stored.
		delete_option( AB_MCP_Sync_Guard::MARK );
		$db->last_error = 'gone';
		AB_MCP_Sync_Guard::arm();
		self::assertTrue( $hooked(), 'Fail closed.' );
		self::assertFalse( get_option( AB_MCP_Sync_Guard::MARK ), 'Asked again on the next request.' );
		$db->last_error = '';
		// Making a run marks the site (AB_MCP_Sync_Run::create(), the
		// integration suite); a boundary that begins marks it as well.
		AB_MCP_Sync_Guard::mark( false );
		self::assertSame( '0', get_option( AB_MCP_Sync_Guard::MARK ) );
		AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( AB_MCP_Sync_Guard::begin( 9, 2, array( 5 => 'page' ) ) );
		self::assertSame( '1', get_option( AB_MCP_Sync_Guard::MARK ), 'A boundary marks the site.' );
		AB_MCP_Sync_Guard::end( 9, 2 );
		AB_MCP_Sync_Guard::disarm();
	}

	/* ------------------------------------------------- the kind post */

	public function testTheRuleOfThisVersionForMetaKeys(): void {
		// Decision 28 (branch 9): which keys a new post takes before it exists
		// (the right follows the right at the post: not protected, no
		// authorization filter of its own; the core keys with an API of their
		// own), and which keys of a post here a step writes at all (those whose
		// values differ; never an excluded key or the name of a credential).
		self::assertTrue( AB_MCP_Sync_Post_Writer::new_meta_allowed( '_wp_page_template', 'page' ), 'The core key with an API of its own.' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::new_meta_allowed( '_thumbnail_id', 'post' ), 'The featured image through set_post_thumbnail().' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::new_meta_allowed( 'note', 'page' ) );
		self::assertFalse( AB_MCP_Sync_Post_Writer::new_meta_allowed( '_my_plugin', 'page' ), 'A protected key: map_meta_cap refuses it without a rule of its owner.' );
		add_filter( 'auth_post_meta_rating', '__return_true' );
		self::assertFalse( AB_MCP_Sync_Post_Writer::new_meta_allowed( 'rating', 'page' ), 'A key with a rule of its own answers at the object only.' );
		remove_filter( 'auth_post_meta_rating', '__return_true' );
		add_filter( 'auth_post_meta_rating_for_page', '__return_true' );
		self::assertFalse( AB_MCP_Sync_Post_Writer::new_meta_allowed( 'rating', 'page' ), 'Also by the subtype (register_post_meta() with an auth_callback).' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::new_meta_allowed( 'rating', 'post' ) );
		remove_filter( 'auth_post_meta_rating_for_page', '__return_true' );
		$prepared = array( 'meta' => array( 'same' => array( 'a' ), 'changed' => array( 'b' ), 'new' => array( 'c' ), 'api_key' => array( 'x' ), '_edit_last' => array( '1' ) ) );
		$current  = array(
			'meta' => array(
				array( 'meta_id' => 1, 'key' => 'same', 'value' => 'a' ),
				array( 'meta_id' => 2, 'key' => 'changed', 'value' => 'B' ),
				array( 'meta_id' => 3, 'key' => 'gone', 'value' => 'g' ),
				array( 'meta_id' => 4, 'key' => 'api_key', 'value' => 'y' ),
				array( 'meta_id' => 5, 'key' => '_edit_last', 'value' => '2' ),
			),
		);
		self::assertSame( array( 'changed', 'gone', 'new' ), AB_MCP_Sync_Post_Writer::differing( $prepared, $current ), 'A key equal on both sides stays untouched; a credential and an excluded key are never written.' );
		self::assertSame( array( 'post', 'page' ), AB_MCP_Sync_Post_Writer::TYPES );
		self::assertSame( array( 'category', 'post_tag', 'post_format' ), AB_MCP_Sync_Post_Writer::TAXONOMIES );
		self::assertSame( array( '_wp_page_template', '_thumbnail_id' ), AB_MCP_Sync_Post_Writer::CORE_META );
	}

	/** A line of a package of objects for a page, as the staging site writes it. */
	private static function line( array $row_over = array(), array $meta = array(), array $terms = array(), array $over = array() ): array {
		$row  = array_merge(
			array(
				'post_author'           => '0',
				'post_date'             => '2026-10-01 10:00:00',
				'post_date_gmt'         => '2026-10-01 10:00:00',
				'post_content'          => 'Hello.',
				'post_title'            => 'Hello',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'hello',
				'post_content_filtered' => '',
				'post_parent'           => '0',
				'menu_order'            => '0',
				'post_type'             => 'page',
				'post_mime_type'        => '',
			),
			$row_over
		);
		$form = array(
			'canon' => AB_MCP_Sync_Canon::VERSION,
			'kind'  => 'post',
			'row'   => array( 'post_status' => $row['post_status'], 'post_title' => $row['post_title'] ),
			'meta'  => array(),
			'terms' => array( '~set' => array() ),
		);
		return array_merge(
			array(
				'kind'     => 'post',
				'ref'      => '12',
				'identity' => array( 'post_type' => $row['post_type'], 'post_name' => $row['post_name'], 'theme' => '' ),
				'status'   => 'z8',
				'locks'    => array(),
				'hash'     => str_repeat( 'a', 64 ),
				'refs'     => array(),
				'form'     => $form,
				'content'  => array( 'row' => $row, 'meta' => $meta, 'terms' => $terms ),
			),
			$over
		);
	}

	private static function ctx( array $map = array() ): array {
		return array(
			'map'       => $map + array( 'post' => array(), 'term' => array() ),
			'addresses' => array( 'https://staging.example.com' => 'https://www.example.com' ),
			'routes'    => null,
			'now'       => 1700000000,
			'users'     => array(),
		);
	}

	private static function item( string $op = 'update', bool $draft = false ): array {
		return array(
			'op'       => $op,
			'live_ref' => 'update' === $op ? '34' : null,
			'as_draft' => $draft,
		);
	}

	private static function lock_of( $result ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_sync_locked', $result->get_error_code(), $result->get_error_message() );
		return (string) $result->get_error_data()['lock']['key'];
	}

	public function testTheTargetOfALineAndTheConfirmedChoice(): void {
		$line = self::line( array( 'post_parent' => '7', 'post_date_gmt' => '0000-00-00 00:00:00' ), array( array( '_wp_page_template', 'full.php' ), array( 'note', 'abc' ) ), array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ), array( 'name' => 'Aside', 'slug' => 'post-format-aside', 'taxonomy' => 'post_format', 'term_id' => 9, 'term_order' => 0 ) ) );
		$ctx  = self::ctx( array( 'post' => array( 7 => 70 ), 'term' => array( 3 => 30 ) ) );
		$got  = AB_MCP_Sync_Post_Writer::target_of( $line, self::item(), $ctx );
		self::assertIsArray( $got, is_wp_error( $got ) ? $got->get_error_message() : '' );
		self::assertSame( 'publish', $got['status'] );
		self::assertSame( 70, $got['postarr']['post_parent'], 'The parent by its pair.' );
		self::assertSame( 0, $got['postarr']['post_author'], 'No author: 0.' );
		self::assertTrue( $got['postarr']['edit_date'], 'Both dates and edit_date (decision 17).' );
		self::assertSame( '2026-10-01 10:00:00', $got['columns']['post_date_gmt'], 'The UTC date WordPress derives for a published post without one.' );
		self::assertSame( array( '_wp_page_template' => array( 'full.php' ), 'note' => array( 'abc' ) ), $got['meta'] );
		self::assertSame( 'full.php', $got['postarr']['page_template'], 'The template through the core way of wp_insert_post() (decisions 17 and 28; B7).' );
		self::assertFalse( $got['pending'] );
		self::assertSame( array( 'category' => array( 30 ), 'post_format' => array( 'post-format-aside' ) ), $got['terms'], 'Terms by their pair, post_format by its slug.' );
		self::assertSame( AB_MCP_Sync_Canon::hash( $got['target_form'] ), $got['target_hash'] );
		self::assertSame( 'publish', $got['target_form']['row']['post_status'] );
		self::assertFalse( $got['as_draft'] );
		// The confirmed choice: the status draft in the row and in the target.
		$draft = AB_MCP_Sync_Post_Writer::target_of( $line, self::item( 'update', true ), $ctx );
		self::assertSame( 'draft', $draft['status'] );
		self::assertSame( 'draft', $draft['postarr']['post_status'] );
		self::assertSame( 'draft', $draft['target_form']['row']['post_status'] );
		self::assertSame( '0000-00-00 00:00:00', $draft['columns']['post_date_gmt'], 'A draft keeps a floating date.' );
		self::assertNotSame( $got['target_hash'], $draft['target_hash'] );
		self::assertTrue( $draft['as_draft'] );
		// The row hash of the target columns is stable over the order of the columns.
		self::assertSame( AB_MCP_Sync_Post_Writer::row_hash( $got['columns'] ), AB_MCP_Sync_Post_Writer::row_hash( array_reverse( $got['columns'], true ) ) );
	}

	public function testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock(): void {
		$ctx = self::ctx( array( 'post' => array( 7 => 70 ), 'term' => array( 3 => 30 ) ) );
		self::assertSame( 'post:engine_type', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'wp_block' ), array(), array(), array( 'identity' => array( 'post_type' => 'wp_block', 'post_name' => 'b', 'theme' => '' ) ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:engine_type', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array(), array( 'content' => array() ) ), self::item(), $ctx ) ), 'No content in the package.' );
		self::assertSame( 'post:password_protected', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_password' => 'x' ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:engine_type', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_status' => 'trash' ) ), self::item(), $ctx ) ), 'The trash is a deletion.' );
		self::assertSame( 'post:engine_type', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_status' => 'auto-draft' ) ), self::item(), $ctx ) ) );
		$parent = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_parent' => '8' ) ), self::item(), $ctx );
		self::assertSame( 'post:reference_unmapped', self::lock_of( $parent ), 'A parent without a pair.' );
		self::assertSame( 'post_parent 8 of the staging site', $parent->get_error_data()['lock']['at'], 'Named as the parent, not caught later as a missing reference of the text.' );
		// A parent the same run creates before this object is pending, not
		// missing (B1): the check before the first write takes it as mapped;
		// the boundary maps it by the pair its step made.
		$pending = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_parent' => '8' ) ), self::item( 'create' ), $ctx + array( 'pending' => array( 'post' => array( 8 => true ), 'term' => array() ) ) );
		self::assertIsArray( $pending, is_wp_error( $pending ) ? $pending->get_error_message() : '' );
		self::assertTrue( $pending['pending'] );
		self::assertSame( 0, $pending['postarr']['post_parent'], 'No id yet.' );
		self::assertSame( 'post:reference_unmapped', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_parent' => '8' ) ), self::item( 'create' ), $ctx + array( 'pending' => array( 'post' => array( 9 => true ), 'term' => array() ) ) ) ), 'Another object pending does not help.' );
		self::assertSame( 'post:author_unknown', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_author' => '2' ), array(), array(), array( 'refs' => array( array( 'space' => 'user', 'id' => 2, 'login' => 'nobody', 'at' => 'post_author', 'strength' => 'strong' ) ) ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:author_unknown', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_author' => '2' ) ), self::item(), $ctx ) ), 'An author without a login in the refs.' );
		// The featured image by the pair of its attachment (decision 28; branch 9).
		self::assertSame( 'post:reference_unmapped', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_thumbnail_id', '5' ) ) ), self::item(), $ctx ) ), 'An attachment without a pair.' );
		$image = AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_thumbnail_id', '7' ) ) ), self::item(), $ctx );
		self::assertSame( array( '_thumbnail_id' => array( '70' ) ), $image['meta'], 'The id of the attachment here.' );
		self::assertSame( 'post:meta_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'b:1;' ) ) ), self::item(), $ctx ) ), 'A serialized scalar is not written through the API as it is.' );
		self::assertSame( 'post:meta_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'a:1:{i:0;d:0.10000000000000001;}' ) ) ), self::item(), $ctx ) ), 'A serialized value serialize() would not give back byte for byte.' );
		self::assertIsArray( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'a:1:{i:0;s:1:"x";}' ) ) ), self::item(), $ctx ), 'A serialized array that serializes back to its bytes.' );
		self::assertSame( 'post:create_with_object_meta', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_my_plugin', 'abc' ) ) ), self::item( 'create' ), $ctx ) ), 'A new post with a protected key (decision 28).' );
		self::assertIsArray( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'abc' ) ) ), self::item( 'create' ), $ctx ), 'A new post with a plain key: the right follows the right at the post (decision 28; branch 9).' );
		self::assertIsArray( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_wp_page_template', 'full.php' ) ) ), self::item( 'create' ), $ctx ), 'The core key goes with a new post.' );
		self::assertSame( array(), AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_edit_lock', '1:1' ), array( 'api_key', 'x' ) ) ), self::item(), $ctx )['meta'], 'What the comparison leaves out is never a part of the target.' );
		self::assertSame( 'post:taxonomy_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'product_cat', 'term_id' => 3, 'term_order' => 0 ) ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:reference_unmapped', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'post_tag', 'term_id' => 99, 'term_order' => 0 ) ) ), self::item(), $ctx ) ), 'A term without a pair.' );
		// A term the same run creates first is pending (branch 9): the check
		// asks the right of its taxonomy, the boundary maps it by its pair.
		$filed = AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'post_tag', 'term_id' => 99, 'term_order' => 0 ) ) ), self::item( 'create' ), $ctx + array( 'pending' => array( 'post' => array(), 'term' => array( 99 => true ) ) ) );
		self::assertIsArray( $filed, is_wp_error( $filed ) ? $filed->get_error_message() : '' );
		self::assertSame( array( 'post_tag' => array() ), $filed['terms'], 'The taxonomy stays for its right; the term follows with its pair.' );
		// A lock names its stage and the way.
		$locked = AB_MCP_Sync_Post_Writer::locked( 'post:slug_collision', 'slug hello' );
		self::assertSame( 'ab_mcp_sync_locked', $locked->get_error_code() );
		self::assertSame( array( 'key' => 'post:slug_collision', 'stage' => 'Z8', 'at' => 'slug hello' ), array_intersect_key( $locked->get_error_data()['lock'], array_flip( array( 'key', 'stage', 'at' ) ) ) );
		self::assertStringContainsString( 'Not written (post:slug_collision, from Z8)', $locked->get_error_message() );
		self::assertStringContainsString( 'wp-admin', $locked->get_error_message() );
		foreach ( array( 'engine_type', 'meta_rule', 'taxonomy_rule', 'slug_collision', 'sanitizer', 'menu_automatic', 'author_unknown', 'status_date', 'reference_unmapped', 'page_template', 'thumbnail' ) as $op ) {
			self::assertIsArray( AB_MCP_Sync_Kinds::lock( 'post', $op ), $op . ' is a lock of the table.' );
		}
	}

	public function testTheTemplateOfAPageGoesThroughTheCoreWayAndTheThemeHasToOfferIt(): void {
		// The theme of this site offers one template for pages (B7).
		$GLOBALS['wpdb']               = new SyncRunOptionWpdb();
		$GLOBALS['ab_test_page_templates'] = array( 'page' => array( 'templates/template-cover.php' => 'Cover' ) );
		$ctx                           = self::ctx();
		$ctx['now']                    = 1800000000;
		$offered = AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_wp_page_template', 'templates/template-cover.php' ) ) ), self::item( 'create' ), $ctx );
		self::assertIsArray( $offered, is_wp_error( $offered ) ? $offered->get_error_message() : '' );
		self::assertSame( 'templates/template-cover.php', $offered['postarr']['page_template'] );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $offered, null, $ctx['now'] ) === true, 'A template the theme offers passes.' );
		$default = AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_wp_page_template', 'default' ) ) ), self::item( 'create' ), $ctx );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $default, null, $ctx['now'] ), 'default is always offered.' );
		$unknown = AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_wp_page_template', 'landing.php' ) ) ), self::item( 'create' ), $ctx );
		self::assertIsArray( $unknown );
		$locked = AB_MCP_Sync_Post_Writer::precheck( $unknown, null, $ctx['now'] );
		self::assertSame( 'post:page_template', self::lock_of( $locked ), 'A template the theme does not offer: WordPress would store default.' );
		self::assertStringContainsString( 'landing.php for a page', $locked->get_error_message() );
		self::assertStringContainsString( 'add the template to the theme', $locked->get_error_message() );
		$none = AB_MCP_Sync_Post_Writer::target_of( self::line(), self::item( 'create' ), $ctx );
		self::assertArrayNotHasKey( 'page_template', $none['postarr'], 'Without the meta nothing is handed to the core.' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $none, null, $ctx['now'] ) );
		unset( $GLOBALS['ab_test_page_templates'] );
	}

	public function testAMetaKeyWithABackslashIsStoredAndRemovedUnderItsOwnName(): void {
		// GitHub Codex round 1 on free#137 (P2): update_metadata(),
		// add_metadata() and delete_metadata() unslash the key as well as the
		// value, so a key handed raw lands under another name (note\path as
		// notepath), and the journal and the way back address a key that was
		// never written. The key is slashed as the values are, on all three
		// ways; the reads from the rows, the rights and the way back take it
		// as it is. The stubs unslash the key as core does.
		$key = 'note\\path';
		self::assertTrue( AB_MCP_Sync_Post_Writer::new_meta_allowed( $key, 'post' ) );
		// Added where there was none.
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_meta( 5, $key, array( 'v1' ), array() ) );
		self::assertSame( array( 'v1' ), $GLOBALS['ab_test_meta'][5][ $key ] ?? null, 'Stored under its own name.' );
		self::assertArrayNotHasKey( 'notepath', $GLOBALS['ab_test_meta'][5], 'Not under the unslashed one.' );
		// One value both sides: update_metadata().
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_meta( 5, $key, array( 'v2' ), array( 'v1' ) ) );
		self::assertSame( array( 'v2' ), $GLOBALS['ab_test_meta'][5][ $key ] ?? null );
		self::assertArrayNotHasKey( 'notepath', $GLOBALS['ab_test_meta'][5] );
		// Several values: the rows removed (delete_metadata()) and added anew.
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_meta( 5, $key, array( 'v3', 'v4' ), array( 'v2' ) ) );
		self::assertSame( array( 'v3', 'v4' ), $GLOBALS['ab_test_meta'][5][ $key ] ?? null );
		self::assertArrayNotHasKey( 'notepath', $GLOBALS['ab_test_meta'][5] );
		// No value: the rows removed.
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_meta( 5, $key, array(), array( 'v3', 'v4' ) ) );
		self::assertArrayNotHasKey( $key, $GLOBALS['ab_test_meta'][5] );
	}

	public function testRemovingAMetaKeyOnlyThisSiteHasNeedsTheRightToDeleteIt(): void {
		// GitHub Codex round 1 on free#137 (P2): a plain key only this site
		// has is removed by the step (write_meta() with no value); the
		// metadata API asks no right itself, so the check before the first
		// write and the boundary ask delete_post_meta for it, per key,
		// through map_meta_cap (decision 28), as they ask edit_post_meta for
		// a key the target carries.
		$db           = self::post_rows( 'News' );
		$db->meta[12] = array(
			array( 'meta_id' => 1, 'meta_key' => 'note', 'meta_value' => 'abc' ),
			array( 'meta_id' => 2, 'meta_key' => 'color', 'meta_value' => 'red' ),
		);
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx();
		$ctx['now']      = 1800000000;
		$prepared        = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'post' ), array( array( 'note', 'abc' ) ), array(), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( 'note' => array( 'abc' ) ), $prepared['meta'], 'The target carries note only: color is removed by the step.' );
		$denied                 = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap . ':' . (string) ( $args[1] ?? '' ), $denied, true );
		};
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'With every right the check passes.' );
		$denied  = array( 'delete_post_meta:color' );
		$refused = AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] );
		self::assertInstanceOf( WP_Error::class, $refused, 'The right to remove the key only this site has is asked before anything is written.' );
		self::assertSame( '*:meta_without_rule', self::lock_of( $refused ), 'A key the map refuses locks the post: its field rule follows with Z8d (decision 28; branch 9).' );
		self::assertStringContainsString( 'Meta key color of the post on this site: delete_post_meta refused', $refused->get_error_message() );
		// A key equal on both sides is no part of the step (decision 28;
		// branch 9): its right is not asked, it stays untouched.
		$denied = array( 'edit_post_meta:note' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'note is abc on both sides.' );
		self::assertSame( array( 'color' ), AB_MCP_Sync_Post_Writer::differing( $prepared, AB_MCP_Sync_Post_Writer::current( 12 ) ) );
		$changed = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'post' ), array( array( 'note', 'xyz' ) ), array(), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertSame( '*:meta_without_rule', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $changed, 12, $ctx['now'] ) ), 'A key that differs asks edit_post_meta.' );
		$denied = array( 'delete_post_meta:note', 'delete_post_meta:other' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'The right to remove is asked for a key only this site has.' );
	}

	public function testTheRightToSetTheTermsIsAskedForEveryTaxonomyTheStepTouchesByIdBySlugAndForOneOnlyThisSiteHas(): void {
		// GitHub Codex round 2 on free#137 (P2): a format is named by its slug
		// (post_format, IDENTITY_TAXONOMIES), so it never reached assign_term;
		// a taxonomy only the post of this site carries never entered the loop,
		// though the step clears it (write_terms() with no term). Now every
		// taxonomy the step touches is asked (decision 28; term_right()): the
		// right of the taxonomy to assign its terms for the set, assign_term for
		// every term by its id or by the id its slug names here, the right to
		// make a term for a slug this site has no term of yet (B6).
		$db                = self::post_rows( 'News' );
		$db->relations[12] = array(
			array( 'term_taxonomy_id' => 3, 'term_order' => 0, 'term_id' => 3, 'taxonomy' => 'category', 'slug' => 'news' ),
			array( 'term_taxonomy_id' => 5, 'term_order' => 0, 'term_id' => 5, 'taxonomy' => 'post_tag', 'slug' => 'tip' ),
		);
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx( array( 'term' => array( 3 => 3 ) ) );
		$ctx['now']      = 1800000000;
		// The target: in the category News (by its pair), the format aside by
		// its slug, no tag: the step clears post_tag, which only this site has.
		$prepared = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'post' ), array( array( 'note', 'abc' ) ), array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ), array( 'name' => 'Aside', 'slug' => 'post-format-aside', 'taxonomy' => 'post_format', 'term_id' => 9, 'term_order' => 0 ) ), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( 'category' => array( 3 ), 'post_format' => array( 'post-format-aside' ) ), $prepared['terms'] );
		$asked  = array();
		$denied = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied, &$asked ): bool {
			$key     = $cap . ':' . implode( ':', array_map( 'strval', $args ) );
			$asked[] = $key;
			return ! in_array( $key, $denied, true );
		};
		$refused = static function ( $result ): string {
			self::assertInstanceOf( WP_Error::class, $result, 'Refused.' );
			self::assertSame( 'ab_mcp_sync_rights', $result->get_error_code() );
			return $result->get_error_message();
		};
		// Without a term of the format here: the right of the taxonomy to make one.
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'With every right the check passes.' );
		self::assertContains( 'edit_posts:', $asked, 'The right of category and post_format to assign their terms.' );
		self::assertContains( 'assign_post_tags:', $asked, 'The right of post_tag, which only this site has and the step clears.' );
		self::assertContains( 'assign_term:3', $asked, 'The category by its id.' );
		self::assertContains( 'manage_categories:', $asked, 'The right to make the term of the format this site has no term of yet.' );
		self::assertNotContains( 'assign_term:5', $asked, 'The tag goes away; no term is assigned.' );
		$denied = array( 'manage_categories:' );
		self::assertStringContainsString( 'make the term post-format-aside of post_format, which this site has no term of yet (manage_categories)', $refused( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		// With a term of the format here: assign_term by its id.
		$GLOBALS['ab_test_terms_by_slug'] = array( 'post_format' => array( 'post-format-aside' => 21 ) );
		$asked                            = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) );
		self::assertContains( 'assign_term:21', $asked, 'The slug resolved to the term of this site.' );
		self::assertNotContains( 'manage_categories:', $asked );
		$denied = array( 'assign_term:21' );
		self::assertStringContainsString( 'assign the term 21 of post_format (assign_term)', $refused( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		// A taxonomy only this site has, which the step clears.
		$denied = array( 'assign_post_tags:' );
		self::assertStringContainsString( 'assign the terms of post_tag (assign_post_tags)', $refused( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		// The right of a taxonomy of the target to assign its terms, and a term by its id.
		$denied = array( 'edit_posts:' );
		self::assertStringContainsString( 'assign the terms of category (edit_posts)', $refused( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		$denied = array( 'assign_term:3' );
		self::assertStringContainsString( 'assign the term 3 of category (assign_term)', $refused( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		$denied = array();
		// A taxonomy only this site has that is not registered here: locked.
		$db->relations[12][] = array( 'term_taxonomy_id' => 8, 'term_order' => 0, 'term_id' => 8, 'taxonomy' => 'product_cat', 'slug' => 'x' );
		self::assertSame( 'post:taxonomy_rule', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ) );
		// term_right() alone: the taxonomy as a whole, a slug and an id; a taxonomy without rights.
		self::assertTrue( AB_MCP_Sync_Post_Writer::term_right( 'post_format', array( 'post-format-aside', 'post-format-quote' ) ) );
		$denied = array( 'manage_categories:' );
		self::assertStringContainsString( 'make the term post-format-quote', (string) AB_MCP_Sync_Post_Writer::term_right( 'post_format', array( 'post-format-aside', 'post-format-quote' ) ) );
		$denied = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::term_right( 'post_tag', array() ), 'Clearing a taxonomy asks the right of the taxonomy only.' );
		self::assertStringContainsString( 'not registered', (string) AB_MCP_Sync_Post_Writer::term_right( 'product_cat', array( 8 ) ) );
	}

	public function testTheWayBackAsksTheRightsAtTheObjectAsTheForwardWriteDoesBeforeAPartGoesBack(): void {
		// GitHub Codex round 2 on free#137 (P2): the way back asked the owner of
		// the run, never the rights at the object; wp_update_post(), the raw SQL
		// of restore_meta() and wp_set_object_terms() ask none. restore_right()
		// asks the rights of the forward write (decision 28) for the part as the
		// way back would write it; put_back() leaves a part the account may not
		// write, with its value before.
		$backup = array(
			'row'   => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => '3', 'post_title' => 'Before' ),
			'meta'  => array( array( 'meta_id' => 1, 'key' => 'note', 'value' => 'abc' ), array( 'meta_id' => 2, 'key' => '_wp_page_template', 'value' => 'full.php' ) ),
			'terms' => array( array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ) ),
		);
		$now    = array(
			'row'   => array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => '3', 'post_title' => 'After' ),
			'meta'  => array( array( 'meta_id' => 1, 'key' => 'note', 'value' => 'def' ), array( 'meta_id' => 3, 'key' => 'extra', 'value' => 'x' ) ),
			'terms' => array(),
		);
		$denied = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap . ':' . implode( ':', array_map( 'strval', $args ) ), $denied, true );
		};
		$right = static fn( string $part, array $deny, ?array $backup_over = null, ?array $now_over = null ) => AB_MCP_Sync_Post_Writer::restore_right( $part, 12, null === $backup_over ? $backup : $backup_over, null === $now_over ? $now : $now_over );
		// The row: edit_post; publish_posts where the backed-up status is
		// publish and the row holds another now; edit_others_posts for another
		// author's post.
		$denied = array();
		self::assertTrue( $right( 'row', array() ) );
		$denied = array( 'edit_post:12' );
		self::assertSame( 'edit the post 12 (edit_post)', $right( 'row', $denied ) );
		$denied = array( 'publish_posts:' );
		self::assertSame( 'publish a post (publish_posts)', $right( 'row', $denied ) );
		$published = $now;
		$published['row']['post_status'] = 'publish';
		self::assertTrue( $right( 'row', $denied, null, $published ), 'Published already: no publishing.' );
		$denied = array( 'edit_others_posts:' );
		self::assertTrue( $right( 'row', $denied ), 'The account\'s own post.' );
		$other = $backup;
		$other['row']['post_author'] = '5';
		self::assertSame( 'write a post of another author (edit_others_posts)', $right( 'row', $denied, $other ) );
		// Meta: edit where rows are written over, add where the backup holds
		// more rows, delete where it holds fewer; the core key with edit_post.
		$denied = array( 'edit_post_meta:12:note' );
		self::assertSame( 'edit the meta key note of the post (edit_post_meta)', $right( 'meta:note', $denied ) );
		$denied = array( 'add_post_meta:12:note' );
		self::assertTrue( $right( 'meta:note', $denied ), 'Both sides hold a row: written over, not added.' );
		$gone = $now;
		$gone['meta'] = array();
		self::assertSame( 'add the meta key note to the post (add_post_meta)', $right( 'meta:note', $denied, null, $gone ) );
		$denied = array( 'delete_post_meta:12:extra' );
		self::assertSame( 'remove the meta key extra of the post (delete_post_meta)', $right( 'meta:extra', $denied ) );
		$denied = array( 'edit_post_meta:12:_wp_page_template', 'add_post_meta:12:_wp_page_template' );
		self::assertTrue( $right( 'meta:_wp_page_template', $denied ), 'The core key goes with edit_post.' );
		// Every part is the post's: edit_post first, whatever the part (Codex
		// round 18: assign_term and the meta rights ask nothing of the post).
		$denied = array( 'edit_post:12' );
		self::assertSame( 'edit the post 12 (edit_post, for the part meta:_wp_page_template)', $right( 'meta:_wp_page_template', $denied ) );
		self::assertSame( 'edit the post 12 (edit_post, for the part meta:note)', $right( 'meta:note', $denied ) );
		self::assertSame( 'edit the post 12 (edit_post, for the part terms:category)', $right( 'terms:category', $denied ), 'The terms of a post the account may not edit stay, whatever the rights of the taxonomy.' );
		// Terms: the taxonomy and each backed-up term; one not registered here.
		$denied = array( 'assign_term:3' );
		self::assertSame( 'assign the term 3 of category (assign_term)', $right( 'terms:category', $denied ) );
		$denied = array( 'edit_posts:' );
		self::assertSame( 'assign the terms of category (edit_posts)', $right( 'terms:category', $denied ) );
		$denied = array();
		self::assertTrue( $right( 'terms:category', $denied ) );
		self::assertStringContainsString( 'not registered', (string) $right( 'terms:product_cat', $denied ) );
	}

	public function testTheKindReadsAgainAsItWritesAPartBackAndLeavesOneThatIsNotWhatTheStepRead(): void {
		// The second check at the moment of writing (decision 23; GRENZEN-LAUF.md):
		// put_back() decides a part on a hash read right before its turn
		// (decide()), and restore_row() and restore_meta() read the part again
		// as they write and leave it where it is not what the step read back
		// ($written). Between the two reads the rights at the object are asked
		// (restore_right()), whose hooks (map_meta_cap, user_has_cap) a site
		// may use to change the part: the integration test
		// testAHookOfTheRightsCheckThatChangesAPartAsItGoesBackIsCaughtByTheSecondReadOfTheKind
		// stages that (Codex round 18 of branch 8); here the kind is called
		// directly, for every branch of the answer.
		$db              = self::post_rows( 'News' );
		$GLOBALS['wpdb'] = $db;
		$now             = AB_MCP_Sync_Post_Writer::current( 12 );
		self::assertIsArray( $now );
		$hash_now = AB_MCP_Sync_Post_Writer::row_hash( $now['row'] );
		$backup   = $now['row'];
		$backup['post_title'] = 'Before';
		$before   = AB_MCP_Sync_Post_Writer::row_hash( $backup );
		self::assertSame( array( 'state' => 'unchanged' ), AB_MCP_Sync_Post_Writer::restore_row( 12, $now['row'], $hash_now, str_repeat( '0', 64 ), 1800000000 ), 'As the backup: nothing to do.' );
		$GLOBALS['ab_test_updated'] = array();
		$left                       = AB_MCP_Sync_Post_Writer::restore_row( 12, $backup, $before, str_repeat( '0', 64 ), 1800000000 );
		self::assertIsArray( $left, 'Left, not written: ' . ( is_wp_error( $left ) ? $left->get_error_message() : '' ) );
		self::assertSame( 'left', $left['state'] );
		self::assertStringContainsString( 'The row was changed since the step wrote it', $left['note'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'], 'wp_update_post() was not called.' );
		self::assertSame( array( 'state' => 'unchanged' ), AB_MCP_Sync_Post_Writer::restore_meta( 12, 'note', array( 'abc' ), str_repeat( '0', 64 ) ) );
		$left = AB_MCP_Sync_Post_Writer::restore_meta( 12, 'note', array( 'before' ), str_repeat( '0', 64 ) );
		self::assertIsArray( $left, 'Left, not written: ' . ( is_wp_error( $left ) ? $left->get_error_message() : '' ) );
		self::assertSame( 'left', $left['state'] );
		self::assertStringContainsString( 'The meta note was changed since the step wrote it', $left['note'] );
	}

	public function testAWriteOfTheRunAndItsStepsIsOneTransactionAndRefusedWithTheWayWithoutInnoDb(): void {
		// GitHub Codex round 2 on free#137 (P1): a run is made with all its
		// steps or not at all, and the way back of a run that is not closed
		// moves the run and stores every step of the way back in one
		// transaction (AB_MCP_Sync_Run::transaction()). The engine of both
		// tables is asked first; one that keeps no transactions refuses with
		// the way, before anything is written.
		$db              = new SyncRunRowsWpdb();
		$GLOBALS['wpdb'] = $db;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$runs  = AB_MCP_Sync_Schema::table( 'runs' );
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$marks = static fn( array $queries ): array => array_values( array_filter( $queries, static fn( string $q ): bool => in_array( $q, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) );
		$db->rows = array( array( 'name' => $runs, 'engine' => 'InnoDB' ), array( 'name' => $steps, 'engine' => 'MyISAM' ) );
		$ran      = false;
		$refused  = AB_MCP_Sync_Run::transaction(
			static function () use ( &$ran ) {
				$ran = true;
				return 1;
			}
		);
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_run_engine', $refused->get_error_code() );
		self::assertStringContainsString( "ALTER TABLE $steps ENGINE=InnoDB", $refused->get_error_message(), 'The way.' );
		self::assertStringContainsString( 'MyISAM', $refused->get_error_message() );
		self::assertFalse( $ran, 'Nothing begun.' );
		self::assertSame( array(), $marks( $db->queries ) );
		$db->rows = array( array( 'name' => $runs, 'engine' => 'InnoDB' ) );
		$refused  = AB_MCP_Sync_Run::transaction( static fn() => 1 );
		self::assertSame( 'ab_mcp_sync_run_engine', $refused->get_error_code(), 'A table the database does not name: refused as well.' );
		self::assertStringContainsString( 'engine unknown', $refused->get_error_message() );
		// InnoDB: begun, the writes, committed; what the function gives comes back.
		$db->rows    = array( array( 'name' => $runs, 'engine' => 'InnoDB' ), array( 'name' => $steps, 'engine' => 'InnoDB' ) );
		$db->queries = array();
		self::assertSame( 'done', AB_MCP_Sync_Run::transaction( static fn() => 'done' ) );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $marks( $db->queries ) );
		// A WP_Error rolls back and comes back as it is.
		$db->queries = array();
		$error       = new WP_Error( 'x', 'y' );
		self::assertSame( $error, AB_MCP_Sync_Run::transaction( static fn() => $error ) );
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $marks( $db->queries ) );
		// An exception rolls back and goes on.
		$db->queries = array();
		try {
			AB_MCP_Sync_Run::transaction(
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
			self::fail( 'Thrown on.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'boom', $e->getMessage() );
		}
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $marks( $db->queries ) );
	}

	public function testTheTimeOfACallIsRaisedWhereItTakesAndTheWorkIsDerivedFromTheLimitItHas(): void {
		// GitHub Codex round 3 on free#137 (P2): measure() asked set_time_limit()
		// for the limit it had, and no call raised it, while the check and the
		// verification worked 50 s and saved their cursor after that: on a host
		// with 30 s a call died before the cursor, and a write could end halfway.
		// Now every executing call asks for WANTED_TIME and reads the limit it
		// has after that (time_for_call()); the work of the call is half the
		// limit, never more than WORK_MAX (50 s), at least 1 s (budget()); a
		// limit under MIN_TIME (30 s) carries no write step (carries_write()).
		self::assertSame( AB_MCP_Sync_Resources::WORK_MAX + AB_MCP_Sync_Resources::WORK_RESERVE, AB_MCP_Sync_Engine::BUDGET, 'One source of the numbers.' );
		self::assertSame( AB_MCP_Sync_Resources::WORK_RESERVE, AB_MCP_Sync_Engine::RESERVE );
		self::assertSame( 120, AB_MCP_Sync_Resources::WANTED_TIME );
		// No limit: the whole work, writes.
		self::assertSame( array( 'limit' => 0, 'before' => 0, 'raised' => false, 'budget' => 50, 'writes' => true ), AB_MCP_Sync_Resources::derived( 0, 0 ) );
		// 30 s, raised to 120: the whole work, writes.
		self::assertSame( array( 'limit' => 120, 'before' => 30, 'raised' => true, 'budget' => 50, 'writes' => true ), AB_MCP_Sync_Resources::derived( 30, 120 ) );
		// 30 s, not raised: half the limit as work, a write still carried (MIN_TIME).
		self::assertSame( array( 'limit' => 30, 'before' => 30, 'raised' => false, 'budget' => 15, 'writes' => true ), AB_MCP_Sync_Resources::derived( 30, 30 ) );
		// Too short: reads go on with a small work, no write.
		self::assertSame( array( 'limit' => 20, 'before' => 20, 'raised' => false, 'budget' => 10, 'writes' => false ), AB_MCP_Sync_Resources::derived( 20, 20 ) );
		self::assertSame( 1, AB_MCP_Sync_Resources::derived( 1, 1 )['budget'], 'At least one second: a call does at least one unit.' );
		self::assertSame( 50, AB_MCP_Sync_Resources::budget( 0 ) );
		self::assertSame( 49, AB_MCP_Sync_Resources::budget( 99 ), 'Half the limit.' );
		self::assertSame( 50, AB_MCP_Sync_Resources::budget( 100 ) );
		self::assertSame( 50, AB_MCP_Sync_Resources::budget( 1000 ), 'Never more than WORK_MAX.' );
		self::assertFalse( AB_MCP_Sync_Resources::carries_write( 29 ) );
		self::assertTrue( AB_MCP_Sync_Resources::carries_write( 30 ) );
		self::assertTrue( AB_MCP_Sync_Resources::carries_write( 0 ) );
		// The raise itself, on this PHP: a limit of 30 s goes to WANTED_TIME; none stays none.
		$saved = (string) ini_get( 'max_execution_time' );
		try {
			ini_set( 'max_execution_time', '30' );
			$pair = AB_MCP_Sync_Resources::raise();
			self::assertSame( array( 'before' => 30, 'after' => 120 ), $pair );
			self::assertSame( 120, (int) ini_get( 'max_execution_time' ), 'The request has the raised limit.' );
			AB_MCP_Sync_Resources::$time = null;
			self::assertSame( array( 'limit' => 120, 'before' => 120, 'raised' => false, 'budget' => 50, 'writes' => true ), AB_MCP_Sync_Resources::time_for_call(), 'Raised already: nothing to raise.' );
			set_time_limit( 0 );
			ini_set( 'max_execution_time', '0' );
			self::assertSame( array( 'before' => 0, 'after' => 0 ), AB_MCP_Sync_Resources::raise() );
		} finally {
			set_time_limit( (int) $saved );
			ini_set( 'max_execution_time', $saved );
		}
		// What a test injects stands in for the request.
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 20, 'after' => 20 );
		self::assertFalse( AB_MCP_Sync_Resources::time_for_call()['writes'] );
		AB_MCP_Sync_Resources::$time = null;
	}

	public function testTheRetentionBreaksATieOfTheSameCloseByTheYoungerRun(): void {
		// GitHub Codex round 3 on free#137 (P2): two qualified closes in the same
		// second tie on forward_closed_at; the rows come by run_id ascending and
		// arsort() kept the older one first, so the last three were 1, 3, 4 and
		// the backups of run 2 went. The younger run comes first on a tie.
		$db              = new SyncRunRowsWpdb();
		$GLOBALS['wpdb'] = $db;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$db->rows = array();
		foreach ( array( 1 => 100, 2 => 100, 3 => 200, 4 => 300 ) as $id => $closed ) {
			// Free: the period of a qualified close is the close itself, reached.
			$db->rows[] = array( 'run_id' => $id, 'type' => 'push', 'state' => 'closed_done', 'closed' => $closed, 'forward_closed_at' => $closed, 'keep_until' => $closed, 'plan' => null );
		}
		$kept = AB_MCP_Sync_Schema::backup_runs( 1000 );
		self::assertIsArray( $kept );
		ksort( $kept );
		self::assertSame( array( 2, 3, 4 ), array_keys( $kept ), 'The last three: on the tie of 100, run 2 before run 1.' );
		$rows = AB_MCP_Sync_Schema::backup_rows( 1000 );
		self::assertSame( array( 1 => false, 2 => true, 3 => true, 4 => true ), $rows );
	}

	public function testTheRetentionReadsThePlanOfARunThatIsNotClosedOnlyAndKeepsWhatItKept(): void {
		// GitHub Codex round 7 on free#137 (P2): backup_rows() read the plan of
		// every run of the history of the site (up to MAX_PLAN each) at every
		// clean-up and every group opened in the store, though only a run that
		// is not closed needs its plan (rule 4: a way back that is not closed
		// keeps the runs it takes back). The rows are read without the plan;
		// the plan of each run that is not closed is read for it alone. What
		// the retention keeps is the same.
		$db              = new SyncRunRowsWpdb();
		$GLOBALS['wpdb'] = $db;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$now      = 1700000000;
		$day      = 86400;
		$big      = (string) json_encode( array( 'objects' => array_fill( 0, 200, str_repeat( 'x', 512 ) ) ) );
		$db->rows = array(
			array( 'run_id' => 1, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 40 * $day, 'forward_closed_at' => $now - 40 * $day, 'keep_until' => $now - 40 * $day, 'plan' => $big ),
			array( 'run_id' => 2, 'type' => 'push', 'state' => 'closed_aborted', 'closed' => $now - 8 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - $day, 'plan' => $big ),
			array( 'run_id' => 3, 'type' => 'revert', 'state' => 'closed_reverted', 'closed' => $now - 8 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - $day, 'plan' => '{"reverts":[5]}' ),
			array( 'run_id' => 4, 'type' => 'revert', 'state' => 'open', 'closed' => 0, 'forward_closed_at' => 0, 'keep_until' => 0, 'plan' => '{"reverts":[2]}' ),
			array( 'run_id' => 5, 'type' => 'push', 'state' => 'closed_checked', 'closed' => $now - 9 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - $day, 'plan' => $big ),
		);
		// The qualified close (1) among the last three, the run the open way
		// back needs (2), the open way back (4); the closed way back (3) and
		// the run its plan names (5) are past their periods.
		self::assertSame( array( 1 => true, 2 => true, 3 => false, 4 => true, 5 => false ), AB_MCP_Sync_Schema::backup_rows( $now ) );
		$plans = array_values( array_filter( $db->queries, static fn( string $q ): bool => false !== stripos( $q, 'plan' ) ) );
		self::assertSame( array( 'SELECT plan FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` WHERE run_id = 4' ), $plans, 'The plan of the run that is not closed, alone; no plan of a closed run.' );
	}

	public function testTheErrorLogIsCountedFromItsMarkInChunksWithACapAndARotationIsSaidSo(): void {
		// GitHub Codex round 4 on free#137 (P2): error_log_lines() loaded the
		// whole debug.log with file() to count its lines; a log of 300 MiB
		// ended the check and the verification on a fatal error. The lines
		// are counted from the byte mark of the check on, in chunks, at most
		// LOG_BYTES in one call; a cap reached and a log smaller than its mark
		// (rotated or emptied) are said so, never «no new line».
		$path = tempnam( sys_get_temp_dir(), 'ab-log' );
		self::assertIsString( $path );
		try {
			$line   = str_repeat( 'e', 99 ) . "\n";
			$handle = fopen( $path, 'wb' );
			for ( $i = 0; $i < 200000; $i++ ) {
				fwrite( $handle, $line );
			}
			fclose( $handle );
			self::assertSame( 20000000, filesize( $path ) );
			$before = memory_get_peak_usage( true );
			$all    = AB_MCP_Sync_Engine::error_log_lines_since( $path, 0, 64 * 1048576 );
			self::assertSame( array( 'lines' => 200000, 'capped' => false, 'rotated' => false, 'failed' => false, 'bytes' => 20000000 ), $all );
			self::assertLessThan( 8 * 1048576, memory_get_peak_usage( true ) - $before, 'Read in chunks of LOG_CHUNK, never loaded whole.' );
			self::assertSame( 65536, AB_MCP_Sync_Engine::LOG_CHUNK );
			self::assertSame( 16777216, AB_MCP_Sync_Engine::LOG_BYTES );
			// From the mark: the lines the log gained.
			$since = AB_MCP_Sync_Engine::error_log_lines_since( $path, 16000000, 64 * 1048576 );
			self::assertSame( array( 'lines' => 40000, 'capped' => false, 'rotated' => false, 'failed' => false, 'bytes' => 20000000 ), $since );
			self::assertSame( 0, AB_MCP_Sync_Engine::error_log_lines_since( $path, 20000000, 64 * 1048576 )['lines'], 'Nothing new.' );
			// The cap: one MiB of a span of four, counted up to there and said so.
			$capped = AB_MCP_Sync_Engine::error_log_lines_since( $path, 16000000, 1048576 );
			self::assertTrue( $capped['capped'] );
			self::assertSame( 10485, $capped['lines'] );
			self::assertFalse( AB_MCP_Sync_Engine::error_log_lines_since( $path, 16000000, 4000000 )['capped'], 'The cap at the very end of the log: read to its end.' );
			// A mark past the size: the log was rotated or emptied; counted from
			// its start and said so.
			$rotated = AB_MCP_Sync_Engine::error_log_lines_since( $path, 30000000, 64 * 1048576 );
			self::assertSame( array( 'lines' => 200000, 'capped' => false, 'rotated' => true, 'failed' => false, 'bytes' => 20000000 ), $rotated );
			self::assertNull( AB_MCP_Sync_Engine::error_log_lines_since( $path . '.none', 0, 100 ), 'No log: null.' );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// A read that fails before the end (fread() false) is no end of the
		// file: counted up to there and said so (failed), never «no new line»
		// (Codex round 22 of branch 8).
		self::assertTrue( stream_wrapper_register( 'ab-log-fail', SyncRunFailingLogStream::class ) );
		try {
			SyncRunFailingLogStream::$content = str_repeat( "line\n", 1000 );
			SyncRunFailingLogStream::$fail_at = 2000;
			$failed                           = AB_MCP_Sync_Engine::error_log_lines_since( 'ab-log-fail://debug.log', 0, 64 * 1048576 );
			self::assertIsArray( $failed );
			self::assertTrue( $failed['failed'], 'The read failed before the end.' );
			self::assertSame( 400, $failed['lines'], 'Counted up to the failure.' );
			self::assertFalse( $failed['capped'] );
			SyncRunFailingLogStream::$fail_at = 0;
			$at_once                          = AB_MCP_Sync_Engine::error_log_lines_since( 'ab-log-fail://debug.log', 0, 64 * 1048576 );
			self::assertSame( array( 'lines' => 0, 'capped' => false, 'rotated' => false, 'failed' => true, 'bytes' => 5000 ), $at_once, 'A read that fails at once: nothing counted, and said so.' );
			SyncRunFailingLogStream::$fail_at = 5000;
			self::assertSame( array( 'lines' => 1000, 'capped' => false, 'rotated' => false, 'failed' => false, 'bytes' => 5000 ), AB_MCP_Sync_Engine::error_log_lines_since( 'ab-log-fail://debug.log', 0, 64 * 1048576 ), 'Read to its end.' );
		} finally {
			stream_wrapper_unregister( 'ab-log-fail' );
		}
	}

	public function testARelationshipWithATermOrderLocksThePostAndIsPartOfTheHashOfItsTerms(): void {
		// GitHub Codex round 5 on free#137 (P2): the canonical form carries the
		// order of a relationship (term_order), wp_set_object_terms() makes
		// every relationship anew with the order 0, and the way back compared
		// the keys of the terms only: a backed-up order was lost and the part
		// reported restored. A post of this site with an order other than 0
		// in a taxonomy the step touches is locked before anything is written
		// (post:term_order, Z9); the order is part of the hash of the terms
		// (term_entries()), so a difference never reads as restored.
		$current = array(
			'row'   => array( 'post_title' => 'Hello' ),
			'meta'  => array(),
			'terms' => array( array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ), array( 'taxonomy' => 'post_tag', 'term_id' => 5, 'slug' => 'tip', 'term_order' => 2 ) ),
		);
		self::assertSame( array( '3' ), AB_MCP_Sync_Post_Writer::term_entries( $current, 'category' ), 'Order 0: the key alone, as before.' );
		self::assertSame( array( '5#2' ), AB_MCP_Sync_Post_Writer::term_entries( $current, 'post_tag' ), 'An order other than 0 is part of the entry.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( array( 3 ) ), AB_MCP_Sync_Post_Writer::part_hash( $current, 'terms:category' ), 'The hash of a taxonomy without orders is the hash of its keys: journals of before read alike.' );
		self::assertNotSame( AB_MCP_Sync_Post_Writer::terms_hash( array( 5 ) ), AB_MCP_Sync_Post_Writer::part_hash( $current, 'terms:post_tag' ), 'With an order the hash differs from the keys alone, which wp_set_object_terms() would make.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::part_hash( $current, 'terms:post_tag' ), AB_MCP_Sync_Post_Writer::hashes( $current, array(), array() )['terms:post_tag'] );
		self::assertIsArray( AB_MCP_Sync_Kinds::lock( 'post', 'term_order' ), 'A lock of the table, with its stage.' );
		self::assertSame( 'Z8d', AB_MCP_Sync_Kinds::lock( 'post', 'term_order' )['stage'] );
		// The check before the first write: the post of this site with an
		// order other than 0 is locked, with the term and its order; with 0 it
		// passes as before.
		$db              = self::post_rows( 'News' );
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx( array( 'term' => array( 3 => 3 ) ) );
		$ctx['now']      = 1800000000;
		$prepared        = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'post' ), array( array( 'note', 'abc' ) ), array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ) ), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'Order 0: as before.' );
		$db->relations[12][0]['term_order'] = 3;
		$locked                             = AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] );
		self::assertSame( 'post:term_order', self::lock_of( $locked ) );
		self::assertStringContainsString( 'The term news of category on the post of this site carries the term_order 3', $locked->get_error_message() );
		self::assertStringContainsString( 'from Z8d', $locked->get_error_message() );
		// The second check of the kind on the way back (restore_terms()): the
		// terms read back as the backup by their entries, the order included.
		// The post here holds News (3) with the order 3; the backup held News
		// with the order 0: the keys are the same, the order is not. Writing
		// back (wp_set_object_terms(), here a stand-in that changes nothing)
		// leaves the order, so the part is unclear, with the order as the reason.
		$now = AB_MCP_Sync_Post_Writer::current( 12 );
		self::assertIsArray( $now );
		self::assertSame( array( '3#3' ), AB_MCP_Sync_Post_Writer::term_entries( $now, 'category' ) );
		$backup = $now;
		$backup['terms'][0]['term_order'] = 0;
		$written = AB_MCP_Sync_Post_Writer::part_hash( $now, 'terms:category' );
		$put     = AB_MCP_Sync_Post_Writer::restore_terms( 12, 'category', $backup, $written );
		self::assertSame( 'unclear', $put['state'] );
		self::assertStringContainsString( 'are back, but not their order (term_order)', $put['note'] );
		// A backup with another term: written back, read other than the backup (the stand-in writes nothing): unclear.
		$backup['terms'][0]['term_id'] = 4;
		$backup['terms'][0]['slug']    = 'other';
		$put                           = AB_MCP_Sync_Post_Writer::restore_terms( 12, 'category', $backup, $written );
		self::assertSame( 'unclear', $put['state'] );
		self::assertStringContainsString( 'do not read as the backup', $put['note'] );
		// As the backup already: unchanged, nothing written.
		$GLOBALS['ab_test_term_writes'] = array();
		self::assertSame( array( 'state' => 'unchanged' ), AB_MCP_Sync_Post_Writer::restore_terms( 12, 'category', $now, $written ) );
		self::assertSame( array(), $GLOBALS['ab_test_term_writes'] );
	}

	public function testTwoMetaKeysTheDatabaseComparesAlikeLockThePostBeforeTheFirstWrite(): void {
		// GitHub Codex round 6 on free#137 (P2): postmeta compares meta_key
		// with its collation (Foo and foo are one key), and update_metadata()
		// and delete_metadata() write by the key, so a write of one changes
		// the other; the journal tells keys byte for byte and could not
		// account for it. A post of this site whose rows the database matches
		// under another spelling for a key the step writes or removes is
		// locked before anything is written (post:meta_spelling); the rows
		// every spelling comes back with are AB_MCP_Undo_Meta::rows().
		$db              = self::post_rows( 'News' );
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx( array( 'term' => array( 3 => 3 ) ) );
		$ctx['now']      = 1800000000;
		$prepared        = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'post' ), array( array( 'note', 'abc' ) ), array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ) ), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ), 'One spelling: as before.' );
		self::assertIsArray( AB_MCP_Sync_Kinds::lock( 'post', 'meta_spelling' ), 'A lock of the table, with its stage.' );
		// The post holds note and Note: the database matches both for a
		// removal of Note (a key only this site has, which the step removes).
		$db->meta[12][] = array( 'meta_id' => 2, 'meta_key' => 'Note', 'meta_value' => 'other' );
		$locked         = AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] );
		self::assertSame( 'post:meta_spelling', self::lock_of( $locked ) );
		self::assertStringContainsString( 'The keys Note and note of the post on this site are one key to the database', $locked->get_error_message() );
		// A key only this site has, which the step removes, is asked the same.
		$db->meta[12] = array( array( 'meta_id' => 1, 'meta_key' => 'note', 'meta_value' => 'abc' ), array( 'meta_id' => 2, 'meta_key' => 'Color', 'meta_value' => 'red' ), array( 'meta_id' => 3, 'meta_key' => 'color', 'meta_value' => 'blue' ) );
		$locked       = AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] );
		self::assertSame( 'post:meta_spelling', self::lock_of( $locked ) );
		self::assertStringContainsString( 'Color and color', $locked->get_error_message() );
	}

	/** A post of a few rows in the fake $wpdb, with one category. */
	private static function post_rows( string $term_name ): SyncRunPostWpdb {
		$db           = new SyncRunPostWpdb();
		$db->rows[12] = array(
			'ID'                    => 12,
			'post_author'           => '0',
			'post_date'             => '2026-10-01 10:00:00',
			'post_date_gmt'         => '2026-10-01 10:00:00',
			'post_content'          => 'Hello.',
			'post_title'            => 'Hello',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'hello',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2026-10-01 10:00:00',
			'post_modified_gmt'     => '2026-10-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => '0',
			'guid'                  => '',
			'menu_order'            => '0',
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => '0',
		);
		$db->meta[12]      = array( array( 'meta_id' => 1, 'meta_key' => 'note', 'meta_value' => 'abc' ) );
		$db->relations[12] = array( array( 'term_taxonomy_id' => 3, 'term_order' => 0, 'term_id' => 3, 'taxonomy' => 'category', 'slug' => 'news' ) );
		$db->term_rows[3]  = array( 'term_id' => 3, 'name' => $term_name, 'slug' => 'news', 'term_group' => 0, 'term_taxonomy_id' => 3, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 );
		return $db;
	}

	public function testAJournalWithAMetaKeyThatIsNoUtf8ReadsBackAsItsBytesAndItsPartGoesBack(): void {
		// GitHub Codex round 8 on free#137 (P2): on a column that takes keys
		// that are no UTF-8, a plain key such as note\xE9 is a part of the
		// journal, meta:note\xE9, which AB_MCP_Sync_Canon::encode() writes as
		// {"~b": …}; decode_step() read it with json_decode() alone, the name
		// came back as the marker (an array), put_back() left the part out and
		// revert_boundary() ended on a TypeError. The records of a step are
		// read through AB_MCP_Sync_Canon::decode(): the name comes back as its
		// bytes, and the part goes back as any other.
		$key             = "note\xE9";
		$name            = 'meta:' . $key;
		$db              = self::post_rows( 'News' );
		$db->meta[12]    = array( array( 'meta_id' => 1, 'meta_key' => $key, 'meta_value' => 'ABC' ) );
		$GLOBALS['wpdb'] = $db;
		$now             = AB_MCP_Sync_Post_Writer::current( 12 );
		self::assertIsArray( $now );
		$backup                     = $now;
		$backup['meta'][0]['value'] = 'abc';
		$part    = static fn( string $part, bool $own ): array => array(
			'part'       => $part,
			'state'      => 'read',
			'before'     => AB_MCP_Sync_Post_Writer::part_hash( $backup, $part ),
			'target'     => AB_MCP_Sync_Post_Writer::part_hash( $now, $part ),
			'pre'        => AB_MCP_Sync_Post_Writer::part_hash( $backup, $part ),
			'post'       => AB_MCP_Sync_Post_Writer::part_hash( $now, $part ),
			'own'        => $own,
			'own_after'  => $own ? AB_MCP_Sync_Post_Writer::part_hash( $now, $part ) : null,
			'own_target' => $own ? AB_MCP_Sync_Post_Writer::part_hash( $now, $part ) : null,
		);
		$journal = array(
			'object'  => array( 'kind' => 'post', 'op' => 'update', 'staging_ref' => '4', 'live_ref' => '12', 'pair_id' => 5, 'as_draft' => false, 'target' => str_repeat( 'a', 64 ), 'deviated' => null, 'identity' => array( 'post_type' => 'post', 'post_name' => "hello\xE9" ) ),
			'backup'  => array( 'name' => 'step-0002', 'sha256' => str_repeat( 'b', 64 ), 'bytes' => 10 ),
			'parts'   => array( $part( 'row', false ), $part( $name, true ), $part( 'terms:category', false ) ),
			'settled' => true,
		);
		$encode = new ReflectionMethod( AB_MCP_Sync_Run::class, 'encode_json' );
		$stored = $encode->invoke( null, $journal, AB_MCP_Sync_Run::MAX_PARTS );
		self::assertIsString( $stored );
		self::assertStringContainsString( '"~b"', $stored, 'The name is stored as the marker of its bytes.' );
		$step = AB_MCP_Sync_Run::decode_step( array( 'run_id' => '4', 'seq' => '2', 'pair_id' => '5', 'op' => 'write', 'phase' => '5', 'depends' => '[1]', 'state' => 'done', 'parts' => $stored, 'pair_before' => null, 'result' => null, 'time' => '0' ) );
		$sorted = static function ( $value ) use ( &$sorted ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			$value = array_map( $sorted, $value );
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			return $value;
		};
		self::assertSame( $sorted( $journal ), $step['parts'], 'The journal reads back as it was written (keys sorted, as encode() writes them), bytes as bytes.' );
		self::assertSame( $name, $step['parts']['parts'][1]['part'] );
		self::assertArrayNotHasKey( 'unread', $step );
		// The way back of the step: every part has its outcome, the meta part
		// goes back byte for byte.
		$put  = ( new ReflectionMethod( AB_MCP_Sync_Engine::class, 'put_back' ) )->invoke( null, $step['parts'], 12, $backup );
		$keys = array_keys( $put['parts'] );
		sort( $keys );
		self::assertSame( array( 0, 1, 2 ), $keys );
		self::assertSame( 'restored', $put['parts'][1]['state'], json_encode( $put['parts'][1], JSON_INVALID_UTF8_SUBSTITUTE ) );
		self::assertSame( 'unchanged', $put['parts'][0]['state'] );
		self::assertSame( 'abc', $db->meta[12][0]['meta_value'] );
		self::assertTrue( $put['all_back'] );
		// A part of a name the engine does not know has its outcome too: left,
		// never put back, and the way back of the step goes on.
		$odd                     = $step['parts'];
		$odd['parts'][1]['part'] = array( '~b' => 'bm90ZennA==' );
		$db->meta[12][0]['meta_value'] = 'ABC';
		$put  = ( new ReflectionMethod( AB_MCP_Sync_Engine::class, 'put_back' ) )->invoke( null, $odd, 12, $backup );
		$keys = array_keys( $put['parts'] );
		sort( $keys );
		self::assertSame( array( 0, 1, 2 ), $keys, 'Every part has its outcome.' );
		self::assertSame( 'left', $put['parts'][1]['state'] );
		self::assertSame( 'ABC', $db->meta[12][0]['meta_value'], 'Not put back.' );
		self::assertFalse( $put['all_back'] );
	}

	public function testARecordOfARunWhoseMarkerDoesNotReadBackIsReadAsAbsentAndNamed(): void {
		// The other side of the decoding (AB_MCP_Sync_Run::records(), fail
		// closed): a marker that does not read back (base64 of bytes that are
		// UTF-8 after all, a float), which no writer of this version stores, is
		// read as absent and named in unread; the engine and the plan of the
		// way back decide nothing on it.
		$step = AB_MCP_Sync_Run::decode_step( array( 'run_id' => '4', 'seq' => '2', 'pair_id' => null, 'op' => 'write', 'phase' => '5', 'depends' => '[1]', 'state' => 'done', 'parts' => '{"parts":[{"part":{"~b":"bm90ZQ=="}}]}', 'pair_before' => null, 'result' => '{"a":1}', 'time' => '0' ) );
		self::assertNull( $step['parts'] );
		self::assertSame( array( 'parts' ), $step['unread'] );
		self::assertSame( array( 'a' => 1 ), $step['result'] );
		$run = AB_MCP_Sync_Run::decode( array( 'run_id' => '4', 'budget' => '{"bytes":5}', 'plan' => '{"bytes":{"~f":"000000000000f03f"}}' ) );
		self::assertNull( $run['plan'] );
		self::assertSame( array( 'plan' ), $run['unread'] );
		self::assertSame( array( 'bytes' => 5 ), $run['budget'] );
		self::assertArrayNotHasKey( 'unread', AB_MCP_Sync_Run::decode( array( 'run_id' => '4', 'budget' => null, 'plan' => '{"objects":[]}' ) ) );
	}

	public function testThePackageOfObjectsCarriesTheContentOfTheReadingItsHashCameFrom(): void {
		// Another writer changes the post between two readings: form and
		// content come from one reading all the same (A4).
		$db                   = self::post_rows( 'News' );
		$db->edit_after_first = 'Edited after canonical read';
		$GLOBALS['wpdb']      = $db;
		$kind                 = \AB_MCP_Sync_Db_Kinds::for_pair( 'post' );
		self::assertNotNull( $kind );
		$source = \AB_MCP_Sync_Inventory::source( array( 'https://staging.example.com' ), 60 );
		$out    = $kind->export( array( array( 'kind' => 'post', 'ref' => '12', 'source' => $source ) ) );
		self::assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		self::assertCount( 1, $out );
		self::assertSame( 1, $db->post_reads, 'The row is read once for form and content.' );
		self::assertSame( 'Hello', $out[0]['content']['row']['post_title'] );
		self::assertStringContainsString( '"post_title":"Hello"', AB_MCP_Sync_Canon::encode( $out[0]['form'] ) );
		self::assertSame( array( array( 'note', 'abc' ) ), $out[0]['content']['meta'] );
		self::assertSame( 'News', $out[0]['content']['terms'][0]['name'] );
		$line = \AB_MCP_Sync_Export::object_line( $out[0] );
		self::assertArrayHasKey( 'hash', $line );
		self::assertSame( $out[0]['hash'], $line['hash'] );
		self::assertSame( 'Hello', $line['content']['row']['post_title'] );
	}

	public function testATermNameWithACredentialHoldsTheObjectOfThePackage(): void {
		// The content adds the names and slugs of the terms to what the form
		// holds; they pass the credential check as the form does (A5).
		$GLOBALS['wpdb'] = self::post_rows( '{"api_key":"sk-live-0123456789"}' );
		$kind            = \AB_MCP_Sync_Db_Kinds::for_pair( 'post' );
		$source          = \AB_MCP_Sync_Inventory::source( array( 'https://staging.example.com' ), 60 );
		$out             = $kind->export( array( array( 'kind' => 'post', 'ref' => '12', 'source' => $source ) ) );
		self::assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$keys = array_column( $out[0]['locks'], 'key' );
		self::assertContains( '*:credential_field', $keys, (string) json_encode( $out[0]['locks'] ) );
		self::assertSame( 'term:category:name', $out[0]['locks'][ array_search( '*:credential_field', $keys, true ) ]['at'] );
		self::assertNotEmpty( $out[0]['credentials']['mixed'] );
		$line = \AB_MCP_Sync_Export::object_line( $out[0] );
		self::assertArrayNotHasKey( 'content', $line, 'Held: the package carries no content of it.' );
		self::assertArrayNotHasKey( 'hash', $line );
		self::assertStringNotContainsString( 'sk-live', AB_MCP_Sync_Canon::encode( $line ) );
		// A clean name: the line carries the content.
		$GLOBALS['wpdb'] = self::post_rows( 'News' );
		$out             = $kind->export( array( array( 'kind' => 'post', 'ref' => '12', 'source' => $source ) ) );
		self::assertArrayHasKey( 'content', \AB_MCP_Sync_Export::object_line( $out[0] ) );
	}

	public function testTheSampleOfAPublishedObjectNeedsTheTitleBesides200(): void {
		// Decision 33: HTTP 200 and the expected string, the title as the page shows it (B4).
		self::assertNull( AB_MCP_Sync_Engine::sample_finding( 200, '<html><title>Hello &amp; co</title></html>', 'Hello & co' ), 'The title as esc_html() shows it.' );
		self::assertNull( AB_MCP_Sync_Engine::sample_finding( 200, '<h1>Hello & co</h1>', 'Hello & co' ), 'The title raw.' );
		self::assertStringContainsString( 'did not show the title', (string) AB_MCP_Sync_Engine::sample_finding( 200, '<html><title>An old page from a cache</title></html>', 'Hello' ) );
		self::assertStringContainsString( 'page cache', (string) AB_MCP_Sync_Engine::sample_finding( 200, '', 'Hello' ) );
		self::assertSame( 'did not answer 200 (404).', AB_MCP_Sync_Engine::sample_finding( 404, 'Hello', 'Hello' ) );
		self::assertSame( 'did not answer 200 (no answer).', AB_MCP_Sync_Engine::sample_finding( null, '', 'Hello' ) );
	}

	public function testTheStepsOfAPhaseRunOnlyAfterThePhasesBeforeIt(): void {
		// Decision 16: the caches (phase 7) and the check of the run (phase 8)
		// run only once every write step (phase 5) has run, whatever became of
		// it; action=step could otherwise close the run before its writes (B2).
		$pick  = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'pick' );
		$run   = array( 'run_id' => 1, 'state' => 'open', 'type' => 'push' );
		$step  = static fn( int $seq, string $op, int $phase, array $depends, string $state ): array => array( 'seq' => $seq, 'op' => $op, 'phase' => $phase, 'depends' => $depends, 'state' => $state, 'result' => null, 'parts' => null, 'pair_id' => null );
		$steps = array(
			$step( 1, 'check', 1, array(), 'done' ),
			$step( 2, 'write', 5, array( 1 ), 'planned' ),
			$step( 3, 'write', 5, array( 1, 2 ), 'planned' ),
			$step( 4, 'post', 7, array( 1 ), 'planned' ),
			$step( 5, 'verify', 8, array( 1, 4 ), 'planned' ),
		);
		self::assertSame( 2, $pick->invoke( null, $run, $steps, null, true )['seq'], 'next: the first write step.' );
		$named = $pick->invoke( null, $run, $steps, 4, true );
		self::assertInstanceOf( WP_Error::class, $named );
		self::assertSame( 'ab_mcp_sync_step_waits', $named->get_error_code() );
		self::assertStringContainsString( 'phase 7', $named->get_error_message() );
		self::assertStringContainsString( 'step 2 is planned', $named->get_error_message() );
		self::assertSame( 'ab_mcp_sync_step_waits', $pick->invoke( null, $run, $steps, 5, true )->get_error_code(), 'The check of the run waits as well.' );
		self::assertSame( 2, $pick->invoke( null, $run, $steps, 2, true )['seq'], 'The write step itself runs.' );
		// Every write step settled, done or not: the caches run.
		$steps[1]['state'] = 'done';
		$steps[2]['state'] = 'failed';
		self::assertSame( 4, $pick->invoke( null, $run, $steps, 4, true )['seq'] );
		self::assertSame( 4, $pick->invoke( null, $run, $steps, null, true )['seq'] );
		self::assertSame( 'ab_mcp_sync_step_waits', $pick->invoke( null, $run, $steps, 5, true )->get_error_code(), 'The check waits for the caches (its dependency).' );
		// A write step begun (running): nothing of a later phase, and next finds nothing.
		$steps[2]['state'] = 'begun';
		self::assertNull( $pick->invoke( null, $run, $steps, null, true ) );
		self::assertSame( 'ab_mcp_sync_step_waits', $pick->invoke( null, $run, $steps, 4, true )->get_error_code() );
	}

	public function testTheHashesOfThePartsAreStableAndTermsAreASet(): void {
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'a', 'b' ) ), AB_MCP_Sync_Post_Writer::meta_hash( array( 'a', 'b' ) ) );
		self::assertNotSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'a', 'b' ) ), AB_MCP_Sync_Post_Writer::meta_hash( array( 'b', 'a' ) ), 'The order of the rows counts.' );
		self::assertNotSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'abc' ) ), AB_MCP_Sync_Post_Writer::meta_hash( array( 'ABC' ) ), 'Bytes, not a collation.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( array( 3, 1, 2 ) ), AB_MCP_Sync_Post_Writer::terms_hash( array( 1, 2, 3, 3 ) ), 'A set.' );
		$current = array(
			'row'   => array( 'post_title' => 'Hello' ),
			'meta'  => array( array( 'meta_id' => 1, 'key' => 'note', 'value' => 'a' ), array( 'meta_id' => 2, 'key' => 'note', 'value' => 'b' ), array( 'meta_id' => 3, 'key' => 'other', 'value' => 'c' ) ),
			'terms' => array( array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ) ),
		);
		$hashes  = AB_MCP_Sync_Post_Writer::hashes( $current, array( 'note', 'gone' ), array( 'post_tag' ) );
		self::assertSame( array( 'row', 'meta:note', 'meta:gone', 'terms:post_tag', 'meta:other', 'terms:category' ), array_keys( $hashes ), 'Every key asked and every key present.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'a', 'b' ) ), $hashes['meta:note'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array() ), $hashes['meta:gone'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( array( 3 ) ), $hashes['terms:category'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( array() ), $hashes['terms:post_tag'] );
		self::assertSame( array( 'row' => null, 'meta:note' => null ), AB_MCP_Sync_Post_Writer::hashes( null, array( 'note' ), array() ), 'No post: no hash.' );
		// One part by its name, as the engine asks it after the row was
		// written for what the core wrote with the row (N2): for no post, the
		// hash of nothing there, so that a part the write made compares.
		self::assertSame( $hashes['row'], AB_MCP_Sync_Post_Writer::part_hash( $current, 'row' ) );
		self::assertSame( $hashes['meta:note'], AB_MCP_Sync_Post_Writer::part_hash( $current, 'meta:note' ) );
		self::assertSame( $hashes['terms:category'], AB_MCP_Sync_Post_Writer::part_hash( $current, 'terms:category' ) );
		self::assertNull( AB_MCP_Sync_Post_Writer::part_hash( null, 'row' ) );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array() ), AB_MCP_Sync_Post_Writer::part_hash( null, 'meta:_wp_page_template' ), 'No post: no rows of the key.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( array() ), AB_MCP_Sync_Post_Writer::part_hash( null, 'terms:category' ) );
		self::assertSame( array( 'a', 'b' ), AB_MCP_Sync_Post_Writer::meta_values( $current, 'note' ) );
		self::assertSame( array( 3 ), AB_MCP_Sync_Post_Writer::term_keys( $current, 'category' ) );
		self::assertSame( 'x', AB_MCP_Sync_Post_Writer::bytes( 'x' ) );
		self::assertSame( "\xff\xfe", AB_MCP_Sync_Post_Writer::bytes( array( '~b' => base64_encode( "\xff\xfe" ) ) ), 'The byte form of the canon.' );
		self::assertSame( '', AB_MCP_Sync_Post_Writer::bytes( array( 'x' => 1 ) ) );
		// A format term is keyed by its slug (B6): the first use of a format
		// on this site has no term yet, and the one wp_set_object_terms() makes
		// has an id no target could name; by the slug, target and read back meet.
		$formatted = array(
			'row'   => array( 'post_title' => 'Hello' ),
			'meta'  => array(),
			'terms' => array( array( 'taxonomy' => 'post_format', 'term_id' => 9, 'slug' => 'post-format-aside', 'term_order' => 0 ), array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ) ),
		);
		self::assertSame( array( 'post-format-aside' ), AB_MCP_Sync_Post_Writer::term_keys( $formatted, 'post_format' ) );
		self::assertSame( array( 3 ), AB_MCP_Sync_Post_Writer::term_keys( $formatted, 'category' ) );
		self::assertSame( array( 'post-format-aside' ), AB_MCP_Sync_Post_Writer::target_term_keys( 'post_format', array( 'post-format-aside' ) ) );
		self::assertSame( array( 30 ), AB_MCP_Sync_Post_Writer::target_term_keys( 'category', array( 30 ) ) );
		self::assertSame( AB_MCP_Sync_Post_Writer::terms_hash( AB_MCP_Sync_Post_Writer::target_term_keys( 'post_format', array( 'post-format-aside' ) ) ), AB_MCP_Sync_Post_Writer::hashes( $formatted, array(), array( 'post_format' ) )['terms:post_format'], 'No term of the format on this site, and still the target hashes as the read back will.' );
		self::assertNotSame( AB_MCP_Sync_Post_Writer::terms_hash( array( 'post-format-aside' ) ), AB_MCP_Sync_Post_Writer::terms_hash( array( 'post-format-quote' ) ) );
	}

	public function testDecideOverEveryCombinationOfOwnershipStateDeviationWayAndForeignMark(): void {
		// The one place of the rule (Codex round 6 of branch 8; the decision of
		// 07.11.2026, «A mit altem Wert»; the table in GRENZEN-LAUF.md): a part
		// goes back by what the journal owns of it (own, own_after) and the
		// hash read now, never by its state, and never where the object
		// deviated within its step. Four distinct hashes: the backup, own_after
		// (what the step read back after its call changed the part), the
		// target, and another value. Every combination of own, now, deviated,
		// the way (terms, which the lock does not refuse) and the mark of a
		// change by another hand (foreign): the way and the mark change
		// nothing of the outcome since the decision (a deviation of any kind
		// ends the step, deviated()), and so the table holds them as «any».
		$hash = array(
			'backup'    => str_repeat( 'b', 64 ),
			'own_after' => str_repeat( 'w', 64 ),
			'target'    => str_repeat( 't', 64 ),
			'other'     => str_repeat( 'x', 64 ),
			'null'      => null,
		);
		$part = static function ( bool $own, bool $deviated, bool $terms, bool $foreign, $post = null, ?string $own_after = null ) use ( $hash ): array {
			return array(
				'part'       => $terms ? 'terms:category' : 'meta:note',
				'way'        => $terms ? AB_MCP_Sync_Post_Writer::WAY_TERMS : AB_MCP_Sync_Post_Writer::WAY_META,
				'before'     => $hash['backup'],
				'target'     => $hash['target'],
				'state'      => 'planned',
				'after'      => $post,
				'pre'        => $hash['backup'],
				'post'       => $post,
				'own'        => $own,
				'own_after'  => $own ? ( $own_after ?? $hash['own_after'] ) : null,
				'own_target' => $own ? $hash['target'] : null,
				'deviated'   => $deviated,
				'foreign'    => $foreign,
			);
		};
		// own, now, deviated => expected; terms and foreign run over both values each.
		$rule = array(
			array( true , 'backup'   , false, 'unchanged' ),
			array( true , 'backup'   , true , 'unchanged' ),
			array( true , 'own_after', false, 'put_back'  ),
			array( true , 'own_after', true , 'left'      ),
			array( true , 'target'   , false, 'left'      ),
			array( true , 'target'   , true , 'left'      ),
			array( true , 'other'    , false, 'left'      ),
			array( true , 'other'    , true , 'left'      ),
			array( true , 'null'     , false, 'left'      ),
			array( true , 'null'     , true , 'left'      ),
			array( false, 'backup'   , false, 'unchanged' ),
			array( false, 'backup'   , true , 'unchanged' ),
			array( false, 'own_after', false, 'left'      ),
			array( false, 'own_after', true , 'left'      ),
			array( false, 'target'   , false, 'left'      ),
			array( false, 'target'   , true , 'left'      ),
			array( false, 'other'    , false, 'left'      ),
			array( false, 'other'    , true , 'left'      ),
			array( false, 'null'     , false, 'left'      ),
			array( false, 'null'     , true , 'left'      ),
		);
		$table = array();
		foreach ( $rule as $row ) {
			foreach ( array( true, false ) as $terms ) {
				foreach ( array( true, false ) as $foreign ) {
					$table[] = array( $row[0], $row[1], $row[2], $terms, $foreign, $row[3] );
				}
			}
		}
		self::assertCount( 80, $table, 'Every combination of 2 x 5 x 2 x 2 x 2.' );
		foreach ( $table as $row ) {
			list( $own, $now, $deviated, $terms, $foreign, $expected ) = $row;
			$case = sprintf( 'own=%s now=%s deviated=%s terms=%s foreign=%s', var_export( $own, true ), $now, var_export( $deviated, true ), var_export( $terms, true ), var_export( $foreign, true ) );
			$got  = AB_MCP_Sync_Engine::decide( $part( $own, $deviated, $terms, $foreign ), $hash[ $now ], $hash['backup'] );
			self::assertSame( $expected, $got['state'], $case );
			if ( 'put_back' === $expected ) {
				self::assertSame( $hash['own_after'], $got['hash'], $case . ': what the part has to hold as it is put back is own_after.' );
			} else {
				self::assertArrayNotHasKey( 'hash', $got, $case );
			}
			if ( 'left' === $expected && null !== $hash[ $now ] ) {
				self::assertStringContainsString( $deviated ? 'deviated within its step' : ( $own ? 'changed since the step wrote it' : 'Not the step\'s own' ), $got['why'], $case );
				if ( $deviated ) {
					self::assertStringContainsString( 'value_before', $got['why'], $case . ': the answer names where the value before stands.' );
				}
			}
		}
		// A part the step owns that reads back as its target (read, the usual
		// case of a step done): back where it still holds it, on either way.
		foreach ( array( false, true ) as $terms ) {
			$read = $part( true, false, $terms, false, $hash['target'], $hash['target'] );
			self::assertSame( 'put_back', AB_MCP_Sync_Engine::decide( $read, $hash['target'], $hash['backup'] )['state'], 'As its target, the step\'s own: back.' );
			self::assertSame( 'left', AB_MCP_Sync_Engine::decide( $read, $hash['other'], $hash['backup'] )['state'], 'Changed since: left.' );
			self::assertSame( 'unchanged', AB_MCP_Sync_Engine::decide( $read, $hash['backup'], $hash['backup'] )['state'] );
		}
		// A part never read back (post null: the request of the step ended in
		// the call, decision 18) holding its target: a hash equal to the target
		// proves no ownership, so it is left, with why (before the decision,
		// the one boundary C put it back by the target).
		$never = AB_MCP_Sync_Engine::decide( $part( false, false, false, false ), $hash['target'], $hash['backup'] );
		self::assertSame( 'left', $never['state'] );
		self::assertStringContainsString( 'never read it back', $never['why'] );
		// A part the step never reached (settled()): left, with why.
		$reached              = $part( false, false, false, false );
		$reached['state']     = 'not_reached';
		self::assertStringContainsString( 'never wrote it', AB_MCP_Sync_Engine::decide( $reached, $hash['target'], $hash['backup'] )['why'] );
		// own without own_after owns nothing (a mark without a hash).
		$bare              = $part( true, false, false, false );
		$bare['own_after'] = null;
		self::assertSame( 'left', AB_MCP_Sync_Engine::decide( $bare, $hash['own_after'], $hash['backup'] )['state'] );
		// The mark of a journal of an earlier build (revert_boundary() sets it
		// where the object does not say whether it deviated): left, with why.
		$earlier             = $part( true, false, false, false, $hash['own_after'] );
		$earlier['deviated'] = 'earlier_build';
		$old                 = AB_MCP_Sync_Engine::decide( $earlier, $hash['own_after'], $hash['backup'] );
		self::assertSame( 'left', $old['state'] );
		self::assertStringContainsString( 'earlier build', $old['why'] );
		// A journal of an earlier shape without the marks: nothing is owned.
		self::assertSame( 'left', AB_MCP_Sync_Engine::decide( array( 'part' => 'row', 'way' => AB_MCP_Sync_Post_Writer::WAY_ROW, 'target' => $hash['target'], 'state' => 'written', 'after' => $hash['own_after'] ), $hash['own_after'], $hash['backup'] )['state'], 'after alone, a description, owns nothing.' );
	}

	public function testAPartNotReadBackAfterItsCallIsNeverTheStepsOwn(): void {
		// Codex round 11: the read after the step's call failed (or the post
		// is gone): seen_after() gets no state. The part is no read back
		// (post null, unread), never the hash of an empty set, never the
		// step's own; write_parts() deviates the object on the mark.
		$part = array(
			'part'   => 'meta:note',
			'way'    => AB_MCP_Sync_Post_Writer::WAY_META,
			'state'  => 'planned',
			'before' => str_repeat( 'b', 64 ),
			'target' => AB_MCP_Sync_Post_Writer::part_hash( null, 'meta:note' ),
			'pre'    => str_repeat( 'b', 64 ),
			'post'   => null,
			'own'    => false,
		);
		$seen = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'seen_after' );
		$after = $seen->invoke( null, $part, null, (string) $part['target'], false, AB_MCP_Sync_Post_Writer::class );
		self::assertNull( $after['post'] );
		self::assertTrue( $after['unread'] );
		self::assertFalse( $after['own'], 'A target of no value is no read back of it.' );
		self::assertSame( 'left', AB_MCP_Sync_Engine::decide( $after, (string) $part['target'], str_repeat( 'b', 64 ) )['state'] );
	}

	public function testAJournalWithoutTheMarkOfOwnershipIsNeverPutBack(): void {
		// Codex round 7, F3: a part of a journal from an earlier build of this
		// branch has no own field; holding its target now, never read back by
		// that build's lights, it is left (fail closed: nothing is owned), and
		// as the backup it is unchanged. Since the decision of 07.11.2026 no
		// clause of its own is needed for it: a part without own is not the
		// step's own, and no part goes back by its target.
		$old = array(
			'part'   => 'terms:category',
			'way'    => AB_MCP_Sync_Post_Writer::WAY_TERMS,
			'state'  => 'written',
			'before' => str_repeat( 'b', 64 ),
			'target' => str_repeat( 't', 64 ),
			'after'  => str_repeat( 'c', 64 ),
		);
		$left = AB_MCP_Sync_Engine::decide( $old, str_repeat( 't', 64 ), str_repeat( 'b', 64 ) );
		self::assertSame( 'left', $left['state'] );
		self::assertStringContainsString( 'Not the step\'s own', $left['why'] );
		self::assertSame( 'left', AB_MCP_Sync_Engine::decide( $old, str_repeat( 'c', 64 ), str_repeat( 'b', 64 ) )['state'] );
		self::assertSame( 'unchanged', AB_MCP_Sync_Engine::decide( $old, str_repeat( 'b', 64 ), str_repeat( 'b', 64 ) )['state'] );
	}

	public function testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped(): void {
		// The decision of 07.11.2026, «mit altem Wert»: a part left names what
		// it held before the step, from the backup (before_value()): the row by
		// its columns that differ from now, a long text by its bytes and the
		// revision that holds it, meta by its values, terms by their names
		// (their slug where this test has no term table); never a credential,
		// never binary, cut per value and per part.
		$backup = array(
			'row'   => array(
				'post_author'           => '1',
				'post_date'             => '2026-10-01 10:00:00',
				'post_date_gmt'         => '2026-10-01 10:00:00',
				'post_content'          => str_repeat( 'Write to us. ', 20 ),
				'post_title'            => 'Contact',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => 'geheim-synthetic-7f3a',
				'post_name'             => 'contact',
				'post_content_filtered' => '',
				'post_parent'           => '0',
				'menu_order'            => '0',
				'post_type'             => 'page',
				'post_mime_type'        => '',
			),
			'meta'  => array(
				array( 'meta_id' => 1, 'key' => 'note', 'value' => 'abc' ),
				array( 'meta_id' => 2, 'key' => 'note', 'value' => 'def' ),
				array( 'meta_id' => 3, 'key' => 'api_token', 'value' => 'sk-synthetic-ABCDEF0123456789' ),
				array( 'meta_id' => 4, 'key' => 'settings', 'value' => '{"password":"synthetic-pw-9981","mode":"x"}' ),
				array( 'meta_id' => 5, 'key' => 'blob', 'value' => "\xFF\xFE\x00binary" ),
				array( 'meta_id' => 6, 'key' => 'long', 'value' => str_repeat( 'y', 500 ) ),
			),
			'terms' => array(
				array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ),
				array( 'taxonomy' => 'category', 'term_id' => 4, 'slug' => 'other', 'term_order' => 0 ),
				array( 'taxonomy' => 'post_tag', 'term_id' => 9, 'slug' => 'api_key=synthetic-77', 'term_order' => 0 ),
			),
		);
		$now = $backup;
		$now['row']['post_title']    = 'Changed by a filter';
		$now['row']['post_content']  = 'Write to us on staging.';
		$now['row']['post_password'] = 'another';
		// The rows this site holds now: the names of the terms (raw rows, as
		// the whole of includes/sync/ reads them), and a revision of another
		// text; nothing else is read.
		$db                  = new SyncRunPostWpdb();
		$db->term_rows[3]    = array( 'term_id' => 3, 'name' => 'News', 'slug' => 'news', 'term_group' => 0, 'term_taxonomy_id' => 3, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 );
		$db->revisions[77]   = array( array( 'ID' => 701, 'post_content' => 'something else', 'post_excerpt' => '' ) );
		$GLOBALS['wpdb']     = $db;
		// The row: only the columns that differ, the password never, the long
		// text by its bytes; no revision of this post holds the text.
		$row = AB_MCP_Sync_Post_Writer::before_value( 'row', $backup, $now, 77 );
		self::assertStringContainsString( 'post_title: "Contact"', $row );
		self::assertStringContainsString( 'post_content: 260 bytes (no revision of the post holds it; the backup of the step does)', $row );
		self::assertStringContainsString( 'post_password: (a credential by its name, not shown)', $row );
		self::assertStringNotContainsString( 'geheim-synthetic', $row );
		self::assertStringNotContainsString( 'post_status', $row, 'A column as now is not named.' );
		// A revision that holds the text byte for byte is named, the newest.
		$db->revisions[77][] = array( 'ID' => 702, 'post_content' => str_repeat( 'Write to us. ', 20 ), 'post_excerpt' => '' );
		self::assertStringContainsString( 'revision 702 of the post holds it', AB_MCP_Sync_Post_Writer::before_value( 'row', $backup, $now, 77 ) );
		// The row gone: the named columns.
		$gone = AB_MCP_Sync_Post_Writer::before_value( 'row', $backup, null, null );
		self::assertStringContainsString( 'post_title: "Contact"', $gone );
		self::assertStringContainsString( 'post_status: "publish"', $gone );
		self::assertStringContainsString( 'post_name: "contact"', $gone );
		// Meta: the values in the order of their rows; a key that names a
		// credential, a value with a credential inside, a binary value: never
		// in the clear.
		self::assertSame( '"abc", "def"', AB_MCP_Sync_Post_Writer::before_value( 'meta:note', $backup, $now, 77 ) );
		$token = AB_MCP_Sync_Post_Writer::before_value( 'meta:api_token', $backup, $now, 77 );
		self::assertSame( '(a credential by its name, not shown)', $token );
		self::assertStringNotContainsString( 'sk-synthetic', $token );
		$mixed = AB_MCP_Sync_Post_Writer::before_value( 'meta:settings', $backup, $now, 77 );
		self::assertSame( '(holds a credential, not shown)', $mixed );
		self::assertStringNotContainsString( 'synthetic-pw', $mixed );
		self::assertSame( '(9 bytes, not UTF-8)', AB_MCP_Sync_Post_Writer::before_value( 'meta:blob', $backup, $now, 77 ) );
		self::assertSame( 'none (no row of the key)', AB_MCP_Sync_Post_Writer::before_value( 'meta:absent', $backup, $now, 77 ) );
		// Cut per value (VALUE_CHARS) and per part (PART_CHARS).
		$long = AB_MCP_Sync_Post_Writer::before_value( 'meta:long', $backup, $now, 77 );
		self::assertSame( '"' . str_repeat( 'y', AB_MCP_Sync_Post_Writer::VALUE_CHARS ) . '…(cut)"', $long );
		$wide = $backup;
		for ( $i = 0; $i < 8; $i++ ) {
			$wide['meta'][] = array( 'meta_id' => 100 + $i, 'key' => 'many', 'value' => str_repeat( 'z', 70 ) );
		}
		$many = AB_MCP_Sync_Post_Writer::before_value( 'meta:many', $wide, $now, 77 );
		self::assertStringEndsWith( '…(cut)', $many );
		self::assertLessThanOrEqual( AB_MCP_Sync_Post_Writer::PART_CHARS + mb_strlen( '…(cut)' ), mb_strlen( $many ) );
		// Terms by the slug the backup holds, never by the name a term has now
		// (Codex round 8: a name renamed since, even to a credential, is no
		// value before); a slug with a credential written into it (as the
		// package checks the names of terms, A5) is not shown.
		$db->term_rows[3]['name'] = 'api_token:"SYNTHETIC-SECRET-77"';
		self::assertSame( '"news", "other"', AB_MCP_Sync_Post_Writer::before_value( 'terms:category', $backup, $now, 77 ) );
		// A value the net names a credential in (a layer the content check
		// does not read as one) is not shown either.
		$netted                 = $backup;
		$netted['meta'][]       = array( 'meta_id' => 120, 'key' => 'snippet', 'value' => 'api_token:"SYNTHETIC-SECRET-77"' );
		$snippet                = AB_MCP_Sync_Post_Writer::before_value( 'meta:snippet', $netted, $now, 77 );
		self::assertSame( '(holds a credential, not shown)', $snippet );
		self::assertStringNotContainsString( 'SYNTHETIC-SECRET', $snippet );
		$tag = AB_MCP_Sync_Post_Writer::before_value( 'terms:post_tag', $backup, $now, 77 );
		self::assertSame( '(holds a credential, not shown)', $tag );
		self::assertStringNotContainsString( 'synthetic-77', $tag );
		self::assertSame( 'none (no term of the taxonomy)', AB_MCP_Sync_Post_Writer::before_value( 'terms:post_format', $backup, $now, 77 ) );
		// A new object has nothing before.
		self::assertStringContainsString( 'not on this site before', AB_MCP_Sync_Post_Writer::before_value( 'row', null, $now, 77 ) );
	}

	public function testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst(): void {
		// AB_MCP_Sync_Run::cap_values(): the values before of every step shown
		// (the parts of the journal and the outcome of a step of the way back)
		// within ANSWER_VALUE_BYTES in all, the newest steps first; what has
		// no room says so.
		$value = str_repeat( 'v', 1000 );
		$steps = array();
		for ( $seq = 1; $seq <= 20; $seq++ ) {
			$steps[] = array(
				'seq'    => $seq,
				'parts'  => array( 'parts' => array( array( 'part' => 'row', 'state' => 'left', 'value_before' => $value, 'value_rules' => AB_MCP_Sync_Post_Writer::VALUE_RULES ) ) ),
				'result' => array( 'parts' => array( array( 'part' => 'meta:note', 'state' => 'left', 'value_before' => $value, 'value_rules' => AB_MCP_Sync_Post_Writer::VALUE_RULES ), array( 'part' => 'terms:category', 'state' => 'restored' ) ) ),
			);
		}
		$capped = AB_MCP_Sync_Run::cap_values( $steps );
		$kept   = 0;
		$cut    = 0;
		$bytes  = 0;
		foreach ( $capped as $step ) {
			foreach ( array( $step['parts']['parts'][0], $step['result']['parts'][0] ) as $part ) {
				if ( ! isset( $part['value_before'] ) ) {
					continue;
				}
				$bytes += strlen( $part['value_before'] );
				if ( $value === $part['value_before'] ) {
					++$kept;
				} else {
					self::assertSame( AB_MCP_Sync_Run::VALUE_CUT, $part['value_before'] );
					++$cut;
				}
			}
			self::assertArrayNotHasKey( 'value_before', $step['result']['parts'][1], 'A part not left carries no value before.' );
		}
		self::assertSame( 16, $kept, '16 values of 1000 bytes fit into 16384.' );
		// The marks count against the budget as well (Codex round 8): past it
		// even for a mark, the value is left out; the answer never carries
		// more than ANSWER_VALUE_BYTES of values before.
		self::assertSame( intdiv( 16384 - 16000, strlen( AB_MCP_Sync_Run::VALUE_CUT ) ), $cut );
		self::assertLessThanOrEqual( 16384, $bytes );
		self::assertArrayNotHasKey( 'value_before', $capped[0]['parts']['parts'][0], 'The oldest values are left out past the budget.' );
		self::assertSame( $value, $capped[19]['parts']['parts'][0]['value_before'], 'The newest step keeps its values.' );
		self::assertSame( $value, $capped[12]['parts']['parts'][0]['value_before'] );
		self::assertSame( AB_MCP_Sync_Run::VALUE_CUT, $capped[11]['parts']['parts'][0]['value_before'], 'The first past the budget say so.' );
		self::assertArrayNotHasKey( 'value_before', $capped[0]['result']['parts'][0] );
		self::assertSame( array(), AB_MCP_Sync_Run::cap_values( array() ) );
		// Every value before is checked once more as it leaves (Codex round 9):
		// one a journal of an earlier build holds (no value_rules of this
		// build) is never shown, a credential in it or not; the values this
		// build composes pass as they are, a credential in one only as the
		// reference.
		$old    = array(
			array(
				'seq'   => 1,
				'parts' => array(
					'parts' => array(
						array( 'part' => 'terms:category', 'state' => 'left', 'value_before' => '"api_token:"SYNTHETIC-SECRET-77""' ),
						array( 'part' => 'row', 'state' => 'left', 'value_before' => 'post_title: "Contact"; post_password: (a credential by its name, not shown); post_content: 260 bytes (revision 702 of the post holds it, wp-admin → Revisions)', 'value_rules' => AB_MCP_Sync_Post_Writer::VALUE_RULES ),
						array( 'part' => 'meta:note', 'state' => 'left', 'value_before' => '"abc", "def"', 'value_rules' => AB_MCP_Sync_Post_Writer::VALUE_RULES ),
						array( 'part' => 'meta:snippet', 'state' => 'left', 'value_before' => 'api_token:"SYNTHETIC-SECRET-77"', 'value_rules' => AB_MCP_Sync_Post_Writer::VALUE_RULES ),
					),
				),
			),
		);
		$shown  = AB_MCP_Sync_Run::cap_values( $old );
		self::assertStringStartsWith( '(composed by an earlier build, not shown', $shown[0]['parts']['parts'][0]['value_before'] );
		self::assertStringNotContainsString( 'SYNTHETIC-SECRET', $shown[0]['parts']['parts'][0]['value_before'] );
		self::assertSame( $old[0]['parts']['parts'][1]['value_before'], $shown[0]['parts']['parts'][1]['value_before'] );
		self::assertSame( '"abc", "def"', $shown[0]['parts']['parts'][2]['value_before'] );
		self::assertSame( '(holds a credential, not shown)', $shown[0]['parts']['parts'][3]['value_before'] );
	}

	/* -------------------------------------------------------- the run */

	public function testTheShapeOfARunAndWhenItsLockIsFresh(): void {
		$raw = array( 'run_id' => '4', 'type' => 'push', 'state' => 'open', 'user_id' => '3', 'created' => '10', 'updated' => '11', 'closed' => '0', 'heartbeat' => '11', 'expires' => '20', 'open_one' => '1', 'forward_closed_at' => '0', 'calls' => '2', 'budget' => '{"bytes":5}', 'plan' => '{"objects":[]}', 'connection' => 'c' );
		$run = AB_MCP_Sync_Run::decode( $raw );
		self::assertSame( 4, $run['run_id'] );
		self::assertSame( 1, $run['open_one'] );
		self::assertSame( array( 'bytes' => 5 ), $run['budget'] );
		self::assertSame( array( 'objects' => array() ), $run['plan'] );
		$closed = AB_MCP_Sync_Run::decode( array( 'run_id' => '5', 'open_one' => null, 'budget' => null, 'plan' => null ) );
		self::assertNull( $closed['open_one'] );
		self::assertNull( $closed['plan'] );
		$step = AB_MCP_Sync_Run::decode_step( array( 'run_id' => '4', 'seq' => '2', 'pair_id' => null, 'op' => 'write', 'phase' => '5', 'depends' => '[1]', 'state' => 'planned', 'parts' => null, 'pair_before' => null, 'result' => '{"a":1}', 'time' => '0' ) );
		self::assertSame( array( 1 ), $step['depends'] );
		self::assertNull( $step['pair_id'] );
		self::assertSame( array( 'a' => 1 ), $step['result'] );
		// The connection a run is bound to: the account without a token.
		$conn = AB_MCP_Staging_Gate::connection();
		self::assertSame( hash( 'sha256', 'ab-sync-run|' . $conn['kind'] . ':' . $conn['id'] ), AB_MCP_Sync_Run::connection(), 'Bound to the connection of the call, as a proposal is.' );
		self::assertNotSame( \AB_MCP_Sync_Proposal::connection(), AB_MCP_Sync_Run::connection(), 'Its own namespace.' );
		self::assertNull( AB_MCP_Sync_Run::owner_refusal( array( 'run_id' => 1, 'user_id' => 3, 'connection' => AB_MCP_Sync_Run::connection() ) ) );
		self::assertSame( 'ab_mcp_sync_run_foreign', AB_MCP_Sync_Run::owner_refusal( array( 'run_id' => 1, 'user_id' => 4, 'connection' => AB_MCP_Sync_Run::connection() ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_run_foreign', AB_MCP_Sync_Run::owner_refusal( array( 'run_id' => 1, 'user_id' => 3, 'connection' => 'other' ) )->get_error_code() );
		// The lock of the boundary (decision 18): fresh while its row is younger than TIMEOUT.
		AB_MCP_Sync_Run::$clock = static fn(): int => 5000;
		self::assertFalse( AB_MCP_Sync_Run::lock_fresh(), 'No row.' );
		update_option( AB_MCP_Change_Lock::OPTION, 5000 - AB_MCP_Change_Lock::TIMEOUT + 1 );
		self::assertTrue( AB_MCP_Sync_Run::lock_fresh() );
		update_option( AB_MCP_Change_Lock::OPTION, 5000 - AB_MCP_Change_Lock::TIMEOUT );
		self::assertFalse( AB_MCP_Sync_Run::lock_fresh(), 'TIMEOUT old: left behind.' );
		self::assertSame( array( 'open', 'interrupted', 'reverting' ), AB_MCP_Sync_Run::OPEN_STATES );
		self::assertSame( 'ab_mcp_sync_run_unconfirmed', AB_MCP_Sync_Run::close_checked( 1, 3, false )->get_error_code(), 'Closing as checked takes a person in wp-admin.' );
	}

	public function testTheRetentionNamesTheRunsWhoseBackupsStay(): void {
		$db              = new SyncRunRowsWpdb();
		$GLOBALS['wpdb'] = $db;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$now      = 1700000000;
		$day      = 86400;
		// Every close stores the period it got (keep_until): in Free a
		// qualified close the time of the close itself (the last three stay
		// without an age), an unqualified one seven days.
		$db->rows = array(
			array( 'run_id' => 1, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 40 * $day, 'forward_closed_at' => $now - 40 * $day, 'keep_until' => $now - 40 * $day, 'plan' => null ),
			array( 'run_id' => 2, 'type' => 'push', 'state' => 'closed_findings', 'closed' => $now - 20 * $day, 'forward_closed_at' => $now - 20 * $day, 'keep_until' => $now - 20 * $day, 'plan' => null ),
			array( 'run_id' => 3, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 3 * $day, 'forward_closed_at' => $now - 3 * $day, 'keep_until' => $now - 3 * $day, 'plan' => null ),
			array( 'run_id' => 4, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 2 * $day, 'forward_closed_at' => $now - 2 * $day, 'keep_until' => $now - 2 * $day, 'plan' => null ),
			array( 'run_id' => 5, 'type' => 'push', 'state' => 'closed_aborted', 'closed' => $now - 6 * $day, 'forward_closed_at' => 0, 'keep_until' => $now + 1 * $day, 'plan' => null ),
			array( 'run_id' => 6, 'type' => 'push', 'state' => 'closed_checked', 'closed' => $now - 8 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - 1 * $day, 'plan' => null ),
			array( 'run_id' => 7, 'type' => 'revert', 'state' => 'closed_reverted', 'closed' => $now - 1, 'forward_closed_at' => 0, 'keep_until' => $now + 7 * $day - 1, 'plan' => '{"reverts":[1]}' ),
			array( 'run_id' => 8, 'type' => 'revert', 'state' => 'open', 'closed' => 0, 'forward_closed_at' => 0, 'keep_until' => 0, 'plan' => '{"reverts":[1]}' ),
			array( 'run_id' => 9, 'type' => 'push', 'state' => 'interrupted', 'closed' => 0, 'forward_closed_at' => 0, 'keep_until' => 0, 'plan' => null ),
		);
		$kept = AB_MCP_Sync_Schema::backup_runs( $now );
		ksort( $kept );
		// Free: the last three qualified closes (2, 3, 4; 1 is the fourth), the
		// aborted run within its seven days (5; 6 is past them), the way back
		// closed just now (7), the open way back (8) and the run it needs (1),
		// the interrupted run (9).
		self::assertSame( array( 1, 2, 3, 4, 5, 7, 8, 9 ), array_keys( $kept ) );
		// Without the open way back, run 1 goes.
		$db->rows[7]['state']      = 'closed_reverted';
		$db->rows[7]['closed']     = $now;
		$db->rows[7]['keep_until'] = $now + 7 * $day;
		$kept                      = AB_MCP_Sync_Schema::backup_runs( $now );
		ksort( $kept );
		self::assertSame( array( 2, 3, 4, 5, 7, 8, 9 ), array_keys( $kept ) );
		// A close under Pro stored its 30 days (rules 3 and 5), and keeps them
		// whatever the edition is now (rule 6; A7): the fourth qualified close
		// of 29 days ago, the run closed as checked 8 days ago.
		$db->rows[0]['forward_closed_at'] = $now - 29 * $day;
		$db->rows[0]['keep_until']        = $now + 1 * $day;
		$db->rows[5]['keep_until']        = $now + 22 * $day;
		$kept                             = AB_MCP_Sync_Schema::backup_runs( $now );
		self::assertArrayHasKey( 1, $kept, 'Within the days its close got.' );
		self::assertArrayHasKey( 6, $kept, 'An unqualified close of Pro: 30 days, not 7.' );
		$db->rows[0]['keep_until'] = $now;
		self::assertArrayNotHasKey( 1, AB_MCP_Sync_Schema::backup_runs( $now ), 'The period reached: the last three only.' );
		// A row of version 2 (no keep_until stored, 0) is kept as long as it is
		// there, fail closed (N1): no stored period says what its close was
		// promised; qualified or not, however old.
		$db->rows[5]['keep_until'] = 0;
		$db->rows[5]['closed']     = $now - 400 * $day;
		self::assertArrayHasKey( 6, AB_MCP_Sync_Schema::backup_runs( $now ), 'An unqualified close of version 2: kept.' );
		$db->rows[]                = array( 'run_id' => 10, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 300 * $day, 'forward_closed_at' => $now - 300 * $day, 'keep_until' => 0, 'plan' => null );
		$kept                      = AB_MCP_Sync_Schema::backup_runs( $now );
		self::assertArrayHasKey( 10, $kept, 'A qualified close of version 2 past the last three: kept.' );
		self::assertArrayNotHasKey( 1, $kept, 'A close with its period stored and reached goes as before.' );
		$db->rows[5]['keep_until'] = $now - 1;
		self::assertArrayNotHasKey( 6, AB_MCP_Sync_Schema::backup_runs( $now ), 'With a period stored and reached it goes.' );
		// The period a close gets (decision 20, rules 3 and 5): never 0.
		self::assertSame( $now, AB_MCP_Sync_Schema::keep_until( $now, true, 0 ), 'Free, qualified: the period ends at the close; the last three stay without an age.' );
		self::assertSame( $now + 30 * $day, AB_MCP_Sync_Schema::keep_until( $now, true, 30 ), 'Pro, qualified: 30 days.' );
		self::assertSame( $now + 7 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 0 ), 'Free, unqualified: 7 days.' );
		self::assertSame( $now + 30 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 30 ), 'Pro, unqualified: 30 days.' );
		self::assertSame( $now + 7 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 3 ), 'Never less than the seven days of Free.' );
		// Only a qualified close counts among the last three: with two of them
		// and an unqualified close whose period is reached, the unqualified
		// one goes, it does not fill the third place.
		$db->rows = array(
			array( 'run_id' => 20, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 90 * $day, 'forward_closed_at' => $now - 90 * $day, 'keep_until' => $now - 90 * $day, 'plan' => null ),
			array( 'run_id' => 21, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 80 * $day, 'forward_closed_at' => $now - 80 * $day, 'keep_until' => $now - 80 * $day, 'plan' => null ),
			array( 'run_id' => 22, 'type' => 'push', 'state' => 'closed_aborted', 'closed' => $now - 70 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - 63 * $day, 'plan' => null ),
		);
		$kept = AB_MCP_Sync_Schema::backup_runs( $now );
		ksort( $kept );
		self::assertSame( array( 20, 21 ), array_keys( $kept ) );
		// The table unreadable: null, so the store keeps everything.
		$db->last_error = 'gone';
		self::assertNull( AB_MCP_Sync_Schema::backup_runs( $now ) );
	}

	public function testTheRetentionNamesEveryRowSoARunWithoutOneIsToldFromOneWhoseBackupsGo(): void {
		$db              = new SyncRunRowsWpdb();
		$GLOBALS['wpdb'] = $db;
		update_option( AB_MCP_Sync_Schema::OPTION, (string) AB_MCP_Sync_Schema::VERSION );
		AB_MCP_Sync_Schema::forget();
		$now      = 1700000000;
		$day      = 86400;
		$db->rows = array(
			array( 'run_id' => 1, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 40 * $day, 'forward_closed_at' => $now - 40 * $day, 'keep_until' => $now - 40 * $day, 'plan' => null ),
			array( 'run_id' => 2, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 20 * $day, 'forward_closed_at' => $now - 20 * $day, 'keep_until' => $now - 20 * $day, 'plan' => null ),
			array( 'run_id' => 3, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 3 * $day, 'forward_closed_at' => $now - 3 * $day, 'keep_until' => $now - 3 * $day, 'plan' => null ),
			array( 'run_id' => 4, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 2 * $day, 'forward_closed_at' => $now - 2 * $day, 'keep_until' => $now - 2 * $day, 'plan' => null ),
			array( 'run_id' => 5, 'type' => 'push', 'state' => 'closed_aborted', 'closed' => $now - 8 * $day, 'forward_closed_at' => 0, 'keep_until' => $now - 1 * $day, 'plan' => null ),
			array( 'run_id' => 6, 'type' => 'revert', 'state' => 'open', 'closed' => 0, 'forward_closed_at' => 0, 'keep_until' => 0, 'plan' => '{"reverts":[5,11]}' ),
		);
		// Every row is named: true where the contract keeps its backups (the
		// last three qualified closes, the open way back and what it needs,
		// among them run 11 without a row), false where they go (the fourth
		// qualified close, 1); so the clean-up tells a run whose row says
		// its backups go from a run without a row (none for 12), which it
		// keeps by its age instead of removing it at once.
		$rows = AB_MCP_Sync_Schema::backup_rows( $now );
		ksort( $rows );
		self::assertSame(
			array(
				1  => false,
				2  => true,
				3  => true,
				4  => true,
				5  => true,
				6  => true,
				11 => true,
			),
			$rows
		);
		self::assertArrayNotHasKey( 12, $rows );
		// backup_runs(): the runs named true, none named false.
		$kept = AB_MCP_Sync_Schema::backup_runs( $now );
		ksort( $kept );
		self::assertSame( array( 2, 3, 4, 5, 6, 11 ), array_keys( $kept ) );
		self::assertSame( array( true ), array_values( array_unique( $kept ) ) );
		// Once the way back closed with its period reached, run 5 is named
		// false too (its own period is reached): a row of this kind goes.
		$db->rows[5]['state']      = 'closed_reverted';
		$db->rows[5]['closed']     = $now - 8 * $day;
		$db->rows[5]['keep_until'] = $now - 1;
		$rows                      = AB_MCP_Sync_Schema::backup_rows( $now );
		self::assertFalse( $rows[5] );
		self::assertFalse( $rows[6] );
		self::assertArrayNotHasKey( 11, $rows );
		// The table unreadable: null for both.
		$db->last_error = 'gone';
		self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ) );
		self::assertNull( AB_MCP_Sync_Schema::backup_runs( $now ) );
	}

	public function testARunLeavesRoomForItsOwnWayBack(): void {
		// A plan has at most MAX_STEPS steps (the entries a tool call takes):
		// the objects and the frame of the run (check, caches, check of the
		// run, one spare), so a plan of 1996 objects goes as before B5 (N4).
		self::assertSame( 2000, AB_MCP_Sync_Run::MAX_STEPS );
		self::assertSame( 4, AB_MCP_Sync_Run::FRAME );
		self::assertSame( 1996, AB_MCP_Sync_Push::MAX_OBJECTS );
		self::assertLessThanOrEqual( AB_MCP_Sync_Run::MAX_STEPS, AB_MCP_Sync_Run::FRAME + AB_MCP_Sync_Push::MAX_OBJECTS );
		// Every written object takes one step of the way back in the same run
		// (decision 15): the run holds the forward plan and as many steps
		// again as it has objects (B5), never less.
		self::assertSame( 3996, AB_MCP_Sync_Run::MAX_RUN_STEPS );
		self::assertLessThanOrEqual( AB_MCP_Sync_Run::MAX_RUN_STEPS, AB_MCP_Sync_Run::FRAME + 2 * AB_MCP_Sync_Push::MAX_OBJECTS );
		self::assertTrue( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::FRAME + AB_MCP_Sync_Push::MAX_OBJECTS, AB_MCP_Sync_Push::MAX_OBJECTS ), 'A full run takes its whole way back.' );
		self::assertTrue( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::MAX_STEPS, AB_MCP_Sync_Push::MAX_OBJECTS ), 'Even with the spare step of the frame used.' );
		self::assertFalse( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::MAX_RUN_STEPS, 1 ) );
		self::assertTrue( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::MAX_RUN_STEPS - 1, 1 ) );
	}

	public function testASelectionPastTheObjectsOfOnePlanIsRefusedWithTheWayInParts(): void {
		// A plan writes at most MAX_OBJECTS objects; a larger selection is
		// refused with the way in parts (N4): select instead of all=true, the
		// run to its close, the next entries from the same report.
		self::assertNull( AB_MCP_Sync_Push::too_many( AB_MCP_Sync_Push::MAX_OBJECTS ) );
		self::assertNull( AB_MCP_Sync_Push::too_many( 1 ) );
		$refused = AB_MCP_Sync_Push::too_many( AB_MCP_Sync_Push::MAX_OBJECTS + 1 );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_push_selection', $refused->get_error_code() );
		self::assertStringContainsString( 'at most 1996 objects; this selection has 1997', $refused->get_error_message() );
		self::assertStringContainsString( 'Plan in parts: pass select with at most 1996 entry numbers', $refused->get_error_message() );
		self::assertStringContainsString( 'same report_id with the same packages', $refused->get_error_message() );
	}

	/* ------------------------------------------------------- the plan */

	private static function verdict( array $entry, ?string $choice = null, bool $draft = false, bool $all = false ) {
		$m = new ReflectionMethod( AB_MCP_Sync_Push::class, 'verdict' );
		return $m->invoke( null, $entry, $choice, $draft, $all );
	}

	private static function entry( array $over = array() ): array {
		return array_merge(
			array(
				'n'       => 1,
				'kind'    => 'post',
				'subtype' => 'page',
				'result'  => 'changed_on_staging',
				'action'  => 'take_over',
				'staging' => array( 'ref' => '12' ),
				'live'    => array( 'ref' => '34' ),
				'pair'    => array( 'id' => 5 ),
			),
			$over
		);
	}

	public function testWhichEntryOfAReportIsAStepLockedOrNothing(): void {
		$update = self::verdict( self::entry() );
		self::assertSame( array( 'n' => 1, 'kind' => 'post', 'subtype' => 'page', 'op' => 'update', 'staging_ref' => '12', 'live_ref' => '34', 'pair_id' => 5, 'as_draft' => false, 'choice' => null, 'action' => 'take_over' ), array_intersect_key( $update['item'], array_flip( array( 'n', 'kind', 'subtype', 'op', 'staging_ref', 'live_ref', 'pair_id', 'as_draft', 'choice', 'action' ) ) ) );
		$create = self::verdict( self::entry( array( 'action' => 'create', 'result' => 'only_staging', 'live' => null, 'pair' => null ) ), null, true );
		self::assertSame( array( 'create', null, null, true ), array( $create['item']['op'], $create['item']['live_ref'], $create['item']['pair_id'], $create['item']['as_draft'] ) );
		// A conflict: the choice of the person.
		$conflict = self::entry( array( 'action' => 'choose', 'result' => 'conflict' ) );
		self::assertSame( 'update', self::verdict( $conflict, 'staging_wins' )['item']['op'] );
		self::assertSame( 'staging_wins', self::verdict( $conflict, 'staging_wins' )['item']['choice'] );
		self::assertNull( self::verdict( $conflict, 'keep_live' ) );
		self::assertNull( self::verdict( $conflict, 'leave_open' ) );
		self::assertStringContainsString( 'name the choice', self::verdict( $conflict )['locked']['reason'] );
		self::assertNull( self::verdict( $conflict, null, false, true ), 'all leaves a conflict open.' );
		self::assertStringContainsString( 'unclear pair', self::verdict( self::entry( array( 'action' => 'choose', 'result' => 'unclear' ) ), 'staging_wins' )['locked']['reason'] );
		self::assertStringContainsString( 'no conflict', self::verdict( self::entry(), 'staging_wins' )['locked']['reason'] );
		// Locked or waiting in the report: locked with its reason and stage.
		$locked = self::verdict( self::entry( array( 'action' => 'locked', 'locks' => array( array( 'key' => '*:delete', 'stage' => 'Z8d', 'reason' => 'No deletion.' ) ) ) ) );
		self::assertSame( array( 'No deletion.', 'Z8d' ), array( $locked['locked']['reason'], $locked['locked']['stage'] ) );
		$waits = self::verdict( self::entry( array( 'action' => 'waits', 'waits_for' => array( array( 'why' => 'It needs an attachment.' ) ) ) ) );
		self::assertStringContainsString( 'It needs an attachment.', $waits['locked']['reason'] );
		// What a takeover does not write: keep, none, pair; with all nothing, chosen locked.
		foreach ( array( 'keep', 'none', 'pair' ) as $action ) {
			self::assertNull( self::verdict( self::entry( array( 'action' => $action ) ), null, false, true ), $action );
			self::assertStringContainsString( "says $action", self::verdict( self::entry( array( 'action' => $action ) ) )['locked']['reason'] );
		}
		// Other kinds and types: locked with the stage that brings them; a
		// term of a taxonomy the term engine writes is an item of its kind
		// (branch 9), one of another taxonomy waits for its classification.
		foreach ( array( 'term' => 'Z8d', 'attachment' => 'Z10', 'menu' => 'Z11', 'option' => 'Z11' ) as $kind => $stage ) {
			self::assertSame( $stage, self::verdict( self::entry( array( 'kind' => $kind, 'subtype' => '' ) ) )['locked']['stage'], $kind );
		}
		$term = self::verdict( self::entry( array( 'kind' => 'term', 'subtype' => 'category' ) ) );
		self::assertSame( array( 'term', 'category', 'update' ), array( $term['item']['kind'], $term['item']['subtype'], $term['item']['op'] ) );
		self::assertSame( 'Z8d', self::verdict( self::entry( array( 'kind' => 'term', 'subtype' => 'genre' ) ) )['locked']['stage'] );
		self::assertSame( 'Z8d', self::verdict( self::entry( array( 'subtype' => 'product' ) ) )['locked']['stage'] );
		self::assertStringContainsString( 'held by a credential', self::verdict( self::entry( array( 'staging' => array( 'held' => 'credential_name' ) ) ) )['locked']['reason'] );
		self::assertStringContainsString( 'no pair', self::verdict( self::entry( array( 'pair' => null ) ) )['locked']['reason'] );
	}

	public function testTheOrderOfTheGraphPutsATargetBeforeWhatRefersToItAndLocksACycle(): void {
		$m     = new ReflectionMethod( AB_MCP_Sync_Push::class, 'order' );
		$items = array(
			3 => array( 'kind' => 'post', 'subtype' => 'page', 'op' => 'create', 'staging_ref' => '30', 'action' => 'create', 'refs' => array( array( 'post', '20' ) ) ),
			2 => array( 'kind' => 'post', 'subtype' => 'page', 'op' => 'create', 'staging_ref' => '20', 'action' => 'create', 'refs' => array() ),
			1 => array( 'kind' => 'post', 'subtype' => 'page', 'op' => 'update', 'staging_ref' => '10', 'action' => 'take_over', 'refs' => array( array( 'post', '20' ), array( 'term', '5' ) ) ),
		);
		$out   = $m->invoke( null, $items );
		self::assertSame( array( 2, 1, 3 ), array_keys( $out['items'] ), 'The target created first, then the smallest entry that is ready; a reference to a term or a pair is no edge.' );
		self::assertSame( array( 2 ), $out['items'][3]['depends_on'] );
		self::assertSame( array( 2 ), $out['items'][1]['depends_on'] );
		self::assertSame( array(), $out['locked'] );
		// A cycle: locked as mutual references, and what depends on a member with it.
		$items[2]['refs'] = array( array( 'post', '30' ) );
		$out              = $m->invoke( null, $items );
		self::assertSame( array(), $out['items'], 'The update refers to a locked member, so it is held back as well (decision 16).' );
		self::assertCount( 3, $out['locked'] );
		self::assertSame( array( 2, 3 ), array_slice( array_column( $out['locked'], 'n' ), 0, 2 ) );
		self::assertSame( 'Z11c', $out['locked'][0]['stage'] );
		self::assertStringContainsString( 'refers to entry', $out['locked'][2]['reason'] );
		// An object referring to itself is a cycle too.
		$self = array( 4 => array( 'kind' => 'post', 'subtype' => 'page', 'op' => 'create', 'staging_ref' => '40', 'action' => 'create', 'refs' => array( array( 'post', '40' ) ) ) );
		$out  = $m->invoke( null, $self );
		self::assertSame( array(), $out['items'] );
		self::assertSame( 4, $out['locked'][0]['n'] );
	}

	public function testTheLineOfAnItemIsCheckedAgainstTheReport(): void {
		$lines = array(
			'lines' => array( "post\x0012" => array( 'kind' => 'post', 'ref' => '12', 'hash' => str_repeat( 'a', 64 ) ), "post\x0013" => array( 'kind' => 'post', 'ref' => '13', 'hash' => str_repeat( 'a', 64 ) ), "post\x0014" => array( 'kind' => 'post', 'ref' => '14' ) ),
			'held'  => array( "post\x00" . hash( 'sha256', AB_MCP_Sync_Canon::encode( '13' ) ) => 'credential_field' ),
		);
		$item  = array( 'kind' => 'post', 'staging_ref' => '12', 'staging_hash' => str_repeat( 'a', 64 ) );
		self::assertSame( '12', AB_MCP_Sync_Engine::line_of( $item, $lines )['ref'] );
		self::assertSame( 'ab_mcp_sync_objects_changed', AB_MCP_Sync_Engine::line_of( array( 'staging_hash' => str_repeat( 'b', 64 ) ) + $item, $lines )->get_error_code(), 'Another hash than the report compared.' );
		self::assertSame( 'ab_mcp_sync_objects_missing', AB_MCP_Sync_Engine::line_of( array( 'staging_ref' => '99' ) + $item, $lines )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_objects_held', AB_MCP_Sync_Engine::line_of( array( 'staging_ref' => '13' ) + $item, $lines )->get_error_code(), 'Held by the rules of this site, although its hash is the one of the report.' );
		self::assertSame( 'ab_mcp_sync_objects_held', AB_MCP_Sync_Engine::line_of( array( 'staging_ref' => '14' ) + $item, $lines )->get_error_code(), 'Held by the staging site: no hash in its line.' );
	}

	/* ----------------------------------------- the kind post, branch 9 */

	public function testATypeTheTableLocksAndWhatTheCanonicalFormLocksAreRefusedWithTheirOwnLock(): void {
		$ctx = self::ctx();
		// A changeset (decision 30): its own lock of the table, with its stage.
		$changeset = AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'customize_changeset' ), array(), array(), array( 'identity' => array( 'post_type' => 'customize_changeset', 'post_name' => 'c', 'theme' => '' ) ) ), self::item(), $ctx );
		self::assertSame( 'post_type:customize_changeset', self::lock_of( $changeset ) );
		self::assertStringContainsString( 'from Z8d', $changeset->get_error_message() );
		self::assertSame( 'post_type:*', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array( 'post_type' => 'product' ), array(), array(), array( 'identity' => array( 'post_type' => 'product', 'post_name' => 'p', 'theme' => '' ) ) ), self::item(), $ctx ) ), 'A type of a plugin.' );
		// A page builder that is not released (decision 31), as the canonical form of the staging object found it.
		$builder = self::line( array(), array(), array(), array( 'locks' => array( array( 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => 'A document of a page builder.', 'at' => 'builder:wpbakery' ) ) ) );
		$locked  = AB_MCP_Sync_Post_Writer::target_of( $builder, self::item(), $ctx );
		self::assertSame( 'post:builder', self::lock_of( $locked ) );
		self::assertStringContainsString( 'from Z8c', $locked->get_error_message() );
		self::assertSame( 'post:engine_type', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array(), array( 'locks' => array( 'x' ) ) ), self::item(), $ctx ) ), 'A lock this version does not read: fail closed.' );
		// The same on the side of this site (live_rule()): the canonical form of the post here.
		$db                          = self::post_rows( 'News' );
		$db->rows[12]['post_content'] = '[vc_row][vc_column]Hi[/vc_column][/vc_row]';
		$GLOBALS['wpdb']             = $db;
		$source                      = \AB_MCP_Sync_Inventory::source( array( 'https://www.example.com' ), 50 );
		self::assertSame( 'post:builder', self::lock_of( AB_MCP_Sync_Post_Writer::live_rule( 12, $source ) ) );
		$db->rows[12]['post_content'] = 'Hello.';
		self::assertTrue( AB_MCP_Sync_Post_Writer::live_rule( 12, $source ) );
	}

	public function testTheFieldRuleAsksEveryOperationOfAKeyThatDiffersAndAPageBuilderKeyNeedsUnfilteredHtml(): void {
		$db              = self::post_rows( 'News' );
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx( array( 'term' => array( 3 => 3 ) ) );
		$ctx['now']      = 1800000000;
		$terms           = array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ) );
		$line            = static fn( array $meta ) => self::line( array( 'post_type' => 'post' ), $meta, $terms, array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) );
		$denied          = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap . ':' . (string) ( $args[1] ?? '' ), $denied, true ) && ! in_array( $cap, $denied, true );
		};
		// A key new on the target asks add_post_meta.
		$added  = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'color', 'red' ) ) ), self::item(), $ctx );
		$denied = array( 'add_post_meta:color' );
		self::assertSame( '*:meta_without_rule', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $added, 12, $ctx['now'] ) ) );
		$denied = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $added, 12, $ctx['now'] ) );
		// A key of a page builder: the right to store unfiltered HTML besides.
		$builder = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'panels_data', 'a:1:{i:0;s:1:"x";}' ) ) ), self::item(), $ctx );
		self::assertIsArray( $builder, is_wp_error( $builder ) ? $builder->get_error_message() : '' );
		$denied  = array( 'unfiltered_html' );
		$refused = AB_MCP_Sync_Post_Writer::precheck( $builder, 12, $ctx['now'] );
		self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		self::assertStringContainsString( 'page-builder meta key panels_data (unfiltered_html)', $refused->get_error_message() );
		$denied = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $builder, 12, $ctx['now'] ) );
		// Every written value through sanitize_meta() (decision 17).
		$upper = static fn( $v ) => strtoupper( (string) $v );
		add_filter( 'sanitize_post_meta_color', $upper );
		self::assertSame( 'post:sanitizer', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $added, 12, $ctx['now'] ) ) );
		remove_filter( 'sanitize_post_meta_color', $upper );
		// A field of the row through sanitize_post(), beyond the texts.
		$closed = static fn( $v ) => 'open';
		add_filter( 'pre_comment_status', $closed );
		self::assertSame( 'post:sanitizer', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $added, 12, $ctx['now'] ) ) );
		remove_filter( 'pre_comment_status', $closed );
		// The template the row writes is asked for other spellings as well, equal or not.
		$db->meta[12] = array( array( 'meta_id' => 1, 'meta_key' => 'note', 'meta_value' => 'abc' ), array( 'meta_id' => 2, 'meta_key' => '_wp_page_template', 'meta_value' => 'full.php' ), array( 'meta_id' => 3, 'meta_key' => '_WP_page_template', 'meta_value' => 'x' ) );
		$GLOBALS['ab_test_page_templates'] = array( 'post' => array( 'full.php' => 'Full' ) );
		$template = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( '_wp_page_template', 'full.php' ), array( '_WP_page_template', 'x' ) ) ), self::item(), $ctx );
		self::assertSame( 'post:meta_spelling', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $template, 12, $ctx['now'] ) ) );
		unset( $GLOBALS['ab_test_page_templates'] );
	}

	public function testTheRightsOfAKeyOfSeveralRowsAreTheOperationsTheWriteMakes(): void {
		// Codex round 1 on branch 9, A2: a key of several rows is removed
		// whole and added anew (write_meta()). The rights were asked by the
		// net count of the rows: two rows to two other rows asked edit alone,
		// and a rule that gave edit but refused add and delete let two other
		// values through. Now the rights are the operations the write makes
		// (meta_ops()): delete and add there, edit where one row stays one.
		$db           = self::post_rows( 'News' );
		$db->meta[12] = array(
			array( 'meta_id' => 1, 'meta_key' => 'note', 'meta_value' => 'abc' ),
			array( 'meta_id' => 2, 'meta_key' => 'colors', 'meta_value' => 'red' ),
			array( 'meta_id' => 3, 'meta_key' => 'colors', 'meta_value' => 'green' ),
		);
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx();
		$ctx['now']      = 1800000000;
		$line            = static fn( array $meta ) => self::line( array( 'post_type' => 'post' ), $meta, array(), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) );
		$denied          = array( 'add_post_meta:colors', 'delete_post_meta:colors' );
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap . ':' . (string) ( $args[1] ?? '' ), $denied, true );
		};
		$two    = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'colors', 'blue' ), array( 'colors', 'yellow' ) ) ), self::item(), $ctx );
		$locked = AB_MCP_Sync_Post_Writer::precheck( $two, 12, $ctx['now'] );
		self::assertSame( '*:meta_without_rule', self::lock_of( $locked ), 'edit_post_meta alone does not cover a key removed whole and added anew.' );
		self::assertStringContainsString( 'Meta key colors of the post on this site: delete_post_meta refused', $locked->get_error_message() );
		// At the call as well: refused, nothing written.
		$GLOBALS['ab_test_meta'][12]['colors'] = array( 'red', 'green' );
		$refused                               = AB_MCP_Sync_Post_Writer::write_part( 12, 'meta:colors', $two, AB_MCP_Sync_Post_Writer::current( 12 ) );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		self::assertSame( array( 'red', 'green' ), $GLOBALS['ab_test_meta'][12]['colors'], 'Nothing written.' );
		$denied = array( 'delete_post_meta:colors' );
		self::assertStringContainsString( 'delete_post_meta refused', AB_MCP_Sync_Post_Writer::precheck( $two, 12, $ctx['now'] )->get_error_message(), 'Each operation on its own.' );
		$denied = array( 'add_post_meta:colors' );
		self::assertStringContainsString( 'add_post_meta refused', AB_MCP_Sync_Post_Writer::precheck( $two, 12, $ctx['now'] )->get_error_message() );
		// Two rows to one: removed and added, never edited in place.
		$one    = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'colors', 'blue' ) ) ), self::item(), $ctx );
		self::assertStringContainsString( 'add_post_meta refused', AB_MCP_Sync_Post_Writer::precheck( $one, 12, $ctx['now'] )->get_error_message() );
		// One row to one row: update_metadata(), edit alone.
		$denied = array( 'add_post_meta:note', 'delete_post_meta:note' );
		$note   = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'xyz' ), array( 'colors', 'red' ), array( 'colors', 'green' ) ) ), self::item(), $ctx );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $note, 12, $ctx['now'] ) );
		$denied = array( 'edit_post_meta:note' );
		self::assertStringContainsString( 'edit_post_meta refused', AB_MCP_Sync_Post_Writer::precheck( $note, 12, $ctx['now'] )->get_error_message() );
		// With every right: written.
		$denied = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $two, 12, $ctx['now'] ) );
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_part( 12, 'meta:colors', $two, AB_MCP_Sync_Post_Writer::current( 12 ) ) );
		self::assertSame( array( 'blue', 'yellow' ), $GLOBALS['ab_test_meta'][12]['colors'] );
		self::assertSame( array( 'delete', 'add' ), AB_MCP_Sync_Post_Writer::meta_ops( array( 'b', 'y' ), array( 'r', 'g' ) ) );
		self::assertSame( array( 'edit' ), AB_MCP_Sync_Post_Writer::meta_ops( array( 'b' ), array( 'r' ) ) );
		self::assertSame( array( 'add' ), AB_MCP_Sync_Post_Writer::meta_ops( array( 'b' ), array() ) );
		self::assertSame( array( 'delete' ), AB_MCP_Sync_Post_Writer::meta_ops( array(), array( 'r' ) ) );
	}

	public function testASanitizerIsComparedByTheBytesWordPressWouldStore(): void {
		// Codex round 1 on branch 9, B3: a plain key rank with the sanitizer
		// absint(): the database holds '5', the sanitizer gives the integer
		// 5, and WordPress stores '5' again. Compared with !== on the PHP
		// value, the post was locked (post:sanitizer); now by the bytes
		// WordPress would store (maybe_serialize(), a scalar as its text).
		$db              = self::post_rows( 'News' );
		$GLOBALS['wpdb'] = $db;
		$ctx             = self::ctx();
		$ctx['now']      = 1800000000;
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		$line            = static fn( array $meta ) => self::line( array( 'post_type' => 'post' ), $meta, array(), array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) );
		add_filter( 'sanitize_post_meta_rank', 'absint' );
		$five = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'rank', '5' ) ) ), self::item(), $ctx );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $five, 12, $ctx['now'] ), 'absint() gives 5, stored as 5: nothing changes.' );
		$padded = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'rank', '05' ) ) ), self::item(), $ctx );
		self::assertSame( 'post:sanitizer', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $padded, 12, $ctx['now'] ) ), 'WordPress would store 5 for 05.' );
		remove_filter( 'sanitize_post_meta_rank', 'absint' );
		$yes = static fn( $v ) => '1' === $v;
		add_filter( 'sanitize_post_meta_rank', $yes );
		$one = AB_MCP_Sync_Post_Writer::target_of( $line( array( array( 'note', 'abc' ), array( 'rank', '1' ) ) ), self::item(), $ctx );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $one, 12, $ctx['now'] ), 'true is stored as 1.' );
		remove_filter( 'sanitize_post_meta_rank', $yes );
		self::assertSame( '5', AB_MCP_Sync_Post_Writer::db_form( 5 ) );
		self::assertSame( '', AB_MCP_Sync_Post_Writer::db_form( false ) );
		self::assertSame( 'a:1:{i:0;s:1:"x";}', AB_MCP_Sync_Post_Writer::db_form( array( 'x' ) ) );
		self::assertNull( AB_MCP_Sync_Post_Writer::db_form( null ), 'NULL equals no target.' );
	}

	public function testTheFeaturedImageGoesThroughItsOwnApiToAnAttachmentWordPressShowsAsAnImage(): void {
		$db               = self::post_rows( 'News' );
		$db->rows[70]     = array( 'ID' => 70, 'post_type' => 'attachment' ) + $db->rows[12];
		$db->rows[71]     = array( 'ID' => 71, 'post_type' => 'page' ) + $db->rows[12];
		$GLOBALS['wpdb']  = $db;
		$ctx              = self::ctx( array( 'term' => array( 3 => 3 ), 'post' => array( 7 => 70, 8 => 71 ) ) );
		$ctx['now']       = 1800000000;
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		$terms            = array( array( 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'term_id' => 3, 'term_order' => 0 ) );
		$line             = static fn( string $image ) => self::line( array( 'post_type' => 'post' ), array( array( 'note', 'abc' ), array( '_thumbnail_id', $image ) ), $terms, array( 'identity' => array( 'post_type' => 'post', 'post_name' => 'hello', 'theme' => '' ) ) );
		$prepared         = AB_MCP_Sync_Post_Writer::target_of( $line( '7' ), self::item(), $ctx );
		self::assertSame( array( '70' ), $prepared['meta']['_thumbnail_id'] );
		self::assertSame( 'post:thumbnail', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) ), 'An attachment WordPress shows as no image: set_post_thumbnail() would remove the featured image.' );
		$GLOBALS['ab_test_images'] = array( 70, 71 );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $prepared, 12, $ctx['now'] ) );
		self::assertSame( 'post:thumbnail', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( AB_MCP_Sync_Post_Writer::target_of( $line( '8' ), self::item(), $ctx ), 12, $ctx['now'] ) ), 'No attachment.' );
		// Written through set_post_thumbnail(), removed through delete_post_thumbnail().
		$GLOBALS['ab_test_meta'][12]['_thumbnail_id'] = array();
		self::assertTrue( AB_MCP_Sync_Post_Writer::write_part( 12, 'meta:_thumbnail_id', $prepared, null ) );
		self::assertSame( array( 70 ), $GLOBALS['ab_test_meta'][12]['_thumbnail_id'] ?? null, 'set_post_thumbnail() of the stubs stores the id.' );
		self::assertSame( array( array( 'set', 12, 70 ) ), $GLOBALS['ab_test_thumbnails'], 'Through the core\'s own API.' );
		unset( $GLOBALS['ab_test_images'], $GLOBALS['ab_test_thumbnails'] );
	}

	public function testTheSlugIsWhatWordPressWouldStoreOrTheStepIsLocked(): void {
		$GLOBALS['wpdb'] = new SyncRunOptionWpdb();
		$ctx             = self::ctx();
		$ctx['now']      = 1800000000;
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		$new             = static fn( array $over ) => AB_MCP_Sync_Post_Writer::target_of( self::line( $over ), self::item( 'create' ), $ctx );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $new( array() ), null, $ctx['now'] ), 'A free slug.' );
		// Another post holds it: wp_unique_post_slug() would rename it.
		$GLOBALS['ab_test_unique_slugs'] = array( 'hello' => 'hello-2' );
		$taken = AB_MCP_Sync_Post_Writer::precheck( $new( array() ), null, $ctx['now'] );
		self::assertSame( 'post:slug_collision', self::lock_of( $taken ) );
		self::assertStringContainsString( 'WordPress would store the slug hello-2 for hello', $taken->get_error_message() );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) ), null, $ctx['now'] ), 'WordPress keeps the slug of a draft as it is.' );
		unset( $GLOBALS['ab_test_unique_slugs'] );
		// A post in the trash with the slug: wp_insert_post() renames it
		// (__trashed) for a draft as well (Codex round 1 on branch 9, C).
		$GLOBALS['wpdb']->trashed = 77;
		$draft                    = AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) ), null, $ctx['now'] );
		self::assertSame( 'post:slug_collision', self::lock_of( $draft ), 'A draft renames the post in the trash as well.' );
		self::assertStringContainsString( 'The post 77 of this site lies in the trash with the slug hello', $draft->get_error_message() );
		self::assertSame( 'post:slug_collision', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $new( array() ), null, $ctx['now'] ) ) );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_name' => '', 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) ), null, $ctx['now'] ), 'A draft without a slug renames nothing.' );
		$GLOBALS['wpdb']->trashed = null;
		// A slug the sanitizing would change.
		$spaced = AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_name' => 'Hello World' ) ), null, $ctx['now'] );
		self::assertSame( 'post:sanitizer', self::lock_of( $spaced ) );
		self::assertStringContainsString( 'WordPress would store hello-world', $spaced->get_error_message() );
		// A published post without a slug gets one from its title; a draft keeps none.
		self::assertSame( 'post:slug_collision', self::lock_of( AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_name' => '' ) ), null, $ctx['now'] ) ) );
		self::assertTrue( AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_name' => '', 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) ), null, $ctx['now'] ) );
		// A pending post of an account that may not publish it loses its slug.
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'publish_posts' !== $cap;
		$pending = AB_MCP_Sync_Post_Writer::precheck( $new( array( 'post_status' => 'pending', 'post_date_gmt' => '0000-00-00 00:00:00' ) ), null, $ctx['now'] );
		self::assertSame( 'post:slug_collision', self::lock_of( $pending ) );
		self::assertStringContainsString( 'WordPress drops the slug hello of a pending post', $pending->get_error_message() );
	}

	public function testTheEngineKnowsWhatItCreatesInBothSpacesAndNoStepWritesAKindWithoutAWriter(): void {
		$pending = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'pending' );
		self::assertSame(
			array(
				'post' => array( 12 => true ),
				'term' => array( 5 => true ),
			),
			$pending->invoke(
				null,
				array(
					array( 'op' => 'create', 'kind' => 'post', 'staging_ref' => '12' ),
					array( 'op' => 'create', 'kind' => 'term', 'staging_ref' => '5' ),
					array( 'op' => 'update', 'kind' => 'term', 'staging_ref' => '6' ),
					array( 'op' => 'create', 'kind' => 'menu', 'staging_ref' => '7' ),
				)
			),
			'A new post and a new term are pending for the check of what refers to them (B1; branch 9).'
		);
		$check   = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'check_item' );
		$finding = $check->invoke( null, array( 'seq' => 2, 'kind' => 'menu', 'staging_ref' => '7', 'op' => 'create' ), array( 'lines' => array(), 'held' => array() ), self::ctx() );
		self::assertStringContainsString( 'No step of this version writes the kind menu', $finding['message'] );
	}

	public function testTheOrderOfTheGraphPutsANewTermBeforeThePostThatIsFiledUnderIt(): void {
		$m     = new ReflectionMethod( AB_MCP_Sync_Push::class, 'order' );
		$items = array(
			1 => array( 'kind' => 'post', 'subtype' => 'post', 'op' => 'create', 'staging_ref' => '5', 'action' => 'create', 'refs' => array( array( 'term', '5' ) ) ),
			2 => array( 'kind' => 'term', 'subtype' => 'category', 'op' => 'create', 'staging_ref' => '5', 'action' => 'create', 'refs' => array() ),
		);
		$got   = $m->invoke( null, $items );
		self::assertSame( array( 2, 1 ), array_keys( $got['items'] ), 'The term 5 first: a post 5 and a term 5 are two objects (two spaces of ids).' );
		self::assertSame( array( 2 ), $got['items'][1]['depends_on'] );
		self::assertSame( array(), $got['locked'] );
	}

	public function testATermTheRunMovesUnderAnotherTermItWritesComesAfterIt(): void {
		// Codex round 1 on branch 9, B4: two categories here swap their
		// places (Alpha 7 goes under Beta 8, Beta leaves Alpha). Written in
		// the order of the report, Alpha under Beta while Beta is still under
		// Alpha would close a loop, which wp_update_term() breaks by changing
		// another term; the parent the run writes comes first. An ordinary
		// move under a term the run does not write orders nothing.
		$m     = new ReflectionMethod( AB_MCP_Sync_Push::class, 'order' );
		$items = array(
			1 => array( 'kind' => 'term', 'subtype' => 'category', 'op' => 'update', 'staging_ref' => '7', 'action' => 'take_over', 'refs' => array( array( 'term', '8' ) ), 'parent_ref' => '8' ),
			2 => array( 'kind' => 'term', 'subtype' => 'category', 'op' => 'update', 'staging_ref' => '8', 'action' => 'take_over', 'refs' => array() ),
			3 => array( 'kind' => 'term', 'subtype' => 'category', 'op' => 'update', 'staging_ref' => '9', 'action' => 'take_over', 'refs' => array( array( 'term', '4' ) ), 'parent_ref' => '4' ),
		);
		$got = $m->invoke( null, $items );
		self::assertSame( array( 2, 1, 3 ), array_keys( $got['items'] ), 'Beta first, then Alpha under it.' );
		self::assertSame( array( 2 ), $got['items'][1]['depends_on'] );
		self::assertSame( array(), $got['items'][3]['depends_on'] );
		self::assertSame( array(), $got['locked'] );
	}
}
