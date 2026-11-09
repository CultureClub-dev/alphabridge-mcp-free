# Grenzen des Pakets (Z8, Zweig «Paket»)

Gesammelte Grenzen der Vertrauensschicht (Schlüssel der Staging-Quelle,
Anker, signiertes Manifest; Entscheide 4 und 5) und des Transports der Pakete
(Entscheid 6) aus den Codex-Runden 1 bis 6 von Zweig 5 und aus dem Bau: seltene oder konstruierte Fälle, die abgelehnt statt
umgangen werden, und was ein späterer Zweig schliesst. Je Grenze ein Satz
mit Beispiel und Grund; wo eine Ablehnung entsteht, nennt sie den Weg.

## Kopplungscode und Manifest

- Ein Kopplungscode über `MAX_CODE` (2048 Zeichen) wird nicht gelesen. Bei
  einer Site-Adresse mit einem Pfad von rund 1500 Zeichen schreibt die
  Staging-Site einen Code von 2288 Zeichen, den `read_code()` ablehnt
  (`ab_mcp_sync_key_code`). Weg: den Schlüssel über https abrufen; eine
  Site-Adresse dieser Länge kommt praktisch nicht vor (Codex-Runde 1, C).
- Ein Manifest über `MAX_TEXT` (1 MiB) wird nicht gelesen. 2000 Untertypen
  mit je 191 Unicode-Zeichen ergeben ein Manifest, das die Staging-Site
  signiert und die Live-Site als `ab_mcp_sync_manifest_envelope` ablehnt;
  echte Untertypen sind Namen von Post-Typen, Taxonomien und Optionen mit
  höchstens einigen Dutzend Zeichen (Codex-Runde 1, C).

## Adressen

- Auf macOS beschädigt `parse_url()` internationale Namen: es macht jedes
  Byte, das die C-Bibliothek als Steuerzeichen zählt, zu «_», und die von
  macOS zählt im Gebietsschema C.UTF-8, das PHP seit 8.1.2 für `LC_CTYPE`
  setzt (Bug #81598), die Bytes 0x80 bis 0x9F und 0xAD dazu (gemessen: PHP 8.5.10 auf
  macOS; mit `LC_CTYPE` C bleiben sie). Betroffen ist jedes Zeichen mit einem
  solchen Byte in seiner UTF-8-Folge, etwa ß, Ä, Ö, Ü, É und í.
  `AB_MCP_Staging::normalize_url()` (Z6) speichert dort eine Partneradresse
  wie `https://straße.example` als anderen Host. Unter Linux (glibc) bleiben
  die Bytes heil (CI: PHP 8.2 bis 8.4), und ein so gespeicherter Name koppelt
  in beiden Schreibweisen. Anker und Manifest lesen den Host aus dem Text
  (`AB_MCP_Sync_Key::site_form()`), die Staging-Site nennt sich in der
  xn--Form, und ein so gespeichertes Paar lehnt die Kopplung mit dem Weg
  ab, die xn--Form einzutragen (`idn_hint()`). Die Speicherung selbst zu
  ändern ist eine Änderung von Z6 mit neuen Texten in neun Sprachen; Hosts
  für WordPress laufen fast immer unter Linux, macOS vor allem lokal. Die
  eigene Adresse ist davon nicht betroffen: Ziel des Pakets und `pair_print`
  lesen sie aus `home_url()` direkt über `site_form()`, ohne
  `normalize_url()` davor (Codex-Runde 2); eine eigene Adresse, die
  `site_form()` nicht lesen kann, gibt kein `pair_print`.
- Ohne die PHP-Erweiterung intl werden internationale Domainnamen Byte für
  Byte verglichen: `https://bücher.example` und `https://xn--bcher-kva.example`
  sind dann verschiedene Sites, und die Ablehnung nennt die xn--Form als Weg.
- Auf macOS trifft dasselbe Pfade mit solchen Bytes: `normalize_url()`
  beschädigt auch sie, und `site_form()` übernimmt den Pfad so, wie
  `normalize_url()` ihn schreibt. Ohne intl nennt eine Staging-Site einen
  solchen Host in Unicode; eine Live-Site auf macOS nimmt diesen Schlüssel
  nicht an, weil sie die Adresse mit `normalize_url()` prüft (`offer()`).
  Der Weg ist derselbe: die Adresse in der xn--Form, einen Pfad
  prozentkodiert.
- Der Port der gespeicherten Partneradresse wird für genau diese Adresse
  und einmal erlaubt (`AB_MCP_Outbound::get_partner()`): die erste Prüfung
  der Adresse verbraucht die Freigabe, eine Weiterleitung folgt ohnehin
  nicht. Eine Anfrage, die ein Haken während der ersten startet, erhält den
  Port nicht, auch für dieselbe Adresse. Startet ein Haken vor der Prüfung
  (`pre_http_request`) eine Anfrage derselben Adresse, erhält diese die
  Freigabe und die eigentliche Anfrage wird abgelehnt («A valid URL was not
  provided.»). `AB_MCP_Staging::check_partner()` (Z6) nutzt denselben Weg.
- Der Abruf (`AB_MCP_Sync_Fetcher`) lehnt eine Adresse ab, deren Pfad ein
  Segment `.` oder `..` hat, auch als `%2e` in beliebiger Schreibweise
  (`ab_mcp_sync_dot_segment`); er löst sie nicht auf. Eine gespeicherte
  Partneradresse mit einem solchen Segment, etwa `https://s.example/blog/..`,
  speichert `normalize_url()` (Z6) so; jeder Abruf scheitert dann, und die
  Ablehnung nennt den Weg, die Adresse ohne das Segment zu speichern
  (Codex-Runde 2).
- Der Abruf prüft den Pfad bis `?`; ein `#` bleibt im geprüften Teil. Eine
  Adresse mit einem Fragment, in dem `/../` steht (`…/file#/../note`), wird
  darum abgelehnt, obwohl eine Anfrage kein Fragment sendet. Die Adressen,
  die der Abruf baut (Schlüssel, Stücke), haben keines (Codex-Bestätigung
  auf d6ceb11).

## Pakete und Transport

- Wer eine Abrufadresse innerhalb ihrer zehn Minuten sieht, liest das Stück
  (bekannte Grenze von Entscheid 6). Die Signatur ist ein MAC des Schlüssels
  der Staging-Site (`AB_MCP_Sync_Key::mac()`); ein neuer Schlüssel macht alle
  Abrufadressen davor wertlos.
- Die Route `alphabridge/v1/staging-package` ist keine Route von WordPress:
  eine Anfrage, die genau stimmt, beantwortet `AB_MCP_Sync_Grant::serve()`
  auf `rest_pre_dispatch`, jede andere beantwortet WordPress wie eine
  unbekannte Route. Was WordPress bei jeder Route tut, gilt hier ebenso:
  `?_envelope` hüllt auch die Antwort «keine Route» ein, und ein Plugin, das
  die REST-API ohne Anmeldung sperrt, sperrt auch den Abruf.
- Vor dem MAC lädt die Route nur `AB_MCP_Sync_Grant` und den Schlüssel, die
  mit dem Plugin geladen sind; Export, Inventar und Arten erst nach einem
  gültigen MAC (Codex-Runde 5, A-4). Was bleibt: die Prüfung liest die
  Rolle (in `ab_mcp_options`, mit dem Plugin geladen) und auf einer
  bestätigten Staging-Site die beiden Optionen ohne Autoload
  `ab_mcp_sync_mac` und `ab_mcp_sync_source` aus der Datenbank. An der Zeit
  einer falschen Anfrage lässt sich darum höchstens erkennen, ob die Site
  eine bestätigte Staging-Site ist, nie ein Paket, ein Stück oder etwas des
  Schlüssels; dieselbe Rolle zeigt ohnehin die Route
  `alphabridge/v1/staging-key`.
- Ein Inventar, das ein Aufruf nicht fasst (60 Sekunden, 4000 Objekte), wird
  zu mehreren Paketen: die Antwort trägt den Cursor für das nächste. Die
  Pakete einer Reihe zählen als ein offener Export und ein offenes Paket;
  ein abgeschlossener Import zählt nicht mehr als offen. Eine
  Art gilt in einem Paket nur als vollständig, wenn es sie von ihrem Anfang
  bis zu ihrem Ende gelesen hat; das Zusammensetzen mehrerer Pakete ist
  Sache des Berichts (Zweig 6).
- Eine Zeile der Datenbank mit vielen Objekten (die Theme-Mods eines
  Themes: eine Optionszeile, jeder Schlüssel ein Objekt) wird ganz gelesen;
  ein Paket kann so über 4000 Objekte hinausgehen. Trägt eine solche Zeile
  ein Paket über 5000 Objekte (eine Zeile der Theme-Mods mit mehr als 1000
  Schlüsseln genau an der Grenze), lehnt der Export ab, und derselbe Cursor
  scheitert wieder (konstruiert). Menüeinträge sind keine solche Zeile: der
  Leser paginiert sie einzeln (berichtigt in Codex-Runde 2 zu Teil 3).
- Ein Objekt, das kein Stück fasst (eine Zeile des Inventars über 5 MiB),
  oder eines, das JSON nicht schreibt (tiefer als 4096 Ebenen), lehnt den
  Export ab; Zeilen des Inventars tragen keinen Inhalt und sind klein.
- Das Relay prüft `is_ssl()`: hinter einem Proxy, der TLS beendet, meldet
  WordPress `http`, solange die Site es nicht einrichtet (in
  `wp-config.php` `$_SERVER['HTTPS']` nach `HTTP_X_FORWARDED_PROTO`
  setzen); eine öffentliche Site lehnt
  das Relay dann ab, der direkte Abruf bleibt. Ob eine Adresse öffentlich
  ist, entscheidet ihr DNS (`AB_MCP_Outbound::public_addresses()`); ein
  Name, der vom Server aus nicht auflöst, gilt als öffentlich.
- Ändert eine Person den Anker, während ein Aufruf Stücke holt, nimmt
  dieser Aufruf seine Stücke noch an; der nächste Aufruf prüft das Manifest
  erneut und lehnt das Paket ab.
- Beginnen zwei Aufrufe dasselbe Paket zur selben Zeit, entstehen zwei
  Gruppen mit derselben Bezeichnung; benutzt wird die älteste, die andere
  räumt die Bereinigung nach einem Tag.
- Ohne die PHP-Erweiterung zlib entsteht kein Paket (Ablehnung mit Weg);
  dieser Zweig hat keinen Test, weil die Prüfumgebung zlib hat.
- Optionen ohne Hash (ein Name einer Zugangsangabe, B9, und andere, die nie
  verglichen werden) zählt die Objektgrenze des Inventars nicht. 5001
  Optionen `password_1` … `password_5001` bleiben unter den 4000 Objekten
  eines Aufrufs, das Paket überschreitet dann aber 5000 Objekte, `pack()`
  lehnt ab, und derselbe Cursor scheitert wieder (konstruiert; Codex-Runde 4).
- Pakete einer früheren Bindung (anderes Paar, Rolle neu bestätigt, neuer
  Schlüssel) gibt die Staging-Site nie mehr aus; verworfen werden sie, wo
  sie ihr begegnen: beim nächsten Export, beim Abruf und bei
  `wp_site_export`/`wp_site_export_chunk` mit ihrer Kennung, sonst nach
  einem Tag durch die Bereinigung. Das Speichern der Rolle fasst die Ablage
  nicht an (es müsste sonst bei jeder Bestätigung die Ablage prüfen). Das
  genügt, weil keine alte Bindung wieder entstehen kann: jedes Speichern
  von Rolle oder Paar schreibt einen neuen Zufallswert in den Datensatz
  (`AB_MCP_Staging::bind()`), den die Bindung enthält; ein Paar A → B → A
  in derselben Sekunde ist eine neue Bindung (Codex-Runde 5, A-2). Fehlt
  der Wert (ein Datensatz von vorher), macht ihn der nächste Export; kein
  Paket davor war an ihn gebunden.
- Bricht ein Export zwischen Stück und Datensatz ab (der Prozess endet),
  bleibt eine Gruppe ohne Datensatz: sie gilt als kein Paket und wird nie
  ausgegeben. Der nächste Export entfernt sie, sobald sie `ABANDONED`
  (600 Sekunden) alt ist; bis dahin kann sie ein Export eines anderen
  Aufrufs sein, der gerade schreibt, und zählt als offener Export ihrer
  Reihe (Codex-Runde 5, B-4).
- Ein Schreiben in die Ablage, das scheitert oder weniger Bytes schreibt
  (volle Platte), hinterlässt keine temporäre Datei und keine Warnung mit
  dem Pfad (`AB_MCP_Payload_File::write()`; gefunden mit den echten
  Schreibfehlern der Tests von Runde 5).
- Ein Objekt mit einer Sperre der Zugangsdatenprüfung (ein Feld einer
  Zugangsangabe, ein Passwort, ein Skript, HTML ausserhalb von kses, ein
  PHP-Objekt, ein Wert, der nicht entpackt, ein Name einer Zugangsangabe in
  einer Schicht, die kein Leser las) steht im Paket nur mit Identität,
  Status und Sperren (mit Etappe und Grund), ohne Hash, Verweise und mögliche Verweise
  (`CREDENTIAL_LOCKS`, Codex-Runde 5, A-1). `php_object` und
  `unreadable_value` geben auch die Leser der kanonischen Form (zu tief,
  nicht entpackbar); für denselben Wert gibt sie die Zugangsdatenprüfung
  ebenso, und sie zählen mit (fail-closed). Andere Sperren (Builder, Typ,
  mögliche Verweise, Schreibweise) behalten ihre Zeile.
- Ein Schlüssel ohne das Geheimnis seiner MACs (ein Schlüssel von vorher,
  ein gelöschtes Geheimnis) bleibt nicht hängen: der nächste Export und das
  Erstellen des Schlüssels auf der Seite (`AB_MCP_Sync_Key::make()`) machen
  es mit dem geschützten Schreiber; nur wo es
  sich nicht speichern lässt, lehnt der Export mit Weg ab
  (`ab_mcp_sync_mac_failed`), Schlüssel und Bestätigung bleiben.
- Ein Import gehört dem Konto und der Verbindung (dem Token von MCP, ohne
  Token dem Konto allein), die ihn begannen; eine andere Verbindung desselben
  Kontos sieht die Gruppe nicht und beginnt eine eigene, wie ein anderes
  Konto (Codex-Runde 1 von Zweig 7, A2). Grund: Bericht und Vorschlag sind
  an ihre Verbindung gebunden (Entscheid 15), so auch die Pakete, aus denen
  sie entstehen. Der Relay und `wp_site_import_chunk` über mehrere Aufrufe
  laufen über dieselbe Verbindung und bleiben, wie sie sind. Ein neues Token
  (erneuert oder ersetzt) holt ein Paket neu; eine Gruppe, deren Aufzeichnung
  keine Verbindung hält (vor dieser Regel), gehört keiner und bleibt bis zu
  ihrem Ablauf. Jede offene Gruppe zählt bis zu ihrem Abschluss gegen die
  fünf offenen Pakete der Site.
- Ein Import hält das `pair_print` seines Beginns und den Zweck, für den er
  begann; jede Fortsetzung prüft beide, bevor sie etwas des Pakets liest,
  und endet, wo sich Rolle, Paar oder Anker geändert haben
  (`ab_mcp_sync_import_pair_changed`, Weg: Paket neu holen) oder der Zweck
  keiner dieser Version ist (`ab_mcp_sync_import_purpose_held`); was ankam,
  wird entfernt (Codex-Runde 5, B-3 und Punkt 3). Das Paar wird vor der
  erneuten Prüfung des Manifests verglichen: ein Import eines erneuerten
  oder widerrufenen Schlüssels, eines entfernten Paares oder Ankers endet so
  mit Weg und wird entfernt, statt mit `ab_mcp_sync_manifest_key` stehen zu
  bleiben. Eine Gruppe eines früheren Paares zählt nicht mehr als offenes
  Paket: die Ablage hält in der Gruppe die Bindung ihres Beginns
  (`pair_print`) und lässt beim Zählen unter ihrer Sperre jede Gruppe einer
  anderen Bindung aus (`AB_MCP_Store_Budget::package_refusal()`); fünf
  unterbrochene Importe sperren nach einem Schlüsselwechsel so keinen
  neuen (GitHub-Codex P2 zu PR 132). Ihre Bytes zählen weiter, bis die
  Ablage sie entfernt, denn sie liegen auf der Platte (alte Pakete nahe der
  Byte-Grenze von 512 MiB können einen neuen so eine Weile über diese
  Grenze ablehnen lassen, mit ihrem Weg). Beim Beginn wird
  keine Gruppe entfernt: die eigene Fortsetzung entfernt ihre Gruppe bei
  einem geänderten Paar (mit Weg), alles Übrige die Ablage nach ihrer Zeit.
  Ein Abdruck, den ein Aufruf beim Beginn aus einem alten Cache liest,
  verschiebt so höchstens die Zählung vorübergehend (eine Gruppe zählt oder fehlt bis zur
  nächsten Fortsetzung oder ihrem Ablauf) und löscht beim Beginn nie
  eine Gruppe, auch keine eines anderen Kontos (Codex zu d51833c und 5f626da, A).
  Übrig bleiben drei seltene Fälle (Codex zu dfb477d): Ein Aufruf mit
  altem Abdruck im Cache kann die Gruppe desselben Kontos entfernen, die
  dasselbe Konto inzwischen für dieselbe Paket-ID unter dem neuen Paar
  begonnen hat (nur eigene Daten; nötig ist ein Neubeginn mit gültigem
  Manifest und Zweck, die Meldung nennt den Weg, empfangene Stücke kommen
  neu). Eine Gruppe ohne gespeicherte Bindung (angelegt vor dieser Regel,
  nur Entwicklungsstände) zählt weiter, bis die Ablage sie entfernt.
  Exporte öffnen ohne Bindung und zählen darum liegengebliebene
  Importgruppen voll mit; das trifft nur eine Site, die nach Importen als
  Live die Rolle Staging bekommt, bis die Ablage die alten Gruppen
  entfernt. Ein
  Manifest, das während eines offenen Imports abläuft, lehnt die nächste
  Fortsetzung ab
  (`ab_mcp_sync_manifest_expired`); diese Gruppe bleibt, bis die Ablage sie
  einen Tag nach ihrem Beginn entfernt, und belegt bis dahin ihren Platz
  (selten: das Paket wurde kurz vor dem Ablauf seines Manifests begonnen
  und liegen gelassen).
- Bestätigt eine Person auf der Live-Site nur die Rolle neu und bleibt der
  Anker unverändert, bleiben Manifeste desselben Schlüssels innerhalb ihrer
  24 Stunden annehmbar: derselbe Anker, dasselbe Vertrauen. Offene Importe
  und Pläne enden je für sich über ihr `pair_print`; ein Paket wird mit
  demselben oder einem neuen Manifest neu begonnen. Zwischen Staging- und
  Live-Site wird keine Uhr verglichen: über die Zeit entscheidet die Live-Site
  allein (`created`, `expires` des Manifests gegen ihre Uhr).
- Bereits ausgelieferte Manifeste und Kopien von Stücken bleiben auf der
  bisherigen Live-Site innerhalb ihrer 24 Stunden annehmbar, solange deren
  Anker unverändert ist, auch wenn die Staging-Site inzwischen mit einer
  anderen Live-Site gekoppelt ist: ein reiner Paarwechsel auf der
  Staging-Site ist dem Relay und der Live-Site unbekannt. Grund: der Anker
  auf der Live-Site ist die Wurzel des Vertrauens, und das Ziel jedes
  Manifests bleibt genau diese Site. Abschneiden geht nur auf der
  Live-Site: den Anker widerrufen (das Paar entfernen) oder den erneuerten
  Schlüssel der Staging-Site dort bestätigen, womit der alte abgelöst ist.
  Erneuert die Staging-Site nur ihren Schlüssel, schneidet das die
  ausgelieferten Kopien nicht ab, solange der Anker der Live-Site den alten
  Schlüssel hält (Codex-Runde 6, F3; berichtigt den Nachtrag von Runde 5).

## Inhalt, Rechte und Entpacken (Teil 3, Entscheide 8 und 9)

- Ein Paket macht nur die bestätigte Staging-Site mit Paar auf eine
  Produktivsite (das Paar einer Staging-Site ist immer eines auf eine
  Produktivsite, `record()`), mit Schreibzugriff (Stufe `full`) und für ein
  Konto mit `manage_options`; sonst `ab_mcp_sync_export_capability` oder
  `ab_mcp_sync_export_mode` mit nummerierten Schritten. Mit Schreibzugriff
  aus gibt die Route auch gemachte Pakete nicht aus.
- Rechte je Objekt (`AB_MCP_Sync_Export_Rights`): Inhalte mit `read_post`
  oder `edit_post` (wer bearbeiten darf, darf lesen: ACF registriert seine
  Feldgruppen mit eigenen Rechten und `map_meta_cap` aus, dort hat ein
  Administrator `edit_post`, aber kein `read_post`; Codex-Runde 1 zu Teil
  3, B1), nicht Öffentliches (`is_post_publicly_viewable()`: Status oder Typ
  nicht öffentlich) mit `edit_post`; Begriffe einer öffentlichen Taxonomie
  wie für jeden auf der Site, sonst mit `edit_term` (der Plan nennt
  Begriffe nicht; die Regel folgt der für Inhalte); Menüs, ihre Einträge,
  Widget-Bereiche, Theme-Mods und das zusätzliche CSS mit
  `edit_theme_options`; Optionen mit `manage_options`, das jeder Export
  zuerst verlangt. Ein Post eines Typs, den WordPress nicht kennt (das
  Plugin ist aus), mit `edit_others_posts`, worauf `map_meta_cap()` für
  `read_post` und `edit_post` eines solchen Posts selbst zurückfällt (direkt
  gefragt, ohne dessen Meldung «doing it wrong»); ein Begriff einer
  unbekannten Taxonomie mit `manage_categories`, was `register_taxonomy()`
  einer Taxonomie zum Verwalten und Bearbeiten ihrer Begriffe gibt
  (`map_meta_cap()` antwortet dort `do_not_allow`; Codex-Runde 1 zu Teil 3,
  A1). Was das Konto nicht darf, ein nicht mehr vorhandenes Objekt und eine
  Art ohne Regel kommen nicht ins Paket, werden gezählt und (die ersten
  200) mit Art, Untertyp, Kennung und fehlendem Recht benannt (`withheld`,
  mit Weg); Art und Untertyp sind dann nicht `complete`.
- Zugangsdaten vor dem Paket (die vorhandene Prüfung des Inventars): ein
  Objekt, dessen Name der einer Zugangsangabe ist (B9), kommt nicht ins
  Paket und wird gezählt (`credentials.names`); seine Art bleibt
  `complete`, weil es auf keiner Site verglichen wird. Ein Feld darin oder
  eine Sperre der Zugangsdatenprüfung hält die Zeile auf Identität, Status
  und Sperren (A-1, `credentials.held`); ausgelassene B9-Einheiten in gepackten
  Objekten zählt `credentials.units`. Optionen, die das Inventar als
  geschützt führt (Status `guarded`, etwa `password_1`), sind kein B9: ihre
  Zeile trägt wie bisher Name und Status, keinen Hash und keinen Wert, dazu
  den Code ihres Grundes (unten, «Gründe ohne Sperre»).
- Beim Import, sobald das Paket ganz geprüft ist, wird es entpackt und
  jede Zeile erneut geprüft, bevor es als vollständig gilt: jedes Stück
  stückweise aufgeblasen (je 512 Bytes Eingabe) und angehalten, sobald es
  den Rest der deklarierten Grösse oder 8 MiB übersteigt, also höchstens um
  die Ausgabe eines Eingabeschritts darüber (zlib bläst höchstens etwa
  1032-fach auf: rund 516 KiB); genau ein gzip-Mitglied, die ganze Eingabe
  gelesen, nichts danach; jedes Stück endet mit einem Zeilenumbruch (ein
  Stück fasst ganze Zeilen von höchstens `SLICE` Bytes, so bleibt auch jede
  Zeile unter 8 MiB); höchstens 5000 Objekte; JSON höchstens 64 tief, in der
  einen Schreibweise von `AB_MCP_Sync_Canon::encode()`; jede Zeile genau mit
  den Feldern und Typen einer Inventarzeile, auch in ihren verschachtelten
  Teilen (Identität je Art, Sperren, Verweise, mögliche Verweise), die
  Identität passend zur Kennung (Id der Datenbank, Name der Option, Bereich,
  Stylesheet und Schlüssel), kein B9-Objekt, keine von einer
  Zugangsdatensperre gehaltene Zeile mit mehr als ihrer Identität; so viele
  Zeilen und Bytes, wie das Manifest nennt (Codex-Runde 1 zu Teil 3, A2 und
  B2). Das ganze Inventar beider Prüf-Sites über alle Arten geht so durch.
- Die Prüfung jeder Zeile hat zwei Stufen (Codex-Runde 3 zu Teil 3; die
  Zeichensperren und Längengrenzen der Runde 2 lehnten Pakete gewöhnlicher
  Sites ab). Die Struktur lehnt das Paket ab, wo eine Zeile nicht ist, was
  der Export schreibt: ein Feld ohne Platz oder von anderem Typ, `canon`
  nicht die Fassung des Kanons, ein Status ausserhalb der festen Liste des
  Inventars, eine Sperre, deren Schlüssel, Etappe und Grund nicht genau so
  in den Tafeln von `AB_MCP_Sync_Kinds` stehen, Raum (post, term, user) und
  Stärke (strong) ausserhalb ihrer Listen, ein Grundcode ausserhalb der
  Liste seines Status, Bytes nicht in der Byte-Form des Kanons, eine
  Identität nicht ihrer Art oder für ein anderes Objekt; dazu LF, gzip und
  Mengen wie oben. Der Inhalt lehnt nie ab: namensartige Felder (die
  Kennung `ref`, Namen und Slugs der Identität, die Orte `at`, `unread` und
  `b9`, Logins, das Ziel `target`) nehmen jeden Text an oder die Byte-Form
  des Kanons, ohne Zeichenverbot und ohne eigene Längengrenze; für sie
  gelten nur die Grenzen je Stück und Zeile (8 MiB, Tiefe 64). Mit echten
  Lesern geprüft gehen so durch: der Stand eines Stapels von WooCommerce
  (`wc_batch_` mit dem Klassennamen samt Backslashes und dessen md5),
  Schlüssel serialisierter Werte mit Anführungszeichen, Backslash und
  geschweiften Klammern (`a"b`, `Vendor\Service`, `{{WRAPPER}}`, je über
  einem lokalen Link), ein Pfad über 4 KiB, ein Login `jörg` (von einem
  Filter eines Plugins belassen), der Name einer Zeile der Theme-Mods in
  Bytes, deren Wert nicht entpackt, und ein leerer Name.
- Zugangsdaten je Zeile beim Import: die Namensprüfung bleibt für die
  Namen gespeicherter Werte (der Name einer Option, der Zeile der
  Theme-Mods, der Schlüssel eines Theme-Mods, bei `unreachable` die Zeile,
  die WordPress stattdessen liest), nach den Regeln der Live-Site samt
  `ab_mcp_redact_keys` (Code `credential_name`). Dazu läuft über jeden
  namensartigen Wert der Zeile, auch über `identity.post_name`, was der
  Export über einen Wert laufen lässt: der Inhaltsteil der
  Zugangsdatenprüfung (`AB_MCP_Sync_Credentials::unit()`) und das Netz über
  den Rohwert in jeder seiner Schichten (`AB_MCP_Sync_Net::over_name()`:
  dekodiert, in Base64, in `data:`; dieselbe Zählung der Zugangsangaben wie
  `check()`, ohne die Zählung der Verweise, denn ein Name ist keine
  Adresse; Codex-Runde 4 zu Teil 3). Ein Feld einer Zugangsangabe hält die
  Zeile (`credential_field`): ein Slug `{"password":"CANARY-ONLY"}`, seine
  Base64-Form, `{password:"CANARY-ONLY"}` und `password%253DCANARY-ONLY`.
  Ein Name, der sich nicht zu Ende lesen lässt (ein Skript, ein unfertiges
  Tag, ein PHP-Objekt, eine Schicht tiefer als das Netz liest), hält sie
  ebenso (`unreadable_name`: was nicht zu Ende gelesen ist, gilt nie als
  sauber). Eine gehaltene Zeile wird gezählt (`held`), das Paket läuft
  weiter. Gewöhnliche Namen bleiben ungehalten, mit echten Lesern geprüft
  (das ganze Inventar der Prüf-Site hält nur die beabsichtigte Zeile):
  `reset-password`, `api-docs`, `token-sale`, `about-us`, `r-d`, `&amp;`,
  Slugs in Unicode und aus Zahlen, Meta und Optionen von WooCommerce,
  Elementor und Yoast. Eine Option, deren Name nach der Namensregel eine
  Zugangsangabe ist (etwa `woocommerce_myaccount_lost_password_endpoint`),
  ist schon beim Export B9 und kommt nicht ins Paket (vorbestehend).
- Die Liste der gehaltenen Zeilen (`held-…`, Format `ab-sync-held/3`) ist
  wertfrei: je Zeile Art, SHA-256 der Kennung in der Schreibweise von
  `AB_MCP_Sync_Canon::encode()` (ein Text mit seinen Anführungszeichen,
  Bytes in der Byte-Form) und Code, nie die Kennung selbst, die eine
  Zugangsangabe tragen kann (eine Option `api_key=…`). Zweig 6 findet die
  Zeile im Paket über denselben Hash ihrer Kennung (Art und Kennung sind
  in einem Paket eindeutig). Jeder Eintrag hat so eine feste Grösse (rund
  100 Bytes), die Liste für 5000 Objekte passt in ihre Reservierung
  (`HELD_MAX`) auch bei Kennungen von einer Million Bytes; vorher sprengten
  fünf solche gehaltene Kennungen den Abschluss (Codex-Runde 4 zu Teil 3,
  C). Eine Liste einer Fassung davor gilt als fehlend
  (`ab_mcp_sync_import_held`, Weg: Paket neu holen).
- Serialisierte Werte von Meta und Optionen prüft das Netz der Arten und
  des Exports seit Zweig 11b `heikel/z8-netz` entpackt, jeden Schlüssel und
  jedes Blatt für sich: Prosa in einem serialisierten Array («Spieler:
  Anna, Token: drei») hält das Objekt nicht mehr, ein echtes Zugangsdatum
  darin (auch verschachtelt, in Base64, im verdrängten Wert eines doppelten
  Schlüssels) hält es weiter, ein Wert, der nicht entpackt oder ein Objekt
  enthält, ebenso. Einzelheiten und die Grenze C in GRENZEN-STRUKTUR.md,
  Abschnitt «Serialisierte Werte im Netz (Zweig 11b)».
- Feste Formate von Zugangsdaten halten das Objekt in jeder Form (Blatt,
  einfacher Wert, `post_content`, Meta, Option, Widget): Tokens von GitHub,
  Stripe, AWS, Slack und Mailchimp, JSON Web Tokens, `Authorization` mit
  Bearer oder Basic am Anfang einer Zeile oder eines Felds, private
  Schlüssel in PEM, der Wert eines Login-Cookies, Passwortfelder in Code-
  und Konfigurationsform (Zweig 11b, GRENZEN.md, Abschnitt «Zugangsdaten
  nach festen Formaten»). Grenze C: Zugangsdaten in Prosa («password:
  Sommer2026» im Beitragstext, «user: …, password: …», `<p>password:
  …</p>`) gehen ins Paket wie auf `8df6146`. Grund: `wp_get_post` gibt den
  Rohinhalt ohnehin aus (`includes/tools/class-tools-content.php:83`) und
  verweigert nur Meta-Schlüssel mit Namen eines Zugangsdatums; das Paket
  zeigt nichts, was das Lesewerkzeug nicht schon zeigt.
- `unreadable_name` hält auch einen harmlosen Namen, den die Prüfung als
  unfertiges HTML liest, etwa eine Option `literal<foo` (konstruiert; kein
  gewöhnlicher Name eines Plugins so gefunden). Das Paket läuft weiter;
  nur diese Zeile übernimmt Zweig 6 nicht von selbst. Ebenso hält das Netz
  über Namen einen Namen mit Klammern, die es nicht auswerten kann (eine
  Option `zz_a}, b: c`), und einen Namen, dessen Schichten tiefer reichen
  als das Netz liest (ein mehrfach Base64-kodierter Text); beides
  konstruiert (Codex-Runde 5 zu Teil 3, C), Slugs, Optionsnamen,
  Meta-Schlüssel, Pfade und Logins gängiger Plugins blieben ungehalten.
- Gründe ohne Sperre als Codes: eine Zeile mit Status `guarded`,
  `unreachable` oder `unreadable` trägt keinen Text, sondern `reason_code`
  aus der festen Liste `AB_MCP_Sync_Option_Schema::REASON_CODES`, die der
  Export setzt und der Import prüft; `unreachable` dazu `target`, den Namen
  der Zeile, die WordPress unter diesem Namen liest (namensartig, geprüft
  wie oben). Ein Code eines anderen Status, ein fehlender, ein Code oder
  ein Ziel bei einem anderen Status lehnen das Paket ab. Zweig 6 setzt die
  Codes in die Meldungen um, die `classify()` und
  `AB_MCP_Sync_Option_Name::settle()` heute zu ihnen schreiben (englisch,
  mit Weg): `option_guard` die Ablehnung durch den Optionsschutz (ein
  Administrator ändert den Namen in wp-admin), `read_as_other_row` «WordPress
  reads the row <target> under this name …», `lookup_failed` und
  `not_settled` die Option liess sich nicht nachschlagen beziehungsweise ihr
  Name nicht auflösen (erneut versuchen; das Fehlerprotokoll der Datenbank
  nennt die Ursache).
- Eine leere Identität gilt nur in der Zeile einer Zeile der Datenbank, die
  nicht gelesen wurde (nicht vollständig, die Tafel in `unread`); dann
  prüft die Namensprüfung die Kennung. Namen, die keine UTF-8 sind, nimmt
  der Import in der Byte-Form des Kanons (`{"~b": …}`) an, und eine
  Menüposition darf negativ sein (`menu_order = -1` eines Plugins). Die deklarierten Höchstwerte (5000 Objekte,
  256 MiB) prüft schon das Manifest (`MAX_OBJECTS`, `MAX_UNPACKED`, gleich
  `AB_MCP_Store_Budget::UNPACK`). Ein Paket, das nicht hält, wird entfernt
  (`ab_mcp_store_unpack_limit` oder `ab_mcp_sync_import_lines`, je mit
  Schritten). Ein Name, den erst die Regeln der Live-Site als Zugangsangabe
  lesen (`ab_mcp_redact_keys`), wird als gehalten gelistet (`held`); die
  Werte selbst waren nie im Paket. Slugs, Post-Typen, Taxonomien und
  Bereiche sind keine Namen gespeicherter Werte: sie prüft nur der
  Inhaltsteil (oben), sonst würde eine Seite «reset-password» auf jeder
  Mitglieder-Site gehalten.
- Ein Exportdatensatz einer älteren Fassung (ohne Bericht) wird beim
  nächsten Zugriff als veraltet verworfen (`ab_mcp_sync_export_stale`, Weg:
  neu exportieren); ein abgeschlossener Import ohne Liste der gehaltenen
  Objekte wird entfernt (`ab_mcp_sync_import_held`, Weg: Paket neu holen;
  Codex-Runde 1 zu Teil 3, C1).

## Anker

- Ein Schlüssel, den die Live-Site bestätigt und dann ersetzt oder
  widerrufen hat (anderes Paar, andere Rolle, Paar entfernt), wird dort nie
  wieder bestätigt: seine Schlüssel-ID steht als SHA-256 in
  `ab_mcp_sync_anchor_retired` (ohne Autoload, zurückgelesen, die letzten
  50; `forget()` und `uninstall.php`), und Vorbereiten wie Bestätigen
  lehnen ihn ab (`ab_mcp_sync_anchor_retired`, Weg: auf der Staging-Site
  unter AlphaBridge → Staging «Renew the key», dann hier den neuen
  Kopplungscode eingeben oder den Schlüssel abrufen und bestätigen; die
  Seite zeigt die Ablehnung mit nummerierten Schritten). «Renew the key»
  (`AB_MCP_Sync_Key::replace()`, `manage_options`, Nonce, POST) erneuert
  den Schlüssel einer bestätigten Staging-Site, ohne die Rolle zu ändern;
  Pakete des alten Schlüssels gibt sie nicht mehr aus (Codex-Runde 6, F2).
- Lässt sich der ersetzte Schlüssel nicht als ersetzt speichern, wird der
  neue nicht bestätigt (`ab_mcp_sync_anchor_retire_failed`). Lässt sich
  beim Widerruf (Paar entfernt oder geändert, Rolle geändert, Hub nimmt
  das Paar zurück) der Schlüssel nicht als widerrufen speichern, bleiben
  Anker und Paar, wie sie waren, und die Seite oder der Hub erhält
  `ab_mcp_sync_anchor_revoke_failed` mit Weg (Datenbank prüfen, erneut
  entfernen); `uninstall.php` löscht alles wie bisher (Codex-Runde 6, F1).
- Eine Bestätigung gilt erst, wenn der Anker so zurückgelesen wird, wie er
  geschrieben wurde (sonst `ab_mcp_sync_anchor_write_failed`), und ein
  Anker, dessen Schlüssel-ID in der Sperrliste steht, gilt nicht als
  aktiv: ein halbes Ersetzen (Sperrliste geschrieben, Anker nicht) führt
  zu «Schlüssel bestätigen», nicht zum alten Schlüssel (Codex-Runde 6, F4).
- Ein zweiter Klick auf «Bestätigen» für den aktiven Schlüssel bestätigt
  nichts neu: der Anker bleibt, wie er ist, mit seinem `pair_print`.
- Mehr als 50 ersetzte Schlüssel ein und derselben Live-Site: der älteste
  fällt aus der Liste und wäre wieder bestätigbar, wenn eine Staging-Site
  ihn noch hätte; eine Staging-Site erneuert ihren Schlüssel nie zurück.

## Privater Schlüssel

- Die Fehlerwege von wpdb geben ihn nicht aus (`store_secret()`): kein
  Fehlerprotokoll, keine Ausgabe, kein Eintrag in `$EZSQL_ERROR`, nichts in
  `last_query`, `func_call`, `last_error` oder unter `SAVEQUERIES` in
  `queries`. Was jeder Code mit Haken in derselben Anfrage sieht, bleibt:
  die Haken von `update_option()` (`pre_update_option_*`, `update_option`,
  `added_option` …), der Filter `query` und unter `SAVEQUERIES`
  `log_query_custom_data` erhalten den Wert, ein dauerhafter Objekt-Cache
  hält ihn wie jede Option, `$wpdb->last_result` hält die gelesene Zeile
  bis zur nächsten Abfrage. Ein Plugin mit Code auf der Site liest die
  Option ohnehin.
- Vollständiger Schreibausfall der Datenbank: Verweigert sie jedes
  Schreiben, scheitert die Erneuerung, und `forget()` versucht zu löschen.
  Bleibt auch das Löschen aus, bleibt der alte Schlüssel beim unveränderten
  alten Datensatz, die Rolle beim alten, vorher bestätigten Stand; die
  Person sieht die Ablehnung mit Weg (Codex-Runden 1 und 2).

## Offen für spätere Zweige

- «Offene Pläne verfallen» (Entscheid 4): mit den Plänen der Zweige 6 und 8
  über `pair_print` im Zustand ihres Tokens; ein offener Lauf hält über das
  `pair_print` in `runs` (Zweig 8).
- Multisite: Export und Import lehnen ab (Entscheid 3), mit Weg
  (`ab_mcp_sync_multisite`).
- Pakete von Medien (`media`): Export und Import nehmen sie seit Zweig 10
  (`AB_MCP_Sync_Media_Package`, `GRENZEN-MEDIEN.md`); ein anderer Zweck
  lehnt weiterhin mit Weg ab (`ab_mcp_sync_import_purpose`; eine Gruppe, die
  eine andere Version für einen Zweck begann, den diese Fassung nicht
  nimmt, endet nach B-3 mit `ab_mcp_sync_import_purpose_held`). Seit Zweig
  10 nimmt diese Fassung jeden Zweck, den ein Manifest nennen kann
  (`AB_MCP_Sync_Export::PURPOSES` gleich `AB_MCP_Sync_Manifest::PURPOSES`):
  der Mutationseintrag `paket/import-purpose-inventory`, der die eine Liste
  gegen die andere tauschte, wäre gleichwertig und hält jetzt die Klausel
  gegen jeden anderen Text; im Prüffall von `paket/p3r2-strength` nennt die
  Zeile eine Stärke, die kein Leser schreibt (`weak` ist seit Zweig 10 eine,
  für das Elternteil eines Anhangs), damit `typed()` sie ablehnt und nicht
  erst eine spätere Prüfung (CI-Lauf 42480445606 von free#140).
  Pakete der Umgebung (`env`) nimmt der Import seit Zweig 7, Pakete von
  Objekten (`objects`) seit Zweig 8: ein Paket `objects` oder `media` gehört
  zu einem Bericht (`report_hash`, Zweig 6); der Import verlangt den
  `report_hash` beim ersten Aufruf (`ab_mcp_sync_import_report`), und das
  Manifest muss denselben Bericht und Zweck nennen
  (`ab_mcp_sync_manifest_report`, `ab_mcp_sync_manifest_purpose`). Die
  Rechte je Objekt, die Zugangsdatenprüfung und die Entpack-Grenzen dieses
  Zweigs gelten für sie ebenso; die Prüfung der Inhalte beim Schreiben
  (Rechte je Meta-Schlüssel, Entscheid 28) bringt Zweig 8.
- Die Liste der Objekte, die eine Zugangsangabe nach den Regeln der
  Live-Site hält (`held-…` in der Gruppe), liest die Übernahme ab Zweig 6
  und findet jede Zeile über den SHA-256 ihrer Kennung; in diesem Zweig
  wird sie geschrieben, gezählt und zurückgelesen.
- Den Anker aus der Paar-Route des Hubs oder per Werkzeug vorbereiten:
  frühestens mit dem Hub-Zweig 14, dann nur als Vorbereitung.
