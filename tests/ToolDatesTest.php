<?php
/**
 * Dates in the tools' answers carry the site's offset, and wp_create_post
 * reads such a date back. Until 4.3.5 the tools handed out the UTC column
 * bare: on 26.09.2026 ChatGPT reported a post published at 09:00 in Zurich as
 * published at 07:00, and a draft's date read "0000-00-00 00:00:00".
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Base;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Media;
use AB_MCP_Tools_Taxonomy_Comments;
use ReflectionMethod;
use WP_Error;

final class ToolDatesTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
	}

	/** "Europe/Zurich" or "UTC" names a zone; "+5.5" or "-5" is a fixed offset. */
	private static function site( string $zone ): void {
		if ( '+' === $zone[0] || '-' === $zone[0] ) {
			update_option( 'gmt_offset', (float) $zone );
		} else {
			update_option( 'timezone_string', $zone );
		}
	}

	/* ------------------------------------------------------------ site_time */

	/** @return array<string,array{0:string,1:string,2:string,3:?string}> */
	public static function storedDates(): array {
		return array(
			'a UTC site'                                   => array( 'UTC', '2026-06-16 07:00:00', '2026-06-16 07:00:00', '2026-06-16T07:00:00+00:00' ),
			'Zurich in summer'                             => array( 'Europe/Zurich', '2026-06-16 07:00:00', '2026-06-16 09:00:00', '2026-06-16T09:00:00+02:00' ),
			'Zurich in winter'                             => array( 'Europe/Zurich', '2026-01-15 12:00:00', '2026-01-15 13:00:00', '2026-01-15T13:00:00+01:00' ),
			'a fixed offset west of UTC'                   => array( '-5', '2026-09-26 20:10:22', '2026-09-26 15:10:22', '2026-09-26T15:10:22-05:00' ),
			'half an hour, over midnight'                  => array( '+5.5', '2026-09-26 20:10:22', '2026-09-27 01:40:22', '2026-09-27T01:40:22+05:30' ),
			'the UTC column wins over a local one'         => array( 'Europe/Zurich', '2026-06-16 07:00:00', '2026-06-16 11:11:11', '2026-06-16T09:00:00+02:00' ),
			'a draft goes by its local column'             => array( 'Europe/Zurich', '0000-00-00 00:00:00', '2026-10-02 09:00:00', '2026-10-02T09:00:00+02:00' ),
			'so does an empty UTC column'                  => array( 'Europe/Zurich', '', '2026-10-02 09:00:00', '2026-10-02T09:00:00+02:00' ),
			'no date at all'                               => array( 'Europe/Zurich', '0000-00-00 00:00:00', '0000-00-00 00:00:00', null ),
			'a UTC column without seconds is not trusted'  => array( 'Europe/Zurich', '2026-06-16 07:00', '2026-06-16 09:30:00', '2026-06-16T09:30:00+02:00' ),
			'a day that does not exist is not rolled over' => array( 'UTC', '2026-02-30 10:00:00', '', null ),
			'a trailing newline is not a stored date'      => array( 'UTC', "2026-06-16 07:00:00\n", '', null ),
			'year zero is not a date'                      => array( 'UTC', '0000-01-01 12:00:00', '', null ),
			'Zurich before 1894 is given in UTC'           => array( 'Europe/Zurich', '1890-06-16 07:00:00', '', '1890-06-16T07:00:00Z' ),
			'a draft in the hour the clocks skip'          => array( 'Europe/Zurich', '0000-00-00 00:00:00', '2026-03-29 02:30:00', '2026-03-29T03:30:00+02:00' ),
			'a draft in the hour the clocks repeat'        => array( 'Europe/Zurich', '0000-00-00 00:00:00', '2026-10-25 02:30:00', '2026-10-25T02:30:00+01:00' ),
			'the UTC column tells the two 02:30s apart'    => array( 'Europe/Zurich', '2026-10-25 00:30:00', '2026-10-25 02:30:00', '2026-10-25T02:30:00+02:00' ),
			'past 9999 in site time, given in UTC'         => array( '+2', '9999-12-31 23:30:00', '', '9999-12-31T23:30:00Z' ),
			'year 1 in site time stays in site time'       => array( '+2', '0000-00-00 00:00:00', '0001-01-01 00:30:00', '0001-01-01T00:30:00+02:00' ),
			'neither form fits the calendar: no date'      => array( 'Europe/Zurich', '0000-00-00 00:00:00', '0001-01-01 00:10:00', null ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'storedDates' )]
	public function testAStoredDateComesOutWithTheSiteOffset( string $zone, string $gmt, string $local, ?string $expected ): void {
		self::site( $zone );
		self::assertSame( $expected, AB_MCP_Tools_Base::site_time( $gmt, $local ) );
	}

	/* --------------------------------------------------- every place a date leaves */

	private static function summary( object $post ): array {
		return ( new ReflectionMethod( AB_MCP_Tools_Base::class, 'post_summary' ) )->invoke( null, $post );
	}

	public function testAPostSummaryShowsPublishedAndModifiedInSiteTime(): void {
		self::site( 'Europe/Zurich' );
		$post = ab_test_add_post(
			10,
			array(
				'post_date_gmt'     => '2026-06-16 07:00:00',
				// Local columns that disagree on purpose: the UTC column must win.
				'post_date'         => '2026-06-16 11:11:11',
				'post_modified_gmt' => '2026-06-17 08:30:00',
				'post_modified'     => '2026-06-17 11:11:11',
			)
		);

		$summary = self::summary( $post );

		self::assertSame( '2026-06-16T09:00:00+02:00', $summary['date'] );
		self::assertSame( '2026-06-17T10:30:00+02:00', $summary['modified'] );
	}

	public function testADraftSummaryShowsItsPlannedTimeNotZeros(): void {
		self::site( 'Europe/Zurich' );
		$post = ab_test_add_post(
			11,
			array(
				'post_status'   => 'draft',
				'post_date_gmt' => '0000-00-00 00:00:00',
				'post_date'     => '2026-10-02 09:00:00',
			)
		);

		self::assertSame( '2026-10-02T09:00:00+02:00', self::summary( $post )['date'] );
	}

	public function testTheMediaListShowsSiteTime(): void {
		self::site( 'Europe/Zurich' );
		$GLOBALS['ab_test_query'] = static fn(): array => array(
			ab_test_add_post(
				20,
				array(
					'post_type'     => 'attachment',
					'post_status'   => 'inherit',
					'post_date_gmt' => '2026-06-16 07:00:00',
					'post_date'     => '2026-06-16 11:11:11',
				)
			),
		);

		$out = AB_MCP_Tools_Media::list_media( array() );

		self::assertSame( '2026-06-16T09:00:00+02:00', $out['items'][0]['date'] );
	}

	public function testTheMediaListFallsBackToTheLocalColumn(): void {
		self::site( 'Europe/Zurich' );
		$GLOBALS['ab_test_query'] = static fn(): array => array(
			ab_test_add_post(
				21,
				array(
					'post_type'     => 'attachment',
					'post_status'   => 'inherit',
					'post_date_gmt' => '0000-00-00 00:00:00',
					'post_date'     => '2026-06-16 09:00:00',
				)
			),
		);

		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Media::list_media( array() )['items'][0]['date'] );
	}

	public function testTheRevisionListFallsBackToTheLocalColumn(): void {
		self::site( 'Europe/Zurich' );
		ab_test_add_post( 32 );
		$GLOBALS['ab_test_revisions'][32] = array(
			ab_test_add_post(
				33,
				array(
					'post_type'         => 'revision',
					'post_parent'       => 32,
					'post_modified_gmt' => '0000-00-00 00:00:00',
					'post_modified'     => '2026-06-16 09:00:00',
				)
			),
		);

		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Content::list_revisions( array( 'id' => 32 ) )['revisions'][0]['date'] );
	}

	public function testCommentsFallBackToTheLocalColumn(): void {
		self::site( 'Europe/Zurich' );
		$GLOBALS['ab_test_comments'][41] = (object) array(
			'comment_ID'       => 41,
			'comment_post_ID'  => 30,
			'comment_author'   => 'Ben',
			'comment_content'  => 'Hallo',
			'comment_date_gmt' => '0000-00-00 00:00:00',
			'comment_date'     => '2026-06-16 09:00:00',
			'comment_parent'   => 0,
		);

		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Taxonomy_Comments::get_comment_tool( array( 'comment_id' => 41 ) )['date'] );
		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Taxonomy_Comments::list_comments( array() )['comments'][0]['date'] );
	}

	public function testTheRevisionListShowsSiteTime(): void {
		self::site( 'Europe/Zurich' );
		ab_test_add_post( 30 );
		$GLOBALS['ab_test_revisions'][30] = array(
			ab_test_add_post(
				31,
				array(
					'post_type'         => 'revision',
					'post_parent'       => 30,
					'post_modified_gmt' => '2026-06-16 07:00:00',
					'post_modified'     => '2026-06-16 11:11:11',
				)
			),
		);

		$out = AB_MCP_Tools_Content::list_revisions( array( 'id' => 30 ) );

		self::assertSame( '2026-06-16T09:00:00+02:00', $out['revisions'][0]['date'] );
	}

	public function testOneCommentAndTheCommentListShowSiteTime(): void {
		self::site( 'Europe/Zurich' );
		$GLOBALS['ab_test_comments'][40] = (object) array(
			'comment_ID'       => 40,
			'comment_post_ID'  => 30,
			'comment_author'   => 'Anna',
			'comment_content'  => 'Hallo',
			'comment_date_gmt' => '2026-06-16 07:00:00',
			'comment_date'     => '2026-06-16 11:11:11',
			'comment_parent'   => 0,
		);

		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Taxonomy_Comments::get_comment_tool( array( 'comment_id' => 40 ) )['date'] );
		self::assertSame( '2026-06-16T09:00:00+02:00', AB_MCP_Tools_Taxonomy_Comments::list_comments( array() )['comments'][0]['date'] );
	}

	/* ------------------------------------------------------------ parse_post_date */

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function readableDates(): array {
		return array(
			'site time with seconds'              => array( '2026-10-02 09:00:00', '2026-10-02 09:00:00', '' ),
			'site time without seconds'           => array( '2026-10-02 09:00', '2026-10-02 09:00:00', '' ),
			'site time with a T'                  => array( '2026-10-02T09:00', '2026-10-02 09:00:00', '' ),
			'a day alone is midnight'             => array( '2026-10-02', '2026-10-02 00:00:00', '' ),
			'surrounding spaces'                  => array( '  2026-10-02 09:00:00 ', '2026-10-02 09:00:00', '' ),
			'UTC with Z'                          => array( '2026-10-02T09:00:00Z', '2026-10-02 11:00:00', '2026-10-02 09:00:00' ),
			'the site offset in summer'           => array( '2026-10-02T09:00:00+02:00', '2026-10-02 09:00:00', '2026-10-02 07:00:00' ),
			'the site offset in winter'           => array( '2026-01-15T09:00:00+01:00', '2026-01-15 09:00:00', '2026-01-15 08:00:00' ),
			'another offset'                      => array( '2026-10-02T09:00:00-05:00', '2026-10-02 16:00:00', '2026-10-02 14:00:00' ),
			'fractions of a second are dropped'   => array( '2026-10-02T09:00:00.123+02:00', '2026-10-02 09:00:00', '2026-10-02 07:00:00' ),
			'an offset without seconds'           => array( '2026-10-02T09:00+02:00', '2026-10-02 09:00:00', '2026-10-02 07:00:00' ),
			'a space instead of T, with offset'   => array( '2026-10-02 09:00:00+02:00', '2026-10-02 09:00:00', '2026-10-02 07:00:00' ),
			'lower case t and z'                  => array( '2026-10-02t09:00:00z', '2026-10-02 11:00:00', '2026-10-02 09:00:00' ),
			'the later 02:30 when clocks go back' => array( '2026-10-25T02:30:00+01:00', '2026-10-25 02:30:00', '2026-10-25 01:30:00' ),
			'that hour in site time, as WordPress takes it' => array( '2026-10-25 02:30', '2026-10-25 02:30:00', '' ),
			'before 1894, from UTC'               => array( '1890-06-16T07:00:00Z', '1890-06-16 07:29:46', '1890-06-16 07:00:00' ),
		);
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public static function readableDatesElsewhere(): array {
		return array(
			'UTC from Z, west of UTC'         => array( '-5', '2026-10-02T09:00:00Z', '2026-10-02 04:00:00', '2026-10-02 09:00:00' ),
			'an offset, on a UTC site'        => array( 'UTC', '2026-10-02T09:00:00+02:00', '2026-10-02 07:00:00', '2026-10-02 07:00:00' ),
			'site time, half an hour offset'  => array( '+5.5', '2026-10-02 09:00', '2026-10-02 09:00:00', '' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'readableDatesElsewhere' )]
	public function testTheParserUsesTheSitesOwnZone( string $zone, string $given, string $local, string $gmt ): void {
		self::site( $zone );
		self::assertSame( array( 'post_date' => $local, 'post_date_gmt' => $gmt ), AB_MCP_Tools_Base::parse_post_date( $given ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'readableDates' )]
	public function testAReadableDateBecomesBothColumns( string $given, string $local, string $gmt ): void {
		self::site( 'Europe/Zurich' );
		self::assertSame( array( 'post_date' => $local, 'post_date_gmt' => $gmt ), AB_MCP_Tools_Base::parse_post_date( $given ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function unreadableDates(): array {
		return array(
			'a thirteenth month'                    => array( '2026-13-01', 'no-such-date' ),
			'the thirtieth of February'             => array( '2026-02-30 10:00:00', 'no-such-date' ),
			'hour 24'                               => array( '2026-10-02 24:00:00', 'no-such-date' ),
			'minute 60'                             => array( '2026-10-02 09:60', 'no-such-date' ),
			'second 60'                             => array( '2026-10-02 09:00:60', 'no-such-date' ),
			'year zero'                             => array( '0000-01-01', 'no-such-date' ),
			'an offset of 24 hours'                 => array( '2026-10-02T09:00:00+24:00', 'no-such-date' ),
			'an offset with minute 60'              => array( '2026-10-02T09:00:00+02:60', 'no-such-date' ),
			'words'                                 => array( 'next friday', 'unreadable' ),
			'nothing'                               => array( '', 'unreadable' ),
			'a local notation'                      => array( '02.10.2026 09:00', 'unreadable' ),
			'an offset without minutes'             => array( '2026-10-02T09:00:00+2', 'unreadable' ),
			'an offset without a colon'             => array( '2026-10-02T09:00:00+0200', 'unreadable' ),
			'a second line'                         => array( "2026-10-02 09:00:00\n2026-10-03", 'unreadable' ),
			'a site time the clocks skip'           => array( '2026-03-29 02:30', 'skipped' ),
			'the earlier 02:30 when clocks go back' => array( '2026-10-25T02:30:00+02:00', 'repeated' ),
			'past the year 9999 after conversion'   => array( '9999-12-31T23:30:00-02:00', 'year' ),
			'before the year 1 after conversion'    => array( '0001-01-01T00:30:00+02:00', 'year' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreadableDates' )]
	public function testAnUnusableDateIsRefusedWithItsReason( string $given, string $reason ): void {
		self::site( 'Europe/Zurich' );
		$result = AB_MCP_Tools_Base::parse_post_date( $given );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_invalid_date', $result->get_error_code() );
		self::assertSame( $reason, $result->get_error_data()['reason'] );
	}

	public function testASiteTimeWhoseUtcYearLeaves9999IsRefused(): void {
		self::site( '-5' );
		$result = AB_MCP_Tools_Base::parse_post_date( '9999-12-31 23:30:00' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'year', $result->get_error_data()['reason'] );
	}

	public function testTheRepeatedHourRefusalSaysHowWordPressWouldReadIt(): void {
		self::site( 'Europe/Zurich' );
		$result = AB_MCP_Tools_Base::parse_post_date( '2026-10-25T02:30:00+02:00' );
		self::assertStringContainsString( '2026-10-25T02:30:00+01:00', $result->get_error_message() );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function storedMoments(): array {
		return array(
			'summer'          => array( '2026-06-16 07:00:00', '2026-06-16 09:00:00' ),
			'winter'          => array( '2026-01-15 12:00:00', '2026-01-15 13:00:00' ),
			'before 1894'     => array( '1890-06-16 07:00:00', '1890-06-16 07:29:46' ),
			'later 02:30'     => array( '2026-10-25 01:30:00', '2026-10-25 02:30:00' ),
		);
	}

	/** What a tool returns comes back as the same moment. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'storedMoments' )]
	public function testADateReadFromAToolCanBePassedBack( string $gmt, string $local ): void {
		self::site( 'Europe/Zurich' );
		$read = (string) AB_MCP_Tools_Base::site_time( $gmt, $local );

		self::assertSame( array( 'post_date' => $local, 'post_date_gmt' => $gmt ), AB_MCP_Tools_Base::parse_post_date( $read ) );
	}

	/* ------------------------------------------------------------ wp_create_post */

	public function testCreatingWithAnOffsetFixesBothColumns(): void {
		self::site( 'Europe/Zurich' );

		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Termin', 'date' => '2026-10-02T09:00:00Z' ) );

		self::assertSame( '2026-10-02 11:00:00', $GLOBALS['ab_test_inserted'][0]['post_date'] );
		self::assertSame( '2026-10-02 09:00:00', $GLOBALS['ab_test_inserted'][0]['post_date_gmt'] );
		self::assertSame( '2026-10-02T11:00:00+02:00', $out['post']['date'] );
	}

	public function testCreatingWithSiteTimeLeavesTheUtcColumnToWordPress(): void {
		self::site( 'Europe/Zurich' );

		AB_MCP_Tools_Content::create_post( array( 'title' => 'Termin', 'date' => '2026-10-02 09:00' ) );

		self::assertSame( '2026-10-02 09:00:00', $GLOBALS['ab_test_inserted'][0]['post_date'] );
		self::assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['ab_test_inserted'][0] );
	}

	public function testCreatingWithAnUnreadableDateCreatesNothing(): void {
		self::site( 'Europe/Zurich' );

		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Termin', 'date' => 'next friday' ) );

		self::assertInstanceOf( WP_Error::class, $out );
		self::assertSame( 'ab_mcp_invalid_date', $out->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
	}

	public function testCreatingWithoutADateSetsNone(): void {
		AB_MCP_Tools_Content::create_post( array( 'title' => 'Ohne Datum' ) );

		self::assertArrayNotHasKey( 'post_date', $GLOBALS['ab_test_inserted'][0] );
		self::assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['ab_test_inserted'][0] );
	}
}
