<?php
/**
 * The record of an address change that is not finished
 * (AB_MCP_Site_Address_Journal, since 4.17.0): read here, written by the
 * Pro add-on's wp_change_site_address.
 *
 * What must hold:
 *
 * - The record is read as its row ($wpdb->get_var() on option_value), not
 *   through get_option(): what a filter or a cache returns for the option
 *   changes nothing. No row, nothing shown: wp_site_info leaves the key
 *   open_address_change out and the card «Connection check» shows no
 *   notice.
 * - A readable record is one JSON object of the format in the head of the
 *   class: v, state, old and new are required, time, user, rest, next and
 *   undo optional; each key named there that is present in another form
 *   makes the whole record unreadable, which is still shown — as «not
 *   finished, record unreadable», with the button that closes it.
 * - A lookup that fails is not «no record»: it is named, without a button.
 * - wp_site_info carries the record in alphabridge.open_address_change; the
 *   card shows the old and the new address (the WordPress Address where it
 *   differs), the state as a sentence, when and by whom it started, what is
 *   still open and the next step, escaped, at the top of the card.
 * - The button closes only behind the nonce and the capability, only the
 *   record the page showed (its fingerprint), and not while a change of the
 *   site holds AB_MCP_Change_Lock; it removes the row and nothing else,
 *   reads it back, and lands on the card with what happened.
 * - uninstall.php removes the option (UninstallTest reads OPTION).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Change_Lock;
use AB_MCP_Site_Address_Journal;
use AB_MCP_Tool_Registry;
use AbTestExit;
use AbTestJournalWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/site-address-journal-stubs.php';
require_once __DIR__ . '/../includes/lifecycle/class-change-lock.php';

final class SiteAddressJournalTest extends TestCase {

	private const TIME = 1793520000;

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$GLOBALS['wpdb']        = new AbTestJournalWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
	}

	private static function db(): AbTestJournalWpdb {
		return $GLOBALS['wpdb'];
	}

	/** A record as the writer writes it, with keys replaced or added. */
	private static function record( array $over = array(), array $drop = array() ): string {
		$record = array_merge(
			array(
				'v'     => 1,
				'state' => 'rescue_incomplete',
				'old'   => array(
					'home'    => 'https://www.example.test',
					'siteurl' => 'https://www.example.test',
				),
				'new'   => array(
					'home'    => 'https://neu.example.test',
					'siteurl' => 'https://neu.example.test/wp',
				),
				'time'  => self::TIME,
				'user'  => 7,
				'rest'  => array( 'The object cache did not confirm <b>that</b> it dropped the options.', 'The .htaccess block could not be written back.' ),
				'next'  => 'Empty the object cache, then save the permalinks once.',
				'undo'  => 'undo_0123456789abcdef0123456789abcdef',
				'bound' => array( '203.0.113.7' ),
			),
			$over
		);
		foreach ( $drop as $key ) {
			unset( $record[ $key ] );
		}
		return (string) json_encode( $record );
	}

	private static function store( string $raw ): void {
		update_option( AB_MCP_Site_Address_Journal::OPTION, $raw, false );
	}

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function post( array $post ): AbTestExit {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ) )->handle_address_journal();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'handle_address_journal ended without a redirect or a refusal.' );
	}

	/* ------------------------------------------------------------- reading */

	public function testWithoutARecordNothingIsShown(): void {
		self::assertNull( AB_MCP_Site_Address_Journal::read() );
		self::assertNull( AB_MCP_Site_Address_Journal::for_site_info() );
		$card = $this->call( 'doctor_card_html' );
		self::assertStringNotContainsString( 'ab-address-journal', $card );
		self::assertStringNotContainsString( 'ab_mcp_address_journal', $card );
		self::assertSame(
			array(
				"SELECT option_value FROM wp_options WHERE option_name = 'ab_mcp_site_address_journal' LIMIT 1",
				"SELECT COUNT(*) FROM wp_options WHERE option_name = 'ab_mcp_site_address_journal'",
			),
			array_values( array_unique( self::db()->queries ) ),
			'The row of the record, by its exact name, value only; and as get_var() answers null for an empty value too, whether the row is there.'
		);
	}

	public function testAnEmptyRowIsARecordNotNone(): void {
		// wpdb::get_var() answers null for a value '' just as for no row. The
		// row is there, so the change it stood for is still open: shown as
		// unreadable, with the button, and the button closes it.
		self::store( '' );
		$read = AB_MCP_Site_Address_Journal::read();
		self::assertIsArray( $read );
		self::assertFalse( $read['readable'] );
		self::assertFalse( $read['lookup_failed'] );
		self::assertSame( hash( 'sha256', '' ), $read['fingerprint'] );
		self::assertFalse( AB_MCP_Site_Address_Journal::for_site_info()['readable'] );
		self::assertStringContainsString( 'ab_mcp_address_journal', $this->call( 'doctor_card_html' ) );
		self::assertSame( 'closed', AB_MCP_Site_Address_Journal::close( hash( 'sha256', '' ) ) );
		self::assertNull( AB_MCP_Site_Address_Journal::read() );
	}

	public function testTheCountThatFailsIsAFailedLookupNotNone(): void {
		self::store( '' );
		self::db()->fail_count[] = AB_MCP_Site_Address_Journal::OPTION;
		self::assertTrue( AB_MCP_Site_Address_Journal::read()['lookup_failed'] );
		self::assertSame( 'kept', AB_MCP_Site_Address_Journal::close( hash( 'sha256', '' ) ) );
	}

	/**
	 * The lock of the removal, with core's lock in place (WP_Upgrader, as in
	 * admin-post.php). The record is read UNDER the lock: a writer that
	 * rewrote it before the lock was granted has its record kept.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheRecordIsReadUnderTheLockAndAnotherWritersRecordStays(): void {
		self::define_upgrader();
		$old = self::record( array( 'state' => 'started' ) );
		$new = self::record();
		self::store( $old );
		// Granted only after another request wrote its record.
		$GLOBALS['ab_test_on_lock'] = static function () use ( $new ): void {
			self::store( $new );
		};

		self::assertSame( 'changed', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $old ) ) );
		self::assertSame( $new, get_option( AB_MCP_Site_Address_Journal::OPTION ), "The other writer's record stays." );
		self::assertSame( array( 'create_lock', 'release_lock' ), $GLOBALS['ab_test_lock_log'] );
		self::assertFalse( AB_MCP_Change_Lock::held(), 'Given back.' );

		// The lock granted with nothing written in between: closed, and
		// the read that decided it came after the lock.
		$GLOBALS['ab_test_on_lock']  = null;
		$GLOBALS['ab_test_lock_log'] = array();
		self::db()->queries          = array();
		self::assertSame( 'closed', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $new ) ) );
		self::assertSame( array( 'create_lock', 'release_lock' ), $GLOBALS['ab_test_lock_log'] );
		self::assertFalse( AB_MCP_Change_Lock::held() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testALockHeldByAnotherRequestClosesNothing(): void {
		self::define_upgrader();
		$raw = self::record();
		self::store( $raw );
		$GLOBALS['ab_test_lock_free'] = false;

		self::assertSame( 'busy', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $raw ) ) );
		self::assertSame( $raw, get_option( AB_MCP_Site_Address_Journal::OPTION ) );
		self::assertNotContains( 'release_lock', $GLOBALS['ab_test_lock_log'], "Another request's lock is not given back." );
	}

	/**
	 * With core's lock, a lock row of '' or of no time is refused, not taken
	 * over: create_lock() bails on a value that reads as false, and a string
	 * that is no number compares greater than any time. Closing then answers
	 * busy and keeps the record — the safe side.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCoresLockRefusesALockRowOfNoTimeAndTheRecordStays(): void {
		self::define_upgrader();
		$GLOBALS['ab_test_lock_core'] = true;
		foreach ( array( '', 'not a time' ) as $lock ) {
			$raw = self::record();
			self::store( $raw );
			update_option( AB_MCP_Change_Lock::OPTION, $lock );
			self::assertSame( 'busy', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $raw ) ), var_export( $lock, true ) );
			self::assertSame( $raw, get_option( AB_MCP_Site_Address_Journal::OPTION ) );
		}
		// An expired lock row of a time is taken over, as core does.
		$raw = self::record();
		self::store( $raw );
		update_option( AB_MCP_Change_Lock::OPTION, (string) ( time() - AB_MCP_Change_Lock::TIMEOUT - 1 ) );
		self::assertSame( 'closed', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $raw ) ) );
		self::assertFalse( get_option( AB_MCP_Change_Lock::OPTION ), 'Given back.' );
	}

	/**
	 * Core's update lock, as a log: create_lock() runs the test's hook first.
	 * With ab_test_lock_core it decides as core 7.1.3 does
	 * (class-wp-upgrader.php create_lock(): a row that reads as false bails,
	 * a row newer than the timeout bails, an older one is taken over).
	 */
	private static function define_upgrader(): void {
		$GLOBALS['ab_test_lock_log']  = array();
		$GLOBALS['ab_test_lock_free'] = true;
		$GLOBALS['ab_test_lock_core'] = false;
		$GLOBALS['ab_test_on_lock']   = null;
		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			eval(
				'class WP_Upgrader {
					public static function create_lock( $name, $timeout = 3600 ) {
						$GLOBALS["ab_test_lock_log"][] = "create_lock";
						if ( ! $GLOBALS["ab_test_lock_free"] ) {
							return false;
						}
						if ( $GLOBALS["ab_test_lock_core"] ) {
							$held = get_option( $name . ".lock", null );
							if ( null !== $held ) {
								if ( ! $held || $held > ( time() - $timeout ) ) {
									return false;
								}
							}
							update_option( $name . ".lock", time(), false );
						}
						if ( is_callable( $GLOBALS["ab_test_on_lock"] ) ) {
							( $GLOBALS["ab_test_on_lock"] )();
						}
						return true;
					}
					public static function release_lock( $name ) {
						$GLOBALS["ab_test_lock_log"][] = "release_lock";
						if ( $GLOBALS["ab_test_lock_core"] ) {
							delete_option( $name . ".lock" );
						}
						return true;
					}
				}'
			);
		}
	}

	public function testTheRecordIsTheRowNotWhatGetOptionReturns(): void {
		// A filter on the option, or a stale cache, would answer get_option()
		// otherwise: the row is what counts, both ways.
		self::db()->rows[ AB_MCP_Site_Address_Journal::OPTION ] = self::record();
		self::assertFalse( get_option( AB_MCP_Site_Address_Journal::OPTION ) );
		$entry = AB_MCP_Site_Address_Journal::read();
		self::assertIsArray( $entry );
		self::assertTrue( $entry['readable'] );
		self::assertSame( 'rescue_incomplete', $entry['state'] );

		self::store( self::record() );
		self::db()->rows[ AB_MCP_Site_Address_Journal::OPTION ] = null;
		self::assertNull( AB_MCP_Site_Address_Journal::read(), 'get_option() has a record, the table has none: nothing is open.' );
	}

	public function testAReadableRecordIsReadWithEveryKey(): void {
		$raw = self::record();
		self::store( $raw );
		self::assertSame(
			array(
				'readable'      => true,
				'lookup_failed' => false,
				'fingerprint'   => hash( 'sha256', $raw ),
				'state'         => 'rescue_incomplete',
				'old'           => array(
					'home'    => 'https://www.example.test',
					'siteurl' => 'https://www.example.test',
				),
				'new'           => array(
					'home'    => 'https://neu.example.test',
					'siteurl' => 'https://neu.example.test/wp',
				),
				'time'          => self::TIME,
				'user'          => 7,
				'rest'          => array( 'The object cache did not confirm <b>that</b> it dropped the options.', 'The .htaccess block could not be written back.' ),
				'next'          => 'Empty the object cache, then save the permalinks once.',
				'undo'          => 'undo_0123456789abcdef0123456789abcdef',
			),
			AB_MCP_Site_Address_Journal::read()
		);
	}

	public function testTheOptionalKeysMayBeMissing(): void {
		$entry = AB_MCP_Site_Address_Journal::parse( self::record( array(), array( 'time', 'user', 'rest', 'next', 'undo', 'bound' ) ) );
		self::assertIsArray( $entry );
		self::assertNull( $entry['time'] );
		self::assertNull( $entry['user'] );
		self::assertSame( array(), $entry['rest'] );
		self::assertNull( $entry['next'] );
		self::assertNull( $entry['undo'] );

		self::assertSame( array( 'Two parts.' ), AB_MCP_Site_Address_Journal::parse( self::record( array( 'rest' => array( "  Two\n\tparts.  " ) ) ) )['rest'], 'A run of control characters reads as one space, trimmed.' );
	}

	/**
	 * Each key the format names, in a form it does not allow: the record is
	 * unreadable as a whole.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unreadable(): array {
		$long = 'https://www.example.test/' . str_repeat( 'a', 2048 );
		return array(
			'empty row'               => array( '' ),
			'not JSON'                => array( 'a:1:{s:5:"state";s:7:"started";}' ),
			'a JSON list'             => array( '[1,"started"]' ),
			'too deep'                => array( self::record( array( 'x' => array( array( array( array( array( array( array( array( 1 ) ) ) ) ) ) ) ) ) ) ),
			'row too long'            => array( self::record( array( 'pad' => str_repeat( 'x', 262144 ) ) ) ),
			'no v'                    => array( self::record( array(), array( 'v' ) ) ),
			'v 2'                     => array( self::record( array( 'v' => 2 ) ) ),
			'v as a string'           => array( self::record( array( 'v' => '1' ) ) ),
			'no state'                => array( self::record( array(), array( 'state' ) ) ),
			'unknown state'           => array( self::record( array( 'state' => 'rolled_back' ) ) ),
			'state in capitals'       => array( self::record( array( 'state' => 'STARTED' ) ) ),
			'no old'                  => array( self::record( array(), array( 'old' ) ) ),
			'old not an object'       => array( self::record( array( 'old' => 'https://www.example.test' ) ) ),
			'old without home'        => array( self::record( array( 'old' => array( 'siteurl' => 'https://www.example.test' ) ) ) ),
			'new without siteurl'     => array( self::record( array( 'new' => array( 'home' => 'https://neu.example.test' ) ) ) ),
			'new home a number'       => array( self::record( array( 'new' => array( 'home' => 5, 'siteurl' => 'https://neu.example.test' ) ) ) ),
			'address without scheme'  => array( self::record( array( 'new' => array( 'home' => 'neu.example.test', 'siteurl' => 'https://neu.example.test' ) ) ) ),
			'address of another scheme' => array( self::record( array( 'old' => array( 'home' => 'ftp://www.example.test', 'siteurl' => 'https://www.example.test' ) ) ) ),
			'address with a space'    => array( self::record( array( 'new' => array( 'home' => 'https://neu.example.test/a b', 'siteurl' => 'https://neu.example.test' ) ) ) ),
			'address with a break'    => array( self::record( array( 'new' => array( 'home' => "https://neu.example.test\n", 'siteurl' => 'https://neu.example.test' ) ) ) ),
			'address too long'        => array( self::record( array( 'old' => array( 'home' => $long, 'siteurl' => 'https://www.example.test' ) ) ) ),
			'time as a string'        => array( self::record( array( 'time' => (string) self::TIME ) ) ),
			'time 0'                  => array( self::record( array( 'time' => 0 ) ) ),
			'user -1'                 => array( self::record( array( 'user' => -1 ) ) ),
			'user as a float'         => array( self::record( array( 'user' => 7.5 ) ) ),
			'rest an object'          => array( self::record( array( 'rest' => array( 'a' => 'The cache.' ) ) ) ),
			'rest a string'           => array( self::record( array( 'rest' => 'The cache.' ) ) ),
			'rest with a number'      => array( self::record( array( 'rest' => array( 'The cache.', 5 ) ) ) ),
			'rest with an empty text' => array( self::record( array( 'rest' => array( 'The cache.', '  ' ) ) ) ),
			'rest too long a list'    => array( self::record( array( 'rest' => array_fill( 0, 51, 'The cache.' ) ) ) ),
			'rest too long a text'    => array( self::record( array( 'rest' => array( str_repeat( 'x', 2001 ) ) ) ) ),
			'next empty'              => array( self::record( array( 'next' => '' ) ) ),
			'next a list'             => array( self::record( array( 'next' => array( 'Empty the cache.' ) ) ) ),
			'next too long'           => array( self::record( array( 'next' => str_repeat( 'x', 2001 ) ) ) ),
			'undo another form'       => array( self::record( array( 'undo' => 'undo_0123' ) ) ),
			'undo a number'           => array( self::record( array( 'undo' => 12 ) ) ),
		);
	}

	#[DataProvider( 'unreadable' )]
	public function testABrokenRecordIsShownAsUnreadable( string $raw ): void {
		self::assertNull( AB_MCP_Site_Address_Journal::parse( $raw ) );
		self::store( $raw );

		$entry = AB_MCP_Site_Address_Journal::read();
		self::assertSame(
			array(
				'readable'      => false,
				'lookup_failed' => false,
				'fingerprint'   => hash( 'sha256', $raw ),
			),
			$entry
		);
		$info = AB_MCP_Site_Address_Journal::for_site_info();
		self::assertFalse( $info['readable'] );
		self::assertStringStartsWith( 'An address change of this site is not finished, and its record cannot be read', $info['note'] );
		self::assertArrayNotHasKey( 'lookup_failed', $info );

		$card = $this->call( 'doctor_card_html' );
		self::assertStringContainsString( 'id="ab-address-journal"', $card );
		self::assertStringContainsString( 'its record cannot be read, so what is still open is not known.', $card );
		self::assertStringContainsString( '<input type="hidden" name="entry" value="' . hash( 'sha256', $raw ) . '">', $card, 'An unreadable record is closed as well.' );
	}

	public function testTheLimitsThemselvesAreReadable(): void {
		$at = self::record(
			array(
				'old'  => array( 'home' => 'https://www.example.test/' . str_repeat( 'a', 2048 - 25 ), 'siteurl' => 'http://www.example.test' ),
				'rest' => array_fill( 0, 50, str_repeat( 'x', 2000 ) ),
				'next' => str_repeat( 'y', 2000 ),
			)
		);
		self::assertIsArray( AB_MCP_Site_Address_Journal::parse( $at ) );
		foreach ( AB_MCP_Site_Address_Journal::STATES as $state ) {
			self::assertIsArray( AB_MCP_Site_Address_Journal::parse( self::record( array( 'state' => $state ) ) ), $state );
		}
	}

	public function testEveryStateHasASentenceOfItsOwn(): void {
		$texts = array();
		foreach ( AB_MCP_Site_Address_Journal::STATES as $state ) {
			$texts[ $state ] = AB_MCP_Site_Address_Journal::state_text( $state );
			self::assertNotSame( '', $texts[ $state ], $state );
		}
		self::assertSame( array( 'started', 'rolled_back_partial', 'rescued', 'rescue_incomplete', 'done' ), array_keys( $texts ) );
		self::assertCount( 5, array_unique( $texts ) );
		self::assertSame( '', AB_MCP_Site_Address_Journal::state_text( 'rolled_back' ) );
	}

	public function testALookupThatFailsIsNotNoRecord(): void {
		self::store( self::record() );
		self::db()->fail[] = AB_MCP_Site_Address_Journal::OPTION;

		self::assertSame(
			array(
				'readable'      => false,
				'lookup_failed' => true,
			),
			AB_MCP_Site_Address_Journal::read()
		);
		$info = AB_MCP_Site_Address_Journal::for_site_info();
		self::assertTrue( $info['lookup_failed'] );
		self::assertStringStartsWith( 'Whether an address change of this site is not finished could not be read', $info['note'] );

		$card = $this->call( 'doctor_card_html' );
		self::assertStringContainsString( 'id="ab-address-journal"', $card );
		self::assertStringContainsString( 'the database answered with an error', $card );
		self::assertStringNotContainsString( 'ab_mcp_address_journal', $card, 'Nothing to close while it cannot be read.' );
	}

	/* ------------------------------------------------------- wp_site_info */

	public function testSiteInfoCarriesWhatTheRecordSays(): void {
		self::store( self::record() );
		self::assertSame(
			array(
				'readable'   => true,
				'state'      => 'rescue_incomplete',
				'state_text' => AB_MCP_Site_Address_Journal::state_text( 'rescue_incomplete' ),
				'old'        => array(
					'home'    => 'https://www.example.test',
					'siteurl' => 'https://www.example.test',
				),
				'new'        => array(
					'home'    => 'https://neu.example.test',
					'siteurl' => 'https://neu.example.test/wp',
				),
				'started'    => gmdate( 'Y-m-d\TH:i:s\Z', self::TIME ),
				'user'       => 7,
				'rest'       => array( 'The object cache did not confirm <b>that</b> it dropped the options.', 'The .htaccess block could not be written back.' ),
				'next'       => 'Empty the object cache, then save the permalinks once.',
				'undo'       => 'undo_0123456789abcdef0123456789abcdef',
				'note'       => 'An address change of this site is not finished. ' . AB_MCP_Site_Address_Journal::check_text(),
			),
			AB_MCP_Site_Address_Journal::for_site_info()
		);
		$bare = AB_MCP_Site_Address_Journal::parse( self::record( array(), array( 'time', 'undo' ) ) );
		self::assertNull( $bare['time'] );
		self::store( self::record( array(), array( 'time', 'undo' ) ) );
		$info = AB_MCP_Site_Address_Journal::for_site_info();
		self::assertNull( $info['started'] );
		self::assertArrayNotHasKey( 'undo', $info, 'No undo point, no key.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheToolNamesTheOpenChangeOnlyWhileARecordStands(): void {
		require_once __DIR__ . '/site-info-stubs.php';
		require_once __DIR__ . '/../includes/tools/class-tools-system.php';
		$GLOBALS['wp_version'] = '7.1.3';

		$block = \AB_MCP_Tools_System::site_info()['alphabridge'];
		self::assertSame( array( 'version', 'connections_used' ), array_keys( $block ), 'Without a record the key is absent.' );

		self::store( self::record() );
		$block = \AB_MCP_Tools_System::site_info()['alphabridge'];
		self::assertSame( array( 'version', 'connections_used', 'open_address_change' ), array_keys( $block ) );
		self::assertSame( AB_MCP_Site_Address_Journal::for_site_info(), $block['open_address_change'] );

		self::store( 'not a record' );
		self::assertFalse( \AB_MCP_Tools_System::site_info()['alphabridge']['open_address_change']['readable'] );
	}

	/* ------------------------------------------------------------ the card */

	public function testTheCardShowsTheRecordEscapedAtItsTop(): void {
		$GLOBALS['ab_test_users'][7] = (object) array(
			'ID'           => 7,
			'display_name' => 'Thomas <i>W.</i>',
		);
		$raw = self::record();
		self::store( $raw );

		$card = $this->call( 'doctor_card_html' );
		$at   = strpos( $card, 'id="ab-address-journal"' );
		self::assertNotFalse( $at );
		self::assertLessThan( strpos( $card, 'Not run yet.' ), $at, 'Above the result of the check.' );
		self::assertStringContainsString( '<b>An address change of this site is not finished.</b> ' . AB_MCP_Site_Address_Journal::state_text( 'rescue_incomplete' ), $card );
		self::assertStringContainsString( 'Old address: https://www.example.test<br>', $card, 'The same WordPress Address is not repeated.' );
		self::assertStringContainsString( 'New address: https://neu.example.test (WordPress Address: https://neu.example.test/wp)', $card );
		self::assertStringContainsString( 'Started ' . gmdate( 'Y-m-d H:i', self::TIME ) . ' by Thomas &lt;i&gt;W.&lt;/i&gt;.', $card );
		self::assertStringContainsString( '<ul class="ab-address-journal__rest"><li>The object cache did not confirm &lt;b&gt;that&lt;/b&gt; it dropped the options.</li><li>The .htaccess block could not be written back.</li></ul>', $card );
		self::assertStringContainsString( 'Next step: Empty the object cache, then save the permalinks once.', $card );
		self::assertStringNotContainsString( '<b>that</b>', $card );
		self::assertStringNotContainsString( '<i>W.</i>', $card );
		self::assertStringContainsString( 'Close the record once the address is right', $card );
		self::assertStringContainsString( '<input type="hidden" name="_wpnonce" value="nonce-ab_mcp_address_journal">', $card );
		self::assertStringContainsString( '<input type="hidden" name="action" value="ab_mcp_address_journal">', $card );
		self::assertStringContainsString( '<input type="hidden" name="entry" value="' . hash( 'sha256', $raw ) . '">', $card );
		self::assertStringContainsString( '>Close the record</button>', $card );
	}

	public function testTheCardLeavesOutWhatTheRecordDoesNotHave(): void {
		self::store( self::record( array( 'old' => array( 'home' => 'https://www.example.test', 'siteurl' => 'https://www.example.test/wp' ), 'state' => 'started' ), array( 'rest', 'next', 'user' ) ) );
		$card = $this->call( 'doctor_card_html' );
		self::assertStringContainsString( 'Old address: https://www.example.test (WordPress Address: https://www.example.test/wp)', $card );
		self::assertStringContainsString( 'Started ' . gmdate( 'Y-m-d H:i', self::TIME ) . '.</p>', $card, 'Without the account, the time alone.' );
		self::assertStringNotContainsString( 'Still open:', $card );
		self::assertStringNotContainsString( 'Next step:', $card );
		self::assertStringContainsString( AB_MCP_Site_Address_Journal::state_text( 'started' ), $card );

		self::store( self::record( array( 'user' => 99 ), array( 'time' ) ) );
		self::assertStringNotContainsString( 'Started', $this->call( 'doctor_card_html' ), 'Without the time, nothing about the start.' );
	}

	/* ---------------------------------------------------------- the button */

	public function testTheButtonClosesTheRecordAndNothingElse(): void {
		$raw = self::record();
		self::store( $raw );
		update_option( 'home', 'https://neu.example.test' );
		$before = $GLOBALS['ab_test_options'];

		$exit = $this->post( array( '_wpnonce' => 'nonce-ab_mcp_address_journal', 'entry' => hash( 'sha256', $raw ) ) );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=address_journal_closed', $exit->detail );
		self::assertStringEndsWith( '#ab-doctor', $exit->detail );
		self::assertArrayNotHasKey( AB_MCP_Site_Address_Journal::OPTION, $GLOBALS['ab_test_options'] );
		unset( $before[ AB_MCP_Site_Address_Journal::OPTION ] );
		self::assertSame( $before, $GLOBALS['ab_test_options'], 'Only the record went.' );
		self::assertNull( AB_MCP_Site_Address_Journal::read() );
	}

	public function testAnUnreadableRecordIsClosedAsWell(): void {
		self::store( 'garbage' );
		$exit = $this->post( array( '_wpnonce' => 'nonce-ab_mcp_address_journal', 'entry' => hash( 'sha256', 'garbage' ) ) );
		self::assertStringContainsString( 'ab_notice=address_journal_closed', $exit->detail );
		self::assertNull( AB_MCP_Site_Address_Journal::read() );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:bool}>
	 */
	public static function refusedPosts(): array {
		return array(
			'no nonce'                     => array( array( 'entry' => 'FP' ), true ),
			'the nonce of the check'       => array( array( '_wpnonce' => 'nonce-ab_mcp_connection_check', 'entry' => 'FP' ), true ),
			'no right'                     => array( array( '_wpnonce' => 'nonce-ab_mcp_address_journal', 'entry' => 'FP' ), false ),
		);
	}

	#[DataProvider( 'refusedPosts' )]
	public function testTheButtonNeedsTheNonceAndTheCapability( array $post, bool $admin ): void {
		if ( ! $admin ) {
			$GLOBALS['ab_test_can'] = static fn(): bool => false;
		}
		$raw = self::record();
		self::store( $raw );
		$post['entry'] = hash( 'sha256', $raw );

		$exit = $this->post( $post );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( $raw, get_option( AB_MCP_Site_Address_Journal::OPTION ), 'The record stays.' );
	}

	/**
	 * What the button meets, and where it lands: the record stays in each
	 * case but «gone».
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function notClosed(): array {
		return array(
			'another record'    => array( 'changed' ),
			'no fingerprint'    => array( 'changed' ),
			'no record'         => array( 'gone' ),
			'a change running'  => array( 'busy' ),
			'lock unreadable'   => array( 'busy' ),
			'a hook keeps it'   => array( 'kept' ),
			'lookup fails'      => array( 'kept' ),
		);
	}

	#[DataProvider( 'notClosed' )]
	public function testTheButtonClosesOnlyTheRecordThePageShowed( string $expected ): void {
		$name = $this->dataName();
		$raw  = self::record();
		self::store( $raw );
		$post = array( '_wpnonce' => 'nonce-ab_mcp_address_journal', 'entry' => hash( 'sha256', $raw ) );
		switch ( $name ) {
			case 'another record':
				$post['entry'] = hash( 'sha256', self::record( array( 'state' => 'started' ) ) );
				break;
			case 'no fingerprint':
				unset( $post['entry'] );
				break;
			case 'no record':
				delete_option( AB_MCP_Site_Address_Journal::OPTION );
				break;
			case 'a change running':
				update_option( AB_MCP_Change_Lock::OPTION, (string) ( time() - AB_MCP_Change_Lock::TIMEOUT + 30 ) );
				break;
			case 'lock unreadable':
				self::db()->fail[] = AB_MCP_Change_Lock::OPTION;
				break;
			case 'a hook keeps it':
				// The row is still there after delete_option(): a hook wrote it
				// back, or the delete did not reach the table.
				self::db()->rows[ AB_MCP_Site_Address_Journal::OPTION ] = $raw;
				break;
			case 'lookup fails':
				self::db()->fail[] = AB_MCP_Site_Address_Journal::OPTION;
				break;
		}

		$exit = $this->post( $post );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=address_journal_' . $expected, $exit->detail );
		self::assertStringEndsWith( '#ab-doctor', $exit->detail );
		if ( 'gone' !== $expected && 'kept' !== $expected ) {
			self::assertSame( $raw, get_option( AB_MCP_Site_Address_Journal::OPTION ), 'The record stays.' );
		}
	}

	public function testALockRowOlderThanItsTimeoutOrNotATimeIsNoRunningChange(): void {
		// The check before the lock: such a row is no change that runs. Here
		// without core's lock (no WP_Upgrader), so the close goes through;
		// what core's lock itself makes of '' and 'not a time' is the test
		// below.
		foreach ( array( (string) ( time() - AB_MCP_Change_Lock::TIMEOUT - 1 ), (string) ( time() - AB_MCP_Change_Lock::TIMEOUT ), 'not a time', '' ) as $lock ) {
			$raw = self::record();
			self::store( $raw );
			update_option( AB_MCP_Change_Lock::OPTION, $lock );
			self::assertSame( 'closed', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $raw ) ), $lock );
		}
		$raw = self::record();
		self::store( $raw );
		update_option( AB_MCP_Change_Lock::OPTION, (string) ( time() - AB_MCP_Change_Lock::TIMEOUT + 1 ) );
		self::assertSame( 'busy', AB_MCP_Site_Address_Journal::close( hash( 'sha256', $raw ) ), 'Within the timeout it runs.' );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function notices(): array {
		return array(
			'closed'  => array( 'address_journal_closed', 'success', 'The record of the address change was removed. Nothing else on the site was changed.' ),
			'gone'    => array( 'address_journal_gone', 'success', 'There was no record of an address change any more; nothing was changed.' ),
			'changed' => array( 'address_journal_changed', 'error', 'Nothing was removed: the record of the address change has changed since this page was loaded.' ),
			'busy'    => array( 'address_journal_busy', 'error', 'Nothing was removed: a change of the site is running right now' ),
			'kept'    => array( 'address_journal_kept', 'error', 'The record of the address change could not be removed.' ),
		);
	}

	#[DataProvider( 'notices' )]
	public function testEveryOutcomeHasItsNotice( string $key, string $kind, string $text ): void {
		$_GET['ab_notice'] = $key;
		$html              = $this->call( 'notice' );
		self::assertStringContainsString( 'notice-' . $kind, $html );
		self::assertStringContainsString( $text, $html );
	}

	public function testTheUninstallRemovesTheRecord(): void {
		self::assertStringContainsString( "'" . AB_MCP_Site_Address_Journal::OPTION . "',", (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ) );
	}
}
