# Grenzen der Struktur (Z8, Zweig «Struktur»)

Gesammelte Gruppe-C-Grenzen von Zweig 11 (`heikel/z8-struktur`): die Arten
`menu` und `menu_item`, `widget_area`, `custom_css` und `option`
(Entscheide 23, 24, 25 und 27; `AB_MCP_Sync_Menu_Writer`,
`AB_MCP_Sync_Menu_Item_Writer`, `AB_MCP_Sync_Widget_Area_Writer`,
`AB_MCP_Sync_Css_Writer`, `AB_MCP_Sync_Option_Writer`,
`AB_MCP_Sync_Shared_Row`). Die Grenzen des Laufs (`GRENZEN-LAUF.md`:
Sperre, Unterbrechung, Journal, Abweichung im Schritt, Rückweg) und der
Arten Post und Term (`GRENZEN-INHALTE.md`) gelten unverändert; ein Menü
ist ein Term von `nav_menu`, ein Menüeintrag ein Post von
`nav_menu_item`. Je Grenze ein Satz mit Beispiel und Grund; wo eine Sperre
entsteht, nennt ihre Meldung Schlüssel, Etappe und Weg.

## Normalfall (hin und zurück)

- Ein geänderter Menüeintrag (Titel, Ziel, Reihenfolge) und ein neuer
  Eintrag im selben Menü: der neue Eintrag nach seinem Menü, die geänderten
  über `wp_update_nav_menu_item()` mit ihren Daten von hier; der Rückweg
  setzt die geänderten bytegenau zurück, der neue bleibt als Rest. Prüffall
  `SyncStructureTest::testAChangedMenuItemAndANewItemGoOverAndTheWayBackPutsTheChangedOnesBackAndLeavesTheNewOneAsARest`.
- Ein neues Menü an einer Position des Themes (auf beiden Sites hält die
  Position vorher ein anderes Menü): erst das Menü, dann sein Eintrag, die
  Position über `set_theme_mod( 'nav_menu_locations' )`; der Rückweg gibt
  die Position dem alten Menü zurück und lässt Menü und Eintrag als Rest.
  Prüffall
  `testANewMenuAtAPositionOfTheThemeGoesOverAndTheWayBackPutsThePositionBackAndLeavesTheMenuAsARest`.
- Ein neues Menü an einer registrierten Position, die noch kein Menü hält:
  das Feld fehlt vor und nach dem Term-Aufruf (`with_row()` nennt es so,
  Codex-Runde 1, B1), dann setzt der Schritt es; der Rückweg leert die
  Position wieder. Prüffall
  `testANewMenuAtAPositionNoMenuHoldsYetGoesOverAndTheWayBackLeavesThePositionEmptyAgain`.
- Ein Menü übernimmt die Position eines anderen Menüs desselben Laufs, das
  sie abgibt: das abgebende kommt zuerst, dann das nehmende, beide fertig.
  Prüffall
  `testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked`.
- Ein geändertes Text- und ein geändertes Bild-Widget eines Bereichs, den
  beide Sites haben: jede Instanz durch das `update()` ihres Widgets (wie
  der Widget-Bildschirm), die Reihenfolge über `wp_set_sidebars_widgets()`;
  der Rückweg setzt beide zurück. Prüffall
  `testAChangedTextWidgetAndImageWidgetGoOverAndBack`.
- Eine neue Instanz in einem Bereich: sie geht hinüber und bleibt auf dem
  Rückweg stehen, der Bereich mit ihr als Rest (`residual`) mit der
  Zuordnung der Instanzen; ein späterer Lauf legt sie kein zweites Mal an.
  Prüffall
  `testANewInstanceMakesTheWholeAreaARestWithItsMappingAndNoLaterRunMakesItTwice`.
- Geändertes Zusätzliches CSS des aktiven Themes: über
  `wp_update_custom_css_post()`, die gerenderte Seite zeigt es; der Rückweg
  setzt den Inhalt zurück. Prüffall
  `testChangedAdditionalCssGoesOverAndBackAndThePageShowsIt`.
- Geänderte Kern-Optionen (`blogname`, `blogdescription`,
  `posts_per_page`, `permalink_structure`): über `update_option()`, die
  Permalinks mit neu gebauten Rewrite-Regeln; der Rückweg setzt jede
  bytegenau zurück, eine seit dem Lauf von Hand geänderte bleibt stehen
  (Entscheid A). Prüffall
  `testChangedCoreOptionsGoOverAndBackAndThePermalinksBuildTheirRulesAnew`.

Die seltenen Fälle sperren über den ganzen Weg (Bericht, Plan, Prüfung
des Laufs), bevor ein Schritt schreibt: eine Option eines Plugins schon im
Bericht, CSS mit schliessendem STYLE-Tag und ein neues Menü an einer
Position, die das Theme hier nicht registriert, in der Prüfung des Laufs
(`closed_aborted`, nichts geschrieben). Prüffall
`testWhatARunCannotWriteAsTheCoreWouldIsLockedWithItsKeyAndNothingOfItIsWritten`.

## Geteilte Zeilen (Entscheid 24)

- Eine Position eines Menüs ist ein Feld der Zeile `theme_mods_<stylesheet>`
  (`location:<name>`, mit dem Wert `self`, wo die Position das Menü selbst
  nennt), der Rest der Zeile ein Teil `mods:rest`, der verglichen, nie
  geschrieben wird. Ein Bereich ist das Feld seines Schlüssels in
  `sidebars_widgets`, jede Instanz das Feld ihrer Nummer in
  `widget_<base>`. Ein Feld trägt die Bytes von `serialize()`, ein
  fehlendes ist von einem leeren verschieden. Nach jedem eigenen Aufruf
  und jedem Zurücksetzen wird der ganze gelesene Stand verglichen: ändert
  ein Haken dabei ein anderes Feld derselben Zeile, weicht der Schritt ab
  (Entscheid A).
- Zurück geht eine geteilte Zeile roh und bytebedingt: geschrieben nur, wo
  sie genau die gelesenen Bytes hält (`CAST … AS BINARY` und
  `OCTET_LENGTH`, auch in Gross- und Kleinschreibung und Länge), danach
  vergessen die Caches der Option sie (`alloptions`, `notoptions`, die
  Option). Ändert ein anderer Prozess zwischen Lesen und Schreiben ein
  anderes Feld derselben Zeile (eine andere Position, ein anderes Widget),
  bleibt das Feld stehen (`left`), mit seinem Wert davor im Journal; der
  Rest wird von Hand gesetzt. Grund: die Zeile ist nur als Ganzes bedingt
  schreibbar. Prüffall
  `testAFieldOfASharedRowGoesBackOnlyWhereItsBytesAreStillThoseReadAndItsCachesForgetIt`.
- `wp_get_custom_css_post()` schreibt beim ersten Seitenaufruf einer Site
  ohne CSS-Post `custom_css_post_id = -1` in die Theme-Mods (ein Cache des
  Kerns). Fällt ein solcher Aufruf zwischen Lesen und Schreiben eines
  Menüschritts, gilt er als fremde Änderung der Zeile: der Schritt ist
  unklar, eine Person prüft ihn. Das geschieht je Site und Theme höchstens
  einmal (danach steht -1 oder eine Post-ID); der Prüffall des neuen Menüs
  vergleicht die Theme-Mods darum ohne diesen Schlüssel.
- Vorwärts schreibt der Schritt über die Kernfunktionen
  (`set_theme_mod()`, `wp_update_custom_css_post()`, `update_option()`,
  `wp_set_sidebars_widgets()`), die die Zeile noch einmal über den Cache
  der Optionen lesen. Unmittelbar davor leert der Schritt die Caches dieser
  Zeile (`forget()`), so liest der Kern dieselbe Zeile, die der Schritt
  eben aus der Datenbank gelesen und verglichen hat (Codex-Runde 1, A1).
  Die Änderungssperre leert nur die mit allen geladenen Optionen; eine
  Zeile mit `autoload` off (WordPress setzt das für grosse Zeilen) hatte
  ihren Cache behalten, und daraus setzte `set_theme_mod()` fremde Felder
  zurück, legte `wp_update_custom_css_post()` bei veralteter Verknüpfung
  einen zweiten CSS-Post an oder schrieb `update_option()` nichts. Prüffall
  `testAStepWritesFromTheRowsAsTheyAreAndNeverFromAStaleCacheOfThem`.
- Ein Prozess, der zwischen diesem Lesen und dem Kernaufruf dieselbe Zeile
  ändert, hält die Übernahmesperre an, wo sie die Zeile deckt (sein Wert
  kommt nicht an); fällt sein Schreiben trotzdem dazwischen (ein Weg, den
  die Sperre nicht abweist, etwa SQL), liest die Engine es nach dem Aufruf
  als fremde Änderung.
- Auch das Zurücksetzen der Positionen eines neuen Menüs (`shared_back()`)
  läuft unter der Übernahmesperre über die Theme-Mods: ein anderer Prozess,
  der sie in diesem Moment schreibt, wird abgewiesen, und die Position geht
  zurück (ohne die Sperre stünde sein Wert und die Position bliebe `left`;
  Codex-Runde 1, C3). Prüffall
  `testAnotherRequestThatWritesTheThemeModsAsANewMenuGivesItsPositionBackIsHeldOffByTheLock`.

## Menüs und Positionen

- Das Menü wird geschrieben wie die Art Term (`wp_insert_term()`,
  `wp_update_term()` auf `nav_menu`), nicht über
  `wp_update_nav_menu_object()`: die Aktionen `wp_create_nav_menu` und
  `wp_update_nav_menu` feuern nicht. Ein Plugin, das an ihnen hängt, sieht
  den Lauf nicht. Grund: die Termwege tragen schon die Sperren, die
  Rechte und den Vergleich der Art Term (Zweig 9).
- Eine Position wird nur gesetzt, wo das aktive Theme dieser Site sie
  registriert (`menu:location_unregistered`, Z8), vorab und noch einmal
  unmittelbar vor dem Setzen; eine Position, die das Menü hier hält und das
  Ziel nicht nennt, wird entfernt (wie der Menü-Bildschirm ein abgewähltes
  Kästchen). Beispiel: Staging hat ein klassisches Theme mit `primary`,
  Live ein Block-Theme ohne Positionen.
- Ein Menü, dessen Zeile eine andere Taxonomie als `nav_menu` nennt, eines
  mit `term_group` oder Elternteil und eines mit einer Position, die kein
  Name ist, sperren
  (`menu:item_shape`, `term:term_group`, `term:parent`); ein neues Menü mit
  Meta sperrt wie ein neuer Term (`term:create_with_meta`).
- Ob ein Menü neue Seiten der obersten Ebene selbst aufnimmt
  (`nav_menu_options`, `auto_add`), geht nicht mit dem Menü: eine Zeile für
  alle Menüs, gesperrt (`option:nav_menu_options`, Z8d; Entscheid 21 liest
  sie nur).
- Positionen zwischen Menüs eines Laufs (Codex-Runde 1, B2): die
  Positionen sind Felder einer Zeile, die Form jedes Menüs nennt sie, und
  jeder Schritt vergleicht sein Menü mit dem Plan. Nimmt ein Menü eine
  Position, die ein anderes Menü des Laufs hier hält, kommt es nach diesem
  (eine Ordnung, keine Abhängigkeit): das andere gibt die Position zuerst
  ab. Nehmen Menüs einander die Positionen (ein Tausch, etwa primary und
  footer vertauscht), geht das in keiner Reihenfolge durch: der Plan sperrt
  jedes davon vor jedem Schreiben (`menu:position_exchange`, Z8d; Weg: eines
  davon in einem eigenen Lauf zuerst, die anderen aus einem neuen Bericht
  danach, oder von Hand unter Design → Menüs → Positionen verwalten).
  Zwischen den zwei Läufen ist eine Position kurz leer. Ihn in einem Lauf
  durchzulassen bräuchte eine Ausnahme im Vergleich der Grenze, die die
  Wirkungen früherer eigener Schritte auf geteilte Felder kennt.
- Die Namen der Positionen eines Menüs (Schlüssel der Theme-Mods) gehen
  durch die Zugangsprüfung des Exports, ebenso Name und Slug der Menüs im
  Inhalt eines Eintrags; ein Fund hält das Objekt, sein Inhalt geht nicht
  ins Paket (Codex-Runde 1, A4). Prüffall
  `testACredentialInTheNameOfAMenuOrOfItsPositionLandsInNoPackage`.
- Darüber hinaus prüft der Export den fertigen Inhalt jedes Objekts noch
  einmal, nachdem jede Art alles angehängt hat
  (`AB_MCP_Sync_Export::checked_content()`, Codex-Runde 2, A8): jede Spalte
  der Zeile wie die Arten eine Spalte prüfen (der Inhalt des CSS als CSS),
  jede Meta-Zeile, Name und Slug der Terme, jeder andere Teil als Wert. So
  kommt kein nachträglich angehängtes Feld an der Prüfung vorbei, etwa die
  Version des Themes im Inhalt des CSS. Jede Einheit geht dabei durch
  dieselbe Prüfung wie bei den Arten (`check_unit()`, Codex-Runde 3, A8):
  die Zugangsprüfung, dann das Netz über den rohen Wert
  (`AB_MCP_Sync_Net::check()`, dieselbe Funktion, mit jeder Schicht des
  Werts: Dekodierungen, Base64, `data:`), so dass auch eine kodierte
  Version (Base64 von `password=…`) das Objekt hält; was das Netz nicht
  bis zum Ende liest, sperrt und macht das Objekt unvollständig.
  Jeder Teil geht in der Form durch die Prüfung, in der seine Art ihn
  geprüft hat (Codex-Runde 4, B; die Meta-Zeilen über
  `AB_MCP_Sync_Db_Kind::checked_meta()` der Art): Post, Anhang und Vorlage
  (JSON im Inhalt), Term, Menü und Menüeintrag tragen Spalten und
  Meta-Zeilen als gespeicherte Texte, ein serialisierter Wert als sein
  Text, so wie `meta_form()` und `row_form()` ihn lesen; die Option ihren
  gespeicherten Wert als Text, wie `option()` ihn liest; das CSS seine
  Spalten als Texte; Theme-Mods tragen keinen Inhalt. Nur der Bereich
  trägt seine Instanzen anders, als er sie prüft: im Inhalt serialisiert,
  geprüft entpackt mit der Basis des Widgets als Namen (`area()`).
  `checked_meta()` des Bereichs entpackt sie darum wie die Art
  (`AB_MCP_Sync_Canon::unserialized()`); was nicht entpackt, geht als Text
  in die Prüfung und sperrt dort. Vorher las das Netz die Klammern der
  serialisierten Einstellungen als Code und die Prosa eines Text-Widgets
  («Spieler: Anna, Token: drei») als Zugangsfeld: die Zeile war gesperrt.
  Prüffall
  `testAHarmlessTextWidgetAndSerializedValuesOfMetaAndOptionsGoIntoThePackageWithTheirContent`
  (mit je einem serialisierten Normalwert für Meta und Option). Nur das
  Zählen der Verweise läuft dort nicht (`$references = false`): kein Leser
  von Adressen läuft über den fertigen Inhalt, die Arten haben die Verweise
  derselben Werte mit ihren Lesern gezählt; eine Adresse mit Id sperrt dort
  also nicht noch einmal. Das Netz läuft damit über jeden Inhalt zweimal
  (in der Art und im Export); das kostet Zeit beim Export, nicht beim
  Schreiben. Eine Meta-Zeile mit dem Namen eines Zugangsdatums (B9), die
  erst danach in den Inhalt käme, fällt dort heraus und wird in `b9`
  genannt (Codex-Runde 3, C), so wie die Arten einen solchen Schlüssel aus
  Form und Inhalt lassen (GRENZEN-INHALTE: nie Teil, nie gepackt). Gewählt
  ist das Entfernen statt des Sperrens, weil die Arten an dieser Stelle
  entfernen: ein gesperrtes Objekt hielte zurück, was die Arten packen, und
  beide liefen auseinander; der Wert geht so wie dort nie ins Paket, und
  der Plan erfährt über `b9`, dass der Schlüssel besteht. Prüffälle
  `testACredentialInTheVersionOfTheThemeOfTheCssLandsInNoPackage` (im
  Klartext und in Base64) und
  `SyncStructureWritersTest::testTheFinishedContentOfAnObjectGoesThroughTheCredentialCheckOnceMore`.
- Der Rückweg eines neuen Menüs lässt Menü und Einträge als Rest stehen und
  setzt die Positionen zurück, die es genommen hat (`shared_back()` der
  Engine, aus der Sicherung des Schritts, bytebedingt); eine Position, die
  seither jemand geändert hat, bleibt stehen.

## Menüeinträge

- Ein Eintrag wird geschrieben, wie der Menü-Bildschirm ihn schreibt
  (`wp_update_nav_menu_item()`: Titel, Ziel, Reihenfolge, Eltern-Eintrag,
  Klassen, `target`, `xfn`, Beschreibung, Titelattribut), die acht
  Kern-Meta und das Menü mit dem Zeilenaufruf; die übrigen Meta nach
  Entscheid 28 (einfache Schlüssel mit Recht, ein neuer Eintrag mit
  Objekt-Meta sperrt: `post:create_with_object_meta`). `_menu_item_orphaned`
  bleibt aussen vor.
- Was der Kern anders speichern würde, sperrt vorab (`menu:sanitizer`, Z8):
  `target`, Objekt und Typ, die `sanitize_key()` ändert; Klassen und `xfn`,
  die `sanitize_html_class()` ändert (eine Klasse mit Leerzeichen sind zwei
  Klassen); eine Adresse, die `sanitize_url()` ändert oder die ein Eintrag
  einer Seite nicht hält; ein Eintrag, der sein eigener Eltern-Eintrag ist;
  ein Titel, der gleich dem Titel des Objekts hier ist (der Kern speichert
  ihn als leer: Beispiel «Über uns» auf Staging vor der Seite «Team», hier
  heisst die Seite «Über uns»); ein Eintrag ohne Titel und Beschreibung (der
  Kern schreibt eine Beschreibung aus einem Leerzeichen); Felder, die kses
  ohne `unfiltered_html` ändert. Grund: die gespeicherten Bytes wichen vom
  Ziel ab, auch wo das Menü gleich aussähe.
- Was der Menü-Bildschirm so nicht schreibt, wartet auf seine Feldregel
  (`menu:item_shape`, Z8d): ein anderer Status als `publish` oder `draft`,
  keine Position ab 1, ein Kern-Schlüssel doppelt oder fehlend, ein Typ, den
  der Kern nicht kennt, kein Menü oder mehrere, ein Term ausserhalb von
  `nav_menu`, ein Post eines anderen Typs.
- Ein geänderter Eintrag behält seine Daten von hier (`post_date`,
  `post_date_gmt` werden mitgegeben, sonst gäbe der Kern die Zeit des
  Aufrufs); ein neuer bekommt die Zeit des Laufs wie im Menü-Bildschirm, die
  Form hält kein Datum.
- Der Eltern-Post eines Eintrags (`post_parent`) ist der, den der Kern aus
  dem Objekt nimmt (der Elternteil der verlinkten Seite), nicht der von
  Staging.
- `wp_update_nav_menu_item()` feuert `wp_update_nav_menu_item`; ein Plugin
  (etwa ein Mega-Menü), das dabei eigene Meta schreibt, lässt den Schritt
  abweichen (Entscheid A).

## Widget-Bereiche

- Ein Bereich, den diese Site nicht hat, wird nicht angelegt
  (`widget_area:create`, Z8d; Weg: einmal Design → Widgets öffnen, dann neu
  berichten). Grund: die Bereiche gibt das Theme, nicht der Inhalt.
- Die Instanzen gelten über die Zuordnung des Paares: aus `base_extra`
  (`instances`: beide Listen der Basis, nach ihrem Platz, nur wo beide
  dieselben Widgets in derselben Reihenfolge nennen) und der Zuordnung, die
  ein Lauf geschrieben hat (`map`). Eine bekannte Instanz ohne bekanntes,
  vorhandenes Gegenstück gleicher Art, oder deren Gegenstück hier in einem
  anderen Bereich liegt (auch `wp_inactive_widgets`), sperrt
  (`widget_area:instance_unmapped`, Z8d; Weg: Paar neu abgleichen mit
  `wp_site_pairs`, oder von Hand).
- Eine neue Instanz behält ihre Nummer von Staging, wo sie hier frei ist,
  sonst die nächste freie (mindestens 2); die Zuordnung hält das Paar.
- Eine Instanz hier, die das Ziel nicht nennt, würde aus dem Bereich
  entfernt: gesperrt (`widget_area:remove_instance`, wie im Bericht).
- Ein Widget, das hier nicht registriert ist, eine Instanz ohne Nummer oder
  ohne Einstellungen, und eine Zeile `widget_<base>`, die nicht die Widgets
  von WordPress geschrieben haben (kein `_multiwidget`), sperren
  (`widget_area:widget_unregistered`, Z8). Grund: nur `update()` des
  Widgets kann seine Einstellungen prüfen.
- Ändert `update()` des Widgets oder `widget_update_callback` die
  Einstellungen (ein Text-Widget mit HTML ohne `unfiltered_html`), sperrt
  der Bereich vorab (`widget_area:sanitizer`, Z8).
- Das Schreiben einer Instanz liest den Bereich erst nach `update()` des
  Widgets, `widget_update_callback` und der Rechtefrage und vergleicht ihn
  mit dem Stand, den die Engine unmittelbar vor dem Aufruf gelesen hat
  (Codex-Runde 1, A2; Codex-Runde 3, A6): ändert ein solcher Filter dabei
  eine andere Instanz oder diese, bleibt seine Änderung, die Instanz wird
  nicht geschrieben, und der Schritt weicht ab (`failed`, jeder Teil des
  Schritts wie vorher; nichts davon geht von selbst zurück). Prüffall
  `testAWidgetFilterThatChangesAnotherInstanceAsTheStepWritesKeepsItsChangeAndTheStepWritesNothing`.
- Ein Schritt, der eine Instanz angelegt hat und dann abweicht, macht den
  Bereich zum Rest (`unit_rest()`); nehmen die Paare dieses Speichern nicht
  an, ist der Schritt unklar und nennt den Weg (das Paar von Hand
  abgleichen, sonst könnte ein späterer Lauf die Instanz noch einmal
  anlegen; Codex-Runde 1, C1). Prüffall
  `testAPairThatCannotBeKeptAsARestMakesTheStepUnclearWithItsWay`.
- Ein Bereich mit einer neuen Instanz bleibt auf dem Rückweg als Ganzes
  Rest (`residual`): die Instanz bleibt, die Reihenfolge, die sie nennt,
  bleibt mit ihr; die übrigen Instanzen gehen zurück. Erst ein bestätigter
  Neuabgleich (`realign`) lässt den Bereich wieder schreiben.

## Zusätzliches CSS

- Geändert wird nur das CSS, das diese Site mit dem Stylesheet verknüpft
  (`custom_css_post_id`) und das nicht im Papierkorb liegt; anlegen ist
  gesperrt (`custom_css:create`, Z8d).
- Nur für das aktive Theme in derselben Version wie auf Staging
  (`custom_css:theme_mismatch`, Z8b): ein Themewechsel ist eine Phase des
  Codes.
- Die Regel des Customizers gilt vorab (`custom_css:markup`, Z8): ein
  schliessendes STYLE-Tag oder ein Ende in einem Teil davon, ein Filter
  `update_custom_css_data`, der das CSS ändert, und kses ohne
  `unfiltered_html`. Ein `<script>` im CSS hält schon die Zugangsprüfung
  des Exports: das Objekt ist gehalten, der Plan weist es mit Weg ab.
- CSS mit der Quelle eines Präprozessors (`post_content_filtered`) oder mit
  einem anderen Status als `publish` wartet auf seine Feldregel
  (`custom_css:field_rule`, Z8d).
- Jede Änderung des CSS-Posts legt eine Revision an, wie der Customizer,
  auch der Rückweg. Keine ältere Revision wird dabei gelöscht: wo diese
  Site eine Zahl von Revisionen hält (`WP_POST_REVISIONS`, Filter
  `wp_revisions_to_keep`), löscht `wp_save_post_revision()` sonst die
  ältesten darüber; für den eigenen Aufruf und diesen Post gilt «alle
  behalten» (-1), der Filter geht danach wieder (Codex-Runde 1, A3); ebenso
  im Filter des Typs, den der Kern danach fragt
  (`wp_custom_css_revisions_to_keep`, Codex-Runde 2, A7). Die
  Zahl kann die Grenze so übersteigen, bis ein späteres Speichern in
  wp-admin aufräumt. Eine Site, die keine Revisionen hält (0), bekommt
  keine. Prüffall `testChangedAdditionalCssGoesOverAndBackAndThePageShowsIt`.
  Derselbe Weg gilt seither für jedes Speichern eines Posts hier
  (`AB_MCP_Sync_Post_Writer::keeping_revisions()`, `GRENZEN-INHALTE.md`),
  auch für Menüeinträge, wo ein Plugin ihnen Revisionen gibt (Prüffall
  `testAChangedMenuItemAndANewItemGoOverAndTheWayBackPutsTheChangedOnesBackAndLeavesTheNewOneAsARest`).

## Kern-Optionen

- Geschrieben werden nur Kern-Optionen mit Schema
  (`AB_MCP_Sync_Option_Schema::CORE`), unter ihrem eigenen Namen und nach
  der Prüfung des Option Guard auf dem aufgelösten Namen; eine Option eines
  Plugins oder Themes sperrt schon im Bericht (`option:unclassified`, Z8d).
- Eine Option, die hier fehlt, wird nicht angelegt (`option:create`, Z8d):
  der Kern legt seine Optionen bei Installation und Update an.
- IDs in Optionen (`page_on_front`, `page_for_posts`, `sticky_posts` und
  die übrigen mit Verweis) gehen über die Paare; ohne Gegenstück sperrt
  die Option (`option:reference_unmapped`, Z8).
- `sanitize_option()` dieser Site gilt vorab (`option:sanitizer`, Z8); ein
  Wert ausserhalb des Schemas sperrt (`option:off_schema`).
- Die Permalink-Optionen bauen die Rewrite-Regeln neu
  (`flush_rewrite_rules( false )`), die `.htaccess` schreibt der Lauf nicht;
  wo sie fehlt (Permalinks erstmals auf Apache), schreibt sie der
  Permalink-Bildschirm beim nächsten Speichern.
- Die Spalte `autoload` bleibt, wie sie ist, hin und zurück.

## Rechte

- Die Rechte jedes Schritts werden unmittelbar vor dem Schreiben noch
  einmal gefragt, auf jedem Weg, auch nach den Filtern, die dazwischen
  laufen (Codex-Runde 1, A5): vorwärts nach `update()` eines Widgets,
  zurück vor dem Zurücksetzen einer Option (nach `sanitize_option()`, mit
  der Klasse des Namens samt Option Guard), einer Position, des
  Zusätzlichen CSS, der Zeile eines Menüeintrags (nach `sanitize_post()`)
  und der Liste und jeder Instanz eines Bereichs (nach `update()` in
  `restore_right()`). Fehlt ein Recht, bleibt der Teil stehen (`left`) mit
  Grund und Weg.
- Die Reihenfolge jedes Schreibens mit einem Kernaufruf, vorwärts und
  zurück, steht an einer Stelle (`AB_MCP_Sync_Write`, Codex-Runden 2 und 3,
  A6): erst die Rechte (ihre Hooks, `map_meta_cap` und `user_has_cap`,
  laufen hier), dann die letzte Lesung des Objekts, dann der Vergleich
  dieser Lesung mit dem Stand, den die Engine für diesen Schritt erwartet,
  dann sofort der Aufruf; zwischen Lesung und Aufruf läuft kein Hook.
  Vorwärts ist der Stand das ganze Objekt, wie die Engine es unmittelbar
  vor dem Aufruf gelesen hat (`current()`, Byte für Byte), zurück die ganze
  Zeile, wie der Schritt sie geschrieben hat. Weicht er ab (ein Rechtehook,
  `update()` eines Widgets oder ein Filter hat das Objekt geändert, auch das
  Feld, das der Aufruf schreiben soll), wird nichts geschrieben: vorwärts
  weicht der Schritt ab (`ab_mcp_sync_changed_before_write`; die Engine
  vermerkt jeden veränderten Teil als fremd, keinen als eigen, Entscheid
  A), zurück bleibt der Teil stehen (`left`). Ein Objekt hier ohne Stand
  der Engine wird nie geschrieben (fail closed). Durch diesen Helfer
  schreiben alle Schreiber, die vor ihrem Aufruf ein Recht fragen: Meta
  eines Posts, eines Terms und die eigenen Schlüssel eines Anhangs, das
  Menü und seine Positionen, die Zeile und die Meta eines Menüeintrags, die
  Liste und die Instanzen eines Bereichs, das CSS, die Option (die Meta
  eines Menüs verglichen als ganzes Menü samt Positionen, nicht nur als
  Term: ein Rechtehook, der eine Position umhängt, wird vor dem Schreiben
  erkannt; Codex-Runde 4, Punkt 2); zurück die
  Zeile eines Posts (auch Vorlagen), eines Terms (auch des Menüs), eines
  Menüeintrags und das CSS. Die Aufrufe ohne Rechtefrage zwischen der
  Lesung der Engine und dem Aufruf (die Zeile eines Posts oder Terms, seine
  Terme, das Beitragsbild) brauchen ihn nicht: dort ist die Lesung der
  Engine die letzte. Die rohen Wege (Option, Position, Liste und Instanz
  eines Bereichs, Meta) schreiben nur, wo die Zeile genau die Bytes hält,
  die vor den Hooks gelesen wurden (`put_back()`), und rufen keinen Kern.
  Prüffälle `testTheOneWriteOfAStepAsksTheRightThenReadsThenComparesThenCalls`
  (die Reihenfolge), `testEveryWriterWritesNothingWhereAHookOfItsRightChangesItsOwnFieldRightBeforeTheCall`
  (ein Fall je Schreiber; der Term in
  `testTheRowOfATermIsWrittenThroughTheCoreAndAnIdWordPressGaveBackIsNotTheStepsOwn`).
- Die Tokens eines Plans und eines Rückwegs decken auch die Reihenfolge,
  in der die Teile jeder Art zurückgehen (`PART_ORDER`, Codex-Runde 1, C2).

## Reichweite der neuen Sperren

Gezählt am 08.11.2026 auf dem lokalen Paar der Integrationssuite (zwei
frische Sites WordPress 7.1.3, Twenty Twenty-Five, gepaart über
«gleich»), mit dem Bericht über alle Objekte der vier Arten:

- Optionen: 80 Objekte. 72 sind Kern-Optionen mit Schema ohne Sperre;
  5 sperrt die Zuständigkeitstabelle wie bisher (`active_plugins`,
  `default_role`, `stylesheet`, `template`, `users_can_register`); die
  neue Art lässt eine einzige ungeklärt (`user_count`, ein Zähler des
  Kerns ohne Schema: `option:unclassified`). Zwei weitere unterscheiden
  sich zwischen den Sites (`blogname`, `default_link_category`) und bleiben
  ungepaart, drei nennt das Paket nicht (`mailserver_*`, nicht
  verglichen), die Rollen je Site (`option:{prefix}user_roles`) wie bisher.
- Widget-Bereiche: drei (`sidebar-1`, `sidebar-2`, `wp_inactive_widgets`),
  gleich, keine Sperre.
- Menüs, Menüeinträge, Zusätzliches CSS: auf einer frischen Site mit
  Block-Theme keine.
- Die Sperren der Prüfung des Laufs (Positionen, Sanitizer, Instanzen,
  Theme, Rechte) trafen in den sechs Normalfällen kein Objekt: jeder Lauf
  schloss `closed_done`.
- `menu:position_exchange` (Codex-Runde 1, B2) trifft nur Pläne, in denen
  Menüs einander Positionen nehmen; auf dem frischen Paar gibt es keine
  Menüs, in den Normalfällen keinen solchen Plan. Eine Verschiebung ohne
  Schleife (ein Menü gibt ab, ein anderes nimmt) sperrt nicht, sie geht in
  der Ordnung des Plans durch.

Echte Sites (klassische Themes mit Menüpositionen, Widgets von Plugins,
Optionen von Plugins) sind in diesem Zweig nicht gemessen; ihre Zählung
gehört zur Messung grosser Sites (Z11c). Erwartet ist dort vor allem
`option:unclassified` für die Optionen von Plugins und Themes, die nach
Entscheid 27 ohnehin nicht geschrieben werden.

## Laufzeit

- Die Integrationsklasse `SyncStructureTest` (fünfzehn Fälle, einer mit
  zwei Datensätzen: die Normalfälle, die seltenen Fälle über den ganzen
  Weg, das bytebedingte Zurücksetzen einer geteilten Zeile und die Fälle
  aus Codex-Runde 1) läuft lokal in rund 81 s und erreicht rund 81 MiB
  Speicher. Sie setzt beide Sites je Fall zurück und
  setzt keinen leeren oder fremden Stand voraus: Menüs, Positionen,
  Widgets, CSS und Optionen legt jeder Fall selbst an; die Positionen
  `primary` und `footer` registriert sie für Site A selbst (Twenty
  Twenty-Five registriert keine).
- Die Mutationsliste `struktur` (124 Einträge, 91 über Unit-Tests, 33 über
  die Integrationsklasse) lief lokal Eintrag für Eintrag: Unit-Einträge
  rund 1 bis 2 s, Integrations-Einträge rund 7 bis 17 s.

## Nicht als Mutation geführt

- Die Texte der Meldungen und die Einträge der Sperrtabelle
  (`AB_MCP_Sync_Kinds`); sie hält `SyncKindsTest` und der Abgleich der
  Gründe (`SyncCoreReasonsTest`).
- Gleichwertig, darum nicht geführt (die Mutation überlebte, keine Probe
  kann sie unterscheiden): in `sanitizer_refusal()` des Menüeintrags
  `sanitize_key()` des Kernwerts für Typ, Objekt und `target` (der
  gespeicherte Wert ist `sanitize_key()` desselben Werts, die Bedingung
  daneben fällt für jeden solchen Wert gleich aus; der Typ ist vorher gegen
  die bekannten Typen geprüft); vor `set_theme_mod()` und
  `wp_update_custom_css_post()` das Leeren des Caches von `stylesheet`
  (`stylesheet` wird mit allen Optionen geladen, und das Leeren der Zeile
  der Theme-Mods leert diese mit).
- Die Optionen der Übernahmesperre in `shared_back()` waren hier zuerst
  als gleichwertig begründet; das traf bei parallelem Zugriff nicht zu
  (Codex-Runde 1, C3). Sie sind jetzt geführt (`c3-guard-options`).
- Die abschliessende Prüfung des Inhalts im Paket der Medien (A8): ein
  Anhang hängt nach seiner Form nichts an, das seine Art nicht schon prüft;
  kein Prüffall kann den Aufruf dort von seinem Fehlen unterscheiden. Er
  steht da, damit ein künftiges angehängtes Feld nicht vorbeikommt.
- Dass die Tokens die Reihenfolge der Teile decken (C2), zeigt kein Test:
  eine andere Reihenfolge bräuchte eine Änderung des Codes zwischen Plan
  und Bestätigung; geführt sind `part_order()` und, für den Rückweg und
  seine Fortsetzung, die Arten aus den Journalen der Schritte
  (`AB_MCP_Sync_Revert::part_orders_of()`; vorher las der Token des
  Rückwegs die Arten aus Einträgen, die keine tragen, und band nichts:
  Codex-Runde 2, C4).
- Nicht geführt, weil kein Prüffall es aufbaut: in `target_of()` des
  Bereichs die Zuordnung früherer Läufe neben der des Laufs
  (`$kept['map']`). Sie zählt erst, wenn ein Lauf einen Bereich schreibt,
  der eine früher zugeordnete Instanz nicht mehr nennt, deren Gegenstück
  hier ebenso fehlt (sonst sperrt `widget_area:remove_instance`), und die
  Instanz später zurückkehrt.
