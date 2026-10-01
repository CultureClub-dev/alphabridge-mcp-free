<?php
/**
 * SiteOrigin Page Builder reader, against the plan's measured page A and a
 * second measured page with more fields, code widgets, a shortcode and styles
 * (tests/fixtures/builders/siteorigin/): page order as the renderer builds
 * it, "w"+index ids, the measured fields, locks, no styling in the answer.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builder_Adapter_SiteOrigin;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class SiteOriginAdapterTest extends TestCase {

	use BuilderTestHelpers;
	use MetaBuilderFixtures;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'siteorigin' ) );
	}

	private function outline( object $post, array $o = array() ): array {
		return ( new AB_MCP_Builder_Adapter_SiteOrigin() )->outline( $post, $o );
	}

	/** panels_data of a fixture, as loaded. */
	private function layout( array $fx ): array {
		return $this->asLoaded( $fx['meta'] )['panels_data'];
	}

	public function testPageAIsTheMeasuredBytes(): void {
		$fx  = $this->fixture( 'page-a', 'siteorigin' );
		$raw = $fx['meta']['panels_data'];
		self::assertSame( $fx['checks']['length'], strlen( $raw ) );
		self::assertSame( $fx['checks']['md5_prefix'], substr( md5( $raw ), 0, 10 ), 'Byte for byte what the measurement stored.' );
		self::assertStringNotContainsString( 'O:', $raw, 'No objects in panels_data (measured).' );
		$post = $this->fixturePage( 200, $fx );
		self::assertSame( $raw, AB_MCP_Builders::raw( $post )['meta:panels_data'] );
	}

	public function testPageAOutline(): void {
		$fx  = $this->fixture( 'page-a', 'siteorigin' );
		$els = $this->outline( $this->fixturePage( 201, $fx ) );
		self::assertSame( array( 'r0', 'r0.c0', 'w0', 'w1', 'w2', 'w3' ), array_column( $els, 'id' ) );
		self::assertSame( array( null, 'r0', 'r0.c0', 'r0.c0', 'r0.c0', 'r0.c0' ), array_column( $els, 'parent' ) );
		self::assertSame( array( 0, 1, 2, 2, 2, 2 ), array_column( $els, 'depth' ) );
		self::assertSame( array( 'row', 'cell', 'SiteOrigin_Widget_Headline_Widget', 'SiteOrigin_Widget_Editor_Widget', 'SiteOrigin_Widget_Button_Widget', 'SiteOrigin_Widget_Image_Widget' ), array_column( $els, 'type' ) );
		$by = $this->byId( $els );
		// The fields the measurement found (nutzlast-A-dekodiert-fundstellen.txt).
		self::assertSame( 'Willkommen bei Alpine Bikes', $by['w0']['fields']['headline.text']['value'] );
		self::assertSame( 'heading', $by['w0']['fields']['headline.text']['kind'] );
		self::assertSame( 'Öffnungszeiten: Mo–Fr 9–18 Uhr', $by['w1']['fields']['text']['value'] );
		self::assertSame( array( 'text' => 'Jetzt reservieren', 'url' => 'https://example.com/reservieren' ), $this->values( $by['w2'] ) );
		self::assertSame( 8, $by['w3']['fields']['image']['value'], 'The attachment id.' );
		self::assertSame( 'Testbild Alpine', $by['w3']['fields']['alt']['value'] );
		// Widget ids are the index in widgets[]; panels_info.id holds the same number.
		foreach ( $this->layout( $fx )['widgets'] as $i => $widget ) {
			self::assertSame( $i, $widget['panels_info']['id'] );
		}
	}

	public function testMoreFieldsRowsCellsAndLocks(): void {
		$fx  = $this->fixture( 'page-more', 'siteorigin' );
		$els = $this->outline( $this->fixturePage( 202, $fx ) );
		self::assertSame( array( 'r0', 'r0.c0', 'w0', 'w1', 'r0.c1', 'w2', 'w3', 'r1', 'r1.c0', 'w4', 'w5', 'w6' ), array_column( $els, 'id' ) );
		$by = $this->byId( $els );
		self::assertSame(
			array(
				'headline.text'                => 'Willkommen bei Alpine Bikes',
				'headline.destination_url'     => 'https://example.com/mz-headline-link',
				'sub_headline.text'            => 'MZ Unterzeile',
				'sub_headline.destination_url' => 'https://example.com/mz-sub-link',
			),
			$this->values( $by['w0'] )
		);
		self::assertSame( array( 'title' => 'MZ Editor-Titel', 'text' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ), $this->values( $by['w1'] ) );
		self::assertSame( 'shortcode', $by['w2']['reason'], 'The shortcode in the editor text ran on the measured page.' );
		self::assertSame( array( 'text' => 'Jetzt reservieren', 'url' => 'https://example.com/reservieren' ), $this->values( $by['w3'] ) );
		self::assertSame( array( 'image' => 4, 'alt' => 'Testbild Alpine', 'title' => 'MZ Bild-Titel', 'url' => 'https://example.com/mz-bild-link' ), $this->values( $by['w4'] ) );
		self::assertSame( 'html widget', $by['w5']['reason'] );
		self::assertSame( array( 'title' => 'MZ Text-Widget-Titel', 'text' => 'MZ Text-Widget-Text' ), $this->values( $by['w6'] ) );

		// Every field read showed on the measured page.
		$keys = array(
			'w0' => array( 'headline.text' => 'headline.text', 'headline.destination_url' => 'headline.destination_url', 'sub_headline.text' => 'sub_headline.text', 'sub_headline.destination_url' => 'sub_headline.destination_url' ),
			'w1' => array( 'title' => 'editor.title', 'text' => 'editor.text' ),
			'w3' => array( 'text' => 'button.text', 'url' => 'button.url' ),
			'w4' => array( 'alt' => 'image.alt', 'title' => 'image.title', 'url' => 'image.url' ),
			'w6' => array( 'title' => 'text_widget.title', 'text' => 'text_widget.text' ),
		);
		foreach ( $keys as $id => $map ) {
			foreach ( $map as $field => $key ) {
				self::assertArrayHasKey( $field, $by[ $id ]['fields'] );
				self::assertTrue( $fx['shown'][ $key ], $key );
			}
		}
		self::assertTrue( $fx['shown']['editor.shortcode_text'] );
		self::assertFalse( $fx['shown']['editor.shortcode_roh'], 'The shortcode itself did not show: it ran.' );
	}

	public function testNoStylingAttributesOrCodeInTheAnswer(): void {
		$fx     = $this->fixture( 'page-more', 'siteorigin' );
		$layout = $this->layout( $fx );
		// The measured save dropped on_click; a layout may still carry one.
		$layout['widgets'][3]['attributes']['on_click'] = 'window.mzKlick=1';
		$this->fixturePage( 203, $fx, array( 'panels_data' => $layout ) );
		$flat = $this->flat( AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 203 ) ) );
		foreach ( array( 'mzKlick', 'mzRoh', 'Rohes HTML', 'MZ HTML-Titel', 'color: red', 'background: blue', 'padding: 1px', 'mz-klasse', 'mz-zeile', 'mz-id', 'mz-button-klasse', 'mz-button-id', 'MZ Button-Titel', '[caption', 'MZ Shortcode-Text', 'sow-button', '_sow_form_timestamp', 'widget_id' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $flat );
		}
	}

	public function testTheProfileCannotReachStylesOrHandlers(): void {
		add_filter(
			'ab_mcp_builder_element_profiles',
			static function ( $profile, $builder ) {
				if ( 'siteorigin' === $builder ) {
					$profile['elements']['SiteOrigin_Widget_Button_Widget']['fields']['attributes.on_click'] = array( 'kind' => 'text' );
					$profile['elements']['SiteOrigin_Widget_Button_Widget']['fields']['attributes.title']    = array( 'kind' => 'text' );
					$profile['elements']['SiteOrigin_Widget_Button_Widget']['fields']['design.theme']       = array( 'kind' => 'text' );
					$profile['elements']['WP_Widget_Custom_HTML']                                           = array( 'fields' => array( 'content' => array( 'kind' => 'html' ) ) );
				}
				return $profile;
			},
			10,
			2
		);
		$fx     = $this->fixture( 'page-more', 'siteorigin' );
		$layout = $this->layout( $fx );
		$layout['widgets'][3]['attributes']['on_click'] = 'window.mzKlick=1';
		$by = $this->byId( $this->outline( $this->fixturePage( 204, $fx, array( 'panels_data' => $layout ) ) ) );
		self::assertSame( array( 'text', 'url', 'design.theme' ), array_keys( $by['w3']['fields'] ), 'Attribute paths are refused; a plain path is a field.' );
		self::assertStringNotContainsString( 'mzRoh', $this->flat( $by['w5'] ), 'An unlocked Custom HTML widget still drops the script.' );
		self::assertStringNotContainsString( '<script', $this->flat( $by ) );
	}

	public function testRendererOrderLegacyInfoAndLooseWidgets(): void {
		$fx     = $this->fixture( 'page-more', 'siteorigin' );
		$layout = $this->layout( $fx );
		// A widget of the first cell listed last still renders in the first cell.
		$moved = $layout['widgets'][1];
		unset( $layout['widgets'][1] );
		$layout['widgets'][7] = $moved;
		// Layouts from before panels_info kept it in "info".
		$layout['widgets'][0]['info'] = $layout['widgets'][0]['panels_info'];
		unset( $layout['widgets'][0]['panels_info'] );
		// A widget in a cell that does not exist.
		$loose                               = $layout['widgets'][6];
		$loose['panels_info']['grid']        = 9;
		$layout['widgets'][8]                = $loose;
		$layout['widgets']['kaputt']         = $loose;
		$els = $this->outline( $this->fixturePage( 205, $fx, array( 'panels_data' => $layout ) ) );
		self::assertSame( array( 'r0', 'r0.c0', 'w0', 'w7', 'r0.c1', 'w2', 'w3', 'r1', 'r1.c0', 'w4', 'w5', 'w6', 'w8' ), array_column( $els, 'id' ) );
		self::assertSame( 'SiteOrigin_Widget_Headline_Widget', $els[2]['type'] );
		self::assertSame( array( null, 0 ), array( $els[12]['parent'], $els[12]['depth'] ) );
	}

	public function testUnknownWidgetsAndLimits(): void {
		$fx     = $this->fixture( 'page-a', 'siteorigin' );
		$layout = $this->layout( $fx );
		$layout['widgets'][2]['panels_info']['class'] = 'SiteOrigin_Widgets_ContactForm_Widget';
		$post = $this->fixturePage( 206, $fx, array( 'panels_data' => $layout ) );
		$by   = $this->byId( $this->outline( $post ) );
		self::assertSame( 'unknown element type', $by['w2']['reason'] );
		self::assertCount( 5, $this->outline( $post, array( 'include_locked' => false ) ) );
		self::assertCount( 3, $this->outline( $post, array( 'max_elements' => 3 ) ) );
		$this->fixturePage( 207, $fx, array( 'panels_data' => 'kein Layout' ) );
		$broken = $this->outline( get_post( 207 ) );
		self::assertCount( 1, $broken, 'A layout that cannot be read is named, not shown as an empty page.' );
		self::assertSame( 'panels_data', $broken[0]['type'] );
		self::assertSame( 'unreadable data', $broken[0]['reason'] );
		self::assertSame( array(), $this->outline( get_post( 207 ), array( 'include_locked' => false ) ) );
		// SiteOrigin finds no rows in it and shows post_content, which the tool reads.
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 207 ) );
		self::assertSame( 'A', $out['storage'] );
		self::assertNotSame( 'unreadable data', $out['elements'][0]['reason'] ?? null );
		self::assertSame( array( 'wp_update_post' ), $out['write_via'] );
	}

	public function testToolAndGuard(): void {
		$fx   = $this->fixture( 'page-a', 'siteorigin' );
		$post = $this->fixturePage( 208, $fx );
		$out  = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 208 ) );
		self::assertSame( 'siteorigin', $out['builder'] );
		self::assertSame( 'B', $out['storage'] );
		self::assertSame( 'read', $out['support'] );
		self::assertTrue( $out['verified'] );
		self::assertArrayNotHasKey( 'draft_differs', $out );
		self::assertSame( array( 'meta:panels_data', 'post_content' ), array_column( $out['copies'], 'loc' ) );
		self::assertTrue( AB_MCP_Builders::content_update_guard( $post )['block'], 'post_content is only a copy (measured).' );
	}
}
