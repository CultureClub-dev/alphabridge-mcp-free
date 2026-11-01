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
			'core/gallery ids'             => array( '<!-- wp:gallery {"ids":[12,"13"]} --><figure><img/></figure><!-- /wp:gallery -->', array( 'post:12@c:core/gallery.ids.0', 'post:13@c:core/gallery.ids.1' ) ),
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
		// A text of digits keeps that it was text.
		list( , , $text ) = self::scan( '<!-- wp:gallery {"ids":["13"]} --><figure></figure><!-- /wp:gallery -->' );
		self::assertSame( array( '~ref' => 'post', 'id' => 13, 'text' => true ), $text['~blocks'][0]['a']['ids'][0] );
	}

	/* ------------------------------------------- possible references */

	public static function possibles(): array {
		return array(
			'a block of a plugin'          => array( '<!-- wp:acme/box {"post":40,"n":"41","list":"42,43","label":"Seite 2","ratio":1.5} /-->', array( '40@c:acme/box.post', '41@c:acme/box.n', '42@c:acme/box.list', '43@c:acme/box.list' ) ),
			'nested in a plugin block'     => array( '<!-- wp:acme/box {"items":[{"id":44}]} /-->', array( '44@c:acme/box.items.0.id' ) ),
			'an unmapped core id'          => array( '<!-- wp:avatar {"userId":45,"size":96} /-->', array( '45@c:core/avatar.userId' ) ),
			'a core attribute of no list'  => array( '<!-- wp:image {"id":12,"acmeLink":46} --><figure></figure><!-- /wp:image -->', array( '46@c:core/image.acmeLink' ) ),
			'a navigation link of custom'  => array( '<!-- wp:navigation-link {"id":47,"kind":"custom"} /-->', array( '47@c:core/navigation-link.id' ) ),
			'another shortcode'            => array( '[contact-form-7 id="48" title="Kontakt"]', array( '48@c:html:shortcode:contact-form-7.id' ) ),
			'JSON in a plugin attribute'   => array( '<!-- wp:acme/box {"data":"{\\"post\\":52,\\"inner\\":\\"{\\\\\\"target\\\\\\":53}\\"}"} /-->', array( '52@c:acme/box.data.post', '53@c:acme/box.data.inner.target' ) ),
			// As stored: shortcode_parse_atts() takes one level of
			// backslashes (stripcslashes()), the JSON inside the next.
			'JSON in a shortcode attribute' => array( '[acme_box data=\'{"post":54,"inner":"{\\\\\\"target\\\\\\":55}"}\']', array( '54@c:html:shortcode:acme_box.data.post', '55@c:html:shortcode:acme_box.data.inner.target' ) ),
			'JSON escaped in a shortcode'  => array( '[acme_box data=\'{\\"post\\":56}\']', array( '56@c:html:shortcode:acme_box.data.post' ) ),
			'by position in a shortcode'   => array( '[acme_box \'{"post":57}\' 58]', array( '57@c:html:shortcode:acme_box.0.post', '58@c:html:shortcode:acme_box.1' ) ),
			'a core shortcode, other attr' => array( '[gallery id="49" columns="3" include="51"]', array( '49@c:html:shortcode:gallery.id', '51@c:html:shortcode:gallery.include' ) ),
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
				'user' => array( 1 => 10, 2 => 20 ),
			),
			array( 'https://staging.test' => 'https://www.live.test' )
		);
	}

	public static function rewrites(): array {
		return array(
			'image'        => array( '<!-- wp:image {"id":12} --><figure><img src="https://staging.test/a.jpg"/></figure><!-- /wp:image -->', '<!-- wp:image {"id":120} --><figure><img src="https://www.live.test/a.jpg"/></figure><!-- /wp:image -->' ),
			'gallery'      => array( '<!-- wp:gallery {"ids":[12,"13"]} --><figure><img/></figure><!-- /wp:gallery -->', '<!-- wp:gallery {"ids":[120,"130"]} --><figure><img/></figure><!-- /wp:gallery -->' ),
			'block'        => array( '<!-- wp:block {"ref":20} /-->', '<!-- wp:block {"ref":200} /-->' ),
			'nav link'     => array( '<!-- wp:navigation-link {"id":22,"kind":"post-type","url":"https://staging.test/x/"} /-->', '<!-- wp:navigation-link {"id":220,"kind":"post-type","url":"https://www.live.test/x/"} /-->' ),
			'query'        => array( '<!-- wp:query {"query":{"author":"1,2","taxQuery":{"include":{"category":[5]}},"exclude":[30]}} --><div></div><!-- /wp:query -->', '<!-- wp:query {"query":{"author":"10,20","taxQuery":{"include":{"category":[50]}},"exclude":[300]}} --><div></div><!-- /wp:query -->' ),
			'latest posts' => array( '<!-- wp:latest-posts {"selectedAuthor":2,"categories":[{"id":6,"value":"T"}]} /-->', '<!-- wp:latest-posts {"selectedAuthor":20,"categories":[{"id":60,"value":"T"}]} /-->' ),
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
