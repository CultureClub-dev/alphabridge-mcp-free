# Grenzen des Kanonisierers (Z8, Zweig «Kanon»)

Gesammelte Gruppe-C-Grenzen der Codex-Runden 20 bis 26: seltene oder
konstruierte Schreibweisen, die der Kanonisierer absichtlich sperrt statt
umschreibt (`*:reference_spelling`, `*:unread_reference`,
`*:unread_credential`, `*:unreadable_value`). Je Grenze ein Satz mit
Beispiel und Sperrgrund; die Sperre trifft die Einheit (Spalte, Meta-Zeile,
Widget-Instanz, Option), nicht nur die Stelle. Alle Fälle sind in den
Suiten als Sperrfälle belegt (SyncCoreReads «unsafeAddresses»,
SyncNetTest, SyncCanonFormTest).

## Adressen und IDs

- Ein Verweis auf ein Objekt in einer Schreibweise, die nicht Byte für Byte
  so gespeichert ist, wie der Kern sie liest, sperrt – etwa `/?p=1&#50;`
  in einem Attribut oder `/?p=1\32` in CSS – weil die ID sonst an einer
  Stelle umgeschrieben würde, die der Kern anders liest (Runde 20).
- Ein doppelter JSON-Schlüssel, dessen verworfener Wert einen Verweis
  trägt (`{"url":"/?p=12","url":"x"}`), sperrt, weil `json_decode()` nur
  den letzten Wert kennt und der verworfene Verweis sonst stehen bliebe
  (Runden 20–23).
- Ein escapeter JSON-Schlüssel mit wirksamer ID (`{"\u0075rl":"/?p=12"}`)
  sperrt, weil kein Erzeuger des Kerns ihn so schreibt und der Pfad des
  Werts nicht Byte für Byte feststeht (Runde 20).
- Gemischte Slash-Escapes in derselben JSON-Adresse (`"\/blog/?p=12"`) und
  ungewöhnliche JSON-Escapes in einer Adresse (`"\u002f?p=12"`) sperren,
  weil die Adresse nicht in genau einer Schreibweise gespeichert ist
  (Runden 20, 23).
- Escapete CSS-Funktions- und At-Regel-Namen (`u\72l(…)`, `@imp\6Frt`) und
  Escapes oder Kommentare in einer Adresse (`url(/?p=1/**/2)`) sperren,
  weil der Browser mehr liest, als der Text zeigt (Runden 17, 20).
- Eine relative ID-Adresse in einer CSS-Variablen oder einem CSS-String
  ausserhalb von `url()`, `@import` und `image-set()`
  (`.x{--bild:"/?attachment_id=12"}`) sperrt, weil nur die Adresskonstrukte
  von CSS gelesen werden (Runden 22, 23).
- Eine Objektadresse in Titel, Alt-Text, Tooltip oder HTML-Kommentar
  (`<p title="/?p=12">`) sperrt, weil diese Stellen kein Leser des Kerns
  als Adresse liest (Runde 20).
- Eine root-relative Objektadresse in einem Textknoten mit
  Zeichenreferenzen wird nur umgeschrieben, wenn sie im gespeicherten
  Knoten genau einmal, an einem Adressanfang und ausserhalb jeder anderen
  Adresse steht (`<p>A &amp; B /?p=12</p>` wird umgeschrieben); sonst
  sperrt sie – nur dekodiert vorhanden (`&#47;?p=12`), zweimal dieselbe
  Adresse (`Preis &lt; 5: /?p=12, dann /?p=12`), Fundstelle in einem Wort
  oder in einer fremden Adresse (`&#47;&#63;p&#61;12 https://other.test/?p=12`)
  –, weil die Rohposition nicht eindeutig ist (Runden 21, 23, 24).
- Ein Verweis relativ zur Seite mit Ziffern (`../12/`, `index.php?p=12`)
  sperrt, weil er gegen die Adresse der Seite aufgelöst wird, die keine
  Regel kennt (Runden 11, 21).

## Abfragewerte (redirect_to, Share-Links)

- Ein kodierter Abfragewert in Klein- oder Mischschreibung der
  Percent-Escapes (`redirect_to=%2f%3fp%3d12`, `%2F?p%3D12`) sperrt, weil
  keine Kodierung (roh, `rawurlencode()`, `urlencode()`) den Wert Byte für
  Byte reproduziert (Runde 23).
- Ein Abfragewert, der tiefer als zwei Stufen verschachtelt ist
  (`?a=enc(/?b=enc(/?c=enc(/?p=12)))`), sperrt, weil die Rundreise sonst
  die Tiefe neu zählte (Runden 22, 23).
- Ein doppelt kodierter Abfragewert
  (`url=https%253A%252F%252Fstaging.test%252F%253Fp%253D12`) sperrt, weil
  erst eine zweite Dekodierung die Adresse zeigt, die kein Leser liest
  (Runden 23, 24).
- Ein Abfragewert in einer Adresse, die selbst nicht umschreibbar ist
  (seitenrelativ `wp-login.php?redirect_to=…`, andere Schreibweise des
  Hosts, die andere Site des Paars), sperrt, weil die äussere Adresse nicht
  kanonisch ist (Runde 23).
- Ein root- oder seitenrelatives Ziel in einem Abfragewert unter einem
  fremden Host – der Wert selbst oder eine Adresse in einem Share-Text –
  sperrt, wenn es eine Objektreferenz trägt, wie der Adressleser sie
  kennt: eine ID-Variable des Kerns mit Ziffern
  (`https://other.test/wp-login.php?redirect_to=%2F%3Fp%3D12`,
  `redirect_to=index.php%3Fp%3D12`, `//other.test/?u=%2F%3Fpage_id%3D13`)
  oder einen Pfad, den die Rewrite-Regeln auf eine ID-Variable abbilden
  (`%2Farchives%2F12%2F`; ohne lesbare Regeln jedes Ziffernsegment), weil
  das Ziel dort das Objekt der fremden Site meint. Ohne Objektreferenz
  bleibt der Wert frei: `?subject=Warum%3F`, `?subject=Preis%201%20%2F2`,
  `?text=Termin%20%2F2026%2F11%2F04`, `redirect_to=%2Fkontakt%2F`
  (Runden 24, 25).
- Eine ID in einem Abfragewert, die der Leser dort nicht wie gespeichert
  liest (eine Variable zweimal, `%2F%3Fp%3D12%26p%3D13`), sperrt den
  ganzen Wert, weil die Rundreise sonst eine andere Stelle umschriebe
  (Runde 23).

## Hosts

- Userinfo vor dem Host (`https://viewer@staging.test/?p=12`,
  `https://_user:pw@staging.test/`), eigener wie fremder Host, auch ohne
  Ziffer, sperrt, weil der URL-Parser einen anderen Host liest als der
  Text zeigt und Zugangsdaten im Text stehen können (Runden 23, 24).
- Ein Percent-Escape im Host oder direkt hinter dem eigenen Host
  (`https://staging.test%2Eother.test/?p=12`, `%73taging.test`) sperrt,
  weil der Browser einen fremden Host liest (Runde 23).

## data:-Adressen und Zeichensätze

- Ein data:-Kopf mit Klammer, ohne Komma innerhalb von 512 Bytes oder mit
  einem Typ, der nach dem Entfernen von Tabs und Zeilenumbrüchen keiner
  ist, sperrt dort, wo die Adresse strukturell als Adresse steht – als
  Wert eines Adressattributs (`src`, `href`, ein `srcset`-Kandidat), als
  Argument von `url()`, `@import` oder als Kandidat von `image-set()` in
  CSS, als Stringwert in geparstem JSON (auch einem Blockattribut oder
  einem Interactivity-Kontext) –, weil der Kopf nicht nach dem
  MIME-Sniffing-Standard lesbar ist. `data:` in Prosa, auch in
  Anführungszeichen (`Format: "Data: CSV/JSON"`), in einem anderen
  Attribut (`title`) oder als JSON-Schlüssel ist keine Adresse
  (Runden 23–25).
- Ein Parameter zweimal im data:-Kopf (`charset=utf-16le;charset=utf-16be`,
  `Name=a;NAME=b`) sperrt, weil der Standard den ersten nähme und das Netz
  nicht entscheidet (Runde 23).
- Ein data:-Körper mit Texttyp, der kein UTF-8 ergibt (UTF-16 ohne BOM und
  ohne XML-Deklaration, Nullbytes) oder einen unbekannten Zeichensatz
  nennt (`charset=koi8-r`), sperrt, weil der Text nicht gelesen werden kann
  (Runden 21–23).
- Eine Verschachtelung von Kodierungen über die Entfaltungsgrenzen hinaus
  (Tiefe 4, 64 Schichten) sperrt die Einheit als nicht zu Ende gelesen
  (Runden 20–22).

## Zugangsdaten in Code

- Code, dessen Klammerzählung nicht entscheidbar ist – ein RegExp-Literal
  mit `}` vor einem Namen (`{r:/}/,api_key:"…"}`, `x:1+/}/.test("x")`,
  `void /}/`, nach einer Division `x:1/2+/}/.test("x")`, `6/2 + /}/`, nach
  der Klammer von `if (a) /}/`) oder mehr geschlossene als geöffnete
  Klammern – sperrt, weil das Netz nicht entscheidet, ob ein Name in einem
  offenen Objekt steht. In der Code-Schicht liest ein Scanner Token für
  Token; ein `/` nach einem Operanden (Bezeichner ausser Schlüsselwörtern,
  auch in Unicode, nach `.` jeder Name als Eigenschaft; Zahl, `)`, `]`,
  String-Ende, Postfix `++`/`--`) ist eine Division, der `/` eines
  HTML-Endtags (`</a>`) beginnt nichts: `a/b/c`, `(1)/2/3`, `x++ / 2 / 3`,
  `obj.if(a) / 2 / 3`, `1 / π / 3` und Prosa mit `</a> …, Ort: Zürich`
  bleiben frei (Runden 23–26).
- Dynamisch zusammengesetzte Namen in JavaScript (`${…}`, Verkettung)
  werden nicht als Zugangsdaten erkannt; das ist eine dokumentierte
  Grenze, keine Sperre (Runde 22).

## HTML ausserhalb der kses-Liste

- Tags und Attribute ausserhalb von `$allowedposttags` (ergänzt um iframe,
  script/style als Daten, img/picture/source mit srcset, sizes, class,
  data-*) sperren als `html_outside_kses`, weil der Kern sie beim Speichern
  ohne `unfiltered_html` entfernte (Runden 16, 23, 24).
- Eine data:-Adresse in `href` oder ein data:-Bild, dessen Bytes nicht zu
  seinem Typ passen (`data:image/png` mit SVG-Bytes), sperrt, weil ein
  eingebettetes Dokument mehr tragen kann als ein Bild (Runden 16, 22).
