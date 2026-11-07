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
  frisch gelesen, und der Rückweg nimmt nur Teile zurück, die der Schritt
  nach seinem eigenen Aufruf zurückgelesen hat (`own`, `own_after`) und deren
  heutiger Hash `own_after` gleicht; ein Teil ohne Rücklesen (der Request
  endete im Aufruf) bleibt `left` mit altem Wert, auch wenn er den Sollwert
  hält: ein Hash gleich dem Soll beweist kein Eigentum (Entscheid 18 (b);
  Entscheid A vom 07.11.2026, unten; Prüffälle
  `testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun`,
  `testARequestThatEndsInsideAMetaCallLeavesThePartForTheWayBackOfTheSameRun`).
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

## Die eine Grenze: Abweichung im Schritt (Grenze C; Entscheid A vom 07.11.2026, «mit altem Wert»)

- Weicht bei einem Objekt im Schritt irgendein Teil ab, nehmen weder der
  Rückbau im Schritt noch `wp_site_revert` das Objekt automatisch zurück
  (`AB_MCP_Sync_Engine::deviated()`). Abweichung heisst: ein Hook oder eine
  fremde Hand hat einen Teil verändert (Vermerk `foreign`: `pre ≠ Sicherung`
  vor dem eigenen Aufruf, oder eine Änderung mit der Zeile ausserhalb dessen,
  was `with_row()` nennt; auch wenn der eigene Aufruf den Teil danach als Soll
  schrieb); ein eigener Aufruf wurde verweigert oder ist gescheitert (ob er
  den Teil vorher veränderte oder nicht; auch ein Teil, der vor seinem Aufruf
  nicht lesbar war); ein Wert liest anders zurück, als der Aufruf ihn
  schreiben sollte (eigener Aufruf: das Soll; Zeilenaufruf: was `with_row()`
  nennt; das Objekt als Ganzes: der Soll-Hash). Dann trägt das Objekt im
  Journal `object.deviated` (Teil und Grund), jeder Teil das Merkmal
  `deviated`, jeder Teil, der nicht der Sicherung gleicht, wird `left` mit
  `value_before` (dem alten Wert aus der Sicherung), der Schritt `unclear`
  (`failed`, wenn kein Teil von der Sicherung abweicht: der Zeilenaufruf
  wurde verweigert, bevor er schrieb; Prüffall
  `testARowCallRefusedBeforeItWroteFailsTheStepAndNothingIsLeft`), das Paar
  `unclear`. `decide()` gibt für jeden Teil eines solchen Objekts `left`
  zurück, auch auf dem Rückweg eines geschlossenen Laufs; ein Teil, der der
  Sicherung gleicht, heisst dort `unchanged`. Die Sicherung bleibt nach
  Entscheid 20. Weg: das Objekt in wp-admin von Hand prüfen, der alte Wert
  steht in der Antwort (`value_before`), das Paar mit `wp_site_pairs`
  bestätigen (Prüffälle
  `testADeviatingMetaPartLeavesTheObjectWholeWithTheOldValuesInTheAnswer`,
  `testADeviatedObjectIsLeftWholeOnTheWayBackOfTheClosedRunToo`).
- Grund: Seit Codex-Runde 2 fand jede Runde einen neuen A-Befund derselben
  Klasse (welche Teile ein Schritt nachweislich geschrieben hat und was
  Rückbau und Rückweg zurücknehmen dürfen; Runde 7: F1–F3 nach dem Umbau auf
  das Eigentumsmerkmal). Mit der Grenze entfallen: der Rückbau im Schritt
  (die Wende; B8, N2, N4), die Sonderregel für abweichende Terme (A2), die
  Übernahme des Eigentums an der Vorgabekategorie beim Zeilen-Rückbau (F2)
  und der Rückweg über den Soll-Hash für einen unterbrochenen Schritt (alte
  Grenze C); `put_back()` läuft nur noch auf dem Rückweg.
- Ein Objekt ohne Abweichung verhält sich wie bisher: der Schritt endet
  `done`, jeder Teil `read`, eigen (`own`, `own_after`), wo der eigene Aufruf
  ihn veränderte, und der Rückweg nimmt einen eigenen Teil zurück, dessen
  heutiger Hash `own_after` gleicht (Prüffall
  `testAPostWithACategoryAndATagAndAPageWithATemplateGoOverAndBackWithEveryPartRestored`).
  Eine Unterbrechung ist keine Abweichung des Objekts: die zurückgelesenen
  eigenen Teile gehen zurück, der nie zurückgelesene bleibt `left`.
- Der alte Wert (`value_before`; `AB_MCP_Sync_Post_Writer::before_value()`):
  bei der Zeile die Spalten, die vom heutigen Stand abweichen, je mit Wert in
  Anführungszeichen (`post_title: "Contact"`); lange Texte (`post_content`,
  `post_excerpt`, `post_content_filtered`) nur nach Länge und mit der
  Revision des Posts, die den Text bytegenau hält, sonst mit dem Hinweis auf
  die Sicherung des Schritts (`post_content: 260 bytes (revision 702 of the
  post holds it, wp-admin → Revisions)`); bei Meta die Werte des Schlüssels
  in der Reihenfolge ihrer Zeilen (`"abc", "def"`; `none (no row of the key)`
  ohne Zeile); bei Termen die Slugs, die die Sicherung hält (`"news",
  "other"`), nie der Name, den ein Term heute hat: ein seither umbenannter
  Term, auch auf ein Zugangsdatum, ist kein alter Wert (Codex-Runde 8).
  Geheimnisprüfung wie beim Paket (`AB_MCP_Sync_Credentials::unit()` über
  Schlüssel und Wert mit `AB_MCP_Redactor::extra_keys()`, dazu das Netz über
  den rohen Wert in jeder Schicht, `AB_MCP_Sync_Net::over_name()`, wie
  `AB_MCP_Sync_Import::held_code()`; `post_password` nach Namen): ein Wert, den sie
  trifft, erscheint nur als Verweis (`(a credential by its name, not shown)`,
  `(holds a credential, not shown)`), kein UTF-8 nur als Byteszahl. Je Wert
  höchstens `VALUE_CHARS` = 80 Zeichen, je Teil `PART_CHARS` = 320, mit
  «…(cut)»; je Antwort höchstens `AB_MCP_Sync_Run::ANSWER_VALUE_BYTES` =
  16 KiB über alle gezeigten Schritte, die neuesten zuerst, danach `VALUE_CUT`
  mit dem Hinweis auf die Sicherung; die Hinweise zählen zum Budget, darüber
  hinaus fehlt der Wert in der Antwort (Codex-Runde 8; Prüffälle
  `testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped`,
  `testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst`).
- Journale aus einem Zwischenstand dieses Zweigs ohne den Schlüssel
  `object.deviated` sagen nicht, ob das Objekt im Schritt abwich: der Rückweg
  behandelt sie wie abgewichen (`deviated = earlier_build`, jeder Teil
  ungleich Sicherung `left` mit altem Wert; fail closed). Solche Journale
  gibt es nur auf Sites eines Zwischenstands, kein Tag enthält die
  Sync-Tabellen. Nicht als Mutation geführt (kein Lauf dieses Stands schreibt
  ein solches Journal).
- Ein Journal, das einen Teil als von fremder Hand geändert vermerkt
  (`foreign`, im Schritt vor dem eigenen Aufruf gelesen und vor dem Aufruf
  gespeichert), ohne dass das Objekt als abgewichen markiert ist (der Request
  endete dazwischen): Der Rückweg und seine Vorschau behandeln das ganze
  Objekt wie abgewichen (`deviated = foreign_mark`, nichts geht zurück, alter
  Wert in der Antwort; Codex-Runde 8). Weicht im Schritt nichts von der
  Sicherung ab (etwa ein Zeilenaufruf, der vor dem Schreiben verweigert
  wurde), trägt das Objekt keine Marke: der Schritt heisst `failed`, die
  Vorschau des Rückwegs nennt nichts als stehen gelassen.
- Im gewöhnlichen Rückweg erreichbar (Lead an die Steuerung): ein Beitrag,
  dessen Schritt alle Kategorien geleert hat (auf Staging ohne Kategorie, nur
  am Editor vorbei möglich), bekommt beim Zeilen-Rückbau von
  `wp_update_post()` die Vorgabekategorie; die Terme sind damit nicht mehr
  eigen und bleiben `left` (Vorgabekategorie statt «keine», alter Wert in der
  Antwort), der Schritt des Rückwegs `unclear` (Prüffall
  `testTheDefaultCategoryTheRowGivesBackLeavesTheTermsOfAPostTheStepEmptied`).
  Ein Beitrag ohne Kategorie auf beiden Seiten, dem Staging eine gibt, geht
  ganz zurück (Prüffall
  `testTheWayBackPutsTheRowBackBeforeTheTermsSoAPostWithoutACategoryStaysWithout`).

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
  erst `wp_insert_post_data` anwendet, fällt beim Rücklesen auf: die Zeile
  liest anders zurück, als der Aufruf sie schreiben sollte, das Objekt ist
  abgewichen (Grenze C): die Zeile bleibt `left` mit altem Wert, Schritt und
  Paar `unclear`; bei einem neuen Objekt bleibt es als Rest (`residual`). Der
  Filter ist in wp-admin abzuschalten, das Objekt von Hand zu prüfen
  (Prüffall `testARowAFilterChangedLeavesTheObjectWithTheTemplateWrittenWithIt`).
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
- Eigentumsregel (Codex-Runde 6, nach sechs Funden derselben Klasse seit
  Runde 2: Eigentum wurde aus Zustandsnamen abgeleitet, `own_hash()` über
  `state`; Zustände sagen aber, was geschah, nicht, wer den heutigen Inhalt
  schrieb). **Eigentum ist ein eigenes Merkmal im Journal, je Teil `own`
  (bool), `own_after` (Hash) und `own_target` (was der Aufruf schreiben
  sollte); kein Zustand entscheidet darüber.** Unmittelbar vor jedem eigenen
  Schreibaufruf liest der Schritt den Teil unter der Sperre frisch (`pre`);
  nach dem Aufruf, ob er `true` oder einen `WP_Error` gab, liest er ihn zurück
  (`post`, zugleich `after`). `own = true`, `own_after = post` genau dann,
  wenn `post ≠ pre`: der Aufruf hat den Teil verändert. `post == pre`: der
  Aufruf hat nichts verändert, `own` bleibt, wie es war. Für die Teile, die
  der Zeilenaufruf laut `AB_MCP_Sync_Post_Writer::with_row()` mitschreibt
  (`_wp_page_template` über `page_template`; die Vorgabe-Kategorie eines
  Beitrags ohne Kategorie), ist `pre` der Stand vor dem Zeilenaufruf, und
  eigen wird nur, was genau so zurückliest, wie `with_row()` es nennt; jede
  andere Veränderung mit der Zeile ist fremd (ein Hook dieser Site, der auf
  die Zeile reagiert): Vermerk `foreign`, nie Eigentum. Weicht `pre` von der
  Sicherung ab, hat eine andere Hand den Teil vor dem Aufruf geändert:
  Vermerk, nie Eigentum (N1: ein Hook setzte `note=fremd` mit der Zeile, der
  eigene Meta-Aufruf wurde verweigert; vorher galt `after ≠ Sicherung` als
  eigene Teilmutation, der Fremdwert wurde zurückgebaut und `restored`
  gemeldet). Jede solche Abweichung (Vermerk `foreign` an irgendeinem Teil,
  ein verweigerter oder gescheiterter Aufruf, ob er den Teil veränderte oder
  nicht, ein Rücklesen ungleich dem, was der Aufruf schreiben sollte) beendet
  den Schritt nach Grenze C: nichts wird zurückgebaut, jeder Teil ungleich
  Sicherung bleibt `left` mit altem Wert (Prüffälle
  `testAMetaCallThatChangedThePartAndThenFailedLeavesTheObjectWithTheOldValues`
  für P1, `testAChangeByAnotherHandBeforeARefusedMetaCallIsNotTheStepsOwnAndStays`
  für N1, `testATemplateAHookChangedWithTheRowIsNotTheStepsOwnAndTheWayBackLeavesIt`
  für N2). Zustände bleiben Beschreibung: `read` (gleich Soll), `failed`
  (der Aufruf antwortete mit einem Fehler; der Vermerk sagt, ob er den Teil
  veränderte), `left` (abgewichenes Objekt, Teil ungleich Sicherung),
  `not_reached`; auf dem Rückweg `restored`, `unchanged`, `unclear`,
  `check_needed`, `left`.
- Die Entscheidung steht an einer Stelle, rein, ohne WordPress-Aufruf und
  ohne Blick auf den Zustand: `AB_MCP_Sync_Engine::decide( $part, $now,
  $backup )` gibt `unchanged`, `put_back` (mit dem Hash, den der Teil beim
  Zurückschreiben halten muss) oder `left` (mit Grund). Der Rückweg ruft sie
  je Teil unmittelbar vor seiner Reihe mit frisch gelesenem `$now`;
  `put_back()` führt aus. Die Tabelle (`B` Sicherung, `W` `own_after`, `T`
  Soll, `X` anderer Wert; der Weg der Teile und der Vermerk `foreign` ändern
  seit Entscheid A nichts am Ergebnis, weil jede Abweichung den Schritt
  beendet; Prüffall
  `testDecideOverEveryCombinationOfOwnershipStateDeviationWayAndForeignMark`,
  alle 80 Kombinationen von `own` × `$now` × `deviated` × Weg × Vermerk):

  | `own` | `$now` | `deviated` | Ergebnis |
  |---|---|---|---|
  | beliebig | `null` (nicht lesbar) | beliebig | `left` |
  | beliebig | `B` | beliebig | `unchanged` |
  | beliebig | `W`, `T` oder `X` | ja | `left` («the object deviated within its step», Grenze C; `earlier_build`: Journal ohne den Schlüssel `object.deviated`) |
  | ja | `W` | nein | `put_back` (Hash `W`) |
  | ja | `T` oder `X` | nein | `left` («changed since the step wrote it») |
  | nein | `W`, `T` oder `X` | nein | `left` («not the step's own»: fremd; nie erreicht; nie zurückgelesen; kein eigener Aufruf veränderte ihn) |

  Zusätzlich: `own` mit `own_after == T` (als Soll gelesen, der Normalfall)
  geht zurück, wenn `$now == T`; `own` ohne `own_after` besitzt nichts; ein
  Journal älterer Form ohne das Feld `own` besitzt nichts und geht nie zurück
  (Codex-Runde 7, F3; seit Entscheid A ohne eigene Klausel, Prüffall
  `testAJournalWithoutTheMarkOfOwnershipIsNeverPutBack`). Ein Teil, der nie
  zurückgelesen wurde (`post` null, unterbrochener Schritt), geht nie zurück,
  auch nicht mit heutigem Hash gleich dem Soll: ein Hash gleich dem Soll
  beweist kein Eigentum (Entscheid 18 (b); vorher Grenze C über den
  Soll-Hash).
- Vor jedem eigenen Meta- und Term-Aufruf steht das Journal mit dem Teil
  ohne Rücklesen (`post = null`) in der Datenbank (Codex-Runde 7, F1), nur
  für die Anzeige des Teilstands: Endet der Request im Aufruf, zeigt das
  Journal den Teil ohne Rücklesen, nie mit dem Rücklesen des Zeilenaufrufs
  davor; der Rückweg lässt ihn `left` mit altem Wert, so oder so. Der
  Term-Aufruf ist dabei nicht als Mutation geführt (derselbe Speicherpunkt
  wie beim Meta-Aufruf; ein Abbruch genau im Term-Aufruf lässt sich ohne
  einen Haken, der schon beim Zeilenaufruf feuert, nicht stellen).
- Einen einfachen Schlüssel, den nur die Live-Seite trägt, entfernt der
  Schritt (`write_meta()` ohne Wert); die Metadaten-API fragt selbst kein
  Recht. Darum prüfen Gesamtvorprüfung und Grenze je solchem Schlüssel
  `delete_post_meta` über `map_meta_cap` (Entscheid 28; GitHub-Codex 1, P2),
  wie `edit_post_meta` für die Schlüssel des Sollwerts; verweigert, sperrt
  `ab_mcp_sync_rights` mit dem Schlüssel. `_wp_page_template` geht mit
  `edit_post` (eigene API, Entscheid 28).
- Meta-Schlüssel mit Backslash: `update_metadata()`, `add_metadata()` und
  `delete_metadata()` entfernen Backslashes auch aus dem Schlüssel
  (`wp_unslash()` in `wp-includes/meta.php`); `note\path` roh übergeben
  landete als `notepath` (GitHub-Codex 1, P2). `write_meta()` übergibt den
  Schlüssel wie die Werte mit `wp_slash()`; alle anderen Stellen nehmen ihn
  roh: das Lesen aus den Zeilen, `map_meta_cap`, `is_protected_meta()` und
  der rohe Rückweg. Keine Sperre, weil sonst jeder Live-Post mit einem solchen
  Schlüssel ohne Gewinn gesperrt wäre.
- Dieselbe Regel gilt für den Rückweg (Codex-Runde 4): Endet ein Schritt in
  seinem Request selbst (`done`, abgewichen, gescheitert), trägt sein Journal
  die Marke `settled`, und die Teile, die er nicht mehr erreicht hat, heissen
  `not_reached` (`AB_MCP_Sync_Engine::settled()`; Beschreibung). Der Rückweg
  nimmt sie nie zurück, weil sie nicht eigen sind (`own = false`): `unchanged`,
  wo sie der Sicherung gleichen, sonst `left`. Ein Schritt, dessen Request
  vor dem Ende abbrach (`interrupted`, Journal ohne `settled`), hinterlässt
  Teile ohne Rücklesen (`post` null, Zustand `planned`): auch sie nicht eigen,
  `left` mit altem Wert (Entscheid A; vorher ging ein solcher Teil über den
  Soll-Hash zurück). Nicht als Mutation geführt: die Marke `settled` selbst
  (nur Beschreibung des Zustands `not_reached`; messbar nur, wenn ein regulär
  beendeter Schritt einen Teil ohne `post` hinterlässt, also die Datenbank
  unmittelbar nach dem Schreiben nicht mehr liest).
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
- Der Rückweg eines Teils eines unterbrochenen Schritts, der nie
  zurückgelesen wurde (`post` null: der Request endete zwischen Aufruf und
  Rücklesen), lässt ihn `left` mit altem Wert, auch wenn die heutige Zeile
  dem Sollwert gleicht: ein fremder Schreiber, der im selben Fenster genau
  den Sollwert schrieb, ist nicht zu unterscheiden, und ein Hash gleich dem
  Soll beweist kein Eigentum (Entscheid 18 (b): nur nachweislich eigene
  Teile; Entscheid A). Gleicht er dem Hash vorher, geschah nichts
  (`unchanged`). Die zurückgelesenen eigenen Teile desselben Schritts (die
  Zeile vor dem Meta-Aufruf) gehen zurück; der Lauf schliesst mit Befund
  (`closed_findings`), die Person prüft den Teil von Hand.
- Der Rückweg eines Schritts kann einmal laufen; ein zweiter Plan für denselben
  Schritt wird abgelehnt (`ab_mcp_sync_revert_steps`, ohne `steps`:
  `ab_mcp_sync_revert_nothing`). Gezählt werden alle Rückweg-Schritte, die den
  Schritt nennen, gleich in welchem Zustand: im selben Lauf (Rückweg eines
  nicht geschlossenen Laufs, Entscheid 15) und in jedem Lauf vom Typ `revert`,
  der den Lauf in `plan.reverts` nennt (`AB_MCP_Sync_Run::reverts_of()`).
  Vorher sah der Plan nur im schreibenden Lauf nach; ein zweiter Rückweglauf
  desselben geschlossenen Laufs nahm die Schritte erneut zurück und machte
  eine spätere Übernahme desselben Objekts rückgängig (GitHub-Codex 1, P2;
  Prüffall `testAStepGoesBackOnceAlsoAcrossRunsOfTheWayBack`).
- Ein Teil «geschrieben, abweichend» (Rücklesen ungleich dem, was der
  Aufruf schreiben sollte), auf welchem Weg auch immer, ist eine Abweichung
  des Objekts (Grenze C): es bleibt ganz `left`, im Schritt und auf dem
  Rückweg (die frühere A2-Klausel in `decide()` für Terme ist darin
  aufgegangen; Prüffall `testADeviatingTermPartLeavesTheObjectWhole`). Beim
  Zeilen-Rückbau ist jede Veränderung eines anderen Teils fremd, auch die
  Vorgabekategorie, die `wp_update_post()` einem Beitrag ohne Kategorie gibt:
  keine Übernahme von Eigentum mehr (Codex-Runde 7, F2); der Teil bleibt
  `left` mit altem Wert (`unchanged`, wenn er so der Sicherung gleicht), der
  Schritt des Rückwegs `unclear` (Prüffall
  `testTheDefaultCategoryOfTheRowGoingBackDoesNotTakeBackTermsAnotherHandChanged`:
  Soll gleich Vorgabekategorie, eine andere Hand entfernte alle Kategorien,
  der Zeilen-Rückbau gibt die Vorgabekategorie wieder, die Terme bleiben, News
  wird nicht darübergeschrieben).
- Term-Hashes werden über die Schlüssel als Zeichenketten gebildet
  (`terms_hash()`, seit B6: Formate nach Slug, Kategorien und Schlagwörter
  nach Paar-ID). Ein Journal aus einem Zwischenstand von Zweig 8, dessen
  Term-Hashes noch über Ganzzahlen entstanden, trifft beim Rückweg einen
  anderen Hash: der Teil bleibt als `left` («changed since the step wrote
  them») stehen, das Paar wird `unclear`, die Zuordnung wird in wp-admin von
  Hand zurückgesetzt (Codex-Runde 2, N5). Fail closed, nichts wird
  überschrieben; solche Journale gibt es nur auf Sites eines
  Zwischenstands, kein Tag enthält die Sync-Tabellen.
- Rückbau gibt es nur auf dem Rückweg (`AB_MCP_Sync_Engine::put_back()` mit
  `decide()`; GitHub-Codex 1, P2; Codex-Runde 6; Entscheid A): (1) zurück
  geht zuerst die Zeile, dann Meta, dann die Zuordnungen, weil
  `wp_update_post()` Nebenwirkungen auf die Terme hat; (2) jeder Teil wird
  unmittelbar vor seiner Reihe frisch gelesen und am Ist-Stand entschieden,
  nie an einem Schnappschuss von vor dem Zeilen-Rückbau (N3: vorher prüfte
  `restore_terms()` den übergebenen Stand von vor dem Zeilen-Rückbau; ein
  Hook, der beim Zurückschreiben der Zeile Kategorie C setzte, wurde mit A
  überschrieben und `restored` gemeldet); (3) nach dem Zeilen-Rückbau liest
  die Engine die übrigen Teile frisch: jede Veränderung durch den
  Zeilen-Rückbau ist fremd (`own = false`, Vermerk), der Teil bleibt `left`,
  wenn er nicht der Sicherung gleicht (Prüffälle
  `testTermsAHookChangedAsTheRowWentBackAreLeftNotWrittenOver`,
  `testTheDefaultCategoryTheRowGivesBackLeavesTheTermsOfAPostTheStepEmptied`);
  die Art liest beim Schreiben noch einmal (`restore_row()`,
  `restore_meta()`, `restore_terms()` prüfen den übergebenen Hash gegen den
  Stand im Moment des Schreibens; `restore_terms()` nimmt keinen gelesenen
  Stand an); (4) Schlussprüfung: jeder als zurück gezählte Teil wird frisch
  gegen den Hash der Sicherung gelesen; nur wenn alle gleichen, heisst der
  Schritt des Rückwegs `done`, sonst `unclear` mit Zustand und Grund je Teil
  («reads other than the backup after the parts went back»; Prüffall
  `testAPartAHookChangedAfterItWasPutBackIsUnclearNotRestored`). Ein Beitrag
  ohne Kategorie auf beiden Seiten, dem Staging eine gibt: die Zeile zurück
  gibt keine Vorgabekategorie dazu (der Beitrag hat in dem Moment eine), die
  Terme gehen danach auf «keine» zurück (Prüffall
  `testTheWayBackPutsTheRowBackBeforeTheTermsSoAPostWithoutACategoryStaysWithout`).
  Ein Teil, der auf dem Rückweg `left` heisst, trägt `value_before`
  (Prüffall `testTheWayBackIsByteForByteAndLeavesWhatChangedSince`).
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
- Der Plan vergleicht den frischen Live-Hash jedes zu überschreibenden
  Objekts mit dem Hash, den der Bericht verglichen hat (die Zeilen der
  Live-Bestandsaufnahme des Berichts, `AB_MCP_Sync_Proposal::live()`); weicht
  er ab, lehnt er ab (`ab_mcp_sync_push_changed`, Weg: neuer Bericht).
  Vorher übernahm der Plan den frischen Hash als Erwartung und behielt die
  Einstufung des Berichts (`take_over`): eine Live-Änderung zwischen Bericht
  und Plan wurde ohne `staging_wins` überschrieben (GitHub-Codex 1, P1;
  Entscheide 15 und 16 binden den Token an den Berichtsstand). Prüffall
  `testATargetThatChangedSinceTheReportIsNotPlannedWhateverTheReportSaid`.
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
  geprüften Termnamen, Plan (`verdict`, `order`, `line_of`), die
  Entscheidungsfunktion `decide()` über alle 80 Kombinationen (Tabelle oben),
  der alte Wert (`before_value()`: Zeile, langer Text mit Revision, Meta,
  Terme, synthetische Geheimnisse, kein UTF-8, Kürzung je Wert und je Teil)
  und die Kappung je Antwort (`cap_values()`).
  Integration (`tests/integration/SyncRunTest.php`, achtunddreissig Fälle): Ende zu Ende mit
  Rückweg und `step` auf eine spätere Phase (Merkmal der Sperre beim
  Anlegen), ein Bericht in zwei Läufen geplant, Abweichung in der
  Gesamtvorprüfung, Unterbrechung in eigenem Prozess (die Zeile bleibt mit
  altem Wert, der Lauf schliesst mit Befund), bytegenau (die stehen
  gelassenen Teile mit altem Wert), Sperrmatrix über REST (Request vor der
  Grenze), Sollwert «als Entwurf übernehmen», zeitabhängiger Status,
  Aufbewahrung mit Fristen (auch Zeilen ohne Frist), neue Eltern- und
  Unterseite, Befunde über Aufrufe, Prüfung mit Titel und Cursor, Vorlage
  über den Kernweg, gescheiterter Schritt ohne Rest, Vorgabe-Kategorie eines
  neuen Beitrags ist mit der Zeile eigen, kein Wiederveröffentlichen bei
  Menü-Automatik, Rückweglauf unter der Ablagesperre, Zeile vor den Termen
  (Beitrag ohne Kategorie), Schlussprüfung gegen einen Hook, Ziel seit dem
  Bericht geändert, Schritt höchstens einmal zurück über Rückweg-Läufe, beim
  Zeilen-Rückbau von einem Hook geänderte Terme werden nicht überschrieben
  (N3); seit Entscheid A (07.11.2026), je ohne die Grenze rot: der
  Normalfall (Beitrag mit Kategorie und Schlagwort, Seite mit Vorlage, hin
  und zurück alles `restored`), abweichender Meta-Teil lässt das Objekt ganz
  stehen mit altem Wert (B8), von einem Filter geänderte Zeile mit der
  mitgeschriebenen Vorlage (N2 regulär), abweichende Terme (A2), fremde
  Kategorie mit der Zeile (auch wenn sie dem Soll gleicht; Eigentumsregel),
  Hook ändert die Terme mit der Zeile anders als der Kern (Beitrag ohne
  Kategorie), nie erreichter Teil nach verweigertem Meta-Aufruf, Unterbrechung
  im Meta-Aufruf (die Zeile zurück, die Notiz bleibt mit altem Wert),
  gescheiterter Aufruf, der den Teil veränderte (P1), die Vorgabekategorie
  beim Zeilen-Rückbau ist fremd (F2, Soll gleich Vorgabekategorie) und lässt
  die Terme eines Beitrags, dessen Schritt die Kategorien leerte, stehen,
  fremde Änderung vor einem verweigerten Meta-Aufruf (N1), von einem Hook
  mit der Zeile geänderte Vorlage (N2), abgewichenes Objekt bleibt auch auf
  dem Rückweg des geschlossenen Laufs ganz stehen (Vorschau `will` = `left`),
  verweigerter Zeilenaufruf ohne Schreiben (`failed`, nichts `left`). Unit
  dazu: Schlüssel mit Backslash, Recht zum Entfernen eines nur live
  vorhandenen Schlüssels. Mutationen: `tests/mutation/lists/lauf.php` (aus
  `tests/mutation/gen/lauf.py`), je Bedingung von `decide()` und
  `deviated()`, für die Aufzeichnung von `pre`/`post`/`own`, für den alten
  Wert und seine Kappung.
- Nicht als Mutation geführt: die Wächter gegen eine Datenbank, die ein Lesen
  oder Schreiben verweigert (keine Test-Site verweigert), das Budget der
  Gesamtvorprüfung über Aufrufe mit Tausenden Objekten (die Fortsetzung selbst
  hält ein Test mit gestellter Uhr), die PHP-Probe (ersetzt: im Test besteht
  sie; dass `partial` und `unreachable` Befunde sind, zeigt nur die Live-Probe),
  die Kapazitätsprüfung des Rückwegs im Plan (mit 1996 Objekten je Lauf und
  3996 Schritten je Lauf nicht erreichbar), der Schritt ohne Sicherung in `revert_boundary()` (der Plan
  lässt ihn nicht hinein), die belegte Sperre (`busy`: ein zweiter Request
  derselben Site im selben Augenblick), die Marke `settled` (oben, nur mit
  einer Datenbank erreichbar, die unmittelbar nach dem Schreiben nicht liest),
  der Vermerk `foreign` aus `pre ≠ Sicherung` vor dem eigenen Aufruf (eine
  fremde Änderung zwischen Zeilenaufruf und eigenem Aufruf trifft schon der
  Vermerk aus dem Rücklesen der Zeile; ein Schreiber genau in diesem Fenster
  ist nicht zu stellen), der Zweig `$core` in `write_parts()` (kein eigener
  Meta-Aufruf für die mit der Zeile geschriebene Vorlage: ein zweiter Aufruf
  mit demselben Wert ist am Zustand nicht messbar), `decide()` bei `$now =
  null` (das Objekt verschwindet während des Laufs nicht: die Sperre
  verweigert das Löschen), die Behandlung eines Journals ohne den Schlüssel
  `object.deviated` als abgewichen (kein Lauf dieses Stands schreibt ein
  solches Journal), die frische Lesung je Teil in `put_back()` (`$seen =
  $again`: die Art liest beim Schreiben ohnehin noch einmal, beide allein
  halten N3) und die Texte der Ablehnungen (`AnswerWayTest` hält, dass jede
  einen Weg nennt).
