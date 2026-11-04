<?php
/**
 * The schema rewriter against the canonical form, with WordPress 7.1.3's
 * own parser and serializer (AB_MCP_Sync_Refs, Z8 decision 12): for every
 * schema, content of the staging side rewritten into the ID space and to
 * the address of the live side hashes as the mapped form of the staging
 * side — what the rewriter writes is what the comparison expects; and the
 * core parses it again.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncRewriteTest extends TestCase {

	private const STAGING = 'http://staging.example.test';
	private const LIVE    = 'https://www.example.test';

	private const MAP = array(
		'post' => array( 12 => 120, 13 => 130, 20 => 200, 22 => 220, 30 => 300, 31 => 310 ),
		'term' => array( 5 => 50, 6 => 60 ),
	);

	public static function contents(): array {
		$s = self::STAGING;
		return array(
			'core/image'           => array( '<!-- wp:image {"id":12,"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="' . $s . '/wp-content/uploads/a.jpg" alt="" class="wp-image-12"/></figure>' . "\n" . '<!-- /wp:image -->' ),
			'core/gallery'         => array( '<!-- wp:gallery {"ids":[12,13],"linkTo":"none"} --><figure class="wp-block-gallery"><ul><li><img src="' . $s . '/a.jpg" data-id="12" class="wp-image-12"/></li></ul></figure><!-- /wp:gallery -->' ),
			'core/cover'           => array( '<!-- wp:cover {"url":"' . $s . '/b.jpg","id":13,"dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-13" src="' . $s . '/b.jpg"/></div><!-- /wp:cover -->' ),
			'core/media-text'      => array( '<!-- wp:media-text {"mediaId":12,"mediaType":"image"} --><div class="wp-block-media-text"><figure><img src="' . $s . '/a.jpg" class="wp-image-12 size-full"/></figure></div><!-- /wp:media-text -->' ),
			'core/file'            => array( '<!-- wp:file {"id":13,"href":"' . $s . '/c.pdf"} --><div class="wp-block-file"><a href="' . $s . '/c.pdf">c</a></div><!-- /wp:file -->' ),
			'core/video, audio'    => array( '<!-- wp:video {"id":12} --><figure class="wp-block-video"><video src="' . $s . '/v.mp4"></video></figure><!-- /wp:video --><!-- wp:audio {"id":13} --><figure class="wp-block-audio"><audio src="' . $s . '/a.mp3"></audio></figure><!-- /wp:audio -->' ),
			'core/block'           => array( '<!-- wp:block {"ref":20} /-->' ),
			'core/navigation'      => array( '<!-- wp:navigation {"ref":20} /-->' ),
			'navigation links'     => array( '<!-- wp:navigation-link {"label":"Seite","type":"page","id":22,"url":"' . $s . '/seite/","kind":"post-type"} /--><!-- wp:navigation-submenu {"label":"K","type":"category","id":5,"url":"' . $s . '/k/","kind":"taxonomy"} --><!-- /wp:navigation-submenu -->' ),
			'core/page-list'       => array( '<!-- wp:page-list {"parentPageID":22} /-->' ),
			'core/query'           => array( '<!-- wp:query {"queryId":1,"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","taxQuery":{"category":[5],"post_tag":[6]},"exclude":[30],"parents":[31]}} --><div class="wp-block-query"></div><!-- /wp:query -->' ),
			'core/query new tax'   => array( '<!-- wp:query {"query":{"taxQuery":{"include":{"category":[5]},"exclude":{"post_tag":[6]}}}} --><div class="wp-block-query"></div><!-- /wp:query -->' ),
			'core/latest-posts'    => array( '<!-- wp:latest-posts {"categories":[{"id":5,"value":"Kat"}],"postsToShow":3} /-->' ),
			'shortcodes'           => array( '<!-- wp:shortcode -->[gallery ids="12,13"] [playlist ids="12"]<!-- /wp:shortcode -->' . "\n\n" . '<!-- wp:paragraph --><p>[caption id="attachment_12" width="300"]<img class="wp-image-12" src="' . $s . '/a.jpg"/> Bild[/caption]</p><!-- /wp:paragraph -->' ),
			'classic content'      => array( '<p><a href="' . $s . '/x/"><img class="alignnone size-full wp-image-13" src="' . $s . '/b.jpg"/></a></p>' . "\n" . '[gallery ids="12"]' ),
			'nested'               => array( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:image {"id":13} --><figure class="wp-block-image"><img class="wp-image-13"/></figure><!-- /wp:image --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->' ),
		);
	}

	#[DataProvider( 'contents' )]
	public function testTheRewrittenContentIsTheMappedFormOfTheStagingSide( string $content ): void {
		$ctx     = AB_MCP_Sync_Canon::context( array( self::STAGING, self::LIVE ), static fn() => false );
		$acc     = AB_MCP_Sync_Canon::acc();
		$staging = AB_MCP_Sync_Refs::content( $content, $ctx, $acc, 'post', 'post_content' );
		self::assertNotSame( array(), $acc['refs'], 'The case has references.' );

		$out = AB_MCP_Sync_Refs::rewrite_content( $content, AB_MCP_Sync_Refs::plan( self::MAP, array( self::STAGING => self::LIVE ) ) );
		self::assertSame( array(), $out['missing'] );
		self::assertTrue( $out['changed'] );
		self::assertStringNotContainsString( self::STAGING, $out['content'] );

		$acc2 = AB_MCP_Sync_Canon::acc();
		$live = AB_MCP_Sync_Refs::content( $out['content'], $ctx, $acc2, 'post', 'post_content' );
		self::assertSame( AB_MCP_Sync_Canon::hash( AB_MCP_Sync_Canon::mapped( $staging, self::MAP ) ), AB_MCP_Sync_Canon::hash( $live ) );
		self::assertSame( count( $acc['refs'] ), count( $acc2['refs'] ) );
		self::assertSame( count( parse_blocks( $content ) ), count( parse_blocks( $out['content'] ) ), 'The core parses it as before.' );
		self::assertSame( array(), $acc2['possible'] );
	}
}
