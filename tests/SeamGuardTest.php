<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C; Codex round 1,
 * A5; Behebung 2): the name of a seam occurs in the shipped tree only in its
 * declaration and its two statements (AB_MCP_Test_Seam_Guard). Over the
 * shipped tree, then each rule and each way around on a fixture that breaks
 * only it (tests/fixtures/seams/bad/), the examples that keep them all, and
 * the limit G9.
 *
 * Today the shipped tree has no seam; the heikel/ branches 2–9 of Z8b add
 * theirs, each with a name that begins with naht_, and this test holds them
 * from then on.
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
		self::assertSame( 'naht_test_example_swap', $seams[0]['property'] );
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
		$outside = 'occurs outside its declaration and its two statements';
		return array(
			'public'                  => array( array( 'public.php' ), 'must be private static' ),
			'not static'              => array( array( 'instance.php' ), 'must be private static' ),
			'typed'                   => array( array( 'typed.php' ), 'must have no type' ),
			'default'                 => array( array( 'default.php' ), 'starts as something other than null' ),
			'filter returns it'       => array( array( 'filter-get.php' ), $outside . ' «return self::$naht_bad_filter_get ?? new <Class>();» and «self::$naht_bad_filter_get = null;»: «final class Seam_Bad_Filter_Get' ),
			'filter by reference'     => array( array( 'filter-ref-array.php' ), $outside ),
			'filter sets it'          => array( array( 'filter-set.php' ), $outside ),
			'option'                  => array( array( 'option.php' ), $outside ),
			'site option'             => array( array( 'site-option.php' ), $outside ),
			'transient'               => array( array( 'transient.php' ), $outside ),
			'reflection setter'       => array( array( 'reflection-setter.php' ), 'the name naht_bad_reflection_setter of the seam Seam_Bad_Reflection_Setter::$naht_bad_reflection_setter ' . $outside ),
			'assigned a new object'   => array( array( 'assign-new.php' ), $outside ),
			'lazy ??='                => array( array( 'lazy.php' ), $outside ),
			'static::'                => array( array( 'static-form.php' ), $outside ),
			'class name'              => array( array( 'name-form.php' ), $outside ),
			'read into a variable'    => array( array( 'read-variable.php' ), $outside ),
			'dynamic static property' => array( array( 'dynamic.php' ), $outside ),
			'arguments'               => array( array( 'with-args.php' ), $outside ),
			'variable class name'     => array( array( 'variable-new.php' ), $outside ),
			'two classes'             => array( array( 'two-classes.php' ), 'more than one class: Seam_Calls, Seam_Other_Calls' ),
			'anonymous class'         => array( array( 'anonymous.php' ), $outside ),
			'reset in an expression'  => array( array( 'reset-expression.php' ), $outside ),
			'outside'                 => array( array( 'outside-owner.php', 'outside-writer.php' ), 'outside-writer.php:3: the name naht_bad_outside' ),
			'another class, same file' => array( array( 'neighbour.php' ), 'the name naht_bad_neighbour' ),
			'variable owner'          => array( array( 'variable-owner.php' ), $outside ),
			'declared twice'          => array( array( 'duplicate.php' ), 'is declared more than once' ),
			'one name in another'     => array( array( 'nested-name.php' ), 'holds the name of the seam naht_bad_nested' ),
			'name in a comment'       => array( array( 'comment.php' ), $outside ),
			'prefix put together'     => array( array( 'prefix-composed.php' ), 'the prefix naht_ of the seams occurs outside a seam' ),
			'lower-case reflection'   => array( array( 'reflection-lowercase.php' ), 'the name naht_bad_lower of the seam Seam_Bad_Lower::$naht_bad_lower ' . $outside ),
			'a trait writes it'       => array( array( 'trait.php' ), 'the name naht_bad_trait of the seam Seam_Bad_Trait_Owner::$naht_bad_trait ' . $outside ),
			'a bound closure'         => array( array( 'closure-owner.php', 'closure-bind.php' ), 'closure-bind.php:3: the name naht_bad_closure' ),
			'a closure called'        => array( array( 'closure-owner.php', 'closure-call.php' ), 'closure-call.php:3: the name naht_bad_closure' ),
			'the name as a string'    => array( array( 'string-access.php' ), 'the name naht_bad_string of the seam Seam_Bad_String::$naht_bad_string ' . $outside ),
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

	public function testEveryAccessWithTheFullNameAsAStringIsSeen(): void {
		$said = AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/string-access.php' ) ) );
		$name = array_filter( $said, static fn( $v ) => false !== strpos( $v, 'the name naht_bad_string' ) );
		self::assertCount( 3, $name, 'The name in $object->{…}, in upper case in a lower-case Reflection call, in property_exists(): ' . implode( "\n", $said ) );
	}

	public function testReflectionThatNeedsNoNameIsNamedInAnyCase(): void {
		$said = implode( "\n", AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/enumerate.php' ) ) ) );
		foreach ( array( 'getProperties', 'setValue', 'GetStaticProperties', 'setStaticPropertyValue', 'getstaticpropertyvalue' ) as $way ) {
			self::assertStringContainsString( $way . ' reaches properties without naming them', $said );
		}
	}

	public function testANameComposedWithoutThePrefixIsBeyondTheGuardG9(): void {
		// GRENZEN-PRUEFUMGEBUNG.md, G9: a name put together at run time
		// without the prefix written out shows nothing the guard could see.
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'beyond-runtime.php' ) ) ) );
	}

	public function testTheOwnerAloneIsNoViolation(): void {
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/outside-owner.php' ) ) ), 'Only the class that reaches in from outside breaks the rule.' );
		self::assertSame( array(), AB_MCP_Test_Seam_Guard::violations( array( self::fixture( 'bad/closure-owner.php' ) ) ) );
	}
}
