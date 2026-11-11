<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C): no shipped code
 * opens a seam through a filter, an option or another way past the one real
 * calls class (AB_MCP_Test_Seam_Guard). Over the shipped tree, then each
 * rule on a fixture that breaks only it (tests/fixtures/seams/bad/), and the
 * example that keeps them all.
 *
 * Today the shipped tree has no seam; the heikel/ branches 2–9 of Z8b add
 * theirs with the tag @ab-mcp-seam, and this test holds them from then on.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Test_Seam_Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/integration/support/class-test-seam-guard.php';

final class SeamGuardTest extends TestCase {

	private static function fixture( string $name ): string {
		return __DIR__ . '/fixtures/seams/' . $name;
	}

	/**
	 * Every PHP file a release carries (as ShippedCodeKnowsNoTestFetcherTest
	 * reads it: .gitattributes export-ignore).
	 *
	 * @return string[]
	 */
	private static function shipped(): array {
		$root    = dirname( __DIR__ );
		$ignored = array( '.git', 'vendor', 'node_modules' );
		foreach ( (array) file( $root . '/.gitattributes', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '#^/([^\s/]+)\s+export-ignore\b#', (string) $line, $m ) ) {
				$ignored[] = $m[1];
			}
		}
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( ! in_array( explode( '/', $relative )[0], $ignored, true ) && 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	public function testTheShippedCodeOpensNoSeam(): void {
		$files = self::shipped();
		self::assertGreaterThan( 100, count( $files ), 'The scan sees the plugin.' );
		self::assertContains( dirname( __DIR__ ) . '/includes/class-outbound.php', $files );
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( $files ) );
	}

	public function testTheExampleKeepsEveryRuleAndIsFound(): void {
		$file  = self::fixture( 'class-test-seam-example.php' );
		$seams = AB_MCP_Test_Seam_Guard::seams( array( $file ) );
		self::assertCount( 1, $seams );
		self::assertSame( 'AB_MCP_Test_Example_Swap', $seams[0]['class'] );
		self::assertSame( 'calls', $seams[0]['property'] );
		self::assertTrue( $seams[0]['private'] );
		self::assertTrue( $seams[0]['static'] );
		self::assertFalse( $seams[0]['typed'] );
		self::assertSame( 'null', $seams[0]['default'] );
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( $file ) ) );
	}

	/**
	 * One fixture per rule, and what the guard says about it.
	 *
	 * @return array<string,array{0:string[],1:string}>
	 */
	public static function broken(): array {
		return array(
			'public'          => array( array( 'public.php' ), 'must be private static' ),
			'not static'      => array( array( 'instance.php' ), 'must be private static' ),
			'typed'           => array( array( 'typed.php' ), 'must have no type' ),
			'default'         => array( array( 'default.php' ), 'starts as something other than null' ),
			'filter'          => array( array( 'filter.php' ), 'apply_filters' ),
			'option'          => array( array( 'option.php' ), 'get_option' ),
			'variable class'  => array( array( 'variable-new.php' ), 'new $class ( ) ;' ),
			'arguments'       => array( array( 'with-args.php' ), 'other than new <Class>() or null' ),
			'two classes'     => array( array( 'two-classes.php' ), 'more than one class: Seam_Calls, Seam_Other_Calls' ),
			'outside'         => array( array( 'outside-owner.php', 'outside-writer.php' ), 'is written outside its class' ),
			'variable owner'  => array( array( 'variable-owner.php' ), 'written through a variable class' ),
			'tag on a method' => array( array( 'tag-on-method.php' ), 'stands on no property' ),
		);
	}

	/**
	 * @param string[] $files Fixtures.
	 */
	#[DataProvider( 'broken' )]
	public function testEachRuleIsHeld( array $files, string $says ): void {
		$paths      = array_map( static fn( $f ) => self::fixture( 'bad/' . $f ), $files );
		$violations = AB_MCP_Test_Seam_Guard::violations( $paths );
		self::assertNotSame( array(), $violations, 'The guard let ' . implode( ', ', $files ) . ' pass.' );
		self::assertStringContainsString( $says, implode( "\n", $violations ) );
	}

	public function testEveryWayPastPrivateIsNamed(): void {
		$said = implode( "\n", AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/reflection.php' ) ) ) );
		foreach ( array( 'ReflectionProperty', 'setStaticPropertyValue', 'bind reaches', 'bindTo' ) as $way ) {
			self::assertStringContainsString( $way, $said );
		}
		self::assertStringNotContainsString( 'ReflectionClass reaches', $said, 'ReflectionClass alone (getFileName() in the plugin) is no way past private.' );
	}

	public function testTheOwnerAloneIsNoViolation(): void {
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/outside-owner.php' ) ) ), 'Only the writer from outside breaks the rule.' );
	}
}
