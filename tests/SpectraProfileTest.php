<?php
/**
 * The Spectra profile on the two vendor templates the measurement used
 * (Spectra 2.20.4, WordPress 7.1.2): heading and info box in the markup,
 * the button with its copies in the comment, the image with three address
 * copies, and the SVG icons that never reach an outline.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class SpectraProfileTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Elements by id. */
	private function byId( string $content ): array {
		return array_column( AB_MCP_Block_Reader::outline( $content, array() ), null, 'id' );
	}

	public function testHeadingsAndTextOfAbout24(): void {
		$by = $this->byId( $this->fixture( 'about-24', 'spectra' )['content'] );
		self::assertCount( 13, $by, 'Every block of the template (8 containers, 1 info box, 4 headings).' );
		self::assertSame( array( 'text' => array( 'kind' => 'html', 'value' => 'Our Vision' ) ), $by['28f119cb']['fields'], 'block_id is the element id.' );
		self::assertArrayNotHasKey( 'note', $by['28f119cb'] );
		self::assertSame( '909937be', $by['28f119cb']['parent'] );
		self::assertSame( 4, $by['28f119cb']['depth'] );
		self::assertStringStartsWith( 'Our vision is to be a global leader', $by['427153b7']['fields']['text']['value'], 'The same block as text (headingTag p).' );
		self::assertArrayNotHasKey( 'fields', $by['156f0c22'], 'A container has no field.' );

		$box = $by['3d00ce3f'];
		self::assertSame( 'uagb/info-box', $box['type'] );
		self::assertSame( array( 'kind' => 'text', 'value' => 'Our Story' ), $box['fields']['prefix'] );
		self::assertSame( array( 'kind' => 'heading', 'value' => 'Discover Our Journey So Far' ), $box['fields']['title'] );
		self::assertStringStartsWith( 'We believe that everyone has the potential', $box['fields']['text']['value'] );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNMEASURED, $box['note'], 'Prefix and title were found where they are, but not changed in the measurement.' );
	}

	public function testButtonAndImageOfAbout20(): void {
		$by  = $this->byId( $this->fixture( 'about-20', 'spectra' )['content'] );
		$btn = $by['0b139ae9'];
		self::assertSame( 'uagb/buttons-child', $btn['type'] );
		self::assertSame( 'f16d49f4', $btn['parent'], 'Inside its button group.' );
		self::assertSame(
			array(
				'text' => array(
					'kind'  => 'text',
					'value' => 'Read More',
				),
				'url'  => array(
					'kind'  => 'url',
					'value' => '#',
				),
			),
			$btn['fields']
		);
		self::assertArrayNotHasKey( 'note', $btn, 'Label and link agree with the markup.' );

		$img = $by['d4e717a3'];
		self::assertSame( 80569, $img['fields']['image']['value'] );
		self::assertSame( 'https://websitedemos.net/wp-content/uploads/2023/10/desserts-01.jpg', $img['fields']['src']['value'] );
		self::assertSame( '', $img['fields']['alt']['value'] );
		self::assertArrayNotHasKey( 'note', $img, 'url, urlTablet and urlMobile agree with the markup.' );

		self::assertArrayNotHasKey( 'prefix', $by['50f73676']['fields'], 'An info box without prefix has no prefix field.' );
	}

	public function testAStaleLabelOrLinkIsNotedAndTheMarkupWins(): void {
		// The measured variants rebuilt from the template (the measurement
		// file keeps only the first 1214 bytes of each stored variant): H
		// changes only the markup (visible), A only the comment (invisible).
		$page = $this->fixture( 'about-20', 'spectra' )['content'];

		$h = $this->byId( str_replace( '<div class="uagb-button__link">Read More</div>', '<div class="uagb-button__link">NEUTEXTH2</div>', $page ) )['0b139ae9'];
		self::assertSame( 'NEUTEXTH2', $h['fields']['text']['value'] );
		self::assertSame( 'text: the copy in attribute label differs', $h['note'] );

		$a = $this->byId( str_replace( '"link":"#"', '"link":"https://example.org/neu-A2"', $page ) )['0b139ae9'];
		self::assertSame( '#', $a['fields']['url']['value'], 'The invisible change is not what the outline reports.' );
		self::assertSame( 'url: the copy in attribute link differs', $a['note'] );

		$k = str_replace( '"urlMobile":"https://websitedemos.net/wp-content/uploads/2023/10/desserts-01.jpg"', '"urlMobile":"https://example.org/neu-A3.jpg"', $page );
		self::assertNotSame( $page, $k );
		self::assertSame( 'src: the copy in attribute urlMobile differs', $this->byId( $k )['d4e717a3']['note'] );
	}

	public function testSpecialCharactersInTheLabel(): void {
		// The stored button after the measurement wrote «A & B "Zitat" <x> –
		// Grüsse ü» into label (escaped by serialize_block_attributes) and into
		// the markup (A &amp; B "Zitat" &lt;x> …, as the page showed it). The
		// field is the visible text of the markup. label is rich text in
		// Spectra: read as HTML, its raw «<x>» is an element, not the text
		// the page shows — so the copy really differs.
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'buttons-child-sonderzeichen', 'spectra' )['content'], array() );
		self::assertSame( 'A & B "Zitat" <x> – Grüsse ü', $e[0]['fields']['text']['value'] );
		self::assertSame( 'text: the copy in attribute label differs', $e[0]['note'] );
	}

	public function testSvgIconsAndStylingNeverReachTheOutline(): void {
		$all = $this->flat( AB_MCP_Block_Reader::outline( $this->fixture( 'about-20', 'spectra' )['content'], array() ) );
		foreach ( array( '<svg', 'M96 480', 'viewBox', 'chevron', 'var(--ast', 'srcset', '780w', 'noopener', '_self' ) as $never ) {
			self::assertStringNotContainsString( $never, $all, $never );
		}
	}

	public function testTheLayoutToolOnTheTemplate(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'spectra' ) );
		$this->page( 311, $this->fixture( 'about-20', 'spectra' )['content'] );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 311 ) );
		self::assertSame( 'spectra', $out['builder'] );
		self::assertSame( 'A', $out['storage'] );
		self::assertSame( 8, $out['element_count'], '3 containers, 2 info boxes, 1 button group, 1 button, 1 image.' );
		self::assertSame( array( 'post_content' ), array_column( $out['copies'], 'loc' ) );
		self::assertFalse( $out['verified'], 'Two info boxes carry fields that were not measured.' );
	}
}
