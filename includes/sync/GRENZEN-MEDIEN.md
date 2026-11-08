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
- Eigen ist nur, was das Journal des Schritts als `laid` (oder `partial`)
  mit `own` führt; vor jedem Anlegen steht die Datei im Journal als
  `creating` (gespeichert, geprüft). Endet die Anfrage in diesem Fenster,
  zeigt das Journal `creating`: ob die Datei liegt, sagt nur die Platte; sie
  gilt als eigen, weil nur der Lauf sie exklusiv angelegt haben kann. Eine
  teilweise geschriebene Datei bleibt so liegen.
- Eigene Dateien eines zurückgenommenen Laufs bleiben liegen und stehen mit
  Pfad im Ergebnis des Schritts und seines Rückwegs (`files`); aufräumbar
  werden sie mit dem Datei-Manifest von Z8b, bis dahin von Hand.
- Ein Pfad, den ein anderes Attachment der Live-Site nennt, sperrt mit
  `attachment:shared_path` (Z8b), geprüft mit einem SQL-Vorfilter nach dem
  letzten Segment des Pfads und danach der Liste jedes Kandidaten wie der
  Kern sie rechnet (`AB_MCP_Sync_Media::named_by()`). Der Vorfilter ist eine
  Obermenge: der Name jeder Datei einer Liste steht unverändert in einem
  der drei Schlüssel. Mehr als 20 000 Kandidaten oder ein Kandidat, dessen
  Liste sich nicht lesen lässt, sperren (fail closed). Die Prüfung läuft in
  der Gesamtvorprüfung und in der Grenze; der Bericht selbst ist rein und
  nennt sie nicht, der Plan erst bei der Vorprüfung. Zwei Anhänge desselben
  Plans, die einen Pfad nennen, sperrt schon der Plan (beide).

## Typ und Rechte

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
- Der Rückweg fragt dieselben Rechte und dieselbe Typprüfung
  (`restore_right()`): die Datei, die ein gesicherter `_wp_attached_file`
  nennt, muss nach ihrem Inhalt ein Typ sein, den das Konto hochladen darf,
  und als einfache Datei unter den Uploads liegen; sonst bleibt der Teil
  mit seinem alten Wert stehen, die übrigen Teile gehen zurück. Auf dem
  Rückweg wird keine Datei gelegt.

## Schreibwege

- Die Meta der Medien-Art geht über die Metadaten-API
  (`update_metadata()`, `add_metadata()`), nicht über
  `wp_update_attachment_metadata()`: ein Bildoptimierer, der diesen Filter
  hakt, läuft bei einer Übernahme nicht. Ändert ein Haken der Site trotzdem
  eine Datei oder einen Wert, weicht das Objekt ab (sein Hash deckt jede
  Datei), und nichts wird von selbst zurückgesetzt.
- Ein neuer Anhang entsteht mit `wp_insert_attachment()` und seiner Datei,
  wie ein Upload ihn macht (`_wp_attached_file` mit der Zeile, die `guid`
  die Adresse der Datei). Die Aktion `add_attachment` läuft dabei; ändert
  ein Plugin dort etwas am Anhang, weicht er ab.
- Fehlt der Uploads-Ordner (eine Site ohne Upload), entsteht er mit der
  ersten Datei, wie WordPress ihn beim ersten Upload macht; fehlende
  Unterordner mit `wp_mkdir_p()`, danach geprüft: aufgelöst unter den
  Uploads, kein Link.

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
  Lauf anlegt, kommt der Anhang nach diesem (eine Kante der Ordnung); nennt
  die neue Seite den Anhang zugleich, ist das ein Zyklus und gesperrt
  (`*:mutual_references`, Z11c). Die Gesamtvorprüfung zählt die Seite als
  eine, die der Lauf anlegt; die Grenze setzt das Elternteil nach ihrem
  Paar. Prüffall: der Normalfall mit dem geänderten Logo unter einer neuen
  Seite.

## Paket der Medien

- Ein Paket `media` trägt die Dateien ganzer Anhänge, je Stück eine Zeile
  (`AB_MCP_Sync_Media_Package`: Base64 von höchstens 3 MiB), bis 48 MiB
  Dateien je Paket, damit Base64 und gzip unter den 64 MiB eines Pakets in
  Free bleiben (Vertrag B). Passt ein Anhang nicht mehr, nennt die Antwort
  ihn in `left` (ein weiteres Paket); sind die Dateien eines Anhangs allein
  grösser, nennt sie ihn in `too_large`, und der Plan sperrt ihn mit
  `attachment:file_too_large` (Z10), wenn er mehr als 48 MiB legen müsste
  (Weg: die Datei von Hand in der Mediathek hochladen). Ein Video über
  48 MiB ist damit ein Weg von Hand.
- Die Inhalte der Dateien werden nicht auf Zugangsdaten gelesen: es sind
  die Dateien, die die Staging-Site unter ihren Adressen ausliefert, und
  ihr Inhalt ist kein Wert der Datenbank. Die Werte des Anhangs
  (Bildmetadaten, Titel, Beschreibung) gehen durch die Zugangsdatenprüfung
  wie jede Meta.
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

- Die Integrationsklasse `SyncMediaTest` (sechs Fälle, gemeinsame Hilfen,
  Bilder mit GD erzeugt) läuft lokal in rund 37 s.

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

- Ersetzen vorhandener Mediendateien, Aufräumen eigener Dateien, Rückweg
  neu angelegter Attachments mit Verweisgraphen: Z8b (Plan, «Folgen für
  spätere Etappen»).
- Löschen eines Anhangs: Z8d (`*:delete`); ein auf Staging gelöschter
  Anhang erscheint als «auf Staging gelöscht».
