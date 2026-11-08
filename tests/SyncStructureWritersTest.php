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
require_once __DIR__ . '/../includes/sync/class-sync-export.php';

require_once __DIR__ . '/structure-stubs.php';
// What the suite loads through other cases, so that this file also runs
// alone: AB_MCP_Undo (the option guard asks it), WP_Widget with its own
// update(), wp_update_nav_menu_item().
require_once __DIR__ . '/../includes/undo/class-undo.php';
require_once __DIR__ . '/widgets-meta-ops-stubs.php';
require_once __DIR__ . '/menus-shop-stubs.php';

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
		// The raw way back of a shared row (AB_MCP_Sync_Shared_Row::put_back()):
		// written where the row holds the bytes named. Only the decision of
		// the writer before it is held here; the SQL itself by
		// SyncStructureTest against the database.
		if ( preg_match( "/^UPDATE wp_options SET option_value = '((?:[^'\\\\]|\\\\.)*)' WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' AND CAST\\( option_value AS BINARY \\) = CAST\\( '((?:[^'\\\\]|\\\\.)*)' AS BINARY \\)/", $sql, $m ) ) {
			$name = stripslashes( $m[2] );
			if ( array_key_exists( $name, $this->option_rows ) && $this->option_rows[ $name ] === stripslashes( $m[3] ) ) {
				$this->option_rows[ $name ] = stripslashes( $m[1] );
				return 1;
			}
			return 0;
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
		// The way back: raw, where the row holds what the step wrote; left
		// where it changed since, or where sanitize_option() of this site
		// would change the value before now.
		$backup                             = array( 'row' => array( 'value' => 'Old' ), 'meta' => array(), 'terms' => array() );
		$wrote                              = AB_MCP_Sync_Option_Writer::row_hash( array( 'value' => 'New' ) );
		self::db()->option_rows['blogname'] = 'New';
		self::assertSame( 'restored', AB_MCP_Sync_Option_Writer::restore_part( 'row', 'blogname', $backup, $wrote, 0 )['state'] );
		self::assertSame( 'Old', self::db()->option_rows['blogname'] );
		self::db()->option_rows['blogname'] = 'By hand';
		self::assertSame( 'left', AB_MCP_Sync_Option_Writer::restore_part( 'row', 'blogname', $backup, $wrote, 0 )['state'], 'Changed since the step.' );
		self::assertSame( 'By hand', self::db()->option_rows['blogname'] );
		self::db()->option_rows['blogname'] = 'New';
		$GLOBALS['ab_test_sanitize_option'] = static fn( $option, $value ) => $value . '!';
		self::assertSame( 'left', AB_MCP_Sync_Option_Writer::restore_part( 'row', 'blogname', $backup, $wrote, 0 )['state'], 'sanitize_option() would change the value before.' );
		self::assertSame( 'New', self::db()->option_rows['blogname'] );
		// The right asked again right before the write (Codex round 1, A5).
		$GLOBALS['ab_test_sanitize_option'] = null;
		$GLOBALS['ab_test_can']             = static fn( string $cap, array $args ): bool => 'manage_options' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Option_Writer::restore_part( 'row', 'blogname', $backup, $wrote, 0 )['state'], 'Without manage_options.' );
		self::assertSame( 'New', self::db()->option_rows['blogname'] );
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
		// No revision deleted by the call (Codex round 1, A3): for this post,
		// where the site keeps a number, none; the filter goes again, also
		// after an error of the call.
		$post  = (object) array( 'ID' => 7 );
		$other = (object) array( 'ID' => 8 );
		self::assertSame( array( -1, 0, 3 ), \AB_MCP_Sync_Post_Writer::keeping_revisions( 7, static fn() => array( apply_filters( 'wp_revisions_to_keep', 3, $post ), apply_filters( 'wp_revisions_to_keep', 0, $post ), apply_filters( 'wp_revisions_to_keep', 3, $other ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_filters']['wp_revisions_to_keep'] ?? array(), 'The filter goes again.' );
		// The filter of the type the core asks after it (Codex round 2, A7):
		// a site's filter there (1) does not win over the call.
		$css   = (object) array( 'ID' => 7, 'post_type' => 'custom_css' );
		$limit = static fn( $num ) => 1;
		add_filter( 'wp_custom_css_revisions_to_keep', $limit, 10, 2 );
		self::assertSame( -1, \AB_MCP_Sync_Post_Writer::keeping_revisions( 7, static fn() => apply_filters( 'wp_custom_css_revisions_to_keep', apply_filters( 'wp_revisions_to_keep', 3, $css ), $css ) ) );
		self::assertSame( 1, apply_filters( 'wp_custom_css_revisions_to_keep', 3, $css ), 'Only within the call.' );
		remove_filter( 'wp_custom_css_revisions_to_keep', $limit, 10 );
		self::assertSame( array(), array_values( $GLOBALS['ab_test_filters']['wp_custom_css_revisions_to_keep'] ?? array() ), 'Its filter goes again.' );
		try {
			\AB_MCP_Sync_Post_Writer::keeping_revisions(
				7,
				static function () {
					throw new \RuntimeException( 'The call failed.' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		self::assertSame( array(), $GLOBALS['ab_test_filters']['wp_revisions_to_keep'] ?? array(), 'Also after an error.' );
		// The way back asks edit_css again right before the write (Codex
		// round 1, A5): without it nothing is handed to wp_update_post().
		self::css_here();
		$now                       = AB_MCP_Sync_Css_Writer::current( 'twentytwentyfive' );
		$backup                    = $now;
		$backup['row']['post_content'] = 'p{}';
		$GLOBALS['ab_test_updated'] = array();
		$GLOBALS['ab_test_can']     = static fn( string $cap, array $args ): bool => 'edit_css' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Css_Writer::restore_part( 'row', 'twentytwentyfive', $backup, AB_MCP_Sync_Css_Writer::row_hash( $now['row'] ), 0 )['state'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		// A hook of the right that allows and changes the CSS as it is asked
		// (Codex round 2, A6): the read after it sees the change, it is left.
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ): bool {
			if ( 'edit_css' === $cap ) {
				self::db()->post_rows[7]['post_content'] = 'body{color:red}';
			}
			return true;
		};
		self::assertSame( 'left', AB_MCP_Sync_Css_Writer::restore_part( 'row', 'twentytwentyfive', $backup, AB_MCP_Sync_Css_Writer::row_hash( $now['row'] ), 0 )['state'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
	}

	public function testTheFinishedContentOfAnObjectGoesThroughTheCredentialCheckOnceMore(): void {
		// Codex round 2 of branch 11, A8: what a kind appends to the content
		// after the check of its form (the version of the theme of the CSS,
		// the names of the menus of an item) is checked once more as the
		// content goes into the package; a credential there holds the object.
		$css = array(
			'kind'        => 'custom_css',
			'ref'         => 'twentytwentyfive',
			'locks'       => array(),
			'credentials' => array( 'b9' => array(), 'mixed' => array() ),
			'content'     => array(
				'meta'  => array(),
				'row'   => array( 'post_content' => 'p{color:red}', 'post_name' => 'twentytwentyfive', 'post_status' => 'publish', 'preprocessed' => '', 'theme_version' => '1.2' ),
				'terms' => array(),
			),
		);
		self::assertSame( $css, \AB_MCP_Sync_Export::checked_content( $css ), 'Clean: as it was.' );
		$version                                  = $css;
		$version['content']['row']['theme_version'] = 'password=synthetic-review-value';
		$held                                     = \AB_MCP_Sync_Export::checked_content( $version );
		self::assertSame( array( 'theme_version' ), array_column( $held['credentials']['mixed'], 'unit' ) );
		self::assertTrue( \AB_MCP_Sync_Export::credential_locked( $held['locks'] ), 'The version of the theme holds the CSS.' );
		// The content of the CSS read as CSS (an escape the CSS reader decodes).
		$escaped                                   = $css;
		$escaped['content']['row']['post_content'] = 'a{content:"\\70 assword=synthetic-review-value"}';
		self::assertNotSame( array(), \AB_MCP_Sync_Export::checked_content( $escaped )['credentials']['mixed'] );
		// Any field appended, wherever in the content it stands.
		foreach ( array(
			'a column of the row' => array( 'row', array( 'post_content' => 'p{}', 'appended' => 'api_key=synthetic-review-value' ) ),
			'a meta row'          => array( 'meta', array( array( 'note', 'password=synthetic-review-value' ) ) ),
			'the name of a term'  => array( 'terms', array( array( 'name' => 'Shop password=synthetic-review-value', 'slug' => 'shop', 'taxonomy' => 'nav_menu', 'term_id' => 5, 'term_order' => 0 ) ) ),
			'another part'        => array( 'more', array( 'x' => 'token=synthetic-review-value' ) ),
		) as $case => list( $part, $value ) ) {
			$object                      = $css;
			$object['kind']              = 'menu_item';
			$object['content'][ $part ]  = $value;
			$held                        = \AB_MCP_Sync_Export::checked_content( $object );
			self::assertNotSame( array(), $held['credentials']['mixed'], $case );
			self::assertTrue( \AB_MCP_Sync_Export::credential_locked( $held['locks'] ), $case );
		}
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
		self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array(), array( 'taxonomy' => 'category' ) ), self::item( 'create' ), self::ctx() ) ), 'A row of another taxonomy.' );
		foreach ( array( array( '' ), array( 7 ) ) as $named ) {
			self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( $named ), self::item( 'create' ), self::ctx() ) ), 'A position that is no name.' );
		}
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
		self::assertNotSame( AB_MCP_Sync_Shared_Row::hash( null ), AB_MCP_Sync_Shared_Row::hash( '' ), 'An absent field is not an empty one.' );
		// A position that names the menu here already is no part.
		$both = AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array( 'footer', 'primary' ) ), self::item( 'update', '5' ), self::ctx() );
		self::assertSame( array( 'row', 'location:primary' ), array_column( AB_MCP_Sync_Menu_Writer::journal_parts( $both, $current ), 'part' ), 'footer names the menu here already.' );
		// Asked again right before a position is set: one the theme registers.
		$GLOBALS['ab_test_nav_menus'] = array();
		self::assertSame( 'menu:location_unregistered', self::lock_of( AB_MCP_Sync_Menu_Writer::write_part( 5, 'location:primary', $prepared, $current ) ) );
		$GLOBALS['ab_test_nav_menus'] = array( 'primary' => 'Primary' );
		// The way back of a position: back where it holds what the step wrote
		// (self), left where it changed since.
		$wrote = AB_MCP_Sync_Shared_Row::hash( AB_MCP_Sync_Menu_Writer::SELF );
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 5, 'footer' => 5 ), 'header_textcolor' => '333333' ) );
		self::assertSame( 'restored', AB_MCP_Sync_Menu_Writer::restore_part( 'location:primary', 5, $current, $wrote, 0 )['state'] );
		self::assertSame( array( 'primary' => 3, 'footer' => 5 ), unserialize( self::db()->option_rows['theme_mods_twentytwentyfive'] )['nav_menu_locations'] );
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 9, 'footer' => 5 ), 'header_textcolor' => '333333' ) );
		self::assertSame( 'left', AB_MCP_Sync_Menu_Writer::restore_part( 'location:primary', 5, $current, $wrote, 0 )['state'], 'Changed since the step: left.' );
		self::assertSame( 9, unserialize( self::db()->option_rows['theme_mods_twentytwentyfive'] )['nav_menu_locations']['primary'] );
		// The right asked again right before the write (Codex round 1, A5).
		self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 5, 'footer' => 5 ), 'header_textcolor' => '333333' ) );
		$GLOBALS['ab_test_can']                                = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Menu_Writer::restore_part( 'location:primary', 5, $current, $wrote, 0 )['state'], 'Without edit_theme_options.' );
		self::assertSame( 5, unserialize( self::db()->option_rows['theme_mods_twentytwentyfive'] )['nav_menu_locations']['primary'] );
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
			'another post type'     => array( array(), array( 'post_type' => 'page' ), array( 5 ) ),
			'no position'           => array( array(), array( 'menu_order' => '0' ), array( 5 ) ),
			'a type the core lacks' => array( array( '_menu_item_type' => 'acme' ), array(), array( 5 ) ),
			'a core key twice'      => array( array( '_menu_item_url' => array( 'a', 'b' ) ), array(), array( 5 ) ),
			'two menus'             => array( array(), array(), array( 5, 6 ) ),
			'no menu'               => array( array(), array(), array() ),
		) as $case => list( $meta, $row, $terms ) ) {
			self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( $meta, $row, $terms ), self::item( 'create' ), $ctx ) ), $case );
		}
		$other                                    = self::item_line();
		$other['content']['terms'][0]['taxonomy'] = 'category';
		self::assertSame( 'menu:item_shape', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( $other, self::item( 'create' ), $ctx ) ), 'A term of another taxonomy.' );
		// The core writes the item it is given, with its dates here, or the
		// step stops (the stub of wp_update_nav_menu_item() answers 900).
		self::db()->post_rows[21]       = array( 'post_type' => 'nav_menu_item', 'post_date' => '2026-01-02 03:04:05', 'post_date_gmt' => '2026-01-02 02:04:05' );
		$GLOBALS['ab_test_menu_writes'] = array();
		$update                         = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array(), array( 'post_title' => 'Shop \\ Bar' ) ), self::item( 'update', '21' ), $ctx );
		self::assertSame( 'ab_mcp_sync_write_failed', AB_MCP_Sync_Menu_Item_Writer::write_row( $update, 21, AB_MCP_Sync_Menu_Item_Writer::current( 21 ) )->get_error_code() );
		self::assertSame( array( '2026-01-02 03:04:05', '2026-01-02 02:04:05' ), array( $GLOBALS['ab_test_menu_writes'][0][2]['menu-item-post-date'], $GLOBALS['ab_test_menu_writes'][0][2]['menu-item-post-date-gmt'] ), 'With the dates of the item here.' );
		self::assertSame( 'Shop \\\\ Bar', $GLOBALS['ab_test_menu_writes'][0][2]['menu-item-title'], 'Slashed, as the core unslashes what it is given.' );
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
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( $page, array( 'post_title' => 'About', 'post_content' => 'Our team' ) ) ), 'The core stores the title of the object as none.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( array( '_menu_item_url' => 'https://x.test/' ) + $page ) ), 'The core keeps no address for an item of a page.' );
		self::assertSame( 'menu:sanitizer', self::lock_of( $check( $page, array( 'post_title' => '', 'post_content' => '' ) ) ), 'A blank item gets a description of one space.' );
		foreach ( array( serialize( array( 'x' => 'a' ) ), serialize( array( 7 ) ) ) as $classes ) {
			self::assertSame( 'menu:sanitizer', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_classes' => $classes ) ), self::item( 'update', '21' ), $ctx ) ), 'The classes no list of names.' );
		}
		$own = self::ctx( array( 'term' => array( 5 => 50 ), 'post' => array( 30 => 21 ) ) );
		self::assertSame( 'menu:sanitizer', self::lock_of( AB_MCP_Sync_Menu_Item_Writer::precheck( AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( '_menu_item_menu_item_parent' => '30' ) ), self::item( 'update', '21' ), $own ), 21, 0 ) ), 'An item is not its own parent.' );
		// The way back asks the rights again right before the row (Codex
		// round 1, A5): without them nothing is handed to wp_update_post().
		$now                           = AB_MCP_Sync_Menu_Item_Writer::current( 21 );
		self::assertIsArray( $now );
		$backup                        = $now;
		$backup['row']['post_title']   = 'Shop before';
		$GLOBALS['ab_test_updated']    = array();
		$GLOBALS['ab_test_can']        = static fn( string $cap, array $args ): bool => 'edit_post' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Menu_Item_Writer::restore_part( 'row', 21, $backup, AB_MCP_Sync_Menu_Item_Writer::row_hash( $now['row'] ), 0 )['state'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		// A hook of the right that allows and changes the item as it is asked
		// (Codex round 2, A6): the last read sees it, it is left.
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ): bool {
			if ( 'edit_post' === $cap ) {
				self::db()->post_rows[21]['post_title'] = 'Changed by the hook';
			}
			return true;
		};
		$moved = AB_MCP_Sync_Menu_Item_Writer::restore_part( 'row', 21, $backup, AB_MCP_Sync_Menu_Item_Writer::row_hash( $now['row'] ), 0 );
		self::assertSame( 'left', $moved['state'], json_encode( $moved ) );
		self::assertStringContainsString( 'changed as it was put back', $moved['note'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		self::db()->post_rows[21]['post_title'] = 'Shop';
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		$parent = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( $page, array( 'post_title' => 'Who we are' ) ), self::item( 'update', '21' ), $ctx );
		self::assertSame( '7', $parent['columns']['post_parent'], 'The parent the core takes from the page.' );
		// The rights: edit_theme_options, edit_post at the item, assign_term at the menu.
		foreach ( array( 'edit_theme_options', 'edit_post', 'assign_term' ) as $cap ) {
			$GLOBALS['ab_test_can'] = static fn( string $c, array $args ): bool => $c !== $cap;
			self::assertSame( 'ab_mcp_sync_rights', $check( array() )->get_error_code(), $cap );
		}
	}

	/* ------------------------------------------- the one write of a step */

	public function testTheOneWriteOfAStepAsksTheRightThenReadsThenComparesThenCalls(): void {
		// Codex round 3 of branch 11, A6: one place for the order of every
		// write of a step with a call of the core, forward and back.
		$seen = array();
		$step = static function ( string $name, $answer ) use ( &$seen ) {
			return static function () use ( $name, $answer, &$seen ) {
				$seen[] = $name;
				return $answer;
			};
		};
		$match = static function ( $answer ) use ( &$seen ) {
			return static function ( $now ) use ( $answer, &$seen ) {
				$seen[] = 'matches ' . $now;
				return $answer;
			};
		};
		self::assertSame( 'called', \AB_MCP_Sync_Write::core( $step( 'rights', true ), $step( 'read', 'now' ), $match( true ), $step( 'call', 'called' ) ) );
		self::assertSame( array( 'rights', 'read', 'matches now', 'call' ), $seen, 'The rights, the last read, the comparison of that read, the call.' );
		$seen    = array();
		$refusal = \AB_MCP_Sync_Write::core( $step( 'rights', 'edit the CSS (edit_css)' ), $step( 'read', 'now' ), $match( true ), $step( 'call', 'called' ) );
		self::assertSame( array( 'ab_mcp_sync_rights', array( 'rights' ) ), array( $refusal->get_error_code(), $seen ), 'A right missing: nothing read, nothing called.' );
		self::assertStringContainsString( 'may not edit the CSS (edit_css)', $refusal->get_error_message() );
		$seen = array();
		self::assertSame( 'own', \AB_MCP_Sync_Write::core( $step( 'rights', new WP_Error( 'own', 'x' ) ), $step( 'read', 'now' ), $match( true ), $step( 'call', 'called' ) )->get_error_code(), 'The refusal of a writer as it is.' );
		$seen = array();
		self::assertSame( array( 'unread', array( 'rights', 'read' ) ), array( \AB_MCP_Sync_Write::core( $step( 'rights', true ), $step( 'read', new WP_Error( 'unread', 'x' ) ), $match( true ), $step( 'call', 'called' ) )->get_error_code(), $seen ), 'A read that fails: nothing compared, nothing called.' );
		foreach ( array( false, 1, 'yes' ) as $answer ) {
			$seen = array();
			self::assertSame( array( \AB_MCP_Sync_Write::CHANGED, array( 'rights', 'read', 'matches now' ) ), array( \AB_MCP_Sync_Write::core( $step( 'rights', true ), $step( 'read', 'now' ), $match( $answer ), $step( 'call', 'called' ) )->get_error_code(), $seen ), 'Another state (only true is the same): nothing called.' );
		}
		// Forward: a new object has no state to compare; one here without a
		// state the engine read is never written (fail closed).
		$seen = array();
		self::assertSame( array( 'called', array( 'rights', 'call' ) ), array( \AB_MCP_Sync_Write::forward( AB_MCP_Sync_Option_Writer::class, null, null, $step( 'rights', true ), $step( 'call', 'called' ) ), $seen ) );
		self::db()->option_rows['blogname'] = 'Old';
		$pre                                = AB_MCP_Sync_Option_Writer::current( 'blogname' );
		self::assertSame( 'called', \AB_MCP_Sync_Write::forward( AB_MCP_Sync_Option_Writer::class, 'blogname', $pre, $step( 'rights', true ), $step( 'call', 'called' ) ) );
		self::assertSame( \AB_MCP_Sync_Write::CHANGED, \AB_MCP_Sync_Write::forward( AB_MCP_Sync_Option_Writer::class, 'blogname', null, $step( 'rights', true ), $step( 'call', 'called' ) )->get_error_code(), 'No state read: not written.' );
		self::db()->option_rows['blogname'] = 'Other';
		self::assertSame( \AB_MCP_Sync_Write::CHANGED, \AB_MCP_Sync_Write::forward( AB_MCP_Sync_Option_Writer::class, 'blogname', $pre, $step( 'rights', true ), $step( 'call', 'called' ) )->get_error_code() );
		// Back: a refusal leaves the part as it is, with its note.
		self::assertSame( array( 'state' => 'left', 'note' => 'Changed.' ), \AB_MCP_Sync_Write::back( $step( 'rights', true ), $step( 'read', 'now' ), $match( false ), $step( 'call', 'called' ), 'Changed.' ) );
		$left = \AB_MCP_Sync_Write::back( $step( 'rights', 'edit the CSS (edit_css)' ), $step( 'read', 'now' ), $match( true ), $step( 'call', 'called' ), 'Changed.' );
		self::assertSame( 'left', $left['state'] );
		self::assertStringContainsString( 'may not edit the CSS (edit_css), asked again right before the write', $left['note'] );
		self::assertSame( 'failed', \AB_MCP_Sync_Write::back( $step( 'rights', true ), $step( 'read', 'now' ), $match( true ), $step( 'call', new WP_Error( 'failed', 'x' ) ), 'Changed.' )->get_error_code(), 'An error of the call as it is.' );
		// The same state, byte for byte: never two states that encode alike.
		self::assertTrue( \AB_MCP_Sync_Write::same( array( 'a' => '1' ), array( 'a' => '1' ) ) );
		self::assertFalse( \AB_MCP_Sync_Write::same( array( 'a' => '1' ), array( 'a' => 1 ) ) );
		self::assertFalse( \AB_MCP_Sync_Write::same( array( 'a' => 1, 'b' => 2 ), array( 'b' => 2, 'a' => 1 ) ) );
		self::assertFalse( \AB_MCP_Sync_Write::same( '', false ) );
	}

	public function testEveryWriterWritesNothingWhereAHookOfItsRightChangesItsOwnFieldRightBeforeTheCall(): void {
		// Codex round 3 of branch 11, A6: the hook of a right (map_meta_cap,
		// user_has_cap) that allows and changes the field the call is to
		// write. The writer reads the object after the rights and compares it
		// with the state the engine read right before the call: it differs,
		// nothing is written (the step deviates). Without the hook the same
		// call writes.
		$writes = static function ( string $case, callable $setup, callable $read, callable $change, callable $call, callable $written ): void {
			$setup();
			$pre                    = $read();
			$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $change ): bool {
				$change();
				return true;
			};
			$refused                = $call( $pre );
			$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
			self::assertInstanceOf( WP_Error::class, $refused, $case );
			self::assertSame( \AB_MCP_Sync_Write::CHANGED, $refused->get_error_code(), $case . ': ' . $refused->get_error_message() );
			self::assertFalse( $written(), $case . ': nothing written.' );
			$setup();
			$done = $call( $read() );
			self::assertFalse( is_wp_error( $done ) && \AB_MCP_Sync_Write::CHANGED === $done->get_error_code(), $case . ': without the hook.' );
			self::assertTrue( $written(), $case . ': written without the hook.' );
		};
		// A meta key of a post, an own key of an attachment.
		$writes(
			'a meta key of a post',
			static function (): void {
				self::db()->post_rows[12]    = array( 'post_type' => 'post', 'post_title' => 'Hello', 'post_status' => 'publish' );
				self::db()->meta[12]         = array( array( 'meta_id' => '1', 'meta_key' => 'colors', 'meta_value' => 'red' ) );
				$GLOBALS['ab_test_meta'][12] = array( 'colors' => array( 'red' ) );
			},
			static fn() => \AB_MCP_Sync_Post_Writer::current( 12 ),
			static function (): void {
				self::db()->meta[12][0]['meta_value'] = 'green';
			},
			static fn( $pre ) => \AB_MCP_Sync_Post_Writer::write_part( 12, 'meta:colors', array( 'meta' => array( 'colors' => array( 'blue' ) ) ), $pre ),
			static fn(): bool => array( 'red' ) !== $GLOBALS['ab_test_meta'][12]['colors']
		);
		$writes(
			'an own key of an attachment',
			static function (): void {
				self::db()->post_rows[8]    = array( 'post_type' => 'attachment', 'post_title' => 'Dog', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' );
				self::db()->meta[8]         = array( array( 'meta_id' => '4', 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => 'A dog' ) );
				$GLOBALS['ab_test_meta'][8] = array( '_wp_attachment_image_alt' => array( 'A dog' ) );
			},
			static fn() => \AB_MCP_Sync_Attachment_Writer::current( 8 ),
			static function (): void {
				self::db()->meta[8][0]['meta_value'] = 'A cat';
			},
			static fn( $pre ) => \AB_MCP_Sync_Attachment_Writer::write_part( 8, 'meta:_wp_attachment_image_alt', array( 'meta' => array( '_wp_attachment_image_alt' => array( 'A bird' ) ) ), $pre ),
			static fn(): bool => array( 'A dog' ) !== $GLOBALS['ab_test_meta'][8]['_wp_attachment_image_alt']
		);
		// The option.
		$option = AB_MCP_Sync_Option_Writer::target_of( self::option_line( 'blogname', 'New' ), self::item( 'update', 'blogname' ), self::ctx() );
		$writes(
			'the option',
			static function (): void {
				self::db()->option_rows['blogname'] = 'Old';
				$GLOBALS['ab_test_options']         = array();
			},
			static fn() => AB_MCP_Sync_Option_Writer::current( 'blogname' ),
			static function (): void {
				self::db()->option_rows['blogname'] = 'By the hook';
			},
			static fn( $pre ) => AB_MCP_Sync_Option_Writer::write_row( $option, 'blogname', $pre ),
			static fn(): bool => array_key_exists( 'blogname', $GLOBALS['ab_test_options'] )
		);
		// The CSS.
		self::css_here();
		$css = AB_MCP_Sync_Css_Writer::target_of( self::css_line( 'p{color:red}' ), self::item( 'update', 'twentytwentyfive' ), self::ctx() );
		$writes(
			'the CSS',
			static function (): void {
				self::css_here();
				$GLOBALS['ab_test_css_writes'] = array();
			},
			static fn() => AB_MCP_Sync_Css_Writer::current( 'twentytwentyfive' ),
			static function (): void {
				self::db()->post_rows[7]['post_content'] = 'body{color:blue}';
			},
			static fn( $pre ) => AB_MCP_Sync_Css_Writer::write_row( $css, 'twentytwentyfive', $pre ),
			static fn(): bool => array() !== $GLOBALS['ab_test_css_writes']
		);
		// The menu: its term and a position.
		$menu_here = static function (): void {
			self::db()->option_rows['stylesheet']                  = 'twentytwentyfive';
			self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 3 ) ) );
			self::db()->term_rows[5]                               = array( 'term_id' => '5', 'name' => 'Main', 'slug' => 'main', 'term_group' => '0', 'term_taxonomy_id' => '9', 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => '0', 'count' => '0' );
			$GLOBALS['ab_test_term_calls']                         = array();
			$GLOBALS['ab_test_theme_mods']                         = array();
		};
		$menu = AB_MCP_Sync_Menu_Writer::target_of( self::menu_line( array( 'primary' ), array( 'name' => 'Main menu' ) ), self::item( 'update', '5' ), self::ctx() );
		self::assertIsArray( $menu, is_wp_error( $menu ) ? $menu->get_error_message() : '' );
		$writes(
			'the term of the menu',
			$menu_here,
			static fn() => AB_MCP_Sync_Menu_Writer::current( 5 ),
			static function (): void {
				self::db()->term_rows[5]['name'] = 'By the hook';
			},
			static fn( $pre ) => AB_MCP_Sync_Menu_Writer::write_row( $menu, 5, $pre ),
			static fn(): bool => array() !== $GLOBALS['ab_test_term_calls']
		);
		$writes(
			'a position of the menu',
			$menu_here,
			static fn() => AB_MCP_Sync_Menu_Writer::current( 5 ),
			static function (): void {
				self::db()->option_rows['theme_mods_twentytwentyfive'] = serialize( array( 'nav_menu_locations' => array( 'primary' => 9 ) ) );
			},
			static fn( $pre ) => AB_MCP_Sync_Menu_Writer::write_part( 5, 'location:primary', $menu, $pre ),
			static fn(): bool => isset( $GLOBALS['ab_test_theme_mods']['nav_menu_locations'] )
		);
		// The menu item: its row and a meta key of its own call.
		$ctx       = self::ctx( array( 'term' => array( 5 => 50 ) ) );
		$item_here = static function (): void {
			self::db()->post_rows[21]       = array( 'post_type' => 'nav_menu_item', 'post_title' => 'Shop', 'post_status' => 'publish', 'menu_order' => '2', 'post_date' => '2026-01-02 03:04:05', 'post_date_gmt' => '2026-01-02 02:04:05' );
			self::db()->meta[21]            = array();
			$GLOBALS['ab_test_menu_writes'] = array();
			$GLOBALS['ab_test_meta'][21]    = array();
		};
		$item = AB_MCP_Sync_Menu_Item_Writer::target_of( self::item_line( array( 'acme_icon' => 'star' ), array( 'post_title' => 'Shop now' ) ), self::item( 'update', '21' ), $ctx );
		self::assertIsArray( $item, is_wp_error( $item ) ? $item->get_error_message() : '' );
		$writes(
			'the row of the menu item',
			$item_here,
			static fn() => AB_MCP_Sync_Menu_Item_Writer::current( 21 ),
			static function (): void {
				self::db()->post_rows[21]['post_title'] = 'By the hook';
			},
			static fn( $pre ) => AB_MCP_Sync_Menu_Item_Writer::write_row( $item, 21, $pre ),
			static fn(): bool => array() !== $GLOBALS['ab_test_menu_writes']
		);
		$writes(
			'a meta key of the menu item',
			$item_here,
			static fn() => AB_MCP_Sync_Menu_Item_Writer::current( 21 ),
			static function (): void {
				self::db()->meta[21] = array( array( 'meta_id' => '3', 'meta_key' => 'acme_icon', 'meta_value' => 'moon' ) );
			},
			static fn( $pre ) => AB_MCP_Sync_Menu_Item_Writer::write_part( 21, 'meta:acme_icon', $item, $pre ),
			static fn(): bool => array() !== ( $GLOBALS['ab_test_meta'][21]['acme_icon'] ?? array() )
		);
		// The list of a widget area (its instances: see the checks of an area).
		if ( class_exists( 'WP_Widget', false ) ) {
			$list = array( 'area' => 'sidebar-1', 'list' => array( 'text-5', 'text-2' ) );
			$writes(
				'the list of a widget area',
				static function (): void {
					self::areas_here( array( 'text-2', 'text-5' ), array( 2 => array( 'title' => 'B' ), 5 => array( 'title' => 'E' ) ) );
					$GLOBALS['ab_test_options'] = array();
				},
				static fn() => AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' ),
				static function (): void {
					self::db()->option_rows['sidebars_widgets'] = serialize( array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'text-5' ), 'sidebar-2' => array(), 'array_version' => 3 ) );
				},
				static fn( $pre ) => AB_MCP_Sync_Widget_Area_Writer::write_row( $list, 'sidebar-1', $pre ),
				static fn(): bool => array_key_exists( 'sidebars_widgets', $GLOBALS['ab_test_options'] )
			);
			unset( $GLOBALS['ab_test_widget_violation'] );
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
		self::assertSame( 'widget_area:widget_unregistered', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array( 'text-3' => null ) ), $item, self::ctx() ) ), 'An instance without its settings on the staging site.' );
		self::assertSame( 'widget_area:create', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array() ), self::item( 'create' ), self::ctx() ) ) );
		self::assertSame( 'widget_area:create', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::target_of( self::area_line( array() ), self::item( 'update', 'sidebar-9' ), self::ctx() ) ) );
	}

	public function testTheChecksOfAnAreaAskTheRightTheRowOfEachWidgetAndItsUpdateAhead(): void {
		if ( ! class_exists( 'WP_Widget', false ) ) {
			self::markTestSkipped( 'The unit suite runs without WP_Widget.' );
		}
		self::areas_here( array( 'text-2' ), array( 2 => array( 'title' => 'A' ) ) );
		$prepared = array(
			'area'   => 'sidebar-1',
			'writes' => array(
				'text-2' => array(
					'base'     => 'text',
					'number'   => 2,
					'settings' => array( 'title' => 'B', 'seen_number' => 2 ),
				),
			),
		);
		self::assertTrue( AB_MCP_Sync_Widget_Area_Writer::precheck( $prepared, 'sidebar-1', 0 ), 'As the update() of the widget keeps it (the stub notes its number).' );
		$tagged = $prepared;
		$tagged['writes']['text-2']['settings']['title'] = '<b>B</b>';
		self::assertSame( 'widget_area:sanitizer', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::precheck( $tagged, 'sidebar-1', 0 ) ), 'The update() of the widget strips the tags.' );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
		self::assertSame( 'ab_mcp_sync_rights', AB_MCP_Sync_Widget_Area_Writer::precheck( $prepared, 'sidebar-1', 0 )->get_error_code() );
		$GLOBALS['ab_test_can']                = static fn( string $cap, array $args ): bool => true;
		self::db()->option_rows['widget_text'] = serialize( array( 2 => array( 'title' => 'A' ) ) );
		self::assertSame( 'widget_area:widget_unregistered', self::lock_of( AB_MCP_Sync_Widget_Area_Writer::precheck( $prepared, 'sidebar-1', 0 ) ), 'A row without _multiwidget: not written by the widgets of WordPress.' );
		// Written as the widgets screen writes it: the settings through the
		// update() of the widget right before (the stub strips the tags and
		// notes the number), a fresh row marked as one of WordPress.
		unset( self::db()->option_rows['widget_text'] );
		$GLOBALS['ab_test_options'] = array();
		$fresh                      = array(
			'area'   => 'sidebar-1',
			'writes' => array(
				'text-3' => array(
					'base'     => 'text',
					'number'   => 3,
					'settings' => array( 'title' => '<b>C</b>' ),
				),
			),
		);
		self::assertTrue( AB_MCP_Sync_Widget_Area_Writer::write_part( 'sidebar-1', 'instance:text-3', $fresh, AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' ) ) );
		self::assertSame( array( 3 => array( 'title' => 'C', 'seen_number' => 3 ), '_multiwidget' => 1 ), $GLOBALS['ab_test_options']['widget_text'] );
		// A hook of the right that allows and changes the instance itself as
		// it is asked right before the write (Codex round 3, A6): the last
		// read differs from the state the engine read, nothing is written.
		self::db()->option_rows['widget_text'] = serialize( array( 3 => array( 'title' => 'C' ), '_multiwidget' => 1 ) );
		$GLOBALS['ab_test_options']            = array();
		$asked                                 = 0;
		$pre                                   = AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' );
		$GLOBALS['ab_test_can']                = static function ( string $cap, array $args ) use ( &$asked ): bool {
			if ( 'edit_theme_options' === $cap && 2 === ++$asked ) {
				self::db()->option_rows['widget_text'] = serialize( array( 3 => array( 'title' => 'Changed by the hook' ), '_multiwidget' => 1 ) );
			}
			return true;
		};
		self::assertSame( \AB_MCP_Sync_Write::CHANGED, AB_MCP_Sync_Widget_Area_Writer::write_part( 'sidebar-1', 'instance:text-3', $fresh, $pre )->get_error_code() );
		self::assertArrayNotHasKey( 'widget_text', $GLOBALS['ab_test_options'], 'Nothing written.' );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		// The right again after the update() of the widget and its filters
		// (Codex round 1, A5): a filter that takes it, nothing written.
		$GLOBALS['ab_test_options']                            = array();
		$GLOBALS['ab_test_filters']['widget_update_callback'][] = array(
			'fn' => static function ( $instance ) {
				$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
				return $instance;
			},
		);
		self::assertSame( 'ab_mcp_sync_rights', AB_MCP_Sync_Widget_Area_Writer::write_part( 'sidebar-1', 'instance:text-3', $fresh, AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' ) )->get_error_code() );
		self::assertArrayNotHasKey( 'widget_text', $GLOBALS['ab_test_options'] );
		$GLOBALS['ab_test_filters'] = array();
		$GLOBALS['ab_test_can']     = static fn( string $cap, array $args ): bool => true;
		// The way back asks it again right before a field goes back.
		self::areas_here( array( 'text-2' ), array( 2 => array( 'title' => 'B', 'seen_number' => 2 ) ) );
		$now                                  = AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' );
		$backup                               = $now;
		$backup['parts']['instance:text-2']   = AB_MCP_Sync_Shared_Row::field( array( 'title' => 'A', 'seen_number' => 2 ) );
		$wrote                                = AB_MCP_Sync_Shared_Row::hash( $now['parts']['instance:text-2'] );
		$row                                  = self::db()->option_rows['widget_text'];
		$GLOBALS['ab_test_can']               = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Widget_Area_Writer::restore_part( 'instance:text-2', 'sidebar-1', $backup, $wrote, 0 )['state'], 'Without edit_theme_options.' );
		self::assertSame( $row, self::db()->option_rows['widget_text'] );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		self::assertSame( 'restored', AB_MCP_Sync_Widget_Area_Writer::restore_part( 'instance:text-2', 'sidebar-1', $backup, $wrote, 0 )['state'], 'With it.' );
		// The list of the area as well.
		self::areas_here( array( 'text-2', 'text-5' ), array( 2 => array( 'title' => 'B' ), 5 => array( 'title' => 'E' ) ) );
		$now                    = AB_MCP_Sync_Widget_Area_Writer::current( 'sidebar-1' );
		$backup                 = $now;
		$backup['row']['area']  = AB_MCP_Sync_Shared_Row::field( array( 'text-5', 'text-2' ) );
		$wrote                  = AB_MCP_Sync_Shared_Row::hash( $now['row']['area'] );
		$areas                  = self::db()->option_rows['sidebars_widgets'];
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_theme_options' !== $cap;
		self::assertSame( 'left', AB_MCP_Sync_Widget_Area_Writer::restore_part( 'row', 'sidebar-1', $backup, $wrote, 0 )['state'], 'Without edit_theme_options.' );
		self::assertSame( $areas, self::db()->option_rows['sidebars_widgets'] );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		self::assertSame( 'restored', AB_MCP_Sync_Widget_Area_Writer::restore_part( 'row', 'sidebar-1', $backup, $wrote, 0 )['state'], 'With it.' );
		// After the writes: the copy of the areas the core keeps for the
		// request goes, as wp_set_sidebars_widgets() lets it go.
		$GLOBALS['_wp_sidebars_widgets'] = array( 'sidebar-1' => array( 'text-2' ) );
		self::assertContains( 'sidebars_widgets', AB_MCP_Sync_Widget_Area_Writer::post_write( 'sidebar-1' ) );
		self::assertNull( $GLOBALS['_wp_sidebars_widgets'] );
	}
}
