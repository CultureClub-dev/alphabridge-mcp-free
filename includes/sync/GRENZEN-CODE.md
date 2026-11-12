# Grenzen: Code und Dateien (Z8b)

Benannte Grenzen des Austauschs von Code und Dateien (Plan Z8b, Fassung 4i, Abschnitte 6, 8.2 und 14). Angelegt mit
Zweig 3 (`heikel/z8b-ablage`); Zweig 5 ergänzt die Grenzen aus E1 und E6, Zweig 6 das Entfernen (E7). Hierher kommen
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
  `ablage/input-*`); eine gelockerte Form, gegen die kein fester Fall steht, bleibt unbemerkt.
- **Trifft Normalfall:** nein. Es ist eine Grenze der Prüfung, kein Fehlweg im Code: ein Zugriff dieser Art entsteht
  nur mit einer künftigen Änderung. Die Durchsicht einer Änderung an diesen Funktionen prüft, ob jeder neue Wert eines
  Entscheids als Zeile der Tabelle kommt und ob eine geänderte Form ihren festen Fall hat.

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
