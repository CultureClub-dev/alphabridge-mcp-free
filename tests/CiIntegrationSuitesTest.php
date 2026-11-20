<?php
/**
 * The integration suite runs in two CI jobs, one per testsuite of
 * tests/integration/phpunit.xml.dist (gratis/ci-grenzen). A class in no list
 * would run in no job, a class in both in two; so: every test class under
 * tests/integration is in exactly one of the lists code and content, no list
 * names a file that is gone or takes a whole directory, and the matrix of the
 * job integration in .github/workflows/ci.yml names exactly these suites,
 * each job running its own.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class CiIntegrationSuitesTest extends TestCase {

	private const SUITES = array( 'code', 'content' );

	private const RUN = 'vendor/bin/phpunit -c tests/integration/phpunit.xml.dist';

	private static function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * The children of each testsuite, by name: `file x` or `<tag>`.
	 *
	 * @return array<string,string[]>
	 */
	private static function suites(): array {
		$doc = new \DOMDocument();
		self::assertTrue( $doc->load( self::root() . '/tests/integration/phpunit.xml.dist' ) );
		$suites = array();
		foreach ( $doc->getElementsByTagName( 'testsuite' ) as $suite ) {
			$name = $suite->getAttribute( 'name' );
			self::assertArrayNotHasKey( $name, $suites, "The testsuite $name is there twice." );
			$suites[ $name ] = array();
			foreach ( $suite->childNodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$suites[ $name ][] = 'file' === $node->tagName ? 'file ' . trim( $node->textContent ) : '<' . $node->tagName . '>';
				}
			}
		}
		return $suites;
	}

	/**
	 * Every *Test.php under tests/integration, relative to it.
	 *
	 * @return string[]
	 */
	private static function classes(): array {
		$dir   = self::root() . '/tests/integration/';
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( str_ends_with( $file->getFilename(), 'Test.php' ) ) {
				$files[] = substr( $file->getPathname(), strlen( $dir ) );
			}
		}
		sort( $files );
		return $files;
	}

	public function testThereAreExactlyTheSuitesCodeAndContent(): void {
		self::assertSame( self::SUITES, array_keys( self::suites() ) );
	}

	public function testEachListNamesFilesOnlyNoDirectory(): void {
		foreach ( self::suites() as $name => $children ) {
			self::assertNotEmpty( $children, "The testsuite $name is empty." );
			foreach ( $children as $child ) {
				self::assertStringStartsWith( 'file ', $child, "The testsuite $name has $child; only <file> entries, one per class." );
			}
		}
	}

	public function testEveryClassOfTheIntegrationSuiteIsInExactlyOneList(): void {
		$listed = array_merge( ...array_values( self::suites() ) );
		self::assertContains( 'SyncCodeRunTest.php', self::classes(), 'The search finds the classes of tests/integration.' );
		foreach ( self::classes() as $class ) {
			$times = count( array_keys( $listed, 'file ' . $class, true ) );
			self::assertSame( 1, $times, "$class is in $times of the lists code and content of tests/integration/phpunit.xml.dist; it belongs in exactly one." );
		}
	}

	public function testNoListNamesAFileThatIsGone(): void {
		foreach ( self::suites() as $name => $children ) {
			foreach ( $children as $child ) {
				self::assertContains( substr( $child, 5 ), self::classes(), "The testsuite $name names $child, no test class under tests/integration." );
			}
		}
	}

	public function testTheJobIntegrationRunsEachSuiteInAJobOfItsOwn(): void {
		$yml = (string) file_get_contents( self::root() . '/.github/workflows/ci.yml' );
		self::assertSame( 1, preg_match( '#^  integration:\n(.*?)(?=^  [a-z-]+:$)#ms', $yml, $job ), 'No job integration in ci.yml.' );
		self::assertSame( 1, preg_match_all( '#^      matrix:\n        suite: \[([^\]]*)\]$#m', $job[1], $matrix ), 'The job integration has no matrix suite.' );
		self::assertSame( self::SUITES, array_map( 'trim', explode( ',', $matrix[1][0] ) ) );
		self::assertSame( 2, substr_count( $job[1], self::RUN ), 'The suite and the check that it ran every test.' );
		self::assertSame( 2, substr_count( $job[1], self::RUN . ' --testsuite ${{ matrix.suite }}' ), 'Each job runs and checks its own suite, not every class.' );
	}
}
