<?php
/**
 * Times on the settings screen follow the site's timezone. The values shown
 * here are stored as time(), a UTC timestamp: «Last used» and «Expires» in the
 * connections list and the time column of the log. Until 4.3.5 the first was
 * compared with the local-offset timestamp and the log was formatted as if it
 * already were one — on a site in Central European summer time a connection
 * used a minute ago read «2 hours ago», and the log showed the UTC clock.
 *
 * Since 4.3.6 «Last used» and «Expires» are a date and time in digits, like the
 * log. They were phrases around human_time_diff(): WordPress translates the
 * time span, this plugin's words around it need its own language pack, and a
 * German site without one read «2 Wochen ago».
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Audit_Log;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;

final class SiteTimeTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * The connections-table row the settings screen renders for one entry.
	 */
	private function row( array $entry ): string {
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'connection_row_html' );
		return (string) $method->invoke(
			new AB_MCP_Admin(),
			$entry + array(
				'hash'    => str_repeat( 'a', 64 ),
				'prefix'  => 'abmcp_abcdef',
				'label'   => 'Test',
				'scope'   => 'read',
				'expires' => 0,
			)
		);
	}

	/**
	 * The plain cells of a row, the ones before the buttons: label, access,
	 * token prefix, «Expires», «Last used».
	 *
	 * @return array<string,string>
	 */
	private function cells( string $html ): array {
		self::assertSame( 5, preg_match_all( '#<td>(.*?)</td>#s', $html, $m ), 'A row has five plain cells before the buttons: ' . $html );
		return array_combine( array( 'label', 'access', 'token', 'expires', 'used' ), $m[1] );
	}

	private static function utc( string $time ): int {
		return ( new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/** The log card as the settings screen renders it. */
	private function logHtml(): string {
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'render_audit' );
		ob_start();
		$method->invoke( new AB_MCP_Admin() );
		return (string) ob_get_clean();
	}

	private function logEntryAt( string $utc ): void {
		$ts = self::utc( $utc );
		update_option(
			AB_MCP_Audit_Log::OPTION,
			array(
				array(
					'ts'      => $ts,
					'user'    => 1,
					'tool'    => 'wp_create_post',
					'status'  => 'ok',
					'message' => '',
					'keys'    => '',
				),
			)
		);
	}

	/** @return array<string,array{0:float,1:string}> */
	public static function offsets(): array {
		return array(
			'UTC'                                => array( 0.0, '2026-09-26 20:10' ),
			'Central European summer'            => array( 2.0, '2026-09-26 22:10' ),
			'US Eastern, west of UTC'            => array( -5.0, '2026-09-26 15:10' ),
			'India, half an hour, over midnight' => array( 5.5, '2026-09-27 01:40' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'offsets' )]
	public function testLastUsedShowsTheSiteClockWhateverTheOffset( float $offset, string $expected ): void {
		update_option( 'gmt_offset', $offset );

		$cells = $this->cells( $this->row( array( 'last_used' => self::utc( '2026-09-26 20:10:22' ) ) ) );

		self::assertSame( $expected, $cells['used'], 'A connection used at 20:10 UTC shows the site clock at that moment, neither the UTC clock nor a doubled offset.' );
	}

	public function testLastUsedIsRightWhenTheSiteNamesItsZone(): void {
		// A named zone alone: WordPress derives gmt_offset from it. India has no
		// daylight saving, so the offset is +5:30 whenever the test runs.
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$cells = $this->cells( $this->row( array( 'last_used' => self::utc( '2026-09-26 20:10:22' ) ) ) );

		self::assertSame( '2026-09-27 01:40', $cells['used'] );
	}

	public function testLastUsedFollowsTheNamedZoneAcrossDaylightSaving(): void {
		// gmt_offset holds today's offset only: Zurich is one hour ahead of UTC
		// in January and two in September.
		update_option( 'timezone_string', 'Europe/Zurich' );

		$winter = $this->cells( $this->row( array( 'last_used' => self::utc( '2026-01-15 12:00:00' ) ) ) );
		$summer = $this->cells( $this->row( array( 'last_used' => self::utc( '2026-09-26 20:10:22' ) ) ) );

		self::assertSame( '2026-01-15 13:00', $winter['used'] );
		self::assertSame( '2026-09-26 22:10', $summer['used'] );
	}

	public function testExpiresShowsTheSiteClockOfTheExpiry(): void {
		// A fixed offset, so the expected text does not depend on the daylight
		// saving rules of a year far ahead.
		update_option( 'gmt_offset', 2.0 );

		$cells = $this->cells( $this->row( array( 'expires' => self::utc( '2099-10-01 08:00:00' ) ) ) );

		self::assertSame( '2099-10-01 10:00', $cells['expires'] );
	}

	public function testExpiresFollowsTheNamedZoneAcrossDaylightSaving(): void {
		// Zurich is one hour ahead of UTC in winter and two in summer. The two
		// expiries lie ahead, one in each season, and the expected text comes
		// from PHP's zone database, so a later change to the daylight saving
		// rules cannot break the test. Arithmetic with today's gmt_offset is
		// right for one of the two and an hour off for the other.
		update_option( 'timezone_string', 'Europe/Zurich' );
		$zone = new DateTimeZone( 'Europe/Zurich' );

		foreach ( array( '01-15 12:00:00', '07-15 12:00:00' ) as $day ) {
			$expires  = self::nextUtc( $day );
			$expected = ( new DateTimeImmutable( '@' . $expires ) )->setTimezone( $zone )->format( 'Y-m-d H:i' );

			$cells = $this->cells( $this->row( array( 'expires' => $expires ) ) );

			self::assertSame( $expected, $cells['expires'], 'Expiry on ' . $day . ' UTC.' );
		}
	}

	/** The next moment at least a day ahead with this month, day and UTC time. */
	private static function nextUtc( string $monthDayTime ): int {
		$year = (int) gmdate( 'Y' );
		$ts   = self::utc( $year . '-' . $monthDayTime );
		return $ts > time() + DAY_IN_SECONDS ? $ts : self::utc( ( $year + 1 ) . '-' . $monthDayTime );
	}

	public function testExpiresStillSaysNeverAndExpired(): void {
		$never   = $this->cells( $this->row( array( 'expires' => 0 ) ) );
		$expired = $this->cells( $this->row( array( 'expires' => time() - 60 ) ) );

		self::assertStringContainsString( '>never</span>', $never['expires'] );
		self::assertStringContainsString( '>expired</span>', $expired['expires'] );
	}

	public function testTheTimeCellsHoldDigitsOnly(): void {
		// Words around a time span need this plugin's language pack, the span
		// itself comes translated from WordPress: without the pack a German site
		// read «2 Wochen ago». Digits read the same in every language.
		update_option( 'timezone_string', 'Europe/Zurich' );

		$cells = $this->cells(
			$this->row(
				array(
					'last_used' => time() - 14 * DAY_IN_SECONDS,
					'expires'   => time() + 3 * DAY_IN_SECONDS,
				)
			)
		);

		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/', $cells['used'] );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/', $cells['expires'] );
	}

	public function testUsingATokenStoresARealUnixTimestamp(): void {
		// The display is only right if what it reads is time(). A writer that
		// stored current_time( 'timestamp' ) would put every use hours off.
		update_option( 'gmt_offset', 2.0 );
		ab_test_add_user( 7 );
		$token = \AB_MCP_Settings::add_token( 7, 'Test', 'read', 0 );
		$hash  = hash( 'sha256', $token );
		// Made three days ago, last used ten minutes ago: past the five-minute
		// throttle, and neither old value may pass for the time of this use.
		$tokens                 = get_option( \AB_MCP_Settings::OPT_TOKENS );
		$tokens[0]['created']   = time() - 3 * DAY_IN_SECONDS;
		$tokens[0]['last_used'] = time() - 10 * MINUTE_IN_SECONDS;
		update_option( \AB_MCP_Settings::OPT_TOKENS, $tokens );

		$before = time();
		\AB_MCP_Settings::touch_token( $hash );
		$stored = (int) \AB_MCP_Settings::get_token_by_hash( $hash )['last_used'];

		self::assertGreaterThanOrEqual( $before, $stored );
		self::assertLessThanOrEqual( time(), $stored );
	}

	public function testALogEntryStoresARealUnixTimestamp(): void {
		update_option( 'gmt_offset', 2.0 );

		$before = time();
		AB_MCP_Audit_Log::record( 'wp_site_info', array(), 'ok' );
		$log = get_option( AB_MCP_Audit_Log::OPTION, array() );

		self::assertCount( 1, $log );
		self::assertGreaterThanOrEqual( $before, (int) $log[0]['ts'] );
		self::assertLessThanOrEqual( time(), (int) $log[0]['ts'] );
	}

	public function testANeverUsedConnectionShowsADash(): void {
		update_option( 'gmt_offset', 2.0 );

		$cells = $this->cells( $this->row( array( 'last_used' => 0 ) ) );

		self::assertSame( '—', $cells['used'] );
	}

	public function testTheLogShowsTheSiteClockInSummer(): void {
		update_option( 'timezone_string', 'Europe/Zurich' );
		$this->logEntryAt( '2026-09-26 20:10:22' );

		$html = $this->logHtml();

		self::assertStringContainsString( '<td>2026-09-26 22:10:22</td>', $html, 'Zurich is two hours ahead of UTC in September.' );
		self::assertStringNotContainsString( '20:10:22', $html, 'The UTC clock must not be shown as if it were local time.' );
	}

	public function testTheLogFollowsTheNamedZoneAcrossDaylightSaving(): void {
		// gmt_offset holds today's offset only; a January entry in Zurich is one
		// hour ahead of UTC. Arithmetic with gmt_offset would be right in winter
		// and an hour off from March to October.
		update_option( 'timezone_string', 'Europe/Zurich' );
		$this->logEntryAt( '2026-01-15 12:00:00' );

		self::assertStringContainsString( '<td>2026-01-15 13:00:00</td>', $this->logHtml() );
	}

	/** @return array<string,array{0:float,1:string}> */
	public static function fixedOffsets(): array {
		return array(
			'US Eastern, west of UTC'                  => array( -5.0, '2026-09-26 15:10:22' ),
			'India, half an hour, over midnight'       => array( 5.5, '2026-09-27 01:40:22' ),
		);
	}

	/** A site without a zone name, only a fixed offset, as WordPress allows it. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'fixedOffsets' )]
	public function testTheLogFollowsAFixedOffsetWithoutAZoneName( float $offset, string $expected ): void {
		update_option( 'gmt_offset', $offset );
		$this->logEntryAt( '2026-09-26 20:10:22' );

		self::assertStringContainsString( '<td>' . $expected . '</td>', $this->logHtml() );
	}

	public function testOnAUtcSiteTheLogShowsTheUtcClock(): void {
		update_option( 'gmt_offset', 0.0 );
		$this->logEntryAt( '2026-09-26 20:10:22' );

		self::assertStringContainsString( '<td>2026-09-26 20:10:22</td>', $this->logHtml() );
	}
}
