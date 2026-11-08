# Grenzen der Inhalte (Z8, Zweige «Inhalte» und «Vorlagen»)

Gesammelte Gruppe-C-Grenzen von Zweig 9 (`heikel/z8-inhalte`): die Term-Art
(`AB_MCP_Sync_Term_Writer`) und die Post-Art mit Feldregel, Slug,
Sanitizern vorab und Beitragsbild (`AB_MCP_Sync_Post_Writer`; Entscheide
17, 19, 21, 25, 28, 30, 31); dazu von Zweig 9b (`heikel/z8-vorlagen`) die
übrigen Kern-Typen der Post-Art: Vorlagen, Vorlagenteile, globale Stile,
Navigationen und wiederverwendbare Blöcke. Eine eigene Datei statt eines Abschnitts in
`GRENZEN-LAUF.md`, weil diese Grenzen die Arten betreffen und nicht den
Lauf; die Grenzen des Laufs (Sperre, Unterbrechung, Journal, die eine
Grenze «Abweichung im Schritt», Rückweg) gelten für beide Arten unverändert
weiter. Je Grenze ein Satz mit Beispiel und Grund; wo eine Sperre entsteht,
nennt ihre Meldung den Weg.

## Der Schreiber je Art

- Die Engine wählt den Schreiber nach der Art des Plans
  (`AB_MCP_Sync_Engine::WRITERS`: `post`, `term`, seit Zweig 10 auch
  `attachment`, siehe `GRENZEN-MEDIEN.md`); alle Aufrufe am Objekt
  (Vorbereitung, Vorprüfung, Lesen, Journal-Teile, Schreiben, Rücklesen,
  Rückweg, Rechte, alter Wert, Caches, Sperre) gehen über ihn. Eine Art
  ohne Schreiber (Menü, Widget-Bereich, CSS, Option) schreibt
  kein Schritt; der Plan nimmt sie ohnehin nicht (`verdict()`), die
  Gesamtvorprüfung meldet sie als Befund. Grund: ein Vertrag je Art
  (Vertrag A), keine zweite Grenze.
- Ein Journal aus Zweig 8 ohne `object.kind` gilt als Post-Journal
  (`writer_of()`); Zweig 8 schrieb nur Posts.
- Kein Speichern eines Posts hier löscht eine Revision (in Z8 wird nichts
  gelöscht; Zweig 11, nach Codex-Runde 1): wo diese Site eine Zahl von
  Revisionen hält (`WP_POST_REVISIONS`, Filter `wp_revisions_to_keep`),
  löscht `wp_save_post_revision()` sonst die ältesten darüber. Für den
  eigenen Aufruf auf genau diesen Post gilt «alle behalten» (-1,
  `AB_MCP_Sync_Post_Writer::keeping_revisions()`, im allgemeinen Filter und
  im Filter seines Typs `wp_{$post_type}_revisions_to_keep`, den der Kern
  danach fragt; beide zuletzt, Codex-Runde 2, A7), vorwärts
  (`wp_update_post()`) und zurück (die Zeile), für Beiträge, Seiten und die
  Vorlagen und übrigen Typen aus Zweig 9b. Die Zahl kann die Grenze so
  übersteigen, bis ein späteres Speichern in wp-admin aufräumt; eine Site
  ohne Revisionen (0) bekommt keine. Ein neuer Post hat keine älteren.
  Anhänge legen keine Revisionen an (`wp_insert_post()` kehrt für ein
  Attachment vor `post_updated` zurück, wp-includes/post.php:5212), auch
  wo ein Plugin sie für Attachments meldet. Prüffall
  `SyncContentTest::testATakeOverOfAPageDeletesNoRevisionOfItWhereTheSiteKeepsANumberOfThem`.

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
  braucht zusätzlich `unfiltered_html`, wie der Free-Meta-Schreiber, vorwärts
  (`precheck()`) und auf dem Rückweg (`restore_right()`, vor dem rohen
  `restore_meta()`): hat das Konto das Recht seit dem Lauf verloren
  (`DISALLOW_UNFILTERED_HTML`), bleibt der Teil `left` mit Grund und altem
  Wert wie bei jedem verweigerten Recht des Rückwegs (GitHub-Codex 1 auf
  free#138, P2; Prüffälle
  `testAPageBuilderKeyGoesBackOnlyWithTheRightToStoreUnfilteredHtml`,
  `testTheWayBackAsksTheRightsAtTheObjectAsTheForwardWriteDoesBeforeAPartGoesBack`).
  Term-Meta kennt diese Regel weder vorwärts noch im Free-Undo von Term-Meta
  (die Liste nennt Post-Meta der Builder); dort fehlt sie darum auch auf dem
  Rückweg nicht. Einen anderen Weg zu `restore_meta()` als über
  `restore_right()` gibt es nicht (`put_back()`).
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

## Post: Vorlagen, Vorlagenteile, globale Stile, Navigationen und wiederverwendbare Blöcke (Zweig 9b)

- Seit Zweig 9b schreibt die Engine der Post-Art alle Kern-Typen aus
  Entscheid 25 (`AB_MCP_Sync_Post_Writer::TYPES`: `post`, `page`,
  `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`,
  `wp_navigation`; Entscheid von Thomas «A: eigener Zweig 9b», 08.11.2026).
  Die Sperre `post:engine_type` von Zweig 9 ist aufgehoben: Schlüssel,
  Begründung in `tests/fixtures/sync-reasons.php` und Eintrag in
  `SyncKindsTest` entfernt; der Unit-Fall von Zweig 9
  (`testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock`)
  prüft jetzt, dass jeder der fünf Typen ein Ziel hat und der Schlüssel
  fehlt; die Mutationseinträge `inhalte/post-type-branch-9b` und
  `inhalte/post-type-other-kind` sind auf die neue Zeile umgestellt. Keine
  Lage des Normalfalls ist durch einen neuen Schlüssel ersetzt; die neuen
  Schlüssel (`post:theme_mismatch`, `post:navigation_fallback`,
  `post:css_markup`) treffen Lagen daneben, je mit Grund und Weg.
- Prüfsites: beide Sites laufen mit Twenty Twenty-Five, dem Standard-Theme
  von WordPress 7.1.3 (`WP_DEFAULT_THEME`, `wp-includes/default-constants.php:437`),
  das `wp_install()` aktiviert: ein Block-Theme mit Vorlagen und
  Vorlagenteilen als Dateien. So gilt der Normalfall (dasselbe aktive
  Block-Theme auf beiden Sites) ohne eigenes Theme der Tests; der erste
  Fall prüft es (`SyncTemplatesTest`). Gespeichert wird auf Staging über die
  REST-Routen des Site-Editors (`/wp/v2/templates`, `/wp/v2/template-parts`,
  `/wp/v2/global-styles`), also so, wie der Kern die Zeilen anlegt.

### Identität, Theme, Bereich

- Vorlage, Vorlagenteil und globale Stile haben ihre Identität über Typ,
  Theme und Slug (Entscheid 25; `AB_MCP_Sync_Identity::THEMED`, das Theme ist
  der Slug ihres Terms von `wp_theme`); die globalen Stile tragen den Slug
  `wp-global-styles-<stylesheet>` (`WP_Theme_JSON_Resolver::
  get_user_data_from_wp_global_styles()`, `wp-includes/class-wp-theme-json-resolver.php:478-532`).
  Der Bereich eines Vorlagenteils (`wp_template_part_area`) ist ein Feld,
  kein Teil der Identität (Berichtigung der Steuerung vom 08.11.2026 zum
  Auftrag): der Kern macht den Slug je Typ und Theme eindeutig
  (`wp_filter_wp_template_unique_post_slug()`, `wp-includes/theme-templates.php:47-99`)
  und nennt einen Teil `theme//slug`; ein Wechsel des Bereichs ist eine
  Änderung desselben Objekts. Theme und Bereich werden wie `post_format`
  über den Slug geschrieben und verglichen (`IDENTITY_TAXONOMIES`), so wie
  die kanonische Form sie nennt (`AB_MCP_Sync_Db_Kind::terms_form()`).
  Prüffall `testATemplateItsPartsAndTheGlobalStylesOfTheActiveThemeGoOverAndBack`
  (Bereich des neuen Fusses geschrieben, `get_block_template()` liest ihn).
- Aktives Theme verschieden: Der Kern findet diese drei Typen nur über den
  Namen des aktiven Themes (`get_block_template()`,
  `render_block_core_template_part()`, `wp-includes/blocks/template-part.php:27`,
  die globalen Stile über `get_stylesheet()`), und eine neue Vorlage wird
  unter den Vorlagen des aktiven Themes eindeutig gemacht
  (`wp-includes/theme-templates.php:63`). Darum schreibt der Schritt nur, wo
  das Theme des Objekts das aktive Stylesheet hier ist, wie
  `get_stylesheet()` es gibt und wie die Optionszeile `stylesheet` es hält,
  das Ziel genau dieses eine Theme nennt und ein Objekt hier es ebenso
  trägt (`theme_refusal()`, `inactive_theme()`); sonst `post:theme_mismatch`
  (Z8) vor jeder Mutation, mit dem Weg (dasselbe Theme auf beiden Sites
  aktivieren; ein Wechsel des Themes ist Code, Z8b; oder von Hand im
  Site-Editor). Kein stilles Umschreiben in ein anderes Theme. Schon der Plan
  nennt ein solches Objekt gesperrt und macht keinen Schritt daraus
  (`AB_MCP_Sync_Push::verdict()` über das Theme der Staging-Identität; `all`
  lässt es mit Grund aus): eine Staging-Site behält die Zeilen jedes Themes,
  das sie einmal hatte (die globalen Stile legt der Site-Editor beim Öffnen
  an), und eine Sperre erst in der Gesamtvorprüfung schlösse den ganzen Lauf.
  Gesamtvorprüfung und Grenze fragen es noch einmal (das Theme kann sich
  zwischen Plan und Lauf ändern). Prüffälle
  `testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten` (eine Vorlage von
  Twenty Twenty-Four im Plan gesperrt, eine mit zwei Themes in der
  Gesamtvorprüfung), `testATemplateOfAThemeAFilterShowsInThisRequestOnlyIsLocked`
  (ein Filter auf `stylesheet` im Plan und zwischen Plan und Lauf). Auf dem Rückweg fragt der Schritt das Theme nicht:
  er schreibt dieselbe Zeile zurück, deren Theme-Term er nicht ändert, und
  `wp_filter_wp_template_unique_post_slug()` fragt bei einem vorhandenen
  Post dessen eigenen Term (`:64-67`).
- Namen der Terme: Der Kern liest Theme und Bereich über den Namen ihres
  Terms (`_build_block_template_object_from_post_object()`,
  `wp-includes/block-template-utils.php:857-895`; die Abfragen mit
  `field => name`), der Schritt schreibt sie über den Slug
  (`wp_set_object_terms()` legt einen fehlenden Term mit dem Slug als Namen
  an). Ein Term, dessen Name nicht sein Slug ist, auf Staging (im Paket) oder
  hier (`named_refusal()`), sperrt `post:taxonomy_rule` (Z8d). Grenze C: ein
  Theme, dessen Ordnername kein Slug ist (Grossbuchstaben, Punkt), hat
  einen Term mit anderem Namen als Slug; seine Vorlagen sperren schon
  `post:theme_mismatch` (der Slug ist nicht das Stylesheet).

### Vorlage nur als Datei auf der Live-Site

- Beim ersten Speichern einer Vorlage oder eines Vorlagenteils des Themes
  im Site-Editor legt der Kern eine Zeile an
  (`WP_REST_Templates_Controller::prepare_item_for_database()`,
  `wp-includes/rest-api/endpoints/class-wp-rest-templates-controller.php:575-662`):
  Status `publish`, der Slug, der Term von `wp_theme` mit dem Theme der
  Datei, die Meta `origin` = `theme`, beim Vorlagenteil der Bereich der Datei
  (`wp_template_part_area`), Autor das Konto. Hat Staging so eine Zeile und
  die Live-Site nur die Datei, sagt der Bericht `create`; der Schritt legt
  dieselbe Zeile über `wp_insert_post()` an, die Meta über die
  Metadaten-API, die Terme über `wp_set_object_terms()`. Danach zeigt der
  Kern die Zeile statt der Datei (`get_block_template()`: `source` =
  `custom`). Nach dem Rückweg bleibt die Zeile als benannter Rest
  (`residual`, nichts wird gelöscht); erst wenn eine Person sie entfernt
  (im Site-Editor «Zurücksetzen»), gilt wieder die Datei. Prüffall
  `testATemplateItsPartsAndTheGlobalStylesOfTheActiveThemeGoOverAndBack`
  (Fuss und Vorlage `single`).

### Verweise

- `core/block` und `core/navigation` mit `ref` gehen über das Paar und den
  Umschreiber aus Zweig 4 (`AB_MCP_Sync_Refs::BLOCKS`); ohne Paar sperrt
  `post:reference_unmapped` (Z8), ein Ziel, das derselbe Lauf vorher
  anlegt, ist ausstehend wie bisher. Prüffall
  `testANavigationAndAReusableBlockGoOverAndBackAndAPageNamesBothByTheirIds`.
- `core/template-part` nennt Theme und Slug, keine ID, also kein Paar
  (`part_refs()`, `parts_refusal()`): ein genannter Teil muss hier zu sehen
  sein, das heisst sein Theme ist das aktive (ohne Attribut gilt das aktive,
  `wp-includes/blocks/template-part.php:25`; ein anderes zeigt nichts, :27),
  und die Site hat ihn als veröffentlichte Zeile dieses Themes und Slugs
  (die Abfrage des Kerns, :29-44), als Teil, den derselbe Lauf vorher
  anlegt (in der Gesamtvorprüfung ausstehend, `pending_named()`), oder als
  Datei des aktiven Themes (`get_block_file_template()`, :70-71). Sonst
  `post:reference_unmapped` mit dem Teil und dem Weg; nie umgeschrieben.
  Gilt für jeden Typ der Post-Art, auch eine Seite mit einem Vorlagenteil.
- Eine Seite, die eine eigene Vorlage des Block-Themes nutzt
  (`_wp_page_template` = Slug der Vorlage; der Kern bietet sie über
  `WP_Theme::get_post_templates()` an, `wp-includes/class-wp-theme.php:1372-1387`),
  deren Vorlage derselbe Lauf anlegt: die Prüfung des Angebots
  (`post:page_template`) gilt in der Gesamtvorprüfung als ausstehend
  (`template_pending`), die Grenze fragt sie mit der angelegten Vorlage.
- Reihenfolge (Codex-Runde 1 von Zweig 9b, B): Jeder Verweis, den die
  Grenze fragt, ist eine Kante der Ordnung, wo sein Ziel im selben Lauf
  geschrieben wird (`AB_MCP_Sync_Post_Writer::order_refs()`, `order_name()`,
  `AB_MCP_Sync_Push::order()`): ein wiederverwendbarer Block und eine
  Navigation über ihren `ref`, ein Vorlagenteil über Theme und Slug (ohne
  Theme das aktive), die Vorlage einer Seite (`_wp_page_template`). Legt der
  Lauf das Ziel an, ist die Kante eine Abhängigkeit (ohne Ziel sperrte die
  Grenze das Objekt; scheitert das Ziel, wartet es, «waits for»; ein Zyklus
  sperrt wie bisher, `*:mutual_references`, Z11c); ändert er es, kommt das
  Ziel nur vorher (das Objekt wird auch geschrieben, wo das Ziel scheitert;
  schliessen solche Kanten eine Schleife, gilt für sie die nächste
  Abhängigkeit, sie sind keine). Eine Abhängigkeit ist die Kante nur, wo
  diese Site das Ziel ohne seinen Schritt nicht auflöste (Codex-Runde 2 von
  Zweig 9b, 2; `resolves_here()`: keine Zeile, keine Datei des aktiven
  Themes, keine Vorlage, die das Theme schon anbietet): die erste Zeile des
  Fusses, den Twenty Twenty-Five als Datei hat, ist nur vorher, und die
  Vorlage, die ihn nennt, läuft, wo die Zeile scheitert (Prüffall
  `testAPartTheThemeHasAsAFileIsNoDependencyAndTheTemplateGoesWhereTheRowOfThePartFails`).
  Die feste Rangfolge der Typen (Terme, dann Vorlagenteile, Vorlagen, der
  Rest, Navigationen zuletzt; `NAMED_FIRST`, `LAST`) bleibt Gleichstand-Regel.
  Vorher kam eine Vorlage vor dem neuen Teil, den sie nennt, wenn der Teil
  auf einen neuen Block wartete, und die Grenze sperrte die Vorlage. Die
  frühere Grenze C «verschachtelte Vorlagenteile» entfällt: ein Teil, der
  einen neuen Teil nennt, wartet auf ihn; es bleiben echte Zyklen. Der
  Zustand des Tokens deckt die Folge der Schritte ab (Art und Staging-Ref
  je Schritt) und seit Codex-Runde 2 die Abhängigkeiten jedes Schritts: ob
  eine Kante eine ist, hängt an dieser Site (eine Zeile oder Datei des
  Teils), und eine Abhängigkeit entscheidet, welcher Schritt übersprungen
  wird; eine Zeile, die zwischen Vorschau und Bestätigung entsteht, macht
  den Token veraltet (Prüffall `testATokenWhoseDependenciesNoLongerHoldIsStale`).
  Prüffälle `testATemplateANewPartAndWhatThePartNamesGoInTheOrderOfTheirReferences`
  (Vorlage, neuer Teil, neuer Block, geänderte Navigation: Block und
  Navigation vor dem Teil, der Teil vor der Vorlage; die Abhängigkeiten der
  Schritte), `testWhatTheSameRunCreatesFirstIsPendingAndANavigationAsDraftLeavesTheFallback`
  (die Seite wartet auf ihre Vorlage, die Vorlage auf ihren Teil).

### Rechte und Feldregeln

- Die Rechte der Typen sind ihre registrierten Capabilities
  (`get_post_type_object()->cap`, `wp-includes/post.php:302-583`), vorwärts in
  `precheck()` und auf dem Rückweg in `restore_right()` (`edit_post` zuerst):
  Vorlagen, Vorlagenteile und Navigationen verlangen für alles
  `edit_theme_options`; die globalen Stile `edit_theme_options`, zum
  Veröffentlichen aber `publish_posts` (der Kern nennt für den Typ kein
  eigenes, :496-527); wiederverwendbare Blöcke `edit_posts`, zum Anlegen
  `publish_posts` (:335-349). Die Terme von `wp_theme` und
  `wp_template_part_area` fragen die Rechte ihrer Taxonomie (der Kern
  registriert keine eigenen: `assign_terms` = `edit_posts`, `edit_terms` =
  `manage_categories`), die von `wp_pattern_category` ebenso; `assign_term`
  je Term wie bisher (`term_right()`).
- Feldregel `wp_block`: Die Meta `wp_pattern_sync_status` (ungesynchtes
  Muster) registriert der Kern ohne `auth_callback`
  (`wp_create_initial_post_meta()`, `wp-includes/post.php:8853-8869`);
  `register_meta()` hängt dann `__return_true` ein (`wp-includes/meta.php:1511-1531`),
  und `map_meta_cap` gibt das Recht am Post selbst. Ein solcher Schlüssel
  gilt jetzt für einen neuen Post als einer ohne eigene Regel
  (`auth_follows_post()`): vorher sperrte `has_filter()` jedes neue
  ungesynchte Muster (`post:create_with_object_meta`). Ein anderer Callback
  neben `__return_true`, oder ein Hook, den die Anfrage nicht so zeigt, wie
  WordPress ihn hält, sperrt weiter (fail closed). Das gilt für jeden
  Schlüssel, den `register_meta()` ohne `auth_callback` registriert.
  Prüffälle `testTheRuleOfThisVersionForMetaKeys` (Unit),
  `testANavigationAndAReusableBlockGoOverAndBackAndAPageNamesBothByTheirIds`.
- Grenze C: Die geschützte Meta `_wp_ignored_hooked_blocks` (Block Hooks
  eines Plugins, `wp-includes/blocks.php:1455-1497`) bleibt unberührt, wo sie
  auf beiden Seiten gleich ist; weicht sie ab, sperrt `*:meta_without_rule`
  (Z8d) wie jeder geschützte Schlüssel ohne Regel.
- Globale Stile, Sanitizer vorab: Der Inhalt läuft wie jeder Post durch
  `sanitize_post( …, 'db' )`; ohne `unfiltered_html` gehört dazu
  `wp_filter_global_styles_post()` (`wp-includes/kses.php:2464-2487`), das
  Custom-CSS nur mit `edit_css` behält (`WP_Theme_JSON::remove_insecure_properties()`,
  `wp-includes/class-wp-theme-json.php:4701-4703`); eine Änderung sperrt
  `post:sanitizer` wie bisher. Zusätzlich fragt der Schritt die Regel, mit
  der der Site-Editor Custom-CSS ablehnt
  (`WP_REST_Global_Styles_Controller::validate_custom_css()`,
  `wp-includes/rest-api/endpoints/class-wp-rest-global-styles-controller.php:683-760`:
  kein `</style` gefolgt von Leerzeichen, `/` oder `>`, kein Ende in einem
  Teil davon, CSS ist Text), für jeden Schlüssel `css` des JSON, nicht nur
  den obersten wie der Kern (jeder wird in ein STYLE-Element gedruckt):
  `post:css_markup` (Z8). Der Schreiber geht über `wp_update_post()` an der
  REST-Prüfung vorbei, darum fragt er sie vorab (`css_markup()`, Schritt für
  Schritt wie der Kern; die Integration hält beide gleich,
  `testTheRuleOfTheCustomCssIsTheOneOfTheSiteEditor`). Jeder Wert wird
  gefragt, sobald der Durchlauf ihn findet (`css_walk()`); der Pfad dient nur
  der Meldung (GitHub-Codex 1 auf free#139, P2: gesammelt nach dem Pfad
  behielt ein Schlüssel mit Punkt, `blocks.core/button` neben `blocks` →
  `core/button`, nur den letzten Wert, und ein `</style` im ersten blieb
  ungefragt, vorwärts wie auf dem Rückweg; Prüffälle
  `testCustomCssUnderAKeyWithADotIsLockedBeforeAnythingIsWritten`,
  `testCustomCssUnderAKeyWithADotIsAskedForwardAndOnTheWayBack`, Unit
  `testTheCustomCssOfTheGlobalStylesKeepsTheRuleOfTheSiteEditorAtAnyDepth`).
  Tiefer als gelesen sperrt (fail closed); ebenso ein Inhalt, der nicht leer
  ist und sich nicht zu einem Array dekodieren lässt (kein JSON, tiefer als
  `json_decode()` liest, ein Skalar): der Kern zeigt davon nichts, meldet bei
  JSON, das er nicht dekodiert, einen Hinweis
  (`WP_Theme_JSON_Resolver::get_user_data()`,
  `wp-includes/class-wp-theme-json-resolver.php:552-589`), und der
  Site-Editor speichert die Stile nur als JSON; die Prüfung des CSS lässt
  sich nicht machen, darum `post:css_markup` mit dem Weg (die Stile auf
  Staging neu speichern). Leerer Inhalt und der Standard des Kerns
  (`{"version": 3, "isGlobalStylesUserThemeJSON": true }`) gehen. Ein
  eigener Schlüssel brächte keinen anderen Weg; `*:unreadable_value` (Z8d)
  wartet auf eine Feldregel, hier fehlt keine, der Inhalt ist kaputt. Die übrigen Sammlungen von
  Zweig 9b haben keinen Pfad als Schlüssel (Namen von Teilen und Vorlagen
  sind je Identität eindeutig, Staging- und Live-IDs Zahlen). Auf dem Rückweg fragt
  `restore_row()` dieselbe Regel am gesicherten Inhalt, bevor es die Zeile
  zurückschreibt: hält er CSS, das der Site-Editor ablehnte, bleibt die Zeile
  `left` mit Grund und altem Wert, wie ein Teil, dessen Recht der Rückweg
  verweigert (Codex-Runde 1 von Zweig 9b, A: die Live-Site hielt
  `</style>` im CSS eines Blocks, das der Kern nur oben prüft; der Rückweg
  schrieb es über `wp_update_post()` ungeprüft zurück, mit
  `unfiltered_html` ohne kses; Prüffall
  `testTheWayBackOfTheGlobalStylesAsksTheRuleOfTheCustomCssAsForward`). Ohne
  `unfiltered_html` greift kses dort selbst; ein so geänderter Inhalt liest
  anders zurück und bleibt `unclear` (die bestehende Abgrenzung aus
  Codex-Runde 5 von Zweig 9).
- Rückweg und Vorwärtsprüfungen der fünf Typen (Codex-Runde 1 von Zweig 9b,
  A, dieselbe Lehre wie GitHub-Codex 1 auf free#138): die Capabilities des
  Typs fragt `restore_right()` (`edit_post`, `publish_posts` bei einem
  anderen Status, `edit_others_posts`, über `get_post_type_object()`), die
  Rechte der Terme `term_right()`, das Builder-Markup `unfiltered_html`, die
  Fallback-Navigation `restore_row()` (unten), das CSS `restore_row()`;
  kses läuft in `wp_update_post()` selbst. Nicht gefragt wird das Theme, mit
  Grund am Code (`restore_row()`): die Zeile geht an denselben Post zurück,
  dessen Term von `wp_theme` der Schritt nicht änderte (das Paar ist eines
  Typs, Themes und Slugs); was die Vorwärtsprüfung hält (das Objekt zeigt
  sich unter dem aktiven Theme, eine neue Vorlage wird unter dessen
  Vorlagen eindeutig), hat für einen vorhandenen Post kein Gegenstück, und
  `wp_filter_wp_template_unique_post_slug()` fragt für ihn seinen eigenen
  Term (`wp-includes/theme-templates.php:64-67`). Die Namen der Terme, die
  Vorlagenteile eines Inhalts und der Slug fragt der Rückweg nicht neu: er
  stellt den gesicherten Stand dieser Site selbst her, nicht den einer
  anderen.

### Kern-Folgen und Caches (Entscheid 21, 33)

- Navigation ohne eigenes Menü: Ein Navigationsblock ohne `ref` (und ohne
  `navigationMenuId`) und ohne eigene Einträge zeigt die zuletzt
  veröffentlichte Navigation der Site
  (`WP_Navigation_Fallback::get_most_recently_published_navigation()`,
  `wp-includes/class-wp-navigation-fallback.php:110-130`, Status `publish`,
  nach Datum absteigend; `wp-includes/blocks/navigation.php:517-547`); gibt
  es keine, legt der Kern beim Anzeigen eine an (`get_fallback()`, :70-101).
  Twenty Twenty-Five hat so einen Block im Kopf (Muster
  `twentytwentyfive/header`). `fallback_moves()` vergleicht aus den Zeilen,
  welche Navigation vor und nach dem Schreiben so angezeigt würde (ein
  Gleichstand des neuesten Datums gilt als Verschiebung: die Reihenfolge des
  Kerns ist dann nicht bestimmt). Gesperrt wird nur, wo die Verschiebung zu
  sehen ist (`fallback_refusal()`, `fallback_shown()`; Codex-Runde 1 von
  Zweig 9b, B: vorher sperrte jede neue, später datierte Navigation, auch
  eine, die alle Blöcke über `ref` einbinden): nach dem Lauf zeigt eine
  Vorlage des aktiven Themes (`get_block_templates()`: Zeilen hier über den
  Dateien des Themes, Vorlagen von Plugins; oder die Fassung, die der Lauf
  schreibt) oder ein veröffentlichter, privater oder geplanter Post eines
  Typs mit eigener Seite oder ein veröffentlichter wiederverwendbarer Block
  (hier oder in der Fassung des Laufs) einen solchen Block, selbst oder über
  die Vorlagenteile (Zeile hier, Datei des Themes, Fassung des Laufs) und
  Muster (die Registry der Muster), die er nennt. Dann `post:navigation_fallback`
  (Z8), vorwärts in `precheck()`, auf dem Rückweg in `restore_row()` als
  «Prüfung nötig» (`check_needed`) mit dem Weg; sonst geht die Navigation.
  Die Posts liest eine begrenzte Abfrage (Inhalt mit `wp:navigation`,
  `wp:pattern`, `wp:block` oder `wp:template-part`, höchstens
  `FALLBACK_POSTS` = 200; mehr ist fail closed); Teile und Muster folgen bis
  `FALLBACK_DEPTH` = 16 Ebenen und `FALLBACK_STEPS` = 2000 Inhalte, mehr ist
  fail closed. Eine geänderte Navigation mit ihrem Datum (der Normalfall)
  behält ihren Platz und geht.
- Was nach dem Lauf zu sehen ist, rechnet eine Regel an einer Stelle
  (`AB_MCP_Sync_Engine::planned()`; Codex-Runde 2 von Zweig 9b, 1 und 3):
  je Objekt des Laufs die geplante Fassung, wo sein Schritt noch zu
  schreiben ist (`planned`), und der frisch gelesene Stand dieser Site, wo
  sein Schritt begann oder endete (`done`, `failed`, `skipped`, `unclear`
  …): was er schrieb oder nicht schrieb, steht dann hier. In der
  Gesamtvorprüfung ist kein Schritt geendet, dort gelten alle geplanten
  Fassungen. Jedes Objekt, das eines dieser Site ersetzt, zählt mit seiner
  geplanten Fassung, auch wo sie keinen Navigationsblock mehr hat (eine
  Seite, die ihren letzten Block ohne Menü entfernt, verdeckt nicht mehr die
  Fassung von hier). Die Grenze der Navigation liest die Zustände der
  Schritte (`AB_MCP_Sync_Run::steps()`, nicht lesbar: der Schritt scheitert).
  Eine Navigation kommt im Plan zuletzt, wo keine Kante sie früher verlangt
  (`AB_MCP_Sync_Push::LAST`, Gleichstand-Regel): die Schritte, die sie nicht
  brauchen, sind bei ihrer Grenze geendet, und sie liest, was diese
  hinterliessen. Prüffälle
  `testANewNavigationAsksWhatTheStepsBeforeItLeftAndAPartThatFailedKeepsItsBlockWithoutAMenu`
  (der Kopf scheitert, die neue Navigation wird nicht geschrieben),
  `testAPageThatDropsItsLastNavigationBlockLetsANewNavigationGo`.
  Grenze C: Ein Objekt, dessen geplante Fassung die Verschiebung verdeckt
  und das die Navigation über ihren `ref` nennt, kommt nach ihr (die Kante
  verlangt es) und kann danach noch scheitern; dann ist die Verschiebung zu
  sehen, ohne dass ein Schritt sie geschrieben hätte, der davon wusste.
  Sein Schritt endet mit Befund, der Lauf `closed_findings`; Weg: den
  Schritt wiederholen (neuer Bericht und Plan) oder dem Block sein Menü von
  Hand geben. Ohne diese Reihenfolge ginge der Normalfall (neue Navigation,
  die der Kopf des Laufs nennt) nicht.
- Prüffälle des Sichtbaren
  `testANewNavigationEveryBlockNamesByItsRefGoesAndOneABlockWithoutAMenuWouldShowIsLocked`
  (die neue Navigation, die der Kopf des Laufs über `ref` nennt, geht hin
  und zurück; ein Block ohne Menü in einer Seite des Laufs, einer Vorlage
  des Laufs oder einer Seite hier, auch in einer Gruppe, sperrt),
  `testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten` (der Kopf des
  Themes zeigt einen solchen Block),
  `testWhatTheSameRunCreatesFirstIsPendingAndANavigationAsDraftLeavesTheFallback`,
  `testTheWayBackOfANavigationThatWouldMoveTheFallbackIsLeftForAPerson`.
  Grenze C: ein Block, dessen `ref` eine Navigation nennt, die hier nicht
  veröffentlicht ist (fehlt, Entwurf, leer), zeigt ebenfalls die
  Fallback-Navigation; er zählt nicht als Block ohne Menü (Staging zeigt
  dieselbe Verschiebung, und ein solcher Verweis ist ein Fehler der Seite).
  Grenze C: die Abfrage liest Vorlagen und Posts, nicht Widgets, Menüs
  klassischer Themes oder Inhalte, die ein Plugin ausserhalb von Posts hält;
  ein unbenutzter wiederverwendbarer Block mit einem Block ohne Menü sperrt
  (fail closed). Weg: dem Block sein Menü geben (im Site-Editor, auch auf
  Staging), oder die Navigation im Site-Editor der Live-Site anlegen oder
  veröffentlichen.
- Caches in Phase 7 (`post_write()`): Für die fünf Typen leert der Schritt
  den Seiten-Cache der ganzen Site (`AB_MCP_Page_Cache::purge_site()`,
  `SITE_WIDE`): jede Seite kann sie zeigen, eigene Seiten haben sie nicht.
  Für die globalen Stile zusätzlich `wp_clean_theme_json_cache()`
  (`wp-includes/global-styles-and-settings.php:430-438`: die Gruppe
  `theme_json` und die Daten von `WP_Theme_JSON_Resolver`); diese Gruppe
  hält WordPress nur je Anfrage (`wp-includes/load.php:928`). Einen
  Transienten der globalen Stile (etwa `_wp_theme_json`) kennt WordPress
  7.1.3 nicht; die Abfragen der Vorlagen, Teile und Navigationen folgen dem
  letzten Wechsel der Posts, den `clean_post_cache()` setzt; die Terme von
  `wp_theme`, `wp_template_part_area` und `wp_pattern_category` leert
  `run_post()` mit den übrigen Taxonomien. Der Transient der Muster des
  Themes (`wp_theme_files_patterns-*`, `wp-includes/class-wp-theme.php:2017`)
  betrifft Dateien und bleibt unberührt.
- Stichprobe in Phase 8: nur ein veröffentlichtes Objekt eines Typs mit
  eigener Seite (`is_post_type_viewable()`, `AB_MCP_Sync_Engine::viewable()`);
  ein Lauf nur mit Vorlagen, Teilen, Stilen, Navigationen oder Blöcken hat
  keine Stichprobe (kein Befund), wie ein Lauf nur mit Termen. Grenze: ob
  eine Seite die neue Vorlage zeigt, prüft der Lauf nicht über HTTP; die
  Hashes der geschriebenen Objekte prüft er wie immer.

### Die übrigen Sperren der Post-Art

- Die übrigen Lagen, die bis 08.11.2026 unter `post:engine_type` fielen,
  haben je einen eigenen Schlüssel; keine ist dabei freigegeben (Prüffälle
  `testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock`,
  `testATypeTheTableLocksAndWhatTheCanonicalFormLocksAreRefusedWithTheirOwnLock`,
  je Eintrag `SyncKindsTest::testEachLockedOperationNamesItsStage`):
  - `post:in_trash` (Z8d): ein Staging-Objekt im Papierkorb ist dort eine
    Löschung; kein Status steht für eine Löschung, die Löschungen kommen mit
    ihrem Rückweg in Z8d (wie `*:delete`). Der Bericht zeigt es als gelöscht.
  - `post:auto_draft` (Z8, nie): ein Auto-Entwurf ist der leere Post, den
    WordPress beim Öffnen des Editors anlegt und nach sieben Tagen löscht
    (`wp_delete_auto_drafts()`); kein Inhalt einer Person, vom Bericht nie
    verglichen, von keiner Etappe übernommen. Das Feld kennt kein «nie»: Z8
    mit dem Grund «never taken over, by no stage», wie die Regeln von Z8, die
    eine Person auflöst.
  - `post:status_rule` (Z8d): ein anderer Status als `publish`, `future`,
    `draft`, `pending`, `private` (ein Status eines Plugins) wartet auf
    seine Einordnung, wie eine Taxonomie eines Plugins.
  - `post:content_missing` (Z8): ein Paket ohne die Zeilen des Objekts (eine
    Zeile war auf Staging nicht lesbar) sagt nicht, was zu schreiben ist;
    der Weg ist ein neues Paket, keine Etappe.
  - `post:lock_unread` (Z8): eine Sperre der kanonischen Form, die diese
    Version nicht liest (auf Staging oder hier), fail closed; der Weg ist
    dieselbe Version auf beiden Sites.
  - `post:type_unregistered` (Z8): ein Typ, den diese Site nicht
    registriert; seine Rechte lassen sich nicht fragen. Der Weg: Plugin oder
    Theme hier aktivieren.
  - `post:other_kind` (Z8): ein Typ einer anderen Art (Anhang, Menüeintrag,
    zusätzliches CSS) schreibt nie die Engine der Post-Art, sondern die
    seiner Art (Zweige 10 und 11).

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
- Umhängen und Tauschen (Codex-Runden 1 und 2, B4): Die Gesamtvorprüfung
  fragt eine Schleife der Eltern am Stand, den der Lauf hinterlässt: die
  Terme, die er schreibt, mit den Eltern, die er ihnen gibt, jeder andere
  Term wie hier (`AB_MCP_Sync_Engine::planned_parents()`). Zwei Kategorien,
  die die Plätze tauschen (hier Beta unter Alpha, auf Staging Alpha unter
  Beta), schliessen so keine Schleife; vorher sperrte der Abgleich mit dem
  Stand hier den ganzen Lauf. Der Plan ordnet die Schritte der Terme nach
  der Tiefe ihres Terms in diesem Zielbaum (`AB_MCP_Sync_Push::
  term_depths()`, `order()`): Vorfahren zuerst, bei gleicher Tiefe nach dem
  Bericht, die Terme vor den übrigen Objekten. So stehen beim Schreiben
  eines Terms alle seine Ziel-Vorfahren schon an ihrem Zielort, und da der
  Zielbaum keine Schleife hat, ist sein neuer Elternteil nie sein Nachfahre,
  auch über Terme ohne Schritt hinweg (Kette hier Alpha → Beta → Gamma,
  Soll Beta → Gamma → Alpha: Beta vor Alpha, obwohl der direkte Elternteil
  Gamma keinen Schritt hat; Codex-Runde 2). Die Reihenfolge ist keine
  Abhängigkeit: scheitert der Schritt eines Elternteils, läuft der Schritt
  des Kinds trotzdem (vorher übersprang ihn eine Kante zum Elternteil, auch
  wo es nur seine Beschreibung änderte; Codex-Runde 2). Jeder Schritt fragt
  beim Schreiben seine Schleife am Stand, wie ihn `wp_update_term()` dann
  sieht (`term:parent`, fail closed). Der Rückweg geht vom letzten Schritt
  zum ersten (`AB_MCP_Sync_Revert::plan()`), so dass auch dort kein
  Zwischenstand eine Schleife schliesst. Prüffälle
  `testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell`
  (Tausch hin und zurück, gewöhnliches Umhängen von Beta nach Gamma),
  `testAChainOfCategoriesTurnedOverGoesOverAndBack`,
  `testAChildWhoseParentStepFailsGoesAllTheSame`,
  `testTheLoopOfParentsIsAskedOfTheStateTheRunLeaves`,
  `testTheTermsOfARunAreWrittenFromTheRootOfTheTreeItLeavesDown`.
  Die Reihenfolge ruht auf mehr als den gewählten Objekten (die Tiefe eines
  Terms liest die Eltern seiner Vorfahren hier): der Zustand, an den der
  Token der Vorschau gebunden ist, deckt darum die Folge der Schritte ab
  (Art und Staging-Ref je Schritt). Hängt eine andere Hand vor der
  Bestätigung einen nicht gewählten Vorfahren um und kehrt sich die
  Reihenfolge, ist der Token veraltet, mit derselben Ablehnung
  (`ab_mcp_confirm_mismatch`) und demselben Weg wie bei jeder anderen
  Änderung des Stands: eine neue Vorschau (Codex-Runde 3; Prüffall
  `testATokenWhoseOrderOfStepsNoLongerHoldsIsStale`).
- Grenze C (konstruiert): `term_depths()` kappt die Tiefe bei
  `AB_MCP_Sync_Push::MAX_OBJECTS` (1996; durch `<=` erreicht sie 1997, nicht
  `AB_MCP_Sync_Manifest::MAX_OBJECTS`). In einem Baum, der tiefer ist
  (hier 1 → … → 1997 → A → B, Soll … → 1997 → B → A), sind beide Tiefen
  gleich, A geht nach dem Bericht zuerst, scheitert an `term:parent` und
  bleibt stehen; B geht. Fail closed. Grund: die Obergrenze hält die
  Rechnung endlich, auch bei einer Schleife der Eltern, die die
  Gesamtvorprüfung sperrt. Weg: ein zweiter Lauf hängt A um, weil B dann
  an seinem Platz steht.
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
- Zweig 9b (Liste `vorlagen`, `tests/mutation/gen/vorlagen.py`; eine eigene
  Liste, weil ihre Klauseln die von Zweig 9b sind und fast alle von
  `SyncTemplatesTest` gehalten werden): nicht geführt sind die Texte der
  Meldungen (der Name «keine Navigation», «zwei Navigationen desselben
  Datums» in `fallback_moves()`: dieselben Zeilen vor und nach dem Schreiben,
  so dass der Name das Urteil nicht ändert; ein Objekt ohne Term von
  `wp_theme`, das die Prüfung des aktiven Themes ohnehin sperrt), die
  Fehlerwege einer nicht lesbaren Zeile (`fallback_moves()`, `part_row()`,
  `pending_named()` bei einem fehlenden Paket), ein Objekt hier mit zwei
  Themes (`theme_refusal()` am Objekt hier: ein Paar dieser Identität lässt
  sich auf den Test-Sites nur mit Zeilen am Kern vorbei stellen) und die
  Art in `AB_MCP_Sync_Engine::viewable()` (ein Term hat keinen Status für
  die Stichprobe).
