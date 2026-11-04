<?php
/**
 * The builder release list of the takeover (AB_MCP_Sync_Builder_Lock, Z8
 * decision 31): released are exactly the six block libraries; every other
 * builder whose markers the stored rows carry locks the post. The markers
 * of signatures.php matched on the rows as AB_MCP_Builders matches them on
 * what WordPress reads: the value of the row with the lowest id, unpacked
 * without classes, equals, not_in, empty values, unless; no database and no
 * get_post_meta() is asked. Against detect_all() itself for every signature
 * in tests/integration/SyncLocksTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use AB_MCP_Sync_Builder_Lock;
use AB_MCP_Sync_Canon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-builder-lock.php';
require_once __DIR__ . '/FakeBuilderAdapter.php';

final class SyncBuilderLockTest extends TestCase {

	/** @var mixed */
	private $saved;

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Builders::reset();
		// No database: the lock reads only the rows it is given.
		$this->saved     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved;
		AB_MCP_Builders::reset();
	}

	private static function row( string $content = '' ): array {
		return array(
			'ID'           => 5,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
			'post_title'   => 'Seite',
		);
	}

	private static function meta( array $pairs ): array {
		$out = array();
		$id  = 1;
		foreach ( $pairs as $pair ) {
			$out[] = array(
				'meta_id' => $id++,
				'key'     => $pair[0],
				'value'   => $pair[1],
			);
		}
		return $out;
	}

	public function testExactlyTheSixBlockLibrariesAreReleased(): void {
		self::assertSame( array( 'kadence', 'spectra', 'generateblocks', 'stackable', 'otter', 'coblocks' ), AB_MCP_Sync_Builder_Lock::RELEASED );
	}

	public static function cases(): array {
		return array(
			'divi 4 by its meta'        => array( '', array( array( '_et_pb_use_builder', 'on' ) ), array( 'divi4' ) ),
			'divi 4 off'                => array( '', array( array( '_et_pb_use_builder', 'off' ) ), array() ),
			'the first row counts'      => array( '', array( array( '_et_pb_use_builder', 'off' ), array( '_et_pb_use_builder', 'on' ) ), array() ),
			'wpbakery'                  => array( '', array( array( '_wpb_vc_js_status', 'true' ) ), array( 'wpbakery' ) ),
			'elementor'                 => array( '', array( array( '_elementor_edit_mode', 'builder' ) ), array( 'elementor' ) ),
			'elementor empty'           => array( '', array( array( '_elementor_edit_mode', '' ) ), array() ),
			'elementor zero'            => array( '', array( array( '_elementor_edit_mode', '0' ) ), array() ),
			'breakdance with a tree'    => array( '', array( array( '_breakdance_data', '{"tree_json_string":"{}"}' ) ), array( 'breakdance' ) ),
			'breakdance empty design'   => array( '', array( array( '_breakdance_data', '{"tree_json_string":""}' ) ), array() ),
			'siteorigin empty array'    => array( '', array( array( 'panels_data', 'a:0:{}' ) ), array() ),
			'siteorigin with widgets'   => array( '', array( array( 'panels_data', serialize( array( 'widgets' => array( 1 ) ) ) ) ), array( 'siteorigin' ) ),
			'divi 5 in the content'     => array( '<!-- wp:divi/section --><div></div><!-- /wp:divi/section -->', array(), array( 'divi5' ) ),
			'kadence in the content'    => array( '<!-- wp:kadence/rowlayout --><div></div><!-- /wp:kadence/rowlayout -->', array(), array() ),
			'core blocks'               => array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', array(), array() ),
			'elementor as an int zero'  => array( '', array( array( '_elementor_edit_mode', 'i:0;' ) ), array() ),
			'elementor with an object'  => array( '', array( array( '_elementor_edit_mode', 'O:8:"stdClass":0:{}' ) ), array( 'elementor' ) ),
			'elementor broken'          => array( '', array( array( '_elementor_edit_mode', 'a:1:{s:1:"x";}' ) ), array() ),
			'divi 4 equals an object'   => array( '', array( array( '_et_pb_use_builder', 'O:8:"stdClass":0:{}' ) ), array() ),
			'divi 4 serialized on'      => array( '', array( array( '_et_pb_use_builder', 's:2:"on";' ) ), array( 'divi4' ) ),
			'divi 4 in the content'     => array( '[et_pb_section][/et_pb_section]', array(), array( 'divi4' ) ),
			'divi 4 unless divi 5'      => array( '[et_pb_section][/et_pb_section]<!-- wp:divi/section --><div></div><!-- /wp:divi/section -->', array(), array( 'divi5' ) ),
		);
	}

	#[DataProvider( 'cases' )]
	public function testABuilderThatIsNotReleasedLocks( string $content, array $meta, array $want ): void {
		$found = array_column( AB_MCP_Sync_Builder_Lock::locked( self::row( $content ), self::meta( $meta ) ), 'id' );
		sort( $found );
		self::assertSame( $want, $found );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Builder_Lock::note( $acc, self::row( $content ), self::meta( $meta ) );
		self::assertSame( array() === $want ? array() : array( 'post:builder' ), array_values( array_unique( array_column( $acc['locks'], 'key' ) ) ) );
	}

	public function testAMarkerGetPostMetaDoesNotShowStillLocks(): void {
		// The stand-in's get_post_meta() knows no meta of post 5 at all:
		// what WordPress would read, here as under a filter, is empty.
		self::assertSame( array(), get_post_meta( 5, '_et_pb_use_builder' ) );
		self::assertSame( array( 'divi4' ), array_column( AB_MCP_Sync_Builder_Lock::locked( self::row(), self::meta( array( array( '_et_pb_use_builder', 'on' ) ) ) ), 'id' ) );
		self::assertSame( array( 'meta:_et_pb_use_builder' ), AB_MCP_Sync_Builder_Lock::detected( self::row(), self::meta( array( array( '_et_pb_use_builder', 'on' ) ) ) )['divi4']['detected_by'] );
	}

	public function testAReaderWithoutASignatureLocksEveryPost(): void {
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( array $list ): array {
				$list[] = new FakeBuilderAdapter( 'acme-builder' );
				return $list;
			}
		);
		AB_MCP_Builders::reset();
		self::assertSame( array( 'acme-builder' ), AB_MCP_Sync_Builder_Lock::unknown() );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Builder_Lock::note( $acc, self::row( '<p>Text</p>' ), array() );
		self::assertSame( array( array( 'post:builder_unknown', 'builder:acme-builder', 'Z8c' ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'], $l['stage'] ), $acc['locks'] ) );
	}

	public function testTheReadersOfThisPluginAllHaveASignature(): void {
		self::assertSame( array(), AB_MCP_Sync_Builder_Lock::unknown() );
	}
}
