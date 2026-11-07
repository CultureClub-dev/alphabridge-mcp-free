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
- Ein abweichender Schlüssel fragt das Recht seiner Operation über
  `map_meta_cap` am Live-Post: `edit_post_meta` (Zeilen beidseits),
  `add_post_meta` (Sollwert hat mehr Zeilen), `delete_post_meta` (weniger).
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
  Ein Entwurf behält seinen Slug wie im Kern.
- Grenze C (konstruiert): Ein Post im Papierkorb, der den Slug hält
  (seit WordPress 5.5 bekommt ein Post beim Verschieben in den Papierkorb
  `__trashed`; so entsteht er nur mit einem Status `trash` beim Anlegen oder
  per SQL), sperrt den Schritt: `wp_insert_post()` würde ihn umbenennen
  (`wp_add_trashed_suffix_to_post_name_for_trashed_posts()`), eine Zeile
  eines fremden Posts (Prüffall
  `testANewPageWhoseSlugAPostInTheTrashHoldsIsLockedBeforeAnythingIsWritten`).
- Ein neuer Post, dessen Slug ein anderer Post seines Typs hält, ist meist
  ein Paar, das der Bericht als «auf beiden Seiten neu» vorschlägt
  (Identität Typ, Theme, Slug); die Sperre trifft die Fälle daneben
  (anderer Elternteil, Attachment mit dem Slug einer Seite, Filter).

## Post: Sanitizer vorab und Sperren der kanonischen Form

- `sanitize_post( …, 'db' )` prüft jetzt auch `comment_status`,
  `ping_status` und `post_mime_type`, `sanitize_meta()` jeden
  geschriebenen Wert; eine Abweichung sperrt `post:sanitizer` vor dem
  ersten Teil. Ein unbekannter Hook, der erst beim Schreiben ändert
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

## Post: weitere Kern-Typen (offen)

- Vorlagen, Vorlagenteile, globale Stile, Navigationen und
  wiederverwendbare Blöcke (`wp_template`, `wp_template_part`,
  `wp_global_styles`, `wp_navigation`, `wp_block`) bleiben gesperrt
  (`post:engine_type`, Stufe Z8d). Entscheid 25 nennt sie bei der Post-Art,
  der Normalfall des Auftrags nicht. Ihre Identität hängt am Theme
  (`wp_theme`, `wp_template_part_area`), ihre Eindeutigkeit des Slugs am
  aktiven Stylesheet (`wp_filter_wp_template_unique_post_slug()`). Offene
  Frage an Thomas im Bericht von Zweig 9.

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
  Meta-Schlüssel `edit_`, `add_` oder `delete_term_meta` über
  `map_meta_cap` (verweigert: `*:meta_without_rule`, Z8d); für einen neuen
  Term `manage_terms`, `edit_terms` und `assign_terms` der Taxonomie. Auf
  dem Rückweg dieselben Rechte, `edit_term` zuerst.
- Neue Terme mit Meta sperrt schon der Bericht (`term:create_with_meta`,
  Z8d), der Schreiber ebenso.
- Grenze C: `term_group` ungleich 0 (eine Gruppe von Aliassen, ein Rest der
  Link-Kategorien) auf einer der beiden Seiten sperrt `term:term_group`
  (Z8d): die Gruppe ist eine ID der Site selbst, die kein Paar abbildet.
- Grenze C (konstruiert): Ein Elternteil, der hier eine Schleife schlösse
  (die Eltern sind auf beiden Sites verschieden gepaart oder geändert),
  sperrt `term:parent` (Z8d): `wp_update_term()` bräche die Schleife über
  `wp_check_term_hierarchy_for_loops()`, indem es fremden Termen den
  Elternteil 0 gibt. Ebenso ein Elternteil in einer Taxonomie ohne Eltern.
- Slug: hält ihn ein anderer Term der Taxonomie, oder machte
  `wp_unique_term_slug()` einen anderen daraus, sperrt
  `term:slug_collision` (Z8). Ein Term gleicher Identität (Taxonomie,
  Slug, Sprache) ist normalerweise ein Paar; die Sperre trifft die Fälle
  daneben (Zwillinge, andere Sprache).
- `sanitize_term( …, 'db' )` (die Filter `pre_term_name`,
  `pre_term_description`, kses ohne `unfiltered_html`) und `sanitize_meta()`
  vorab; ein leerer Name oder Slug sperrt `term:sanitizer` (Z8).
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
  `<taxonomie>_children` (die Hierarchie im Cache, vom Kern neu gebaut).
  Das ist kein Löschen eines Objekts; der Test «kein Löschaufruf» nimmt sie
  ausdrücklich aus. Sie ist keine Option der Gruppe B in
  `AB_MCP_Sync_Exclusions`; die Options-Art (Zweig 11) ordnet sie ein.
- Phase 7 leert die Caches des Terms (`clean_term_cache()`, Term-Meta);
  den Seiten-Cache von Archivseiten eines geänderten Terms leert sie nicht
  (`AB_MCP_Page_Cache::purge_posts()` kennt nur Posts): ein
  Caching-Plugin kann die Archivseite bis zum Ablauf alt zeigen. Die
  Prüfung des Laufs zieht ihre Stichprobe nur aus veröffentlichten Posts;
  ein Lauf nur mit Termen hat keine Stichprobe (kein Befund).

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
