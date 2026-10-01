<?php
/**
 * Recognition: one case per documented marker of every builder, the cases
 * that must NOT count, and the active checks.
 *
 * The cases are written out here, not derived from signatures.php: a test
 * that read its expectations from the file under test would pass whatever
 * the file said.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuilderDetectionTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * Builder id, post_content, meta, expected evidence.
	 *
	 * @return array<string,array{0:string,1:string,2:array,3:string}>
	 */
	public static function markers(): array {
		return array(
			'elementor meta'          => array( 'elementor', '', array( '_elementor_edit_mode' => 'builder' ), 'meta:_elementor_edit_mode' ),
			'beaver meta'             => array( 'beaver', '', array( '_fl_builder_enabled' => '1' ), 'meta:_fl_builder_enabled' ),
			'siteorigin meta'         => array( 'siteorigin', '', array( 'panels_data' => array( 'widgets' => array( 1 ) ) ), 'meta:panels_data' ),
			'brizy meta'              => array( 'brizy', '', array( 'brizy_enabled' => '1' ), 'meta:brizy_enabled' ),
			'brizy content'           => array( 'brizy', '<div class="brz-root__container"></div>', array(), 'content:brz-root__container' ),
			'themify meta'            => array( 'themify', '', array( '_themify_builder_settings_json' => '[{"cols":[]}]' ), 'meta:_themify_builder_settings_json' ),
			'themify content'         => array( 'themify', '<!--themify_builder_static-->Text<!--/themify_builder_static-->', array(), 'content:<!--themify_builder_static-->' ),
			'zion meta'               => array( 'zion', '', array( '_zionbuilder_page_status' => 'enabled' ), 'meta:_zionbuilder_page_status' ),
			'livecomposer meta'       => array( 'livecomposer', '', array( 'dslc_code' => '[{"content":[]}]' ), 'meta:dslc_code' ),
			'livecomposer content'    => array( 'livecomposer', '<div class="dslc-modules-section">x</div>', array(), 'content:dslc-modules-section' ),
			'enfold meta'             => array( 'enfold', '[av_textblock]x[/av_textblock]', array( '_aviaLayoutBuilder_active' => 'active' ), 'meta:_aviaLayoutBuilder_active' ),
			'cornerstone meta'        => array( 'cornerstone', '', array( '_cornerstone_data' => '[{"_type":"section"}]' ), 'meta:_cornerstone_data' ),
			'cornerstone html'        => array( 'cornerstone', '<!-- cs-content --><div id="cs-content"></div>', array(), 'content:<!-- cs-content -->' ),
			'cornerstone shortcode'   => array( 'cornerstone', '[cs_content]x[/cs_content]', array(), 'content:[cs_content' ),
			'thrive meta'             => array( 'thrive', '', array( 'tcb_editor_enabled' => '1' ), 'meta:tcb_editor_enabled' ),
			'bricks meta'             => array( 'bricks', '', array( '_bricks_editor_mode' => 'bricks' ), 'meta:_bricks_editor_mode' ),
			'breakdance meta'         => array( 'breakdance', '', array( '_breakdance_data' => '{"tree_json_string":"{\"root\":{}}"}' ), 'meta:_breakdance_data' ),
			'oxygen 6 meta'           => array( 'oxygen', '', array( '_oxygen_data' => '{"tree_json_string":"{\"root\":{}}"}' ), 'meta:_oxygen_data' ),
			'oxygen classic json'     => array( 'oxygen-classic', '', array( 'ct_builder_json' => '{"children":[]}' ), 'meta:ct_builder_json' ),
			'oxygen classic sc'       => array( 'oxygen-classic', '', array( 'ct_builder_shortcodes' => '[ct_section]' ), 'meta:ct_builder_shortcodes' ),
			'betheme meta'            => array( 'betheme', '', array( 'mfn-page-items' => 'YToxOnt9' ), 'meta:mfn-page-items' ),
			'seedprod page'           => array( 'seedprod', '<div id="sp-page"></div>', array( '_seedprod_page' => '1' ), 'meta:_seedprod_page' ),
			'seedprod edited'         => array( 'seedprod', '<div id="sp-page"></div>', array( '_seedprod_edited_with_seedprod' => '1' ), 'meta:_seedprod_edited_with_seedprod' ),
			'visualcomposer meta'     => array( 'visualcomposer', '', array( 'vcv-pageContent' => '%7B%22elements%22%3A%7B%7D%7D' ), 'meta:vcv-pageContent' ),
			'visualcomposer content'  => array( 'visualcomposer', '<!--vcv no format--><div>x</div>', array(), 'content:<!--vcv no format-->' ),
			'pagelayer blocks'        => array( 'pagelayer', '<!-- wp:pagelayer/pl_heading {"pagelayer-id":"x1"} --><h1>Hi</h1><!-- /wp:pagelayer/pl_heading -->', array(), 'content:<!-- wp:pagelayer/' ),
			'pagelayer meta'          => array( 'pagelayer', '', array( 'pagelayer-data' => '1790000000' ), 'meta:pagelayer-data' ),
			'generateblocks'          => array( 'generateblocks', '<!-- wp:generateblocks/text {"uniqueId":"2ddee60a","tagName":"h2"} --><h2 class="gb-text">Hi</h2><!-- /wp:generateblocks/text -->', array(), 'content:<!-- wp:generateblocks/' ),
			'kadence'                 => array( 'kadence', '<!-- wp:kadence/singlebtn {"uniqueID":"19290_62d41f-67","text":"Call To Action"} /-->', array(), 'content:<!-- wp:kadence/' ),
			'spectra'                 => array( 'spectra', '<!-- wp:uagb/buttons-child {"block_id":"0b139ae9","label":"Read More"} --><div>Read More</div><!-- /wp:uagb/buttons-child -->', array(), 'content:<!-- wp:uagb/' ),
			'stackable'               => array( 'stackable', '<!-- wp:stackable/heading {"uniqueId":"ab2c9eb"} --><div></div><!-- /wp:stackable/heading -->', array(), 'content:<!-- wp:stackable/' ),
			'otter'                   => array( 'otter', '<!-- wp:themeisle-blocks/button {"id":"wp-block-x"} /-->', array(), 'content:<!-- wp:themeisle-blocks/' ),
			'coblocks'                => array( 'coblocks', '<!-- wp:coblocks/accordion --><div></div><!-- /wp:coblocks/accordion -->', array(), 'content:<!-- wp:coblocks/' ),
			'divi 5'                  => array( 'divi5', '<!-- wp:divi/section {"builderVersion":"5.0"} --><!-- wp:divi/text {"content":{}} /--><!-- /wp:divi/section -->', array(), 'content:<!-- wp:divi/' ),
			'etch'                    => array( 'etch', '<!-- wp:etch/text {"content":"Hi"} /-->', array(), 'content:<!-- wp:etch/' ),
			'wpbakery row'            => array( 'wpbakery', '[vc_row][vc_column][vc_column_text]x[/vc_column_text][/vc_column][/vc_row]', array(), 'content:[vc_' ),
			'wpbakery section'        => array( 'wpbakery', '[vc_section][vc_row][/vc_row][/vc_section]', array(), 'content:[vc_' ),
			'wpbakery meta'           => array( 'wpbakery', 'plain', array( '_wpb_vc_js_status' => 'true' ), 'meta:_wpb_vc_js_status' ),
			'divi 4 meta'             => array( 'divi4', 'plain', array( '_et_pb_use_builder' => 'on' ), 'meta:_et_pb_use_builder' ),
			'divi 4 content'          => array( 'divi4', '[et_pb_section fb_built="1"][et_pb_row][/et_pb_row][/et_pb_section]', array(), 'content:[et_pb_section' ),
			'divi 4 with layout block' => array( 'divi4', '<!-- wp:divi/layout {"layoutContent":"[et_pb_section]"} /-->[et_pb_section][/et_pb_section]', array(), 'content:[et_pb_section' ),
			'avada content'           => array( 'avada', '[fusion_builder_container][fusion_builder_row][/fusion_builder_row][/fusion_builder_container]', array(), 'content:[fusion_builder_container' ),
			'avada meta'              => array( 'avada', 'plain', array( 'fusion_builder_status' => 'active' ), 'meta:fusion_builder_status' ),
			'flatsome ux element'     => array( 'flatsome', '[ux_image id="12"]', array(), 'content:[ux_' ),
			'flatsome section row'    => array( 'flatsome', '[section bg="1"] [row][col span="6"]x[/col][/row][/section]', array(), 'content:[section' ),
		);
	}

	#[DataProvider( 'markers' )]
	public function testEachMarkerIsRecognised( string $builder, string $content, array $meta, string $evidence ): void {
		$post  = $this->page( 40, $content, $meta );
		$found = array();
		foreach ( AB_MCP_Builders::detect_all( $post ) as $entry ) {
			$found[ $entry['id'] ] = $entry;
		}
		self::assertArrayHasKey( $builder, $found, 'Recognised: ' . $builder );
		self::assertContains( $evidence, $found[ $builder ]['detected_by'] );
	}

	/**
	 * Builder id, post_content, meta: none of these is that builder.
	 *
	 * @return array<string,array{0:string,1:string,2:array}>
	 */
	public static function notMarkers(): array {
		return array(
			'elementor switched off'      => array( 'elementor', '', array( '_elementor_edit_mode' => '' ) ),
			'beaver switched off'         => array( 'beaver', '', array( '_fl_builder_enabled' => '0' ) ),
			'thrive off'                  => array( 'thrive', '', array( 'tcb_editor_enabled' => '0' ) ),
			'divi 4 back to classic'      => array( 'divi4', 'plain', array( '_et_pb_use_builder' => 'off' ) ),
			'divi 5 page is not divi 4'   => array( 'divi4', '<!-- wp:divi/section {} --><!-- /wp:divi/section -->', array( '_et_pb_use_builder' => 'on' ) ),
			'divi layout block not 5'     => array( 'divi5', '<!-- wp:divi/layout {"x":1} /-->', array() ),
			'enfold standard editor'      => array( 'enfold', '', array( '_aviaLayoutBuilder_active' => '' ) ),
			'avada inactive'              => array( 'avada', 'plain', array( 'fusion_builder_status' => 'inactive' ) ),
			'wpbakery editor off'         => array( 'wpbakery', 'plain', array( '_wpb_vc_js_status' => 'false' ) ),
			'wpbakery other vc tag'       => array( 'wpbakery', '[vc_custom_heading text="x"]', array() ),
			'zion disabled'               => array( 'zion', '', array( '_zionbuilder_page_status' => 'disabled' ) ),
			'bricks wordpress mode'       => array( 'bricks', '', array( '_bricks_editor_mode' => 'wordpress' ) ),
			'oxygen 6 empty design'       => array( 'oxygen', '', array( '_oxygen_data' => '{"tree_json_string":""}' ) ),
			'breakdance empty design'     => array( 'breakdance', '', array( '_breakdance_data' => '{"tree_json_string":""}' ) ),
			'flatsome lone section'       => array( 'flatsome', '[section]x[/section]', array() ),
			'visual composer marker late' => array( 'visualcomposer', '<p>x</p><!--vcv no format-->', array() ),
			'core blocks are no builder'  => array( 'kadence', '<!-- wp:paragraph --><p>Kadence</p><!-- /wp:paragraph -->', array() ),
			'mosaic never'                => array( 'mosaic', 'anything [mosaic] <!-- wp:mosaic/x /-->', array( 'mosaic' => '1' ) ),
		);
	}

	#[DataProvider( 'notMarkers' )]
	public function testLookAlikesAreNotRecognised( string $builder, string $content, array $meta ): void {
		$post = $this->page( 41, $content, $meta );
		$ids  = array_column( AB_MCP_Builders::detect_all( $post ), 'id' );
		self::assertNotContains( $builder, $ids, 'Not ' . $builder );
		self::assertSame( array(), AB_MCP_Builders::markers_found( $builder, $post ) );
	}

	public function testAPlainPostHasNoBuilder(): void {
		$post = $this->page( 42, '<p>Hello</p>' );
		self::assertSame( array(), AB_MCP_Builders::detect_all( $post ) );
		self::assertNull( AB_MCP_Builders::primary( $post ) );
		self::assertNull( AB_MCP_Builders::built_with( $post ) );
	}

	public function testActiveChecksReadConstantsFunctionsPluginsAndThemes(): void {
		add_filter(
			'ab_mcp_builder_signatures',
			static function ( $sigs ) {
				$sigs['t-constant'] = array( 'active' => array( array( 'constant' => 'AB_MCP_BUILDER_API' ) ), 'markers' => array( array( 'meta' => 'x' ) ) );
				$sigs['t-function'] = array( 'active' => array( array( 'function' => '\\ab_test_reset' ) ), 'markers' => array( array( 'meta' => 'x' ) ) );
				$sigs['t-class']    = array( 'active' => array( array( 'class' => '\\AB_MCP_Builders' ) ), 'markers' => array( array( 'meta' => 'x' ) ) );
				$sigs['t-missing']  = array( 'active' => array( array( 'constant' => 'AB_TEST_NOT_DEFINED_ANYWHERE' ), array( 'class' => 'No\\Such\\Class_Here' ) ), 'markers' => array( array( 'meta' => 'x' ) ) );
				$sigs['t-unknown']  = array( 'markers' => array( array( 'meta' => 'x' ) ) );
				return $sigs;
			}
		);
		AB_MCP_Builders::reset();
		self::assertTrue( AB_MCP_Builders::signature_active( 't-constant' ) );
		self::assertTrue( AB_MCP_Builders::signature_active( 't-function' ), 'A leading backslash is fine.' );
		self::assertTrue( AB_MCP_Builders::signature_active( 't-class' ) );
		self::assertFalse( AB_MCP_Builders::signature_active( 't-missing' ) );
		self::assertNull( AB_MCP_Builders::signature_active( 't-unknown' ), 'No documented check: unknown, not false.' );
		self::assertNull( AB_MCP_Builders::signature_active( 'betheme' ), 'BeTheme has no documented check.' );
	}

	public function testPluginChecksReadTheActivePluginsAndTheNetwork(): void {
		self::assertFalse( AB_MCP_Builders::signature_active( 'avada' ) );
		update_option( 'active_plugins', array( 'fusion-builder/fusion-builder.php' ) );
		self::assertTrue( AB_MCP_Builders::signature_active( 'avada' ) );

		update_option( 'active_plugins', array() );
		$GLOBALS['ab_test_multisite']    = true;
		$GLOBALS['ab_test_site_options'] = array( 'active_sitewide_plugins' => array( 'oxygen/plugin.php' => 1790000000 ) );
		self::assertTrue( AB_MCP_Builders::signature_active( 'oxygen' ), 'Network-activated.' );
		self::assertFalse( AB_MCP_Builders::signature_active( 'avada' ) );
	}

	public function testThemeChecksReadTheParentAndTheChildTheme(): void {
		self::assertFalse( AB_MCP_Builders::signature_active( 'bricks' ) );
		$GLOBALS['ab_test_template'] = 'bricks';
		self::assertTrue( AB_MCP_Builders::signature_active( 'bricks' ), 'Parent theme.' );
		$GLOBALS['ab_test_template']   = 'twentytwentyfive';
		$GLOBALS['ab_test_stylesheet'] = 'Divi';
		self::assertTrue( AB_MCP_Builders::signature_active( 'divi4' ), 'Active theme.' );
	}

	public function testTheFirstActiveBuilderAnswersForThePage(): void {
		// Kadence blocks in the copy of an Elementor page: Elementor answers
		// while it is active, the blocks when it is not.
		$this->builders( array( 'elementor', 'kadence' ) );
		$post = $this->page( 43, '<!-- wp:kadence/advancedheading {"uniqueID":"k1"} --><h2>Hi</h2><!-- /wp:kadence/advancedheading -->', array( '_elementor_edit_mode' => 'builder' ) );
		self::assertSame( 'elementor', AB_MCP_Builders::primary( $post )['id'] );
		self::assertSame( 'B', AB_MCP_Builders::effective_storage( $post ) );

		$this->builders( array( 'kadence' ), array( 'elementor' ) );
		self::assertSame( 'kadence', AB_MCP_Builders::primary( $post )['id'] );
		self::assertSame( 'A', AB_MCP_Builders::effective_storage( $post ) );

		$this->builders( array(), array( 'elementor', 'kadence' ) );
		self::assertSame( 'elementor', AB_MCP_Builders::primary( $post )['id'], 'None active: the first detected.' );
		self::assertSame( 'A', AB_MCP_Builders::effective_storage( $post ), 'No active builder: WordPress shows post_content.' );
	}

	public function testBuiltWithSaysWhatPostContentIs(): void {
		$this->builders( array( 'elementor', 'seedprod', 'kadence' ) );
		$b = AB_MCP_Builders::built_with( $this->page( 44, '<p>copy</p>', array( '_elementor_edit_mode' => 'builder' ) ) );
		self::assertSame( 'elementor', $b['builder'] );
		self::assertSame( 'B', $b['storage'] );
		self::assertSame( 'detected_only', $b['support'] );
		self::assertSame( array(), $b['write_via'], 'Nothing on this site writes Elementor data.' );
		self::assertStringContainsString( 'post_content is only a copy', $b['note'] );
		self::assertStringContainsString( 'wp_get_builder_layout', $b['note'] );

		$b = AB_MCP_Builders::built_with( $this->page( 45, '<div id="sp-page">x</div>', array( '_seedprod_page' => '1' ) ) );
		self::assertSame( 'A2', $b['storage'] );
		self::assertStringContainsString( 'restores it', $b['note'] );

		$b = AB_MCP_Builders::built_with( $this->page( 46, '<!-- wp:kadence/singlebtn {"uniqueID":"a"} /-->' ) );
		self::assertSame( 'read', $b['support'] );
		self::assertSame( array( 'wp_update_post' ), $b['write_via'] );
		self::assertArrayNotHasKey( 'note', $b, 'Storage A: post_content is the page.' );
	}
}
