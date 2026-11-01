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
			'core/gallery ids, data-id'    => array( '<!-- wp:gallery {"ids":[12,"13"]} --><figure><img data-id="12" class="wp-image-12"/></figure><!-- /wp:gallery -->', array( 'post:12@c:core/gallery.ids.0', 'post:12@c:core/gallery:html:class', 'post:12@c:core/gallery:html:data_id', 'post:13@c:core/gallery.ids.1' ) ),
			'core/cover'                   => array( '<!-- wp:cover {"id":7} --><div><img class="wp-block-cover__image-background wp-image-7"/></div><!-- /wp:cover -->', array( 'post:7@c:core/cover.id', 'post:7@c:core/cover:html:class' ) ),
			'core/media-text'              => array( '<!-- wp:media-text {"mediaId":8} --><div><img class="wp-image-8 size-full"/></div><!-- /wp:media-text -->', array( 'post:8@c:core/media-text.mediaId', 'post:8@c:core/media-text:html:class' ) ),
			'caption with its image'       => array( '[caption id="attachment_15" width="300"]<img class="wp-image-15"/> Text[/caption]', array( 'post:15@c:html:html:class', 'post:15@c:html:shortcode:caption.id' ) ),
			'classic image'                => array( '<p><img class="alignnone size-full wp-image-16"/></p>', array( 'post:16@c:html:html:class' ) ),
			'single quotes'                => array( "<p><img src='a.jpg' class='wp-image-17 size-full'/></p>", array( 'post:17@c:html:html:class' ) ),
			'names in capitals'            => array( '<p><IMG CLASS="WP-IMAGE-18"/></p>', array( 'post:18@c:html:html:class' ) ),
			'a character reference'        => array( '<p><img class="wp&#45;image-19"/></p>', array( 'post:19@c:html:html:class' ) ),
			'data-id in capitals'          => array( '<!-- wp:gallery --><figure><img DATA-ID="20"/></figure><!-- /wp:gallery -->', array( 'post:20@c:core/gallery:html:data_id' ) ),
			'the classic block in a group' => array( '<!-- wp:group --><div><!-- wp:freeform --><p><img class="alignnone wp-image-26"/></p><!-- /wp:freeform --></div><!-- /wp:group -->', array( 'post:26@c:core/freeform:html:class' ) ),
			'not data-class'               => array( '<p><img data-class="wp-image-21" class="size-full"/></p>', array() ),
			'not a longer token'           => array( '<p><img class="xwp-image-22 wp-image-22x"/></p>', array() ),
			'not a file name'              => array( '<p><img src="https://cdn.test/wp-image-23.jpg" alt="wp-image-23" class="alignnone"/> wp-image-23</p>', array() ),
			'not data-id outside gallery'  => array( '<!-- wp:image --><figure><img data-id="24"/></figure><!-- /wp:image -->', array() ),
			'not data-ids'                 => array( '<!-- wp:gallery --><figure><img data-ids="25"/></figure><!-- /wp:gallery -->', array() ),
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
			'gallery'  => array( '<!-- wp:gallery {"ids":[12,"13"]} --><figure><img data-id="12" class="wp-image-12"/></figure><!-- /wp:gallery -->', '<!-- wp:gallery {"ids":[120,"130"]} --><figure><img data-id="120" class="wp-image-120"/></figure><!-- /wp:gallery -->' ),
			'caption'  => array( '[caption id="attachment_12"]<img class="alignnone wp-image-12 size-full"/>[/caption] [gallery ids="12,13"]', '[caption id="attachment_120"]<img class="alignnone wp-image-120 size-full"/>[/caption] [gallery ids="120,130"]' ),
			'freeform' => array( '<!-- wp:group --><div><!-- wp:freeform --><p><img src="https://staging.test/a.jpg" class="wp-image-12"/></p><!-- /wp:freeform --></div><!-- /wp:group -->', '<!-- wp:group --><div><!-- wp:freeform --><p><img src="https://www.live.test/a.jpg" class="wp-image-120"/></p><!-- /wp:freeform --></div><!-- /wp:group -->' ),
			'only ids' => array( '<p><img data-class="wp-image-12" src="https://cdn.test/wp-image-12.jpg" class="wp-image-13"/></p>', '<p><img data-class="wp-image-12" src="https://cdn.test/wp-image-12.jpg" class="wp-image-130"/></p>' ),
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
}
