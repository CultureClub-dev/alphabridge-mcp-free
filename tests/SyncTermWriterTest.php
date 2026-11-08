<?php
/**
 * The write side of the kind term (Z8 branch 9, AB_MCP_Sync_Term_Writer):
 * what a step writes for a term, from a line of a package of objects, and
 * what it refuses before anything is written; the parts of its journal;
 * what a part held before. The writes themselves, the rows and the way back
 * run against WordPress in the integration suite
 * (tests/integration/SyncContentTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Post_Writer;
use AB_MCP_Sync_Term_Writer;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-run-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-engine.php';
require_once __DIR__ . '/../includes/sync/class-sync-term-writer.php';

if ( ! class_exists( 'WP_REST_Terms_Controller', false ) ) {
	// The controller of terms the lock of a boundary recognizes (by its class).
	eval( 'class WP_REST_Terms_Controller {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stub class in the global namespace from a namespaced test.
}

/**
 * A $wpdb without rows: every question answers none (a slug no term
 * holds), as the precheck of a new term asks it.
 */
final class SyncTermEmptyWpdb {
	public $prefix        = 'wp_';
	public $terms         = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta      = 'wp_termmeta';
	public $options       = 'wp_options';
	public $last_error    = '';
	public $last_result   = array();
	/** @var int|null The term id the query for a slug answers. */
	public $holder = null;

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		return preg_match( '/^SELECT t\.term_id FROM wp_terms/', (string) $query ) ? $this->holder : null;
	}

	public function query( $sql ) {
		$this->last_result = array();
		return 0;
	}
}

/**
 * A $wpdb with the rows of some terms as AB_MCP_Sync_Rows and
 * AB_MCP_Undo_Meta read them (term, its taxonomy, its meta; the meta of a
 * key compared without regard to letter case, as the collation of termmeta
 * does), the highest term id, and the term that holds a slug.
 */
final class SyncTermWpdb {
	public $prefix        = 'wp_';
	public $terms         = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta      = 'wp_termmeta';
	public $options       = 'wp_options';
	public $last_error    = '';
	public $last_result   = array();
	/** @var array<int,array> Term rows by id (the columns of AB_MCP_Sync_Rows::TERM_COLUMNS). */
	public $term_rows = array();
	/** @var array<int,array[]> Meta rows by term id: meta_id, meta_key, meta_value. */
	public $meta = array();
	/** @var int|null The term id the query for a slug answers. */
	public $holder = null;
	/** @var int The highest term id. */
	public $highest = 50;

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		if ( preg_match( '/^SELECT t\.term_id FROM wp_terms/', (string) $query ) ) {
			return $this->holder;
		}
		if ( preg_match( '/^SELECT MAX\(term_id\) FROM wp_terms/', (string) $query ) ) {
			return (string) $this->highest;
		}
		return null;
	}

	public function query( $sql ) {
		$this->last_result = array();
		$sql               = (string) $sql;
		if ( preg_match( '/^SELECT taxonomy FROM wp_term_taxonomy WHERE term_id = (\d+)/', $sql, $m ) ) {
			if ( isset( $this->term_rows[ (int) $m[1] ] ) ) {
				$this->last_result = array( (object) array( 'taxonomy' => $this->term_rows[ (int) $m[1] ]['taxonomy'] ) );
			}
			return count( $this->last_result );
		}
		if ( preg_match( "/FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON tt\.term_id = t\.term_id WHERE t\.term_id = (\d+) AND tt\.taxonomy = BINARY '([^']*)'/", $sql, $m ) ) {
			$row = $this->term_rows[ (int) $m[1] ] ?? null;
			if ( is_array( $row ) && $row['taxonomy'] === $m[2] ) {
				$this->last_result = array( (object) $row );
			}
			return count( $this->last_result );
		}
		if ( preg_match( "/FROM wp_termmeta WHERE term_id = (\d+) AND meta_key = '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m ) ) {
			foreach ( $this->meta[ (int) $m[1] ] ?? array() as $row ) {
				if ( 0 === strcasecmp( $row['meta_key'], stripslashes( $m[2] ) ) ) {
					$this->last_result[] = (object) $row;
				}
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/FROM wp_termmeta WHERE term_id = (\d+) ORDER BY/', $sql, $m ) ) {
			foreach ( $this->meta[ (int) $m[1] ] ?? array() as $row ) {
				$this->last_result[] = (object) $row;
			}
			return count( $this->last_result );
		}
		return 0;
	}
}

/** A request of the REST API as the lock of a boundary reads it: its method and its id. */
final class SyncTermRestRequest {
	private $method;
	private $id;

	public function __construct( string $method, int $id ) {
		$this->method = $method;
		$this->id     = $id;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_param( $name ) {
		return 'id' === $name ? $this->id : null;
	}
}

final class SyncTermWriterTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['wpdb']                           = new SyncTermEmptyWpdb();
		$GLOBALS['ab_test_way']['taxonomies']      = array(
			'category' => (object) array(
				'name' => 'category',
				'cap'  => (object) array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'edit_posts',
				),
			),
		);
		$GLOBALS['ab_test_taxonomies'] = array( 'category', 'post_tag', 'post_format' );
		$GLOBALS['ab_test_can']        = static fn( string $cap, array $args ): bool => true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_way']['taxonomies'], $GLOBALS['ab_test_taxonomies'], $GLOBALS['ab_test_unique_slugs'], $GLOBALS['ab_test_loop'], $GLOBALS['ab_test_term_calls'], $GLOBALS['ab_test_term_answer'] );
		$GLOBALS['ab_test_can'] = null;
	}

	/** A line of a package of objects for a category, as the staging site writes it (Kind_Term::export()). */
	private static function line( array $row_over = array(), array $meta = array(), array $over = array() ): array {
		$row = array_merge(
			array(
				'name'        => 'News',
				'slug'        => 'news',
				'term_group'  => '0',
				'taxonomy'    => 'category',
				'description' => 'Fresh news.',
				'parent'      => '0',
			),
			$row_over
		);
		return array_merge(
			array(
				'kind'     => 'term',
				'ref'      => '12',
				'identity' => array( 'taxonomy' => $row['taxonomy'], 'slug' => $row['slug'] ),
				'status'   => 'z8',
				'locks'    => array(),
				'hash'     => str_repeat( 'a', 64 ),
				'refs'     => array(),
				'form'     => array(
					'canon' => AB_MCP_Sync_Canon::VERSION,
					'kind'  => 'term',
					'row'   => array( 'name' => $row['name'], 'slug' => $row['slug'] ),
					'meta'  => array(),
				),
				'content'  => array( 'row' => $row, 'meta' => $meta, 'terms' => array() ),
			),
			$over
		);
	}

	private static function ctx( array $map = array(), array $pending = array() ): array {
		return array(
			'map'       => $map + array( 'post' => array(), 'term' => array() ),
			'addresses' => array( 'https://staging.example.com' => 'https://www.example.com' ),
			'routes'    => null,
			'now'       => 1700000000,
			'users'     => array(),
			'pending'   => $pending + array( 'post' => array(), 'term' => array() ),
		);
	}

	private static function item( string $op = 'update' ): array {
		return array(
			'op'       => $op,
			'live_ref' => 'update' === $op ? '34' : null,
			'as_draft' => false,
		);
	}

	private static function lock_of( $result ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_sync_locked', $result->get_error_code(), $result->get_error_message() );
		return (string) $result->get_error_data()['lock']['key'];
	}

	public function testTheEngineWritesTermsWithTheirOwnWriter(): void {
		self::assertSame( AB_MCP_Sync_Term_Writer::class, AB_MCP_Sync_Engine::writer( 'term' ) );
		self::assertSame( AB_MCP_Sync_Post_Writer::class, AB_MCP_Sync_Engine::writer( 'post' ) );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'menu' ), 'No step writes a kind without a writer (branch 11).' );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'attachment' ) );
		self::assertSame( array( 'category', 'post_tag', 'wp_pattern_category' ), AB_MCP_Sync_Term_Writer::TAXONOMIES );
		foreach ( AB_MCP_Sync_Term_Writer::TAXONOMIES as $taxonomy ) {
			self::assertSame( 'term', AB_MCP_Sync_Kinds::taxonomy( $taxonomy )['kind'], 'The kind term owns ' . $taxonomy );
		}
		self::assertSame( AB_MCP_Sync_Post_Writer::VALUE_RULES, AB_MCP_Sync_Term_Writer::VALUE_RULES, 'One rule for the values before (AB_MCP_Sync_Run::cap_values()).' );
	}

	public function testTheTargetOfATermIsItsRowWithTheParentByItsPair(): void {
		$got = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '7', 'description' => 'See https://staging.example.com/news/.' ) ), self::item(), self::ctx( array( 'term' => array( 7 => 70 ) ) ) );
		self::assertIsArray( $got, is_wp_error( $got ) ? $got->get_error_message() : '' );
		self::assertSame( 'category', $got['type'] );
		self::assertSame( array( 'name' => 'News', 'slug' => 'news', 'description' => 'See https://www.example.com/news/.', 'parent' => 70 ), $got['args'], 'The parent by its pair, the address of this site.' );
		self::assertSame( array( 'name' => 'News', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => 'See https://www.example.com/news/.', 'parent' => '70' ), $got['columns'] );
		self::assertSame( array(), $got['meta'] );
		self::assertSame( array(), $got['terms'], 'A term carries no terms.' );
		self::assertSame( AB_MCP_Sync_Canon::hash( $got['target_form'] ), $got['target_hash'] );
		self::assertFalse( $got['pending'] );
		self::assertNull( $got['status'] );
		self::assertSame( 'News', AB_MCP_Sync_Term_Writer::title_of( $got ) );
		self::assertSame( AB_MCP_Sync_Term_Writer::row_hash( $got['columns'] ), AB_MCP_Sync_Term_Writer::row_hash( array_reverse( $got['columns'], true ) ), 'Stable over the order of the columns.' );
	}

	public function testWhatTheEngineOfTheTermDoesNotWriteIsRefusedWithItsLock(): void {
		$ctx = self::ctx( array( 'term' => array( 7 => 70 ) ) );
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'taxonomy' => 'genre' ) ), self::item(), $ctx ) ), 'A taxonomy of a plugin.' );
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'taxonomy' => 'nav_menu' ) ), self::item(), $ctx ) ), 'The menus are the kind menu.' );
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array(), array( 'content' => array() ) ), self::item(), $ctx ) ), 'No content in the package.' );
		$carries = self::line();
		$carries['content']['terms'] = array( array( 'name' => 'X', 'slug' => 'x', 'taxonomy' => 'post_tag', 'term_id' => 3, 'term_order' => 0 ) );
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( $carries, self::item(), $ctx ) ), 'A term carries no terms: a line that does is no line of a term.' );
		$other = self::line();
		$other['content']['row']['taxonomy'] = 'post_tag';
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( $other, self::item(), $ctx ) ), 'The row and the identity name another taxonomy.' );
		self::assertSame( 'term:term_group', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'term_group' => '3' ) ), self::item(), $ctx ) ), 'A group of aliases.' );
		self::assertSame( 'term:reference_unmapped', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '8' ) ), self::item(), $ctx ) ), 'A parent without a pair.' );
		self::assertSame( 'term:create_with_meta', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'color', 'red' ) ) ), self::item( 'create' ), $ctx ) ), 'A new term with meta (decision 28).' );
		self::assertSame( 'term:meta_rule', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'color', 'b:1;' ) ) ), self::item(), $ctx ) ), 'A serialized scalar.' );
		$locked = self::line( array(), array(), array( 'locks' => array( array( 'key' => '*:credential_field', 'stage' => 'Z8d', 'reason' => 'A credential.', 'at' => 'description' ) ) ) );
		self::assertSame( '*:credential_field', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( $locked, self::item(), $ctx ) ), 'What the canonical form locks.' );
		// A parent the same run creates first is pending: mapped once its step ran (Eltern zuerst).
		$pending = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '8' ) ), self::item( 'create' ), self::ctx( array(), array( 'term' => array( 8 => true ) ) ) );
		self::assertIsArray( $pending, is_wp_error( $pending ) ? $pending->get_error_message() : '' );
		self::assertTrue( $pending['pending'] );
		self::assertSame( 0, $pending['args']['parent'] );
		self::assertSame( 'term:reference_unmapped', self::lock_of( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '8' ) ), self::item( 'create' ), self::ctx( array(), array( 'term' => array( 9 => true ) ) ) ) ), 'Another pending term does not help.' );
		// The meta of a term here, as the metadata API stores it back.
		$meta = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'color', 'red' ), array( 'sizes', 'a:1:{i:0;s:1:"L";}' ) ) ), self::item(), $ctx );
		self::assertSame( array( 'color' => array( 'red' ), 'sizes' => array( array( 'L' ) ) ), $meta['meta'] );
		foreach ( array( 'taxonomy_rule', 'term_group', 'parent', 'slug_collision', 'sanitizer', 'reference_unmapped', 'create_with_meta', 'meta_rule', 'meta_spelling' ) as $op ) {
			self::assertIsArray( AB_MCP_Sync_Kinds::lock( 'term', $op ), $op . ' is a lock of the table.' );
		}
		self::assertNull( AB_MCP_Sync_Kinds::lock( 'term', 'engine' ), 'The engine of the terms is there (branch 9).' );
	}

	public function testANewTermAsksTheRightsOfItsTaxonomyItsSlugAndItsSanitizersBeforeAnythingIsWritten(): void {
		$prepared = AB_MCP_Sync_Term_Writer::target_of( self::line(), self::item( 'create' ), self::ctx() );
		self::assertIsArray( $prepared );
		self::assertTrue( AB_MCP_Sync_Term_Writer::precheck( $prepared, null, 0 ), 'With every right, a free slug and stable fields.' );
		// The rights of the taxonomy (decision 28): manage, edit and assign.
		foreach ( array( 'manage_categories', 'edit_posts' ) as $cap ) {
			$GLOBALS['ab_test_can'] = static fn( string $asked, array $args ): bool => $asked !== $cap;
			$refused                = AB_MCP_Sync_Term_Writer::precheck( $prepared, null, 0 );
			self::assertInstanceOf( WP_Error::class, $refused, $cap );
			self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
			self::assertStringContainsString( "make a term of category ($cap)", $refused->get_error_message() );
		}
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		// The slug: another term of the taxonomy holds it, or WordPress would store another.
		$GLOBALS['wpdb']->holder = 41;
		self::assertSame( 'term:slug_collision', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, null, 0 ) ) );
		$GLOBALS['wpdb']->holder         = null;
		$GLOBALS['ab_test_unique_slugs'] = array( 'news' => 'news-2' );
		$renamed                         = AB_MCP_Sync_Term_Writer::precheck( $prepared, null, 0 );
		self::assertSame( 'term:slug_collision', self::lock_of( $renamed ) );
		self::assertStringContainsString( 'WordPress would store the slug news-2 for news', $renamed->get_error_message() );
		unset( $GLOBALS['ab_test_unique_slugs'] );
		self::assertSame( 'term:sanitizer', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'slug' => '' ) ), self::item( 'create' ), self::ctx() ), null, 0 ) ), 'An empty slug: WordPress would make one.' );
		// The sanitizers ahead: a field the filters of the term would change.
		$strip = static fn( $v ) => strip_tags( (string) $v );
		add_filter( 'pre_term_description', $strip );
		$html = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'description' => 'Fresh <em>news</em>.' ) ), self::item( 'create' ), self::ctx() );
		$san  = AB_MCP_Sync_Term_Writer::precheck( $html, null, 0 );
		self::assertSame( 'term:sanitizer', self::lock_of( $san ) );
		self::assertStringContainsString( 'Field description', $san->get_error_message() );
		remove_filter( 'pre_term_description', $strip );
		self::assertSame( 'term:sanitizer', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'name' => ' ' ) ), self::item( 'create' ), self::ctx() ), null, 0 ) ), 'An empty name.' );
		// A taxonomy not registered here.
		unset( $GLOBALS['ab_test_way']['taxonomies']['category'] );
		self::assertSame( 'term:taxonomy_rule', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, null, 0 ) ) );
	}

	public function testThePartsOfATermAreItsRowAndTheKeysThatDiffer(): void {
		$prepared = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'color', 'blue' ), array( 'size', 'L' ), array( 'api_key', 'x' ) ) ), self::item(), self::ctx() );
		$current  = array(
			'row'   => array( 'name' => 'Old', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => '', 'parent' => '0' ),
			'meta'  => array(
				array( 'meta_id' => 1, 'key' => 'color', 'value' => 'red' ),
				array( 'meta_id' => 2, 'key' => 'size', 'value' => 'L' ),
				array( 'meta_id' => 3, 'key' => 'old', 'value' => 'o' ),
				array( 'meta_id' => 4, 'key' => 'api_key', 'value' => 'y' ),
			),
			'terms' => array(),
		);
		self::assertSame( array( 'color', 'old' ), AB_MCP_Sync_Term_Writer::differing( $prepared, $current ), 'A key equal on both sides is untouched, a credential never written.' );
		$parts = AB_MCP_Sync_Term_Writer::journal_parts( $prepared, $current );
		self::assertSame( array( 'row', 'meta:color', 'meta:old' ), array_column( $parts, 'part' ) );
		self::assertSame( array( 'not_refusable', 'refusable', 'refusable' ), array_column( $parts, 'way' ), 'The row of a term is on a way the lock does not refuse (decision 19).' );
		self::assertSame( AB_MCP_Sync_Term_Writer::row_hash( $current['row'] ), $parts[0]['before'] );
		self::assertSame( AB_MCP_Sync_Term_Writer::row_hash( $prepared['columns'] ), $parts[0]['target'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'red' ) ), $parts[1]['before'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'blue' ) ), $parts[1]['target'] );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array() ), $parts[2]['target'], 'A key only this site has is removed.' );
		self::assertSame( array( 'row' ), array_column( AB_MCP_Sync_Term_Writer::journal_parts( AB_MCP_Sync_Term_Writer::target_of( self::line(), self::item( 'create' ), self::ctx() ), null ), 'part' ), 'A new term: its row.' );
		self::assertSame( array(), AB_MCP_Sync_Term_Writer::with_row( $prepared, 34, $current ), 'The row call writes nothing besides the row.' );
		self::assertFalse( AB_MCP_Sync_Term_Writer::through_row( $prepared, 'meta:color' ) );
		self::assertSame( AB_MCP_Sync_Term_Writer::row_hash( $current['row'] ), AB_MCP_Sync_Term_Writer::part_hash( $current, 'row' ) );
		self::assertNull( AB_MCP_Sync_Term_Writer::part_hash( null, 'row' ), 'No term, no row.' );
		self::assertSame( AB_MCP_Sync_Post_Writer::meta_hash( array( 'red' ) ), AB_MCP_Sync_Term_Writer::part_hash( $current, 'meta:color' ) );
		// What the lock of a boundary covers: the term and new terms of its taxonomy.
		self::assertSame( array( 'posts' => array(), 'taxonomies' => array( 'category' ), 'terms' => array( 34 => 'category' ) ), AB_MCP_Sync_Term_Writer::guard_of( 34, 'category' ) );
		self::assertSame( array( 'posts' => array(), 'taxonomies' => array( 'category' ), 'terms' => array() ), AB_MCP_Sync_Term_Writer::guard_of( null, 'category' ) );
		self::assertSame( 'category', AB_MCP_Sync_Term_Writer::subtype_of( array( 'identity' => array( 'taxonomy' => 'category' ) ) ) );
	}

	public function testTheRightsOfTheMetaOfATermAreAskedPerOperationThroughMapMetaCap(): void {
		$denied                 = array();
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap . ':' . (string) ( $args[1] ?? '' ), $denied, true );
		};
		$backup = array( 'meta' => array( array( 'meta_id' => 1, 'key' => 'color', 'value' => 'red' ) ) );
		$now    = array( 'meta' => array( array( 'meta_id' => 1, 'key' => 'color', 'value' => 'blue' ), array( 'meta_id' => 2, 'key' => 'new', 'value' => 'n' ) ) );
		self::assertTrue( AB_MCP_Sync_Term_Writer::restore_right( 'meta:color', 34, $backup, $now ) );
		$denied = array( 'edit_term_meta:color' );
		self::assertStringContainsString( 'edit_term_meta refused', AB_MCP_Sync_Term_Writer::restore_right( 'meta:color', 34, $backup, $now ) );
		$denied = array( 'delete_term_meta:new' );
		self::assertStringContainsString( 'delete_term_meta refused', AB_MCP_Sync_Term_Writer::restore_right( 'meta:new', 34, $backup, $now ), 'The backup holds fewer rows: the way back removes.' );
		$denied = array( 'add_term_meta:gone' );
		self::assertStringContainsString( 'add_term_meta refused', AB_MCP_Sync_Term_Writer::restore_right( 'meta:gone', 34, array( 'meta' => array( array( 'meta_id' => 5, 'key' => 'gone', 'value' => 'g' ) ) ), $now ) );
		$denied = array( 'edit_term:' );
		self::assertStringContainsString( 'edit the term 34 (edit_term)', AB_MCP_Sync_Term_Writer::restore_right( 'row', 34, $backup, $now ) );
		self::assertStringContainsString( 'edit_term, for the part meta:color', AB_MCP_Sync_Term_Writer::restore_right( 'meta:color', 34, $backup, $now ), 'edit_term first, for every part.' );
	}

	/** A $wpdb with the category 34 (News, parent 0) and its meta color=red. */
	private static function live_term( array $row_over = array(), array $meta = array( array( 'meta_id' => 1, 'meta_key' => 'color', 'meta_value' => 'red' ) ) ): SyncTermWpdb {
		$db                = new SyncTermWpdb();
		$db->term_rows[34] = array_merge(
			array(
				'term_id'          => '34',
				'name'             => 'News',
				'slug'             => 'news',
				'term_group'       => '0',
				'term_taxonomy_id' => '34',
				'taxonomy'         => 'category',
				'description'      => 'Fresh news.',
				'parent'           => '0',
				'count'            => '3',
			),
			$row_over
		);
		$db->term_rows[60] = array( 'term_id' => '60', 'name' => 'Topics', 'slug' => 'topics', 'term_group' => '0', 'term_taxonomy_id' => '60', 'taxonomy' => 'category', 'description' => '', 'parent' => '0', 'count' => '0' );
		$db->meta[34]      = $meta;
		$GLOBALS['wpdb']   = $db;
		return $db;
	}

	public function testATermHereAsksItsRightItsGroupItsParentAndTheRightOfEveryKeyThatDiffers(): void {
		$db       = self::live_term();
		$ctx      = self::ctx( array( 'term' => array( 7 => 60 ) ) );
		$prepared = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '7' ), array( array( 'color', 'blue' ) ) ), self::item(), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ), 'With every right, its parent here and no loop.' );
		$current = AB_MCP_Sync_Term_Writer::current( 34 );
		self::assertSame( array( 'name' => 'News', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => 'Fresh news.', 'parent' => '0' ), $current['row'], 'The written columns, without the counter.' );
		self::assertSame( array( 'color' ), AB_MCP_Sync_Term_Writer::differing( $prepared, $current ) );
		// The right at the term.
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_term' !== $cap;
		$refused                = AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 );
		self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		self::assertStringContainsString( 'edit the term 34 of category (edit_term)', $refused->get_error_message() );
		// The right of the key that differs, through map_meta_cap (decision 28).
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_term_meta' !== $cap;
		$locked                 = AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 );
		self::assertSame( '*:meta_without_rule', self::lock_of( $locked ) );
		self::assertStringContainsString( 'Meta key color of the term on this site: edit_term_meta refused', $locked->get_error_message() );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		// The sanitizer of the meta.
		$upper = static fn( $v ) => strtoupper( (string) $v );
		add_filter( 'sanitize_term_meta_color', $upper );
		self::assertSame( 'term:sanitizer', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) ) );
		remove_filter( 'sanitize_term_meta_color', $upper );
		// Two spellings of the key the database compares alike.
		$db->meta[34][] = array( 'meta_id' => 2, 'meta_key' => 'Color', 'meta_value' => 'x' );
		$spelled        = AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 );
		self::assertSame( 'term:meta_spelling', self::lock_of( $spelled ) );
		array_pop( $db->meta[34] );
		// The group of this site, the parent, a loop.
		$db->term_rows[34]['term_group'] = '2';
		self::assertSame( 'term:term_group', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) ) );
		$db->term_rows[34]['term_group'] = '0';
		$GLOBALS['ab_test_loop']         = array( 60 => true, 34 => true );
		self::assertSame( 'term:parent', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) ), 'WordPress would break the loop by changing parents.' );
		unset( $GLOBALS['ab_test_loop'] );
		$gone = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'parent' => '8' ) ), self::item(), self::ctx( array( 'term' => array( 8 => 61 ) ) ) );
		self::assertSame( 'term:reference_unmapped', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $gone, 34, 0 ) ), 'A parent the pair names that is no term here.' );
		$GLOBALS['ab_test_hierarchical'] = array();
		self::assertSame( 'term:parent', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) ), 'A taxonomy without parents.' );
		unset( $GLOBALS['ab_test_hierarchical'] );
		// The slug another term holds; its own is fine.
		$db->holder = 34;
		self::assertTrue( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) );
		$db->holder = 41;
		self::assertSame( 'term:slug_collision', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) ) );
		$db->holder = null;
		// A term of another taxonomy under this id is gone for the step.
		$db->term_rows[34]['taxonomy'] = 'post_tag';
		self::assertSame( 'ab_mcp_sync_target_gone', AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 )->get_error_code() );
	}

	public function testTheRightsOfAKeyOfSeveralRowsOfATermAreTheOperationsTheWriteMakes(): void {
		// Codex round 1 on branch 9, A2, for terms: two rows of colors to
		// two other rows are removed whole and added anew (write_meta()); a
		// rule that gives edit_term_meta but refuses add and delete locks the
		// term, and the call is not made. Before, the net count asked edit
		// alone and the two other values were written.
		$db       = self::live_term( array(), array( array( 'meta_id' => 1, 'meta_key' => 'colors', 'meta_value' => 'red' ), array( 'meta_id' => 2, 'meta_key' => 'colors', 'meta_value' => 'green' ) ) );
		$prepared = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'colors', 'blue' ), array( 'colors', 'yellow' ) ) ), self::item(), self::ctx() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		$denied                 = array( 'add_term_meta', 'delete_term_meta' );
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( &$denied ): bool {
			return ! in_array( $cap, $denied, true );
		};
		$locked = AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 );
		self::assertSame( '*:meta_without_rule', self::lock_of( $locked ) );
		self::assertStringContainsString( 'Meta key colors of the term on this site: delete_term_meta refused', $locked->get_error_message() );
		$calls  = array();
		$record = static function ( $check ) use ( &$calls ) {
			$calls[] = current_filter();
			return $check;
		};
		foreach ( array( 'add_term_metadata', 'update_term_metadata', 'delete_term_metadata' ) as $hook ) {
			add_filter( $hook, $record );
		}
		$refused = AB_MCP_Sync_Term_Writer::write_part( 34, 'meta:colors', $prepared, AB_MCP_Sync_Term_Writer::current( 34 ) );
		foreach ( array( 'add_term_metadata', 'update_term_metadata', 'delete_term_metadata' ) as $hook ) {
			remove_filter( $hook, $record );
		}
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		self::assertStringContainsString( 'delete_term_meta refused', $refused->get_error_message() );
		self::assertSame( array(), $calls, 'Nothing written.' );
		$denied = array( 'add_term_meta' );
		self::assertStringContainsString( 'add_term_meta refused', AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 )->get_error_message() );
		$denied = array();
		self::assertTrue( AB_MCP_Sync_Term_Writer::precheck( $prepared, 34, 0 ) );
		// The way back puts the rows back in place (restore_meta()): edit
		// there, as before.
		$denied = array( 'add_term_meta', 'delete_term_meta' );
		$now    = array( 'meta' => array( array( 'meta_id' => 3, 'key' => 'colors', 'value' => 'blue' ), array( 'meta_id' => 4, 'key' => 'colors', 'value' => 'yellow' ) ) );
		self::assertTrue( AB_MCP_Sync_Term_Writer::restore_right( 'meta:colors', 34, AB_MCP_Sync_Term_Writer::current( 34 ), $now ) );
		unset( $db );
	}

	public function testASanitizerOfTheMetaOfATermIsComparedByTheBytesWordPressWouldStore(): void {
		// Codex round 1 on branch 9, B3, for terms: rank with absint(), '5'
		// on this site and '7' on staging: the sanitizer gives 7, stored as
		// '7', so the term goes; '07' would be stored as 7 and locks.
		self::live_term( array(), array( array( 'meta_id' => 1, 'meta_key' => 'rank', 'meta_value' => '5' ) ) );
		add_filter( 'sanitize_term_meta_rank', 'absint' );
		$seven = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'rank', '7' ) ) ), self::item(), self::ctx() );
		self::assertIsArray( $seven, is_wp_error( $seven ) ? $seven->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Term_Writer::precheck( $seven, 34, 0 ) );
		$padded = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'rank', '07' ) ) ), self::item(), self::ctx() );
		self::assertSame( 'term:sanitizer', self::lock_of( AB_MCP_Sync_Term_Writer::precheck( $padded, 34, 0 ) ) );
		remove_filter( 'sanitize_term_meta_rank', 'absint' );
	}

	public function testTheRowOfATermIsWrittenThroughTheCoreAndAnIdWordPressGaveBackIsNotTheStepsOwn(): void {
		$db       = self::live_term();
		$prepared = AB_MCP_Sync_Term_Writer::target_of( self::line( array( 'name' => 'O\'Neil \\ Co', 'description' => 'A "b"' ) ), self::item( 'create' ), self::ctx() );
		$GLOBALS['ab_test_term_answer'] = array( 'term_id' => 51, 'term_taxonomy_id' => 51 );
		self::assertSame( 51, AB_MCP_Sync_Term_Writer::write_row( $prepared, null ) );
		self::assertSame( array( 'insert', wp_slash( 'O\'Neil \\ Co' ), 'category', array( 'slug' => 'news', 'description' => wp_slash( 'A "b"' ), 'parent' => 0 ) ), $GLOBALS['ab_test_term_calls'][0], 'Name and description slashed: wp_insert_term() unslashes them.' );
		// An id not above the highest before the call: a duplicate WordPress gave back.
		$GLOBALS['ab_test_term_answer'] = array( 'term_id' => 50, 'term_taxonomy_id' => 50 );
		$back                           = AB_MCP_Sync_Term_Writer::write_row( $prepared, null );
		self::assertInstanceOf( WP_Error::class, $back );
		self::assertStringContainsString( 'which was there before the step', $back->get_error_message() );
		// An update under another id: a shared term WordPress split.
		$GLOBALS['ab_test_term_answer'] = array( 'term_id' => 99, 'term_taxonomy_id' => 99 );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Sync_Term_Writer::write_row( $prepared, 34 ) );
		unset( $GLOBALS['ab_test_term_answer'] );
		self::assertSame( 34, AB_MCP_Sync_Term_Writer::write_row( $prepared, 34 ) );
		self::assertSame( 'update', end( $GLOBALS['ab_test_term_calls'] )[0] );
		// A meta part asks its right at the term right before the call.
		$meta                   = AB_MCP_Sync_Term_Writer::target_of( self::line( array(), array( array( 'color', 'blue' ) ) ), self::item(), self::ctx() );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_term_meta' !== $cap;
		$refused                = AB_MCP_Sync_Term_Writer::write_part( 34, 'meta:color', $meta, AB_MCP_Sync_Term_Writer::current( 34 ) );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_rights', $refused->get_error_code() );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Sync_Term_Writer::write_part( 34, 'terms:category', $meta, null ), 'A term carries no terms.' );
		unset( $db );
	}

	public function testTheRowOfATermGoesBackOnlyWhereItIsWhatTheStepWroteAndItsSlugAndParentAreFree(): void {
		$db      = self::live_term( array( 'name' => 'News, new' ) );
		$backup  = array( 'name' => 'News', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => 'Fresh news.', 'parent' => '0' );
		$written = AB_MCP_Sync_Term_Writer::row_hash( AB_MCP_Sync_Term_Writer::current( 34 )['row'] );
		self::assertSame( 'left', AB_MCP_Sync_Term_Writer::restore_row( 34, $backup, str_repeat( 'f', 64 ) )['state'], 'Changed since the step wrote it.' );
		$db->holder = 41;
		$taken      = AB_MCP_Sync_Term_Writer::restore_row( 34, $backup, $written );
		self::assertSame( 'left', $taken['state'] );
		self::assertStringContainsString( 'held by another term', $taken['note'] );
		$db->holder = null;
		$parent     = AB_MCP_Sync_Term_Writer::restore_row( 34, array( 'parent' => '77' ) + $backup, $written );
		self::assertSame( 'left', $parent['state'], 'The parent of the backup is no term here now.' );
		$upper = static fn( $v ) => strtoupper( (string) $v );
		add_filter( 'pre_term_name', $upper );
		self::assertSame( 'left', AB_MCP_Sync_Term_Writer::restore_row( 34, $backup, $written )['state'], 'The sanitizing would change the backed-up name.' );
		remove_filter( 'pre_term_name', $upper );
		// Written back through wp_update_term(); the fake rows do not change,
		// so it reads other than the backup: unclear, never restored.
		self::assertSame( 'unclear', AB_MCP_Sync_Term_Writer::restore_row( 34, $backup, $written )['state'] );
		self::assertSame( array( 'update', 34, 'category', array( 'name' => 'News', 'slug' => 'news', 'description' => 'Fresh news.', 'parent' => 0 ) ), end( $GLOBALS['ab_test_term_calls'] ) );
		$db->term_rows[34]['name'] = 'News';
		self::assertSame( 'unchanged', AB_MCP_Sync_Term_Writer::restore_row( 34, $backup, $written )['state'], 'As the backup: nothing to do.' );
	}

	public function testTheLockOfABoundaryCoversATermItsMetaAndItsRestSaves(): void {
		$GLOBALS['wpdb']        = new SyncRunOptionWpdb();
		\AB_MCP_Sync_Guard::$now = 1000;
		self::assertTrue( \AB_MCP_Sync_Guard::begin( 7, 2, array(), array( 'category' ), array(), array( 34 => 'category' ) ) );
		self::assertSame( array( '34' => 'category' ), get_option( \AB_MCP_Sync_Guard::OPTION )['terms'] );
		\AB_MCP_Sync_Guard::$owner = false;
		self::assertTrue( \AB_MCP_Sync_Guard::covers_term( 34 ) );
		self::assertFalse( \AB_MCP_Sync_Guard::covers_term( 35 ) );
		self::assertFalse( \AB_MCP_Sync_Guard::term_meta_by_object( null, 34 ), 'The meta of the covered term is not written.' );
		self::assertNull( \AB_MCP_Sync_Guard::term_meta_by_object( null, 35 ) );
		self::assertFalse( \AB_MCP_Sync_Guard::term_meta_delete( null, 34, 'color' ) );
		self::assertFalse( \AB_MCP_Sync_Guard::term_meta_delete( null, null, 'color', '', true ), 'A delete across every term while one is covered.' );
		self::assertNull( \AB_MCP_Sync_Guard::term_meta_delete( null, 35, 'color' ) );
		// A REST save of the covered term: refused before the callbacks of the
		// controller of terms (rest_pre_insert_{$taxonomy} cannot refuse it).
		$handler = array( 'callback' => array( new \WP_REST_Terms_Controller(), 'update_item' ) );
		$refused = \AB_MCP_Sync_Guard::rest_term( null, $handler, new SyncTermRestRequest( 'POST', 34 ) );
		self::assertInstanceOf( WP_Error::class, $refused, 'A REST save of the covered term.' );
		self::assertSame( 423, $refused->get_error_data()['status'] );
		self::assertNull( \AB_MCP_Sync_Guard::rest_term( null, $handler, new SyncTermRestRequest( 'POST', 35 ) ), 'Another term.' );
		self::assertNull( \AB_MCP_Sync_Guard::rest_term( null, $handler, new SyncTermRestRequest( 'GET', 34 ) ), 'A read.' );
		self::assertNull( \AB_MCP_Sync_Guard::rest_term( null, array( 'callback' => 'strlen' ), new SyncTermRestRequest( 'POST', 34 ) ), 'Not a controller of terms.' );
		$earlier = new WP_Error( 'x', 'y' );
		self::assertSame( $earlier, \AB_MCP_Sync_Guard::rest_term( $earlier, $handler, new SyncTermRestRequest( 'POST', 34 ) ), 'What an earlier filter answered stands.' );
		$GLOBALS['ab_test_meta_ids'][9] = array( 34, 'color', 'red' );
		self::assertFalse( \AB_MCP_Sync_Guard::term_meta_by_mid( null, 9 ), 'By the meta id of the covered term.' );
		$GLOBALS['ab_test_meta_ids'][9] = array( 35, 'color', 'red' );
		self::assertNull( \AB_MCP_Sync_Guard::term_meta_by_mid( null, 9 ) );
		\AB_MCP_Sync_Guard::$owner = true;
		\AB_MCP_Sync_Guard::end( 7, 2 );
		\AB_MCP_Sync_Guard::disarm();
	}

	public function testTheValueBeforeOfAPartOfATermIsShortAndNeverACredential(): void {
		$backup = array(
			'row'   => array( 'name' => 'Old name', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => 'Old.', 'parent' => '0' ),
			'meta'  => array( array( 'meta_id' => 1, 'key' => 'color', 'value' => 'red' ), array( 'meta_id' => 2, 'key' => 'api_key', 'value' => 'sk-123' ) ),
			'terms' => array(),
		);
		$now    = array( 'row' => array( 'name' => 'New', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => 'category', 'description' => 'Old.', 'parent' => '0' ), 'meta' => array(), 'terms' => array() );
		self::assertSame( 'name: "Old name"', AB_MCP_Sync_Term_Writer::before_value( 'row', $backup, $now, 34 ), 'The columns that differ from now.' );
		self::assertSame( 'name: "Old name"; slug: "news"; description: "Old."; parent: "0"', AB_MCP_Sync_Term_Writer::before_value( 'row', $backup, null, 34 ), 'The named columns where the row is gone.' );
		self::assertSame( '"red"', AB_MCP_Sync_Term_Writer::before_value( 'meta:color', $backup, $now, 34 ) );
		self::assertSame( '(a credential by its name, not shown)', AB_MCP_Sync_Term_Writer::before_value( 'meta:api_key', $backup, $now, 34 ) );
		self::assertSame( 'none: the object was not on this site before the run.', AB_MCP_Sync_Term_Writer::before_value( 'row', null, $now, 34 ) );
	}
}
