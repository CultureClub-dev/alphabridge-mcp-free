<?php
/**
 * The section «Takeover» of the page «Staging» and transfer in
 * wp_staging_status, as far as a test without a database shows them (Z8,
 * decision 35 of the plan of 01.11.2026, branch 12; the moments of the plan
 * of the incentives of 03.11.2026).
 *
 * What must hold:
 *
 * - transfer reports the side of the site: none (no role, or a production
 *   site without a pair), staging (its key with the fingerprint, or none,
 *   with the way), production (the anchor active or prepared with its
 *   fingerprint, the tables, the way); it reads only, writes nothing, and
 *   advertises nothing.
 * - The operating parts of the moments: M1 from 10 object pairs a run can
 *   take over, M2 for a run closed as qualified with its steps, calls and
 *   the duration closed − created, M3 from the third pair of a run taken
 *   back; the duration in words.
 * - The edition part of a notice shows only in an edition without the
 *   feature, with the notes not hidden, after the first report, once its
 *   feature is published (none is in this version), once per site and
 *   moment, one notice at a time by priority (M1 before M3). The text of M1
 *   has no sentence on stopping the run (V4); a feature of Pro is a line
 *   with a link, no switch (V19). The switch «Hide edition notes» is
 *   offered only where a note can show.
 * - The retention of the backups of a run in words, by state; the report as
 *   the page shows it (what a run can take over, M1 only once it is done);
 *   the rest of a run named by its objects.
 * - The section draws what it was given: on a site without a pair one line
 *   and no form; on the staging site the way and the store; on the
 *   production site without a confirmed key the way; with one, the store
 *   with the rule for the host, an interrupted run first with its journal,
 *   its rest and «Close as checked», the steps of a run with a form per
 *   pair, the report with M1, the runs with their backups, M2, «Abort» and
 *   «Go on», the pairs with «Discard». Every form posts to its own action
 *   with a nonce bound to its object and carries the state the page showed.
 *   Without a notice nothing in the section names an edition.
 * - Every handler of the section is hooked, refuses without manage_options,
 *   with another nonce and without a POST, and with all three keeps the
 *   outcome for the page and lands on the section.
 *
 * On two WordPress sites with runs, steps and the way back:
 * tests/integration/StagingTransferTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Staging;
use AB_MCP_Transfer;
use AB_MCP_Transfer_Moments as Moments;
use AB_MCP_Transfer_View;
use AbTestExit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/sync-run-stubs.php';
require_once __DIR__ . '/SyncTrustSites.php';

final class StagingTransferTest extends TestCase {

	use SyncTrustSites;

	/** What would name an edition, a price or a trial. */
	const EDITION = '/\bPro\b|Agency|USD|\$|trial|price|upgrade|checkout|licen[cs]e|freemius/i';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		$_GET                            = array();
		$_POST                           = array();
		$_REQUEST                        = array();
		$_SERVER['REQUEST_METHOD']       = 'POST';
		Moments::$published              = null;
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Moments::$published = null;
	}

	/* ------------------------------------------------------------ helpers */

	private static function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', self::xpath( $html )->query( '//body' )->item( 0 )->textContent ) );
	}

	/** A view as AB_MCP_Transfer::view() gives it, for a site of the given side, with $over. */
	private static function view( string $side, array $over = array() ): array {
		$status = array(
			'side' => $side,
			'next' => 'The way on.',
		);
		if ( 'production' === $side ) {
			$status += array(
				'anchor' => array(
					'active'   => array(
						'fingerprint'  => 'ABCD EFGH IJKL MNOP QRST UVWX YZ23 4567',
						'confirmed_at' => '2026-11-09T10:00:00Z',
					),
					'prepared' => null,
				),
				'tables' => true,
				'run'    => null,
			);
		}
		return array_replace(
			array(
				'side'        => $side,
				'status'      => $status,
				'store'       => 'none' === $side ? null : array(
					'available' => true,
					'where'     => 'outside',
					'checked'   => 1762682400,
					'rule'      => null,
					'message'   => null,
				),
				'report'      => null,
				'runs'        => array(),
				'open'        => null,
				'interrupted' => null,
				'run'         => null,
				'pairs'       => null,
				'notice'      => null,
				'notes'       => array(
					'possible' => false,
					'hidden'   => false,
				),
				'outcome'     => null,
			),
			$over
		);
	}

	/** A run as AB_MCP_Sync_Run::get() reads it. */
	private static function a_run( int $id, string $state, array $over = array() ): array {
		return array_merge(
			array(
				'run_id'            => $id,
				'type'              => 'push',
				'state'             => $state,
				'created'           => 1762680000,
				'updated'           => 1762682000,
				'closed'            => in_array( $state, array( 'open', 'interrupted', 'reverting' ), true ) ? 0 : 1762681380,
				'calls'             => 41,
				'forward_closed_at' => 0,
				'keep_until'        => 0,
				'user_id'           => 3,
				'connection'        => 'c',
				'plan'              => array(),
			),
			$over
		);
	}

	/** A run of the list (AB_MCP_Transfer::run_row()). */
	private static function row( int $id, string $state, array $over = array() ): array {
		return array_merge(
			array(
				'run_id'  => $id,
				'type'    => 'push',
				'state'   => $state,
				'created' => 1762680000,
				'closed'  => 0,
				'counts'  => array( 'done' => 2, 'planned' => 3 ),
				'steps'   => 5,
				'backups' => array( 'state' => 'open', 'until' => 0 ),
				'm2'      => null,
				'abort'   => false,
				'go_on'   => false,
			),
			$over
		);
	}

	/** A run of the view with its journal (AB_MCP_Transfer::run_view()). */
	private static function run_view( array $run, array $over = array() ): array {
		return array_merge(
			array(
				'run'     => $run,
				'shown'   => array(
					'steps'   => array(
						array( 'seq' => 1, 'op' => 'check', 'state' => 'done', 'parts' => null ),
						array(
							'seq'   => 2,
							'op'    => 'write',
							'state' => 'begun',
							'parts' => array(
								'object' => array( 'kind' => 'post', 'live_ref' => '12' ),
								'parts'  => array(
									array( 'part' => 'row', 'state' => 'written' ),
									array( 'part' => 'meta:note', 'state' => 'planned' ),
								),
							),
						),
					),
					'rests'   => array( array( 'kind' => 'post', 'live_ref' => '40' ) ),
					'unclear' => array(),
				),
				'error'   => null,
				'rest'    => array(
					array(
						'seq'    => 3,
						'op'     => 'write',
						'object' => array( 'kind' => 'term', 'op' => 'update', 'staging_ref' => '7', 'live_ref' => '9' ),
					),
				),
				'back'    => 0,
				'm2'      => null,
				'm3'      => null,
				'pairs'   => array(),
				'no_back' => null,
				'print'   => str_repeat( 'e', 64 ),
				'back_of' => array(),
			),
			$over
		);
	}

	/** Every form of a section: action => nonce, and its hidden fields. */
	private static function forms( string $html ): array {
		$x   = self::xpath( $html );
		$out = array();
		foreach ( $x->query( '//form' ) as $form ) {
			self::assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
			self::assertSame( 'post', $form->getAttribute( 'method' ) );
			$fields = array();
			foreach ( $x->query( './/input', $form ) as $input ) {
				$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
			$out[] = $fields;
		}
		return $out;
	}

	/* ------------------------------------------- transfer in the status */

	public function testASiteWithoutAPairIsOnNoSide(): void {
		self::assertSame( 'none', AB_MCP_Transfer::status()['side'], 'No role.' );
		AB_MCP_Staging::save( 'production', '', null, 3, 'form' );
		$status = AB_MCP_Transfer::status();
		self::assertSame( 'none', $status['side'], 'A production site without a pair.' );
		self::assertStringContainsString( 'paired with a staging site', $status['next'] );
	}

	public function testTheStagingSiteReportsItsKeyWithTheFingerprint(): void {
		$this->paired();
		$this->on( 'staging' );
		// Confirming the role staging site makes its key (AB_MCP_Sync_Key).
		$status = AB_MCP_Transfer::status();
		self::assertSame( 'staging', $status['side'] );
		self::assertSame( \AB_MCP_Sync_Key::answer()['fingerprint'], $status['key']['fingerprint'] );
		self::assertStringContainsString( 'wp_site_export here makes its packages', $status['next'] );
		self::assertSame( array( 'side', 'key', 'next' ), array_keys( $status ), 'Nothing of the production site.' );
	}

	public function testTheProductionSiteReportsTheAnchorPreparedThenActiveAndTheTables(): void {
		$this->paired();
		$status = AB_MCP_Transfer::status();
		self::assertSame( 'production', $status['side'] );
		self::assertSame( array( 'active' => null, 'prepared' => null ), $status['anchor'] );
		self::assertStringContainsString( 'No key of the staging site is confirmed here', $status['next'] );

		$code = $this->code();
		$this->on( 'live' );
		$prepared = \AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		$status   = AB_MCP_Transfer::status();
		self::assertNull( $status['anchor']['active'] );
		self::assertSame( $prepared['fingerprint'], $status['anchor']['prepared']['fingerprint'] );
		self::assertSame( 'pairing code', $status['anchor']['prepared']['via'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $status['anchor']['prepared']['prepared_at'] );
		self::assertStringContainsString( 'prepared but not confirmed', $status['next'] );

		$active = \AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		$status = AB_MCP_Transfer::status();
		self::assertSame( $active['fingerprint'], $status['anchor']['active']['fingerprint'] );
		self::assertNull( $status['anchor']['prepared'] );
		self::assertFalse( $status['tables'], 'No tables without a database.' );
		self::assertNull( $status['run'] );
		self::assertStringContainsString( 'The tables of the takeover are not there', $status['next'] );
	}

	public function testTheStatusReadsOnlyAndAdvertisesNothing(): void {
		$this->paired();
		$this->anchored();
		foreach ( array( 'live', 'staging' ) as $site ) {
			$this->on( $site );
			$options = $GLOBALS['ab_test_options'];
			$writes  = $GLOBALS['ab_test_writes'];
			$status  = \AB_MCP_Tools_Staging::staging_status( array() )['transfer'];
			self::assertSame( $options, $GLOBALS['ab_test_options'], $site );
			self::assertSame( $writes, $GLOBALS['ab_test_writes'], $site . ': not one write.' );
			self::assertDoesNotMatchRegularExpression( self::EDITION, (string) json_encode( $status ), $site );
		}
	}

	/* --------------------------------------------- the operating parts */

	public function testM1FromTenObjectPairsARunCanTakeOver(): void {
		self::assertNull( Moments::m1( 9 ) );
		self::assertSame( '10 changes ready.', Moments::m1( 10 ) );
		self::assertSame( '37 changes ready.', Moments::m1( 37 ) );
	}

	public function testM2ForAQualifiedCloseWithStepsCallsAndTheDurationOfTheRun(): void {
		$run = self::a_run( 9, 'closed_done', array( 'forward_closed_at' => 1762681380 ) );
		self::assertSame( 'Run 9: 37 steps, 41 calls since the run was made, duration 23 minutes.', Moments::m2( $run, 37 ) );
		self::assertNull( Moments::m2( array( 'forward_closed_at' => 0 ) + $run, 37 ), 'Not qualified: no line.' );
		self::assertNull( Moments::m2( array( 'closed' => 0 ) + $run, 37 ), 'Not closed.' );
		self::assertSame( '45 seconds', Moments::duration( 45 ) );
		self::assertSame( '1 minute', Moments::duration( 60 ) );
		self::assertSame( '3 h 10 min', Moments::duration( 3 * 3600 + 600 ), 'Interruptions count: closed − created.' );
	}

	public function testM3FromTheThirdPairOfARunTakenBack(): void {
		self::assertNull( Moments::m3( 9, 2 ) );
		self::assertSame( 'You are taking run 9 back pair by pair.', Moments::m3( 9, 3 ) );
	}

	/* ---------------------------------------------- the edition parts */

	public function testTheEditionPartsSayWhatEachEditionDoesWithoutTheStopRule(): void {
		$m1 = Moments::edition( 'm1', 37 );
		self::assertStringStartsWith( 'Free carries the takeover over step by step', $m1['text'] );
		self::assertStringContainsString( 'In both editions the lock does not cover every way of writing', $m1['text'], 'The limit stands with it.' );
		self::assertStringEndsWith( 'You can take all 37 over one by one now.', $m1['text'], 'The way of Free.' );
		self::assertDoesNotMatchRegularExpression( '/stop|confirm|without a point/i', $m1['text'], 'V4: no sentence on stopping the run when the restore point fails.' );
		$m3 = Moments::edition( 'm3' );
		self::assertStringContainsString( 'Pro takes the supported changes of a run back together', $m3['text'] );
		self::assertStringContainsString( 'stays as a finding in both editions', $m3['text'] );
		foreach ( array( $m1, $m3 ) as $part ) {
			foreach ( $part['links'] as $link ) {
				self::assertStringStartsWith( Moments::CHECKOUT, $link['url'] );
			}
		}
	}

	public function testNoEditionPartShowsWhileNoFeatureIsPublished(): void {
		Moments::report_seen( 1762680000 );
		self::assertSame( array(), Moments::PUBLISHED, 'None in this version.' );
		self::assertFalse( Moments::possible() );
		self::assertFalse( Moments::may_show( 'm1' ) );
		self::assertNull( Moments::notice( array( 'm1', 'm3' ) ) );
		self::assertSame( array(), Moments::state()['shown'], 'Nothing noted as shown.' );
	}

	/** @return array<string,array{0:callable,1:string}> */
	public static function conditions(): array {
		return array(
			'an edition with the feature' => array( static function (): void {
				add_filter( 'ab_mcp_site_edition', static fn() => 'pro' );
			}, 'Pro has the feature' ),
			'the agency edition'          => array( static function (): void {
				add_filter( 'ab_mcp_site_edition', static fn() => 'agency' );
			}, 'Agency has it too' ),
			'the notes hidden'            => array( static function (): void {
				Moments::set_hidden( true );
			}, 'the switch' ),
			'no report seen'              => array( static function (): void {
				$GLOBALS['ab_test_options'][ Moments::OPTION ] = array();
			}, 'before the first report' ),
			'its feature not published'   => array( static function (): void {
				Moments::$published = array( 'run_revert' );
			}, 'the feature of M1 is not out' ),
			'shown once already'          => array( static function (): void {
				Moments::notice( array( 'm1' ) );
			}, 'once per site' ),
		);
	}

	#[DataProvider( 'conditions' )]
	public function testTheEditionPartShowsOnlyWhereEveryRuleHolds( callable $break, string $why ): void {
		Moments::$published = array( 'site_sync', 'run_revert' );
		Moments::report_seen( 1762680000 );
		self::assertTrue( Moments::may_show( 'm1' ), 'Every rule holds.' );
		$break();
		self::assertFalse( Moments::may_show( 'm1' ), $why );
	}

	public function testOneNoticeAtATimeByPriorityEachOncePerSite(): void {
		Moments::$published = array( 'site_sync', 'run_revert' );
		Moments::report_seen( 1762680000 );
		self::assertTrue( Moments::possible() );
		self::assertSame( 'm1', Moments::notice( array( 'm3', 'm1' ), 1762690000 ), 'M1 before M3.' );
		self::assertSame( array( 'm1' => 1762690000 ), Moments::state()['shown'] );
		self::assertSame( 'm3', Moments::notice( array( 'm3', 'm1' ) ), 'M1 shown: M3 now.' );
		self::assertNull( Moments::notice( array( 'm3', 'm1' ) ), 'Both once.' );
		self::assertNull( Moments::notice( array( 'm2' ) ), 'M2 is a quiet line, never a notice.' );
	}

	public function testTheFirstReportSeenStaysTheFirst(): void {
		Moments::report_seen( 1762680000 );
		Moments::report_seen( 1762690000 );
		self::assertSame( 1762680000, Moments::state()['first_report'] );
	}

	/* ------------------------------------------------- the data shapes */

	/** @return array<string,array{0:array,1:mixed,2:string,3:string}> */
	public static function retention(): array {
		$now = 1762700000;
		return array(
			'open'           => array( self::a_run( 1, 'open' ), array(), 'open', 'kept while the run is not closed' ),
			'interrupted'    => array( self::a_run( 1, 'interrupted' ), array(), 'open', 'kept while the run is not closed' ),
			'table unread'   => array( self::a_run( 1, 'closed_done' ), null, 'unknown', 'kept (the runs could not be read)' ),
			'gone'           => array( self::a_run( 1, 'closed_done', array( 'keep_until' => $now - 1 ) ), array( 1 => false ), 'gone', 'no longer kept' ),
			'until'          => array( self::a_run( 1, 'closed_aborted', array( 'keep_until' => $now + 86400 ) ), array( 1 => true ), 'until', 'kept until ' . wp_date( 'Y-m-d H:i', $now + 86400 ) ),
			'last three'     => array( self::a_run( 1, 'closed_done', array( 'keep_until' => $now - 1, 'forward_closed_at' => $now - 9 ) ), array( 1 => true ), 'last_three', 'kept: one of the last three completed takeovers' ),
			'no period'      => array( self::a_run( 1, 'closed_done' ), array( 1 => true ), 'kept', 'kept (closed before periods were stored)' ),
			'for a way back' => array( self::a_run( 1, 'closed_aborted', array( 'keep_until' => $now - 1 ) ), array( 1 => true ), 'way_back', 'kept for a way back that is not closed' ),
		);
	}

	#[DataProvider( 'retention' )]
	public function testTheBackupsOfARunAreKeptByTheRetentionContract( array $run, $keep, string $state, string $words ): void {
		$backups = AB_MCP_Transfer::backups( $run, $keep, 1762700000 );
		self::assertSame( $state, $backups['state'] );
		self::assertSame( $words, AB_MCP_Transfer_View::backups_text( $backups ) );
	}

	public function testTheReportCountsWhatARunCanTakeOverAndM1OnceItIsDone(): void {
		$index  = array(
			'total'   => 40,
			'counts'  => array( 'actions' => array( 'take_over' => 7, 'create' => 4, 'choose' => 2, 'keep' => 20, 'locked' => 3 ) ),
			'blocked' => array( array( 'key' => 'a' ) ),
		);
		$report = AB_MCP_Transfer::report_view( array( 'report_id' => str_repeat( 'a', 32 ), 'created' => 1, 'expires' => 2, 'index' => $index ) );
		self::assertTrue( $report['done'] );
		self::assertSame( 11, $report['takeable'], 'take_over and create.' );
		self::assertSame( '11 changes ready.', $report['m1'] );
		self::assertSame( 1, $report['blocked'] );
		$index['counts']['actions']['create'] = 2;
		self::assertNull( AB_MCP_Transfer::report_view( array( 'report_id' => 'x', 'created' => 1, 'expires' => 2, 'index' => $index ) )['m1'], 'Nine: no M1.' );
		$begun = AB_MCP_Transfer::report_view( array( 'report_id' => 'x', 'created' => 1, 'expires' => 2, 'index' => null ) );
		self::assertFalse( $begun['done'] );
		self::assertNull( $begun['m1'] );
	}

	public function testTheRestNamesTheStepsNotRunWithTheirObjects(): void {
		$run   = self::a_run(
			4,
			'interrupted',
			array(
				'plan' => array(
					'objects' => array(
						array( 'seq' => 2, 'kind' => 'post', 'op' => 'update', 'staging_ref' => '5', 'live_ref' => '12' ),
						array( 'seq' => 3, 'kind' => 'post', 'op' => 'create', 'staging_ref' => '6', 'live_ref' => null ),
					),
				),
			)
		);
		$steps = array(
			array( 'seq' => 1, 'op' => 'check', 'state' => 'done', 'parts' => null ),
			array( 'seq' => 2, 'op' => 'write', 'state' => 'begun', 'parts' => null ),
			array( 'seq' => 3, 'op' => 'write', 'state' => 'planned', 'parts' => null ),
			array( 'seq' => 4, 'op' => 'post', 'state' => 'planned', 'parts' => null ),
			array( 'seq' => 5, 'op' => 'revert', 'state' => 'planned', 'parts' => array( 'forward_seq' => 2 ) ),
		);
		self::assertSame(
			array(
				array( 'seq' => 3, 'op' => 'write', 'object' => array( 'kind' => 'post', 'op' => 'create', 'staging_ref' => '6', 'live_ref' => null ) ),
				array( 'seq' => 4, 'op' => 'post' ),
				array( 'seq' => 5, 'op' => 'revert', 'takes_back' => 2 ),
			),
			AB_MCP_Transfer::rest( $run, $steps )
		);
	}

	/* -------------------------------------------------------- the view */

	public function testOnASiteWithoutAPairTheSectionIsOneLine(): void {
		$html = AB_MCP_Transfer_View::html( self::view( 'none' ) );
		self::assertSame( 1, self::xpath( $html )->query( '//section[@id="ab-staging-transfer"][@aria-labelledby="ab-staging-transfer-title"]' )->length );
		self::assertSame( array(), self::forms( $html ) );
		self::assertStringContainsString( 'Shown here once this site is the confirmed production site paired with a staging site', self::text( $html ) );
	}

	public function testTheStagingSiteShowsTheWayAndTheStore(): void {
		$html = AB_MCP_Transfer_View::html( self::view( 'staging' ) );
		self::assertStringContainsString( 'The way on.', self::text( $html ) );
		self::assertStringContainsString( 'Beside the WordPress folder, outside the web root', self::text( $html ) );
		self::assertSame( array(), self::forms( $html ) );
	}

	public function testWithoutAConfirmedKeyTheWayToIt(): void {
		$view                                       = self::view( 'production' );
		$view['status']['anchor']['active']         = null;
		$view['status']['next']                     = 'Confirm the key first.';
		$html                                       = AB_MCP_Transfer_View::html( $view );
		self::assertStringContainsString( 'Confirm the key first.', self::text( $html ) );
		self::assertStringNotContainsString( 'Last report', self::text( $html ) );
	}

	public function testTheStoreShowsWhereItIsOrWhyNotAndTheRuleForTheHost(): void {
		$view          = self::view( 'production' );
		$view['store'] = array(
			'available' => true,
			'where'     => 'uploads',
			'checked'   => 1762682400,
			'rule'      => 'The rule.',
			'message'   => null,
		);
		$text          = self::text( AB_MCP_Transfer_View::html( $view ) );
		self::assertStringContainsString( 'In the uploads folder, closed to the web, checked on ' . wp_date( 'Y-m-d H:i', 1762682400 ), $text );
		$view['store']['rule'] = 'location ~ /ab-mcp-store- { deny all; }';
		self::assertStringContainsString( 'location ~ /ab-mcp-store- { deny all; }', self::text( AB_MCP_Transfer_View::html( $view ) ) );
		$view['store'] = array(
			'available' => false,
			'where'     => null,
			'checked'   => 0,
			'rule'      => null,
			'message'   => 'There is no protected store, so nothing is exported, imported or taken over.',
		);
		self::assertStringContainsString( 'There is no protected store', self::text( AB_MCP_Transfer_View::html( $view ) ) );
	}

	public function testTheProofOfTheStoreCarriesTheRuleForTheHostOnlyForTheUploads(): void {
		$proof = new \ReflectionProperty( \AB_MCP_Protected_Store::class, 'proof' );
		$base  = array( 'available' => true, 'checked' => 1762682400, 'reason' => null, 'findings' => array(), 'detail' => array(), 'message' => null );
		try {
			$proof->setValue( null, array( 'where' => 'uploads' ) + $base );
			$store = AB_MCP_Transfer::store();
			self::assertSame( array( true, 'uploads', 1762682400 ), array( $store['available'], $store['where'], $store['checked'] ) );
			self::assertStringContainsString( 'location ~ /ab-mcp-store- { deny all; }', (string) $store['rule'], 'nginx reads neither .htaccess nor web.config.' );
			self::assertStringContainsString( 'outside the web root', (string) $store['rule'], 'And the safe place.' );
			$proof->setValue( null, array( 'where' => 'outside' ) + $base );
			self::assertNull( AB_MCP_Transfer::store()['rule'], 'Outside the web root no rule is needed.' );
			$proof->setValue( null, array( 'available' => false, 'where' => null, 'message' => 'There is no protected store.' ) + $base );
			self::assertSame( array( false, null, 'There is no protected store.' ), array( AB_MCP_Transfer::store()['available'], AB_MCP_Transfer::store()['rule'], AB_MCP_Transfer::store()['message'] ) );
		} finally {
			$proof->setValue( null, null );
		}
	}

	public function testAnInterruptedRunComesFirstWithItsJournalItsRestAndCloseAsChecked(): void {
		$run  = self::a_run( 4, 'interrupted' );
		$view = self::view(
			'production',
			array(
				'open'        => $run,
				'interrupted' => self::run_view( $run ),
				'report'      => array( 'error' => 'x' ),
				'runs'        => array( self::row( 4, 'interrupted' ) ),
			)
		);
		$html = AB_MCP_Transfer_View::html( $view );
		$text = self::text( $html );
		self::assertLessThan( strpos( $text, 'Last report' ), strpos( $text, 'Run 4 is interrupted' ), 'First in the section.' );
		$x = self::xpath( $html );
		self::assertSame( array( '1', 'check', 'done', '', '' ), array_map( static fn( $td ) => trim( $td->textContent ), iterator_to_array( $x->query( '//table[contains(@class, "ab-staging-transfer__journal")]/tbody/tr[1]/td' ) ) ) );
		self::assertSame( array( '2', 'write: post 12', 'begun', 'row written; meta:note planned', '' ), array_map( static fn( $td ) => trim( $td->textContent ), iterator_to_array( $x->query( '//table[contains(@class, "ab-staging-transfer__journal")]/tbody/tr[2]/td' ) ) ), 'Each part with its state.' );
		self::assertStringContainsString( 'Not run: step 3 (write: term 9).', $text, 'The rest, named.' );
		self::assertStringContainsString( 'Left as a rest (made by the run; remove by hand in wp-admin where it should go): post 40.', $text );
		$forms = self::forms( $html );
		unset( $forms[0]['_wp_http_referer'] );
		self::assertSame( array( '_wpnonce' => 'nonce-ab_mcp_transfer_checked_4', 'action' => 'ab_mcp_transfer_checked', 'run_id' => '4', 'state' => str_repeat( 'e', 64 ) ), $forms[0], 'Bound to the interruption the page shows (Codex round 1 of branch 12, A).' );
		self::assertCount( 1, $forms, 'One form: close as checked.' );
		self::assertSame( 'Close as checked', trim( $x->query( '//div[contains(@class, "ab-staging-transfer__interrupted")]//button' )->item( 0 )->textContent ) );
		self::assertStringContainsString( 'Read its journal', self::text( AB_MCP_Transfer_View::interrupted_line( $run ) ) );
		self::assertSame( '', AB_MCP_Transfer_View::interrupted_line( self::a_run( 4, 'open' ) ) );
		self::assertSame( '', AB_MCP_Transfer_View::interrupted_line( null ) );
	}

	public function testWithoutTheKeyTheRunsStayWithTheirJournalAndCloseAsChecked(): void {
		// Codex round 1 of branch 12, B: the anchor gone, or the pair changed
		// while a run is interrupted.
		$run = self::a_run( 4, 'interrupted' );
		foreach ( array( 'production', 'none' ) as $side ) {
			$view = self::view(
				$side,
				array(
					'open'        => $run,
					'interrupted' => self::run_view( $run ),
					'runs'        => array( self::row( 4, 'interrupted' ), self::row( 3, 'open', array( 'abort' => true ) ) ),
				)
			);
			if ( 'production' === $side ) {
				$view['status']['anchor']['active'] = null;
			}
			$html    = AB_MCP_Transfer_View::html( $view );
			$actions = array_column( self::forms( $html ), 'action' );
			self::assertSame( array( 'ab_mcp_transfer_checked', 'ab_mcp_transfer_abort' ), $actions, $side . ': close as checked and abort, without the key.' );
			self::assertStringContainsString( 'Run 4 is interrupted', self::text( $html ) );
			self::assertStringContainsString( 'The report, the object pairs and taking a pair back need the confirmed key of the staging site', self::text( $html ), $side . ': why the rest is not shown.' );
			self::assertStringNotContainsString( 'Last report', self::text( $html ) );
		}
		$none = AB_MCP_Transfer_View::html( self::view( 'none' ) );
		self::assertStringNotContainsString( 'need the confirmed key', self::text( $none ), 'Without a run: one line, as before.' );
	}

	public function testTheRunsToActOnStandAboveThePageOfTheListWithTheWayToOlderRuns(): void {
		// Codex round 1 of branch 12, B: ten newer runs of the way back pushed
		// the run that wrote off the list.
		$view = self::view(
			'production',
			array(
				'runs'      => array( self::row( 14, 'closed_reverted', array( 'type' => 'revert' ) ) ),
				'pinned'    => array( self::row( 2, 'closed_done', array( 'backups' => array( 'state' => 'last_three', 'until' => 0 ) ) ) ),
				'runs_next' => 14,
			)
		);
		$html = AB_MCP_Transfer_View::html( $view );
		$x    = self::xpath( $html );
		self::assertSame( 1, $x->query( '//table[contains(@class, "ab-staging-transfer__pinned")]/tbody/tr' )->length );
		self::assertStringStartsWith( '2, takeover', trim( $x->query( '//table[contains(@class, "ab-staging-transfer__pinned")]/tbody/tr/td' )->item( 0 )->textContent ) );
		self::assertLessThan( strpos( $html, 'ab-staging-transfer__list' ), strpos( $html, 'ab-staging-transfer__pinned' ) );
		$older = $x->query( '//div[contains(@class, "ab-staging-transfer__runs")]//p/a' );
		self::assertSame( 'Older runs', $older->item( 0 )->textContent );
		self::assertStringEndsWith( 'admin.php?page=alphabridge-staging&runs_after=14#ab-staging-transfer', $older->item( 0 )->getAttribute( 'href' ) );
		$view['runs_next'] = null;
		self::assertStringNotContainsString( 'Older runs', AB_MCP_Transfer_View::html( $view ) );
	}

	public function testTheJournalShowsTheOutcomeOfAStepOfTheWayBackAsTheToolReportsIt(): void {
		// Codex round 1 of branch 12, B: the outcome stands in step.result.
		$run  = self::a_run( 8, 'closed_findings', array( 'type' => 'revert' ) );
		$view = self::run_view(
			$run,
			array(
				'shown'   => array(
					'steps'   => array(
						array(
							'seq'    => 1,
							'op'     => 'revert',
							'state'  => 'unclear',
							'parts'  => array( 'object' => null, 'backup' => null, 'parts' => array() ),
							'result' => array(
								'message'  => 'The post 12 went back only in part.',
								'live_ref' => '12',
								'parts'    => array(
									array( 'part' => 'row', 'state' => 'left', 'note' => 'changed here since the run wrote it', 'value_before' => 'post_content: 12 bytes <b>x</b>' ),
								),
							),
						),
					),
					'rests'   => array(),
					'unclear' => array( 1 ),
				),
				'rest'    => array(),
				'back_of' => array( 1 => array( 'run' => 7, 'seq' => 2, 'kind' => 'post', 'live_ref' => '12' ) ),
			)
		);
		$html = AB_MCP_Transfer_View::html( self::view( 'production', array( 'run' => $view ) ) );
		$x    = self::xpath( $html );
		$tds  = array_map( static fn( $td ) => trim( $td->textContent ), iterator_to_array( $x->query( '//table[contains(@class, "ab-staging-transfer__journal")]/tbody/tr[1]/td' ) ) );
		self::assertSame( array( '1', 'revert of step 2 of run 7: post 12', 'unclear', 'row left: changed here since the run wrote it (before: post_content: 12 bytes <b>x</b>)', 'The post 12 went back only in part.' ), $tds, 'Object, state of the part, why, the value before, the finding.' );
		self::assertStringNotContainsString( '<b>x</b>', $html, 'Escaped.' );
	}

	public function testEveryFormPostsToItsActionWithANonceBoundToItsObjectAndTheStateThePageShowed(): void {
		$closed = self::a_run( 7, 'closed_done', array( 'forward_closed_at' => 1762681380 ) );
		$view   = self::view(
			'production',
			array(
				'run'    => self::run_view(
					$closed,
					array(
						'pairs' => array(
							array( 'seq' => 2, 'kind' => 'post', 'op' => 'update', 'live_ref' => '12', 'will' => 'back', 'plan_state' => str_repeat( 'b', 64 ) ),
							array( 'seq' => 3, 'kind' => 'post', 'op' => 'create', 'live_ref' => '40', 'will' => 'rest', 'plan_state' => str_repeat( 'c', 64 ) ),
						),
						'm2'    => 'Run 7: 5 steps, 6 calls since the run was made, duration 2 minutes.',
					)
				),
				'runs'   => array(
					self::row( 8, 'open', array( 'abort' => true ) ),
					self::row( 9, 'open', array( 'type' => 'revert', 'go_on' => true ) ),
					self::row( 7, 'closed_done', array( 'm2' => 'Run 7: 5 steps, 6 calls since the run was made, duration 2 minutes.', 'backups' => array( 'state' => 'last_three', 'until' => 0 ) ) ),
				),
				'pairs'  => array(
					'source' => 's',
					'pairs'  => array(
						array( 'id' => 21, 'kind' => 'post', 'live_ref' => '12', 'staging_ref' => '5', 'state' => 'active', 'last_run' => 7, 'discard' => str_repeat( 'd', 64 ) ),
					),
					'next'   => 21,
					'after'  => 0,
				),
				'report' => array( 'report_id' => 'r', 'created' => 1762680000, 'expires' => 1762766400, 'done' => true, 'total' => 40, 'actions' => array( 'take_over' => 9, 'create' => 3, 'choose' => 1, 'waits' => 2, 'locked' => 4 ), 'takeable' => 12, 'blocked' => 1, 'm1' => '12 changes ready.' ),
			)
		);
		$html  = AB_MCP_Transfer_View::html( $view );
		$forms = array();
		foreach ( self::forms( $html ) as $fields ) {
			unset( $fields['_wp_http_referer'] );
			$forms[] = $fields;
		}
		self::assertSame(
			array(
				array( '_wpnonce' => 'nonce-ab_mcp_transfer_back_7_2', 'action' => 'ab_mcp_transfer_back', 'run_id' => '7', 'seq' => '2', 'state' => str_repeat( 'b', 64 ) ),
				array( '_wpnonce' => 'nonce-ab_mcp_transfer_back_7_3', 'action' => 'ab_mcp_transfer_back', 'run_id' => '7', 'seq' => '3', 'state' => str_repeat( 'c', 64 ) ),
				array( '_wpnonce' => 'nonce-ab_mcp_transfer_abort_8', 'action' => 'ab_mcp_transfer_abort', 'run_id' => '8' ),
				array( '_wpnonce' => 'nonce-ab_mcp_transfer_go_on_9', 'action' => 'ab_mcp_transfer_go_on', 'run_id' => '9' ),
				array( '_wpnonce' => 'nonce-ab_mcp_transfer_discard_21', 'action' => 'ab_mcp_transfer_discard', 'pair_id' => '21', 'state' => str_repeat( 'd', 64 ) ),
			),
			$forms,
			'The way back per pair, abort, go on, discard; no form of another object.'
		);
		$text = self::text( $html );
		self::assertStringContainsString( 'goes back', $text );
		self::assertStringContainsString( 'made by the run: stays as a rest', $text );
		self::assertSame( 2, substr_count( $text, 'Run 7: 5 steps, 6 calls since the run was made, duration 2 minutes.' ), 'M2 in the list and with the steps of the run.' );
		self::assertStringContainsString( '12 changes ready.', $text, 'M1 as a quiet line.' );
		self::assertStringContainsString( '12 can be taken over (9 changed on the staging site, 3 only there), 1 wait for a choice, 2 wait for another object, 4 are locked.', $text );
		self::assertStringContainsString( 'kept: one of the last three completed takeovers', $text );
		self::assertDoesNotMatchRegularExpression( self::EDITION, $html, 'Without a notice nothing names an edition.' );
		$x = self::xpath( $html );
		self::assertStringEndsWith( 'admin.php?page=alphabridge-staging&run=7#ab-staging-transfer', $x->query( '//table[contains(@class, "ab-staging-transfer__list")]//a' )->item( 2 )->getAttribute( 'href' ) );
		self::assertStringEndsWith( 'admin.php?page=alphabridge-staging&pairs_after=21#ab-staging-transfer', $x->query( '//div[contains(@class, "ab-staging-transfer__pairs")]//p/a' )->item( 0 )->getAttribute( 'href' ) );
		self::assertSame( 0, $x->query( '//*[@disabled]' )->length, 'No grey switch.' );
	}

	public function testANoticeCarriesBothPartsTheLinksAndTheSwitchOnce(): void {
		$notes = array( 'possible' => true, 'hidden' => false );
		$view  = self::view(
			'production',
			array(
				'report' => array( 'report_id' => 'r', 'created' => 1, 'expires' => 2, 'done' => true, 'total' => 40, 'actions' => array( 'take_over' => 12 ), 'takeable' => 12, 'blocked' => 0, 'm1' => '12 changes ready.' ),
				'notice' => array(
					'moment'  => 'm1',
					'text'    => '12 changes ready.',
					'edition' => Moments::edition( 'm1', 12 ),
				),
				'notes'  => $notes,
			)
		);
		$html = AB_MCP_Transfer_View::html( $view );
		$x    = self::xpath( $html );
		$box  = $x->query( '//div[@data-moment="m1"]' );
		self::assertSame( 1, $box->length );
		$parts = array_map( static fn( $p ) => trim( $p->textContent ), iterator_to_array( $x->query( '//div[@data-moment="m1"]/p' ) ) );
		self::assertSame( '12 changes ready.', $parts[0], 'The operating part first.' );
		self::assertStringStartsWith( 'Free carries the takeover over step by step', $parts[1], 'Then the edition part.' );
		self::assertSame( 1, substr_count( self::text( $html ), '12 changes ready.' ), 'Not a quiet line beside it.' );
		self::assertSame( array( 'Pro monthly, 9 USD', 'Try it for 7 days' ), array_map( static fn( $a ) => $a->textContent, iterator_to_array( $x->query( '//div[@data-moment="m1"]//a' ) ) ), 'Lines with a link.' );
		self::assertSame( 1, $x->query( '//form//input[@name="action"][@value="ab_mcp_transfer_notes"]' )->length, 'The switch once, in the notice.' );
		self::assertSame( '1', $x->query( '//form//input[@name="hidden"]' )->item( 0 )->getAttribute( 'value' ) );

		$view['notice'] = null;
		$html           = AB_MCP_Transfer_View::html( $view );
		self::assertSame( 1, self::xpath( $html )->query( '//form//input[@name="action"][@value="ab_mcp_transfer_notes"]' )->length, 'Without a notice, at the end of the section.' );
		$view['notes']['possible'] = false;
		self::assertSame( 0, self::xpath( AB_MCP_Transfer_View::html( $view ) )->query( '//form' )->length, 'Not offered where no note can show.' );
	}

	public function testTheSectionStandsOnThePageStagingBelowTheCard(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		ob_start();
		( new AB_MCP_Admin() )->render_staging();
		$html = (string) ob_get_clean();
		self::assertLessThan( strpos( $html, 'id="ab-staging-transfer"' ), strpos( $html, 'id="ab-staging-card"' ) );
		self::assertSame( 1, substr_count( $html, 'id="ab-staging-transfer"' ) );
	}

	/* --------------------------------------------------------- handlers */

	/** @return array<string,array{0:string,1:string,2:array}> */
	public static function handlers(): array {
		return array(
			'abort'          => array( 'handle_transfer_abort', 'ab_mcp_transfer_abort_8', array( 'run_id' => '8' ) ),
			'close checked'  => array( 'handle_transfer_checked', 'ab_mcp_transfer_checked_4', array( 'run_id' => '4' ) ),
			'take back'      => array( 'handle_transfer_back', 'ab_mcp_transfer_back_7_2', array( 'run_id' => '7', 'seq' => '2', 'state' => 'x' ) ),
			'go on'          => array( 'handle_transfer_go_on', 'ab_mcp_transfer_go_on_9', array( 'run_id' => '9' ) ),
			'discard'        => array( 'handle_transfer_discard', 'ab_mcp_transfer_discard_21', array( 'pair_id' => '21', 'state' => 'x' ) ),
			'edition notes'  => array( 'handle_transfer_notes', 'ab_mcp_transfer_notes', array( 'hidden' => '1' ) ),
		);
	}

	/** Send a form to a handler; how it ended. */
	private function submit( string $handler, array $post, string $nonce ): AbTestExit {
		$_POST    = array_merge( $post, array( '_wpnonce' => $nonce ) );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect.' );
	}

	#[DataProvider( 'handlers' )]
	public function testEveryHandlerNeedsManageOptionsItsNonceAndAPost( string $handler, string $action, array $post ): void {
		$outcome = AB_MCP_Transfer::OUTCOME . '3';
		$before  = serialize( $GLOBALS['ab_test_options'] );

		$exit = $this->submit( $handler, $post, 'nonce-ab_mcp_save' );
		self::assertSame( 'die', $exit->kind, 'Another form\'s nonce.' );
		$other = $post;
		foreach ( array( 'run_id', 'pair_id', 'seq' ) as $field ) {
			if ( isset( $other[ $field ] ) ) {
				$other[ $field ] = (string) ( (int) $other[ $field ] + 1 );
				$exit            = $this->submit( $handler, $other, 'nonce-' . $action );
				self::assertSame( 'die', $exit->kind, 'The nonce of another object (' . $field . ').' );
				$other = $post;
			}
		}

		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		$exit                   = $this->submit( $handler, $post, 'nonce-' . $action );
		self::assertSame( 'die', $exit->kind );
		self::assertSame( 'Insufficient permissions.', $exit->detail );

		$GLOBALS['ab_test_can']    = static fn(): bool => true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$exit                      = $this->submit( $handler, $post, 'nonce-' . $action );
		self::assertSame( 'die', $exit->kind, 'A GET with the nonce in its address.' );
		self::assertStringContainsString( 'work only from their buttons', $exit->detail );
		self::assertFalse( get_transient( $outcome ), 'Nothing was done.' );
		self::assertSame( $before, serialize( $GLOBALS['ab_test_options'] ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$exit                      = $this->submit( $handler, $post, 'nonce-' . $action );
		self::assertSame( 'redirect', $exit->kind );
		self::assertMatchesRegularExpression( '~^https://example\.test/wp-admin/admin\.php\?page=alphabridge-staging&ab_notice=transfer(&run=\d+)?#ab-staging-transfer$~', $exit->detail );
		self::assertIsArray( get_transient( $outcome ), 'The outcome is kept for the page.' );
	}

	public function testTheHandlersAreHookedForAdminPostOnly(): void {
		$admin = new AB_MCP_Admin();
		foreach ( self::handlers() as $case ) {
			$name = preg_replace( '/_\d+(_\d+)?$/', '', $case[1] );
			self::assertSame( 10, has_action( 'admin_post_' . $name, array( $admin, $case[0] ) ), $name );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $name, array( $admin, $case[0] ) ) );
		}
	}

	public function testTheSwitchHidesAndShowsTheEditionNotesAndThePageShowsTheOutcomeOnce(): void {
		$this->submit( 'handle_transfer_notes', array( 'hidden' => '1' ), 'nonce-ab_mcp_transfer_notes' );
		self::assertTrue( Moments::hidden() );
		$this->submit( 'handle_transfer_notes', array( 'hidden' => '0' ), 'nonce-ab_mcp_transfer_notes' );
		self::assertFalse( Moments::hidden() );
		$view = AB_MCP_Transfer::view();
		self::assertSame( array( 'ok' => true, 'text' => 'The edition notes show again on this site.' ), $view['outcome'] );
		self::assertNull( AB_MCP_Transfer::view()['outcome'], 'Once.' );
	}

	public function testAFailedActionKeepsItsReasonForThePage(): void {
		$this->submit( 'handle_transfer_abort', array( 'run_id' => '8' ), 'nonce-ab_mcp_transfer_abort_8' );
		$kept = get_transient( AB_MCP_Transfer::OUTCOME . '3' );
		self::assertFalse( $kept['ok'] );
		self::assertStringContainsString( 'tables of the takeover are not available', $kept['text'], 'The refusal of the run, with its way.' );
		$html = AB_MCP_Transfer_View::html( self::view( 'none', array( 'outcome' => array( 'ok' => false, 'text' => $kept['text'] ) ) ) );
		self::assertSame( 1, self::xpath( $html )->query( '//div[contains(@class, "notice-error")][contains(@class, "ab-staging-transfer__outcome")]' )->length );
	}
}
