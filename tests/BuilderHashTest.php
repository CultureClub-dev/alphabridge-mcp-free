<?php
/**
 * layout_hash: the same stored page gives the same hash, any change to any
 * stored copy gives another — the conflict check Pro relies on before it
 * writes.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use PHPUnit\Framework\TestCase;

final class BuilderHashTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	public function testStableForTheSamePage(): void {
		$post = $this->page( 80, $this->fixture( 'paragraph' )['content'] );
		$a    = AB_MCP_Builders::layout_hash( $post );
		AB_MCP_Builders::reset();
		self::assertSame( $a, AB_MCP_Builders::layout_hash( get_post( 80 ) ) );
		self::assertSame( hash( 'sha256', (string) json_encode( array( 'post_content' => $post->post_content ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $a, 'sha256 over json_encode of the raw values.' );
	}

	public function testAnyChangedCopyChangesIt(): void {
		$this->builders( array( 'seedprod' ) );
		$post = $this->page( 81, '<div id="sp-page">Mo–Fr</div>', array( '_seedprod_page' => '1' ), array( 'post_content_filtered' => '{"t":"Mo–Fr"}' ) );
		$a    = AB_MCP_Builders::layout_hash( $post );
		$post->post_content_filtered = '{"t":"Mo–Sa"}';
		$b = AB_MCP_Builders::layout_hash( $post );
		self::assertNotSame( $a, $b, 'The builder\'s own copy counts.' );
		$post->post_content = '<div id="sp-page">Mo–Sa</div>';
		self::assertNotSame( $b, AB_MCP_Builders::layout_hash( $post ) );
		self::assertSame( array( 'post_content', 'post_content_filtered' ), array_keys( AB_MCP_Builders::raw( $post ) ), 'Sorted by location.' );
	}

	public function testMetaCopiesCountForBuildersWithoutReaderToo(): void {
		$this->builders( array( 'elementor' ) );
		$post = $this->page( 82, 'copy', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[{"id":"a","settings":{"title":"Alt"}}]' ) );
		$a    = AB_MCP_Builders::layout_hash( $post );
		$GLOBALS['ab_test_meta'][82]['_elementor_data'] = array( '[{"id":"a","settings":{"title":"Neu"}}]' );
		self::assertNotSame( $a, AB_MCP_Builders::layout_hash( $post ) );
	}

	public function testInvalidUtf8DoesNotCollapseIntoOneHash(): void {
		$one = $this->page( 83, "broken \xC3 one" );
		$two = $this->page( 84, "broken \xC3 two" );
		$h1  = AB_MCP_Builders::layout_hash( $one );
		self::assertNotSame( $h1, AB_MCP_Builders::layout_hash( $two ) );
		self::assertNotSame( hash( 'sha256', '' ), $h1, 'json_encode did not fail into an empty string.' );
	}

	public function testLockedKeysNeverEnterRawValues(): void {
		self::assertTrue( AB_MCP_Builders::is_locked_key( 'vcv-settingsLocalJsHead', array( 'vcv-settingsLocalJs*' ) ) );
		self::assertTrue( AB_MCP_Builders::is_locked_key( 'tbp_custom_js', array( 'tbp_custom_js' ) ) );
		self::assertFalse( AB_MCP_Builders::is_locked_key( 'vcv-pageContent', array( 'vcv-settingsLocalJs*' ) ) );
		$post = get_post( 0 );
		self::assertNull( $post );
		$post = $this->page( 85, 'x', array( 'secret_js' => 'alert(1)' ) );
		$raw  = AB_MCP_Builders::raw_values(
			$post,
			array(
				array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ),
				array( 'loc' => 'meta:secret_js', 'shown' => false, 'role' => 'copy' ),
			),
			array( 'secret_js' )
		);
		self::assertSame( array( 'post_content' => 'x' ), $raw );
	}
}
