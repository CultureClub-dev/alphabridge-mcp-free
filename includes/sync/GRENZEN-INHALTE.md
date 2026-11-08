# Grenzen der Inhalte (Z8, Zweig «Inhalte»)

Gesammelte Gruppe-C-Grenzen von Zweig 9 (`heikel/z8-inhalte`): die Term-Art
(`AB_MCP_Sync_Term_Writer`) und die Post-Art mit Feldregel, Slug,
Sanitizern vorab und Beitragsbild (`AB_MCP_Sync_Post_Writer`; Entscheide
17, 19, 21, 25, 28, 30, 31). Eine eigene Datei statt eines Abschnitts in
`GRENZEN-LAUF.md`, weil diese Grenzen die Arten betreffen und nicht den
Lauf; die Grenzen des Laufs (Sperre, Unterbrechung, Journal, die eine
Grenze «Abweichung im Schritt», Rückweg) gelten für beide Arten unverändert
weiter. Je Grenze ein Satz mit Beispiel und Grund; wo eine Sperre entsteht,
nennt ihre Meldung den Weg.

## Der Schreiber je Art

- Die Engine wählt den Schreiber nach der Art des Plans
  (`AB_MCP_Sync_Engine::WRITERS`: `post`, `term`); alle Aufrufe am Objekt
  (Vorbereitung, Vorprüfung, Lesen, Journal-Teile, Schreiben, Rücklesen,
  Rückweg, Rechte, alter Wert, Caches, Sperre) gehen über ihn. Eine Art
  ohne Schreiber (Menü, Attachment, Widget-Bereich, CSS, Option) schreibt
  kein Schritt; der Plan nimmt sie ohnehin nicht (`verdict()`), die
  Gesamtvorprüfung meldet sie als Befund. Grund: ein Vertrag je Art
  (Vertrag A), keine zweite Grenze.
- Ein Journal aus Zweig 8 ohne `object.kind` gilt als Post-Journal
  (`writer_of()`); Zweig 8 schrieb nur Posts.

## Post: Feldregel (Entscheid 28)

- Teil eines Schritts ist nur ein Meta-Schlüssel, dessen Werte zwischen
  Sollwert und Live-Post abweichen (`differing()`); ein Schlüssel, der auf
  beiden Seiten gleich ist, bleibt unberührt, auch ein geschützter
  Plugin-Schlüssel (`_yoast_…`, `_seo_title`). Vorher sperrte jeder
  Live-Post mit einem Schlüssel ausserhalb der Regel von Zweig 8 die
  Übernahme (`post:meta_rule`), also fast jede Seite mit Plugins. Prüffall
  `testAPluginKeyEqualOnBothSidesStaysUntouchedAndOneThatDiffersWaitsForARuleOfTheSite`.
- Ein abweichender Schlüssel fragt das Recht jeder Operation, die
  `write_meta()` für ihn ausführt (`meta_ops()`, die eine Stelle für das
  Schreiben und seine Rechte), über `map_meta_cap` am Live-Post: bleibt eine
  Zeile eine Zeile, `edit_post_meta` (`update_metadata()`); sonst
  `delete_post_meta`, wo Zeilen da sind (`delete_metadata()` entfernt alle
  Zeilen des Schlüssels), und `add_post_meta`, wo Werte zu speichern sind
  (`add_metadata()` je Wert). Vorher galt die Nettoanzahl der Zeilen: zwei
  Zeilen zu zwei anderen fragten nur `edit_post_meta`, und eine Regel, die
  `edit` gab, `add` und `delete` aber verweigerte, liess zwei andere Werte
  durch (Codex-Runde 1, A2; Prüffall
  `testTheRightsOfAKeyOfSeveralRowsAreTheOperationsTheWriteMakes`). Der
  Rückweg (`restore_meta()`, Zeile um Zeile roh) fragt seine eigenen
  Operationen (`restore_ops()`): `edit` für Zeilen beidseits, `add` für
  Zeilen, die der Schritt entfernte, `delete` für Zeilen, die er anlegte.
  Verweigert, sperrt der Post vor jeder Mutation mit `*:meta_without_rule`
  (Z8d, «Feldregel folgt mit Z8d»); das gilt für geschützte Schlüssel ohne
  Regel ihres Plugins. Gibt eine Regel der Site das Recht
  (`register_meta()` mit `auth_callback`, Filter `auth_post_meta_<key>`),
  geht der Schlüssel hin und zurück. Grund: Entscheid 28 wörtlich; die
  Metadaten-API fragt selbst kein Recht.
- Ein Schlüssel eines Page-Builders (`AB_MCP_Builders::markup_meta_refusal()`)
  braucht zusätzlich `unfiltered_html`, wie der Free-Meta-Schreiber.
- Ein Schlüssel mit dem Namen eines Zugangsdatums (B9) und ein Schlüssel
  der Gruppe B (`_edit_lock`, `_wp_old_slug`, …) ist nie Teil: nie
  gepackt, nie verglichen, nie geschrieben, auf keiner Seite.
- Neue Posts: Vor dem Anlegen gehen nur Schlüssel, deren Recht dem Recht
  am eigenen neuen Post folgt (nicht geschützt, ohne eigene
  `auth_post_meta_<key>`- oder `…_for_<type>`-Regel; `new_meta_allowed()`)
  und die Kern-Schlüssel mit eigener API; jeder andere sperrt den Post vor
  dem Anlegen (`post:create_with_object_meta`, Z8d). Unmittelbar vor jedem
  Meta-Aufruf fragt `write_part()` das Recht am inzwischen angelegten Post;
  verweigert, wird der Aufruf nicht gemacht, das Objekt weicht ab, bleibt
  als Rest (`residual`) und steht in der Antwort (Entscheid 28, «Fehler
  erst nach dem Anlegen»; Prüffall
  `testANewPostWithAPlainKeyGoesAndOneWhoseRightTheNewPostRefusesStaysAsARest`).
  Grenze C: Ein Filter von `map_meta_cap`, der für ein neues Objekt anders
  antwortet als `is_protected_meta()` und `has_filter()` vorhersagen,
  zeigt sich erst so.
- Serialisierte Werte: nur ein Array, das `serialize()` bytegleich
  wiedergibt (`meta_value()`); ein serialisierter Skalar (`b:1;`) oder ein
  Wert, den ein älteres PHP anders schrieb (`d:0.10000000000000001;`),
  sperrt `post:meta_rule` (Z8d). Grund: die Metadaten-API speichert
  `maybe_serialize()` des Übergebenen; der Wert läse anders zurück.
- `post:meta_spelling` und `post:term_order` bleiben gesperrt (Stufe jetzt
  Z8d): Zweig 9 bringt keine Feldregel für zwei Schreibweisen unter der
  Kollation oder für sortierte Zuordnungen; die Grenzen aus
  `GRENZEN-LAUF.md` gelten weiter. Die Schreibweisen-Sperre fragt jetzt
  die Schlüssel, die der Schritt schreibt (die abweichenden, dazu
  `_wp_page_template`, den die Zeile immer mitschreibt).

## Post: Beitragsbild

- `_thumbnail_id` geht über `set_post_thumbnail()` und
  `delete_post_thumbnail()` mit `edit_post` (Entscheid 28), der Wert über
  das Paar des Attachments (`post:reference_unmapped` ohne Paar). Vor dem
  Schreiben muss das Attachment hier ein Bild sein, das WordPress zeigt
  (`wp_get_attachment_image()`), sonst `post:thumbnail` (Z8): sonst
  entfernte `set_post_thumbnail()` das Beitragsbild statt es zu setzen.
- Grenze C (bis Zweig 10): Attachments sind noch nicht im Bestand, also
  hat kein Attachment ein Paar; der Bericht lässt einen Post mit
  Beitragsbild auf das Attachment warten. Der Weg über
  `set_post_thumbnail()` ist darum nur in der Unit-Suite belegt
  (`testTheFeaturedImageGoesThroughItsOwnApiToAnAttachmentWordPressShowsAsAnImage`).

## Post: Slug

- Die Vorprüfung rechnet den Slug, den `wp_insert_post()` speichern würde
  (`slug_refusal()`): ein `pending`-Post eines Kontos ohne Recht zum
  Veröffentlichen verliert ihn; ein veröffentlichter ohne Slug bekäme einen
  aus dem Titel; `sanitize_title()` (bei einem Update in der alten
  Fassung, wo der Post sie hat); `wp_unique_post_slug()` mit den Filtern
  des Kerns und der Plugins. Weicht er ab, sperrt `post:slug_collision`
  bzw. `post:sanitizer` (Z8) mit dem Slug, den WordPress speichern würde.
  Ein Entwurf behält seinen Slug wie im Kern (`wp_unique_post_slug()` gibt
  ihn für `draft`, `pending` und `auto-draft` unverändert zurück).
- Grenze C (konstruiert): Ein Post im Papierkorb, der den Slug hält
  (seit WordPress 5.5 bekommt ein Post beim Verschieben in den Papierkorb
  `__trashed`; so entsteht er nur mit einem Status `trash` beim Anlegen oder
  per SQL), sperrt den Schritt, in jedem Status ausser `trash`, auch für
  einen Entwurf: `wp_insert_post()` benennt ihn vor `wp_unique_post_slug()`
  für jeden Post mit Slug um, der nicht in den Papierkorb geht
  (`wp_add_trashed_suffix_to_post_name_for_trashed_posts()`,
  wp-includes/post.php:4884), eine Zeile eines fremden Posts (Prüffälle
  `testANewPageWhoseSlugAPostInTheTrashHoldsIsLockedBeforeAnythingIsWritten`,
  für den Entwurf `testTheSlugIsWhatWordPressWouldStoreOrTheStepIsLocked`).
  Vorher übersprang die Vorprüfung diese Frage für Entwürfe, und die
  Sperrzusage stimmte für sie nicht (Codex-Runde 1, C). Ein Entwurf ohne
  Slug benennt nichts um.
- Ein neuer Post, dessen Slug ein anderer Post seines Typs hält, ist meist
  ein Paar, das der Bericht als «auf beiden Seiten neu» vorschlägt
  (Identität Typ, Theme, Slug); die Sperre trifft die Fälle daneben
  (anderer Elternteil, Attachment mit dem Slug einer Seite, Filter).

## Post: Sanitizer vorab und Sperren der kanonischen Form

- `sanitize_post( …, 'db' )` prüft jetzt auch `comment_status`,
  `ping_status` und `post_mime_type`, `sanitize_meta()` jeden
  geschriebenen Wert; eine Abweichung sperrt `post:sanitizer` vor dem
  ersten Teil. Verglichen werden die Bytes, die WordPress speichern würde
  (`db_form()`: `maybe_serialize()`, ein Skalar als sein Text, ein Bool als
  `1` oder leer), nicht der PHP-Wert: `absint()` gibt für `'5'` die Zahl 5,
  gespeichert wird wieder `5`, nichts ändert sich (Codex-Runde 1, B3;
  vorher sperrte `!==` jeden numerischen Sanitizer; Prüffall
  `testASanitizerIsComparedByTheBytesWordPressWouldStore`). Ein unbekannter Hook, der erst beim Schreiben ändert
  (`updated_post_meta`), bleibt «geschrieben, abweichend» nach der einen
  Grenze von `GRENZEN-LAUF.md`.
- Was die kanonische Form einer Seite sperrt, sperrt auch der Schreiber:
  die Zeile des Pakets (`line_lock()`: Builder nach Entscheid 31,
  Zugangsdaten in Feldern, unlesbare Werte) und der Live-Post
  (`live_rule()` über `read_lock()`); ein Typ, den die Tabelle sperrt
  (Changeset, Entscheid 30; Schriften; Typen von Plugins), mit seiner
  eigenen Sperre und Stufe. Der Bericht hält solche Einträge schon zurück;
  der Schreiber prüft es unter der Sperre der Grenze noch einmal.
  WPBakery gesperrt, Kadence übernommen: Prüffall
  `testAWpBakeryPageIsLockedAndAKadencePageGoesOver`.

## Post: weitere Kern-Typen (bis Zweig 9b)

- Vorlagen, Vorlagenteile, globale Stile, Navigationen und
  wiederverwendbare Blöcke (`wp_template`, `wp_template_part`,
  `wp_global_styles`, `wp_navigation`, `wp_block`) bleiben in Zweig 9
  gesperrt (`post:engine_type`, Stufe Z9b): Zweig 9b
  (`heikel/z8-vorlagen`) gibt sie direkt nach dem Merge von Zweig 9 frei
  (Entscheid von Thomas «A: eigener Zweig 9b», 08.11.2026). Entscheid 25
  nennt sie bei der Post-Art, der Normalfall des Auftrags nicht. Ihre
  Identität hängt am Theme (`wp_theme`, `wp_template_part_area`), ihre
  Eindeutigkeit des Slugs am aktiven Stylesheet
  (`wp_filter_wp_template_unique_post_slug()`). Dieselbe Sperre trägt, was
  auch Zweig 9b nicht schreibt: ein Status, den WordPress nicht so
  speichert (Papierkorb, `auto-draft`), ein Paket ohne Inhalt des Objekts
  und eine Sperre, die diese Version nicht liest (fail closed).

## Term (Entscheid 25, A4)

- Geschrieben werden die Terme von `category`, `post_tag` und
  `wp_pattern_category` (die Taxonomien der Term-Art): Zeile (Name, Slug,
  Beschreibung, Eltern) über `wp_insert_term()` und `wp_update_term()`,
  Name und Beschreibung mit den Adressen dieser Site, Eltern über das Paar;
  ein Elternteil, den derselbe Lauf vorher anlegt, gilt in der
  Gesamtvorprüfung als ausstehend (Eltern zuerst, Prüffall
  `testANewCategoryWithANewChildAndANewPostInTheChildGoInOneRunParentsFirst`).
  Ebenso ein neuer Term, in dem ein neuer Post derselben Planung liegt.
- Rechte (Entscheid 28): am vorhandenen Term `edit_term`, je abweichendem
  Meta-Schlüssel das Recht jeder Operation, die `write_meta()` ausführt
  (`edit_`, `delete_` und `add_term_meta` wie bei Posts, `meta_ops()`;
  Codex-Runde 1, A2, Prüffall
  `testTheRightsOfAKeyOfSeveralRowsOfATermAreTheOperationsTheWriteMakes`),
  über `map_meta_cap` (verweigert: `*:meta_without_rule`, Z8d); für einen neuen
  Term `manage_terms`, `edit_terms` und `assign_terms` der Taxonomie. Auf
  dem Rückweg dieselben Rechte, `edit_term` zuerst.
- Neue Terme mit Meta sperrt schon der Bericht (`term:create_with_meta`,
  Z8d), der Schreiber ebenso.
- Grenze C: `term_group` ungleich 0 (eine Gruppe von Aliassen, ein Rest der
  Link-Kategorien) auf einer der beiden Seiten sperrt `term:term_group`
  (Z8d): die Gruppe ist eine ID der Site selbst, die kein Paar abbildet.
- Umhängen und Tauschen (Codex-Runde 1, B4): Die Gesamtvorprüfung fragt
  eine Schleife der Eltern am Stand, den der Lauf hinterlässt: die Terme,
  die er schreibt, mit den Eltern, die er ihnen gibt, jeder andere Term wie
  hier (`AB_MCP_Sync_Engine::planned_parents()`). Zwei Kategorien, die die
  Plätze tauschen (hier Beta unter Alpha, auf Staging Alpha unter Beta),
  schliessen so keine Schleife; vorher sperrte der Abgleich mit dem Stand
  hier den ganzen Lauf. Der Plan schreibt einen Elternteil, den der Lauf
  umhängt, zuerst (`order()`, Kante über den Elternteil des Terms; wie
  jede Kante des Plans: endet der Schritt des Elternteils nicht `done`,
  wird der Schritt des Kinds übersprungen, auch wo es nur seine
  Beschreibung ändert, fail closed), und
  die Grenze prüft den Stand, wie ihn `wp_update_term()` dann sieht; der
  Rückweg geht vom letzten Schritt zum ersten (`AB_MCP_Sync_Revert::plan()`),
  so dass auch dort kein Zwischenstand eine Schleife schliesst. Prüffälle
  `testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell`
  (Tausch hin und zurück, gewöhnliches Umhängen von Beta nach Gamma),
  `testTheLoopOfParentsIsAskedOfTheStateTheRunLeaves`,
  `testATermTheRunMovesUnderAnotherTermItWritesComesAfterIt`.
- Grenze C: Ein Elternteil, der am Stand nach dem Lauf eine Schleife
  schlösse (ein Term ausserhalb der Auswahl hängt hier anders als auf
  Staging), sperrt `term:parent` (Z8d): `wp_update_term()` bräche die
  Schleife über `wp_check_term_hierarchy_for_loops()`, indem es fremden
  Termen den Elternteil 0 gibt. Ebenso ein Elternteil in einer Taxonomie
  ohne Eltern. Ein Term, dessen neuer Elternteil im selben Lauf erst
  angelegt wird, zählt in der Gesamtvorprüfung mit seinem Elternteil von
  hier (fail closed: ein anderer Term, der darunter wandert und hier sein
  Vorfahr ist, sperrt dann); die Grenze fragt den Stand danach ohnehin
  noch einmal. Diese Zurückhaltung hat keinen eigenen Mutationseintrag:
  ohne sie fände die Grenze dieselbe Schleife erst nach dem Anlegen des
  neuen Elternteils.
- Slug: hält ihn ein anderer Term der Taxonomie, oder machte
  `wp_unique_term_slug()` einen anderen daraus, sperrt
  `term:slug_collision` (Z8). Ein Term gleicher Identität (Taxonomie,
  Slug, Sprache) ist normalerweise ein Paar; die Sperre trifft die Fälle
  daneben (Zwillinge, andere Sprache).
- `sanitize_term( …, 'db' )` (die Filter `pre_term_name`,
  `pre_term_description`, kses ohne `unfiltered_html`) und `sanitize_meta()`
  vorab (Meta nach den gespeicherten Bytes wie beim Post, Codex-Runde 1,
  B3); ein leerer Name oder Slug sperrt `term:sanitizer` (Z8).
- Grenze C (konstruiert): `wp_insert_term()` gibt für einen Doppelgänger
  (gleicher Slug, gleiche Ebene, nach dem Einfügen gefunden) die ID des
  vorhandenen Terms zurück und löscht die eingefügten Zeilen. Ein Term,
  dessen ID nicht über der höchsten vor dem Aufruf liegt, ist darum nie
  eigen: der Zeilenaufruf gilt als gescheitert, kein Rest, kein Paar
  (`write_row()`). Nur bei einem gleichzeitigen Anlegen am Kern vorbei
  denkbar; `pre_insert_term` der Taxonomie ist während der Grenze
  abgewiesen.
- Grenze C: Eine Term-ID mit Zeilen in zwei Taxonomien (vom Kern nicht
  geteilt) liest weder der Bestand noch der Schreiber (`current()`
  antwortet mit Fehler); der Weg ist, den Term in wp-admin einmal zu
  speichern.
- Die Zeile eines Terms liegt auf einem Weg, den die Sperre für andere
  Hände nicht abweist (`WAY_ROW = not_refusable`; Entscheid 19): ein
  `wp_update_term()` eines anderen Plugins oder der Term-Seiten von
  wp-admin im Fenster zwischen Zielprüfung und Schreiben kann verloren
  gehen. Abgewiesen sind das Speichern über die REST-API
  (`rest_request_before_callbacks`, 423 mit Meldung; der Filter
  `rest_pre_insert_{$taxonomy}` kann es nicht, weil
  `WP_REST_Terms_Controller::update_item()` seine Antwort ungeprüft zu den
  Argumenten von `wp_update_term()` macht), die Term-Meta (Kurzschlüsse
  nach Objekt und `meta_id`) und neue Terme der Taxonomie
  (`pre_insert_term`), vorwärts und auf dem Rückweg (Prüffall
  `testAnotherWriterOfATermIsRefusedWhileItsStepWrites`).
- Grenze C: Während der Grenze eines Terms (Sekunden) weist die Site neue
  Terme derselben Taxonomie ab, auch ein Schlagwort, das ein Editor beim
  Speichern eines Beitrags neu anlegt; er speichert danach erneut. Grund:
  sonst könnte ein gleichzeitig angelegter Term den Slug nehmen.
- Rückweg der Zeile über `wp_update_term()` (Entscheid 23), nur wo die
  Zeile bytegenau dem entspricht, was der Schritt zurücklas, und der
  gesicherte Slug frei, der gesicherte Elternteil da und ohne Schleife
  ist und die Bereinigung des Kontos die gesicherten Felder nicht ändert;
  sonst bleibt der Teil `left` mit Grund und altem Wert. Term-Meta geht
  roh bytegenau bedingt zurück (`restore_meta()` mit Typ `term`). Ein im
  Lauf angelegter Term bleibt als Rest (`residual`).
- Kern-Folge (Entscheid 21, Caches): `clean_term_cache()` löscht beim
  Schreiben eines Terms einer Taxonomie mit Eltern die Option
  `<taxonomie>_children` und baut sie sofort neu (`_get_term_hierarchy()`,
  `wp-includes/taxonomy.php:3782, :3990`). Für die einzige Kern-Taxonomie
  mit Eltern steht `category_children` jetzt in der Ausschlussliste der
  Gruppe B (`AB_MCP_Sync_Exclusions::OPTIONS`, mit Fundstelle in
  `tests/fixtures/sync-reasons.php` und Test je Eintrag in
  `SyncKindsTest`): nie verglichen, nie geschrieben; der Bericht führt sie
  nicht als Abweichung, und der Test «kein Löschaufruf» erkennt sie über
  die Liste. Grund: sie ist Arbeit des Kerns wie `rewrite_rules`, kein
  Inhalt. Grenze C: dieselbe Option einer Taxonomie eines Plugins
  (`product_cat_children`) steht nicht auf der Liste (ein Name mit
  `_children` am Ende kann auch eine Option eines Plugins mit Inhalt sein);
  die Options-Art sperrt sie als nicht eingeordnet (Z8d).
- Phase 7 leert die Caches des Terms (`clean_term_cache()`, Term-Meta);
  den Seiten-Cache von Archivseiten eines geänderten Terms leert sie nicht
  (`AB_MCP_Page_Cache::purge_posts()` kennt nur Posts): ein
  Caching-Plugin kann die Archivseite bis zum Ablauf alt zeigen. Die
  Prüfung des Laufs zieht ihre Stichprobe nur aus veröffentlichten Posts;
  ein Lauf nur mit Termen hat keine Stichprobe (kein Befund).

## Paket der Objekte: Status und B9

- Die Ergebnisse von `AB_MCP_Sync_Kind::export()` trugen keinen Status;
  die Prüfung auf B9 in `AB_MCP_Sync_Export::sort()` las einen fehlenden
  Schlüssel (PHP-Warnung `class-sync-export.php:437`, schon auf `a2e4fc3`)
  und griff nie. Seit Zweig 9 gibt `make_objects()` jedem Ergebnis den
  Status nach der Regel des Bestands (`AB_MCP_Sync_Inventory::status_of()`,
  dieselbe Stelle wie `entry()`); `sort()` lässt ein Objekt ohne Status
  draussen und zählt es als zurückgehalten (`needs: status`), `line()`
  macht aus einem Objekt ohne Status eine gehaltene Zeile ohne Hash und
  Inhalt (fail closed). Der Weg des Bestands (`make()` über
  `AB_MCP_Sync_Inventory::run()`) gab jedem Objekt schon über `entry()`
  seinen Status; dort war keine Lücke.
- Ins Paket kam dadurch nie ein Objekt mit dem Namen eines Zugangsdatums:
  einen Status `b9` tragen nur Optionen und Theme-Mods (die Options-Art,
  `bare()`), und diese Arten machen kein Paket von Objekten
  (`ab_mcp_sync_not_in_branch`); Posts und Terme kennen B9 nur je Einheit
  (eine Meta-Zeile mit dem Namen eines Zugangsdatums fehlt in Form und
  Inhalt). Prüffälle `testAnObjectWithTheNameOfACredentialNeverLandsInAPackageOfObjects`
  (Integration), `testAnObjectWithoutTheStatusOfTheInventoryIsNeverPackedAndOneNamedAsACredentialIsCounted`
  (Unit, ohne die Behebung rot).

## Nicht als Mutation geführt

- `writer()` mit einer Art ohne Schreiber in `boundary()` und
  `revert_boundary()` (der Plan nimmt keine solche Art; die
  Gesamtvorprüfung ist geführt: `inhalte/engine-check-no-writer`).
- Die Fehlerwege, wenn eine Zeile nicht lesbar ist (keine Test-Site
  verweigert das Lesen), und die Texte der Meldungen (AnswerWayTest).
- Die Reservierung der Sicherung eines neuen Terms (Grösse einer Marke).
- `live_key()` im Plan des Rückwegs (Post-ID und Term-ID im selben
  Zustands-Hash getrennt; ein gleicher Wert beider Räume in einem Rückweg
  lässt sich auf den Test-Sites nicht gezielt stellen).
