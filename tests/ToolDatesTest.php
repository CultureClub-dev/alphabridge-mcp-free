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
				'post_date'         => '2026-06-16 09:00:00',
				'post_modified_gmt' => '2026-06-17 08:30:00',
				'post_modified'     => '2026-06-17 10:30:00',
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
					'post_date'     => '2026-06-16 09:00:00',
				)
			),
		);

		$out = AB_MCP_Tools_Media::list_media( array() );

		self::assertSame( '2026-06-16T09:00:00+02:00', $out['items'][0]['date'] );
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
					'post_modified'     => '2026-06-16 09:00:00',
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
			'comment_date'     => '2026-06-16 09:00:00',
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
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'readableDates' )]
	public function testAReadableDateBecomesBothColumns( string $given, string $local, string $gmt ): void {
		self::site( 'Europe/Zurich' );
		self::assertSame( array( 'post_date' => $local, 'post_date_gmt' => $gmt ), AB_MCP_Tools_Base::parse_post_date( $given ) );
	}

	/** @return array<string,array{0:string}> */
	public static function unreadableDates(): array {
		return array(
			'a thirteenth month'          => array( '2026-13-01' ),
			'the thirtieth of February'   => array( '2026-02-30 10:00:00' ),
			'hour 24'                     => array( '2026-10-02 24:00:00' ),
			'minute 60'                   => array( '2026-10-02 09:60' ),
			'second 60'                   => array( '2026-10-02 09:00:60' ),
			'year zero'                   => array( '0000-01-01' ),
			'words'                       => array( 'next friday' ),
			'nothing'                     => array( '' ),
			'a local notation'            => array( '02.10.2026 09:00' ),
			'an offset without minutes'   => array( '2026-10-02T09:00:00+2' ),
			'an offset without a colon'   => array( '2026-10-02T09:00:00+0200' ),
			'an offset of 24 hours'       => array( '2026-10-02T09:00:00+24:00' ),
			'an offset with minute 60'    => array( '2026-10-02T09:00:00+02:60' ),
			'a second line'               => array( "2026-10-02 09:00:00\n2026-10-03" ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreadableDates' )]
	public function testAnUnreadableDateIsRefused( string $given ): void {
		self::site( 'Europe/Zurich' );
		$result = AB_MCP_Tools_Base::parse_post_date( $given );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_invalid_date', $result->get_error_code() );
	}

	public function testADateReadFromAToolCanBePassedBackUnchanged(): void {
		self::site( 'Europe/Zurich' );
		$read = AB_MCP_Tools_Base::site_time( '2026-06-16 07:00:00', '2026-06-16 09:00:00' );

		self::assertSame(
			array( 'post_date' => '2026-06-16 09:00:00', 'post_date_gmt' => '2026-06-16 07:00:00' ),
			AB_MCP_Tools_Base::parse_post_date( (string) $read )
		);
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
