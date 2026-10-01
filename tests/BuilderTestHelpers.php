<?php
/**
 * Shared set-up for the page-builder tests: fixtures, pages with builder
 * meta, and builders switched on or off per test.
 *
 * Whether a builder is "active" is a constant, class or function on a real
 * site — none of which a test can take back once defined. The tests swap a
 * builder's active check for an entry in active_plugins instead, through the
 * same filter a site owner would use, so every test decides for itself.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;

trait BuilderTestHelpers {

	/**
	 * A fixture from tests/fixtures/builders/<builder>/<name>.json.
	 *
	 * @return array{source:string,measured:bool,content:string}
	 */
	private function fixture( string $name, string $builder = 'core' ): array {
		$path = dirname( __FILE__ ) . '/fixtures/builders/' . $builder . '/' . $name . '.json';
		$data = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $data, 'Fixture ' . $name . ' is readable JSON.' );
		self::assertNotSame( '', $data['source'], 'Every fixture names its source.' );
		return $data;
	}

	/**
	 * A page with this content and meta (key => single value).
	 */
	private function page( int $id, string $content, array $meta = array(), array $fields = array() ): object {
		ab_test_add_post( $id, array_merge( array( 'post_type' => 'page', 'post_content' => $content ), $fields ) );
		foreach ( $meta as $key => $value ) {
			$GLOBALS['ab_test_meta'][ $id ][ $key ] = array( $value );
		}
		return get_post( $id );
	}

	/**
	 * Builders in $active are loaded, those in $inactive are not.
	 *
	 * @param string[] $active   Builder ids.
	 * @param string[] $inactive Builder ids.
	 */
	private function builders( array $active, array $inactive = array() ): void {
		$ids = array_merge( $active, $inactive );
		add_filter(
			'ab_mcp_builder_signatures',
			static function ( $sigs ) use ( $ids ) {
				foreach ( $ids as $id ) {
					$sigs[ $id ]['active'] = array( array( 'plugin' => 'ab-test-' . $id . '/plugin.php' ) );
				}
				return $sigs;
			}
		);
		update_option(
			'active_plugins',
			array_map(
				static function ( string $id ): string {
					return 'ab-test-' . $id . '/plugin.php';
				},
				$active
			)
		);
		AB_MCP_Builders::reset();
	}

	/**
	 * A small Elementor tree, as _elementor_data holds it: one container with
	 * a heading. Elementor shows a page as its own only while this is not
	 * empty (AB_MCP_Builder_Adapter_Elementor::own_data_shown()).
	 */
	private function elementorTree( string $title = 'Willkommen' ): string {
		return (string) json_encode(
			array(
				array(
					'id'       => 'c0ffee1',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'a1b2c3d',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => $title ),
							'elements'   => array(),
						),
					),
				),
			)
		);
	}

	/** The current user may do everything. */
	private function mayDoAnything(): void {
		$GLOBALS['ab_test_can'] = static function (): bool {
			return true;
		};
	}

	/**
	 * Every string anywhere in a value, for "never in the output" checks.
	 */
	private function flat( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
