<?php
/**
 * Element profiles (profiles/elements-*.php) and the reader they share:
 * which paths a profile may name, how values are shaped, conditions, lists,
 * shortcodes, and that every shipped entry follows the format.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builder_Element_Profile;
use AB_MCP_Builders;
use PHPUnit\Framework\TestCase;

final class ElementProfileTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		unset( $GLOBALS['shortcode_tags'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['shortcode_tags'] );
	}

	private function profile( array $elements, bool $verified = true ): array {
		add_filter(
			'ab_mcp_builder_element_profiles',
			static function ( $p, $builder ) use ( $elements, $verified ) {
				return 'mz' === $builder ? array( 'verified' => $verified, 'elements' => $elements ) : $p;
			},
			10,
			2
		);
		return AB_MCP_Builder_Element_Profile::get( 'mz' );
	}

	private function read( array $profile, string $type, $settings, array $o = array() ): array {
		$spec = $profile['elements'][ $type ] ?? null;
		return AB_MCP_Builder_Element_Profile::element(
			array( 'id' => 'e1', 'type' => $type, 'parent' => null, 'depth' => 0 ),
			$settings,
			$spec,
			$profile,
			AB_MCP_Builder_Element_Profile::options( $o )
		);
	}

	public function testShippedProfilesFollowTheFormat(): void {
		foreach ( array( 'beaver', 'siteorigin', 'seedprod' ) as $builder ) {
			$file = dirname( __DIR__ ) . '/includes/builders/profiles/elements-' . $builder . '.php';
			$raw  = include $file;
			$norm = AB_MCP_Builder_Element_Profile::get( $builder );
			self::assertSame( $builder, $norm['builder'] );
			self::assertTrue( $norm['verified'], $builder . ': measured.' );
			self::assertNotSame( '', $norm['source'] );
			self::assertSame( array_keys( $raw['elements'] ), array_keys( $norm['elements'] ), $builder . ': no entry dropped.' );
			foreach ( $raw['elements'] as $type => $spec ) {
				self::assertSame( array_keys( $spec['fields'] ?? array() ), array_keys( $norm['elements'][ $type ]['fields'] ), $builder . ' ' . $type . ': no field dropped.' );
			}
			self::assertNotNull( AB_MCP_Builders::signature( $builder ), $builder . ' has a signature.' );
			self::assertSame( $builder, AB_MCP_Builders::adapter_for_builder( $builder )->id(), $builder . ' has its adapter.' );
		}
		self::assertSame( array(), AB_MCP_Builder_Element_Profile::get( 'nope' )['elements'] );
		self::assertSame( array(), AB_MCP_Builder_Element_Profile::get( '../../wp-config' )['elements'], 'Only files in profiles/ are read.' );
	}

	public function testPathsToCodeStylesAndHandlersAreRefused(): void {
		$p = $this->profile(
			array(
				't' => array(
					'fields' => array(
						'text'                => array( 'kind' => 'text' ),
						'custom_css'          => array( 'kind' => 'text' ),
						'design.styleColor'   => array( 'kind' => 'text' ),
						'attributes.title'    => array( 'kind' => 'text' ),
						'onClick'             => array( 'kind' => 'url' ),
						'on_click'            => array( 'kind' => 'url' ),
						'embed_code'          => array( 'kind' => 'html' ),
						'html'                => array( 'kind' => 'html' ),
						'items.*.*.text'      => array( 'kind' => 'text' ),
						'title'               => array( 'kind' => 'bold' ),
						'items..text'         => array( 'kind' => 'text' ),
						'items.*.label'       => array( 'kind' => 'text' ),
					),
				),
			)
		);
		self::assertSame( array( 'text', 'items.*.label' ), array_keys( $p['elements']['t']['fields'] ) );
	}

	public function testKindsShapeTheValues(): void {
		$p   = $this->profile(
			array(
				't' => array(
					'fields' => array(
						'h'   => array( 'kind' => 'heading' ),
						'x'   => array( 'kind' => 'text' ),
						'r'   => array( 'kind' => 'html' ),
						'u'   => array( 'kind' => 'url' ),
						'bad' => array( 'kind' => 'url' ),
						'i'   => array( 'kind' => 'image' ),
						'j'   => array( 'kind' => 'image' ),
						'k'   => array( 'kind' => 'image' ),
						'n'   => array( 'kind' => 'text' ),
						'b'   => array( 'kind' => 'text' ),
						'm'   => array( 'kind' => 'text' ),
					),
				),
			)
		);
		$out = $this->read(
			$p,
			't',
			(object) array(
				'h'   => '<b>Fett</b> &amp; klar',
				'x'   => "Zeile\n<br>zwei<script>alert(1)</script>",
				'r'   => '<p style="color:red">Text <a href="https://example.com" onclick="x()">Link</a><img src=x onerror=y></p>',
				'u'   => ' https://example.com/a?b=1&c=2 ',
				'bad' => 'JaVaScRiPt:alert(1)',
				'i'   => '42',
				'j'   => 7.0,
				'k'   => 'data:image/svg+xml;base64,PHN2Zz4=',
				'n'   => null,
				'b'   => true,
				'm'   => array( 'nested' ),
			)
		);
		$f = $out['element']['fields'];
		self::assertSame( 'Fett & klar', $f['h']['value'] );
		self::assertSame( 'Zeile zwei', $f['x']['value'] );
		self::assertSame( 'Text <a href="https://example.com">Link</a>', $f['r']['value'] );
		self::assertSame( 'https://example.com/a?b=1&c=2', $f['u']['value'] );
		self::assertSame( 42, $f['i']['value'] );
		self::assertSame( 7, $f['j']['value'] );
		self::assertSame( array( 'h', 'x', 'r', 'u', 'i', 'j' ), array_keys( $f ), 'Code addresses, null, booleans and arrays are not fields.' );
		self::assertSame( 'bad: an address that would run code, left out; k: an address that would run code, left out', $out['element']['note'] );
	}

	public function testConditionsListsAndLocks(): void {
		$p = $this->profile(
			array(
				'b' => array(
					'fields' => array(
						'items.*.text' => array( 'kind' => 'text' ),
						'items.*.link' => array(
							'kind' => 'url',
							'when' => array( 'items.*.action' => array( '', 'link' ) ),
						),
						'caption'      => array(
							'kind' => 'text',
							'when' => array( 'show' => array( 'below' ) ),
						),
					),
				),
				'v' => array(
					'locked'      => 'code element',
					'locked_when' => array( 'type' => array( 'embed' ) ),
					'fields'      => array( 'title' => array( 'kind' => 'heading' ) ),
				),
				'g' => array(
					'locked' => 'global element',
					'global' => true,
					'ref'    => 'block_id',
				),
				'c' => array( 'container' => true ),
			)
		);
		$out = $this->read(
			$p,
			'b',
			array(
				'items'   => array(
					(object) array( 'text' => 'Eins', 'link' => '/eins' ),
					(object) array( 'text' => 'Zwei', 'link' => '/zwei', 'action' => 'lightbox' ),
				),
				'caption' => 'Unterschrift',
				'show'    => '0',
			)
		);
		self::assertSame( array( 'items.0.text', 'items.1.text', 'items.0.link' ), array_keys( $out['element']['fields'] ) );
		self::assertTrue( $out['children'] );

		self::assertSame( 'code element', $this->read( $p, 'v', array( 'type' => 'embed', 'title' => 'T' ) )['element']['reason'] );
		self::assertSame( array( 'title' => array( 'kind' => 'heading', 'value' => 'T' ) ), $this->read( $p, 'v', array( 'type' => 'file', 'title' => 'T' ) )['element']['fields'] );
		$g = $this->read( $p, 'g', array( 'block_id' => 'block-123' ) );
		self::assertSame( array( true, 'global element', true, 'its content is post #123' ), array( $g['element']['locked'], $g['element']['reason'], $g['element']['global'], $g['element']['note'] ) );
		self::assertFalse( $g['children'] );
		self::assertNull( $this->read( $p, 'g', array(), array( 'include_locked' => false ) )['element'] );
		self::assertSame( 'unknown element type', $this->read( $p, 'nope', array() )['element']['reason'] );
		self::assertSame( array( 'id', 'type', 'parent', 'depth' ), array_keys( $this->read( $p, 'c', array( 'x' => 'y' ) )['element'] ) );
	}

	public function testShortcodesLockAndOnASiteOnlyRegisteredOnesCount(): void {
		$p = $this->profile( array( 't' => array( 'fields' => array( 'text' => array( 'kind' => 'html' ) ) ) ) );
		self::assertSame( 'shortcode', $this->read( $p, 't', array( 'text' => 'Siehe [gallery ids="1"]' ) )['element']['reason'] );
		self::assertArrayNotHasKey( 'locked', $this->read( $p, 't', array( 'text' => 'Maskiert [[gallery]] und eine [ Klammer' ) )['element'] );

		$GLOBALS['shortcode_tags'] = array( 'gallery' => 'gallery_shortcode' );
		self::assertTrue( AB_MCP_Builder_Element_Profile::has_shortcode( 'x [gallery] y' ) );
		self::assertFalse( AB_MCP_Builder_Element_Profile::has_shortcode( 'x [sic] y' ), 'Not registered: WordPress leaves it as text.' );
		unset( $GLOBALS['shortcode_tags'] );
		self::assertTrue( AB_MCP_Builder_Element_Profile::has_shortcode( 'x [sic] y' ), 'Without the registry every tag counts.' );
	}

	public function testUnmeasuredEntriesSayItAndValueAtWalksArraysAndObjects(): void {
		$p   = $this->profile(
			array(
				't' => array(
					'fields' => array(
						'a' => array( 'kind' => 'text' ),
						'b' => array( 'kind' => 'text', 'verified' => false ),
					),
				),
			)
		);
		$one = $this->read( $p, 't', array( 'a' => 'A' ) );
		self::assertArrayNotHasKey( 'note', $one['element'] );
		$two = $this->read( $p, 't', array( 'a' => 'A', 'b' => 'B' ) );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNMEASURED, $two['element']['note'] );

		$data = (object) array( 'list' => array( (object) array( 'deep' => array( 'x' => 'X' ) ) ), 'n' => null );
		self::assertSame( 'X', AB_MCP_Builder_Element_Profile::value_at( $data, 'list.0.deep.x' ) );
		self::assertNull( AB_MCP_Builder_Element_Profile::value_at( $data, 'list.1.deep' ) );
		self::assertNull( AB_MCP_Builder_Element_Profile::value_at( $data, 'n' ) );
		self::assertNull( AB_MCP_Builder_Element_Profile::value_at( 'text', 'x' ) );
	}

	public function testOptionsDefaultsAndLimits(): void {
		self::assertSame( array( 'include_locked' => true, 'max_elements' => 500, 'max_field_chars' => 2000 ), AB_MCP_Builder_Element_Profile::options( array() ) );
		self::assertSame( array( 'include_locked' => false, 'max_elements' => 1, 'max_field_chars' => 100 ), AB_MCP_Builder_Element_Profile::options( array( 'include_locked' => false, 'max_elements' => 0, 'max_field_chars' => 5 ) ) );
	}
}
