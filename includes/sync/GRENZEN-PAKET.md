# Grenzen des Pakets (Z8, Zweig «Paket»)

Gesammelte Grenzen der Vertrauensschicht (Schlüssel der Staging-Quelle,
Anker, signiertes Manifest; Entscheide 4 und 5) aus den Codex-Runden 1 und 2
von Zweig 5 und aus dem Bau: seltene oder konstruierte Fälle, die abgelehnt statt
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
- Multisite: Export und Import lehnen in Teil 2 ab (Entscheid 3), mit Weg.
- Den Anker aus der Paar-Route des Hubs oder per Werkzeug vorbereiten:
  frühestens mit dem Hub-Zweig 14, dann nur als Vorbereitung.
