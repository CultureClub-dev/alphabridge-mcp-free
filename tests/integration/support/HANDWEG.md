# Regel für den Shell-Lauf der Handwege

Festgelegt in Z8b Zweig 1 (Entscheid der Lead 21:21, F-B). Zweig 3 baut die Anleitung `WIEDERHERSTELLEN.txt` (plan.md,
E6) und übernimmt diese Regel oder ersetzt sie; dann ändert er `class-it-handweg.php` und diese Datei zusammen.
Testcode, geht nicht ins ZIP (`/tests` ist `export-ignore`).

## Form der Anleitung

- Eine Anleitung ist eine Textdatei. Sie darf beliebigen Text für die Person enthalten.
- **Ein Abschnitt je Abbruchpunkt.** Eine Zeile, die nur `[<Punkt>]` enthält, öffnet ihn; `<Punkt>` besteht aus
  Kleinbuchstaben, Ziffern und `-`, beginnt mit Buchstabe oder Ziffer (etwa `[zwischen-den-umbenennungen]`). Der
  Abschnitt endet beim nächsten solchen Kopf oder am Dateiende.
- **Befehlszeilen** beginnen mit `$ ` (Dollar, Leerzeichen). Alles danach ist ein Befehl für die Shell. Andere Zeilen
  eines Abschnitts sind Text für die Person und werden nicht ausgeführt.
- Text vor dem ersten Kopf gehört zu keinem Abschnitt; eine `$ `-Zeile dort wird nie ausgeführt.
- Ein Punkt hat höchstens einen Abschnitt. Ein Abschnitt ohne Befehlszeile ist ein Fehler: ein Handweg ohne Befehl
  ist kein Weg.

Beispiel:

```
Einheit: ab-it-aktiv (Plugin)

[zwischen-den-umbenennungen]
Das Ziel fehlt, die Sicherung ist vollständig. Zurücklegen:
$ test ! -e wp-content/plugins/ab-it-aktiv
$ mv ab-mcp-store/backups/ab-it-aktiv wp-content/plugins/ab-it-aktiv
Prüfen: Die Datei ab-it-aktiv.php liegt wieder am Ziel.
$ test -f wp-content/plugins/ab-it-aktiv/ab-it-aktiv.php
```

## Ausführung

- Die Befehle eines Punkts laufen in ihrer Reihenfolge in **einer** Shell: `bash -euo pipefail -c <Befehle>`, je
  Befehl eine Zeile. Der erste scheiternde Befehl beendet den Lauf (`-e`), eine unbekannte Variable ist ein Fehler
  (`-u`), ein Fehler in einer Pipe zählt (`pipefail`).
- Arbeitsordner ist der Ordner, den der Test angibt (etwa die Wurzel der Site); die Umgebung ist die des Tests.
- Zeitgrenze je Punkt (Vorgabe 60 s). Die Shell läuft in einer eigenen Prozessgruppe; an der Zeitgrenze und an ihrem
  Ende beendet die Hilfe die ganze Gruppe über ihre ID, also auch Befehle, die sie im Hintergrund gestartet hat.
- Rückgabe: Status (`null` bei Signal oder Zeitgrenze), Signal, ob die Zeitgrenze griff, stdout, stderr und die
  ausgeführten Befehle. Der Test prüft danach den Zustand (Ordner am Ziel, Sicherung, Marke), nicht nur den Status.

## Gebrauch im Test

```php
$back = AB_MCP_IT_Handweg::run_point( $anleitung, 'zwischen-den-umbenennungen', AB_MCP_IT_Sites::docroot( 'a' ) );
self::assertSame( 0, $back['status'], $back['stderr'] );
```
