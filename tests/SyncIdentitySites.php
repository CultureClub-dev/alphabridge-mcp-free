<?php
/**
 * The site a test of the index of identities describes, and the $wpdb that
 * answers the readers of AB_MCP_Sync_Identity from it a page at a time
 * (SyncIdentityTest; SyncReportTest builds the mapped forms of two sites
 * with them, Codex round 1 of Z8 branch 6 part 2).
 *
 * @package AlphaBridge_MCP
 */

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Identity;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-identity.php';

/**
 * A $wpdb that answers the readers of the index from a site a test
 * describes, a page at a time (AB_MCP_Sync_Rows::select(): query() and
 * last_result); any other question fails the test.
 */
final class SyncIdentityFakeWpdb {
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
	/** @var int Questions asked. */
	public $asked = 0;
	/** @var string|null A pattern of the questions that fail. */
	public $fail = null;
	/** @var callable function( string $what, array $args ): array[] */
	private $site;

	public function __construct( callable $site ) {
		$this->site = $site;
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = (string) preg_replace_callback(
				'/%[sd]/',
				static function ( $m ) use ( $arg ) {
					return '%d' === $m[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}

	public function query( $sql ) {
		if ( ++$this->asked > 5000 ) {
			TestCase::fail( 'The index asks again and again: ' . $sql );
		}
		$this->last_result = array();
		$this->last_error  = '';
		if ( null !== $this->fail && 1 === preg_match( $this->fail, $sql ) ) {
			$this->last_error = 'Lost connection';
			return false;
		}
		$questions = array(
			'posts'      => "/FROM wp_posts WHERE ID > (\\d+) AND post_type <> 'revision' ORDER BY ID ASC LIMIT (\\d+)/",
			'meta'       => "/meta_key = BINARY '([^']*)' AND meta_id > (\\d+) ORDER BY meta_id ASC LIMIT (\\d+)/",
			'relations'  => "/tt.taxonomy = BINARY '([^']*)' AND \\( tr.object_id > (\\d+) OR \\( tr.object_id = \\d+ AND tr.term_taxonomy_id > (\\d+) \\) \\) ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC LIMIT (\\d+)/",
			'wpml_table' => '/FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = \'wp_icl_translations\'/',
			'wpml'       => '/FROM `wp_icl_translations` WHERE element_id IS NOT NULL AND translation_id > (\\d+) ORDER BY translation_id ASC LIMIT (\\d+)/',
			'terms'      => '/WHERE tt.term_taxonomy_id > (\\d+) ORDER BY tt.term_taxonomy_id ASC LIMIT (\\d+)/',
			'titles'     => "/^SELECT ID, post_title FROM wp_posts WHERE post_type = 'nav_menu_item' ORDER BY ID ASC$/",
			'item_meta'  => "/^SELECT pm.post_id, pm.meta_key, pm.meta_value FROM wp_postmeta AS pm INNER JOIN wp_posts AS p ON p.ID = pm.post_id WHERE p.post_type = 'nav_menu_item'/",
			'type_of'    => '/^SELECT post_type FROM wp_posts WHERE ID = (\\d+)$/',
			'option'     => '/^SELECT option_name, option_value, autoload FROM wp_options WHERE option_name = /',
		);
		foreach ( $questions as $what => $pattern ) {
			if ( 1 === preg_match( $pattern, $sql, $m ) ) {
				$rows              = call_user_func( $this->site, $what, array_slice( $m, 1 ) );
				$this->last_result = array_map( static fn( $r ) => (object) $r, $rows );
				return count( $rows );
			}
		}
		TestCase::fail( 'A question the index does not ask: ' . $sql );
	}
}

/**
 * A site of a few objects, as rows.
 */
final class SyncIdentitySite {
	/** @var array[] ID, post_type, post_name, post_parent. */
	public $posts = array();
	/** @var array[] meta_id, post_id, meta_key, meta_value. */
	public $meta = array();
	/** @var array<string,array[]> taxonomy => object_id, term_taxonomy_id, slug. */
	public $relations = array();
	/** @var array[] term_id, term_taxonomy_id, taxonomy, slug. */
	public $terms = array();
	/** @var array[]|null translation_id, element_type, element_id, language_code; null for no table. */
	public $wpml = null;
	/** @var array<int,string> Menu item id => title. */
	public $titles = array();

	public function post( int $id, string $type, string $name, int $parent = 0 ): int {
		$this->posts[] = array( 'ID' => $id, 'post_type' => $type, 'post_name' => $name, 'post_parent' => $parent );
		return $id;
	}

	public function meta( int $post, string $key, string $value ): void {
		$this->meta[] = array( 'meta_id' => count( $this->meta ) + 1, 'post_id' => $post, 'meta_key' => $key, 'meta_value' => $value );
	}

	public function term( int $id, int $ttid, string $taxonomy, string $slug ): int {
		$this->terms[] = array( 'term_id' => $id, 'name' => $slug, 'slug' => $slug, 'term_group' => 0, 'term_taxonomy_id' => $ttid, 'taxonomy' => $taxonomy, 'description' => '', 'parent' => 0, 'count' => 0 );
		return $id;
	}

	public function relate( string $taxonomy, int $object, int $ttid, string $slug ): void {
		$this->relations[ $taxonomy ][] = array( 'object_id' => $object, 'term_taxonomy_id' => $ttid, 'slug' => $slug );
	}

	public function item( int $id, string $title, array $meta, string $menu = 'main' ): int {
		$this->post( $id, 'nav_menu_item', (string) $id );
		$this->titles[ $id ] = $title;
		foreach ( $meta as $key => $value ) {
			$this->meta( $id, $key, (string) $value );
		}
		$this->relate( 'nav_menu', $id, 900, $menu );
		return $id;
	}

	/** The answers of the questions of SyncIdentityFakeWpdb. */
	public function __invoke( string $what, array $a ): array {
		$after = static fn( array $rows, string $by, int $after, int $limit ): array => array_slice( array_values( array_filter( $rows, static fn( $r ) => $r[ $by ] > $after ) ), 0, $limit );
		switch ( $what ) {
			case 'posts':
				$rows = $this->posts;
				usort( $rows, static fn( $x, $y ) => $x['ID'] <=> $y['ID'] );
				return $after( $rows, 'ID', (int) $a[0], (int) $a[1] );
			case 'meta':
				return $after( array_values( array_filter( $this->meta, static fn( $r ) => $a[0] === $r['meta_key'] ) ), 'meta_id', (int) $a[1], (int) $a[2] );
			case 'relations':
				$rows = $this->relations[ $a[0] ] ?? array();
				usort( $rows, static fn( $x, $y ) => array( $x['object_id'], $x['term_taxonomy_id'] ) <=> array( $y['object_id'], $y['term_taxonomy_id'] ) );
				$rows = array_values( array_filter( $rows, static fn( $r ) => $r['object_id'] > (int) $a[1] || ( $r['object_id'] === (int) $a[1] && $r['term_taxonomy_id'] > (int) $a[2] ) ) );
				return array_slice( $rows, 0, (int) $a[3] );
			case 'wpml_table':
				return null === $this->wpml ? array() : array( array( 'TABLE_NAME' => 'wp_icl_translations' ) );
			case 'wpml':
				return $after( (array) $this->wpml, 'translation_id', (int) $a[0], (int) $a[1] );
			case 'terms':
				$rows = $this->terms;
				usort( $rows, static fn( $x, $y ) => $x['term_taxonomy_id'] <=> $y['term_taxonomy_id'] );
				return $after( $rows, 'term_taxonomy_id', (int) $a[0], (int) $a[1] );
			case 'titles':
				$out = array();
				foreach ( $this->titles as $id => $title ) {
					$out[] = array( 'ID' => $id, 'post_title' => $title );
				}
				return $out;
			case 'item_meta':
				return array_map(
					static fn( $r ) => array( 'post_id' => $r['post_id'], 'meta_key' => $r['meta_key'], 'meta_value' => $r['meta_value'] ),
					array_values( array_filter( $this->meta, fn( $r ) => isset( $this->titles[ $r['post_id'] ] ) && in_array( $r['meta_key'], AB_MCP_Sync_Identity::ITEM_META, true ) ) )
				);
			case 'type_of':
				foreach ( $this->posts as $row ) {
					if ( (int) $a[0] === $row['ID'] ) {
						return array( array( 'post_type' => $row['post_type'] ) );
					}
				}
				return array();
		}
		return array();
	}
}
