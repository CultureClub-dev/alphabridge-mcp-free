<?php
/**
 * Ordinary tutorial and support texts, written freely as in real blog posts
 * with their placeholders (block markup as the editor stores it), for the
 * count of the patterns of credentials of branch 11b (the steering,
 * 09.11.2026): name => post_content. T2 (PHP code with
 * 'Authorization' => 'Bearer ') and T6 (.env with API_KEY=) lock already
 * on 8df6146 by the names before = and :; every other one is free. The
 * frames of PEM in T4 are put together at run time, as the tokens of the
 * tests are (tests/fixtures/sync-tokens.php).
 *
 * @package AlphaBridge_MCP
 */
$code = static fn( string $c ): string => '<!-- wp:code --><pre class="wp-block-code"><code>' . htmlspecialchars( $c, ENT_NOQUOTES ) . '</code></pre><!-- /wp:code -->';
$p    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$key  = 'PRIVATE' . ' KEY';
return array(
	'T1 REST-API mit Anwendungspasswort' =>
		$p( 'Seit WordPress 5.6 kannst du die REST-API mit einem Anwendungspasswort ansprechen. Lege es unter Benutzer → Profil an und kopiere es sofort, es wird nur einmal angezeigt.' )
		. $code( 'curl --user "admin:xxxx xxxx xxxx xxxx xxxx xxxx" https://example.com/wp-json/wp/v2/posts' )
		. $p( 'Alternativ schickst du den Header selbst mit. Der Wert ist Base64 von <em>benutzer:anwendungspasswort</em>:' )
		. $code( 'Authorization: Basic YWRtaW46eHh4eCB4eHh4IHh4eHggeHh4eCB4eHh4IHh4eHg=' )
		. $p( 'Tipp: Speichere das Passwort nie im Code, sondern in einer Umgebungsvariablen.' ),
	'T2 API mit Bearer-Token aufrufen' =>
		$p( 'Die meisten Dienste erwarten den API-Schlüssel als Bearer-Token im Header. So sieht ein Aufruf mit curl aus:' )
		. $code( "curl https://api.example.com/v1/orders \\\n  -H \"Authorization: Bearer <token>\" \\\n  -H \"Accept: application/json\"" )
		. $p( 'In PHP mit wp_remote_get():' )
		. $code( "\$response = wp_remote_get( 'https://api.example.com/v1/orders', array(\n\t'headers' => array( 'Authorization' => 'Bearer ' . \$token ),\n) );" )
		. $p( 'Ersetze YOUR_TOKEN im Beispiel unten durch deinen eigenen Schlüssel: <code>Authorization: Bearer YOUR_TOKEN</code>. Wer den Token hat, hat Zugriff, also behandle ihn wie ein Passwort.' ),
	'T3 JWT erklärt (gekürzt)' =>
		$p( 'Ein JSON Web Token besteht aus drei Teilen, getrennt durch Punkte: Header, Payload und Signatur. Im Header steht der Algorithmus, etwa HS256.' )
		. $code( 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOi…' )
		. $p( 'Der Server prüft die Signatur mit seinem Schlüssel. Läuft das Token ab (exp), muss der Client ein neues holen. Bearer heisst übrigens «Inhaber»: wer das Token vorzeigt, gilt als berechtigt.' ),
	'T4 SSH-Schlüssel und Zertifikate' =>
		$p( 'Ein privater Schlüssel beginnt immer mit einer BEGIN-Zeile. Gib ihn nie weiter. So sieht er aus (gekürzt):' )
		. $code( '-----BEGIN OPENSSH ' . $key . "-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW...\n...\n-----END OPENSSH " . $key . '-----' )
		. $p( 'Für Let\'s Encrypt liegt der Schlüssel in privkey.pem:' )
		. $code( '-----BEGIN ' . $key . "-----\n(dein Schlüssel)\n-----END " . $key . '-----' )
		. $p( 'Den öffentlichen Schlüssel darfst du teilen: <code>ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl ich@laptop</code>' ),
	'T5 Login-Cookies von WordPress' =>
		$p( 'Nach dem Login setzt WordPress zwei Cookies: wordpress_sec_[hash] für den Admin-Bereich und wordpress_logged_in_[hash] für die Seite. Ihr Wert hat vier Teile, getrennt durch |: Benutzername, Ablaufzeit, Token und HMAC.' )
		. $code( 'Cookie: wordpress_logged_in_5c3e…=admin%7C1700000000%7C…%7C…' )
		. $p( 'Wenn du dich ausloggst, werden die Cookies ungültig. Ein Plugin für Zwei-Faktor-Anmeldung prüft zusätzlich einen Code.' ),
	'T6 Konfiguration mit .env' =>
		$p( 'Lege eine Datei .env im Projektordner an und trage deine Zugangsdaten ein. Die Datei gehört in .gitignore.' )
		. $code( "DB_HOST=localhost\nDB_USER=wordpress\nMAILCHIMP_API_KEY=your-api-key-here\nSTRIPE_SECRET=sk_test_..." )
		. $p( 'Im Code liest du sie mit getenv( \'MAILCHIMP_API_KEY\' ).' ),
	'T7 Newsletter-Plugin einrichten' =>
		$p( 'Kopiere den API-Schlüssel aus deinem Mailchimp-Konto (Profil → Extras → API-Schlüssel). Er sieht etwa so aus: abc123def456-us21. Im Plugin gibst du ihn so an:' )
		. $code( "mailchimp:\n  api_key: abc123def\n  list_id: 1a2b3c4d" )
		. $p( 'Danach auf «Verbinden» klicken. Klappt es nicht, prüfe, ob der Schlüssel noch aktiv ist.' ),
	'T8 Passwort vergessen (Hilfeseite)' =>
		$p( 'Passwort vergessen? Klicke auf der Anmeldeseite auf «Passwort vergessen». Token: Du erhältst einen Link per E-Mail, der 24 Stunden gültig ist.' )
		. $p( 'Benutzername: deine E-Mail-Adresse. Passwort: mindestens zwölf Zeichen, am besten ein Satz.' ),
	'T9 Spielanleitung mit Spielsteinen' =>
		$p( 'Spieler: 2 bis 4, Token: drei pro Person, Dauer: 20 Minuten.' )
		. $p( 'Token: "Ein Spielstein pro Zug" – so steht es auf der Karte. Wer zuerst alle Token abgelegt hat, gewinnt.' ),
	'T10 Basic Auth auf dem Server' =>
		$p( 'Mit HTTP Basic Auth schützt du die Staging-Seite. Der Browser schickt dann bei jeder Anfrage den Header Authorization: Basic mit Benutzer und Passwort in Base64, etwa für «user:password»:' )
		. $code( 'Authorization: Basic dXNlcjpwYXNzd29yZA==' )
		. $p( 'Das bekannte Beispiel aus der Spezifikation ist Aladdin mit «open sesame»: <code>Authorization: Basic QWxhZGRpbjpvcGVuIHNlc2FtZQ==</code>. Base64 ist keine Verschlüsselung!' ),
);
