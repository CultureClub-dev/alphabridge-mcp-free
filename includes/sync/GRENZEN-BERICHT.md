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
- **Verweise auf Objekte eines Paars** (Codex Runde 2 von Teil 2, b; Runde 3
  von Teil 2, B): «Gleich» verlangt zudem, dass jeder Verweis auf ein Objekt
  eines Paars auf der anderen Seite an derselben Stelle das andere Objekt
  desselben Paars nennt (`AB_MCP_Sync_Proposal::refs_agree()`, dieselbe Regel
  wie Zeile 6 des Berichts). Zwei Regeln, je nach Ziel: ein Ziel **ohne Paar**
  vergleicht allein die abgebildete Form, die es über seine Identität nennt
  (Entscheid 10; der Mapper seiner Site hat die Identität jedes Ziels
  hineingerechnet, ob das Paket die Zeile des Ziels hält oder nicht); ein Ziel
  **eines Paars** vergleicht die Kennung seines Paars, die die Paartabelle über
  die ID des Verweises nennt, ohne Zeile des Ziels (`places()`). Grund: die
  abgebildete Form nennt ein Ziel über seine Identität, und ein umbenanntes
  Objekt eines Paars, dessen Slug ein anderes Objekt übernimmt, hätte sonst
  dieselbe Form wie das andere. Darum vergleicht ein Teilpaket, das die
  Beiträge ganz, aber noch keine Terme, Seiten oder Menüs trägt, gleich wie
  ein vollständiges (Codex Runde 3 von Teil 2, B: eine Zwischenfassung las die
  Identität eines Ziels ohne Paar aus dessen Zeile und nannte ein Ziel ohne
  Zeile ungleich). Widerspricht ein Verweis, der das Objekt benennt (der
  Elternteil eines Posts; Ziel, Eltern-Eintrag oder Menü eines Menüeintrags),
  heisst es «unsicher» (eine Person wählt, die Bestätigung über `categories`
  nimmt es nicht); ein anderer Verweis (Terme, Beitragsbild, Blöcke) heisst
  «anderer Inhalt» und wird als *ungeklärt* bestätigt. Geprüft wird je Objekt
  der eigene Verweis: eine Seite unter einer neuen Elternseite, deren eigener
  Elternteil widerspricht, bleibt gegen diese Elternseite «gleich»; die
  Elternseite selbst heisst «unsicher» und wird nur durch eine Wahl gepaart
  (selten: Umbenennung mit Slug-Übernahme und darunter neue Seiten auf beiden
  Sites). Grenze (konstruiert): In einer Menge der kanonischen Form (Terme
  einer Taxonomie, Menüs eines Menüeintrags) trägt die abgebildete Form die
  Identitäten als Menge, ohne zu sagen, welche davon zu einem Paar gehört
  (`AB_MCP_Sync_Identity::map()` setzt je Verweis die Identität ein,
  `mapped_hash()` hasht die ganze Form). Tauschen auf einer Site ein Term
  eines Paars und ein Term ohne Paar derselben Taxonomie ihre Slugs (der
  gepaarte wird «sport», ein neuer «news» tritt an seine Stelle) und steht ein
  Beitrag auf beiden Sites genau in diesen zwei Termen, nennen beide Seiten
  dasselbe Paar und dieselbe Menge von Identitäten: «gleich», wie in 7111bc4,
  obwohl der ungepaarte Term ein anderer ist. Der gepaarte Term selbst
  erscheint in demselben Vorschlag oder Bericht als geändert, sofern das
  Paket seine Zeile trägt; in einem Teilpaket ohne die Term-Zeilen steht er
  nur als «nicht verglichen» (Codex Runde 4). Ein Paar für den zweiten Term
  hebt die Grenze auf.
- **Ein Ziel je Identität** (Codex Runde 2, A-1; Runde 3, 1): Die Identität
  ist eine Funktion der ganzen Site. `AB_MCP_Sync_Identity` liest in einem
  ersten Durchgang, was jedes Objekt nennt (Typ, Slug, Datei, Ziel, Verweis
  auf den Eltern-Eintrag, Theme, Sprache), seitenweise über die ganze Site,
  und rechnet im zweiten die Identitäten Ebene für Ebene der Eltern, jede aus
  den fertigen Identitäten der Ebene darüber; erst über die fertigen fragt es,
  ob ein zweites Objekt dieselbe hat. Nichts wird gemerkt, bevor es feststeht:
  die Reihenfolge des Lesens ändert keine Identität. Eine Identität, die ein
  zweites Objekt derselben Site auch hat, nennt keines von beiden; ein Verweis
  darauf bleibt unaufgelöst («anderer Inhalt»), und ein Objekt, dessen eigene
  Identität sie enthält, hat keine (ein Menüeintrag unter einem solchen
  Eltern-Eintrag, eine Seite unter einer solchen Elternseite heissen
  «unsicher»). Die Site sagt in der Zeile selbst, dass ein Objekt ein
  Zwilling ist und wie viele Objekte die Identität haben (`twin`, Codex
  Runde 4 und 5): Zwillinge paart nur eine gleiche abgebildete Form, und nur
  wo alle beider Sites im Vergleich vorliegen (unten, «Zwillinge über ihre
  Form»); sonst heisst jede Zeile «unsicher», auch wo ein Paket nur einen der
  Zwillinge hält oder der andere schon durch eine Wahl gepaart ist (der Rest
  wird nie «gleich»); ebenso eine Seite unter einem Zwilling, auch wo das
  Paket nur diesen einen Elternteil hält (Elternpfad `?`). Beispiele: zwei
  eigene Links «#» mit derselben Beschriftung unter demselben Eltern-Eintrag,
  zwei Posts eines Typs, Slugs und einer
  Sprache unter derselben Elternseite (ein Entwurf behält seinen Slug), zwei
  Anhänge derselben Datei und Sprache, zwei Terme einer Taxonomie, eines
  Slugs und einer Sprache (der Kern vergibt einen Slug je Taxonomie nur
  einmal; nur eine anders geschriebene Tabelle hat ihn zweimal). Eigene Links
  heissen nach Adresse **und** Beschriftung: «Privat» und «Firma» auf `#`
  sind zwei Einträge, ein Untereintrag, der von «Privat» zu «Firma» wechselt,
  ist ein anderer Eintrag («nur Staging» und «nur live»), nie «gleich»; eine
  umbenannte Überschrift ebenso. Ein Untermenü verschiedener Einträge
  («Privat» mit «Kontakt» und «Hilfe») ist «gleich».
- **Sprache** (Codex Runde 3, B2): Wo die Zeilen der Site sie nennen, gehört
  die Sprache zur Identität von Posts, Anhängen und Termen und zur Identität
  ihrer Zeile (`language`, fehlt ohne Sprache): bei Polylang die Taxonomien
  `language` und `term_language`, sonst die Tabelle `icl_translations` von
  WPML (gelesen, wo es sie gibt). So sind Übersetzungen mit gemeinsamem Slug
  (Polylang Pro, WPML) und übersetzte Medien derselben Datei je Sprache ein
  eigenes Objekt; Unterseiten haben einen ganzen Pfad. Grenzen: ein
  Sprach-Plugin, das die Sprache anderswo hält (eigene Tabellen, Optionen,
  Multisite je Sprache), wird nicht gelesen: dort bleiben gemeinsame Slugs
  doppelt (Unterseiten «unsicher») und übersetzte Medien derselben Datei
  doppelt (Verweiser «anderer Inhalt»), die sichere Seite; Weg: die Paare der
  Übersetzungen selbst wählen (`choices`). Nennt die Site für ein Objekt mehr
  als eine Sprache, hat es keine Identität (`language` `?`). Die Pakete beider
  Sites tragen die Sprache nur, wo beide dieselbe Fassung haben.
- **Grosse Sites** (Codex Runde 4, B): Der Index der Identitäten ist kompakt:
  je Post ein Satz von 43 Bytes (ID, Sprache, SHA-256 seiner Identität; bei
  einem Zwilling stattdessen, wie viele Objekte sie haben), je
  Term einer von 53 Bytes, sortiert und durch Halbieren gefunden; während des
  Aufbaus je Post einer von 51 Bytes mit der ID seines Elternteils, gezählt
  wird je SHA-256. Gemessen für 20 000 Seiten und 50 000 Anhänge, jeder mit
  einer Sprache von WPML: Aufbau unter 18 MiB, fertig rund 2,9 MiB
  (`SyncIdentityTest`, ohne WordPress; auf MariaDB mit WordPress 17,3 MiB in
  0,3 s), vorher rund 150 MiB. Er wird mit der Zeitgrenze der
  Bestandsaufnahme in Teilen aufgebaut, Seite für Seite, und je Lauf einmal:
  ein Vorschlag hält ihn bei seinen Teilen, eine Reihe von Paketen beim
  Paket, in dem er fertig wurde (in Scheiben zu 8 MiB, eine Datei der Ablage
  hält höchstens 16 MiB; das Paket meldet ihre Bytes an); die Folgeaufrufe
  nehmen ihn auf und fragen die Datenbank nicht mehr. Ein Exportaufruf braucht so nicht mehr Speicher
  als vor dem Index (gemessen, 4000 Objekte je Paket: 71,7 und 68,8 MiB
  gegen 71,8 und 71,8 MiB). Grenzen: Der Index eines Laufs ist die Site, wie
  sie war, als der Lauf ihn las; ein Objekt, das während eines Vorschlags
  oder einer Reihe von Paketen neu entsteht, umbenannt oder verschoben wird,
  heisst so, wie der Index es sagt (ein neuer Post hat keine Identität, ein
  Verweis darauf heisst «anderer Inhalt», seine Zeile trägt keine Sprache);
  Weg: nach Änderungen neu exportieren und neu vorschlagen (wie bei der
  Bestandsaufnahme in Teilen, die sich ebenso über Aufrufe erstreckt). Auf
  einer grossen Site können die ersten Pakete einer Reihe kein Objekt halten
  (nur den Index); sie gehören zur Reihe und schaden dem Vorschlag nicht. Ist
  das Paket mit dem Index weg (vor dem Ende der Reihe entfernt), geht die
  Reihe nicht weiter (`ab_mcp_sync_export_index`, Weg: neue Reihe). Der
  zweite Durchgang (die Ebenen) läuft in einem Aufruf, nicht in Teilen
  (gemessen 0,2 s für 70 000 Posts).
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
- Die zweite Form erhält den Wert (Codex Runde 3, A1): Nur eine Ziffernfolge
  in ihrer schlichten Schreibweise (keine führende Null, nicht 0) wird über
  die Identität genannt; jeder andere Teil des Werts (ein Trenner, 0, eine Zahl
  mit führender Null, eine, die nichts trifft) bleibt, und der Marker sagt, ob
  der Wert eine Zahl oder ein Text war. `"007"` gegen `"7"`, `"7,0,9"` gegen
  `"7,9"` und die JSON-Zahl `7` gegen den Text `"7"` heissen so «anderer
  Inhalt».
- Grenzen der möglichen Verweise: Ein Objekt, das **beides** enthält,
  verschobene Bild-IDs und eine gleiche Zahl, die auf den beiden Sites
  verschiedene Objekte trifft (etwa eine Galerie und `_stock` 5), heisst
  weiter «anderer Inhalt» und wird *ungeklärt* bestätigt (die sichere Seite;
  solche Objekte sperrt Entscheid 12 ohnehin, soweit die verschobenen Bilder
  im Umfang der Übernahme liegen). Grund: dafür müsste je Zahl verglichen
  werden, und ein Paket trägt einen möglichen Verweis nur mit seiner Stelle,
  nie mit seinem Wert (Vertrag A von Zweig 5). Umgekehrt bleibt ein falsches
  «gleich», wo zwei verschiedene Zahlen in derselben Schreibweise auf ihren
  Sites Objekte derselben Identität treffen, ohne Verweise zu sein (ein Preis
  7 hier, 107 dort, und Post 7 hier ist derselbe wie Post 107 dort;
  konstruiert). Das kann auch ein Objekt treffen, das Entscheid 12 nicht
  sperrt; das Paar wird dann aktiv bestätigt, ein späterer Lauf sieht den
  Unterschied als Änderung einer Seite. Mögliche Verweise im Inhalt (Blöcke
  ohne Schema, Shortcodes), in Optionen und Widgets werden nicht abgebildet:
  dort bleibt der Vergleich wie bisher (andere IDs heissen «anderer Inhalt»).
- Ein Verweis auf ein Ziel ohne Identität (ein Post ohne Slug, ein gelöschtes
  Ziel, ein Term, dessen ID noch in zwei Taxonomien steht) gibt keine
  abgebildete Form: das Objekt heisst «anderer Inhalt». Dafür tragen die
  Zeilen eines Pakets jetzt `mapped` und `mapped_possible` und die
  Menüeinträge `target` und `parent` (SHA-256 der Identität, nie ein Wert);
  das Paketformat ist darum zwischen den Fassungen nicht gemischt lesbar
  (beide Sites gleiche Fassung; eine ältere Zeile ohne `mapped` heisst
  «anderer Inhalt»).
- Keine Paarung durch Ausschluss: Nur ein Objekt je Seite derselben Identität
  auf demselben Pfad ist ein Paar; mehrere auf einem Pfad sind Zwillinge ihrer
  Site, die nur eine gleiche abgebildete Form paart (unten), sonst heissen sie
  «unsicher». Der Pfad nennt jeden Elternteil mit Typ,
  Theme, Slug und Sprache (Codex Runde 4): `kontakt` unter `team` (de) gegen
  `kontakt` unter `team` (en) ist ein anderer Pfad, «unsicher», nie «anderer
  Inhalt». Dieselbe Identität auf einem anderen Elternpfad (`/team/kontakt`
  gegen `/shop/kontakt`), ein Post ohne Slug (Entwürfe) und ein Menüeintrag,
  dessen Ziel oder Eltern-Eintrag keine Identität hat, heissen «unsicher»
  (`uncertain`) und werden nur durch eine ausdrückliche Wahl gepaart, nie über
  eine Kategorie bestätigt. Eine verschobene Seite muss die Person darum selbst
  zuordnen.
- Ein **unvollständiger Elternpfad** führt nach «unsicher», wo beide Seiten
  Kandidaten derselben Identität haben (Codex Runde 2, A-2): eine Elternseite,
  die die Bestandsaufnahme ihrer Seite nicht hält (nicht gelesen, in einem
  fehlenden Teilpaket, gehalten), keinen Slug hat, deren Typ, Slug, Sprache
  und Eltern ein zweiter Post derselben Seite auch hat oder deren Zeile ihre
  Site als Zwilling nennt, steht im Pfad als `?`; `?` ist nie ein gemeinsamer Pfad beider Seiten. Hat die andere
  Seite keinen Kandidaten dieser Identität, bleibt das Objekt «nur live» oder
  «nur Staging» (Codex Runde 3, C1; selten, keine Fehlpaarung: es gibt nichts
  zu paaren).
- Menüeinträge werden über den Slug ihres Menüs, Typ, Objekttyp, ihr Ziel
  (eigene Links: Adresse und Beschriftung) und ihren Eltern-Eintrag
  zugeordnet, nie über die Position (umsortiert ist dasselbe Ziel dasselbe
  Objekt). Haben zwei Einträge eines Menüs dieselbe Identität (dasselbe Ziel
  oder dieselbe Überschrift zweimal unter einem Eltern-Eintrag), trennt sie
  nur ihr Platz: sie heissen «unsicher», auch wo ihre ganzen Formen auf beiden
  Seiten eindeutig wären, und was unter ihnen hängt, ebenso (Codex Runde 3,
  A2). Ein Ziel ausserhalb der Bestandsaufnahme ohne Identität (ein
  gelöschter Post) macht den Eintrag «unsicher».
- **Zwillinge über ihre Form** (Codex Runde 3, B3; in Runde 5 wiederhergestellt,
  nur als positiver Beleg): Mehrere Kandidaten derselben Identität auf einem
  Pfad werden über eine gleiche abgebildete Form gepaart, zuerst `mapped`, dann
  `mapped_possible`, wo diese Form unter dem Rest auf jeder Seite genau einmal
  vorkommt und beide Objekte ganz gelesen sind. Das gilt nur, wo alle
  Zwillinge beider Sites im Vergleich vorliegen: so viele auf dem Pfad, wie die
  Zeile jeder Site sagt (`twin`, gezählt vom Index der Identitäten). Was danach
  bleibt, heisst «unsicher», nie «gleich» und nie «anderer Inhalt» durch
  Ausschluss; ebenso Zwillinge gleicher Form, ein Paket mit nur einem Teil der
  Zwillinge, der Rest nach einer gewählten Paarung, ein gehaltener oder nicht
  gelesener Zwilling und Menüeinträge (nur ihr Platz trennt sie, Codex
  Runde 3, A2). Gewöhnlich ist das bei einer veröffentlichten Seite und einem
  Entwurf mit ihrem Slug: WordPress prüft den Slug eines Entwurfs nicht auf
  Eindeutigkeit (`wp_unique_post_slug()`), so entstehen sie etwa als Kopie
  einer Seite. Unverändert sind beide «gleich»; ist eine geändert, ist die
  andere «gleich» und die geänderte «unsicher». Die Kategorie «mehrdeutig»
  entfällt (seit Runde 4). Grenze C: Unterscheiden sich beide Zwillinge
  zwischen den Sites oder gleichen sie einander, wählt die Person (`choices`;
  selten, die sichere Seite); ebenso bei Übersetzungen mit gemeinsamem Slug,
  wo das Sprach-Plugin nicht gelesen wird (Polylang und WPML werden gelesen),
  und bei von Hand oder durch ein Plugin doppelt geschriebenen Slugs, soweit
  ihr Inhalt sie nicht trennt.
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
  und entscheidet der Plan des Laufs (Zweig 8). Ein gewähltes Paar beginnt
  *aktiv* nur, wo beide ganz gelesen sind, die abgebildeten Formen gleich
  sind und die Verweise auf Objekte eines Paars einander nennen
  (`AB_MCP_Sync_Proposal::alike()`, Codex Runde 2 von Teil 2, b); ein Eintrag
  eines Vorschlags aus einer Fassung vor dieser Regel trägt die Verweise
  nicht und wird gewählt als *ungeklärt* bestätigt (ein Vorschlag gilt
  höchstens einen Tag).
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
- Die 45 s begrenzen die Bestandsaufnahme dieser Site je Aufruf (mit dem
  Index der Identitäten), nicht den ganzen Aufruf: im letzten Aufruf folgen das Lesen der Pakete und die
  Zuordnung über beide Inventare (15 s dafür vorgesehen, nicht erzwungen).
- Grosse Sites: Ausgearbeitet wird ein Vorschlag im Speicher (die Zeilen
  beider Seiten); gemessen ist das bis zu rund 500 Objekten je Seite
  (Prüffälle), grosse Sites misst Z11c.

## Teil 2: der Bericht (`wp_site_diff`)

### Vorprüfung und Pakete

- Die Version von WordPress der Staging-Site steht seit diesem Teil im
  signierten Manifest (Feld `wordpress`, wie `wp-includes/version.php` sie
  setzt; `AB_MCP_Sync_Manifest::WORDPRESS`). Ein Manifest ohne das Feld (ein
  Paket des Zwischenstands `81db5c5`, den keine Installation hat) lehnt die
  Prüfung des Formats ab (`ab_mcp_sync_manifest_shape`, Weg: beide Sites auf
  dieselbe Fassung, neu exportieren; Grenze C wie in Teil 1). Liegt in einer
  Reihe ein Update zwischen zwei Paketen, gilt die höhere Version. Ist
  WordPress live neuer als auf Staging, berichtet der Bericht trotzdem
  (abgelehnt wird nur die neuere Staging-Site, Konzept A17).
- Ein Customizer-Changeset darf ausserhalb des Customizers kein Konto
  bearbeiten (WordPress: `edit_post` wird `customize`, eine Fähigkeit, die
  keine Rolle als solche hat; geplante zudem `do_not_allow`). Der Export hält
  darum jeden Changeset zurück (Entscheid 9: nicht Öffentliches nur mit
  `edit_post`), und der Bericht nennt den Untertyp `customize_changeset` in
  `staging.incomplete` mit seiner Sperre (`post_type:customize_changeset`,
  Z8d) statt eines Eintrags je Changeset. Changesets dieser Site erscheinen
  als «nicht verglichen» mit derselben Sperre. Übernommen wird in Z8 keiner;
  nur die Anzeige ist gröber.

### Zustände einer Seite

- «Gelöscht» auf Staging nur aus einer vollständigen Bestandsaufnahme der
  Staging-Seite (Art und Untertyp, bei unbekanntem Untertyp jeder Untertyp
  der Art, `AB_MCP_Sync_Report::complete()`); sonst «nicht verglichen». Der
  Papierkorb gilt als gelöscht (kein Status ersetzt eine Löschung), ein
  Entwurf `auto-draft` als kein Inhalt. Ein Objekt ohne Paar im Papierkorb
  wird nicht angelegt; liegt die Elternseite einer neuen Seite im Papierkorb,
  ist ihr Pfad `?` und sie «unsicher» (selten).
- Gilt der Name eines gepaarten Objekts seit der Paarung auf dieser Site als
  Zugangsangabe (Regeln `ab_mcp_redact_keys`), fehlt es in ihrer
  Bestandsaufnahme; der Bericht kennt es über Art und SHA-256 seiner
  Kennung (`b9_refs` der Teile) und nennt es «nicht verglichen», nie
  gelöscht. Der Eintrag nennt dann keine Seite beim Namen (Codex Runde 1 von
  Teil 2, A): beide Seiten erscheinen wie eine zurückgehaltene Zeile
  (`held: credential_name`, SHA-256 der Kennung), auch wo die Staging-Zeile
  den Namen noch trägt (der Import hält eine Zeile nach den Regeln seines
  Tages zurück, der Bericht nach denen von heute) und wo sie fehlt (statt der
  Kennung des Paars). Auf der Staging-Seite fehlt dafür ein solcher
  Nachweis im Paket (es zählt nur `credentials.names`): ein gepaartes
  Objekt, dessen Name dort seither eine Zugangsangabe ist, erscheint als
  «auf Staging gelöscht – Übernahme ab Z8d», ohne Schreibweg (konstruiert:
  die Regeln einer Site ändern sich nach der Paarung).
- Ein Paar einer Art, die die Bestandsaufnahme dieser Fassung nicht liest
  (etwa `attachment` vor Zweig 10), heisst auf beiden Seiten «nicht
  verglichen», nie gelöscht.

### Ordnung (Regel 0, Vorrang 1–4)

- Vorrang 2 (*gelöscht übernommen*) tritt in Z8 nicht ein. Der Bericht
  entscheidet ihn trotzdem nach dem Konzept: live wieder da (vorhanden und
  nicht im Papierkorb; ein Post im Papierkorb gilt weiter als gelöscht, Codex
  Runde 1 von Teil 2, C) → der Live-Stand ist die Basis, danach die Tabelle
  mit der Staging-Seite; auf Staging gelöscht → nichts; auf Staging wieder da
  → «auf Staging wiederhergestellt», gesperrt bis Z8d (`*:restore_deleted`).
  Er schreibt kein Paar: «Paar aktiv, B := Live-Stand» (Vorrang 2), «B :=
  gemeinsamer Stand» (Zeile 6), «Paar gelöscht übernommen» (Zeile 8) und
  «Paar schliessen» (Zeile 10) folgen mit dem Lauf beziehungsweise mit
  `wp_site_pairs action=discard`; der Bericht nennt den Weg.
- Zeile 10 unterscheidet nach dem Konzept: beide Objekte entfernt →
  `deleted_both` mit dem Weg `wp_site_pairs action=discard`; mindestens eines
  im Papierkorb → `deleted_both_trash`, der Übergang «gelöscht übernommen»
  folgt mit Z8d, das Paar bleibt bis dahin, wie es ist (Entscheid 22), und
  der Bericht bietet kein Verwerfen an (Codex Runde 1 von Teil 2, B).
- Regel 0: «Rest entfernt» heisst nur, dass das Objekt des Rests auf dieser
  Site fehlt. Liegt es hier im Papierkorb, bleibt es der Rest («Rest nach
  Rückweg oder Fehler», mit dem Grund), ohne den Weg eines entfernten Rests
  (Codex Runde 1 von Teil 2, C).
- Zeile 6 («beidseitig gleich geändert») verlangt neben derselben
  abgebildeten Form, dass jeder Verweis auf ein Objekt eines Paars auf der
  anderen Seite an derselben Stelle das andere Objekt desselben Paars nennt
  (`refs_agree()`, Codex Runde 1 von Teil 2, A): die abgebildete Form nennt
  ein Ziel über seine Identität, und ein umbenanntes Objekt eines Paars,
  dessen Slug ein anderes Objekt übernimmt, hätte sonst dieselbe Form wie
  das andere. Sonst heisst es Konflikt (Zeile 5, eine Person wählt). In einer
  Menge der kanonischen Form (Terme einer Taxonomie, Menüs eines
  Menüeintrags) zählt die Reihenfolge nicht: der Bericht vergleicht die
  Kennungen der Paare der Stelle in einer Ordnung, mit so vielen leeren
  Einträgen wie Ziele ohne Paar; die Ziele ohne Paar vergleicht allein die
  abgebildete Form (Codex Runde 2 von Teil 2, B: ein Beitrag in «news» eines
  Paars und in «events» ohne Paar auf beiden Seiten bleibt Zeile 6; Runde 3,
  B: auch aus einem Teilpaket ohne die Zeile von «events», weil weder die
  Form noch die Paartabelle eine Zeile des Ziels braucht). Nennt eine Seite
  ein Ziel eines Paars und die andere an seiner Stelle ein Objekt ohne Paar,
  auch eines derselben Identität, ist es nicht gleich. Eine Liste vergleicht
  weiter je Stelle; eine Stelle ohne Ziel eines Paars entscheidet die
  abgebildete Form. Grenze (konstruiert) wie in Teil 1 («Verweise auf Objekte
  eines Paars»): der Slug-Tausch zwischen einem Term eines Paars und einem
  ohne Paar in derselben Menge bleibt Zeile 6.
- Vorrang 1 (*ungeklärt*): Hält eine Zugangsangabe eine Seite (eine
  zurückgehaltene Zeile, oder der Name gilt hier seit der Paarung als
  Zugangsangabe), heisst der Eintrag «nicht verglichen» mit Grund und bietet
  keine Wahl an, wie in den übrigen Zuständen eines Paars (Codex Runde 2 von
  Teil 2, a). Grund: ein solches Objekt wird von Hand übernommen (wie beim
  aktiven Paar), eine Wahl hätte keinen Weg. Eine sonst
  nicht verglichene Seite eines ungeklärten Paars (nicht ganz gelesen,
  unvollständige Staging-Seite) behält die Wahl; vor dem ersten Schreiben
  prüft der Lauf nach Entscheid 16 alle Paare seines Plans frisch (Zweig 8).
- Zeile 9 (live gelöscht, Staging geändert): Standard «live behalten»; die
  Wahl «aus Staging wiederherstellen» braucht ein neues Live-Objekt unter
  einem bestehenden Paar und ist bis Z8d gesperrt (`*:restore_deleted`).
- Ein Paar mit `override` vergleicht die Live-Seite gegen den Sollwert
  (`base_live` ist sein Hash, Entscheid 17) und nennt ihn («taken over with
  post_status draft»). «Beidseitig gleich geändert» heisst dann: beide Seiten
  haben jetzt dieselbe abgebildete Form, der Sollwert gilt live nicht mehr.

### Gesperrtes

- Rollen erscheinen als eine Option (`option:{prefix}user_roles`, Z8d) mit
  «gleich» oder einer Änderung, nicht mit der Abweichung je Rolle und Recht
  (Entscheid 29): das Paket trägt Hashes, keine Werte. Die Aufstellung je
  Rolle folgt mit der Rollen-Engine (Z8d); Theme-Mods erscheinen je Schlüssel
  (je Schlüssel ein Objekt).
- Ein Menü, aus dem auf Staging ein Eintrag entfernt wurde, sperrt das Menü
  und jeden Eintrag darin auf beiden Seiten, auch einen auf Staging neuen
  (`menu:remove_item`). Auch ein Rest (`residual`), den Staging entfernt hat,
  sperrt sein Menü (die sichere Seite); der Rest selbst bekommt keine Sperre
  (Regel 0).
- Ein Widget-Bereich: entfernt heisst, eine Instanz seiner Basis fehlt auf
  Staging. Die Instanzen der Basis hält das Paar seit diesem Teil in
  `base_extra` (`instances`: `live`, `staging`), geschrieben von
  `wp_site_pairs confirm`; ein Neuabgleich ersetzt sie und lässt die übrigen
  Schlüssel (die Zuordnung eines Laufs) stehen. Ein Paar ohne sie (ein
  Entwicklungsstand vor diesem Teil, keine Installation) und eine Zeile ohne
  `instances` sperren einen auf Staging geänderten Bereich als Ganzes (fail
  closed; Weg: das Paar verwerfen und neu paaren).
- Neue Terme mit Meta (`term:create_with_meta`): die Zeile zählt die
  Meta-Zeilen ihrer Form (`meta`); eine Meta-Zeile, die als Zugangsangabe
  ausgelassen ist (`b9` mit `meta:`), zählt mit, eine Zeile ohne die Zahl
  sperrt (fail closed). Ob ein neuer Post einen Meta-Schlüssel trägt, dessen
  Recht erst am Objekt prüfbar ist (`post:create_with_object_meta`,
  Entscheid 28), und ob eine Seite der obersten Ebene bei Menü-Automatik
  veröffentlicht würde (Entscheid 21), entscheidet die Gesamtvorprüfung des
  Laufs (Zweig 9) auf dieser Site; der Bericht nennt solche Posts «anlegen».
- Zusätzliches CSS: anlegen ist gesperrt (`custom_css:create`); ändern nur,
  wo diese Site das CSS mit ihrem Stylesheet verknüpft (`linked`), sonst
  ebenso gesperrt (das Schreiben setzte die Verknüpfung).

### Abhängigkeiten

- Gefolgt wird den notwendigen Verweisen der Staging-Zeile (`refs`: Post-
  und Term-IDs; Autoren gehen über das Login, Benutzer sind Gruppe B). Jeder
  Verweis in Z8 ist «strong»; schwache Verweise (Elternteil eines Anhangs)
  kommen mit den Medien.
- Bis zu den Medien (Zweig 10) ist kein Anhang in der Bestandsaufnahme:
  jedes Objekt, das auf einen Anhang verweist (Beitragsbild, Bildblock,
  Galerie), wartet («not in its inventory»). Der Bericht unterscheidet einen
  Anhang nicht von einem anderen nicht gelesenen Objekt; der Grund nennt
  beides und die Stelle (`at`).
- Gegenseitige Verweise sperren nur unter Objekten, die im Lauf angelegt
  würden (eine Kante zeigt nur auf ein anzulegendes Ziel); bestehende Objekte,
  die einander nennen, nennen sich über ihre Paare. Ein neues Objekt, das
  sich selbst über seine ID nennt, ist gesperrt (seine ID gibt es erst nach
  dem Anlegen; selten). Ein Ziel mit aktivem Paar und vorhandenem
  Live-Objekt gilt als benannt, auch wo es selbst gesperrt ist (es bleibt
  live, wie es ist); ein *ungeklärtes* Paar, ein gelöschtes Ziel, ein Ziel
  ohne Paar, das nicht angelegt wird (gesperrt, beidseitig neu, nicht
  verglichen, gehalten), lassen warten. Ein Rest (`residual`) als Ziel gilt
  mit seiner Zuordnung als benannt.

### Ablage, Seiten, Grösse

- Der Bericht ist ein Schnappschuss: Seiten mit `cursor` liest er aus der
  Ablage, nicht neu. Er gilt so lange wie sein frühestes Paket (höchstens
  einen Tag) und gehört Konto und Verbindung, die ihn machten; ein neuer
  Bericht derselben Verbindung ersetzt den früheren. `report_hash` bindet
  Pakete, Abdruck des Paars, Quelle, Kanon, Versionen und die Teile der
  Einträge, nicht Verbindung und Zeit.
- Wie der Vorschlag hält der letzte Aufruf beide Bestandsaufnahmen im
  Speicher; gemessen in den Prüffällen, grosse Sites misst Z11c.

## Zweig 7 (Bestandsaufnahme)

Die lesende Bestandsaufnahme der übrigen Arten und von Gruppe U
(`AB_MCP_Sync_Env`; Paket-Zweck `env`, `wp_site_diff` mit `environment`).
Was sie liest, steht in der Klasse; hier, was sie nicht leistet und warum.

### Was verglichen heisst

- «equal» oder «differs» nach Inhalt gibt es nur für Must-Use-Plugins und
  Drop-ins (SHA-256 der Datei). Die Werte von Gruppe U (Versionen von PHP
  und Datenbank, Objekt-Cache, `.htaccess`-Block und Must-Use-Lader als
  SHA-256, Konstanten) und die Sprache zeigen beide Seiten: «same_value»,
  wo der Wert auf beiden gleich ist, sonst «differs» (Codex-Runde 1, B4).
  Grund: gleiche PHP-Version heisst nicht gleiches PHP (Erweiterungen,
  Einstellungen des Hosters); der Bericht behauptet nur, was er gelesen hat,
  den Wert. «same_value» steht neben «counted» (gezählt, Inhalt nicht
  verglichen) und hat keine Etappe: diese Werte übernimmt keine Etappe,
  sie ändert der Hoster. Plugins, Themes und WordPress mit gleicher Version, Tabellen
  mit gleichen Zeilen und gleichem Schema, Wurzeln mit gleicher Zahl und
  Grösse heissen «counted», mit `contents: not compared` und der Etappe des
  Inhaltsvergleichs (Z8b für Dateien und Code, Z8d für Tabellen). Grund:
  gleiche Version oder Zahl sagt nichts über veränderte Dateien oder Zeilen
  (Befund R2-18). Eine abweichende Zahl oder Version heisst «differs»: sie
  belegt einen Unterschied.
- Die Sprache (`WPLANG`) wird nur gezeigt (Aktion `none`, «same_value» oder
  «differs»): sie ist eine Option und gehört nach der Zuständigkeitsregel
  der Options-Art; ihre Dateien zählt die Wurzel `languages`.
- Nichts davon ist übernehmbar. Code, Dateien und Tabellen tragen eine
  Sperre mit Grund und Etappe (`env:plugin`, `env:theme`, `env:wordpress`,
  `env:mu_plugin`, `env:mu_files`, `env:languages`, `env:fonts`,
  `env:uploads`, `env:table`); Drop-ins und Gruppe U tragen immer die
  Aktion `host` («change at the host»), auch ein gleiches Drop-in und ein
  gleicher Wert: was dort zu ändern ist, ändert der Hoster. Die Sperren stehen in `AB_MCP_Sync_Env::LOCKS`, nicht in
  `AB_MCP_Sync_Kinds`: keine Art des Datenbank-Vertrags beansprucht sie, und
  die Schlüssel mit `env:` stossen mit keinem dort zusammen.
- Die Plugin-Version kommt aus dem Kopf der Datei (`get_plugins()`), die
  Theme-Version aus `WP_Theme::get()`; ob etwas läuft, aus den gespeicherten
  Zeilen `active_plugins`, `stylesheet` und `template` ohne Filter. Ein
  Plugin, das Code zur Laufzeit abschaltet, zeigt sich hier als aktiv.
  Netzwerkweit aktive Plugins gibt es nicht: Multisite ist abgelehnt.

### Wurzeln und Dateien

- Gezählt werden reguläre Dateien und ihre Grössen; eine Verknüpfung wird
  nicht verfolgt und als `skipped` gezählt, ebenso was weder Datei noch
  Ordner ist. Ein Ordner, der sich nicht lesen lässt, macht die ganze Wurzel
  «not compared» (fail closed), auch wo der Rest gezählt wäre.
- `uploads` zählt die Dateien ausserhalb der Dateilisten der Anhänge
  (`AB_MCP_Sync_Media::reverse()`, genau die Pfade von
  `wp_delete_attachment_files()`); `attached` nennt, wie viele Dateien
  einer Liste angehören. Ausgelassen sind `fonts/` (eigene Wurzel), der
  Undo-Ordner und die geschützte Ablage von AlphaBridge (`ab-mcp-undo`,
  `ab-mcp-store-*`). Caches und Sicherungen anderer Plugins unter
  `uploads` werden mitgezählt: ohne Wissen über das Plugin ist ein Ordner
  nicht als Cache zu erkennen, und ein Raten würde Dateien verschweigen.
- Seitenweise: Die Zählung geht in der Reihenfolge von `order()` (Ordner
  zuerst in die Tiefe, Namen nach `strcmp()`) und setzt nach dem zuletzt
  gezählten Pfad fort; die Uhr wird alle 64 Einträge gefragt, so kommt jeder
  Aufruf mindestens 64 Einträge weiter. Eine Datei, die zwischen zwei
  Aufrufen vor dem Fortsetzungspunkt entsteht oder verschwindet, fehlt oder
  zählt nach dem Stand ihres Teils; die Zahl ist ein Stand über die Dauer der
  Zählung, kein Schnappschuss.
- Die Dateilisten der Anhänge liest jeder Aufruf neu, der in `uploads`
  zählt. Mehr als 500 000 Pfade, oder ein Lesen, das mehr als die Hälfte des
  verbleibenden Budgets braucht, macht `uploads` «not compared»
  (`attachments_too_many`); eine Liste, die sich nicht ganz lesen lässt,
  ebenso (`attachments_unreadable`). Grund: ohne die vollständige Liste wäre
  «ausserhalb der Anhänge» geraten. Messung grosser Mediatheken mit Z11c.
- Entscheidet Code, wo die Uploads oder Schriften liegen (Filter auf
  `upload_dir`, `upload_path` oder `font_dir`), wird die Wurzel nicht
  gezählt (`by_code`), wie bei den Anhängen (`attachment:uploads_by_code`).
- `mu-plugins` als Wurzel zählt, was WordPress dort nicht selbst lädt
  (Unterordner, andere Dateien); die PHP-Dateien der obersten Ebene sind je
  eine Zeile mit SHA-256, ohne den Lader von AlphaBridge (Gruppe U). Was ein
  Must-Use-Plugin aus einem Unterordner lädt, ist darum nur gezählt.
- Die Wurzel `languages` ist `WP_LANG_DIR`, wie WordPress sie beim Laden
  festlegt; Sprachdateien in Plugin- oder Theme-Ordnern gehören zu deren
  Code.

### Tabellen

- Gelesen werden nur Name, `COUNT(*)` und `SHOW CREATE TABLE`; keine Zeile.
  Das Schema wird ohne das Präfix der Site und ohne `AUTO_INCREMENT=`
  verglichen; andere Unterschiede der Erzeugung (Zeichensatz der Verbindung,
  Version von MariaDB oder MySQL, `ROW_FORMAT`) lassen ein gleiches Schema
  «differs» heissen. Grund: lieber ein Unterschied zu viel als eine
  Gleichheit, die nicht belegt ist.
- `COUNT(*)` läuft je Tabelle in der Zeit des Aufrufs; eine sehr grosse
  Tabelle ohne schnellen Zähler (InnoDB zählt die Zeilen ab) kann einen
  Aufruf über das Budget dehnen, weil die Uhr zwischen den Tabellen gefragt
  wird. Ausgenommen sind die Tabellen von `$wpdb->tables()` und von
  `AB_MCP_Sync_Schema`; Tabellen eines anderen Präfixes sieht die
  Bestandsaufnahme nicht.
- Ob eine Tabelle Betriebsdaten hält (Konzept Abschnitt 3: `*_log`,
  `*_sessions`), sagt der Bericht noch nicht: Klassifikation und Adapter
  sind Z8d.

### Gruppe U und Konstanten

- Die Konstanten sind eine feste Liste ohne Geheimnisse
  (`AB_MCP_Sync_Env::CONSTANTS`); zusätzlich werden nie die Namen von
  `NEVER` (Datenbankzugang, Schlüssel und Salze) und keine Namen gelesen,
  die `AB_MCP_Redactor::redacts_key()` mit den Regeln der Site
  (`ab_mcp_redact_keys`) schwärzt. Ein Text, der kein kurzes einfaches Wort
  ist (ein Pfad, eine Adresse), steht nur als SHA-256 im Paket und heisst im
  Bericht «not shown»; zwei Sites vergleichen ihn so ohne Klartext. Ein
  SHA-256 eines kurzen Werts lässt sich erraten; die Liste nimmt darum keine
  Konstante auf, deren Wert vertraulich ist.
- Der `.htaccess`-Block ist der Text zwischen `# BEGIN WordPress` und
  `# END WordPress` neben WordPress (`ABSPATH`). Liegt die Site in einem
  anderen Ordner als WordPress (`home` und `siteurl` mit anderem Pfad), wird
  er nicht gelesen («not compared»): `get_home_path()` fragt das Skript der
  Anfrage. Eine Regel des Servers ausserhalb dieser Datei (nginx) sieht die
  Bestandsaufnahme nicht.
- Die Datenbankversion ist die des Servers (`db_server_info()`), nicht die
  der Verbindung.

### Pakete und Teile

- Eine Bestandsaufnahme der Umgebung ist eine Reihe von Paketen; jedes
  beginnt mit seiner Seite (`walk`, `seq`, `last`), die mit den Zeilen
  signiert ist. Der Bericht nimmt nur die ganze Reihe in ihrer Reihenfolge
  (Teile 1 bis n, eine `walk`, der letzte zuletzt). So lässt sich eine
  Reihe nicht aus zwei Lesungen zusammensetzen; eine ganze ältere Reihe
  innerhalb ihres Tages bleibt gültig (wie ein Paket des Inventars).
- Die Zeilen der Site (Kern, Plugins, Themes, Must-Use-Plugins, Drop-ins,
  Gruppe U) stehen immer im ersten Teil; eine Site mit mehr als rund 4900
  Plugins und Themes zusammen überschreitet die Grenze eines Pakets
  (5000 Zeilen) und wird mit dem Weg der Paketgrösse abgelehnt.
- Die Umgebung dieser Site liest der Bericht in Teilen der Ablage (`v`),
  nach dem Inventar; ein Aufruf, dem weniger als 10 Sekunden seines Budgets
  bleiben, liest sie im nächsten Aufruf. Ohne `environment` liest er sie
  nicht und nennt die Umgebung als einen Eintrag «not compared» mit dem Weg.
- Die Zeilen eines Pakets der Umgebung prüft der Import auf ihre Struktur
  (`AB_MCP_Sync_Env::shaped()`) und ihre Namen auf Zugangsdaten nach den
  Regeln dieser Site (siehe «Namen»); was er hält, steht in seiner Liste
  `held` wie beim Inventar. Werte von Konstanten kommen nur aus der festen
  Liste.
- Ein Paket gehört dem Konto und der Verbindung (dem Token von MCP), die
  den Import begannen (`GRENZEN-PAKET.md`); ein Bericht aus Paketen eines
  anderen Tokens desselben Kontos lehnt mit `ab_mcp_sync_import_unknown` ab.
- Ein Folgeaufruf eines Imports, der einen anderen Zweck nennt als den des
  Beginns, wird abgelehnt (`ab_mcp_sync_import_purpose_other`, mit Weg);
  das Paket bleibt, wie es war (Codex-Runde 1, A3).

### Namen

- Namen der Site in der Umgebung (Plugins, Themes, Must-Use-Plugins,
  Tabellen) gehen als Ganzes (ein Pfad mit seinen Ordnern, eine Tabelle mit
  ihrem ganzen Namen) durch dieselbe Prüfung, die der Import über die Namen
  einer Zeile des Inventars legt (`AB_MCP_Sync_Env::held()`: die
  Inhaltsprüfung von `AB_MCP_Sync_Credentials` und das Netz über den rohen
  Namen, mit `ab_mcp_redact_keys`). Ein Name mit einem eingeschriebenen
  Feld einer Zugangsangabe (`password=…`, `password:…`, auch kodiert, etwa
  in base64) oder einer, der sich nicht zu Ende lesen lässt, steht in
  Paket, Bericht, Ablage und Antwort nur als SHA-256 (`sha256:…`) mit
  `held` (`credential_field`, `unreadable_name`); beide Sites bilden
  denselben SHA-256, so bleibt der Vergleich (ein gleiches Must-Use-Plugin
  heisst weiter «equal»). Codex-Runden 1 und 2, A1.
- Den Doppelpunkt ohne Anführungszeichen (`password:…`) erkennt das Netz
  nur für Namen der Umgebung (`AB_MCP_Sync_Net::over_name()` mit `$bare`,
  Muster `BARE`), an jeder Stelle des Namens, auch nach `-` oder `.`. Grund:
  ein Datei- oder Tabellenname ist keine Prosa; bei den Namen des Inventars
  (darunter Titel) bleibt die Regel der Werte, nach der «Token: x» in Prosa
  nichts hält. Ohne Wert danach (`password:`) hält der Doppelpunkt nichts.
- Nicht angewandt wird die Regel für Schlüssel (`names_credential()`, B9 der
  Optionen): ein Name der Umgebung nennt eine Datei, einen Ordner oder eine
  Tabelle, wie ein Slug einen Beitrag, keinen gespeicherten Wert. Grund:
  sie hielte gewöhnliche Namen fest (`reset-password`, `wp-oauth-server`,
  WooCommerces Tabelle `woocommerce_api_keys`), deren Inhalt die
  Bestandsaufnahme ohnehin nicht liest; der Bericht nennte dann Plugins nur
  noch als SHA-256.
- Was eine Zeile nicht als Text hält (kein UTF-8, ein Steuerzeichen, länger
  als 1024 Bytes), steht ebenfalls als SHA-256, ohne `held`; ebenso eine
  zu lange Versionsangabe, die der Bericht «not shown» nennt (Codex-Runde 1,
  C6). So bleibt jede Zeile in der Form, die der Import annimmt.
- Der Fortsetzungspunkt eines Teils (`after` im Zustand: im Cursor von
  `wp_site_export` und in den Teilen eines Berichts) trägt keinen Namen und
  keinen Teil eines Namens, auch keinen gewöhnlichen: nur den SHA-256 jedes
  Ordners, durch den sein Pfad geht, und zuletzt den des ganzen relativen
  Pfads (einer Tabelle: den ihres ganzen Namens), `AB_MCP_Sync_Env::place()`
  (Codex-Runden 1 und 2, A1 und C5; damit sind auch Namen ohne UTF-8
  gelöst). Der nächste Teil sucht ihn Ordner für Ordner unter den Namen
  wieder (`found()`). Einen SHA-256 je Ordner statt eines einzigen für den
  ganzen Pfad, damit die Fortsetzung nicht alles bis zur Stelle neu liest,
  was die Teile davor gezählt haben. Ist der Eintrag oder einer seiner
  Ordner inzwischen weg, gilt die Wurzel als nicht zu Ende gezählt («not
  compared», `moved`), die Tabellen als nicht ganz gelesen. Grund: ohne den
  Namen lässt sich die Stelle in der Reihenfolge nicht mehr bestimmen, und
  eine Zählung von vorn zählte doppelt. Das gilt jetzt für jeden Namen,
  nicht nur für gehaltene: eine Datei, die zwischen zwei Aufrufen genau an
  der Stelle gelöscht wird, an der ein Teil endete, lässt ihre Wurzel «not
  compared».
- Kennt nur die Live-Site eine Regel (`ab_mcp_redact_keys` dort), hält erst
  ihr Import den Namen: im empfangenen Paket (Ablage `in` der Live-Site,
  `out` der Staging-Site) steht er, wie die Staging-Site ihn schrieb und
  signierte, wie bei den Paketen des Inventars; Bericht, Teile dieser Site
  und Antwort zeigen ihn nur als SHA-256. Diese Ausnahme bleibt.
- Hält umgekehrt nur die Staging-Site einen Namen, gelten ihre Regeln auch
  für die Teile dieser Site, bevor sie in die Ablage kommen: der Bericht
  liest die Pakete der Umgebung beim ersten Aufruf, bevor er die Umgebung
  dieser Site liest, und legt die SHA-256 der Namen, die die Staging-Site
  hält, mit ihrem Grund in den Kopf seiner Gruppe
  (`AB_MCP_Sync_Env::held_names()`, Feld `held`, Format
  `ab-sync-report/2`); jeder Teil dieser Site wird damit gekürzt
  gespeichert (`held_there()`): ein Name, dessen SHA-256 die Staging-Site
  hält, steht nur als SHA-256 mit `held`, gleich welcher Art (ein
  gleichnamiges Plugin ebenso wie das Must-Use-Plugin). Der Bericht zeigt
  einen Eintrag unter dem SHA-256 (kein «nur hier» und «nur Staging»).
  Codex-Runde 2, A1. Ein Bericht im Format `ab-sync-report/1` (vor dieser
  Änderung begonnen) gilt als unbekannt und wird neu begonnen.

### Prüfung

- Unit (`tests/SyncEnvTest.php`) hält die Klauseln ohne WordPress (Lauf der
  Wurzeln, Kopf einer Datei, Plugins und Themes in Ordnern, `.htaccess`,
  Schema, Konstanten, Struktur der Zeilen, Namen und Fortsetzungspunkte,
  Vergleich); Integration (`tests/integration/SyncEnvTest.php`) den Bericht
  über zwei Sites, die Reihe der Pakete, die Teile dieser Site, dass keine
  geheime Konstante in Antwort oder Ablage steht und kein gehaltener Name
  (`password=EXAMPLE_SECRET`, `password:EXAMPLE_SECRET`, ein Ordner
  `password=` über `EXAMPLE_SECRET/…`, eine Regel nur auf der Staging-Site)
  in Antwort, Cursor, Zustand, Paket oder einer Datei der Ablage beider
  Sites, die Fortsetzung über Teile mit der neuen Stelle (auch mit einem
  inzwischen verschwundenen Eintrag), sowie den Zweck eines Folgeaufrufs. Die Bindung an die
  Verbindung hält `tests/integration/SyncPackageTest.php`.
- Ein Dateiname ohne UTF-8 lässt sich auf APFS (macOS) nicht anlegen; die
  Integration prüft den Fortsetzungspunkt darum mit gehaltenen Namen, die
  Unit-Suite die Form ohne UTF-8 über die kanonische Form hin und zurück. Mutationen: `tests/mutation/lists/inventar.php`
  (aus `tests/mutation/gen/inventar.py`).
- Nicht als Mutation geführt: `is_file()` vor `hash_file()` in
  `file_hash()` (ohne diese Prüfung blockierte eine FIFO an der Stelle eines
  Drop-ins den Aufruf; ein Test darauf liefe in die Zeitgrenze des Runners
  statt rot), die Ablauf-Zeit eines Berichts über die Pakete der Umgebung
  (sie entstehen Sekunden nach denen des Inventars) und die Fehlerwege der
  Leser, die eine Test-Site nicht auslöst (`SHOW TABLES`, `COUNT`, `SHOW
  CREATE TABLE`, unlesbare Zeilen `WPLANG`, `stylesheet`, `template`,
  `active_plugins`). Ebenso fünf Wächter, deren Wegfall nichts ändert, was
  ein Test sehen kann (Codex-Runde 1): die Art in `held_line()` (eine Ref
  einer festen Liste hält nie etwas), der SHA-256 dort (ein SHA-256 hält
  nichts), `isset( held )` in `held_here()` (eine gehaltene Zeile bleibt
  beim Kürzen dieselbe), der Platz in `held_alike()` (eine Seite hat einen
  Namen nicht zugleich als Text und als SHA-256) und die Art einer
  gehaltenen Zeile in `shaped()` (die Arten ausserhalb von `NAMED` haben
  Refs fester Listen, die nie die Form eines SHA-256 haben); dazu (Runde 2)
  die Art und die Form des SHA-256 in `held_names()`: die Zeilen kommen
  durch `shaped()`, das `held` nur bei `NAMED` mit einer Ref in Form eines
  SHA-256 zulässt.
