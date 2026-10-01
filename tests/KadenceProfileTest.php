<?php
/**
 * The Kadence Blocks profile on the vendor's "Example Page", the page the
 * measurement used (Kadence Blocks 3.7.12, WordPress 7.1.2): headings and
 * text in the markup, the button only in its comment, the image, the ids.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class KadenceProfileTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Elements by id. */
	private function byId( array $elements ): array {
		return array_column( $elements, null, 'id' );
	}

	/** The single element of a one-block fixture. */
	private function one( string $name ): array {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( $name, 'kadence' )['content'], array() );
		self::assertCount( 1, $e, $name );
		return $e[0];
	}

	public function testTheMeasuredBlocks(): void {
		$heading = $this->one( 'heading' );
		self::assertSame( '19290_a73277-99', $heading['id'], 'uniqueID is the element id.' );
		self::assertSame( array( 'text' => array( 'kind' => 'html', 'value' => 'Write a Brief Title' ) ), $heading['fields'] );
		self::assertArrayNotHasKey( 'note', $heading );

		$text = $this->one( 'text' );
		self::assertSame( 'kadence/advancedheading', $text['type'], 'Text is the same block with htmlTag p.' );
		self::assertStringStartsWith( 'Use this paragraph section', $text['fields']['text']['value'] );

		$image = $this->one( 'image' );
		self::assertSame( 2864, $image['fields']['image']['value'] );
		self::assertSame( 'https://patterns.startertemplatecloud.com/wp-content/uploads/2023/02/Example-A-Roll-Image-scaled.jpg', $image['fields']['src']['value'] );
		self::assertSame( '', $image['fields']['alt']['value'] );
		self::assertArrayNotHasKey( 'caption', $image['fields'] );
		self::assertArrayNotHasKey( 'note', $image );
	}

	public function testTheButtonIsReadFromItsCommentAlone(): void {
		// Measured: singlebtn is self-closing — no markup at all; Kadence
		// builds the button from text and link when it renders.
		$content = $this->fixture( 'singlebtn', 'kadence' )['content'];
		self::assertStringEndsWith( '/-->', $content );
		$btn = $this->one( 'singlebtn' );
		self::assertSame( '19290_62d41f-67', $btn['id'] );
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'Call To Action' ) ), $btn['fields'], 'The measured button has no link yet.' );

		$linked = $this->one( 'singlebtn-link' );
		self::assertSame( array( 'kind' => 'url', 'value' => 'https://example.org/neu-K2' ), $linked['fields']['url'], 'Measured: the link set in the attribute is the visible link.' );
	}

	public function testTheButtonTextIsReadAsVisitorsSeeIt(): void {
		// Measured: «A \u0026 B \u0022Zitat\u0022 \u003cx\u003e – Grüsse ü» in the
		// comment, and Kadence printed it raw: <span …>A & B "Zitat" <x> –
		// Grüsse ü</span> — the <x> became an element, which shows nothing.
		$btn = $this->one( 'singlebtn-sonderzeichen' );
		self::assertSame( 'A & B "Zitat" – Grüsse ü', $btn['fields']['text']['value'] );
		self::assertStringNotContainsString( 'u0026', $this->flat( $btn ) );
		self::assertStringNotContainsString( '<x>', $this->flat( $btn ) );
	}

	public function testTheExamplePageAsAnOutline(): void {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'example-page', 'kadence' )['content'], array() );
		self::assertCount( 62, $e, 'Every block of the page, nothing more.' );
		$types = array_count_values( array_column( $e, 'type' ) );
		ksort( $types );
		self::assertSame(
			array(
				'kadence/advancedbtn'     => 4,
				'kadence/advancedheading' => 16,
				'kadence/column'          => 17,
				'kadence/image'           => 3,
				'kadence/infobox'         => 6,
				'kadence/rowlayout'       => 7,
				'kadence/singlebtn'       => 5,
				'kadence/testimonial'     => 3,
				'kadence/testimonials'    => 1,
			),
			$types,
			'The block counts of the measured page (ergebnis-kadence.json analyse.quellen[0]).'
		);

		$by = $this->byId( $e );
		self::assertSame( '19290_9a2453-0b', $by['19290_62d41f-67']['parent'], 'The button sits in its button group.' );
		self::assertSame( 3, $by['19290_62d41f-67']['depth'] );
		self::assertSame( 'Secondary Button', $by['19290_cf0060-c1']['fields']['text']['value'] );
		self::assertSame( 'Add a short &amp; sweet headline', $by['19290_fd242c-2a']['fields']['text']['value'], 'Rich text stays HTML, as the vendor saved it.' );

		// Blocks outside the measured list are read by the general reader and say so.
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNVERIFIED, $by['19290_440975-de']['note'] );
		self::assertSame( 'kadence/infobox', $by['19290_440975-de']['type'] );
		foreach ( $e as $element ) {
			if ( in_array( $element['type'], array( 'kadence/rowlayout', 'kadence/column', 'kadence/advancedbtn' ), true ) ) {
				self::assertArrayNotHasKey( 'fields', $element, $element['id'] );
				self::assertArrayNotHasKey( 'note', $element, 'A measured container needs no note.' );
			}
		}
	}

	public function testKadenceCssIsNeverRead(): void {
		// Constructed (not measured): the measured heading with the per-block
		// CSS attribute kadenceBlockCSS (README h), class-…-row-layout-block.php:1321).
		$content = str_replace( '{"level":1,', '{"level":1,"kadenceBlockCSS":"selector { color: rgb(9, 8, 7); }",', $this->fixture( 'heading', 'kadence' )['content'] );
		$e       = AB_MCP_Block_Reader::outline( $content, array() );
		self::assertSame( 'Write a Brief Title', $e[0]['fields']['text']['value'] );
		self::assertStringNotContainsString( 'rgb(9, 8, 7)', $this->flat( $e ) );
	}

	public function testTheLayoutToolReportsKadenceAndWhatWasNotMeasured(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'kadence' ) );
		$this->page( 301, $this->fixture( 'example-page', 'kadence' )['content'] );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 301 ) );
		self::assertSame( 'kadence', $out['builder'] );
		self::assertSame( 'A', $out['storage'] );
		self::assertSame( 'read', $out['support'] );
		self::assertSame( array( 'wp_update_post' ), $out['write_via'] );
		self::assertSame( 62, $out['element_count'] );
		self::assertFalse( $out['verified'], 'Info boxes and testimonials were read without a measured profile.' );
		self::assertStringContainsString( '10 elements were read without a measured profile', implode( ' ', $out['notes'] ) );
		self::assertSame( AB_MCP_Builders::layout_hash( get_post( 301 ) ), $out['layout_hash'] );
	}
}
