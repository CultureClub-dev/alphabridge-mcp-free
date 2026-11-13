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
    beide, und ein Abbruch in der Kopie lässt ein halbes Ziel. Die Prüfung in `write_file()` sieht das nicht.
  - **Ordner:** `php_copy_file_ctx()` (`ext/standard/file.c`) lehnt einen Ordner als Quelle ab («The first argument to
    copy() function cannot be a directory», `FAILURE`); `rename()` gibt dann `false` mit einer Warnung, und nichts ist
    verschoben. Ein Tausch eines Ordners über eine solche Grenze scheitert also, statt nicht atomar zu kopieren.
    Gelesen im Quelltext von PHP 8.4 (`PHP-8.4`, 12.11.2026), nicht in der CI geprobt.
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
