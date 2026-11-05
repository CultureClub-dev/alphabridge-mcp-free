# Grenzen des Pakets (Z8, Zweig «Paket»)

Gesammelte Grenzen der Vertrauensschicht (Schlüssel der Staging-Quelle,
Anker, signiertes Manifest; Entscheide 4 und 5) aus der Codex-Runde 1 von
Zweig 5 und aus dem Bau: seltene oder konstruierte Fälle, die abgelehnt statt
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

- `parse_url()` macht aus den Bytes 0x80 bis 0x9F ein «_»; darunter sind die
  UTF-8-Folgen von ß, Ä, Ö und Ü. `AB_MCP_Staging::normalize_url()` (Z6)
  speichert eine Partneradresse wie `https://straße.example` darum als
  anderen Host. Anker und Manifest lesen den Host aus dem Text
  (`AB_MCP_Sync_Key::site_form()`), die Staging-Site nennt sich in der
  xn--Form, und ein so gespeichertes Paar lehnt die Kopplung mit dem Weg
  ab, die xn--Form einzutragen (`idn_hint()`). Die Speicherung selbst zu
  ändern ist eine Änderung von Z6 mit neuen Texten in neun Sprachen.
- Ohne die PHP-Erweiterung intl werden internationale Domainnamen Byte für
  Byte verglichen: `https://bücher.example` und `https://xn--bcher-kva.example`
  sind dann verschiedene Sites, und die Ablehnung nennt die xn--Form als Weg.
- Ohne intl hat eine Staging-Site, deren eigene Adresse solche Bytes hat,
  keinen Kopplungscode, und ihre Route antwortet mit einem Kodierungsfehler,
  weil die so gelesene Adresse kein UTF-8 mehr ist und kein JSON sie trägt;
  der Weg ist derselbe: die Adresse in der xn--Form.
- Der Port der gespeicherten Partneradresse wird für genau die eine Anfrage
  und genau diese Adresse erlaubt (`AB_MCP_Outbound::get_partner()`), eine
  Weiterleitung folgt ohnehin nicht. `AB_MCP_Staging::check_partner()` (Z6)
  nutzt denselben Weg.

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
- Verweigert die Datenbank jedes Schreiben, scheitert die Erneuerung,
  `forget()` versucht zu löschen, und bleibt auch das Löschen aus, bleibt
  der alte Schlüssel zum unveränderten alten Datensatz (die Rolle bleibt,
  wie sie vorher bestätigt war); die Person sieht die Ablehnung mit Weg.

## Offen für spätere Zweige

- «Offene Pläne verfallen» (Entscheid 4): mit den Plänen der Zweige 6 und 8
  über `pair_print` im Zustand ihres Tokens; ein offener Lauf hält über das
  `pair_print` in `runs` (Zweig 8).
- Multisite: Export und Import lehnen in Teil 2 ab (Entscheid 3), mit Weg.
- Den Anker aus der Paar-Route des Hubs oder per Werkzeug vorbereiten:
  frühestens mit dem Hub-Zweig 14, dann nur als Vorbereitung.
