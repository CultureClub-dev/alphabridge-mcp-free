<?php
/**
 * The proposal of object pairs as far as a test without a database shows it
 * (AB_MCP_Sync_Proposal, Z8 decisions 10, 13 and 14; branch 6, part 1):
 *
 * - match(): one to one by kind and identity, equal only with both hashes,
 *   both read whole and the same; differs; more candidates on a side
 *   ambiguous, split by the path of parents, then by an equal hash unique on
 *   both sides, what is left one to one paired; staging_only, live_only only
 *   where the staging side is complete, else not_compared; a credential in a
 *   name holds the whole group (no name in its entry); a side without a hash,
 *   a row not read and a ref no pair holds are not compared, with the words
 *   of the reason code; objects of a pair are not proposed again, unclear and
 *   residual pairs are proposed for a new alignment; the entries numbered in
 *   the order of the categories.
 * - identity_key(): what names an object apart from its id, by kind; a menu
 *   item by the slug of its menu, not its id.
 * - compose(): packages of one series as one inventory: an object twice or a
 *   kind read whole in one package with objects in another refused; a kind
 *   and subtype complete only where one package read its kind of the
 *   database whole and withheld nothing; held lines marked.
 * - canon_forms(): the target is the staging hash only where nothing maps
 *   it.
 * - The names of the files of a proposal, the words of the reason codes.
 *
 * The proposal over two WordPress sites (packages, the inventory of this
 * site in parts, the store, the canon change on rows, the confirmation with
 * its token): tests/integration/SyncProposalTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Option_Schema;
use AB_MCP_Sync_Proposal;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-proposal.php';

final class SyncProposalTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
	}

	private static function h( string $text ): string {
		return hash( 'sha256', $text );
	}

	/** A line as AB_MCP_Sync_Export::line() writes one. */
	private static function line( string $kind, $ref, array $identity, ?string $hash, array $over = array() ): array {
		$line = array(
			'kind'     => $kind,
			'ref'      => $ref,
			'identity' => $identity,
			'status'   => 'z8',
			'locks'    => array(),
			'canon'    => 'canon/1',
			'hash'     => $hash,
			'size'     => 10,
			'complete' => true,
			'unread'   => array(),
			'refs'     => array(),
			'possible' => array(),
			'b9'       => array(),
		);
		if ( null === $hash ) {
			$line = array_intersect_key( $line, array_flip( array( 'kind', 'ref', 'identity', 'status', 'locks' ) ) );
		}
		return array_merge( $line, $over );
	}

	/** A page (or post of $type) with its parent. */
	private static function page( string $ref, string $name, ?string $hash, ?string $parent = null, array $over = array(), string $type = 'page' ): array {
		$refs = null === $parent ? array() : array(
			array(
				'at'       => 'post_parent',
				'id'       => (int) $parent,
				'space'    => 'post',
				'strength' => 'strong',
			),
		);
		return self::line(
			'post',
			$ref,
			array(
				'post_name' => $name,
				'post_type' => $type,
				'theme'     => '',
			),
			$hash,
			null === $hash ? $over : array_merge( array( 'refs' => $refs ), $over )
		);
	}

	private static function option( string $name, ?string $hash, array $over = array() ): array {
		return self::line( 'option', $name, array( 'name' => $name ), $hash, $over );
	}

	/** match() with every kind complete on the staging side unless told. */
	private static function match( array $s, array $l, array $pairs = array(), ?callable $complete = null ): array {
		return AB_MCP_Sync_Proposal::match( $s, $l, $pairs, $complete ?? static fn(): bool => true );
	}

	/** The entries of a category. */
	private static function of( array $result, string $category ): array {
		return array_values( array_filter( $result['entries'], static fn( $e ) => $category === $e['category'] ) );
	}

	/** A pair as AB_MCP_Sync_Pairs::decode() gives it. */
	private static function pair( int $id, string $kind, string $live, string $staging, string $state, array $over = array() ): array {
		return array_merge(
			array(
				'id'           => $id,
				'source_id'    => str_repeat( 'a', 32 ),
				'kind'         => $kind,
				'live_ref'     => $live,
				'staging_ref'  => $staging,
				'state'        => $state,
				'override'     => null,
				'canon'        => 'canon/1',
				'base_live'    => self::h( 'bl' ),
				'base_staging' => self::h( 'bs' ),
				'base_extra'   => null,
				'base_time'    => 100,
				'base_origin'  => 'proposal',
				'last_run'     => null,
				'updated'      => 100,
			),
			$over
		);
	}

	/* --------------------------------------------------------- one to one */

	public function testTheSameIdentityAndHashIsEqualAndConfirmedActive(): void {
		$r = self::match( array( self::page( '34', 'about', self::h( 'x' ) ) ), array( self::page( '12', 'about', self::h( 'x' ) ) ) );
		self::assertCount( 1, $r['entries'] );
		$e = $r['entries'][0];
		self::assertSame( 'equal', $e['category'] );
		self::assertSame( 'active', $e['confirm'] );
		self::assertSame( '34', $e['staging']['ref'] );
		self::assertSame( '12', $e['live']['ref'] );
		self::assertSame( 1, $e['n'] );
		self::assertSame( 1, $r['counts']['equal'] );
	}

	public function testTheSameIdentityWithOtherContentDiffersAndIsConfirmedUnclear(): void {
		$e = self::match( array( self::page( '34', 'about', self::h( 'x' ) ) ), array( self::page( '12', 'about', self::h( 'y' ) ) ) )['entries'][0];
		self::assertSame( 'differs', $e['category'] );
		self::assertSame( 'unclear', $e['confirm'] );
	}

	public function testAnObjectNotReadWholeIsNeverEqual(): void {
		foreach ( array( 's', 'l' ) as $side ) {
			$s = self::page( '34', 'about', self::h( 'x' ), null, 's' === $side ? array( 'complete' => false ) : array() );
			$l = self::page( '12', 'about', self::h( 'x' ), null, 'l' === $side ? array( 'complete' => false ) : array() );
			self::assertSame( 'differs', self::match( array( $s ), array( $l ) )['entries'][0]['category'], $side . ': the same hash of a form not read to its end says nothing.' );
		}
	}

	public function testAnotherKindTypeOrThemeIsAnotherIdentity(): void {
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ) ), self::line( 'term', '5', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 't' ) ) ),
			array( self::page( '12', 'about', self::h( 'x' ), null, array(), 'post' ), self::line( 'menu', '6', array( 'slug' => 'news', 'taxonomy' => 'nav_menu' ), self::h( 't' ) ) )
		);
		self::assertSame( 0, $r['counts']['equal'] + $r['counts']['differs'] );
		self::assertSame( 2, $r['counts']['staging_only'] );
		self::assertSame( 2, $r['counts']['live_only'] );
	}

	/* ------------------------------------------------------- more than one */

	public function testMoreCandidatesOnASideAreAmbiguousWithAllOfThem(): void {
		$r = self::match(
			array( self::page( '34', '', self::h( 'a' ), null, array(), 'post' ), self::page( '35', '', self::h( 'b' ), null, array(), 'post' ) ),
			array( self::page( '12', '', self::h( 'c' ), null, array(), 'post' ), self::page( '13', '', self::h( 'd' ), null, array(), 'post' ), self::page( '14', '', self::h( 'e' ), null, array(), 'post' ) )
		);
		$e = self::of( $r, 'ambiguous' );
		self::assertCount( 1, $e );
		self::assertSame( 'choice', $e[0]['confirm'] );
		self::assertSame( array( '34', '35' ), array_column( $e[0]['candidates']['staging'], 'ref' ) );
		self::assertSame( array( '12', '13', '14' ), array_column( $e[0]['candidates']['live'], 'ref' ) );
		self::assertNull( $e[0]['staging'] );
	}

	public function testThePathOfParentsTellsPagesOfTheSameSlugApart(): void {
		$s = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '2', 'shop', self::h( 'shop' ) ), self::page( '3', 'about', self::h( 'a1' ), '1' ), self::page( '4', 'about', self::h( 'a2' ), '2' ) );
		$l = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '12', 'shop', self::h( 'shop' ) ), self::page( '13', 'about', self::h( 'a1x' ), '11' ), self::page( '14', 'about', self::h( 'a2x' ), '12' ) );
		$r = self::match( $s, $l );
		self::assertSame( 0, $r['counts']['ambiguous'] );
		$pairs = array();
		foreach ( self::of( $r, 'differs' ) as $e ) {
			$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
		}
		ksort( $pairs );
		self::assertSame( array( '3' => '13', '4' => '14' ), $pairs, 'team/about with team/about, shop/about with shop/about.' );
	}

	public function testAPageMovedOnStagingStillMatchesByItsSlug(): void {
		$s = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '3', 'about', self::h( 'a' ), '1' ) );
		$l = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '13', 'about', self::h( 'b' ) ) );
		$e = array_values( array_filter( self::match( $s, $l )['entries'], static fn( $e ) => 'about' === ( $e['staging']['identity']['post_name'] ?? null ) ) );
		self::assertSame( 'differs', $e[0]['category'], 'One about on each side: a pair, whatever its path.' );
	}

	public function testOnlyThePostParentIsAParentNotAnotherReferenceToAPost(): void {
		$thumb = array(
			'refs' => array(
				array(
					'at'       => 'meta:_thumbnail_id',
					'id'       => 2,
					'space'    => 'post',
					'strength' => 'strong',
				),
			),
		);
		$s     = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '2', 'shop', self::h( 'shop' ) ), self::page( '3', 'about', self::h( 'a' ), '1' ), self::page( '4', 'about', self::h( 'b' ), null, $thumb ), self::page( '5', 'about', self::h( 'c' ), '2' ) );
		$l     = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '12', 'shop', self::h( 'shop' ) ), self::page( '13', 'about', self::h( 'x' ), '11' ), self::page( '14', 'about', self::h( 'y' ) ), self::page( '15', 'about', self::h( 'z' ), '12' ) );
		$r     = self::match( $s, $l );
		$pairs = array();
		foreach ( self::of( $r, 'differs' ) as $e ) {
			$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
		}
		ksort( $pairs );
		self::assertSame( array( '3' => '13', '4' => '14', '5' => '15' ), $pairs, 'The page with a featured image stays at the top.' );
	}

	public function testWhatThePathLeavesOneToOneIsAPair(): void {
		// Two about on each side; one in the same place, the other moved.
		$s = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '2', 'shop', self::h( 'shop' ) ), self::page( '3', 'about', self::h( 'a' ), '1' ), self::page( '4', 'about', self::h( 'b' ), '2' ) );
		$l = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '12', 'shop', self::h( 'shop' ) ), self::page( '13', 'about', self::h( 'a' ), '11' ), self::page( '14', 'about', self::h( 'c' ) ) );
		$r     = self::match( $s, $l );
		$pairs = array();
		foreach ( array_merge( self::of( $r, 'equal' ), self::of( $r, 'differs' ) ) as $e ) {
			$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
		}
		ksort( $pairs );
		self::assertSame( array( '1' => '11', '2' => '12', '3' => '13', '4' => '14' ), $pairs );
		self::assertSame( 0, $r['counts']['ambiguous'] + $r['counts']['staging_only'] + $r['counts']['live_only'] );
	}

	public function testAnEqualHashUniqueOnBothSidesIsAPair(): void {
		$r = self::match(
			array( self::page( '34', '', self::h( 'a' ), null, array(), 'post' ), self::page( '35', '', self::h( 'b' ), null, array(), 'post' ) ),
			array( self::page( '12', '', self::h( 'b' ), null, array(), 'post' ), self::page( '13', '', self::h( 'a' ), null, array(), 'post' ) )
		);
		$pairs = array();
		foreach ( self::of( $r, 'equal' ) as $e ) {
			$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
		}
		ksort( $pairs );
		self::assertSame( array( '34' => '13', '35' => '12' ), $pairs );
		self::assertSame( 0, $r['counts']['ambiguous'] );
	}

	public function testAnEqualHashTwiceOnASideIsNoPair(): void {
		foreach ( array( 's', 'l' ) as $twice ) {
			$s = array( self::page( '34', '', self::h( 'a' ), null, array(), 'post' ), self::page( '35', '', self::h( 's' === $twice ? 'a' : 'b' ), null, array(), 'post' ) );
			$l = array( self::page( '12', '', self::h( 'a' ), null, array(), 'post' ), self::page( '13', '', self::h( 'l' === $twice ? 'a' : 'c' ), null, array(), 'post' ) );
			$r = self::match( $s, $l );
			self::assertSame( 0, $r['counts']['equal'], $twice );
			self::assertSame( 1, $r['counts']['ambiguous'], $twice );
		}
	}

	/* ------------------------------------------------------ no counterpart */

	public function testAnObjectOnlyOnStagingIsStagingOnly(): void {
		$e = self::match( array( self::page( '34', 'new', self::h( 'x' ) ) ), array() )['entries'][0];
		self::assertSame( 'staging_only', $e['category'] );
		self::assertNull( $e['live'] );
		self::assertArrayNotHasKey( 'confirm', $e );
	}

	public function testAnObjectAloneWithoutAHashSaysWhy(): void {
		$e = self::match( array( self::option( 'opt', null, array( 'status' => 'guarded', 'reason_code' => 'option_guard' ) ) ), array() )['entries'][0];
		self::assertSame( 'staging_only', $e['category'] );
		self::assertStringContainsString( 'The option guard of AlphaBridge MCP refuses this option name', $e['reason'] );
		self::assertArrayNotHasKey( 'reason', self::match( array( self::option( 'opt', self::h( 'x' ) ) ), array() )['entries'][0] );
	}

	public function testAnObjectOnlyOnThisSiteIsLiveOnlyOnlyWhereStagingIsComplete(): void {
		$asked = array();
		$r     = self::match(
			array(),
			array( self::page( '12', 'old', self::h( 'x' ) ) ),
			array(),
			static function ( string $kind, string $subtype ) use ( &$asked ): bool {
				$asked[] = $kind . '/' . $subtype;
				return true;
			}
		);
		self::assertSame( 'live_only', $r['entries'][0]['category'] );
		self::assertSame( array( 'post/page' ), $asked, 'Asked for its kind and subtype.' );

		$e = self::match( array(), array( self::page( '12', 'old', self::h( 'x' ) ) ), array(), static fn(): bool => false )['entries'][0];
		self::assertSame( 'not_compared', $e['category'] );
		self::assertStringContainsString( 'does not hold every object of this kind', $e['reason'] );
		self::assertStringContainsString( 'wp_site_export', $e['reason'] );
	}

	/* ---------------------------------------------------------------- held */

	public function testAnObjectACredentialHoldsIsShownByTheHashOfItsRefOnly(): void {
		$secret = 'api_key=CANARY-ONLY';
		$r      = self::match( array( self::option( $secret, self::h( 'x' ), array( 'held' => 'credential_name' ) ) ), array( self::option( $secret, self::h( 'x' ), array( 'held' => 'credential_name' ) ) ) );
		self::assertSame( 1, $r['counts']['held'] );
		self::assertSame( 0, $r['counts']['equal'] );
		$e = $r['entries'][0];
		self::assertSame( array( array( 'ref_sha256' => self::h( '"' . $secret . '"' ), 'held' => 'credential_name' ) ), $e['staging'] );
		self::assertStringNotContainsString( 'CANARY', (string) json_encode( $e ) );
		self::assertSame( array( 'staging' => 1, 'live' => 1 ), $r['held'] );
	}

	public function testAGroupWithAHeldObjectOnEitherSideIsHeldAsAWhole(): void {
		foreach ( array( 's', 'l' ) as $side ) {
			$s = self::option( 'x_token', self::h( 'a' ), 's' === $side ? array( 'held' => 'credential_field' ) : array() );
			$l = self::option( 'x_token', self::h( 'a' ), 'l' === $side ? array( 'held' => 'credential_field' ) : array() );
			$r = self::match( array( $s ), array( $l ) );
			self::assertSame( array( 'held' ), array_column( $r['entries'], 'category' ), $side );
		}
		// A held staging object makes no live object «only live».
		$r = self::match( array( self::option( 'y', self::h( 'a' ), array( 'held' => 'unreadable_name' ) ) ), array() );
		self::assertSame( 'held', $r['entries'][0]['category'] );
		self::assertSame( array(), $r['entries'][0]['live'] );
	}

	/* -------------------------------------------------------- not compared */

	public function testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode(): void {
		$cases = array(
			'option_guard'      => array( 'guarded', array( 'reason_code' => 'option_guard' ), 'The option guard of AlphaBridge MCP refuses this option name' ),
			'read_as_other_row' => array( 'unreachable', array( 'reason_code' => 'read_as_other_row', 'target' => 'other_row' ), 'WordPress reads the row other_row under this name' ),
			'lookup_failed'     => array( 'unreadable', array( 'reason_code' => 'lookup_failed' ), 'could not be looked up' ),
			'not_settled'       => array( 'unreadable', array( 'reason_code' => 'not_settled' ), 'could not be settled' ),
		);
		foreach ( $cases as $code => list( $status, $over, $says ) ) {
			$s = self::option( 'opt', null, array( 'status' => $status ) + $over );
			$l = self::option( 'opt', self::h( 'v' ) );
			$e = self::match( array( $s ), array( $l ) )['entries'][0];
			self::assertSame( 'not_compared', $e['category'], $code );
			self::assertStringContainsString( $says, $e['reason'], $code );
			$e = self::match( array( $l ), array( $s ) )['entries'][0];
			self::assertSame( 'not_compared', $e['category'], $code . ' on this site' );
			self::assertStringContainsString( $says, $e['reason'], $code );
		}
	}

	public function testAnObjectACredentialLockHoldsIsNotCompared(): void {
		$lock = array(
			'key'    => 'post:password_protected',
			'stage'  => 'Z8d',
			'reason' => 'x',
			'at'     => 'post_password',
		);
		$e    = self::match( array( self::page( '34', 'p', null, null, array( 'locks' => array( $lock ) ) ) ), array( self::page( '12', 'p', self::h( 'x' ) ) ) )['entries'][0];
		self::assertSame( 'not_compared', $e['category'] );
		self::assertStringContainsString( 'A credential holds this object', $e['reason'] );
		self::assertSame( array( array( 'key' => 'post:password_protected', 'stage' => 'Z8d' ) ), $e['staging']['locks'] );
	}

	public function testARowNotReadIsNotComparedByItself(): void {
		$r = self::match( array( self::line( 'post', '34', array(), self::h( 'x' ), array( 'unread' => array( 'posts' ) ) ) ), array( self::line( 'post', '12', array(), self::h( 'x' ) ) ) );
		self::assertSame( array( 'not_compared', 'not_compared' ), array_column( $r['entries'], 'category' ) );
		self::assertStringContainsString( 'The staging site could not read this row', $r['entries'][0]['reason'] . $r['entries'][1]['reason'] );
		self::assertStringContainsString( 'This site could not read this row', $r['entries'][0]['reason'] . $r['entries'][1]['reason'] );
	}

	public function testANameNoPairHoldsIsNotCompared(): void {
		$long = str_repeat( 'o', 192 );
		foreach ( array( $long, "a\tb", array( '~b' => base64_encode( "\xff" ) ) ) as $i => $name ) {
			$r = self::match( array( self::option( 'x', self::h( 'x' ), array( 'ref' => $name, 'identity' => array( 'name' => $name ) ) ) ), array( self::option( 'x', self::h( 'x' ), array( 'ref' => $name, 'identity' => array( 'name' => $name ) ) ) ) );
			self::assertSame( array( 'not_compared', 'not_compared' ), array_column( $r['entries'], 'category' ), (string) $i );
			self::assertStringContainsString( 'cannot be held by a pair', $r['entries'][0]['reason'] );
		}
		$held = self::match( array( self::option( 'x', self::h( 'x' ), array( 'ref' => $long, 'identity' => array( 'name' => $long ), 'held' => 'credential_field' ) ) ), array() );
		self::assertSame( array( 'held' ), array_column( $held['entries'], 'category' ), 'A held one stays held, by the hash of its ref.' );
		$ok = str_repeat( 'o', 191 );
		self::assertSame( 'equal', self::match( array( self::option( $ok, self::h( 'x' ) ) ), array( self::option( $ok, self::h( 'x' ) ) ) )['entries'][0]['category'], '191 characters fit.' );
	}

	/* -------------------------------------------------------------- pairs */

	public function testObjectsOfAnActivePairAreNotProposedAgain(): void {
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ) ), self::page( '35', 'contact', self::h( 'c' ) ) ),
			array( self::page( '12', 'about', self::h( 'y' ) ), self::page( '13', 'contact', self::h( 'c' ) ) ),
			array( self::pair( 7, 'post', '12', '34', 'active' ) )
		);
		self::assertSame( 1, $r['paired'] );
		self::assertSame( array( 'equal' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( '35', $r['entries'][0]['staging']['ref'] );
	}

	public function testAPairOfAnotherKindTakesNoObjectAway(): void {
		$r = self::match( array( self::page( '34', 'about', self::h( 'x' ) ) ), array( self::page( '12', 'about', self::h( 'x' ) ) ), array( self::pair( 7, 'term', '12', '34', 'active' ) ) );
		self::assertSame( array( 'equal' ), array_column( $r['entries'], 'category' ) );
	}

	public function testUnclearAndResidualPairsAreProposedForANewAlignment(): void {
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ) ), self::page( '35', 'team', self::h( 't' ) ) ),
			array( self::page( '12', 'about', self::h( 'y' ) ), self::page( '13', 'team', self::h( 't' ) ) ),
			array( self::pair( 7, 'post', '12', '34', 'unclear' ), self::pair( 8, 'post', '13', '35', 'residual' ) )
		);
		self::assertSame( 0, $r['paired'] );
		$e = self::of( $r, 'realign' );
		self::assertCount( 2, $e );
		self::assertSame( array( 7, 8 ), array( $e[0]['pair']['id'], $e[1]['pair']['id'] ) );
		self::assertSame( array( 'active', 'active' ), array_column( $e, 'confirm' ) );
		self::assertStringContainsString( 'The two sides differ now', $e[0]['note'] );
		self::assertArrayNotHasKey( 'note', $e[1], 'Equal now: nothing to say.' );
		self::assertSame( self::h( AB_MCP_Sync_Proposal::pair_snapshot( self::pair( 7, 'post', '12', '34', 'unclear' ) ) ), $e[0]['pair']['snapshot'] );
		self::assertSame( 0, $r['counts']['equal'] + $r['counts']['differs'], 'Their objects are taken.' );
	}

	public function testAPairWhoseObjectIsGoneIsNotAlignedAnew(): void {
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ) ) ),
			array( self::page( '13', 'team', self::h( 't' ) ) ),
			array( self::pair( 7, 'post', '12', '34', 'residual' ), self::pair( 8, 'post', '13', '35', 'unclear' ), self::pair( 9, 'post', '14', '36', 'unclear' ) )
		);
		$e = self::of( $r, 'realign' );
		self::assertSame( array( null, null, null ), array_column( $e, 'confirm' ) );
		self::assertStringStartsWith( 'Rest removed: the object is no longer on this site.', $e[0]['reason'] );
		self::assertStringContainsString( 'action=discard', $e[0]['reason'] );
		self::assertStringStartsWith( 'The object is not in the inventory of the staging site.', $e[1]['reason'] );
		self::assertStringStartsWith( 'The object is no longer on this site.', $e[2]['reason'] );
		self::assertSame( '14', $e[2]['live']['ref'] );
	}

	public function testAPairWithAHeldSideIsNotAlignedAnew(): void {
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ), null, array( 'held' => 'credential_field' ) ) ),
			array( self::page( '12', 'about', self::h( 'x' ) ) ),
			array( self::pair( 7, 'post', '12', '34', 'unclear' ) )
		);
		self::assertNull( self::of( $r, 'realign' )[0]['confirm'] );
		$r = self::match(
			array( self::page( '34', 'about', self::h( 'x' ) ) ),
			array( self::page( '12', 'about', self::h( 'x' ), null, array( 'held' => 'credential_name' ) ) ),
			array( self::pair( 7, 'post', '12', '34', 'unclear' ) )
		);
		self::assertNull( self::of( $r, 'realign' )[0]['confirm'], 'Held on this site.' );
		foreach ( array( 's', 'l' ) as $side ) {
			$r = self::match(
				array( self::page( '34', 'about', 's' === $side ? null : self::h( 'x' ) ) ),
				array( self::page( '12', 'about', 'l' === $side ? null : self::h( 'x' ) ) ),
				array( self::pair( 7, 'post', '12', '34', 'unclear' ) )
			);
			$e = self::of( $r, 'realign' )[0];
			self::assertNull( $e['confirm'], $side . ': without a hash.' );
			self::assertStringContainsString( 'is not compared', $e['reason'] );
		}
	}

	/* ------------------------------------------------------------ kinds */

	public function testAMenuItemMatchesByTheSlugOfItsMenuNotItsId(): void {
		$item = static fn( string $ref, int $menu, string $hash ): array => self::line(
			'menu_item',
			$ref,
			array(
				'menus'    => array( $menu ),
				'object'   => 'page',
				'position' => 1,
				'type'     => 'post_type',
			),
			self::h( $hash )
		);
		$menu = static fn( string $ref, string $slug ): array => self::line( 'menu', $ref, array( 'slug' => $slug, 'taxonomy' => 'nav_menu' ), self::h( 'm' . $slug ) );
		// The same hash for both items: only the slug of the menu tells them apart.
		$r    = self::match( array( $menu( '5', 'main' ), $menu( '6', 'footer' ), $item( '50', 5, 'i' ), $item( '60', 6, 'i' ) ), array( $menu( '15', 'footer' ), $menu( '16', 'main' ), $item( '150', 16, 'i' ), $item( '160', 15, 'i' ) ) );
		$pairs = array();
		foreach ( self::of( $r, 'equal' ) as $e ) {
			$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
		}
		ksort( $pairs, SORT_STRING );
		self::assertSame( array( '5' => '16', '50' => '150', '6' => '15', '60' => '160' ), $pairs );
	}

	public function testTheIdentityKeyOfEachKind(): void {
		$k = static fn( string $kind, array $identity ): ?string => AB_MCP_Sync_Proposal::identity_key( $kind, $identity );
		self::assertNull( $k( 'post', array() ) );
		self::assertNotSame( $k( 'term', array( 'slug' => 'a', 'taxonomy' => 'category' ) ), $k( 'menu', array( 'slug' => 'a', 'taxonomy' => 'category' ) ) );
		self::assertNotSame( $k( 'term', array( 'slug' => 'a', 'taxonomy' => 'category' ) ), $k( 'term', array( 'slug' => 'a', 'taxonomy' => 'post_tag' ) ) );
		self::assertNotSame( $k( 'post', array( 'post_type' => 'wp_template', 'post_name' => 'index', 'theme' => 'one' ) ), $k( 'post', array( 'post_type' => 'wp_template', 'post_name' => 'index', 'theme' => 'two' ) ) );
		self::assertNotSame( $k( 'custom_css', array( 'stylesheet' => 'one', 'linked' => true, 'post' => 5 ) ), $k( 'custom_css', array( 'stylesheet' => 'one', 'linked' => false, 'post' => 5 ) ) );
		self::assertSame( $k( 'custom_css', array( 'stylesheet' => 'one', 'linked' => true, 'post' => 5 ) ), $k( 'custom_css', array( 'stylesheet' => 'one', 'linked' => true, 'post' => 9 ) ), 'The post id names no CSS across sites.' );
		self::assertNotSame( $k( 'theme_mod', array( 'name' => 'theme_mods_a', 'theme_mod' => 'x' ) ), $k( 'theme_mod', array( 'name' => 'theme_mods_a', 'theme_mod' => 'y' ) ) );
		self::assertSame( $k( 'widget_area', array( 'area' => 'sidebar-1' ) ), $k( 'widget_area', array( 'area' => 'sidebar-1' ) ) );
		self::assertNotSame( $k( 'option', array( 'name' => 'a' ) ), $k( 'option', array( 'name' => 'b' ) ) );
		self::assertNotSame( $k( 'menu_item', array( 'menus' => array( 1 ), 'object' => 'page', 'position' => 1, 'type' => 'post_type' ) ), $k( 'menu_item', array( 'menus' => array( 1 ), 'object' => 'page', 'position' => 2, 'type' => 'post_type' ) ) );
	}

	/* ------------------------------------------------------------ order */

	public function testTheEntriesAreNumberedInTheOrderOfTheCategories(): void {
		$r = self::match(
			array( self::page( '1', 'only-s', self::h( 'a' ) ), self::page( '2', 'diff', self::h( 'b' ) ), self::page( '3', 'same', self::h( 'c' ) ) ),
			array( self::page( '11', 'only-l', self::h( 'd' ) ), self::page( '12', 'diff', self::h( 'e' ) ), self::page( '13', 'same', self::h( 'c' ) ) )
		);
		self::assertSame( array( 'equal', 'differs', 'staging_only', 'live_only' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $r['entries'], 'n' ) );
		self::assertArrayNotHasKey( 'sort', $r['entries'][0] );
		self::assertSame( array( 'equal', 'differs', 'ambiguous', 'realign', 'staging_only', 'live_only', 'not_compared', 'held' ), AB_MCP_Sync_Proposal::CATEGORIES );
	}

	public function testAnAnswerShowsNoHashOrSnapshot(): void {
		$r = self::match( array( self::page( '34', 'about', self::h( 'x' ) ) ), array( self::page( '12', 'about', self::h( 'x' ) ) ), array( self::pair( 7, 'post', '13', '35', 'unclear' ) ) );
		foreach ( $r['entries'] as $entry ) {
			$shown = (string) json_encode( AB_MCP_Sync_Proposal::shown( $entry ) );
			self::assertStringNotContainsString( self::h( 'x' ), $shown );
			self::assertStringNotContainsString( '"snapshot"', $shown );
		}
		$amb = self::match( array( self::page( '1', '', self::h( 'p' ), null, array(), 'post' ), self::page( '2', '', self::h( 'q' ), null, array(), 'post' ) ), array( self::page( '3', '', self::h( 'r' ), null, array(), 'post' ), self::page( '4', '', self::h( 's' ), null, array(), 'post' ) ) )['entries'][0];
		self::assertStringNotContainsString( self::h( 'p' ), (string) json_encode( AB_MCP_Sync_Proposal::shown( $amb ) ) );
	}

	/* ------------------------------------------------------------- series */

	/** taken() of a package with these lines and kinds. */
	private static function taken( array $lines, array $kinds, array $held = array(), int $expires = 5000 ): array {
		return array(
			'manifest' => array(
				'kinds'   => $kinds,
				'expires' => $expires,
			),
			'lines'    => $lines,
			'held'     => $held,
		);
	}

	private static function kind( string $kind, string $subtype, bool $complete ): array {
		return array(
			'complete' => $complete,
			'kind'     => $kind,
			'subtype'  => $subtype,
		);
	}

	public function testPackagesOfOneSeriesAreOneInventory(): void {
		$one = self::taken( array( self::page( '1', 'a', self::h( 'a' ) ), self::page( '2', 'b', self::h( 'b' ) ) ), array( self::kind( 'post', 'page', true ) ), array(), 6000 );
		$two = self::taken( array( self::line( 'term', '5', array( 'slug' => 's', 'taxonomy' => 'category' ), self::h( 't' ) ), self::option( 'blogname', self::h( 'o' ) ) ), array( self::kind( 'term', 'category', true ), self::kind( 'option', '', false ) ), array(), 7000 );
		$all = AB_MCP_Sync_Proposal::compose( array( $one, $two ) );
		self::assertIsArray( $all );
		self::assertSame( 4, $all['objects'] );
		self::assertSame( 6000, $all['expires'], 'The earliest package.' );
		self::assertTrue( AB_MCP_Sync_Proposal::complete( $all, 'post', 'page' ) );
		self::assertTrue( AB_MCP_Sync_Proposal::complete( $all, 'post', 'post' ), 'Read whole and named nowhere: none on staging.' );
		self::assertTrue( AB_MCP_Sync_Proposal::complete( $all, 'term', 'post_tag' ) );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'option', '' ), 'The option kind was not read whole in a package.' );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'theme_mod', '' ), 'theme_mod is of the option kind.' );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'menu', 'nav_menu' ), 'No package names the menu kind.' );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'nonsense', '' ) );
	}

	public function testASubtypeWithheldInThePackageThatReadItsKindIsNotComplete(): void {
		$all = AB_MCP_Sync_Proposal::compose( array( self::taken( array( self::page( '1', 'a', self::h( 'a' ) ) ), array( self::kind( 'post', 'page', true ), self::kind( 'post', 'post', false ) ) ) ) );
		self::assertTrue( AB_MCP_Sync_Proposal::complete( $all, 'post', 'page' ) );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'post', 'post' ) );
	}

	public function testAKindReadAcrossPackagesIsCompleteInNone(): void {
		$all = AB_MCP_Sync_Proposal::compose(
			array(
				self::taken( array( self::page( '1', 'a', self::h( 'a' ) ) ), array( self::kind( 'post', 'page', false ) ) ),
				self::taken( array( self::page( '2', 'b', self::h( 'b' ) ) ), array( self::kind( 'post', 'page', false ) ) ),
			)
		);
		self::assertIsArray( $all );
		self::assertSame( 2, $all['objects'] );
		self::assertFalse( AB_MCP_Sync_Proposal::complete( $all, 'post', 'page' ) );
	}

	public function testAnObjectInTwoPackagesIsRefused(): void {
		$res = AB_MCP_Sync_Proposal::compose(
			array(
				self::taken( array( self::page( '1', 'a', self::h( 'a' ) ) ), array( self::kind( 'post', 'page', false ) ) ),
				self::taken( array( self::page( '1', 'a', self::h( 'b' ) ) ), array( self::kind( 'post', 'page', false ) ) ),
			)
		);
		self::assertInstanceOf( WP_Error::class, $res );
		self::assertSame( 'ab_mcp_sync_pairs_series', $res->get_error_code() );
		self::assertStringContainsString( 'Pass the packages of one series only', $res->get_error_message() );
	}

	public function testAKindWholeInOnePackageWithObjectsInAnotherIsRefused(): void {
		$whole = self::taken( array( self::page( '1', 'a', self::h( 'a' ) ) ), array( self::kind( 'post', 'page', true ) ) );
		$other = self::taken( array( self::page( '2', 'b', self::h( 'b' ) ) ), array( self::kind( 'post', 'post', false ) ) );
		self::assertSame( 'ab_mcp_sync_pairs_series', AB_MCP_Sync_Proposal::compose( array( $whole, $other ) )->get_error_code(), 'Objects of the kind in another package.' );
		self::assertSame( 'ab_mcp_sync_pairs_series', AB_MCP_Sync_Proposal::compose( array( $whole, $whole ) )->get_error_code(), 'The kind whole in two packages.' );
		$term = self::taken( array( self::line( 'term', '5', array( 'slug' => 's', 'taxonomy' => 'category' ), self::h( 't' ) ) ), array( self::kind( 'term', 'category', true ) ) );
		self::assertIsArray( AB_MCP_Sync_Proposal::compose( array( $whole, $term ) ), 'Another kind is no conflict.' );
	}

	public function testTheListOfHeldObjectsMarksTheirLines(): void {
		$ref = 'api_key=x';
		$all = AB_MCP_Sync_Proposal::compose( array( self::taken( array( self::option( $ref, null ), self::option( 'blogname', self::h( 'o' ) ) ), array(), array( array( 'option', self::h( '"' . $ref . '"' ), 'credential_name' ), array( 'post', self::h( '"blogname"' ), 'credential_name' ) ) ) ) );
		self::assertSame( 'credential_name', $all['lines'][0]['held'] );
		self::assertArrayNotHasKey( 'held', $all['lines'][1], 'Kind and ref both.' );
		self::assertSame( 1, $all['held'] );
	}

	public function testThePairKindsOfTheDatabaseKinds(): void {
		self::assertSame(
			array(
				'post'        => 'post',
				'term'        => 'term',
				'menu'        => 'menu',
				'menu_item'   => 'menu',
				'widget_area' => 'widget_area',
				'custom_css'  => 'custom_css',
				'option'      => 'option',
				'theme_mod'   => 'option',
			),
			AB_MCP_Sync_Proposal::db_kinds()
		);
	}

	/* -------------------------------------------------------------- canon */

	public function testTheTargetOfACanonChangeIsTheStagingHashOnlyWhereNothingMapsIt(): void {
		$pair = self::pair( 7, 'post', '12', '34', 'active', array( 'canon' => 'canon/9' ) );
		$s    = self::page( '34', 'a', self::h( 's' ) );
		$l    = self::page( '12', 'a', self::h( 'l' ) );
		self::assertSame( array( 'live' => self::h( 'l' ), 'staging' => self::h( 's' ), 'target' => self::h( 's' ) ), AB_MCP_Sync_Proposal::canon_forms( $pair, $s, $l ) );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( array( 'override' => array( 'post_status' => 'draft' ) ) + $pair, $s, $l )['target'], 'An override changes the target.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, self::page( '34', 'a', self::h( 's' ), '9' ), $l )['target'], 'A post named by its id.' );
		$term = self::page( '34', 'a', self::h( 's' ), null, array( 'refs' => array( array( 'at' => 'terms', 'id' => 3, 'space' => 'term', 'strength' => 'strong' ) ) ) );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, $term, $l )['target'], 'A term named by its id.' );
		$user = self::page( '34', 'a', self::h( 's' ), null, array( 'refs' => array( array( 'at' => 'post_author', 'id' => 1, 'login' => 'admin', 'space' => 'user', 'strength' => 'strong' ) ) ) );
		self::assertSame( self::h( 's' ), AB_MCP_Sync_Proposal::canon_forms( $pair, $user, $l )['target'], 'An author is named by the login.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, self::page( '34', 'a', self::h( 's' ), null, array( 'possible' => array( array( 'at' => 'post_content' ) ) ) ), $l )['target'], 'A possible reference.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, null, $l )['staging'] );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, $s, self::page( '12', 'a', self::h( 'l' ), null, array( 'complete' => false ) ) )['live'], 'Not read whole: no form.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, $s, self::page( '12', 'a', self::h( 'l' ), null, array( 'held' => 'credential_field' ) ) )['live'], 'Held: no form.' );
	}

	/* ------------------------------------------------------------- names */

	public function testTheNamesOfTheFilesOfAProposal(): void {
		$sha = self::h( 'file' );
		foreach ( array( array( 'h', null ), array( 'x', null ), array( 'c', 1 ), array( 'd', 12 ), array( 'e', 9999 ) ) as list( $prefix, $seq ) ) {
			$name = AB_MCP_Sync_Proposal::name( $prefix, $seq, $sha );
			self::assertLessThanOrEqual( 64, strlen( $name ) );
			self::assertMatchesRegularExpression( '/^[a-z0-9._-]{1,64}\z/', $name, 'A name of the protected store.' );
			self::assertSame(
				array(
					'prefix' => $prefix,
					'seq'    => $seq,
					'sha'    => $sha,
					'name'   => $name,
				),
				AB_MCP_Sync_Proposal::parse_name( $name )
			);
		}
		self::assertNull( AB_MCP_Sync_Proposal::parse_name( 'c' . AB_MCP_Sync_Proposal::name( 'h', null, $sha ) ) );
		self::assertNull( AB_MCP_Sync_Proposal::parse_name( 'h' . str_repeat( 'a', 51 ) . 'b' ), 'The last letter carries four bits that are 0.' );
		self::assertNull( AB_MCP_Sync_Proposal::parse_name( 'complete' ) );
		self::assertNull( AB_MCP_Sync_Proposal::parse_name( 'd1-' . str_repeat( 'a', 52 ) ) );
	}

	/* ------------------------------------------------------- reason codes */

	public function testEachReasonCodeHasTheWordsTheSiteThatReadTheRowWrites(): void {
		self::assertSame( array( 'option_guard', 'read_as_other_row', 'lookup_failed', 'not_settled' ), array_keys( AB_MCP_Sync_Option_Schema::REASON_TEXTS ) );
		self::assertSame( array_merge( ...array_values( AB_MCP_Sync_Option_Schema::REASON_CODES ) ), array_keys( AB_MCP_Sync_Option_Schema::REASON_TEXTS ) );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/sync/class-sync-option-schema.php' );
		foreach ( array( 'option_guard', 'lookup_failed', 'not_settled' ) as $code ) {
			self::assertSame( 2, substr_count( $source, "'" . AB_MCP_Sync_Option_Schema::REASON_TEXTS[ $code ] . "'" ), $code . ': the same words in settle().' );
		}
		self::assertStringContainsString( "'WordPress reads the row %s under this name, so this row is never read and is not taken over.'", $source, 'classify()' );
		self::assertSame( 'WordPress reads the row x under this name, so this row is never read and is not taken over. An administrator changes it in wp-admin.', AB_MCP_Sync_Option_Schema::reason_text( 'read_as_other_row', 'x' ) );
		self::assertStringContainsString( 'the row of another name under', (string) AB_MCP_Sync_Option_Schema::reason_text( 'read_as_other_row', array( '~b' => 'x' ) ) );
		self::assertNull( AB_MCP_Sync_Option_Schema::reason_text( 'nonsense' ) );
		self::assertNull( AB_MCP_Sync_Option_Schema::reason_text( null ) );
	}
}
