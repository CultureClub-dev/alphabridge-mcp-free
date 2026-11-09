# Grenzen des Laufs (Z8, Zweig «Lauf»)

Gesammelte Gruppe-C-Grenzen des Übernahmelaufs (`wp_site_push`, `wp_site_revert`;
Entscheide 15 bis 20, 23, 32 und 33) für die Abnahme Z11c: seltene oder
konstruierte Fälle, bekannte Grenzen der Bauweise und solche, die eine spätere
Etappe schliesst. Je Grenze ein Satz mit Beispiel und Grund; wo eine
Ablehnung entsteht, nennt sie den Weg.

## Sperre und Unterbrechung (Entscheid 18)

- **Eine Sperre je verändernden Aufruf** (Thomas 07.11.2026 abends,
  Variante A, nach GitHub-Codex 6): Jeder Aufruf von `wp_site_push` und
  `wp_site_revert`, der einen Lauf verändern kann – `next`, `step`, `abort`,
  `wp_site_revert action=plan` mit Token (Beginn des Rückwegs desselben
  Laufs, Fortsetzung eines unterbrochenen Rückwegs, Rückweglauf eines
  geschlossenen Laufs), `wp_site_revert action=next`, und was dabei
  schliesst (Gesamtvorprüfung mit `closed_aborted`, Prüfung des Laufs,
  Schluss des Rückwegs, mit Freigabe der Reservierung und Bereinigung der
  Ablage) –, läuft unter der Sperre der Schreibgrenze vom ersten Lesen des
  Laufs bis zur Antwort (`AB_MCP_Sync_Run::under_lock()`, Freigabe im
  `finally`; `touch()` wie bisher in den langen Schleifen der Grenze und des
  Rückwegs). Darunter liest er Lauf und Schritte frisch und entscheidet erst
  dann: die Engine an einer Stelle (`AB_MCP_Sync_Engine::fresh()`: der Lauf
  im Zustand, in dem der Aufrufer ihn las, sonst `ab_mcp_sync_run_state` und
  nichts geschrieben; dann `settle()`), der Rückweg in
  `AB_MCP_Sync_Revert::begin_locked()` (gelesen, gesettelt, der Plan neu
  gerechnet und mit dem bestätigten verglichen). Auch «als geprüft
  schliessen» (`close_checked()`, Weg a) läuft so und settelt den Lauf
  selbst. Die Einzelsperren je Übergang entfallen: `run_check()` (Sperre
  verfügbar), `run_write()`, `run_revert()`, `abort()` und
  `AB_MCP_Sync_Revert::begin()` nahmen sie je für sich, und `still()` in
  beiden Grenzen ist in `fresh()` aufgegangen. Was bleibt, ist die
  verschachtelte Bestätigung derselben Sperre: die Werkzeuge
  (`AB_MCP_Sync_Push::call()`, `AB_MCP_Sync_Revert::call()`) nehmen sie vor dem
  ersten Lesen, die Engine (`next()`, `abort()`) nimmt sie für einen direkten
  Aufrufer selbst, im selben Request zählt `AB_MCP_Change_Lock` nur die
  Tiefe (`lifecycle/class-change-lock.php:74`). Grund: GitHub-Codex fand in
  den Runden 3, 4 und 6 je einen Zustandswechsel eines Laufs ausserhalb der
  Sperre (Beginn des Rückwegs, `abort`, `close_revert()` neben dem letzten
  Rückweg-Schritt); statt eines vierten Flickens je Übergang die Behebung an
  einer Stelle. Vorher buchte zudem die Gesamtvorprüfung eines verzögerten
  `next` die Reservierung für einen schon abgebrochenen Lauf und meldete sich
  `done`. Prüffälle
  `testACallThatChangesARunIsRefusedAsBusyWithoutAnyChangeWhileAnotherRequestHoldsTheLock`,
  `testADelayedCallWithAnOldReadOfTheRunWritesNothingAfterAnAbortOrAfterTheWayBackClosed`.
- **Bei fremder Sperre** (ein anderer Request hält sie: ein Schritt eines
  Laufs, eine Aktivierung, ein Deploy oder ein Update durch AlphaBridge)
  lehnt der verändernde Aufruf mit `ab_mcp_sync_run_busy` ab, bevor er etwas
  vom Lauf liest oder schreibt: Lauf (auch Herzschlag und `calls`),
  Schritte, Reservierung und Sicherungen bleiben unverändert; die Ablehnung
  nennt den Weg «erneut aufrufen» und die Zeit, ab der eine liegen
  gebliebene Sperre übernommen wird. Vorher gab ein `next` bei belegter
  Sperre eine Antwort mit `busy: true` statt einer Ablehnung. Das Feld
  `busy` bleibt in der Form der Antwort und ist immer `false`.
- **`settle()` unter der Sperre**: Jeder Schritt wird innerhalb eines
  Aufrufs unter dessen Sperre begonnen und beendet. Unter der Sperre gehört
  ein Schritt `begun`, den nicht dieser Request begann, darum einem Request,
  der vor dem Ende des Schritts endete (Sperre beim Shutdown zurückgegeben
  oder nach `TIMEOUT` übernommen): der Lauf wird `interrupted`. Ein Schritt,
  den dieser Request unter der Sperre seines Aufrufs begann
  (`AB_MCP_Sync_Run::$own`, vermerkt in `update_step()`, vergessen, wenn der
  äusserste Aufruf die Sperre zurückgibt), ist der eigene, laufende Aufruf
  und nie unterbrochen. Lesende Aufrufe (`status`, die Übersicht ohne
  `run_id`, die Vorschau von `plan` beider Werkzeuge) rufen `settle()` ohne
  Sperre: ohne begonnenen Schritt ändert es nichts; bei einem begonnenen
  Schritt und frischer Sperrzeile (`lock_fresh()`), oder wenn ein anderer
  Request die Sperre zuerst nimmt, gilt der Schritt als laufend (`running`,
  nichts geändert); sonst nimmt es die Sperre nur für die Entscheidung,
  liest den Lauf darunter neu und gibt sie sofort zurück. Ein fremder, noch
  laufender Request gilt so nie als unterbrochen, solange er die Sperre
  hält; ohne Sperre entscheidet `settle()` nie. Vorher entschied es neben der
  Sperre nach dem Alter der Sperrzeile (ein Lesen der Schritte vor dem Blick
  auf die Sperre konnte einen Schritt, der dazwischen endete, als
  unterbrochen werten), und unter der eigenen Sperre galt jeder begonnene
  Schritt als laufend, auch der eines beendeten Requests (Prüffall
  `testUnderTheLockOfItsCallAStepTheCallBeganIsItsOwnAndAStepAnEndedRequestLeftIsInterrupted`).
- Grenze C: Ein lesender Aufruf, der den begonnenen Schritt eines beendeten
  Requests findet, hält die Sperre für den Augenblick der Entscheidung; ein
  verändernder Aufruf genau in diesem Augenblick antwortet busy (erneut
  aufrufen). Nur nach einer Unterbrechung, ohne Änderung.
- Grenze C: Die Sperre gilt für den ganzen Aufruf, also auch für die
  Gesamtvorprüfung, die Caches und die Prüfung des Laufs (je höchstens
  `WORK_MAX` und Reserve, rund 60 s). Solange antwortet eine Aktivierung, ein
  Deploy oder ein Update durch AlphaBridge `ab_mcp_change_busy`, und ein
  langer Deploy (ZIP über SFTP, ohne Zeitgrenze) hält umgekehrt jeden
  verändernden Aufruf eines Laufs busy, bis er fertig ist. Grund: dieselbe
  Sperre für Code-Änderungen und Übernahme (Entscheid 18); beides läuft
  ohnehin nicht nebeneinander.
- `wp_site_push action=plan` mit Token legt einen Lauf an und verändert
  keinen: es läuft ohne die Sperre; einen zweiten nicht geschlossenen Lauf
  verhindert der Eindeutigkeitsschlüssel `open_one` (Entscheid 15). `touch()`
  in `status` (Herzschlag, Zählung der Aufrufe) schreibt ohne Sperre und
  ändert keinen Zustand.
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
- Ein lesender Aufruf sieht an `AB_MCP_Sync_Run::lock_fresh()` (Sperrzeile
  `ab_mcp_pro_code_change.lock`, jünger als `TIMEOUT`), ob ein anderer
  Request die Sperre hält: eine frische Zeile heisst «ein Schritt läuft»
  (`running`), auch wenn sie eine Aktivierung oder ein Deploy durch
  AlphaBridge hält und nicht der Lauf; ein verändernder Aufruf entscheidet an
  `take()` selbst. Der Lauf unterscheidet die Halter nicht; `status` nennt
  den Schritt, `next` antwortet busy. Grund:
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
  16 KiB über alle gezeigten Schritte, die neuesten zuerst, danach `value_cut()`
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
  wurde), heisst der Schritt `failed`, das Objekt behält seine Marke (ein
  zuvor eigener Teil geht so auch später nie zurück, Codex-Runde 9), und die
  Vorschau des Rückwegs sagt für ihn `nothing`, solange jeder Teil heute wie
  vor dem Schritt liest; hat eine andere Hand das Objekt seither geändert,
  sagt sie `left` (Codex-Runde 10). Seit Codex-Runde 11 sagt die Vorschau
  für jedes abgewichene Objekt einheitlich `stays` (nichts davon geht von
  selbst zurück) und behauptet nichts über seinen heutigen Stand; der
  Rückweg nennt jeden Teil `unchanged` (wie die Sicherung) oder `left`. Grenze C: Ein Journal aus dem lokalen,
  nie gepushten Stand `61bb244` konnte die Marke eines solchen Objekts
  gelöscht und ein früheres Eigentum behalten haben; solche Journale gibt es
  nur in lokalen Testdatenbanken dieses Zweigs.
- Liest der Schritt einen Teil nach seinem eigenen Aufruf nicht zurück (das
  Lesen scheitert, der Post ist weg), ist das eine Abweichung: kein
  Rücklesen, nie der Hash einer leeren Menge, nie Eigentum; das Objekt bleibt
  ganz stehen (`seen_after()`, `unread`; Codex-Runde 11). Die Abweichung im
  Schritt selbst ist nicht als Mutation geführt (keine Test-Site verweigert
  das Lesen), `seen_after()` schon.
- Ändert ein eigener Aufruf im Schritt einen anderen Teil, ob der Schritt ihn
  schon geschrieben hat oder noch schreiben wird (ein Hook dieser Site, der
  etwa aus Kategorie und Schlagwort oder aus Preis und Menge einen Meta-Wert
  berechnet), ist das eine Abweichung: das Objekt bleibt ganz stehen, auch
  wenn der Teil später wieder dem Soll oder der Sicherung gleicht
  (`write_parts()` vergleicht nach jedem Meta- und Term-Aufruf den ganzen
  gelesenen Stand mit dem Stand unmittelbar vor dem Aufruf, jeden Teil ausser
  dem geschriebenen, auch Schlüssel und Taxonomien, die der Plan nicht nennt,
  etwa ein Schlüssel, den ein Hook anlegt und wieder entfernt; Codex-Runden 13
  bis 15; das Gegenstück zum Rückweg unten).
- Verändert das Zurücksetzen eines Teils auf dem Rückweg irgendetwas anderes
  am Objekt (eine Nebenwirkung des Kerns wie die Vorgabe-Kategorie; ein Hook,
  der beim Zurücksetzen der Kategorie die Schlagwörter setzt oder einen
  Meta-Wert anlegt, auch einen Schlüssel oder eine Taxonomie, die der Plan
  nicht nennt), hält der Rückweg für dieses Objekt an: kein weiterer Teil
  geht von selbst zurück, jeder Teil ungleich Sicherung bleibt `left` mit
  altem Wert, das Objekt gilt nicht als ganz zurück, das Paar wird `unclear`
  (Vergleich des ganzen Stands vor und nach jedem Zurücksetzen; Codex-Runde
  16). Die Notiz eines stehen gelassenen Teils nennt den Halt mit dem Teil,
  dessen Zurücksetzen ihn auslöste, auch wenn `decide()` den Teil schon aus
  eigenem Grund stehen lässt («changed since the step wrote it»). Die Marke
  «fremd» auf dem veränderten Teil (Codex-Runde 12) entschied seit dem Halt
  nichts mehr, was der Halt nicht entscheidet, und ist entfernt (die CI zu
  GitHub-Codex 2 liess ihre Mutation überleben; Prüffälle
  `testTagsAHookSetsAsTheCategoryGoesBackAreLeftNotTakenBack`,
  `testAKeyAHookAddsAsACategoryGoesBackStopsTheWayBackOfTheObject`).
- Rechte auf dem Rückweg (Entscheid 28; GitHub-Codex 2, P2): Unmittelbar vor
  dem Zurücksetzen eines Teils fragt `put_back()` dieselben Rechte wie die
  Vorwärtsgrenze, am Objekt (`AB_MCP_Sync_Post_Writer::restore_right()`):
  für jeden Teil zuerst `edit_post` am Live-Post, auch für Meta und Terme
  (`assign_term` und die Meta-Rechte fragen nichts am Post selbst; ohne
  `edit_post` blieb der Titel stehen und die Kategorie ging trotzdem zurück,
  Codex-Runde 18); für die Zeile dazu `publish_posts`, wenn der gesicherte
  Status `publish`, `future` oder `private` ist und die Zeile heute einen
  anderen hält, und `edit_others_posts`, wenn der gesicherte Autor ein anderes
  Konto ist; je Meta-Schlüssel `edit_post_meta` (Zeilen beidseits),
  `add_post_meta` (die Sicherung hat mehr Zeilen) oder `delete_post_meta`
  (weniger) über `map_meta_cap`, `_wp_page_template` nur mit `edit_post`; je
  Taxonomie die Rechte wie vorwärts (`term_right()` über die gesicherten
  Schlüssel). Fehlt eines,
  bleibt der Teil `left` mit Grund und altem Wert, fail closed, der Lauf
  läuft weiter, der Schritt wird `unclear`, das Paar `unclear`; der Weg: den
  Teil in wp-admin mit einem Konto setzen, das darf (der Schritt geht nur
  einmal zurück). Vorher prüfte der Rückweg nur das Eigentum am Lauf;
  `wp_update_post()`, das rohe SQL von `restore_meta()` und
  `wp_set_object_terms()` fragen kein Recht (Prüffälle
  `testTheWayBackAsksTheRightsAtTheObjectAndLeavesAPartTheAccountMayNotWrite`,
  `testWithoutEditPostNothingOfThePostGoesBackNotEvenItsTerms`). Die
  Rechteprüfung ruft die Hooks der Rechte auf (`map_meta_cap`,
  `user_has_cap`), zwischen der Lesung, auf der `decide()` entscheidet, und
  dem Schreiben der Art; ändert ein solcher Hook den Teil, fängt das die
  zweite Lesung der Art beim Schreiben (`restore_row()`, `restore_meta()`
  gegen das, was der Schritt zurücklas): der Teil bleibt `left`, der Wert des
  Hooks steht (Codex-Runde 18; Prüffall
  `testAHookOfTheRightsCheckThatChangesAPartAsItGoesBackIsCaughtByTheSecondReadOfTheKind`).
- Jeder alte Wert trägt die Marke der Regeln, nach denen dieser Build ihn
  zusammensetzt (`value_rules` = `AB_MCP_Sync_Post_Writer::VALUE_RULES`).
  Beim Ausgeben (`AB_MCP_Sync_Run::cap_values()`) zeigt die Antwort einen
  Wert ohne diese Marke nie (Journal eines Zwischenstands, nach anderen
  Regeln zusammengesetzt), sondern verweist auf die Sicherung; jeder Wert
  mit Marke geht noch einmal durch die Geheimnisprüfung und das Netz
  (`checked_before()`, Codex-Runde 9). Grund: Das Netz erkennt ein
  Zugangsdatum im rohen Wert, im schon zusammengesetzten Text in
  Anführungszeichen aber nicht in jedem Fall. Grenze C (fail closed): Ein
  harmloser Wert, dessen zusammengesetzte Darstellung das Netz als nicht zu
  Ende lesbar wertet (etwa ein serialisiertes Array mit `}` in einem
  Eintrag), erscheint als `(holds a credential, not shown)`; die Sicherung
  des Schritts hält ihn (Codex-Runde 10).
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
  nimmt das in Kauf. Seit Zweig 11 decken Schritte Kern-Optionen und die
  geteilten Zeilen der Theme-Mods und Widgets; ein Wert, den ein anderer
  Schreiber dort still hält, liest der Schritt nicht als Ziel zurück und
  weicht ab (`GRENZEN-STRUKTUR.md`).

## Vorprüfung der Mittel (Konzept 15.11)

- Die Tempo-Messung schreibt und liest 1 MiB im Ordner der Ablage und liest
  eine Seite Post-IDs; die Hochrechnung auf die Sicherungen des Laufs ist
  grob und heisst darum nur «knapp» (über 30 Minuten), nie «reicht nicht».
  Die 5 MB und 1'000 Zeilen des Konzepts gehören zur Abbild-Engine (Z9a).
- Platz in der Datenbank und Datenbankrechte prüft der Lauf nicht: er legt
  keine Tabelle an und schreibt nur Zeilen, die WordPress selbst schreibt.
- Die Reservierung (GitHub-Codex 6, P2): Der Plan rechnet je Objekt die
  kanonische Grösse der Staging-Seite doppelt plus `JOURNAL_BYTES` und dazu
  die Sicherung des Live-Objekts, wie `AB_MCP_Sync_Backup::put()` sie
  schreibt (`size_of()`: die Zeilen der Live-Site in der Kanon-Schreibweise,
  unkomprimiert, als obere Schranke; die Ablage komprimiert beim Schreiben,
  zählt aber die unkomprimierten Bytes als `used`, so dass Reservierung und
  Verbrauch dieselbe Einheit haben; ein neues Objekt die kleine Marke
  «absent»). Vorher zählte der Plan nur die Staging-Grösse: grosse
  Live-Objekte mit kleinen Ersetzungen bestanden die Vorprüfung und konnten
  die 32 MiB Reserve der Ablage aufbrauchen. Dazu prüft die Grenze vor jeder
  Sicherung, dass sie in die Buchung des Laufs passt, neben dem, was seine
  Sicherungen schon halten (`fits()`); sonst scheitert der Schritt vor der
  Mutation mit dem Weg (neu planen, weniger Objekte). Grenze: der Plan liest
  dafür die Zeilen jedes ausgewählten Live-Objekts (höchstens 1996), wie er
  schon ihre Hashes liest (Prüffall
  `testTheReservationCountsTheBackupsOfTheObjectsOfThisSiteAndTheWriterHoldsTheRunToIt`).
- Der Weg 2 der Rückfrage («direkt auf der Live-Site arbeiten») wird in
  Zweig 8 nur genannt (Einstellung «Staging zuerst» aus, oder
  `staging_acknowledged`); die Wahl mit dem Grund «Voraussetzungen fehlen»
  und die Anzeige in der Karte sind Z9a und Zweig 12.
- Die Zeitgrenze (GitHub-Codex 3, P2): Jeder ausführende Aufruf (`next`,
  `step` von `wp_site_push` und `wp_site_revert`) versucht zuerst
  `set_time_limit( WANTED_TIME = 120 )`, wo `max_execution_time` darunter
  liegt, und liest die Grenze danach (`AB_MCP_Sync_Resources::time_for_call()`,
  `raise()`); `measure()` misst dieselbe angehobene Grenze, so dass die
  Vorprüfung mit dem rechnet, was die Aufrufe haben werden. Die Arbeit eines
  Aufrufs (`budget()`): ohne Grenze (0) `WORK_MAX` = 50 s, sonst die Hälfte
  der Grenze, nie über 50 s, mindestens 1 s; die Gesamtvorprüfung und die
  Prüfung des Laufs halten danach an, sichern den Cursor und setzen im
  nächsten Aufruf fort (je Aufruf mindestens eine Einheit). Warum die
  Hälfte: `max_execution_time` zählt auf Linux die Rechenzeit des Skripts;
  die Schleifen prüfen in PHP (Hashes), die andere Hälfte bleibt für den
  Schritt, den der Aufruf nach der Arbeit schreibt, für die Journale und die
  Antwort; 50 s, weil die anderen langen Werkzeuge 60 s mit 10 s Reserve
  nehmen (`AB_MCP_Sync_Engine::BUDGET`, `RESERVE`, aus denselben Zahlen).
  Warum 120 s gewünscht: das Doppelte von Arbeit und Reserve, damit ein
  Host, dessen Grenze greift, Luft lässt. Eine Grenze unter `MIN_TIME` =
  30 s trägt keinen Schreibschritt (`carries_write()`): ein Schreibschritt
  und ein Rückweg-Schritt werden darunter nicht ausgeführt
  (`ab_mcp_sync_run_time`, der Schritt bleibt `planned`, nichts geschrieben,
  der Weg: `max_execution_time` beim Hoster auf 30 s, oder `set_time_limit()`
  zulassen); Lesen und Prüfen laufen mit der kleineren Arbeit weiter. Warum
  30 s: eine Grenze mit Sicherung, Journal, Kern-Aufrufen und Rücklesen je
  Teil braucht in der Praxis Sekunden Rechenzeit, nicht Dutzende; 30 s ist
  die untere Grenze der verbreiteten Host-Vorgaben und schon der Bedarf
  eines Schritts in der Vorprüfung (`MIN_TIME`). Vorher rief `measure()`
  `set_time_limit()` nur mit der bestehenden Grenze auf, kein Aufruf hob sie
  an, und die Schleifen arbeiteten 50 s, bevor sie den Cursor sicherten: auf
  einem Host mit 30 s starb der Aufruf vor dem Cursor, ein Schreibschritt
  konnte mitten in der Mutation enden. Vorgeschaltete Grenzen (FPM, Proxy)
  misst Z8 nicht, weil ein Messrequest an die eigene Adresse bei Hosts ohne
  Loopback nichts beweist (Prüffälle
  `testTheTimeOfACallIsRaisedWhereItTakesAndTheWorkIsDerivedFromTheLimitItHas`,
  `testAWriteStepIsNotRunUnderATimeLimitThatDoesNotCarryItWhileTheCheckGoesOnWithASmallerWork`).
  Nicht als Mutation geführt: der Aufruf von `raise()` in `measure()` (die
  Unit-Tests injizieren die Messung; der Aufruf je Schritt ist geführt).
- Grenze C (neu durch die Zeitregel; Codex-Runde 20): Ein Lauf mit
  geschriebenen Schritten, dessen Umgebung später auf eine nicht anhebbare
  Zeitgrenze unter `MIN_TIME` (30 s) wechselt (etwa von der CLI mit 0 auf das
  Web mit 20 s), kann nach `reverting` wechseln (`wp_site_revert` `plan`
  schreibt nichts) und steht dann still: `next` verweigert jeden
  Rückweg-Schritt (`ab_mcp_sync_run_time`), erneutes Planen (der Lauf ist
  `reverting`) und `abort` (nur für `open`) gehen nicht. Weg: die Zeitgrenze
  beim Hoster auf mindestens 30 s oder `set_time_limit()` zulassen, oder den
  Rückweg über die CLI fortsetzen (dort 0); geschrieben wird in diesem
  Zustand nichts. Bei unverändert 30 s oder 0 entsteht der Fall nicht (die
  Vorprüfung lehnt unter 30 s vor dem ersten Schreiben ab); ein eigener
  Rückweglauf eines geschlossenen Laufs bleibt `open` und lässt sich mit
  `wp_site_push abort` abbrechen. Keine Sperre davor, weil der Wechsel der
  Umgebung mitten im Lauf sehr selten ist, ohne ausreichende Zeit nichts
  geschrieben wird und der Weg heraus besteht.

## Post-Art in Zweig 8 (Zweig 9 bringt den Rest)

- Stand Zweig 9: Die Feldregel (Entscheid 28), der Slug, die weiteren
  Sanitizer, das Beitragsbild und die Term-Art stehen in
  `GRENZEN-INHALTE.md`; was dieser Abschnitt über `post:meta_rule`,
  `post:create_with_object_meta`, `post:slug_collision` und die einfachen
  Schlüssel sagt, gilt in der Fassung dort. Die Sperren mit Stufe Z9 sind
  aufgelöst (Z8 oder Z8d); `live_meta_rule()` heisst jetzt
  `AB_MCP_Sync_Post_Writer::live_rule()` und prüft die Taxonomien und die
  Sperren der kanonischen Form des Live-Posts.
- Geschrieben werden nur `post` und `page` mit Zeile, Meta von
  `_wp_page_template` und auf vorhandenen Posts einfachen Schlüsseln (kein
  Unterstrich, nicht geschützt, keine Zugangsangabe, kein Builder-Markup, die
  das Konto bearbeiten darf) und Terme von `category`, `post_tag` und
  `post_format`. Alles andere der Post-Art ist fail closed gesperrt mit Grund
  und Etappe (`post:engine_type`: in Zweig 9 nur die fünf Kern-Typen von
  Zweig 9b, Etappe Z8, die übrigen Lagen mit eigenen Schlüsseln; seit
  Zweig 9b aufgehoben, die fünf Typen geschrieben, GRENZEN-INHALTE.md;
  `post:meta_rule`, `post:taxonomy_rule`,
  `post:slug_collision`, `post:sanitizer`: Z9; `post:create_with_object_meta`:
  Z8d), auch ein vorhandener Live-Post mit einem Meta-Schlüssel ausserhalb der
  Regel (er stünde sonst nach dem Schreiben nicht auf dem Sollwert).
- Ein Beitragsbild (`_thumbnail_id`) ist ein Verweis auf ein Attachment; der
  Bericht liess den Post in Zweig 8 und 9 darauf warten. Seit Zweig 10 ist
  das Attachment eine Art des Laufs: ein Beitragsbild mit Paar geht, eines,
  das derselbe Lauf anlegt, kommt vor dem Post (`GRENZEN-MEDIEN.md`).
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
- `term_order` ungleich 0 (ein Plugin, das Terme sortiert) stellt
  `wp_set_object_terms()` nicht her: es legt jede Zuordnung neu mit Reihenfolge
  0 an, vorwärts wie auf dem Rückweg; eine Zuordnung, die beidseits bleibt,
  behält ihre Reihenfolge, eine entfernte und zurückgegebene verliert sie.
  Grenze C (GitHub-Codex 5, P2): Ein Live-Post mit einer Zuordnung mit
  `term_order` ungleich 0 in einer Taxonomie, die der Schritt berührt (alle
  Taxonomien des Sollwerts und des Live-Posts, denn der Schritt schreibt oder
  leert jede), ist vor jeder Mutation gesperrt (`post:term_order`, Z9, Grund
  mit Term und Reihenfolge; Prüffall
  `testAPostWhoseTermHasAnOrderIsLockedBeforeTheFirstWriteAndOneWithoutGoesOverAndBack`).
  Ebenso ein Staging-Post, dessen Sollwert eine Zuordnung mit `term_order`
  ungleich 0 trägt (GitHub-Codex 7, P2): `target_of()` sperrt ihn vor jeder
  Mutation mit derselben Sperre, der Grund nennt die Seite («on the post of
  the staging site» bzw. «of this site»), den Term und die Reihenfolge.
  Vorher warf `target_of()` die Reihenfolge weg, `precheck()` prüfte nur die
  Live-Zuordnungen, der Schritt schrieb, und das Objekt wich danach über den
  Soll-Hash ab (unklar, ohne Rückweg; Prüffall
  `testAStagingPostWhoseTermHasAnOrderIsLockedBeforeTheFirstWriteAndOneWithoutGoesOverAndBack`:
  Staging 7, live 0 → `closed_aborted`, nichts geschrieben; mit 0 hin und
  zurück wie bisher). Jede Staging-Zuordnung liegt in einer Taxonomie, die
  der Schritt berührt: eine andere Taxonomie sperrt schon
  `post:taxonomy_rule`.
  Mit Reihenfolge 0, wie der Kern sie schreibt, ändert sich nichts: der
  Vorwärtsschritt legt neue Zuordnungen mit 0 an und berührt bestehende
  nicht (`wp_set_object_terms()` löscht nur entfernte und fügt nur neue ein;
  die Nachsortierung des Kerns gilt nur Taxonomien mit `sort`, keine
  Kern-Taxonomie). Dazu trägt der Hash der Terme die Reihenfolge, wo sie
  ungleich 0 ist (`term_entries()`: `3#2`; bei 0 der Schlüssel allein, so
  dass Journale von vorher gleich lesen): die Prüfung des Rückwegs
  (`restore_terms()`) und die Schlussprüfung gegen die Sicherung
  (`put_back()`) sehen einen Unterschied der Reihenfolge, ein solcher Teil
  heisst nie `restored`, sondern `unclear` mit dem Grund (Prüffall Unit
  `testARelationshipWithATermOrderLocksThePostAndIsPartOfTheHashOfItsTerms`).
  Vorher verglich der Rückweg nur Schlüssel und meldete `restored`, obwohl
  die kanonische Form die Reihenfolge enthält.
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
  Aufruf hat nichts verändert, `own` bleibt, wie es war. Ein Aufruf, den
  der Helfer des Schreibers nicht macht (`AB_MCP_Sync_Write::refused()`:
  das Objekt hat sich nach der Rechtefrage unmittelbar davor geändert,
  `ab_mcp_sync_changed_before_write`, Zweig 11, Codex-Runde 3, A6; ein
  Recht fehlt oder die letzte Lesung scheitert, Codex-Runde 4, Punkt 3),
  macht nichts eigen, auch wo `post ≠ pre`, etwa wo ein Rechtehook das
  Objekt ändert und dann das Recht verweigert: jeder veränderte Teil ist
  fremd, der Schritt weicht ab und nennt den ersten davon; ist bei einem
  geänderten Stand kein Teil des Schritts anders als die Sicherung, ist er
  `failed` und sagt, dass die fremde Änderung bleibt. Ein Fehler, den der
  Kernaufruf selbst gibt, ist keine solche Verweigerung: dort gilt die
  Regel oben. Für die Teile, die
  der Zeilenaufruf laut `AB_MCP_Sync_Post_Writer::with_row()` mitschreibt
  (`_wp_page_template` über `page_template`; die Vorgabe-Kategorie eines
  Beitrags ohne Kategorie), ist `pre` der Stand vor dem Zeilenaufruf, und
  eigen wird nur, was genau so zurückliest, wie `with_row()` es nennt; jede
  andere Veränderung mit der Zeile ist fremd (ein Hook dieser Site, der auf
  die Zeile reagiert): Vermerk `foreign`, nie Eigentum. Das gilt für jeden
  Teil, den das Lesen vor und nach dem Zeilenaufruf kennt, auch einen, der
  nicht im Journal steht (ein beidseits gleicher Meta-Schlüssel, Entscheid
  28): ändert der Zeilenaufruf ihn, weicht das Objekt ab, bevor das Journal
  wieder gespeichert wird; ein Request, der danach endet, hinterlässt die
  Zeile nie als eigen (Zweig 9, Codex-Runde 1, A1; Prüffall
  `testAHookThatChangesAKeyEqualOnBothSidesWithTheRowDeviatesTheObjectAndTheWayBackLeavesIt`).
  Weicht `pre` von der
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
- Jeder Speicherpunkt des Journals wird geprüft (GitHub-Codex 9, P2): das
  Journal vor dem ersten Teil, der Stand vor jedem Meta- und Term-Aufruf,
  der Stand nach jedem zurückgelesenen Teil, und das Ende jedes Schritts,
  vorwärts wie auf dem Rückweg. Scheitert ein Speichern (Fehler der
  Datenbank, Journal über `MAX_PARTS`), hält die Grenze vor jeder weiteren
  Mutation an: ohne Journal vor dem ersten Teil wird nichts geschrieben,
  der Schritt ist `failed` mit Grund; danach (die Zeile ist immer der erste
  Aufruf) weicht das Objekt ab (`unsaved()` über `deviated()`, Entscheid A):
  was der Schritt schon schrieb, bleibt `left` mit altem Wert, der Schritt
  ist `unclear`, das Paar `unclear`, der nicht gemachte Aufruf steht in der
  Notiz des Teils («so the call was not made»); `failed`, wo nichts von der
  Sicherung abweicht. Lässt sich das Ende eines Schritts nicht speichern
  (`end_step()`), sagt der Aufruf es (`ab_mcp_sync_run_journal`) statt ein
  Ende zu melden, das nicht gespeichert ist; der Schritt bleibt `begun`, der
  nächste Aufruf findet den Lauf `interrupted` mit seinen zwei Wegen, und
  der Rückweg nimmt nur, was das zuletzt gespeicherte Journal als eigen
  ausweist (ein Rückweg-Schritt bleibt beim Fortsetzen `unclear`). Vorher
  verwarf `write_parts()` das Ergebnis jedes Speicherns und schrieb weiter;
  ein Request, der im nächsten Aufruf endete, liess ein veraltetes Journal
  für den Rückweg zurück. Nicht geprüft und darum ohne Halt: die Cursor der
  Gesamtvorprüfung, der Caches und der Prüfung des Laufs (ein Speichern, das
  scheitert, lässt den nächsten Aufruf beim alten Cursor beginnen; Lesen und
  Cache-Leeren zweimal ändern nichts) und das Überspringen in `pick()`.
  Dazu wertet `update_step()` eine Änderung, die keine Zeile berührt,
  während der Schritt im gelesenen Zustand steht, als gespeichert: die
  Datenbank zählt eine Zeile mit gleichen Werten als nicht berührt (derselbe
  Speicherpunkt zweimal in einer Sekunde, etwa nach der mit der Zeile
  geschriebenen Vorlage), das ist keine Bewegung durch einen anderen Aufruf;
  vorher blieb das unbemerkt, weil das Ergebnis verworfen wurde.
  Prüffälle `testAJournalThatCannotBeSavedStopsTheStepBeforeAnyFurtherMutation`
  (ein Trigger der Datenbank verweigert je einen Speicherpunkt: vor dem
  ersten Teil, nach der Zeile, vor dem Meta-, vor dem Term-Aufruf),
  `testAStepWhoseEndCannotBeRecordedSaysSoAndLeavesTheRunInterrupted`.
- Einen einfachen Schlüssel, den nur die Live-Seite trägt, entfernt der
  Schritt (`write_meta()` ohne Wert); die Metadaten-API fragt selbst kein
  Recht. Darum prüfen Gesamtvorprüfung und Grenze je solchem Schlüssel
  `delete_post_meta` über `map_meta_cap` (Entscheid 28; GitHub-Codex 1, P2),
  wie `edit_post_meta` für die Schlüssel des Sollwerts (seit Zweig 9,
  Codex-Runde 1, A2: genau die Operationen, die `write_meta()` ausführt,
  `AB_MCP_Sync_Post_Writer::meta_ops()`; GRENZEN-INHALTE.md); verweigert, sperrt
  `ab_mcp_sync_rights` mit dem Schlüssel. `_wp_page_template` geht mit
  `edit_post` (eigene API, Entscheid 28).
- Rechte je Taxonomie (Entscheid 28; GitHub-Codex 2, P2): Gesamtvorprüfung
  und Grenze fragen für jede Taxonomie, die der Schritt berührt, die des
  Sollwerts und die, die nur der Live-Post trägt und die der Schritt leert
  (`write_terms()` ohne Term), das Recht der Taxonomie, Terme zuzuordnen (ihr
  `cap->assign_terms`, auch für das Leeren), je Term `assign_term` über
  `map_meta_cap` nach ID, bei einem Format nach der ID des Terms, den der
  Slug auf der Live-Site nennt, und für einen Slug, zu dem die Live-Site noch
  keinen Term hat, das Recht der Taxonomie, einen anzulegen (ihr
  `cap->edit_terms`; `wp_set_object_terms()` legt ihn an, B6)
  (`AB_MCP_Sync_Post_Writer::term_right()`). Fehlt eines, sperrt
  `ab_mcp_sync_rights` vor dem ersten Schreiben. Vorher wurde `assign_term`
  nur für Terme mit Ganzzahl-ID gefragt; Formate (Slugs) und nur live
  vorhandene Taxonomien blieben ungefragt (Prüffälle
  `testTheRightToSetTheTermsIsAskedForEveryTaxonomyTheStepTouchesByIdBySlugAndForOneOnlyThisSiteHas`,
  `testAFormatNamedByItsSlugAndATaxonomyOnlyThisSiteHasAskTheRightToAssignBeforeTheFirstWrite`).
  Eine nur live vorhandene Taxonomie, die auf der Live-Site nicht registriert
  ist, sperrt `post:taxonomy_rule` (vorher scheiterte erst der Term-Aufruf
  nach dem Zeilenaufruf); `live_meta_rule()` sperrt sie ohnehin, wenn sie
  nicht `category`, `post_tag` oder `post_format` ist.
- Zwei Meta-Schlüssel, die die Datenbank gleich vergleicht (`Foo` und `foo`
  unter der Kollation von `postmeta`, etwa `utf8mb4_unicode_ci`): Grenze C
  (GitHub-Codex 6, P2). `update_metadata()` und `delete_metadata()`
  schreiben mit `WHERE meta_key = …` und träfen beide Zeilen, auch
  `_wp_page_template` über `update_post_meta()` mit der Zeile; das Journal
  unterscheidet Schlüssel byteweise und könnte die zweite Zeile nicht
  zurechnen (der Vergleich der anderen Teile fände es erst nach der
  Mutation, Objekt abgewichen). Darum sperrt die Vorprüfung einen Live-Post,
  bei dem für einen Schlüssel, den der Schritt schreibt oder entfernt, die
  Datenbank Zeilen unter einer anderen Schreibweise hält (`post:meta_spelling`,
  Z9, Grund mit beiden Schreibweisen), vor jeder Mutation; die Zeilen je
  Schreibweise liest `AB_MCP_Undo_Meta::rows()`, dieselbe Maschine, mit der
  das Undo die anderen Meta-Schreiber fragt (`spelling_refusal()` lehnt dort
  nach den Rechten am anderen Schlüssel ab; hier sperrt jede andere
  Schreibweise, weil es um das Journal geht, nicht um Rechte). Eine
  Tabelle, die sich nicht lesen lässt, sperrt (fail closed). Der Rückweg
  schreibt Meta roh je `meta_id` und ist nicht betroffen (Prüffälle
  `testTwoMetaKeysTheDatabaseComparesAlikeLockThePageBeforeTheFirstWriteAndAnOrdinaryPageRuns`,
  Unit `testTwoMetaKeysTheDatabaseComparesAlikeLockThePostBeforeTheFirstWrite`).
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

- Neu Angelegtes bleibt (Posts, Terme, Attachments, Menüs mit ihren
  Einträgen, Widget-Instanzen mit ihrem Bereich), auch veröffentlicht;
  das Paar wird `residual`, die Antwort
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
- Der Rückweg beginnt unter der Sperre der Schreibgrenze, die der Aufruf
  `wp_site_revert action=plan` mit Token vom ersten Lesen an hält (GitHub-Codex
  3, P1; seit Variante A die Sperre des ganzen Aufrufs, oben): hält sie ein
  anderer Request, wird abgelehnt (`ab_mcp_sync_run_busy`, Weg: erneut
  aufrufen), der Lauf bleibt, wie er war. Unter der Sperre liest
  `begin_locked()` den Lauf neu und settelt ihn: ein Schritt `begun` ist dort
  einer, dessen Request endete, der Lauf wird `interrupted`, und der für den
  offenen Lauf bestätigte Plan wird abgelehnt (`ab_mcp_sync_revert_changed`,
  «was open … and is interrupted now»; neu geplant, beginnt der Rückweg des
  unterbrochenen Laufs mit dem Schritt `unclear`). Vorher lehnte `begin()`
  den Schritt als `ab_mcp_sync_run_begun` ab und überliess das Setteln einem
  `status`. Dann der Plan neu gerechnet (`plan()` mit dem gesettelten Lauf)
  und sein Zustand mit dem bestätigten verglichen: weicht er ab (ein Schritt
  hat dazwischen geschrieben, ein Objekt sich geändert, der Lauf den Zustand
  gewechselt), Ablehnung `ab_mcp_sync_revert_changed`, Weg: neu planen, der
  neue Plan nimmt auch diesen Schritt zurück. Erst dann die Transaktion bzw.
  das Anlegen des Rückweglaufs eines geschlossenen Laufs (auch dieses unter
  der Sperre des Aufrufs und darin unter der Sperre der Ablage). Vorher
  konnte ein gleichzeitiges `next` zwischen `plan()` und der Transaktion
  einen Schreibschritt beginnen: der Lauf blieb `open`, der Wechsel gelang,
  der Schritt wurde `unclear` markiert, obwohl der Plan ihn nicht zurücknahm,
  seine Journal-Updates (`WHERE state = 'begun'`) scheiterten, und der
  Rückweg schloss ohne diese Änderung (Prüffall
  `testTheWayBackOfARunDoesNotBeginBesideAStepBeingWrittenNorOnAPlanTheRunHasLeftBehind`,
  der `begin()` direkt nach dem Plan aufruft, mit einem Schritt `begun` unter
  fremder Sperre, ohne Sperre, und nach einem dazwischen geschriebenen
  Schritt).
- Der Rückweg beginnt mit allen seinen Schritten oder gar nicht (GitHub-Codex
  2, P1): `AB_MCP_Sync_Revert::begin()` führt den Lauf nach `reverting`, setzt
  die Vorwärtsschritte auf `unclear`/`skipped` und legt alle Rückweg-Schritte
  in einer Transaktion der Datenbank an (`AB_MCP_Sync_Run::transaction()`,
  nach dem Muster von `AB_MCP_Sync_Pairs::transaction()`); scheitert etwas,
  rollt alles zurück, der Lauf bleibt `open` oder `interrupted`, kein Schritt
  des Rückwegs bleibt stehen, und die Ablehnung nennt den Zustand und den Weg
  (erneut planen). Dasselbe für `create()`: ein Lauf entsteht mit allen seinen
  Schritten oder gar nicht, auch der Rückweglauf eines geschlossenen Laufs;
  die frühere Notlösung «`closed_aborted` nach gescheitertem Insert» entfällt.
  Vorher stand der Lauf nach einem gescheiterten Insert oder einem Request,
  der zwischen zwei Inserts endete, `reverting` mit einem Teil seines Plans,
  und `close_revert()` meldete `closed_reverted`. Voraussetzung: die Tabellen
  `runs` und `steps` sind InnoDB (dbDelta legt sie mit dem Vorgabe-Engine der
  Datenbank an, auf MariaDB und MySQL ab 5.5 InnoDB); ein anderer Engine
  lehnt vor jedem Schreiben ab (`ab_mcp_sync_run_engine`) und nennt
  `ALTER TABLE … ENGINE=InnoDB` als Weg. Grenze C (Codex-Runde 18): Ein Lauf,
  der schon `interrupted` steht, kommt auf Tabellen ohne InnoDB weder mit
  `next` (ein unterbrochener Lauf hat kein `next`) noch mit `wp_site_revert`
  weiter, bis die Tabellen umgestellt sind; die Ablehnung nennt den Weg, und
  «als geprüft schliessen» (Weg a) bleibt. Möglich nur bei Tabellen, die nach
  dem Anlegen umgestellt wurden, oder bei Journalen früherer Stände; kein
  Code. Grenze: der Request, der zwischen
  zwei Inserts endet, ist nicht als Mutation geführt (die Transaktion deckt ihn
  mit demselben Rollback wie den gescheiterten Insert, den ein Trigger der
  Datenbank im Prüffall stellt; Prüffälle
  `testTheWayBackOfARunThatIsNotClosedBeginsWithAllOfItsStepsOrNotAtAll`,
  `testAWriteOfTheRunAndItsStepsIsOneTransactionAndRefusedWithTheWayWithoutInnoDb`).
- Die Sicherung liest zurück, was sie hielt, auch Bytes, die kein UTF-8 sind
  (GitHub-Codex 2, P2): `AB_MCP_Sync_Canon::encode()` schreibt sie als Hülle
  `{"~b": "<base64>"}`; `AB_MCP_Sync_Backup::get()` las mit `json_decode()`
  allein, der Wert kam als Array, `meta_hash()` hashte ihn als «Array», und
  `restore_meta()` hätte die Hülle statt der Bytes geschrieben. Neu liest
  `get()` über `AB_MCP_Sync_Canon::decode()`, die Umkehr von `encode()` für
  Formen aus Text, Bytes, Zahlen, Wahrheitswerten und Textschlüsseln (die
  Sicherungen): die Hülle wird wieder zu den Bytes; eine Hülle, die nicht
  zurückliest (kein Base64 ihrer Bytes, UTF-8, eine Float-, Set- oder
  Schlüsselmarke, die keine Sicherung trägt), macht die Sicherung ungültig
  (`ab_mcp_sync_backup_shape`, fail closed). Für die kanonische Form eines
  Objekts (Marken `~set`, `~php`, `~s`, `~ref`; gespeicherte Schlüssel mit
  `~`) gilt `decode()` nicht; sie wird über ihren Hash verglichen, nie
  zurückgelesen. Grenze: Auf MariaDB mit striktem SQL-Modus nimmt eine
  `utf8mb4`-Spalte solche Bytes nicht an; der Fall entsteht auf Spalten oder
  Verbindungen, die UTF-8 nicht prüfen (`binary`, ältere Server). Die
  Prüffälle stellen ihn an der Sicherung selbst (Rundreise über die echte
  Ablage) und an `decode()`
  (`testABackupGivesBytesThatAreNoUtf8BackAsBytesNotAsTheMarkerOfTheCanon`,
  `testABackupReadsBackBytesThatAreNoUtf8AsBytesNotAsTheMarker`).
- Ebenso die Datensätze des Laufs: Plan und Reservierung eines Laufs, je
  Schritt `depends`, Journal und Ergebnis liest `AB_MCP_Sync_Run::records()`
  über `AB_MCP_Sync_Canon::decode()`, die Umkehr von `encode()`, mit dem
  `encode_json()` sie schreibt (GitHub-Codex 8, P2). Ein erlaubter einfacher
  Meta-Schlüssel ausserhalb von UTF-8 (`note\xE9`, nur auf Spalten, die solche
  Schlüssel zulassen) steht im Journal als Teil `meta:note\xE9` mit der Hülle
  `{"~b": …}`; `json_decode()` allein liess die Hülle als Array stehen,
  `put_back()` liess den Teil aus, `revert_boundary()` endete an einem
  `TypeError`, und der Rückweg blieb unterbrochen. Jetzt kommen Namen und
  Werte als die ursprünglichen Bytes zurück, und der Teil geht zurück wie
  jeder andere. Gewählt ist das Entpacken statt einer Sperre solcher
  Schlüssel: `decode()` ist die genaue Umkehr für Formen aus Text, Bytes,
  Zahlen, Wahrheitswerten und Textschlüsseln, wie Datensätze des Laufs sie
  tragen, und gilt für die Sicherungen schon. Andere Leser bleiben, weil
  keine Hülle in ihnen stehen kann: die Schnappschüsse der Paare schreibt
  `json_encode()` nur mit gültigem UTF-8, und `backup_rows()` liest aus dem
  Plan nur die Zahlen in `reverts`. Dazu gibt `put_back()` jedem Teil ein
  Ergebnis (ein Teil unbekannten Namens: `left`), so dass der Rückweg eines
  Schritts nie an einem fehlenden Ergebnis scheitert. Grenze C: Ein
  Datensatz, dessen Hülle nicht zurückliest (kein Schreiber dieses Stands
  erzeugt einen: kein Datensatz eines Laufs trägt einen Float, eine Menge
  oder einen Schlüssel ausserhalb von UTF-8; nur ein von aussen veränderter),
  gilt als nicht vorhanden und steht in `unread` (auch in `status`): kein
  verändernder Aufruf entscheidet auf einem Lauf mit nicht lesbarem Plan oder
  nicht lesbarer Reservierung (`ab_mcp_sync_run_unread`, nichts geändert),
  und der Plan des Rückwegs lehnt einen Schritt mit nicht lesbarem Journal ab
  (`ab_mcp_sync_revert_unread`, Weg: `steps` ohne ihn, das Objekt von Hand
  prüfen; die Sicherung hält den Stand davor). Eine Antwort zeigt einen
  Teilnamen ausserhalb von UTF-8 so, wie `wp_json_encode()` ihn für JSON
  umwandelt; das Journal hält die Bytes. Prüffälle
  `testAJournalWithAMetaKeyThatIsNoUtf8ReadsBackAsItsBytesAndItsPartGoesBack`,
  `testARecordOfARunWhoseMarkerDoesNotReadBackIsReadAsAbsentAndNamed` (Unit),
  `testARecordOfTheRunThatDoesNotReadBackIsDecidedOnByNoCall` (Integration).

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
  zurückgenommen bzw. fortgesetzt; die Ablehnung nennt die Wege). `abort`
  läuft unter der Sperre des Aufrufs (GitHub-Codex 4, P2; Variante A): hält
  sie ein anderer Request, Ablehnung `ab_mcp_sync_run_busy` (erneut
  aufrufen); unter der Sperre wird der Lauf neu gelesen (nicht mehr `open` →
  `ab_mcp_sync_run_changed`, nichts geändert) und gesettelt (ein Schritt
  `begun` eines beendeten Requests: `interrupted`, nicht abgebrochen), und das
  Schliessen, die Freigabe der Reservierung und die Bereinigung der Ablage
  geschehen darunter. Jeder verändernde Aufruf liest den Lauf unter der
  Sperre neu, bevor er entscheidet (`AB_MCP_Sync_Engine::fresh()`, die eine
  Stelle des früheren `still()`): ein Request, der den Lauf vor einem
  `abort`, einem Schliessen, einem Settle oder einer Fortsetzung gelesen
  hatte, schreibt nichts (`ab_mcp_sync_run_state`, die Schritte bleiben, wie
  sie sind), auch nicht die Gesamtvorprüfung, die Caches oder die Prüfung
  des Laufs. Vorher konnte ein verzögertes `next` seinen Schritt nach einem
  erfolgreichen `abort` noch `begun` setzen und in einen
  `closed_aborted`-Lauf schreiben, dessen Reservierung schon freigegeben war
  (Prüffälle
  `testAnAbortTakesTheLockAndADelayedWriteStepFindsTheRunClosedAndWritesNothing`,
  `testADelayedCallWithAnOldReadOfTheRunWritesNothingAfterAnAbortOrAfterTheWayBackClosed`).
- **Ein Weg (b) für jeden unterbrochenen Lauf**, für beide Laufarten gleich
  (GitHub-Codex 4 und 6, je P1; Variante A): `wp_site_revert action=plan` mit
  Token auf einen unterbrochenen Lauf geht in eine Funktion
  (`AB_MCP_Sync_Revert::interrupted_plan()`). Hat der Rückweg des Laufs
  begonnen (ein Rückweglauf der Art `revert`, oder ein Lauf der Art `push`
  mit Rückweg-Schritten: der Rückweg desselben Laufs, Entscheid 15;
  `AB_MCP_Sync_Run::on_way_back()`), wird genau dieser Rückweg fortgesetzt
  (`continuation()`, `begin_same_run()`): der Schritt, den die Unterbrechung
  `begun` liess, wird `unclear` und bleibt stehen (kein Rückbau wird
  wiederholt, kein Teil davon geht von selbst zurück; sein Paar `unclear`),
  die noch geplanten Rückweg-Schritte laufen danach mit `next`, jeder mit
  frischer Lesung (nur eigene Teile, Entscheid A); keine neuen
  Rückweg-Schritte, kein Wiederanlauf, `steps` wird nicht angenommen; der
  Lauf schliesst `closed_findings` und nennt den stehen gebliebenen Schritt
  in der Antwort. Hat er nicht begonnen (ein unterbrochener Vorwärtslauf),
  beginnt der Rückweg wie für einen offenen Lauf (`way_back()`). Weg (a)
  bleibt, wie gebaut: eine Person schliesst jeden unterbrochenen Lauf in
  wp-admin «als geprüft» (`AB_MCP_Sync_Run::close_checked()`: der Schritt,
  den die Unterbrechung `begun` liess, wird `unclear`, das Paar des Schritts
  bzw. des Schritts, den er zurücknahm, `unclear` – ein Rückweg-Schritt trägt
  sein Paar erst am Ende, darum über `pair_of_revert_step()` –, `open_one`
  wird frei; seit Variante A unter der Sperre des Aufrufs, der Lauf darunter
  gesettelt; der Knopf kommt mit Zweig 12, bis dahin nur interne Fassungen).
  Die Hinweise (`status`, die Ablehnungen von `next`, `abort` und eines neuen
  Plans, `AB_MCP_Sync_Run::way()` und `interrupted_refusal()`) nennen je Lage
  die Wege, die gehen: auf dem Rückweg «leads it on», sonst «begins its way
  back». Vorher nahm die Fortsetzung nur Läufe der Art `revert`; ein
  unterbrochener Rückweg desselben Laufs (Art `push`) wurde als neuer
  Rückweg geplant, fand jeden Schreibschritt schon von seinen
  Rückweg-Schritten genannt und lehnte ab (`ab_mcp_sync_revert_nothing`),
  `next` und `abort` nehmen keinen unterbrochenen Lauf, und der Lauf hielt
  die Site, bis eine Person ihn schloss (Prüffälle
  `testAnInterruptedWayBackOfTheSameRunIsLedOnWithTheTokenAndTheSiteIsFreeAfterwards`,
  `testAnInterruptedRunOfTheWayBackIsLedOnWithTheTokenOrClosedAsCheckedAndFreesTheSite`).
  Ein geschlossener Rückweglauf wird nicht zurückgenommen.
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
- `backup_rows()` liest die Felder der Aufbewahrung ohne die Spalte `plan`
  und den Plan nur für jeden nicht geschlossenen Lauf einzeln (im Normalfall
  höchstens einer, `open_one`; GitHub-Codex 7, P2): nur dort steht, welche
  Läufe ein offener Rückweg braucht (Regel 4). Vorher lud jede Bereinigung
  und jedes Öffnen einer Gruppe der Ablage (`AB_MCP_Protected_Store::sweep()`)
  den Plan aller vergangenen Läufe (bis `MAX_PLAN` = 8 MiB je Lauf), und eine
  lange Geschichte erschöpfte den Speicher. Die Auswahl bleibt gleich, auch
  der Gleichstand nach `run_id`; scheitert das Lesen eines Plans, bleibt
  jede Laufsicherung (`null`). Prüffall
  `testTheRetentionReadsThePlanOfARunThatIsNotClosedOnlyAndKeepsWhatItKept`
  (Unit, über die Abfragen der `$wpdb`-Attrappe).
- Schliessen zwei qualifizierte Läufe in derselben Sekunde (`forward_closed_at`
  gleich), zählt der jüngere Lauf (höhere `run_id`) zuerst zu den letzten
  drei (GitHub-Codex 3, P2): vorher kamen die Zeilen nach `run_id`
  aufsteigend und `arsort()` liess bei Gleichstand den älteren vorn, so dass
  bei Abschlüssen 100, 100, 200, 300 die Läufe 1, 3, 4 blieben und die
  Sicherung von Lauf 2 ging (Prüffall
  `testTheRetentionBreaksATieOfTheSameCloseByTheYoungerRun`).
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
- Phase 7 (Caches) läuft ebenso in Teilen nach der Arbeit des Aufrufs
  (`budget()`), der Cursor im Ergebnis des Schritts (GitHub-Codex 5, P2):
  jeder Aufruf leert mindestens ein Objekt, sichert bei erschöpfter Arbeit
  den Cursor am ersten noch nicht geleerten Objekt und antwortet «weiter mit
  next»; nach dem letzten Teil die Term-Caches und `done` wie bisher. Jedes
  Objekt wird einmal geleert; endet ein Request zwischen einem Leeren und
  dem Sichern des Cursors, werden die Objekte dieses Aufrufs noch einmal
  geleert, was nichts ändert (ein geleerter Cache ist leer). Vorher leerte
  der Schritt alle Objekte in einem Aufruf ohne Budget; bei grossen Auswahlen
  lief er in die Zeitgrenze, blieb `planned`, und jeder Versuch begann beim
  ersten Objekt (Prüffall
  `testTheCachesOfPhaseSevenAreEmptiedInPartsWithinTheWorkOfEachCallEachObjectOnce`).
- Die Stichprobe fragt eine veröffentlichte Adresse mit `wp_remote_get()`
  (Loopback), seit Zweig 9b nur die eines Typs mit eigener Seite
  (`is_post_type_viewable()`: keine Vorlage, kein Vorlagenteil, keine
  globalen Stile, keine Navigation, kein wiederverwendbarer Block;
  GRENZEN-INHALTE.md), und verlangt 200 und die erwartete Zeichenkette: den Titel des
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
- Das Fehlerlog (`wp-content/debug.log`) wird nicht mehr ganz gelesen
  (GitHub-Codex 4, P2: `file()` lud die ganze Datei, ein Log von 300 MiB
  beendete Prüfung und Lauf mit einem Fatal Error): die Gesamtvorprüfung
  merkt sich seine Grösse (`error_log_bytes`; 0, wenn es noch keines gibt),
  die Prüfung liest ab dieser Stelle in Stücken von 64 KiB
  (`AB_MCP_Sync_Engine::error_log_lines_since()`, `LOG_CHUNK`) und zählt
  die Zeilenenden, höchstens `LOG_BYTES` = 16 MiB je Aufruf. Neue Zeilen sind
  ein Befund; ein Log, das kleiner ist als an der gemerkten Stelle (rotiert
  oder geleert), wird von vorn gezählt und als solches gemeldet (seine
  Zeilen sind von neuen nicht zu unterscheiden); ein Log, das um mehr als
  16 MiB gewachsen ist, wird bis dort gezählt und als «nicht bis zum Ende»
  gemeldet; ein Log, das es an der Vorprüfung nicht gab und seither entstand,
  ist ganz neu (Marke 0); ein Lesefehler vor dem Ende (`fread()` false) gilt
  nicht als Dateiende, sondern als «nicht bis zum Ende gezählt» und ist ein
  Befund (Codex-Runde 22); eine Datei, die sich nicht öffnen oder
  positionieren lässt, ebenso (`null` → Befund «nicht lesbar»). Nie «keine
  neue Zeile», wo nicht gezählt werden konnte. Ein Journal eines Laufs, der vor diesem Stand begann (Marke als
  Zeilenzahl, `error_log_lines`), gibt den Befund «nicht markiert». Grenze:
  gelesen wird nur `wp-content/debug.log`; ein Log, das `WP_DEBUG_LOG` an
  einen anderen Ort schreibt, wird nicht verglichen (kein Befund), wie
  bisher; ob die Prüfung ihm folgen soll, ist offen (die Test-Sites schreiben
  so, und WordPress' eigene Update-Warnungen bei gesperrtem externem HTTP
  stünden dann in jedem Lauf als Befund). Prüffälle
  `testTheErrorLogIsCountedFromItsMarkInChunksWithACapAndARotationIsSaidSo`
  (Unit, 20 MB in Stücken, Kappe, Rotation),
  `testTheErrorLogIsCountedFromTheMarkOfTheCheckAndALogRotatedSinceIsAFinding`
  (Integration).

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
  Integration (`tests/integration/SyncRunTest.php`, siebenundsechzig Fälle): Ende zu Ende mit
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
  dem Rückweg des geschlossenen Laufs ganz stehen (Vorschau `will` = `stays`),
  verweigerter Zeilenaufruf ohne Schreiben (`failed`, nichts `left`). Unit
  dazu: Schlüssel mit Backslash, Recht zum Entfernen eines nur live
  vorhandenen Schlüssels. GitHub-Codex 2 (07.11.2026), je ohne die Behebung
  rot: Integration – der Rückweg beginnt mit allen Schritten oder gar nicht
  (Trigger der Datenbank verweigert den zweiten Rückweg-Schritt, im selben
  Lauf und im Rückweglauf eines geschlossenen Laufs), Rechte am Objekt auf
  dem Rückweg (Schlüssel über `auth_post_meta_note`, Seite über `edit_post`,
  Term über `assign_term` verweigert: drei Teile `left` mit altem Wert), Rechte
  je Taxonomie vorwärts (Format-Slug ohne Term, mit Term, nur live vorhandene
  Taxonomie: je `closed_aborted` vor dem ersten Schreiben), die Sicherung mit
  Bytes, die kein UTF-8 sind; Unit – `term_right()` und `precheck()` über
  jede Taxonomie, `restore_right()` je Teil, `transaction()` (Engine-Prüfung,
  Commit, Rollback bei `WP_Error` und Ausnahme), die zweite Prüfung der Art
  beim Schreiben (`restore_row()`, `restore_meta()` gegen `$written`),
  `AB_MCP_Sync_Canon::decode()` mit jeder abgelehnten Hülle. Variante A
  (07.11.2026 abends), je ohne die Behebung rot: Integration – ein
  unterbrochener Rückweg desselben Laufs (Art `push`, der Rückweg-Schritt in
  eigenem Prozess beendet) wird mit Plan und Token fortgesetzt, die Site ist
  danach frei; bei fremder Sperre lehnen `next`, `abort`, Plan mit Token und
  der Schluss des Rückwegs busy ab, ohne Lauf, Schritte, Reservierung,
  Sicherungen oder Seiten zu ändern, und gehen nach der Freigabe; ein
  verzögerter Aufruf mit altem Laufstand schreibt nach `abort` und nach dem
  Schluss des Rückwegs nichts; unter der Sperre des Aufrufs ist ein eigener
  begonnener Schritt kein unterbrochener, der eines beendeten Requests schon,
  und «als geprüft schliessen» läuft unter der Sperre. Codex-Runde 18:
  ohne `edit_post` bleiben Zeile und Kategorie stehen (Integration); ein Hook
  der Rechteprüfung, der Titel oder Meta-Wert beim Rückweg ändert, wird von
  der zweiten Lesung der Art gefangen (Integration, hält
  `writer-restore-row-own` und `writer-restore-meta-own`). Mutationen:
  `tests/mutation/lists/lauf.php` (aus `tests/mutation/gen/lauf.py`), je
  Bedingung von `decide()` und `deviated()`, für die Aufzeichnung von
  `pre`/`post`/`own`, für den alten Wert und seine Kappung, je Recht von
  `term_right()` und `restore_right()`, für die Transaktion in `begin()` und
  `create()`, für `decode()` je Hülle.
- Nicht als Mutation geführt: die Wächter gegen eine Datenbank, die ein Lesen
  oder Schreiben verweigert (keine Test-Site verweigert), das Budget der
  Gesamtvorprüfung über Aufrufe mit Tausenden Objekten (die Fortsetzung selbst
  hält ein Test mit gestellter Uhr), die PHP-Probe (ersetzt: im Test besteht
  sie; dass `partial` und `unreachable` Befunde sind, zeigt nur die Live-Probe),
  die Kapazitätsprüfung des Rückwegs im Plan (mit 1996 Objekten je Lauf und
  3996 Schritten je Lauf nicht erreichbar), der Schritt ohne Sicherung in `revert_boundary()` (der Plan
  lässt ihn nicht hinein), der Blick eines lesenden Aufrufs auf die
  Sperrzeile vor `take()` in `settle()` (`lock_fresh()`: gleichwertig mit
  `take()` allein, solange nicht zwei Requests im selben Augenblick laufen),
  die Marke `settled` (oben, nur mit
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
  $again`: die Art liest beim Schreiben ohnehin noch einmal; deren zweite
  Prüfung hält ein Integrationstest mit einem Hook der Rechteprüfung, der den
  Teil zwischen den Lesungen ändert, Codex-Runde 18),
  die Fehlerpfade von `update_step()` und `START TRANSACTION`/`COMMIT` in
  `begin()` und `transaction()` (keine Test-Datenbank verweigert sie; der
  gescheiterte Insert ist geführt) und die Texte der Ablehnungen
  (`AnswerWayTest` hält, dass jede einen Weg nennt).
