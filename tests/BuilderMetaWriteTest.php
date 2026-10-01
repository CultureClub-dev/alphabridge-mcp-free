<?php
/**
 * Page-builder data in the "meta" argument of wp_create_post and
 * wp_update_post. Only an account with unfiltered_html may write it, also
 * where a builder keeps it under a key without "_" (panels_data, dslc_code,
 * pagelayer-data …); for any other account the call is refused before
 * anything is written, and the answer names the ways that remain. Ordinary
 * keys are written as before, and "_" keys stay passed over as before.
 *
 * The keys come from the builder signatures (meta copies, locked_meta,
 * markup_meta), so a builder added there is guarded without a second list.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuilderMetaWriteTest extends TestCase {

	use BuilderTestHelpers;

	/**
	 * AB_MCP_Tools_Content_Pro::markup_meta_keys() of Pro, branch
	 * integration/pro-4.6 at 8958e1a (02.10.2026): the list the free one is
	 * to hold as well, so both editions guard the same keys.
	 */
	const PRO_KEYS = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_controls_usage',
		'_fl_builder_data',
		'_fl_builder_draft',
		'_fl_builder_data_settings',
		'_fl_builder_draft_settings',
		'panels_data',
		'dslc_code',
		'pagelayer-data',
		'pagelayer_header_code',
		'pagelayer_body_open_code',
		'pagelayer_footer_code',
		'brizy',
		'brizy-compiled-sections',
		'vcv-pageContent',
		'vcv-settingsLocalJs*',
		'vcvSettingsSourceCustomCss',
		'_themify_builder_settings_json',
		'tbp_custom_js',
		'tbp_custom_css',
		'_zionbuilder_page_elements',
		'mfn-page-items',
		'tve_updated_post',
		'tve_custom_css',
		'tve_user_custom_css',
		'_bricks_page_content_2',
		'_bricks_page_header_2',
		'_bricks_page_footer_2',
		'_bricks_page_settings',
		'_breakdance_data',
		'ct_builder_shortcodes',
		'ct_builder_json',
		'_oxygen_data',
		'_cornerstone_data',
		'_aviaLayoutBuilderCleanData',
		'_wpb_shortcodes_custom_css',
		'_wpb_post_custom_css',
		'_fusion_builder_custom_css',
	);

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_now']          = strtotime( '2026-10-01 12:00:00 UTC' );
		AB_MCP_Builders::reset();
	}

	protected function tearDown(): void {
		AB_MCP_Builders::reset();
	}

	/** May do everything but store unfiltered HTML: an editor on a multisite network, say. */
	private function withoutUnfilteredHtml(): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap ): bool {
			return 'unfiltered_html' !== $cap;
		};
	}

	private function sorted( array $keys ): array {
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Keys without "_" in which a builder keeps markup or code, as a caller
	 * would send them. The writers store them lower-cased (sanitize_key()).
	 *
	 * @return array<string,array{string}>
	 */
	public static function openBuilderKeys(): array {
		$out = array();
		foreach ( array( 'panels_data', 'dslc_code', 'pagelayer-data', 'pagelayer_header_code', 'pagelayer_footer_code', 'brizy', 'brizy-compiled-sections', 'vcv-pageContent', 'vcv-settingsLocalJsHead', 'vcvSettingsSourceCustomCss', 'tbp_custom_js', 'mfn-page-items', 'tve_updated_post', 'tve_custom_css', 'ct_builder_shortcodes', 'ct_builder_json' ) as $key ) {
			$out[ $key ] = array( $key );
		}
		return $out;
	}

	/* ------------------------------------------------------------- the list */

	public function testTheListHoldsTheKeysProGuards(): void {
		self::assertSame( $this->sorted( self::PRO_KEYS ), $this->sorted( AB_MCP_Builders::markup_meta_keys() ) );
	}

	public function testTheListComesFromTheSignatures(): void {
		add_filter(
			'ab_mcp_builder_signatures',
			static function ( $sigs ) {
				$sigs['acme'] = array(
					'name'        => 'Acme Builder',
					'markers'     => array( array( 'meta' => 'acme_on' ) ),
					'copies'      => array( array( 'loc' => 'meta:acme_layout', 'shown' => true, 'role' => 'source' ) ),
					'locked_meta' => array( 'acme_js*' ),
					'markup_meta' => array( 'acme_render_mode' ),
				);
				return $sigs;
			}
		);
		AB_MCP_Builders::reset();
		$keys = AB_MCP_Builders::markup_meta_keys();
		foreach ( array( 'acme_layout', 'acme_js*', 'acme_render_mode' ) as $key ) {
			self::assertContains( $key, $keys, $key . ': a meta copy, locked keys and further markup keys are all guarded.' );
		}
		self::assertNotContains( 'acme_on', $keys, 'A marker alone is no page data.' );
	}

	public function testLetterCaseAndPrefixes(): void {
		self::assertTrue( AB_MCP_Builders::is_markup_meta_key( 'vcv-pagecontent' ), 'The lower-cased key lands on the row "vcv-pageContent".' );
		self::assertTrue( AB_MCP_Builders::is_markup_meta_key( 'PANELS_DATA' ) );
		self::assertTrue( AB_MCP_Builders::is_markup_meta_key( 'vcv-settingslocaljsfooter' ), 'A prefix entry covers every key it starts.' );
		self::assertFalse( AB_MCP_Builders::is_markup_meta_key( 'panels_data_note' ), 'A plain entry is the whole key.' );
		self::assertFalse( AB_MCP_Builders::is_markup_meta_key( 'subtitle' ) );
		self::assertFalse( AB_MCP_Builders::is_markup_meta_key( '' ) );
		self::assertFalse( AB_MCP_Builders::is_markup_meta_key( 'subtitle', array( '*' ) ), 'A bare "*" guards nothing rather than everything.' );
	}

	/* ---------------------------------------------------------- the writers */

	#[DataProvider( 'openBuilderKeys' )]
	public function testCreateRefusesBuilderDataWithoutUnfilteredHtml( string $key ): void {
		$this->withoutUnfilteredHtml();
		$out = AB_MCP_Tools_Content::create_post(
			array(
				'type'  => 'page',
				'title' => 'Landing',
				'meta'  => array(
					'subtitle' => 'Hello',
					$key       => '<script>alert(1)</script>',
				),
			)
		);
		self::assertTrue( is_wp_error( $out ), $key );
		self::assertSame( 'ab_mcp_needs_unfiltered_html', $out->get_error_code() );
		self::assertSame( array( 'meta_key' => sanitize_key( $key ) ), $out->get_error_data() );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'], 'No post was created.' );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'], 'No meta was written, the ordinary key neither.' );
	}

	public function testTheRefusalNamesTheWays(): void {
		$this->withoutUnfilteredHtml();
		$msg = AB_MCP_Tools_Content::create_post( array( 'title' => 'Landing', 'meta' => array( 'panels_data' => array( 'widgets' => array() ) ) ) )->get_error_message();
		self::assertStringContainsString( 'Nothing was written', $msg );
		self::assertStringContainsString( '"panels_data"', $msg );
		self::assertStringContainsString( 'unfiltered_html', $msg, 'The capability it takes.' );
		self::assertStringContainsString( 'multisite network only super admins', $msg, 'Who has it.' );
		self::assertStringContainsString( 'Connect with such an account', $msg );
		self::assertStringContainsString( "builder's own editor", $msg );
		self::assertStringContainsString( 'without this key', $msg, 'The way to change the other fields.' );
		self::assertStringContainsString( 'ab_mcp_builder_markup_meta_keys', $msg, 'The way for a site owner.' );
	}

	public function testUpdateRefusesBuilderDataBeforeAnythingIsWritten(): void {
		$this->withoutUnfilteredHtml();
		$this->page( 40, '<p>Copy</p>', array( 'panels_data' => 'a:0:{}' ), array( 'post_title' => 'Old title' ) );

		$out = AB_MCP_Tools_Content::update_post(
			array(
				'id'    => 40,
				'title' => 'New title',
				'meta'  => array( 'dslc_code' => '<div onclick="x()">' ),
			)
		);
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_needs_unfiltered_html', $out->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_updated'], 'The title was not written either.' );
		self::assertSame( 'Old title', get_post( 40 )->post_title );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
		self::assertStringNotContainsString( 'Change its texts with', $out->get_error_message(), 'No tool on this site writes the page: none is named.' );
	}

	public function testUpdateNamesTheToolsThatChangeThePageTexts(): void {
		$this->withoutUnfilteredHtml();
		$this->page( 41, '<p>Copy</p>' );
		add_filter(
			'ab_mcp_builder_write_via',
			static function ( $tools ) {
				$tools[] = 'wp_update_builder_element';
				return $tools;
			}
		);
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 41, 'meta' => array( 'panels_data' => 'x' ) ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertStringContainsString( 'Change its texts with wp_update_builder_element.', $out->get_error_message() );
	}

	public function testAnAccountWithUnfilteredHtmlWritesBuilderData(): void {
		$this->mayDoAnything();
		$out = AB_MCP_Tools_Content::create_post( array( 'type' => 'page', 'title' => 'Landing', 'meta' => array( 'panels_data' => 'a:0:{}' ) ) );
		self::assertTrue( $out['created'] );
		$id = (int) $out['post']['id'];
		self::assertSame( array( array( $id, 'panels_data' ) ), $GLOBALS['ab_test_meta_updated'] );

		$this->page( 42, '<p>Copy</p>' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 42, 'meta' => array( 'vcv-pageContent' => '%7B%7D' ) ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( array( '%7B%7D' ), $GLOBALS['ab_test_meta'][42]['vcv-pagecontent'], 'Stored under the key sanitize_key() gives, as before.' );
	}

	public function testOrdinaryKeysAreWrittenAsBefore(): void {
		$this->withoutUnfilteredHtml();
		$this->page( 43, '<p>Text</p>' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 43, 'meta' => array( 'subtitle' => 'Hello <b>world</b>' ) ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( array( 'Hello <b>world</b>' ), $GLOBALS['ab_test_meta'][43]['subtitle'] );
	}

	public function testUnderscoreKeysStayPassedOverAsBefore(): void {
		$this->withoutUnfilteredHtml();
		$this->page( 44, '<p>Text</p>' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 44, 'title' => 'New', 'meta' => array( '_elementor_data' => '[]' ) ) );
		self::assertTrue( $out['updated'], 'A "_" key never reached the table here; the call goes on as before.' );
		self::assertSame( array(), $GLOBALS['ab_test_meta_updated'] );
		self::assertSame( 'New', get_post( 44 )->post_title );
	}

	public function testAKeyTakenOffTheListIsWrittenAgain(): void {
		$this->withoutUnfilteredHtml();
		add_filter(
			'ab_mcp_builder_markup_meta_keys',
			static function ( $keys ) {
				return array_values( array_diff( $keys, array( 'panels_data' ) ) );
			}
		);
		$this->page( 45, '<p>Text</p>' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 45, 'meta' => array( 'panels_data' => 'a:0:{}' ) ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( array( array( 45, 'panels_data' ) ), $GLOBALS['ab_test_meta_updated'] );
	}

	public function testBothToolsDescribeTheRule(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Content::register( $r );
		foreach ( array( 'wp_create_post', 'wp_update_post' ) as $tool ) {
			$meta = $r->get( $tool )['inputSchema']['properties']['meta'];
			self::assertSame( AB_MCP_Tools_Content::META_DESCRIPTION, $meta['description'], $tool );
		}
		self::assertStringContainsString( 'panels_data', AB_MCP_Tools_Content::META_DESCRIPTION );
		self::assertStringContainsString( 'unfiltered_html', AB_MCP_Tools_Content::META_DESCRIPTION );
		self::assertStringContainsString( 'refused before anything is written', AB_MCP_Tools_Content::META_DESCRIPTION );
	}
}
