<?php
/**
 * Times on the settings screen follow the site's timezone. Both values shown
 * here are stored as time(), a UTC timestamp: «Last used» in the connections
 * list and the time column of the log. Until 4.3.5 the first was compared with
 * the local-offset timestamp and the second was formatted as if it already
 * were one — on a site in Central European summer time a connection used a
 * minute ago read «2 hours ago», and the log showed the UTC clock.
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
	 * The seconds in the «Last used» cell. The stand-in for human_time_diff()
	 * prints exact seconds, so any shift is visible.
	 */
	private function secondsAgo( string $html ): int {
		self::assertSame( 1, preg_match( '#<td>(\d+) seconds ago</td>#', $html, $m ), 'The row says how long ago the connection was used: ' . $html );
		return (int) $m[1];
	}

	/** The log card as the settings screen renders it. */
	private function logHtml(): string {
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'render_audit' );
		ob_start();
		$method->invoke( new AB_MCP_Admin() );
		return (string) ob_get_clean();
	}

	private function logEntryAt( string $utc ): void {
		$ts = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
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

	/** @return array<string,array{0:float}> */
	public static function offsets(): array {
		return array(
			'UTC'                        => array( 0.0 ),
			'Central European summer'    => array( 2.0 ),
			'US Eastern, west of UTC'    => array( -5.0 ),
			'India, half an hour offset' => array( 5.5 ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'offsets' )]
	public function testLastUsedIsTheRealTimeSinceTheCallWhateverTheSiteOffset( float $offset ): void {
		update_option( 'gmt_offset', $offset );

		$seconds = $this->secondsAgo( $this->row( array( 'last_used' => time() - 60 ) ) );

		self::assertGreaterThanOrEqual( 60, $seconds );
		self::assertLessThan( 65, $seconds, 'A connection used a minute ago must look a minute old, not hours older or younger.' );
	}

	public function testLastUsedIsRightWhenTheSiteNamesItsZone(): void {
		// A named zone alone: WordPress derives gmt_offset from it. India has no
		// daylight saving, so the offset is +5:30 whenever the test runs.
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$seconds = $this->secondsAgo( $this->row( array( 'last_used' => time() - 60 ) ) );

		self::assertGreaterThanOrEqual( 60, $seconds );
		self::assertLessThan( 65, $seconds );
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

		$html = $this->row( array( 'last_used' => 0 ) );

		self::assertStringContainsString( '<td>—</td>', $html );
		self::assertStringNotContainsString( 'seconds ago', $html );
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
