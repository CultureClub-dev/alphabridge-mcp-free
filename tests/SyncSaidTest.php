<?php
/**
 * The English texts of the report and of the transport said to a person in
 * the language of the site (AB_MCP_Sync_Said; branch 13 text/z8, Codex
 * round 2, B1): every reason of the tables, those of the environment
 * among them, is known, a known text and a form with its values are given
 * translated, an unknown text as it is, and a kind reads as the page
 * «Staging» names it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Env;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Option_Schema;
use AB_MCP_Sync_Said;
use AB_MCP_Tools_Staging;
use AB_MCP_Transfer_View;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/../includes/sync/class-sync-kinds.php';
require_once __DIR__ . '/../includes/sync/class-sync-option-schema.php';
require_once __DIR__ . '/../includes/sync/class-sync-said.php';
require_once __DIR__ . '/../includes/class-transfer-view.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class SyncSaidTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_translations'] );
	}

	public function testEveryReasonOfTheTablesIsKnown(): void {
		$reasons = array();
		foreach ( array( AB_MCP_Sync_Kinds::OWNERSHIP, AB_MCP_Sync_Kinds::LOCKS ) as $table ) {
			foreach ( $table as $key => $entry ) {
				if ( isset( $entry['reason'] ) ) {
					$reasons[ $key ] = $entry['reason'];
				}
			}
		}
		$excluded           = new \ReflectionMethod( AB_MCP_Sync_Kinds::class, 'excluded' );
		$reasons['group B'] = $excluded->invoke( null, 'option:cron' )['reason'];
		foreach ( AB_MCP_Sync_Option_Schema::REASON_TEXTS as $code => $text ) {
			$reasons[ 'option schema ' . $code ] = sprintf( $text, 'siteurl' );
		}
		self::assertGreaterThan( 90, count( $reasons ) );
		self::assertCount( 9, AB_MCP_Sync_Env::LOCKS );
		foreach ( AB_MCP_Sync_Env::LOCKS as $key => $lock ) {
			$reasons[ 'env ' . $key ] = $lock['reason'];
		}
		foreach ( $reasons as $key => $reason ) {
			self::assertTrue( AB_MCP_Sync_Said::knows( $reason ), $key . ': ' . $reason );
		}
	}

	public function testAKnownTextIsSaidInTheLanguageOfTheSite(): void {
		$reason                          = AB_MCP_Sync_Kinds::LOCKS['option:create']['reason'];
		$GLOBALS['ab_test_translations'] = array( $reason => 'Die Kernoption fehlt auf dieser Website.' );
		self::assertSame( 'Die Kernoption fehlt auf dieser Website.', AB_MCP_Sync_Said::text( $reason ) );
	}

	public function testAFormIsSaidWithItsValuesInTheirPlaces(): void {
		$GLOBALS['ab_test_translations'] = array(
			'Its target, the %1$s of the staging site, has an unclear pair (pair %2$d): a person confirms which side wins first.' => 'Paar %2$d ist unklar, Ziel %1$s.',
			'WordPress reads the row %s under this name, so this row is never read and is not taken over.'                       => 'WordPress liest die Zeile %s.',
		);
		self::assertSame( 'Paar 7 ist unklar, Ziel post 12.', AB_MCP_Sync_Said::text( 'Its target, the post 12 of the staging site, has an unclear pair (pair 7): a person confirms which side wins first.' ) );
		self::assertSame( 'WordPress liest die Zeile home.', AB_MCP_Sync_Said::text( 'WordPress reads the row home under this name, so this row is never read and is not taken over.' ) );
		$twice = 'It needs the term 5 of the staging site, which is created in the same run but is held back itself (locked, or waiting for another).';
		self::assertTrue( AB_MCP_Sync_Said::knows( $twice ) );
		self::assertSame( $twice, AB_MCP_Sync_Said::text( $twice ), 'Without a translation the English text, with its values.' );
	}

	public function testAnUnknownTextIsGivenAsItIs(): void {
		$GLOBALS['ab_test_translations'] = array( 'Not carried to its end in this version.' => 'Nicht zu Ende geführt.' );
		foreach ( array( 'cURL error 28: Operation timed out after 10001 milliseconds.', '', 'Not carried to its end in this version. More.' ) as $text ) {
			self::assertFalse( AB_MCP_Sync_Said::knows( $text ), $text );
			self::assertSame( $text, AB_MCP_Sync_Said::text( $text ) );
		}
		self::assertSame( 'Nicht zu Ende geführt.', AB_MCP_Sync_Said::text( 'Not carried to its end in this version.' ) );
	}

	/**
	 * Codex round 4 on branch 13 (B): the reasons in the answers of
	 * wp_site_diff (blocked, entries, environment) and wp_site_pairs
	 * action=propose (entries) reach the person; the copy in the answer says
	 * them in the language of the site, the stored value stays as it is.
	 */
	public function testAnAnswerSaysItsReasonsAndKeepsEverythingElse(): void {
		$plugin                          = AB_MCP_Sync_Env::LOCKS['plugin']['reason'];
		$GLOBALS['ab_test_translations'] = array(
			$plugin                                   => 'Ein Plugin ist Code.',
			'Not carried to its end in this version.' => 'Nicht zu Ende geführt.',
		);
		$stored = array(
			'blocked' => array(
				array(
					'key'    => 'env:plugin',
					'reason' => $plugin,
					'stage'  => 'Z8b',
					'count'  => 2,
				),
			),
			'entries' => array(
				array(
					'n'         => 1,
					'reason'    => 'Not carried to its end in this version.',
					'waits_for' => array( array( 'why' => 'Not carried to its end in this version.', 'ref' => 'post 4' ) ),
					'locks'     => array( array( 'reason' => 'cURL error 28', 'stage' => 'Z8d' ) ),
					'result'    => 'Not carried to its end in this version.',
					'reasons'   => array( 'reason' => 7 ),
				),
			),
		);
		$copy   = $stored;
		$shown  = AB_MCP_Sync_Said::shown( $copy );
		self::assertSame( $stored, $copy, 'The value given in is not changed.' );
		self::assertSame( 'Ein Plugin ist Code.', $shown['blocked'][0]['reason'] );
		self::assertSame( array( 'env:plugin', 'Z8b', 2 ), array( $shown['blocked'][0]['key'], $shown['blocked'][0]['stage'], $shown['blocked'][0]['count'] ) );
		self::assertSame( 'Nicht zu Ende geführt.', $shown['entries'][0]['reason'] );
		self::assertSame( 'Nicht zu Ende geführt.', $shown['entries'][0]['waits_for'][0]['why'], 'Nested, a why too.' );
		self::assertSame( 'cURL error 28', $shown['entries'][0]['locks'][0]['reason'], 'An unknown reason as it is.' );
		self::assertSame( 'Not carried to its end in this version.', $shown['entries'][0]['result'], 'Only reason and why.' );
		self::assertSame( array( 'reason' => 7 ), $shown['entries'][0]['reasons'], 'A reason that is no text stays.' );
		self::assertSame( 1, $shown['entries'][0]['n'] );
		self::assertNull( AB_MCP_Sync_Said::shown( null ) );
		self::assertSame( 'x', AB_MCP_Sync_Said::shown( 'x' ) );
	}

	/**
	 * Codex round 5 on branch 13 (B): words and note reach the person as
	 * reason and why do, also under staging, live, incomplete and lock; a
	 * code under such a key stays as it is.
	 */
	public function testAnAnswerSaysEveryKeyOfProseAtAnyDepth(): void {
		$plugin                          = AB_MCP_Sync_Env::LOCKS['plugin']['reason'];
		$GLOBALS['ab_test_translations'] = array( $plugin => 'Ein Plugin ist Code.' );
		self::assertSame( array( 'reason', 'why', 'words', 'note' ), AB_MCP_Sync_Said::PROSE );
		$stored = array(
			'staging' => array( 'incomplete' => array( array( 'kind' => 'post', 'lock' => array( 'reason' => $plugin, 'key' => 'env:plugin' ) ) ) ),
			'live'    => array( 'note' => $plugin ),
			'entries' => array(
				array(
					'words'  => $plugin,
					'note'   => $plugin,
					'why'    => 'by_code',
					'action' => $plugin,
				),
			),
		);
		$shown  = AB_MCP_Sync_Said::shown( $stored );
		self::assertSame( 'Ein Plugin ist Code.', $shown['staging']['incomplete'][0]['lock']['reason'] );
		self::assertSame( 'env:plugin', $shown['staging']['incomplete'][0]['lock']['key'] );
		self::assertSame( 'Ein Plugin ist Code.', $shown['live']['note'] );
		self::assertSame( 'Ein Plugin ist Code.', $shown['entries'][0]['words'] );
		self::assertSame( 'Ein Plugin ist Code.', $shown['entries'][0]['note'] );
		self::assertSame( 'by_code', $shown['entries'][0]['why'], 'A code under a key of prose stays.' );
		self::assertSame( $plugin, $shown['entries'][0]['action'], 'Only the keys of prose.' );
	}

	/**
	 * Sentences the list knows, one after another and separated by a space,
	 * are said each in turn; one sentence it does not know leaves the whole
	 * text as it is.
	 */
	public function testKnownSentencesOneAfterAnotherAreSaidEachInTurn(): void {
		$plugin                          = AB_MCP_Sync_Env::LOCKS['plugin']['reason'];
		$theme                           = AB_MCP_Sync_Env::LOCKS['theme']['reason'];
		$GLOBALS['ab_test_translations'] = array(
			$plugin => 'Ein Plugin ist Code.',
			$theme  => 'Ein Theme ist Code.',
		);
		self::assertFalse( AB_MCP_Sync_Said::knows( $plugin . $theme ) );
		self::assertTrue( AB_MCP_Sync_Said::knows( $plugin . ' ' . $theme ) );
		self::assertSame( 'Ein Plugin ist Code. Ein Theme ist Code.', AB_MCP_Sync_Said::text( $plugin . ' ' . $theme ) );
		self::assertSame( 'Ein Theme ist Code. Ein Plugin ist Code. Ein Theme ist Code.', AB_MCP_Sync_Said::text( $theme . ' ' . $plugin . ' ' . $theme ) );
		$unknown = $plugin . ' Something else. ' . $theme;
		self::assertFalse( AB_MCP_Sync_Said::knows( $unknown ) );
		self::assertSame( $unknown, AB_MCP_Sync_Said::text( $unknown ) );
	}

	/**
	 * The answers of wp_site_pairs, wp_site_diff, wp_site_push and
	 * wp_site_revert and their previews go out through said(), the whole of
	 * each; the state a token is bound to and the export do not (the state
	 * is hashed, the manifest and cursor of the export are passed back
	 * unchanged).
	 */
	public function testTheStagingToolsSayTheirWholeAnswer(): void {
		$source = (string) file_get_contents( __DIR__ . '/../includes/tools/class-tools-staging.php' );
		$body   = static function ( string $name ) use ( $source ): string {
			self::assertSame( 1, preg_match( '/function ' . $name . '\( array \$a \) \{(.*?)\n\t\}/s', $source, $m ), $name );
			return $m[1];
		};
		foreach ( array( 'pairs_preview', 'site_pairs', 'site_diff', 'push_preview', 'site_push', 'revert_preview', 'site_revert' ) as $name ) {
			self::assertMatchesRegularExpression( '/return self::said\( AB_MCP_Sync_[A-Za-z_]+::(call|preview)\( \$a \) \);/', $body( $name ), $name );
		}
		foreach ( array( 'pairs_state', 'push_state', 'revert_state', 'site_export', 'site_export_chunk', 'site_import_chunk' ) as $name ) {
			self::assertStringNotContainsString( 'said(', $body( $name ), $name );
		}
		$plugin                          = AB_MCP_Sync_Env::LOCKS['plugin']['reason'];
		$GLOBALS['ab_test_translations'] = array( $plugin => 'Ein Plugin ist Code.' );
		$said                            = new \ReflectionMethod( AB_MCP_Tools_Staging::class, 'said' );
		self::assertSame( array( 'blocked' => array( array( 'reason' => 'Ein Plugin ist Code.' ) ) ), $said->invoke( null, array( 'blocked' => array( array( 'reason' => $plugin ) ) ) ) );
		$error = new \WP_Error( 'x', $plugin );
		self::assertSame( $error, $said->invoke( null, $error ), 'An error as it is.' );
	}

	public function testAKindReadsAsThePageStagingNamesIt(): void {
		foreach ( array( 'post', 'term', 'attachment', 'menu', 'menu_item', 'widget_area', 'custom_css', 'option', 'other' ) as $kind ) {
			self::assertSame( AB_MCP_Transfer_View::kind( $kind ), AB_MCP_Sync_Said::kind( $kind ), $kind );
		}
		self::assertSame( 'widget area', AB_MCP_Sync_Said::kind( 'widget_area' ) );
	}
}
