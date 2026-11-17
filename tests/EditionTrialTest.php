<?php
/**
 * The fourth edition value, 'trial': a trial of AlphaBridge MCP Pro without
 * an active valid licence, as the Pro add-on reports it through
 * ab_mcp_site_edition.
 *
 * Each place of this plugin that reads the edition, one test per decision:
 * the self-description lets 'trial' through (L); the box about Pro is not
 * shown (H1); the agreement to the Terms of Use stays required, as for the
 * free edition, since a trial bought nothing (H2, the one place that
 * decides a release, unchanged); the box «On: full power» names what Pro
 * writes (H3); the refusals of the option guard name the tools of Pro, not
 * those of the Agency plan (H4); the edition part of the moments of the
 * takeover stays silent (H5). A value that is not a string reads as the
 * free edition at every place that checks it (a string that says 'pro'
 * by __toString() included: it is no string).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Option_Guard;
use AB_MCP_REST_Controller;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Transfer_Moments as Moments;
use AbTestExit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

require_once __DIR__ . '/undo-stubs.php';
require_once __DIR__ . '/../includes/class-option-guard.php';
require_once __DIR__ . '/../includes/undo/class-undo.php';
require_once __DIR__ . '/../includes/class-transfer-moments.php';

final class EditionTrialTest extends TestCase {

	/** The sentence of the box «On: full power» with Pro. */
	private const WRITES_PRO = 'AI assistants read and write: posts, pages, plugins, themes, code and the database; the registration settings and the site address only with a confirmation from the preview and with an undo point.';

	/** The sentence of the box «On: full power» of the free edition. */
	private const WRITES_FREE = 'AI assistants read and write: posts, pages, media, terms, comments, menus, widgets, users, site settings, the shop and SEO fields.';

	protected function setUp(): void {
		ab_test_reset();
		Moments::$published = null;
	}

	protected function tearDown(): void {
		Moments::$published = null;
		$_POST              = array();
		$_REQUEST           = array();
	}

	/* ------------------------------------------------------------ helpers */

	/** The add-on reports $edition, whatever its type. */
	private static function edition( $edition ): void {
		add_filter( 'ab_mcp_site_edition', static fn() => $edition );
	}

	/** Not a string, though PHP would turn it into 'pro' by a cast. */
	private static function stringable( string $says ): object {
		return new class( $says ) {
			/** @var string */
			private $says;

			public function __construct( string $says ) {
				$this->says = $says;
			}

			public function __toString(): string {
				return $this->says;
			}
		};
	}

	/** @param mixed ...$args */
	private static function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	/** @return array<string,mixed> */
	private static function meta(): array {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'site_meta' );
		return $m->invoke( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) );
	}

	private static function writes(): string {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . self::admin( 'mode_card_html' ), LIBXML_NOERROR | LIBXML_NOWARNING );
		$node = ( new DOMXPath( $doc ) )->query( '//*[contains(@class, "ab-mode__writes")]' )->item( 0 );
		self::assertNotNull( $node );
		return trim( $node->textContent );
	}

	private static function refusal( string $name ): string {
		$answer = AB_MCP_Option_Guard::option_write_refused( $name );
		self::assertInstanceOf( WP_Error::class, $answer );
		self::assertSame( 'ab_mcp_blocked', $answer->get_error_code() );
		return $answer->get_error_message();
	}

	private static function edition_has( string $tool ): bool {
		$m = new ReflectionMethod( AB_MCP_Option_Guard::class, 'edition_has' );
		return (bool) $m->invoke( null, $tool );
	}

	/** Post to an admin-post handler as user 3; returns how the request ended. */
	private static function post( string $handler, array $post ): AbTestExit {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                           = $post;
		$_REQUEST                        = $post;
		try {
			( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ) )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect or a refusal.' );
	}

	private static function notice_of( AbTestExit $exit ): string {
		self::assertSame( 'redirect', $exit->kind, $exit->detail );
		parse_str( (string) parse_url( $exit->detail, PHP_URL_QUERY ), $q );
		return (string) ( $q['ab_notice'] ?? '' );
	}

	/** The form of the settings page switching write access on, the box of the terms left out. */
	private static function full_post_without_terms(): array {
		return array(
			'_wpnonce'       => 'nonce-ab_mcp_site_mode',
			'action'         => 'ab_mcp_site_mode',
			'mode'           => 'full',
			'notice_version' => AB_MCP_Site_Mode::NOTICE_VERSION,
			'notice_hash'    => AB_MCP_Site_Mode::notice_hash(),
			'confirm_full'   => '1',
		);
	}

	/* ------------------------------------------- L, the self-description */

	public function testTheSelfDescriptionReportsATrial(): void {
		self::edition( 'trial' );
		self::assertSame( 'trial', self::meta()['edition'] );
	}

	public function testAnUnknownEditionBesideTheTrialIsStillFree(): void {
		foreach ( array( 'enterprise', 'Trial', 'trial ' ) as $edition ) {
			ab_test_reset();
			self::edition( $edition );
			self::assertSame( 'free', self::meta()['edition'], var_export( $edition, true ) );
		}
	}

	public function testAnEditionThatIsNotAStringIsFreeInTheSelfDescription(): void {
		foreach ( array( 'pro', 'agency', 'trial' ) as $says ) {
			ab_test_reset();
			self::edition( self::stringable( $says ) );
			self::assertSame( 'free', self::meta()['edition'], $says );
		}
	}

	/* ------------------------------------------------- H1, the Pro box */

	public function testATrialIsNotOfferedTheTrialItRuns(): void {
		self::edition( 'trial' );
		self::assertSame( '', self::admin( 'pro_box_html' ) );
	}

	public function testAnEditionThatIsNotAStringIsOfferedPro(): void {
		self::edition( self::stringable( 'pro' ) );
		self::assertStringContainsString( 'Try Pro free for 7 days', self::admin( 'pro_box_html' ) );
	}

	/* --------------------------------- H2, the agreement to the terms */

	public function testATrialMustAgreeToTheTermsAsTheFreeEditionMust(): void {
		self::edition( 'trial' );
		self::assertTrue( AB_MCP_Site_Mode::terms_required(), 'A trial bought nothing.' );

		$exit = self::post( 'handle_site_mode', self::full_post_without_terms() );

		self::assertSame( 'mode_terms', self::notice_of( $exit ) );
		self::assertFalse( AB_MCP_Site_Mode::is_full() );
		self::assertNull( AB_MCP_Site_Mode::confirmation() );
	}

	public function testATrialThatAgreesSwitchesOnAndTheRecordKeepsTheTrial(): void {
		self::edition( 'trial' );

		$exit = self::post( 'handle_site_mode', array( 'agree_terms' => '1' ) + self::full_post_without_terms() );

		self::assertSame( 'mode_full', self::notice_of( $exit ) );
		$record = AB_MCP_Site_Mode::confirmation();
		self::assertTrue( $record['terms_agreed'] );
		self::assertSame( 'trial', $record['edition'] );
		self::assertFalse( AB_MCP_Site_Mode::terms_pending() );
	}

	/* ---------------------------------- H3, the box «On: full power» */

	public function testATrialReadsWhatProWrites(): void {
		self::edition( 'trial' );
		self::assertSame( self::WRITES_PRO, self::writes() );
	}

	public function testAnEditionThatIsNotAStringReadsWhatTheFreeEditionWrites(): void {
		self::edition( self::stringable( 'pro' ) );
		self::assertSame( self::WRITES_FREE, self::writes() );
	}

	/* --------------------------------- H4, the refusals of the guard */

	public function testOnATrialTheRefusalsNameTheToolsOfPro(): void {
		self::edition( 'trial' );
		self::assertStringContainsString( 'wp_network_activate_plugin', self::refusal( 'active_sitewide_plugins' ) );
		$cron = self::refusal( 'cron' );
		self::assertStringContainsString( 'wp_schedule_cron', $cron );
		self::assertStringContainsString( 'wp_unschedule_cron', $cron );
		self::assertStringContainsString( 'wp_change_site_address', self::refusal( 'siteurl' ) );
		foreach ( AB_MCP_Option_Guard::CONFIRMED as $name ) {
			self::assertStringContainsString( 'wp_update_option', self::refusal( $name ), $name );
		}
	}

	public function testATrialHasNoToolOfTheAgencyPlan(): void {
		self::edition( 'trial' );
		self::assertTrue( self::edition_has( 'wp_change_site_address' ), 'A tool of Pro.' );
		foreach ( \AB_MCP_Guidance::AGENCY_TOOLS as $tool ) {
			self::assertFalse( self::edition_has( $tool ), $tool );
		}
	}

	public function testAnEditionThatIsNotAStringHasNoToolOfProOrAgency(): void {
		self::edition( self::stringable( 'pro' ) );
		self::assertStringNotContainsString( 'wp_network_activate_plugin', self::refusal( 'active_sitewide_plugins' ) );
		self::assertStringNotContainsString( 'wp_change_site_address', self::refusal( 'siteurl' ) );
		self::assertFalse( self::edition_has( 'wp_change_site_address' ) );

		ab_test_reset();
		self::edition( self::stringable( 'agency' ) );
		self::assertFalse( self::edition_has( 'wp_deploy_push_zip' ), 'Nor a tool of the Agency plan.' );
	}

	/* ---------------------------- H5, the moments of the takeover */

	public function testOnATrialNoEditionPartIsPossible(): void {
		Moments::$published = array( 'site_sync', 'run_revert' );
		self::assertTrue( Moments::possible(), 'The free edition, a feature published.' );

		self::edition( 'trial' );
		self::assertFalse( Moments::possible() );
		Moments::report_seen( 1762680000 );
		self::assertFalse( Moments::may_show( 'm1' ) );
	}

	public function testAnEditionThatIsNotAStringIsTheFreeOneForTheMoments(): void {
		Moments::$published = array( 'site_sync', 'run_revert' );
		self::edition( self::stringable( 'pro' ) );
		self::assertTrue( Moments::possible() );
	}
}
