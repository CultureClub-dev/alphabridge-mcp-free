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
		return $this->rows;
	}
}

/**
 * A $wpdb that answers the row of an option from $ab_test_options, as the
 * lock of a boundary reads its record afresh (AB_MCP_Sync_Rows::option():
 * query() and last_result); every other question gives no row.
 */
final class SyncRunOptionWpdb {
	public $prefix      = 'wp_';
	public $options     = 'wp_options';
	public $posts       = 'wp_posts';
	public $last_error  = '';
	public $last_result = array();
	/** @var int Reads of the record. */
	public $record_reads = 0;

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		return null;
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
		if ( preg_match( '/FROM wp_posts WHERE ID = (\d+)$/', (string) $sql, $m ) ) {
			++$this->post_reads;
			if ( isset( $this->rows[ (int) $m[1] ] ) ) {
				$row = $this->rows[ (int) $m[1] ];
				if ( $this->post_reads > 1 && null !== $this->edit_after_first ) {
					$row['post_title'] = $this->edit_after_first;
				}
				$rows[] = $row;
			}
		} elseif ( preg_match( '/FROM wp_postmeta WHERE post_id = (\d+) ORDER BY meta_id ASC$/', (string) $sql, $m ) ) {
			$rows = $this->meta[ (int) $m[1] ] ?? array();
		} elseif ( preg_match( '/FROM wp_term_relationships AS tr .* WHERE tr\.object_id = (\d+) ORDER BY/', (string) $sql, $m ) ) {
			$rows = $this->relations[ (int) $m[1] ] ?? array();
		} elseif ( preg_match( "/FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON tt\.term_id = t\.term_id WHERE t\.term_id = (\d+) AND tt\.taxonomy = BINARY '([^']*)'/", (string) $sql, $m ) ) {
			$rows = isset( $this->term_rows[ (int) $m[1] ] ) && $this->term_rows[ (int) $m[1] ]['taxonomy'] === stripslashes( $m[2] ) ? array( $this->term_rows[ (int) $m[1] ] ) : array();
		}
		$this->last_result = array_map( static fn( $r ) => (object) $r, $rows );
		return count( $rows );
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'], $GLOBALS['wpdb'] );
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Guard::$now       = null;
		AB_MCP_Sync_Resources::$probe = null;
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
		self::assertSame( array( 'run_id' => 7, 'seq' => 2, 'posts' => array( '5' => 'page' ), 'taxonomies' => array( 'category' ), 'options' => array( 'blogname' ), 'until' => 1000 + AB_MCP_Change_Lock::TIMEOUT, 'user_id' => 3 ), $record );
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

	/* ------------------------------------------------- the kind post */

	public function testTheRuleOfThisVersionForMetaKeys(): void {
		self::assertTrue( AB_MCP_Sync_Post_Writer::meta_allowed( '_wp_page_template' ), 'The core key with an API of its own.' );
		self::assertTrue( AB_MCP_Sync_Post_Writer::meta_allowed( 'note' ) );
		self::assertTrue( AB_MCP_Sync_Post_Writer::meta_allowed( 'subtitle' ) );
		self::assertFalse( AB_MCP_Sync_Post_Writer::meta_allowed( '_thumbnail_id' ), 'A protected key.' );
		self::assertFalse( AB_MCP_Sync_Post_Writer::meta_allowed( '_my_plugin' ) );
		self::assertFalse( AB_MCP_Sync_Post_Writer::meta_allowed( '' ) );
		self::assertFalse( AB_MCP_Sync_Post_Writer::meta_allowed( 'api_key' ), 'The name of a credential.' );
		self::assertFalse( AB_MCP_Sync_Post_Writer::meta_allowed( 'panels_data' ), 'The markup of a page builder.' );
		self::assertSame( array( 'post', 'page' ), AB_MCP_Sync_Post_Writer::TYPES );
		self::assertSame( array( 'category', 'post_tag', 'post_format' ), AB_MCP_Sync_Post_Writer::TAXONOMIES );
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
		self::assertSame( 'post:meta_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_thumbnail_id', '5' ) ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:meta_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'b:1;' ) ) ), self::item(), $ctx ) ), 'A serialized scalar is not written through the API as it is.' );
		self::assertSame( 'post:create_with_object_meta', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( 'note', 'abc' ) ) ), self::item( 'create' ), $ctx ) ), 'A new post with plain meta (decision 28).' );
		self::assertIsArray( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array( array( '_wp_page_template', 'full.php' ) ) ), self::item( 'create' ), $ctx ), 'The core key goes with a new post.' );
		self::assertSame( 'post:taxonomy_rule', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'product_cat', 'term_id' => 3, 'term_order' => 0 ) ) ), self::item(), $ctx ) ) );
		self::assertSame( 'post:reference_unmapped', self::lock_of( AB_MCP_Sync_Post_Writer::target_of( self::line( array(), array(), array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'post_tag', 'term_id' => 99, 'term_order' => 0 ) ) ), self::item(), $ctx ) ), 'A term without a pair.' );
		// A lock names its stage and the way.
		$locked = AB_MCP_Sync_Post_Writer::locked( 'post:slug_collision', 'slug hello' );
		self::assertSame( 'ab_mcp_sync_locked', $locked->get_error_code() );
		self::assertSame( array( 'key' => 'post:slug_collision', 'stage' => 'Z9', 'at' => 'slug hello' ), array_intersect_key( $locked->get_error_data()['lock'], array_flip( array( 'key', 'stage', 'at' ) ) ) );
		self::assertStringContainsString( 'Not written (post:slug_collision, from Z9)', $locked->get_error_message() );
		self::assertStringContainsString( 'wp-admin', $locked->get_error_message() );
		foreach ( array( 'engine_type', 'meta_rule', 'taxonomy_rule', 'slug_collision', 'sanitizer', 'menu_automatic', 'author_unknown', 'status_date', 'reference_unmapped', 'page_template' ) as $op ) {
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
		// qualified close none (the last three stay without an age), an
		// unqualified one seven days.
		$db->rows = array(
			array( 'run_id' => 1, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 40 * $day, 'forward_closed_at' => $now - 40 * $day, 'keep_until' => 0, 'plan' => null ),
			array( 'run_id' => 2, 'type' => 'push', 'state' => 'closed_findings', 'closed' => $now - 20 * $day, 'forward_closed_at' => $now - 20 * $day, 'keep_until' => 0, 'plan' => null ),
			array( 'run_id' => 3, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 3 * $day, 'forward_closed_at' => $now - 3 * $day, 'keep_until' => 0, 'plan' => null ),
			array( 'run_id' => 4, 'type' => 'push', 'state' => 'closed_done', 'closed' => $now - 2 * $day, 'forward_closed_at' => $now - 2 * $day, 'keep_until' => 0, 'plan' => null ),
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
		// A row of version 2 (no keep_until) that did not qualify: seven days
		// from its close.
		$db->rows[5]['keep_until'] = 0;
		self::assertArrayNotHasKey( 6, AB_MCP_Sync_Schema::backup_runs( $now ) );
		$db->rows[5]['closed'] = $now - 6 * $day;
		self::assertArrayHasKey( 6, AB_MCP_Sync_Schema::backup_runs( $now ) );
		// The period a close gets (decision 20, rules 3 and 5).
		self::assertSame( 0, AB_MCP_Sync_Schema::keep_until( $now, true, 0 ), 'Free, qualified: the last three, no age.' );
		self::assertSame( $now + 30 * $day, AB_MCP_Sync_Schema::keep_until( $now, true, 30 ), 'Pro, qualified: 30 days.' );
		self::assertSame( $now + 7 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 0 ), 'Free, unqualified: 7 days.' );
		self::assertSame( $now + 30 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 30 ), 'Pro, unqualified: 30 days.' );
		self::assertSame( $now + 7 * $day, AB_MCP_Sync_Schema::keep_until( $now, false, 3 ), 'Never less than the seven days of Free.' );
		// The table unreadable: null, so the store keeps everything.
		$db->last_error = 'gone';
		self::assertNull( AB_MCP_Sync_Schema::backup_runs( $now ) );
	}

	public function testARunLeavesRoomForItsOwnWayBack(): void {
		// Every written object takes one step of the way back in the same run
		// (decision 15), besides the four steps of the frame (B5).
		self::assertLessThanOrEqual( AB_MCP_Sync_Run::MAX_STEPS, 4 + 2 * AB_MCP_Sync_Push::MAX_OBJECTS );
		self::assertTrue( AB_MCP_Sync_Run::has_room( 4 + AB_MCP_Sync_Push::MAX_OBJECTS, AB_MCP_Sync_Push::MAX_OBJECTS ) );
		self::assertFalse( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::MAX_STEPS, 1 ) );
		self::assertTrue( AB_MCP_Sync_Run::has_room( AB_MCP_Sync_Run::MAX_STEPS - 1, 1 ) );
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
		// Other kinds and types: locked with the stage that brings them.
		foreach ( array( 'term' => 'Z9', 'attachment' => 'Z10', 'menu' => 'Z11', 'option' => 'Z11' ) as $kind => $stage ) {
			self::assertSame( $stage, self::verdict( self::entry( array( 'kind' => $kind, 'subtype' => '' ) ) )['locked']['stage'], $kind );
		}
		self::assertSame( 'Z9', self::verdict( self::entry( array( 'subtype' => 'wp_block' ) ) )['locked']['stage'] );
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
}
