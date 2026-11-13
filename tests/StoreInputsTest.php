<?php
/**
 * The table of the inputs of the store, AB_MCP_Store_Inputs::TABLE (Z8b
 * branch 3, Behebung 4, Option C1): every form whole, the values it names
 * those of their sources, every row driven by one test, and for every row
 * the unit suite drives every wrong form of its form (WrongForms) at its
 * consumer: kept() unknown (retention), keep_until() 0 and no age
 * (period), the refusal of the budget (budget). Valid values, empty ones
 * where the form takes them, reach the consumer as values. The rows whose
 * consumer needs the folder of the store run in the integration suite
 * (StoreInputsTest there, WrongForms::INTEGRATION).
 *
 * The test reads the table from the code: a new row gets its wrong forms
 * from its form, and is red here until a driver reaches its consumer. The
 * generated forms follow the form in the code, so a form loosened there
 * loosens them with it; the fixed wrong forms of each driver (more, named
 * "fixed: ..." after the list of the order) do not: they are wrong whatever
 * the code says, and hold the form of each row.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Protected_Store;
use AB_MCP_Shape;
use AB_MCP_Store_Budget;
use AB_MCP_Store_Inputs;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Code_Package;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Test_Seam;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-protected-store.php';
require_once __DIR__ . '/../includes/sync/class-sync-run.php';
require_once __DIR__ . '/../includes/sync/class-sync-canon.php';
require_once __DIR__ . '/../includes/sync/class-sync-backup.php';
require_once __DIR__ . '/../includes/sync/class-sync-import.php';
require_once __DIR__ . '/../includes/sync/class-sync-code-package.php';
require_once __DIR__ . '/../includes/sync/class-sync-code-stage.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';

/**
 * A stand-in of $wpdb for the runs: the rows of backup_rows(), the column
 * of open_runs() and the plan of one run, each given as it is.
 */
final class StoreInputsWpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	/** @var mixed */
	public $rows = array();
	/** @var mixed */
	public $col = array();
	/** @var mixed */
	public $plan = null;

	public function prepare( $query, ...$args ) {
		return (string) $query;
	}

	public function get_results( $query, $output = null ) {
		return $this->rows;
	}

	public function get_col( $query, $x = 0 ) {
		return $this->col;
	}

	public function get_var( $query ) {
		return $this->plan;
	}
}

final class StoreInputsTest extends TestCase {

	private const NOW = 1800000000;
	private const DAY = 86400;

	/** @var mixed */
	private $wpdb_before;

	protected function setUp(): void {
		$this->wpdb_before = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = new StoreInputsWpdb();
		self::schema_available( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_before;
		self::schema_available( null );
		AB_MCP_Sync_Run::$clock = null;
		foreach ( array( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, AB_MCP_Store_Budget::FILTER_PACKAGES, AB_MCP_Store_Budget::FILTER_BYTES, AB_MCP_Store_Budget::FILTER_EXPORTS ) as $hook ) {
			unset( $GLOBALS['ab_test_filters'][ $hook ] );
		}
	}

	private static function schema_available( ?bool $yes ): void {
		$status = new ReflectionProperty( AB_MCP_Sync_Schema::class, 'status' );
		$status->setValue( null, null === $yes ? null : array( 'available' => $yes, 'reason' => $yes ? null : 'missing' ) );
	}

	public function testEveryFormOfTheTableIsWholeAndEveryRowNamesItsConsumerSourceAndField(): void {
		self::assertNotSame( array(), AB_MCP_Store_Inputs::TABLE );
		foreach ( AB_MCP_Store_Inputs::TABLE as $id => $row ) {
			self::assertSame( array( 'consumer', 'source', 'field', 'form' ), array_keys( $row ), $id );
			self::assertContains( $row['consumer'], array( 'retention', 'period', 'sidecar', 'protection', 'file_time', 'budget', 'package', 'stage' ), $id );
			self::assertNotSame( '', $row['source'], $id );
			self::assertNotSame( '', $row['field'], $id );
			self::assertTrue( AB_MCP_Shape::valid_form( $row['form'] ), "$id: its form names every property of its type and nothing else." );
		}
		self::assertNull( AB_MCP_Store_Inputs::read( 'no such row', 5 ), 'A row the table has not: unknown.' );
	}

	public function testTheValuesTheTableNamesAreThoseOfTheirSources(): void {
		$t = AB_MCP_Store_Inputs::TABLE;
		self::assertSame( array_merge( AB_MCP_Sync_Run::OPEN_STATES, AB_MCP_Sync_Schema::CLOSED ), $t['runs.state']['form']['in'] );
		self::assertSame( AB_MCP_Sync_Run::TYPES, $t['runs.type']['form']['in'] );
		self::assertSame( array( AB_MCP_Protected_Store::FORMAT ), $t['sidecar.format']['form']['in'] );
		$mark = $t['mark.json']['form']['value']['fields'];
		self::assertSame( array( AB_MCP_Protected_Store::MARK_FORMAT ), $mark['format']['in'] );
		self::assertSame( AB_MCP_Protected_Store::MARK_ROLES, $mark['trees']['item']['fields']['role']['in'] );
		self::assertSame( count( AB_MCP_Protected_Store::MARK_ROLES ), $mark['trees']['max_items'] );
		self::assertSame( 3650, $t['period.days']['form']['max'] );
		// The rows of the sidecar: one for each type of SIDECAR but area,
		// which is the area of its row (whole()).
		$types = array();
		foreach ( AB_MCP_Protected_Store::SIDECAR as $row ) {
			$types = array_merge( $types, array_values( $row ) );
		}
		$types = array_values( array_diff( array_unique( $types ), array( 'area' ) ) );
		sort( $types );
		$rows = array();
		foreach ( array_keys( $t ) as $id ) {
			if ( 0 === strpos( $id, 'sidecar.' ) && 'sidecar.json' !== $id ) {
				$rows[] = substr( $id, 8 );
			}
		}
		sort( $rows );
		self::assertSame( $types, $rows, 'Every type of the table SIDECAR has its row.' );
		// The label of open() and of its row read the same.
		$store = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-protected-store.php' );
		self::assertStringContainsString( "preg_match( '" . $t['sidecar.label']['form']['pattern'] . "', \$label )", $store );
	}

	public function testEveryRowIsDrivenByOneTestAndEveryInputOfKeptHasItsRows(): void {
		$unit = array_keys( self::drivers() );
		self::assertSame( array(), array_values( array_intersect( $unit, WrongForms::INTEGRATION ) ), 'No row driven twice.' );
		$both = array_merge( $unit, WrongForms::INTEGRATION );
		sort( $both );
		$rows = array_keys( AB_MCP_Store_Inputs::TABLE );
		sort( $rows );
		self::assertSame( $rows, $both, 'Every row of the table has its driver: a new row is red here until one reaches its consumer.' );
		$of_kept = array(
			'entry'   => 'sidecar.json',
			'now'     => 'retention.now',
			'open'    => 'retention.open',
			'backups' => 'retention.backups',
			'keep'    => 'retention.keep',
		);
		self::assertSame( array_keys( AB_MCP_Protected_Store::RETENTION_INPUTS ), array_keys( $of_kept ) );
		foreach ( $of_kept as $row ) {
			self::assertArrayHasKey( $row, AB_MCP_Store_Inputs::TABLE );
		}
	}

	public function testEveryWrongFormOfARowReachesItsConsumerAsUnknownAndEveryValidOneAsAValue(): void {
		$red   = array();
		$count = 0;
		foreach ( self::drivers() as $id => $driver ) {
			$form  = AB_MCP_Store_Inputs::TABLE[ $id ]['form'];
			$wrong = WrongForms::of( $form, $driver['base'] ) + $driver['more'];
			foreach ( $driver['valid'] as $i => $case ) {
				$this->setUp();
				$got = ( $driver['run'] )( $case[0] );
				$this->tearDown();
				if ( $got !== $case[1] ) {
					$red[] = "$id, valid #$i " . var_export( $case[0], true ) . ': ' . var_export( $got, true );
				}
			}
			foreach ( $wrong as $what => $value ) {
				++$count;
				$this->setUp();
				$got = ( $driver['run'] )( $value );
				$this->tearDown();
				if ( ! ( $driver['unknown'] )( $got ) ) {
					$red[] = "$id, $what: " . var_export( $got, true );
				}
			}
		}
		foreach ( self::drivers()['runs.plan']['more'] as $what => $text ) {
			if ( 0 === strpos( $what, 'fixed: ' ) ) {
				self::assertSame( $text, AB_MCP_Sync_Canon::encode( AB_MCP_Sync_Canon::decode( $text, 64 ) ), "$what: spelt as encode() writes it, so the form of the row decides." );
			}
		}
		self::assertGreaterThan( 300, $count, 'Every row with its wrong forms.' );
		self::assertSame( array(), $red, count( $red ) . ' of ' . $count . " wrong forms reached the consumer as a value:\n" . implode( "\n", $red ) );
	}

	public function testTheGeneratorMakesWrongValuesOfEveryFieldOfEveryRow(): void {
		// Behebung 5, B2: from a value of each row's form, and from one whose
		// fields that may be null are null, the generator makes at least one
		// wrong value of every field at every depth (not only its absence).
		// A field it leaves out is red here, in this table and any other.
		$fields = 0;
		foreach ( AB_MCP_Store_Inputs::TABLE as $id => $row ) {
			$form = $row['form'];
			$base = WrongForms::example( $form );
			self::assertNotNull( $base, "$id: the generator finds a value of its form" );
			self::assertNotNull( AB_MCP_Shape::read( $base, $form ), "$id: and it is of its form" );
			$nulled = self::nulled( $form, $base );
			self::assertNotNull( AB_MCP_Shape::read( $nulled, $form ), "$id: with its nullable fields null, of its form" );
			foreach ( array( 'a value' => $base, 'its nullable fields null' => $nulled ) as $how => $value ) {
				$keys = array_map( 'strval', array_keys( WrongForms::of( $form, $value ) ) );
				self::assertNotSame( array(), $keys, "$id ($how): wrong forms" );
				foreach ( self::paths( $form, '' ) as $path ) {
					++$fields;
					$hit = array_filter( $keys, static fn( string $key ): bool => 0 === strpos( $key, $path ) );
					self::assertNotSame( array(), $hit, "$id ($how): no wrong value of the field «" . rtrim( $path, ': ' ) . '»' );
				}
			}
		}
		self::assertGreaterThan( 40, $fields, 'The fields of the records, lists, maps and JSON of the table.' );
	}

	/**
	 * The prefixes the generator names the wrong values of each field with,
	 * at every depth of a form.
	 *
	 * @return string[]
	 */
	private static function paths( array $form, string $prefix ): array {
		switch ( $form['type'] ) {
			case 'record':
				$out = array();
				foreach ( $form['fields'] as $name => $field ) {
					$out[] = $prefix . $name . ': ';
					$out   = array_merge( $out, self::paths( $field, $prefix . $name . ': ' ) );
				}
				return $out;
			case 'list':
				return array_merge( array( $prefix . 'an element: ' ), self::paths( $form['item'], $prefix . 'an element: ' ) );
			case 'map':
				return array_merge( array( $prefix . 'a key: ', $prefix . 'a value: ' ), self::paths( $form['value'], $prefix . 'a value: ' ) );
			case 'json':
				return self::paths( $form['value'], $prefix . 'as its writer writes it: ' );
		}
		return array();
	}

	/**
	 * A value of a form with every field that may be null set to null, as
	 * its writer writes it.
	 *
	 * @param mixed $value Value of the form.
	 * @return mixed
	 */
	private static function nulled( array $form, $value ) {
		switch ( $form['type'] ) {
			case 'record':
				foreach ( $form['fields'] as $name => $field ) {
					$value[ $name ] = array_key_exists( 'nullable', $field ) ? null : self::nulled( $field, $value[ $name ] );
				}
				return $value;
			case 'list':
				return array_map( static fn( $item ) => self::nulled( $form['item'], $item ), $value );
			case 'map':
				foreach ( $value as $key => $item ) {
					$value[ $key ] = self::nulled( $form['value'], $item );
				}
				return $value;
			case 'json':
				$inner = self::nulled( $form['value'], AB_MCP_Shape::read( $value, $form ) );
				if ( 'json' !== $form['codec'] ) {
					return $form['codec']::encode( $inner );
				}
				return (string) json_encode( $form['object'] && is_array( $inner ) ? (object) $inner : $inner, $form['flags'] );
		}
		return $value;
	}

	public function testABackupThatCannotBeWrittenAsJsonHasNoSizeNeverZero(): void {
		// The source of budget.payload: where encode() gives no text (nested
		// past its depth), size_of() knows no size (null) and fits() refuses
		// (Behebung 4; it was strlen( '' ), 0, which fit every booking).
		$deep = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$deep = array( $deep );
		}
		self::assertNull( AB_MCP_Sync_Backup::size_of( array( 'rows' => $deep ) ) );
		$data = array( 'a' => 1 );
		self::assertSame( strlen( AB_MCP_Sync_Canon::encode( array( 'data' => $data, 'format' => AB_MCP_Sync_Backup::FORMAT, 'run_id' => PHP_INT_MAX ) ) ), AB_MCP_Sync_Backup::size_of( $data ) );
	}

	/* ------------------------------------------------------------ drivers */

	/**
	 * For each row the unit suite drives: a value of its form to make the
	 * wrong forms of (base), more wrong forms (more), the valid values with
	 * what the consumer gives for them (valid), how the consumer is reached
	 * with a value (run) and what it gives for one that is not known.
	 *
	 * @return array<string,array{base:mixed,more:array,valid:array,run:callable,unknown:callable}>
	 */

	/** A signed manifest of a unit as AB_MCP_Sync_Code_Stage::signed() gives it, with this host of updates. */
	private static function code_signed( $host ): array {
		return array(
			'kind'       => 'plugin',
			'ref'        => 'ab-it-code',
			'form'       => 'folder',
			'hash'       => str_repeat( 'b', 64 ),
			'files'      => 1,
			'bytes'      => 3,
			'version'    => '1.0.0',
			'update_uri' => $host,
			'entries'    => array( array( 'a.php', 3, str_repeat( 'a', 64 ) ) ),
		);
	}


	private static function drivers(): array {
		$now     = self::NOW;
		$day     = self::DAY;
		$null    = static fn( $got ): bool => null === $got;
		$zero    = static fn( $got ): bool => array( 0, null ) === $got;
		$refused = static fn( string $code ): callable => static fn( $got ): bool => $got instanceof \WP_Error && $code === $got->get_error_code();
		$entry   = static fn( string $area, int $run, int $created ): array => array(
			'format'   => AB_MCP_Protected_Store::FORMAT,
			'gid'      => str_repeat( 'a', 32 ),
			'area'     => $area,
			'created'  => $created,
			'user_id'  => 1,
			'run_id'   => $run,
			'declared' => 0,
			'used'     => 0,
			'label'    => '',
			'closed'   => false,
			'binding'  => '',
		);
		$keeps   = static function (): array {
			$out = array();
			foreach ( array_keys( AB_MCP_Protected_Store::SIDECAR ) as $area ) {
				$out[ $area ] = AB_MCP_Protected_Store::keep( $area );
			}
			return $out;
		};
		$kept    = static fn( array $entry, array $over ): ?bool => AB_MCP_Protected_Store::kept(
			array_replace(
				array(
					'entry'   => $entry,
					'now'     => $now,
					'open'    => array(),
					'backups' => array(),
					'keep'    => $keeps(),
				),
				$over
			)
		);
		$db      = static fn(): StoreInputsWpdb => $GLOBALS['wpdb'];
		// A closed run 10 past its period, not qualified: its backup goes.
		$closed  = static fn( array $over = array() ): array => array_replace(
			array(
				'run_id'            => '10',
				'type'              => 'push',
				'state'             => 'closed_aborted',
				'forward_closed_at' => '0',
				'keep_until'        => (string) ( $now - 10 ),
			),
			$over
		);
		$backup  = static fn(): ?bool => $kept( $entry( 'backups', 10, $now - 30 * $day ), array( 'backups' => AB_MCP_Sync_Schema::backup_rows( $now ) ) );
		$one     = static fn( string $area ): array => array( $entry( $area, 0, $now ) );
		return array(
			/* -------------------------------------------- kept() itself */
			'retention.now'            => array(
				'base'    => $now,
				'more'    => array(
					'fixed: 0'                    => 0,
					'fixed: its digits as text'    => (string) $now,
					'fixed: negative'              => -1,
				),
				'valid'   => array( array( $now, false ) ),
				'run'     => static fn( $v ) => $kept( $entry( 'reports', 0, $now - 2 * $day ), array( 'now' => $v ) ),
				'unknown' => $null,
			),
			'retention.open'           => array(
				'base'    => array( 7 ),
				'more'    => array(
					'fixed: a list from key 1' => array( 1 => 7 ),
					'fixed: 7 twice'           => array( 7, 7 ),
					'fixed: 7 as text'         => array( '7' ),
					'fixed: run 0'             => array( 0 ),
				),
				'valid'   => array( array( array( 7 ), true ), array( array(), false ) ),
				'run'     => static fn( $v ) => $kept( $entry( 'reports', 7, $now - 2 * $day ), array( 'open' => $v ) ),
				'unknown' => $null,
			),
			'retention.backups'        => array(
				'base'    => array( 7 => false ),
				'more'    => array(
					'fixed: 1 for true' => array( 7 => 1 ),
					'fixed: run 0'      => array( 0 => false, 7 => false ),
				),
				'valid'   => array( array( array( 7 => false ), false ), array( array(), true ) ),
				'run'     => static fn( $v ) => $kept( $entry( 'backups', 7, $now - 100 ), array( 'backups' => $v ) ),
				'unknown' => $null,
			),
			'retention.keep'           => array(
				'base'    => $day,
				'more'    => array(
					'fixed: its digits as text' => (string) $day,
					'fixed: negative'           => -1,
				),
				'valid'   => array( array( $day, true ), array( 0, false ) ),
				'run'     => static fn( $v ) => $kept( $entry( 'reports', 0, $now - 100 ), array( 'keep' => array( 'reports' => $v ) ) ),
				'unknown' => $null,
			),
			/* -------------------- the rows of the runs, through the database */
			'runs.open_ids'            => array(
				'base'    => array( '7' ),
				'more'    => array(
					'fixed: run 0'               => array( '0' ),
					'fixed: 7 twice'             => array( '7', '7' ),
					'fixed: a list from key 1'   => array( 1 => '7' ),
					'fixed: digits with a suffix' => array( '7a' ),
				),
				// Its consumers: kept(), and the bookings of the budget without
				// those of closed runs (all of them kept while not known).
				'valid'   => array( array( array( '7' ), array( true, array( 7 ) ) ), array( array( 7 ), array( true, array( 7 ) ) ), array( array(), array( false, array() ) ) ),
				'run'     => static function ( $v ) use ( $db, $kept, $entry, $now, $day ) {
					$db()->col = $v;
					$without   = new \ReflectionMethod( AB_MCP_Store_Budget::class, 'without_closed' );
					$book      = array(
						7 => array( 'bytes' => 1 ),
						9 => array( 'bytes' => 1 ),
					);
					return array( $kept( $entry( 'reports', 7, $now - 2 * $day ), array( 'open' => AB_MCP_Sync_Schema::open_runs() ) ), array_keys( $without->invoke( null, $book ) ) );
				},
				'unknown' => static fn( $got ): bool => array( null, array( 7, 9 ) ) === $got,
			),
			'runs.run_id'              => array(
				'base'    => '10',
				'more'    => array(
					'fixed: run 0'                  => '0',
					'fixed: digits with a suffix'   => '10a',
					'fixed: a blank before'         => ' 10',
					'fixed: a leading zero'         => '010',
					'fixed: PHP_INT_MAX+1 as text'  => '9223372036854775808',
				),
				'valid'   => array( array( '10', false ), array( 10, false ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed( array( 'run_id' => $v ) ) );
					return $backup();
				},
				'unknown' => $null,
			),
			'runs.state'               => array(
				'base'    => 'closed_aborted',
				'more'    => array(
					'fixed: another state'  => 'closed',
					'fixed: in other case'  => 'Closed_aborted',
				),
				'valid'   => array( array( 'closed_aborted', false ), array( 'open', true ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed( array( 'state' => $v ) ) );
					return $backup();
				},
				'unknown' => $null,
			),
			'runs.type'                => array(
				'base'    => 'push',
				'more'    => array( 'fixed: another type' => 'pull' ),
				'valid'   => array( array( 'push', false ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed(), $closed( array( 'run_id' => '12', 'state' => 'open', 'type' => $v ) ) );
					return $backup();
				},
				'unknown' => $null,
			),
			'runs.keep_until'          => array(
				'base'    => (string) ( $now - 10 ),
				'more'    => array(
					'fixed: negative'             => '-1',
					'fixed: digits with a suffix' => '5a',
				),
				'valid'   => array( array( (string) ( $now - 10 ), false ), array( '0', true ), array( $now + 100, true ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed( array( 'keep_until' => $v ) ) );
					return $backup();
				},
				'unknown' => $null,
			),
			'runs.forward_closed_at'   => array(
				'base'    => '0',
				'more'    => array(
					'fixed: negative'             => '-1',
					'fixed: digits with a suffix' => '5a',
				),
				'valid'   => array( array( '0', false ), array( (string) ( $now - 20 ), true ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed( array( 'forward_closed_at' => $v ) ) );
					return $backup();
				},
				'unknown' => $null,
			),
			'runs.plan'                => array(
				'base'    => '{"items":[],"reverts":[10]}',
				// A1 of the confirmation of Behebung 3, as the order names them.
				'more'    => array(
					'A1: reverts an object of a list key' => '{"reverts":{"0":11}}',
					'A1: reverts an object'               => '{"reverts":{"k":11}}',
					'A1: reverts twice'                   => '{"reverts":[10],"reverts":[11]}',
					// Spelt as encode() writes them, so each reaches the form of its row.
					'fixed: reverts empty'                => '{"items":[],"reverts":[]}',
					'fixed: reverts run 0'                => '{"items":[],"reverts":[0]}',
					'fixed: reverts a run as text'        => '{"items":[],"reverts":["10"]}',
					'fixed: reverts a run twice'          => '{"items":[],"reverts":[10,10]}',
					'fixed: reverts a negative run'       => '{"items":[],"reverts":[-10]}',
					'fixed: reverts from key 1'           => '{"items":[],"reverts":{"1":10}}',
				),
				'valid'   => array( array( '{"items":[],"reverts":[10]}', true ), array( '{"items":[],"reverts":[11]}', false ) ),
				'run'     => static function ( $v ) use ( $db, $closed, $backup ) {
					$db()->rows = array( $closed(), $closed( array( 'run_id' => '11', 'state' => 'open', 'type' => 'revert' ) ) );
					$db()->plan = $v;
					return $backup();
				},
				'unknown' => $null,
			),
			/* ------------------------------------------------------ periods */
			'period.days'              => array(
				'base'    => 30,
				'more'    => array(
					'fixed: above 3650'        => 3651,
					'fixed: digits above 3650' => '3651',
					'fixed: negative'          => -1,
				),
				'valid'   => array( array( 30, array( $now + 30 * $day, true ) ), array( '30', array( $now + 30 * $day, true ) ), array( 0, array( $now + 7 * $day, true ) ) ),
				'run'     => static function ( $v ) use ( $kept, $entry, $now ) {
					add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn() => $v );
					return array( AB_MCP_Sync_Schema::keep_until( $now, false, AB_MCP_Protected_Store::backup_days() ), $kept( $entry( 'backups', 99, $now - 100 ), array( 'keep' => array( 'backups' => AB_MCP_Protected_Store::keep( 'backups' ) ) ) ) );
				},
				'unknown' => $zero,
			),
			'period.clock'             => array(
				'base'    => $now,
				'more'    => array(
					'fixed: 0'                         => 0,
					'fixed: its digits as text'        => (string) $now,
					'fixed: digits with a suffix (B2)' => '1bad',
				),
				'valid'   => array( array( $now, array( $now + 30 * $day, $now ) ) ),
				'run'     => static function ( $v ) {
					AB_MCP_Sync_Run::$clock = static fn() => $v;
					$at                     = AB_MCP_Sync_Run::now();
					return array( AB_MCP_Sync_Schema::keep_until( $at, false, 30 ), $at );
				},
				'unknown' => $zero,
			),
			'period.qualified'         => array(
				'base'    => true,
				'more'    => array(
					'fixed: "false"' => 'false',
					'fixed: 0'       => 0,
					'fixed: 1'       => 1,
				),
				'valid'   => array( array( true, $now ), array( false, $now + 7 * $day ) ),
				'run'     => static fn( $v ) => AB_MCP_Sync_Schema::keep_until( $now, $v, 0 ),
				'unknown' => static fn( $got ): bool => 0 === $got,
			),
			/* ------------------------------ the stage of a unit of code */
			'stage.update_uri'         => array(
				'base'    => 'example.com',
				'more'    => array(
					'fixed: a list of a host' => array( 'wordpress.org' ),
					'fixed: false'            => false,
				),
				// Its consumer from_org(): a host of wordpress.org or none
				// lets it go on (to the file system, here not direct), another
				// host refuses as such, an unknown one refuses as unknown.
				'valid'   => array( array( '', 'code:file_mods' ), array( 'wordpress.org', 'code:file_mods' ), array( 'w.org', 'code:file_mods' ), array( 'example.com', 'code:update_uri' ) ),
				'run'     => static function ( $v ) {
					AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, 'naht_sync_code_stage', new StoreInputsCodeCalls() );
					try {
						$got = AB_MCP_Sync_Code_Stage::from_org( self::code_signed( AB_MCP_Sync_Code_Stage::update_host( array( 'update_uri' => $v ) ) ), '/nowhere' );
					} finally {
						AB_MCP_Test_Seam::restore_all();
					}
					return is_wp_error( $got ) ? $got->get_error_code() : $got;
				},
				'unknown' => static fn( $got ): bool => 'code:update_uri_unknown' === $got,
			),
			'stage.update_head'        => array(
				'base'    => array( 'Update URI' => 'https://example.com/x' ),
				'more'    => array(
					'fixed: the address alone' => 'https://example.com/x',
					'fixed: none read'         => null,
					'fixed: a field more'      => array(
						'Update URI' => '',
						'Version'    => '1',
					),
				),
				// Its consumer host_of_head(): the host of a head read whole, ''
				// where it names none; a head not of its form is unknown (A2).
				'valid'   => array( array( array( 'Update URI' => '' ), '' ), array( array( 'Update URI' => 'https://Updates.Example.com/b?key=1' ), 'updates.example.com' ) ),
				'run'     => static fn( $v ) => \AB_MCP_Sync_Env::host_of_head( $v ),
				'unknown' => $null,
			),
			'stage.head'               => array(
				'base'    => array(
					'Plugin Name'       => 'A',
					'Requires PHP'      => '',
					'Requires at least' => '',
				),
				'more'    => array(
					'fixed: none read'          => null,
					'fixed: its values in a list' => array( 'A', '', '' ),
				),
				// Its consumer head_refusal(): a head of no main file holds; a
				// head not of its form refuses as unknown (A4), never as none.
				'valid'   => array(
					array(
						array(
							'Plugin Name'       => '',
							'Requires PHP'      => '',
							'Requires at least' => '',
						),
						null,
					),
				),
				'run'     => static fn( $v ) => AB_MCP_Sync_Code_Stage::head_refusal( $v, 'a.php', 'plugin' ),
				'unknown' => static fn( $got ): bool => is_string( $got ) && false !== strpos( $got, 'could not be read whole' ),
			),
			'stage.version'            => array(
				'base'    => '1.0.0',
				'more'    => array(
					'fixed: a number' => 1.0,
					'fixed: none'     => null,
				),
				// Its consumer from_org(): a version of its form goes on (to the
				// file system, here not direct); one not of its form fetches
				// nothing.
				'valid'   => array( array( '1.0.0', 'code:file_mods' ) ),
				'run'     => static function ( $v ) {
					AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, 'naht_sync_code_stage', new StoreInputsCodeCalls() );
					try {
						$got = AB_MCP_Sync_Code_Stage::from_org( array( 'version' => $v ) + self::code_signed( '' ), '/nowhere' );
					} finally {
						AB_MCP_Test_Seam::restore_all();
					}
					return is_wp_error( $got ) ? $got->get_error_code() : $got;
				},
				'unknown' => static fn( $got ): bool => 'code:org_unit' === $got,
			),
			/* ------------------------------------------------------ budgets */
			'budget.max_packages'      => array(
				'base'    => 5,
				'more'    => array(
					'fixed: negative'             => -1,
					'fixed: digits with a suffix' => '5bad',
					'fixed: a float'              => 5.0,
				),
				'valid'   => array( array( 5, null ), array( '5', null ) ),
				'run'     => static function ( $v ) use ( $one ) {
					add_filter( AB_MCP_Store_Budget::FILTER_PACKAGES, static fn() => $v );
					return AB_MCP_Store_Budget::package_refusal( $one( 'in' ), 'in', 1, 10 );
				},
				'unknown' => $refused( 'ab_mcp_store_limit_unknown' ),
			),
			'budget.max_bytes'         => array(
				'base'    => 1000,
				'more'    => array(
					'fixed: negative'             => -1,
					'fixed: digits with a suffix' => '5bad',
					'fixed: a float'              => 5.0,
				),
				'valid'   => array( array( 1000, null ), array( '1000', null ) ),
				'run'     => static function ( $v ) use ( $one ) {
					add_filter( AB_MCP_Store_Budget::FILTER_BYTES, static fn() => $v );
					return AB_MCP_Store_Budget::package_refusal( $one( 'in' ), 'in', 1, 10 );
				},
				'unknown' => $refused( 'ab_mcp_store_limit_unknown' ),
			),
			'budget.max_exports'       => array(
				'base'    => 3,
				'more'    => array(
					'fixed: negative'             => -1,
					'fixed: digits with a suffix' => '5bad',
					'fixed: a float'              => 5.0,
				),
				'valid'   => array( array( 3, null ), array( '3', null ) ),
				'run'     => static function ( $v ) use ( $one ) {
					add_filter( AB_MCP_Store_Budget::FILTER_EXPORTS, static fn() => $v );
					return AB_MCP_Store_Budget::package_refusal( $one( 'out' ), 'out', 1, 10 );
				},
				'unknown' => $refused( 'ab_mcp_store_limit_unknown' ),
			),
			'budget.declared'          => array(
				'base'    => 10,
				'more'    => array(
					'fixed: its digits as text' => '10',
					'fixed: negative'           => -1,
				),
				'valid'   => array( array( 10, null ) ),
				'run'     => static fn( $v ) => AB_MCP_Store_Budget::package_refusal( array(), 'in', 1, $v ),
				'unknown' => $refused( 'ab_mcp_store_declare' ),
			),
		);
	}
}

/** The calls of the stage for the driver of stage.update_uri: a file system that is not direct, nothing else. */
final class StoreInputsCodeCalls {

	public function method(): string {
		return 'ftpext';
	}
}
