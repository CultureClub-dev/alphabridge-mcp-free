<?php
/**
 * The card «Staging» (free since 4.15.0), on the page «Staging» since 4.16.0.
 *
 * The card brings the way to a staging site together: the checklist of
 * wp_staging_status with the next step, the role of the site and the
 * other site of its pair (the pair in the hub only as a note, no link: the
 * hub's way is not public yet), how to make the staging copy (the host's
 * own guide only where one was read), the preview protection and «Staging
 * first» with every connection. It adds no form, action or nonce of its own; it folds
 * per user and is never taken off the page.
 *
 * What must hold:
 *
 * - The card stands on the page «Staging» (since 4.16.0; until 4.15.0 between
 *   the connection check and the fine-tuning of the settings page), with its
 *   parts in the order of the checklist, each form once.
 * - Every form in the card is signed for its own handler, and every action
 *   of the card (four forms, two ajax calls) is refused without its nonce
 *   and without manage_options; a form lands on a part inside the card.
 * - The checklist is the one wp_staging_status reports, item for item,
 *   with its state as a word; the first open item is the next step.
 * - The state of each connection in the table is what the gate decides for
 *   its next writing call (AB_MCP_Staging_Gate::would_hold_next()).
 * - No line about an edition, a price or a trial, and no link to the hub;
 *   the only links leaving the site are the guides of the hosts.
 * - The guide of a host shows only for a host whose guide was read; any
 *   other host gets the ways that work everywhere, and the checklist names
 *   a host's staging function only with its guide.
 * - The card folds per user, opens after a form of its own, and stays on
 *   the page folded, with «Staging first» off too.
 * - Its heading labels it and stands outside the fold; each part has one.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Preview_Protection;
use AB_MCP_Settings;
use AB_MCP_Staging;
use AB_MCP_Staging_Gate as Gate;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AbTestExit;
use AbTestJsonExit;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class StagingCardTest extends TestCase {

	const STAGING = 'https://staging.example.test';

	/** The parts of the card, in their order. */
	const PARTS = array( 'ab-staging-checklist', 'ab-staging', 'ab-staging-copy', 'ab-preview', 'ab-staging-first' );

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
		$GLOBALS['ab_test_current_user']           = 7;
		$GLOBALS['ab_test_can']                    = static fn(): bool => true;
		$GLOBALS['ab_test_home']                   = 'https://example.test';
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		// The loader of the preview protection lands in this folder.
		if ( ! \defined( 'WPMU_PLUGIN_DIR' ) ) {
			\define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-mu-plugins-' . getmypid() );
		}
		self::cleanMuDir();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		self::cleanMuDir();
	}

	/* ------------------------------------------------------------ helpers */

	private static function cleanMuDir(): void {
		$dir = WPMU_PLUGIN_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $dir );
	}

	/** @param mixed ...$args */
	private function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function card(): string {
		return $this->admin( 'staging_card_html' );
	}

	private static function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/** Text of a node with its white space folded. */
	private static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
	}

	/** A connection (token entry); returns its hash. */
	private static function connect( string $label, int $user = 7 ): string {
		return hash( 'sha256', AB_MCP_Settings::add_token( $user, $label ) );
	}

	/** The rows of the table under «Staging first»: label => state. */
	private static function rows( string $html ): array {
		$out = array();
		foreach ( self::xpath( $html )->query( '//table[contains(@class, "ab-staging-first__table")]/tbody/tr' ) as $tr ) {
			$cells                            = $tr->getElementsByTagName( 'td' );
			$out[ self::text( $cells->item( 0 ) ) ] = self::text( $cells->item( 1 ) );
		}
		return $out;
	}

	/** Send a form to a handler; returns how it ended. */
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

	/** Call an ajax handler; returns its answer or how it ended. */
	private function ajax( string $handler, array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin() )->$handler();
		} catch ( AbTestJsonExit $exit ) {
			return $exit;
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without an answer.' );
	}

	/** The site states the card is checked in. */
	private static function state( string $name ): void {
		switch ( $name ) {
			case 'unconfirmed':
				return;
			case 'production on kinsta':
				AB_MCP_Staging::save( 'production', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
				AB_MCP_Staging::set_host( 'kinsta', 7 );
				return;
			case 'production paired':
				AB_MCP_Staging::save( 'production', self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
				return;
			case 'staging unprotected':
				AB_MCP_Staging::save( 'staging', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
				return;
			case 'staging protected and paired':
				$GLOBALS['ab_test_home'] = self::STAGING;
				AB_MCP_Staging::save( 'staging', 'https://example.test', null, 7, AB_MCP_Staging::SOURCE_FORM );
				AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
				return;
		}
		self::fail( 'Unknown state ' . $name );
	}

	/** @return array<string,array{0:string}> */
	public static function states(): array {
		$out = array();
		foreach ( array( 'unconfirmed', 'production on kinsta', 'production paired', 'staging unprotected', 'staging protected and paired' ) as $name ) {
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	/* ------------------------------------------------------------- place */

	public function testTheCardStandsOnThePageStaging(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$html                   = $this->admin( 'render_staging' );

		self::assertIsInt( strpos( $html, 'id="ab-staging-card"' ) );
		self::assertFalse( strpos( $html, 'id="ab-doctor"' ), 'Nothing of «AI Connection» there.' );
		self::assertFalse( strpos( $html, 'id="ab-caps"' ) );

		$x       = self::xpath( $html );
		$section = $x->query( '//section[@id="ab-staging-card"]' );
		self::assertSame( 1, $section->length );
		$at = array();
		foreach ( self::PARTS as $id ) {
			self::assertSame( 1, $x->query( '//*[@id="' . $id . '"]' )->length, $id . ' once on the page.' );
			self::assertSame( 1, $x->query( './/*[@id="' . $id . '"]', $section->item( 0 ) )->length, $id . ' inside the card.' );
			$at[] = strpos( $html, 'id="' . $id . '"' );
		}
		$sorted = $at;
		sort( $sorted );
		self::assertSame( $sorted, $at, 'Checklist, role, staging copy, preview protection, Staging first.' );

		foreach ( array( 'ab_mcp_staging', 'ab_mcp_preview_protection', 'ab_mcp_staging_first' ) as $action ) {
			self::assertSame( 1, substr_count( $html, '<input type="hidden" name="action" value="' . $action . '">' ), $action . ': one form, no double.' );
		}
	}

	/* ------------------------------------------------------------ actions */

	public function testEveryFormInTheCardIsSignedForItsOwnHandler(): void {
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		$hash = self::connect( 'Claude' );
		Gate::save_state( array( 'kind' => 'user', 'id' => '9' ), array( Gate::FIELD_SEEN => time() ) );
		Gate::set_setting( Gate::REQUIRE );

		$x       = self::xpath( $this->card() );
		$actions = array();
		foreach ( $x->query( '//section[@id="ab-staging-card"]//form' ) as $form ) {
			self::assertInstanceOf( DOMElement::class, $form );
			self::assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
			self::assertSame( 'post', $form->getAttribute( 'method' ) );
			$action = $x->query( './/input[@name="action"]', $form )->item( 0 )->getAttribute( 'value' );
			$nonce  = $x->query( './/input[@name="_wpnonce"]', $form )->item( 0 )->getAttribute( 'value' );
			self::assertSame( 'nonce-' . $action, $nonce, $action . ' is signed for its own handler.' );
			$actions[ $action ] = true;
		}
		ksort( $actions );
		self::assertSame( array( 'ab_mcp_preview_protection', 'ab_mcp_staging', 'ab_mcp_staging_connection', 'ab_mcp_staging_first' ), array_keys( $actions ), 'The four forms of before; none of the card\'s own.' );
		self::assertSame( 2, $x->query( '//form//input[@name="action"][@value="ab_mcp_staging_connection"]' )->length, 'A release for the connection and for the account.' );
		self::assertStringContainsString( 'name="hash" value="' . $hash . '"', $this->card() );
		self::assertSame( 1, $x->query( '//button[@data-preview="password"]' )->length, 'A new password over ajax.' );
	}

	/** @return array<string,array{0:string,1:string,2:array,3:string}> */
	public static function handlers(): array {
		return array(
			'role of the site'   => array( 'handle_staging_record', 'ab_mcp_staging', array( 'staging_role' => 'production', 'partner_url' => '', 'host' => '' ), 'ab-staging' ),
			'preview protection' => array( 'handle_preview_protection', 'ab_mcp_preview_protection', array( 'preview' => 'off' ), 'ab-preview' ),
			'staging first'      => array( 'handle_staging_first', 'ab_mcp_staging_first', array( 'staging_first' => 'off' ), 'ab-staging-first' ),
			'one connection'     => array( 'handle_staging_connection', 'ab_mcp_staging_connection', array( 'do' => 'release' ), 'ab-staging-first' ),
		);
	}

	/** Everything a handler of the card can change. */
	private static function stored(): string {
		return serialize( array( $GLOBALS['ab_test_options'], get_option( Gate::OPTION, array() ) ) );
	}

	#[DataProvider( 'handlers' )]
	public function testEveryActionOfTheCardNeedsItsNonceAndManageOptions( string $handler, string $action, array $post, string $anchor ): void {
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		Gate::set_setting( Gate::REQUIRE );
		$post['hash'] = self::connect( 'Claude' );
		$before       = self::stored();

		$exit = $this->submit( $handler, $post, 'nonce-ab_mcp_save' );
		self::assertSame( 'die', $exit->kind, 'Another form\'s nonce.' );
		self::assertSame( $before, self::stored() );

		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		$exit                   = $this->submit( $handler, $post, 'nonce-' . $action );
		self::assertSame( 'die', $exit->kind );
		self::assertSame( 'Insufficient permissions.', $exit->detail );
		self::assertSame( $before, self::stored() );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit                   = $this->submit( $handler, $post, 'nonce-' . $action );
		self::assertSame( 'redirect', $exit->kind );
		self::assertNotSame( $before, self::stored(), 'With both, it acts.' );
		self::assertStringEndsWith( '#' . $anchor, $exit->detail );
		self::assertContains( $anchor, self::PARTS, 'It lands on a part of the card.' );
	}

	/** @return array<string,array{0:string,1:array}> */
	public static function ajaxHandlers(): array {
		return array(
			'fold the card'           => array( 'ajax_staging_card', array( 'folded' => '1' ) ),
			'switch the protection on' => array( 'ajax_preview_protection', array( 'preview' => 'on' ) ),
		);
	}

	#[DataProvider( 'ajaxHandlers' )]
	public function testTheAjaxOfTheCardNeedsTheNonceAndManageOptions( string $handler, array $post ): void {
		$GLOBALS['ab_test_ajax_strict'] = true;
		$before                         = serialize( array( $GLOBALS['ab_test_options'], $GLOBALS['ab_test_meta_store'] ) );

		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		$exit                   = $this->ajax( $handler, $post + array( 'nonce' => 'nonce-ab_mcp_ajax' ) );
		self::assertInstanceOf( AbTestJsonExit::class, $exit );
		self::assertSame( 403, $exit->status );
		self::assertSame( $before, serialize( array( $GLOBALS['ab_test_options'], $GLOBALS['ab_test_meta_store'] ) ) );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit                   = $this->ajax( $handler, $post + array( 'nonce' => 'nonce-ab_mcp_save' ) );
		self::assertInstanceOf( AbTestExit::class, $exit, 'Another nonce: the request ends.' );
		self::assertSame( 'die', $exit->kind );
		$exit = $this->ajax( $handler, $post );
		self::assertInstanceOf( AbTestExit::class, $exit, 'No nonce: the request ends.' );
		self::assertSame( $before, serialize( array( $GLOBALS['ab_test_options'], $GLOBALS['ab_test_meta_store'] ) ) );

		$exit = $this->ajax( $handler, $post + array( 'nonce' => 'nonce-ab_mcp_ajax' ) );
		self::assertInstanceOf( AbTestJsonExit::class, $exit );
		self::assertSame( 200, $exit->status );
		self::assertNotSame( $before, serialize( array( $GLOBALS['ab_test_options'], $GLOBALS['ab_test_meta_store'] ) ) );
	}

	public function testTheActionsAreHooked(): void {
		$admin = new AB_MCP_Admin();
		self::assertSame( 10, has_action( 'wp_ajax_ab_mcp_staging_card', array( $admin, 'ajax_staging_card' ) ) );
		self::assertFalse( has_action( 'wp_ajax_nopriv_ab_mcp_staging_card', array( $admin, 'ajax_staging_card' ) ) );
		foreach ( array( 'handle_staging_record' => 'ab_mcp_staging', 'handle_preview_protection' => 'ab_mcp_preview_protection', 'handle_staging_first' => 'ab_mcp_staging_first', 'handle_staging_connection' => 'ab_mcp_staging_connection' ) as $method => $action ) {
			self::assertSame( 10, has_action( 'admin_post_' . $action, array( $admin, $method ) ), $action );
		}
	}

	/* ---------------------------------------------------------- checklist */

	#[DataProvider( 'states' )]
	public function testTheChecklistIsTheOneTheToolReports( string $state ): void {
		self::state( $state );
		$tool = AB_MCP_Tools_Staging::staging_status( array() );
		$html = $this->card();
		$x    = self::xpath( $html );

		$items = $x->query( '//div[@id="ab-staging-checklist"]//li' );
		self::assertSame( count( $tool['checklist'] ), $items->length );
		// The item the tool's next_step names, read from its text.
		$next  = '';
		$check = false;
		foreach ( $tool['checklist'] as $item ) {
			if ( '' !== $next ) {
				break;
			}
			if ( false === $item['done'] && 0 === strpos( $tool['next_step'], $item['finding'] . ' ' . $item['steps'] ) ) {
				$next = $item['id'];
			} elseif ( isset( $item['check'] ) && $tool['next_step'] === $item['check'] ) {
				$next  = $item['id'];
				$check = true;
			} elseif ( true !== $item['done'] && $tool['next_step'] === $item['finding'] ) {
				$next = $item['id'];
			}
		}
		foreach ( $tool['checklist'] as $i => $item ) {
			$li    = $items->item( $i );
			$texts = array();
			foreach ( $x->query( './p', $li ) as $p ) {
				$texts[] = self::text( $p );
			}
			if ( true === $item['done'] ) {
				$word = 'Done';
			} elseif ( $next === $item['id'] ) {
				$word = 'Next step';
			} elseif ( false === $item['done'] ) {
				$word = 'Open';
			} else {
				$word = 'Not checked';
			}
			$want = array( $word . ' ' . $item['title'], $item['finding'] );
			if ( false === $item['done'] ) {
				$want[] = $item['steps'];
				if ( isset( $item['fallback'] ) ) {
					$want[] = $item['fallback'];
				}
			}
			if ( $check && $next === $item['id'] ) {
				$want[] = 'An assistant checks it from here: wp_staging_status with check_partner=true asks the other site once whether AlphaBridge MCP answers there.';
			}
			self::assertSame( $want, $texts, $state . ': item ' . $item['id'] . ' as the tool reports it.' );
			self::assertSame( $word, self::text( $x->query( './/span[contains(@class, "ab-pill")]', $li )->item( 0 ) ), 'Its state as a word, not only a colour.' );
		}

		$done = count( array_filter( array_column( $tool['checklist'], 'done' ), static fn( $d ) => true === $d ) );
		$line = self::text( $x->query( '//p[contains(@class, "ab-staging-card__state")]' )->item( 0 ) );
		self::assertStringContainsString( 'Checklist: ' . $done . ' of ' . count( $tool['checklist'] ) . ' done.', $line );

		$in_place = $x->query( '//p[contains(@class, "ab-staging-checklist__in-place")]' );
		if ( '' === $next ) {
			self::assertSame( $tool['next_step'], self::text( $in_place->item( 0 ) ), 'No next item: what the tool says to do now that the way is in place.' );
		} else {
			self::assertSame( 0, $in_place->length );
			self::assertSame( 1, $x->query( '//li[contains(@class, "ab-staging-checklist__item--next")]' )->length, 'One next step, the tool\'s.' );
			self::assertStringNotContainsString( 'Direct link:', $html, 'The tool\'s next step is written for the assistant; the card shows the item.' );
		}
	}

	public function testAPairedProductionSiteShowsTheCheckAsTheNextStep(): void {
		self::state( 'production paired' );
		$tool = AB_MCP_Tools_Staging::staging_status( array() );
		self::assertSame( 'Call wp_staging_status with check_partner=true to check that the staging site answers.', $tool['next_step'] );
		$x    = self::xpath( $this->card() );
		$next = $x->query( '//li[contains(@class, "ab-staging-checklist__item--next")]' );
		self::assertSame( 1, $next->length );
		self::assertStringStartsWith( 'Next step Staging site there and answering', self::text( $next->item( 0 ) ) );
		self::assertSame( 1, $x->query( './/p[contains(@class, "ab-staging-checklist__check")]', $next->item( 0 ) )->length, 'The check, said for the person.' );
		self::assertSame( 0, $x->query( '//p[contains(@class, "ab-staging-checklist__in-place")]' )->length, 'Not in place before the check.' );
	}

	public function testSwitchingTheProtectionOnOverAjaxBringsTheCardUpToDate(): void {
		self::state( 'staging unprotected' );
		$before = $this->admin( 'staging_checklist_html', AB_MCP_Staging::report( false ) );
		self::assertStringContainsString( 'Next step</span> <b>Preview protection on the staging site', $before );

		$_POST = array( 'preview' => 'on' );
		try {
			( new AB_MCP_Admin() )->ajax_preview_protection();
			self::fail( 'No answer.' );
		} catch ( AbTestJsonExit $exit ) {
			self::assertSame( 200, $exit->status );
			$data = $exit->payload;
		}
		self::assertSame( AB_MCP_Preview_Protection::PASSWORD_LENGTH, strlen( $data['password'] ), 'The password, once.' );
		$card = self::xpath( $this->card() );
		self::assertSame( $this->admin( 'staging_checklist_html', AB_MCP_Staging::report( false ) ), $data['staging_checklist'], 'The checklist as a reload would show it.' );
		self::assertStringContainsString( 'Done</span> <b>Preview protection on the staging site', $data['staging_checklist'] );
		self::assertStringStartsWith( '<div class="ab-staging ab-staging-checklist" id="ab-staging-checklist">', $data['staging_checklist'], 'It takes the place of the old one.' );
		self::assertSame( self::text( $card->query( '//p[contains(@class, "ab-staging-card__state")]' )->item( 0 ) ), $data['staging_state'] );
		self::assertSame( 'Staging site. Checklist: 3 of 4 done. Staging first: ask.', $data['staging_state'] );
		self::assertSame( self::text( $card->query( '//p[contains(@class, "ab-staging__suggestion")]' )->item( 0 ) ), $data['staging_suggestion'] );
		self::assertStringContainsString( 'The preview protection is on.', $data['staging_suggestion'] );

		$_POST = array( 'preview' => 'password' );
		try {
			( new AB_MCP_Admin() )->ajax_preview_protection();
			self::fail( 'No answer.' );
		} catch ( AbTestJsonExit $exit ) {
			self::assertSame( 200, $exit->status );
			self::assertSame( $data['staging_checklist'], $exit->payload['staging_checklist'], 'A new password: the same checklist, sent all the same.' );
		}
	}

	public function testTheStateLineSaysRoleChecklistAndSetting(): void {
		self::assertStringContainsString( '<p class="ab-direct__status ab-staging-card__state">Not confirmed (counts as the production site). Checklist: 0 of 4 done. Staging first: ask.</p>', $this->card() );
		self::state( 'production paired' );
		Gate::set_setting( Gate::REQUIRE );
		self::assertStringContainsString( '>Production site. Checklist: 2 of 4 done. Staging first: require.</p>', $this->card() );
		Gate::set_setting( Gate::OFF );
		self::assertStringContainsString( '>Production site. Checklist: 2 of 4 done. Staging first: off.</p>', $this->card() );
	}

	/* -------------------------------------------------------------- table */

	public function testTheTableTakesTheStateOfEachConnectionFromWouldHoldNext(): void {
		$now   = time();
		$hash  = array(
			'Fresh'  => self::connect( 'Fresh' ),
			'Asked'  => self::connect( 'Asked' ),
			'Old'    => self::connect( 'Old' ),
			'Never'  => self::connect( 'Never' ),
		);
		AB_MCP_Settings::update_token_fields( $hash['Asked'], array( Gate::FIELD_ACK => $now - 60 ) );
		AB_MCP_Settings::update_token_fields( $hash['Old'], array( Gate::FIELD_ACK => $now - 8 * DAY_IN_SECONDS ) );
		AB_MCP_Settings::update_token_fields( $hash['Never'], array( Gate::FIELD_ACK => $now - 8 * DAY_IN_SECONDS, Gate::FIELD_NEVER => array( 'at' => $now ) ) );

		$check = function ( string $when ) use ( $hash, $now ): array {
			$rows     = self::rows( $this->card() );
			$outcomes = array();
			foreach ( $hash as $label => $h ) {
				$outcome            = Gate::would_hold_next( $now, array( 'kind' => 'token', 'id' => $h ), AB_MCP_Settings::get_token_by_hash( $h ) );
				$outcomes[ $label ] = $outcome;
				self::assertArrayHasKey( $label, $rows, $when );
				if ( Gate::ASK === $outcome ) {
					self::assertMatchesRegularExpression( '/^Asks (with the first|again with the next) change\.$/', $rows[ $label ], $when . ': ' . $label );
				} elseif ( Gate::REQUIRE === $outcome ) {
					self::assertSame( 'Not released: every change is refused.', $rows[ $label ], $when . ': ' . $label );
				} else {
					self::assertDoesNotMatchRegularExpression( '/^Asks (with|again)|Not released/', $rows[ $label ], $when . ': ' . $label . ' runs.' );
				}
			}
			return $outcomes;
		};

		self::assertSame( array( 'Fresh' => Gate::ASK, 'Asked' => Gate::RUNS, 'Old' => Gate::ASK, 'Never' => Gate::RUNS ), $check( 'ask' ) );
		Gate::set_setting( Gate::REQUIRE );
		Gate::release( array( 'kind' => 'token', 'id' => $hash['Asked'] ), 7, $now );
		self::assertSame( array( 'Fresh' => Gate::REQUIRE, 'Asked' => Gate::RUNS, 'Old' => Gate::REQUIRE, 'Never' => Gate::REQUIRE ), $check( 'require' ) );
		AB_MCP_Staging::save( 'production', self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'Fresh' => Gate::RUNS, 'Asked' => Gate::RUNS, 'Old' => Gate::RUNS, 'Never' => Gate::RUNS ), $check( 'paired' ) );
	}

	/* -------------------------------------------------- what it leaves out */

	#[DataProvider( 'states' )]
	public function testNothingInTheCardAdvertisesAndNothingLinksTheHub( string $state ): void {
		self::state( $state );
		self::connect( 'Claude' );
		Gate::set_setting( Gate::REQUIRE );
		$html = $this->card();
		$x    = self::xpath( $html );
		$text = self::text( $x->query( '//section[@id="ab-staging-card"]' )->item( 0 ) );

		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|trial|price|upgrade|checkout|licen[cs]e|freemius/i', $html );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|trial|price|upgrade/i', $text );
		self::assertStringNotContainsString( 'connect.alphabridge-mcp.com', $html );
		self::assertStringNotContainsString( AB_MCP_Admin::HUB_ADDRESS, $html );
		foreach ( $x->query( '//a[@href]' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			self::assertTrue( 0 === strpos( $href, 'https://example.test/wp-admin/' ) || in_array( $href, AB_MCP_Staging::HOST_GUIDES, true ), 'A link leaving the site goes to a host\'s guide only: ' . $href );
		}
		$hub = $x->query( '//p[contains(@class, "ab-staging__hub")]' );
		self::assertSame( 1, $hub->length, 'The pair in the hub: a note.' );
		self::assertSame( 0, $x->query( './/a', $hub->item( 0 ) )->length, 'No link: the hub\'s way is not public yet.' );
		self::assertStringStartsWith( 'Pair in the hub: ', self::text( $hub->item( 0 ) ) );
		self::assertSame( 1, $x->query( '//input[@name="partner_url"]' )->length, 'The address of the other site is entered here.' );
	}

	/* --------------------------------------------------------------- host */

	/** @return array<string,array{0:string}> */
	public static function hosts(): array {
		$out = array( 'unknown' => array( 'unknown' ) );
		foreach ( array_keys( AB_MCP_Staging::HOSTS ) as $host ) {
			$out[ $host ] = array( $host );
		}
		return $out;
	}

	#[DataProvider( 'hosts' )]
	public function testTheGuideOfAHostShowsOnlyWhereItWasRead( string $host ): void {
		AB_MCP_Staging::save( 'production', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		AB_MCP_Staging::set_host( $host, 7 );
		$guide = AB_MCP_Staging::HOST_GUIDES[ $host ] ?? null;
		$name  = AB_MCP_Staging::HOSTS[ $host ] ?? '';

		$report = AB_MCP_Tools_Staging::staging_status( array() );
		self::assertSame( $guide, $report['host']['guide'], 'wp_staging_status names the same guide.' );
		$steps = $report['checklist'][1]['steps'];

		$x    = self::xpath( $this->card() );
		$part = $x->query( '//div[@id="ab-staging-copy"]' )->item( 0 );
		self::assertNotNull( $part );
		$links = $x->query( './/a', $part );
		if ( null !== $guide ) {
			self::assertSame( 1, $links->length );
			self::assertSame( $guide, $links->item( 0 )->getAttribute( 'href' ) );
			self::assertSame( '_blank', $links->item( 0 )->getAttribute( 'target' ) );
			self::assertStringContainsString( 'noopener', $links->item( 0 )->getAttribute( 'rel' ) );
			self::assertSame( 'Staging guide of ' . $name . ' ↗ (opens in a new tab)', self::text( $links->item( 0 ) ) );
			self::assertStringContainsString( $name . ' has a staging function of its own', self::text( $part ) );
			self::assertStringContainsString( 'with the staging function in the control panel of ' . $name . '; its guide: ' . $guide . ' 2. ', $steps );
		} else {
			self::assertSame( 0, $links->length, 'No guide was read: no link.' );
			self::assertStringContainsString( 'Ways to make the copy, with any host:', self::text( $part ) );
			self::assertSame( 3, $x->query( './/ol/li', $part )->length );
			self::assertStringNotContainsString( 'has a staging function', self::text( $part ) );
			self::assertStringContainsString( 'with the staging function of your host, if it has one, or with a staging plugin', $steps );
			if ( '' !== $name ) {
				self::assertStringNotContainsString( 'control panel of ' . $name, $steps, 'No staging function nobody has read about.' );
			}
		}
	}

	public function testTheGuidesAreHttpsPagesOfKnownHosts(): void {
		self::assertNotSame( array(), AB_MCP_Staging::HOST_GUIDES );
		foreach ( AB_MCP_Staging::HOST_GUIDES as $host => $url ) {
			self::assertArrayHasKey( $host, AB_MCP_Staging::HOSTS );
			self::assertStringStartsWith( 'https://', $url );
			self::assertDoesNotMatchRegularExpression( '/[?#\s]/', $url, $host . ': an address without query or fragment.' );
			self::assertNotSame( '', AB_MCP_Staging::normalize_url( $url ) );
		}
		self::assertArrayNotHasKey( 'infomaniak', AB_MCP_Staging::HOST_GUIDES, 'Its guides describe no staging function of its own.' );
		self::assertNull( AB_MCP_Staging::host_guide( 'unknown' ) );
		self::assertNull( AB_MCP_Staging::host_guide( array( 'kinsta' ) ) );
	}

	public function testTheStagingSiteIsNotToldHowToMakeACopy(): void {
		self::state( 'staging unprotected' );
		$html = $this->card();
		self::assertStringNotContainsString( 'id="ab-staging-copy"', $html );
		self::assertStringContainsString( 'id="ab-preview"', $html, 'Its protection is.' );
	}

	/* --------------------------------------------------------------- fold */

	private static function open( string $html ): bool {
		$fold = self::xpath( $html )->query( '//section[@id="ab-staging-card"]/details[contains(@class, "ab-staging-card__fold")]' );
		self::assertSame( 1, $fold->length );
		return $fold->item( 0 )->hasAttribute( 'open' );
	}

	public function testTheCardFoldsForEachUserAndStaysOnThePage(): void {
		self::assertTrue( self::open( $this->card() ), 'Unfolded at first.' );

		$exit = $this->ajax( 'ajax_staging_card', array( 'folded' => '1' ) );
		self::assertSame( 200, $exit->status );
		self::assertSame( array( 'folded' => true ), $exit->payload );
		self::assertSame( '1', get_user_meta( 7, AB_MCP_Admin::STAGING_CARD_FOLDED, true ) );
		self::assertSame( array( '1' ), get_user_meta( 7, AB_MCP_Admin::STAGING_CARD_FOLDED ), 'One row.' );
		$html = $this->card();
		self::assertFalse( self::open( $html ), 'Folded for this user.' );
		self::assertStringContainsString( 'Work on a staging site first</h2>', $html, 'The title stays.' );
		self::assertStringContainsString( 'ab-staging-card__state', $html, 'The state line stays.' );
		foreach ( self::PARTS as $id ) {
			self::assertStringContainsString( 'id="' . $id . '"', $html, 'Folded, a link to ' . $id . ' still finds it.' );
		}

		$GLOBALS['ab_test_current_user'] = 9;
		self::assertTrue( self::open( $this->card() ), 'Another user: unfolded.' );
		$GLOBALS['ab_test_current_user'] = 7;

		Gate::set_setting( Gate::OFF );
		$html = $this->card();
		self::assertFalse( self::open( $html ) );
		self::assertStringContainsString( 'id="ab-staging-first"', $html, 'With Staging first off the card stays too: it folds, it does not go.' );

		foreach ( array( 'staging_saved', 'staging_first_saved', 'staging_released', 'preview_off', 'staging_bad_role' ) as $notice ) {
			$_GET['ab_notice'] = $notice;
			self::assertTrue( self::open( $this->card() ), 'After a form of the card (' . $notice . '): open.' );
		}
		$_GET['ab_notice'] = 'tools_saved';
		self::assertFalse( self::open( $this->card() ), 'Another notice: as the user left it.' );
		$_GET = array();

		self::assertSame( 200, $this->ajax( 'ajax_staging_card', array( 'folded' => '0' ) )->status );
		self::assertSame( '', get_user_meta( 7, AB_MCP_Admin::STAGING_CARD_FOLDED, true ), 'Unfolded: the row goes.' );
		self::assertTrue( self::open( $this->card() ) );
		self::assertSame( 200, $this->ajax( 'ajax_staging_card', array( 'folded' => 'yes' ) )->status );
		self::assertTrue( self::open( $this->card() ), 'Only 1 folds.' );
		self::assertSame( array(), $GLOBALS['ab_test_meta_store']['user'][9] ?? array(), 'Only the user\'s own choice.' );
	}

	/* ------------------------------------------------------ accessibility */

	public function testTheHeadingLabelsTheCardAndStandsOutsideTheFold(): void {
		update_user_meta( 7, AB_MCP_Admin::STAGING_CARD_FOLDED, '1' );
		$x    = self::xpath( $this->card() );
		$card = $x->query( '//section[@id="ab-staging-card"]' )->item( 0 );
		$h2   = $x->query( './h2', $card )->item( 0 );
		self::assertNotNull( $h2 );
		self::assertSame( $card->getAttribute( 'aria-labelledby' ), $h2->getAttribute( 'id' ) );
		self::assertSame( 'Work on a staging site first', self::text( $h2 ) );
		self::assertSame( 0, $x->query( '//summary//h2 | //details//h2' )->length, 'The heading is no part of the fold.' );
		self::assertSame( 'Steps and settings', self::text( $x->query( '//details[contains(@class, "ab-staging-card__fold")]/summary' )->item( 0 ) ) );
		foreach ( array( 'ab-staging-checklist', 'ab-staging', 'ab-staging-copy', 'ab-preview', 'ab-staging-first' ) as $id ) {
			self::assertSame( 1, $x->query( '//*[@id="' . $id . '"]/h3' )->length, $id . ' has its heading.' );
		}
		self::assertSame( 1, $x->query( '//label[@for="ab-staging-partner"]' )->length );
		self::assertSame( 1, $x->query( '//label[@for="ab-staging-host"]' )->length );
	}
}
