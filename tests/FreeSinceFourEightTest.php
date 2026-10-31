<?php
/**
 * wp_seo_set, which came with AlphaBridge MCP Pro only until 4.8.0 and is
 * free since: the SEO title, meta description and focus keyword of a post
 * in Yoast SEO or Rank Math.
 *
 * It is Pro's tool, so these checks are the ones that matter when code
 * changes hands: it is registered here with Pro's capability and flags, it
 * writes only the fields given, it asks for the right to the post and to
 * each SEO field before writing anything, and it refuses where neither
 * Yoast SEO nor Rank Math keeps the fields. Two things it does better than
 * Pro's copy did: a value goes to WordPress slashed, so a backslash stays,
 * and the variables of the SEO plugin (%%category%%) stay as written.
 *
 * The SEO plugin is told by its constant, which cannot be taken back in a
 * process, so the tests that need one run in a process of their own.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Seo;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/tools/class-tools-seo.php';
// Since 4.14.0 wp_seo_set writes through the meta undo (AB_MCP_Undo_Meta).
require_once __DIR__ . '/undo-stubs.php';

final class FreeSinceFourEightTest extends TestCase {

	private const YOAST = array(
		'title'         => '_yoast_wpseo_title',
		'description'   => '_yoast_wpseo_metadesc',
		'focus_keyword' => '_yoast_wpseo_focuskw',
	);

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_post( 12 );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'seo', 'SEO' );
		AB_MCP_Tools_Seo::register( $r );
		return $r;
	}

	/** The stored value of one meta key, as get_post_meta( $id, $key, true ) gives it. */
	private static function stored( string $key ) {
		return get_post_meta( 12, $key, true );
	}

	public function testItIsRegisteredAsProRegisteredIt(): void {
		$def = $this->registry()->get( 'wp_seo_set' );
		self::assertIsArray( $def );
		self::assertSame( 'seo', $def['group'] );
		self::assertSame( 'edit_posts', $def['capability'] );
		self::assertArrayNotHasKey( 'tier', $def, 'A tool of this plugin now.' );
		self::assertTrue( ! empty( $def['dangerous'] ), 'It keeps its flag.' );
		self::assertSame( array( 'post_id' ), $def['inputSchema']['required'] );
		self::assertSame( array( 'post_id', 'title', 'description', 'focus_keyword' ), array_keys( $def['inputSchema']['properties'] ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_open_world( 'wp_seo_set', $def ), 'SEO title and description show in search results.' );
		self::assertTrue( AB_MCP_Tool_Registry::is_destructive( 'wp_seo_set', $def ), 'It overwrites the stored values.' );
		self::assertFalse( AB_MCP_Tool_Registry::is_read_only( 'wp_seo_set', $def ) );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_seo_set', $def ), 'It waits for write access.' );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_seo_set', $def ), 'A content connection may set the fields of a post.' );
		self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_seo_set', $def ) );
	}

	public function testProRegisteringTheSameNameReplacesTheDefinition(): void {
		$r = $this->registry();
		$r->register( 'wp_seo_set', array( 'description' => 'Pro', 'capability' => 'edit_posts', 'tier' => 'pro', 'callback' => '__return_true' ) );
		self::assertSame( 'Pro', $r->get( 'wp_seo_set' )['description'] );
		self::assertSame( 1, count( array_filter( array_keys( $r->all() ), static fn( $n ) => 'wp_seo_set' === $n ) ) );
	}

	public function testAnUnknownPostIsNotFound(): void {
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 99, 'title' => 'x' ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_not_found', $res->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
	}

	public function testWithoutTheRightToThePostNothingIsWritten(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => 'edit_post' !== $cap;
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'x' ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( 'this specific post', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
	}

	public function testWithoutASupportedSeoPluginTheCallIsRefusedWithTheWay(): void {
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'x' ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_seo_unsupported', $res->get_error_code() );
		self::assertStringContainsString( 'Yoast SEO and Rank Math', $res->get_error_message() );
		self::assertStringContainsString( 'under Plugins', $res->get_error_message() );
		self::assertStringNotContainsString( 'wp_install_plugin', $res->get_error_message(), 'No tool of Pro is offered as the way.' );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWithYoastOnlyTheFieldsGivenAreWritten(): void {
		define( 'WPSEO_VERSION', '26.0' );
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'Fresh <b>bread</b>', 'description' => "Line one\nline two" ) );

		self::assertSame( array( 'updated' => true, 'provider' => 'yoast', 'changed' => array( 'title', 'description' ) ), array_intersect_key( $res, array_flip( array( 'updated', 'provider', 'changed' ) ) ) );
		// Since 4.14.0 the fields as stored, read again, and the undo outcome
		// (here no point: this test has no tables the undo can read).
		self::assertEquals( (object) array( 'title' => 'Fresh bread', 'description' => 'Line one line two' ), $res['saved_fields'] );
		self::assertArrayHasKey( 'undo', $res );
		self::assertNull( $res['undo'] );
		self::assertArrayHasKey( 'undo_note', $res );
		self::assertSame( 'Fresh bread', self::stored( self::YOAST['title'] ), 'Tags are stripped.' );
		self::assertSame( 'Line one line two', self::stored( self::YOAST['description'] ), 'Line breaks are stripped.' );
		self::assertSame( '', self::stored( self::YOAST['focus_keyword'] ), 'A field not given is not touched.' );
		self::assertSame( array( array( 12, self::YOAST['title'] ), array( 12, self::YOAST['description'] ) ), $GLOBALS['ab_test_meta_updated'] );

		$empty = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => '' ) );
		self::assertSame( array( 'title' ), $empty['changed'] );
		self::assertSame( '', self::stored( self::YOAST['title'] ), 'An empty string stores an empty value.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheVariablesOfTheSeoPluginStay(): void {
		define( 'WPSEO_VERSION', '26.0' );
		AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => '%%title%% %%sep%% %%category%% %%date%%', 'description' => '%category% %date% <script>x()</script>%%excerpt%%' ) );
		self::assertSame( '%%title%% %%sep%% %%category%% %%date%%', self::stored( self::YOAST['title'] ), 'sanitize_text_field() would make %%category%% into %tegory%%.' );
		self::assertSame( '%category% %date% %%excerpt%%', self::stored( self::YOAST['description'] ), 'A script goes with its content.' );

		AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => "Budget <50 CHF, 100%AB and x<3 \u{E000}", 'description' => 'Save 25% <em>today</em>' ) );
		self::assertSame( 'Budget &lt;50 CHF, 100%AB and x&lt;3', self::stored( self::YOAST['title'] ), 'A lone < is kept as &lt;, as sanitize_text_field() keeps it; a stray placeholder character is not turned into %.' );
		self::assertSame( 'Save 25% today', self::stored( self::YOAST['description'] ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testABackslashIsStoredAsWritten(): void {
		define( 'WPSEO_VERSION', '26.0' );
		AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'focus_keyword' => 'C:\\Docs' ) );
		self::assertSame( 'C:\\Docs', self::stored( self::YOAST['focus_keyword'] ), 'update_metadata() unslashes, so the value goes in slashed.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRankMathKeepsItsOwnKeys(): void {
		define( 'RANK_MATH_VERSION', '1.0.250' );
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'T', 'description' => 'D', 'focus_keyword' => 'K' ) );
		self::assertSame( 'rankmath', $res['provider'] );
		self::assertSame( 'T', self::stored( 'rank_math_title' ) );
		self::assertSame( 'D', self::stored( 'rank_math_description' ) );
		self::assertSame( 'K', self::stored( 'rank_math_focus_keyword' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAFieldTheAccountMayNotEditStopsTheWholeCall(): void {
		define( 'WPSEO_VERSION', '26.0' );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'edit_post_meta' === $cap && self::YOAST['description'] === ( $args[1] ?? '' ) );
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'T', 'description' => 'D' ) );

		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_forbidden_meta', $res->get_error_code() );
		self::assertStringContainsString( self::YOAST['description'], $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'], 'Asked before anything is written: the title stays too.' );

		$ok = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'T' ) );
		self::assertSame( array( 'title' ), $ok['changed'], 'A field not given is not asked for.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAioseoIsRefusedWithItsOwnPanelAsTheWay(): void {
		define( 'AIOSEO_VERSION', '4.9.0' );
		$res = AB_MCP_Tools_Seo::set_seo( array( 'post_id' => 12, 'title' => 'x' ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_seo_unsupported', $res->get_error_code() );
		self::assertStringContainsString( 'AIOSEO\'s panel', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
	}
}
