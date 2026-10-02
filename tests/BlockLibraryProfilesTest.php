<?php
/**
 * The block-library profiles as data (GenerateBlocks, Kadence, Spectra,
 * Stackable, Pagelayer): what every profile must hold, checked on all of them
 * and on every fixture of the measurement.
 *
 * The library-specific behaviour is in the test class of each library.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builder_Html;
use AB_MCP_Builders;
use PHPUnit\Framework\TestCase;

final class BlockLibraryProfilesTest extends TestCase {

	use BuilderTestHelpers;

	/** Profile id => builder id in signatures.php. */
	const LIBRARIES = array(
		'generateblocks' => 'generateblocks',
		'kadence'        => 'kadence',
		'pagelayer'      => 'pagelayer',
		'spectra'        => 'spectra',
		'stackable'      => 'stackable',
	);

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * Every fixture of the block libraries: [ builder dir, name ].
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function fixtures(): array {
		$out = array();
		foreach ( array( 'generateblocks', 'kadence', 'pagelayer', 'spectra', 'stackable' ) as $dir ) {
			foreach ( (array) glob( dirname( __FILE__ ) . '/fixtures/builders/' . $dir . '/*.json' ) as $file ) {
				$out[] = array( $dir, basename( (string) $file, '.json' ) );
			}
		}
		return $out;
	}

	/** The raw profile file, as written. */
	private function rawProfile( string $id ): array {
		return include dirname( __DIR__ ) . '/includes/builders/profiles/blocks-' . $id . '.php';
	}

	/** The loaded (normalised) profile. */
	private function profile( string $id ): array {
		foreach ( AB_MCP_Block_Reader::profiles() as $profile ) {
			if ( $id === $profile['id'] ) {
				return $profile;
			}
		}
		self::fail( 'Profile ' . $id . ' is loaded.' );
	}

	public function testEveryLibraryProfileIsLoadedAfterCoreInFileOrder(): void {
		self::assertSame( array( 'core', 'generateblocks', 'kadence', 'pagelayer', 'spectra', 'stackable' ), array_column( AB_MCP_Block_Reader::profiles(), 'id' ) );
	}

	public function testEveryProfileSpeaksForAKnownBlockLibrary(): void {
		foreach ( self::LIBRARIES as $id => $builder ) {
			$profile = $this->profile( $id );
			self::assertSame( $builder, $profile['builder'], $id );
			self::assertTrue( $profile['verified'], $id . ' was measured.' );
			self::assertNotSame( '', $profile['source'], $id . ' names its source.' );
			self::assertNotSame( '', $profile['id_attr'], $id . ' names the attribute with its element id.' );
			$sig = AB_MCP_Builders::signature( $builder );
			self::assertIsArray( $sig, $id );
			self::assertSame( 'blocks', $sig['family'], $id . ' is read by the block reader.' );
			self::assertSame( 'A', $sig['storage'], $id );
			foreach ( array_keys( $profile['blocks'] ) as $name ) {
				$inside = false;
				foreach ( $profile['namespaces'] as $prefix ) {
					$inside = $inside || 0 === strpos( $name, $prefix );
				}
				self::assertTrue( $inside, $name . ' belongs to the namespaces of ' . $id );
				self::assertStringContainsString( '<!-- wp:' . strtok( $name, '/' ) . '/', $sig['markers'][0]['content'], $name . ' is recognised by the marker of ' . $builder );
			}
		}
	}

	public function testEveryDeclaredFieldAndCopyCanBeRead(): void {
		// The reader drops a field it may not read (CSS, styles, classes,
		// attribute maps, code, event handlers) and a copy without a source.
		// A profile that lists one is wrong as written: every field and copy
		// declared in a profile file has to survive, so a code field added to
		// a positive list turns this red.
		foreach ( array_keys( self::LIBRARIES ) as $id ) {
			$raw  = $this->rawProfile( $id );
			$norm = $this->profile( $id );
			self::assertSame( array_keys( $raw['blocks'] ), array_keys( $norm['blocks'] ), $id );
			foreach ( $raw['blocks'] as $name => $spec ) {
				$fields = $spec['fields'] ?? array();
				self::assertSame( array_keys( $fields ), array_keys( $norm['blocks'][ $name ]['fields'] ), $name );
				foreach ( $fields as $field_name => $field ) {
					self::assertCount( count( $field['also'] ?? array() ), $norm['blocks'][ $name ]['fields'][ $field_name ]['also'], $name . ' ' . $field_name . ': every copy is compared.' );
				}
				self::assertSame( $spec['locked_when'] ?? array(), $norm['blocks'][ $name ]['locked_when'], $name );
			}
		}
	}

	public function testNoFieldReadsCodeStylingOrAttributeMaps(): void {
		// The paths of plan §8 that are never fields: GenerateBlocks css,
		// styles and htmlAttributes; Kadence kadenceBlockCSS; Stackable
		// customCSS; Pagelayer ele_css and ele_attributes. Only copies may
		// name htmlAttributes (they are compared, never handed out).
		foreach ( array( 'css', 'styles', 'htmlAttributes', 'kadenceBlockCSS', 'customCSS', 'ele_css', 'ele_attributes' ) as $path ) {
			self::assertTrue( AB_MCP_Block_Reader::denied_segment( $path ), $path );
		}
		foreach ( array_keys( self::LIBRARIES ) as $id ) {
			foreach ( $this->rawProfile( $id )['blocks'] as $name => $spec ) {
				foreach ( $spec['fields'] ?? array() as $field_name => $field ) {
					if ( 'html_attr' === $field['from'] ) {
						self::assertContains( $field['attr'], AB_MCP_Block_Reader::HTML_ATTRS, $name . ' ' . $field_name );
					}
					foreach ( explode( '.', (string) ( $field['path'] ?? '' ) ) as $segment ) {
						self::assertFalse( '' !== $segment && AB_MCP_Block_Reader::denied_segment( $segment ), $name . ' ' . $field_name . ' reads ' . $segment );
					}
				}
			}
		}
	}

	public function testTheCopiedParserRoundTripsEveryFixtureByteForByte(): void {
		// The measurement found serialize_blocks( parse_blocks( x ) ) === x
		// for every sample of the libraries; so must the reader's parser.
		$checked = 0;
		foreach ( $this->fixtures() as list( $dir, $name ) ) {
			$content = $this->fixture( $name, $dir )['content'];
			self::assertSame( $content, serialize_blocks( parse_blocks( $content ) ), $dir . '/' . $name );
			++$checked;
		}
		self::assertGreaterThanOrEqual( 38, $checked, 'Every fixture was checked.' );
	}

	public function testNoFixtureLeaksCodeStylingOrAttributes(): void {
		// Nothing of what the builders save around the content may reach an
		// outline: classes, inline and block CSS, SVG icons, scripts, the
		// attribute maps, placeholders of CSS selectors.
		$never = array( 'class=', 'style=', '<style', '<script', '<svg', '<path', 'var(--', 'aspect-ratio', 'margin-bottom', 'gb-text-', 'kt-adv-heading', 'stk-block', 'stk-img', 'stk-button', 'uagb-block-', 'data-block-id', '{{element}}', 'window.mz', 'srcset', 'palette', 'focussable', 'rel=', 'target=' );
		foreach ( $this->fixtures() as list( $dir, $name ) ) {
			$all = $this->flat( AB_MCP_Block_Reader::outline( $this->fixture( $name, $dir )['content'], array() ) );
			foreach ( $never as $needle ) {
				self::assertStringNotContainsString( $needle, $all, $dir . '/' . $name . ': ' . $needle );
			}
			// A note may name where a stale copy lives; no value may hold the map.
			$values = $this->flat( array_column( AB_MCP_Block_Reader::outline( $this->fixture( $name, $dir )['content'], array() ), 'fields' ) );
			self::assertStringNotContainsString( 'htmlAttributes', $values, $dir . '/' . $name );
		}
	}

	public function testContainersHoldNoTextOfTheirOwnOnTheMeasuredPages(): void {
		// "container" means: structure only, no field. Where a measured page
		// has such a block, its own markup (without inner blocks) must indeed
		// show no text — else the profile would hide visible content.
		$containers = array();
		foreach ( array_keys( self::LIBRARIES ) as $id ) {
			foreach ( $this->profile( $id )['blocks'] as $name => $spec ) {
				if ( $spec['container'] ) {
					$containers[ $name ] = 0;
				}
			}
		}
		$walk = static function ( array $blocks ) use ( &$walk, &$containers ): void {
			foreach ( $blocks as $block ) {
				if ( isset( $containers[ (string) $block['blockName'] ] ) ) {
					$own = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
					self::assertSame( '', AB_MCP_Builder_Html::text( $own ), $block['blockName'] );
					++$containers[ $block['blockName'] ];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		foreach ( $this->fixtures() as list( $dir, $name ) ) {
			$walk( parse_blocks( $this->fixture( $name, $dir )['content'] ) );
		}
		// Containers the fixtures do not hold, because the measurement files
		// keep only the measured blocks of those samples, not the whole
		// pattern: GenerateBlocks element (2.x) and container (1.x), Stackable
		// columns and column (design-library template). For the GenerateBlocks
		// element the measured page output shows it without text of its own
		// (ergebnis-gb2.json, varianten[*].do_blocks_ausschnitt).
		$unseen = array_keys(
			array_filter(
				$containers,
				static function ( int $n ): bool {
					return 0 === $n;
				}
			)
		);
		sort( $unseen );
		self::assertSame( array( 'generateblocks/container', 'generateblocks/element', 'stackable/column', 'stackable/columns' ), $unseen );
	}

	public function testElementIdsAreTheLibraryIdsAndUniqueOnEveryMeasuredPage(): void {
		$pages = array(
			array( 'kadence', 'example-page', 'uniqueID' ),
			array( 'spectra', 'about-24', 'block_id' ),
			array( 'spectra', 'about-20', 'block_id' ),
			array( 'stackable', 'preview-page', 'uniqueId' ),
			array( 'pagelayer', 'ability-page', 'pagelayer-id' ),
			array( 'pagelayer', 'after-editor', 'pagelayer-id' ),
		);
		foreach ( $pages as list( $dir, $name, $attr ) ) {
			$content  = $this->fixture( $name, $dir )['content'];
			$elements = AB_MCP_Block_Reader::outline( $content, array() );
			$ids      = array_column( $elements, 'id' );
			self::assertSame( count( $ids ), count( array_unique( $ids ) ), $name );
			preg_match_all( '~"' . preg_quote( $attr, '~' ) . '":"([^"]+)"~', $content, $m );
			self::assertSame( $m[1], $ids, $name . ': every element by its ' . $attr . ', in page order' );
		}
	}
}
