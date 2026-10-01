<?php
/**
 * What the readmes promise about page builders, held against the registry.
 *
 * readme.txt (wordpress.org) and README.md (GitHub) each carry a dated list
 * of the builders the free core reads and of those it only recognises. A
 * list written by hand goes stale the day an adapter is added, removed or
 * marked measured, so this test reads both lists and compares them with what
 * AB_MCP_Builders actually registers: every builder that can be recognised
 * must stand in the right list and in no other, and one that cannot (a
 * signature without any marker, such as Mosaic) in neither. The same goes
 * for the tool count, which the readmes state as a number.
 *
 * Names are matched by the part of the signature name before " (", so the
 * readme may write «Cornerstone» for «Cornerstone (X, Pro)» and no reader
 * takes «Pro» for the commercial add-on.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class BuilderReadmeTest extends TestCase {

	/** The readmes, relative to the plugin root. */
	const FILES = array( 'readme.txt', 'README.md' );

	protected function setUp(): void {
		ab_test_reset();
	}

	private function text( string $file ): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/' . $file );
		self::assertIsString( $text, $file );
		return $text;
	}

	/**
	 * The text of one list item of the builder section, its line breaks
	 * folded. The label may wrap like the rest of the item; an item ends with
	 * a full stop at the end of a line.
	 */
	private function item( string $text, string $label ): string {
		$pattern = str_replace( ' ', '\\s+', preg_quote( trim( $label ), '/' ) );
		$found   = preg_match_all( '/^\s*[-*] ' . $pattern . '\s(.+?)\.\s*$/ms', $text, $m );
		self::assertSame( 1, $found, 'Exactly one item «' . trim( $label ) . '».' );
		return (string) preg_replace( '/\s+/', ' ', $m[1][0] );
	}

	/** The paragraph that names the builders whose post_content is only a copy. */
	private function copy_only_sentence( string $text ): string {
		$found = preg_match_all( '/^\s*Where a builder shows its own data(.+?)instead\./ms', $text, $m );
		self::assertSame( 1, $found, 'Exactly one paragraph about builders that show their own data.' );
		return (string) preg_replace( '/\s+/', ' ', $m[1][0] );
	}

	private function names( string $list, string $name ): bool {
		return 1 === preg_match( '/(?<![\w])' . preg_quote( $name, '/' ) . '(?![\w])/u', $list );
	}

	private function short_name( string $name ): string {
		$cut = strpos( $name, ' (' );
		return false === $cut ? $name : substr( $name, 0, $cut );
	}

	public function testTheListIsDated(): void {
		foreach ( self::FILES as $file ) {
			self::assertMatchesRegularExpression(
				'/As of \d{1,2} (January|February|March|April|May|June|July|August|September|October|November|December) \d{4}:/',
				$this->text( $file ),
				$file . ' says on which day the builder list was true.'
			);
		}
	}

	public function testEveryKnownBuilderStandsInTheRightList(): void {
		$signatures = AB_MCP_Builders::signatures();
		self::assertGreaterThan( 20, count( $signatures ), 'The check sees the builders it is about.' );
		foreach ( self::FILES as $file ) {
			$text       = $this->text( $file );
			$read       = $this->item( $text, 'Read: ' );
			$recognised = $this->item( $text, 'Recognised, not read: ' );
			self::assertTrue( $this->names( $read, 'WordPress blocks' ), $file . ': plain block pages are read.' );
			foreach ( $signatures as $id => $sig ) {
				$name   = $this->short_name( $sig['name'] );
				$reader = AB_MCP_Builders::adapter_for_builder( $id );
				if ( array() === $sig['markers'] ) {
					// Nothing on a page marks it: never recognised, so named in no list.
					self::assertFalse( $this->names( $recognised, $name ), $file . ': ' . $name . ' has no marker, so it is never recognised.' );
					self::assertFalse( $this->names( $read, $name ), $file . ': ' . $name . ' has no marker, so it is never read.' );
				} elseif ( null !== $reader ) {
					self::assertTrue( $this->names( $read, $name ), $file . ': ' . $name . ' is read, so it stands under «Read».' );
					self::assertFalse( $this->names( $recognised, $name ), $file . ': ' . $name . ' is read, not only recognised.' );
				} else {
					self::assertTrue( $this->names( $recognised, $name ), $file . ': ' . $name . ' has no reader, so it stands under «Recognised, not read».' );
					self::assertFalse( $this->names( $read, $name ), $file . ': ' . $name . ' has no reader.' );
				}
			}
		}
	}

	public function testTheReadersNotYetMeasuredAreNamedAsSuch(): void {
		$unmeasured = array();
		foreach ( AB_MCP_Builders::adapters() as $adapter ) {
			if ( ! $adapter->verified() ) {
				foreach ( $adapter->builders() as $builder ) {
					$sig          = AB_MCP_Builders::signature( $builder );
					$unmeasured[] = $this->short_name( null !== $sig ? $sig['name'] : $adapter->name() );
				}
			}
		}
		self::assertNotSame( array(), $unmeasured, 'Some readers follow documentation only; the readmes must say which.' );
		foreach ( self::FILES as $file ) {
			$list = $this->item( $this->text( $file ), 'Read from the vendors\' documentation and code, not yet checked on a live installation (the answer says so): ' );
			foreach ( $unmeasured as $name ) {
				self::assertTrue( $this->names( $list, $name ), $file . ': the reader of ' . $name . ' is not measured.' );
			}
			foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
				$name   = $this->short_name( $sig['name'] );
				$reader = AB_MCP_Builders::adapter_for_builder( $id );
				if ( $this->names( $list, $name ) ) {
					self::assertTrue( null !== $reader && ! $reader->verified(), $file . ': ' . $name . ' is listed as not measured, but its reader is measured or missing.' );
				}
			}
		}
	}

	public function testTheBuildersWhosePostContentIsOnlyACopyAreNamed(): void {
		foreach ( self::FILES as $file ) {
			$sentence = $this->copy_only_sentence( $this->text( $file ) );
			foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
				$name    = $this->short_name( $sig['name'] );
				$guarded = 'B' === $sig['storage'] && null !== AB_MCP_Builders::adapter_for_builder( $id );
				self::assertSame(
					$guarded,
					$this->names( $sentence, $name ),
					$file . ': ' . $name . ( $guarded ? ' is storage B with a reader, so wp_update_post refuses content there.' : ' is not refused by wp_update_post.' )
				);
			}
		}
	}

	public function testTheToolCountIsTheNumberOfTools(): void {
		$names = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			if ( preg_match_all( '/->register\\(\\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		$count = count( array_unique( $names ) );
		self::assertGreaterThan( 20, $count, 'The count sees the tools.' );
		foreach ( self::FILES as $file ) {
			self::assertSame( 1, preg_match( '/(\d+) structured tools/', $this->text( $file ), $m ), $file );
			self::assertSame( $count, (int) $m[1], $file . ' states the number of tools the plugin registers.' );
		}
	}

	public function testTheBuilderTextsDoNotAdvertise(): void {
		// wordpress.org guideline 11: the free plugin's tool texts stay
		// neutral. Every translatable string of the builder code and the
		// tool description, read from the source.
		$texts = array( AB_MCP_Tools_Builders::DESCRIPTION );
		$files = array_merge(
			glob( dirname( __DIR__ ) . '/includes/builders/*.php' ),
			glob( dirname( __DIR__ ) . '/includes/builders/*/*.php' ),
			array( dirname( __DIR__ ) . '/includes/tools/class-tools-builders.php' )
		);
		foreach ( $files as $file ) {
			if ( preg_match_all( "/\\b__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents( $file ), $m ) ) {
				$texts = array_merge( $texts, $m[1] );
			}
		}
		self::assertGreaterThan( 10, count( $texts ), 'The check sees the texts.' );
		foreach ( $texts as $text ) {
			self::assertDoesNotMatchRegularExpression( '/\bPro\b|premium|upgrade|purchase|licen[cs]e/i', $text );
		}
	}
}
