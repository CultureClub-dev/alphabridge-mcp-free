<?php
/**
 * Die Ratenbremse der öffentlichen OAuth-Endpunkte.
 *
 * Bis zum 15.09.2026 gab es hierzu keinen einzigen Test — und genau darin
 * steckten zwei Fehler, die beim Prüfen des Hubs auffielen: die Grenze war mit
 * zehn Registrierungen je Stunde so eng, dass ehrliches Ausprobieren dagegen
 * lief, und jede zugelassene Anfrage gab dem Zähler eine neue volle Stunde —
 * die Zählung begann also erst nach einer Stunde ohne zugelassene Anfrage von
 * vorn. (Abgewiesene Anfragen schrieben nichts, eine Sperre lief ab.)
 *
 * Die Bremse ist nicht der Schutz des Endpunkts. Eine Registrierung gewährt für
 * sich nichts, und gegen das Vollschreiben des Klientenspeichers hilft
 * MAX_CLIENTS mit Verdrängung. Sie soll Lärm dämpfen, nicht Nutzung verhindern.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;

final class RateLimitTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	/** Der Schlüssel, unter dem der Zähler liegt. */
	private function key( string $bucket ): string {
		return 'ab_mcp_o' . $bucket . '_' . md5( (string) $_SERVER['REMOTE_ADDR'] );
	}

	public function test_lets_requests_through_up_to_the_limit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( AB_MCP_OAuth::within_rate_limit( 'probe', 5 ), "Anfrage $i von 5" );
		}
		$this->assertFalse( AB_MCP_OAuth::within_rate_limit( 'probe', 5 ), 'die sechste ist zu viel' );
	}

	public function test_a_refused_request_does_not_count(): void {
		// Sonst schöbe jeder abgewiesene Fühler das Fenster weiter, und wer
		// einmal draussen ist, käme nie wieder herein.
		for ( $i = 1; $i <= 3; $i++ ) {
			AB_MCP_OAuth::within_rate_limit( 'probe', 3 );
		}
		$vorher = $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ];
		AB_MCP_OAuth::within_rate_limit( 'probe', 3 );
		AB_MCP_OAuth::within_rate_limit( 'probe', 3 );
		$this->assertSame( $vorher, $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ], 'der Zähler bleibt stehen' );
	}

	public function test_the_window_does_not_slide(): void {
		/*
		 * Der eigentliche Fehler: set_transient() bekam bei jedem ZUGELASSENEN
		 * Zugriff eine volle Stunde. Die Zählung begann damit erst nach einer
		 * Stunde ohne zugelassene Anfrage von vorn. Die Laufzeit muss der
		 * verbleibenden Zeit des laufenden Fensters entsprechen, statt bei jeder
		 * zugelassenen Anfrage erneut eine volle Stunde zu betragen.
		 */
		AB_MCP_OAuth::within_rate_limit( 'probe', 10 );
		$erste = $GLOBALS['ab_test_transient_ttl'][ $this->key( 'probe' ) ];
		$this->assertSame( HOUR_IN_SECONDS, $erste, 'das Fenster beginnt mit einer vollen Stunde' );

		// Das Fenster ist eine Minute alt: die Laufzeit muss entsprechend kürzer sein.
		$daten = $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ];
		$GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ] = array(
			'count' => $daten['count'],
			'start' => $daten['start'] - 60,
		);
		AB_MCP_OAuth::within_rate_limit( 'probe', 10 );
		$zweite = $GLOBALS['ab_test_transient_ttl'][ $this->key( 'probe' ) ];

		$this->assertLessThan( $erste, $zweite, 'die Laufzeit wird kürzer, nicht wieder voll' );
		/*
		 * Eine Sekunde Spiel: die beiden Aufrufe von time() — meiner hier und
		 * der im Code — können eine Sekundengrenze umschliessen. Geprüft wird,
		 * dass die Laufzeit um das Alter des Fensters schrumpft, nicht dass sie
		 * auf die Sekunde stimmt. Codex, 15.09.2026.
		 */
		$this->assertGreaterThanOrEqual( HOUR_IN_SECONDS - 61, $zweite, 'und zwar um das Alter des Fensters' );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS - 60, $zweite, 'und nicht um mehr' );
	}

	public function test_a_window_that_has_run_out_starts_over(): void {
		AB_MCP_OAuth::within_rate_limit( 'probe', 2 );
		AB_MCP_OAuth::within_rate_limit( 'probe', 2 );
		$this->assertFalse( AB_MCP_OAuth::within_rate_limit( 'probe', 2 ), 'Grenze erreicht' );

		// Das Fenster liegt jetzt eine Stunde und eine Sekunde zurück.
		$daten = $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ];
		$GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ] = array(
			'count' => $daten['count'],
			'start' => $daten['start'] - HOUR_IN_SECONDS - 1,
		);

		$this->assertTrue( AB_MCP_OAuth::within_rate_limit( 'probe', 2 ), 'nach Ablauf wieder frei' );
		$this->assertSame( 1, $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ]['count'], 'und von vorn gezählt' );
	}

	public function test_an_old_counter_from_a_previous_version_is_discarded(): void {
		// Frühere Fassungen legten eine blosse Zahl ab, ohne Fensteranfang.
		// Geraten wird nicht; verworfen ist die harmlosere Seite des Irrtums.
		$GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ] = 99;
		$this->assertTrue( AB_MCP_OAuth::within_rate_limit( 'probe', 3 ), 'alter Zähler blockiert nicht' );
		$this->assertIsArray( $GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ] );
	}

	public function test_a_half_written_counter_is_discarded(): void {
		/*
		 * Ein Fensteranfang ohne Zähler ist kein Fenster. Würde nur der Anfang
		 * übernommen, liefe das Fenster früher ab als es darf — sichtbar allein
		 * an der Laufzeit, nicht am Zählstand. Darum wird hier die Laufzeit
		 * gemessen und nicht der Zähler.
		 */
		$GLOBALS['ab_test_transients'][ $this->key( 'probe' ) ] = array( 'start' => time() - 1800 );
		AB_MCP_OAuth::within_rate_limit( 'probe', 3 );
		$this->assertSame(
			HOUR_IN_SECONDS,
			$GLOBALS['ab_test_transient_ttl'][ $this->key( 'probe' ) ],
			'ein halber Datensatz beginnt ein neues Fenster, kein halb abgelaufenes'
		);
	}

	public function test_each_sender_is_counted_separately(): void {
		AB_MCP_OAuth::within_rate_limit( 'probe', 1 );
		$this->assertFalse( AB_MCP_OAuth::within_rate_limit( 'probe', 1 ), 'dieser Absender ist durch' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertTrue( AB_MCP_OAuth::within_rate_limit( 'probe', 1 ), 'ein anderer nicht' );
	}

	public function test_buckets_do_not_share_a_counter(): void {
		AB_MCP_OAuth::within_rate_limit( 'reg', 1 );
		$this->assertTrue( AB_MCP_OAuth::within_rate_limit( 'tok', 1 ), 'Token-Anfragen zählen eigenständig' );
	}

	public function test_the_registration_limit_leaves_room_for_honest_use(): void {
		/*
		 * Kein Selbstzweck: Über einen gehosteten Hub kommen alle Verbindungen
		 * einer Site von wenigen Adressen, und wer ausprobiert, verbindet und
		 * trennt mehrfach. Zehn waren dafür zu wenig — am 15.09.2026 zweimal
		 * dagegengelaufen, ohne jede Absicht.
		 */
		// Genau dreissig, nicht «mindestens». Eine Sperre, die jeden Wert nach
		// oben durchlässt, hält die Entscheidung nicht fest. Codex, 15.09.2026.
		$this->assertSame( 30, AB_MCP_OAuth::MAX_REGISTRATIONS_PER_WINDOW );
	}
}
