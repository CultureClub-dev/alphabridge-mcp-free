<?php
/**
 * The spellings of a class before :: that the walk of StoreRemoverGuardTest
 * reads as PHP does (testTheWalkReadsEverySpellingOfANameAsPhpResolvesIt):
 * a class in any case, self and static as this class, parent as its
 * extends; and a function that returns by reference as that function.
 * Never loaded.
 *
 * @package AlphaBridge_MCP
 */

class AB_MCP_Walker_Classes extends AB_MCP_Payload_File {

	public static function &all(): array {
		ab_mcp_payload_file::write( 'a' );
		SELF::write( 'b' );
		static::write( 'c' );
		Parent::write( 'd' );
		unlink( 'e' );
		$f = static function &() {
			return unlink( 'f' );
		};
		return $f();
	}
}
