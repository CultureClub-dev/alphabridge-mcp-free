<?php
/**
 * The references the save of a core block writes into its HTML, read with
 * the HTML API of WordPress 7.1.3 (AB_MCP_Sync_Html, AB_MCP_Sync_Refs::HTML,
 * Z8 decision 12): the class token wp-image-<id> exactly as a token of the
 * class attribute and data-id exactly by its name — not data-class, not a
 * token that only holds the letters, not a file name; character references
 * and the letter case of attribute names as a browser reads them; a tag in
 * a class attribute in other quotes or spacing reads alike; the rewriter sets the ids of the other side.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncHtmlRefsTest extends TestCase {

	/** The references a content has, as space:id@where. */
	private static function scan( string $content ): array {
		$ctx  = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ), static fn( $id ) => 'user' . $id );
		$acc  = AB_MCP_Sync_Canon::acc();
		$form = AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'c' );
		$refs = array();
		foreach ( $acc['refs'] as $ref ) {
			$refs[] = $ref['space'] . ':' . $ref['id'] . '@' . $ref['at'];
		}
		sort( $refs );
		return array( $refs, $form, $acc );
	}

	public static function schemas(): array {
		return array(
			'core/image id and class'      => array( '<!-- wp:image {"id":12} --><figure><img class="wp-image-12"/></figure><!-- /wp:image -->', array( 'post:12@c:core/image.id', 'post:12@c:core/image:html:class' ) ),
			'core/gallery ids, data-id'    => array( '<!-- wp:gallery {"ids":[12,13]} --><figure><img data-id="12" class="wp-image-12"/></figure><!-- /wp:gallery -->', array( 'post:12@c:core/gallery.ids.0', 'post:12@c:core/gallery:html:class', 'post:12@c:core/gallery:html:data_id', 'post:13@c:core/gallery.ids.1' ) ),
			'core/cover'                   => array( '<!-- wp:cover {"id":7} --><div><img class="wp-block-cover__image-background wp-image-7"/></div><!-- /wp:cover -->', array( 'post:7@c:core/cover.id', 'post:7@c:core/cover:html:class' ) ),
			'core/media-text'              => array( '<!-- wp:media-text {"mediaId":8} --><div><img class="wp-image-8 size-full"/></div><!-- /wp:media-text -->', array( 'post:8@c:core/media-text.mediaId', 'post:8@c:core/media-text:html:class' ) ),
			'caption with its image'       => array( '[caption id="attachment_15" width="300"]<img class="wp-image-15"/> Text[/caption]', array( 'post:15@c:html:html:class', 'post:15@c:html:shortcode:caption.id' ) ),
			'classic image'                => array( '<p><img class="alignnone size-full wp-image-16"/></p>', array( 'post:16@c:html:html:class' ) ),
			'single quotes'                => array( "<p><img src='a.jpg' class='wp-image-17 size-full'/></p>", array( 'post:17@c:html:html:class' ) ),
			'names in capitals'            => array( '<p><IMG CLASS="WP-IMAGE-18"/></p>', array( 'post:18@c:html:html:class' ) ),
			'a character reference'        => array( '<p><img class="wp&#45;image-19"/></p>', array( 'post:19@c:html:html:class' ) ),
			'data-id in capitals'          => array( '<!-- wp:gallery --><figure><img DATA-ID="20"/></figure><!-- /wp:gallery -->', array( 'post:20@c:core/gallery:html:data_id' ) ),
			'the classic block in a group' => array( '<!-- wp:group --><div><!-- wp:freeform --><p><img class="alignnone wp-image-26"/></p><!-- /wp:freeform --></div><!-- /wp:group -->', array( 'post:26@c:core/freeform:html:class' ) ),
			'an image in a paragraph'      => array( '<!-- wp:paragraph --><p>Text <img class="wp-image-7" src="x.jpg"> Text</p><!-- /wp:paragraph -->', array( 'post:7@c:core/paragraph:html:class' ) ),
			'a caption in a paragraph'     => array( '<!-- wp:paragraph --><p>[caption id="attachment_9"]<img class="wp-image-9"/>[/caption]</p><!-- /wp:paragraph -->', array( 'post:9@c:core/paragraph:html:class', 'post:9@c:core/paragraph:shortcode:caption.id' ) ),
			'not data-class'               => array( '<p><img data-class="wp-image-21" class="size-full"/></p>', array() ),
			'not a longer token'           => array( '<p><img class="xwp-image-22 wp-image-22x"/></p>', array() ),
			'not a file name'              => array( '<p><img src="https://cdn.test/wp-image-23.jpg" alt="wp-image-23" class="alignnone"/> wp-image-23</p>', array() ),
			'not data-id outside gallery'  => array( '<!-- wp:image --><figure><img data-id="24"/></figure><!-- /wp:image -->', array() ),
			'not data-ids'                 => array( '<!-- wp:gallery --><figure><img data-ids="25"/></figure><!-- /wp:gallery -->', array() ),
			'not wp-image-0'               => array( '<p><img class="wp-image-0"/></p>', array() ),
			'not data-id 0'                => array( '<!-- wp:gallery --><figure><img data-id="0"/></figure><!-- /wp:gallery -->', array() ),
			// A short link in a tag with an id: read once, in the form of the tag.
			'a short link in a tag'        => array( '<p><img class="wp-image-12" src="https://staging.test/?attachment_id=13"/></p>', array( 'post:12@c:html:html:class', 'post:13@c:html:html?attachment_id' ) ),
		);
	}

	#[DataProvider( 'schemas' )]
	public function testTheHtmlApiFindsExactlyTheReferencesOfTheSave( string $content, array $want ): void {
		self::assertSame( $want, self::scan( $content )[0] );
	}

	public function testAClassInOtherQuotesOrSpacingReadsAlikeAndTheIdIsAMarker(): void {
		list( , $a ) = self::scan( '<p><img src="https://staging.test/a.jpg" class="wp-image-12  size-full"/></p>' );
		list( , $b ) = self::scan( "<p><img src=\"https://staging.test/a.jpg\" class='wp-image-12 size-full'/></p>" );
		list( , $c ) = self::scan( '<p><img src="https://staging.test/a.jpg" class="wp-image-13 size-full"/></p>' );
		$tag         = $a['~blocks'][0]['c'][0]['~s'][1];
		self::assertSame( array( array( '~ref' => 'post', 'id' => 12 ) ), $tag['refs'] );
		self::assertStringNotContainsString( '12', AB_MCP_Sync_Canon::encode( $tag['~tag'] ), 'The id is no part of the tag text.' );
		self::assertSame( AB_MCP_Sync_Canon::hash( $a ), AB_MCP_Sync_Canon::hash( $b ) );
		self::assertNotSame( AB_MCP_Sync_Canon::hash( $a ), AB_MCP_Sync_Canon::hash( $c ) );
		self::assertSame( AB_MCP_Sync_Canon::hash( AB_MCP_Sync_Canon::mapped( $a, array( 'post' => array( 12 => 13 ) ) ) ), AB_MCP_Sync_Canon::hash( $c ) );
	}

	public static function rewrites(): array {
		return array(
			'image'    => array( '<!-- wp:image {"id":12} --><figure><img src="https://staging.test/a.jpg" class="wp-image-12"/></figure><!-- /wp:image -->', '<!-- wp:image {"id":120} --><figure><img src="https://www.live.test/a.jpg" class="wp-image-120"/></figure><!-- /wp:image -->' ),
			'gallery'  => array( '<!-- wp:gallery {"ids":[12,13]} --><figure><img data-id="12" class="wp-image-12"/></figure><!-- /wp:gallery -->', '<!-- wp:gallery {"ids":[120,130]} --><figure><img data-id="120" class="wp-image-120"/></figure><!-- /wp:gallery -->' ),
			'caption'  => array( '[caption id="attachment_12"]<img class="alignnone wp-image-12 size-full"/>[/caption] [gallery ids="12,13"]', '[caption id="attachment_120"]<img class="alignnone wp-image-120 size-full"/>[/caption] [gallery ids="120,130"]' ),
			'freeform' => array( '<!-- wp:group --><div><!-- wp:freeform --><p><img src="https://staging.test/a.jpg" class="wp-image-12"/></p><!-- /wp:freeform --></div><!-- /wp:group -->', '<!-- wp:group --><div><!-- wp:freeform --><p><img src="https://www.live.test/a.jpg" class="wp-image-120"/></p><!-- /wp:freeform --></div><!-- /wp:group -->' ),
			'only ids' => array( '<p><img data-class="photo" src="https://cdn.test/a.jpg" class="wp-image-13"/></p>', '<p><img data-class="photo" src="https://cdn.test/a.jpg" class="wp-image-130"/></p>' ),
		);
	}

	#[DataProvider( 'rewrites' )]
	public function testTheRewriterSetsTheIdsOfTheOtherSide( string $from, string $to ): void {
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120, 13 => 130 ) ), array( 'https://staging.test' => 'https://www.live.test' ) );
		$out  = AB_MCP_Sync_Refs::rewrite_content( $from, $plan );
		self::assertSame( array(), $out['missing'] );
		self::assertSame( $to, $out['content'] );
	}

	public function testAnIdWithoutCounterpartStaysAndIsNamed(): void {
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array() ), array() );
		$out  = AB_MCP_Sync_Refs::rewrite_content( '<!-- wp:image {"id":77} --><figure><img class="wp-image-77"/></figure><!-- /wp:image -->', $plan );
		self::assertSame( array( array( 'space' => 'post', 'id' => 77 ), array( 'space' => 'post', 'id' => 77 ) ), $out['missing'] );
		self::assertStringContainsString( 'class="wp-image-77"', $out['content'] );
	}

	public static function imageSpellings(): array {
		return array(
			'a file name first' => array( '<!-- wp:image {"id":12} --><figure><img src="https://cdn.test/wp-image-5.jpg" class="wp-image-12"/></figure><!-- /wp:image -->', 'c:core/image:img@8' ),
			'another attribute' => array( '<p><img data-class="wp-image-12" src="https://cdn.test/a.jpg" class="wp-image-13"/></p>', 'c:html:img@3' ),
			'no class token'    => array( '<p><img src="https://cdn.test/wp-image-23.jpg" class="alignnone"/></p>', 'c:html:img@3' ),
			'a longer token'    => array( '<p><img class="xwp-image-22"/></p>', 'c:html:img@3' ),
			'the same id twice' => array( '<p><img alt="wp-image-12" class="wp-image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			'two class tokens'  => array( '<p><img class="wp-image-12 wp-image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			'an attribute name' => array( '<p><img wp-image-5 class="wp-image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			'not an image'      => array( '<!-- wp:image {"id":12} --><figure class="wp-image-12"><img class="wp-image-12"/></figure><!-- /wp:image -->', 'c:core/image:img@0' ),
			'a reference in the class' => array( '<p><img class="wp&#45;image-19"/></p>', 'c:html:img@3' ),
			'a reference in an alt'    => array( '<p><img alt="wp&#45;image-5" class="wp-image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			// The core reads the wp-image- of a later class or alt, the HTML
			// API only the first of a name; or of a name beside a class that
			// holds it as a reference.
			'a duplicate class'        => array( '<p><img class="wp&#45;image-12" class="wp-image-5" src="a.jpg"></p>', 'c:html:img@3' ),
			'a duplicate alt'          => array( '<p><img alt="Bild" ALT="wp-image-5" class="wp&#45;image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			'a name, the class a reference' => array( '<p><img wp-image-5 class="wp&#45;image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			// The same id before the class: the rewritten class would come after it.
			'a name with the same id'  => array( '<p><img wp-image-12 class="wp&#45;image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			'a duplicate alt with the same id' => array( '<p><img alt="Bild" ALT="wp-image-12" class="wp&#45;image-12" src="a.jpg"></p>', 'c:html:img@3' ),
			// A data-id is no class id: the core reads the file name.
			'a data-id and a file name' => array( '<!-- wp:gallery {"ids":[12]} --><figure><img data-id="12" src="https://cdn.test/wp-image-5.jpg"/></figure><!-- /wp:gallery -->', 'c:core/gallery:img@8' ),
			// Codex, round 6: the HTML API reads 12, wp_filter_content_tags() 1.
			'a reference among the digits' => array( '<p><img class="wp-image-1&#50;" src="a.jpg"></p>', 'c:html:img@3' ),
			'a reference before the digits' => array( '<p><img class="wp-image-&#49;2" src="a.jpg"></p>', 'c:html:img@3' ),
			'a leading zero'           => array( '<p><img class="wp-image-012" src="a.jpg"></p>', 'c:html:img@3' ),
			'a leading zero the core reads' => array( '<p><img src="https://cdn.test/wp-image-012.jpg" class="alignnone"/></p>', 'c:html:img@3' ),
			// The core skips wp-image- without digits and reads the next one.
			'a word first, then the id' => array( '<p><img src="https://cdn.test/wp-image-a.jpg" alt="wp-image-5"></p>', 'c:html:img@3' ),
			'a data-id as a reference' => array( '<!-- wp:gallery --><figure><img data-id="1&#50;"/></figure><!-- /wp:gallery -->', 'c:core/gallery:img@8' ),
			'a data-id with a leading zero' => array( '<!-- wp:gallery --><figure><img data-id="012"/></figure><!-- /wp:gallery -->', 'c:core/gallery:img@8' ),
		);
	}

	#[DataProvider( 'imageSpellings' )]
	public function testAnImageIdTheCoreReadsOtherwiseThanTheRulesLocks( string $content, string $at ): void {
		list( , , $acc ) = self::scan( $content );
		self::assertSame( array(), array_filter( $acc['refs'], static fn( $r ) => ! is_int( $r['id'] ) || $r['id'] <= 0 ), 'An id in another spelling is no reference.' );
		self::assertSame( array( array( '*:reference_spelling', $at ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( array( 'post' => array( 1 => 100, 5 => 50, 7 => 70, 12 => 120, 13 => 130, 22 => 220, 23 => 230 ) ), array() ) );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), 'The rewriter writes nothing.' );
	}

	public static function tagsAcrossBlocks(): array {
		return array(
			// Codex, round 7: do_blocks() joins the class to wp-image-12.
			'a class split by a block'       => array( '<!-- wp:image {"id":12} --><figure><img class="wp-image-1<!-- wp:html /-->2" src="a.jpg"></figure><!-- /wp:image -->', 'c:tag@35' ),
			// The HTML API reads the tag past the ">" in a quoted value.
			'a ">" in a quoted value before' => array( '<!-- wp:image {"id":12} --><figure><img alt="a>b" class="wp-image-1<!-- wp:html /-->2" src="a.jpg"></figure><!-- /wp:image -->', 'c:tag@35' ),
			'a tag beginning split'          => array( '<!-- wp:paragraph --><p><<!-- wp:html /-->img class="wp-image-12" src="a.jpg"></p><!-- /wp:paragraph -->', 'c:tag@24' ),
		);
	}

	#[DataProvider( 'tagsAcrossBlocks' )]
	public function testATagAcrossBlockDelimitersLocks( string $content, string $at ): void {
		list( , , $acc ) = self::scan( $content );
		self::assertContains( array( '*:reference_spelling', $at ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
		$out = AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 120 ) ), array() ) );
		self::assertContains( 'spelling', array_column( $out['missing'], 'space' ), 'The rewriter writes nothing.' );
	}

	public function testTheIdsOfAShortLinkAfterAnHtmlAmpersandAreReferences(): void {
		// The query as an HTML attribute holds it: &amp; between the variables.
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 13 => 130 ) ), array( 'https://staging.test' => 'https://www.live.test' ) );
		foreach ( array( '&amp;', '&#038;', '&' ) as $and ) {
			$content = '<p><a href="https://staging.test/index.php?preview=true' . $and . 'page_id=13' . $and . 'pp=14">x</a></p>';
			list( $refs, , $acc ) = self::scan( $content );
			self::assertSame( array( 'post:13@c:html?page_id' ), $refs, $and );
			self::assertSame( array(), $acc['locks'], $and );
			$out = AB_MCP_Sync_Refs::rewrite_content( $content, $plan );
			self::assertSame( array( '<p><a href="https://www.live.test/index.php?preview=true' . $and . 'page_id=130' . $and . 'pp=14">x</a></p>', array() ), array( $out['content'], $out['missing'] ), $and );
			// Without the host, as the browser resolves it against the site.
			$content = '<p><a href="/index.php?preview=true' . $and . 'page_id=13">x</a></p>';
			self::assertSame( array( 'post:13@c:html?page_id' ), self::scan( $content )[0], $and );
			self::assertSame( '<p><a href="/index.php?preview=true' . $and . 'page_id=130">x</a></p>', AB_MCP_Sync_Refs::rewrite_content( $content, $plan )['content'], $and );
		}
	}

	public function testAnIdBesideACharacterReferenceLocks(): void {
		// Codex, round 9: the HTML API reads ?p=12 of ?p=1&#50;, a pattern 1.
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => array( 1 => 10, 12 => 120 ) ), array( 'https://staging.test' => 'https://www.live.test' ) );
		foreach ( array(
			'<p><a href="https://staging.test/?p=1&#50;">x</a></p>',
			'<p><a href="https://staging.test/?p=&#49;&#50;">x</a></p>',
			'<p><a href="/?p&#61;12">x</a></p>',
			'<p><a href="/?&#112;=12">x</a></p>',
		) as $content ) {
			list( $refs, , $acc ) = self::scan( $content );
			self::assertSame( array(), $refs, $content );
			self::assertSame( array( 'c:html?p' ), $acc['spelling'], $content );
			self::assertContains( 'spelling', array_column( AB_MCP_Sync_Refs::rewrite_content( $content, $plan )['missing'], 'space' ), $content );
		}
		// The query only decoded: "&#63;" is the "?" the browser reads.
		list( $refs, , $acc ) = self::scan( '<p><a href="https://staging.test/&#63;p=12">x</a></p>' );
		self::assertSame( array( array(), array( 'c:html?p' ) ), array( $refs, $acc['spelling'] ) );
	}

	public function testSeveralImagesLockInTheOrderOfTheText(): void {
		$content = '<p><img src="https://cdn.test/wp-image-5.jpg"><img class="wp&#45;image-12"></p>';
		list( , , $acc ) = self::scan( $content );
		self::assertSame(
			array( 'c:html:img@3', 'c:html:img@' . strpos( $content, '<img class' ) ),
			array_column( $acc['locks'], 'at' ),
			'The core reads the first, the rules read the second in another spelling.'
		);
	}

	public static function noCoreIds(): array {
		return array(
			'an iframe'          => array( '<p><iframe src="https://cdn.test/wp-image-5.html"></iframe></p>' ),
			'not a number'       => array( '<p><img src="https://cdn.test/wp-image-a.jpg" class="alignnone"/></p>' ),
			'an img in an iframe tag' => array( '<p><iframe title="<img class=wp-image-5 >" src="https://cdn.test/v"></iframe></p>' ),
			'a data-id alone'    => array( '<!-- wp:gallery {"ids":[20]} --><figure><img data-id="20" src="https://staging.test/a.jpg"/></figure><!-- /wp:gallery -->' ),
			'wp-image-0'         => array( '<p><img src="https://cdn.test/wp-image-0.jpg" class="alignnone"/></p>' ),
		);
	}

	/** The core's pattern finds the iframe, and reads ids only of an img, only wp-image-<digits> other than 0; a data-id is no class id. */
	#[DataProvider( 'noCoreIds' )]
	public function testWhereTheCoreReadsNoIdNothingLocks( string $content ): void {
		self::assertSame( array(), self::scan( $content )[2]['locks'] );
	}

	public function testWhereTheCoreAndTheRulesReadTheSameIdNothingLocks(): void {
		foreach ( array(
			'<!-- wp:image {"id":12} --><figure><img src="https://staging.test/a.jpg" alt="Bild" class="wp-image-12"/></figure><!-- /wp:image -->',
			'<p><img class="alignnone size-full wp-image-16" src="a-300x200.jpg" width="300"/></p>',
			'<p><IMG CLASS="WP-IMAGE-18"/></p>',
			// A ">" in a quoted value, and no delimiter in the tag.
			'<!-- wp:image {"id":12} --><figure><img alt="a>b" class="wp-image-12" src="a.jpg"></figure><!-- /wp:image -->',
		) as $content ) {
			self::assertSame( array(), self::scan( $content )[2]['locks'], $content );
		}
	}
}
