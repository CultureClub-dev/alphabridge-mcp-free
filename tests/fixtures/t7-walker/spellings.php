<?php
/**
 * The spellings of a call that the walk of StoreRemoverGuardTest reads
 * (testTheWalkReadsEverySpellingOfANameAsPhpResolvesIt). Never loaded:
 * only its code is read.
 *
 * @package AlphaBridge_MCP
 */

final class AB_MCP_Walker_Spellings {

	public function all(): void {
		rename( 'a', 'b' );
		\rename( 'c', 'd' );
		namespace\rename( 'e', 'f' );
		Ns\rename( 'g', 'h' );
		\AB_MCP_Payload_File::write( 'i' );
		namespace\AB_MCP_Payload_File::write( 'j' );
		Ns\AB_MCP_Payload_File::write( 'k' );
		$this->rename( 'l' );
		$f = static function ( $x ) use ( $f ) {
			return rename( $x, 'm' );
		};
	}
}

$walk = static function () use ( $walk ) {
	return \rename( 'n', 'o' );
};
