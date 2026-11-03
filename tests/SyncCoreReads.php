<?php
/**
 * What the core reads, and nothing rebuilt (Codex, round 10): the tags and
 * the values of their attributes as the HTML API reads them, character
 * references as WP_HTML_Decoder decodes them, a query as parse_str() reads
 * it, a host in lower case without its default port or "www." as
 * url_to_postid() compares it. Rewritten only where the stored form is
 * exactly the one these readings give; every other form locks.
 *
 * One set of cases for both suites. The unit suite (SyncCoreReadsTest)
 * has no HTML API and no WP_HTML_Decoder: a text with a tag or a character
 * reference fails closed there (unreadable, locked); the integration suite
 * (tests/integration/SyncCoreReadsTest.php) reads every case with the
 * core of WordPress 7.1.3 and checks the answer itself.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Credentials;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\Attributes\DataProvider;

trait SyncCoreReads {

	/** Whether the core reads the cases (the integration suite). */
	abstract protected static function core(): bool;

	/** Whether a text fails closed here: a tag or a character reference without the core. */
	private static function closed( string $text ): bool {
		return ! self::core() && 1 === preg_match( '/<[A-Za-z]|&/', $text );
	}

	private static function reads_ctx(): array {
		return AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id );
	}

	private static function reads_plan(): array {
		return AB_MCP_Sync_Refs::plan(
			array(
				'post' => array( 12 => 120, 13 => 130 ),
				'term' => array( 5 => 50, 6 => 60 ),
				'user' => array( 1 => 10 ),
			),
			array( 'https://staging.test' => 'https://www.live.test' )
		);
	}

	/** @return array{0:string[],1:array} The references as space:id@at, the accumulator. */
	private static function reads_scan( string $content ): array {
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( $content, self::reads_ctx(), $acc, 'post', 'c' );
		$refs = array();
		foreach ( $acc['refs'] as $ref ) {
			$refs[] = $ref['space'] . ':' . ( 'user' === $ref['space'] ? $ref['login'] : $ref['id'] ) . '@' . $ref['at'];
		}
		return array( $refs, $acc );
	}

	/** Without the core: locked, and the rewriter writes nothing. */
	private static function assertLockedWithoutTheCore( string $content ): void {
		list( , $acc ) = self::reads_scan( $content );
		self::assertNotSame( array(), array_merge( $acc['spelling'], array_column( $acc['locks'], 'key' ) ), 'Locked: ' . $content );
		self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() )['missing'], 'space' ), $content );
	}

	/* ----------------------------------------------------- credentials */

	public static function credentialKeys(): array {
		return array(
			'two spaces before wp:' => array( '<!--  wp:paragraph {"api_key":"dummy-secret"} /-->' ),
			'an escaped outer tag'  => array( '[[unregistered] [secret api_key="example-only"] [/unregistered]]' ),
			'an unknown outer tag'  => array( '[outer][inner token="x"][/outer]' ),
			'an unknown HTML tag'   => array( '<acme-widget data-secret="x"></acme-widget>' ),
			'a comment'             => array( '<!-- password=hunter2 -->' ),
			'a query'               => array( '<a href="https://example.test/?id=1&amp;API_KEY=x">Link</a>' ),
			'a character reference' => array( '[acme api&#95;key="x"]' ),
			'a percent escape'      => array( 'https://example.test/?api%5Fkey=x' ),
			'a JSON escape'         => array( '{"x":"{\\"client_secret\\":1}"}' ),
			'a JSON unicode escape' => array( '<!-- wp:acme/box {"\\u0061pi_key":"x"} /-->' ),
			'single quotes'         => array( "<script>var o = { 'access_token': 'x' };</script>" ),
			'single quotes in a text' => array( "Konfiguration {'client_secret': 'x'}" ),
			'escaped JSON in a comment' => array( '<!-- {\\"api_key\\":1} -->' ),
			'capitals'              => array( 'PASSWORD = "x"' ),
			'JSON in a comment'     => array( '<!-- {"api_key":"x"} -->' ),
			'escaped JSON in a script' => array( '<script>var c = "{\\"api_key\\": 1}";</script>' ),
			'unicode in a comment'  => array( '<!-- {"\\u0061pi_key":"x"} -->' ),
			// Neither = nor : as stored, only decoded.
			'an encoded equals'     => array( '/?api_key%3Dx' ),
			'an equals as a reference' => array( '/?api_key&#61;x' ),
			'an escaped equals'     => array( '<!-- api_key\\u003dx -->' ),
			// Codex, round 6: do_blocks() joins it to [acme api_key="example-only"].
			'a key split by a block' => array( '[acme api_<!-- wp:paragraph /-->key="example-only"]' ),
			'a key split by two delimiters' => array( '[acme to<!-- wp:paragraph -->ken="x"<!-- /wp:paragraph -->]' ),
			// Codex, round 8: a name without quotes in code.
			'an object in a script'   => array( '<script>window.service = {apiKey: "EXAMPLE_SECRET"};</script>' ),
			'a property in a style'   => array( '<style>.x { --api-key: abc; }</style>' ),
			'an event attribute'      => array( '<button onclick="init({secret: 1})">Los</button>' ),
			'a javascript: address'   => array( '<a href="javascript:login({password: 1})">x</a>' ),
			'a shortcode attribute'   => array( '[acme config="{token: 1}"]' ),
			'a block attribute'       => array( '<!-- wp:acme/x {"js":"{accessToken: 1}"} /-->' ),
			'an arrow in an attribute' => array( '[acme run="() => login(secret: 1)"]' ),
			// Codex, round 8: a field of a form.
			'a field of a form'       => array( '<input type="hidden" name="api_key" value="EXAMPLE_SECRET">' ),
			'a field by its id'       => array( '<input id="token" value="x">' ),
			'a meta tag'              => array( '<meta name="client_secret" content="x">' ),
			'a name as a reference'   => array( '<input title="&quot;a&gt;b&quot;" name="api&#95;key" value="x">' ),
			// Codex, round 9: the whole value of a javascript: address is code.
			'a javascript: address with a string first' => array( '<a href="javascript:connect(\'service\',{apiKey:\'DEMO_SECRET\'})">Connect</a>' ),
			'a javascript: address in capitals, spaced' => array( '<a href="  JavaScript:x(1, {token: 1})">x</a>' ),
			'a javascript: address as references'       => array( '<a href="javascript&colon;x({secret&colon; 1})">x</a>' ),
			'a textarea'              => array( '<textarea name="private_key">DEMO_PRIVATE_KEY</textarea>' ),
			'a select'                => array( '<select id="api_key"><option value="a">A</option><option value="b" selected>B</option></select>' ),
			// Codex, round 10 (1): a reference without ";", as the HTML API decodes it.
			'a reference without a semicolon' => array( '<textarea name="api&#95key">DEMO_SECRET</textarea>' ),
			'a reference without a semicolon in an input' => array( '<input name="api&#95key" value="DEMO_SECRET">' ),
			// Codex, round 10 (2): the URL parser drops tabs and line breaks.
			'a tab in javascript:'        => array( '<a href="java&#x09;script:connect({apiKey:\'DEMO_SECRET\'})">Connect</a>' ),
			'a line break in javascript:' => array( "<a href=\"java\nscript:connect({token:'DEMO_SECRET'})\">Connect</a>" ),
			// Codex, round 10 (3): every field by its name, with a value or without.
			'a ">" in an attribute of an option' => array( '<select name="api_key"><option title="a>b" value="DEMO_SECRET" selected>Key</option></select>' ),
			'a wrong end tag in a textarea'      => array( '<textarea name="private_key"></textareax>DEMO_SECRET</textarea>' ),
			'a select without selected'          => array( '<select name="api_key"><option value="DEMO_SECRET">Key</option></select>' ),
			'an empty field'                     => array( '<input type="password" name="api_key" value="">' ),
			'an empty textarea'                  => array( '<textarea name="api_key"></textarea>' ),
			'a button'                           => array( '<button name="token" value="x">Los</button>' ),
			'an option by its id'                => array( '<select><option id="client_secret">x</option></select>' ),
			'the field of the login form'        => array( '<input type="password" name="pwd" class="token-field">' ),
		);
	}

	#[DataProvider( 'credentialKeys' )]
	public function testEveryKeyOfACredentialInAnySpellingLocks( string $text ): void {
		$check = AB_MCP_Sync_Credentials::unit( '', $text, array() );
		if ( self::closed( $text ) ) {
			self::assertSame( array( 'state' => 'unreadable', 'fields' => array(), 'why' => 'unreadable_value' ), $check, 'Not read without the core: never clean.' );
			return;
		}
		self::assertSame( 'mixed', $check['state'], (string) json_encode( $check ) );
		self::assertNotSame( array(), $check['fields'] );
	}

	public static function credentialProse(): array {
		return array(
			'a field of another name'   => array( '<input type="text" name="login" class="token-field">' ),
			'a label'                   => array( '<label for="password">Passwort:</label>' ),
			'words in JSON'             => array( '{"hasPassword":true,"token_count":3}' ),
			'CSS'                       => array( 'a[href] { color: red } input[type="password"] { }' ),
			'prose'                     => array( 'Das Passwort: geheim. Die Seite = gut.' ),
			'a name before ":" in prose' => array( '<p>Das password: geheim, der Token: 123.</p>' ),
			'prose in a shortcode'      => array( '[acme_note]Ihr secret: bleibt hier.[/acme_note]' ),
			'a script without code'     => array( '<script src="app.js"></script><p>Kontakt: info</p>' ),
			// Codex, round 9: a label of a block, prose in a shortcode attribute.
			'a label of a block'        => array( '<!-- wp:navigation-link {"label":"Password: reset","kind":"custom","url":"/help/"} /-->' ),
			'prose in an attribute'     => array( '[acme_button label="Token: anfordern"]' ),
			// Codex, round 10 (10): parentheses and colons alone are prose.
			'a label with parentheses'  => array( '<!-- wp:navigation-link {"label":"Password: reset (help)","kind":"custom","url":"/help/"} /-->' ),
			'an attribute with parentheses' => array( '[acme_button label="Token: anfordern (kostenlos)"]' ),
			'an attribute with a semicolon' => array( '[acme_note text="Secret: siehe Hilfe; danke (2026)"]' ),
			'a block attribute with an equals' => array( '<!-- wp:acme/x {"hint":"Token: a = b (Beispiel)"} /-->' ),
			// A tag written as text is none: the browser parses the stored text once.
			'an escaped tag'            => array( '&lt;input name="api_key"&gt;' ),
			// Only the fields of a form: an anchor or a box of that name is none.
			'an anchor and a box'       => array( '<a name="password"></a><div id="token">x</div>' ),
		);
	}

	#[DataProvider( 'credentialProse' )]
	public function testWordsAboutCredentialsAreNone( string $text ): void {
		self::assertSame( self::closed( $text ) ? 'unreadable' : 'clean', AB_MCP_Sync_Credentials::unit( '', $text, array() )['state'], $text );
	}

	public function testATagTheHtmlApiCannotFinishIsUnreadable(): void {
		// What follows an unfinished tag is not read: never clean.
		foreach ( array( '<p>Text</p><input name="api_key"', '<p>Text <a href="javascript:x({token: 1})" ' ) as $text ) {
			self::assertSame( 'unreadable', AB_MCP_Sync_Credentials::unit( '', $text, array() )['state'], $text );
		}
	}

	/* ------------------------------------------------------- addresses */

	public static function shortLinks(): array {
		return array(
			// Codex, round 8: WP_Query reads post 12 of ?p=12.
			'p'                    => array( '<!-- wp:paragraph --><p><a href="https://staging.test/?p=12">Beitrag</a></p><!-- /wp:paragraph -->', array( 'post:12@c:core/paragraph?p' ), '<!-- wp:paragraph --><p><a href="https://www.live.test/?p=120">Beitrag</a></p><!-- /wp:paragraph -->' ),
			'categories'           => array( '<p><a href="https://staging.test/?cat=5,6">x</a></p>', array( 'term:5@c:html?cat', 'term:6@c:html?cat' ), '<p><a href="https://www.live.test/?cat=50,60">x</a></p>' ),
			'an author'            => array( '<p>https://staging.test/?author=1</p>', array( 'user:user1@c:html?author' ), '<p>https://www.live.test/?author=10</p>' ),
			'an author in prose'   => array( 'Autor: https://staging.test/?author=1', array( 'user:user1@c:html?author' ), 'Autor: https://www.live.test/?author=10' ),
			'in a block attribute' => array( '<!-- wp:acme/x {"u":"https:\/\/staging.test\/?attachment_id=12"} /-->', array( 'post:12@c:acme/x.u?attachment_id' ), '<!-- wp:acme/x {"u":"https://www.live.test/?attachment_id=120"} /-->' ),
			'subpost_id'           => array( 'Siehe https://staging.test/?subpost_id=12 hier', array( 'post:12@c:html?subpost_id' ), 'Siehe https://www.live.test/?subpost_id=120 hier' ),
			'after an ampersand'   => array( '<p><a href="https://staging.test/?preview=1&amp;p=12">x</a></p>', array( 'post:12@c:html?p' ), '<p><a href="https://www.live.test/?preview=1&amp;p=120">x</a></p>' ),
			'after an ampersand in prose' => array( 'Siehe https://staging.test/?preview=1&p=12 hier', array( 'post:12@c:html?p' ), 'Siehe https://www.live.test/?preview=1&p=120 hier' ),
			'in another attribute' => array( '<p><span data-target="https://staging.test/?p=12">x</span></p>', array( 'post:12@c:html?p' ), '<p><span data-target="https://www.live.test/?p=120">x</span></p>' ),
		);
	}

	#[DataProvider( 'shortLinks' )]
	public function testTheIdsOfAShortLinkAreReferences( string $content, array $want, string $to ): void {
		if ( self::closed( $content ) ) {
			self::assertLockedWithoutTheCore( $content );
			return;
		}
		list( $refs, $acc ) = self::reads_scan( $content );
		self::assertSame( array( $want, array(), array() ), array( $refs, $acc['locks'], $acc['spelling'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
		self::assertSame( array( $to, array() ), array( $out['content'], $out['missing'] ) );
	}

	public static function safeAddresses(): array {
		return array(
			// The safe forms: with the host (also without the scheme), from the root.
			'from the root'                => array( '<p><a href="/?p=12">x</a></p>', '<p><a href="/?p=120">x</a></p>' ),
			'a path from the root'         => array( '<p><a href="/blog/?page_id=13">x</a></p>', '<p><a href="/blog/?page_id=130">x</a></p>' ),
			'without the scheme'           => array( '<p><a href="//staging.test/?p=12">x</a></p>', '<p><a href="//www.live.test/?p=120">x</a></p>' ),
			'a navigation link'            => array( '<!-- wp:navigation-link {"label":"Beitrag","kind":"custom","url":"/?p=12"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","kind":"custom","url":"/?p=120"} /-->' ),
			'a navigation link and its id' => array( '<!-- wp:navigation-link {"label":"Beitrag","id":12,"kind":"post-type","url":"/?p=12"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","id":120,"kind":"post-type","url":"/?p=120"} /-->' ),
			'a full address and its id'    => array( '<!-- wp:navigation-link {"label":"Beitrag","id":12,"kind":"post-type","url":"https://staging.test/beitrag/"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","id":120,"kind":"post-type","url":"https://www.live.test/beitrag/"} /-->' ),
			'an address by its value'      => array( '<!-- wp:acme/x {"target":"/?p=12"} /-->', '<!-- wp:acme/x {"target":"/?p=120"} /-->' ),
			'a shortcode attribute'        => array( '[acme_button url="/?p=12"]', '[acme_button url="/?p=120"]' ),
			'spaces before'                => array( '<p><a href=" /?p=12">x</a></p>', '<p><a href=" /?p=120">x</a></p>' ),
			'in a sentence'                => array( 'Mehr unter https://staging.test/?p=12.', 'Mehr unter https://www.live.test/?p=120.' ),
			'an image and its address'     => array( '<!-- wp:image {"id":12} --><figure><img src="https://staging.test/a.jpg"/></figure><!-- /wp:image -->', '<!-- wp:image {"id":120} --><figure><img src="https://www.live.test/a.jpg"/></figure><!-- /wp:image -->' ),
		);
	}

	#[DataProvider( 'safeAddresses' )]
	public function testAnAddressInASafeFormIsRewrittenWithItsIds( string $content, string $to ): void {
		if ( self::closed( $content ) ) {
			self::assertLockedWithoutTheCore( $content );
			return;
		}
		list( , $acc ) = self::reads_scan( $content );
		self::assertSame( array( array(), array() ), array( $acc['locks'], $acc['spelling'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
		self::assertSame( array( $to, array() ), array( $out['content'], $out['missing'] ) );
	}

	public static function unsafeAddresses(): array {
		return array(
			// Relative to the page: what it resolves to, the page tells.
			'only a query'           => array( '<p><a href="?p=12">x</a></p>', 'c:html?p' ),
			'relative to the path'   => array( '<p><a href="./?p=12">x</a></p>', 'c:html?p' ),
			'index.php'              => array( "<p><a href='index.php?p=13'>y</a></p>", 'c:html?p' ),
			'an unquoted source'     => array( '<p><img src=?attachment_id=12></p>', 'c:html?attachment_id' ),
			'a navigation link'      => array( '<!-- wp:navigation-link {"label":"B","kind":"custom","url":"?p=12"} /-->', 'c:core/navigation-link.url?p' ),
			'an address by its name' => array( '<!-- wp:acme/x {"buttonUrl":"index.php?p=12"} /-->', 'c:acme/x.buttonUrl?p' ),
			// Codex, round 9: the pair as stored is not name=digits.
			'a list with a space'    => array( '<p><a href="https://staging.test/?cat=5, 6">x</a></p>', 'c:html?cat' ),
			'a list with a plus'     => array( '<p><a href="/?cat=5+6">x</a></p>', 'c:html?cat' ),
			'a name escaped'         => array( '<p><a href="/?%70=12">x</a></p>', 'c:html?p' ),
			'a value escaped'        => array( '<p><a href="https://staging.test/?p=%312">x</a></p>', 'c:html?p' ),
			'a list of a name'       => array( '<p><a href="/?p[]=12">x</a></p>', 'c:html?p' ),
			'a space before the name' => array( '<p><a href="/?%20p=12">x</a></p>', 'c:html?p' ),
			'a leading zero'         => array( '<p><a href="/?p=012">x</a></p>', 'c:html?p' ),
			'a leading zero in prose' => array( 'Siehe https://staging.test/?p=012 hier', 'c:html?p' ),
			'a list for one id'      => array( '<p><a href="/?p=12,13">x</a></p>', 'c:html?p' ),
			'a sign in a list'       => array( 'https://staging.test/?cat=-5', 'c:html?cat' ),
			'a sign in a longer list' => array( 'https://staging.test/?cat=5,-6', 'c:html?cat' ),
			'an exponent'            => array( 'https://staging.test/?author=1e1', 'c:html?author' ),
			// Codex, round 10 (1): a reference without ";" names p=12.
			'a name as a reference without ";"' => array( '<p><a href="/?&#112=12">x</a></p>', 'c:html?p' ),
			'an equals as a reference'          => array( '<p><a href="https://staging.test/?p&#61;12">x</a></p>', 'c:html?p' ),
			// Codex, round 10 (4): the name as parse_str() reads it.
			'a dot in the name'      => array( '<p><a href="/?page.id=12">x</a></p>', 'c:html?page_id' ),
			'a dot in the name in prose' => array( 'Siehe https://staging.test/?page.id=12 hier', 'c:html?page_id' ),
			'a plus in the name'     => array( '<p><a href="/?page+id=12">x</a></p>', 'c:html?page_id' ),
			'a plus before the name' => array( '<p><a href="/?+p=12">x</a></p>', 'c:html?p' ),
			'a NUL in the name'      => array( '<p><a href="/?p%00suffix=12">x</a></p>', 'c:html?p' ),
			'a space in the name in prose' => array( 'Siehe https://staging.test/?page%20id=12 hier', 'c:html?page_id' ),
			// Codex, round 10 (5): the same site in another spelling.
			'capitals in the host'   => array( '<p><a href="https://STAGING.test/?p=12">x</a></p>', 'c:html?p' ),
			'capitals without the scheme' => array( '<p><a href="//STAGING.test/?p=12">x</a></p>', 'c:html?p' ),
			'the default port'       => array( '<p><a href="https://staging.test:443/?p=12">x</a></p>', 'c:html?p' ),
			'the default port of http' => array( 'Siehe http://staging.test:80/?p=12 hier', 'c:html?p' ),
			'www.'                   => array( '<p><a href="https://www.staging.test/?p=12">x</a></p>', 'c:html?p' ),
			'capitals in prose'      => array( 'Siehe https://Staging.Test/?page_id=12 hier', 'c:html?page_id' ),
			'www. in prose'          => array( 'Siehe https://www.staging.test/?p=12 hier', 'c:html?p' ),
			// What the HTML API reads, not a pattern: an unfinished tag, a
			// spelling inside another attribute, the name twice, a reference
			// without ";" in a text.
			'an unfinished tag'      => array( '<p><a href="/?p=12">x</a> <a href="/?p=13"', 'c:html:tags' ),
			'the spelling in another attribute' => array( "<p><a title=' href=\"/?p=12\"' href='/?p=12'>x</a></p>", 'c:html?p' ),
			'the name twice'         => array( '<p><a href="https://staging.test/?p=12&amp;p=13">x</a></p>', 'c:html?p' ),
			'a reference without ";" in a text' => array( '<p>https://staging.test/?x=1&ampp=12</p>', 'c:html?p' ),
			'a reference in a text'  => array( '<p>https://staging.test/?p=1&#50;</p>', 'c:html?p' ),
			// The attributes of a shortcode as the core reads them.
			'inside [[…]]'           => array( '[[outer] [acme_button url="/?p=12"] [/outer]]', 'c:html?p' ),
			'the same attribute twice' => array( '[acme_button url="/?p=12" url="/?p=12"]', 'c:html?p' ),
			'an escape in a shortcode' => array( '[acme_button url="/\\?p=12"]', 'c:html?p' ),
		);
	}

	#[DataProvider( 'unsafeAddresses' )]
	public function testAnAddressWithAnIdInAnyOtherFormLocks( string $content, string $at ): void {
		if ( self::closed( $content ) ) {
			self::assertLockedWithoutTheCore( $content );
			return;
		}
		list( $refs, $acc ) = self::reads_scan( $content );
		self::assertSame( array(), $refs );
		$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
		self::assertContains( array( '*:reference_spelling', $at ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $result['locks'] ) );
		self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() )['missing'], 'space' ) );
	}

	public static function noAddresses(): array {
		return array(
			'another host'             => array( '<p><a href="//other.test/?p=12">x</a></p>' ),
			'another scheme, a fragment' => array( '<p><a href="mailto:a@b.test?p=12">x</a> <a href="#?p=12">y</a></p>' ),
			'prose without a host'     => array( 'Siehe ?p=12 und /?p=12 im Text.' ),
			'no address attribute'     => array( '<p><a data-href="/?p=12">x</a></p>' ),
			'another port'             => array( 'Siehe https://staging.test:8443/?p=12 und http://staging.test:443/?p=12' ),
			'a longer host'            => array( 'Siehe https://staging.test.evil.com/?p=12' ),
			'a variable that is no id' => array( '<p><a href="https://staging.test/?paged=2">y</a> https://staging.test/?pp=12</p>' ),
			'a fragment in a block attribute' => array( '<!-- wp:acme/x {"url":"#?p=12"} /-->' ),
			'an escaped shortcode'   => array( '[[acme_button url="/?p=12"]]' ),
			// Codex, round 10 (9): a file relative to the page names no object.
			'a file relative to the page'    => array( '<p><img src="photo2.jpg"></p>' ),
			'an upload relative to the page' => array( '<p><img src="wp-content/uploads/2026/11/photo.jpg"></p>' ),
		);
	}

	#[DataProvider( 'noAddresses' )]
	public function testWhatNamesNoObjectOfTheSitesIsNone( string $content ): void {
		list( $refs, $acc ) = self::reads_scan( $content );
		self::assertSame( array(), $refs, $content );
		if ( self::closed( $content ) ) {
			return;
		}
		self::assertSame( array( array(), array() ), array( $acc['locks'], $acc['spelling'] ), $content );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
		self::assertSame( array(), $out['missing'], $content );
	}

	public function testAShortLinkInJsonAfterAnAmpersandIsAReference(): void {
		// As JSON stores it: a slash as \/, "&" as \u0026; read as HTML too.
		$json    = '{"url":"https:\/\/staging.test\/?preview=1\u0026p=12"}';
		$acc     = AB_MCP_Sync_Canon::acc();
		$changed = false;
		AB_MCP_Sync_Canon::value( $json, self::reads_ctx(), $acc, 'post', 'meta:_json' );
		$plan = self::reads_plan();
		$out  = AB_MCP_Sync_Refs::rewrite_value( $json, $plan, $changed );
		if ( ! self::core() ) {
			self::assertSame( array( array(), array( 'meta:_json?' ) ), array( $acc['refs'], $acc['spelling'] ), 'Without WP_HTML_Decoder: locked.' );
			return;
		}
		self::assertSame( array( 'meta:_json?p' ), array_column( $acc['refs'], 'at' ) );
		self::assertSame( '{"url":"https:\/\/www.live.test\/?preview=1\u0026p=120"}', $out );
	}

	public function testFromTheRootOnlyUnderTheFolderOfTheSiteAndWhereBothSitesShareIt(): void {
		$ctx  = AB_MCP_Sync_Canon::context( array( 'https://staging.test/blog' ), static fn( $id ) => 'user' . $id );
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array( 'https://staging.test/blog' => 'https://www.live.test/blog' ) );
		foreach ( array(
			'[acme_button url="/blog/?p=12"]'               => true,
			'[acme_button url="/blog?p=12"]'                => true,
			'[acme_button url="//staging.test/blog/?p=12"]' => true,
			'[acme_button url="/?p=12"]'                    => false,
			'[acme_button url="/blogx/?p=12"]'              => false,
			'[acme_button url="//staging.test/?p=12"]'      => false,
			'<p><a href="/blog/?p=12">x</a></p>'            => true,
			'<p><a href="/blogx/?p=12">x</a></p>'           => false,
		) as $content => $site ) {
			if ( self::closed( $content ) ) {
				continue;
			}
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
			self::assertSame( $site ? array( 12 ) : array(), array_column( $acc['refs'], 'id' ), $content );
			self::assertSame( $site, false !== strpos( AB_MCP_Sync_Refs::rewrite_content( $content, $plan )['content'], 'p=120' ), $content );
		}
		// Another root on the other side: from the root, nothing is rewritten.
		$moved = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array( 'https://staging.test/blog' => 'https://www.live.test' ) );
		self::assertSame( array( 'spelling' ), array_column( AB_MCP_Sync_Refs::rewrite_content( '[acme_button url="/blog/?p=12"]', $moved )['missing'], 'space' ) );
		$both = AB_MCP_Sync_Canon::context( array( 'https://staging.test/blog', 'https://www.live.test' ), static fn( $id ) => 'user' . $id );
		$acc  = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '[acme_button url="/blog/?p=12"]', $both, $acc, 'post', 'c' );
		self::assertSame( array( 'c:html?p' ), $acc['spelling'] );
	}

	public function testAnIdInAnAddressOfTheOtherSiteLocks(): void {
		$ctx     = AB_MCP_Sync_Canon::context( array( 'https://staging.test', 'https://www.live.test' ), static fn( $id ) => 'user' . $id, null, array( 'https://staging.test' ) );
		$content = 'Siehe https://www.live.test/?p=120 und https://www.live.test/archives/120 und https://staging.test/?p=12 und https://WWW.LIVE.TEST/?page_id=13';
		$acc     = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
		self::assertSame( array( 12 ), array_column( $acc['refs'], 'id' ) );
		self::assertSame( array( 'c:html?p', 'c:html%post_id%', 'c:html?page_id' ), $acc['spelling'], 'The id space and the permalinks of the other site are not this one\'s.' );
		if ( self::core() ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( '<p><a href="https://www.live.test/?p=120">x</a> <a href="https://staging.test/?p=12">y</a></p>', $ctx, $acc, 'post', 'c' );
			self::assertSame( array( array( 12 ), array( 'c:html?p' ) ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ) );
		}
	}
}
