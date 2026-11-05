# Grenzen des Berichts (Z8, Zweig «Bericht»)

Gesammelte Grenzen der Objektpaare (`wp_site_pairs`, Entscheide 10, 13 und 15;
Teil 1) und des Berichts (`wp_site_diff`, Entscheid 14; Teil 2): seltene oder
konstruierte Fälle und solche, die eine spätere Etappe schliesst. Je Grenze
ein Satz mit Beispiel und Grund; wo eine Ablehnung entsteht, nennt sie den
Weg.

## Teil 1: Objektpaare vorschlagen, bestätigen, verwerfen

### Inventar der Staging-Site aus mehreren Paketen

- Eine Art und ein Untertyp gelten auf der Staging-Seite nur als vollständig,
  wo **ein** Paket ihre Art der Datenbank von ihrem Anfang bis zu ihrem Ende
  las (`AB_MCP_Sync_Export::kinds()`) und nichts davon zurückhielt. Liegt eine
  Art über zwei Pakete verteilt, ist sie in keinem vollständig, und ein
  Objekt dieser Site ohne Gegenstück heisst «nicht verglichen» mit Weg, nie
  «nur live» (unvollständig → nie «nur live»). Beispiel: eine Staging-Site mit
  mehr als rund 4000 Posts aller Typen zusammen (auch Produkte und andere
  gesperrte Typen zählen) oder eine Bestandsaufnahme, die länger als 60 s
  dauert; die Art `option` einer gewöhnlichen Site liegt schon bei vier
  Objekten je Paket (Prüffall) über Pakete verteilt. Grund: das signierte
  Manifest nennt je Paket nur seine Arten mit `complete`, weder die Reihe noch
  die Stellen des Cursors; ein ausgelassenes Paket aus der Mitte einer Reihe
  wäre sonst nicht zu erkennen. Was gepaart, «gleich» oder «anderer Inhalt»
  heisst, hängt davon nicht ab; nur die Aussage «nur live» fehlt. Offen für
  die Lead-Sitzung: signierte Reihenstellen im Manifest (Änderung von
  Zweig 5).
- Hat die Staging-Site von einer Art der Datenbank gar kein Objekt (kein
  Menü, kein zusätzliches CSS), nennt kein Paket diese Art; ein Objekt dieser
  Art auf dieser Site heisst dann «nicht verglichen», nie «nur live». Grund
  wie oben: ein leeres Paket ist von einem nicht gelesenen nicht zu
  unterscheiden.
- Pakete zweier Reihen zusammen (ein Objekt zweimal, oder eine Art, die ein
  Paket ganz las, mit Objekten in einem anderen) lehnt der Vorschlag mit Weg
  ab (`ab_mcp_sync_pairs_series`): nur die Pakete einer Reihe übergeben.

### Zugangsdaten

- Ob ein Name eine Zugangsangabe ist (B9), entscheiden die Regeln jeder Site
  (`ab_mcp_redact_keys`). Gilt ein Name nur auf der Staging-Site als B9, fehlt
  das Objekt im Paket und erscheint hier als «nur live»; gilt er nur hier als
  B9, wird es hier gezählt (`live.credentials`) und erscheint auf der anderen
  Seite als «nur Staging». Nur eine Anzeige: nichts davon wird gepaart oder
  übernommen.
- Hält eine Zugangsangabe einen Namen eines Objekts (Liste der gehaltenen
  Zeilen des Imports; auf dieser Site dieselbe Prüfung,
  `AB_MCP_Sync_Import::held_reason()`), wird die ganze Gruppe gleicher
  Identität als «gehalten» gezeigt: je Objekt nur der SHA-256 seiner Kennung
  und der Code, nie ein Name, und nie gepaart (die Kennung stünde sonst im
  Klartext in der Paartabelle und in `list`).

### Vergleich

- «Gleich» heisst: dieselbe Identität und derselbe Hash, beide Seiten ganz
  gelesen. Die Hashes stehen je im eigenen ID-Raum (Entscheid 10). Verweist ein
  Objekt auf ein anderes, dessen ID auf beiden Sites verschieden ist (eine
  fremde Staging-Site mit anderen IDs), heisst es «anderer Inhalt», auch wenn
  es abgebildet gleich wäre; die abgebildete Form kommt mit den Paketen der
  Objekte (Zweig 8). Kopien eines Hosters haben dieselben IDs.
- Menüeinträge werden nach dem Slug ihres Menüs, Typ, Objekttyp und Position
  zugeordnet; zwei Einträge an derselben Position desselben Menüs trennt nur
  ein eindeutiger Hash, sonst «mehrdeutig».
- Eine Kennung, die kein Paar hält (länger als 191 Zeichen, ein
  Steuerzeichen, kein UTF-8; etwa ein Schlüssel eines Theme-Mods, der mit dem
  Stylesheet über 191 Zeichen kommt), heisst «nicht verglichen» mit dem Weg
  «von Hand übernehmen». Konstruiert; Optionsnamen haben höchstens 191
  Zeichen.

### Kanon-Wechsel

- Ein aktives Paar einer anderen Fassung der kanonischen Form wird nur neu
  gerechnet, wo beide Bestandsaufnahmen seine Objekte halten; sonst wartet es
  unverändert (`canon.waiting`). Das Ziel ist der Staging-Hash nur ohne
  `override` und ohne Verweis auf einen Post oder Term über seine ID und ohne
  möglichen Verweis; sonst wird das Paar *ungeklärt*. Grund: ohne die Formen
  lässt sich nicht abbilden, und *ungeklärt* ist die sichere Seite. Bis heute
  gibt es nur `canon/1`.

### Bestätigen und Verwerfen

- Der Ausgangsstand eines bestätigten Paars ist der Stand des Vorschlags,
  nicht frisch gelesen: ändert sich ein Objekt dazwischen, zeigt der Bericht
  die Änderung gegenüber dem bestätigten Stand. Grund: `confirm` schreibt
  keine Inhalte, und frische Hashes beider Seiten verlangten eine neue
  Bestandsaufnahme. Der Token bindet dafür den Vorschlag, die Paare, wie sie
  jetzt sind, den Abdruck des Site-Paars und die Verbindung.
- `realign` (Neuabgleich eines *ungeklärten* oder `residual`-Paars) macht den
  Stand jeder Seite zum neuen Ausgangsstand (Entscheid 14 wörtlich): ein
  Unterschied, der jetzt besteht, gilt danach nicht mehr als Änderung. Vorschlag
  und Vorschau nennen das (`note`).
- «Anderer Inhalt» und ein mehrdeutiges Paar anderen Inhalts werden als
  *ungeklärt* bestätigt; welche Seite gewinnt, fragt der Bericht (Vorrang 1)
  und entscheidet der Plan des Laufs (Zweig 8).
- Ein Vorschlag gilt so lange wie sein frühestes Paket (höchstens einen Tag)
  und gehört der Verbindung, die ihn machte (und damit ihrem Konto): über eine
  andere Verbindung, etwa den Hub statt der direkten, wird neu vorgeschlagen.
  Ein neuer Vorschlag einer Verbindung entfernt ihre früheren aus der Ablage
  (die Ablage kennt für Berichte kein Kontingent, wie für Pakete; so häufen
  sich wiederholte Vorschläge nicht für ihren Tag an).
- Eine Bestätigung macht höchstens 500 Paare; mehr in Teilen über `after`
  (die Antwort nennt `more_after`).
- Alles oder nichts durch Ausgleich, nicht durch eine Transaktion: scheitert
  ein Paar, nimmt die Bestätigung zurück, was sie davor schrieb (verwirft neue
  Paare, setzt neu abgeglichene auf ihren Schnappschuss); ein Verwerfen legt
  davor beendete Paare mit neuen IDs wieder an. Scheitert auch der Ausgleich,
  nennt die Meldung den Rest.
- Zwei Aufrufe, die denselben Vorschlag zugleich fortsetzen, schreiben zwei
  Teile derselben Nummer; der Vorschlag gilt dann nicht mehr, Weg: neu
  vorschlagen, ein Aufruf nach dem anderen.
- Grosse Sites: Ausgearbeitet wird ein Vorschlag im Speicher (die Zeilen
  beider Seiten); gemessen ist das bis zu rund 500 Objekten je Seite
  (Prüffälle), grosse Sites misst Z11c.
