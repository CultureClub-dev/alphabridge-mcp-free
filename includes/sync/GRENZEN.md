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

## Serialisierte Werte (Zweig 11b)

- Seit Zweig 11b liest das Netz die Zugangsdaten einer Meta-Zeile, einer
  Option und eines Widget-Bereichs entpackt, jeden Schlüssel und jedes
  Blatt für sich; die Verweise zählt es weiter über den Rohwert
  (GRENZEN-STRUKTUR.md, Abschnitt «Serialisierte Werte im Netz»). Ein
  serialisierter Wert, der aus seinem Wert nicht Byte für Byte zurück
  serialisiert, wird zusätzlich über jede Zeichenkette seines Formats
  gelesen, jede für sich, weil das Entpacken dort etwas verworfen haben
  kann (ein Schlüssel zweimal, Bytes nach dem Ende). Ein Wert, bei dem
  nichts verloren ging (eine Gleitkommazahl, geschrieben mit einer anderen
  `serialize_precision` als der der prüfenden Site: 17 Stellen,
  `d:0.10000000000000001;`, schreibt jedes PHP mit
  `serialize_precision=17`, auch PHP 8.5, und PHP vor 7.1 als Vorgabe;
  ein Schlüssel aus Ziffern als Text), urteilt so wie sein Inhalt: Prosa
  darin bleibt frei (SyncNetTest
  `testAValueThatDoesNotSerializeBackIsReadByTheStringsOfItsFormat`).

## Zugangsdaten nach festen Formaten (Zweig 11b)

Neben den Namen mit Wert (oben) zählt das Netz feste Formate, wo immer sie
stehen und welcher Name auch davorsteht: im einfachen Wert, im Blatt eines
serialisierten Werts (auch verschachtelt), in `post_content`, in Meta, in
einer Option und im Widget, in jeder Schicht (Dekodierungen, Base64,
`data:`). Neuansatz nach dem Stopp (Thomas 09.11.2026 früh, «a»,
decisions.md). Jede Regel wurde vor dem Commit in WordPress über die zehn
Tutorials (`tests/fixtures/sync-tutorials.php`) und 47 Prosafälle in den
sieben Formen gezählt: gegenüber `8df6146` sperrt kein gewöhnlicher Text
neu.

- Tokens eines Dienstes nach Präfix und Form (`TOKENS`): `ghp_` und 36
  Buchstaben und Ziffern, `github_pat_` und 36 oder mehr, `sk_live_` und
  `rk_live_` mit 24, `AKIA` und 16 Grossbuchstaben und Ziffern, `xoxb-` und
  `xoxp-` mit ihren Nummern, Mailchimp mit 32 Hex und `-us` mit der Nummer
  des Rechenzentrums (aufgenommen, weil zwei der zehn Tutorials seinen
  Schlüssel nennen, keines in dieser Form). Die Form entscheidet, kein Wort
  darin: `…EXAMPLE` (das Beispiel von AWS), `fake`, `my_` im Token sperren
  (Codex-Runde 3 zu 11b, A1).
- Ein JSON Web Token (`JWT`): drei Teile base64url, der Kopf `eyJ…` ein
  Objekt von JSON mit `alg`, Nutzlast und Signatur base64url. Gekürzt
  (`eyJhbGciOi…`) hat er keine zwei Punkte.
- Der Header `Authorization` (auch `Proxy-Authorization`) mit `Bearer`
  oder `Basic` am Anfang einer Zeile oder eines Felds (`AUTHORIZATION`): am
  Textanfang, nach einem Zeilenumbruch, nach «;» (Zeile mit Headern), nach
  einem Anführungszeichen (`-H "…"`) oder nach dem «:» eines Header-Arrays.
  `Bearer` sperrt mit jedem Token, der kein Platzhalter ist, auch kurz
  (`abc123`, `TOKEN`, `your_api_token_goes_here`; Codex-Runde 1 zu 11b,
  A1: `Accept: …; Authorization: Bearer REVIEW_ONLY`). `Basic` sperrt, wo
  das Base64 zu `user:password` dekodiert und das Passwort kein Platzhalter
  ist, auch `user:password` und das Beispiel `Aladdin:open sesame` von
  RFC 7617; mit leerem Passwort entscheidet der Benutzer (ein Schlüssel als
  Benutzer). Der Wert endet an «;» und «,»: ein Feld danach wird als eigenes
  gelesen (Codex-Runde 3 zu 11b, A3). `"Authorization":` von JSON und
  `Authorization=` oder `=>` zählen wie bisher nach dem Namen.
- Ein privater Schlüssel in PEM (`PEM`: RSA, EC, OPENSSH, ENCRYPTED, PGP …
  PRIVATE KEY, bis zur END-Zeile derselben Bezeichnung): wo sein Körper
  ohne die Kopfzeilen eines verschlüsselten Schlüssels und ohne die
  Prüfsumme von PGP gültiges Base64 ist (nur das Alphabet mit Auffüllung,
  eine Länge in Vielfachen von vier), auch auf einer Zeile oder mit `<br>`.
  Frei bleiben ein gekürztes Beispiel (`...`, `…`), Text im Block
  («(dein Schlüssel)», «Hier kommt der Key 2 hin»), ein Wort einer anderen
  Länge, ein Satz mit BEGIN und END, öffentliche Schlüssel und
  Zertifikate.
- Der Wert eines Login-Cookies (`COOKIE`) in der Form von
  `wp_generate_auth_cookie()`: ab dem ersten «|» der Ablauf mit zehn
  Ziffern, das Token mit 43 Buchstaben und Ziffern, das HMAC mit 64 Hex,
  jedes «|» roh oder URL-kodiert. Mit dem Namen des Cookies oder ohne (ein
  Blatt für sich), was auch immer der Benutzer ist: mit Leerzeichen roh,
  als «+» und als `%20` (Codex-Runde 3 zu 11b, A4). Frei bleiben der Name
  allein (`wordpress_sec_[hash]`), ein gekürzter Wert, `deleted` und
  `wordpress_test_cookie`.
- Passwort- und Secret-Felder nur in Code- und Konfigurationsform: `=`,
  `=>`, `"password":` von JSON, das Array von PHP (alle schon vorher) und
  neu der Schlüssel des serialisierten Formats mit Wert
  (`s:8:"password";s:…;`, `i:`, `d:`) in einem Text, der nicht entpackt
  wird (eine Spalte, ein Blatt, ein Bruchstück in Prosa).
- Ein Platzhalter ist nur einer in exakter Form als ganzer Wert
  (`PLACEHOLDER`): `<token>` (in HTML `&lt;token&gt;`), `{var}`, `{{var}}`,
  `$VAR`, `${VAR}`, `YOUR_` mit Grossbuchstaben (`YOUR_TOKEN`,
  `YOUR_PASSWORD`), `xxx` (auch in Gruppen, `xxxx xxxx`), `***`, `...`,
  `…`, `[REDACTED]`; nie als Teil eines Worts (Codex-Runde 3 zu 11b, A1).

Grenzen (Gruppe C):

- Zugangsdaten in Prosa sperren nicht, wie auf `8df6146`: «password:
  Sommer2026» im Beitragstext, `<p>password: …</p>` vor einem Endtag
  (Codex-Runde 3 zu 11b, A2), «user: anna, password: …» (A3), «Token:
  Zugreihenfolgemarkierung», «Password: RESET». Grund: `wp_get_post` gibt
  den Rohinhalt eines Beitrags ohnehin aus
  (`includes/tools/class-tools-content.php:83`); verweigert werden dort nur
  Meta-Schlüssel mit Namen eines Zugangsdatums. Prosa zeigt das
  Lesewerkzeug also schon heute; das Paket zeigt nichts, was es nicht
  zeigt. Ein Name mit Doppelpunkt in Prosa lässt sich nach der Form nicht
  von einem Satz unterscheiden (der Versuch auf `heikel/z8-netz-referenz`
  scheiterte an A2 und A3); die festen Formate oben schon.
- `Authorization` im Satz («den Header Authorization: Basic mit …»), nach
  einem Tag (`<code>Authorization: …`) oder nach «,» steht am Anfang
  keiner Zeile und keines Felds und zählt nicht nach seinem Wert; so
  bleiben die Beispiele in Code-Blöcken (T1, T3, T10) frei. Ein Token
  einer festen Form sperrt auch dort.
- Andere Schemata als Bearer und Basic (`Token`, `Digest`) zählen nicht
  nach dem Wert, und `Bearer` ohne `Authorization` davor nicht
  (`Bearer REVIEWONLY`, «This is a bearer instrument.»); ein Token einer
  festen Form darin sperrt.
- Ein gekürzter Wert am Anfang einer Zeile (`Authorization: Bearer
  eyJhbGciOi…`) sperrt: «…» im Wort ist kein Platzhalter. Ein Platzhalter
  `<token>` unkodiert in einem Text sperrt in WordPress weiter als Tag
  ausserhalb der kses-Liste (vorbestehend).
- `define( 'DB_PASSWORD', '…' )` aus `wp-config.php` ist keine der Formen
  oben und bleibt frei wie auf `8df6146` (Tutorials zu `wp-config.php`
  zeigen `password_here`).
- Ein JWT ohne Signatur (`alg: none`, zwei Punkte und nichts danach) und
  ein Login-Cookie in der Form vor WordPress 4.0 (ohne Token) zählen nicht
  nach der Form.
