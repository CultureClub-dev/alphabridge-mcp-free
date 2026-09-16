<?php
/**
 * Die drei Listen, die bestimmen, was Kunden bekommen.
 *
 * Das Paket entsteht auf zwei Wegen: `git archive` folgt `.gitattributes`
 * (und genau das prüft die CI), `wp dist-archive` folgt `.distignore`. Am
 * 16.09.2026 hinkte `.distignore` hinterher: `RELEASE.md` stand nur in der
 * einen Liste. Diese Datei trägt interne Pfade und Notizen zur Hub-Konfiguration
 * — nichts, was in ein Kundenpaket gehört.
 *
 * Eine Liste, die nur ein Werkzeug kennt, wird still falsch. Darum die Regel:
 * Was `git archive` aussperrt, muss `.distignore` auch aussperren. Andersherum
 * gilt sie nicht — `.distignore` muss zusätzlich Dinge nennen, die gar nicht
 * versioniert sind (`vendor/`, Zwischendateien), die `git archive` deshalb nie
 * sieht.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class PackagingListsTest extends TestCase {

	/** @return string[] */
	private function export_ignored(): array {
		$zeilen = file( dirname( __DIR__ ) . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$pfade  = array();
		foreach ( (array) $zeilen as $zeile ) {
			if ( '' === trim( $zeile ) || '#' === $zeile[0] ) {
				continue;
			}
			if ( false === strpos( $zeile, 'export-ignore' ) ) {
				continue;
			}
			$pfade[] = trim( (string) strtok( trim( $zeile ), " \t" ) );
		}
		return $pfade;
	}

	/** @return string[] */
	private function dist_ignored(): array {
		$zeilen = file( dirname( __DIR__ ) . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$pfade  = array();
		foreach ( (array) $zeilen as $zeile ) {
			$zeile = trim( $zeile );
			if ( '' === $zeile || '#' === $zeile[0] ) {
				continue;
			}
			$pfade[] = $zeile;
		}
		return $pfade;
	}

	public function test_beide_listen_sind_nicht_leer(): void {
		// Sonst prüft der Vergleich unten nichts: zwei leere Listen sind gleich.
		$this->assertGreaterThan( 5, count( $this->export_ignored() ), '.gitattributes' );
		$this->assertGreaterThan( 5, count( $this->dist_ignored() ), '.distignore' );
	}

	public function test_distignore_sperrt_alles_aus_was_git_archive_aussperrt(): void {
		$fehlend = array_values( array_diff( $this->export_ignored(), $this->dist_ignored() ) );

		$this->assertSame(
			array(),
			$fehlend,
			'Diese Pfade hält git archive draussen, .distignore aber nicht: ' . implode( ', ', $fehlend )
		);
	}

	public function test_release_anleitung_bleibt_im_haus(): void {
		// Namentlich, weil genau diese Datei die Lücke war und interne Pfade trägt.
		$this->assertContains( '/RELEASE.md', $this->export_ignored() );
		$this->assertContains( '/RELEASE.md', $this->dist_ignored() );
	}
}
