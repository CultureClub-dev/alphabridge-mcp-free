<?php
/**
 * The canonical form canon/1 against WordPress 7.1.3 and MariaDB (Z8,
 * decision 11; AB_MCP_Sync_Canon, AB_MCP_Sync_Rows, AB_MCP_Sync_Db_Kinds):
 *
 * - the same content on site A and site B, with other ids and the other
 *   address, hashes alike: directly where it names no other object, as the
 *   mapped form (decision 10) where it does — each site computing with its
 *   own WordPress in a process of its own;
 * - nothing the exclusions name, nothing the hooks of the core write by
 *   themselves (revisions, former slugs and dates, counters, the editing
 *   lock), changes the hash; every change of content does;
 * - filters on get_post_meta(), get_option(), the title, the content and
 *   the block parser do not reach it;
 * - the inventory reads every kind with a cursor, each object once, within
 *   its budget.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Db_Kinds;
use AB_MCP_Sync_Inventory;
use PHPUnit\Framework\TestCase;

final class SyncCanonTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Inventory::$clock = null;
		remove_all_filters( 'get_post_metadata' );
		remove_all_filters( 'option_blogname' );
		remove_all_filters( 'pre_option_blogname' );
		remove_all_filters( 'the_title' );
		remove_all_filters( 'block_parser_class' );
		remove_all_filters( 'get_term' );
	}

	public static function tearDownAfterClass(): void {
		wp_set_current_user( 0 );
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
	}

	/* ---------------------------------------------------------- helpers */

	private static function kind( string $name ) {
		foreach ( AB_MCP_Sync_Db_Kinds::all() as $kind ) {
			if ( $kind->kind() === $name ) {
				return $kind;
			}
		}
		self::fail( "No kind $name." );
	}

	/** The canonical result of an object of site A, in this process. */
	private static function canon( string $kind, $ref ): array {
		$result = self::kind( $kind )->canonical(
			array(
				'ref'    => (string) $ref,
				'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
			)
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertTrue( $result['complete'], 'Read completely: ' . implode( ', ', $result['unread'] ) );
		return $result;
	}

	private static function hash( string $kind, $ref ): string {
		return self::canon( $kind, $ref )['hash'];
	}

	private static function post( array $over = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Kanon',
					'post_content' => '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_date'    => '2026-10-01 10:00:00',
				),
				$over
			),
			true
		);
		self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/** Write a column of a post past every API and cache. */
	private static function column( int $id, string $column, $value ): void {
		global $wpdb;
		self::assertNotFalse( $wpdb->update( $wpdb->posts, array( $column => $value ), array( 'ID' => $id ) ) );
		clean_post_cache( $id );
	}

	/**
	 * The steps that make the same content on a site: an attachment, a
	 * category, a parent page and a page that names all three, links to its
	 * own site in text, blocks, shortcodes, serialized and JSON meta.
	 */
	private static function content_steps( string $own, bool $reverse = false ): array {
		$json  = str_replace( '/', '\\/', $own );
		$terms = array(
			array( 'op' => 'insert_term', 'as' => 'cat', 'name' => 'Kategorie', 'taxonomy' => 'category' ),
			array( 'op' => 'insert_term', 'as' => 'cat2', 'name' => 'Zweite', 'taxonomy' => 'category' ),
		);
		$meta  = array(
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'ab_it_z', 'value' => 'zuletzt' ),
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'ab_it_m', 'value' => 'mitte' ),
		);
		// The same content made in another order on the other site: other
		// ids in another order, meta rows in another order.
		if ( $reverse ) {
			$terms = array_reverse( $terms );
			$meta  = array_reverse( $meta );
		}
		return array_merge( $terms, array(
			array( 'op' => 'insert_post', 'as' => 'att', 'args' => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_mime_type' => 'image/jpeg', 'post_date' => '2026-10-01 10:00:00' ) ),
			array( 'op' => 'add_meta', 'post' => '$att', 'key' => '_wp_attached_file', 'value' => '2026/10/bild.jpg' ),
			array( 'op' => 'insert_term', 'as' => 'fmt', 'name' => 'post-format-aside', 'taxonomy' => 'post_format' ),
			array( 'op' => 'insert_post', 'as' => 'parent', 'args' => array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Eltern', 'post_name' => 'eltern', 'post_date' => '2026-10-01 10:00:00' ) ),
			array(
				'op'   => 'insert_post',
				'as'   => 'page',
				'args' => array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Seite mit Bezügen',
					'post_name'    => 'bezuege',
					'post_parent'  => '$parent',
					'post_date'    => '2026-10-02 11:00:00',
					'post_excerpt' => 'Mehr unter ' . $own . '/kontakt/.',
					'post_content' => '<!-- wp:image {"id":{$att},"sizeSlug":"large"} -->' . "\n"
						. '<figure class="wp-block-image size-large"><img src="' . $own . '/wp-content/uploads/2026/10/bild.jpg" alt="" class="wp-image-{$att}"/></figure>' . "\n"
						. '<!-- /wp:image -->' . "\n\n"
						. '<!-- wp:file {"id":{$att},"href":"' . $own . '/wp-content/uploads/2026/10/bild.jpg"} --><div class="wp-block-file"><a href="' . $own . '/wp-content/uploads/2026/10/bild.jpg">Datei</a></div><!-- /wp:file -->' . "\n\n"
						. '<!-- wp:paragraph --><p>Siehe <a href="' . $own . '/eltern/">Eltern</a> [gallery ids="{$att}"]</p><!-- /wp:paragraph -->' . "\n\n"
						. '<!-- wp:query {"query":{"perPage":3,"taxQuery":{"category":[{$cat}]},"parents":[{$parent}]}} --><div class="wp-block-query"></div><!-- /wp:query -->',
				),
			),
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => '_thumbnail_id', 'value' => '{$att}' ),
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'ab_it_link', 'value' => $own . '/x' ),
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'ab_it_array', 'value' => array( 'url' => $own . '/y', 'n' => 'zwei' ) ),
			array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'ab_it_json', 'value' => '{"u":"' . $json . '\/z"}' ),
			array( 'op' => 'set_terms', 'post' => '$page', 'terms' => array( '$cat', '$cat2' ), 'taxonomy' => 'category' ),
			array( 'op' => 'set_terms', 'post' => '$page', 'terms' => array( '$fmt' ), 'taxonomy' => 'post_format' ),
			array( 'op' => 'update_option', 'name' => 'blogname', 'value' => 'Gleiche Site' ),
			array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Auf ' . $own . '/ zuhause' ),
			array( 'op' => 'canonical', 'as' => 'c_cat', 'kind' => 'term', 'ref' => '$cat', 'addresses' => AB_MCP_IT_Sync_Steps::addresses() ),
			array( 'op' => 'canonical', 'as' => 'c_blogname', 'kind' => 'option', 'ref' => 'blogname', 'addresses' => AB_MCP_IT_Sync_Steps::addresses() ),
			array( 'op' => 'canonical', 'as' => 'c_description', 'kind' => 'option', 'ref' => 'blogdescription', 'addresses' => AB_MCP_IT_Sync_Steps::addresses() ),
		), $meta, array(
			array( 'op' => 'canonical', 'as' => 'c_page', 'kind' => 'post', 'ref' => '$page', 'addresses' => AB_MCP_IT_Sync_Steps::addresses() ),
		) );
	}

	/* -------------------------------------------------- the two sites */

	public function testTheSameContentOnBothSitesHashesAlikeWithOtherIdsAndAddresses(): void {
		AB_MCP_IT_Sites::reset( 'b' );
		// Other ids on site B: three posts and two terms before the content.
		AB_MCP_IT_Sync_Steps::run(
			'b',
			array(
				array( 'op' => 'insert_post', 'args' => array( 'post_title' => 'Vorher 1', 'post_status' => 'draft' ) ),
				array( 'op' => 'insert_post', 'args' => array( 'post_title' => 'Vorher 2', 'post_status' => 'draft' ) ),
				array( 'op' => 'insert_post', 'args' => array( 'post_title' => 'Vorher 3', 'post_status' => 'draft' ) ),
				array( 'op' => 'insert_term', 'name' => 'Vorher', 'taxonomy' => 'post_tag' ),
				array( 'op' => 'insert_term', 'name' => 'Vorher 2', 'taxonomy' => 'post_tag' ),
			)
		);
		$a = AB_MCP_IT_Sync_Steps::run( 'a', self::content_steps( AB_MCP_IT_Sites::url( 'a' ) ) );
		$b = AB_MCP_IT_Sync_Steps::run( 'b', self::content_steps( AB_MCP_IT_Sites::url( 'b' ), true ) );

		foreach ( array( 'att', 'cat', 'cat2', 'fmt', 'parent', 'page' ) as $name ) {
			self::assertNotSame( $a[ $name ], $b[ $name ], "$name has another id on site B." );
		}
		foreach ( array( 'c_page', 'c_cat', 'c_blogname', 'c_description' ) as $name ) {
			self::assertTrue( $a[ $name ]['complete'] && $b[ $name ]['complete'], $name );
			self::assertSame( 'canon/1', $b[ $name ]['canon'] );
		}

		// Without references: alike as they are, addresses neutral.
		self::assertSame( $a['c_cat']['hash'], $b['c_cat']['hash'], 'The term.' );
		self::assertSame( $a['c_blogname']['hash'], $b['c_blogname']['hash'], 'The site title.' );
		self::assertSame( $a['c_description']['hash'], $b['c_description']['hash'], 'A text with the address of each site.' );

		// With references: each in the ID space of its site, so not alike
		// as they are, and alike once B's ids are mapped to A's.
		self::assertNotSame( $a['c_page']['hash'], $b['c_page']['hash'] );
		$map = array(
			'post' => array(
				$b['att']    => $a['att'],
				$b['parent'] => $a['parent'],
			),
			'term' => array(
				$b['cat']  => $a['cat'],
				$b['cat2'] => $a['cat2'],
			),
		);
		self::assertLessThan( $b['cat'], $b['cat2'], 'On site B the second category came first.' );
		self::assertSame( $a['c_page']['hash'], AB_MCP_Sync_Canon::hash( AB_MCP_Sync_Canon::mapped( $b['c_page']['form'], $map ) ) );
		// A map that leaves one out keeps them apart.
		unset( $map['term'][ $b['cat2'] ] );
		self::assertNotSame( $a['c_page']['hash'], AB_MCP_Sync_Canon::hash( AB_MCP_Sync_Canon::mapped( $b['c_page']['form'], $map ) ) );

		// The references the walk found, typed.
		$refs = array();
		foreach ( $a['c_page']['refs'] as $ref ) {
			$refs[] = $ref['space'] . ':' . ( $ref['id'] === $a['att'] ? 'att' : ( $ref['id'] === $a['cat'] ? 'cat' : ( $ref['id'] === $a['parent'] ? 'parent' : ( 'user' === $ref['space'] ? 'user' : (string) $ref['id'] ) ) ) ) . '@' . $ref['at'];
		}
		foreach ( array(
			'post:parent@post_parent',
			'user:user@post_author',
			'post:att@post_content:core/image.id',
			'post:att@post_content:core/image:html:class',
			'post:att@post_content:core/file.id',
			'post:att@post_content:core/paragraph:shortcode:gallery.ids',
			'term:cat@post_content:core/query.query.taxQuery.category.0',
			'post:parent@post_content:core/query.query.parents.0',
			'post:att@meta:_thumbnail_id',
			'term:cat@term:category',
		) as $want ) {
			self::assertContains( $want, $refs );
		}
		self::assertSame( array(), $a['c_page']['locks'] );
		self::assertSame( array(), $a['c_page']['possible'], 'No number without a schema: perPage is a plain number of core/query.' );
	}

	/* ------------------------------------------------------- exclusions */

	public function testWhatTheExclusionsNameAndWhatTheCoreHooksWriteDoNotChangeTheHash(): void {
		$cat  = wp_insert_term( 'Zähler', 'category' );
		$id   = self::post( array( 'post_category' => array( (int) $cat['term_id'] ) ) );
		$base = self::hash( 'post', $id );
		$term = self::hash( 'term', $cat['term_id'] );

		global $wpdb;
		$changes = array(
			'guid'              => static function () use ( $id ) {
				self::column( $id, 'guid', 'http://elsewhere.test/?p=1' );
			},
			'post_modified'     => static function () use ( $id ) {
				self::column( $id, 'post_modified', '2030-01-01 00:00:00' );
				self::column( $id, 'post_modified_gmt', '2030-01-01 00:00:00' );
			},
			'comment_count'     => static function () use ( $id ) {
				self::column( $id, 'comment_count', 7 );
			},
			'to_ping / pinged'  => static function () use ( $id ) {
				self::column( $id, 'to_ping', 'http://ping.test/' );
				self::column( $id, 'pinged', 'http://pinged.test/' );
			},
			'meta of the life of the post' => static function () use ( $id ) {
				foreach ( array( '_edit_lock' => '1700000000:1', '_edit_last' => '1', '_wp_old_slug' => 'alt', '_wp_old_date' => '2020-01-01', '_wp_desired_post_slug' => 'x', '_encloseme' => '1', '_pingme' => '1', '_wp_trash_meta_status' => 'publish', '_oembed_0123' => '<iframe>', '_ab_mcp_sha256' => 'abc' ) as $key => $value ) {
					add_post_meta( $id, $key, $value );
				}
			},
			'a revision'        => static function () use ( $id ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => 'Kanon neu' ) );
				wp_update_post( array( 'ID' => $id, 'post_title' => 'Kanon' ) );
				self::assertNotEmpty( wp_get_post_revisions( $id ), 'The core made revisions.' );
			},
		);
		foreach ( $changes as $what => $change ) {
			$change();
			self::assertSame( $base, self::hash( 'post', $id ), $what );
		}
		// The hooks wrote former slugs and the editing lock on the way.
		wp_update_post( array( 'ID' => $id, 'post_name' => 'neu' ) );
		self::assertNotEmpty( get_post_meta( $id, '_wp_old_slug' ) );
		wp_update_post( array( 'ID' => $id, 'post_name' => 'kanon' ) );
		self::assertSame( $base, self::hash( 'post', $id ), 'Former slug back and forth.' );

		// The counter of the term.
		$wpdb->update( $wpdb->term_taxonomy, array( 'count' => 99 ), array( 'term_id' => (int) $cat['term_id'] ) );
		clean_term_cache( (int) $cat['term_id'] );
		self::assertSame( $term, self::hash( 'term', $cat['term_id'] ), 'The term count.' );
		self::post( array( 'post_category' => array( (int) $cat['term_id'] ) ) );
		self::assertSame( $term, self::hash( 'term', $cat['term_id'] ), 'A post more in the term.' );
	}

	public function testEveryChangeOfContentChangesTheHash(): void {
		$cat   = wp_insert_term( 'Inhalt', 'category' );
		$other = wp_insert_term( 'Anders', 'category' );
		$page  = self::post( array( 'post_type' => 'page', 'post_title' => 'Eltern' ) );
		$user  = wp_insert_user( array( 'user_login' => 'zweite', 'user_pass' => wp_generate_password(), 'user_email' => 'zweite@example.test', 'role' => 'editor' ) );
		$id    = self::post( array( 'post_category' => array( (int) $cat['term_id'] ) ) );
		add_post_meta( $id, 'ab_it_a', 'eins' );
		add_post_meta( $id, 'ab_it_a', 'zwei' );
		$seen = array( self::hash( 'post', $id ) => 'start' );

		$changes = array(
			'post_title'            => array( 'post_title', 'Anders' ),
			'post_content'          => array( 'post_content', '<!-- wp:paragraph --><p>Text!</p><!-- /wp:paragraph -->' ),
			'post_excerpt'          => array( 'post_excerpt', 'Auszug' ),
			'post_status'           => array( 'post_status', 'draft' ),
			'post_name'             => array( 'post_name', 'anders' ),
			'post_date'             => array( 'post_date', '2026-10-01 10:00:01' ),
			'post_date_gmt'         => array( 'post_date_gmt', '2026-10-01 08:00:01' ),
			'post_password'         => array( 'post_password', 'geheim' ),
			'comment_status'        => array( 'comment_status', 'closed' ),
			'ping_status'           => array( 'ping_status', 'closed' ),
			'menu_order'            => array( 'menu_order', 3 ),
			'post_mime_type'        => array( 'post_mime_type', 'text/plain' ),
			'post_content_filtered' => array( 'post_content_filtered', 'x' ),
			'post_parent'           => array( 'post_parent', $page ),
			'post_author'           => array( 'post_author', $user ),
			'post_type'             => array( 'post_type', 'page' ),
		);
		foreach ( $changes as $what => list( $column, $value ) ) {
			self::column( $id, $column, $value );
			$hash = self::hash( 'post', $id );
			self::assertArrayNotHasKey( $hash, $seen, "$what changes the hash." );
			$seen[ $hash ] = $what;
		}
		global $wpdb;
		$more = array(
			'a meta value'      => static function () use ( $id ) {
				update_post_meta( $id, 'ab_it_a', 'drei', 'zwei' );
			},
			'a meta row more'   => static function () use ( $id ) {
				add_post_meta( $id, 'ab_it_b', 'neu' );
			},
			'the order of rows' => static function () use ( $id, $wpdb ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'ab_it_a' ORDER BY meta_id", $id ), ARRAY_A );
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $rows[1]['meta_value'] ), array( 'meta_id' => $rows[0]['meta_id'] ) );
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $rows[0]['meta_value'] ), array( 'meta_id' => $rows[1]['meta_id'] ) );
				wp_cache_delete( $id, 'post_meta' );
			},
			'another term'      => static function () use ( $id, $other ) {
				wp_set_object_terms( $id, array( (int) $other['term_id'] ), 'category' );
			},
			'a block attribute' => static function () use ( $id ) {
				self::column( $id, 'post_content', '<!-- wp:paragraph {"align":"right"} --><p>Text!</p><!-- /wp:paragraph -->' );
			},
		);
		foreach ( $more as $what => $change ) {
			$change();
			$hash = self::hash( 'post', $id );
			self::assertArrayNotHasKey( $hash, $seen, "$what changes the hash." );
			$seen[ $hash ] = $what;
		}

		// Term, option and widget area alike.
		$term = self::hash( 'term', $cat['term_id'] );
		wp_update_term( (int) $cat['term_id'], 'category', array( 'description' => 'Neu' ) );
		self::assertNotSame( $term, self::hash( 'term', $cat['term_id'] ), 'A term description.' );
		self::assertSame( get_option( 'use_smilies' ), get_option( 'comments_notify' ) );
		self::assertNotSame( self::hash( 'option', 'use_smilies' ), self::hash( 'option', 'comments_notify' ), 'Two options with the same value are two forms.' );
		$opt = self::hash( 'option', 'posts_per_page' );
		update_option( 'posts_per_page', 11 );
		self::assertNotSame( $opt, self::hash( 'option', 'posts_per_page' ), 'A core option.' );
	}

	/* ---------------------------------------------------------- filters */

	public function testFiltersOfWordPressDoNotReachTheCanonicalForm(): void {
		$id = self::post( array( 'post_title' => 'Titel' ) );
		add_post_meta( $id, 'ab_it_meta', 'gespeichert' );
		add_post_meta( $id, '_et_pb_use_builder', 'on' );
		$cat    = wp_insert_term( 'Gefiltert', 'category' );
		$post   = self::canon( 'post', $id );
		$option = self::hash( 'option', 'blogname' );
		$term   = self::hash( 'term', $cat['term_id'] );

		add_filter(
			'get_post_metadata',
			static function ( $value, $object, $key ) {
				return '_et_pb_use_builder' === $key ? array( '' ) : array( 'gefiltert' );
			},
			10,
			3
		);
		add_filter( 'option_blogname', static fn() => 'Gefiltert' );
		add_filter( 'pre_option_blogname', static fn() => 'Vorher gefiltert' );
		add_filter( 'the_title', static fn() => 'Gefiltert' );
		add_filter( 'get_term', static function ( $t ) {
			$t->name = 'Gefiltert';
			return $t;
		} );
		add_filter( 'block_parser_class', static fn() => 'AB_IT_No_Such_Parser' );
		self::assertSame( 'gefiltert', get_post_meta( $id, 'ab_it_meta', true ), 'The filter works on get_post_meta().' );
		self::assertSame( 'Vorher gefiltert', get_option( 'blogname' ) );

		$again = self::canon( 'post', $id );
		self::assertSame( $post['hash'], $again['hash'], 'The post.' );
		// The builder lock holds although get_post_meta() hides its marker;
		// a filter can add locks (detect_all() sees its values), never take one.
		self::assertContains( 'builder:divi4', array_column( $post['locks'], 'at' ) );
		self::assertContains( 'builder:divi4', array_column( $again['locks'], 'at' ) );
		self::assertSame( $option, self::hash( 'option', 'blogname' ), 'The option.' );
		self::assertSame( $term, self::hash( 'term', $cat['term_id'] ), 'The term.' );
	}

	/* -------------------------------------------------------- inventory */

	public function testTheInventoryReadsEveryKindOnceAcrossItsCursor(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			self::post( array( 'post_title' => "Post $i" ) );
		}
		wp_insert_term( 'Eins', 'category' );
		$menu = wp_create_nav_menu( 'Hauptmenü' );
		$page = self::post( array( 'post_type' => 'page', 'post_title' => 'Ziel' ) );
		$item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-status' => 'publish' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );

		wp_update_post( array( 'ID' => $page, 'post_title' => 'Ziel neu' ) );
		$revisions = array_keys( wp_get_post_revisions( $page ) );
		self::assertNotEmpty( $revisions );

		$all = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		self::assertIsArray( $all );
		self::assertNull( $all['cursor'], 'One call within the budget reads everything.' );
		$keys = array();
		foreach ( $all['objects'] as $object ) {
			$keys[] = $object['kind'] . ':' . $object['ref'];
			self::assertArrayNotHasKey( 'form', $object );
			self::assertTrue( null === $object['hash'] || 1 === preg_match( '/^[0-9a-f]{64}\z/', $object['hash'] ) );
		}
		self::assertSame( count( $keys ), count( array_unique( $keys ) ) );
		foreach ( array( 'post:' . $page, 'menu:' . $menu, 'menu_item:item:' . $item, 'option:blogname', 'widget_area:sidebar-1' ) as $want ) {
			self::assertContains( $want, $keys );
		}
		$menu_entry = $all['objects'][ array_search( 'menu:' . $menu, $keys, true ) ];
		self::assertSame( 'menu', $menu_entry['kind'] );
		self::assertNotContains( 'post:' . $item, $keys, 'A menu item belongs to the menu kind only.' );
		foreach ( $revisions as $revision ) {
			self::assertNotContains( 'post:' . $revision, $keys, 'A revision is never compared.' );
		}

		// A clock that runs out after every object: each call one object,
		// the cursor goes on, nothing twice, nothing missing.
		$tick                         = 0.0;
		AB_MCP_Sync_Inventory::$clock = static function () use ( &$tick ) {
			$tick += 100.0;
			return $tick;
		};
		$source = AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses(), 50 );
		$cursor = null;
		$parts  = array();
		$calls  = 0;
		do {
			$source['deadline'] = $tick + 50;
			$part               = AB_MCP_Sync_Inventory::run( $source, $cursor );
			self::assertIsArray( $part );
			foreach ( $part['objects'] as $object ) {
				$parts[] = $object['kind'] . ':' . $object['ref'];
			}
			$cursor = $part['cursor'];
			++$calls;
		} while ( null !== $cursor && $calls < 1000 );
		self::assertGreaterThan( 10, $calls );
		self::assertSame( $keys, $parts );
	}

	public function testTheOwnAddressesAreTheRowsNotTheFilteredOptions(): void {
		add_filter( 'option_home', static fn() => 'https://filtered.test' );
		try {
			self::assertSame( array( AB_MCP_IT_Sites::url( 'a' ) ), AB_MCP_Sync_Canon::own_addresses() );
		} finally {
			remove_all_filters( 'option_home' );
		}
	}

	public function testEachSiteComputesTheVersionAndRefusesAnother(): void {
		self::assertSame( 'canon/1', self::canon( 'option', 'blogname' )['canon'] );
		self::assertNull( AB_MCP_Sync_Canon::version_refusal( 'canon/1' ) );
		$refusal = AB_MCP_Sync_Canon::version_refusal( 'canon/2' );
		self::assertInstanceOf( \WP_Error::class, $refusal );
		self::assertSame( 'ab_mcp_sync_canon_version', $refusal->get_error_code() );
		self::assertStringContainsString( 'Update AlphaBridge MCP on both sites', $refusal->get_error_message() );
	}
}
