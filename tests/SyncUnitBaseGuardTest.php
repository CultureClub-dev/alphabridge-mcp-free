<?php
/**
 * The guard of the one place of Z44 (Z8b branch 2b, plan 3.1): the bases of
 * the parts of the pair of a unit, first ones and a confirmed new alignment,
 * come from AB_MCP_Sync_Pairs::set_unit_base() only, from no way past it in
 * the shipped code. What the code does at run time (create(), realign() and
 * change_canon() refuse a unit, rebase() gives no first base to a part,
 * restore() puts back only the state its journal names, a proof made
 * otherwise is refused) the integration suite holds
 * (SyncUnitPairsTest::testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit,
 * testWhatConfirmsABaseIsMadeOnlyFromWhatHappened); this one holds the
 * places, read from the source:
 *
 * - set_unit_base() is called by the confirmation of wp_site_pairs only
 *   (AB_MCP_Sync_Pairs_Tool::confirm()); the engine brings its call for a
 *   unit a run made (origin push) with branch 5, which names it here;
 * - what confirms a base (AB_MCP_Sync_Unit_Proof) cannot be made from what
 *   a caller says (Behebung 1, A2): its constructor and its copy are
 *   private, it is never unserialized, no shipped code makes an object past
 *   its constructor or names the class but its own file and the
 *   confirmation, and its two makers make it only from
 *   what happened: of_confirmation() where AB_MCP_Confirm_Token::passed()
 *   says the token passed its check against this state in this request
 *   (recorded in check() only, after the MAC, and check() and issue() run
 *   in gate() only), of_step() from the journal of a step done; the
 *   confirmation calls of_confirmation() with its arguments and the state
 *   of its plan, of_step() is called nowhere yet (branch 5);
 * - the public functions of AB_MCP_Sync_Pairs are the ones this test knows:
 *   a new one that writes is reviewed here first;
 * - create(), realign() and change_canon() ask whether the pair is of a
 *   unit before anything they write, and a row is inserted in one place,
 *   reached by create() for an object and by set_unit_base();
 * - rebase() (Z38) moves on an active pair only, and a part with a base
 *   only (A4); restore() of a unit asks its journal first (A3);
 * - the confirmation writes the plan state() made (V1), and the proposal
 *   leads the pairs of units past the match of the objects and the canon
 *   change (V4).
 *
 * It loads the classes it reads by reflection itself, so it runs as a file
 * of its own (Behebung 1, C7).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-confirm-token.php';
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-pairs.php';

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

	public function testWhatConfirmsABaseCannotBeMadeOtherwiseThanFromWhatHappened(): void {
		$class = new \ReflectionClass( \AB_MCP_Sync_Unit_Proof::class );
		self::assertTrue( $class->isFinal() );
		self::assertTrue( $class->getConstructor()->isPrivate(), 'No proof by new.' );
		self::assertTrue( $class->getMethod( '__clone' )->isPrivate(), 'No copy of a proof.' );
		$makers = array();
		foreach ( $class->getMethods( \ReflectionMethod::IS_STATIC ) as $method ) {
			$makers[] = ( $method->isPublic() ? 'public ' : 'not public ' ) . $method->getName();
		}
		sort( $makers );
		self::assertSame( array( 'public of_confirmation', 'public of_step' ), $makers, 'Its two makers, no other function of the class makes one.' );
		// A proof from a string: refused, also with the fields of one.
		$forged = 'O:22:"AB_MCP_Sync_Unit_Proof":1:{s:30:"' . "\0" . 'AB_MCP_Sync_Unit_Proof' . "\0" . 'fields";a:2:{s:6:"origin";s:8:"proposal";s:3:"ops";a:0:{}}}';
		try {
			unserialize( $forged ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			self::fail( 'A proof is never unserialized.' );
		} catch ( \LogicException $e ) {
			self::assertStringContainsString( 'never unserialized', $e->getMessage() );
		}

		$pairs = 'includes/sync/class-sync-pairs.php';
		self::assertSame( 2, substr_count( self::shipped()[ $pairs ], 'new self(' ), 'Made by its two makers only.' );
		$confirmation = self::body( $pairs, 'of_confirmation' );
		self::assertSame( 1, substr_count( $confirmation, 'new self(' ) );
		self::assertLessThan( strpos( $confirmation, 'new self(' ), strpos( $confirmation, "AB_MCP_Confirm_Token::passed( 'wp_site_pairs', \$args, hash( 'sha256', AB_MCP_Sync_Canon::encode( \$state ) ) )" ), 'Only where the token passed its check against this very state.' );
		$step = self::body( $pairs, 'of_step' );
		self::assertSame( 1, substr_count( $step, 'new self(' ) );
		self::assertLessThan( strpos( $step, 'new self(' ), strpos( $step, "'done' !== \$step['state']" ), 'Only from a step done in its journal.' );
		self::assertLessThan( strpos( $step, 'new self(' ), strpos( $step, 'AB_MCP_Sync_Run::step(' ) );

		// The token: a passed check recorded in check() only, after the MAC;
		// check() and issue() run in gate() only.
		$token = 'includes/class-confirm-token.php';
		self::assertSame( 1, substr_count( self::shipped()[ $token ], 'self::$passed[' ) - substr_count( self::body( $token, 'passed' ), 'self::$passed[' ), 'One write of a passed check.' );
		$check = self::body( $token, 'check' );
		self::assertLessThan( strpos( $check, 'self::$passed[' ), strpos( $check, 'hash_equals( $mac, $m[2] )' ), 'Recorded after the MAC matched.' );
		self::assertSame( 1, substr_count( self::shipped()[ $token ], 'self::check(' ) );
		self::assertSame( 1, substr_count( self::shipped()[ $token ], 'self::issue(' ) );
		self::assertStringContainsString( 'self::check(', self::body( $token, 'gate' ) );
		self::assertStringContainsString( 'self::issue(', self::body( $token, 'gate' ) );
		foreach ( array( 'AB_MCP_Confirm_Token::check(', 'AB_MCP_Confirm_Token::issue(' ) as $call ) {
			self::assertSame( array(), self::holding( $call ), $call . ' only in gate().' );
		}
		self::assertSame( array( $pairs => 1 ), self::holding( 'AB_MCP_Confirm_Token::passed(' ) );

		// The callers: the confirmation with its arguments and the state of
		// its plan; no step yet (branch 5).
		self::assertSame( array( 'includes/sync/class-sync-pairs-tool.php' => 1 ), self::holding( 'AB_MCP_Sync_Unit_Proof::of_confirmation(' ) );
		self::assertStringContainsString( "AB_MCP_Sync_Unit_Proof::of_confirmation( \$a, \$plan['state'] )", self::body( 'includes/sync/class-sync-pairs-tool.php', 'confirm' ) );
		self::assertSame( array(), self::holding( 'AB_MCP_Sync_Unit_Proof::of_step(' ), 'No step proves a base yet (branch 5).' );
		self::assertSame( array(), self::holding( 'new AB_MCP_Sync_Unit_Proof' ) );
		// No way around its constructor in the shipped code (code and texts
		// read, comments not): no object made past a constructor, and the
		// class named in its own file (its declaration, the check in
		// set_unit_base()) and in the confirmation only.
		$past  = array();
		$named = array();
		foreach ( self::shipped() as $file => $code ) {
			if ( false !== stripos( $code, 'newInstanceWithoutConstructor' ) ) {
				$past[] = $file;
			}
			$n = substr_count( $code, 'AB_MCP_Sync_Unit_Proof' );
			if ( $n > 0 ) {
				$named[ $file ] = $n;
			}
		}
		ksort( $named );
		self::assertSame( array(), $past );
		self::assertSame( array( 'includes/sync/class-sync-pairs-tool.php' => 1, $pairs => 2 ), $named );
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
		$rebase = self::body( $file, 'rebase' );
		self::assertStringContainsString( "if ( 'active' !== \$raw['state'] ) {", $rebase, 'rebase() moves on an active pair only: no first base.' );
		self::assertLessThan( strrpos( $rebase, 'self::swap(' ), strpos( $rebase, 'if ( null === self::part_bases( self::decode( $raw ), (string) $part ) ) {' ), 'A4: a part with a base only, before it writes the part.' );
		$restore = self::body( $file, 'restore' );
		self::assertLessThan( strrpos( $restore, 'self::swap(' ), strpos( $restore, "self::is_unit( \$raw['kind'] ) && ! self::step_before(" ), 'A3: a unit only to the state its journal names.' );
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
