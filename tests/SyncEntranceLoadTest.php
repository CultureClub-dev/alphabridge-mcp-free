<?php
/**
 * The places of the entrance of the code as the files load (B5 of the
 * fourth check after T4; the seam, step T5): the engine, Push, the way
 * back, the operations of the code and its planning, each loaded first in
 * a fresh process after the bootstrap of the unit tests, leave both places
 * filled as this plugin fills them: the place of the engine with
 * AB_MCP_Sync_Code_Ops (the line at the end of the file of the operations)
 * and the place of the planning in Push with AB_MCP_Sync_Code_Plan (the
 * line at the end of the file of the planning). Whichever file a request
 * loads first, a run of the code finds its entrance; the core without them
 * is tested by tests/integration/SyncCodeWithoutEntranceTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncEntranceLoadTest extends TestCase {

	/** The classes of the five files, none loaded before the one loaded first. */
	private const CLASSES = array( 'AB_MCP_Sync_Engine', 'AB_MCP_Sync_Push', 'AB_MCP_Sync_Revert', 'AB_MCP_Sync_Code_Ops', 'AB_MCP_Sync_Code_Plan' );

	/** The files, each loaded first in a process of its own. */
	public static function firsts(): array {
		return array(
			'the engine'                 => array( 'includes/sync/class-sync-engine.php' ),
			'push'                       => array( 'includes/sync/class-sync-push.php' ),
			'the way back'               => array( 'includes/sync/class-sync-revert.php' ),
			'the operations of the code' => array( 'includes/sync/class-sync-code-ops.php' ),
			'the planning of the code'   => array( 'includes/sync/class-sync-code-plan.php' ),
		);
	}

	/**
	 * Prüft: (B5) a file loaded first, after the bootstrap and before any of
	 * the five classes, leaves the place of the engine with
	 * AB_MCP_Sync_Code_Ops and the place of the planning in Push with
	 * AB_MCP_Sync_Code_Plan.
	 * Sonst: a request that loads this file first would find a place empty
	 * and treat a run of the code as one without its entrance.
	 */
	#[DataProvider( 'firsts' )]
	public function testEachFileLoadedFirstFillsBothPlacesOfTheEntranceOfTheCode( string $file ): void {
		$root  = dirname( __DIR__ );
		$code  = 'require ' . var_export( $root . '/tests/bootstrap.php', true ) . ';'
			. ' $before = array_values( array_filter( ' . var_export( self::CLASSES, true ) . ', static fn( $c ) => class_exists( $c, false ) ) );'
			. ' require ' . var_export( $root . '/' . $file, true ) . ';'
			. ' echo json_encode( array( $before, ( new ReflectionProperty( "AB_MCP_Sync_Engine", "code" ) )->getValue(), ( new ReflectionProperty( "AB_MCP_Sync_Push", "code" ) )->getValue() ) );';
		$lines = array();
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $lines, $status );
		self::assertSame( 0, $status, implode( "\n", $lines ) );
		self::assertSame( array( array(), 'AB_MCP_Sync_Code_Ops', 'AB_MCP_Sync_Code_Plan' ), json_decode( implode( "\n", $lines ), true ), "$file loaded first: none of the five classes before it, then both places filled as this plugin fills them." );
	}
}
