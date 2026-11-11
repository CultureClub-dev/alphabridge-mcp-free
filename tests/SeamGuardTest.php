<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C; Codex round 1,
 * A5): no shipped code reads or sets a seam through a filter, an option,
 * Reflection or another way past the one real calls class
 * (AB_MCP_Test_Seam_Guard, a positive rule: two statements only). Over the
 * shipped tree, then each rule and each way around on a fixture that breaks
 * only it (tests/fixtures/seams/bad/), and the examples that keep them all.
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

	public function testTheTwoAllowedStatementsAloneKeepEveryRule(): void {
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'good-both-shapes.php' ) ) ) );
	}

	/**
	 * One fixture per rule and per way around, and what the guard says.
	 *
	 * @return array<string,array{0:string[],1:string}>
	 */
	public static function broken(): array {
		$other = 'occurs other than as';
		return array(
			'public'                  => array( array( 'public.php' ), 'must be private static' ),
			'not static'              => array( array( 'instance.php' ), 'must be private static' ),
			'typed'                   => array( array( 'typed.php' ), 'must have no type' ),
			'default'                 => array( array( 'default.php' ), 'starts as something other than null' ),
			'filter returns it'       => array( array( 'filter-get.php' ), $other . ' «return self::$calls ?? new <Class>();» or «self::$calls = null;»: «return apply_filters(' ),
			'filter by reference'     => array( array( 'filter-ref-array.php' ), 'apply_filters_ref_array' ),
			'filter sets it'          => array( array( 'filter-set.php' ), 'self::$calls = apply_filters(' ),
			'option'                  => array( array( 'option.php' ), 'get_option' ),
			'site option'             => array( array( 'site-option.php' ), 'get_site_option' ),
			'transient'               => array( array( 'transient.php' ), 'get_transient' ),
			'reflection setter'       => array( array( 'reflection-setter.php' ), 'getProperty reaches past private' ),
			'assigned a new object'   => array( array( 'assign-new.php' ), $other ),
			'lazy ??='                => array( array( 'lazy.php' ), $other ),
			'static::'                => array( array( 'static-form.php' ), $other ),
			'class name'              => array( array( 'name-form.php' ), $other ),
			'read into a variable'    => array( array( 'read-variable.php' ), '$c = self::$calls;' ),
			'dynamic static property' => array( array( 'dynamic.php' ), 'dynamic static property' ),
			'arguments'               => array( array( 'with-args.php' ), $other ),
			'variable class name'     => array( array( 'variable-new.php' ), $other ),
			'two classes'             => array( array( 'two-classes.php' ), 'more than one class: Seam_Calls, Seam_Other_Calls' ),
			'anonymous class'         => array( array( 'anonymous.php' ), $other ),
			'reset in an expression'  => array( array( 'reset-expression.php' ), '$old = self::$calls = null;' ),
			'outside'                 => array( array( 'outside-owner.php', 'outside-writer.php' ), 'is reached outside its class' ),
			'variable owner'          => array( array( 'variable-owner.php' ), 'through a variable class' ),
			'tag on a method'         => array( array( 'tag-on-method.php' ), 'stands on no property' ),
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
		foreach ( array( 'ReflectionProperty', 'setAccessible', 'setStaticPropertyValue', 'getStaticProperties', 'getStaticPropertyValue', 'ReflectionObject', 'getProperties', 'Closure::bind', 'bindTo', '->call' ) as $way ) {
			self::assertStringContainsString( $way . ' reaches past private', $said );
		}
		self::assertStringNotContainsString( 'ReflectionClass reaches', $said, 'ReflectionClass alone (getFileName() in the plugin) is no way past private.' );
	}

	public function testTheOwnerAloneIsNoViolation(): void {
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/outside-owner.php' ) ) ), 'Only the class that reaches in from outside breaks the rule.' );
	}
}
