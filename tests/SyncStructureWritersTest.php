<?php
/**
 * The write sides of the kinds of the structure of a site (Z8 branch 11):
 * AB_MCP_Sync_Option_Writer, AB_MCP_Sync_Css_Writer, AB_MCP_Sync_Menu_Writer,
 * AB_MCP_Sync_Menu_Item_Writer and AB_MCP_Sync_Widget_Area_Writer: what a step
 * writes from a line of a package of objects, what it refuses with its lock
 * before anything is written, the parts of its journal, and the engine that
 * takes their objects by a name. The writes, the rows and the way back run
 * against WordPress in the integration suite
 * (tests/integration/SyncStructureTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Css_Writer;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Menu_Item_Writer;
use AB_MCP_Sync_Menu_Writer;
use AB_MCP_Sync_Option_Writer;
use AB_MCP_Sync_Shared_Row;
use AB_MCP_Sync_Widget_Area_Writer;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-run-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-engine.php';

require_once __DIR__ . '/structure-stubs.php';

/**
 * A $wpdb with option rows, post rows and their meta and terms, as
 * AB_MCP_Sync_Rows reads them (the rows of AB_MCP_Undo_Widgets::row(),
 * AB_MCP_Sync_Rows::post(), meta(), object_terms(), term()).
 */
final class SyncStructureWpdb {
	public $prefix             = 'wp_';
	public $options            = 'wp_options';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $termmeta           = 'wp_termmeta';
	public $last_error         = '';
	public $last_result        = array();
	/** @var array<string,string> Option name => value as stored. */
	public $option_rows = array();
	/** @var array<int,array> Post rows by id (the columns a test names; the rest empty). */
	public $post_rows = array();
	/** @var array<int,array> Term rows by id. */
	public $term_rows = array();
	/** @var array<int,array[]> Post meta by post: meta_id, meta_key, meta_value. */
	public $meta = array();

	public function prepare( $query, ...$args ) {
		return ( new \AbTestSyncWpdb() )->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		return null;
	}

	public function query( $sql ) {
		$this->last_result = array();
		$sql               = (string) $sql;
		if ( preg_match( "/FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1/", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( array_key_exists( $name, $this->option_rows ) ) {
				$this->last_result = array( (object) array( 'option_name' => $name, 'option_value' => $this->option_rows[ $name ], 'autoload' => 'on' ) );
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/FROM wp_posts WHERE ID = (\d+)/', $sql, $m ) ) {
			if ( isset( $this->post_rows[ (int) $m[1] ] ) ) {
				$row = array();
				foreach ( \AB_MCP_Sync_Rows::POST_COLUMNS as $column ) {
					$row[ $column ] = (string) ( $this->post_rows[ (int) $m[1] ][ $column ] ?? '' );
				}
				$row['ID']         = (string) (int) $m[1];
				$this->last_result = array( (object) $row );
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/FROM wp_postmeta WHERE post_id = (\d+)/', $sql, $m ) ) {
			foreach ( $this->meta[ (int) $m[1] ] ?? array() as $row ) {
				$this->last_result[] = (object) $row;
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/WHERE tr\.object_id = (\d+)/', $sql ) ) {
			return 0;
		}
		if ( preg_match( "/WHERE t\.term_id = (\d+) AND tt\.taxonomy = BINARY '([^']*)'/", $sql, $m ) ) {
			$row = $this->term_rows[ (int) $m[1] ] ?? null;
			if ( is_array( $row ) && $row['taxonomy'] === $m[2] ) {
				$this->last_result = array( (object) $row );
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/^SELECT taxonomy FROM wp_term_taxonomy WHERE term_id = (\d+)/', $sql, $m ) ) {
			if ( isset( $this->term_rows[ (int) $m[1] ] ) ) {
				$this->last_result = array( (object) array( 'taxonomy' => $this->term_rows[ (int) $m[1] ]['taxonomy'] ) );
			}
			return count( $this->last_result );
		}
		return 0;
	}
}

final class SyncStructureWritersTest extends TestCase {

	/** @var mixed The $wpdb before the case. */
	private $wpdb_before;

	protected function setUp(): void {
		$this->wpdb_before                  = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                    = new SyncStructureWpdb();
		$GLOBALS['ab_test_can']             = static fn( string $cap, array $args ): bool => true;
		$GLOBALS['ab_test_sanitize_option'] = null;
		$GLOBALS['ab_test_nav_menus']       = array( 'primary' => 'Primary' );
		$GLOBALS['ab_test_theme_mods']      = array();
		$GLOBALS['ab_test_stylesheet']      = 'twentytwentyfive';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                    = $this->wpdb_before;
		$GLOBALS['ab_test_can']             = null;
		$GLOBALS['ab_test_sanitize_option'] = null;
		$GLOBALS['ab_test_nav_menus']       = array();
		$GLOBALS['ab_test_filters']         = array();
	}

	private static function ctx( array $map = array(), array $pending = array() ): array {
		return array(
			'map'       => array( 'post' => $map['post'] ?? array(), 'term' => $map['term'] ?? array() ),
			'addresses' => array( 'https://staging.test' => 'https://live.test' ),
			'routes'    => null,
			'pending'   => array( 'post' => $pending['post'] ?? array(), 'term' => $pending['term'] ?? array() ),
			'now'       => 1800000000,
		);
	}

	private static function lock_of( $result ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_sync_locked', $result->get_error_code(), $result->get_error_message() );
		return (string) $result->get_error_data()['lock']['key'];
	}

	private static function db(): SyncStructureWpdb {
		return $GLOBALS['wpdb'];
	}

	/* ------------------------------------------------------------ engine */

	public function testTheEngineWritesTheStructureByTheWriterOfEachKindAndNamesItsObjectsByAName(): void {
		self::assertSame( AB_MCP_Sync_Menu_Writer::class, AB_MCP_Sync_Engine::writer( 'menu' ) );
		self::assertSame( AB_MCP_Sync_Menu_Item_Writer::class, AB_MCP_Sync_Engine::writer( 'menu_item' ) );
		self::assertSame( AB_MCP_Sync_Widget_Area_Writer::class, AB_MCP_Sync_Engine::writer( 'widget_area' ) );
		self::assertSame( AB_MCP_Sync_Css_Writer::class, AB_MCP_Sync_Engine::writer( 'custom_css' ) );
		self::assertSame( AB_MCP_Sync_Option_Writer::class, AB_MCP_Sync_Engine::writer( 'option' ) );
		// An id as an integer (as before), a name as the writer reads it.
		self::assertSame( 12, AB_MCP_Sync_Engine::live_of( AB_MCP_Sync_Engine::writer( 'menu_item' ), '12' ) );
		self::assertSame( 'blogname', AB_MCP_Sync_Engine::live_of( AB_MCP_Sync_Engine::writer( 'option' ), 'blogname' ) );
		self::assertSame( 'sidebar-1', AB_MCP_Sync_Engine::live_of( AB_MCP_Sync_Engine::writer( 'widget_area' ), 'sidebar-1' ) );
		self::assertNull( AB_MCP_Sync_Engine::live_of( AB_MCP_Sync_Engine::writer( 'widget_area' ), 'array_version' ), 'No area of that name.' );
		self::assertSame( 0, AB_MCP_Sync_Engine::live_of( null, 'x' ) );
		foreach ( array( 12 => true, 0 => false, -1 => false ) as $id => $is ) {
			self::assertSame( $is, AB_MCP_Sync_Engine::is_ref( $id ), (string) $id );
		}
		self::assertTrue( AB_MCP_Sync_Engine::is_ref( 'sidebar-1' ) );
		self::assertFalse( AB_MCP_Sync_Engine::is_ref( '' ) );
		self::assertFalse( AB_MCP_Sync_Engine::is_ref( null ) );
		// A field of a shared row: the bytes PHP stores it with, none apart.
		self::assertNotSame( AB_MCP_Sync_Shared_Row::hash( AB_MCP_Sync_Shared_Row::field( 5 ) ), AB_MCP_Sync_Shared_Row::hash( AB_MCP_Sync_Shared_Row::field( '5' ) ) );
		self::assertNotSame( AB_MCP_Sync_Shared_Row::hash( null ), AB_MCP_Sync_Shared_Row::hash( '' ) );
	}

	public function testThePartsOfAStateOfSharedRowsAreEveryFieldAndThePartsOfAKindGoBackInItsOrder(): void {
		$names = new \ReflectionMethod( AB_MCP_Sync_Engine::class, 'part_names' );
		self::assertSame(
			array( 'row', 'meta:k', 'location:primary', 'mods:rest' ),
			$names->invoke(
				null,
				array(
					'row'   => array(),
					'meta'  => array( array( 'key' => 'k' ) ),
					'terms' => array(),
					'parts' => array( 'location:primary' => 'self', 'mods:rest' => 'a:0:{}' ),
				)
			),
			'Every field of the state is a part, whether the plan names it or not (decision 24).'
		);
		self::assertSame( array( 'row', 'meta:', 'location:' ), AB_MCP_Sync_Menu_Writer::PART_ORDER );
		self::assertSame( array( 'instance:', 'row' ), AB_MCP_Sync_Widget_Area_Writer::PART_ORDER );
	}

	/* ------------------------------------------------------------ option */

	private static function option_line( string $name, string $value, array $over = array() ): array {
		return array_merge(
			array(
				'kind'     => 'option',
				'ref'      => $name,
				'identity' => array( 'name' => $name ),
				'locks'    => array(),
				'hash'     => str_repeat( 'a', 64 ),
				'form'     => array( 'name' => $name, 'value' => $value ),
				'content'  => array(
					'meta'  => array(),
					'row'   => array( 'value' => $value ),
					'terms' => array(),
				),
			),
			$over
		);
	}

	private static function item( string $op, string $live = '' ): array {
		return array(
			'op'       => $op,
			'live_ref' => 'update' === $op ? $live : null,
			'as_draft' => false,
			'pair_id'  => null,
		);
	}

	public function testACoreOptionIsWrittenWithItsValueInTheIdsOfThisSiteAndAnyOtherIsLocked(): void {
		$prepared = AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'page_on_front', '12' ), self::item( 'update', 'page_on_front' ), self::ctx( array( 'post' => array( 12 => 34 ) ) ) );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( '34', array( 'value' => '34' ) ), array( $prepared['value'], $prepared['columns'] ), 'The page by its pair.' );
		self::assertSame( 'option:reference_unmapped', self::lock_of( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'page_on_front', '12' ), self::item( 'update', 'page_on_front' ), self::ctx() ) ) );
		self::assertIsArray( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'page_on_front', '12' ), self::item( 'update', 'page_on_front' ), self::ctx( array(), array( 'post' => array( 12 => true ) ) ) ), 'A page the run makes first is pending.' );
		$sticky = AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'sticky_posts', serialize( array( 12, '13' ) ) ), self::item( 'update', 'sticky_posts' ), self::ctx( array( 'post' => array( 12 => 40, 13 => 41 ) ) ) );
		self::assertSame( array( 40, '41' ), $sticky['value'], 'The list with the types of its items.' );
		self::assertSame( 'option:create', self::lock_of( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'blogname', 'A' ), self::item( 'create' ), self::ctx() ) ), 'An option is not made by a run.' );
		self::assertSame( 'option:unclassified', self::lock_of( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'acme_settings', 'x' ), self::item( 'update', 'acme_settings' ), self::ctx() ) ), 'An option of a plugin.' );
		self::assertSame( 'option:unclassified', self::lock_of( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'blogname', 'A' ), self::item( 'update', 'blogdescription' ), self::ctx() ) ), 'Under its own name only.' );
		self::assertSame( 'post:content_missing', self::lock_of( AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'blogname', 'A', array( 'content' => array() ) ), self::item( 'update', 'blogname' ), self::ctx() ) ) );
	}

	public function testTheChecksOfAnOptionAskTheRightTheRowTheSchemaAndTheSanitizerAhead(): void {
		self::db()->option_rows['blogname']       = 'Old';
		self::db()->option_rows['posts_per_page'] = '10';
		$name     = AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'blogname', 'New' ), self::item( 'update', 'blogname' ), self::ctx() );
		self::assertTrue( AB_MCP_Sync_Option_Writer::precheck( $name, 'blogname', 0 ) );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'manage_options' !== $cap;
		self::assertSame( 'ab_mcp_sync_rights', AB_MCP_Sync_Option_Writer::precheck( $name, 'blogname', 0 )->get_error_code() );
		$GLOBALS['ab_test_can']             = static fn( string $cap, array $args ): bool => true;
		$GLOBALS['ab_test_sanitize_option'] = static fn( $option, $value ) => 'blogname' === $option ? esc_html( (string) $value ) . '!' : $value;
		self::assertSame( 'option:sanitizer', self::lock_of( AB_MCP_Sync_Option_Writer::precheck( $name, 'blogname', 0 ) ) );
		$GLOBALS['ab_test_sanitize_option'] = null;
		$off = AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'posts_per_page', 'many' ), self::item( 'update', 'posts_per_page' ), self::ctx() );
		self::assertSame( 'option:off_schema', self::lock_of( AB_MCP_Sync_Option_Writer::precheck( $off, 'posts_per_page', 0 ) ) );
		unset( self::db()->option_rows['blogname'] );
		self::assertSame( 'option:create', self::lock_of( AB_MCP_Sync_Option_Writer::precheck( $name, 'blogname', 0 ) ), 'An option not here is not made.' );
		// The one part: the row, its target the bytes update_option() stores.
		self::db()->option_rows['blogname'] = 'Old';
		$parts                              = AB_MCP_Sync_Option_Writer::journal_parts( $name, AB_MCP_Sync_Option_Writer::current( 'blogname' ) );
		self::assertSame( array( 'row' ), array_column( $parts, 'part' ) );
		self::assertSame( AB_MCP_Sync_Shared_Row::hash( 'New' ), $parts[0]['target'] );
		self::assertSame( AB_MCP_Sync_Shared_Row::hash( 'Old' ), $parts[0]['before'] );
		self::assertSame( array( 'options' => array( 'blogname' ) ), array_intersect_key( AB_MCP_Sync_Option_Writer::guard_of( 'blogname', 'blogname' ), array( 'options' => true ) ), 'The takeover lock covers the option (pre_update_option, decision 19).' );
	}

	/* --------------------------------------------------------------- CSS */

	private static function css_line( string $css, array $row = array() ): array {
		return array(
			'kind'     => 'custom_css',
			'ref'      => 'twentytwentyfive',
			'identity' => array( 'stylesheet' => 'twentytwentyfive', 'post' => 7, 'linked' => true ),
			'locks'    => array(),
			'hash'     => str_repeat( 'b', 64 ),
			'form'     => array( 'stylesheet' => 'twentytwentyfive', 'css' => $css, 'status' => 'publish' ),
			'content'  => array(
				'meta'  => array(),
				'row'   => $row + array(
					'post_content'  => $css,
					'post_name'     => 'twentytwentyfive',
					'post_status'   => 'publish',
					'preprocessed'  => '',
					'theme_version' => '1.2',
				),
				'terms' => array(),
			),
		);
	}

	/** The CSS post 7 of this site, linked to the active stylesheet. */
	private static function css_here( string $status = 'publish' ): void {
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'custom_css_post_id' => 7 ) );
		self::db()->post_rows[7]                                = array( 'post_type' => 'custom_css', 'post_name' => 'twentytwentyfive', 'post_title' => 'twentytwentyfive', 'post_status' => $status, 'post_content' => 'body{}' );
	}

	public function testOnlyTheLinkedCssOfTheActiveThemeInTheSameVersionIsChangedAndItsRuleIsAskedAhead(): void {
		self::css_here();
		$item     = self::item( 'update', 'twentytwentyfive' );
		$prepared = AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{color:red}' ), $item, self::ctx() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 ) );
		self::assertSame( array( 'row' ), array_column( AB_MCP_Sync_Css_Writer::journal_parts( $prepared, AB_MCP_Sync_Css_Writer::current( 'twentytwentyfive' ) ), 'part' ) );
		// edit_css, as the Customizer asks it.
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_css' !== $cap;
		self::assertSame( 'ab_mcp_sync_rights', AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 )->get_error_code() );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		// The rule of WP_Customize_Custom_CSS_Setting::validate().
		$markup = AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{} </style><script>' ), $item, self::ctx() );
		self::assertSame( 'custom_css:markup', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( $markup, 'twentytwentyfive', 0 ) ) );
		self::assertSame( 'custom_css:markup', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{} </sty' ), $item, self::ctx() ), 'twentytwentyfive', 0 ) ), 'Nor an end in a part of one.' );
		// A filter the core saves the CSS with.
		$GLOBALS['ab_test_filters']['update_custom_css_data'][] = array( 'fn' => static fn( $data ) => array( 'css' => $data['css'] . '/* x */', 'preprocessed' => '' ) );
		self::assertSame( 'custom_css:markup', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 ) ) );
		$GLOBALS['ab_test_filters'] = array();
		// The theme: the active one, in the same version.
		$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfour';
		self::assertSame( 'custom_css:theme_mismatch', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 ) ) );
		$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfive';
		self::assertSame( 'custom_css:theme_mismatch', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{}', array( 'theme_version' => '1.3' ) ), $item, self::ctx() ), 'twentytwentyfive', 0 ) ) );
		// Only what is there: linked, not in the trash; none is made.
		self::css_here( 'trash' );
		self::assertSame( 'custom_css:create', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 ) ) );
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'custom_css_post_id' => -1 ) );
		self::assertSame( 'custom_css:create', self::lock_of( AB_MCP_Sync_Css_Writer::precheck( $prepared, 'twentytwentyfive', 0 ) ) );
		self::assertSame( 'custom_css:create', self::lock_of( AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{}' ), self::item( 'create' ), self::ctx() ) ) );
		// What the engine does not write as it is.
		self::assertSame( 'custom_css:field_rule', self::lock_of( AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{}', array( 'preprocessed' => '1' ) ), $item, self::ctx() ) ) );
		self::assertSame( 'custom_css:field_rule', self::lock_of( AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{}', array( 'post_status' => 'draft' ) ), $item, self::ctx() ) ) );
		self::assertSame( 'custom_css:reference_unmapped', self::lock_of( AB_MCP_Sync_Css_Writer::target_of( self::css_line( '.a{background:url(https://staging.test/?attachment_id=12)}' ), $item, self::ctx() ) ) );
	}

	/* ------------------------------------------------------------ menus */

	private static function menu_line( array $locations, array $row = array(), array $meta = array() ): array {
		return array(
			'kind'     => 'menu',
			'ref'      => '5',
			'identity' => array( 'taxonomy' => 'nav_menu', 'slug' => 'main' ),
			'locks'    => array(),
			'hash'     => str_repeat( 'c', 64 ),
			'form'     => array( 'row' => array(), 'meta' => array(), 'locations' => array( '~set' => $locations ) ),
			'content'  => array(
				'meta'  => $meta,
				'row'   => $row + array( 'name' => 'Main', 'slug' => 'main', 'term_group' => '0', 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => '0' ),
				'terms' => array(),
			),
		);
	}

	public function testAMenuIsATermOfNavMenuWithItsPositionsFromItsFormAndWhatTheMenuScreenWouldNotWriteIsLocked(): void {
		$prepared = AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array( 'primary' ) ), self::item( 'create' ), self::ctx() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( 'nav_menu', array( 'primary' ), 'main' ), array( $prepared['type'], $prepared['locations'], $prepared['args']['slug'] ) );
		self::assertSame( 'term:term_group', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array(), array( 'term_group' => '2' ) ), self::item( 'create' ), self::ctx() ) ) );
		self::assertSame( 'term:parent', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array(), array( 'parent' => '3' ) ), self::item( 'create' ), self::ctx() ) ) );
		self::assertSame( 'term:create_with_meta', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array(), array(), array( array( 'k', 'v' ) ) ), self::item( 'create' ), self::ctx() ) ) );
		$line                         = self::menu_line( array() );
		$line['identity']['taxonomy'] = 'category';
		self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( $line, self::item( 'create' ), self::ctx() ) ) );
		$line = self::menu_line( array() );
		unset( $line['form']['locations'] );
		self::assertSame( 'post:content_missing', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( $line, self::item( 'create' ), self::ctx() ) ) );
		// Only positions the active theme registers, and edit_theme_options.
		$GLOBALS['ab_test_way']['taxonomies']['nav_menu']      = (object) array( 'cap' => (object) array( 'manage_terms' => 'edit_theme_options', 'edit_terms' => 'edit_theme_options', 'assign_terms' => 'edit_theme_options' ) );
		self::db()->option_rows['stylesheet']                  = 'twentytwentyfive';
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 3 ) ) );
		$GLOBALS['ab_test_nav_menus']                          = array();
		self::assertSame( 'menu:location_unregistered', self::lock_of( AB_MCP_Sync_Menu_Writer::precheck( $prepared, null, 0 ) ) );
		$GLOBALS['ab_test_nav_menus'] = array( 'primary' => 'Primary' );
		$GLOBALS['ab_test_can']       = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
		self::assertSame( 'ab_mcp_sync_rights', AB_MCP_Sync_Menu_Writer::precheck( $prepared, null, 0 )->get_error_code() );
		unset( $GLOBALS['ab_test_way']['taxonomies']['nav_menu'] );
	}

	public function testThePositionsOfAMenuAreFieldsOfTheThemeModsThatNameTheMenuItselfAsSelf(): void {
		self::db()->option_rows['stylesheet']                  = 'twentytwentyfive';
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize(
			array(
				'nav_menu_locations' => array( 'primary' => 3, 'footer' => 5 ),
				'header_textcolor'   => '333333',
			)
		);
		self::db()->term_rows[5] = array( 'term_id' => '5', 'name' => 'Main', 'slug' => 'main', 'term_group' => '0', 'term_taxonomy_id' => '9', 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => '0', 'count' => '0' );
		$current                 = AB_MCP_Sync_Menu_Writer::current( 5 );
		self::assertIsArray( $current, is_wp_error( $current ) ? $current->get_error_message() : '' );
		self::assertSame( array( 'location:primary' => AB_MCP_Sync_Shared_Row::field( 3 ), 'location:footer' => AB_MCP_Sync_Menu_Writer::SELF, 'mods:rest' => AB_MCP_Sync_Shared_Row::field( array( 'header_textcolor' => '333333' ) ) ), $current['parts'] );
		// The target names primary: it becomes self; footer, held by the menu
		// and not named, is unset; a position equal on both is no part.
		$prepared = AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array( 'primary' ) ), self::item( 'update', '5' ), self::ctx() );
		$parts    = array();
		foreach ( AB_MCP_Sync_Menu_Writer::journal_parts( $prepared, $current ) as $part ) {
			$parts[ $part['part'] ] = $part['target'];
		}
		self::assertSame( array( 'row', 'location:footer', 'location:primary' ), array_keys( $parts ) );
		self::assertSame( AB_MCP_Sync_Shared_Row::hash( AB_MCP_Sync_Menu_Writer::SELF ), $parts['location:primary'] );
		self::assertSame( AB_MCP_Sync_Shared_Row::hash( null ), $parts['location:footer'], 'Unset, as the menu screen leaves a position it unchecks.' );
		self::assertTrue( AB_MCP_Sync_Menu_Writer::write_part( 5, 'location:primary', $prepared, $current ) );
		self::assertSame( array( 'primary' => 5, 'footer' => 5 ), $GLOBALS['ab_test_theme_mods']['nav_menu_locations'], 'Through set_theme_mod(), the id of the menu.' );
		self::assertTrue( AB_MCP_Sync_Menu_Writer::write_part( 5, 'location:footer', $prepared, $current ) );
		self::assertSame( array( 'primary' => 3 ), $GLOBALS['ab_test_theme_mods']['nav_menu_locations'], 'The key unset (read from the row, as it is now).' );
		self::assertSame( array( 'theme_mods_twentytwentyfive' ), AB_MCP_Sync_Menu_Writer::guard_of( 5, 'nav_menu' )['options'] );
		// A new menu: the positions as they are now, named with the term call.
		self::assertSame( array( 'location:primary', 'location:footer', 'mods:rest' ), array_keys( AB_MCP_Sync_Menu_Writer::with_row( $prepared, null, null ) ) );
		self::assertSame( array(), AB_MCP_Sync_Menu_Writer::with_row( $prepared, 5, $current ) );
		self::assertTrue( AB_MCP_Sync_Menu_Writer::shared_part( 'location:primary' ) );
		self::assertFalse( AB_MCP_Sync_Menu_Writer::shared_part( 'row' ) );
	}

	/* ------------------------------------------------------- menu items */

	private static function item_line( array $meta_over = array(), array $row_over = array(), array $terms = array( 5 ) ): array {
		$meta = array_merge(
			array(
				'_menu_item_type'             => 'custom',
				'_menu_item_menu_item_parent' => '0',
				'_menu_item_object_id'        => '21',
				'_menu_item_object'           => 'custom',
				'_menu_item_target'           => '',
				'_menu_item_classes'          => serialize( array( '' ) ),
				'_menu_item_xfn'              => '',
				'_menu_item_url'              => 'https://example.test/shop/',
			),
			$meta_over
		);
		$rows = array();
		foreach ( $meta as $key => $value ) {
			foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
				$rows[] = array( $key, $one );
			}
		}
		return array(
			'kind'     => 'menu_item',
			'ref'      => '21',
			'identity' => array(),
			'locks'    => array(),
			'hash'     => str_repeat( 'd', 64 ),
			'form'     => array( 'row' => array() ),
			'content'  => array(
				'meta'  => $rows,
				'row'   => $row_over + array( 'post_title' => 'Shop', 'post_excerpt' => '', 'post_content' => '', 'post_status' => 'publish', 'menu_order' => '2', 'post_parent' => '0', 'post_type' => 'nav_menu_item' ),
				'terms' => array_map( static fn( $id ) => array( 'name' => 'Main', 'slug' => 'main', 'taxonomy' => 'nav_menu', 'term_id' => $id, 'term_order' => 0 ), $terms ),
			),
		);
	}

	public function testAMenuItemIsWrittenAsTheMenuScreenWritesItAndWhatItWouldNotWriteIsLocked(): void {
		$ctx      = self::ctx( array( 'term' => array( 5 => 50 ) ) );
		$prepared = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line(), self::item( 'create' ), $ctx );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( 50, AB_MCP_Sync_Menu_Item_Writer::SELF, 'custom', 0 ), array( $prepared['menu'], $prepared['core']['_menu_item_object_id'], $prepared['fields']['menu-item-type'], $prepared['fields']['menu-item-object-id'] ), 'A custom link names itself, by the id the core gives it.' );
		self::assertSame( '', $prepared['fields']['menu-item-classes'] );
		// The core meta and the menu go with the row call; another key by its own.
		self::assertSame( array_merge( array_map( static fn( $k ) => 'meta:' . $k, AB_MCP_Sync_Menu_Item_Writer::CORE_META ), array( 'terms:nav_menu' ) ), array_keys( AB_MCP_Sync_Menu_Item_Writer::with_row( $prepared, null, null ) ) );
		self::assertTrue( AB_MCP_Sync_Menu_Item_Writer::through_row( $prepared, 'meta:_menu_item_url' ) );
		self::assertTrue( AB_MCP_Sync_Menu_Item_Writer::through_row( $prepared, 'terms:nav_menu' ) );
		self::assertFalse( AB_MCP_Sync_Menu_Item_Writer::through_row( $prepared, 'meta:_acme_icon' ) );
		// A shape the core does not write through the menu screen.
		foreach ( array(
			'a status'              => array( array(), array( 'post_status' => 'pending' ), array( 5 ) ),
			'no position'           => array( array(), array( 'menu_order' => '0' ), array( 5 ) ),
			'a type the core lacks' => array( array( '_menu_item_type' => 'acme' ), array(), array( 5 ) ),
			'a core key twice'      => array( array( '_menu_item_url' => array( 'a', 'b' ) ), array(), array( 5 ) ),
			'two menus'             => array( array(), array(), array( 5, 6 ) ),
			'no menu'               => array( array(), array(), array() ),
		) as $case => list( $meta, $row, $terms ) ) {
			self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( $meta, $row, $terms ), self::item( 'create' ), $ctx ) ), $case );
		}
		// References: the menu, the parent item, the object by their pairs.
		self::assertSame( 'menu:reference_unmapped', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line(), self::item( 'create' ), self::ctx() ) ), 'The menu.' );
		self::assertIsArray( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line(), self::item( 'create' ), self::ctx( array(), array( 'term' => array( 5 => true ) ) ) ), 'A menu the run makes first is pending.' );
		self::assertSame( 'menu:reference_unmapped', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_menu_item_parent' => '30' ) ), self::item( 'create' ), $ctx ) ), 'The parent item.' );
		$under = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_menu_item_parent' => '30' ) ), self::item( 'create' ), self::ctx( array( 'term' => array( 5 => 50 ) ), array( 'post' => array( 30 => true ) ) ) );
		self::assertTrue( $under['pending'], 'A parent item the run makes first is pending.' );
		self::assertSame( 'menu:reference_unmapped', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => '12', '_menu_item_url' => '' ) ), self::item( 'create' ), $ctx ) ), 'The page.' );
		$page = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => '12', '_menu_item_url' => '' ) ), self::item( 'create' ), self::ctx( array( 'term' => array( 5 => 50 ), 'post' => array( 12 => 120 ) ) ) );
		self::assertSame( array( '120', 120 ), array( $page['core']['_menu_item_object_id'], $page['fields']['menu-item-object-id'] ) );
	}

	public function testTheFieldsOfAMenuItemGoThroughWhatTheCoreWouldMakeOfThemBeforeAnythingIsWritten(): void {
		self::db()->term_rows[50] = array( 'term_id' => '50', 'name' => 'Main', 'slug' => 'main', 'term_group' => '0', 'term_taxonomy_id' => '9', 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => '0', 'count' => '0' );
		self::db()->post_rows[21]  = array( 'post_type' => 'nav_menu_item', 'post_title' => 'Shop', 'post_status' => 'publish', 'menu_order' => '2', 'post_parent' => '0' );
		$ctx                      = self::ctx( array( 'term' => array( 5 => 50 ), 'post' => array( 12 => 120 ) ) );
		$check                    = static fn( array $meta, array $row = array() ) => AB_MCP_Sync_Menu_Item_Writer::precheck( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( $meta, $row ), self::item( 'update', '21' ), $ctx ), 21, 0 );
		self::assertTrue( $check( array() ) );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_target' => 'Blank' ) ) ), 'sanitize_key() changes it.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_xfn' => 'friend me!' ) ) ), 'sanitize_html_class() changes it.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_classes' => serialize( array( 'a b' ) ) ) ) ), 'A class with a space is two classes to the core.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_url' => 'javascript:alert(1)' ) ) ), 'sanitize_url() drops it.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_object' => 'page' ) ) ), 'A custom link is of the object custom.' );
		// An item of a page: no address; the title is none where it is the page's.
		$page                     = array( '_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => '12', '_menu_item_url' => '' );
		self::db()->post_rows[120] = array( 'post_type' => 'page', 'post_title' => 'About', 'post_parent' => '7' );
		self::assertTrue( $check( $page, array( 'post_title' => 'Who we are' ) ) );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( $page, array( 'post_title' => 'About' ) ) ), 'The core stores the title of the object as none.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_url' => 'https://x.test/' ) + $page ) ), 'The core keeps no address for an item of a page.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( $page, array( 'post_title' => '', 'post_content' => '' ) ) ), 'A blank item gets a description of one space.' );
		$parent = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( $page, array( 'post_title' => 'Who we are' ) ), self::item( 'update', '21' ), $ctx );
		self::assertSame( '7', $parent['columns']['post_parent'], 'The parent the core takes from the page.' );
		// The rights: edit_theme_options, edit_post at the item, assign_term at the menu.
		foreach ( array( 'edit_theme_options', 'edit_post', 'assign_term' ) as $cap ) {
			$GLOBALS['ab_test_can'] = static fn( string $c, array $args ): bool => $c !== $cap;
			self::assertSame( 'ab_mcp_sync_rights', $check( array() )->get_error_code(), $cap );
		}
	}

	/* ------------------------------------------------------ widget areas */

	private static function area_line( array $instances ): array {
		$rows = array();
		foreach ( $instances as $id => $settings ) {
			$rows[] = array( $id, null === $settings ? '' : serialize( $settings ) );
		}
		return array(
			'kind'     => 'widget_area',
			'ref'      => 'sidebar-1',
			'identity' => array( 'area' => 'sidebar-1' ),
			'locks'    => array(),
			'hash'     => str_repeat( 'e', 64 ),
			'form'     => array( 'area' => 'sidebar-1', 'instances' => array() ),
			'content'  => array(
				'meta'  => $rows,
				'row'   => array( 'area' => 'sidebar-1' ),
				'terms' => array(),
			),
		);
	}

	/** The areas and the rows of text on this site; text registered. */
	private static function areas_here( array $sidebar, array $text, array $other = array() ): void {
		global $wp_widget_factory;
		$wp_widget_factory          = (object) array( 'widgets' => array( new \WP_Widget( 'text', 'Text' ) ) );
		self::db()->option_rows['sidebars_widgets'] = serialize( array( 'wp_inactive_widgets' => array(), 'sidebar-1' => $sidebar, 'sidebar-2' => $other, 'array_version' => 3 ) );
		self::db()->option_rows['widget_text']      = serialize( $text + array( '_multiwidget' => 1 ) );
	}

	public function testTheInstancesOfAnAreaAreOneByTheMappingOfThePairAndANewOneKeepsItsNumberWhereItIsFree(): void {
		$known = new \ReflectionMethod( AB_MCP_Sync_Widget_Area_Writer::class, 'known' );
		self::assertSame( array( 'text-2' => 'text-7', 'media_image-2' => 'media_image-3' ), $known->invoke( null, array( 'instances' => array( 'staging' => array( 'text-2', 'media_image-2' ), 'live' => array( 'text-7', 'media_image-3' ) ) ) ), 'By their place, the same widgets in the same order.' );
		self::assertSame( array( 'text-2' => null, 'media_image-2' => null ), $known->invoke( null, array( 'instances' => array( 'staging' => array( 'text-2', 'media_image-2' ), 'live' => array( 'media_image-3', 'text-7' ) ) ) ), 'Another order: not known.' );
		self::assertSame( array( 'text-2' => null ), $known->invoke( null, array( 'instances' => array( 'staging' => array( 'text-2' ), 'live' => array() ) ) ), 'Another count: not known.' );
		self::assertSame( array( 'text-2' => 'text-7', 'text-3' => 'text-9' ), $known->invoke( null, array( 'instances' => array( 'staging' => array( 'text-2' ), 'live' => array( 'text-7' ) ), 'map' => array( 'text-3' => 'text-9' ) ) ), 'The mapping a run made.' );
		$counterpart = new \ReflectionMethod( AB_MCP_Sync_Widget_Area_Writer::class, 'counterpart' );
		$state       = array(
			'parts'  => array( 'instance:text-2' => 'x', 'instance:text-7' => 'y', 'instance:text-8' => 'z' ),
			'placed' => array( 'text-2' => 'sidebar-1', 'text-8' => 'sidebar-2', 'text-9' => 'wp_inactive_widgets' ),
		);
		self::assertSame( 'text-2', $counterpart->invoke( null, 'text-5', 'text', 5, array( 'text-5' => 'text-2' ), $state, array(), 'sidebar-1' ) );
		self::assertSame( 'text-3', $counterpart->invoke( null, 'text-3', 'text', 3, array(), $state, array(), 'sidebar-1' ), 'New, its number of staging free here.' );
		self::assertSame( 'text-10', $counterpart->invoke( null, 'text-7', 'text', 7, array(), $state, array(), 'sidebar-1' ), 'New, its number taken here (a row, a list): the next.' );
		self::assertSame( 'text-10', $counterpart->invoke( null, 'text-9', 'text', 9, array(), $state, array(), 'sidebar-1' ), 'Placed in no row, but in a list.' );
		self::assertSame( 'widget_area:instance_unmapped', self::lock_of( $counterpart->invoke( null, 'text-5', 'text', 5, array( 'text-5' => null ), $state, array(), 'sidebar-1' ) ), 'Known at the base, no counterpart known.' );
		self::assertSame( 'widget_area:instance_unmapped', self::lock_of( $counterpart->invoke( null, 'text-5', 'text', 5, array( 'text-5' => 'text-8' ), $state, array(), 'sidebar-1' ) ), 'Its counterpart placed in another area here.' );
		self::assertSame( 'widget_area:instance_unmapped', self::lock_of( $counterpart->invoke( null, 'text-5', 'text', 5, array( 'text-5' => 'text-4' ), $state, array(), 'sidebar-1' ) ), 'Its counterpart no longer here.' );
		self::assertSame( 'widget_area:instance_unmapped', self::lock_of( $counterpart->invoke( null, 'text-5', 'text', 5, array( 'text-5' => 'media_image-2' ), $state, array(), 'sidebar-1' ) ), 'Of another widget.' );
	}

	public function testWhatAnAreaWouldLoseOrCannotTellIsLockedBeforeAnythingIsWritten(): void {
		if ( ! class_exists( 'WP_Widget', false ) ) {
			self::markTestSkipped( 'The unit suite runs without WP_Widget.' );
		}
		self::areas_here( array( 'text-2', 'text-5' ), array( 2 => array( 'title' => 'A' ), 5 => array( 'title' => 'E' ) ) );
		$item = self::item( 'update', 'sidebar-1' );
		self::assertSame( 'widget_area:remove_instance', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array( 'text-2' => array( 'title' => 'B' ) ) ), $item, self::ctx() ) ), 'text-5 here has no counterpart: writing would remove it.' );
		self::assertSame( 'widget_area:widget_unregistered', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array( 'acme-2' => array( 'x' => 1 ) ) ), $item, self::ctx() ) ) );
		self::assertSame( 'widget_area:widget_unregistered', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array( 'search' => null ) ), $item, self::ctx() ) ), 'A widget without a number.' );
		self::assertSame( 'widget_area:create', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array() ), self::item( 'create' ), self::ctx() ) ) );
		self::assertSame( 'widget_area:create', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array() ), self::item( 'update', 'sidebar-9' ), self::ctx() ) ) );
	}
}
