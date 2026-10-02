<?php
/**
 * wp_get_widgets leaves credentials of other plugins' widgets out.
 *
 * The tool runs in the mode Read and on a read token. Widget settings are
 * the option widget_<base>, written by whatever plugin brought the widget,
 * and some keep API keys and tokens there. What must hold:
 *
 * - A value whose key the meta guard (is_sensitive_meta_key()) takes for a
 *   credential is not in the answer, at any depth; everything else is, as
 *   stored.
 * - The answer names what it left out (hidden_keys per widget, as paths) and
 *   says where the values are managed; an answer without such a key has
 *   neither.
 * - Nothing is written: the stored settings stay as they are.
 *
 * The values below are made-up placeholders, not credentials.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Widgets;

require_once dirname( __DIR__ ) . '/includes/tools/class-tools-base.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-widgets.php';

final class WidgetCredentialsTest extends TestCase {

	private const FEED = array(
		'title'               => 'Tweets',
		'count'               => 5,
		'consumer_key'        => 'PLACEHOLDER-CK',
		'consumer_secret'     => 'PLACEHOLDER-CS',
		'access_token'        => 'PLACEHOLDER-AT',
		'access_token_secret' => 'PLACEHOLDER-ATS',
		'feed'                => array(
			'url'         => 'https://example.org/feed',
			'api_key'     => 'PLACEHOLDER-NESTED',
			'token_count' => 3,
		),
	);

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['wp_registered_widgets'] = array(
			'feedwidget-2' => array( 'name' => 'Feed' ),
			'text-3'       => array( 'name' => 'Text' ),
		);
		update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'feedwidget-2', 'text-3' ), 'array_version' => 3 ) );
		update_option( 'widget_feedwidget', array( 2 => self::FEED, '_multiwidget' => 1 ) );
		update_option( 'widget_text', array( 3 => array( 'title' => 'Hello', 'text' => 'World' ), '_multiwidget' => 1 ) );
		$GLOBALS['ab_test_writes'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_registered_widgets'] );
	}

	/** @return array<string,array> id => entry */
	private static function by_id( array $res ): array {
		$out = array();
		foreach ( $res['widgets'] as $w ) {
			$out[ $w['id'] ] = $w;
		}
		return $out;
	}

	public function testTheReaderRunsInRead(): void {
		// Why the filter matters: nobody confirmed anything for this tool.
		self::assertTrue( AB_MCP_Site_Mode::runs_in_read( 'wp_get_widgets', array() ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_read_only( 'wp_get_widgets', array() ) );
	}

	public function testCredentialsAreLeftOutAtEveryDepthAndTheRestStays(): void {
		$res  = AB_MCP_Tools_Widgets::get_widgets( array() );
		$feed = self::by_id( $res )['feedwidget-2'];

		self::assertSame(
			array(
				'title' => 'Tweets',
				'count' => 5,
				'feed'  => array(
					'url'         => 'https://example.org/feed',
					'token_count' => 3,
				),
			),
			$feed['settings']
		);
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $res ) );
		self::assertSame( array( 'consumer_key', 'consumer_secret', 'access_token', 'access_token_secret', 'feed/api_key' ), $feed['hidden_keys'] );
	}

	public function testTheAnswerSaysWhatItLeftOutAndWhereItIsManaged(): void {
		$res = AB_MCP_Tools_Widgets::get_widgets( array() );
		self::assertStringContainsString( 'hidden_keys', $res['note'] );
		self::assertStringContainsString( 'Appearance › Widgets', $res['note'] );
		self::assertArrayNotHasKey( 'hidden_keys', self::by_id( $res )['text-3'], 'A widget without such a key names nothing.' );
		self::assertSame( array( 'title' => 'Hello', 'text' => 'World' ), self::by_id( $res )['text-3']['settings'] );
	}

	public function testWithoutAnyCredentialTheAnswerHasNoNote(): void {
		$res = AB_MCP_Tools_Widgets::get_widgets( array( 'sidebar' => 'sidebar-1' ) );
		self::assertArrayHasKey( 'note', $res );
		update_option( 'widget_feedwidget', array( 2 => array( 'title' => 'Tweets' ), '_multiwidget' => 1 ) );
		$res = AB_MCP_Tools_Widgets::get_widgets( array() );
		self::assertArrayNotHasKey( 'note', $res );
		self::assertArrayNotHasKey( 'hidden_keys', self::by_id( $res )['feedwidget-2'] );
	}

	public function testNothingIsWritten(): void {
		AB_MCP_Tools_Widgets::get_widgets( array() );
		self::assertSame( array(), $GLOBALS['ab_test_writes'] );
		self::assertSame( self::FEED, get_option( 'widget_feedwidget' )[2] );
	}
}
