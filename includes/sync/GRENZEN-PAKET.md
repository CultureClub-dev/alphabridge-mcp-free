# Grenzen des Pakets (Z8, Zweig «Paket»)

Gesammelte Grenzen der Vertrauensschicht (Schlüssel der Staging-Quelle,
Anker, signiertes Manifest; Entscheide 4 und 5) und des Transports der Pakete
(Entscheid 6) aus den Codex-Runden 1 bis 5 von Zweig 5 und aus dem Bau: seltene oder konstruierte Fälle, die abgelehnt statt
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
- Eine Zeile der Datenbank mit vielen Objekten (ein Menü mit seinen
  Einträgen, die Theme-Mods eines Themes) wird ganz gelesen; ein Paket kann
  so über 4000 Objekte hinausgehen. Trägt eine solche Zeile ein Paket über
  5000 Objekte (ein Menü mit mehr als 1000 Einträgen genau an der Grenze),
  lehnt der Export ab, und derselbe Cursor scheitert wieder (konstruiert).
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
  Status, Sperren und Grund, ohne Hash, Verweise und mögliche Verweise
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
- Ein Import gehört dem Konto, das ihn begann; ein anderes Konto beginnt
  eine eigene Gruppe. Jede offene Gruppe zählt bis zu ihrem Abschluss gegen
  die fünf offenen Pakete der Site.
- Ein Import hält das `pair_print` seines Beginns und den Zweck, für den er
  begann; jede Fortsetzung prüft beide, bevor sie etwas des Pakets liest,
  und endet, wo sich Rolle, Paar oder Anker geändert haben
  (`ab_mcp_sync_import_pair_changed`, Weg: Paket neu holen) oder der Zweck
  keiner dieser Version ist (`ab_mcp_sync_import_purpose_held`); was ankam,
  wird entfernt (Codex-Runde 5, B-3 und Punkt 3).
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
  Manifests bleibt genau diese Site. Abschneiden: den Schlüssel auf der
  Staging-Site erneuern (die alten Schlüssel-IDs lehnt die Live-Site ab)
  oder den Anker auf der Live-Site widerrufen.

## Anker

- Ein Schlüssel, den die Live-Site bestätigt und dann ersetzt oder
  widerrufen hat (anderes Paar, andere Rolle, Paar entfernt), wird dort nie
  wieder bestätigt: seine Schlüssel-ID steht als SHA-256 in
  `ab_mcp_sync_anchor_retired` (ohne Autoload, zurückgelesen, die letzten
  50; `forget()` und `uninstall.php`), und Vorbereiten wie Bestätigen
  lehnen ihn ab (`ab_mcp_sync_anchor_retired`, Weg: auf der Staging-Site
  den Schlüssel erneuern, dann den Anker neu vorbereiten und bestätigen).
  Lässt sich der ersetzte Schlüssel nicht als ersetzt speichern, wird der
  neue nicht bestätigt (`ab_mcp_sync_anchor_retire_failed`); beim Widerruf
  geht der Anker auch dann (Codex-Runde 5, Nachtrag zu Punkt 3).
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
- Pakete von Objekten, Medien und Umgebung (`objects`, `media`, `env`):
  der Export lehnt sie in Teil 2 mit Weg ab; Rechte je Objekt, `complete`
  je Art und Untertyp nach Entscheid 9 und die Zugangsdatenprüfung folgen
  in Teil 3, die Entpack-Grenzen beim Lesen eines Pakets ebenda.
- Den Anker aus der Paar-Route des Hubs oder per Werkzeug vorbereiten:
  frühestens mit dem Hub-Zweig 14, dann nur als Vorbereitung.
