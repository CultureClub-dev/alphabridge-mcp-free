<?php
/**
 * Der einmalige Bewertungshinweis, eine Leiste am Fuss des farbigen Kopfs der
 * Einstellungsseite (Variante C, Entscheid Thomas 05.10.2026).
 *
 * Er soll erst erscheinen, wenn eine Site das Plugin wirklich benutzt hat, und
 * nach «Nicht mehr fragen» nie wieder — auch nicht nach einem Update. Jede
 * Klausel der Regel hat hier ihren eigenen Rotnachweis: ein Hinweis, der zu
 * früh oder ein zweites Mal erscheint, bewirkt das Gegenteil dessen, wofür er
 * da ist.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Review_Notice;
use AB_MCP_Settings;
use AbTestExit;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class ReviewNoticeTest extends TestCase {

	private const T0  = 1700000000;
	private const DAY = 86400;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Ein Zustand, bei dem beide Schwellen genau erreicht sind. */
	private function reif(): array {
		return array(
			'first_call' => self::T0,
			'calls'      => AB_MCP_Review_Notice::MIN_CALLS,
			'dismissed'  => false,
		);
	}

	/** Der erste Zeitpunkt, an dem die Frist um ist. */
	private function frist_um(): int {
		return self::T0 + AB_MCP_Review_Notice::MIN_DAYS * self::DAY;
	}

	public function test_due_when_both_thresholds_are_met(): void {
		$this->assertTrue( AB_MCP_Review_Notice::is_due( $this->reif(), $this->frist_um() ) );
	}

	public function test_not_due_one_second_before_the_days_are_up(): void {
		$this->assertFalse( AB_MCP_Review_Notice::is_due( $this->reif(), $this->frist_um() - 1 ) );
	}

	public function test_not_due_with_one_call_too_few(): void {
		$zustand = array_merge( $this->reif(), array( 'calls' => AB_MCP_Review_Notice::MIN_CALLS - 1 ) );
		$this->assertFalse( AB_MCP_Review_Notice::is_due( $zustand, $this->frist_um() + 365 * self::DAY ) );
	}

	public function test_not_due_once_dismissed_however_long_ago(): void {
		$zustand = array_merge( $this->reif(), array( 'dismissed' => true ) );
		$this->assertFalse( AB_MCP_Review_Notice::is_due( $zustand, $this->frist_um() + 365 * self::DAY ) );
	}

	public function test_not_due_before_a_first_call_started_the_clock(): void {
		// Ohne Startzeitpunkt wäre «jetzt minus null» ein riesiges Alter und die
		// Frist auf einer frisch aktivierten Site sofort um. Ein negativer
		// Zeitstempel ist Müll und zählt genauso wenig als Start.
		foreach ( array( 0, -1 ) as $start ) {
			$zustand = array( 'first_call' => $start, 'calls' => AB_MCP_Review_Notice::MIN_CALLS, 'dismissed' => false );
			$this->assertFalse( AB_MCP_Review_Notice::is_due( $zustand, $this->frist_um() ), "first_call = $start" );
		}
	}

	public function test_the_thresholds_are_fourteen_days_and_fifty_calls(): void {
		// Die Zahlen stehen im Plan; die übrigen Tests rechnen mit den
		// Konstanten und würden eine leisere Schwelle nicht bemerken.
		$this->assertSame( 14, AB_MCP_Review_Notice::MIN_DAYS );
		$this->assertSame( 50, AB_MCP_Review_Notice::MIN_CALLS );
	}

	public function test_the_first_call_starts_the_clock_and_the_counter(): void {
		AB_MCP_Review_Notice::count_call( self::T0 );
		$this->assertSame( self::T0, AB_MCP_Review_Notice::state()['first_call'] );
		$this->assertSame( 1, AB_MCP_Review_Notice::state()['calls'] );

		AB_MCP_Review_Notice::count_call( self::T0 + 5 );
		$this->assertSame( self::T0, AB_MCP_Review_Notice::state()['first_call'], 'der Startzeitpunkt bleibt der erste' );
		$this->assertSame( 2, AB_MCP_Review_Notice::state()['calls'] );
	}

	public function test_counting_stops_at_the_threshold(): void {
		for ( $i = 0; $i < AB_MCP_Review_Notice::MIN_CALLS + 10; $i++ ) {
			AB_MCP_Review_Notice::count_call( self::T0 + $i );
		}
		$this->assertSame( AB_MCP_Review_Notice::MIN_CALLS, AB_MCP_Review_Notice::state()['calls'] );
		$this->assertSame(
			AB_MCP_Review_Notice::MIN_CALLS,
			ab_test_writes( AB_MCP_Review_Notice::OPTION ),
			'ab der Schwelle wird nichts mehr geschrieben — jeder Aufruf wäre sonst ein Datenbankzugriff'
		);
	}

	public function test_nothing_is_written_once_dismissed(): void {
		AB_MCP_Review_Notice::count_call( self::T0 );
		AB_MCP_Review_Notice::dismiss();
		$vorher = ab_test_writes( AB_MCP_Review_Notice::OPTION );

		AB_MCP_Review_Notice::count_call( self::T0 + 1 );

		$this->assertSame( $vorher, ab_test_writes( AB_MCP_Review_Notice::OPTION ) );
		$this->assertSame( 1, AB_MCP_Review_Notice::state()['calls'] );
	}

	public function test_dismissal_survives_an_update(): void {
		// Ein Update ruft install_defaults() erneut auf und schreibt die
		// Einstellungen neu; der Hinweis lebt in seinen eigenen Optionen.
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		AB_MCP_Review_Notice::dismiss();
		AB_MCP_Settings::install_defaults();

		$this->assertTrue( AB_MCP_Review_Notice::state()['dismissed'] );
		$this->assertSame( '', AB_MCP_Review_Notice::render( $this->frist_um() ) );
	}

	public function test_a_stale_counter_write_cannot_revive_the_question(): void {
		// Der Wettlauf: ein Werkzeugaufruf hat den Zähler gelesen, der
		// Administrator klickt «nicht mehr fragen», danach schreibt der Aufruf
		// seinen alten Stand zurück. Die Absage darf davon nichts merken.
		AB_MCP_Review_Notice::count_call( self::T0 );
		AB_MCP_Review_Notice::dismiss();
		update_option( AB_MCP_Review_Notice::OPTION, array( 'first_call' => self::T0, 'calls' => AB_MCP_Review_Notice::MIN_CALLS ) );

		$this->assertTrue( AB_MCP_Review_Notice::state()['dismissed'] );
		$this->assertSame( '', AB_MCP_Review_Notice::render( $this->frist_um() ) );
	}

	public function test_counting_never_writes_the_dismissal(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			AB_MCP_Review_Notice::count_call( self::T0 + $i );
		}
		$this->assertSame( 0, ab_test_writes( AB_MCP_Review_Notice::OPTION_DISMISSED ), 'zählen fasst die Absage nicht an' );
		AB_MCP_Review_Notice::dismiss();
		$this->assertSame( 1, ab_test_writes( AB_MCP_Review_Notice::OPTION_DISMISSED ) );
		$this->assertSame( 5, ab_test_writes( AB_MCP_Review_Notice::OPTION ), 'die Absage fasst den Zähler nicht an' );
	}

	public function test_render_is_empty_until_due(): void {
		$this->assertSame( '', AB_MCP_Review_Notice::render( $this->frist_um() ), 'ohne jeden Aufruf nichts' );
		update_option( AB_MCP_Review_Notice::OPTION, array_merge( $this->reif(), array( 'calls' => 1 ) ) );
		$this->assertSame( '', AB_MCP_Review_Notice::render( $this->frist_um() ), 'mit zu wenigen Aufrufen nichts' );
	}

	public function test_render_is_a_strip_in_the_header_not_a_wordpress_notice(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$html = AB_MCP_Review_Notice::render( $this->frist_um() );

		$this->assertStringStartsWith( '<div class="ab-review">', $html );
		// WordPress verschiebt alles mit der Klasse «notice» unter die Marke
		// wp-header-end, also aus dem Kopf heraus.
		$this->assertDoesNotMatchRegularExpression( '/class="[^"]*\bnotice\b/', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html, 'der Schliessknopf von WordPress merkt sich nichts' );
	}

	public function test_render_names_the_days_and_the_calls_it_counted(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$this->assertStringContainsString( 'In use for 14 days, at least 50 successful calls. What do you think of it?', AB_MCP_Review_Notice::render( $this->frist_um() ) );
		$this->assertStringContainsString( 'In use for 18 days,', AB_MCP_Review_Notice::render( $this->frist_um() + 4 * self::DAY + 3600 ), 'angebrochene Tage zählen nicht' );
	}

	public function test_render_leads_through_admin_post_to_the_review_form_and_to_the_dismissal(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$x     = $this->xpath( AB_MCP_Review_Notice::render( $this->frist_um() ) );
		$go    = $x->query( '//a[contains(@class,"ab-review__go")]' )->item( 0 );
		$off   = $x->query( '//a[contains(@class,"ab-review__off")]' )->item( 0 );

		$this->assertNotNull( $go );
		$this->assertStringContainsString( 'admin-post.php?action=ab_mcp_review_go', $go->getAttribute( 'href' ), 'der Klick wird hier vermerkt, dann geht es weiter' );
		$this->assertStringContainsString( '_wpnonce=', $go->getAttribute( 'href' ) );
		$this->assertSame( '_blank', $go->getAttribute( 'target' ) );
		$this->assertStringContainsString( 'noopener', $go->getAttribute( 'rel' ) );
		$this->assertStringContainsString( '(opens in a new tab)', $go->textContent, 'für Screenreader' );
		$this->assertNotNull( $off );
		$this->assertStringContainsString( 'action=ab_mcp_review_dismiss', $off->getAttribute( 'href' ) );
		$this->assertStringContainsString( '_wpnonce=', $off->getAttribute( 'href' ), 'die Absage ist eine Zustandsänderung und trägt einen Nonce' );
		$this->assertSame( 'Don’t show again', trim( $off->textContent ) );
	}

	public function test_write_a_review_records_the_answer_and_sends_on_to_the_form(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$exit = $this->go( 'nonce-ab_mcp_review_go', true );

		$this->assertSame( 'redirect', $exit->kind, $exit->detail );
		$this->assertSame( 'https://wordpress.org/support/plugin/alphabridge-mcp/reviews/#new-post', $exit->detail );
		$this->assertTrue( AB_MCP_Review_Notice::state()['dismissed'] );
		$this->assertSame( '', AB_MCP_Review_Notice::render( $this->frist_um() ), 'die Leiste kommt danach nicht wieder' );
	}

	public function test_write_a_review_needs_the_nonce_and_the_right_to_the_page(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$this->assertSame( 'die', $this->go( 'nonce-other', true )->kind, 'ohne gültigen Nonce' );
		$this->assertSame( 'die', $this->go( 'nonce-ab_mcp_review_go', false )->kind, 'ohne manage_options' );
		$this->assertFalse( AB_MCP_Review_Notice::state()['dismissed'] );
	}

	public function test_the_strip_stands_at_the_foot_of_the_header(): void {
		update_option( AB_MCP_Review_Notice::OPTION, array( 'first_call' => time() - 20 * self::DAY, 'calls' => AB_MCP_Review_Notice::MIN_CALLS ) );
		$x = $this->xpath( $this->hero() );
		$this->assertSame( 1, $x->query( '//div[contains(@class,"ab-hero")]/div[@class="ab-review"]' )->length, 'im Kopf' );
		$this->assertSame( 0, $x->query( '//div[contains(@class,"ab-hero")]/div[@class="ab-review"]/following-sibling::*' )->length, 'als letztes Element des Kopfs' );

		AB_MCP_Review_Notice::dismiss();
		$this->assertSame( 0, $this->xpath( $this->hero() )->query( '//div[@class="ab-review"]' )->length, 'nach der Antwort nicht mehr' );
	}

	/** Follow «Write a review» as an administrator, or as someone without the page. */
	private function go( string $nonce, bool $may ): AbTestExit {
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn( string $cap ): bool => $may && 'manage_options' === $cap;
		$_GET                            = array( '_wpnonce' => $nonce );
		$_REQUEST                        = $_GET;
		try {
			( new AB_MCP_Admin() )->handle_review_go();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'handle_review_go ended without a redirect or a refusal.' );
	}

	private function hero(): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, 'hero_html' );
		return (string) $m->invoke( new AB_MCP_Admin(), array(), array() );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	public function test_state_tolerates_garbage_in_the_option(): void {
		update_option( AB_MCP_Review_Notice::OPTION, 'kaputt' );
		$this->assertSame( array( 'first_call' => 0, 'calls' => 0, 'dismissed' => false ), AB_MCP_Review_Notice::state() );
		update_option( AB_MCP_Review_Notice::OPTION, array( 'calls' => '7', 'dismissed' => '1' ) );
		$this->assertSame( array( 'first_call' => 0, 'calls' => 7, 'dismissed' => false ), AB_MCP_Review_Notice::state(), 'die Absage steht nur in ihrer eigenen Option' );
		update_option( AB_MCP_Review_Notice::OPTION_DISMISSED, '1' );
		$this->assertTrue( AB_MCP_Review_Notice::state()['dismissed'] );
	}

	public function test_the_notice_is_wired_into_the_settings_page_only(): void {
		$root  = dirname( __DIR__ );
		$admin = (string) file_get_contents( $root . '/includes/class-admin.php' );
		$rest  = (string) file_get_contents( $root . '/includes/class-rest-controller.php' );

		$this->assertStringContainsString( 'AB_MCP_Review_Notice::render()', $admin, 'gezeigt auf der eigenen Seite' );
		$this->assertStringContainsString( 'AB_MCP_Review_Notice::count_call()', $rest, 'gezählt bei jedem gelungenen Aufruf' );
		// Die Bitte um eine Bewertung hängt sich nicht in jede Admin-Seite. Der
		// einzige Haken an admin_notices ist der Hinweis nach dem Update auf
		// den Site-Modus (AB_MCP_Admin::mode_notice()): abweisbar und weg,
		// sobald jemand den Modus umschaltet.
		foreach ( glob( $root . '/includes/*.php' ) as $datei ) {
			$inhalt = (string) file_get_contents( $datei );
			if ( 'class-admin.php' === basename( $datei ) ) {
				$this->assertSame( 1, substr_count( $inhalt, 'admin_notices' ), 'nur ein Haken' );
				$this->assertStringContainsString( "add_action( 'admin_notices', array( \$this, 'mode_notice' ) );", $inhalt );
				continue;
			}
			$this->assertStringNotContainsString( 'admin_notices', $inhalt, basename( $datei ) . ' hängt sich nicht in jede Admin-Seite' );
		}
	}
}
