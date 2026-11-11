<?php
/**
 * The shell run of a manual way back (Z8b branch 1, point 4; F-B): the rule
 * of tests/integration/support/HANDWEG.md, clause by clause, and a run of
 * the commands of one point in a folder, as the abort cases of the plan
 * (F25–F33) take them from the instruction of a swap.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_IT_Handweg;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/integration/support/class-it-handweg.php';

final class HandwegTest extends TestCase {

	private const ANLEITUNG = <<<'TXT'
Einheit: ab-it-aktiv (Plugin)
$ echo nie-ausgefuehrt-vor-dem-ersten-kopf

[vor-der-ersten-umbenennung]
Nichts ist verschoben. Prüfen:
$ test -f ziel/plugin.php

[zwischen-den-umbenennungen]
Das Ziel fehlt, die Sicherung ist vollständig. Zurücklegen:
$ test ! -e ziel
$ mv sicherung ziel
Prüfen:
$ test -f ziel/plugin.php
$ cat ziel/plugin.php
TXT;

	private string $dir;

	protected function setUp(): void {
		$this->dir = (string) realpath( sys_get_temp_dir() ) . '/ab-handweg-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/sicherung', 0700, true );
		file_put_contents( $this->dir . '/sicherung/plugin.php', 'alt' );
	}

	protected function tearDown(): void {
		foreach ( array( 'ziel/plugin.php', 'sicherung/plugin.php', 'anleitung.txt', 'spur' ) as $file ) {
			if ( is_file( $this->dir . '/' . $file ) ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		foreach ( array( 'ziel', 'sicherung', '' ) as $dir ) {
			if ( is_dir( $this->dir . '/' . $dir ) ) {
				rmdir( $this->dir . '/' . $dir );
			}
		}
	}

	/* ------------------------------------------------------- the rule */

	public function testTheCommandsOfOnePointAreItsDollarLinesInOrder(): void {
		self::assertSame( array( 'test ! -e ziel', 'mv sicherung ziel', 'test -f ziel/plugin.php', 'cat ziel/plugin.php' ), AB_MCP_IT_Handweg::commands( self::ANLEITUNG, 'zwischen-den-umbenennungen' ) );
		self::assertSame( array( 'test -f ziel/plugin.php' ), AB_MCP_IT_Handweg::commands( self::ANLEITUNG, 'vor-der-ersten-umbenennung' ), 'A section ends at the next head.' );
	}

	public function testALineBeforeTheFirstHeadIsNeverACommand(): void {
		foreach ( array( 'vor-der-ersten-umbenennung', 'zwischen-den-umbenennungen' ) as $point ) {
			self::assertNotContains( 'echo nie-ausgefuehrt-vor-dem-ersten-kopf', AB_MCP_IT_Handweg::commands( self::ANLEITUNG, $point ) );
		}
	}

	public function testAMissingPointIsAnError(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no section [nach-der-probe]; it has: vor-der-ersten-umbenennung, zwischen-den-umbenennungen' );
		AB_MCP_IT_Handweg::commands( self::ANLEITUNG, 'nach-der-probe' );
	}

	public function testAPointTwiceIsAnError(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'twice' );
		AB_MCP_IT_Handweg::commands( "[a]\n\$ true\n[a]\n\$ false\n", 'a' );
	}

	public function testASectionWithoutACommandIsAnError(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no command line' );
		AB_MCP_IT_Handweg::commands( "[a]\nNur Text.\n  \$ eingerückt ist kein Befehl\n\$kein Leerzeichen\n", 'a' );
	}

	public function testAHeadIsALineOfItsOwnInLowerCase(): void {
		self::assertSame( array( 'echo b' ), AB_MCP_IT_Handweg::commands( "[a]\n\$ echo a\nText [b] im Satz\n[B]\n\$ echo nicht-b\n[b]\n\$ echo b\n", 'b' ) );
		self::assertSame( array( 'echo a', 'echo nicht-b' ), AB_MCP_IT_Handweg::commands( "[a]\n\$ echo a\n[B]\n\$ echo nicht-b\n", 'a' ), '[B] opens no section.' );
	}

	/* -------------------------------------------------------- the run */

	public function testTheCommandsOfAPointRunInTheFolderAndPutTheTargetBack(): void {
		file_put_contents( $this->dir . '/anleitung.txt', self::ANLEITUNG );
		$back = AB_MCP_IT_Handweg::run_point( $this->dir . '/anleitung.txt', 'zwischen-den-umbenennungen', $this->dir );
		self::assertSame( 0, $back['status'], $back['stderr'] );
		self::assertSame( 'alt', $back['output'] );
		self::assertSame( 'alt', file_get_contents( $this->dir . '/ziel/plugin.php' ) );
		self::assertDirectoryDoesNotExist( $this->dir . '/sicherung' );
	}

	public function testTheFirstFailingCommandEndsTheRun(): void {
		$back = AB_MCP_IT_Handweg::run( array( 'echo eins > spur', 'false', 'echo zwei >> spur' ), $this->dir );
		self::assertSame( 1, $back['status'] );
		self::assertSame( "eins\n", file_get_contents( $this->dir . '/spur' ), 'set -e: nothing after the failing command.' );
	}

	public function testAnUnsetVariableIsAnError(): void {
		$back = AB_MCP_IT_Handweg::run( array( 'echo "$AB_IT_NICHT_GESETZT_' . bin2hex( random_bytes( 3 ) ) . '"' ), $this->dir );
		self::assertNotSame( 0, $back['status'] );
		self::assertStringContainsString( 'unbound variable', $back['stderr'] );
	}

	public function testAFailureInAPipeCounts(): void {
		$back = AB_MCP_IT_Handweg::run( array( 'false | cat' ), $this->dir );
		self::assertSame( 1, $back['status'], 'pipefail' );
	}

	public function testTheTimeLimitEndsTheShellAndWhatItStarted(): void {
		$back = AB_MCP_IT_Handweg::run( array( 'sleep 30 &', 'echo $! > spur', 'wait' ), $this->dir, 1 );
		self::assertTrue( $back['timed_out'] );
		self::assertNull( $back['status'] );
		self::assertSame( 9, $back['signal'] );
		$sleep = (int) file_get_contents( $this->dir . '/spur' );
		self::assertGreaterThan( 0, $sleep );
		usleep( 200000 );
		self::assertFalse( posix_kill( $sleep, 0 ), 'The command the shell started is gone too.' );
	}

	public function testAMissingFolderOrInstructionIsAnError(): void {
		try {
			AB_MCP_IT_Handweg::run( array( 'true' ), $this->dir . '/fehlt' );
			self::fail( 'Ran in a missing folder.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'No folder', $e->getMessage() );
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No instruction' );
		AB_MCP_IT_Handweg::run_point( $this->dir . '/fehlt.txt', 'a', $this->dir );
	}
}
