<?php
/**
 * References with a schema and possible references (AB_MCP_Sync_Refs, Z8
 * decision 12), one case per schema: the attributes of the core blocks
 * and the core shortcodes (the HTML their save writes from an id is read
 * with the HTML API of the core: tests/integration/SyncHtmlRefsTest.php); whole numbers
 * without a schema noted, plain numbers of the core not; the lock of a
 * possible reference in both ID spaces; the rewriter for every schema, the
 * addresses included, and nothing changed where nothing is to change.
 * Against WordPress 7.1.3 in tests/integration (SyncSchemasTest,
 * SyncCanonTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Credentials;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-refs.php';
require_once __DIR__ . '/../includes/sync/class-sync-option-schema.php';

final class SyncRefsTest extends TestCase {

	private static function ctx(): array {
		return AB_MCP_Sync_Canon::context(
			array( 'https://staging.test' ),
			static function ( int $id ) {
				return 'user' . $id;
			}
		);
	}

	/** The references and possible references content has. */
	private static function scan( string $content ): array {
		$acc  = AB_MCP_Sync_Canon::acc();
		$form = AB_MCP_Sync_Refs::content( $content, self::ctx(), $acc, 'post', 'c' );
		$refs = array();
		foreach ( $acc['refs'] as $ref ) {
			$refs[] = $ref['space'] . ':' . ( 'user' === $ref['space'] ? $ref['login'] : $ref['id'] ) . '@' . $ref['at'];
		}
		$possible = array();
		foreach ( $acc['possible'] as $p ) {
			$possible[] = $p['value'] . '@' . $p['at'];
		}
		return array( $refs, $possible, $form, $acc );
	}

	/* ------------------------------------------------------ the schemas */

	public static function schemas(): array {
		return array(
			'core/image id'                => array( '<!-- wp:image {"id":12} --><figure><img/></figure><!-- /wp:image -->', array( 'post:12@c:core/image.id' ) ),
			'core/gallery ids'             => array( '<!-- wp:gallery {"ids":[12,13]} --><figure><img/></figure><!-- /wp:gallery -->', array( 'post:12@c:core/gallery.ids.0', 'post:13@c:core/gallery.ids.1' ) ),
			'core/cover'                   => array( '<!-- wp:cover {"id":7} --><div><img/></div><!-- /wp:cover -->', array( 'post:7@c:core/cover.id' ) ),
			'core/media-text'              => array( '<!-- wp:media-text {"mediaId":8} --><div><img/></div><!-- /wp:media-text -->', array( 'post:8@c:core/media-text.mediaId' ) ),
			'core/file'                    => array( '<!-- wp:file {"id":9} --><div></div><!-- /wp:file -->', array( 'post:9@c:core/file.id' ) ),
			'core/video'                   => array( '<!-- wp:video {"id":10} --><figure></figure><!-- /wp:video -->', array( 'post:10@c:core/video.id' ) ),
			'core/audio'                   => array( '<!-- wp:audio {"id":11} --><figure></figure><!-- /wp:audio -->', array( 'post:11@c:core/audio.id' ) ),
			'core/block'                   => array( '<!-- wp:block {"ref":20} /-->', array( 'post:20@c:core/block.ref' ) ),
			'core/navigation'              => array( '<!-- wp:navigation {"ref":21} /-->', array( 'post:21@c:core/navigation.ref' ) ),
			'navigation-link post-type'    => array( '<!-- wp:navigation-link {"id":22,"kind":"post-type","type":"page"} /-->', array( 'post:22@c:core/navigation-link.id' ) ),
			'navigation-submenu taxonomy'  => array( '<!-- wp:navigation-submenu {"id":4,"kind":"taxonomy","type":"category"} /-->', array( 'term:4@c:core/navigation-submenu.id' ) ),
			'core/page-list'               => array( '<!-- wp:page-list {"parentPageID":23} /-->', array( 'post:23@c:core/page-list.parentPageID' ) ),
			'core/query author text'       => array( '<!-- wp:query {"query":{"author":"1,2"}} --><div></div><!-- /wp:query -->', array( 'user:user1@c:core/query.query.author', 'user:user2@c:core/query.query.author' ) ),
			'core/query author list'       => array( '<!-- wp:query {"query":{"author":[3]}} --><div></div><!-- /wp:query -->', array( 'user:user3@c:core/query.query.author.0' ) ),
			'core/query taxQuery old'      => array( '<!-- wp:query {"query":{"taxQuery":{"category":[5],"post_tag":[6]}}} --><div></div><!-- /wp:query -->', array( 'term:5@c:core/query.query.taxQuery.category.0', 'term:6@c:core/query.query.taxQuery.post_tag.0' ) ),
			'core/query taxQuery new'      => array( '<!-- wp:query {"query":{"taxQuery":{"include":{"category":[5]},"exclude":{"post_tag":[6]}}}} --><div></div><!-- /wp:query -->', array( 'term:5@c:core/query.query.taxQuery.include.category.0', 'term:6@c:core/query.query.taxQuery.exclude.post_tag.0' ) ),
			'core/query exclude, parents'  => array( '<!-- wp:query {"query":{"exclude":[30],"parents":[31],"perPage":3,"offset":2}} --><div></div><!-- /wp:query -->', array( 'post:30@c:core/query.query.exclude.0', 'post:31@c:core/query.query.parents.0' ) ),
			'core/latest-posts'            => array( '<!-- wp:latest-posts {"selectedAuthor":2,"categories":[{"id":5,"value":"Kat"}],"postsToShow":4} /-->', array( 'user:user2@c:core/latest-posts.selectedAuthor', 'term:5@c:core/latest-posts.categories.0.id' ) ),
			'shortcode gallery'            => array( '<p>[gallery ids="12, 13"]</p>', array( 'post:12@c:html:shortcode:gallery.ids', 'post:13@c:html:shortcode:gallery.ids' ) ),
			'shortcode playlist'           => array( '[playlist ids=\'14\']', array( 'post:14@c:html:shortcode:playlist.ids' ) ),
			'shortcode caption'            => array( '[caption id="attachment_15" width="300"]<img/> Text[/caption]', array( 'post:15@c:html:shortcode:caption.id' ) ),
			// Codex, round 7: gallery_shortcode() reads include, exclude and id as well.
			'shortcode gallery include'    => array( '[gallery include="12 13"]', array( 'post:12@c:html:shortcode:gallery.include', 'post:13@c:html:shortcode:gallery.include' ) ),
			'shortcode gallery exclude, id' => array( '[gallery id="49" exclude="52"]', array( 'post:49@c:html:shortcode:gallery.id', 'post:52@c:html:shortcode:gallery.exclude' ) ),
			'shortcode playlist include, id' => array( '[playlist id="7" include="12,13" exclude="14"]', array( 'post:7@c:html:shortcode:playlist.id', 'post:12@c:html:shortcode:playlist.include', 'post:13@c:html:shortcode:playlist.include', 'post:14@c:html:shortcode:playlist.exclude' ) ),
			// Codex, round 7: render_block_core_avatar() reads a user.
			'core/avatar userId'           => array( '<!-- wp:avatar {"userId":45,"isLink":true} /-->', array( 'user:user45@c:core/avatar.userId' ) ),
		);
	}

	#[DataProvider( 'schemas' )]
	public function testEachSchemaGivesItsReferences( string $content, array $want ): void {
		list( $refs, $possible ) = self::scan( $content );
		sort( $refs );
		sort( $want );
		self::assertSame( $want, $refs );
		self::assertSame( array(), $possible, 'Nothing else is a possible reference.' );
	}

	public function testTheFormHoldsTheMarkersNotTheIds(): void {
		list( , , $form ) = self::scan( '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure><img src="https://staging.test/a.jpg"/> [gallery ids="12"]</figure><!-- /wp:image -->' );
		$block = $form['~blocks'][0];
		self::assertSame( 'core/image', $block['b'] );
		self::assertSame( array( '~ref' => 'post', 'id' => 12 ), $block['a']['id'] );
		self::assertSame( 'large', $block['a']['sizeSlug'] );
		self::assertSame( array( '~s' => array( '<figure><img src="', array( '~addr' => 'plain' ), '/a.jpg"/> [gallery ids="', array( '~ref' => 'post', 'id' => 12 ), '"]</figure>' ) ), $block['c'][0] );
		// A text of exactly the digits is an id as well, and keeps that it was text.
		list( , , $text, $acc ) = self::scan( '<!-- wp:gallery {"ids":["13"]} --><figure></figure><!-- /wp:gallery -->' );
		self::assertSame( array( '~ref' => 'post', 'id' => 13, 'text' => true ), $text['~blocks'][0]['a']['ids'][0] );
		list( , , $author ) = self::scan( '<!-- wp:latest-posts {"selectedAuthor":"2"} /-->' );
		self::assertSame( array( '~ref' => 'user', 'login' => 'user2', 'text' => true ), $author['~blocks'][0]['a']['selectedAuthor'] );
		self::assertSame( array(), AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc )['locks'] );
	}

	/* ------------------------------------------- possible references */

	public static function possibles(): array {
		return array(
			'a block of a plugin'          => array( '<!-- wp:acme/box {"post":40,"n":"41","list":"42,43","label":"Seite 2","ratio":1.5} /-->', array( '40@c:acme/box.post', '41@c:acme/box.n', '42@c:acme/box.list', '43@c:acme/box.list' ) ),
			'nested in a plugin block'     => array( '<!-- wp:acme/box {"items":[{"id":44}]} /-->', array( '44@c:acme/box.items.0.id' ) ),
			'an unmapped core id'          => array( '<!-- wp:page-list-item {"id":45} /-->', array( '45@c:core/page-list-item.id' ) ),
			'a core attribute of no list'  => array( '<!-- wp:image {"id":12,"acmeLink":46} --><figure></figure><!-- /wp:image -->', array( '46@c:core/image.acmeLink' ) ),
			'a navigation link of custom'  => array( '<!-- wp:navigation-link {"id":47,"kind":"custom"} /-->', array( '47@c:core/navigation-link.id' ) ),
			'another shortcode'            => array( '[contact-form-7 id="48" title="Kontakt"]', array( '48@c:html:shortcode:contact-form-7.id' ) ),
			'JSON in a plugin attribute'   => array( '<!-- wp:acme/box {"data":"{\\"post\\":52,\\"inner\\":\\"{\\\\\\"target\\\\\\":53}\\"}"} /-->', array( '52@c:acme/box.data.post', '53@c:acme/box.data.inner.target' ) ),
			// As stored: shortcode_parse_atts() takes one level of
			// backslashes (stripcslashes()), the JSON inside the next.
			'JSON in a shortcode attribute' => array( '[acme_box data=\'{"post":54,"inner":"{\\\\\\"target\\\\\\":55}"}\']', array( '54@c:html:shortcode:acme_box.data.post', '55@c:html:shortcode:acme_box.data.inner.target' ) ),
			'JSON escaped in a shortcode'  => array( '[acme_box data=\'{\\"post\\":56}\']', array( '56@c:html:shortcode:acme_box.data.post' ) ),
			'by position in a shortcode'   => array( '[acme_box \'{"post":57}\' 58]', array( '57@c:html:shortcode:acme_box.0.post', '58@c:html:shortcode:acme_box.1' ) ),
			'a core shortcode, other attr' => array( '[gallery link="49" columns="3" size="51"]', array( '49@c:html:shortcode:gallery.link', '51@c:html:shortcode:gallery.size' ) ),
			'a name with a dot'            => array( '[acme.box id="59"]', array( '59@c:html:shortcode:acme.box.id' ) ),
			'a name beyond ASCII'          => array( '[äbox id="60"]', array( '60@c:html:shortcode:äbox.id' ) ),
		);
	}

	#[DataProvider( 'possibles' )]
	public function testWholeNumbersWithoutASchemaArePossibleReferences( string $content, array $want ): void {
		list( , $possible ) = self::scan( $content );
		self::assertSame( $want, $possible );
	}

	public function testTheAttributesOfBlocksAndShortcodesAreFieldsOfTheCredentialCheck(): void {
		foreach ( array(
			'a released block library' => array( '<!-- wp:kadence/rowlayout {"uniqueID":"_a1","settings":{"api_key":"sk_live"}} --><div></div><!-- /wp:kadence/rowlayout -->', array( '[block:kadence/rowlayout].settings.api_key' ) ),
			'a core block'             => array( '<!-- wp:paragraph {"metadata":{"clientSecret":"x"}} --><p>x</p><!-- /wp:paragraph -->', array( '[block:core/paragraph].metadata.clientSecret' ) ),
			'JSON in an attribute'     => array( '<!-- wp:acme/box {"data":"{\\"access_token\\":\\"x\\"}"} /-->', array( '[block:acme/box].data.access_token' ) ),
			'an inner block'           => array( '<!-- wp:group --><div><!-- wp:acme/box {"password":"x"} /--></div><!-- /wp:group -->', array( '[block:acme/box].password' ) ),
			'a shortcode'              => array( '<p>[acme_form api_key="sk_live" title="T"]</p>', array( '[shortcode:acme_form].api_key' ) ),
			'a shortcode in a block'   => array( '<!-- wp:paragraph --><p>[acme_form token="x"]</p><!-- /wp:paragraph -->', array( '[shortcode:acme_form].token' ) ),
			'JSON by position'         => array( "[acme '{\"api_key\":\"probe\"}']", array( '[shortcode:acme].0.api_key' ) ),
			'JSON escaped as stored'   => array( '[acme data=\'{\\"api_key\\":\\"probe\\"}\']', array( '[shortcode:acme].data.api_key' ) ),
			'a nested shortcode'       => array( '[acme_box][acme_form secret="x"][/acme_box]', array( '[shortcode:acme_form].secret' ) ),
		) as $what => list( $content, $fields ) ) {
			self::assertSame( array( 'state' => 'mixed', 'fields' => $fields ), AB_MCP_Sync_Credentials::unit( '', $content, array() ), $what );
		}
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', '<!-- wp:kadence/rowlayout {"uniqueID":"_a1","hasPassword":true} --><div></div><!-- /wp:kadence/rowlayout -->', array() )['state'], 'A word about a credential is none.' );
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', 'a[href] { color: red } input[type="password"] { }', array() )['state'], 'CSS with brackets names no field.' );
	}

	public function testWhatTheCoreParsesAsABlockOrAShortcodeIsReadSo(): void {
		$parser = new \WP_Block_Parser();
		foreach ( array(
			"two spaces"   => array( '<!--  wp:paragraph {"api_key":"dummy-secret"} /-->', '[block:core/paragraph].api_key' ),
			"a tab"        => array( "<!--\twp:acme/box {\"token\":\"x\"} /-->", '[block:acme/box].token' ),
			"a line break" => array( "<!--\nwp:acme/box {\"password\":\"x\"}\n/-->", '[block:acme/box].password' ),
			"a dot"        => array( '[acme.form api_key="x"]', '[shortcode:acme.form].api_key' ),
			"an umlaut"    => array( '[äform secret="x"]', '[shortcode:äform].secret' ),
		) as $what => list( $text, $field ) ) {
			if ( 0 === strpos( $text, '<!--' ) ) {
				self::assertNotNull( $parser->parse( $text )[0]['blockName'], 'The core reads a block: ' . $what );
			}
			self::assertSame( array( 'state' => 'mixed', 'fields' => array( $field ) ), AB_MCP_Sync_Credentials::unit( '', $text, array() ), $what );
		}
		// Without a space the core reads no block, a comment: its keys are
		// read all the same, as text (flat()).
		self::assertNull( $parser->parse( '<!--wp:acme/box {"token":"x"} /-->' )[0]['blockName'] );
		self::assertSame( array( 'state' => 'mixed', 'fields' => array( '~token' ) ), AB_MCP_Sync_Credentials::unit( '', '<!--wp:acme/box {"token":"x"} /-->', array() ) );
	}

	public static function spellings(): array {
		return array(
			'octal escapes'       => array( '[gallery ids="\\61\\62"]', 'c:html:shortcode:gallery.ids' ),
			'a character ref'     => array( '[gallery ids="&#49;2"]', 'c:html:shortcode:gallery.ids' ),
			'the name twice'      => array( '[gallery ids="12" ids="13"]', 'c:html:shortcode:gallery.ids' ),
			'the same value twice' => array( '[gallery ids="12" IDS="12"]', 'c:html:shortcode:gallery.ids' ),
			'other quotes'        => array( '[gallery ids=”12”]', 'c:html:shortcode:gallery.ids' ),
			'an escaped caption'  => array( '[caption id="attachment_\\65"]Bild[/caption]', 'c:html:shortcode:caption.id' ),
			'inside [[…]]'        => array( '[[unregistered] [gallery ids="12"] [/unregistered]]', 'c:html:shortcode:gallery.ids' ),
			// Read by wp_parse_id_list() (absint()) as 12, written otherwise.
			'a leading zero'      => array( '[gallery ids="012,13"]', 'c:html:shortcode:gallery.ids' ),
			'a sign'              => array( '[playlist ids="-12"]', 'c:html:shortcode:playlist.ids' ),
			'a caption with a leading zero' => array( '[caption id="attachment_012"]Bild[/caption]', 'c:html:shortcode:caption.id' ),
			// Codex, round 7: (int) reads 10.
			'an exponent as the gallery id' => array( '[gallery id="1e1"]', 'c:html:shortcode:gallery.id' ),
			'a leading zero in exclude' => array( '[playlist exclude="012"]', 'c:html:shortcode:playlist.exclude' ),
		);
	}

	#[DataProvider( 'spellings' )]
	public function testAReferenceNotStoredInThePlainSpellingLocksAndIsNotRewritten( string $content, string $at ): void {
		list( $refs, , , $acc ) = self::scan( $content );
		self::assertSame( array(), $refs, 'No reference is read from the stored digits.' );
		self::assertSame( array( array( '*:reference_spelling', $at ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 112, 13 => 113, 5 => 105, 7 => 107 ) ), array() ) );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), 'The rewriter writes nothing.' );
	}

	public function testThePlainSpellingsOfTheCoreAreRewritten(): void {
		foreach ( array(
			'[gallery ids="12,13"]'  => '[gallery ids="112,113"]',
			"[gallery ids='12, 13']" => "[gallery ids='112, 113']",
			'[gallery ids=12,13]'    => '[gallery ids=112,113]',
			'[gallery IDS="12"]'     => '[gallery IDS="112"]',
			// wp_parse_id_list() splits at spaces as well.
			'[gallery ids="12 13"]'  => '[gallery ids="112 113"]',
			'[gallery include="12 13"]'  => '[gallery include="112 113"]',
			'[playlist include="12 13"]' => '[playlist include="112 113"]',
			'[gallery exclude="12,13" id="12"]' => '[gallery exclude="112,113" id="112"]',
		) as $from => $to ) {
			list( $refs, , , $acc ) = self::scan( $from );
			self::assertNotSame( array(), $refs, $from );
			self::assertSame( array(), $acc['locks'], $from );
			$out = AB_MCP_Sync_Refs::rewrite_content( $from, AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 112, 13 => 113 ) ), array() ) );
			self::assertSame( array( $to, array() ), array( $out['content'], $out['missing'] ), $from );
		}
	}

	public static function blockNumbers(): array {
		return array(
			// The content, where it locks, the same in the spelling of the id.
			'a float'              => array( '<!-- wp:block {"ref":12.0} /-->', 'c:core/block.ref', '<!-- wp:block {"ref":12} /-->' ),
			'an exponent'          => array( '<!-- wp:block {"ref":1.2e1} /-->', 'c:core/block.ref', '<!-- wp:block {"ref":12} /-->' ),
			'true'                 => array( '<!-- wp:block {"ref":true} /-->', 'c:core/block.ref', '<!-- wp:block {"ref":12} /-->' ),
			'a leading zero'       => array( '<!-- wp:image {"id":"012"} --><figure></figure><!-- /wp:image -->', 'c:core/image.id', '<!-- wp:image {"id":"12"} --><figure></figure><!-- /wp:image -->' ),
			'a sign'               => array( '<!-- wp:image {"id":"+12"} --><figure></figure><!-- /wp:image -->', 'c:core/image.id', '<!-- wp:image {"id":"12"} --><figure></figure><!-- /wp:image -->' ),
			'a space'              => array( '<!-- wp:gallery {"ids":[12," 13"]} --><figure></figure><!-- /wp:gallery -->', 'c:core/gallery.ids.1', '<!-- wp:gallery {"ids":[12,"13"]} --><figure></figure><!-- /wp:gallery -->' ),
			'a float author'       => array( '<!-- wp:latest-posts {"selectedAuthor":3.0} /-->', 'c:core/latest-posts.selectedAuthor', '<!-- wp:latest-posts {"selectedAuthor":3} /-->' ),
			'a float avatar'       => array( '<!-- wp:avatar {"userId":45.0} /-->', 'c:core/avatar.userId', '<!-- wp:avatar {"userId":45} /-->' ),
			'a float term'         => array( '<!-- wp:query {"query":{"taxQuery":{"category":[4.0]}}} /-->', 'c:core/query.query.taxQuery.category.0', '<!-- wp:query {"query":{"taxQuery":{"category":[4]}}} /-->' ),
			// Codex, round 6: intval(), then absint() in WP_Query, reads 12.
			'a negative exclude'   => array( '<!-- wp:query {"query":{"inherit":false,"exclude":[-12]}} --><div></div><!-- /wp:query -->', 'c:core/query.query.exclude.0', '<!-- wp:query {"query":{"inherit":false,"exclude":[12]}} --><div></div><!-- /wp:query -->' ),
			'a negative parent'    => array( '<!-- wp:query {"query":{"inherit":false,"parents":[-12]}} --><div></div><!-- /wp:query -->', 'c:core/query.query.parents.0', '<!-- wp:query {"query":{"inherit":false,"parents":[12]}} --><div></div><!-- /wp:query -->' ),
			// Codex, round 6: intval( "1e1" ) is 10; explode( "," ) keeps the space.
			'an exponent author'   => array( '<!-- wp:query {"query":{"inherit":false,"author":"1e1"}} --><div></div><!-- /wp:query -->', 'c:core/query.query.author', '<!-- wp:query {"query":{"inherit":false,"author":"10"}} --><div></div><!-- /wp:query -->' ),
			'a space in an author list' => array( '<!-- wp:query {"query":{"inherit":false,"author":"1, 2"}} --><div></div><!-- /wp:query -->', 'c:core/query.query.author', '<!-- wp:query {"query":{"inherit":false,"author":"1,2"}} --><div></div><!-- /wp:query -->' ),
		);
	}

	#[DataProvider( 'blockNumbers' )]
	public function testAnIdOfABlockOnlyInItsSpellingOtherFormsLock( string $content, string $at, string $canonical ): void {
		list( $refs, , , $acc ) = self::scan( $content );
		self::assertNotContains( 'post:12@' . $at, $refs );
		$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
		self::assertContains( array( '*:reference_spelling', $at ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $result['locks'] ) );
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120, 13 => 130 ), 'term' => array( 4 => 40 ), 'user' => array( 1 => 2, 2 => 20, 3 => 30, 10 => 110, 45 => 450 ) ), array() );
		$out  = AB_MCP_Sync_Refs::rewrite_content( $content, $plan );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), 'The rewriter writes nothing.' );
		// In the spelling of the id it is a reference, rewritten.
		list( , , , $clean ) = self::scan( $canonical );
		self::assertSame( array(), AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $clean )['locks'], $canonical );
		$out = AB_MCP_Sync_Refs::rewrite_content( $canonical, $plan );
		self::assertSame( array( array(), true ), array( $out['missing'], $out['changed'] ), $canonical );
	}

	public function testWhatHoldsNoIdIsNoReference(): void {
		foreach ( array(
			'<!-- wp:block {"ref":0} /-->',
			'<!-- wp:block {"ref":-0.0} /-->',
			'<!-- wp:image {"id":"0"} --><figure></figure><!-- /wp:image -->',
			'<!-- wp:image {"id":""} --><figure></figure><!-- /wp:image -->',
			'<!-- wp:query {"query":{"author":""}} --><div></div><!-- /wp:query -->',
			// The caption's id names an attachment only after attachment_.
			'[caption id="figure-number-12"]Bild[/caption]',
		) as $content ) {
			list( $refs, , , $acc ) = self::scan( $content );
			self::assertSame( array( array(), array() ), array( $refs, $acc['locks'] ), $content );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() );
			self::assertSame( array( array(), false ), array( $out['missing'], $out['changed'] ), $content );
		}
	}

	public static function shortcodesAcrossBlocks(): array {
		return array(
			// Codex, round 6: do_blocks() makes [gallery ids="12"] of it.
			'the attribute in a block' => array( '[gallery <!-- wp:paragraph -->ids="12"<!-- /wp:paragraph -->]', 'c:shortcode@0' ),
			'the name split'           => array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->[<!-- wp:paragraph /-->gallery ids="12"]', 'c:shortcode@51' ),
			'a closer between'         => array( '<!-- wp:paragraph --><p>[gallery </p><!-- /wp:paragraph -->ids="12"]', 'c:shortcode@24' ),
			'a closing tag split'      => array( '[acme_box]Bild[/acme_<!-- wp:html /-->box]', 'c:shortcode@14' ),
		);
	}

	#[DataProvider( 'shortcodesAcrossBlocks' )]
	public function testAShortcodeAcrossBlockDelimitersLocks( string $content, string $at ): void {
		list( $refs, , , $acc ) = self::scan( $content );
		self::assertSame( array(), $refs, 'No text of a block holds the shortcode.' );
		self::assertSame( array( array( '*:reference_spelling', $at ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), 'The rewriter writes nothing.' );
		// Brackets closed before the delimiter: nothing locks.
		list( , , , $clean ) = self::scan( '<!-- wp:paragraph --><p>[gallery ids="12"] [x]</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>y</p><!-- /wp:paragraph -->' );
		self::assertSame( array(), $clean['locks'] );
	}

	public static function noUnitAcrossBlocks(): array {
		return array(
			// Codex, round 8: no tag, no shortcode begins there.
			'a less-than sign'         => array( '<!-- wp:html -->2 < 3<!-- /wp:html -->' ),
			'an open bracket'          => array( '<!-- wp:paragraph --><p>Tippe [ zum Öffnen.</p><!-- /wp:paragraph -->' ),
			'a tag never closed'       => array( '<!-- wp:html -->a <b<!-- /wp:html -->' ),
			'a bracket never closed'   => array( '<!-- wp:paragraph --><p>Liste [a</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->' ),
			'a less-than sign, later a greater-than sign' => array( '<!-- wp:html -->2 < 3<!-- /wp:html --><!-- wp:html --> und 4 > 1<!-- /wp:html -->' ),
			'an open bracket, later a closed one' => array( '<!-- wp:paragraph --><p>Tippe [ zum Öffnen.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>[x]</p><!-- /wp:paragraph -->' ),
		);
	}

	#[DataProvider( 'noUnitAcrossBlocks' )]
	public function testWhereNoTagOrShortcodeIsJoinedNothingLocks( string $content ): void {
		list( , , , $acc ) = self::scan( $content );
		self::assertSame( array(), $acc['locks'] );
		self::assertSame( array(), AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() )['missing'] );
	}

	public function testAShortcodeSchemaOnlyForExactlyItsName(): void {
		list( $refs, $possible, , $acc ) = self::scan( '[GALLERY ids="12"]' );
		self::assertSame( array(), $refs, 'A shortcode GALLERY of a plugin has no schema of the core.' );
		self::assertSame( array( '12@c:html:shortcode:GALLERY.ids' ), $possible, 'Its number is a possible reference.' );
		self::assertSame( array(), $acc['locks'] );
		$out = AB_MCP_Sync_Refs::rewrite_content( '[GALLERY ids="12"]', AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array() ) );
		self::assertSame( '[GALLERY ids="12"]', $out['content'], 'Not rewritten.' );
	}

	public static function flatKeys(): array {
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
			'capitals'              => array( 'PASSWORD = "x"' ),
			'JSON in a comment'     => array( '<!-- {"api_key":"x"} -->' ),
			'escaped JSON in a script' => array( '<script>var c = "{\\"api_key\\": 1}";</script>' ),
			'unicode in a comment'  => array( '<!-- {"\\u0061pi_key":"x"} -->' ),
			// Neither = nor : as stored, only decoded: each character of the
			// test before the decodings (flat()) lets the text through.
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
			// Codex, round 8: a field of a form with its value, a pair of name and value.
			'a field of a form'       => array( '<input type="hidden" name="api_key" value="EXAMPLE_SECRET">' ),
			'a field by its id'       => array( '<input id="token" value="x">' ),
			'a meta tag'              => array( '<meta name="client_secret" content="x">' ),
			// The text decoded as a whole loses the tag (&quot;): the name is decoded alone.
			'a name as a reference'   => array( '<input title="&quot;a&gt;b&quot;" name="api&#95;key" value="x">' ),
			// Codex, round 9: the whole value of a javascript: address is code.
			'a javascript: address with a string first' => array( '<a href="javascript:connect(\'service\',{apiKey:\'DEMO_SECRET\'})">Connect</a>' ),
			'a javascript: address in capitals, spaced' => array( '<a href="  JavaScript:x(1, {token: 1})">x</a>' ),
			'a javascript: address as references'       => array( '<a href="javascript&colon;x({secret&colon; 1})">x</a>' ),
			// Codex, round 9: the content of a textarea, the selected option of a select.
			'a textarea'              => array( '<textarea name="private_key">DEMO_PRIVATE_KEY</textarea>' ),
			'a select'                => array( '<select id="api_key"><option value="a">A</option><option value="b" selected>B</option></select>' ),
		);
	}

	#[DataProvider( 'flatKeys' )]
	public function testEveryKeyOfACredentialInAnySpellingOfTheTextLocks( string $text ): void {
		$check = AB_MCP_Sync_Credentials::unit( '', $text, array() );
		self::assertSame( 'mixed', $check['state'], json_encode( $check ) );
		self::assertNotSame( array(), $check['fields'] );
	}

	public function testTheFlatCheckFindsWhatTheStructureDoesNotRead(): void {
		// No block, no shortcode, no JSON as a whole: only flat() reads them.
		foreach ( array(
			'<!-- {"api_key":"x"} -->'                         => 'api_key',
			'<script>var c = "{\\"api_key\\": 1}";</script>' => 'api_key',
			'<!-- {"\\u0061pi_key":"x"} -->'                  => 'api_key',
			'/?api_key%3Dx'                                    => 'api_key',
			'/?api_key&#61;x'                                  => 'api_key',
			'<!-- api_key\\u003dx -->'                          => 'api_key',
			'[acme api_<!-- wp:paragraph /-->key="example-only"]' => 'api_key',
			'<script>window.service = {apiKey: "EXAMPLE_SECRET"};</script>' => 'apiKey',
			'<input type="hidden" name="api_key" value="EXAMPLE_SECRET">' => 'api_key',
		) as $text => $key ) {
			self::assertSame( array( 'state' => 'mixed', 'fields' => array( '~' . $key ) ), AB_MCP_Sync_Credentials::unit( '', $text, array() ), $text );
		}
	}

	public function testATextThatCannotBeJoinedIsUnreadable(): void {
		// Without the joined text (a PCRE error) nothing says what it holds.
		$jit   = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$check = AB_MCP_Sync_Credentials::unit( '', '<!-- wp:acme/box {"title":"' . str_repeat( 'x', 200 ) . '"} /--> a=1', array() );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $limit );
			ini_set( 'pcre.jit', (string) $jit );
		}
		self::assertSame( array( 'state' => 'unreadable', 'fields' => array(), 'why' => 'unreadable_value' ), $check );
	}

	public function testWordsAboutCredentialsAndTheirValuesAreNone(): void {
		foreach ( array(
			'<input type="password" name="pwd" class="token-field">',
			'<label for="password">Passwort:</label>',
			'{"hasPassword":true,"token_count":3}',
			'a[href] { color: red } input[type="password"] { }',
			'Das Passwort: geheim. Die Seite = gut.',
			// Prose: a name before ":" outside code, a field of a form without a value.
			'<p>Das password: geheim, der Token: 123.</p>',
			'[acme_note]Ihr secret: bleibt hier.[/acme_note]',
			'<input type="password" name="api_key" value="">',
			'<script src="app.js"></script><p>Kontakt: info</p>',
			// Codex, round 9: a label of a block, prose in a shortcode attribute.
			'<!-- wp:navigation-link {"label":"Password: reset","kind":"custom","url":"/help/"} /-->',
			'[acme_button label="Token: anfordern"]',
			'<textarea name="api_key"></textarea><select name="secret"><option value="a">A</option></select>',
		) as $text ) {
			self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( '', $text, array() )['state'], $text );
		}
	}

	public function testShortcodesNestedDeeperThanTheyAreReadLock(): void {
		$nest = static function ( int $n ): string {
			$open  = '';
			$close = '';
			for ( $i = 1; $i <= $n; $i++ ) {
				$open .= '[acme_' . $i . ']';
				$close = '[/acme_' . $i . ']' . $close;
			}
			return $open . 'x' . $close;
		};
		$text = $nest( 10 );
		list( , , , $acc ) = self::scan( $text );
		self::assertContains( '*:unreadable_value', array_column( $acc['locks'], 'key' ) );
		self::assertNotSame( array(), $acc['unread'], 'Not complete.' );
		$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
		self::assertFalse( $result['complete'] );
		self::assertSame( array( 'state' => 'unreadable', 'fields' => array(), 'why' => 'unreadable_value' ), AB_MCP_Sync_Credentials::unit( '', $text, array() ), 'The credential check is not read to its end either.' );
		list( , , , $acc ) = self::scan( $nest( 9 ) );
		self::assertSame( array(), $acc['locks'], 'Nine deep are read.' );
	}

	public function testJsonInAnAttributeDeeperThanItIsReadLocks(): void {
		// Objects: a "]" would end the shortcode.
		$json = '1';
		for ( $i = 0; $i < AB_MCP_Sync_Canon::MAX_DEPTH + 2; $i++ ) {
			$json = '{"a":' . $json . '}';
		}
		list( , $possible, , $acc ) = self::scan( "[acme_box data='" . $json . "']" );
		self::assertSame( array(), $possible );
		self::assertSame( array( '*:unreadable_value' ), array_column( $acc['locks'], 'key' ), 'The numbers below are not read: locked.' );
		self::assertFalse( AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc )['complete'] );
	}

	public function testNumbersInNestedJsonAndSerializedTextsArePossibleReferences(): void {
		$acc  = AB_MCP_Sync_Canon::acc();
		$leaf = AB_MCP_Sync_Refs::possible_leaf( $acc, 'meta:x' );
		AB_MCP_Sync_Canon::value( '{"outer":"{\\"target\\":12}"}', AB_MCP_Sync_Canon::context( array() ), $acc, 'post', 'meta:x', $leaf );
		AB_MCP_Sync_Canon::value( json_encode( json_encode( array( 'id' => 13 ) ) ), AB_MCP_Sync_Canon::context( array() ), $acc, 'post', 'meta:y', AB_MCP_Sync_Refs::possible_leaf( $acc, 'meta:y' ) );
		AB_MCP_Sync_Canon::value( serialize( array( 'json' => '[14,{"a":"15"}]' ) ), AB_MCP_Sync_Canon::context( array() ), $acc, 'post', 'meta:z', AB_MCP_Sync_Refs::possible_leaf( $acc, 'meta:z' ) );
		self::assertSame(
			array( 'meta:x.outer.target' => 12, 'meta:y.id' => 13, 'meta:z.json.0' => 14, 'meta:z.json.1.a' => 15 ),
			array_column( $acc['possible'], 'value', 'at' )
		);
	}

	public function testPlainNumbersOfTheCoreAndNumbersInTextAreNone(): void {
		foreach ( array(
			'[video width="640" height="360" mp4="a.mp4"][/video] [embed width="500" height="300"]https://example.test/v[/embed]',
			'<!-- wp:heading {"level":2} --><h2>Kapitel 12</h2><!-- /wp:heading -->',
			'<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"12"}}}} --><div></div><!-- /wp:columns -->',
			'<!-- wp:group {"layout":{"type":"grid","columnCount":3}} --><div></div><!-- /wp:group -->',
			'<!-- wp:query {"queryId":4,"query":{"perPage":10,"pages":0,"offset":1}} --><div></div><!-- /wp:query -->',
			'<!-- wp:latest-posts {"postsToShow":5,"excerptLength":55} /-->',
			'<p>Am 12. Mai 2026 um 14 Uhr, Raum 3.</p>',
			'[[gallery ids="50"]]',
		) as $content ) {
			list( $refs, $possible ) = self::scan( $content );
			self::assertSame( array(), $possible, $content );
			self::assertSame( array(), $refs, $content );
		}
	}

	public function testAPossibleReferenceLocksWhereItsNumberMovedInEitherIdSpace(): void {
		$result = array(
			'kind'     => 'post',
			'locks'    => array(),
			'possible' => array( array( 'value' => 40, 'at' => 'c:acme/box.post' ) ),
		);
		self::assertSame( array(), AB_MCP_Sync_Refs::lock_possible( $result, array( 'post' => array( 40 => 40 ) ) )['locks'], 'The same id on both sides.' );
		self::assertSame( array(), AB_MCP_Sync_Refs::lock_possible( $result, array( 'post' => array( 41 => 99 ) ) )['locks'], 'Another object moved.' );
		foreach ( array(
			'moved post'     => array( 'post' => array( 40 => 400 ) ),
			'new post'       => array( 'post' => array( 40 => null ) ),
			'moved term'     => array( 'term' => array( 40 => 4 ) ),
		) as $what => $map ) {
			$locked = AB_MCP_Sync_Refs::lock_possible( $result, $map );
			self::assertSame( array( '*:possible_reference' ), array_column( $locked['locks'], 'key' ), $what );
			self::assertSame( 'Z8d', $locked['locks'][0]['stage'] );
			self::assertSame( 'c:acme/box.post', $locked['locks'][0]['at'] );
		}
		self::assertSame( array( 1, 2 ), AB_MCP_Sync_Refs::numbers( ' 1, 2 ' ) );
		self::assertSame( array(), AB_MCP_Sync_Refs::numbers( '1.5' ) );
		self::assertSame( array(), AB_MCP_Sync_Refs::numbers( 0 ) );
		self::assertSame( array(), AB_MCP_Sync_Refs::numbers( true ) );
	}

	/* --------------------------------------------------------- rewrite */

	private static function plan(): array {
		return AB_MCP_Sync_Refs::plan(
			array(
				'post' => array( 12 => 120, 13 => 130, 20 => 200, 22 => 220, 30 => 300 ),
				'term' => array( 5 => 50, 6 => 60 ),
				'user' => array( 1 => 10, 2 => 20, 45 => 450 ),
			),
			array( 'https://staging.test' => 'https://www.live.test' )
		);
	}

	public static function rewrites(): array {
		return array(
			'image'        => array( '<!-- wp:image {"id":12} --><figure><img src="https://staging.test/a.jpg"/></figure><!-- /wp:image -->', '<!-- wp:image {"id":120} --><figure><img src="https://www.live.test/a.jpg"/></figure><!-- /wp:image -->' ),
			'gallery'      => array( '<!-- wp:gallery {"ids":[12,13]} --><figure><img/></figure><!-- /wp:gallery -->', '<!-- wp:gallery {"ids":[120,130]} --><figure><img/></figure><!-- /wp:gallery -->' ),
			'block'        => array( '<!-- wp:block {"ref":20} /-->', '<!-- wp:block {"ref":200} /-->' ),
			'nav link'     => array( '<!-- wp:navigation-link {"id":22,"kind":"post-type","url":"https://staging.test/x/"} /-->', '<!-- wp:navigation-link {"id":220,"kind":"post-type","url":"https://www.live.test/x/"} /-->' ),
			'query'        => array( '<!-- wp:query {"query":{"author":"1,2","taxQuery":{"include":{"category":[5]}},"exclude":[30]}} --><div></div><!-- /wp:query -->', '<!-- wp:query {"query":{"author":"10,20","taxQuery":{"include":{"category":[50]}},"exclude":[300]}} --><div></div><!-- /wp:query -->' ),
			'latest posts' => array( '<!-- wp:latest-posts {"selectedAuthor":2,"categories":[{"id":6,"value":"T"}]} /-->', '<!-- wp:latest-posts {"selectedAuthor":20,"categories":[{"id":60,"value":"T"}]} /-->' ),
			// Codex, round 7: the user of an avatar, as an author.
			'avatar'       => array( '<!-- wp:avatar {"userId":45,"isLink":true} /-->', '<!-- wp:avatar {"userId":450,"isLink":true} /-->' ),
			'avatar text'  => array( '<!-- wp:avatar {"userId":"45"} /-->', '<!-- wp:avatar {"userId":"450"} /-->' ),
			'shortcodes'   => array( '[caption id="attachment_12"]<img/>[/caption] [gallery ids="12,13"]', '[caption id="attachment_120"]<img/>[/caption] [gallery ids="120,130"]' ),
			'json address' => array( '<!-- wp:acme/x {"u":"https:\/\/staging.test\/y"} /-->', '<!-- wp:acme/x {"u":"https://www.live.test/y"} /-->' ),
		);
	}

	#[DataProvider( 'rewrites' )]
	public function testTheRewriterPutsInTheIdsAndAddressesOfTheOtherSide( string $from, string $to ): void {
		$out = AB_MCP_Sync_Refs::rewrite_content( $from, self::plan() );
		self::assertSame( array(), $out['missing'] );
		self::assertTrue( $out['changed'] );
		self::assertSame( $to, $out['content'] );
	}

	public static function shortLinks(): array {
		return array(
			// Codex, round 8: WP_Query reads post 12 of ?p=12.
			'p'                    => array( '<!-- wp:paragraph --><p><a href="https://staging.test/?p=12">Beitrag</a></p><!-- /wp:paragraph -->', array( 'post:12@c:core/paragraph?p' ), '<!-- wp:paragraph --><p><a href="https://www.live.test/?p=120">Beitrag</a></p><!-- /wp:paragraph -->' ),
			'categories'           => array( '<p><a href="https://staging.test/?cat=5,6">x</a></p>', array( 'term:5@c:html?cat', 'term:6@c:html?cat' ), '<p><a href="https://www.live.test/?cat=50,60">x</a></p>' ),
			'an author'            => array( '<p>https://staging.test/?author=1</p>', array( 'user:user1@c:html?author' ), '<p>https://www.live.test/?author=10</p>' ),
			'in a block attribute' => array( '<!-- wp:acme/x {"u":"https:\/\/staging.test\/?attachment_id=12"} /-->', array( 'post:12@c:acme/x.u?attachment_id' ), '<!-- wp:acme/x {"u":"https://www.live.test/?attachment_id=120"} /-->' ),
			'subpost_id'           => array( '<p>https://staging.test/?subpost_id=12</p>', array( 'post:12@c:html?subpost_id' ), '<p>https://www.live.test/?subpost_id=120</p>' ),
		);
	}

	#[DataProvider( 'shortLinks' )]
	public function testTheIdsOfAShortLinkAreReferences( string $content, array $want, string $to ): void {
		list( $refs, , , $acc ) = self::scan( $content );
		self::assertSame( $want, $refs );
		self::assertSame( array(), $acc['locks'] );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() );
		self::assertSame( array( $to, array() ), array( $out['content'], $out['missing'] ) );
	}

	public function testAShortLinkInAnotherSpellingOrWithoutCounterpartWritesNothing(): void {
		foreach ( array(
			'<p><a href="https://staging.test/?p=012">x</a></p>'   => 'c:html?p',
			'<p><a href="https://staging.test/?cat=-5">x</a></p>'  => 'c:html?cat',
			'<p><a href="https://staging.test/?author=1e1">x</a></p>' => 'c:html?author',
		) as $content => $at ) {
			list( $refs, , , $acc ) = self::scan( $content );
			self::assertSame( array(), $refs, $content );
			$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
			self::assertSame( array( array( '*:reference_spelling', $at ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $result['locks'] ), $content );
			self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() )['missing'], 'space' ), $content );
		}
		$out = AB_MCP_Sync_Refs::rewrite_content( '<p><a href="https://staging.test/?p=77">x</a></p>', self::plan() );
		self::assertSame( array( array( 'space' => 'post', 'id' => 77 ) ), $out['missing'] );
		// Another site's address, a variable that is no id: no reference
		// (&amp; between the variables: tests/integration/SyncHtmlRefsTest.php).
		list( $refs, , , $acc ) = self::scan( '<p><a href="https://other.test/?p=12">x</a> <a href="https://staging.test/?paged=2">y</a> https://staging.test/?pp=12</p>' );
		self::assertSame( array( array(), array() ), array( $refs, $acc['locks'] ) );
	}

	public static function safeAddresses(): array {
		return array(
			// The safe forms: with the host (also without the scheme), from the root.
			'from the root'                => array( '<p><a href="/?p=12">x</a></p>', '<p><a href="/?p=120">x</a></p>' ),
			'a path from the root'         => array( '<p><a href="/blog/?page_id=13">x</a></p>', '<p><a href="/blog/?page_id=130">x</a></p>' ),
			// Codex, round 9: the host of "//host" is replaced with the id.
			'without the scheme'           => array( '<p><a href="//staging.test/?p=12">x</a></p>', '<p><a href="//www.live.test/?p=120">x</a></p>' ),
			// Codex, round 9: the address of a navigation link, and with its id.
			'a navigation link'            => array( '<!-- wp:navigation-link {"label":"Beitrag","kind":"custom","url":"/?p=12"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","kind":"custom","url":"/?p=120"} /-->' ),
			'a navigation link and its id' => array( '<!-- wp:navigation-link {"label":"Beitrag","id":12,"kind":"post-type","url":"/?p=12"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","id":120,"kind":"post-type","url":"/?p=120"} /-->' ),
			'a full address and its id'    => array( '<!-- wp:navigation-link {"label":"Beitrag","id":12,"kind":"post-type","url":"https://staging.test/beitrag/"} /-->', '<!-- wp:navigation-link {"label":"Beitrag","id":120,"kind":"post-type","url":"https://www.live.test/beitrag/"} /-->' ),
			'an address by its value'      => array( '<!-- wp:acme/x {"target":"/?p=12"} /-->', '<!-- wp:acme/x {"target":"/?p=120"} /-->' ),
			'a shortcode attribute'        => array( '[acme_button url="/?p=12"]', '[acme_button url="/?p=120"]' ),
			'spaces before'                => array( '<p><a href=" /?p=12">x</a></p>', '<p><a href=" /?p=120">x</a></p>' ),
			'in a sentence'                => array( '<p>Mehr unter https://staging.test/?p=12.</p>', '<p>Mehr unter https://www.live.test/?p=120.</p>' ),
		);
	}

	#[DataProvider( 'safeAddresses' )]
	public function testAnAddressInASafeFormIsRewrittenWithItsIds( string $content, string $to ): void {
		list( , , , $acc ) = self::scan( $content );
		self::assertSame( array( array(), array() ), array( $acc['locks'], $acc['spelling'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() );
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
			// Codex, round 9: a list with a space (the value ends with the quote).
			'a list with a space'    => array( '<p><a href="https://staging.test/?cat=5, 6">x</a></p>', 'c:html?cat' ),
			'a list with a plus'     => array( '<p><a href="/?cat=5+6">x</a></p>', 'c:html?cat' ),
			'a name escaped'         => array( '<p><a href="/?%70=12">x</a></p>', 'c:html?p' ),
			'a value escaped'        => array( '<p><a href="https://staging.test/?p=%312">x</a></p>', 'c:html?p' ),
			'a list of a name'       => array( '<p><a href="/?p[]=12">x</a></p>', 'c:html?p' ),
			'a space before the name' => array( '<p><a href="/?%20p=12">x</a></p>', 'c:html?p' ),
			'a leading zero'         => array( '<p><a href="/?p=012">x</a></p>', 'c:html?p' ),
			'a list for one id'      => array( '<p><a href="/?p=12,13">x</a></p>', 'c:html?p' ),
			'an address by its name' => array( '<!-- wp:acme/x {"buttonUrl":"index.php?p=12"} /-->', 'c:acme/x.buttonUrl?p' ),
		);
	}

	#[DataProvider( 'unsafeAddresses' )]
	public function testAnAddressWithAnIdInAnyOtherFormLocks( string $content, string $at ): void {
		list( $refs, , , $acc ) = self::scan( $content );
		self::assertSame( array(), $refs );
		$result = AB_MCP_Sync_Canon::finish( 'post', '1', array(), array(), $acc );
		self::assertContains( array( '*:reference_spelling', $at ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $result['locks'] ) );
		self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() )['missing'], 'space' ) );
	}

	public function testAnAddressWithoutTheHostOutsideTheSitesIsNone(): void {
		foreach ( array(
			'<p><a href="//other.test/?p=12">x</a></p>',
			'<p><a href="mailto:a@b.test?p=12">x</a> <a href="#?p=12">y</a></p>',
			'<p>Siehe ?p=12 und /?p=12 im Text.</p>',
			'<p><a data-href="/?p=12">x</a></p>',
		) as $content ) {
			list( $refs, , , $acc ) = self::scan( $content );
			self::assertSame( array( array(), array(), array() ), array( $refs, $acc['locks'], $acc['spelling'] ), $content );
			self::assertSame( $content, AB_MCP_Sync_Refs::rewrite_content( $content, self::plan() )['content'], $content );
		}
	}

	public function testFromTheRootOnlyUnderTheFolderOfTheSiteAndWhereBothSitesShareIt(): void {
		$ctx  = AB_MCP_Sync_Canon::context( array( 'https://staging.test/blog' ), static fn( $id ) => 'user' . $id );
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array( 'https://staging.test/blog' => 'https://www.live.test/blog' ) );
		foreach ( array(
			'<p><a href="/blog/?p=12">x</a></p>'               => true,
			'<p><a href="/blog?p=12">x</a></p>'                => true,
			'<p><a href="//staging.test/blog/?p=12">x</a></p>' => true,
			'<p><a href="/?p=12">x</a></p>'                    => false,
			'<p><a href="/blogx/?p=12">x</a></p>'              => false,
			'<p><a href="//staging.test/?p=12">x</a></p>'      => false,
		) as $content => $site ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
			self::assertSame( $site ? array( 12 ) : array(), array_column( $acc['refs'], 'id' ), $content );
			self::assertSame( $site, false !== strpos( AB_MCP_Sync_Refs::rewrite_content( $content, $plan )['content'], 'p=120' ), $content );
		}
		// Another root on the other side: from the root, nothing is rewritten.
		$moved = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array( 'https://staging.test/blog' => 'https://www.live.test' ) );
		self::assertSame( array( 'spelling' ), array_column( AB_MCP_Sync_Refs::rewrite_content( '<p><a href="/blog/?p=12">x</a></p>', $moved )['missing'], 'space' ) );
		$both = AB_MCP_Sync_Canon::context( array( 'https://staging.test/blog', 'https://www.live.test' ), static fn( $id ) => 'user' . $id );
		$acc  = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '<p><a href="/blog/?p=12">x</a></p>', $both, $acc, 'post', 'c' );
		self::assertSame( array( 'c:html?p' ), $acc['spelling'] );
	}

	public function testAnIdInAnAddressOfTheOtherSiteLocks(): void {
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test', 'https://www.live.test' ), static fn( $id ) => 'user' . $id, '', array( 'https://staging.test' ) );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '<p><a href="https://www.live.test/?p=120">x</a> <a href="https://www.live.test/archives/120">z</a> <a href="https://staging.test/?p=12">y</a></p>', $ctx, $acc, 'post', 'c' );
		self::assertSame( array( 12 ), array_column( $acc['refs'], 'id' ) );
		self::assertSame( array( 'c:html?p', 'c:html%post_id%' ), $acc['spelling'], 'The id space and the permalinks of the other site are not this one\'s.' );
	}

	public function testTheIdOfANumericPermalinkIsAReference(): void {
		// Codex, round 9: the permalinks "Numeric" (/archives/%post_id%).
		$structure = '/archives/%post_id%';
		$ctx       = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, $structure );
		$plan      = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array( 'https://staging.test' => 'https://www.live.test' ), $structure );
		foreach ( array(
			'<p><a href="https://staging.test/archives/12">x</a></p>'                 => '<p><a href="https://www.live.test/archives/120">x</a></p>',
			'<p><a href="/archives/12/">x</a></p>'                                     => '<p><a href="/archives/120/">x</a></p>',
			'<p><a href="https://staging.test/archives/12/attachment/bild/">x</a></p>' => '<p><a href="https://www.live.test/archives/120/attachment/bild/">x</a></p>',
		) as $from => $to ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( $from, $ctx, $acc, 'post', 'c' );
			self::assertSame( array( array( 12, 'c:html%post_id%' ) ), array_map( static fn( $r ) => array( $r['id'], $r['at'] ), $acc['refs'] ), $from );
			self::assertSame( array( $to, array() ), array( AB_MCP_Sync_Refs::rewrite_content( $from, $plan )['content'], AB_MCP_Sync_Refs::rewrite_content( $from, $plan )['missing'] ), $from );
		}
		foreach ( array( '<p><a href="https://staging.test/archives/%31%32">x</a></p>', '<p><a href="https://staging.test/archives/012">x</a></p>', '<p><a href="archives/12">x</a></p>', '<p><a href="../archives/12">x</a></p>' ) as $content ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
			self::assertSame( array( 'c:html%post_id%' ), $acc['spelling'], $content );
		}
		// Other addresses of the site are none.
		foreach ( array( '<p><a href="https://staging.test/2026/11/03/beitrag/">x</a> <img src="https://staging.test/wp-content/uploads/2026/11/a.jpg"></p>', '<p><a href="https://staging.test/archives/">x</a></p>' ) as $content ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
			self::assertSame( array( array(), array() ), array( $acc['refs'], $acc['spelling'] ), $content );
		}
	}

	public function testADateArchiveIsNoPostOfThePermalinks(): void {
		// The rules of the date archives come before the posts': /2026/11/ is a month.
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, '/%year%/%post_id%/' );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '<p><a href="https://staging.test/2026/11/">x</a> <a href="https://staging.test/2026/123/">y</a></p>', $ctx, $acc, 'post', 'c' );
		self::assertSame( array( 123 ), array_column( $acc['refs'], 'id' ) );
		self::assertSame( array( 'c:html%post_id%' ), $acc['spelling'] );
		// Date permalinks without %post_id%: no id in the path.
		$dates = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, '/%year%/%monthnum%/%day%/%postname%/' );
		$acc   = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '<p><a href="https://staging.test/2026/11/03/beitrag/">x</a></p>', $dates, $acc, 'post', 'c' );
		self::assertSame( array( array(), array() ), array( $acc['refs'], $acc['spelling'] ) );
	}

	public function testWithoutThePermalinksASegmentOfDigitsLocks(): void {
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id, null );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( '<p><a href="https://staging.test/archives/12">x</a> <a href="https://staging.test/beitrag/">y</a></p>', $ctx, $acc, 'post', 'c' );
		self::assertSame( array( 'c:html%post_id%' ), $acc['spelling'] );
	}

	public function testWithoutAStructureGivenThePermalinksAreUnknown(): void {
		// Not given is not known: only the structure of the row, given, reads
		// the path exactly; without it a segment of digits locks.
		$content = '<p><a href="https://staging.test/archives/12">x</a></p>';
		$acc     = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( $content, AB_MCP_Sync_Canon::context( array( 'https://staging.test' ) ), $acc, 'post', 'c' );
		self::assertSame( array( 'c:html%post_id%' ), $acc['spelling'] );
		$addresses = array( 'https://staging.test' => 'https://www.live.test' );
		self::assertSame( array( 'spelling' ), array_column( AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( array(), $addresses ) )['missing'], 'space' ) );
		// Plain links, given: the path names no id.
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( array(), $addresses, '' ) );
		self::assertSame( array( '<p><a href="https://www.live.test/archives/12">x</a></p>', array() ), array( $out['content'], $out['missing'] ) );
	}

	public function testTheAddressOfACustomMenuItemIsOne(): void {
		// Codex, round 9: _menu_item_url "/?p=12".
		$plan    = self::plan();
		$changed = false;
		self::assertSame( '/?p=120', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_url', '/?p=12', 'custom', 9, $plan, $changed ) );
		self::assertSame( 'index.php?p=12', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_url', 'index.php?p=12', 'custom', 9, $plan, $changed ) );
		self::assertSame( array( 'spelling' ), array_column( $plan['missing'], 'space' ) );
	}

	public function testAShortLinkInAStoredValueIsAReference(): void {
		$acc  = AB_MCP_Sync_Canon::acc();
		$form = AB_MCP_Sync_Canon::value( serialize( array( 'url' => 'https://staging.test/?page_id=12' ) ), self::ctx(), $acc, 'post', 'meta:_link' );
		self::assertSame( array( 'post' ), array_column( $acc['refs'], 'space' ) );
		self::assertSame( array( 'meta:_link.url?page_id' ), array_column( $acc['refs'], 'at' ) );
		self::assertSame( array( '~ref' => 'post', 'id' => 12 ), $form['~php']['url']['~s'][2] );
		$plan    = self::plan();
		$changed = false;
		self::assertSame( serialize( array( 'url' => 'https://www.live.test/?page_id=120' ) ), AB_MCP_Sync_Refs::rewrite_value( serialize( array( 'url' => 'https://staging.test/?page_id=12' ) ), $plan, $changed ) );
		// As JSON stores it: a slash as \/, "&" as \u0026.
		$json = '{"url":"https:\/\/staging.test\/?preview=1\u0026p=12"}';
		$acc  = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( $json, self::ctx(), $acc, 'post', 'meta:_json' );
		self::assertSame( array( 'meta:_json?p' ), array_column( $acc['refs'], 'at' ) );
		self::assertSame( '{"url":"https:\/\/www.live.test\/?preview=1\u0026p=120"}', AB_MCP_Sync_Refs::rewrite_value( $json, $plan, $changed ) );
		// The query only as JSON decodes it (\u003f is the "?"): it locks.
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Canon::value( '{"url":"https:\/\/staging.test\/\u003fp=12"}', self::ctx(), $acc, 'post', 'meta:_json' );
		self::assertSame( array( array(), array( 'meta:_json?' ) ), array( $acc['refs'], $acc['spelling'] ) );
	}

	public function testAContentThatCannotBeJoinedLocks(): void {
		// Without the joined text (a PCRE error) nothing says what do_blocks() makes of it.
		$jit   = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Refs::content( '<!-- wp:acme/box {"title":"' . str_repeat( 'x', 200 ) . '"} /-->', self::ctx(), $acc, 'post', 'c' );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $limit );
			ini_set( 'pcre.jit', (string) $jit );
		}
		self::assertContains( array( '*:reference_spelling', 'c:joined@0' ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
	}

	public function testWithoutAnythingToChangeTheBytesStayAndAMissingIdIsNamed(): void {
		$raw = '<!--  wp:paragraph  --><p>Nichts</p><!-- /wp:paragraph -->';
		$out = AB_MCP_Sync_Refs::rewrite_content( $raw, self::plan() );
		self::assertFalse( $out['changed'] );
		self::assertSame( $raw, $out['content'] );

		$out = AB_MCP_Sync_Refs::rewrite_content( '<!-- wp:image {"id":77} --><figure><img/></figure><!-- /wp:image -->[gallery ids="77"]', self::plan() );
		self::assertSame( array( array( 'space' => 'post', 'id' => 77 ), array( 'space' => 'post', 'id' => 77 ) ), $out['missing'] );
		self::assertStringContainsString( 'ids="77"', $out['content'], 'Nothing guessed.' );
		// An address that only begins like the other one stays.
		$out = AB_MCP_Sync_Refs::rewrite_content( '<p>https://staging.test.evil.com/ und https://staging.test:8443/</p>', self::plan() );
		self::assertFalse( $out['changed'] );
	}

	/* -------------------------------------------- rewrite of the fields */

	public function testTheFieldsOfTheKindsAreRewrittenByTheirSchemas(): void {
		$plan    = self::plan();
		$changed = false;
		// Core options.
		self::assertSame( '120', AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['page_on_front'], '12', $plan, $changed ) );
		self::assertSame( '50', AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['default_category'], '5', $plan, $changed ) );
		self::assertSame( '0', AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['page_on_front'], '0', $plan, $changed ) );
		self::assertSame( serialize( array( 120, '130' ) ), AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['sticky_posts'], serialize( array( 12, '13' ) ), $plan, $changed ) );
		self::assertSame( 'Auf https://www.live.test/', AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['blogdescription'], 'Auf https://staging.test/', $plan, $changed ) );
		self::assertSame( '12', AB_MCP_Sync_Refs::rewrite_option( \AB_MCP_Sync_Option_Schema::CORE['posts_per_page'], '12', $plan, $changed ), 'A plain number is no id.' );
		self::assertTrue( $changed );

		// Menu item meta by _menu_item_type.
		self::assertSame( '120', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_object_id', '12', 'post_type', null, $plan, $changed ) );
		self::assertSame( '60', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_object_id', '6', 'taxonomy', null, $plan, $changed ) );
		self::assertSame( '220', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_menu_item_parent', '22', 'custom', null, $plan, $changed ) );
		self::assertSame( '900', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_object_id', '77', 'custom', 900, $plan, $changed ), 'A custom link names itself on the other side.' );
		self::assertSame( 'https://www.live.test/kontakt/', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_url', 'https://staging.test/kontakt/', 'custom', 900, $plan, $changed ) );
		self::assertSame( 'page', AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_object', 'page', 'post_type', null, $plan, $changed ) );
		self::assertSame( array(), $plan['missing'] );
		AB_MCP_Sync_Refs::rewrite_menu_item_meta( '_menu_item_object_id', '77', 'custom', null, $plan, $changed );
		self::assertSame( array( array( 'space' => 'self', 'id' => 77 ) ), $plan['missing'], 'A new custom link has no id yet.' );

		// Menu positions.
		$plan = self::plan();
		self::assertSame( array( 'primary' => 50, 'footer' => 60 ), AB_MCP_Sync_Refs::rewrite_locations( array( 'primary' => 5, 'footer' => 6 ), $plan, $changed ) );

		// Core widgets with ids.
		self::assertSame( array( 'title' => 'M', 'nav_menu' => 50 ), AB_MCP_Sync_Refs::rewrite_widget( 'nav_menu', array( 'title' => 'M', 'nav_menu' => 5 ), $plan, $changed ) );
		self::assertSame( array( 'attachment_id' => 120, 'url' => 'https://www.live.test/a.jpg', 'width' => 12 ), AB_MCP_Sync_Refs::rewrite_widget( 'media_image', array( 'attachment_id' => 12, 'url' => 'https://staging.test/a.jpg', 'width' => 12 ), $plan, $changed ) );
		self::assertSame( array( 'ids' => array( 120, 130 ) ), AB_MCP_Sync_Refs::rewrite_widget( 'media_gallery', array( 'ids' => array( 12, 13 ) ), $plan, $changed ) );
		self::assertSame( array( 'exclude' => '120, 130' ), AB_MCP_Sync_Refs::rewrite_widget( 'pages', array( 'exclude' => '12, 13' ), $plan, $changed ) );
		self::assertSame( array( 'content' => '<!-- wp:image {"id":120} --><figure><img/></figure><!-- /wp:image -->' ), AB_MCP_Sync_Refs::rewrite_widget( 'block', array( 'content' => '<!-- wp:image {"id":12} --><figure><img/></figure><!-- /wp:image -->' ), $plan, $changed ) );
		self::assertSame( array( 'post' => 12 ), AB_MCP_Sync_Refs::rewrite_widget( 'acme_box', array( 'post' => 12 ), $plan, $changed ), 'A widget of no schema keeps its numbers.' );
		self::assertSame( array(), $plan['missing'] );

		// A stored value: addresses inside a serialized one, serialized again.
		$changed = false;
		self::assertSame( serialize( array( 'u' => 'https://www.live.test/x' ) ), AB_MCP_Sync_Refs::rewrite_value( serialize( array( 'u' => 'https://staging.test/x' ) ), $plan, $changed ) );
		self::assertTrue( $changed );
		$changed = false;
		$raw     = serialize( array( 'u' => 'nichts' ) );
		self::assertSame( $raw, AB_MCP_Sync_Refs::rewrite_value( $raw, $plan, $changed ) );
		self::assertFalse( $changed, 'Unchanged bytes stay.' );
		self::assertSame( 'O:8:"stdClass":0:{}', AB_MCP_Sync_Refs::rewrite_value( 'O:8:"stdClass":0:{}', $plan, $changed ) );
		self::assertSame( array( array( 'space' => 'value', 'id' => 0 ) ), $plan['missing'], 'A value with an object is not rewritten.' );
	}
}
