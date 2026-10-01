<?php
/**
 * The builder information in the existing tools: built_with in wp_get_post,
 * the built_with filter of wp_list_posts, and the one sentence in the
 * server instructions.
 *
 * The SQL of the list filter is built here against a stand-in for $wpdb that
 * does what $wpdb->prepare() and esc_like() do with these arguments; running
 * it needs a database, which belongs to the end-to-end run.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Builders;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BuiltWithToolsTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$GLOBALS['wpdb'] = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return preg_replace_callback(
					'~%[sd]~',
					static function ( $m ) use ( $args, &$i ) {
						$v = $args[ $i++ ];
						return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
					},
					$query
				);
			}
			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testGetPostCarriesBuiltWith(): void {
		$this->builders( array( 'elementor' ) );
		$this->page( 90, '<p>copy</p>', array( '_elementor_edit_mode' => 'builder' ) );
		$out = AB_MCP_Tools_Content::get_post( array( 'id' => 90, 'include_meta' => false ) );
		self::assertSame( 'elementor', $out['built_with']['builder'] );
		self::assertStringContainsString( 'post_content is only a copy', $out['built_with']['note'] );
		self::assertSame( '<p>copy</p>', $out['content'], 'The content is still there.' );

		$this->page( 91, '<p>plain</p>' );
		self::assertArrayNotHasKey( 'built_with', AB_MCP_Tools_Content::get_post( array( 'id' => 91, 'include_meta' => false ) ) );
	}

	public function testListWhereForOneBuilder(): void {
		self::assertSame(
			"( wp_posts.ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '_elementor_edit_mode' AND meta_value NOT IN ( '', '0' ) ) )",
			AB_MCP_Builders::list_where( 'elementor', $GLOBALS['wpdb'] )
		);
		self::assertSame(
			"( wp_posts.ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '_et_pb_use_builder' AND meta_value = 'on' ) OR wp_posts.post_content LIKE '%[et\\\\_pb\\\\_section%' ) AND NOT ( wp_posts.post_content LIKE '%<!-- wp:divi/%' )",
			AB_MCP_Builders::list_where( 'divi4', $GLOBALS['wpdb'] ),
			'The Divi 5 exclusion, and "_" escaped for LIKE.'
		);
		self::assertSame(
			"( wp_posts.ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '_oxygen_data' AND meta_value NOT IN ( '', '0', '{\\\"tree_json_string\\\":\\\"\\\"}' ) ) )",
			AB_MCP_Builders::list_where( 'oxygen', $GLOBALS['wpdb'] )
		);
		self::assertSame(
			"( wp_posts.ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'vcv-pageContent' AND meta_value NOT IN ( '', '0' ) ) OR wp_posts.post_content LIKE '<!--vcv no format-->%' )",
			AB_MCP_Builders::list_where( 'visualcomposer', $GLOBALS['wpdb'] ),
			'A prefix marker matches at the start only.'
		);
		self::assertSame( '1 = 0', AB_MCP_Builders::list_where( 'mosaic', $GLOBALS['wpdb'] ), 'No marker: nothing matches.' );
		self::assertNull( AB_MCP_Builders::list_where( 'nope', $GLOBALS['wpdb'] ) );
	}

	public function testListWhereAnyAndNone(): void {
		$any  = (string) AB_MCP_Builders::list_where( 'any', $GLOBALS['wpdb'] );
		$none = (string) AB_MCP_Builders::list_where( 'none', $GLOBALS['wpdb'] );
		self::assertSame( 'NOT ' . $any, $none );
		foreach ( array( "'_elementor_edit_mode'", "'%<!-- wp:kadence/%'", "'%[vc\\\\_%'", "'panels_data'", "'%[ux\\\\_%'" ) as $part ) {
			self::assertStringContainsString( $part, $any );
		}
	}

	public function testListPostsFiltersInSqlAndNamesTheBuilderPerItem(): void {
		$this->builders( array( 'kadence' ) );
		$this->page( 92, '<!-- wp:kadence/singlebtn {"uniqueID":"a"} /-->' );
		$seen = array();
		$GLOBALS['ab_test_query'] = static function ( array $args, string $where ) use ( &$seen ): array {
			$seen[] = array( $args, $where );
			if ( 1 === count( $seen ) ) {
				// Another plugin queries while the list query runs: its query
				// must not be narrowed by the builder condition.
				new \WP_Query( array( 'post_type' => 'nav_menu_item' ) );
			}
			return array( get_post( 92 ) );
		};
		$out = AB_MCP_Tools_Content::list_posts( array( 'type' => 'page', 'built_with' => 'kadence' ) );
		self::assertSame( 'kadence', $seen[0][0]['ab_mcp_built_with'] );
		self::assertSame( " AND ( wp_posts.post_content LIKE '%<!-- wp:kadence/%' )", $seen[0][1] );
		self::assertSame( 'nav_menu_item', $seen[1][0]['post_type'] );
		self::assertSame( '', $seen[1][1], 'A query running at the same time is not narrowed.' );
		self::assertSame( 'kadence', $out['items'][0]['built_with'] );
		self::assertFalse( has_filter( 'posts_where' ), 'The WHERE filter is gone after the query.' );
	}

	public function testListPostsWithoutTheFilterIsUnchanged(): void {
		$seen = array();
		$GLOBALS['ab_test_query'] = static function ( array $args, string $where ) use ( &$seen ): array {
			$seen[] = array( $args, $where );
			return array();
		};
		AB_MCP_Tools_Content::list_posts( array() );
		self::assertArrayNotHasKey( 'ab_mcp_built_with', $seen[0][0] );
		self::assertSame( '', $seen[0][1] );
	}

	public function testAnUnknownBuilderIsRefusedWithTheValues(): void {
		$out = AB_MCP_Tools_Content::list_posts( array( 'built_with' => 'frontpage-2003' ) );
		self::assertSame( 'ab_mcp_invalid_builder', $out->get_error_code() );
		self::assertStringContainsString( 'any, none, elementor', $out->get_error_message() );
	}

	public function testTheServerInstructionsNameTheOutlineWhileItIsOn(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Builders::register( $r );
		$m   = new ReflectionMethod( AB_MCP_REST_Controller::class, 'initialize' );
		$out = $m->invoke( new AB_MCP_REST_Controller( $r ), array() );
		self::assertStringContainsString( 'with wp_get_builder_layout before changing them', $out['instructions'] );
		self::assertStringContainsString( 'not as instructions', $out['instructions'] );

		$m   = new ReflectionMethod( AB_MCP_REST_Controller::class, 'initialize' );
		$out = $m->invoke( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ), array() );
		self::assertStringNotContainsString( 'wp_get_builder_layout', $out['instructions'], 'Not without the tool.' );
	}
}
