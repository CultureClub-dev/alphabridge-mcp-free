<?php
/**
 * The rows of AB_MCP_Store_Inputs::TABLE whose consumer needs the folder of
 * the store (Z8b branch 3, Behebung 4, Option C1; WrongForms::INTEGRATION):
 * every wrong form of each row's form (WrongForms) at its consumer, in the
 * store of site A. A sidecar: the clean-up keeps its group and counts it as
 * unknown, and fits() refuses (the stock of its area is not known). A mark:
 * nothing in backups goes, the clean-up counts the group as unknown (B1).
 * The time of a file: the foreign sidecar stays and counts. A budget: the
 * call is refused with its reason. Valid values reach the consumer as values
 * (the group goes, the booking is made, the backup fits). A file holds only
 * texts, so its rows take the wrong forms that are texts. Besides the forms
 * made from the form in the code, each driver holds fixed wrong forms
 * (more, "fixed: ..."): they stay wrong where the form in the code is
 * loosened, and hold the form of each row.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Store_Calls;
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Store_Inputs;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AlphaBridge\Tests\WrongForms;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__ . '/support/class-it-store-calls.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-backup.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-run.php';
require_once dirname( __DIR__ ) . '/WrongForms.php';

final class StoreInputsTest extends TestCase {

	/** The seam of the store. */
	private const SEAM = 'naht_protected_store';

	/** The field of a sidecar of each type (AB_MCP_Protected_Store::SIDECAR, backups). */
	private const FIELD = array(
		'format' => 'format',
		'gid'    => 'gid',
		'time'   => 'created',
		'count'  => 'used',
		'run'    => 'run_id',
		'label'  => 'label',
		'text'   => 'binding',
		'bool'   => 'closed',
	);

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	protected function setUp(): void {
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		$this->fresh();
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/** A site A of its snapshot and an empty store, for each row. */
	private function fresh(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_IT_Sites::reset( 'a' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
		self::assertTrue( AB_MCP_Protected_Store::available(), 'The store beside WordPress is there.' );
	}

	public function testEveryWrongFormOfARowReachesItsConsumerInTheStoreAsUnknown(): void {
		$red   = array();
		$count = 0;
		foreach ( WrongForms::INTEGRATION as $id ) {
			self::assertArrayHasKey( $id, AB_MCP_Store_Inputs::TABLE );
			$this->fresh();
			$driver = $this->driver( $id );
			$form   = AB_MCP_Store_Inputs::TABLE[ $id ]['form'];
			$wrong  = WrongForms::of( $form, $driver['base'] ) + $driver['more'];
			foreach ( $driver['valid'] as $i => $case ) {
				$got = ( $driver['run'] )( $case[0] );
				if ( $got !== $case[1] ) {
					$red[] = "$id, valid #$i: " . var_export( $got, true );
				}
			}
			foreach ( $wrong as $what => $value ) {
				if ( ( $driver['texts'] && ! is_string( $value ) ) || ( null !== $driver['written'] && ( $driver['written'] )( $value ) ) ) {
					continue;
				}
				++$count;
				$got = ( $driver['run'] )( $value );
				if ( ! ( $driver['unknown'] )( $got ) ) {
					$red[] = "$id, $what: " . var_export( $got, true );
				}
			}
		}
		self::assertGreaterThan( 400, $count, 'Every row with its wrong forms.' );
		self::assertSame( array(), $red, count( $red ) . ' of ' . $count . " wrong forms reached the consumer as a value:\n" . implode( "\n", $red ) );
	}

	public function testATimeOfTheCleanUpNotOfItsFormRemovesNothing(): void {
		// retention.now at sweep(), besides kept() (the unit suite drives
		// that): with a time not of its form no group goes by its age, no
		// foreign sidecar and no folder of a mark without one goes; the group
		// counts. Each wrong form, and the fixed ones.
		$now   = time();
		$forms = WrongForms::of( AB_MCP_Store_Inputs::TABLE['retention.now']['form'], $now );
		// cleanup( null ) is the clean-up now (its contract: the time of the
		// clock where none is given), no wrong form.
		unset( $forms['null'] );
		$forms['fixed: 0']                  = 0;
		$forms['fixed: its digits as text'] = (string) $now;
		$forms['fixed: negative']           = -1;
		foreach ( $forms + array( 'valid' => $now ) as $what => $value ) {
			$this->fresh();
			// Both groups first: open() sweeps the store with time().
			$g     = self::group( 'reports', 0 );
			$f     = self::group( 'reports', 0 );
			$entry = json_decode( (string) file_get_contents( $g['side'] ), true );
			$entry['created'] = $now - 30 * 86400;
			file_put_contents( $g['side'], (string) json_encode( $entry, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
			$foreign        = json_decode( (string) file_get_contents( $f['side'] ), true );
			$foreign['gid']     = str_repeat( 'f', 32 );
			$foreign['created'] = $now - 30 * 86400;
			file_put_contents( $f['side'], (string) json_encode( $foreign, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
			touch( $f['side'], $now - 3 * 86400 );
			$half = self::store() . '/marks/' . str_repeat( 'b', 32 );
			@mkdir( self::store() . '/marks', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			mkdir( $half, 0700 );
			touch( $half, $now - 3 * 86400 );
			$done = AB_MCP_Protected_Store::cleanup( $value );
			$got  = array( is_dir( $g['dir'] ), is_file( $f['side'] ), is_dir( $half ), $done['unknown'] > 0 );
			self::assertSame( 'valid' === $what ? array( false, false, false, false ) : array( true, true, true, true ), $got, $what );
		}
	}

	public function testTheClockOfTheRunsAgesNoTempFileAndAbandonsNoMakingWithoutATime(): void {
		// period.clock at its consumers in the store (Behebung 5, B1, and
		// 5b): the age of a temp file (delete_path()), of a making whose
		// record never came (AB_MCP_Sync_Export::discard_stale()) and of a
		// group at a clean-up without a time given (cleanup(),
		// backup_rows()), the time of a new group (open()) and of a mark that
		// closes (close_mark_in()), each by the one checked clock,
		// AB_MCP_Sync_Run::now(). A clock not of its form ages nothing, makes
		// no group and closes a mark with 0 (never old). Each wrong form, and
		// the fixed ones.
		require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
		$now     = time();
		$discard = new ReflectionMethod( \AB_MCP_Sync_Export::class, 'discard_stale' );
		$close   = new ReflectionMethod( AB_MCP_Protected_Store::class, 'close_mark_in' );
		$forms   = WrongForms::of( AB_MCP_Store_Inputs::TABLE['period.clock']['form'], $now );
		$forms['fixed: 0']                         = 0;
		$forms['fixed: its digits as text']        = (string) $now;
		$forms['fixed: digits with a suffix (B2)'] = '1bad';
		foreach ( $forms + array( 'valid' => $now ) as $what => $value ) {
			$this->fresh();
			// The groups first: open() sweeps the store.
			$gid = AB_MCP_Protected_Store::open( 'out', array( 'declared' => 100, 'label' => 'series:abc' ) );
			self::assertIsString( $gid, $what );
			$old = self::group( 'reports', 0 );
			@mkdir( self::store() . '/tmp', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$temp = self::store() . '/tmp/.ab-tmp-alt';
			file_put_contents( $temp, 'x' );
			touch( $temp, $now - 3 * 3600 );
			$dir   = self::store() . '/out/' . AB_MCP_Payload_File::name( 'group|out|' . $gid, AB_MCP_Protected_Store::install_id(), '' );
			$entry = json_decode( (string) file_get_contents( $dir . '.json' ), true );
			$entry['created'] = $now - 2 * \AB_MCP_Sync_Export::ABANDONED;
			file_put_contents( $dir . '.json', (string) json_encode( $entry, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
			// A report a month old, and an open mark to close.
			$side  = json_decode( (string) file_get_contents( $old['side'] ), true );
			$side['created'] = $now - 30 * 86400;
			file_put_contents( $old['side'], (string) json_encode( $side, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
			$mid  = str_repeat( 'c', 32 );
			$mark = self::store() . '/marks/' . $mid;
			@mkdir( self::store() . '/marks', 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			mkdir( $mark, 0700 );
			file_put_contents( $mark . '/mark.json', self::mark_text( $old, $mid ) );
			\AB_MCP_Sync_Run::$clock = static fn() => $value;
			try {
				AB_MCP_Protected_Store::cleanup();
				$discard->invoke( null );
				$made   = AB_MCP_Protected_Store::open( 'reports', array( 'label' => 'report' ) );
				$rows   = AB_MCP_Sync_Schema::backup_rows();
				$closed = $close->invoke( null, self::store(), $mid, json_decode( (string) file_get_contents( $mark . '/mark.json' ), true ) );
			} finally {
				\AB_MCP_Sync_Run::$clock = null;
			}
			$after = json_decode( (string) file_get_contents( $mark . '/mark.json' ), true );
			$got   = array( is_file( $temp ), is_dir( $dir ), is_dir( $old['dir'] ), is_wp_error( $made ) ? $made->get_error_code() : 'made', null === $rows, $closed, 'closed' === $after['state'] && 0 === $after['closed'] );
			self::assertSame( 'valid' === $what ? array( false, false, false, 'made', false, true, false ) : array( true, true, true, 'ab_mcp_store_clock', true, true, true ), $got, (string) $what );
			self::remove_tree( $mark );
		}
	}

	public function testOpenTakesTheDeclaredBytesOfAGroupOnlyInTheFormOfTheirRow(): void {
		// budget.declared at its second consumer, open() of a group (the
		// row is driven at package_refusal() in the unit suite): each wrong
		// form, and the fixed ones, is refused before anything is made.
		$forms = WrongForms::of( AB_MCP_Store_Inputs::TABLE['budget.declared']['form'], 10 );
		$forms['fixed: its digits as text'] = '10';
		$forms['fixed: negative']           = -1;
		$areas = array(
			'in'      => array(
				'label'   => 'import:abc',
				'binding' => 'print',
			),
			'reports' => array( 'label' => 'report' ),
		);
		foreach ( $areas as $area => $meta ) {
			foreach ( $forms as $what => $value ) {
				$got = AB_MCP_Protected_Store::open( $area, array( 'declared' => $value ) + $meta );
				self::assertInstanceOf( \WP_Error::class, $got, "$area, $what" );
				self::assertSame( 'ab_mcp_store_declare', $got->get_error_code(), "$area, $what" );
			}
			self::assertIsString( AB_MCP_Protected_Store::open( $area, array( 'declared' => 10 ) + $meta ), "$area: 10 bytes, opened" );
		}
	}

	/* ------------------------------------------------------------ drivers */

	/**
	 * The driver of a row: the value its wrong forms are made of (base),
	 * more wrong forms, the valid values with what the consumer gives, how
	 * the consumer is reached with a value, what it gives for a value not
	 * known, and whether the row is a file (only texts).
	 *
	 * @param string $id Row.
	 * @return array{base:mixed,more:array,valid:array,run:callable,unknown:callable,texts:bool,written:callable|null}
	 */
	private function driver( string $id ): array {
		$now     = time();
		$data    = array( 'row' => 'eine Zeile' );
		$refused = static fn( string $code ): callable => static fn( $got ): bool => is_string( $got ) && $code === $got;
		$answer  = static fn( $got ) => is_wp_error( $got ) ? $got->get_error_code() : ( is_array( $got ) ? 'booked' : $got );
		if ( 0 === strpos( $id, 'sidecar.' ) ) {
			// A backup of run 4, closed and past its period: it goes where its
			// sidecar is whole, and fits() counts the stock of backups.
			self::run_row( 4, 'closed_done', $now - 10 );
			self::assertIsArray( AB_MCP_Store_Budget::book( 4, 1048576 ) );
			$probe = self::group( 'backups', 4 );
			$entry = json_decode( (string) file_get_contents( $probe['side'] ), true );
			$text  = (string) file_get_contents( $probe['side'] );
			self::drop( $probe );
			$json      = 'sidecar.json' === $id;
			$field     = $json ? null : self::FIELD[ substr( $id, 8 ) ];
			$probe_gid = $probe['gid'];
			$fixed     = array(
				'json'   => array(
					'fixed: a slash escaped' => str_replace( '/', '\\/', $text ),
					'fixed: a blank after it' => $text . ' ',
				),
				'format' => array( 'fixed: another format' => 'ab-mcp-store/2' ),
				'gid'    => array( 'fixed: in capitals' => strtoupper( (string) $entry['gid'] ) ),
				'time'   => array(
					'fixed: 0'                  => 0,
					'fixed: its digits as text' => (string) $entry['created'],
				),
				'count'  => array(
					'fixed: its digits as text' => (string) $entry['used'],
					'fixed: negative'           => -1,
				),
				'run'    => array(
					'fixed: run 0'              => 0,
					'fixed: its digits as text' => '4',
				),
				'label'  => array(
					'fixed: a blank'          => 'backup 4',
					'fixed: 192 characters'   => str_repeat( 'a', 192 ),
				),
				'text'   => array( 'fixed: a number' => 5 ),
				'bool'   => array(
					'fixed: 1'       => 1,
					'fixed: "false"' => 'false',
				),
			);
			$run       = function ( $value ) use ( $json, $field, $data, $probe_gid ) {
				$g = self::group( 'backups', 4 );
				if ( $json ) {
					// The text of the probe, of this group's gid.
					file_put_contents( $g['side'], str_replace( $probe_gid, $g['gid'], $value ) );
				} else {
					$entry = json_decode( (string) file_get_contents( $g['side'] ), true );
					if ( 'gid' === $field && is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}\z/', $value ) ) {
						$value = $entry['gid'];
					}
					$entry[ $field ] = $value;
					file_put_contents( $g['side'], (string) json_encode( $entry, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
				}
				$fits   = AB_MCP_Sync_Backup::fits( 4, $data );
				$listed = AB_MCP_Protected_Store::groups( 'backups' );
				$listed = is_array( $listed ) && in_array( $g['gid'], array_column( $listed, 'gid' ), true );
				$done   = AB_MCP_Protected_Store::cleanup( time() );
				$out    = array( is_wp_error( $fits ) ? $fits->get_error_code() : $fits, is_dir( $g['dir'] ), $done['unknown'] > 0, $listed );
				self::drop( $g );
				return $out;
			};
			return array(
				'base'    => $json ? $text : $entry[ $field ],
				'more'    => $fixed[ $json ? 'json' : substr( $id, 8 ) ],
				// fits() refuses, the group stays and counts, groups() offers it
				// to no owner.
				'valid'   => array( array( $json ? $text : $entry[ $field ], array( true, false, false, true ) ) ),
				'run'     => $run,
				'unknown' => static fn( $got ): bool => array( 'ab_mcp_store_stock_unknown', true, true, false ) === $got,
				'texts'   => $json,
				// 4.0 is written 4: a value its writer writes as the valid one is no wrong form in the file.
				'written' => $json ? null : static fn( $v ): bool => json_decode( (string) json_encode( $v ), true ) === $entry[ $field ],
			);
		}
		if ( 'mark.json' === $id ) {
			// An open mark names a tree in a backup of run 3, closed and past
			// its period: the rest of the group goes where the mark is read;
			// where it is not, nothing in backups goes and it counts (B1).
			self::run_row( 3, 'closed_done', $now - 10 );
			$probe = self::group( 'backups', 3 );
			$text  = self::mark_text( $probe, str_repeat( 'c', 32 ) );
			self::drop( $probe );
			$mark  = json_decode( $text, true );
			$as    = static fn( array $over, int $flags = AB_MCP_Store_Inputs::MARK_FLAGS ): string => (string) json_encode( array_replace( $mark, $over ), $flags );
			$tree  = $mark['trees'][0];
			$other = array_replace( $tree, array( 'path' => $tree['path'] . '-2' ) );
			$third = array_replace( $tree, array( 'path' => $tree['path'] . '-3' ) );
			return array(
				'base'    => $text,
				'more'    => array(
					'fixed: no tree'              => $as( array( 'trees' => array() ) ),
					'fixed: three trees'          => $as( array( 'trees' => array( $tree, $other, $third ) ) ),
					'fixed: a tree twice'         => $as( array( 'trees' => array( $tree, $tree ) ) ),
					'fixed: a tree from key 1'    => $as( array( 'trees' => array( 1 => $tree ) ) ),
					'fixed: a tree with more'     => $as( array( 'trees' => array( $tree + array( 'more' => 1 ) ) ) ),
					'fixed: a slash escaped'      => $as( array(), AB_MCP_Store_Inputs::MARK_FLAGS & ~JSON_UNESCAPED_SLASHES ),
					'fixed: closed as text'       => $as( array( 'closed' => '0' ) ),
					'fixed: another state'        => $as( array( 'state' => 'opened' ) ),
				),
				'valid'   => array( array( 'valid', array( false, false ) ) ),
				'run'     => function ( $value ) {
					$g   = self::group( 'backups', 3 );
					$mid = str_repeat( 'c', 32 );
					$dir = self::store() . '/marks/' . $mid;
					mkdir( $dir, 0700 );
					file_put_contents( $dir . '/mark.json', 'valid' === $value ? self::mark_text( $g, $mid ) : $value );
					$done = AB_MCP_Protected_Store::cleanup( time() );
					$out  = array( is_file( $g['dir'] . '/post-1' ), $done['unknown'] > 0 );
					self::drop( $g );
					self::remove_tree( $dir );
					return $out;
				},
				'unknown' => static fn( $got ): bool => array( true, true ) === $got,
				'texts'   => true,
				'written' => null,
			);
		}
		if ( 'file.mtime' === $id ) {
			// Another installation's sidecar of a report, past its time: it
			// goes a day after its file was written, by the time lstat() says.
			return array(
				'base'    => $now - 2 * 86400,
				'more'    => array(
					'fixed: its digits as text' => (string) ( $now - 2 * 86400 ),
					'fixed: negative'           => -1,
				),
				'valid'   => array( array( $now - 2 * 86400, array( false, false, false, false ) ) ),
				'run'     => function ( $value ) use ( $now ) {
					// Its three consumers: a foreign sidecar, a temp file of tmp/
					// and a folder of a mark that holds none, each with the time.
					$g     = self::group( 'reports', 0 );
					$entry = json_decode( (string) file_get_contents( $g['side'] ), true );
					$entry['gid']     = str_repeat( 'f', 32 );
					$entry['created'] = $now - 30 * 86400;
					file_put_contents( $g['side'], (string) json_encode( $entry, AB_MCP_Store_Inputs::SIDECAR_FLAGS ) );
					$temp = self::store() . '/tmp/.ab-tmp-alt';
					file_put_contents( $temp, 'x' );
					$half = self::store() . '/marks/' . str_repeat( 'b', 32 );
					mkdir( $half, 0700 );
					$calls        = new AB_MCP_IT_Store_Calls();
					$calls->mtime = array(
						$g['side'] => $value,
						$temp      => $value,
						$half      => $value,
					);
					AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
					$done = AB_MCP_Protected_Store::cleanup( time() );
					AB_MCP_Test_Seam::restore_all();
					$out = array( is_file( $g['side'] ), $done['unknown'] > 0, is_file( $temp ), is_dir( $half ) );
					self::drop( $g );
					self::remove_tree( $temp );
					self::remove_tree( $half );
					return $out;
				},
				'unknown' => static fn( $got ): bool => array( true, true, true, true ) === $got,
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.book_run' === $id || 'budget.book_bytes' === $id ) {
			$run = 'budget.book_run' === $id;
			return array(
				'base'    => $run ? 5 : 100,
				'more'    => $run ? array(
					'fixed: run 0'              => 0,
					'fixed: its digits as text' => '5',
				) : array(
					'fixed: negative'           => -1,
					'fixed: its digits as text' => '100',
				),
				// The run of a booking: book() and release() read it.
				'valid'   => array( array( $run ? 5 : 100, $run ? array( 'booked', true ) : 'booked' ) ),
				'run'     => static fn( $value ) => $run ? array( $answer( AB_MCP_Store_Budget::book( $value, 100 ) ), $answer( AB_MCP_Store_Budget::release( $value ) ) ) : $answer( AB_MCP_Store_Budget::book( 5, $value ) ),
				'unknown' => $run ? static fn( $got ): bool => array( 'ab_mcp_store_booking', 'ab_mcp_store_booking' ) === $got : $refused( 'ab_mcp_store_booking' ),
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.free' === $id ) {
			return array(
				'base'    => 1e12,
				'more'    => array(
					'fixed: negative'     => -1.0,
					'fixed: infinite'     => INF,
					'fixed: not a number' => NAN,
					'fixed: a text'       => '1000000000000',
				),
				'valid'   => array( array( 1e12, 'booked' ), array( 1000000000000, 'booked' ) ),
				'run'     => static function ( $value ) use ( $answer ) {
					$calls       = new AB_MCP_IT_Store_Calls();
					$calls->free = array( true, $value );
					AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
					$got = AB_MCP_Store_Budget::book( 5, 100 );
					AB_MCP_Test_Seam::restore_all();
					return $answer( $got );
				},
				'unknown' => $refused( 'ab_mcp_store_space_unknown' ),
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.bookings' === $id ) {
			$book = array( 'bytes' => 1048576, 'booked' => $now, 'user_id' => 1 );
			$text = (string) json_encode( (object) array( 4 => $book ) );
			return array(
				'base'    => $text,
				'more'    => array(
					'fixed: run 0'            => (string) json_encode( (object) array( 0 => $book, 4 => $book ) ),
					'fixed: bytes as text'    => (string) json_encode( (object) array( 4 => array_replace( $book, array( 'bytes' => '1048576' ) ) ) ),
					'fixed: another field'    => (string) json_encode( (object) array( 4 => $book + array( 'more' => 1 ) ) ),
					'fixed: a list at the root' => (string) json_encode( array( $book ) ),
				),
				// Its consumers: fits(), book() and release() (Behebung 5, B2).
				'valid'   => array( array( $text, array( true, 'booked', true ) ), array( '{}', array( 'ab_mcp_sync_backup_booking', 'booked', true ) ) ),
				'run'     => static function ( $value ) use ( $data, $answer ) {
					$file = self::store() . '/bookings.json';
					$out  = array();
					foreach ( array( 'fits', 'book', 'release' ) as $call ) {
						file_put_contents( $file, $value );
						$out[] = $answer( 'fits' === $call ? AB_MCP_Sync_Backup::fits( 4, $data ) : ( 'book' === $call ? AB_MCP_Store_Budget::book( 5, 100 ) : AB_MCP_Store_Budget::release( 4 ) ) );
					}
					return $out;
				},
				'unknown' => static fn( $got ): bool => array_fill( 0, 3, 'ab_mcp_store_bookings_unread' ) === $got,
				'texts'   => true,
				'written' => null,
			);
		}
		self::assertIsArray( AB_MCP_Store_Budget::book( 4, 1048576 ) );
		if ( 'backup.run' === $id ) {
			return array(
				'base'    => '4',
				'more'    => array(
					'fixed: run 0'                => '0',
					'fixed: digits with a suffix' => '4a',
				),
				// The run of a backup: fits() and group() read it.
				'valid'   => array( array( '4', array( true, 'group' ) ), array( 4, array( true, 'group' ) ) ),
				'run'     => static function ( $value ) use ( $answer, $data ) {
					$group = AB_MCP_Sync_Backup::group( $value );
					return array( $answer( AB_MCP_Sync_Backup::fits( $value, $data ) ), is_string( $group ) ? 'group' : $answer( $group ) );
				},
				'unknown' => static fn( $got ): bool => array( 'ab_mcp_sync_backup_run', 'ab_mcp_sync_backup_run' ) === $got,
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.payload' === $id ) {
			$fits = new ReflectionMethod( AB_MCP_Sync_Backup::class, 'fits_bytes' );
			return array(
				'base'    => 10,
				'more'    => array(
					'fixed: negative'           => -1,
					'fixed: its digits as text' => '10',
				),
				'valid'   => array( array( 10, true ), array( 0, true ) ),
				'run'     => static fn( $value ) => $answer( $fits->invoke( null, 4, $value ) ),
				'unknown' => $refused( 'ab_mcp_sync_backup_size' ),
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.listing' === $id ) {
			$dir  = self::store() . '/backups';
			$real = array_values( (array) scandir( $dir ) );
			return array(
				'base'    => $real,
				'more'    => array(
					'no listing (false)'       => false,
					'fixed: a name twice'      => array_merge( $real, array( $real[0] ) ),
					'fixed: a list from key 1' => array_combine( range( 1, count( $real ) ), $real ),
					'fixed: a number'          => array_merge( $real, array( 5 ) ),
				),
				// Its consumers: the stock of fits(), and groups().
				'valid'   => array( array( $real, array( true, 'listed' ) ) ),
				'run'     => static function ( $value ) use ( $dir, $data, $answer ) {
					$calls        = new AB_MCP_IT_Store_Calls();
					$calls->names = array( $dir => $value );
					AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
					$got    = AB_MCP_Sync_Backup::fits( 4, $data );
					$listed = AB_MCP_Protected_Store::groups( 'backups' );
					AB_MCP_Test_Seam::restore_all();
					return array( $answer( $got ), is_array( $listed ) ? 'listed' : $answer( $listed ) );
				},
				'unknown' => static fn( $got ): bool => array( 'ab_mcp_store_stock_unknown', 'ab_mcp_store_unlisted' ) === $got,
				'texts'   => false,
				'written' => null,
			);
		}
		if ( 'budget.held_names' === $id || 'budget.file_size' === $id ) {
			// put() counts what a group holds from its files (held()): where
			// they cannot all be counted it writes nothing (Behebung 4).
			$names = 'budget.held_names' === $id;
			$g     = self::group( 'backups', 4 );
			$file  = $g['dir'] . '/post-1';
			$real  = $names ? array_values( (array) scandir( $g['dir'] ) ) : (int) filesize( $file );
			$n     = 0;
			return array(
				'base'    => $real,
				'more'    => $names ? array(
					'no listing (false)'       => false,
					'fixed: a name twice'      => array_merge( $real, array( $real[0] ) ),
					'fixed: a list from key 1' => array_combine( range( 1, count( $real ) ), $real ),
				) : array(
					'no size (false)'           => false,
					'fixed: negative'           => -1,
					'fixed: its digits as text' => (string) $real,
				),
				'valid'   => array( array( $real, 'written' ) ),
				'run'     => static function ( $value ) use ( $names, $g, $file, &$n ) {
					$calls = new AB_MCP_IT_Store_Calls();
					if ( $names ) {
						$calls->names = array( $g['dir'] => $value );
					} else {
						$calls->size = array( $file => $value );
					}
					AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, self::SEAM, $calls );
					$got = AB_MCP_Protected_Store::put( 'backups', $g['gid'], 'more-' . ( ++$n ), 'x' );
					AB_MCP_Test_Seam::restore_all();
					return is_wp_error( $got ) ? $got->get_error_code() : ( is_array( $got ) ? 'written' : $got );
				},
				'unknown' => $refused( 'ab_mcp_store_used_unknown' ),
				'texts'   => false,
				'written' => null,
			);
		}
		throw new RuntimeException( "The row $id has no driver in this test." );
	}

	/* ------------------------------------------------------------ helpers */

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function store(): string {
		$root = AB_MCP_Protected_Store::root();
		self::assertIsString( $root );
		return $root;
	}

	/** A run row of site A. */
	private static function run_row( int $run, string $state, int $keep_until = 0, int $qualified = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			AB_MCP_Sync_Schema::table( 'runs' ),
			array(
				'run_id'            => $run,
				'type'              => 'push',
				'state'             => $state,
				'keep_until'        => $keep_until,
				'forward_closed_at' => $qualified,
			)
		);
		AB_MCP_Sync_Schema::forget();
	}

	/**
	 * A group of an area with a file, of a run (0: none).
	 *
	 * @return array{gid:string,dir:string,side:string}
	 */
	private static function group( string $area, int $run ): array {
		$meta = 'backups' === $area ? array( 'label' => 'backup:' . $run ) : array( 'label' => 'report' );
		$gid  = AB_MCP_Protected_Store::open( $area, array( 'run_id' => $run ) + $meta );
		self::assertIsString( $gid, $area );
		self::assertIsArray( AB_MCP_Protected_Store::put( $area, $gid, 'post-1', 'eine Zeile' ) );
		$dir = self::store() . '/' . $area . '/' . AB_MCP_Payload_File::name( 'group|' . $area . '|' . $gid, AB_MCP_Protected_Store::install_id(), '' );
		return array(
			'gid'  => $gid,
			'dir'  => $dir,
			'side' => $dir . '.json',
		);
	}

	/** A group taken out by hand, whatever is left of it. */
	private static function drop( array $group ): void {
		self::remove_tree( $group['dir'] );
		self::remove_tree( $group['side'] );
	}

	/** The text of an open mark naming a tree in a group, as put_mark() writes it. */
	private static function mark_text( array $group, string $mid ): string {
		return (string) json_encode(
			array(
				'format' => AB_MCP_Protected_Store::MARK_FORMAT,
				'mid'    => $mid,
				'state'  => 'open',
				'begun'  => time(),
				'closed' => 0,
				'store'  => self::store(),
				'where'  => 'outside',
				'run_id' => 3,
				'seq'    => 1,
				'op'     => 'replace',
				'unit'   => array(
					'kind' => 'plugin',
					'ref'  => 'ab-it-baum',
				),
				'target' => AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/plugins/ab-it-baum',
				'trees'  => array(
					array(
						'role' => 'backup',
						'path' => 'backups/' . basename( $group['dir'] ) . '/ab-it-baum',
						'form' => 'folder',
						'dev'  => 1,
						'ino'  => 2,
						'hash' => null,
					),
				),
				'active' => array(),
				'note'   => '',
			),
			AB_MCP_Store_Inputs::MARK_FLAGS
		);
	}

	/** Remove what the store made beside WordPress and in the uploads of site A. */
	private static function wipe(): void {
		$paths   = array( self::root() . '/ab-mcp-store' );
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		foreach ( array_merge( (array) glob( $uploads . '/ab-mcp-store-*' ), (array) glob( $uploads . '/.ab-tmp-*' ), (array) glob( $uploads . '/ab-mcp-probe-*' ), (array) glob( self::root() . '/run/ab-store-*' ) ) as $path ) {
			$paths[] = (string) $path;
		}
		foreach ( $paths as $path ) {
			if ( 0 !== strpos( $path, self::root() . '/' ) ) {
				throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
			}
			self::remove_tree( $path );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		chmod( $path, 0700 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}
