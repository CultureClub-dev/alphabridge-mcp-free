<?php
/**
 * The builder registry: the contract constant, the list of known builders,
 * where every entry of it comes from, and how adapters are registered.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builder_Adapter_Blocks;
use AB_MCP_Builder_Adapter_Shortcodes;
use AB_MCP_Builders;
use PHPUnit\Framework\TestCase;

final class BuilderRegistryTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	public function testTheContractVersionIsTheIntegerOne(): void {
		// Pro compares it as a number before it loads its builder parts.
		self::assertTrue( defined( 'AB_MCP_BUILDER_API' ) );
		self::assertSame( 1, AB_MCP_BUILDER_API );
	}

	public function testEveryBuilderOfThePlanIsKnown(): void {
		$expected = array(
			'elementor', 'wpbakery', 'divi4', 'divi5', 'avada', 'spectra', 'seedprod', 'kadence', 'siteorigin',
			'pagelayer', 'betheme', 'enfold', 'flatsome', 'cornerstone', 'generateblocks', 'beaver', 'stackable',
			'brizy', 'bricks', 'visualcomposer', 'oxygen', 'oxygen-classic', 'breakdance', 'thrive', 'livecomposer',
			'themify', 'zion', 'etch', 'mosaic', 'otter', 'coblocks',
		);
		$ids = AB_MCP_Builders::builder_ids();
		foreach ( $expected as $id ) {
			self::assertContains( $id, $ids, $id . ' is listed.' );
		}
		self::assertCount( count( $expected ), $ids, 'Nothing listed beyond the plan.' );
	}

	public function testEverySignatureLineNamesItsSource(): void {
		// The rule for signatures.php: nothing without a source. Every line that
		// holds a marker, an active check, a copy, a storage kind or locked keys
		// carries a comment, or follows one.
		$lines   = file( dirname( __DIR__ ) . '/includes/builders/signatures.php' );
		$checked = 0;
		foreach ( $lines as $n => $line ) {
			if ( ! preg_match( "~array\\( '(meta|content|content_prefix|content_regex|constant|class|function|plugin|theme|loc)' =>|'storage'\\s+=>|'locked_meta'\\s+=> array\\( '|'active'\\s+=> array\\(\\),|'markers'\\s+=> array\\(\\),~", $line ) ) {
				continue;
			}
			++$checked;
			$commented = false !== strpos( $line, '//' ) || 1 === preg_match( '~^\s*//~', (string) ( $lines[ $n - 1 ] ?? '' ) );
			self::assertTrue( $commented, 'signatures.php line ' . ( $n + 1 ) . ' has no source: ' . trim( $line ) );
		}
		self::assertGreaterThan( 120, $checked, 'The check saw the lines it is about.' );
	}

	public function testEverySignatureIsComplete(): void {
		foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
			self::assertNotSame( '', $sig['name'], $id );
			self::assertContains( $sig['storage'], array( 'A', 'A2', 'B', 'C' ), $id );
			self::assertContains( $sig['family'], array( 'blocks', 'blocks-json', 'shortcodes', 'meta', 'tables' ), $id );
			if ( 'mosaic' !== $id ) {
				self::assertNotEmpty( $sig['markers'], $id . ' can be recognised.' );
				self::assertNotEmpty( $sig['copies'], $id . ' says where the page is.' );
			}
		}
	}

	public function testMalformedSignaturesAndMarkersAreDropped(): void {
		add_filter(
			'ab_mcp_builder_signatures',
			static function ( $sigs ) {
				$sigs['broken']  = 'not an array';
				$sigs['patterns'] = array(
					'name'    => 'Patterns',
					'markers' => array(
						array( 'content_regex' => '~(unclosed~' ),
						array( 'content' => '' ),
						array( 'meta' => 'ok_key' ),
						'nonsense',
					),
					'active'  => array( array( 'constant' => 'X', 'class' => 'Y' ), array( 'shell' => 'rm' ), array( 'theme' => 'kept' ) ),
					'copies'  => array( array( 'loc' => 'option:x' ), array( 'loc' => 'meta:ok_key', 'shown' => true, 'role' => 'source' ) ),
				);
				return $sigs;
			}
		);
		AB_MCP_Builders::reset();
		self::assertNull( AB_MCP_Builders::signature( 'broken' ) );
		$sig = AB_MCP_Builders::signature( 'patterns' );
		self::assertSame( array( array( 'meta' => 'ok_key' ) ), $sig['markers'], 'Only the usable marker stays.' );
		self::assertSame( array( array( 'theme' => 'kept' ) ), $sig['active'], 'An active check names one thing, of a known kind.' );
		self::assertSame( array( array( 'loc' => 'meta:ok_key', 'shown' => true, 'role' => 'source' ) ), $sig['copies'] );
		self::assertSame( 'A', $sig['storage'], 'A missing storage kind is A, the plain case.' );
	}

	public function testTheBlockReaderIsRegisteredAndReadsEveryBlockLibrary(): void {
		$adapters = AB_MCP_Builders::adapters();
		self::assertArrayHasKey( 'blocks', $adapters );
		self::assertInstanceOf( AB_MCP_Builder_Adapter_Blocks::class, $adapters['blocks'] );
		foreach ( array( 'blocks', 'generateblocks', 'kadence', 'spectra', 'stackable', 'pagelayer', 'otter', 'coblocks' ) as $id ) {
			self::assertSame( $adapters['blocks'], AB_MCP_Builders::adapter_for_builder( $id ), $id );
		}
		// Blocks that keep their text in JSON attributes are not read as blocks.
		self::assertNull( AB_MCP_Builders::adapter_for_builder( 'divi5' ) );
		self::assertNull( AB_MCP_Builders::adapter_for_builder( 'etch' ) );
		self::assertNull( AB_MCP_Builders::adapter_for_builder( 'elementor' ), 'No Elementor reader in this build.' );
	}

	public function testAdaptersComeThroughTheFilterAndTheLastOneWins(): void {
		$first  = new FakeBuilderAdapter( 'elementor' );
		$second = new FakeBuilderAdapter( 'elementor' );
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) use ( $first, $second ) {
				$list[] = $first;
				$list[] = 'not an adapter';
				$list[] = $second;
				return $list;
			}
		);
		AB_MCP_Builders::reset();
		self::assertSame( $second, AB_MCP_Builders::adapters()['elementor'] );
		self::assertSame( $second, AB_MCP_Builders::adapter_for_builder( 'elementor' ) );
		self::assertCount( 2 + count( AB_MCP_Builder_Adapter_Shortcodes::defaults() ), AB_MCP_Builders::adapters(), 'blocks, the shortcode readers and elementor, nothing else.' );
	}

	public function testADedicatedAdapterTakesOverABlockLibrary(): void {
		$kadence = new FakeBuilderAdapter( 'kadence' );
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) use ( $kadence ) {
				$list[] = $kadence;
				return $list;
			}
		);
		AB_MCP_Builders::reset();
		self::assertSame( $kadence, AB_MCP_Builders::adapter_for_builder( 'kadence' ) );
		self::assertInstanceOf( AB_MCP_Builder_Adapter_Blocks::class, AB_MCP_Builders::adapter_for_builder( 'spectra' ) );
	}

	public function testSupportFollowsTheAdapters(): void {
		$this->builders( array( 'elementor' ) );
		$post = $this->page( 30, '<p>copy</p>', array( '_elementor_edit_mode' => 'builder' ) );
		$all  = AB_MCP_Builders::detect_all( $post );
		self::assertSame( 'detected_only', $all[0]['support'] );

		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) {
				$list[] = new FakeBuilderAdapter( 'elementor' );
				return $list;
			}
		);
		AB_MCP_Builders::reset();
		$all = AB_MCP_Builders::detect_all( $post );
		self::assertSame( 'read', $all[0]['support'] );
	}

	public function testAThirdPartyAdapterWithoutSignatureRecognisesItsOwnPages(): void {
		$own = new class() extends \AB_MCP_Builder_Adapter {
			public function id(): string {
				return 'acme';
			}
			public function name(): string {
				return 'Acme Builder';
			}
			public function detect( $post ): bool {
				return false !== strpos( (string) $post->post_content, '[acme' );
			}
			public function is_active(): bool {
				return true;
			}
			public function storage( $post ): string {
				return 'A';
			}
			public function outline( $post, array $o ): array {
				return array();
			}
		};
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) use ( $own ) {
				$list[] = $own;
				return $list;
			}
		);
		AB_MCP_Builders::reset();
		$all = AB_MCP_Builders::detect_all( $this->page( 31, '[acme]x[/acme]' ) );
		self::assertSame( 'acme', $all[0]['id'] );
		self::assertSame( 'Acme Builder', $all[0]['name'] );
		self::assertSame( array( 'adapter:acme' ), $all[0]['detected_by'] );
		self::assertSame( array(), AB_MCP_Builders::detect_all( $this->page( 32, 'plain' ) ) );
	}
}
