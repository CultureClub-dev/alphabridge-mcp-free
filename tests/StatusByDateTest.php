<?php
/**
 * WordPress settles "publish" and "future" by the date: wp_insert_post()
 * stores "future" for a date at least a minute ahead and "publish" for any
 * other. Before this rule, wp_update_post took "future" without a date and a
 * draft went live at once — the draft is dated at the save, and a "future"
 * post dated now is published. A save must not publish a post, or take one
 * off the site, unless the call asks for exactly that.
 *
 * The rule is a pure function, measured against a table; the tools are
 * checked for what they hand WordPress and for writing nothing when refused.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;

final class StatusByDateTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		update_option( 'timezone_string', 'Europe/Zurich' );
	}

	/** The moment the table is measured at: 28 September 2026, 10:00 UTC. */
	private static function now(): int {
		return gmmktime( 10, 0, 0, 9, 28, 2026 );
	}

	/** A UTC date so many seconds from now(). */
	private static function at( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', self::now() + $seconds );
	}

	/* ------------------------------------------------------------- the rule */

	/** @return array<string,array{0:string,1:?string,2:?string,3:string,4:bool,5:string}> */
	public static function saves(): array {
		return array(
			// type, status now, status asked, UTC date saved, date given, refusal.
			'new, scheduled an hour ahead'          => array( 'post', null, 'future', self::at( 3600 ), true, '' ),
			'new, scheduled 59 s ahead'             => array( 'post', null, 'future', self::at( 59 ), true, 'would_publish' ),
			'new, scheduled exactly a minute ahead' => array( 'post', null, 'future', self::at( 60 ), true, 'too_close' ),
			'new, scheduled 4:59 ahead'             => array( 'post', null, 'future', self::at( 299 ), true, 'too_close' ),
			'new, scheduled five minutes ahead'     => array( 'post', null, 'future', self::at( 300 ), true, '' ),
			'new, scheduled without a date'         => array( 'post', null, 'future', self::at( 0 ), false, 'would_publish' ),
			'new, published with a date ahead'      => array( 'post', null, 'publish', self::at( 3600 ), true, '' ),
			'new, published now'                    => array( 'post', null, 'publish', self::at( 0 ), false, '' ),
			'new draft dated ahead'                 => array( 'post', null, 'draft', self::at( 3600 ), true, '' ),
			'new page scheduled without a date'     => array( 'page', null, 'future', self::at( 0 ), false, 'would_publish' ),
			'draft scheduled ahead'                 => array( 'post', 'draft', 'future', self::at( 3600 ), true, '' ),
			'draft scheduled without a date'        => array( 'post', 'draft', 'future', self::at( 0 ), false, 'would_publish' ),
			'draft scheduled for yesterday'         => array( 'post', 'draft', 'future', self::at( -86400 ), true, 'would_publish' ),
			'draft published with a date ahead'     => array( 'post', 'draft', 'publish', self::at( 3600 ), true, '' ),
			'draft published two minutes ahead'     => array( 'post', 'draft', 'publish', self::at( 120 ), true, 'too_close' ),
			'draft dated ahead, published bare'     => array( 'post', 'draft', 'publish', self::at( 3600 ), false, 'stays_scheduled' ),
			'draft given a date only'               => array( 'post', 'draft', null, self::at( 3600 ), true, '' ),
			'pending scheduled ahead'               => array( 'post', 'pending', 'future', self::at( 3600 ), true, '' ),
			'private given a date ahead'            => array( 'post', 'private', null, self::at( 3600 ), true, '' ),
			'published, backdated'                  => array( 'post', 'publish', null, self::at( -86400 ), true, '' ),
			'published, edited'                     => array( 'post', 'publish', null, self::at( -86400 ), false, '' ),
			'published, dated ahead'                => array( 'post', 'publish', null, self::at( 3600 ), true, 'would_unpublish' ),
			'published, scheduled ahead'            => array( 'post', 'publish', 'future', self::at( 3600 ), true, '' ),
			'published, scheduled for the past'     => array( 'post', 'publish', 'future', self::at( -60 ), true, 'would_publish' ),
			'published, scheduled two minutes ahead' => array( 'post', 'publish', 'future', self::at( 120 ), true, 'too_close' ),
			'scheduled, rescheduled'                => array( 'post', 'future', null, self::at( 7200 ), true, '' ),
			'scheduled, edited'                     => array( 'post', 'future', null, self::at( 7200 ), false, '' ),
			'scheduled, edited two minutes before'  => array( 'post', 'future', null, self::at( 120 ), false, 'too_close' ),
			'scheduled, moved to two minutes ahead' => array( 'post', 'future', null, self::at( 120 ), true, 'too_close' ),
			'scheduled, dated in the past'          => array( 'post', 'future', null, self::at( -3600 ), true, 'would_publish_scheduled' ),
			'scheduled, date passed, edited'        => array( 'post', 'future', null, self::at( -3600 ), false, 'would_publish_scheduled' ),
			'scheduled, published bare'             => array( 'post', 'future', 'publish', self::at( 3600 ), false, 'stays_scheduled' ),
			'scheduled, published with a past date' => array( 'post', 'future', 'publish', self::at( -60 ), true, '' ),
			'scheduled, published with a new date'  => array( 'post', 'future', 'publish', self::at( 3600 ), true, '' ),
			'scheduled, moved to draft'             => array( 'post', 'future', 'draft', self::at( -3600 ), false, '' ),
			'attachment, dated ahead'               => array( 'attachment', 'inherit', 'publish', self::at( 3600 ), false, '' ),
			'attachment handed future, close'       => array( 'attachment', 'inherit', 'future', self::at( 120 ), true, '' ),
			'unreadable date is not ahead'          => array( 'post', null, 'future', 'soon', true, 'would_publish' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'saves' )]
	public function testTheRuleRefusesExactlyTheSavesTheDateWouldTurn( string $type, ?string $old, ?string $asked, string $gmt, bool $dated, string $expected ): void {
		self::assertSame( $expected, AB_MCP_Tools_Content::status_date_refusal( $type, $old, $asked, $gmt, $dated, self::now() ) );
	}

	/* ------------------------------------------------------- wp_update_post */

	public function testASiteTimeDateSetsBothColumnsAndEditDate(): void {
		ab_test_add_post( 5 );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'date' => '2001-02-03 04:05' ) );

		self::assertTrue( $out['updated'] );
		$handed = $GLOBALS['ab_test_updated'][0];
		self::assertSame( '2001-02-03 04:05:00', $handed['post_date'] );
		// Zurich in winter is UTC+1. Without the UTC column WordPress would
		// keep the stored one next to the new local date.
		self::assertSame( '2001-02-03 03:05:00', $handed['post_date_gmt'] );
		self::assertTrue( $handed['edit_date'], 'Without edit_date WordPress dates a draft at the save.' );
		self::assertArrayNotHasKey( 'post_status', $handed, 'A date alone hands over no status.' );
	}

	public function testADateWithAnOffsetSetsBothColumnsFromIt(): void {
		ab_test_add_post( 5 );

		AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'date' => '2001-02-03T04:05:00Z' ) );

		self::assertSame( '2001-02-03 05:05:00', $GLOBALS['ab_test_updated'][0]['post_date'] );
		self::assertSame( '2001-02-03 04:05:00', $GLOBALS['ab_test_updated'][0]['post_date_gmt'] );
	}

	public function testAnUnreadableDateChangesNothing(): void {
		ab_test_add_post( 5 );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'title' => 'New', 'date' => 'next friday' ) );

		self::assertSame( 'ab_mcp_invalid_date', $out->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}

	public function testSchedulingADraftWithoutADateIsRefused(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'future' ) );

		self::assertSame( 'ab_mcp_status_by_date', $out->get_error_code() );
		self::assertSame( 'would_publish', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'], 'Refused before WordPress is called.' );
	}

	public function testADraftsFloatingDateAheadDoesNotCountForScheduling(): void {
		// A draft may show a planned local date without a UTC date. WordPress
		// dates such a draft at the save, however far ahead the local one is,
		// so "future" without a date would publish it now.
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00', 'post_date' => '2099-01-01 09:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'future' ) );

		self::assertSame( 'would_publish', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}

	public function testSchedulingADraftForADateAheadHandsBothOver(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'future', 'date' => '2099-06-01 09:00' ) );

		self::assertTrue( $out['updated'] );
		$handed = $GLOBALS['ab_test_updated'][0];
		self::assertSame( 'future', $handed['post_status'] );
		self::assertSame( '2099-06-01 09:00:00', $handed['post_date'] );
		self::assertSame( '2099-06-01 07:00:00', $handed['post_date_gmt'] );
	}

	public function testADateAheadDoesNotTakeAPublishedPostOffTheSite(): void {
		ab_test_add_post( 5 );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'date' => '2099-01-01 09:00' ) );

		self::assertSame( 'would_unpublish', $out->get_error_data()['reason'] );
		self::assertStringContainsString( '2099-01-01T09:00:00+01:00', $out->get_error_message(), 'The date is named in site time.' );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}

	public function testAnEditDoesNotPublishAScheduledPostWhoseDateHasPassed(): void {
		ab_test_add_post( 8, array( 'post_status' => 'future', 'post_date_gmt' => '2001-01-01 08:00:00', 'post_date' => '2001-01-01 09:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 8, 'title' => 'Typo fixed' ) );

		self::assertSame( 'would_publish_scheduled', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}

	public function testPublishingAScheduledPostNeedsADateThatIsNotAhead(): void {
		ab_test_add_post( 9, array( 'post_status' => 'future', 'post_date_gmt' => '2099-01-01 08:00:00', 'post_date' => '2099-01-01 09:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 9, 'status' => 'publish' ) );
		self::assertSame( 'stays_scheduled', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 9, 'status' => 'publish', 'date' => '2001-01-01 09:00' ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( 'publish', $GLOBALS['ab_test_updated'][0]['post_status'] );
	}

	public function testAStatusGivenAsNullIsRefusedNotStoredAsDraft(): void {
		ab_test_add_post( 5 );

		foreach ( array( null, array( 'publish' ) ) as $status ) {
			$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => $status ) );
			self::assertSame( 'ab_mcp_invalid_status', $out->get_error_code() );
		}
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}

	/* ------------------------------------------------------- wp_create_post */

	public function testCreatingAScheduledPostWithoutADateIsRefused(): void {
		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Plan', 'status' => 'future' ) );

		self::assertSame( 'would_publish', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
	}

	public function testCreatingAScheduledPostForADateAheadWorks(): void {
		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Plan', 'status' => 'future', 'date' => '2099-06-01 09:00' ) );

		self::assertTrue( $out['created'] );
		self::assertSame( 'future', $GLOBALS['ab_test_inserted'][0]['post_status'] );
	}

	public function testCreatingPublishedWithADateAheadIsLeftToWordPressToSchedule(): void {
		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Plan', 'status' => 'publish', 'date' => '2099-06-01 09:00' ) );

		self::assertTrue( $out['created'] );
		self::assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['ab_test_inserted'][0], 'A site-time date still leaves the UTC column to WordPress.' );
	}

	/* --------------------------------------------- scheduling needs a lead */

	public function testAScheduleOnlyAMinuteAheadIsRefusedAsTooClose(): void {
		$GLOBALS['ab_test_now'] = gmmktime( 10, 0, 0, 9, 28, 2026 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'future', 'date' => '2026-09-28T10:01:00Z' ) );
		self::assertSame( 'too_close', $out->get_error_data()['reason'] );
		self::assertStringContainsString( '2026-09-28T12:01:00+02:00', $out->get_error_message() );

		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Plan', 'status' => 'future', 'date' => '2026-09-28T10:01:00Z' ) );
		self::assertSame( 'too_close', $out->get_error_data()['reason'] );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
	}

	public function testFiveMinutesAheadHoldsThroughASaveThatRunsLong(): void {
		// WordPress reads its clock after the tool did; even three minutes
		// later the post is still more than a minute ahead and stays scheduled.
		$GLOBALS['ab_test_now']        = gmmktime( 10, 0, 0, 9, 28, 2026 );
		$GLOBALS['ab_test_core_delay'] = 180;
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'future', 'date' => '2026-09-28T10:05:00Z' ) );

		self::assertTrue( $out['updated'] );
		self::assertSame( 'future', get_post( 7 )->post_status );
	}

	public function testASecondSaveOfTheSamePostMinutesLaterDoesNotPublishItEarly(): void {
		// Another plugin saves the post again while this call is still running,
		// here almost four minutes later: WordPress keeps it scheduled.
		$GLOBALS['ab_test_now'] = gmmktime( 10, 0, 0, 9, 28, 2026 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
		AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'publish', 'date' => '2026-09-28T10:05:00Z' ) );
		self::assertSame( 'future', get_post( 7 )->post_status, '"publish" with a date ahead schedules the post.' );

		$GLOBALS['ab_test_now'] += 239;
		wp_update_post( array( 'ID' => 7, 'post_excerpt' => 'Added by another plugin' ) );

		self::assertSame( 'future', get_post( 7 )->post_status );
	}

	/* --------------------------------------- an undated save, dated here */

	public function testPublishingAnUndatedDraftHandsBothColumnsFromOneReading(): void {
		$GLOBALS['ab_test_now'] = gmmktime( 10, 0, 0, 9, 28, 2026 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'publish' ) );

		self::assertTrue( $out['updated'] );
		$handed = $GLOBALS['ab_test_updated'][0];
		self::assertSame( '2026-09-28 12:00:00', $handed['post_date'] );
		self::assertSame( '2026-09-28 10:00:00', $handed['post_date_gmt'] );
		self::assertTrue( $handed['edit_date'] );
	}

	public function testAnUndatedDraftSavedAsDraftStaysUndated(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'title' => 'Still a draft' ) );

		self::assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['ab_test_updated'][0] );
		self::assertArrayNotHasKey( 'edit_date', $GLOBALS['ab_test_updated'][0] );
	}

	public function testAPublishedPostWithoutAUtcDateKeepsItsDateOnEdit(): void {
		// WordPress dates only a draft, pending or auto-draft post at the save;
		// a published one without a UTC date keeps its local date.
		ab_test_add_post( 5, array( 'post_date_gmt' => '0000-00-00 00:00:00', 'post_date' => '2001-01-01 09:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'title' => 'Typo fixed' ) );

		self::assertTrue( $out['updated'] );
		self::assertArrayNotHasKey( 'post_date', $GLOBALS['ab_test_updated'][0] );
		self::assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['ab_test_updated'][0] );
	}

	public function testTheStoredUtcDateDecidesWhenTheColumnsDisagree(): void {
		// WordPress takes the stored UTC column, not the local one, when it
		// settles "future" — here still ahead although the local one has passed.
		ab_test_add_post( 9, array( 'post_status' => 'future', 'post_date_gmt' => '2099-01-01 08:00:00', 'post_date' => '2001-01-01 09:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 9, 'title' => 'Typo fixed' ) );

		self::assertTrue( $out['updated'] );
		self::assertSame( 'future', get_post( 9 )->post_status );
	}

	public function testCreatingPublishedWithoutADateHandsBothColumnsFromOneReading(): void {
		$GLOBALS['ab_test_now'] = gmmktime( 10, 0, 0, 9, 28, 2026 );

		AB_MCP_Tools_Content::create_post( array( 'title' => 'Now', 'status' => 'publish' ) );

		self::assertSame( '2026-09-28 12:00:00', $GLOBALS['ab_test_inserted'][0]['post_date'] );
		self::assertSame( '2026-09-28 10:00:00', $GLOBALS['ab_test_inserted'][0]['post_date_gmt'] );
	}

	public function testInTheRepeatedHourAnUndatedSaveIsDatedAtTheRealMoment(): void {
		// 25 October 2026, 00:30 UTC is the first 02:30 in Zurich (+02:00).
		// WordPress would date the save by the site's clock and read that
		// "02:30" as the second one (+01:00), an hour ahead, and schedule
		// instead of publishing. Dated here, it is the real moment.
		$GLOBALS['ab_test_now'] = gmmktime( 0, 30, 0, 10, 25, 2026 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 7, 'status' => 'publish' ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( '2026-10-25 00:30:00', $GLOBALS['ab_test_updated'][0]['post_date_gmt'] );
		self::assertSame( 'publish', get_post( 7 )->post_status );

		$out = AB_MCP_Tools_Content::create_post( array( 'title' => 'Plan', 'status' => 'future' ) );
		self::assertSame( 'would_publish', $out->get_error_data()['reason'], 'Now is not ahead, whatever the local clock reads.' );
	}

	/* ----------------------------------------------------- the right to publish */

	public function testADateThatWouldScheduleAPublishedPostNeedsThePublishRight(): void {
		// "publish" on a published post changes no status as asked, but with a
		// date ahead WordPress stores "future": the post leaves the site.
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'publish_posts' !== $cap;
		ab_test_add_post( 5 );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'publish', 'date' => '2099-06-01 09:00' ) );

		self::assertSame( 'ab_mcp_forbidden', $out->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$out                    = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'publish', 'date' => '2099-06-01 09:00' ) );
		self::assertTrue( $out['updated'] );
		self::assertSame( 'future', get_post( 5 )->post_status );
	}

	/* ------------------------------------------ what a refusal says to do works */

	/** A post with this status and a UTC date so many seconds from the fixed now. */
	private static function postAt( int $id, string $status, int $lead ): void {
		$GLOBALS['ab_test_now'] = self::now();
		ab_test_add_post( $id, array( 'post_status' => $status, 'post_date_gmt' => self::at( $lead ), 'post_date' => self::at( $lead ) ) );
	}

	/** The fixed now plus so many seconds, as an RFC 3339 date to pass. */
	private static function dateAt( int $lead ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', self::now() + $lead );
	}

	/**
	 * Each refusal names one or two ways on; each must be accepted when taken.
	 *
	 * @return array<string,array{0:string,1:int,2:array,3:string,4:array,5:?array}>
	 */
	public static function refusalsAndWaysOn(): array {
		return array(
			// status now, lead, refused call, reason, way on 1, way on 2.
			'too_close: an edit two minutes before' => array( 'future', 120, array(), 'too_close', array( 'date' => self::dateAt( 600 ) ), array( 'status' => 'publish', 'date' => self::dateAt( 0 ) ) ),
			'stays_scheduled: publish bare'         => array( 'future', 3600, array( 'status' => 'publish' ), 'stays_scheduled', array( 'status' => 'publish', 'date' => self::dateAt( 0 ) ), array( 'status' => 'future', 'date' => self::dateAt( 600 ) ) ),
			'would_unpublish: a date ahead'         => array( 'publish', -86400, array( 'date' => self::dateAt( 3600 ) ), 'would_unpublish', array( 'status' => 'future', 'date' => self::dateAt( 600 ) ), array( 'date' => self::dateAt( -60 ) ) ),
			'would_publish: half a minute ahead'    => array( 'draft', 3600, array( 'status' => 'future', 'date' => self::dateAt( 30 ) ), 'would_publish', array( 'status' => 'future', 'date' => self::dateAt( 600 ) ), null ),
			'would_publish_scheduled: date passed'  => array( 'future', -3600, array(), 'would_publish_scheduled', array( 'status' => 'publish' ), array( 'date' => self::dateAt( 600 ) ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refusalsAndWaysOn' )]
	public function testEveryWayOnARefusalNamesIsAccepted( string $status, int $lead, array $refused, string $reason, array $first, ?array $second ): void {
		self::postAt( 21, $status, $lead );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 21 ) + $refused );
		self::assertSame( $reason, $out->get_error_data()['reason'] );

		foreach ( array_filter( array( 22 => $first, 23 => $second ) ) as $id => $way ) {
			self::postAt( $id, $status, $lead );
			$out = AB_MCP_Tools_Content::update_post( array( 'id' => $id ) + $way );
			self::assertIsArray( $out, 'Refused again: ' . ( is_wp_error( $out ) ? $out->get_error_message() : '' ) );
			self::assertTrue( $out['updated'] );
		}
	}

	/* ------------------------------------------------------------ the schema */

	public function testTheUpdateToolOffersTheDateWithTheSharedDescription(): void {
		$registry = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Content::register( $registry );

		$date = $registry->get( 'wp_update_post' )['inputSchema']['properties']['date'];
		self::assertSame( 'string', $date['type'] );
		self::assertSame( AB_MCP_Tools_Content::UPDATE_DATE_DESCRIPTION, $date['description'] );
		self::assertStringContainsString( 'never changes the status', $date['description'] );
	}
}
