<?php
/**
 * The three answers of the row readers (AB_MCP_Sync_Rows, Z8 decision 11),
 * as the undo readers give them: the rows; none; null when the question
 * could not be asked (no table, query() false, an error) — never "none"
 * for a question that did not run. Each reader asks query() and reads
 * last_result, and none asks get_post(), get_post_meta() or get_option().
 * What the SQL does is shown against MariaDB in tests/integration (a
 * stand-in that answers SQL proves nothing about it).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Rows;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-rows.php';

/**
 * A $wpdb that answers every query with the rows a test sets, or fails.
 */
final class SyncRowsFakeWpdb {
	public $prefix        = 'wp_';
	public $posts         = 'wp_posts';
	public $postmeta      = 'wp_postmeta';
	public $terms         = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta      = 'wp_termmeta';
	public $term_relationships = 'wp_term_relationships';
	public $options       = 'wp_options';
	public $users         = 'wp_users';
	public $last_error    = '';
	public $last_result   = array();
	/** @var array|false What query() answers with: rows, or false. */
	public $answer = array();
	/** @var string The error query() leaves. */
	public $error = '';
	/** @var string[] */
	public $queries = array();

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function query( $query ) {
		$this->queries[]  = $query;
		$this->last_error = $this->error;
		if ( false === $this->answer ) {
			$this->last_result = array();
			return false;
		}
		$this->last_result = array_map( static fn( $r ) => (object) $r, $this->answer );
		return count( $this->answer );
	}
}

final class SyncRowsTest extends TestCase {

	/** @var mixed */
	private $saved;

	protected function setUp(): void {
		$this->saved     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new SyncRowsFakeWpdb();
		ab_test_reset();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved;
	}

	private static function db(): SyncRowsFakeWpdb {
		return $GLOBALS['wpdb'];
	}

	/** Every reader, called. */
	private static function all(): array {
		return array(
			'post'         => AB_MCP_Sync_Rows::post( 5 ),
			'post_ids'     => AB_MCP_Sync_Rows::post_ids( null, 0, 10 ),
			'meta'         => AB_MCP_Sync_Rows::meta( 'post', 5 ),
			'object_terms' => AB_MCP_Sync_Rows::object_terms( 5 ),
			'term_objects' => AB_MCP_Sync_Rows::term_objects( 3, 'category' ),
			'term'         => AB_MCP_Sync_Rows::term( 3, 'category' ),
			'terms'        => AB_MCP_Sync_Rows::terms( null, 0, 10 ),
			'options'      => AB_MCP_Sync_Rows::options( 0, 10 ),
			'user_login'   => AB_MCP_Sync_Rows::user_login( 1 ),
			'taxonomies'   => AB_MCP_Sync_Rows::term_taxonomies( 3 ),
		);
	}

	public function testAQuestionThatDidNotRunIsNull(): void {
		self::db()->answer = false;
		foreach ( self::all() as $reader => $answer ) {
			self::assertNull( $answer, "$reader: query() false." );
		}
		self::db()->answer = array();
		self::db()->error  = 'Lost connection';
		foreach ( self::all() as $reader => $answer ) {
			self::assertNull( $answer, "$reader: an error." );
		}
		$GLOBALS['wpdb'] = null;
		foreach ( self::all() as $reader => $answer ) {
			self::assertNull( $answer, "$reader: no database." );
		}
	}

	public function testNoneIsFalseForOneRowAndEmptyForMany(): void {
		self::db()->answer = array();
		$all               = self::all();
		foreach ( array( 'post', 'term', 'user_login' ) as $one ) {
			self::assertFalse( $all[ $one ], $one );
		}
		foreach ( array( 'post_ids', 'meta', 'object_terms', 'term_objects', 'terms', 'options', 'taxonomies' ) as $many ) {
			self::assertSame( array(), $all[ $many ], $many );
		}
		self::assertSame( array(), AB_MCP_Sync_Rows::post_ids( array(), 0, 10 ), 'No type asked: no query.' );
	}

	public function testTheRowsComeBackAsStoredText(): void {
		self::db()->answer = array( array( 'meta_id' => '7', 'meta_key' => 'Mixed', 'meta_value' => null ), array( 'meta_id' => '8', 'meta_key' => 'mixed', 'meta_value' => 'a:0:{}' ) );
		self::assertSame(
			array(
				array( 'meta_id' => 7, 'key' => 'Mixed', 'value' => '' ),
				array( 'meta_id' => 8, 'key' => 'mixed', 'value' => 'a:0:{}' ),
			),
			AB_MCP_Sync_Rows::meta( 'post', 5 )
		);
		self::db()->answer = array( array( 'ID' => '5', 'post_title' => 'T' ) );
		$post              = AB_MCP_Sync_Rows::post( 5 );
		self::assertSame( AB_MCP_Sync_Rows::POST_COLUMNS, array_keys( $post ) );
		self::assertSame( 'T', $post['post_title'] );
		self::assertSame( '', $post['guid'], 'A column the row lacks is empty text.' );
		self::assertNull( AB_MCP_Sync_Rows::meta( 'user', 1 ), 'Only post and term meta.' );
	}

	public function testNoReaderAsksTheFilteredFunctions(): void {
		$code = (string) file_get_contents( dirname( __DIR__ ) . '/includes/sync/class-sync-rows.php' );
		$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $code );
		foreach ( array( 'get_post(', 'get_post_meta(', 'get_option(', 'get_term(', 'get_metadata(', 'get_userdata(' ) as $function ) {
			self::assertStringNotContainsString( $function, $code );
		}
		self::assertSame( 500, AB_MCP_Sync_Rows::MAX_PAGE );
	}
}
