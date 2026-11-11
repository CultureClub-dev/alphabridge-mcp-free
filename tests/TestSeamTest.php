<?php
/**
 * The common helper of the test seams (Z8b branch 1, point 5): each kind of
 * fault on the example seam (tests/fixtures/seams/class-test-seam-example.php,
 * a swap of two folders with a journal), in this process; the abort, which
 * needs a process of its own, is TestAbortTest.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Test_Example_Calls;
use AB_MCP_Test_Example_Swap;
use AB_MCP_Test_Seam;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/integration/support/class-test-abort.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';
require_once __DIR__ . '/fixtures/seams/class-test-seam-example.php';
require_once __DIR__ . '/fixtures/seams/runtime-bad.php';
require_once __DIR__ . '/fixtures/seams/class-test-seam-writer.php';

final class TestSeamTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = (string) realpath( sys_get_temp_dir() ) . '/ab-seam-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/target', 0700, true );
		mkdir( $this->dir . '/staged', 0700 );
		file_put_contents( $this->dir . '/target/plugin.php', 'alt' );
		file_put_contents( $this->dir . '/staged/plugin.php', 'neu' );
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		self::remove( $this->dir );
	}

	private static function remove( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::remove( $path . '/' . $name );
				}
			}
			rmdir( $path );
		}
	}

	private function swap(): string {
		return AB_MCP_Test_Example_Swap::swap( $this->dir . '/target', $this->dir . '/staged', $this->dir . '/backup', $this->dir . '/journal' );
	}

	private function seam( array $faults ): \AB_MCP_Test_Seam_Proxy {
		return AB_MCP_Test_Seam::install( AB_MCP_Test_Example_Swap::class, 'naht_test_example_swap', AB_MCP_Test_Example_Calls::class, $faults );
	}

	private function value(): mixed {
		return ( new ReflectionProperty( AB_MCP_Test_Example_Swap::class, 'naht_test_example_swap' ) )->getValue();
	}

	/* ---------------------------------------------- without a fault */

	public function testWithoutAFaultTheRealCallsRunAndAreRecorded(): void {
		$proxy = $this->seam( array() );
		self::assertSame( 'swapped', $this->swap() );
		self::assertSame( 'neu', file_get_contents( $this->dir . '/target/plugin.php' ) );
		self::assertSame( 'alt', file_get_contents( $this->dir . '/backup/plugin.php' ) );
		self::assertSame( 'done', file_get_contents( $this->dir . '/journal' ) );
		self::assertSame(
			array( 'put#1', 'rename#1', 'rename#2', 'put#2' ),
			array_map( static fn( $c ) => $c['method'] . '#' . $c['n'], $proxy->calls )
		);
		self::assertSame( array( null, null, null, null ), array_column( $proxy->calls, 'fault' ) );
	}

	/* ------------------------------------------------ kinds of fault */

	public function testReturnFalseAtTheSecondRenameAndOnlyThere(): void {
		$proxy = $this->seam( array( array( 'method' => 'rename', 'nth' => 2, 'kind' => 'return' ) ) );
		self::assertSame( 'second_rename_failed', $this->swap() );
		self::assertSame( 'alt', file_get_contents( $this->dir . '/target/plugin.php' ), 'The third rename (the way back) was real.' );
		self::assertSame( array( null, null, 'return', null ), array_column( $proxy->calls, 'fault' ) );
	}

	public function testAThrowableLeavesTheCall(): void {
		$this->seam( array( array( 'method' => 'rename', 'nth' => 1, 'kind' => 'throw', 'class' => Error::class, 'message' => 'disk gone' ) ) );
		try {
			$this->swap();
			self::fail( 'The Throwable did not leave the swap.' );
		} catch ( Error $e ) {
			self::assertSame( 'disk gone', $e->getMessage() );
		}
		self::assertDirectoryExists( $this->dir . '/target', 'Nothing was moved.' );
		self::assertDirectoryDoesNotExist( $this->dir . '/backup' );
	}

	public function testAWriterBeforeTheCallRunsFirstThenTheRealCall(): void {
		$proxy = $this->seam(
			array(
				array(
					'method' => 'rename',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => function () {
						mkdir( $this->dir . '/target' );
						file_put_contents( $this->dir . '/target/fremd.txt', 'another writer' );
					},
				),
			)
		);
		self::assertSame( 'second_rename_failed', $this->swap(), 'The real rename found the target taken.' );
		self::assertSame( 'another writer', file_get_contents( $this->dir . '/target/fremd.txt' ) );
		self::assertSame( 'before', $proxy->calls[2]['fault'] );
	}

	/**
	 * Every form of a writer before the call (GitHub Codex on free#145): an
	 * object method and a static method as array callables, a closure, a
	 * function name, and a list of writer ops.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function writers(): array {
		return array(
			'object method'   => array( 'object' ),
			'static method'   => array( 'static' ),
			'closure'         => array( 'closure' ),
			'list of ops'     => array( 'ops' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'writers' )]
	public function testEveryFormOfAWriterRunsBeforeTheCall( string $form ): void {
		$file = $this->dir . '/vorher';
		switch ( $form ) {
			case 'object':
				$before = array( new \AB_MCP_Test_Seam_Writer( $file ), 'before' );
				$wrote  = 'object before put';
				break;
			case 'static':
				\AB_MCP_Test_Seam_Writer::$file = $file;
				$before                         = array( \AB_MCP_Test_Seam_Writer::class, 'before_static' );
				$wrote                          = 'static before put';
				break;
			case 'closure':
				$before = static function ( $method ) use ( $file ) {
					file_put_contents( $file, 'closure before ' . $method );
				};
				$wrote  = 'closure before put';
				break;
			default:
				$before = array(
					array( 'op' => 'put_file', 'path' => $file, 'bytes' => base64_encode( 'ops before' ) ),
					array( 'op' => 'mkdir', 'path' => $this->dir . '/ordner' ),
				);
				$wrote  = 'ops before';
		}
		$proxy = $this->seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'before', 'before' => $before ) ) );
		self::assertSame( 'swapped', $this->swap() );
		self::assertSame( $wrote, file_get_contents( $file ) );
		self::assertSame( 'before', $proxy->calls[0]['fault'] );
		if ( 'ops' === $form ) {
			self::assertDirectoryExists( $this->dir . '/ordner' );
			rmdir( $this->dir . '/ordner' );
		}
	}

	public function testAWriterAsAnOpOfAPlan(): void {
		$this->seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'before', 'before' => array( 'op' => 'put_file', 'path' => $this->dir . '/vorher', 'bytes' => base64_encode( 'x' ) ) ) ) );
		self::assertSame( 'swapped', $this->swap() );
		self::assertSame( 'x', file_get_contents( $this->dir . '/vorher' ) );
	}

	public function testHalfWrittenCutsTheArgumentAndFails(): void {
		$this->seam( array( array( 'method' => 'put', 'nth' => 1, 'kind' => 'half', 'arg' => 1, 'keep' => 3 ) ) );
		self::assertSame( 'journal_failed', $this->swap() );
		self::assertSame( 'beg', file_get_contents( $this->dir . '/journal' ), 'Half of the journal is on the disk.' );
	}

	public function testHalfReadCutsTheResult(): void {
		file_put_contents( $this->dir . '/journal', 'begun' );
		$this->seam( array( array( 'method' => 'get', 'kind' => 'half', 'keep' => 2 ) ) );
		self::assertSame( 'be', AB_MCP_Test_Example_Swap::journal( $this->dir . '/journal' ) );
	}

	public function testAReadErrorGivesFalseOrOtherBytes(): void {
		file_put_contents( $this->dir . '/journal', 'begun' );
		$this->seam( array( array( 'method' => 'get', 'nth' => 1, 'kind' => 'return' ), array( 'method' => 'get', 'nth' => 2, 'kind' => 'return', 'value' => "\x00\xff" ) ) );
		self::assertSame( 'unreadable', AB_MCP_Test_Example_Swap::journal( $this->dir . '/journal' ) );
		self::assertSame( "\x00\xff", AB_MCP_Test_Example_Swap::journal( $this->dir . '/journal' ) );
		self::assertSame( 'begun', AB_MCP_Test_Example_Swap::journal( $this->dir . '/journal' ), 'The third read is real.' );
	}

	public function testAnAbortIsRefusedInTheTestProcess(): void {
		$this->seam( array( array( 'method' => 'rename', 'nth' => 1, 'kind' => 'abort', 'point' => 'zwischen', 'mode' => 'shutdown' ) ) );
		try {
			$this->swap();
			self::fail( 'An abort ran in the test process.' );
		} catch ( LogicException $e ) {
			self::assertStringContainsString( 'never in the test process', $e->getMessage() );
		}
	}

	/* ------------------------------------------- install and restore */

	public function testRestorePutsBackWhatTheSeamHeld(): void {
		self::assertNull( $this->value() );
		$this->seam( array() );
		self::assertInstanceOf( \AB_MCP_Test_Seam_Proxy::class, $this->value() );
		AB_MCP_Test_Seam::restore_all();
		self::assertNull( $this->value() );
		self::assertSame( array(), AB_MCP_Test_Seam::recorded() );
	}

	public function testWithoutARealObjectOrClassTheSeamIsRefused(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'needs the real calls object' );
		AB_MCP_Test_Seam::install( AB_MCP_Test_Example_Swap::class, 'naht_test_example_swap' );
	}

	public function testOnlyAPrivateStaticUntypedPropertyWithThePrefixIsASeam(): void {
		foreach (
			array(
				\AB_MCP_Test_Bad_Public::class   => array( 'naht_bad_public', 'must be private static' ),
				\AB_MCP_Test_Bad_Instance::class => array( 'naht_bad_instance', 'must be private static' ),
				\AB_MCP_Test_Bad_Typed::class    => array( 'naht_bad_typed', 'has a type' ),
				\AB_MCP_Test_Bad_Unnamed::class  => array( 'calls', 'must begin with naht_' ),
			) as $class => list( $property, $says )
		) {
			try {
				AB_MCP_Test_Seam::property( $class, $property );
				self::fail( "$class passed as a seam." );
			} catch ( LogicException $e ) {
				self::assertStringContainsString( $says, $e->getMessage() );
			}
		}
		$this->expectException( LogicException::class );
		AB_MCP_Test_Seam::property( AB_MCP_Test_Example_Swap::class, 'nicht_da' );
	}

	public function testAFaultPlanIsChecked(): void {
		foreach (
			array(
				'names no method'      => array( 'kind' => 'return' ),
				'has no kind'          => array( 'method' => 'put', 'kind' => 'explode' ),
				'nth counts from 1'    => array( 'method' => 'put', 'kind' => 'return', 'nth' => 0 ),
				'without naming'       => array( 'method' => 'put', 'kind' => 'abort', 'mode' => 'shutdown' ),
				'No abort mode'        => array( 'method' => 'put', 'kind' => 'abort', 'point' => 'p', 'mode' => 'kill' ),
				'before or after'      => array( 'method' => 'put', 'kind' => 'abort', 'point' => 'p', 'mode' => 'shutdown', 'when' => 'during' ),
				'no writer'            => array( 'method' => 'put', 'kind' => 'before' ),
				'half needs keep'      => array( 'method' => 'put', 'kind' => 'half' ),
			) as $says => $fault
		) {
			try {
				AB_MCP_Test_Seam::check_faults( array( $fault ) );
				self::fail( 'Accepted: ' . json_encode( $fault ) );
			} catch ( InvalidArgumentException $e ) {
				self::assertStringContainsString( $says, $e->getMessage() );
			}
		}
	}

	public function testAThrowClassMustBeAThrowable(): void {
		$this->seam( array( array( 'method' => 'put', 'kind' => 'throw', 'class' => \stdClass::class ) ) );
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'no Throwable' );
		$this->swap();
	}

	public function testAWriterOpThatIsUnknownIsRefused(): void {
		$this->expectException( InvalidArgumentException::class );
		AB_MCP_Test_Seam::run_op( array( 'op' => 'format_disk' ) );
	}

	public function testAMethodTheRealCallsLackIsRefused(): void {
		$proxy = $this->seam( array() );
		$this->expectException( \BadMethodCallException::class );
		$proxy->delete_everything( '/' );
	}

	public function testTheDefaultThrowableIsARuntimeException(): void {
		$this->seam( array( array( 'method' => 'put', 'kind' => 'throw' ) ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Fault injected into put (call 1)' );
		$this->swap();
	}
}
