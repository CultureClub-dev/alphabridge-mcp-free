<?php
/**
 * Der einmalige Bewertungshinweis.
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
use AB_MCP_Review_Notice;
use AB_MCP_Settings;

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

	public function test_render_links_to_the_review_form_and_to_the_dismissal(): void {
		update_option( AB_MCP_Review_Notice::OPTION, $this->reif() );
		$html = AB_MCP_Review_Notice::render( $this->frist_um() );

		$this->assertStringContainsString( 'https://wordpress.org/support/plugin/alphabridge-mcp/reviews/#new-post', $html );
		$this->assertStringContainsString( 'action=ab_mcp_review_dismiss', $html );
		$this->assertStringContainsString( '_wpnonce=', $html, 'die Absage ist eine Zustandsänderung und trägt einen Nonce' );
		$this->assertStringNotContainsString( 'is-dismissible', $html, 'der Schliessknopf von WordPress merkt sich nichts' );
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
		foreach ( glob( $root . '/includes/*.php' ) as $datei ) {
			$this->assertStringNotContainsString( 'admin_notices', (string) file_get_contents( $datei ), basename( $datei ) . ' hängt sich nicht in jede Admin-Seite' );
		}
	}
}
