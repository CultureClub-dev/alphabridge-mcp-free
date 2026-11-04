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
use AB_MCP_Sync_Net;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../includes/sync/class-sync-net.php';

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
			'a data: SVG in srcset'   => array( '<img src="a.jpg" srcset="data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . ' 1x" alt="">' ),
			'a source outside its list' => array( '<picture><source srcset="a.jpg" title="x"><img src="a.jpg" alt=""></picture>' ),
			'a picture outside its list' => array( '<picture title="x"><source srcset="a.jpg"><img src="a.jpg" alt=""></picture>' ),
			'a tag of a plugin'       => array( '<acme-widget data-x="1"></acme-widget>' ),
			'an input'                => array( '<input type="text" name="q">' ),
			// Codex, round 16 (A4): an embedded document, object with data.
			'a data: document in an iframe' => array( '<iframe src="data:text/html;base64,' . base64_encode( '<script>window.api_key="demo-secret"</script>' ) . '"></iframe>' ),
			'a data: address in capitals' => array( '<a href=" DATA:text/html,x">x</a>' ),
			'a data: image that is none' => array( '<img src="data:image/png;base64,AAAA" alt="">' ),
			// Codex, round 22 (B-3): a raster image only with the bytes of its type.
			'a data: PNG with the bytes of an SVG' => array( '<img src="data:image/png;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . '" alt="">' ),
			'a data: SVG in src'      => array( '<img src="data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . '" alt="">' ),
			'a data: GIF in href'     => array( '<a href="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">x</a>' ),
			'a data: WebP with the bytes of a PNG in poster' => array( '<video poster="data:image/webp;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" ) . '"></video>' ),
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
			// Codex, round 22 (B-3): the placeholder of a lazy loader, a raster image in data:.
			'a GIF placeholder' => array( '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="">' ),
			'a PNG placeholder beside data-src' => array( '<img src="data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" ) . '" data-src="https://staging.test/a.jpg" alt="">' ),
			'a WebP poster'   => array( '<video poster="data:image/webp;base64,' . base64_encode( "RIFF\0\0\0\0WEBPVP8 " ) . '"></video>' ),
			'a JPEG and an AVIF' => array( '<img src="data:image/jpeg;base64,' . base64_encode( "\xFF\xD8\xFF\xE0" ) . '" alt=""><img src="data:image/avif;base64,' . base64_encode( "\0\0\0\x1cftypavif" ) . '" alt="">' ),
			// Codex, round 23 (B-2): responsive images (srcset, sizes; source).
			'srcset and sizes'   => array( '<img src="a.jpg" srcset="a-300.jpg 300w, a.jpg 1024w" sizes="(max-width: 600px) 100vw, 50vw" alt="">' ),
			'a data: GIF in srcset' => array( '<img src="a.jpg" srcset="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7 1x, a.jpg 2x" alt="">' ),
			'a source with srcset' => array( '<picture><source srcset="a.webp 1x, a@2x.webp 2x" type="image/webp" media="(min-width: 600px)"><img src="a.jpg" alt=""></picture>' ),
			// Codex, round 24 (B-2): lazysizes; (B-1) a format in prose.
			'a lazy loader'       => array( '<picture class="lazy" data-sizes="auto"><source data-srcset="a.webp 1x, a2.webp 2x" type="image/webp" class="lazy"><img class="lazyload" data-src="a.jpg" data-srcset="a-300.jpg 300w" alt=""></picture>' ),
			'a lazy loader with a data: GIF' => array( '<img class="lazyload" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="a.jpg" data-srcset="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7 1x, a.jpg 2x" alt="">' ),
			'a format in prose'   => array( '<p>Data: CSV/JSON</p><p>Data: text/plain (UTF-8)</p>' ),
			// Codex, round 25 (B-1): in quotes as well.
			'a format in quotes in prose' => array( '<p>Format: "Data: CSV/JSON"</p><p>Hinweis: "data: siehe Tabelle"</p>' ),
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
		// Codex, round 17 (A4), 18 (B3): data: in url(…), @import or
		// image-set(…): an image decoded, a site named in it locks; any other
		// type, or one that does not decode, locks. What the image holds
		// beside a site (an address with an id, a credential) is the net's
		// (netReferences(), netCredentials()).
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
			self::assertSame( array( 'clean', null, array( 'css:css' ) ), $read( $css ), $css );
		}
		// The mask of the image block of the core (blocks/image/style.css:177),
		// percent-encoded, base64; a text of an image, a script of an image
		// (it runs none); raster images: free for the readers of CSS.
		$svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>';
		foreach ( array(
			"img{mask-image:url('data:image/svg+xml;utf8," . $svg . "')}",
			'img{mask-image:url("data:image/svg+xml,' . rawurlencode( $svg ) . '")}',
			'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( $svg ) . ')}',
			'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><text x="1" y="9">Token: x</text></svg>' ) . ')}',
			'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>var x = 1;</script><circle r="1" onclick="go()"/></svg>' ) . ')}',
			'a{background:url( DATA:image/png;base64,iVBORw0KGgo= )}',
			'a{background:url(data:Image/PNG;base64,iVBORw0KGgo=)}',
			'a{background:url("data:image/png;base64,iVBO Rw0K Ggo=")}',
			'a{background-image:image-set("data:image/webp;base64,UklGRg==" 1x, "data:image/avif;base64,AAAA" 2x)}',
			'a{background:url(data:image/gif;base64,R0lGODlh) , url(data:image/jpeg;base64,/9j/4A==)}',
			'a{background:url(data:image/png;base64,' . base64_encode( "\x89PNG tEXt api_key=demo-secret" ) . ')}',
		) as $css ) {
			self::assertSame( array( 'clean', null, array() ), $read( $css ), $css );
		}
		// An SVG that names a site: locked (its host cannot be replaced in place).
		$site = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://staging.test/a.png"/></svg>';
		self::assertContains( 'css:css', $read( "a{mask:url('data:image/svg+xml;base64," . base64_encode( $site ) . "')}" )[2] );
		self::assertContains( 'css:css', $read( 'a{mask:url("data:image/svg+xml,' . rawurlencode( $site ) . '")}' )[2] );
	}

	/* ----------------------------------------------------------- the net */

	/**
	 * A text as a unit of its kind through its readers and the net over the
	 * raw value (AB_MCP_Sync_Net, as AB_MCP_Sync_Db_Kinds runs it).
	 *
	 * @param string $raw  The stored value.
	 * @param string $as   content (post_content: blocks), css (custom_css)
	 *                     or value (a meta value).
	 * @param array  $ctx  Context; reads_ctx() when null.
	 * @return array{0:string[],1:string[],2:array} The locks as key@at, the
	 *               names of credentials noted, the accumulator.
	 */
	private static function net_read( string $raw, string $as = 'value', ?array $ctx = null ): array {
		$ctx  = $ctx ?? self::reads_ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$mark = AB_MCP_Sync_Net::mark( $acc );
		AB_MCP_Sync_Credentials::note( $acc, 'post', 'u', AB_MCP_Sync_Credentials::unit( '', $raw, array(), 'css' === $as ) );
		if ( 'content' === $as ) {
			AB_MCP_Sync_Refs::content( $raw, $ctx, $acc, 'post', 'u' );
		} else {
			AB_MCP_Sync_Canon::value( $raw, 'css' === $as ? array( 'css' => true ) + $ctx : $ctx, $acc, 'post', 'u' );
		}
		AB_MCP_Sync_Net::check( $acc, $mark, 'post', 'u', $raw, $ctx );
		$locks = array();
		foreach ( $acc['locks'] as $lock ) {
			$locks[] = $lock['key'] . '@' . $lock['at'];
		}
		$names = array();
		foreach ( $acc['credentials']['mixed'] as $mixed ) {
			$names = array_merge( $names, $mixed['fields'] );
		}
		return array( $locks, $names, $acc );
	}

	/** The context with the plain permalinks of the site: no rule routes a path. */
	private static function net_ctx(): array {
		return AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, array( 'rules' => array(), 'verbose' => false, 'index' => false, 'pages' => static fn( $p ) => null ) );
	}

	private static function svg( string $inner ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
	}

	/**
	 * Codex, round 20 (A-1, A-4, A-5) and their variations (Thomas,
	 * 04.11.2026: thought ahead, not found afterwards): an address with an id
	 * no reader of the core read, in any layer: the net locks the unit
	 * (*:unread_reference at u:net:<layer>). A text with a tag fails closed
	 * without the core (locked by the readers, the net adds nothing).
	 */
	public static function netReferences(): array {
		$svg_link = self::svg( '<a href="/?p=12"><circle r="1"/></a>' );
		return array(
			'A-1 an escaped quote before a variable'    => array( '.x{content:"a\\"";--bild:"/?attachment_id=12";background-image:image-set(var(--bild) 1x)}', 'css', 'raw' ),
			'A-1 in single quotes'                      => array( ".x{content:'a\\'';--bild:'/?attachment_id=12'}", 'css', 'raw' ),
			'A-1 a variable with a page id'             => array( ".x{--bild:'/?page_id=12'}", 'css', 'raw' ),
			'A-1 a variable in style of content'        => array( '<style>.x{--bild:"/?attachment_id=12"}</style>', 'content', 'raw' ),
			'A-1 a variable in a style attribute'       => array( '<div style="--bild:\'/?attachment_id=12\'">x</div>', 'content', 'raw' ),
			'A-4 dropped in a block attribute'          => array( '<!-- wp:acme/x {"u":"/?p=12","u":"x"} /-->', 'content', 'raw' ),
			'A-5 percent-encoded'                       => array( '<a data-options="%7B%22src%22%3A%22%2F%3Fp%3D12%22%7D">x</a>', 'content', 'raw/percent' ),
			'A-5 one read beside one not'               => array( '<a href="/?p=12" data-target="/?p=13">x</a>', 'content', 'raw' ),
			'a title with a short link'                 => array( '<p title="/?p=12">x</p>', 'content', 'raw' ),
			'base64 in a meta value'                    => array( base64_encode( '{"src":"/?p=12"}' ), 'value', 'raw/base64' ),
			'base64 inside serialized'                  => array( serialize( array( 'b' => base64_encode( '<a href="/?p=12">' ) ) ), 'value', 'raw/base64' ),
			'a data: text with an address'              => array( 'data:text/plain;base64,' . base64_encode( '/?p=12' ), 'value', 'raw/data' ),
			'a data: address inside base64'             => array( base64_encode( 'x data:text/plain;base64,' . base64_encode( '/?p=12' ) ), 'value', 'raw/base64/data' ),
			'an SVG with a link in a variable'          => array( '.x{--m:"data:image/svg+xml;base64,' . base64_encode( $svg_link ) . '"}', 'css', 'raw/data' ),
			'an SVG with a link in url()'               => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( $svg_link ) . ')}', 'css', 'raw/data' ),
			'an SVG with a link, percent-encoded'       => array( 'a{mask:url("data:image/svg+xml,' . rawurlencode( $svg_link ) . '")}', 'css', 'raw/data' ),
			'an SVG with style in url()'                => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<style>a{background:url(/?p=12)}</style>' ) ) . ')}', 'css', 'raw/data' ),
			'an SVG with an address in a style attribute' => array( '<p style="mask-image:url(\'data:image/svg+xml;utf8,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot;><a href=&quot;/?p=12&quot;/></svg>\')">x</p>', 'content', 'raw' ),
			'a JSON escape of the slash'                => array( '<p title="\\/?p=12">x</p>', 'content', 'raw/json' ),
			// Codex, round 21 (A-1): a query wherever it stands, relative to
			// the page.
			'A-1 index.php in a title'                  => array( '<p title="index.php?p=12">x</p>', 'content', 'raw' ),
			'A-1 ./ in base64'                          => array( base64_encode( './?p=12' ), 'value', 'raw/base64' ),
			'A-1 ../ in a data: text'                   => array( 'data:text/plain;base64,' . base64_encode( '../?p=12' ), 'value', 'raw/data' ),
			'A-1 a query after letters in prose'        => array( 'siehe seite?p=12', 'value', 'raw' ),
			'A-1 in a data attribute of a plugin, no JSON' => array( '<a data-target="index.php?p=12">x</a>', 'content', 'raw' ),
			// Codex, round 21 (A-2): a reference read in the text never covers
			// one in a layer taken out of it.
			'A-2 a data: document beside a link read'   => array( '<a href="/?p=12">normal</a><a data-fancybox data-type="iframe" data-options="data:text/html;base64,' . base64_encode( '<a href="https://staging.test/?p=13">target</a>' ) . '">open</a>', 'content', 'raw/data' ),
			'A-2 the same id hidden beside one read'    => array( '<a href="/?p=12">normal</a><a data-options="data:text/html;base64,' . base64_encode( '<a href="/?p=12">x</a>' ) . '">open</a>', 'content', 'raw/data' ),
			'A-2 base64 beside a link read'             => array( '<p><a href="https://staging.test/?p=12">x</a> ' . base64_encode( '{"src":"/?p=13"}' ) . '</p>', 'content', 'raw/base64' ),
			'A-2 a copy in the text beside one read'    => array( '<a href="/?p=12">x</a><p title="/?p=12">y</p>', 'content', 'raw' ),
			// Codex, round 21 (B-1): the JSON of a data-* attribute no producer
			// of the core writes so is a text: an address with an id locks.
			'B-1 data-options with &amp; in quotes of HTML' => array( '<a data-fancybox data-options=\'{"src":"/?p=12&amp;x=1","type":"iframe"}\'>Details</a>', 'content', 'raw' ),
			'a character reference in a query'          => array( '<p title="/&#63;p=12">x</p>', 'content', 'raw' ),
			'a short link as character references'      => array( '<p title="&#47;&#63;p=12">x</p>', 'content', 'raw/html' ),
		);
	}

	#[DataProvider( 'netReferences' )]
	public function testTheNetLocksAnAddressWithAnIdNoReaderRead( string $raw, string $as, string $layer ): void {
		list( $locks, $names, $acc ) = self::net_read( $raw, $as, self::net_ctx() );
		if ( self::closed( $raw ) ) {
			self::assertNotSame( array(), array_merge( $acc['spelling'], array_column( $acc['locks'], 'key' ) ), 'Locked without the core: ' . $raw );
			return;
		}
		self::assertContains( '*:unread_reference@u:net:' . $layer, $locks, $raw . ' ' . json_encode( $locks ) );
		self::assertNotContains( '*:unread_credential', array_map( static fn( $l ) => explode( '@', $l )[0], $locks ), $raw );
		self::assertSame( array(), $names );
	}

	/**
	 * Codex, round 20 (A-2, A-3, A-4) and their variations: the name of a
	 * credential with a value beside it that the credential check did not
	 * name, in any layer: the net locks the unit (*:unread_credential) and
	 * notes the name, never a value.
	 */
	public static function netCredentials(): array {
		$svg_json = self::svg( '<metadata><script type="application/json">[{"name":"api_key","value":"demo-secret"}]</script></metadata><circle r="10"/>' );
		$svg_js   = self::svg( '<script>var cfg={api_key:"demo-secret"};</script>' );
		$svg_on   = self::svg( '<circle r="1" onclick="cfg[\'api_key\']=\'demo-secret\'"/>' );
		return array(
			'A-2 an SVG with JSON in a variable, base64'  => array( '.x{--bild:"data:image/svg+xml;base64,' . base64_encode( $svg_json ) . '";background-image:image-set(var(--bild) 1x)}', 'css', 'raw/data', 'api_key' ),
			'A-2 percent-encoded in a variable'           => array( '.x{--bild:"data:image/svg+xml,' . rawurlencode( $svg_json ) . '"}', 'css', 'raw/data', 'api_key' ),
			'A-2 in url()'                                => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( $svg_json ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-2 in a style attribute'                    => array( '<div style="--bild:url(data:image/svg+xml;base64,' . base64_encode( $svg_json ) . ')">x</div>', 'content', 'raw/data', 'api_key' ),
			'A-2 in the css of global styles'             => array( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"css":".x{--m:url(data:image/svg+xml;base64,' . base64_encode( $svg_json ) . ')}"}}', 'value', 'raw/data', 'api_key' ),
			'A-3 a script of an SVG image'                => array( 'a{background-image:url(data:image/svg+xml;base64,' . base64_encode( $svg_js ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-3 an event attribute of an SVG image'      => array( 'a{background-image:url(data:image/svg+xml;base64,' . base64_encode( $svg_on ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-3 single quotes in a script'               => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( "<script>var cfg={token:'x'}</script>" ) ) . ')}', 'css', 'raw/data', 'token' ),
			'A-3 a key after a comma in an object'        => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>init({a:1, client_secret: "x"})</script>' ) ) . ')}', 'css', 'raw/data', 'client_secret' ),
			'A-4 a key twice with a pair of name and value' => array( '{"x":{"name":"api_key","value":"demo-secret"},"x":0}', 'value', 'raw', 'api_key' ),
			'A-4 in a block attribute'                    => array( '<!-- wp:acme/x {"x":{"name":"api_key","value":"s"},"x":0} /-->', 'content', 'raw', 'api_key' ),
			'A-4 in a data attribute of a plugin'         => array( '<div data-config=\'{"x":{"name":"token","value":"s"},"x":1}\'></div>', 'content', 'raw', 'token' ),
			'A-4 the name as a JSON escape'               => array( '{"x":{"name":"api\\u005fkey","value":"s"},"x":0}', 'value', 'raw/json', 'api_key' ),
			'base64 in a meta value'                      => array( 'x ' . base64_encode( 'api_key=demo-secret' ) . ' y', 'value', 'raw/base64', 'api_key' ),
			'a data: text with a pair'                    => array( 'data:text/plain;base64,' . base64_encode( 'api_key=demo-secret' ), 'value', 'raw/data', 'api_key' ),
			'a data: text in a data attribute'            => array( '<div data-x="data:text/plain;base64,' . base64_encode( '{"token":"x"}' ) . '">x</div>', 'content', 'raw/data', 'token' ),
			'the text of a raster image'                  => array( 'a{background:url(data:image/png;base64,' . base64_encode( "\x89PNG tEXt api_key=demo-secret" ) . ')}', 'css', 'raw/data', 'api_key' ),
			'CSS of a data: import in style'              => array( '<style>@import url("data:text/css;base64,' . base64_encode( ':root{--api-key:demo-secret}' ) . '");</style>', 'content', 'raw/data', 'api_key' ),
			'base64 inside serialized'                    => array( serialize( array( 'b' => base64_encode( '{"name":"token","value":"s"}' ) ) ), 'value', 'raw/base64', 'token' ),
			'an SVG with a field, in a variable'          => array( '.x{--m:"data:image/svg+xml;base64,' . base64_encode( self::svg( '<input name="api_key" value="x"/>' ) ) . '"}', 'css', 'raw/data', 'api_key' ),
			'an SVG with a property of CSS'               => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<style>:root{--client-secret:1}</style>' ) ) . ')}', 'css', 'raw/data', 'client_secret' ),
			'camel case in a dropped value'               => array( '{"x":{"name":"accessToken","value":"s"},"x":0}', 'value', 'raw', 'access_token' ),
			// Codex, round 21 (A-3): a text in another charset, by its charset or BOM.
			'A-3 UTF-16 by its charset'                   => array( 'a{background-image:url(data:image/svg+xml;charset=utf-16;base64,' . base64_encode( "\xff\xfe" . mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg"><metadata><script type="application/json">{"api_key":"demo-secret"}</script></metadata></svg>', 'UTF-16LE', 'UTF-8' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-3 UTF-16BE by its BOM'                     => array( 'a{mask:url(' . 'data:image/svg+xml;base64,' . base64_encode( "\xfe\xff" . mb_convert_encoding( self::svg( '<script>var cfg={token:"demo-secret"}</script>' ), 'UTF-16BE', 'UTF-8' ) ) . ')}', 'css', 'raw/data', 'token' ),
			'A-3 ISO-8859-1 in a data: text'              => array( 'data:text/plain;charset=iso-8859-1;base64,' . base64_encode( "caf\xe9 api_key=demo-secret" ), 'value', 'raw/data', 'api_key' ),
			'A-3 UTF-16 in base64 by its BOM'             => array( base64_encode( "\xff\xfe" . mb_convert_encoding( 'client_secret=demo-secret', 'UTF-16LE', 'UTF-8' ) ), 'value', 'raw/base64', 'client_secret' ),
			// Codex, round 21 (A-4): the spellings of JavaScript.
			'A-4 \\u{5f} in the name'                     => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg={api\\u{5f}key:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data/js', 'api_key' ),
			'A-4 \\x5f in the name'                       => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg={api\\x5fkey:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data/js', 'api_key' ),
			'A-4 a comment before the colon'              => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg = {api_key/*a*/:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data/code', 'api_key' ),
			'A-4 a comment to the end of the line'        => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( "<script>var cfg = {\n  api_key // Hinweis\n  : \"demo-secret\"\n};</script>" ) ) . ')}', 'css', 'raw/data/code', 'api_key' ),
			'A-4 a template literal as the name'          => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>cfg[`api_key`] = "demo-secret";</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-4 a template literal as the value'         => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>cfg[`api_key`] = `${secret}`;</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			// Codex, round 21 (A-5): a value with white space before it.
			'A-5 a space before the value'                => array( base64_encode( '{"api_key":" demo-secret"}' ), 'value', 'raw/base64', 'api_key' ),
			'A-5 a tab before the value, in an SVG'       => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<metadata><script type="application/json">{"api_key":"' . "\t" . 'demo-secret"}</script></metadata>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-5 a line break before the value'           => array( base64_encode( "token=\n demo-secret" ), 'value', 'raw/base64', 'token' ),
			// Codex, round 22 (A-2): a header of MIME with parameters, XML in
			// UTF-16 by its first bytes and its declaration.
			'A-2 a parameter in quotes before base64'     => array( 'a{mask:url(\'data:image/svg+xml;name="icon";base64,' . base64_encode( mb_convert_encoding( self::xml16( 'UTF-16LE' ), 'UTF-16LE', 'UTF-8' ) ) . '\')}', 'css', 'raw/data', 'api_key' ),
			'A-2 a charset with a space before it'        => array( 'a{mask:url(\'data:image/svg+xml; charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( self::xml16( 'UTF-16LE' ), 'UTF-16LE', 'UTF-8' ) ) . '\')}', 'css', 'raw/data', 'api_key' ),
			'A-2 a charset in quotes'                     => array( 'a{mask:url(\'data:image/svg+xml;charset="utf-16le";base64,' . base64_encode( mb_convert_encoding( self::xml16( 'UTF-16LE' ), 'UTF-16LE', 'UTF-8' ) ) . '\')}', 'css', 'raw/data', 'api_key' ),
			'A-2 UTF-16BE without a BOM, by its declaration' => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( mb_convert_encoding( self::xml16( 'UTF-16BE' ), 'UTF-16BE', 'UTF-8' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-2 ISO-8859-1 by its declaration'           => array( 'data:text/xml;base64,' . base64_encode( "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><r>caf\xe9 token=x</r>" ), 'value', 'raw/data', 'token' ),
			'A-2 a parameter with an escaped quote'       => array( 'data:text/plain;name="a\\"b";charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( 'token=x', 'UTF-16LE', 'UTF-8' ) ), 'value', 'raw/data', 'token' ),
			// Codex, round 22 (A-3, A-4): white space in a key in brackets, a
			// brace in a string of the object.
			'A-3 white space before the bracket'          => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>cfg["api_key" ] = "demo-secret";</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-3 line breaks in the brackets'             => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( "<script>cfg[\n\"api_key\"\n]=\"demo-secret\";</script>" ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-3 a backtick with white space'             => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>cfg[ `api_key` ] = `demo-secret`;</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-4 a closing brace in a string'             => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg = {a:"}",api_key:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-4 an opening brace in a string'            => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg = {a:"{",api_key:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data', 'api_key' ),
			'A-4 a brace in a template literal'           => array( 'a{mask:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<script>var cfg = {a:`}}`, token:"demo-secret"};</script>' ) ) . ')}', 'css', 'raw/data', 'token' ),
			// Codex, round 22 (B-3): the text of a raster image in src.
			'B-3 a PNG in src with a text'                => array( '<img src="data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n tEXt api_key=demo-secret" ) . '" alt="">', 'content', 'raw/data', 'api_key' ),
		);
	}

	/** An SVG with a credential in a JSON script, with its XML declaration for a charset. */
	private static function xml16( string $encoding ): string {
		return '<?xml version="1.0" encoding="' . $encoding . '"?><svg xmlns="http://www.w3.org/2000/svg"><metadata><script type="application/json">{"api_key":"demo-secret"}</script></metadata></svg>';
	}

	#[DataProvider( 'netCredentials' )]
	public function testTheNetLocksACredentialWithAValueTheCheckDidNotName( string $raw, string $as, string $layer, string $name ): void {
		list( $locks, $names, $acc ) = self::net_read( $raw, $as, self::net_ctx() );
		if ( self::closed( $raw ) ) {
			self::assertNotSame( array(), array_merge( $acc['spelling'], array_column( $acc['locks'], 'key' ) ), 'Locked without the core: ' . $raw );
			return;
		}
		self::assertContains( '*:unread_credential@u:net:' . $layer, $locks, $raw . ' ' . json_encode( $locks ) );
		self::assertContains( '~' . $name, $names );
		self::assertStringNotContainsString( 'demo-secret', json_encode( $acc ), 'Never a value.' );
	}

	/**
	 * What the net leaves free (Codex, rounds 18 to 20, group B): a site's
	 * address without an id, words about credentials, Tailwind, a CDN image
	 * beside a local id, the spelling of the core helper, a tooltip, prose,
	 * and every address the readers read as well (the counts are equal).
	 */
	public static function netFree(): array {
		return array(
			'JSON-LD with a site address'       => array( "<script type=\"application/ld+json\">\n{\n  \"@type\": \"Organization\",\n  \"url\": \"https://staging.test/ueber-uns/\"\n}\n</script>", 'content' ),
			'design tokens'                     => array( '/* design tokens */ a{color:red}', 'css' ),
			'a text of an SVG image'            => array( 'img{mask-image:url(data:image/svg+xml;base64,' . base64_encode( self::svg( '<text x="1" y="9">Token: x</text>' ) ) . ')}', 'css' ),
			'Tailwind'                          => array( '.md\\:flex{display:flex} .w-1\\/2{width:50%} .sm\\:w-1\\/3{width:33%}', 'css' ),
			'a CDN image beside a local id'     => array( '<!-- wp:group {"style":{"background":{"backgroundImage":{"id":12,"url":"https://cdn.example.org/uploads/bg.jpg","source":"file"}}}} --><div class="wp-block-group"><p>x</p></div><!-- /wp:group -->', 'content' ),
			'the core helper with &'            => array( '<div data-wp-context=\'acme/store::{"url":"\/?p=12&foo=bar"}\'></div>', 'content' ),
			'a tooltip'                         => array( '<span data-tippy-content="[Strg+K] Suche öffnen">Suche</span>', 'content' ),
			'prose about credentials'           => array( '<p>Das Passwort: geheim, der Token: 123. Felder: Name, Token: "abc" (Secret: "def").</p>', 'content' ),
			'a label with a quoted value'       => array( 'Token: x', 'value' ),
			'an address read, with a host'      => array( '<a href="https://staging.test/?p=12">x</a>', 'content' ),
			'an address read, root-relative'    => array( '<a href="/?p=12">x</a>', 'content' ),
			'an address read in prose'          => array( 'Link https://staging.test/?p=12 im Text.', 'value' ),
			'an address read in a string of CSS' => array( ".x{--bild:'https://staging.test/?p=12'} .y{content:\"Mehr: https://staging.test/?page_id=13\"}", 'css' ),
			'escapes of CSS the readers lock'   => array( '.x{--u:"\\2f archives\\2f 12"}', 'css' ),
			'a list of ids read'                => array( 'https://staging.test/?cat=1,2,3', 'value' ),
			'JSON of the core read'             => array( '{"url":"\\/?p=12"}', 'value' ),
			'JSON in a context read'            => array( '<div data-wp-context=\'{"url":"\\/?p=12"}\'></div>', 'content' ),
			'a shortcode read'                  => array( '[acme url="https://staging.test/?p=12"]', 'value' ),
			'an image read'                     => array( '<figure class="wp-block-image"><img src="https://staging.test/wp-content/uploads/2024/05/a.jpg" class="wp-image-12" alt=""/></figure>', 'content' ),
			'srcset'                            => array( '<img src="https://staging.test/a.jpg" srcset="https://staging.test/a-300x200.jpg 300w, https://staging.test/a.jpg 1024w" alt="">', 'content' ),
			'a login link without an id'        => array( 'https://staging.test/wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2Fkontakt%2F', 'value' ),
			'a login link with an encoded short link' => array( 'https://staging.test/wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2F%3Fp%3D12', 'value' ),
			// Codex, round 21 (B-2): a route of another web application.
			'B-2 a route of another web application' => array( '<a href="https://catalog.example/#/search?p=12">Seite 12 im Katalog</a>', 'content' ),
			'B-2 in prose'                      => array( 'Siehe https://catalog.example/#/search?p=12 und https://cdn.example.org/x/?attachment_id=3.', 'value' ),
			'B-2 a menu link'                   => array( '//catalog.example/#/items/12', 'value' ),
			// Codex, round 21 (B-1): the JSON of Fancybox read by its round trip.
			'B-1 the JSON of Fancybox'          => array( '<a data-fancybox data-options=\'{"src":"/?p=12","type":"iframe"}\'>Details</a>', 'content' ),
			'B-1 as references'                 => array( '<a data-options="{&quot;src&quot;:&quot;/?p=12&quot;}">x</a>', 'content' ),
			'B-1 absolute'                      => array( '<a data-options=\'{"src":"https://staging.test/?p=12"}\'>x</a>', 'content' ),
			// Codex, round 22 (B-2): JSON as a plugin writes it, value by value.
			'B-2 Fancybox with spaces'          => array( '<a data-fancybox data-options=\'{ "src": "/?p=12", "type": "iframe" }\'>Details</a>', 'content' ),
			'B-2 Fancybox with line breaks'     => array( "<a data-fancybox data-options='{\n  \"src\": \"/?p=12\",\n  \"type\": \"iframe\"\n}'>Details</a>", 'content' ),
			'B-2 Fancybox with &amp; as esc_attr() writes it' => array( '<a data-options="{&quot;src&quot;:&quot;/?p=12&amp;x=1&quot;}">x</a>', 'content' ),
			'B-2 JSON-LD indented with an id'   => array( "<script type=\"application/ld+json\">\n{\n  \"@type\": \"Article\",\n  \"url\": \"https://staging.test/?p=12\"\n}\n</script>", 'content' ),
			// Codex, round 22 (B-1): the visible text of a link, prose.
			'B-1 the visible text of a link'    => array( '<p><a href="/?p=12">/?p=12</a></p>', 'content' ),
			'B-1 a short link in prose'         => array( 'Der Kurzlink /?p=12 und die Seite /?page_id=13.', 'value' ),
			'B-1 in a description'              => array( '<p>Siehe <strong>/?p=12</strong> dort.</p>', 'content' ),
			// Codex, round 22 (A-1): a query value read, and one of another host.
			'A-1 redirect_to read'              => array( '<p><a href="https://staging.test/wp-login.php?redirect_to=%2F%3Fp%3D12">Anmelden</a></p>', 'content' ),
			'A-1 redirect_to to another host'   => array( '<p><a href="/?redirect_to=https%3A%2F%2Fcatalog.example%2F%23%2Fsearch%3Fp%3D12">x</a></p>', 'content' ),
			// Codex, round 22 (B-3): a placeholder of a lazy loader.
			'B-3 a GIF placeholder'             => array( '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="">', 'content' ),
			'B-3 a PNG placeholder beside data-src' => array( '<img src="data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" ) . '" data-src="https://staging.test/wp-content/uploads/a.jpg" alt="">', 'content' ),
			'a comment of code in prose'        => array( 'Preis: 1/2 // kein Kommentar, /* auch nicht */ https://staging.test/?p=12 gelesen', 'value' ),
			'fractions, dates, calc'            => array( 'Preis: 1/2 Tag am 05/12/2024, siehe /2024/05/ und calc(100%/3), 16/9.', 'value' ),
			'CSS with slashes'                  => array( 'a{grid-area:1 / 2 / 3 / 4;font:12px/1.5 Arial;aspect-ratio:16/9;background:url(/img/12.png)}', 'css' ),
			'base64 of a word'                  => array( 'Kontaktformular Datenschutzerklaerung Veranstaltungskalender', 'value' ),
			'base64 of an image'                => array( 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'value' ),
			'a password field named'            => array( '<input type="password" name="pwd" class="token-field">', 'content' ),
			'a credential the check named'      => array( '{"api_key":"x","x":"' . base64_encode( 'api_key=x' ) . '"}', 'value' ),
			// Codex, round 25 (A-6): a share text with "+" for a space, read by
			// the readers and by the percent layer alike.
			'A-6 a mailto text as urlencode() writes it' => array( '<p><a href="mailto:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Mail</a></p>', 'content' ),
			'A-6 a share text of Twitter as urlencode() writes it' => array( '<p><a href="https://twitter.com/intent/tweet?text=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Teilen</a></p>', 'content' ),
			'A-6 an sms text as urlencode() writes it' => array( '<p><a href="sms:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12">SMS</a></p>', 'content' ),
			'A-6 a mailto text as rawurlencode() writes it' => array( '<p><a href="mailto:?body=Lesen%3A%20https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Mail</a></p>', 'content' ),
			'A-6 in prose'                      => array( 'Teilen: mailto:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12+und+https%3A%2F%2Fstaging.test%2F%3Fpage_id%3D13', 'value' ),
		);
	}

	#[DataProvider( 'netFree' )]
	public function testTheNetLeavesTheOrdinaryFree( string $raw, string $as ): void {
		list( $locks ) = self::net_read( $raw, $as, self::net_ctx() );
		self::assertSame( array(), preg_grep( '/^\*:unread_/', $locks ), $raw . ' ' . json_encode( $locks ) );
	}

	/**
	 * What the net cannot read locks only where it stands so (Codex, round
	 * 25): a RegExp literal of code before the name of a credential (A-1:
	 * after a division as well), a header of a data: address that is none
	 * where the address stands as one (B-1: an attribute of an address,
	 * url() and image-set() of CSS, a string value of JSON); a division
	 * after a postfix ++, an end tag of HTML, "data:" in quotes in prose,
	 * in another attribute or as a key of JSON: free. Null: no lock at all.
	 */
	public static function netUnreadable(): array {
		$svg = base64_encode( '<svg/>' );
		return array(
			'A-1 a literal after a division'      => array( '<pre><code>const c={x:1/2+/}/.test("x"),api_key:"demo-secret"};</code></pre>', 'content', '*:unreadable_value@u:net:raw/code' ),
			'A-1 after a division and a space'    => array( 'const c={x:6/2 + /}/.test("x"),api_key:"demo-secret"};', 'value', '*:unreadable_value@u:net:raw/code' ),
			'A-1 after the parenthesis of if'     => array( 'var o={k:(function(){ if (a) /}/.test(b) })(), api_key:"s"};', 'value', '*:unreadable_value@u:net:raw/code' ),
			'A-1 a division after a postfix'      => array( '<pre><code>const o={}; let x=4; x++ / 2 / 3; label: "x";</code></pre>', 'content', null ),
			'A-1 divisions'                       => array( 'const o={}; a/b/c; (1)/2/3; 6/2; label: "x";', 'value', null ),
			'A-1 end tags before a label'         => array( '<p>Siehe <a href="https://example.test/">hier</a> und <em>dort</em>, Ort: Zürich</p>', 'content', null ),
			'B-1 a format in quotes'              => array( '<p>Format: "Data: CSV/JSON"</p>', 'content', null ),
			'B-1 a note in quotes'                => array( '<p>Hinweis: "data: siehe Tabelle"</p>', 'content', null ),
			'B-1 in another attribute'            => array( '<p title="data: siehe Tabelle">x</p>', 'content', null ),
			'B-1 in quotes in a value of JSON'    => array( '{"note":"Hinweis: \\"data: siehe Tabelle\\""}', 'value', null ),
			'B-1 a key of JSON'                   => array( '{"data: x":1}', 'value', null ),
			'B-1 in a block attribute'            => array( '<!-- wp:acme/x {"url":"data:image/svg+x(ml;base64,' . $svg . '"} /-->', 'content', '*:unreadable_value@u:net:raw' ),
			'B-1 in a context of a block'         => array( '<div data-wp-context=\'{"img":"data:image/svg+x(ml;base64,' . $svg . '"}\'>x</div>', 'content', '*:unreadable_value@u:net:raw' ),
			'B-1 a value of JSON on a line of its own' => array( "{\"a\":1}\n{\"url\":\"data:image/svg+x(ml;base64," . $svg . '"}', 'value', '*:unreadable_value@u:net:raw' ),
			'B-1 the second candidate of image-set' => array( 'a{background-image:image-set("a.png" 1x, "data:image/svg+x(ml;base64,' . $svg . '" 2x)}', 'css', '*:unreadable_value@u:net:raw' ),
			'B-1 in url()'                        => array( 'a{mask:url("data:image/svg+x(ml;base64,' . $svg . '")}', 'css', '*:unreadable_value@u:net:raw' ),
			// Codex, round 26: "+" is a space in a query only (image/svg+xml,
			// a path keep it); a keyword after "." is a property; identifiers
			// of Unicode.
			'an SVG mask, percent-encoded'        => array( 'a{mask:url("data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E")}', 'css', null ),
			'an SVG in image-set(), percent-encoded' => array( 'a{background-image:image-set("data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E" 1x)}', 'css', null ),
			'an SVG in base64 beside a percentage' => array( 'a{width:100%;mask:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . ')}', 'css', null ),
			'an SVG mask in a style attribute'    => array( '<p style="width:100%;mask:url(&quot;data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E&quot;)">x</p>', 'content', null ),
			'an upload with + and %20'            => array( '<p><img src="https://staging.test/wp-content/uploads/a+b%20c.jpg" alt=""></p>', 'content', null ),
			'a method named if'                   => array( '<pre><code>const o={}; x = obj.if(a) / 2 / 3; label: "x";</code></pre>', 'content', null ),
			'a method named while'                => array( '<pre><code>const o={}; x = obj.while(a) / 2 / 3; label: "x";</code></pre>', 'content', null ),
			'a division by π'                     => array( '<pre><code>const o={}; x = 1 / π / 3; label: "x";</code></pre>', 'content', null ),
			'a division of ä'                     => array( '<pre><code>const o={}; x = ä / 2; label: "x";</code></pre>', 'content', null ),
			'A-1 if before a literal, still'      => array( '<pre><code>var o={k:(function(){ if (a) /}/.test(b) })(), api_key:"s"};</code></pre>', 'content', '*:unreadable_value@u:net:raw/code' ),
		);
	}

	#[DataProvider( 'netUnreadable' )]
	public function testTheNetLocksWhatItCannotReadOnlyWhereItStandsSo( string $raw, string $as, ?string $lock ): void {
		list( $locks ) = self::net_read( $raw, $as, self::net_ctx() );
		if ( self::closed( $raw ) ) {
			self::assertNotSame( array(), $locks, 'Without the core the credential check fails closed: ' . $raw );
			return;
		}
		if ( null === $lock ) {
			self::assertSame( array(), $locks, $raw );
			return;
		}
		self::assertContains( $lock, $locks, $raw . ' ' . json_encode( $locks ) );
	}

	/** Rules of a site: archives/12 is post 12, a date is none, any other path a page by its name (none). */
	private static function rules(): array {
		return array(
			'rules'   => array(
				'archives/([0-9]+)/?$'       => 'index.php?p=$matches[1]',
				'([0-9]{4})/([0-9]{1,2})/?$' => 'index.php?year=$matches[1]&monthnum=$matches[2]',
				'(.?.+?)/?$'                 => 'index.php?pagename=$matches[1]',
			),
			'verbose' => false,
			'index'   => false,
			'pages'   => static fn( $p ) => null,
		);
	}

	/**
	 * Codex, round 25 (A-5, Thomas: «a»): under a host of no site a target
	 * relative to the root or the page – the value, or one in a text –
	 * locks only where it carries a reference as the readers read one: an
	 * id variable of the core with digits, a path the rules route to an
	 * id. A question, a fraction, a date, a page by its name: free.
	 * Null: free and unchanged.
	 */
	public static function foreignTargets(): array {
		return array(
			'a question in a subject'          => array( '<p><a href="mailto:info@example.test?subject=Warum%3F">Mail</a></p>', null ),
			'a question in a share text'       => array( 'Teilen: https://twitter.com/intent/tweet?text=Warum%3F', null ),
			'a fraction in a subject'          => array( '<p><a href="mailto:info@example.test?subject=Preis%201%20%2F2">Mail</a></p>', null ),
			'a fraction in a subject in prose' => array( 'Mail: mailto:info@example.test?subject=Preis%201%20%2F2', null ),
			'a date in a share text'           => array( 'Teilen: https://twitter.com/intent/tweet?text=Termin%20%2F2026%2F11%2F04', null ),
			'a page of another site by its name' => array( '<p><a href="https://other.test/wp-login.php?redirect_to=%2Fkontakt%2F">Login</a></p>', null ),
			'a short link'                     => array( '<p><a href="https://other.test/wp-login.php?redirect_to=%2F%3Fp%3D12">Login</a></p>', 'c:html?redirect_to' ),
			'a short link relative to the page' => array( '<p><a href="https://other.test/wp-login.php?redirect_to=index.php%3Fp%3D12">Login</a></p>', 'c:html?redirect_to' ),
			'a page id without a scheme'       => array( 'Siehe //other.test/?u=%2F%3Fpage_id%3D13 dort.', 'c:html?u' ),
			'a path the rules route to an id'  => array( 'Login: https://other.test/wp-login.php?redirect_to=%2Farchives%2F12%2F', 'c:html?redirect_to' ),
			'a short link in a share text'     => array( 'Teilen: https://twitter.com/intent/tweet?text=Lesen%3A%20%2F%3Fp%3D12', 'c:html?text' ),
		);
	}

	#[DataProvider( 'foreignTargets' )]
	public function testATargetUnderAHostOfNoSiteLocksOnlyWithAReference( string $content, ?string $at ): void {
		if ( self::closed( $content ) ) {
			self::assertLockedWithoutTheCore( $content );
			return;
		}
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, self::rules() );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
		$plan = AB_MCP_Sync_Refs::plan(
			array( 'post' => array( 12 => 120, 13 => 130 ) ),
			array( 'https://staging.test' => 'https://www.live.test' ),
			self::rules()
		);
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, $plan );
		if ( null === $at ) {
			self::assertSame( array( array(), array(), array(), $content, array() ), array( $acc['refs'], $acc['locks'], $acc['spelling'], $out['content'], $out['missing'] ), $content );
			list( $locks ) = self::net_read( $content, 'content', $ctx );
			self::assertSame( array(), $locks, $content );
			return;
		}
		$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
		self::assertContains( array( '*:reference_spelling', $at ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $result['locks'] ), $content );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), $content );
	}

	public function testTheJsonOfADataAttributeIsReadByItsRoundTrip(): void {
		// Codex, round 21 (B-1): the JSON of a plugin in a data-* attribute
		// (Fancybox data-options), where a producer of the core writes it so
		// byte for byte, as it is or as esc_attr() writes it: read as
		// data-wp-* is, rewritten and written back in that spelling. What no
		// producer writes so is a text (the net locks an address with an id
		// in it, netReferences()); a text that only begins with [ is one.
		$cases = array(
			array( '<a data-fancybox data-options=\'{"src":"/?p=12","type":"iframe"}\'>Details</a>', 'post:12@c:html:json.src?p', '<a data-fancybox data-options=\'{"src":"/?p=120","type":"iframe"}\'>Details</a>' ),
			array( '<a data-options="{&quot;src&quot;:&quot;/?p=12&quot;}">x</a>', 'post:12@c:html:json.src?p', '<a data-options="{&quot;src&quot;:&quot;/?p=120&quot;}">x</a>' ),
			array( '<a data-options=\'{"src":"https://staging.test/?p=12"}\'>x</a>', 'post:12@c:html:json.src?p', '<a data-options=\'{"src":"https://www.live.test/?p=120"}\'>x</a>' ),
			array( '<div data-wp-context="{&quot;url&quot;:&quot;/?p=12&quot;}">x</div>', 'post:12@c:html:json.url?p', '<div data-wp-context="{&quot;url&quot;:&quot;/?p=120&quot;}">x</div>' ),
			array( '<div data-config=\'[{"href":"/?page_id=12"}]\'>x</div>', 'post:12@c:html:json.0.href?page_id', '<div data-config=\'[{"href":"/?page_id=120"}]\'>x</div>' ),
			// Codex, round 22 (B-2): no producer writes it so: value by value
			// in its spans, the formatting as it is.
			array( '<a data-fancybox data-options=\'{ "src": "/?p=12", "type": "iframe" }\'>Details</a>', 'post:12@c:html:json.src?p', '<a data-fancybox data-options=\'{ "src": "/?p=120", "type": "iframe" }\'>Details</a>' ),
			array( "<a data-fancybox data-options='{\n  \"src\": \"/?p=12\",\n  \"type\": \"iframe\"\n}'>Details</a>", 'post:12@c:html:json.src?p', "<a data-fancybox data-options='{\n  \"src\": \"/?p=120\",\n  \"type\": \"iframe\"\n}'>Details</a>" ),
			array( '<a data-options="{&quot;src&quot;:&quot;/?p=12&amp;x=1&quot;}">x</a>', 'post:12@c:html:json.src?p', '<a data-options="{&quot;src&quot;:&quot;/?p=120&amp;x=1&quot;}">x</a>' ),
			array( '<a data-options=\'{ "src": "\/?p=12" }\'>x</a>', 'post:12@c:html:json.src?p', '<a data-options=\'{ "src": "\/?p=120" }\'>x</a>' ),
			array( '<a data-options=\'{ "src": "https:\/\/staging.test\/archiv\/?p=12" }\'>x</a>', 'post:12@c:html:json.src?p', '<a data-options=\'{ "src": "https:\/\/www.live.test\/archiv\/?p=120" }\'>x</a>' ),
			array( "<script type=\"application/ld+json\">\n{\n  \"@type\": \"Article\",\n  \"url\": \"https://staging.test/?p=12\"\n}\n</script>", 'post:12@c:html:json.url?p', "<script type=\"application/ld+json\">\n{\n  \"@type\": \"Article\",\n  \"url\": \"https://www.live.test/?p=120\"\n}\n</script>" ),
			array( '<div data-wp-context="{ &quot;url&quot;: &quot;/?p=12&quot; }">x</div>', 'post:12@c:html:json.url?p', '<div data-wp-context="{ &quot;url&quot;: &quot;/?p=120&quot; }">x</div>' ),
		);
		foreach ( $cases as $case ) {
			list( $content, $ref, $rewritten ) = $case;
			if ( self::closed( $content ) ) {
				self::assertLockedWithoutTheCore( $content );
				continue;
			}
			list( $refs, $acc ) = self::reads_scan( $content );
			self::assertSame( array( array( $ref ), array() ), array( $refs, $acc['spelling'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
			self::assertSame( array( $rewritten, array() ), array( $out['content'], $out['missing'] ), $content );
		}
		// Relative to the page: locked by the reader, not rewritten.
		$page = '<a data-fancybox data-options=\'{"src":"index.php?p=12","type":"iframe"}\'>Details</a>';
		if ( ! self::closed( $page ) ) {
			list( $refs, $acc ) = self::reads_scan( $page );
			self::assertSame( array( array(), array( 'c:html:json.src?p' ) ), array( $refs, $acc['spelling'] ) );
			self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $page, self::reads_plan() )['missing'], 'space' ) );
		}
		// No JSON, or not stored as it is read: a text, nothing read, nothing
		// rewritten.
		foreach ( array(
			'<a data-options=\'{"src":"/?p=12&amp;x=1"}\'>x</a>',
			'<span data-tippy-content="[Strg+K] Suche öffnen">Suche</span>',
			'<span data-x="{nicht JSON">x</span>',
		) as $text ) {
			if ( self::closed( $text ) ) {
				continue;
			}
			list( $refs, $acc ) = self::reads_scan( $text );
			self::assertSame( array( array(), array() ), array( $refs, $acc['spelling'] ), $text );
			self::assertSame( $text, AB_MCP_Sync_Refs::rewrite_content( $text, self::reads_plan() )['content'], $text );
		}
	}

	public function testTheNetReadsNoDeeperThanItsDepth(): void {
		// A layer derived beyond MAX_DEPTH: the unit is not read to its end.
		$deep = '/?p=12';
		for ( $i = 0; $i < 5; $i++ ) {
			$deep = 'data:text/plain;base64,' . base64_encode( $deep );
		}
		list( $locks, , $acc ) = self::net_read( $deep, 'value', self::net_ctx() );
		self::assertContains( '*:unreadable_value@u:net', $locks );
		self::assertContains( 'depth:u:net', $acc['unread'] );
		$shallow = 'data:text/plain;base64,' . base64_encode( 'data:text/plain;base64,' . base64_encode( '/?p=12' ) );
		list( $locks ) = self::net_read( $shallow, 'value', self::net_ctx() );
		self::assertContains( '*:unread_reference@u:net:raw/data/data', $locks );
		self::assertNotContains( '*:unreadable_value@u:net', $locks );
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
		// An escape of CSS tells CSS from a text (as a text node, /?p=1 would
		// be read): under the key read as CSS in JSON and in a structure, and
		// rewritten as CSS (locked, not the id 1 mapped).
		$escaped = $styles( array( 'css' => '.x{background:url(/?p=1\\32)}' ) );
		list( $refs, $acc ) = self::reads_scan( $escaped );
		self::assertSame( array( array(), array( 'c:html:json.styles.css:css' ) ), array( $refs, $acc['spelling'] ), $escaped );
		$out = AB_MCP_Sync_Refs::rewrite_content( $escaped, self::reads_plan() );
		self::assertSame( array( $escaped, array( 'spelling' ) ), array( $out['content'], array_column( $out['missing'], 'space' ) ), $escaped );
		$structure = serialize( array( 'css' => '.x{background:url(/?p=1\\32)}' ) );
		$acc       = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( $structure, self::reads_ctx(), $acc, 'option', 'o' );
		self::assertSame( array( array(), array( 'o.css:css' ) ), array( $acc['refs'], $acc['spelling'] ), $structure );
		$plan = self::reads_plan();
		self::assertSame( $structure, AB_MCP_Sync_Refs::rewrite_value( $structure, $plan, $changed ) );
		self::assertSame( array( 'spelling' ), array_column( $plan['missing'], 'space' ), $structure );
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
			// Codex, round 22 (B-1): a root-relative address in a text node
			// (the visible text of a link, prose) as the value of an attribute.
			'the visible text of a link' => array( '<p><a href="/?p=12">/?p=12</a></p>', array( 'post:12@c:html?p', 'post:12@c:html?p' ), '<p><a href="/?p=120">/?p=120</a></p>' ),
			'root-relative in prose' => array( 'Siehe /?p=12 und /?page_id=13.', array( 'post:12@c:html?p', 'post:13@c:html?page_id' ), 'Siehe /?p=120 und /?page_id=130.' ),
			'root-relative in a text node after a tag' => array( '<p>Kurzlink: <strong>/?p=12</strong>, Seite /?page_id=13</p>', array( 'post:12@c:html?p', 'post:13@c:html?page_id' ), '<p>Kurzlink: <strong>/?p=120</strong>, Seite /?page_id=130</p>' ),
			'an escaped shortcode as text' => array( '[[acme_button url="/?p=12"]]', array( 'post:12@c:html?p' ), '[[acme_button url="/?p=120"]]' ),
			// Codex, round 22 (A-1): the value of a query variable that is an
			// address of the sites, in the encoding that writes it so.
			'redirect_to encoded' => array( '<p><a href="https://staging.test/wp-login.php?redirect_to=%2F%3Fp%3D12">Anmelden</a></p>', array( 'post:12@c:html?redirect_to?p' ), '<p><a href="https://www.live.test/wp-login.php?redirect_to=%2F%3Fp%3D120">Anmelden</a></p>' ),
			'redirect_to encoded with the host' => array( '<p><a href="https://staging.test/wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Anmelden</a></p>', array( 'post:12@c:html?redirect_to?p' ), '<p><a href="https://www.live.test/wp-login.php?redirect_to=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120">Anmelden</a></p>' ),
			'redirect_to encoded with the host in prose' => array( 'Anmelden: https://staging.test/wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?redirect_to?p' ), 'Anmelden: https://www.live.test/wp-login.php?redirect_to=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			'a query value as it is in prose' => array( 'Siehe /?x=/?p=13 dort.', array( 'post:13@c:html?x?p' ), 'Siehe /?x=/?p=130 dort.' ),
			// Codex, round 23 (A-1): an address of the sites in the value of a
			// query of an address of no site (a share link), by its round trip:
			// host and id rewritten, written back in the encoding stored.
			'a share link of Twitter' => array( '<p><a href="https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Teilen</a></p>', array( 'post:12@c:html?url?p' ), '<p><a href="https://twitter.com/intent/tweet?url=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120">Teilen</a></p>' ),
			'a share link of Facebook in prose' => array( 'Teilen: https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?u?p' ), 'Teilen: https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			'a share link of LinkedIn' => array( '<p><a href="https://www.linkedin.com/shareArticle?mini=true&amp;url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12&amp;title=Beitrag">Teilen</a></p>', array( 'post:12@c:html?url?p' ), '<p><a href="https://www.linkedin.com/shareArticle?mini=true&amp;url=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120&amp;title=Beitrag">Teilen</a></p>' ),
			'a share link with the text first' => array( 'https://twitter.com/intent/tweet?text=Lesen&url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?url?p' ), 'https://twitter.com/intent/tweet?text=Lesen&url=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			'a mailto with the address in its body' => array( '<p><a href="mailto:?subject=Tipp&amp;body=https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Mailen</a></p>', array( 'post:12@c:html?body?p' ), '<p><a href="mailto:?subject=Tipp&amp;body=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120">Mailen</a></p>' ),
			'a share link as urlencode() writes it' => array( 'Teilen: https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fcat%3D5%2C6%23~', array( 'term:5@c:html?url?cat', 'term:6@c:html?url?cat' ), 'Teilen: https://twitter.com/intent/tweet?url=https%3A%2F%2Fwww.live.test%2F%3Fcat%3D50%2C60%23~' ),
			'a login link without an id, its host inside rewritten' => array( 'https://staging.test/wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2Fkontakt%2F', array(), 'https://www.live.test/wp-login.php?redirect_to=https%3A%2F%2Fwww.live.test%2Fkontakt%2F' ),
			'a share link without an id, its host inside rewritten' => array( '<p><a href="https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2Fkontakt%2F">Teilen</a></p>', array(), '<p><a href="https://twitter.com/intent/tweet?url=https%3A%2F%2Fwww.live.test%2Fkontakt%2F">Teilen</a></p>' ),
			'a mailto in prose' => array( 'Mailen: mailto:?body=https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?body?p' ), 'Mailen: mailto:?body=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			// Codex, round 24 (A-6): a share text with an address of the sites in it, by the same round trip.
			'a mailto with a text and a permalink' => array( '<p><a href="mailto:?body=Lesen%3A%20https%3A%2F%2Fstaging.test%2Fkontakt%2F">Mail</a></p>', array(), '<p><a href="mailto:?body=Lesen%3A%20https%3A%2F%2Fwww.live.test%2Fkontakt%2F">Mail</a></p>' ),
			'a share text of Twitter with a permalink and an id' => array( 'Teilen: https://twitter.com/intent/tweet?text=Lesen%3A%20https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?text?p' ), 'Teilen: https://twitter.com/intent/tweet?text=Lesen%3A%20https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			'two addresses in a share text' => array( 'Mailen: mailto:?body=https%3A%2F%2Fstaging.test%2F%3Fp%3D12%20und%20https%3A%2F%2Fstaging.test%2Fkontakt%2F', array( 'post:12@c:html?body?p' ), 'Mailen: mailto:?body=https%3A%2F%2Fwww.live.test%2F%3Fp%3D120%20und%20https%3A%2F%2Fwww.live.test%2Fkontakt%2F' ),
			'a share text as urlencode() writes it' => array( 'Teilen: https://twitter.com/intent/tweet?text=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12', array( 'post:12@c:html?text?p' ), 'Teilen: https://twitter.com/intent/tweet?text=Lesen+https%3A%2F%2Fwww.live.test%2F%3Fp%3D120' ),
			// Codex, round 25 (A-6): mailto and sms as urlencode() writes them.
			'a mailto text as urlencode() writes it' => array( '<p><a href="mailto:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12">Mail</a></p>', array( 'post:12@c:html?body?p' ), '<p><a href="mailto:?body=Lesen+https%3A%2F%2Fwww.live.test%2F%3Fp%3D120">Mail</a></p>' ),
			'an sms text as urlencode() writes it' => array( '<p><a href="sms:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12">SMS</a></p>', array( 'post:12@c:html?body?p' ), '<p><a href="sms:?body=Lesen+https%3A%2F%2Fwww.live.test%2F%3Fp%3D120">SMS</a></p>' ),
			'a text with a root-relative address in a query value' => array( 'Siehe https://staging.test/?u=Lesen%3A%20%2F%3Fp%3D12', array( 'post:12@c:html?u?p' ), 'Siehe https://www.live.test/?u=Lesen%3A%20%2F%3Fp%3D120' ),
			'a space after a query value' => array( 'Siehe https://staging.test/?u=%2F%3Fp%3D12%20', array( 'post:12@c:html?u?p' ), 'Siehe https://www.live.test/?u=%2F%3Fp%3D120%20' ),
			// Codex, round 24 (B-2): the markup of a lazy loader.
			'a data-src with a short link' => array( '<img data-src="/?attachment_id=12" alt="">', array( 'post:12@c:html?attachment_id' ), '<img data-src="/?attachment_id=120" alt="">' ),
			'a lazy loader with data-src and data-srcset' => array( '<picture><source data-srcset="/?p=12 1x, /?page_id=13 2x" type="image/webp"><img class="lazyload" data-src="/?p=12" alt=""></picture>', array( 'post:12@c:html?p', 'post:13@c:html?page_id', 'post:12@c:html?p' ), '<picture><source data-srcset="/?p=120 1x, /?page_id=130 2x" type="image/webp"><img class="lazyload" data-src="/?p=120" alt=""></picture>' ),
			'a candidate of srcset ending with a comma' => array( '<img src="/?p=12" srcset="/?p=12, /?page_id=13 2x" alt="">', array( 'post:12@c:html?p', 'post:12@c:html?p', 'post:13@c:html?page_id' ), '<img src="/?p=120" srcset="/?p=120, /?page_id=130 2x" alt="">' ),
			// Codex, round 23 (B-1): a text node with character references: the
			// address found where it stands, as it is or as esc_html() writes it.
			'a reference before the address in a text node' => array( '<p>A &amp; B /?p=12</p>', array( 'post:12@c:html?p' ), '<p>A &amp; B /?p=120</p>' ),
			'a reference inside the address in a text node' => array( '<p>/?p=12&amp;x=1</p>', array( 'post:12@c:html?p' ), '<p>/?p=120&amp;x=1</p>' ),
			'a reference inside, the id after it' => array( '<p>Siehe /?x=1&amp;p=12 und /?page_id=13.</p>', array( 'post:12@c:html?p', 'post:13@c:html?page_id' ), '<p>Siehe /?x=1&amp;p=120 und /?page_id=130.</p>' ),
			'a less-than before two different addresses' => array( '<p>Preis &lt; 5: /?p=12, dann /?page_id=13</p>', array( 'post:12@c:html?p', 'post:13@c:html?page_id' ), '<p>Preis &lt; 5: /?p=120, dann /?page_id=130</p>' ),
			// Codex, round 23 (B-2): the candidates of srcset, each an address.
			'a candidate of srcset with an id' => array( '<img src="https://staging.test/a.jpg" srcset="https://staging.test/?p=12 300w, https://staging.test/a.jpg 1024w" alt="">', array( 'post:12@c:html?p' ), '<img src="https://www.live.test/a.jpg" srcset="https://www.live.test/?p=120 300w, https://www.live.test/a.jpg 1024w" alt="">' ),
			'a candidate of srcset of a source' => array( '<picture><source srcset="/?p=12 1x,/?page_id=13 2x" type="image/webp"><img src="/?p=12" alt=""></picture>', array( 'post:12@c:html?p', 'post:13@c:html?page_id', 'post:12@c:html?p' ), '<picture><source srcset="/?p=120 1x,/?page_id=130 2x" type="image/webp"><img src="/?p=120" alt=""></picture>' ),
			'a query value as it is' => array( '<p><a href="/?x=/?p=13">x</a></p>', array( 'post:13@c:html?x?p' ), '<p><a href="/?x=/?p=130">x</a></p>' ),
			'a query value as urlencode() writes it' => array( 'Siehe https://staging.test/?x=1&u=%2F%3Fcat%3D5%2C6%26q%3Da+b', array( 'term:5@c:html?u?cat', 'term:6@c:html?u?cat' ), 'Siehe https://www.live.test/?x=1&u=%2F%3Fcat%3D50%2C60%26q%3Da+b' ),
			'a tilde as urlencode() writes it' => array( 'Siehe https://staging.test/?u=%2F%3Fcat%3D5%2C6%23%7E', array( 'term:5@c:html?u?cat', 'term:6@c:html?u?cat' ), 'Siehe https://www.live.test/?u=%2F%3Fcat%3D50%2C60%23%7E' ),
			'a tilde as rawurlencode() writes it' => array( 'Siehe https://staging.test/?u=%2F%3Fcat%3D5%2C6%23~', array( 'term:5@c:html?u?cat', 'term:6@c:html?u?cat' ), 'Siehe https://www.live.test/?u=%2F%3Fcat%3D50%2C60%23~' ),
			'a query value in a query value' => array( '<p><a href="/?a=%2F%3Fb%3D%252F%253Fp%253D12">x</a></p>', array( 'post:12@c:html?a?b?p' ), '<p><a href="/?a=%2F%3Fb%3D%252F%253Fp%253D120">x</a></p>' ),
			// Codex, round 22 (B-2): each string value by itself, in either spelling.
			'the same address in both spellings in the context' => array( '<div data-wp-context=\'{"a":"/?p=12","b":"\\/?p=12"}\'>x</div>', array( 'post:12@c:html:json.a?p', 'post:12@c:html:json.b?p' ), '<div data-wp-context=\'{"a":"/?p=120","b":"\\/?p=120"}\'>x</div>' ),
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
			'a JSON-LD with a space after the address' => array( '<script type="application/ld+json">{ "@type": "Article", "url": "/?p=12 " }</script>', 'c:html:json' ),
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
			'a key twice in the context' => array( '<div data-wp-context=\'{"url":"/?p=12","url":"/?p=13"}\'>x</div>', 'c:html:json' ),
			'a context that is no JSON' => array( '<div data-wp-context=\'{"url":"/?p=12"\'>x</div>', 'c:html:json' ),
			'a context with a namespace that is no JSON' => array( '<div data-wp-context=\'acme::{"url":"/?p=12"\'>x</div>', 'c:html:json' ),
			// Codex, round 17 (A4), 18 (B3): data: that is no image, or does
			// not decode (an SVG with an address in it: the net, netReferences()).
			'data in a style attribute'  => array( '<p style="background:url(DATA:text/css,x)">x</p>', 'c:html:css' ),
			'data of another type'       => array( '<p style="background:url(data:text/html,x)">x</p>', 'c:html:css' ),
			'data that does not decode'  => array( '<p style="background:url(data:image/png;base64,!!)">x</p>', 'c:html:css' ),
			// Codex, round 17 (6): a script of JSON that is no JSON.
			'JSON-LD that is no JSON' => array( '<script type="application/ld+json">{"url": /?p=12}</script>', 'c:html:json' ),
			// Codex, round 22 (A-1): a query value in an encoding no function
			// writes (lower case, a mix), relative to the page.
			'redirect_to in lower case' => array( '<p><a href="https://staging.test/wp-login.php?redirect_to=%2f%3fp%3d12">x</a></p>', 'c:html?redirect_to' ),
			'redirect_to mixed' => array( '<p><a href="/?redirect_to=%2F?p%3D12">x</a></p>', 'c:html?redirect_to' ),
			'redirect_to relative to the page' => array( '<p><a href="/?redirect_to=index.php%3Fp%3D12">x</a></p>', 'c:html?redirect_to?p' ),
			'redirect_to in a link relative to the page' => array( '<p><a href="wp-login.php?redirect_to=%2F%3Fp%3D12">x</a></p>', 'c:html?redirect_to' ),
			// Codex, round 23 (B-2): a "," inside a candidate of srcset is part of its address (as the browser reads it).
			'a comma inside a candidate of srcset' => array( '<img src="a.jpg" srcset="/?p=12x,/?page_id=13 2x" alt="">', 'c:html?p' ),
			// Codex, round 23 (A-5): userinfo before the host, of a site or not.
			'userinfo before the host' => array( '<p><a href="https://viewer@staging.test/?p=12">x</a></p>', 'c:html:userinfo' ),
			'userinfo with a password in prose' => array( 'Siehe https://viewer:pw@staging.test/?p=12', 'c:html:userinfo' ),
			'userinfo before a host of no site' => array( 'Siehe https://viewer@other.test/?p=12', 'c:html:userinfo' ),
			'userinfo in a share link' => array( 'Teilen: https://twitter.com/intent/tweet?url=https%3A%2F%2Fviewer%40staging.test%2F%3Fp%3D12', 'c:html?url:userinfo' ),
			// Codex, round 24 (A-5): a target relative to the root or the page under a host of no site names its objects.
			'a root-relative value in a link of another site' => array( 'Siehe //other.test/?u=%2F%3Fp%3D12 dort.', 'c:html?u' ),
			'a login of another site with a relative target' => array( '<p><a href="https://other.test/wp-login.php?redirect_to=%2F%3Fp%3D12">Login</a></p>', 'c:html?redirect_to' ),
			'a login of another site with a target relative to the page' => array( '<p><a href="https://other.test/wp-login.php?redirect_to=index.php%3Fp%3D12">Login</a></p>', 'c:html?redirect_to' ),
			'a share text of another site with a relative address' => array( 'Teilen: https://twitter.com/intent/tweet?text=Lesen%3A%20%2F%3Fp%3D12%20auf%20https%3A%2F%2Fstaging.test', 'c:html?text' ),
			// Codex, round 24 (A-6): a share text encoded twice.
			'a share text encoded twice' => array( '<p><a href="mailto:?body=Lesen%253A%2520https%253A%252F%252Fstaging.test%252Fkontakt%252F">Mail</a></p>', 'c:html?body' ),
			// Codex, round 24 (A-3): userinfo of any spelling, without a digit.
			'userinfo with an underscore and a password in prose' => array( 'Adresse: https://_user:demo-secret@staging.test/', 'c:html:userinfo' ),
			'a password alone before the host' => array( '<p>https://:demo-secret@staging.test/</p>', 'c:html:userinfo' ),
			// Codex, round 24 (A-4): the place of an address in a node with references must be the one.
			'an address of references and the same one of another site' => array( '<p>&#47;&#63;p&#61;12 https://other.test/?p=12</p>', 'c:html?p' ),
			'an address of named references and the same one of another site' => array( '<p>&sol;&quest;p&equals;12 https://other.test/?p=12</p>', 'c:html?p' ),
			'the same address twice in a node with a reference' => array( '<p>Preis &lt; 5: /?p=12, dann /?p=12</p>', 'c:html?p' ),
			'an address of references found inside a word' => array( '<p>&#47;?p=12 siehe x/?p=12</p>', 'c:html?p' ),
			'an address of references found inside the query of another address' => array( '<p>&#47;&#63;p&#61;12 https://other.test/?u=/?p=12</p>', 'c:html?p' ),
			// Codex, round 23 (A-1): a share link in JSON no producer writes.
			'a share link in JSON of no producer' => array( '{ "url": "https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12" }', 'c:html:json' ),
			// Codex, round 23 (A-6): "%" in the host, after the host of a site.
			'an escape after the host' => array( '<p><a href="https://staging.test%2Eother.test/?p=12">x</a></p>', 'c:html:host' ),
			'an escaped slash after the host in prose' => array( 'Siehe https://staging.test%2F?p=12', 'c:html:host' ),
			'an escape in a host without a scheme' => array( 'Siehe //staging.test%2eother.test/?p=12', 'c:html:host' ),
			// Codex, round 23 (B-1): an address that stands in the node only decoded.
			'an address of references only in a text node' => array( '<p>&#47;?p=12</p>', 'c:html?p' ),
			'an address with a reference for its digit in a text node' => array( '<p>/?p=1&#50; dort</p>', 'c:html?p' ),
			// Codex, round 22 (B-2): JSON no producer writes so, value by value;
			// what is no address by itself, or escaped, locks.
			'a key twice with an address' => array( '{"url":"https://staging.test/?p=12","url":"x"}', 'c:html:json' ),
			'a root address dropped' => array( '{"src":"/?p=12","src":"x"}', 'c:html:json' ),
			'a query relative to the page in JSON' => array( '{"x":"?p=12","x":1}', 'c:html:json' ),
			'an escape in the address of a value' => array( '<a data-options=\'{ "src": "\u002f?p=12" }\'>x</a>', 'c:html:json' ),
			'a mix of escaped slashes in a value' => array( '<a data-options=\'{ "src": "\/blog/?p=12" }\'>x</a>', 'c:html:json' ),
			'an address as part of a longer value' => array( '<a data-options=\'{ "caption": "Mehr: https://staging.test/?p=12" }\'>x</a>', 'c:html:json' ),
			// The context not stored as it is read (a reference for a digit): read only, an id locks.
			'the context with a reference for a digit' => array( '<div data-wp-context=\'{"url":"\\/?p=1&#50;"}\'>x</div>', 'c:html:json' ),
			// Codex, round 22 (A-1): deeper than the depth, a value is not read.
			'a query value three deep' => array( '<p><a href="https://staging.test/?a=' . rawurlencode( '/?b=' . rawurlencode( '/?c=' . rawurlencode( '/?p=12' ) ) ) . '">x</a></p>', 'c:html?a?b?c?' ),
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
			'prose without a host'     => array( 'Siehe ?p=12 und index.php?p=12 im Text.' ),
			'no address attribute'     => array( '<p><a data-href="/?p=12">x</a></p>' ),
			'another port'             => array( 'Siehe https://staging.test:8443/?p=12 und http://staging.test:443/?p=12' ),
			'a longer host'            => array( 'Siehe https://staging.test.evil.com/?p=12' ),
			'a variable that is no id' => array( '<p><a href="https://staging.test/?paged=2">y</a> https://staging.test/?pp=12</p>' ),
			// JavaScript is no address: the unit locks for it (*:script).
			'an event attribute'     => array( "<p><button onclick=\"location.href='https://staging.test/?p=12'\">x</button></p>" ),
			'a fragment in a block attribute' => array( '<!-- wp:acme/x {"url":"#?p=12"} /-->' ),
			'an escaped shortcode with a host of no site' => array( '[[acme_button url="https://cdn.example.org/?p=12"]]' ),
			// Codex, round 22 (A-1): a query value that is no address of the sites.
			'a query value of another host' => array( '<p><a href="/?redirect_to=https%3A%2F%2Fother.test%2F%3Fp%3D12">x</a></p>' ),
			'a query value of a file' => array( '<p><a href="/?src=%2Fwp-content%2Fuploads%2Fa.jpg">x</a></p>' ),
			// Codex, round 23 (A-1): a share link without an address of the sites.
			'a share link of another site' => array( 'Teilen: https://twitter.com/intent/tweet?url=https%3A%2F%2Fother.test%2F%3Fp%3D12' ),
			'a share link with a text only' => array( 'Teilen: https://twitter.com/intent/tweet?text=Beitrag+12' ),
			// Codex, round 24 (A-3): "@" of a mailto is no userinfo (no "//").
			'a mailto with a name'     => array( '<p><a href="mailto:info@staging.test?subject=Beitrag%2012">Mail</a> an info@staging.test</p>' ),
			'a data: SVG with a bracket in a text node' => array( '<p>Beispiel: data:image/svg+x(ml;base64,AAAA</p>' ),
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
			// Codex, round 17 (A5): JSON json_decode() does not read. (A data:
			// address of CSS is read by the net, netCredentials(); one that is
			// no image or does not decode locks the addresses, unsafeAddresses().)
			'JSON deeper than it is read' => array( "<div data-config='" . str_repeat( '[', 513 ) . '{"name":"api_key","value":"demo-secret"}' . str_repeat( ']', 513 ) . "'></div>" ),
			'a context not finished' => array( "<div data-wp-context='{\"name\":\"api_key\",\"value\":\"x\"'></div>" ),
			'a title deeper than it is read' => array( '<p title="' . str_repeat( '[', 513 ) . str_repeat( ']', 513 ) . '">x</p>' ),
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
		// Codex, round 22 (B-2): the ids of the string values as markers, in
		// their order; a user by its login.
		$acc  = AB_MCP_Sync_Canon::acc();
		$form = AB_MCP_Sync_Canon::value( "{\n  \"u\": \"https://staging.test/?p=12\",\n  \"a\": \"/?author=1\"\n}", self::reads_ctx(), $acc, 'meta', 'm' );
		self::assertSame( array( '~s' => array( array( '~s' => array( "{\n  \"u\": \"", AB_MCP_Sync_Canon::address_cuts( 'https://staging.test', self::reads_ctx() )[0]['marker'], '/?p=', AB_MCP_Sync_Canon::ref( 'post', 12 ), "\",\n  \"a\": \"/?author=", array( '~ref' => 'user', 'login' => 'user1' ), "\"\n}" ) ) ) ), $form );
		self::assertSame( array( 'post:12', 'user:1' ), array_map( static fn( $r ) => $r['space'] . ':' . $r['id'], $acc['refs'] ), 'Each once.' );
		self::assertSame( "{\n  \"u\": \"https://www.live.test/?p=120\",\n  \"a\": \"/?author=10\"\n}", AB_MCP_Sync_Refs::rewrite_value( "{\n  \"u\": \"https://staging.test/?p=12\",\n  \"a\": \"/?author=1\"\n}", $plan, $changed ), 'Rewritten where they stand.' );
		self::assertSame( "{\n  \"u\": \"\\/?p=120\"\n}", AB_MCP_Sync_Refs::rewrite_value( "{\n  \"u\": \"\\/?p=12\"\n}", $plan, $changed ), 'With \\/ as well.' );
		// A key that looks like an address is none: not read, not rewritten.
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( "{\n  \"/?p=12\": \"x\"\n}", self::reads_ctx(), $acc, 'meta', 'm' );
		self::assertSame( array( array(), array() ), array( $acc['refs'], $acc['spelling'] ) );
		self::assertSame( "{\n  \"/?p=12\": \"x\"\n}", AB_MCP_Sync_Refs::rewrite_value( "{\n  \"/?p=12\": \"x\"\n}", $plan, $changed ) );
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
		// Codex, round 22 (B-2): a string value that is an address by itself
		// is rewritten where it stands.
		foreach ( array( $ld( 'https://staging.test/?p=12' ), $ld( '/?p=12' ) ) as $content ) {
			list( $refs, $acc ) = self::reads_scan( $content );
			self::assertSame( array( array( 'post:12@c:html:json.url?p' ), array() ), array( $refs, $acc['spelling'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
			self::assertSame( array( str_replace( array( 'staging.test', '=12' ), array( 'www.live.test', '=120' ), $content ), array() ), array( $out['content'], $out['missing'] ), $content );
		}
		// A user by its login; a key that looks like an address is none.
		list( $refs, $acc ) = self::reads_scan( $ld( '/?author=1' ) );
		self::assertSame( array( array( 'user:user1@c:html:json.url?author' ), array() ), array( $refs, $acc['spelling'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $ld( '/?author=1' ), self::reads_plan() );
		self::assertSame( array( $ld( '/?author=10' ), array() ), array( $out['content'], $out['missing'] ) );
		list( $refs, $acc ) = self::reads_scan( $ld( 'x', '/?p=12' ) );
		self::assertSame( array( array(), array() ), array( $refs, $acc['spelling'] ) );
		self::assertSame( $ld( 'x', '/?p=12' ), AB_MCP_Sync_Refs::rewrite_content( $ld( 'x', '/?p=12' ), self::reads_plan() )['content'] );
		foreach ( array(
			$ld( 'https://staging\\u002etest/ueber-uns/' ),
			$ld( 'https://staging.test/ueber-uns/', 'https://staging.test/ueber-uns/' ),
			$ld( '?p=12' ),
		) as $content ) {
			list( $refs, $acc ) = self::reads_scan( $content );
			self::assertSame( array( array(), array( 'c:html:json' ) ), array( $refs, $acc['spelling'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::reads_plan() );
			self::assertSame( array( $content, array( 'spelling' ) ), array( $out['content'], array_column( $out['missing'], 'space' ) ), $content );
		}
		// Stored as esc_attr() writes it in an attribute: read, written back so.
		list( , $acc ) = self::reads_scan( '<div data-wp-context="{ &quot;logo&quot;: &quot;https://staging.test/logo.png&quot; }">x</div>' );
		self::assertSame( array(), $acc['spelling'] );
		self::assertSame( '<div data-wp-context="{ &quot;logo&quot;: &quot;https://www.live.test/logo.png&quot; }">x</div>', AB_MCP_Sync_Refs::rewrite_content( '<div data-wp-context="{ &quot;logo&quot;: &quot;https://staging.test/logo.png&quot; }">x</div>', self::reads_plan() )['content'] );
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

	public function testAnIdInsideAQueryValueNotInTheMapIsMissing(): void {
		// Codex, round 23 (A-1): what the round trip of a value cannot map is
		// reported as the value itself would be.
		$plan = self::reads_plan();
		$out  = AB_MCP_Sync_Refs::rewrite_content( 'https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fp%3D99', $plan );
		self::assertSame( array( array( 'space' => 'post', 'id' => 99 ) ), $out['missing'] );
		self::assertSame( 'https://twitter.com/intent/tweet?url=https%3A%2F%2Fwww.live.test%2F%3Fp%3D99', $out['content'], 'The host rewritten, the id kept.' );
	}

	public function testAShortLinkAfterAnAmpersandAsSerializeBlockAttributesWritesIt(): void {
		// The slashes as they are, "&" as \u0026 and "--" as \u002d\u002d:
		// the spelling of serialize_block_attributes() only
		// (AB_MCP_Sync_Json::encode()); no other producer writes "--" so, and
		// no reading value by value takes the escapes.
		$json    = '{"url":"/?p=12\u0026x=a\u002d\u002db"}';
		$acc     = AB_MCP_Sync_Canon::acc();
		$changed = false;
		AB_MCP_Sync_Canon::value( $json, self::reads_ctx(), $acc, 'post', 'meta:_json' );
		$plan = self::reads_plan();
		$out  = AB_MCP_Sync_Refs::rewrite_value( $json, $plan, $changed );
		if ( ! self::core() ) {
			self::assertSame( array( array(), array( 'meta:_json:json.url?' ) ), array( $acc['refs'], $acc['spelling'] ), 'Without WP_HTML_Decoder: locked, as the producer reads it.' );
			return;
		}
		self::assertSame( array( array( 'meta:_json:json.url?p' ), array() ), array( array_column( $acc['refs'], 'at' ), $acc['spelling'] ) );
		self::assertSame( '{"url":"/?p=120\u0026x=a\u002d\u002db"}', $out );
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
