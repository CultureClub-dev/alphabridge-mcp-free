# Grenzen des Laufs (Z8, Zweig «Lauf»)

Gesammelte Gruppe-C-Grenzen des Übernahmelaufs (`wp_site_push`, `wp_site_revert`;
Entscheide 15 bis 20, 23, 32 und 33) für die Abnahme Z11c: seltene oder
konstruierte Fälle, bekannte Grenzen der Bauweise und solche, die eine spätere
Etappe schliesst. Je Grenze ein Satz mit Beispiel und Grund; wo eine
Ablehnung entsteht, nennt sie den Weg.

## Sperre und Unterbrechung (Entscheid 18)

- Ein Request, der nach Ablauf der Sperre (600 s, `AB_MCP_Change_Lock::TIMEOUT`)
  noch in einem WordPress-Schreibaufruf steckt, kann noch schreiben: der
  Lauf gilt dann schon als `interrupted`, und ein Rückweg, der danach
  beginnt, liest einen Teil, den dieser Request noch verändert. Grund: ein
  abgelaufener Herzschlag beweist kein Ende des Requests (Befund R2-5).
  Darum erscheint der Zustand beim Rückweg und beim Schliessen «als geprüft»
  frisch gelesen, und der Rückweg nimmt nur Teile zurück, deren heutiger Hash
  dem Ist-Hash des Journals (oder, ohne Rücklesen, dem Soll-Hash) gleicht;
  alles andere bleibt stehen und ist benannt (Prüffall
  `testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun`).
- Ob die Sperre gehalten wird, liest `AB_MCP_Sync_Run::lock_fresh()` aus der
  Sperrzeile `ab_mcp_pro_code_change.lock`: eine frische Zeile heisst
  «ein Schritt läuft» (`busy`), auch wenn sie eine Aktivierung oder ein
  Deploy durch AlphaBridge hält und nicht der Lauf. Der Lauf unterscheidet
  die Halter nicht; `status` nennt den Schritt, `next` antwortet busy. Grund:
  dieselbe Sperre für Code-Änderungen und Übernahme (Entscheid 18), kein
  eigener Sperrbaustein.
- Ein Prozess, der in einer Grenze endet (`exit`, Fatal Error), löst die Sperre
  über die Shutdown-Funktion von `AB_MCP_Change_Lock`, so dass der nächste
  Aufruf den Lauf sofort als `interrupted` sieht. Fällt die Shutdown-Funktion
  aus (Prozess hart beendet), bleibt die Sperrzeile bis zu 600 s stehen;
  solange antwortet `next` busy. Ob Shutdown-Funktionen bei einem Fatal Error
  laufen, misst ein Folgeposten aus Z7; Z8 baut nicht darauf.
- «Als geprüft schliessen» (Weg a) ist in Zweig 8 ein Klassenweg
  (`AB_MCP_Sync_Run::close_checked()` mit dem Nonce-Platzhalter `$confirmed`),
  kein Werkzeug: die Seite «Staging» von Zweig 12 ruft ihn nach ihrer
  Nonce-Prüfung. Ein Werkzeug könnte den Schritt einer Person nicht ersetzen
  (Entscheid 18).

## Übernahmesperre (Entscheid 19)

- Nicht abweisbar sind `wp_publish_post()` (der Cron veröffentlicht geplante
  Beiträge per SQL), Ändern und Löschen von Termen auf klassischem Weg,
  `wp_set_object_terms()`, `delete_option()` und jeder Schreiber am
  WordPress-Weg vorbei (direktes SQL). Eine solche Zwischenänderung zwischen
  Zielprüfung und Schreiben geht verloren, ohne dass das Rücklesen sie sieht;
  die Vorschau und die Werkzeugtexte sagen das. Das Fenster ist die Dauer
  einer Grenze (Sekunden); die Zielprüfung unmittelbar vor dem Schreiben
  verkleinert es.
- Die Sperre deckt nur die Objekte eines Schritts (IDs des Datensatzes); ein
  neuer Post, den ein Schritt anlegt, hat vor dem Anlegen keine ID. Ein
  anderer Nutzer kann im selben Fenster eine Seite mit demselben Slug anlegen;
  WordPress gibt dann einem der beiden `-2`, und das Rücklesen des Schritts
  meldet die Abweichung (Teil «geschrieben, abweichend», Objekt neu → Paar
  `residual`). Konstruiert; Z9 bringt die Slug-Regel.
- Die Filter der Sperre hängen bei `plugins_loaded` (Priorität 11) auf
  jedem Request einer Site, die je einen Lauf hatte, ob gerade eine Grenze
  läuft oder nicht, und lesen den Datensatz (Option `ab_mcp_sync_boundary`)
  bei jedem gehakten Schreibweg frisch aus der Zeile
  (`AB_MCP_Sync_Guard::holding()`, an alloptions- und notoptions-Cache des
  Requests vorbei): ein Request, der vor `begin()` startete, trifft die
  Grenze trotzdem (Codex-Runde 1, A1). Der Preis ist eine
  Primärschlüssel-Abfrage je Schreibweg (Meta-, Lösch-, Term-, REST-,
  Options-Schreiben) auf dieser Site; Optionen der Gruppe B (Transients und
  die übrigen ausgeschlossenen) werden ohne Abfrage durchgelassen. Ein
  Request, der vor `plugins_loaded` schreibt, trifft die Sperre nicht;
  WordPress bietet davor keine Schreibwege über die API.
- Ob eine Site je einen Lauf hatte, sagt das Merkmal `ab_mcp_sync_guard`
  (`AB_MCP_Sync_Guard::MARK`, autoload: mit den Optionen jedes Requests
  geladen, keine eigene Abfrage): `1` setzt `AB_MCP_Sync_Run::create()`
  vor dem ersten Schritt und `begin()` jeder Grenze; eine Site ohne Lauf
  (`0`) hängt nichts ein und zahlt nichts (Codex-Runde 2, N3). Fehlt das
  Merkmal (Site aus einem Zwischenstand, Option entfernt), leitet
  `guarded()` es einmal aus der Lauftabelle ab und speichert es; ohne
  Tabellen gibt es keinen Lauf (`0`), eine nicht lesbare Tabelle hängt die
  Filter für diesen Request ein und speichert nichts (fail closed). Zurück
  auf `0` geht es nicht: Laufzeilen werden nie entfernt, und der Rückweg
  eines geschlossenen Laufs beginnt jederzeit eine Grenze; die
  Deinstallation entfernt das Merkmal. Grenze (konstruiert, einmal je Site):
  ein Request, der vor dem allerersten `create()` seiner Site
  `plugins_loaded` passierte (Merkmal `0`) und nach Beginn einer Grenze
  dieses ersten Laufs schreibt, wird nicht abgewiesen; die Zielprüfung
  unmittelbar vor dem Schreiben und das Rücklesen bleiben.
- `delete_post_meta_by_key()` übergibt dem Kern keine Objekt-ID und
  `delete_all`; solange eine Grenze irgendeinen Post deckt, ist der Aufruf
  als Ganzes gesperrt (er träfe auch den gedeckten Post), höchstens 600 s.
  Ein Plugin, das in diesem Fenster einen Schlüssel siteweit entfernt, bekommt
  nur `false` und muss es wiederholen (A1): der Kurzschlussfilter
  `delete_post_metadata` kennt keinen Antworttext, WordPress gibt dem
  Aufrufer kein `WP_Error` weiter; Grund und Fenster stehen hier und in den
  Werkzeugtexten, nicht in der Antwort an das Plugin.
- `pre_update_option` (generisch, für jeden Optionsnamen des Datensatzes)
  hält einen Wert «still» (der alte Wert kommt zurück, `update_option()`
  schreibt nichts); der schreibende Request erfährt nichts davon. Entscheid 19
  nimmt das in Kauf; in Zweig 8 deckt kein Schritt Optionen (Zweig 11).

## Vorprüfung der Mittel (Konzept 15.11)

- Die Tempo-Messung schreibt und liest 1 MiB im Ordner der Ablage und liest
  eine Seite Post-IDs; die Hochrechnung auf die Sicherungen des Laufs ist
  grob und heisst darum nur «knapp» (über 30 Minuten), nie «reicht nicht».
  Die 5 MB und 1'000 Zeilen des Konzepts gehören zur Abbild-Engine (Z9a).
- Platz in der Datenbank und Datenbankrechte prüft der Lauf nicht: er legt
  keine Tabelle an und schreibt nur Zeilen, die WordPress selbst schreibt.
- Der Weg 2 der Rückfrage («direkt auf der Live-Site arbeiten») wird in
  Zweig 8 nur genannt (Einstellung «Staging zuerst» aus, oder
  `staging_acknowledged`); die Wahl mit dem Grund «Voraussetzungen fehlen»
  und die Anzeige in der Karte sind Z9a und Zweig 12.
- Die Zeitgrenze misst `max_execution_time` und ob `set_time_limit()` einen
  Wert nimmt; vorgeschaltete Grenzen (FPM, Proxy) misst Z8 nicht, weil ein
  Messrequest an die eigene Adresse bei Hosts ohne Loopback nichts beweist.
  Jeder Schritt endet innerhalb von 60 s (`AB_MCP_Sync_Engine::BUDGET`), die
  Gesamtvorprüfung und die Prüfung des Laufs setzen über Aufrufe fort.

## Post-Art in Zweig 8 (Zweig 9 bringt den Rest)

- Geschrieben werden nur `post` und `page` mit Zeile, Meta von
  `_wp_page_template` und auf vorhandenen Posts einfachen Schlüsseln (kein
  Unterstrich, nicht geschützt, keine Zugangsangabe, kein Builder-Markup, die
  das Konto bearbeiten darf) und Terme von `category`, `post_tag` und
  `post_format`. Alles andere der Post-Art ist fail closed gesperrt mit Grund
  und Etappe (`post:engine_type`, `post:meta_rule`, `post:taxonomy_rule`,
  `post:slug_collision`, `post:sanitizer`: Z9; `post:create_with_object_meta`:
  Z8d), auch ein vorhandener Live-Post mit einem Meta-Schlüssel ausserhalb der
  Regel (er stünde sonst nach dem Schreiben nicht auf dem Sollwert).
- Ein Beitragsbild (`_thumbnail_id`) ist ein Verweis auf ein Attachment; der
  Bericht lässt den Post darauf warten (Medien: Zweig 10). Ein Post mit
  Beitragsbild wird in Zweig 8 nicht übernommen.
- Der Sanitizer-Vorlauf prüft die Felder durch `sanitize_post( …, 'db' )` im
  Rechtekontext des Kontos (kses ohne `unfiltered_html`). Ein Filter, den
  erst `wp_insert_post_data` anwendet, fällt beim Rücklesen auf: Teil
  «geschrieben, abweichend», Zeile bytegenau zurück (`restored`), Schritt
  `restored`; bei einem neuen Objekt bleibt es als Rest (`residual`). Greift
  derselbe Filter auch beim Zurückschreiben der Zeile (`wp_update_post()`
  mit der Sicherung, Entscheid 23), liest sie sich nicht als Sicherung
  zurück: der Teil heisst `unclear` mit Vermerk, das Paar wird *ungeklärt*;
  der Filter ist in wp-admin abzuschalten und der Rückweg neu zu planen.
- Ein Meta-Wert, der serialisiert, aber kein Array ist (`b:1;`, `i:5;`), lässt
  sich über die Metadaten-API nicht byteidentisch ablegen
  (`maybe_serialize()` serialisiert ihn ein zweites Mal); solche Werte sperrt
  `post:meta_rule`. Der Rückweg schreibt roh und ist davon nicht betroffen.
- `term_order` ungleich 0 (sortierbare Taxonomien) stellt
  `wp_set_object_terms()` nicht her; die Abweichung fällt beim Rücklesen auf.
  Kern-Taxonomien sortieren nicht.
- Terme von `post_format` sind Teil der Identität des Posts und werden nach
  Slug verglichen und geschrieben (`IDENTITY_TAXONOMIES`, `term_keys()`): die
  erste Verwendung eines Formats auf der Live-Site hat noch keinen Term, den
  legt `wp_set_object_terms()` an (B6). Terme von `category` und `post_tag`
  nach ihrer Paar-ID.
- `_wp_page_template` geht über `page_template` von `wp_insert_post()`
  (Entscheide 17 und 28): die Vorprüfung verlangt, dass das Live-Theme die
  Vorlage für den Typ anbietet (`WP_Theme::get_page_templates()`), sonst
  Sperre `post:page_template` (WordPress speicherte `default`); der Weg:
  die Vorlage im Live-Theme ergänzen, oder die Seite von Hand übernehmen und
  aus dem Plan lassen (B7). Entfernen läuft über `delete_metadata()`.
- Eigentumsregel (Codex-Runde 3, nach B8 und N2; an einer Stelle:
  `AB_MCP_Sync_Post_Writer::with_row()` nennt, was der Zeilenaufruf neben der
  Zeile schreibt, und mit welchem Hash): Ein Teil ist nur dann eigen, wenn
  der Schritt ihn selbst geschrieben hat – durch den eigenen Teilaufruf oder
  weil sein Feld im `postarr` des eigenen Zeilenaufrufs steht
  (`_wp_page_template` über `page_template`; bei einem Beitrag ohne
  Kategorie – neu, oder hier ohne eine – die Vorgabe-Kategorie, die
  `wp_insert_post()` setzt, bei Neuanlage ohne Rückbau: ein neues Objekt
  wird nie zurückgesetzt) – und das Rücklesen zeigt, was dieser
  Aufruf schreiben sollte. Nur eigene Teile stehen im Journal als `read`
  (gleich dem Soll) oder `written` und gehen in den Rückbau. Jeder andere
  Teil, der sich seit der Sicherung geändert hat, ist eine fremde Änderung
  (ein Hook dieser Site, der auf die Zeile reagiert; ein anderer Schreiber
  auf einem nicht abweisbaren Weg): nie als geschrieben oder gelesen geführt,
  nie zurückgenommen; das Journal sagt es (Vermerk), eine Wende des Schritts
  lässt ihn stehen (`left`) und meldet das Objekt `unclear`, der Rückweg des
  geschlossenen Laufs lässt ihn ebenso. Schreibt der Schritt einen solchen
  Teil danach selbst als Soll, bleibt der Vermerk stehen (das Fenster eines
  nicht abweisbaren Wegs, oben). Ein mit der Zeile geschriebener Teil, der
  anders zurückliest, als die Zeile schreiben sollte (eine Vorlage, die ein
  Filter änderte), ist nicht nachweislich eigen: `left`, Schritt `unclear`,
  nichts geht zurück. Vorher (N2) galt jeder nach der Zeile veränderte Teil
  als «mit der Zeile geschrieben» – auch eine fremde Termänderung auf den
  Sollwert, die der Rückbau dann zurücksetzte und `restored` meldete.
- Verweise auf Objekte, die derselbe Lauf vorher anlegt (Elternteil, Verweis
  im Inhalt), gelten in der Gesamtvorprüfung als ausstehend (`pending`), nicht
  als fehlend; der Schreibschritt mappt sie über das Paar, das der Schritt
  des Ziels anlegte, und verweigert mit `post:reference_unmapped`, wenn es
  fehlt. Eine Seite mit ausstehendem Elternteil ist keine Seite der obersten
  Ebene (Menü-Automatik, Slug-Kollision werden an der Grenze geprüft) (B1).
- Der Autor wird über das Login abgebildet (Entscheid 25); fehlt das Konto
  live, sperrt `post:author_unknown`. Ein Autor mit anderem Login auf beiden
  Sites ist ein anderer Autor.
- Die Kategorie-Vorgabe: WordPress gibt einem neuen Beitrag ohne Kategorie
  `default_category`; der Schritt setzt danach genau die gepaarten Terme. Ohne
  Paar für die Kategorie (auch «Uncategorized» braucht ein Paar) wartet der
  Eintrag im Bericht.

## Rückweg (Entscheid 23)

- Neu Angelegtes bleibt (Posts; in späteren Zweigen Terme, Menüs,
  Attachments), auch veröffentlicht; das Paar wird `residual`, die Antwort
  nennt den Rest mit dem Weg «von Hand entfernen». Ein zweiter Lauf schreibt
  den Rest nicht (Regel 0 des Berichts), bis `wp_site_pairs` ihn bestätigt
  oder verwirft.
- Eine Zeile `future`, deren Termin abgelaufen oder näher als 300 s ist,
  wird nicht zurückgesetzt («Prüfung nötig: Planungstermin abgelaufen»):
  `wp_update_post()` machte sofort `publish` daraus. Das Paar wird
  `unclear`; der Termin wird in wp-admin neu gesetzt.
- Meta geht roh zurück: Zeilen, die der Schritt änderte, nur mit genau den
  Bytes, die er schrieb (`CAST(… AS BINARY)`, `OCTET_LENGTH()`); hinzugefügte
  Zeilen werden nur mit diesen Bytes entfernt, entfernte neu eingefügt (neue
  `meta_id`). Ein Teil, dessen Zeilen jemand seit dem Schreiben änderte,
  bleibt und heisst `left`; das Paar wird `unclear`.
- Der Rückweg eines Teils, der nie zurückgelesen wurde (Request endete
  dazwischen), prüft gegen den Soll-Hash: gleicht die heutige Zeile dem
  Sollwert, hat der Schritt sie geschrieben und sie geht zurück; gleicht sie
  dem Hash vorher, geschah nichts; sonst bleibt sie. Ein fremder Schreiber,
  der im selben Fenster genau den Sollwert schrieb, ist nicht zu
  unterscheiden (konstruiert).
- Der Rückweg eines Schritts kann einmal laufen; ein zweiter Plan für denselben
  Schritt wird abgelehnt (`ab_mcp_sync_revert_steps`).
- Ein Teil «geschrieben, abweichend» (Rücklesen ungleich Soll) auf einem nicht
  abweisbaren Weg (Terme) geht nie zurück, auch wenn der heutige Hash dem
  Ist-Hash gleicht: was zurückgelesen wurde, ist nicht nachweislich eigen.
  Er heisst `left`, das Paar wird `unclear` (Entscheide 17, 23; A2).
- Term-Hashes werden über die Schlüssel als Zeichenketten gebildet
  (`terms_hash()`, seit B6: Formate nach Slug, Kategorien und Schlagwörter
  nach Paar-ID). Ein Journal aus einem Zwischenstand von Zweig 8, dessen
  Term-Hashes noch über Ganzzahlen entstanden, trifft beim Rückweg einen
  anderen Hash: der Teil bleibt als `left` («changed since the step wrote
  them») stehen, das Paar wird `unclear`, die Zuordnung wird in wp-admin von
  Hand zurückgesetzt (Codex-Runde 2, N5). Fail closed, nichts wird
  überschrieben; solche Journale gibt es nur auf Sites eines
  Zwischenstands, kein Tag enthält die Sync-Tabellen.
- Weicht ein Teil innerhalb der Grenze ab, gehen alle eigenen Teile des
  Schritts mit zurück (Eigentumsregel oben: die davor als Soll geschriebenen,
  B8, und die, die der Zeilenaufruf laut `with_row()` schrieb, N2), jeder
  unter derselben Sperre zurückgelesen, der zuletzt geschriebene zuerst; ein
  nicht eigener Teil, der sich seit der Sicherung geändert hat, bleibt
  `left`. Der Schritt heisst nur `restored`, wenn jeder eigene Teil zurück
  ist und sich sonst nichts geändert hat, sonst `unclear` mit dem Zustand je
  Teil im Journal.
- In den Rückweg gehen nur Schritte mit Journal und Sicherung (ohne Sicherung
  keine Mutation, Entscheid 17); ein Schritt, der vor seiner Sicherung
  scheiterte (Ziel seit dem Plan geändert), ist weder Rest noch neu angelegt.
  «Neu angelegt» heisst `op = create` im Journal, nicht «Sicherung fehlt» (B9).
- Stellt der Rückweg für eine Seite der obersten Ebene `publish` oder `future`
  her, solange ein Menü Seiten automatisch aufnimmt, bleibt die Zeile als
  `check_needed` stehen (Entscheide 21, 23; A3): die Automatik abschalten und
  den Rückweg neu planen, oder den Status von Hand setzen.
- Ein Plan hat höchstens `AB_MCP_Sync_Run::MAX_STEPS` = 2000 Schritte (die
  Einträge eines Aufrufs, `AB_MCP_Security`), also
  `AB_MCP_Sync_Push::MAX_OBJECTS` = 1996 Objekte neben dem Rahmen
  (`FRAME` = 4: Gesamtvorprüfung, Caches, Prüfung, ein Reserveschritt). Der
  Rückweg im selben Lauf braucht je geschriebenem Objekt einen Schritt; der
  Lauf fasst dafür `MAX_RUN_STEPS` = 3996 Schritte (4 + 2 × 1996), so dass
  der Rückweg eines vollen Laufs immer Platz hat, und der Plan des Rückwegs
  prüft den Platz vor dem Wechsel auf `reverting` (B5, N4: Zwischenstand
  998). Mehr Objekte gehen in Teilen: `select` mit höchstens 1996 Einträgen
  je Plan statt `all=true`, den Lauf schliessen, dann die nächsten Einträge
  aus demselben `report_id` mit denselben Paketen planen (Prüffall
  `testAReportIsPlannedInPartsOneRunAfterTheOther`); die Ablehnung nennt
  diesen Weg. Was ein voller Lauf kostet, ändert sich mit dem Rückweg
  wenig: `AB_MCP_Sync_Run::steps()` liest je Aufruf alle Schritte des Laufs
  mit Journal; die Schritte des Rückwegs tragen nur den Verweis auf den
  zurückgenommenen Schritt und das Ergebnis je Teil, die Journale der 1996
  Schreibschritte wogen schon vor B5 so viel.
- Der Rückweglauf für einen geschlossenen Lauf entsteht unter der Sperre der
  Ablage (`AB_MCP_Protected_Store::under_lock()`): die Bereinigung liest die
  zu schützenden Läufe und löscht unter derselben Sperre, sieht den neuen
  Lauf also oder war vorher fertig (Entscheid 20 (4); A6). Ohne Ablage gibt es
  nichts zu schützen, der Lauf entsteht ohne Sperre.

## Lauf und Plan (Entscheide 15, 16, 32)

- Die Konflikt-Wahl `staging_wins` schreibt die Staging-Fassung; `keep_live`
  und `leave_open` schreiben nichts und ändern die Ausgangsstände nicht. Ein
  Paar `unclear` wird nicht geschrieben, bis `wp_site_pairs` seine
  Ausgangsstände bestätigt.
- Der Plan sperrt eine Gruppe mit gegenseitigen notwendigen Verweisen
  (`*:mutual_references`, Z11c) und alles, was auf ein gesperrtes Mitglied
  verweist; der Bericht hat dieselben Einträge schon als `locked` oder
  `waits`.
- Die Phasen laufen in Reihenfolge (Entscheid 16): ein Schritt der Phase 7
  (Nachbearbeitung) oder 8 (Prüfung) läuft, auch per `step`, erst, wenn jeder
  Schritt einer tieferen Phase gelaufen ist, gleich mit welchem Ausgang;
  `step` auf einen früheren wird mit Weg abgelehnt (B2). `next` hielt die
  Reihenfolge schon.
- Die Gesamtvorprüfung trägt ihre Befunde mit dem Cursor über Aufrufe; ein
  Aufruf, der Befunde fand und an der Zeitgrenze endete, verliert sie nicht,
  der Lauf schliesst `closed_aborted` (B3).
- `all=true` nimmt jeden Eintrag mit `take_over` oder `create` der Post-Art;
  was der Lauf nicht schreiben kann, nennt die Vorschau in `not_written`, ohne
  den Plan abzulehnen. Ausgewählte Einträge (`select`) ausserhalb der Regel
  erscheinen in `locked`; der Plan läuft mit dem Rest, sofern einer bleibt.
- Der Token bindet die frisch gelesenen Hashes der Live-Objekte und die
  Schnappschüsse der Paare; die Gesamtvorprüfung (Schritt 1) liest beides
  erneut und bricht bei einer Abweichung ab (`closed_aborted`). Zwischen
  Schritt 1 und einem späteren Schreibschritt prüft die Grenze das Ziel ein
  drittes Mal; ein Schritt, dessen Ziel sich änderte, wird `failed`, der Lauf
  läuft mit den übrigen weiter.
- Ein Bericht ohne Umgebung (`wp_site_diff` ohne `environment`) läuft: die
  Vorschau sagt «environment not compared», die Gesamtvorprüfung notiert es.
  Grund: der Lauf schreibt in Zweig 8 nur Objekte; die Umgebung wird in keiner
  Etappe von Z8 übernommen. Fail closed wäre hier eine Sperre ohne Nutzen.
- `abort` schliesst einen offenen Lauf; Geschriebenes bleibt mit neuen
  Ausgangsständen. Der Rückweg ist `wp_site_revert` für den geschlossenen
  Lauf. Ein unterbrochener Lauf wird nicht abgebrochen (nur geprüft oder
  zurückgenommen).
- Die Erfassung je Lauf (Momente M2 und M8): `calls` zählt jeden Aufruf ab
  der bestätigten Anlage (Anlage, Schritte, Statusabfragen, Wiederholungen),
  `duration` ist `closed − created`. Vorschau-Aufrufe vor der Anlage zählen
  nicht.

## Aufbewahrung (Entscheid 20)

- Die Bereinigung läuft beim Öffnen einer Gruppe der Ablage und nach jedem
  Abschluss (`AB_MCP_Protected_Store::cleanup()`); der vierte qualifizierte
  Abschluss verdrängt die Laufsicherungen des ersten beim nächsten Durchlauf.
  Eine Sicherung, die ein offener oder unterbrochener Rückweg braucht (Lauf
  vom Typ `revert` mit `reverts` im Plan, oder der eigene Lauf in `reverting`),
  bleibt, solange dieser nicht geschlossen ist.
- Sicherungen eines Laufs ohne Zeile in `runs` (eine Gruppe, deren Lauf nie
  angelegt wurde, oder deren Zeile mit einer aus einer älteren Kopie
  zurückgespielten Datenbank verschwand) folgen der Altersregel ihres
  Bereichs wie eine Sicherung ohne Lauf: sie bleiben so lange wie die eines
  nicht qualifizierten Abschlusses (7 Tage Free, 30 Tage Pro;
  `AB_MCP_Protected_Store::keep()`), dann gehen sie. Sofortiges Löschen
  wäre nicht fail closed: der Lauf ist nicht lesbar, nicht beendet. Der Lauf
  legt seine Zeile vor der ersten Sicherung an, im Normalfall gibt es solche
  Gruppen also nicht. Sicherungen eines Laufs mit Zeile folgen allein dem
  Vertrag, wie die Zeile ihn hält (`AB_MCP_Sync_Schema::backup_rows()`:
  `true` bleibt, `false` geht, wie jung die Gruppe auch ist).
- Kann die Tabelle der Läufe nicht gelesen werden, bleibt jede Laufsicherung
  (`backup_rows()` gibt `null`), nie umgekehrt.
- Jeder Abschluss speichert die beim Abschluss geltende Frist in
  `runs.keep_until` (Schema Version 3; `AB_MCP_Sync_Schema::keep_until()`):
  qualifiziert so viele Tage, wie der Filter `ab_mcp_staging_backup_days`
  sagt (Free 0: die Frist endet mit dem Abschluss, es bleiben die letzten
  drei; Pro 30, Zweig 15), nicht qualifiziert (`closed_aborted`,
  `closed_checked`, `closed_reverted`, `revert`-Läufe) 7 Tage Free bzw. 30
  Tage Pro (Entscheid 20 (5)); nie 0. Die Bereinigung liest nur die
  gespeicherte Frist: Pro → Free behält jede Frist, Free → Pro verlängert
  keine (Entscheid 20 (6); A7).
- Zeilen ohne gespeicherte Frist (`keep_until = 0`: Abschlüsse der Schema
  Version 2, vor der Frist) löscht die Bereinigung nie, qualifiziert oder
  nicht, wie alt auch immer: keine gespeicherte Frist sagt, was ihrem
  Abschluss versprochen war (ein unter Pro qualifizierter Abschluss hätte 30
  Tage), also fail closed; ihre Sicherungen bleiben, bis ein späterer Zweig
  die Fristen nachträgt (Codex-Runde 2, N1). Solche Zeilen gibt es nur auf
  Sites eines Zwischenstands von Zweig 8: main hat Version 1 ohne
  schreibenden Lauf, kein Tag enthält die Sync-Tabellen.

## Prüfung des Laufs (Entscheid 33)

- Die Prüfung läuft wie die Gesamtvorprüfung über Aufrufe (Stufen `hashes`
  mit Cursor, `sample`, `probe`, `log` im Ergebnis des Schritts); jeder Aufruf
  erledigt mindestens eine Einheit, so dass sie immer vorankommt.
  `closed_done` nur, wenn jede Stufe lief und bestand (B4).
- Die Stichprobe fragt eine veröffentlichte Adresse mit `wp_remote_get()`
  (Loopback) und verlangt 200 und die erwartete Zeichenkette: den Titel des
  Sollwerts, wie die Seite ihn zeigt (roh, `esc_html()`, texturiert). Hosts
  ohne Loopback antworten nicht → Befund «no answer»; ein Theme ohne Titel
  oder ein Seiten-Cache mit alter Seite → Befund «did not show the title»;
  der Lauf schliesst `closed_findings`, nicht `closed_done`. Grenze: eine
  veraltete Cache-Seite mit unverändertem Titel besteht die Probe; Phase 7
  leert nur bekannte Caches. Die Integrationstests ersetzen die Probe
  (`AB_MCP_Sync_Engine::$probe`: Status und Body), weil `php -S` den Loopback
  zum Test zwar bedient, aber die Aussage der echten Live-Probe gehört.
- Die PHP-Probe (`AB_MCP_Php_Probe::run()`) muss ganz bestehen (`passed`);
  `fatal`, `partial` und `unreachable` sind Befunde (B4). Für die Probe bleibt
  die restliche Zeit des Aufrufs, mindestens 1 s.
- Das Fehlerlog wird über die Zeilenzahl von `debug.log` verglichen; ohne
  `WP_DEBUG_LOG` gibt es keinen Befund.

## Prüfung

- Unit (`tests/SyncRunTest.php`): Registrierung und `when`, Mittel, Sperre
  (auch Request vor der Grenze, `delete_all`; Filter nur auf Sites mit Lauf,
  Merkmal abgeleitet, fail closed), Post-Engine (Sollwert, Sperren, Hashes
  je Teil, Formate nach Slug, Vorlage über den Kernweg, ausstehender
  Elternteil), Lauf (Form, Verbindung, frische Sperre, Platz für den Rückweg
  mit `MAX_RUN_STEPS`, Ablehnung über `MAX_OBJECTS` mit dem Weg in Teilen),
  Phasenreihenfolge, Stichprobe mit Titel, Aufbewahrung aus den Zeilen mit
  `keep_until` (auch Zeilen ohne Frist), Paket aus einer Lesung mit
  geprüften Termnamen, Plan (`verdict`, `order`, `line_of`). Integration
  (`tests/integration/SyncRunTest.php`, einundzwanzig Fälle): Ende zu Ende mit
  Rückweg und `step` auf eine spätere Phase (Merkmal der Sperre beim
  Anlegen), ein Bericht in zwei Läufen geplant, Abweichung in der
  Gesamtvorprüfung, Unterbrechung in eigenem Prozess, bytegenau, Sperrmatrix
  über REST (Request vor der Grenze), Sollwert «als Entwurf übernehmen»,
  zeitabhängiger Status, Aufbewahrung mit Fristen (auch Zeilen ohne Frist),
  neue Eltern- und Unterseite, Befunde über Aufrufe, Prüfung mit Titel und
  Cursor, Vorlage über den Kernweg, abweichender Meta-Teil nimmt die Zeile
  mit, mit der Zeile geschriebene Vorlage geht mit der Zeile zurück, fremde
  Kategorie auf dem Sollwert ist nicht eigen und bleibt (Eigentumsregel),
  Vorgabe-Kategorie eines neuen Beitrags ist mit der Zeile eigen,
  gescheiterter Schritt ohne Rest, abweichende Terme bleiben, kein
  Wiederveröffentlichen bei Menü-Automatik, Rückweglauf unter der
  Ablagesperre. Mutationen: `tests/mutation/lists/lauf.php` (aus
  `tests/mutation/gen/lauf.py`).
- Nicht als Mutation geführt: die Wächter gegen eine Datenbank, die ein Lesen
  oder Schreiben verweigert (keine Test-Site verweigert), das Budget der
  Gesamtvorprüfung über Aufrufe mit Tausenden Objekten (die Fortsetzung selbst
  hält ein Test mit gestellter Uhr), die PHP-Probe (ersetzt: im Test besteht
  sie; dass `partial` und `unreachable` Befunde sind, zeigt nur die Live-Probe),
  die Kapazitätsprüfung des Rückwegs im Plan (mit 1996 Objekten je Lauf und
  3996 Schritten je Lauf nicht erreichbar), der Schritt ohne Sicherung in `revert_boundary()` (der Plan
  lässt ihn nicht hinein), die belegte Sperre (`busy`: ein zweiter Request
  derselben Site im selben Augenblick) und die Texte der Ablehnungen
  (`AnswerWayTest` hält, dass jede einen Weg nennt).
