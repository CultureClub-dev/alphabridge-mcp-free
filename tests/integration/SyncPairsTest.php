<?php
/**
 * The object pairs on real rows (AB_MCP_Sync_Pairs, Z8 decisions 3, 10, 14
 * and 23 of the plan):
 *
 * - a pair is found by its id and by each of its refs, byte for byte;
 * - a second pair on a ref is refused by name, before any insert, and
 *   when another request wrote it in between, by the unique key;
 * - the pairs of a source and a kind come a page at a time;
 * - the state changes only along the allowed ways; out of residual and
 *   unclear only the confirmed new alignment leads, deleted is not reached;
 * - every write names the row it read and is made only on those bytes:
 *   a change in between, also one the collation does not see (abc → ABC,
 *   a → a ), leaves the row as the other side wrote it;
 * - the snapshot for steps.before and the conditional restore from it;
 * - the canon change with a test canonicalizer: equal forms give new
 *   bases, other forms unclear; residual and unclear pairs stay as they are;
 * - base_extra up to its bound stored byte for byte.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use WP_Error;
use wpdb;

final class SyncPairsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	protected function setUp(): void {
		// Only the tables of the takeover are written here: made anew for
		// each test, the rest of site A stays as reset() left it.
		AB_MCP_Sync_Schema::uninstall();
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_Sync_Schema::forget();
	}

	private static function h( string $text ): string {
		return hash( 'sha256', $text );
	}

	/** The fields of a valid pair, with some replaced. */
	private static function fields( array $over = array() ): array {
		return array_merge(
			array(
				'source_id'    => 'src-1',
				'kind'         => 'post',
				'live_ref'     => '12',
				'staging_ref'  => '34',
				'canon'        => 'canon/1',
				'base_live'    => self::h( 'live' ),
				'base_staging' => self::h( 'staging' ),
				'base_origin'  => 'proposal',
			),
			$over
		);
	}

	private static function make( array $over = array() ): array {
		$pair = AB_MCP_Sync_Pairs::create( self::fields( $over ) );
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		return $pair;
	}

	/** The snapshot hash of a pair now, as a caller that read it holds it. */
	private static function now( int $id ): string {
		$snapshot = AB_MCP_Sync_Pairs::snapshot( $id );
		self::assertIsString( $snapshot );
		return AB_MCP_Sync_Pairs::snapshot_hash( $snapshot );
	}

	private static function row( int $id ): ?array {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs', array( 'id' => $id ) );
		return array() === $rows ? null : $rows[0];
	}

	private static function assertError( string $code, $answer, string $message = '' ): WP_Error {
		self::assertInstanceOf( WP_Error::class, $answer, $message );
		self::assertSame( $code, $answer->get_error_code(), $message . ' ' . $answer->get_error_message() );
		return $answer;
	}

	/** Every query of $wpdb while $run runs. */
	private static function queries( callable $run ): array {
		$seen   = array();
		$filter = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$run();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $seen;
	}

	/**
	 * Run $sql on a connection of its own the moment before $wpdb sends a
	 * statement that starts with $before: another request that writes
	 * between the read and the write.
	 */
	private static function between( string $before, string $sql ): void {
		$other        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$done         = false;
		self::$filter = static function ( $query ) use ( $before, $sql, $other, &$done ) {
			if ( ! $done && 0 === strpos( (string) $query, $before ) ) {
				$done = true;
				$other->query( $sql );
			}
			return $query;
		};
		add_filter( 'query', self::$filter );
	}

	/** The other request is done. */
	private static function stop_between(): void {
		if ( null !== self::$filter ) {
			remove_filter( 'query', self::$filter );
			self::$filter = null;
		}
	}

	/** @var callable|null The filter of between(). */
	private static $filter = null;

	protected function tearDown(): void {
		self::stop_between();
	}

	/* ------------------------------------------------------------- read */

	public function testAPairIsFoundByItsIdAndEachRefByteForByte(): void {
		$pair = self::make(
			array(
				'override'   => array( 'post_status' => 'draft' ),
				'base_extra' => array( 'b' => 2, 'a' => array( 'y', 'x' ) ),
				'last_run'   => 4,
			)
		);
		self::assertSame( $pair, AB_MCP_Sync_Pairs::get( $pair['id'] ) );
		self::assertSame( $pair, AB_MCP_Sync_Pairs::find_live( 'src-1', 'post', '12' ) );
		self::assertSame( $pair, AB_MCP_Sync_Pairs::find_staging( 'src-1', 'post', '34' ) );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-1', 'post', '34' ), 'The refs are of their side.' );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-1', 'term', '12' ) );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-2', 'post', '12' ) );
		self::assertNull( AB_MCP_Sync_Pairs::get( $pair['id'] + 1 ) );
		self::assertSame( array( 'post_status' => 'draft' ), $pair['override'] );
		self::assertSame( 4, $pair['last_run'] );

		$row = self::row( $pair['id'] );
		self::assertSame( '{"post_status":"draft"}', $row['override'] );
		self::assertSame( '{"a":["y","x"],"b":2}', $row['base_extra'], 'Stored canonical: keys sorted, lists in order.' );
		self::assertGreaterThan( 0, (int) $row['updated'] );

		// The table's collation takes these for the same; the pairs do not.
		$area = self::make(
			array(
				'kind'        => 'widget_area',
				'live_ref'    => 'Sidebar-1',
				'staging_ref' => 'sidebar-1',
			)
		);
		self::assertSame( $area['id'], AB_MCP_Sync_Pairs::find_live( 'src-1', 'widget_area', 'Sidebar-1' )['id'] );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-1', 'widget_area', 'sidebar-1' ) );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-1', 'widget_area', 'Sidebar-1 ' ) );
		self::assertNull( AB_MCP_Sync_Pairs::find_staging( 'src-1', 'widget_area', 'SIDEBAR-1' ) );
	}

	public function testThePairsOfASourceAndKindComeAPageAtATime(): void {
		$ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = self::make( array( 'live_ref' => "l$i", 'staging_ref' => "s$i" ) )['id'];
		}
		self::make( array( 'kind' => 'term', 'live_ref' => 'l1', 'staging_ref' => 's1' ) );
		self::make( array( 'source_id' => 'src-2', 'live_ref' => 'l1', 'staging_ref' => 's1' ) );

		$seen  = array();
		$after = 0;
		$pages = 0;
		do {
			$page = AB_MCP_Sync_Pairs::page( 'src-1', 'post', $after, 2 );
			self::assertIsArray( $page );
			self::assertLessThanOrEqual( 2, count( $page['pairs'] ) );
			$seen  = array_merge( $seen, array_column( $page['pairs'], 'id' ) );
			$after = $page['next'];
			++$pages;
		} while ( null !== $after );
		self::assertSame( $ids, $seen );
		self::assertSame( 3, $pages );
		self::assertCount( 1, AB_MCP_Sync_Pairs::page( 'src-1', 'term' )['pairs'] );
		self::assertSame( array( 'pairs' => array(), 'next' => null ), AB_MCP_Sync_Pairs::page( 'src-3', 'post' ) );
		self::assertNull( AB_MCP_Sync_Pairs::page( 'src-1', 'post', 0, 5000 )['next'], 'At most PER_PAGE, and here fewer.' );
		self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::page( 'SRC', 'post' ) );
	}

	/* -------------------------------------------------------- conflicts */

	public function testASecondPairOnARefIsRefusedByNameBeforeAnyInsert(): void {
		$first  = self::make();
		$before = self::row( $first['id'] );
		$cases  = array(
			'live'             => array( array( 'staging_ref' => '99' ), 'live_ref', 'bytes' ),
			'staging'          => array( array( 'live_ref' => '99' ), 'staging_ref', 'bytes' ),
			'live, collation'  => array( array( 'kind' => 'post', 'live_ref' => '12 ', 'staging_ref' => '99' ), 'live_ref', 'collation' ),
		);
		foreach ( $cases as $case => list( $over, $key, $same ) ) {
			$answer = null;
			$asked  = self::queries(
				static function () use ( $over, &$answer ) {
					$answer = AB_MCP_Sync_Pairs::create( self::fields( $over ) );
				}
			);
			$error = self::assertError( 'ab_mcp_staging_pair_conflict', $answer, $case );
			self::assertSame( array( 'key' => $key, 'pair' => $first['id'], 'same' => $same ), $error->get_error_data(), $case );
			self::assertSame( array(), preg_grep( '/^INSERT/i', $asked ), $case . ': refused before the insert.' );
		}
		// Collation, on a ref whose case differs.
		$area = self::make( array( 'kind' => 'widget_area', 'live_ref' => 'abc', 'staging_ref' => 'abc' ) );
		$case = AB_MCP_Sync_Pairs::create( self::fields( array( 'kind' => 'widget_area', 'live_ref' => 'ABC', 'staging_ref' => 'other' ) ) );
		self::assertSame( array( 'key' => 'live_ref', 'pair' => $area['id'], 'same' => 'collation' ), self::assertError( 'ab_mcp_staging_pair_conflict', $case )->get_error_data() );

		self::assertSame( $before, self::row( $first['id'] ), 'The first pair is as it was.' );
		self::assertCount( 2, AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
	}

	public function testAPairAnotherRequestWroteInBetweenIsRefusedByTheUniqueKey(): void {
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		self::between( 'INSERT INTO `' . $table . '`', "INSERT INTO `$table` (source_id, kind, live_ref, staging_ref, canon) VALUES ('src-1', 'post', '12', '77', 'canon/1')" );
		$answer = AB_MCP_Sync_Pairs::create( self::fields() );
		$other  = AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' );
		self::assertCount( 1, $other, 'Only the pair of the other request.' );
		self::assertSame( '77', $other[0]['staging_ref'] );
		self::assertSame( array( 'key' => 'live_ref', 'pair' => (int) $other[0]['id'], 'same' => 'bytes' ), self::assertError( 'ab_mcp_staging_pair_conflict', $answer )->get_error_data() );
	}

	public function testAPairWithoutItsFieldsOrWithWrongOnesIsNotMade(): void {
		$cases = array(
			'no source'     => array( array(), 'source_id' ),
			'upper source'  => array( array( 'source_id' => 'SRC' ), 'source_id' ),
			'kind'          => array( array( 'kind' => 'Post' ), 'kind' ),
			'empty ref'     => array( array( 'live_ref' => '' ), 'live_ref' ),
			'long ref'      => array( array( 'live_ref' => str_repeat( 'ä', 192 ) ), 'live_ref' ),
			'control'       => array( array( 'staging_ref' => "a\nb" ), 'staging_ref' ),
			'state'         => array( array( 'state' => 'reserved' ), 'state' ),
			'canon'         => array( array( 'canon' => 'canon/0' ), 'canon' ),
			'hash'          => array( array( 'base_live' => strtoupper( self::h( 'x' ) ) ), 'base_live' ),
			'origin'        => array( array( 'base_origin' => 'manual' ), 'base_origin' ),
			'time'          => array( array( 'base_time' => -1 ), 'base_time' ),
			'run'           => array( array( 'last_run' => 0 ), 'last_run' ),
			'unknown field' => array( array( 'id' => 5 ), 'id' ),
			'extra'         => array( array( 'base_extra' => array( 0.5 ) ), 'base_extra' ),
		);
		foreach ( $cases as $case => list( $over, $field ) ) {
			$fields = self::fields( $over );
			if ( 'no source' === $case ) {
				unset( $fields['source_id'] );
			}
			$error = self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::create( $fields ), $case );
			self::assertSame( array( 'field' => $field ), $error->get_error_data(), $case );
		}
		self::assertIsArray( AB_MCP_Sync_Pairs::create( self::fields( array( 'live_ref' => str_repeat( 'ä', 191 ) ) ) ), '191 characters fit.' );
		$deleted = self::assertError( 'ab_mcp_staging_pair_deleted', AB_MCP_Sync_Pairs::create( self::fields( array( 'state' => 'deleted', 'live_ref' => 'x' ) ) ) );
		self::assertSame( array( 'stage' => 'Z8d' ), $deleted->get_error_data(), 'No pair is taken over as deleted in Z8.' );
		self::assertCount( 1, AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) );
	}

	/* --------------------------------------------------- state changes */

	public function testTheStateChangesOnlyAlongTheAllowedWays(): void {
		$pair = self::make();
		$id   = $pair['id'];

		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::set_state( $id, 'unclear', self::now( $id ) )['state'] );
		$refused = self::assertError( 'ab_mcp_staging_pair_transition', AB_MCP_Sync_Pairs::set_state( $id, 'active', self::now( $id ) ) );
		self::assertSame( array( 'from' => 'unclear', 'to' => 'active' ), $refused->get_error_data() );
		self::assertStringContainsString( 'confirmed new alignment', $refused->get_error_message() );

		self::assertSame( 'residual', AB_MCP_Sync_Pairs::set_state( $id, 'residual', self::now( $id ) )['state'] );
		foreach ( array( 'active', 'unclear', 'deleted' ) as $to ) {
			$error = self::assertError( 'ab_mcp_staging_pair_transition', AB_MCP_Sync_Pairs::set_state( $id, $to, self::now( $id ) ), "residual > $to" );
			self::assertSame( 'residual', $error->get_error_data()['from'] );
		}
		self::assertSame( 'residual', self::row( $id )['state'] );

		// Asking for the state it has is no change, and writes nothing.
		$row = self::row( $id );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::set_state( $id, 'residual', self::now( $id ) )['state'] );
		self::assertSame( $row, self::row( $id ) );

		self::assertError( 'ab_mcp_staging_pair_transition', AB_MCP_Sync_Pairs::set_state( self::make( array( 'live_ref' => 'a', 'staging_ref' => 'a' ) )['id'], 'deleted', self::now( $id + 1 ) ), 'active > deleted' );
		self::assertError( 'ab_mcp_staging_pair_missing', AB_MCP_Sync_Pairs::set_state( 999, 'unclear', self::now( $id ) ) );
	}

	public function testOnlyAnActivePairTakesNewBases(): void {
		$pair = self::make();
		$id   = $pair['id'];
		$new  = array(
			'canon'        => 'canon/1',
			'base_live'    => self::h( 'live 2' ),
			'base_staging' => self::h( 'staging 2' ),
			'base_origin'  => 'push',
			'override'     => array( 'post_status' => 'draft' ),
			'last_run'     => 9,
		);
		$done = AB_MCP_Sync_Pairs::rebase( $id, $new, self::now( $id ) );
		self::assertSame( self::h( 'live 2' ), $done['base_live'] );
		self::assertSame( 'push', $done['base_origin'] );
		self::assertSame( array( 'post_status' => 'draft' ), $done['override'] );
		self::assertSame( 9, $done['last_run'] );
		self::assertNull( $done['base_extra'], 'Left as it was.' );

		foreach ( array( 'unclear', 'residual' ) as $state ) {
			AB_MCP_Sync_Pairs::set_state( $id, $state, self::now( $id ) );
			$row   = self::row( $id );
			$error = self::assertError( 'ab_mcp_staging_pair_not_active', AB_MCP_Sync_Pairs::rebase( $id, $new, self::now( $id ) ), $state );
			self::assertSame( array( 'state' => $state ), $error->get_error_data() );
			self::assertSame( $row, self::row( $id ), $state . ': rule 0, nothing taken over for it.' );
		}
		self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::rebase( self::make( array( 'live_ref' => 'a', 'staging_ref' => 'a' ) )['id'], array( 'canon' => 'canon/1' ), self::now( $id + 1 ) ) );
	}

	public function testOutOfResidualOnlyTheConfirmedNewAlignmentLeads(): void {
		// A widget area left residual with an instance the run made: its
		// mapping in base_extra stays until the confirmation, and after it.
		$area = self::make(
			array(
				'kind'        => 'widget_area',
				'live_ref'    => 'sidebar-1',
				'staging_ref' => 'sidebar-1',
				'base_extra'  => array( 'instances' => array( 'text-3' => 7 ) ),
			)
		);
		$id = $area['id'];
		AB_MCP_Sync_Pairs::set_state( $id, 'residual', self::now( $id ) );
		$values = array(
			'canon'        => 'canon/1',
			'base_live'    => self::h( 'live new' ),
			'base_staging' => self::h( 'staging new' ),
		);

		self::assertError( 'ab_mcp_staging_pair_unconfirmed', AB_MCP_Sync_Pairs::realign( $id, $values, self::now( $id ), 1 ), 'true and nothing else' );
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::realign( $id, $values, self::h( 'stale' ), true ) );
		self::assertSame( 'residual', self::row( $id )['state'] );

		$done = AB_MCP_Sync_Pairs::realign( $id, $values, self::now( $id ), true );
		self::assertSame( 'active', $done['state'] );
		self::assertSame( 'proposal', $done['base_origin'] );
		self::assertSame( self::h( 'live new' ), $done['base_live'] );
		self::assertSame( self::h( 'staging new' ), $done['base_staging'] );
		self::assertSame( array( 'instances' => array( 'text-3' => 7 ) ), $done['base_extra'], 'The instance stays mapped.' );

		// From unclear as well, base_extra replaced where given.
		AB_MCP_Sync_Pairs::set_state( $id, 'unclear', self::now( $id ) );
		$again = AB_MCP_Sync_Pairs::realign( $id, $values + array( 'base_extra' => array( 'instances' => array( 'text-3' => 7, 'block-2' => 8 ) ) ), self::now( $id ), true );
		self::assertSame( 'active', $again['state'] );
		self::assertSame( array( 'instances' => array( 'block-2' => 8, 'text-3' => 7 ) ), $again['base_extra'] );

		// A row stored as deleted (by a later version) is not realigned here.
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'state' => 'deleted' ), array( 'id' => $id ) );
		self::assertError( 'ab_mcp_staging_pair_transition', AB_MCP_Sync_Pairs::realign( $id, $values, self::now( $id ), true ) );
	}

	/* ------------------------------------------------ conditional writes */

	public function testAWriteNeedsTheRowAsTheCallerReadIt(): void {
		$pair = self::make();
		$id   = $pair['id'];
		$read = self::now( $id );

		// Another side changed it since: the hash the caller holds is old.
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'base_origin' => 'clone' ), array( 'id' => $id ) );
		$row = self::row( $id );
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::set_state( $id, 'unclear', $read ) );
		self::assertSame( $row, self::row( $id ), 'Not overwritten.' );
		self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::set_state( $id, 'unclear', 'nothing' ) );
	}

	public function testAChangeBetweenTheReadAndTheWriteIsSeenByItsBytes(): void {
		// Between reading the row and writing it, another request changes a
		// ref in a way the collation of the table does not see (decision 23
		// and its test: abc → ABC, a → a ). The write is not made, and the
		// row keeps the other request's bytes.
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		foreach ( array( 'case' => array( 'abc', 'ABC' ), 'space' => array( 'a', 'a ' ), 'other' => array( 'x', 'y' ) ) as $case => list( $from, $to ) ) {
			$pair = self::make( array( 'kind' => 'widget_area', 'live_ref' => 'area-' . $case, 'staging_ref' => $from ) );
			$id   = $pair['id'];
			self::between( 'UPDATE ' . $table . ' SET', "UPDATE `$table` SET staging_ref = '$to' WHERE id = $id" );
			$answer = AB_MCP_Sync_Pairs::set_state( $id, 'unclear', self::now( $id ) );
			self::stop_between();
			self::assertError( 'ab_mcp_staging_pair_changed', $answer, $case );
			$row = self::row( $id );
			self::assertSame( $to, $row['staging_ref'], $case );
			self::assertSame( 'active', $row['state'], $case . ': the state was not written over the other change.' );
		}
	}

	public function testDiscardRemovesThePairOnlyAsItWasConfirmed(): void {
		$pair = self::make();
		$id   = $pair['id'];
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::discard( $id, self::h( 'stale' ) ) );
		self::assertNotNull( self::row( $id ) );
		self::assertTrue( AB_MCP_Sync_Pairs::discard( $id, self::now( $id ) ) );
		self::assertNull( self::row( $id ) );
		self::assertNull( AB_MCP_Sync_Pairs::get( $id ) );
	}

	/* ------------------------------------------- snapshot (steps.before) */

	public function testTheSnapshotHoldsEveryColumnButIdAndUpdatedStably(): void {
		$pair     = self::make( array( 'override' => array( 'post_status' => 'draft' ), 'base_extra' => array( 'z' => 1, 'a' => 2 ) ) );
		$snapshot = AB_MCP_Sync_Pairs::snapshot( $pair['id'] );
		self::assertSame( $snapshot, AB_MCP_Sync_Pairs::snapshot( $pair['id'] ), 'The same row, the same bytes.' );
		$data = json_decode( $snapshot, true );
		self::assertSame( array( 'columns', 'format' ), array_keys( $data ) );
		self::assertSame( 1, $data['format'] );
		self::assertSame( AB_MCP_Sync_Pairs::SNAPSHOT_COLUMNS, array_keys( $data['columns'] ), 'Sorted, without id and updated.' );
		self::assertSame( '{"a":2,"z":1}', $data['columns']['base_extra'], 'As stored.' );
		self::assertSame( hash( 'sha256', $snapshot ), AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) );

		// Only updated changes: the snapshot stays.
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'updated' => 5 ), array( 'id' => $pair['id'] ) );
		self::assertSame( $snapshot, AB_MCP_Sync_Pairs::snapshot( $pair['id'] ) );
		self::assertError( 'ab_mcp_staging_pair_missing', AB_MCP_Sync_Pairs::snapshot( $pair['id'] + 9 ) );
	}

	public function testRestorePutsThePairBackOnlyWhenNobodyChangedItSince(): void {
		$pair   = self::make( array( 'base_extra' => array( 'items' => array( 1, 2 ) ) ) );
		$id     = $pair['id'];
		$before = AB_MCP_Sync_Pairs::snapshot( $id );

		// The step writes the pair (new bases, an override), then its way back.
		$after   = AB_MCP_Sync_Pairs::rebase(
			$id,
			array(
				'canon'        => 'canon/1',
				'base_live'    => self::h( 'target' ),
				'base_staging' => self::h( 'staging 2' ),
				'base_origin'  => 'push',
				'override'     => array( 'post_status' => 'draft' ),
				'base_extra'   => array( 'items' => array( 1, 2, 3 ) ),
				'last_run'     => 3,
			),
			AB_MCP_Sync_Pairs::snapshot_hash( $before )
		);
		$written = self::now( $id );
		self::assertNotSame( AB_MCP_Sync_Pairs::snapshot_hash( $before ), $written );

		$back = AB_MCP_Sync_Pairs::restore( $id, $before, $written );
		self::assertIsArray( $back );
		self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( $id ), 'Every column as it was before the step.' );
		self::assertGreaterThan( $after['updated'], $back['updated'] );
		self::assertNull( $back['override'] );
		self::assertNull( $back['last_run'] );

		// Again with the hash of the state the step left: that is gone now.
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::restore( $id, $before, $written ) );

		// A change by another side after the step: the restore leaves it.
		$step = AB_MCP_Sync_Pairs::rebase( $id, array( 'canon' => 'canon/1', 'base_live' => self::h( 't' ), 'base_staging' => self::h( 's' ), 'base_origin' => 'push' ), self::now( $id ) );
		$left = self::now( $id );
		global $wpdb;
		$wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'base_live' => self::h( 'someone else' ) ), array( 'id' => $id ) );
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::restore( $id, $before, $left ) );
		self::assertSame( self::h( 'someone else' ), self::row( $id )['base_live'] );
		self::assertSame( $step['base_staging'], self::row( $id )['base_staging'] );
	}

	public function testRestoreSeesAChangeBetweenItsReadAndItsWriteByTheBytes(): void {
		$table  = AB_MCP_Sync_Schema::table( 'pairs' );
		$pair   = self::make( array( 'kind' => 'widget_area', 'live_ref' => 'area', 'staging_ref' => 'a' ) );
		$id     = $pair['id'];
		$before = AB_MCP_Sync_Pairs::snapshot( $id );
		AB_MCP_Sync_Pairs::set_state( $id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( $before ) );
		self::between( 'UPDATE ' . $table . ' SET', "UPDATE `$table` SET staging_ref = 'a ' WHERE id = $id" );
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::restore( $id, $before, self::now( $id ) ) );
		self::stop_between();
		self::assertSame( 'unclear', self::row( $id )['state'], 'Not put back over the other change.' );
		self::assertSame( 'a ', self::row( $id )['staging_ref'] );
	}

	public function testRestoreNeitherRemovesAPairNorEndsResidualNorTakesAForeignSnapshot(): void {
		$pair   = self::make();
		$id     = $pair['id'];
		$before = AB_MCP_Sync_Pairs::snapshot( $id );

		// A pair the run made has no snapshot before it: it is never removed.
		foreach ( array( null, '' ) as $none ) {
			self::assertError( 'ab_mcp_staging_pair_no_before', AB_MCP_Sync_Pairs::restore( $id, $none, self::now( $id ) ) );
		}
		self::assertNotNull( self::row( $id ) );

		// Rule 0: residual ends only by the confirmed new alignment.
		AB_MCP_Sync_Pairs::set_state( $id, 'residual', self::now( $id ) );
		$error = self::assertError( 'ab_mcp_staging_pair_transition', AB_MCP_Sync_Pairs::restore( $id, $before, self::now( $id ) ) );
		self::assertSame( array( 'from' => 'residual', 'to' => 'restore' ), $error->get_error_data() );
		self::assertSame( 'residual', self::row( $id )['state'] );

		// The snapshot of another pair, a broken one, one with deleted.
		$other = self::make( array( 'live_ref' => '13', 'staging_ref' => '35' ) );
		$oid   = $other['id'];
		self::assertSame( array( 'column' => 'live_ref' ), self::assertError( 'ab_mcp_staging_pair_snapshot_foreign', AB_MCP_Sync_Pairs::restore( $oid, $before, self::now( $oid ) ) )->get_error_data() );
		$broken = json_decode( AB_MCP_Sync_Pairs::snapshot( $oid ), true );
		foreach ( array(
			'not json'     => 'nonsense',
			'format'       => wp_json_encode( array_merge( $broken, array( 'format' => 2 ) ) ),
			'a column'     => wp_json_encode( array( 'columns' => array_diff_key( $broken['columns'], array( 'canon' => 1 ) ), 'format' => 1 ) ),
			'extra json'   => wp_json_encode( array( 'columns' => array_merge( $broken['columns'], array( 'base_extra' => '{"b":1,"a":2}' ) ), 'format' => 1 ) ),
		) as $case => $snapshot ) {
			self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::restore( $oid, $snapshot, self::now( $oid ) ), $case );
		}
		$deleted = wp_json_encode( array( 'columns' => array_merge( $broken['columns'], array( 'state' => 'deleted' ) ), 'format' => 1 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		self::assertError( 'ab_mcp_staging_pair_deleted', AB_MCP_Sync_Pairs::restore( $oid, $deleted, self::now( $oid ) ) );
	}

	/* ------------------------------------------------- canon change (10) */

	public function testACanonChangeTakesNewBasesForEqualFormsAndMarksTheOthersUnclear(): void {
		$equal     = self::make( array( 'live_ref' => 'e', 'staging_ref' => 'e', 'base_origin' => 'clone', 'override' => array( 'post_status' => 'draft' ) ) );
		$different = self::make( array( 'live_ref' => 'd', 'staging_ref' => 'd' ) );
		$gone      = self::make( array( 'live_ref' => 'g', 'staging_ref' => 'g' ) );
		$residual  = self::make( array( 'live_ref' => 'r', 'staging_ref' => 'r' ) );
		$unclear   = self::make( array( 'live_ref' => 'u', 'staging_ref' => 'u' ) );
		$current   = self::make( array( 'live_ref' => 'c', 'staging_ref' => 'c', 'canon' => 'canon/2' ) );
		AB_MCP_Sync_Pairs::set_state( $residual['id'], 'residual', self::now( $residual['id'] ) );
		AB_MCP_Sync_Pairs::set_state( $unclear['id'], 'unclear', self::now( $unclear['id'] ) );

		// The test version of the canonicalizer: the forms in canon/2, by ref.
		$asked = array();
		$forms = static function ( array $pair, string $canon ) use ( &$asked ) {
			$asked[] = array( $pair['live_ref'], $canon, $pair['override'] );
			$l       = hash( 'sha256', 'L2 ' . $pair['live_ref'] );
			$s       = hash( 'sha256', 'S2 ' . $pair['live_ref'] );
			switch ( $pair['live_ref'] ) {
				case 'e':
					return array( 'live' => $l, 'staging' => $s, 'target' => $l );
				case 'd':
					return array( 'live' => $l, 'staging' => $s, 'target' => hash( 'sha256', 'other' ) );
				case 'g':
					return array( 'live' => null, 'staging' => $s, 'target' => $l );
			}
			return new WP_Error( 'ab_test_unreadable', 'Not readable.' );
		};

		$old = array();
		foreach ( array( $equal, $different, $gone, $residual, $unclear, $current ) as $pair ) {
			$old[ $pair['live_ref'] ] = self::row( $pair['id'] );
		}

		self::assertSame( 'rebased', AB_MCP_Sync_Pairs::change_canon( $equal['id'], 'canon/2', $forms ) );
		$row = self::row( $equal['id'] );
		self::assertSame( 'canon/2', $row['canon'] );
		self::assertSame( hash( 'sha256', 'L2 e' ), $row['base_live'] );
		self::assertSame( hash( 'sha256', 'S2 e' ), $row['base_staging'] );
		self::assertSame( 'active', $row['state'] );
		self::assertSame( 'clone', $row['base_origin'], 'The base is the same one, in the new version.' );
		self::assertSame( '{"post_status":"draft"}', $row['override'], 'The confirmed override stays, and the canonicalizer saw it.' );
		self::assertSame( array( 'e', 'canon/2', array( 'post_status' => 'draft' ) ), $asked[0] );

		foreach ( array( 'd' => $different, 'g' => $gone ) as $ref => $pair ) {
			self::assertSame( 'unclear', AB_MCP_Sync_Pairs::change_canon( $pair['id'], 'canon/2', $forms ), $ref );
			$row = self::row( $pair['id'] );
			self::assertSame( 'unclear', $row['state'], $ref );
			foreach ( array( 'canon', 'base_live', 'base_staging', 'base_time' ) as $column ) {
				self::assertSame( $old[ $ref ][ $column ], $row[ $column ], "$ref: $column stays for the person who confirms." );
			}
		}

		$calls = count( $asked );
		self::assertSame( 'skipped', AB_MCP_Sync_Pairs::change_canon( $residual['id'], 'canon/2', $forms ), 'Rule 0.' );
		self::assertSame( 'skipped', AB_MCP_Sync_Pairs::change_canon( $unclear['id'], 'canon/2', $forms ) );
		self::assertSame( 'current', AB_MCP_Sync_Pairs::change_canon( $current['id'], 'canon/2', $forms ) );
		self::assertCount( $calls, $asked, 'Not even read.' );
		foreach ( array( 'r' => $residual, 'u' => $unclear, 'c' => $current ) as $ref => $pair ) {
			self::assertSame( $old[ $ref ], self::row( $pair['id'] ), $ref );
		}

		// A canonicalizer that cannot read: its error, the pair as it was.
		$unread = self::make( array( 'live_ref' => 'x', 'staging_ref' => 'x' ) );
		$row    = self::row( $unread['id'] );
		self::assertError( 'ab_test_unreadable', AB_MCP_Sync_Pairs::change_canon( $unread['id'], 'canon/2', $forms ) );
		self::assertSame( $row, self::row( $unread['id'] ) );
		self::assertError( 'ab_mcp_staging_pair_invalid', AB_MCP_Sync_Pairs::change_canon( $unread['id'], 'canon-2', $forms ) );
	}

	public function testACanonChangeWhileAnotherRequestWritesThePairLeavesThatWrite(): void {
		global $wpdb;
		$pair  = self::make();
		$id    = $pair['id'];
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		$forms = static function ( array $pair ) use ( $wpdb, $table, $id ) {
			// The canonicalizer takes its time; meanwhile a step writes the pair.
			$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET base_origin = 'push' WHERE id = %d", $id ) );
			$l = hash( 'sha256', 'L' );
			return array( 'live' => $l, 'staging' => hash( 'sha256', 'S' ), 'target' => $l );
		};
		self::assertError( 'ab_mcp_staging_pair_changed', AB_MCP_Sync_Pairs::change_canon( $id, 'canon/2', $forms ) );
		$row = self::row( $id );
		self::assertSame( 'push', $row['base_origin'] );
		self::assertSame( 'canon/1', $row['canon'] );
	}

	/* ----------------------------------------------------- base_extra */

	public function testBaseExtraUpToItsBoundIsStoredByteForByte(): void {
		$max  = AB_MCP_Sync_Pairs::MAX_EXTRA;
		$pair = self::make( array( 'base_extra' => array( str_repeat( 'é', ( $max - 4 ) / 2 ) ) ) );
		$row  = self::row( $pair['id'] );
		self::assertSame( $max, strlen( (string) $row['base_extra'] ) );
		self::assertSame( array( str_repeat( 'é', ( $max - 4 ) / 2 ) ), $pair['base_extra'] );

		$over = AB_MCP_Sync_Pairs::create( self::fields( array( 'live_ref' => 'o', 'staging_ref' => 'o', 'base_extra' => array( str_repeat( 'x', $max - 3 ) ) ) ) );
		self::assertError( 'ab_mcp_staging_pair_invalid', $over );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( 'src-1', 'post', 'o' ) );
	}
}
