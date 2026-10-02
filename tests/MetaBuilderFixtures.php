<?php
/**
 * Set-up for the readers of builders that keep a page in post meta or in
 * post_content_filtered (Beaver Builder, SiteOrigin, SeedProd): a page from
 * a fixture, with the meta the way WordPress hands it to get_post_meta().
 *
 * The fixtures hold meta exactly as stored (strings, serialized where the
 * builder stored arrays or objects). The test double of get_post_meta()
 * returns what is put in, so the values go in unserialized — as
 * maybe_unserialize() returns them on a site — and AB_MCP_Builders::raw()
 * serializes them back for the hash, which gives the stored bytes again
 * (each fixture test checks that round trip).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

trait MetaBuilderFixtures {

	/**
	 * Meta values as get_post_meta() returns them: serialized ones
	 * unserialized (the builders store arrays and stdClass objects only).
	 *
	 * @param array<string,string> $raw Key => value as stored.
	 * @return array<string,mixed>
	 */
	private function asLoaded( array $raw ): array {
		$out = array();
		foreach ( $raw as $key => $value ) {
			$data        = @unserialize( $value, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
			$out[ $key ] = ( false === $data && 'b:0;' !== $value ) ? $value : $data;
		}
		return $out;
	}

	/**
	 * A page from a meta-builder fixture.
	 *
	 * @param int   $id      Post id.
	 * @param array $fixture Fixture (post_content, meta, post_content_filtered).
	 * @param array $meta    Meta to use instead of the fixture's, as loaded.
	 */
	private function fixturePage( int $id, array $fixture, ?array $meta = null ): object {
		$fields = array();
		if ( isset( $fixture['post_content_filtered'] ) ) {
			$fields['post_content_filtered'] = $fixture['post_content_filtered'];
		}
		return $this->page( $id, (string) $fixture['post_content'], $meta ?? $this->asLoaded( $fixture['meta'] ?? array() ), $fields );
	}

	/**
	 * Elements of an outline by id.
	 *
	 * @param array<int,array> $elements Outline.
	 * @return array<string,array>
	 */
	private function byId( array $elements ): array {
		$out = array();
		foreach ( $elements as $element ) {
			$out[ $element['id'] ] = $element;
		}
		return $out;
	}

	/**
	 * Field values of an element: name => value.
	 *
	 * @param array $element Element.
	 * @return array<string,mixed>
	 */
	private function values( array $element ): array {
		return array_map(
			static function ( array $field ) {
				return $field['value'];
			},
			$element['fields'] ?? array()
		);
	}
}
