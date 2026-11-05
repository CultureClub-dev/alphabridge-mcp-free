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
  Objekt dieser Site ohne Gegenstück heisst «nicht verglichen», nie «nur
  live» (unvollständig → nie «nur live»). Beispiel: eine Staging-Site mit
  mehr als rund 4000 Posts aller Typen zusammen (auch Produkte und andere
  gesperrte Typen zählen) oder eine Bestandsaufnahme, die länger als 60 s
  dauert; die Art `option` einer gewöhnlichen Site liegt schon bei vier
  Objekten je Paket (Prüffall) über Pakete verteilt. Grund: Reihenkennung und
  Cursor stehen nur im Exportdatensatz der Staging-Site, nicht im signierten
  Manifest, und `complete` bescheinigt nur das eine Paket; eine Lücke oder eine
  Mischung getrennter Teilpakete wäre sonst nicht zu erkennen (bestätigt von
  Codex, Runde 1). Was gepaart, «gleich» oder «anderer Inhalt» heisst, hängt
  davon nicht ab; nur die Aussage «nur live» fehlt. Die Meldung sagt das so:
  ein neuer Export mit vollen Rechten hilft nur bei zurückgehaltenen Objekten,
  nicht bei einer verteilten Art. Abhilfe ist ein signierter Zusammenhang der
  Reihe (Reihenkennung, lückenlos prüfbare Stelle oder Vorgänger, Abschluss);
  eine Änderung am Paketvertrag, als offener Punkt im Plan geführt.
- Hat die Staging-Site von einer Art der Datenbank gar kein Objekt (kein
  Menü, kein zusätzliches CSS), nennt kein Paket diese Art; ein Objekt dieser
  Art auf dieser Site heisst dann «nicht verglichen», nie «nur live». Grund
  wie oben: ein leeres Paket ist von einem nicht gelesenen nicht zu
  unterscheiden.
- Pakete zweier Reihen zusammen (ein Objekt zweimal, oder eine Art, die ein
  Paket ganz las, mit Objekten in einem anderen) lehnt der Vorschlag mit Weg
  ab (`ab_mcp_sync_pairs_series`): nur die Pakete einer Reihe übergeben.
- Ein fertiger Vorschlag wird geblättert und bestätigt, ohne seine Pakete
  erneut zu öffnen: er ist ein geprüfter Schnappschuss, gebunden an Verbindung,
  Abdruck des Site-Paars, Quelle, Kanon und den Ablauf seines frühesten
  Pakets. Ein inzwischen entferntes Paket macht ihn nicht ungültig.

### Zugangsdaten

- Ob ein Name eine Zugangsangabe ist (B9), entscheiden die Regeln jeder Site
  (`ab_mcp_redact_keys`). Gilt ein Name nur auf der Staging-Site als B9, fehlt
  das Objekt im Paket und erscheint hier als «nur live»; gilt er nur hier als
  B9, wird es hier gezählt (`live.credentials`) und erscheint auf der anderen
  Seite als «nur Staging». Nur eine Anzeige: nichts davon wird gepaart oder
  übernommen.
- Hält eine Zugangsangabe einen Namen eines Objekts (Liste der gehaltenen
  Zeilen des Imports; auf dieser Site dieselbe Prüfung,
  `AB_MCP_Sync_Import::held_reason()`), wird die Zeile **vor jeder Ablage und
  Ausgabe der Vorschlagsdaten** auf Art, SHA-256 der Kennung und Code gekürzt
  (`AB_MCP_Sync_Proposal::reduced()`), auch für schon gepaarte Objekte und im
  Neuabgleich. Die ursprünglichen, geschützten Eingangsstücke des Pakets
  behalten ihren Inhalt: der Import legt das signierte Stück ab, bevor er die
  Namen prüft (vorbestehende Grenze des Transports, Zweig 5; der Name einer
  Zugangsangabe in einer Kennung ist konstruiert). Sie steht für sich (die gekürzte Zeile hat keine Identität mehr,
  nach der sich gruppieren liesse) und wird nie gepaart. Ihr Gegenstück
  derselben Identität auf der anderen Seite erscheint dann als «nur Staging»
  oder «nur live»: beide Seiten prüfen mit den Regeln dieser Site, so trifft
  die Haltung gleiche Namen auf beiden Seiten.

### Vergleich

- «Gleich» heisst: dieselbe Identität und derselbe Pfad der Eltern, beide
  Seiten ganz gelesen, dieselbe **abgebildete Form** (Entscheid 10): jeder
  Verweis über eine ID nennt sein Ziel über dessen Identität auf derselben
  Seite (`AB_MCP_Sync_Identity`: ein Post über Typ, bei Vorlagen das Theme,
  die Slugs seiner Eltern und seinen Slug; ein Anhang über `_wp_attached_file`,
  nur gelesen, gepaart wird er mit Zweig 10; ein Term über Taxonomie und Slug;
  ein Menüeintrag über Menüs, Typ, Ziel und Eltern-Eintrag; ein Autor über das
  Login). Dieselben Objekte mit anderen IDs auf beiden Sites sind so «gleich».
  Die Ausgangsstände eines Paars bleiben die Hashes je Seite im eigenen
  ID-Raum.
- **Ein Ziel je Identität** (Codex Runde 2, A-1): Eine Identität, die ein
  zweites Objekt derselben Site auch hat, nennt keines von beiden; ein Verweis
  darauf bleibt unaufgelöst («anderer Inhalt»), und ein Objekt, dessen eigene
  Identität sie enthält, hat keine (ein Menüeintrag unter einem solchen
  Eltern-Eintrag, eine Seite unter einer solchen Elternseite heissen
  «unsicher»). Beispiele: zwei eigene Links «#» mit derselben Beschriftung
  unter demselben Eltern-Eintrag, zwei Posts eines Typs mit demselben Slug
  unter derselben Elternseite (ein Entwurf behält seinen Slug), zwei Anhänge
  derselben Datei, zwei Terme einer Taxonomie mit demselben Slug (der Kern
  vergibt einen Slug je Taxonomie nur einmal; nur eine anders geschriebene
  Tabelle hat ihn zweimal). Eigene Links heissen nach Adresse **und**
  Beschriftung: «Privat» und «Firma» auf `#` sind zwei Einträge, ein
  Untereintrag, der von «Privat» zu «Firma» wechselt, ist ein anderer Eintrag
  («nur Staging» und «nur live»), nie «gleich»; eine umbenannte Überschrift
  ebenso. Folge für mehrsprachige Sites, deren Plugin Anhänge je Sprache auf
  dieselbe Datei verdoppelt (WPML): ein Verweis auf einen solchen Anhang ist
  nicht aufzulösen, das Objekt heisst «anderer Inhalt» (die sichere Seite).
  Die Gruppe selbst wird weiter nur über eine auf beiden Seiten eindeutige
  gleiche abgebildete Form gepaart (zwei gleiche Überschriften an ihren
  Plätzen), sonst «mehrdeutig».
- **Mögliche Verweise** (Entscheid 12; Codex Runde 2, B-1): Ganze Zahlen ohne
  belegtes Schema in gespeicherten Werten (Meta von Posts, Termen und
  Menüeinträgen, auch in serialisierten Werten und gespeichertem JSON, etwa
  `settings.image.id` in `_elementor_data`, ein Bildfeld von ACF, die
  Komma-Liste `_product_image_gallery`) nennen in einer **zweiten**
  abgebildeten Form das Objekt, das sie auf ihrer Site treffen, über dessen
  Identität, als möglicher Verweis markiert (`mapped_possible`). Eine Zahl,
  die nichts trifft, einen Post und einen Term zugleich oder ein Objekt ohne
  eindeutige Identität, bleibt, wie sie ist. «Gleich» heisst: eine der beiden
  abgebildeten Formen ist gleich (`AB_MCP_Sync_Proposal::mapped_same()`). So
  bleibt eine Zahl, die kein Verweis ist (ein Preis 10 auf beiden Sites, der
  dort verschiedene Posts 10 trifft), gleich, und eine Bild-ID 7 gegen 107
  derselben Datei ist gleich. Die Sperre nach Entscheid 12 bleibt
  unverändert: solche Objekte übernimmt Z8 nicht; nur ihr Paar und ihr
  Vergleich werden richtig.
- Grenzen der möglichen Verweise: Ein Objekt, das **beides** enthält,
  verschobene Bild-IDs und eine gleiche Zahl, die auf den beiden Sites
  verschiedene Objekte trifft (etwa eine Galerie und `_stock` 5), heisst
  weiter «anderer Inhalt» und wird *ungeklärt* bestätigt (die sichere Seite).
  Grund: dafür müsste je Zahl verglichen werden, und ein Paket trägt einen
  möglichen Verweis nur mit seiner Stelle, nie mit seinem Wert (Vertrag A von
  Zweig 5). Umgekehrt können zufällig gleich abgebildete Zahlen (ein Preis 7
  hier, 107 dort, und Post 7 hier ist derselbe wie Post 107 dort; konstruiert)
  «gleich» ergeben; das betrifft nur gesperrte Objekte und keinen Schreibweg
  in Z8. Mögliche Verweise im Inhalt (Blöcke ohne Schema, Shortcodes), in
  Optionen und Widgets werden nicht abgebildet: dort bleibt der Vergleich
  wie bisher (andere IDs heissen «anderer Inhalt»).
- Ein Verweis auf ein Ziel ohne Identität (ein Post ohne Slug, ein gelöschtes
  Ziel, ein Term, dessen ID noch in zwei Taxonomien steht) gibt keine
  abgebildete Form: das Objekt heisst «anderer Inhalt». Dafür tragen die
  Zeilen eines Pakets jetzt `mapped` und `mapped_possible` und die
  Menüeinträge `target` und `parent` (SHA-256 der Identität, nie ein Wert);
  das Paketformat ist darum zwischen den Fassungen nicht gemischt lesbar
  (beide Sites gleiche Fassung; eine ältere Zeile ohne `mapped` heisst
  «anderer Inhalt»).
- Keine Paarung durch Ausschluss: Nur ein Objekt je Seite derselben Identität
  auf demselben Pfad ist ein Paar; mehrere auf einem Pfad nur über eine auf
  beiden Seiten eindeutige gleiche abgebildete Form, der Rest ist
  «mehrdeutig». Dieselbe Identität auf einem anderen Elternpfad (`/team/kontakt`
  gegen `/shop/kontakt`), ein Post ohne Slug (Entwürfe) und ein Menüeintrag,
  dessen Ziel oder Eltern-Eintrag keine Identität hat, heissen «unsicher»
  (`uncertain`) und werden nur durch eine ausdrückliche Wahl gepaart, nie über
  eine Kategorie bestätigt. Eine verschobene Seite muss die Person darum selbst
  zuordnen.
- Ein **unvollständiger Elternpfad** führt immer nach «unsicher» (Codex Runde
  2, A-2): eine Elternseite, die die Bestandsaufnahme ihrer Seite nicht hält
  (nicht gelesen, in einem fehlenden Teilpaket, gehalten), keinen Slug hat
  oder deren Typ, Slug und Eltern ein zweiter Post derselben Seite auch hat,
  steht im Pfad als `?`; `?` ist nie ein gemeinsamer Pfad beider Seiten.
- Menüeinträge werden über den Slug ihres Menüs, Typ, Objekttyp, ihr Ziel
  (eigene Links: Adresse und Beschriftung) und ihren Eltern-Eintrag
  zugeordnet, nie über die Position (umsortiert ist
  dasselbe Ziel dasselbe Objekt). Dasselbe Ziel zweimal unter demselben
  Eltern-Eintrag desselben Menüs ist «mehrdeutig», ausser eine gleiche
  abgebildete Form ist auf beiden Seiten eindeutig. Ein Ziel ausserhalb der
  Bestandsaufnahme ohne Identität (ein gelöschter Post) macht den Eintrag
  «unsicher».
- Eine Kennung, die kein Paar hält (länger als 191 Zeichen, ein
  Steuerzeichen, kein UTF-8; etwa ein Schlüssel eines Theme-Mods, der mit dem
  Stylesheet über 191 Zeichen kommt), heisst «nicht verglichen» mit dem Weg
  «von Hand übernehmen». Konstruiert; Optionsnamen haben höchstens 191
  Zeichen.

### Kanon-Wechsel

- Ein aktives Paar einer anderen Fassung der kanonischen Form wird nur neu
  gerechnet, wo beide Bestandsaufnahmen seine Objekte halten; sonst wartet es
  unverändert (`canon.waiting`). Gleich abgebildete Formen geben neue
  Ausgangsstände; ein Paar mit `override` wird *ungeklärt*, weil sich das Ziel
  mit der bestätigten Wahl aus den Formen allein nicht rechnen lässt (die
  sichere Seite). Bis heute gibt es nur `canon/1`.

### Bestätigen und Verwerfen

- Der Ausgangsstand eines bestätigten Paars ist der Stand des Vorschlags.
  Der Token bindet zusätzlich die frisch gelesenen Hashes der gewählten
  Objekte dieser Site (höchstens 500 je Aufruf): ändert sich eines zwischen
  Vorschau und Bestätigung, gibt es eine neue Vorschau, die die Änderung seit
  dem Vorschlag nennt (`changed_since_proposal`); ein seither entferntes Objekt
  wird übersprungen. Die Staging-Seite gilt wie im Paket. Dazu bindet der
  Zustand je Operation das Paar, wie es jetzt ist, den Abdruck des Site-Paars
  und die Verbindung; Aktion, Vorschlag und Auswahl binden die Argumente.
- `realign` (Neuabgleich eines *ungeklärten* oder `residual`-Paars) macht den
  Stand jeder Seite zum neuen Ausgangsstand (Entscheid 14 wörtlich): ein
  Unterschied, der jetzt besteht, gilt danach nicht mehr als Änderung. Vorschlag
  und Vorschau nennen das (`note`).
- «Anderer Inhalt» und ein gewähltes Paar anderen Inhalts werden als
  *ungeklärt* bestätigt; welche Seite gewinnt, fragt der Bericht (Vorrang 1)
  und entscheidet der Plan des Laufs (Zweig 8).
- Ein Vorschlag gilt so lange wie sein frühestes Paket (höchstens einen Tag)
  und gehört der Verbindung, die ihn machte (und damit ihrem Konto): über eine
  andere Verbindung, etwa den Hub statt der direkten, wird neu vorgeschlagen.
  Ein neuer Vorschlag einer Verbindung entfernt ihre früheren aus der Ablage
  (die Ablage kennt für Berichte kein Kontingent, wie für Pakete; so häufen
  sich wiederholte Vorschläge nicht für ihren Tag an).
- **Verwerfen darf jedes Konto** mit `manage_options` und Stufe Full, mit
  Token; das Protokoll der Werkzeugaufrufe (`ab_mcp_audit`) nennt das Konto,
  sofern die Protokollierung eingeschaltet ist.
  Bewusste Abweichung vom Eigentumsmuster von Entscheid 15 (Entscheid der
  Lead-Sitzung): Paare sind Daten der Site, nicht eines Kontos, und ein
  ausgeschiedener Administrator darf Paare nicht für immer festhalten. Ein
  Vorschlag bleibt an seine Verbindung gebunden.
- Eine Bestätigung macht höchstens 500 Paare; mehr in Teilen über `after`
  (die Antwort nennt `more_after`, auch die Meldung «nichts mehr übrig» einer
  wiederholten Bestätigung).
- Alles oder nichts **je Aufruf** durch eine Transaktion der Datenbank über
  die Paartabelle (`AB_MCP_Sync_Pairs::transaction()`): auch ein Prozess, der
  zwischen zwei Paaren endet, hinterlässt nichts. Dafür muss die Tabelle InnoDB
  sein; eine andere Engine (MyISAM, selten) lehnt mit dem Weg `ALTER TABLE …
  ENGINE=InnoDB` ab, bevor etwas geschrieben ist. Eine Auswahl über mehrere
  Aufrufe (mehr als 500) ist nicht als Ganzes atomar: die schon bestätigten
  Teile bleiben, wenn ein späterer scheitert.
- Zwei Aufrufe, die denselben Vorschlag zugleich fortsetzen, schreiben zwei
  Teile derselben Nummer; der Vorschlag gilt dann nicht mehr, Weg: neu
  vorschlagen, ein Aufruf nach dem anderen (selten).
- Die 45 s begrenzen die Bestandsaufnahme dieser Site je Aufruf, nicht den
  ganzen Aufruf: im letzten Aufruf folgen das Lesen der Pakete und die
  Zuordnung über beide Inventare (15 s dafür vorgesehen, nicht erzwungen).
- Grosse Sites: Ausgearbeitet wird ein Vorschlag im Speicher (die Zeilen
  beider Seiten); gemessen ist das bis zu rund 500 Objekten je Seite
  (Prüffälle), grosse Sites misst Z11c.
