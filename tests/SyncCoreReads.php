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
			'a comment'             => array( '<!-- password=hunter2 -->' ),
			'a query'               => array( '<a href="https://example.test/?id=1&amp;API_KEY=x">Link</a>' ),
			'a character reference' => array( '[acme api&#95;key="x"]' ),
			'a percent escape'      => array( 'https://example.test/?api%5Fkey=x' ),
			'a JSON escape'         => array( '{"x":"{\\"client_secret\\":1}"}' ),
			'a JSON unicode escape' => array( '<!-- wp:acme/box {"\\u0061pi_key":"x"} /-->' ),
			'single quotes in a text' => array( "Konfiguration {'client_secret': 'x'}" ),
			'escaped JSON in a comment' => array( '<!-- {\\"api_key\\":1} -->' ),
			'capitals'              => array( 'PASSWORD = "x"' ),
			'JSON in a comment'     => array( '<!-- {"api_key":"x"} -->' ),
			'unicode in a comment'  => array( '<!-- {"\\u0061pi_key":"x"} -->' ),
			// Neither = nor : as stored, only decoded.
			'an encoded equals'     => array( '/?api_key%3Dx' ),
			'an equals as a reference' => array( '/?api_key&#61;x' ),
			'an escaped equals'     => array( '<!-- api_key\\u003dx -->' ),
			// Codex, round 6: do_blocks() joins it to [acme api_key="example-only"].
			'a key split by a block' => array( '[acme api_<!-- wp:paragraph /-->key="example-only"]' ),
			'a key split by two delimiters' => array( '[acme to<!-- wp:paragraph -->ken="x"<!-- /wp:paragraph -->]' ),
			// Codex, round 8: a name without quotes in code.
			'a property in a style'   => array( '<style>.x { --api-key: abc; }</style>' ),
			// Codex, round 13: a script of JSON is read as JSON.
			'a key in JSON-LD'        => array( '<script type="application/ld+json">{"@type":"Thing","api_key":"x"}</script>' ),
			// Codex, round 15: a name with a dot, whole and in its parts.
			'a name with a dot'       => array( '<script type="application/json">[{"name":"api_key.v1","value":"demo-secret"}]</script>' ),
			'a dot before the name'   => array( '<script type="application/json">[{"name":"v1.token","value":"x"}]</script>' ),
			// Codex, round 15: the CSS of a style attribute as that of style.
			'a textarea by its id'    => array( '<textarea id="api_key">x</textarea>' ),
			// Codex, round 16 (A3): JSON in an attribute as JSON.
			'JSON in a data attribute' => array( "<div data-config='[{\"name\":\"api_key\",\"value\":\"demo-secret\"}]'></div>" ),
			'JSON in a data attribute as references' => array( '<div data-config="[{&quot;name&quot;:&quot;token&quot;,&quot;value&quot;:&quot;x&quot;}]"></div>' ),
			// Codex, round 16 (A6): the escapes of CSS.
			'an escaped name in a style attribute' => array( '<div style="--api\\2d key: demo-secret">x</div>' ),
			'an escaped name in style' => array( '<style>.x{--client\\-secret:1}</style>' ),
			'a property in a style attribute' => array( '<div style="--api-key: demo-secret">x</div>' ),
			'a property in a style attribute, spaced' => array( '<p style="color:red; --client-secret :x">x</p>' ),
			// Codex, round 17 (A3): read without the comments of CSS.
			'a comment before the colon in style' => array( '<style>.x{--api-key/**/:demo-secret}</style>' ),
			'a comment in a style attribute' => array( '<div style="--client-secret/* Hinweis */ : 1">x</div>' ),
			// Codex, round 18 (A1, A3, B1): a credential in a comment of style;
			// the context of a block with its namespace; JSON in a title as text.
			'a credential in a comment of style' => array( '<style>/* token: abc */ p{color:red}</style>' ),
			'a context with a namespace' => array( '<div data-wp-context=\'acme/store::{"name":"api_key","value":"demo-secret"}\'></div>' ),
			'JSON in a title'      => array( '<p title=\'[{"name":"api_key","value":"x"}]\'>x</p>' ),
			// Codex, round 14 (A1): the same structure as JSON of a meta value.
			'a pair of name and value in JSON' => array( '<script type="application/json">[{"name":"api_key","value":"demo-secret"}]</script>' ),
			'a pair by its key in JSON-LD' => array( '<script type="application/ld+json">{"x":[{"key":"token","value":"x"}]}</script>' ),
			'a type in capitals'      => array( '<script type=" Application/JSON ">{"password":"x"}</script>' ),
			'a key in a JSON string in JSON-LD' => array( '<script type="application/json">{"a":"{\\"token\\":1}"}</script>' ),
			'a shortcode attribute'   => array( '[acme config="{token: 1}"]' ),
			'a block attribute'       => array( '<!-- wp:acme/x {"js":"{accessToken: 1}"} /-->' ),
			'an arrow in an attribute' => array( '[acme run="() => login(secret: 1)"]' ),
			// Codex, round 8: a field of a form.
			'a textarea'              => array( '<textarea name="private_key">DEMO_PRIVATE_KEY</textarea>' ),
			// Codex, round 10 (1): a reference without ";", as the HTML API decodes it.
			'a reference without a semicolon' => array( '<textarea name="api&#95key">DEMO_SECRET</textarea>' ),
			// Codex, round 10 (2): the URL parser drops tabs and line breaks.
			// Codex, round 10 (3): every field by its name, with a value or without.
			'a wrong end tag in a textarea'      => array( '<textarea name="private_key"></textareax>DEMO_SECRET</textarea>' ),
			'an empty textarea'                  => array( '<textarea name="api_key"></textarea>' ),
			'a button'                           => array( '<button name="token" value="x">Los</button>' ),
		);
	}

	public static function scripts(): array {
		return array(
			// Codex, round 13 (A2): JavaScript no parser reads locks the unit.
			// Codex, round 14: every event attribute and javascript: address, any
			// script but data in JSON, whatever they hold.
			'a word in an event attribute' => array( "<button onclick=\"cfg['api_key']=1\">Los</button>" ),
			'a part of a word in an event attribute' => array( "<button onclick=\"open('password-reset')\">Los</button>" ),
			'an escaped word in a javascript: address' => array( "<a href=\"javascript:x('api%5Fkey')\">x</a>" ),
			'an event attribute with a credential' => array( '<button onclick="init({secret: 1})">Los</button>' ),
			'a javascript: address'   => array( '<a href="javascript:login({password: 1})">x</a>' ),
			'a javascript: address with a string first' => array( '<a href="javascript:connect(\'service\',{apiKey:\'DEMO_SECRET\'})">Connect</a>' ),
			'a javascript: address in capitals, spaced' => array( '<a href="  JavaScript:x(1, {token: 1})">x</a>' ),
			'a javascript: address as references'       => array( '<a href="javascript&colon;x({secret&colon; 1})">x</a>' ),
			'a tab in javascript:'        => array( '<a href="java&#x09;script:connect({apiKey:\'DEMO_SECRET\'})">Connect</a>' ),
			'a line break in javascript:' => array( "<a href=\"java\nscript:connect({token:'DEMO_SECRET'})\">Connect</a>" ),
			'a template'                => array( '<script type="text/template"><p>{{ token }}</p></script>' ),
			'an event attribute without a credential' => array( '<button onclick="toggle(this)">Mehr</button>' ),
			'an address in an event attribute' => array( "<p><button onclick=\"location.href='https://staging.test/?p=12'\">x</button></p>" ),
			'a host in another spelling in an event attribute' => array( "<p><button onclick=\"go('https://STAGING.test/?p=12')\">x</button></p>" ),
			'an address in a javascript: address' => array( "<p><a href=\"javascript:location='https://staging.test/archives/12/'\">x</a></p>" ),
			'an id in an event attribute' => array( '<button onclick="location.href=\'/?p=12\'">Weiter</button>' ),
			'an id in a javascript: address' => array( '<a href="javascript:location.href=\'/?p=12\'">Weiter</a>' ),
			'an empty type and a language' => array( '<script type="" language="foo">location.href="/?p=12";</script>' ),
			'a type with parameters'  => array( '<script type="application/json; charset=utf-8">{}</script>' ),
			'a type with a space'     => array( '<script type="module ">x()</script>' ),
			'an unknown type'         => array( '<script type="text/x-handlebars">{{x}}</script>' ),
			'a type of no list'       => array( '<script type="application/x-json">{}</script>' ),
			'a key in brackets'       => array( '<script>window.config = {}; window.config["api_key"] = "demo-secret";</script>' ),
			'single quotes'           => array( "<script>var o = { 'access_token': 'x' };</script>" ),
			'escaped JSON in a script' => array( '<script>var c = "{\\"api_key\\": 1}";</script>' ),
			'an object in a script'   => array( '<script>window.service = {apiKey: "EXAMPLE_SECRET"};</script>' ),
			'an address in a script'  => array( '<script>location.href="/?p=12";</script>' ),
			'an escaped id in a script' => array( '<script>const u="https://staging.test/archives/\\u0031\\u0032/";</script>' ),
			'a script embed'          => array( '<script src="https://cdn.test/app.js"></script><p>Kontakt: info</p>' ),
			'a script without = or :' => array( '<script>alert(1)</script>' ),
			'a module'                => array( '<script type="module">import a from "./a.js";</script>' ),
			'a JavaScript type'       => array( '<script type="text/javascript; charset=utf-8">x()</script>' ),
			'an old language'         => array( '<script language="JavaScript">x()</script>' ),
			// A script of JSON that is no JSON, or nested deeper than read.
			'JSON-LD not JSON'        => array( '<script type="application/ld+json">{"url": /?p=12}</script>', 'unreadable_value' ),
			'JSON-LD too deep'        => array( '<script type="application/ld+json">' . str_repeat( '[', 70 ) . str_repeat( ']', 70 ) . '</script>', 'unreadable_value' ),
		);
	}

	public static function outsideKses(): array {
		return array(
			// Codex, round 15: tags and attributes outside the list of kses.
			'srcdoc'                  => array( '<iframe srcdoc="&lt;script>window[\'api\'+\'_key\']=\'demo-secret\';&lt;/script>"></iframe>' ),
			'srcdoc without a script' => array( '<iframe src="https://www.youtube.com/embed/x" srcdoc="&lt;p>x&lt;/p>"></iframe>' ),
			'SVG with xlink:href'     => array( '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="/?p=12"><text x="0" y="15">Beitrag</text></a></svg>' ),
			'xlink:href on a link'    => array( '<a xlink:href="/?p=12">Beitrag</a>' ),
			'embed'                   => array( '<embed src="https://cdn.test/a.swf">' ),
			'base'                    => array( '<base href="https://staging.test/">' ),
			'meta http-equiv'         => array( '<meta http-equiv="refresh" content="0;url=https://staging.test/?p=12">' ),
			'an attribute of no list' => array( '<a href="https://example.test" ping="https://cdn.test/p">x</a>' ),
			'srcset'                  => array( '<img src="a.jpg" srcset="a-300.jpg 300w" alt="">' ),
			'a tag of a plugin'       => array( '<acme-widget data-x="1"></acme-widget>' ),
			'an input'                => array( '<input type="text" name="q">' ),
			// Codex, round 16 (A4): an embedded document, object with data.
			'a data: document in an iframe' => array( '<iframe src="data:text/html;base64,' . base64_encode( '<script>window.api_key="demo-secret"</script>' ) . '"></iframe>' ),
			'a data: address in capitals' => array( '<a href=" DATA:text/html,x">x</a>' ),
			'a data: image'           => array( '<img src="data:image/png;base64,AAAA" alt="">' ),
			'an object of HTML'       => array( '<object type="text/html" data="https://cdn.test/a.html"></object>' ),
			'a PDF outside the uploads of the site' => array( '<div class="wp-block-file"><object class="wp-block-file__embed" data="https://staging.test/a.pdf" type="application/pdf" style="width:100%;height:600px" aria-label="Embed of a."></object><a href="https://staging.test/a.pdf">a</a></div>' ),
			// Fields of a form outside the list (input, select, option, meta).
			'an unknown HTML tag'   => array( '<acme-widget data-secret="x"></acme-widget>' ),
			'a field of a form'       => array( '<input type="hidden" name="api_key" value="EXAMPLE_SECRET">' ),
			'a field by its id'       => array( '<input id="token" value="x">' ),
			'a meta tag'              => array( '<meta name="client_secret" content="x">' ),
			'a name as a reference'   => array( '<input title="&quot;a&gt;b&quot;" name="api&#95;key" value="x">' ),
			'a select'                => array( '<select id="api_key"><option value="a">A</option><option value="b" selected>B</option></select>' ),
			'a reference without a semicolon in an input' => array( '<input name="api&#95key" value="DEMO_SECRET">' ),
			'a ">" in an attribute of an option' => array( '<select name="api_key"><option title="a>b" value="DEMO_SECRET" selected>Key</option></select>' ),
			'a select without selected'          => array( '<select name="api_key"><option value="DEMO_SECRET">Key</option></select>' ),
			'an empty field'                     => array( '<input type="password" name="api_key" value="">' ),
			'an option by its id'                => array( '<select><option id="client_secret">x</option></select>' ),
			'the field of the login form'        => array( '<input type="password" name="pwd" class="token-field">' ),
			'a field of another name'   => array( '<input type="text" name="login" class="token-field">' ),
		);
	}

	#[DataProvider( 'outsideKses' )]
	public function testHtmlOutsideTheListOfKsesLocksTheUnit( string $text ): void {
		$check = AB_MCP_Sync_Credentials::unit( '', $text, array() );
		self::assertSame( array( 'unreadable', self::core() ? 'html_outside_kses' : 'unreadable_value' ), array( $check['state'], $check['why'] ?? null ), (string) json_encode( $check ) );
	}

	public static function blockOutput(): array {
		return array(
			// What the blocks of the core store, and an embed: inside the list.
			'a paragraph'   => array( '<p class="has-text-align-center">x <strong>y</strong> <em>z</em> <a href="https://example.test" target="_blank" rel="noreferrer noopener">a</a><br>b</p>' ),
			'an image'      => array( '<figure class="wp-block-image size-large is-resized"><img src="https://staging.test/wp-content/uploads/a.jpg" alt="Bild" class="wp-image-12" style="aspect-ratio:1;object-fit:cover" width="300" height="300"/><figcaption class="wp-element-caption">Text</figcaption></figure>' ),
			'a gallery'     => array( '<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><figure class="wp-block-image size-large"><img data-id="12" src="https://staging.test/a.jpg" alt="" class="wp-image-12"/></figure></figure>' ),
			'columns'       => array( '<div class="wp-block-columns are-vertically-aligned-top"><div class="wp-block-column" style="flex-basis:66.66%"><p>x</p></div></div>' ),
			'a table'       => array( '<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>a</th></tr></thead><tbody><tr><td>b</td></tr></tbody><tfoot><tr><td>c</td></tr></tfoot></table><figcaption class="wp-element-caption">t</figcaption></figure>' ),
			'buttons'       => array( '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test" target="_blank" rel="noreferrer noopener">Los</a></div></div>' ),
			'a quote'       => array( '<blockquote class="wp-block-quote"><p>x</p><cite>y</cite></blockquote>' ),
			'a list'        => array( '<ul class="wp-block-list"><li>a<ol><li>b</li></ol></li></ul>' ),
			'a group with a background' => array( '<div class="wp-block-group has-background" style="background-image:url(\'https://staging.test/wp-content/uploads/bg.jpg\');background-size:cover"><p>x</p></div>' ),
			'an embed'      => array( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=x' . "\n" . '</div></figure>' ),
			'a YouTube iframe' => array( '<iframe width="560" height="315" src="https://www.youtube.com/embed/x" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>' ),
			'a cover'       => array( '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-7" alt="" src="https://staging.test/a.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><p>x</p></div></div>' ),
			'a video'       => array( '<figure class="wp-block-video"><video controls src="https://staging.test/a.mp4"></video></figure>' ),
			'a file'        => array( '<div class="wp-block-file"><a id="wp-block-file--media-1" href="https://staging.test/a.pdf">a</a><a href="https://staging.test/a.pdf" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-1">Herunterladen</a></div>' ),
			// Codex, round 16 (B1): the directives of the Interactivity API.
			'an interactive block' => array( '<div data-wp-interactive="acme" data-wp-context=\'{"open":false}\'><button data-wp-on--click="actions.open" data-wp-bind--hidden="!state.open">Öffnen</button></div>' ),
			// Codex, round 17 (A6): the context of a block without an address.
			'an interactive block with a context' => array( '<div data-wp-interactive="acme" data-wp-context=\'{"open":false,"label":"Mehr","count":2}\'><button data-wp-on--click="actions.toggle">Mehr</button></div>' ),
			'CSS with an escape'   => array( '<style>q::before{content:"\\201C"}</style><p style="font-family:&quot;A\\2c B&quot;">x</p>' ),
			// Codex, round 18 (B1): a title is text, also with a bracket.
			'a value with a bracket' => array( '<p title="[Hinweis] Text" aria-label="[1]">x</p><img src="https://staging.test/a.jpg" alt="{x">' ),
			// Codex, round 19 (B-5): data-* other than data-wp-* is text.
			'a tooltip with a bracket' => array( '<span data-tippy-content="[Strg+K] Suche öffnen" data-x="{nicht JSON">Suche</span>' ),
			// Codex, round 18 (B3): the mask of the image block of the core.
			'an SVG mask'          => array( '<style>.is-style-circle-mask img{mask-image:url(\'data:image/svg+xml;utf8,<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>\')}</style>' ),
			// Codex, round 17 (A3): comments and an escape that hides nothing.
			'CSS with comments'    => array( '<style>/* Zitat */ q::before{content:"\\201C"} /* Ende */ a{background:url(https://staging.test/a.png)}</style>' ),
			'details'       => array( '<details class="wp-block-details"><summary>a</summary><p>b</p></details>' ),
			'a heading and code' => array( '<h2 class="wp-block-heading" id="kapitel">K</h2><pre class="wp-block-code"><code>x = 1</code></pre>' ),
		);
	}

	#[DataProvider( 'blockOutput' )]
	public function testTheOutputOfTheBlocksIsInsideTheList( string $text ): void {
		self::assertSame( self::closed( $text ) ? 'unreadable' : 'clean', AB_MCP_Sync_Credentials::unit( '', $text, array() )['state'], $text );
	}

	#[DataProvider( 'scripts' )]
	public function testAScriptOfJavaScriptLocksTheUnit( string $text, string $why = 'script' ): void {
		$check = AB_MCP_Sync_Credentials::unit( '', $text, array() );
		self::assertSame( array( 'unreadable', self::core() ? $why : 'unreadable_value' ), array( $check['state'], $check['why'] ?? null ), (string) json_encode( $check ) );
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
			'a label'                   => array( '<label for="password">Passwort:</label>' ),
			'words in JSON'             => array( '{"hasPassword":true,"token_count":3}' ),
			'CSS'                       => array( 'a[href] { color: red } input[type="password"] { }' ),
			'prose'                     => array( 'Das Passwort: geheim. Die Seite = gut.' ),
			'a name before ":" in prose' => array( '<p>Das password: geheim, der Token: 123.</p>' ),
			'prose in a shortcode'      => array( '[acme_note]Ihr secret: bleibt hier.[/acme_note]' ),
			// Codex, round 9: a label of a block, prose in a shortcode attribute.
			'a label of a block'        => array( '<!-- wp:navigation-link {"label":"Password: reset","kind":"custom","url":"/help/"} /-->' ),
			'prose in an attribute'     => array( '[acme_button label="Token: anfordern"]' ),
			// Codex, round 10 (10): parentheses and colons alone are prose.
			'a label with parentheses'  => array( '<!-- wp:navigation-link {"label":"Password: reset (help)","kind":"custom","url":"/help/"} /-->' ),
			'an attribute with parentheses' => array( '[acme_button label="Token: anfordern (kostenlos)"]' ),
			'an attribute with a semicolon' => array( '[acme_note text="Secret: siehe Hilfe; danke (2026)"]' ),
			'a block attribute with an equals' => array( '<!-- wp:acme/x {"hint":"Token: a = b (Beispiel)"} /-->' ),
			// Codex, round 13: JSON-LD and data blocks are no JavaScript.
			'JSON-LD with an address' => array( '<script type="application/ld+json">{"@type":"Article","url":"https://staging.test/?p=12"}</script>' ),
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

	public function testANameWithADotIsReadWholeAndInItsParts(): void {
		// Codex, round 15: an extra name of the site with a dot, whole.
		self::assertSame( array( 'state' => 'mixed', 'fields' => array( '~my.hidden', 'my.hidden' ) ), AB_MCP_Sync_Credentials::unit( '', '{"my.hidden":"x"}', array( 'my.hidden' ) ) );
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', '{"my.other":"x"}', array( 'my.hidden' ) )['state'] );
		// Codex, round 16 (A1): the outer name (a meta key) the same way.
		self::assertSame( 'b9', AB_MCP_Sync_Credentials::unit( 'v1.token', 'demo-secret', array() )['state'] );
		self::assertSame( 'b9', AB_MCP_Sync_Credentials::unit( 'my.hidden', 'x', array( 'my.hidden' ) )['state'] );
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( 'v1.title', 'x', array() )['state'] );
	}

	public function testEveryNameGoesThroughTheSameCheck(): void {
		// One place asks redacts_key(): names_credential(), for every name.
		$calls = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) as $file ) {
			// Calls, not the names in comments.
			$count = preg_match_all( '/AB_MCP_Redactor::redacts_key\s*\(\s*[$a-z]/', (string) file_get_contents( $file ) );
			if ( $count > 0 ) {
				$calls[ basename( $file ) ] = $count;
			}
		}
		self::assertSame( array( 'class-sync-credentials.php' => 1 ), $calls );
	}

	public function testCssIsReadAsCss(): void {
		// Codex, round 16 (A5, A6): custom_css as CSS, its escapes decoded.
		foreach ( array( '.x { --api-key: demo-secret; }', '.x{--api\\2d key:demo-secret}', '.x { --client\\-secret: 1 }', '.x{--secre\\t:1}' ) as $css ) {
			self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		foreach ( array( '.x { color: red }', 'q::before { content: "\\201C"; }', 'p{font-family:"A\\2c B"}' ) as $css ) {
			self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', '.x { --api-key: demo-secret; }', array() )['state'], 'Not as CSS: prose.' );
		// The key css of global styles is CSS.
		self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', '{"styles":{"css":".x{--api-key:1}"}}', array() )['state'] );
		// Addresses: url(…) without a host read, escapes that hide one lock.
		$ctx = array( 'css' => true ) + self::reads_ctx();
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::text( 'body{background:url("/?p=12")} a{background:url(img/bg2.png)}', $ctx, array(), $acc, 'css' );
		self::assertSame( array( array( 12 ), array() ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ) );
		foreach ( array( 'a{background:url(../12/)}' => 'css%post_id%', 'a{background:url(https://staging\\2e test/?p=12)}' => 'css:css' ) as $css => $at ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			self::assertSame( array( $at ), $acc['spelling'], $css );
		}
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::text( 'q::before{content:"\\201C"} body{background:url(https://staging.test/a.png)}', $ctx, array(), $acc, 'css' );
		self::assertSame( array(), $acc['spelling'], 'An escape that hides no address.' );
	}

	public function testCssIsReadWithoutItsComments(): void {
		// Codex, round 17 (A3): the comments outside of strings removed first.
		foreach ( array( '.x{--api-key/**/:demo-secret}', ".x{--client-secret/* Hinweis */\n: 1}", '.x{--to/* */ken:1}' ) as $css ) {
			self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		// A comment is none in a string, nor after an escape; a string ends at
		// a line break, an escaped one goes on.
		foreach ( array( 'q::before{content:"/*"} .x{--api-key:1} q::after{content:"*/"}', ".x{content:'a\\'/*'} .y{--token:1} .z{content:'*/'}", ".x{content:\"a\\\r\n/*\"} .y{--secret:1} .z{content:\"*/\"}", '.x\\/*{--api-key:1} .y{content:"*/"}' ) as $css ) {
			self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', '/* Kopfzeile */ q::before{content:"\\201C"} /* Ende */', array(), true )['state'] );
		// Codex, round 18 (A1): the credentials read the comments as well (the
		// text is taken over as it is), with the rule of names and values.
		foreach ( array( "/* api_key: sk_live_EXAMPLE */\nbody { color: red }", '/* client_secret=geheim */ a{color:red}' ) as $css ) {
			self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		foreach ( array( '/* design tokens */ a{color:red}', '/* Hinweis: Passwortfeld unten */ a{color:red}' ) as $css ) {
			self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		$ctx  = array( 'css' => true ) + self::reads_ctx();
		$read = static function ( string $css ) use ( $ctx ): array {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			return array( array_column( $acc['refs'], 'id' ), $acc['spelling'] );
		};
		// A comment inside url(…) or an address of a site locks, never rewritten.
		foreach ( array( 'a{background:url(/?p=1/**/2)}', 'a{background:url(/**/"/?p=12")}', 'a{--u:https://staging.te/**/st/?p=12}', 'a{--u:https://staging.test/?p=1/* x */2}' ) as $css ) {
			self::assertContains( 'css:css', $read( $css )[1], $css );
		}
		// Beside it, the addresses are read and rewritten where they stand.
		self::assertSame( array( array( 12 ), array() ), $read( '/* Kopf */ body{background:url(/?p=12)} /* Fuss */' ) );
		self::assertSame( array( array( 12 ), array() ), $read( 'a{background:/**/url(/?p=12)/**/}' ), 'Before and after url(…): not inside.' );
		self::assertSame( array( array(), array() ), $read( 'a{--u: /* x */https://staging.test/a.png} b{--v:https://staging.test/b.png/* y */ } c{--x:a/**/b}' ), 'Beside an address, or inside a word that is none.' );
		// What a comment holds is none: to its end, to the end of the text,
		// after a string a line break ended.
		self::assertSame( array( array(), array() ), $read( 'a{color:red} /* b{background:url(/?p=12)}' ) );
		self::assertSame( array( array(), array() ), $read( "a::after{content:\"<a href='/?p=12'>\"}" ), 'CSS holds no tags.' );
		// A string in single quotes and one an escaped CR LF continues hold
		// no comment: what follows is read.
		self::assertSame( array( array( 12 ), array() ), $read( "a{content:'/*'} b{background:url(/?p=12)} c{content:'*/'}" ) );
		self::assertSame( array( array( 12 ), array() ), $read( "a{content:\"x\\\r\n/*\"} b{background:url(/?p=12)} c{content:\"*/\"}" ) );
		foreach ( array( "\n", "\r", "\f" ) as $break ) {
			self::assertSame( array( array(), array() ), $read( 'a{content:"x' . $break . '/*"} b{background:url(/?p=12)} c{content:"*/"}' ), (string) json_encode( $break ) );
		}
		self::assertSame( array( array(), array() ), $read( '/* Kopfzeile */ q::before{content:"\\201C"} /* Ende */ a{background:url(https://staging.test/a.png)}' ), 'Comments and an escape that hides nothing: free.' );
		$plan = self::reads_plan();
		$changed = false;
		self::assertSame( '/* a */ body{background:url(/?p=120)}', AB_MCP_Sync_Refs::rewrite_text( '/* a */ body{background:url(/?p=12)}', $plan, array(), $changed, false, true ) );
	}

	public function testEscapesInCssThatGiveAnAddressLock(): void {
		// Codex, round 17 (A1), round 18 (A4): a backslash outside of strings
		// and comments (a name, a function, an at-rule); in a string, escapes
		// that give an address, a segment of digits or an id after "?"; a
		// backslash in an address.
		$ctx = array( 'css' => true ) + self::reads_ctx();
		foreach ( array(
			'a{background:u\\72l(/?p=12)}',
			'a{background:U\\52L(/?page_id=13)}',
			'@\\69mport "/?p=12";',
			'@imp\\6Frt "/?p=12";',
			'a{background-image:image-s\\65t("/?p=12" 1x)}',
			'a{color:re\\64}',
			'p{--u:\\2f x}',
			'a:n\\6Ft(.b){color:red}',
			'a{--u:";" \\64 x}',
			'a{--u:\\64 "{"}',
			'a{--u:\\64 "\\{"}',
			'@me\\64ia screen{a{color:red}}',
			'a{background:url(/?p=1\\32)}',
			'a{background:url("/?p=1\\32")}',
			'a{background:url(https://staging.test/?p=1\\32)}',
			'@import "/?p=1\\32";',
			'a{--u:"\\2f archives\\2f 12"}',
			'a{--u:"https://staging\\2e test/?p=12"}',
			'a{--u:"\\3f p=12"}',
			'a{background:url(/?p=12 x)}',
			'@import x;',
		) as $css ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			self::assertContains( 'css:css', $acc['spelling'], $css );
		}
		// Codex, round 19 (B-1): in a selector and in the name of a property a
		// backslash is free; \201C gives none of these, nor a variable that is
		// no id: free.
		foreach ( array( '.md\\:flex{display:flex} .w-1\\/2{width:50%}', '@media (min-width:768px){.md\\:flex{display:flex}}', '.a:hover .md\\:flex{display:flex}', '@import "a.css" supports(display:\\66 lex);', 'a::before{content:"\\2192 (mehr)"}', 'a::before{content:"\\2192 (mehr)"} .md\\:flex{display:flex}', 'a::before{content:"@\\69"} .md\\:flex{display:flex}', 'q::before{content:"\\201C"} a{background:url(/img/a.png)}', '.icon-pfeil::before{content:"\\f084"}', 'a{--u:"\\3f x=1"}', '.x::after{content:"\\2192"} .secret{color:red}' ) as $css ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			self::assertSame( array( array(), array() ), array( $acc['refs'], $acc['spelling'] ), $css );
			self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::text( '.x{--api\\-key:1}', $ctx, array(), $acc, 'css' );
		self::assertSame( array(), $acc['spelling'], 'The name of a property: free for the addresses.' );
		// A name of a credential an escape gives: a key (also where the word
		// stands elsewhere unescaped).
		foreach ( array( 'q::before{content:"\\61pi_key"}', '.x{--api\\-key:1}', '.x{content:"api_key"} .y{api\\5f k\\65y:1}' ) as $css ) {
			self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $css, array(), true )['state'], $css );
		}
	}

	public function testAnImportWithoutUrlAndImageSetAreAddresses(): void {
		// Codex, round 17 (A7): @import "…" is an address as url(…) is.
		$ctx  = array( 'css' => true ) + self::reads_ctx();
		$plan = self::reads_plan();
		foreach ( array(
			'@import "/?p=12";'                                 => array( '@import "/?p=120";', array( 12 ) ),
			"@import '/?page_id=13' screen;"                    => array( "@import '/?page_id=130' screen;", array( 13 ) ),
			'@import/**/"/?p=12";'                              => array( '@import/**/"/?p=120";', array( 12 ) ),
			'@import url(/?p=12);'                              => array( '@import url(/?p=120);', array( 12 ) ),
			'a{background-image:image-set("/?p=12" 1x, url(/?page_id=13) 2x)}' => array( 'a{background-image:image-set("/?p=120" 1x, url(/?page_id=130) 2x)}', array( 12, 13 ) ),
			'a{background-image:image-set(url("/?p=12") 1x)}'   => array( 'a{background-image:image-set(url("/?p=120") 1x)}', array( 12 ) ),
		) as $css => $want ) {
			list( $to, $ids ) = $want;
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			self::assertSame( array( $ids, array() ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ), $css );
			$changed = false;
			self::assertSame( $to, AB_MCP_Sync_Refs::rewrite_text( $css, $plan, array(), $changed, false, true ), $css );
		}
		foreach ( array( '@import "?p=12";' => 'css?p', '@import "../12/";' => 'css%post_id%' ) as $css => $at ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			self::assertSame( array( $at ), $acc['spelling'], $css );
		}
	}

	public function testDataAsAnAddressOfCssIsReadAsAnImage(): void {
		// Codex, round 17 (A4), 18 (B3), 19 (A-2, B-4): data: in url(…),
		// @import or image-set(…): an image decoded; an SVG read as content
		// of HTML (by the HTML API: locked without it); any other type, or one
		// that does not decode, locks.
		$ctx  = array( 'css' => true ) + self::reads_ctx();
		$read = static function ( string $css ) use ( $ctx ): array {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			$check = AB_MCP_Sync_Credentials::unit( '', $css, array(), true );
			return array( $check['state'], $check['why'] ?? null, $acc['spelling'] );
		};
		foreach ( array(
			'@import url("data:text/css;base64,OnJvb3R7LS1hcGkta2V5OmRlbW8tc2VjcmV0fQ==");',
			'@import "data:text/css,:root{--x:1}";',
			'a{background:url(data:text/html,x)}',
			'a{background:url(data:,x)}',
			'a{background:url( DATA:image/png;base64,!!!! )}',
			'a{background-image:image-set("data:image/svg+xml;base64,%%" 1x)}',
		) as $css ) {
			self::assertSame( array( 'unreadable', 'unreadable_value', array( 'css:css' ) ), $read( $css ), $css );
		}
		// The mask of the image block of the core (blocks/image/style.css:177),
		// percent-encoded, base64; raster images: free.
		$svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>';
		foreach ( array(
			"img{mask-image:url('data:image/svg+xml;utf8," . $svg . "')}",
			'img{mask-image:url("data:image/svg+xml,' . rawurlencode( $svg ) . '")}',
			'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( $svg ) . ')}',
			'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><text x="1" y="9">Token: x</text></svg>' ) . ')}',
		) as $css ) {
			self::assertSame( array( self::core() ? 'clean' : 'unreadable', self::core() ? null : 'unreadable_value', array() ), $read( $css ), $css );
		}
		// A script of an image runs not: free (without the HTML API: locked).
		$script = 'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>var x = 1;</script><circle r="1" onclick="go()"/></svg>' ) . ')}';
		self::assertSame( self::core() ? array( 'clean', null, array() ) : array( 'unreadable', 'unreadable_value', array( 'css:css' ) ), $read( $script ) );
		foreach ( array(
			'a{background:url( DATA:image/png;base64,iVBORw0KGgo= )}',
			'a{background:url(data:Image/PNG;base64,iVBORw0KGgo=)}',
			'a{background:url("data:image/png;base64,iVBO Rw0K Ggo=")}',
			'a{background-image:image-set("data:image/webp;base64,UklGRg==" 1x, "data:image/avif;base64,AAAA" 2x)}',
			'a{background:url(data:image/gif;base64,R0lGODlh) , url(data:image/jpeg;base64,/9j/4A==)}',
			'a{background:url(data:image/png;base64,' . base64_encode( "\x89PNG <a href=\"/?p=12\">" ) . ')}',
		) as $css ) {
			self::assertSame( array( 'clean', null, array() ), $read( $css ), $css );
		}
		self::assertSame( 'mixed', $read( 'a{background:url(data:image/png;base64,' . base64_encode( "\x89PNG tEXt api_key=demo-secret" ) . ')}' )[0], 'The text of a raster image.' );
		// An SVG with an address of the sites: locked.
		foreach ( array(
			'<svg xmlns="http://www.w3.org/2000/svg"><a href="/?p=12"><circle r="1"/></a></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><image href="https://staging.test/a.png"/></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><style>a{background:url(/?p=12)}</style></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><a href="?p=12"><circle r="1"/></a></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><a href=/?p=12><circle r="1"/></a></svg>',
		) as $bad ) {
			self::assertContains( 'css:css', $read( "a{mask:url('data:image/svg+xml;base64," . base64_encode( $bad ) . "')}" )[2], $bad );
			self::assertContains( 'css:css', $read( 'a{mask:url("data:image/svg+xml,' . rawurlencode( $bad ) . '")}' )[2], $bad );
		}
		// An SVG with a credential, its fields, its JSON, its CSS: mixed.
		foreach ( array(
			'<svg xmlns="http://www.w3.org/2000/svg" api_key="demo-secret"/>',
			'<svg xmlns="http://www.w3.org/2000/svg"><style>:root{--client-secret:1}</style></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><metadata><script type="application/json">[{"name":"api_key","value":"demo-secret"}]</script></metadata><circle r="10"/></svg>',
		) as $bad ) {
			foreach ( array( 'a{mask:url("data:image/svg+xml,' . rawurlencode( $bad ) . '")}', 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( $bad ) . ')}' ) as $css ) {
				self::assertSame( self::core() ? 'mixed' : 'unreadable', $read( $css )[0], $bad );
			}
		}
	}

	public function testAStringOfCssWithAnAddressLocks(): void {
		// Codex, round 19 (A-3): every string (a custom property reaches
		// image-set(var(…)) as an address) read; an address with an id there
		// locks, rewritten only in url(), image-set(), @import.
		$ctx  = array( 'css' => true ) + self::reads_ctx();
		$read = static function ( string $css ) use ( $ctx ): array {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Canon::text( $css, $ctx, array(), $acc, 'css' );
			return array( array_column( $acc['refs'], 'id' ), $acc['spelling'] );
		};
		foreach ( array(
			'.x{--bild:"/?attachment_id=12";background-image:image-set(var(--bild) 1x)}',
			".x{--bild:'https://staging.test/?p=12'}",
			'.x{content:"Mehr: https://staging.test/?page_id=13"}',
			'.x{--bild:"../12/"}',
		) as $css ) {
			self::assertContains( 'css:css', $read( $css )[1], $css );
		}
		foreach ( array( 'a{content:"/"} b{font-family:"Open Sans"} c{content:"12"} d{--bild:"https://staging.test/a.png"}', 'a::before{content:"\\201C"}' ) as $css ) {
			self::assertSame( array( array(), array() ), $read( $css ), $css );
		}
	}

	public function testGlobalStylesAreReadAsCss(): void {
		// Codex, round 17 (A2), 19 (1): the value of every key css of JSON is
		// CSS; stored JSON by its round trip, as its producer writes it.
		$styles = static fn( array $styles, int $flags = 0 ): string => (string) json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => $styles ), $flags );
		$core   = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
		foreach ( array(
			array( $styles( array( 'css' => '.x{background:url(/?p=12)}' ) ), 'post:12@c:html:json.styles.css?p' ),
			array( $styles( array( 'css' => '.x::before{content:"é"} .x{background:url(/?p=12)}' ), $core ), 'post:12@c:html:json.styles.css?p' ),
			array( $styles( array( 'css' => ".x::before{content:'a'} .x{background:url(/?p=12)}" ) ), 'post:12@c:html:json.styles.css?p' ),
			array( $styles( array( 'blocks' => array( 'core/paragraph' => array( 'css' => 'a{background:url(/?page_id=12)}' ) ) ) ), 'post:12@c:html:json.styles.blocks.core/paragraph.css?page_id' ),
		) as $case ) {
			list( $json, $ref ) = $case;
			list( $refs, $acc ) = self::reads_scan( $json );
			self::assertSame( array( array( $ref ), array() ), array( $refs, $acc['spelling'] ), $json );
			$out = AB_MCP_Sync_Refs::rewrite_content( $json, self::reads_plan() );
			self::assertSame( array( str_replace( array( '=12)', '=12}' ), array( '=120)', '=120}' ), $json ), array() ), array( $out['content'], $out['missing'] ), $json );
		}
		// Not as a producer writes it (spaces, a key twice, an escaped key): an
		// id or an address in it locks.
		foreach ( array(
			'{ "styles": { "css": ".x{background:url(/?p=12)}" } }',
			'{"styles":{"css":"a{}","css":".x{background:url(/?p=12)}"}}',
			'{"styles":{"\u0063ss":".x{background:url(https://staging.test/?p=12)}"}}',
		) as $json ) {
			list( $refs, $acc ) = self::reads_scan( $json );
			self::assertSame( array( array(), array( 'c:html:json' ) ), array( $refs, $acc['spelling'] ), $json );
			$out = AB_MCP_Sync_Refs::rewrite_content( $json, self::reads_plan() );
			self::assertSame( array( $json, array( 'spelling' ) ), array( $out['content'], array_column( $out['missing'], 'space' ) ), $json );
		}
		list( $refs, $acc ) = self::reads_scan( '{ "styles": { "color": { "text": "red" } } }' );
		self::assertSame( array( array(), array() ), array( $refs, $acc['spelling'] ), 'Nothing to read in it: free.' );
		$plan = self::reads_plan();
		AB_MCP_Sync_Refs::rewrite_text( '{ "u": "?p=12" }', $plan, array(), $changed );
		self::assertSame( array( 'spelling' ), array_column( $plan['missing'], 'space' ), 'An id that locks in it.' );
		// The canonical form is the data, a key that names a site locks, deeper
		// than MAX_DEPTH locks; a text that is JSON of no object or list
		// (a number, a string) stays a text.
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( array( '2024', '"x"' ), array( AB_MCP_Sync_Canon::text( '2024', self::reads_ctx(), array(), $acc, 'c' ), AB_MCP_Sync_Canon::text( '"x"', self::reads_ctx(), array(), $acc, 'c' ) ) );
		self::assertNotSame( AB_MCP_Sync_Canon::text( '{"a":"x"}', self::reads_ctx(), array(), $acc, 'c' ), AB_MCP_Sync_Canon::text( '{"a":"y"}', self::reads_ctx(), array(), $acc, 'c' ) );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::text( '{"https://staging.test/a":1}', self::reads_ctx(), array(), $acc, 'c' );
		self::assertSame( array( 'c:json.https://staging.test/a' ), $acc['spelling'] );
		$plan = self::reads_plan();
		AB_MCP_Sync_Refs::rewrite_text( '{"https://staging.test/a":1}', $plan, array(), $changed );
		self::assertSame( array( 'spelling' ), array_column( $plan['missing'], 'space' ) );
		$deep = str_repeat( '[', AB_MCP_Sync_Canon::MAX_DEPTH + 2 ) . '"x"' . str_repeat( ']', AB_MCP_Sync_Canon::MAX_DEPTH + 2 );
		$acc  = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::text( $deep, self::reads_ctx(), array(), $acc, 'c' );
		self::assertNotSame( array(), $acc['spelling'] );
		// As serialize_block_attributes() writes JSON: a producer as well.
		$block = '{"url":"/?p=12","t":"a\\u002d\\u002db \\u003c \\u003e \\u0026 \\u005c \\u0022 é"}';
		$acc   = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( $block, self::reads_ctx(), $acc, 'meta', 'm' );
		self::assertSame( array( array( 12 ), array() ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ), $block );
		$plan = self::reads_plan();
		self::assertSame( str_replace( 'p=12', 'p=120', $block ), AB_MCP_Sync_Refs::rewrite_value( $block, $plan, $changed ) );
		list( , $acc ) = self::reads_scan( $styles( array( 'css' => 'a{background:u\\72l(https://staging.test/x.png)}' ) ) );
		self::assertSame( array( 'c:html:json.styles.css:css' ), $acc['spelling'] );
		// The same as the value of an option or a meta row, and as an attribute of a block.
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( serialize( array( 'styles' => array( 'css' => '.x{background:url(/?p=12)}' ) ) ), self::reads_ctx(), $acc, 'option', 'o' );
		self::assertSame( array( array( 12 ), array() ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ) );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( serialize( array( 'css' => array( 'print' => '.x{background:url(/?p=12)}' ) ) ), self::reads_ctx(), $acc, 'option', 'o' );
		self::assertSame( array( array( 12 ), array() ), array( array_column( $acc['refs'], 'id' ), $acc['spelling'] ), 'Every value under css.' );
		$block = '<!-- wp:acme/x {"css":"/* Druck */ a{background:url(/?p=12)}"} /-->';
		list( $refs, $acc ) = self::reads_scan( $block );
		self::assertSame( array( array( 'post:12@c:acme/x.css?p' ), array() ), array( $refs, $acc['spelling'] ), 'CSS, not a whole address.' );
		self::assertSame( str_replace( 'p=12', 'p=120', $block ), AB_MCP_Sync_Refs::rewrite_content( $block, self::reads_plan() )['content'] );
		list( $refs, $acc ) = self::reads_scan( '<!-- wp:acme/x {"style":{"css":".x{background:url(/?p=12)}"}} /-->' );
		self::assertSame( array( array( 'post:12@c:acme/x.style.css?p' ), array() ), array( $refs, $acc['spelling'] ) );
		self::assertSame( '<!-- wp:acme/x {"style":{"css":".x{background:url(/?p=120)}"}} /-->', AB_MCP_Sync_Refs::rewrite_content( '<!-- wp:acme/x {"style":{"css":".x{background:url(/?p=12)}"}} /-->', self::reads_plan() )['content'] );
		$plan    = self::reads_plan();
		$changed = false;
		self::assertSame( serialize( array( 'css' => '.x{background:url(/?p=120)}' ) ), AB_MCP_Sync_Refs::rewrite_value( serialize( array( 'css' => '.x{background:url(/?p=12)}' ) ), $plan, $changed ) );
		// Codex, round 18 (A5): the whole subtree under css, in JSON as serialized.
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( '{"css":{"desktop":"a{background:url(/?p=12)}"}}', self::reads_ctx(), $acc, 'meta', 'm' );
		self::assertSame( array( array( 'm:json.css.desktop?p' ), array() ), array( array_column( $acc['refs'], 'at' ), $acc['spelling'] ) );
		$plan    = self::reads_plan();
		$changed = false;
		self::assertSame( '{"css":{"desktop":"a{background:url(/?p=120)}"}}', AB_MCP_Sync_Refs::rewrite_value( '{"css":{"desktop":"a{background:url(/?p=12)}"}}', $plan, $changed ) );
		// An absolute address of an image: its host rewritten.
		$image = array(
			'background' => array( 'backgroundImage' => array( 'url' => 'https://staging.test/wp-content/uploads/bg.jpg' ) ),
			'css'        => '.x{background:url(https://staging.test/wp-content/uploads/a.png)}',
		);
		$free  = $styles( $image );
		foreach ( array( $free, $styles( $image, $core ) ) as $json ) {
			list( $refs, $acc ) = self::reads_scan( $json );
			self::assertSame( array( array(), array(), array() ), array( $refs, $acc['locks'], $acc['spelling'] ), $json );
			$out = AB_MCP_Sync_Refs::rewrite_content( $json, self::reads_plan() );
			self::assertSame( array( str_replace( 'staging.test', 'www.live.test', $json ), array() ), array( $out['content'], $out['missing'] ), $json );
		}
		// The credentials: blocks.*.css as CSS, its comments removed; every
		// value under css.
		self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $styles( array( 'blocks' => array( 'core/paragraph' => array( 'css' => '.x{--api/**/-key:1}' ) ) ) ), array() )['state'] );
		self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', $styles( array( 'css' => array( 'print' => '.x{--api/**/-key:1}' ) ) ), array() )['state'] );
		self::assertSame( 'mixed', AB_MCP_Sync_Credentials::unit( '', '{"css":{"desktop":{"print":".x{--api/**/-key:1}"}}}', array() )['state'] );
		// JSON deeper than json_decode() reads: locked.
		list( , $acc ) = self::reads_scan( str_repeat( '[', 513 ) . '"x"' . str_repeat( ']', 513 ) );
		self::assertSame( array( 'c:html:json' ), $acc['spelling'] );
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'] );
	}

	public function testTheBackgroundImageOfGlobalStylesIsAReference(): void {
		// Codex, round 18 (A2), 19 (B-2): …background.backgroundImage.id at any
		// depth, as in the attributes of a block: a local attachment whatever
		// host its url names; without a url it locks.
		$styles = static fn( array $styles, int $flags = 0 ): string => (string) json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => $styles ), $flags );
		$image  = static fn( $id, $url = 'https://staging.test/wp-content/uploads/bg.jpg' ): array => array( 'background' => array( 'backgroundImage' => array_filter( array( 'url' => $url, 'id' => $id, 'source' => 'file' ), static fn( $v ) => null !== $v ) ) );
		$live   = static fn( string $json ): string => str_replace( array( 'staging.test', '"id":12,', '"id":12}', '"id": 12,' ), array( 'www.live.test', '"id":120,', '"id":120}', '"id": 120,' ), $json );
		foreach ( array(
			array( $styles( $image( 12 ) ), 'styles.background.backgroundImage.id' ),
			array( $styles( array( 'blocks' => array( 'core/group' => $image( 12 ) ) ), JSON_PRETTY_PRINT ), null ),
			array( $styles( array( 'elements' => array( 'link' => $image( 12 ) ), 'blocks' => array( 'core/cover' => array( 'id' => 'x' ) ) ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ), 'styles.elements.link.background.backgroundImage.id' ),
			array( $styles( $image( 12, 'https://cdn.test/uploads/bg.jpg' ) ), 'styles.background.backgroundImage.id' ),
			array( $styles( $image( 12, '/wp-content/uploads/bg.jpg' ) ), 'styles.background.backgroundImage.id' ),
		) as $case ) {
			list( $json, $path ) = $case;
			list( $refs, $acc ) = self::reads_scan( $json );
			if ( null === $path ) {
				// JSON_PRETTY_PRINT is no producer's spelling: locked.
				self::assertSame( array( array(), array( 'c:html:json' ) ), array( $refs, $acc['spelling'] ), $json );
				continue;
			}
			self::assertSame( array( array( 'post:12@c:html:json.' . $path ), array() ), array( $refs, $acc['spelling'] ), $json );
			$out = AB_MCP_Sync_Refs::rewrite_content( $json, self::reads_plan() );
			self::assertSame( array( $live( $json ), array() ), array( $out['content'], $out['missing'] ), $json );
		}
		// Without a url, not a whole number: locked there; not as a producer
		// writes it (escaped, twice, 1.2e1: Codex, round 19, A-1): locked whole.
		foreach ( array(
			array( $styles( $image( 12, null ) ), 'c:html:json.styles.background.backgroundImage.id' ),
			array( $styles( $image( array( 'x' => 12 ) ) ), 'c:html:json.styles.background.backgroundImage.id' ),
			array( $styles( $image( 12.5 ) ), 'c:html:json.styles.background.backgroundImage.id' ),
			array( $styles( $image( 12, 5 ) ), 'c:html:json.styles.background.backgroundImage.id' ),
			array( str_replace( '"id":12', '"\\u0069d":12', $styles( $image( 12 ) ) ), 'c:html:json' ),
			array( str_replace( '"id":12', '"id":12,"id":12', $styles( $image( 12 ) ) ), 'c:html:json' ),
			array( str_replace( '"id":12', '"id":1.2e1', $styles( $image( 12 ) ) ), 'c:html:json' ),
			array( '{"foo":{"id":12,"id":12},"styles":{"background":{"backgroundImage":{"\\u0069d":12,"url":"https://staging.test/a.jpg"}}},"version":3,"isGlobalStylesUserThemeJSON":true}', 'c:html:json' ),
		) as $case ) {
			list( $json, $at ) = $case;
			list( $refs, $acc ) = self::reads_scan( $json );
			self::assertSame( array( array(), array( $at ) ), array( $refs, $acc['spelling'] ), $json );
			$out = AB_MCP_Sync_Refs::rewrite_content( $json, self::reads_plan() );
			self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), $json );
			self::assertStringNotContainsString( '120', $out['content'], $json );
		}
		// In a block: the same rule.
		list( $refs, $acc ) = self::reads_scan( '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://cdn.test/bg.jpg","id":12}}}} /-->' );
		self::assertSame( array( array( 'post:12@c:core/group.style.background.backgroundImage.id' ), array() ), array( $refs, $acc['spelling'] ) );
		self::assertSame( '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://cdn.test/bg.jpg","id":120}}}} /-->', AB_MCP_Sync_Refs::rewrite_content( '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://cdn.test/bg.jpg","id":12}}}} /-->', self::reads_plan() )['content'] );
		foreach ( array( '{"id":12}', '{"url":5,"id":12}' ) as $bg ) {
			list( $refs, $acc ) = self::reads_scan( '<!-- wp:group {"style":{"background":{"backgroundImage":' . $bg . '}}} /-->' );
			self::assertSame( array( array(), array( 'c:core/group.style.background.backgroundImage.id' ) ), array( $refs, $acc['spelling'] ), $bg );
		}
		self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( '<!-- wp:group {"style":{"background":{"backgroundImage":{"id":12}}}} /-->', self::reads_plan() )['missing'], 'space' ) );
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
			// Codex, round 11 (3): the content of script and textarea is text.
			'in a script'          => array( '<script type="application/ld+json">{"position":"2","url":"https://staging.test/?p=12"}</script>', array( 'post:12@c:html:json.url?p' ), '<script type="application/ld+json">{"position":"2","url":"https://www.live.test/?p=120"}</script>' ),
			'in a textarea'        => array( '<textarea>https://staging.test/?p=12</textarea>', array( 'post:12@c:html?p' ), '<textarea>https://www.live.test/?p=120</textarea>' ),
			// Codex, round 12 (4): url(…) and a template string.
			'in a style'           => array( '<style>body{background:url(https://staging.test/?p=12)}</style>', array( 'post:12@c:html?p' ), '<style>body{background:url(https://www.live.test/?p=120)}</style>' ),
			'in a template string' => array( '<script>const u=`https://staging.test/?p=12`;</script>', array( 'post:12@c:html?p' ), '<script>const u=`https://www.live.test/?p=120`;</script>' ),
			// Codex, round 16 (C1): url(…) without a host as any address.
			'a relative address in a style attribute' => array( "<p style=\"background-image:url('/?p=12')\">x</p>", array( 'post:12@c:html?p' ), "<p style=\"background-image:url('/?p=120')\">x</p>" ),
			'a relative address in style' => array( '<style>body{background:url(/?page_id=13)}</style>', array( 'post:13@c:html?page_id' ), '<style>body{background:url(/?page_id=130)}</style>' ),
			// Codex, round 15: the addresses of a style attribute one by one.
			'in a style attribute' => array( '<p style="background-image:url(https://staging.test/?p=12)">x</p>', array( 'post:12@c:html?p' ), '<p style="background-image:url(https://www.live.test/?p=120)">x</p>' ),
			'two in a style attribute' => array( "<div style=\"background:url('https://staging.test/?p=12'),url(https://staging.test/?page_id=13)\">x</div>", array( 'post:12@c:html?p', 'post:13@c:html?page_id' ), "<div style=\"background:url('https://www.live.test/?p=120'),url(https://www.live.test/?page_id=130)\">x</div>" ),
			// Codex, round 13 (B1): single quotes as the core writes them.
			'in single quotes'     => array( "<p><a href='https://staging.test/?p=12'>Beitrag</a></p>", array( 'post:12@c:html?p' ), "<p><a href='https://www.live.test/?p=120'>Beitrag</a></p>" ),
			'in single quotes with an ampersand' => array( "<p><a href='https://staging.test/?a=1&amp;p=12'>x</a></p>", array( 'post:12@c:html?p' ), "<p><a href='https://www.live.test/?a=1&amp;p=120'>x</a></p>" ),
			'escaped JSON in a script' => array( '<script>var u = "https:\\/\\/staging.test\\/?p=12";</script>', array( 'post:12@c:html?p' ), '<script>var u = "https:\\/\\/www.live.test\\/?p=120";</script>' ),
			// CI, round 20: \u0026 of JSON in JavaScript separates as & does.
			'in a script with an ampersand of JSON' => array( '<script>var u = "https:\\/\\/staging.test\\/?preview=1\\u0026p=12";</script>', array( 'post:12@c:html?p' ), '<script>var u = "https:\\/\\/www.live.test\\/?preview=1\\u0026p=120";</script>' ),
			'after an ampersand'   => array( '<p><a href="https://staging.test/?preview=1&amp;p=12">x</a></p>', array( 'post:12@c:html?p' ), '<p><a href="https://www.live.test/?preview=1&amp;p=120">x</a></p>' ),
			'after an ampersand in prose' => array( 'Siehe https://staging.test/?preview=1&p=12 hier', array( 'post:12@c:html?p' ), 'Siehe https://www.live.test/?preview=1&p=120 hier' ),
			'in another attribute' => array( '<p><span data-target="https://staging.test/?p=12">x</span></p>', array( 'post:12@c:html?p' ), '<p><span data-target="https://www.live.test/?p=120">x</span></p>' ),
			// Codex, round 17 (A6): JSON in an attribute, value by value.
			'in the context of a block' => array( '<div data-wp-interactive="acme" data-wp-context=\'{"url":"/?p=12"}\'><a data-wp-bind--href="context.url">Öffnen</a></div>', array( 'post:12@c:html:json.url?p' ), '<div data-wp-interactive="acme" data-wp-context=\'{"url":"/?p=120"}\'><a data-wp-bind--href="context.url">Öffnen</a></div>' ),
			// Codex, round 18 (A3, B2): with its namespace; each / as \/ as the
			// core writes it (wp_interactivity_data_wp_context()).
			'in the context with a namespace' => array( '<div data-wp-context=\'acme/store::{"url":"/?p=12"}\'>x</div>', array( 'post:12@c:html:json.url?p' ), '<div data-wp-context=\'acme/store::{"url":"/?p=120"}\'>x</div>' ),
			'in the context as the core writes it' => array( '<div data-wp-context=\'acme/store::{"url":"\\/?p=12"}\'>x</div>', array( 'post:12@c:html:json.url?p' ), '<div data-wp-context=\'acme/store::{"url":"\\/?p=120"}\'>x</div>' ),
			'absolute in the context as the core writes it' => array( '<div data-wp-context=\'{"url":"https:\\/\\/staging.test\\/archiv\\/?p=12"}\'>x</div>', array( 'post:12@c:html:json.url?p' ), '<div data-wp-context=\'{"url":"https:\\/\\/www.live.test\\/archiv\\/?p=120"}\'>x</div>' ),
			// Codex, round 19 (1): JSON by its round trip, as its producer
			// writes it (\u0026 of wp_interactivity_data_wp_context() too).
			'with an ampersand as the core writes it' => array( '<div data-wp-context=\'{"url":"\\/?a=1\\u0026p=12"}\'>x</div>', array( 'post:12@c:html:json.url?p' ), '<div data-wp-context=\'{"url":"\\/?a=1\\u0026p=120"}\'>x</div>' ),
			'the same address twice in the context' => array( '<div data-wp-context=\'{"a":"/?p=12","b":"/?p=12"}\'>x</div>', array( 'post:12@c:html:json.a?p', 'post:12@c:html:json.b?p' ), '<div data-wp-context=\'{"a":"/?p=120","b":"/?p=120"}\'>x</div>' ),
			'a site in a text of the context' => array( '<div data-wp-context=\'{"html":"Mehr: https://staging.test/?p=12"}\'>x</div>', array( 'post:12@c:html:json.html?p' ), '<div data-wp-context=\'{"html":"Mehr: https://www.live.test/?p=120"}\'>x</div>' ),
			'a relative address in JSON-LD' => array( '<script type="application/ld+json">{"@type":"Article","url":"/?p=12"}</script>', array( 'post:12@c:html:json.url?p' ), '<script type="application/ld+json">{"@type":"Article","url":"/?p=120"}</script>' ),
			'deeper in the context of a block' => array( '<div data-wp-context=\'{"items":[{"link":"https://staging.test/?page_id=13","n":2}]}\'>x</div>', array( 'post:13@c:html:json.items.0.link?page_id' ), '<div data-wp-context=\'{"items":[{"link":"https://www.live.test/?page_id=130","n":2}]}\'>x</div>' ),
			// Codex, round 17 (A7): @import "…" and image-set(…) as url(…).
			'an import'            => array( '<style>@import "/?p=12";</style>', array( 'post:12@c:html?p' ), '<style>@import "/?p=120";</style>' ),
			'an import in single quotes' => array( "<style>@import '/?page_id=13' screen;</style>", array( 'post:13@c:html?page_id' ), "<style>@import '/?page_id=130' screen;</style>" ),
			'an image set'         => array( '<style>.x{background-image:image-set("/?p=12" 1x)}</style>', array( 'post:12@c:html?p' ), '<style>.x{background-image:image-set("/?p=120" 1x)}</style>' ),
			// Codex, round 17 (A3): after a comment, where it stands.
			'after a comment in style' => array( '<style>/* Kopf */ body{background:url(/?p=12)}</style>', array( 'post:12@c:html?p' ), '<style>/* Kopf */ body{background:url(/?p=120)}</style>' ),
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
			'in a sentence'                => array( 'Mehr unter https://staging.test/?p=12.', 'Mehr unter https://www.live.test/?p=120.' ),
			// Codex, round 14 (A3): the background image with both fields.
			'a group with a background image' => array( '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://staging.test/wp-content/uploads/bg.jpg","id":12,"source":"file"}}}} /-->', '<!-- wp:group {"style":{"background":{"backgroundImage":{"url":"https://www.live.test/wp-content/uploads/bg.jpg","id":120,"source":"file"}}}} /-->' ),
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
			'a list of a list in prose' => array( 'Siehe https://staging.test/?cat[]=5 hier', 'c:html?cat' ),
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
			'the spelling in another attribute' => array( "<p><a title=' href=\"/?p=12\"' href=/?p=12>x</a></p>", 'c:html?p' ),
			'the name twice'         => array( '<p><a href="https://staging.test/?p=12&amp;p=13">x</a></p>', 'c:html?p' ),
			'a reference without ";" in a text' => array( '<p>https://staging.test/?x=1&ampp=12</p>', 'c:html?p' ),
			'a reference in a text'  => array( '<p>https://staging.test/?p=1&#50;</p>', 'c:html?p' ),
			// The attributes of a shortcode as the core reads them.
			'inside [[…]]'           => array( '[[outer] [acme_button url="/?p=12"] [/outer]]', 'c:html?p' ),
			'the same attribute twice' => array( '[acme_button url="/?p=12" url="/?p=12"]', 'c:html?p' ),
			'an escape in a shortcode' => array( '[acme_button url="/\\?p=12"]', 'c:html?p' ),
			// Codex, round 11 (3): a textarea decodes its references; read so, locked.
			'in a textarea with a reference' => array( '<textarea>https://staging.test/?a=1&amp;p=12</textarea>', 'c:html?p' ),
			// Codex, round 13: JavaScript in an attribute with an address of the sites.
			'digits relative to the page in CSS' => array( '<p style="background:url(../12/)">x</p>', 'c:html%post_id%' ),
			'an address hidden by an escape of CSS' => array( '<p style="background:url(https://staging\\2e test/?p=12)">x</p>', 'c:html:css' ),
			'a style attribute read decoded' => array( '<p style="background:url(https://staging.test/?p=1&#50;)">x</p>', 'c:html?p' ),
			'a JSON-LD in no spelling of a producer' => array( '<script type="application/ld+json">{ "@type": "Article", "url": "/?p=12" }</script>', 'c:html:json' ),
			'in a title'             => array( '<title>https://staging.test/?a=&#49;&amp;p=12</title>', 'c:html?p' ),
			// Codex, round 11 (4): relative to the page, a segment of digits.
			'digits relative to the page' => array( '<p><a href="../12/">Beitrag</a></p>', 'c:html%post_id%' ),
			'digits relative in a block attribute' => array( '<!-- wp:acme/x {"url":"../12/"} /-->', 'c:acme/x.url%post_id%' ),
			// Codex, round 12 (3): an encoded slash makes the segments.
			'an encoded slash relative to the page' => array( '<p><a href="archives%2F12/">x</a></p>', 'c:html%post_id%' ),
			'an encoded dot relative in a block attribute' => array( '<!-- wp:acme/x {"url":"%2e%2e/12/"} /-->', 'c:acme/x.url%post_id%' ),
			'escaped digits relative to the page' => array( '<p><a href="archives/%31%32">x</a></p>', 'c:html%post_id%' ),
			// Codex, round 11 (6): as the URL parser reads it (tab, line break, spaces).
			'a tab in the name'      => array( '<p><a href="/?pa&#9;ge_id=12">x</a></p>', 'c:html?page_id' ),
			'a line break in the name' => array( '<p><a href="/?p&#10;=12">x</a></p>', 'c:html?p' ),
			'spaces before'          => array( '<p><a href=" /?p=12">x</a></p>', 'c:html?p' ),
			'spaces in a block attribute' => array( '<!-- wp:acme/x {"url":" /?p=12 "} /-->', 'c:acme/x.url?p' ),
			'a tab in a shortcode attribute' => array( "[acme_button url=\"/?p=1\t2\"]", 'c:html?p' ),
			'a tab in a name in a shortcode attribute' => array( "[acme_button url=\"/?pa\tge_id=12\"]", 'c:html?page_id' ),
			'a space after in a block attribute' => array( '<!-- wp:acme/x {"url":"/?p=12 "} /-->', 'c:acme/x.url?p' ),
			// Codex, round 17 (A1): escapes and a backslash in CSS.
			'url escaped in a style attribute' => array( '<p style="background:u\\72l(/?p=12)">x</p>', 'c:html:css' ),
			'a style attribute with references for quotes' => array( '<p style="content:&quot;/*&quot;; background:url(/?p=12); content:&quot;*/&quot;">x</p>', 'c:html?p' ),
			'digits relative by its value in a block attribute' => array( '<!-- wp:acme/x {"target":"../12/"} /-->', 'c:acme/x.target%post_id%' ),
			'a backslash in url(…)' => array( '<style>.x{background:url(/?p=1\\32)}</style>', 'c:html:css' ),
			'a backslash in an import' => array( '<style>@import "/?p=1\\32";</style>', 'c:html:css' ),
			// Codex, round 17 (A3): a comment inside an address.
			'a comment in url(…)'  => array( '<style>.x{background:url(/?p=1/**/2)}</style>', 'c:html:css' ),
			'a comment in a host'  => array( '<style>.x{--u:https://staging.te/**/st/?p=12}</style>', 'c:html:css' ),
			// Codex, round 17 (A4): data: in CSS.
			'data in an import'    => array( '<style>@import url("data:text/css;base64,OnJvb3R7LS1hcGkta2V5OmRlbW8tc2VjcmV0fQ==");</style>', 'c:html:css' ),
			// Codex, round 17 (A6): in JSON of an attribute, not stored as JSON writes it.
			'a slash escaped once in the context' => array( '<div data-wp-context=\'{"url":"\\/blog/?p=12"}\'>x</div>', 'c:html:json' ),
			'the context as references' => array( '<div data-wp-context="{&quot;url&quot;:&quot;/?p=12&quot;}">x</div>', 'c:html:json' ),
			'the same address in both spellings in the context' => array( '<div data-wp-context=\'{"a":"/?p=12","b":"\\/?p=12"}\'>x</div>', 'c:html:json' ),
			'a key twice in the context' => array( '<div data-wp-context=\'{"url":"/?p=12","url":"/?p=13"}\'>x</div>', 'c:html:json' ),
			'a context that is no JSON' => array( '<div data-wp-context=\'{"url":"/?p=12"\'>x</div>', 'c:html:json' ),
			'a context with a namespace that is no JSON' => array( '<div data-wp-context=\'acme::{"url":"/?p=12"\'>x</div>', 'c:html:json' ),
			// Codex, round 18 (B3): an SVG with an address in data: of CSS.
			'an SVG with an address' => array( '<p style="mask-image:url(\'data:image/svg+xml;utf8,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot;><a href=&quot;/?p=12&quot;/></svg>\')">x</p>', 'c:html:css' ),
			// Codex, round 17 (6): a script of JSON that is no JSON.
			'JSON-LD that is no JSON' => array( '<script type="application/ld+json">{"url": /?p=12}</script>', 'c:html:json' ),
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
			// JavaScript is no address: the unit locks for it (*:script).
			'an event attribute'     => array( "<p><button onclick=\"location.href='https://staging.test/?p=12'\">x</button></p>" ),
			'a fragment in a block attribute' => array( '<!-- wp:acme/x {"url":"#?p=12"} /-->' ),
			'an escaped shortcode'   => array( '[[acme_button url="/?p=12"]]' ),
			// Codex, round 11 (7): 0 and empty name no object.
			'all categories'         => array( '<p><a href="/?cat=0">Alle Kategorien</a></p>' ),
			'no author, no post'     => array( 'Siehe https://staging.test/?author= und https://staging.test/?p=0 und https://staging.test/?cat=0,-0' ),
			'no page in a block attribute' => array( '<!-- wp:acme/x {"url":"/?page_id=0"} /-->' ),
			// Codex, round 12 (8): also relative and normalized.
			'all categories relative to the page' => array( '<p><a href="?cat=0">Alle</a> <a href="?author=">Alle</a></p>' ),
			'no post with spaces'    => array( '<p><a href=" /?p=0 ">x</a></p>' ),
			'no author in a block attribute relative' => array( '<!-- wp:acme/x {"url":"?author="} /-->' ),
			'a zero segment relative' => array( '<!-- wp:acme/x {"url":"../0/"} /-->' ),
			// Codex, round 10 (9): a file relative to the page names no object.
			'a file relative to the page'    => array( '<p><img src="photo2.jpg"></p>' ),
			'an upload relative to the page' => array( '<p><img src="wp-content/uploads/2026/11/photo.jpg"></p>' ),
			// Codex, round 17 (A6): the context of a block without an address.
			'a title with a bracket' => array( '<p title="[Hinweis] /?p=12">x</p>' ),
			'a context as references without an address' => array( '<div data-wp-context="{&quot;open&quot;:false}">x</div>' ),
			'a namespace on another data attribute' => array( '<div data-x=\'acme::{"url":"/?p=12"}\'>x</div>' ),
			'a context without an address' => array( '<div data-wp-interactive="acme" data-wp-context=\'{"open":false,"label":"Mehr","count":2,"step":"12"}\'><button data-wp-on--click="actions.toggle">Mehr</button></div>' ),
			// Codex, round 17 (A3): comments, an escape that hides nothing.
			'CSS with comments and an escape' => array( '<style>/* Zitat */ q::before{content:"\\201C"} /* Bild */ a{background:url(https://staging.test/a.png)}</style>' ),
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

	public function testAnEscapeInAnAddressOfCssLocks(): void {
		// Codex, round 17 (A1): CSS reads p=12, the address up to the
		// backslash p=1; never rewritten so.
		$content = '<style>.x{background:url(https://staging.test/?p=1\\32)}</style>';
		if ( self::closed( $content ) ) {
			self::assertLockedWithoutTheCore( $content );
			return;
		}
		list( , $acc ) = self::reads_scan( $content );
		self::assertContains( 'c:html:css', $acc['spelling'] );
		self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() )['missing'], 'space' ) );
	}

	public static function unreadableUnits(): array {
		return array(
			// Codex, round 17 (A4): data: as an address of CSS.
			'data in an import in style' => array( '<style>@import url("data:text/css;base64,OnJvb3R7LS1hcGkta2V5OmRlbW8tc2VjcmV0fQ==");</style>' ),
			'data in a style attribute' => array( '<p style="background:url(DATA:text/css,x)">x</p>' ),
			// Codex, round 17 (A5): JSON json_decode() does not read.
			'JSON deeper than it is read' => array( "<div data-config='" . str_repeat( '[', 513 ) . '{"name":"api_key","value":"demo-secret"}' . str_repeat( ']', 513 ) . "'></div>" ),
			'a context not finished' => array( "<div data-wp-context='{\"name\":\"api_key\",\"value\":\"x\"'></div>" ),
			'a title deeper than it is read' => array( '<p title="' . str_repeat( '[', 513 ) . str_repeat( ']', 513 ) . '">x</p>' ),
			// Codex, round 18 (B3): data: that is no image, or does not decode.
			'data of another type' => array( '<p style="background:url(data:text/html,x)">x</p>' ),
			'data that does not decode' => array( '<p style="background:url(data:image/png;base64,!!)">x</p>' ),
		);
	}

	#[DataProvider( 'unreadableUnits' )]
	public function testWhatIsNotReadToItsEndIsUnreadable( string $text ): void {
		$check = AB_MCP_Sync_Credentials::unit( '', $text, array() );
		self::assertSame( array( 'unreadable', 'unreadable_value' ), array( $check['state'], $check['why'] ?? null ), $text );
	}

	public function testIndentedJsonTakesOverItsHostOnly(): void {
		// Codex, round 20 (B-1): JSON written by hand (indented, no producer's
		// spelling): a host stored literally in a string value is replaced,
		// every other byte stays; an id, an escaped host, a host in a key lock.
		$ld   = static fn( string $url, string $key = 'url' ): string => "<script type=\"application/ld+json\">\n{\n  \"@type\": \"Organization\",\n  \"" . $key . "\": \"" . $url . "\"\n}\n</script>";
		// In a meta value: the same.
		$meta = "{\n  \"logo\": \"https://staging.test/logo.png\"\n}";
		$acc  = AB_MCP_Sync_Canon::acc();
		$form = AB_MCP_Sync_Canon::value( $meta, self::reads_ctx(), $acc, 'meta', 'm' );
		self::assertSame( array(), $acc['spelling'] );
		self::assertSame( array( '~s' => array( array( '~s' => array( "{\n  \"logo\": \"", AB_MCP_Sync_Canon::address_cuts( 'https://staging.test', self::reads_ctx() )[0]['marker'], "/logo.png\"\n}" ) ) ) ), $form, 'The host neutral, the rest as it is.' );
		$plan    = self::reads_plan();
		$changed = false;
		self::assertSame( str_replace( 'staging.test', 'www.live.test', $meta ), AB_MCP_Sync_Refs::rewrite_value( $meta, $plan, $changed ) );
		$free = $ld( 'https://staging.test/ueber-uns/' );
		if ( self::closed( $free ) ) {
			self::assertLockedWithoutTheCore( $free );
			return;
		}
		foreach ( array( $free, $ld( 'Mehr unter https://staging.test/ueber-uns/ und https://staging.test/team/' ), $ld( 'https:\\/\\/staging.test\\/ueber-uns\\/' ), str_replace( '"@type": "Organization",', '"@type": "Organization",' . "\n  " . '"name": "Masse 18\\" \\\\ Zoll",', $free ) ) as $content ) {
			list( $refs, $acc ) = self::reads_scan( $content );
			self::assertSame( array( array(), array() ), array( $refs, $acc['spelling'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
			self::assertSame( array( str_replace( 'staging.test', 'www.live.test', $content ), array() ), array( $out['content'], $out['missing'] ), $content );
		}
		foreach ( array(
			$ld( 'https://staging.test/?p=12' ),
			$ld( 'https://staging\\u002etest/ueber-uns/' ),
			$ld( 'https://staging.test/ueber-uns/', 'https://staging.test/ueber-uns/' ),
			$ld( '/?p=12' ),
			$ld( '?p=12' ),
		) as $content ) {
			list( $refs, $acc ) = self::reads_scan( $content );
			self::assertSame( array( array(), array( 'c:html:json' ) ), array( $refs, $acc['spelling'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
			self::assertSame( array( $content, array( 'spelling' ) ), array( $out['content'], array_column( $out['missing'], 'space' ) ), $content );
		}
		// Not stored as it is read in an attribute: locked.
		list( , $acc ) = self::reads_scan( '<div data-wp-context="{ &quot;logo&quot;: &quot;https://staging.test/logo.png&quot; }">x</div>' );
		self::assertSame( array( 'c:html:json' ), $acc['spelling'] );
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
			self::assertSame( array( array(), array( 'meta:_json:json.url?' ) ), array( $acc['refs'], $acc['spelling'] ), 'Without WP_HTML_Decoder: locked.' );
			return;
		}
		self::assertSame( array( 'meta:_json:json.url?p' ), array_column( $acc['refs'], 'at' ) );
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
