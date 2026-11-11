# Grenzen der Prüfumgebung

Benannte Grenzen der Testhilfen der Integrationssuite (Z8b Zweig 1, Vorabprüfung; plan.md Abschnitt 14). Jede ist ein
Grenzkandidat: Sie trifft weder den Normalfall noch eine Kernzusage, darum bleibt die vorhandene Hilfe, wie sie ist, und
die neue Hilfe macht es anders. Fundstellen am Stand von Free `9be07f7`. Testcode, geht nicht ins ZIP (`/tests` ist
`export-ignore`; der Job «ZIP stays clean» lehnt jeden Eintrag unter `/tests/` ab).

## G1 `exit_at` belegt den Abbruchpunkt nicht (V2)

- **Fundstelle:** `tests/integration/bin/sync-site.php:230–244` (`exit( 0 )` :238), gelesen über
  `tests/integration/support/class-it-sync-steps.php:30–41` («gave no JSON»).
- **Was nicht geht:** Der Helfer erkennt einen Abbruch nur daran, dass auf stdout kein JSON steht. Genauso sehen aus:
  `wp_die()` im Kindprozess (der Standard-Handler endet im CLI mit `die()`, Status 0, HTML), ein `exit` des geprüften
  Codes vor dem Haken, eine Ausgabe vor dem JSON bei einem Lauf, der zu Ende lief. Wo der Prozess endete, belegt er nicht.
- **Warum kein Normalfall:** Der einzige Nutzer
  (`SyncRunTest::testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun`) prüft danach den
  Zustand (Zeile geschrieben, Schritt `begun`, Journal `planned`); ein Abbruch an anderer Stelle machte ihn rot.
- **Was die neue Hilfe tut:** `AB_MCP_Test_Abort` schreibt vor dem Abbruch eine Markendatei mit Punkt und Betriebsart;
  `AB_MCP_IT_Process::abort()` verlangt sie und den Nachweis der Betriebsart, sonst ist der Lauf ein Fehler
  (`TestAbortTest`, `AbortHelperTest`).

## G2 Der Prozesshelfer hat keine Zeitgrenze und liest stdout vor stderr (V3)

- **Fundstelle:** `tests/integration/support/class-it-sync-steps.php:24–35` (`proc_open()`, dann
  `stream_get_contents()` erst für stdout, dann für stderr).
- **Was nicht geht:** Ein Kindprozess, der hängt (eine Sperre, eine Anfrage ohne Antwort), oder der mehr als einen
  Pipe-Puffer (64 KiB) auf stderr schreibt, hält den Job `integration` bis zu seiner Zeitgrenze von 35 min fest, ohne
  den Test zu nennen.
- **Warum kein Normalfall:** Die Schritte von `bin/sync-site.php` schreiben auf stderr nur kurze Fehlermeldungen,
  `WP_DEBUG_DISPLAY` ist aus (Meldungen gehen ins Log der Site), und kein Schritt wartet ohne eigene Zeitgrenze; ein
  Hänger ist ein Fehlerfall, kein Normalfall. Der Schritt «Logs of the sites» läuft seit Zweig 1 auch beim Abbruch durch
  die Jobgrenze (`if: failure() || cancelled()`).
- **Was die neue Hilfe tut:** `AB_MCP_IT_Process::run()` liest stdout und stderr nebeneinander ohne Blockieren, mit
  Zeitgrenze je Kindprozess, und beendet an der Grenze das Kind über seine eigene PID samt dem, was es gestartet hat
  (`TestAbortTest::testAChildPastItsTimeLimitIsEndedByItsOwnPid`, `…testMuchOutputOnBothPipesDoesNotStallTheChild`).

## G3 Der Mutationslauf beendet bei seiner Zeitgrenze nur PHPUnit (V4)

- **Fundstelle:** `tests/mutation/run.php`, `exec()` (Zeitgrenze, `proc_terminate()` auf den direkten Kindprozess) und
  `restore_pending()` (SIGTERM, SIGINT, SIGHUP).
- **Was nicht geht:** Prozesse, die PHPUnit gestartet hat (`bin/sync-site.php`, `bin/site-call.php`), laufen nach dem
  Ende von PHPUnit weiter und können Sperren oder Zeilen halten, die der nächste Eintrag antrifft.
- **Warum kein Normalfall:** Es betrifft nur einen Eintrag, der seine Zeitgrenze (600 s) erreicht; der ist ohnehin
  «TIMEOUT», also ohne Urteil, und die Liste braucht einen Blick.
- **Was die neue Hilfe tut:** Kindprozesse über `AB_MCP_IT_Process` haben ihre eigene, kürzere Zeitgrenze und werden
  samt ihren Nachkommen beendet, bevor die Grenze des Mutationslaufs greift.

## G4 `serve.sh stop` beendet Worker ohne Prüfung der Befehlszeile (V6)

- **Fundstelle:** `tests/integration/bin/serve.sh`, `stop()`: Kinder der geprüften Server-PID (`pgrep -P`), beendet nach
  bis zu 5 s Warten, ohne ihre Befehlszeile zu prüfen.
- **Was nicht geht:** Wäre die PID eines Workers in diesem Fenster neu vergeben, träfe `kill` einen fremden Prozess.
- **Warum kein Normalfall:** Die Worker enden mit ihrem Server; eine Neuvergabe derselben PID innerhalb weniger Sekunden
  ist praktisch ausgeschlossen (fortlaufende PIDs auf macOS und Linux).
- **Was die neue Hilfe tut:** `AB_MCP_IT_Process` liest die Nachkommen erst im Moment des Beendens und nur unterhalb der
  eigenen Kind-PID.

## G5 `sync-site.php` gibt Ergebnisse mit `JSON_PARTIAL_OUTPUT_ON_ERROR` aus (V7)

- **Fundstelle:** `tests/integration/bin/sync-site.php:557`.
- **Was nicht geht:** Ein Ergebnis mit ungültigem UTF-8 wird still zu `null` statt zu einem Fehler (Fehlerart «halb
  geschrieben»).
- **Warum kein Normalfall:** Die Schritte geben Zeilen, Zahlen und Texte der Sites zurück, die WordPress als UTF-8
  speichert; Bytes geben die Schritte in base64 (`upload`, `put_file`).
- **Was die neue Hilfe tut:** `AB_MCP_IT_Child` gibt sein Ergebnis mit `JSON_THROW_ON_ERROR` aus; ein Ergebnis, das kein
  gültiges UTF-8 ist, beendet den Lauf als Fehler.
