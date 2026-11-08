<?php
/**
 * The report as far as a test without a database shows it (AB_MCP_Sync_Decision,
 * AB_MCP_Sync_Report; Z8 decisions 1, 14, 16, 21 and 22; branch 6, part 2):
 *
 * - the decision function: a test for each row of the table of an active
 *   pair (ten, each with its words as literals), of the table without a
 *   pair (three) and for each rule of the order (0 residual and rest
 *   removed, a rest in the trash still a rest, 1 unclear, 2 taken over as
 *   deleted in its three cases, a live object in the trash still deleted, 3
 *   active, 4 no pair); row 10 removed on both, in the trash on both or on
 *   one; changed alike only where both sides name the objects of one pair
 *   (Codex's case with the real index of identities; the matrix of every
 *   place a reference stands, a target of a pair by its pair, one without
 *   by the mapped form, with and without the lines of the targets in the
 *   staging package: rounds 1 to 3 of part 2), and new on both sides under
 *   the object of a pair here
 *   and another there uncertain; an unclear pair a credential holds a side
 *   of not compared, without a choice; incomplete never deleted, the trash a deletion, an auto-draft no
 *   content, a name holding a credential here not deleted and neither side
 *   by its name, an override compared against its target and named;
 * - what is locked, with its reason and stage: the locks of the lines, a
 *   deletion, bringing a deleted object back, creating Additional CSS (or
 *   updating CSS this site does not link), creating a term with meta, a menu
 *   with an item removed on staging, a widget area with an instance removed
 *   on staging (against the instances of its base);
 * - the graph of the necessary references: an edge to a target created in
 *   the same run, a target named by its pair, waits for a locked, unclear,
 *   unpaired or unknown target, mutual references locked (two objects, one
 *   referring to itself), waiting passed on along a chain;
 * - the report: the version of WordPress, the check before (a network, the
 *   pair, the anchor), the packages, the report_hash, the tool as it is
 *   registered.
 *
 * The report over two WordPress sites (packages, the inventory in parts, the
 * store, the tool over MCP): tests/integration/SyncReportTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Staging_Gate;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Decision;
use AB_MCP_Sync_Identity;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Report;
use AB_MCP_Tool_Labels;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/class-tool-labels.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/../includes/sync/class-sync-report.php';
require_once __DIR__ . '/SyncTrustSites.php';
require_once __DIR__ . '/SyncIdentitySites.php';

final class SyncReportTest extends TestCase {

	use SyncTrustSites;

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'], $GLOBALS['wpdb'] );
		$GLOBALS['ab_test_multisite'] = false;
	}

	private static function h( string $text ): string {
		return hash( 'sha256', $text );
	}

	/** A line as AB_MCP_Sync_Export::line() writes one; its mapped form its hash unless told. */
	private static function line( string $kind, string $ref, array $identity, ?string $hash, array $over = array() ): array {
		return array_merge(
			array(
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
			),
			$over
		);
	}

	/** A page with its slug, its hash a hash of $state. */
	private static function page( string $ref, string $name, string $state, array $over = array() ): array {
		return self::line(
			'post',
			$ref,
			array(
				'post_name' => $name,
				'post_type' => 'page',
				'theme'     => '',
			),
			self::h( $state ),
			array_merge( array( 'post_status' => 'publish', 'meta' => 0 ), $over )
		);
	}

	/** A reference to a post or term of the same side. */
	private static function ref( string $space, int $id, string $at = 'post_parent' ): array {
		return array(
			'at'       => $at,
			'id'       => $id,
			'space'    => $space,
			'strength' => 'strong',
		);
	}

	/** A pair as AB_MCP_Sync_Pairs::decode() gives it, with the bases s0 and l0. */
	private static function pair( int $id, string $kind, string $live, string $staging, string $state = 'active', array $over = array() ): array {
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
				'base_live'    => self::h( 'l0' ),
				'base_staging' => self::h( 's0' ),
				'base_extra'   => null,
				'base_time'    => 100,
				'base_origin'  => 'proposal',
				'last_run'     => null,
				'updated'      => 100,
			),
			$over
		);
	}

	/** The report of these lines and pairs, the staging side complete unless told. */
	private static function report( array $s, array $l, array $pairs = array(), ?callable $complete = null, array $b9 = array() ): array {
		return AB_MCP_Sync_Decision::report( $s, $l, $pairs, $complete ?? static fn(): bool => true, $b9 );
	}

	/** The entry of the object of a side by its ref (a pair's entry by either ref). */
	private static function of( array $report, string $kind, string $ref ): array {
		foreach ( $report['entries'] as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				if ( $kind === $entry['kind'] && isset( $entry[ $side ]['ref'] ) && $ref === $entry[ $side ]['ref'] ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $kind $ref." );
	}

	/** The one entry of a pair. */
	private static function decided( array $s, array $l, array $pair, ?callable $complete = null, array $b9 = array() ): array {
		$report = self::report( $s, $l, array( $pair ), $complete, $b9 );
		foreach ( $report['entries'] as $entry ) {
			if ( null !== $entry['pair'] && $pair['id'] === $entry['pair']['id'] ) {
				return $entry;
			}
		}
		self::fail( 'No entry for the pair.' );
	}

	private static function keys( array $entry ): array {
		return array_column( $entry['locks'] ?? array(), 'key' );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/* ------------------------------------------- the table of an active pair */

	/** The ten rows: S, L, row, result, action, alike; the states of both sides and the words as the report says them. */
	public static function rows(): array {
		return array(
			'row 1: unchanged, unchanged'          => array( 's0', 'l0', 1, 'equal', 'none', false, 'unchanged', 'unchanged', 'Equal on both sides: nothing to take over.' ),
			'row 2: changed, unchanged'            => array( 's1', 'l0', 2, 'changed_on_staging', 'take_over', false, 'changed', 'unchanged', 'Changed on staging, unchanged on this site: taken over.' ),
			'row 3: deleted, unchanged'            => array( null, 'l0', 3, 'deleted_on_staging', 'locked', false, 'deleted', 'unchanged', 'Deleted on staging – taken over from Z8d: nothing is deleted in this version, and no status stands in for a deletion. Delete it here by hand in wp-admin where it should go.' ),
			'row 4: unchanged, changed'            => array( 's0', 'l1', 4, 'changed_live', 'keep', false, 'unchanged', 'changed', 'Changed on this site, unchanged on staging: kept and shown.' ),
			'row 5: changed, changed, other forms' => array( 's1', 'l1', 5, 'conflict', 'choose', false, 'changed', 'changed', 'Changed on both sides, differently: a conflict. A person chooses: staging wins, keep this site, or leave it open.' ),
			'row 6: changed, changed, alike'       => array( 's1', 'l1', 6, 'changed_alike', 'none', true, 'changed', 'changed', 'Changed on both sides alike: nothing to take over; the bases of the pair move on with the next run.' ),
			'row 7: deleted, changed'              => array( null, 'l1', 7, 'deleted_against_changed_live', 'keep', false, 'deleted', 'changed', 'Deleted on staging, changed on this site: a conflict, kept on this site by default; the deletion is taken over from Z8d.' ),
			'row 8: unchanged, deleted'            => array( 's0', null, 8, 'deleted_live', 'none', false, 'unchanged', 'deleted', 'Deleted on this site, unchanged on staging: nothing to do, the deletion holds.' ),
			'row 9: changed, deleted'              => array( 's1', null, 9, 'deleted_live_changed_staging', 'keep', false, 'changed', 'deleted', 'Deleted on this site, changed on staging: a conflict, deleted here by default; bringing it back from staging follows with Z8d.' ),
			'row 10: deleted, deleted'             => array( null, null, 10, 'deleted_both', 'none', false, 'deleted', 'deleted', 'Deleted on both sides, removed on both (neither is in the trash): nothing to do; wp_site_pairs action=discard ends the pair.' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'rows' )]
	public function testEachRowOfTheTableOfAnActivePair( ?string $s, ?string $l, int $row, string $result, string $action, bool $alike, string $staging_state, string $live_state, string $words ): void {
		$staging = null === $s ? array() : array( self::page( '34', 'about', $s, $alike ? array( 'mapped' => self::h( 'same' ) ) : array() ) );
		$live    = null === $l ? array() : array( self::page( '12', 'about', $l, $alike ? array( 'mapped' => self::h( 'same' ) ) : array() ) );
		$entry   = self::decided( $staging, $live, self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( $result, '3', $row, $action ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), (string) json_encode( $entry ) );
		self::assertSame( array( 'staging' => $staging_state, 'live' => $live_state ), $entry['sides'] );
		self::assertSame( $words, $entry['words'] );
	}

	public function testTheTableHasOneRowForEachStateOfBothSidesAndTwoForChangedOnBoth(): void {
		self::assertCount( 10, AB_MCP_Sync_Decision::TABLE );
		$states = array( 'unchanged', 'changed', 'deleted' );
		foreach ( $states as $s ) {
			foreach ( $states as $l ) {
				$row = AB_MCP_Sync_Decision::row( $s, $l, false );
				self::assertIsInt( $row, "$s, $l" );
				self::assertSame( array( $s, $l ), array_slice( AB_MCP_Sync_Decision::TABLE[ $row ], 0, 2 ) );
			}
		}
		self::assertSame( 5, AB_MCP_Sync_Decision::row( 'changed', 'changed', false ) );
		self::assertSame( 6, AB_MCP_Sync_Decision::row( 'changed', 'changed', true ) );
		self::assertSame( 1, AB_MCP_Sync_Decision::row( 'unchanged', 'unchanged', true ), 'Alike counts for changed on both only.' );
		self::assertNull( AB_MCP_Sync_Decision::row( 'not_compared', 'unchanged', false ) );
	}

	public function testADeletionOnStagingIsShownWithoutAWayAndTakenOverFromZ8d(): void {
		$entry = self::decided( array(), array( self::page( '12', 'about', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'deleted_on_staging', 'locked' ), array( $entry['result'], $entry['action'] ) );
		self::assertSame( array( '*:delete' ), self::keys( $entry ) );
		self::assertSame( 'Z8d', $entry['locks'][0]['stage'] );
		self::assertStringContainsString( 'taken over from Z8d', $entry['words'] );
		self::assertArrayNotHasKey( 'choices', $entry );
		// Against a change here: kept by default, the deletion locked.
		$entry = self::decided( array(), array( self::page( '12', 'about', 'l1' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'keep', array( '*:delete' ) ), array( $entry['action'], self::keys( $entry ) ) );
	}

	public function testAConflictOffersItsThreeChoices(): void {
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array( self::page( '12', 'about', 'l1' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'staging_wins', 'keep_live', 'leave_open' ), $entry['choices'] );
	}

	public function testChangedHereAndDeletedThereNamesTheWayBackAsLocked(): void {
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array(), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'deleted_live_changed_staging', 'keep', array( '*:restore_deleted' ) ), array( $entry['result'], $entry['action'], self::keys( $entry ) ) );
		self::assertSame( 'Z8d', $entry['locks'][0]['stage'] );
	}

	public function testRowTenEndsThePairOnlyWhereBothObjectsAreRemoved(): void {
		$trash = static fn( string $ref, string $state ): array => self::page( $ref, 'about__trashed', $state, array( 'post_status' => 'trash' ) );
		$cases = array(
			'both removed'                  => array( array(), array(), 'deleted_both', 'Deleted on both sides, removed on both (neither is in the trash): nothing to do; wp_site_pairs action=discard ends the pair.' ),
			'both in the trash'             => array( array( $trash( '34', 's9' ) ), array( $trash( '12', 'l9' ) ), 'deleted_both_trash', 'Deleted on both sides, in the trash on at least one: nothing to do. The pair is then taken over as deleted (the deletion holds), which follows with Z8d; until then it stays as it is, and it is not ended: only a pair whose objects are removed on both sides is, so an object in the trash can still come back to its pair.' ),
			'removed here, trash there'     => array( array( $trash( '34', 's9' ) ), array(), 'deleted_both_trash', 'Deleted on both sides, in the trash on at least one: nothing to do. The pair is then taken over as deleted (the deletion holds), which follows with Z8d; until then it stays as it is, and it is not ended: only a pair whose objects are removed on both sides is, so an object in the trash can still come back to its pair.' ),
			'in the trash here, removed there' => array( array(), array( $trash( '12', 'l9' ) ), 'deleted_both_trash', 'Deleted on both sides, in the trash on at least one: nothing to do. The pair is then taken over as deleted (the deletion holds), which follows with Z8d; until then it stays as it is, and it is not ended: only a pair whose objects are removed on both sides is, so an object in the trash can still come back to its pair.' ),
		);
		foreach ( $cases as $case => list( $staging, $live, $result, $words ) ) {
			$entry = self::decided( $staging, $live, self::pair( 7, 'post', '12', '34' ) );
			self::assertSame( array( $result, '3', 10, 'none' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), $case );
			self::assertSame( array( 'staging' => 'deleted', 'live' => 'deleted' ), $entry['sides'], $case );
			self::assertSame( $words, $entry['words'], $case );
		}
	}

	/**
	 * The mapped form of a page «kontakt» under a parent, on a site of these
	 * pages (id => slug, parent), by the index of identities of that site
	 * (AB_MCP_Sync_Identity, as the inventory builds it).
	 */
	private static function mapped_under( array $pages, int $parent ): string {
		$site = new SyncIdentitySite();
		foreach ( $pages as $id => list( $slug, $of ) ) {
			$site->post( $id, 'page', $slug, $of );
		}
		$GLOBALS['wpdb'] = new SyncIdentityFakeWpdb( $site );
		$ctx             = AB_MCP_Sync_Canon::context( array( 'https://live.example', 'https://staging.example' ) );
		AB_MCP_Sync_Identity::reset( $ctx );
		self::assertTrue( AB_MCP_Sync_Identity::build() );
		$hash = AB_MCP_Sync_Identity::mapped_hash( array( 'row' => array( 'post_title' => 'Kontakt', 'post_parent' => AB_MCP_Sync_Canon::ref( 'post', $parent ) ) ), $ctx );
		AB_MCP_Sync_Identity::reset();
		self::assertIsString( $hash );
		return $hash;
	}

	/**
	 * Codex round 1 of part 2, A: the pair S34 → L12 began as «team»; here
	 * L12 is renamed «team-alt» and another page L13 takes the slug «team».
	 * A paired page moved on both sides, on staging under S34, here under
	 * L13, has the same mapped form on both (its parent by the identity
	 * «team»), but staging names L12 through the pair and this site L13.
	 */
	public function testChangedAlikeOnlyWhereBothSidesNameTheObjectsOfOnePair(): void {
		$staging = array( 34 => array( 'team', 0 ), 50 => array( 'kontakt', 34 ) );
		$s       = self::mapped_under( $staging, 34 );
		$l       = self::mapped_under( array( 12 => array( 'team-alt', 0 ), 13 => array( 'team', 0 ), 20 => array( 'kontakt', 13 ) ), 13 );
		self::assertSame( $s, $l, 'The same mapped form on both sides: the case of the finding.' );
		$pairs = array( self::pair( 1, 'post', '12', '34' ), self::pair( 2, 'post', '20', '50' ) );
		$r     = self::report(
			array( self::page( '34', 'team', 's0' ), self::page( '50', 'kontakt', 's1', array( 'mapped' => $s, 'refs' => array( self::ref( 'post', 34 ) ) ) ) ),
			array( self::page( '12', 'team-alt', 'l1' ), self::page( '13', 'team', 'n' ), self::page( '20', 'kontakt', 'l1', array( 'mapped' => $l, 'refs' => array( self::ref( 'post', 13 ) ) ) ) ),
			$pairs
		);
		$entry = self::of( $r, 'post', '50' );
		self::assertSame( array( 'conflict', 5, 'choose' ), array( $entry['result'], $entry['row'], $entry['action'] ), 'Staging names L12 through the pair, this site L13: not alike.' );
		// Both under the objects of the pair: alike, row 6.
		$l = self::mapped_under( array( 12 => array( 'team', 0 ), 13 => array( 'team-neu', 0 ), 20 => array( 'kontakt', 12 ) ), 12 );
		self::assertSame( $s, $l );
		$r     = self::report(
			array( self::page( '34', 'team', 's0' ), self::page( '50', 'kontakt', 's1', array( 'mapped' => $s, 'refs' => array( self::ref( 'post', 34 ) ) ) ) ),
			array( self::page( '12', 'team', 'l0' ), self::page( '13', 'team-neu', 'n' ), self::page( '20', 'kontakt', 'l1', array( 'mapped' => $l, 'refs' => array( self::ref( 'post', 12 ) ) ) ) ),
			$pairs
		);
		$entry = self::of( $r, 'post', '50' );
		self::assertSame( array( 'changed_alike', 6, 'none' ), array( $entry['result'], $entry['row'], $entry['action'] ) );
		// The same two cases from a staging package without the line of the
		// parent S34 (nothing complete): the pair of the parent tells it.
		$l13 = self::mapped_under( array( 12 => array( 'team-alt', 0 ), 13 => array( 'team', 0 ), 20 => array( 'kontakt', 13 ) ), 13 );
		foreach ( array( 13 => array( $l13, 'conflict', 5 ), 12 => array( $l, 'changed_alike', 6 ) ) as $under => list( $form, $result, $row ) ) {
			$here  = 13 === $under ? array( self::page( '12', 'team-alt', 'l1' ), self::page( '13', 'team', 'n' ) ) : array( self::page( '12', 'team', 'l0' ), self::page( '13', 'team-neu', 'n' ) );
			$r     = self::report(
				array( self::page( '50', 'kontakt', 's1', array( 'mapped' => $s, 'refs' => array( self::ref( 'post', 34 ) ) ) ) ),
				array_merge( $here, array( self::page( '20', 'kontakt', 'l1', array( 'mapped' => $form, 'refs' => array( self::ref( 'post', $under ) ) ) ) ) ),
				$pairs,
				static fn(): bool => false
			);
			$entry = self::of( $r, 'post', '50' );
			self::assertSame( array( $result, $row ), array( $entry['result'], $entry['row'] ), "Under L$under, without the line of S34." );
		}
	}

	/**
	 * Codex round 2 of part 2, b: the same case for pages new on both sides,
	 * without a pair of their own. The proposal the report shows is uncertain
	 * (a person chooses), not equal; under the objects of the pair on both
	 * sides it stays equal.
	 */
	public function testNewOnBothSidesUnderTheObjectOfAPairHereAndAnotherThereIsUncertain(): void {
		$s     = self::mapped_under( array( 34 => array( 'team', 0 ), 50 => array( 'kontakt', 34 ) ), 34 );
		$l     = self::mapped_under( array( 12 => array( 'team-alt', 0 ), 13 => array( 'team', 0 ), 20 => array( 'kontakt', 13 ) ), 13 );
		$r     = self::report(
			array( self::page( '34', 'team', 's0' ), self::page( '50', 'kontakt', 'k', array( 'mapped' => $s, 'refs' => array( self::ref( 'post', 34 ) ) ) ) ),
			array( self::page( '12', 'team-alt', 'l1' ), self::page( '13', 'team', 'n' ), self::page( '20', 'kontakt', 'k', array( 'mapped' => $l, 'refs' => array( self::ref( 'post', 13 ) ) ) ) ),
			array( self::pair( 1, 'post', '12', '34' ) )
		);
		$entry = array_values( array_filter( $r['entries'], static fn( $e ) => array( '50' ) === array_column( $e['candidates']['staging'] ?? array(), 'ref' ) ) );
		self::assertCount( 1, $entry, (string) json_encode( $r['entries'] ) );
		self::assertSame( array( 'new_both', 'pair', 'uncertain' ), array( $entry[0]['result'], $entry[0]['action'], $entry[0]['proposal'] ) );
		self::assertSame( array( '20' ), array_column( $entry[0]['candidates']['live'], 'ref' ) );
		self::assertStringNotContainsString( '"places"', (string) json_encode( $r ), 'What the comparison keeps of the references is not shown.' );
		// Under the objects of the pair on both sides: equal.
		$l = self::mapped_under( array( 12 => array( 'team', 0 ), 13 => array( 'team-neu', 0 ), 20 => array( 'kontakt', 12 ) ), 12 );
		$r = self::report(
			array( self::page( '34', 'team', 's0' ), self::page( '50', 'kontakt', 'k', array( 'mapped' => $s, 'refs' => array( self::ref( 'post', 34 ) ) ) ) ),
			array( self::page( '12', 'team', 'l0' ), self::page( '13', 'team-neu', 'n' ), self::page( '20', 'kontakt', 'k', array( 'mapped' => $l, 'refs' => array( self::ref( 'post', 12 ) ) ) ) ),
			array( self::pair( 1, 'post', '12', '34' ) )
		);
		self::assertSame( array( 'new_both', 'equal' ), array( self::of( $r, 'post', '50' )['result'], self::of( $r, 'post', '50' )['proposal'] ) );
	}

	/** A term of a taxonomy, its identity its slug. */
	private static function term( string $ref, string $slug, string $taxonomy = 'category' ): array {
		return self::line( 'term', $ref, array( 'slug' => $slug, 'taxonomy' => $taxonomy ), self::h( 't' . $ref ), array( 'meta' => 0 ) );
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

	/** The lines of the objects of a site of sites(), as its inventory writes them (none for an attachment, an empty identity for a term not read). */
	private static function lines( array $site ): array {
		$out = array();
		foreach ( $site['post'] as $id => $as ) {
			list( $type, $slug ) = explode( '/', $as, 2 );
			if ( 'page' === $type ) {
				$out[] = self::page( (string) $id, basename( $slug ), 'n' . $id );
			} elseif ( 'item' === $type ) {
				$out[] = self::item( (string) $id, 0, $slug, 'n' . $id );
			}
		}
		foreach ( $site['term'] as $id => $as ) {
			if ( null === $as ) {
				$out[] = self::line( 'term', (string) $id, array(), self::h( 't' . $id ), array( 'meta' => 0 ) );
				continue;
			}
			list( $taxonomy, $slug ) = explode( '/', $as, 2 );
			$out[] = 'nav_menu' === $taxonomy ? self::menu( (string) $id, $slug, 'n' . $id ) : self::term( (string) $id, $slug, $taxonomy );
		}
		return $out;
	}

	/** An object of a pair under test, changed on its side (its hash of $state), with these references and this mapped form. */
	private static function object( string $kind, string $ref, array $refs, ?string $mapped, string $state ): array {
		$over = array( 'mapped' => $mapped, 'refs' => $refs );
		if ( 'term' === $kind ) {
			return array_merge( self::term( $ref, 'kontakt' ), $over );
		}
		if ( 'menu_item' === $kind ) {
			return array_merge( self::item( $ref, 0, 'team', $state ), $over );
		}
		return self::page( $ref, 'kontakt', $state, $over );
	}

	/**
	 * The rule of the references of a paired object changed on both sides, in
	 * every place a reference stands (Codex rounds 1 to 3 of part 2): a
	 * target of a pair by its pair, a target without one by the identity the
	 * mapped form of the object names it by; once with the lines of the
	 * targets in the staging package, once without any of them (a package
	 * that holds the posts whole but no terms, pages or menus yet: nothing
	 * complete), with the same row each time. Row 6 wherever no reference
	 * contradicts a pair and the mapped forms are the same (as in 7111bc4);
	 * row 5 where a side names the object of a pair and the other, in its
	 * place, another object (also one of the same identity, which the forms
	 * alone would call the same), or where the mapped forms differ.
	 */
	public function testReferencesArePairedTargetsByTheirPairsAndOthersByTheMappedFormAlsoWithoutTheirLines(): void {
		$sites   = self::sites();
		$pairs   = array( self::pair( 1, 'post', '12', '34' ), self::pair( 3, 'term', '15', '5' ), self::pair( 4, 'term', '16', '6' ), self::pair( 5, 'post', '13', '35' ), self::pair( 6, 'menu', '103', '3' ), self::pair( 7, 'menu_item', '160', '60' ), self::pair( 8, 'term', '110', '10' ) );
		$cat     = static fn( int $id ): array => self::ref( 'term', $id, 'term:category' );
		$tag     = static fn( int $id ): array => self::ref( 'term', $id, 'term:post_tag' );
		$parent  = static fn( int $id ): array => self::ref( 'post', $id );
		$thumb   = static fn( int $id ): array => self::ref( 'post', $id, 'meta:_thumbnail_id' );
		$tparent = static fn( int $id ): array => self::ref( 'term', $id, 'parent' );
		$target  = static fn( int $id ): array => self::ref( 'post', $id, 'meta:_menu_item_object_id' );
		$above   = static fn( int $id ): array => self::ref( 'post', $id, 'meta:_menu_item_menu_item_parent' );
		$menu    = static fn( int $id ): array => self::ref( 'term', $id, 'menu' );
		$ids     = static fn( int $id ): array => self::ref( 'term', $id, 'meta:ids' );
		$cases   = array(
			// Alike: no reference contradicts a pair, the same mapped form.
			'categories, all of pairs'                                   => array( 'post', array( $cat( 5 ), $cat( 6 ) ), array( $cat( 15 ), $cat( 16 ) ), 6 ),
			'categories, none of a pair'                                 => array( 'post', array( $cat( 8 ), $cat( 9 ) ), array( $cat( 18 ), $cat( 19 ) ), 6 ),
			'categories mixed, in another order'                         => array( 'post', array( $cat( 9 ), $cat( 6 ), $cat( 8 ), $cat( 5 ) ), array( $cat( 15 ), $cat( 18 ), $cat( 16 ), $cat( 19 ) ), 6 ),
			'categories and tags, some of pairs'                         => array( 'post', array( $cat( 5 ), $cat( 9 ), $tag( 10 ), $tag( 11 ) ), array( $cat( 15 ), $cat( 19 ), $tag( 110 ), $tag( 111 ) ), 6 ),
			'a featured image without a pair'                            => array( 'post', array( $thumb( 99 ), $cat( 5 ) ), array( $thumb( 98 ), $cat( 15 ) ), 6 ),
			'under the page of a pair'                                   => array( 'post', array( $parent( 34 ) ), array( $parent( 12 ) ), 6 ),
			'under a page without a pair'                                => array( 'post', array( $parent( 36 ) ), array( $parent( 14 ) ), 6 ),
			'a grandchild under a page without a pair under the pair'    => array( 'post', array( $parent( 40 ) ), array( $parent( 41 ) ), 6 ),
			'a term under the term of a pair'                            => array( 'term', array( $tparent( 5 ) ), array( $tparent( 15 ) ), 6 ),
			'a term under a term without a pair'                         => array( 'term', array( $tparent( 8 ) ), array( $tparent( 18 ) ), 6 ),
			'a menu item: target, parent item and menu of pairs'         => array( 'menu_item', array( $target( 34 ), $above( 60 ), $menu( 3 ) ), array( $target( 12 ), $above( 160 ), $menu( 103 ) ), 6 ),
			'a menu item: target without a pair, menu of a pair'         => array( 'menu_item', array( $target( 36 ), $menu( 3 ) ), array( $target( 14 ), $menu( 103 ) ), 6 ),
			'a menu item: parent item and menu without a pair'           => array( 'menu_item', array( $target( 34 ), $above( 61 ), $menu( 2 ) ), array( $target( 12 ), $above( 161 ), $menu( 102 ) ), 6 ),
			'a menu item in two menus, one of a pair'                    => array( 'menu_item', array( $target( 36 ), $menu( 2 ), $menu( 3 ) ), array( $target( 14 ), $menu( 103 ), $menu( 102 ) ), 6 ),
			'a list of ids in its places'                                => array( 'post', array( $ids( 5 ), $ids( 9 ) ), array( $ids( 15 ), $ids( 19 ) ), 6 ),
			// Not alike: a reference contradicts a pair, the same mapped form on both (Codex round 1 of part 2, A; round 2, b).
			'under the page of a pair there, here the page that took its slug'       => array( 'post', array( $parent( 34 ) ), array( $parent( 13 ) ), 5, 'taken' ),
			'in the category of a pair there, here the term that took its slug'      => array( 'post', array( $cat( 5 ), $cat( 9 ) ), array( $cat( 17 ), $cat( 19 ) ), 5, 'taken' ),
			'in a list, the term of a pair there, here the term that took its slug'  => array( 'post', array( $ids( 5 ) ), array( $ids( 17 ) ), 5, 'taken' ),
			'a menu item: its target of a pair there, here the page that took it'    => array( 'menu_item', array( $target( 34 ), $menu( 2 ) ), array( $target( 13 ), $menu( 102 ) ), 5, 'taken' ),
			'a menu item: its menu of a pair there, here the menu that took it'      => array( 'menu_item', array( $target( 36 ), $menu( 3 ) ), array( $target( 14 ), $menu( 104 ) ), 5, 'taken' ),
			'a menu item: its parent item of a pair there, here the item that took it' => array( 'menu_item', array( $target( 36 ), $above( 60 ), $menu( 2 ) ), array( $target( 14 ), $above( 161 ), $menu( 102 ) ), 5, 'taken' ),
			'a term under the term of a pair there, here the term that took its slug' => array( 'term', array( $tparent( 5 ) ), array( $tparent( 17 ) ), 5, 'taken' ),
			// Not alike by the mapped forms (as in 7111bc4): other targets, a target without identity, a reference on one side only.
			'other categories without a pair'                            => array( 'post', array( $cat( 5 ), $cat( 9 ) ), array( $cat( 15 ), $cat( 18 ) ), 5 ),
			'a category of a pair more on one side'                      => array( 'post', array( $cat( 5 ), $cat( 6 ), $cat( 9 ) ), array( $cat( 15 ), $cat( 19 ) ), 5 ),
			'a target without an identity on its site'                   => array( 'post', array( $cat( 4 ), $cat( 5 ) ), array( $cat( 15 ), $cat( 19 ) ), 5 ),
			'a list in another order'                                    => array( 'post', array( $ids( 5 ), $ids( 6 ) ), array( $ids( 16 ), $ids( 15 ) ), 5 ),
			'a featured image on staging only'                           => array( 'post', array( $thumb( 34 ) ), array(), 5 ),
		);
		foreach ( array( 'the lines of the targets in the package' => true, 'no line of a target of staging in the package' => false ) as $package => $whole ) {
			foreach ( $cases as $case => $c ) {
				list( $kind, $s_refs, $l_refs, $row ) = $c;
				$live  = $sites[ $c[4] ?? 'l' ];
				$forms = array( self::mapped_by( 'changed', $s_refs, $sites['s'] ), self::mapped_by( 'changed', $l_refs, $live ) );
				if ( isset( $c[4] ) ) {
					self::assertSame( $forms[0], $forms[1], "$case: the same mapped form on both, the case of the finding." );
				}
				$s     = self::object( $kind, '50', $s_refs, $forms[0], 's1' );
				$l     = self::object( $kind, '20', $l_refs, $forms[1], 'l1' );
				$r     = self::report(
					array_merge( $whole ? self::lines( $sites['s'] ) : array(), array( $s ) ),
					array_merge( self::lines( $live ), array( $l ) ),
					array_merge( array( self::pair( 2, $kind, '20', '50' ) ), $pairs ),
					$whole ? null : static fn(): bool => false
				);
				$entry = self::of( $r, $kind, '50' );
				self::assertSame( array( $row, 'changed', 'changed' ), array( $entry['row'], $entry['sides']['staging'], $entry['sides']['live'] ), "$case ($package)" );
			}
		}
	}

	/**
	 * Codex round 3 of part 2, B: a package of the staging site holds its
	 * posts whole, but no term lines yet. A paired post changed alike on both
	 * sides, in «news» of a pair (S5 → L15) and in «events» without a pair on
	 * both sides (S9, L19), has the same mapped form on both (the export of
	 * its site named each term by its identity): row 6, as in 7111bc4. A
	 * target without a pair is compared by the mapped form of the object that
	 * names it, never by a line of its own.
	 */
	public function testATargetWithoutAPairIsComparedByTheMappedFormAlsoWhereItsLineIsNotAtHand(): void {
		$cat   = static fn( int $id ): array => self::ref( 'term', $id, 'term:category' );
		$pairs = array( self::pair( 2, 'post', '20', '50' ), self::pair( 3, 'term', '15', '5' ) );
		$cases = array(
			'no term lines of staging in the package' => array(),
			'the lines of its terms as well'           => array( self::term( '5', 'news' ), self::term( '9', 'events' ) ),
		);
		foreach ( $cases as $case => $terms ) {
			$r     = self::report(
				array_merge( $terms, array( self::page( '50', 'kontakt', 's1', array( 'mapped' => self::h( 'same' ), 'refs' => array( $cat( 5 ), $cat( 9 ) ) ) ) ) ),
				array( self::page( '20', 'kontakt', 'l1', array( 'mapped' => self::h( 'same' ), 'refs' => array( $cat( 15 ), $cat( 19 ) ) ) ), self::term( '15', 'news' ), self::term( '19', 'events' ) ),
				$pairs,
				static fn( string $kind ): bool => 'term' !== $kind || array() !== $terms
			);
			$entry = self::of( $r, 'post', '50' );
			self::assertSame( array( 'changed_alike', 6, 'none' ), array( $entry['result'], $entry['row'], $entry['action'] ), $case );
		}
	}

	/* ----------------------------------------------- the table without a pair */

	public function testEachRowOfTheTableWithoutAPair(): void {
		$r = self::report(
			array( self::page( '34', 'new-here', 'x' ), self::page( '35', 'both', 'b' ) ),
			array( self::page( '12', 'live-new', 'y' ), self::page( '13', 'both', 'b' ) )
		);
		$only_staging = self::of( $r, 'post', '34' );
		$only_live    = self::of( $r, 'post', '12' );
		$both         = self::of( $r, 'post', '35' );
		self::assertSame( array( 'only_staging', '4', 1, 'create' ), array( $only_staging['result'], $only_staging['rule'], $only_staging['row'], $only_staging['action'] ) );
		self::assertSame( array( 'new', 'absent' ), array_values( $only_staging['sides'] ) );
		self::assertSame( array( 'only_live', '4', 2, 'keep' ), array( $only_live['result'], $only_live['rule'], $only_live['row'], $only_live['action'] ) );
		self::assertSame( array( 'absent', 'new' ), array_values( $only_live['sides'] ) );
		self::assertSame( array( 'new_both', '4', 3, 'pair', 'equal' ), array( $both['result'], $both['rule'], $both['row'], $both['action'], $both['proposal'] ) );
		self::assertSame( '13', $both['live']['ref'], 'The same entry names both sides.' );
		self::assertCount( 3, $r['entries'] );
	}

	public function testAnObjectWithoutAPairThatIsHeldOrNotComparedIsNotTakenOver(): void {
		$held = array(
			'kind'       => 'option',
			'ref_sha256' => self::h( '"x"' ),
			'held'       => 'credential_name',
		);
		$r    = self::report( array( $held, self::page( '34', 'about', 'x', array( 'hash' => null, 'mapped' => null, 'reason_code' => null ) ), self::page( '35', 'part', 'y', array( 'complete' => false ) ) ), array() );
		$h    = array_values( array_filter( $r['entries'], static fn( $e ) => 'held' === $e['result'] ) );
		self::assertCount( 1, $h );
		self::assertSame( array( 'none', array( array( 'held' => 'credential_name', 'ref_sha256' => self::h( '"x"' ) ) ) ), array( $h[0]['action'], $h[0]['staging'] ) );
		foreach ( array( '34', '35' ) as $ref ) {
			self::assertSame( array( 'not_compared', 'none' ), array( self::of( $r, 'post', $ref )['result'], self::of( $r, 'post', $ref )['action'] ), 'Not created: ' . $ref );
		}
	}

	public function testAnObjectOnlyHereIsNotComparedWhereTheStagingSideIsNotComplete(): void {
		$r = self::report( array(), array( self::page( '12', 'live-new', 'y' ) ), array(), static fn(): bool => false );
		self::assertSame( array( 'not_compared', 'none' ), array( self::of( $r, 'post', '12' )['result'], self::of( $r, 'post', '12' )['action'] ) );
	}

	public function testNewOnBothSidesOfOtherContentOrUncertainArePairedFirst(): void {
		$r = self::report( array( self::page( '35', 'both', 'b1' ) ), array( self::page( '13', 'both', 'b2' ) ) );
		self::assertSame( array( 'new_both', 'pair', 'differs' ), array( self::of( $r, 'post', '35' )['result'], self::of( $r, 'post', '35' )['action'], self::of( $r, 'post', '35' )['proposal'] ) );
		$r = self::report( array( self::page( '35', 'twin', 'b1' ), self::page( '36', 'twin', 'b2' ) ), array( self::page( '13', 'twin', 'b3' ) ) );
		$entry = $r['entries'][0];
		self::assertSame( array( 'new_both', 'pair', 'uncertain' ), array( $entry['result'], $entry['action'], $entry['proposal'] ) );
		self::assertSame( array( '35', '36' ), array_column( $entry['candidates']['staging'], 'ref' ) );
		self::assertStringNotContainsString( '"hash"', (string) json_encode( $r ), 'No hash in an entry.' );
		self::assertStringNotContainsString( '"mapped', (string) json_encode( $r ) );
	}

	/* ---------------------------------------------------------- the order */

	public function testRuleZeroAResidualPairIsARestAndNothingAppliesToIt(): void {
		foreach ( array( 's0', 's1' ) as $staging ) {
			$entry = self::decided( array( self::page( '34', 'about', $staging ) ), array( self::page( '12', 'about', 'l1' ) ), self::pair( 7, 'post', '12', '34', 'residual', array( 'last_run' => 42 ) ) );
			self::assertSame( array( 'residual', '0', null, 'none' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), 'Also where staging changes it: ' . $staging );
			self::assertSame( 42, $entry['pair']['last_run'], 'The run of the rest.' );
			self::assertStringContainsString( 'Rest after a way back or an error', $entry['words'] );
		}
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array(), self::pair( 7, 'post', '12', '34', 'residual' ) );
		self::assertSame( array( 'residual_removed', '0', 'none' ), array( $entry['result'], $entry['rule'], $entry['action'] ) );
		self::assertStringContainsString( 'Rest removed', $entry['words'] );
	}

	public function testRuleZeroARestInTheTrashIsStillARest(): void {
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array( self::page( '12', 'about__trashed', 'l9', array( 'post_status' => 'trash' ) ) ), self::pair( 7, 'post', '12', '34', 'residual', array( 'last_run' => 42 ) ) );
		self::assertSame( array( 'residual', '0', null, 'none' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ) );
		self::assertSame( 'Rest after a way back or an error: the pair keeps its mapping, and nothing is taken over or created for it, also where staging changes it. Only a confirmed new alignment moves it on (wp_site_pairs action=propose shows it, confirm makes it active with the bases of both sides now, discard ends the pair).', $entry['words'] );
		self::assertSame( 'In the trash on this site: still a rest. A rest is removed only where its object is no longer on this site.', $entry['reason'] );
		self::assertSame( 'deleted', $entry['sides']['live'] );
	}

	public function testRuleZeroHoldsAWidgetAreaAsAWholeWithItsInstanceMapping(): void {
		$extra = array( 'instances' => array( 'staging' => array( 'text-2' ), 'live' => array( 'text-5' ) ), 'map' => array( 'text-2' => 'text-5' ) );
		$s     = self::line( 'widget_area', 'sidebar-1', array( 'area' => 'sidebar-1' ), self::h( 's1' ), array( 'instances' => array( 'text-3' ) ) );
		$l     = self::line( 'widget_area', 'sidebar-1', array( 'area' => 'sidebar-1' ), self::h( 'l0' ), array( 'instances' => array( 'text-5' ) ) );
		$entry = self::decided( array( $s ), array( $l ), self::pair( 7, 'widget_area', 'sidebar-1', 'sidebar-1', 'residual', array( 'base_extra' => $extra ) ) );
		self::assertSame( array( 'residual', 'none' ), array( $entry['result'], $entry['action'] ), 'An instance gone on staging locks nothing of a rest: nothing applies to it.' );
		self::assertArrayNotHasKey( 'locks', $entry );
	}

	public function testRuleOneAnUnclearPairIsLikeAConflict(): void {
		foreach ( array( array( 's0', 'l0' ), array( 's1', 'l0' ), array( 's1', 'l1' ) ) as list( $s, $l ) ) {
			$entry = self::decided( array( self::page( '34', 'about', $s ) ), array( self::page( '12', 'about', $l ) ), self::pair( 7, 'post', '12', '34', 'unclear' ) );
			self::assertSame( array( 'unclear', '1', null, 'choose' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), "$s, $l" );
			self::assertSame( array( 'staging_wins', 'keep_live', 'leave_open' ), $entry['choices'] );
		}
		self::assertCount( 1, self::report( array( self::page( '34', 'about', 's1' ) ), array( self::page( '12', 'about', 'l0' ) ), array( self::pair( 7, 'post', '12', '34', 'unclear' ) ) )['entries'], 'One entry: the proposal\'s new alignment is not one of the report.' );
		// A side deleted is still a choice of a person.
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array(), self::pair( 7, 'post', '12', '34', 'unclear' ) );
		self::assertSame( array( 'unclear', 'choose' ), array( $entry['result'], $entry['action'] ) );
	}

	/**
	 * Codex round 2 of part 2, a: an unclear pair a side of which a credential
	 * holds (its name a credential here since it was paired, or a line held)
	 * is not compared and offers no choice, as in the other states of a pair.
	 */
	public function testRuleOneAnUnclearPairWithASideHeldByACredentialOffersNoChoice(): void {
		$words = 'A name of this object holds a credential by the rules of this site: shown by the SHA-256 of its ref only, never compared. Take it over by hand.';
		$b9    = array( "option\0" . self::h( '"my_setting"' ) => true );
		$line  = self::line( 'option', 'my_setting', array( 'name' => 'my_setting' ), self::h( 's1' ) );
		foreach ( array( 'its staging line there' => array( array( $line ), 'not_compared' ), 'its staging line gone' => array( array(), 'deleted' ) ) as $case => list( $staging, $state ) ) {
			$entry = self::decided( $staging, array(), self::pair( 7, 'option', 'my_setting', 'my_setting', 'unclear' ), null, $b9 );
			self::assertSame( array( 'not_compared', '1', null, 'none' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), $case );
			self::assertSame( array( 'staging' => $state, 'live' => 'not_compared' ), $entry['sides'], $case );
			self::assertArrayNotHasKey( 'choices', $entry, $case );
			self::assertSame( $words, $entry['reason'], $case );
		}
		$held = static fn( string $ref ): array => array(
			'kind'       => 'post',
			'ref_sha256' => self::h( '"' . $ref . '"' ),
			'held'       => 'credential_field',
		);
		foreach ( array( 'held on staging' => array( array( $held( '34' ) ), array( self::page( '12', 'about', 'l1' ) ) ), 'held here' => array( array( self::page( '34', 'about', 's1' ) ), array( $held( '12' ) ) ) ) as $case => list( $staging, $live ) ) {
			$entry = self::decided( $staging, $live, self::pair( 7, 'post', '12', '34', 'unclear' ) );
			self::assertSame( array( 'not_compared', '1', 'none' ), array( $entry['result'], $entry['rule'], $entry['action'] ), $case );
			self::assertArrayNotHasKey( 'choices', $entry, $case );
			self::assertSame( $words, $entry['reason'], $case );
		}
	}

	public function testRuleTwoAPairTakenOverAsDeleted(): void {
		// This site has it again: its state the base, then the table with S.
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array( self::page( '12', 'about', 'l7' ) ), self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'changed_on_staging', '2', 2, 'take_over' ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ) );
		self::assertSame( 'unchanged', $entry['sides']['live'] );
		$entry = self::decided( array(), array( self::page( '12', 'about', 'l7' ) ), self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'deleted_on_staging', '2', 3, 'locked', array( '*:delete' ) ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'], self::keys( $entry ) ) );
		// Deleted on staging as well: nothing.
		$entry = self::decided( array(), array(), self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'deleted_done', '2', 'none' ), array( $entry['result'], $entry['rule'], $entry['action'] ) );
		// There again on staging: brought back with Z8d.
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array(), self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'restored_on_staging', '2', 'locked', array( '*:restore_deleted' ) ), array( $entry['result'], $entry['rule'], $entry['action'], self::keys( $entry ) ) );
	}

	public function testRuleTwoALiveObjectInTheTrashIsStillDeleted(): void {
		$trashed = array( self::page( '12', 'about__trashed', 'l7', array( 'post_status' => 'trash' ) ) );
		$entry   = self::decided( array( self::page( '34', 'about', 's1' ) ), $trashed, self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'restored_on_staging', '2', null, 'locked', array( '*:restore_deleted' ) ), array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'], self::keys( $entry ) ), 'In the trash here: not there again.' );
		self::assertSame( array( 'staging' => 'changed', 'live' => 'deleted' ), $entry['sides'] );
		self::assertSame( 'Taken over as deleted, and there again on staging: bringing it back here follows with Z8d.', $entry['words'] );
		$entry = self::decided( array(), $trashed, self::pair( 7, 'post', '12', '34', 'deleted' ) );
		self::assertSame( array( 'deleted_done', '2', 'none' ), array( $entry['result'], $entry['rule'], $entry['action'] ) );
	}

	public function testRuleThreeAnActivePairTakesTheTableAndRuleFourNoPairTheOther(): void {
		$r = self::report( array( self::page( '34', 'about', 's1' ), self::page( '35', 'new', 'n' ) ), array( self::page( '12', 'about', 'l0' ) ), array( self::pair( 7, 'post', '12', '34' ) ) );
		self::assertSame( array( '3', 2 ), array( self::of( $r, 'post', '34' )['rule'], self::of( $r, 'post', '34' )['row'] ) );
		self::assertSame( array( '4', 1 ), array( self::of( $r, 'post', '35' )['rule'], self::of( $r, 'post', '35' )['row'] ) );
		self::assertCount( 2, $r['entries'], 'The objects of a pair are no entry of their own.' );
		self::assertSame( array( 'active' => 1, 'deleted' => 0, 'unclear' => 0, 'residual' => 0 ), $r['pairs'] );
	}

	/* ------------------------------------------------- the state of a side */

	public function testIncompleteNeverMeansDeleted(): void {
		$asked = array();
		$entry = self::decided(
			array(),
			array( self::page( '12', 'about', 'l0' ) ),
			self::pair( 7, 'post', '12', '34' ),
			static function ( string $kind, ?string $subtype ) use ( &$asked ): bool {
				$asked[] = array( $kind, $subtype );
				return false;
			}
		);
		self::assertSame( array( 'not_compared', 'none' ), array( $entry['result'], $entry['action'] ) );
		self::assertSame( 'not_compared', $entry['sides']['staging'] );
		self::assertStringContainsString( 'not called deleted', $entry['reason'] );
		self::assertContains( array( 'post', 'page' ), $asked, 'By the subtype the line of this site names.' );
		// Gone on both sides: the kind with every subtype.
		$asked = array();
		self::decided(
			array(),
			array(),
			self::pair( 7, 'post', '12', '34' ),
			static function ( string $kind, ?string $subtype ) use ( &$asked ): bool {
				$asked[] = array( $kind, $subtype );
				return true;
			}
		);
		self::assertContains( array( 'post', null ), $asked );
	}

	public function testAKindThisSiteDoesNotReadIsNeverDeleted(): void {
		// Since branch 10 the inventory reads the attachments: a kind it does
		// not read is one of no kind of the database.
		$entry = self::decided( array(), array(), self::pair( 7, 'gallery', '12', '34' ) );
		self::assertSame( array( 'not_compared', 'not_compared' ), array( $entry['sides']['staging'], $entry['sides']['live'] ) );
	}

	public function testTheTrashIsADeletionAndAnAutoDraftNoContent(): void {
		$entry = self::decided( array( self::page( '34', 'about__trashed', 's9', array( 'post_status' => 'trash' ) ) ), array( self::page( '12', 'about', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'deleted_on_staging', 3, 'locked' ), array( $entry['result'], $entry['row'], $entry['action'] ), 'In the trash on staging: deleted, no status stands in.' );
		self::assertCount( 1, self::report( array( self::page( '34', 'about__trashed', 's9', array( 'post_status' => 'trash' ) ) ), array( self::page( '12', 'about', 'l0' ) ), array( self::pair( 7, 'post', '12', '34' ) ) )['entries'], 'An object of a pair in the trash is no entry of its own.' );
		$entry = self::decided( array( self::page( '34', 'about', 's0' ) ), array( self::page( '12', 'about', 'l9', array( 'post_status' => 'trash' ) ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'deleted_live', 8 ), array( $entry['result'], $entry['row'] ) );
		$entry = self::decided( array( self::page( '34', '', 's1', array( 'post_status' => 'auto-draft' ) ) ), array( self::page( '12', 'about', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'not_compared', 'none', 'not_compared' ), array( $entry['result'], $entry['action'], $entry['sides']['staging'] ), 'An auto-draft of a pair: no content.' );
		$r = self::report( array( self::page( '34', 'gone__trashed', 'x', array( 'post_status' => 'trash' ) ), self::page( '35', '', 'y', array( 'post_status' => 'auto-draft' ) ) ), array( self::page( '13', '', 'z', array( 'post_status' => 'auto-draft' ) ) ) );
		self::assertSame( array( 'in_trash', 'none' ), array( self::of( $r, 'post', '34' )['result'], self::of( $r, 'post', '34' )['action'] ) );
		foreach ( array( '35', '13' ) as $ref ) {
			self::assertSame( array( 'not_compared', 'none' ), array( self::of( $r, 'post', $ref )['result'], self::of( $r, 'post', $ref )['action'] ), 'An auto-draft is never created, nor uncertain with another.' );
			self::assertStringContainsString( 'auto-draft', self::of( $r, 'post', $ref )['reason'] );
		}
	}

	public function testAnObjectHereWhoseNameHoldsACredentialIsNotDeleted(): void {
		$b9    = array( "option\0" . self::h( '"api_key"' ) => true );
		$entry = self::decided( array( self::line( 'option', 'api_key', array( 'name' => 'api_key' ), self::h( 's0' ) ) ), array(), self::pair( 7, 'option', 'api_key', 'api_key' ), null, $b9 );
		self::assertSame( array( 'not_compared', 'not_compared' ), array( $entry['result'], $entry['sides']['live'] ) );
		self::assertStringContainsString( 'credential', $entry['reason'] );
		$entry = self::decided( array( self::line( 'option', 'api_key', array( 'name' => 'api_key' ), self::h( 's0' ) ) ), array(), self::pair( 7, 'option', 'api_key', 'api_key' ) );
		self::assertSame( 'deleted', $entry['sides']['live'], 'Without it: gone.' );
	}

	public function testAPairWhoseNameThisSiteCallsACredentialShowsNeitherSideByItsName(): void {
		$b9   = array( "option\0" . self::h( '"my_setting"' ) => true );
		$line = self::line( 'option', 'my_setting', array( 'name' => 'my_setting' ), self::h( 's1' ) );
		$held = array( 'held' => 'credential_name', 'ref_sha256' => self::h( '"my_setting"' ) );
		foreach ( array( 'its staging line there' => array( $line ), 'its staging line gone' => array() ) as $case => $staging ) {
			foreach ( array( 'active', 'residual', 'unclear', 'deleted' ) as $state ) {
				$r = self::report( $staging, array(), array( self::pair( 7, 'option', 'my_setting', 'my_setting', $state ) ), null, $b9 );
				self::assertStringNotContainsString( 'my_setting', (string) json_encode( $r ), "$case, $state: the name nowhere" );
				self::assertCount( 1, $r['entries'], "$case, $state" );
				self::assertSame( array( 'staging' => $held, 'live' => $held ), array( 'staging' => $r['entries'][0]['staging'], 'live' => $r['entries'][0]['live'] ), "$case, $state: by the SHA-256 of its ref only, as a held line" );
			}
		}
		$entry = self::decided( array( $line ), array(), self::pair( 7, 'option', 'my_setting', 'my_setting' ), null, $b9 );
		self::assertSame( array( 'not_compared', 'none', 'not_compared', 'not_compared' ), array( $entry['result'], $entry['action'], $entry['sides']['staging'], $entry['sides']['live'] ), 'Not compared, not deleted.' );
	}

	public function testASideNotComparedLeavesThePairNotCompared(): void {
		$pair = self::pair( 7, 'post', '12', '34' );
		$l    = self::page( '12', 'about', 'l0' );
		$held = array(
			'kind'       => 'post',
			'ref_sha256' => self::h( '"34"' ),
			'held'       => 'credential_field',
		);
		$cases = array(
			'held'             => $held,
			'without a hash'   => self::page( '34', 'about', 's0', array( 'hash' => null, 'mapped' => null ) ),
			'not read whole'   => self::page( '34', 'about', 's0', array( 'complete' => false ) ),
			'another canon'    => self::page( '34', 'about', 's0', array( 'canon' => 'canon/2' ) ),
		);
		foreach ( $cases as $case => $s ) {
			$entry = self::decided( array( $s ), array( $l ), $pair );
			self::assertSame( array( 'not_compared', 'none', 'not_compared' ), array( $entry['result'], $entry['action'], $entry['sides']['staging'] ), $case );
		}
		self::assertSame( array( 'held' => 'credential_field', 'ref_sha256' => self::h( '"34"' ) ), self::decided( array( $held ), array( $l ), $pair )['staging'], 'By the SHA-256 of its ref only.' );
		self::assertSame( 'A name of this object holds a credential by the rules of this site: shown by the SHA-256 of its ref only, never compared. Take it over by hand.', self::decided( array( $held ), array( $l ), $pair )['reason'], 'Not compared because a credential holds it.' );
		$entry = self::decided( array( self::page( '34', 'about', 's0' ) ), array( $l ), self::pair( 7, 'post', '12', '34', 'active', array( 'canon' => 'canon/9' ) ) );
		self::assertSame( array( 'not_compared', 'none' ), array( $entry['result'], $entry['action'] ), 'Bases of another version.' );
		self::assertStringContainsString( 'wp_site_pairs action=propose', $entry['reason'] );
	}

	public function testAPairWithAnOverrideIsComparedAgainstItsTargetAndNamesIt(): void {
		$pair  = self::pair( 7, 'post', '12', '34', 'active', array( 'override' => array( 'post_status' => 'draft' ), 'base_live' => self::h( 'draft' ) ) );
		$entry = self::decided( array( self::page( '34', 'about', 's0' ) ), array( self::page( '12', 'about', 'draft' ) ), $pair );
		self::assertSame( array( 'equal', 'none' ), array( $entry['result'], $entry['action'] ), 'This site as the confirmed target is unchanged.' );
		self::assertSame( 'Compared against the confirmed target: taken over with post_status draft.', $entry['note'] );
		self::assertSame( array( 'post_status' => 'draft' ), $entry['pair']['override'] );
		$entry = self::decided( array( self::page( '34', 'about', 's0' ) ), array( self::page( '12', 'about', 'published' ) ), $pair );
		self::assertSame( 'changed_live', $entry['result'], 'Published here since: a change of this site.' );
	}

	/* ------------------------------------------------------------ locked */

	public function testTheLocksOfTheLinesHoldATakeoverAndAreShownWithTheirStage(): void {
		$builder = array( 'at' => 'meta:_elementor_data', 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => AB_MCP_Sync_Kinds::LOCKS['post:builder']['reason'] );
		$entry   = self::decided( array( self::page( '34', 'about', 's1', array( 'status' => 'locked', 'locks' => array( $builder ) ) ) ), array( self::page( '12', 'about', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'changed_on_staging', 'locked' ), array( $entry['result'], $entry['action'] ) );
		self::assertSame( array( array( 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => $builder['reason'] ) ), $entry['locks'], 'With its reason and stage, without the place.' );
		$entry = self::decided( array( self::page( '34', 'about', 's1', array( 'locks' => array( $builder ) ) ) ), array( self::page( '12', 'about', 'l1' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'conflict', 'locked' ), array( $entry['result'], $entry['action'] ), 'A conflict whose staging side is locked: no choice of staging.' );
		self::assertArrayNotHasKey( 'choices', $entry );
		// A lock of this site's object holds as well.
		$entry = self::decided( array( self::page( '34', 'about', 's1' ) ), array( self::page( '12', 'about', 'l0', array( 'locks' => array( $builder ) ) ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( 'locked', $entry['action'] );
		// Equal and locked: nothing to do, the lock shown.
		$entry = self::decided( array( self::page( '34', 'about', 's0', array( 'locks' => array( $builder ) ) ) ), array( self::page( '12', 'about', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( array( 'equal', 'none', array( 'post:builder' ) ), array( $entry['result'], $entry['action'], self::keys( $entry ) ) );
		// Locked kinds: roles, a changeset, an option of a plugin, a theme mod.
		$kinds = array(
			array( 'option', 'wp_user_roles', array( 'name' => 'wp_user_roles' ), 'option:{prefix}user_roles' ),
			array( 'post', '40', array( 'post_name' => 'c', 'post_type' => 'customize_changeset', 'theme' => '' ), 'post_type:customize_changeset' ),
			array( 'option', 'woocommerce_currency', array( 'name' => 'woocommerce_currency' ), 'option:unclassified' ),
			array( 'theme_mod', 'twentytwenty/header_textcolor', array( 'name' => 'theme_mods_twentytwenty', 'theme_mod' => 'header_textcolor' ), 'theme_mod:*' ),
		);
		foreach ( $kinds as list( $kind, $ref, $identity, $key ) ) {
			$table = AB_MCP_Sync_Kinds::OWNERSHIP[ $key ] ?? AB_MCP_Sync_Kinds::LOCKS[ $key ];
			$lock  = array( 'at' => 'type', 'key' => $key, 'stage' => $table['stage'], 'reason' => $table['reason'] );
			$r     = self::report( array( self::line( $kind, $ref, $identity, self::h( 'n' ), array( 'status' => 'locked', 'locks' => array( $lock ) ) ) ), array() );
			$entry = self::of( $r, $kind, $ref );
			self::assertSame( array( 'only_staging', 'locked', array( $key ) ), array( $entry['result'], $entry['action'], self::keys( $entry ) ), $key );
			self::assertSame( $table['stage'], $entry['locks'][0]['stage'] );
			self::assertSame( array( array( 'key' => $key, 'stage' => $table['stage'], 'reason' => $table['reason'], 'entries' => 1 ) ), $r['blocked'], 'What is locked, with its reason, stage and count.' );
		}
	}

	public function testCreatingAdditionalCssIsLockedAndOnlyLinkedCssIsUpdated(): void {
		$css = static fn( string $ref, bool $linked, string $state ): array => self::line( 'custom_css', $ref, array( 'linked' => $linked, 'post' => 9, 'stylesheet' => 'twentytwenty' ), self::h( $state ) );
		$r   = self::report( array( $css( 'twentytwenty', true, 'c' ) ), array() );
		self::assertSame( array( 'only_staging', 'locked', array( 'custom_css:create' ) ), array( $r['entries'][0]['result'], $r['entries'][0]['action'], self::keys( $r['entries'][0] ) ) );
		$entry = self::decided( array( $css( 'twentytwenty', true, 's1' ) ), array( $css( 'twentytwenty', true, 'l0' ) ), self::pair( 7, 'custom_css', 'twentytwenty', 'twentytwenty' ) );
		self::assertSame( 'take_over', $entry['action'], 'The CSS this site links: updated.' );
		$entry = self::decided( array( $css( 'twentytwenty', true, 's1' ) ), array( $css( 'twentytwenty@9', false, 'l0' ) ), self::pair( 7, 'custom_css', 'twentytwenty@9', 'twentytwenty' ) );
		self::assertSame( array( 'locked', array( 'custom_css:create' ) ), array( $entry['action'], self::keys( $entry ) ), 'A CSS post this site does not link: writing it would make the link.' );
		$entry = self::decided( array( $css( 'twentytwenty', true, 's0' ) ), array( $css( 'twentytwenty@9', false, 'l0' ) ), self::pair( 7, 'custom_css', 'twentytwenty@9', 'twentytwenty' ) );
		self::assertSame( array( 'equal', 'none', array() ), array( $entry['result'], $entry['action'], self::keys( $entry ) ), 'Nothing written: no lock of writing.' );
	}

	public function testCreatingATermWithMetaIsLocked(): void {
		$term = static fn( string $ref, array $over ): array => self::line( 'term', $ref, array( 'slug' => 'news-' . $ref, 'taxonomy' => 'category' ), self::h( 't' . $ref ), $over );
		$r    = self::report( array( $term( '5', array( 'meta' => 0 ) ), $term( '6', array( 'meta' => 2 ) ), $term( '7', array( 'meta' => 0, 'b9' => array( 'meta:api_key' ) ) ), $term( '8', array() ) ), array() );
		self::assertSame( array( 'create', array() ), array( self::of( $r, 'term', '5' )['action'], self::keys( self::of( $r, 'term', '5' ) ) ), 'Without meta: created.' );
		foreach ( array( '6' => 'meta rows', '7' => 'meta left out as a credential', '8' => 'a line that does not count its meta' ) as $ref => $case ) {
			self::assertSame( array( 'locked', array( 'term:create_with_meta' ) ), array( self::of( $r, 'term', (string) $ref )['action'], self::keys( self::of( $r, 'term', (string) $ref ) ) ), $case );
		}
		$entry = self::decided( array( $term( '6', array( 'meta' => 2 ) ) ), array( array_merge( $term( '16', array( 'meta' => 2 ) ), array( 'hash' => self::h( 'l0' ) ) ) ), self::pair( 7, 'term', '16', '6', 'active', array( 'base_staging' => self::h( 'x' ) ) ) );
		self::assertSame( 'take_over', $entry['action'], 'A term there on both sides is changed with its meta (decision 28).' );
	}

	/** A menu item line of a menu, with its target and parent by identity. */
	private static function item( string $ref, int $menu, string $target, string $state ): array {
		return self::line(
			'menu_item',
			$ref,
			array(
				'menus'    => array( $menu ),
				'object'   => 'page',
				'parent'   => self::h( 'none' ),
				'position' => 1,
				'target'   => self::h( $target ),
				'type'     => 'post_type',
			),
			self::h( $state )
		);
	}

	private static function menu( string $ref, string $slug, string $state ): array {
		return self::line( 'menu', $ref, array( 'slug' => $slug, 'taxonomy' => 'nav_menu' ), self::h( $state ), array( 'meta' => 0 ) );
	}

	public function testAMenuWithAnItemRemovedOnStagingIsLockedAsAWhole(): void {
		$staging = array( self::menu( '5', 'main', 's1' ), self::item( '52', 5, 'kontakt', 's1' ), self::item( '53', 5, 'new', 'n' ), self::menu( '6', 'footer', 's1' ), self::item( '61', 6, 'about', 's1' ) );
		$live    = array( self::menu( '15', 'main', 'l0' ), self::item( '151', 15, 'about', 'l0' ), self::item( '152', 15, 'kontakt', 'l0' ), self::menu( '16', 'footer', 'l0' ), self::item( '161', 16, 'about', 'l0' ) );
		$pairs   = array(
			self::pair( 1, 'menu', '15', '5' ),
			self::pair( 2, 'menu_item', '151', '51' ),
			self::pair( 3, 'menu_item', '152', '52' ),
			self::pair( 4, 'menu', '16', '6' ),
			self::pair( 5, 'menu_item', '161', '61' ),
		);
		$r = self::report( $staging, $live, $pairs );
		self::assertSame( array( 'deleted_on_staging', 'locked' ), array( self::of( $r, 'menu_item', '151' )['result'], self::of( $r, 'menu_item', '151' )['action'] ), 'The item removed on staging.' );
		self::assertContains( 'menu:remove_item', self::keys( self::of( $r, 'menu_item', '151' ) ) );
		foreach ( array( array( 'menu', '5' ), array( 'menu_item', '52' ), array( 'menu_item', '53' ) ) as list( $kind, $ref ) ) {
			$entry = self::of( $r, $kind, $ref );
			self::assertSame( 'locked', $entry['action'], "$kind $ref: the menu as a whole, its new item too" );
			self::assertSame( array( 'menu:remove_item' ), self::keys( $entry ) );
			self::assertSame( 'Z8d', $entry['locks'][0]['stage'] );
		}
		self::assertSame( 'take_over', self::of( $r, 'menu', '6' )['action'], 'Another menu stays as it is.' );
		self::assertSame( 'take_over', self::of( $r, 'menu_item', '61' )['action'] );

		// A rest in the menu takes no lock (rule 0); a rest removed on staging locks its menu.
		$r = self::report( $staging, array_merge( $live, array( self::item( '154', 15, 'rest', 'l0' ) ) ), array_merge( $pairs, array( self::pair( 6, 'menu_item', '154', '54', 'residual' ) ) ) );
		self::assertArrayNotHasKey( 'locks', self::of( $r, 'menu_item', '154' ) );
		$r = self::report( $staging, array_merge( $live, array( self::item( '164', 16, 'rest', 'l0' ) ) ), array( self::pair( 4, 'menu', '16', '6' ), self::pair( 5, 'menu_item', '161', '61' ), self::pair( 6, 'menu_item', '164', '64', 'residual' ) ) );
		self::assertSame( array( 'locked', array( 'menu:remove_item' ) ), array( self::of( $r, 'menu', '6' )['action'], self::keys( self::of( $r, 'menu', '6' ) ) ), 'Its item gone on staging, kept here as a rest.' );
	}

	public function testAWidgetAreaWithAnInstanceRemovedOnStagingIsLockedAsAWhole(): void {
		$area  = static fn( string $state, array $ids ): array => self::line( 'widget_area', 'sidebar-1', array( 'area' => 'sidebar-1' ), self::h( $state ), array( 'instances' => $ids ) );
		$extra = array( 'instances' => array( 'live' => array( 'text-5', 'block-7' ), 'staging' => array( 'text-2', 'block-3' ) ) );
		$pair  = self::pair( 7, 'widget_area', 'sidebar-1', 'sidebar-1', 'active', array( 'base_extra' => $extra ) );
		$entry = self::decided( array( $area( 's1', array( 'text-2' ) ) ), array( $area( 'l0', array( 'text-5', 'block-7' ) ) ), $pair );
		self::assertSame( array( 'changed_on_staging', 'locked', array( 'widget_area:remove_instance' ) ), array( $entry['result'], $entry['action'], self::keys( $entry ) ) );
		$entry = self::decided( array( $area( 's1', array( 'block-3', 'text-2', 'text-9' ) ) ), array( $area( 'l0', array( 'text-5', 'block-7' ) ) ), $pair );
		self::assertSame( 'take_over', $entry['action'], 'Moved and added: no instance removed.' );
		self::assertSame( array( 'block-3', 'text-2', 'text-9' ), $entry['staging']['instances'] );
		$entry = self::decided( array( $area( 's1', array( 'text-2', 'block-3' ) ) ), array( $area( 'l0', array( 'text-5', 'block-7' ) ) ), self::pair( 7, 'widget_area', 'sidebar-1', 'sidebar-1' ) );
		self::assertSame( array( 'locked', array( 'widget_area:remove_instance' ) ), array( $entry['action'], self::keys( $entry ) ), 'The instances of its base not known: fail closed.' );
		$entry = self::decided( array( self::line( 'widget_area', 'sidebar-1', array( 'area' => 'sidebar-1' ), self::h( 's1' ) ) ), array( $area( 'l0', array() ) ), $pair );
		self::assertSame( 'locked', $entry['action'], 'A staging line that does not name its instances: fail closed.' );
		$entry = self::decided( array( $area( 's0', array( 'text-2' ) ) ), array( $area( 'l1', array( 'text-5' ) ) ), $pair );
		self::assertSame( array( 'changed_live', 'keep', array() ), array( $entry['result'], $entry['action'], self::keys( $entry ) ), 'Removed here: a change of this site.' );
	}

	/* ------------------------------------------------------------- graph */

	public function testATargetCreatedInTheSameRunIsAnEdgeNotAWait(): void {
		$r = self::report( array( self::page( '34', 'child', 'c', array( 'refs' => array( self::ref( 'post', 35 ) ) ) ), self::page( '35', 'parent', 'p' ) ), array() );
		self::assertSame( array( 'create', 'create' ), array( self::of( $r, 'post', '34' )['action'], self::of( $r, 'post', '35' )['action'] ) );
		self::assertArrayNotHasKey( 'waits_for', self::of( $r, 'post', '34' ) );
	}

	public function testATargetWithAnActivePairIsNamedByItsPairAlsoWhereItIsLocked(): void {
		$builder = array( 'at' => 'type', 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => AB_MCP_Sync_Kinds::LOCKS['post:builder']['reason'] );
		$r       = self::report(
			array( self::page( '34', 'child', 's1', array( 'refs' => array( self::ref( 'post', 35 ) ) ) ), self::page( '35', 'parent', 's0', array( 'locks' => array( $builder ) ) ), self::page( '36', 'new', 'n', array( 'refs' => array( self::ref( 'post', 35 ) ) ) ) ),
			array( self::page( '12', 'child', 'l0' ), self::page( '13', 'parent', 'l0' ) ),
			array( self::pair( 1, 'post', '12', '34' ), self::pair( 2, 'post', '13', '35' ) )
		);
		self::assertSame( 'take_over', self::of( $r, 'post', '34' )['action'] );
		self::assertSame( 'create', self::of( $r, 'post', '36' )['action'], 'A locked target that stays as it is here: named by its pair.' );
	}

	public function testATargetThatCannotBeNamedHereMakesItWait(): void {
		$builder = array( 'at' => 'type', 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => AB_MCP_Sync_Kinds::LOCKS['post:builder']['reason'] );
		$r       = self::report(
			array(
				self::page( '30', 'needs-locked', 'a', array( 'refs' => array( self::ref( 'post', 40 ) ) ) ),
				self::page( '40', 'locked-new', 'b', array( 'locks' => array( $builder ) ) ),
				self::page( '31', 'needs-unclear', 'a', array( 'refs' => array( self::ref( 'post', 41 ) ) ) ),
				self::page( '41', 'unclear', 's0' ),
				self::page( '32', 'needs-image', 'a', array( 'refs' => array( self::ref( 'post', 99, 'meta:_thumbnail_id' ) ) ) ),
				self::page( '33', 'needs-unpaired', 'a', array( 'refs' => array( self::ref( 'term', 7, 'term:category' ) ) ) ),
				self::line( 'term', '7', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 't7' ), array( 'meta' => 0 ) ),
				self::page( '34', 'needs-gone', 'a', array( 'refs' => array( self::ref( 'post', 42 ) ) ) ),
				self::page( '42', 'gone-here', 's0' ),
				// A target of twins (uncertain), a pair taken over as deleted,
				// a pair whose staging object is not there, one in the trash.
				self::page( '50', 'needs-twin', 'a', array( 'refs' => array( self::ref( 'post', 60 ) ) ) ),
				self::page( '60', 'twin', 't1' ),
				self::page( '61', 'twin', 't2' ),
				self::page( '51', 'needs-deleted', 'a', array( 'refs' => array( self::ref( 'post', 43 ) ) ) ),
				self::page( '52', 'needs-missing', 'a', array( 'refs' => array( self::ref( 'post', 44 ) ) ) ),
				self::page( '53', 'needs-trashed', 'a', array( 'refs' => array( self::ref( 'post', 45 ) ) ) ),
				self::page( '45', 'trashed__trashed', 's7', array( 'post_status' => 'trash' ) ),
			),
			array(
				self::page( '14', 'unclear', 'l0' ),
				self::line( 'term', '17', array( 'slug' => 'news', 'taxonomy' => 'category' ), self::h( 't17' ), array( 'meta' => 0 ) ),
				self::page( '70', 'twin', 't3' ),
				self::page( '16', 'deleted', 'l0' ),
				self::page( '18', 'missing', 'l0' ),
				self::page( '19', 'trashed', 'l0' ),
			),
			array( self::pair( 1, 'post', '14', '41', 'unclear' ), self::pair( 2, 'post', '15', '42' ), self::pair( 3, 'post', '16', '43', 'deleted' ), self::pair( 4, 'post', '18', '44' ), self::pair( 5, 'post', '19', '45' ) ),
			static fn( string $kind, ?string $subtype ): bool => 'post' !== $kind || 'page' === $subtype
		);
		foreach ( array( '30' => 'held back itself', '31' => 'unclear pair', '32' => 'not in its inventory', '33' => 'pair them first', '34' => 'gone on this site', '50' => 'pair them first', '51' => 'taken over as deleted', '52' => 'not in the inventory of the staging site', '53' => 'in the trash there' ) as $ref => $why ) {
			$entry = self::of( $r, 'post', (string) $ref );
			self::assertSame( 'waits', $entry['action'], $why );
			self::assertStringContainsString( $why, $entry['waits_for'][0]['why'] );
		}
		self::assertSame( array( 'post', 99, 'meta:_thumbnail_id' ), array( self::of( $r, 'post', '32' )['waits_for'][0]['space'], self::of( $r, 'post', '32' )['waits_for'][0]['id'], self::of( $r, 'post', '32' )['waits_for'][0]['at'] ) );
		self::assertSame( 'locked', self::of( $r, 'post', '40' )['action'] );
	}

	public function testObjectsThatNeedEachOtherToBeCreatedAreLocked(): void {
		$r = self::report(
			array(
				self::page( '34', 'a', 'a', array( 'refs' => array( self::ref( 'post', 35, 'post_content' ) ) ) ),
				self::page( '35', 'b', 'b', array( 'refs' => array( self::ref( 'post', 34, 'post_content' ) ) ) ),
				self::page( '36', 'needs-a', 'c', array( 'refs' => array( self::ref( 'post', 34, 'post_content' ) ) ) ),
				self::page( '37', 'self', 'd', array( 'refs' => array( self::ref( 'post', 37, 'post_content' ) ) ) ),
			),
			array()
		);
		foreach ( array( '34', '35', '37' ) as $ref ) {
			$entry = self::of( $r, 'post', $ref );
			self::assertSame( array( 'locked', array( '*:mutual_references' ) ), array( $entry['action'], self::keys( $entry ) ), $ref );
			self::assertSame( 'Z11c', $entry['locks'][0]['stage'] );
		}
		self::assertSame( 'waits', self::of( $r, 'post', '36' )['action'], 'What needs one of them waits.' );
		// An object that exists here refers to itself through its pair: no cycle.
		$entry = self::decided( array( self::page( '34', 'a', 's1', array( 'refs' => array( self::ref( 'post', 34, 'post_content' ) ) ) ) ), array( self::page( '12', 'a', 'l0' ) ), self::pair( 7, 'post', '12', '34' ) );
		self::assertSame( 'take_over', $entry['action'] );
	}

	public function testTheParentOfAnAttachmentIsAWeakReferenceThatWaitsButMakesNoCycle(): void {
		// Decision 16 (branch 10): a page that shows its image (strong) and
		// the image that hangs under the page (weak) are created in one run,
		// no mutual reference; the parent is set by a step of its own. The
		// weak reference waits where its target is held back, as a strong
		// one does, and where it has no target at all.
		$image = static fn( string $ref, string $file, int $parent ): array => self::line(
			'attachment',
			$ref,
			array(
				'file'      => $file,
				'post_type' => 'attachment',
			),
			self::h( $file ),
			array(
				'refs' => array(
					array(
						'at'       => 'post_parent',
						'id'       => $parent,
						'space'    => 'post',
						'strength' => 'weak',
					),
				),
			)
		);
		$r = self::report(
			array(
				$image( '50', '2026/10/a.jpg', 51 ),
				self::page( '51', 'galerie', 'g', array( 'refs' => array( self::ref( 'post', 50, 'meta:_thumbnail_id' ) ) ) ),
				$image( '52', '2026/10/b.jpg', 53 ),
				self::page( '53', 'gesperrt', 'h', array( 'locks' => array( array( 'key' => 'post:builder', 'stage' => 'Z8c', 'reason' => AB_MCP_Sync_Kinds::lock( 'post', 'builder' )['reason'], 'at' => 'meta' ) ) ) ),
				$image( '54', '2026/10/c.jpg', 99 ),
			),
			array()
		);
		self::assertSame( array( 'create', array() ), array( self::of( $r, 'attachment', '50' )['action'], self::keys( self::of( $r, 'attachment', '50' ) ) ), 'No cycle with the page.' );
		self::assertSame( 'create', self::of( $r, 'post', '51' )['action'] );
		self::assertSame( 'waits', self::of( $r, 'attachment', '52' )['action'], 'Its parent is held back.' );
		self::assertStringContainsString( 'held back itself', self::of( $r, 'attachment', '52' )['waits_for'][0]['why'] );
		self::assertSame( 'waits', self::of( $r, 'attachment', '54' )['action'], 'Its parent is not there.' );
		self::assertSame( array( 'post', 99, 'post_parent' ), array( self::of( $r, 'attachment', '54' )['waits_for'][0]['space'], self::of( $r, 'attachment', '54' )['waits_for'][0]['id'], self::of( $r, 'attachment', '54' )['waits_for'][0]['at'] ) );
	}

	public function testWaitingIsPassedOnAlongAChain(): void {
		$r = self::report(
			array(
				self::page( '34', 'a', 'a', array( 'refs' => array( self::ref( 'post', 35 ) ) ) ),
				self::page( '35', 'b', 'b', array( 'refs' => array( self::ref( 'post', 36 ) ) ) ),
				self::page( '36', 'c', 'c', array( 'refs' => array( self::ref( 'post', 99, 'meta:_thumbnail_id' ) ) ) ),
				self::page( '37', 'd', 'd', array( 'refs' => array( self::ref( 'post', 35 ) ) ) ),
			),
			array()
		);
		foreach ( array( '34', '35', '36', '37' ) as $ref ) {
			self::assertSame( 'waits', self::of( $r, 'post', $ref )['action'], $ref );
		}
		self::assertStringContainsString( 'held back itself', self::of( $r, 'post', '34' )['waits_for'][0]['why'] );
		self::assertSame( 35, self::of( $r, 'post', '34' )['waits_for'][0]['id'] );
	}

	public function testTheCyclesOfTheEdges(): void {
		self::assertSame( array(), AB_MCP_Sync_Decision::cycles( array( 1 => array( 2 => true ), 2 => array( 3 => true ) ) ) );
		self::assertSame( array( array( 1, 2, 3 ) ), AB_MCP_Sync_Decision::cycles( array( 1 => array( 2 => true ), 2 => array( 3 => true ), 3 => array( 1 => true ), 4 => array( 1 => true ) ) ) );
		self::assertSame( array( array( 5 ) ), AB_MCP_Sync_Decision::cycles( array( 5 => array( 5 => true ), 6 => array( 5 => true ) ) ) );
		$two = AB_MCP_Sync_Decision::cycles( array( 1 => array( 2 => true ), 2 => array( 1 => true ), 3 => array( 4 => true ), 4 => array( 3 => true ) ) );
		sort( $two );
		self::assertSame( array( array( 1, 2 ), array( 3, 4 ) ), $two );
	}

	/* ---------------------------------------------------------- the order */

	public function testTheEntriesAreNumberedChoicesAndTakeoversFirst(): void {
		$r = self::report(
			array( self::page( '34', 'a', 's1' ), self::page( '35', 'b', 's1' ), self::page( '36', 'c', 'n' ) ),
			array( self::page( '12', 'a', 'l1' ), self::page( '13', 'b', 'l0' ), self::page( '14', 'z', 'z' ) ),
			array( self::pair( 1, 'post', '12', '34' ), self::pair( 2, 'post', '13', '35' ) )
		);
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $r['entries'], 'n' ) );
		self::assertSame( array( 'choose', 'take_over', 'create', 'keep' ), array_column( $r['entries'], 'action' ) );
		self::assertSame( 4, $r['counts']['entries'] );
		self::assertSame( 1, $r['counts']['actions']['take_over'] );
		self::assertSame( array( 'changed_on_staging' => 1, 'conflict' => 1, 'only_live' => 1, 'only_staging' => 1 ), $r['counts']['results'] );
	}

	/* ------------------------------------------------------------ report */

	public function testWordPressOnStagingNewerThanHereIsRefusedWithTheWayOfAnUpdate(): void {
		$msg = self::refused( AB_MCP_Sync_Report::wordpress_refusal( '7.2', '7.1.3' ), 'ab_mcp_sync_report_wordpress' );
		self::assertStringContainsString( 'wp_update_core', $msg );
		self::assertStringContainsString( 'Direct link:', $msg );
		self::refused( AB_MCP_Sync_Report::wordpress_refusal( '7.2-beta1', '7.1.3' ), 'ab_mcp_sync_report_wordpress' );
		self::refused( AB_MCP_Sync_Report::wordpress_refusal( '7.1.4', '7.1.3' ), 'ab_mcp_sync_report_wordpress' );
		foreach ( array( array( '7.1.3', '7.1.3' ), array( '7.1', '7.1.3' ), array( '7.1.3', '7.2-beta1' ), array( '7.2-beta1', '7.2' ) ) as list( $staging, $live ) ) {
			self::assertNull( AB_MCP_Sync_Report::wordpress_refusal( $staging, $live ), "$staging on staging, $live here" );
		}
		foreach ( array( array( '', '7.1.3' ), array( '7.1.3', '' ), array( 'latest', '7.1.3' ) ) as list( $staging, $live ) ) {
			self::assertStringContainsString( 'cannot be read', self::refused( AB_MCP_Sync_Report::wordpress_refusal( $staging, $live ), 'ab_mcp_sync_report_wordpress' ) );
		}
	}

	public function testTheVersionOfWordPressOfASeriesIsTheHighestItsManifestsName(): void {
		$of = static fn( string ...$versions ): string => AB_MCP_Sync_Report::wordpress_of( array_map( static fn( $v ) => array( 'manifest' => array( 'wordpress' => $v ) ), $versions ) );
		self::assertSame( '7.1.3', $of( '7.1.3' ) );
		self::assertSame( '7.2', $of( '7.1.3', '7.2', '7.1.4' ), 'An update between two packages: the newer.' );
		self::assertSame( '7.2', $of( '7.2', '7.2-beta1' ) );
		self::assertSame( '', $of() );
	}

	public function testTheCheckBeforeNamesTheWayForANetworkThePairAndTheAnchor(): void {
		$GLOBALS['ab_test_multisite'] = true;
		$msg                          = self::refused( AB_MCP_Tools_Staging::site_diff( array( 'packages' => array( str_repeat( 'a', 32 ) ) ) ), 'ab_mcp_sync_report_multisite' );
		self::assertStringContainsString( 'Direct link:', $msg );
		$GLOBALS['ab_test_multisite'] = false;
		self::assertStringContainsString( 'confirm the role production site', self::refused( AB_MCP_Tools_Staging::site_diff( array( 'packages' => array( str_repeat( 'a', 32 ) ) ) ), 'ab_mcp_sync_report_pair' ) );
		$this->paired();
		self::assertStringContainsString( 'pairing code', self::refused( AB_MCP_Tools_Staging::site_diff( array( 'packages' => array( str_repeat( 'a', 32 ) ) ) ), 'ab_mcp_sync_report_no_anchor' ) );
		$code = $this->code();
		$this->on( 'live' );
		self::assertIsArray( \AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 ) );
		$msg = self::refused( AB_MCP_Tools_Staging::site_diff( array( 'packages' => array( str_repeat( 'a', 32 ) ) ) ), 'ab_mcp_sync_report_unconfirmed' );
		self::assertStringContainsString( 'fingerprint', $msg );
		self::assertStringContainsString( 'page=alphabridge-staging#ab-staging-trust', $msg, 'The link to the key on the page «Staging».' );
	}

	public function testAReportBeginsWithTheListOfPackagesOfOneSeries(): void {
		$this->paired();
		$this->anchored();
		foreach ( array( array(), array( 'packages' => array() ), array( 'packages' => 'x' ), array( 'packages' => array( 'a', 'a' ) ), array( 'packages' => array( 'k' => 'a' ) ), array( 'packages' => array_map( 'strval', range( 1, 101 ) ) ) ) as $a ) {
			self::assertStringContainsString( 'wp_site_import_chunk', self::refused( AB_MCP_Tools_Staging::site_diff( $a ), 'ab_mcp_sync_report_packages' ), (string) json_encode( $a ) );
		}
		$msg = self::refused( AB_MCP_Tools_Staging::site_diff( array( 'packages' => array( 'x' ) ) ), 'ab_mcp_sync_import_package' );
		self::assertStringContainsString( 'Direct link:', $msg, 'The refusal of a manifest with the way of the page.' );
		foreach ( array( 'x', str_repeat( 'g', 32 ), str_repeat( 'A', 32 ), 5 ) as $id ) {
			self::refused( AB_MCP_Tools_Staging::site_diff( array( 'report_id' => $id ) ), 'ab_mcp_sync_report_unknown' );
		}
	}

	public function testTheStagingSideIsCompleteForAKindWithEverySubtypeWhereTheSubtypeIsNotKnown(): void {
		$staging = array(
			'whole'    => array( 'post' => true ),
			'complete' => array( "post\0page" => true, "post\0post" => false ),
		);
		self::assertTrue( AB_MCP_Sync_Report::complete( $staging, 'post', 'page' ) );
		self::assertFalse( AB_MCP_Sync_Report::complete( $staging, 'post', 'post' ) );
		self::assertTrue( AB_MCP_Sync_Report::complete( $staging, 'post', 'wp_block' ), 'Named nowhere: none on staging.' );
		self::assertFalse( AB_MCP_Sync_Report::complete( $staging, 'post', null ), 'Not every subtype.' );
		$staging['complete']["post\0post"] = true;
		self::assertTrue( AB_MCP_Sync_Report::complete( $staging, 'post', null ) );
		self::assertFalse( AB_MCP_Sync_Report::complete( $staging, 'term', null ), 'A kind no package read whole.' );
	}

	public function testTheReportHashBindsTheHeadAndTheEntriesNotTheConnection(): void {
		$head  = array( 'canon' => 'canon/1', 'connection' => 'c1', 'created' => 1, 'expires' => 2, 'format' => AB_MCP_Sync_Report::FORMAT, 'packages' => array( 'p' ), 'print' => 'x', 'source_id' => 's', 'wordpress' => array( 'live' => '7.1.3', 'staging' => '7.1.3' ) );
		$index = array( 'counts' => array( 'entries' => 1 ), 'parts' => array( array( 'name' => 'e', 'sha' => self::h( 'e1' ), 'first' => 1, 'count' => 1 ) ) );
		$hash  = AB_MCP_Sync_Report::report_hash( $head, $index );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $hash );
		self::assertSame( $hash, AB_MCP_Sync_Report::report_hash( array( 'connection' => 'c2', 'created' => 9, 'expires' => 9 ) + $head, $index + array( 'report_hash' => 'old' ) ) );
		self::assertNotSame( $hash, AB_MCP_Sync_Report::report_hash( $head, array( 'parts' => array( array( 'sha' => self::h( 'e2' ) ) + $index['parts'][0] ) ) + $index ), 'Another part of the entries.' );
		self::assertNotSame( $hash, AB_MCP_Sync_Report::report_hash( array( 'packages' => array( 'q' ) ) + $head, $index ) );
		self::assertNotSame( $hash, AB_MCP_Sync_Report::report_hash( array( 'print' => 'y' ) + $head, $index ) );
	}

	/* -------------------------------------------------------------- tool */

	public function testItIsRegisteredAsAReadingToolOfTheStagingGroup(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		$def = $r->get( 'wp_site_diff' );
		self::assertNotNull( $def );
		self::assertSame( 'manage_options', $def['capability'] );
		self::assertTrue( $def['readonly'], 'Said in its definition.' );
		self::assertTrue( AB_MCP_Tool_Registry::is_read_only( 'wp_site_diff', $def ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_open_world( 'wp_site_diff', $def ) );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_site_diff', $def ) );
		self::assertFalse( AB_MCP_Staging_Gate::concerns( 'wp_site_diff', $def ), 'A reader stands outside «Staging first».' );
		self::assertArrayNotHasKey( 'confirm', $def );
		self::assertSame( array( 'packages', 'environment', 'report_id', 'cursor' ), array_keys( $def['inputSchema']['properties'] ) );
		foreach ( array( 'wp_update_core', 'multisite', 'Z8d', 'purpose=env', 'counted, contents not compared', 'never equal', 'Z8b for files and code, Z8d for tables', 'changed at the host', 'no constant that holds a secret', 'not compared, with the way', 'no object is deleted', 'as a whole', 'roles, Customizer changesets, options of plugins, theme mods other than the menu positions', 'creating additional CSS and creating terms with meta', 'mutual references', 'residual', '60 seconds', 'the first 200 entries', 'cursor', 'SHA-256 of its ref only', 'Needs manage_options' ) as $says ) {
			self::assertStringContainsString( $says, $def['description'] );
		}
		foreach ( array( 'Pro', 'Agency', 'upgrade' ) as $never ) {
			self::assertStringNotContainsString( $never, $def['description'], 'No edition, no advertising.' );
		}
		self::assertSame( 'Compare with the staging site', AB_MCP_Tool_Labels::all()['wp_site_diff'] );
	}
}
