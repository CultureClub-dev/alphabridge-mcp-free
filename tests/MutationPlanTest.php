<?php
/**
 * The sharing of the mutation entries among the jobs of the CI by their
 * measured durations (tests/mutation/verteilung.php), the measure file
 * (tests/mutation/dauer.php) and its import from the log of a CI run; then
 * tests/mutation/run.php --shard itself, on a tree of its own in the
 * temporary directory.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_Mutation_Plan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/tests/mutation/verteilung.php';

final class MutationPlanTest extends TestCase {

	private const SOURCE = '78708fba2a84321e7f65df9198d2c2b638440f82';

	private const BASE = 'e6220a653b1b63d9e44d74303b6177c3296efbe4';

	/** @var string[] Folders to remove after the test. */
	private array $remove = array();

	protected function tearDown(): void {
		foreach ( $this->remove as $dir ) {
			self::rm( $dir );
		}
	}

	private static function rm( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::rm( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}

	/* ------------------------------------------------------------ fixtures */

	private static function unit( string $filter ): array {
		return array( 'vendor/bin/phpunit', '--no-progress', '--filter', $filter );
	}

	private static function it( string $filter ): array {
		return array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', $filter );
	}

	/**
	 * Entries of several commands and durations with many ties: two lists.
	 *
	 * @return array{0:array[],1:array[]}
	 */
	private static function lists(): array {
		$a = array();
		$b = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$a[] = array( 'id' => sprintf( 'a/%02d', $i ), 'run' => self::unit( 'T' . ( $i % 7 ) ) );
		}
		for ( $i = 0; $i < 30; $i++ ) {
			$b[] = array( 'id' => sprintf( 'b/%02d', $i ), 'run' => 0 === $i % 2 ? self::it( 'T' . ( $i % 5 ) ) : self::unit( 'U' . ( $i % 3 ) ) );
		}
		return array( $a, $b );
	}

	/**
	 * Durations for the entries of lists(): many equal, some missing.
	 *
	 * @param bool $gaps Leave some out.
	 * @return array
	 */
	private static function measures( bool $gaps ): array {
		list( $a, $b ) = self::lists();
		$m             = array(
			'herkunft'   => array(),
			'mutation'   => array(),
			'grundlinie' => array(),
		);
		foreach ( array_merge( $a, $b ) as $i => $entry ) {
			if ( $gaps && 0 === $i % 9 ) {
				continue;
			}
			$m['mutation'][ $entry['id'] ] = array( 10, 10, 25, 0, 40 )[ $i % 5 ];
			$key                           = AB_Mutation_Plan::key( $entry['run'] );
			if ( ! ( $gaps && str_contains( $key, '"T3"' ) ) ) {
				$m['grundlinie'][ $key ] = 0 === $i % 3 ? 30 : 10;
			}
		}
		return $m;
	}

	/**
	 * Shard per id.
	 *
	 * @return array<string,int>
	 */
	private static function by_id( array $entries, array $shards ): array {
		$out = array();
		foreach ( array_values( $entries ) as $i => $entry ) {
			$out[ $entry['id'] ] = $shards[ $i ];
		}
		ksort( $out );
		return $out;
	}

	/* ------------------------------------------------------------- sharing */

	public static function shardCounts(): array {
		$out = array();
		foreach ( array( 1, 2, 3, 5, 12, 69, 70, 71, 200 ) as $n ) {
			$out[ "n=$n, measured" ] = array( $n, false );
			$out[ "n=$n, with gaps" ] = array( $n, true );
		}
		return $out;
	}

	#[DataProvider( 'shardCounts' )]
	public function testEveryEntryIsInExactlyOneShard( int $n, bool $gaps ): void {
		list( $a, $b ) = self::lists();
		$entries       = array_merge( $a, $b );
		$measures      = self::measures( $gaps );
		$shards        = AB_Mutation_Plan::assign( $entries, $measures, $n );
		self::assertSame( array_keys( $entries ), array_keys( $shards ) );
		foreach ( $shards as $k ) {
			self::assertGreaterThanOrEqual( 1, $k );
			self::assertLessThanOrEqual( $n, $k );
		}
		$seen = array();
		for ( $k = 1; $k <= $n; $k++ ) {
			foreach ( AB_Mutation_Plan::shard( $entries, $measures, $k, $n ) as $entry ) {
				self::assertArrayNotHasKey( $entry['id'], $seen, "{$entry['id']} twice" );
				$seen[ $entry['id'] ] = $k;
			}
		}
		self::assertCount( count( $entries ), $seen );
	}

	public function testTheSharingDoesNotDependOnTheOrderOfListsAndEntries(): void {
		list( $a, $b ) = self::lists();
		foreach ( array( false, true ) as $gaps ) {
			$measures = self::measures( $gaps );
			foreach ( array( 2, 5, 12 ) as $n ) {
				$first = self::by_id( array_merge( $a, $b ), AB_Mutation_Plan::assign( array_merge( $a, $b ), $measures, $n ) );
				$other = array(
					array_merge( $b, $a ),
					array_merge( array_reverse( $a ), array_reverse( $b ) ),
					array_reverse( array_merge( $a, $b ) ),
				);
				foreach ( array( 3, 17, 4242 ) as $seed ) {
					mt_srand( $seed );
					$mixed = array_merge( $a, $b );
					shuffle( $mixed );
					$other[] = $mixed;
				}
				foreach ( $other as $order ) {
					self::assertSame( $first, self::by_id( $order, AB_Mutation_Plan::assign( $order, $measures, $n ) ) );
				}
			}
		}
		mt_srand();
	}

	public function testWithinAShardTheEntriesKeepTheOrderLoaded(): void {
		list( $a, $b ) = self::lists();
		$entries       = array_merge( $a, $b );
		$position      = array_flip( array_column( $entries, 'id' ) );
		$last          = -1;
		foreach ( AB_Mutation_Plan::shard( $entries, self::measures( false ), 2, 3 ) as $entry ) {
			self::assertGreaterThan( $last, $position[ $entry['id'] ] );
			$last = $position[ $entry['id'] ];
		}
	}

	public function testAMissingDurationIsTheDefaultAndAMeasuredOneItsValue(): void {
		$measures = array(
			'mutation'   => array( 'x/known' => 37, 'x/zero' => 0 ),
			'grundlinie' => array( AB_Mutation_Plan::key( self::unit( 'Known' ) ) => 12 ),
		);
		self::assertSame( 37, AB_Mutation_Plan::mutation( $measures, 'x/known' ) );
		self::assertSame( 0, AB_Mutation_Plan::mutation( $measures, 'x/zero' ) );
		self::assertSame( AB_Mutation_Plan::DEFAULT_MUTATION, AB_Mutation_Plan::mutation( $measures, 'x/new' ) );
		self::assertSame( 12, AB_Mutation_Plan::baseline( $measures, AB_Mutation_Plan::key( self::unit( 'Known' ) ) ) );
		self::assertSame( AB_Mutation_Plan::DEFAULT_BASELINE, AB_Mutation_Plan::baseline( $measures, AB_Mutation_Plan::key( self::unit( 'New' ) ) ) );

		$entries = array(
			array( 'id' => 'x/known', 'run' => self::unit( 'Known' ) ),
			array( 'id' => 'x/new', 'run' => self::unit( 'Known' ) ),
			array( 'id' => 'x/other', 'run' => self::unit( 'New' ) ),
		);
		$costs   = AB_Mutation_Plan::costs( $entries, array( 1, 1, 1 ), $measures, 1 );
		self::assertSame(
			array( 'cost' => 12 + 37 + AB_Mutation_Plan::DEFAULT_MUTATION + AB_Mutation_Plan::DEFAULT_BASELINE + AB_Mutation_Plan::DEFAULT_MUTATION, 'baselines' => 2, 'entries' => 3 ),
			$costs[1]
		);
	}

	public function testTheUnitAndTheIntegrationRunOfOneFilterAreTwoCommands(): void {
		self::assertNotSame( AB_Mutation_Plan::key( self::unit( 'SyncCoreReadsTest' ) ), AB_Mutation_Plan::key( self::it( 'SyncCoreReadsTest' ) ) );
		$measures = array(
			'mutation'   => array( 'u' => 10, 'i' => 10 ),
			'grundlinie' => array(
				AB_Mutation_Plan::key( self::unit( 'SyncCoreReadsTest' ) ) => 5,
				AB_Mutation_Plan::key( self::it( 'SyncCoreReadsTest' ) )   => 70,
			),
		);
		$entries  = array(
			array( 'id' => 'u', 'run' => self::unit( 'SyncCoreReadsTest' ) ),
			array( 'id' => 'i', 'run' => self::it( 'SyncCoreReadsTest' ) ),
		);
		self::assertSame( array( 'cost' => 95, 'baselines' => 2, 'entries' => 2 ), AB_Mutation_Plan::costs( $entries, array( 1, 1 ), $measures, 1 )[1] );
	}

	public function testTheEntriesOfACommandStayTogetherWhereTheyFit(): void {
		$entries  = array();
		$measures = array( 'mutation' => array(), 'grundlinie' => array() );
		foreach ( array( 'A', 'B', 'C', 'D' ) as $c ) {
			$measures['grundlinie'][ AB_Mutation_Plan::key( self::unit( $c ) ) ] = 50;
			for ( $i = 0; $i < 5; $i++ ) {
				$entries[]                          = array( 'id' => "$c/$i", 'run' => self::unit( $c ) );
				$measures['mutation'][ "$c/$i" ] = 10;
			}
		}
		$shards = self::by_id( $entries, AB_Mutation_Plan::assign( $entries, $measures, 2 ) );
		foreach ( array( 'A', 'B', 'C', 'D' ) as $c ) {
			$of = array_unique( array_filter( $shards, static fn( $id ) => str_starts_with( $id, "$c/" ), ARRAY_FILTER_USE_KEY ) );
			self::assertCount( 1, $of, "$c is split" );
		}
		$costs = AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::assign( $entries, $measures, 2 ), $measures, 2 );
		self::assertSame( array( 200, 200 ), array_column( $costs, 'cost' ) );
		self::assertSame( 4, array_sum( array_column( $costs, 'baselines' ) ) );
	}

	public function testACommandLongerThanTheIdealShardIsCutIntoParts(): void {
		$entries  = array();
		$measures = array( 'mutation' => array(), 'grundlinie' => array( AB_Mutation_Plan::key( self::unit( 'Big' ) ) => 10 ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$entries[]                       = array( 'id' => sprintf( 'big/%02d', $i ), 'run' => self::unit( 'Big' ) );
			$measures['mutation'][ end( $entries )['id'] ] = 100;
		}
		$entries[]                          = array( 'id' => 'small/0', 'run' => self::unit( 'Small' ) );
		$measures['mutation']['small/0']    = 10;
		$measures['grundlinie'][ AB_Mutation_Plan::key( self::unit( 'Small' ) ) ] = 10;

		// Whole: 10 + 1200 + 10 + 10 = 1230; ideal of three shards 410: three
		// parts of 10 + 400, the small command beside the first.
		$costs = AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::assign( $entries, $measures, 3 ), $measures, 3 );
		self::assertSame( array( 430, 410, 410 ), array_column( $costs, 'cost' ) );
		self::assertSame( 4, array_sum( array_column( $costs, 'baselines' ) ) );
	}

	/**
	 * Entries each of its own command (baseline 0) or of one, with their
	 * tenths; costs and baselines per shard.
	 *
	 * @param array<string,array<string,int>> $commands Command => id => tenths.
	 * @param array<string,int>               $base     Command => baseline.
	 * @return array{0:int[],1:int[]}
	 */
	private static function planned( array $commands, array $base, int $n ): array {
		$entries  = array();
		$measures = array( 'mutation' => array(), 'grundlinie' => array() );
		foreach ( $commands as $command => $ids ) {
			$measures['grundlinie'][ AB_Mutation_Plan::key( self::unit( $command ) ) ] = $base[ $command ] ?? 0;
			foreach ( $ids as $id => $tenths ) {
				$entries[]                    = array( 'id' => $id, 'run' => self::unit( $command ) );
				$measures['mutation'][ $id ] = $tenths;
			}
		}
		$costs = AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::assign( $entries, $measures, $n ), $measures, $n );
		return array( array_column( $costs, 'cost' ), array_column( $costs, 'baselines' ) );
	}

	public function testTheLongestCommandGoesFirst(): void {
		// 5, 4, 3, 3, 3 on two shards: longest first gives 8 and 10; the
		// shortest first would give 11 and 7.
		self::assertSame(
			array( array( 80, 100 ), array( 2, 3 ) ),
			self::planned( array( 'P' => array( 'p' => 50 ), 'Q' => array( 'q' => 40 ), 'R' => array( 'r' => 30 ), 'S' => array( 's' => 30 ), 'T' => array( 't' => 30 ) ), array(), 2 )
		);
	}

	public function testThePartsOfACutCommandTakeTheLongestEntryFirst(): void {
		// One command of 18 s on two shards: parts 8 and 10 (the shortest
		// first would give 11 and 7).
		self::assertSame(
			array( array( 100, 80 ), array( 1, 1 ) ),
			self::planned( array( 'P' => array( 'p1' => 30, 'p2' => 50, 'p3' => 30, 'p4' => 40, 'p5' => 30 ) ), array(), 2 )
		);
	}

	public function testACutThatDoesNotShortenTheLongestShardIsNotMade(): void {
		// 13 s against 12 s: cut into 7 and 6, the longest shard would be 13 s
		// all the same, with one baseline more.
		self::assertSame(
			array( array( 130, 120 ), array( 1, 1 ) ),
			self::planned( array( 'B' => array( 'b1' => 60, 'b2' => 60, 'b3' => 10 ), 'O' => array( 'o' => 120 ) ), array(), 2 )
		);
	}

	public function testACutThatCostsMoreBaselineThanItSavesIsNotMade(): void {
		// Cut, the command of 23 s (7 s baseline) would give 21 and 29 s.
		self::assertSame(
			array( array( 230, 200 ), array( 1, 2 ) ),
			self::planned(
				array( 'C0' => array( 'c0/0' => 30, 'c0/1' => 30, 'c0/2' => 100 ), 'C1' => array( 'c1/0' => 40, 'c1/1' => 10, 'c1/2' => 80, 'c1/3' => 10 ), 'C2' => array( 'c2/0' => 30 ) ),
				array( 'C0' => 70, 'C1' => 20, 'C2' => 10 ),
				2
			)
		);
	}

	public function testThePartsOfACutCommandCountTheirBaseline(): void {
		// Whole the longest shard is 24 s; cut, with the baseline in each
		// part, 23 s.
		self::assertSame(
			array( array( 150, 230, 230 ), array( 1, 2, 2 ) ),
			self::planned(
				array( 'C0' => array( 'c0/0' => 50 ), 'C1' => array( 'c1/0' => 100, 'c1/1' => 40 ), 'C2' => array( 'c2/0' => 10, 'c2/1' => 90, 'c2/2' => 100 ) ),
				array( 'C0' => 40, 'C1' => 50, 'C2' => 40 ),
				3
			)
		);
	}

	public function testMoreShardsThanEntriesLeavesTheRestEmpty(): void {
		$entries  = array(
			array( 'id' => 'one', 'run' => self::unit( 'A' ) ),
			array( 'id' => 'two', 'run' => self::unit( 'B' ) ),
		);
		$measures = array( 'mutation' => array(), 'grundlinie' => array() );
		$shards   = AB_Mutation_Plan::assign( $entries, $measures, 5 );
		self::assertSame( array( 1, 2 ), $shards );
		for ( $k = 3; $k <= 5; $k++ ) {
			self::assertSame( array(), AB_Mutation_Plan::shard( $entries, $measures, $k, 5 ) );
		}
	}

	public function testEveryNthFollowsTheOrderLoaded(): void {
		$entries = array(
			array( 'id' => 'a', 'run' => self::unit( 'A' ) ),
			array( 'id' => 'b', 'run' => self::unit( 'A' ) ),
			array( 'id' => 'c', 'run' => self::unit( 'B' ) ),
			array( 'id' => 'd', 'run' => self::unit( 'B' ) ),
		);
		self::assertSame( array( 'a' => 1, 'b' => 2, 'c' => 1, 'd' => 2 ), self::by_id( $entries, AB_Mutation_Plan::round_robin( $entries, 2 ) ) );
		$reverse = array_reverse( $entries );
		self::assertSame( array( 'a' => 2, 'b' => 1, 'c' => 2, 'd' => 1 ), self::by_id( $reverse, AB_Mutation_Plan::round_robin( $reverse, 2 ) ) );
		$measures = array( 'mutation' => array( 'a' => 10, 'b' => 10, 'c' => 10, 'd' => 10 ), 'grundlinie' => array( AB_Mutation_Plan::key( self::unit( 'A' ) ) => 5, AB_Mutation_Plan::key( self::unit( 'B' ) ) => 5 ) );
		self::assertSame( array( 30, 30 ), array_column( AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::round_robin( $entries, 2 ), $measures, 2 ), 'cost' ) );
		self::assertSame( array( 2, 2 ), array_column( AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::round_robin( $entries, 2 ), $measures, 2 ), 'baselines' ) );
	}

	public function testTheCheckedInMeasuresShareTheListsBetterThanEveryNth(): void {
		$root     = dirname( __DIR__ );
		$measures = AB_Mutation_Plan::load_measures( $root . '/tests/mutation/dauer.php' );
		$lists    = glob( $root . '/tests/mutation/lists/*.php' );
		sort( $lists );
		$entries = AB_Mutation_Plan::list_entries( $lists );
		$before  = array_column( AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::round_robin( $entries, 12 ), $measures, 12 ), 'cost' );
		$after   = AB_Mutation_Plan::costs( $entries, AB_Mutation_Plan::assign( $entries, $measures, 12 ), $measures, 12 );
		self::assertLessThan( max( $before ), max( array_column( $after, 'cost' ) ) );
		self::assertSame( count( $entries ), array_sum( array_column( $after, 'entries' ) ) );
	}

	/* -------------------------------------------------------- measure file */

	private function file( string $php ): string {
		$dir = (string) realpath( sys_get_temp_dir() ) . '/ab-dauer-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0700 );
		$this->remove[] = $dir;
		file_put_contents( $dir . '/dauer.php', $php );
		return $dir . '/dauer.php';
	}

	private static function valid(): array {
		return array(
			'herkunft'   => array( 'quelle' => self::SOURCE, 'lauf' => 43211580790, 'versuch' => 1 ),
			'mutation'   => array( 'a/one' => 1.4, 'a/zero' => 0.0 ),
			'grundlinie' => array( '["vendor/bin/phpunit","--filter","A"]' => 2.0 ),
		);
	}

	public function testAMissingMeasureFileIsAnError(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is missing' );
		AB_Mutation_Plan::load_measures( (string) realpath( sys_get_temp_dir() ) . '/ab-dauer-fehlt-' . bin2hex( random_bytes( 6 ) ) . '.php' );
	}

	public static function brokenFiles(): array {
		$v   = self::valid();
		$out = array(
			'no array'               => array( '<?php return 3;', 'returns no array' ),
			'a parse error'          => array( '<?php return array(', 'does not load' ),
		);
		$cut = array(
			'no herkunft'            => array( array( 'herkunft' => null ), "no 'herkunft'" ),
			'no mutation'            => array( array( 'mutation' => null ), "no 'mutation'" ),
			'no grundlinie'          => array( array( 'grundlinie' => 'x' ), "no 'grundlinie'" ),
			'a short source'         => array( array( 'herkunft' => array( 'quelle' => '78708fb' ) + $v['herkunft'] ), 'no source commit' ),
			'no run'                 => array( array( 'herkunft' => array( 'lauf' => 0 ) + $v['herkunft'] ), 'herkunft.lauf' ),
			'an attempt as text'     => array( array( 'herkunft' => array( 'versuch' => '1' ) + $v['herkunft'] ), 'herkunft.versuch' ),
			'a negative time'        => array( array( 'mutation' => array( 'a' => -0.1 ) ), 'no valid duration for a' ),
			'a time as text'         => array( array( 'mutation' => array( 'a' => '1.0' ) ), 'no valid duration for a' ),
			'a time that is no number' => array( array( 'grundlinie' => array( '["x"]' => NAN ) ), 'no valid duration' ),
			'a number as id'         => array( array( 'mutation' => array( 7 => 1.0 ) ), 'is no name' ),
			'an empty id'            => array( array( 'mutation' => array( '' => 1.0 ) ), 'is no name' ),
			'a baseline of no array' => array( array( 'grundlinie' => array( 'vendor/bin/phpunit --filter A' => 1.0 ) ), 'no command array' ),
		);
		foreach ( $cut as $name => list( $over, $says ) ) {
			$data = array_filter( array_merge( $v, $over ), static fn( $part ) => null !== $part );
			$out[ $name ] = array( '<?php return ' . var_export( $data, true ) . ';', $says );
		}
		return $out;
	}

	#[DataProvider( 'brokenFiles' )]
	public function testABrokenMeasureFileIsAnError( string $php, string $says ): void {
		$file = $this->file( $php );
		try {
			AB_Mutation_Plan::load_measures( $file );
			self::fail( 'A broken measure file was taken.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $says, $e->getMessage() );
		}
	}

	public function testAValidMeasureFileLoadsInTenthsAndExportsAlikeAndSorted(): void {
		$valid                         = self::valid();
		$valid['mutation']             = array( 'b/last' => 12.5 ) + $valid['mutation'];
		$file                          = $this->file( '<?php return ' . var_export( $valid, true ) . ';' );
		$measures                      = AB_Mutation_Plan::load_measures( $file );
		self::assertSame( array( 'b/last' => 125, 'a/one' => 14, 'a/zero' => 0 ), $measures['mutation'] );
		self::assertSame( array( '["vendor/bin/phpunit","--filter","A"]' => 20 ), $measures['grundlinie'] );
		$text = AB_Mutation_Plan::export( $measures );
		self::assertStringContainsString( "\t\t'a/one' => 1.4,\n\t\t'a/zero' => 0.0,\n\t\t'b/last' => 12.5,\n", $text );
		$again = AB_Mutation_Plan::load_measures( $this->file( $text ) );
		ksort( $measures['mutation'] );
		self::assertSame( $measures, $again );
	}

	public function testTheMedianOfSeveralBaselines(): void {
		self::assertSame( 3, AB_Mutation_Plan::median( array( 3 ) ) );
		self::assertSame( 3, AB_Mutation_Plan::median( array( 1, 5 ) ) );
		self::assertSame( 2, AB_Mutation_Plan::median( array( 2, 1 ) ) );
		self::assertSame( 2, AB_Mutation_Plan::median( array( 9, 1, 2 ) ) );
		self::assertSame( 4, AB_Mutation_Plan::median( array( 100, 3, 0, 4 ) ) );
	}

	/* -------------------------------------------------------------- import */

	/**
	 * The entries the source expects in the import tests.
	 *
	 * @return array[]
	 */
	private static function expected(): array {
		return array(
			array( 'id' => 'l/one', 'run' => self::unit( 'A' ) ),
			array( 'id' => 'l/two', 'run' => self::unit( 'A' ) ),
			array( 'id' => 'l/three', 'run' => self::it( 'A' ) ),
		);
	}

	/**
	 * A log as gh run view --log prints it: job, step, time stamp and line.
	 *
	 * @param array<string,string[]> $jobs Lines per job.
	 * @return string
	 */
	private static function log( array $jobs ): string {
		$out = array();
		foreach ( $jobs as $job => $lines ) {
			foreach ( $lines as $i => $line ) {
				$out[] = $job . "\tUNKNOWN STEP\t" . ( 0 === $i ? "\u{FEFF}" : '' ) . '2026-11-12T11:43:' . sprintf( '%02d', $i % 60 ) . '.1234567Z ' . $line;
			}
		}
		return implode( "\n", $out ) . "\n";
	}

	/**
	 * The lines of a mutation job.
	 *
	 * @param int      $k    Shard.
	 * @param int      $n    Shards.
	 * @param string[] $body Lines of the runner.
	 * @param string   $head Checkout line.
	 * @return string[]
	 */
	private static function job( int $k, int $n, array $body, string $head = '' ): array {
		return array_merge(
			array(
				'##[group]Run actions/checkout@v4',
				'' !== $head ? $head : 'HEAD is now at 3cac19c Merge ' . self::SOURCE . ' into ' . self::BASE,
				'HEAD is now at fc9832b Tag 7.1.3 Built from https://develop.svn.wordpress.org/tags/7.1.3@64157',
				"##[group]Run php tests/mutation/run.php --shard=$k/$n",
				"\e[36;1mphp tests/mutation/run.php --shard=$k/$n\e[0m",
				'##[endgroup]',
			),
			$body,
			array( '##[group]Run set -e', 'git status --porcelain' )
		);
	}

	private static function jobName( int $k, int $n ): string {
		return "Every listed clause is held by a test ($k/$n)";
	}

	/**
	 * A good log of the entries of expected() over two shards.
	 *
	 * @return array<string,string[]>
	 */
	private static function jobs(): array {
		return array(
			'PHPUnit on PHP 8.4' => array( 'killed    9.9 s  l/one — not a mutation job (exit 1)' ),
			self::jobName( 2, 2 )   => self::job(
				2,
				2,
				array(
					'baseline  green    3.0 s  vendor/bin/phpunit --no-progress --filter A',
					'killed      0.0 s  l/two — a clause (exit 1)',
					'all 1 mutations killed; every file is back byte for byte',
				)
			),
			self::jobName( 1, 2 )   => self::job(
				1,
				2,
				array(
					'baseline  green    1.0 s  vendor/bin/phpunit --no-progress --filter A',
					'baseline  green  123.4 s  vendor/bin/phpunit --no-progress -c tests/integration/phpunit.xml.dist --filter A',
					'killed      1.4 s  l/one — a clause (exit 1)',
					'killed     12.0 s  l/three — another clause (exit 2)',
					'all 2 mutations killed; every file is back byte for byte',
				)
			),
		);
	}

	public function testAGoodLogGivesEveryDuration(): void {
		$found = AB_Mutation_Plan::import( self::log( self::jobs() ), self::expected(), self::SOURCE );
		self::assertSame( 2, $found['shards'] );
		self::assertSame( array( 'l/one' => 14, 'l/three' => 120, 'l/two' => 0 ), $found['mutation'] );
		self::assertSame(
			array(
				AB_Mutation_Plan::key( self::unit( 'A' ) ) => 20,
				AB_Mutation_Plan::key( self::it( 'A' ) )   => 1234,
			),
			$found['grundlinie']
		);
		self::assertSame( array( self::BASE ), $found['merge_bases'] );
	}

	public function testTheCommitItselfCheckedOutCountsAndNamesNoMerge(): void {
		$jobs = self::jobs();
		foreach ( array( 1, 2 ) as $k ) {
			$jobs[ self::jobName( $k, 2 ) ][1] = 'HEAD is now at 78708fb Z8b Zweig 2b: erster Ausgangsstand';
		}
		self::assertSame( array(), AB_Mutation_Plan::import( self::log( $jobs ), self::expected(), self::SOURCE )['merge_bases'] );
	}

	public function testAnEmptyShardEndsWithItsOwnLine(): void {
		$jobs                       = self::jobs();
		$jobs[ self::jobName( 3, 3 ) ] = self::job( 3, 3, array( 'shard 3/3 has no entry; the other shards run them all' ) );
		$jobs[ self::jobName( 1, 3 ) ] = self::job( 1, 3, array_slice( $jobs[ self::jobName( 1, 2 ) ], 6, 5 ) );
		$jobs[ self::jobName( 2, 3 ) ] = self::job( 2, 3, array_slice( $jobs[ self::jobName( 2, 2 ) ], 6, 3 ) );
		unset( $jobs[ self::jobName( 1, 2 ) ], $jobs[ self::jobName( 2, 2 ) ] );
		self::assertSame( 3, AB_Mutation_Plan::import( self::log( $jobs ), self::expected(), self::SOURCE )['shards'] );
	}

	/**
	 * Each break of the contract, on the good log.
	 *
	 * @return array<string,array{0:callable,1:string}>
	 */
	public static function brokenLogs(): array {
		$one = self::jobName( 1, 2 );
		$two = self::jobName( 2, 2 );
		return array(
			'a shard missing'                  => array( static function ( &$j ) use ( $two ) { unset( $j[ $two ] ); }, 'shard 2/2 is missing' ),
			'no mutation job'                  => array( static function ( &$j ) use ( $one, $two ) { unset( $j[ $one ], $j[ $two ] ); }, 'no mutation job' ),
			'a shard twice'                    => array( static function ( &$j ) use ( $two ) { $j['again'] = $j[ $two ]; }, 'shard 2/2 is in the log twice' ),
			'shards of another count'          => array( static function ( &$j ) use ( $two ) { $j[ $two ][3] = '##[group]Run php tests/mutation/run.php --shard=2/3'; }, 'share into' ),
			'no last line'                     => array( static function ( &$j ) use ( $two ) { unset( $j[ $two ][8] ); }, 'shard 2/2 did not reach its end (no last line)' ),
			'a last line of another count'     => array( static function ( &$j ) use ( $one ) { $j[ $one ][10] = 'all 3 mutations killed; every file is back byte for byte'; }, '3 counted, 2 killed lines' ),
			'a killed line after the step'     => array( static function ( &$j ) use ( $two ) { $j[ $two ][] = 'killed      1.0 s  l/four — late (exit 1)'; }, '' ),
			'an entry not measured'            => array( static function ( &$j ) use ( $two ) { unset( $j[ $two ][7] ); $j[ $two ][8] = 'all 0 mutations killed; every file is back byte for byte'; }, '1 entries of the source have no measurement: l/two' ),
			'an entry measured twice'          => array( static function ( &$j ) use ( $one, $two ) { $j[ $two ][7] = 'killed      0.0 s  l/one — a clause (exit 1)'; }, 'l/one is measured twice' ),
			'an entry the source does not have' => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'killed      0.0 s  l/four — a clause (exit 1)'; }, 'measured l/four, which the lists at the source do not have' ),
			'a mutation that survived'         => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'SURVIVED    0.4 s  l/two — a clause (the tests pass without the clause)'; }, 'is not green' ),
			'a mutation out of time'           => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'TIMEOUT   600.0 s  l/two — a clause (no verdict within 600 s)'; }, 'is not green' ),
			'a time that is no number'         => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'killed      nan s  l/two — a clause (exit 1)'; }, "the time 'nan' is no valid duration" ),
			'a time of two decimals'           => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'killed     1.25 s  l/two — a clause (exit 1)'; }, "the time '1.25' is no valid duration" ),
			'a negative baseline'              => array( static function ( &$j ) use ( $two ) { $j[ $two ][6] = 'baseline  green   -1.0 s  vendor/bin/phpunit --no-progress --filter A'; }, "the time '-1.0' is no valid duration" ),
			'a killed line out of form'        => array( static function ( &$j ) use ( $two ) { $j[ $two ][7] = 'killed      0.0 s  l/two'; }, 'a mutation line out of form' ),
			'a baseline line out of form'      => array( static function ( &$j ) use ( $two ) { $j[ $two ][6] = 'baseline  red    3.0 s  vendor/bin/phpunit --no-progress --filter A'; }, 'a baseline line out of form' ),
			'a baseline the lists lack'        => array( static function ( &$j ) use ( $two ) { $j[ $two ][6] = 'baseline  green    3.0 s  vendor/bin/phpunit --no-progress --filter B'; }, 'ran a baseline the lists do not have' ),
			'a command without baseline'       => array( static function ( &$j ) use ( $one ) { unset( $j[ $one ][7] ); }, 'the baseline of vendor/bin/phpunit --no-progress -c tests/integration/phpunit.xml.dist --filter A has no measurement' ),
			'another commit checked out'       => array( static function ( &$j ) use ( $one ) { $j[ $one ][1] = 'HEAD is now at 40a282c Z8b Zweig 2b'; }, 'shard 1/2 did not check out the source' ),
			'a merge of another commit'        => array( static function ( &$j ) use ( $one ) { $j[ $one ][1] = 'HEAD is now at 3cac19c Merge 40a282c68cabd478828578ec3bcb435fb75f73ce into ' . self::BASE; }, 'shard 1/2 did not check out the source' ),
			'the checkout after the run'       => array( static function ( &$j ) use ( $one ) { $j[ $one ][] = $j[ $one ][1]; $j[ $one ][1] = 'HEAD is now at 40a282c Z8b'; }, 'shard 1/2 did not check out the source' ),
		);
	}

	#[DataProvider( 'brokenLogs' )]
	public function testALogThatBreaksTheContractIsAnError( callable $break, string $says ): void {
		$jobs = self::jobs();
		$break( $jobs );
		if ( '' === $says ) {
			// A line after the runner's step is not the runner's.
			self::assertArrayNotHasKey( 'l/four', AB_Mutation_Plan::import( self::log( $jobs ), self::expected(), self::SOURCE )['mutation'] );
			return;
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $says );
		AB_Mutation_Plan::import( self::log( $jobs ), self::expected(), self::SOURCE );
	}

	public function testTwoCommandsPrintedAlikeCannotBeImported(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'printed alike' );
		AB_Mutation_Plan::import(
			self::log( self::jobs() ),
			array_merge( self::expected(), array( array( 'id' => 'l/four', 'run' => array( 'vendor/bin/phpunit --no-progress', '--filter', 'A' ) ) ) ),
			self::SOURCE
		);
	}

	public function testTheSourceMustBeAWholeCommitId(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no full commit id' );
		AB_Mutation_Plan::import( self::log( self::jobs() ), self::expected(), '78708fb' );
	}

	public function testTheListsOfTheImportAreChecked(): void {
		$dir            = (string) realpath( sys_get_temp_dir() ) . '/ab-dauer-' . bin2hex( random_bytes( 6 ) );
		$this->remove[] = $dir;
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/a.php', '<?php return ' . var_export( self::expected(), true ) . ';' );
		file_put_contents( $dir . '/b.php', '<?php return array( array( "id" => "l/one", "run" => array( "x" ) ) );' );
		file_put_contents( $dir . '/c.php', '<?php return 1;' );
		file_put_contents( $dir . '/d.php', '<?php return array( array( "id" => "l/x" ) );' );
		self::assertSame( self::expected(), AB_Mutation_Plan::list_entries( array( $dir . '/a.php' ) ) );
		foreach ( array( 'b.php' => 'the id l/one is taken', 'c.php' => 'returns no list', 'd.php' => 'has no id or no command' ) as $file => $says ) {
			try {
				AB_Mutation_Plan::list_entries( array( $dir . '/a.php', $dir . '/' . $file ) );
				self::fail( "$file was taken" );
			} catch ( RuntimeException $e ) {
				self::assertStringContainsString( $says, $e->getMessage() );
			}
		}
	}

	/* ------------------------------------------------- the runner itself */

	/**
	 * A tree of its own: the runner and the plan, three shipped files, a
	 * list of three entries; the measure file only where asked.
	 *
	 * @param string|null $measures Bytes of tests/mutation/dauer.php.
	 * @return string Root.
	 */
	private function tree( ?string $measures ): string {
		$root           = (string) realpath( sys_get_temp_dir() ) . '/ab-mutation-plan-' . bin2hex( random_bytes( 6 ) );
		$this->remove[] = $root;
		foreach ( array( 'includes', 'tests/mutation/lists' ) as $dir ) {
			mkdir( $root . '/' . $dir, 0700, true );
		}
		foreach ( array( 'run.php', 'verteilung.php' ) as $file ) {
			copy( dirname( __DIR__ ) . '/tests/mutation/' . $file, $root . '/tests/mutation/' . $file );
		}
		$entries = array();
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			file_put_contents( $root . "/includes/$name.php", "<?php\nreturn '$name';\n" );
			$entries[] = array(
				'id'      => "probe/$name",
				'clause'  => "the $name",
				'file'    => "includes/$name.php",
				'search'  => "'$name'",
				'replace' => "'MUTIERT'",
				'run'     => array( PHP_BINARY, '-r', 'exit( false !== strpos( (string) file_get_contents( "includes/' . $name . '.php" ), "MUTIERT" ) ? 1 : 0 );' ),
			);
		}
		file_put_contents( $root . '/tests/mutation/lists/probe.php', '<?php return ' . var_export( $entries, true ) . ';' );
		if ( null !== $measures ) {
			file_put_contents( $root . '/tests/mutation/dauer.php', $measures );
		}
		return $root;
	}

	/**
	 * Run the runner of a tree.
	 *
	 * @param string   $root Root.
	 * @param string[] $args Arguments.
	 * @return array{0:int,1:string,2:string} Status, output, errors.
	 */
	private static function runner( string $root, array $args ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY, $root . '/tests/mutation/run.php' ), $args ),
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			$root
		);
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	private static function measureFile(): string {
		return AB_Mutation_Plan::export(
			array(
				'herkunft'   => array( 'quelle' => self::SOURCE, 'lauf' => 1, 'versuch' => 1 ),
				'mutation'   => array( 'probe/one' => 50, 'probe/two' => 10 ),
				'grundlinie' => array(),
			)
		);
	}

	/**
	 * The ids killed in an output.
	 *
	 * @return string[]
	 */
	private static function killed( string $out ): array {
		preg_match_all( '/^killed +[0-9.]+ s  (\S+) — /m', $out, $m );
		return $m[1];
	}

	public function testShardWithoutTheMeasureFileStopsAndRunsNothing(): void {
		$root = $this->tree( null );
		list( $code, $out, $err ) = self::runner( $root, array( '--shard=1/2' ) );
		self::assertSame( 2, $code, $out . $err );
		self::assertSame( '', $out );
		self::assertStringContainsString( '--shard shares the entries out by their measured durations, but the measure file', $err );
		self::assertStringContainsString( 'is missing', $err );
	}

	public function testShardWithABrokenMeasureFileStopsAndRunsNothing(): void {
		$root = $this->tree( '<?php return array( "herkunft" => array() );' );
		list( $code, $out, $err ) = self::runner( $root, array( '--shard=2/2' ) );
		self::assertSame( 2, $code, $out . $err );
		self::assertSame( '', $out );
		self::assertStringContainsString( "has no 'mutation'", $err );
	}

	public function testWithoutShardTheRunNeedsNoMeasureFile(): void {
		$root = $this->tree( null );
		list( $code, $out, $err ) = self::runner( $root, array() );
		self::assertSame( 0, $code, $out . $err );
		self::assertSame( array( 'probe/one', 'probe/two', 'probe/three' ), self::killed( $out ) );
		self::assertStringContainsString( 'all 3 mutations killed; every file is back byte for byte', $out );
		list( $code, $out ) = self::runner( $this->tree( '<?php return 1;' ), array( '--only=probe/two' ) );
		self::assertSame( 0, $code );
		self::assertSame( array( 'probe/two' ), self::killed( $out ) );
	}

	public function testTheShardsTogetherRunEveryEntryOnceByTheirDurations(): void {
		$root = $this->tree( self::measureFile() );
		$ran  = array();
		foreach ( array( 1, 2 ) as $k ) {
			list( $code, $out, $err ) = self::runner( $root, array( "--shard=$k/2" ) );
			self::assertSame( 0, $code, $out . $err );
			$ran[ $k ] = self::killed( $out );
		}
		// Each its own command, every baseline the default (10 s): three (10 s,
		// the default) 20 s first, then one (5 s) 15 s, two (1 s) 11 s beside
		// one; in each shard in the order loaded.
		self::assertSame( array( 1 => array( 'probe/three' ), 2 => array( 'probe/one', 'probe/two' ) ), $ran );
	}

	public function testOnlyWithShardRunsTheEntryInOneShard(): void {
		$root = $this->tree( self::measureFile() );
		$ran  = array();
		foreach ( array( 1, 2, 3 ) as $k ) {
			list( $code, $out, $err ) = self::runner( $root, array( '--only=probe/three', "--shard=$k/3" ) );
			self::assertSame( 0, $code, $out . $err );
			$ran = array_merge( $ran, self::killed( $out ) );
			if ( 1 !== $k ) {
				self::assertSame( "shard $k/3 has no entry; the other shards run them all\n", $out );
			}
		}
		self::assertSame( array( 'probe/three' ), $ran );
	}

	public function testMoreShardsThanEntriesRunsEachOnceAndTheRestNothing(): void {
		$root = $this->tree( self::measureFile() );
		$ran  = array();
		foreach ( range( 1, 5 ) as $k ) {
			list( $code, $out, $err ) = self::runner( $root, array( "--shard=$k/5" ) );
			self::assertSame( 0, $code, $out . $err );
			$ran = array_merge( $ran, self::killed( $out ) );
		}
		sort( $ran );
		self::assertSame( array( 'probe/one', 'probe/three', 'probe/two' ), $ran );
	}
}
