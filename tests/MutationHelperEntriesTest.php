<?php
/**
 * Mutation entries for helpers of the integration suite (Z8b branch 1,
 * F-A): tests/mutation/run.php mutates a file under tests/ only for an entry
 * with 'helper' => true, and only under tests/integration/support/ or
 * tests/integration/bin/, named as it is. One test per path rule, on a tree
 * of its own in the temporary directory; then a whole run of such an entry:
 * baseline green, mutation red, the helper back byte for byte.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_Mutation_Run;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/tests/mutation/run.php';

final class MutationHelperEntriesTest extends TestCase {

	private string $root;

	private string $outside;

	protected function setUp(): void {
		$base          = (string) realpath( sys_get_temp_dir() );
		$this->root    = $base . '/ab-mutation-root-' . bin2hex( random_bytes( 6 ) );
		$this->outside = $base . '/ab-mutation-outside-' . bin2hex( random_bytes( 6 ) );
		foreach ( array( 'includes', 'tests/integration/support/tief', 'tests/integration/bin', 'tests/other', 'lists' ) as $dir ) {
			mkdir( $this->root . '/' . $dir, 0700, true );
		}
		mkdir( $this->outside, 0700 );
		$files = array(
			'includes/shipped.php'                     => "<?php\nreturn 'shipped';\n",
			'tests/integration/support/hilfe.php'      => "<?php\nreturn 'HILFE';\n",
			'tests/integration/support/tief/hilfe.php' => "<?php\nreturn 'tief';\n",
			'tests/integration/bin/skript.php'         => "<?php\nreturn 'skript';\n",
			'tests/integration/SomeTest.php'           => "<?php\n",
			'tests/other/x.php'                        => "<?php\n",
		);
		foreach ( $files as $file => $bytes ) {
			file_put_contents( $this->root . '/' . $file, $bytes );
		}
		file_put_contents( $this->outside . '/x.php', "<?php\n" );
		symlink( $this->outside . '/x.php', $this->root . '/tests/integration/support/link-aussen.php' );
		symlink( $this->root . '/tests/other/x.php', $this->root . '/tests/integration/support/link-tests.php' );
		symlink( $this->root . '/tests/integration/support/hilfe.php', $this->root . '/tests/integration/support/link-hilfe.php' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->root, $this->outside ) as $dir ) {
			self::remove( $dir );
		}
	}

	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::remove( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}

	/**
	 * The entries of a list of one entry, through the runner's check.
	 *
	 * @param array<string,mixed> $fields Fields over the defaults.
	 * @return array<int,array>
	 */
	private function entries( array $fields ): array {
		$entry = array_merge(
			array(
				'id'      => 'probe/one',
				'clause'  => 'a clause',
				'file'    => 'tests/integration/support/hilfe.php',
				'search'  => "'HILFE'",
				'replace' => "'MUTIERT'",
				'run'     => array( 'true' ),
				'helper'  => true,
			),
			$fields
		);
		// A field given as null is left out (an entry without the flag).
		$entry = array_filter( $entry, static fn( $value ) => null !== $value );
		$list  = $this->root . '/lists/list.php';
		file_put_contents( $list, '<?php return ' . var_export( array( $entry ), true ) . ';' );
		return ( new AB_Mutation_Run( $this->root ) )->entries( array( $list ) );
	}

	private function refused( array $fields, string $says ): void {
		try {
			$this->entries( $fields );
			self::fail( 'The runner accepted ' . json_encode( $fields ) );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $says, $e->getMessage() );
		}
	}

	/* ---------------------------------------------------------- allowed */

	public function testAHelperUnderSupportIsAccepted(): void {
		$entries = $this->entries( array() );
		self::assertSame( $this->root . '/tests/integration/support/hilfe.php', $entries[0]['path'] );
	}

	public function testAHelperUnderBinAndOneInASubfolderOfSupportAreAccepted(): void {
		self::assertSame( $this->root . '/tests/integration/bin/skript.php', $this->entries( array( 'file' => 'tests/integration/bin/skript.php' ) )[0]['path'] );
		self::assertSame( $this->root . '/tests/integration/support/tief/hilfe.php', $this->entries( array( 'file' => 'tests/integration/support/tief/hilfe.php' ) )[0]['path'] );
	}

	/* --------------------------------------------------------- refused */

	public function testAnotherFileUnderTestsIsRefusedAsHelper(): void {
		$this->refused( array( 'file' => 'tests/other/x.php' ), 'outside tests/integration/support/ and tests/integration/bin/' );
		$this->refused( array( 'file' => 'tests/integration/SomeTest.php' ), 'outside tests/integration/support/ and tests/integration/bin/' );
	}

	public function testAPathWithASegmentDotDotIsRefusedEvenWhereItLeadsToAHelper(): void {
		$this->refused( array( 'file' => 'tests/integration/support/../bin/skript.php' ), 'segment . or ..' );
		$this->refused( array( 'file' => 'tests/integration/support/./hilfe.php' ), 'segment . or ..' );
		$this->refused( array( 'file' => 'tests/integration/support/../../other/x.php' ), 'segment . or ..' );
	}

	public function testALinkOutOfTheTreeIsRefused(): void {
		$this->refused( array( 'file' => 'tests/integration/support/link-aussen.php' ), 'missing or a link' );
	}

	public function testALinkToAnotherFileUnderTestsIsRefused(): void {
		$this->refused( array( 'file' => 'tests/integration/support/link-tests.php' ), 'missing or a link' );
	}

	public function testALinkEvenToAHelperIsRefused(): void {
		$this->refused( array( 'file' => 'tests/integration/support/link-hilfe.php' ), 'missing or a link' );
	}

	public function testAMissingHelperIsRefused(): void {
		$this->refused( array( 'file' => 'tests/integration/support/fehlt.php' ), 'missing or a link' );
	}

	public function testTheHelperFlagOnAShippedFileIsRefused(): void {
		$this->refused( array( 'file' => 'includes/shipped.php' ), 'outside tests/integration/support/ and tests/integration/bin/' );
	}

	public function testAHelperFlagOtherThanTrueIsRefused(): void {
		$this->refused( array( 'helper' => 1 ), "'helper' other than true" );
		$this->refused( array( 'helper' => false ), "'helper' other than true" );
	}

	public function testWithoutTheFlagEveryFileUnderTestsStaysRefusedAndAShippedFileAccepted(): void {
		$this->refused( array( 'helper' => null ), 'names no shipped file' );
		$entries = $this->entries( array( 'helper' => null, 'file' => 'includes/shipped.php', 'search' => "'shipped'", 'replace' => "'x'" ) );
		self::assertSame( $this->root . '/includes/shipped.php', $entries[0]['path'] );
	}

	/* ------------------------------------------------ a run of an entry */

	public function testAHelperEntryRunsGreenThenRedAndTheHelperComesBackByteForByte(): void {
		$file   = $this->root . '/tests/integration/support/hilfe.php';
		$before = (string) file_get_contents( $file );
		$probe  = array( PHP_BINARY, '-r', 'exit( false !== strpos( (string) file_get_contents( "tests/integration/support/hilfe.php" ), "MUTIERT" ) ? 1 : 0 );' );
		$run    = new AB_Mutation_Run( $this->root );
		$found  = $this->entries( array( 'run' => $probe ) );
		ob_start();
		$code   = $run->run( $found );
		$output = (string) ob_get_clean();
		self::assertSame( 0, $code, $output );
		self::assertStringContainsString( 'killed', $output );
		self::assertStringContainsString( 'all 1 mutations killed', $output );
		self::assertSame( $before, file_get_contents( $file ), 'The helper is back byte for byte.' );
		self::assertFileDoesNotExist( $file . '.rein' );
	}
}
