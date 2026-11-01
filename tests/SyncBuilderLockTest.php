<?php
/**
 * The builder release list of the takeover (AB_MCP_Sync_Builder_Lock, Z8
 * decision 31): released are exactly the six block libraries; every other
 * builder detect_all() names locks the post, and so does a meta marker of
 * the stored rows that get_post_meta() does not show (a filter can add a
 * lock, never take one). The marker rules of signatures.php: the value
 * read as the core reads it (the row with the lowest id), equals, not_in,
 * empty values. Against WordPress itself in tests/integration/SyncLocksTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Builder_Lock;
use AB_MCP_Sync_Canon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-builder-lock.php';

/**
 * A $wpdb with an options table whose rows a test sets (option name =>
 * value): what the gate of AB_MCP_Sync_Safe reads.
 */
final class SyncBuilderLockFakeWpdb {
	public $options     = 'wp_options';
	public $last_error  = '';
	public $last_result = array();
	/** @var array<string,string> */
	public $rows = array();

	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}

	public function query( $query ) {
		$name              = is_array( $query ) && isset( $query[1][0] ) ? (string) $query[1][0] : '';
		$this->last_result = array();
		if ( isset( $this->rows[ $name ] ) ) {
			$this->last_result[] = (object) array( 'option_name' => $name, 'option_value' => $this->rows[ $name ], 'autoload' => 'on' );
		}
		return count( $this->last_result );
	}
}

final class SyncBuilderLockTest extends TestCase {

	/** @var mixed */
	private $saved;

	protected function setUp(): void {
		ab_test_reset();
		$this->saved     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new SyncBuilderLockFakeWpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved;
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

	public function testAnOptionDetectAllReadsWithAnObjectKeepsItFromBeingAsked(): void {
		$GLOBALS['wpdb']->rows['template'] = 'O:8:"stdClass":0:{}';
		$row                               = self::row( '<!-- wp:divi/section --><div></div><!-- /wp:divi/section -->' );
		self::assertSame( array(), AB_MCP_Sync_Builder_Lock::locked( $row, array() ), 'detect_all() was not asked.' );
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Builder_Lock::note( $acc, $row, array() );
		self::assertSame( array( array( '*:php_object', 'builder:options' ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
		unset( $GLOBALS['wpdb']->rows['template'] );
		self::assertSame( array( 'divi5' ), array_column( AB_MCP_Sync_Builder_Lock::locked( $row, array() ), 'id' ), 'Asked once the row is clean.' );
	}

	public function testAMarkerGetPostMetaDoesNotShowStillLocks(): void {
		// The stand-in's get_post_meta() knows no meta of post 5 at all:
		// what detect_all() reads, here as under a filter, is empty.
		self::assertSame( array(), get_post_meta( 5, '_et_pb_use_builder' ) );
		self::assertSame( array( 'divi4' ), array_column( AB_MCP_Sync_Builder_Lock::locked( self::row(), self::meta( array( array( '_et_pb_use_builder', 'on' ) ) ) ), 'id' ) );
		self::assertSame( array( 'divi4' => array( 'meta:_et_pb_use_builder' ) ), AB_MCP_Sync_Builder_Lock::stored_markers( self::meta( array( array( '_et_pb_use_builder', 'on' ) ) ) ) );
	}
}
