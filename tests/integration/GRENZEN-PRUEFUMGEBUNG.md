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
  Zeitgrenze je Kindprozess. Das Kind läuft in einer eigenen Prozessgruppe; an der Grenze, an seinem eigenen Ende und
  nach seinem eigenen SIGKILL beendet die Hilfe die ganze Gruppe über ihre Gruppen-ID
  (`TestAbortTest::testAChildPastItsTimeLimitIsEndedByItsOwnPid`, `…testWhatTheChildStartedEndsWithIt`,
  `…testMuchOutputOnBothPipesDoesNotStallTheChild`).

## G3 Der Mutationslauf beendet bei seiner Zeitgrenze und bei Signalen nur PHPUnit (V4)

- **Fundstelle:** `tests/mutation/run.php`, `exec()` (Zeitgrenze je Eintrag, Vorgabe 600 s, `proc_terminate()` auf den
  direkten Kindprozess) und `restore_pending()` (SIGINT, SIGTERM, SIGHUP: ebenso nur der direkte Kindprozess).
- **Was nicht geht:** Prozesse, die PHPUnit gestartet hat (`bin/sync-site.php`, `bin/site-call.php`,
  `bin/plain-call.php`), laufen nach dem Ende von PHPUnit weiter und können Sperren oder Zeilen halten, die der nächste
  Eintrag antrifft. Die Zeitgrenzen der neuen Hilfe ändern daran nichts: Sie beginnen erst mit dem jeweiligen Kind, ein
  spät gestartetes Kind kann über die Grenze des Mutationslaufs hinaus laufen, und wird PHPUnit vorher beendet (seine
  Zeitgrenze, ein Signal), endet mit ihm auch die Überwachung des Kindes. Das Kind läuft dann in seiner eigenen
  Prozessgruppe bis zu seinem eigenen Ende weiter.
- **Warum kein Normalfall:** Es betrifft nur einen Eintrag, der seine Zeitgrenze erreicht (er ist dann «TIMEOUT», also
  ohne Urteil, und die Liste braucht einen Blick), oder einen Lauf, den ein Signal beendet; die CI prüft danach, dass
  der Checkout unverändert ist.
- **Was die neue Hilfe tut:** Solange PHPUnit läuft, endet jedes ihrer Kinder mit seiner Prozessgruppe an seiner
  eigenen Zeitgrenze oder an seinem Ende (G2). Was nach dem Ende von PHPUnit geschieht, hält sie nicht.

## G4 `serve.sh stop` beendet Worker ohne Prüfung der Befehlszeile (V6)

- **Fundstelle:** `tests/integration/bin/serve.sh`, `stop()`: Kinder der geprüften Server-PID (`pgrep -P`), beendet nach
  bis zu 5 s Warten, ohne ihre Befehlszeile zu prüfen.
- **Was nicht geht:** Wäre die PID eines Workers in diesem Fenster neu vergeben, träfe `kill` einen fremden Prozess.
- **Warum kein Normalfall:** Die Worker enden mit ihrem Server; eine Neuvergabe derselben PID innerhalb weniger Sekunden
  ist praktisch ausgeschlossen (fortlaufende PIDs auf macOS und Linux).
- **Was die neue Hilfe tut:** `AB_MCP_IT_Process` beendet keine einzelnen PIDs, sondern die Prozessgruppe, die sie für
  ihr Kind angelegt hat, über deren ID und nur, solange die Gruppe besteht (`posix_kill( -id, 0 )` vorher).

## G5 `sync-site.php` gibt Ergebnisse mit `JSON_PARTIAL_OUTPUT_ON_ERROR` aus (V7)

- **Fundstelle:** `tests/integration/bin/sync-site.php:557`.
- **Was nicht geht:** Ein Ergebnis mit ungültigem UTF-8 wird still zu `null` statt zu einem Fehler (Fehlerart «halb
  geschrieben»).
- **Warum kein Normalfall:** Die Schritte geben Zeilen, Zahlen und Texte der Sites zurück, die WordPress als UTF-8
  speichert; Bytes geben die Schritte in base64 (`upload`, `put_file`).
- **Was die neue Hilfe tut:** `AB_MCP_IT_Child` gibt sein Ergebnis mit `JSON_THROW_ON_ERROR` aus; ein Ergebnis, das kein
  gültiges UTF-8 ist, beendet den Lauf als Fehler.

## G6 Ein Nachkomme, der die Prozessgruppe verlässt, bleibt (Behebung 1, A6)

- **Fundstelle:** `tests/integration/support/class-it-process.php`, `kill_group()`.
- **Was nicht geht:** Die Hilfe beendet die Prozessgruppe, die sie für das Kind angelegt hat. Ein Nachkomme, der eine
  eigene Gruppe oder Sitzung anlegt (`setsid`, `setpgid`, eine Shell mit Jobsteuerung), liegt ausserhalb und bleibt.
- **Warum kein Normalfall:** Die Kindprozesse der Suite (`bin/site-call.php`, `bin/plain-call.php`, die Shell eines
  Handwegs) legen keine eigene Gruppe an; `bash -c` ohne `-m` hat keine Jobsteuerung.
- **Was die neue Hilfe tut:** Sie beendet nur Gruppen, die sie selbst angelegt hat, über deren ID; kein `pkill`, kein
  Muster, keine fremde PID.

## G7 Der Wächter der Nähte sieht nur die Naht (Behebung 1, A5)

- **Fundstelle:** `tests/integration/support/class-test-seam-guard.php`.
- **Was nicht geht:** Der Wächter hält, dass der Name einer Naht im ausgelieferten Baum nur in ihrer Deklaration und in
  ihren zwei erlaubten Anweisungen vorkommt (Behebung 2) und dass kein ausgelieferter Code Eigenschaften über
  Reflection aufzählt oder ohne Namen schreibt. Code, der die Naht gar nicht benutzt und die Aussenwelt auf einem
  anderen Weg ruft (etwa ein zweites Objekt aus einem Filter), öffnet die Naht nicht und bleibt ihm verborgen.
- **Warum kein Normalfall:** Jeder heikle Zweig ruft seine Aussenwelt über seine Naht; das prüft die Codex-Runde des
  Zweigs an seinem Durchsetzungsort (plan.md 3.1, `conventions.md`, «Heikle Zweige …»).
- **Was die neue Hilfe tut:** Namensregel statt Sperrliste (Präfix `naht_`, ein Name je Naht, im ganzen Baum nur an
  den erlaubten Stellen, in jeder Schreibweise, auch in Zeichenketten und Kommentaren).

## G8 Shutdown-Funktionen nach der Ergebniszeile sieht `calls()` nicht (Codex-Runde 1, A2; Behebung 2)

- **Fundstelle:** `tests/integration/support/class-it-child.php`, `run()` (Ergebniszeile aus einer Shutdown-Funktion, die
  ihren Schreiber einmal ans Ende der Schlange stellt), `tests/integration/support/class-it-process.php`, `calls()`.
- **Was `calls()` sieht:** einen `exit`, `die`, `wp_die()`, Fatal oder ein Signal im Request, also während der Plan
  läuft (keine Ergebniszeile); jedes solche Ende in einer Shutdown-Funktion, die vor dem Schreiber der Ergebniszeile in
  der Schlange steht (die Zeile kommt dann nicht); einen anderen Status als 0 nach der Zeile (etwa aus einem Destruktor).
- **Was nicht geht:** Eine Shutdown-Funktion, die erst nach dem Schreiber registriert wird (eine Shutdown-Funktion, die
  ihrerseits weitere registriert, nachdem der Schreiber sich ans Ende gestellt hat) und darin den Prozess mit Status 0
  beendet, verhindert die Funktionen nach ihr, aber nicht die Ergebniszeile. `calls()` zählt den Lauf dann als Erfolg.
- **Warum:** PHP hat keinen Haken «nach allen Shutdown-Funktionen». Jede Shutdown-Funktion kann weitere registrieren,
  die ans Ende der Schlange kommen; ein Schreiber kann sich nur endlich oft dahinter stellen. Der Normalfall (die
  Rettungen des Plugins registrieren ihre Shutdown-Funktion während des Requests) liegt vor dem Schreiber.
- **Was die neue Hilfe tut:** Ergebniszeile erst aus der zuletzt registrierten Shutdown-Funktion, die sich beim Laufen
  noch einmal ans Ende stellt; dazu verlangt `calls()` Status 0.

## G9 Zur Laufzeit zusammengesetzte Namen sieht der Wächter nicht (Behebung 2, feste Endregel)

- **Fundstelle:** `tests/integration/support/class-test-seam-guard.php`, Regeln 3 bis 5; Beleg
  `SeamGuardTest::testANameComposedWithoutThePrefixIsBeyondTheGuardG9`.
- **Was nicht geht:** Ein Zugriff über einen Namen, der erst zur Laufzeit entsteht und dessen voller Name samt Präfix
  `naht_` nirgends im Quelltext steht: Verkettung ohne das ausgeschriebene Präfix (`'na' . 'ht_x'`), `sprintf` mit
  zerlegtem Präfix, `chr()`, `strrev()`, base64, eine Variablenvariable aus einem solchen Namen
  (`Owner::$$name`). Der Wächter liest den Quelltext; was erst beim Laufen zusammenkommt, sieht er nicht.
- **Warum kein Normalfall:** Niemand schreibt so, ohne die Naht absichtlich verstecken zu wollen; der Name und das
  Präfix ausgeschrieben, in jeder Schreibweise, in Code, Zeichenketten und Kommentaren, sind gesperrt, ebenso
  Reflection, die Eigenschaften ohne Namen aufzählt oder schreibt.
- **Was die neue Hilfe tut:** Nichts darüber hinaus. Nach dem Entscheid der Steuerung (Behebung 2) ist das die feste
  Endregel: Nur ein Befund im Normalfall öffnet eine weitere Runde.

## G10 Code nach dem Ende einer Klasse in derselben Datei gilt dem Wächter noch als ihr Code (Bestätigung Behebung 2)

- **Fundstelle:** `tests/integration/support/class-test-seam-guard.php`, `class_at()` (merkt sich die zuletzt begonnene
  Klasse, nicht ihr Ende) und die Freigabe der Fundstellen in der eigenen Klasse.
- **Was nicht geht:** Steht in derselben Datei nach der schliessenden Klammer der Nahtklasse Code ausserhalb jeder
  Klasse, etwa eine globale Closure, die mit `Closure::bind( …, null, Eigentümer::class )` an die Klasse gebunden wird
  und die Naht in einer der zwei erlaubten Formen schreibt oder liest, ordnet der Wächter diese Zeilen der Nahtklasse zu
  und meldet nichts.
- **Warum kein Normalfall:** Der ausgelieferte Baum enthält heute keine Naht; die Zweige 2–9 bringen sie. Code
  ausserhalb jeder Klasse, der nach einer Nahtklasse in derselben Datei steht und die Klasse per `Closure::bind`
  betritt, ist kein Muster des Plugins. In den Testdateien von heute folgt nach der Beispielnahtklasse kein Code.
  Vorgabe für die Zweige 2–9: nach einer Nahtklasse kein Code ausserhalb einer Klasse in derselben Datei; die
  Codex-Runde jedes Zweigs prüft den Durchsetzungsort. Nach der festen Endregel (Entscheid der Steuerung zu Behebung 2)
  öffnet ein Befund ausserhalb des Normalfalls keine weitere Runde.
- **Was die neue Hilfe tut:** Nichts darüber hinaus.
