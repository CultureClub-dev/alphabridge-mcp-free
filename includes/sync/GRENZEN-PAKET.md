# Grenzen des Pakets (Z8, Zweig «Paket»)

Gesammelte Grenzen der Vertrauensschicht (Schlüssel der Staging-Quelle,
Anker, signiertes Manifest; Entscheide 4 und 5) und des Transports der Pakete
(Entscheid 6) aus den Codex-Runden 1 und 2 von Zweig 5 und aus dem Bau: seltene oder konstruierte Fälle, die abgelehnt statt
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
  eine Anfrage, die genau stimmt, beantwortet `AB_MCP_Sync_Export::serve()`
  auf `rest_pre_dispatch`, jede andere beantwortet WordPress wie eine
  unbekannte Route. Was WordPress bei jeder Route tut, gilt hier ebenso:
  `?_envelope` hüllt auch die Antwort «keine Route» ein, und ein Plugin, das
  die REST-API ohne Anmeldung sperrt, sperrt auch den Abruf.
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
  nicht an (es müsste sonst bei jeder Bestätigung die Ablage prüfen).
- Bricht ein Export zwischen Stück und Datensatz ab (der Prozess endet),
  bleibt eine Gruppe ohne Datensatz: sie gilt als kein Paket und wird nie
  ausgegeben, zählt aber als offener Export ihrer Reihe, bis die
  Bereinigung sie nach einem Tag entfernt.
- Ein Import gehört dem Konto, das ihn begann; ein anderes Konto beginnt
  eine eigene Gruppe. Jede offene Gruppe zählt bis zu ihrem Abschluss gegen
  die fünf offenen Pakete der Site.

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
