<?php
/**
 * The title a client is sent for a tool is its English short name.
 *
 * Until 23 October 2026 no definition set a title, so tools/list derived one
 * from the name: «Wc Update Order Status», «Db Execute», «Seo Set». The same
 * string went out as annotations.title. Readable short names already existed
 * for the fine-tuning (AB_MCP_Tool_Labels), but only translated into the
 * admin's language, and a client shows the title to whoever uses it next to an
 * English description. So the title is now the English source of that short
 * name, whatever language the request runs in.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tool_Labels;
use AB_MCP_Tool_Registry;

final class ToolTitlesTest extends TestCase {

	protected function setUp(): void {
		self::reset_cache();
		$GLOBALS['ab_test_translations'] = array(
			"tool label\x04Change an order's status" => 'Status einer Bestellung ändern',
			"tool label\x04List snippets"             => 'Snippets auflisten',
		);
	}

	protected function tearDown(): void {
		$GLOBALS['ab_test_translations'] = array();
		self::reset_cache();
	}

	private static function reset_cache(): void {
		$p = new \ReflectionProperty( AB_MCP_Tool_Labels::class, 'cache' );
		$p->setValue( null, array() );
	}

	/** Free tool names as registered in includes/tools. */
	private static function registered(): array {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			if ( preg_match_all( '/->register\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}

	public function test_the_fine_tuning_still_shows_the_translation(): void {
		// Counter-check: the translation is active here, so english() below
		// has to undo it, not merely find nothing to translate.
		$all = AB_MCP_Tool_Labels::all();
		$this->assertSame( 'Status einer Bestellung ändern', $all['wp_wc_update_order_status'] );
		$this->assertSame( 'Snippets auflisten', $all['wp_list_php_snippets'] );
	}

	public function test_english_names_are_the_source_text(): void {
		AB_MCP_Tool_Labels::all();
		$en = AB_MCP_Tool_Labels::english();
		$this->assertSame( "Change an order's status", $en['wp_wc_update_order_status'] );
		$this->assertSame( 'List snippets', $en['wp_list_php_snippets'] );
		$this->assertSame( array_keys( AB_MCP_Tool_Labels::all() ), array_keys( $en ), 'both lists name the same tools' );
	}

	public function test_english_leaves_no_filter_behind(): void {
		AB_MCP_Tool_Labels::english();
		$this->assertFalse( has_filter( 'gettext_with_context_alphabridge-mcp' ), 'the source-text filter stays only while the list is built' );
		self::reset_cache();
		$this->assertSame( 'Snippets auflisten', AB_MCP_Tool_Labels::all()['wp_list_php_snippets'], 'translations work again afterwards' );
	}

	public function test_title_prefers_the_definition_then_the_short_name(): void {
		$this->assertSame( 'Own title', AB_MCP_Tool_Labels::title( 'wp_list_posts', array( 'title' => 'Own title' ) ) );
		$this->assertSame( 'List posts and pages', AB_MCP_Tool_Labels::title( 'wp_list_posts', array( 'title' => 'wp_list_posts' ) ) );
		$this->assertSame( "Change an order's status", AB_MCP_Tool_Labels::title( 'wp_wc_update_order_status', array() ) );
		$this->assertSame( 'List snippets', AB_MCP_Tool_Labels::title( 'wp_list_php_snippets', array() ) );
	}

	public function test_a_tool_the_list_does_not_know_keeps_a_derived_title(): void {
		$this->assertSame( 'Foo Bar', AB_MCP_Tool_Labels::title( 'wp_foo_bar', array() ) );
		$this->assertSame( 'WC Foo', AB_MCP_Tool_Labels::title( 'wc_foo', array() ) );
	}

	public function test_every_free_tool_has_an_english_title(): void {
		$names = self::registered();
		$this->assertGreaterThan( 30, count( $names ), 'the tool list was not found' );
		$en = AB_MCP_Tool_Labels::english();
		foreach ( $names as $name ) {
			$this->assertArrayHasKey( $name, $en, "$name has no short name and would get a derived title" );
		}
	}

	public function test_tools_list_and_annotations_carry_the_short_name(): void {
		$controller = ( new \ReflectionClass( \AB_MCP_REST_Controller::class ) )->newInstanceWithoutConstructor();
		$title      = new \ReflectionMethod( \AB_MCP_REST_Controller::class, 'tool_title' );
		$annotate   = new \ReflectionMethod( \AB_MCP_REST_Controller::class, 'tool_annotations' );
		$def        = array( 'title' => 'wp_wc_update_order_status' ); // What register() stores by default.
		$this->assertSame( "Change an order's status", $title->invoke( $controller, 'wp_wc_update_order_status', $def ) );
		$this->assertSame( "Change an order's status", $annotate->invoke( $controller, 'wp_wc_update_order_status', $def )['title'] );
	}
}
