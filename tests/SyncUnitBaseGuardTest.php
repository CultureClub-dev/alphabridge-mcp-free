<?php
/**
 * The guard of the one place of Z44 (Z8b branch 2b, plan 3.1): the first
 * base of the pair of a unit, and its confirmed new alignment, come from
 * AB_MCP_Sync_Pairs::set_unit_base() only, from no way past it in the
 * shipped code. What the code does at run time (create(), realign() and
 * change_canon() refuse a unit, rebase() makes no first base, restore()
 * puts back only a pair's own snapshot) the integration suite holds
 * (SyncUnitPairsTest::testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit);
 * this one holds the places, read from the source:
 *
 * - set_unit_base() is called by the confirmation of wp_site_pairs only
 *   (AB_MCP_Sync_Pairs_Tool::confirm()); the engine brings its call for a
 *   unit a run made (origin push) with branch 5, which names it here;
 * - what confirms a base is made in those places only: the confirmation of
 *   a person from the operations of the plan the token was checked against,
 *   the proof of a step nowhere yet; the constructor is private;
 * - the public functions of AB_MCP_Sync_Pairs are the ones this test knows:
 *   a new one that writes is reviewed here first;
 * - create(), realign() and change_canon() ask whether the pair is of a
 *   unit before anything they write, and a row is inserted in one place,
 *   reached by create() for an object and by set_unit_base();
 * - rebase() (Z38) moves on an active pair only, and is not the way of a
 *   confirmation;
 * - the confirmation writes the plan state() made (V1), and the proposal
 *   leads the pairs of units past the match of the objects and the canon
 *   change (V4).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class SyncUnitBaseGuardTest extends TestCase {

	/** @return array<string,string> Shipped PHP file (relative) => its code without comments (and without texts in quotes, $bare). */
	private static function shipped( bool $bare = false ): array {
		$root = dirname( __DIR__ );
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$all  = iterator_to_array( $it );
		$all[] = new \SplFileInfo( $root . '/alphabridge-mcp.php' );
		$all[] = new \SplFileInfo( $root . '/uninstall.php' );
		foreach ( $all as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( (string) $file ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( $bare && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$code .= "''";
					continue;
				}
				$code .= is_array( $token ) ? $token[1] : $token;
			}
			$out[ substr( (string) $file, strlen( $root ) + 1 ) ] = $code;
		}
		self::assertGreaterThan( 100, count( $out ) );
		return $out;
	}

	/** @return array<string,int> The shipped files whose code (outside texts in quotes) holds a text, with how often. */
	private static function holding( string $text ): array {
		$found = array();
		foreach ( self::shipped( true ) as $file => $code ) {
			$n = substr_count( $code, $text );
			if ( $n > 0 ) {
				$found[ $file ] = $n;
			}
		}
		ksort( $found );
		return $found;
	}

	/** The body of a function of a file (code without comments), up to the next function. */
	private static function body( string $file, string $function ): string {
		$code = self::shipped()[ $file ];
		self::assertSame( 1, preg_match( '/function\s+' . preg_quote( $function, '/' ) . '\s*\((.*?)(?=\n\t(?:public|private|protected)\s+(?:static\s+)?function\s|\n}\s*$)/s', $code, $m ), "$file: $function()" );
		return $m[1];
	}

	public function testSetUnitBaseIsCalledByTheConfirmationOnly(): void {
		self::assertSame( array( 'includes/sync/class-sync-pairs-tool.php' => 1 ), self::holding( 'AB_MCP_Sync_Pairs::set_unit_base(' ), 'The confirmation of a person; the engine (push) comes with branch 5 and is named here then.' );
		self::assertStringContainsString( 'AB_MCP_Sync_Pairs::set_unit_base(', self::body( 'includes/sync/class-sync-pairs-tool.php', 'confirm' ) );
		self::assertSame( array( 'includes/sync/class-sync-pairs.php' => 1 ), self::holding( 'function set_unit_base(' ) );
		self::assertSame( array(), self::holding( 'self::set_unit_base(' ), 'No call from within the pairs.' );
	}

	public function testWhatConfirmsABaseIsMadeInItsPlacesOnly(): void {
		self::assertSame( array( 'includes/sync/class-sync-pairs-tool.php' => 1 ), self::holding( 'AB_MCP_Sync_Unit_Proof::confirmed(' ) );
		self::assertStringContainsString( 'AB_MCP_Sync_Unit_Proof::confirmed( $plan[\'ops\'] )', self::body( 'includes/sync/class-sync-pairs-tool.php', 'confirm' ), 'From the operations of the plan the confirmation writes.' );
		self::assertSame( array(), self::holding( 'AB_MCP_Sync_Unit_Proof::pushed(' ), 'No step proves a base yet (branch 5).' );
		self::assertSame( array(), self::holding( 'new AB_MCP_Sync_Unit_Proof' ) );
		$pairs = self::shipped()['includes/sync/class-sync-pairs.php'];
		self::assertSame( 2, substr_count( $pairs, 'new self(' ), 'Made by its two makers only.' );
		self::assertSame( 1, preg_match( '/private function __construct\(\s*\$origin,/', $pairs ) );
		$class = new \ReflectionClass( \AB_MCP_Sync_Unit_Proof::class );
		self::assertTrue( $class->getConstructor()->isPrivate() );
		self::assertTrue( $class->isFinal() );
	}

	public function testThePublicFunctionsOfThePairsAreTheOnesKnown(): void {
		$public = array();
		foreach ( ( new \ReflectionClass( \AB_MCP_Sync_Pairs::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$public[] = $method->getName();
		}
		sort( $public );
		// Writers: create (an object), set_unit_base (Z44), rebase (Z38),
		// realign (an object), set_state, change_canon (an object), restore
		// (a pair to its own snapshot), discard, discard_row, transaction.
		self::assertSame(
			array( 'canon_outcome', 'change_canon', 'create', 'decode', 'discard', 'discard_row', 'encode_json', 'find_live', 'find_staging', 'forget', 'get', 'is_unit', 'page', 'page_source', 'page_source_shown', 'part_bases', 'realign', 'rebase', 'responsible', 'restore', 'set_state', 'set_unit_base', 'shown_row', 'snapshot', 'snapshot_hash', 'transaction', 'transition_allowed', 'valid_ref' ),
			$public,
			'A new public function of the pairs is reviewed against Z44 here first.'
		);
	}

	public function testTheWaysOfObjectsAskForAUnitBeforeTheyWrite(): void {
		$file = 'includes/sync/class-sync-pairs.php';
		foreach ( array( 'create' => 'self::insert_pair(', 'realign' => 'self::swap(', 'change_canon' => 'self::swap(' ) as $function => $write ) {
			$body  = self::body( $file, $function );
			$check = strpos( $body, 'self::is_unit(' );
			self::assertNotFalse( $check, "$function() asks whether the pair is of a unit." );
			self::assertLessThan( strpos( $body, $write ), $check, "$function(): before it writes." );
			self::assertStringContainsString( "self::unit_only( '$function' )", $body );
		}
		$pairs = self::shipped()[ $file ];
		self::assertSame( 2, substr_count( $pairs, 'self::insert_pair(' ), 'create() for an object and the base of a unit only.' );
		self::assertStringContainsString( 'self::insert_pair(', self::body( $file, 'write_unit_base' ) );
		self::assertSame( 1, substr_count( $pairs, 'self::calls()->insert_row(' ), 'One insert of a row.' );
		self::assertStringContainsString( "if ( 'active' !== \$raw['state'] ) {", self::body( $file, 'rebase' ), 'rebase() moves on an active pair only: no first base.' );
		self::assertStringContainsString( 'return self::transaction(', self::body( $file, 'set_unit_base' ), 'V5: the base and its reading back in one transaction.' );
	}

	public function testTheConfirmationWritesThePlanItsTokenWasCheckedAgainst(): void {
		$file = 'includes/sync/class-sync-pairs-tool.php';
		$call = self::body( $file, 'call' );
		self::assertStringNotContainsString( 'self::plan(', $call, 'V1: no plan made anew in call().' );
		self::assertStringContainsString( 'self::checked( $a )', $call );
		self::assertStringContainsString( 'self::$checked = array(', self::body( $file, 'state' ) );
	}

	public function testThePairsOfUnitsGoPastTheMatchOfObjectsAndTheCanonChange(): void {
		$finish = self::body( 'includes/sync/class-sync-proposal.php', 'finish' );
		self::assertStringContainsString( '$pairs = self::objects( $all );', $finish, 'V4.' );
		self::assertSame( 1, preg_match( '/self::canon\(\s*\$pairs,/', $finish ) );
		self::assertSame( 1, preg_match( '/self::match\(\s*\$staging\[\'lines\'\],\s*\$live\[\'lines\'\],\s*\$pairs,/', $finish ) );
		self::assertStringContainsString( 'AB_MCP_Sync_Unit_Proposal::entries( $env_side[\'lines\'], $env, $all )', $finish );
	}
}
