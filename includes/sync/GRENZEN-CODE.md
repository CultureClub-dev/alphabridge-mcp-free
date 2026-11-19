# Grenzen: Code und Dateien (Z8b)

Benannte Grenzen des Austauschs von Code und Dateien (Plan Z8b, Fassung 4i, Abschnitte 6, 8.2 und 14). Angelegt mit
Zweig 3 (`heikel/z8b-ablage`); Zweig 4 ergänzt die Grenzen des Codepakets und der Bereitstellung; Zweig 5 ergänzt die Grenzen aus E1 und E6, Zweig 6 das Entfernen (E7). Hierher kommen
auch die Grenzkandidaten der Codex-Runden der Zweige: Befunde, die weder den Normalfall noch eine Kernzusage treffen
(gleichzeitiger Schreiber ohne Sperre, exotische Dateiform, Fehler nur unter Fehlerinjektion), statt einer weiteren
Runde (`knowledge/conventions.md`, «Heikle Zweige», Punkt 4).

Je Eintrag: Name, aktuelle Fundstelle (Stand der Zeile im Baum, in dem der Eintrag geschrieben wurde; die Funktion ist
die dauerhafte Angabe), Quelle, «trifft Normalfall» und Begründung. Wo die Einstufung keine Messung ist, steht es dabei.

## Die Ablage (Zweig 3)

### G-3-1 Mount-Grenze mit gleicher Gerätenummer

- **Fundstelle:** `AB_MCP_Protected_Store::write_file()` (Prüfung der Gerätenummer von `<Ablage>/tmp/` und Zielordner
  vor dem Umbenennen) und `AB_MCP_Protected_Store::put_tree()` (Gerätenummer von Ablage und Ordner der Einheit,
  `code:store_device`), `includes/class-protected-store.php`.
- **Quelle:** Plan Abschnitt 6 und Z37 (Codex-Runde 12); Abschnitt 13, Zeile «Zweig 3 und 5».
- **Was:** Die Zusagen zum Schreiben der Ablage setzen voraus, dass die Ablage ein gewöhnliches Verzeichnis ohne innere
  Mount-Grenze ist und dass zwischen Ablage und Ordner einer Einheit keine Mount-Grenze liegt. Unter Linux meldet ein
  Bind-Mount desselben Dateisystems dieselbe Gerätenummer; `rename(2)` scheitert dort trotzdem mit `EXDEV` («rename()
  does not work across different mount points, even if the same filesystem is mounted on both», Linux-Handbuchseite
  `rename(2)`, Fehler `EXDEV`). PHP behandelt `EXDEV` in `php_plain_files_rename()` (`main/streams/plain_wrapper.c`):
  `php_copy_file()`, danach Eigentümer und Modus der Quelle, und erst wenn alles gelang `unlink()` der Quelle.
  - **Datei:** Sie wird kopiert und danach gelöscht, nicht in einem Schritt ersetzt; zwischen Kopie und Löschen liegen
    beide, und ein Abbruch in der Kopie lässt ein halbes Ziel. Die Prüfung in `write_file()` sieht das nicht. Ein
    `EPERM` beim Setzen von Eigentümer und Modus duldet PHP; scheitert eines davon anders, gibt `rename()` `false`, und
    die Kopie bleibt am Ziel. Das Löschen der Quelle prüft PHP nicht: scheitert es, gibt `rename()` `true`, und die
    Quelle bleibt liegen.
  - **Ordner:** `php_copy_file_ctx()` (`ext/standard/file.c`) lehnt einen Ordner als Quelle ab («The first argument to
    copy() function cannot be a directory», `FAILURE`); `rename()` gibt dann `false` mit einer Warnung, und nichts ist
    verschoben. Ein Tausch eines Ordners über eine solche Grenze scheitert also, statt nicht atomar zu kopieren: schon
    die erste Umbenennung (`AB_MCP_Sync_Code_Swap::rename_marked()` → `move()` → `AB_MCP_Sync_Code_Swap_Calls::rename()`,
    `includes/sync/class-sync-code-swap.php:1422`, `:1506`, `:257`, Stand Teil G, Abschnitt 2), mit zwei Warnungen und
    ohne Bewegung.
  - **PHP 8.0 bis 8.4:** Gelesen am 16.11.2026 in den Tags php-8.0.0, 8.0.30, 8.1.0, 8.1.34, 8.2.0, 8.2.35, 8.3.0,
    8.3.36, 8.4.0 und 8.4.27 (`raw.githubusercontent.com/php/php-src/<Tag>/…`; Zeilen je Tag im Bauzettel
    `belege/z5r-g-zettel.md`, Abschnitt 4, etwa php-8.0.0 `plain_wrapper.c:1204`, `:1240`, `:1247`, `:1275` und
    `file.c:1633`, `:1650`, php-8.4.27 `plain_wrapper.c:1323`, `:1360`, `:1367`, `:1397` und `file.c:1508`, `:1526`).
    Der Weg ist in allen zehn gleich; sie unterscheiden sich nur im Leerraum eines Kommentars und, ab 8.3.36, in der
    Form der Warnungen. PHP vergleicht keine Gerätenummer; es entscheidet allein `EXDEV` von `rename(2)`. Nicht in der
    CI geprobt.
- **Trifft Normalfall:** nein. Eine Ablage mit innerer Mount-Grenze oder ein Bind-Mount zwischen `wp-content` und dem
  Ordner neben WordPress ist eine eigene Einrichtung eines Hosters. F54 prüft diesen Fall ausdrücklich nicht.

### G-3-2 Prüfdateien der Selbstprüfung nach einem harten Ende

- **Fundstelle:** `AB_MCP_Protected_Store::self_check()`; die Dateien werden im `finally` über `delete_path()` entfernt.
- **Quelle:** Vorabprüfung von Zweig 3 (V3), Test `StoreTreesTest::testAnEndOfTheProcessInTheSelfCheckLeavesItsTestFiles
  WhichTheNextCheckDoesNotNeed`.
- **Was:** Endet der Prozess während der Selbstprüfung hart (Signal, Zeitlimit), bleiben die Kontrolldatei neben der
  Ablage in den Uploads (Zufallsinhalt), die drei Prüfdateien und die beiden PHP-Prüfdateien mit ihren Ordnern unter
  `stage` und `backups` liegen. Keine Bereinigung kennt sie. Die nächste Prüfung braucht sie nicht und gelingt daneben.
  Die PHP-Prüfdatei gibt beim Ausführen nur einen Text aus.
- **Trifft Normalfall:** nein (harter Abbruch in einem Fenster von höchstens sechs Anfragen zu je fünf Sekunden).

### G-3-3 Aufbauordner nach einem harten Ende des Erstaufbaus

- **Fundstelle:** `AB_MCP_Protected_Store::ensure()`.
- **Quelle:** Vorabprüfung von Zweig 3 (V4).
- **Was:** Endet der Prozess hart zwischen dem Anlegen des Aufbauordners (`.ab-tmp-<Name>.<hex>` neben der künftigen
  Ablage) und seiner Umbenennung, bleibt er liegen; keine Bereinigung entfernt ihn. Er wird nie als Ablage genommen
  (anderer Name; die Eigentumsdatei wird zuletzt geschrieben, nach den Wachdateien, so dass sie in den Uploads nie
  ohne Wachdateien liegt). Die nächste Anfrage legt die Ablage neu an.
- **Trifft Normalfall:** nein (harter Abbruch im Erstaufbau).

### G-3-4 Halber Baum nach einem harten Ende beim Kopieren

- **Fundstelle:** `AB_MCP_Protected_Store::put_tree()` mit `copy_tree()`.
- **Quelle:** Test `StoreTreesTest::testAnEndOfTheProcessInTheMiddleOfACopyLeavesAHalfTreeThatIsNeverUsed`.
- **Was:** Endet der Prozess hart mitten im Kopieren, bleibt der halbe Baum in seiner Gruppe, bis die Gruppe nach ihrer
  Aufbewahrung geht. Er wird nie benutzt: `tree()` gibt ihn nur mit dem Hash zurück, den `put_tree()` gab, und den gab
  es nicht; `put_tree()` legt keinen Baum über ihn. Der Aufrufer nimmt einen anderen Namen oder eine neue Gruppe.
- **Trifft Normalfall:** nein.

### G-3-5 Schreiber ausserhalb von AlphaBridge

- **Fundstelle:** die ganze Ablage; besonders `AB_MCP_Protected_Store::put_back()` (Prüfung «Ziel fehlt» über
  `listed()`, dann `rename()`) und `delete_path()` (Marken gelesen, dann gelöscht).
- **Quelle:** Plan 8.4 («Code ausserhalb von AlphaBridge … bleibt eine Grenze»); Test `StoreRemoverTest::
  testAFolderThatAppearsAtTheTargetIsNotWrittenOver`.
- **Was:** Hoster, FTP oder andere Plugins können in der Ablage schreiben oder löschen, ohne Sperre und ohne Marke.
  AlphaBridge ordnet nur die eigenen Schreiber unter der Sperre der Ablage. Legt jemand zwischen Prüfung und
  Umbenennung einen Ordner mit Inhalt an das Ziel, scheitert die Umbenennung (ein Ordner geht nicht über einen
  vollen Ordner); bei einer Einheit aus einer Datei ginge eine dort neu angelegte Datei verloren.
- **Trifft Normalfall:** nein (gleichzeitiger Schreiber ohne Sperre).

### G-3-6 Temporäre Dateien der Fassung vor Zweig 3

- **Fundstelle:** `AB_MCP_Protected_Store::sweep()` (nur noch `tmp/` mit Präfix).
- **Quelle:** Z37 («nie nach ihrer Endung»), Umbau in Zweig 3.
- **Was:** Abgebrochene Schreibvorgänge der Fassung vor Zweig 3 liessen `<Datei>.<hex>.tmp` neben dem Ziel (in Gruppen
  und in der Wurzel der Ablage), `.ab-test-write-<hex>.tmp` und `resources-<Prozess>.tmp` in der Wurzel. Die Bereinigung
  entfernt nach der Regel von Z37 keine Datei nach ihrer Endung mehr. In Gruppen gehen sie mit der Gruppe; in der
  Wurzel bleiben sie. Betroffen sind nur Ablagen des internen Stands 4.18.0 (Plan 3.1, Zu Z37).
- **Trifft Normalfall:** nein.

### G-3-7 Gruppe ohne Ordner nach einem Throwable beim Anlegen

- **Fundstelle:** `AB_MCP_Protected_Store::open()`.
- **Quelle:** Test `StoreRemoverTest::testAGroupThatCannotBeMadeLeavesNoSidecarAndTouchesNoTree`.
- **Was:** Wirft das Anlegen des Gruppenordners (nur unter Fehlerinjektion), bleibt die schon geschriebene
  Begleitdatei liegen: eine leere Gruppe, die die Bereinigung nach ihrer Zeit entfernt. Gibt das Anlegen `false`,
  entfernt `open()` die Begleitdatei sofort.
- **Trifft Normalfall:** nein (Fehler nur unter Fehlerinjektion).

### G-3-8 Grosser Baum in einem Aufruf

- **Fundstelle:** `AB_MCP_Protected_Store::put_tree()`: Lesen der Quelle und der Kopie mit `TREE_BUDGET` (45 s je
  Lesung, wie eine Einheit der Umgebung), das Kopieren selbst ohne eigene Zeitgrenze.
- **Quelle:** Bau von Zweig 3; Codex-Runde 1, C1; gemessen in Behebung 1.
- **Was:** Ein Baum wird in einem Aufruf kopiert und zweimal gelesen (Manifest mit SHA-256 jeder Datei vor und nach
  der Kopie). Ein Baum, der dafür nicht in die Zeit eines Aufrufs passt, wird nicht gelesen (`too_large`, nichts
  kopiert) oder endet wie G-3-4. Zweig 4 entscheidet, ob die Bereitstellung über mehrere Aufrufe gebaut wird; dann
  bleibt `put_tree()` der eine Eingang.
- **Lokale Messwerte** (12.11.2026, Mac mit Apple M1, 8 Kerne, 8 GB, APFS auf SSD, PHP 8.5.10, Integrationsumgebung
  der Ablage neben WordPress; synthetische Bäume ohne Downloads: 80 % PHP-Dateien von 1 bis 12 KB Text, 20 % Bilder als
  Zufallsbytes bis zur Gesamtgrösse, Ordner zu 40 Dateien in zwei Ebenen; je drei Läufe, Median, in Klammern Spanne;
  die Quelle war unmittelbar vorher geschrieben, der Seitencache nicht geleert):
  - 3001 Dateien, 30,1 MB: eine Lesung mit Hashes 0,28 s; `put_tree()` mit Lesen, Kopie und Rücklesen **1,53 s**
    (1,48–1,60 s);
  - 8001 Dateien, 79,9 MB: eine Lesung 0,75 s; `put_tree()` **3,91 s** (3,81–4,35 s).
- **Hochrechnung (Annahme, nicht gemessen):** mit dem Faktor 2 für langsamere Hosts rund 3,1 s und 7,8 s. Ein Host
  ohne Seitencache für die Quelle, mit langsamem Netzwerkspeicher oder mit vielen kleineren Dateien kann davon
  abweichen; dafür gibt es keine Messung.
- **Zeitbudget einer Anfrage: unbelegt.** Übliche Grenzen (`max_execution_time` 30 s, Zeitgrenzen von Proxy und
  Webserver) sind für die Hosts der Nutzer nicht gemessen.
- **Trifft Normalfall:** nein (Entscheid der Lead 12.11.2026 nach Messung). «Normalfall nein» trägt, weil die Zeit
  reicht und der Fehlweg sicher ist, nicht weil grosse Bäume selten wären: 8000 Dateien sind nicht selten;
  WooCommerce und Builder-Pakete liegen in dieser Grössenordnung. Begründung:
  - Gemessen sind 3,91 s für 8001 Dateien und 79,9 MB, lokal.
  - Mit dem angenommenen Faktor 2 sind es rund 7,8 s, also etwa ein Viertel der üblichen `max_execution_time` von 30 s.
  - Ein Host mit langsamem Netzwerkspeicher kann davon abweichen; das ist nicht gemessen.
  - Der Fehlweg bleibt sicher: `too_large` ohne Kopie, oder G-3-4 mit halbem Baum, ohne Verlust des Originals.
  - Es ist ein reiner Zeitbefund und darf nach der Merge-Regel heikel (12.11. 07:26) Grenze bleiben.
  - Zweig 4 misst `put_tree()` mit einem echten grossen Plugin auf einem gehosteten Server. Liegt das mit Faktor 2
    über 10 s, baut Zweig 4 die Bereitstellung über mehrere Aufrufe.
- **Gemessen in Zweig 4** (13.11.2026, von der Steuerung auf ab-unterordner, Infomaniak, PHP 8.4.27 fpm,
  `max_execution_time` 60, Ablage «outside», Rohantworten `~/Entwicklung/wt-ab/z8b-zweig-4-messung/belege/
  messung-ab-unterordner.json`): WooCommerce 11.3.0, 5452 Dateien, 56'489'851 B: `put_tree()` 2,367 / 2,024 /
  2,092 s, eine Lesung 0,156–0,166 s, Spitzenspeicher 82 MB; mit Faktor 2 rund 4,7 s. `put_tree()` bleibt in einem
  Aufruf (Entscheid der Lead 13.11.2026 01:49); die Grenze für langsamere Hosts ist G-4-3.

### G-3-9 Ordner der Ablage nach einer Deinstallation ohne Sperre

- **Fundstelle:** `AB_MCP_Protected_Store::uninstall()` mit `uninstall_locked()` (Sperre mit `LOCK_NB`, erneut alle
  0,1 s, `UNINSTALL_WAIT` = 10 s für die ganze Deinstallation, alle Ordner zusammen), `includes/class-protected-store.php`.
- **Quelle:** Codex-Runde 1 von Zweig 3, B3; Behebung 1. Tests `StoreRemoverTest::
  testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs` und `testTheUninstallDoesNothingWhereTheLockCannotBeOpened`.
- **Was:** Lässt sich die Sperre eines Ordners nicht öffnen (`store.lock` ist ein Ordner, eine Verknüpfung oder ohne
  Rechte), scheitert sie aus einem anderen Grund als einem Halter, oder hält ein anderer Prozess sie über das Ende der
  10 s hinaus, läuft für diesen Ordner nichts: kein Original geht zurück, nichts wird entfernt. Nach der Deinstallation
  bleibt der Ordner mit Eigentumsdatei, Wachdateien, Marken, Sicherungen und Anleitungen (`WIEDERHERSTELLEN.txt`)
  liegen, der Handweg bleibt möglich. Das Plugin ist danach weg, die tägliche Bereinigung auch; den Ordner entfernt
  dann nur noch eine Person von Hand oder eine neue Installation mit erneuter Deinstallation. Die Optionen gehen wie
  bisher mit `uninstall.php`.
- **Trifft Normalfall:** nein, nach Einschätzung (nicht gemessen): WordPress deinstalliert nur ein deaktiviertes
  Plugin; die Sperre hält dann höchstens eine Anfrage, die vor der Deaktivierung begann und noch schreibt (etwa
  `put_tree()` eines grossen Baums, G-3-8), oder ein Hoster, der `flock()` oder die Rechte in dem Ordner verweigert.
  Die Alternative, ohne Sperre weiterzumachen, nähme einem laufenden Schreiber die Dateien unter der Hand weg
  (B3 der Codex-Runde 1); die Alternative, unbegrenzt zu warten, liesse die Deinstallation im Zeitlimit der Anfrage
  enden.

### G-3-10 Neuer Direktzugriff ausserhalb der Tabelle der Eingänge

- **Fundstelle:** `AB_MCP_Store_Inputs::TABLE` (`includes/class-store-inputs.php`) mit der Formlesestelle
  `AB_MCP_Shape::read()` (`includes/class-shape.php`); die Verbraucher in `includes/class-protected-store.php`
  (`kept()`, `inputs()`, `sweep()`, `delete_path()`, `guard()`, `read_mark()`, `sidecar()`, `entries()`, `stock()`,
  `open()`, `put()` mit `held()`), `includes/class-store-budget.php` (`book()`, `release()`, `package_refusal()`,
  `bookings()`), `includes/sync/class-sync-backup.php` (`group()`, `fits()`), `includes/sync/class-sync-schema.php`
  (`open_runs()`, `backup_rows()`, `keep_until()`) und `AB_MCP_Sync_Run::now()`.
- **Quelle:** Behebung 3 von Zweig 3, Fassung 2a, Abschnitt 6.5 (Entscheid der Lead: kein Tokenizer-Wächter);
  Behebung 4 (Option C1, Entscheid Thomas 12.11.): die Tabelle ersetzt die Liste `RETENTION_INPUTS` als Ort aller
  Eingänge von Aufbewahrung, Schutz, Dateizeiten, Budget und Uhr der Läufe.
- **Was:** Jeder dieser Eingänge steht als Zeile (Verbraucher, Quelle, Feld, Form) in der Tabelle und wird über
  `AB_MCP_Store_Inputs::read()` gelesen: Wert in seiner Form oder `null`, ohne Cast und ohne Ersatzwert. Der erzeugte
  Gegentest (`StoreInputsTest`, Unit und Integration) liest die Tabelle aus dem Code, verlangt für jede Zeile einen
  Treiber bis zu ihrem Verbraucher und führt jede falsche Form ihrer Form dorthin; eine neue Zeile ist rot, bis ein
  Treiber sie erreicht. Zwei Grenzen bleiben. Erstens fällt ein neuer Wert, der einen Entscheid trägt und nicht über
  die Tabelle kommt, durch keinen Test auf: ein neuer Dateizugriff in `sweep()`, `delete_path()` oder `held()`, eine
  neue Option, ein neuer Filter, ein Cast an einer Entscheidstelle. Ihn finden nur die Durchsicht und Codex. Ein Wächter
  über den PHP-Tokenizer hätte eine Liste aller erlaubten Aufrufe gebraucht, die selbst die Lücke geworden wäre
  («Wächterliste ist die Grenze»). Zweitens folgen die erzeugten falschen Formen der Form im Code: wer eine Form
  lockert, lockert sie mit. Dagegen halten die festen falschen Formen je Treiber («fixed: ...» nach der Liste des
  Auftrags), die gültigen Werte je Zeile und die Mutationseinträge der Formen (`ablage/table-*`, `ablage/a1r-*`,
  `ablage/input-*`); eine gelockerte Form, gegen die kein fester Fall steht, bleibt unbemerkt. Seit Behebung 5b halten
  zwei Wächter über die Tokens des Quelltexts (`StoreClockAndReadersTest`, `token_get_all()`): in den Dateien seiner
  Liste (die berührten des Zweigs und `class-sync-export.php`) kein `time()` ausser der Uhr `AB_MCP_Sync_Run::now()`
  und den benannten Ausnahmen, und jede Methode in `includes/`, die eine Zeile der Tabelle oder die Uhr liest, hat
  ihren Treiber oder ihren Grund. Ihre Reichweite ist die Grenze: ein `time()` in einer anderen Datei, ein Leser einer
  Funktion, die einen gelesenen Wert weitergibt (etwa `open_runs()`, `stock()`, `groups()`), und eine Zeitquelle,
  die nicht `time()` heisst (`microtime()`, `date()`, `$_SERVER['REQUEST_TIME']`), fallen ihnen nicht auf.
- **Trifft Normalfall:** nein. Es ist eine Grenze der Prüfung, kein Fehlweg im Code: ein Zugriff dieser Art entsteht
  nur mit einer künftigen Änderung. Die Durchsicht einer Änderung an diesen Funktionen prüft, ob jeder neue Wert eines
  Entscheids als Zeile der Tabelle kommt und ob eine geänderte Form ihren festen Fall hat.

## Das Codepaket und die Bereitstellung (Zweig 4)

### G-4-1 Installationsweg ohne `version`

- **Fundstelle:** `AB_MCP_Tools_Install_Update::install_plugin()` und `install_theme()` ohne `version`
  (`includes/tools/class-tools-install-update.php`): `plugins_api()`/`themes_api()` und deren `download_link` an den
  Upgrader des Kerns.
- **Quelle:** Vorabprüfung von Zweig 4 (V1); Entscheid der Lead 13.11.2026 01:43 («bleibt wie heute, Grenze,
  Folgeposten»).
- **Was:** Der Download läuft über `download_url()` des Kerns, nicht über `AB_MCP_Outbound`; er folgt bis zu fünf
  Weiterleitungen, und `plugins_api()` fällt bei einem Fehler von TLS auf `http` zurück, sodass `download_link` aus einer
  unverschlüsselten Antwort stammen kann. Mit `version` gilt der gemeinsame Downloader (`AB_MCP_Sync_Code_Stage::
  fetch_zip()`: https, `AB_MCP_Outbound`, keine Weiterleitung, `ORG_MAX`). Die Bereitstellung der Übernahme nutzt den
  Weg ohne `version` nie (Wächter `CodeStageGuardTest`).
- **Trifft Normalfall:** nein (der Rückfall auf `http` setzt einen gescheiterten TLS-Aufbau voraus). Folgeposten.

### G-4-2 PHP-Dateien über 1 MiB in der Parserprüfung

- **Fundstelle:** `AB_MCP_Sync_Code_Stage::parse_room()` und `parse_check()` (`PARSE_MAX_BYTES` = 1 MiB,
  Speicherprüfung `PARSE_MEMORY_FACTOR` × Grösse gegen `memory_limit`), `check_file()`: geprüft nach der Grösse, die das
  Manifest nennt, bevor die Datei gelesen wird (Behebung 5, GitHub-Codex auf free#153, P2); gelesen wird höchstens
  diese Grösse und ein Byte mehr.
- **Quelle:** Entscheid der Steuerung 13.11.2026 01:44 (O1); D6 (01:43).
- **Was:** Eine Datei über 1 MiB, oder eine, für die der Speicher nicht reicht, ist `skipped` und damit
  `code:syntax_unchecked`: Dass dieselben Bytes auf Staging liefen, belegt nichts (D6). Gemessen (Zentralverzeichnis der
  ZIPs von downloads.wordpress.org, 13.11.2026): grösste PHP-Datei von Yoast SEO 28.8 640'228 B
  (`src/generated/container.php`), All in One SEO 5.0.5 716'612 B, WPForms Lite 2.0.4 525'873 B, WooCommerce 11.3.0
  473'477 B; keine der zehn gezählten Pakete hat eine Datei über 1 MiB. Pro behält 512 KiB
  (`AB_MCP_Pro_Code_File_Guard::PARSE_MAX_BYTES`); die Angleichung ist ein Folgeposten für Pro.
- **Trifft Normalfall:** nein (bei den gemessenen verbreiteten Paketen keine Datei über der Grenze).

### G-4-3 Hosts, die mehr als doppelt so langsam sind wie Infomaniak

- **Fundstelle:** `AB_MCP_Sync_Code_Stage::stage()` (Aufbau und `put_tree()` in einem Aufruf, D4).
- **Quelle:** Messung der Steuerung auf ab-unterordner (13.11.2026, Rohantworten
  `~/Entwicklung/wt-ab/z8b-zweig-4-messung/belege/messung-ab-unterordner.json`); Entscheid der Lead 01:49 (O2: nicht
  fortsetzbar).
- **Was:** Die Bereitstellung baut die Einheit und nimmt sie mit `put_tree()` in einem Aufruf auf. Gemessen auf
  ab-unterordner (PHP 8.4.27 fpm, `max_execution_time` 60, `memory_limit` 640M, Ablage «outside»), WooCommerce 11.3.0
  (5452 Dateien, 56'489'851 B): `put_tree()` 2,367 / 2,024 / 2,092 s, eine Lesung 0,156–0,166 s, Spitzenspeicher 82 MB;
  mit Faktor 2 rund 4,7 s, unter den 5 s des Auftrags. Hosts, die mehr als doppelt so langsam sind, sind nicht gemessen;
  dort kann ein grosser Baum die Zeit eines Aufrufs überschreiten und endet wie G-3-4 (halber Baum, nie benutzt).
  Der Aufbau aus Codepaketen (`from_packages()`, ein Durchgang je Paket über seine Chunks seit Behebung 5) ist lokal
  gemessen (Apple M1, PHP 8.5.10, 13.11.2026): WooCommerce 11.3.0 aus zwei Paketen (5452 Dateien, 16 Chunks)
  2,97 s, Spitzenspeicher 95,2 MiB (Zuwachs 32,2 MiB); `stage()` ganz mit `put_tree()` 6,46 s. Der Weg davor (ein
  Durchgang je Datei) brauchte für dieselben Pakete 220,2 s.
- **Trifft Normalfall:** nein (Messung auf dem Hoster der Live-Sites).

### G-4-4 Ein Aufbau, der länger als TMP_AGE läuft

- **Fundstelle:** `AB_MCP_Protected_Store::delete_path()` mit `temp_gone()` (Alter eines Aufbauordners,
  `tmp.build_mtime`) und `own_temp()`, `build_dir()`, `includes/class-protected-store.php`.
- **Quelle:** D4 (Lead 13.11.2026 01:43 und 01:49).
- **Was:** Ein Aufbauordner gilt nach `TMP_AGE` (1 Stunde, nach seiner eigenen Zeit) als nicht mehr laufend; die
  Bereinigung einer anderen Anfrage nimmt ihn dann mit seinem Inhalt. Ein Aufbau dauert einen Aufruf; nur ein Aufruf
  ohne Zeitgrenze, der länger als eine Stunde baut (etwa WP-CLI), wäre betroffen. Dann scheitert er an der fehlenden
  Quelle (`put_tree()`: nicht gelesen), ohne halben Stand in `stage`.
- **Trifft Normalfall:** nein.

### G-4-5 Pfad mit dem Namen einer Zugangsdatenregel

- **Fundstelle:** `AB_MCP_Sync_Export::code_unit()` (`held_path`).
- **Quelle:** Bau von Zweig 4 (Plan E13: Namen werden geprüft, Inhalte nicht).
- **Was:** Eine Einheit, deren Datei einen Namen trägt, den die Prüfung auf Zugangsdaten hält, geht in kein Codepaket
  (`unread` mit `held_path`); ist sie auch auf wordpress.org nicht in der Version, bleibt sie gesperrt. Weg: von Hand.
- **Trifft Normalfall:** nein.

### G-4-6 Prüfung mit php -l über viele Aufrufe

- **Fundstelle:** `AB_MCP_Sync_Code_Stage::complete()` (`CHECK_BUDGET` 20 s je Aufruf), `AB_MCP_Sync_Code_Stage_Calls::
  lint()` (ein Prozess je Datei).
- **Quelle:** Bau von Zweig 4 (D7).
- **Was:** Wo ein PHP-Kommando gleicher Version da ist, läuft php -l je Datei in einem eigenen Prozess (Pro-Port; PHP
  vor 8.3 kennt kein Lint mehrerer Dateien). Ein Plugin mit tausenden PHP-Dateien braucht dafür mehrere Aufrufe von
  `complete()`; der Fortschritt steht im Zustand der Bereitstellung. Nicht gemessen ist, wie viele Aufrufe das auf
  einem Shared Hosting für WooCommerce (3658 PHP-Dateien) sind.
- **Trifft Normalfall:** nein (Dauer, kein Fehlweg; «bereit» erst nach jeder Datei).

### G-4-7 Budgetprüfung des Exports eines Codepakets ohne eigenen Test

- **Fundstelle:** `AB_MCP_Sync_Export::code_unit()` (`includes/sync/class-sync-export.php`), die Uhr des Budgets der
  Lesung einer Einheit (`UNIT_BUDGET`).
- **Quelle:** Behebung 2 von Zweig 4 (Codex-Runde 1, B4; Entscheid der Lead 13.11.2026 04:26: als benannte Grenze).
- **Was:** `code_unit()` prüft den Messwert der Uhr: ohne endliche Zahl liest es nichts (`unreadable`), es entsteht
  kein Paket der Einheit. Einen eigenen Test hat diese Prüfung nicht: der Export läuft in der Integrationsumgebung auf
  der Staging-Site im Kindprozess (`sync-site.php`), dessen Uhr der Test nicht setzt. Die gleiche Regel der Uhr ist
  an `AB_MCP_Sync_Code_Stage::clock()` und `AB_MCP_Sync_File_Manifest::now()` getestet
  (`StoreClockAndReadersTest::BUDGET`).
- **Trifft Normalfall:** nein (der Weg ist sicher: ohne Lesung kein Paket).

## Der Tausch (Zweig 5)

### G-5-1 Dauerhaft beschädigter Sidecar der Stufe lässt den Schritt «bereitstellen» geplant

- **Fundstelle:** `AB_MCP_Protected_Store::group()` und `no_group()` (`includes/class-protected-store.php`);
  `AB_MCP_Sync_Code_Stage::state()` (`includes/sync/class-sync-code-stage.php`) → `files()` → `group()`;
  `AB_MCP_Sync_Code_Stage::take_in()` und `write_state()`; `AB_MCP_Sync_Code_Ops::stage()`, `not_checked()` und
  `not_staged()` (`includes/sync/class-sync-code-ops.php`); `PERMANENT` in `AB_MCP_Sync_Code_Stage`.
- **Quelle:** gezielte Codex-Nachfrage zu `f24115a` (1 A), geschlossene Codex-Prüfung von K4 (2 A an derselben
  Stelle, Stoppfall), Entscheid Thomas vom 13.11.2026 16:04 («D: Eine ehrliche Meldung», Teil K4d), geschlossene
  Codex-Prüfung von K4d (1 A: der Satz über die Wirkung des Aufrufs), Entscheid der Steuerung in Thomas' Auftrag vom
  13.11.2026 16:28 (Teil K4e), geschlossene Codex-Bestätigung von K4e (1 A: die Klammer über die Aufbewahrung),
  Entscheid der Steuerung in Thomas' Auftrag vom 13.11.2026 16:43 und Fable-Prüfstelle 5 (Teil K4f: die Klammer ist
  gestrichen, die Meldung nennt keine Aufbewahrungsfristen mehr).
- **Was:** Gibt der Sidecar der Gruppe einer Stufe dauerhaft keinen Eintrag seiner Gruppe (nicht lesbar, keine Datei,
  nicht in der Form, ein Eintrag einer anderen Gruppe, oder er fehlt), lehnt `group()` mit `ab_mcp_store_no_group` ab.
  Die Meldung nennt beide Möglichkeiten (die Gruppe ist nicht da: nie geöffnet oder entfernt; oder sie ist da, und ihr
  Eintrag oder ihr Ordner fehlt oder liest nicht) und den Weg für beide und legt sich auf keine fest: der Store
  unterscheidet sie an dieser Stelle nicht. Darüber, was derselbe Aufruf vorher geschrieben hat, sagt sie nichts.
  - Ist die Stufe schon bereitgestellt (das Journal des Schritts steht), ist der Fehler vorübergehend
    (`ab_mcp_store_no_group` steht nicht in `PERMANENT`): `AB_MCP_Sync_Code_Ops::stage()` antwortet über
    `not_checked()`, der Schritt bleibt bei jedem `wp_site_push action=next` `planned`, nie `failed`, und an der Site
    wird nichts geschrieben. Den Ausweg nennt die Meldung des Schritts: `wp_site_push action=abort` schliesst den Lauf
    auch mit dem beschädigten Sidecar (belegt in
    `SyncCodeRunTest::testADamagedSidecarOfTheStageLeavesTheStepPlannedWithItsReasonUntilItIsBackOrTheRunIsAborted`,
    dort mit dem Schaden vor dem Aufruf: auch an der Stufe wird dann nichts geschrieben).
  - Fällt der Schaden in den ersten Aufruf, zwischen `put_tree()` und dem ersten Zustand (`take_in()` →
    `write_state()` → `put()`), gibt `AB_MCP_Sync_Code_Stage::stage()` den Fehler zurück, und
    `AB_MCP_Sync_Code_Ops::stage()` beendet den Schritt über `not_staged()` mit `failed`. Der schon geschriebene Baum
    bleibt in der Gruppe: `take_in()` entfernt nichts. Nach dem Code gelesen, ohne eigenen Test.
    In diesem Fall rät die Meldung des Stores «try again», der Schritt ist aber beendet (`failed`), und die Meldung
    des Schritts führt zum nächsten Schritt (`action=next`): ein neuer Versuch für diese Einheit geht dann nur über
    einen neuen Lauf. Die Meldung sagt das nicht (Befund K4e Nr. 2, nach dem Code gelesen, ohne eigenen Test;
    Folgeposten der Fehlerweg-Prüfung des Teils).
  - Ebenso nach einer Ablehnung von `AB_MCP_Sync_Code_Stage::stage()` selbst (Fable-Prüfstelle 2, C3; Entscheid der
    Lead vom 13.11.2026 18:51): `code:org_unzip` und zwei der drei `code:org_fetch` raten «Try again later, or send the
    unit as a package of code (wp_site_export with purpose=code)», das dritte `code:org_fetch` (wordpress.org gibt das
    Paket dieser Version nicht) rät nur «Send the unit as a package of code (wp_site_export with purpose=code)»;
    `not_staged()` (`class-sync-code-ops.php`) beendet den Schritt mit `failed` und hängt «The run goes on
    with the next step (action=next).» an. Ein neuer Versuch für diese Einheit geht auch hier nur über einen neuen
    Lauf. Folgeposten für Teil 2: `run_verify()` hängt beim Schliessen eines Laufs «The way back for its pairs is
    wp_site_revert (action=plan with this run_id).» an (`class-sync-engine.php:2812`), auch bei einem Code-Lauf, von
    dem kein Schritt etwas geschrieben hat. Seit Teil H (G3 von GitHub Codex auf #155) hat ein Code-Lauf einen
    eigenen Schlusssatz (`closing()`, ohne Stichprobe); der angehängte Satz zum Rückweg bleibt für beide Arten.
    Nach dem Code gelesen, ohne eigenen Test.
    **Trifft Normalfall:** nein.
  - B (kein Normalfall, keine Kernzusage): `read_entry()` gibt auch für einen formgerechten Eintrag eines anderen
    Bereichs null (`sidecar()`, Zustand `other`: der Eintrag nennt selbst einen anderen Bereich als den, in dem er
    liegt), und `group()` lehnt dann ebenso mit `ab_mcp_store_no_group` ab. Die Aufzählung der Meldung («is missing, is
    no plain file, cannot be read, is not of its form or names another group») nennt diesen Fall nicht wörtlich.
    Nötig ist ein fremder Schreiber oder ein Schaden auf der Platte; der Weg der Meldung («ask the host to check the
    folder of the protected store») gilt auch dafür. Die Tests erwarten den Teilsatz wörtlich
    (`ProtectedStoreTest`, `SyncCodeRunTest`), darum Vermerk statt Textänderung.
  Die genaue Trennung «fehlt / beschädigt / dauerhaft» (und damit ein `failed` für einen dauerhaft formwidrigen
  Sidecar) ist Folgeposten der Fehlerweg-Prüfung des Teils, zusammen mit `sidecar()`.
- **Trifft Normalfall:** nein. Der eigene Code schreibt einen Sidecar nur ganz (`write_entry()` über eine Temp-Datei,
  `bind()` prüft `whole()` vorher); nötig ist ein Schaden auf der Platte oder ein fremder Schreiber nach dem
  Bereitstellen. Der Weg bleibt fail-closed: kein Tausch ohne als bereit gelesene Stufe.

### G-5-2 Meldungen der Ablage und des Transports, die nicht in jedem Fall stimmen: eigener Zweig

- **Quelle:** Codex-Bestätigung und gesammelte Prüfung über K4f (`7d9fcc2..6f693f1`, 13.11.2026: B1 bis B7, A3 bis A10,
  B8; Wortlaut: `uebergaben/anhang/2026-11-11-z8b/codex-zweig-5-code-6f693f1.md` im knowledge-Repo) und Befund des
  Teils K4h; Entscheid der Steuerung in Thomas' Auftrag vom 13.11.2026 17:25 (Punkt 1: die B auf dem Pfad von Zweig 5
  als Grenze; Punkt 2: eigener Zweig «Meldungen der Ablage und des Transports» nach Zweig 5 und vor `text/z8b`, der
  alle Meldungen seines Bereichs in einem Durchgang prüft und behebt; bis dahin benannte Grenze). Teil K4g hat für B1
  bis B7 je Eintrag am Code gelesen, ob er über `wp_site_push` oder die Stufe erreichbar ist (Zeilen am Stand von
  K4g), für A3 bis A10 und B8 nur, dass Code und Wortlaut an der genannten Zeile stehen; keiner der Fälle ist
  nachgestellt.
- **Auf dem Weg des Tauschs** (B1 bis B7, alle in `includes/class-protected-store.php`): In jedem Fall lehnt die Ablage
  ab (fail-closed); betroffen ist nur, was die Meldung als Grund oder Weg nennt. Keine Kernzusage ist berührt (Codex:
  überall nein). **Trifft Normalfall:** nein, je Eintrag begründet.
  - **`put()`, `ab_mcp_store_write` (`:1039`), «so it was taken out again».** Fall: die Datei ist geschrieben, der
    Sidecar lässt sich danach nicht schreiben (`write_entry()`), und auch `delete_path()` (`:1038`) entfernt die Datei
    nicht; sein Ergebnis wird nicht geprüft. Dann liegt die Datei noch da, die Meldung sagt das Gegenteil. Erreichbar
    über `AB_MCP_Sync_Backup::put()` (`includes/sync/class-sync-engine.php:1194`, Sicherung eines Schritts) und über
    `AB_MCP_Sync_Code_Stage::write_state()` (`includes/sync/class-sync-code-stage.php:1492`, Zustand der Stufe).
    **Trifft Normalfall:** nein (braucht zwei Schreibstörungen in Folge).
  - **`entry()`, `ab_mcp_store_entry` (`:1162`), Aufzählung «cannot be read, is not there, or is not of its form».**
    Fall: der Sidecar liest ganz und in seiner Form, nennt aber einen anderen Bereich (`sidecar()`: `other`) oder eine
    andere Gruppe (`$gid !== $read[1]['gid']`, `:1161`); die Aufzählung nennt beides nicht. Erreichbar über
    `AB_MCP_Sync_Code_Stage::foreign()` (aus `complete()` und `ready()`). **Trifft Normalfall:** nein (fremder Schreiber
    oder Schaden auf der Platte).
  - **`no_group()`, `ab_mcp_store_no_group` (`:4576`).** Die zwei Vermerke stehen in G-5-1: der Zustand `other` fehlt
    in der Aufzählung, und «try again» nach einem mit `failed` beendeten Schritt geht nur über einen neuen Lauf.
    **Trifft Normalfall:** nein.
  - **`tree()`, `ab_mcp_store_tree_missing` (`:2235`), «it was never put there, or its group was removed after its
    time. Use put_tree() to put it there again».** Fall: `lstat()` des Baums gibt nichts, obwohl `group()` die Gruppe
    eben gefunden hat: der Baum allein ist entfernt (fremder Eingriff), oder der Pfad lässt sich wegen der Rechte nicht
    ansehen. Keiner der zwei genannten Gründe trifft dann zu; «after its time» ist eine Fristaussage (Gruppen gehen
    auch über `remove()` und die Deinstallation); `put_tree()` ist eine Funktion der Ablage, kein Werkzeug, und ein
    Baum der Sicherungen lässt sich nicht noch einmal ablegen. Einzige Aufruferin: `AB_MCP_Sync_Code_Stage::complete()`
    (`includes/sync/class-sync-code-stage.php:1049`), dort bleibt der Schritt `planned` (der Code steht nicht in
    `PERMANENT`), der Weg des Schritts ist `action=next` oder `abort`. **Trifft Normalfall:** nein.
  - **`build_dir()` und `temp_file()`, `ab_mcp_store_tmp` (`:2056`, `:2917`), «is missing or is a link».** Fall:
    `way()` gibt auch dann nichts, wenn `tmp` eine gewöhnliche Datei ist, wenn `lstat()` wegen der Rechte scheitert
    oder wenn `holds()` den Ordner nicht der Ablage zuordnen kann; dann fehlt `tmp` nicht und ist kein Link.
    Erreichbar über `AB_MCP_Sync_Code_Stage::stage()` (`:762`, Aufbauordner) und über jedes Schreiben der Ablage
    (`write_file()` → `temp_file()`). Der Weg der Meldung («ask the host to check the folder of the store») gilt auch
    dafür. **Trifft Normalfall:** nein.
  - **`copy_tree()`, `ab_mcp_store_tree_unread` (`:2314`), «The source of the tree is gone».** Fall: `lstat()` der
    Quelle scheitert nach einer Änderung der Rechte, die Quelle liegt noch da. Erreichbar über
    `AB_MCP_Sync_Code_Stage::take_in()` → `put_tree()`; die Quelle ist dort der Aufbauordner derselben Anfrage und
    wurde unmittelbar davor ganz gelesen. **Trifft Normalfall:** nein.
  - **`unsafe()`, `ab_mcp_store_link` (`:4400`), «is a link, or lies outside the store … remove the link».** Fall:
    `way()` gibt auch für einen Ordner nichts, der fehlt oder sich nicht ansehen lässt (etwa ein nachträglich
    entfernter Bereich, `group()` `:4307`–`:4310`); dann gibt es keinen Link, und «remove the link» nennt ein
    Hindernis, das nicht besteht. Erreichbar über jeden Zugriff auf eine Gruppe (`group()`, `entry()`).
    **Trifft Normalfall:** nein (braucht einen Ordner der Ablage, der fehlt oder sich nicht ansehen lässt).
- **Fristen und Bestand** (A3 bis A10, B8, K4h). **Trifft Normalfall:** A3 bis A10 nach Codex ja (gewöhnliche
  Bedienfolgen), Kernzusage nein: sie werden in dem genannten Zweig behoben und sind bis dahin als Grenze geführt,
  nicht als hingenommen. B8: nein.
  - A3, `ab_mcp_store_quota` (Exporte), `includes/class-store-budget.php:159`: «An export counts until it is removed, a
    day after it was made, or later while a run that is not closed uses it» nennt einen festen Termin; nach Codex
    entfernt erst ein Aufräumen nach Alter, und eine neu bestätigte Paarung entfernt Exporte früher.
  - A4, `ab_mcp_store_quota` (Pakete), `includes/class-store-budget.php:174`: «A package counts until it is removed»;
    nach Codex zählt ein geschlossenes Paket nicht mehr, obwohl es noch liegt.
  - A5, `ab_mcp_store_quota` (Bytes), `includes/class-store-budget.php:188`: «the open packages of this site … hold
    %3$d»; nach Codex ist die Zahl die Summe der deklarierten Grössen aller vorhandenen Pakete.
  - A6, `ab_mcp_store_space`, `includes/class-store-budget.php:239`: «wait until packages and reports are removed (a
    day after they were made)»; nach Codex bleiben Pakete offener Läufe länger, Berichte gehen auch früher.
  - A7, `ab_mcp_sync_export_unknown`, `includes/sync/class-sync-export.php:1927`: «This site keeps no package of that
    id … packages are kept a day»; nach Codex liegt ein abgelaufenes Paket bis zum Aufräumen noch da. Dazu
    `ab_mcp_sync_export_index` (`:207`): jeder Lesefehler der Ablage wird zu «is no longer in the protected store».
  - A8, `ab_mcp_sync_import_unknown`, `includes/sync/class-sync-import.php:369`: «packages are kept a day»; nach Codex
    hält ein offener Lauf seine Pakete länger, und der beschriebene Weg nennt nicht alle nötigen Angaben.
  - A9, `ab_mcp_sync_pairs_unknown`, `includes/sync/class-sync-proposal.php:2525`: «a proposal is kept a day at most»;
    nach Codex gilt die Frist für die Gültigkeit, nicht für das Liegen in der Ablage.
  - A10, `ab_mcp_sync_report_unknown`, `includes/sync/class-sync-report.php:989`: «a report is kept a day at most, and
    a new one of the same connection replaces it»; nach Codex wie A9, und zwei gleichzeitige Aufrufe können zwei
    Berichte hinterlassen.
  - B8, `close_mark()`, `ab_mcp_store_mark_read`, `includes/class-protected-store.php:2561`: «so it stays open»; nach
    Codex kann die Marke auf der Platte schon `closed` sein.
  - Befund des Teils K4h (Entscheid der Lead: in diesen Zweig), `ab_mcp_store_tree_exists`,
    `includes/class-protected-store.php:1939`: «Use another name for it», ebenso G-3-4 («einen anderen Namen oder eine
    neue Gruppe»); für einen Baum aus einer Datei geht ein anderer Name nicht, `put_tree()` lehnt ihn mit
    `ab_mcp_store_tree_name` ab (`:1954`), gangbar ist nur eine neue Gruppe. Über `wp_site_push` nicht erreichbar: der
    einzige Aufrufer im Produkt, `AB_MCP_Sync_Code_Stage::take_in()` (`includes/sync/class-sync-code-stage.php:974`),
    legt in eine Gruppe, die `stage()` je Aufruf neu öffnet (`:752`). Am Code von K4h gelesen, nicht nachgestellt.
    **Trifft Normalfall:** nein (braucht einen liegen gebliebenen Baum).

### G-5-3 Meldungen der Ablage und des Transports mit Aussagen über Fristen und Bestand — in G-5-2

- **Grund:** Mit G-5-2 zusammengelegt (Qualitätsdurchsicht vor Teil H, `belege/z5r-q-liste.md` Nr. 22): die Quelle
  steht dort in der gemeinsamen Quelle, Einträge und Normalfall unter «Fristen und Bestand». Die Nummer bleibt, ohne
  Umnummerierung.

### G-5-4 Verwaiste Gruppe der Stufe, wenn das Journal nach `stage()` nicht gespeichert wird

- **Fundstelle:** `AB_MCP_Sync_Code_Ops::stage()` (`includes/sync/class-sync-code-ops.php:192`–`:292`):
  `AB_MCP_Sync_Code_Stage::stage()` (`:196`), Prüfung des Journals (`:222`), `AB_MCP_Sync_Run::update_step()` (`:225`);
  der Tausch `AB_MCP_Sync_Code_Ops::swap()` (`:310`–`:326`) mit `staged()` (`:95`). Zeilen am Stand von Teil D2b des
  Rests (Z5r).
- **Quelle:** Fable-Prüfstelle 2 über `90ab733..d1e0c77`, B2; Entscheid der Lead vom 13.11.2026 18:51 (Teil 1a-4d).
- **Fall:** `stage()` hat eine Gruppe im Bereich `stage` geöffnet und den Baum abgelegt; danach gibt `update_step()`
  einen Fehler (`:226`–`:228` gibt ihn zurück) oder die Anfrage endet vor dem Speichern (Zeitgrenze). Der Schritt
  bleibt `planned` ohne Journal (`parts` null); der nächste Aufruf stellt die Einheit noch einmal bereit, in einer
  neuen Gruppe (`stage()` öffnet je Aufruf eine, `class-sync-code-stage.php:775`).
- **Wirkung:** Die erste Gruppe mit ihrem Baum bleibt liegen; dieser Schritt und der nächste Aufruf entfernen sie
  nicht. Wann sie geht, regeln die Aufbewahrung des Bereichs `stage` (`KEEP` ein Tag,
  `includes/class-protected-store.php:432`) und `kept()` (`:1615`); nicht nachgestellt. Nach Fable zählt sie bis
  dahin im Budget (nicht nachgeprüft). Durch die Bereitstellung wurde nichts an der Site geschrieben. Der Tausch
  (Teil 2, gebaut) nimmt die Gruppe nicht: `swap()` liest die Stufe nur aus dem Journal des Schritts der
  Bereitstellung, wenn dieser `done` ist und dieselbe Einheit nennt (`:312`–`:316`, sonst `journal_unknown()`,
  `ab_mcp_sync_code_journal`), und der Tausch prüft den Baum dieser Gruppe gegen den Hash des Journals
  (`signed_stage()`, `code:stage_not_ready`, `class-sync-code-swap.php:977`). Belegt in
  `SyncCodeRunTest::testTheSwapTakesOnlyTheStageTheJournalOfItsStepNames`: eine verwaiste Gruppe derselben Einheit
  bleibt unberührt, getauscht wird die Stufe des Journals; ein Journal einer anderen Einheit oder einer anderen Gruppe
  wird vor der ersten Mutation abgelehnt (Schritt `planned`, kein Journal, keine Marke, kein Vermerk).
- **Trifft Normalfall:** nein (braucht einen Fehler der Datenbank beim Speichern oder ein Ende der Anfrage genau
  zwischen `stage()` und `update_step()`).
- **Folgeposten:** Test mit einem Fehler an `update_step()` in der Fehlerweg-Prüfung des Teils. Die Anforderung an
  Teil 2 (der Tausch nimmt nur die Stufe, die das Journal seines Schritts nennt, nie eine verwaiste Gruppe) ist mit
  dem Tausch gebaut und mit dem Test oben belegt (Teil D2b des Rests).

### G-5-5 Doppelfehler beim Binden der Pakete und beim Abbruch des neuen Laufs

- **Fundstelle:** `AB_MCP_Sync_Push`, `action=plan` eines Code-Laufs (`includes/sync/class-sync-push.php:1388`–`:1392`).
- **Quelle:** Fable-Prüfstelle 2, C1; Entscheid der Lead vom 13.11.2026 18:51.
- **Fall:** `AB_MCP_Sync_Code_Plan::bind()` gibt einen Fehler, und das folgende `AB_MCP_Sync_Engine::abort()` gibt
  ebenfalls einen.
- **Wirkung:** Zurück kommt nur der Fehler von `abort()` (`:1390`, `:1392`); der Bindungsfehler wird nicht ergänzt.
  Ob die Meldung die Laufnummer nennt, hängt vom Abbruchfehler ab (die Ablehnung, weil ein anderer Aufruf die Sperre
  hält, nennt sie: «Run %d is not aborted.», `class-sync-engine.php:422`, über `AB_MCP_Sync_Run::under_lock()`). In
  welchem Zustand der Lauf und seine Pakete dann bleiben, hängt daran, wo `abort()` scheitert; nicht nachgeprüft. An
  der Site ist bis dahin nichts geschrieben (der Lauf ist eben erst geplant).
- **Trifft Normalfall:** nein (zwei Fehler hintereinander).

### G-5-6 `by_label()` lehnt eine formwidrige Gruppe aus `groups( 'in' )` ab — entfällt

- **Grund:** Seit der Qualitätsdurchsicht vor Teil H (`belege/z5r-q-liste.md` Nr. 7, 17, 21) liest `by_label()` die
  Gruppen nicht mehr in einer eigenen Form und lehnt keine ab: `groups( 'in' )` gibt nur Einträge, die `entries()` über
  `sidecar()` und `whole()` ganz las, in denselben Formen `sidecar.gid` und `sidecar.label`. Fehlt dadurch ein
  benötigtes Paket, lehnt `bind()` dessen Bindung ab, und der neue Lauf wird wieder geschlossen (G-5-5 ohne den zweiten
  Fehler); kein Fall sperrt jeden Code-Lauf. Die Nummer bleibt, ohne Umnummerierung.

### G-5-7 Laufende Einheit ganz ohne PHP-Datei wird nicht getauscht

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::under_lock()` mit `php_file()` (`includes/sync/class-sync-code-swap.php:649`,
  `:785`, Stand `7db0e52`), Ablehnung `code:opcache_file`.
- **Quelle:** Teil 2a, Befund 2 und Frage 8; Entscheid der Lead vom 13.11.2026 (nach 20:29).
- **Fall:** Code der Einheit läuft (`released()` ist wahr), die Einheit ist keine Neuanlage, und weder sie selbst noch
  ein Ordner unter ihr enthält eine PHP-Datei (etwa ein Block-Theme nur aus `style.css`, `theme.json` und Vorlagen).
- **Wirkung:** Die OPcache-Vorprüfung (E5 3) braucht eine vorhandene PHP-Datei; ohne sie lehnt `prepare()` mit
  `code:opcache_file` ab (Weg: von Hand in wp-admin aktualisieren). `style.css` ersetzt die Prüfung nicht. Eine
  Neuanlage ist davon nicht betroffen: Sie hat live keinen laufenden Code, die Vorprüfung entfällt; nennt WordPress sie
  trotzdem als laufend, lehnt die Regel von Z24 vorher ab, mit und ohne `accept`
  (`AB_MCP_Sync_Code_Ops::released()`, `ab_mcp_sync_code_create_runs`, `class-sync-code-ops.php:153`, Stand `7db0e52`).
- **Trifft Normalfall:** nein. Am lokalen WordPress 7.1.3 (`~/Entwicklung/wp-core-7.1.3/wp-content/themes/`) hat jedes
  der 15 mitgelieferten Themes mindestens eine PHP-Datei; Twenty Twenty-Three hat keine im Wurzelordner, aber 7 unter
  `patterns/`, und `php_file()` sucht Ordner für Ordner.

### G-5-8 Frist vor jeder Lesung: 149 Stellen weichen von den Regeln des Wächters ab; an wie vielen davon eine Lesung ohne Prüfung der Frist beginnt, ist nicht gezählt

- **Fundstelle:** die Liste `OPEN` in `tests/ReadBudgetExceptions.php:198`, 149 Einträge in 70 Funktionen, so wie der
  Wächter der Lesungen sie am Stand `7d54485` auswies (`tests/ReadBudgetGuardTest.php` mit `ReadBudgetScan.php`,
  `ReadBudgetRules.php` und `ReadBudgetSoll.php`: grün, 40 Tests; jede Verletzung seiner Regeln steht in `OPEN`, und
  `OPEN` hält nichts darüber hinaus; 0 Probleme des Lesers und der Regeln). Ein Eintrag ist eine Abweichung von einer
  Regel des Wächters, nicht schon eine Lesung ohne Prüfung der Frist: Die Regeln verlangen eine bestimmte Schreibweise
  der Prüfung, und eine andere Schreibweise desselben Schutzes erkennt der Wächter nicht. Gezählt je Regel:
  - **R1, 65:** ein Schritt der Platte, der Datenbank, eines Codecs oder einer Sortierung direkt statt über den Helfer
    der Schritte `AB_MCP_Sync_Step` (`includes/sync/class-sync-step.php`), der die Frist vor dem Schritt prüft;
  - **R2, 20:** eine Schleife, deren Durchgang nicht mit einer Prüfung beginnt, die am Halt verlässt;
  - **R3, 10:** eine Rekursion ohne Prüfung davor oder danach (`AB_MCP_Shape`, `AB_MCP_Sync_Canon::prepared()`,
    `AB_MCP_Sync_File_Manifest::folder()`);
  - **R4, 4:** ein Eintritt in die Kette oder ein Aufruf einer Naht der Platte, dem keine Prüfung unmittelbar vorangeht,
    deren Halt in einer Form weitergegeben ist, die der Wächter liest;
  - **R7, 50:** der Halt eines Aufrufs, den die nächste Anweisung nicht in einer Form weitergibt, die der Wächter liest
    (`is_wp_error()` oder `true !==` mit `return`, oder ein Fachwert aus `FACHWERT` und `AB_MCP_Sync_Deadline::stop()`).

  **Regelabweichung und tatsächliche Lücke:** Die Zahlen zählen Abweichungen von den Regeln. Wie viele der 149 Einträge
  eine Lesung sind, die wirklich ohne jede Prüfung der Frist unmittelbar davor beginnt, oder ein Schritt nach einem
  Halt, hat niemand gezählt; eine solche Zählung gibt es nicht. Beide Arten sind belegt (Befund B1-1 der Codex-Runde
  über Teil B):
  - anders geschützt, nur eine Abweichung von der Regel: `AB_MCP_Sync_File_Manifest::whole()` (R4, unten) und
    `AB_MCP_Protected_Store::get()`, das den Halt von `group()` in der nächsten Anweisung zurückgibt
    (`if ( ! is_array( $found ) ) { return $found; }`, `includes/class-protected-store.php:1116–1118`); der Wächter liest
    diese Form nicht und zählt den Eintrag unter R7;
  - eine tatsächliche Lücke: die Schleife über `stylesheet` und `template` in `AB_MCP_Sync_Env::names_held()` (R2,
    unten unter «Die Namensprüfung»).

  Darunter:
  - **die acht Datenbankstellen** (R1; je Funktion mit ihrer Zeile am Kopf `7d54485`):
    `AB_MCP_Guidance::settings_url()` `admin_url()` (`includes/class-guidance.php:194`),
    `AB_MCP_Sync_Anchor::pair_print()` `home_url()` (`includes/sync/class-sync-anchor.php:352`), `::read()`
    `get_option()` (`:422`), `::retired_list()` `get_option()` (`:500`), `AB_MCP_Settings::get()` `get_option()`
    (`includes/class-settings.php:317`), `AB_MCP_Sync_Manifest::verify()` `home_url()`
    (`includes/sync/class-sync-manifest.php:310`), `AB_MCP_Protected_Store::candidates()` `wp_upload_dir()`
    (`includes/class-protected-store.php:3994`) und `::stored()` `$wpdb->get_var()` (`:4114`);
  - **`AB_MCP_Sync_File_Manifest::whole()`** (R4, Festlegung F3-2-5): dem Aufruf der Naht `lstat()` des Grundpfads
    einer Einheit (`includes/sync/class-sync-file-manifest.php:299`) geht die Prüfung der Frist unmittelbar voran
    (`self::left( $acc )`, `:296`); am Halt antwortet `whole()` mit `return $acc;`, einer Form, die der Wächter nicht
    als weitergegebenen Halt liest und die nicht in `FACHWERT` steht. Der Eintrag ist eine Abweichung von der Regel,
    keine Lesung ohne Prüfung.
  - **die Startlesung PS:2527** (Zählung des Auftrags R12b, Fassung 3c, Befund B1; am Kopf
    `AB_MCP_Protected_Store::read_tree()`, `includes/class-protected-store.php:2588`): die erste Lesung der Uhr des
    Baums liest die Zeit mit dem Cast `(float)`; unter einer Frist ist sie nicht streng, eine unbekannte Zeit wird dort
    nicht als `ab_mcp_read_clock` gehalten (B1 und B1-a in `auftrag-teil-r12b-fassung3c.txt:329–336`, B1-a nicht
    gebaut). Sie ist keine Regel des Wächters und kein Eintrag in
    `OPEN`; sie gehört zu derselben Grenze.
- **Quelle:** Entscheid 1 von Thomas vom 15.11.2026 (`knowledge/decisions.md` «2026-11-15 18:20», bestätigt in
  «2026-11-15 20:50»): die Frist vor jeder Lesung (R12b, Plan 15.20 bis 15.22) endet am Kopf, die 149 Stellen werden
  benannte Grenze; Plan Z8b 15.23, «Zeitbudget (R12b): endet am Kopf» und Grenze «Frist vor jeder Lesung am Kopf»;
  Textvorschlag `knowledge/uebergaben/anhang/2026-11-15-kuerzung/z8b-rest-lead.md:147–151`; Stand des Wächters und
  Festlegungen F3-2-1 bis F3-2-8 in `belege/r12b-3-2-uebergabe.md:83–87` und `:174–193` (von der Lead bestätigt; der
  Code bleibt, wie er am Kopf steht). Nicht mehr gebaut: Teil 3.3 (die acht Datenbankstellen, die übrigen A3-artigen
  Stellen der Ablage, B1-a an PS:2527, C1 an PS:2528) und die Teile 4 bis 7.
- **Was:** Auf den Wegen der Vorbereitung eines Tauschs (`AB_MCP_Sync_Code_Swap::prepare()`: Stufe, signiertes
  Manifest, Baum, Köpfe der Einheit) weichen diese Stellen von den Regeln ab, mit denen der Wächter die Prüfung der
  Frist vor jeder Lesung belegte. An einem Teil von ihnen beginnt eine Lesung tatsächlich, ohne dass die Frist
  unmittelbar davor geprüft ist, oder nach einem Halt folgt noch ein Schritt; an einem anderen Teil schützt eine
  Schreibweise, die der Wächter nicht erkennt. Wie gross welcher Teil ist, ist nicht gezählt (oben). Ein Tausch kann
  darum auf einem langsamen Host über sein Budget laufen. Schranken bleiben die Mittelprüfung
  (`AB_MCP_Sync_Code_Swap::time_left()`, `code:means`, `includes/sync/class-sync-code-swap.php:1781`; die letzte von
  `prepare()` `:725`, die letzte vor der ersten Mutation in `swapped()` `:1147`; Stand `7db0e52`) und die Zeitgrenze von PHP (`max_execution_time`). Für den Kunden heisst
  das: Ein Tausch auf einem langsamen Server kann ins Zeitlimit laufen (Vorschlag für den Kundentext in Zweig 11,
  `z8b-rest-lead.md:152–153`; ein eigener Kundentext besteht nicht). Dass der Halt ankommt, wo die Frist geprüft wird,
  zeigen die Tests des Abbruchs (`tests/integration/ReadDeadlineTest.php`, `tests/SyncStepTest.php`,
  `tests/SyncDeadlineTest.php`). Der Wächter der Lesungen ist nach T7 und T10 an `d1a0478` entfallen (Teil A1 des
  Rests: der Rückbau der Kernsperre machte ihn rot, Plan 15.23); seine Dateien und Zeilen in diesem Text sind die am
  Stand `7d54485`, und dieser Text trägt seither die Grenze.
  - **Von den Tests nicht unterschieden:** An vier Stellen sehen die Tests nicht, ob der Halt vor dem Zugriff auf die
    Platte gegeben wird oder erst danach: `AB_MCP_Protected_Store_Calls::read()` vor `lstat()`,
    `AB_MCP_Protected_Store::holds()` vor dem `stat()` eines Ordners, `AB_MCP_Sync_Env::head_read()` vor `filesize()`
    und `AB_MCP_Payload_File::read()` vor `file_get_contents()`. Die Antwort ist in beiden Fällen dieselbe. Ein Zugriff
    nach dem Halt kann aber auf einer langsamen Platte Zeit kosten, bei `file_get_contents()` je nach Länge der Datei.
    Gegenproben dazu überleben die Tests (Teil B des Rests, `belege/z5r-b-unsichtbar-liste.php`). Ein Test bräuchte
    eine Naht, die die Zugriffe innerhalb dieser Funktionen sieht; er ist nicht gebaut (Befund A-2 der Codex-Runde
    über Teil A).
  - **Die Namensprüfung:** In der Kette der Namensprüfung (`AB_MCP_Sync_Env::held()`,
    `includes/sync/class-sync-env.php:2413`; im Wächter `NAMED` → `CHAIN`, `tests/ReadBudgetExceptions.php:500`) wird
    die Frist vor den Aufrufen von `held()` geprüft, mit einer Ausnahme (Befund B1-2 der Codex-Runde über Teil B): In
    `AB_MCP_Sync_Env::names_held()` prüft die Schleife über `stylesheet` und `template` des Zweigs `theme_choice`
    (`includes/sync/class-sync-env.php:2584`) zwischen den beiden Namen keine Frist. Die zweite Namensprüfung beginnt
    also auch dann, wenn die erste die Frist überschritten hat. Die Stelle steht in `OPEN` (R2, am Stand `7d54485`).
    Ob weitere Aufrufe von `held()` ohne Prüfung davor beginnen, ist nach dem Wächter nicht noch einmal einzeln
    nachgelesen. Eine begonnene Prüfung eines Namens läuft ohne Prüfung zu Ende
    (Entscheid von Thomas vom 14.11.2026, Option 4; Zusage (b) in
    `knowledge/uebergaben/anhang/2026-11-11-z8b/stoppfall-zeitbudget/auftrag-teil-r12b-fassung3c.txt:89–95`). Ihre
    Einflussgrössen sind die Länge des Namens, die Schichten der Prüfung, die Zahl der Zusatzschlüssel aus dem Filter
    `ab_mcp_redact_keys`, die Datenbankabfragen über `uploads_url()` und die Kernaufrufe. Gemessen (Teil 1b, am Kopf
    `6e89b61`): **höchstens 43,266 ms** für einen Aufruf von `held()` über alle Pflichtfälle M0 bis M4 mit bis zu 100
    Zusatzschlüsseln (Fall M3c, erster kalter Aufruf; Median der warmen Aufrufe dort 28,164 ms; Schwelle 100 ms
    eingehalten); mit 1000 Zusatzschlüsseln 50,974 ms (gemessen, entscheidet nicht). Quelle:
    `knowledge/uebergaben/anhang/2026-11-11-z8b/stoppfall-zeitbudget/r12b-t1b-messbericht.md:16` und `:31`;
    `belege/lead-offen.md:898`.
- **Trifft Normalfall:** nicht gemessen (`z8b-rest-lead.md:150`): wie lange die Lesungen an diesen Stellen auf einem
  langsamen Host brauchen, ist nicht gemessen.

### G-5-9 Kernsperre: Der Tausch nimmt die Sperre `auto_updater` von WordPress nicht

- **Fundstelle:** `AB_MCP_Sync_Code_Swap` (`includes/sync/class-sync-code-swap.php`, Stand `7db0e52`): `take()` (`:550`)
  nimmt nur den Change-Lock (`AB_MCP_Change_Lock::take()` über `Calls::take_lock()`, `:111`); `release()` (`:507`) gibt
  nur ihn zurück (`Calls::release_lock()`, `:120`); `checked()` liest vor der Antwort von `prepare()` nur ihn
  (`if ( ! self::lock_held() )`, `:719`; `lock_held()` `:495`). Eine Sperre für Updates nimmt der Tausch nicht.
  `includes/lifecycle/class-update-guard.php` und `class-update-log.php` stehen wieder wie auf main (`90ab733`).
  Rückbau der Kernsperre aus dem 2a-WIP: Commit `d1a0478` (Teil A1 des Rests).
- **Quelle:** Entscheid 1 von Thomas vom 15.11.2026 (Variante b; `knowledge/decisions.md` «2026-11-15 18:20»,
  bestätigt in «2026-11-15 20:50»). Er löst seine Wahl von Option A zur Kernsperre ab (`decisions.md` «2026-11-14
  00:03»). Plan Z8b 15.23, «Kernsperre: fällt» und Grenze «Kernsperre» unter «Neue Grenzen mit Ort»; Gewicht nach
  Option C der Vorlage (`stoppfall-kernsperre/vorlage-kernsperre-thomas.md:26`).
- **Was:** Der Tausch nimmt `auto_updater` nicht. Ein Hintergrund-Update von WordPress, ein Update von Hand oder über
  WP-CLI derselben Einheit kann neben dem Tausch laufen. AlphaBridge trennt nur seine eigenen Wege über den
  Change-Lock: Tausch, Update-Werkzeug, Aktivierung und Deploy von AlphaBridge laufen nacheinander
  (`AB_MCP_Change_Lock`, `includes/lifecycle/class-change-lock.php`; die Ablehnung `ab_mcp_change_busy` nennt die
  möglichen Halter nur als Beispiele, `held_elsewhere()`). Dass der Tausch die Sperre für Updates nicht nimmt, belegt
  der Code: Unter `includes/sync/` steht ausser in diesem Text kein `auto_updater` (`git grep -n auto_updater --
  includes/sync`, Stand `7492dff`). Die Fälle in `tests/integration/ReadDeadlineTest.php`, die `assertNoLock()`
  aufrufen, prüfen nur den Endzustand: Nach dem Aufruf liegt weder die Sperre noch eine Notiz darauf. Ein Nehmen und
  Freigeben während des Aufrufs sähen sie nicht (Befund A-3 der Codex-Runde über Teil A).
- **Trifft Normalfall:** nein. Im Normalfall (F56: ein aktives Plugin mit `accept`) läuft neben dem Tausch kein Update
  derselben Einheit. Wie oft ein Hintergrund-Update von WordPress in einen Tausch fällt, ist nicht gemessen.

### G-5-10 Umbenennen nur auf einen freien Platz: Exklusivität für Ordner, Restfenster bei einer Datei

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::move()` (`includes/sync/class-sync-code-swap.php`): `lstat()` (Ziel frei),
  dann `rename()` (über `Calls::rename()`). Keine Reservierung, kein `unlink`, gelöscht wird nichts. Gerufen aus
  `rename_marked()`, für beide Umbenennungen und den Rückweg F35, und aus `moved_back()` für den Rückweg eines Tauschs.
- **Quelle:** Entscheid der Lead vom 16.11.2026 02:50 nach der zweiten Codex-Runde über D1
  (`belege/codex-free-z5r-d-2-antwort.md`: Befund 3 offen, zwei neue A an der Reservierung;
  `belege/lead-auftrag-z5r-teil-d2.md`, Vorpunkt): Die Reservierung des Platzes aus der Behebung von D1 (`de8caba`)
  fällt wieder weg. Eine leere Reservierung am Live-Ziel kannten Journal, Anleitung und Handweg nicht. Plan Z16.
- **Was:**
  - **Ordner** (der Normalfall: ein Plugin oder Theme mit Ordner): `rename()` ersetzt nach POSIX nie einen Ordner mit
    Inhalt (`ENOTEMPTY`/`EEXIST`) und keine Datei mit einem Ordner (`ENOTDIR`). Legt ein anderer Prozess zwischen
    `lstat()` und `rename()` etwas an den Platz, scheitert die Umbenennung oder ersetzt höchstens einen leeren Ordner,
    der keine Daten trägt. Das ist die Exklusivität nach Z16 für Ordner. Beleg: die Manpage `rename(2)` dieses
    Systems (`man 2 rename`, macOS 27.2, Fassung «BSD 4.2, June 3, 2021»; «The rename() function conforms to IEEE Std
    1003.1-1988 ("POSIX.1")»): «If new exists, it is first removed. Both old and new must be of the same type»,
    «[ENOTEMPTY] New is a directory and is not empty.», «[ENOTDIR] old is a directory, but new is not a directory.».
    `EEXIST` für einen vollen Zielordner nennt diese Manpage nicht; dass POSIX es neben `ENOTEMPTY` zulässt, ist hier
    nicht nachgelesen. Unter Windows scheitert `rename()` auf einen vorhandenen Ordner ganz: `MoveFileExW` meldet
    einen Fehler, wenn das Ziel ein vorhandener Ordner ist (learn.microsoft.com, «MoveFileExW function», Flag
    `MOVEFILE_REPLACE_EXISTING`: «If lpNewFileName names an existing directory, an error is reported.»; übernommen
    aus der Behebung von D1). Ob PHP unter Windows darüber umbenennt, ist nicht nachgelesen und nicht gemessen.
  - **Einzeldatei** (F6): `rename()` ersetzt eine vorhandene Datei (Manpage oben: «it is first removed»). Zwischen
    `lstat()` und `rename()` bleibt ein Restfenster: Eine Datei, die genau dann an den Platz kommt, wird ersetzt.
    Praktisch ist das ein gleichzeitiges Update derselben Einheit (G-5-9). Benannte Grenze nach dem Vorbild WordPress,
    das ebenso erst prüft und dann schreibt (WordPress 7.1.3 des Testsystems, `~/tmp/ab-it-z8b5/site-a`): `move_dir()`
    (`wp-admin/includes/file.php:2106`, `exists()` `:2113`, dann `move()` `:2122`) und `WP_Filesystem_Direct::copy()`
    (`wp-admin/includes/class-wp-filesystem-direct.php:332`, `exists()` `:333`, dann `copy()` `:337`; ebenso `move()`
    `:364` mit `exists()` `:365` und `rename()` `:375`).
  - Ganz schliessen würde das Fenster nur ein Umbenennen ohne Ersetzen (unter Linux `renameat2()` mit
    `RENAME_NOREPLACE`; unter macOS `renamex_np()` mit `RENAME_EXCL`, Manpage oben: «cause EEXIST to be returned if
    the destination already exists»). PHP bietet keines davon an.
  - Tests: `SyncCodeRunTest::testASwapRenamesOnlyOntoAFreePlaceAndReplacesNothingAnotherProcessPutThere` (ein
    leerer Ordner am Platz der Sicherung, bevor der Tausch nachsieht; ein Ordner mit Datei dort zwischen `lstat()` und
    `rename()`; F35; ein Ordner mit Datei am Ziel vor dem Rückweg). Gegenprobe `tausch/d1c-move-free`.
- **Trifft Normalfall:** nein. Im Normalfall legt kein anderer Prozess etwas an die Plätze, und die Einheit ist ein
  Ordner, für den die Exklusivität aus POSIX gilt.

### G-5-11 Rechte der neuen Fassung wie bei WordPress

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::given_rights()` (`includes/sync/class-sync-code-swap.php:1360`; Rechnung
  `:1366`–`:1367`, Rücklesung `:1378`), aus `swapped()` (`:1210`) nach `plan_of()` und vor der letzten Zeitprüfung
  (`time_left()`), also vor dem Ersatzvermerk; Zeilen am Stand von Teil G, Abschnitt 3. Vorbild: WordPress 7.1.3 des
  Testsystems, `wp-admin/includes/file.php:2234`–`:2240` in `WP_Filesystem()` (`:2180`): `FS_CHMOD_DIR` gleich
  `fileperms( ABSPATH ) & 0777 | 0755`, `FS_CHMOD_FILE` gleich `fileperms( ABSPATH . 'index.php' ) & 0777 | 0644`, wo
  nicht definiert; `unzip_file()` legt Ordner und Dateien mit diesen Rechten an (`:1780`, `:1834`).
- **Quelle:** Befund A2 der Codex-Runde über D1, Entscheid 2 der Lead (`belege/lead-auftrag-z5r-d1-behebung.md`).
- **Was:**
  - Die Stage von wordpress.org kommt über `unzip_file()` (`class-sync-code-stage.php:122`) schon mit diesen Rechten;
    das Setzen ändert dort nichts. Die Stage eines Pakets bringt die Rechte ihres Bauordners mit (Ordner 0700,
    `AB_MCP_Sync_Code_Stage_Calls::mkdir()`, `class-sync-code-stage.php:233`); erst `given_rights()` gibt ihr die der
    Site.
  - Wo `FS_CHMOD_DIR` oder `FS_CHMOD_FILE` nicht definiert und `fileperms()` nicht lesbar ist, gilt wie bei WordPress
    0755 beziehungsweise 0644 (`false & 0777` ist 0). Eine Konstante, die keine ganze Zahl von 0 bis 07777 ist, lehnt
    der Tausch ab (`code:rights`), WordPress gäbe sie an `chmod()` weiter.
  - Braucht der Webserver eines Hosts andere Rechte, als WordPress setzt, gilt dasselbe für jedes Update über wp-admin.
  - Nicht gebaut (Alternative für Teil G): die Rechte der alten Fassung je gleichem Pfad übernehmen; neue Einträge
    bräuchten dann trotzdem eine Regel.
  - Das Setzen läuft je Eintrag und gehört zur Zeit des Aufrufs vor der letzten Zeitprüfung.
  - **Dauer** (gemessen 17.11.2026, drei Läufe; Apple M3, 24 GiB, Last vor dem Start 4,95; PHP 8.5.10 der
    Kommandozeile, OPcache dort aus: `opcache.enable_cli` Off, jedes `opcache()` «not_active»; Rohwerte und Protokoll
    `~/Entwicklung/wt-ab/z8b-zweig-5-messung/belege/z5r-g3-messung.log`, Auswertung `z5r-g3-auswertung.txt` daneben):
    WooCommerce 11.3.0 auf dem Weg von F56 in der Integrationsumgebung, hier mit gesenkter Versionszeile und aktiv, auf
    Staging unverändert, Stage von wordpress.org (das Paket lokal, sha256 `d9eb1e46…accc6`), mit `accept`. Die Einheit
    hat 6349 Einträge (5452 Dateien, 897 Ordner), davon 3658 PHP-Dateien. Je Lauf 1 / 2 / 3:
    - Bereitstellung (Schritt «bereitstellen», zwei Aufrufe bis bereit; geprüft eine PHP-Datei, die einzige neue oder
      geänderte): 5,553 / 5,608 / 5,491 s.
    - Vorher-Probe (`prepare()` allein, dann `release()`): 3,626 / 3,242 / 3,341 s, davon die Testanfragen 2,715 /
      2,355 / 2,467 s.
    - Tausch (`wp_site_push action=next` des Schritts «tauschen», darin `run()`): 3,856 / 3,805 / 3,661 s; davon der
      Teil `prepare()` 2,157 / 2,236 / 2,101 s (Testanfragen 1,268 / 1,312 / 1,217 s), danach 1,694 / 1,563 / 1,556 s.
      Netto (Tausch − Vorher-Probe) 0,230 / 0,563 / 0,320 s, kleiner als der Teil danach, weil die Testanfragen der
      Vorher-Probe in jedem Lauf länger dauerten als die des Tauschs.
    - Im Teil danach: die Rechte (`given_rights()`, je Eintrag `lstat()`, `chmod()`, `lstat()`, je Ordner `scandir()`)
      0,207 / 0,197 / 0,191 s, darin die 6349 `chmod()` zusammen 0,104 / 0,099 / 0,097 s; der Platz der Sicherung mit
      der Lesung des Ziels (`plan_of()`) 0,394 / 0,411 / 0,397 s; die zwei `rename()` zusammen 0,239 / 0,200 /
      0,189 ms; OPcache je PHP-Datei 0,121 / 0,096 / 0,093 s; `close()` bis zur geschlossenen Marke 0,968 / 0,855 /
      0,870 s.
    - Nicht gemessen: die Rechte an der Stage eines Pakets, OPcache an, ein Host.
- **Trifft Normalfall:** nein für eine Stage von wordpress.org (F56), ja für die Stage eines Pakets; dort ist es gebaut.

### G-5-12 Der Handweg leert OPcache nicht selbst

- **Fundstelle:** `AB_MCP_Protected_Store::guide()` (`includes/class-protected-store.php:2831`): nach jedem Befehl, der
  etwas bewegt, der Satz `$opcache` (`:2858`). Die Befehle eines Punkts sind eine Zeile, mit `&&` verkettet und mit
  `echo OK` am Ende (`$chain`, `:2850`). Zeilen am Stand von Teil G, Abschnitt 2.
- **Quelle:** Befunde A6 und A7 der Codex-Runde über D1, Entscheide 6 und 7 der Lead; Bauzettel F, Abschnitt 8 (P3).
- **Was:** Aus einer Shell lässt sich der OPcache der Prozesse, die die Site bedienen (PHP-FPM, Modul des Webservers),
  nicht leeren; die Kommandozeile von PHP hat einen eigenen. Der Handweg nennt darum das Leeren oder den Neustart von PHP
  beim Hoster als Satz, ohne Befehl. Bis dahin kann bei `opcache.validate_timestamps=0` der Code laufen, den PHP vor dem
  Zurücklegen gelesen hat. Nach einem harten Abbruch nach den Umbenennungen (P3, F58, G-5-17) lief kein
  `invalidate()`: Bis Weg (b), der OPcache je PHP-Datei am Ziel leert (G-5-26), oder bis zum Satz zu OPcache der
  Anleitung kann PHP Code der alten Fassung aus OPcache ausführen.
- **Trifft Normalfall:** nein. Im Normalfall braucht es den Handweg nicht.

### G-5-13 Z24: Eine Aktivierung nach der letzten Prüfung sieht der Tausch nicht

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::as_prepared()` (`includes/sync/class-sync-code-swap.php:1252`), gerufen in
  `swapped()` (`:1226`) nach der letzten Zeitprüfung und unmittelbar vor `rename_marked()` (`:1230`); die frische
  Lesung ist `runs()` (`:471`), der Stand der Vorbereitung `$prepared['runs']` aus `released()` in `under_lock()`
  (`:775`). Der Satz in der Antwort des gelungenen Tauschs: `after_renames()` (gebildet `:1632`, angehängt erst nach
  dem geschriebenen Ende `:1654`). Zeilen am Stand von Teil G, Abschnitt 2.
- **Quelle:** Plan 15.23, Festlegung zu 1 (Z24 Option C) und Grenze Z24; Vorlage
  `stoppfall-z24/vorlage-z24-thomas.md`, «Was in jedem Fall gilt»; F57; Teil C des Rests, Lesart des Satzes nach dem
  Entscheid der Steuerung vom 16.11. 14:29.
- **Was:**
  - Der Tausch setzt keinen Wartungsmodus (keine Datei `.maintenance`) und hält die Option `active_plugins` nicht.
  - Die letzte Prüfung liegt unmittelbar vor dem ersten Schreiben des Schritts: ob die Einheit aktiv ist, an jedem
    Cache vorbei gelesen und streng mit dem Stand von `prepare()` verglichen. Weicht er ab oder ist er nicht lesbar,
    endet der Tausch ohne Mutation (`code:runs_changed`), der Schritt bleibt `planned`, der nächste Aufruf bereitet
    neu vor; `accept` ersetzt das nicht.
  - Eine gewöhnliche Aktivierung (oder ein Themewechsel), die nach dieser Prüfung beginnt, sieht der Tausch nicht,
    ebenso eine kurz an- und wieder ausgeschaltete zwischen zwei Lesungen. Nach der Prüfung liegen noch Journal
    (`:1444`), Marke mit Anleitung (`:1452`) und Vermerk (`:1464`) vor der ersten Umbenennung (`:1471`); eine
    Aktivierung in diesem Fenster oder danach läuft gegen die alte Fassung, einen leeren Platz oder die neue.
  - Gesehen wird nur die Zeile `active_plugins` (bei einem Theme `stylesheet` und `template`). Eine Aktivierung in
    wp-admin lädt das Plugin und lässt seine Routine laufen, bevor sie die Zeile schreibt (`activate_plugin()`). Auch
    eine Aktivierung, die vor der Prüfung begann, deren Zeile aber erst danach geschrieben wird, sieht der Tausch nicht;
    ihre Routine läuft womöglich schon gegen die alte Fassung, und die Umbenennungen laufen unter ihr durch.
  - Gelesen wird die Aktivierung, nicht der laufende Code: Die Vorschau eines inaktiven Themes (Customizer,
    `start_previewing_theme()`; Site-Editor, `wp_theme_preview`) lässt dessen Code laufen, der Tausch behandelt es als
    nicht aktiv (ohne `accept`). Die zwei Sätze des Tauschs sagen darum «active» (Bauzettel G, V4).
  - `prepare()` ermittelt nur, ob die Einheit aktiv ist (ja, nein), nicht welche Hauptdateien; eine weitere Hauptdatei
    eines schon aktiven Plugins ändert den Wert nicht, ebenso ein Wechsel zwischen Stylesheet und Template desselben
    Themes.
  - Die Grenze gilt für jede Antwort des Tauschs: für den gelungenen Tausch, die Enden nach einem Fehler (`failed`,
    `unclear`, das nicht geschriebene Ende in `ended()`) und die Ablehnungen. Den Satz dazu trägt nur die Antwort des
    gelungenen Tauschs, erst nachdem sein Ende geschrieben ist (nicht das gespeicherte Ergebnis des Schritts); die
    Antworten nach einem Fehler sagen nach Option B nur, was der Aufruf nachgeprüft hat, und eine Ablehnung hat nichts
    getauscht. Der Satz spricht nur von der getauschten Einheit: Die Antwort kennt, ob sie aktiv ist, so, wie die
    letzte Prüfung es las; eine Aktivierung von ihr (bei einem Theme ein Wechsel des Themes), die die Site erst nach
    dieser Prüfung festhält, und eine davor an- und wieder ausgeschaltete sieht sie nicht.
  - Tests: `SyncCodeRunTest::testAPluginActivatedAfterThePreparationIsNotSwappedUntilItIsPreparedAnew` (F57; vier
    Fälle, auch ein aktives Plugin, das nach `prepare()` deaktiviert wird) und
    `testASwapWhoseEndCannotBeWrittenSaysItIsSwappedAndTheNextCallChangesNothing` (D2a 5: der Satz nicht in der
    Ablehnung von `ended()`). Gegenproben `tausch/c-runs-fresh`, `tausch/c-runs-strict` und `tausch/cb-z24-in-result`.
- **Trifft Normalfall:** nein. Im Normalfall aktiviert niemand die Einheit in den Sekunden ihres Tauschs.

### G-5-14 Der Rückweg eines Tauschs: ein Eingang, keine eigene Marke, die Marke über ihre Liste

- **Fundstelle:** Der Platz des Eingangs `AB_MCP_Sync_Revert::admit()` und die Weiche `code()`
  (`includes/sync/class-sync-revert.php:126`, `:149`), belegt in der letzten Zeile von
  `includes/sync/class-sync-code-ops.php` (`:592`); der Rückweg im Tausch `back()`, `restore()`, `restored()`,
  `moved_back()`, `mark_id()` (`includes/sync/class-sync-code-swap.php:2102`, `:2166`, `:2207`, `:2301`, `:2426`); das
  Journal in der Form seiner Zeile `step.swap` (`includes/class-store-inputs.php:985`); die Fortsetzung eines
  unterbrochenen Rückweg-Schritts `begin_same_run()` (`class-sync-revert.php:986`). Zeilen am Stand des Abschnitts
  «ein Leser», Commit 2 (Qualitätsdurchsicht vor H).
- **Quelle:** Teil E des Rests, Bauzettel `z5r-e-zettel.md` (Grenzen) mit der Freigabe der Lead (Fragen 1 bis 5, N12)
  und der Kürzung nach `z5r-e1-abweichung.md`.
- **Was:**
  - Der Rückweg eines gelungenen Tauschs schreibt keine eigene Marke. Ein harter Abbruch zwischen seinen zwei
    Umbenennungen lässt das Ziel leer; die neue Fassung liegt dann in der Gruppe `back-<lauf>-<schritt>` der
    Sicherungen des Rückweglaufs, die alte in der Sicherung. Der Rückweg-Schritt wird bei der Fortsetzung `unclear`
    (`begin_same_run()`, deren Satz von «object» spricht), und einen Handweg gibt es nur, solange die Marke des Tauschs
    offen ist (nach einem gelungenen Tausch ist sie zu). Ein harter Abbruch eines Rückweg-Schritts ist nicht getestet
    (F58 bricht nur den Tausch ab).
  - Der Platz des Eingangs ist öffentlich wie jeder Aufruf in PHP: Code im selben Prozess könnte ihn belegen oder
    leeren. Free belegt ihn nur aus der Datei von `AB_MCP_Sync_Code_Ops`; leer lehnen Vorschau, Schritt und
    Fortsetzung eines Laufs mit Code-Schritten ab (`ab_mcp_sync_revert_code`).
  - Die Marke des Tauschs findet der Rückweg über die Liste der Marken (Lauf und Schritt). Ist sie weg (ihr Ordner
    einen Tag nach dem Schliessen) oder kann die Liste nicht gelesen werden, schliesst er keine und belegt allein am
    Ziel; die Marke eines `unclear`-Tauschs bleibt dann offen, und die Antwort sagt nichts über sie.
  - Nach einer gescheiterten Umbenennung sagt die Antwort nur, dass sie scheiterte (Option B), nicht, wo die neue
    Fassung liegt; das Ergebnis des Schritts nennt den Platz, wo er gefüllt blieb (auch wo die Rückkehr der neuen
    Fassung scheitert und das Ziel leer ist).
  - Den Platz der neuen Fassung nimmt der Rückweg-Schritt vor dem Journal `begun` (wo das Ziel nicht leer ist);
    verweigert ihn der Speicher, bleibt der Schritt `planned`. Bewegt der Schritt danach nichts (die alte Fassung ist
    schon am Ziel, oder ein Nachweis scheitert), bleibt die Gruppe `back-<lauf>-<schritt>` leer stehen, so lange wie
    die Sicherungen des Rückweglaufs.
  - Die Ablehnung der Uhr auf dem Rückweg (`restored()`, `class-sync-code-swap.php:2218`) trägt den Satz von
    `clock_refusal()` (`:2488`): «the unit is not swapped». Er ist wörtlich wahr (nichts wird getauscht), und der Weg
    (`action=next`) stimmt; er nennt den Rückweg nicht als solchen.
  - Der Schlusssatz eines Rückweglaufs mit Befunden (`AB_MCP_Sync_Engine::closed_note()`,
    `includes/sync/class-sync-engine.php:3236`, Satz `:3261`) spricht für jeden Schritt, der `unclear` oder `failed`
    blieb, von Teilen und Resten («each part left names its value before; remove a rest by hand …»), auch für einen
    Code-Schritt, der weder Teile noch Rest hat; den Weg eines Code-Schritts (seine Antwort, die Anleitung der offenen
    Marke) nennt er nicht (Skeptiker E1, C4; wie der Satz von `begin_same_run()` oben). Von Paaren sagt er nichts mehr:
    ein Schritt, der sein Paar `unclear` liess, sagt es in seiner Antwort, ein `failed`-Schritt lässt es, wie es war
    (Befund G2, Nachtrag der Steuerung 16.11. 23:26).
- **Trifft Normalfall:** nein. Im Normalfall (F59, F1, F2, F6, F47, F55) ist der Eingang belegt, die Marke in der Liste,
  und beide Umbenennungen gelingen in einem Aufruf.

### G-5-15 Wiedereinschalten mit `accept` und Weg (a) für einen Lauf mit Code-Schritten

- **Fundstelle:** `accept` im Schema von `wp_site_revert` (`includes/tools/class-tools-staging.php:481`), durchgereicht
  von `AB_MCP_Sync_Revert::one()` und `code()`, geprüft in `AB_MCP_Sync_Code_Ops::back()`
  (`includes/sync/class-sync-code-ops.php:163`); im Tausch `back()`, `restored()`, `on_again()`, `closed_checked()`
  (`includes/sync/class-sync-code-swap.php:2102`, `:2207`, `:2345`, `:2387`); Weg (a) in
  `AB_MCP_Sync_Run::close_checked_on()` (`includes/sync/class-sync-run.php:1180`). Zeilen am Stand des Abschnitts
  «ein Leser», Commit 2 (Qualitätsdurchsicht vor H).
- **Quelle:** Teil E des Rests, Abschnitt 2, Kurzzettel `z5r-e2-zettel.md` nach dem Bauzettel `z5r-e-zettel.md`
  (Bausteine 4 und 7, Frage 2 der Lead).
- **Was:**
  - Wieder eingeschaltet werden nur Plugins, deren Hauptdatei beim Beginn des Tauschs lief (Journal `active`, wie die
    Marke sie nennt). Ein Wechsel des Themes durch den Kern (`validate_current_theme()`, wo der Ordner fehlte) wird
    nicht zurückgestellt (G-5-18); das Journal hält für ein Theme kein `active`. Seit Teil F, Abschnitt 2 nennt die
    Antwort des Rückweg-Schritts ein Theme, das dieser Aufruf als nicht aktiv liest (weder Theme der Site noch sein
    Eltern-Theme, Wortlaut wie V4), mit dem Weg (wp-admin), ohne einen Wechsel zu behaupten und auch für ein Theme, das
    nie aktiv war («where it, or a child theme of it, was active before the swap»; Skeptiker Leser, C2: der Weg führt
    zum Theme, das lief); wo das nicht gelesen wird (`runs()` null), sagt sie nichts dazu (Option B).
  - `accept` wirkt nur für einen Schritt, dessen Rückweg geplant wird. Eine Nummer, die kein Schreibschritt des Laufs
    ist, nicht in `steps` steht, schon zurückgenommen wurde, oder `accept` für einen Lauf der Inhalte bleibt ohne
    Wirkung und wird nicht abgelehnt (wie `steps`). Bei der Fortsetzung eines unterbrochenen Rückwegs gilt der Eintrag
    des Rückweg-Schritts; ein neues `accept` wird dort weder genommen noch abgelehnt.
  - Mit `accept` fragt der Rückweg-Schritt den Guard für jede solche Hauptdatei; ob sie aktiv ist, liest der Guard
    selbst. Ohne `accept` und in der Vorschau nennt die Antwort jede, die `active_mains()` nicht als aktiv liest; ist
    die Zeile `active_plugins` nicht lesbar, nennt sie alle («not read as active»).
  - Die Aktivierung läuft im Aufruf des Rückweg-Schritts, mit dem Zeitbudget des Guards ab dem Beginn dieses Aufrufs;
    lehnt er ab (etwa keine Zeit mehr für seine Testanfragen), bleibt das Plugin inaktiv, der Schritt `done`, und die
    Antwort nennt die Ablehnung mit dem Weg. Einen zweiten Versuch über `wp_site_revert` gibt es nicht (ein Schritt geht
    einmal zurück); der Weg ist wp-admin oder `wp_activate_plugin`. Das gilt seit dem einen Leser auch, wo ein Eintrag
    oder Plan nicht liest (G-5-23).
  - Weg (a) schliesst nur, wo am Ziel die neue Fassung und in der Sicherung die alte ganz belegt ist, oder am Ziel die
    alte; sonst nennt die Ablehnung den Rückweg und den Handweg über die Anleitung der Marke nur, wo diese Marke noch
    offen ist (Skeptiker F2+G, C1; nach einem gelungenen Tausch ist sie zu). Die Marke schliesst dabei (bei `swap` mit dem Verwerfen der Datei-Undo-Punkte der Einheit wie Z20a). Die
    Meldung der Seite «Staging» danach sagt weiter «Nothing was written». Konnte die Sperre für Änderungen danach nicht
    zurückgegeben werden, nennt das nur eine Ablehnung, nicht ein gelungenes Schliessen. Ein Journal, das nicht als
    Tausch lesbar ist, lehnt mit dem Satz `journal_unknown` ab, der nur den Handweg nennt (die Teilmenge der Schritte
    nur in der Vorschau, wo `steps` gilt); der Lauf bleibt dann unterbrochen, und kein Weg schliesst ihn, solange das
    Journal nicht lesbar ist. Ein Lauf, dessen Datensatz nicht zurückliest, wird weder geplant noch zurückgelegt noch
    als geprüft geschlossen (`code()` über den einen Leser `AB_MCP_Sync_Run::wrote()`, fail closed; G-5-23).
- **Trifft Normalfall:** nein. Im Normalfall bleibt ein aktives Plugin über Tausch und Rückweg aktiv, und ein
  unterbrochener Tausch geht über Weg (b) zurück.

### G-5-16 Kein Rücktausch: eine fatale neue Fassung bleibt am Ziel

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::rename_marked()` (`includes/sync/class-sync-code-swap.php:1422`): nach den
  zwei Umbenennungen keine Probe der Site; `after_renames()` (`:1601`) leert OPcache, schliesst die Marke und antwortet
  mit Ziel, Sicherung, dem Rückweg des Laufs und den Zeilen von `by_hand()` (Antwort `:1625`, `by_hand()` `:2061`). Die
  Vorschau eines Laufs nennt nur die Probe davor (`AB_MCP_Sync_Push::begin()`, `shown()`,
  `includes/sync/class-sync-push.php:1408`, `:1492`). Vorbild, das zurücklegt: `wp_update_plugin`
  (`includes/lifecycle/class-update-guard.php:111`, wie auf main). Zeilen am Stand von Teil G, Abschnitt 2.
- **Quelle:** Plan Z8b 15.23, «Benannte Grenze zum Rücktausch» und «Kein Rücktausch» (Entscheid 1 von Thomas,
  `decisions.md` «2026-11-15 18:20»); Bauzettel `belege/z5r-g-zettel.md`, Abschnitt 1.
- **Was:**
  - Der Tausch prüft die Site nach dem Umbenennen nicht und legt bei einem fatalen Fehler der neuen Fassung nicht
    selbst zurück.
  - Antworten MCP oder wp-admin noch, legt der Rückweg des Laufs die alte Fassung zurück (`wp_site_revert`, G-5-14).
    Endet die neue Fassung bei jedem Aufruf fatal, bleibt der Weg von Hand: Die Antwort jedes Tauschs nennt Zielordner,
    Sicherungsordner und die Befehle. Die Marke ist nach einem gelungenen Tausch geschlossen und ihr Ordner einen Tag
    danach weg; ihre Anleitung gilt dann nicht mehr. Daneben hilft der Wiederherstellungsmodus von WordPress; den
    Notweg bringt Z9a (Plan).
- **Trifft Normalfall:** nein. Im Normalfall (F59, Fall 1) ist die neue Fassung gesund; Fall 2 (fatal bei jedem
  Aufruf) prüft den Weg von Hand.

### G-5-17 Harter Abbruch: keine Rettung im Aufruf

- **Fundstelle:** `AB_MCP_Sync_Code_Swap` (`includes/sync/class-sync-code-swap.php`) ruft kein
  `register_shutdown_function` (`git grep -c` ohne Treffer); der Ersatzvermerk in `rename_marked()` (`noted()` `:1521`,
  vor der ersten Umbenennung `:1471`), «not taken back» (`not_swapped()`, `:1920`). Die Sperre für Änderungen
  `AB_MCP_Change_Lock::TIMEOUT` (600 s, `includes/lifecycle/class-change-lock.php:54`) und
  `AB_MCP_Sync_Run::settle()` (`includes/sync/class-sync-run.php:877`). Test
  `SyncCodeRunTest::testASwapAbortedHardAtEachPointLeavesAStateItsWaysBackKnow` (F58). Zeilen am Stand des Abschnitts
  «ein Leser», Commit 2 (Qualitätsdurchsicht vor H).
- **Quelle:** Plan 15.23, «Keine Shutdown-Rettung»; Bauzettel F, Abschnitt 8 (`belege/z5r-f-zettel.md`), am Bau von
  Teil F, Abschnitt 2 geprüft; Notiz G-2 (D2a).
- **Was:**
  - Endet der Aufruf hart zwischen Journal und Ende (SIGKILL, Ende des Prozesses), bleibt die Marke offen, bis Weg (b)
    oder Weg (a) sie schliesst. Der Handweg schliesst weder Marke noch Schritt noch Paar; ohne Eingang bleiben danach
    Lauf und Marke offen (die Marke hält Sicherung und Stage). Ob der Lauf nach einer Verlängerung weitergeht, ist der
    Prüfpunkt R1.
  - Nach SIGKILL hält die Sperre für Änderungen, bis ihre Zeile `TIMEOUT` alt ist; so lange liest `settle()` den
    Schritt als `running`, der Lauf bleibt offen, und Weg (b) und (a) warten (F58 prüft `running` vor dem Altern der
    Zeile).
  - Der Ersatzvermerk bleibt nach einem Abbruch an jedem der drei Punkte geschrieben (P1 vor, P2 zwischen, P3 nach den
    Umbenennungen); F58 prüft, dass er steht, nicht was danach mit ihm geschieht. Ebenso bleibt er ohne Ersatz stehen,
    wo nach ihm eine Umbenennung scheitert, bis zum nächsten Tausch oder Update der Einheit: Die Datei-Undo-Punkte der
    Einheit von vor dem Aufruf werden verweigert wie nach einem Tausch. Die Antwort von `not_swapped()` sagt es; die
    eines `unclear`-Endes zwischen den Umbenennungen nennt den Handweg, nicht den Vermerk.
  - Weg (a) und Handweg sind an P1 und P3 am Stand nach SIGKILL nicht gelaufen; F58 hält fest, dass dieser Stand dem
    der bestehenden Tests gleicht (Marke offen, Vermerk geschrieben, Journal `begun`), ausser der Sperre.
- **Trifft Normalfall:** nein. Im Normalfall endet der Aufruf des Tauschs nicht hart.

### G-5-18 Einheit bei offenem Tausch von WordPress abgeschaltet (Theme gewechselt, Plugin deaktiviert)

- **Fundstelle:**
  - Theme: Kern (WordPress 7.1.3): `validate_current_theme()` (`wp-includes/theme.php:898`, Filter `:906`,
    `switch_theme( WP_DEFAULT_THEME )` `:927`), gerufen aus `wp-admin/themes.php:264` und dem Customizer
    (`wp-includes/class-wp-customize-manager.php:668`); `includes/` hält keinen Filter darauf. Der Satz des Rückwegs in
    `AB_MCP_Sync_Code_Swap::restored()` (`includes/sync/class-sync-code-swap.php:2207`, Satz `:2267`). Zeilen am Stand
    von Teil G, Abschnitt 2.
  - Plugin: Kern: `wp-admin/plugins.php:639` ruft `validate_active_plugins()` (`wp-admin/includes/plugin.php:1075`),
    das ein Plugin ohne Datei still deaktiviert (`deactivate_plugins( $plugin, true )`, `:1099`). Die Anleitung der
    Marke sagt es und nennt den Weg von Hand (Plugins → Activate; `AB_MCP_Protected_Store::guide()`,
    `includes/class-protected-store.php:2981`–`:2982`; seit Skeptiker F2+G, C5 ohne Weg (a), der nichts einschaltet);
    Wiedereinschalten auf dem Rückweg mit `accept` (G-5-15).
- **Quelle:** Theme: Plan 15.23, «Kein Tauschvorbehalt» (E6); Bauzettel F, Abschnitt 8; Notiz G-24 (Teil E,
  Abschnitt 2). Plugin: Plan 15.23, «Kein Vergleich am Ende»; Plan E6 (Entscheid 2 von Thomas, 11.11.).
- **Was:**
  - **Theme, kein Vorbehalt:** Fehlt bei offenem Tausch der Ordner des aktiven Themes (zwischen den Umbenennungen, nach
    einem Abbruch an P2), wechselt WordPress beim nächsten Aufruf von «Design → Themes» oder des Customizers auf das
    Standard-Theme; der Tausch hält das nicht auf. Der Rückweg stellt den Wechsel nicht zurück (das Journal hält für
    ein Theme kein `active`, G-5-15); seine Antwort nennt ein Theme, das der Aufruf als nicht laufend liest, mit dem
    Weg in wp-admin, ohne einen Wechsel zu behaupten.
  - **Plugin, kein Aktivierungsvergleich am Ende:** Deaktiviert WordPress ein Plugin bei offenem Tausch oder danach,
    meldet der Tausch es nicht; er vergleicht am Ende keine Aktivierung. Die Prüfung des Laufs liest seit Teil H (G3
    von GitHub Codex auf #155) jede getauschte Einheit ganz und vergleicht ihren Hash mit der neuen Fassung des
    Journals (`AB_MCP_Sync_Code_Ops::finding()`); ob sie aktiv ist, prüft sie nicht. Die Deaktivierungshaken des
    Plugins laufen dabei nicht. Der nächste
    Bericht zeigt die Aktivierung als «live geändert». Eingeschaltet wird von Hand (wp-admin, `wp_activate_plugin`)
    oder, wo der Tausch zurückgeht, über den Rückweg mit `accept`. Die Grenze «Rennen nach dem Vergleich» aus E6
    entfällt mit dem Vergleich.
- **Trifft Normalfall:** nein. Im Normalfall liegen beide Umbenennungen im selben Aufruf, ohne Aufruf von wp-admin
  dazwischen (Theme), und zwischen den zwei Umbenennungen öffnet niemand «Plugins» (Plugin).

### G-5-19 Plugin bei offenem Tausch oder danach deaktiviert: kein Aktivierungsvergleich am Ende — in G-5-18

- **Grund:** Mit G-5-18 zusammengelegt (Qualitätsdurchsicht vor Teil H, `belege/z5r-q-liste.md` Nr. 20): Fundstelle,
  Quelle, Fall und Normalfall des Plugins stehen dort je unter «Plugin». Die Nummer bleibt, ohne Umnummerierung.

### G-5-20 Daten im Ordner eines Plugins

- **Fundstelle:** (a) `AB_MCP_Sync_Decision::unit_way()` (`includes/sync/class-sync-decision.php:1458`, Sperre
  `code:live_changed` `:1462`), `includes/sync/class-sync-env.php:473`, im Tausch
  `includes/sync/class-sync-code-swap.php:951`, `:1293`; (b) der Satz der Vorschau
  `includes/sync/class-sync-code-plan.php:433`. Zeilen am Stand von Teil G, Abschnitt 2.
- **Quelle:** Plan E1 (Entscheid 1 von Thomas, 11.11., «wie WP-Update»; Grenze A1).
- **Was:**
  - (a) Ein Plugin, das laufend in seinen eigenen Ordner schreibt (Logs, Caches, eigene Sicherungen), ist live nach
    jedem Schreiben wieder «live geändert». Ist es auch auf Staging geändert, ist es vom Tausch ausgeschlossen
    (`code:live_changed`, ohne Wahl «Staging gewinnt»); es bleibt das Update von Hand in wp-admin, das die Antwort
    nennt.
  - (b) Dateien, die im Ausgangsstand im Ordner lagen und die die neue Fassung nicht hat, liegen nach dem Tausch nur
    noch in der Sicherung, wie nach einem Update in wp-admin, und kommen über den Rückweg zurück, solange die
    Sicherung aufbewahrt wird; die Vorschau sagt es je Pfad.
- **Trifft Normalfall:** nein. Wie viele Plugins laufend in ihren Ordner schreiben, ist nicht gezählt (Plan 5.5).

### G-5-21 Rechte des Kontos: erst im Schritt des Tauschs, kein Weg über ein anderes Konto

- **Fundstelle:** `AB_MCP_Sync_Code_Kind::rights()` (`includes/sync/class-sync-code-kind.php:182`, angehängter Satz
  `:188`), gefragt im Tausch `run()` und `restore()` (`includes/sync/class-sync-code-swap.php:1173`, `:2171`), nicht
  beim Planen. Zeilen am Stand von Teil G, Abschnitt 2.
- **Quelle:** Notiz G-16 (D3b-2b, gestrichen nach dem vierten Befund) und G-7 (D3a).
- **Was:**
  - Jede Ablehnung von `rights()` endet mit dem einen Satz zum Lauf (abbrechen mit dem eigenen Konto, neu planen ohne
    den Eintrag, wo er abgelehnt bleibt). Dass ein Konto mit dem Recht die Einheit übernehmen darf, sagt keine
    Antwort; der Satz dazu ist gestrichen, weil er unter `DISALLOW_FILE_EDIT` in die nächste Ablehnung führte.
  - Ein Konto ohne das Recht bekommt Plan und Lauf; erst der Schritt des Tauschs lehnt ab, je Aufruf, und ändert
    nichts. `DISALLOW_FILE_EDIT` als Konstante ist nicht getestet, nur seine Wirkung über `map_meta_cap`.
- **Trifft Normalfall:** nein. Im Normalfall hat das Konto der Verbindung die Rechte eines Administrators.

### G-5-22 Reichweite der Wächter

- **Fundstelle:** Kopf von `tests/StoreRemoverGuardTest.php` (`:32`–`:56`), `walk()` (`:111`, `nikic/php-parser`),
  `callers()` (`:267`), `naming()` (`:286`), `testNoShippedFileNamesARemoverAsAValue()` (`:351`), die Wächter von
  `root()` (`:387`), `$building` (`:409`), `remove()` (`:443`), `uninstall()` (`:477`), `opens()` (`:489`);
  `tests/SyncSaidScanTest.php` `UNSEEN` (`:113`, `:114`). Zeilen am Stand der Behebung nach dem Skeptiker Leser.
- **Quelle:** Notiz G-20 (D3b-2c, Skeptiker C) und G-14 (D3b-1); Bauzettel G, V1 bis V3, gebaut in Teil G,
  Abschnitt 1 (echter Parser, Entscheid der Steuerung 16.11. 18:31).
- **Was:**
  - Gesehen wird jeder Aufruf, dessen Name als Name im Code steht (eine Funktion, ein Name nach `->`, `?->`, `::` oder
    `new`), aufgelöst wie PHP auflöst: jede Schreibweise und Gross/klein, Import und Namensraum, `self`, `static` und
    `parent` (im Trait bleiben sie wie geschrieben), `function &name`.
  - Nicht gesehen wird ein Name, der erst zur Laufzeit ein Wert ist: eine Variable (`$f( … )`, `$obj->$m( … )`), ein
    String (`'rename'( … )`), ein weitergereichter Callback (`call_user_func( 'rename', … )`), ein zusammengesetzter
    Name (`'un' . 'link'`). Dass keine ausgelieferte Datei `unlink`, `rmdir`, `rename`, `mkdir` oder `wp_delete_file`
    als String hält, prüft `testNoShippedFileNamesARemoverAsAValue()`; ein zusammengesetzter bleibt ungesehen. Im
    Tausch ist ein Name nur bei der Uhr (`call_user_func( self::$clock )`) und den eigenen Closures in
    `given_rights()` ein Wert.
  - Ebenso eine Klasse als Wert: Ein statischer Aufruf über eine Klasse in einer Variablen (`$c::delete_path( … )`) zählt
    nicht als Aufruf des Speichers (die Klasse steht dann als Quelltext in `of` und fällt durch jeden Filter, siehe
    unten); `new $c` und ein Methodenname als Wert (`X::$m( … )`) kommen gar nicht in die Aufrufe (`:154`, `:169`).
    Gezählt am Kopf `8a7d710` (Lead, 17.11.2026, ohne Commit, mit `nikic/php-parser` über die 195 Dateien von
    `shipped()`): 84 statische Aufrufe und 3 `new` mit der Klasse als Wert, 1 statischer Aufruf mit dem Methodennamen
    als Wert, 0 statische Eigenschaften über eine Klasse als Wert. Keiner ruft den Speicher: Die Klassen kommen aus
    `AB_MCP_Sync_Engine::WRITERS`, aus `AB_MCP_Sync_Db_Kinds`, aus den Formen von `AB_MCP_Shape` (nur Klassen mit
    `decode()` und `encode()`, die der Speicher nicht hat), aus `object_kinds()` von Undo, aus den Unterklassen von
    `AB_MCP_Builder_Writer`; der Methodenname ist `find_live` oder `find_staging` von `AB_MCP_Sync_Pairs`.
  - Kein Wächter dieses Tests sucht im Text einer Datei (Skeptiker F2+G, B1; Skeptiker Leser, B1: der Text
    `::delete_path(` übersah `::delete_path (`; Codex H1, F8: `opens()` las die Meta als Text). Alle lesen über
    `walk()`: die Aufrufe (`callers()`; `opens()` mit dem Bereich und den String-Schlüsseln der obersten Ebene der
    Meta, die dafür ein Array-Literal sein muss: eine Meta in einer Variablen oder aus einem Aufruf gilt als ohne
    Schlüssel, und die Prüfung, dass ein Schreiber von `backups` oder `stage` den Lauf gibt, wird rot; auch
    `delete_path()`, `temp_file()` und `remove()` mit einem Bereich als Variable), die Namen und die Werte der Strings
    einer Datei (`naming()`: die Naht `AB_MCP_Protected_Store_Calls`, der Ordner `ab-mcp-store`) und das Schreiben
    einer statischen Eigenschaft (die Marke `$building`). Ungesehen bleiben, wie oben, ein Name als Wert zur Laufzeit,
    ein zusammengesetzter String und eine Marke über eine Referenz. Der Aufruf über die Naht des Vorschlags
    (`->open(`, `->remove(`) zählt nur in dessen Datei. `named_by_swap()`
    (`includes/class-protected-store.php:3001`) gibt dem Tausch den Ordner der Marken, einen Pfad in der Ablage, und
    zählt nicht als `root()`. Zwei Stellen des Tauschs stehen mit Begründung in `UNSEEN` des Scans der Sätze.
  - Vier Felder von `walk()` halten den Quelltext eines einzelnen Knotens, wie er geschrieben steht (`text()`, `:236`;
    Codex nach der Behebung, Q2): `args`, das erste Argument eines Aufrufs (`:179`); `key` und `value` einer Zuweisung
    an eine statische Eigenschaft (`:189`, `:198`); `of`, die Klasse eines statischen Aufrufs oder einer statischen
    Eigenschaft, wo sie ein Wert ist (`class_of()`, `:217`, gerufen an `:153` und `:195`). `args`, `key` und `value`
    werden wörtlich mit festen Listen verglichen (`:401`, `:417`, `:463`, `:465`, `:528`; der Filter `:521`–`:523`
    liest den Bereich ohne Anführungszeichen, `:528` fängt jede andere Schreibweise): Eine andere Schreibweise gleicher
    Bedeutung macht rot (etwa `TRUE` statt `true` an `:417`), durch kommt nichts. `of` wird als Filter mit
    `strcasecmp` gelesen (`:258`, `:396`, `:413`, `:455`, `:472`, `:493`) und nur im Test der Schreibweisen über die
    Fixtures (`:325`) wörtlich mit einer festen Liste: Eine Klasse als Wert fällt durch jeden Filter, der Aufruf bleibt
    ungesehen (Grenze oben).
  - Sechs weitere Token-Scanner vergleichen Klassennamen wörtlich (`tests/SourceScan.php`, `SyncSafeCallsTest.php`,
    `WellKnownGuardTest.php`, `integration/support/class-test-seam-guard.php`, `support/class-said-scan.php`,
    `AnswerWayTest.php`); sie folgen nach H.
- **Trifft Normalfall:** nein. Die Wächter sind Tests; ein Aufruf, den sie nicht sehen, ändert an der Site nichts,
  solange der Code ihn nicht schreibt.

### G-5-23 Ungelesenes auf dem Rückweg: was nach dem einen Leser bleibt

- **Fundstelle:** der eine Leser `AB_MCP_Sync_Run::wrote()` (`includes/sync/class-sync-run.php:335`) mit
  `reverts_of()` (`:245`, nur Läufe mit grösserer Nummer), `close_checked_on()` (`:1180`) und `pair_of_revert_step()`
  (`:1264`); `AB_MCP_Sync_Revert::code()`, `way_back()` («a step goes back once» `:564`, `:566`) und `continuation()`
  (`includes/sync/class-sync-revert.php:149`, `:474`, `:778`); `AB_MCP_Sync_Engine::revert_boundary()`
  (`includes/sync/class-sync-engine.php:2966`). Ausserhalb des Lesers: die Seite «Staging»
  `AB_MCP_Transfer::way_back_open()`, `back_of()`, `pairs_back()` (`includes/class-transfer.php:565`, `:761`, `:789`;
  `reverts_of()` `:571`, `:792`; `plan_for_person()` `:736`); `AB_MCP_Sync_Engine::close_revert()` und `closed_note()`
  (`:3196`, `:3236`); `AB_MCP_Sync_Run::decode_step()` (`:1330`) mit `AB_MCP_Sync_Engine::pick()` (`:583`). Zeilen am
  Stand des Abschnitts «ein Leser», Commit 2 (Qualitätsdurchsicht vor H).
- **Quelle:** Skeptiker E1b, B1 (`belege/skeptiker-e1b.md`, dritter Befund der Klasse «Ungelesenes wird
  durchgelassen»); Bauzettel des Lesers `belege/z5r-leser-zettel.md` (Stellen S1 bis S13, Abschnitt 9) mit der Freigabe
  der Steuerung vom 16.11. 21:43 (F2 B, F4 B, Frage 5 A).
- **Was:** Seit dem einen Leser liest kein Rückweg einen Datensatz, der nicht zurückliest, als leer, als nicht
  zurückgenommen oder als Lauf der Inhalte; er lehnt ab (Planen, Fortsetzen, Weg (a)), oder ein Rückweg-Schritt der
  Inhalte endet `failed` mit dem Satz des Lesers, nichts zurückgelegt, und der Lauf geht weiter. Ein Schritt geht damit
  einmal zurück, auch wo der Eintrag eines Rückweg-Schritts nicht liest. Es bleibt:
  - S2 (mit F4 B): Ein Rückweglauf, dessen Datensatz nicht zurückliest, sperrt den Rückweg jedes älteren Laufs der Site
    (welchen Lauf er zurücknimmt, ist dann nicht bekannt), bis der Host den Datensatz prüft; den Rückweg eines jüngeren
    Laufs sperrt er nicht.
  - S5 mit S7: Ein unterbrochener Rückweg, dessen offener Schritt nicht zurückliest, wird nicht fortgesetzt. Weg (a)
    liest nur begonnene Schritte: liest einer nicht, lehnt er ab, und der Lauf bleibt `interrupted`, bis der Host den
    Datensatz prüft (beim Code seit dem Rest von B4 ebenso). Liest nur ein geplanter nicht, schliesst Weg (a) den Lauf;
    der Schritt bleibt `planned` im geschlossenen Lauf, und der Rückweg des Laufs, der schrieb, lehnt danach an S3 ab
    (Skeptiker Leser, C3).
  - Folge von C3 für Inhalte: Liest ein Feld des Laufs, der schrieb, nicht zurück, das der Rückweg der Inhalte nicht
    braucht (sein `budget`), lehnt der Leser trotzdem ab (Planen, Schritt, Fortsetzen, Weg (a)); der Schritt bleibt
    `planned`, ein unterbrochener Rückweg offen wie bei S5.
  - Ausserhalb des Lesers bleibt es nach Frage 5 (A) beim heutigen Verhalten:
    - S11: Die Seite «Staging» überspringt einen solchen Schritt und liest einen Fehler von `reverts_of()` als leer.
      Sie zeigt nur an; der Rückweg selbst geht über `plan_for_person()` und `way_back()` und lehnt dort ab, was die
      Seite unter `no_back` zeigt.
    - S12: Ein `result` eines Rückweg-Schritts, das nicht zurückliest, zählt als ohne Rest; `close_revert()` kann den
      Lauf dann `closed_reverted` statt `closed_findings` schliessen, und `closed_note()` zählt den Schritt nicht.
    - S13: `decode_step()` liest ein `depends`, das nicht zurückliest, als keine Abhängigkeit, für jeden Lauf;
      Rückweg-Schritte haben `depends` leer.
  - Ein Fehler der Datenbank beim Lesen des genannten Schritts in `revert_boundary()` ist die Antwort, der Schritt
    bleibt `planned`; nicht getestet (keine Naht für ein scheiterndes `step()` im Integrationslauf).
- **Trifft Normalfall:** nein. Ein Eintrag, ein Plan, ein Ergebnis oder `depends`, das nicht zurückliest, ist ein
  beschädigter Datensatz.

### G-5-24 Sperre für Änderungen nach Ablauf übernommen: Weiterschreiben und Freigabe der fremden Zeile

- **Fundstelle:** `AB_MCP_Change_Lock` (`includes/lifecycle/class-change-lock.php`): `TIMEOUT` 600 s (`:54`), `take()`
  (`:76`–`:79`, verschachtelt nur gezählt), `take_for_update()` (`:101`), `release()` (`:117`–`:130`), `touch()`
  (`:158`–`:164`), `create()` (`:171`–`:189`, `WP_Upgrader::create_lock()` an `:172`). `AB_MCP_Sync_Code_Swap_Calls`
  (`includes/sync/class-sync-code-swap.php:126`): `take_lock()`/`release_lock()` (`:144`, `:153`).
  `AB_MCP_Sync_Code_Swap` (dieselbe Datei): `lock_held()` (`:539`–`:541`, gefragt nur in `checked()` an `:763`;
  `checked()` beginnt an `:728`, `under_lock()` (`:669`) ruft es an `:712`), `release()` (`:551`), `until()` (`:1135`),
  die Bereiche mit der Sperre `:638`–`:648`, `:1183`–`:1187`, `:2219`–`:2227`, `:2449`–`:2463`; der Beweis `close()`
  (`:1787`–`:1810`) mit `at_target()` (`:1825`) und `in_backup()` (`:1855`), seine zweite Lesung `proved_now()`
  (`:1883`) an `:1622`, `:1970`, `:2303` und `:2468`. `AB_MCP_Sync_Run::under_lock()`
  (`includes/sync/class-sync-run.php:829`–`:852`). Zeilen am Stand der Behebung A1 (Teil H); die Behebung B1 bis B6
  vom 18.11. verschiebt in diesen Dateien keine Zeile.
- **Quelle:** Fable-Prüfstelle «Lösch-, Überschreib- und Freigabestellen» vor dem Merge von Teil H, B1
  (`belege/fable-pruefstelle-ergebnis.md`); Entscheid der Steuerung vom 17.11. nach 21:02: jetzt als Grenze, mit dem
  Code als Posten vor dem ersten Release, das den Tausch enthält (unten). Codex-Runde auf `004988a` (Astra,
  `belege/codex-free-z5r-h-b1-ausgabe.txt`): A1 (P1, Fall (iii): ein `done` auf einem Beweis, den das Ziel nicht mehr
  hält) und B1 bis B6, hier eingearbeitet; Behebungszettel A1, Fassung 3 (`belege/z5r-a1-behebung-zettel.md`), mit
  den Entscheiden der Steuerung nach 21:29 (Weg (ii) an allen Stellen) und nach 21:32 (die Frist). Codex-Runde auf
  `aa7c8ba` (Astra, `belege/codex-free-z5r-h-a1-antwort.txt`) und Behebungsauftrag der Lead vom 18.11.
  (`belege/lead-auftrag-b1-b6-fable.md`): B1 bis B3 und B6 hier eingearbeitet, B5 in `unclear()`; das Restfenster
  (A1 dieser Runde) bleibt nach dem Entscheid von Thomas vom 18.11.2026 («Option 1») als benannte Grenze stehen
  (unten).
- **Was:**
  - **Ab wann die 600 s laufen.**
    - Die Sperrzeile trägt die Zeit, zu der sie geschrieben wurde: beim ersten `take()` des Aufrufs über `create()`,
      danach bei jedem `touch()`, frühestens alle 60 s.
    - Der Tausch ruft `touch()` nicht (`git grep -c "Change_Lock::touch" -- includes/sync/class-sync-code-swap.php`:
      0). Die drei Aufrufe in der Engine liegen in anderen Pfaden: `includes/sync/class-sync-engine.php:1249` in
      `boundary()`, `:1701` in `write_parts()`, `:2441` in `put_back()`, alle für Schritte des Inhalts. Ein Schritt des
      Codes geht in `run_write()` an `:1022`–`:1024` direkt an `AB_MCP_Sync_Code_Ops::run()` und kehrt von dort zurück;
      keiner der drei frischt die Zeile während oder nach einem Schritt des Tauschs auf.
    - Der Tausch nimmt die Sperre in einem Lauf verschachtelt (`take()` `:77`–`:79`). Die Zeile stammt dann vom äusseren
      `take()` in `under_lock()` (Run `:831`), am Anfang des Aufrufs, oder vom letzten `touch()` davor im selben Aufruf.
    - Die 600 s laufen also nicht je Dateiaufruf, sondern über den ganzen Aufruf, bis zur Freigabe.
  - **Schleifen über Dateien zwischen `take()` und `release()`, aus dem Code.** Die Frist des Aufrufs (`until()`)
    wird zwischen den Schritten gefragt. Ein einzelner Aufruf an das Dateisystem ist durch sie nicht unterbrechbar (ein
    `rename()`, ein `chmod()`, das Lesen eines Blocks einer Datei, ein `scandir()`, ein `opcache_invalidate()`).
    - `given_rights()` (`:1362`–`:1395`): Rechte über die ganze Stufe; die Frist wird je Eintrag gefragt (`:1377`–`:1378`).
    - `at_target()` und `in_backup()`: Der Beweis am Ziel und in der Sicherung liest den ganzen Baum, Datei für Datei
      und jede Datei blockweise (`class-sync-file-manifest.php:558`–`:586`). Die Frist geben die beiden verschieden
      weiter:
      - `at_target()` gibt `AB_MCP_Sync_File_Manifest::unit()` eine Zahl, `min( until, jetzt + UNIT_BUDGET )`
        (`:1830`), ohne Frist eines Aufrufers. `unit()` fragt sie über `due()` vor jedem Eintrag eines Ordners, vor dem
        Öffnen einer Datei, nach jedem Block, der nicht der letzte ist, in `still()` und vor der Ausgabe des Hashes
        (`class-sync-file-manifest.php:693`–`:724`).
      - `in_backup()` gibt `AB_MCP_Protected_Store::tree()` eine Frist `AB_MCP_Sync_Deadline::at( until )` (`:1857`),
        die die Ablage an ihren eigenen Toren fragt.
    - `invalidated()` mit `php_paths()` (`:1680`–`:1702`, `:1717`–`:1750`): Die Frist wird einmal je Ordner gefragt
      (`:1729`–`:1731`), nicht je Eintrag. Die Schleife, die den Cache je Datei leert (`:1694`–`:1700`), fragt sie
      nicht.
    - `discard_files` (`:1808` → `AB_MCP_Undo::discard_files_of()`, `includes/undo/class-undo.php:1279`, Schleife
      `:1291`): Die Schleife über den Index der Rückgängig-Punkte fragt die Frist nicht.
  - **Folgen, wenn ein Aufruf die 600 s überschreitet.** Das geschieht, wenn eine der Schleifen oder ein einzelner
    Aufruf darin die Zeit über den Rest des Aufrufs hinaus braucht.
    1. **Übernahme.** Ein anderer Aufruf (ein zweiter Tausch, ein Schritt eines Laufs, eine Aktivierung, ein Update)
       findet die Zeile älter als 600 s und übernimmt sie: `create()` über `WP_Upgrader::create_lock()` mit `TIMEOUT`
       (`:172`), ein Update über `take_for_update()` (`:101`, `includes/lifecycle/class-update-guard.php:153`).
    2. **Weiterschreiben ohne Sperre.** Der erste Aufruf läuft weiter und schreibt ohne Sperre. Er fragt nicht, ob die
       Zeile noch seine ist: `lock_held()` prüft nur, dass eine Zeile da ist, die sich als Zeit liest, und wird nur in
       `checked()` vor dem Tausch gefragt (`:763`). Danach laufen Umbenennen, Rechte, Schliessen der Marke und
       `discard_files` ohne Prüfung der Sperre.
    3. **Freigabe der fremden Zeile am Ende.** Die äusserste Freigabe des ersten Aufrufs (Run `:847`, oder `release()`
       des Tauschs ohne äussere Sperre) löscht die Zeile über `WP_Upgrader::release_lock()`, ohne ihren Wert anzusehen
       (`:117`–`:130`). Sie löscht damit die Zeile des Übernehmers. Ein dritter Aufruf kann dann neben dem Übernehmer
       beginnen, der weiter glaubt, die Sperre zu halten.
  - **Was dann noch schützt:**
    - Vor jedem Umbenennen steht ein gespeichertes `begun`. Das Schreiben des Schritts ist bedingt auf seinen Zustand
      (Run `:780`–`:789`, `AND state = …`); von zwei Aufrufen kann nur einer denselben Schritt von `planned` auf `begun`
      setzen. Zurückgelesen wird nur vorwärts: `begun()` liest den Schritt nach dem Schreiben und vergleicht sein
      Journal (`:1544`–`:1547`). Der Rückweg schreibt `begun` bedingt (`:2268`) und prüft nur die Antwort des Schreibens;
      `journal()` (`:294`–`:296`) gibt an `AB_MCP_Sync_Run::update_step()` weiter, ohne Rücklesung.
    - Ersetzt wird am Ziel nur, wo `lstat()` nichts findet (`:1509`); das Zeitfenster dazwischen nennt G-5-10.
    - Der Tausch löscht keine Sicherung; `discard_files` trifft nur Schnappschüsse der Datei-Werkzeuge und läuft erst
      nach dem Beweis und dem Schliessen der Marke (`:1787`–`:1808`).
    - **Beweis neu gelesen vor jedem Zustand mit einer Aussage über das Ziel (A1).** Die Invariante der Steuerung: «Ein
      Zustand, dessen Ergebnis sagt, welche Fassung am Ziel liegt, wird nur auf einem Beweis nach dem letzten
      Dateiaufruf geschrieben.» Der Beweis von `close()` steht vor `close_mark()` und `discard_files()`. Danach liest
      `proved_now()` (`:1883`) denselben Beweis neu (`at_target()`, für die neue Fassung dazu `in_backup()`), an vier
      Stellen:
      1. vorwärts in `after_renames()` vor `rebased()` (`:1622`), erst danach `done` (`:1642`); bei Abweichung
         `unclear` mit dem Satz des Beweises und den Befehlen von Hand (`unclear()` mit `$whole`);
      2. in `not_swapped()` vor `failed` mit «… the old version is at its place» (`:1970`, `:1974`); bei Abweichung
         `unclear` mit der kurzen Antwort;
      3. auf dem Rückweg in `restored()` nach `on_again()`, das Plugins aktiviert und damit ihren Code ausführt, und vor
         `ended()` (`:2303`, `:2322`), nur ohne Fehler davor; bei Abweichung `unclear` über den Fehlerweg des Rückwegs;
      4. in Weg (a), `closed_checked()`, nach `release()` und vor `return true` (`:2468`, `:2471`), mit der Fassung des
         `close()`, das gelang, auch bei `ab_mcp_store_no_mark`; bei Abweichung die Ablehnung `code:checked`, und der
         Lauf bleibt unterbrochen.

      Die Ablehnung von `proved_now()` hat den Code `code:proof_again`, nie `code:close_target`, auf den Weg (a) zu
      `kept` ausweicht (`:2457`). Die eigenen Rahmensätze von `unclear()` (`:2018`) sagen keine Fassung am Ziel und
      brauchen keine zweite Lesung. Den Satz seines Aufrufers gibt `unclear()` unverändert weiter; `after_renames()`
      übergibt nach einem Fehler von `rebased()` «The new version is at the target and the mark of the swap is closed,
      …» (`:1629`). Dieser Satz steht nach der zweiten Lesung (`:1622`) und fällt in das Restfenster unten.
      Weg (a) und Weg (b) kennen `unclear`: Der Rückweg nimmt einen Tausch-Schritt `begun`, `unclear` oder `done`
      (`class-sync-code-ops.php:168`); Weg (a) setzt `begun` auf `unclear` (Run `:1203`) und listet `unclear` (Run
      `:1440`). Tests: `SyncCodeRunTest::testASwapWhoseTargetIsReplacedUnderATakenOverLockAfterItsProofIsNotDone`,
      `::testASwapNotMadeWhoseTargetIsReplacedUnderATakenOverLockAfterItsProofIsNotFailed`,
      `::testAWayBackWhoseTargetIsReplacedUnderATakenOverLockAfterItsProofIsNotDone` und
      `::testWayAWhoseTargetIsReplacedUnderATakenOverLockAfterItsProofClosesNoRun`, je mit Weg (a) und Weg (b) danach;
      Gegenproben `tausch/a1-vorwaerts`, `tausch/a1-nicht-getauscht`, `tausch/a1-rueckweg`, `tausch/a1-weg-a`.
    - **Die Frist der zweiten Lesung.** Die Frist des Aufrufs ist `until()` (`:1135`) = jetzt + `budget`, mit
      `budget = max( 1, min( WORK_MAX, intdiv( limit, 2 ) ) )` in ganzen Sekunden, ohne Limit `WORK_MAX`, und
      `WORK_MAX` = 50 s (`includes/sync/class-sync-resources.php:62`, `budget()` `:173`–`:179`). Das Limit hebt der
      Aufruf, wo der Host es erlaubt, auf `WANTED_TIME` = 120 s (`:59`), sonst gilt das Limit des Hosts.
      `at_target()` liest höchstens bis `min( until, jetzt + UNIT_BUDGET )` mit `UNIT_BUDGET` = 45 s
      (`includes/sync/class-sync-env.php:256`). Gemessen ist eine Lesung bei
      WooCommerce 11.3.0 mit 5452 Dateien: 0,156–0,166 s (G-3-8, `:141`–`:143` dieser Datei); die zweite Lesung kostet
      je festgeschriebenem Beweis eine solche Lesung mehr. Reicht die Frist nicht für die zweite Lesung, hängt das
      Ende davon ab, in welchem Teil des Beweises sie abläuft und auf welchem Weg (`proved_now()`, `:1884`–`:1888`):
      1. Sie läuft in `at_target()` ab (vorwärts, in `not_swapped()` oder auf dem Rückweg): Der Schritt endet
         `unclear`, mit dem Satz von `at_target()`. Tests: vorwärts
         `SyncCodeRunTest::testASwapWhoseTimeRunsOutInTheSecondReadingOfItsProofIsUnclearWithItsSentence`; in
         `not_swapped()` ohne Marke
         `::testASwapWithoutAMarkWhoseTimeRunsOutInTheSecondReadingOfItsProofNamesNoInstructionToOpen` (die kurze
         Antwort hat dort keine Zeile der Anleitung, `:2027`; Gegenprobe `tausch/b5-ohne-anleitung`). Auf dem Rückweg
         mit Fristmangel nicht getestet.
      2. Vorwärts, bei der neuen Fassung, gelingt `at_target()`, und erst `in_backup()` scheitert (`:1886`): Der
         Schritt endet `unclear`, mit dem Satz von `in_backup()` (`:1862`). Nicht getestet.
      3. In Weg (a) endet in beiden Fällen kein Schritt: Die Ablehnung ist `code:checked` mit dem Satz des Beweises
         (`:2475`), der Schritt bleibt `begun`, der Lauf unterbrochen. Mit Fristmangel nicht getestet.
  - **Was nicht geschützt ist (Restfenster nach A1):**
    - **Benannte Grenze: das Fenster zwischen der zweiten Lesung und dem Schreiben des Zustands.** Nach der zweiten
      Lesung folgen bis zum Schreiben des Zustands noch Aufrufe. Keiner liest das Ziel oder fragt die Sperre:
      - vorwärts in `after_renames()` nach `:1622`, in dieser Reihenfolge:
        1. `rebased()` (`:1626`, `:1901`) → `AB_MCP_Sync_Pairs::rebase()`
           (`includes/sync/class-sync-pairs.php:725`–`:793`): Datenbank, liest das Paar und schreibt es bedingt.
        2. `by_hand()` (`:1631`, `:2103`) → `AB_MCP_Protected_Store::named_by_swap()`
           (`includes/class-protected-store.php:3001`) → `root()` (`:660`) → `candidates()` (`:4151`), das
           `wp_upload_dir()` ruft (`:4153`). WordPress wendet darin den Filter `upload_dir` an; dort läuft Code
           anderer Plugins.
        3. Die Sätze der Antwort über `__()` (`:1633`, `:1641`); `__()` wendet den Filter `gettext` an, auch dort
           kann Code anderer Plugins laufen.
        4. `ended()` (`:1642`, `:2069`) → `journal()` (`:294`–`:296`) → `AB_MCP_Sync_Run::update_step()`:
           Datenbank, schreibt `done`, bedingt auf den Zustand `begun` des Schritts (Run `:780`–`:789`), nicht auf
           das Ziel.
        Scheitert `rebased()`, schreibt dasselbe `ended()` statt `done` den Zustand `unclear`, mit dem Satz «The
        new version is at the target …» (`:1629`);
      - in `not_swapped()` nach `:1970` nur `ended()` (`:1974`), auf dem Rückweg nach `:2303` nur `ended()` (`:2322`);
      - in Weg (a) nach `:2468` das Schliessen des Laufs, `AB_MCP_Sync_Run::close_checked_on()` (Run `:1182`):
        Datenbank.

      Hängt einer dieser Aufrufe so lange, dass ein anderer Aufruf die Sperre übernimmt und das Ziel ersetzt, ist ein
      Zustand mit einer Aussage über das Ziel auf einem Beweis, den das Ziel nicht mehr hält, weiter möglich
      (Codex-Runde auf `aa7c8ba`, A1). Dieses Fenster wird jetzt nicht geschlossen. Es wird im Posten «Sperre für
      Änderungen» (unten) geschlossen, vor dem ersten Release, das den Tausch enthält. Quelle: Entscheid Thomas
      18.11.2026 («Option 1»).
    - Der Beweis liest die Dateien einzeln; eine Datei, die nach ihrem Lesen ersetzt wird, sieht er nicht (wie G-5-10).
    - Was der erste Aufruf nach der Übernahme schreibt, schreibt er neben dem Übernehmer, an anderen Einheiten wie an
      derselben, im Speicher und an den Schnappschüssen, ohne Ausschluss.
    - Nach der Freigabe der fremden Zeile schützt die Sperre den Übernehmer nicht mehr gegen einen dritten Aufruf.
    - Die Prüfung vor dem Push fand mit A1 eine Abfolge, die eine Kernzusage brach: ein `done` auf einem Beweis, den das
      Ziel nicht mehr hält. Die vier Stellen oben schliessen sie bis auf das Restfenster. Ob eine weitere Abfolge eine
      Kernzusage bricht (Code am Ziel ohne Sicherung der alten Fassung, ein Zustand, den kein Weg kennt), ist hier
      nicht behauptet. Die Bestätigung auf `aa7c8ba` fand ausser dem Restfenster keine solche Abfolge.
- **Posten (Entscheid der Steuerung):** «Sperre für Änderungen: Freigabe und `lock_held()` nur für die eigene Zeile;
  `touch()` im Tausch; `register_shutdown_function` vor `forget_active()` (B2)». Erledigt vor dem ersten Release, das den
  Tausch enthält. Eigener Bauzettel, nicht Zweig 5. Zum Posten gehört nach dem Entscheid von Thomas vom 18.11.2026
  («Option 1») auch das Restfenster nach A1 (oben, «Was nicht geschützt ist», erster Punkt): Es wird dort
  geschlossen. Vermerk aus A1: Sobald die Sperre ein Eigentum kennt, darf die
  zweite Lesung auf «nur bei Verlust der Sperre» verengt werden; das ist dann eine Beschleunigung, kein Teil der
  Zusage. Die Vorarbeit dazu liegt in `belege/sperre-posten-vorarbeit-a1-fassung2.md`.
- **Trifft Normalfall:** nein. Es braucht einen Aufruf, der mit allem vor dem Tausch länger als 600 s dauert; das
  Budget eines Aufrufs liegt weit darunter (`until()`).

### G-5-25 Prüfung des Laufs: eine Einheit, die als erste Arbeit eines Aufrufs nicht ganz gelesen wird, ist ein Befund

- **Fundstelle:** `AB_MCP_Sync_Code_Ops::finding()` (`includes/sync/class-sync-code-ops.php:238`, Lesung `:245`,
  `stopped` `:249`); `AB_MCP_Sync_Engine::run_verify()` (`includes/sync/class-sync-engine.php:2616`, Schleife `:2660`)
  und `written()` (`:2911`); die Frist der ersten Arbeit `AB_MCP_Sync_Code_Swap::until()` (`class-sync-code-swap.php:1134`).
  Zeilen am Stand G3b-1 (`acc5b28`).
- **Quelle:** G3 von GitHub Codex auf #155 (Kommentar 4621397353); Auflage c der Lead vom 18./19.11.2026 (23:26):
  «Eine Einheit, die der Tausch in einem Aufruf ganz lesen konnte, darf in der Schlussprüfung nicht wegen der Frist
  zum Befund werden.» Prüfung durch Astra auf `478b2ca` (`belege/codex-pr155-g3-wip-antwort.txt`, F2 und F4).
- **Was:** Die Prüfung des Laufs liest jede getauschte Einheit ganz. Ist die Einheit nicht die erste Arbeit des
  Aufrufs, hat die Lesung den Rest des Aufrufs (`$started + work`); endet er in der Lesung, bleibt der Cursor auf ihr,
  ohne Befund und ohne Zählung, und der nächste Aufruf liest sie als erste Arbeit. Als erste Arbeit hat sie die Zeit
  eines ganzen Aufrufs ab dem Beginn der Lesung (`until()`, `time_for_call()`, wie der Tausch); wird sie darin nicht
  ganz gelesen (`too_large`), ist das ein Befund («could not be confirmed … not readable whole within the time of one
  call»), und die folgenden Einheiten werden weiter geprüft. Die Schranke ist dieselbe wie im Tausch, aber nicht
  enger als er: der Tausch las mit `min( until, jetzt + UNIT_BUDGET )` nach seinen Umbenennungen; eine Einheit, die er
  so ganz lesen konnte, kann die erste Arbeit eines Aufrufs bei gleichen Bytes und gleicher Last ebenso lesen. Ein
  langsameres Dateisystem zum Zeitpunkt der Prüfung kann sie trotzdem zum Befund machen; der Lauf schliesst dann mit
  Befunden, die Einheit selbst bleibt, wie sie ist (nichts wird geändert).
- **Trifft Normalfall:** nein. Im Normalfall liest ein Aufruf eine Einheit in Bruchteilen seines Budgets.

### G-5-26 Einträge entfernter PHP-Dateien bleiben in OPcache bis zum Reset

- **Fundstelle:** `AB_MCP_Sync_Code_Swap::invalidated()` (`includes/sync/class-sync-code-swap.php:1681`), gerufen aus
  `after_renames()` (`:1607`) und `restored()` (`:2289`); `moved_back()` (`:2369`, `moved` false `:2372`, leeres Ziel
  `:2380`). Zeilen am Stand der Behebung (`721f2d1`).
- **Quelle:** Befund 4623266599 von GitHub Codex auf #155; Entscheid von Thomas vom 19.11.2026 («Option A»); Bericht
  der Prüfstelle 5, Q4.
- **Was:** Nach dem Tausch und nach seinem Rückweg leert der Schritt OPcache für jede PHP-Datei, die dann am Ziel
  liegt, wie der Kern nach einem Update (`wp_opcache_invalidate_directory()`). Eine PHP-Datei, die nur die weggelegte
  Fassung hat, bekommt keinen Aufruf. Bei `opcache.validate_timestamps=0` (und `opcache.revalidate_path=0`) bleibt ihr
  Eintrag bis zum Reset von OPcache oder zum Neustart von PHP gültig. Er läuft nur, wenn etwas genau diesen Pfad
  einbindet: `include` oder `require` ohne Existenzprüfung aus fremdem oder neuem Code; mit Existenzprüfung nur bei
  `opcache.enable_file_override=1`. Vor PHP 8.1.25 und 8.2.12 liesse sich ein solcher Pfad nach dem Umbenennen gar
  nicht leeren (`opcache_invalidate()` gibt für eine fehlende Datei false). Zwei Randfälle des Rückwegs:
    - Leeres Ziel (nach einem Abbruch zwischen den Umbenennungen, oder die neue Fassung wurde von Hand entfernt): Der
      Rückweg leert für die alte Fassung am Ziel. Lag die neue Fassung vorher am Ziel, bleiben die Einträge ihrer
      Dateien, die die alte nicht hat, ohne Aufruf.
    - Schon zurückgelegt (die alte Fassung liegt bewiesen am Ziel, etwa nach dem Handweg): Der Schritt bewegt nichts,
      ruft OPcache nicht und endet `done` mit `opcache` 0. Das Leeren nach dem Handweg ist der Satz der Anleitung
      (G-5-12).
- **Trifft Normalfall:** nein, nach Einschätzung (nicht gemessen: wie verbreitet `opcache.validate_timestamps=0` ist
  und wie oft ein Update PHP-Dateien entfernt). Die Einträge bleiben zwar, wo eine Fassung PHP-Dateien hat, die der
  anderen fehlen; alter Code läuft aber nur, wenn die Zeitprüfung aus ist und dazu etwas genau den entfernten Pfad
  einbindet. Der Weg ist der Reset von OPcache oder der Neustart von PHP beim Hoster.

## Übernommen aus Zweig 2 (`bericht-zweig-2.md`, Abschnitt Grenzkandidaten; G-M9 aus «Behebung 2»)

### G-M1 Ein Lesen über Teile ist kein Augenblick

- **Fundstelle:** `AB_MCP_Sync_Env` (Lesen der Einheiten über mehrere Aufrufe, `read_unit()`,
  `includes/sync/class-sync-env.php:1417`).
- **Was:** Eine Einheit ist in sich ein Stand, die Serie nicht; entsteht eine Datei in einer schon gelesenen Einheit,
  zeigt der Bericht den älteren Stand.
- **Trifft Normalfall:** nein (der Tausch hasht die Live-Einheit unmittelbar vor der Mutation neu, Z3 in Zweig 5).

### G-M2 Speicher beim Abschluss des Berichts

- **Fundstelle:** `AB_MCP_Sync_Report::finish()` (`includes/sync/class-sync-report.php:564`), `environment()` (:312).
- **Was:** Alle Zeilen der Umgebung beider Seiten liegen beim Abschluss zugleich im Speicher. Gemessen wurden 3,0 MiB
  serialisierte Zeilen für 23'900 Dateien; Spitzenspeicher und Dateischwelle bei einem Speicherlimit von 256 MB sind
  damit nicht bestimmt. Wird die Grenze erreicht, endet der Aufruf mit dem Speicherfehler von PHP, ohne Bericht;
  falsch verglichen wird nichts.
- **Trifft Normalfall:** nein. Die Lead stuft G-M2 als Grenze mit «trifft Normalfall: nein» ein; dies ist eine
  Einschätzung, kein gemessener oder nachvollziehbar berechneter Nachweis der Speicherschwelle (`bericht-zweig-2.md`,
  G-M2). Vorschlag aus Zweig 2: den Spitzenspeicher (`memory_get_peak_usage()`) beim Abschluss in einem späteren Zweig
  messen.

### G-M3 `AB_MCP_Sync_Canon::encode()` liefert `''` bei einem Fehler

- **Fundstelle:** `AB_MCP_Sync_Canon::encode()` (`includes/sync/class-sync-canon.php:717`), `hash()` (:726);
  `AB_MCP_Sync_File_Manifest::whole()` (`includes/sync/class-sync-file-manifest.php:250`) gibt bei leerem Text keinen
  Hash (`unreadable`).
- **Was:** Für andere Formen als das Manifest bleibt die Lücke: zwei nicht kodierbare Formen hashen gleich.
- **Trifft Normalfall:** nein (erst ab Tiefe 4096 erreichbar, Formen sind bei 64 abgeschnitten).

### G-M4 Unterbrechung in einer FIFO

- **Fundstelle:** `AB_MCP_Sync_File_Manifest::file()` (`includes/sync/class-sync-file-manifest.php:481`).
- **Was:** Wird ein Pfad zwischen `lstat()` und `fopen()` gegen ein FIFO getauscht, blockiert `fopen()`.
- **Trifft Normalfall:** nein (gleichzeitiger Schreiber mit Absicht).

### G-M5 Prüfsummen nur für Sprachpakete des Kerns

- **Fundstelle:** `AB_MCP_Sync_Core_Compare::check()` (`includes/sync/class-sync-core-compare.php:186`, Sprache :199).
- **Was:** Ohne `$wp_local_package` fragt der Vergleich `en_US`; ein von Hand übersetzter Kern weicht dann ab.
- **Trifft Normalfall:** nein.

### G-M6 Der Kernvergleich fragt DNS

- **Fundstelle:** `AB_MCP_Outbound` (Auflösung vor der Anfrage, `includes/class-outbound.php`).
- **Was:** Auf den Testsites eine DNS-Anfrage je Lesung von `api.wordpress.org`, keine Verbindung.
- **Trifft Normalfall:** nein.

### G-M7 Kernvergleich bei dauerhaft überschrittenem Zeitbudget — entfällt

- **Grund:** In Zweig 2, Behebung 7 (`79477f5`), behoben (`bericht-zweig-2.md`, «Behebung 7»).

### G-M8 Sehr grosse Pfadlisten

- **Fundstelle:** Portionierung der Pfade in `AB_MCP_Sync_Decision` (Folgeeinträge `more_paths`,
  `includes/sync/class-sync-decision.php:1608–1627`); Grenze einer Ablagedatei `AB_MCP_Protected_Store::FILE_MAX`.
- **Was:** Die Portionierung hält die Grenze einer Ablagedatei (16 MiB) nicht in jedem Fall ein (Probe von Codex:
  19'000 Pfade zu je 904 Bytes, 190 Einträge mit 17'303'581 Bytes in einem Berichtsteil). Der Abschluss kann dann
  scheitern; falsche Gleichheit entsteht nicht.
- **Trifft Normalfall:** nein.

### G-M9 Ersetzung in derselben Sekunde mit gleicher Inode

- **Fundstelle:** `AB_MCP_Sync_File_Manifest::file()` und `same()` (`includes/sync/class-sync-file-manifest.php:481`,
  :596).
- **Was:** Wer eine Datei durch eine gleich grosse ersetzt, ihr dieselbe mtime gibt, die alte Inode wiederbekommt und
  das in derselben Sekunde wie der letzte ctime der alten Datei tut, bleibt für `stat()` unsichtbar (ganze Sekunden).
- **Trifft Normalfall:** nein (nur mit Absicht erreichbar).

### G-M10 Frist zwischen letzter Uhrabfrage und Freigabe

- **Fundstelle:** `AB_MCP_Sync_File_Manifest::whole()` (`includes/sync/class-sync-file-manifest.php:250`, `due()` nach
  der Hash-Berechnung und vor der Zuweisung).
- **Was:** Prüfung und Zuweisung sind nicht atomar; der Hash kann bei erreichter Frist gesetzt werden. Betrifft nur
  das Zeitbudget, nicht den Inhalt.
- **Trifft Normalfall:** nein.

### G-M11 Fortschritt beim Durchlauf

- **Fundstelle:** `AB_MCP_Sync_Env::nested()` (`includes/sync/class-sync-env.php:1360`).
- **Was:** Verbraucht das Auflisten eines Unterordners wiederholt das ganze Budget eines Aufrufs (45 s), endet jeder
  Aufruf vor dessen nächstem Eintrag mit demselben Cursor. Keine falsche Vollständigkeit, kein falscher Hash
  (Thomas 12.11.: «Als Grenze, Antwort im PR»; GitHub-Codex auf `f25da74`).
- **Trifft Normalfall:** nein.

### G-M12 Verschachtelte Themes der Standardwurzel

- **Fundstelle:** `AB_MCP_Sync_Env::candidates()` (`includes/sync/class-sync-env.php:1290`), `theme_dirs()` (:809).
- **Was:** Liegen mehrere Themes unter einem gemeinsamen Zwischenordner der Standardwurzel, wird nur der oberste
  Ordner zur Einheit; Änderungen eines einzelnen Themes erscheinen unter dieser gemeinsamen Einheit. Verschachtelte
  Themes sind derzeit nicht einzeln vergleichbar.
- **Trifft Normalfall:** nein.

## Übernommen aus Zweig 2b (`bericht-zweig-2b.md`, Grenzkandidaten und Behebung 1)

### G-2b-1 Neuabgleich eines Widget-Bereichs mit verschlucktem Lesefehler

- **Fundstelle:** `AB_MCP_Sync_Pairs_Tool::confirm()` (`includes/sync/class-sync-pairs-tool.php:942`, Lesen des Paars
  :976 vor `realign()` :980).
- **Was:** Liefert das Lesen des Paars einen Fehler und gelingt das folgende Lesen in `realign()`, wird `base_extra`
  ohne die gehaltene Zuordnung der Instanzen geschrieben (Vorabprüfung 2b, V2; Entscheid der Lead).
- **Trifft Normalfall:** nein.

### G-2b-2 Einheit mit Namen über 191 Zeichen oder gehaltenem Namen

- **Fundstelle:** `AB_MCP_Sync_Pairs::MAX_REF` (`includes/sync/class-sync-pairs.php:332`), Ablehnung :950;
  `AB_MCP_Sync_Unit_Proposal::NO_REF` (`includes/sync/class-sync-unit-proposal.php:70`).
- **Was:** Kein Paar, also kein Tausch; Weg: von Hand übernehmen oder umbenennen.
- **Trifft Normalfall:** nein.

### G-2b-3 Gleichzeitiger Schreiber in einer anderen Verbindung während der Bestätigung

- **Fundstelle:** `AB_MCP_Sync_Pairs::set_unit_base()` (Transaktion mit eindeutigem Schlüssel).
- **Was:** Der Schnappschuss der Transaktion sieht die fremde Zeile nicht; die Antwort nennt die Ablehnung der
  Datenbank statt «There is a pair for this …»; geschrieben wird nichts.
- **Trifft Normalfall:** nein (nur der Wortlaut).

### G-2b-4 Pfade im Vorschlag — entfällt

- **Grund:** Seit Behebung 1 von Zweig 2b (C8) eine Anzeige, keine Grenze: je Liste die ersten 10 mit Gesamtzahl und
  dem Weg zur vollen Liste (`AB_MCP_Sync_Unit_Proposal::compared()`); die Basis bildet sich aus den Zeilen beider
  Seiten, nicht aus der Anzeige.

### G-2b-5 Reflexion im selben Prozess

- **Fundstelle:** `AB_MCP_Sync_Unit_Proof` (`includes/sync/class-sync-pairs.php:131`, privater Konstruktor :139).
- **Was:** PHP-Code im selben Prozess kann per Reflexion ein Objekt am Konstruktor vorbei bilden und private Felder
  setzen; PHP schliesst das im selben Prozess nicht aus. Der Wächter `SyncUnitBaseGuardTest` hält, dass der
  ausgelieferte Code das nicht tut.
- **Trifft Normalfall:** nein.

### G-2b-6 Ende des Prozesses genau beim Kopf eines Vorschlags

- **Fundstelle:** `AB_MCP_Sync_Proposal::propose()` (`includes/sync/class-sync-proposal.php:330`), `find()` (:449).
- **Was:** Eine Gruppe ohne Kopf bleibt bis zur Bereinigung der Ablage (`KEEP['reports']`); `find()` kennt sie nicht
  als Vorschlag, ein neuer Vorschlag beginnt neu.
- **Trifft Normalfall:** nein.
