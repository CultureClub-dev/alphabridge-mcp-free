<?php
/**
 * SeedProd reader, against the JSON measured in post_content_filtered and
 * the HTML SeedProd's editor wrote to post_content
 * (tests/fixtures/builders/seedprod/): SeedProd's ids, the measured fields,
 * locked code blocks, no scripts or CSS in the answer, and notes where the
 * page shows another version than the JSON.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builder_Adapter_SeedProd;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class SeedProdAdapterTest extends TestCase {

	use BuilderTestHelpers;
	use MetaBuilderFixtures;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'seedprod' ) );
	}

	private function outline( object $post, array $o = array() ): array {
		return ( new AB_MCP_Builder_Adapter_SeedProd() )->outline( $post, $o );
	}

	/**
	 * The measured page with another post_content and/or JSON.
	 */
	private function variant( int $id, array $fx, ?string $content = null, ?string $json = null ): object {
		$fx['post_content'] = $content ?? $fx['post_content'];
		if ( null !== $json ) {
			$fx['post_content_filtered'] = $json;
		}
		return $this->fixturePage( $id, $fx, $fx['meta'] );
	}

	/** The JSON of the measured page with the image address of the plan's measurement, whose HTML the fragments are. */
	private function jsonAsPlanMeasured( array $fx, array $html ): string {
		$data = json_decode( $fx['post_content_filtered'], true );
		$data['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][3]['settings']['src'] = $html['image_src'];
		return (string) json_encode( $data );
	}

	/** A JSON unicode escape (a backslash, "u", four hex digits) as the ability writes it. */
	private function esc( string $hex ): string {
		return chr( 92 ) . 'u' . $hex;
	}

	public function testTheFixtureIsTheJsonSeedProdSaved(): void {
		$fx = $this->fixture( 'page', 'seedprod' );
		self::assertSame( '6.20.10', $fx['versions']['seedprod'] );
		self::assertSame( '', $fx['post_content'], 'Saved through the ability, post_content stays empty (measured).' );
		self::assertStringContainsString( $this->esc( '00d6' ) . 'ffnungszeiten', $fx['post_content_filtered'], 'The ability writes escaped JSON (measured).' );
		self::assertSame( 1500, strlen( $fx['post_content_filtered'] ), 'The plan measured 1499 with a port one digit shorter.' );
	}

	public function testOutlineWithSeedProdIdsAndTheMeasuredFields(): void {
		$fx  = $this->fixture( 'page', 'seedprod' );
		$els = $this->outline( $this->fixturePage( 300, $fx ) );
		self::assertSame( array( 'sec001', 'row001', 'col001', 'blk001', 'blk002', 'blk003', 'blk004' ), array_column( $els, 'id' ) );
		self::assertSame( array( 'section', 'row', 'col', 'header', 'text', 'button', 'image' ), array_column( $els, 'type' ) );
		self::assertSame( array( null, 'sec001', 'row001', 'col001', 'col001', 'col001', 'col001' ), array_column( $els, 'parent' ) );
		self::assertSame( array( 0, 1, 2, 3, 3, 3, 3 ), array_column( $els, 'depth' ) );
		$by = $this->byId( $els );
		self::assertSame( array( 'headerTxt' => 'Willkommen bei Alpine Bikes' ), $this->values( $by['blk001'] ) );
		self::assertSame( 'heading', $by['blk001']['fields']['headerTxt']['kind'] );
		self::assertSame( array( 'txt' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ), $this->values( $by['blk002'] ) );
		self::assertSame( array( 'btnTxt' => 'Jetzt reservieren', 'link' => 'https://example.com/reservieren' ), $this->values( $by['blk003'] ) );
		self::assertSame( 'image', $by['blk004']['fields']['src']['kind'] );
		self::assertStringEndsWith( '/wp-content/uploads/2026/10/alpine-velo.png', $by['blk004']['fields']['src']['value'] );
		self::assertSame( 'Velo vor dem Laden', $by['blk004']['fields']['altTxt']['value'] );
		self::assertSame( AB_MCP_Builder_Adapter_SeedProd::NOTE_NOT_SHOWN, $by['sec001']['note'], 'Never saved in the editor: the site shows none of it (measured).' );
	}

	public function testTheEditorsUnescapedJsonReadsTheSame(): void {
		$fx = $this->fixture( 'page', 'seedprod' );
		// SeedProd's editor saves umlauts, dashes and "https://" as they are (measured, sp-5); same data otherwise.
		$raw = (string) json_encode( json_decode( $fx['post_content_filtered'], true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		self::assertStringContainsString( 'Öffnungszeiten: Mo–Fr', $raw );
		self::assertSame( $this->outline( $this->fixturePage( 301, $fx ) ), $this->outline( $this->variant( 302, $fx, null, $raw ) ) );
	}

	public function testNotesWhereThePageShowsAnotherVersion(): void {
		$fx   = $this->fixture( 'page', 'seedprod' );
		$html = $this->fixture( 'editor-html', 'seedprod' );
		$json = $this->jsonAsPlanMeasured( $fx, $html );

		// After the first save in the editor, post_content shows what the JSON says (measured, sp-2).
		$saved = implode( ' ', $html['fragments']['sp-2-nach-editor.json'] );
		$els   = $this->outline( $this->variant( 303, $fx, $saved, $json ) );
		foreach ( $els as $el ) {
			self::assertArrayNotHasKey( 'note', $el, $el['id'] );
		}

		// After a change to post_content only, the site shows Mo–Sa, the JSON still Mo–Fr (measured, sp-3).
		$changed = implode( ' ', $html['fragments']['sp-3-alphabridge.json'] );
		self::assertStringContainsString( 'Mo–Sa 9–17 Uhr', $changed );
		$by = $this->byId( $this->outline( $this->variant( 304, $fx, $changed, $json ) ) );
		self::assertSame( 'txt: post_content shows another version', $by['blk002']['note'] );
		self::assertSame( 'Öffnungszeiten: Mo–Fr 9–18 Uhr', $by['blk002']['fields']['txt']['value'], 'The outline is the JSON the editor saves next.' );
		foreach ( array( 'blk001', 'blk003', 'blk004' ) as $id ) {
			self::assertArrayNotHasKey( 'note', $by[ $id ], $id );
		}

		// A link the page does not show.
		$other = str_replace( 'https://example.com/reservieren', 'https://example.com/anders', $changed );
		$by    = $this->byId( $this->outline( $this->variant( 305, $fx, $other, $json ) ) );
		self::assertSame( 'link: post_content shows another version', $by['blk003']['note'] );
	}

	public function testCodeBlocksAreLockedAndNeverRead(): void {
		$fx  = $this->fixture( 'code-page', 'seedprod' );
		$els = $this->byId( $this->outline( $this->fixturePage( 306, $fx ) ) );
		self::assertSame( 'code element', $els['blk101']['reason'] );
		self::assertSame( 'custom-html', $els['blk101']['type'] );
		self::assertSame( 'shortcode', $els['blk102']['reason'] );
		self::assertSame( 'code element', $els['blk103']['reason'] );
		self::assertSame( 'video', $els['blk103']['type'] );
		self::assertSame( array( 'headerTxt' => 'MZ Ueberschrift auf der Codeseite' ), $this->values( $els['blk104'] ) );
		self::assertStringContainsString( 'mzKopf', $fx['post_content_filtered'], 'The page script is in the JSON.' );
		$flat = $this->flat( AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 306 ) ) );
		foreach ( array( 'mzKopf', 'header_scripts', 'mzRoh', 'Rohes HTML', 'mz-video', 'iframe', 'Bildunterschrift-MZ', '[caption', 'bgColor', '#FFFFFF', 'Roboto' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $flat );
		}
	}

	public function testTheMeasuredEditorHtmlOfTheCodePage(): void {
		// The editor shows custom-html as it is (measured, sp-8): the reason for the lock.
		$html = $this->fixture( 'editor-html', 'seedprod' );
		self::assertStringContainsString( '<script>window.mzRoh=1;</script>', $html['code_page'] );
		$fx  = $this->fixture( 'code-page', 'seedprod' );
		$els = $this->byId( $this->outline( $this->variant( 307, $fx, $html['code_page'] ) ) );
		self::assertArrayNotHasKey( 'note', $els['sec101'], 'post_content is not empty.' );
		self::assertSame( 'headerTxt: post_content shows another version', $els['blk104']['note'], 'That save had no such header.' );
	}

	public function testRepeatedOrOddIdsFallBackToPaths(): void {
		$fx   = $this->fixture( 'page', 'seedprod' );
		$data = json_decode( $fx['post_content_filtered'], true );
		$data['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][1]['id'] = 'blk001';
		$data['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][3]['id'] = '<b>';
		unset( $data['document']['sections'][0]['id'] );
		$els = $this->outline( $this->variant( 308, $fx, null, (string) json_encode( $data ) ) );
		self::assertSame( array( 'p0', 'row001', 'col001', 'p0.0.0.0', 'p0.0.0.1', 'blk003', 'p0.0.0.3' ), array_column( $els, 'id' ) );
		self::assertSame( 'p0', $els[1]['parent'] );
	}

	public function testUnknownBlocksLimitsAndBrokenJson(): void {
		$fx   = $this->fixture( 'page', 'seedprod' );
		$data = json_decode( $fx['post_content_filtered'], true );
		$data['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][2]['type'] = 'optin-form';
		$data['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][3]['type'] = 'countdown';
		$post = $this->variant( 309, $fx, null, (string) json_encode( $data ) );
		$by   = $this->byId( $this->outline( $post ) );
		self::assertSame( 'form', $by['blk003']['reason'] );
		self::assertSame( 'unknown element type', $by['blk004']['reason'] );
		self::assertCount( 5, $this->outline( $post, array( 'include_locked' => false ) ) );
		self::assertCount( 2, $this->outline( $post, array( 'max_elements' => 2 ) ) );
		$broken = $this->outline( $this->variant( 310, $fx, null, '{"document":' ) );
		self::assertCount( 1, $broken, 'Broken JSON is named, not shown as an empty page.' );
		self::assertSame( 'post_content_filtered', $broken[0]['type'] );
		self::assertSame( 'unreadable data', $broken[0]['reason'] );
		self::assertStringContainsString( 'the site shows post_content', $broken[0]['note'] );
		self::assertSame( array(), $this->outline( $this->variant( 310, $fx, null, '{"document":' ), array( 'include_locked' => false ) ) );
		self::assertSame( array(), $this->outline( $this->variant( 311, $fx, null, '' ) ) );
		self::assertSame( array(), $this->outline( $this->variant( 313, $fx, null, '{"document":{}}' ) ), 'A document without sections is an empty page.' );
	}

	public function testToolHashAndGuard(): void {
		$fx   = $this->fixture( 'page', 'seedprod' );
		$html = $this->fixture( 'editor-html', 'seedprod' );
		$post = $this->variant( 312, $fx, implode( ' ', $html['fragments']['sp-2-nach-editor.json'] ), $this->jsonAsPlanMeasured( $fx, $html ) );
		$out  = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 312 ) );
		self::assertSame( 'seedprod', $out['builder'] );
		self::assertSame( 'A2', $out['storage'] );
		self::assertSame( 'read', $out['support'] );
		self::assertTrue( $out['verified'] );
		self::assertSame(
			array(
				array( 'loc' => 'post_content', 'shown' => true, 'role' => 'copy' ),
				array( 'loc' => 'post_content_filtered', 'shown' => false, 'role' => 'source' ),
			),
			$out['copies']
		);
		$hash = AB_MCP_Builders::layout_hash( $post );
		$post->post_content_filtered = str_replace( 'Mo' . $this->esc( '2013' ) . 'Fr', 'Mo' . $this->esc( '2013' ) . 'Sa', $post->post_content_filtered );
		self::assertNotSame( $hash, AB_MCP_Builders::layout_hash( $post ), 'The JSON counts.' );
		$guard = AB_MCP_Builders::content_update_guard( $post );
		self::assertFalse( $guard['block'], 'A2: the change shows, with a warning.' );
		self::assertStringContainsString( 'SeedProd', $guard['message'] );
	}
}
