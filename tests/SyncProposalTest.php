<?php
/**
 * The proposal of object pairs as far as a test without a database shows it
 * (AB_MCP_Sync_Proposal, Z8 decisions 10, 13 and 14; branch 6, part 1):
 *
 * - match(): equality of the mapped forms (decision 10: the same objects
 *   with other ids are equal), only both read whole; differs otherwise and
 *   where a side names a target without identity. No pair by exclusion: one
 *   to one on the same identity and path is a pair (each parent by its type,
 *   theme, slug and language); an identity there more than once on a site
 *   (twice on a path of a side, or a line its site names a twin) pairs only
 *   by an equal mapped form there once on each side, where every twin of
 *   both sites is at hand (a published page and a draft of its slug); else,
 *   what is left, twins of one form and menu items are uncertain, also with
 *   one of them at hand or the other one paired; the
 *   same identity on another path, a post without a slug and a menu item
 *   without the identity of its target or parent are uncertain; staging_only,
 *   live_only only where the staging side is complete, else not_compared; a
 *   held line is shown by the SHA-256 of its ref only; a side without a
 *   hash, a row not read and a ref no pair holds are not compared, with the
 *   words of the reason code; objects of a pair are not proposed again,
 *   unclear and residual pairs are proposed for a new alignment; entries
 *   numbered in the order of the categories. A reference to the object of a
 *   pair is equal only where the other side names its other object
 *   (refs_agree()): else differs, or uncertain where it names the object
 *   (the parent of a post, the target or menu of a menu item); a target
 *   without a pair stays with the mapped form, whether its line is in the
 *   package or not (the matrix of every place a reference stands, with and
 *   without the lines of the targets; Codex rounds 1 to 3 of part 2); a
 *   chosen pair is active only so (alike(), also as the store keeps the
 *   sides).
 * - identity_key(): what names an object apart from its id, by kind; a menu
 *   item by the slug of its menu, its target and parent, never its position.
 * - compose(): packages of one series as one inventory: an object twice or a
 *   kind read whole in one package with objects in another refused; a kind
 *   and subtype complete only where one package read its kind of the
 *   database whole and withheld nothing; held lines reduced.
 * - canon_forms(): the target is the live hash only where the mapped forms
 *   are the same and no override changes it.
 * - The names of the files of a proposal, the words of the reason codes.
 *
 * The proposal over two WordPress sites (packages, the inventory of this
 * site in parts, the mapped forms of real rows, the store, the canon change
 * on rows, the confirmation with its token in a transaction):
 * tests/integration/SyncProposalTest.php.
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

	/**
	 * A line as AB_MCP_Sync_Export::line() writes one: its mapped form the
	 * same as its hash unless told (both sites with the same ids).
	 */
	private static function line( string $kind, $ref, array $identity, ?string $hash, array $over = array() ): array {
		$line = array(
			'kind'     => $kind,
			'ref'      => $ref,
			'identity' => $identity,
			'status'   => 'z8',
			'locks'    => array(),
			'canon'    => 'canon/1',
			'hash'     => $hash,
			'mapped'   => $hash,
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

	/** A held line, reduced as the import and the inventory store it. */
	private static function held( string $kind, $ref, string $code ): array {
		return AB_MCP_Sync_Proposal::reduced(
			array(
				'kind' => $kind,
				'ref'  => $ref,
			),
			$code
		);
	}

	/** match() with every kind complete on the staging side unless told. */
	private static function match( array $s, array $l, array $pairs = array(), ?callable $complete = null ): array {
		return AB_MCP_Sync_Proposal::match( $s, $l, $pairs, $complete ?? static fn(): bool => true );
	}

	/** The entries of a category. */
	private static function of( array $result, string $category ): array {
		return array_values( array_filter( $result['entries'], static fn( $e ) => $category === $e['category'] ) );
	}

	/** The pairs of entries of these categories: staging ref => live ref. */
	private static function paired( array $result, array $categories = array( 'equal', 'differs' ) ): array {
		$pairs = array();
		foreach ( $categories as $category ) {
			foreach ( self::of( $result, $category ) as $e ) {
				$pairs[ $e['staging']['ref'] ] = $e['live']['ref'];
			}
		}
		ksort( $pairs, SORT_STRING );
		return $pairs;
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

	public function testTheSameIdentityAndMappedFormIsEqualAndConfirmedActive(): void {
		$r = self::match( array( self::page( '34', 'about', self::h( 'x' ) ) ), array( self::page( '12', 'about', self::h( 'x' ) ) ) );
		self::assertCount( 1, $r['entries'] );
		$e = $r['entries'][0];
		self::assertSame( 'equal', $e['category'] );
		self::assertSame( 'active', $e['confirm'] );
		self::assertSame( '34', $e['staging']['ref'] );
		self::assertSame( '12', $e['live']['ref'] );
		self::assertSame( array(), $e['live']['path'], 'The path of parents is shown.' );
		self::assertSame( 1, $e['n'] );
		self::assertSame( 1, $r['counts']['equal'] );
	}

	public function testOtherIdsOfTheSameTargetsAreEqualOnceMapped(): void {
		// Parent 1 on staging, 11 here: each hash in its own ID space differs,
		// the mapped forms (the parent by its identity) are the same.
		$s = self::page( '3', 'team-page', self::h( 'with 1' ), null, array( 'mapped' => self::h( 'mapped' ) ) );
		$l = self::page( '13', 'team-page', self::h( 'with 11' ), null, array( 'mapped' => self::h( 'mapped' ) ) );
		self::assertSame( 'equal', self::match( array( $s ), array( $l ) )['entries'][0]['category'] );
		$l['mapped'] = self::h( 'other' );
		self::assertSame( 'differs', self::match( array( $s ), array( $l ) )['entries'][0]['category'], 'Another mapped form: other content.' );
		$s['hash']   = self::h( 'with 11' );
		self::assertSame( 'differs', self::match( array( $s ), array( $l ) )['entries'][0]['category'], 'The same hash in another ID space is no proof.' );
	}

	public function testEitherMappedFormMakesTheSame(): void {
		$cases = array(
			'possible references by identity' => array( array( 'm1', 'p' ), array( 'm2', 'p' ), 'equal' ),
			'a number that is no reference'   => array( array( 'm', 'p1' ), array( 'm', 'p2' ), 'equal' ),
			'neither'                         => array( array( 'm1', 'p1' ), array( 'm2', 'p2' ), 'differs' ),
			'one side without the second'     => array( array( 'm1', 'p' ), array( 'm2', null ), 'differs' ),
			'both without the second'         => array( array( 'm1', null ), array( 'm2', null ), 'differs' ),
		);
		foreach ( $cases as $what => list( $a, $b, $category ) ) {
			$s = self::page( '34', 'about', self::h( 'x' ), null, array( 'mapped' => self::h( $a[0] ), 'mapped_possible' => null === $a[1] ? null : self::h( $a[1] ) ) );
			$l = self::page( '12', 'about', self::h( 'y' ), null, array( 'mapped' => self::h( $b[0] ), 'mapped_possible' => null === $b[1] ? null : self::h( $b[1] ) ) );
			self::assertSame( $category, self::match( array( $s ), array( $l ) )['entries'][0]['category'], $what );
		}
		$s = self::page( '34', 'about', self::h( 'x' ), null, array( 'mapped' => null, 'mapped_possible' => null ) );
		self::assertFalse( AB_MCP_Sync_Proposal::mapped_same( $s, $s ), 'No mapped form at all: never the same.' );
	}

	public function testAPathNotKnownWholeIsUncertainNeverAPair(): void {
		$cases = array(
			'parents not read'      => array(
				array( self::page( '34', 'kontakt', self::h( 'x' ), '90' ) ),
				array( self::page( '12', 'kontakt', self::h( 'y' ), '80' ) ),
			),
			'a parent without slug' => array(
				array( self::page( '9', '', self::h( 'd' ) ), self::page( '34', 'kontakt', self::h( 'x' ), '9' ) ),
				array( self::page( '19', '', self::h( 'd' ) ), self::page( '12', 'kontakt', self::h( 'x' ), '19' ) ),
			),
			'a parent twice'        => array(
				array( self::page( '8', 'team', self::h( 't1' ) ), self::page( '9', 'team', self::h( 't2' ) ), self::page( '34', 'kontakt', self::h( 'x' ), '9' ) ),
				array( self::page( '18', 'team', self::h( 't1' ) ), self::page( '12', 'kontakt', self::h( 'x' ), '18' ) ),
			),
		);
		foreach ( $cases as $what => list( $s, $l ) ) {
			$r       = self::match( $s, $l );
			$kontakt = array_values( array_filter( self::of( $r, 'uncertain' ), static fn( $e ) => 'kontakt' === ( $e['candidates']['staging'][0]['identity']['post_name'] ?? null ) ) );
			self::assertCount( 1, $kontakt, $what . ': "?" names no parent the two sites share.' );
			self::assertArrayNotHasKey( '34', self::paired( $r ), $what );
		}
		$r = self::match( $cases['parents not read'][0], $cases['parents not read'][1] );
		self::assertStringContainsString( 'path of its parents is not known whole', self::of( $r, 'uncertain' )[0]['reason'] );
	}

	public function testAReferenceWithoutATargetIsADifference(): void {
		foreach ( array( 's', 'l', 'both' ) as $side ) {
			$s = self::page( '34', 'about', self::h( 'x' ), null, 'l' !== $side ? array( 'mapped' => null ) : array() );
			$l = self::page( '12', 'about', self::h( 'x' ), null, 's' !== $side ? array( 'mapped' => null ) : array() );
			self::assertSame( 'differs', self::match( array( $s ), array( $l ) )['entries'][0]['category'], $side . ': also where both sides name targets without identity alike.' );
		}
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
			self::assertSame( 'differs', self::match( array( $s ), array( $l ) )['entries'][0]['category'], $side . ': the same form not read to its end says nothing.' );
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

	/* --------------------------------------------- no pair by exclusion */

	public function testMoreOfTheSameIdentityOnAPathAreUncertainWithAllOfThem(): void {
		$r = self::match(
			array( self::page( '34', 'dup', self::h( 'a' ) ), self::page( '35', 'dup', self::h( 'b' ) ) ),
			array( self::page( '12', 'dup', self::h( 'c' ) ), self::page( '13', 'dup', self::h( 'd' ) ), self::page( '14', 'dup', self::h( 'e' ) ) )
		);
		$e = self::of( $r, 'uncertain' );
		self::assertCount( 1, $e );
		self::assertSame( 'choice', $e[0]['confirm'] );
		self::assertSame( array( '34', '35' ), array_column( $e[0]['candidates']['staging'], 'ref' ) );
		self::assertSame( array( '12', '13', '14' ), array_column( $e[0]['candidates']['live'], 'ref' ) );
		self::assertNull( $e[0]['staging'] );
		self::assertStringContainsString( 'Another object of its site has the same identity', $e[0]['reason'] );
		foreach ( array( 's' => array( 2, 1 ), 'l' => array( 1, 2 ) ) as $twice => list( $on_s, $on_l ) ) {
			$r = self::match( array_slice( array( self::page( '34', 'dup', self::h( 'a' ) ), self::page( '35', 'dup', self::h( 'b' ) ) ), 0, $on_s ), array_slice( array( self::page( '12', 'dup', self::h( 'a' ) ), self::page( '13', 'dup', self::h( 'b' ) ) ), 0, $on_l ) );
			self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ), $twice . ': twice on one side is enough.' );
			self::assertStringContainsString( 'Another object of its site has the same identity', $r['entries'][0]['reason'], $twice . ': as twins, not as another path.' );
		}
	}

	public function testThePathOfParentsTellsPagesOfTheSameSlugApart(): void {
		$s = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '2', 'shop', self::h( 'shop' ) ), self::page( '3', 'about', self::h( 'a1' ), '1' ), self::page( '4', 'about', self::h( 'a2' ), '2' ) );
		$l = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '12', 'shop', self::h( 'shop' ) ), self::page( '13', 'about', self::h( 'a1x' ), '11' ), self::page( '14', 'about', self::h( 'a2x' ), '12' ) );
		$r = self::match( $s, $l );
		self::assertSame( 0, $r['counts']['uncertain'] );
		self::assertSame( array( '1' => '11', '2' => '12', '3' => '13', '4' => '14' ), self::paired( $r ), 'team/about with team/about, shop/about with shop/about.' );
		$about = array_values( array_filter( self::of( $r, 'differs' ), static fn( $e ) => '13' === $e['live']['ref'] ) )[0];
		self::assertSame( array( array( 'team' ), array( 'team' ) ), array( $about['staging']['path'], $about['live']['path'] ), 'The path shown with the identity.' );
	}

	public function testTheSameSlugOnAnotherPathIsUncertainNeverAPair(): void {
		// /team/kontakt on staging, /shop/kontakt here: two objects, or one that moved.
		$s = array( self::page( '1', 'team', self::h( 'team' ) ), self::page( '2', 'shop', self::h( 'shop' ) ), self::page( '3', 'kontakt', self::h( 'k' ), '1' ) );
		$l = array( self::page( '11', 'team', self::h( 'team' ) ), self::page( '12', 'shop', self::h( 'shop' ) ), self::page( '13', 'kontakt', self::h( 'k' ), '12' ) );
		$r = self::match( $s, $l );
		$e = self::of( $r, 'uncertain' );
		self::assertCount( 1, $e, 'Not paired by exclusion, even with the same content.' );
		self::assertSame( 'choice', $e[0]['confirm'] );
		self::assertSame( array( array( 'team' ) ), array_column( $e[0]['candidates']['staging'], 'path' ) );
		self::assertSame( array( array( 'shop' ) ), array_column( $e[0]['candidates']['live'], 'path' ) );
		self::assertStringContainsString( 'another path of parents', $e[0]['reason'] );
		self::assertSame( array( '1' => '11', '2' => '12' ), self::paired( $r ) );
	}

	public function testPostsWithoutASlugAreUncertainNeverAPair(): void {
		$r = self::match( array( self::page( '34', '', self::h( 'draft' ), null, array(), 'post' ) ), array( self::page( '12', '', self::h( 'draft' ), null, array(), 'post' ) ) );
		self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ), 'One each, the same content: still the person\'s choice.' );
		self::assertStringContainsString( 'Without a slug', $r['entries'][0]['reason'] );
		$r = self::match( array( self::page( '34', '', self::h( 'draft' ), null, array(), 'post' ) ), array() );
		self::assertSame( array( 'staging_only' ), array_column( $r['entries'], 'category' ) );
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
		self::assertSame( array( '1' => '11', '2' => '12', '3' => '13', '4' => '14', '5' => '15' ), self::paired( self::match( $s, $l ) ), 'The page with a featured image stays at the top.' );
	}

	/* -------------------- references to the objects of pairs (part 2, b) */

	/** A reference of a line to a post or term of its side. */
	private static function to( string $space, int $id, string $at ): array {
		return array(
			'at'       => $at,
			'id'       => $id,
			'space'    => $space,
			'strength' => 'strong',
		);
	}

	/** A term of a taxonomy, its identity its slug. */
	private static function term( string $ref, string $slug, string $taxonomy = 'category' ): array {
		return self::line( 'term', $ref, array( 'slug' => $slug, 'taxonomy' => $taxonomy ), self::h( 't' . $ref ) );
	}

	/** An entry as the store gives it back (JSON, as AB_MCP_Sync_Proposal::put() writes it). */
	private static function stored( array $entry ): array {
		return json_decode( \AB_MCP_Sync_Canon::encode( $entry ), true );
	}

	/**
	 * Codex round 2 of part 2, b: the pair S34 → L12 began as «team»; L12 is
	 * renamed «team-alt» here and L13 takes «team». A page «kontakt» new on
	 * both sides, on staging under S34, here under L13, has the same mapped
	 * form on both (its parent by the identity «team»), but staging names L12
	 * through the pair: another parent, so uncertain, never equal; chosen by a
	 * person it is confirmed unclear.
	 */
	public function testAPageUnderTheObjectOfAPairOnOneSideAndAnotherOnTheOtherIsUncertain(): void {
		$pairs = array( self::pair( 1, 'post', '12', '34', 'active' ) );
		$same  = array( 'mapped' => self::h( 'kontakt under team' ) );
		$r     = self::match(
			array( self::page( '34', 'team', self::h( 't' ) ), self::page( '50', 'kontakt', self::h( 'k50' ), '34', $same ) ),
			array( self::page( '12', 'team-alt', self::h( 't2' ) ), self::page( '13', 'team', self::h( 'n' ) ), self::page( '20', 'kontakt', self::h( 'k20' ), '13', $same ) ),
			$pairs
		);
		$e = array_values( array_filter( self::of( $r, 'uncertain' ), static fn( $e ) => '50' === $e['candidates']['staging'][0]['ref'] ) );
		self::assertCount( 1, $e, (string) json_encode( $r['entries'] ) );
		self::assertSame( array( 'choice', array( '50' ), array( '20' ) ), array( $e[0]['confirm'], array_column( $e[0]['candidates']['staging'], 'ref' ), array_column( $e[0]['candidates']['live'], 'ref' ) ) );
		self::assertStringContainsString( 'the object of a pair on one side and another object on the other', $e[0]['reason'] );
		self::assertSame( array(), self::of( $r, 'equal' ) );
		// A person who chooses it pairs it unclear (AB_MCP_Sync_Pairs_Tool), also from the store.
		$stored = self::stored( $e[0] );
		self::assertFalse( AB_MCP_Sync_Proposal::alike( $stored['candidates']['staging'][0], $stored['candidates']['live'][0] ) );
		self::assertTrue( AB_MCP_Sync_Proposal::mapped_same( $stored['candidates']['staging'][0], $stored['candidates']['live'][0] ), 'The same mapped form: the case of the finding.' );
		// Under the objects of the pair on both sides, or with no parent of a pair: equal and active.
		$cases = array(
			'under the objects of the pair'   => array( array( self::page( '34', 'team', self::h( 't' ) ), self::page( '50', 'kontakt', self::h( 'k50' ), '34', $same ) ), array( self::page( '12', 'team', self::h( 't' ) ), self::page( '13', 'team-neu', self::h( 'n' ) ), self::page( '20', 'kontakt', self::h( 'k20' ), '12', $same ) ), $pairs ),
			'parents without a pair'         => array( array( self::page( '34', 'team', self::h( 't' ) ), self::page( '50', 'kontakt', self::h( 'k50' ), '34', $same ) ), array( self::page( '12', 'team', self::h( 't' ) ), self::page( '20', 'kontakt', self::h( 'k20' ), '12', $same ) ), array() ),
			'grandchildren under the pair'   => array( array( self::page( '34', 'team', self::h( 't' ) ), self::page( '40', 'a', self::h( 'a' ), '34' ), self::page( '50', 'kontakt', self::h( 'k50' ), '40', $same ) ), array( self::page( '12', 'team', self::h( 't' ) ), self::page( '41', 'a', self::h( 'a' ), '12' ), self::page( '20', 'kontakt', self::h( 'k20' ), '41', $same ) ), $pairs ),
		);
		foreach ( $cases as $case => list( $s, $l, $p ) ) {
			$r = self::match( $s, $l, $p );
			$e = array_values( array_filter( $r['entries'], static fn( $e ) => '50' === ( $e['staging']['ref'] ?? null ) ) );
			self::assertSame( array( 'equal', 'active', '20' ), array( $e[0]['category'] ?? null, $e[0]['confirm'] ?? null, $e[0]['live']['ref'] ?? null ), $case . ': ' . json_encode( $r['entries'] ) );
			self::assertTrue( AB_MCP_Sync_Proposal::alike( self::stored( $e[0] )['staging'], self::stored( $e[0] )['live'] ), $case );
		}
		$old = self::stored( $e[0] );
		unset( $old['live']['places'] );
		self::assertFalse( AB_MCP_Sync_Proposal::alike( $old['staging'], $old['live'] ), 'A side kept without its places (a proposal before them): not alike.' );
	}

	/**
	 * The identities of the objects of the two sites of the matrix of
	 * references (mapped_by(), lines()), by ID space and id: staging (s), this
	 * site (l), and this site after renames with a slug taken over (taken:
	 * the page L12 of the pair with S34 renamed «team-alt» and L13 taking
	 * «team», the term L15 of the pair with S5 renamed «news-alt» and L17
	 * taking «news», the menu L103 of the pair with S3 renamed «main-alt» and
	 * L104 taking «main», the parent item L160 of the pair with S60 renamed
	 * and L161 taking its name; Codex round 1 of part 2, A, and round 2, b).
	 * An identity is type/slug (posts; a child by its path), taxonomy/slug
	 * (terms and menus), item/name (menu items); null for an object without
	 * one (a term not read). No attachment is in an inventory before branch
	 * 10, so the featured images 99 and 98 have identities and no lines.
	 */
	private static function sites(): array {
		$s = array(
			'post' => array( 34 => 'page/team', 35 => 'page/shop', 36 => 'page/about', 40 => 'page/team/a', 60 => 'item/parent', 61 => 'item/other', 99 => 'attachment/bild.jpg' ),
			'term' => array( 2 => 'nav_menu/footer', 3 => 'nav_menu/main', 4 => null, 5 => 'category/news', 6 => 'category/sport', 7 => 'category/extra', 8 => 'category/misc', 9 => 'category/events', 10 => 'post_tag/misc', 11 => 'post_tag/events' ),
		);
		$l = array(
			'post' => array( 12 => 'page/team', 13 => 'page/shop', 14 => 'page/about', 41 => 'page/team/a', 160 => 'item/parent', 161 => 'item/other', 98 => 'attachment/bild.jpg' ),
			'term' => array( 102 => 'nav_menu/footer', 103 => 'nav_menu/main', 15 => 'category/news', 16 => 'category/sport', 17 => 'category/extra', 18 => 'category/misc', 19 => 'category/events', 110 => 'post_tag/misc', 111 => 'post_tag/events' ),
		);
		$taken = $l;
		$taken['post'][12]  = 'page/team-alt';
		$taken['post'][13]  = 'page/team';
		$taken['post'][160] = 'item/parent-alt';
		$taken['post'][161] = 'item/parent';
		$taken['term'][15]  = 'category/news-alt';
		$taken['term'][17]  = 'category/news';
		$taken['term'][103] = 'nav_menu/main-alt';
		$taken['term'][104] = 'nav_menu/main';
		return array( 's' => $s, 'l' => $l, 'taken' => $taken );
	}

	/**
	 * The mapped form of a line as the mapper of its site computes it
	 * (AB_MCP_Sync_Identity::mapped_hash(): each reference by the identity of
	 * its target on its site, a set of the canonical form in one order, none
	 * where a target has no identity), from the identities of that site
	 * (sites()). Of the site, not of the package: the form carries the
	 * identity of a target whether the package holds the line of the target
	 * or not.
	 */
	private static function mapped_by( string $text, array $refs, array $site ): ?string {
		$places = array();
		foreach ( $refs as $ref ) {
			$as = $site[ $ref['space'] ][ $ref['id'] ] ?? null;
			if ( null === $as ) {
				return null;
			}
			$places[ $ref['at'] ][] = $as;
		}
		foreach ( array_keys( $places ) as $at ) {
			if ( 'menu' === $at || str_starts_with( $at, 'term:' ) ) {
				sort( $places[ $at ], SORT_STRING );
			}
		}
		ksort( $places, SORT_STRING );
		return self::h( $text . json_encode( $places ) );
	}

	/**
	 * The lines of the objects of a site of sites(), as its inventory writes
	 * them (none for an attachment, an empty identity for a term not read, a
	 * child page under the page of the path before its slug); with $only,
	 * those of these kinds alone (a package that holds the menus but no
	 * terms or pages yet).
	 */
	private static function lines( array $site, ?array $only = null ): array {
		$out = array();
		$ids = array_flip( array_filter( $site['post'], 'is_string' ) );
		foreach ( $site['post'] as $id => $as ) {
			list( $type, $slug ) = explode( '/', $as, 2 );
			if ( 'page' === $type && ( null === $only || in_array( 'post', $only, true ) ) ) {
				$up    = dirname( $slug );
				$out[] = self::page( (string) $id, basename( $slug ), self::h( 'n' . $id ), '.' === $up ? null : (string) $ids[ 'page/' . $up ] );
			} elseif ( 'item' === $type && ( null === $only || in_array( 'menu_item', $only, true ) ) ) {
				$out[] = self::item( (string) $id, 0, $slug, 1, 'n' . $id );
			}
		}
		foreach ( $site['term'] as $id => $as ) {
			if ( null === $as ) {
				if ( null === $only || in_array( 'term', $only, true ) ) {
					$out[] = self::line( 'term', (string) $id, array(), self::h( 't' . $id ) );
				}
				continue;
			}
			list( $taxonomy, $slug ) = explode( '/', $as, 2 );
			if ( 'nav_menu' === $taxonomy ) {
				if ( null === $only || in_array( 'menu', $only, true ) ) {
					$out[] = self::menu( (string) $id, $slug );
				}
			} elseif ( null === $only || in_array( 'term', $only, true ) ) {
				$out[] = self::term( (string) $id, $slug, $taxonomy );
			}
		}
		return $out;
	}

	/** An object new on its side, of the same identity on both (the menu item in the menu its refs name), with these references and this mapped form. */
	private static function object( string $kind, string $ref, array $refs, ?string $mapped ): array {
		$over = array( 'mapped' => $mapped, 'refs' => $refs );
		if ( 'term' === $kind ) {
			return array_merge( self::term( $ref, 'neu' ), $over );
		}
		if ( 'menu_item' === $kind ) {
			$menus = array_column( array_filter( $refs, static fn( array $r ): bool => 'menu' === $r['at'] ), 'id' );
			return array_merge( self::item( $ref, (int) ( $menus[0] ?? 0 ), 'team', 1, 'i' . $ref ), $over );
		}
		return self::page( $ref, 'neu', self::h( 'p' . $ref ), null, $over, 'post' );
	}

	/**
	 * The rule of the references of an object new on both sides, in every
	 * place a reference stands (Codex rounds 1 to 3 of part 2): a target of a
	 * pair by its pair, a target without one by the identity the mapped form
	 * of the object names it by; once with the lines of the targets in the
	 * staging package, once without the terms and pages of staging (a package
	 * that holds the posts and menus whole but no terms or pages yet: nothing
	 * else complete), with the same category each time. A page under a parent
	 * is left out of the second round: without the line of its parent its
	 * path is not known whole, which is uncertain by its own rule. Equal
	 * wherever no reference contradicts a pair and the mapped forms are the
	 * same (as in 7111bc4); where a side names the object of a pair and the
	 * other, in its place, another object (also one of the same identity,
	 * which the forms alone would call the same): uncertain where the
	 * reference names the object (the parent of a post; the target, parent
	 * item or menu of a menu item), else differs; differs where the mapped
	 * forms differ.
	 */
	public function testReferencesArePairedTargetsByTheirPairsAndOthersByTheMappedFormAlsoWithoutTheirLines(): void {
		$sites   = self::sites();
		$pairs   = array( self::pair( 1, 'post', '12', '34', 'active' ), self::pair( 3, 'term', '15', '5', 'active' ), self::pair( 4, 'term', '16', '6', 'active' ), self::pair( 5, 'post', '13', '35', 'active' ), self::pair( 6, 'menu', '103', '3', 'active' ), self::pair( 7, 'menu_item', '160', '60', 'active' ), self::pair( 8, 'term', '110', '10', 'active' ) );
		$cat     = static fn( int $id ): array => self::to( 'term', $id, 'term:category' );
		$tag     = static fn( int $id ): array => self::to( 'term', $id, 'term:post_tag' );
		$parent  = static fn( int $id ): array => self::to( 'post', $id, 'post_parent' );
		$thumb   = static fn( int $id ): array => self::to( 'post', $id, 'meta:_thumbnail_id' );
		$tparent = static fn( int $id ): array => self::to( 'term', $id, 'parent' );
		$target  = static fn( int $id ): array => self::to( 'post', $id, 'meta:_menu_item_object_id' );
		$above   = static fn( int $id ): array => self::to( 'post', $id, 'meta:_menu_item_menu_item_parent' );
		$menu    = static fn( int $id ): array => self::to( 'term', $id, 'menu' );
		$ids     = static fn( int $id ): array => self::to( 'term', $id, 'meta:ids' );
		$cases   = array(
			// Equal: no reference contradicts a pair, the same mapped form.
			'categories, all of pairs'                                   => array( 'post', array( $cat( 5 ), $cat( 6 ) ), array( $cat( 15 ), $cat( 16 ) ), 'equal' ),
			'categories, none of a pair'                                 => array( 'post', array( $cat( 8 ), $cat( 9 ) ), array( $cat( 18 ), $cat( 19 ) ), 'equal' ),
			'categories mixed, in another order'                         => array( 'post', array( $cat( 9 ), $cat( 6 ), $cat( 8 ), $cat( 5 ) ), array( $cat( 15 ), $cat( 18 ), $cat( 16 ), $cat( 19 ) ), 'equal' ),
			'categories and tags, some of pairs'                         => array( 'post', array( $cat( 5 ), $cat( 9 ), $tag( 10 ), $tag( 11 ) ), array( $cat( 15 ), $cat( 19 ), $tag( 110 ), $tag( 111 ) ), 'equal' ),
			'a featured image without a pair'                            => array( 'post', array( $thumb( 99 ), $cat( 5 ) ), array( $thumb( 98 ), $cat( 15 ) ), 'equal' ),
			'under the page of a pair'                                   => array( 'post', array( $parent( 34 ) ), array( $parent( 12 ) ), 'equal' ),
			'under a page without a pair'                                => array( 'post', array( $parent( 36 ) ), array( $parent( 14 ) ), 'equal' ),
			'a grandchild under a page without a pair under the pair'    => array( 'post', array( $parent( 40 ) ), array( $parent( 41 ) ), 'equal' ),
			'a term under the term of a pair'                            => array( 'term', array( $tparent( 5 ) ), array( $tparent( 15 ) ), 'equal' ),
			'a term under a term without a pair'                         => array( 'term', array( $tparent( 8 ) ), array( $tparent( 18 ) ), 'equal' ),
			'a menu item: target, parent item and menu of pairs'         => array( 'menu_item', array( $target( 34 ), $above( 60 ), $menu( 3 ) ), array( $target( 12 ), $above( 160 ), $menu( 103 ) ), 'equal' ),
			'a menu item: target without a pair, menu of a pair'         => array( 'menu_item', array( $target( 36 ), $menu( 3 ) ), array( $target( 14 ), $menu( 103 ) ), 'equal' ),
			'a menu item: parent item and menu without a pair'           => array( 'menu_item', array( $target( 34 ), $above( 61 ), $menu( 2 ) ), array( $target( 12 ), $above( 161 ), $menu( 102 ) ), 'equal' ),
			'a list of ids in its places'                                => array( 'post', array( $ids( 5 ), $ids( 9 ) ), array( $ids( 15 ), $ids( 19 ) ), 'equal' ),
			// Uncertain: what names the object is of a pair there and another object here, the same mapped form (Codex round 2 of part 2, b).
			'under the page of a pair there, here the page that took its slug'       => array( 'post', array( $parent( 34 ) ), array( $parent( 13 ) ), 'uncertain', 'taken' ),
			'a menu item: its target of a pair there, here the page that took it'    => array( 'menu_item', array( $target( 34 ), $menu( 2 ) ), array( $target( 13 ), $menu( 102 ) ), 'uncertain', 'taken' ),
			'a menu item: its menu of a pair there, here the menu that took it'      => array( 'menu_item', array( $target( 36 ), $menu( 3 ) ), array( $target( 14 ), $menu( 104 ) ), 'uncertain', 'taken' ),
			'a menu item: its parent item of a pair there, here the item that took it' => array( 'menu_item', array( $target( 36 ), $above( 60 ), $menu( 2 ) ), array( $target( 14 ), $above( 161 ), $menu( 102 ) ), 'uncertain', 'taken' ),
			// Differs: another reference contradicts a pair, the same mapped form.
			'in the category of a pair there, here the term that took its slug'      => array( 'post', array( $cat( 5 ), $cat( 9 ) ), array( $cat( 17 ), $cat( 19 ) ), 'differs', 'taken' ),
			'in a list, the term of a pair there, here the term that took its slug'  => array( 'post', array( $ids( 5 ) ), array( $ids( 17 ) ), 'differs', 'taken' ),
			'a term under the term of a pair there, here the term that took its slug' => array( 'term', array( $tparent( 5 ) ), array( $tparent( 17 ) ), 'differs', 'taken' ),
			// Differs by the mapped forms (as in 7111bc4): other targets, a target without identity, a reference on one side only.
			'other categories without a pair'                            => array( 'post', array( $cat( 5 ), $cat( 9 ) ), array( $cat( 15 ), $cat( 18 ) ), 'differs' ),
			'a category of a pair more on one side'                      => array( 'post', array( $cat( 5 ), $cat( 6 ), $cat( 9 ) ), array( $cat( 15 ), $cat( 19 ) ), 'differs' ),
			'a target without an identity on its site'                   => array( 'post', array( $cat( 4 ), $cat( 5 ) ), array( $cat( 15 ), $cat( 19 ) ), 'differs' ),
			'a list in another order'                                    => array( 'post', array( $ids( 5 ), $ids( 6 ) ), array( $ids( 16 ), $ids( 15 ) ), 'differs' ),
			'a featured image on staging only'                           => array( 'post', array( $thumb( 34 ) ), array(), 'differs' ),
		);
		$found   = static function ( array $r, string $ref ): array {
			foreach ( $r['entries'] as $e ) {
				if ( $ref === ( $e['staging']['ref'] ?? null ) || array( $ref ) === array_column( $e['candidates']['staging'] ?? array(), 'ref' ) ) {
					return array( $e['category'], $e['confirm'] ?? null, $e['live']['ref'] ?? ( array_column( $e['candidates']['live'] ?? array(), 'ref' )[0] ?? null ) );
				}
			}
			return array( null, null, null );
		};
		foreach ( array( 'the lines of the targets in the package' => null, 'no term or page of staging in the package' => array( 'menu', 'menu_item' ) ) as $package => $only ) {
			foreach ( $cases as $case => $c ) {
				list( $kind, $s_refs, $l_refs, $category ) = $c;
				if ( null !== $only && array( 'post_parent' ) === array_column( $s_refs, 'at' ) ) {
					continue;
				}
				$live = $sites[ $c[4] ?? 'l' ];
				$s    = self::mapped_by( 'same', $s_refs, $sites['s'] );
				$l    = self::mapped_by( 'same', $l_refs, $live );
				if ( isset( $c[4] ) ) {
					self::assertSame( $s, $l, "$case: the same mapped form on both, the case of the finding." );
				}
				$r = self::match(
					array_merge( self::lines( $sites['s'], $only ), array( self::object( $kind, '50', $s_refs, $s ) ) ),
					array_merge( self::lines( $live ), array( self::object( $kind, '20', $l_refs, $l ) ) ),
					$pairs,
					null === $only ? null : static fn( string $kind ): bool => in_array( $kind, $only, true )
				);
				self::assertSame( array( $category, 'equal' === $category ? 'active' : ( 'differs' === $category ? 'unclear' : 'choice' ), '20' ), $found( $r, '50' ), "$case ($package): " . json_encode( $r['entries'] ) );
			}
		}
	}

	/**
	 * A post in a term a credential holds on the staging site (its line
	 * reduced, Codex B9): compared by its mapped form like any other, which
	 * the mapper of its site computed before the line was held; the name of
	 * the term reaches neither the entry nor what the comparison keeps.
	 */
	public function testAPostInATermACredentialHoldsIsComparedByItsFormAndShowsNoName(): void {
		$sites = self::sites();
		$cat   = static fn( int $id ): array => self::to( 'term', $id, 'term:category' );
		$sites['s']['term'][9] = 'category/secret-events';
		$sites['l']['term'][29] = 'category/secret-events';
		$held  = array( 'held' => 'credential_name' ) + self::term( '9', 'secret-events' );
		$r     = self::match(
			array( self::term( '5', 'news' ), $held, self::object( 'post', '60', array( $cat( 5 ), $cat( 9 ) ), self::mapped_by( 'same', array( $cat( 5 ), $cat( 9 ) ), $sites['s'] ) ) ),
			array( self::term( '15', 'news' ), self::term( '29', 'secret-events' ), self::object( 'post', '70', array( $cat( 15 ), $cat( 29 ) ), self::mapped_by( 'same', array( $cat( 15 ), $cat( 29 ) ), $sites['l'] ) ) ),
			array( self::pair( 3, 'term', '15', '5', 'active' ) )
		);
		$e     = array_values( array_filter( $r['entries'], static fn( $e ) => '60' === ( $e['staging']['ref'] ?? null ) ) );
		self::assertSame( array( 'equal', 'active' ), array( $e[0]['category'] ?? null, $e[0]['confirm'] ?? null ), (string) json_encode( $r['entries'] ) );
		self::assertStringNotContainsString( 'secret-events', (string) json_encode( $e[0] ) );
	}

	/**
	 * What the comparison keeps of the references of a line (places(), as the
	 * store keeps a side for a choice) and when two of them agree
	 * (refs_agree()): by place, each target as the id of its pair or '', from
	 * the pairs of its side alone (no line of a target); a place without a
	 * target of a pair is not kept (the mapped forms compare it); a set in any
	 * order, a list in its places; a place with a pair on either side only is
	 * not alike.
	 */
	public function testThePlacesOfALineAndWhereTheyAgree(): void {
		$paired = AB_MCP_Sync_Proposal::paired( array( self::pair( 1, 'post', '12', '34', 'active' ), self::pair( 3, 'term', '15', '5', 'active' ), self::pair( 6, 'menu', '103', '3', 'active' ) ) );
		$line   = array( 'refs' => array( self::to( 'term', 9, 'term:category' ), self::to( 'term', 5, 'term:category' ), self::to( 'post', 99, 'meta:_thumbnail_id' ), self::to( 'post', 34, 'post_parent' ), self::to( 'post', 36, 'post_content' ), self::to( 'term', 3, 'menu' ) ) );
		self::assertSame(
			array( "term\0term:category" => array( 'pairs' => array( '', '3' ) ), "post\0post_parent" => array( 'pairs' => array( '1' ) ), "term\0menu" => array( 'pairs' => array( '6' ) ) ),
			AB_MCP_Sync_Proposal::places( $line, $paired['s'] ),
			'No line of a target, the pairs tell; the image and the content without a pair are not kept.'
		);
		self::assertSame( array( "post\0post_parent" => array( 'pairs' => array( '1' ) ) ), AB_MCP_Sync_Proposal::places( array( 'refs' => array( self::to( 'post', 12, 'post_parent' ) ) ), $paired['l'] ), 'By the objects of this site.' );
		self::assertSame( array(), AB_MCP_Sync_Proposal::places( array( 'refs' => array( self::to( 'post', 12, 'post_parent' ) ) ), $paired['s'] ), 'An id of the other side is no pair here.' );
		$set  = static fn( string $at, array $pairs ): array => array( "term\0" . $at => array( 'pairs' => $pairs ) );
		$agree = array( AB_MCP_Sync_Proposal::class, 'refs_agree' );
		self::assertTrue( $agree( $set( 'term:category', array( '3', '' ) ), $set( 'term:category', array( '', '3' ) ) ), 'A set in any order.' );
		self::assertTrue( $agree( $set( 'menu', array( '6', '' ) ), $set( 'menu', array( '', '6' ) ) ), 'The menus of an item, a set.' );
		self::assertFalse( $agree( $set( 'meta:ids', array( '3', '' ) ), $set( 'meta:ids', array( '', '3' ) ) ), 'A list keeps its places.' );
		self::assertTrue( $agree( $set( 'meta:ids', array( '3', '' ) ), $set( 'meta:ids', array( '3', '' ) ) ) );
		self::assertFalse( $agree( $set( 'term:category', array( '3', '' ) ), $set( 'term:category', array( '', '' ) ) ), 'A target of a pair against one without.' );
		self::assertFalse( $agree( $set( 'term:category', array( '3' ) ), array() ), 'A place with a pair on staging only.' );
		self::assertFalse( $agree( array(), $set( 'term:category', array( '3' ) ) ), 'A place with a pair on this site only.' );
		self::assertTrue( $agree( array(), array() ) );
	}

	/**
	 * Codex round 3 of part 2, B: a package of the staging site holds its
	 * posts whole, but no term lines yet. A post in «news» of a pair (S5 → L15)
	 * and in «events» without a pair on both sides (S9, L19) has the same
	 * mapped form on both (the export of its site named each term by its
	 * identity): equal, as in 7111bc4. A target without a pair is compared by
	 * the mapped form of the object that names it, never by a line of its own;
	 * only a target of a pair is compared by its pair, which the table of
	 * pairs tells without a line.
	 */
	public function testATargetWithoutAPairIsComparedByTheMappedFormAlsoWhereItsLineIsNotAtHand(): void {
		$cat   = static fn( int $id ): array => self::to( 'term', $id, 'term:category' );
		$post  = static fn( string $ref, array $refs ): array => self::page( $ref, 'neu', self::h( 'p' . $ref ), null, array( 'mapped' => self::h( 'same' ), 'refs' => $refs ), 'post' );
		$pairs = array( self::pair( 3, 'term', '15', '5', 'active' ) );
		$here  = array( self::term( '15', 'news' ), self::term( '19', 'events' ) );
		$cases = array(
			'no term lines of staging in the package' => array(),
			'the lines of its terms as well'           => array( self::term( '5', 'news' ), self::term( '9', 'events' ) ),
		);
		foreach ( $cases as $case => $terms ) {
			$r = self::match(
				array_merge( $terms, array( $post( '60', array( $cat( 5 ), $cat( 9 ) ) ) ) ),
				array_merge( $here, array( $post( '70', array( $cat( 15 ), $cat( 19 ) ) ) ) ),
				$pairs,
				static fn( string $kind ): bool => 'term' !== $kind || array() !== $terms
			);
			$e = array_values( array_filter( $r['entries'], static fn( $e ) => '60' === ( $e['staging']['ref'] ?? null ) ) );
			self::assertSame( array( 'equal', 'active', '70' ), array( $e[0]['category'] ?? null, $e[0]['confirm'] ?? null, $e[0]['live']['ref'] ?? null ), $case . ': ' . json_encode( $r['entries'] ) );
		}
	}

	public function testAMenuItemOfATargetOrMenuOfAPairOnOneSideAndAnotherOnTheOtherIsUncertain(): void {
		$with  = static fn( array $line, array $refs ): array => array( 'refs' => $refs ) + $line;
		$item  = static fn( string $ref, int $menu, int $target ): array => $with( self::item( $ref, $menu, 'team', 1, 'i' ), array( self::to( 'post', $target, 'meta:_menu_item_object_id' ), self::to( 'term', $menu, 'menu' ) ) );
		$page  = self::pair( 1, 'post', '12', '34', 'active' );
		$menu  = self::pair( 3, 'menu', '15', '5', 'active' );
		// The menu 5 «main» of the pair 3 with 15, here «main» as well, or
		// renamed «main-alt» where 16 took «main»; the page S34 of the pair 1
		// with L12, the target of the item by the identity «team» on both.
		$cases = array(
			'its target of a pair there, here another page' => array( $item( '151', 15, 13 ), 'main', array( $menu, $page ), 'uncertain' ),
			'its menu of a pair there, here another menu'   => array( $item( '151', 16, 13 ), 'main-alt', array( $menu ), 'uncertain' ),
			'the target and menu of the pairs'              => array( $item( '151', 15, 12 ), 'main', array( $menu, $page ), 'equal' ),
			'a target without a pair'                       => array( $item( '151', 15, 13 ), 'main', array( $menu ), 'equal' ),
			'a menu and target without a pair'              => array( $item( '151', 15, 13 ), 'main', array(), 'equal' ),
			'its parent item of a pair there, here another' => array( $with( $item( '151', 15, 13 ), array_merge( $item( '151', 15, 13 )['refs'], array( self::to( 'post', 161, 'meta:_menu_item_menu_item_parent' ) ) ) ), 'main', array( $menu, self::pair( 5, 'menu_item', '160', '60', 'active' ) ), 'uncertain' ),
			'its parent item of the pair'                   => array( $with( $item( '151', 15, 13 ), array_merge( $item( '151', 15, 13 )['refs'], array( self::to( 'post', 160, 'meta:_menu_item_menu_item_parent' ) ) ) ), 'main', array( $menu, self::pair( 5, 'menu_item', '160', '60', 'active' ) ), 'equal' ),
		);
		foreach ( $cases as $case => list( $l, $slug, $pairs, $category ) ) {
			$here = 'main' === $slug ? array( self::menu( '15', 'main' ) ) : array( self::menu( '15', $slug ), self::menu( '16', 'main' ) );
			$s    = $with( $item( '51', 5, 34 ), array_merge( $item( '51', 5, 34 )['refs'], array( self::to( 'post', 60, 'meta:_menu_item_menu_item_parent' ) ) ) );
			$r    = self::match( array( self::menu( '5', 'main' ), $s ), array_merge( $here, array( $l ) ), $pairs );
			$e = array_values( array_filter( $r['entries'], static fn( $e ) => 'menu_item' === $e['kind'] ) );
			self::assertCount( 1, $e, $case . ': ' . json_encode( $r['entries'] ) );
			self::assertSame( $category, $e[0]['category'], $case );
		}
	}

	public function testAnEqualMappedFormThereOnceOnEachSideOfAPathPairsTwins(): void {
		// Each form there once on each side, every twin of both sites at hand: a pair (Codex round 3, B3; round 5).
		$r = self::match(
			array( self::twin( self::page( '34', 'dup', self::h( 'a' ) ) ), self::twin( self::page( '35', 'dup', self::h( 'b' ) ) ) ),
			array( self::twin( self::page( '12', 'dup', self::h( 'b' ) ) ), self::twin( self::page( '13', 'dup', self::h( 'a' ) ) ) )
		);
		self::assertSame( array( '34' => '13', '35' => '12' ), self::paired( $r, array( 'equal' ) ) );
		self::assertSame( array( 'equal', 'equal' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( array( 'active', 'active' ), array_column( $r['entries'], 'confirm' ) );
		// Other ids of the images in their meta: the mapped forms differ, the second ones pair.
		$r = self::match(
			array( self::twin( self::page( '34', 'dup', self::h( 'a' ), null, array( 'mapped' => self::h( 's1' ), 'mapped_possible' => self::h( 'p1' ) ) ) ), self::twin( self::page( '35', 'dup', self::h( 'b' ), null, array( 'mapped' => self::h( 's2' ), 'mapped_possible' => self::h( 'p2' ) ) ) ) ),
			array( self::twin( self::page( '12', 'dup', self::h( 'c' ), null, array( 'mapped' => self::h( 'l2' ), 'mapped_possible' => self::h( 'p2' ) ) ) ), self::twin( self::page( '13', 'dup', self::h( 'd' ), null, array( 'mapped' => self::h( 'l1' ), 'mapped_possible' => self::h( 'p1' ) ) ) ) )
		);
		self::assertSame( array( '34' => '13', '35' => '12' ), self::paired( $r, array( 'equal' ) ), 'By the second form as well.' );
		self::assertSame( 0, $r['counts']['uncertain'] );
	}

	/* -------------------------------------- twins of a site (Codex round 4) */

	/** A line its site names a twin of another object of the site: how many objects of it have its identity. */
	private static function twin( array $line, int $twins = 2 ): array {
		return array( 'twin' => $twins ) + $line;
	}

	public function testATwinOfItsSiteIsUncertainAlsoWhereOnlyOneIsAtHand(): void {
		foreach ( array( 's', 'l', 'both' ) as $side ) {
			$s = self::page( '34', 'dup', self::h( 'x' ) );
			$l = self::page( '12', 'dup', self::h( 'x' ) );
			$r = self::match( array( 'l' === $side ? $s : self::twin( $s ) ), array( 's' === $side ? $l : self::twin( $l ) ) );
			self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ), $side . ': the package holds one of the twins, never equal.' );
			self::assertStringContainsString( 'also where a package holds only one of them', $r['entries'][0]['reason'] );
		}
		// A twin without a counterpart on its path is only on its side.
		$r = self::match( array( self::twin( self::page( '34', 'dup', self::h( 'x' ) ) ) ), array() );
		self::assertSame( array( 'staging_only' ), array_column( $r['entries'], 'category' ) );
		$r = self::match( array( self::twin( self::page( '34', 'dup', self::h( 'x' ) ) ) ), array( self::page( '11', 'team', self::h( 't' ) ), self::page( '12', 'dup', self::h( 'x' ), '11' ) ) );
		self::assertSame( 1, $r['counts']['uncertain'], 'On another path: still the person\'s choice.' );
		self::assertSame( 0, $r['counts']['equal'] );
	}

	public function testTheRestOfTwinsAfterAChosenPairIsUncertainNeverEqual(): void {
		// 34 and 12 were paired by a choice; 35 and 13 are what is left of the twins.
		$r = self::match(
			array( self::twin( self::page( '34', 'dup', self::h( 'a' ) ) ), self::twin( self::page( '35', 'dup', self::h( 'b' ) ) ) ),
			array( self::twin( self::page( '12', 'dup', self::h( 'a' ) ) ), self::twin( self::page( '13', 'dup', self::h( 'b' ) ) ) ),
			array( self::pair( 1, 'post', '12', '34', 'active' ) )
		);
		self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( array( '35' ), array_column( $r['entries'][0]['candidates']['staging'], 'ref' ) );
		self::assertSame( array( '13' ), array_column( $r['entries'][0]['candidates']['live'], 'ref' ) );
		// Menu items, terms and menus alike.
		$r = self::match(
			array( self::menu( '5', 'main' ), self::twin( self::item( '51', 5, 'shop', 1, 'a' ) ), self::twin( self::line( 'term', '7', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 't' ) ) ) ),
			array( self::twin( self::menu( '15', 'main' ) ), self::item( '151', 15, 'shop', 1, 'a' ), self::line( 'term', '17', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 't' ) ) )
		);
		self::assertSame( array( 'uncertain', 'uncertain', 'uncertain' ), array_column( $r['entries'], 'category' ) );
		$item = array_values( array_filter( $r['entries'], static fn( $e ) => 'menu_item' === $e['kind'] ) )[0];
		self::assertStringContainsString( 'only its place tells them apart', $item['reason'] );
	}

	public function testAPageUnderATwinIsUncertainAlsoWhereOnlyOneTwinIsAtHand(): void {
		$s = array( self::twin( self::page( '8', 'team', self::h( 't' ) ) ), self::page( '34', 'kontakt', self::h( 'x' ), '8' ) );
		$l = array( self::page( '18', 'team', self::h( 't' ) ), self::page( '12', 'kontakt', self::h( 'x' ), '18' ) );
		$r = self::match( $s, $l );
		$kontakt = array_values( array_filter( $r['entries'], static fn( $e ) => 'kontakt' === ( $e['candidates']['staging'][0]['identity']['post_name'] ?? null ) ) );
		self::assertCount( 1, $kontakt );
		self::assertSame( 'uncertain', $kontakt[0]['category'] );
		self::assertSame( array( array( '?' ) ), array_column( $kontakt[0]['candidates']['staging'], 'path' ), 'Its parent names nothing on staging.' );
		self::assertStringContainsString( 'path of its parents is not known whole', $kontakt[0]['reason'] );
	}

	/* --------------------------- twins paired by their form (Codex round 5) */

	public function testAPublishedPageAndADraftOfItsSlugUnchangedAreTwoEqualPairs(): void {
		// WordPress keeps the slug of a draft as it is (wp_unique_post_slug()): a copy of a page.
		$published = static fn( string $ref, string $content = 'published' ): array => self::twin( self::page( $ref, 'kontakt', self::h( $content ) ) );
		$draft     = static fn( string $ref, string $content = 'draft' ): array => self::twin( self::page( $ref, 'kontakt', self::h( $content ) ) );
		$r         = self::match( array( $published( '34' ), $draft( '35' ) ), array( $draft( '13' ), $published( '12' ) ) );
		self::assertSame( array( '34' => '12', '35' => '13' ), self::paired( $r, array( 'equal' ) ), 'Both unchanged: two pairs, each by its content.' );
		self::assertSame( array( 'equal', 'equal' ), array_column( $r['entries'], 'category' ) );
		// One of them changed on staging: the other is equal, the changed one only a choice.
		foreach ( array( 'the draft' => array( $published( '34' ), $draft( '35', 'draft changed' ), '34', '12', '35', '13' ), 'the page' => array( $published( '34', 'published changed' ), $draft( '35' ), '35', '13', '34', '12' ) ) as $what => list( $p, $d, $same_s, $same_l, $changed_s, $changed_l ) ) {
			$r = self::match( array( $p, $d ), array( $published( '12' ), $draft( '13' ) ) );
			self::assertSame( array( 'equal', 'uncertain' ), array_column( $r['entries'], 'category' ), $what );
			self::assertSame( array( $same_s => $same_l ), self::paired( $r, array( 'equal', 'differs' ) ), $what . ': the unchanged one is a pair, the changed one never differs (no pair by exclusion).' );
			$uncertain = self::of( $r, 'uncertain' )[0];
			self::assertSame( array( $changed_s ), array_column( $uncertain['candidates']['staging'], 'ref' ), $what );
			self::assertSame( array( $changed_l ), array_column( $uncertain['candidates']['live'], 'ref' ), $what );
			self::assertSame( 'choice', $uncertain['confirm'] );
			self::assertStringContainsString( 'Another object of its site has the same identity', $uncertain['reason'] );
		}
		// The draft on one site only: the page is a pair, the draft has no counterpart.
		$r = self::match( array( $published( '34' ), $draft( '35' ) ), array( self::page( '12', 'kontakt', self::h( 'published' ) ) ) );
		self::assertSame( array( 'equal', 'staging_only' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( '35', $r['entries'][1]['staging']['ref'] );
		$r = self::match( array( self::page( '34', 'kontakt', self::h( 'published' ) ) ), array( $published( '12' ), $draft( '13' ) ) );
		self::assertSame( array( 'equal', 'live_only' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( '13', $r['entries'][1]['live']['ref'] );
	}

	public function testTwinsAreNotPairedByTheirFormWhereNotAllOfThemAreAtHand(): void {
		$s = array( self::twin( self::page( '34', 'dup', self::h( 'a' ) ) ), self::twin( self::page( '35', 'dup', self::h( 'b' ) ) ) );
		$l = array( self::twin( self::page( '12', 'dup', self::h( 'a' ) ) ), self::twin( self::page( '13', 'dup', self::h( 'b' ) ) ) );
		$cases = array(
			'a package with one of the twins'          => array( array( $s[0] ), $l, array(), array( '34' ), array( '12', '13' ) ),
			'one of the twins here held'               => array( $s, array( $l[0], self::held( 'post', '13', 'credential_name' ) ), array(), array( '34', '35' ), array( '12' ) ),
			'the rest after a choice, the same form'   => array( $s, $l, array( self::pair( 1, 'post', '12', '34', 'active' ) ), array( '35' ), array( '13' ) ),
			'the rest after a choice, another form'    => array( $s, array( $l[0], self::twin( self::page( '13', 'dup', self::h( 'c' ) ) ) ), array( self::pair( 1, 'post', '12', '34', 'active' ) ), array( '35' ), array( '13' ) ),
			'three on staging, a package with two'     => array( array( self::twin( $s[0], 3 ), self::twin( $s[1], 3 ) ), $l, array(), array( '34', '35' ), array( '12', '13' ) ),
			'a twin that does not say how many'        => array( array( array( 'twin' => true ) + $s[0], array( 'twin' => true ) + $s[1] ), $l, array(), array( '34', '35' ), array( '12', '13' ) ),
			'twice on a path, no twin of its site'     => array( array( self::page( '34', 'dup', self::h( 'a' ) ), self::page( '35', 'dup', self::h( 'b' ) ) ), $l, array(), array( '34', '35' ), array( '12', '13' ) ),
		);
		foreach ( $cases as $what => list( $staging, $live, $pairs, $on_s, $on_l ) ) {
			$r = self::match( $staging, $live, $pairs );
			self::assertSame( 0, $r['counts']['equal'] + $r['counts']['differs'], $what . ': never paired by its form, nor by exclusion.' );
			$uncertain = self::of( $r, 'uncertain' );
			self::assertCount( 1, $uncertain, $what );
			self::assertSame( $on_s, array_column( $uncertain[0]['candidates']['staging'], 'ref' ), $what );
			self::assertSame( $on_l, array_column( $uncertain[0]['candidates']['live'], 'ref' ), $what );
		}
	}

	public function testTwinsOfOneFormAreUncertain(): void {
		$cases = array(
			'one form on all four'          => array( array( 'x', 'x' ), array( 'x', 'x' ) ),
			'one form twice on staging'     => array( array( 'x', 'x' ), array( 'x', 'y' ) ),
			'one form twice here'           => array( array( 'x', 'y' ), array( 'x', 'x' ) ),
		);
		foreach ( $cases as $what => list( $s, $l ) ) {
			$r = self::match(
				array( self::twin( self::page( '34', 'dup', self::h( $s[0] ) ) ), self::twin( self::page( '35', 'dup', self::h( $s[1] ) ) ) ),
				array( self::twin( self::page( '12', 'dup', self::h( $l[0] ) ) ), self::twin( self::page( '13', 'dup', self::h( $l[1] ) ) ) )
			);
			self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ), $what );
			self::assertCount( 2, $r['entries'][0]['candidates']['staging'], $what );
			self::assertCount( 2, $r['entries'][0]['candidates']['live'], $what );
		}
		// Two twins of one form on staging, the one page of that form here.
		$r = self::match( array( self::twin( self::page( '34', 'dup', self::h( 'x' ) ) ), self::twin( self::page( '35', 'dup', self::h( 'x' ) ) ) ), array( self::page( '12', 'dup', self::h( 'x' ) ) ) );
		self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ) );
		// The second form twice: no pair by it either.
		$possible = static fn( string $ref, string $m, string $p ): array => self::twin( self::page( $ref, 'dup', self::h( $ref ), null, array( 'mapped' => self::h( $m ), 'mapped_possible' => self::h( $p ) ) ) );
		$r        = self::match( array( $possible( '34', 's1', 'p' ), $possible( '35', 's2', 'p' ) ), array( $possible( '12', 'l1', 'p' ), $possible( '13', 'l2', 'q' ) ) );
		self::assertSame( array( 'uncertain' ), array_column( $r['entries'], 'category' ) );
	}

	public function testTwinsNotReadWholeAreNotPairedByTheirForm(): void {
		foreach ( array( 's', 'l' ) as $side ) {
			$s = array( self::twin( self::page( '34', 'dup', self::h( 'a' ), null, 's' === $side ? array( 'complete' => false ) : array() ) ), self::twin( self::page( '35', 'dup', self::h( 'b' ) ) ) );
			$l = array( self::twin( self::page( '12', 'dup', self::h( 'a' ), null, 'l' === $side ? array( 'complete' => false ) : array() ) ), self::twin( self::page( '13', 'dup', self::h( 'b' ) ) ) );
			$r = self::match( $s, $l );
			self::assertSame( array( '35' => '13' ), self::paired( $r, array( 'equal', 'differs' ) ), $side . ': the form not read to its end says nothing.' );
			self::assertSame( array( 'equal', 'uncertain' ), array_column( $r['entries'], 'category' ), $side );
			self::assertSame( array( '34' ), array_column( self::of( $r, 'uncertain' )[0]['candidates']['staging'], 'ref' ), $side );
		}
	}

	public function testMenuItemTwinsStayUncertainAlsoWhereAllAreAtHand(): void {
		// Every form there once on each side, both items of each menu at hand: only their place tells them apart (Codex round 3, A2).
		$r = self::match(
			array( self::menu( '5', 'main' ), self::twin( self::item( '51', 5, 'shop', 1, 'a' ) ), self::twin( self::item( '52', 5, 'shop', 2, 'b' ) ) ),
			array( self::menu( '15', 'main' ), self::twin( self::item( '151', 15, 'shop', 1, 'a' ) ), self::twin( self::item( '152', 15, 'shop', 2, 'b' ) ) )
		);
		self::assertSame( array( '5' => '15' ), self::paired( $r ), 'Only the menu.' );
		self::assertCount( 1, self::of( $r, 'uncertain' ) );
		self::assertStringContainsString( 'only its place tells them apart', self::of( $r, 'uncertain' )[0]['reason'] );
		// Terms and menus of one slug twice are paired by their form.
		$r = self::match(
			array( self::twin( self::line( 'term', '7', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 'n1' ) ) ), self::twin( self::line( 'term', '8', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 'n2' ) ) ), self::twin( self::menu( '5', 'main' ) ), self::twin( self::line( 'menu', '6', array( 'slug' => 'main', 'taxonomy' => 'nav_menu' ), self::h( 'other' ) ) ) ),
			array( self::twin( self::line( 'term', '17', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 'n2' ) ) ), self::twin( self::line( 'term', '18', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 'n1' ) ) ), self::menu( '15', 'main' ) )
		);
		self::assertSame( array( '5' => '15', '7' => '18', '8' => '17' ), self::paired( $r, array( 'equal' ) ) );
	}

	/* --------------------------------- the whole parent (Codex round 4) */

	public function testAParentOfAnotherLanguageTypeOrThemeIsAnotherPath(): void {
		$with = static fn( array $line, array $identity ): array => array( 'identity' => $identity + $line['identity'] ) + $line;
		$cases = array(
			'language' => array( array( 'language' => 'de' ), array( 'language' => 'en' ) ),
			'type'     => array( array(), array( 'post_type' => 'team_page' ) ),
			'theme'    => array( array( 'theme' => 'one' ), array( 'theme' => 'two' ) ),
		);
		foreach ( $cases as $what => list( $here, $there ) ) {
			// kontakt under team/de on staging, under team/en here (the same content).
			$s = array( $with( self::page( '8', 'team', self::h( 't' ) ), $here ), self::page( '34', 'kontakt', self::h( 'x' ), '8' ) );
			$l = array( $with( self::page( '18', 'team', self::h( 't' ) ), $there ), self::page( '12', 'kontakt', self::h( 'x' ), '18' ) );
			$r = self::match( $s, $l );
			$kontakt = array_values( array_filter( $r['entries'], static fn( $e ) => 'kontakt' === ( ( $e['candidates']['staging'][0] ?? $e['staging'] ?? array() )['identity']['post_name'] ?? null ) ) );
			self::assertCount( 1, $kontakt, $what );
			self::assertSame( 'uncertain', $kontakt[0]['category'], $what . ': another path, never differs.' );
			self::assertStringContainsString( 'a parent of another type, theme or language', $kontakt[0]['reason'], $what );
			self::assertSame( array( array( 'team' ) ), array_column( $kontakt[0]['candidates']['live'], 'path' ), $what . ': the path shown by its slugs.' );
			$s[0] = $with( $s[0], $there );
			self::assertSame( 'equal', self::entry_of( self::match( $s, $l ), '34' )['category'], $what . ': the same whole parent, the same path.' );
		}
	}

	/** The entry of a staging ref. */
	private static function entry_of( array $result, string $ref ): array {
		foreach ( $result['entries'] as $entry ) {
			if ( $ref === ( $entry['staging']['ref'] ?? null ) ) {
				return $entry;
			}
		}
		self::fail( 'No entry of ' . $ref );
	}

	public function testTranslationsSharingASlugAreTwoObjects(): void {
		$lang = static fn( string $l ): array => array( 'identity' => array( 'post_name' => 'kontakt', 'post_type' => 'page', 'theme' => '', 'language' => $l ) );
		$r    = self::match(
			array( array_merge( self::page( '34', 'kontakt', self::h( 'de' ) ), $lang( 'de' ) ), array_merge( self::page( '35', 'kontakt', self::h( 'en' ) ), $lang( 'en' ) ), self::page( '36', 'team', self::h( 't' ), '35' ) ),
			array( array_merge( self::page( '12', 'kontakt', self::h( 'en-changed' ) ), $lang( 'en' ) ), array_merge( self::page( '13', 'kontakt', self::h( 'de' ) ), $lang( 'de' ) ), self::page( '14', 'team', self::h( 't' ), '12' ) )
		);
		self::assertSame( array( '34' => '13', '35' => '12', '36' => '14' ), self::paired( $r ), 'By language (Codex round 3, B2); the page under one of them has a whole path.' );
		self::assertSame( 0, $r['counts']['uncertain'] );
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
		self::assertStringContainsString( 'it was read across packages', $e['reason'] );
		self::assertStringContainsString( 'a kind read across packages stays so in this version', $e['reason'], 'The way is true: a new export does not help a size.' );
	}

	/* ---------------------------------------------------------------- held */

	public function testAHeldObjectIsShownByTheHashOfItsRefOnly(): void {
		$secret = 'api_key=CANARY-ONLY';
		$r      = self::match( array( self::held( 'option', $secret, 'credential_name' ) ), array( self::held( 'option', $secret, 'credential_name' ) ) );
		self::assertSame( 2, $r['counts']['held'] );
		self::assertSame( 0, $r['counts']['equal'] );
		self::assertSame( array( array( 'held' => 'credential_name', 'ref_sha256' => self::h( '"' . $secret . '"' ) ) ), $r['entries'][0]['live'] ?: $r['entries'][0]['staging'] );
		self::assertStringNotContainsString( 'CANARY', (string) json_encode( $r ) );
		self::assertSame( array( 'staging' => 1, 'live' => 1 ), $r['held'] );
		self::assertSame( array(), $r['subtypes'], 'A held line names no subtype.' );
	}

	public function testAHeldLineIsReducedToItsKindTheHashOfItsRefAndItsCode(): void {
		self::assertSame(
			array(
				'kind'       => 'post',
				'ref_sha256' => self::h( '"12"' ),
				'held'       => 'credential_field',
			),
			AB_MCP_Sync_Proposal::reduced( self::page( '12', '{"password":"CANARY-ONLY"}', self::h( 'x' ) ), 'credential_field' )
		);
	}

	public function testAHeldObjectStandsAloneAndHoldsNoGroup(): void {
		$r = self::match( array( self::held( 'option', 'x_token', 'credential_field' ) ), array( self::option( 'x_token', self::h( 'a' ) ) ) );
		self::assertSame( array( 'live_only', 'held' ), array_column( $r['entries'], 'category' ) );
		$long = str_repeat( 'o', 200 );
		self::assertSame( array( 'held' ), array_column( self::match( array( self::held( 'option', $long, 'credential_field' ) ), array() )['entries'], 'category' ), 'A ref no pair holds, held: held.' );
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

	public function testAPairOfAHeldObjectTakesItAway(): void {
		$r = self::match( array( self::held( 'option', 'api_key', 'credential_name' ) ), array( self::held( 'option', 'api_key', 'credential_name' ) ), array( self::pair( 7, 'option', 'api_key', 'api_key', 'active' ) ) );
		self::assertSame( 1, $r['paired'] );
		self::assertSame( array(), $r['entries'] );
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

	public function testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash(): void {
		foreach ( array( 's', 'l' ) as $side ) {
			$r = self::match(
				array( 's' === $side ? self::held( 'post', '34', 'credential_field' ) : self::page( '34', 'about', self::h( 'x' ) ) ),
				array( 'l' === $side ? self::held( 'post', '12', 'credential_field' ) : self::page( '12', 'about', self::h( 'x' ) ) ),
				array( self::pair( 7, 'post', '12', '34', 'unclear' ) )
			);
			$e = self::of( $r, 'realign' )[0];
			self::assertNull( $e['confirm'], $side );
			self::assertSame( array( 'held' => 'credential_field', 'ref_sha256' => self::h( '"' . ( 's' === $side ? '34' : '12' ) . '"' ) ), 's' === $side ? $e['staging'] : $e['live'], $side . ': named by the hash of its ref.' );
		}
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

	/** A menu item line with its target and parent by identity. */
	private static function item( string $ref, int $menu, string $target, int $position, string $hash, ?string $parent = 'none' ): array {
		return self::line(
			'menu_item',
			$ref,
			array(
				'menus'    => array( $menu ),
				'object'   => 'page',
				'parent'   => null === $parent ? null : self::h( $parent ),
				'position' => $position,
				'target'   => '' === $target ? null : self::h( $target ),
				'type'     => 'post_type',
			),
			self::h( $hash )
		);
	}

	private static function menu( string $ref, string $slug ): array {
		return self::line( 'menu', $ref, array( 'slug' => $slug, 'taxonomy' => 'nav_menu' ), self::h( 'm' . $slug ) );
	}

	public function testAMenuItemMatchesByTheSlugOfItsMenuNotItsId(): void {
		// The same target and hash in both menus: only the slug of the menu tells them apart.
		$r = self::match( array( self::menu( '5', 'main' ), self::menu( '6', 'footer' ), self::item( '50', 5, 'about', 1, 'i' ), self::item( '60', 6, 'about', 1, 'i' ) ), array( self::menu( '15', 'footer' ), self::menu( '16', 'main' ), self::item( '150', 16, 'about', 1, 'i' ), self::item( '160', 15, 'about', 1, 'i' ) ) );
		self::assertSame( array( '5' => '16', '50' => '150', '6' => '15', '60' => '160' ), self::paired( $r, array( 'equal' ) ) );
	}

	public function testAMenuSortedAnewPairsEachItemWithItsTarget(): void {
		// Here: 1 «Über uns», 2 «Kontakt»; on staging the other way round.
		$r = self::match(
			array( self::menu( '5', 'main' ), self::item( '51', 5, 'kontakt', 1, 'k1' ), self::item( '52', 5, 'ueber-uns', 2, 'u2' ) ),
			array( self::menu( '15', 'main' ), self::item( '151', 15, 'ueber-uns', 1, 'u1' ), self::item( '152', 15, 'kontakt', 2, 'k2' ) )
		);
		self::assertSame( array( '5' => '15', '51' => '152', '52' => '151' ), self::paired( $r ), 'Kontakt with Kontakt, whatever its position.' );
	}

	public function testTheSameTargetTwiceUnderOneParentIsUncertainEvenWhereTheFormsTellThemApart(): void {
		$r = self::match(
			array( self::menu( '5', 'main' ), self::item( '51', 5, 'shop', 1, 'a' ), self::item( '52', 5, 'shop', 2, 'b' ) ),
			array( self::menu( '15', 'main' ), self::item( '151', 15, 'shop', 1, 'c' ), self::item( '152', 15, 'shop', 2, 'd' ) )
		);
		self::assertSame( 1, $r['counts']['uncertain'] );
		// The same forms on both sides: only the place tells them apart, which is no identity (Codex round 3, 1).
		$r = self::match(
			array( self::menu( '5', 'main' ), self::item( '51', 5, 'shop', 2, 'a' ), self::item( '52', 5, 'shop', 3, 'b' ) ),
			array( self::menu( '15', 'main' ), self::item( '151', 15, 'shop', 2, 'a' ), self::item( '152', 15, 'shop', 3, 'b' ) )
		);
		self::assertSame( 1, $r['counts']['uncertain'] );
		self::assertSame( 1, $r['counts']['equal'], 'Only the menu is equal.' );
		self::assertStringContainsString( 'only its place tells them apart', self::of( $r, 'uncertain' )[0]['reason'] );
		$r = self::match(
			array( self::menu( '5', 'main' ), self::item( '51', 5, 'shop', 1, 'a', 'top' ), self::item( '52', 5, 'shop', 2, 'b', 'other' ) ),
			array( self::menu( '15', 'main' ), self::item( '151', 15, 'shop', 1, 'c', 'top' ), self::item( '152', 15, 'shop', 2, 'd', 'other' ) )
		);
		self::assertSame( array( '5' => '15', '51' => '151', '52' => '152' ), self::paired( $r ), 'Under another parent item: another identity.' );
	}

	public function testAMenuItemWithoutTheIdentityOfItsTargetOrParentIsUncertain(): void {
		foreach ( array( 'target' => array( '', 'none' ), 'parent' => array( 'about', null ) ) as $what => list( $target, $parent ) ) {
			$r = self::match( array( self::menu( '5', 'main' ), self::item( '51', 5, $target, 1, 'x', $parent ) ), array( self::menu( '15', 'main' ), self::item( '151', 15, $target, 1, 'x', $parent ) ) );
			self::assertSame( 1, $r['counts']['uncertain'], $what );
			self::assertStringContainsString( 'has no identity of its own on its site', self::of( $r, 'uncertain' )[0]['reason'], $what );
		}
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
		$item = array( 'menus' => array( 1 ), 'object' => 'page', 'parent' => 'p', 'position' => 1, 'target' => 't', 'type' => 'post_type' );
		self::assertSame( $k( 'menu_item', $item ), $k( 'menu_item', array( 'position' => 2 ) + $item ), 'Not by its position.' );
		self::assertNotSame( $k( 'menu_item', $item ), $k( 'menu_item', array( 'target' => 'u' ) + $item ) );
		self::assertNotSame( $k( 'menu_item', $item ), $k( 'menu_item', array( 'parent' => 'q' ) + $item ) );
	}

	/* ------------------------------------------------------------ order */

	public function testTheEntriesAreNumberedInTheOrderOfTheCategories(): void {
		$r = self::match(
			array( self::page( '1', 'only-s', self::h( 'a' ) ), self::page( '2', 'diff', self::h( 'b' ) ), self::page( '3', 'same', self::h( 'c' ) ), self::page( '4', '', self::h( 'd' ) ) ),
			array( self::page( '11', 'only-l', self::h( 'd' ) ), self::page( '12', 'diff', self::h( 'e' ) ), self::page( '13', 'same', self::h( 'c' ) ), self::page( '14', '', self::h( 'd' ) ) )
		);
		self::assertSame( array( 'equal', 'differs', 'uncertain', 'staging_only', 'live_only' ), array_column( $r['entries'], 'category' ) );
		self::assertSame( array( 1, 2, 3, 4, 5 ), array_column( $r['entries'], 'n' ) );
		self::assertArrayNotHasKey( 'sort', $r['entries'][0] );
		self::assertSame( array( 'equal', 'differs', 'uncertain', 'realign', 'staging_only', 'live_only', 'not_compared', 'held' ), AB_MCP_Sync_Proposal::CATEGORIES );
		self::assertSame( array( 'uncertain' ), AB_MCP_Sync_Proposal::BY_CHOICE );
	}

	public function testAnAnswerShowsNoHashMappedFormOrSnapshot(): void {
		$possible = array( 'mapped_possible' => self::h( 'possible' ) );
		$r        = self::match( array( self::page( '34', 'about', self::h( 'x' ), null, $possible ) ), array( self::page( '12', 'about', self::h( 'x' ), null, $possible ) ), array( self::pair( 7, 'post', '13', '35', 'unclear' ) ) );
		foreach ( $r['entries'] as $entry ) {
			$shown = (string) json_encode( AB_MCP_Sync_Proposal::shown( $entry ) );
			self::assertStringNotContainsString( self::h( 'x' ), $shown );
			self::assertStringNotContainsString( '"snapshot"', $shown );
			self::assertStringNotContainsString( '"mapped', $shown );
			self::assertStringNotContainsString( self::h( 'possible' ), $shown );
		}
		self::assertStringContainsString( '"places"', (string) json_encode( $r['entries'] ), 'Kept for a choice.' );
		self::assertStringNotContainsString( '"places"', (string) json_encode( array_map( array( AB_MCP_Sync_Proposal::class, 'shown' ), $r['entries'] ) ), 'Not shown.' );
		$amb = self::match( array( self::page( '1', 'd', self::h( 'p' ), null, $possible ), self::page( '2', 'd', self::h( 'q' ) ) ), array( self::page( '3', 'd', self::h( 'r' ) ), self::page( '4', 'd', self::h( 's' ) ) ) )['entries'][0];
		self::assertSame( 'uncertain', $amb['category'] );
		$shown = (string) json_encode( AB_MCP_Sync_Proposal::shown( $amb ) );
		self::assertStringContainsString( '"places"', (string) json_encode( $amb ) );
		self::assertStringNotContainsString( '"places"', $shown );
		self::assertStringNotContainsString( self::h( 'p' ), $shown );
		self::assertStringNotContainsString( '"mapped', $shown );
		self::assertStringNotContainsString( self::h( 'possible' ), $shown );
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

	public function testTheListOfHeldObjectsReducesTheirLines(): void {
		$ref = 'api_key=x';
		$all = AB_MCP_Sync_Proposal::compose( array( self::taken( array( self::option( $ref, self::h( 'v' ) ), self::option( 'blogname', self::h( 'o' ) ) ), array(), array( array( 'option', self::h( '"' . $ref . '"' ), 'credential_name' ), array( 'post', self::h( '"blogname"' ), 'credential_name' ) ) ) ) );
		self::assertSame(
			array(
				'kind'       => 'option',
				'ref_sha256' => self::h( '"' . $ref . '"' ),
				'held'       => 'credential_name',
			),
			$all['lines'][0],
			'Reduced: no name, no hash.'
		);
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

	public function testTheTargetOfACanonChangeIsTheLiveHashOnlyWhereTheMappedFormsAreTheSame(): void {
		$pair = self::pair( 7, 'post', '12', '34', 'active', array( 'canon' => 'canon/9' ) );
		$s    = self::page( '34', 'a', self::h( 's' ), null, array( 'mapped' => self::h( 'm' ) ) );
		$l    = self::page( '12', 'a', self::h( 'l' ), null, array( 'mapped' => self::h( 'm' ) ) );
		self::assertSame( array( 'live' => self::h( 'l' ), 'staging' => self::h( 's' ), 'target' => self::h( 'l' ) ), AB_MCP_Sync_Proposal::canon_forms( $pair, $s, $l ), 'Other ids, the same mapped form.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( array( 'override' => array( 'post_status' => 'draft' ) ) + $pair, $s, $l )['target'], 'An override changes the target.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, array( 'mapped' => self::h( 'other' ) ) + $s, $l )['target'], 'Another mapped form.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, array( 'mapped' => null ) + $s, $l )['target'], 'A target without identity.' );
		self::assertSame( self::h( 'l' ), AB_MCP_Sync_Proposal::canon_forms( $pair, array( 'mapped' => self::h( 'other' ), 'mapped_possible' => self::h( 'p' ) ) + $s, array( 'mapped_possible' => self::h( 'p' ) ) + $l )['target'], 'The same with its possible references by identity.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, null, $l )['staging'] );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, $s, array( 'complete' => false ) + $l )['live'], 'Not read whole: no form.' );
		self::assertNull( AB_MCP_Sync_Proposal::canon_forms( $pair, $s, array( 'held' => 'credential_field' ) + $l )['live'], 'Held: no form.' );
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
