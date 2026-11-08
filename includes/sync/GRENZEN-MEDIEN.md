# Grenzen der Medien (Z8, Zweig «Medien»)

Gesammelte Gruppe-C-Grenzen von Zweig 10 (`heikel/z8-medien`): die Art
`attachment` mit ihren Dateien (Entscheid 26; `AB_MCP_Sync_Kind_Attachment`,
`AB_MCP_Sync_Attachment_Writer`, `AB_MCP_Sync_Media`,
`AB_MCP_Sync_Media_Package`). Eine eigene Datei wie `GRENZEN-INHALTE.md`,
weil diese Grenzen die Art betreffen; die Grenzen des Laufs
(`GRENZEN-LAUF.md`: Sperre, Unterbrechung, Journal, die eine Grenze
«Abweichung im Schritt», Rückweg) und der Post-Art (`GRENZEN-INHALTE.md`:
Zeile, Meta, Terme, Slug, Sanitizer vorab) gelten für Attachments
unverändert. Je Grenze ein Satz mit Beispiel und Grund; wo eine Sperre
entsteht, nennt ihre Meldung den Weg.

## Normalfall (hin und zurück)

- Ein neues Bild mit den Grössen, die Staging erzeugt hat, und ein neues
  PDF, beide in einer neuen Seite (Beitragsbild, Bild-Block, Datei-Block),
  das Bild unter der Seite: die Anhänge zuerst, dann die Seite, dann der
  Elternschritt des Bilds; jede Datei exklusiv gelegt, bytegleich wie auf
  Staging. Der Rückweg lässt die drei neuen Objekte als Rest stehen, ihre
  Paare `residual`, jede gelegte Datei liegt weiter und steht mit Pfad im
  Ergebnis. Prüffall
  `SyncMediaTest::testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles`.
- Ein geänderter Titel, Alternativtext oder eine geänderte Beschreibung
  eines Anhangs, den beide Sites haben, dazu eine neue Seite, unter die er
  auf Staging gehängt wurde: erst die Seite, dann nur Zeile und Meta des
  Anhangs, jede Datei ist schon da (`present`), keine wird geschrieben; der
  Rückweg setzt den Anhang bytegenau zurück, die Seite bleibt als Rest.
  Prüffall
  `testAChangedTitleAltTextDescriptionAndParentOfAnAttachmentGoOverAndBackWithoutAFile`.
- Ein auf Staging bearbeitetes Bild (`wp_save_image()`: neue Datei
  daneben, neue Grössen, das Original in `_wp_attachment_backup_sizes`):
  nur die neuen Dateien werden gelegt, das Original liegt schon da, die
  alten Grössen bleiben unberührt auf der Platte. Prüffall
  `testAnImageEditedOnStagingLaysItsNewFilesOnlyWithTheRightToUploadAndTheWayBackAsksTheTypeOfTheFileItNamesAgain`.

## Dateiliste und Kanon

- Die Dateiliste der kanonischen Form ist genau die Liste von
  `wp_delete_attachment_files()` (Zweig 4, `AB_MCP_Sync_Media`), je Datei
  Pfad relativ zu den Uploads, Grösse und SHA-256, aus den Zeilen derselben
  Lesung wie Form und Inhalt (`AB_MCP_Sync_Media::of_rows()`). Ein Test hält
  sie gegen die Kernfunktion (`SyncMediaListTest`, und im Normalfall die
  Liste des gelegten Bilds). Der Hash eines Anhangs deckt jede Datei; jede
  Bestandsaufnahme liest darum alle Dateien beider Sites einmal (bei
  grossen Mediatheken dauert sie entsprechend, in Teilen nach dem Budget
  von 60 s).
- Ein Anhang ohne Datei (`_wp_attached_file` leer, etwa von einem Plugin
  für externe Medien) sperrt mit `attachment:unsafe_path` (Z8b); ein
  Objekt, das ihn nennt (ein Beitragsbild), wartet im Bericht auf ihn.
- Die Grössen werden nicht neu erzeugt: Live bekommt die Dateien, die
  Staging gemacht hat. Registriert das Live-Theme andere Grössen, fehlen
  diese, bis jemand sie in wp-admin neu erzeugt (Entscheid 26, «Keine
  Neuerzeugung der Grössen»).

## Pfade und Eigentum

- Jede Datei der Liste ist live entweder bytegleich am Pfad (`present`,
  nichts geschrieben, nicht eigen), oder dort liegt nichts (`absent`: der
  Schritt legt sie exklusiv mit `fopen( …, 'xb' )`, eigen), oder dort liegt
  etwas anderes: eine andere Datei, ein Link, ein Ordner sperrt mit
  `attachment:replace_file` (Z8b), ein Link oder eine Datei auf dem Weg mit
  `attachment:unsafe_path` (Z8b). Das gilt auch für eine eigene Datei eines
  früheren Laufs: überschrieben wird nie, gelöscht auch nicht.
- Eigen ist nur, was das Journal des Schritts nach dem exklusiven Anlegen
  als `laid` (oder `partial`) mit `own` führt; vor jedem Anlegen steht die
  Datei im Journal als `creating` (gespeichert, geprüft). Endet die Anfrage
  in diesem Fenster, zeigt das Journal `creating`: ob dort eine Datei liegt
  und wessen, sagt nur die Platte, denn ein anderer Upload kann den Pfad
  danach belegt haben. Eine solche Datei ist nie eigen, sondern unklar: sie
  steht mit Pfad in `files_unclear` im Ergebnis des Schritts und seines
  Rückwegs (Codex-Runde 1, A2). Eine teilweise geschriebene eigene Datei
  bleibt so liegen.
- Ordner und Datei werden gegen ausgetauschte Ordner gehärtet (Codex-Runde
  1, A1): der Uploads-Ordner und jeder Ordner auf dem Weg entsteht einzeln
  mit `wp_mkdir_p()` für diese eine Ebene unter einem geprüften Ordner
  (Entscheid 26) und wird danach je Ebene
  geprüft (aufgelöst er selbst, kein Link); unmittelbar vor `fopen( …,
  'xb' )` wird der aufgelöste Ordner der Datei noch einmal gefragt, ohne
  Speichern des Journals dazwischen; nach dem Öffnen belegen Gerät und
  Inode der geöffneten Datei (`fstat()`) gegen die des Pfads (`lstat()`)
  und der aufgelöste Pfad, dass sie unter den Uploads liegt. Sonst wird
  nichts hineingeschrieben, die leere Datei bleibt (unklar, mit Pfad), der
  Schritt endet `unclear`, gelöscht wird nichts. Nach dem Schreiben und vor
  dem Speichern als `laid` wird der Ort noch einmal so belegt (Codex-Runde
  2, A1); stimmt er nicht, ist die Datei unklar (mit Pfad), der Schritt hält
  mit Befund an, keine weitere Datei wird gelegt. Jede dieser Prüfungen
  leert vorher auch den Realpath-Cache von PHP und fragt `realpath()`
  frisch (Codex-Runde 3, A1: ein Ordner, der verschoben und am alten Ort
  durch einen Link zum neuen ersetzt wird, ist durch `lstat()` derselbe
  Inode, nur ein frisches `realpath()` zeigt den Umweg). Rest (Grenze C),
  ehrlich gefasst: ein paralleler Prozess mit Schreib- und Verschieberecht
  an den Uploads kann zwischen den Prüfungen handeln. Tauscht er zwischen
  der letzten Prüfung und dem Öffnen einen Ordner gegen einen Link,
  entsteht eine leere Datei ausserhalb der Uploads; tauscht er zwischen der
  Prüfung eines Ordners und dem Anlegen darunter, ein leerer Ordner;
  verschiebt er den schon geöffneten Ordner aus den Uploads, während die
  Datei geschrieben wird, entstehen die Bytes des Anhangs (nur sie, aus dem
  signierten Paket) am neuen Ort ausserhalb. Was davon bei der nächsten
  Prüfung noch sichtbar ist, wird erkannt und gemeldet; ein Tausch nach der
  letzten Prüfung bleibt unerkannt. Am offenen Handle lässt PHP das nicht
  ausschliessen; keine Prüfung verhindert es.
- Ein Schritt legt alle Dateien seines Anhangs in einer Anfrage: höchstens
  48 MiB (`attachment:file_too_large` darüber), Stück für Stück aus dem
  Paket gelesen, nie ganz im Speicher; einen Cursor innerhalb des Schritts
  gibt es nicht. Endet die Anfrage mittendrin, gilt das Fenster `creating`
  oben, der Schritt schreibt keine Zeile, und der Lauf führt ihn als
  unterbrochen wie jeden anderen Schritt (`GRENZEN-LAUF.md`). Grund: ein
  Anhang ist eine Einheit des Journals; 48 MiB kopiert eine Site in
  Sekunden.
- Eigene Dateien eines zurückgenommenen Laufs bleiben liegen und stehen mit
  Pfad im Ergebnis des Schritts und seines Rückwegs (`files`); aufräumbar
  werden sie mit dem Datei-Manifest von Z8b, bis dahin von Hand. Ein
  Schritt, der Dateien legte, aber keinen neuen Anhang anlegte (eine Datei
  scheiterte), nennt sie nur in seinem eigenen Ergebnis: einen Rückweg hat
  er nicht, weil er keine Zeile schrieb (`wp_site_revert` nimmt nur
  Schritte, die schrieben).
- Ein Pfad, den ein anderes Attachment der Live-Site nennt, sperrt mit
  `attachment:shared_path` (Z8b), geprüft mit einem SQL-Vorfilter nach dem
  letzten Segment des Pfads und danach der Liste jedes Kandidaten wie der
  Kern sie rechnet (`AB_MCP_Sync_Media::named_by()`). Der Vorfilter ist eine
  Obermenge: der Name jeder Datei einer Liste steht unverändert in einem
  der drei Schlüssel. Die Dateien eines Kandidaten liest sie wie der Kern
  (`get_attached_file()`, `wp_delete_attachment_files()`): ein absoluter
  Pfad unter den Uploads nennt die Datei an seinem Pfad relativ zu ihnen,
  wie `_wp_relative_upload_path()` ihn speichert, für `_wp_attached_file`
  und für `metadata.file`, den Ordner der Sicherungen (ein alter Import;
  Codex-Runde 1, A6; Runde 2, B: ein Januar-Anhang mit beiden absolut und
  einer Sicherung sperrte einen unbeteiligten neuen Anhang). Die
  kanonische Form liest so nicht: dort nennt ein absoluter Pfad einen Ort
  der Site, die ihn speicherte, und bleibt ein Problem. Mehr als 20 000
  Kandidaten oder ein Kandidat, dessen Liste sich nicht ganz lesen lässt
  (ein Name, der kein einfacher Pfad unter den Uploads ist, auch ein
  absoluter ausserhalb), sperren (fail closed). Betroffen ist nur ein
  Anhang, dessen Dateiname ein solcher Kandidat enthält; WordPress
  speichert seit 2.7 relativ, ein Upload der Mediathek ergibt nie einen
  solchen Namen, der Normalfall bleibt frei. Die Prüfung läuft in
  der Gesamtvorprüfung und in der Grenze; der Bericht selbst ist rein und
  nennt sie nicht, der Plan erst bei der Vorprüfung. Zwei Anhänge desselben
  Plans, die einen Pfad nennen, sperrt schon der Plan (beide).

## Typ und Rechte

- Jede Datei der Liste, die die Live-Site schon bytegleich hat (`present`)
  und die der Schritt darum nicht schreibt, prüft der Plan nach ihrem
  Inhalt, mit einem Unterschied je nachdem, wem sie gehört (Codex-Runde 1,
  C; Runde 2, B; Präzisierung der Lead danach):
  - Nennt die Liste desselben Live-Anhangs sie schon, prüft er nur, dass
    ihr Inhalt zu ihrem Namen passt: WordPress' Prüfung nach dem Inhalt,
    gegen jeden Typ, den WordPress kennt, vereinigt mit dem, was
    `upload_mimes` dem Konto sonst gibt (ein Plugin, das SVG dort
    registriert), nie als Schranke des Kontos (`content_check()`: für
    diesen einen Aufruf ist die Vereinigung erlaubt, ein Filter, der die
    Liste ergänzt und nichts ersetzt, weil `wp_check_filetype_and_ext()`
    sonst zuletzt gegen die Typen des Kontos prüft; Codex-Runde 3, B: ein
    SVG mit Titeländerung sperrte, weil die Ergänzung verloren ging). Eine WebP-Grösse, die die Mediathek machte,
    sperrt so eine Änderung des Titels nicht, auch für ein Konto, das WebP
    nicht hochladen darf.
  - Jede andere liegende Datei (ein neuer Anhang, der eine Datei übernähme,
    die zu keinem Anhang gehört; eine Datei, die erst die neue Liste nennt)
    zählt wie eine, die der Schritt legt: die Typen des Kontos sind
    verbindlich. Grund: sonst machte ein Konto ohne `unfiltered_upload`
    eine liegende Datei eines Typs, den es nicht hochladen darf (SVG, HTML,
    unter den Uploads liegengeblieben), zu einem eigenen Medium. Selten
    (verwaiste Dateien, gleich auf Staging), darum sperrt es mit
    `attachment:file_type`.
  Eine liegengebliebene Grösse mit dem Inhalt eines anderen Typs sperrt in
  beiden Fällen. Die Hauptdatei hat den Typ des Anhangs. Prüffälle
  `testAWebpSizeAndAnImportOfOldWithAbsolutePathsLockNoAttachmentBesideThem`
  (WebP-Grösse hin und zurück) und der Sperren-Fall (ein neuer Anhang auf
  einer verwaisten SVG, einer mit einer verwaisten SVG-Grösse).
- Typ nach dem Inhalt: jede zu legende Datei wird erst ganz in eine
  temporäre Datei dieser Anfrage gelesen (`get_temp_dir()`, ausserhalb der
  Uploads, danach entfernt), gegen Grösse und SHA-256 geprüft und mit
  `wp_check_filetype_and_ext()` und den Typen geprüft, die dieses Konto
  hochladen darf (`get_allowed_mime_types()`), wie `_wp_handle_upload()`
  einen Upload prüft; eine Datei, deren Inhalt einen anderen Namen verlangt
  (ein PNG als `.jpg`), oder ein Typ, den das Konto nicht hochladen darf,
  sperrt mit `attachment:file_type` (Z8), bevor eine Datei des Anhangs
  liegt. Die Hauptdatei hat den Typ, den der Anhang nennt
  (`post_mime_type`), nach dem Namen und, wo der Schritt den Anhang eine
  Datei nennen lässt, die die Live-Site schon hat (ein neuer Anhang auf
  einer liegengebliebenen Datei, eine andere Hauptdatei), auch nach dem
  Inhalt dieser Datei, wie der Rückweg es fragt. Ein Plugin, das den Filter
  `wp_check_filetype_and_ext` ändert, ändert diese Prüfung wie beim
  Upload. Ein Konto mit `unfiltered_upload` darf wie beim Upload jeden Typ;
  einen Inhalt, der einen anderen Namen verlangt, nimmt es trotzdem nicht.
- Rechte: ein neuer Anhang `upload_files` (das `create_posts` der Art),
  ein vorhandener `edit_post`; die Schlüssel, die Dateien nennen
  (`_wp_attached_file`, `_wp_attachment_metadata`,
  `_wp_attachment_backup_sizes`), zusätzlich `upload_files`; der
  Alternativtext mit `edit_post`, wie Mediathek und REST-API ihn schreiben.
  `map_meta_cap` verweigert diese geschützten Schlüssel jedem; sie gehen
  darum nach der Regel der Medien-Art (Entscheid 28: «die Schlüssel der
  Menü- und Medien-Art in ihrer Art»). Jeder andere Schlüssel folgt der
  Regel der Post-Art.
- Der Rückweg fragt dieselben Rechte und dieselben Prüfungen der Dateien
  wie der Hinweg (`restore_right()`, `restore_files()`; Codex-Runde 1, A4),
  und die drei Schlüssel, die Dateien nennen (`_wp_attached_file`,
  `_wp_attachment_metadata`, `_wp_attachment_backup_sizes`), gehen nur
  gemeinsam zurück (`together()`; Codex-Runde 2, A4): lässt der Rückweg
  einen davon stehen, weil er nicht mehr der eigene des Laufs ist, oder
  verweigert die Prüfung ihn, bleiben alle drei stehen (`left`, mit Grund).
  Vor dem Rückweg eines Anhangs vergleicht er alle drei, auch die, die das
  Journal nicht hält (gleich ihrem Ziel, also wie die Sicherung), mit dem
  Stand, den der Schritt hinterliess (Codex-Runde 3, A4, an der Stelle von
  Entscheid A): weicht einer ab, hat eine andere Hand das Objekt geändert,
  und das ganze Objekt bleibt stehen (etwa: der Lauf änderte nur die
  Metadaten, danach zeigte jemand `_wp_attached_file` von September auf
  Oktober). Ein gewöhnlicher Rückweg ändert keinen der drei zwischen Lauf
  und Rückweg; der einzige Weg des Kerns, der einen schreibt, ist
  `wp_maybe_generate_attachment_metadata()` auf der Bearbeitungsseite eines
  Audios oder Videos, dem der Schlüssel `_wp_attachment_metadata` ganz
  fehlt (ein Upload der Mediathek schreibt ihn immer): ein solcher Anhang
  bliebe beim Rückweg stehen (Grenze C).
  So prüft `restore_files()` genau die Liste, die danach gilt: jede Datei,
  die die gesicherten Werte zusammen nennen (gerechnet wie der Kern), muss
  eine einfache Datei unter den Uploads sein, ohne Link auf dem Weg, von
  keinem anderen Anhang der Site genannt, ihr Inhalt muss zu ihrem Namen
  passen (`content_check()`, alle Typen, die WordPress kennt), die
  Hauptdatei vom Typ des Anhangs. Die Typen des Kontos fragt der Rückweg
  nicht (Entscheid der Lead auf Codex-Runde 2): er legt keine Datei, er
  nennt nur Dateien wieder, die vor dem Lauf in der Liste desselben
  Anhangs standen; die Typen des Kontos regeln nach Entscheid 26, was
  hochgeladen oder gelegt wird. Die Rechte `edit_post` und `upload_files`
  für die Dateischlüssel bleiben. Prüffälle: ein bearbeitetes Bild geht
  für ein Konto, das kein JPEG hochladen darf, ganz zurück; eine alte
  Grösse, die ein PNG unter `.jpg` ist, lässt die Gruppe stehen. Hatte der
  Anhang vorher keinen der drei, geht ihr Entfernen zurück. Auf dem
  Rückweg wird keine Datei gelegt.
- Unmittelbar vor jeder Datei, die ein Schritt legt, fragt er die Rechte
  noch einmal wie die Vorprüfung (Codex-Runde 1, A5): `upload_files` (das
  `create_posts` der Art) für einen neuen Anhang, `edit_post` und
  `upload_files` für einen vorhandenen, der Typ nach dem Namen für das
  Konto erlaubt. Hat ein Filter, der beim Bereitstellen lief, sie
  genommen, liegt keine Datei, und der Schritt endet mit dem Grund.

## Schreibwege

- Die Meta der Medien-Art geht über die Metadaten-API
  (`update_metadata()`, `add_metadata()`), nicht über
  `wp_update_attachment_metadata()`: ein Bildoptimierer, der diesen Filter
  hakt, läuft bei einer Übernahme nicht. Ändert ein Haken der Site trotzdem
  eine Datei oder einen Wert, weicht das Objekt ab, und nichts wird von
  selbst zurückgesetzt (Entscheid A). Der gelesene Stand eines Anhangs
  umfasst dafür neben seinen Zeilen seine Dateien (Codex-Runde 1, A3): je
  Pfad der Liste des Ziels und der Liste vor dem Schritt (`watch` im
  Journal) liegt nichts, eine Datei mit SHA-256 und Grösse oder etwas
  anderes (`files_state()`). Nach jedem eigenen Aufruf wird er verglichen
  wie die Teile; eine Datei, die ein Aufruf geändert hat, lässt das Objekt
  abweichen. Die letzte Lesung steht im Journal (`files_seen`); der Rückweg
  vergleicht vor dem ersten Teil gegen sie (eine Datei, die seitdem ein
  Haken oder eine andere Hand änderte, auch nach einer Anfrage, die nach
  dem Zeilenaufruf endete: nichts geht zurück) und nach jedem
  zurückgesetzten Teil (ändert ein Haken dabei eine Datei, hält der Rückweg
  für das Objekt an, die übrigen Teile bleiben), und unmittelbar vor jedem
  Teil, der zurückgeht (Codex-Runde 2, A3: ein Filter der Prüfung des
  Rückwegs, der eine Datei ändert, hält ihn an, bevor ein weiterer Teil
  läuft). Prüffall
  `testAFileAHookChangesAsTheStepWritesOrPutsBackDeviatesTheAttachmentAndStopsItsWayBack`.
- Ein neuer Anhang entsteht mit `wp_insert_attachment()` und seiner Datei,
  wie ein Upload ihn macht (`_wp_attached_file` mit der Zeile, die `guid`
  die Adresse der Datei). Die Aktion `add_attachment` läuft dabei; ändert
  ein Plugin dort etwas am Anhang, weicht er ab.
- Fehlt der Uploads-Ordner (eine Site ohne Upload), entsteht er mit der
  ersten Datei, wie WordPress ihn beim ersten Upload macht, auch wo die
  Datei in keinem Unterordner liegt (Ordner nach Monaten aus, Codex-Runde
  1, B8); fehlende Unterordner einzeln, je Ebene geprüft (oben). Prüffall
  `testTheFirstFilesOfASiteWithoutMonthFoldersGoIntoItsUploadsAndOnlyWithTheRightToUploadAsTheyAreLaid`.

## Elternverweis (schwach, Entscheid 16)

- Das Elternteil eines Anhangs ist ein schwacher Verweis: legt derselbe
  Lauf die Seite an, unter der das Bild hängt, entsteht das Bild zuerst
  ohne Elternteil, und ein eigener Elternschritt setzt es nach der Seite.
  Bis dahin trägt das Paar als Staging-Basis den Stand ohne Elternteil
  (im Normalfall gemessen: die Basis vor und nach dem Elternschritt
  verschieden, der nächste Bericht gleich); scheitert die Seite, sperrt der
  Elternschritt mit `post:reference_unmapped`, das Bild bleibt ohne
  Elternteil, und der nächste Bericht zeigt es als «auf Staging geändert»
  (dieser Ausgang ist kein eigener Prüffall). Im Bericht wartet der
  schwache Verweis wie ein starker, wo sein Ziel zurückgehalten ist oder
  fehlt, aber ohne Kante (keine gegenseitigen Verweise mit der Seite).
  Wählt der Plan das Bild ohne seine neue Seite, sperrt die Gesamtvorprüfung
  es mit `post:reference_unmapped` (Weg: die Seite mitnehmen oder zuerst
  übernehmen).
- Hat ein vorhandener Anhang auf Staging ein neues Elternteil, das der
  Lauf anlegt, kommt der Anhang nach diesem (eine Kante der Ordnung). Nennt
  die neue Seite den Anhang zugleich (als Beitragsbild, in einem Block), ist
  das kein Zyklus: der vorhandene Anhang hat ein Paar, eine Kante der
  Ordnung führt nur zu einem Objekt, das der Lauf anlegt
  (`AB_MCP_Sync_Push::order()`); erst die Seite, dann der Anhang
  (Codex-Runde 1, C: die frühere Beschreibung als Zyklus stimmte nicht).
  Die Gesamtvorprüfung zählt die Seite als eine, die der Lauf anlegt; die
  Grenze setzt das Elternteil nach ihrem Paar. Prüffall: der Normalfall mit
  dem geänderten Logo unter einer neuen Seite.

## Paket der Medien

- Ein Paket `media` trägt die Dateien ganzer Anhänge, je Stück eine Zeile
  (`AB_MCP_Sync_Media_Package`: Base64 von höchstens 3 MiB), bis 48 MiB
  Dateien je Paket, damit Base64 und gzip unter den 64 MiB eines Pakets in
  Free bleiben (Vertrag B), und bis 5000 Zeilen je Paket (so viele Objekte
  nennt ein Manifest; viele kleine Dateien). Passt ein Anhang nicht mehr,
  nach Bytes oder nach Zeilen, nennt die Antwort ihn in `left` (ein
  weiteres Paket; Codex-Runde 1, C: vorher verwarf die Zeilengrenze das
  ganze Paket); sind die Dateien eines Anhangs allein grösser, nennt sie
  ihn in `too_large`, und der Plan sperrt ihn mit
  `attachment:file_too_large` (Z8, wie die übrigen Sperren, die eine Person
  auflöst; keine spätere Etappe des Plans hebt sie auf), wenn er mehr als
  48 MiB legen müsste (Weg: die Datei von Hand in der Mediathek
  hochladen). Ein Video über 48 MiB ist damit ein Weg von Hand.
- Ein Dateiname, der kein UTF-8 ist (etwa `café.jpg` in Latin-1), steht in
  den Zeilen und im Index als Marke seiner Bytes und wird als diese Bytes
  gelesen (Codex-Runde 1, C).
- Die Inhalte der Dateien werden nicht auf Zugangsdaten gelesen: es sind
  die Dateien, die die Staging-Site unter ihren Adressen ausliefert, und
  ihr Inhalt ist kein Wert der Datenbank. Die Werte des Anhangs
  (Bildmetadaten, Titel, Beschreibung) gehen durch die Zugangsdatenprüfung
  wie jede Meta, und mit ihnen die Pfade und Dateinamen der Liste: das
  Paket der Medien liest jeden Anhang so, wie das Paket der Objekte ihn
  liest, und lässt einen, den eine Zugangsangabe hält (ein Name nach B9,
  ein Feld davon, eine Sperre der Prüfung; etwa ein gespeicherter Pfad
  `password=….jpg`), draussen: in `withheld` mit `needs: credential`,
  gezählt in `credentials`, keine seiner Dateien im Paket (Codex-Runde 1,
  A7).
- Die Live-Site prüft ein Paket der Medien ganz, bevor es als vollständig
  gilt (jede Zeile in ihrer einen Schreibweise und Form, die Stücke einer
  Datei in ihrer Reihenfolge und Grösse, jede Datei gegen ihren SHA-256),
  und hält einen Index; ein Schritt liest eine Datei aus den Stücken, die
  sie halten, nie das Paket ganz. Ändert sich eine Datei auf Staging,
  während sie gepackt wird, entsteht kein Paket.
- Der Plan bucht die Bytes der Dateien, die er legen könnte, zweifach (die
  temporäre Kopie und die Datei), bei einem vorhandenen Anhang nur die
  Pfade, die seine Liste hier nicht nennt (Entscheid 8).

## Laufzeit

- Die Integrationsklasse `SyncMediaTest` (neun Fälle, gemeinsame Hilfen,
  Bilder mit GD erzeugt) läuft lokal in rund 64 s (nach Codex-Runde 2: der
  Fall mit WebP-Grösse und Altbestand neu, rund 7 s; der Hook-Fall mit
  zwei bearbeiteten Bildern mehr). Der Paket-Fall erreicht allein rund
  123 MiB Speicher (Stücke von 3 MiB), unter den 128 MB eines Prozesses je
  Klasse.

## Nicht als Mutation geführt

- Die Texte der Meldungen; die Sperre `attachment:file_too_large` (ein
  Prüffall bräuchte Dateien über 48 MiB); die Buchung der Dateibytes im
  Plan; der Index-Abruf mit mehr Kandidaten als `SHARED_MAX`.
- Gleichwertig, darum nicht geführt (die Mutation überlebte, keine Probe
  kann sie unterscheiden): in `line()` die Grösse unter null (die Daten
  eines Stücks haben genau die Länge, die Nummer und Grösse ergeben, eine
  negative gibt es nicht); in `unpacked()` die Nummer jedes weiteren
  Stücks (eine Folge mit falscher Nummer nähme der Index nur an, wo ihre
  Bytes den SHA-256 der Datei ergeben, und `extract()` prüft die Nummer
  jedes Stücks noch einmal, bevor eine Datei liegt); in `extract()` der
  SHA-256 im Speicher neben dem von der Platte gelesenen (die Platte liest,
  was geschrieben wurde; geführt ist die Klausel mit der Zahl der Stücke);
  in `check_item()` der Elternschritt ohne eigene Prüfung (geprüft wie ein
  Anlegen findet er, was die Prüfung des Schritts findet, der den Anhang
  anlegt); in `attach_item()` der Zustand `done` des Anlegeschritts (ein
  nicht fertiger Schritt hinterlässt kein aktives Paar dieses Laufs, das
  prüft die Bedingung daneben); in `plan()` das `continue` nach dem
  schwachen Elternverweis (ein schwacher Verweis erfüllt die Bedingung des
  starken danach nicht; geführt ist, dass der Plan ihn liest).
- Nach Codex-Runde 1 nicht geführt: die Prüfungen eines Ordners auf Link
  und Auflösung in `folder()` je für sich (sie sichern einander: ein Link
  auf dem Weg fällt beiden auf; geführt sind der Uploads-Ordner, der Ordner
  je Ebene und die Datei, wo ein Ordner hingehört); das Rennen zwischen
  der Prüfung eines Ordners und dem Anlegen darunter (keine Naht; die Prüfung
  vor und nach dem Öffnen der Datei führt die Naht `$probe`); die erste
  Lesung der Dateien nach dem Legen (`files_seen` in `lay_files()`:
  gleichwertig, `write_parts()` liest sie sonst vor dem ersten Aufruf
  selbst, ein Unterschied entsteht nur bei einer Anfrage, die genau
  zwischen dem Legen und dem Zeilenaufruf endet); die Pfade, die nur die
  Liste vor dem Schritt nennt, unter den beobachteten Dateien (kein Fall,
  in dem ein Haken eine alte Grösse ändert); der Typ nach dem Namen in
  `may_lay()` (kein Fall ändert die erlaubten Typen zwischen Prüfung und
  Legen; geführt ist das Recht); in `restore_files()` die Rückkehr ohne
  Prüfung für einen Wert ohne Datei (gleichwertig: ein solcher Wert nennt
  keine Datei zu prüfen, ausser die gesicherte Hauptdatei ist kein
  einfacher Pfad) und in der Inhaltsprüfung liegender Dateien der Typ der
  Hauptdatei gegen den Anhang (gleichwertig: der Name ist vorher gegen den
  Anhang geprüft, und ein Inhalt eines anderen Typs als der Name setzt
  `proper_filename`).
- Nach Codex-Runde 3 nicht geführt: die Entscheidung der Gruppe in
  `put_back()` und `group_refusal()` über ein Glied, das der Rückweg nach
  seiner Regel stehen lässt. Den Fall, den sie hielten (ein Glied seit dem
  Lauf geändert), hält jetzt der Vergleich vor dem Rückweg; eine
  Verweigerung durch `restore_right()` war schon gleichwertig. Übrig bleibt
  ein Glied, das der Schritt nie zurücklas, weil seine Anfrage zwischen
  zwei Dateischlüsseln endete: die Gruppe lässt dann auch die schon
  geschriebenen stehen. Ein Prüffall bräuchte eine Anfrage in einem eigenen
  Prozess, die genau zwischen den beiden Metadaten-Aufrufen endet; diese
  Klasse hat keinen, ein nachgestelltes Journal wäre eine Attrappe.
- Nach Codex-Runde 2 nicht geführt: in `files_plan()` die bekannten Typen
  als Massstab für den Namen einer liegenden Datei (`$known`; mit den Typen
  des Kontos allein wäre eine solche Datei nur nicht geprüft, also
  lockerer, nie gesperrt) und das Überspringen eines Namens, dessen Typ
  WordPress nicht kennt (kein Fall hat eine solche liegende Datei); in
  `group_refusal()` die Verweigerung durch `restore_right()` (gleichwertig:
  `restore_files()` prüft die ganze Liste, jedes Glied der Gruppe bekommt
  an seiner Stelle dieselbe Verweigerung; geführt ist die Gruppe über ein
  Glied, das der Rückweg nach seiner Regel stehen lässt).

- Ersetzen vorhandener Mediendateien, Aufräumen eigener Dateien, Rückweg
  neu angelegter Attachments mit Verweisgraphen: Z8b (Plan, «Folgen für
  spätere Etappen»).
- Löschen eines Anhangs: Z8d (`*:delete`); ein auf Staging gelöschter
  Anhang erscheint als «auf Staging gelöscht».
